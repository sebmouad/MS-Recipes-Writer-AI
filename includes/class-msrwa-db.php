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
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();
		$sql = array(
			"CREATE TABLE {$t['batches']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_id bigint(20) unsigned NOT NULL, status varchar(32) NOT NULL DEFAULT 'queued', total int unsigned NOT NULL DEFAULT 0, completed int unsigned NOT NULL DEFAULT 0, settings_snapshot longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY owner_status (owner_id,status), KEY created_at (created_at)) $charset;",
			"CREATE TABLE {$t['jobs']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, batch_id bigint(20) unsigned NOT NULL, owner_id bigint(20) unsigned NOT NULL, title text NOT NULL, input_json longtext NULL, canonical_json longtext NULL, selected_models_json longtext NULL, cost_estimate decimal(12,6) NOT NULL DEFAULT 0, status varchar(32) NOT NULL DEFAULT 'queued', stage varchar(64) NOT NULL DEFAULT 'intake', correction_cycles tinyint unsigned NOT NULL DEFAULT 0, attempts tinyint unsigned NOT NULL DEFAULT 0, error_code varchar(80) NULL, error_message text NULL, lock_token varchar(64) NULL, lock_until datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY batch_status (batch_id,status), KEY owner_status (owner_id,status), KEY lock_until (lock_until)) $charset;",
			"CREATE TABLE {$t['events']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, batch_id bigint(20) unsigned NULL, job_id bigint(20) unsigned NULL, actor_id bigint(20) unsigned NULL, event_type varchar(80) NOT NULL, payload_json longtext NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY job_event (job_id,event_type), KEY created_at (created_at)) $charset;",
		);
		foreach ( $sql as $statement ) { dbDelta( $statement ); }
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
}
