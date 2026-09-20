<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Derived cost model.
 *
 * Every amount comes from the tokens a step needs multiplied by the catalogue
 * rate of the model that step would use. Nothing here is a flat guess, so a
 * change to the word count, an image size or a model moves the estimate.
 *
 * Each recipe is reported in four buckets with a minimum and a maximum:
 * minimum is every step passing on its first attempt, maximum is every step
 * using all its correction cycles.
 */
final class MSRWA_Cost {
	/** Byte-level tokenizers over French prose sit close to four characters per token. */
	const CHARS_PER_TOKEN = 4;

	/** French prose costs about 1.6 tokens per word once punctuation is counted. */
	const TOKENS_PER_WORD = 1.6;

	/**
	 * Written output costs more per word than plain prose: the HTML tags, the
	 * JSON escaping and the metadata fields around the body all bill. Measured
	 * over fifteen real article answers on 2026-09-20 — 1.88 tokens per word at
	 * best, 3.97 on a model that also bills its reasoning — so the band below is
	 * the observed floor and ceiling rather than an assumption.
	 */
	const WRITTEN_TOKENS_PER_WORD_MIN = 2.0;
	const WRITTEN_TOKENS_PER_WORD_MAX = 4.0;

	/**
	 * The output ceiling a written step must be given. Sending less truncates the
	 * answer, which is billed in full and scores zero: an 8000-token cap against a
	 * 2800-word target cost $0.086 on Sonnet 5 and $0.164 on a proofreading call,
	 * both stopped at max_tokens.
	 */
	public static function output_budget( $words_max ) {
		return (int) ceil( max( 1, (int) $words_max ) * self::WRITTEN_TOKENS_PER_WORD_MAX / 500 ) * 500;
	}

	public static function buckets() { return array( 'article', 'featured', 'facebook', 'other' ); }

	/**
	 * Steps of one recipe, in pipeline order.
	 *
	 * `repeats` is how many extra times a step can run when corrections are
	 * needed; `enabled` false marks a step the specification requires but the
	 * pipeline does not perform yet, so the estimate never claims work that
	 * does not happen.
	 */
	public static function steps( $settings = null ) {
		$s = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$corrections = max( 0, (int) ( $s['max_corrections'] ?? 0 ) );
		$words_min = max( 1, (int) ( $s['quality_min_words'] ?? 2000 ) );
		$words_max = max( $words_min, (int) ( $s['quality_max_words'] ?? $words_min ) );
		$references = max( 0, (int) ( $s['max_reference_images'] ?? 0 ) );
		return array(
			'router' => array( 'bucket' => 'other', 'capability' => 'text', 'stage' => 'text', 'prompts' => array( 'prompt_router' ), 'context' => 600, 'output' => (int) ( $s['router_max_output_tokens'] ?? 700 ), 'repeats' => 0, 'enabled' => true ),
			'association' => array( 'bucket' => 'other', 'capability' => 'text', 'stage' => 'text', 'prompts' => array( 'prompt_association' ), 'context' => 1200, 'output' => (int) ( $s['association_max_output_tokens'] ?? 900 ), 'repeats' => 0, 'enabled' => true ),
			'reference_vision' => array( 'bucket' => 'other', 'capability' => 'vision', 'stage' => 'review', 'prompts' => array( 'prompt_reference_vision' ), 'context' => 800, 'output' => (int) ( $s['vision_max_output_tokens'] ?? 1200 ), 'repeats' => 0, 'enabled' => true, 'occurrences_min' => 0, 'occurrences_max' => $references ),
			'research' => array( 'bucket' => 'other', 'capability' => 'web_search', 'stage' => 'search', 'prompts' => array( 'prompt_research' ), 'context' => 1200, 'output' => (int) ( $s['research_max_output_tokens'] ?? 4000 ), 'repeats' => 0, 'enabled' => true, 'tool' => true ),
			'canonical_recipe' => array( 'bucket' => 'article', 'capability' => 'text', 'stage' => 'text', 'prompts' => array( 'prompt_recipe', 'prompt_nutrition' ), 'context' => 3000, 'output' => (int) ( $s['canonical_max_output_tokens'] ?? 2600 ), 'repeats' => $corrections, 'enabled' => true ),
			'article' => array( 'bucket' => 'article', 'capability' => 'text', 'stage' => 'text', 'prompts' => array( 'prompt_article', 'prompt_seo', 'prompt_internal_links' ), 'context' => 4000, 'output_min' => (int) round( $words_min * self::WRITTEN_TOKENS_PER_WORD_MIN ), 'output_max' => (int) round( $words_max * self::WRITTEN_TOKENS_PER_WORD_MAX ), 'repeats' => $corrections, 'enabled' => true ),
			'review' => array( 'bucket' => 'article', 'capability' => 'text', 'stage' => 'review', 'prompts' => array( 'prompt_review' ), 'context_words' => true, 'context' => 2000, 'output' => (int) ( $s['review_max_output_tokens'] ?? 3000 ), 'repeats' => $corrections, 'enabled' => true ),
			'fact_check' => array( 'bucket' => 'article', 'capability' => 'text', 'stage' => 'review', 'prompts' => array( 'prompt_review' ), 'context_words' => true, 'context' => 2000, 'output' => (int) ( $s['review_max_output_tokens'] ?? 3000 ), 'repeats' => $corrections, 'enabled' => ! empty( $s['fact_check_enabled'] ) ),
			'proofreading' => array( 'bucket' => 'article', 'capability' => 'text', 'stage' => 'review', 'prompts' => array( 'prompt_correction' ), 'context_words' => true, 'context' => 500, 'output_min' => (int) round( $words_min * self::WRITTEN_TOKENS_PER_WORD_MIN ), 'output_max' => (int) round( $words_max * self::WRITTEN_TOKENS_PER_WORD_MAX ), 'repeats' => 0, 'enabled' => ! empty( $s['proofreading_enabled'] ) ),
			'featured_image' => array( 'bucket' => 'featured', 'capability' => 'image_generation', 'stage' => 'image', 'prompts' => array( 'prompt_image' ), 'context' => 600, 'image' => 'featured', 'repeats' => $corrections, 'enabled' => true ),
			'featured_image_review' => array( 'bucket' => 'featured', 'capability' => 'vision', 'stage' => 'review', 'prompts' => array( 'prompt_image_review' ), 'context' => 800, 'output' => (int) ( $s['image_review_max_output_tokens'] ?? 1000 ), 'repeats' => $corrections, 'enabled' => true ),
			'facebook_image' => array( 'bucket' => 'facebook', 'capability' => 'image_generation', 'stage' => 'image', 'prompts' => array( 'prompt_facebook_image' ), 'context' => 600, 'image' => 'facebook', 'repeats' => $corrections, 'enabled' => true ),
			'facebook_image_review' => array( 'bucket' => 'facebook', 'capability' => 'vision', 'stage' => 'review', 'prompts' => array( 'prompt_image_review' ), 'context' => 800, 'output' => (int) ( $s['image_review_max_output_tokens'] ?? 1000 ), 'repeats' => $corrections, 'enabled' => true ),
		);
	}

