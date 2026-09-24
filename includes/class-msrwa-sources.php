<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What each recipe was written from, kept in the plugin's own folder.
 *
 * The media library holds what a reader sees: an article's featured image and
 * its collage. The photographs a writer sends are working material — read,
 * paired, described, then written from — and used to land in the library as
 * though they were content, where a second upload of the same file collided
 * with the first. They live here instead, under uploads/msrwa/, closed to the
 * web and served to the people allowed to see them through the REST API:
 *
 *   msrwa/lots/<lot>/<hash>.<ext>               a lot's photographs, until it is sent
 *   msrwa/<run>/sources/<hash>.<ext>            the writer's photographs for one recipe
 *   msrwa/<run>/sources/references/<hash>.<ext> the photographs the engine found
 *   msrwa/<run>/history/NN-<stage>.json         what happened to them: see MSRWA_History
 *
 * A file is named after its bytes, so the same photograph sent twice is one
 * file, never a name that already exists.
 */
final class MSRWA_Sources {

	/** The media types a source may be. */
	const TYPES = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );

	/** uploads/msrwa, created closed to the web on first use. */
	public static function root() {
		$uploads = wp_upload_dir();
		$root = trailingslashit( $uploads['basedir'] ) . 'msrwa';
		if ( ! is_dir( $root ) ) { wp_mkdir_p( $root ); }
		// Apache reads the first; every server serves the second instead of a listing.
		// Nginx ignores both, which is why nothing here is named guessably.
		if ( ! is_file( $root . '/.htaccess' ) ) { @file_put_contents( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_file( $root . '/index.php' ) ) { @file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $root;
	}

	public static function lot_dir( $lot ) { return self::root() . '/lots/' . absint( $lot ); }

	public static function run_dir( $run ) { return self::root() . '/' . absint( $run ) . '/sources'; }

	/**
	 * Keeps a lot's photographs, checked already, and returns them as the lot
	 * records them. The type comes from the bytes, as it was checked.
	 */
	public static function receive( $lot, array $files ) {
		$dir = self::lot_dir( $lot );
		wp_mkdir_p( $dir );
		$out = array();
		$seen = array();
		foreach ( $files as $file ) {
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			$size = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$ext = self::TYPES[ $size['mime'] ?? '' ] ?? '';
			if ( '' === $ext ) { continue; }
			$name = substr( (string) hash_file( 'sha256', $tmp ), 0, 32 ) . '.' . $ext;
			// The same photograph twice in one lot is one photograph.
			if ( isset( $seen[ $name ] ) ) { continue; }
			$seen[ $name ] = true;
			if ( ! is_file( $dir . '/' . $name ) && ! @copy( $tmp, $dir . '/' . $name ) ) { continue; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$original = sanitize_file_name( (string) ( $file['name'] ?? $name ) );
			$out[] = array(
				'id' => $name,
				'title' => (string) preg_replace( '/\.[^.]+$/', '', $original ),
				'file' => $original,
				'url' => self::lot_url( $lot, $name ),
				'mime' => (string) $size['mime'],
			);
		}
		return $out;
	}

	/** Where the pairing screen shows a lot's photograph: behind the REST API's own checks. */
	public static function lot_url( $lot, $name ) {
		return rest_url( 'msrwa/v1/batches/' . absint( $lot ) . '/photos/' . rawurlencode( self::name( $name ) ) );
	}

	/** A stored name, or '' for anything else: never a path. */
	public static function name( $name ) {
		$name = (string) $name;
		return preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name ) ? $name : '';
	}

	/** One stored file as the engine and the vision calls want it: a media type and base64 bytes. */
	public static function read( $dir, $name, $max_bytes = 10000000 ) {
		$path = self::path( $dir, $name );
		if ( '' === $path ) { return array( 'error' => 'fichier introuvable' ); }
		if ( filesize( $path ) > $max_bytes ) { return array( 'error' => 'image trop lourde' ); }
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! isset( self::TYPES[ $size['mime'] ?? '' ] ) ) { return array( 'error' => 'type non pris en charge' ); }
		return array( 'mime' => (string) $size['mime'], 'data' => base64_encode( (string) file_get_contents( $path ) ) );
	}

	/** The file's real path, only if it is a stored name inside $dir. */
	public static function path( $dir, $name ) {
		$name = self::name( $name );
		$root = realpath( (string) $dir );
		$path = '' === $name || ! $root ? false : realpath( $root . '/' . $name );
		return $path && is_file( $path ) && dirname( $path ) === $root ? $path : '';
	}

	/**
	 * Moves one recipe's photographs from its lot into its run's folder and
	 * writes down the brief the engine is handed: the writer's text as the
	 * text reference, their photographs and what each shows as the visual
	 * one. A photograph belongs to one recipe, so it is moved, not copied.
	 */
	public static function hand_over( $lot, $run, array $brief ) {
		$dir = self::run_dir( $run );
		wp_mkdir_p( $dir );
		$photos = array();
		foreach ( (array) ( $brief['images'] ?? array() ) as $image ) {
			$from = self::path( self::lot_dir( $lot ), $image['id'] ?? '' );
			if ( '' === $from ) { continue; }
			if ( ! @rename( $from, $dir . '/' . basename( $from ) ) ) { @copy( $from, $dir . '/' . basename( $from ) ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$photos[] = array( 'file' => basename( $from ), 'name' => (string) ( $image['title'] ?? '' ), 'observation' => (array) ( $image['observation'] ?? array() ) );
		}
		MSRWA_History::run( $run, 'brief', array(
			'text_brief' => array( 'title' => (string) ( $brief['title'] ?? '' ), 'text' => (string) ( $brief['text'] ?? '' ) ),
			'visual_brief' => array( 'photos' => $photos, 'source' => $photos ? 'writer' : 'engine' ),
		), $lot );
	}

	/** Once every recipe has its photographs, the lot's own copies go. */
	public static function forget_lot( $lot ) { self::remove( self::lot_dir( $lot ) ); }

	/** A run's whole folder: its sources and the images the engine drew. */
	public static function forget_run( $run ) { self::remove( self::root() . '/' . absint( $run ) ); }

	/** The engine's reader for one run: the writer's photographs, by name, and nothing else. */
	public static function reader( $run ) {
		return static function ( $image, $max_bytes ) use ( $run ) {
			$name = is_array( $image ) ? (string) ( $image['id'] ?? '' ) : '';
			// A lot sent before 0.24.0 named its photographs by attachment id.
			if ( ctype_digit( $name ) ) { return MSRWA_Intake::engine_image( $image, $max_bytes ); }
			return self::read( self::run_dir( $run ), $name, $max_bytes );
		};
	}

	/**
	 * The engine's keeper for one run: a photograph it found on the web and
	 * read is kept beside the writer's, so the recipe's record says what it
	 * was written from whoever supplied it.
	 */
	public static function keeper( $run ) {
		return static function ( $url, array $image ) use ( $run ) {
			$ext = self::TYPES[ (string) ( $image['mime'] ?? '' ) ] ?? '';
			$bytes = base64_decode( (string) ( $image['data'] ?? '' ), true );
			if ( '' === $ext || ! $bytes ) { return; }
			$dir = self::run_dir( $run ) . '/references';
			wp_mkdir_p( $dir );
			$name = substr( hash( 'sha256', $bytes ), 0, 32 ) . '.' . $ext;
			if ( ! is_file( $dir . '/' . $name ) ) { @file_put_contents( $dir . '/' . $name, $bytes ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			MSRWA_History::run( $run, 'web_reference', array( 'file' => 'sources/references/' . $name, 'url' => esc_url_raw( (string) $url ), 'mime' => (string) $image['mime'] ) );
		};
	}

	/**
	 * What the engine completed of the brief: the dish, its figures,
	 * ingredients and steps as the text reference, and the photographs it was
	 * written from as the visual one — the writer's, or those it found.
	 */
	public static function complete( $run, array $research ) {
		MSRWA_History::run( $run, 'engine_brief', array(
			'text_brief' => array(
				'dish' => (array) ( $research['dish_identity'] ?? array() ),
				'outline' => (array) ( $research['recipe_outline'] ?? array() ),
				'ingredients' => array_values( (array) ( $research['ingredients'] ?? array() ) ),
				'preparation' => array_values( (array) ( $research['preparation'] ?? array() ) ),
				'references' => array_values( (array) ( $research['references'] ?? array() ) ),
			),
			'visual_brief' => array(
				'references' => array_values( (array) ( $research['visual_references'] ?? array() ) ),
				'observations' => array_values( (array) ( $research['visual_observations'] ?? array() ) ),
			),
		) );
	}

	/** Removes one directory under uploads/msrwa and everything in it, and nothing outside. */
	private static function remove( $dir ) {
		$root = realpath( self::root() );
		$dir = realpath( (string) $dir );
		if ( ! $root || ! $dir || 0 !== strpos( $dir, $root . DIRECTORY_SEPARATOR ) ) { return; }
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) { @unlink( $item->getPathname() ); } else { @rmdir( $item->getPathname() ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Streams one of a lot's photographs to someone the REST route has already
	 * let see the lot: from the lot while it is paired, from the recipe that
	 * took it once it is sent.
	 */
	public static function serve( $lot, $name, array $runs = array() ) {
		$path = self::path( self::lot_dir( $lot ), $name );
		foreach ( $runs as $run ) {
			if ( '' === $path ) { $path = self::path( self::run_dir( $run ), $name ); }
		}
		if ( '' === $path ) { return false; }
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		nocache_headers();
		header( 'Content-Type: ' . ( $size['mime'] ?? 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		return true;
	}
}
