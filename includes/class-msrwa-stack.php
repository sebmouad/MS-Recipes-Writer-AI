<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The draft, written the way the rest of the MS stack reads it.
 *
 * The MS Recipes theme renders the recipe card and, when no SEO plugin runs,
 * the canonical, Open Graph and Recipe JSON-LD; MS SEO Plus reads the same
 * keys when it does; MS FB Posts reads `fb_images_data`; MS Image Optimizer
 * names, titles and describes the images from `_seo_title` and
 * `_seo_description`. Each one parses its own format — plain lines for lists,
 * whole minutes, a fixed difficulty vocabulary, a JSON string of image and
 * caption — and a value in the wrong shape is worse than none: the card
 * printed the JSON of the ingredient list as the ingredient list.
 *
 * Nothing here is invented to fill a field. A value the recipe does not have
 * stays empty, because every one of these fields reaches a search engine as a
 * fact about the dish.
 */
final class MSRWA_Stack {

	/** The keys written here, which the generic mapping must leave alone. */
	const KEYS = array(
		'_recipe_prep_time', '_recipe_cook_time', '_recipe_servings', '_recipe_calories',
		'_recipe_cuisine', '_recipe_keywords', '_recipe_difficulty', '_recipe_ingredients',
		'_recipe_instructions', '_recipe_equipment', '_recipe_notes', '_recipe_faq',
		'_seo_title', '_seo_description', 'fb_images_data',
	);

	/** What the MS SEO fields allow before a search engine cuts them. */
	const SEO_TITLE_MAX = 60;
	const SEO_DESCRIPTION_MAX = 155;

	/** The image ratios Google asks a recipe for, beside the square one drawn. */
	const RATIOS = array( 'msrwa-4x3' => array( 4, 3 ), 'msrwa-16x9' => array( 16, 9 ) );

