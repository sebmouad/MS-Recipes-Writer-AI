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
			if ( is_array( $image ) && ! empty( $image['path'] ) && class_exists( 'MSRWA_Storage' ) && MSRWA_Storage::is_private_path( $image['path'] ) ) {
				$out[] = array( 'origin' => 'upload', 'path' => $image['path'], 'source_url' => '', 'mime' => sanitize_text_field( $image['mime'] ?? '' ), 'bytes' => absint( $image['bytes'] ?? 0 ), 'width' => absint( $image['width'] ?? 0 ), 'height' => absint( $image['height'] ?? 0 ), 'sha256' => sanitize_text_field( $image['sha256'] ?? '' ), 'original_name' => sanitize_file_name( $image['original_name'] ?? 'reference' ) );
				continue;
			}
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
		if ( empty( $recipe['servings'] ) || absint( $recipe['servings'] ) < 1 ) { $errors['servings'] = 'Nombre de portions requis.'; }
		if ( ! array_key_exists( 'prep_minutes', $recipe ) || ! is_numeric( $recipe['prep_minutes'] ) || (int) $recipe['prep_minutes'] < 0 ) { $errors['prep_minutes'] = 'Temps de préparation numérique requis.'; }
		if ( ! array_key_exists( 'cook_minutes', $recipe ) || ! is_numeric( $recipe['cook_minutes'] ) || (int) $recipe['cook_minutes'] < 0 ) { $errors['cook_minutes'] = 'Temps de cuisson numérique requis, avec 0 pour une recette sans cuisson.'; }
		if ( empty( $recipe['cuisine'] ) ) { $errors['cuisine'] = 'Cuisine requise.'; }
		if ( empty( $recipe['calories_estimate'] ) || ! is_numeric( $recipe['calories_estimate'] ) ) { $errors['calories_estimate'] = 'Calories estimées numériques requises.'; }
		foreach ( isset( $recipe['ingredients'] ) && is_array( $recipe['ingredients'] ) ? $recipe['ingredients'] : array() as $index => $ingredient ) {
			if ( ! is_array( $ingredient ) || empty( $ingredient['name'] ) ) { $errors[ 'ingredient_' . $index ] = 'Chaque ingrédient doit avoir un nom.'; }
		}
		foreach ( isset( $recipe['steps'] ) && is_array( $recipe['steps'] ) ? $recipe['steps'] : array() as $index => $step ) {
			$text = is_array( $step ) ? ( $step['text'] ?? '' ) : $step;
			if ( '' === trim( (string) $text ) ) { $errors[ 'step_' . $index ] = 'Chaque étape doit contenir une instruction.'; }
		}
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
