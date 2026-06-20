<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Family_Importer {
    private $client;
    private $logger;

    public function __construct(Olama_Oracle_Api_Client $client, Olama_Oracle_Sync_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function import_all($run_id = null) {
        $own_run = !$run_id;
        $run_id = $run_id ?: $this->logger->start_run('families');
        $limit = max(1, min(1000, absint(Olama_Oracle_Settings::get('batch_size'))));
        $offset = 0;
        $seen = 0;
        $previous_first_id = null;

        do {
            $result = $this->client->get_families(array('limit' => $limit, 'offset' => $offset));

            if (!$result['success']) {
                $this->logger->finish_run($run_id, 'failed', $result['message']);
                return $result;
            }

            $families = $this->extract_list($result['data'], 'families');
            $first_id = $families && is_array($families[0]) ? $this->first($families[0], array('family_id', 'oracle_family_id')) : null;
            if ($first_id && $previous_first_id && (string) $first_id === (string) $previous_first_id) {
                $this->logger->log_item($run_id, 'family', null, null, null, 'skipped', 'failed', 'Oracle bridge returned the same families page again; stopping to avoid duplicate pagination loop.');
                break;
            }
            $previous_first_id = $first_id;

            foreach ($families as $family) {
                $this->import_record($family, $run_id, '/api/families');
                $seen++;
            }

            $offset += $limit;
        } while (count($families) === $limit);

        if (0 === $seen && $offset > $limit) {
            $this->logger->log_item($run_id, 'family', null, null, null, 'skipped', 'failed', 'No families were returned by the Oracle bridge.');
        }

        if ($own_run) {
            $this->logger->finish_run($run_id);
        }

        return array('success' => true, 'message' => 'Families import finished. Records received: ' . $seen . '.', 'run_id' => $run_id);
    }

    public function import_one($oracle_family_id, $run_id = null) {
        $own_run = !$run_id;
        $run_id = $run_id ?: $this->logger->start_run('family');
        $result = $this->client->get_family($oracle_family_id);

        if (!$result['success']) {
            $this->logger->finish_run($run_id, 'failed', $result['message']);
            return $result;
        }

        $family = isset($result['data']['family']) && is_array($result['data']['family']) ? $result['data']['family'] : $result['data'];
        $this->import_record($family, $run_id, '/api/families/' . $oracle_family_id);
        if ($own_run) {
            $this->logger->finish_run($run_id);
        }

        return array('success' => true, 'message' => 'Family import finished.', 'run_id' => $run_id);
    }

    private function import_record(array $family, $run_id, $endpoint) {
        $family_id = isset($family['family_id']) ? $family['family_id'] : (isset($family['oracle_family_id']) ? $family['oracle_family_id'] : '');
        try {
            $data = array(
                'oracle_family_id' => $family_id,
                'sponsor_full_name' => isset($family['sponsor_full_name']) ? $family['sponsor_full_name'] : '',
                'father_name' => isset($family['father_name']) ? $family['father_name'] : '',
                'mother_name' => isset($family['mother_name']) ? $family['mother_name'] : '',
                'father_mobile' => isset($family['father_mobile']) ? $family['father_mobile'] : '',
                'mother_mobile' => isset($family['mother_mobile']) ? $family['mother_mobile'] : '',
                'primary_mobile' => isset($family['primary_mobile']) ? $family['primary_mobile'] : (isset($family['father_mobile']) ? $family['father_mobile'] : ''),
                'email' => isset($family['email']) ? $family['email'] : '',
                'address' => $this->first($family, array('address', 'family_address')),
                'family_address' => $this->first($family, array('family_address', 'address')),
                'trans_region_id' => $this->first($family, array('trans_region_id', 'transportation_region_id', 'region_id')),
                'trans_region_name' => $this->first($family, array('trans_region_name', 'transportation_region_name', 'region_name', 'area_name')),
                'family_status' => $this->first($family, array('family_status', 'status')),
                'family_status_name' => $this->first($family, array('family_status_name', 'status_name')),
                'students_count' => $this->first($family, array('students_count', 'student_count', 'children_count')),
                'raw' => $family,
            );
            $result = olama_core()->families()->upsert_from_source($data);
            $this->logger->store_payload('family', $family_id, null, $endpoint, $family);
            $this->logger->log_item($run_id, 'family', $result['uid'], $family_id, null, $result['operation'], 'success', ucfirst($result['operation']));
        } catch (Exception $e) {
            $uid = $family_id ? 'ORA-FAM-' . $family_id : null;
            $this->logger->log_item($run_id, 'family', $uid, $family_id, null, 'failed', 'failed', $e->getMessage());
        }
    }

    private function extract_list($data, $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        return is_array($data) ? $data : array();
    }

    private function first($data, $keys, $default = '') {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return $default;
    }
}
