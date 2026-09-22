<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Images {
	public static function validate( $image ) {
		$id = isset( $image['attachment_id'] ) ? absint( $image['attachment_id'] ) : 0;
		if ( ! $id || ! get_attached_file( $id ) || ! wp_attachment_is_image( $id ) ) { return new WP_Error( 'image_invalid', __( 'Le média image généré est introuvable ou invalide.', 'ms-recipes-writer-ai' ) ); }
		$meta = wp_get_attachment_metadata( $id );
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) { return new WP_Error( 'image_dimensions_missing', __( 'Les dimensions de l’image générée sont absentes.', 'ms-recipes-writer-ai' ) ); }
		return true;
	}

	/**
	 * Research and user references establish a visual direction only. Source media
	 * is never sent to an image generator as a reusable asset.
	 */
	private static function visual_context( $artifacts ) {
		$research = isset( $artifacts['research'] ) && is_array( $artifacts['research'] ) ? $artifacts['research'] : array();
		$visual = isset( $artifacts['visual_research']['references'] ) && is_array( $artifacts['visual_research']['references'] ) ? $artifacts['visual_research']['references'] : array();
		$observations = array();
		foreach ( array_slice( $visual, 0, 10 ) as $reference ) {
			if ( is_array( $reference ) && ! empty( $reference['analysis'] ) ) { $observations[] = $reference['analysis']; }
		}
		$context = array(
			'direction' => isset( $research['visual_direction'] ) ? $research['visual_direction'] : array(),
			'observations' => $observations,
			'rule' => 'Use these as abstract visual guidance only. Do not reproduce, imitate closely, include branding from, or claim affiliation with any reference image or source.',
		);
		if ( empty( $context['direction'] ) && empty( $context['observations'] ) ) { return ''; }
		return '\nDIRECTION VISUELLE DE RECHERCHE (observations uniquement, sans réutiliser ni reproduire une image source) : ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function correction_context( $artifacts, $key, $settings ) {
		$context = isset( $artifacts['image_correction_context'][ $key ] ) && is_array( $artifacts['image_correction_context'][ $key ] ) ? $artifacts['image_correction_context'][ $key ] : array();
		if ( empty( $context ) || empty( $settings['prompt_image_correction'] ) ) { return ''; }
		return '\nCORRECTION IMAGE : ' . $settings['prompt_image_correction'] . '\nDÉFAUTS À CORRIGER : ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/** Size actually requested from the provider. Shared with MSRWA_Cost so the estimate matches. */
	public static function native_size( $ratio, $fallback ) {
		$sizes = array( '1:1' => '1024x1024', '3:2' => '1536x1024', '2:3' => '1024x1536', '4:5' => '1024x1536' );
		return isset( $sizes[ $ratio ] ) ? $sizes[ $ratio ] : $fallback;
	}

	/** The qualities the image API accepts, in ascending cost. */
	public static function qualities() { return array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' ); }

	/**
	 * Quality for one of the two images. They are priced independently and judged
	 * independently, so the administrator sets each. `image_quality` is read as a
	 * fallback for sites configured before the split.
	 */
	public static function quality( $settings, $kind = 'featured' ) {
		foreach ( array( $kind . '_image_quality', 'image_quality' ) as $key ) {
			$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			if ( in_array( $value, self::qualities(), true ) ) { return $value; }
		}
		return 'medium';
	}

	private static function format( $settings ) {
		$value = isset( $settings['image_format'] ) ? $settings['image_format'] : 'webp';
		return in_array( $value, array( 'webp', 'jpeg', 'png' ), true ) ? $value : 'webp';
	}

	private static function crop_ratio( $attachment_id, $ratio, $format = 'webp', $fit = 'cover', $padding_color = '#ffffff' ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) { return new WP_Error( 'image_file_missing', __( 'Fichier image introuvable pour le recadrage.', 'ms-recipes-writer-ai' ) ); }
		$parts = array_map( 'absint', explode( ':', (string) $ratio ) );
		if ( count( $parts ) !== 2 || ! $parts[0] || ! $parts[1] ) { return $attachment_id; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) { return $editor; }
		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) { return new WP_Error( 'image_size_missing', __( 'Dimensions image indisponibles.', 'ms-recipes-writer-ai' ) ); }
		$target_ratio = $parts[0] / $parts[1];
		$source_ratio = $size['width'] / $size['height'];
		$width = $size['width'];
		$height = $size['height'];
		if ( 'contain' === $fit ) {
			if ( $source_ratio > $target_ratio ) { $height = (int) ceil( $width / $target_ratio ); } else { $width = (int) ceil( $height * $target_ratio ); }
		} elseif ( $source_ratio > $target_ratio ) { $width = (int) round( $height * $target_ratio ); } else { $height = (int) round( $width / $target_ratio ); }
		if ( $width === (int) $size['width'] && $height === (int) $size['height'] ) { return $attachment_id; }
		if ( 'contain' !== $fit ) {
			$resized = $editor->resize( $width, $height, true );
			if ( is_wp_error( $resized ) ) { return $resized; }
		}
		$format = in_array( $format, array( 'webp', 'jpeg', 'png' ), true ) ? $format : 'webp';
		$mime = 'jpeg' === $format ? 'image/jpeg' : 'image/' . $format;
		$target = trailingslashit( dirname( $file ) ) . sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) . '.' . $format );
		if ( 'contain' === $fit ) {
			$saved = self::pad_image( $file, $target, $width, $height, $padding_color, $format );
		} else { $saved = $editor->save( $target, $mime ); }
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( ! empty( $saved['path'] ) && $saved['path'] !== $file && file_exists( $file ) ) { wp_delete_file( $file ); }
		$path = ! empty( $saved['path'] ) ? $saved['path'] : $target;
		update_attached_file( $attachment_id, $path );
		wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $mime ) );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		return $attachment_id;
	}

	/** Preserve every collage panel when the delivery ratio differs from the API ratio. */
	private static function pad_image( $file, $target, $width, $height, $color, $format ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) { return new WP_Error( 'image_padding_unavailable', __( 'GD est nécessaire pour conserver le collage entier au ratio demandé.', 'ms-recipes-writer-ai' ) ); }
		$source = imagecreatefromstring( file_get_contents( $file ) );
		if ( ! $source ) { return new WP_Error( 'image_decode_failed', __( 'Impossible de lire le collage généré.', 'ms-recipes-writer-ai' ) ); }
		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) { imagedestroy( $source ); return new WP_Error( 'image_canvas_failed', __( 'Impossible de préparer le format image.', 'ms-recipes-writer-ai' ) ); }
		$hex = ltrim( $color, '#' );
		$background = imagecolorallocate( $canvas, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		imagefill( $canvas, 0, 0, $background );
		imagecopy( $canvas, $source, (int) floor( ( $width - imagesx( $source ) ) / 2 ), (int) floor( ( $height - imagesy( $source ) ) / 2 ), 0, 0, imagesx( $source ), imagesy( $source ) );
		$writer = 'jpeg' === $format ? 'imagejpeg' : ( 'png' === $format ? 'imagepng' : 'imagewebp' );
		$ok = function_exists( $writer ) && $writer( $canvas, $target );
		imagedestroy( $source );
		imagedestroy( $canvas );
		return $ok ? array( 'path' => $target ) : new WP_Error( 'image_padding_save_failed', __( 'Impossible de sauvegarder le collage au format demandé.', 'ms-recipes-writer-ai' ) );
	}
}
