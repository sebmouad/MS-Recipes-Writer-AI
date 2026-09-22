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
				<button type="button" class="button" id="ms-pick"><?php esc_html_e( 'Choisir dans la médiathèque', 'ms-recipes-writer-ai' ); ?></button>
				<span class="ms-muted" id="ms-image-count"><?php esc_html_e( 'aucune photographie', 'ms-recipes-writer-ai' ); ?></span>
				<input type="hidden" id="ms-images" name="images" value="">
				<div class="ms-thumbs" id="ms-thumbs"></div>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'Ce qu’il faut produire', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php esc_html_e( 'Chaque recette suit le même chemin. Les montants sont calculés depuis le routage et les tarifs réellement configurés — ce sont des estimations, jamais une facture.', 'ms-recipes-writer-ai' ); ?></p>
				<div class="ms-choices">
					<?php foreach ( MSRWA_Profile::all() as $key => $profile ) : ?>
						<label class="ms-choice">
							<input type="radio" name="profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( MSRWA_Profile::FULL, $key ); ?>>
							<span class="ms-choice-body">
								<strong><?php echo esc_html( $profile['label'] ); ?></strong>
								<small><?php echo esc_html( $profile['description'] ); ?></small>
							</span>
							<span class="ms-choice-cost" data-profile-cost="<?php echo esc_attr( $key ); ?>">~ <?php echo esc_html( MSRWA_I18N::money( MSRWA_Estimate::recipe( $key )['cost_usd'], 4 ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="ms-step">
				<h3><?php esc_html_e( 'La langue et le plafond', 'ms-recipes-writer-ai' ); ?></h3>
				<p><?php esc_html_e( 'La langue de l’article, qui n’est pas celle de cette interface. Le plafond s’applique à chaque recette : au-delà, le run s’arrête plutôt que de dépenser.', 'ms-recipes-writer-ai' ); ?></p>
				<p>
					<label for="ms-language"><strong><?php esc_html_e( 'Langue de l’article', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<select id="ms-language" name="language">
						<?php foreach ( MSRWA_Profile::languages() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'fr', $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="ms-budget"><strong><?php esc_html_e( 'Plafond par recette', 'ms-recipes-writer-ai' ); ?></strong></label><br>
					<input type="number" id="ms-budget" name="budget" value="0.20" step="0.01" min="0.01" class="small-text ms-num"> $
				</p>
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
