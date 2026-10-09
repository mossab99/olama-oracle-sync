<?php

require __DIR__ . '/test-job-pause.php';

function get_transient($key) { return !empty($GLOBALS['recovery_locked']); }

class Oracle_Recovery_Test_Manager extends Olama_Oracle_Job_Manager {
    public $processed = array();
    public function process_job($id) { $this->processed[] = $id; }
}

$manager = new Oracle_Recovery_Test_Manager();
$db = $GLOBALS['wpdb'];
$db->job['status'] = 'queued';
$db->job['heartbeat_at'] = null;
$db->job['started_at'] = '2026-08-19 17:00:00';
$GLOBALS['cleared_jobs'] = array();
$manager->recover_stalled_job(10);
if ($manager->processed !== array(10) || $GLOBALS['cleared_jobs'] !== array(10)) {
    throw new RuntimeException('Overdue queued job was not recovered and its event consumed.');
}

$db->job['status'] = 'running';
$db->job['heartbeat_at'] = '2026-08-19 17:29:50';
$manager->recover_stalled_job(10);
$db->job['heartbeat_at'] = '2026-08-19 17:00:00';
$GLOBALS['recovery_locked'] = true;
$manager->recover_stalled_job(10);
$GLOBALS['recovery_locked'] = false;
$db->job['status'] = 'paused';
$manager->recover_stalled_job(10);
$db->job['status'] = 'completed';
$manager->recover_stalled_job(10);
if ($manager->processed !== array(10)) {
    throw new RuntimeException('Recovery advanced a recent, locked, paused, or completed job.');
}

$db->job['status'] = 'running';
$manager->recover_stalled_job(10);
if ($manager->processed !== array(10, 10)) {
    throw new RuntimeException('Stalled running job was not recovered.');
}
echo "Oracle stalled job recovery: PASS\n";
