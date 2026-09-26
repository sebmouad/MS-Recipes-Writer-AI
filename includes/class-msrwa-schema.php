<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The recipe, told to search engines.
 *
 * Every draft carries its canonical recipe in `_msrwa_recipe`. Once the post is
 * published this prints it as schema.org Recipe JSON-LD, so a site needs no
 * recipe-card plugin for the rich result. It says nothing on a post that has no
 * recipe, nothing while the post is a draft, and nothing when a recipe plugin
 * that prints its own Recipe markup is active — two Recipe objects for one dish
 * is a structured-data error, not twice the chance.
 */
final class MSRWA_Schema {

	public static function hooks() { add_action( 'wp_head', array( __CLASS__, 'print_head' ), 30 ); }

	public static function print_head() {
		// A locked post's recipe and questions are part of what the password protects.
		if ( ! is_singular( 'post' ) || post_password_required( get_queried_object_id() ) ) { return; }
		foreach ( array( self::for_post( get_queried_object_id() ), self::faq_for_post( get_queried_object_id() ) ) as $data ) {
			if ( ! $data ) { continue; }
			echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	}

	/**
	 * The article's FAQ as FAQPage JSON-LD. Bing and the AI assistants that
	 * read the web take question-and-answer pairs from it; Google shows it as a
	 * rich result only on a few sites but reads it all the same. The theme prints
	 * no FAQ markup, so this is printed beside its Recipe graph too — but not
	 * where an SEO plugin may print its own, and only for questions the page
	 * really shows.
	 */
	public static function faq_for_post( $post_id ) {
		if ( empty( MSRWA_Settings::get()['recipe_schema'] ) || MSRWA_Head::seo_plugin_active() ) { return null; }
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! get_post_meta( $post_id, '_msrwa_run_id', true ) ) { return null; }
		return self::faq( (array) json_decode( (string) get_post_meta( $post_id, '_recipe_faq', true ), true ), self::page_of( (string) $post->post_content, (int) get_query_var( 'page' ) ) );
	}

	/**
	 * One page of a post split with <!--nextpage-->: the FAQ is on the last
	 * page of a two-page article, and page one must not claim it.
	 */
	public static function page_of( $content, $page ) {
		$pages = preg_split( '/<!--nextpage-->/', (string) $content );
		return (string) ( $pages[ max( 1, (int) $page ) - 1 ] ?? '' );
	}

