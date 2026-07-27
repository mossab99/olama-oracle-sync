<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent, server-owned orchestration for Oracle synchronization jobs.
 *
 * Importers continue to own mapping and Core writes. This class only advances
 * small phases/batches and records enough state to resume after navigation.
 */
class Olama_Oracle_Job_Manager {
    const PROCESS_HOOK = 'olama_oracle_process_sync_job';
    const SCHEDULE_HOOK = 'olama_oracle_scheduled_sync';
    const CLEANUP_HOOK = 'olama_oracle_cleanup_payloads';

    private $table;
    private $logger;
    private $client;

    public function __construct() {
        global $wpdb;

        $this->table = $wpdb->prefix . 'olama_oracle_sync_jobs';
        $this->logger = new Olama_Oracle_Sync_Logger();
        $this->client = new Olama_Oracle_Api_Client();
    }

    public function init() {
        add_action(self::PROCESS_HOOK, array($this, 'process_job'));
        add_action(self::SCHEDULE_HOOK, array($this, 'start_scheduled_job'));
        add_action(self::CLEANUP_HOOK, array($this, 'cleanup_payloads'));
        add_action('olama_oracle_settings_updated', array($this, 'sync_schedules'));
        $this->ensure_cleanup_schedule();
        $this->sync_schedules();
    }

    public function start_job($scope, $study_year, $created_by = null) {
        global $wpdb;

        $scope = in_array($scope, array('complete', 'family_pipeline'), true) ? $scope : 'complete';
        $study_year = sanitize_text_field((string) $study_year);
        if ('' === $study_year) {
            return new WP_Error('oracle_job_year_required', 'The active study year is required.');
        }

        $active = $this->active_job();
        if ($active) {
            return new WP_Error('oracle_job_running', 'Another Oracle synchronization job is already active.', array('job_id' => (int) $active['id']));
        }

        $phases = $this->phases_for_scope($scope);
        $wpdb->insert($this->table, array(
            'scope' => $scope,
            'study_year' => $study_year,
            'status' => 'queued',
            'current_phase' => $phases[0],
            'phase_index' => 0,
            'total_phases' => count($phases),
            'cursor_offset' => 0,
            'batch_size' => max(1, min(100, absint(Olama_Oracle_Settings::get('batch_size')))),
            'phase_runs' => wp_json_encode(array()),
            'summary_json' => wp_json_encode(array()),
            'message' => 'Synchronization queued.',
            'created_by' => null === $created_by ? get_current_user_id() : absint($created_by),
            'started_at' => current_time('mysql'),
        ));

        $job_id = (int) $wpdb->insert_id;
        if (!$job_id) {
            return new WP_Error('oracle_job_create_failed', 'Could not create the synchronization job.');
        }

        $this->schedule_job($job_id);
        return $this->get_job($job_id);
    }

