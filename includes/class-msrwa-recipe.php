<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Recipe {
	public static function normalize_input( $item ) {
		$item = is_array( $item ) ? $item : array();
		return array(
			'title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
			'source_text' => isset( $item['text'] ) ? sanitize_textarea_field( $item['text'] ) : '',
			'reference_images' => self::images( isset( $item['images'] ) ? $item['images'] : array() ),
			'language' => isset( $item['language'] ) ? sanitize_text_field( $item['language'] ) : 'fr',
		);
	}

	private static function images( $images ) {
		if ( ! is_array( $images ) ) { return array(); }
		$out = array();
		foreach ( array_slice( $images, 0, 10 ) as $image ) {
			$url = is_array( $image ) && isset( $image['url'] ) ? $image['url'] : $image;
			$url = esc_url_raw( $url );
			if ( $url && preg_match( '#^https?://#i', $url ) ) { $out[] = $url; }
		}
		return array_values( array_unique( $out ) );
	}

	public static function validate( $recipe ) {
		$errors = array();
		if ( ! is_array( $recipe ) ) { return array( 'recipe' => 'La recette canonique doit être un objet.' ); }
		if ( empty( $recipe['title'] ) ) { $errors['title'] = 'Titre requis.'; }
		if ( ! isset( $recipe['ingredients'] ) || ! is_array( $recipe['ingredients'] ) || ! count( $recipe['ingredients'] ) ) { $errors['ingredients'] = 'Ingrédients requis.'; }
		if ( ! isset( $recipe['steps'] ) || ! is_array( $recipe['steps'] ) || ! count( $recipe['steps'] ) ) { $errors['steps'] = 'Étapes requises.'; }
		return $errors;
	}

	public static function stages() { return array( 'intake', 'association', 'research', 'canonical_recipe', 'article', 'review', 'featured_image', 'facebook_image', 'final_review', 'draft' ); }

	public static function can_transition( $from, $to ) {
		$stages = self::stages();
		$from_index = array_search( $from, $stages, true );
		$to_index = array_search( $to, $stages, true );
		return false !== $from_index && false !== $to_index && $to_index === $from_index + 1;
	}
}
