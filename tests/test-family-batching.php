<?php

define('ABSPATH', __DIR__);

function absint($value) { return abs((int) $value); }

class Olama_Oracle_Settings {
    public static function get($key = null) { return 2; }
}

class Olama_Oracle_Api_Client {
    public $offsets = array();
    public $unpaginated = false;

    public function get_families($args) {
        $this->offsets[] = (int) $args['offset'];
        if ($this->unpaginated) {
            return array('success' => true, 'data' => array('families' => array(
                array('family_id' => 20),
                array('family_id' => 21),
                array('family_id' => 22),
            )));
        }
        if (0 === (int) $args['offset']) {
            return array('success' => true, 'data' => array('families' => array(
                array('family_id' => 10),
                array('family_id' => 11),
            )));
        }
        return array('success' => true, 'data' => array('families' => array(
            array('family_id' => 12),
        )));
    }
}

class Olama_Oracle_Sync_Logger {
    public $finished = false;
    public function start_run($type) { return 9; }
    public function finish_run($id, $status = 'completed', $message = '') { $this->finished = true; }
    public function store_payload($type, $family, $student, $endpoint, array $payload) {}
    public function log_item($run, $type, $uid, $family, $student, $operation, $status, $message = '') {}
}

class Olama_Oracle_Family_Test_Repository {
    public $ids = array();
    public function upsert_from_source($data) {
        $this->ids[] = (int) $data['oracle_family_id'];
        return array('uid' => 'ORA-FAM-' . $data['oracle_family_id'], 'operation' => 'created');
    }
}

class Olama_Oracle_Family_Test_Core {
    public $repository;
    public function __construct() { $this->repository = new Olama_Oracle_Family_Test_Repository(); }
    public function families() { return $this->repository; }
}

function olama_core() {
    static $core;
    return $core ?: ($core = new Olama_Oracle_Family_Test_Core());
}

require dirname(__DIR__) . '/includes/class-olama-oracle-family-importer.php';

$client = new Olama_Oracle_Api_Client();
$logger = new Olama_Oracle_Sync_Logger();
$result = (new Olama_Oracle_Family_Importer($client, $logger))->import_all();

if (empty($result['success']) || $client->offsets !== array(0, 2) || olama_core()->repository->ids !== array(10, 11, 12) || !$logger->finished) {
    fwrite(STDERR, "Family importer batching: FAIL\n");
    exit(1);
}

$client = new Olama_Oracle_Api_Client();
$client->unpaginated = true;
$logger = new Olama_Oracle_Sync_Logger();
$result = (new Olama_Oracle_Family_Importer($client, $logger))->import_all();

if (empty($result['success']) || $client->offsets !== array(0) || !$logger->finished || array_slice(olama_core()->repository->ids, -3) !== array(20, 21, 22)) {
    fwrite(STDERR, "Unpaginated family response: FAIL\n");
    exit(1);
}

echo "Family importer batching: PASS\n";
