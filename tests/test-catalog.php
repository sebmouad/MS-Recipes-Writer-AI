<?php
// Two kinds of knowledge that must not be confused: what a model costs, which
// is written down by hand because no API gives it, and which identifiers a
// provider still answers to, which only the provider knows. Mixing them is how
// a route ends up naming a model that cannot run.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'catalog' );

$GLOBALS['msrwa_test_options'] = array();

// --- Nothing has ever been asked -----------------------------------------

msrwa_test_assert( array() === MSRWA_Catalog::available( 'claude' ), 'A provider never asked has listed nothing.' );
msrwa_test_assert( '' === MSRWA_Catalog::listed_at( 'claude' ), 'And carries no date.' );
// Silence from a provider is not evidence against a model. Reporting it as
// missing would send an administrator chasing one that is perfectly fine.
msrwa_test_assert( 'unknown' === MSRWA_Catalog::served( 'claude', 'claude-sonnet-5' ), 'An unasked provider says nothing about its models.' );

// --- What the provider answered ------------------------------------------

msrwa_test_assert( MSRWA_Catalog::remember_models( 'claude', array( 'claude-sonnet-5', 'claude-opus-5' ) ), 'A listing is recorded.' );
msrwa_test_assert( array( 'claude-sonnet-5', 'claude-opus-5' ) === MSRWA_Catalog::available( 'claude' ), 'And read back as it came.' );
msrwa_test_assert( '' !== MSRWA_Catalog::listed_at( 'claude' ), 'With the moment it was taken, because a stale list must be recognisable as one.' );
msrwa_test_assert( 'yes' === MSRWA_Catalog::served( 'claude', 'claude-sonnet-5' ), 'A listed model is served.' );
msrwa_test_assert( 'no' === MSRWA_Catalog::served( 'claude', 'claude-haiku-4-5' ), 'And one the provider omitted is not — the real bug this exists to catch.' );
msrwa_test_assert( 'unknown' === MSRWA_Catalog::served( 'gemini', 'claude-sonnet-5' ), 'One provider’s listing says nothing about another.' );

// An empty answer means the fetch failed or the shape changed. Forgetting
// every model is far worse than holding a list that is merely old.
msrwa_test_assert( ! MSRWA_Catalog::remember_models( 'claude', array() ), 'An empty listing is refused.' );
msrwa_test_assert( array( 'claude-sonnet-5', 'claude-opus-5' ) === MSRWA_Catalog::available( 'claude' ), 'And leaves what was known untouched.' );

msrwa_test_assert( MSRWA_Catalog::remember_models( 'claude', array( 'claude-sonnet-5', '', 'claude-sonnet-5', 'claude-opus-5-5' ) ), 'A listing with blanks and repeats is still a listing.' );
msrwa_test_assert( array( 'claude-sonnet-5', 'claude-opus-5-5' ) === MSRWA_Catalog::available( 'claude' ), 'Cleaned to what it actually named.' );

// --- The shipped catalogue ------------------------------------------------

$catalog = MSRWA_Catalog::defaults();
msrwa_test_assert( array( 'openai', 'gemini', 'claude' ) === array_keys( $catalog ), 'One section per provider, named the engine’s way.' );
foreach ( $catalog as $provider => $models ) {
	msrwa_test_assert( ! empty( $models ), $provider . ' lists at least one model.' );
	foreach ( $models as $id => $model ) {
		msrwa_test_assert( '' !== trim( (string) ( $model['label'] ?? '' ) ), $provider . ':' . $id . ' has a name a person can read.' );
		// An unpriced model makes every estimate that touches it incomplete,
		// and the plugin promises estimates before it spends.
		msrwa_test_assert( (float) ( $model['input'] ?? 0 ) > 0, $provider . ':' . $id . ' has an input price.' );
		msrwa_test_assert( (float) ( $model['output'] ?? 0 ) > 0, $provider . ':' . $id . ' has an output price.' );
		// A price nobody can check is a price nobody will maintain.
		msrwa_test_assert( 0 === strpos( (string) ( $model['source'] ?? '' ), 'https://' ), $provider . ':' . $id . ' names where its price came from.' );
		msrwa_test_assert( ! empty( $model['text'] ) || ! empty( $model['image_generation'] ), $provider . ':' . $id . ' can do something the engine asks for.' );
	}
}

msrwa_test_assert( MSRWA_Catalog::models() === MSRWA_Catalog::defaults(), 'What the plugin prices against is the shipped catalogue.' );

msrwa_test_done( 'model catalogue' );
