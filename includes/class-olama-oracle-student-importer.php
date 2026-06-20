<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Student_Importer {
    private $client;
    private $logger;

    public function __construct(Olama_Oracle_Api_Client $client, Olama_Oracle_Sync_Logger $logger) {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function import_family($oracle_family_id, $run_id = null) {
        $own_run = !$run_id;
        $run_id = $run_id ?: $this->logger->start_run('family_students');

        if (!olama_core()->families()->get_by_oracle_id($oracle_family_id)) {
            $this->logger->log_item($run_id, 'family', 'ORA-FAM-' . $oracle_family_id, $oracle_family_id, null, 'skipped', 'failed', 'Family does not exist in Core.');
            if ($own_run) {
                $this->logger->finish_run($run_id, 'failed', 'Family does not exist in Core.');
            }
            return array('success' => false, 'message' => 'Family does not exist in Core.', 'run_id' => $run_id);
        }

        $result = $this->client->get_family_students($oracle_family_id);
        if (!$result['success']) {
            if ($own_run) {
                $this->logger->finish_run($run_id, 'failed', $result['message']);
            }
            return $result;
        }

        foreach ($this->extract_list($result['data'], 'students') as $student) {
            $student['family_id'] = isset($student['family_id']) ? $student['family_id'] : $oracle_family_id;
            $this->import_record($student, $run_id, '/api/families/' . $oracle_family_id . '/students');
        }

        if ($own_run) {
            $this->logger->finish_run($run_id);
        }

        return array('success' => true, 'message' => 'Students import finished.', 'run_id' => $run_id);
    }

    public function import_all_imported_families() {
        global $wpdb;

        $run_id = $this->logger->start_run('all_students');
        $family_table = $wpdb->prefix . 'olama_core_families';
        $limit = max(1, min(1000, absint(Olama_Oracle_Settings::get('batch_size'))));
        $families = $wpdb->get_col($wpdb->prepare('SELECT oracle_family_id FROM `' . esc_sql($family_table) . '` ORDER BY id ASC LIMIT %d', $limit));

        foreach ($families as $family_id) {
            $this->import_family($family_id, $run_id);
        }

        $this->logger->finish_run($run_id);
        return array('success' => true, 'message' => 'All imported families student sync finished.', 'run_id' => $run_id);
    }

    public function import_students_by_study_year($study_year = null) {
        $study_year = $study_year ? sanitize_text_field($study_year) : sanitize_text_field(Olama_Oracle_Settings::get('default_study_year'));
        $run_id = $this->logger->start_run('students_by_study_year');
        $params = $study_year ? array('study_year' => $study_year) : array();
        $result = $this->client->get_students($params);

        if (!$result['success']) {
            $this->logger->finish_run($run_id, 'failed', $result['message']);
            return array('success' => false, 'message' => $result['message'], 'run_id' => $run_id);
        }

        $students = $this->extract_list($result['data'], 'students');
        foreach ($students as $student) {
            if (!is_array($student)) {
                $this->logger->log_item($run_id, 'student', null, null, null, 'skipped', 'success', 'Student record is not a valid object.');
                continue;
            }

            if ((!isset($student['study_year']) || $student['study_year'] === '') && $study_year) {
                $student['study_year'] = $study_year;
            }

            $family_id = isset($student['family_id']) ? $student['family_id'] : (isset($student['oracle_family_id']) ? $student['oracle_family_id'] : '');
            $student_id = isset($student['student_id']) ? $student['student_id'] : (isset($student['oracle_student_id']) ? $student['oracle_student_id'] : '');
            $student_uid = 'ORA-STU-' . $family_id . '-' . $student_id;

            if (!$family_id || !olama_core()->families()->get_by_oracle_id($family_id)) {
                $this->logger->log_item($run_id, 'student', $student_uid, $family_id, $student_id, 'skipped', 'success', 'Matching Core family not found');
                continue;
            }

            $import_result = $this->import_record($student, $run_id, '/api/students', false);
            $this->logger->log_item(
                $run_id,
                'student',
                $student_uid,
                $family_id,
                $student_id,
                $import_result['operation'],
                $import_result['status'],
                $import_result['message']
            );
        }

        $this->logger->finish_run($run_id);
        return array('success' => true, 'message' => 'Students by study year import finished. Records received: ' . count($students) . '.', 'run_id' => $run_id);
    }

    private function import_record(array $student, $run_id, $endpoint, $log_items = true) {
        $family_id = isset($student['family_id']) ? $student['family_id'] : (isset($student['oracle_family_id']) ? $student['oracle_family_id'] : '');
        $student_id = isset($student['student_id']) ? $student['student_id'] : (isset($student['oracle_student_id']) ? $student['oracle_student_id'] : '');
        $student_uid = 'ORA-STU-' . $family_id . '-' . $student_id;

        try {
            if (!olama_core()->families()->get_by_oracle_id($family_id)) {
                throw new RuntimeException('Matching Core family not found.');
            }

            $student_data = array(
                'oracle_family_id' => $family_id,
                'oracle_student_id' => $student_id,
                'student_name' => isset($student['student_name']) ? $student['student_name'] : '',
                'student_national_no' => isset($student['student_national_no']) ? $student['student_national_no'] : '',
                'student_mobile' => isset($student['student_mobile']) ? $student['student_mobile'] : '',
                'student_status' => isset($student['student_status']) ? $student['student_status'] : '',
                'raw' => $student,
            );
            $student_result = olama_core()->students()->upsert_from_source($student_data);
            if ($log_items) {
                $this->logger->log_item($run_id, 'student', $student_result['uid'], $family_id, $student_id, $student_result['operation'], 'success', 'Student ' . $student_result['operation']);
            }

            $operation = $student_result['operation'];
            $study_year = isset($student['study_year']) && $student['study_year'] !== '' ? $student['study_year'] : Olama_Oracle_Settings::get('default_study_year');
            if ($study_year) {
                $year_data = array(
                    'oracle_family_id' => $family_id,
                    'oracle_student_id' => $student_id,
                    'study_year' => $study_year,
                    'class_id' => isset($student['class_id']) ? $student['class_id'] : '',
                    'class_name' => isset($student['class_name']) ? $student['class_name'] : '',
                    'section_id' => isset($student['section_id']) ? $student['section_id'] : '',
                    'section_name' => isset($student['section_name']) ? $student['section_name'] : '',
                    'student_year_status' => isset($student['student_status']) ? $student['student_status'] : '',
                    'raw' => $student,
                );
                $year_result = olama_core()->student_years()->upsert_from_source($year_data);
                $operation = $year_result['operation'];
                if ($log_items) {
                    $this->logger->log_item($run_id, 'student_year', $year_result['uid'], $family_id, $student_id, $year_result['operation'], 'success', 'Student year ' . $year_result['operation']);
                }
            }

            $this->logger->store_payload('student', $family_id, $student_id, $endpoint, $student);
            return array('operation' => $operation, 'status' => 'success', 'message' => 'Student by study year ' . $operation);
        } catch (Exception $e) {
            if ($log_items) {
                $this->logger->log_item($run_id, 'student', $student_uid, $family_id, $student_id, 'failed', 'failed', $e->getMessage());
            }
            return array('operation' => 'failed', 'status' => 'failed', 'message' => $e->getMessage());
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
