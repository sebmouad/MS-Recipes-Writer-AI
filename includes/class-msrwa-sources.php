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
 *   msrwa/lots/<lot>/lot<lot>-photo<n>-<hash>.<ext>  a lot's photographs, until it is sent
 *   msrwa/<run>/sources/lot<lot>-photo<n>-<hash>.<ext> the writer's photographs for one recipe
 *   msrwa/<run>/sources/references/<hash>.<ext> the photographs the engine found
 *   msrwa/<run>/history/NN-<stage>.json         what happened to them: see MSRWA_History
 *
 * A writer's photograph, uploaded or pasted, is named by its lot and its
 * place in it — `lot81-photo2` — which every report and screen shows, then
 * by its bytes, so the name is unique, not guessable, and the same
 * photograph sent twice in a lot is one file. Photographs kept before
 * 0.28.19 are named by their bytes alone and are still read.
 */
final class MSRWA_Sources {

	/** The media types a source may be. */
	const TYPES = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );

	/** A stored name, as a pattern: the REST route and name() read the same one. */
	const NAME = '(?:lot\d+-photo\d+-[a-f0-9]{24}|[a-f0-9]{32})\.(?:jpg|png|webp)';

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
			$hash = substr( (string) hash_file( 'sha256', $tmp ), 0, 24 );
			// The same photograph twice in one lot is one photograph.
			if ( isset( $seen[ $hash ] ) ) { continue; }
			$seen[ $hash ] = true;
			$ref = 'lot' . absint( $lot ) . '-photo' . count( $seen );
			$name = $ref . '-' . $hash . '.' . $ext;
			if ( ! is_file( $dir . '/' . $name ) && ! @copy( $tmp, $dir . '/' . $name ) ) { unset( $seen[ $hash ] ); continue; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$origin = in_array( $file['msrwa_origin'] ?? '', array( 'pasted', 'url' ), true ) ? $file['msrwa_origin'] : 'upload';
			$pasted = 'pasted' === $origin;
			$original = sanitize_file_name( (string) ( $file['name'] ?? $name ) );
			$out[] = array(
				'id' => $name,
				'ref' => $ref,
				'origin' => $origin,
				'source' => 'url' === $origin ? esc_url_raw( (string) ( $file['msrwa_source'] ?? '' ) ) : '',
				// A pasted image's name is the browser's, not the writer's: it
				// would reach the vision call as a hint about the dish.
				'title' => $pasted ? '' : (string) preg_replace( '/\.[^.]+$/', '', $original ),
				'file' => $original,
				'url' => self::lot_url( $lot, $name ),
				'mime' => (string) $size['mime'],
			);
		}
		return $out;
	}

	/** The photograph's identifier as people read it — `lot81-photo2` — from its stored name. */
	public static function ref( $name ) {
		$name = self::name( basename( (string) $name ) );
		if ( preg_match( '/^(lot\d+-photo\d+)-/', $name, $match ) ) { return $match[1]; }
		return '' === $name ? '' : substr( $name, 0, 8 );
	}

	/** How a lot's photograph is named on a screen: its identifier, then where it came from. */
	public static function label( array $image ) {
		$ref = (string) ( $image['ref'] ?? self::ref( (string) ( $image['id'] ?? '' ) ) );
		$origin = (string) ( $image['origin'] ?? '' );
		$from = 'pasted' === $origin ? __( 'image collée', 'ms-recipes-writer-ai' )
			: ( 'url' === $origin && '' !== (string) ( $image['source'] ?? '' ) ? (string) wp_parse_url( (string) $image['source'], PHP_URL_HOST ) . ' · ' . (string) ( $image['file'] ?? '' ) : (string) ( $image['file'] ?? '' ) );
		if ( '' === $ref ) { return $from; }
		return '' === $from || $from === $ref ? $ref : $ref . ' · ' . $from;
	}

	/** Where the pairing screen shows a lot's photograph: behind the REST API's own checks. */
	public static function lot_url( $lot, $name ) {
		return rest_url( 'msrwa/v1/batches/' . absint( $lot ) . '/photos/' . rawurlencode( self::name( $name ) ) );
	}

	/** A stored name, or '' for anything else: never a path. */
	public static function name( $name ) {
		$name = (string) $name;
		return preg_match( '/^' . self::NAME . '$/', $name ) ? $name : '';
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
			$photos[] = array( 'file' => basename( $from ), 'ref' => self::ref( basename( $from ) ), 'name' => (string) ( $image['title'] ?? '' ), 'observation' => (array) ( $image['observation'] ?? array() ) );
		}
		MSRWA_History::run( $run, 'brief', array(
			'text_brief' => array( 'title' => (string) ( $brief['title'] ?? '' ), 'text' => (string) ( $brief['text'] ?? '' ) ),
			'visual_brief' => array( 'photos' => $photos, 'source' => $photos ? 'writer' : 'engine' ),
		), $lot );
	}

	/** Once every recipe has its photographs, the lot's own copies go. */
	public static function forget_lot( $lot ) { self::remove( self::lot_dir( $lot ) ); }

	/** The photographs a run was written from — the writer's and the web's — and nothing it drew. */
	public static function forget_sources( $run ) { self::remove( self::run_dir( $run ) ); }

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

	/** How many style references are kept: the engine draws from the first. */
	const STYLE_MAX = 3;

	/**
	 * The owner's own collages, whose look a Facebook collage keeps when the
	 * writer sent no photograph of the dish. Kept here, closed to the web, and
	 * handed to the engine as paths.
	 */
	public static function style_dir() { return self::root() . '/style'; }

	/** The stored style references, oldest first: the first is the one drawn from. */
	public static function style_paths() {
		$paths = array();
		foreach ( (array) glob( self::style_dir() . '/*' ) as $path ) {
			if ( '' !== self::name( basename( (string) $path ) ) && is_file( $path ) ) { $paths[ (string) $path ] = (int) filemtime( $path ); }
		}
		asort( $paths );
		return array_slice( array_keys( $paths ), 0, self::STYLE_MAX );
	}

	/**
	 * The collage every test was drawn from — the owner's own rôti Orloff —
	 * shipped with the plugin, so a site that uploaded nothing still gets the
	 * approved look instead of a collage drawn from the text alone.
	 */
	public static function default_style() {
		$path = ( defined( 'MSRWA_DIR' ) ? MSRWA_DIR : dirname( __DIR__ ) . '/' ) . 'assets/style/facebook-reference.jpg';
		return is_file( $path ) ? $path : '';
	}

	/** What the engine draws from: the site's own references, or the shipped one. */
	public static function style_in_use() {
		$paths = self::style_paths();
		if ( ! $paths && '' !== self::default_style() ) { $paths = array( self::default_style() ); }
		return $paths;
	}

	/** Keeps one uploaded image as a style reference. Returns '' or why it was refused. */
	public static function add_style( array $file ) {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) && ! is_file( $tmp ) ) { return __( 'Aucune image reçue.', 'ms-recipes-writer-ai' ); }
		if ( count( self::style_paths() ) >= self::STYLE_MAX ) { return __( 'Trois références au plus : retirez-en une d’abord.', 'ms-recipes-writer-ai' ); }
		if ( filesize( $tmp ) > 10000000 ) { return __( 'Image trop lourde : 10 Mo au plus.', 'ms-recipes-writer-ai' ); }
		$size = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ext = self::TYPES[ $size['mime'] ?? '' ] ?? '';
		if ( '' === $ext ) { return __( 'Seules les images JPEG, PNG et WebP sont acceptées.', 'ms-recipes-writer-ai' ); }
		wp_mkdir_p( self::style_dir() );
		$target = self::style_dir() . '/' . substr( (string) hash_file( 'sha256', $tmp ), 0, 32 ) . '.' . $ext;
		if ( ! is_file( $target ) && ! @copy( $tmp, $target ) ) { return __( 'L’image n’a pas pu être enregistrée.', 'ms-recipes-writer-ai' ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return '';
	}

	public static function remove_style( $name ) {
		$path = self::path( self::style_dir(), $name );
		if ( '' !== $path ) { @unlink( $path ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** A small preview of a stored reference, for the settings screen: the folder is closed to the web. */
	public static function style_preview( $path, $width = 180 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) { return ''; }
		$image = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $image ) { return ''; }
		$small = imagescale( $image, $width );
		ob_start();
		imagejpeg( $small, null, 80 );
		return 'data:image/jpeg;base64,' . base64_encode( (string) ob_get_clean() );
	}

	/** Removes one directory under uploads/msrwa and everything in it, and nothing outside. */
	/**
	 * Every folder under uploads/msrwa but the style references: the lots, the
	 * runs, their photographs, drawn images and history. What a data reset
	 * clears; the style references are a setting.
	 */
	public static function forget_work() {
		foreach ( (array) glob( self::root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( 'style' !== basename( (string) $dir ) ) { self::remove( $dir ); }
		}
	}

	/** The uploaded style references, so the shipped one serves again. */
	public static function forget_styles() { self::remove( self::style_dir() ); }

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
