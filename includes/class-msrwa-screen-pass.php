<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The pass: what is cooking, what is waiting to be read, what went wrong.
 *
 * The first screen anyone opens, and the only one designed to be read at a
 * glance rather than studied. What needs a person comes first; the ledger comes
 * after, because money spent is never as urgent as work stuck.
 */
final class MSRWA_Screen_Pass {

	public static function render() {
		if ( ! MSRWA_Rights::may_write() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$now = MSRWA_Ledger::now();
		$today = MSRWA_Ledger::spend( 1 );
		$month = MSRWA_Ledger::spend( 30 );
		$moving = MSRWA_Ledger::runs( array( 'status' => 'moving', 'per_page' => 12 ) );
		$attention = MSRWA_Ledger::runs( array( 'status' => 'attention', 'per_page' => 8 ) );

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Le pass', 'ms-recipes-writer-ai' ),
			__( 'Ce qui est en cours, ce qui attend une relecture, et ce qui s’est arrêté.', 'ms-recipes-writer-ai' ),
			array(
				__( 'aujourd’hui', 'ms-recipes-writer-ai' ) => MSRWA_I18N::money( $today['spend_usd'], 2 ),
				__( '30 jours', 'ms-recipes-writer-ai' ) => MSRWA_I18N::money( $month['spend_usd'], 2 ),
			),
			'<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>'
		);

		self::warnings();

		MSRWA_UI::figures( array(
			array( 'label' => __( 'en cours', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['moving'] ) ),
			array( 'label' => __( 'à relire', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['to_read'] ), 'note' => __( 'brouillons prêts', 'ms-recipes-writer-ai' ) ),
			array( 'label' => __( 'réserves du juge', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['reserved'] ) ),
			array( 'label' => __( 'échecs', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['failed'] ) ),
			array(
				'label' => __( 'coût moyen', 'ms-recipes-writer-ai' ),
				'value' => MSRWA_I18N::money( $month['average_usd'] ),
				'note' => $month['unpriced_steps']
					/* translators: %d is a number of steps. */
					? sprintf( __( '%d étape(s) sans tarif publié', 'ms-recipes-writer-ai' ), $month['unpriced_steps'] )
					: __( 'par recette, sur 30 jours', 'ms-recipes-writer-ai' ),
			),
		) );

		self::rail( __( 'En cours', 'ms-recipes-writer-ai' ), $moving['runs'], __( 'Rien ne tourne.', 'ms-recipes-writer-ai' ), __( 'Déposez des recettes et des photographies pour lancer un lot.', 'ms-recipes-writer-ai' ), true );

		if ( $attention['runs'] ) {
			self::rail( __( 'Demande une décision', 'ms-recipes-writer-ai' ), $attention['runs'], '', '', false );
		}

		echo '</div>';
	}

	/** The two ways a run sits still without saying anything. */
	private static function warnings() {
		if ( ! MSRWA_Settings::configured_providers() ) {
			MSRWA_UI::note( sprintf(
				/* translators: %s is a link to the settings screen. */
				__( 'Aucune clé d’API n’est enregistrée, donc rien ne peut être généré. %s', 'ms-recipes-writer-ai' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=msrwa-settings' ) ) . '">' . esc_html__( 'Ouvrir les réglages', 'ms-recipes-writer-ai' ) . '</a>'
			), 'stop' );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && MSRWA_Rights::may_manage() ) {
			MSRWA_UI::note( __( 'DISABLE_WP_CRON est actif. Les lots n’avanceront que si un cron serveur appelle wp-cron.php ; sans cela ils resteront en attente sans rien signaler.', 'ms-recipes-writer-ai' ), 'warn' );
		}
	}

	private static function rail( $title, array $runs, $empty_title, $empty_text, $live ) {
		echo '<section class="ms-card ms-card-flush"' . ( $live ? ' id="ms-live-rail"' : '' ) . '><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $runs ) {
			MSRWA_UI::nothing( $empty_title, $empty_text, '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>' );
			echo '</section>';
			return;
		}
		echo '<div class="ms-rail">';
		foreach ( $runs as $run ) {
			MSRWA_UI::ticket( $run, admin_url( 'admin.php?page=msrwa-run&run_id=' . (int) $run['id'] ) );
		}
		echo '</div></section>';
	}
}
