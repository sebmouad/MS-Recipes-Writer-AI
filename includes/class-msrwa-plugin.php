<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_Plugin {

	/** How long one cleanup tick may keep starting work; each part is bounded on its own. */
	const CLEANUP_SECONDS = 60;

	public static function activate() {
		// The interval this plugin schedules on must be registered before the
		// event is scheduled, and activation does not run boot().
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );
		MSRWA_DB::install();
		self::caps();
		// Hourly is fine for tidying, but a lot asked for at nine o'clock should
		// not wait until ten, so the same event runs every five minutes.
		if ( ! wp_next_scheduled( 'msrwa_cleanup' ) ) { wp_schedule_event( time() + 300, 'msrwa_five_minutes', 'msrwa_cleanup' ); }
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'msrwa_run_step' );
		wp_clear_scheduled_hook( 'msrwa_cleanup' );
	}

	/**
	 * The same grants as activation, from the same list. Upgrades used to apply
	 * a second, different list — editors lost `msrwa_view_all` here and got it
	 * back on the next activation, and administrators never got `msrwa_manage`
	 * from an upgrade at all.
	 */
	private static function caps() {
		MSRWA_Rights::grant();
		// Left over from earlier versions: a view-all grant on writers, which
		// no longer widens anything, and a view-own capability nothing reads.
		foreach ( array( 'editor', 'author', 'writer' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) { $role->remove_cap( MSRWA_Rights::VIEW_ALL ); $role->remove_cap( 'msrwa_view_own' ); }
		}
	}

	/**
	 * The five-minute tick: stuck recipes back in the queue, the lots whose
	 * hour has come, then retention. Under one lock, so a tick that outlasts
	 * five minutes is not joined by the next; a part that would start past
	 * the budget waits for the next tick. What each part took is written
	 * down, and a tick past its budget goes to the PHP error log.
	 */
	public static function cleanup() {
		global $wpdb;
		$lock = 'msrwa_cleanup_' . md5( DB_NAME . '|' . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return false; }
		$started = microtime( true );
		$took = array();
		try {
			$parts = array( 'recover' => array( 'MSRWA_Run', 'recover_expired' ), 'due' => array( 'MSRWA_Schedule', 'due' ), 'prune' => array( __CLASS__, 'prune' ) );
			foreach ( $parts as $part => $callback ) {
				if ( microtime( true ) - $started > self::CLEANUP_SECONDS ) { break; }
				$at = microtime( true );
				call_user_func( $callback );
				$took[ $part ] = round( microtime( true ) - $at, 2 );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		$seconds = round( microtime( true ) - $started, 2 );
		update_option( 'msrwa_cleanup_last', array( 'at' => current_time( 'mysql', true ), 'seconds' => $seconds, 'parts' => $took ), false );
		if ( $seconds > self::CLEANUP_SECONDS + 30 ) { error_log( sprintf( '[ms-recipes-writer-ai] cleanup tick took %.1f s, over its %d s budget: %s', $seconds, self::CLEANUP_SECONDS, wp_json_encode( $took ) ) ); }
		return $took;
	}

	/**
	 * Minutes the five-minute tick has been due without running: 0 while
	 * WP-Cron runs it. WP-Cron runs only when the site is visited, and never
	 * with DISABLE_WP_CRON and no server cron. A tick run another way
	 * (wp msrwa tick from the server's cron) counts as a run.
	 */
	public static function cron_stalled_minutes() {
		$next = wp_next_scheduled( 'msrwa_cleanup' );
		$late = $next ? time() - (int) $next : 0;
		$last = get_option( 'msrwa_cleanup_last', array() );
		$at   = is_array( $last ) && ! empty( $last['at'] ) ? strtotime( $last['at'] . ' UTC' ) : 0;
		if ( $at ) { $late = min( $late, time() - $at ); }
		return $late > 10 * MINUTE_IN_SECONDS ? (int) floor( $late / MINUTE_IN_SECONDS ) : 0;
	}

	/** Queued recipes and scheduled lots wait while WP-Cron does not run: say so, with the commands. */
	public static function cron_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$minutes = self::cron_stalled_minutes();
		if ( ! $minutes ) { return; }
		$path = ' --path=' . untrailingslashit( ABSPATH );
		/* translators: %d is a number of minutes. */
		$text = sprintf( _n( 'WP-Cron ne s’est pas exécuté depuis %d minute : les recettes en file et les lots programmés attendent. WP-Cron ne s’exécute que lorsque le site est visité, et jamais avec DISABLE_WP_CRON. Lancez-le depuis le serveur chaque minute avec l’une de ces commandes :', 'WP-Cron ne s’est pas exécuté depuis %d minutes : les recettes en file et les lots programmés attendent. WP-Cron ne s’exécute que lorsque le site est visité, et jamais avec DISABLE_WP_CRON. Lancez-le depuis le serveur chaque minute avec l’une de ces commandes :', $minutes, 'ms-recipes-writer-ai' ), $minutes );
		echo '<div class="notice notice-warning"><p><strong>MS Recipes Writer AI</strong> &mdash; ' . esc_html( $text ) . '</p><p><code>' . esc_html( 'wp cron event run --due-now' . $path ) . '</code><br><code>' . esc_html( 'wp msrwa tick' . $path ) . '</code></p></div>';
	}

	/**
	 * Runs the five-minute tick, then the recipe steps that are due, one
	 * after another: for a server cron when WP-Cron cannot be relied on.
	 * Blocking. Steps that would start after four minutes wait for the next
	 * call; each recipe's own tick is bounded by MSRWA_Run::TICK_SECONDS.
	 *
	 * ## EXAMPLES
	 *
	 *     * * * * * wp msrwa tick --path=/var/www/site --quiet
	 */
	public static function cli_tick() {
		self::cleanup();
		$started = time();
		$ran = 0;
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > time() || time() - $started > 240 ) { break; }
			foreach ( (array) ( $hooks['msrwa_run_step'] ?? array() ) as $event ) {
				wp_unschedule_event( $timestamp, 'msrwa_run_step', $event['args'] );
				do_action_ref_array( 'msrwa_run_step', $event['args'] );
				$ran++;
			}
		}
		/* translators: %d is a number of recipe steps. */
		WP_CLI::success( sprintf( _n( 'Tick terminé : %d étape de recette lancée.', 'Tick terminé : %d étapes de recettes lancées.', $ran, 'ms-recipes-writer-ai' ), $ran ) );
	}

	/** Whatever the retention policy says has outlived its usefulness. */
	public static function prune() {
		MSRWA_Retention::sweep();
	}

	/**
	 * Five minutes, because a lot scheduled for nine that leaves at ten has
	 * missed its hour.
	 *
	 * The label is only translated once translations may be loaded. Activation
	 * schedules this event, and activation runs before `init`: asking for a
	 * translation there makes WordPress complain, on every first activation,
	 * about a domain loaded too early.
	 */
	public static function intervals( $schedules ) {
		$schedules['msrwa_five_minutes'] = array(
			'interval' => 300,
			'display' => did_action( 'init' ) ? __( 'Toutes les cinq minutes (MS Recipes AI)', 'ms-recipes-writer-ai' ) : 'Toutes les cinq minutes (MS Recipes AI)',
		);
		return $schedules;
	}

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( wp_get_schedule( 'msrwa_cleanup' ) !== 'msrwa_five_minutes' ) {
			wp_clear_scheduled_hook( 'msrwa_cleanup' );
			wp_schedule_event( time() + 300, 'msrwa_five_minutes', 'msrwa_cleanup' );
		}
		// Nothing loaded the catalogues. Translations appeared anyway because
		// WordPress 6.7 began loading a plugin's own /languages just in time,
		// so the gap was invisible on a current site and total on an older
		// one: every screen in French, whatever the reader had chosen.
		add_action( 'init', array( 'MSRWA_I18N', 'load' ) );
		add_action( 'rest_api_init', array( 'MSRWA_REST', 'register' ) );
		add_action( 'transition_post_status', array( 'MSRWA_Stack', 'on_publish' ), 10, 3 );
		add_action( 'msrwa_run_step', array( 'MSRWA_Run', 'tick' ) );
		MSRWA_Worker::hooks();
		add_action( 'msrwa_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( is_admin() ) { MSRWA_Admin::hooks(); MSRWA_Editor::hooks(); add_action( 'admin_notices', array( __CLASS__, 'cron_notice' ) ); }
		if ( defined( 'WP_CLI' ) && WP_CLI ) { WP_CLI::add_command( 'msrwa tick', array( __CLASS__, 'cli_tick' ) ); }
		// The toolbar is drawn on the site as well as in the admin.
		add_action( 'admin_bar_menu', array( 'MSRWA_Admin', 'toolbar' ), 75 );
		MSRWA_Schema::hooks();
		MSRWA_Head::hooks();
		MSRWA_Stack::hooks();
		if ( get_option( 'msrwa_db_version' ) !== MSRWA_VERSION ) { MSRWA_DB::install(); self::caps(); }
	}

	public static function schedules( $schedules ) {
		$schedules['msrwa_five_minutes'] = array( 'interval' => 300, 'display' => 'MS Recipes : toutes les cinq minutes' );
		return $schedules;
	}
}
