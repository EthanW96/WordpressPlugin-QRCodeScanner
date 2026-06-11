<?php
// CSV export logic for QR Code Tracker
class QRCodeTracker_Export {
    private $main_table;
    private $log_table;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
    }

    public function handle_csv_export() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_export_data()) {
            QRCodeTracker_Permissions::die_with_permission_error('qr_tracker_export_data');
        }
        
        global $wpdb;
        
        // Get export parameters
        $export_type = isset($_GET['export_type']) ? sanitize_text_field($_GET['export_type']) : '';
        $group_type = isset($_GET['group_type']) ? sanitize_text_field($_GET['group_type']) : '';
        
        if (empty($export_type)) {
            wp_die('Missing export type parameter');
        }
        
        // For breakdown and rollup exports, group_type defaults to postcode
        if (in_array($export_type, ['breakdown', 'rollup']) && empty($group_type)) {
            $group_type = 'postcode';
        }
        
        // Get filter parameters
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $postcode_filter = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $tree_filter = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        $city_filter = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        // Build WHERE clause
        $where_clause = "WHERE 1=1";
        $where_params = [];
        
        if (!empty($date_from)) {
            $where_clause .= " AND l.scanned_at >= %s";
            $where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_clause .= " AND l.scanned_at <= %s";
            $where_params[] = $date_to . ' 23:59:59';
        }
        
        if (!empty($postcode_filter)) {
            $where_clause .= " AND l.postcode = %s";
            $where_params[] = $postcode_filter;
        }
        
        if (!empty($tree_filter)) {
            $where_clause .= " AND l.tree = %s";
            $where_params[] = $tree_filter;
        }

        if (!empty($city_filter)) {
            $where_clause .= " AND l.city = %s";
            $where_params[] = $city_filter;
        }

        $filename = 'qr_tracker_report_' . $export_type . ($group_type ? '_' . $group_type : '') . '_' . date('Y-m-d_H-i-s') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        if (in_array($export_type, ['breakdown', 'rollup'], true)) {
            $where_clause .= ' AND ' . QRCodeTracker::get_scan_only_log_condition('l');
        }

        if ($export_type == 'breakdown') {
            $this->export_breakdown_csv($output, $where_clause, $where_params, $group_type);
        } elseif ($export_type == 'rollup') {
            $this->export_rollup_csv($output, $where_clause, $where_params, $group_type);
        } elseif ($export_type == 'logs') {
            $this->export_logs_csv($output, $where_clause, $where_params);
        } elseif ($export_type == 'single_qr') {
            $this->export_single_qr_csv($output, $where_clause, $where_params);
        } elseif ($export_type == 'city') {
            $this->export_city_csv($output, $where_clause, $where_params);
        } elseif ($export_type == 'reporting_id') {
            $this->export_reporting_id_csv($output, $where_clause, $where_params);
        }
        
        fclose($output);
        exit;
    }
    private function export_breakdown_csv($output, $where_clause, $where_params, $group_type) {
        global $wpdb;
        
        // Default to postcode grouping if no group_type specified
        $group_type = $group_type ?: 'postcode';
        $group_field = $group_type == 'postcode' ? 'l.postcode' : 'l.tree';
        $group_label = $group_type == 'postcode' ? 'Postcode' : 'Tree';
        
        $sql = "SELECT 
                    l.postcode,
                    l.tree,
                    l.city,
                    {$group_field} as group_value,
                    t.reporting_id,
                    COUNT(*) as scan_count,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY {$group_field}, l.postcode, l.tree, l.city, t.reporting_id
                ORDER BY scan_count DESC";
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        // Write header
        fputcsv($output, ['Postcode', 'City', 'Tree', 'Reporting ID', $group_label, 'Total Scans', 'Unique Days', 'First Scan', 'Last Scan']);
        
        // Write data
        foreach ($results as $row) {
            fputcsv($output, [
                $row->postcode,
                $row->city,
                $row->tree,
                $row->reporting_id,
                $row->group_value,
                $row->scan_count,
                $row->unique_days,
                $row->first_scan,
                $row->last_scan
            ]);
        }
    }
    private function export_rollup_csv($output, $where_clause, $where_params, $group_type) {
        global $wpdb;
        // Default to postcode grouping if no group_type specified
        $group_type = $group_type ?: 'postcode';
        $group_field = $group_type == 'postcode' ? 'l.postcode' : 'l.tree';
        $other_field = $group_type == 'postcode' ? 'l.tree' : 'l.postcode';
        $group_label = $group_type == 'postcode' ? 'Postcode' : 'Tree';
        $other_label = $group_type == 'postcode' ? 'Tree' : 'Postcode';
        $sql = "SELECT 
                    {$group_field} as group_value,
                    {$other_field} as other_value,
                    l.city,
                    t.reporting_id,
                    COUNT(*) as scan_count,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY {$group_field}, {$other_field}, l.city, t.reporting_id
                ORDER BY {$group_field}, scan_count DESC";
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        // Write header
        fputcsv($output, ['Postcode', 'City', 'Tree', 'Reporting ID', $group_label, $other_label, 'Total Scans', 'Unique Days', 'First Scan', 'Last Scan']);
        // Write data
        foreach ($results as $row) {
            fputcsv($output, [
                $row->postcode,
                $row->city,
                $row->tree,
                $row->reporting_id,
                $row->group_value,
                $row->other_value,
                $row->scan_count,
                $row->unique_days,
                $row->first_scan,
                $row->last_scan
            ]);
        }
    }
    private function export_logs_csv($output, $where_clause, $where_params) {
        global $wpdb;
        
        // Get limit parameter
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5000;
        
        // Fix the WHERE clause to use correct table name instead of alias
        $where_clause = str_replace('l.scanned_at', 'scanned_at', $where_clause);
        $where_clause = str_replace('l.postcode', 'postcode', $where_clause);
        $where_clause = str_replace('l.city', 'city', $where_clause);
        $where_clause = str_replace('l.tree', 'tree', $where_clause);
        $where_clause = str_replace('l.scan_source', 'scan_source', $where_clause);
        
        $sql = "SELECT * FROM {$this->log_table} {$where_clause} ORDER BY scanned_at DESC LIMIT %d";
        $where_params[] = $limit;
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($wpdb->prepare($sql, [$limit]));
        }
        
        // Write header
        fputcsv($output, ['ID', 'Tracker ID', 'Postcode', 'City', 'Tree', 'Source', 'Scanned At']);
        
        // Write data
        foreach ($results as $row) {
            fputcsv($output, [
                $row->id,
                $row->tracker_id,
                $row->postcode,
                $row->city,
                $row->tree,
                $row->scan_source,
                $row->scanned_at
            ]);
        }
    }

    private function export_single_qr_csv($output, $where_clause, $where_params) {
            global $wpdb;
            
            // Get QR code ID from parameters
            $qr_id = isset($_GET['qr_id']) ? intval($_GET['qr_id']) : 0;
            
            if (!$qr_id) {
                wp_die('Missing QR code ID parameter');
            }
            
            // Get QR code details
            $qr_code = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE id = %d",
                $qr_id
            ));
            
            if (!$qr_code) {
                wp_die('QR code not found');
            }
            
            // Build WHERE clause for this specific QR code
            $single_where_clause = "WHERE l.tracker_id = %d";
            $single_where_params = [$qr_id];
            
            // Add date filters if provided
            $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
            $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
            
            if (!empty($date_from)) {
                $single_where_clause .= " AND l.scanned_at >= %s";
                $single_where_params[] = $date_from . ' 00:00:00';
            }
            
            if (!empty($date_to)) {
                $single_where_clause .= " AND l.scanned_at <= %s";
                $single_where_params[] = $date_to . ' 23:59:59';
            }
            
            // Get scan logs for this QR code
            $sql = "SELECT 
                        l.id,
                        l.scan_source,
                        l.scanned_at,
                        HOUR(l.scanned_at) as scan_hour,
                        DAYOFWEEK(l.scanned_at) as day_of_week,
                        DATE(l.scanned_at) as scan_date
                    FROM {$this->log_table} l
                    {$single_where_clause}
                    ORDER BY l.scanned_at DESC";
            
            $results = $wpdb->get_results($wpdb->prepare($sql, $single_where_params));
            
            // Write header
            fputcsv($output, [
                'QR Code ID',
                'Postcode',
                'City', 
                'Tree',
                'Label',
                'Reporting ID',
                'Scan ID',
                'Source',
                'Scanned At',
                'Scan Date',
                'Scan Hour',
                'Day of Week'
            ]);
            
            // Write data
            foreach ($results as $row) {
                $day_names = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $day_name = $day_names[$row->day_of_week] ?? 'Unknown';
                
                fputcsv($output, [
                    $qr_code->id,
                    $qr_code->postcode,
                    $qr_code->city,
                    $qr_code->tree,
                    $qr_code->label,
                    $qr_code->reporting_id,
                    $row->id,
                    $row->scan_source,
                    $row->scanned_at,
                    $row->scan_date,
                    $row->scan_hour,
                    $day_name
                ]);
            }
    }

    private function export_city_csv($output, $where_clause, $where_params) {
        global $wpdb;
        
        // Get city name from parameters
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        
        if (empty($city)) {
            wp_die('Missing city parameter');
        }
        
        // Build WHERE clause for this specific city
        $city_where_clause = "WHERE l.city = %s";
        $city_where_params = [$city];
        
        // Add date filters if provided
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        if (!empty($date_from)) {
            $city_where_clause .= " AND l.scanned_at >= %s";
            $city_where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $city_where_clause .= " AND l.scanned_at <= %s";
            $city_where_params[] = $date_to . ' 23:59:59';
        }
        
        // Get scan logs for this city
        $sql = "SELECT 
                    l.id,
                    l.scan_source,
                    l.scanned_at,
                    t.postcode,
                    t.tree,
                    t.label,
                    t.reporting_id,
                    HOUR(l.scanned_at) as scan_hour,
                    DAYOFWEEK(l.scanned_at) as day_of_week,
                    DATE(l.scanned_at) as scan_date
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$city_where_clause}
                ORDER BY l.scanned_at DESC";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $city_where_params));
        
        // Write header
        fputcsv($output, [
            'City',
            'Postcode',
            'Tree',
            'Label',
            'Reporting ID',
            'Scan ID',
            'Source',
            'Scanned At',
            'Scan Date',
            'Scan Hour',
            'Day of Week'
        ]);
        
        // Write data
        foreach ($results as $row) {
            $day_names = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $day_name = $day_names[$row->day_of_week] ?? 'Unknown';
            
            fputcsv($output, [
                $city,
                $row->postcode,
                $row->tree,
                $row->label,
                $row->reporting_id,
                $row->id,
                $row->scan_source,
                $row->scanned_at,
                $row->scan_date,
                $row->scan_hour,
                $day_name
            ]);
        }
    }

    private function export_reporting_id_csv($output, $where_clause, $where_params) {
        global $wpdb;
        
        // Get reporting ID from parameters
        $reporting_id = isset($_GET['reporting_id']) ? sanitize_text_field($_GET['reporting_id']) : '';
        
        if (empty($reporting_id)) {
            wp_die('Missing reporting ID parameter');
        }
        
        // Build WHERE clause for this specific reporting ID
        $reporting_id_where_clause = "WHERE t.reporting_id = %s";
        $reporting_id_where_params = [$reporting_id];
        
        // Add date filters if provided
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        if (!empty($date_from)) {
            $reporting_id_where_clause .= " AND l.scanned_at >= %s";
            $reporting_id_where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $reporting_id_where_clause .= " AND l.scanned_at <= %s";
            $reporting_id_where_params[] = $date_to . ' 23:59:59';
        }
        
        // Get scan logs for this reporting ID
        $sql = "SELECT 
                    l.id,
                    l.scan_source,
                    l.scanned_at,
                    t.postcode,
                    t.city,
                    t.tree,
                    t.label,
                    HOUR(l.scanned_at) as scan_hour,
                    DAYOFWEEK(l.scanned_at) as day_of_week,
                    DATE(l.scanned_at) as scan_date
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$reporting_id_where_clause}
                ORDER BY l.scanned_at DESC";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $reporting_id_where_params));
        
        // Write header
        fputcsv($output, [
            'Reporting ID',
            'Postcode',
            'City',
            'Tree',
            'Label',
            'Scan ID',
            'Source',
            'Scanned At',
            'Scan Date',
            'Scan Hour',
            'Day of Week'
        ]);
        
        // Write data
        foreach ($results as $row) {
            $day_names = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $day_name = $day_names[$row->day_of_week] ?? 'Unknown';
            
            fputcsv($output, [
                $reporting_id,
                $row->postcode,
                $row->city,
                $row->tree,
                $row->label,
                $row->id,
                $row->scan_source,
                $row->scanned_at,
                $row->scan_date,
                $row->scan_hour,
                $day_name
            ]);
        }
    }
} 