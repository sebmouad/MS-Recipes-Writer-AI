<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the site is allowed to spend, and whether it already has.
 *
 * Three ceilings, each answering a different fear. The per-recipe ceiling stops
 * one runaway article. The daily ceiling stops a bad afternoon. The monthly
 * ceiling stops a bad month nobody noticed until the invoice.
 *
 * They are checked in two places, and both matter. Before a lot is dispatched,
 * because refusing to start is free. And before every wave of every run, because
 * a lot that was affordable when it started can stop being affordable while it
 * is running — that is exactly what a runaway looks like.
 *
 * A ceiling of zero is no ceiling. Nothing here invents a limit a site did not
 * ask for.
 */
final class MSRWA_Budget {

	const DAILY = 'daily_budget_usd';
	const MONTHLY = 'monthly_budget_usd';

	public static function ceilings() {
		$settings = MSRWA_Settings::get();
		return array(
			'daily' => max( 0.0, (float) ( $settings[ self::DAILY ] ?? 0 ) ),
			'monthly' => max( 0.0, (float) ( $settings[ self::MONTHLY ] ?? 0 ) ),
		);
	}

	/**
	 * What the whole site has spent, not what one writer has.
	 *
	 * Deliberately unscoped: a budget is the site's, and a writer who could
	 * only see their own share of it would never understand why their lot was
	 * refused.
	 */
	public static function spent() {
		// Every amount on the day it was spent, lot pairings and redraws
		// included, and still counted after its lot is deleted.
		return array(
			'daily' => MSRWA_Spend::window( 1 )['spend_usd'],
			'monthly' => MSRWA_Spend::window( 30 )['spend_usd'],
		);
	}

	/** Where each ceiling stands: spent, remaining, and how full it is. */
	public static function state() {
		$ceilings = self::ceilings();
		$spent = self::spent();
		$out = array();
		foreach ( $ceilings as $name => $ceiling ) {
			$out[ $name ] = array(
				'ceiling' => $ceiling,
				'spent' => $spent[ $name ],
				'left' => $ceiling > 0 ? max( 0.0, round( $ceiling - $spent[ $name ], 6 ) ) : null,
				'share' => $ceiling > 0 ? min( 100, (int) round( 100 * $spent[ $name ] / $ceiling ) ) : 0,
				'exceeded' => $ceiling > 0 && $spent[ $name ] >= $ceiling,
			);
		}
		return $out;
	}

	/**
	 * Why nothing more may be spent, or '' when it may.
	 *
	 * `$about_to_spend` is what the caller is about to commit. Passing it is
	 * what turns this from a report into a gate: a lot that would take the site
	 * over its month is refused before it starts, not after.
	 */
	public static function refusal( $about_to_spend = 0.0 ) {
		$about_to_spend = max( 0.0, (float) $about_to_spend );
		foreach ( self::state() as $name => $budget ) {
			if ( $budget['ceiling'] <= 0 ) { continue; }
			if ( $budget['spent'] >= $budget['ceiling'] ) {
				return 'daily' === $name
					? sprintf(
						/* translators: 1: amount spent, 2: the ceiling. */
						__( 'Le plafond du jour est atteint : %1$s dépensés sur %2$s. Rien de plus ne sera lancé avant demain.', 'ms-recipes-writer-ai' ),
						MSRWA_I18N::money( $budget['spent'], 2 ), MSRWA_I18N::money( $budget['ceiling'], 2 )
					)
					: sprintf(
						/* translators: 1: amount spent, 2: the ceiling. */
						__( 'Le plafond des trente derniers jours est atteint : %1$s dépensés sur %2$s.', 'ms-recipes-writer-ai' ),
						MSRWA_I18N::money( $budget['spent'], 2 ), MSRWA_I18N::money( $budget['ceiling'], 2 )
					);
			}
			if ( $about_to_spend > 0 && $budget['spent'] + $about_to_spend > $budget['ceiling'] ) {
				return sprintf(
					/* translators: 1: what the lot would cost, 2: what is left. */
					__( 'Ce lot est estimé à %1$s et il ne reste que %2$s sous le plafond. Réduisez le lot, ou relevez le plafond dans les réglages.', 'ms-recipes-writer-ai' ),
					MSRWA_I18N::money( $about_to_spend, 2 ),
					MSRWA_I18N::money( (float) $budget['left'], 2 )
				);
			}
		}
		return '';
	}

}
