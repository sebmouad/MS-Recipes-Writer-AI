<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public administration vocabulary. AI verdicts are textual, never synthetic percentages. */
final class MSRWA_Presentation {
	public static function state( $status ) {
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) { return 'canceled'; }
		if ( in_array( $status, array( 'failed', 'uncertain' ), true ) ) { return 'error'; }
		if ( in_array( $status, array( 'completed', 'needs_review' ), true ) ) { return 'completed'; }
		return 'encours';
	}

	public static function verdict( $value, $fallback = 'unknown' ) {
		$value = strtolower( preg_replace( '/[^a-z_]/', '', (string) $value ) );
		$labels = array( 'good' => 'Bon', 'needs_review' => 'À vérifier', 'bad' => 'Mauvais', 'unknown' => 'Non évalué', 'not_generated' => 'Non générée' );
		if ( ! isset( $labels[ $value ] ) ) { $value = $fallback; }
		return array( 'code' => $value, 'label' => $labels[ $value ] ?? $labels['unknown'] );
	}

	public static function render_verdict( $value ) {
		$verdict = self::verdict( $value );
		echo '<span class="msrwa-quality-badge msrwa-quality-' . esc_attr( $verdict['code'] ) . '">' . esc_html( $verdict['label'] ) . '</span>';
	}

	/** Compatibility summary used by job and batch pages. */
	public static function quality( $job ) {
		$job = (array) $job;
		$value = $job['article_quality'] ?? '';
		if ( ! $value && ! empty( $job['artifacts_json'] ) ) {
			$artifacts = json_decode( (string) $job['artifacts_json'], true );
			$value = is_array( $artifacts ) ? ( $artifacts['review']['verdict'] ?? '' ) : '';
		}
		$verdict = self::verdict( $value );
		return array( 'code' => $verdict['code'], 'label' => $verdict['label'], 'score' => null, 'note' => '', 'evaluated' => 'unknown' === $verdict['code'] ? 0 : 1, 'total' => 1 );
	}

	public static function render_quality( $quality ) { self::render_verdict( is_array( $quality ) ? ( $quality['code'] ?? '' ) : $quality ); }

	public static function batch( $jobs ) {
		$states = array(); $worst = 'good'; $rank = array( 'good' => 0, 'unknown' => 1, 'needs_review' => 2, 'bad' => 3 );
		foreach ( $jobs as $job ) {
			$states[] = self::state( $job['status'] ?? '' );
			$q = self::quality( $job );
			if ( ( $rank[ $q['code'] ] ?? 1 ) > ( $rank[ $worst ] ?? 0 ) ) { $worst = $q['code']; }
		}
		$state = ! $states || in_array( 'encours', $states, true ) ? 'encours' : ( in_array( 'error', $states, true ) ? 'error' : 'completed' );
		if ( $states && array( 'canceled' ) === array_values( array_unique( $states ) ) ) { $state = 'canceled'; }
		if ( ! $jobs ) { $worst = 'unknown'; }
		return array( 'state' => $state, 'quality' => self::quality( array( 'article_quality' => $worst ) ), 'finished' => count( array_filter( $states, static function ( $s ) { return 'encours' !== $s; } ) ) );
	}
}
