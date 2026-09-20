<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Publisher {
	public static function create_draft( $job, $artifacts, $allow_partial = false ) {
		global $wpdb;
		$article = isset( $artifacts['article'] ) && is_array( $artifacts['article'] ) ? $artifacts['article'] : array();
		$canonical = isset( $artifacts['canonical'] ) && is_array( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		if ( $allow_partial && empty( $article['title'] ) ) { $article['title'] = $job->title; }
		if ( $allow_partial && empty( $article['content_html'] ) ) {
			$input = json_decode( (string) $job->input_json, true );
			$article['content_html'] = wpautop( esc_html( (string) ( $input['source_text'] ?? $input['text'] ?? '' ) ) );
		}
		if ( empty( $article['title'] ) || ( ! $allow_partial && empty( $article['content_html'] ) ) ) { return new WP_Error( 'draft_content_missing', 'L’article ne contient pas le titre ou le contenu.' ); }
		// Re-read the link in case a previous write succeeded before its worker stopped.
		$post_id = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT draft_post_id FROM ' . MSRWA_DB::tables()['jobs'] . ' WHERE id=%d', $job->id ) ) );
		if ( $post_id ) {
			$existing = get_post( $post_id );
			if ( ! $existing || 'draft' !== $existing->post_status ) { return new WP_Error( 'draft_not_editable', 'Le contenu lié n’est plus un brouillon ; aucune modification automatique effectuée.' ); }
		}
		$link_specs = self::internal_link_specs( isset( $article['internal_links'] ) ? $article['internal_links'] : array(), isset( $artifacts['internal_link_candidates'] ) ? $artifacts['internal_link_candidates'] : array() );
		$content = self::sanitize_content( (string) $article['content_html'], $link_specs );
		$content = self::insert_internal_links( $content, $link_specs );
		if ( ! preg_match( '/<\w[\s\S]*>/i', $content ) ) { $content = wpautop( esc_html( $content ) ); }
		if ( isset( $canonical['calories_estimate'] ) && '' !== (string) $canonical['calories_estimate'] ) {
			$content .= '<p class="msrwa-nutrition-note">Valeurs nutritionnelles estimées par IA ; elles ne remplacent pas une analyse nutritionnelle professionnelle.</p>';
		}
		$paginated = self::apply_pagination( $content, MSRWA_Settings::get() );
		if ( is_wp_error( $paginated ) ) {
			if ( ! $allow_partial ) { return $paginated; }
			$artifacts['delivery_findings'][] = array( 'severity' => 'warning', 'field' => 'pagination', 'reason' => $paginated->get_error_message() );
		} else { $content = $paginated; }
		$author_id = self::author_id( $job );
		if ( is_wp_error( $author_id ) ) { return $author_id; }
		$post_data = array(
			'post_title'   => sanitize_text_field( $article['title'] ),
			'post_content' => $content,
			'post_excerpt' => isset( $article['excerpt'] ) ? sanitize_textarea_field( $article['excerpt'] ) : '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_author'  => $author_id,
			'post_name'    => isset( $article['slug'] ) ? sanitize_title( $article['slug'] ) : sanitize_title( $article['title'] ),
		);
		if ( $post_id ) {
			$post_data['ID'] = $post_id;
			$post_id = wp_update_post( wp_slash( $post_data ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $post_data ), true );
			if ( ! is_wp_error( $post_id ) ) {
				$wpdb->update( MSRWA_DB::tables()['jobs'], array( 'draft_post_id' => (int) $post_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id ), array( '%d', '%s' ), array( '%d' ) );
				MSRWA_DB::event( 'draft_created', $job->batch_id, $job->id, array( 'post_id' => (int) $post_id ) );
			}
		}
		if ( is_wp_error( $post_id ) ) { return $post_id; }
		self::write_recipe_meta( $post_id, $canonical );
		self::write_seo_meta( $post_id, $article );
		self::write_taxonomies( $post_id, $article );
		self::write_facebook_meta( $post_id, $article, $artifacts );
		self::write_provenance( $post_id, $job, $artifacts );
		$report = self::editorial_report( $artifacts, $allow_partial );
		MSRWA_DB::store_artifact( $job->id, $job->batch_id, 'editorial_review', $report );
		update_post_meta( $post_id, '_msrwa_job_id', absint( $job->id ) );
		if ( ! empty( $artifacts['featured_image']['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, absint( $artifacts['featured_image']['attachment_id'] ) );
		}
		if ( ! empty( $artifacts['facebook_image']['attachment_id'] ) ) {
			update_post_meta( $post_id, '_msrwa_facebook_image_id', absint( $artifacts['facebook_image']['attachment_id'] ) );
		}
		if ( $allow_partial ) { return (int) $post_id; }
		$verified = self::verify_draft( $post_id, $article, $canonical, $artifacts );
		if ( is_wp_error( $verified ) ) { return $verified; }
		return (int) $post_id;
	}

	public static function editorial_report( $artifacts, $requires_review = false ) {
		$quality = (array) ( $artifacts['quality_report'] ?? array() );
		$findings = array_merge( (array) ( $quality['findings'] ?? array() ), (array) ( $artifacts['review']['findings'] ?? array() ), (array) ( $artifacts['delivery_findings'] ?? array() ) );
		foreach ( array( 'featured_image', 'facebook_image' ) as $key ) {
			if ( empty( $artifacts[ $key ]['attachment_id'] ) ) { $requires_review = true; $findings[] = array( 'severity' => 'warning', 'field' => $key, 'reason' => 'Image non disponible.' ); }
			foreach ( (array) ( $artifacts['image_reviews'][ $key ]['findings'] ?? array() ) as $finding ) { if ( is_array( $finding ) ) { $finding['field'] = $key; $findings[] = $finding; } }
			if ( true !== ( $artifacts['image_reviews'][ $key ]['pass'] ?? null ) ) { $requires_review = true; }
		}
		if ( empty( $quality['pass'] ) || true !== ( $artifacts['review']['pass'] ?? null ) || empty( $artifacts['article']['content_html'] ) ) { $requires_review = true; }
		$unique = array();
		foreach ( $findings as $finding ) { if ( is_array( $finding ) ) { $unique[ hash( 'sha256', wp_json_encode( $finding ) ) ] = $finding; } }
		return array( 'status' => $requires_review ? 'needs_review' : 'checks_passed', 'score' => isset( $quality['score'] ) ? (int) $quality['score'] : null, 'article_available' => ! empty( $artifacts['article']['content_html'] ), 'text_review_passed' => true === ( $artifacts['review']['pass'] ?? null ), 'findings' => array_values( $unique ), 'metrics' => $quality['metrics'] ?? array(), 'generated_at' => current_time( 'mysql', true ) );
	}

	private static function author_id( $job ) {
		$owner = absint( $job->owner_id );
		if ( ! $owner || ! get_userdata( $owner ) ) { return new WP_Error( 'draft_owner_missing', 'Le propriétaire du job n’existe plus ; le brouillon ne peut pas être attribué de façon sûre.' ); }
		if ( ! user_can( $owner, 'edit_posts' ) || ( ! user_can( $owner, 'msrwa_create' ) && ! user_can( $owner, 'msrwa_manage' ) && ! user_can( $owner, 'manage_options' ) ) ) {
			return new WP_Error( 'draft_owner_unauthorized', 'Le propriétaire du job ne possède plus les droits requis pour créer ce brouillon.' );
		}
		return $owner;
	}

	/**
	 * Re-read all public integration values before a job can be marked completed.
	 * A generated draft stays available for manual recovery when this check fails.
	 */
	private static function verify_draft( $post_id, $article, $canonical, $artifacts ) {
		$post = get_post( $post_id );
		if ( ! $post || 'draft' !== $post->post_status || 'post' !== $post->post_type ) { return new WP_Error( 'draft_write_failed', 'Le brouillon WordPress n’a pas été enregistré dans l’état attendu.' ); }
		$settings = MSRWA_Settings::get();
		$page_breaks = substr_count( (string) $post->post_content, '<!--nextpage-->' );
		if ( ! empty( $settings['article_pagination_enabled'] ) && 1 !== $page_breaks ) { return new WP_Error( 'draft_pagination_missing', 'La division de l’article en deux pages n’a pas été enregistrée correctement.' ); }
		if ( empty( $settings['article_pagination_enabled'] ) && 0 !== $page_breaks ) { return new WP_Error( 'draft_pagination_unexpected', 'Une division de page est présente alors que ce réglage est désactivé.' ); }
		$mapping = MSRWA_Settings::get()['integration_mapping'];
		foreach ( array( 'prep_minutes', 'cook_minutes', 'servings', 'calories_estimate', 'cuisine', 'difficulty' ) as $field ) {
			$key = isset( $mapping[ $field ] ) ? $mapping[ $field ] : '';
			if ( ! $key || ! array_key_exists( $field, $canonical ) ) { continue; }
			$expected = in_array( $field, array( 'prep_minutes', 'cook_minutes', 'servings', 'calories_estimate' ), true ) ? (string) absint( $canonical[ $field ] ) : sanitize_text_field( $canonical[ $field ] );
			if ( (string) get_post_meta( $post_id, $key, true ) !== $expected ) { return new WP_Error( 'draft_recipe_meta_missing', 'Une métadonnée Recipe Card n’a pas été enregistrée correctement : ' . $field . '.' ); }
		}
		foreach ( array( 'seo_title', 'seo_description' ) as $field ) {
			$key = isset( $mapping[ $field ] ) ? $mapping[ $field ] : '';
			if ( ! $key || ! isset( $article[ $field ] ) ) { continue; }
			$expected = 'seo_title' === $field ? sanitize_text_field( $article[ $field ] ) : sanitize_textarea_field( $article[ $field ] );
			if ( (string) get_post_meta( $post_id, $key, true ) !== $expected ) { return new WP_Error( 'draft_seo_meta_missing', 'Une métadonnée SEO n’a pas été enregistrée correctement : ' . $field . '.' ); }
		}
		$featured = absint( $artifacts['featured_image']['attachment_id'] ?? 0 );
		if ( $featured && $featured !== (int) get_post_thumbnail_id( $post_id ) ) { return new WP_Error( 'draft_featured_image_missing', 'L’image principale n’est pas correctement associée au brouillon.' ); }
		$facebook = absint( $artifacts['facebook_image']['attachment_id'] ?? 0 );
		$key = isset( $mapping['facebook_meta'] ) ? $mapping['facebook_meta'] : '';
		if ( $facebook && $key ) {
			$stored = json_decode( (string) get_post_meta( $post_id, $key, true ), true );
			if ( ! is_array( $stored ) || absint( $stored[0]['id'] ?? 0 ) !== $facebook ) { return new WP_Error( 'draft_facebook_meta_missing', 'La référence Facebook n’a pas été enregistrée correctement.' ); }
		}
		return true;
	}

	private static function apply_pagination( $content, $settings ) {
		$content = preg_replace( '/\s*<!--\s*nextpage\s*-->\s*/i', "\n", (string) $content );
		if ( empty( $settings['article_pagination_enabled'] ) ) { return $content; }
		$words = preg_match_all( '/\p{L}+(?:[’\'-]\p{L}+)*/u', wp_strip_all_tags( $content ) );
		$minimum = max( 300, absint( $settings['article_pagination_min_words'] ?? 1000 ) );
		if ( $words < $minimum ) { return new WP_Error( 'article_too_short_for_pagination', 'L’article est trop court pour être divisé proprement en deux pages.' ); }
		$ratio = min( 70, max( 30, absint( $settings['article_pagination_split_percent'] ?? 50 ) ) ) / 100;
		if ( ! class_exists( 'DOMDocument' ) ) { return new WP_Error( 'article_parser_missing', 'L’extension DOM est nécessaire pour diviser le contenu sans casser le HTML.' ); }
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$previous_errors = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"><html><body><div id="msrwa-pagination-root">' . $content . '</div></body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$root = $document->getElementById( 'msrwa-pagination-root' );
		if ( ! $root ) { return new WP_Error( 'article_parser_failed', 'Impossible de déterminer les limites du contenu.' ); }
		$content = '';
		$boundaries = array();
		foreach ( $root->childNodes as $node ) {
			if ( $node instanceof DOMElement ) { $boundaries[] = array( 'offset' => strlen( $content ), 'tag' => strtolower( $node->tagName ) ); }
			$content .= $document->saveHTML( $node );
		}
		$target = (int) round( strlen( $content ) * $ratio );
		$candidates = array();
		foreach ( array( array( 'h2' ), array( 'h3', 'section', 'article', 'div' ), array( 'p', 'ul', 'ol' ) ) as $tags ) {
			foreach ( $boundaries as $boundary ) {
				if ( in_array( $boundary['tag'], $tags, true ) && $boundary['offset'] > strlen( $content ) * 0.30 && $boundary['offset'] < strlen( $content ) * 0.75 ) { $candidates[] = $boundary['offset']; }
			}
			if ( $candidates ) { break; }
		}
		if ( ! $candidates ) { return new WP_Error( 'article_pagination_boundary_missing', 'Aucun intertitre sûr ne permet de diviser cet article en deux pages.' ); }
		usort( $candidates, static function ( $a, $b ) use ( $target ) { return abs( $a - $target ) <=> abs( $b - $target ); } );
		$offset = (int) reset( $candidates );
		return substr( $content, 0, $offset ) . "\n<!--nextpage-->\n" . substr( $content, $offset );
	}

	private static function sanitize_content( $content, $internal_links ) {
		$content = wp_kses_post( $content );
		if ( ! preg_match( '/<\w[\s\S]*>/i', $content ) ) { return wpautop( esc_html( $content ) ); }
		$content = preg_replace( '/<h1\b[^>]*>(.*?)<\/h1>/is', '$1', $content );
		$allowed = array();
		foreach ( is_array( $internal_links ) ? $internal_links : array() as $link ) {
			$url = is_array( $link ) && isset( $link['url'] ) ? (string) $link['url'] : '';
			$allowed[] = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $url ) : ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
		}
		$content = preg_replace_callback( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ( $match ) use ( $allowed ) {
			$href = self::relative_internal_url( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
			return $href && in_array( $href, $allowed, true ) ? '<a href="' . esc_url( $href ) . '">' . $match[2] . '</a>' : $match[2];
		}, $content );
		return $content;
	}

	private static function internal_link_specs( $article_links, $candidate_links ) {
		$settings = MSRWA_Settings::get();
		if ( empty( $settings['internal_links_enabled'] ) ) { return array(); }
		$limit = min( 10, max( 0, absint( $settings['internal_links_max'] ) ) );
		if ( 0 === $limit ) { return array(); }
		$allowed = array();
		foreach ( is_array( $candidate_links ) ? $candidate_links : array() as $link ) {
			if ( is_array( $link ) ) { $allowed[] = $link; }
		}
		$allowed_urls = array();
		foreach ( $allowed as $link ) {
			$relative = self::relative_internal_url( isset( $link['url'] ) ? $link['url'] : '' );
			if ( $relative ) { $allowed_urls[ $relative ] = true; }
		}
		$out = array();
		foreach ( is_array( $article_links ) ? $article_links : array() as $link ) {
			if ( count( $out ) >= $limit || ! is_array( $link ) ) { break; }
			$relative = self::relative_internal_url( isset( $link['url'] ) ? $link['url'] : '' );
			if ( ! $relative || empty( $allowed_urls[ $relative ] ) ) { continue; }
			$title = sanitize_text_field( isset( $link['title'] ) ? $link['title'] : '' );
			$anchor = sanitize_text_field( isset( $link['anchor'] ) ? $link['anchor'] : '' );
			$key = md5( $relative );
			if ( isset( $out[ $key ] ) ) { continue; }
			$out[ $key ] = array( 'title' => $title, 'anchor' => $anchor ? $anchor : ( $title ? $title : $relative ), 'url' => $relative );
		}
		return array_values( $out );
	}

	private static function relative_internal_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/^(?:javascript|data|vbscript):/i', $url ) ) { return ''; }
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) { return ''; }
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $host && $home_host && strtolower( $host ) !== strtolower( $home_host ) ) { return ''; }
		$relative = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $url ) : ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
		if ( ! is_string( $relative ) || 0 !== strpos( $relative, '/' ) ) { return ''; }
		return esc_url_raw( $relative );
	}

	private static function insert_internal_links( $content, $links ) {
		if ( ! $links || ! class_exists( 'DOMDocument' ) ) { return $content; }
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$errors = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>', LIBXML_NONET );
		libxml_clear_errors(); libxml_use_internal_errors( $errors );
		$xpath = new DOMXPath( $doc );
		$used = array();
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) { $used[ self::relative_internal_url( $a->getAttribute( 'href' ) ) ] = true; }
		$changed = false;
		foreach ( $links as $link ) {
			$url = self::relative_internal_url( $link['url'] ?? '' );
			$anchor = trim( (string) ( $link['anchor'] ?? '' ) );
			if ( ! $url || ! $anchor || isset( $used[ $url ] ) ) { continue; }
			$nodes = $xpath->query( '//p//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::code)]' );
			foreach ( $nodes as $node ) {
				if ( ! preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $anchor, '/' ) . '(?![\p{L}\p{N}])/iu', $node->nodeValue, $match, PREG_OFFSET_CAPTURE ) ) { continue; }
				$text = $node->nodeValue; $start = $match[0][1]; $matched = $match[0][0];
				$fragment = $doc->createDocumentFragment();
				$fragment->appendChild( $doc->createTextNode( substr( $text, 0, $start ) ) );
				$a = $doc->createElement( 'a' ); $a->setAttribute( 'href', $url ); $a->appendChild( $doc->createTextNode( $matched ) ); $fragment->appendChild( $a );
				$fragment->appendChild( $doc->createTextNode( substr( $text, $start + strlen( $matched ) ) ) );
				$node->parentNode->replaceChild( $fragment, $node ); $used[ $url ] = true; $changed = true; break;
			}
		}
		if ( ! $changed ) { return $content; }
		$html = ''; foreach ( $doc->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) { $html .= $doc->saveHTML( $node ); }
		return $html;
	}

	private static function write_recipe_meta( $post_id, $recipe ) {
		$mapping = MSRWA_Settings::get()['integration_mapping'];
		$map = array(
			'prep_minutes'    => $mapping['prep_minutes'],
			'cook_minutes'    => $mapping['cook_minutes'],
			'servings'        => $mapping['servings'],
			'calories_estimate' => $mapping['calories_estimate'],
			'cuisine'         => $mapping['cuisine'],
			'difficulty'      => $mapping['difficulty'],
		);
		foreach ( $map as $source => $key ) {
			if ( $key && isset( $recipe[ $source ] ) ) {
				$value = in_array( $source, array( 'prep_minutes', 'cook_minutes', 'servings', 'calories_estimate' ), true ) ? absint( $recipe[ $source ] ) : sanitize_text_field( $recipe[ $source ] );
				update_post_meta( $post_id, $key, $value );
			}
		}
		$ingredients = array();
		foreach ( isset( $recipe['ingredients'] ) && is_array( $recipe['ingredients'] ) ? $recipe['ingredients'] : array() as $item ) {
			if ( is_string( $item ) ) { $ingredients[] = sanitize_text_field( $item ); continue; }
			$name = isset( $item['name'] ) ? $item['name'] : ( isset( $item['ingredient'] ) ? $item['ingredient'] : '' );
			$quantity = isset( $item['quantity'] ) ? $item['quantity'] : ( isset( $item['quantite'] ) ? $item['quantite'] : '' );
			$unit = isset( $item['unit'] ) ? $item['unit'] : ( isset( $item['unite'] ) ? $item['unite'] : '' );
			$ingredients[] = trim( sanitize_text_field( $quantity . ' ' . $unit . ' ' . $name ) );
		}
		$steps = array();
		foreach ( isset( $recipe['steps'] ) && is_array( $recipe['steps'] ) ? $recipe['steps'] : array() as $step ) {
			$steps[] = sanitize_textarea_field( is_array( $step ) ? ( $step['text'] ?? ( $step['step'] ?? '' ) ) : $step );
		}
		if ( ! empty( $mapping['ingredients'] ) ) { update_post_meta( $post_id, $mapping['ingredients'], implode( "\n", array_filter( $ingredients ) ) ); }
		if ( ! empty( $mapping['instructions'] ) ) { update_post_meta( $post_id, $mapping['instructions'], implode( "\n", array_filter( $steps ) ) ); }
		if ( isset( $recipe['calories_estimate'] ) ) { update_post_meta( $post_id, '_recipe_calories_estimated', '1' ); }
		if ( ! empty( $recipe['keywords'] ) && ! empty( $mapping['keywords'] ) ) { update_post_meta( $post_id, $mapping['keywords'], sanitize_text_field( is_array( $recipe['keywords'] ) ? implode( ', ', $recipe['keywords'] ) : $recipe['keywords'] ) ); }
		if ( ! empty( $recipe['equipment'] ) && ! empty( $mapping['equipment'] ) ) { update_post_meta( $post_id, $mapping['equipment'], sanitize_textarea_field( is_array( $recipe['equipment'] ) ? implode( "\n", $recipe['equipment'] ) : $recipe['equipment'] ) ); }
		if ( ! empty( $recipe['notes'] ) && ! empty( $mapping['notes'] ) ) { update_post_meta( $post_id, $mapping['notes'], sanitize_textarea_field( is_array( $recipe['notes'] ) ? implode( "\n", $recipe['notes'] ) : $recipe['notes'] ) ); }
		if ( ! empty( $recipe['faq'] ) && ! empty( $mapping['faq'] ) ) {
			$faq = array();
			foreach ( is_array( $recipe['faq'] ) ? $recipe['faq'] : array() as $item ) {
				if ( ! is_array( $item ) || empty( $item['question'] ) || empty( $item['answer'] ) ) { continue; }
				$faq[] = array( 'question' => sanitize_text_field( $item['question'] ), 'answer' => sanitize_textarea_field( $item['answer'] ) );
			}
			if ( $faq ) { update_post_meta( $post_id, $mapping['faq'], wp_json_encode( $faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); }
		}
	}

	private static function write_seo_meta( $post_id, $article ) {
		$mapping = MSRWA_Settings::get()['integration_mapping'];
		if ( $mapping['seo_title'] && isset( $article['seo_title'] ) ) { update_post_meta( $post_id, $mapping['seo_title'], sanitize_text_field( $article['seo_title'] ) ); }
		if ( $mapping['seo_description'] && isset( $article['seo_description'] ) ) { update_post_meta( $post_id, $mapping['seo_description'], sanitize_textarea_field( $article['seo_description'] ) ); }
	}

	private static function write_taxonomies( $post_id, $article ) {
		foreach ( array( 'tags' => 'post_tag', 'categories' => 'category' ) as $source => $taxonomy ) {
			if ( empty( $article[ $source ] ) ) { continue; }
			$terms = is_array( $article[ $source ] ) ? $article[ $source ] : array( $article[ $source ] );
			$terms = array_values( array_filter( array_map( 'sanitize_text_field', $terms ) ) );
			if ( $terms ) { wp_set_post_terms( $post_id, $terms, $taxonomy, false ); }
		}
	}

	private static function write_facebook_meta( $post_id, $article, $artifacts ) {
		$mapping = MSRWA_Settings::get()['integration_mapping'];
		$items = array();
		if ( ! empty( $artifacts['facebook_image']['attachment_id'] ) ) {
			$items[] = array( 'id' => absint( $artifacts['facebook_image']['attachment_id'] ), 'text' => '' );
		}
		if ( $items && post_type_exists( 'post' ) ) {
			$caption = isset( $article['facebook_caption'] ) ? sanitize_textarea_field( $article['facebook_caption'] ) : '';
			if ( $caption ) { $items[0]['text'] = $caption; }
			if ( $mapping['facebook_meta'] ) { update_post_meta( $post_id, $mapping['facebook_meta'], wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); }
		}
	}

	private static function write_provenance( $post_id, $job, $artifacts ) {
		$provenance = array(
			'plugin_version' => defined( 'MSRWA_VERSION' ) ? MSRWA_VERSION : '',
			'job_id' => absint( $job->id ),
			'batch_id' => absint( $job->batch_id ),
			'sources' => isset( $artifacts['sources'] ) && is_array( $artifacts['sources'] ) ? array_slice( $artifacts['sources'], 0, 20 ) : array(),
			'selected_models' => json_decode( (string) $job->selected_models_json, true ),
			'correction_history' => isset( $artifacts['correction_history'] ) && is_array( $artifacts['correction_history'] ) ? $artifacts['correction_history'] : array(),
			'created_at' => current_time( 'mysql', true ),
		);
		update_post_meta( $post_id, '_msrwa_provenance', wp_json_encode( $provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