	/** Pure: the pairs whose question the page shows, as FAQPage, or null. */
	public static function faq( array $pairs, $content ) {
		$shown = html_entity_decode( wp_strip_all_tags( (string) $content ), ENT_QUOTES, 'UTF-8' );
		$entities = array();
		foreach ( $pairs as $pair ) {
			$question = trim( (string) ( $pair['question'] ?? '' ) );
			$answer = trim( (string) ( $pair['answer'] ?? '' ) );
			if ( '' === $question || '' === $answer || false === mb_strpos( $shown, $question ) ) { continue; }
			$entities[] = array( '@type' => 'Question', 'name' => $question, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ) );
		}
		return $entities ? array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities ) : null;
	}

	/** Recipe plugins that print their own Recipe markup. */
	public static function another_plugin_prints_it() {
		// The MS stack builds its Recipe graph from the fields this plugin fills,
		// and MSRWA_Stack::enrich() adds what only this plugin knows to it.
		return MSRWA_Stack::owns_head( 'schema' ) || defined( 'WPRM_VERSION' ) || defined( 'TASTY_RECIPES_PLUGIN_VERSION' ) || class_exists( 'Mediavine\Create\Plugin' ) || defined( 'WPZOOM_RCB_VERSION' );
	}

	/** The JSON-LD for one post, or null when there is nothing to say. */
	public static function for_post( $post_id ) {
		$settings = MSRWA_Settings::get();
		if ( empty( $settings['recipe_schema'] ) || self::another_plugin_prints_it() ) { return null; }
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) { return null; }
		$recipe = json_decode( (string) get_post_meta( $post_id, '_msrwa_recipe', true ), true );
		if ( ! is_array( $recipe ) || empty( $recipe['ingredients'] ) ) { return null; }

		$image = has_post_thumbnail( $post_id ) ? wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'full' ) : '';
		return self::build( $recipe, array(
			'name' => get_the_title( $post ),
			'url' => get_permalink( $post ),
			'image' => $image ? $image : '',
			'author' => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'published' => get_post_time( 'c', true, $post ),
			// The lot's language, which may not be the site's.
			'language' => (string) ( get_post_meta( $post_id, '_msrwa_language', true ) ?: $settings['site_language'] ),
			'modified' => get_post_modified_time( 'c', true, $post ),
			'nutrition' => (array) json_decode( (string) get_post_meta( $post_id, '_msrwa_nutrition', true ), true ),
		) );
	}

	/** Pure: a canonical recipe and a little post context in, schema.org Recipe out. */
	public static function build( array $recipe, array $post ) {
		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'Recipe',
			'name' => (string) ( $post['name'] ?? $recipe['title'] ?? '' ),
		);
		if ( '' === $data['name'] ) { $data['name'] = (string) ( $recipe['title'] ?? '' ); }
		foreach ( array( 'url' => 'url', 'image' => 'image', 'published' => 'datePublished', 'modified' => 'dateModified', 'language' => 'inLanguage' ) as $key => $property ) {
			if ( ! empty( $post[ $key ] ) ) { $data[ $property ] = (string) $post[ $key ]; }
		}
		if ( ! empty( $post['author'] ) ) { $data['author'] = array( '@type' => 'Person', 'name' => (string) $post['author'] ); }
		if ( ! empty( $recipe['description'] ) ) { $data['description'] = (string) $recipe['description']; }

		foreach ( array( 'prep_minutes' => 'prepTime', 'cook_minutes' => 'cookTime', 'total_minutes' => 'totalTime' ) as $key => $property ) {
			// Zero cooking is a real answer for a no-cook dish; zero total is not.
			if ( isset( $recipe[ $key ] ) && is_numeric( $recipe[ $key ] ) && ( (int) $recipe[ $key ] > 0 || 'cook_minutes' === $key ) ) {
				$data[ $property ] = self::duration( (int) $recipe[ $key ] );
			}
		}
		if ( ! empty( $recipe['servings'] ) ) { $data['recipeYield'] = (string) absint( $recipe['servings'] ); }
		if ( ! empty( $recipe['recipe_category'] ) ) { $data['recipeCategory'] = (string) $recipe['recipe_category']; }
		if ( ! empty( $recipe['cuisine'] ) ) { $data['recipeCuisine'] = (string) $recipe['cuisine']; }
		$keywords = is_array( $recipe['keywords'] ?? null ) ? $recipe['keywords'] : array_map( 'trim', explode( ',', (string) ( $recipe['keywords'] ?? '' ) ) );
		$keywords = array_values( array_filter( array_map( 'strval', $keywords ) ) );
		if ( $keywords ) { $data['keywords'] = implode( ', ', $keywords ); }

		$data['recipeIngredient'] = array();
		foreach ( (array) $recipe['ingredients'] as $ingredient ) {
			$line = self::ingredient( $ingredient );
			if ( '' !== $line ) { $data['recipeIngredient'][] = $line; }
		}
		$steps = array();
		foreach ( (array) ( $recipe['steps'] ?? array() ) as $step ) {
			$text = trim( wp_strip_all_tags( is_array( $step ) ? (string) ( $step['text'] ?? '' ) : (string) $step ) );
			if ( '' !== $text ) { $steps[] = array( '@type' => 'HowToStep', 'text' => $text ); }
		}
		if ( $steps ) { $data['recipeInstructions'] = $steps; }
		if ( ! empty( $recipe['calories_estimate'] ) && is_numeric( $recipe['calories_estimate'] ) ) {
			$data['nutrition'] = array( '@type' => 'NutritionInformation', 'calories' => (int) $recipe['calories_estimate'] . ' kcal' );
		}
		$stated = self::nutrition( (array) ( $post['nutrition'] ?? array() ) );
		if ( $stated ) { $data['nutrition'] = array_merge( $data['nutrition'] ?? array( '@type' => 'NutritionInformation' ), $stated ); }
		return $data;
	}

	/** Nutrition a source stated per serving, as schema.org spells it. */
	public static function nutrition( array $stated ) {
		$out = array();
		if ( ! empty( $stated['calories'] ) ) { $out['calories'] = (int) round( (float) $stated['calories'] ) . ' kcal'; }
		foreach ( array( 'protein_g' => 'proteinContent', 'carbohydrates_g' => 'carbohydrateContent', 'fat_g' => 'fatContent' ) as $key => $property ) {
			if ( ! empty( $stated[ $key ] ) ) { $out[ $property ] = rtrim( rtrim( number_format( (float) $stated[ $key ], 1, '.', '' ), '0' ), '.' ) . ' g'; }
		}
		return $out;
	}

	/** ISO 8601, which is what Google reads: PT1H5M rather than "65 minutes". */
	public static function duration( $minutes ) {
		$minutes = max( 0, (int) $minutes );
		$hours = intdiv( $minutes, 60 );
		$rest = $minutes % 60;
		if ( ! $hours ) { return 'PT' . $rest . 'M'; }
		return 'PT' . $hours . 'H' . ( $rest ? $rest . 'M' : '' );
	}

	private static function ingredient( $ingredient ) {
		if ( ! is_array( $ingredient ) ) { return trim( wp_strip_all_tags( (string) $ingredient ) ); }
		$parts = array();
		foreach ( array( 'quantity', 'unit', 'name' ) as $key ) {
			$value = trim( wp_strip_all_tags( (string) ( $ingredient[ $key ] ?? '' ) ) );
			if ( '' !== $value ) { $parts[] = $value; }
		}
		return implode( ' ', $parts );
	}
}
