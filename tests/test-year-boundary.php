<?php

define('ABSPATH', __DIR__);

class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function sanitize_text_field($value) { return trim((string) $value); }

class Olama_Oracle_Year_Test_Calendar {
    public function resolve_external_year($source, $value) {
        $normalized = str_replace('/', '-', (string) $value);
        if ($normalized === '2025-2026') {
            return (object) array('id' => 1);
        }
        if ($normalized === '2026-2027') {
            return (object) array('id' => 2);
        }
        return null;
    }

    public function external_year_code($year_id, $source) {
        return (int) $year_id === 1 ? '2025/2026' : '2026-2027';
    }
}

class Olama_Oracle_Year_Test_Core {
    public function academic_calendar() { return new Olama_Oracle_Year_Test_Calendar(); }
}

function olama_core() {
    static $core;
    return $core ?: ($core = new Olama_Oracle_Year_Test_Core());
}

require dirname(__DIR__) . '/includes/class-olama-oracle-api-client.php';

$client = new Olama_Oracle_Api_Client('https://example.test', 'test-key', 30);
$method = new ReflectionMethod($client, 'translate_study_year_params');
$method->setAccessible(true);
$old = $method->invoke($client, array('study_year' => '2025-2026'));
$current = $method->invoke($client, array('study_year' => '2026-2027'));
$unknown = $method->invoke($client, array('study_year' => '2030-2031'));

if ($old['study_year'] !== '2025/2026' || $current['study_year'] !== '2026-2027' || !is_wp_error($unknown)) {
    fwrite(STDERR, "Oracle year boundary translation: FAIL\n");
    exit(1);
}

echo "Oracle year boundary translation: PASS\n";
