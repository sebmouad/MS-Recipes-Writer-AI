<?php
// What a lot produces is the site's, one choice per kind of user, set by an
// administrator in the settings; the form offers nothing to pick.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'profile' );

msrwa_test_settings( array( 'lot_profiles' => array( 'writer' => 'article', 'editor' => 'featured', 'admin' => 'full' ) ) );
msrwa_test_as_editor( 7 );
msrwa_test_assert( 'writer' === MSRWA_Profile::user_kind(), 'Somebody who can only write is a writer.' );
msrwa_test_assert( 'article' === MSRWA_Profile::for_user(), 'A writer’s lot is the writers’ type.' );
$GLOBALS['msrwa_test_caps'][] = 'edit_others_posts';
msrwa_test_assert( 'editor' === MSRWA_Profile::user_kind() && 'featured' === MSRWA_Profile::for_user(), 'An editor’s lot is the editors’ type.' );
msrwa_test_as_admin();
msrwa_test_assert( 'admin' === MSRWA_Profile::user_kind() && 'full' === MSRWA_Profile::for_user(), 'An administrator’s lot is the administrators’ type.' );
msrwa_test_settings( array( 'lot_profiles' => array( 'admin' => 'nonsense' ) ) );
msrwa_test_assert( 'full' === MSRWA_Profile::for_user(), 'An unknown type falls back to the complete one.' );

msrwa_test_done( 'each kind of user gets the lot type the settings give it' );
