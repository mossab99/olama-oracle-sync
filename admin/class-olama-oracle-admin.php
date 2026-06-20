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

    private function action_form($label, $action, $needs_family = false, $has_offset = false, $has_study_year = false, $study_year = '') {
        echo '<form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:14px;">';
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
