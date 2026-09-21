<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What a batch asks the engine to produce, and in which language.
 *
 * Both are expressed as the engine's own caller configuration — a list of steps
 * to run and an override or two — so nothing inside `includes/engine/` knows
 * these choices exist. The engine ships one pipeline; this decides how much of
 * it a given batch pays for.
 */
final class MSRWA_Profile {

	const FULL = 'full';
	const FEATURED = 'featured';
	const ARTICLE = 'article';

	/**
	 * The three shapes of output, most complete first.
	 *
	 * The final judge is the only place the article and both images are checked
	 * against one another, and the engine asks it for both images by name. So it
	 * runs for the full profile and not for the others — not as a saving, but
	 * because with one image or none there is nothing for it to compare. The
	 * text checks — review, fact check, proofreading — run in every profile.
	 */
	public static function all() {
		return array(
			self::FULL => array(
				'label' => __( 'Article, image à la une et collage Facebook', 'ms-recipes-writer-ai' ),
				'description' => __( 'La chaîne complète, jugement final compris : c’est le seul moment où le texte et les deux images sont confrontés.', 'ms-recipes-writer-ai' ),
				'drop' => array(),
				'estimate_usd' => 0.12,
			),
			self::FEATURED => array(
				'label' => __( 'Article et image à la une', 'ms-recipes-writer-ai' ),
				'description' => __( 'Pas de collage. Le jugement final ne s’exécute pas : il confronte le texte aux deux images, et il n’y en a qu’une.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'facebook_image', 'final_approval' ),
				'estimate_usd' => 0.08,
			),
			self::ARTICLE => array(
				'label' => __( 'Article seul', 'ms-recipes-writer-ai' ),
				'description' => __( 'Texte uniquement, relu et vérifié. Aucune image n’est générée.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'featured_image', 'facebook_image', 'final_approval' ),
				'estimate_usd' => 0.05,
			),
		);
	}

	public static function exists( $profile ) { return array_key_exists( (string) $profile, self::all() ); }

	public static function get( $profile ) {
		$all = self::all();
		return $all[ self::exists( $profile ) ? $profile : self::FULL ];
	}

	/** The languages an article may be written in. */
	public static function languages() {
		return array(
			'fr' => __( 'Français', 'ms-recipes-writer-ai' ),
			'en' => __( 'Anglais', 'ms-recipes-writer-ai' ),
			'ar' => __( 'Arabe', 'ms-recipes-writer-ai' ),
		);
	}

	public static function language_exists( $language ) { return array_key_exists( (string) $language, self::languages() ); }

	/** Which steps this profile runs, in the engine's own order. */
	public static function steps( $profile, array $registry = array() ) {
		$drop = self::get( $profile )['drop'];
		return array_values( array_diff( MSRWA_Engine_Steps::names( $registry ), $drop ) );
	}

	/**
	 * The engine configuration this profile implies.
	 *
	 * A step that is not run produces no artifact, so anything that declared a
	 * need on it would wait for something that is never coming. Rather than
	 * guess which, every remaining step has the dropped artifacts removed from
	 * its `needs` — which is exactly what the engine's step registry is
	 * overridable for.
	 */
	public static function config( $profile, $language, array $registry = array() ) {
		$drop = self::get( $profile )['drop'];
		if ( ! $drop ) { return self::language_config( $language ); }

		$produces = array();
		foreach ( $drop as $name ) {
			$step = MSRWA_Engine_Steps::get( $name, $registry );
			if ( ! empty( $step['produces'] ) ) { $produces[] = $step['produces']; }
		}

		$steps = array();
		foreach ( self::steps( $profile, $registry ) as $name ) {
			$step = MSRWA_Engine_Steps::get( $name, $registry );
			$needs = array_values( array_diff( (array) ( $step['needs'] ?? array() ), $produces ) );
			if ( $needs !== (array) ( $step['needs'] ?? array() ) ) { $steps[ $name ] = array( 'needs' => $needs ); }
		}

		return array_merge( self::language_config( $language ), $steps ? array( 'steps' => $steps ) : array() );
	}

	private static function language_config( $language ) {
		return self::language_exists( $language ) ? array( 'language' => (string) $language ) : array();
	}

	/** A rough figure to show before anything is spent. It is never an invoice. */
	public static function estimate( $profile, $recipes ) {
		return round( (float) self::get( $profile )['estimate_usd'] * max( 1, (int) $recipes ), 2 );
	}
}
