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

	/** How many refusals one lookup accepts before it gives up. */
	const ATTEMPTS = 8;

	/** Each provider's own pricing page: the only source a rate may cite. */
	public static function pages() {
		return array(
			// OpenAI's pricing page shows only its flagship models until a script
			// runs; each model's own page carries its rate in the markup.
			'openai' => array( 'url' => 'https://developers.openai.com/api/docs/pricing', 'model_url' => 'https://developers.openai.com/api/docs/models/%s', 'hosts' => array( 'openai.com' ) ),
			'gemini' => array( 'url' => 'https://ai.google.dev/gemini-api/docs/pricing', 'hosts' => array( 'ai.google.dev', 'cloud.google.com' ) ),
			'claude' => array( 'url' => 'https://platform.claude.com/docs/en/about-claude/pricing', 'hosts' => array( 'claude.com', 'anthropic.com' ) ),
		);
	}

	/**
	 * Looks up every model that has no price a person stands behind.
	 *
	 * One question per provider, each pointed at that provider's own page. A
	 * single question about every model at once sent a model searching three
	 * sites for sixty names and ran out of room before it answered. And when the
	 * route refuses — quota spent, no credit, a model "experiencing high demand",
	 * all three seen on real keys on 2026-09-23 — the next route this site has a
	 * key for is tried, instead of the whole lookup ending on the first refusal.
	 *
	 * Returns what it did, per model, so the screen can show the reasoning
	 * rather than a spinner that ends in a number nobody can account for.
	 */
	public static function lookup( array $only = array() ) {
		$wanted = self::wanted( $only );
		if ( ! $wanted ) { return array( 'asked' => 0, 'found' => 0, 'results' => array() ); }

		$out = array( 'asked' => count( $wanted ), 'found' => 0, 'results' => array() );

		// What can be read straight off a provider's page needs no model: it is
		// free, and it cannot invent a number.
		foreach ( $wanted as $key => $row ) {
			$read = self::read_page( $row['provider'], $row['model_id'] );
			if ( ! $read ) { continue; }
			MSRWA_Catalog::remember_price( $row['provider'], $row['model_id'], $read['input'], $read['output'], MSRWA_Catalog::READ, $read['source'] );
			$out['results'][ $key ] = array( 'state' => 'found' ) + $read;
			$out['found']++;
			unset( $wanted[ $key ] );
		}
		if ( ! $wanted ) { return $out; }

		$routes = self::routes();
		if ( ! $routes ) {
			return array_merge( $out, array( 'error' => __( 'Aucun modèle n’est routé pour faire cette recherche. Enregistrez une clé d’abord.', 'ms-recipes-writer-ai' ) ) );
		}

		$groups = array();
		foreach ( $wanted as $key => $row ) { $groups[ $row['provider'] ][ $key ] = $row; }
		$errors = array();
		$answered = false;
		foreach ( $groups as $provider => $group ) {
			$answer = null;
			foreach ( $routes as $index => $route ) {
				$answer = MSRWA_Engine_Call::text( $route['provider'], $route['model'], self::prompt( $group, $provider ), 8000, true, true, self::wire( $route ) );
				if ( empty( $answer['error'] ) ) { break; }
				$errors[] = $route['provider'] . ':' . $route['model'] . ' — ' . MSRWA_DB::sanitize( (string) $answer['error'] );
				// A route that refused once will refuse the next provider too.
				unset( $routes[ $index ] );
			}
			if ( ! $answer || ! empty( $answer['error'] ) ) {
				foreach ( $group as $key => $row ) { $out['results'][ $key ] = array( 'state' => 'missing', 'why' => __( 'aucun modèle disponible n’a pu lire la page du fournisseur', 'ms-recipes-writer-ai' ) ); }
				continue;
			}
			$answered = true;
			$applied = self::apply( $group, MSRWA_Json::decode( (string) ( $answer['text'] ?? '' ) ) );
			$out['found'] += $applied['found'];
			$out['results'] += $applied['results'];
		}
		// Only when nothing answered at all: a page read that found no rate is an
		// answer, and the refusals before it are not what the owner needs to see.
		if ( ! $answered && $errors ) { $out['error'] = implode( ' · ', $errors ); }
		return $out;
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

	/**
	 * Every route that can do the looking, in the order worth trying.
	 *
	 * Research first, because it is the step the owner already pointed at a
	 * model that reads the web; then every model the catalogue knows can write,
	 * on a provider this site has a key for, cheapest first. Reading a table
	 * off a page is not work that needs the dearest model, and on 2026-09-23 the
	 * research route, both tiers of Gemini and Claude refused at the same
	 * moment while a Gemini model two places down the price list answered.
	 */
	public static function routes( $config = null, ?array $rows = null ) {
		$config = $config ? $config : MSRWA_Engine_Config::create( MSRWA_Engine_Settings::stored(), array( 'settings' => MSRWA_Settings::engine_settings() ) );
		$candidates = array( $config->model_for( 'research' ) );
		$writers = array_filter( null === $rows ? MSRWA_Catalog::rows() : $rows, static function ( $row ) {
			return false !== $row['served'] && 'text' === MSRWA_Catalog::role( $row['provider'], $row['model_id'] );
		} );
		usort( $writers, static function ( $a, $b ) { return (float) ( $a['input_usd'] ?? 1000 ) <=> (float) ( $b['input_usd'] ?? 1000 ); } );
		foreach ( $writers as $row ) { $candidates[] = array( 'provider' => $row['provider'], 'model' => $row['model_id'] ); }
		foreach ( (array) $config->get( 'tiers', array() ) as $models ) {
			foreach ( (array) $models as $provider => $model ) { $candidates[] = array( 'provider' => $provider, 'model' => (string) $model ); }
		}
		$out = array();
		foreach ( $candidates as $route ) {
			$key = $route['provider'] . ':' . $route['model'];
			if ( '' === (string) $route['model'] || isset( $out[ $key ] ) ) { continue; }
			$wire = $config->provider( $route['provider'], $route['model'] );
			if ( empty( $wire['has_key'] ) ) { continue; }
			$out[ $key ] = array( 'provider' => $route['provider'], 'model' => $route['model'], 'wire' => $wire );
		}
		return array_slice( array_values( $out ), 0, self::ATTEMPTS );
	}

	/**
	 * The wire, with the tool that reads a page rather than one that searches.
	 *
	 * The prompt names the page, so Gemini needs only to open it: url_context
	 * read Google's pricing page on a key whose search grounding was out of
	 * quota. Search remains the tool for the other two, which find the page
	 * themselves.
	 */
	public static function wire( array $route ) {
		$wire = $route['wire'];
		if ( 'gemini' === $route['provider'] ) { $wire['web_search_tool'] = array( 'url_context' => array() ); }
		// Copying a number off a table needs no deliberation.
		$wire['thinking_level'] = 'low';
		return $wire;
	}

	/** Asked for one provider at a time, in the words the answer must come back in. */
	public static function prompt( array $wanted, $provider = '' ) {
		$lines = array();
		$image = false;
		$model_url = self::pages()[ $provider ]['model_url'] ?? '';
		foreach ( $wanted as $key => $row ) {
			$lines[] = '- ' . $key . ( '' !== $model_url ? ' — its own page: ' . sprintf( $model_url, rawurlencode( (string) $row['model_id'] ) ) : '' );
			if ( 'image' === MSRWA_Catalog::role( $row['provider'], $row['model_id'] ) ) { $image = true; }
		}
		$page = self::pages()[ $provider ]['url'] ?? '';

		return "Find the current published API price of each model listed below.\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. "Each line is `provider:model_id`. Use the provider's own published pricing page and no other source."
			. ( '' !== $page ? ' That page is ' . $page . " — read it.\n\n" : "\n\n" )
			. "Report USD per ONE MILLION tokens, standard rates: not batch, not flex, not priority, not cached input.\n"
			. "If a provider quotes per 1,000 tokens, multiply by 1000.\n"
			. "Where a model's rate depends on prompt length, report the rate for prompts under 200,000 tokens.\n"
			. "`output` is the rate for output tokens, which on reasoning models includes thinking tokens.\n"
			. ( $image ? "For an image generation model, `input` is the text input rate and `output` is the IMAGE output token rate.\n" : '' )
			. "\nReturn JSON only, of this shape:\n"
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
			if ( ! self::own_page( $row['provider'], $source ) ) {
				// A reseller's table or a blog post is how a stale or invented
				// rate gets in with a citation that looks like proof.
				$results[ $key ] = array( 'state' => 'refused', 'why' => __( 'la page citée n’est pas celle du fournisseur', 'ms-recipes-writer-ai' ) );
				continue;
			}
			MSRWA_Catalog::remember_price( $row['provider'], $row['model_id'], $input, $output, MSRWA_Catalog::LOOKED_UP, $source );
			$results[ $key ] = array( 'state' => 'found', 'input' => $input, 'output' => $output, 'source' => $source );
			$found++;
		}
		return array( 'asked' => count( $wanted ), 'found' => $found, 'results' => $results );
	}

	/**
	 * A model's rate read off its provider's own page, or null.
	 *
	 * Only OpenAI publishes a page per model whose markup states the rate
	 * outright — "Input $0.20 … Output $1.20", before any comparison with
	 * other models. Its pricing page shows only the flagships until a script
	 * runs, and a model with web search read "no rate found" for Luna there.
	 */
	public static function read_page( $provider, $model_id ) {
		$template = self::pages()[ $provider ]['model_url'] ?? '';
		if ( '' === $template ) { return null; }
		$url = sprintf( $template, rawurlencode( (string) $model_id ) );
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return null; }
		$rate = self::parse_page( (string) wp_remote_retrieve_body( $response ) );
		return $rate ? $rate + array( 'source' => $url ) : null;
	}

	/** The first input and output rate a model page states. Pure, for the tests. */
	public static function parse_page( $html ) {
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$text = preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		// The labels run into their neighbours in the markup ("tokensInput$0.20…
		// $0.02Output$1.20"), so no word boundary is required; the capital I and
		// O keep "Cached input" out of it.
		if ( ! preg_match( '/Input\s*\$\s*([0-9]+(?:\.[0-9]+)?)(.{0,80}?)Output\s*\$\s*([0-9]+(?:\.[0-9]+)?)/u', $text, $m ) ) { return null; }
		$input = self::rate( $m[1] );
		$output = self::rate( $m[3] );
		return null === $input || null === $output ? null : array( 'input' => $input, 'output' => $output );
	}

	/** Whether a cited page belongs to the provider it prices. */
	public static function own_page( $provider, $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme || '' === $host ) { return false; }
		foreach ( self::pages()[ sanitize_key( (string) $provider ) ]['hosts'] ?? array() as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) { return true; }
		}
		return false;
	}

	/** A number that could be a published rate, or null. */
	private static function rate( $value ) {
		if ( ! is_numeric( $value ) ) { return null; }
		$value = (float) $value;
		return $value >= self::FLOOR && $value <= self::CEILING ? $value : null;
	}
}
