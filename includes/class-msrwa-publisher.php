<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Publisher {
	public static function create_draft( $job, $artifacts ) {
		global $wpdb;
		$article = isset( $artifacts['article'] ) && is_array( $artifacts['article'] ) ? $artifacts['article'] : array();
		$canonical = isset( $artifacts['canonical'] ) && is_array( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		if ( empty( $article['title'] ) || empty( $article['content_html'] ) ) { return new WP_Error( 'draft_content_missing', 'L’article validé ne contient pas le titre ou le contenu.' ); }
		$post_id = absint( isset( $job->draft_post_id ) ? $job->draft_post_id : 0 );
		$link_specs = self::internal_link_specs( isset( $article['internal_links'] ) ? $article['internal_links'] : array(), isset( $artifacts['internal_link_candidates'] ) ? $artifacts['internal_link_candidates'] : array() );
		$content = self::sanitize_content( (string) $article['content_html'], $link_specs );
		$content = self::append_internal_links( $content, $link_specs );
		if ( ! preg_match( '/<\w[\s\S]*>/i', $content ) ) { $content = wpautop( esc_html( $content ) ); }
		if ( isset( $canonical['calories_estimate'] ) && '' !== (string) $canonical['calories_estimate'] ) {
			$content .= '<p class="msrwa-nutrition-note">Valeurs nutritionnelles estimées par IA ; elles ne remplacent pas une analyse nutritionnelle professionnelle.</p>';
		}
		$post_data = array(
			'post_title'   => sanitize_text_field( $article['title'] ),
			'post_content' => $content,
			'post_excerpt' => isset( $article['excerpt'] ) ? sanitize_textarea_field( $article['excerpt'] ) : '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_author'  => self::author_id( $job ),
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
		if ( ! empty( $artifacts['featured_image']['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, absint( $artifacts['featured_image']['attachment_id'] ) );
		}
		if ( ! empty( $artifacts['facebook_image']['attachment_id'] ) ) {
			update_post_meta( $post_id, '_msrwa_facebook_image_id', absint( $artifacts['facebook_image']['attachment_id'] ) );
		}
		return (int) $post_id;
	}

	private static function author_id( $job ) {
		$owner = absint( $job->owner_id );
		if ( $owner && get_userdata( $owner ) ) { return $owner; }
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return ! empty( $admin[0] ) ? absint( $admin[0] ) : 1;
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
			$href = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $match[1] ) : ( wp_parse_url( $match[1], PHP_URL_PATH ) ?: '' );
			return in_array( $href, $allowed, true ) ? $match[0] : $match[2];
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
		foreach ( $allowed as $link ) {
			if ( count( $out ) >= $limit || ! is_array( $link ) ) { break; }
			$relative = self::relative_internal_url( isset( $link['url'] ) ? $link['url'] : '' );
			if ( ! $relative ) { continue; }
			$key = md5( $relative );
			if ( isset( $out[ $key ] ) ) { continue; }
			$title = sanitize_text_field( isset( $link['title'] ) ? $link['title'] : '' );
			$out[ $key ] = array( 'title' => $title, 'anchor' => $title ? $title : $relative, 'url' => $relative );
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

	private static function append_internal_links( $content, $links ) {
		if ( ! is_array( $links ) || empty( $links ) ) { return $content; }
		$items = array();
		$heading = sanitize_text_field( MSRWA_Settings::get()['internal_links_heading'] );
		if ( '' === $heading ) { return $content; }
		foreach ( $links as $link ) {
			$url = esc_url( $link['url'] );
			$anchor = esc_html( $link['anchor'] );
			if ( ! $url || ! $anchor || false !== strpos( $content, esc_attr( $link['url'] ) ) ) { continue; }
			$items[] = '<li><a href="' . $url . '">' . $anchor . '</a></li>';
		}
		if ( empty( $items ) ) { return $content; }
		return $content . '<section class="msrwa-internal-links" aria-labelledby="msrwa-internal-links-title"><h2 id="msrwa-internal-links-title">' . esc_html( $heading ) . '</h2><ul>' . implode( '', $items ) . '</ul></section>';
	}

	private static function write_recipe_meta( $post_id, $recipe ) {
		$mapping = MSRWA_Settings::get()['integration_mapping'];
		$map = array(
			'prep_minutes'    => $mapping['prep_minutes'],
			'cook_minutes'    => $mapping['cook_minutes'],
			'servings'        => $mapping['servings'],
			'calories_estimate' => $mapping['calories_estimate'],
			'cuisine'         => $mapping['cuisine'],
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
		update_post_meta( $post_id, '_recipe_ingredients', implode( "\n", array_filter( $ingredients ) ) );
		update_post_meta( $post_id, '_recipe_instructions', implode( "\n", array_filter( $steps ) ) );
		if ( isset( $recipe['calories_estimate'] ) ) { update_post_meta( $post_id, '_recipe_calories_estimated', '1' ); }
		if ( ! empty( $recipe['keywords'] ) ) { update_post_meta( $post_id, '_recipe_keywords', sanitize_text_field( is_array( $recipe['keywords'] ) ? implode( ', ', $recipe['keywords'] ) : $recipe['keywords'] ) ); }
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
