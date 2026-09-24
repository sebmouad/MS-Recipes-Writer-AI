<?php
// The Facebook collage is drawn the way the owner's ChatGPT draws it: a text
// model writes the image prompt from his brief, the recipe and a reference
// image, then the image is drawn with that reference attached. Checked here
// without a provider: what each call is sent, and what happens when one fails.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'images', 'prompt', 'json' );
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/tools/lib/steps.php';

$fixture = lab_brief( 'croquettes-pommes-de-terre-jambon' );
// No web photograph: offline, nothing is downloaded. The research's own
// photograph is the fallback when the writer sent none, measured live.
$fixture['research']['visual_references'] = array();
$brief = array( 'title' => $fixture['title'], 'text' => $fixture['text'], 'images' => array(), 'artifacts' => array( 'research' => $fixture['research'], 'canonical' => $fixture['canonical'] ) );
$pixel = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$style = sys_get_temp_dir() . '/msrwa-style-' . getmypid() . '.png';
file_put_contents( $style, $pixel );
$written = str_repeat( 'Panel 1: whole raw potatoes, diced ham and grated cheese in separate bowls. ', 6 );

$calls = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, $written, $pixel ) {
	$calls[] = array( 'url' => $url, 'payload' => $payload );
	if ( false !== strpos( $url, '/images/' ) ) {
		return array( 'status' => 200, 'raw' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( $pixel ) ) ), 'usage' => array( 'input_tokens' => 2000, 'output_tokens' => 1372 ) ) ) );
	}
	return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => $written ) ) ) ), 'usage' => array( 'input_tokens' => 3000, 'output_tokens' => 1200 ) ) ) );
};
$config = array( 'settings' => array_merge( lab_settings(), array( 'keys' => array( 'openai' => 'k' ) ) ), 'images' => array( 'style_references' => array( $style ), 'format' => 'png' ) );
$result = MSRWA_Engine::run_step( 'facebook_image', $brief, array( 'config' => $config, 'workspace' => sys_get_temp_dir() ) );

msrwa_test_assert( 2 === count( $calls ), 'One call writes the prompt, one draws: ' . count( $calls ) . ' call(s).' );
$compose = $calls[0]['payload'];
$sent_text = wp_json_encode( $compose );
msrwa_test_contains( $sent_text, 'You are ChatGPT', 'The writing model is told how to write the prompt.' );
msrwa_test_contains( $sent_text, 'Croquettes de pommes de terre', 'The owner’s brief names the dish.' );
msrwa_test_contains( $sent_text, 'THE RECIPE', 'The recipe it must follow is sent.' );
msrwa_test_contains( $sent_text, 'approved collage of another dish', 'It is told the reference is a style, not the dish.' );
msrwa_test_contains( $sent_text, 'input_image', 'It sees the reference image.' );
msrwa_test_missing( $sent_text, '[DISH]', 'The dish marker is replaced.' );

$draw = $calls[1];
msrwa_test_contains( $draw['url'], '/images/edits', 'The collage is drawn from the reference on the edits endpoint.' );
msrwa_test_assert( isset( $draw['payload']['image[]']['file'] ) && $pixel === $draw['payload']['image[]']['file'], 'The reference is uploaded with the drawing.' );
msrwa_test_assert( 0 === strpos( (string) $draw['payload']['prompt'], trim( $written ) ), 'The written prompt is the one drawn: ' . substr( (string) $draw['payload']['prompt'], 0, 80 ) );
$step = end( $result->steps );
msrwa_test_assert( '' === $step['error'] && ! empty( $result->artifacts['facebook']['path'] ), 'The collage is made.' );
msrwa_test_assert( $step['cost_usd'] > (float) MSRWA_Engine_Config::create( $config )->price( 'openai', 'gpt-image-2.5-flare', array( 'input_tokens' => 2000, 'output_tokens' => 1372 ) ), 'Writing the prompt is billed with the image.' );

// A redraw reuses the written prompt and adds only what was refused.
$findings = array( array( 'target' => 'facebook_image', 'severity' => 'blocking', 'reason' => 'Le dernier panneau montre le plat entier.', 'fix' => 'Ouvrir une croquette.' ) );
$method = new ReflectionMethod( 'MSRWA_Engine', 'compose_collage' );
$method->setAccessible( true );
$calls = array();
$again = $method->invoke( null, 'facebook_image', MSRWA_Engine_Config::create( $config ), $result, array(), $brief + array( 'canonical' => $fixture['canonical'], 'research' => $fixture['research'] ), MSRWA_Engine_Config::create( $config )->facebook_template(), $findings );
msrwa_test_assert( 0 === count( $calls ), 'A redraw does not write the prompt again.' );
msrwa_test_contains( $again['prompt'], 'THIS IMAGE WAS REFUSED', 'The refusal reaches the drawing.' );
msrwa_test_contains( $again['prompt'], 'Ouvrir une croquette.', 'With its fix.' );

