<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Images {
	public static function featured( $job, $artifacts ) {
		$settings = MSRWA_Settings::get();
		$plan = self::image_plan( 'featured_image', $job );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$reservation = MSRWA_DB::reserve( $job, (float) $settings['featured_image_estimate_usd'], 'featured_image' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$canonical = isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		$prompt = $settings['prompt_image'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . self::visual_context( $artifacts ) . self::correction_context( $artifacts, 'featured_image', $settings );
		$size = self::native_size( $settings['featured_ratio'], '1024x1024' );
		$quality = self::quality( $settings );
		$format = self::format( $settings );
		$started = current_time( 'mysql', true );
		$result = 'openai' === $plan['provider'] ? MSRWA_OpenAI::images_generate( $prompt, $plan['model'], $size, $quality, $format ) : MSRWA_Providers::image( $plan['provider'], $plan['model'], $prompt, $size );
		self::record_call( $job, 'featured_image', $plan['provider'], $plan['model'], $prompt, $result, true, $reservation, $started );
		if ( is_wp_error( $result ) ) { return $result; }
		$attachment_id = self::store_base64( $result['base64'], $job->title, 'Image principale générée', $result['format'] );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		$attachment_id = self::crop_ratio( $attachment_id, $settings['featured_ratio'], $format );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		return array( 'attachment_id' => $attachment_id, 'model' => $plan['model'], 'ratio' => $settings['featured_ratio'], 'format' => $result['format'] );
	}

	public static function facebook( $job, $artifacts ) {
		$settings = MSRWA_Settings::get();
		$plan = self::image_plan( 'facebook_image', $job );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$reservation = MSRWA_DB::reserve( $job, (float) $settings['facebook_image_estimate_usd'], 'facebook_image' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$featured_id = isset( $artifacts['featured_image']['attachment_id'] ) ? absint( $artifacts['featured_image']['attachment_id'] ) : 0;
		$featured_file = $featured_id ? get_attached_file( $featured_id ) : '';
		$settings = MSRWA_Settings::get();
		$canonical = isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		$prompt = $settings['prompt_facebook_image'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . self::visual_context( $artifacts ) . self::correction_context( $artifacts, 'facebook_image', $settings );
		$size = self::native_size( $settings['facebook_ratio'], '1024x1536' );
		$quality = self::quality( $settings );
		$format = self::format( $settings );
		$started = current_time( 'mysql', true );
		if ( $featured_file && file_exists( $featured_file ) ) {
			$result = 'openai' === $plan['provider'] ? MSRWA_OpenAI::images_edit( $featured_file, $prompt, $plan['model'], $size, $quality, $format ) : MSRWA_Providers::image_edit( $plan['provider'], $plan['model'], $featured_file, $prompt, $size );
		} else {
			$result = 'openai' === $plan['provider'] ? MSRWA_OpenAI::images_generate( $prompt, $plan['model'], $size, $quality, $format ) : MSRWA_Providers::image( $plan['provider'], $plan['model'], $prompt, $size );
		}
		self::record_call( $job, 'facebook_image', $plan['provider'], $plan['model'], $prompt, $result, true, $reservation, $started );
		if ( is_wp_error( $result ) ) { return $result; }
		$attachment_id = self::store_base64( $result['base64'], $job->title . ' Facebook', 'Variante Facebook générée', $result['format'] );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		$attachment_id = self::crop_ratio( $attachment_id, $settings['facebook_ratio'], $format, $settings['facebook_image_fit'], $settings['image_padding_color'] );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		return array( 'attachment_id' => $attachment_id, 'model' => $plan['model'], 'ratio' => $settings['facebook_ratio'], 'format' => $result['format'], 'reference_attachment_id' => $featured_id );
	}

	public static function validate( $image ) {
		$id = isset( $image['attachment_id'] ) ? absint( $image['attachment_id'] ) : 0;
		if ( ! $id || ! get_attached_file( $id ) || ! wp_attachment_is_image( $id ) ) { return new WP_Error( 'image_invalid', 'Le média image généré est introuvable ou invalide.' ); }
		$meta = wp_get_attachment_metadata( $id );
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) { return new WP_Error( 'image_dimensions_missing', 'Les dimensions de l’image générée sont absentes.' ); }
		return true;
	}

	public static function review( $job, $image, $canonical ) {
		$settings = MSRWA_Settings::get();
		$plan = self::vision_plan( $job );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$file = get_attached_file( absint( $image['attachment_id'] ?? 0 ) );
		$reservation = MSRWA_DB::reserve( $job, (float) $settings['vision_reserve_usd'], 'image_review' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		$prompt = $settings['prompt_image_review'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$purpose = ( $image['purpose'] ?? '' ) === 'facebook_image' ? 'facebook_image' : 'featured_image';
		$prompt .= '\nUSAGE : ' . $purpose . '\nCONSIGNES VISUELLES : ' . $settings[ 'facebook_image' === $purpose ? 'prompt_facebook_image' : 'prompt_image' ];
		$started = current_time( 'mysql', true );
		$result = MSRWA_Providers::vision_text( $plan['provider'], $plan['model'], $prompt, $file, absint( $settings['image_review_max_output_tokens'] ) );
		self::record_call( $job, 'image_review', $plan['provider'], $plan['model'], $prompt, $result, false, $reservation, $started );
		if ( is_wp_error( $result ) ) { return $result; }
		$json = json_decode( trim( (string) $result['text'] ), true );
		if ( ! is_array( $json ) ) { $start = strpos( $result['text'], '{' ); $end = strrpos( $result['text'], '}' ); if ( false !== $start && false !== $end ) { $json = json_decode( substr( $result['text'], $start, $end - $start + 1 ), true ); } }
		if ( ! is_array( $json ) ) { return new WP_Error( 'image_review_invalid', 'La relecture vision n’a pas retourné un JSON valide.' ); }
		return MSRWA_Quality::normalize_review( $json, true );
	}

	private static function image_plan( $stage, $job = null ) {
		$plan = self::selected_plan( $job, 'image_generation', 'image' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		MSRWA_DB::event( 'image_model_selected', $job ? $job->batch_id : 0, $job ? $job->id : 0, array( 'stage' => $stage, 'provider' => $plan['provider'], 'model' => $plan['model'] ) );
		return $plan;
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

	private static function native_size( $ratio, $fallback ) {
		$sizes = array( '1:1' => '1024x1024', '3:2' => '1536x1024', '2:3' => '1024x1536', '4:5' => '1024x1536' );
		return isset( $sizes[ $ratio ] ) ? $sizes[ $ratio ] : $fallback;
	}

	private static function quality( $settings ) {
		$value = isset( $settings['image_quality'] ) ? $settings['image_quality'] : 'low';
		return in_array( $value, array( 'low', 'medium', 'high', 'xhigh', 'max', 'auto' ), true ) ? $value : 'low';
	}

	private static function format( $settings ) {
		$value = isset( $settings['image_format'] ) ? $settings['image_format'] : 'webp';
		return in_array( $value, array( 'webp', 'jpeg', 'png' ), true ) ? $value : 'webp';
	}

	private static function vision_plan( $job ) {
		return self::selected_plan( $job, 'vision', 'vision' );
	}

	private static function selected_plan( $job, $capability, $stage ) {
		$selected = $job ? json_decode( (string) $job->selected_models_json, true ) : array();
		if ( is_array( $selected ) && ! empty( $selected[ $stage ] ) && is_array( $selected[ $stage ] ) ) {
			$provider = sanitize_key( isset( $selected[ $stage ]['provider'] ) ? $selected[ $stage ]['provider'] : '' );
			$model = sanitize_text_field( isset( $selected[ $stage ]['model'] ) ? $selected[ $stage ]['model'] : '' );
			$eligible = MSRWA_Catalog::eligible( $capability );
			if ( $provider && $model && isset( $eligible[ $provider . ':' . $model ] ) && MSRWA_Router::connected( $provider ) ) { return $selected[ $stage ]; }
			return new WP_Error( 'selected_model_unavailable', 'Le modèle enregistré pour cette étape n’est plus disponible ou compatible.', array( 'status' => 409 ) );
		}
		return MSRWA_Router::plan( $capability, $stage );
	}

	private static function record_call( $job, $operation, $provider, $model, $prompt, $result, $uncertain = false, $reservation = null, $started = null ) {
		$settings = MSRWA_Settings::get();
		$catalog = MSRWA_Catalog::models();
		$input_tokens = is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0;
		$output_tokens = is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0;
		$is_image = in_array( $operation, array( 'featured_image', 'facebook_image' ), true );
		$fallback_cost = 'facebook_image' === $operation ? (float) $settings['facebook_image_estimate_usd'] : ( 'featured_image' === $operation ? (float) $settings['featured_image_estimate_usd'] : (float) $settings['vision_reserve_usd'] );
		$has_usage = $input_tokens || $output_tokens;
		if ( $has_usage && isset( $catalog[ $provider ][ $model ] ) ) {
			$model_cost = $catalog[ $provider ][ $model ];
			$text_input = isset( $result['usage']['input_tokens_details']['text_tokens'] ) ? absint( $result['usage']['input_tokens_details']['text_tokens'] ) : $input_tokens;
			$image_input = isset( $result['usage']['input_tokens_details']['image_tokens'] ) ? absint( $result['usage']['input_tokens_details']['image_tokens'] ) : 0;
			$cost = ( $text_input * (float) $model_cost['input'] + $image_input * (float) ( $model_cost['image_input'] ?? $model_cost['input'] ) + $output_tokens * (float) $model_cost['output'] ) / 1000000;
			$uncertain = false;
		} else {
			$cost = $fallback_cost;
			$uncertain = $is_image || $uncertain;
		}
		MSRWA_DB::call( array(
			'batch_id' => absint( $job->batch_id ),
			'job_id' => absint( $job->id ),
			'provider' => $provider,
			'model' => $model,
			'operation' => $operation,
			'status' => is_wp_error( $result ) ? 'failed' : 'completed',
			'request_id' => '',
			'input_tokens' => $input_tokens,
			'output_tokens' => $output_tokens,
			'cost_estimate' => $cost,
			'uncertain' => $uncertain ? 1 : 0,
			'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '',
			'request_json' => array( 'prompt' => $prompt, 'operation' => $operation ),
			'response_json' => MSRWA_DB::diagnostic_payload( $result ),
			'payload_hash' => hash( 'sha256', (string) $prompt ),
			'started_at' => $started ?: current_time( 'mysql', true ),
			'finished_at' => current_time( 'mysql', true ),
		) );
		if ( is_wp_error( $result ) ) { MSRWA_DB::release( $reservation ); } else { MSRWA_DB::settle( $reservation, $cost ); }
	}

	private static function store_base64( $base64, $title, $caption, $format = 'webp' ) {
		$binary = base64_decode( (string) $base64, true );
		if ( false === $binary || strlen( $binary ) < 128 || strlen( $binary ) > 25 * 1024 * 1024 ) { return new WP_Error( 'image_payload_invalid', 'Le fichier image généré est invalide ou dépasse la limite.' ); }
		$format = in_array( $format, array( 'png', 'jpeg', 'webp' ), true ) ? $format : 'webp';
		$mime = 'png' === $format ? 'image/png' : ( 'jpeg' === $format ? 'image/jpeg' : 'image/webp' );
		$tmp = wp_tempnam( sanitize_file_name( $title ) . '.' . $format );
		if ( ! $tmp || false === file_put_contents( $tmp, $binary ) ) { return new WP_Error( 'image_temp_failed', 'Impossible de stocker temporairement l’image générée.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = array( 'name' => sanitize_file_name( $title ) . '.' . $format, 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $binary ), 'type' => $mime );
		$id = media_handle_sideload( $file, 0, $caption, array( 'post_status' => 'inherit' ) );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
		wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $title ), 'post_excerpt' => sanitize_text_field( $caption ) ) );
		return (int) $id;
	}

	private static function crop_ratio( $attachment_id, $ratio, $format = 'webp', $fit = 'cover', $padding_color = '#ffffff' ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) { return new WP_Error( 'image_file_missing', 'Fichier image introuvable pour le recadrage.' ); }
		$parts = array_map( 'absint', explode( ':', (string) $ratio ) );
		if ( count( $parts ) !== 2 || ! $parts[0] || ! $parts[1] ) { return $attachment_id; }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) { return $editor; }
		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) { return new WP_Error( 'image_size_missing', 'Dimensions image indisponibles.' ); }
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
		if ( ! function_exists( 'imagecreatefromstring' ) ) { return new WP_Error( 'image_padding_unavailable', 'GD est nécessaire pour conserver le collage entier au ratio demandé.' ); }
		$source = imagecreatefromstring( file_get_contents( $file ) );
		if ( ! $source ) { return new WP_Error( 'image_decode_failed', 'Impossible de lire le collage généré.' ); }
		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) { imagedestroy( $source ); return new WP_Error( 'image_canvas_failed', 'Impossible de préparer le format image.' ); }
		$hex = ltrim( $color, '#' );
		$background = imagecolorallocate( $canvas, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
		imagefill( $canvas, 0, 0, $background );
		imagecopy( $canvas, $source, (int) floor( ( $width - imagesx( $source ) ) / 2 ), (int) floor( ( $height - imagesy( $source ) ) / 2 ), 0, 0, imagesx( $source ), imagesy( $source ) );
		$writer = 'jpeg' === $format ? 'imagejpeg' : ( 'png' === $format ? 'imagepng' : 'imagewebp' );
		$ok = function_exists( $writer ) && $writer( $canvas, $target );
		imagedestroy( $source );
		imagedestroy( $canvas );
		return $ok ? array( 'path' => $target ) : new WP_Error( 'image_padding_save_failed', 'Impossible de sauvegarder le collage au format demandé.' );
	}
}
