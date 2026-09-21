<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What every engine call hands back.
 *
 * The engine is used by two callers with opposite needs: the plugin, which must
 * never be killed by a bad brief or a provider outage, and the lab, which wants
 * everything that happened written down. So nothing here throws or exits —
 * a failure is a value, recorded alongside what still succeeded.
 *
 * The same object is the progress report while a run is in flight and the record
 * once it finishes, which is why the HTML report and the plugin's admin screen
 * can read the same thing.
 */
final class MSRWA_Result {
	/** @var bool False as soon as any step fails; a partial run still returns its artifacts. */
	public $ok = true;

	/** @var array Named outputs: research, canonical, article, featured, facebook, approval. */
	public $artifacts = array();

	/** @var array One entry per step attempted, in the order attempted. */
	public $steps = array();

	/** @var array Everything that happened, with the second it happened at. */
	public $events = array();

	/** @var array Failures, each naming the step it belongs to. */
	public $errors = array();

	/** @var float Seconds since the run began. */
	private $started;

	/** @var callable|null Called with each event as it happens, for a live progress bar. */
	private $observer;

	public function __construct( $observer = null ) {
		$this->started = microtime( true );
		$this->observer = is_callable( $observer ) ? $observer : null;
	}

	public function elapsed() { return round( microtime( true ) - $this->started, 1 ); }

	/** Records something worth telling the caller about, and tells it immediately. */
	public function event( $kind, $step, $message, $data = array() ) {
		$event = array( 'at' => $this->elapsed(), 'kind' => $kind, 'step' => $step, 'message' => $message, 'data' => $data );
		$this->events[] = $event;
		if ( $this->observer ) { call_user_func( $this->observer, $event ); }
		return $this;
	}

	/** A step that ran, whether or not it satisfied its contract. */
	public function step( $name, $outcome ) {
		$outcome = array_merge( array(
			'step' => $name, 'model' => '', 'provider' => '', 'seconds' => 0, 'attempts' => 1,
			'usage' => array(), 'cost_usd' => 0.0, 'status' => '', 'passed' => null, 'total' => null, 'checks' => array(), 'error' => '',
		), (array) $outcome );
		$this->steps[] = $outcome;
		if ( '' !== $outcome['error'] ) { return $this->fail( $name, $outcome['error'] ); }
		$this->event( 'step', $name, $this->describe( $outcome ), array( 'cost_usd' => $outcome['cost_usd'], 'seconds' => $outcome['seconds'] ) );
		return $this;
	}

	private function describe( $outcome ) {
		$score = null === $outcome['passed'] ? '' : sprintf( ' %d/%d', $outcome['passed'], $outcome['total'] );
		return sprintf( '%s%s in %ss for $%.4f', $outcome['step'], $score, $outcome['seconds'], $outcome['cost_usd'] );
	}

	/** A failure the caller must see. The run continues; `ok` does not. */
	public function fail( $step, $message, $data = array() ) {
		$this->ok = false;
		$this->errors[] = array( 'step' => $step, 'message' => $message, 'data' => $data );
		$this->event( 'error', $step, $message, $data );
		return $this;
	}

	public function artifact( $name, $value ) {
		$this->artifacts[ $name ] = $value;
		return $this;
	}

	/** Seconds, cost and tokens, plus the four budget buckets the owner reports on. */
	public function totals() {
		$totals = array( 'seconds' => 0.0, 'cost_usd' => 0.0, 'input_tokens' => 0, 'output_tokens' => 0, 'steps' => count( $this->steps ), 'buckets' => array( 'article' => 0.0, 'featured' => 0.0, 'facebook' => 0.0, 'other' => 0.0 ) );
		foreach ( $this->steps as $step ) {
			$totals['seconds'] += (float) $step['seconds'];
			$totals['cost_usd'] += (float) $step['cost_usd'];
			$totals['input_tokens'] += (int) ( $step['usage']['input_tokens'] ?? 0 );
			$totals['output_tokens'] += (int) ( $step['usage']['output_tokens'] ?? 0 );
			$bucket = MSRWA_Engine_Steps::bucket( $step['step'] );
			$totals['buckets'][ $bucket ] += (float) $step['cost_usd'];
		}
		$totals['seconds'] = round( $totals['seconds'], 1 );
		$totals['cost_usd'] = round( $totals['cost_usd'], 6 );
		foreach ( $totals['buckets'] as $name => $value ) { $totals['buckets'][ $name ] = round( $value, 6 ); }
		return $totals;
	}

	/** The whole run as plain data: what the plugin stores and the report renders. */
	public function to_array() {
		return array(
			'ok' => $this->ok, 'totals' => $this->totals(), 'steps' => $this->steps,
			'artifacts' => $this->artifacts, 'errors' => $this->errors, 'events' => $this->events,
		);
	}
}
