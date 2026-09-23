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
		if ( ! $run || ! MSRWA_Run::may_see( $run ) ) { wp_die( esc_html__( 'Recette introuvable.', 'ms-recipes-writer-ai' ) ); }

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
		self::verdict( $approval );
		if ( MSRWA_Rights::may_read_diagnostics() ) { self::steps( $state['steps'] ); } else { self::progress( $state['steps'] ); }

		if ( MSRWA_Rights::may_read_diagnostics() ) {
			self::calls( $id );
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
			$figures[ __( 'coût', 'ms-recipes-writer-ai' ) ] = MSRWA_I18N::money( $state['totals']['cost_usd'] );
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
		if ( '' === $machine || '' === $published || $machine === $published ) { return; }

		$difference = abs( mb_strlen( $published ) - mb_strlen( $machine ) );
		MSRWA_UI::note( esc_html( sprintf(
			/* translators: %s is a number of characters. */
			__( 'Le brouillon a été modifié depuis sa génération : %s caractères d’écart. Les contrôles ci-dessous portent sur ce que la machine a écrit, pas sur la version actuelle.', 'ms-recipes-writer-ai' ),
			number_format_i18n( $difference )
		) ) );
	}

	/**
	 * The judge's own opinion, framed as what it is.
	 *
	 * It has an opinion about its own output and none whatsoever about whether
	 * this site should publish it, and the wording here never blurs the two.
	 */
	private static function verdict( array $approval ) {
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
		foreach ( $targets as $key => $label ) {
			$entry = (array) ( $approval[ $key ] ?? array() );
			if ( empty( $entry['verdict'] ) ) { continue; }
			$figures[] = array( 'label' => $label, 'value' => self::verdict_word( (string) $entry['verdict'] ), 'note' => (string) ( $entry['summary'] ?? '' ) );
		}
		if ( $figures ) { MSRWA_UI::figures( $figures ); }

		$findings = (array) ( $approval['findings'] ?? array() );
		if ( ! $findings ) { echo '<p class="ms-muted" style="margin-top:14px">' . esc_html__( 'Aucune remarque.', 'ms-recipes-writer-ai' ) . '</p></section>'; return; }

		echo '<table class="ms-table" style="margin-top:16px"><thead><tr>'
			. '<th>' . esc_html__( 'Gravité', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Porte sur', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Ce qui ne va pas', 'ms-recipes-writer-ai' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $findings as $finding ) {
			$blocking = 'blocking' === ( $finding['severity'] ?? '' );
			echo '<tr><td><span class="ms-state ms-state-' . ( $blocking ? 'stop' : 'warn' ) . '">'
				. esc_html( $blocking ? __( 'bloquante', 'ms-recipes-writer-ai' ) : __( 'mineure', 'ms-recipes-writer-ai' ) ) . '</span></td>'
				. '<td>' . esc_html( $targets[ (string) ( $finding['target'] ?? '' ) ] ?? (string) ( $finding['target'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $finding['reason'] ?? '' ) );
			if ( ! empty( $finding['quote'] ) ) { echo '<small>« ' . esc_html( (string) $finding['quote'] ) . ' »</small>'; }
			if ( ! empty( $finding['fix'] ) ) { echo '<small><strong>' . esc_html__( 'Correction :', 'ms-recipes-writer-ai' ) . '</strong> ' . esc_html( (string) $finding['fix'] ) . '</small>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function verdict_word( $verdict ) {
		$words = array(
			'good' => __( 'bon', 'ms-recipes-writer-ai' ),
			'reservations' => __( 'réserves', 'ms-recipes-writer-ai' ),
			'bad' => __( 'mauvais', 'ms-recipes-writer-ai' ),
		);
		return $words[ $verdict ] ?? $verdict;
	}

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

		foreach ( $steps as $step ) {
			echo '<tr><td><strong>' . esc_html( MSRWA_UI::step_name( $step['step'] ) ) . '</strong> <span class="ms-key">' . esc_html( $step['step'] ) . '</span>';
			if ( '' !== $step['error'] ) { echo '<small>' . esc_html( $step['error'] ) . '</small>'; }
			echo '</td>';
			if ( MSRWA_Rights::may_read_diagnostics() ) { echo '<td class="ms-key">' . esc_html( $step['model'] ) . '</td>'; }
			echo '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $step['seconds'] ) ) . '</td>';
			if ( MSRWA_Rights::may_see_money() ) {
				echo '<td class="ms-num">' . esc_html( null === $step['cost_usd'] ? __( 'tarif inconnu', 'ms-recipes-writer-ai' ) : MSRWA_I18N::money( $step['cost_usd'] ) ) . '</td>';
			}
			echo '<td class="ms-num">' . esc_html( null === $step['passed'] ? '—' : $step['passed'] . ' / ' . $step['total'] ) . '</td><td class="ms-wrap">';
			$failed = array_filter( (array) $step['checks'], static function ( $check ) { return is_array( $check ) && empty( $check['pass'] ); } );
			if ( ! $failed ) { echo '—'; }
			foreach ( $failed as $label => $check ) {
				echo '<div><span class="ms-key">' . esc_html( $label ) . '</span> ' . esc_html( is_scalar( $check['detail'] ?? '' ) ? (string) $check['detail'] : '' ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function calls( $id ) {
		$calls = MSRWA_Run::calls( $id );
		if ( ! $calls ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Appels', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ce qui a réellement été facturé, par quel point d’entrée, et quelle part de l’entrée a été servie depuis le cache du fournisseur.', 'ms-recipes-writer-ai' ) . '</p>';
		echo MSRWA_UI::scroll( __( 'Appels', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Modèle', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Entrée', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Cache', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Sortie', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Durée', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Coût', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $calls as $call ) {
			$input = (int) $call['input_tokens'];
			echo '<tr><td>' . esc_html( $call['step'] ) . '</td>'
				. '<td><strong>' . esc_html( $call['model'] ) . '</strong><small>' . esc_html( $call['endpoint'] ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( $input ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( $input && (int) $call['cached_tokens'] ? round( 100 * (int) $call['cached_tokens'] / $input ) . ' %' : '—' ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( (int) $call['output_tokens'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $call['seconds'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( empty( $call['priced'] ) ? __( 'tarif inconnu', 'ms-recipes-writer-ai' ) : MSRWA_I18N::money( $call['cost_usd'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function artifacts( array $artifacts ) {
		if ( ! $artifacts ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Productions', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<table class="ms-table"><thead><tr><th>' . esc_html__( 'Nom', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Taille', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $artifacts as $key => $value ) {
			echo '<tr><td class="ms-key">' . esc_html( $key ) . '</td><td class="ms-num">' . esc_html( size_format( strlen( (string) wp_json_encode( $value ) ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function timeline( array $events ) {
		if ( ! $events ) { return; }
		echo '<section class="ms-card"><h2>' . esc_html__( 'Déroulé', 'ms-recipes-writer-ai' ) . '</h2><div class="ms-timeline">';
		foreach ( $events as $event ) {
			echo '<div><time>' . esc_html( MSRWA_I18N::seconds( $event['at'] ) ) . '</time>'
				. '<span class="ms-key">' . esc_html( $event['step'] ) . '</span>'
				. '<span>' . esc_html( $event['message'] ) . '</span></div>';
		}
		echo '</div></section>';
	}
}
