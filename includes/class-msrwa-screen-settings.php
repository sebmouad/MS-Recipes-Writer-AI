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
					'claude_key' => array( 'Claude', 'anthropic' ),
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
			<?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ) ); ?>
		</form>
		<?php
		self::health();
		echo '</div>';
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
			array(
				'label' => __( 'rétention', 'ms-recipes-writer-ai' ),
				'value' => number_format_i18n( (int) apply_filters( 'msrwa_event_retention_days', 90 ) ),
				'note' => __( 'jours de déroulé conservés ; les chiffres restent', 'ms-recipes-writer-ai' ),
			),
		) );
		echo '</section>';
	}
}
