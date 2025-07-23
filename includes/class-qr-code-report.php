<?php
// Report helper logic for QR Code Tracker

class QRCodeTracker_Report {
    /**
     * Get time series scan data for charting (dates and series) with data sampling for performance
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @param int $max_data_points Maximum data points to return (default 100)
     * @return array
     */
    public static function get_time_series_data($log_table, $main_table, $where_clause, $where_params, $max_data_points = 100) {
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
        
        // Calculate date range and determine sampling interval
        $start_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        $date_diff = $start_date->diff($end_date);
        $total_days = $date_diff->days + 1;
        
        // Determine sampling interval based on data size
        $sampling_interval = 1; // Default: daily
        if ($total_days > $max_data_points) {
            $sampling_interval = ceil($total_days / $max_data_points);
        }
        
        // Generate array of sampled dates
        $dates = [];
        $current_date = clone $start_date;
        $date_count = 0;
        while ($current_date <= $end_date && $date_count < $max_data_points) {
            $dates[] = $current_date->format('Y-m-d');
            $current_date->add(new DateInterval('P' . $sampling_interval . 'D'));
            $date_count++;
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
        
        // Use aggregation for better performance
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
        
        // Fill in missing dates with 0 values and apply sampling
        $processed_series_data = array();
        foreach ($series_data as $series_name => $values) {
            $new_values = array();
            $sampled_dates = [];
            $date_index = 0;
            
            foreach ($dates as $date) {
                $date_key = (string)$date;
                $new_values[$date_key] = isset($values[$date_key]) ? (int)$values[$date_key] : 0;
                $sampled_dates[] = $date;
                $date_index++;
                
                // Stop if we've reached max data points
                if ($date_index >= $max_data_points) {
                    break;
                }
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
            'series' => $series_arrays,
            'sampling_interval' => $sampling_interval,
            'total_days' => $total_days
        ];
    }

    /**
     * Get aggregated scan data for performance optimization
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @param int $max_series Maximum number of series to return
     * @return array
     */
    public static function get_aggregated_scan_data($log_table, $main_table, $where_clause, $where_params, $max_series = 20) {
        global $wpdb;
        
        // Get top series by scan count to limit data
        $top_series_sql = "SELECT 
            CASE 
                WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                ELSE l.postcode
            END as series_name,
            COUNT(*) as total_scans
        FROM $log_table l
        JOIN $main_table t ON l.tracker_id = t.id
        $where_clause
        GROUP BY series_name
        ORDER BY total_scans DESC
        LIMIT %d";
        
        $top_series_params = array_merge($where_params, [$max_series]);
        $top_series = $wpdb->get_results($wpdb->prepare($top_series_sql, $top_series_params));
        
        $top_series_names = array_column($top_series, 'series_name');
        $top_series_where = '';
        if (!empty($top_series_names)) {
            $placeholders = implode(',', array_fill(0, count($top_series_names), '%s'));
            $top_series_where = " AND (CASE 
                WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                ELSE l.postcode
            END) IN ($placeholders)";
        }
        
        return [
            'top_series' => $top_series_names,
            'series_where' => $top_series_where,
            'series_params' => $top_series_names
        ];
    }

    /**
     * Get scan counts by hour of day (all series combined) with performance optimization
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
     * Get scan counts by hour of day for each series (postcode or reporting_id) with performance optimization
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @param int $max_series Maximum number of series to return
     * @return array
     */
    public static function get_scan_hour_series_data($log_table, $main_table, $where_clause, $where_params, $max_series = 10) {
        global $wpdb;
        
        // First get top series to limit data
        $top_series_sql = "SELECT 
            CASE 
                WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                ELSE l.postcode
            END as series_name,
            COUNT(*) as total_scans
        FROM $log_table l
        JOIN $main_table t ON l.tracker_id = t.id
        $where_clause
        GROUP BY series_name
        ORDER BY total_scans DESC
        LIMIT %d";
        
        $top_series_params = array_merge($where_params, [$max_series]);
        $top_series = $wpdb->get_results($wpdb->prepare($top_series_sql, $top_series_params));
        
        if (empty($top_series)) {
            return [];
        }
        
        $top_series_names = array_column($top_series, 'series_name');
        $placeholders = implode(',', array_fill(0, count($top_series_names), '%s'));
        
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
                AND (CASE 
                    WHEN t.reporting_id IS NOT NULL AND t.reporting_id != '' THEN t.reporting_id
                    ELSE l.postcode
                END) IN ($placeholders)
                GROUP BY series_name, scan_hour
                ORDER BY series_name, scan_hour";
        
        $all_params = array_merge($where_params, $top_series_names);
        $results = $wpdb->get_results($wpdb->prepare($sql, $all_params));
        
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
    
    /**
     * Get summary statistics with performance optimization
     * @param string $log_table
     * @param string $main_table
     * @param string $where_clause
     * @param array $where_params
     * @return array
     */
    public static function get_summary_stats($log_table, $main_table, $where_clause, $where_params) {
        global $wpdb;
        
        $sql = "SELECT 
                    COUNT(*) as total_scans,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days,
                    COUNT(DISTINCT l.postcode) as unique_postcodes,
                    COUNT(DISTINCT CONCAT(l.postcode, ':', l.tree)) as unique_trees,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan
                FROM $log_table l
                {$where_clause}";
        
        if (!empty($where_params)) {
            $stats = $wpdb->get_row($wpdb->prepare($sql, $where_params));
        } else {
            $stats = $wpdb->get_row($sql);
        }
        
        return $stats;
    }
} 