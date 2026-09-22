<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One screen that answers "why is nothing happening".
 *
 * Every line is a thing that must be true for a recipe to reach a draft, the
 * measurement behind it, and — when it is not true — what to do. Nothing here
 * calls a provider or spends anything, so it is safe to open while a lot runs.
 */
final class MSRWA_Screen_Diagnostics {

	public static function render() {
		if ( ! MSRWA_Rights::may_manage() ) { wp_die( esc_html__( 'Vous n’avez pas accès à cet écran.', 'ms-recipes-writer-ai' ) ); }

		$checks = MSRWA_Diagnostics::checks();
		$worst = MSRWA_Diagnostics::worst( $checks );
		$blocking = 0;
		foreach ( $checks as $check ) {
			if ( 'good' !== $check['tone'] ) { $blocking++; }
		}

		echo '<div class="wrap msrwa">';
		MSRWA_UI::head(
			__( 'Diagnostic', 'ms-recipes-writer-ai' ),
			__( 'Ce qui doit être vrai pour qu’une recette aboutisse, vérifié maintenant. Aucun appel facturé.', 'ms-recipes-writer-ai' ),
			array(
				__( 'contrôles', 'ms-recipes-writer-ai' ) => number_format_i18n( count( $checks ) ),
				__( 'à regarder', 'ms-recipes-writer-ai' ) => number_format_i18n( $blocking ),
			)
		);

		if ( 'stop' === $worst ) {
			MSRWA_UI::note( esc_html__( 'Quelque chose empêche une recette d’aboutir. Les lignes marquées en rouge disent quoi.', 'ms-recipes-writer-ai' ), 'stop' );
		} elseif ( 'warn' === $worst ) {
			MSRWA_UI::note( esc_html__( 'Rien n’est bloqué, mais quelque chose mérite un regard.', 'ms-recipes-writer-ai' ), 'warn' );
		}

		echo '<section class="ms-card ms-card-flush"><h2>' . esc_html__( 'Contrôles', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<div class="ms-checks">';
		foreach ( $checks as $check ) {
			echo '<div class="ms-check">';
			echo '<span class="ms-state ms-state-' . esc_attr( $check['tone'] ) . '">' . esc_html( $check['title'] ) . '</span>';
			echo '<div><p>' . esc_html( $check['detail'] ) . '</p>';
			if ( '' !== $check['remedy'] ) { echo '<p class="ms-muted">' . esc_html( $check['remedy'] ) . '</p>'; }
			echo '</div></div>';
		}
		echo '</div></section>';

		echo '<section class="ms-card"><h2>' . esc_html__( 'Rapport', 'ms-recipes-writer-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Les mêmes constats en un bloc, à coller dans une demande d’aide. Il ne porte ni clé, ni chemin, ni adresse du site.', 'ms-recipes-writer-ai' ) . '</p>';
		echo '<p><textarea id="ms-diagnostic-report" class="large-text ms-code" rows="' . esc_attr( count( $checks ) + 3 ) . '" readonly>'
			. esc_textarea( MSRWA_Diagnostics::report( $checks ) ) . '</textarea></p>';
		echo '<p class="ms-row"><button type="button" class="button" id="ms-copy-report">' . esc_html__( 'Copier le rapport', 'ms-recipes-writer-ai' ) . '</button>'
			. '<span class="ms-muted" id="ms-copy-status" aria-live="polite"></span></p>';
		echo '</section>';

		echo '</div>';
	}
}
