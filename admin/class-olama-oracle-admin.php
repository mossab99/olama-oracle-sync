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
        add_action('wp_ajax_olama_oracle_start_all_sync', array($this, 'ajax_start_all_sync'));
        add_action('wp_ajax_olama_oracle_sync_one_family', array($this, 'ajax_sync_one_family'));
        add_action('wp_ajax_olama_oracle_run_students_full_sync_batch', array($this, 'ajax_run_students_full_sync_batch'));
        add_action('wp_ajax_olama_oracle_run_student_years_full_sync_batch', array($this, 'ajax_run_student_years_full_sync_batch'));
        add_action('wp_ajax_olama_oracle_update_full_sync_progress', array($this, 'ajax_update_full_sync_progress'));
        add_action('wp_ajax_olama_oracle_reset_full_sync_progress', array($this, 'ajax_reset_full_sync_progress'));
        add_action('wp_ajax_olama_oracle_test_api_endpoint', array($this, 'ajax_test_api_endpoint'));
    }

    public function register_menu() {
        add_menu_page('Olama Oracle Sync', 'Olama Oracle Sync', 'olama_access_oracle_sync', 'olama-oracle-sync', array($this, 'dashboard'), 'dashicons-update-alt', 57);
        add_submenu_page('olama-oracle-sync', 'Dashboard', 'Dashboard', 'olama_access_oracle_sync', 'olama-oracle-sync', array($this, 'dashboard'));
        add_submenu_page('olama-oracle-sync', 'Settings', 'Settings', 'olama_access_oracle_sync', 'olama-oracle-sync-settings', array($this, 'settings'));
        add_submenu_page('olama-oracle-sync', 'API Map', 'API Map', 'olama_access_oracle_sync', 'olama-oracle-sync-api-map', array($this, 'api_map'));
    }

    public function handle_actions() {
        if (!current_user_can('olama_access_oracle_sync') || empty($_POST['olama_oracle_action'])) {
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
        } elseif ('import_employees' === $action) {
            $result = (new Olama_Oracle_Employee_Importer($this->client, $this->logger))->import_all();
            $success = $result['success'];
            $message = $result['message'];
        } elseif ('import_transport_master' === $action) {
            $result = (new Olama_Oracle_Transport_Master_Importer($this->client, $this->logger))->import_all($study_year);
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
            'page' => isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'olama-oracle-sync',
            'olama_message' => $message,
            'olama_success' => $success ? '1' : '0',
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function dashboard() {
        global $wpdb;

        $study_year = $this->get_default_study_year();
        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page olama-oracle-sync-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Olama Oracle Sync</h1><p class="olama-oracle-page-subtitle">مزامنة بيانات العائلات والطلاب من Oracle ERP ومتابعة جودة البيانات من مكان واحد.</p></div>';
        echo '<div class="olama-oracle-header-actions"><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-settings')) . '">الإعدادات</a><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="#olama-oracle-recent-runs">آخر العمليات</a></div></div>';
        $this->notice();
        echo '<div class="olama-oracle-stack">';
        $this->simple_sync_panel($study_year);
        $this->dashboard_validation_card();
        $this->dashboard_recent_runs();
        echo '</div></div></div>';
        return;

        $runs = $wpdb->prefix . 'olama_oracle_sync_runs';
        $last = $wpdb->get_row("SELECT * FROM `" . esc_sql($runs) . "` ORDER BY id DESC LIMIT 1", ARRAY_A);
        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Olama Oracle Sync</h1><p class="olama-oracle-page-subtitle">إدارة مزامنة بيانات Oracle ERP إلى جداول Olama Core المحلية</p></div></div>';
        $this->notice();
        echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><h2 class="olama-oracle-section-title">حالة الربط</h2></div>';
        echo '<div class="olama-oracle-kpi-grid">';
        echo '<div class="olama-oracle-kpi"><span class="olama-oracle-kpi-label">Olama Core</span><strong class="olama-oracle-kpi-value">' . esc_html(defined('OLAMA_CORE_VERSION') ? 'Active' : 'Missing') . '</strong></div>';
        echo '<div class="olama-oracle-kpi"><span class="olama-oracle-kpi-label">Sync mode</span><strong class="olama-oracle-kpi-value">' . esc_html(Olama_Oracle_Settings::get('sync_mode') === 'manual' ? 'Manual' : 'Scheduled') . '</strong></div>';
        echo '<div class="olama-oracle-kpi"><span class="olama-oracle-kpi-label">Last run</span><strong class="olama-oracle-kpi-value">' . esc_html($last ? '#' . $last['id'] . ' ' . $last['status'] : 'None') . '</strong></div>';
        echo '</div></section></div></div>';
    }

    public function settings() {
        $settings = Olama_Oracle_Settings::get();
        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Oracle Sync Settings</h1><p class="olama-oracle-page-subtitle">إعدادات الاتصال والمزامنة الافتراضية مع Oracle Bridge</p></div></div>';
        $this->notice();
        echo '<section class="olama-oracle-section"><form method="post" class="olama-oracle-settings-form">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="save_settings">';
        echo '<table class="form-table olama-oracle-form-table"><tbody>';
        $this->field('Oracle Bridge Base URL', 'base_url', $settings['base_url']);
        $this->field('API Key', 'api_key', $settings['api_key'], 'password');
        $this->field('Default Study Year', 'default_study_year', $settings['default_study_year']);
        $this->field('Request Timeout', 'request_timeout', $settings['request_timeout'], 'number');
        $this->field('Batch Size', 'batch_size', $settings['batch_size'], 'number');
        echo '<tr><th>Store Raw Payloads</th><td><select name="settings[store_raw_payloads]"><option value="yes"' . selected($settings['store_raw_payloads'], 'yes', false) . '>Yes</option><option value="no"' . selected($settings['store_raw_payloads'], 'no', false) . '>No</option></select></td></tr>';
        echo '<tr><th>Sync Mode</th><td><select name="settings[sync_mode]"><option value="manual"' . selected($settings['sync_mode'], 'manual', false) . '>Manual Only</option><option value="scheduled_read_only"' . selected($settings['sync_mode'], 'scheduled_read_only', false) . '>Scheduled Read-Only</option></select></td></tr>';
        echo '</tbody></table>';
        submit_button('Save Settings', 'primary olama-oracle-btn olama-oracle-btn-primary');
        echo '</form></section></div></div>';
    }

    public function manual_sync() {
        $study_year = $this->get_default_study_year();
        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page olama-oracle-sync-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">مزامنة Oracle</h1><p class="olama-oracle-page-subtitle">تحديث العائلات والطلاب وبيانات السنة الدراسية من Oracle ERP إلى Olama Core.</p></div>';
        echo '<div class="olama-oracle-header-actions"><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-settings')) . '">الإعدادات</a><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-runs')) . '">سجل المزامنة</a></div></div>';
        $this->notice();
        echo '<div class="olama-oracle-stack">';
        $this->simple_sync_panel($study_year);
        echo '</div></div></div>';
        return;

        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Manual Oracle Sync</h1><p class="olama-oracle-page-subtitle">تشغيل ومتابعة مزامنة العائلات والطلاب وسنوات الطلاب من Oracle ERP</p></div></div>';
        $this->notice();
        echo '<div class="olama-oracle-stack">';
        $this->full_sync_panel($study_year);
        echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header">';
        echo '<h2>مزامنة متقدمة بالدفعات</h2>';
        echo '<p class="olama-oracle-section-note">أدوات يدوية للتشخيص أو تشغيل دفعة محددة فقط.</p></div><div class="olama-oracle-action-grid">';
        $this->action_form('Test Oracle Connection', 'test_connection');
        $this->action_form('Import All Families', 'import_families');
        $this->action_form('Import One Family by Oracle FAMILY_ID', 'import_one_family', true);
        $this->action_form('Import Students for One Family', 'import_family_students', true, false, true, $study_year);
        $this->action_form('Import Students for Imported Families Batch', 'import_all_students', false, true, true, $study_year);
        $this->action_form('Import Student Years for Imported Families Batch', 'import_student_years', false, true, true, $study_year);
        $this->action_form('Import Students by Study Year', 'import_students_by_study_year', false, false, true, $study_year);
        $this->action_form('Run Validation Report', 'run_validation');
        echo '</div></section></div></div></div>';
    }

    public function enqueue_assets($hook) {
        if (false === strpos($hook, 'olama-oracle-sync')) {
            return;
        }

        wp_enqueue_style(
            'olama-oracle-admin',
            OLAMA_ORACLE_SYNC_URL . 'admin/css/olama-oracle-admin.css',
            array(),
            filemtime(OLAMA_ORACLE_SYNC_PATH . 'admin/css/olama-oracle-admin.css')
        );

        if ('olama-oracle-sync_page_olama-oracle-sync-api-map' === $hook) {
            wp_enqueue_script(
                'olama-oracle-api-map',
                OLAMA_ORACLE_SYNC_URL . 'assets/js/api-map.js',
                array(),
                filemtime(OLAMA_ORACLE_SYNC_PATH . 'assets/js/api-map.js'),
                true
            );
            wp_localize_script('olama-oracle-api-map', 'OlamaOracleApiMap', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('olama_oracle_api_map'),
            ));
            return;
        }

        if (!in_array($hook, array('toplevel_page_olama-oracle-sync', 'olama-oracle-sync_page_olama-oracle-sync-manual'), true)) {
            return;
        }

        wp_enqueue_script(
            'olama-oracle-full-sync',
            OLAMA_ORACLE_SYNC_URL . 'assets/js/full-sync.js',
            array('jquery'),
            filemtime(OLAMA_ORACLE_SYNC_PATH . 'assets/js/full-sync.js'),
            true
        );
        wp_localize_script('olama-oracle-full-sync', 'OlamaOracleFullSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('olama_oracle_full_sync'),
            'batchSize' => max(1, min(100, absint(Olama_Oracle_Settings::get('batch_size')))),
            'dashboardUrl' => admin_url('admin.php?page=olama-core'),
            'studentsUrl' => admin_url('admin.php?page=olama-core-directory&tab=students'),
            'studentYearsUrl' => admin_url('admin.php?page=olama-core-directory&tab=student_years'),
        ));
    }

    public function ajax_run_students_full_sync_batch() {
        $this->ajax_run_full_sync_batch('students');
    }

    public function ajax_start_all_sync() {
        $this->verify_full_sync_ajax();
        $study_year = $this->sanitize_study_year_from_request();
        if ('' === $study_year) {
            wp_send_json_error(array('message' => 'السنة الدراسية مطلوبة.'), 400);
        }

        $mode = 'students';
        $progress = $this->default_full_sync_progress($mode, $study_year);
        $progress['status'] = 'running';
        $progress['phase'] = 'families';
        $progress['started_at'] = current_time('mysql');
        $progress['last_message'] = 'جاري تحديث دليل العائلات من Oracle...';
        $this->save_full_sync_progress($mode, $study_year, $progress);

        $result = (new Olama_Oracle_Family_Importer($this->client, $this->logger))->import_all();
        if (empty($result['success'])) {
            $progress['status'] = 'failed';
            $progress['last_error_message'] = isset($result['message']) ? sanitize_text_field($result['message']) : 'تعذر تحديث العائلات.';
            $progress['last_message'] = $progress['last_error_message'];
            $progress['total_errors'] = 1;
            $this->save_full_sync_progress($mode, $study_year, $progress);
            wp_send_json_error($this->full_sync_response_from_progress($progress, false, $progress['last_message']));
        }

        $family_counts = $this->get_run_counts(isset($result['run_id']) ? absint($result['run_id']) : 0);
        $progress['families_created'] = $family_counts['created'];
        $progress['families_updated'] = $family_counts['updated'];
        $progress['families_skipped'] = $family_counts['skipped'];
        $progress['total_errors'] = $family_counts['failed'];
        $progress['total_families'] = $this->count_imported_families();
        $progress['phase'] = 'students';
        $progress['last_message'] = 'تم تحديث العائلات. جاري مزامنة الطلاب وبيانات السنة الدراسية...';
        if (0 === $progress['total_families']) {
            $progress['status'] = 'completed';
            $progress['phase'] = 'completed';
            $progress['completed_at'] = current_time('mysql');
        }
        $this->save_full_sync_progress($mode, $study_year, $progress);

        wp_send_json_success($this->full_sync_response_from_progress($progress, 0 === $progress['total_families'], $progress['last_message']));
    }

    public function ajax_sync_one_family() {
        $this->verify_full_sync_ajax();
        $study_year = $this->sanitize_study_year_from_request();
        $family_id = isset($_POST['family_id']) ? sanitize_text_field(wp_unslash($_POST['family_id'])) : '';
        if ('' === $family_id || '' === $study_year) {
            wp_send_json_error(array('message' => 'رقم العائلة والسنة الدراسية مطلوبان.'), 400);
        }

        $progress = $this->default_full_sync_progress('students', $study_year);
        $progress['status'] = 'running';
        $progress['phase'] = 'families';
        $progress['total_families'] = 1;
        $progress['last_family_id'] = $family_id;
        $progress['started_at'] = current_time('mysql');

        $family_result = (new Olama_Oracle_Family_Importer($this->client, $this->logger))->import_one($family_id);
        if (empty($family_result['success'])) {
            $progress['status'] = 'failed';
            $progress['last_message'] = isset($family_result['message']) ? sanitize_text_field($family_result['message']) : 'تعذر مزامنة العائلة.';
            $progress['total_errors'] = 1;
            wp_send_json_error($this->full_sync_response_from_progress($progress, false, $progress['last_message']));
        }

        $family_counts = $this->get_run_counts(isset($family_result['run_id']) ? absint($family_result['run_id']) : 0);
        $student_result = (new Olama_Oracle_Student_Importer($this->client, $this->logger))->import_family($family_id, null, $study_year);
        $summary = isset($student_result['summary']) && is_array($student_result['summary']) ? $student_result['summary'] : array();

        $progress['families_processed'] = 1;
        $progress['families_created'] = $family_counts['created'];
        $progress['families_updated'] = $family_counts['updated'];
        $progress['families_skipped'] = $family_counts['skipped'];
        $progress['total_students_inserted'] = (int) ($summary['students_created'] ?? 0);
        $progress['total_students_updated'] = (int) ($summary['students_updated'] ?? 0);
        $progress['total_students_skipped'] = (int) ($summary['students_skipped'] ?? 0);
        $progress['total_student_year_rows_inserted'] = (int) ($summary['student_years_created'] ?? 0);
        $progress['total_student_year_rows_updated'] = (int) ($summary['student_years_updated'] ?? 0);
        $progress['total_student_year_rows_skipped'] = (int) ($summary['student_years_skipped'] ?? 0);
        $progress['total_errors'] = $family_counts['failed'] + (int) ($summary['failed'] ?? 0);
        $progress['status'] = !empty($student_result['success']) ? 'completed' : 'failed';
        $progress['phase'] = $progress['status'];
        $progress['next_offset'] = 1;
        $progress['completed_at'] = current_time('mysql');
        $progress['last_message'] = !empty($student_result['success'])
            ? 'اكتملت مزامنة العائلة رقم ' . $family_id . ' بنجاح.'
            : (isset($student_result['message']) ? sanitize_text_field($student_result['message']) : 'تعذر مزامنة طلاب العائلة.');

        if ('failed' === $progress['status']) {
            wp_send_json_error($this->full_sync_response_from_progress($progress, false, $progress['last_message']));
        }
        wp_send_json_success($this->full_sync_response_from_progress($progress, true, $progress['last_message']));
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

    public function api_map() {
        $catalog = $this->api_catalog();
        $groups = array();
        foreach ($catalog as $endpoint) {
            $groups[$endpoint['group']] = $endpoint['group_label'];
        }
        $default_year = $this->get_default_study_year();
        if ('' === $default_year) {
            $default_year = '2026-2027';
        }

        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page olama-api-map-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">API Map</h1><p class="olama-oracle-page-subtitle">دليل واختبار واجهات Oracle API Bridge وربطها الحالي أو المستقبلي مع Olama Core.</p></div>';
        echo '<div class="olama-oracle-header-actions"><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-settings')) . '">Settings</a></div></div>';
        echo '<section class="olama-oracle-section olama-api-map-intro"><div><strong>Read-only diagnostics</strong><p>Tests validate connectivity and response contracts only. They never synchronize or modify Olama Core data. Oracle Sync is the sole Bridge consumer; domain plugins read the canonical Core tables.</p></div><span class="dashicons dashicons-shield-alt"></span></section>';
        echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><div><h2 class="olama-oracle-section-title">Test parameters</h2><p class="olama-oracle-section-note">Sample identifiers are used only for endpoints that require them.</p></div></div>';
        echo '<div class="olama-api-map-controls">';
        echo '<label><span>Family number</span><input id="olama-api-family-id" type="number" min="1" value="1161"></label>';
        echo '<label><span>Student number</span><input id="olama-api-student-id" type="number" min="1" value="1"></label>';
        echo '<label><span>Study year</span><input id="olama-api-study-year" type="text" value="' . esc_attr($default_year) . '"></label>';
        echo '<label><span>Search text</span><input id="olama-api-search" type="text" value="1161"></label>';
        echo '<label><span>Group</span><select id="olama-api-group-filter"><option value="">All groups</option>';
        foreach ($groups as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label></div>';
        echo '<div class="olama-api-map-actions"><button type="button" class="button olama-oracle-btn olama-oracle-btn-primary" id="olama-api-test-selected">Test selected</button><button type="button" class="button olama-oracle-btn olama-oracle-btn-secondary" id="olama-api-test-all">Test all APIs</button><button type="button" class="button olama-oracle-btn olama-oracle-btn-ghost" id="olama-api-clear">Clear results</button><span id="olama-api-map-progress" aria-live="polite"></span></div></section>';
        echo '<div class="olama-api-map-summary"><div><span>Catalogued APIs</span><strong>' . esc_html(count($catalog)) . '</strong></div><div><span>Tested</span><strong id="olama-api-tested-count">0</strong></div><div class="is-pass"><span>Passed</span><strong id="olama-api-passed-count">0</strong></div><div class="is-fail"><span>Failed</span><strong id="olama-api-failed-count">0</strong></div></div>';
        echo '<section class="olama-oracle-section"><div class="olama-api-map-table-wrap"><table class="olama-api-map-table"><thead><tr><th><input type="checkbox" id="olama-api-select-all" aria-label="Select all APIs"></th><th>Group</th><th>API endpoint</th><th>Purpose</th><th>Core mapping</th><th>Integration</th><th>Test result</th></tr></thead><tbody>';
        foreach ($catalog as $id => $endpoint) {
            echo '<tr data-api-id="' . esc_attr($id) . '" data-api-group="' . esc_attr($endpoint['group']) . '">';
            echo '<td><input type="checkbox" class="olama-api-select" value="' . esc_attr($id) . '"></td>';
            echo '<td><span class="olama-api-group">' . esc_html($endpoint['group_label']) . '</span></td>';
            echo '<td><strong>' . esc_html($endpoint['label']) . '</strong><code dir="ltr">GET ' . esc_html($endpoint['path']) . '</code></td>';
            echo '<td>' . esc_html($endpoint['purpose']) . '</td>';
            echo '<td>' . esc_html($endpoint['core_target']) . '</td>';
            echo '<td><span class="olama-api-integration is-' . esc_attr($endpoint['integration']) . '">' . esc_html($endpoint['integration_label']) . '</span></td>';
            echo '<td class="olama-api-result"><span class="olama-api-result-status is-idle">Not tested</span><small></small></td></tr>';
        }
        echo '</tbody></table></div></section></div></div>';
    }

    public function ajax_test_api_endpoint() {
        if (!current_user_can('olama_access_oracle_sync')) {
            wp_send_json_error(array('message' => 'You do not have permission to test Oracle APIs.'), 403);
        }
        if (!check_ajax_referer('olama_oracle_api_map', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Refresh the page and try again.'), 403);
        }
        $id = isset($_POST['endpoint_id']) ? sanitize_key(wp_unslash($_POST['endpoint_id'])) : '';
        $catalog = $this->api_catalog();
        if (!isset($catalog[$id])) {
            wp_send_json_error(array('message' => 'Unknown API endpoint.'), 400);
        }
        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $student_id = isset($_POST['student_id']) ? absint($_POST['student_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        if (!$family_id || !$student_id || '' === $study_year) {
            wp_send_json_error(array('message' => 'Family number, student number, and study year are required.'), 400);
        }

        $endpoint = $catalog[$id];
        $path = strtr($endpoint['path'], array('{family_id}' => rawurlencode((string) $family_id), '{student_id}' => rawurlencode((string) $student_id), '{student_key}' => rawurlencode($family_id . ':' . $student_id)));
        $params = array();
        foreach ($endpoint['params'] as $key => $source) {
            if ('study_year' === $source) {
                $params[$key] = $study_year;
            } elseif ('family_id' === $source) {
                $params[$key] = $family_id;
            } elseif ('student_id' === $source) {
                $params[$key] = $student_id;
            } elseif ('search' === $source) {
                $params[$key] = '' !== $search ? $search : (string) $family_id;
            } else {
                $params[$key] = $source;
            }
        }

        $started = microtime(true);
        $result = $this->client->get($path, $params);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        $missing = array();
        foreach ($endpoint['expected'] as $field) {
            if (!array_key_exists($field, $data)) {
                $missing[] = $field;
            }
        }
        $contract_ok = !empty($result['success']) && !$missing;
        $response_status = isset($data['status']) ? sanitize_text_field((string) $data['status']) : '';
        $api_ok = !empty($result['success']) && !in_array($response_status, array('error', 'not_found'), true);

        wp_send_json_success(array(
            'endpoint_id' => $id,
            'ok' => $api_ok && $contract_ok,
            'http_status' => isset($result['status_code']) ? (int) $result['status_code'] : 0,
            'latency_ms' => $elapsed,
            'valid_json' => !empty($result['success']),
            'contract_ok' => $contract_ok,
            'missing_fields' => $missing,
            'response_status' => $response_status,
            'record_count' => $this->api_response_count($data),
            'response_keys' => array_slice(array_keys($data), 0, 18),
            'message' => !empty($result['success']) ? 'Valid JSON response received.' : sanitize_text_field((string) ($result['message'] ?? 'Request failed.')),
            'tested_at' => current_time('mysql'),
        ));
    }

    private function api_response_count(array $data) {
        foreach (array('count', 'total') as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                return (int) $data[$field];
            }
        }
        foreach (array('families', 'students', 'employees', 'buses', 'regions', 'recipients', 'transportation', 'items', 'transactions', 'dues', 'receipts', 'payments', 'academic_history') as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                return count($data[$field]);
            }
        }
        return null;
    }

    private function api_endpoint($group, $group_label, $label, $path, $purpose, $core_target, $integration, $integration_label, $params = array(), $expected = array('status')) {
        return compact('group', 'group_label', 'label', 'path', 'purpose', 'core_target', 'integration', 'integration_label', 'params', 'expected');
    }

    private function api_catalog() {
        $e = array();
        $e['root'] = $this->api_endpoint('connection', 'Connection', 'Bridge index', '/', 'Bridge identity and endpoint index.', 'Diagnostics only', 'diagnostic', 'Diagnostic', array(), array('status', 'endpoints'));
        $e['health'] = $this->api_endpoint('connection', 'Connection', 'Oracle health', '/api/health', 'Bridge and Oracle connectivity.', 'Sync connection status', 'integrated', 'Integrated', array(), array('status', 'oracle'));

        $e['families'] = $this->api_endpoint('identity', 'Families & students', 'Families list', '/api/families', 'Active family directory.', 'Families', 'integrated', 'Integrated', array('limit' => '5', 'offset' => '0'), array('status', 'families'));
        $e['family'] = $this->api_endpoint('identity', 'Families & students', 'Family details', '/api/families/{family_id}', 'One family with students.', 'Family lookup', 'integrated', 'Integrated', array(), array('status', 'family', 'students'));
        $e['family_students'] = $this->api_endpoint('identity', 'Families & students', 'Family students', '/api/families/{family_id}/students', 'Students belonging to one family.', 'Students and academic years', 'integrated', 'Integrated', array('study_year' => 'study_year'), array('status', 'students'));
        $e['students'] = $this->api_endpoint('identity', 'Families & students', 'Students list', '/api/students', 'Active student directory.', 'Students and academic years', 'integrated', 'Integrated', array('limit' => '5', 'offset' => '0', 'study_year' => 'study_year'), array('status', 'students'));
        $e['student'] = $this->api_endpoint('identity', 'Families & students', 'Student details', '/api/students/{family_id}/{student_id}', 'One current student record.', 'Student lookup', 'planned', 'Available later', array(), array('status', 'student'));
        $e['student_search'] = $this->api_endpoint('identity', 'Families & students', 'Student search', '/api/students/search', 'Search students by name or identifier.', 'Search helper', 'integrated', 'Integrated', array('q' => 'search'), array('status', 'students'));

        $e['employees'] = $this->api_endpoint(
            'employees',
            'Employees',
            'Active employees',
            '/api/employees',
            'HR_EMP_CARD employees whose resolved Oracle status is exactly مستمر. Returns identity, contact, job, appointment, and qualification fields with read-only pagination.',
            'Future OLAMA Users employee accounts and staff identities',
            'planned',
            'API ready',
            array('limit' => '5', 'offset' => '0'),
            array('status', 'employee_status', 'count', 'limit', 'offset', 'employees')
        );

        $e['family_card'] = $this->api_endpoint('cards', 'Detailed cards', 'Family card', '/api/families/{family_id}/card', 'Detailed family, children, and academics.', 'Families, students, academic years', 'integrated', 'Integrated', array('study_year' => 'study_year'), array('status', 'family', 'students'));
        $e['student_card'] = $this->api_endpoint('cards', 'Detailed cards', 'Student card', '/api/families/{family_id}/students/{student_id}/card', 'Detailed student and academic history.', 'Student knowledge projection', 'planned', 'Available later', array('study_year' => 'study_year'), array('status', 'student', 'academic_history'));

        $e['transportation'] = $this->api_endpoint('transportation', 'Transportation', 'Family transportation', '/api/families/{family_id}/transportation', 'Transportation assignments for a family.', 'Student transportation', 'integrated', 'Integrated', array('study_year' => 'study_year'), array('status', 'transportation'));
        $e['transport_buses'] = $this->api_endpoint(
            'transportation',
            'Transportation',
            'Transportation bus master',
            '/api/transportation/buses',
            'Read-only Oracle fleet master used exclusively by Olama Oracle Sync.',
            'olama_core_transport_buses → Transportation planning projection',
            'integrated',
            'Core master feed',
            array(),
            array('status', 'count', 'buses')
        );
        $e['transport_regions'] = $this->api_endpoint(
            'transportation',
            'Transportation',
            'Transportation regions',
            '/api/transportation/regions',
            'Read-only source regions and demand counts for the selected study year.',
            'olama_core_transport_regions → major-area mapping',
            'integrated',
            'Core master feed',
            array('study_year' => 'study_year'),
            array('status', 'study_year', 'count', 'regions')
        );
        $e['transport_recipients'] = $this->api_endpoint('transportation', 'Transportation', 'Messaging transportation recipients', '/api/messaging/transportation/recipients', 'Pre-filtered messaging audience.', 'Derived locally from Core', 'projection', 'Projection only', array('study_year' => 'study_year', 'family_id' => 'family_id', 'limit' => '5', 'offset' => '0'), array('status', 'recipients'));
        $e['transport_options'] = $this->api_endpoint('transportation', 'Transportation', 'Transportation options', '/api/messaging/transportation/options', 'Bus, route, class, and section filters.', 'Derived locally from Core', 'projection', 'Projection only', array('study_year' => 'study_year'), array('status'));

        $e['financial_card'] = $this->api_endpoint('financial', 'Financial', 'Family financial card', '/api/families/{family_id}/financial-card', 'Official summary, dues, and ledger.', 'Financial summary, dues, transactions', 'integrated', 'Integrated', array('study_year' => 'study_year'), array('status', 'family_summary', 'due_allocations', 'student_transactions'));
        $e['financial_summary_message'] = $this->api_endpoint('financial', 'Financial', 'Messaging financial summary', '/api/families/{family_id}/financial-summary', 'Compact message-ready financial summary.', 'Derived from Core financial data', 'projection', 'Projection only', array('study_year' => 'study_year'), array('status'));
        $e['payment_report'] = $this->api_endpoint('financial', 'Financial', 'Messaging payment report', '/api/families/{family_id}/payment-report', 'Message-ready payment report.', 'Derived from Core financial data', 'projection', 'Projection only', array('study_year' => 'study_year'), array('family_id', 'study_year', 'students', 'financial'));
        $e['financial_recipients'] = $this->api_endpoint('financial', 'Financial', 'Messaging financial recipients', '/api/messaging/recipients', 'Pre-filtered collection audience.', 'Derived locally from Core', 'projection', 'Projection only', array('study_year' => 'study_year', 'family_id' => 'family_id', 'limit' => '5', 'offset' => '0'), array('status', 'recipients'));

        $e['financial'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Canonical family financial', '/api/families/{family_id}/financial', 'Normalized family balance contract.', 'Potential canonical summary feed', 'planned', 'Available later', array('study_year' => 'study_year'), array('family_id', 'study_year'));
        $e['balance_alias'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Balance alias', '/api/families/{family_id}/balance', 'Alias of family financial summary.', 'Do not ingest duplicate alias', 'projection', 'Alias only', array('study_year' => 'study_year'), array('family_id', 'study_year'));
        $e['financial_family_alias'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Financial family alias', '/api/financial/families/{family_id}', 'Alias of family financial summary.', 'Do not ingest duplicate alias', 'projection', 'Alias only', array('study_year' => 'study_year'), array('family_id', 'study_year'));
        $e['financial_transactions'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Financial transactions', '/api/families/{family_id}/financial-transactions', 'Normalized paginated ledger.', 'Potential canonical transaction feed', 'planned', 'Available later', array('study_year' => 'study_year', 'limit' => '20', 'offset' => '0'), array('status', 'transactions'));
        $e['transactions_alias'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Transactions alias', '/api/families/{family_id}/transactions', 'Alias of normalized ledger.', 'Do not ingest duplicate alias', 'projection', 'Alias only', array('study_year' => 'study_year', 'limit' => '20', 'offset' => '0'), array('status', 'transactions'));
        $e['dues'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Family dues', '/api/families/{family_id}/dues', 'Normalized due allocation collection.', 'Potential canonical dues feed', 'planned', 'Available later', array('study_year' => 'study_year', 'limit' => '20', 'offset' => '0'), array('status', 'dues'));
        $e['receipts'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Family receipts', '/api/families/{family_id}/receipts', 'Receipt-like credit records.', 'Derived from transactions', 'planned', 'Available later', array('study_year' => 'study_year', 'limit' => '20', 'offset' => '0'), array('status', 'receipts'));
        $e['payments'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Family payments', '/api/families/{family_id}/payments', 'Reserved payment entity; currently empty.', 'No proven Core entity', 'deferred', 'Deferred', array('study_year' => 'study_year', 'limit' => '20', 'offset' => '0'), array('status', 'payments'));
        $e['student_financial'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Student financial summary', '/api/students/{student_key}/financial-summary', 'Normalized financial summary for one student.', 'Potential student financial projection', 'planned', 'Available later', array('study_year' => 'study_year'), array('status', 'oracle_student_key', 'oracle_family_id', 'oracle_student_id', 'study_year', 'summary'));
        $e['student_financial_alias'] = $this->api_endpoint('financial_contract', 'Financial contracts', 'Student financial alias', '/api/students/{student_key}/financial', 'Alias of student financial summary.', 'Do not ingest duplicate alias', 'projection', 'Alias only', array('study_year' => 'study_year'), array('status', 'oracle_student_key', 'oracle_family_id', 'oracle_student_id', 'study_year', 'summary'));

        $e['financial_diagnostics'] = $this->api_endpoint('diagnostics', 'Diagnostics', 'Financial diagnostics', '/api/financial/diagnostics', 'Counts and readiness of finance sources.', 'Sync diagnostics only', 'diagnostic', 'Diagnostic', array('study_year' => 'study_year'), array('status', 'diagnostics', 'readiness'));
        $e['crosswalk'] = $this->api_endpoint('crosswalk', 'Student crosswalk', 'Student crosswalk', '/api/students/crosswalk', 'Canonical family/student identity mapping.', 'Existing Core identity keys', 'planned', 'Available later', array('study_year' => 'study_year', 'family_id' => 'family_id', 'student_id' => 'student_id', 'limit' => '20', 'offset' => '0'), array('status'));
        $e['crosswalk_diagnostics'] = $this->api_endpoint('crosswalk', 'Student crosswalk', 'Crosswalk diagnostics', '/api/students/crosswalk/diagnostics', 'Completeness and duplicate diagnostics.', 'Sync diagnostics only', 'diagnostic', 'Diagnostic', array('study_year' => 'study_year'), array('status'));
        $e['crosswalk_schema'] = $this->api_endpoint('crosswalk', 'Student crosswalk', 'Crosswalk schema candidates', '/api/students/crosswalk/schema-candidates', 'Candidate Oracle identity columns.', 'Design diagnostics only', 'diagnostic', 'Diagnostic', array(), array('status'));
        return $e;
    }

    public function sync_runs() {
        global $wpdb;

        $runs = $wpdb->prefix . 'olama_oracle_sync_runs';
        $items = $wpdb->prefix . 'olama_oracle_sync_items';
        $run_id = isset($_GET['run_id']) ? absint($_GET['run_id']) : 0;
        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Oracle Sync Runs</h1><p class="olama-oracle-page-subtitle">سجل عمليات المزامنة وعناصرها التفصيلية</p></div></div>';
        if ($run_id) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . esc_sql($items) . '` WHERE sync_run_id = %d ORDER BY id DESC LIMIT 500', $run_id), ARRAY_A);
            echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><h2 class="olama-oracle-section-title">Sync Items for Run #' . esc_html($run_id) . '</h2></div>';
            $this->table($rows, array('entity_type' => 'Entity Type', 'entity_uid' => 'Entity UID', 'oracle_family_id' => 'Oracle Family ID', 'oracle_student_id' => 'Oracle Student ID', 'operation' => 'Operation', 'status' => 'Status', 'message' => 'Message', 'created_at' => 'Created At'));
            echo '</section>';
        } else {
            $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($runs) . '` ORDER BY id DESC LIMIT 100', ARRAY_A);
            foreach ($rows as &$row) {
                $row['view_items'] = '<a href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-runs&run_id=' . absint($row['id']))) . '">View Items</a>';
            }
            unset($row);
            echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><h2 class="olama-oracle-section-title">آخر عمليات المزامنة</h2></div>';
            $this->table($rows, array('id' => 'Run ID', 'sync_type' => 'Sync Type', 'status' => 'Status', 'started_at' => 'Started At', 'finished_at' => 'Finished At', 'records_seen' => 'Records Seen', 'records_created' => 'Created', 'records_updated' => 'Updated', 'records_skipped' => 'Skipped', 'records_failed' => 'Failed', 'error_summary' => 'Error Summary', 'view_items' => 'View Items'), true);
            echo '</section>';
        }
        echo '</div></div>';
    }

    public function validation() {
        $report = (new Olama_Oracle_Validator())->report();
        $failed = $report['Last failed sync items'];
        unset($report['Last failed sync items']);

        echo '<div class="wrap olama-oracle-admin" dir="rtl"><div class="olama-oracle-page">';
        echo '<div class="olama-oracle-page-header"><div><h1 class="olama-oracle-page-title">Oracle Sync Validation</h1><p class="olama-oracle-page-subtitle">فحص سريع لجودة بيانات المزامنة المحلية</p></div></div>';
        $this->notice();
        echo '<section class="olama-oracle-section"><form method="post" class="olama-oracle-inline-form">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="run_validation">';
        submit_button('Run Validation Report', 'secondary olama-oracle-btn olama-oracle-btn-secondary', 'submit', false);
        echo '</form>';
        echo '<div class="olama-oracle-table-wrap"><table class="olama-oracle-table"><tbody>';
        foreach ($report as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></div></section><section class="olama-oracle-section"><div class="olama-oracle-section-header"><h2 class="olama-oracle-section-title">Last Failed Sync Items</h2></div>';
        $this->table($failed, array('entity_type' => 'Entity Type', 'entity_uid' => 'Entity UID', 'oracle_family_id' => 'Oracle Family ID', 'oracle_student_id' => 'Oracle Student ID', 'operation' => 'Operation', 'message' => 'Message', 'created_at' => 'Created At'));
        echo '</section></div></div>';
    }

    private function field($label, $key, $value, $type = 'text') {
        echo '<tr><th><label for="olama_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="olama_' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function simple_sync_panel($study_year) {
        $settings = Olama_Oracle_Settings::get();
        $configured = !empty($settings['base_url']) && !empty($settings['api_key']);

        echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><div><h2 class="olama-oracle-section-title">Employee master data</h2><p class="olama-oracle-section-note">Import Oracle employees whose status is exactly مستمر into OLAMA Core. This does not create WordPress accounts.</p></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=olama-oracle-sync')) . '">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="import_employees">';
        submit_button('Import active employees to Core', 'primary olama-oracle-btn olama-oracle-btn-primary', 'submit', false);
        echo '</form></section>';

        echo '<section class="olama-oracle-section"><div class="olama-oracle-section-header"><div><h2 class="olama-oracle-section-title">Transportation master data</h2><p class="olama-oracle-section-note">Import Oracle buses and transportation regions into Olama Core. Transportation and other domain plugins read only the canonical Core copy.</p></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=olama-oracle-sync')) . '">';
        wp_nonce_field('olama_oracle_action');
        echo '<input type="hidden" name="olama_oracle_action" value="import_transport_master">';
        echo '<input type="hidden" name="study_year" value="' . esc_attr($study_year) . '">';
        submit_button('Import transportation master to Core', 'primary olama-oracle-btn olama-oracle-btn-primary', 'submit', false);
        echo '</form></section>';

        echo '<section class="olama-oracle-connection-card ' . ($configured ? 'is-ready' : 'is-missing') . '">';
        echo '<div><span class="olama-oracle-status-dot" aria-hidden="true"></span><strong>' . esc_html($configured ? 'الاتصال مُعدّ' : 'الاتصال غير مكتمل') . '</strong><p>' . esc_html($configured ? 'Oracle Bridge جاهز للمزامنة.' : 'أدخل رابط Oracle Bridge ومفتاح API من صفحة الإعدادات.') . '</p></div>';
        echo '<a class="button olama-oracle-btn olama-oracle-btn-secondary" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync-settings')) . '">' . esc_html($configured ? 'مراجعة الاتصال' : 'إعداد الاتصال') . '</a>';
        echo '</section>';

        echo '<section class="olama-oracle-sync-actions" aria-label="خيارات المزامنة">';
        echo '<article class="olama-oracle-sync-choice olama-oracle-sync-choice-primary">';
        echo '<div class="olama-oracle-choice-icon dashicons dashicons-update-alt" aria-hidden="true"></div><div><span class="olama-oracle-eyebrow">المزامنة الرئيسية</span><h2>مزامنة جميع العائلات</h2><p>تحديث دليل العائلات، ثم الطلاب وبياناتهم للسنة الدراسية المحددة. السجلات غير المتغيرة يتم تخطي كتابتها تلقائياً.</p></div>';
        echo '<label for="olama-oracle-all-year">السنة الدراسية</label><input id="olama-oracle-all-year" type="text" value="' . esc_attr($study_year) . '" placeholder="2026-2027" data-olama-sync-year required>';
        echo '<button type="button" class="button button-primary olama-oracle-btn olama-oracle-btn-primary" data-olama-sync-all' . ($configured ? '' : ' disabled') . '><span class="dashicons dashicons-update"></span> مزامنة جميع العائلات</button>';
        echo '</article>';

        echo '<article class="olama-oracle-sync-choice">';
        echo '<div class="olama-oracle-choice-icon dashicons dashicons-admin-users" aria-hidden="true"></div><div><span class="olama-oracle-eyebrow">تحديث محدد</span><h2>مزامنة عائلة واحدة</h2><p>استخدم رقم العائلة في Oracle لتحديث العائلة وأبنائها فقط، دون تشغيل المزامنة الشاملة.</p></div>';
        echo '<div class="olama-oracle-single-fields"><label for="olama-oracle-family-id">رقم العائلة في Oracle</label><input id="olama-oracle-family-id" type="text" inputmode="numeric" placeholder="FAMILY_ID" data-olama-family-id><label for="olama-oracle-single-year">السنة الدراسية</label><input id="olama-oracle-single-year" type="text" value="' . esc_attr($study_year) . '" placeholder="2026-2027" data-olama-single-year></div>';
        echo '<button type="button" class="button olama-oracle-btn olama-oracle-btn-secondary" data-olama-sync-one' . ($configured ? '' : ' disabled') . '><span class="dashicons dashicons-update"></span> مزامنة العائلة</button>';
        echo '</article>';
        echo '</section>';

        echo '<section class="olama-oracle-progress-card" data-olama-progress-card aria-live="polite">';
        echo '<div class="olama-oracle-progress-heading"><div><span class="olama-oracle-eyebrow">حالة العملية</span><h2 data-olama-progress-title>جاهز للمزامنة</h2><p data-olama-full-sync-message>اختر نوع المزامنة للبدء.</p></div><strong class="olama-oracle-progress-percent" data-olama-progress-percent>0%</strong></div>';
        echo '<div class="olama-oracle-progress"><div class="olama-oracle-progress-bar" data-olama-full-sync-bar></div></div>';
        echo '<div class="olama-oracle-phase-list"><span data-phase="families">1 <b>العائلات</b></span><span data-phase="students">2 <b>الطلاب والسنوات</b></span><span data-phase="completed">3 <b>الاكتمال</b></span></div>';
        echo '<div class="olama-oracle-result-grid">';
        foreach (array(
            'families_processed' => 'العائلات المعالجة',
            'students_changed' => 'الطلاب المضافون/المحدثون',
            'student_years_changed' => 'سجلات السنوات المضافة/المحدثة',
            'unchanged' => 'بدون تغيير',
            'errors' => 'الأخطاء',
        ) as $key => $label) {
            echo '<div><span>' . esc_html($label) . '</span><strong data-olama-full-sync-field="' . esc_attr($key) . '">0</strong></div>';
        }
        echo '</div>';
        echo '<div class="olama-oracle-progress-footer"><span data-olama-last-family></span><div data-olama-full-sync-links hidden><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="' . esc_url(admin_url('admin.php?page=olama-core')) . '">فتح Olama Core</a><a class="button olama-oracle-btn olama-oracle-btn-ghost" href="#olama-oracle-validation">التحقق من البيانات</a></div></div>';
        echo '</section>';
    }

    private function dashboard_validation_card() {
        $report = (new Olama_Oracle_Validator())->report();
        $failed_items = isset($report['Last failed sync items']) && is_array($report['Last failed sync items']) ? $report['Last failed sync items'] : array();
        unset($report['Last failed sync items']);

        $families = (int) ($report['Core families count'] ?? 0);
        $students = (int) ($report['Core students count'] ?? 0);
        $student_years = (int) ($report['Core student years count'] ?? 0);
        $relationship_issues = (int) ($report['Students without matching family'] ?? 0) + (int) ($report['Student years without matching student'] ?? 0);
        $duplicates = (int) ($report['Duplicate Oracle family IDs'] ?? 0) + (int) ($report['Duplicate Oracle student keys'] ?? 0);
        $incomplete = (int) ($report['Missing student names'] ?? 0) + (int) ($report['Missing class/section for current study year'] ?? 0);
        $issue_total = $relationship_issues + $duplicates + $incomplete;

        echo '<section id="olama-oracle-validation" class="olama-oracle-dashboard-card olama-oracle-validation-card">';
        echo '<div class="olama-oracle-section-header"><div><span class="olama-oracle-eyebrow">جودة البيانات</span><h2 class="olama-oracle-section-title">التحقق من بيانات Olama Core</h2><p class="olama-oracle-section-note">فحص العلاقات والتكرار والحقول الأساسية بعد المزامنة.</p></div>';
        echo '<a class="button olama-oracle-btn olama-oracle-btn-secondary" href="' . esc_url(admin_url('admin.php?page=olama-oracle-sync#olama-oracle-validation')) . '"><span class="dashicons dashicons-update"></span> تحديث الفحص</a></div>';
        echo '<div class="olama-oracle-validation-summary ' . (0 === $issue_total ? 'is-healthy' : 'has-warnings') . '"><span class="dashicons ' . (0 === $issue_total ? 'dashicons-yes-alt' : 'dashicons-warning') . '"></span><div><strong>' . esc_html(0 === $issue_total ? 'البيانات الأساسية سليمة' : number_format_i18n($issue_total) . ' ملاحظة تحتاج للمراجعة') . '</strong><p>' . esc_html(0 === $issue_total ? 'لم يكتشف الفحص مشاكل في العلاقات أو التكرار أو الحقول الأساسية.' : 'المزامنة تعمل، لكن بعض السجلات المحلية تحتاج إلى مراجعة أو استكمال.') . '</p></div></div>';
        echo '<div class="olama-oracle-validation-grid">';
        foreach (array(
            array('label' => 'العائلات', 'value' => $families, 'state' => ''),
            array('label' => 'الطلاب', 'value' => $students, 'state' => ''),
            array('label' => 'سجلات السنوات', 'value' => $student_years, 'state' => ''),
            array('label' => 'مشاكل العلاقات', 'value' => $relationship_issues, 'state' => $relationship_issues ? 'has-issue' : 'is-good'),
            array('label' => 'السجلات المكررة', 'value' => $duplicates, 'state' => $duplicates ? 'has-issue' : 'is-good'),
            array('label' => 'بيانات غير مكتملة', 'value' => $incomplete, 'state' => $incomplete ? 'has-warning' : 'is-good'),
        ) as $metric) {
            echo '<div class="' . esc_attr($metric['state']) . '"><span>' . esc_html($metric['label']) . '</span><strong>' . esc_html(number_format_i18n($metric['value'])) . '</strong></div>';
        }
        echo '</div>';
        if ($failed_items) {
            echo '<p class="olama-oracle-validation-note"><span class="dashicons dashicons-info-outline"></span> يوجد ' . esc_html(number_format_i18n(count($failed_items))) . ' عنصر فشل حديثاً في سجل المزامنة.</p>';
        }
        echo '</section>';
    }

    private function dashboard_recent_runs() {
        global $wpdb;

        $runs_table = $wpdb->prefix . 'olama_oracle_sync_runs';
        $rows = $wpdb->get_results(
            "SELECT * FROM `" . esc_sql($runs_table) . "` WHERE sync_type NOT IN ('validation', 'connection_test') ORDER BY id DESC LIMIT 10",
            ARRAY_A
        );

        echo '<section id="olama-oracle-recent-runs" class="olama-oracle-dashboard-card olama-oracle-runs-card">';
        echo '<div class="olama-oracle-section-header"><div><span class="olama-oracle-eyebrow">سجل النشاط</span><h2 class="olama-oracle-section-title">آخر 10 عمليات مزامنة</h2><p class="olama-oracle-section-note">ملخص النتيجة والمدة والتغييرات التي نفذتها كل عملية.</p></div></div>';
        echo '<div class="olama-oracle-table-wrap"><table class="olama-oracle-table olama-oracle-runs-table"><thead><tr><th>العملية</th><th>النوع</th><th>الحالة</th><th>وقت البدء</th><th>المدة</th><th>النتيجة</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="6" class="olama-oracle-empty-state">لا توجد عمليات مزامنة حتى الآن.</td></tr>';
        }
        foreach ($rows as $row) {
            $status = sanitize_key((string) ($row['status'] ?? ''));
            $status_labels = array(
                'running' => 'قيد التشغيل',
                'completed' => 'مكتملة',
                'completed_with_errors' => 'مكتملة مع أخطاء',
                'failed' => 'فشلت',
                'paused' => 'متوقفة',
            );
            $type_labels = array(
                'families' => 'العائلات',
                'family' => 'عائلة واحدة',
                'family_students' => 'طلاب عائلة',
                'all_students' => 'الطلاب والسنوات',
                'students_by_study_year' => 'طلاب السنة',
            );
            $created = (int) ($row['records_created'] ?? 0);
            $updated = (int) ($row['records_updated'] ?? 0);
            $skipped = (int) ($row['records_skipped'] ?? 0);
            $failed = (int) ($row['records_failed'] ?? 0);
            $started = !empty($row['started_at']) ? mysql2date('Y-m-d H:i', $row['started_at']) : '—';
            $duration = $this->format_run_duration($row['started_at'] ?? '', $row['finished_at'] ?? '');

            echo '<tr>';
            echo '<td><strong>#' . esc_html((int) $row['id']) . '</strong></td>';
            echo '<td>' . esc_html($type_labels[$row['sync_type']] ?? $row['sync_type']) . '</td>';
            echo '<td><span class="olama-oracle-status-pill status-' . esc_attr($status) . '">' . esc_html($status_labels[$status] ?? $status) . '</span></td>';
            echo '<td>' . esc_html($started) . '</td>';
            echo '<td>' . esc_html($duration) . '</td>';
            echo '<td><div class="olama-oracle-run-result"><span class="is-created">+' . esc_html($created) . ' مضاف</span><span class="is-updated">' . esc_html($updated) . ' محدث</span><span class="is-skipped">' . esc_html($skipped) . ' بدون تغيير</span>' . ($failed ? '<span class="is-failed">' . esc_html($failed) . ' فشل</span>' : '') . '</div></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function format_run_duration($started_at, $finished_at) {
        if (!$started_at) {
            return '—';
        }

        $start = strtotime($started_at);
        $end = $finished_at ? strtotime($finished_at) : current_time('timestamp');
        if (!$start || !$end || $end < $start) {
            return '—';
        }

        $seconds = $end - $start;
        if ($seconds < 60) {
            return $seconds . ' ث';
        }

        return floor($seconds / 60) . ' د ' . ($seconds % 60) . ' ث';
    }

    private function full_sync_panel($study_year) {
        $configured_limit = max(1, min(100, absint(Olama_Oracle_Settings::get('batch_size'))));
        if (!$configured_limit) {
            $configured_limit = 25;
        }
        echo '<section id="olama-oracle-full-sync" style="background:#fff;border:1px solid #ccd0d4;padding:16px;">';
        echo '<div class="olama-oracle-section-header olama-oracle-full-sync-heading"><div><h2 class="olama-oracle-section-title">مزامنة كاملة تلقائية</h2><p class="olama-oracle-section-note">تشغيل مزامنة كاملة على دفعات آمنة بدون الحاجة إلى إدخال offset يدوياً.</p></div></div>';
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
        $progress['total_students_skipped'] = isset($progress['total_students_skipped']) ? (int) $progress['total_students_skipped'] + (int) ($summary['students_skipped'] ?? 0) : (int) ($summary['students_skipped'] ?? 0);
        $progress['total_student_year_rows_inserted'] = isset($progress['total_student_year_rows_inserted']) ? (int) $progress['total_student_year_rows_inserted'] + (int) ($summary['student_years_created'] ?? 0) : (int) ($summary['student_years_created'] ?? 0);
        $progress['total_student_year_rows_updated'] = isset($progress['total_student_year_rows_updated']) ? (int) $progress['total_student_year_rows_updated'] + (int) ($summary['student_years_updated'] ?? 0) : (int) ($summary['student_years_updated'] ?? 0);
        $progress['total_student_year_rows_skipped'] = isset($progress['total_student_year_rows_skipped']) ? (int) $progress['total_student_year_rows_skipped'] + (int) ($summary['student_years_skipped'] ?? 0) : (int) ($summary['student_years_skipped'] ?? 0);
        $progress['total_errors'] = isset($progress['total_errors']) ? (int) $progress['total_errors'] + (int) ($summary['failed'] ?? 0) : (int) ($summary['failed'] ?? 0);
        $progress['last_family_id'] = isset($summary['last_family_id']) ? sanitize_text_field($summary['last_family_id']) : ($progress['last_family_id'] ?? '');
        $progress['status'] = $done ? 'completed' : 'running';
        $progress['phase'] = $done ? 'completed' : 'students';
        $progress['last_message'] = isset($result['message']) ? sanitize_text_field($result['message']) : '';
        if ($done) {
            $progress['completed_at'] = current_time('mysql');
        }
        $this->save_full_sync_progress($mode, $study_year, $progress);

        wp_send_json_success($this->full_sync_response_from_progress($progress, $done, $progress['last_message']));
    }

    private function verify_full_sync_ajax() {
        if (!current_user_can('olama_access_oracle_sync')) {
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
            'families_created' => 0,
            'families_updated' => 0,
            'families_skipped' => 0,
            'total_students_inserted' => 0,
            'total_students_updated' => 0,
            'total_students_skipped' => 0,
            'total_student_year_rows_inserted' => 0,
            'total_student_year_rows_updated' => 0,
            'total_student_year_rows_skipped' => 0,
            'total_errors' => 0,
            'last_family_id' => '',
            'last_error_message' => '',
            'last_message' => '',
            'started_at' => '',
            'last_run_at' => '',
            'completed_at' => '',
            'status' => 'paused',
            'phase' => 'ready',
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
            'phase' => $progress['phase'] ?? 'ready',
            'progress_percentage' => $percentage,
            'last_family_id' => $progress['last_family_id'] ?? '',
            'families_created' => (int) ($progress['families_created'] ?? 0),
            'families_updated' => (int) ($progress['families_updated'] ?? 0),
            'families_skipped' => (int) ($progress['families_skipped'] ?? 0),
            'students_skipped' => (int) ($progress['total_students_skipped'] ?? 0),
            'student_year_rows_skipped' => (int) ($progress['total_student_year_rows_skipped'] ?? 0),
            'done' => (bool) $done,
            'message' => $message,
        );
    }

    private function count_imported_families() {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_families';
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`');
    }

    private function get_run_counts($run_id) {
        global $wpdb;

        if (!$run_id) {
            return array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);
        }

        $table = $wpdb->prefix . 'olama_oracle_sync_runs';
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT records_created, records_updated, records_skipped, records_failed FROM `' . esc_sql($table) . '` WHERE id = %d',
            $run_id
        ), ARRAY_A);

        return array(
            'created' => (int) ($row['records_created'] ?? 0),
            'updated' => (int) ($row['records_updated'] ?? 0),
            'skipped' => (int) ($row['records_skipped'] ?? 0),
            'failed' => (int) ($row['records_failed'] ?? 0),
        );
    }

    private function action_form($label, $action, $needs_family = false, $has_offset = false, $has_study_year = false, $study_year = '') {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'olama-oracle-sync-manual';
        echo '<form method="post" action="' . esc_url(add_query_arg(array('page' => $page), admin_url('admin.php'))) . '" class="olama-oracle-action-card">';
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
        submit_button($label, 'primary olama-oracle-btn olama-oracle-btn-primary', 'submit', false);
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
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible olama-oracle-notice"><p>' . esc_html(wp_unslash($_GET['olama_message'])) . '</p></div>';
    }

    private function table($rows, $columns, $allow_html = false) {
        echo '<div class="olama-oracle-table-wrap"><table class="olama-oracle-table"><thead><tr>';
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
        echo '</tbody></table></div>';
    }
}
