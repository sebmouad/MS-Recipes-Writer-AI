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
	 * Splits one submission into recipes.
	 *
	 * Writers separate recipes the way writers do: a blank line and a rule, a
	 * row of dashes, a numbered heading. Rather than guess at prose, the
	 * separator is explicit — a line of three or more dashes — and anything
	 * before the first one is a recipe too. A single recipe with no separator
	 * comes back as one recipe, which is the common case and must not need
	 * ceremony.
	 */
	public static function recipes( $text ) {
		$text = trim( (string) wp_unslash( $text ) );
		if ( '' === $text ) { return array(); }

		$blocks = preg_split( '/^\s*-{3,}\s*$/m', $text );
		$out = array();
		foreach ( (array) $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) { continue; }
			$out[] = array( 'title' => self::title_of( $block ), 'text' => $block );
		}
		return $out;
	}

	/**
	 * The recipe's title: its first non-empty line, stripped of the decoration
	 * writers put around a heading.
	 */
	private static function title_of( $block ) {
		foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			$line = trim( preg_replace( '/^(?:#{1,6}|\d+[.)]|[-*•])\s*/u', '', $line ) );
			$line = trim( $line, "*_ \t" );
			if ( '' !== $line ) { return mb_substr( $line, 0, 180 ); }
		}
		return '';
	}

	/**
	 * The uploaded photographs, as attachment ids the writer already owns.
	 *
	 * Images arrive through the media library rather than through this form, so
	 * WordPress does the storing, the resizing and the permission checks, and a
	 * photograph the writer may not read never reaches a brief.
	 */
	public static function images( $attachment_ids ) {
		$out = array();
		foreach ( (array) $attachment_ids as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) { continue; }
			$path = get_attached_file( $id );
			if ( ! $path || ! is_readable( $path ) ) { continue; }
			$out[] = array(
				'id' => $id,
				'title' => (string) get_the_title( $id ),
				'file' => basename( $path ),
				'url' => (string) wp_get_attachment_url( $id ),
				'mime' => (string) get_post_mime_type( $id ),
			);
		}
		return $out;
	}

	/** How many photographs one lot may carry. Each is described by a paid call. */
	const MAX_PHOTOS = 30;

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
	 * the browser claimed: the file is about to enter the media library.
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
	 * The photographs sent from the writer's computer, added to the media
	 * library as theirs. All or nothing: one refused file refuses the lot, and
	 * whatever was already added is removed again, so a failed submission
	 * leaves no orphan in the library.
	 *
	 * Returns array( 'ids' => attachment ids, 'error' => '' or why ).
	 */
	public static function upload( array $files, $max_bytes = 10000000 ) {
		if ( count( $files ) > self::MAX_PHOTOS ) {
			/* translators: %d is the largest number of photographs a lot may carry. */
			return array( 'ids' => array(), 'error' => sprintf( __( 'Un lot accepte au plus %d photographies.', 'ms-recipes-writer-ai' ), self::MAX_PHOTOS ) );
		}
		foreach ( $files as $file ) {
			$why = self::refuse( (array) $file, $max_bytes );
			if ( '' !== $why ) { return array( 'ids' => array(), 'error' => $why ); }
		}
		if ( ! function_exists( 'media_handle_sideload' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/media.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$ids = array();
		foreach ( $files as $file ) {
			$id = media_handle_sideload( array( 'name' => sanitize_file_name( (string) $file['name'] ), 'tmp_name' => (string) $file['tmp_name'] ), 0 );
			if ( is_wp_error( $id ) ) {
				self::discard( $ids );
				return array( 'ids' => array(), 'error' => sprintf( '%s : %s', sanitize_file_name( (string) $file['name'] ), $id->get_error_message() ) );
			}
			$ids[] = (int) $id;
		}
		return array( 'ids' => $ids, 'error' => '' );
	}

	/** Removes photographs a submission added and then could not use. */
	public static function discard( array $ids ) {
		foreach ( $ids as $id ) { wp_delete_attachment( absint( $id ), true ); }
	}

	/** One image as the engine's vision call wants it: a media type and base64 bytes. */
	public static function read( $attachment_id, $max_bytes = 10000000 ) {
		$path = get_attached_file( absint( $attachment_id ) );
		if ( ! $path || ! is_readable( $path ) ) { return array( 'error' => 'fichier introuvable' ); }
		if ( filesize( $path ) > $max_bytes ) { return array( 'error' => 'image trop lourde' ); }
		$mime = (string) get_post_mime_type( absint( $attachment_id ) );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) { return array( 'error' => 'type non pris en charge : ' . $mime ); }
		return array( 'mime' => $mime, 'data' => base64_encode( (string) file_get_contents( $path ) ) );
	}
}
