<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every engine parameter, editable from WordPress.
 *
 * The engine holds no constant a caller cannot replace — routing, ceilings,
 * attempts, prices, prompts, the step registry itself — and this is where an
 * administrator replaces them. What is stored is only the difference: the keys
 * actually changed, nothing else, so an engine that gains a default later
 * gains it here too instead of being pinned to whatever was current the day
 * somebody pressed save.
 *
 * The API keys are not edited here. They live in the existing configuration
 * screen, encrypted, and reach the engine by another road.
 */
final class MSRWA_Lab_Config {

	const OPTION = 'msrwa_engine_config';

	/** Groups a person tunes by hand, against the free-form JSON for the rest. */
	private static function simple() {
		return array(
			'routing'     => 'Quel fournisseur et quel niveau pour chaque étape, sous la forme `fournisseur:niveau` ou `fournisseur:modèle`.',
			'max_output'  => 'Plafond de tokens en sortie, par étape. Une réponse coupée est facturée entière.',
			'attempts'    => 'Nombre de tentatives, par étape.',
			'limits'      => 'Budget, délais, concurrence, taille des images inspectées.',
			'images'      => 'Format, qualité, dimensions et nombre de panneaux du collage.',
			'thresholds'  => 'Les seuils au-dessus desquels une étape est considérée réussie.',
		);
	}

	/** Groups that are structures rather than settings, edited as JSON. */
	private static function structural() {
		return array(
			'providers' => 'Points d’entrée, en-têtes et nom de la variable d’environnement par fournisseur.',
			'models'    => 'Tarifs par million de tokens : `[entrée, sortie]`. Un modèle sans tarif rend le run invérifiable et l’arrête.',
			'tiers'     => 'Quel modèle répond derrière `bas`, `moyen` et `haut`.',
			'steps'     => 'Le registre des étapes : dépendances, capacité, gabarit, poste de dépense.',
			'prompts'   => 'Gabarits de prompt qui remplacent ceux livrés avec le moteur.',
			'observation_fields' => 'Les champs d’observation qui atteignent un prompt d’image.',
		);
	}

	/** What an administrator has overridden, as the engine's caller layer. */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The defaults as they stand today, for the page to show beside each field.
	 * Keys and secrets are already stripped by the engine's own record shape.
	 */
	public static function defaults() {
		return MSRWA_Engine_Config::create()->to_array();
	}

	public static function menu() {
		add_submenu_page( 'ms-recipes-writer-ai', 'Moteur', 'Moteur', 'manage_options', 'ms-recipes-writer-ai-engine', array( __CLASS__, 'page' ) );
	}

	public static function hooks() {
		add_action( 'admin_post_msrwa_save_engine', array( __CLASS__, 'save' ) );
	}

