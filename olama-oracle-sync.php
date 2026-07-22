<?php
/**
 * Plugin Name: Olama Oracle Sync
 * Description: Standalone Oracle bridge sync plugin for importing Oracle families and students into Olama Core.
 * Version: 0.4.1
 * Author: Olama
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_ORACLE_SYNC_VERSION', '0.4.1');
define('OLAMA_ORACLE_SYNC_FILE', __FILE__);
define('OLAMA_ORACLE_SYNC_PATH', plugin_dir_path(__FILE__));
define('OLAMA_ORACLE_SYNC_URL', plugin_dir_url(__FILE__));

require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-migrator.php';

register_activation_hook(__FILE__, array('Olama_Oracle_Migrator', 'activate'));

add_action('plugins_loaded', 'olama_oracle_sync_bootstrap', 20);

function olama_oracle_sync_get_api_config() {
    if (!class_exists('Olama_Oracle_Settings')) {
        require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-settings.php';
    }

    return Olama_Oracle_Settings::get();
}

function olama_oracle_sync_api_get($path, $query_args = array()) {
    if (!class_exists('Olama_Oracle_Settings')) {
        require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-settings.php';
    }
    if (!class_exists('Olama_Oracle_Api_Client')) {
        require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-api-client.php';
    }

    $path = '/' . ltrim((string) $path, '/');
    $client = new Olama_Oracle_Api_Client();

    return $client->get($path, is_array($query_args) ? $query_args : array());
}

/**
 * Refresh one family and every Core-owned domain attached to it.
 *
 * Olama Core deliberately does not call Oracle endpoints itself. This command is
 * exposed by the ingestion plugin so Core screens can request a refresh and then
 * continue reading the canonical local tables.
 */
function olama_oracle_sync_refresh_family($oracle_family_id, $study_year = '') {
    $oracle_family_id = absint($oracle_family_id);
    if ($oracle_family_id <= 0 || !function_exists('olama_core')) {
        return array('success' => false, 'message' => 'A valid family ID and Olama Core are required.');
    }

    foreach (array(
        'Olama_Oracle_Settings' => 'includes/class-olama-oracle-settings.php',
        'Olama_Oracle_Api_Client' => 'includes/class-olama-oracle-api-client.php',
        'Olama_Oracle_Sync_Logger' => 'includes/class-olama-oracle-sync-logger.php',
        'Olama_Oracle_Family_Importer' => 'includes/class-olama-oracle-family-importer.php',
        'Olama_Oracle_Student_Importer' => 'includes/class-olama-oracle-student-importer.php',
    ) as $class_name => $relative_file) {
        if (!class_exists($class_name)) {
            require_once OLAMA_ORACLE_SYNC_PATH . $relative_file;
        }
    }

    $client = new Olama_Oracle_Api_Client();
    $logger = new Olama_Oracle_Sync_Logger();
    $run_id = $logger->start_run('family_refresh');
    $family_result = (new Olama_Oracle_Family_Importer($client, $logger))->import_one($oracle_family_id, $run_id);

    if (empty($family_result['success'])) {
        $logger->finish_run($run_id, 'failed', isset($family_result['message']) ? $family_result['message'] : 'Family refresh failed.');
        return $family_result;
    }

    $result = (new Olama_Oracle_Student_Importer($client, $logger))->import_family($oracle_family_id, $run_id, $study_year);
    $logger->finish_run($run_id, empty($result['success']) ? 'completed_with_errors' : 'completed', empty($result['message']) ? '' : $result['message']);
    $result['run_id'] = $run_id;

    return $result;
}

function olama_oracle_sync_bootstrap() {
    if (!defined('OLAMA_CORE_VERSION') || !function_exists('olama_core')) {
        add_action('admin_notices', 'olama_oracle_sync_core_missing_notice');
        return;
    }

    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-settings.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-api-client.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-sync-logger.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-family-importer.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-employee-importer.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-academic-importer.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-transport-master-importer.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-student-importer.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-validator.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'admin/class-olama-oracle-admin.php';

    if (is_admin()) {
        $admin = new Olama_Oracle_Admin();
        $admin->init();
    }
}

function olama_oracle_sync_core_missing_notice() {
    echo '<div class="notice notice-error"><p>Olama Oracle Sync requires Olama Core to be active. Activate Olama Core first.</p></div>';
}
