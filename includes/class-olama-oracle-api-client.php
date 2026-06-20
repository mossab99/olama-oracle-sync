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

    public function health() {
        return $this->request('GET', '/api/health');
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

    public function get_students($params = array()) {
        return $this->request('GET', '/api/students', $params);
    }

    public function search_students($term) {
        return $this->request('GET', '/api/students/search', array('q' => $term));
    }

    public function get($path, $params = array()) {
        return $this->request('GET', $path, $params);
    }

    private function request($method, $path, $params = array()) {
        if (!$this->base_url) {
            return array('success' => false, 'status_code' => 0, 'data' => null, 'message' => 'Oracle Bridge Base URL is not configured.');
        }

        $url = $this->base_url . $path;
        if ($params && strtoupper($method) === 'GET') {
            $url = add_query_arg(array_map('sanitize_text_field', $params), $url);
        }

        $args = array(
            'timeout' => max(1, absint($this->timeout)),
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
}
