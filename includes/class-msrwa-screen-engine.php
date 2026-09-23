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
		// What a person actually typed, which is what "modified" means here.
		// The models and tiers the catalogue generates are also part of the
		// caller layer, but nobody edited them on this screen and badging them
		// as changed would send the owner looking for an edit they never made.
		$stored = MSRWA_Engine_Settings::typed();
		$invalid = isset( $_GET['invalid'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ) ) ) ) : array();
		$encode = static function ( $value ) { return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); };

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Moteur', 'ms-recipes-writer-ai' ),
			__( 'Rien n’est figé dans le moteur : routage, plafonds, tentatives, tarifs, gabarits, registre des étapes. Seule la différence avec ses valeurs par défaut est conservée.', 'ms-recipes-writer-ai' ),
			array( __( 'groupes modifiés', 'ms-recipes-writer-ai' ) => number_format_i18n( count( $stored ) ) )
		);

		$refused = get_transient( 'msrwa_engine_refused_' . get_current_user_id() );
		if ( is_array( $refused ) && $refused ) {
			delete_transient( 'msrwa_engine_refused_' . get_current_user_id() );
			MSRWA_UI::note( esc_html( sprintf(
				/* translators: %s lists each refused step, its route and the reason. */
				__( 'Rien n’a été enregistré : une étape était confiée à un modèle qui ne peut pas la faire. %s', 'ms-recipes-writer-ai' ),
				MSRWA_Compat::describe( $refused )
			) ), 'stop' );
		} elseif ( ! empty( $_GET['saved'] ) && ! $invalid ) { MSRWA_UI::note( esc_html__( 'Enregistré.', 'ms-recipes-writer-ai' ) ); }
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
				// The picker above is the everyday control; the raw groups are for
				// the rare hand edit, so they stay folded unless one was changed.
				$open = (bool) array_intersect_key( $groups, $stored );
				?>
				<details class="ms-advanced"<?php echo $open ? ' open' : ''; ?>>
				<summary><h2><?php echo esc_html( $section ); ?></h2>
					<span class="ms-muted"><?php echo esc_html( sprintf(
						/* translators: %d is a number of configuration groups. */
						_n( '%d groupe en JSON, pour une modification à la main', '%d groupes en JSON, pour une modification à la main', count( $groups ), 'ms-recipes-writer-ai' ),
						count( $groups )
					) ); ?></span>
				</summary>
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
				</details>
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


		<details class="ms-advanced">
			<summary><h2><?php esc_html_e( 'Ce qui est réellement transmis au moteur', 'ms-recipes-writer-ai' ); ?></h2>
				<span class="ms-muted"><?php esc_html_e( 'pour un dépannage', 'ms-recipes-writer-ai' ); ?></span>
			</summary>
			<section class="ms-card">
				<p><?php esc_html_e( 'La couche appelante, telle quelle : ce qui a été saisi ici, plus les modèles et les niveaux que la page Modèles engendre. Vide signifie que tout suit le moteur.', 'ms-recipes-writer-ai' ); ?></p>
				<pre class="ms-code"><?php echo esc_html( $encode( MSRWA_Engine_Settings::stored() ) ); ?></pre>
			</section>
		</details>
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
		// Over the catalogue, like everything the engine is handed: built on
		// the typed settings alone, the picker named the engine's own tiers and
		// called every model the catalogue priced unpriced.
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored() );
		$catalog = MSRWA_Catalog::defaults();
		$providers = array(
			'openai' => __( 'OpenAI', 'ms-recipes-writer-ai' ),
			'gemini' => __( 'Google Gemini', 'ms-recipes-writer-ai' ),
			'claude' => __( 'Anthropic Claude', 'ms-recipes-writer-ai' ),
		);
		$steps = $config->steps();

		// The two images are chosen apart: each is judged on its own, and a
		// collage can need a stronger model than a single photograph.
		$routing_keys = array();
		foreach ( array_keys( (array) $defaults['routing'] ) as $key ) {
			if ( 'image' === $key ) { $routing_keys[] = 'featured_image'; $routing_keys[] = 'facebook_image'; continue; }
			$routing_keys[] = $key;
		}
		$keys = array();
		foreach ( $routing_keys as $key ) {
			if ( isset( $steps[ $key ] ) ) {
				// The screen's own words, translated, rather than the engine's
				// registry labels, which are French whatever the admin speaks.
				$keys[ $key ] = MSRWA_UI::step_name( $key ) !== $key ? MSRWA_UI::step_name( $key ) : (string) ( $steps[ $key ]['label'] ?? $key );
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
		// Every image model the catalogue knows, fetched or shipped, and only
		// those the engine can draw with: OpenAI's images endpoint.
		$image_models = array();
		$labels = array();
		foreach ( MSRWA_Catalog::rows() as $row ) {
			if ( 'openai' !== $row['provider'] || 'image' !== MSRWA_Catalog::role( $row['provider'], $row['model_id'] ) || false === $row['served'] ) { continue; }
			$labels[ $row['provider'] . ':' . $row['model_id'] ] = (string) $row['label'];
		}
		foreach ( $catalog as $provider => $models ) {
			foreach ( $models as $model => $info ) {
				if ( ! empty( $info['image_generation'] ) && ! isset( $labels[ $provider . ':' . $model ] ) ) { $labels[ $provider . ':' . $model ] = (string) $info['label']; }
			}
		}
		foreach ( $labels as $value => $label ) {
			list( $provider ) = explode( ':', $value, 2 );
			$image_models[] = array( 'value' => $value, 'label' => ( $providers[ $provider ] ?? $provider ) . ' — ' . $label );
		}

		$current = array();
		foreach ( array_keys( $keys ) as $key ) {
			$current[ $key ] = $config->model_for( in_array( $key, array( 'featured_image', 'facebook_image' ), true ) ? $config->image_route( $key ) : $key )['route'];
		}
		$quality = array(
			'featured_image' => (string) $config->get( 'images.featured_quality', 'medium' ),
			'facebook_image' => (string) $config->get( 'images.facebook_quality', 'medium' ),
		);

		// What each choice actually resolves to. A provider and a quality name
		// are not an answer to "which model will run and what will it cost" —
		// the tier map turns them into a model identifier, and until this was
		// shown, choosing "low" picked a model nobody on this screen could
		// name, two of which this plugin has no price for and one of which its
		// provider does not serve at all.
		$resolved = array();
		// The engine's own price list, which is the one it bills against. The
		// plugin ships a second, shorter list in MSRWA_Catalog for the models
		// it documents; reading that one here reported four perfectly priced
		// models as unpriced.
		$prices = (array) $config->get( 'models', array() );
		$tiers = (array) $config->get( 'tiers', array() );
		foreach ( $tiers as $tier => $by_provider ) {
			foreach ( (array) $by_provider as $provider => $model ) {
				$resolved[ $provider . ':' . $tier ] = self::describe_model( $provider, (string) $model, $prices );
			}
		}
		foreach ( $image_models as $entry ) {
			list( $provider, $model ) = array_pad( explode( ':', $entry['value'], 2 ), 2, '' );
			$resolved[ $entry['value'] ] = self::describe_model( $provider, $model, $prices );
		}

		// Each step's choices that resolve to a model unable to serve it, with
		// the reason. The picker disables them; saving refuses them anyway.
		$blocked = array();
		foreach ( array_keys( $keys ) as $key ) {
			if ( in_array( $key, array( 'featured_image', 'facebook_image' ), true ) ) {
				foreach ( $image_models as $entry ) {
					list( $provider, $model ) = array_pad( explode( ':', $entry['value'], 2 ), 2, '' );
					$why = MSRWA_Compat::refusal( $provider, $model, $key );
					if ( '' !== $why ) { $blocked[ $key ][ $entry['value'] ] = $why; }
				}
				continue;
			}
			foreach ( $tiers as $tier => $by_provider ) {
				foreach ( (array) $by_provider as $provider => $model ) {
					$why = MSRWA_Compat::refusal( $provider, (string) $model, $key );
					if ( '' !== $why ) { $blocked[ $key ][ $provider . ':' . $tier ] = $why; }
				}
			}
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
			'blocked' => $blocked,
			'thinking' => array_map( 'strval', (array) $config->get( 'thinking', array() ) ),
			'imageQuality' => $quality,
			'qualities' => array_values( array_diff( MSRWA_Images::qualities(), array( 'auto' ) ) ),
			'thinkingLevels' => MSRWA_Engine_Config::thinking_levels(),
			// The tiers, the thinking levels and the image qualities share the
			// words low, medium and high; shown raw side by side they read as
			// the same setting twice. Each is named for what it decides.
			'tierNames' => array(
				'low' => __( 'économique', 'ms-recipes-writer-ai' ),
				'medium' => __( 'standard', 'ms-recipes-writer-ai' ),
				'high' => __( 'avancé', 'ms-recipes-writer-ai' ),
			),
			'thinkingNames' => array(
				'minimal' => __( 'minimale', 'ms-recipes-writer-ai' ),
				'low' => __( 'légère', 'ms-recipes-writer-ai' ),
				'medium' => __( 'moyenne', 'ms-recipes-writer-ai' ),
				'high' => __( 'poussée', 'ms-recipes-writer-ai' ),
			),
			'qualityNames' => array(
				'low' => __( 'basse', 'ms-recipes-writer-ai' ),
				'medium' => __( 'moyenne', 'ms-recipes-writer-ai' ),
				'high' => __( 'haute', 'ms-recipes-writer-ai' ),
				'xhigh' => __( 'très haute', 'ms-recipes-writer-ai' ),
				'max' => __( 'maximale', 'ms-recipes-writer-ai' ),
			),
			'labels' => array(
				'step' => __( 'Étape', 'ms-recipes-writer-ai' ),
				'model' => __( 'Modèle', 'ms-recipes-writer-ai' ),
				'thinking' => __( 'Réflexion', 'ms-recipes-writer-ai' ),
				'quality' => __( 'Qualité de l’image', 'ms-recipes-writer-ai' ),
				'provider' => __( 'Fournisseur', 'ms-recipes-writer-ai' ),
				'thinkingDefault' => __( 'réglage du fournisseur', 'ms-recipes-writer-ai' ),
				/* translators: %s is a thinking level such as "moyenne". */
				'thinkingSite' => __( 'par défaut (%s)', 'ms-recipes-writer-ai' ),
				/* translators: %s is a level or model name. */
				'blockedOption' => __( '%s — ne convient pas à cette étape', 'ms-recipes-writer-ai' ),
				'unpriced' => __( 'aucun tarif connu — le coût de cette étape ne peut pas être estimé', 'ms-recipes-writer-ai' ),
				'unserved' => __( 'le fournisseur ne sert pas ce nom : l’étape échouera', 'ms-recipes-writer-ai' ),
				'unknown' => __( 'jamais vérifié auprès du fournisseur', 'ms-recipes-writer-ai' ),
				'perMillion' => __( '$%1$s entrée / $%2$s sortie par million de jetons', 'ms-recipes-writer-ai' ),
			),
		);
		?>
		<section class="ms-card" id="ms-engine-routing-picker">
			<h2><?php esc_html_e( 'Modèle par étape', 'ms-recipes-writer-ai' ); ?></h2>
			<dl class="ms-route-help">
				<div><dt><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></dt><dd><?php esc_html_e( 'Qui fait le travail, et donc le prix de chaque jeton. Économique, standard et avancé désignent un modèle précis chez chaque fournisseur.', 'ms-recipes-writer-ai' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Réflexion', 'ms-recipes-writer-ai' ); ?></dt><dd><?php esc_html_e( 'Combien ce modèle réfléchit avant de répondre. Plus il réfléchit, plus il consomme de jetons facturés — sans changer de modèle.', 'ms-recipes-writer-ai' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Qualité de l’image', 'ms-recipes-writer-ai' ); ?></dt><dd><?php esc_html_e( 'Pour les deux images, qui ne réfléchissent pas : le niveau de détail du rendu, qui en fixe le prix.', 'ms-recipes-writer-ai' ); ?></dd></div>
			</dl>
			<table class="ms-table ms-routing-picker ms-stack">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Étape', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Réflexion ou qualité', 'ms-recipes-writer-ai' ); ?></th>
				</tr></thead>
				<tbody></tbody>
			</table>
			<script type="application/json" id="ms-engine-routing-data"><?php echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
			<p class="ms-muted"><small><?php esc_html_e( 'Chaque changement réécrit les champs « routing », « thinking » et « images » des réglages plus bas ; ce qui y est modifié à la main prévaut après un chargement de page.', 'ms-recipes-writer-ai' ); ?></small></p>
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
			<table class="ms-table ms-stack ms-registry">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Étape', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attend', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Produit', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modèle', 'ms-recipes-writer-ai' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Limites', 'ms-recipes-writer-ai' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $steps as $key => $step ) : ?>
					<?php
					// An image step is routed on its own key — or the shared `image`
					// one — and a step that asks no model has no model to show.
					$capability = MSRWA_Engine_Steps::capability( $key, $steps );
					$route = 'none' === $capability ? array() : $config->model_for( 'image_generation' === $capability ? $config->image_route( $key ) : $key );
					$has_key = ! empty( $route['model'] ) && ! empty( $config->provider( $route['provider'], $route['model'] )['has_key'] );
					$priced = ! empty( $route['model'] ) && null !== $config->price( $route['provider'], $route['model'], array() );
					?>
					<tr>
						<th scope="row">
							<?php echo esc_html( MSRWA_UI::step_name( $key ) !== $key ? MSRWA_UI::step_name( $key ) : (string) ( $step['label'] ?? $key ) ); ?>
							<br><small class="ms-muted"><?php echo esc_html( (string) ( $step['expects'] ?? '' ) ); ?></small>
						</th>
						<td data-label="<?php esc_attr_e( 'Attend', 'ms-recipes-writer-ai' ); ?>"><?php echo esc_html( $step['needs'] ? implode( ', ', (array) $step['needs'] ) : '—' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Produit', 'ms-recipes-writer-ai' ); ?>"><code class="ms-key"><?php echo esc_html( (string) ( $step['produces'] ?? '' ) ); ?></code></td>
						<td data-label="<?php esc_attr_e( 'Modèle', 'ms-recipes-writer-ai' ); ?>">
							<?php if ( empty( $route['model'] ) ) : ?>
								<span class="ms-muted"><?php esc_html_e( 'aucun — appliqué en code', 'ms-recipes-writer-ai' ); ?></span>
							<?php else : ?>
								<code class="ms-key"><?php echo esc_html( (string) $route['model'] ); ?></code>
								<small class="ms-muted"><?php echo esc_html( (string) ( $route['route'] ?? '' ) ); ?></small>
								<?php if ( ! $has_key ) : ?><small class="ms-stop"><?php esc_html_e( 'aucune clé pour ce fournisseur', 'ms-recipes-writer-ai' ); ?></small><?php endif; ?>
								<?php if ( ! $priced ) : ?><small class="ms-warn"><?php esc_html_e( 'aucun tarif connu', 'ms-recipes-writer-ai' ); ?></small><?php endif; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Limites', 'ms-recipes-writer-ai' ); ?>">
							<?php if ( ! empty( $route['model'] ) ) : ?>
								<?php echo esc_html( sprintf(
									/* translators: %s is a number of tokens. */
									__( '%s jetons en sortie', 'ms-recipes-writer-ai' ),
									number_format_i18n( $config->max_output( $key ) )
								) ); ?>
								<small class="ms-muted"><?php echo esc_html( sprintf(
									/* translators: %s is a number of attempts. */
									_n( '%s tentative', '%s tentatives', $config->attempts( $key ), 'ms-recipes-writer-ai' ),
									number_format_i18n( $config->attempts( $key ) )
								) ); ?></small>
							<?php else : ?>—<?php endif; ?>
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
				<table class="ms-table ms-stack">
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
							<td data-label="<?php esc_attr_e( 'Sait faire', 'ms-recipes-writer-ai' ); ?>"><?php echo esc_html( implode( ', ', $can ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Entrée / sortie par million', 'ms-recipes-writer-ai' ); ?>" class="ms-num-cell"><?php echo esc_html( sprintf( '$%s / $%s', number_format_i18n( (float) ( $model['input'] ?? 0 ), 2 ), number_format_i18n( (float) ( $model['output'] ?? 0 ), 2 ) ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Servi', 'ms-recipes-writer-ai' ); ?>"><?php
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

	private static function describe_model( $provider, $model, array $prices ) {
		$rate = $prices[ $provider ][ $model ] ?? null;
		$priced = is_array( $rate ) && isset( $rate[0], $rate[1] );
		$shipped = MSRWA_Catalog::defaults()[ $provider ][ $model ]['label'] ?? '';
		$row = '' === $shipped ? MSRWA_Catalog::row( $provider, $model ) : null;
		return array(
			'id' => $model,
			'label' => '' !== $shipped ? (string) $shipped : (string) ( $row['label'] ?? $model ),
			'input' => $priced ? (float) $rate[0] : null,
			'output' => $priced ? (float) $rate[1] : null,
			'priced' => $priced,
			'served' => MSRWA_Catalog::served( $provider, $model ),
		);
	}
}
