<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the engine produced, turned into a draft an editor can open.
 *
 * A draft, never a published post, and never phrased as approved: the engine's
 * judge decides whether an article holds together, not whether this site wants
 * it. The verdict travels with the draft so the editor reads it before
 * publishing rather than after.
 */
final class MSRWA_Draft {

	/**
	 * Creates the draft for a finished run, once.
	 *
	 * A run that produced no article produces no draft — a post with a title and
	 * nothing under it is worse than no post, because it looks like work that
	 * succeeded.
	 */
	public static function create( $run_id ) {
		$run = MSRWA_Run::get( $run_id );
		if ( ! $run || (int) $run['draft_post_id'] ) { return 0; }

		$artifacts = MSRWA_Run::artifacts( $run_id );
		// The latest text there is — proofread if it ran, otherwise corrected,
		// otherwise the draft as first written — over the article's own
		// metadata. Proofreading returns the body alone; taking its artifact
		// whole once left every draft without an excerpt, a slug or an SEO title.
		$article = array_merge(
			(array) ( $artifacts['article'] ?? array() ),
			(array) ( $artifacts['corrected'] ?? array() ),
			(array) ( $artifacts['proofread'] ?? array() )
		);
		$html = (string) ( $article['content_html'] ?? '' );
		if ( '' === trim( $html ) ) { return 0; }

		$canonical = (array) ( $artifacts['canonical'] ?? array() );
		$brief = (array) json_decode( (string) $run['brief_json'], true );
		$title = (string) ( $canonical['title'] ?? $article['title'] ?? $brief['title'] ?? 'Recette' );

		$post = array(
			'post_type' => 'post', 'post_status' => 'draft',
			'post_title' => wp_strip_all_tags( $title ),
			'post_content' => $html,
			'post_excerpt' => wp_strip_all_tags( (string) ( $article['excerpt'] ?? '' ) ),
			'post_author' => (int) $run['owner_id'],
		);
		$slug = sanitize_title( (string) ( $article['slug'] ?? '' ) );
		if ( '' !== $slug ) { $post['post_name'] = $slug; }
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) { return 0; }

		self::remember( $post_id, $run_id, $canonical, (array) ( $artifacts['approval'] ?? array() ) );
		self::describe( $post_id, $article, $canonical );
		$batch = MSRWA_Batch::get( (int) $run['batch_id'] );
		if ( $batch && '' !== (string) $batch['language'] ) { update_post_meta( $post_id, '_msrwa_language', sanitize_key( (string) $batch['language'] ) ); }
		self::attach_images( $post_id, $run_id, $artifacts, $title );

