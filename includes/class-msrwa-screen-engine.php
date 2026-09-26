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

			<?php
			// One language for every lot, the site's: a lot no longer picks its
			// own, and the engine's `language` key was a second answer nobody
			// read. This saves the same setting as the Réglages screen.
			$site_language = (string) MSRWA_Settings::get()['site_language'];
			?>
			<section class="ms-card ms-language-card">
				<h2><label for="ms-site-language-engine"><?php esc_html_e( 'Langue des articles', 'ms-recipes-writer-ai' ); ?></label></h2>
				<p><?php esc_html_e( 'Chaque lot est écrit dans cette langue : recherche, recette, article, contrôles et relecture. Le même réglage figure sur l’écran Réglages.', 'ms-recipes-writer-ai' ); ?></p>
				<div class="ms-language-pick">
					<select id="ms-site-language-engine" name="msrwa_site_language">
						<?php foreach ( MSRWA_Profile::languages() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $site_language, $code ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="ms-muted"><?php esc_html_e( 'Les sections exigées, le titre de la page deux et la balise de langue suivent ce choix.', 'ms-recipes-writer-ai' ); ?></span>
				</div>
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
		<section class="ms-card">
			<h2><?php esc_html_e( 'Modèles et tarifs', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php esc_html_e( 'Les modèles proposés ci-dessus sont ceux de l’écran Modèles : ceux qui y sont activés, avec leurs tarifs et les étapes qu’ils ont le droit de servir. Un modèle désactivé ou retiré d’une étape là-bas est grisé ici, avec la raison.', 'ms-recipes-writer-ai' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=msrwa-models' ) ); ?>"><?php esc_html_e( 'Ouvrir l’écran Modèles', 'ms-recipes-writer-ai' ); ?></a></p>
		</section>


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
		$providers = array();
		foreach ( array( 'openai', 'gemini', 'claude' ) as $provider ) { $providers[ $provider ] = MSRWA_UI::provider_name( $provider ); }

		// The same steps, the same candidates and the same refusals as the
		// Modèles screen: both read MSRWA_Compat, so a model switched off or
		// taken off a step there is greyed here with that reason, and nothing
		// is offered here that the Modèles screen does not list.
		$keys = array();
		$choices = array();
		$current = array();
		$resolved = array();
		$blocked = array();
		// The engine's own price list, which is the one it bills against.
		$prices = (array) $config->get( 'models', array() );
		foreach ( MSRWA_Compat::steps() as $key => $step ) {
			$keys[ $key ] = $step['label'];
			$route = $config->model_for( $step['image'] ? $config->image_route( $key ) : $key );
			$current[ $key ] = (string) $route['route'];
			foreach ( MSRWA_Compat::choices( $key, $config ) as $provider => $list ) {
				foreach ( $list as $choice ) {
					$choices[ $key ][ $provider ][] = array( 'value' => $choice['value'], 'tier' => $choice['tier'] );
					$resolved[ $choice['value'] ] = self::describe_model( $provider, $choice['model'], $prices );
					if ( '' !== $choice['blocked'] ) { $blocked[ $key ][ $choice['value'] ] = $choice['blocked']; }
				}
			}
			// A route naming something no longer offered stays on screen, with
			// why it cannot run, rather than silently turning into another choice.
			if ( ! isset( $resolved[ $current[ $key ] ] ) && '' !== (string) $route['model'] ) {
				$resolved[ $current[ $key ] ] = self::describe_model( (string) $route['provider'], (string) $route['model'], $prices );
			}
			$why = MSRWA_Compat::refusal( (string) $route['provider'], (string) $route['model'], $key );
			if ( '' !== $why ) { $blocked[ $key ][ $current[ $key ] ] = $why; }
		}
		$quality = array(
			'featured_image' => (string) $config->get( 'images.featured_quality', 'medium' ),
			'facebook_image' => (string) $config->get( 'images.facebook_quality', 'medium' ),
		);

		$data = array(
			'presets' => self::presets( $config, $choices, $current ),
			'providers' => $providers,
			'choices' => $choices,
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
				'low' => MSRWA_UI::tier_name( 'low' ),
				'medium' => MSRWA_UI::tier_name( 'medium' ),
				'high' => MSRWA_UI::tier_name( 'high' ),
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
				'custom' => __( 'Personnalisé', 'ms-recipes-writer-ai' ),
				/* translators: %s is the per-recipe ceiling. */
				'overCeiling' => sprintf( __( 'Au-dessus du plafond de %s par recette : les lots seraient refusés tant qu’il n’est pas relevé dans les Réglages.', 'ms-recipes-writer-ai' ), MSRWA_I18N::money( (float) MSRWA_Settings::get()['per_recipe_budget_usd'], 2 ) ),
				/* translators: %s is an estimated amount per recipe. */
				'perRecipe' => __( '≈ %s par recette', 'ms-recipes-writer-ai' ),
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
			<fieldset class="ms-presets" id="ms-presets">
				<legend><?php esc_html_e( 'Qualité du contenu', 'ms-recipes-writer-ai' ); ?></legend>
				<p class="ms-muted"><?php esc_html_e( 'Choisit d’un coup le modèle, la réflexion et la qualité d’image de chaque étape. Changer une étape à la main fait passer à « Personnalisé ».', 'ms-recipes-writer-ai' ); ?></p>
				<div class="ms-preset-row"></div>
			</fieldset>
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
	 * Every step the engine will run, in the order its dependencies allow.
	 *
	 * The registry was readable only as raw JSON in the `steps` override box,
	 * which is empty on a site that has changed nothing — so the one screen
	 * about the engine showed nothing about what the engine actually does.
	 */
	private static function step_registry() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		// A complete lot as it runs today: the collage drawn and read first.
		$steps = MSRWA_Engine_Steps::all( MSRWA_Engine_Steps::for_lead( (array) $config->get( 'steps', array() ), 'drawn' ) );
		?>
		<section class="ms-card">
			<h2><?php esc_html_e( 'Ce que fait le moteur, étape par étape', 'ms-recipes-writer-ai' ); ?></h2>
			<p><?php echo esc_html( sprintf(
				/* translators: %s is a number of steps. */
				__( 'Un lot complet en compte %s, dans l’ordre où elles tournent. Une étape part dès que tout ce dont elle dépend est prêt : c’est ce qui découpe une recette en vagues, et ce qui fait qu’une reprise ne repaie pas ce qui a réussi.', 'ms-recipes-writer-ai' ),
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
					$route = 'none' === $capability ? array() : $config->model_for( MSRWA_Estimate::route_for( $key, $capability, $config ) );
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
	 * Three whole configurations, each one choice: the model, the thinking and
	 * the image quality of every step, for a content quality. Built from the
	 * same candidates and refusals as the rows below, so a preset never picks
	 * what a row would grey out: research stays on the standard model in the
	 * economy preset, because the economy model is measured unfit for it.
	 */
	private static function presets( MSRWA_Engine_Config $config, array $choices, array $current ) {
		$defaults = MSRWA_Engine_Config::defaults();
		$shipped_thinking = (array) ( $defaults['thinking'] ?? array() );
		// Measured 2026-09-25: the cheapest model is unfit for every text step
		// (MSRWA_Compat::unfit()), and less thinking on the review loses a third
		// of its corrections. What economy can give up is image quality and some
		// thinking where it was not missed; what premium can buy within a sane
		// ceiling is thinking and image quality — the top model on the article
		// and review alone costs $0.35 a recipe.
		$plans = array(
			'economy' => array( 'tier' => 'low', 'thinking' => array( 'research' => 'low', 'final_approval' => 'low' ), 'featured' => 'low', 'facebook' => 'low',
				'label' => __( 'Économique', 'ms-recipes-writer-ai' ),
				'note' => __( 'Les mêmes modèles là où les moins chers ont été mesurés insuffisants, un peu moins de réflexion pour la recherche et le contrôle final, les deux images en qualité basse.', 'ms-recipes-writer-ai' ) ),
			'standard' => array( 'tier' => 'medium', 'thinking' => array(), 'featured' => (string) ( $defaults['images']['featured_quality'] ?? 'low' ), 'facebook' => (string) ( $defaults['images']['facebook_quality'] ?? 'medium' ),
				'label' => __( 'Standard', 'ms-recipes-writer-ai' ),
				'note' => __( 'Les réglages livrés, mesurés : chaque recette approuvée, le meilleur rapport qualité-prix.', 'ms-recipes-writer-ai' ) ),
			'premium' => array( 'tier' => 'medium', 'thinking' => array( 'research' => 'high', 'review' => 'high', 'final_approval' => 'high' ), 'featured' => 'medium', 'facebook' => 'high',
				'label' => __( 'Premium', 'ms-recipes-writer-ai' ),
				'note' => __( 'Plus de réflexion pour la recherche, la relecture et le contrôle final, l’image à la une en qualité moyenne et le collage en haute qualité. Les modèles les plus chers coûteraient quatre fois plus pour un article à peine différent.', 'ms-recipes-writer-ai' ) ),
		);
		$out = array();
		foreach ( $plans as $name => $plan ) {
			$routing = array();
			$thinking = array();
			foreach ( $current as $key => $route ) {
				if ( in_array( $key, array( 'featured_image', 'facebook_image' ), true ) ) {
					$routing[ $key ] = (string) ( $defaults['routing']['image'] ?? $route );
					continue;
				}
				$provider = (string) strtok( (string) ( $defaults['routing'][ $key ] ?? 'openai:medium' ), ':' );
				$offered = array();
				foreach ( (array) ( $choices[ $key ][ $provider ] ?? array() ) as $choice ) { if ( '' === $choice['tier'] || isset( $offered[ $choice['tier'] ] ) ) { continue; } $offered[ $choice['tier'] ] = $choice['value']; }
				$tiers = (array) $config->get( 'tiers', array() );
				$blocked = static function ( $value ) use ( $tiers, $key, $provider ) {
					$named = (string) substr( (string) $value, strlen( $provider ) + 1 );
					$model = (string) ( $tiers[ $named ][ $provider ] ?? $named );
					return '' !== MSRWA_Compat::refusal( $provider, $model, $key );
				};
				$pick = '';
				foreach ( array( $plan['tier'], 'medium' ) as $tier ) {
					if ( isset( $offered[ $tier ] ) && ! $blocked( $offered[ $tier ] ) ) { $pick = $offered[ $tier ]; break; }
				}
				$routing[ $key ] = '' !== $pick ? $pick : (string) ( $defaults['routing'][ $key ] ?? $route );
				$thinking[ $key ] = (string) ( $plan['thinking'][ $key ] ?? $shipped_thinking[ $key ] ?? '' );
			}
			$quality = array( 'featured_image' => $plan['featured'], 'facebook_image' => $plan['facebook'] );
			$overrides = array(
				'routing' => $routing,
				'thinking' => array_merge( array( 'default' => '' ), $thinking ),
				'images' => array( 'featured_quality' => $plan['featured'], 'facebook_quality' => $plan['facebook'] ),
			);
			$estimate = MSRWA_Estimate::recipe_on( MSRWA_Engine_Config::create( MSRWA_Engine_Settings::merge( MSRWA_Engine_Settings::stored(), $overrides ) ), MSRWA_Profile::FULL );
			$out[ $name ] = array(
				'label' => $plan['label'], 'note' => $plan['note'],
				'routing' => $routing, 'thinking' => $thinking, 'quality' => $quality,
				'cost' => $estimate['unpriced'] ? null : MSRWA_I18N::money( (float) $estimate['cost_usd'], 3 ),
				// A preset that costs more than the ceiling would have every lot refused.
				'over' => ! $estimate['unpriced'] && ! MSRWA_Estimate::fits( (float) $estimate['cost_usd'], (float) MSRWA_Settings::get()['per_recipe_budget_usd'] ),
			);
		}
		return $out;
	}

	/**
	 * One model, as the screen has to describe it: name, price, and whether
	 * the provider still answers to it.
	 *
	 * `served` is deliberately three-valued. A provider that has never been
	 * asked says nothing about its models, and reporting silence as "missing"
	 * would send an administrator chasing a model that is perfectly fine.
	 */
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
