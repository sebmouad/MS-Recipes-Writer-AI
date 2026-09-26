<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One recipe, and everything the engine reported about it.
 *
 * Two readers stand here. A writer wants to know whether the article is usable
 * and what was objected to. An operator wants to know what it cost, on which
 * model, and which check failed. So the verdict comes first for everyone, and
 * the diagnostics only exist for the reader who may see them.
 */
final class MSRWA_Screen_Run {

	public static function render() {
		if ( ! MSRWA_Rights::may_write() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$run = MSRWA_Run::get( isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0 );
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { wp_die( esc_html__( 'Recette introuvable.', 'ms-recipes-writer-ai' ), '', array( 'response' => 404, 'back_link' => true ) ); }

		$id = (int) $run['id'];
		$state = MSRWA_Run::state( $id );
		$approval = (array) ( $state['artifacts']['approval'] ?? array() );

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			$run['label'],
			'',
			self::figures( $run, $state ),
			self::actions( $run )
		);
		MSRWA_UI::facts( $run );

		// Where it stands, in a sentence, for everybody; the raw message only for
		// whoever can act on it.
		$reason = MSRWA_UI::reason( $run, $state['steps'] );
		MSRWA_UI::note( esc_html( $reason[1] ), $reason[0] );
		if ( '' !== (string) $run['error_message'] && MSRWA_Rights::may_read_diagnostics() ) { MSRWA_UI::note( esc_html( $run['error_message'] ), 'stop' ); }
		if ( ! empty( $state['totals']['unpriced_steps'] ) && MSRWA_Rights::may_see_money() ) {
			MSRWA_UI::note( sprintf(
				/* translators: %d is a number of steps. */
				esc_html__( '%d étape(s) ont tourné sur un modèle sans tarif publié. Le coût de cette recette est incomplet, pas exact.', 'ms-recipes-writer-ai' ),
				(int) $state['totals']['unpriced_steps']
			), 'warn' );
		}

		self::edited( $state['artifacts'] );
		self::redrawn();
		self::verdict( $approval, $run, ! empty( $state['artifacts']['facebook']['provided'] ) );
		if ( MSRWA_Rights::may_read_diagnostics() ) { self::steps( $state['steps'] ); } else { self::progress( $state['steps'] ); }

		if ( MSRWA_Rights::may_read_diagnostics() ) {
			self::calls( $id, $state['steps'] );
			self::artifacts( $state['artifacts'] );
			self::timeline( $state['events'] );
		}

		echo '</div>';
	}

