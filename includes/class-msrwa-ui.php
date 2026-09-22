<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The pieces every screen is built from.
 *
 * Kept in one place so a state means the same thing on every screen and is
 * worded the same way. The rule that shapes most of this: a finished run is
 * work waiting for an editor, never work that has been approved. The engine's
 * judge has an opinion about its own output; it has no opinion about whether
 * this site wants to publish it, and no screen here is allowed to suggest
 * otherwise.
 */
final class MSRWA_UI {

	/** The page head: what this is, one line about it, the figures, the actions. */
	public static function head( $title, $lead = '', array $figures = array(), $actions = '' ) {
		echo '<div class="ms-head"><div><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $lead ) { echo '<p>' . esc_html( $lead ) . '</p>'; }
		echo '</div>';
		if ( $figures ) {
			echo '<div class="ms-head-figures">';
			foreach ( $figures as $label => $value ) {
				echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
			}
			echo '</div>';
		}
		if ( '' !== $actions ) { echo '<div class="ms-head-actions">' . wp_kses_post( $actions ) . '</div>'; }
		echo '</div>';
	}

	/**
	 * A table too wide for the screen, made reachable rather than crushed.
	 *
	 * A region that scrolls with the mouse and not with the keyboard is a table
	 * some readers simply cannot see the end of, so it is focusable and named.
	 */
	public static function scroll( $label ) {
		return '<div class="ms-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $label ) . '">';
	}

	public static function figures( array $figures ) {
		echo '<dl class="ms-figures">';
		foreach ( $figures as $entry ) {
			echo '<div class="ms-figure"><dt>' . esc_html( $entry['label'] ) . '</dt><dd>' . esc_html( $entry['value'] );
			if ( ! empty( $entry['note'] ) ) { echo '<small>' . esc_html( $entry['note'] ) . '</small>'; }
			echo '</dd></div>';
		}
		echo '</dl>';
	}

	/**
	 * What a run's state is called, and how it reads.
	 *
	 * `done` is deliberately not "terminé" on its own: a finished run is either
	 * waiting for an editor to read it or waiting for someone to fix what the
	 * judge objected to. Neither is a validation, and saying so plainly is the
	 * difference between a tool an editor trusts and one that flatters itself.
	 */
	public static function state_of( array $run ) {
		$status = (string) ( $run['status'] ?? '' );
		if ( 'queued' === $status ) { return array( 'tone' => '', 'label' => __( 'en attente', 'ms-recipes-writer-ai' ) ); }
		if ( 'running' === $status ) { return array( 'tone' => 'live', 'label' => __( 'en cours', 'ms-recipes-writer-ai' ) ); }
		if ( 'cancelled' === $status ) { return array( 'tone' => '', 'label' => __( 'arrêté', 'ms-recipes-writer-ai' ) ); }
		if ( 'failed' === $status ) { return array( 'tone' => 'stop', 'label' => __( 'échec', 'ms-recipes-writer-ai' ) ); }

		$approved = $run['approved'] ?? null;
		if ( null !== $approved && ! $approved ) { return array( 'tone' => 'warn', 'label' => __( 'réserves du juge', 'ms-recipes-writer-ai' ) ); }
		return array( 'tone' => 'good', 'label' => __( 'à relire', 'ms-recipes-writer-ai' ) );
	}

	public static function state( array $run ) {
		$state = self::state_of( $run );
		return '<span class="ms-state' . ( $state['tone'] ? ' ms-state-' . $state['tone'] : '' ) . '">' . esc_html( $state['label'] ) . '</span>';
	}

	/** How far through its steps a run is. */
	public static function progress( $done, $total ) {
		$total = max( 1, (int) $total );
		$share = min( 100, round( 100 * (int) $done / $total ) );
		$complete = (int) $done >= (int) $total;
		return '<span class="ms-progress' . ( $complete ? ' ms-progress-done' : '' ) . '" role="img" aria-label="'
			. esc_attr( sprintf( /* translators: 1: steps finished, 2: steps in total. */ __( '%1$d étapes sur %2$d', 'ms-recipes-writer-ai' ), (int) $done, $total ) )
			. '"><i style="inline-size:' . (int) $share . '%"></i></span>';
	}

	/**
	 * One run on the rail.
	 *
	 * The spine at the leading edge carries the state before anything is read,
	 * which is the whole reason the rail exists: an editor glancing at the page
	 * should see that something went wrong without reading a word.
	 */
	public static function ticket( array $run, $url, $selectable = false ) {
		$state = self::state_of( $run );
		$spine = in_array( $state['tone'], array( 'live' ), true ) ? 'live' : ( 'stop' === $state['tone'] ? 'stop' : ( 'good' === $state['tone'] ? 'done' : '' ) );
		?>
		<article class="ms-ticket<?php echo $spine ? ' ms-ticket-' . esc_attr( $spine ) : ''; ?>" data-run="<?php echo esc_attr( $run['id'] ); ?>">
			<div class="ms-ticket-title">
				<?php if ( $selectable ) : ?>
					<input type="checkbox" class="ms-pick-run" value="<?php echo esc_attr( $run['id'] ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s is a recipe title. */ __( 'Sélectionner %s', 'ms-recipes-writer-ai' ), $run['label'] ) ); ?>">
				<?php endif; ?>
				<span class="ms-ticket-no">#<?php echo esc_html( $run['id'] ); ?></span>
				<a class="ms-ticket-name" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $run['label'] ); ?></a>
			</div>
			<div class="ms-ticket-meta">
				<span data-field="steps"><?php echo esc_html( $run['steps_done'] . '/' . $run['steps_total'] ); ?></span>
				<?php if ( MSRWA_Rights::may_see_money() && isset( $run['cost_usd'] ) ) : ?>
					<span data-field="cost"><?php echo esc_html( MSRWA_I18N::money( $run['cost_usd'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( isset( $run['seconds'] ) ) : ?>
					<span data-field="seconds"><?php echo esc_html( MSRWA_I18N::seconds( $run['seconds'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $run['step'] ?? '' ) && 'running' === $run['status'] ) : ?>
					<span class="ms-key" data-field="step"><?php echo esc_html( $run['step'] ); ?></span>
				<?php endif; ?>
				<?php if ( 'queued' === $run['status'] && (int) ( $run['priority'] ?? 0 ) > 0 ) : ?>
					<span class="ms-key"><?php esc_html_e( 'passe devant', 'ms-recipes-writer-ai' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="ms-ticket-side">
				<?php echo self::progress( $run['steps_done'], $run['steps_total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span data-field="state"><?php echo self::state( $run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php if ( (int) ( $run['draft_post_id'] ?? 0 ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( (string) get_edit_post_link( (int) $run['draft_post_id'] ) ); ?>"><?php esc_html_e( 'Brouillon', 'ms-recipes-writer-ai' ); ?></a>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/** An empty screen says what to do next, never just that there is nothing. */
	public static function nothing( $title, $text, $action = '' ) {
		echo '<div class="ms-empty"><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $action ) { echo wp_kses_post( $action ); }
		echo '</div>';
	}

	public static function note( $text, $tone = '' ) {
		echo '<div class="ms-note' . ( $tone ? ' ms-note-' . esc_attr( $tone ) : '' ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
	}

	/** The author's name, or a dash when the reader may not see whose it is. */
	public static function owner( $owner_id ) {
		if ( ! MSRWA_Rights::may_see_everything() ) { return ''; }
		$user = get_userdata( (int) $owner_id );
		return $user ? $user->display_name : '—';
	}
}
