<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Api_Client {
    private $base_url;
    private $api_key;
    private $timeout;

    public function __construct($base_url = null, $api_key = null, $timeout = null) {
        $this->base_url = untrailingslashit($base_url ?: Olama_Oracle_Settings::get('base_url'));
        $this->api_key = $api_key ?: Olama_Oracle_Settings::get('api_key');
        $this->timeout = $timeout ?: Olama_Oracle_Settings::get('request_timeout');
    }

    public function health($timeout = null) {
        return $this->request('GET', '/api/health', array(), $timeout);
    }

    public function get_families($params = array()) {
        return $this->request('GET', '/api/families', $params);
    }

    public function get_family($oracle_family_id) {
        return $this->request('GET', '/api/families/' . rawurlencode($oracle_family_id));
    }

    public function get_family_card($oracle_family_id, $params = array()) {
        return $this->request('GET', '/api/families/' . rawurlencode($oracle_family_id) . '/card', $params);
    }

    public function get_family_students($oracle_family_id, $params = array()) {
        return $this->request('GET', '/api/families/' . rawurlencode($oracle_family_id) . '/students', $params);
    }

    public function get_family_financial_card($oracle_family_id, $params = array()) {
        return $this->request('GET', '/api/families/' . rawurlencode($oracle_family_id) . '/financial-card', $params);
    }

    public function get_family_transportation($oracle_family_id, $params = array()) {
        return $this->request('GET', '/api/families/' . rawurlencode($oracle_family_id) . '/transportation', $params);
    }

    public function get_fast_sync_batch($study_year, $limit = 50, $cursor = 0) {
        return $this->request('GET', '/api/v1/sync/families-bulk', array(
            'study_year' => $study_year,
            'limit' => max(1, min(100, absint($limit))),
            'cursor' => max(0, absint($cursor)),
        ));
    }

    public function get_transportation_buses() {
        return $this->request('GET', '/api/transportation/buses', array('include_inactive' => 1));
    }

    public function get_transportation_regions($study_year) {
        return $this->request('GET', '/api/transportation/regions', array(
            'study_year' => $study_year,
            // Some Oracle Bridge versions apply active_only inconsistently and
            // omit valid active regions (for example, region 15). Fetch the
            // complete master list and let the importer apply the Oracle
            // status filter consistently before writing to Olama Core.
            'active_only' => 0,
            'include_inactive' => 1,
        ));
    }

    public function get_transportation_family_locations($limit = 500, $offset = 0) {
        return $this->request('GET', '/api/transportation/family-locations', array(
            'limit' => max(1, min(1000, absint($limit))),
            'offset' => max(0, absint($offset)),
        ));
    }

    public function get_students($params = array()) {
        return $this->request('GET', '/api/students', $params);
    }

    public function search_students($term) {
        return $this->request('GET', '/api/students/search', array('q' => $term));
    }

    public function get_transferred_students($study_year) {
        return $this->request('GET', '/api/academic/transferred-students', array('study_year' => $study_year));
    }

    public function get($path, $params = array()) {
        return $this->request('GET', $path, $params);
    }
    private function request($method, $path, $params = array(), $timeout = null) {
        if (!$this->base_url) {
            return array('success' => false, 'status_code' => 0, 'data' => null, 'message' => 'Oracle Bridge Base URL is not configured.');
        }

        $url = $this->base_url . $path;
        $params = $this->translate_study_year_params($params);
        if (is_wp_error($params)) {
            return array('success' => false, 'status_code' => 0, 'data' => null, 'message' => $params->get_error_message());
        }
        if ($params && strtoupper($method) === 'GET') {
            $url = add_query_arg(array_map('sanitize_text_field', $params), $url);
        }

        $args = array(
            'timeout' => max(1, absint(null === $timeout ? $this->timeout : $timeout)),
            'headers' => array(
                'X-API-Key' => $this->api_key,
                'Accept' => 'application/json',
            ),
        );

        $response = strtoupper($method) === 'POST' ? wp_remote_post($url, $args) : wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return array('success' => false, 'status_code' => 0, 'data' => null, 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return array('success' => false, 'status_code' => $code, 'data' => null, 'message' => 'HTTP error ' . $code . ': ' . wp_strip_all_tags($body));
        }
        if ('' === trim($body)) {
            return array('success' => false, 'status_code' => $code, 'data' => null, 'message' => 'Empty response from Oracle bridge.');
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'status_code' => $code, 'data' => null, 'message' => 'Invalid JSON: ' . json_last_error_msg());
        }

        return array('success' => true, 'status_code' => $code, 'data' => $data, 'message' => 'OK');
    }

    private function translate_study_year_params(array $params) {
        if (!array_key_exists('study_year', $params) || trim((string) $params['study_year']) === '') {
            return $params;
        }
        if (!function_exists('olama_core') || !method_exists(olama_core(), 'academic_calendar')) {
            return new WP_Error('oracle_year_core_unavailable', 'Olama Core academic calendar is required to resolve the Oracle study year.');
        }
        $calendar = olama_core()->academic_calendar();
        $year = $calendar->resolve_external_year('oracle', $params['study_year']);
        if (!$year) {
            return new WP_Error('oracle_year_unmapped', 'The requested study year is not defined in Olama Core.');
        }
        $external = $calendar->external_year_code((int) $year->id, 'oracle');
        if ($external === '') {
            return new WP_Error('oracle_year_mapping_missing', 'The Oracle study-year mapping is empty in Olama Core.');
        }
        $params['study_year'] = $external;
        return $params;
    }
}
