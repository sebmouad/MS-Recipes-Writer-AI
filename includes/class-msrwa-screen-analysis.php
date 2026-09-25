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
		$before = MSRWA_Ledger::previously( $days );
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
			array(
				'label' => __( 'dépense', 'ms-recipes-writer-ai' ), 'value' => MSRWA_I18N::money( $spend['spend_usd'], 2 ),
				'note' => trim( self::ceiling_note( $days ) . ' ' . ( $spend['matching_usd'] > 0
					/* translators: %s is an amount of money. */
					? sprintf( __( 'Dont %s pour lire et apparier les photographies des lots.', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( $spend['matching_usd'], 2 ) ) : '' ) ),
			),
			array(
				'label' => __( 'par recette', 'ms-recipes-writer-ai' ),
				'value' => MSRWA_I18N::money( $spend['average_usd'] ),
				'note' => self::against( $spend['average_usd'], $before['average_usd'], $spend['runs'], $before['runs'], $days ),
			),
			array(
				'label' => __( 'durée moyenne', 'ms-recipes-writer-ai' ),
				'value' => MSRWA_I18N::seconds( $spend['seconds'] ),
				'note' => self::against( $spend['seconds'], $before['seconds'], $spend['runs'], $before['runs'], $days ),
			),
		) );

		self::buckets( $days );

		if ( $spend['unpriced_steps'] ) {
			MSRWA_UI::note( sprintf(
				/* translators: %d is a number of steps. */
				__( '%d appel(s) ont tourné sur un modèle sans tarif publié. La dépense ci-dessus est donc incomplète, et non pas exacte : elle ne compte pas ce qu’on ne sait pas chiffrer.', 'ms-recipes-writer-ai' ),
				$spend['unpriced_steps']
			), 'warn' );
		}

		self::verdicts( $verdicts );
		self::steps( $days );
		self::models( $days, (float) $spend['spend_usd'] );
		self::checks( $days );
		self::days();

		echo '<section class="ms-card"><h2>' . esc_html__( 'Exporter', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'La même période, en CSV, pour les questions qu’un tableur répond mieux qu’un écran. Un coût inconnu y est vide, jamais zéro : une colonne de zéros s’additionne en un total qui n’a jamais existé.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<p>' . MSRWA_Export::links( $days ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links() escapes its own.
		echo '</section>';

		echo '</div>';
	}

	/**
	 * How this window compares with the one before it, when that is honest.
	 *
	 * Two runs against three is not a trend, so below a handful on either side
	 * this says nothing at all rather than dressing noise as a direction. A
	 * window of "everything" has nothing before it and is left alone too.
	 */
	private static function against( $now, $then, $runs_now, $runs_then, $days ) {
		if ( $days <= 0 || $runs_now < 3 || $runs_then < 3 || $then <= 0 ) { return ''; }
		$change = round( 100 * ( (float) $now - (float) $then ) / (float) $then );
		if ( 0 === (int) $change ) {
			/* translators: %d is a number of days. */
			return sprintf( __( 'stable sur %d jours', 'ms-recipes-writer-ai' ), (int) $days );
		}
		return sprintf(
			/* translators: 1: a signed percentage, e.g. "+12 %"; 2: a number of days. */
			__( '%1$s sur les %2$d jours précédents', 'ms-recipes-writer-ai' ),
			( $change > 0 ? '+' : '−' ) . abs( (int) $change ) . ' %',
			(int) $days
		);
	}

	/** What is left under the ceiling, when the site has set one. */
	private static function ceiling_note( $days ) {
		$state = MSRWA_Budget::state();
		$budget = 30 === (int) $days ? $state['monthly'] : ( 1 === (int) $days ? $state['daily'] : null );
		if ( ! $budget || ! $budget['ceiling'] ) { return ''; }
		return sprintf(
			/* translators: %s is an amount of money. */
			__( 'plafond %s', 'ms-recipes-writer-ai' ),
			MSRWA_I18N::money( $budget['ceiling'], 2 )
		);
	}

	/**
	 * Which part of the product the bill is for.
	 *
	 * The step table is a long list; this is four lines, and it is the first
	 * thing somebody looking at a total wants to know — whether the money went
	 * on the writing or on the pictures.
	 */
	private static function buckets( $days ) {
		$rows = MSRWA_Ledger::by_bucket( $days );
		if ( count( $rows ) < 2 ) { return; }

		$labels = array(
			'article' => __( 'l’article', 'ms-recipes-writer-ai' ),
			'featured' => __( 'l’image à la une', 'ms-recipes-writer-ai' ),
			'facebook' => __( 'l’image Facebook', 'ms-recipes-writer-ai' ),
			'other' => __( 'le reste', 'ms-recipes-writer-ai' ),
		);
		$total = 0.0;
		$minutes = 0.0;
		foreach ( $rows as $row ) { $total += (float) $row['spend']; $minutes += (float) $row['seconds']; }

		echo '<section class="ms-card"><h2>' . esc_html__( 'Où part l’argent', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Les quatre postes du moteur, dépense et temps passé. Le temps n’est pas la dépense : une image coûte cher et va vite, une relecture est l’inverse.', 'ms-recipes-writer-ai' ) . '</p>';

		// Each item keeps its colour whatever its rank: the order is the
		// product's, not the period's.
		$order = array( 'article' => 1, 'featured' => 2, 'facebook' => 3, 'other' => 4 );
		$by = array();
		foreach ( $rows as $row ) { $by[ (string) $row['bucket'] ] = $row; }
		uksort( $by, static function ( $a, $b ) use ( $order ) { return ( $order[ $a ] ?? 9 ) <=> ( $order[ $b ] ?? 9 ); } );

		echo '<div class="ms-split">';
		echo '<ul class="ms-split-legend">';
		foreach ( $by as $bucket => $row ) {
			echo '<li><i class="ms-cat-' . (int) ( $order[ $bucket ] ?? 4 ) . '"></i>' . esc_html( $labels[ $bucket ] ?? $bucket ) . ' <b>' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) ) . '</b></li>';
		}
		echo '</ul>';
		foreach ( array(
			array( __( 'Dépense', 'ms-recipes-writer-ai' ), 'spend', $total, MSRWA_I18N::money( $total, 2 ) ),
			array( __( 'Temps', 'ms-recipes-writer-ai' ), 'seconds', $minutes, MSRWA_I18N::seconds( $minutes ) ),
		) as $bar ) {
			list( $title, $field, $sum, $shown ) = $bar;
			echo '<div class="ms-split-row"><span>' . esc_html( $title ) . '</span><div class="ms-split-bar" role="img" aria-label="' . esc_attr( $title ) . '">';
			foreach ( $by as $bucket => $row ) {
				$value = (float) $row[ $field ];
				$share = $sum > 0 ? 100 * $value / $sum : 0;
				if ( $share <= 0 ) { continue; }
				$amount = 'spend' === $field ? MSRWA_I18N::money( $value, 2 ) : MSRWA_I18N::seconds( $value );
				$label = $labels[ $bucket ] ?? $bucket;
				echo '<div class="ms-cat-' . (int) ( $order[ $bucket ] ?? 4 ) . '" style="--w:' . esc_attr( number_format( $share, 2, '.', '' ) ) . '%" tabindex="0"'
					. ' data-tip-title="' . esc_attr( $label ) . '" data-tip="' . esc_attr( $title . ' : ' . $amount . ' · ' . round( $share ) . ' %' ) . '"'
					. ' aria-label="' . esc_attr( $label . ', ' . $title . ' ' . $amount . ', ' . round( $share ) . ' %' ) . '">'
					// A share too narrow for its label is left to the tooltip and the table.
					. ( $share >= 9 ? '<em>' . esc_html( round( $share ) . ' %' ) . '</em>' : '' ) . '</div>';
			}
			echo '</div><strong>' . esc_html( $shown ) . '</strong></div>';
		}
		echo '</div>';

		// The same figures as a table, for whoever reads numbers rather than bars.
		echo '<table class="ms-table ms-share"><thead><tr>'
			. '<th>' . esc_html__( 'Poste', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Dépense', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Temps', 'ms-recipes-writer-ai' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $by as $bucket => $row ) {
			$share = $total > 0 ? round( 100 * (float) $row['spend'] / $total ) : 0;
			$time = $minutes > 0 ? round( 100 * (float) $row['seconds'] / $minutes ) : 0;
			echo '<tr><td class="ms-share-label"><i class="ms-swatch ms-cat-' . (int) ( $order[ $bucket ] ?? 4 ) . '"></i> ' . esc_html( $labels[ $bucket ] ?? $bucket ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) )
				/* translators: %s is a percentage, e.g. "30 %". */
				. '<small>' . esc_html( sprintf( __( '%s du coût', 'ms-recipes-writer-ai' ), $share . ' %' ) ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $row['seconds'] ) )
				/* translators: %s is a percentage, e.g. "40 %". */
				. '<small>' . esc_html( sprintf( __( '%s du temps', 'ms-recipes-writer-ai' ), $time . ' %' ) ) . '</small></td></tr>';
		}
		echo '</tbody></table></section>';
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
			// One bar per thing judged, split good / reservations / bad in the
			// status colours, each part labelled: how often the judge objects to
			// each image, at a glance.
			$names = array(
				'article' => __( 'article', 'ms-recipes-writer-ai' ), 'featured_image' => __( 'image à la une', 'ms-recipes-writer-ai' ),
				'facebook_image' => __( 'collage', 'ms-recipes-writer-ai' ), 'consistency' => __( 'cohérence', 'ms-recipes-writer-ai' ),
			);
			echo '<div class="ms-split" style="margin-top:18px"><ul class="ms-split-legend">';
			foreach ( array( 'good', 'reservations', 'bad' ) as $verdict ) {
				echo '<li><i class="ms-verdict-' . esc_attr( MSRWA_UI::verdict_tone( $verdict ) ) . '"></i>' . esc_html( MSRWA_UI::verdict_word( $verdict ) ) . '</li>';
			}
			echo '</ul>';
			foreach ( $verdicts['targets'] as $target => $counts ) {
				$all = max( 1, array_sum( $counts ) );
				echo '<div class="ms-split-row"><span>' . esc_html( $names[ $target ] ?? $target ) . '</span><div class="ms-split-bar ms-verdict-bar">';
				foreach ( array( 'good', 'reservations', 'bad' ) as $verdict ) {
					if ( empty( $counts[ $verdict ] ) ) { continue; }
					$share = 100 * (int) $counts[ $verdict ] / $all;
					$word = MSRWA_UI::verdict_word( $verdict );
					echo '<div class="ms-verdict-' . esc_attr( MSRWA_UI::verdict_tone( $verdict ) ) . '" style="--w:' . esc_attr( number_format( $share, 2, '.', '' ) ) . '%" tabindex="0"'
						. ' data-tip-title="' . esc_attr( $names[ $target ] ?? $target ) . '" data-tip="' . esc_attr( $word . ' : ' . (int) $counts[ $verdict ] . ' · ' . round( $share ) . ' %' ) . '"'
						. ' aria-label="' . esc_attr( $word . ' ' . (int) $counts[ $verdict ] ) . '">'
						. ( $share >= 6 ? '<em>' . esc_html( (string) (int) $counts[ $verdict ] ) . '</em>' : '' ) . '</div>';
				}
				echo '</div><strong>' . esc_html( round( 100 * (int) ( $counts['good'] ?? 0 ) / $all ) . ' %' ) . '</strong></div>';
			}
			echo '</div>';
			echo '<details class="ms-fold ms-fold-inline"><summary>' . esc_html__( 'Voir les chiffres', 'ms-recipes-writer-ai' ) . '</summary>';
			echo '<table class="ms-table" style="margin-top:8px"><thead><tr><th>' . esc_html__( 'Artefact', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Verdicts', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
			$targets = array(
				'article' => __( 'article', 'ms-recipes-writer-ai' ), 'featured_image' => __( 'image à la une', 'ms-recipes-writer-ai' ),
				'facebook_image' => __( 'collage', 'ms-recipes-writer-ai' ), 'consistency' => __( 'cohérence', 'ms-recipes-writer-ai' ),
			);
			foreach ( $verdicts['targets'] as $target => $counts ) {
				echo '<tr><td>' . esc_html( $targets[ $target ] ?? $target ) . '</td><td>';
				// Good first, then reservations, then refusals: read left to right.
				uksort( $counts, static function ( $a, $b ) { $order = array( 'good' => 0, 'reservations' => 1, 'bad' => 2 ); return ( $order[ $a ] ?? 3 ) <=> ( $order[ $b ] ?? 3 ); } );
				foreach ( $counts as $verdict => $count ) { echo '<span class="ms-state ms-state-' . esc_attr( MSRWA_UI::verdict_tone( (string) $verdict ) ) . '">' . esc_html( MSRWA_UI::verdict_word( (string) $verdict ) . ' × ' . $count ) . '</span> '; }
				echo '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		echo '</section>';
	}

	private static function steps( $days ) {
		$rows = MSRWA_Ledger::by_step( $days );
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Par étape', 'ms-recipes-writer-ai' ) . '</h2>';
		if ( ! $rows ) { MSRWA_UI::nothing( __( 'Rien à analyser', 'ms-recipes-writer-ai' ), __( 'Aucune recette n’a encore tourné sur cette période.', 'ms-recipes-writer-ai' ) ); echo '</section>'; return; }
		echo MSRWA_UI::scroll( __( 'Par étape', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
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
		$all = max( 0.000001, array_sum( array_map( static function ( $row ) { return (float) $row['spend']; }, $rows ) ) );
		foreach ( $rows as $row ) {
			$share = (int) round( 100 * (float) $row['spend'] / $all );
			echo '<tr><td><strong>' . esc_html( MSRWA_UI::step_name( (string) $row['step'] ) ) . '</strong> <span class="ms-key">' . esc_html( $row['step'] ) . '</span></td>'
				. '<td class="ms-num">' . esc_html( $row['runs'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::seconds( $row['seconds'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( null === $row['cost'] ? '—' : MSRWA_I18N::money( $row['cost'] ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( MSRWA_I18N::money( $row['spend'], 2 ) ) . ( $share > 0 ? '<span class="ms-costbar" style="--share:' . (int) $share . '%" title="' . esc_attr( $share . ' %' ) . '"></span>' : '' ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( round( (float) $row['input_tokens'] ) ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( round( (float) $row['output_tokens'] ) ) ) . '</td>'
				. '<td class="ms-num">' . esc_html( null === $row['score'] ? '—' : round( 100 * (float) $row['score'] ) . ' %' ) . '</td>'
				. '<td class="ms-num">' . esc_html( $row['failures'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function models( $days, $spent = 0.0 ) {
		$rows = MSRWA_Ledger::by_model( $days );
		$itemised = 0.0;
		if ( ! $rows ) { return; }
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Par modèle', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Ce qui a réellement été facturé, et quelle part de l’entrée le fournisseur a servie depuis son cache.', 'ms-recipes-writer-ai' ) . '</p>';
		echo MSRWA_UI::scroll( __( 'Par modèle', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
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
			$itemised += (float) $row['spend'];
		}
		// What no call row carries — the research reading the photographs it
		// cites, a lot pairing its photographs — so the table adds up to the
		// spend above instead of falling a little short of it.
		$rest = $spent - $itemised;
		if ( $rest > 0.005 ) {
			echo '<tr class="ms-row-quiet"><td colspan="5"><small>' . esc_html__( 'Hors appels détaillés : lecture des photographies citées par la recherche, appariement des photographies des lots', 'ms-recipes-writer-ai' ) . '</small></td><td class="ms-num">' . esc_html( MSRWA_I18N::money( $rest, 2 ) ) . '</td><td></td></tr>';
		}
		echo '<tr class="ms-row-total"><td colspan="5">' . esc_html__( 'Total', 'ms-recipes-writer-ai' ) . '</td><td class="ms-num">' . esc_html( MSRWA_I18N::money( max( $spent, $itemised ), 2 ) ) . '</td><td></td></tr>';
		echo '</tbody></table></div></section>';
	}

	private static function checks( $days ) {
		$rows = MSRWA_Ledger::checks( $days );
		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Contrôles qui échouent', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Un contrôle qui échoue une fois sur six est du bruit ; six fois sur six, c’est un contrat que le prompt ne tient pas.', 'ms-recipes-writer-ai' ) . '</p>';
		if ( ! $rows ) { MSRWA_UI::nothing( __( 'Aucun contrôle en échec', 'ms-recipes-writer-ai' ), __( 'Toutes les étapes ont satisfait leurs contrôles sur cette période.', 'ms-recipes-writer-ai' ) ); echo '</section>'; return; }
		echo MSRWA_UI::scroll( __( 'Contrôles qui échouent', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
			. '<th>' . esc_html__( 'Étape', 'ms-recipes-writer-ai' ) . '</th><th>' . esc_html__( 'Contrôle', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Échecs', 'ms-recipes-writer-ai' ) . '</th><th class="ms-num">' . esc_html__( 'Sur', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Taux', 'ms-recipes-writer-ai' ) . '</th></tr></thead><tbody>';
		// The ten that fail most; the long tail of one-offs is folded below.
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( 10 === $index ) {
				echo '</tbody></table></div><details class="ms-fold ms-fold-inline"><summary>' . esc_html( sprintf(
					/* translators: %d is a number of checks. */
					_n( '%d autre contrôle, en échec plus rarement', '%d autres contrôles, en échec plus rarement', count( $rows ) - 10, 'ms-recipes-writer-ai' ), count( $rows ) - 10 ) ) . '</summary><div class="ms-scroll"><table class="ms-table"><tbody>';
			}
			$rate = (int) round( 100 * $row['failed'] / max( 1, $row['seen'] ) );
			echo '<tr><td>' . esc_html( MSRWA_UI::step_name( (string) $row['step'] ) ) . '</td><td class="ms-key">' . esc_html( $row['check'] ) . '</td>'
				. '<td class="ms-num">' . esc_html( $row['failed'] ) . '</td><td class="ms-num">' . esc_html( $row['seen'] ) . '</td>'
				. '<td class="ms-num"><span class="ms-state ms-state-' . ( $rate >= 50 ? 'stop' : ( $rate >= 20 ? 'warn' : '' ) ) . '">' . esc_html( $rate . ' %' ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>' . ( count( $rows ) > 10 ? '</details>' : '' ) . '</section>';
	}

	/** Spend per day, drawn as bars rather than a chart library nobody can audit. */
	private static function days() {
		$rows = MSRWA_Ledger::by_day( 14 );
		if ( ! $rows ) { return; }

		// Every day in the window, including the ones nothing ran on. Drawing
		// only the days that have rows puts two distant days side by side and
		// reads as a run of activity that never happened.
		$seen = array();
		foreach ( $rows as $row ) { $seen[ (string) $row['day'] ] = $row; }
		$rows = array();
		$peak = 0.0;
		for ( $back = 13; $back >= 0; $back-- ) {
			$day = gmdate( 'Y-m-d', time() - $back * DAY_IN_SECONDS );
			$rows[] = $seen[ $day ] ?? array( 'day' => $day, 'runs' => 0, 'spend' => 0, 'seconds' => 0 );
			$peak = max( $peak, (float) ( $seen[ $day ]['spend'] ?? 0 ) );
		}

		// A column per day, the daily ceiling as a line across them when one is
		// set: how close each day came is the question this answers.
		$ceiling = (float) MSRWA_Budget::ceilings()['daily'];
		$scale = max( $peak, $ceiling, 0.0001 );
		$spent = array_sum( array_map( static function ( $row ) { return (float) $row['spend']; }, $rows ) );
		$recipes = array_sum( array_map( static function ( $row ) { return (int) $row['runs']; }, $rows ) );
		$last = count( $rows ) - 1;

		echo '<section class="ms-card"><h2>' . esc_html__( 'Sur quatorze jours', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			/* translators: 1: an amount of money, 2: a number of recipes, 3: an amount of money. */
			__( '%1$s dépensés pour %2$d recette(s), soit %3$s par jour en moyenne. Chaque somme compte le jour où elle a été dépensée.', 'ms-recipes-writer-ai' ),
			MSRWA_I18N::money( $spent, 2 ), $recipes, MSRWA_I18N::money( $spent / 14, 2 )
		) ) . '</p>';
		echo '<div class="ms-columns" role="img" aria-label="' . esc_attr__( 'Dépense par jour sur quatorze jours', 'ms-recipes-writer-ai' ) . '">';
		if ( $ceiling > 0 ) {
			echo '<div class="ms-columns-limit" style="--at:' . esc_attr( number_format( 100 * $ceiling / $scale, 2, '.', '' ) ) . '%"><span>'
				/* translators: %s is an amount of money. */
				. esc_html( sprintf( __( 'plafond du jour %s', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( $ceiling, 2 ) ) ) . '</span></div>';
		}
		foreach ( $rows as $index => $row ) {
			$value = (float) $row['spend'];
			$time = strtotime( $row['day'] . ' 12:00:00' );
			$long = date_i18n( get_option( 'date_format' ), $time );
			/* translators: %d is a count of recipes. */
			$count = sprintf( _n( '%d recette', '%d recettes', (int) $row['runs'], 'ms-recipes-writer-ai' ), (int) $row['runs'] );
			// Labels only where they carry weight: the busiest day, today, and
			// any day over the ceiling.
			$label = $value > 0 && ( $value === $peak || $index === $last || ( $ceiling > 0 && $value > $ceiling ) ) ? MSRWA_I18N::money( $value, 2 ) : '';
			echo '<div class="ms-column' . ( $ceiling > 0 && $value > $ceiling ? ' ms-column-over' : '' ) . ( $index === $last ? ' ms-column-today' : '' ) . '" tabindex="0"'
				. ' data-tip-title="' . esc_attr( $long ) . '" data-tip="' . esc_attr( MSRWA_I18N::money( $value, 2 ) . ' · ' . $count ) . '" aria-label="' . esc_attr( $long . ' : ' . MSRWA_I18N::money( $value, 2 ) . ', ' . $count ) . '">'
				. '<span class="ms-column-value">' . esc_html( $label ) . '</span>'
				. '<i style="--h:' . esc_attr( number_format( $value > 0 ? max( 1.5, 100 * $value / $scale ) : 0, 2, '.', '' ) ) . '%"></i>'
				. '<small>' . esc_html( date_i18n( 'j', $time ) ) . '</small></div>';
		}
		echo '</div></section>';
	}
}
