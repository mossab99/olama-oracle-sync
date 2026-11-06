<?php

require __DIR__ . '/test-year-boundary.php';
require dirname(__DIR__) . '/admin/class-olama-oracle-admin.php';

function add_query_arg($params, $url) { return $url . '?' . http_build_query($params); }
function current_user_can($capability) { return true; }
function check_ajax_referer($action, $key) {}
function current_time($type) { return '2026-11-07 12:00:00'; }
function wp_remote_get($url, $args) {
    $GLOBALS['last_request'] = array('url' => $url, 'args' => $args);
    return !empty($GLOBALS['probe_timeout'])
        ? new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 5002 milliseconds with 0 bytes received')
        : array('code' => 200, 'body' => '{"status":"ok","oracle":"connected"}');
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_send_json_success($data) { throw new Oracle_Connection_Test_Response($data); }

class Olama_Oracle_Settings {
    public static function get($key = null) {
        $settings = array('base_url' => 'https://example.test', 'api_key' => 'test-key', 'request_timeout' => 5);
        return $key === null ? $settings : $settings[$key];
    }
}
class Oracle_Connection_Test_Response extends RuntimeException {
    public $data;
    public function __construct($data) { $this->data = $data; }
}
class Oracle_Connection_Test_Jobs {
    public $failed = false;
    public function fail_active_job_for_disconnect($message) { $this->failed = true; return 50; }
}
function connection_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

// Existing five-second settings must not truncate data queries.
$client = new Olama_Oracle_Api_Client();
$client->get_families();
connection_assert($GLOBALS['last_request']['args']['timeout'] === 30, 'Data request still uses the five-second timeout.');
$client->get_fast_sync_batch('2026-2027', 25, 50);
connection_assert($GLOBALS['last_request']['args']['timeout'] === 60, 'Bulk request needs its longer timeout.');
connection_assert(strpos($GLOBALS['last_request']['url'], 'cursor=50') !== false, 'Bulk cursor was lost.');
connection_assert($GLOBALS['last_request']['args']['headers']['X-API-Key'] === 'test-key', 'Authentication was lost.');
$client->health(5);
connection_assert($GLOBALS['last_request']['args']['timeout'] === 5, 'Explicit short probes should remain short.');

$long_client = new Olama_Oracle_Api_Client('https://example.test', 'test-key', 120);
$long_client->get_families();
connection_assert($GLOBALS['last_request']['args']['timeout'] === 120, 'Longer data timeout was ignored.');
$long_client->get_fast_sync_batch('2026-2027');
connection_assert($GLOBALS['last_request']['args']['timeout'] === 120, 'Longer bulk timeout was ignored.');

// A timed-out monitor request must report offline without failing a worker.
$admin = (new ReflectionClass('Olama_Oracle_Admin'))->newInstanceWithoutConstructor();
$jobs = new Oracle_Connection_Test_Jobs();
foreach (array('client' => $client, 'jobs' => $jobs) as $name => $value) {
    $property = new ReflectionProperty('Olama_Oracle_Admin', $name);
    $property->setValue($admin, $value);
}
foreach (array(true, false) as $timeout) {
    $GLOBALS['probe_timeout'] = $timeout;
    try {
        $admin->ajax_bridge_status();
        throw new RuntimeException('Expected a JSON response.');
    } catch (Oracle_Connection_Test_Response $response) {
        connection_assert($response->data['connected'] === !$timeout, 'Monitor returned the wrong connection status.');
        connection_assert($response->data['failed_job_id'] === 0 && !$jobs->failed, 'Monitor terminated an active job.');
    }
}

echo "Oracle connection timeouts and non-destructive monitoring: PASS\n";
