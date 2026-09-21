<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every engine parameter, editable, without the engine knowing this exists.
 *
 * What is stored is only the difference from the engine's own defaults, and it
 * is handed back to the engine as its caller layer. So an engine that changes
 * its mind about a ceiling is followed everywhere except where somebody
 * deliberately disagreed — which is also why the default is always shown beside
 * the field rather than copied into it.
 */
final class MSRWA_Screen_Engine {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$defaults = MSRWA_Engine_Settings::defaults();
		$stored = MSRWA_Engine_Settings::stored();
		$invalid = isset( $_GET['invalid'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ) ) ) ) : array();
		$encode = static function ( $value ) { return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Moteur', 'ms-recipes-writer-ai' ),
			__( 'Rien n’est figé dans le moteur : routage, plafonds, tentatives, tarifs, gabarits, registre des étapes. Seule la différence avec ses valeurs par défaut est conservée.', 'ms-recipes-writer-ai' ),
			array( __( 'groupes modifiés', 'ms-recipes-writer-ai' ) => number_format_i18n( count( $stored ) ) )
		);

		if ( isset( $_GET['saved'] ) && ! $invalid ) { MSRWA_UI::note( esc_html__( 'Enregistré.', 'ms-recipes-writer-ai' ) ); }
		if ( $invalid ) {
			MSRWA_UI::note( esc_html( sprintf(
				/* translators: %s is a comma-separated list of group names. */
				__( 'JSON invalide, donc ignoré pour : %s. Le reste a bien été enregistré, et ces groupes gardent leur valeur précédente.', 'ms-recipes-writer-ai' ),
				implode( ', ', $invalid )
			) ), 'stop' );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="msrwa_save_engine">
			<?php wp_nonce_field( 'msrwa_save_engine' ); ?>

			<section class="ms-card">
				<h2><?php esc_html_e( 'Langue par défaut', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Celle d’un lot qui n’en choisit pas.', 'ms-recipes-writer-ai' ); ?></p>
				<p>
					<input type="text" name="msrwa_engine[language]" value="<?php echo esc_attr( $stored['language'] ?? $defaults['language'] ); ?>" class="small-text ms-key">
					<span class="ms-muted"><?php echo esc_html( sprintf( /* translators: %s is a language code. */ __( 'défaut du moteur : %s', 'ms-recipes-writer-ai' ), $defaults['language'] ) ); ?></span>
				</p>
			</section>

			<?php
			foreach ( array(
				__( 'Réglages', 'ms-recipes-writer-ai' ) => MSRWA_Engine_Settings::simple(),
				__( 'Structures', 'ms-recipes-writer-ai' ) => MSRWA_Engine_Settings::structural(),
			) as $section => $groups ) :
				?>
				<h2 style="margin-top:28px"><?php echo esc_html( $section ); ?></h2>
				<?php foreach ( $groups as $group => $help ) : ?>
					<?php
					$effective = MSRWA_Engine_Settings::effective( $group );
					$changed = isset( $stored[ $group ] );
					$text = $encode( $effective );
					?>
					<section class="ms-card">
						<h2>
							<?php echo esc_html( $group ); ?>
							<?php if ( $changed ) : ?><span class="ms-state ms-state-warn"><?php esc_html_e( 'modifié', 'ms-recipes-writer-ai' ); ?></span><?php endif; ?>
						</h2>
						<p><?php echo esc_html( $help ); ?></p>
						<p>
							<label class="screen-reader-text" for="ms-engine-<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $group ); ?></label>
							<textarea id="ms-engine-<?php echo esc_attr( $group ); ?>" name="msrwa_engine[<?php echo esc_attr( $group ); ?>]"
								rows="<?php echo esc_attr( min( 26, max( 6, substr_count( $text, "\n" ) + 1 ) ) ); ?>"
								class="large-text ms-code" spellcheck="false"><?php echo esc_textarea( $text ); ?></textarea>
						</p>
						<details>
							<summary><?php esc_html_e( 'Valeur par défaut du moteur', 'ms-recipes-writer-ai' ); ?></summary>
							<pre class="ms-code"><?php echo esc_html( $encode( $defaults[ $group ] ?? array() ) ); ?></pre>
						</details>
					</section>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ) ); ?>
		</form>

		<?php MSRWA_Operations::diagnostics(); ?>

		<section class="ms-card">
			<h2><?php esc_html_e( 'Ce qui est réellement transmis au moteur', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'La couche appelante, telle quelle. Vide signifie que tout suit le moteur.', 'ms-recipes-writer-ai' ); ?></p>
			<pre class="ms-code"><?php echo esc_html( $encode( $stored ) ); ?></pre>
		</section>
		<?php
		echo '</div>';
	}
}
