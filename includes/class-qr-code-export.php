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
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        global $wpdb;
        
        // Get export parameters
        $export_type = isset($_GET['export_type']) ? sanitize_text_field($_GET['export_type']) : '';
        $group_type = isset($_GET['group_type']) ? sanitize_text_field($_GET['group_type']) : '';
        
        if (empty($export_type)) {
            wp_die('Missing export type parameter');
        }
        
        // For breakdown and rollup exports, group_type is required
        if (in_array($export_type, ['breakdown', 'rollup']) && empty($group_type)) {
            wp_die('Missing group type parameter for ' . $export_type . ' export');
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

        // Debug logging (only for admins)
        if (current_user_can('manage_options')) {
            error_log("QR Export Debug - Export Type: " . $export_type);
            error_log("QR Export Debug - Date From: " . $date_from);
            error_log("QR Export Debug - Date To: " . $date_to);
            error_log("QR Export Debug - Where Clause: " . $where_clause);
            error_log("QR Export Debug - Where Params: " . print_r($where_params, true));
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
        
        if ($export_type == 'breakdown') {
            $this->export_breakdown_csv($output, $where_clause, $where_params, $group_type);
        } elseif ($export_type == 'rollup') {
            $this->export_rollup_csv($output, $where_clause, $where_params, $group_type);
        } elseif ($export_type == 'logs') {
            $this->export_logs_csv($output, $where_clause, $where_params);
        }
        
        fclose($output);
        exit;
    }
    private function export_breakdown_csv($output, $where_clause, $where_params, $group_type) {
        global $wpdb;
        
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
        $where_clause = str_replace('l.tree', 'tree', $where_clause);
        
        $sql = "SELECT * FROM {$this->log_table} {$where_clause} ORDER BY scanned_at DESC LIMIT %d";
        $where_params[] = $limit;
        
        // Debug logging
        if (current_user_can('manage_options')) {
            error_log("QR Export Debug - SQL Query: " . $sql);
            error_log("QR Export Debug - SQL Params: " . print_r($where_params, true));
        }
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($wpdb->prepare($sql, [$limit]));
        }
        
        // Debug logging
        if (current_user_can('manage_options')) {
            error_log("QR Export Debug - Results Count: " . count($results));
            if (empty($results)) {
                error_log("QR Export Debug - No results found. Checking if table has data...");
                $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table}");
                error_log("QR Export Debug - Total logs in table: " . $total_logs);
            }
        }
        
        // Write header
        fputcsv($output, ['ID', 'Tracker ID', 'Postcode', 'City', 'Tree', 'Scanned At']);
        
        // Write data
        foreach ($results as $row) {
            fputcsv($output, [
                $row->id,
                $row->tracker_id,
                $row->postcode,
                $row->city,
                $row->tree,
                $row->scanned_at
            ]);
        }
    }
} 