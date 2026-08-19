<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    private $message;
    public function __construct($code, $message, $data = null) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class Olama_Oracle_Sync_Logger {}
class Olama_Oracle_Api_Client {
    public function health($timeout = null) {
        return array('success' => true, 'data' => array('status' => 'ok', 'oracle' => 'connected'), 'message' => 'OK');
    }
}

function absint($value) { return abs((int) $value); }
function current_time($type) { return '2026-08-19 17:30:00'; }
function wp_json_encode($value) { return json_encode($value); }
function wp_clear_scheduled_hook($hook, $args = array()) { $GLOBALS['cleared_jobs'][] = $args[0]; }
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_schedule_single_event($time, $hook, $args = array()) { $GLOBALS['scheduled_jobs'][] = $args[0]; }

class Olama_Oracle_Pause_Test_Wpdb {
    public $prefix = 'wp_';
    public $job;

    public function __construct() {
        $this->job = array(
            'id' => 10,
            'scope' => 'fast',
            'study_year' => '2026-2027',
            'status' => 'running',
            'current_phase' => 'validation',
            'phase_index' => 4,
            'total_phases' => 5,
            'cursor_offset' => 50,
            'batch_size' => 25,
            'active_run_id' => null,
            'phase_runs' => '{"fast_sync":42}',
            'summary_json' => '{"cached_counts":{"seen":250,"created":3,"updated":20,"skipped":227,"failed":0}}',
            'message' => 'Running',
            'error_summary' => null,
            'created_by' => 1,
            'started_at' => '2026-08-19 17:00:00',
            'heartbeat_at' => null,
            'finished_at' => null,
        );
    }

    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $query = preg_replace('/%d/', (string) (int) $arg, $query, 1);
        }
        return $query;
    }

    public function get_row($query, $format = null) {
        return strpos($query, 'olama_oracle_sync_jobs') !== false ? $this->job : null;
    }

    public function get_var($query) {
        if (strpos($query, "'paused'") !== false && in_array($this->job['status'], array('queued', 'running', 'paused'), true)) {
            return $this->job['id'];
        }
        return null;
    }

    public function update($table, $data, $where) {
        $this->job = array_merge($this->job, $data);
        return 1;
    }
}

$GLOBALS['wpdb'] = new Olama_Oracle_Pause_Test_Wpdb();
$GLOBALS['cleared_jobs'] = array();
$GLOBALS['scheduled_jobs'] = array();

require dirname(__DIR__) . '/includes/class-olama-oracle-job-manager.php';

$manager = new Olama_Oracle_Job_Manager();
$paused = $manager->pause_job(10);
$active = $manager->active_job();
$resumed = $manager->resume_job(10);

if ($paused['status'] !== 'paused'
    || $active['status'] !== 'paused'
    || $resumed['status'] !== 'queued'
    || $active['counts']['seen'] !== 250
    || $active['counts']['updated'] !== 20
    || $GLOBALS['cleared_jobs'] !== array(10)
    || $GLOBALS['scheduled_jobs'] !== array(10)) {
    fwrite(STDERR, "Oracle job pause/resume: FAIL\n");
    exit(1);
}

echo "Oracle job pause/resume: PASS\n";