	/**
	 * Stores only what differs from the engine's defaults.
	 *
	 * A value equal to the default is removed rather than written, so the day
	 * the engine changes its mind about a ceiling the site follows unless
	 * somebody deliberately disagreed.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		check_admin_referer( 'msrwa_save_engine' );

		$raw = isset( $_POST['msrwa_engine'] ) ? wp_unslash( $_POST['msrwa_engine'] ) : array();
		$defaults = self::defaults();
		$config = array();
		$errors = array();

		foreach ( array_keys( array_merge( self::simple(), self::structural() ) ) as $group ) {
			$text = trim( (string) ( $raw[ $group ] ?? '' ) );
			if ( '' === $text ) { continue; }
			$value = json_decode( $text, true );
			if ( ! is_array( $value ) ) { $errors[] = $group; continue; }
			$difference = self::difference( $value, (array) ( $defaults[ $group ] ?? array() ) );
			if ( $difference ) { $config[ $group ] = $difference; }
		}

		$language = sanitize_text_field( (string) ( $raw['language'] ?? '' ) );
		if ( '' !== $language && $language !== (string) ( $defaults['language'] ?? '' ) ) { $config['language'] = $language; }

		update_option( self::OPTION, $config, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ms-recipes-writer-ai-engine', 'saved' => 1, 'invalid' => implode( ',', $errors ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** What in $value is not already what the engine would have done. */
	private static function difference( array $value, array $default ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( ! array_key_exists( $key, $default ) ) { $out[ $key ] = $item; continue; }
			if ( is_array( $item ) && is_array( $default[ $key ] ) ) {
				$nested = self::difference( $item, $default[ $key ] );
				if ( $nested ) { $out[ $key ] = $nested; }
				continue;
			}
			if ( $item !== $default[ $key ] ) { $out[ $key ] = $item; }
		}
		return $out;
	}

	private static function encode( $value ) {
		return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Accès refusé.', 'ms-recipes-writer-ai' ) ); }
		$defaults = self::defaults();
		$stored = self::stored();
		$invalid = isset( $_GET['invalid'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ) ) ) ) : array();
		?>
		<div class="wrap msrwa-wrap">
			<h1>Moteur</h1>
			<p class="description">Tout ce que le moteur utilise est modifiable ici. Ce qui est enregistré n’est que la différence : un champ laissé tel quel suit la valeur par défaut du moteur, y compris quand celle-ci change.</p>

			<?php if ( isset( $_GET['saved'] ) && ! $invalid ) : ?>
				<div class="notice notice-success"><p>Configuration enregistrée.</p></div>
			<?php endif; ?>
			<?php if ( $invalid ) : ?>
				<div class="notice notice-error"><p>JSON invalide, ignoré pour : <?php echo esc_html( implode( ', ', $invalid ) ); ?>.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="msrwa_save_engine">
				<?php wp_nonce_field( 'msrwa_save_engine' ); ?>

				<div class="msrwa-card">
					<h2>Langue</h2>
					<p><input type="text" name="msrwa_engine[language]" value="<?php echo esc_attr( $stored['language'] ?? $defaults['language'] ); ?>" class="small-text"> <span class="description">Par défaut : <code><?php echo esc_html( $defaults['language'] ); ?></code></span></p>
				</div>

				<?php foreach ( array( 'Réglages' => self::simple(), 'Structures' => self::structural() ) as $section => $groups ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<?php foreach ( $groups as $group => $help ) : ?>
						<?php $effective = isset( $stored[ $group ] ) ? self::merge( (array) ( $defaults[ $group ] ?? array() ), (array) $stored[ $group ] ) : (array) ( $defaults[ $group ] ?? array() ); ?>
						<div class="msrwa-card">
							<h3><?php echo esc_html( $group ); ?><?php if ( isset( $stored[ $group ] ) ) : ?> <span class="description">— modifié</span><?php endif; ?></h3>
							<p class="description"><?php echo esc_html( $help ); ?></p>
							<p><textarea name="msrwa_engine[<?php echo esc_attr( $group ); ?>]" rows="<?php echo esc_attr( min( 24, max( 6, substr_count( self::encode( $effective ), "\n" ) + 1 ) ) ); ?>" class="large-text code" spellcheck="false"><?php echo esc_textarea( self::encode( $effective ) ); ?></textarea></p>
							<details><summary>Valeur par défaut du moteur</summary><pre class="code"><?php echo esc_html( self::encode( $defaults[ $group ] ?? array() ) ); ?></pre></details>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>

				<?php submit_button( 'Enregistrer la configuration du moteur' ); ?>
			</form>

			<div class="msrwa-card">
				<h2>Ce qui est réellement enregistré</h2>
				<p class="description">La couche « appelant » que le moteur reçoit. Vide signifie : tout est par défaut.</p>
				<pre class="code"><?php echo esc_html( self::encode( $stored ) ); ?></pre>
			</div>
		</div>
		<?php
	}

	/** Deep merge, so a stored override shows in context rather than alone. */
	private static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}
}
