<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the engine can do, and in what order.
 *
 * One entry per step: what it needs before it can run, what it produces, which
 * budget bucket its cost belongs to, which capability it asks a model for, and
 * the prompt template it is built from. The order here is the order a recipe is
 * made, and `needs` is what lets a caller run part of a pipeline — or run two
 * steps at once when neither waits on the other.
 */
final class MSRWA_Engine_Steps {

	/**
	 * The registry, with the caller's changes applied over it.
	 *
	 * A caller retunes a step by naming only what moves — a label, a bucket, a
	 * dependency, a different prompt file — and adds one by giving a name the
	 * registry does not have. Nothing here is fixed; this is the default.
	 */
	public static function all( $overrides = array() ) {
		$steps = self::shipped();
		foreach ( (array) $overrides as $name => $changes ) {
			if ( ! is_array( $changes ) || '_order' === $name ) { continue; }
			$steps[ $name ] = isset( $steps[ $name ] ) ? array_merge( $steps[ $name ], $changes ) : array_merge( self::blank(), $changes );
		}
		// A step a caller adds lands last unless the caller says where.
		if ( ! empty( $overrides['_order'] ) && is_array( $overrides['_order'] ) ) {
			$ordered = array();
			foreach ( $overrides['_order'] as $name ) { if ( isset( $steps[ $name ] ) ) { $ordered[ $name ] = $steps[ $name ]; } }
			$steps = $ordered + $steps;
		}
		return $steps;
	}

	/** A step a caller adds, before it says anything about it. */
	private static function blank() {
		return array( 'label' => '', 'bucket' => 'other', 'capability' => 'text', 'prompt' => '', 'needs' => array(), 'produces' => '', 'expects' => '' );
	}

	private static function shipped() {
		return array(
			'research' => array(
				'label' => 'Recherche', 'bucket' => 'other', 'capability' => 'web_search', 'prompt' => 'research.tpl.txt',
				'needs' => array(), 'produces' => 'research',
				'expects' => 'the recipe the sources describe, with photographs of it read from their bytes',
			),
			'canonical_recipe' => array(
				'label' => 'Recette canonique', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'canonical_recipe.tpl.txt',
				'needs' => array( 'research' ), 'produces' => 'canonical',
				'expects' => 'a recipe passing MSRWA_Recipe::validate',
			),
			'article' => array(
				'label' => 'Article', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'article.tpl.txt',
				'needs' => array( 'research', 'canonical' ), 'produces' => 'article',
				'expects' => 'an article passing MSRWA_Quality plus the required outline',
			),
			'featured_image' => array(
				'label' => 'Image à la une', 'bucket' => 'featured', 'capability' => 'image_generation', 'prompt' => 'featured_image.tpl.txt',
				'needs' => array( 'research', 'canonical' ), 'produces' => 'featured',
				'expects' => 'a photograph of this dish at the configured size',
			),
			'facebook_image' => array(
				'label' => 'Collage Facebook', 'bucket' => 'facebook', 'capability' => 'image_generation', 'prompt' => 'facebook_image.tpl.txt',
				'needs' => array( 'research', 'canonical' ), 'produces' => 'facebook',
				'expects' => 'a panel collage following the recipe order',
			),
			'review' => array(
				// One reading of the article does the three checks that used to be
				// three calls — the editorial review, the fact check and the
				// proofread — each of which sent the same research, recipe and
				// article again and thought about them from scratch.
				'label' => 'Relecture', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'review.tpl.txt',
				'needs' => array( 'research', 'canonical', 'article' ), 'produces' => 'review',
				'expects' => 'a verdict with findings, the passages the sources contradict and the language changes, each quoted verbatim',
			),
			'corrections' => array(
				// No model runs here. The review returns the sentence it objects to
				// verbatim and the sentence that replaces it, so applying them is a
				// substitution, not a judgement — free, exact, and recorded either way.
				'label' => 'Corrections factuelles', 'bucket' => 'article', 'capability' => 'none', 'prompt' => '',
				'needs' => array( 'article', 'review' ), 'produces' => 'corrected',
				'expects' => 'the article with every verbatim correction applied, and the rest reported',
			),
			'proofread' => array(
				// No model either: the language changes came with the review and are
				// substituted last, on the text the facts have already been fixed in.
				'label' => 'Correction', 'bucket' => 'article', 'capability' => 'none', 'prompt' => '',
				'needs' => array( 'review', 'corrected' ), 'produces' => 'proofread',
				'expects' => 'the same article with its language corrected and every figure untouched',
			),
			'final_approval' => array(
				'label' => 'Approbation finale', 'bucket' => 'other', 'capability' => 'vision', 'prompt' => 'final_approval.tpl.txt',
				// It judges the images alone — the review has held the text to its
				// sources — so it runs beside the review rather than after it.
				'needs' => array( 'research', 'canonical', 'featured', 'facebook' ), 'produces' => 'approval',
				'expects' => 'one decision over both images together',
			),
		);
	}

