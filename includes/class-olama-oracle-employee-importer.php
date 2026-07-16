<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Employee_Importer {
    private $client;
    private $logger;

    public function __construct(Olama_Oracle_Api_Client $client, Olama_Oracle_Sync_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function import_all() {
        $run_id = $this->logger->start_run('employees');
        $limit = max(100, min(1000, absint(Olama_Oracle_Settings::get('batch_size'))));
        $offset = 0;
        $received = 0;
        $active_employee_ids = array();

        do {
            $response = $this->client->get('/api/employees', array('limit' => $limit, 'offset' => $offset));
            if (empty($response['success']) || !isset($response['data']['employees']) || !is_array($response['data']['employees'])) {
                $message = !empty($response['message']) ? $response['message'] : 'Employee API returned an invalid response.';
                $this->logger->finish_run($run_id, 'failed', $message);
                return array('success' => false, 'message' => $message, 'run_id' => $run_id);
            }

            $employees = $response['data']['employees'];
            foreach ($employees as $employee) {
                $employee_id = isset($employee['employee_id']) ? sanitize_text_field((string) $employee['employee_id']) : '';
                try {
                    if (!isset($employee['employee_status']) || 'مستمر' !== trim((string) $employee['employee_status'])) {
                        throw new RuntimeException('Employee status is not active.');
                    }
                    $active_employee_ids[] = $employee_id;
                    $result = olama_core()->employees()->upsert_from_source($employee);
                    $this->logger->log_item($run_id, 'employee', $result['uid'], null, null, $result['operation'], 'success', ucfirst($result['operation']));
                } catch (Exception $exception) {
                    $this->logger->log_item($run_id, 'employee', $employee_id ? 'ORA-EMP-' . $employee_id : null, null, null, 'failed', 'failed', $exception->getMessage());
                }
            }

            $page_count = count($employees);
            $received += $page_count;
            $offset += $page_count;
        } while ($page_count === $limit);

        if (0 === $received) {
            $message = 'Employee API returned no active records; existing Core employees were preserved.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $deactivated = olama_core()->employees()->mark_missing_inactive($active_employee_ids);

        $this->logger->finish_run($run_id);
        return array(
            'success' => true,
            'message' => sprintf('Employee import finished. Active Oracle records received: %d. Missing previous employees marked inactive: %d.', $received, $deactivated),
            'run_id' => $run_id,
        );
    }
}
