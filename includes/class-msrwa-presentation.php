<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public admin vocabulary, independent from worker recovery states. */
final class MSRWA_Presentation {
	public static function state( $status ) {
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) { return 'canceled'; }
		if ( in_array( $status, array( 'failed', 'uncertain' ), true ) ) { return 'error'; }
		if ( in_array( $status, array( 'completed', 'needs_review' ), true ) ) { return 'completed'; }
		return 'encours';
	}

	public static function quality( $job ) {
		$job = (array) $job;
		$status = $job['status'] ?? '';
		$artifacts = json_decode( (string) ( $job['artifacts_json'] ?? '' ), true );
		$artifacts = is_array( $artifacts ) ? $artifacts : array();
		$report = MSRWA_Publisher::editorial_report( $artifacts );
		$score = isset( $report['score'] ) ? max( 0, min( 100, (int) $report['score'] ) ) : null;
		$code = 'pending'; $label = 'Non évalué';
		if ( 'uncertain' === $status ) { $code = 'uncertain'; $label = 'uncertain'; }
		elseif ( 'failed' === $status ) { $code = 'incomplete'; $label = 'Incomplet'; }
		elseif ( in_array( $status, array( 'completed', 'needs_review' ), true ) ) {
			$code = 'completed' === $status && 'checks_passed' === $report['status'] ? 'good' : 'review';
			$label = 'good' === $code ? 'Bon' : 'À vérifier';
		} elseif ( null !== $score && 'canceled' !== self::state( $status ) ) { $label = 'Évaluation en cours'; }
		$notes = array( 'paused' => 'En pause — reprise manuelle', 'paused_budget' => 'Budget insuffisant — action requise', 'awaiting_input' => 'Confirmation requise', 'awaiting_admin' => 'Décision administrateur requise', 'retry_wait' => 'Nouvelle tentative planifiée', 'uncertain' => 'Résultat fournisseur à vérifier avant relance', 'failed' => 'Traitement arrêté — consulter les erreurs' );
		return array( 'code' => $code, 'label' => $label, 'score' => $score, 'note' => $notes[ $status ] ?? '', 'evaluated' => null === $score ? 0 : 1, 'total' => 1 );
	}

	public static function batch( $jobs ) {
		$states = array(); $scores = array(); $quality = array( 'code' => 'pending', 'label' => 'Non évalué', 'score' => null, 'note' => '', 'evaluated' => 0, 'total' => count( $jobs ) );
		$rank = array( 'good' => 0, 'pending' => 1, 'review' => 2, 'incomplete' => 3, 'uncertain' => 4 );
		$worst = -1; $notes = array();
		foreach ( $jobs as $job ) {
			$state = self::state( $job['status'] ); $states[] = $state;
			if ( 'canceled' === $state ) { continue; }
			$q = self::quality( $job );
			if ( null !== $q['score'] ) { $scores[] = $q['score']; }
			if ( $rank[ $q['code'] ] > $worst ) { $quality['code'] = $q['code']; $quality['label'] = $q['label']; $worst = $rank[ $q['code'] ]; }
			if ( $q['note'] ) { $notes[] = $q['note']; }
		}
		$state = ! $states || in_array( 'encours', $states, true ) ? 'encours' : ( count( array_unique( $states ) ) === 1 && 'canceled' === $states[0] ? 'canceled' : 'completed' );
		if ( 'encours' !== $state && in_array( 'error', $states, true ) ) { $state = 'error'; }
		$quality['score'] = $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : null;
		$quality['evaluated'] = count( $scores );
		$quality['note'] = implode( ' · ', array_unique( $notes ) );
		return array( 'state' => $state, 'quality' => $quality, 'finished' => count( array_filter( $states, static function ( $s ) { return 'encours' !== $s; } ) ) );
	}

	public static function render_quality( $quality ) {
		echo '<span class="msrwa-quality-badge msrwa-quality-' . esc_attr( $quality['code'] ) . '">' . esc_html( $quality['label'] ) . '</span>';
		echo '<span class="msrwa-quality-score" title="Score des contrôles de structure, et non garantie éditoriale">' . ( null === $quality['score'] ? '—' : esc_html( $quality['score'] ) . ' %' ) . '</span>';
		if ( $quality['total'] > 1 ) { echo '<small class="msrwa-quality-note">Moyenne · ' . esc_html( $quality['evaluated'] . '/' . $quality['total'] ) . ' évalués</small>'; }
		if ( $quality['note'] ) { echo '<small class="msrwa-quality-note">' . esc_html( $quality['note'] ) . '</small>'; }
	}
}
