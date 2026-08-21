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

        if (!function_exists('olama_core') || !method_exists(olama_core(), 'transport_master') || !method_exists(olama_core(), 'families')) {
            $message = 'Olama Core transport master service is unavailable.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }
        $families_service = olama_core()->families();
        if (!method_exists($families_service, 'update_location_from_source')
            && (!method_exists($families_service, 'get_by_oracle_id') || !method_exists($families_service, 'upsert_from_source'))) {
            $message = 'Olama Core family location service is unavailable.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $buses_response = $this->client->get_transportation_buses();
        $regions_response = $this->client->get_transportation_regions($study_year);
        $locations_response = $this->fetch_family_locations();
        if (empty($buses_response['success']) || empty($regions_response['success']) || empty($locations_response['success'])) {
            $message = 'Transportation master API failed. Buses: ' .
                ($buses_response['message'] ?? 'unknown') . '; regions: ' .
                ($regions_response['message'] ?? 'unknown') . '; family locations: ' .
                ($locations_response['message'] ?? 'unknown');
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $buses = $this->list_from($buses_response['data'], 'buses');
        $regions = array_values(array_filter(
            $this->list_from($regions_response['data'], 'regions'),
            array($this, 'region_is_active')
        ));
        $locations = $locations_response['locations'];
        try {
            $bus_summary = olama_core()->transport_master()->replace_buses_from_source($buses);
            $region_summary = olama_core()->transport_master()->replace_regions_from_source($regions);
            $location_summary = array('received' => count($locations), 'updated' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0);
            foreach ($locations as $location) {
                if (!is_array($location)) {
                    continue;
                }
                $oracle_id = sanitize_text_field((string) ($location['family_id'] ?? $location['oracle_family_id'] ?? ''));
                try {
                    $result = $this->update_family_location($families_service, $location);
                    $operation = sanitize_key((string) ($result['operation'] ?? 'skipped'));
                    if (!isset($location_summary[$operation])) {
                        $operation = 'skipped';
                    }
                    $location_summary[$operation]++;
                    $this->logger->log_item(
                        $run_id,
                        'family_location',
                        $oracle_id ? 'ORA-FAM-' . $oracle_id : null,
                        $oracle_id ?: null,
                        null,
                        $operation,
                        'success',
                        'Oracle family address and area ' . $operation . '.'
                    );
                } catch (Exception $exception) {
                    $location_summary['failed']++;
                    $this->logger->log_item(
                        $run_id,
                        'family_location',
                        $oracle_id ? 'ORA-FAM-' . $oracle_id : null,
                        $oracle_id ?: null,
                        null,
                        'failed',
                        'failed',
                        $exception->getMessage()
                    );
                }
            }
            /**
             * Domain plugins may refresh their local planning projections only
             * after both master and family-location data are current in Core.
             */
            do_action('olama_core_transport_master_updated', $bus_summary, $region_summary, $location_summary);
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
            $this->logger->store_payload('transport_family_locations', null, null, $locations_response['endpoint'], array('locations' => $locations));
            $this->logger->finish_run($run_id);
            return array(
                'success' => true,
                'message' => sprintf(
                    'Transportation data synchronized to Olama Core. Buses: %d; regions: %d; family locations: %d updated, %d unchanged, %d missing.',
                    count($buses),
                    count($regions),
                    $location_summary['updated'],
                    $location_summary['skipped'],
                    $location_summary['missing']
                ),
                'run_id' => $run_id,
                'buses' => $bus_summary,
                'regions' => $region_summary,
                'family_locations' => $location_summary,
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

    private function fetch_family_locations() {
        $limit = 500;
        $offset = 0;
        $locations = array();

        do {
            $response = $this->client->get_transportation_family_locations($limit, $offset);
            if (empty($response['success'])) {
                // Keep compatibility with a Bridge that has not received the
                // dedicated lightweight endpoint yet.
                if (0 === $offset && 404 === (int) ($response['status_code'] ?? 0)) {
                    $fallback = $this->client->get_families();
                    if (empty($fallback['success'])) {
                        return $fallback;
                    }
                    return array(
                        'success' => true,
                        'locations' => $this->list_from($fallback['data'], 'families'),
                        'endpoint' => '/api/families',
                        'message' => 'Compatibility family endpoint used.',
                    );
                }
                return $response;
            }

            $page = $this->list_from($response['data'], 'locations');
            $received = count($page);
            $locations = array_merge($locations, $page);
            $offset += $received;
            $total = isset($response['data']['total']) ? absint($response['data']['total']) : 0;
            $done = 0 === $received || $received < $limit || ($total > 0 && $offset >= $total);
        } while (!$done);

        return array(
            'success' => true,
            'locations' => $locations,
            'endpoint' => '/api/transportation/family-locations',
            'message' => 'Family locations loaded.',
        );
    }

    private function update_family_location($families_service, array $location) {
        if (method_exists($families_service, 'update_location_from_source')) {
            return $families_service->update_location_from_source($location);
        }

        $oracle_id = sanitize_text_field((string) ($location['family_id'] ?? $location['oracle_family_id'] ?? ''));
        if ('' === $oracle_id || !$families_service->get_by_oracle_id($oracle_id)) {
            return array('operation' => 'missing', 'status' => 'skipped', 'uid' => $oracle_id ? 'ORA-FAM-' . $oracle_id : null);
        }
        $location['oracle_family_id'] = $oracle_id;
        $location['_partial'] = true;
        return $families_service->upsert_from_source($location);
    }

    /**
     * Defensively honor the Oracle region status when an older Bridge version
     * still returns inactive rows despite the active-only request.
     */
    private function region_is_active($region) {
        if (!is_array($region)) {
            return false;
        }
        $keys = array(
            'is_active', 'active', 'region_is_active', 'region_active',
            'is_active_name', 'active_name', 'is_enabled', 'enabled',
            'region_status', 'region_status_name', 'status', 'status_name',
        );
        foreach ($keys as $key) {
            if (!array_key_exists($key, $region) || $region[$key] === '' || $region[$key] === null) {
                continue;
            }
            $value = strtolower(trim((string) $region[$key]));
            return in_array($value, array('1', 'true', 'yes', 'y', 'active', 'enabled', 'فعال'), true);
        }

        // The endpoint was explicitly requested as active-only. Rows from a
        // Bridge contract that predates a status field are therefore accepted.
        return true;
    }
}
