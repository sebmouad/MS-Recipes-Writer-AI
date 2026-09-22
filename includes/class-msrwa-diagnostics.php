<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Everything that has to be true for a recipe to reach a draft, checked now.
 *
 * The plugin already knew all of this, in pieces, on four different screens:
 * whether the schema migrated, whether a key exists, whether the route for a
 * step resolves, whether cron is moving the queue. An operator whose lot is
 * sitting still had to know which of those pieces to go and look at. This
 * answers the question they actually have — *why is nothing happening* — and
 * every answer says what to do about it.
 *
 * Read-only: it calls no provider, spends nothing, and changes nothing.
 */
final class MSRWA_Diagnostics {

	/**
	 * Every check, in the order a run meets them.
	 *
	 * Each is `tone` (`stop`, `warn` or `good`), a `title`, the `detail` that
	 * was measured, and a `remedy` — empty when nothing needs doing. A check
	 * never reports a key, a path or anything a screenshot should not carry.
	 */
	public static function checks() {
		return array(
			self::schema(),
			self::keys(),
			self::routing(),
			self::cron(),
			self::uploads(),
			self::rights(),
			self::budget(),
			self::leftovers(),
		);
	}

	/** The worst tone present, which is what the head should show. */
	public static function worst( array $checks ) {
		foreach ( array( 'stop', 'warn' ) as $tone ) {
			foreach ( $checks as $check ) {
				if ( $tone === $check['tone'] ) { return $tone; }
			}
		}
		return 'good';
	}

