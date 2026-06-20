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

    public function import_family($oracle_family_id, $run_id = null, $study_year = null) {
        $own_run = !$run_id;
        $run_id = $run_id ?: $this->logger->start_run('family_students');
        $study_year = $this->resolve_study_year($study_year);
        $summary = $this->empty_summary();

        if (!olama_core()->families()->get_by_oracle_id($oracle_family_id)) {
            $this->logger->log_item($run_id, 'family', 'ORA-FAM-' . $oracle_family_id, $oracle_family_id, null, 'skipped', 'failed', 'Family does not exist in Core.');
            if ($own_run) {
                $this->logger->finish_run($run_id, 'failed', 'Family does not exist in Core.');
            }
            return array('success' => false, 'message' => 'Family does not exist in Core.', 'run_id' => $run_id);
        }

        $query_args = array('study_year' => $study_year);
        $source_endpoint = '/api/families/' . $oracle_family_id . '/card';
        $card = $this->client->get_family_card($oracle_family_id, $query_args);
        $students = array();

        if ($card['success']) {
            $students = $this->extract_students_from_card($card['data']);
        }

        if (!$students) {
            $source_endpoint = '/api/families/' . $oracle_family_id . '/students';
            $result = $this->client->get_family_students($oracle_family_id, $query_args);
            if (!$result['success']) {
                if ($own_run) {
                    $this->logger->finish_run($run_id, 'failed', $result['message']);
                }
                return $result;
            }

            $students = $this->extract_list($result['data'], 'students');
        }

        foreach ($students as $student) {
            $student['family_id'] = isset($student['family_id']) ? $student['family_id'] : $oracle_family_id;
            $import_result = $this->import_record($student, $run_id, $source_endpoint, $study_year);
            $this->merge_summary($summary, $import_result);
        }

        if ($own_run) {
            $this->logger->finish_run($run_id);
        }

        return array('success' => true, 'message' => $this->summary_message('Students import finished.', $summary, $study_year, null, null), 'run_id' => $run_id, 'summary' => $summary, 'study_year' => $study_year);
    }

    public function import_all_imported_families($offset = 0, $study_year = null) {
        global $wpdb;

        $run_id = $this->logger->start_run('all_students');
        $study_year = $this->resolve_study_year($study_year);
        $family_table = $wpdb->prefix . 'olama_core_families';
        $limit = max(1, min(1000, absint(Olama_Oracle_Settings::get('batch_size'))));
        $offset = max(0, absint($offset));
        $total_families = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($family_table) . '`');
        $families = $wpdb->get_col($wpdb->prepare('SELECT oracle_family_id FROM `' . esc_sql($family_table) . '` ORDER BY id ASC LIMIT %d OFFSET %d', $limit, $offset));
        $summary = $this->empty_summary();
        $summary['families'] = count($families);

        foreach ($families as $family_id) {
            $result = $this->import_family($family_id, $run_id, $study_year);
            if (!empty($result['summary'])) {
                $this->merge_summary($summary, $result['summary']);
            } elseif (empty($result['success'])) {
                $summary['failed']++;
            }
        }

        $this->logger->finish_run($run_id);
        $next_offset = $offset + $limit;
        $message = $this->summary_message('Students sync batch finished. Families ' . min($next_offset, $total_families) . ' / ' . $total_families . '.', $summary, $study_year, $offset, $limit);
        if ($next_offset < $total_families) {
            $message .= ' Continue with next offset: ' . $next_offset . '.';
        }

        return array('success' => true, 'message' => $message, 'run_id' => $run_id, 'next_offset' => $next_offset < $total_families ? $next_offset : null, 'summary' => $summary, 'study_year' => $study_year);
    }

    public function import_student_years_for_imported_families($offset = 0, $study_year = null) {
        return $this->import_all_imported_families($offset, $study_year);
    }

    public function import_students_by_study_year($study_year = null) {
        $study_year = $this->resolve_study_year($study_year);
        $run_id = $this->logger->start_run('students_by_study_year');
        $limit = max(1, min(1000, absint(Olama_Oracle_Settings::get('batch_size'))));
        $offset = 0;
        $received = 0;
        $summary = $this->empty_summary();
        $previous_first_uid = null;

        do {
            $params = array('limit' => $limit, 'offset' => $offset);
            if ($study_year) {
                $params['study_year'] = $study_year;
            }
            $result = $this->client->get_students($params);

            if (!$result['success']) {
                $this->logger->finish_run($run_id, 'failed', $result['message']);
                return array('success' => false, 'message' => $result['message'], 'run_id' => $run_id);
            }

            $students = $this->extract_list($result['data'], 'students');
            $first = $students && is_array($students[0]) ? $this->first($students[0], array('student_uid', 'student_id', 'oracle_student_id')) : null;
            if ($first && $previous_first_uid && (string) $first === (string) $previous_first_uid) {
                $this->logger->log_item($run_id, 'student', null, null, null, 'skipped', 'failed', 'Oracle bridge returned the same students page again; stopping to avoid duplicate pagination loop.');
                break;
            }
            $previous_first_uid = $first;

            foreach ($students as $student) {
                $received++;
                if (!is_array($student)) {
                    $this->logger->log_item($run_id, 'student', null, null, null, 'skipped', 'success', 'Student record is not a valid object.');
                    continue;
                }

                if ((!isset($student['study_year']) || $student['study_year'] === '') && $study_year) {
                    $student['study_year'] = $study_year;
                }

                $family_id = $this->first($student, array('family_id', 'oracle_family_id'));
                $student_id = $this->first($student, array('student_id', 'oracle_student_id'));
                $student_uid = 'ORA-STU-' . $family_id . '-' . $student_id;

                if (!$family_id || !olama_core()->families()->get_by_oracle_id($family_id)) {
                    $this->logger->log_item($run_id, 'student', $student_uid, $family_id, $student_id, 'skipped', 'success', 'Matching Core family not found');
                    continue;
                }

                $import_result = $this->import_record($student, $run_id, '/api/students', $study_year, false);
                $this->merge_summary($summary, $import_result);
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

            $offset += $limit;
        } while (count($students) === $limit);

        $this->logger->finish_run($run_id);
        return array('success' => true, 'message' => $this->summary_message('Students by study year import finished. Records received: ' . $received . '.', $summary, $study_year, 0, $limit), 'run_id' => $run_id, 'study_year' => $study_year);
    }

    private function import_record(array $student, $run_id, $endpoint, $study_year, $log_items = true) {
        $study_year = $this->resolve_study_year($study_year);
        $family_id = $this->first($student, array('family_id', 'oracle_family_id'));
        $student_id = $this->first($student, array('student_id', 'oracle_student_id'));
        $student_uid = 'ORA-STU-' . $family_id . '-' . $student_id;

        try {
            if (!olama_core()->families()->get_by_oracle_id($family_id)) {
                throw new RuntimeException('Matching Core family not found.');
            }

            $student_data = array(
                'oracle_family_id' => $family_id,
                'oracle_student_id' => $student_id,
                'student_name' => $this->first($student, array('student_name', 'name', 'full_name')),
                'student_national_no' => $this->first($student, array('student_national_no', 'national_no', 'national_number')),
                'student_gender' => $this->first($student, array('student_gender', 'gender')),
                'student_gender_name' => $this->first($student, array('student_gender_name', 'gender_name')),
                'student_mobile' => $this->first($student, array('student_mobile', 'mobile')),
                'mother_mobile' => $this->first($student, array('mother_mobile')),
                'student_status' => $this->first($student, array('student_status', 'status')),
                'student_status_name' => $this->first($student, array('student_status_name', 'status_name')),
                'raw' => $student,
            );
            $student_result = olama_core()->students()->upsert_from_source($student_data);
            if ($log_items) {
                $this->logger->log_item($run_id, 'student', $student_result['uid'], $family_id, $student_id, $student_result['operation'], 'success', 'Student ' . $student_result['operation']);
            }

            $summary = $this->empty_summary();
            $summary['students'] = 1;
            if ('created' === $student_result['operation']) {
                $summary['students_created']++;
            } elseif ('updated' === $student_result['operation']) {
                $summary['students_updated']++;
            } elseif ('skipped' === $student_result['operation']) {
                $summary['students_skipped']++;
            }
            $operation = $student_result['operation'];
            foreach ($this->student_year_records($student, $study_year) as $year_data) {
                $year_data = array_merge($year_data, array(
                    'oracle_family_id' => $family_id,
                    'oracle_student_id' => $student_id,
                    'raw' => isset($year_data['raw']) ? $year_data['raw'] : $student,
                ));

                $year_result = olama_core()->student_years()->upsert_from_source($year_data);
                $operation = $year_result['operation'];
                $summary['student_years']++;
                if ('created' === $year_result['operation']) {
                    $summary['student_years_created']++;
                } elseif ('updated' === $year_result['operation']) {
                    $summary['student_years_updated']++;
                } elseif ('skipped' === $year_result['operation']) {
                    $summary['student_years_skipped']++;
                }
                if ($log_items) {
                    $this->logger->log_item($run_id, 'student_year', $year_result['uid'], $family_id, $student_id, $year_result['operation'], 'success', 'Student year ' . $year_result['operation']);
                }
            }

            $this->logger->store_payload('student', $family_id, $student_id, $endpoint, $student);
            return array('operation' => $operation, 'status' => 'success', 'message' => 'Student by study year ' . $operation, 'summary' => $summary);
        } catch (Exception $e) {
            if ($log_items) {
                $this->logger->log_item($run_id, 'student', $student_uid, $family_id, $student_id, 'failed', 'failed', $e->getMessage());
            }
            $summary = $this->empty_summary();
            $summary['failed'] = 1;
            return array('operation' => 'failed', 'status' => 'failed', 'message' => $e->getMessage(), 'summary' => $summary);
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

    private function extract_students_from_card($data) {
        $students = array();
        foreach (array('students', 'family_students', 'children') as $key) {
            $students = array_merge($students, $this->extract_list($data, $key));
        }
        if (isset($data['data']) && is_array($data['data'])) {
            foreach (array('students', 'family_students', 'children') as $key) {
                $students = array_merge($students, $this->extract_list($data['data'], $key));
            }
        }
        if (isset($data['family_card']) && is_array($data['family_card'])) {
            foreach (array('students', 'family_students', 'children') as $key) {
                $students = array_merge($students, $this->extract_list($data['family_card'], $key));
            }
        }

        return $students;
    }

    private function student_year_records($student, $study_year) {
        $study_year = $this->resolve_study_year($study_year);
        $records = array();
        foreach (array('student_years', 'academic_years', 'academic_records', 'years') as $key) {
            if (isset($student[$key]) && is_array($student[$key])) {
                foreach ($student[$key] as $item) {
                    if (is_array($item)) {
                        $records[] = $item;
                    }
                }
            }
        }

        if (!$records) {
            if ($study_year) {
                $records[] = $student;
            }
        }

        $normalized = array();
        foreach ($records as $record) {
            $normalized[] = array(
                'study_year' => $study_year,
                'school_id' => $this->first($record, array('school_id')),
                'school_name' => $this->first($record, array('school_name')),
                'class_id' => $this->first($record, array('class_id')),
                'class_name' => $this->first($record, array('class_name')),
                'section_id' => $this->first($record, array('section_id')),
                'section_name' => $this->first($record, array('section_name')),
                'student_status' => $this->first($record, array('student_status', 'student_year_status', 'status')),
                'student_status_name' => $this->first($record, array('student_status_name', 'student_year_status_name', 'status_name')),
                'student_year_status' => $this->first($record, array('student_year_status', 'student_status', 'status')),
                'registration_date' => $this->first($record, array('registration_date', 'register_date', 'date_registered')),
                'withdraw_date' => $this->first($record, array('withdraw_date', 'withdrawal_date')),
                'raw' => $record,
            );
        }

        return array_filter($normalized, function($record) {
            return !empty($record['study_year']);
        });
    }

    private function first($data, $keys, $default = '') {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return $default;
    }

    private function merge_summary(&$summary, $result) {
        if (isset($result['summary']) && is_array($result['summary'])) {
            $result = $result['summary'];
        }

        foreach (array('families', 'students', 'students_created', 'students_updated', 'students_skipped', 'student_years', 'student_years_created', 'student_years_updated', 'student_years_skipped', 'failed') as $key) {
            if (isset($result[$key])) {
                $summary[$key] = isset($summary[$key]) ? $summary[$key] + (int) $result[$key] : (int) $result[$key];
            }
        }
    }

    private function summary_message($prefix, $summary, $study_year, $offset, $limit) {
        $parts = array(
            $prefix,
            'Study year used: ' . $study_year . '.',
        );

        if (null !== $offset) {
            $parts[] = 'Offset: ' . (int) $offset . '.';
        }
        if (null !== $limit) {
            $parts[] = 'Limit: ' . (int) $limit . '.';
        }

        $parts[] = 'Families processed: ' . (isset($summary['families']) ? (int) $summary['families'] : 0) . '.';
        $parts[] = 'Students inserted: ' . (isset($summary['students_created']) ? (int) $summary['students_created'] : 0) . '.';
        $parts[] = 'Students updated: ' . (isset($summary['students_updated']) ? (int) $summary['students_updated'] : 0) . '.';
        $parts[] = 'Student-year rows inserted: ' . (isset($summary['student_years_created']) ? (int) $summary['student_years_created'] : 0) . '.';
        $parts[] = 'Student-year rows updated: ' . (isset($summary['student_years_updated']) ? (int) $summary['student_years_updated'] : 0) . '.';
        $parts[] = 'Errors: ' . (isset($summary['failed']) ? (int) $summary['failed'] : 0) . '.';

        return implode(' ', $parts);
    }

    private function empty_summary() {
        return array(
            'families' => 0,
            'students' => 0,
            'students_created' => 0,
            'students_updated' => 0,
            'students_skipped' => 0,
            'student_years' => 0,
            'student_years_created' => 0,
            'student_years_updated' => 0,
            'student_years_skipped' => 0,
            'failed' => 0,
        );
    }

    private function resolve_study_year($study_year = null) {
        $study_year = sanitize_text_field((string) $study_year);
        if ('' === $study_year) {
            $study_year = sanitize_text_field((string) Olama_Oracle_Settings::get('default_study_year'));
        }

        return $study_year;
    }
}
