<?php
// An administrator closes some types of lot to editors. The form offers them
// only what is open, the API refuses the rest, administrators keep all three,
// and at least one type always stays open.
require __DIR__ . '/bootstrap.php';
msrwa_test_load( 'rights', 'profile' );

msrwa_test_settings( array( 'editor_profiles' => array( 'article', 'featured' ) ) );
msrwa_test_as_editor( 7 );
msrwa_test_assert( array( 'featured', 'article' ) === array_keys( MSRWA_Profile::offered() ), 'An editor is offered only the open types.' );
msrwa_test_assert( ! MSRWA_Profile::allowed( 'full' ) && MSRWA_Profile::allowed( 'article' ), 'A closed type is not allowed to an editor.' );

msrwa_test_as_admin();
msrwa_test_assert( 3 === count( MSRWA_Profile::offered() ) && MSRWA_Profile::allowed( 'full' ), 'An administrator keeps all three.' );

msrwa_test_done( 'types of lot closed to editors stay closed, and one always stays open' );
