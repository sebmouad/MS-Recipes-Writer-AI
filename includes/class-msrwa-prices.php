<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What a model costs, looked up because no provider will tell you.
 *
 * Every provider publishes its rates on a web page and none of them exposes
 * one through an API — checked against the live model listings of all three,
 * which carry capabilities and token limits and not a single price field. So
 * a rate is either typed by a person or read off those pages, and reading
 * pages is something a model with web search does well.
 *
 * It is done as a lookup and never as a fact. The answer is stored with the
 * page it came from and marked `ai`, it never overwrites a rate a person
 * entered, and anything outside the range real rates occupy is refused rather
 * than believed — a hallucinated number that looks authoritative is worse
 * than an empty cell, because an empty cell stops the estimate and a wrong
 * one quietly bills against it.
 */
final class MSRWA_Prices {

	/** Dollars per million tokens. Outside this, the answer is not a rate. */
	const FLOOR = 0.001;
	const CEILING = 1000.0;

	/**
	 * Looks up every model that has no price a person stands behind.
	 *
	 * Returns what it did, per model, so the screen can show the reasoning
	 * rather than a spinner that ends in a number nobody can account for.
	 */
	public static function lookup( array $only = array() ) {
		$wanted = self::wanted( $only );
		if ( ! $wanted ) { return array( 'asked' => 0, 'found' => 0, 'results' => array() ); }

		$route = self::route();
		if ( ! $route ) {
			return array( 'asked' => 0, 'found' => 0, 'error' => __( 'Aucun modèle n’est routé pour faire cette recherche. Enregistrez une clé d’abord.', 'ms-recipes-writer-ai' ), 'results' => array() );
		}

		// The wire has to be the configured one. Left out, the call builds its
		// own from the engine's bare defaults, which carry no keys — and the
		// lookup fails with "No API key" on a site that has three.
		$answer = MSRWA_Engine_Call::text(
			$route['provider'],
			$route['model'],
			self::prompt( $wanted ),
			4000,
			true,
			true,
			$route['wire']
		);
		if ( ! empty( $answer['error'] ) ) {
			return array( 'asked' => count( $wanted ), 'found' => 0, 'error' => MSRWA_DB::sanitize( (string) $answer['error'] ), 'results' => array() );
		}

		return self::apply( $wanted, MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) ) );
	}

	/**
	 * The models worth asking about: served, enabled, and not already carrying
	 * a rate somebody is answerable for.
	 */
	public static function wanted( array $only = array() ) {
		$out = array();
		foreach ( MSRWA_Catalog::rows() as $row ) {
			$key = $row['provider'] . ':' . $row['model_id'];
			if ( $only && ! in_array( $key, $only, true ) ) { continue; }
			if ( ! $only ) {
				// A rate a person typed is never replaced by a guess, and a
				// model the provider does not serve is not worth the tokens.
				if ( MSRWA_Catalog::MANUAL === $row['price_method'] ) { continue; }
				if ( false === $row['served'] ) { continue; }
				if ( null !== $row['input_usd'] && null !== $row['output_usd'] ) { continue; }
			}
			$out[ $key ] = $row;
		}
		return $out;
	}

	/** The step that does the looking, on whatever this site has a key for. */
	private static function route() {
		$config = MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		// Research is the step already routed at a model with web search, so
		// the lookup rides the site's own choice rather than inventing one.
		$route = $config->model_for( 'research' );
		$wire = $config->provider( $route['provider'], $route['model'] );
		if ( empty( $wire['has_key'] ) ) { return null; }
		return $route + array( 'wire' => $wire );
	}

	/** Asked for one thing at a time, in the words the answer must come back in. */
	public static function prompt( array $wanted ) {
		$lines = array();
		foreach ( $wanted as $key => $row ) { $lines[] = '- ' . $key; }

		return "Find the current published API price of each model listed below.\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. "Each line is `provider:model_id`. Use the provider's own published pricing page and no other source.\n\n"
			. "Report USD per ONE MILLION tokens, standard (non-batch, non-cached) rates.\n"
			. "If a provider quotes per 1,000 tokens, multiply by 1000.\n\n"
			. "Return JSON only, of this shape:\n"
			. '{"prices":[{"key":"provider:model_id","input":0.00,"output":0.00,"source":"https://..."}]}' . "\n\n"
			. "Rules that matter more than completeness:\n"
			. "- Omit any model whose price you cannot find on the provider's own page. A missing entry is correct; a guessed one is not.\n"
			. "- `source` must be the exact page you read the number from.\n"
			. "- Never infer a price from a similarly named model.";
	}

	/**
	 * Stores what came back, refusing anything that is not a plausible rate.
	 *
	 * A model asked about a price it cannot find will sometimes answer with a
	 * confident number anyway. The shape is checked, the range is checked, and
	 * the source has to be a real URL; what fails is reported rather than
	 * silently dropped, so the owner can see the lookup was attempted.
	 */
	public static function apply( array $wanted, $answer ) {
		$results = array();
		$found = 0;
		$quoted = array();
		foreach ( (array) ( is_array( $answer ) ? ( $answer['prices'] ?? array() ) : array() ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['key'] ) ) { $quoted[ (string) $entry['key'] ] = $entry; }
		}

		foreach ( $wanted as $key => $row ) {
			$entry = $quoted[ $key ] ?? null;
			if ( ! $entry ) {
				$results[ $key ] = array( 'state' => 'missing', 'why' => __( 'aucun tarif trouvé sur la page du fournisseur', 'ms-recipes-writer-ai' ) );
				continue;
			}
			$input = self::rate( $entry['input'] ?? null );
			$output = self::rate( $entry['output'] ?? null );
			$source = esc_url_raw( (string) ( $entry['source'] ?? '' ) );
			if ( null === $input || null === $output ) {
				$results[ $key ] = array( 'state' => 'refused', 'why' => __( 'la réponse n’est pas un tarif plausible', 'ms-recipes-writer-ai' ) );
				continue;
			}
			if ( '' === $source ) {
				// Without the page it was read from, nobody can check it, and a
				// rate nobody can check is a rate nobody should bill on.
				$results[ $key ] = array( 'state' => 'refused', 'why' => __( 'aucune page citée', 'ms-recipes-writer-ai' ) );
				continue;
			}
			MSRWA_Catalog::remember_price( $row['provider'], $row['model_id'], $input, $output, MSRWA_Catalog::LOOKED_UP, $source );
			$results[ $key ] = array( 'state' => 'found', 'input' => $input, 'output' => $output, 'source' => $source );
			$found++;
		}
		return array( 'asked' => count( $wanted ), 'found' => $found, 'results' => $results );
	}

	/** A number that could be a published rate, or null. */
	private static function rate( $value ) {
		if ( ! is_numeric( $value ) ) { return null; }
		$value = (float) $value;
		return $value >= self::FLOOR && $value <= self::CEILING ? $value : null;
	}
}
