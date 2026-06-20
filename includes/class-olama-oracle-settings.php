<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Settings {
    public static function get($key = null) {
        $settings = wp_parse_args(get_option('olama_oracle_sync_settings', array()), Olama_Oracle_Migrator::default_settings());
        if ($key) {
            return isset($settings[$key]) ? $settings[$key] : null;
        }

        return $settings;
    }

    public static function update($input) {
        $settings = array(
            'base_url' => isset($input['base_url']) ? esc_url_raw(trim($input['base_url'])) : '',
            'api_key' => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
            'default_study_year' => isset($input['default_study_year']) ? sanitize_text_field($input['default_study_year']) : '',
            'request_timeout' => isset($input['request_timeout']) ? max(1, absint($input['request_timeout'])) : 30,
            'batch_size' => isset($input['batch_size']) ? max(1, min(1000, absint($input['batch_size']))) : 100,
            'store_raw_payloads' => isset($input['store_raw_payloads']) && $input['store_raw_payloads'] === 'yes' ? 'yes' : 'no',
            'sync_mode' => isset($input['sync_mode']) && $input['sync_mode'] === 'scheduled_read_only' ? 'scheduled_read_only' : 'manual',
        );
        update_option('olama_oracle_sync_settings', $settings);

        return $settings;
    }
}