	/** Tokens a prompt of this length costs, rounded up. */
	public static function text_tokens( $text ) { return (int) ceil( strlen( (string) $text ) / self::CHARS_PER_TOKEN ); }

	/** Price of a text call in USD. Returns null when the model carries no rate. */
	public static function text_price( $model, $input_tokens, $output_tokens ) {
		if ( ! is_array( $model ) || ! isset( $model['input'], $model['output'] ) ) { return null; }
		return ( (int) $input_tokens * (float) $model['input'] + (int) $output_tokens * (float) $model['output'] ) / 1000000;
	}

	/**
	 * Generated image tokens for a size and quality. Image APIs bill the image
	 * they return, so the catalogue carries the token count per size and the
	 * model's own output rate prices it. Unknown combinations return null and
	 * are reported as unknown, never as free.
	 */
	public static function image_tokens( $model, $size, $quality = 'medium' ) {
		$table = is_array( $model ) && isset( $model['image_tokens'] ) && is_array( $model['image_tokens'] ) ? $model['image_tokens'] : array();
		$quality = in_array( $quality, array( 'low', 'medium', 'high' ), true ) ? $quality : 'medium';
		if ( ! isset( $table[ $size ][ $quality ] ) ) { return null; }
		return max( 0, (int) $table[ $size ][ $quality ] );
	}

	/** Price of one generated image, prompt included. Null when unknown. */
	public static function image_price( $model, $size, $quality, $prompt_tokens = 0 ) {
		$tokens = self::image_tokens( $model, $size, $quality );
		if ( null === $tokens || ! isset( $model['output'] ) ) { return null; }
		$input_rate = isset( $model['input'] ) ? (float) $model['input'] : 0;
		return ( $tokens * (float) $model['output'] + (int) $prompt_tokens * $input_rate ) / 1000000;
	}

