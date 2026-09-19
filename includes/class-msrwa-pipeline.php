<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Pipeline {
	public static function process_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_Queue::acquire_job( $job_id ) ) { return; }
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $job_id ) );
		if ( ! $job || in_array( $job->status, array( 'completed', 'cancelled', 'needs_review', 'awaiting_admin' ), true ) ) { MSRWA_Queue::release_job( $job_id ); return; }
		$settings = MSRWA_Settings::get();
		if ( empty( $settings['allow_paid_tests'] ) || (float) $settings['test_budget_usd'] <= 0 ) {
			self::set_status( $job, 'awaiting_admin', 'paid_tests_disabled', 'Activez explicitement les tests payants et un budget supérieur à zéro.' );
			return;
		}
		$input = json_decode( (string) $job->input_json, true );
		$artifacts = json_decode( (string) $job->artifacts_json, true );
		$artifacts = is_array( $artifacts ) ? $artifacts : array();
		try {
			switch ( $job->stage ) {
				case 'intake': self::advance( $job, $artifacts, 'association' ); break;
				case 'association': self::association( $job, $input, $artifacts ); break;
				case 'research': self::research( $job, $input, $artifacts ); break;
				case 'canonical_recipe': self::canonical( $job, $input, $artifacts ); break;
				case 'article': self::article( $job, $artifacts ); break;
				case 'review': self::review( $job, $artifacts ); break;
				case 'featured_image': self::featured_image( $job, $artifacts ); break;
				case 'facebook_image': self::facebook_image( $job, $artifacts ); break;
				case 'final_review': self::final_review( $job, $artifacts ); break;
				case 'draft': self::draft( $job, $artifacts ); break;
				default: self::set_status( $job, 'needs_review', 'stage_not_implemented', 'Cette étape attend encore son adaptateur fournisseur.' );
			}
		} catch ( Exception $exception ) {
			if ( preg_match( '/budget|payant|insuffisant/i', $exception->getMessage() ) ) {
				self::set_status( $job, 'paused_budget', 'budget_blocked', $exception->getMessage() );
			} elseif ( self::retryable( $exception->getMessage() ) && (int) $job->attempts < 3 ) {
				$delay = min( 900, 30 * ( 2 ** max( 0, (int) $job->attempts - 1 ) ) + wp_rand( 0, 15 ) );
				$updated = $wpdb->update( $t['jobs'], array( 'status' => 'retry_wait', 'error_code' => 'retry_scheduled', 'error_message' => sanitize_textarea_field( $exception->getMessage() ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
				if ( ! $updated ) { return; }
				MSRWA_DB::event( 'job_retry_scheduled', $job->batch_id, $job->id, array( 'delay' => $delay, 'attempt' => (int) $job->attempts ) );
				MSRWA_Queue::schedule_job( $job->id, $delay );
			} else {
				self::set_status( $job, 'failed', 'pipeline_exception', $exception->getMessage() );
			}
		}
	}

	private static function research( $job, $input, &$artifacts ) {
		$settings = MSRWA_Settings::get();
		$prompt = $settings['prompt_research'] . '\nEntrée éditeur : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = self::text_call( $job, $prompt, 1800, array( array( 'type' => 'web_search' ) ), true, 'research' );
		if ( is_wp_error( $result ) ) {
			$fallback = self::fallback_research( $job, isset( $input['title'] ) ? $input['title'] : '' );
			if ( ! is_wp_error( $fallback ) ) { $result = $fallback; MSRWA_DB::event( 'research_fallback_used', $job->batch_id, $job->id ); }
			else { self::require_result( $result ); }
		}
		try {
			$artifacts['research'] = self::decode_json( $result['text'], 'research' );
		} catch ( Exception $first_error ) {
			$retry_prompt = $settings['prompt_research'] . '\nRéessaie en retournant un JSON compact strict, sans Markdown, sans commentaire et avec les clés recipe_facts, references et uncertainties. Entrée éditeur : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$retry = self::text_call( $job, $retry_prompt, 2400, array( array( 'type' => 'web_search' ) ), true, 'research_retry' );
			self::require_result( $retry );
			$artifacts['research'] = self::decode_json( $retry['text'], 'research' );
			MSRWA_DB::event( 'research_retry', $job->batch_id, $job->id, array( 'reason' => 'invalid_json' ) );
			$result = $retry;
		}
		$artifacts['sources'] = $result['sources'];
		self::advance( $job, $artifacts, 'canonical_recipe' );
	}

	private static function association( $job, $input, &$artifacts ) {
		if ( ! empty( $artifacts['association']['editor_confirmed'] ) ) { self::advance( $job, $artifacts, 'research' ); return; }
		$routing = MSRWA_Router::agent_plan( $job );
		if ( is_wp_error( $routing ) ) {
			if ( preg_match( '/budget|payant|insuffisant/i', $routing->get_error_message() ) ) { throw new Exception( $routing->get_error_message() ); }
			MSRWA_DB::event( 'router_fallback', $job->batch_id, $job->id, array( 'code' => $routing->get_error_code() ) );
		} else {
			global $wpdb;
			$t = MSRWA_DB::tables();
			$wpdb->update( $t['jobs'], array( 'selected_models_json' => wp_json_encode( $routing ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s' ), array( '%d', '%s', '%s' ) );
			$job->selected_models_json = wp_json_encode( $routing );
			MSRWA_DB::event( 'router_selected', $job->batch_id, $job->id, array( 'models' => array_map( function ( $value ) { return is_array( $value ) && isset( $value['provider'], $value['model'] ) ? $value['provider'] . ':' . $value['model'] : ''; }, $routing ), 'reason' => isset( $routing['reason'] ) ? $routing['reason'] : '' ) );
		}
		$references = isset( $input['reference_images'] ) && is_array( $input['reference_images'] ) ? array_values( $input['reference_images'] ) : array();
		$settings = MSRWA_Settings::get();
		$stored_references = array();
		$remote_references = array();
		foreach ( $references as $reference ) {
			if ( is_array( $reference ) && ! empty( $reference['path'] ) && class_exists( 'MSRWA_Storage' ) && MSRWA_Storage::is_private_path( $reference['path'] ) ) { $stored_references[] = $reference; } else { $remote_references[] = $reference; }
		}
		$remaining_limit = max( 0, (int) $settings['max_reference_images'] - count( $stored_references ) );
		$downloaded = class_exists( 'MSRWA_Storage' ) ? MSRWA_Storage::download_references( $job->id, $remote_references, $remaining_limit ) : array( 'valid' => array(), 'errors' => array( array( 'code' => 'storage_unavailable', 'message' => 'Le stockage privé des références est indisponible.' ) ) );
		$reference_images = isset( $downloaded['valid'] ) && is_array( $downloaded['valid'] ) ? $downloaded['valid'] : array();
		$reference_images = array_merge( $stored_references, $reference_images );
		$reference_errors = isset( $downloaded['errors'] ) && is_array( $downloaded['errors'] ) ? $downloaded['errors'] : array();
		$reference_context = array();
		foreach ( $reference_images as $reference ) { $reference_context[] = array( 'source_url' => isset( $reference['source_url'] ) ? $reference['source_url'] : '', 'mime' => isset( $reference['mime'] ) ? $reference['mime'] : '', 'bytes' => isset( $reference['bytes'] ) ? absint( $reference['bytes'] ) : 0, 'width' => isset( $reference['width'] ) ? absint( $reference['width'] ) : 0, 'height' => isset( $reference['height'] ) ? absint( $reference['height'] ) : 0, 'sha256' => isset( $reference['sha256'] ) ? $reference['sha256'] : '' ); }
		$association = array( 'title' => isset( $input['title'] ) ? $input['title'] : '', 'reference_images' => $reference_images, 'reference_errors' => $reference_errors, 'confidence' => empty( $input['title'] ) ? 0 : 1, 'needs_editor' => empty( $input['title'] ), 'notes' => array(), 'visual_analysis' => array() );
		if ( ! empty( $input['title'] ) ) {
			$association_prompt = $settings['prompt_association'] . '\nRetourne uniquement un JSON avec title, confidence (0 à 1), needs_editor (boolean), matched_reference_indexes et notes. Ne prétends pas voir une image si elle n’est pas directement fournie comme entrée vision. TITRE : ' . wp_json_encode( $input['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' TEXTE : ' . wp_json_encode( substr( (string) ( $input['source_text'] ?? '' ), 0, 8000 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' RÉFÉRENCES TÉLÉCHARGÉES ET VALIDÉES : ' . wp_json_encode( $reference_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$result = self::text_call( $job, $association_prompt, 900, array(), false, 'association' );
			if ( is_wp_error( $result ) ) { throw new Exception( $result->get_error_message() ); }
			try {
				$decoded = self::decode_json( $result['text'], 'association' );
				$association['confidence'] = min( 1, max( 0, (float) ( $decoded['confidence'] ?? $association['confidence'] ) ) );
				$association['needs_editor'] = ! empty( $decoded['needs_editor'] ) || $association['confidence'] < 0.65;
				$association['matched_reference_indexes'] = isset( $decoded['matched_reference_indexes'] ) && is_array( $decoded['matched_reference_indexes'] ) ? array_values( array_map( 'absint', $decoded['matched_reference_indexes'] ) ) : array();
				$association['notes'] = array();
				if ( isset( $decoded['notes'] ) ) {
					$notes = is_array( $decoded['notes'] ) ? $decoded['notes'] : array( $decoded['notes'] );
					foreach ( $notes as $note ) { if ( is_scalar( $note ) && '' !== trim( (string) $note ) ) { $association['notes'][] = sanitize_text_field( $note ); } }
				}
			} catch ( Exception $error ) {
				$association['notes'][] = 'Réponse d’association non structurée ; validation déterministe conservée.';
				MSRWA_DB::event( 'association_fallback', $job->batch_id, $job->id, array( 'reason' => 'invalid_json' ) );
			}
			foreach ( $reference_images as $reference ) {
				if ( empty( $reference['path'] ) || empty( $settings['prompt_reference_vision'] ) ) { continue; }
				$vision_prompt = $settings['prompt_reference_vision'] . '\nTITRE FOURNI : ' . wp_json_encode( $input['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '\nURL SOURCE (provenance uniquement) : ' . wp_json_encode( $reference['source_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$vision = self::vision_call( $job, $vision_prompt, $reference['path'], 'reference_vision' );
				if ( is_wp_error( $vision ) ) { $association['reference_errors'][] = array( 'url' => $reference['source_url'], 'code' => $vision->get_error_code(), 'message' => $vision->get_error_message() ); continue; }
				try { $analysis = self::decode_json( $vision['text'], 'reference_vision' ); } catch ( Exception $e ) { $analysis = array( 'summary' => sanitize_textarea_field( substr( (string) $vision['text'], 0, 2000 ) ), 'uncertainties' => array( 'La vision n’a pas retourné le schéma JSON attendu.' ) ); }
				$association['visual_analysis'][] = array( 'source_url' => $reference['source_url'], 'analysis' => $analysis );
			}
		}
		$artifacts['association'] = $association;
		if ( empty( $input['title'] ) || ! empty( $association['needs_editor'] ) ) { self::set_status( $job, 'awaiting_input', 'association_ambiguous', 'L’association de cette entrée doit être confirmée par l’éditeur.' ); return; }
		self::advance( $job, $artifacts, 'research' );
	}

	private static function canonical( $job, $input, &$artifacts ) {
		if ( ! is_array( $input ) || empty( $input['title'] ) ) {
			self::set_status( $job, 'needs_review', 'input_missing', 'Les données de recette fournies sont incomplètes.' );
			return;
		}
		$settings = MSRWA_Settings::get();
		$prompt = $settings['prompt_recipe'] . '\n' . $settings['prompt_nutrition'] . '\nEntrée : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' Recherche : ' . wp_json_encode( isset( $artifacts['research'] ) ? $artifacts['research'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' Analyse visuelle de référence (observations, jamais preuve de quantités) : ' . wp_json_encode( isset( $artifacts['association']['visual_analysis'] ) ? $artifacts['association']['visual_analysis'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = self::text_call( $job, $prompt, 2400, array(), false, 'canonical_recipe' );
		self::require_result( $result );
		$canonical = self::normalize_canonical( self::decode_json( $result['text'], 'canonical_recipe' ) );
		$errors = MSRWA_Recipe::validate( $canonical );
		if ( $errors ) { self::set_status( $job, 'needs_review', 'canonical_invalid', wp_json_encode( $errors ) ); return; }
		$artifacts['canonical'] = $canonical;
		self::advance( $job, $artifacts, 'article' );
	}

	private static function article( $job, &$artifacts ) {
		if ( empty( $artifacts['canonical'] ) || ! is_array( $artifacts['canonical'] ) ) {
			self::set_status( $job, 'needs_review', 'canonical_missing', 'La recette canonique n’a pas été produite.' );
			return;
		}
		$settings = MSRWA_Settings::get();
		$previous_findings = '';
		if ( ! empty( $artifacts['review']['findings'] ) ) {
			$previous_findings = ' ' . $settings['prompt_correction'] . ' Corrige également ces observations de relecture : ' . wp_json_encode( $artifacts['review']['findings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( ! empty( $artifacts['review']['corrected_artifact'] ) && is_array( $artifacts['review']['corrected_artifact'] ) ) {
			$previous_findings .= ' Version corrigée proposée à préserver si elle est cohérente : ' . wp_json_encode( $artifacts['review']['corrected_artifact'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$links = self::internal_link_candidates( isset( $artifacts['canonical']['title'] ) ? $artifacts['canonical']['title'] : $job->title, (int) $settings['internal_links_max'] );
		$artifacts['internal_link_candidates'] = $links;
		$links_context = ! empty( $settings['internal_links_enabled'] ) ? '\nLiens internes autorisés : ' . wp_json_encode( $links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '. Utilise uniquement ces chemins relatifs, au maximum ' . (int) $settings['internal_links_max'] . ', et retourne aussi internal_links (title, url, anchor). Ne crée aucun lien si la liste est vide.' : '\nLes liens internes sont désactivés : retourne internal_links comme tableau vide.';
		$prompt = $settings['prompt_article'] . '\n' . $settings['prompt_seo'] . '\nRecette canonique : ' . wp_json_encode( $artifacts['canonical'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . $links_context . $previous_findings;
		$result = self::text_call( $job, $prompt, 2800, array(), false, 'article' );
		self::require_result( $result );
		try {
			$artifacts['article'] = self::decode_json( $result['text'], 'article' );
		} catch ( Exception $first_error ) {
			$retry = self::text_call( $job, $prompt . '\nRéponds à nouveau avec un JSON compact strict sans Markdown ni commentaire.', 3000, array(), false, 'article_retry' );
			self::require_result( $retry );
			$artifacts['article'] = self::decode_json( $retry['text'], 'article' );
		}
		self::advance( $job, $artifacts, 'review' );
	}

	private static function review( $job, &$artifacts ) {
		if ( empty( $artifacts['canonical'] ) || ! is_array( $artifacts['canonical'] ) || empty( $artifacts['article'] ) || ! is_array( $artifacts['article'] ) ) {
			self::set_status( $job, 'needs_review', 'article_missing', 'La recette canonique ou l’article à relire est absent.' );
			return;
		}
		$settings = MSRWA_Settings::get();
		$prompt = $settings['prompt_review'] . '\nDeux cycles maximum sont gérés par le moteur. CANONICAL: ' . wp_json_encode( $artifacts['canonical'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' ARTICLE: ' . wp_json_encode( $artifacts['article'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = self::text_call( $job, $prompt, 2400, array(), false, 'review' );
		self::require_result( $result );
		try {
			$review = self::decode_json( $result['text'], 'review' );
		} catch ( Exception $first_error ) {
			$retry = self::text_call( $job, $prompt . '\nRéponds à nouveau avec un JSON compact strict sans Markdown ni commentaire.', 2800, array(), false, 'review_retry' );
			self::require_result( $retry );
			$review = self::decode_json( $retry['text'], 'review' );
		}
		$artifacts['review'] = $review;
		if ( empty( $review['pass'] ) && ! empty( $review['corrected_artifact'] ) && is_array( $review['corrected_artifact'] ) ) {
			if ( empty( $artifacts['correction_history'] ) || ! is_array( $artifacts['correction_history'] ) ) { $artifacts['correction_history'] = array(); }
			$artifacts['correction_history'][] = array( 'stage' => 'article', 'cycle' => (int) $job->correction_cycles + 1, 'before' => self::compact_article( $artifacts['article'] ), 'after' => self::compact_article( $review['corrected_artifact'] ), 'findings' => isset( $review['findings'] ) ? $review['findings'] : array(), 'created_at' => current_time( 'mysql', true ) );
		}
		if ( empty( $review['pass'] ) && (int) $job->correction_cycles < (int) $settings['max_corrections'] ) {
			global $wpdb;
			$t = MSRWA_DB::tables();
			$updated = $wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts ), 'correction_cycles' => (int) $job->correction_cycles + 1, 'stage' => 'article', 'status' => 'queued', 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
			if ( ! $updated ) { return; }
			MSRWA_DB::event( 'review_correction_requested', $job->batch_id, $job->id, array( 'cycle' => (int) $job->correction_cycles + 1 ) );
			MSRWA_Queue::schedule_job( $job->id );
			return;
		}
		if ( empty( $review['pass'] ) ) { self::set_status( $job, 'needs_review', 'review_failed', 'La relecture reste négative après la limite de corrections.' ); return; }
		self::advance( $job, $artifacts, 'featured_image' );
	}

	private static function internal_link_candidates( $title, $limit ) {
		$limit = min( 10, max( 0, absint( $limit ) ) );
		if ( 0 === $limit || ! function_exists( 'get_posts' ) ) { return array(); }
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 's' => sanitize_text_field( $title ), 'posts_per_page' => $limit, 'no_found_rows' => true ) );
		if ( count( $posts ) < $limit ) {
			$fallback = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
			$seen = array();
			foreach ( $posts as $post ) { $seen[ (int) $post->ID ] = true; }
			foreach ( $fallback as $post ) { if ( empty( $seen[ (int) $post->ID ] ) ) { $posts[] = $post; } if ( count( $posts ) >= $limit ) { break; } }
		}
		$out = array();
		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( ! $url ) { continue; }
			$relative_url = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $url ) : ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
			$out[] = array( 'title' => get_the_title( $post ), 'url' => esc_url_raw( $relative_url ) );
			if ( count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	private static function normalize_canonical( $recipe ) {
		$recipe = is_array( $recipe ) ? $recipe : array();
		if ( empty( $recipe['title'] ) ) {
			foreach ( array( 'titre', 'name', 'nom', 'recipe_title' ) as $key ) {
				if ( ! empty( $recipe[ $key ] ) ) { $recipe['title'] = sanitize_text_field( $recipe[ $key ] ); break; }
			}
		}
		if ( empty( $recipe['steps'] ) ) {
			foreach ( array( 'etapes', 'étapes', 'preparation', 'préparation', 'instructions' ) as $key ) {
				if ( ! empty( $recipe[ $key ] ) && is_array( $recipe[ $key ] ) ) { $recipe['steps'] = $recipe[ $key ]; break; }
			}
		}
		if ( empty( $recipe['prep_minutes'] ) && ! empty( $recipe['prep_time'] ) ) { $recipe['prep_minutes'] = self::minutes( $recipe['prep_time'] ); }
		if ( empty( $recipe['cook_minutes'] ) && ! empty( $recipe['cook_time'] ) ) { $recipe['cook_minutes'] = self::minutes( $recipe['cook_time'] ); }
		if ( empty( $recipe['servings'] ) && ! empty( $recipe['yield'] ) && preg_match( '/\d+/', (string) $recipe['yield'], $match ) ) { $recipe['servings'] = absint( $match[0] ); }
		if ( ! empty( $recipe['ingredients'] ) && is_array( $recipe['ingredients'] ) ) {
			foreach ( $recipe['ingredients'] as $index => $ingredient ) {
				if ( ! is_array( $ingredient ) ) { $recipe['ingredients'][ $index ] = array( 'name' => sanitize_text_field( $ingredient ), 'quantity' => '', 'unit' => '' ); continue; }
				if ( empty( $ingredient['name'] ) ) {
					foreach ( array( 'ingredient', 'ingrédient', 'nom' ) as $key ) { if ( ! empty( $ingredient[ $key ] ) ) { $recipe['ingredients'][ $index ]['name'] = sanitize_text_field( $ingredient[ $key ] ); break; } }
				}
				if ( empty( $ingredient['quantity'] ) ) {
					foreach ( array( 'quantite', 'quantité', 'amount' ) as $key ) { if ( isset( $ingredient[ $key ] ) ) { $recipe['ingredients'][ $index ]['quantity'] = sanitize_text_field( $ingredient[ $key ] ); break; } }
				}
				if ( empty( $ingredient['unit'] ) ) {
					foreach ( array( 'unite', 'unité', 'unités' ) as $key ) { if ( isset( $ingredient[ $key ] ) ) { $recipe['ingredients'][ $index ]['unit'] = sanitize_text_field( $ingredient[ $key ] ); break; } }
				}
			}
		}
		return $recipe;
	}

	private static function minutes( $value ) {
		$text = strtolower( (string) $value );
		$total = 0;
		if ( preg_match( '/(\d+)\s*(?:h|heure|heures)/u', $text, $hours ) ) { $total += 60 * absint( $hours[1] ); }
		if ( preg_match( '/(\d+)\s*(?:m|min|minute|minutes)/u', $text, $minutes ) ) { $total += absint( $minutes[1] ); }
		return $total ? $total : absint( $value );
	}

	private static function featured_image( $job, &$artifacts ) {
		if ( ! empty( $artifacts['featured_image']['attachment_id'] ) ) {
			$valid = MSRWA_Images::validate( $artifacts['featured_image'] );
			if ( ! is_wp_error( $valid ) ) { self::advance( $job, $artifacts, 'facebook_image' ); return; }
		}
		$result = MSRWA_Images::featured( $job, $artifacts );
		if ( is_wp_error( $result ) ) { self::set_status( $job, 'needs_review', $result->get_error_code(), $result->get_error_message() ); return; }
		$artifacts['featured_image'] = $result;
		MSRWA_DB::event( 'featured_image_created', $job->batch_id, $job->id, array( 'attachment_id' => $result['attachment_id'], 'model' => $result['model'] ) );
		self::advance( $job, $artifacts, 'facebook_image' );
	}

	private static function facebook_image( $job, &$artifacts ) {
		if ( ! empty( $artifacts['facebook_image']['attachment_id'] ) ) {
			$valid = MSRWA_Images::validate( $artifacts['facebook_image'] );
			if ( ! is_wp_error( $valid ) ) { self::advance( $job, $artifacts, 'final_review' ); return; }
		}
		$result = MSRWA_Images::facebook( $job, $artifacts );
		if ( is_wp_error( $result ) ) { self::set_status( $job, 'needs_review', $result->get_error_code(), $result->get_error_message() ); return; }
		$artifacts['facebook_image'] = $result;
		MSRWA_DB::event( 'facebook_image_created', $job->batch_id, $job->id, array( 'attachment_id' => $result['attachment_id'], 'reference_attachment_id' => $result['reference_attachment_id'], 'model' => $result['model'] ) );
		self::advance( $job, $artifacts, 'final_review' );
	}

	private static function final_review( $job, &$artifacts ) {
		foreach ( array( 'featured_image', 'facebook_image' ) as $key ) {
			if ( empty( $artifacts[ $key ] ) ) { self::set_status( $job, 'needs_review', 'image_missing', 'Une image requise est absente avant la finalisation.' ); return; }
			$valid = MSRWA_Images::validate( $artifacts[ $key ] );
			if ( is_wp_error( $valid ) ) { self::set_status( $job, 'needs_review', $valid->get_error_code(), $valid->get_error_message() ); return; }
			$review = MSRWA_Images::review( $job, $artifacts[ $key ], isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array() );
			if ( is_wp_error( $review ) ) { self::set_status( $job, 'needs_review', $review->get_error_code(), $review->get_error_message() ); return; }
			$artifacts['image_reviews'][ $key ] = $review;
			if ( isset( $review['pass'] ) && empty( $review['pass'] ) ) { self::set_status( $job, 'needs_review', 'image_review_failed', 'La relecture image a détecté un défaut à vérifier.' ); return; }
		}
		MSRWA_DB::event( 'final_review_passed', $job->batch_id, $job->id, array( 'checks' => array( 'article', 'featured_image', 'facebook_image' ) ) );
		self::advance( $job, $artifacts, 'draft' );
	}

	private static function draft( $job, $artifacts ) {
		$post_id = MSRWA_Publisher::create_draft( $job, $artifacts );
		if ( is_wp_error( $post_id ) ) { self::set_status( $job, 'needs_review', $post_id->get_error_code(), $post_id->get_error_message() ); return; }
		global $wpdb;
		$t = MSRWA_DB::tables();
		$updated = $wpdb->update( $t['jobs'], array( 'status' => 'completed', 'stage' => 'draft', 'error_code' => null, 'error_message' => null, 'draft_post_id' => absint( $post_id ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		MSRWA_Queue::refresh_batch( $job->batch_id );
		MSRWA_DB::event( 'draft_completed', $job->batch_id, $job->id, array( 'post_id' => absint( $post_id ) ) );
	}

	private static function text_call( $job, $prompt, $max_tokens, $tools = array(), $required_tool = false, $operation = 'text' ) {
		$capability = $tools ? 'web_search' : 'text';
		$stage = $tools ? 'search' : ( preg_match( '/^review(?:_|$)/', $operation ) ? 'review' : 'text' );
		$plan = self::selected_plan( $job, $capability, $stage );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$provider = $plan['provider'];
		$model = $plan['model'];
		$catalog = MSRWA_Catalog::models();
		$estimate = isset( $catalog[ $provider ][ $model ] ) ? ( ( max( 16, absint( $max_tokens ) ) * (float) $catalog[ $provider ][ $model ]['output'] ) / 1000000 ) + ( $tools ? 0.02 : 0.005 ) : 0.05;
		$reservation = MSRWA_DB::reserve( $job, $estimate, $operation );
		if ( is_wp_error( $reservation ) ) { MSRWA_DB::event( 'budget_blocked', $job->batch_id, $job->id, array( 'operation' => $operation, 'reason' => $reservation->get_error_code(), 'estimate' => $estimate ) ); return $reservation; }
		$started = current_time( 'mysql', true );
		$result = MSRWA_Providers::text( $provider, $model, $prompt, $max_tokens, ! empty( $tools ) && $required_tool );
		$row = array(
			'batch_id' => absint( $job->batch_id ),
			'job_id' => absint( $job->id ),
			'provider' => $provider,
			'model' => is_array( $result ) && ! empty( $result['model'] ) ? $result['model'] : $model,
			'operation' => $operation,
			'status' => is_wp_error( $result ) ? 'failed' : 'completed',
			'request_id' => is_array( $result ) && ! empty( $result['id'] ) ? $result['id'] : '',
			'input_tokens' => is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0,
			'output_tokens' => is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0,
			'payload_hash' => hash( 'sha256', (string) $prompt ),
			'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '',
			'started_at' => $started,
			'finished_at' => current_time( 'mysql', true ),
		);
		if ( isset( $catalog[ $provider ][ $row['model'] ] ) && is_array( $result ) ) {
			$row['cost_estimate'] = ( $row['input_tokens'] * (float) $catalog[ $provider ][ $row['model'] ]['input'] + $row['output_tokens'] * (float) $catalog[ $provider ][ $row['model'] ]['output'] ) / 1000000;
		}
		MSRWA_DB::call( $row );
		if ( is_wp_error( $result ) || ! isset( $row['cost_estimate'] ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $row['cost_estimate'] ); }
		return $result;
	}

	private static function vision_call( $job, $prompt, $path, $operation ) {
		$plan = self::selected_plan( $job, 'vision', 'vision' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$settings = MSRWA_Settings::get();
		$catalog = MSRWA_Catalog::models();
		$estimate = isset( $settings['vision_reserve_usd'] ) ? (float) $settings['vision_reserve_usd'] : 0.05;
		$reservation = MSRWA_DB::reserve( $job, $estimate, $operation );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$started = current_time( 'mysql', true );
		$result = MSRWA_Providers::vision_text( $plan['provider'], $plan['model'], $prompt, $path, 1200 );
		$input_tokens = is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0;
		$output_tokens = is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0;
		$cost = isset( $catalog[ $plan['provider'] ][ $plan['model'] ] ) && is_array( $result ) ? ( $input_tokens * (float) $catalog[ $plan['provider'] ][ $plan['model'] ]['input'] + $output_tokens * (float) $catalog[ $plan['provider'] ][ $plan['model'] ]['output'] ) / 1000000 : $estimate;
		MSRWA_DB::call( array( 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'provider' => $plan['provider'], 'model' => is_array( $result ) && ! empty( $result['model'] ) ? $result['model'] : $plan['model'], 'operation' => $operation, 'status' => is_wp_error( $result ) ? 'failed' : 'completed', 'request_id' => is_array( $result ) && ! empty( $result['id'] ) ? $result['id'] : '', 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'cost_estimate' => $cost, 'uncertain' => is_wp_error( $result ) ? 0 : 1, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'payload_hash' => hash( 'sha256', (string) $prompt ), 'started_at' => $started, 'finished_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $cost ); }
		return $result;
	}

	private static function fallback_research( $job, $query ) {
		$reservation = MSRWA_DB::reserve( $job, 0.02, 'research_fallback' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$result = MSRWA_Providers::research_fallback( $query, 5 );
		MSRWA_DB::call( array( 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'provider' => 'external_search', 'model' => 'custom_json', 'operation' => 'research_fallback', 'status' => is_wp_error( $result ) ? 'failed' : 'completed', 'request_id' => '', 'input_tokens' => 0, 'output_tokens' => 0, 'cost_estimate' => is_wp_error( $result ) ? 0 : 0.02, 'uncertain' => 1, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'payload_hash' => hash( 'sha256', sanitize_text_field( $query ) ), 'started_at' => current_time( 'mysql', true ), 'finished_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, 0.02 ); }
		return $result;
	}

	private static function selected_plan( $job, $capability, $stage ) {
		$selected = json_decode( (string) $job->selected_models_json, true );
		$key = $stage;
		if ( 'review' === $stage && 'text' === $capability ) { $key = 'review'; }
		if ( is_array( $selected ) && ! empty( $selected[ $key ] ) && is_array( $selected[ $key ] ) ) {
			$provider = sanitize_key( isset( $selected[ $key ]['provider'] ) ? $selected[ $key ]['provider'] : '' );
			$model = sanitize_text_field( isset( $selected[ $key ]['model'] ) ? $selected[ $key ]['model'] : '' );
			$eligible = MSRWA_Catalog::eligible( $capability );
			if ( $provider && $model && isset( $eligible[ $provider . ':' . $model ] ) && MSRWA_Router::connected( $provider ) ) {
				return $selected[ $key ];
			}
			return new WP_Error( 'selected_model_unavailable', 'Le modèle enregistré pour cette étape n’est plus disponible ou compatible.', array( 'status' => 409 ) );
		}
		return MSRWA_Router::plan( $capability, $stage );
	}

	private static function advance( $job, $artifacts, $stage ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$updated = $wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts ), 'stage' => sanitize_key( $stage ), 'status' => 'queued', 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		MSRWA_DB::event( 'stage_completed', $job->batch_id, $job->id, array( 'stage' => $job->stage, 'next' => $stage ) );
		MSRWA_Queue::schedule_job( $job->id );
	}

	private static function set_status( $job, $status, $code, $message ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$updated = $wpdb->update( $t['jobs'], array( 'status' => sanitize_key( $status ), 'error_code' => sanitize_key( $code ), 'error_message' => sanitize_textarea_field( $message ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		MSRWA_Queue::refresh_batch( $job->batch_id );
		MSRWA_DB::event( 'job_' . $status, $job->batch_id, $job->id, array( 'code' => $code ) );
	}

	private static function require_result( $result ) { if ( is_wp_error( $result ) ) { throw new Exception( $result->get_error_message() ); } }

	private static function retryable( $message ) { return (bool) preg_match( '/\b(?:429|500|502|503|504)\b|timeout|timed out|temporarily|rate limit|réseau|network/i', (string) $message ); }

	private static function decode_json( $text, $label ) {
		$json = json_decode( trim( (string) $text ), true );
		if ( ! is_array( $json ) ) {
			$start = strpos( $text, '{' ); $end = strrpos( $text, '}' );
			if ( false !== $start && false !== $end ) { $json = json_decode( substr( $text, $start, $end - $start + 1 ), true ); }
		}
		if ( ! is_array( $json ) ) { throw new Exception( 'Sortie JSON invalide pour ' . $label . '.' ); }
		return $json;
	}

	private static function compact_article( $article ) {
		$article = is_array( $article ) ? $article : array();
		$keys = array( 'title', 'excerpt', 'content_html', 'seo_title', 'seo_description', 'slug', 'tags', 'categories', 'recipe_meta', 'internal_links', 'facebook_caption' );
		$out = array();
		foreach ( $keys as $key ) { if ( array_key_exists( $key, $article ) ) { $out[ $key ] = $article[ $key ]; } }
		return $out;
	}
}
