<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Migrator {
    public static function activate() {
        if (!defined('OLAMA_CORE_VERSION') || !function_exists('olama_core')) {
            deactivate_plugins(plugin_basename(OLAMA_ORACLE_SYNC_FILE));
            wp_die('Olama Oracle Sync requires Olama Core to be active. Activate Olama Core first.');
        }

        self::create_tables();
        if (!get_option('olama_oracle_sync_settings')) {
            add_option('olama_oracle_sync_settings', self::default_settings());
        }
        update_option('olama_oracle_sync_db_version', OLAMA_ORACLE_SYNC_VERSION);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('olama_oracle_scheduled_sync');
        wp_clear_scheduled_hook('olama_oracle_cleanup_payloads');
    }

    public static function default_settings() {
        return array(
            'base_url' => '',
            'api_key' => '',
            'default_study_year' => '',
            'request_timeout' => 30,
            'batch_size' => 100,
            'store_raw_payloads' => 'no',
            'raw_payload_retention_days' => 7,
            'sync_mode' => 'manual',
            'schedule_frequency' => 'daily',
        );
    }

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $runs = $wpdb->prefix . 'olama_oracle_sync_runs';
        $items = $wpdb->prefix . 'olama_oracle_sync_items';
        $payloads = $wpdb->prefix . 'olama_oracle_raw_payloads';
        $jobs = $wpdb->prefix . 'olama_oracle_sync_jobs';

        dbDelta("CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_type VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            records_seen INT DEFAULT 0,
            records_created INT DEFAULT 0,
            records_updated INT DEFAULT 0,
            records_skipped INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            error_summary TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY idx_sync_type (sync_type),
            KEY idx_status (status),
            KEY idx_started_at (started_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_run_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_uid VARCHAR(100) NULL,
            oracle_family_id VARCHAR(100) NULL,
            oracle_student_id VARCHAR(100) NULL,
            operation VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_run (sync_run_id),
            KEY idx_entity (entity_type, entity_uid),
            KEY idx_oracle_keys (oracle_family_id, oracle_student_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$payloads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            oracle_family_id VARCHAR(100) NULL,
            oracle_student_id VARCHAR(100) NULL,
            endpoint VARCHAR(255) NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_entity (entity_type),
            KEY idx_oracle_keys (oracle_family_id, oracle_student_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$jobs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(40) NOT NULL,
            study_year VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL,
            current_phase VARCHAR(40) NOT NULL,
            phase_index INT UNSIGNED DEFAULT 0,
            total_phases INT UNSIGNED DEFAULT 0,
            cursor_offset INT UNSIGNED DEFAULT 0,
            batch_size INT UNSIGNED DEFAULT 25,
            active_run_id BIGINT UNSIGNED NULL,
            phase_runs LONGTEXT NULL,
            summary_json LONGTEXT NULL,
            message TEXT NULL,
            error_summary TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            started_at DATETIME NOT NULL,
            heartbeat_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_started_at (started_at)
        ) {$charset_collate};");
    }
}