    public function process_job($job_id) {
        $job_id = absint($job_id);
        $job = $this->get_job($job_id, false);
        if (!$job || !in_array($job['status'], array('queued', 'running'), true)) {
            return;
        }

        $lock_key = 'olama_oracle_job_lock_' . $job_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $this->update_job($job_id, array('status' => 'running', 'heartbeat_at' => current_time('mysql')));
            $job = $this->get_job($job_id, false);
            $result = $this->process_phase($job);

            if (is_wp_error($result)) {
                $this->fail_job($job, $result->get_error_message());
                return;
            }

            if (!empty($result['job_complete'])) {
                $this->complete_job($job_id);
                return;
            }

            $this->schedule_job($job_id);
        } catch (Throwable $e) {
            $this->fail_job($job, $e->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }

    public function get_job($job_id, $with_counts = true) {
        global $wpdb;

        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$this->table}` WHERE id = %d", absint($job_id)), ARRAY_A);
        if (!$job) {
            return null;
        }

        $job['phase_runs'] = $this->decode_json($job['phase_runs']);
        $job['summary'] = $this->decode_json($job['summary_json']);
        unset($job['summary_json']);
        $job['progress_percentage'] = $this->progress_percentage($job);
        if ($with_counts) {
            $job['counts'] = $this->aggregate_counts($job['phase_runs']);
        }
        $job['done'] = in_array($job['status'], array('completed', 'completed_with_errors', 'failed'), true);

        return $job;
    }

    public function recent_jobs($limit = 20) {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `{$this->table}` ORDER BY id DESC LIMIT %d", max(1, min(100, absint($limit)))));
        return array_values(array_filter(array_map(array($this, 'get_job'), $ids)));
    }

    public function active_job() {
        global $wpdb;

        $id = $wpdb->get_var("SELECT id FROM `{$this->table}` WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        return $id ? $this->get_job($id) : null;
    }

    public function start_scheduled_job() {
        if ('scheduled' !== Olama_Oracle_Settings::get('sync_mode') || $this->active_job()) {
            return;
        }

        $this->start_job('complete', Olama_Oracle_Settings::get('default_study_year'), 0);
    }

    public function cleanup_payloads() {
        $this->logger->purge_expired_payloads();
    }

    public function sync_schedules() {
        $frequency = Olama_Oracle_Settings::get('schedule_frequency');
        if (!in_array($frequency, array('hourly', 'twicedaily', 'daily'), true)) {
            $frequency = 'daily';
        }

        $current = wp_get_scheduled_event(self::SCHEDULE_HOOK);
        if ('scheduled' !== Olama_Oracle_Settings::get('sync_mode')) {
            wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
            return;
        }
        if (!$current || $current->schedule !== $frequency) {
            wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $frequency, self::SCHEDULE_HOOK);
        }
    }

    private function process_phase($job) {
        $phases = $this->phases_for_scope($job['scope']);
        $phase_index = (int) $job['phase_index'];
        if (!isset($phases[$phase_index])) {
            return array('job_complete' => true);
        }

        $phase = $phases[$phase_index];
        if ('families' === $phase) {
            return $this->process_families($job);
        }
        if ('students' === $phase) {
            return $this->process_students($job);
        }

        $result = $this->run_single_phase($phase, $job['study_year']);
        if (empty($result['success'])) {
            return new WP_Error('oracle_job_phase_failed', isset($result['message']) ? $result['message'] : ucfirst($phase) . ' synchronization failed.');
        }
        if (!empty($result['run_id'])) {
            $this->record_phase_run($job['id'], $phase, absint($result['run_id']));
        }

        return $this->advance_phase($job, ucfirst($phase) . ' phase completed.');
    }

    private function process_families($job) {
        $run_id = $this->ensure_phase_run($job, 'families', 'job_families');
        $result = (new Olama_Oracle_Family_Importer($this->client, $this->logger))->import_batch(
            (int) $job['cursor_offset'],
            (int) $job['batch_size'],
            $run_id
        );
        if (empty($result['success'])) {
            $this->logger->finish_run($run_id, 'failed', $result['message']);
            return new WP_Error('oracle_families_failed', $result['message']);
        }

        $summary = $job['summary'];
        if (!empty($summary['family_page_first']) && !empty($result['first_family_id']) && (string) $summary['family_page_first'] === (string) $result['first_family_id']) {
            $message = 'Oracle Bridge returned the same families page twice; the job stopped to avoid a pagination loop.';
            $this->logger->log_item($run_id, 'family', null, null, null, 'skipped', 'failed', $message);
            $this->logger->finish_run($run_id, 'failed', $message);
            return new WP_Error('oracle_family_pagination_loop', $message);
        }
        $summary['family_page_first'] = isset($result['first_family_id']) ? $result['first_family_id'] : '';
        $summary['families_processed'] = isset($summary['families_processed']) ? (int) $summary['families_processed'] + (int) $result['records_seen'] : (int) $result['records_seen'];

        if (!empty($result['done'])) {
            $this->logger->finish_run($run_id);
            $this->update_job($job['id'], array('summary_json' => wp_json_encode($summary)));
            return $this->advance_phase($job, 'Families phase completed.');
        }

        $this->update_job($job['id'], array(
            'cursor_offset' => (int) $result['next_offset'],
            'summary_json' => wp_json_encode($summary),
            'message' => 'Synchronizing families: ' . (int) $summary['families_processed'] . ' processed.',
            'heartbeat_at' => current_time('mysql'),
        ));
        return array('job_complete' => false);
    }

    private function process_students($job) {
        global $wpdb;

        $run_id = $this->ensure_phase_run($job, 'students', 'job_students');
        $result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_all_imported_families(
            (int) $job['cursor_offset'],
            $job['study_year'],
            (int) $job['batch_size'],
            $run_id
        );
        if (empty($result['success'])) {
            $this->logger->finish_run($run_id, 'failed', $result['message']);
            return new WP_Error('oracle_students_failed', $result['message']);
        }

        $summary = $job['summary'];
        $batch = isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : array();
        foreach (array('families', 'students_created', 'students_updated', 'students_skipped', 'student_years_created', 'student_years_updated', 'student_years_skipped', 'failed') as $key) {
            $summary[$key] = isset($summary[$key]) ? (int) $summary[$key] + (int) ($batch[$key] ?? 0) : (int) ($batch[$key] ?? 0);
        }
        if (!empty($batch['last_family_id'])) {
            $summary['last_family_id'] = $batch['last_family_id'];
        }

        $next_offset = isset($result['next_offset']) && null !== $result['next_offset'] ? absint($result['next_offset']) : 0;
        if (!$next_offset) {
            $this->logger->finish_run($run_id);
            $this->update_job($job['id'], array('summary_json' => wp_json_encode($summary)));
            return $this->advance_phase($job, 'Students and related family domains completed.');
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}olama_core_families`");
        $this->update_job($job['id'], array(
            'cursor_offset' => $next_offset,
            'summary_json' => wp_json_encode($summary),
            'message' => 'Synchronizing family records: ' . min($next_offset, $total) . ' / ' . $total . '.',
            'heartbeat_at' => current_time('mysql'),
        ));
        return array('job_complete' => false);
    }