	/** The tables the plugin needs, and whether the migration actually ran. */
	private static function schema() {
		$missing = array();
		foreach ( MSRWA_DB::tables() as $name => $table ) {
			if ( ! MSRWA_DB::table_exists( $table ) ) { $missing[] = $name; }
		}
		$stored = (int) get_option( 'msrwa_schema', 0 );

		if ( $missing ) {
			return self::check( 'stop', __( 'Tables', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %s is a comma-separated list of table names. */
				__( 'Il en manque : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', $missing )
			), __( 'Désactivez puis réactivez l’extension : l’installation des tables se rejoue à l’activation.', 'ms-recipes-writer-ai' ) );
		}
		if ( $stored !== MSRWA_DB::SCHEMA ) {
			return self::check( 'warn', __( 'Tables', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: 1: the schema version stored on the site, 2: the one this version expects. */
				__( 'Le schéma enregistré est %1$d ; cette version en attend %2$d.', 'ms-recipes-writer-ai' ),
				$stored,
				MSRWA_DB::SCHEMA
			), __( 'Rechargez n’importe quel écran d’administration : la migration se déclenche au premier chargement après une mise à jour.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Tables', 'ms-recipes-writer-ai' ), sprintf(
			/* translators: %d is a schema version number. */
			__( 'Les six tables sont là, schéma %d.', 'ms-recipes-writer-ai' ),
			MSRWA_DB::SCHEMA
		) );
	}

	/** Whether anything can be written at all. */
	private static function keys() {
		$configured = MSRWA_Settings::configured_providers();
		if ( ! $configured ) {
			return self::check( 'stop', __( 'Clés d’API', 'ms-recipes-writer-ai' ), __( 'Aucune clé enregistrée : aucune recette ne peut partir.', 'ms-recipes-writer-ai' ), __( 'Réglages → Clés d’API, puis « Vérifier les clés ».', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Clés d’API', 'ms-recipes-writer-ai' ), sprintf(
			/* translators: %s is a comma-separated list of provider names. */
			__( 'Enregistrée(s) pour : %s. Une clé enregistrée n’est pas une clé valide — « Vérifier les clés » le dit gratuitement.', 'ms-recipes-writer-ai' ),
			implode( ', ', $configured )
		) );
	}

	/**
	 * Whether each step would actually reach a model.
	 *
	 * A route with no key is the failure that costs the most to discover the
	 * expensive way: the lot runs, pays for every step before it, and stops.
	 */
	private static function routing() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		$keyless = array();
		$unpriced = array();

		foreach ( $config->steps() as $step => $definition ) {
			$capability = (string) ( $definition['capability'] ?? '' );
			if ( 'none' === $capability ) { continue; }
			$route = $config->model_for( MSRWA_Estimate::route_for( $step, $capability ) );
			$provider = $config->provider( $route['provider'], $route['model'] );
			if ( empty( $provider['has_key'] ) ) { $keyless[ $route['provider'] ] = $route['provider']; }
			if ( null === $config->price( $route['provider'], $route['model'], array() ) ) { $unpriced[ $route['model'] ] = $route['model']; }
		}

		if ( $keyless ) {
			return self::check( 'stop', __( 'Routage', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %s is a comma-separated list of provider names. */
				__( 'Des étapes sont routées vers un fournisseur sans clé : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', $keyless )
			), __( 'Enregistrez sa clé dans Réglages, ou changez la route dans Moteur → Modèle par étape.', 'ms-recipes-writer-ai' ) );
		}
		if ( $unpriced ) {
			return self::check( 'warn', __( 'Routage', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %s is a comma-separated list of model names. */
				__( 'Des étapes tournent sur un modèle sans tarif publié : %s. La dépense sera incomplète, jamais fausse.', 'ms-recipes-writer-ai' ),
				implode( ', ', $unpriced )
			), __( 'Ajoutez son tarif dans Moteur → models, ou routez l’étape vers un modèle tarifé.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Routage', 'ms-recipes-writer-ai' ), __( 'Chaque étape a une clé et un tarif publié.', 'ms-recipes-writer-ai' ) );
	}

	/**
	 * Whether anything is moving the queue.
	 *
	 * A held queue is a decision and reads as one. A stalled queue is cron, and
	 * on a site nobody visits that is the usual answer.
	 */
	private static function cron() {
		$state = MSRWA_Queue::state();
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		$server = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( MSRWA_Queue::stalled() ) {
			return self::check( 'stop', __( 'File', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: 1: number of waiting recipes, 2: a duration, e.g. "22 min". */
				__( '%1$d recette(s) attendent depuis %2$s sans que rien n’avance.', 'ms-recipes-writer-ai' ),
				$state['waiting'],
				MSRWA_I18N::seconds( $state['waiting_seconds'] )
			), $server
				? __( 'DISABLE_WP_CRON est actif : vérifiez que le cron du serveur appelle bien wp-cron.php.', 'ms-recipes-writer-ai' )
				: __( 'Le cron de WordPress ne se déclenche qu’à une visite. Ajoutez un cron serveur sur wp-cron.php toutes les cinq minutes.', 'ms-recipes-writer-ai' ) );
		}
		if ( $state['held'] ) {
			return self::check( 'warn', __( 'File', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %d is a number of recipes. */
				__( 'La file est suspendue ; %d recette(s) attendent.', 'ms-recipes-writer-ai' ),
				$state['waiting']
			), __( 'Le pass → « Reprendre la file » quand vous voulez qu’elles repartent.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'File', 'ms-recipes-writer-ai' ), sprintf(
			/* translators: 1: number of running recipes, 2: number waiting, 3: how the cron is driven. */
			__( '%1$d en cours, %2$d en attente ; entretien piloté par %3$s.', 'ms-recipes-writer-ai' ),
			$state['working'],
			$state['waiting'],
			$server ? __( 'le serveur', 'ms-recipes-writer-ai' ) : __( 'WordPress', 'ms-recipes-writer-ai' )
		), $next ? '' : __( 'Aucun entretien n’est planifié : réactivez l’extension pour le réarmer.', 'ms-recipes-writer-ai' ) );
	}

	/** Where a generated image has to be able to land. */
	private static function uploads() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || ! wp_is_writable( $uploads['basedir'] ) ) {
			return self::check( 'stop', __( 'Téléversements', 'ms-recipes-writer-ai' ), __( 'Le dossier des téléversements n’est pas accessible en écriture.', 'ms-recipes-writer-ai' ), __( 'Corrigez les droits du dossier wp-content/uploads ; sans lui, aucune image générée ne peut être enregistrée.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Téléversements', 'ms-recipes-writer-ai' ), __( 'Le dossier est accessible en écriture.', 'ms-recipes-writer-ai' ) );
	}

	/** Whether the roles still carry what activation granted them. */
	private static function rights() {
		$short = array();
		foreach ( MSRWA_Rights::roles() as $name => $capabilities ) {
			$role = get_role( $name );
			if ( ! $role ) { continue; }
			foreach ( $capabilities as $capability ) {
				if ( ! $role->has_cap( $capability ) ) { $short[] = $name; break; }
			}
		}
		if ( $short ) {
			return self::check( 'warn', __( 'Droits', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %s is a comma-separated list of role names. */
				__( 'Des rôles ont perdu une capacité : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', array_unique( $short ) )
			), __( 'Réactivez l’extension : les capacités sont accordées depuis une seule liste, à l’activation comme à la mise à jour.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Droits', 'ms-recipes-writer-ai' ), __( 'Chaque rôle porte les capacités que l’activation accorde.', 'ms-recipes-writer-ai' ) );
	}

	/** Whether a ceiling is currently refusing work. */
	private static function budget() {
		$refusal = MSRWA_Budget::refusal();
		if ( '' !== $refusal ) {
			return self::check( 'warn', __( 'Plafonds', 'ms-recipes-writer-ai' ), $refusal, __( 'Réglages → Plafonds de dépense, ou attendez la fenêtre suivante.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Plafonds', 'ms-recipes-writer-ai' ), __( 'Aucun plafond n’arrête de travail en ce moment.', 'ms-recipes-writer-ai' ) );
	}

	/** Tables from the plugin that stood here before this one. */
	private static function leftovers() {
		$dormant = MSRWA_DB::dormant();
		if ( $dormant ) {
			return self::check( 'warn', __( 'Restes', 'ms-recipes-writer-ai' ), sprintf(
				/* translators: %s is a comma-separated list of table names. */
				__( 'Des tables d’une version précédente occupent encore la base : %s.', 'ms-recipes-writer-ai' ),
				implode( ', ', $dormant )
			), __( 'Plus rien ne les lit. Elles ne sont jamais supprimées d’ici : c’est à vous, une fois la sauvegarde faite.', 'ms-recipes-writer-ai' ) );
		}
		return self::check( 'good', __( 'Restes', 'ms-recipes-writer-ai' ), __( 'Aucune table d’une version précédente.', 'ms-recipes-writer-ai' ) );
	}

	private static function check( $tone, $title, $detail, $remedy = '' ) {
		return array( 'tone' => $tone, 'title' => $title, 'detail' => $detail, 'remedy' => $remedy );
	}

	/**
	 * The same findings as one block of text, for pasting into a support thread.
	 *
	 * Carries versions and states, never a key, a path or a site URL: somebody
	 * pasting this into a forum should not have to read it first.
	 */
	public static function report( array $checks ) {
		global $wp_version;
		$lines = array(
			'MS Recipes Writer AI ' . MSRWA_VERSION,
			'WordPress ' . ( isset( $wp_version ) ? $wp_version : '?' ) . ' · PHP ' . PHP_VERSION . ' · schema ' . (int) get_option( 'msrwa_schema', 0 ),
		);
		foreach ( $checks as $check ) {
			$lines[] = strtoupper( $check['tone'] ) . ' · ' . $check['title'] . ' · ' . $check['detail'];
		}
		return implode( "\n", $lines );
	}
}
