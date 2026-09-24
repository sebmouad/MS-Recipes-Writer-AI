<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Starts a recipe's next tick at once, in a request of its own.
 *
 * WordPress cron waits for a visit before it fires, and wp-cron.php then runs
 * every due event one after another: a lot's recipes went through one at a
 * time, each waiting on the next visitor between two ticks. The site now calls
 * itself for each recipe as soon as it is queued, so recipes run side by side
 * and a tick follows the last without a visit. Cron stays behind it: a site
 * that cannot call itself loses nothing, it only waits as it did before.
 */
final class MSRWA_Worker {

	/** Recipes running at once; more only queues calls at the provider's rate limit. */
	const PARALLEL = 3;

	/** How long a signed call stays valid. */
	const TTL = 120;

	/** Calls one request may start: a watchdog re-arming a hundred recipes starts three. */
	private static $started = 0;

	public static function hooks() {
		add_action( 'wp_ajax_nopriv_msrwa_worker', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_msrwa_worker', array( __CLASS__, 'handle' ) );
	}

	/** Asks the site to run this recipe's tick now, without waiting for the answer. */
	public static function kick( $id ) {
		$id = absint( $id );
		if ( ! $id || self::$started >= self::PARALLEL || self::running() >= self::PARALLEL ) { return false; }
		if ( MSRWA_Queue::held() ) { return false; }
		self::$started++;
		$expires = time() + self::TTL;
		wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'blocking' => false, 'timeout' => 0.01,
			// A loopback: the site's own certificate may not verify against itself.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body' => array( 'action' => 'msrwa_worker', 'run' => $id, 'expires' => $expires, 'sig' => self::sign( $id, $expires ) ),
		) );
		return true;
	}

	/** The call itself: nobody is logged in, so the signature is the permission. */
	public static function handle() {
		$id = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- signed below.
		$expires = isset( $_POST['expires'] ) ? absint( $_POST['expires'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::valid( $id, $expires, $sig ) ) { wp_die( '', '', array( 'response' => 403 ) ); }
		// The caller did not wait: nothing is sent back, and the tick runs to its end.
		MSRWA_Run::tick( $id );
		wp_die( '', '', array( 'response' => 204 ) );
	}

	public static function valid( $id, $expires, $sig ) {
		return $id > 0 && $expires >= time() && $expires <= time() + self::TTL && hash_equals( self::sign( $id, $expires ), (string) $sig );
	}

	private static function sign( $id, $expires ) { return hash_hmac( 'sha256', 'msrwa_worker|' . absint( $id ) . '|' . absint( $expires ), wp_salt( 'auth' ) ); }

	private static function running() {
		global $wpdb;
		$t = MSRWA_DB::tables();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['runs']} WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until > UTC_TIMESTAMP()" );
	}
}
