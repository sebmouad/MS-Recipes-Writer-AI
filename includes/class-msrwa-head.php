<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the published article tells search engines and social networks.
 *
 * The article step writes an SEO title, an SEO description and a Facebook
 * caption, and the engine draws a 1200x630 image for sharing. All four were
 * stored on the post and then read by nobody: unless the site happened to run
 * Yoast or Rank Math, the description never reached a `<meta>` tag and the
 * shared link fell back to whatever the theme offered.
 *
 * So the plugin prints them itself — and steps aside the moment an SEO plugin
 * is active, on the same reasoning as the Recipe markup: two descriptions of
 * one page is a worse answer than one, and the site's SEO plugin is the one
 * its owner configured.
 */
final class MSRWA_Head {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 4 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
	}

	/**
	 * SEO plugins that own the description and the Open Graph tags.
	 *
	 * Checked as constants rather than through each plugin's own filters: the
	 * question here is only "is somebody else printing this", and a constant is
	 * answerable before `wp_head` runs.
	 */
	public static function another_plugin_prints_it() {
		// The MS Recipes theme prints them itself when no SEO plugin runs, and
		// MS SEO Plus when it does: this plugin then feeds their fields instead.
		return MSRWA_Stack::owns_head( 'meta' ) || self::seo_plugin_active();
	}

	/** A dedicated SEO plugin, which may print its own FAQ and keywords. */
	public static function seo_plugin_active() {
		foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'SEOPRESS_VERSION', 'AIOSEO_VERSION', 'SLIM_SEO_VER', 'SQ_VERSION', 'THE_SEO_FRAMEWORK_PRESENT' ) as $constant ) {
			if ( defined( $constant ) ) { return true; }
		}
		return MSRWA_Stack::ms_seo_plus_active();
	}

	/** The document title, when the article wrote a better one than the post title. */
	public static function title_parts( $parts ) {
		$title = self::seo_title( self::subject() );
		if ( '' !== $title ) { $parts['title'] = $title; }
		return $parts;
	}

	public static function print_head() {
		$tags = self::tags( self::subject() );
		foreach ( $tags as $tag ) {
			$attribute = 0 === strpos( $tag['name'], 'og:' ) ? 'property' : 'name';
			printf( "<meta %s=\"%s\" content=\"%s\">\n", esc_attr( $attribute ), esc_attr( $tag['name'] ), esc_attr( $tag['content'] ) );
		}
	}

	/** The published post this plugin wrote, or 0 when the page is not one. */
	private static function subject() {
		if ( ! is_singular( 'post' ) || self::another_plugin_prints_it() ) { return 0; }
		if ( empty( MSRWA_Settings::get()['seo_meta'] ) ) { return 0; }
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) { return 0; }
		// Only what this plugin produced. A site's own posts are its own business.
		return get_post_meta( $post_id, '_msrwa_run_id', true ) ? $post_id : 0;
	}

	private static function seo_title( $post_id ) {
		return $post_id ? trim( wp_strip_all_tags( (string) get_post_meta( $post_id, '_msrwa_seo_title', true ) ) ) : '';
	}

	/**
	 * Every tag for one post, as name/content pairs.
	 *
	 * Pure enough to test: it reads post meta and nothing else, and returns an
	 * empty list rather than half a set when there is nothing worth saying.
	 */
	public static function tags( $post_id ) {
		if ( ! $post_id ) { return array(); }

		$title = self::seo_title( $post_id );
		if ( '' === $title ) { $title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) ); }
		$description = trim( wp_strip_all_tags( (string) get_post_meta( $post_id, '_msrwa_seo_description', true ) ) );
		if ( '' === $description ) { $description = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $post_id ) ) ); }

		$tags = array();
		if ( '' !== $description ) { $tags[] = array( 'name' => 'description', 'content' => $description ); }

		// The caption is written for a Facebook post, not for a link preview:
		// it is the words somebody publishes alongside the link, so it belongs
		// nowhere in og:description, which describes the page itself.
		$tags[] = array( 'name' => 'og:type', 'content' => 'article' );
		$tags[] = array( 'name' => 'og:title', 'content' => $title );
		if ( '' !== $description ) { $tags[] = array( 'name' => 'og:description', 'content' => $description ); }
		$tags[] = array( 'name' => 'og:url', 'content' => (string) get_permalink( $post_id ) );
		$tags[] = array( 'name' => 'og:locale', 'content' => self::locale( $post_id ) );
		$site = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' !== $site ) { $tags[] = array( 'name' => 'og:site_name', 'content' => $site ); }

		// The sharing image if the engine drew one — it is already the 1200x630
		// Facebook asks for — and the featured image otherwise.
		$image = (int) get_post_meta( $post_id, MSRWA_Draft::generated_key( 'facebook' ), true );
		if ( ! $image ) { $image = (int) get_post_thumbnail_id( $post_id ); }
		$url = $image ? wp_get_attachment_image_url( $image, 'full' ) : '';
		if ( $url ) {
			$tags[] = array( 'name' => 'og:image', 'content' => $url );
			$size = wp_get_attachment_metadata( $image );
			if ( ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
				$tags[] = array( 'name' => 'og:image:width', 'content' => (string) (int) $size['width'] );
				$tags[] = array( 'name' => 'og:image:height', 'content' => (string) (int) $size['height'] );
			}
			$alt = trim( wp_strip_all_tags( (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) ) );
			if ( '' !== $alt ) { $tags[] = array( 'name' => 'og:image:alt', 'content' => $alt ); }
		}

		$tags[] = array( 'name' => 'twitter:card', 'content' => $url ? 'summary_large_image' : 'summary' );
		$tags[] = array( 'name' => 'twitter:title', 'content' => $title );
		if ( '' !== $description ) { $tags[] = array( 'name' => 'twitter:description', 'content' => $description ); }
		if ( $url ) { $tags[] = array( 'name' => 'twitter:image', 'content' => $url ); }

		return $tags;
	}

	/** The lot's language as a locale, since a French article may sit on an English site. */
	private static function locale( $post_id ) {
		$language = (string) get_post_meta( $post_id, '_msrwa_language', true );
		if ( '' === $language ) { $language = (string) ( MSRWA_Settings::get()['site_language'] ?? 'fr' ); }
		$locales = array( 'fr' => 'fr_FR', 'en' => 'en_US', 'ar' => 'ar_AR', 'es' => 'es_ES' );
		return $locales[ $language ] ?? str_replace( '-', '_', $language );
	}
}
