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
		// Money is an operator's concern: a writer's pass neither shows the
		// spend nor asks the database for it.
		$money = MSRWA_Rights::may_see_money();
		$today = $money ? MSRWA_Ledger::spend( 1 ) : null;
		$month = $money ? MSRWA_Ledger::spend( 30 ) : null;
		$moving = MSRWA_Ledger::runs( array( 'status' => 'moving', 'per_page' => 12 ) );
		$attention = MSRWA_Ledger::runs( array( 'status' => 'attention', 'per_page' => 8 ) );

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Le pass', 'ms-recipes-writer-ai' ),
			__( 'Ce qui est en cours, ce qui attend une relecture, et ce qui s’est arrêté.', 'ms-recipes-writer-ai' ),
			$money ? array(
				__( 'aujourd’hui', 'ms-recipes-writer-ai' ) => MSRWA_I18N::money( $today['spend_usd'], 2 ),
				__( '30 jours', 'ms-recipes-writer-ai' ) => MSRWA_I18N::money( $month['spend_usd'], 2 ),
			) : array(),
			'<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>'
		);

		self::warnings();

		// Somebody who has never sent a recipe learns nothing from four zeros.
		// They get the three things that will happen instead, and the button.
		if ( ! $moving['total'] && ! $attention['total'] && ! MSRWA_Ledger::runs( array( 'per_page' => 1 ) )['total'] ) {
			self::queue();
			self::welcome();
			echo '</div>';
			return;
		}

		$figures = array(
			array( 'label' => __( 'en cours', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['moving'] ) ),
			array( 'label' => __( 'à relire', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['to_read'] ), 'note' => __( 'brouillons prêts', 'ms-recipes-writer-ai' ) ),
			array( 'label' => __( 'à corriger', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['reserved'] ) ),
			array( 'label' => __( 'échecs', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $now['failed'] ) ),
		);
		if ( $money ) {
			$figures[] = array(
				'label' => __( 'coût moyen', 'ms-recipes-writer-ai' ),
				'value' => MSRWA_I18N::money( $month['average_usd'] ),
				'note' => $month['unpriced_steps']
					/* translators: %d is a number of steps. */
					? sprintf( __( '%d étape(s) sans tarif publié', 'ms-recipes-writer-ai' ), $month['unpriced_steps'] )
					: __( 'par recette, sur 30 jours', 'ms-recipes-writer-ai' ),
			);
		}
		MSRWA_UI::figures( $figures );
		if ( $money ) { self::ceilings(); }

		self::queue();
		self::waiting();
		self::rail( __( 'En cours', 'ms-recipes-writer-ai' ), $moving['runs'], __( 'Rien ne tourne.', 'ms-recipes-writer-ai' ), __( 'Déposez des recettes et des photographies pour lancer un lot.', 'ms-recipes-writer-ai' ), true );

		if ( $attention['runs'] ) {
			self::rail( __( 'Demande une décision', 'ms-recipes-writer-ai' ), $attention['runs'], '', '', false );
		}

		echo '</div>';
	}

	/** The first visit: what a lot is, in the order it happens. */
	private static function welcome() {
		$steps = array(
			array( __( 'Déposez un lot', 'ms-recipes-writer-ai' ), __( 'Collez une ou plusieurs recettes, séparées par une ligne de tirets, et ajoutez leurs photographies depuis votre ordinateur, sans dire laquelle va avec quoi.', 'ms-recipes-writer-ai' ) ),
			array( __( 'Vérifiez l’appariement', 'ms-recipes-writer-ai' ), __( 'Chaque photographie est rapprochée de sa recette. Corrigez si besoin, puis lancez : rien n’est écrit avant.', 'ms-recipes-writer-ai' ) ),
			array( __( 'Relisez les brouillons', 'ms-recipes-writer-ai' ), __( 'Chaque recette devient un brouillon WordPress avec son article, ses images et les remarques du contrôle final. Rien n’est jamais publié sans vous.', 'ms-recipes-writer-ai' ) ),
		);
		?>
		<section class="ms-card ms-welcome">
			<h2><?php esc_html_e( 'Comment ça marche', 'ms-recipes-writer-ai' ); ?></h2>
			<ol class="ms-welcome-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li><strong><?php echo esc_html( $step[0] ); ?></strong><span><?php echo esc_html( $step[1] ); ?></span></li>
				<?php endforeach; ?>
			</ol>
			<p><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ); ?>"><?php esc_html_e( 'Déposer mon premier lot', 'ms-recipes-writer-ai' ); ?></a></p>
		</section>
		<?php
	}

	/**
	 * The queue, and the one control that matters when something is wrong.
	 *
	 * A hold stops new waves without losing anything. It is here, on the first
	 * screen, because the moment it is needed is the moment several recipes are
	 * spending money and nobody yet knows why.
	 */
	private static function queue() {
		$state = MSRWA_Queue::state();
		if ( ! MSRWA_Rights::may_manage() && ! $state['held'] ) { return; }

		// A ceiling that has been reached also makes the queue sit still, and
		// blaming cron for it would send somebody to the wrong place.
		if ( MSRWA_Queue::stalled() && '' === MSRWA_Budget::refusal() ) {
			MSRWA_UI::note( esc_html( sprintf(
				/* translators: %s is a duration, e.g. "20 minutes". */
				__( 'La file n’avance pas : la plus ancienne recette attend depuis %s et rien ne tourne. Sur un site peu visité, c’est le cron.', 'ms-recipes-writer-ai' ),
				human_time_diff( time() - $state['waiting_seconds'], time() )
			) ), 'warn' );
		}

		echo '<section class="ms-card" id="ms-queue"><h2>' . esc_html__( 'La file', 'ms-recipes-writer-ai' ) . '</h2>';
		if ( $state['held'] ) {
			MSRWA_UI::note( esc_html__( 'La file est suspendue. Rien de nouveau ne part ; ce qui est déjà commencé garde tout ce qui a été fait et reprendra à l’endroit exact.', 'ms-recipes-writer-ai' ), 'warn' );
		}

		echo '<div class="ms-row">';
		echo '<span class="ms-muted">' . esc_html( sprintf(
			/* translators: 1: recipes waiting, 2: recipes working. */
			__( '%1$d en attente, %2$d en cours', 'ms-recipes-writer-ai' ),
			$state['waiting'], $state['working']
		) ) . '</span>';

		if ( MSRWA_Rights::may_manage() ) {
			echo '<span id="ms-queue-status" class="ms-muted" aria-live="polite"></span>';
			echo '<button class="button" id="ms-queue-toggle" data-held="' . ( $state['held'] ? '1' : '0' ) . '">'
				. esc_html( $state['held'] ? __( 'Reprendre la file', 'ms-recipes-writer-ai' ) : __( 'Suspendre la file', 'ms-recipes-writer-ai' ) )
				. '</button>';
		}
		echo '</div></section>';
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

		$refusal = MSRWA_Budget::refusal();
		if ( '' !== $refusal ) { MSRWA_UI::note( esc_html( $refusal ), 'stop' ); }

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && MSRWA_Rights::may_manage() ) {
			MSRWA_UI::note( __( 'DISABLE_WP_CRON est actif. Les lots n’avanceront que si un cron serveur appelle wp-cron.php ; sans cela ils resteront en attente sans rien signaler.', 'ms-recipes-writer-ai' ), 'warn' );
		}
	}

	/**
	 * Lots that have not left yet.
	 *
	 * The rails below show work the engine is doing; this shows work it has not
	 * been asked to do. A lot paired last Thursday and never sent used to leave
	 * the screen with the person who made it, and there was nowhere at all to
	 * see what was scheduled for tonight.
	 */
	private static function waiting() {
		$batches = MSRWA_Batch::waiting( 10 );
		if ( ! $batches ) { return; }

		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Pas encore parti', 'ms-recipes-writer-ai' ) . '</h2>';
		echo MSRWA_UI::scroll( __( 'Pas encore parti', 'ms-recipes-writer-ai' ) ) . '<table class="ms-table"><thead><tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- scroll() escapes its own.
			. '<th>' . esc_html__( 'Lot', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th class="ms-num">' . esc_html__( 'Recettes', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th>' . esc_html__( 'Départ', 'ms-recipes-writer-ai' ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( $batches as $batch ) {
			$url = admin_url( 'admin.php?page=msrwa-batch&batch_id=' . (int) $batch['id'] );
			echo '<tr><td><strong>#' . esc_html( $batch['id'] ) . '</strong>'
				. ( '' !== (string) $batch['label'] ? ' ' . esc_html( MSRWA_Batch::title( $batch ) ) : '' )
				. '<small>' . esc_html( (string) MSRWA_Profile::get( (string) $batch['profile'] )['label'] ) . '</small></td>'
				. '<td class="ms-num">' . esc_html( number_format_i18n( (int) $batch['recipes'] ) ) . '</td>'
				. '<td>' . ( empty( $batch['dispatch_at'] )
					? '<span class="ms-state ms-state-warn">' . esc_html__( 'attend votre confirmation', 'ms-recipes-writer-ai' ) . '</span>'
					: esc_html( MSRWA_I18N::when( (string) $batch['dispatch_at'] ) ) )
				. '</td>'
				. '<td class="ms-num"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Ouvrir', 'ms-recipes-writer-ai' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function rail( $title, array $runs, $empty_title, $empty_text, $live ) {
		echo '<section class="ms-card ms-card-flush"' . ( $live ? ' id="ms-live-rail"' : '' ) . '><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $runs ) {
			MSRWA_UI::nothing( $empty_title, $empty_text, '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=msrwa-compose' ) ) . '">' . esc_html__( 'Nouveau lot', 'ms-recipes-writer-ai' ) . '</a>' );
			echo '</section>';
			return;
		}
		MSRWA_UI::run_table( $runs );
		echo '</section>';
	}

	/**
	 * How full each site ceiling is, as a gauge: spent against the ceiling,
	 * green, then orange past four fifths, red once reached. Only the ceilings
	 * the site set are drawn.
	 */
	private static function ceilings() {
		$state = MSRWA_Budget::state();
		$names = array( 'daily' => __( 'aujourd’hui', 'ms-recipes-writer-ai' ), 'monthly' => __( '30 jours', 'ms-recipes-writer-ai' ) );
		$rows = '';
		foreach ( $names as $key => $name ) {
			$gauge = (array) ( $state[ $key ] ?? array() );
			if ( empty( $gauge['ceiling'] ) ) { continue; }
			$share = (int) $gauge['share'];
			$tone = $gauge['exceeded'] ? 'stop' : ( $share >= 80 ? 'warn' : 'good' );
			$rows .= '<div class="ms-split-row"><span>' . esc_html( $name ) . '</span>'
				. '<div class="ms-gauge" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $share ) . '" aria-label="' . esc_attr( $name ) . '"><i class="ms-gauge-' . esc_attr( $tone ) . '" style="--w:' . esc_attr( (string) max( 1, $share ) ) . '%"></i></div>'
				. '<strong>' . esc_html( sprintf(
					/* translators: 1: spent, 2: the ceiling. */
					__( '%1$s sur %2$s', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( $gauge['spent'], 2 ), MSRWA_I18N::money( $gauge['ceiling'], 2 )
				) ) . '</strong></div>';
		}
		if ( '' === $rows ) { return; }
		echo '<section class="ms-card"><h2>' . esc_html__( 'Plafonds du site', 'ms-recipes-writer-ai' ) . '</h2>'
			. '<p>' . esc_html__( 'Ce qui a été dépensé face à chaque plafond, lectures de photographies et nouveaux dessins compris. Un lot qui le dépasserait est refusé avant de partir.', 'ms-recipes-writer-ai' ) . '</p>'
			. '<div class="ms-split ms-gauges">' . $rows . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}
}
