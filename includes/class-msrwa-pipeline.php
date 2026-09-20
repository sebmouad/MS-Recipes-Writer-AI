<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Pipeline {
	public static function process_job( $job_id ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		if ( ! MSRWA_Queue::acquire_job( $job_id ) ) { MSRWA_Queue::requeue_unclaimed( $job_id ); return; }
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['jobs']} WHERE id = %d", $job_id ) );
		if ( ! $job || in_array( $job->status, array( 'completed', 'cancelled', 'needs_review', 'awaiting_admin' ), true ) ) { MSRWA_Queue::release_job( $job_id ); return; }
		$input = json_decode( (string) $job->input_json, true );
		$artifacts = json_decode( (string) $job->artifacts_json, true );
		$artifacts = is_array( $artifacts ) ? $artifacts : array();
		if ( ! isset( $artifacts['output_options'] ) ) {
			$settings = MSRWA_Settings::get();
			$artifacts['output_options'] = array_intersect_key( $settings, array_flip( array( 'generate_featured_image', 'generate_facebook_image', 'article_pagination_enabled', 'article_pagination_min_words', 'article_pagination_split_percent' ) ) );
		}
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
				default: self::set_status( $job, 'needs_review', 'stage_not_implemented', 'Cette étape attend encore son adaptateur fournisseur.', $artifacts );
			}
		} catch ( Exception $exception ) {
			if ( false !== strpos( $exception->getMessage(), 'Résultat API incertain' ) ) {
				self::set_status( $job, 'uncertain', 'provider_result_uncertain', $exception->getMessage(), $artifacts );
			} elseif ( preg_match( '/budget|payant|insuffisant/i', $exception->getMessage() ) ) {
				self::set_status( $job, 'paused_budget', 'budget_blocked', $exception->getMessage(), $artifacts );
			} elseif ( self::retryable( $exception->getMessage() ) && (int) $job->retry_attempts < 3 ) {
				$retry_attempt = (int) $job->retry_attempts + 1;
				$delay = min( 900, 30 * ( 2 ** max( 0, $retry_attempt - 1 ) ) + wp_rand( 0, 15 ) );
				$updated = $wpdb->update( $t['jobs'], array( 'status' => 'retry_wait', 'retry_attempts' => $retry_attempt, 'error_code' => 'retry_scheduled', 'error_message' => sanitize_textarea_field( $exception->getMessage() ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
				if ( ! $updated ) { return; }
				MSRWA_DB::event( 'job_retry_scheduled', $job->batch_id, $job->id, array( 'delay' => $delay, 'attempt' => $retry_attempt ) );
				MSRWA_Queue::schedule_job( $job->id, $delay );
			} else {
				self::set_status( $job, 'failed', 'pipeline_exception', $exception->getMessage(), $artifacts );
			}
		}
	}

	private static function research( $job, $input, &$artifacts ) {
		$settings = MSRWA_Settings::get();
		$prompt = $settings['prompt_research'] . '\nEntrée éditeur : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = self::text_call( $job, $prompt, absint( $settings['research_max_output_tokens'] ), array( array( 'type' => 'web_search' ) ), true, 'research' );
		if ( is_wp_error( $result ) ) {
			if ( 'provider_result_uncertain' === $result->get_error_code() ) { self::require_result( $result ); }
			$fallback = self::fallback_research( $job, isset( $input['title'] ) ? $input['title'] : '' );
			if ( ! is_wp_error( $fallback ) ) { $result = $fallback; MSRWA_DB::event( 'research_fallback_used', $job->batch_id, $job->id ); }
			else { self::require_result( $result ); }
		}
		try {
			$artifacts['research'] = self::decode_json( $result['text'], 'research' );
		} catch ( Exception $first_error ) {
			$retry_prompt = $settings['prompt_research'] . '\nRéessaie en retournant un JSON compact strict, sans Markdown, sans commentaire et avec les clés recipe_facts, references et uncertainties. Entrée éditeur : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$retry = self::text_call( $job, $retry_prompt, absint( $settings['research_max_output_tokens'] ), array( array( 'type' => 'web_search' ) ), true, 'research_retry' );
			self::require_result( $retry );
			$artifacts['research'] = self::decode_json( $retry['text'], 'research' );
			MSRWA_DB::event( 'research_retry', $job->batch_id, $job->id, array( 'reason' => 'invalid_json' ) );
			$result = $retry;
		}
		$artifacts['sources'] = $result['sources'];
		$artifacts['visual_research'] = self::visual_research( $job, $artifacts['research'], $settings );
		self::advance( $job, $artifacts, 'canonical_recipe' );
	}

	/**
	 * Converts optional web-search image candidates into private, bounded visual
	 * observations. A candidate is never used as a generated-image input.
	 */
	private static function visual_research( $job, $research, $settings ) {
		$out = array( 'enabled' => ! empty( $settings['visual_reference_search'] ), 'references' => array(), 'errors' => array() );
		$limit = min( 10, max( 0, absint( $settings['visual_reference_max'] ) ) );
		if ( empty( $out['enabled'] ) || ! $limit || ! is_array( $research ) || empty( $research['visual_references'] ) || ! is_array( $research['visual_references'] ) ) {
			if ( ! empty( $out['enabled'] ) && ! $limit ) { $out['reason'] = 'limit_zero'; }
			return $out;
		}
		$candidates = array();
		foreach ( array_slice( $research['visual_references'], 0, $limit ) as $candidate ) {
			$url = is_array( $candidate ) ? ( $candidate['image_url'] ?? $candidate['url'] ?? '' ) : $candidate;
			$url = esc_url_raw( (string) $url );
			if ( $url ) { $candidates[] = array( 'url' => $url ); }
		}
		if ( empty( $candidates ) || ! class_exists( 'MSRWA_Storage' ) ) { $out['reason'] = empty( $candidates ) ? 'no_safe_candidate' : 'storage_unavailable'; return $out; }
		$downloaded = MSRWA_Storage::download_references( $job->id, $candidates, $limit );
		$out['errors'] = isset( $downloaded['errors'] ) && is_array( $downloaded['errors'] ) ? $downloaded['errors'] : array();
		foreach ( isset( $downloaded['valid'] ) && is_array( $downloaded['valid'] ) ? $downloaded['valid'] : array() as $reference ) {
			$prompt = $settings['prompt_reference_vision'] . '\nCette image provient d’une recherche web et ne peut servir que de référence abstraite, jamais d’actif à réutiliser ou à reproduire. Décris seulement les choix visuels génériques. TITRE DE RECETTE : ' . wp_json_encode( $job->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$vision = self::vision_call( $job, $prompt, $reference['path'], 'visual_research_vision' );
			if ( is_wp_error( $vision ) ) { $out['errors'][] = array( 'url' => $reference['source_url'], 'code' => $vision->get_error_code(), 'message' => $vision->get_error_message() ); continue; }
			try { $analysis = self::decode_json( $vision['text'], 'visual_research_vision' ); } catch ( Exception $e ) { $analysis = array( 'summary' => sanitize_textarea_field( substr( (string) $vision['text'], 0, 2000 ) ), 'uncertainties' => array( 'La vision n’a pas retourné le schéma JSON attendu.' ) ); }
			$out['references'][] = array( 'source_url' => esc_url_raw( $reference['source_url'] ), 'analysis' => $analysis, 'sha256' => sanitize_text_field( $reference['sha256'] ?? '' ) );
		}
		MSRWA_DB::event( 'visual_research_completed', $job->batch_id, $job->id, array( 'analyzed' => count( $out['references'] ), 'errors' => count( $out['errors'] ) ) );
		return $out;
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
			MSRWA_DB::snapshot( 'model_plan', $routing, $job->batch_id, $job->id );
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
		if ( ! empty( $input['title'] ) && ! empty( $reference_images ) ) {
			$association_prompt = $settings['prompt_association'] . '\nRetourne uniquement un JSON avec title, confidence (0 à 1), needs_editor (boolean), matched_reference_indexes et notes. Ne prétends pas voir une image si elle n’est pas directement fournie comme entrée vision. TITRE : ' . wp_json_encode( $input['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' TEXTE : ' . wp_json_encode( substr( (string) ( $input['source_text'] ?? '' ), 0, 8000 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' RÉFÉRENCES TÉLÉCHARGÉES ET VALIDÉES : ' . wp_json_encode( $reference_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$result = self::text_call( $job, $association_prompt, absint( $settings['association_max_output_tokens'] ), array(), false, 'association' );
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
		if ( empty( $input['title'] ) || ! empty( $association['needs_editor'] ) ) { self::set_status( $job, 'awaiting_input', 'association_ambiguous', 'L’association de cette entrée doit être confirmée par l’éditeur.', $artifacts ); return; }
		self::advance( $job, $artifacts, 'research' );
	}

	private static function canonical( $job, $input, &$artifacts ) {
		if ( ! is_array( $input ) || empty( $input['title'] ) ) {
			self::set_status( $job, 'needs_review', 'input_missing', 'Les données de recette fournies sont incomplètes.', $artifacts );
			return;
		}
		$settings = MSRWA_Settings::get();
		$visual_observations = array_merge( isset( $artifacts['association']['visual_analysis'] ) && is_array( $artifacts['association']['visual_analysis'] ) ? $artifacts['association']['visual_analysis'] : array(), isset( $artifacts['visual_research']['references'] ) && is_array( $artifacts['visual_research']['references'] ) ? $artifacts['visual_research']['references'] : array() );
		$feedback = ! empty( $artifacts['canonical_feedback'] ) ? '\nCORRECTIONS REQUISES : ' . wp_json_encode( $artifacts['canonical_feedback'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '\nRECETTE PRÉCÉDENTE : ' . wp_json_encode( $artifacts['canonical'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
		$prompt = $settings['prompt_recipe'] . '\n' . $settings['prompt_nutrition'] . '\nEntrée : ' . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' Recherche : ' . wp_json_encode( isset( $artifacts['research'] ) ? $artifacts['research'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' Analyse visuelle de référence (observations, jamais preuve de quantités) : ' . wp_json_encode( $visual_observations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . $feedback;
		$result = self::text_call( $job, $prompt, absint( $settings['canonical_max_output_tokens'] ), array(), false, 'canonical_recipe' );
		self::require_result( $result );
		$canonical = self::normalize_canonical( self::decode_json( $result['text'], 'canonical_recipe' ) );
		$artifacts['canonical_candidate'] = $canonical;
		MSRWA_DB::store_artifact( $job->id, $job->batch_id, 'canonical_candidate', $canonical );
		$errors = MSRWA_Recipe::validate( $canonical );
		if ( $errors ) {
			$repair_prompt = $settings['prompt_recipe'] . '\nCorrige cette recette candidate afin de satisfaire exactement le schéma demandé. Retourne uniquement le JSON complet corrigé. ERREURS DE VALIDATION : ' . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' CANDIDATE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$repair = self::text_call( $job, $repair_prompt, absint( $settings['canonical_max_output_tokens'] ), array(), false, 'canonical_recipe_repair' );
			self::require_result( $repair );
			$canonical = self::normalize_canonical( self::decode_json( $repair['text'], 'canonical_recipe_repair' ) );
			$artifacts['canonical_candidate'] = $canonical;
			MSRWA_DB::store_artifact( $job->id, $job->batch_id, 'canonical_candidate', $canonical );
			$errors = MSRWA_Recipe::validate( $canonical );
		}
		if ( $errors ) { $artifacts['canonical_validation_errors'] = $errors; self::set_status( $job, 'needs_review', 'canonical_invalid', wp_json_encode( $errors ), $artifacts ); return; }
		$artifacts['canonical'] = $canonical;
		unset( $artifacts['canonical_validation_errors'], $artifacts['canonical_feedback'] );
		self::advance( $job, $artifacts, 'article' );
	}

	private static function article( $job, &$artifacts ) {
		if ( empty( $artifacts['canonical'] ) || ! is_array( $artifacts['canonical'] ) ) {
			self::set_status( $job, 'needs_review', 'canonical_missing', 'La recette canonique n’a pas été produite.', $artifacts );
			return;
		}
		$settings = MSRWA_Settings::get();
		$previous_findings = '';
		if ( ! empty( $artifacts['review']['findings'] ) ) {
			$previous_findings = ' ' . $settings['prompt_correction'] . ' Corrige également ces observations de relecture : ' . wp_json_encode( $artifacts['review']['findings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( ! empty( $artifacts['review']['corrected_artifact'] ) && is_array( $artifacts['review']['corrected_artifact'] ) ) {
			$previous_findings .= ' Version corrigée proposée à préserver si elle est cohérente : ' . wp_json_encode( $artifacts['review']['corrected_artifact'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( ! empty( $artifacts['review']['findings'] ) && ! empty( $artifacts['article'] ) && is_array( $artifacts['article'] ) ) {
			$previous_findings .= ' Version précédente à corriger et développer sans perdre les parties déjà valides : ' . wp_json_encode( self::compact_article( $artifacts['article'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$links = self::internal_link_candidates( isset( $artifacts['canonical']['title'] ) ? $artifacts['canonical']['title'] : $job->title, (int) $settings['internal_links_max'] );
		$artifacts['internal_link_candidates'] = $links;
		$links_context = ! empty( $settings['internal_links_enabled'] ) ? '\nLiens internes autorisés : ' . wp_json_encode( $links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '. ' . $settings['prompt_internal_links'] . ' Utilise uniquement ces chemins relatifs, au maximum ' . (int) $settings['internal_links_max'] . ', et retourne aussi internal_links (title, url, anchor). Ne crée aucun lien si la liste est vide.' : '\nLes liens internes sont désactivés : retourne internal_links comme tableau vide.';
		$prompt = $settings['prompt_article'] . '\n' . $settings['prompt_seo'] . MSRWA_Quality::prompt_contract( $settings ) . '\nRecette canonique : ' . wp_json_encode( $artifacts['canonical'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . $links_context . $previous_findings;
		$article_tokens = max( 1000, absint( $settings['article_max_output_tokens'] ) );
		$result = self::text_call( $job, $prompt, $article_tokens, array(), false, 'article' );
		self::require_result( $result );
		try {
			$artifacts['article'] = self::decode_json( $result['text'], 'article' );
		} catch ( Exception $first_error ) {
			$retry = self::text_call( $job, $prompt . '\nRéponds à nouveau avec un JSON compact strict sans Markdown ni commentaire.', $article_tokens, array(), false, 'article_retry' );
			self::require_result( $retry );
			$artifacts['article'] = self::decode_json( $retry['text'], 'article' );
		}
		self::advance( $job, $artifacts, 'review' );
	}

	private static function review( $job, &$artifacts ) {
		if ( empty( $artifacts['canonical'] ) || ! is_array( $artifacts['canonical'] ) || empty( $artifacts['article'] ) || ! is_array( $artifacts['article'] ) ) {
			self::set_status( $job, 'needs_review', 'article_missing', 'La recette canonique ou l’article à relire est absent.', $artifacts );
			return;
		}
		$settings = MSRWA_Settings::get();
		$quality = MSRWA_Quality::evaluate( $artifacts['article'], $artifacts['canonical'], $settings );
		$artifacts['quality_report'] = $quality;
		MSRWA_DB::event( 'quality_gate_evaluated', $job->batch_id, $job->id, array( 'score' => $quality['score'], 'pass' => $quality['pass'], 'words' => $quality['metrics']['words'], 'target_words' => $quality['benchmark']['words'] ) );
		if ( empty( $quality['pass'] ) ) {
			$review = array( 'pass' => false, 'findings' => $quality['findings'], 'corrected_artifact' => array(), 'uncertainties' => array(), 'source' => 'deterministic_quality_gate' );
		} else {
			$public_recipe = $artifacts['canonical'];
			unset( $public_recipe['uncertainties'] );
			$public_article = self::compact_article( $artifacts['article'] );
			unset( $public_article['recipe_meta'] );
			$prompt = $settings['prompt_review'] . '\nLa limite configurée est de ' . absint( $settings['max_corrections'] ) . ' cycles. CONTRÔLE DÉTERMINISTE : ' . wp_json_encode( $quality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' CANONICAL: ' . wp_json_encode( $public_recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' ARTICLE: ' . wp_json_encode( $public_article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$review_tokens = max( 500, absint( $settings['review_max_output_tokens'] ) );
			$result = self::text_call( $job, $prompt, $review_tokens, array(), false, 'review' );
			self::require_result( $result );
			try {
				$review = self::decode_json( $result['text'], 'review' );
			} catch ( Exception $first_error ) {
				$retry = self::text_call( $job, $prompt . '\nRéponds à nouveau avec un JSON compact strict sans Markdown ni commentaire.', $review_tokens, array(), false, 'review_retry' );
				self::require_result( $retry );
				$review = self::decode_json( $retry['text'], 'review' );
			}
			if ( ! array_key_exists( 'pass', $review ) || ! is_bool( $review['pass'] ) ) {
				$review['pass'] = false;
				$review['findings'] = array_merge( isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : array(), array( array( 'severity' => 'blocking', 'field' => 'review_schema', 'reason' => 'Le relecteur n’a pas retourné pass comme booléen.', 'fix' => 'Retourner le schéma JSON demandé.' ) ) );
			}
		}
		$artifacts['review'] = $review;
		if ( empty( $review['pass'] ) && ! empty( $review['corrected_artifact'] ) && is_array( $review['corrected_artifact'] ) ) {
			if ( empty( $artifacts['correction_history'] ) || ! is_array( $artifacts['correction_history'] ) ) { $artifacts['correction_history'] = array(); }
			$artifacts['correction_history'][] = array( 'stage' => 'article', 'cycle' => (int) $job->correction_cycles + 1, 'before' => self::compact_article( $artifacts['article'] ), 'after' => self::compact_article( $review['corrected_artifact'] ), 'findings' => isset( $review['findings'] ) ? $review['findings'] : array(), 'created_at' => current_time( 'mysql', true ) );
		}
		$next_stage = 'article';
		$canonical_fields = array( 'recipe_core', 'ingredients', 'steps', 'recipe_enrichment', 'servings', 'prep_minutes', 'cook_minutes', 'total_minutes', 'calories_estimate', 'equipment', 'notes', 'faq' );
		foreach ( isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : array() as $finding ) {
			$field = is_array( $finding ) ? (string) ( $finding['field'] ?? '' ) : '';
			if ( in_array( $field, $canonical_fields, true ) || preg_match( '/^canonical\.(?!uncertainties\b)/', $field ) ) { $next_stage = 'canonical_recipe'; break; }
		}
		$element_cycles = isset( $artifacts['correction_cycles'] ) && is_array( $artifacts['correction_cycles'] ) ? $artifacts['correction_cycles'] : array();
		$current_cycle = absint( $element_cycles[ $next_stage ] ?? 0 );
		if ( empty( $review['pass'] ) && $current_cycle < (int) $settings['max_corrections'] ) {
			global $wpdb;
			$t = MSRWA_DB::tables();
			$element_cycles[ $next_stage ] = $current_cycle + 1;
			$artifacts['correction_cycles'] = $element_cycles;
			if ( 'canonical_recipe' === $next_stage ) { $artifacts['canonical_feedback'] = $review['findings']; }
			MSRWA_DB::store_artifacts( $job->id, $job->batch_id, $artifacts );
			MSRWA_DB::snapshot( 'correction_decision', array( 'cycle' => $element_cycles[ $next_stage ], 'total_cycles' => (int) $job->correction_cycles + 1, 'stage' => $next_stage, 'review' => $review, 'quality' => $quality ), $job->batch_id, $job->id );
			$updated = $wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts ), 'correction_cycles' => (int) $job->correction_cycles + 1, 'correction_cycles_json' => wp_json_encode( $element_cycles ), 'stage' => $next_stage, 'status' => 'queued', 'retry_attempts' => 0, 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
			if ( ! $updated ) { return; }
			MSRWA_DB::event( 'review_correction_requested', $job->batch_id, $job->id, array( 'cycle' => $element_cycles[ $next_stage ], 'total_cycles' => (int) $job->correction_cycles + 1, 'stage' => $next_stage ) );
			MSRWA_Queue::schedule_job( $job->id );
			return;
		}
		if ( empty( $review['pass'] ) ) {
			$artifacts['requires_editor_review'] = true;
			MSRWA_DB::event( 'editor_review_required', $job->batch_id, $job->id, array( 'stage' => 'review', 'findings' => $review['findings'] ?? array() ) );
		}
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
		if ( empty( $recipe['servings'] ) && ! empty( $recipe['yield'] ) ) { $recipe['servings'] = self::first_number( $recipe['yield'] ); }
		if ( ! array_key_exists( 'cook_minutes', $recipe ) && ! empty( $recipe['no_cook'] ) ) { $recipe['cook_minutes'] = 0; }
		if ( empty( $recipe['calories_estimate'] ) ) {
			foreach ( array( 'calories', 'calories_per_serving', 'estimated_calories' ) as $key ) { if ( isset( $recipe[ $key ] ) && self::first_number( $recipe[ $key ] ) ) { $recipe['calories_estimate'] = self::first_number( $recipe[ $key ] ); break; } }
		}
		if ( empty( $recipe['difficulty'] ) ) { foreach ( array( 'difficulte', 'difficulté', 'level' ) as $key ) { if ( ! empty( $recipe[ $key ] ) ) { $recipe['difficulty'] = sanitize_text_field( $recipe[ $key ] ); break; } } }
		foreach ( array( 'equipment' => array( 'utensils', 'materiel', 'matériel' ), 'notes' => array( 'tips', 'conseils' ), 'faq' => array( 'questions', 'frequently_asked_questions' ), 'keywords' => array( 'mots_cles', 'mots-clés' ), 'food_safety' => array( 'securite_alimentaire', 'sécurité_alimentaire', 'safety' ) ) as $target => $aliases ) {
			if ( empty( $recipe[ $target ] ) ) { foreach ( $aliases as $alias ) { if ( ! empty( $recipe[ $alias ] ) ) { $recipe[ $target ] = $recipe[ $alias ]; break; } } }
		}
		foreach ( array( 'equipment', 'faq', 'keywords' ) as $field ) { if ( ! empty( $recipe[ $field ] ) && ! is_array( $recipe[ $field ] ) ) { $recipe[ $field ] = array( sanitize_text_field( $recipe[ $field ] ) ); } }
		if ( ! isset( $recipe['total_minutes'] ) && isset( $recipe['prep_minutes'], $recipe['cook_minutes'] ) ) { $recipe['total_minutes'] = absint( $recipe['prep_minutes'] ) + absint( $recipe['cook_minutes'] ); }
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
		if ( ! empty( $recipe['steps'] ) && is_array( $recipe['steps'] ) ) {
			foreach ( $recipe['steps'] as $index => $step ) {
				if ( is_string( $step ) ) { $recipe['steps'][ $index ] = array( 'text' => sanitize_textarea_field( $step ) ); }
				elseif ( is_array( $step ) && empty( $step['text'] ) ) { foreach ( array( 'instruction', 'description', 'etape', 'étape' ) as $key ) { if ( ! empty( $step[ $key ] ) ) { $recipe['steps'][ $index ]['text'] = sanitize_textarea_field( $step[ $key ] ); break; } } }
			}
		}
		if ( isset( $recipe['instructions'] ) && $recipe['instructions'] === $recipe['steps'] ) { unset( $recipe['instructions'] ); }
		return $recipe;
	}

	private static function minutes( $value ) {
		if ( is_array( $value ) ) {
			$hours = self::first_number( $value['hours'] ?? $value['heures'] ?? 0 );
			$minutes = self::first_number( $value['minutes'] ?? $value['mins'] ?? 0 );
			if ( $hours || $minutes ) { return 60 * $hours + $minutes; }
			return self::first_number( $value );
		}
		$text = strtolower( (string) $value );
		$total = 0;
		if ( preg_match( '/(\d+)\s*(?:h|heure|heures)/u', $text, $hours ) ) { $total += 60 * absint( $hours[1] ); }
		if ( preg_match( '/(\d+)\s*(?:m|min|minute|minutes)/u', $text, $minutes ) ) { $total += absint( $minutes[1] ); }
		return $total ? $total : absint( $value );
	}

	private static function first_number( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) { $number = self::first_number( $item ); if ( $number ) { return $number; } }
			return 0;
		}
		return is_scalar( $value ) && preg_match( '/\d+(?:[.,]\d+)?/', (string) $value, $match ) ? absint( str_replace( ',', '.', $match[0] ) ) : 0;
	}

	private static function featured_image( $job, &$artifacts ) {
		if ( isset( $artifacts['output_options']['generate_featured_image'] ) && ! $artifacts['output_options']['generate_featured_image'] ) { self::advance( $job, $artifacts, 'facebook_image' ); return; }
		if ( ! empty( $artifacts['featured_image']['attachment_id'] ) ) {
			$valid = MSRWA_Images::validate( $artifacts['featured_image'] );
			if ( ! is_wp_error( $valid ) ) { self::advance( $job, $artifacts, 'facebook_image' ); return; }
		}
		$result = MSRWA_Images::featured( $job, $artifacts );
		if ( is_wp_error( $result ) ) { self::set_status( $job, 'needs_review', $result->get_error_code(), $result->get_error_message(), $artifacts ); return; }
		$artifacts['featured_image'] = $result;
		MSRWA_DB::event( 'featured_image_created', $job->batch_id, $job->id, array( 'attachment_id' => $result['attachment_id'], 'model' => $result['model'] ) );
		self::advance( $job, $artifacts, 'facebook_image' );
	}

	private static function facebook_image( $job, &$artifacts ) {
		if ( isset( $artifacts['output_options']['generate_facebook_image'] ) && ! $artifacts['output_options']['generate_facebook_image'] ) { self::advance( $job, $artifacts, 'final_review' ); return; }
		if ( ! empty( $artifacts['facebook_image']['attachment_id'] ) ) {
			$valid = MSRWA_Images::validate( $artifacts['facebook_image'] );
			if ( ! is_wp_error( $valid ) ) { self::advance( $job, $artifacts, 'final_review' ); return; }
		}
		$result = MSRWA_Images::facebook( $job, $artifacts );
		if ( is_wp_error( $result ) ) { self::set_status( $job, 'needs_review', $result->get_error_code(), $result->get_error_message(), $artifacts ); return; }
		$artifacts['facebook_image'] = $result;
		MSRWA_DB::event( 'facebook_image_created', $job->batch_id, $job->id, array( 'attachment_id' => $result['attachment_id'], 'reference_attachment_id' => $result['reference_attachment_id'], 'model' => $result['model'] ) );
		self::advance( $job, $artifacts, 'final_review' );
	}

	private static function final_review( $job, &$artifacts ) {
		$checks = array( 'article' );
		foreach ( array( 'featured_image', 'facebook_image' ) as $key ) {
			if ( isset( $artifacts['output_options'][ 'generate_' . $key ] ) && ! $artifacts['output_options'][ 'generate_' . $key ] ) { continue; }
			$checks[] = $key;
			if ( empty( $artifacts[ $key ] ) ) { self::set_status( $job, 'needs_review', 'image_missing', 'Une image requise est absente avant la finalisation.', $artifacts ); return; }
			$valid = MSRWA_Images::validate( $artifacts[ $key ] );
			if ( is_wp_error( $valid ) ) { self::set_status( $job, 'needs_review', $valid->get_error_code(), $valid->get_error_message(), $artifacts ); return; }
			$review = MSRWA_Images::review( $job, $artifacts[ $key ], isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array() );
			if ( is_wp_error( $review ) ) { self::set_status( $job, 'needs_review', $review->get_error_code(), $review->get_error_message(), $artifacts ); return; }
			$artifacts['image_reviews'][ $key ] = $review;
			if ( ! isset( $review['pass'] ) || ! is_bool( $review['pass'] ) ) { self::set_status( $job, 'needs_review', 'image_review_invalid', 'Le contrôle image ne contient pas de verdict valide.', $artifacts ); return; }
			if ( false === $review['pass'] ) {
				$element_cycles = isset( $artifacts['correction_cycles'] ) && is_array( $artifacts['correction_cycles'] ) ? $artifacts['correction_cycles'] : array();
				$cycles = absint( $element_cycles[ $key ] ?? 0 );
				$settings = MSRWA_Settings::get();
				$max = isset( $settings['max_corrections'] ) ? max( 0, (int) $settings['max_corrections'] ) : 0;
				if ( $cycles >= $max ) { self::set_status( $job, 'needs_review', 'image_review_failed', 'La relecture image reste négative après la limite de corrections.', $artifacts ); return; }
				if ( empty( $artifacts['image_correction_context'] ) || ! is_array( $artifacts['image_correction_context'] ) ) { $artifacts['image_correction_context'] = array(); }
				$element_cycles[ $key ] = $cycles + 1;
				$artifacts['correction_cycles'] = $element_cycles;
				$artifacts['image_correction_context'][ $key ] = isset( $review['findings'] ) && is_array( $review['findings'] ) ? array_slice( $review['findings'], 0, 8 ) : array( array( 'reason' => 'La relecture a échoué sans détail structuré.', 'fix' => 'Reproduire fidèlement la recette et le ratio demandés.' ) );
				self::discard_image_artifact( $artifacts, $key );
				if ( 'featured_image' === $key ) {
					self::discard_image_artifact( $artifacts, 'facebook_image' );
					unset( $artifacts['image_correction_context']['facebook_image'] );
				}
				MSRWA_DB::event( 'image_correction_requested', $job->batch_id, $job->id, array( 'image' => $key, 'cycle' => $cycles + 1 ) );
				self::advance( $job, $artifacts, $key, 1 );
				return;
			}
		}
		MSRWA_DB::event( 'final_review_passed', $job->batch_id, $job->id, array( 'checks' => $checks ) );
		self::advance( $job, $artifacts, 'draft' );
	}

	private static function discard_image_artifact( &$artifacts, $key ) {
		if ( ! empty( $artifacts[ $key ] ) ) {
			$artifacts['image_history'][ $key ][] = array( 'image' => $artifacts[ $key ], 'review' => $artifacts['image_reviews'][ $key ] ?? array(), 'replaced_at' => current_time( 'mysql', true ) );
		}
		unset( $artifacts[ $key ], $artifacts['image_reviews'][ $key ] );
	}

	private static function draft( $job, $artifacts ) {
		MSRWA_DB::store_artifacts( $job->id, $job->batch_id, $artifacts );
		$requires_review = ! empty( $artifacts['requires_editor_review'] );
		if ( $requires_review ) { self::set_status( $job, 'needs_review', 'editor_review_required', 'Brouillon disponible ; vérifier les observations avant publication.', $artifacts ); return; }
		$post_id = MSRWA_Publisher::create_draft( $job, $artifacts );
		if ( is_wp_error( $post_id ) ) { self::set_status( $job, 'needs_review', $post_id->get_error_code(), $post_id->get_error_message(), $artifacts ); return; }
		global $wpdb;
		$t = MSRWA_DB::tables();
		$updated = $wpdb->update( $t['jobs'], array( 'status' => 'completed', 'stage' => 'draft', 'error_code' => null, 'error_message' => null, 'draft_post_id' => absint( $post_id ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		MSRWA_DB::snapshot( 'draft_result', array( 'post_id' => absint( $post_id ), 'status' => 'draft', 'completed_at' => current_time( 'mysql', true ) ), $job->batch_id, $job->id );
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
		$settings = MSRWA_Settings::get();
		$tool_cost = $tools ? (float) $settings['web_search_tool_cost_usd'] * absint( $settings['web_search_max_tool_calls'] ) : 0;
		// UTF-8 byte count is a conservative input bound for byte-level tokenizers.
		$estimate = isset( $catalog[ $provider ][ $model ] ) ? ( ( strlen( $prompt ) * (float) $catalog[ $provider ][ $model ]['input'] + max( 16, absint( $max_tokens ) ) * (float) $catalog[ $provider ][ $model ]['output'] ) / 1000000 ) + $tool_cost + (float) $settings['text_reserve_margin_usd'] : max( 0.001, (float) $settings['text_reserve_margin_usd'] );
		$reservation = MSRWA_DB::reserve( $job, $estimate, $operation );
		if ( is_wp_error( $reservation ) ) { MSRWA_DB::event( 'budget_blocked', $job->batch_id, $job->id, array( 'operation' => $operation, 'reason' => $reservation->get_error_code(), 'estimate' => $estimate ) ); return $reservation; }
		$started = current_time( 'mysql', true );
		$result = MSRWA_Providers::text( $provider, $model, $prompt, $max_tokens, ! empty( $tools ) && $required_tool, true );
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
			'request_json' => array( 'prompt' => $prompt, 'max_output_tokens' => absint( $max_tokens ), 'tools' => $tools, 'required_tool' => (bool) $required_tool ),
			'response_json' => MSRWA_DB::diagnostic_payload( $result ),
			'payload_hash' => hash( 'sha256', (string) $prompt ),
			'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '',
			'started_at' => $started,
			'finished_at' => current_time( 'mysql', true ),
		);
		if ( isset( $catalog[ $provider ][ $row['model'] ] ) && is_array( $result ) ) {
			if ( $tools && isset( $result['search_calls'] ) ) { $tool_cost = absint( $result['search_calls'] ) * (float) $settings['web_search_tool_cost_usd']; }
			$row['cost_estimate'] = ( $row['input_tokens'] * (float) $catalog[ $provider ][ $row['model'] ]['input'] + $row['output_tokens'] * (float) $catalog[ $provider ][ $row['model'] ]['output'] ) / 1000000 + $tool_cost;
		}
		$uncertain = is_wp_error( $result ) && preg_match( '/timeout|timed out|cURL error 28/i', $result->get_error_message() );
		if ( $uncertain ) { $row['uncertain'] = 1; $row['status'] = 'uncertain'; $row['cost_estimate'] = $estimate; }
		MSRWA_DB::call( $row );
		if ( $uncertain ) {
			MSRWA_DB::settle( $reservation, $estimate );
			return new WP_Error( 'provider_result_uncertain', 'Résultat API incertain après expiration du délai ; vérifier la facturation avant de relancer.' );
		}
		if ( is_wp_error( $result ) || ! isset( $row['cost_estimate'] ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $row['cost_estimate'] ); }
		if ( is_array( $result ) && 'incomplete' === ( $result['response_status'] ?? '' ) ) { return new WP_Error( 'provider_output_incomplete', 'La réponse a atteint sa limite de tokens ; ajustez cette limite avant de relancer.' ); }
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
		$vision_tokens = absint( $settings['vision_max_output_tokens'] );
		$result = MSRWA_Providers::vision_text( $plan['provider'], $plan['model'], $prompt, $path, $vision_tokens );
		$input_tokens = is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0;
		$output_tokens = is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0;
		$cost = isset( $catalog[ $plan['provider'] ][ $plan['model'] ] ) && is_array( $result ) ? ( $input_tokens * (float) $catalog[ $plan['provider'] ][ $plan['model'] ]['input'] + $output_tokens * (float) $catalog[ $plan['provider'] ][ $plan['model'] ]['output'] ) / 1000000 : $estimate;
		MSRWA_DB::call( array( 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'provider' => $plan['provider'], 'model' => is_array( $result ) && ! empty( $result['model'] ) ? $result['model'] : $plan['model'], 'operation' => $operation, 'status' => is_wp_error( $result ) ? 'failed' : 'completed', 'request_id' => is_array( $result ) && ! empty( $result['id'] ) ? $result['id'] : '', 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'cost_estimate' => $cost, 'uncertain' => is_wp_error( $result ) ? 0 : 1, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'request_json' => array( 'prompt' => $prompt, 'max_output_tokens' => $vision_tokens, 'reference_file' => basename( (string) $path ) ), 'response_json' => MSRWA_DB::diagnostic_payload( $result ), 'payload_hash' => hash( 'sha256', (string) $prompt ), 'started_at' => $started, 'finished_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $cost ); }
		return $result;
	}

	private static function fallback_research( $job, $query ) {
		$settings = MSRWA_Settings::get();
		$fallback_cost = (float) $settings['research_fallback_cost_usd'];
		$max_results = absint( $settings['research_fallback_max_results'] );
		$reservation = MSRWA_DB::reserve( $job, $fallback_cost, 'research_fallback' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$result = MSRWA_Providers::research_fallback( $query, $max_results );
		MSRWA_DB::call( array( 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'provider' => 'external_search', 'model' => 'custom_json', 'operation' => 'research_fallback', 'status' => is_wp_error( $result ) ? 'failed' : 'completed', 'request_id' => '', 'input_tokens' => 0, 'output_tokens' => 0, 'cost_estimate' => is_wp_error( $result ) ? 0 : $fallback_cost, 'uncertain' => 1, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'request_json' => array( 'query' => sanitize_text_field( $query ), 'max_results' => $max_results ), 'response_json' => MSRWA_DB::diagnostic_payload( $result ), 'payload_hash' => hash( 'sha256', sanitize_text_field( $query ) ), 'started_at' => current_time( 'mysql', true ), 'finished_at' => current_time( 'mysql', true ) ) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $fallback_cost ); }
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

	private static function advance( $job, $artifacts, $stage, $correction_increment = 0 ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		MSRWA_DB::store_artifacts( $job->id, $job->batch_id, $artifacts );
		MSRWA_DB::snapshot( 'stage_transition', array( 'from' => $job->stage, 'to' => sanitize_key( $stage ), 'status' => 'queued' ), $job->batch_id, $job->id );
		$element_cycles = isset( $artifacts['correction_cycles'] ) && is_array( $artifacts['correction_cycles'] ) ? $artifacts['correction_cycles'] : array();
		$updated = $wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts ), 'stage' => sanitize_key( $stage ), 'status' => 'queued', 'correction_cycles' => (int) $job->correction_cycles + absint( $correction_increment ), 'correction_cycles_json' => wp_json_encode( $element_cycles ), 'retry_attempts' => 0, 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ), array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		MSRWA_DB::event( 'stage_completed', $job->batch_id, $job->id, array( 'stage' => $job->stage, 'next' => $stage ) );
		MSRWA_Queue::schedule_job( $job->id );
	}

	private static function set_status( $job, $status, $code, $message, $artifacts = null ) {
		global $wpdb;
		$t = MSRWA_DB::tables();
		$data = array( 'status' => sanitize_key( $status ), 'error_code' => sanitize_key( $code ), 'error_message' => sanitize_textarea_field( $message ), 'lock_token' => null, 'lock_until' => null, 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( is_array( $artifacts ) ) { $data['artifacts_json'] = wp_json_encode( $artifacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); $formats[] = '%s'; }
		$updated = $wpdb->update( $t['jobs'], $data, array( 'id' => $job->id, 'lock_token' => $job->lock_token, 'status' => 'running' ), $formats, array( '%d', '%s', '%s' ) );
		if ( ! $updated ) { return; }
		if ( is_array( $artifacts ) ) { MSRWA_DB::store_artifacts( $job->id, $job->batch_id, $artifacts ); }
		if ( in_array( $status, array( 'needs_review', 'failed', 'paused_budget', 'uncertain' ), true ) && is_array( $artifacts ) ) {
			$artifacts['delivery_findings'][] = array( 'severity' => 'warning', 'field' => $job->stage, 'reason' => $message );
			$post_id = MSRWA_Publisher::create_draft( $job, $artifacts, true );
			MSRWA_DB::event( is_wp_error( $post_id ) ? 'review_draft_failed' : 'review_draft_saved', $job->batch_id, $job->id, is_wp_error( $post_id ) ? array( 'reason' => $post_id->get_error_message() ) : array( 'post_id' => $post_id, 'status' => $status ) );
		}
		MSRWA_DB::snapshot( 'status_change', array( 'stage' => $job->stage, 'status' => sanitize_key( $status ), 'code' => sanitize_key( $code ) ), $job->batch_id, $job->id );
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
