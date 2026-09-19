<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Images {
	public static function featured( $job, $artifacts ) {
		$settings = MSRWA_Settings::get();
		$plan = self::image_plan( 'featured_image' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$budget = MSRWA_DB::budget_allows( $job, (float) $settings['image_reserve_usd'] );
		if ( is_wp_error( $budget ) ) { return $budget; }
		if ( 'openai' !== $plan['provider'] ) { return new WP_Error( 'image_provider_pending', 'Cet adaptateur image fournisseur n’est pas encore disponible.', array( 'status' => 409 ) ); }
		$canonical = isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		$prompt = $settings['prompt_image'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = MSRWA_OpenAI::images_generate( $prompt, $plan['model'], '1024x1024', 'low', 'webp' );
		self::record_call( $job, 'featured_image', $plan['model'], $prompt, $result );
		if ( is_wp_error( $result ) ) { return $result; }
		$attachment_id = self::store_base64( $result['base64'], $job->title, 'Image principale générée' );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		return array( 'attachment_id' => $attachment_id, 'model' => $plan['model'], 'ratio' => $settings['featured_ratio'], 'format' => $result['format'] );
	}

	public static function facebook( $job, $artifacts ) {
		$settings = MSRWA_Settings::get();
		$plan = self::image_plan( 'facebook_image' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		$budget = MSRWA_DB::budget_allows( $job, (float) $settings['image_reserve_usd'] );
		if ( is_wp_error( $budget ) ) { return $budget; }
		$featured_id = isset( $artifacts['featured_image']['attachment_id'] ) ? absint( $artifacts['featured_image']['attachment_id'] ) : 0;
		$featured_file = $featured_id ? get_attached_file( $featured_id ) : '';
		if ( 'openai' !== $plan['provider'] ) { return new WP_Error( 'image_provider_pending', 'Cet adaptateur image fournisseur n’est pas encore disponible.', array( 'status' => 409 ) ); }
		$settings = MSRWA_Settings::get();
		$canonical = isset( $artifacts['canonical'] ) ? $artifacts['canonical'] : array();
		$prompt = $settings['prompt_facebook_image'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $featured_file && file_exists( $featured_file ) ) {
			$result = MSRWA_OpenAI::images_edit( $featured_file, $prompt, $plan['model'], '1024x1536', 'low', 'webp' );
		} else {
			$result = MSRWA_OpenAI::images_generate( $prompt, $plan['model'], '1024x1536', 'low', 'webp' );
		}
		self::record_call( $job, 'facebook_image', $plan['model'], $prompt, $result );
		if ( is_wp_error( $result ) ) { return $result; }
		$attachment_id = self::store_base64( $result['base64'], $job->title . ' Facebook', 'Variante Facebook générée' );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		$attachment_id = self::crop_ratio( $attachment_id, $settings['facebook_ratio'] );
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
		$plan = MSRWA_Router::plan( 'vision' );
		if ( is_wp_error( $plan ) || 'openai' !== $plan['provider'] ) { return new WP_Error( 'image_review_pending', 'Aucun adaptateur de vision compatible n’est connecté.' ); }
		$file = get_attached_file( absint( $image['attachment_id'] ?? 0 ) );
		$budget = MSRWA_DB::budget_allows( $job, 0.05 );
		if ( is_wp_error( $budget ) ) { return $budget; }
		$prompt = $settings['prompt_image_review'] . '\nRECETTE VALIDÉE : ' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$result = MSRWA_OpenAI::vision_text( $prompt, $file, $plan['model'], 1000 );
		self::record_call( $job, 'image_review', $plan['model'], $prompt, $result );
		if ( is_wp_error( $result ) ) { return $result; }
		$json = json_decode( trim( (string) $result['text'] ), true );
		if ( ! is_array( $json ) ) { $start = strpos( $result['text'], '{' ); $end = strrpos( $result['text'], '}' ); if ( false !== $start && false !== $end ) { $json = json_decode( substr( $result['text'], $start, $end - $start + 1 ), true ); } }
		return is_array( $json ) ? $json : new WP_Error( 'image_review_invalid', 'La relecture vision n’a pas retourné un JSON valide.' );
	}

	private static function image_plan( $stage ) {
		$plan = MSRWA_Router::plan( 'image_generation' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		MSRWA_DB::event( 'image_model_selected', 0, 0, array( 'stage' => $stage, 'provider' => $plan['provider'], 'model' => $plan['model'] ) );
		return $plan;
	}

	private static function record_call( $job, $operation, $model, $prompt, $result ) {
		$settings = MSRWA_Settings::get();
		MSRWA_DB::call( array(
			'batch_id' => absint( $job->batch_id ),
			'job_id' => absint( $job->id ),
			'provider' => 'openai',
			'model' => $model,
			'operation' => $operation,
			'status' => is_wp_error( $result ) ? 'failed' : 'completed',
			'request_id' => '',
			'input_tokens' => is_array( $result ) && ! empty( $result['usage']['input_tokens'] ) ? absint( $result['usage']['input_tokens'] ) : 0,
			'output_tokens' => is_array( $result ) && ! empty( $result['usage']['output_tokens'] ) ? absint( $result['usage']['output_tokens'] ) : 0,
			'cost_estimate' => (float) $settings['image_reserve_usd'],
			'uncertain' => 1,
			'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '',
			'payload_hash' => hash( 'sha256', (string) $prompt ),
			'started_at' => current_time( 'mysql', true ),
			'finished_at' => current_time( 'mysql', true ),
		) );
	}

	private static function store_base64( $base64, $title, $caption ) {
		$binary = base64_decode( (string) $base64, true );
		if ( false === $binary || strlen( $binary ) < 128 || strlen( $binary ) > 25 * 1024 * 1024 ) { return new WP_Error( 'image_payload_invalid', 'Le fichier image généré est invalide ou dépasse la limite.' ); }
		$tmp = wp_tempnam( sanitize_file_name( $title ) . '.webp' );
		if ( ! $tmp || false === file_put_contents( $tmp, $binary ) ) { return new WP_Error( 'image_temp_failed', 'Impossible de stocker temporairement l’image générée.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = array( 'name' => sanitize_file_name( $title ) . '.webp', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $binary ), 'type' => 'image/webp' );
		$id = media_handle_sideload( $file, 0, $caption, array( 'post_status' => 'inherit' ) );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
		wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $title ), 'post_excerpt' => sanitize_text_field( $caption ) ) );
		return (int) $id;
	}

	private static function crop_ratio( $attachment_id, $ratio ) {
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
		if ( $source_ratio > $target_ratio ) { $width = (int) round( $height * $target_ratio ); } else { $height = (int) round( $width / $target_ratio ); }
		$editor->resize( $width, $height, true );
		$saved = $editor->save( $file, 'image/webp' );
		if ( is_wp_error( $saved ) ) { return $saved; }
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
		return $attachment_id;
	}
}
