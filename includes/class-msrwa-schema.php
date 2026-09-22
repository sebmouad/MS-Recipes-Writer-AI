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
		if ( ! is_singular( 'post' ) ) { return; }
		$data = self::for_post( get_queried_object_id() );
		if ( ! $data ) { return; }
		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
	}

	/** Recipe plugins that print their own Recipe markup. */
	public static function another_plugin_prints_it() {
		return defined( 'WPRM_VERSION' ) || defined( 'TASTY_RECIPES_PLUGIN_VERSION' ) || class_exists( 'Mediavine\Create\Plugin' ) || defined( 'WPZOOM_RCB_VERSION' );
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
		foreach ( array( 'url' => 'url', 'image' => 'image', 'published' => 'datePublished', 'language' => 'inLanguage' ) as $key => $property ) {
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
		return $data;
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
