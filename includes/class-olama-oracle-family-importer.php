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
        $result = $this->client->get_families(array('limit' => Olama_Oracle_Settings::get('batch_size')));

        if (!$result['success']) {
            $this->logger->finish_run($run_id, 'failed', $result['message']);
            return $result;
        }

        $families = $this->extract_list($result['data'], 'families');
        foreach ($families as $family) {
            $this->import_record($family, $run_id, '/api/families');
        }

        if ($own_run) {
            $this->logger->finish_run($run_id);
        }

        return array('success' => true, 'message' => 'Families import finished.', 'run_id' => $run_id);
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
                'address' => isset($family['address']) ? $family['address'] : '',
                'family_status' => isset($family['family_status']) ? $family['family_status'] : '',
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
}
