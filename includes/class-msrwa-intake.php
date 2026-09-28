<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * What the writer handed over, turned into something countable.
 *
 * One paste holding several recipes, and a pile of photographs with no stated
 * order. Nothing here decides which photograph belongs to which recipe — that
 * is the matching step's job, and it costs money. This only separates the
 * recipes and reads the images off disk.
 */
final class MSRWA_Intake {

	/**
	 * Splits one submission into recipes, as a first guess.
	 *
	 * Writers lay recipes out as they please: one dish per line, each recipe
	 * with its ingredients and steps, a blank line between them or none. The
	 * lot's reading (MSRWA_Match) finds them properly; this guess is what the
	 * estimate counts before anything is sent, and what the lot falls back on
	 * when the reading cannot be had. A line of dashes still separates, as it
	 * did; otherwise blank lines do, and a block of three short lines or more
	 * without punctuation is a list of dish names, one recipe each.
	 */
	public static function recipes( $text ) {
		$text = self::text( $text );
		if ( '' === $text ) { return array(); }

		$ruled = (bool) preg_match( '/^\s*-{3,}\s*$/m', $text );
		$blocks = $ruled ? preg_split( '/^\s*-{3,}\s*$/m', $text ) : preg_split( '/\n\s*\n/', $text );
		$out = array();
		foreach ( (array) $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) { continue; }
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $block ) ), 'strlen' ) );
			if ( ! $ruled && count( $lines ) > 2 && self::names_only( $lines ) ) {
				foreach ( $lines as $line ) { $out[] = array( 'title' => self::title_of( $line ), 'text' => $line ); }
				continue;
			}
			$out[] = array( 'title' => self::title_of( $block ), 'text' => $block );
		}
		return $out;
	}

	/** The submission as typed, without the slashes WordPress may have added. */
	public static function text( $text ) { return trim( str_replace( "\r\n", "\n", (string) wp_unslash( $text ) ) ); }

	/**
	 * Whether these lines read as a list of dish names: each short, none with
	 * the comma, colon or quantity of an ingredient line or a step.
	 */
	private static function names_only( array $lines ) {
		foreach ( $lines as $line ) {
			$line = preg_replace( '/^(?:#{1,6}|\d+[.)]|[-*•])\s*/u', '', $line );
			if ( mb_strlen( $line ) > 60 || preg_match( '/[,;:]|\d/u', $line ) ) { return false; }
		}
		return true;
	}

	/**
	 * The recipe's title: its first non-empty line, stripped of the decoration
	 * writers put around a heading. The reading names the dish itself; this is
	 * the title when it does not.
	 */
	public static function title_of( $block ) {
		foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			$line = trim( preg_replace( '/^(?:#{1,6}|\d+[.)]|[-*•])\s*/u', '', $line ) );
			$line = trim( $line, "*_ \t" );
			$line = self::plain_title( $line );
			if ( '' !== $line ) { return mb_substr( $line, 0, 180 ); }
		}
		return '';
	}

	/**
	 * A recipe's title as plain words: no emoji or pictograph. Writers paste
	 * titles from Facebook with "✨😋" on them; a dish's name does not carry
	 * them into a post, and WordPress's admin drew each as a broken image
	 * wherever its emoji styles were removed. Letters of every script, digits
	 * and ordinary punctuation stay.
	 */
	public static function plain_title( $title ) {
		$title = (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{2190}-\x{21FF}\x{25A0}-\x{25FF}\x{2900}-\x{297F}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', (string) $title );
		return trim( (string) preg_replace( '/\s{2,}/u', ' ', $title ), " \t\n\r\0\x0B-–—|·,;:" );
	}

	/** How many photographs one lot may carry. Each is described by a paid call. */
	const MAX_PHOTOS = 30;

	/**
	 * Marked a library photograph as one a writer sent with a lot. Photographs
	 * are kept in MSRWA_Sources since 0.24.0; lots sent before still name
	 * these, and are read and cleaned up through them.
	 */
	const SENT = '_msrwa_sent_by_writer';

	/** What a photograph may be: the types every provider's vision reads. */
	const PHOTO_TYPES = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );

	/**
	 * The files of one form field as a flat list. PHP spreads a multiple upload
	 * across parallel arrays — every name, then every tmp_name — which is the
	 * wrong way round for checking one file at a time.
	 */
	public static function files( $field ) {
		if ( ! is_array( $field ) || ! isset( $field['name'] ) ) { return array(); }
		if ( ! is_array( $field['name'] ) ) { return array( $field ); }
		$out = array();
		foreach ( array_keys( $field['name'] ) as $i ) {
			$file = array();
			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) { $file[ $key ] = $field[ $key ][ $i ] ?? ''; }
			if ( '' === (string) $file['name'] && UPLOAD_ERR_NO_FILE === (int) $file['error'] ) { continue; }
			$out[] = $file;
		}
		return $out;
	}

	/**
	 * Why one uploaded file cannot be a photograph, or '' when it can.
	 *
	 * The type is read from the bytes, never taken from the name or from what
	 * the browser claimed: the file is about to be kept on the site's disk.
	 */
	public static function refuse( array $file, $max_bytes = 10000000 ) {
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			/* translators: %s is a file name. */
			return sprintf( __( '%s n’a pas été reçu en entier.', 'ms-recipes-writer-ai' ), $name );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			/* translators: %s is a file name. */
			return sprintf( __( '%s n’a pas été reçu en entier.', 'ms-recipes-writer-ai' ), $name );
		}
		if ( filesize( $tmp ) > $max_bytes ) {
			/* translators: 1: a file name, 2: the largest size allowed, in megabytes. */
			return sprintf( __( '%1$s dépasse %2$s Mo.', 'ms-recipes-writer-ai' ), $name, (string) floor( $max_bytes / 1000000 ) );
		}
		$checked = wp_check_filetype_and_ext( $tmp, $name, self::PHOTO_TYPES );
		$size = @getimagesize( $tmp );
		if ( empty( $checked['type'] ) || ! in_array( $checked['type'], self::PHOTO_TYPES, true ) || ! $size || ! in_array( $size['mime'] ?? '', self::PHOTO_TYPES, true ) ) {
			/* translators: %s is a file name. */
			return sprintf( __( '%s n’est pas une photographie JPEG, PNG ou WebP.', 'ms-recipes-writer-ai' ), $name );
		}
		return '';
	}

	/**
	 * Images given by their address, fetched by the site and handed back as
	 * uploaded files, so the same checks and the same storage apply.
	 *
	 * The browser cannot fetch them itself — another site's image is not its
	 * to read — so the server does, through the engine's own fetch: HTTPS
	 * only, public addresses only, the checked address pinned, no redirect
	 * followed, the size capped while it downloads.
	 *
	 * @return array{files: array, error: string}
	 */
	public static function from_urls( array $urls, $max_bytes = 10000000 ) {
		$urls = array_values( array_unique( array_filter( array_map( static function ( $url ) {
			$url = trim( (string) $url );
			return preg_match( '#^https://#i', $url ) ? esc_url_raw( $url, array( 'https' ) ) : '';
		}, $urls ) ) ) );
		if ( ! $urls ) { return array( 'files' => array(), 'error' => '' ); }
		if ( count( $urls ) > self::MAX_PHOTOS ) {
			/* translators: %d is the largest number of photographs a lot may carry. */
			return array( 'files' => array(), 'error' => sprintf( __( 'Un lot accepte au plus %d photographies.', 'ms-recipes-writer-ai' ), self::MAX_PHOTOS ) );
		}
		$fetched = MSRWA_Engine_Call::fetch_images( $urls, (int) $max_bytes, count( $urls ) );
		$files = array();
		foreach ( $urls as $key => $url ) {
			$image = (array) ( $fetched[ $key ] ?? array() );
			// The engine's reason names an HTTP status, and a writer is never
			// shown one: what to check is said instead.
			if ( empty( $image['data'] ) ) {
				self::discard( $files );
				/* translators: %s is a web address. */
				return array( 'files' => array(), 'error' => sprintf( __( 'L’image à l’adresse %s n’a pas pu être récupérée. Vérifiez que l’adresse mène directement à une image JPEG, PNG ou WebP, qu’elle commence par https:// et qu’elle s’ouvre dans un navigateur.', 'ms-recipes-writer-ai' ), $url ) );
			}
			// wp_tempnam() lives in wp-admin and is not loaded for a REST request.
			$tmp = (string) tempnam( get_temp_dir(), 'msrwa-url' );
			file_put_contents( $tmp, base64_decode( (string) $image['data'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$name = sanitize_file_name( rawurldecode( basename( $path ) ) );
			$ext = array_search( (string) ( $image['mime'] ?? '' ), array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ), true );
			if ( '' === $name || ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $name ) ) { $name = ( '' !== preg_replace( '/\.[^.]*$/', '', $name ) ? preg_replace( '/\.[^.]*$/', '', $name ) : 'image' ) . '.' . ( $ext ? $ext : 'jpg' ); }
			$files[] = array(
				'name' => $name, 'type' => (string) ( $image['mime'] ?? '' ), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize( $tmp ),
				'msrwa_origin' => 'url',
				// Where it came from, without its query: an address can carry a token.
				'msrwa_source' => strtok( $url, '?#' ),
			);
		}
		return array( 'files' => $files, 'error' => '' );
	}

	/** Removes the temporary copies of images fetched by address. */
	public static function discard( array $files ) {
		foreach ( $files as $file ) {
			if ( 'url' === ( $file['msrwa_origin'] ?? '' ) && is_file( (string) $file['tmp_name'] ) ) { wp_delete_file( (string) $file['tmp_name'] ); }
		}
	}

	/**
	 * Why a lot's photographs cannot be kept, or '' when they can. All or
	 * nothing: one refused file refuses the lot, before anything is stored.
	 */
	public static function check( array $files, $max_bytes = 10000000 ) {
		if ( count( $files ) > self::MAX_PHOTOS ) {
			/* translators: %d is the largest number of photographs a lot may carry. */
			return sprintf( __( 'Un lot accepte au plus %d photographies.', 'ms-recipes-writer-ai' ), self::MAX_PHOTOS );
		}
		foreach ( $files as $file ) {
			$why = self::refuse( (array) $file, $max_bytes );
			if ( '' !== $why ) { return $why; }
		}
		return '';
	}

	/** Whether a library item is a photograph a writer sent with a lot and nothing has taken yet. */
	public static function loose( $id ) {
		$post = get_post( absint( $id ) );
		return $post && 'attachment' === $post->post_type && ! (int) $post->post_parent && get_post_meta( $post->ID, self::SENT, true );
	}

	/** Removes a deleted lot's photographs that no draft took. */
	public static function forget( array $ids ) {
		foreach ( $ids as $id ) {
			if ( self::loose( $id ) ) { wp_delete_attachment( absint( $id ), true ); }
		}
	}

	/**
	 * One of a brief's photographs, for the engine: read by its attachment id,
	 * and only if it is a photograph a writer sent — a brief can name nothing
	 * else on this site's disk.
	 */
	public static function engine_image( $image, $max_bytes = 10000000 ) {
		$id = absint( is_array( $image ) ? ( $image['id'] ?? 0 ) : 0 );
		if ( ! $id || ! get_post_meta( $id, self::SENT, true ) ) { return array( 'error' => 'not a photograph sent with the lot' ); }
		return self::read( $id, $max_bytes );
	}

	public static function read( $attachment_id, $max_bytes = 10000000 ) {
		$path = get_attached_file( absint( $attachment_id ) );
		if ( ! $path || ! is_readable( $path ) ) { return array( 'error' => 'fichier introuvable' ); }
		if ( filesize( $path ) > $max_bytes ) { return array( 'error' => 'image trop lourde' ); }
		$mime = (string) get_post_mime_type( absint( $attachment_id ) );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) { return array( 'error' => 'type non pris en charge : ' . $mime ); }
		return array( 'mime' => $mime, 'data' => base64_encode( (string) file_get_contents( $path ) ) );
	}
}
