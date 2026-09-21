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
