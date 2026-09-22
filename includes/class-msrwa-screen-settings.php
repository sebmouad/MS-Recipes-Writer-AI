<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Credentials, defaults, and whether the machinery that carries the work is
 * actually running.
 *
 * Health sits on the same screen as the keys on purpose: the two ways a lot
 * sits still saying nothing are a missing key and a cron that never fires, and
 * an operator should find both in the same place.
 */
final class MSRWA_Screen_Settings {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		$configured = MSRWA_Settings::configured_providers();

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Réglages', 'ms-recipes-writer-ai' ),
			__( 'Les clés, et l’état de ce qui fait avancer le travail.', 'ms-recipes-writer-ai' )
		);

		if ( isset( $_GET['saved'] ) ) { MSRWA_UI::note( esc_html__( 'Enregistré.', 'ms-recipes-writer-ai' ) ); }
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="msrwa_save_settings">
			<?php wp_nonce_field( 'msrwa_save_settings' ); ?>
			<section class="ms-card">
				<h2><?php esc_html_e( 'Clés d’API', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Chiffrées à l’enregistrement et jamais réaffichées. Un champ laissé vide conserve la clé enregistrée ; il ne l’efface pas.', 'ms-recipes-writer-ai' ); ?></p>
				<?php
				foreach ( array(
					'openai_key' => array( 'OpenAI', 'openai' ),
					'gemini_key' => array( 'Gemini', 'gemini' ),
					'claude_key' => array( 'Claude', 'claude' ),
				) as $field => $provider ) :
					$stored = in_array( $provider[1], $configured, true );
					?>
					<p>
						<label for="ms-<?php echo esc_attr( $field ); ?>"><strong><?php echo esc_html( $provider[0] ); ?></strong></label>
						<?php if ( $stored ) : ?><span class="ms-state ms-state-good"><?php esc_html_e( 'clé enregistrée', 'ms-recipes-writer-ai' ); ?></span><?php endif; ?>
						<br>
						<input type="password" id="ms-<?php echo esc_attr( $field ); ?>" name="msrwa_settings[<?php echo esc_attr( $field ); ?>]" value="" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( $stored ? __( 'inchangée', 'ms-recipes-writer-ai' ) : __( 'aucune clé', 'ms-recipes-writer-ai' ) ); ?>">
					</p>
				<?php endforeach; ?>
			</section>
			<section class="ms-card">
				<h2><?php esc_html_e( 'Plafonds de dépense', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Trois plafonds, pour trois craintes différentes. Celui par recette arrête un article emballé ; celui du jour arrête un mauvais après-midi ; celui du mois arrête un mauvais mois que personne n’a vu venir. Ils sont vérifiés avant qu’un lot parte et avant chaque vague de chaque recette, parce qu’un lot qui tenait au départ peut cesser de tenir en cours de route. À zéro, aucun plafond.', 'ms-recipes-writer-ai' ); ?></p>
				<?php $settings = MSRWA_Settings::get(); ?>
				<p>
					<label for="ms-per-recipe"><strong><?php esc_html_e( 'Par recette', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-per-recipe" name="msrwa_settings[per_recipe_budget_usd]" value="<?php echo esc_attr( (float) ( $settings['per_recipe_budget_usd'] ?? 0.20 ) ); ?>" step="0.01" min="0" class="small-text ms-num"> $
					<br><small class="ms-muted"><?php esc_html_e( 'La valeur proposée sur un nouveau lot, et celle qui s’applique à un lot déposé par quelqu’un qui ne voit pas les montants.', 'ms-recipes-writer-ai' ); ?></small>
				</p>
				<p>
					<label for="ms-daily"><strong><?php esc_html_e( 'Par jour', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-daily" name="msrwa_settings[daily_budget_usd]" value="<?php echo esc_attr( (float) ( $settings['daily_budget_usd'] ?? 0 ) ); ?>" step="0.5" min="0" class="small-text ms-num"> $
				</p>
				<p>
					<label for="ms-monthly"><strong><?php esc_html_e( 'Sur trente jours', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-monthly" name="msrwa_settings[monthly_budget_usd]" value="<?php echo esc_attr( (float) ( $settings['monthly_budget_usd'] ?? 0 ) ); ?>" step="1" min="0" class="small-text ms-num"> $
				</p>
			</section>

			<div class="ms-card ms-save"><?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ), 'primary', 'submit', false ); ?></div>
		</form>

		<?php self::budget(); ?>
		<?php
		self::health();
		echo '</div>';
	}

	/** Where each ceiling stands right now. */
	private static function budget() {
		$state = MSRWA_Budget::state();
		if ( ! $state['daily']['ceiling'] && ! $state['monthly']['ceiling'] ) { return; }

		echo '<section class="ms-card"><h2>' . esc_html__( 'Où en sont les plafonds', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'La dépense du site entier, pas celle d’un rédacteur : un plafond appartient au site, et quelqu’un qui n’en verrait que sa part ne comprendrait jamais pourquoi son lot a été refusé.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<table class="ms-table ms-share"><tbody>';
		foreach ( array( 'daily' => __( 'aujourd’hui', 'ms-recipes-writer-ai' ), 'monthly' => __( 'trente jours', 'ms-recipes-writer-ai' ) ) as $name => $label ) {
			$budget = $state[ $name ];
			if ( ! $budget['ceiling'] ) { continue; }
			echo '<tr><td class="ms-share-label">' . esc_html( $label ) . '</td>'
				. '<td class="ms-bar"><span class="ms-progress">'
				. '<i style="inline-size:' . (int) $budget['share'] . '%' . ( $budget['exceeded'] ? ';background:var(--ms-stop)' : '' ) . '"></i></span></td>'
				. '<td class="ms-num">'
				. esc_html( sprintf(
					/* translators: 1: amount spent, 2: the ceiling. */
					__( '%1$s sur %2$s', 'ms-recipes-writer-ai' ),
					MSRWA_I18N::money( $budget['spent'], 2 ),
					MSRWA_I18N::money( $budget['ceiling'], 2 )
				) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$refusal = MSRWA_Budget::refusal();
		if ( '' !== $refusal ) { MSRWA_UI::note( esc_html( $refusal ), 'stop' ); }
		echo '</section>';
	}

	/**
	 * Whether the work can actually move.
	 *
	 * Every line here answers a question an operator asks when nothing is
	 * happening, and says what to do rather than only what is true.
	 */
	private static function health() {
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		$pending = MSRWA_Ledger::now();
		$uploads = wp_upload_dir();
		$writable = wp_is_writable( $uploads['basedir'] );
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		echo '<section class="ms-card"><h2>' . esc_html__( 'État de la machinerie', 'ms-recipes-writer-ai' ) . '</h2>';

		if ( $cron_off ) {
			MSRWA_UI::note( esc_html__( 'DISABLE_WP_CRON est actif. C’est la configuration recommandée, mais elle suppose qu’un cron serveur appelle wp-cron.php : sans lui, les recettes resteront en attente indéfiniment.', 'ms-recipes-writer-ai' ), 'warn' );
		}
		if ( ! $writable ) {
			MSRWA_UI::note( esc_html__( 'Le dossier des téléversements n’est pas accessible en écriture : aucune image générée ne pourra être enregistrée.', 'ms-recipes-writer-ai' ), 'stop' );
		}

		MSRWA_UI::figures( array(
			array( 'label' => __( 'en attente', 'ms-recipes-writer-ai' ), 'value' => number_format_i18n( $pending['moving'] ) ),
			array(
				'label' => __( 'cron', 'ms-recipes-writer-ai' ),
				'value' => $cron_off ? __( 'serveur', 'ms-recipes-writer-ai' ) : __( 'WordPress', 'ms-recipes-writer-ai' ),
				'note' => $next ? MSRWA_I18N::when( gmdate( 'Y-m-d H:i:s', $next ) ) : __( 'aucun entretien planifié', 'ms-recipes-writer-ai' ),
			),
			array(
				'label' => __( 'téléversements', 'ms-recipes-writer-ai' ),
				'value' => $writable ? __( 'accessibles', 'ms-recipes-writer-ai' ) : __( 'bloqués', 'ms-recipes-writer-ai' ),
			),
		) );
		echo '</section>';

		self::retention();
	}

	/**
	 * What is kept, for how long, and what that currently weighs.
	 *
	 * On the same screen as the keys because it is the other thing an operator
	 * comes here to check, and because a plugin that quietly grows without
	 * limit should at least say how big it has got.
	 */
	private static function retention() {
		$settings = MSRWA_Settings::get();
		$policy = MSRWA_Retention::policy();
		$weight = MSRWA_Retention::weight();
		$last = MSRWA_Retention::last();

		$ages = array(
			'events' => array( 'retention_events_days', __( 'Déroulé', 'ms-recipes-writer-ai' ), __( 'La narration minute par minute. La table qui grossit le plus vite, et celle que personne ne relit.', 'ms-recipes-writer-ai' ) ),
			'artifacts' => array( 'retention_artifacts_days', __( 'Productions lourdes', 'ms-recipes-writer-ai' ), __( 'Le dossier de recherche, l’article tel que la machine l’a écrit, les prompts d’image. Le verdict, la revue et la recette restent quoi qu’il arrive.', 'ms-recipes-writer-ai' ) ),
			'runs' => array( 'retention_runs_days', __( 'Runs entiers', 'ms-recipes-writer-ai' ), __( 'Y compris les chiffres. Laissé à zéro par défaut : une ligne d’étape est le seul témoignage de ce qu’un run a coûté. Un run qui a produit un brouillon n’est jamais supprimé.', 'ms-recipes-writer-ai' ) ),
		);

		echo '<section class="ms-card"><h2>' . esc_html__( 'Ce qui est conservé', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Trois durées, parce que trois choses vieillissent différemment. À zéro, rien de cette sorte n’est jamais supprimé.', 'ms-recipes-writer-ai' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="msrwa_save_settings">';
		wp_nonce_field( 'msrwa_save_settings' );

		$pinned = array();
		foreach ( $ages as $name => $age ) {
			list( $key, $label, $why ) = $age;
			$set = (int) ( $settings[ $key ] ?? 0 );
			if ( $set !== (int) $policy[ $name ] ) { $pinned[] = $label; }
			echo '<p><label for="ms-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>'
				. '<input type="number" id="ms-' . esc_attr( $key ) . '" name="msrwa_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $set ) . '" step="1" min="0" max="3650" class="small-text ms-num"> '
				. esc_html__( 'jours', 'ms-recipes-writer-ai' )
				. '<br><small class="ms-muted">' . esc_html( $why ) . '</small></p>';
		}
		echo '<p>';
		submit_button( __( 'Enregistrer les durées', 'ms-recipes-writer-ai' ), 'secondary', 'submit', false );
		echo '</p></form>';

		// A filter beats the field, so a screen that showed only the field would
		// be telling an operator something that is not going to happen.
		if ( $pinned ) {
			MSRWA_UI::note( esc_html( sprintf(
				/* translators: %s is a list of names, e.g. "Déroulé, Runs entiers". */
				__( 'Un filtre impose une autre durée pour : %s. C’est le filtre qui s’applique, pas ce qui est saisi ici.', 'ms-recipes-writer-ai' ),
				implode( ', ', $pinned )
			) ), 'warn' );
		}

		MSRWA_UI::figures( array(
			array(
				'label' => __( 'poids actuel', 'ms-recipes-writer-ai' ),
				'value' => size_format( $weight['artifact_bytes'] ),
				'note' => sprintf(
					/* translators: 1: number of runs, 2: number of timeline entries. */
					__( '%1$s run(s), %2$s lignes de déroulé', 'ms-recipes-writer-ai' ),
					number_format_i18n( $weight['runs'] ),
					number_format_i18n( $weight['events'] )
				),
			),
		) );

		echo '<div class="ms-row">';
		if ( $last['at'] ) {
			echo '<span class="ms-muted">' . esc_html( sprintf(
				/* translators: 1: how long ago, 2: timeline rows removed, 3: heavy outputs removed, 4: whole runs removed. */
				__( 'Dernier passage il y a %1$s : %2$s lignes de déroulé, %3$s productions, %4$s runs.', 'ms-recipes-writer-ai' ),
				human_time_diff( (int) strtotime( $last['at'] . ' UTC' ), time() ),
				number_format_i18n( $last['events'] ),
				number_format_i18n( $last['artifacts'] ),
				number_format_i18n( $last['runs'] )
			) ) . '</span>';
		} else {
			echo '<span class="ms-muted">' . esc_html__( 'Le nettoyage n’a encore jamais tourné.', 'ms-recipes-writer-ai' ) . '</span>';
		}
		echo '<span id="ms-prune-status" class="ms-muted" aria-live="polite"></span>';
		echo '<button type="button" class="button" id="ms-prune">' . esc_html__( 'Nettoyer maintenant', 'ms-recipes-writer-ai' ) . '</button>';
		echo '</div>';

		echo '<p class="ms-muted">' . esc_html__( 'Un passage est borné : il retire ce qu’il peut sans faire tomber la requête, et reprend au suivant. Chaque durée est aussi un filtre — msrwa_retention_events_days, msrwa_retention_artifacts_days, msrwa_retention_runs_days.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '</section>';
	}
}
