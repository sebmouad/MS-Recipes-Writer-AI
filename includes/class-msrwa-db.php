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
			'settings' => $prefix . 'settings',
			'settings_history' => $prefix . 'settings_history',
			'providers' => $prefix . 'providers',
			'models' => $prefix . 'models',
			'prompts' => $prefix . 'prompts',
			'artifacts' => $prefix . 'artifacts',
			'snapshots' => $prefix . 'snapshots',
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();
		$sql = array(
			"CREATE TABLE {$t['settings']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n scope varchar(24) NOT NULL DEFAULT 'global',\n owner_id bigint(20) unsigned NOT NULL DEFAULT 0,\n group_name varchar(64) NOT NULL DEFAULT 'general',\n setting_key varchar(120) NOT NULL,\n value_json longtext NULL,\n value_type varchar(24) NOT NULL DEFAULT 'string',\n is_secret tinyint(1) NOT NULL DEFAULT 0,\n source varchar(32) NOT NULL DEFAULT 'default',\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n updated_by bigint(20) unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY (id),\n UNIQUE KEY scope_owner_key (scope,owner_id,setting_key),\n KEY group_name (group_name),\n KEY updated_at (updated_at)\n) $charset;",
			"CREATE TABLE {$t['settings_history']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n scope varchar(24) NOT NULL DEFAULT 'global',\n owner_id bigint(20) unsigned NOT NULL DEFAULT 0,\n group_name varchar(64) NOT NULL DEFAULT 'general',\n setting_key varchar(120) NOT NULL,\n old_value_json longtext NULL,\n new_value_json longtext NULL,\n changed_by bigint(20) unsigned NOT NULL DEFAULT 0,\n change_source varchar(32) NOT NULL DEFAULT 'admin',\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n KEY setting_time (setting_key,created_at),\n KEY actor_time (changed_by,created_at)\n) $charset;",
			"CREATE TABLE {$t['providers']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n provider_key varchar(32) NOT NULL,\n label varchar(120) NOT NULL,\n enabled tinyint(1) NOT NULL DEFAULT 1,\n adapter_class varchar(120) NULL,\n capabilities_json longtext NULL,\n api_config_json longtext NULL,\n status_json longtext NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY provider_key (provider_key),\n KEY enabled (enabled)\n) $charset;",
			"CREATE TABLE {$t['models']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n provider_key varchar(32) NOT NULL,\n model_id varchar(191) NOT NULL,\n label varchar(191) NOT NULL,\n stable tinyint(1) NOT NULL DEFAULT 0,\n enabled tinyint(1) NOT NULL DEFAULT 1,\n capabilities_json longtext NULL,\n pricing_json longtext NULL,\n api_specifics_json longtext NULL,\n source_url text NULL,\n verified_at datetime NULL,\n created_at datetime NOT NULL,\n updated_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY provider_model (provider_key,model_id),\n KEY stable_enabled (stable,enabled)\n) $charset;",
			"CREATE TABLE {$t['prompts']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n prompt_key varchar(120) NOT NULL,\n label varchar(191) NOT NULL,\n version int unsigned NOT NULL DEFAULT 1,\n content longtext NOT NULL,\n content_hash char(64) NOT NULL,\n is_active tinyint(1) NOT NULL DEFAULT 0,\n created_by bigint(20) unsigned NOT NULL DEFAULT 0,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY prompt_version (prompt_key,version),\n KEY active_prompt (prompt_key,is_active)\n) $charset;",
			"CREATE TABLE {$t['artifacts']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NOT NULL DEFAULT 0,\n job_id bigint(20) unsigned NOT NULL,\n artifact_key varchar(120) NOT NULL,\n version int unsigned NOT NULL DEFAULT 1,\n status varchar(32) NOT NULL DEFAULT 'current',\n content_json longtext NULL,\n content_hash char(64) NOT NULL,\n created_by bigint(20) unsigned NOT NULL DEFAULT 0,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY job_artifact_version (job_id,artifact_key,version),\n KEY job_current (job_id,status),\n KEY batch_id (batch_id)\n) $charset;",
			"CREATE TABLE {$t['snapshots']} (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n batch_id bigint(20) unsigned NOT NULL DEFAULT 0,\n job_id bigint(20) unsigned NOT NULL DEFAULT 0,\n snapshot_type varchar(80) NOT NULL,\n version int unsigned NOT NULL DEFAULT 1,\n data_json longtext NULL,\n data_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY (id),\n UNIQUE KEY target_snapshot_version (batch_id,job_id,snapshot_type,version),\n KEY job_type (job_id,snapshot_type),\n KEY batch_type (batch_id,snapshot_type)\n) $charset;",
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
		self::backfill_structured_data();
		self::set_system_value( 'db_version', MSRWA_VERSION );
	}

	private static function backfill_structured_data() {
		global $wpdb;
		$t = self::tables();
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,batch_id,input_json,artifacts_json,selected_models_json FROM {$t['jobs']} ORDER BY id ASC LIMIT 200 OFFSET %d", $offset ) );
			foreach ( $rows as $row ) {
				$input = json_decode( (string) $row->input_json, true );
				$artifacts = json_decode( (string) $row->artifacts_json, true );
				$models = json_decode( (string) $row->selected_models_json, true );
				if ( is_array( $input ) ) { self::snapshot( 'input', $input, $row->batch_id, $row->id ); }
				if ( is_array( $models ) ) { self::snapshot( 'model_plan', $models, $row->batch_id, $row->id ); }
				if ( is_array( $artifacts ) ) {
					$artifacts = self::sanitize_persisted_data( $artifacts );
					$wpdb->update( $t['jobs'], array( 'artifacts_json' => wp_json_encode( $artifacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
					self::store_artifacts( $row->id, $row->batch_id, $artifacts );
				}
			}
			$offset += count( $rows );
		} while ( count( $rows ) === 200 );
		$batches = $wpdb->get_results( "SELECT id,settings_snapshot FROM {$t['batches']} WHERE settings_snapshot IS NOT NULL AND settings_snapshot <> ''" );
		foreach ( $batches as $batch ) {
			$data = json_decode( (string) $batch->settings_snapshot, true );
			if ( is_array( $data ) ) {
				$data = self::sanitize_persisted_data( $data );
				$wpdb->update( $t['batches'], array( 'settings_snapshot' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => $batch->id ), array( '%s' ), array( '%d' ) );
				self::snapshot( 'settings', $data, $batch->id, 0 );
			}
		}
	}

	public static function system_value( $key, $default = '' ) {
		global $wpdb;
		$t = self::tables();
		if ( ! self::table_exists( $t['settings'] ) ) { return $default; }
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value_json FROM {$t['settings']} WHERE scope = 'system' AND owner_id = 0 AND setting_key = %s", sanitize_key( $key ) ) );
		if ( null === $value ) { return $default; }
		$decoded = json_decode( (string) $value, true );
		return null === $decoded && 'null' !== $value ? $default : $decoded;
	}

	public static function set_system_value( $key, $value ) {
		global $wpdb;
		$t = self::tables();
		$now = current_time( 'mysql', true );
		$sql = "INSERT INTO {$t['settings']} (scope,owner_id,group_name,setting_key,value_json,value_type,is_secret,source,created_at,updated_at,updated_by) VALUES ('system',0,'system',%s,%s,%s,0,'system',%s,%s,0) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),value_type=VALUES(value_type),updated_at=VALUES(updated_at)";
		return false !== $wpdb->query( $wpdb->prepare( $sql, sanitize_key( $key ), wp_json_encode( $value ), gettype( $value ), $now, $now ) );
	}

	public static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function store_artifacts( $job_id, $batch_id, $artifacts ) {
		if ( ! is_array( $artifacts ) ) { return; }
		foreach ( $artifacts as $key => $content ) { self::store_artifact( $job_id, $batch_id, $key, $content ); }
	}

	public static function store_artifact( $job_id, $batch_id, $key, $content ) {
		global $wpdb;
		$t = self::tables();
		$key = sanitize_key( $key );
		$content = self::sanitize_persisted_data( $content );
		$json = wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$hash = hash( 'sha256', (string) $json );
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT version,content_hash FROM {$t['artifacts']} WHERE job_id = %d AND artifact_key = %s ORDER BY version DESC LIMIT 1", absint( $job_id ), $key ) );
		if ( $last && hash_equals( (string) $last->content_hash, $hash ) ) { return (int) $last->version; }
		$version = $last ? (int) $last->version + 1 : 1;
		$wpdb->update( $t['artifacts'], array( 'status' => 'superseded' ), array( 'job_id' => absint( $job_id ), 'artifact_key' => $key, 'status' => 'current' ), array( '%s' ), array( '%d', '%s', '%s' ) );
		$wpdb->insert( $t['artifacts'], array( 'batch_id' => absint( $batch_id ), 'job_id' => absint( $job_id ), 'artifact_key' => $key, 'version' => $version, 'status' => 'current', 'content_json' => $json, 'content_hash' => $hash, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ) );
		return $version;
	}

	public static function snapshot( $type, $data, $batch_id = 0, $job_id = 0 ) {
		global $wpdb;
		$t = self::tables();
		$type = sanitize_key( $type );
		$data = self::sanitize_persisted_data( $data );
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$hash = hash( 'sha256', (string) $json );
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT version,data_hash FROM {$t['snapshots']} WHERE batch_id = %d AND job_id = %d AND snapshot_type = %s ORDER BY version DESC LIMIT 1", absint( $batch_id ), absint( $job_id ), $type ) );
		if ( $last && hash_equals( (string) $last->data_hash, $hash ) ) { return (int) $last->version; }
		$version = $last ? (int) $last->version + 1 : 1;
		$wpdb->insert( $t['snapshots'], array( 'batch_id' => absint( $batch_id ), 'job_id' => absint( $job_id ), 'snapshot_type' => $type, 'version' => $version, 'data_json' => $json, 'data_hash' => $hash, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' ) );
		return $version;
	}

	private static function sanitize_persisted_data( $value, $parent = '' ) {
		if ( ! is_array( $value ) ) { return $value; }
		$out = array();
		foreach ( $value as $key => $item ) {
			$name = (string) $key;
			if ( 'benchmark' === $parent && in_array( $name, array( 'author', 'author_id', 'sample' ), true ) ) { continue; }
			$out[ $key ] = self::sanitize_persisted_data( $item, $name );
		}
		return $out;
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
		$estimate = max( 0, (float) $estimate );
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