	private static function figures( array $run, array $state ) {
		$figures = array(
			__( 'étapes', 'ms-recipes-writer-ai' ) => $run['steps_done'] . ' / ' . $run['steps_total'],
		);
		if ( MSRWA_Rights::may_see_money() ) {
			$ceiling = (float) ( MSRWA_Batch::config_for( (int) $run['batch_id'] )['limits']['budget_usd'] ?? 0 );
			/* translators: 1: what the recipe cost, 2: its ceiling. */
			$figures[ __( 'coût', 'ms-recipes-writer-ai' ) ] = $ceiling > 0 ? sprintf( __( '%1$s sur %2$s', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( $state['totals']['cost_usd'] ), MSRWA_I18N::money( $ceiling, 2 ) ) : MSRWA_I18N::money( $state['totals']['cost_usd'] );
			$figures[ __( 'durée', 'ms-recipes-writer-ai' ) ] = MSRWA_I18N::seconds( $state['totals']['seconds'] );
		}
		return $figures;
	}

	private static function actions( array $run ) {
		$out = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa-batch&batch_id=' . (int) $run['batch_id'] ) ) . '">'
			. esc_html__( 'Retour au lot', 'ms-recipes-writer-ai' ) . '</a> ';

		if ( (int) $run['draft_post_id'] ) {
			$out .= '<a class="button button-primary" href="' . esc_url( (string) get_edit_post_link( (int) $run['draft_post_id'] ) ) . '">'
				. esc_html__( 'Ouvrir le brouillon', 'ms-recipes-writer-ai' ) . '</a> ';
		}

		if ( MSRWA_Rights::may_read_diagnostics() && (int) $run['steps_done'] ) {
			$report = wp_nonce_url(
				admin_url( 'admin-post.php?action=msrwa_report&run_id=' . (int) $run['id'] ),
				'msrwa_job_report_' . (int) $run['id']
			);
			$out .= '<a class="button" href="' . esc_url( $report ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Rapport complet', 'ms-recipes-writer-ai' ) . '</a> ';
		}

		if ( MSRWA_Run::may_retry( $run ) ) {
			$out .= '<button class="button ms-retry" data-run="' . esc_attr( $run['id'] ) . '">'
				. esc_html__( 'Reprendre', 'ms-recipes-writer-ai' ) . '</button> ';
		}

		// Only while it is waiting: reordering a recipe that is already running
		// changes nothing, and offering it would say otherwise.
		if ( MSRWA_Rights::may_manage() && 'queued' === (string) $run['status'] ) {
			$ahead = (int) ( $run['priority'] ?? 0 ) > 0;
			$out .= '<button class="button ms-priority" data-run="' . esc_attr( $run['id'] ) . '" data-priority="' . ( $ahead ? '0' : '5' ) . '">'
				. esc_html( $ahead ? __( 'Remettre dans l’ordre', 'ms-recipes-writer-ai' ) : __( 'Faire passer devant', 'ms-recipes-writer-ai' ) )
				. '</button> ';
		}

		return $out . '<span class="ms-muted" id="ms-run-status" aria-live="polite"></span>';
	}

	/**
	 * Whether anybody has changed the article since the machine wrote it.
	 *
	 * Worth saying out loud on a screen that reports how the engine performed:
	 * a check that passed on the machine's text tells you nothing about a
	 * paragraph an editor added afterwards, and this is the only place that
	 * distinction is visible.
	 */
	private static function edited( array $artifacts ) {
		$machine = (string) ( ( (array) ( $artifacts['proofread'] ?? array() ) )['content_html'] ?? '' );
		$published = (string) ( ( (array) ( $artifacts['published'] ?? array() ) )['content_html'] ?? '' );
		if ( '' === $machine || '' === $published ) { return; }
		// The draft stores the machine's HTML as blocks: their comments and
		// classes are not an edit, so only the text a reader sees is compared.
		$machine = self::readable( $machine );
		$published = self::readable( $published );
		if ( $machine === $published ) { return; }

		$difference = abs( mb_strlen( $published ) - mb_strlen( $machine ) );
		MSRWA_UI::note( esc_html( sprintf(
			/* translators: %s is a number of characters. */
			__( 'Le brouillon a été modifié depuis sa génération : %s caractères d’écart. Les contrôles ci-dessous portent sur ce que la machine a écrit, pas sur la version actuelle.', 'ms-recipes-writer-ai' ),
			number_format_i18n( $difference )
		) ) );
	}

	/** An article's text as its reader sees it: no block comments, tags or spacing. */
	public static function readable( $html ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', (string) $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * The judge's own opinion, framed as what it is.
	 *
	 * It has an opinion about its own output and none whatsoever about whether
	 * this site should publish it, and the wording here never blurs the two.
	 */
	/** The redraw the editor just asked for went through. */
	private static function redrawn() {
		if ( ! isset( $_GET['redrawn'] ) ) { return; }
		MSRWA_UI::note( esc_html__( 'Image redessinée d’après les remarques. Elle remplace l’ancienne dans le brouillon ; regardez-la avant de publier.', 'ms-recipes-writer-ai' ), 'good' );
	}

	private static function verdict( array $approval, array $run = array(), $provided = false ) {
		if ( ! $approval ) { return; }
		echo '<section class="ms-card"><h2>' . esc_html__( 'Ce que le juge a relevé', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'L’avis du moteur sur sa propre production. Ce n’est pas une validation éditoriale : c’est à vous de décider si l’article part.', 'ms-recipes-writer-ai' ) . '</p>';

		$figures = array();
		$targets = array(
			'article' => __( 'article', 'ms-recipes-writer-ai' ),
			'featured_image' => __( 'image à la une', 'ms-recipes-writer-ai' ),
			'facebook_image' => __( 'collage', 'ms-recipes-writer-ai' ),
			'consistency' => __( 'cohérence', 'ms-recipes-writer-ai' ),
		);
		// Each verdict beside the image it judged, coloured as it reads.
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		$images = $post_id ? array(
			'featured_image' => (int) get_post_thumbnail_id( $post_id ),
			'facebook_image' => (int) get_post_meta( $post_id, MSRWA_Draft::generated_key( 'facebook' ), true ),
		) : array();
		$tones = array( 'good' => 'good', 'reservations' => 'warn', 'bad' => 'stop' );
		foreach ( $targets as $key => $label ) {
			$entry = (array) ( $approval[ $key ] ?? array() );
			if ( empty( $entry['verdict'] ) ) { continue; }
			// The writer's own collage was never judged: it is shown as theirs, not as passed.
			if ( 'facebook_image' === $key && $provided ) {
				$figures[] = array( 'label' => $label, 'value' => __( 'Votre collage', 'ms-recipes-writer-ai' ), 'note' => __( 'Publié tel quel et référence de la recette : le juge ne le note pas.', 'ms-recipes-writer-ai' ), 'tone' => 'lamp', 'image' => $images[ $key ] ?? 0 );
				continue;
			}
			$figures[] = array(
				'label' => $label, 'value' => self::verdict_word( (string) $entry['verdict'] ), 'note' => (string) ( $entry['summary'] ?? '' ),
				'tone' => $tones[ (string) $entry['verdict'] ] ?? '', 'image' => $images[ $key ] ?? 0,
			);
		}
		if ( $figures ) { MSRWA_UI::figures( $figures ); }

		$findings = (array) ( $approval['findings'] ?? array() );
		if ( ! $findings ) { echo '<p class="ms-muted" style="margin-top:14px">' . esc_html__( 'Aucune remarque.', 'ms-recipes-writer-ai' ) . '</p></section>'; return; }

		echo '<table class="ms-table ms-stack ms-findings" style="margin-top:16px"><thead><tr>'
			. '<th>' . esc_html__( 'Gravité', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Porte sur', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Ce qui ne va pas', 'ms-recipes-writer-ai' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $findings as $finding ) {
			$blocking = 'blocking' === ( $finding['severity'] ?? '' );
			echo '<tr><td data-label="' . esc_attr__( 'Gravité', 'ms-recipes-writer-ai' ) . '"><span class="ms-state ms-state-' . ( $blocking ? 'stop' : 'warn' ) . '">'
				. esc_html( $blocking ? __( 'bloquante', 'ms-recipes-writer-ai' ) : __( 'mineure', 'ms-recipes-writer-ai' ) ) . '</span></td>'
				. '<td data-label="' . esc_attr__( 'Porte sur', 'ms-recipes-writer-ai' ) . '">' . esc_html( $targets[ (string) ( $finding['target'] ?? '' ) ] ?? (string) ( $finding['target'] ?? '' ) ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Ce qui ne va pas', 'ms-recipes-writer-ai' ) . '">' . esc_html( (string) ( $finding['reason'] ?? '' ) );
			if ( ! empty( $finding['quote'] ) ) { echo '<small>« ' . esc_html( (string) $finding['quote'] ) . ' »</small>'; }
			if ( ! empty( $finding['fix'] ) ) { echo '<small><strong>' . esc_html__( 'Correction :', 'ms-recipes-writer-ai' ) . '</strong> ' . esc_html( (string) $finding['fix'] ) . '</small>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::redraw_buttons( $run );
		echo '</section>';
	}

	/**
	 * The refused images the editor may have redrawn. The engine does not redraw
	 * on its own any more: the editor reads the findings and decides.
	 */
	private static function redraw_buttons( array $run ) {
		// The verdict above is about the image as first drawn: say so once it has been replaced.
		$post_id = (int) ( $run['draft_post_id'] ?? 0 );
		$notes = array(
			/* translators: %d is how many times. */
			'featured' => __( 'L’image à la une a été redessinée %d fois d’après ces remarques ; la nouvelle n’a pas été rejugée.', 'ms-recipes-writer-ai' ),
			/* translators: %d is how many times. */
			'facebook' => __( 'Le collage a été redessiné %d fois d’après ces remarques ; le nouveau n’a pas été rejugé.', 'ms-recipes-writer-ai' ),
		);
		foreach ( $notes as $kind => $note ) {
			$times = $post_id ? (int) get_post_meta( $post_id, '_msrwa_' . $kind . '_redrawn', true ) : 0;
			if ( $times ) { echo '<p class="ms-muted" style="margin-top:14px">' . esc_html( sprintf( $note, $times ) ) . '</p>'; }
		}
		$kinds = $run ? MSRWA_Run::redrawable( $run ) : array();
		if ( ! $kinds ) { return; }
		$labels = array( 'featured' => __( 'Redessiner l’image à la une', 'ms-recipes-writer-ai' ), 'facebook' => __( 'Redessiner le collage', 'ms-recipes-writer-ai' ) );
		echo '<p class="ms-muted" style="margin-top:14px">' . esc_html__( 'Une image refusée n’est pas redessinée d’office. Si les remarques vous semblent justes, faites-la redessiner : elles sont transmises au dessin, et la nouvelle image remplace l’ancienne dans le brouillon.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<p class="ms-redraw" data-run="' . esc_attr( (string) (int) $run['id'] ) . '" data-busy="' . esc_attr__( 'Dessin en cours, une trentaine de secondes…', 'ms-recipes-writer-ai' ) . '">';
		foreach ( $kinds as $kind ) {
			echo '<button type="button" class="button" value="' . esc_attr( $kind ) . '">' . esc_html( $labels[ $kind ] ) . '</button> ';
		}
		echo '<span class="ms-muted" aria-live="polite"></span></p>';
	}

	private static function verdict_word( $verdict ) { return MSRWA_UI::verdict_word( $verdict ); }

	/**
	 * The steps as a writer needs them: what has been done and what went wrong,
	 * without scores, check names or model names.
	 */
	private static function progress( array $steps ) {
		if ( ! $steps ) { return; }
		echo '<section class="ms-card"><h2>' . esc_html__( 'Étapes', 'ms-recipes-writer-ai' ) . '</h2><ol class="ms-steps-plain">';
		foreach ( $steps as $step ) {
			$failed = '' !== (string) $step['error'];
			echo '<li class="' . ( $failed ? 'is-failed' : 'is-done' ) . '"><span class="ms-state ms-state-' . ( $failed ? 'stop' : 'good' ) . '">'
				. esc_html( $failed ? __( 'arrêtée', 'ms-recipes-writer-ai' ) : __( 'faite', 'ms-recipes-writer-ai' ) ) . '</span> '
				. esc_html( MSRWA_UI::step_name( $step['step'] ) ) . '</li>';
		}
		echo '</ol></section>';
	}

	private static function steps( array $steps ) {
		if ( ! $steps ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Étapes', 'ms-recipes-writer-ai' ) . '</h2>'
			. MSRWA_UI::scroll( __( 'Étapes', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th>';
		if ( MSRWA_Rights::may_read_diagnostics() ) { echo '<th>' . esc_html__( 'Modèle', 'ms-recipes-writer-ai' ) . '</th>'; }
		echo '<th class="ms-num">' . esc_html__( 'Durée', 'ms-recipes-writer-ai' ) . '</th>';
		if ( MSRWA_Rights::may_see_money() ) { echo '<th class="ms-num">' . esc_html__( 'Coût', 'ms-recipes-writer-ai' ) . '</th>'; }
		echo '<th class="ms-num">' . esc_html__( 'Score', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Contrôles non satisfaits', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';

		$spent = max( 0.000001, array_sum( array_map( static function ( $step ) { return (float) $step['cost_usd']; }, $steps ) ) );
		foreach ( $steps as $step ) {
			echo '<tr' . ( '' !== $step['error'] ? ' class="ms-row-failed"' : '' ) . '><td><strong>' . esc_html( MSRWA_UI::step_name( $step['step'] ) ) . '</strong> <span class="ms-key">' . esc_html( $step['step'] ) . '</span>';
			if ( '' !== $step['error'] ) { echo '<small>' . esc_html( $step['error'] ) . '</small>'; }
			echo '</td>';
			if ( MSRWA_Rights::may_read_diagnostics() ) { echo '<td class="ms-key">' . esc_html( $step['model'] ) . '</td>'; }
			echo '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $step['seconds'] ) ) . '</td>';
			if ( MSRWA_Rights::may_see_money() ) {
				// The bar is the step's share of the recipe: where the money went, at a glance.
				$share = null === $step['cost_usd'] ? 0 : (int) round( 100 * (float) $step['cost_usd'] / $spent );
				echo '<td class="ms-num">' . esc_html( null === $step['cost_usd'] ? __( 'tarif inconnu', 'ms-recipes-writer-ai' ) : MSRWA_I18N::money( $step['cost_usd'] ) )
					. ( $share > 0 ? '<span class="ms-costbar" style="--share:' . (int) $share . '%" title="' . esc_attr( $share . ' %' ) . '"></span>' : '' ) . '</td>';
			}
			$tone = null === $step['passed'] ? '' : ( '' !== $step['error'] ? 'stop' : ( (int) $step['passed'] < (int) $step['total'] ? 'warn' : 'good' ) );
			echo '<td class="ms-num">' . ( null === $step['passed'] ? ( '' !== $step['error'] ? '<span class="ms-state ms-state-stop">' . esc_html__( 'échec', 'ms-recipes-writer-ai' ) . '</span>' : '—' ) : '<span class="ms-state ms-state-' . esc_attr( $tone ) . '">' . esc_html( $step['passed'] . ' / ' . $step['total'] ) . '</span>' ) . '</td><td class="ms-wrap">';
			$failed = array_filter( (array) $step['checks'], static function ( $check ) { return is_array( $check ) && empty( $check['pass'] ); } );
			if ( ! $failed ) { echo '—'; }
			foreach ( $failed as $label => $check ) {
				echo '<div><span class="ms-key">' . esc_html( $label ) . '</span> ' . esc_html( is_scalar( $check['detail'] ?? '' ) ? (string) $check['detail'] : '' ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function calls( $id, array $steps = array() ) {
		$calls = MSRWA_Run::calls( $id );
		if ( ! $calls ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Appels', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ce qui a réellement été facturé, par quel point d’entrée, et quelle part de l’entrée a été servie depuis le cache du fournisseur.', 'ms-recipes-writer-ai' ) . '</p>';
		echo MSRWA_UI::scroll( __( 'Appels', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Modèle', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Entrée', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Cache', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Sortie', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Durée', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Coût', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		$billed = array();
		foreach ( $calls as $call ) {
			$input = (int) $call['input_tokens'];
			echo '<tr><td>' . esc_html( $call['step'] ) . '</td>'
				. '<td><strong>' . esc_html( $call['model'] ) . '</strong><small>' . esc_html( $call['endpoint'] ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( $input ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( $input && (int) $call['cached_tokens'] ? round( 100 * (int) $call['cached_tokens'] / $input ) . ' %' : '—' ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( (int) $call['output_tokens'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $call['seconds'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( empty( $call['priced'] ) ? __( 'tarif inconnu', 'ms-recipes-writer-ai' ) : MSRWA_I18N::money( $call['cost_usd'] ) ) . '</td></tr>';
			$billed[ $call['step'] ] = ( $billed[ $call['step'] ] ?? 0.0 ) + (float) $call['cost_usd'];
		}
		// The research reads the photographs it cites with calls billed to it
		// but not itemised: the difference is its own line, so the table adds
		// up to what the recipe cost.
		$total = array_sum( $billed );
		foreach ( $steps as $step ) {
			$gap = (float) ( $step['cost_usd'] ?? 0 ) - ( $billed[ $step['step'] ] ?? 0.0 );
			$billed[ $step['step'] ] = max( 0.0, -$gap );
			if ( $gap < 0.00001 ) { continue; }
			$total += $gap;
			echo '<tr class="ms-row-quiet"><td>' . esc_html( $step['step'] ) . '</td><td colspan="5"><small>' . esc_html__( 'Part de l’étape sans appel détaillé : la lecture des photographies qu’elle cite', 'ms-recipes-writer-ai' ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::money( $gap ) ) . '</td></tr>';
		}
		echo '<tr class="ms-row-total"><td colspan="6">' . esc_html__( 'Total', 'ms-recipes-writer-ai' ) . '</td><td class="ms-num">' . esc_html( MSRWA_I18N::money( $total ) ) . '</td></tr>';
		echo '</tbody></table></div></section>';
	}

	private static function artifacts( array $artifacts ) {
		if ( ! $artifacts ) { return; }
		echo '<section class="ms-card ms-card-flush"><details class="ms-fold"><summary><h2>' . esc_html__( 'Productions', 'ms-recipes-writer-ai' ) . '</h2><span class="ms-muted">' . esc_html( sprintf( /* translators: %d is how many. */ _n( '%d élément conservé', '%d éléments conservés', count( $artifacts ), 'ms-recipes-writer-ai' ), count( $artifacts ) ) ) . '</span></summary>';
		echo '<table class="ms-table"><thead><tr><th>' . esc_html__( 'Nom', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Taille', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $artifacts as $key => $value ) {
			echo '<tr><td class="ms-key">' . esc_html( $key ) . '</td><td class="ms-num">' . esc_html( size_format( strlen( (string) wp_json_encode( $value ) ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></details></section>';
	}

	private static function timeline( array $events ) {
		if ( ! $events ) { return; }
		// The story — waves, steps, readings, warnings, decisions — shows; the
		// attempts, prompts and calls behind it are one click away.
		echo '<section class="ms-card"><h2>' . esc_html__( 'Déroulé', 'ms-recipes-writer-ai' ) . '</h2>'
			. '<input type="checkbox" id="ms-timeline-all" class="ms-timeline-all"><label for="ms-timeline-all" class="button ms-timeline-toggle">' . esc_html__( 'Afficher aussi les essais, consignes et appels', 'ms-recipes-writer-ai' ) . '</label>'
			. '<div class="ms-timeline">';
		// One colour per kind of event, so a failure or a warning is seen from afar.
		$flags = array(
			'error' => __( 'Échec', 'ms-recipes-writer-ai' ), 'warning' => __( 'Avertissement', 'ms-recipes-writer-ai' ),
			'retry' => __( 'Nouvel essai', 'ms-recipes-writer-ai' ), 'decision' => __( 'Décision', 'ms-recipes-writer-ai' ),
		);
		foreach ( $events as $event ) {
			$kind = sanitize_html_class( (string) ( $event['kind'] ?? '' ) );
			echo '<div class="ms-ev-' . esc_attr( $kind ) . '"><time>' . esc_html( MSRWA_I18N::seconds( $event['at'] ) ) . '</time>'
				. '<span class="ms-key">' . esc_html( $event['step'] ) . '</span>'
				. '<span>' . ( isset( $flags[ $kind ] ) ? '<b class="ms-ev-flag">' . esc_html( $flags[ $kind ] ) . '</b> ' : '' ) . esc_html( $event['message'] ) . '</span></div>';
		}
		echo '</div></section>';
	}
}
