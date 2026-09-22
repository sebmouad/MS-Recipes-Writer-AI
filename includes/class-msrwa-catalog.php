<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What each model costs and what it can do — and what the provider still serves.
 *
 * Two halves that must not be confused. `defaults()` is shipped knowledge: no
 * API tells you a price or whether a model may be trusted for an article, so
 * it is written down here and corrected by hand against the sources each entry
 * names. `available()` is the opposite: only the provider knows which
 * identifiers still answer, and that changes without warning when a model is
 * renamed or retired, so it is asked for and cached rather than guessed.
 *
 * An earlier generation of this plugin kept both in `msrwa_providers` and
 * `msrwa_models` tables. Those tables are superseded and no longer created, so
 * everything that read them — a whole installer, an eligibility filter and a
 * status store — was answering from a table that does not exist. What is left
 * here is what runs.
 */
final class MSRWA_Catalog {

	/** Where the live listing is kept: small, per-provider, and safe to lose. */
	const LISTED = 'msrwa_provider_models';

	public static function defaults() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 5.00, 'image_input' => 8.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
			),
			'gemini' => array(
				'gemini-3.1-flash-image' => array( 'label' => 'Gemini 3.1 Flash Image', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'image_tokens' => array( '1024x1024' => array( 'low' => 272, 'medium' => 1056, 'high' => 4160 ), '1024x1536' => array( 'low' => 408, 'medium' => 1584, 'high' => 6240 ), '1536x1024' => array( 'low' => 400, 'medium' => 1568, 'high' => 6208 ) ), 'input' => 0.50, 'output' => 3.00, 'source' => 'https://ai.google.dev/gemini-api/docs/image-generation' ),
				'gemini-3.5-flash' => array( 'label' => 'Gemini 3.5 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.50, 'output' => 9.00, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
			),
			'claude' => array(
				'claude-sonnet-5' => array( 'label' => 'Claude Sonnet 5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 10.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
				'claude-haiku-4-5-20251001' => array( 'label' => 'Claude Haiku 4.5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.00, 'output' => 5.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
			),
		);
	}

	/**
	 * The catalogue the rest of the plugin prices against.
	 *
	 * Kept as a method rather than reaching for `defaults()` everywhere, because
	 * a site's own corrections belong here when they are reintroduced, and
	 * because the tests double this one name.
	 */
	public static function models( $include_disabled = false ) {
		unset( $include_disabled );
		return self::defaults();
	}

	/**
	 * What the provider itself last said it serves.
	 *
	 * The key check already downloads this list and used to throw it away,
	 * reading only the status code. It is the one thing the provider knows and
	 * this plugin cannot, so it is kept: a route to a name the provider no
	 * longer answers to can then be caught on a screen instead of in the middle
	 * of a paid lot.
	 *
	 * An empty list is never recorded. It means the fetch failed or the shape
	 * changed, and forgetting every model is far worse than holding a stale
	 * list — which is why what is stored carries the moment it was taken.
	 */
	public static function remember_models( $provider, array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		if ( ! $ids ) { return false; }
		$all = (array) get_option( self::LISTED, array() );
		$all[ sanitize_key( (string) $provider ) ] = array( 'ids' => $ids, 'listed_at' => current_time( 'mysql', true ) );
		return (bool) update_option( self::LISTED, $all, false );
	}

	/** Model identifiers this site last saw a provider offer, newest first call wins. */
	public static function available( $provider ) {
		$all = (array) get_option( self::LISTED, array() );
		$ids = $all[ sanitize_key( (string) $provider ) ]['ids'] ?? array();
		return is_array( $ids ) ? $ids : array();
	}

	/** When that listing was taken, or '' if the provider was never asked. */
	public static function listed_at( $provider ) {
		$all = (array) get_option( self::LISTED, array() );
		return (string) ( $all[ sanitize_key( (string) $provider ) ]['listed_at'] ?? '' );
	}

	/**
	 * Whether one model is known to be served, as a word rather than a boolean.
	 *
	 * "Unknown" is a real answer and must not collapse into "no": a provider
	 * that has never been asked, or could not be reached, says nothing about
	 * its models, and reporting that as missing would send somebody chasing a
	 * model that is perfectly fine.
	 */
	public static function served( $provider, $model ) {
		$ids = self::available( $provider );
		if ( ! $ids ) { return 'unknown'; }
		return in_array( (string) $model, $ids, true ) ? 'yes' : 'no';
	}
}
