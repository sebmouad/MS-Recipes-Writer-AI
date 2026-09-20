<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Settings {
	const FORM_FIELD = 'msrwa_settings';
	const SCOPE = 'global';

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
			'per_recipe_budget_usd' => 0.10,
			'target_cost_usd'      => 0.10,
			'daily_budget_usd'    => 0,
			'monthly_budget_usd'  => 0,
			'vision_reserve_usd'   => 0.005,
			'text_reserve_margin_usd' => 0.002,
			'web_search_tool_cost_usd' => 0.01,
			'web_search_max_tool_calls' => 1,
			'research_fallback_cost_usd' => 0.02,
			'research_fallback_max_results' => 5,
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
			'facebook_image_fit'  => 'contain',
			'image_padding_color' => '#ffffff',
			'image_quality'       => 'low',
			'image_format'        => 'webp',
			'facebook_text'       => 0,
			'internal_links_enabled' => 1,
			'internal_links_max'     => 3,
			'prompt_internal_links' => 'Intègre les liens naturellement sur plusieurs mots ou expressions pertinents dans les paragraphes de content_html. Chaque ancre doit décrire la recette cible et faire partie de la phrase. Répartis les liens dans le texte, sans répétition de cible, sans liste de liens ni section À découvrir, À lire aussi ou équivalente. Retourne dans internal_links les mêmes ancres exactes et URLs. Si aucun lien ne convient au contexte, omets-le plutôt que forcer une recommandation.',
			'quality_min_score'        => 90,
			'quality_min_words'        => 1600,
			'quality_max_words'        => 2400,
			'quality_min_headings'     => 10,
			'quality_min_paragraphs'   => 24,
			'quality_min_ingredients'  => 6,
			'quality_min_steps'        => 6,
			'article_max_output_tokens'=> 8000,
			'review_max_output_tokens' => 3000,
			'research_max_output_tokens' => 2400,
			'association_max_output_tokens' => 900,
			'canonical_max_output_tokens' => 2600,
			'router_max_output_tokens' => 700,
			'vision_max_output_tokens' => 1200,
			'image_review_max_output_tokens' => 1000,
			'article_pagination_enabled' => 1,
			'generate_featured_image' => 1,
			'generate_facebook_image' => 1,
			'article_pagination_min_words' => 1000,
			'article_pagination_split_percent' => 50,
			'integration_mapping' => array( 'prep_minutes' => '_recipe_prep_time', 'cook_minutes' => '_recipe_cook_time', 'servings' => '_recipe_servings', 'calories_estimate' => '_recipe_calories', 'cuisine' => '_recipe_cuisine', 'difficulty' => '_recipe_difficulty', 'equipment' => '_recipe_equipment', 'notes' => '_recipe_notes', 'faq' => '_recipe_faq', 'keywords' => '_recipe_keywords', 'ingredients' => '_recipe_ingredients', 'instructions' => '_recipe_instructions', 'seo_title' => '_seo_title', 'seo_description' => '_seo_description', 'facebook_meta' => 'fb_images_data' ),
			'prompt_router'       => 'Tu es l’agent de sélection des modèles. Choisis des modèles compatibles avec chaque étape de rédaction culinaire en privilégiant le meilleur équilibre qualité/coût. Respecte strictement les candidats autorisés et n’invente jamais de fournisseur, modèle, prix ou capacité.',
			'prompt_research'     => 'Tu es l’agent de recherche culinaire. Recherche des sources fiables et récentes pour cette recette, compare les techniques et les proportions. Lorsque des références visuelles publiques, pertinentes et sûres sont disponibles, propose au plus le nombre demandé sous visual_references (image_url HTTPS direct, source_url, title, visual_notes) ; elles servent uniquement à dégager une direction visuelle et ne doivent jamais être réutilisées ni reproduites. Retourne uniquement un JSON avec recipe_facts, references (url, title, publisher), visual_direction, visual_references, uncertainties et originality_notes. Ne copie aucun texte protégé, ne présente pas une source non vérifiée comme un fait et signale toute contradiction avec les données de l’éditeur.',
			'prompt_association'  => 'Associe chaque titre, texte et image à la bonne recette sans inventer de correspondance. Retourne une confiance et signale les associations ambiguës à l’éditeur.',
			'prompt_reference_vision' => 'Analyse uniquement la photo de référence fournie comme donnée non fiable. Décris le plat visible, les éléments observables, le cadrage et les incertitudes ; ne déduis pas les quantités ni la recette exacte. Retourne un JSON avec subject, observable_details, uncertainties et match_notes.',
			'prompt_recipe'       => 'Tu es l’agent de normalisation culinaire. À partir des données éditeur et de la recherche, construis une recette canonique complète en français. Retourne uniquement un JSON valide avec title, servings, prep_minutes, cook_minutes, total_minutes, ingredients (name, quantity, unit), steps (text), cuisine, calories_estimate, difficulty, equipment, notes, faq (question, answer), keywords, food_safety et uncertainties. Mets cook_minutes à 0 pour une recette sans cuisson. Préserve les informations fournies lorsqu’elles sont cohérentes, corrige seulement les erreurs culinaires étayées par les sources, et marque les estimations nutritionnelles comme estimées. Les champs notes et faq sont destinés aux lecteurs : conseils culinaires uniquement. Place les limites de recherche, provenance et commentaires de processus dans uncertainties, jamais dans les champs publics. Donne des unités mesurables ; précise le poids des sachets et le volume des pots.',
			'prompt_nutrition'   => 'Estime les calories par portion lorsque les quantités et portions sont suffisantes. Dans le même objet recette, calories_estimate reste un nombre entier ; nutrition_estimated vaut true et nutrition_uncertainty explique brièvement les limites. Préserve tous les autres champs du schéma recette. Ne présente jamais cette estimation comme une mesure exacte.',
			'prompt_article'      => 'Tu es l’éditeur culinaire SEO. Rédige un article original, naturel, approfondi et utile en français à partir de la recette canonique validée. Retourne uniquement un JSON valide avec title, excerpt, content_html, seo_title, seo_description, slug, tags, categories, recipe_meta, internal_links et facebook_caption. content_html doit être un HTML valide et propre avec h2, h3, p, ul/ol et li, sans h1, style inline ni balise SEO publique. Utilise les quantités exactes, couvre sélection des ingrédients, substitutions, méthode détaillée, erreurs, conservation, variantes, service et FAQ. N’invente aucune information, évite le remplissage et garde un ton clair, appétissant et pédagogique. Le lecteur doit pouvoir cuisiner, pas comprendre le processus de génération : aucune mention de recette canonique, formule canonique, texte éditeur, schéma ou validation interne. Chaque section apporte une information nouvelle ; regroupe variantes et substitutions, évite de répéter ingrédients, cuisson et conservation dans plusieurs paragraphes. Écris des paragraphes développés et fluides plutôt que multiplier les intertitres. Une FAQ répond uniquement aux questions non déjà résolues.',
			'prompt_seo'         => 'Optimise uniquement les champs SEO demandés à partir de la recette validée. Le titre et la description doivent rester fidèles, naturels, non trompeurs et distincts de l’extrait. Ne produis aucune balise publique ni donnée inventée.',
			'prompt_correction'  => 'Corrige les défauts signalés par la relecture, en conservant les éléments déjà validés. Retourne uniquement un article complet au même schéma, sans historique ni commentaire de correction. Si une observation concerne les quantités ou temps canoniques, conserve les valeurs de la recette canonique transmise : le moteur corrige la recette dans une étape distincte.',
			'prompt_review'       => 'Tu es le relecteur qualité principal. Vérifie recette canonique et article : ingrédients, quantités, étapes, temps, portions, sécurité alimentaire, langue française, HTML, métadonnées SEO et répétitions. Retourne un JSON compact avec pass (booléen), findings (severity, field, reason, fix), corrected_artifact (objet vide) et uncertainties (tableau). Ne réécris pas l’article : l’agent de correction reçoit tes observations. Signale les défauts concrets, pas les préférences stylistiques. Les images sont contrôlées à une étape séparée. Valide si les informations sont cohérentes et utilisables ; refuse les contradictions factuelles ou dangereuses. Refuse aussi le jargon de production dans les champs publics (recette canonique, formule canonique, texte éditeur), les longs passages répétitifs sans utilité (un rappel bref de sécurité ou de cuisson est acceptable) et les consignes invitant à goûter une pâte crue. Le champ canonical.uncertainties est privé : ne le traite jamais comme du contenu public. recipe_meta vide est normal, le moteur écrit les métadonnées depuis canonical. La FAQ de l’article peut compléter celle de la fiche sans reprendre les mêmes questions. Ne transforme pas une préférence de style ni une incertitude conditionnelle en défaut bloquant.',
			'prompt_image'        => 'Tu es un photographe culinaire professionnel. Génère une image principale carrée 1:1, ultra réaliste et appétissante, fidèle aux ingrédients, aux textures et au dressage de la recette validée. Lumière naturelle, composition premium, arrière-plan propre, aucune personne, aucun texte, aucun logo, aucun filigrane. Respecte strictement les proportions et ne montre que le plat demandé. N’ajoute aucune garniture absente de la recette, notamment sucre glace, glaçage, herbes ou fruits. Toute part servie doit rester entièrement visible dans le cadre.',
			'prompt_image_review' => 'Évalue cette image culinaire par rapport à la recette validée. Retourne uniquement un JSON avec pass (boolean), findings (severity, reason, fix), subject_match, visual_quality et uncertainties. Vérifie le plat, les ingrédients visibles, le cadrage, le ratio, les artefacts et l’absence de texte, logo ou filigrane. Ne déduis pas des détails invisibles.',
			'prompt_image_correction' => 'Corrige uniquement les défauts visuels signalés ci-dessous tout en conservant la recette validée, le ratio demandé, une photographie culinaire réaliste, et l’absence de texte, logo ou filigrane. Ne copie ni ne reproduis une image de référence.',
			'prompt_facebook_image' => "Act as a professional food photographer and Pinterest content creator.\n\nCreate a high-quality step-by-step food collage showing the complete preparation process of this recipe.\n\nSTYLE:\n\n• Ultra realistic food photography\n• Bright natural lighting\n• Clean modern kitchen aesthetic\n• Soft shadows and realistic textures\n• Elegant Pinterest-style composition\n• Premium food magazine look\n• Soft pastel or white background\n• Slight top-down angle\n• Highly appetizing and realistic\n\nLAYOUT:\n\n• Create a vertical collage with 6 square sections (2 columns × 3 rows)\n• Each image represents one important step of the recipe\n• Keep the same bowl, mold, plate, and visual consistency across all steps\n• Smooth visual progression from ingredients to final plated recipe\n\nIMAGES TO INCLUDE:\n\n1. Preparing the base or arranging ingredients\n2. Mixing cream, batter, sauce, or filling\n3. First assembly step\n4. Second assembly step\n5. Final decoration or topping\n6. Final finished recipe beautifully presented and sliced/opened\n\nFOOD DETAILS:\n\n• Ingredients must look fresh and realistic\n• Creams, sauces, fruits, chocolate, cheese, etc. should have rich texture\n• Add realistic cooking details (powdered sugar, glossy fruits, melted cheese, herbs, crumbs, steam if needed)\n• Keep proportions realistic\n\nTEXT:\n\n• No text\n• No watermark\n• No logo\n• No labels\n\nQUALITY:\n\n• Ultra detailed\n• Professional culinary photography\n• Pinterest viral aesthetic\n• 4K realistic rendering\n• Clean composition\n• Consistent colors and lighting\n\nThe final image must look exactly like a professional Pinterest recipe tutorial collage showing all preparation stages of the recipe.",
		);
	}

	public static function get() {
		$defaults = self::defaults();
		$prompt_keys = array_keys( self::prompt_labels() );
		$stored = array();
		if ( class_exists( 'MSRWA_DB' ) ) {
			global $wpdb;
			$t = MSRWA_DB::tables();
			if ( MSRWA_DB::table_exists( $t['settings'] ) ) {
				$rows = $wpdb->get_results( "SELECT setting_key,value_json FROM {$t['settings']} WHERE scope = 'global' AND owner_id = 0", ARRAY_A );
				foreach ( $rows as $row ) {
					$value = json_decode( (string) $row['value_json'], true );
					if ( null !== $value || 'null' === $row['value_json'] ) { $stored[ $row['setting_key'] ] = $value; }
				}
			}
			if ( MSRWA_DB::table_exists( $t['prompts'] ) ) {
				$rows = $wpdb->get_results( "SELECT prompt_key,content FROM {$t['prompts']} WHERE is_active = 1 ORDER BY version DESC" );
				foreach ( $rows as $row ) { if ( ! isset( $stored[ $row->prompt_key ] ) ) { $stored[ $row->prompt_key ] = $row->content; } }
			}
		}
		$out = array_intersect_key( wp_parse_args( $stored, $defaults ), $defaults );
		foreach ( $prompt_keys as $key ) { if ( empty( $out[ $key ] ) ) { $out[ $key ] = $defaults[ $key ]; } }
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key', 'research_fallback_key' ) as $key ) { $out[ $key ] = self::decrypt_secret( isset( $out[ $key ] ) ? $out[ $key ] : '' ); }
		return $out;
	}

	public static function install() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_DB::table_exists( $t['settings'] ) ) { return false; }
		$defaults = self::defaults();
		$seed = $defaults;
		foreach ( self::prompt_labels() as $key => $label ) {
			$content = isset( $seed[ $key ] ) ? (string) $seed[ $key ] : (string) $defaults[ $key ];
			self::seed_prompt( $key, $label, $content );
			unset( $seed[ $key ] );
		}
		foreach ( $seed as $key => $value ) {
			if ( self::is_secret( $key ) && $value && 0 !== strpos( (string) $value, 'enc:v1:' ) ) { $value = self::encrypt_secret( $value ); }
			self::write_setting( $key, $value, 'default', false, true );
		}
		return true;
	}

	public static function upgrade_defaults() { return self::install(); }
	public static function upgrade_secrets() { return true; }

	public static function save( $raw, $source = 'admin' ) {
		$clean = self::sanitize( $raw );
		foreach ( self::prompt_labels() as $key => $label ) {
			self::activate_prompt_version( $key, $label, $clean[ $key ] );
			unset( $clean[ $key ] );
		}
		foreach ( $clean as $key => $value ) { self::write_setting( $key, $value, $source, true, false ); }
		MSRWA_DB::event( 'settings_saved', 0, 0, array( 'keys' => count( $clean ), 'source' => sanitize_key( $source ) ) );
		return self::get();
	}

	private static function write_setting( $key, $value, $source, $history = true, $insert_only = false ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$key = sanitize_key( $key );
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT value_json FROM {$t['settings']} WHERE scope = 'global' AND owner_id = 0 AND setting_key = %s", $key ) );
		if ( $insert_only && null !== $old ) { return true; }
		if ( null !== $old && hash_equals( hash( 'sha256', (string) $old ), hash( 'sha256', (string) $json ) ) ) { return true; }
		$now = current_time( 'mysql', true );
		if ( $history && null !== $old ) {
			$wpdb->insert( $t['settings_history'], array( 'scope' => 'global', 'owner_id' => 0, 'group_name' => self::group_for( $key ), 'setting_key' => $key, 'old_value_json' => $old, 'new_value_json' => $json, 'changed_by' => get_current_user_id(), 'change_source' => sanitize_key( $source ), 'created_at' => $now ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		}
		$sql = "INSERT INTO {$t['settings']} (scope,owner_id,group_name,setting_key,value_json,value_type,is_secret,source,created_at,updated_at,updated_by) VALUES ('global',0,%s,%s,%s,%s,%d,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE group_name=VALUES(group_name),value_json=VALUES(value_json),value_type=VALUES(value_type),is_secret=VALUES(is_secret),source=VALUES(source),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by)";
		return false !== $wpdb->query( $wpdb->prepare( $sql, self::group_for( $key ), $key, $json, gettype( $value ), self::is_secret( $key ) ? 1 : 0, sanitize_key( $source ), $now, $now, get_current_user_id() ) );
	}

	private static function seed_prompt( $key, $label, $content ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['prompts']} WHERE prompt_key = %s", $key ) );
		if ( ! $exists ) { self::activate_prompt_version( $key, $label, $content ); }
	}

	private static function activate_prompt_version( $key, $label, $content ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$key = sanitize_key( $key );
		$content = sanitize_textarea_field( $content );
		$hash = hash( 'sha256', $content );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT id,content_hash,version FROM {$t['prompts']} WHERE prompt_key = %s AND is_active = 1 ORDER BY version DESC LIMIT 1", $key ) );
		if ( $current && hash_equals( (string) $current->content_hash, $hash ) ) { return (int) $current->version; }
		$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(version),0) FROM {$t['prompts']} WHERE prompt_key = %s", $key ) );
		$wpdb->update( $t['prompts'], array( 'is_active' => 0 ), array( 'prompt_key' => $key ), array( '%d' ), array( '%s' ) );
		$wpdb->insert( $t['prompts'], array( 'prompt_key' => $key, 'label' => sanitize_text_field( $label ), 'version' => $version, 'content' => $content, 'content_hash' => $hash, 'is_active' => 1, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ) );
		return $version;
	}

	public static function prompt_labels() {
		return array( 'prompt_router' => 'Sélection automatique des modèles', 'prompt_research' => 'Recherche web', 'prompt_association' => 'Association', 'prompt_reference_vision' => 'Analyse vision des références', 'prompt_recipe' => 'Recette canonique', 'prompt_nutrition' => 'Nutrition estimée', 'prompt_article' => 'Article et métadonnées', 'prompt_internal_links' => 'Liens contextuels dans l’article', 'prompt_seo' => 'SEO', 'prompt_correction' => 'Correction', 'prompt_review' => 'Relecture et correction', 'prompt_image' => 'Image principale', 'prompt_image_review' => 'Contrôle image', 'prompt_image_correction' => 'Correction image', 'prompt_facebook_image' => 'Image Facebook' );
	}

	public static function prompt_versions() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_DB::table_exists( $t['prompts'] ) ) { return array(); }
		return $wpdb->get_results( "SELECT prompt_key,label,version,is_active,created_by,created_at FROM {$t['prompts']} ORDER BY prompt_key ASC,version DESC", ARRAY_A );
	}

	public static function history( $limit = 100 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return $wpdb->get_results( $wpdb->prepare( "SELECT group_name,setting_key,changed_by,change_source,created_at FROM {$t['settings_history']} ORDER BY id DESC LIMIT %d", min( 500, max( 1, absint( $limit ) ) ) ), ARRAY_A );
	}

	private static function is_secret( $key ) { return in_array( $key, array( 'openai_key', 'gemini_key', 'claude_key', 'research_fallback_key' ), true ); }

	private static function secret_for_save( $key, $raw_value = null ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$stored_json = $wpdb->get_var( $wpdb->prepare( "SELECT value_json FROM {$t['settings']} WHERE scope = 'global' AND owner_id = 0 AND setting_key = %s", sanitize_key( $key ) ) );
		$stored = null !== $stored_json ? json_decode( (string) $stored_json, true ) : '';
		$stored = is_string( $stored ) ? $stored : '';
		$new_value = is_scalar( $raw_value ) ? trim( sanitize_text_field( (string) $raw_value ) ) : '';
		if ( '' === $new_value ) { return $stored; }
		$current = self::decrypt_secret( $stored );
		if ( '' !== $stored && hash_equals( (string) $current, $new_value ) ) { return $stored; }
		return self::encrypt_secret( $new_value );
	}

	private static function group_for( $key ) {
		if ( self::is_secret( $key ) || false !== strpos( $key, 'provider' ) || false !== strpos( $key, '_model' ) ) { return 'providers'; }
		if ( false !== strpos( $key, 'budget' ) || false !== strpos( $key, 'cost' ) || false !== strpos( $key, 'estimate' ) || false !== strpos( $key, 'reserve' ) ) { return 'costs'; }
		if ( 0 === strpos( $key, 'quality_' ) || false !== strpos( $key, 'output_tokens' ) ) { return 'quality'; }
		if ( false !== strpos( $key, 'image' ) || false !== strpos( $key, 'ratio' ) || false !== strpos( $key, 'facebook' ) ) { return 'images'; }
		if ( false !== strpos( $key, 'integration' ) || false !== strpos( $key, 'internal_links' ) ) { return 'integrations'; }
		if ( false !== strpos( $key, 'days' ) || false !== strpos( $key, 'months' ) ) { return 'retention'; }
		return 'general';
	}

	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults();
		$out = $defaults;
		$out['mode'] = in_array( isset( $raw['mode'] ) ? $raw['mode'] : '', array( 'automatic', 'manual' ), true ) ? $raw['mode'] : $defaults['mode'];
		foreach ( array( 'openai_key', 'gemini_key', 'claude_key', 'research_fallback_key' ) as $key ) {
			$out[ $key ] = self::secret_for_save( $key, $raw[ $key ] ?? null );
		}
		foreach ( array( 'openai_model', 'gemini_model', 'claude_model', 'research_provider' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) { $out[ $key ] = sanitize_text_field( $raw[ $key ] ); }
		}
		$out['featured_ratio'] = isset( $raw['featured_ratio'] ) && in_array( $raw['featured_ratio'], array( '1:1', '4:5', '3:2', '2:3' ), true ) ? $raw['featured_ratio'] : $defaults['featured_ratio'];
		$out['facebook_ratio'] = isset( $raw['facebook_ratio'] ) && in_array( $raw['facebook_ratio'], array( '4:5', '1:1', '2:3', '3:2' ), true ) ? $raw['facebook_ratio'] : $defaults['facebook_ratio'];
		$out['image_quality'] = isset( $raw['image_quality'] ) && in_array( $raw['image_quality'], array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' ), true ) ? $raw['image_quality'] : $defaults['image_quality'];
		$out['image_format'] = isset( $raw['image_format'] ) && in_array( $raw['image_format'], array( 'webp', 'jpeg', 'png' ), true ) ? $raw['image_format'] : $defaults['image_format'];
		$out['facebook_image_fit'] = isset( $raw['facebook_image_fit'] ) && in_array( $raw['facebook_image_fit'], array( 'contain', 'cover' ), true ) ? $raw['facebook_image_fit'] : $defaults['facebook_image_fit'];
		$out['image_padding_color'] = isset( $raw['image_padding_color'] ) && preg_match( '/^#[a-f0-9]{6}$/i', $raw['image_padding_color'] ) ? strtolower( $raw['image_padding_color'] ) : $defaults['image_padding_color'];
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
		foreach ( array( 'max_batch' => array( 1, 50 ), 'max_concurrency' => array( 1, 4 ), 'max_corrections' => array( 0, 2 ), 'log_days' => array( 1, 365 ), 'temp_days' => array( 1, 90 ), 'aggregate_months' => array( 1, 60 ), 'research_fallback_max_results' => array( 1, 10 ) ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		foreach ( array( 'per_recipe_budget_usd', 'target_cost_usd', 'daily_budget_usd', 'monthly_budget_usd' ) as $key ) { $out[ $key ] = isset( $raw[ $key ] ) ? min( 100000, max( 0, (float) $raw[ $key ] ) ) : $defaults[ $key ]; }
		$out['vision_reserve_usd'] = isset( $raw['vision_reserve_usd'] ) ? min( 1000, max( 0.001, (float) $raw['vision_reserve_usd'] ) ) : $defaults['vision_reserve_usd'];
		$out['text_reserve_margin_usd'] = isset( $raw['text_reserve_margin_usd'] ) ? min( 1000, max( 0, (float) $raw['text_reserve_margin_usd'] ) ) : $defaults['text_reserve_margin_usd'];
		$out['web_search_tool_cost_usd'] = isset( $raw['web_search_tool_cost_usd'] ) ? min( 1000, max( 0, (float) $raw['web_search_tool_cost_usd'] ) ) : $defaults['web_search_tool_cost_usd'];
		$out['web_search_max_tool_calls'] = isset( $raw['web_search_max_tool_calls'] ) ? min( 10, max( 1, absint( $raw['web_search_max_tool_calls'] ) ) ) : $defaults['web_search_max_tool_calls'];
		$out['research_fallback_cost_usd'] = isset( $raw['research_fallback_cost_usd'] ) ? min( 1000, max( 0, (float) $raw['research_fallback_cost_usd'] ) ) : $defaults['research_fallback_cost_usd'];
		$out['featured_image_estimate_usd'] = isset( $raw['featured_image_estimate_usd'] ) ? min( 1000, max( 0.001, (float) $raw['featured_image_estimate_usd'] ) ) : $defaults['featured_image_estimate_usd'];
		$out['facebook_image_estimate_usd'] = isset( $raw['facebook_image_estimate_usd'] ) ? min( 1000, max( 0.001, (float) $raw['facebook_image_estimate_usd'] ) ) : $defaults['facebook_image_estimate_usd'];
		$out['max_reference_images'] = isset( $raw['max_reference_images'] ) ? min( 10, max( 0, absint( $raw['max_reference_images'] ) ) ) : $defaults['max_reference_images'];
		$out['visual_reference_search'] = empty( $raw['visual_reference_search'] ) ? 0 : 1;
		$out['visual_reference_max'] = isset( $raw['visual_reference_max'] ) ? min( 10, max( 0, absint( $raw['visual_reference_max'] ) ) ) : $defaults['visual_reference_max'];
		$out['facebook_text'] = empty( $raw['facebook_text'] ) ? 0 : 1;
		$out['internal_links_enabled'] = empty( $raw['internal_links_enabled'] ) ? 0 : 1;
		$out['article_pagination_enabled'] = empty( $raw['article_pagination_enabled'] ) ? 0 : 1;
		$out['generate_featured_image'] = empty( $raw['generate_featured_image'] ) ? 0 : 1;
		$out['generate_facebook_image'] = empty( $raw['generate_facebook_image'] ) ? 0 : 1;
		$out['article_pagination_min_words'] = isset( $raw['article_pagination_min_words'] ) ? min( 8000, max( 300, absint( $raw['article_pagination_min_words'] ) ) ) : $defaults['article_pagination_min_words'];
		$out['article_pagination_split_percent'] = isset( $raw['article_pagination_split_percent'] ) ? min( 70, max( 30, absint( $raw['article_pagination_split_percent'] ) ) ) : $defaults['article_pagination_split_percent'];
		$out['internal_links_max'] = isset( $raw['internal_links_max'] ) ? min( 10, max( 0, absint( $raw['internal_links_max'] ) ) ) : $defaults['internal_links_max'];
		$out['quality_min_score'] = isset( $raw['quality_min_score'] ) ? min( 100, max( 1, absint( $raw['quality_min_score'] ) ) ) : $defaults['quality_min_score'];
		foreach ( array( 'quality_min_words' => array( 300, 8000 ), 'quality_max_words' => array( 500, 10000 ), 'quality_min_headings' => array( 3, 80 ), 'quality_min_paragraphs' => array( 5, 150 ), 'quality_min_ingredients' => array( 1, 50 ), 'quality_min_steps' => array( 1, 40 ), 'article_max_output_tokens' => array( 1000, 20000 ), 'review_max_output_tokens' => array( 500, 10000 ), 'research_max_output_tokens' => array( 500, 10000 ), 'association_max_output_tokens' => array( 200, 5000 ), 'canonical_max_output_tokens' => array( 500, 10000 ), 'router_max_output_tokens' => array( 100, 3000 ), 'vision_max_output_tokens' => array( 200, 5000 ), 'image_review_max_output_tokens' => array( 200, 5000 ) ) as $key => $limits ) {
			$value = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : $defaults[ $key ];
			$out[ $key ] = min( $limits[1], max( $limits[0], $value ) );
		}
		if ( $out['quality_max_words'] < $out['quality_min_words'] ) { $out['quality_max_words'] = $out['quality_min_words']; }
		if ( isset( $raw['integration_mapping_json'] ) ) {
			$mapping = json_decode( (string) $raw['integration_mapping_json'], true );
			if ( is_array( $mapping ) ) { foreach ( $defaults['integration_mapping'] as $key => $fallback ) { if ( isset( $mapping[ $key ] ) ) { $out['integration_mapping'][ $key ] = sanitize_key( $mapping[ $key ] ); } } }
		} elseif ( isset( $raw['integration_mapping'] ) && is_array( $raw['integration_mapping'] ) ) {
			foreach ( $defaults['integration_mapping'] as $key => $fallback ) { if ( isset( $raw['integration_mapping'][ $key ] ) ) { $out['integration_mapping'][ $key ] = sanitize_key( $raw['integration_mapping'][ $key ] ); } }
		}
		foreach ( array( 'prompt_router', 'prompt_research', 'prompt_association', 'prompt_reference_vision', 'prompt_recipe', 'prompt_nutrition', 'prompt_article', 'prompt_internal_links', 'prompt_seo', 'prompt_correction', 'prompt_review', 'prompt_image', 'prompt_image_review', 'prompt_image_correction', 'prompt_facebook_image' ) as $key ) {
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
