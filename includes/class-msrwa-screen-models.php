<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The models this site may use, and everything variable about them.
 *
 * The engine holds the editorial process and nothing else, so this is where a
 * renamed model, a moved rate or a change of mind about which model may write
 * an article is handled — without touching the chain that writes one.
 *
 * Two buttons, deliberately separate. Asking a provider for its models is free
 * and certain, so it is one action. Asking a model to read published pricing
 * pages costs a little and can be wrong, so it is another, and what it returns
 * is marked as looked up for as long as it stands.
 */
final class MSRWA_Screen_Models {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$rows = MSRWA_Catalog::rows();
		$steps = self::steps();
		$unpriced = 0;
		$unserved = 0;
		foreach ( $rows as $row ) {
			if ( null === $row['input_usd'] || null === $row['output_usd'] ) { $unpriced++; }
			if ( false === $row['served'] ) { $unserved++; }
		}

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Modèles', 'ms-recipes-writer-ai' ),
			__( 'Quels modèles ce site peut utiliser, ce qu’ils coûtent, et quelle étape chacun a le droit de servir. Le moteur ne contient que le processus éditorial : tout ce qui est ici est une donnée, modifiable sans toucher à la manière dont un article est écrit.', 'ms-recipes-writer-ai' ),
			array(
				__( 'modèles', 'ms-recipes-writer-ai' ) => number_format_i18n( count( $rows ) ),
				__( 'sans tarif', 'ms-recipes-writer-ai' ) => number_format_i18n( $unpriced ),
				__( 'retirés', 'ms-recipes-writer-ai' ) => number_format_i18n( $unserved ),
			)
		);

		if ( isset( $_GET['saved'] ) ) { MSRWA_UI::note( esc_html__( 'Enregistré.', 'ms-recipes-writer-ai' ) ); }

		self::actions();

		if ( ! $rows ) {
			MSRWA_UI::note( esc_html__( 'Aucun modèle enregistré. Réactivez l’extension pour installer le catalogue livré.', 'ms-recipes-writer-ai' ), 'warn' );
			echo '</div>';
			return;
		}

		self::table( $rows, $steps );
		self::derived();
		echo '</div>';
	}

	/** The steps a model can be given, straight from the engine's registry. */
	private static function steps() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		$out = array();
		foreach ( (array) $config->steps() as $key => $step ) {
			$capability = (string) ( $step['capability'] ?? 'text' );
			// A step that calls no model cannot be given one.
			if ( 'none' === $capability ) { continue; }
			$name = MSRWA_UI::step_name( $key );
			$out[ $key ] = array( 'label' => $name !== $key ? $name : (string) ( $step['label'] ?? $key ), 'image' => 'image_generation' === $capability );
		}
		return $out;
	}

	private static function actions() {
		?>
		<section class="ms-card">
			<h2><?php esc_html_e( 'Mettre le catalogue à jour', 'ms-recipes-writer-ai' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="ms-fetch-models"><?php esc_html_e( 'Relever les modèles chez les fournisseurs', 'ms-recipes-writer-ai' ); ?></button>
				<span class="ms-muted"><?php esc_html_e( 'Demande à chaque fournisseur la liste de ce qu’il sert, gratuitement, puis cherche aussitôt le tarif des nouveaux modèles qui n’en ont pas : quelques centimes, et seulement s’il en manque. Aucun fournisseur ne publie ses tarifs par API.', 'ms-recipes-writer-ai' ); ?></span>
			</p>
			<p>
				<button type="button" class="button" id="ms-fetch-prices"><?php esc_html_e( 'Chercher les tarifs manquants', 'ms-recipes-writer-ai' ); ?></button>
				<span class="ms-muted"><?php esc_html_e( 'Coûte quelques centimes. Un modèle avec recherche web lit les pages de tarifs des fournisseurs. Ce qu’il rapporte est marqué « trouvé par IA » et cite sa page : c’est une indication à vérifier, jamais une facture. Un tarif saisi à la main n’est jamais remplacé.', 'ms-recipes-writer-ai' ); ?></span>
			</p>
			<div id="ms-catalog-result"></div>
		</section>
		<?php
	}

	private static function table( array $rows, array $steps ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="msrwa_save_models">
			<?php wp_nonce_field( 'msrwa_save_models' ); ?>
			<section class="ms-card ms-card-flush">
				<h2><?php esc_html_e( 'Le catalogue', 'ms-recipes-writer-ai' ); ?></h2>
				<?php echo MSRWA_UI::scroll( esc_attr__( 'Le catalogue', 'ms-recipes-writer-ai' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<table class="ms-table ms-catalog">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Entrée $/M', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sortie $/M', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'D’où vient ce tarif', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Étapes qu’il a le droit de servir', 'ms-recipes-writer-ai' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $name = $row['provider'] . ':' . $row['model_id']; ?>
						<tr>
							<th scope="row">
								<label>
									<input type="checkbox" name="model[<?php echo esc_attr( $name ); ?>][enabled]" value="1" <?php checked( $row['enabled'] ); ?>>
									<code class="ms-key"><?php echo esc_html( $row['model_id'] ); ?></code>
								</label>
								<br><small class="ms-muted"><?php echo esc_html( $row['provider'] . ( $row['label'] && $row['label'] !== $row['model_id'] ? ' · ' . $row['label'] : '' ) ); ?></small>
								<?php if ( false === $row['served'] ) : ?>
									<br><small class="ms-stop"><?php esc_html_e( 'le fournisseur ne le sert plus', 'ms-recipes-writer-ai' ); ?></small>
								<?php elseif ( null === $row['served'] ) : ?>
									<br><small class="ms-muted"><?php esc_html_e( 'jamais relevé', 'ms-recipes-writer-ai' ); ?></small>
								<?php endif; ?>
							</th>
							<td><input type="number" step="0.000001" min="0" class="small-text ms-num" name="model[<?php echo esc_attr( $name ); ?>][input]" value="<?php echo esc_attr( null === $row['input_usd'] ? '' : $row['input_usd'] ); ?>"></td>
							<td><input type="number" step="0.000001" min="0" class="small-text ms-num" name="model[<?php echo esc_attr( $name ); ?>][output]" value="<?php echo esc_attr( null === $row['output_usd'] ? '' : $row['output_usd'] ); ?>"></td>
							<td><?php self::provenance( $row ); ?></td>
							<td class="ms-grid-cell">
								<?php
								// An image model draws and a text model writes: each is offered
								// only the steps it could actually serve.
								$draws = ! empty( $row['capabilities']['image_generation'] );
								?>
								<?php foreach ( $steps as $key => $step ) : ?>
									<?php if ( $step['image'] !== $draws ) { continue; } ?>
									<label class="ms-grid-step">
										<input type="checkbox" name="model[<?php echo esc_attr( $name ); ?>][steps][]" value="<?php echo esc_attr( $key ); ?>"
											<?php checked( $row['steps'] && in_array( $key, $row['steps'], true ) ); ?>>
										<?php echo esc_html( $step['label'] ); ?>
									</label>
								<?php endforeach; ?>
								<?php if ( ! $row['steps'] ) : ?>
									<br><small class="ms-muted"><?php esc_html_e( 'rien de coché : aucune restriction', 'ms-recipes-writer-ai' ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</section>
			<div class="ms-card ms-save"><?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ), 'primary', 'submit', false ); ?></div>
		</form>
		<?php
	}

	/** Where a rate came from, which decides how far it may be trusted. */
	private static function provenance( array $row ) {
		if ( null === $row['input_usd'] ) {
			echo '<span class="ms-state ms-state-warn">' . esc_html__( 'aucun tarif', 'ms-recipes-writer-ai' ) . '</span>';
			return;
		}
		$tones = array(
			MSRWA_Catalog::MANUAL => array( 'good', __( 'saisi à la main', 'ms-recipes-writer-ai' ) ),
			MSRWA_Catalog::LOOKED_UP => array( 'warn', __( 'trouvé par IA — à vérifier', 'ms-recipes-writer-ai' ) ),
			MSRWA_Catalog::READ => array( 'good', __( 'lu sur la page du fournisseur', 'ms-recipes-writer-ai' ) ),
			MSRWA_Catalog::SHIPPED => array( 'idle', __( 'livré avec l’extension', 'ms-recipes-writer-ai' ) ),
		);
		list( $tone, $label ) = $tones[ $row['price_method'] ] ?? array( 'idle', $row['price_method'] );
		echo '<span class="ms-state ms-state-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
		if ( $row['price_source'] ) {
			echo '<br><small class="ms-muted"><a href="' . esc_url( $row['price_source'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'la page citée', 'ms-recipes-writer-ai' ) . '</a></small>';
		}
		if ( $row['price_checked_at'] ) {
			echo '<br><small class="ms-muted">' . esc_html( $row['price_checked_at'] ) . '</small>';
		}
	}

	/** What all of this produces, so the effect of an edit is visible here. */
	private static function derived() {
		$handed = MSRWA_Catalog::for_engine();
		?>
		<section class="ms-card">
			<h2><?php esc_html_e( 'Ce que le moteur reçoit', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'Engendré par ce tableau, puis remis au moteur comme couche appelante. Les niveaux sont le moins cher, le milieu et le plus cher de ce que ce site a vraiment — c’est ce qui empêche un niveau de désigner un modèle que personne n’a tarifé ou que le fournisseur a retiré. Seules les étapes de texte passent par un niveau ; la génération d’image nomme son modèle directement.', 'ms-recipes-writer-ai' ); ?></p>
			<?php if ( empty( $handed['tiers'] ) ) : ?>
				<p class="ms-muted"><?php esc_html_e( 'Rien d’engendré : aucun modèle tarifé et connu pour écrire. Les valeurs par défaut du moteur s’appliquent.', 'ms-recipes-writer-ai' ); ?></p>
			<?php else : ?>
				<table class="ms-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Niveau', 'ms-recipes-writer-ai' ); ?></th>
						<?php foreach ( array_keys( $handed['models'] ) as $provider ) : ?>
							<th scope="col"><?php echo esc_html( $provider ); ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( array( 'low', 'medium', 'high' ) as $tier ) : ?>
						<?php if ( empty( $handed['tiers'][ $tier ] ) ) { continue; } ?>
						<tr>
							<th scope="row"><?php echo esc_html( $tier ); ?></th>
							<?php foreach ( array_keys( $handed['models'] ) as $provider ) : ?>
								<td><code class="ms-key"><?php echo esc_html( (string) ( $handed['tiers'][ $tier ][ $provider ] ?? '—' ) ); ?></code></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Saving the grid and the rates a person typed. */
	public static function save() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_models' );

		$posted = isset( $_POST['model'] ) && is_array( $_POST['model'] ) ? wp_unslash( $_POST['model'] ) : array();
		foreach ( $posted as $name => $values ) {
			list( $provider, $model_id ) = array_pad( explode( ':', (string) $name, 2 ), 2, '' );
			if ( '' === $provider || '' === $model_id ) { continue; }
			$row = MSRWA_Catalog::row( $provider, $model_id );
			if ( ! $row ) { continue; }

			MSRWA_Catalog::remember_compatibility( $provider, $model_id, (array) ( $values['steps'] ?? array() ), ! empty( $values['enabled'] ) );

			$input = '' === trim( (string) ( $values['input'] ?? '' ) ) ? null : (float) $values['input'];
			$output = '' === trim( (string) ( $values['output'] ?? '' ) ) ? null : (float) $values['output'];
			// Only a real change is recorded as a person's doing. Saving the
			// grid must not relabel every looked-up rate as hand-checked.
			if ( $input !== $row['input_usd'] || $output !== $row['output_usd'] ) {
				MSRWA_Catalog::remember_price( $provider, $model_id, $input, $output, MSRWA_Catalog::MANUAL, (string) $row['price_source'] );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=msrwa-models&saved=1' ) );
		exit;
	}
}