		global $wpdb;
		$t = MSRWA_DB::tables();
		$wpdb->update( $t['runs'], array( 'draft_post_id' => (int) $post_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $run_id ) ) );

		// WordPress now holds the article, the recipe, the verdict and the
		// images. A second copy in this plugin's tables would only be the older
		// one: an editor corrects the post, not the row.
		MSRWA_Run::release_stored( $run_id );
		return (int) $post_id;
	}

	/** The recipe and the verdict, kept with the post an editor will read. */
	private static function remember( $post_id, $run_id, array $canonical, array $approval ) {
		update_post_meta( $post_id, '_msrwa_run_id', (int) $run_id );
		if ( $canonical ) { update_post_meta( $post_id, '_msrwa_recipe', wp_slash( wp_json_encode( $canonical ) ) ); }
		if ( ! $approval ) { return; }
		// `approved` here is the engine judge's, never this site's editorial
		// decision. The meta key says so, and the screen that prints it says so.
		update_post_meta( $post_id, '_msrwa_judge_approved', empty( $approval['approved'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_msrwa_judge_report', wp_slash( wp_json_encode( MSRWA_DB::sanitize( $approval ) ) ) );
	}

	/**
	 * What the article said about itself, where a site will look for it.
	 *
	 * The article step writes an SEO title and description, tags, categories, a
	 * Facebook caption and a slug, and the recipe carries its times and yield.
	 * All of it used to stop at the artifact: the draft had a body and nothing
	 * an SEO plugin, a recipe card or a social post could read.
	 */
	private static function describe( $post_id, array $article, array $canonical ) {
		$seo_title = trim( wp_strip_all_tags( (string) ( $article['seo_title'] ?? '' ) ) );
		$seo_description = trim( wp_strip_all_tags( (string) ( $article['seo_description'] ?? '' ) ) );
		$caption = trim( wp_strip_all_tags( (string) ( $article['facebook_caption'] ?? '' ) ) );
		if ( '' !== $seo_title ) { update_post_meta( $post_id, '_msrwa_seo_title', $seo_title ); }
		if ( '' !== $seo_description ) { update_post_meta( $post_id, '_msrwa_seo_description', $seo_description ); }
		if ( '' !== $caption ) { update_post_meta( $post_id, '_msrwa_facebook_caption', $caption ); }

		// The two SEO plugins most sites run, each only when it is there to read
		// its own keys: writing a plugin's meta onto a site without it is litter.
		if ( defined( 'WPSEO_VERSION' ) ) {
			if ( '' !== $seo_title ) { update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title ); }
			if ( '' !== $seo_description ) { update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_description ); }
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			if ( '' !== $seo_title ) { update_post_meta( $post_id, 'rank_math_title', $seo_title ); }
			if ( '' !== $seo_description ) { update_post_meta( $post_id, 'rank_math_description', $seo_description ); }
		}

		$tags = array_values( array_filter( array_map( static function ( $tag ) { return trim( wp_strip_all_tags( (string) $tag ) ); }, self::listed( $article['tags'] ?? array() ) ) ) );
		if ( $tags ) { wp_set_post_tags( $post_id, array_slice( $tags, 0, 12 ), false ); }

		// A category is part of a site's structure, so the article may only choose
		// among the ones that exist; it never invents a new one.
		$categories = array();
		foreach ( self::listed( $article['categories'] ?? array() ) as $name ) {
			$term = get_term_by( 'name', trim( wp_strip_all_tags( (string) $name ) ), 'category' );
			if ( $term && ! is_wp_error( $term ) ) { $categories[] = (int) $term->term_id; }
		}
		if ( $categories ) { wp_set_post_categories( $post_id, array_unique( $categories ), false ); }

		self::map_recipe( $post_id, $canonical, $article );
	}

	/**
	 * The recipe fields, under the meta keys the site's recipe card reads.
	 * The mapping is a setting because every card plugin names them differently.
	 */
	private static function map_recipe( $post_id, array $canonical, array $article ) {
		$mapping = (array) ( MSRWA_Settings::get()['integration_mapping'] ?? array() );
		$values = $canonical;
		$values['seo_title'] = $article['seo_title'] ?? '';
		$values['seo_description'] = $article['seo_description'] ?? '';
		// The recipe calls them steps; the cards that read this meta call them instructions.
		if ( isset( $canonical['steps'] ) ) { $values['instructions'] = $canonical['steps']; }
		foreach ( $mapping as $field => $meta_key ) {
			$meta_key = sanitize_key( (string) $meta_key );
			if ( '' === $meta_key || 'facebook_meta' === $field || ! isset( $values[ $field ] ) ) { continue; }
			$value = $values[ $field ];
			if ( is_array( $value ) ) {
				$value = wp_slash( wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) );
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	/** A list the model may have written as an array or as "a, b, c". */
	private static function listed( $value ) {
		if ( is_array( $value ) ) { return $value; }
		return '' === trim( (string) $value ) ? array() : explode( ',', (string) $value );
	}

	/**
	 * Brings the generated images into the media library.
	 *
	 * The engine writes them to the run's workspace as files; the library is
	 * where WordPress expects an image to live, and a featured image that is not
	 * an attachment cannot be set.
	 */
	private static function attach_images( $post_id, $run_id, array $artifacts, $title ) {
		foreach ( array( 'featured' => 'Image à la une', 'facebook' => 'Image Facebook' ) as $kind => $label ) {
			$image = (array) ( $artifacts[ $kind ] ?? array() );
			$path = (string) ( $image['path'] ?? '' );
			if ( '' === $path || ! is_readable( $path ) ) { continue; }

			$attachment = self::sideload( $path, $post_id, $title . ' — ' . $label, (string) ( $image['mime'] ?? 'image/webp' ) );
			if ( ! $attachment ) { continue; }
			if ( 'featured' === $kind ) { set_post_thumbnail( $post_id, $attachment ); }
			update_post_meta( $post_id, '_msrwa_' . $kind . '_image_id', (int) $attachment );
		}
	}

	/** Copies one generated file into the uploads directory as an attachment. */
	private static function sideload( $path, $post_id, $title, $mime ) {
		$uploaded = wp_upload_bits( sanitize_file_name( basename( $path ) ), null, (string) file_get_contents( $path ) );
		if ( ! empty( $uploaded['error'] ) ) { return 0; }

		$attachment = wp_insert_attachment( array(
			'post_mime_type' => $mime, 'post_title' => wp_strip_all_tags( $title ),
			'post_content' => '', 'post_status' => 'inherit',
		), $uploaded['file'], $post_id, true );
		if ( is_wp_error( $attachment ) || ! $attachment ) { return 0; }

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $uploaded['file'] ) );
		return (int) $attachment;
	}
}