	public static function hooks() {
		// MS Image Optimizer refuses to touch an image when PHP allows less than
		// 45 seconds, and a host's 30-second default is common: every featured
		// image this plugin generated failed there with "execution_limit_too_low".
		// Its own workers are given the time they ask for, and nothing else is.
		add_filter( 'msimg_reference_structured_sources', array( __CLASS__, 'not_references' ), 10, 1 );
		foreach ( array( 'msimg_image_queue_cron', 'msimg_post_discovery_cron', 'msimg_recovery_cron', 'wp_ajax_msimg_async_post_discovery', 'wp_ajax_nopriv_msimg_async_post_discovery' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'room_for_images' ), 0 );
		}
		// The theme applies both filters to one graph, MS SEO Plus the second:
		// enriching is idempotent, so being called twice changes nothing.
		add_filter( 'ms_recipes_seo_structured_data', array( __CLASS__, 'enrich' ), 20, 2 );
		add_filter( 'ms_seo_plus_structured_data', array( __CLASS__, 'enrich' ), 20, 2 );
	}

	/** The MS Recipes theme is printing canonical, Open Graph and Recipe markup. */
	/**
	 * The attachment details of a generated image: alternative text and title
	 * from the SEO title, caption and description from the SEO description,
	 * each capped as MS Image Optimizer caps them. It is that plugin's own
	 * mapping for every image role, so it finds nothing to rewrite, and a site
	 * without it still gets all four fields filled.
	 */
	public static function describe_image( $post_id, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) { return false; }
		$post = get_post( absint( $post_id ) );
		if ( ! $post ) { return false; }
		$title = self::text( (string) get_post_meta( $post->ID, '_seo_title', true ) );
		if ( '' === $title ) { $title = self::text( (string) $post->post_title ); }
		$description = self::text( (string) get_post_meta( $post->ID, '_seo_description', true ) );
		if ( '' === $description ) { $description = self::text( (string) $post->post_excerpt ); }
		// Cut exactly as the optimizer cuts, or it would see a difference to fix.
		$title = mb_substr( $title, 0, 125 );
		$description = mb_substr( $description, 0, 160 );
		if ( '' === $title ) { return false; }
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
		wp_update_post( array( 'ID' => $attachment_id, 'post_title' => $title, 'post_excerpt' => $description, 'post_content' => $description ) );
		return true;
	}

	/**
	 * Tells MS Image Optimizer which stored numbers are not attachment ids.
	 *
	 * Before it edits an image it searches every post meta and option for the
	 * attachment's id, and a match anywhere marks the image as shared and
	 * leaves it alone. A collage that happened to be attachment 150 was never
	 * compressed because a recipe cooks for 150 minutes (`_recipe_cook_time`)
	 * and WordPress's thumbnails are 150 pixels wide (`thumbnail_size_w`).
	 * Recipe figures, this plugin's own records and the image-size settings
	 * hold numbers, never attachment ids.
	 */
	public static function not_references( $sources ) {
		global $wpdb;
		foreach ( (array) $sources as $index => $source ) {
			$table = (string) ( $source['table'] ?? '' );
			if ( $table === $wpdb->postmeta ) {
				$sources[ $index ]['where'] = trim( (string) ( $source['where'] ?? '' ) . ( '' !== trim( (string) ( $source['where'] ?? '' ) ) ? ' AND ' : '' ) . "meta_key NOT LIKE '\\_recipe\\_%' AND meta_key NOT LIKE '\\_msrwa\\_%'" );
			} elseif ( $table === $wpdb->options ) {
				$sources[ $index ]['where'] = trim( (string) ( $source['where'] ?? '' ) . ( '' !== trim( (string) ( $source['where'] ?? '' ) ) ? ' AND ' : '' ) . "option_name NOT IN ('thumbnail_size_w','thumbnail_size_h','medium_size_w','medium_size_h','medium_large_size_w','medium_large_size_h','large_size_w','large_size_h','posts_per_page','posts_per_rss','page_on_front','page_for_posts','msrwa_settings')" );
			}
		}
		return $sources;
	}

	/**
	 * Re-encodes a generated image already in the media library once, when it
	 * is still a lossless WebP, and rebuilds its sizes. Images stored before
	 * MSRWA_Draft::lossy() existed could not be compressed by anything.
	 */
	public static function make_lossy( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$path = $attachment_id ? (string) get_attached_file( $attachment_id ) : '';
		if ( '' === $path || ! is_file( $path ) || 'image/webp' !== get_post_mime_type( $attachment_id ) ) { return false; }
		$bytes = MSRWA_Draft::lossy( $path, 'image/webp' );
		if ( strlen( $bytes ) >= (int) filesize( $path ) ) { return false; }
		if ( false === file_put_contents( $path, $bytes ) ) { return false; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		return true;
	}

	/**
	 * Hands posts back to MS Image Optimizer, through its own queue, when it is
	 * active. Returns how many image jobs it queued.
	 */
	public static function reoptimize( array $post_ids ) {
		if ( ! class_exists( 'MSIMG_Plugin' ) || ! method_exists( 'MSIMG_Plugin', 'instance' ) ) { return 0; }
		$worker = MSIMG_Plugin::instance()->worker ?? null;
		if ( ! is_object( $worker ) || ! method_exists( $worker, 'enqueue_post' ) ) { return 0; }
		$queued = 0;
		foreach ( array_slice( array_map( 'absint', $post_ids ), 0, 100 ) as $post_id ) {
			if ( $post_id ) { $queued += (int) $worker->enqueue_post( $post_id, '', 50 ); }
		}
		return $queued;
	}

	/** Raises the PHP time limit for MS Image Optimizer's worker, when it is set and too low. */
	public static function room_for_images() {
		$want = (int) apply_filters( 'msrwa_image_optimizer_seconds', 180 );
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $want > 0 && $limit > 0 && $limit < $want && function_exists( 'set_time_limit' ) ) { @set_time_limit( $want ); }
	}

	public static function theme_owns_seo() {
		return function_exists( 'ms_recipes_seo_plugin_active' ) && function_exists( 'ms_recipes_seo_singular_schema' ) && ! ms_recipes_seo_plugin_active();
	}

	public static function ms_seo_plus_active() { return defined( 'MSSEO_PLUGIN_VERSION' ) || class_exists( 'MS_SEO_Plus' ); }

	/** Somebody in the stack prints the head tags and the schema; this plugin only feeds them. */
	public static function owns_head() { return self::theme_owns_seo() || self::ms_seo_plus_active(); }

	/** Writes every stack field for one draft. */
	public static function write( $post_id, array $canonical, array $article, $facebook_id = 0 ) {
		$post_id = absint( $post_id );
		$language = (string) get_post_meta( $post_id, '_msrwa_language', true );
		foreach ( self::recipe_meta( $canonical, $article, $language ) as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		$caption = trim( wp_strip_all_tags( (string) ( $article['facebook_caption'] ?? '' ) ) );
		$facebook = self::facebook_data( (int) $facebook_id, $caption );
		// MS FB Posts refuses anything but a JSON string, and update_post_meta
		// unslashes what it is given: the escaped quotes are slashed back first.
		if ( '' !== $facebook ) { update_post_meta( $post_id, 'fb_images_data', wp_slash( $facebook ) ); }

		$categories = self::category_ids( array_merge( self::listed( $article['categories'] ?? array() ), array( (string) ( $canonical['recipe_category'] ?? '' ) ) ) );
		if ( $categories ) {
			wp_set_post_categories( $post_id, $categories, false );
		} elseif ( '' !== trim( (string) ( $canonical['recipe_category'] ?? '' ) ) ) {
			// A category is the site's structure: none is created from a model's
			// word. The suggestion waits for the editor instead.
			update_post_meta( $post_id, '_msrwa_suggested_category', self::text( $canonical['recipe_category'] ) );
		}
	}

	/**
	 * The recipe card and SEO fields, key => stored value, in the shapes the
	 * theme's card and MS SEO Plus parse. Pure: reads nothing, writes nothing.
	 */
	public static function recipe_meta( array $canonical, array $article, $language = 'fr' ) {
		$out = array();
		foreach ( array( '_recipe_prep_time' => 'prep_minutes', '_recipe_cook_time' => 'cook_minutes', '_recipe_servings' => 'servings', '_recipe_calories' => 'calories_estimate' ) as $key => $field ) {
			$number = (int) round( (float) ( $canonical[ $field ] ?? 0 ) );
			if ( $number > 0 ) { $out[ $key ] = (string) $number; }
		}
		$cuisine = self::text( $canonical['cuisine'] ?? '' );
		if ( '' !== $cuisine ) { $out['_recipe_cuisine'] = self::capitalise( $cuisine ); }

		$keywords = array_filter( array_map( array( __CLASS__, 'text' ), self::listed( $canonical['keywords'] ?? ( $article['tags'] ?? array() ) ) ) );
		if ( $keywords ) { $out['_recipe_keywords'] = implode( ', ', array_slice( array_unique( $keywords ), 0, 12 ) ); }

		$difficulty = self::difficulty( $canonical['difficulty'] ?? '' );
		if ( '' !== $difficulty ) { $out['_recipe_difficulty'] = $difficulty; }

		$ingredients = array();
		foreach ( (array) ( $canonical['ingredients'] ?? array() ) as $ingredient ) {
			$line = is_array( $ingredient ) ? self::ingredient( $ingredient, $language ) : self::text( $ingredient );
			if ( '' !== $line ) { $ingredients[] = $line; }
		}
		if ( $ingredients ) { $out['_recipe_ingredients'] = implode( "\n", $ingredients ); }

		$steps = array();
		foreach ( (array) ( $canonical['steps'] ?? $canonical['instructions'] ?? array() ) as $step ) {
			$line = self::text( is_array( $step ) ? ( $step['text'] ?? '' ) : $step );
			if ( '' !== $line ) { $steps[] = $line; }
		}
		if ( $steps ) { $out['_recipe_instructions'] = implode( "\n", $steps ); }

		foreach ( array( '_recipe_equipment' => 'equipment', '_recipe_notes' => 'notes' ) as $key => $field ) {
			$lines = array_values( array_filter( array_map( array( __CLASS__, 'text' ), self::listed( $canonical[ $field ] ?? array() ) ) ) );
			if ( $lines ) { $out[ $key ] = implode( "\n", $lines ); }
		}

		$faq = array();
		foreach ( (array) ( $canonical['faq'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$question = self::text( $item['question'] ?? '' );
			$answer = self::text( $item['answer'] ?? '' );
			if ( '' !== $question && '' !== $answer ) { $faq[] = array( 'question' => $question, 'answer' => $answer ); }
		}
		if ( $faq ) { $out['_recipe_faq'] = (string) wp_json_encode( array_slice( $faq, 0, 6 ), JSON_UNESCAPED_UNICODE ); }

		$title = self::clip( self::text( $article['seo_title'] ?? '' ), self::SEO_TITLE_MAX );
		if ( '' === $title ) { $title = self::clip( self::text( $article['title'] ?? $canonical['title'] ?? '' ), self::SEO_TITLE_MAX ); }
		if ( '' !== $title ) { $out['_seo_title'] = $title; }
		$description = self::clip( self::text( $article['seo_description'] ?? '' ), self::SEO_DESCRIPTION_MAX );
		if ( '' === $description ) { $description = self::clip( self::text( $article['excerpt'] ?? '' ), self::SEO_DESCRIPTION_MAX ); }
		if ( '' !== $description ) { $out['_seo_description'] = $description; }

		return $out;
	}

	/** One ingredient as the card's servings scaler reads it: the quantity first. */
	public static function ingredient( array $ingredient, $language = 'fr' ) {
		$quantity = $ingredient['quantity'] ?? '';
		if ( is_numeric( $quantity ) ) {
			$quantity = rtrim( rtrim( number_format( (float) $quantity, 2, '.', '' ), '0' ), '.' );
			if ( 'en' !== $language ) { $quantity = str_replace( '.', ',', $quantity ); }
		}
		// "au goût", "une pincée": a quantity that is not a number goes after the
		// name, so the card's scaler never reads a word where a number belongs.
		if ( '' !== trim( (string) $quantity ) && ! is_numeric( str_replace( ',', '.', (string) $quantity ) ) ) {
			return self::text( trim( trim( (string) ( $ingredient['unit'] ?? '' ) . ' ' . (string) ( $ingredient['name'] ?? '' ) ) . ' (' . trim( (string) $quantity ) . ')' ) );
		}
		return self::text( MSRWA_Engine_Input::ingredient_line( array( 'quantity' => $quantity, 'unit' => $ingredient['unit'] ?? '', 'name' => $ingredient['name'] ?? '' ) ) );
	}

	/** The card knows three levels; anything else is left for the editor rather than guessed. */
	public static function difficulty( $value ) {
		$value = self::fold( (string) $value );
		foreach ( array(
			'easy' => array( 'facile', 'tres facile', 'simple', 'easy', 'debutant', 'sahl' ),
			'medium' => array( 'moyen', 'moyenne', 'intermediaire', 'medium', 'modere', 'moderee' ),
			'hard' => array( 'difficile', 'avance', 'avancee', 'expert', 'hard', 'difficult' ),
		) as $level => $words ) {
			if ( in_array( $value, $words, true ) ) { return $level; }
		}
		return '';
	}

	/** `fb_images_data` as MS FB Posts stores it: the collage and its caption. */
	public static function facebook_data( $attachment_id, $caption ) {
		if ( $attachment_id <= 0 ) { return ''; }
		return (string) wp_json_encode( array( array( 'id' => (int) $attachment_id, 'text' => (string) $caption ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The categories this site files posts under, for the article to choose
	 * from. Left to its own words, the model named "Apéritif" or "Cuisine
	 * française familiale" on a site that had neither, and the post stayed
	 * uncategorised. The default category is never offered; the most used
	 * come first, and a site with hundreds sends its first sixty.
	 */
	public static function site_categories() {
		$default = (int) get_option( 'default_category' );
		$names = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'number' => 61 ) ) as $term ) {
			if ( ! is_object( $term ) || (int) $term->term_id === $default ) { continue; }
			$names[] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		}
		return array_slice( $names, 0, 60 );
	}

	/**
	 * Existing categories the suggestions name, compared without accents, case
	 * or a plural's final letter: "plat principal" is filed under "Plats
	 * principaux". The default category never counts as a match.
	 */
	public static function category_ids( array $suggestions ) {
		$wanted = array();
		foreach ( $suggestions as $name ) {
			$key = self::term_key( (string) $name );
			if ( '' !== $key ) { $wanted[ $key ] = true; }
		}
		if ( ! $wanted ) { return array(); }
		$default = (int) get_option( 'default_category' );
		$ids = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) as $term ) {
			if ( ! is_object( $term ) || (int) $term->term_id === $default ) { continue; }
			if ( isset( $wanted[ self::term_key( $term->name ) ] ) || isset( $wanted[ self::term_key( str_replace( '-', ' ', $term->slug ) ) ] ) ) { $ids[] = (int) $term->term_id; }
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Files an article of this plugin's as it is published, when it still sits
	 * in the default category only: a draft written before the article chose
	 * from the site's list, a category created since, or none that fitted then.
	 * An editor's own choice is never touched, no category is created, and no
	 * model is asked — publishing stays instant and free.
	 */
	public static function on_publish( $new_status, $old_status, $post ) {
		if ( ! in_array( $new_status, array( 'publish', 'future' ), true ) || $new_status === $old_status ) { return; }
		if ( ! is_object( $post ) || 'post' !== $post->post_type ) { return; }
		$recipe = json_decode( (string) get_post_meta( $post->ID, '_msrwa_recipe', true ), true );
		if ( ! is_array( $recipe ) && ! get_post_meta( $post->ID, '_msrwa_run_id', true ) ) { return; }
		$default = (int) get_option( 'default_category' );
		$current = array_map( 'intval', (array) wp_get_post_categories( $post->ID ) );
		if ( array_diff( $current, array( $default, 0 ) ) ) { return; }

		$categories = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) as $term ) {
			if ( is_object( $term ) && (int) $term->term_id !== $default ) { $categories[ (int) $term->term_id ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ); }
		}
		$recipe = is_array( $recipe ) ? $recipe : array();
		$tags = array_map( static function ( $tag ) { return is_object( $tag ) ? (string) $tag->name : ''; }, (array) wp_get_post_tags( $post->ID ) );
		$chosen = self::choose_categories( $categories, array(
			3 => array( (string) get_post_meta( $post->ID, '_msrwa_suggested_category', true ), (string) ( $recipe['recipe_category'] ?? '' ), (string) ( $recipe['cuisine'] ?? '' ) ),
			2 => array_merge( array( (string) $post->post_title, (string) ( $recipe['title'] ?? '' ) ), self::listed( $recipe['keywords'] ?? array() ), $tags ),
		) );
		if ( ! $chosen ) { return; }
		wp_set_post_categories( $post->ID, $chosen, false );
		delete_post_meta( $post->ID, '_msrwa_suggested_category' );
	}

	/**
	 * The one or two categories the clues point to, or none. A category named
	 * in full by a clue wins outright; otherwise each of its words that a clue
	 * contains counts that clue's weight — "Gratin dauphinois" points to
	 * "Gratins", "rôti de porc" to "Viandes et porc" only through "porc". Words
	 * every recipe site shares carry nothing. Pure: the caller reads the site.
	 *
	 * @param array $categories id => name.
	 * @param array $clues      weight => list of texts.
	 */
	public static function choose_categories( array $categories, array $clues ) {
		$generic = array( 'recette', 'cuisine', 'maison', 'facile', 'rapide', 'idee', 'plat', 'autre', 'divers' );
		$texts = array();
		foreach ( $clues as $weight => $list ) {
			foreach ( (array) $list as $text ) {
				$key = self::term_key( (string) $text );
				if ( '' !== $key ) { $texts[] = array( (int) $weight, $key, array_flip( explode( ' ', $key ) ) ); }
			}
		}
		$scores = array();
		foreach ( $categories as $id => $name ) {
			$key = self::term_key( (string) $name );
			if ( '' === $key ) { continue; }
			// "Viandes et volailles" is either; "Pommes de terre" is both words,
			// or "Tarte aux pommes" would be filed with the potatoes.
			$alternatives = array();
			foreach ( preg_split( '/\s+(?:et|and|ou|or)\s+|[,&\/]/u', (string) $name ) as $part ) {
				$words = array_values( array_filter( explode( ' ', self::term_key( $part ) ), static function ( $word ) use ( $generic ) { return mb_strlen( $word ) > 3 && ! in_array( $word, $generic, true ); } ) );
				if ( $words ) { $alternatives[] = $words; }
			}
			$score = 0;
			foreach ( $texts as $text ) {
				if ( $text[1] === $key ) { $score += 10 * $text[0]; continue; }
				foreach ( $alternatives as $words ) {
					if ( ! array_diff_key( array_flip( $words ), $text[2] ) ) { $score += $text[0]; break; }
				}
			}
			if ( $score >= 2 ) { $scores[ (int) $id ] = $score; }
		}
		arsort( $scores );
		return array_slice( array_keys( $scores ), 0, 2 );
	}

	/** A category name reduced to what two spellings of it share. */
	public static function term_key( $name ) {
		$words = preg_split( '/\s+/', self::fold( $name ) );
		$words = array_map( static function ( $word ) {
			if ( preg_match( '/aux$/', $word ) ) { return substr( $word, 0, -3 ) . 'al'; }
			return mb_strlen( $word ) > 3 ? rtrim( $word, 'sx' ) : $word;
		}, array_filter( (array) $words ) );
		return implode( ' ', $words );
	}

	/**
	 * What this plugin knows that the theme's Recipe graph does not: the images
	 * in the three ratios Google asks for, the tools, the language, the recipe's
	 * own category when the post has none of its own, and the total time when
	 * the recipe rests or marinates beyond its prep and cooking.
	 */
	public static function enrich( $graph, $post ) {
		if ( ! is_array( $graph ) || ! $post instanceof WP_Post || 'Recipe' !== ( $graph['@type'] ?? '' ) ) { return $graph; }
		if ( ! get_post_meta( $post->ID, '_msrwa_run_id', true ) ) { return $graph; }
		$recipe = json_decode( (string) get_post_meta( $post->ID, '_msrwa_recipe', true ), true );
		$recipe = is_array( $recipe ) ? $recipe : array();

		$thumbnail = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumbnail ) {
			$images = (array) ( $graph['image'] ?? array() );
			foreach ( array_keys( self::RATIOS ) as $size ) {
				$src = wp_get_attachment_image_src( $thumbnail, $size );
				if ( $src && ! empty( $src[3] ) ) { $images[] = (string) $src[0]; }
			}
			$graph['image'] = array_values( array_unique( array_filter( $images ) ) );
		}

		$default = get_term( (int) get_option( 'default_category' ), 'category' );
		$uncategorised = is_object( $default ) && isset( $graph['recipeCategory'] ) && $graph['recipeCategory'] === $default->name;
		$category = self::text( $recipe['recipe_category'] ?? '' );
		if ( '' !== $category && ( empty( $graph['recipeCategory'] ) || $uncategorised ) ) { $graph['recipeCategory'] = self::capitalise( $category ); }

		$tools = array();
		foreach ( self::listed( $recipe['equipment'] ?? array() ) as $tool ) {
			$tool = self::text( $tool );
			if ( '' !== $tool ) { $tools[] = array( '@type' => 'HowToTool', 'name' => $tool ); }
		}
		if ( $tools && empty( $graph['tool'] ) ) { $graph['tool'] = $tools; }

		$total = (int) ( $recipe['total_minutes'] ?? 0 );
		$counted = (int) ( $recipe['prep_minutes'] ?? 0 ) + (int) ( $recipe['cook_minutes'] ?? 0 );
		if ( $total > $counted ) { $graph['totalTime'] = 'PT' . $total . 'M'; }

		$language = (string) get_post_meta( $post->ID, '_msrwa_language', true );
		if ( '' !== $language && empty( $graph['inLanguage'] ) ) { $graph['inLanguage'] = $language; }
		return $graph;
	}

	/**
	 * The featured image cropped to 4:3 and 16:9 from its centre, recorded as
	 * sizes of the same attachment. The engine draws a square; Google's recipe
	 * results pick among the three ratios, and a missing one is a missing slot.
	 */
	public static function crops( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( '' === $file || ! is_readable( $file ) || ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) { return 0; }
		$made = 0;
		foreach ( self::RATIOS as $size => $ratio ) {
			$width = (int) $meta['width'];
			$height = (int) floor( $width * $ratio[1] / $ratio[0] );
			if ( $height > (int) $meta['height'] ) { $height = (int) $meta['height']; $width = (int) floor( $height * $ratio[0] / $ratio[1] ); }
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) { continue; }
			$editor->crop( (int) floor( ( $meta['width'] - $width ) / 2 ), (int) floor( ( $meta['height'] - $height ) / 2 ), $width, $height );
			$saved = $editor->save( $editor->generate_filename( $width . 'x' . $height ) );
			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) { continue; }
			$meta['sizes'][ $size ] = array( 'file' => $saved['file'], 'width' => $width, 'height' => $height, 'mime-type' => $saved['mime-type'] ?? '' );
			$made++;
		}
		if ( $made ) { wp_update_attachment_metadata( $attachment_id, $meta ); }
		return $made;
	}

	/** A list a model wrote as an array, or as "a, b, c" or one per line. */
	public static function listed( $value ) {
		if ( is_array( $value ) ) { return array_values( $value ); }
		$value = trim( (string) $value );
		return '' === $value ? array() : preg_split( '/\r\n|\r|\n|,/', $value );
	}

	/** Model output as text: tags stripped, whitespace collapsed. */
	public static function text( $value ) {
		return is_scalar( $value ) ? trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) ) : '';
	}

	/** Cut at a word boundary, never mid-word, and never with a trailing comma. */
	public static function clip( $text, $max ) {
		if ( mb_strlen( $text ) <= $max ) { return $text; }
		$cut = mb_substr( $text, 0, $max + 1 );
		$space = mb_strrpos( $cut, ' ' );
		$cut = false === $space || $space < $max * 0.6 ? mb_substr( $text, 0, $max ) : mb_substr( $cut, 0, $space );
		return rtrim( $cut, " ,;:–—-" );
	}

	private static function capitalise( $text ) { return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 ); }

	private static function fold( $text ) {
		$text = mb_strtolower( trim( (string) $text ) );
		if ( function_exists( 'remove_accents' ) ) { return remove_accents( $text ); }
		$ascii = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ) : false;
		return false === $ascii ? $text : preg_replace( '/[^a-z0-9 ]/', '', strtolower( $ascii ) );
	}
}
