<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Every engine parameter, editable from WordPress.
 *
 * The engine holds no constant a caller cannot replace — routing, ceilings,
 * attempts, prices, prompts, the step registry itself. This is where an
 * administrator replaces them, and it changes nothing inside the engine: what
 * is stored here is handed over as the engine's own caller layer.
 *
 * Only the difference from the defaults is kept. An engine that changes its
 * mind about a ceiling later is followed, except where somebody deliberately
 * disagreed.
 */
final class MSRWA_Engine_Settings {

	const OPTION = 'msrwa_engine_config';

	/** Groups a person tunes by hand. */
	public static function simple() {
		return array(
			'routing'    => 'Quel fournisseur et quel niveau pour chaque étape, sous la forme `fournisseur:niveau` ou `fournisseur:modèle`.',
			'max_output' => 'Plafond de tokens en sortie, par étape. Une réponse coupée est facturée entière.',
			'attempts'   => 'Nombre de tentatives, par étape.',
			'limits'     => 'Délais, concurrence, taille des images inspectées. Le budget vient du lot.',
			'images'     => 'Format, qualité, dimensions et nombre de panneaux du collage.',
			'thresholds' => 'Les seuils au-dessus desquels une étape est considérée réussie.',
		);
	}

	/** Groups that are structures rather than settings, edited as JSON. */
	public static function structural() {
		return array(
			'providers' => 'Points d’entrée, en-têtes et variable d’environnement par fournisseur.',
			'models'    => 'Tarifs par million de tokens : `[entrée, sortie]`. Un modèle sans tarif rend la dépense invérifiable et arrête le run.',
			'tiers'     => 'Quel modèle répond derrière `low`, `medium` et `high`.',
			'steps'     => 'Le registre des étapes : dépendances, capacité, gabarit, poste de dépense.',
			'prompts'   => 'Gabarits de prompt qui remplacent ceux livrés avec le moteur.',
			'observation_fields' => 'Les champs d’observation qui atteignent un prompt d’image.',
		);
	}

	/**
	 * The caller layer the engine is handed.
	 *
	 * What the owner typed on the Moteur screen, over the models and tiers the
	 * catalogue generates. The catalogue comes first so an explicit override
	 * still wins — somebody who edits `tiers` by hand means it — but on every
	 * site that has not, the model map is data in a table rather than a
	 * constant in the engine. That is what lets a provider rename a model
	 * without anybody touching the process that writes an article.
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! class_exists( 'MSRWA_Catalog' ) ) { return $stored; }

		foreach ( MSRWA_Catalog::for_engine() as $group => $value ) {
			if ( $value && ! isset( $stored[ $group ] ) ) { $stored[ $group ] = $value; }
		}
		return $stored;
	}

	/** Only what a person actually saved, for the screen that edits it. */
	public static function typed() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** The defaults as they stand today, with keys and secrets already stripped. */
	public static function defaults() {
		return MSRWA_Engine_Config::create()->to_array();
	}

	/** The effective value of one group, for a screen that shows it in context. */
	public static function effective( $group ) {
		$effective = MSRWA_Engine_Config::create( self::stored() )->to_array();
		return (array) ( $effective[ $group ] ?? array() );
	}

	/**
	 * Stores only what differs from the engine's defaults. Returns the groups
	 * whose JSON could not be read. A malformed submission is rejected as a
	 * whole rather than silently dropping any previously saved settings.
	 */
	public static function save( array $raw ) {
		$parsed = self::parse( $raw );
		if ( ! $parsed['invalid'] ) { update_option( self::OPTION, $parsed['config'], false ); }
		return $parsed['invalid'];
	}

	/** Shared by saving and the non-mutating diagnostic preview. */
	public static function parse( array $raw ) {
		$defaults = self::defaults();
		$config = array();
		$invalid = array();

		foreach ( array_keys( array_merge( self::simple(), self::structural() ) ) as $group ) {
			if ( isset( $raw[ $group ] ) && ! is_string( $raw[ $group ] ) ) { $invalid[] = $group; continue; }
			$text = trim( (string) ( $raw[ $group ] ?? '' ) );
			if ( '' === $text ) { continue; }
			$value = json_decode( $text, true );
			if ( ! is_array( $value ) ) { $invalid[] = $group; continue; }
			$difference = self::difference( $value, (array) ( $defaults[ $group ] ?? array() ) );
			if ( $difference ) { $config[ $group ] = $difference; }
		}

		$language = sanitize_text_field( (string) ( $raw['language'] ?? '' ) );
		if ( '' !== $language && $language !== (string) ( $defaults['language'] ?? '' ) ) { $config['language'] = $language; }

		return array( 'config' => $config, 'invalid' => $invalid );
	}

	/** What in $value is not already what the engine would have done. */
	public static function difference( array $value, array $default ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( ! array_key_exists( $key, $default ) ) { $out[ $key ] = $item; continue; }
			if ( is_array( $item ) && is_array( $default[ $key ] ) ) {
				if ( array() === $item || array_keys( $item ) === range( 0, count( $item ) - 1 ) ) {
					if ( $item !== $default[ $key ] ) { $out[ $key ] = $item; }
					continue;
				}
				$nested = self::difference( $item, $default[ $key ] );
				if ( $nested ) { $out[ $key ] = $nested; }
				continue;
			}
			if ( $item !== $default[ $key ] ) { $out[ $key ] = $item; }
		}
		return $out;
	}

	/**
	 * One configuration laid over another, branch by branch.
	 *
	 * The inverse of difference(), and the operation every caller performs to
	 * put a lot's overrides over the site's: it lived twice, identically, in
	 * MSRWA_Estimate and MSRWA_Batch, which is one copy too many for a
	 * recursive function that decides what the engine is handed.
	 */
	public static function merge( array $base, array $over ) {
		foreach ( $over as $key => $value ) {
			$base[ $key ] = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

}
