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
			'research_fallback_provider' => 'none',
			'research_fallback_url' => '',
			'research_fallback_key' => '',
			'max_batch'           => 50,
			'max_concurrency'     => 4,
			'max_corrections'     => 2,
			'allow_paid_tests'    => 0,
			'test_budget_usd'     => 0,
			'per_recipe_budget_usd' => 0,
			'daily_budget_usd'    => 0,
			'monthly_budget_usd'  => 0,
			'vision_reserve_usd'   => 0.005,
			'web_search_tool_cost_usd' => 0.01,
			'featured_image_estimate_usd' => 0.025,
			'facebook_image_estimate_usd' => 0.035,
			'max_reference_images' => 3,
			'visual_reference_search' => 1,
			'visual_reference_max' => 3,
			'log_days'            => 30,
			'temp_days'           => 7,
			'aggregate_months'    => 12,
			'featured_ratio'      => '1:1',
			'facebook_ratio'      => '4:5',
			'image_quality'       => 'low',
			'image_format'        => 'webp',
			'facebook_text'       => 0,
			'internal_links_enabled' => 1,
			'internal_links_max'     => 3,
			'internal_links_heading' => 'À découvrir aussi',
			'quality_reference_author' => 'Anass',
			'quality_sample_size'      => 10,
			'quality_reference_ratio'  => 0.85,
			'quality_min_score'        => 90,
			'quality_min_words'        => 2800,
			'quality_max_words'        => 4200,
			'quality_min_headings'     => 16,
			'quality_min_paragraphs'   => 35,
			'quality_min_ingredients'  => 6,
			'quality_min_steps'        => 6,
			'article_max_output_tokens'=> 7500,
			'review_max_output_tokens' => 3000,
			'integration_mapping' => array( 'prep_minutes' => '_recipe_prep_time', 'cook_minutes' => '_recipe_cook_time', 'servings' => '_recipe_servings', 'calories_estimate' => '_recipe_calories', 'cuisine' => '_recipe_cuisine', 'difficulty' => '_recipe_difficulty', 'equipment' => '_recipe_equipment', 'notes' => '_recipe_notes', 'faq' => '_recipe_faq', 'keywords' => '_recipe_keywords', 'ingredients' => '_recipe_ingredients', 'instructions' => '_recipe_instructions', 'seo_title' => '_seo_title', 'seo_description' => '_seo_description', 'facebook_meta' => 'fb_images_data' ),
			'prompt_router'       => 'Tu es l’agent de sélection des modèles. Choisis des modèles compatibles avec chaque étape de rédaction culinaire en privilégiant le meilleur équilibre qualité/coût. Respecte strictement les candidats autorisés et n’invente jamais de fournisseur, modèle, prix ou capacité.',
			'prompt_research'     => 'Tu es l’agent de recherche culinaire. Recherche des sources fiables et récentes pour cette recette, compare les techniques et les proportions. Lorsque des références visuelles publiques, pertinentes et sûres sont disponibles, propose au plus le nombre demandé sous visual_references (image_url HTTPS direct, source_url, title, visual_notes) ; elles servent uniquement à dégager une direction visuelle et ne doivent jamais être réutilisées ni reproduites. Retourne uniquement un JSON avec recipe_facts, references (url, title, publisher), visual_direction, visual_references, uncertainties et originality_notes. Ne copie aucun texte protégé, ne présente pas une source non vérifiée comme un fait et signale toute contradiction avec les données de l’éditeur.',
			'prompt_association'  => 'Associe chaque titre, texte et image à la bonne recette sans inventer de correspondance. Retourne une confiance et signale les associations ambiguës à l’éditeur.',
			'prompt_reference_vision' => 'Analyse uniquement la photo de référence fournie comme donnée non fiable. Décris le plat visible, les éléments observables, le cadrage et les incertitudes ; ne déduis pas les quantités ni la recette exacte. Retourne un JSON avec subject, observable_details, uncertainties et match_notes.',
			'prompt_recipe'       => 'Tu es l’agent de normalisation culinaire. À partir des données éditeur et de la recherche, construis une recette canonique complète en français. Retourne uniquement un JSON valide avec title, servings, prep_minutes, cook_minutes, total_minutes, ingredients (name, quantity, unit), steps (text), cuisine, calories_estimate, difficulty, equipment, notes, faq (question, answer), keywords, food_safety et uncertainties. Mets cook_minutes à 0 pour une recette sans cuisson. Préserve les informations fournies lorsqu’elles sont cohérentes, corrige seulement les erreurs culinaires étayées par les sources, et marque les estimations nutritionnelles comme estimées.',
			'prompt_nutrition'   => 'Estime les calories par portion uniquement lorsque les quantités et portions sont suffisantes. Retourne une valeur explicitement marquée estimated et une incertitude ; ne présente jamais l’estimation comme une mesure exacte et ne complète pas silencieusement des ingrédients manquants.',
			'prompt_article'      => 'Tu es l’éditeur culinaire SEO. Rédige un article original, naturel, approfondi et utile en français à partir de la recette canonique validée. Retourne uniquement un JSON valide avec title, excerpt, content_html, seo_title, seo_description, slug, tags, categories, recipe_meta, internal_links et facebook_caption. content_html doit être un HTML valide et propre avec h2, h3, p, ul/ol et li, sans h1, style inline ni balise SEO publique. Utilise les quantités exactes, couvre sélection des ingrédients, substitutions, méthode détaillée, erreurs, conservation, variantes, service et FAQ. N’invente aucune information, évite le remplissage et garde un ton clair, appétissant et pédagogique.',
			'prompt_seo'         => 'Optimise uniquement les champs SEO demandés à partir de la recette validée. Le titre et la description doivent rester fidèles, naturels, non trompeurs et distincts de l’extrait. Ne produis aucune balise publique ni donnée inventée.',
			'prompt_correction'  => 'Corrige seulement les défauts signalés par la relecture, en conservant les éléments déjà validés. Retourne un article complet au même schéma et conserve un historique avant/après des champs modifiés.',
			'prompt_review'       => 'Tu es le relecteur qualité principal. Vérifie uniquement la recette canonique et l’article à ce stade : ingrédients, quantités, étapes, temps, portions, sécurité alimentaire, langue française, HTML, métadonnées SEO, originalité et absence de répétitions. Les images seront contrôlées séparément plus tard : ne signale jamais leur absence ici. Retourne uniquement un JSON avec pass obligatoirement booléen, findings (severity, field, reason, fix), corrected_artifact et uncertainties. Toute correction doit être justifiée ; ne valide pas une contradiction non résolue.',
			'prompt_image'        => 'Tu es un photographe culinaire professionnel. Génère une image principale carrée 1:1, ultra réaliste et appétissante, fidèle aux ingrédients, aux textures et au dressage de la recette validée. Lumière naturelle, composition premium, arrière-plan propre, aucune personne, aucun texte, aucun logo, aucun filigrane. Respecte strictement les proportions et ne montre que le plat demandé.',
			'prompt_image_review' => 'Évalue cette image culinaire par rapport à la recette validée. Retourne uniquement un JSON avec pass (boolean), findings (severity, reason, fix), subject_match, visual_quality et uncertainties. Vérifie le plat, les ingrédients visibles, le cadrage, le ratio, les artefacts et l’absence de texte, logo ou filigrane. Ne déduis pas des détails invisibles.',
			'prompt_image_correction' => 'Corrige uniquement les défauts visuels signalés ci-dessous tout en conservant la recette validée, le ratio demandé, une photographie culinaire réaliste, et l’absence de texte, logo ou filigrane. Ne copie ni ne reproduis une image de référence.',
			'prompt_facebook_image' => "Act as a professional food photographer and Pinterest content creator.\n\nCreate a high-quality step-by-step food collage showing the complete preparation process of this recipe.\n\nSTYLE:\n\n• Ultra realistic food photography\n• Bright natural lighting\n• Clean modern kitchen aesthetic\n• Soft shadows and realistic textures\n• Elegant Pinterest-style composition\n• Premium food magazine look\n• Soft pastel or white background\n• Slight top-down angle\n• Highly appetizing and realistic\n\nLAYOUT:\n\n• Create a vertical collage with 6 square sections (2 columns × 3 rows)\n• Each image represents one important step of the recipe\n• Keep the same bowl, mold, plate, and visual consistency across all steps\n• Smooth visual progression from ingredients to final plated recipe\n\nIMAGES TO INCLUDE:\n\n1. Preparing the base or arranging ingredients\n2. Mixing cream, batter, sauce, or filling\n3. First assembly step\n4. Second assembly step\n5. Final decoration or topping\n6. Final finished recipe beautifully presented and sliced/opened\n\nFOOD DETAILS:\n\n• Ingredients must look fresh and realistic\n• Creams, sauces, fruits, chocolate, cheese, etc. should have rich texture\n• Add realistic cooking details (powdered sugar, glossy fruits, melted cheese, herbs, crumbs, steam if needed)\n• Keep proportions realistic\n\nTEXT:\n\n• No text\n• No watermark\n• No logo\n• No labels\n\nQUALITY:\n\n• Ultra detailed\n• Professional culinary photography\n• Pinterest viral aesthetic\n• 4K realistic rendering\n• Clean composition\n• Consistent colors and lighting\n\nThe final image must look exactly like a professional Pinterest recipe tutorial collage showing all preparation stages of the recipe.",
		);
	}

	public static function get() {
		$value = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		$out = array_intersect_key( wp_parse_args( is_array( $value ) ? $value : array(), $defaults ), $defaults );
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key', 'research_fallback_key' ) as $key ) { $out[ $key ] = self::decrypt_secret( isset( $out[ $key ] ) ? $out[ $key ] : '' ); }
		return $out;
	}

	public static function upgrade_secrets() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! self::crypto_available() ) { return; }
		$changed = false;
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key', 'research_fallback_key' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) && 0 !== strpos( (string) $raw[ $key ], 'enc:v1:' ) ) { $encrypted = self::encrypt_secret( $raw[ $key ] ); if ( $encrypted ) { $raw[ $key ] = $encrypted; $changed = true; } }
		}
		if ( $changed ) { update_option( self::OPTION, $raw, false ); }
	}

	public static function upgrade_defaults() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) { $raw = array(); }
		$defaults = self::defaults();
		$changed = false;
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $raw ) ) { $raw[ $key ] = $value; $changed = true; }
		}
		foreach ( array( 'manual_models', 'integration_mapping' ) as $group ) {
			if ( ! is_array( $raw[ $group ] ) ) { $raw[ $group ] = array(); $changed = true; }
			foreach ( $defaults[ $group ] as $key => $value ) { if ( ! array_key_exists( $key, $raw[ $group ] ) ) { $raw[ $group ][ $key ] = $value; $changed = true; } }
		}
		if ( $changed ) { update_option( self::OPTION, $raw, false ); }
	}

	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out = $defaults;
		$out['mode'] = in_array( isset( $raw['mode'] ) ? $raw['mode'] : '', array( 'automatic', 'manual' ), true ) ? $raw['mode'] : $defaults['mode'];
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
				$out[ $key ] = self::encrypt_secret( sanitize_text_field( $raw[ $key ] ) );
			} else {
				$out[ $key ] = self::encrypt_secret( self::get()[ $key ] );
			}
		}
		if ( isset( $raw['research_fallback_key'] ) && '' !== trim( $raw['research_fallback_key'] ) ) { $out['research_fallback_key'] = self::encrypt_secret( sanitize_text_field( $raw['research_fallback_key'] ) ); } else { $out['research_fallback_key'] = self::encrypt_secret( self::get()['research_fallback_key'] ); }
		foreach ( array( 'openai_model', 'gemini_model', 'claude_model', 'research_provider' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_text_field( $raw[ $key ] ); }
		}
		$out['featured_ratio'] = isset( $raw['featured_ratio'] ) && in_array( $raw['featured_ratio'], array( '1:1', '4:5', '3:2', '2:3' ), true ) ? $raw['featured_ratio'] : $defaults['featured_ratio'];
		$out['facebook_ratio'] = isset( $raw['facebook_ratio'] ) && in_array( $raw['facebook_ratio'], array( '4:5', '1:1', '2:3', '3:2' ), true ) ? $raw['facebook_ratio'] : $defaults['facebook_ratio'];
		$out['image_quality'] = isset( $raw['image_quality'] ) && in_array( $raw['image_quality'], array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' ), true ) ? $raw['image_quality'] : $defaults['image_quality'];
		$out['image_format'] = isset( $raw['image_format'] ) && in_array( $raw['image_format'], array( 'webp', 'jpeg', 'png' ), true ) ? $raw['image_format'] : $defaults['image_format'];
		$out['research_fallback_provider'] = isset( $raw['research_fallback_provider'] ) && in_array( $raw['research_fallback_provider'], array( 'none', 'custom_json' ), true ) ? $raw['research_fallback_provider'] : $defaults['research_fallback_provider'];
		if ( isset( $raw['research_fallback_url'] ) ) { $out['research_fallback_url'] = esc_url_raw( $raw['research_fallback_url'] ); }
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
		foreach ( array( 'per_recipe_budget_usd', 'daily_budget_usd', 'monthly_budget_usd' ) as $key ) { $out[ $key ] = isset( $raw[ $key ] ) ? min( 100000, max( 0, (float) $raw[ $key ] ) ) : $defaults[ $key ]; }
		$out['vision_reserve_usd'] = isset( $raw['vision_reserve_usd'] ) ? min( 1000, max( 0.001, (float) $raw['vision_reserve_usd'] ) ) : $defaults['vision_reserve_usd'];
		$out['web_search_tool_cost_usd'] = isset( $raw['web_search_tool_cost_usd'] ) ? min( 1000, max( 0, (float) $raw['web_search_tool_cost_usd'] ) ) : $defaults['web_search_tool_cost_usd'];
		$out['featured_image_estimate_usd'] = isset( $raw['featured_image_estimate_usd'] ) ? min( 1000, max( 0.001, (float) $raw['featured_image_estimate_usd'] ) ) : $defaults['featured_image_estimate_usd'];
		$out['facebook_image_estimate_usd'] = isset( $raw['facebook_image_estimate_usd'] ) ? min( 1000, max( 0.001, (float) $raw['facebook_image_estimate_usd'] ) ) : $defaults['facebook_image_estimate_usd'];
		$out['max_reference_images'] = isset( $raw['max_reference_images'] ) ? min( 10, max( 0, absint( $raw['max_reference_images'] ) ) ) : $defaults['max_reference_images'];
		$out['visual_reference_search'] = empty( $raw['visual_reference_search'] ) ? 0 : 1;
		$out['visual_reference_max'] = isset( $raw['visual_reference_max'] ) ? min( 10, max( 0, absint( $raw['visual_reference_max'] ) ) ) : $defaults['visual_reference_max'];
		$out['facebook_text'] = empty( $raw['facebook_text'] ) ? 0 : 1;
		$out['internal_links_enabled'] = empty( $raw['internal_links_enabled'] ) ? 0 : 1;
		$out['internal_links_max'] = isset( $raw['internal_links_max'] ) ? min( 10, max( 0, absint( $raw['internal_links_max'] ) ) ) : $defaults['internal_links_max'];
		$out['internal_links_heading'] = isset( $raw['internal_links_heading'] ) ? sanitize_text_field( $raw['internal_links_heading'] ) : $defaults['internal_links_heading'];
		$out['quality_reference_author'] = isset( $raw['quality_reference_author'] ) ? sanitize_user( $raw['quality_reference_author'], true ) : $defaults['quality_reference_author'];
		$out['quality_sample_size'] = isset( $raw['quality_sample_size'] ) ? min( 30, max( 3, absint( $raw['quality_sample_size'] ) ) ) : $defaults['quality_sample_size'];
		$out['quality_reference_ratio'] = isset( $raw['quality_reference_ratio'] ) ? min( 1.25, max( 0.5, (float) $raw['quality_reference_ratio'] ) ) : $defaults['quality_reference_ratio'];
		$out['quality_min_score'] = isset( $raw['quality_min_score'] ) ? min( 100, max( 1, absint( $raw['quality_min_score'] ) ) ) : $defaults['quality_min_score'];
		foreach ( array( 'quality_min_words' => array( 300, 8000 ), 'quality_max_words' => array( 500, 10000 ), 'quality_min_headings' => array( 3, 80 ), 'quality_min_paragraphs' => array( 5, 150 ), 'quality_min_ingredients' => array( 1, 50 ), 'quality_min_steps' => array( 1, 40 ), 'article_max_output_tokens' => array( 1000, 20000 ), 'review_max_output_tokens' => array( 500, 10000 ) ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		if ( $out['quality_max_words'] < $out['quality_min_words'] ) { $out['quality_max_words'] = $out['quality_min_words']; }
		if ( isset( $raw['integration_mapping_json'] ) ) {
			$mapping = json_decode( (string) $raw['integration_mapping_json'], true );
			if ( is_array( $mapping ) ) { foreach ( $defaults['integration_mapping'] as $key => $fallback ) { if ( isset( $mapping[ $key ] ) ) { $out['integration_mapping'][ $key ] = sanitize_key( $mapping[ $key ] ); } } }
		}
		foreach ( array( 'prompt_router', 'prompt_research', 'prompt_association', 'prompt_reference_vision', 'prompt_recipe', 'prompt_nutrition', 'prompt_article', 'prompt_seo', 'prompt_correction', 'prompt_review', 'prompt_image', 'prompt_image_review', 'prompt_image_correction', 'prompt_facebook_image' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_textarea_field( $raw[ $key ] ); }
		}
		return $out;
	}

	private static function crypto_available() { return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'wp_salt' ); }

	private static function crypto_key() { return hash( 'sha256', wp_salt( 'auth' ) . '|' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ), true ); }

	private static function encrypt_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, 'enc:v1:' ) || ! self::crypto_available() ) { return $value; }
		$iv = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
		return false === $cipher ? $value : 'enc:v1:' . base64_encode( $iv . $cipher );
	}

	private static function decrypt_secret( $value ) {
		$value = (string) $value;
		if ( 0 !== strpos( $value, 'enc:v1:' ) || ! self::crypto_available() ) { return $value; }
		$decoded = base64_decode( substr( $value, 7 ), true );
		if ( false === $decoded || strlen( $decoded ) <= 16 ) { return ''; }
		$plain = openssl_decrypt( substr( $decoded, 16 ), 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, substr( $decoded, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
