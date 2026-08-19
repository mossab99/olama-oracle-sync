<?php

define('ABSPATH', __DIR__);

function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function absint($value) { return abs((int) $value); }
function do_action($hook, ...$args) { $GLOBALS['transport_actions'][] = array($hook, $args); }

class Olama_Oracle_Api_Client {
    public function get_transportation_buses() {
        return array('success' => true, 'data' => array('buses' => array(array('oracle_bus_id' => 1))));
    }
    public function get_transportation_regions($year) {
        return array('success' => true, 'data' => array('regions' => array(array('oracle_region_id' => 89, 'is_active' => 1))));
    }
    public function get_transportation_family_locations($limit, $offset) {
        return array('success' => true, 'data' => array(
            'total' => 2,
            'locations' => array(
                array('family_id' => 1200, 'family_address' => 'Amman', 'trans_region_id' => 89, 'trans_region_name' => 'عدن'),
                array('family_id' => 1260, 'family_address' => '', 'trans_region_id' => null, 'trans_region_name' => null),
            ),
        ));
    }
    public function get_families() { return array('success' => false); }
}

class Olama_Oracle_Sync_Logger {
    public $items = array();
    public function start_run($type) { return 7; }
    public function finish_run($id, $status = 'completed', $message = '') {}
    public function log_item($run, $type, $uid, $family, $student, $operation, $status, $message = '') {
        $this->items[] = compact('type', 'family', 'operation', 'status');
    }
    public function store_payload($type, $family, $student, $endpoint, array $payload) {}
}

class Olama_Oracle_Transport_Test_Master {
    public function replace_buses_from_source($rows) { return array('received' => count($rows)); }
    public function replace_regions_from_source($rows) { return array('received' => count($rows)); }
}

class Olama_Oracle_Transport_Test_Families {
    public $locations = array();
    public function update_location_from_source(array $location) {
        $this->locations[] = $location;
        return array(
            'operation' => 1200 === (int) $location['family_id'] ? 'updated' : 'missing',
            'uid' => 'ORA-FAM-' . $location['family_id'],
        );
    }
}

class Olama_Oracle_Transport_Test_Core {
    public $master;
    public $family_service;
    public function __construct() {
        $this->master = new Olama_Oracle_Transport_Test_Master();
        $this->family_service = new Olama_Oracle_Transport_Test_Families();
    }
    public function transport_master() { return $this->master; }
    public function families() { return $this->family_service; }
}

function olama_core() {
    static $core;
    return $core ?: ($core = new Olama_Oracle_Transport_Test_Core());
}

$GLOBALS['transport_actions'] = array();
require dirname(__DIR__) . '/includes/class-olama-oracle-transport-master-importer.php';

$logger = new Olama_Oracle_Sync_Logger();
$result = (new Olama_Oracle_Transport_Master_Importer(new Olama_Oracle_Api_Client(), $logger))->import_all('2026-2027');

if (empty($result['success'])
    || $result['family_locations']['updated'] !== 1
    || $result['family_locations']['missing'] !== 1
    || count(olama_core()->family_service->locations) !== 2
    || olama_core()->family_service->locations[0]['trans_region_name'] !== 'عدن'
    || count($GLOBALS['transport_actions']) !== 1
    || $GLOBALS['transport_actions'][0][0] !== 'olama_core_transport_master_updated') {
    fwrite(STDERR, "Transportation family locations: FAIL\n");
    exit(1);
}

echo "Transportation family locations: PASS\n";
