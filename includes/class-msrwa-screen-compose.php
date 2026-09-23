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
			__( 'Collez vos recettes et ajoutez les photographies. Elles seront décrites puis associées ; vous confirmez l’appariement avant que quoi que ce soit ne soit généré.', 'ms-recipes-writer-ai' ),
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
				<h3><?php esc_html_e( 'Les recettes', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php esc_html_e( 'Une ligne de trois tirets ou plus sépare deux recettes. La première ligne de chaque bloc en devient le titre.', 'ms-recipes-writer-ai' ); ?></p>
				<textarea id="ms-recipes" name="recipes" rows="14" class="large-text ms-code" spellcheck="false" placeholder="<?php echo esc_attr( __( "Tarte aux pommes normande\nPâte brisée, pommes, crème, calvados…\n\n---\n\nPoulet yassa\nPoulet, oignons, citron…", 'ms-recipes-writer-ai' ) ); ?>"></textarea>
				<p class="ms-muted" id="ms-recipe-count" aria-live="polite"></p>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'Les photographies', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php esc_html_e( 'Sans dire lesquelles vont avec quoi : chacune sera décrite depuis ses propres pixels, puis associée à une recette. Une photographie est facturée une fois, même si vous corrigez ensuite l’association.', 'ms-recipes-writer-ai' ); ?></p>
				<label class="button" for="ms-photos"><?php esc_html_e( 'Choisir sur mon ordinateur', 'ms-recipes-writer-ai' ); ?></label>
				<input type="file" id="ms-photos" name="photos[]" class="screen-reader-text" multiple accept="image/jpeg,image/png,image/webp">
				<span class="ms-muted" id="ms-image-count" aria-live="polite"><?php esc_html_e( 'aucune photographie', 'ms-recipes-writer-ai' ); ?></span>
				<p class="ms-muted"><small><?php echo esc_html( sprintf(
					/* translators: 1: the largest number of photographs, 2: the largest size of one, in megabytes. */
					__( 'JPEG, PNG ou WebP, %1$d au plus, %2$s Mo chacune. Elles sont ajoutées à la médiathèque quand le lot est créé.', 'ms-recipes-writer-ai' ),
					MSRWA_Intake::MAX_PHOTOS,
					number_format_i18n( MSRWA_Admin::photo_bytes() / 1000000, 0 )
				) ); ?></small></p>
				<div class="ms-thumbs" id="ms-thumbs"></div>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'Ce qu’il faut produire', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php echo esc_html( MSRWA_Rights::may_see_money()
					? __( 'Chaque recette suit le même chemin. Les montants sont calculés depuis le routage et les tarifs réellement configurés — ce sont des estimations, jamais une facture.', 'ms-recipes-writer-ai' )
					: __( 'Chaque recette suit le même chemin. Plus il y a d’images, plus la recette demande de travail.', 'ms-recipes-writer-ai' ) ); ?></p>
				<div class="ms-choices">
					<?php foreach ( MSRWA_Profile::all() as $key => $profile ) : ?>
						<label class="ms-choice">
							<input type="radio" name="profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( MSRWA_Profile::FULL, $key ); ?>>
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
			</section>

			<section class="ms-step">
				<h3><?php echo esc_html( MSRWA_Rights::may_see_money() ? __( 'La langue et le plafond', 'ms-recipes-writer-ai' ) : __( 'La langue', 'ms-recipes-writer-ai' ) ); ?></h3>
				<p><?php echo esc_html( MSRWA_Rights::may_see_money()
					? __( 'La langue de l’article, qui n’est pas celle de cette interface. Le plafond s’applique à chaque recette : au-delà, le run s’arrête plutôt que de dépenser.', 'ms-recipes-writer-ai' )
					: __( 'La langue de l’article, qui n’est pas celle de cette interface.', 'ms-recipes-writer-ai' ) ); ?></p>
				<p>
					<label for="ms-language"><strong><?php esc_html_e( 'Langue de l’article', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<select id="ms-language" name="language">
						<?php foreach ( MSRWA_Profile::languages() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (string) MSRWA_Settings::get()['site_language'], $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( MSRWA_Rights::may_see_money() ) : ?>
					<p>
						<label for="ms-budget"><strong><?php esc_html_e( 'Plafond par recette', 'ms-recipes-writer-ai' ); ?></strong></label><br>
						<input type="number" id="ms-budget" name="budget" value="<?php echo esc_attr( (float) MSRWA_Settings::get()['per_recipe_budget_usd'] ); ?>" step="0.01" min="0.01" class="small-text ms-num"> $
					</p>
				<?php endif; ?>
			</section>

			<div class="ms-card">
				<p id="ms-estimate" class="ms-muted" aria-live="polite"></p>
				<p>
					<button type="submit" class="button button-primary button-hero" id="ms-submit"><?php esc_html_e( 'Décrire et apparier', 'ms-recipes-writer-ai' ); ?></button>
					<span id="ms-compose-status" class="ms-muted"></span>
				</p>
				<p class="ms-muted"><?php esc_html_e( 'Rien n’est écrit à cette étape : seules les photographies sont décrites. Vous verrez l’appariement avant de lancer quoi que ce soit.', 'ms-recipes-writer-ai' ); ?></p>
			</div>
		</form>
		<?php
		echo '</div>';
	}
}
