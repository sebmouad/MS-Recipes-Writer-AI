<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Storage for the one thing this plugin does.
 *
 * A writer hands over several recipes and several photographs. Each recipe
 * becomes a brief, every brief is sent to the engine, and every run is written
 * down here in full — because what the engine reports is the only evidence
 * there is about what an article cost and why it was refused.
 *
 * Five tables. `batches` is one submission; `runs` is one recipe inside it; the
 * other three hold what the engine reported, in rows rather than one blob, so
 * the cost of a step can be asked about across every run instead of only inside
 * one of them.
 */
final class MSRWA_DB {

	/** Bumped whenever the schema below changes. */
	const SCHEMA = 7;

	public static function tables() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'msrwa_';
		return array(
			'batches'   => $prefix . 'batches',
			'runs'      => $prefix . 'runs',
			'steps'     => $prefix . 'steps',
			'calls'     => $prefix . 'calls',
			'events'    => $prefix . 'events',
			'artifacts' => $prefix . 'artifacts',
		);
	}

	/**
	 * Tables from the plugin that stood here before this one.
	 *
	 * Nothing left in the code can read them, but they are not touched. A
	 * plugin update that destroys data is a plugin update nobody can undo, and
	 * the owner would rather drop them himself once he is satisfied than
	 * discover on activation that he cannot get them back.
	 *
	 * Listed here so it is on the record which tables are dormant.
	 */
	private static function superseded() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'msrwa_';
		return array_map( static function ( $name ) use ( $prefix ) { return $prefix . $name; }, array(
			'jobs', 'reservations', 'settings', 'settings_history', 'providers', 'models', 'prompts', 'snapshots',
			'lab_runs', 'lab_steps', 'lab_calls', 'lab_events', 'lab_artifacts',
		) );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();

		foreach ( array(
			"CREATE TABLE {$t['batches']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n owner_id bigint(20) unsigned NOT NULL,\n label varchar(191) NOT NULL DEFAULT '',\n status varchar(32) NOT NULL DEFAULT 'matching',\n recipes smallint unsigned NOT NULL DEFAULT 0,\n images smallint unsigned NOT NULL DEFAULT 0,\n budget_usd decimal(12,6) NOT NULL DEFAULT 0,\n profile varchar(24) NOT NULL DEFAULT 'full',\n language varchar(8) NOT NULL DEFAULT 'fr',\n dispatch_at datetime NULL,\n config_json longtext NULL,\n matching_json longtext NULL,\n error_message text NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY owner_status (owner_id,status),\n KEY created_at (created_at),\n KEY waiting (status,dispatch_at)\n) $charset;",

			"CREATE TABLE {$t['runs']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NOT NULL,\n owner_id bigint(20) unsigned NOT NULL,\n label varchar(191) NOT NULL DEFAULT '',\n brief_json longtext NULL,\n result_json longtext NULL,\n status varchar(32) NOT NULL DEFAULT 'queued',\n step varchar(120) NOT NULL DEFAULT '',\n steps_done smallint unsigned NOT NULL DEFAULT 0,\n steps_total smallint unsigned NOT NULL DEFAULT 0,\n cost_usd decimal(12,6) NOT NULL DEFAULT 0,\n seconds decimal(12,1) NOT NULL DEFAULT 0,\n approved tinyint(1) NULL,\n draft_post_id bigint(20) unsigned NOT NULL DEFAULT 0,\n workspace text NULL,\n error_message text NULL,\n lock_token varchar(64) NULL,\n lock_until datetime NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY batch_status (batch_id,status),\n KEY owner_status (owner_id,status),\n KEY owner_created (owner_id,created_at),\n KEY owner_recent (owner_id,id),\n KEY lock_until (lock_until)\n) $charset;",

			"CREATE TABLE {$t['steps']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n run_id bigint(20) unsigned NOT NULL,\n step varchar(64) NOT NULL,\n provider varchar(32) NOT NULL DEFAULT '',\n model varchar(191) NOT NULL DEFAULT '',\n seconds decimal(10,1) NOT NULL DEFAULT 0,\n attempts tinyint unsigned NOT NULL DEFAULT 1,\n input_tokens int unsigned NOT NULL DEFAULT 0,\n output_tokens int unsigned NOT NULL DEFAULT 0,\n cost_usd decimal(12,6) NULL,\n bucket varchar(24) NOT NULL DEFAULT 'other',\n status varchar(48) NOT NULL DEFAULT '',\n passed smallint unsigned NULL,\n total smallint unsigned NULL,\n checks_json longtext NULL,\n checks_failed smallint unsigned NOT NULL DEFAULT 0,\n error_message text NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY run_step (run_id,step),\n KEY step_model (step,model),\n KEY step_failures (step,checks_failed)\n) $charset;",

			"CREATE TABLE {$t['calls']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n run_id bigint(20) unsigned NOT NULL,\n step varchar(64) NOT NULL,\n provider varchar(32) NOT NULL DEFAULT '',\n model varchar(191) NOT NULL DEFAULT '',\n tier varchar(24) NOT NULL DEFAULT '',\n endpoint text NULL,\n seconds decimal(10,1) NOT NULL DEFAULT 0,\n input_tokens int unsigned NOT NULL DEFAULT 0,\n output_tokens int unsigned NOT NULL DEFAULT 0,\n cached_tokens int unsigned NOT NULL DEFAULT 0,\n cost_usd decimal(12,6) NULL,\n priced tinyint(1) NOT NULL DEFAULT 1,\n status varchar(48) NOT NULL DEFAULT '',\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY run_step (run_id,step),\n KEY provider_model (provider,model)\n) $charset;",

			"CREATE TABLE {$t['events']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n run_id bigint(20) unsigned NOT NULL,\n at_seconds decimal(10,1) NOT NULL DEFAULT 0,\n kind varchar(32) NOT NULL DEFAULT '',\n step varchar(64) NOT NULL DEFAULT '',\n message text NULL,\n data_json longtext NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY run_order (run_id,id),\n KEY run_kind (run_id,kind)\n) $charset;",

			"CREATE TABLE {$t['artifacts']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n run_id bigint(20) unsigned NOT NULL,\n artifact_key varchar(64) NOT NULL,\n content_json longtext NULL,\n bytes int unsigned NOT NULL DEFAULT 0,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY run_artifact (run_id,artifact_key)\n) $charset;",
		) as $statement ) { dbDelta( $statement ); }

		foreach ( array(
			'profile' => "ALTER TABLE {$t['batches']} ADD COLUMN profile varchar(24) NOT NULL DEFAULT 'full' AFTER budget_usd",
			'language' => "ALTER TABLE {$t['batches']} ADD COLUMN language varchar(8) NOT NULL DEFAULT 'fr' AFTER profile",
		) as $column => $statement ) {
			if ( ! self::column_exists( $t['batches'], $column ) ) { $wpdb->query( $statement ); }
		}
		if ( ! self::column_exists( $t['batches'], 'dispatch_at' ) ) {
			$wpdb->query( "ALTER TABLE {$t['batches']} ADD COLUMN dispatch_at datetime NULL AFTER language" );
		}
		if ( ! self::column_exists( $t['steps'], 'checks_failed' ) ) {
			$wpdb->query( "ALTER TABLE {$t['steps']} ADD COLUMN checks_failed smallint unsigned NOT NULL DEFAULT 0 AFTER checks_json" );
			// Bounded and idempotent: it fills in what already exists without
			// reading a whole table into memory on a site that has been running
			// for a year.
			self::backfill_check_counts();
		}

		self::reclaim_duplicates();
		update_option( 'msrwa_schema', self::SCHEMA, false );
		update_option( 'msrwa_db_version', MSRWA_VERSION, false );
	}

	/**
	 * Releases the artifacts WordPress already holds, on runs that predate the
	 * plugin knowing not to store them twice.
	 *
	 * In pages, once, under its own flag. It only removes rows for runs that
	 * actually produced a draft, so a run whose only copy is here keeps it.
	 */
	private static function reclaim_duplicates() {
		global $wpdb;
		if ( get_option( 'msrwa_duplicates_reclaimed' ) ) { return; }
		$t = self::tables();
		if ( ! self::table_exists( $t['runs'] ) || ! self::table_exists( $t['artifacts'] ) ) { return; }

		$guard = 0;
		$last = 0;
		while ( $guard++ < 200 ) {
			$ids = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$t['runs']} WHERE draft_post_id > 0 AND id > %d ORDER BY id ASC LIMIT 100", $last ) );
			if ( ! $ids ) { break; }
			foreach ( $ids as $id ) {
				$last = (int) $id;
				MSRWA_Run::release_stored( $last );
			}
		}
		update_option( 'msrwa_duplicates_reclaimed', 1, false );
	}

	/** Which of the previous plugin's tables are still on disk, for a screen to report. */
	public static function dormant() {
		$found = array();
		foreach ( self::superseded() as $table ) {
			if ( self::table_exists( $table ) ) { $found[] = $table; }
		}
		return $found;
	}

	/**
	 * Counts the failing checks on steps stored before the column existed.
	 *
	 * In pages, oldest first, stopping when it runs out. Nothing here loads
	 * more than a few hundred rows at a time, because this runs on activation
	 * on somebody's live site and must not be the thing that exhausts its
	 * memory limit.
	 */
	private static function backfill_check_counts() {
		global $wpdb;
		$t = self::tables();
		$last = 0;
		$guard = 0;
		while ( $guard++ < 200 ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, checks_json FROM {$t['steps']} WHERE id > %d AND checks_json IS NOT NULL ORDER BY id ASC LIMIT 200", $last ), ARRAY_A );
			if ( ! $rows ) { return; }
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				$failed = 0;
				foreach ( (array) json_decode( (string) $row['checks_json'], true ) as $check ) {
					if ( is_array( $check ) && empty( $check['pass'] ) ) { $failed++; }
				}
				if ( $failed ) { $wpdb->update( $t['steps'], array( 'checks_failed' => $failed ), array( 'id' => $last ), array( '%d' ), array( '%d' ) ); }
			}
		}
	}

	/**
	 * Inserts many rows in one statement.
	 *
	 * A wave produces a dozen events and several steps, and each one was a
	 * round trip to the database. One statement per table per tick instead,
	 * with every value still passed through $wpdb->prepare.
	 */
	public static function insert_many( $table, array $rows ) {
		global $wpdb;
		if ( ! $rows ) { return 0; }
		$columns = array_keys( $rows[0] );
		$placeholders = array();
		$values = array();

		foreach ( $rows as $row ) {
			$marks = array();
			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? null;
				if ( null === $value ) { $marks[] = 'NULL'; continue; }
				$marks[] = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
				$values[] = $value;
			}
			$placeholders[] = '(' . implode( ',', $marks ) . ')';
		}

		$sql = 'INSERT INTO ' . $table . ' (`' . implode( '`,`', $columns ) . '`) VALUES ' . implode( ',', $placeholders );
		return (int) $wpdb->query( $values ? $wpdb->prepare( $sql, $values ) : $sql );
	}

	/**
	 * Drops the verbose half of old runs, keeping the figures.
	 *
	 * Events are the narration and grow without limit; steps, calls and
	 * artifacts are the evidence and stay. Bounded per pass so a cron tick that
	 * is killed halfway simply resumes on the next one.
	 */
	public static function prune_events( $days = 90, $limit = 2000 ) {
		global $wpdb;
		$t = self::tables();
		$days = max( 7, (int) $days );
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE e FROM {$t['events']} e INNER JOIN {$t['runs']} r ON r.id = e.run_id
			WHERE r.status NOT IN ('queued','running') AND r.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			LIMIT %d", $days, max( 100, (int) $limit ) ) );
	}

	public static function column_exists( $table, $column ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
	}

	public static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Strips secrets and binary payloads from anything on its way into storage.
	 *
	 * Nothing written by this plugin has ever needed to carry an API key or a
	 * megabyte of base64, and a diagnostic row is read by people who should not
	 * be handed either.
	 */
	public static function sanitize( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 262144 ) { return substr( $value, 0, 262144 ) . "\n[tronqué]"; }
		if ( ! is_array( $value ) ) { return $value; }
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( preg_match( '/(?:api.?key|secret|password|authorization|lock_token)/i', (string) $key ) ) { $out[ $key ] = '[masqué]'; continue; }
			if ( in_array( strtolower( (string) $key ), array( 'base64', 'b64_json', 'data' ), true ) && is_string( $item ) && strlen( $item ) > 1024 ) {
				$out[ $key ] = array( 'binary_omitted' => true, 'encoded_bytes' => strlen( $item ), 'sha256' => hash( 'sha256', $item ) );
				continue;
			}
			$out[ $key ] = self::sanitize( $item );
		}
		return $out;
	}
}
