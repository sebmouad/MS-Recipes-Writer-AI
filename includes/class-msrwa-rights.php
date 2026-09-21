<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Who may do what, decided in one place.
 *
 * Three capabilities, and every screen, route and query asks this class rather
 * than testing capabilities inline. Scattered checks are how a list ends up
 * scoped on one screen and not on the next.
 *
 *   msrwa_create     submit work, and see one's own
 *   msrwa_view_all   see everyone's work
 *   msrwa_manage     settings, the engine, deletion, the whole ledger
 *
 * An administrator holds all three. `manage_options` is honoured everywhere as
 * a superset, so a site that never assigned the roles still works.
 */
final class MSRWA_Rights {

	const CREATE = 'msrwa_create';
	const VIEW_ALL = 'msrwa_view_all';
	const MANAGE = 'msrwa_manage';

	/** The roles this plugin grants on activation, and what each one gets. */
	public static function roles() {
		return array(
			'administrator' => array( self::CREATE, self::VIEW_ALL, self::MANAGE ),
			'editor' => array( self::CREATE, self::VIEW_ALL ),
			'author' => array( self::CREATE ),
		);
	}

	public static function grant() {
		foreach ( self::roles() as $name => $capabilities ) {
			$role = get_role( $name );
			if ( ! $role ) { continue; }
			foreach ( $capabilities as $capability ) { $role->add_cap( $capability ); }
		}
	}

	public static function revoke() {
		foreach ( array_keys( self::roles() ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) { continue; }
			foreach ( array( self::CREATE, self::VIEW_ALL, self::MANAGE ) as $capability ) { $role->remove_cap( $capability ); }
		}
	}

	public static function can( $capability, $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( $user_id ) { return user_can( $user_id, $capability ) || user_can( $user_id, 'manage_options' ); }
		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}

	public static function may_write() { return self::can( self::CREATE ); }
	public static function may_manage() { return self::can( self::MANAGE ); }
	/**
	 * Whether this reader sees other people's work.
	 *
	 * Deliberately narrower than it looks: holding `msrwa_view_all` is not
	 * enough on its own. Sites carry that capability from an earlier version of
	 * this plugin where it meant something else, and widening a writer's view
	 * because of a leftover grant is how one writer reads another's drafts.
	 * Only `msrwa_manage` — which administrators have — opens the whole list.
	 */
	public static function may_see_everything() { return self::can( self::MANAGE ); }

	/**
	 * Whether this reader is shown what things cost.
	 *
	 * Money is an operator's concern. A writer needs to know whether their
	 * article is ready, not what the run was billed, and a screen that cannot
	 * show a figure must not fetch it either.
	 */
	public static function may_see_money() { return self::can( self::MANAGE ); }

	/** Whether this user may open one row, whoever owns it. */
	public static function may_see( $owner_id ) {
		return (int) $owner_id === get_current_user_id() || self::may_see_everything();
	}

	/**
	 * The owner clause every list query carries.
	 *
	 * Returned as SQL rather than applied by the caller so that a list cannot be
	 * written without it: a query that forgets to call this has no WHERE at all
	 * and fails review, where one that forgets an inline check quietly shows
	 * another writer's work.
	 */
	public static function scope_sql( $column = 'owner_id' ) {
		global $wpdb;
		if ( self::may_see_everything() ) { return '1=1'; }
		return $wpdb->prepare( $column . ' = %d', get_current_user_id() );
	}

	/** What a screen is allowed to show about other people's work. */
	public static function may_read_diagnostics() { return self::may_manage(); }

	/** Deleting destroys evidence of what was spent, so it is an administrator's. */
	public static function may_delete() { return self::may_manage(); }
}
