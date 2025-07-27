<?php
/*
Plugin Name: QR Code Tracker
Description: Generate and track QR code links with query strings, including scan tracking and postcode rollups, plus dynamic HTML messages via shortcodes.
Version: 0.9993
Author: Ethan Widen & ChatGPT
*/


if (!defined('ABSPATH')) exit;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-qr-code-db.php';
require_once __DIR__ . '/includes/class-qr-code-admin.php';
require_once __DIR__ . '/includes/class-qr-code-export.php';
require_once __DIR__ . '/includes/class-qr-code-report.php';
require_once __DIR__ . '/includes/class-qr-code-single-report.php';
require_once __DIR__ . '/includes/class-qr-code-city-report.php';
require_once __DIR__ . '/includes/class-qr-code-popup.php';

// 1. Add QR code library import at the top (after class QRCodeTracker {)
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QRCodeTracker {
    private $main_table;
    private $log_table;
    private $current_tracker = null;
    private $admin;
    private $db;
    private $export;
    private $popup;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';

        $this->db = new QRCodeTracker_DB();
        register_activation_hook(__FILE__, [$this->db, 'install']);
        register_uninstall_hook(__FILE__, ['QRCodeTracker_DB', 'uninstall']);
        add_action('plugins_loaded', [$this->db, 'maybe_upgrade_schema']);

        $this->admin = new QRCodeTracker_Admin($this);
        add_action('admin_menu', [$this->admin, 'admin_menu']);

        $this->export = new QRCodeTracker_Export();
        add_action('admin_action_qr_tracker_export', [$this->export, 'handle_csv_export']);

        // Initialize popup functionality
        $this->popup = new QRCodeTracker_Popup($this);

        add_action('wp', [$this, 'track_visit']);
        add_shortcode('qr_tracker_message_1', [$this, 'shortcode_message_1']);
        add_shortcode('qr_tracker_message_2', [$this, 'shortcode_message_2']);
        add_action('admin_action_qr_tracker_download_qr', [$this, 'handle_download_qr']);
    }


    // private function handle_export($export_type, $group_type) {
    //     global $wpdb;
        
    //     // Get filter parameters
    //     $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    //     $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    //     $postcode_filter = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
    //     $tree_filter = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';

    //     // Build WHERE clause
    //     $where_clause = "WHERE 1=1";
    //     $where_params = [];
        
    //     if (!empty($date_from)) {
    //         $where_clause .= " AND l.scanned_at >= %s";
    //         $where_params[] = $date_from . ' 00:00:00';
    //     }
        
    //     if (!empty($date_to)) {
    //         $where_clause .= " AND l.scanned_at <= %s";
    //         $where_params[] = $date_to . ' 23:59:59';
    //     }
        
    //     if (!empty($postcode_filter)) {
    //         $where_clause .= " AND l.postcode = %s";
    //         $where_params[] = $postcode_filter;
    //     }
        
    //     if (!empty($tree_filter)) {
    //         $where_clause .= " AND l.tree = %s";
    //         $where_params[] = $tree_filter;
    //     }

    //     $filename = 'qr_tracker_report_' . $export_type . ($group_type ? '_' . $group_type : '') . '_' . date('Y-m-d_H-i-s') . '.csv';
        
    //     header('Content-Type: text/csv');
    //     header('Content-Disposition: attachment; filename="' . $filename . '"');
    //     header('Pragma: no-cache');
    //     header('Expires: 0');
        
    //     $output = fopen('php://output', 'w');
        
    //     if ($export_type == 'breakdown') {
    //         $this->export_breakdown_csv($output, $where_clause, $where_params, $group_type);
    //     } else {
    //         $this->export_rollup_csv($output, $where_clause, $where_params, $group_type);
    //     }
        
    //     fclose($output);
    //     exit;
    // }

    

    public function track_visit() {

        global $wpdb;
        
        // Get the current URL in multiple ways to ensure we catch it
        $current_url = home_url(add_query_arg(null, null));
        $request_uri = home_url($_SERVER['REQUEST_URI']);
        
        // Also try without trailing slash variations
        $current_url_no_slash = rtrim($current_url, '/');
        $request_uri_no_slash = rtrim($request_uri, '/');
        
        // Debug: Log the URLs being checked (only for admin users)
        if (current_user_can('manage_options')) {
            error_log("QR Tracker Debug - Current URL: " . $current_url);
            error_log("QR Tracker Debug - Request URI: " . $request_uri);
            error_log("QR Tracker Debug - Current URL no slash: " . $current_url_no_slash);
            error_log("QR Tracker Debug - Request URI no slash: " . $request_uri_no_slash);
        }
        
        // Find the exact URL match in the database - try multiple variations
        // Also try adding/removing trailing slash before query parameters
        $current_url_alt = str_replace('/?', '?', $current_url);
        $request_uri_alt = str_replace('/?', '?', $request_uri);
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->main_table} WHERE url = %s OR url = %s OR url = %s OR url = %s OR url = %s OR url = %s", 
            $current_url, $request_uri, $current_url_no_slash, $request_uri_no_slash, $current_url_alt, $request_uri_alt
        ));

        if ($row) {
            $wpdb->update(
                $this->main_table,
                [
                    'scan_count' => $row->scan_count + 1,
                    'last_scanned' => current_time('mysql', 1)
                ],
                ['id' => $row->id]
            );

            $wpdb->insert($this->log_table, [
                'tracker_id' => $row->id,
                'postcode' => $row->postcode,
                'city' => $row->city,
                'tree' => $row->tree,
                'scanned_at' => current_time('mysql', 1)
            ]);

            $this->current_tracker = $row;
            
            // Debug: Log successful match
            if (current_user_can('manage_options')) {
                error_log("QR Tracker Debug - Found match for tracker ID: " . $row->id);
            }
        } else {
            // Debug: Log when no match is found
            if (current_user_can('manage_options')) {
                error_log("QR Tracker Debug - No match found in database");
            }
        }
    }

    public function get_current_tracker() {
        if ($this->current_tracker !== null) {
            return $this->current_tracker;
        }

        global $wpdb;
        
        // Get the current URL in multiple ways to ensure we catch it
        $current_url = home_url(add_query_arg(null, null));
        $request_uri = home_url($_SERVER['REQUEST_URI']);
        
        // Also try without trailing slash variations
        $current_url_no_slash = rtrim($current_url, '/');
        $request_uri_no_slash = rtrim($request_uri, '/');
        
        // Find the exact URL match in the database - try multiple variations
        // Also try adding/removing trailing slash before query parameters
        $current_url_alt = str_replace('/?', '?', $current_url);
        $request_uri_alt = str_replace('/?', '?', $request_uri);
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->main_table} WHERE url = %s OR url = %s OR url = %s OR url = %s OR url = %s OR url = %s", 
            $current_url, $request_uri, $current_url_no_slash, $request_uri_no_slash, $current_url_alt, $request_uri_alt
        ));
        $this->current_tracker = $row;
        return $row;
    }

    public function shortcode_message_1() {
        $tracker = $this->get_current_tracker();
        return $tracker && !empty($tracker->message_1) ? $tracker->message_1 : '';
    }

    public function shortcode_message_2() {
        $tracker = $this->get_current_tracker();
        return $tracker && !empty($tracker->message_2) ? $tracker->message_2 : '';
    }



    // 3. Add a helper to generate a QR code image as a data URI
    public function generate_qr_code_image($url) {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 5,
            'imageBase64' => true
        ]);
        $qrcode = new QRCode($options);
        return $qrcode->render($url);
    }

    public function handle_download_qr() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        global $wpdb;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $id));
        if (!$row) {
            wp_die('QR code not found');
        }
        $url = $row->url;
        // High-res QR code (e.g., 2000x2000 px)
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 20,
            'imageBase64' => false
        ]);
        $qrcode = new QRCode($options);
        $imageData = $qrcode->render($url);
        // Sanitize filename parts
        $postcode = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->postcode);
        $city = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->city);
        $tree = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->tree);
        $filename = ($postcode ? $postcode : 'qr') . ($city ? '-' . $city : '') . ($tree ? '-' . $tree : '') . '.png';
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($imageData));
        echo $imageData;
        exit;
    }

    public function generate_tracker_url($postcode, $city, $tree) {
        $base = home_url('/');
        $params = [
            'postcode' => $postcode,
            'city' => $city,
            'tree' => $tree
        ];
        return add_query_arg($params, $base);
    }
}

new QRCodeTracker();