	/**
	 * The registry when the Facebook collage leads the recipe (ENGINE.md §7, 50).
	 *
	 * `drawn`: the collage is drawn right after the research, freely, and read
	 * panel by panel; the recipe, the article and the featured image then follow
	 * what it shows. `provided`: the editor's own collage stands in for the
	 * drawing (see skipped()) and is read the same way. Anything else leaves
	 * the registry as it is — the recipe first, the images drawn from it.
	 */
	public static function for_lead( array $overrides, $lead ) {
		if ( ! in_array( $lead, array( 'drawn', 'provided' ), true ) ) { return $overrides; }
		$steps = self::all( $overrides );
		$set = static function ( $name, array $changes ) use ( &$overrides ) {
			$overrides[ $name ] = array_merge( (array) ( $overrides[ $name ] ?? array() ), $changes );
		};
		$set( 'facebook_image', array( 'needs' => array( 'research' ) ) );
		$set( 'collage_reading', array(
			'label' => 'Lecture du collage', 'bucket' => 'facebook', 'capability' => 'read', 'prompt' => 'collage_reading.tpl.txt',
			'needs' => array( 'facebook' ), 'produces' => 'collage',
			'expects' => 'what each panel of the collage shows: ingredients, garnish, stages and the finished dish',
		) );
		$with = static function ( $name ) use ( $steps ) { return array_values( array_unique( array_merge( (array) ( $steps[ $name ]['needs'] ?? array() ), array( 'collage' ) ) ) ); };
		foreach ( array( 'canonical_recipe', 'article', 'featured_image' ) as $name ) { $set( $name, array( 'needs' => $with( $name ) ) ); }
		// Registered after the collage and before the recipe, so a list of the
		// steps reads in the order they run.
		$ordered = array();
		foreach ( array_keys( self::all( $overrides ) ) as $name ) {
			if ( 'collage_reading' === $name ) { continue; }
			if ( 'canonical_recipe' === $name ) { $ordered['collage_reading'] = true; }
			$ordered[ $name ] = true;
		}
		$overrides['_order'] = array_keys( $ordered );
		return $overrides;
	}

	/** The steps a lead leaves out: the editor's own collage is not drawn again. */
	public static function skipped( $lead ) { return 'provided' === $lead ? array( 'facebook_image' ) : array(); }

	public static function names( $overrides = array() ) { return array_keys( self::all( $overrides ) ); }

	public static function get( $name, $overrides = array() ) {
		$all = self::all( $overrides );
		return isset( $all[ $name ] ) ? $all[ $name ] : array();
	}

	/** The capability a step asks a model for: text, web_search, image_generation or vision. */
	public static function capability( $name, $overrides = array() ) {
		$step = self::get( $name, $overrides );
		return isset( $step['capability'] ) ? $step['capability'] : 'text';
	}

	/** Which of the four budget buckets a step's cost belongs to. */
	public static function bucket( $name, $overrides = array() ) {
		$step = self::get( $name, $overrides );
		return isset( $step['bucket'] ) ? $step['bucket'] : 'other';
	}

	/** Steps whose inputs all exist, so they may run now — several at once when more than one qualifies. */
	public static function ready( $done, $remaining = null, $overrides = array() ) {
		$remaining = null === $remaining ? self::names( $overrides ) : $remaining;
		$ready = array();
		foreach ( $remaining as $name ) {
			$step = self::get( $name, $overrides );
			if ( ! $step ) { continue; }
			$missing = array_diff( $step['needs'], array_keys( array_filter( $done, static function ( $value ) { return null !== $value && array() !== $value && '' !== $value; } ) ) );
			if ( ! $missing ) { $ready[] = $name; }
		}
		return $ready;
	}

	/** What a step still waits on, for an error a person can act on. */
	public static function missing( $name, $done, $overrides = array() ) {
		$step = self::get( $name, $overrides );
		if ( ! $step ) { return array( 'unknown step' ); }
		return array_values( array_diff( $step['needs'], array_keys( array_filter( $done ) ) ) );
	}
}
