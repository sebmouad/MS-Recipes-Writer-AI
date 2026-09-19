<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Settings {
	const OPTION = 'msrwa_settings';

	public static function defaults() {
		return array(
			'mode'                => 'automatic',
			'openai_key'          => '',
			'gemini_key'          => '',
			'claude_key'          => '',
			'openai_model'        => 'gpt-5.6-luna',
			'gemini_model'        => 'gemini-3.1-flash-image',
			'claude_model'        => 'claude-sonnet-5',
			'manual_models'       => array(
				'text'   => 'openai:gpt-5.6-luna',
				'review' => 'claude:claude-sonnet-5',
				'image'  => 'gemini:gemini-3.1-flash-image',
				'search' => 'openai:gpt-5.6-luna',
			),
			'research_provider'   => 'native',
			'max_batch'           => 50,
			'max_concurrency'     => 4,
			'max_corrections'     => 2,
			'allow_paid_tests'    => 0,
			'test_budget_usd'     => 0,
			'log_days'            => 30,
			'temp_days'           => 7,
			'aggregate_months'    => 12,
			'featured_ratio'      => '1:1',
			'facebook_ratio'      => '4:5',
			'facebook_text'       => 0,
			'internal_links_enabled' => 1,
			'internal_links_max'     => 3,
			'prompt_research'     => 'Tu es l’agent de recherche culinaire. Recherche des sources fiables et récentes pour cette recette, compare les techniques et les proportions, puis retourne uniquement un JSON avec recipe_facts, references (url, title, publisher), uncertainties et originality_notes. Ne copie aucun texte protégé, ne présente pas une source non vérifiée comme un fait et signale toute contradiction avec les données de l’éditeur.',
			'prompt_recipe'       => 'Tu es l’agent de normalisation culinaire. À partir des données éditeur et de la recherche, construis une recette canonique en français. Retourne uniquement un JSON valide avec title, servings, prep_minutes, cook_minutes, ingredients (name, quantity, unit), steps, cuisine, calories_estimate et uncertainties. Préserve les informations fournies lorsqu’elles sont cohérentes, corrige seulement les erreurs culinaires étayées par les sources, et marque les estimations nutritionnelles comme estimées.',
			'prompt_article'      => 'Tu es l’éditeur culinaire SEO. Rédige un article original, naturel et utile en français à partir de la recette canonique validée. Retourne uniquement un JSON valide avec title, excerpt, content_html, seo_title, seo_description, slug, tags, categories, recipe_meta, internal_links et facebook_caption. content_html doit être un HTML valide et propre avec h2, h3, p, ul/ol et li, sans styles inline ni balises SEO publiques. Utilise les quantités exactes de la recette, n’invente aucune information et garde un ton clair, appétissant et pédagogique.',
			'prompt_review'       => 'Tu es le relecteur qualité principal. Vérifie la cohérence entre recette canonique et article (ingrédients, quantités, étapes, temps, portions, sécurité alimentaire), la langue française, les métadonnées SEO, l’originalité et la conformité des images demandées. Retourne uniquement un JSON avec pass (boolean), findings (severity, field, reason, fix), corrected_artifact et uncertainties. Toute correction doit être justifiée ; ne valide pas une contradiction non résolue.',
			'prompt_image'        => 'Tu es un photographe culinaire professionnel. Génère une image principale carrée 1:1, ultra réaliste et appétissante, fidèle aux ingrédients, aux textures et au dressage de la recette validée. Lumière naturelle, composition premium, arrière-plan propre, aucune personne, aucun texte, aucun logo, aucun filigrane. Respecte strictement les proportions et ne montre que le plat demandé.',
			'prompt_facebook_image' => "Act as a professional food photographer and Pinterest content creator.\n\nCreate a high-quality step-by-step food collage showing the complete preparation process of this recipe.\n\nSTYLE:\n\n• Ultra realistic food photography\n• Bright natural lighting\n• Clean modern kitchen aesthetic\n• Soft shadows and realistic textures\n• Elegant Pinterest-style composition\n• Premium food magazine look\n• Soft pastel or white background\n• Slight top-down angle\n• Highly appetizing and realistic\n\nLAYOUT:\n\n• Create a vertical collage with 6 square sections (2 columns × 3 rows)\n• Each image represents one important step of the recipe\n• Keep the same bowl, mold, plate, and visual consistency across all steps\n• Smooth visual progression from ingredients to final plated recipe\n\nIMAGES TO INCLUDE:\n\n1. Preparing the base or arranging ingredients\n2. Mixing cream, batter, sauce, or filling\n3. First assembly step\n4. Second assembly step\n5. Final decoration or topping\n6. Final finished recipe beautifully presented and sliced/opened\n\nFOOD DETAILS:\n\n• Ingredients must look fresh and realistic\n• Creams, sauces, fruits, chocolate, cheese, etc. should have rich texture\n• Add realistic cooking details (powdered sugar, glossy fruits, melted cheese, herbs, crumbs, steam if needed)\n• Keep proportions realistic\n\nTEXT:\n\n• No text\n• No watermark\n• No logo\n• No labels\n\nQUALITY:\n\n• Ultra detailed\n• Professional culinary photography\n• Pinterest viral aesthetic\n• 4K realistic rendering\n• Clean composition\n• Consistent colors and lighting\n\nThe final image must look exactly like a professional Pinterest recipe tutorial collage showing all preparation stages of the recipe.",
		);
	}

	public static function get() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out = $defaults;
		$out['mode'] = in_array( isset( $raw['mode'] ) ? $raw['mode'] : '', array( 'automatic', 'manual' ), true ) ? $raw['mode'] : $defaults['mode'];
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $raw[ $key ] );
			} else {
				$out[ $key ] = self::get()[ $key ];
			}
		}
		foreach ( array( 'openai_model', 'gemini_model', 'claude_model', 'research_provider', 'featured_ratio', 'facebook_ratio' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_text_field( $raw[ $key ] ); }
		}
		$catalog = MSRWA_Catalog::models();
		if ( isset( $raw['manual_models'] ) && is_array( $raw['manual_models'] ) ) {
			foreach ( array( 'text', 'review', 'image', 'search' ) as $stage ) {
				$value = isset( $raw['manual_models'][ $stage ] ) ? sanitize_text_field( $raw['manual_models'][ $stage ] ) : $defaults['manual_models'][ $stage ];
				list( $provider, $model ) = array_pad( explode( ':', $value, 2 ), 2, '' );
				if ( isset( $catalog[ $provider ][ $model ] ) && ! empty( $catalog[ $provider ][ $model ]['stable'] ) ) { $out['manual_models'][ $stage ] = $provider . ':' . $model; }
			}
		}
		foreach ( array( 'max_batch' => array( 1, 50 ), 'max_concurrency' => array( 1, 4 ), 'max_corrections' => array( 0, 2 ), 'log_days' => array( 1, 365 ), 'temp_days' => array( 1, 90 ), 'aggregate_months' => array( 1, 60 ) ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		$out['allow_paid_tests'] = empty( $raw['allow_paid_tests'] ) ? 0 : 1;
		$out['test_budget_usd'] = isset( $raw['test_budget_usd'] ) ? min( 1000, max( 0, (float) $raw['test_budget_usd'] ) ) : $defaults['test_budget_usd'];
		$out['facebook_text'] = empty( $raw['facebook_text'] ) ? 0 : 1;
		$out['internal_links_enabled'] = empty( $raw['internal_links_enabled'] ) ? 0 : 1;
		$out['internal_links_max'] = isset( $raw['internal_links_max'] ) ? min( 10, max( 0, absint( $raw['internal_links_max'] ) ) ) : $defaults['internal_links_max'];
		foreach ( array( 'prompt_research', 'prompt_recipe', 'prompt_article', 'prompt_review', 'prompt_image', 'prompt_facebook_image' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_textarea_field( $raw[ $key ] ); }
		}
		return $out;
	}
}