// When the prompt cannot be written, the template's own prompt still draws.
$calls = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, $pixel ) {
	$calls[] = array( 'url' => $url, 'payload' => $payload );
	if ( false !== strpos( $url, '/images/' ) ) { return array( 'status' => 200, 'raw' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( $pixel ) ) ), 'usage' => array() ) ) ); }
	return array( 'status' => 500, 'raw' => 'down' );
};
$fallback = MSRWA_Engine::run_step( 'facebook_image', $brief, array( 'config' => $config, 'workspace' => sys_get_temp_dir() ) );
MSRWA_Engine_Call::$transport = null;
msrwa_test_assert( ! empty( $fallback->artifacts['facebook']['path'] ), 'A failed prompt-writing call still gives a collage.' );
msrwa_test_contains( implode( "\n", array_column( $fallback->events, 'message' ) ), 'drawing from the template instead', 'And says so.' );
msrwa_test_contains( (string) end( $calls )['payload']['prompt'], 'Act as a professional food photographer', 'The template prompt is the one drawn.' );

// A redraw goes out through the single-call path, which once dropped the upload
// flag and sent the reference as a form: HTTP 400 on every redraw. Every place
// that sends a planned request must pass the flag on.
foreach ( glob( dirname( __DIR__ ) . '/includes/engine/*.php' ) as $file ) {
	preg_match_all( '/http\( \$request\[\'url\'\][^;]*;/', (string) file_get_contents( $file ), $sends );
	foreach ( $sends[0] as $send ) { msrwa_test_contains( $send, 'multipart', basename( $file ) . ' sends a request without saying whether it is an upload: ' . $send ); }
}

// With the writer's photograph too: the approved collage still sets the look.
// A dim, flash-lit photograph of a rôti Orloff once replaced it and gave a
// collage nothing like the owner's. The photograph now shows the prompt's
// writer what the dish is; the image is drawn from the approved collage alone.
$photo = imagecreatetruecolor( 3, 2 );
ob_start(); imagejpeg( $photo ); $photo_bytes = ob_get_clean();
$with_photo = $brief;
$with_photo['images'] = array( array( 'file' => 'roti.jpg' ) );
$reader = static function () use ( $photo_bytes ) { return array( 'mime' => 'image/jpeg', 'data' => base64_encode( $photo_bytes ) ); };
$calls = array();
MSRWA_Engine_Call::$transport = static function ( $url, $payload ) use ( &$calls, $written, $pixel ) {
	$calls[] = array( 'url' => $url, 'payload' => $payload );
	if ( false !== strpos( $url, '/images/' ) ) { return array( 'status' => 200, 'raw' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( $pixel ) ) ), 'usage' => array() ) ) ); }
	return array( 'status' => 200, 'raw' => json_encode( array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'text' => $written ) ) ) ), 'usage' => array() ) ) );
};
MSRWA_Engine::run_step( 'facebook_image', $with_photo, array( 'config' => $config, 'workspace' => sys_get_temp_dir(), 'read_image' => $reader ) );
MSRWA_Engine_Call::$transport = null;
$shown = array_values( array_filter( (array) ( $calls[0]['payload']['input'][1]['content'] ?? array() ), static function ( $part ) { return 'input_image' === ( $part['type'] ?? '' ); } ) );
msrwa_test_assert( 2 === count( $shown ), 'The prompt’s writer sees the approved collage and the writer’s photograph; saw ' . count( $shown ) . '.' );
msrwa_test_contains( wp_json_encode( $calls[0]['payload'] ), 'never its light, colours, background or framing', 'And is told to take only the dish from the photograph.' );
$drawn = (array) ( $calls[1]['payload']['image[]'] ?? array() );
msrwa_test_assert( 2 === count( $drawn ) && isset( $drawn[0]['file'], $drawn[1]['file'] ), 'The image model is given both, the approved collage first.' );
msrwa_test_contains( (string) $calls[1]['payload']['prompt'], 'REFERENCE IMAGES: the first is the approved collage', 'And told which is which.' );

@unlink( $style );
msrwa_test_done( 'the collage prompt is written from the recipe and the reference, then drawn with it' );
