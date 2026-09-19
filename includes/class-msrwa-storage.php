<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private, bounded storage for user-provided reference images.
 * Remote media is treated as untrusted input and is never copied into uploads.
 */
final class MSRWA_Storage {
	const MAX_BYTES = 10485760;
	const MAX_WIDTH = 6000;
	const MAX_HEIGHT = 6000;

	public static function download_references( $job_id, $urls, $limit = 3 ) {
		$valid = array();
		$errors = array();
		$seen = array();
		$limit = min( 10, max( 0, absint( $limit ) ) );
		foreach ( is_array( $urls ) ? $urls : array() as $index => $url ) {
			if ( count( $valid ) >= $limit ) { break; }
			$url = esc_url_raw( is_array( $url ) && isset( $url['url'] ) ? $url['url'] : $url );
			if ( ! $url || isset( $seen[ $url ] ) ) { continue; }
			$seen[ $url ] = true;
			$result = self::download_reference( $job_id, $url, $index );
			if ( is_wp_error( $result ) ) {
				$errors[] = array( 'url' => $url, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() );
				continue;
			}
			$valid[] = $result;
		}
		return array( 'valid' => $valid, 'errors' => $errors );
	}

	public static function download_reference( $job_id, $url, $index = 0 ) {
		$url = esc_url_raw( $url );
		if ( ! self::safe_url( $url ) ) { return new WP_Error( 'reference_url_blocked', 'L’URL de référence est bloquée ou ne respecte pas les règles HTTPS.', array( 'status' => 400 ) ); }
		$dir = self::private_dir();
		if ( ! $dir ) { return new WP_Error( 'reference_storage_unavailable', 'Le stockage privé des références n’est pas disponible.' ); }
		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'redirection' => 0,
			'sslverify' => true,
			'limit_response_size' => self::MAX_BYTES + 1,
			'headers' => array( 'Accept' => 'image/jpeg,image/png,image/webp,image/gif;q=0.8,*/*;q=0.1' ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'reference_download_failed', $response->get_error_message(), array( 'status' => 502 ) ); }
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) { return new WP_Error( 'reference_http_' . absint( $code ), 'Le serveur de référence a retourné HTTP ' . absint( $code ) . '.', array( 'status' => $code ?: 502 ) ); }
		$declared = absint( wp_remote_retrieve_header( $response, 'content-length' ) );
		if ( $declared > self::MAX_BYTES ) { return new WP_Error( 'reference_too_large', 'La référence dépasse la taille maximale autorisée.' ); }
		$binary = wp_remote_retrieve_body( $response );
		if ( ! is_string( $binary ) || strlen( $binary ) < 128 || strlen( $binary ) > self::MAX_BYTES ) { return new WP_Error( 'reference_payload_invalid', 'Le contenu de référence est vide ou trop volumineux.' ); }
		$tmp = wp_tempnam( 'msrwa-reference-' . absint( $job_id ) );
		if ( ! $tmp || false === file_put_contents( $tmp, $binary, LOCK_EX ) ) { return new WP_Error( 'reference_temp_failed', 'Impossible de stocker temporairement la référence.' ); }
		$size = @getimagesize( $tmp );
		$mime = self::mime( $tmp, $size );
		$allowed = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) || ! isset( $allowed[ $mime ] ) || $size[0] > self::MAX_WIDTH || $size[1] > self::MAX_HEIGHT ) {
			@unlink( $tmp );
			return new WP_Error( 'reference_image_invalid', 'Le fichier téléchargé n’est pas une image autorisée ou ses dimensions sont excessives.' );
		}
		$name = 'msrwa-' . absint( $job_id ) . '-' . absint( $index ) . '-' . substr( hash( 'sha256', $url . microtime( true ) . wp_generate_uuid4() ), 0, 24 ) . '.' . $allowed[ $mime ];
		$target = trailingslashit( $dir ) . $name;
		if ( ! @rename( $tmp, $target ) && ( ! @copy( $tmp, $target ) || ! @unlink( $tmp ) ) ) { @unlink( $tmp ); return new WP_Error( 'reference_store_failed', 'Impossible de déplacer la référence vers le stockage privé.' ); }
		@chmod( $target, 0600 );
		return array( 'source_url' => $url, 'path' => $target, 'mime' => $mime, 'bytes' => strlen( $binary ), 'width' => absint( $size[0] ), 'height' => absint( $size[1] ), 'sha256' => hash_file( 'sha256', $target ), 'downloaded_at' => current_time( 'mysql', true ) );
	}

	public static function purge_expired( $days = 7 ) {
		$dir = self::private_dir( false );
		if ( ! $dir || ! is_dir( $dir ) ) { return 0; }
		$keep = self::protected_paths();
		$cutoff = time() - max( 1, absint( $days ) ) * DAY_IN_SECONDS;
		$count = 0;
		$files = glob( trailingslashit( $dir ) . 'msrwa-*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( ! is_file( $file ) || isset( $keep[ $file ] ) || @filemtime( $file ) >= $cutoff ) { continue; }
			if ( @unlink( $file ) ) { $count++; }
		}
		return $count;
	}

	private static function safe_url( $url ) {
		if ( ! $url || ! preg_match( '#^https://#i', $url ) ) { return false; }
		$parts = wp_parse_url( $url );
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( ! $host || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) && 443 !== absint( $parts['port'] ) ) { return false; }
		if ( preg_match( '/(?:^|\.)(?:localhost|local|internal|invalid)$/i', $host ) || 'localhost' === $host ) { return false; }
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : ( function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $host ) : array() );
		if ( empty( $ips ) ) { return false; }
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
		}
		return true;
	}

	private static function mime( $file, $size ) {
		if ( is_array( $size ) && ! empty( $size['mime'] ) ) { return sanitize_text_field( $size['mime'] ); }
		if ( function_exists( 'finfo_open' ) ) { $finfo = finfo_open( FILEINFO_MIME_TYPE ); $mime = $finfo ? finfo_file( $finfo, $file ) : ''; if ( $finfo ) { finfo_close( $finfo ); } return sanitize_text_field( $mime ); }
		return function_exists( 'mime_content_type' ) ? sanitize_text_field( mime_content_type( $file ) ) : '';
	}

	private static function private_dir( $create = true ) {
		$configured = defined( 'MSRWA_PRIVATE_DIR' ) ? (string) MSRWA_PRIVATE_DIR : '';
		$document_root = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		$base = $configured ? $configured : ( defined( 'ABSPATH' ) ? dirname( rtrim( ABSPATH, '/\\' ) ) . '/msrwa-private' : sys_get_temp_dir() . '/msrwa-private' );
		$base = rtrim( $base, '/\\' );
		if ( '' === $base || ( $document_root && 0 === strpos( realpath( dirname( $base ) ) ?: dirname( $base ), $document_root ) ) ) { $base = rtrim( sys_get_temp_dir(), '/\\' ) . '/msrwa-private'; }
		if ( $create && ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) { return ''; }
		if ( ! is_dir( $base ) || ! is_writable( $base ) ) { return ''; }
		@chmod( $base, 0700 );
		return $base;
	}

	private static function protected_paths() {
		global $wpdb;
		$paths = array();
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) { return $paths; }
		$t = MSRWA_DB::tables();
		$rows = $wpdb->get_col( "SELECT artifacts_json FROM {$t['jobs']} WHERE status NOT IN ('completed','cancelled','failed')" );
		foreach ( $rows as $json ) { self::collect_paths( json_decode( (string) $json, true ), $paths ); }
		return $paths;
	}

	private static function collect_paths( $value, &$paths ) {
		if ( is_array( $value ) ) { foreach ( $value as $item ) { self::collect_paths( $item, $paths ); } return; }
		if ( is_string( $value ) && false !== strpos( basename( $value ), 'msrwa-' ) && is_file( $value ) ) { $paths[ $value ] = true; }
	}
}
