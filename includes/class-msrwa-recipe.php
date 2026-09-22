<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Recipe {
	public static function validate( $recipe ) {
		$errors = array();
		if ( ! is_array( $recipe ) ) { return array( 'recipe' => 'La recette canonique doit être un objet.' ); }
		if ( empty( $recipe['title'] ) ) { $errors['title'] = 'Titre requis.'; }
		if ( ! isset( $recipe['ingredients'] ) || ! is_array( $recipe['ingredients'] ) || ! count( $recipe['ingredients'] ) ) { $errors['ingredients'] = 'Ingrédients requis.'; }
		if ( ! isset( $recipe['steps'] ) || ! is_array( $recipe['steps'] ) || ! count( $recipe['steps'] ) ) { $errors['steps'] = 'Étapes requises.'; }
		if ( empty( $recipe['servings'] ) || absint( $recipe['servings'] ) < 1 ) { $errors['servings'] = 'Nombre de portions requis.'; }
		if ( ! array_key_exists( 'prep_minutes', $recipe ) || ! is_numeric( $recipe['prep_minutes'] ) || (int) $recipe['prep_minutes'] < 0 ) { $errors['prep_minutes'] = 'Temps de préparation numérique requis.'; }
		if ( ! array_key_exists( 'cook_minutes', $recipe ) || ! is_numeric( $recipe['cook_minutes'] ) || (int) $recipe['cook_minutes'] < 0 ) { $errors['cook_minutes'] = 'Temps de cuisson numérique requis, avec 0 pour une recette sans cuisson.'; }
		if ( ! array_key_exists( 'total_minutes', $recipe ) || ! is_numeric( $recipe['total_minutes'] ) || (int) $recipe['total_minutes'] <= 0 ) { $errors['total_minutes'] = 'Temps total numérique requis.'; }
		if ( empty( $recipe['cuisine'] ) ) { $errors['cuisine'] = 'Cuisine requise.'; }
		// Google reads both for the Recipe rich result: the course, and the sentence shown on the results page.
		if ( empty( $recipe['recipe_category'] ) ) { $errors['recipe_category'] = 'Catégorie de plat requise.'; }
		if ( empty( $recipe['description'] ) ) { $errors['description'] = 'Description courte requise.'; }
		if ( empty( $recipe['calories_estimate'] ) || ! is_numeric( $recipe['calories_estimate'] ) ) { $errors['calories_estimate'] = 'Calories estimées numériques requises.'; }
		if ( empty( $recipe['difficulty'] ) ) { $errors['difficulty'] = 'Difficulté requise.'; }
		if ( empty( $recipe['equipment'] ) || ! is_array( $recipe['equipment'] ) ) { $errors['equipment'] = 'Liste d’équipement requise.'; }
		if ( empty( $recipe['notes'] ) ) { $errors['notes'] = 'Notes culinaires requises.'; }
		if ( empty( $recipe['faq'] ) || ! is_array( $recipe['faq'] ) ) { $errors['faq'] = 'FAQ structurée requise.'; }
		// The publisher normalises a comma-separated string, so only emptiness is a fault.
		if ( empty( $recipe['keywords'] ) || ( ! is_array( $recipe['keywords'] ) && '' === trim( (string) $recipe['keywords'] ) ) ) { $errors['keywords'] = 'Mots-clés requis.'; }
		if ( empty( $recipe['food_safety'] ) ) { $errors['food_safety'] = 'Consignes de sécurité alimentaire requises.'; }
		foreach ( isset( $recipe['ingredients'] ) && is_array( $recipe['ingredients'] ) ? $recipe['ingredients'] : array() as $index => $ingredient ) {
			if ( ! is_array( $ingredient ) || empty( $ingredient['name'] ) ) { $errors[ 'ingredient_' . $index ] = 'Chaque ingrédient doit avoir un nom.'; }
		}
		foreach ( isset( $recipe['steps'] ) && is_array( $recipe['steps'] ) ? $recipe['steps'] : array() as $index => $step ) {
			$text = is_array( $step ) ? ( $step['text'] ?? '' ) : $step;
			if ( '' === trim( (string) $text ) ) { $errors[ 'step_' . $index ] = 'Chaque étape doit contenir une instruction.'; }
		}
		return $errors;
	}


}
