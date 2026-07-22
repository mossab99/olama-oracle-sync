<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Academic_Importer {
    private $client;
    private $logger;

    public function __construct(Olama_Oracle_Api_Client $client, Olama_Oracle_Sync_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function import($study_year) {
        $study_year = sanitize_text_field((string) $study_year);
        $run_id = $this->logger->start_run('academic_info');
        if ('' === $study_year) {
            $message = 'Study year is required for academic information sync.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        $response = $this->client->get('/api/academic/snapshot', array('study_year' => $study_year));
        if (empty($response['success']) || !is_array($response['data']) || 'ok' !== ($response['data']['status'] ?? '')) {
            $message = !empty($response['message']) ? $response['message'] : 'Academic API returned an invalid response.';
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }

        try {
            $counts = olama_core()->academic()->import_snapshot($response['data']);
            foreach ($counts as $entity => $count) {
                if ('study_year' === $entity) {
                    continue;
                }
                $this->logger->log_item($run_id, 'academic_' . $entity, $study_year, null, null, 'replaced', 'success', sprintf('%d records synchronized.', $count));
            }
            $message = sprintf(
                'Academic information synchronized for %s: %d grades, %d sections, %d grade-section pairs, %d students, and %d grade subjects.',
                $study_year,
                $counts['grades'],
                $counts['sections'],
                $counts['grade_sections'],
                $counts['students'],
                $counts['grade_subjects']
            );
            $this->logger->finish_run($run_id, 'completed', $message);
            return array('success' => true, 'message' => $message, 'run_id' => $run_id, 'counts' => $counts);
        } catch (Exception $exception) {
            $message = 'Academic information could not be saved to Olama Core: ' . $exception->getMessage();
            $this->logger->finish_run($run_id, 'failed', $message);
            return array('success' => false, 'message' => $message, 'run_id' => $run_id);
        }
    }
}
