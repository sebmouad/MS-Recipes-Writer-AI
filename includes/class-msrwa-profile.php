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
				'description' => __( 'La chaîne complète : le collage Facebook d’abord — le vôtre, ou dessiné librement après la recherche —, puis la recette et l’article qui le suivent et l’image à la une du même plat. Le texte est relu et vérifié, les deux images jugées ensemble. Une image refusée vous est signalée, et vous décidez de la faire redessiner ; votre propre collage ne l’est jamais.', 'ms-recipes-writer-ai' ),
				'drop' => array(),
			),
			self::FEATURED => array(
				'label' => __( 'Article et image à la une', 'ms-recipes-writer-ai' ),
				'description' => __( 'Aucun collage n’est dessiné ; le vôtre, s’il est joint, est publié tel quel et sert de référence. Le texte est relu et vérifié, et l’image à la une est jugée face à la recette.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'facebook_image' ),
			),
			self::ARTICLE => array(
				'label' => __( 'Article seul', 'ms-recipes-writer-ai' ),
				'description' => __( 'Texte uniquement, relu et vérifié. Aucune image n’est générée, et le jugement final n’aurait rien à regarder.', 'ms-recipes-writer-ai' ),
				'drop' => array( 'featured_image', 'facebook_image', 'final_approval' ),
			),
		);
	}

	/** The kind of lot in two or three words, for a row that has no room for the sentence. */
	public static function short( $profile ) {
		$short = array(
			self::FULL => __( 'Complet', 'ms-recipes-writer-ai' ),
			self::FEATURED => __( 'Article et image', 'ms-recipes-writer-ai' ),
			self::ARTICLE => __( 'Article seul', 'ms-recipes-writer-ai' ),
		);
		return $short[ (string) $profile ] ?? '';
	}

	public static function exists( $profile ) { return array_key_exists( (string) $profile, self::all() ); }

	/** The three kinds of user a lot type is set for, in the settings. */
	public static function users() {
		return array(
			'writer' => __( 'Rédacteur', 'ms-recipes-writer-ai' ),
			'editor' => __( 'Éditeur', 'ms-recipes-writer-ai' ),
			'admin' => __( 'Administrateur', 'ms-recipes-writer-ai' ),
		);
	}

	/**
	 * Which of those a user is: an administrator manages the plugin, an editor
	 * can edit others' posts, a writer is anybody else allowed to send a lot.
	 */
	public static function user_kind( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( user_can( $user_id, MSRWA_Rights::MANAGE ) ) { return 'admin'; }
		return user_can( $user_id, 'edit_others_posts' ) ? 'editor' : 'writer';
	}

	/**
	 * What a lot sent by this user produces: an administrator's choice in the
	 * settings, one per kind of user. A lot used to pick its own, which put a
	 * decision about cost and output in front of everyone who sent one.
	 */
	public static function for_user( $user_id = 0 ) {
		$profile = (string) ( ( (array) ( MSRWA_Settings::get()['lot_profiles'] ?? array() ) )[ self::user_kind( $user_id ) ] ?? self::FULL );
		return self::exists( $profile ) ? $profile : self::FULL;
	}

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
			'es' => __( 'Espagnol', 'ms-recipes-writer-ai' ),
		);
	}

	public static function language_exists( $language ) { return array_key_exists( (string) $language, self::languages() ); }

	/** Which steps this profile runs, in the engine's own order. */
	public static function steps( $profile, array $registry = array() ) {
		$drop = self::get( $profile )['drop'];
		return array_values( array_diff( MSRWA_Engine_Steps::names( $registry ), $drop ) );
	}

	/**
	 * The steps one recipe runs: its lot's profile, in the order its collage
	 * lead sets — drawn first, or the editor's own collage in place of the
	 * drawing (ENGINE.md §7, 50).
	 */
	public static function run_steps( $profile, array $registry, $lead ) {
		$led = MSRWA_Engine_Steps::for_lead( $registry, (string) $lead );
		return array_values( array_diff( self::steps( $profile, $led ), MSRWA_Engine_Steps::skipped( (string) $lead ) ) );
	}

	/**
	 * Which collage leads a recipe: the editor's own when one of its
	 * photographs is a collage they kept ticked (any profile with images), a
	 * collage drawn first on a complete lot, and none otherwise.
	 */
	public static function lead( $profile, $provided ) {
		if ( $provided && self::ARTICLE !== $profile ) { return 'provided'; }
		return self::FULL === $profile ? 'drawn' : '';
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

	/** The heading that opens page two, in each shipped language. */
	public static function page_two_headings() {
		return array(
			'fr' => 'Préparation de la recette étape par étape',
			'en' => 'Step-by-step preparation',
			'ar' => 'طريقة التحضير خطوة بخطوة',
			'es' => 'Preparación de la receta paso a paso',
		);
	}

	public static function page_two_heading( $language ) {
		$headings = self::page_two_headings();
		return $headings[ (string) $language ] ?? $headings['fr'];
	}

	/**
	 * The article language, where the engine's shared classes read it.
	 *
	 * The prompts take their language from `settings.site_language`; the
	 * engine's top-level `language` key is recorded but read by nothing, so a
	 * lot asked for in English or Arabic was written in French. And the article
	 * and proofreading scorecards measure French accents, which an English or
	 * Arabic text rightly has none of: for those the accent checks stand down
	 * rather than fail a correct article.
	 */
	private static function language_config( $language ) {
		if ( ! self::language_exists( $language ) ) { return array(); }
		$config = array( 'language' => (string) $language, 'settings' => array( 'site_language' => (string) $language ) );
		// The heading that opens page two is text in some language. A heading the
		// site wrote itself is kept for lots in the site's language; a shipped
		// one, or any heading on a lot in another language, becomes that
		// language's own — or an English article turns its page in French.
		$site = class_exists( 'MSRWA_Settings' ) ? (array) MSRWA_Settings::get() : array();
		$heading = (string) ( $site['article_page2_heading'] ?? '' );
		$shipped = in_array( $heading, self::page_two_headings(), true );
		if ( (string) ( $site['site_language'] ?? 'fr' ) !== (string) $language || ( $shipped && self::page_two_heading( $language ) !== $heading ) ) {
			$config['settings']['article_page2_heading'] = self::page_two_heading( $language );
		}
		if ( 'fr' !== $language ) {
			$config['thresholds'] = array( 'article_accents_per_1000' => 0, 'proofread_accent_slack' => 1000 );
		}
		return $config;
	}

}
