<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Oracle_Validator {
    public function report() {
        global $wpdb;

        $families = $wpdb->prefix . 'olama_core_families';
        $students = $wpdb->prefix . 'olama_core_students';
        $years = $wpdb->prefix . 'olama_core_student_years';
        $items = $wpdb->prefix . 'olama_oracle_sync_items';
        $settings_year = Olama_Oracle_Settings::get('default_study_year');

        return array(
            'Core families count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($families) . '`'),
            'Core students count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($students) . '`'),
            'Core student years count' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($years) . '`'),
            'Students without matching family' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($students) . '` s LEFT JOIN `' . esc_sql($families) . '` f ON s.family_uid = f.family_uid WHERE f.id IS NULL'),
            'Student years without matching student' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($years) . '` y LEFT JOIN `' . esc_sql($students) . '` s ON y.student_uid = s.student_uid WHERE s.id IS NULL'),
            'Duplicate Oracle family IDs' => (int) $wpdb->get_var('SELECT COUNT(*) FROM (SELECT oracle_family_id FROM `' . esc_sql($families) . '` GROUP BY oracle_family_id HAVING COUNT(*) > 1) d'),
            'Duplicate Oracle student keys' => (int) $wpdb->get_var('SELECT COUNT(*) FROM (SELECT oracle_family_id, oracle_student_id FROM `' . esc_sql($students) . '` GROUP BY oracle_family_id, oracle_student_id HAVING COUNT(*) > 1) d'),
            'Families without students' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($families) . '` f LEFT JOIN `' . esc_sql($students) . '` s ON f.family_uid = s.family_uid WHERE s.id IS NULL'),
            'Missing student names' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($students) . "` WHERE student_name = '' OR student_name IS NULL"),
            'Missing class/section for current study year' => $this->missing_class_section($years, $settings_year),
            'Last failed sync items' => $wpdb->get_results("SELECT entity_type, entity_uid, oracle_family_id, oracle_student_id, operation, message, created_at FROM `" . esc_sql($items) . "` WHERE status = 'failed' ORDER BY id DESC LIMIT 20", ARRAY_A),
        );
    }

    private function missing_class_section($years, $study_year) {
        global $wpdb;

        if ($study_year) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `" . esc_sql($years) . "` WHERE study_year = %s AND (class_id IS NULL OR class_id = '' OR section_id IS NULL OR section_id = '')",
                $study_year
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($years) . "` WHERE class_id IS NULL OR class_id = '' OR section_id IS NULL OR section_id = ''");
    }
}
