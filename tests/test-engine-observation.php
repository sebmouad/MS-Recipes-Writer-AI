<?php
// The evidence images are fetched and described together rather than one after
// another. What is saved is the waiting; the observations and their cost are the
// same, and every refusal is still decided per image.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';

// A vision call must be plannable without being made, or a set of them could
// never go out together. This is what parallelising them rests on.
$plan = MSRWA_Engine_Call::plan_vision( 'openai', 'gpt-5.6-luna', array( 'mime' => 'image/jpeg', 'data' => 'AAAA' ), 'Tarte aux pommes', 900, array( 'text_endpoint' => 'https://example.invalid/v1', 'headers' => array(), 'has_key' => true, 'timeout' => 30 ) );
msrwa_test_assert( ! isset( $plan['error'] ), 'A vision call must be plannable ahead of being made.' );
msrwa_test_assert( 'vision' === $plan['kind'], 'The plan must say what kind of answer to read back.' );
msrwa_test_assert( 'https://example.invalid/v1' === $plan['request']['url'], 'The plan carries the configured endpoint, not a written-in one.' );
$encoded = json_encode( $plan['request']['payload'] );
msrwa_test_assert( false !== strpos( $encoded, 'base64,AAAA' ), 'The image bytes must reach the request.' );
msrwa_test_assert( false !== strpos( $encoded, 'Tarte aux pommes' ), 'The reference title is the context the observation is made in.' );

// Every refusal is decided before a connection is opened, and each URL is
// judged on its own: one bad reference must not cost the others their evidence.
$refused = MSRWA_Engine_Call::fetch_images( array( 'http://example.com/a.jpg', 'https://127.0.0.1/b.jpg', 'ftp://x/c.jpg' ), 1000, 4 );
msrwa_test_assert( 3 === count( $refused ), 'Every requested image comes back, refusals included.' );
msrwa_test_assert( false !== strpos( $refused[0]['error'], 'HTTPS' ), 'A plain HTTP image is refused; got ' . $refused[0]['error'] );
msrwa_test_assert( false !== strpos( $refused[1]['error'], 'public' ), 'A private address is refused; got ' . $refused[1]['error'] );
msrwa_test_assert( isset( $refused[2]['error'] ), 'An unknown scheme is refused.' );

// The single-image entry point still exists and still answers the same shape:
// the engine's editor-image path calls it.
$one = MSRWA_Engine_Call::fetch_image( 'http://example.com/a.jpg' );
msrwa_test_assert( isset( $one['error'] ) && false !== strpos( $one['error'], 'HTTPS' ), 'fetch_image must keep reporting a refusal as a value.' );

// With no references there is nothing to fetch and no call to make.
$quiet = MSRWA_Engine_Call::observe_images( 'openai', 'gpt-5.6-luna', array( 'visual_references' => array() ), 3 );
msrwa_test_assert( array() === $quiet['package']['visual_observations'], 'No references means no observations.' );
msrwa_test_assert( 0 === $quiet['usage']['input_tokens'], 'Nothing observed costs nothing.' );

// A reference that cannot be fetched is recorded as an uncertainty, never dropped
// in silence — the judge is told what could not be seen.
$unfetchable = MSRWA_Engine_Call::observe_images( 'openai', 'gpt-5.6-luna', array( 'visual_references' => array( array( 'image_url' => 'http://example.com/a.jpg', 'title' => 'x' ) ), 'uncertainties' => array() ), 3 );
msrwa_test_assert( 1 === count( $unfetchable['package']['uncertainties'] ), 'An image that could not be read must be said so.' );

msrwa_test_done( 'engine observation wave OK' );
