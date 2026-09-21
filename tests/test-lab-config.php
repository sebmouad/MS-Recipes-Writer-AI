<?php
// The Moteur screen stores the difference from the engine's defaults, never a
// copy of them. That is what lets the engine change its mind later without
// every site being pinned to whatever was current the day somebody saved.
require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/engine/load.php';
require_once dirname( __DIR__ ) . '/includes/class-msrwa-lab-config.php';

$difference = new ReflectionMethod( MSRWA_Lab_Config::class, 'difference' );
$difference->setAccessible( true );
$diff = function ( $value, $default ) use ( $difference ) { return $difference->invoke( null, $value, $default ); };

// Submitting the defaults back stores nothing at all.
$defaults = array( 'budget_usd' => 0.0, 'concurrency' => 4, 'nested' => array( 'a' => 1, 'b' => 2 ) );
msrwa_test_assert( array() === $diff( $defaults, $defaults ), 'Saving the defaults unchanged must store nothing.' );

// One changed value is stored, and only that one.
$changed = $diff( array( 'budget_usd' => 0.0, 'concurrency' => 8, 'nested' => array( 'a' => 1, 'b' => 2 ) ), $defaults );
msrwa_test_assert( array( 'concurrency' => 8 ) === $changed, 'Only the value that moved is stored; got ' . json_encode( $changed ) );

// A change buried in a nested group keeps its path and drops its siblings.
$nested = $diff( array( 'nested' => array( 'a' => 1, 'b' => 99 ) ), $defaults );
msrwa_test_assert( array( 'nested' => array( 'b' => 99 ) ) === $nested, 'A nested change keeps its path only; got ' . json_encode( $nested ) );

// A key the engine does not have is a caller adding something, and is kept.
$added = $diff( array( 'ma_propre_etape' => array( 'needs' => array() ) ), $defaults );
msrwa_test_assert( isset( $added['ma_propre_etape'] ), 'A key the engine does not ship must still be stored.' );

// Types matter: "4" is not 4, because one of them reaches a provider as text.
msrwa_test_assert( array( 'concurrency' => '4' ) === $diff( array( 'concurrency' => '4' ), $defaults ), 'A value that changed type has changed.' );

// What the screen offers must actually be what the engine has, or a group
// would silently never be editable.
$engine = array_keys( MSRWA_Engine_Config::create()->to_array() );
$simple = new ReflectionMethod( MSRWA_Lab_Config::class, 'simple' );
$simple->setAccessible( true );
$structural = new ReflectionMethod( MSRWA_Lab_Config::class, 'structural' );
$structural->setAccessible( true );
$offered = array_keys( array_merge( $simple->invoke( null ), $structural->invoke( null ) ) );

foreach ( $offered as $group ) {
	msrwa_test_assert( in_array( $group, $engine, true ), 'The screen offers "' . $group . '", which the engine does not have.' );
}
// `language` has its own field; `settings` holds the keys and is edited on the
// configuration screen; `_provenance` is the record of who decided what, not a
// setting. Everything else the engine carries must be reachable from here.
foreach ( array_diff( $engine, $offered, array( 'language', 'settings', '_provenance' ) ) as $group ) {
	msrwa_test_assert( false, 'The engine carries "' . $group . '", which no field on the screen can reach.' );
}

msrwa_test_done( 'engine configuration screen OK' );
