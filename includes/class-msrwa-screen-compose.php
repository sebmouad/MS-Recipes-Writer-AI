<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Submitting work: recipes, photographs, what to produce, in which language.
 *
 * Numbered, because it genuinely is a sequence — nothing can be paired before
 * the photographs are chosen, and nothing dispatched before the pairing is
 * settled. The estimate is shown before the button, because the first thing a
 * person deserves to know is what this will cost.
 */
final class MSRWA_Screen_Compose {

	public static function render() {
		if ( ! MSRWA_Rights::may_write() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Nouveau lot', 'ms-recipes-writer-ai' ),
			__( 'Des recettes, des photographies, ou les deux. Les photographies sont décrites puis associées ; sans texte, chaque plat qu’elles montrent devient une recette. Vous confirmez avant que quoi que ce soit ne soit généré.', 'ms-recipes-writer-ai' ),
			array(),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=msrwa' ) ) . '">' . esc_html__( 'Retour au pass', 'ms-recipes-writer-ai' ) . '</a>'
		);

		if ( ! MSRWA_Settings::configured_providers() ) {
			MSRWA_UI::note( __( 'Aucune clé d’API n’est enregistrée. Un lot lancé maintenant échouerait à la première étape.', 'ms-recipes-writer-ai' ), 'stop' );
		}
		$refusal = MSRWA_Budget::refusal();
		if ( '' !== $refusal ) { MSRWA_UI::note( esc_html( $refusal ), 'stop' ); }
		?>
		<form id="ms-compose" class="ms-steps">

			<section class="ms-step">
				<h3><?php esc_html_e( 'Les recettes', 'ms-recipes-writer-ai' ); ?> <span class="ms-optional"><?php esc_html_e( 'facultatif avec des photographies', 'ms-recipes-writer-ai' ); ?></span></h3>
				<p><?php esc_html_e( 'Une ligne de trois tirets ou plus sépare deux recettes. La première ligne de chaque bloc en devient le titre. Le nom du plat suffit : le reste est établi d’après les sources.', 'ms-recipes-writer-ai' ); ?></p>
				<textarea id="ms-recipes" name="recipes" rows="10" class="large-text ms-recipes-text" spellcheck="true" placeholder="<?php echo esc_attr( __( "Tarte aux pommes normande\nPâte brisée, pommes, crème, calvados…\n\n---\n\nPoulet yassa\nPoulet, oignons, citron…", 'ms-recipes-writer-ai' ) ); ?>"></textarea>
				<div class="ms-recipes-bar">
					<button type="button" class="button" id="ms-recipe-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Ajouter une recette', 'ms-recipes-writer-ai' ); ?></button>
					<p class="ms-muted" id="ms-recipe-count" aria-live="polite"></p>
				</div>
				<ol class="ms-recipe-list" id="ms-recipe-list" hidden></ol>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'Les photographies', 'ms-recipes-writer-ai' ); ?> <span class="ms-optional"><?php esc_html_e( 'facultatif avec du texte', 'ms-recipes-writer-ai' ); ?></span></h3>
				<p><?php esc_html_e( 'Sans dire lesquelles vont avec quoi : chacune sera décrite depuis ses propres pixels, puis associée à une recette — ou, sans texte, regroupée avec les autres photographies du même plat. Une photographie est facturée une fois, même si vous corrigez ensuite l’association.', 'ms-recipes-writer-ai' ); ?></p>
				<?php
				// One target for everything: a click opens the file picker, a drop
				// takes files, and a paste anywhere on the page — an image or an
				// image's address — is read by the script.
				?>
				<label class="ms-drop" id="ms-drop" for="ms-photos">
					<input type="file" id="ms-photos" name="photos[]" class="ms-drop-input" multiple accept="image/jpeg,image/png,image/webp">
					<span class="dashicons dashicons-upload ms-drop-icon" aria-hidden="true"></span>
					<span class="ms-drop-title"><?php esc_html_e( 'Choisir des photographies sur mon ordinateur', 'ms-recipes-writer-ai' ); ?></span>
					<span class="ms-drop-or"><?php esc_html_e( 'ou déposez-les ici, ou collez avec Ctrl+V (⌘V sur Mac) une image copiée ou l’adresse d’une image', 'ms-recipes-writer-ai' ); ?></span>
					<span class="ms-drop-hint"><?php echo esc_html( sprintf(
						/* translators: 1: the largest number of photographs, 2: the largest size of one, in megabytes. */
						__( 'JPEG, PNG ou WebP, %1$d au plus, %2$s Mo chacune.', 'ms-recipes-writer-ai' ),
						MSRWA_Intake::MAX_PHOTOS,
						number_format_i18n( MSRWA_Admin::photo_bytes() / 1000000, 0 )
					) ); ?></span>
				</label>
				<div class="ms-photo-bar">
					<p class="ms-muted ms-photo-count" id="ms-image-count" aria-live="polite"><?php esc_html_e( 'aucune photographie', 'ms-recipes-writer-ai' ); ?></p>
					<button type="button" class="button-link ms-photo-clear" id="ms-photo-clear" hidden><?php esc_html_e( 'Tout retirer', 'ms-recipes-writer-ai' ); ?></button>
				</div>
				<ul class="ms-photo-grid" id="ms-thumbs"></ul>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'Ce qu’il faut produire', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php echo esc_html( MSRWA_Rights::may_see_money()
					? __( 'Chaque recette suit le même chemin. Les montants sont calculés depuis le routage et les tarifs réellement configurés — ce sont des estimations, jamais une facture.', 'ms-recipes-writer-ai' )
					: __( 'Chaque recette suit le même chemin. Plus il y a d’images, plus la recette demande de travail.', 'ms-recipes-writer-ai' ) ); ?></p>
				<div class="ms-choices">
					<?php $msrwa_offered = MSRWA_Profile::offered(); ?>
					<?php foreach ( $msrwa_offered as $key => $profile ) : ?>
						<label class="ms-choice">
							<input type="radio" name="profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $msrwa_offered[ MSRWA_Profile::FULL ] ) ? MSRWA_Profile::FULL : array_key_first( $msrwa_offered ), $key ); ?>>
							<span class="ms-choice-body">
								<strong><?php echo esc_html( $profile['label'] ); ?></strong>
								<small><?php echo esc_html( $profile['description'] ); ?></small>
							</span>
							<?php if ( MSRWA_Rights::may_see_money() ) : ?>
								<span class="ms-choice-cost" data-profile-cost="<?php echo esc_attr( $key ); ?>">~ <?php echo esc_html( MSRWA_I18N::money( MSRWA_Estimate::recipe( $key )['cost_usd'], 4 ) ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<?php $msrwa_templates = MSRWA_Profile::facebook_templates(); ?>
				<?php if ( count( $msrwa_templates ) > 1 ) : ?>
					<h3><?php esc_html_e( 'Visuel Facebook', 'ms-recipes-writer-ai' ); ?></h3>
					<p><?php esc_html_e( 'Le modèle du visuel Facebook de chaque recette de ce lot.', 'ms-recipes-writer-ai' ); ?></p>
					<div class="ms-choices">
						<?php foreach ( $msrwa_templates as $key => $label ) : ?>
							<label class="ms-choice">
								<input type="radio" name="facebook_template" value="<?php echo esc_attr( $key ); ?>" <?php checked( array_key_first( $msrwa_templates ), $key ); ?>>
								<span class="ms-choice-body"><strong><?php echo esc_html( $label ); ?></strong></span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<?php
			// Language and ceiling are the site's, set once in the settings: a
			// lot used to carry its own, and a writer could pick a language
			// nobody reviews in, or an administrator a ceiling nobody meant.
			$settings = MSRWA_Settings::get();
			$languages = MSRWA_Profile::languages();
			$language = $languages[ (string) $settings['site_language'] ] ?? (string) $settings['site_language'];
			?>
			<p class="ms-muted ms-lot-defaults" id="ms-lot-defaults">
				<?php
				echo esc_html( MSRWA_Rights::may_see_money()
					/* translators: 1: a language name, 2: an amount in US dollars. */
					? sprintf( __( 'Article en %1$s · plafond de %2$s par recette.', 'ms-recipes-writer-ai' ), $language, MSRWA_I18N::money( (float) $settings['per_recipe_budget_usd'], 2 ) )
					/* translators: %s is a language name. */
					: sprintf( __( 'Article en %s.', 'ms-recipes-writer-ai' ), $language ) );
				if ( MSRWA_Rights::may_manage() ) {
					echo ' <a href="' . esc_url( admin_url( 'admin.php?page=msrwa-settings' ) ) . '">' . esc_html__( 'Modifier dans les réglages', 'ms-recipes-writer-ai' ) . '</a>';
				}
				?>
			</p>

			<div class="ms-card">
				<p id="ms-estimate" class="ms-muted" aria-live="polite"></p>
				<p>
					<button type="submit" class="button button-primary button-hero" id="ms-submit"><?php esc_html_e( 'Décrire et apparier', 'ms-recipes-writer-ai' ); ?></button>
					<span id="ms-compose-status" class="ms-muted"></span>
				</p>
				<p class="ms-muted"><?php esc_html_e( 'Rien n’est écrit à cette étape : seules les photographies sont décrites. Vous verrez les recettes et l’appariement avant de lancer quoi que ce soit.', 'ms-recipes-writer-ai' ); ?></p>
			</div>
		</form>
		<?php
		echo '</div>';
	}
}
