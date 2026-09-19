<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Catalog {
	public static function models() {
		return array(
			'openai' => array(
				'gpt-5.6-luna' => array( 'label' => 'GPT-5.6 Luna', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'input' => 0.20, 'output' => 1.20, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-luna' ),
				'gpt-5.6-terra' => array( 'label' => 'GPT-5.6 Terra', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'input' => 2.00, 'output' => 12.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-terra' ),
				'gpt-5.6-sol' => array( 'label' => 'GPT-5.6 Sol', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'input' => 4.00, 'output' => 20.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-5.6-sol' ),
				'gpt-image-2.5-flare' => array( 'label' => 'GPT Image 2.5 Flare', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-flare' ),
				'gpt-image-2.5-sunburst' => array( 'label' => 'GPT Image 2.5 Sunburst', 'stable' => true, 'text' => false, 'vision' => true, 'web_search' => false, 'image_generation' => true, 'input' => 5.00, 'output' => 30.00, 'source' => 'https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst' ),
			),
			'gemini' => array(
				'gemini-3.1-flash-image' => array( 'label' => 'Gemini 3.1 Flash Image', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => true, 'input' => 0.50, 'output' => 3.00, 'source' => 'https://ai.google.dev/gemini-api/docs/image-generation' ),
				'gemini-3.5-flash' => array( 'label' => 'Gemini 3.5 Flash', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.50, 'output' => 9.00, 'source' => 'https://ai.google.dev/gemini-api/docs/pricing' ),
			),
			'claude' => array(
				'claude-sonnet-5' => array( 'label' => 'Claude Sonnet 5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 2.00, 'output' => 10.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
				'claude-haiku-4-5-20251001' => array( 'label' => 'Claude Haiku 4.5', 'stable' => true, 'text' => true, 'vision' => true, 'web_search' => true, 'image_generation' => false, 'input' => 1.00, 'output' => 5.00, 'source' => 'https://platform.claude.com/docs/en/models/overview' ),
			),
		);
	}

	public static function eligible( $capability = 'text' ) {
		$out = array();
		foreach ( self::models() as $provider => $models ) {
			foreach ( $models as $id => $model ) {
				if ( ! empty( $model['stable'] ) && ! empty( $model[ $capability ] ) ) { $out[ $provider . ':' . $id ] = $model + array( 'provider' => $provider, 'id' => $id ); }
			}
		}
		return $out;
	}
}
