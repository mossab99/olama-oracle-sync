<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only adapter allowed to move Oracle fleet/region masters into Olama.
 */
class Olama_Oracle_Transport_Master_Importer {
    private $client;
    private $logger;

    public function __construct(Olama_Oracle_Api_Client $client, Olama_Oracle_Sync_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function import_all($study_year) {
        $run_id = $this->logger->start_run('transport_master');
        $study_year = sanitize_text_field((string) $study_year);

        if (!function_exists('olama_core') || !method_exists(olama_core(), 'transport_master')) {
            $message = 'Olama Core transport master service is unavailable.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $buses_response = $this->client->get_transportation_buses();
        $regions_response = $this->client->get_transportation_regions($study_year);
        if (empty($buses_response['success']) || empty($regions_response['success'])) {
            $message = 'Transportation master API failed. Buses: ' .
                ($buses_response['message'] ?? 'unknown') . '; regions: ' .
                ($regions_response['message'] ?? 'unknown');
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $buses = $this->list_from($buses_response['data'], 'buses');
        $regions = $this->list_from($regions_response['data'], 'regions');
        try {
            $bus_summary = olama_core()->transport_master()->replace_buses_from_source($buses);
            $region_summary = olama_core()->transport_master()->replace_regions_from_source($regions);
            /**
             * Domain plugins may refresh their local planning projections only
             * after the canonical Core transaction has completed.
             */
            do_action('olama_core_transport_master_updated', $bus_summary, $region_summary);
            foreach ($buses as $bus) {
                $oracle_id = sanitize_text_field((string) ($bus['oracle_bus_id'] ?? ''));
                $this->logger->log_item(
                    $run_id, 'transport_bus', $oracle_id ? 'ORA-BUS-' . $oracle_id : null,
                    null, null, 'updated', 'success', 'Canonical Core bus synchronized.'
                );
            }
            foreach ($regions as $region) {
                $oracle_id = sanitize_text_field((string) ($region['oracle_region_id'] ?? ''));
                $this->logger->log_item(
                    $run_id, 'transport_region', $oracle_id ? 'ORA-REGION-' . $oracle_id : null,
                    null, null, 'updated', 'success', 'Canonical Core region synchronized.'
                );
            }
            $this->logger->store_payload('transport_buses', null, null, '/api/transportation/buses', $buses_response['data']);
            $this->logger->store_payload('transport_regions', null, null, '/api/transportation/regions', $regions_response['data']);
            $this->logger->finish_run($run_id);
            return array(
                'success' => true,
                'message' => sprintf(
                    'Transportation master synchronized to Olama Core. Buses: %d; regions: %d.',
                    count($buses),
                    count($regions)
                ),
                'run_id' => $run_id,
                'buses' => $bus_summary,
                'regions' => $region_summary,
            );
        } catch (Exception $exception) {
            $this->logger->finish_run($run_id, 'failed', $exception->getMessage());
            return array('success' => false, 'message' => $exception->getMessage(), 'run_id' => $run_id);
        }
    }

    private function list_from($data, $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
        return array();
    }
}
