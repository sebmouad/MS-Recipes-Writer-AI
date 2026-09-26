<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Submitting work: recipes and photographs. What a lot produces, its language
 * and its ceiling are the site's, set by an administrator in the settings.
 *
 * Numbered, because it genuinely is a sequence — nothing can be paired before
 * the photographs are chosen, and nothing dispatched before the pairing is
 * settled. The estimate sits beside the button, because the first thing a
 * person deserves to know is what this will cost.
 */
final class MSRWA_Screen_Compose {

	/**
	 * The form, at the top of the pass: a new lot is the first thing anyone
	 * comes to do, and what is already cooking reads best right under it.
	 * The site's warnings — no key, a ceiling reached — are the pass's, above.
	 */
	public static function form() {
		?>
		<section class="ms-new" id="ms-new" aria-labelledby="ms-new-title">
			<div class="ms-new-head">
				<h2 id="ms-new-title"><?php esc_html_e( 'Nouveau lot de recettes', 'ms-recipes-writer-ai' ); ?></h2>
				<p><?php esc_html_e( 'Des recettes, des photographies, ou les deux. Les photographies sont décrites puis associées ; sans texte, chaque plat qu’elles montrent devient une recette. Vous confirmez avant que quoi que ce soit ne soit généré.', 'ms-recipes-writer-ai' ); ?></p>
			</div>
		<form id="ms-compose" class="ms-steps">

			<div class="ms-new-inputs">
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

			</div>

			<div class="ms-new-go">
				<div class="ms-new-go-text">
					<p id="ms-estimate" class="ms-new-estimate" aria-live="polite"></p>
					<p class="ms-muted"><?php esc_html_e( 'Rien n’est écrit à cette étape : seules les photographies sont décrites. Vous verrez les recettes et l’appariement avant de lancer quoi que ce soit.', 'ms-recipes-writer-ai' ); ?></p>
				</div>
				<div class="ms-new-go-action">
					<button type="submit" class="button button-primary button-hero" id="ms-submit"><?php esc_html_e( 'Décrire et apparier', 'ms-recipes-writer-ai' ); ?></button>
					<span id="ms-compose-status" class="ms-muted" aria-live="polite"></span>
				</div>
			</div>
		</form>
		</section>
		<?php
	}
}
