<?php
/*
Plugin Name: QR Code Tracker
Description: Generate and track QR code links with query strings, including scan tracking and postcode rollups, plus dynamic HTML messages via shortcodes.
Version: 0.9994
Author: Ethan Widen
*/


if (!defined('ABSPATH')) exit;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-qr-code-db.php';
require_once __DIR__ . '/includes/class-qr-code-admin.php';
require_once __DIR__ . '/includes/class-qr-code-teams.php';
require_once __DIR__ . '/includes/class-qr-code-export.php';
require_once __DIR__ . '/includes/class-qr-code-report.php';
require_once __DIR__ . '/includes/class-qr-code-single-report.php';
require_once __DIR__ . '/includes/class-qr-code-city-report.php';
require_once __DIR__ . '/includes/class-qr-code-popup.php';
require_once __DIR__ . '/includes/class-qr-code-permissions.php';

// 1. Add QR code library import at the top (after class QRCodeTracker {)
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QRCodeTracker {
    private const CHRISTMAS_IDENTIFIER_LENGTH = 6;
    private const CHRISTMAS_FALLBACK_HASH_ALGO = 'sha256';
    private $main_table;
    private $log_table;
    private $current_tracker = null;
    private $admin;
    private $db;
    private $export;
    private $popup;
    private $teams;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';

        $this->db = new QRCodeTracker_DB();
        register_activation_hook(__FILE__, [$this->db, 'install']);
        register_uninstall_hook(__FILE__, ['QRCodeTracker_DB', 'uninstall']);
        add_action('plugins_loaded', [$this->db, 'maybe_upgrade_schema']);

        $this->teams = new QRCodeTracker_Teams();
        $this->admin = new QRCodeTracker_Admin($this, $this->teams);
        add_action('admin_menu', [$this->admin, 'admin_menu']);

        // Initialize permissions system
        new QRCodeTracker_Permissions();

        $this->export = new QRCodeTracker_Export();
        add_action('admin_action_qr_tracker_export', [$this->export, 'handle_csv_export']);

        // Initialize popup functionality
        $this->popup = new QRCodeTracker_Popup($this);

        add_action('wp', [$this, 'track_visit']);
        add_shortcode('qr_tracker_message_1', [$this, 'shortcode_message_1']);
        add_shortcode('qr_tracker_message_2', [$this, 'shortcode_message_2']);
        add_shortcode('qr_tracker_shop_link', [$this, 'shortcode_shop_link']);
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
        
        // First, try to match by extracting postcode, city, and tree from current URL
        $postcode = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        $tree = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        
        $row = null;
        if (!empty($postcode) && !empty($city) && !empty($tree)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE postcode = %s AND city = %s AND tree = %s", 
                $postcode, $city, $tree
            ));
        }
        
        // Fallback to exact URL matching for backward compatibility
        if (!$row) {
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
        }

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
        }
    }

    public function get_current_tracker() {
        if ($this->current_tracker !== null) {
            return $this->current_tracker;
        }

        global $wpdb;
        
        // First, try to match by extracting postcode, city, and tree from current URL
        $postcode = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        $tree = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        
        if (!empty($postcode) && !empty($city) && !empty($tree)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE postcode = %s AND city = %s AND tree = %s", 
                $postcode, $city, $tree
            ));
            if ($row) {
                $this->current_tracker = $row;
                return $row;
            }
        }
        
        // Fallback to exact URL matching for backward compatibility
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
        if ($tracker && !empty($tracker->message_1)) {
            return do_shortcode($tracker->message_1);
        }
        return '';
    }

    public function shortcode_message_2() {
        $tracker = $this->get_current_tracker();
        if ($tracker && !empty($tracker->message_2)) {
            return do_shortcode($tracker->message_2);
        }
        return do_shortcode('Thank you for visiting! Look below to receive a free book, make a comment, listen to a song, and watch a children\'s story.');
    }

    public function shortcode_shop_link($atts = []) {
        $tracker = $this->get_current_tracker();
        
        // Parse shortcode attributes
        $atts = shortcode_atts([
            'class' => 'qr-shop-link',
            'target' => '_blank',
            'max_width' => '200px'
        ], $atts, 'qr_tracker_shop_link');
        
        $output = '';
        
        // Check if we have QR code information
        if ($tracker) {
            // We have QR code information - check if shop link should be shown
            if (!$tracker->show_shop_link) {
                return '';
            }
            
            // Get shop link and logo from QR code entry, fallback to defaults
            $shop_link = !empty($tracker->shop_link) ? $tracker->shop_link : get_option('qr_tracker_default_shop_link', '');
            $shop_logo = !empty($tracker->shop_logo) ? $tracker->shop_logo : get_option('qr_tracker_default_shop_logo', '');
            
            if (empty($shop_link) || empty($shop_logo)) {
                return '';
            }
            
            // Add the Advent gifts banner
            $output .= '<div class="qr-advent-banner" style="background: #f7c334; padding: 15px; margin: 20px 0; text-align: center; border-radius: 4px;">';
            $output .= '<p style="margin: 0; color: #000; font-weight: bold; font-size: 16px;">For all your hand-crafted Advent gifts visit:</p>';
            
            // Add the shop link
            $output .= '<div class="' . esc_attr($atts['class']) . '">';
            $output .= '<a href="' . esc_url($shop_link) . '" target="' . esc_attr($atts['target']) . '" rel="noopener noreferrer">';
            $output .= '<img src="' . esc_url($shop_logo) . '" alt="Shop Logo" style="max-width: ' . esc_attr($atts['max_width']) . '; height: auto;">';
            $output .= '</a>';
            $output .= '</div>';
            $output .= '</div>';
            
        } else {
            // No QR code information - show banner and default shop link
            $shop_link = get_option('qr_tracker_default_shop_link', '');
            $shop_logo = get_option('qr_tracker_default_shop_logo', '');
            
            if (empty($shop_link) || empty($shop_logo)) {
                return '';
            }
            
            // Add the Advent gifts banner
            $output .= '<div class="qr-advent-banner" style="background: #f7c334; padding: 15px; margin: 20px 0; text-align: center; border-radius: 4px;">';
            $output .= '<p style="margin: 0; color: #000; font-weight: bold; font-size: 16px;">For all your hand-crafted Advent gifts visit:</p>';
            
            // Add the default shop link
            $output .= '<div class="' . esc_attr($atts['class']) . '">';
            $output .= '<a href="' . esc_url($shop_link) . '" target="' . esc_attr($atts['target']) . '" rel="noopener noreferrer">';
            $output .= '<img src="' . esc_url($shop_logo) . '" alt="Shop Logo" style="max-width: ' . esc_attr($atts['max_width']) . '; height: auto;">';
            $output .= '</a>';
            $output .= '</div>';
            $output .= '</div>';
        }
        
        return $output;
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
        if (!QRCodeTracker_Permissions::can_download_qr_codes()) {
            QRCodeTracker_Permissions::die_with_permission_error('qr_tracker_download_qr_codes');
        }
        global $wpdb;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!$id || !wp_verify_nonce($nonce, 'qr_tracker_download_qr_' . $id)) {
            wp_die('Invalid download request');
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $id));
        if (!$row) {
            wp_die('QR code not found');
        }
        $export_type = isset($_GET['export']) ? sanitize_key(wp_unslash($_GET['export'])) : '';
        $tiny_identifier = '';
        if ($export_type === 'christmas') {
            $tiny_identifier = $this->get_tiny_identifier($row);
        }
        $url = $export_type === 'christmas' ? $this->get_merry_christmas_qr_payload($tiny_identifier) : $row->url;
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
        $filename = ($postcode ? $postcode : 'qr') . ($city ? '-' . $city : '') . ($tree ? '-' . $tree : '');
        if ($export_type === 'christmas') {
            $filename .= '-merry-christmas-' . $tiny_identifier;
        }
        $filename .= '.png';
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($imageData));
        echo $imageData;
        exit;
    }

    private function get_merry_christmas_qr_payload($tiny_identifier) {
        return 'Merry Christmas ' . $tiny_identifier;
    }

    private function get_tiny_identifier($row) {
        $id = isset($row->id) ? (int) $row->id : 0;

        if ($id > 0) {
            $identifier = strtoupper(base_convert((string) $id, 10, 36));
            if (strlen($identifier) > self::CHRISTMAS_IDENTIFIER_LENGTH) {
                return substr($identifier, -self::CHRISTMAS_IDENTIFIER_LENGTH);
            }
            return str_pad($identifier, self::CHRISTMAS_IDENTIFIER_LENGTH, '0', STR_PAD_LEFT);
        }

        $fallback_seed = implode('|', [
            (string) ($row->url ?? ''),
            (string) ($row->postcode ?? ''),
            (string) ($row->city ?? ''),
            (string) ($row->tree ?? ''),
            (string) ($row->label ?? ''),
            (string) ($row->reporting_id ?? ''),
        ]);
        return strtoupper(substr(hash(self::CHRISTMAS_FALLBACK_HASH_ALGO, $fallback_seed), 0, self::CHRISTMAS_IDENTIFIER_LENGTH));
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
