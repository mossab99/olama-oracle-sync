<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function current_time($type) { return '2026-09-25 12:00:00'; }
function wp_json_encode($value) { return json_encode($value); }
function absint($value) { return abs((int) $value); }

class Olama_Oracle_Sync_Logger {
    public $finished = array();
    public function finish_run($id, $status = 'completed', $message = '') { $this->finished[] = $id; }
}
class Olama_Oracle_Api_Client {}
class Olama_Oracle_Student_Importer {
    public static $calls = array();
    public function __construct($client, $logger) {}
    public function sync_family_dues($family_id, $year, $run_id = null) {
        self::$calls[] = array($family_id, $year, $run_id);
        return array('success' => $family_id !== '2');
    }
}
class Financial_Dues_Test_Db {
    public $prefix = 'wp_';
    public $update = array();
    public function get_var($query) { return 3; }
    public function prepare($query, ...$args) { return vsprintf(str_replace('%d', '%d', $query), $args); }
    public function get_col($query) {
        if (strpos($query, 'OFFSET 0') !== false) { return array('1', '2'); }
        if (strpos($query, 'OFFSET 2') !== false) { return array('3'); }
        return array();
    }
    public function update($table, $data, $where) { $this->update = $data; }
}

$GLOBALS['wpdb'] = new Financial_Dues_Test_Db();
require dirname(__DIR__) . '/includes/class-olama-oracle-job-manager.php';

$manager = new Olama_Oracle_Job_Manager();
$method = new ReflectionMethod($manager, 'process_financial_dues');
$job = array(
    'id' => 1, 'scope' => 'financial_dues', 'study_year' => '2026-2027',
    'cursor_offset' => 0, 'batch_size' => 2, 'active_run_id' => 9, 'summary' => array(),
);
$first = $method->invoke($manager, $job);
$job['cursor_offset'] = $GLOBALS['wpdb']->update['cursor_offset'];
$job['summary'] = json_decode($GLOBALS['wpdb']->update['summary_json'], true);
$second = $method->invoke($manager, $job);

if ($first['job_complete'] !== false || $second['job_complete'] !== true
    || array_column(Olama_Oracle_Student_Importer::$calls, 0) !== array('1', '2', '3')
    || $GLOBALS['wpdb']->update['cursor_offset'] !== 3
    || json_decode($GLOBALS['wpdb']->update['summary_json'], true)['failed'] !== 1) {
    throw new RuntimeException('Financial dues job did not cover all families or continue after a failed family.');
}

echo "Financial dues batch job checks passed.\n";
