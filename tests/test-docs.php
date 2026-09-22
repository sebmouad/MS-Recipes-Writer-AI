<?php
// Documentation rots quietly: a file moves, twenty links keep pointing at where
// it used to be, and the next person to open one decides the docs are stale and
// stops reading them. This holds the layout in place and fails on the first
// link that no longer resolves.
require __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

// Only the owner's README and the entry point an agent reads first live at the
// root. Everything else is filed under .claude/docs/.
$at_root = array_map( 'basename', (array) glob( $root . '/*.md' ) );
sort( $at_root );
msrwa_test_assert(
	array( 'CLAUDE.md', 'README.md' ) === $at_root,
	'The root carries README.md and CLAUDE.md, nothing else; found: ' . implode( ', ', $at_root )
);

foreach ( array( 'ARCHITECTURE', 'BUILD-CHECKLIST', 'ENGINE', 'LAB', 'LAB-RESULTS', 'PLAN', 'TESTING' ) as $document ) {
	msrwa_test_assert(
		is_readable( $root . '/.claude/docs/' . $document . '.md' ),
		'.claude/docs/' . $document . '.md is where the repository map says it is.'
	);
}

// Every relative link in every document must resolve. External links are the
// web's problem; a path inside the repository is ours.
$documents = array_merge(
	(array) glob( $root . '/*.md' ),
	(array) glob( $root . '/.claude/docs/*.md' )
);
$broken = array();
foreach ( $documents as $document ) {
	$text = (string) file_get_contents( $document );
	if ( ! preg_match_all( '/\]\(([^)\s#]+)(?:#[^)\s]*)?\)/', $text, $matches ) ) { continue; }
	foreach ( $matches[1] as $target ) {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $target ) || 0 === strpos( $target, 'mailto:' ) ) { continue; }
		$resolved = realpath( dirname( $document ) . '/' . $target );
		if ( false === $resolved ) { $broken[] = basename( $document ) . ' → ' . $target; }
	}
}
msrwa_test_assert( ! $broken, count( $broken ) . ' link(s) point nowhere: ' . implode( ', ', array_slice( $broken, 0, 8 ) ) );

// The three documents a change is supposed to touch are named in CLAUDE.md, so
// a rename that misses one is caught here rather than by the next reader.
$claude = (string) file_get_contents( $root . '/CLAUDE.md' );
foreach ( array( 'ARCHITECTURE.md', 'BUILD-CHECKLIST.md', 'ENGINE.md', 'TESTING.md', 'PLAN.md' ) as $named ) {
	msrwa_test_contains( $claude, $named, 'CLAUDE.md still points a newcomer at ' . $named . '.' );
}

msrwa_test_done( 'documentation layout and links' );