    private function run_single_phase($phase, $study_year) {
        if ('employees' === $phase) {
            return (new Olama_Oracle_Employee_Importer($this->client, $this->logger))->import_all();
        }
        if ('academic' === $phase) {
            return (new Olama_Oracle_Academic_Importer($this->client, $this->logger))->import($study_year);
        }
        if ('transportation' === $phase) {
            return (new Olama_Oracle_Transport_Master_Importer($this->client, $this->logger))->import_all($study_year);
        }
        if ('validation' === $phase) {
            $run_id = $this->logger->start_run('validation');
            (new Olama_Oracle_Validator())->report();
            $this->logger->log_item($run_id, 'validation', null, null, null, 'report', 'success', 'Post-sync validation completed.');
            $this->logger->finish_run($run_id);
            return array('success' => true, 'run_id' => $run_id, 'message' => 'Validation completed.');
        }

        return array('success' => false, 'message' => 'Unknown synchronization phase: ' . $phase);
    }

    private function ensure_phase_run($job, $phase, $sync_type) {
        if (!empty($job['active_run_id'])) {
            return (int) $job['active_run_id'];
        }

        $run_id = $this->logger->start_run($sync_type);
        $this->record_phase_run($job['id'], $phase, $run_id);
        $this->update_job($job['id'], array('active_run_id' => $run_id));
        return $run_id;
    }

    private function record_phase_run($job_id, $phase, $run_id) {
        $job = $this->get_job($job_id, false);
        $runs = $job ? $job['phase_runs'] : array();
        if (!isset($runs[$phase])) {
            $runs[$phase] = array();
        }
        if (!in_array((int) $run_id, array_map('intval', $runs[$phase]), true)) {
            $runs[$phase][] = (int) $run_id;
        }
        $this->update_job($job_id, array('phase_runs' => wp_json_encode($runs)));
    }

