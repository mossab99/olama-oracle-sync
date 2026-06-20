<?php
/**
 * Plugin Name: Olama Oracle Sync
 * Description: Standalone Oracle bridge sync plugin for importing Oracle families and students into Olama Core.
 * Version: 0.1.0
 * Author: Olama
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_ORACLE_SYNC_VERSION', '0.1.0');
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

function olama_oracle_sync_bootstrap() {
    if (!defined('OLAMA_CORE_VERSION') || !function_exists('olama_core')) {
        add_action('admin_notices', 'olama_oracle_sync_core_missing_notice');
        return;
    }

    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-settings.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-api-client.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-sync-logger.php';
    require_once OLAMA_ORACLE_SYNC_PATH . 'includes/class-olama-oracle-family-importer.php';
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
