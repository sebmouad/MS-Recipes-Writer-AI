<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the runs say together.
 *
 * One run says what happened once; these say what happens. Which step carries
 * the cost, which model is actually billed, which named check keeps failing,
 * and how often the judge approves anything at all — the last being the figure
 * that tells you whether a refusal is a real signal or a reviewer that must
 * always find something.
 */
final class MSRWA_Screen_Analysis {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$spend = MSRWA_Ledger::spend( $days );
		$verdicts = MSRWA_Ledger::verdicts( $days );

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Analyse', 'ms-recipes-writer-ai' ),
			__( 'Lu sur ce que le moteur a rapporté, jamais recalculé. Un coût inconnu reste inconnu jusqu’ici.', 'ms-recipes-writer-ai' ),
			array(),
			self::windows( $days )
		);

		MSRWA_UI::figures( array(
			array( 'label' => __( 'recettes', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $spend['runs'] ) ),
			array( 'label' => __( 'dépense', 'ms-recipes-writer-ai' ), 'value' => MSRWA_I18N::money( $spend['spend_usd'], 2 ) ),
			array( 'label' => __( 'par recette', 'ms-recipes-writer-ai' ), 'value' => MSRWA_I18N::money( $spend['average_usd'] ) ),
			array( 'label' => __( 'durée moyenne', 'ms-recipes-writer-ai' ), 'value' => MSRWA_I18N::seconds( $spend['seconds'] ) ),
		) );

		if ( $spend['unpriced_steps'] ) {
			MSRWA_UI::note( sprintf(
				/* translators: %d is a number of steps. */
				__( '%d appel(s) ont tourné sur un modèle sans tarif publié. La dépense ci-dessus est donc incomplète, et non pas exacte : elle ne compte pas ce qu’on ne sait pas chiffrer.', 'ms-recipes-writer-ai' ),
				$spend['unpriced_steps']
			), 'warn' );
		}

		self::verdicts( $verdicts );
		self::steps( $days );
		self::models( $days );
		self::checks( $days );
		self::days();

		echo '</div>';
	}

	private static function windows( $days ) {
		$out = '';
		foreach ( array( 7 => __( '7 jours', 'ms-recipes-writer-ai' ), 30 => __( '30 jours', 'ms-recipes-writer-ai' ), 0 => __( 'Tout', 'ms-recipes-writer-ai' ) ) as $window => $label ) {
			$out .= '<a class="button' . ( (int) $days === $window ? ' button-primary' : '' ) . '" href="'
				. esc_url( admin_url( 'admin.php?page=msrwa-analysis&days=' . $window ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		return $out;
	}

	private static function verdicts( array $verdicts ) {
		if ( ! $verdicts['judged'] ) { return; }
		echo '<section class="ms-card"><h2>' . esc_html__( 'Le juge', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Son avis porte sur sa propre production. Ce n’est jamais une validation éditoriale, et un taux d’approbation très bas ou très haut en dit autant sur le prompt que sur les articles.', 'ms-recipes-writer-ai' ) . '</p>';

		MSRWA_UI::figures( array(
			array( 'label' => __( 'jugés', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $verdicts['judged'] ) ),
			array(
				'label' => __( 'approuvés', 'ms-recipes-writer-ai' ),
				'value' => round( 100 * $verdicts['approved'] / max( 1, $verdicts['judged'] ) ) . ' %',
				'note' => sprintf( /* translators: %d is a count. */ __( '%d au total', 'ms-recipes-writer-ai' ), $verdicts['approved'] ),
			),
			array( 'label' => __( 'remarques', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $verdicts['findings'] ) ),
			array( 'label' => __( 'bloquantes', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $verdicts['blocking'] ) ),
		) );

		if ( $verdicts['targets'] ) {
			echo '<table class="ms-table" style="margin-top:16px"><thead><tr><th>' . esc_html__( 'Artefact', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Verdicts', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
			foreach ( $verdicts['targets'] as $target => $counts ) {
				echo '<tr><td class="ms-key">' . esc_html( $target ) . '</td><td>';
				foreach ( $counts as $verdict => $count ) { echo '<span class="ms-state">' . esc_html( $verdict . ' × ' . $count ) . '</span> '; }
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	private static function steps( $days ) {
		$rows = MSRWA_Ledger::by_step( $days );
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Par étape', 'ms-recipes-writer-ai' ) . '</h2>';
		if ( ! $rows ) { MSRWA_UI::nothing( __( 'Rien à analyser', 'ms-recipes-writer-ai' ), __( 'Aucune recette n’a encore tourné sur cette période.', 'ms-recipes-writer-ai' ) ); echo '</section>'; return; }
		echo '<div class="ms-scroll"><table class="ms-table"><thead><tr>'
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Exéc.', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Durée moy.', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Coût moy.', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Dépense', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Entrée', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Sortie', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Score', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Échecs', 'ms-recipes-writer-ai' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><strong>' . esc_html( $row['step'] ) . '</strong><small>' . esc_html( $row['bucket'] ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( $row['runs'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $row['seconds'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( null === $row['cost'] ? '—' : MSRWA_I18N::money( $row['cost'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( round( (float) $row['input_tokens'] ) ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( round( (float) $row['output_tokens'] ) ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( null === $row['score'] ? '—' : round( 100 * (float) $row['score'] ) . ' %' ) . '</td>'
				. '<td class="ms-num">' . esc_html( $row['failures'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function models( $days ) {
		$rows = MSRWA_Ledger::by_model( $days );
		if ( ! $rows ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Par modèle', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ce qui a réellement été facturé, et quelle part de l’entrée le fournisseur a servie depuis son cache.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<div class="ms-scroll"><table class="ms-table"><thead><tr>'
			. '<th>' . esc_html__( 'Modèle', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Appels', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Entrée', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Cache', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Sortie', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Dépense', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Sans tarif', 'ms-recipes-writer-ai' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$input = (int) $row['input_tokens'];
			echo '<tr><td><strong>' . esc_html( $row['model'] ) . '</strong><small>' . esc_html( $row['provider'] ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( $row['calls'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( $input ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( $input ? round( 100 * (int) $row['cached_tokens'] / $input ) . ' %' : '—' ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( (int) $row['output_tokens'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( $row['unpriced'] ? $row['unpriced'] : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function checks( $days ) {
		$rows = MSRWA_Ledger::checks( $days );
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Contrôles qui échouent', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Un contrôle qui échoue une fois sur six est du bruit ; six fois sur six, c’est un contrat que le prompt ne tient pas.', 'ms-recipes-writer-ai' ) . '</p>';
		if ( ! $rows ) { MSRWA_UI::nothing( __( 'Aucun contrôle en échec', 'ms-recipes-writer-ai' ), __( 'Toutes les étapes ont satisfait leurs contrôles sur cette période.', 'ms-recipes-writer-ai' ) ); echo '</section>'; return; }
		echo '<div class="ms-scroll"><table class="ms-table"><thead><tr>'
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Contrôle', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Échecs', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Sur', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Taux', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['step'] ) . '</td><td class="ms-key">' . esc_html( $row['check'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( $row['failed'] ) . '</td><td class="ms-num">' . esc_html( $row['seen'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( round( 100 * $row['failed'] / max( 1, $row['seen'] ) ) . ' %' ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/** Spend per day, drawn as bars rather than a chart library nobody can audit. */
	private static function days() {
		$rows = MSRWA_Ledger::by_day( 14 );
		if ( ! $rows ) { return; }
		$peak = 0.0;
		foreach ( $rows as $row ) { $peak = max( $peak, (float) $row['spend'] ); }

		echo '<section class="ms-card"><h2>' . esc_html__( 'Sur quatorze jours', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<table class="ms-table"><tbody>';
		foreach ( $rows as $row ) {
			$share = $peak > 0 ? round( 100 * (float) $row['spend'] / $peak ) : 0;
			echo '<tr><td class="ms-key" style="inline-size:120px">' . esc_html( $row['day'] ) . '</td>'
				. '<td><span class="ms-progress" style="inline-size:100%"><i style="inline-size:' . (int) $share . '%"></i></span></td>'
				. '<td class="ms-num" style="inline-size:110px">' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) ) . '</td>'
				. '<td class="ms-num" style="inline-size:90px">' . esc_html( sprintf( /* translators: %d is a count of recipes. */ _n( '%d recette', '%d recettes', (int) $row['runs'], 'ms-recipes-writer-ai' ), (int) $row['runs'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}
}