    private function advance_phase($job, $message) {
        $phases = $this->phases_for_scope($job['scope']);
        $next = (int) $job['phase_index'] + 1;
        if (!isset($phases[$next])) {
            return array('job_complete' => true);
        }

        $this->update_job($job['id'], array(
            'phase_index' => $next,
            'current_phase' => $phases[$next],
            'cursor_offset' => 0,
            'active_run_id' => null,
            'message' => $message,
            'heartbeat_at' => current_time('mysql'),
        ));
        return array('job_complete' => false);
    }

    private function complete_job($job_id) {
        $job = $this->get_job($job_id);
        $status = !empty($job['counts']['failed']) ? 'completed_with_errors' : 'completed';
        $this->update_job($job_id, array(
            'status' => $status,
            'current_phase' => 'completed',
            'phase_index' => (int) $job['total_phases'],
            'active_run_id' => null,
            'message' => 'Oracle-to-Core synchronization completed.',
            'heartbeat_at' => current_time('mysql'),
            'finished_at' => current_time('mysql'),
        ));
        do_action('olama_oracle_sync_job_completed', $job_id, $status);
    }

    private function fail_job($job, $message) {
        if (!empty($job['active_run_id'])) {
            $this->logger->finish_run((int) $job['active_run_id'], 'failed', $message);
        }
        $this->update_job($job['id'], array(
            'status' => 'failed',
            'message' => 'Synchronization stopped.',
            'error_summary' => sanitize_textarea_field($message),
            'heartbeat_at' => current_time('mysql'),
            'finished_at' => current_time('mysql'),
        ));
    }

    private function schedule_job($job_id) {
        $args = array(absint($job_id));
        if (!wp_next_scheduled(self::PROCESS_HOOK, $args)) {
            wp_schedule_single_event(time() + 1, self::PROCESS_HOOK, $args);
        }
    }

    private function ensure_cleanup_schedule() {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
        }
    }

    private function phases_for_scope($scope) {
        if ('family_pipeline' === $scope) {
            return array('families', 'students', 'validation');
        }
        return array('families', 'students', 'employees', 'academic', 'transportation', 'validation');
    }

    private function update_job($job_id, $data) {
        global $wpdb;

        $wpdb->update($this->table, $data, array('id' => absint($job_id)));
    }

    private function decode_json($json) {
        $value = json_decode((string) $json, true);
        return is_array($value) ? $value : array();
    }

    private function progress_percentage($job) {
        global $wpdb;

        if (in_array($job['status'], array('completed', 'completed_with_errors'), true)) {
            return 100;
        }
        $total = max(1, (int) $job['total_phases']);
        $phase_progress = 0;
        if ('students' === $job['current_phase']) {
            $families = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}olama_core_families`");
            if ($families > 0) {
                $phase_progress = min(1, (int) $job['cursor_offset'] / $families);
            }
        }
        return min(99, round((((int) $job['phase_index'] + $phase_progress) / $total) * 100));
    }

    private function aggregate_counts($phase_runs) {
        global $wpdb;

        $ids = array();
        foreach ($phase_runs as $runs) {
            $ids = array_merge($ids, array_map('absint', (array) $runs));
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return array('seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) seen,
                    SUM(operation = 'created' AND status = 'success') created,
                    SUM(operation = 'updated' AND status = 'success') updated,
                    SUM(operation = 'skipped' AND status = 'success') skipped,
                    SUM(status = 'failed') failed
             FROM `{$wpdb->prefix}olama_oracle_sync_items`
             WHERE sync_run_id IN ({$placeholders})",
            $ids
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        return array(
            'seen' => (int) ($row['seen'] ?? 0),
            'created' => (int) ($row['created'] ?? 0),
            'updated' => (int) ($row['updated'] ?? 0),
            'skipped' => (int) ($row['skipped'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        );
    }
}
