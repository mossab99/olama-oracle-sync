<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Settings {
    public static function get($key = null) {
        $settings = wp_parse_args(get_option('olama_oracle_sync_settings', array()), Olama_Oracle_Migrator::default_settings());
        if ('scheduled_read_only' === $settings['sync_mode']) {
            $settings['sync_mode'] = 'scheduled';
        }
        if ('default_study_year' === $key && function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
            $year = olama_core()->academic_context()->current_year();
            return $year ? (string) (!empty($year->code) ? $year->code : $year->year_name) : '';
        }
        if ($key) {
            return isset($settings[$key]) ? $settings[$key] : null;
        }

        return $settings;
    }

    public static function update($input) {
        $existing = wp_parse_args(get_option('olama_oracle_sync_settings', array()), Olama_Oracle_Migrator::default_settings());
        $settings = array(
            'base_url' => isset($input['base_url']) ? esc_url_raw(trim($input['base_url'])) : '',
            'api_key' => isset($input['api_key']) && '' !== trim((string) $input['api_key'])
                ? sanitize_text_field($input['api_key'])
                : sanitize_text_field((string) $existing['api_key']),
            // Retained in the option for downgrade compatibility. Runtime reads
            // always use the Core-owned active academic year.
            'default_study_year' => isset($input['default_study_year'])
                ? sanitize_text_field($input['default_study_year'])
                : sanitize_text_field((string) $existing['default_study_year']),
            'request_timeout' => isset($input['request_timeout']) ? max(1, absint($input['request_timeout'])) : 30,
            'batch_size' => isset($input['batch_size']) ? max(1, min(1000, absint($input['batch_size']))) : 100,
            'store_raw_payloads' => isset($input['store_raw_payloads']) && $input['store_raw_payloads'] === 'yes' ? 'yes' : 'no',
            'raw_payload_retention_days' => isset($input['raw_payload_retention_days']) ? max(1, min(365, absint($input['raw_payload_retention_days']))) : 7,
            'sync_mode' => isset($input['sync_mode']) && in_array($input['sync_mode'], array('scheduled_read_only', 'scheduled'), true) ? 'scheduled' : 'manual',
            'schedule_frequency' => isset($input['schedule_frequency']) && in_array($input['schedule_frequency'], array('hourly', 'twicedaily', 'daily'), true) ? $input['schedule_frequency'] : 'daily',
        );
        update_option('olama_oracle_sync_settings', $settings, false);
        do_action('olama_oracle_settings_updated', $settings);

        return $settings;
    }
}
