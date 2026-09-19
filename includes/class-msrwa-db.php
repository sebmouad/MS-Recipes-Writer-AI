<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MSRWA_DB {
	public static function tables() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'msrwa_';
		return array(
			'batches' => $prefix . 'batches',
			'jobs'    => $prefix . 'jobs',
			'events'  => $prefix . 'events',
			'calls'   => $prefix . 'calls',
			'reservations' => $prefix . 'reservations',
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();
		$sql = array(
			"CREATE TABLE {$t['batches']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n owner_id bigint(20) unsigned NOT NULL,\n status varchar(32) NOT NULL DEFAULT 'queued',\n total int unsigned NOT NULL DEFAULT 0,\n completed int unsigned NOT NULL DEFAULT 0,\n settings_snapshot longtext NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY owner_status (owner_id,status),\n KEY created_at (created_at)\n) $charset;",
			"CREATE TABLE {$t['jobs']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NOT NULL,\n owner_id bigint(20) unsigned NOT NULL,\n title text NOT NULL,\n input_json longtext NULL,\n canonical_json longtext NULL,\n artifacts_json longtext NULL,\n selected_models_json longtext NULL,\n cost_estimate decimal(12,6) NOT NULL DEFAULT 0,\n draft_post_id bigint(20) unsigned NOT NULL DEFAULT 0,\n status varchar(32) NOT NULL DEFAULT 'queued',\n stage varchar(64) NOT NULL DEFAULT 'intake',\n correction_cycles tinyint unsigned NOT NULL DEFAULT 0,\n attempts tinyint unsigned NOT NULL DEFAULT 0,\n error_code varchar(80) NULL,\n error_message text NULL,\n lock_token varchar(64) NULL,\n lock_until datetime NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY batch_status (batch_id,status),\n KEY owner_status (owner_id,status),\n KEY lock_until (lock_until)\n) $charset;",
			"CREATE TABLE {$t['events']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NULL,\n job_id bigint(20) unsigned NULL,\n actor_id bigint(20) unsigned NULL,\n event_type varchar(80) NOT NULL,\n payload_json longtext NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY job_event (job_id,event_type),\n KEY created_at (created_at)\n) $charset;",
			"CREATE TABLE {$t['calls']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NULL,\n job_id bigint(20) unsigned NULL,\n provider varchar(32) NOT NULL,\n model varchar(120) NOT NULL,\n operation varchar(64) NOT NULL,\n status varchar(32) NOT NULL,\n http_status smallint unsigned NOT NULL DEFAULT 0,\n request_id varchar(191) NULL,\n input_tokens bigint unsigned NOT NULL DEFAULT 0,\n output_tokens bigint unsigned NOT NULL DEFAULT 0,\n cost_estimate decimal(12,6) NULL,\n uncertain tinyint(1) NOT NULL DEFAULT 0,\n error_code varchar(80) NULL,\n payload_hash char(64) NULL,\n started_at datetime NOT NULL,\n finished_at datetime NULL,\n PRIMARY KEY (id),\n KEY job_status (job_id,status),\n KEY provider_model (provider,model),\n KEY started_at (started_at)\n) $charset;",
			"CREATE TABLE {$t['reservations']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n token char(36) NOT NULL,\n batch_id bigint(20) unsigned NOT NULL,\n job_id bigint(20) unsigned NOT NULL,\n operation varchar(64) NOT NULL,\n amount decimal(12,6) NOT NULL DEFAULT 0,\n settled_amount decimal(12,6) NULL,\n status varchar(16) NOT NULL DEFAULT 'reserved',\n expires_at datetime NOT NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY token (token),\n KEY job_status (job_id,status),\n KEY status_expiry (status,expires_at)\n) $charset;",
		);
		foreach ( $sql as $statement ) { dbDelta( $statement ); }
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$t['jobs']} LIKE %s", 'draft_post_id' ) ) ) {
			$wpdb->query( "ALTER TABLE {$t['jobs']} ADD COLUMN draft_post_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER cost_estimate" );
		}
		update_option( 'msrwa_db_version', MSRWA_VERSION, false );
	}

	public static function event( $type, $batch_id = 0, $job_id = 0, $payload = array() ) {
		global $wpdb;
		$t = self::tables();
		$wpdb->insert( $t['events'], array(
			'batch_id' => absint( $batch_id ), 'job_id' => absint( $job_id ), 'actor_id' => get_current_user_id(),
			'event_type' => sanitize_key( $type ), 'payload_json' => wp_json_encode( $payload ), 'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%d', '%s', '%s', '%s' ) );
	}

	public static function call( $data ) {
		global $wpdb;
		$t = self::tables();
		$allowed = array( 'batch_id', 'job_id', 'provider', 'model', 'operation', 'status', 'http_status', 'request_id', 'input_tokens', 'output_tokens', 'cost_estimate', 'uncertain', 'error_code', 'payload_hash', 'started_at', 'finished_at' );
		$row = array();
		$formats = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) { continue; }
			$row[ $key ] = $data[ $key ];
			$formats[] = in_array( $key, array( 'batch_id', 'job_id', 'http_status', 'input_tokens', 'output_tokens', 'uncertain' ), true ) ? '%d' : ( 'cost_estimate' === $key ? '%f' : '%s' );
		}
		if ( empty( $row['started_at'] ) ) { $row['started_at'] = current_time( 'mysql', true ); $formats[] = '%s'; }
		$wpdb->insert( $t['calls'], $row, $formats );
		if ( ! empty( $row['job_id'] ) && isset( $row['cost_estimate'] ) && null !== $row['cost_estimate'] ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t['jobs']} SET cost_estimate = cost_estimate + %f WHERE id = %d", (float) $row['cost_estimate'], absint( $row['job_id'] ) ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function purge_expired( $log_days = null ) {
		global $wpdb;
		$t = self::tables();
		$settings = class_exists( 'MSRWA_Settings' ) ? MSRWA_Settings::get() : array();
		$days = null === $log_days ? ( isset( $settings['log_days'] ) ? absint( $settings['log_days'] ) : 30 ) : absint( $log_days );
		$days = max( 1, $days );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['events']} WHERE created_at < UTC_TIMESTAMP() - INTERVAL %d DAY", $days ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['calls']} WHERE started_at < UTC_TIMESTAMP() - INTERVAL %d DAY", $days ) );
		$wpdb->query( "DELETE FROM {$t['reservations']} WHERE status = 'reserved' AND expires_at < UTC_TIMESTAMP()" );
		if ( class_exists( 'MSRWA_Storage' ) ) { MSRWA_Storage::purge_expired( isset( $settings['temp_days'] ) ? $settings['temp_days'] : 7 ); }
	}

	public static function budget_used( $job_id = 0, $period = '' ) {
		global $wpdb;
		$t = self::tables();
		$where = '1=1';
		$args = array();
		if ( $job_id ) { $where .= ' AND job_id = %d'; $args[] = absint( $job_id ); }
		if ( 'day' === $period ) { $where .= ' AND started_at >= UTC_DATE()'; }
		if ( 'month' === $period ) { $where .= ' AND started_at >= DATE_FORMAT(UTC_DATE(), \'%Y-%m-01\')'; }
		$sql = "SELECT COALESCE(SUM(cost_estimate),0) FROM {$t['calls']} WHERE {$where}";
		return (float) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	public static function budget_allows( $job, $estimate ) {
		$settings = MSRWA_Settings::get();
		if ( empty( $settings['allow_paid_tests'] ) || (float) $settings['test_budget_usd'] <= 0 ) { return new WP_Error( 'paid_tests_disabled', 'Les appels payants nécessitent un budget de test explicite.' ); }
		$estimate = max( 0, (float) $estimate );
		if ( (float) $settings['test_budget_usd'] > 0 && self::budget_used() + $estimate > (float) $settings['test_budget_usd'] ) { return new WP_Error( 'test_budget_exceeded', 'Le budget de test disponible est insuffisant.' ); }
		if ( (float) $settings['per_recipe_budget_usd'] > 0 && self::budget_used( $job->id ) + $estimate > (float) $settings['per_recipe_budget_usd'] ) { return new WP_Error( 'recipe_budget_exceeded', 'Le budget de cette recette est insuffisant.' ); }
		if ( (float) $settings['daily_budget_usd'] > 0 && self::budget_used( 0, 'day' ) + $estimate > (float) $settings['daily_budget_usd'] ) { return new WP_Error( 'daily_budget_exceeded', 'Le budget quotidien est insuffisant.' ); }
		if ( (float) $settings['monthly_budget_usd'] > 0 && self::budget_used( 0, 'month' ) + $estimate > (float) $settings['monthly_budget_usd'] ) { return new WP_Error( 'monthly_budget_exceeded', 'Le budget mensuel est insuffisant.' ); }
		return true;
	}

	public static function reserve( $job, $estimate, $operation ) {
		global $wpdb;
		$t = self::tables();
		$settings = MSRWA_Settings::get();
		$estimate = max( 0, (float) $estimate );
		if ( empty( $settings['allow_paid_tests'] ) || (float) $settings['test_budget_usd'] <= 0 ) { return new WP_Error( 'paid_tests_disabled', 'Les appels payants nécessitent un budget de test explicite.' ); }
		$lock_name = 'msrwa_budget_' . ( function_exists( 'get_current_blog_id' ) ? absint( get_current_blog_id() ) : 1 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) ) ) { return new WP_Error( 'budget_lock_unavailable', 'Le verrou budgétaire est momentanément indisponible.' ); }
		$token = wp_generate_uuid4();
		try {
			$wpdb->query( "DELETE FROM {$t['reservations']} WHERE status = 'reserved' AND expires_at < UTC_TIMESTAMP()" );
			$global_reserved = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$t['reservations']} WHERE status = 'reserved' AND expires_at >= UTC_TIMESTAMP()" );
			$job_reserved = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$t['reservations']} WHERE job_id = %d AND status = 'reserved' AND expires_at >= UTC_TIMESTAMP()", absint( $job->id ) ) );
			$used_global = self::budget_used();
			$used_job = self::budget_used( $job->id );
			$used_day = self::budget_used( 0, 'day' );
			$used_month = self::budget_used( 0, 'month' );
			if ( (float) $settings['test_budget_usd'] > 0 && $used_global + $global_reserved + $estimate > (float) $settings['test_budget_usd'] ) { return new WP_Error( 'test_budget_exceeded', 'Le budget de test disponible est insuffisant.' ); }
			if ( (float) $settings['per_recipe_budget_usd'] > 0 && $used_job + $job_reserved + $estimate > (float) $settings['per_recipe_budget_usd'] ) { return new WP_Error( 'recipe_budget_exceeded', 'Le budget de cette recette est insuffisant.' ); }
			if ( (float) $settings['daily_budget_usd'] > 0 && $used_day + $global_reserved + $estimate > (float) $settings['daily_budget_usd'] ) { return new WP_Error( 'daily_budget_exceeded', 'Le budget quotidien est insuffisant.' ); }
			if ( (float) $settings['monthly_budget_usd'] > 0 && $used_month + $global_reserved + $estimate > (float) $settings['monthly_budget_usd'] ) { return new WP_Error( 'monthly_budget_exceeded', 'Le budget mensuel est insuffisant.' ); }
			$now = current_time( 'mysql', true );
			$expires = gmdate( 'Y-m-d H:i:s', time() + 1800 );
			$inserted = $wpdb->insert( $t['reservations'], array( 'token' => $token, 'batch_id' => absint( $job->batch_id ), 'job_id' => absint( $job->id ), 'operation' => sanitize_key( $operation ), 'amount' => $estimate, 'status' => 'reserved', 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s' ) );
			if ( ! $inserted ) { return new WP_Error( 'budget_reservation_failed', 'La réservation budgétaire n’a pas pu être enregistrée.' ); }
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		return array( 'token' => $token, 'amount' => $estimate );
	}

	public static function settle( $reservation, $amount = null ) {
		global $wpdb;
		if ( ! is_array( $reservation ) || empty( $reservation['token'] ) ) { return false; }
		$t = self::tables();
		$amount = null === $amount ? (float) $reservation['amount'] : max( 0, (float) $amount );
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t['reservations']} SET status = 'settled', settled_amount = %f, updated_at = %s WHERE token = %s AND status = 'reserved'", $amount, current_time( 'mysql', true ), sanitize_text_field( $reservation['token'] ) ) );
	}

	public static function release( $reservation ) {
		global $wpdb;
		if ( ! is_array( $reservation ) || empty( $reservation['token'] ) ) { return false; }
		$t = self::tables();
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t['reservations']} SET status = 'released', settled_amount = 0, updated_at = %s WHERE token = %s AND status = 'reserved'", current_time( 'mysql', true ), sanitize_text_field( $reservation['token'] ) ) );
	}
}
