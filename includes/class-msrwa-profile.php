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
	 * The final judge sees the article beside whatever images were produced, so
	 * it runs wherever there is an image to look at. With no image at all there
	 * is nothing for it to do that the text checks — review, fact check,
	 * proofreading — do not already do, so the article-only profile skips it and
	 * saves the call. Those text checks run in every profile.
	 */
	public static function all() {
		return array(
			self::FULL => array(
				'label' => __( 'Article, image à la une et collage Facebook', 'ms-recipes-writer-ai' ),
				'description' => __( 'La chaîne complète, jugement final compris : c’est le seul moment où le texte et les deux images sont confrontés.', 'ms-recipes-writer-ai' ),
				'drop' => array(),
			),
			self::FEATURED => array(
				'label' => __( 'Article et image à la une', 'ms-recipes-writer-ai' ),
				'description' => __( 'Pas de collage. Le jugement final s’exécute quand même et confronte le texte à l’image produite ; il n’a simplement pas de collage à comparer.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'facebook_image' ),
			),
			self::ARTICLE => array(
				'label' => __( 'Article seul', 'ms-recipes-writer-ai' ),
				'description' => __( 'Texte uniquement, relu et vérifié. Aucune image n’est générée, et le jugement final n’aurait rien à regarder.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'featured_image', 'facebook_image', 'final_approval' ),
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

}