	/**
	 * Estimate for one recipe.
	 *
	 * Returns each bucket with min and max, the recipe total, the per-step detail
	 * and any step whose price could not be established.
	 */
	public static function estimate( $settings = null, $plan = null ) {
		$s = is_array( $settings ) ? $settings : MSRWA_Settings::get();
		$catalog = MSRWA_Catalog::models();
		$buckets = array();
		foreach ( self::buckets() as $bucket ) { $buckets[ $bucket ] = array( 'min' => 0.0, 'max' => 0.0 ); }
		$details = array();
		$unknown = array();
		foreach ( self::steps( $s ) as $name => $step ) {
			if ( empty( $step['enabled'] ) ) { continue; }
			$model_row = self::resolve_model( $name, $step, $catalog, $plan );
			$prompt_tokens = 0;
			foreach ( (array) $step['prompts'] as $key ) { $prompt_tokens += self::text_tokens( $s[ $key ] ?? '' ); }
			$input_tokens = $prompt_tokens + (int) ( $step['context'] ?? 0 );
			if ( ! empty( $step['context_words'] ) ) { $input_tokens += (int) round( max( 1, (int) ( $s['quality_min_words'] ?? 2000 ) ) * self::TOKENS_PER_WORD ); }
			if ( isset( $step['image'] ) ) {
				$size = self::image_size( $step['image'], $s );
				$quality = MSRWA_Images::quality( $s, $step['image'] );
				$once = self::image_price( $model_row, $size, $quality, $input_tokens );
				$detail = array( 'bucket' => $step['bucket'], 'model' => $model_row['id'] ?? '', 'size' => $size, 'quality' => $quality, 'input_tokens' => $input_tokens, 'output_tokens' => self::image_tokens( $model_row, $size, $quality ) );
			} else {
				$output_min = (int) ( $step['output_min'] ?? $step['output'] ?? 0 );
				$output_max = (int) ( $step['output_max'] ?? $step['output'] ?? 0 );
				$once = self::text_price( $model_row, $input_tokens, $output_min );
				$once_max = self::text_price( $model_row, $input_tokens, $output_max );
				$detail = array( 'bucket' => $step['bucket'], 'model' => $model_row['id'] ?? '', 'input_tokens' => $input_tokens, 'output_tokens' => $output_max );
			}
			if ( null === $once ) { $unknown[] = $name; $details[ $name ] = $detail + array( 'min' => null, 'max' => null ); continue; }
			$max_once = isset( $once_max ) && null !== $once_max ? $once_max : $once;
			unset( $once_max );
			if ( ! empty( $step['tool'] ) ) {
				$tool = (float) ( $s['web_search_tool_cost_usd'] ?? 0 ) * max( 0, (int) ( $s['web_search_max_tool_calls'] ?? 0 ) );
				$once += $tool; $max_once += $tool;
			}
			$occurrences_min = (int) ( $step['occurrences_min'] ?? 1 );
			$occurrences_max = (int) ( $step['occurrences_max'] ?? 1 );
			$min = $once * $occurrences_min;
			$max = $max_once * $occurrences_max * ( 1 + (int) ( $step['repeats'] ?? 0 ) );
			$buckets[ $step['bucket'] ]['min'] += $min;
			$buckets[ $step['bucket'] ]['max'] += $max;
			$details[ $name ] = $detail + array( 'min' => round( $min, 6 ), 'max' => round( $max, 6 ) );
		}
		$total = array( 'min' => 0.0, 'max' => 0.0 );
		foreach ( $buckets as $bucket => $amounts ) {
			$buckets[ $bucket ] = array( 'min' => round( $amounts['min'], 6 ), 'max' => round( $amounts['max'], 6 ) );
			$total['min'] += $buckets[ $bucket ]['min'];
			$total['max'] += $buckets[ $bucket ]['max'];
		}
		$total = array( 'min' => round( $total['min'], 6 ), 'max' => round( $total['max'], 6 ) );
		return array( 'buckets' => $buckets, 'total' => $total, 'steps' => $details, 'unknown' => $unknown, 'currency' => 'USD', 'generated_at' => current_time( 'mysql', true ) );
	}

	/** Size the pipeline will actually request, so the estimate follows the ratio setting. */
	public static function image_size( $kind, $settings ) {
		$ratio = 'facebook' === $kind ? ( $settings['facebook_ratio'] ?? '4:5' ) : ( $settings['featured_ratio'] ?? '1:1' );
		return MSRWA_Images::native_size( $ratio, 'facebook' === $kind ? '1024x1536' : '1024x1024' );
	}

	/** The model a step would use: the frozen plan when there is one, the router otherwise. */
	private static function resolve_model( $name, $step, $catalog, $plan ) {
		$provider = ''; $model = '';
		if ( is_array( $plan ) && isset( $plan[ $name ]['provider'], $plan[ $name ]['model'] ) ) {
			$provider = $plan[ $name ]['provider']; $model = $plan[ $name ]['model'];
		} else {
			$route = MSRWA_Router::plan( $step['capability'], $step['stage'] );
			if ( is_wp_error( $route ) ) { return array(); }
			$provider = $route['provider']; $model = $route['model'];
		}
		$row = isset( $catalog[ $provider ][ $model ] ) ? $catalog[ $provider ][ $model ] : array();
		if ( $row ) { $row['id'] = $provider . ':' . $model; }
		return $row;
	}
}
