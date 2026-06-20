<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Sync_Logger {
    private $runs_table;
    private $items_table;

    public function __construct() {
        global $wpdb;

        $this->runs_table = $wpdb->prefix . 'olama_oracle_sync_runs';
        $this->items_table = $wpdb->prefix . 'olama_oracle_sync_items';
    }

    public function start_run($sync_type) {
        global $wpdb;

        $wpdb->insert($this->runs_table, array(
            'sync_type' => sanitize_text_field($sync_type),
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
        ));

        return (int) $wpdb->insert_id;
    }

    public function log_item($run_id, $entity_type, $entity_uid, $oracle_family_id, $oracle_student_id, $operation, $status, $message = '') {
        global $wpdb;

        $wpdb->insert($this->items_table, array(
            'sync_run_id' => (int) $run_id,
            'entity_type' => sanitize_text_field($entity_type),
            'entity_uid' => $entity_uid ? sanitize_text_field($entity_uid) : null,
            'oracle_family_id' => $oracle_family_id ? sanitize_text_field($oracle_family_id) : null,
            'oracle_student_id' => $oracle_student_id ? sanitize_text_field($oracle_student_id) : null,
            'operation' => sanitize_text_field($operation),
            'status' => sanitize_text_field($status),
            'message' => sanitize_textarea_field($message),
            'created_at' => current_time('mysql'),
        ));
    }

    public function finish_run($run_id, $status = 'completed', $error_summary = '') {
        global $wpdb;

        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS seen,
                SUM(operation = 'created' AND status = 'success') AS created,
                SUM(operation = 'updated' AND status = 'success') AS updated,
                SUM(operation = 'skipped' AND status = 'success') AS skipped,
                SUM(status = 'failed') AS failed
             FROM `" . esc_sql($this->items_table) . "` WHERE sync_run_id = %d",
            (int) $run_id
        ), ARRAY_A);

        $failed = isset($counts['failed']) ? (int) $counts['failed'] : 0;
        $wpdb->update($this->runs_table, array(
            'status' => $failed && $status === 'completed' ? 'completed_with_errors' : sanitize_text_field($status),
            'finished_at' => current_time('mysql'),
            'records_seen' => isset($counts['seen']) ? (int) $counts['seen'] : 0,
            'records_created' => isset($counts['created']) ? (int) $counts['created'] : 0,
            'records_updated' => isset($counts['updated']) ? (int) $counts['updated'] : 0,
            'records_skipped' => isset($counts['skipped']) ? (int) $counts['skipped'] : 0,
            'records_failed' => $failed,
            'error_summary' => $error_summary ? sanitize_textarea_field($error_summary) : null,
        ), array('id' => (int) $run_id));
    }

    public function store_payload($entity_type, $oracle_family_id, $oracle_student_id, $endpoint, array $payload) {
        if (Olama_Oracle_Settings::get('store_raw_payloads') !== 'yes') {
            return;
        }

        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'olama_oracle_raw_payloads', array(
            'entity_type' => sanitize_text_field($entity_type),
            'oracle_family_id' => $oracle_family_id ? sanitize_text_field($oracle_family_id) : null,
            'oracle_student_id' => $oracle_student_id ? sanitize_text_field($oracle_student_id) : null,
            'endpoint' => sanitize_text_field($endpoint),
            'payload_json' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ));
    }
}
