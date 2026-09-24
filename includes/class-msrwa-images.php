<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The image sizes and qualities the image API accepts, shared by the engine and the estimate. */
final class MSRWA_Images {

	/** Size actually requested from the provider. */
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
}
