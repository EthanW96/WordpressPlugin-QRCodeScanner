<?php
// Report helper logic for QR Code Tracker

class QRCodeTracker_Report {
    /**
     * Get time series scan data for charting (dates and series)
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @return array
     */
    public static function get_time_series_data($log_table, $main_table, $where_clause, $where_params) {
        global $wpdb;
        // Get date range from filters
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        if (empty($date_from)) {
            $date_from = date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($date_to)) {
            $date_to = date('Y-m-d');
        }
        // Generate array of dates
        $dates = [];
        $current_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        while ($current_date <= $end_date) {
            $dates[] = $current_date->format('Y-m-d');
            $current_date->add(new DateInterval('P1D'));
        }
        // Build a clean WHERE clause for time series data
        $time_series_where = "WHERE DATE(l.scanned_at) >= %s AND DATE(l.scanned_at) <= %s";
        $time_series_params = [$date_from, $date_to];
        if (isset($_GET['postcode']) && !empty($_GET['postcode'])) {
            $time_series_where .= " AND l.postcode = %s";
            $time_series_params[] = sanitize_text_field($_GET['postcode']);
        }
        if (isset($_GET['tree']) && !empty($_GET['tree'])) {
            $time_series_where .= " AND l.tree = %s";
            $time_series_params[] = sanitize_text_field($_GET['tree']);
        }
        $sql = "SELECT 
                    DATE(l.scanned_at) as scan_date,
                    CASE 
                        WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                        ELSE t.postcode
                    END as series_name,
                    COUNT(*) as scan_count
                FROM $log_table l
                JOIN $main_table t ON l.tracker_id = t.id
                $time_series_where
                GROUP BY DATE(l.scanned_at), series_name
                ORDER BY DATE(l.scanned_at), series_name";
        $results = $wpdb->get_results($wpdb->prepare($sql, $time_series_params));
        // Organize data by series
        $series_data = [];
        $series_names = [];
        foreach ($results as $row) {
            $series_name = $row->series_name;
            if (empty($series_name)) continue;
            if (!in_array($series_name, $series_names)) {
                $series_names[] = $series_name;
            }
            if (!isset($series_data[$series_name])) {
                $series_data[$series_name] = [];
            }
            $date_key = (string)$row->scan_date;
            $series_data[$series_name][$date_key] = (int)$row->scan_count;
        }
        // Fill in missing dates with 0 values
        $processed_series_data = array();
        foreach ($series_data as $series_name => $values) {
            $new_values = array();
            foreach ($dates as $date) {
                $date_key = (string)$date;
                $new_values[$date_key] = isset($values[$date_key]) ? (int)$values[$date_key] : 0;
            }
            $processed_series_data[$series_name] = $new_values;
        }
        $series_data = $processed_series_data;
        // Convert to arrays for JavaScript
        $series_arrays = [];
        foreach ($series_names as $series_name) {
            $values = [];
            foreach ($dates as $date) {
                $date_key = (string)$date;
                $value = isset($series_data[$series_name][$date_key]) ? (int)$series_data[$series_name][$date_key] : 0;
                $values[] = $value;
            }
            $series_arrays[$series_name] = $values;
        }
        return [
            'dates' => $dates,
            'series' => $series_arrays
        ];
    }

    /**
     * Get scan counts by hour of day (all series combined)
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @return array
     */
    public static function get_scan_hour_data($log_table, $main_table, $where_clause, $where_params) {
        global $wpdb;
        $sql = "SELECT HOUR(l.scanned_at) as scan_hour, COUNT(*) as scan_count
                FROM $log_table l
                JOIN $main_table t ON l.tracker_id = t.id
                $where_clause
                GROUP BY scan_hour
                ORDER BY scan_hour";
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        $hour_data = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $hour = (int)$row->scan_hour;
            $hour_data[$hour] = (int)$row->scan_count;
        }
        return $hour_data;
    }

    /**
     * Get scan counts by hour of day for each series (postcode or reporting_id)
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @return array
     */
    public static function get_scan_hour_series_data($log_table, $main_table, $where_clause, $where_params) {
        global $wpdb;
        $sql = "SELECT 
                    CASE 
                        WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                        ELSE l.postcode
                    END as series_name,
                    HOUR(l.scanned_at) as scan_hour,
                    COUNT(*) as scan_count
                FROM $log_table l
                JOIN $main_table t ON l.tracker_id = t.id
                $where_clause
                GROUP BY series_name, scan_hour
                ORDER BY series_name, scan_hour";
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        $series_data = [];
        foreach ($results as $row) {
            $series = $row->series_name;
            $hour = (int)$row->scan_hour;
            if (!isset($series_data[$series])) {
                $series_data[$series] = array_fill(0, 24, 0);
            }
            $series_data[$series][$hour] = (int)$row->scan_count;
        }
        return $series_data;
    }
} 