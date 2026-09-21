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

	public static function all() {
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
				'label' => 'Revue éditoriale', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'review.tpl.txt',
				'needs' => array( 'research', 'canonical', 'article' ), 'produces' => 'review',
				'expects' => 'a research-grounded verdict and findings naming the section',
			),
			'fact_check' => array(
				'label' => 'Vérification des faits', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'fact_check.tpl.txt',
				'needs' => array( 'research', 'canonical', 'article' ), 'produces' => 'fact_check',
				'expects' => 'only the passages the sources contradict, quoted verbatim',
			),
			'corrections' => array(
				// No model runs here. The fact check returns the sentence it objects to
				// verbatim and the sentence that replaces it, so applying them is a
				// substitution, not a judgement — free, exact, and recorded either way.
				'label' => 'Corrections factuelles', 'bucket' => 'article', 'capability' => 'none', 'prompt' => '',
				'needs' => array( 'article', 'review', 'fact_check' ), 'produces' => 'corrected',
				'expects' => 'the article with every verbatim correction applied, and the rest reported',
			),
			'proofread' => array(
				'label' => 'Correction', 'bucket' => 'article', 'capability' => 'text', 'prompt' => 'proofread.tpl.txt',
				// Language is corrected last, on the text the facts have already been fixed in.
				'needs' => array( 'corrected' ), 'produces' => 'proofread',
				'expects' => 'the same article with its language corrected and every figure untouched',
			),
			'final_approval' => array(
				'label' => 'Approbation finale', 'bucket' => 'other', 'capability' => 'vision', 'prompt' => 'final_approval.tpl.txt',
				// The proofread article is what a reader gets, so it is what gets judged.
				'needs' => array( 'research', 'canonical', 'proofread', 'featured', 'facebook' ), 'produces' => 'approval',
				'expects' => 'one decision over the article and both images together',
			),
		);
	}

	public static function names() { return array_keys( self::all() ); }

	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : array();
	}

	/** The capability a step asks a model for: text, web_search, image_generation or vision. */
	public static function capability( $name ) {
		$step = self::get( $name );
		return isset( $step['capability'] ) ? $step['capability'] : 'text';
	}

	/** Which of the four budget buckets a step's cost belongs to. */
	public static function bucket( $name ) {
		$step = self::get( $name );
		return isset( $step['bucket'] ) ? $step['bucket'] : 'other';
	}

	/** Steps whose inputs all exist, so they may run now — several at once when more than one qualifies. */
	public static function ready( $done, $remaining = null ) {
		$remaining = null === $remaining ? self::names() : $remaining;
		$ready = array();
		foreach ( $remaining as $name ) {
			$step = self::get( $name );
			if ( ! $step ) { continue; }
			$missing = array_diff( $step['needs'], array_keys( array_filter( $done, static function ( $value ) { return null !== $value && array() !== $value && '' !== $value; } ) ) );
			if ( ! $missing ) { $ready[] = $name; }
		}
		return $ready;
	}

	/** What a step still waits on, for an error a person can act on. */
	public static function missing( $name, $done ) {
		$step = self::get( $name );
		if ( ! $step ) { return array( 'unknown step' ); }
		return array_values( array_diff( $step['needs'], array_keys( array_filter( $done ) ) ) );
	}
}
