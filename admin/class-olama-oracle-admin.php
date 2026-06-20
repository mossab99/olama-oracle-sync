<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Admin {
    private $logger;
    private $client;

    public function __construct() {
        $this->logger = new Olama_Oracle_Sync_Logger();
        $this->client = new Olama_Oracle_Api_Client();
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_olama_oracle_run_students_full_sync_batch', array($this, 'ajax_run_students_full_sync_batch'));
        add_action('wp_ajax_olama_oracle_run_student_years_full_sync_batch', array($this, 'ajax_run_student_years_full_sync_batch'));
        add_action('wp_ajax_olama_oracle_update_full_sync_progress', array($this, 'ajax_update_full_sync_progress'));
        add_action('wp_ajax_olama_oracle_reset_full_sync_progress', array($this, 'ajax_reset_full_sync_progress'));
    }

    public function register_menu() {
        add_menu_page('Olama Oracle Sync', 'Olama Oracle Sync', 'manage_options', 'olama-oracle-sync', array($this, 'dashboard'), 'dashicons-update-alt', 57);
        add_submenu_page('olama-oracle-sync', 'Dashboard', 'Dashboard', 'manage_options', 'olama-oracle-sync', array($this, 'dashboard'));
        add_submenu_page('olama-oracle-sync', 'Settings', 'Settings', 'manage_options', 'olama-oracle-sync-settings', array($this, 'settings'));
        add_submenu_page('olama-oracle-sync', 'Manual Sync', 'Manual Sync', 'manage_options', 'olama-oracle-sync-manual', array($this, 'manual_sync'));
        add_submenu_page('olama-oracle-sync', 'Sync Runs', 'Sync Runs', 'manage_options', 'olama-oracle-sync-runs', array($this, 'sync_runs'));
        add_submenu_page('olama-oracle-sync', 'Validation', 'Validation', 'manage_options', 'olama-oracle-sync-validation', array($this, 'validation'));
    }

    public function handle_actions() {
        if (!current_user_can('manage_options') || empty($_POST['olama_oracle_action'])) {
            return;
        }

        check_admin_referer('olama_oracle_action');
        $action = sanitize_text_field($_POST['olama_oracle_action']);
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : $this->get_default_study_year();
        $message = '';
        $success = true;

        if ('save_settings' === $action) {
            Olama_Oracle_Settings::update(isset($_POST['settings']) ? wp_unslash($_POST['settings']) : array());
            $message = 'Settings saved.';
        } elseif ('test_connection' === $action) {
            $run_id = $this->logger->start_run('connection_test');
            $result = $this->client->health();
            $success = $result['success'];
            $message = $success ? 'Oracle connection succeeded.' : 'Oracle connection failed: ' . $result['message'];
            $this->logger->log_item($run_id, 'oracle_bridge', null, null, null, 'test_connection', $success ? 'success' : 'failed', $message);
            $this->logger->finish_run($run_id, $success ? 'completed' : 'failed', $success ? '' : $result['message']);
        } elseif ('import_families' === $action) {
            $result = (new Olama_Oracle_Family_Importer($this->client, $this->logger))->import_all();
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_one_family' === $action) {
            $family_id = isset($_POST['oracle_family_id']) ? sanitize_text_field(wp_unslash($_POST['oracle_family_id'])) : '';
            if ($family_id) {
                $result = (new Olama_Oracle_Family_Importer($this->client, $this->logger))->import_one($family_id);
            } else {
                $run_id = $this->logger->start_run('family');
                $this->logger->log_item($run_id, 'family', null, null, null, 'failed', 'failed', 'Oracle FAMILY_ID is required.');
                $this->logger->finish_run($run_id, 'failed', 'Oracle FAMILY_ID is required.');
                $result = array('success' => false, 'message' => 'Oracle FAMILY_ID is required.');
            }
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_family_students' === $action) {
            $family_id = isset($_POST['oracle_family_id']) ? sanitize_text_field(wp_unslash($_POST['oracle_family_id'])) : '';
            if ($family_id) {
                $result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_family($family_id, null, $study_year);
            } else {
                $run_id = $this->logger->start_run('family_students');
                $this->logger->log_item($run_id, 'student', null, null, null, 'failed', 'failed', 'Oracle FAMILY_ID is required.');
                $this->logger->finish_run($run_id, 'failed', 'Oracle FAMILY_ID is required.');
                $result = array('success' => false, 'message' => 'Oracle FAMILY_ID is required.');
            }
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_all_students' === $action) {
            $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_all_imported_families($offset, $study_year);
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_student_years' === $action) {
            $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_student_years_for_imported_families($offset, $study_year);
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_students_by_study_year' === $action) {
            $result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_students_by_study_year($study_year);
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('run_validation' === $action) {
            $run_id = $this->logger->start_run('validation');
            $this->logger->log_item($run_id, 'validation', null, null, null, 'report', 'success', 'Validation report generated.');
            $this->logger->finish_run($run_id);
            $message = 'Validation report refreshed.';
        }

        $redirect = add_query_arg(array(
            'page' => isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'olama-oracle-sync-manual',
            'olama_message' => $message,
            'olama_success' => $success ? '1' : '0',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function dashboard() {
        global $wpdb;

        $runs = $wpdb->prefix . 'olama_oracle_sync_runs';
        $last = $wpdb->get_row("SELECT * FROM `" . esc_sql($runs) . "` ORDER BY id DESC LIMIT 1", ARRAY_A);
        echo '<div class="wrap"><h1>Olama Oracle Sync</h1>';
        $this->notice();
        echo '<p>This plugin imports Oracle families, students, and student academic placement into Olama Core only.</p>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>Core dependency</th><td>' . esc_html(defined('OLAMA_CORE_VERSION') ? 'Active, version ' . OLAMA_CORE_VERSION : 'Missing') . '</td></tr>';
        echo '<tr><th>Sync mode</th><td>' . esc_html(Olama_Oracle_Settings::get('sync_mode') === 'manual' ? 'Manual Only' : 'Scheduled Read-Only') . '</td></tr>';
        echo '<tr><th>Last run</th><td>' . esc_html($last ? '#' . $last['id'] . ' ' . $last['sync_type'] . ' - ' . $last['status'] : 'None') . '</td></tr>';
        echo '</tbody></table></div>';
    }

    public function settings() {
        $settings = Olama_Oracle_Settings::get();
        echo '<div class="wrap"><h1>Oracle Sync Settings</h1>';
        $this->notice();
        echo '<form method="post">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="save_settings">';
        echo '<table class="form-table"><tbody>';
        $this->field('Oracle Bridge Base URL', 'base_url', $settings['base_url']);
        $this->field('API Key', 'api_key', $settings['api_key'], 'password');
        $this->field('Default Study Year', 'default_study_year', $settings['default_study_year']);
        $this->field('Request Timeout', 'request_timeout', $settings['request_timeout'], 'number');
        $this->field('Batch Size', 'batch_size', $settings['batch_size'], 'number');
        echo '<tr><th>Store Raw Payloads</th><td><select name="settings[store_raw_payloads]"><option value="yes"' . selected($settings['store_raw_payloads'], 'yes', false) . '>Yes</option><option value="no"' . selected($settings['store_raw_payloads'], 'no', false) . '>No</option></select></td></tr>';
        echo '<tr><th>Sync Mode</th><td><select name="settings[sync_mode]"><option value="manual"' . selected($settings['sync_mode'], 'manual', false) . '>Manual Only</option><option value="scheduled_read_only"' . selected($settings['sync_mode'], 'scheduled_read_only', false) . '>Scheduled Read-Only</option></select></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Settings');
        echo '</form></div>';
    }

    public function manual_sync() {
        $study_year = $this->get_default_study_year();
        echo '<div class="wrap"><h1>Manual Oracle Sync</h1>';
        $this->notice();
        echo '<div style="display:grid;gap:16px;max-width:760px;">';
        $this->full_sync_panel($study_year);
        echo '<h2>مزامنة متقدمة بالدفعات</h2>';
        $this->action_form('Test Oracle Connection', 'test_connection');
        $this->action_form('Import All Families', 'import_families');
        $this->action_form('Import One Family by Oracle FAMILY_ID', 'import_one_family', true);
        $this->action_form('Import Students for One Family', 'import_family_students', true, false, true, $study_year);
        $this->action_form('Import Students for Imported Families Batch', 'import_all_students', false, true, true, $study_year);
        $this->action_form('Import Student Years for Imported Families Batch', 'import_student_years', false, true, true, $study_year);
        $this->action_form('Import Students by Study Year', 'import_students_by_study_year', false, false, true, $study_year);
        $this->action_form('Run Validation Report', 'run_validation');
        echo '</div></div>';
    }

    public function enqueue_assets($hook) {
        if ('olama-oracle-sync_page_olama-oracle-sync-manual' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'olama-oracle-full-sync',
            OLAMA_ORACLE_SYNC_URL . 'assets/js/full-sync.js',
            array('jquery'),
            OLAMA_ORACLE_SYNC_VERSION,
            true
        );
        wp_localize_script('olama-oracle-full-sync', 'OlamaOracleFullSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('olama_oracle_full_sync'),
            'dashboardUrl' => admin_url('admin.php?page=olama-core'),
            'studentsUrl' => admin_url('admin.php?page=olama-core-directory&tab=students'),
            'studentYearsUrl' => admin_url('admin.php?page=olama-core-directory&tab=student_years'),
        ));
    }

    public function ajax_run_students_full_sync_batch() {
        $this->ajax_run_full_sync_batch('students');
    }

    public function ajax_run_student_years_full_sync_batch() {
        $this->ajax_run_full_sync_batch('student_years');
    }

    public function ajax_update_full_sync_progress() {
        $this->verify_full_sync_ajax();
        $mode = $this->sanitize_sync_mode(isset($_POST['mode']) ? wp_unslash($_POST['mode']) : '');
        $study_year = $this->sanitize_study_year_from_request();
        $progress = $this->get_full_sync_progress($mode, $study_year);
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'paused';
        if (!in_array($status, array('running', 'paused', 'completed', 'failed'), true)) {
            $status = 'paused';
        }
        $progress['status'] = $status;
        $progress['last_run_at'] = current_time('mysql');
        $this->save_full_sync_progress($mode, $study_year, $progress);
        wp_send_json_success($progress);
    }

    public function ajax_reset_full_sync_progress() {
        $this->verify_full_sync_ajax();
        $mode = $this->sanitize_sync_mode(isset($_POST['mode']) ? wp_unslash($_POST['mode']) : '');
        $study_year = $this->sanitize_study_year_from_request();
        delete_option($this->progress_option_name($mode, $study_year));
        wp_send_json_success($this->default_full_sync_progress($mode, $study_year));
    }

    public function sync_runs() {
        global $wpdb;

        $runs = $wpdb->prefix . 'olama_oracle_sync_runs';
        $items = $wpdb->prefix . 'olama_oracle_sync_items';
        $run_id = isset($_GET['run_id']) ? absint($_GET['run_id']) : 0;
        echo '<div class="wrap"><h1>Oracle Sync Runs</h1>';
        if ($run_id) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . esc_sql($items) . '` WHERE sync_run_id = %d ORDER BY id DESC LIMIT 500', $run_id), ARRAY_A);
            echo '<h2>Sync Items for Run #' . esc_html($run_id) . '</h2>';
            $this->table($rows, array('entity_type' => 'Entity Type', 'entity_uid' => 'Entity UID', 'oracle_family_id' => 'Oracle Family ID', 'oracle_student_id' => 'Oracle Student ID', 'operation' => 'Operation', 'status' => 'Status', 'message' => 'Message', 'created_at' => 'Created At'));
        } else {
            $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($runs) . '` ORDER BY id DESC LIMIT 100', ARRAY_A);
            foreach ($rows as &$row) {
                $row['view_items'] = '<a href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-runs&run_id=' . absint($row['id']))) . '">View Items</a>';
            }
            unset($row);
            $this->table($rows, array('id' => 'Run ID', 'sync_type' => 'Sync Type', 'status' => 'Status', 'started_at' => 'Started At', 'finished_at' => 'Finished At', 'records_seen' => 'Records Seen', 'records_created' => 'Created', 'records_updated' => 'Updated', 'records_skipped' => 'Skipped', 'records_failed' => 'Failed', 'error_summary' => 'Error Summary', 'view_items' => 'View Items'), true);
        }
        echo '</div>';
    }

    public function validation() {
        $report = (new Olama_Oracle_Validator())->report();
        $failed = $report['Last failed sync items'];
        unset($report['Last failed sync items']);

        echo '<div class="wrap"><h1>Oracle Sync Validation</h1>';
        $this->notice();
        echo '<form method="post" style="margin-bottom:12px;">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="run_validation">';
        submit_button('Run Validation Report', 'secondary', 'submit', false);
        echo '</form>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table><h2>Last Failed Sync Items</h2>';
        $this->table($failed, array('entity_type' => 'Entity Type', 'entity_uid' => 'Entity UID', 'oracle_family_id' => 'Oracle Family ID', 'oracle_student_id' => 'Oracle Student ID', 'operation' => 'Operation', 'message' => 'Message', 'created_at' => 'Created At'));
        echo '</div>';
    }

    private function field($label, $key, $value, $type = 'text') {
        echo '<tr><th><label for="olama_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="olama_' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function full_sync_panel($study_year) {
        $configured_limit = max(1, min(100, absint(Olama_Oracle_Settings::get('batch_size'))));
        if (!$configured_limit) {
            $configured_limit = 25;
        }
        echo '<section id="olama-oracle-full-sync" style="background:#fff;border:1px solid #ccd0d4;padding:16px;">';
        echo '<h2 style="margin-top:0;">مزامنة كاملة تلقائية</h2>';
        echo '<p>تشغيل مزامنة كاملة على دفعات آمنة بدون الحاجة إلى إدخال offset يدوياً.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(140px,1fr));gap:12px;margin-bottom:12px;">';
        echo '<label>Study Year <input type="text" class="regular-text" data-olama-full-sync-study-year value="' . esc_attr($study_year) . '" required></label>';
        echo '<label>Batch Size <input type="number" min="1" max="100" step="1" class="small-text" data-olama-full-sync-limit value="' . esc_attr($configured_limit) . '"></label>';
        echo '<label>Start Offset <input type="number" min="0" step="1" class="small-text" data-olama-full-sync-offset value="0"></label>';
        echo '</div>';
        echo '<p style="display:flex;flex-wrap:wrap;gap:8px;">';
        echo '<button type="button" class="button button-primary" data-olama-full-sync-start="students">تشغيل مزامنة الطلاب كاملة</button>';
        echo '<button type="button" class="button button-primary" data-olama-full-sync-start="student_years">تشغيل مزامنة سنوات الطلاب كاملة</button>';
        echo '<button type="button" class="button" data-olama-full-sync-pause>إيقاف مؤقت</button>';
        echo '<button type="button" class="button" data-olama-full-sync-resume>استكمال</button>';
        echo '<button type="button" class="button" data-olama-full-sync-reset>إعادة ضبط التقدم</button>';
        echo '</p>';
        echo '<div style="height:16px;background:#f0f0f1;border:1px solid #dcdcde;margin:12px 0;overflow:hidden;"><div data-olama-full-sync-bar style="height:100%;width:0;background:#2271b1;"></div></div>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($this->full_sync_progress_labels() as $key => $label) {
            echo '<tr><th style="width:240px;">' . esc_html($label) . '</th><td data-olama-full-sync-field="' . esc_attr($key) . '">—</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p data-olama-full-sync-message style="font-weight:600;"></p>';
        echo '<p data-olama-full-sync-links style="display:none;"><a class="button" href="' . esc_url(admin_url('admin.php?page=olama-core')) . '">Olama Core Dashboard</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=olama-core-directory&tab=students')) . '">Directory Students</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=olama-core-directory&tab=student_years')) . '">Directory Student Years</a></p>';
        echo '</section>';
    }

    private function full_sync_progress_labels() {
        return array(
            'status' => 'Current status',
            'study_year' => 'Study year',
            'last_offset' => 'Current offset',
            'total_families' => 'Total families',
            'families_processed' => 'Families processed',
            'total_students_inserted' => 'Students inserted',
            'total_students_updated' => 'Students updated',
            'total_student_year_rows_inserted' => 'Student-year rows inserted',
            'total_student_year_rows_updated' => 'Student-year rows updated',
            'total_errors' => 'Errors',
            'progress_percentage' => 'Progress percentage',
            'last_family_id' => 'Last processed family ID',
        );
    }

    private function ajax_run_full_sync_batch($mode) {
        $this->verify_full_sync_ajax();
        $mode = $this->sanitize_sync_mode($mode);
        $study_year = $this->sanitize_study_year_from_request();
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? max(1, min(100, absint($_POST['limit']))) : 25;
        $progress = $this->get_full_sync_progress($mode, $study_year);
        if ('completed' === $progress['status'] && $offset <= (int) $progress['last_offset']) {
            wp_send_json_success($this->full_sync_response_from_progress($progress, true, 'Sync is already completed.'));
        }

        $progress['status'] = 'running';
        $progress['limit'] = $limit;
        $progress['last_run_at'] = current_time('mysql');
        if (empty($progress['started_at'])) {
            $progress['started_at'] = current_time('mysql');
        }

        $importer = new Olama_Oracle_Student_Importer($this->client, $this->logger);
        $result = 'student_years' === $mode
            ? $importer->import_student_years_for_imported_families($offset, $study_year, $limit)
            : $importer->import_all_imported_families($offset, $study_year, $limit);

        if (empty($result['success'])) {
            $progress['status'] = 'failed';
            $progress['total_errors'] = isset($progress['total_errors']) ? (int) $progress['total_errors'] + 1 : 1;
            $progress['last_error_message'] = isset($result['message']) ? sanitize_text_field($result['message']) : 'Sync batch failed.';
            $this->save_full_sync_progress($mode, $study_year, $progress);
            wp_send_json_error($this->full_sync_response_from_progress($progress, false, $progress['last_error_message']));
        }

        $summary = isset($result['summary']) && is_array($result['summary']) ? $result['summary'] : array();
        $families_processed = isset($summary['families']) ? (int) $summary['families'] : 0;
        $next_offset = isset($result['next_offset']) && null !== $result['next_offset'] ? absint($result['next_offset']) : $offset + $families_processed;
        $total_families = $this->count_imported_families();
        $done = $next_offset >= $total_families || 0 === $families_processed || $next_offset <= $offset;

        $progress['last_offset'] = $offset;
        $progress['next_offset'] = $next_offset;
        $progress['total_families'] = $total_families;
        $progress['families_processed'] = isset($progress['families_processed']) ? (int) $progress['families_processed'] + $families_processed : $families_processed;
        $progress['total_students_inserted'] = isset($progress['total_students_inserted']) ? (int) $progress['total_students_inserted'] + (int) ($summary['students_created'] ?? 0) : (int) ($summary['students_created'] ?? 0);
        $progress['total_students_updated'] = isset($progress['total_students_updated']) ? (int) $progress['total_students_updated'] + (int) ($summary['students_updated'] ?? 0) : (int) ($summary['students_updated'] ?? 0);
        $progress['total_student_year_rows_inserted'] = isset($progress['total_student_year_rows_inserted']) ? (int) $progress['total_student_year_rows_inserted'] + (int) ($summary['student_years_created'] ?? 0) : (int) ($summary['student_years_created'] ?? 0);
        $progress['total_student_year_rows_updated'] = isset($progress['total_student_year_rows_updated']) ? (int) $progress['total_student_year_rows_updated'] + (int) ($summary['student_years_updated'] ?? 0) : (int) ($summary['student_years_updated'] ?? 0);
        $progress['total_errors'] = isset($progress['total_errors']) ? (int) $progress['total_errors'] + (int) ($summary['failed'] ?? 0) : (int) ($summary['failed'] ?? 0);
        $progress['last_family_id'] = isset($summary['last_family_id']) ? sanitize_text_field($summary['last_family_id']) : ($progress['last_family_id'] ?? '');
        $progress['status'] = $done ? 'completed' : 'running';
        $progress['last_message'] = isset($result['message']) ? sanitize_text_field($result['message']) : '';
        if ($done) {
            $progress['completed_at'] = current_time('mysql');
        }
        $this->save_full_sync_progress($mode, $study_year, $progress);

        wp_send_json_success($this->full_sync_response_from_progress($progress, $done, $progress['last_message']));
    }

    private function verify_full_sync_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'), 403);
        }
        check_ajax_referer('olama_oracle_full_sync', 'nonce');
    }

    private function sanitize_sync_mode($mode) {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('students', 'student_years'), true) ? $mode : 'students';
    }

    private function sanitize_study_year_from_request() {
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : $this->get_default_study_year();
        return '' !== $study_year ? $study_year : $this->get_default_study_year();
    }

    private function progress_option_name($mode, $study_year) {
        $mode = $this->sanitize_sync_mode($mode);
        $hash = substr(md5((string) $study_year), 0, 16);
        return 'olama_oracle_' . $mode . '_sync_progress_' . $hash;
    }

    private function default_full_sync_progress($mode, $study_year) {
        return array(
            'mode' => $this->sanitize_sync_mode($mode),
            'study_year' => $study_year,
            'last_offset' => 0,
            'next_offset' => 0,
            'limit' => 25,
            'total_families' => $this->count_imported_families(),
            'families_processed' => 0,
            'total_students_inserted' => 0,
            'total_students_updated' => 0,
            'total_student_year_rows_inserted' => 0,
            'total_student_year_rows_updated' => 0,
            'total_errors' => 0,
            'last_family_id' => '',
            'last_error_message' => '',
            'last_message' => '',
            'started_at' => '',
            'last_run_at' => '',
            'completed_at' => '',
            'status' => 'paused',
        );
    }

    private function get_full_sync_progress($mode, $study_year) {
        $progress = get_option($this->progress_option_name($mode, $study_year), array());
        return wp_parse_args(is_array($progress) ? $progress : array(), $this->default_full_sync_progress($mode, $study_year));
    }

    private function save_full_sync_progress($mode, $study_year, $progress) {
        update_option($this->progress_option_name($mode, $study_year), $progress, false);
    }

    private function full_sync_response_from_progress($progress, $done, $message) {
        $total = max(0, (int) ($progress['total_families'] ?? 0));
        $next_offset = max(0, (int) ($progress['next_offset'] ?? 0));
        $percentage = $total > 0 ? min(100, round(($next_offset / $total) * 100, 2)) : 0;

        return array(
            'success' => true,
            'mode' => $progress['mode'] ?? 'students',
            'study_year' => $progress['study_year'] ?? '',
            'offset' => (int) ($progress['last_offset'] ?? 0),
            'next_offset' => $next_offset,
            'limit' => (int) ($progress['limit'] ?? 25),
            'total_families' => $total,
            'families_processed' => (int) ($progress['families_processed'] ?? 0),
            'students_inserted' => (int) ($progress['total_students_inserted'] ?? 0),
            'students_updated' => (int) ($progress['total_students_updated'] ?? 0),
            'student_year_rows_inserted' => (int) ($progress['total_student_year_rows_inserted'] ?? 0),
            'student_year_rows_updated' => (int) ($progress['total_student_year_rows_updated'] ?? 0),
            'errors' => (int) ($progress['total_errors'] ?? 0),
            'status' => $progress['status'] ?? 'paused',
            'progress_percentage' => $percentage,
            'last_family_id' => $progress['last_family_id'] ?? '',
            'done' => (bool) $done,
            'message' => $message,
        );
    }

    private function count_imported_families() {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_families';
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');
    }

    private function action_form($label, $action, $needs_family = false, $has_offset = false, $has_study_year = false, $study_year = '') {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'olama-oracle-sync-manual';
        echo '<form method="post" action="' . esc_url(add_query_arg(array('page' => $page), admin_url('admin.php'))) . '" style="background:#fff;border:1px solid #ccd0d4;padding:14px;">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="' . esc_attr($action) . '">';
        if ($needs_family) {
            echo '<p><label>Oracle FAMILY_ID <input type="text" name="oracle_family_id" class="regular-text" required></label></p>';
        }
        if ($has_study_year) {
            echo '<p><label>Study Year <input type="text" name="study_year" class="regular-text" value="' . esc_attr($study_year) . '" required></label></p>';
        }
        if ($has_offset) {
            echo '<p><label>Start offset <input type="number" min="0" step="1" name="offset" class="small-text" value="0"></label> <span class="description">Run the next batch using the offset shown in the previous result.</span></p>';
        }
        submit_button($label, 'primary', 'submit', false);
        echo '</form>';
    }

    private function get_default_study_year() {
        return sanitize_text_field((string) Olama_Oracle_Settings::get('default_study_year'));
    }

    private function notice() {
        if (!isset($_GET['olama_message'])) {
            return;
        }
        $class = isset($_GET['olama_success']) && $_GET['olama_success'] === '1' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html(wp_unslash($_GET['olama_message'])) . '</p></div>';
    }

    private function table($rows, $columns, $allow_html = false) {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . esc_attr(count($columns)) . '">No records found.</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                $value = isset($row[$key]) ? $row[$key] : '';
                echo '<td>' . ($allow_html && $key === 'view_items' ? wp_kses_post($value) : esc_html($value)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
