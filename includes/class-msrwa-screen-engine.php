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

			<?php self::routing_picker( $stored, $defaults ); ?>

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

			<div class="ms-card ms-save">
				<button type="button" class="button" id="ms-engine-preview"><?php esc_html_e( 'Prévisualiser', 'ms-recipes-writer-ai' ); ?></button>
				<span class="ms-muted"><?php esc_html_e( 'Résout ce qui est à l’écran sans l’enregistrer et sans appeler personne.', 'ms-recipes-writer-ai' ); ?></span>
				<?php submit_button( __( 'Enregistrer', 'ms-recipes-writer-ai' ), 'primary', 'submit', false ); ?>
			</div>
			<div id="ms-engine-preview-result"></div>
		</form>

		<?php self::step_registry(); ?>
		<?php self::model_catalogue(); ?>

		<?php MSRWA_Operations::diagnostics(); ?>

		<section class="ms-card">
			<h2><?php esc_html_e( 'Ce qui est réellement transmis au moteur', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'La couche appelante, telle quelle. Vide signifie que tout suit le moteur.', 'ms-recipes-writer-ai' ); ?></p>
			<pre class="ms-code"><?php echo esc_html( $encode( $stored ) ); ?></pre>
		</section>
		<?php
		echo '</div>';
	}

	/**
	 * A friendly editor for the one JSON group people actually reach for:
	 * which provider and tier serves each step. It only ever rewrites the
	 * `routing` textarea below — that field stays the one thing the form
	 * submits, so nothing about saving or validating `routing` changes.
	 */
	private static function routing_picker( $stored, $defaults ) {
		$config = MSRWA_Engine_Config::create( $stored );
		$catalog = MSRWA_Catalog::defaults();
		$providers = array(
			'openai' => __( 'OpenAI', 'ms-recipes-writer-ai' ),
			'gemini' => __( 'Google Gemini', 'ms-recipes-writer-ai' ),
			'claude' => __( 'Anthropic Claude', 'ms-recipes-writer-ai' ),
		);
		$steps = $config->steps();

		$keys = array();
		foreach ( array_keys( (array) $defaults['routing'] ) as $key ) {
			if ( isset( $steps[ $key ]['label'] ) ) {
				$keys[ $key ] = $steps[ $key ]['label'];
			} elseif ( 'vision' === $key ) {
				$keys[ $key ] = __( 'Lecture des photographies (appariement, observation avant génération)', 'ms-recipes-writer-ai' );
			} elseif ( 'image' === $key ) {
				$keys[ $key ] = __( 'Génération d’image (à la une et collage Facebook)', 'ms-recipes-writer-ai' );
			} else {
				$keys[ $key ] = $key;
			}
		}

		// Every tier resolves to a text-and-vision-capable model on every
		// provider, so the tier pickers offer all three unconditionally; only
		// image generation is uneven across providers and models, so it lists
		// exactly what can do it rather than pretending otherwise.
		$image_models = array();
		foreach ( $catalog as $provider => $models ) {
			foreach ( $models as $model => $info ) {
				if ( empty( $info['image_generation'] ) ) { continue; }
				$image_models[] = array(
					'value' => $provider . ':' . $model,
					'label' => ( $providers[ $provider ] ?? $provider ) . ' — ' . $info['label'],
				);
			}
		}

		$current = array();
		foreach ( array_keys( $keys ) as $key ) { $current[ $key ] = $config->model_for( $key )['route']; }

		// What each choice actually resolves to. A provider and a quality name
		// are not an answer to "which model will run and what will it cost" —
		// the tier map turns them into a model identifier, and until this was
		// shown, choosing "low" picked a model nobody on this screen could
		// name, two of which this plugin has no price for and one of which its
		// provider does not serve at all.
		$resolved = array();
		$tiers = (array) $config->get( 'tiers', array() );
		foreach ( $tiers as $tier => $by_provider ) {
			foreach ( (array) $by_provider as $provider => $model ) {
				$resolved[ $provider . ':' . $tier ] = self::describe_model( $provider, (string) $model, $catalog );
			}
		}
		foreach ( $image_models as $entry ) {
			list( $provider, $model ) = array_pad( explode( ':', $entry['value'], 2 ), 2, '' );
			$resolved[ $entry['value'] ] = self::describe_model( $provider, $model, $catalog );
		}

		$listed = array();
		foreach ( array_keys( $providers ) as $provider ) {
			$listed[ $provider ] = array(
				'count' => count( MSRWA_Catalog::available( $provider ) ),
				'at' => MSRWA_Catalog::listed_at( $provider ),
			);
		}

		$data = array(
			'providers' => $providers,
			'tiers' => array_keys( (array) ( $defaults['tiers'] ?? array() ) ),
			'imageModels' => $image_models,
			'keys' => $keys,
			'current' => $current,
			'resolved' => $resolved,
			'labels' => array(
				'unpriced' => __( 'aucun tarif connu — le coût de cette étape ne peut pas être estimé', 'ms-recipes-writer-ai' ),
				'unserved' => __( 'le fournisseur ne sert pas ce nom : l’étape échouera', 'ms-recipes-writer-ai' ),
				'unknown' => __( 'jamais vérifié auprès du fournisseur', 'ms-recipes-writer-ai' ),
				'perMillion' => __( '$%1$s entrée / $%2$s sortie par million de jetons', 'ms-recipes-writer-ai' ),
			),
		);
		?>
		<section class="ms-card" id="ms-engine-routing-picker">
			<h2><?php esc_html_e( 'Modèle par étape', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'La façon courante de changer une route : un fournisseur, un niveau. Ceci réécrit le champ « routing » plus bas à chaque changement ; ce qui y est modifié à la main reste possible et prévaut en cas de désaccord entre les deux après un chargement de page.', 'ms-recipes-writer-ai' ); ?></p>
			<table class="ms-table ms-routing-picker">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Étape', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fournisseur et niveau', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modèle qui tournera', 'ms-recipes-writer-ai' ); ?></th>
				</tr></thead>
				<tbody></tbody>
			</table>
			<script type="application/json" id="ms-engine-routing-data"><?php echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			<noscript><p class="ms-muted"><?php esc_html_e( 'Nécessite JavaScript ; sans cela, utilisez directement le champ « routing » ci-dessous.', 'ms-recipes-writer-ai' ); ?></p></noscript>
		</section>
		<?php
	}

	/**
	 * One model, as the screen has to describe it: name, price, and whether
	 * the provider still answers to it.
	 *
	 * `served` is deliberately three-valued. A provider that has never been
	 * asked says nothing about its models, and reporting silence as "missing"
	 * would send an administrator chasing a model that is perfectly fine.
	 */
	/**
	 * Every step the engine will run, in the order its dependencies allow.
	 *
	 * The registry was readable only as raw JSON in the `steps` override box,
	 * which is empty on a site that has changed nothing — so the one screen
	 * about the engine showed nothing about what the engine actually does.
	 */
	private static function step_registry() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		$steps = (array) $config->steps();
		?>
		<section class="ms-card">
			<h2><?php esc_html_e( 'Ce que fait le moteur, étape par étape', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php echo esc_html( sprintf(
				/* translators: %s is a number of steps. */
				__( 'Le profil complet en compte %s. Une étape part dès que tout ce dont elle dépend est prêt : c’est ce qui découpe une recette en vagues, et ce qui fait qu’une reprise ne repaie pas ce qui a réussi.', 'ms-recipes-writer-ai' ),
				number_format_i18n( count( $steps ) )
			) ); ?></p>
			<table class="ms-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Étape', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attend', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Produit', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $steps as $key => $step ) : ?>
					<?php $route = $config->model_for( $key ); ?>
					<tr>
						<th scope="row">
							<?php echo esc_html( (string) ( $step['label'] ?? $key ) ); ?>
							<br><small class="ms-muted"><?php echo esc_html( (string) ( $step['expects'] ?? '' ) ); ?></small>
						</th>
						<td><?php echo esc_html( $step['needs'] ? implode( ', ', (array) $step['needs'] ) : '—' ); ?></td>
						<td><code class="ms-key"><?php echo esc_html( (string) ( $step['produces'] ?? '' ) ); ?></code></td>
						<td>
							<code class="ms-key"><?php echo esc_html( (string) ( $route['model'] ?? '' ) ); ?></code>
							<br><small class="ms-muted"><?php echo esc_html( (string) ( $route['route'] ?? '' ) ); ?></small>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * The models this plugin knows, beside the ones the provider still serves.
	 *
	 * Two different kinds of knowledge, and confusing them is how a route ends
	 * up naming a model that cannot run: what a model costs is written down
	 * here by hand, and which identifiers answer is only ever known by asking.
	 */
	private static function model_catalogue() {
		$catalog = MSRWA_Catalog::defaults();
		$providers = array( 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude' );
		?>
		<section class="ms-card">
			<h2><?php esc_html_e( 'Modèles et tarifs', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'Les tarifs sont saisis à la main d’après la page de chaque fournisseur : aucune API ne les donne, et une estimation ne vaut que ce qu’ils valent. La liste des identifiants, elle, vient du fournisseur lui-même et se met à jour à chaque « Vérifier les clés ».', 'ms-recipes-writer-ai' ); ?></p>
			<?php foreach ( $providers as $provider => $label ) : ?>
				<?php
				$available = MSRWA_Catalog::available( $provider );
				$at = MSRWA_Catalog::listed_at( $provider );
				?>
				<h3><?php echo esc_html( $label ); ?></h3>
				<p class="ms-muted"><?php echo esc_html( $available
					? sprintf(
						/* translators: 1: number of models, 2: a date and time. */
						__( '%1$d modèle(s) servis d’après le fournisseur, relevés le %2$s.', 'ms-recipes-writer-ai' ),
						count( $available ),
						$at
					)
					: __( 'Jamais interrogé. « Vérifier les clés », dans les réglages, relève la liste sans rien dépenser.', 'ms-recipes-writer-ai' )
				); ?></p>
				<table class="ms-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sait faire', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Entrée / sortie par million', 'ms-recipes-writer-ai' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Servi', 'ms-recipes-writer-ai' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( (array) ( $catalog[ $provider ] ?? array() ) as $id => $model ) : ?>
						<?php
						$can = array();
						foreach ( array( 'text' => __( 'texte', 'ms-recipes-writer-ai' ), 'vision' => __( 'vision', 'ms-recipes-writer-ai' ), 'web_search' => __( 'recherche web', 'ms-recipes-writer-ai' ), 'image_generation' => __( 'génération d’image', 'ms-recipes-writer-ai' ) ) as $capability => $name ) {
							if ( ! empty( $model[ $capability ] ) ) { $can[] = $name; }
						}
						$served = MSRWA_Catalog::served( $provider, (string) $id );
						?>
						<tr>
							<th scope="row">
								<code class="ms-key"><?php echo esc_html( $id ); ?></code>
								<br><small class="ms-muted"><?php echo esc_html( (string) $model['label'] ); ?></small>
							</th>
							<td><?php echo esc_html( implode( ', ', $can ) ); ?></td>
							<td><?php echo esc_html( sprintf( '$%s / $%s', number_format_i18n( (float) ( $model['input'] ?? 0 ), 2 ), number_format_i18n( (float) ( $model['output'] ?? 0 ), 2 ) ) ); ?></td>
							<td><?php
							if ( 'yes' === $served ) {
								echo '<span class="ms-state ms-state-good">' . esc_html__( 'oui', 'ms-recipes-writer-ai' ) . '</span>';
							} elseif ( 'no' === $served ) {
								echo '<span class="ms-state ms-state-stop">' . esc_html__( 'non', 'ms-recipes-writer-ai' ) . '</span>';
							} else {
								echo '<span class="ms-muted">' . esc_html__( 'non vérifié', 'ms-recipes-writer-ai' ) . '</span>';
							}
							?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $available ) : ?>
					<details>
						<summary><?php esc_html_e( 'Tout ce que le fournisseur sert', 'ms-recipes-writer-ai' ); ?></summary>
						<pre class="ms-code"><?php echo esc_html( implode( "\n", $available ) ); ?></pre>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function describe_model( $provider, $model, array $catalog ) {
		$known = $catalog[ $provider ][ $model ] ?? null;
		return array(
			'id' => $model,
			'label' => $known ? (string) $known['label'] : $model,
			'input' => $known ? (float) ( $known['input'] ?? 0 ) : null,
			'output' => $known ? (float) ( $known['output'] ?? 0 ) : null,
			'priced' => (bool) $known,
			'served' => MSRWA_Catalog::served( $provider, $model ),
		);
	}
}
