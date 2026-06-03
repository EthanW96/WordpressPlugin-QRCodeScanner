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
    private const SHORT_CODE_MIN_LENGTH = 6;
    private const SHORT_CODE_MAX_LENGTH = 16;
    private const EDIT_TOKEN_LENGTH = 32;
    private const EDIT_TOKEN_MIN_LENGTH = 16;
    private const EDIT_TOKEN_MAX_LENGTH = 64;

    private $main_table;
    private $log_table;
    private $access_requests_table;
    private $current_tracker = null;
    private $current_request_url = '';
    private $visit_debug = null;
    private $admin;
    private $db;
    private $export;
    private $popup;
    private $teams;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
        $this->access_requests_table = $wpdb->prefix . 'qr_tracker_access_requests';

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

        add_action('wp', [$this, 'track_visit'], 0);
        add_action('template_redirect', [$this, 'handle_legacy_short_code_redirect'], 0);
        add_action('template_redirect', [$this, 'handle_qr_management_request']);
        add_action('template_redirect', [$this, 'handle_anonymous_short_code_redirect']);
        add_action('wp_footer', [$this, 'render_visit_debug_message'], 99);
        add_shortcode('qr_tracker_message_1', [$this, 'shortcode_message_1']);
        add_shortcode('qr_tracker_message_2', [$this, 'shortcode_message_2']);
        add_shortcode('qr_tracker_shop_link', [$this, 'shortcode_shop_link']);
        add_action('admin_action_qr_tracker_download_qr', [$this, 'handle_download_qr']);
        $this->init_woocommerce_tree_fields();
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
        $visit_start = microtime(true);
        $scan_source = 'unknown';

        if (isset($_GET['_qr_redirect']) && $_GET['_qr_redirect'] === '1') {
            $scan_source = 'legacy_redirect';
            $this->set_visit_debug(
                true,
                'Visit already recorded during legacy short-code redirect.',
                $scan_source,
                $visit_start
            );
            return;
        }

        // Preferred matching by unique short code.
        $short_code = isset($_GET['qr']) ? sanitize_text_field($_GET['qr']) : '';
        if (!empty($short_code)) {
            $scan_source = 'query_short_code';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE short_code = %s",
                $short_code
            ));
        } else {
            $row = null;
        }

        if (!$row) {
            $path_short_code = $this->get_path_short_code_from_request();
            if (!empty($path_short_code) && function_exists('is_404') && is_404()) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->main_table} WHERE short_code = %s",
                    $path_short_code
                ));
                if ($row) {
                    $scan_source = 'legacy_path_short_code';
                }
            }
        }
        
        // First, try to match by extracting postcode, city, and tree from current URL
        $postcode = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        $tree = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        
        if (!$row && !empty($postcode) && !empty($city) && !empty($tree)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE postcode = %s AND city = %s AND tree = %s", 
                $postcode, $city, $tree
            ));
            if ($row) {
                $scan_source = 'postcode_city_tree';
            }
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
            if ($row) {
                $scan_source = 'legacy_url_match';
            }
        }

        if ($row) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $request_uri = function_exists('mb_substr')
                ? mb_substr($request_uri, 0, 255, 'UTF-8')
                : substr($request_uri, 0, 255);
            $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
            $visitor_hash = hash('sha256', $remote_addr . '|' . $user_agent);

            $update_result = $wpdb->update(
                $this->main_table,
                [
                    'scan_count' => $row->scan_count + 1,
                    'last_scanned' => current_time('mysql', 1)
                ],
                ['id' => $row->id]
            );

            $insert_result = $wpdb->insert($this->log_table, [
                'tracker_id' => $row->id,
                'postcode' => $row->postcode,
                'city' => $row->city,
                'tree' => $row->tree,
                'scanned_at' => current_time('mysql', 1),
                'scan_source' => $scan_source,
                'request_uri' => $request_uri,
                'visitor_hash' => $visitor_hash
            ]);

            $recorded = ($update_result !== false && $insert_result !== false);
            if ($recorded) {
                $this->set_visit_debug(true, 'Visit recorded successfully.', $scan_source, $visit_start);
            } else {
                $error_message = !empty($wpdb->last_error) ? $wpdb->last_error : 'Unknown database error.';
                $this->set_visit_debug(false, 'Visit recording failed: ' . $error_message, $scan_source, $visit_start);
            }
            $this->current_tracker = $row;
            return;
        }

        $this->set_visit_debug(false, 'No matching QR code was found for this request.', $scan_source, $visit_start);
    }

    public function handle_legacy_short_code_redirect() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (isset($_GET['qr']) && $_GET['qr'] !== '') {
            return;
        }

        $postcode = isset($_GET['postcode']) ? sanitize_text_field(wp_unslash($_GET['postcode'])) : '';
        $city = isset($_GET['city']) ? sanitize_text_field(wp_unslash($_GET['city'])) : '';
        $tree = isset($_GET['tree']) ? sanitize_text_field(wp_unslash($_GET['tree'])) : '';
        if ($postcode !== '' && $city !== '' && $tree !== '') {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
            $request_path = is_string($request_uri) ? wp_parse_url($request_uri, PHP_URL_PATH) : '';
            $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
            if (trim((string) $request_path, '/') === trim((string) $home_path, '/')) {
                global $wpdb;
                $short_code = $wpdb->get_var($wpdb->prepare(
                    "SELECT short_code FROM {$this->main_table} WHERE postcode = %s AND city = %s AND tree = %s LIMIT 1",
                    $postcode,
                    $city,
                    $tree
                ));
                if (empty($short_code)) {
                    return;
                }
                $short_url = $this->generate_anonymous_tracker_url($short_code);
                if (!empty($short_url)) {
                    $current_url = home_url(add_query_arg(null, null));
                    if (untrailingslashit($current_url) !== untrailingslashit($short_url)) {
                        wp_safe_redirect($short_url, 302);
                        exit;
                    }
                }
            }
            return;
        }

        if (!function_exists('is_404') || !is_404()) {
            return;
        }

        $short_code = $this->get_path_short_code_from_request();
        if (empty($short_code)) {
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT url FROM {$this->main_table} WHERE short_code = %s",
            $short_code
        ));

        if (!$row || empty($row->url)) {
            return;
        }

        $validated_url = wp_validate_redirect($row->url, '');
        if (empty($validated_url)) {
            return;
        }
    }

    private function get_path_short_code_from_request() {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }

        $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
        $path = parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        $trimmed_path = trim($path, '/');
        if ($trimmed_path === '' || strpos($trimmed_path, '/') !== false) {
            return '';
        }

        if ($trimmed_path === 'wp-admin' || $trimmed_path === 'wp-login.php' || $trimmed_path === 'wp-json') {
            return '';
        }

        return sanitize_text_field($trimmed_path);
    }

    private function set_visit_debug($success, $message, $scan_source, $visit_start) {
        if ((int) get_option('qr_tracker_debug_mode', 0) !== 1) {
            $this->visit_debug = null;
            return;
        }

        $duration_ms = (microtime(true) - $visit_start) * 1000;
        $this->visit_debug = [
            'success' => (bool) $success,
            'message' => (string) $message,
            'duration_ms' => round($duration_ms, 2),
            'scan_source' => (string) $scan_source,
        ];
    }

    public function render_visit_debug_message() {
        if ((int) get_option('qr_tracker_debug_mode', 0) !== 1 || empty($this->visit_debug) || is_admin()) {
            return;
        }

        $status = $this->visit_debug['success'] ? 'SUCCESS' : 'FAILED';
        $background = $this->visit_debug['success'] ? '#e7f7ed' : '#fdeaea';
        $border = $this->visit_debug['success'] ? '#46b450' : '#d63638';

        echo '<div style="position:fixed;bottom:12px;left:12px;z-index:99999;max-width:520px;padding:10px 12px;background:' . esc_attr($background) . ';border:1px solid ' . esc_attr($border) . ';border-radius:4px;font-size:12px;line-height:1.4;box-shadow:0 1px 4px rgba(0,0,0,0.12);">';
        echo '<strong>QR Tracker Debug:</strong> ' . esc_html($status) . '<br>';
        echo 'Source: ' . esc_html($this->visit_debug['scan_source']) . ' | DB time: ' . esc_html(number_format((float) $this->visit_debug['duration_ms'], 2)) . ' ms<br>';
        echo esc_html($this->visit_debug['message']);
        echo '</div>';
    }

    public function get_current_tracker() {
        if ($this->current_tracker !== null) {
            return $this->current_tracker;
        }

        global $wpdb;

        // Preferred matching by unique short code.
        $short_code = isset($_GET['qr']) ? sanitize_text_field($_GET['qr']) : '';
        if (!empty($short_code)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE short_code = %s",
                $short_code
            ));
            if ($row) {
                $this->current_tracker = $row;
                return $row;
            }
        }
        
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

    public function generate_tracker_url($postcode, $city, $tree, $short_code = '') {
        $base = home_url('/');
        if (empty($short_code)) {
            $short_code = $this->generate_unique_short_code();
        }
        $params = ['qr' => $short_code];
        return add_query_arg($params, $base);
    }

    public function generate_anonymous_tracker_url($short_code) {
        $short_code = strtolower(sanitize_text_field((string) $short_code));
        if (empty($short_code)) {
            return '';
        }
        return home_url('/' . rawurlencode($short_code));
    }

    public function generate_team_management_url($team_id) {
        $team_id = (int) $team_id;
        if ($team_id <= 0) {
            return '';
        }

        $signature = hash_hmac('sha256', 'qr-team-manage:' . $team_id, wp_salt('auth'));
        return add_query_arg(['qr_manage_team' => $team_id . '-' . $signature], home_url('/'));
    }

    private function parse_team_management_token($team_management_token) {
        $team_management_token = sanitize_text_field((string) $team_management_token);
        if (!preg_match('/^([1-9][0-9]*)-([a-f0-9]{64})$/', $team_management_token, $matches)) {
            return 0;
        }

        $team_id = (int) $matches[1];
        $expected_signature = hash_hmac('sha256', 'qr-team-manage:' . $team_id, wp_salt('auth'));
        if (!hash_equals($expected_signature, $matches[2])) {
            return 0;
        }

        return $team_id;
    }

    public function generate_unique_edit_token($length = self::EDIT_TOKEN_LENGTH) {
        global $wpdb;

        do {
            $token = strtolower(wp_generate_password($length, false, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->main_table} WHERE edit_token = %s LIMIT 1",
                $token
            ));
        } while ($exists);

        return $token;
    }

    private function get_short_code_from_request_path() {
        $this->current_request_url = '';

        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }

        $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
        $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($request_path)) {
            return '';
        }
        $request_query = wp_parse_url($request_uri, PHP_URL_QUERY);
        $this->current_request_url = home_url($request_path . ($request_query ? '?' . $request_query : ''));

        $request_path = trim($request_path, '/');
        if ($request_path === '') {
            return '';
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($home_path)) {
            $home_path = trim($home_path, '/');
            if ($home_path !== '') {
                if (strpos($request_path, $home_path . '/') === 0) {
                    $request_path = substr($request_path, strlen($home_path) + 1);
                } elseif ($request_path === $home_path) {
                    $request_path = '';
                }
            }
        }

        if ($request_path === '' || strpos($request_path, '/') !== false) {
            return '';
        }

        $short_code_pattern = '/^[a-z0-9]{' . self::SHORT_CODE_MIN_LENGTH . ',' . self::SHORT_CODE_MAX_LENGTH . '}$/';
        if (!preg_match($short_code_pattern, $request_path)) {
            return '';
        }

        return strtolower($request_path);
    }

    public function handle_anonymous_short_code_redirect() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        if (isset($_GET['qr']) || isset($_GET['postcode']) || isset($_GET['city']) || isset($_GET['tree']) || isset($_GET['qr_manage']) || isset($_GET['qr_manage_team'])) {
            return;
        }

        $short_code = $this->get_short_code_from_request_path();
        if (empty($short_code)) {
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT url FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
            $short_code
        ));

        if (!$row || empty($row->url)) {
            return;
        }

        $current_url = $this->current_request_url;
        if (empty($current_url)) {
            return;
        }
        $stored_url = esc_url_raw($row->url);

        if (empty($stored_url)) {
            return;
        }

        // Prevent redirect loops when the anonymous URL already resolves to the stored URL.
        if (untrailingslashit($current_url) === untrailingslashit($stored_url)) {
            return;
        }

        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $stored_host = wp_parse_url($stored_url, PHP_URL_HOST);
        $is_same_site_host = false;
        if (empty($stored_host)) {
            // Relative URLs are same-site by definition.
            $is_same_site_host = true;
        } elseif (!empty($site_host) && strcasecmp($site_host, $stored_host) === 0) {
            $is_same_site_host = true;
        }

        if ($is_same_site_host) {
            $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
            $stored_path = trim((string) wp_parse_url($stored_url, PHP_URL_PATH), '/');

            // Keep the browser URL short when the resolved target stays on the site's home route.
            if ($stored_path === '' || $stored_path === $home_path) {
                $stored_query = wp_parse_url($stored_url, PHP_URL_QUERY);
                if (is_string($stored_query) && $stored_query !== '') {
                    $stored_params = [];
                    parse_str($stored_query, $stored_params);
                    if (!empty($stored_params)) {
                        $allowed_params = ['qr', 'postcode', 'city', 'tree'];
                        foreach ($stored_params as $key => $value) {
                            $sanitized_key = sanitize_key((string) $key);
                            if (
                                $sanitized_key === '' ||
                                !in_array($sanitized_key, $allowed_params, true) ||
                                isset($_GET[$sanitized_key]) ||
                                is_array($value) ||
                                is_object($value)
                            ) {
                                continue;
                            }

                            $sanitized_value = sanitize_text_field((string) $value);
                            $_GET[$sanitized_key] = $sanitized_value;
                            set_query_var($sanitized_key, $sanitized_value);
                        }
                    }
                }

                if (function_exists('is_404') && is_404()) {
                    global $wp_query, $wp_the_query;
                    $queries_to_normalize = [$wp_query, $wp_the_query];
                    $normalized_query_vars = [];
                    foreach (['qr', 'postcode', 'city', 'tree'] as $query_key) {
                        if (!isset($_GET[$query_key]) || !is_scalar($_GET[$query_key])) {
                            continue;
                        }
                        $normalized_query_vars[$query_key] = sanitize_text_field(wp_unslash((string) $_GET[$query_key]));
                    }
                    if (get_option('show_on_front') === 'page') {
                        $front_page_id = (int) get_option('page_on_front');
                        if ($front_page_id > 0) {
                            $normalized_query_vars['page_id'] = $front_page_id;
                        }
                    }
                    foreach ($queries_to_normalize as $query_to_normalize) {
                        if (!($query_to_normalize instanceof WP_Query) || !method_exists($query_to_normalize, 'set_404')) {
                            continue;
                        }

                        $query_to_normalize->set_404(false);
                        // Re-parse as a home/front request so /{shortcode} renders the correct template instead of 404.
                        if (method_exists($query_to_normalize, 'parse_query')) {
                            $query_to_normalize->parse_query($normalized_query_vars);
                        }
                    }
                    status_header(200);
                }
                return;
            }
        }

        wp_safe_redirect($stored_url, 302);
        exit;
    }

    public function handle_qr_management_request() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $team_management_token = isset($_GET['qr_manage_team']) ? sanitize_text_field(wp_unslash($_GET['qr_manage_team'])) : '';
        if (empty($team_management_token)) {
            if (isset($_GET['qr_manage'])) {
                wp_die('Individual QR management links are no longer supported. Please contact your team administrator to obtain the team management link.', 'QR Code Manager', ['response' => 410]);
            }
            return;
        }

        $team_id = $this->parse_team_management_token($team_management_token);
        if ($team_id <= 0) {
            wp_die('Invalid team management link.', 'QR Code Manager', ['response' => 404]);
        }

        $team = $this->teams->get_team($team_id);
        if (!$team) {
            wp_die('Invalid team management link.', 'QR Code Manager', ['response' => 404]);
        }

        if (!is_user_logged_in()) {
            $request_url = esc_url_raw(add_query_arg([]));
            $login_url = wp_login_url($request_url);
            $register_url = add_query_arg(['redirect_to' => $request_url], wp_registration_url());
            $message = '<p>You must log in to manage this team.</p>';
            $message .= '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p>';
            if (get_option('users_can_register')) {
                $message .= '<p><a href="' . esc_url($register_url) . '">Create an account</a></p>';
                $message .= '<p>After creating an account, return to this link to request access.</p>';
            }
            wp_die($message, 'QR Code Manager', ['response' => 403]);
        }

        global $wpdb;
        $user_id = get_current_user_id();
        if (!$this->teams->user_can_access_team($user_id, (int) $team->id)) {
            $request_notice = '';
            $request_qr_code = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE team_id = %d ORDER BY id ASC LIMIT 1",
                (int) $team->id
            ));

            if (isset($_POST['qr_request_access_submit'])) {
                check_admin_referer('qr_manage_team_request_' . (int) $team->id);
                if ($request_qr_code) {
                    if ($this->submit_qr_access_request($request_qr_code, $user_id)) {
                        $request_notice = '<div class="notice notice-success" style="padding:10px;margin:10px 0;"><p>Your team access request was submitted and reviewers were notified.</p></div>';
                    } else {
                        $request_notice = '<div class="notice notice-error" style="padding:10px;margin:10px 0;"><p>Unable to submit your request. Please try again.</p></div>';
                    }
                } else {
                    $request_notice = '<div class="notice notice-error" style="padding:10px;margin:10px 0;"><p>Access cannot be requested for this team right now.</p></div>';
                }
            }
            $this->render_team_management_access_request_page($team, $request_qr_code, $request_notice);
            return;
        }

        $selected_qr_id = isset($_GET['team_qr_id']) ? absint($_GET['team_qr_id']) : 0;
        if ($selected_qr_id > 0) {
            $qr_code = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE id = %d AND team_id = %d LIMIT 1",
                $selected_qr_id,
                (int) $team->id
            ));
            if (!$qr_code) {
                wp_die('The selected QR code could not be found.', 'QR Code Manager', ['response' => 404]);
            }
            $this->render_team_qr_management_page($team, $qr_code);
            return;
        }

        $this->render_team_management_page($team);
    }

    /**
     * Render the team-scoped QR management form for a selected team QR code.
     *
     * @param object $team Team record object.
     * @param object $qr_code QR code record object that belongs to the team.
     * @return void
     */
    private function render_team_qr_management_page($team, $qr_code) {
        global $wpdb;

        $notice = '';
        if (isset($_POST['qr_manage_submit'])) {
            check_admin_referer('qr_manage_' . $qr_code->id);

            $update_data = [
                'label' => isset($_POST['qr_label']) ? sanitize_text_field(wp_unslash($_POST['qr_label'])) : '',
                'reporting_id' => isset($_POST['qr_reporting_id']) ? sanitize_text_field(wp_unslash($_POST['qr_reporting_id'])) : '',
                'message_1' => isset($_POST['qr_message_1']) ? wp_kses_post(wp_unslash($_POST['qr_message_1'])) : '',
                'message_2' => isset($_POST['qr_message_2']) ? wp_kses_post(wp_unslash($_POST['qr_message_2'])) : '',
                'show_popup' => isset($_POST['qr_show_popup']) ? 1 : 0,
                'shop_link' => isset($_POST['qr_shop_link']) ? esc_url_raw(wp_unslash($_POST['qr_shop_link'])) : '',
                'shop_logo' => isset($_POST['qr_shop_logo']) ? esc_url_raw(wp_unslash($_POST['qr_shop_logo'])) : '',
                'show_shop_link' => isset($_POST['qr_show_shop_link']) ? 1 : 0,
            ];

            $update_result = $wpdb->update($this->main_table, $update_data, ['id' => $qr_code->id]);
            if ($update_result === false) {
                $notice = '<div class="notice notice-error" style="padding:10px;margin:10px 0;"><p>Unable to update QR code details. Please try again.</p></div>';
            } else {
                $qr_code = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $qr_code->id));
                $notice = '<div class="notice notice-success" style="padding:10px;margin:10px 0;"><p>QR code details updated.</p></div>';
            }
        }

        status_header(200);
        nocache_headers();
        get_header();
        echo '<div class="wrap" style="max-width:900px;margin:40px auto;padding:20px;">';
        $back_link = $this->generate_team_management_url((int) $team->id);
        echo '<p><a href="' . esc_url($back_link) . '">← Back to Team QR Codes</a></p>';
        echo '<h1>Manage QR Code</h1>';
        echo '<p><strong>Team:</strong> ' . esc_html($team->name) . '</p>';
        echo '<p><strong>Postcode:</strong> ' . esc_html($qr_code->postcode) . ' &nbsp; <strong>City:</strong> ' . esc_html($qr_code->city) . ' &nbsp; <strong>Tree:</strong> ' . esc_html($qr_code->tree) . '</p>';
        echo wp_kses_post($notice);
        echo '<form method="post">';
        wp_nonce_field('qr_manage_' . $qr_code->id);
        echo '<table class="form-table">';
        echo '<tr><th><label for="qr_label">Label</label></th><td><input type="text" id="qr_label" name="qr_label" value="' . esc_attr($qr_code->label) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="qr_reporting_id">Reporting ID</label></th><td><input type="text" id="qr_reporting_id" name="qr_reporting_id" value="' . esc_attr($qr_code->reporting_id) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="qr_message_1">Message 1</label></th><td><textarea id="qr_message_1" name="qr_message_1" rows="6" class="large-text">' . esc_textarea($qr_code->message_1) . '</textarea></td></tr>';
        echo '<tr><th><label for="qr_message_2">Message 2</label></th><td><textarea id="qr_message_2" name="qr_message_2" rows="6" class="large-text">' . esc_textarea($qr_code->message_2) . '</textarea></td></tr>';
        echo '<tr><th><label for="qr_show_popup">Show Popup</label></th><td><input type="checkbox" id="qr_show_popup" name="qr_show_popup" value="1"' . checked((int) $qr_code->show_popup, 1, false) . '></td></tr>';
        echo '<tr><th><label for="qr_shop_link">Shop Link</label></th><td><input type="url" id="qr_shop_link" name="qr_shop_link" value="' . esc_attr($qr_code->shop_link) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="qr_shop_logo">Shop Logo URL</label></th><td><input type="url" id="qr_shop_logo" name="qr_shop_logo" value="' . esc_attr($qr_code->shop_logo) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="qr_show_shop_link">Show Shop Link</label></th><td><input type="checkbox" id="qr_show_shop_link" name="qr_show_shop_link" value="1"' . checked((int) $qr_code->show_shop_link, 1, false) . '></td></tr>';
        echo '</table>';
        echo '<p><button type="submit" name="qr_manage_submit" class="button button-primary">Update QR Code</button></p>';
        echo '</form></div>';
        get_footer();
        exit;
    }

    private function get_qr_access_request($qr_id, $user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->access_requests_table} WHERE qr_id = %d AND user_id = %d LIMIT 1",
            $qr_id,
            $user_id
        ));
    }

    private function submit_qr_access_request($qr_code, $user_id) {
        global $wpdb;

        $requested_at = current_time('mysql');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->access_requests_table}
                (qr_id, team_id, user_id, status, requested_at, reviewed_at, reviewed_by)
             VALUES (%d, %d, %d, 'pending', %s, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                team_id = %d,
                status = 'pending',
                requested_at = %s,
                reviewed_at = NULL,
                reviewed_by = NULL",
            (int) $qr_code->id,
            (int) $qr_code->team_id,
            (int) $user_id,
            $requested_at,
            (int) $qr_code->team_id,
            $requested_at
        ));

        if ($result === false) {
            return false;
        }

        $request_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->access_requests_table} WHERE qr_id = %d AND user_id = %d LIMIT 1",
            (int) $qr_code->id,
            (int) $user_id
        ));
        $user = get_userdata($user_id);
        if (!$user || $request_id <= 0) {
            return false;
        }

        $this->notify_access_request_managers($qr_code, $user, $request_id);
        return true;
    }

    private function notify_access_request_managers($qr_code, $user, $request_id) {
        $emails = [];
        $review_users = get_users([
            'role' => QRCodeTracker_Permissions::ACCESS_REQUEST_MANAGER_ROLE,
            'fields' => ['user_email'],
        ]);

        foreach ($review_users as $review_user) {
            if (!empty($review_user->user_email)) {
                $emails[] = sanitize_email($review_user->user_email);
            }
        }

        $emails = array_values(array_unique(array_filter($emails)));
        if (empty($emails)) {
            return;
        }

        $subject = sprintf('[%s] QR access request pending review', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $team_id = isset($qr_code->team_id) ? (int) $qr_code->team_id : 0;
        $management_url = $team_id > 0 ? $this->generate_team_management_url($team_id) : '';
        $team_name = '';
        if ($team_id > 0) {
            $team = $this->teams->get_team($team_id);
            if ($team && !empty($team->name)) {
                $team_name = $team->name;
            }
        }
        $team_tree_count = $this->get_team_tree_count($team_id);
        $review_url = admin_url('admin.php?page=qr-teams');
        $message = "A user requested access to manage a team.\n\n";
        $message .= 'Request ID: ' . $request_id . "\n";
        $message .= 'User: ' . $user->display_name . ' (' . $user->user_email . ")\n";
        if (!empty($team_name)) {
            $message .= 'Team: ' . $team_name . "\n";
        } elseif ($team_id > 0) {
            $message .= 'Team ID: ' . $team_id . "\n";
        }
        if ($team_id > 0) {
            $message .= 'Trees in Team: ' . $team_tree_count . "\n";
        }
        if (!empty($management_url)) {
            $message .= 'Team Management Link: ' . $management_url . "\n";
        }
        $message .= "\nReview requests in WordPress admin:\n" . $review_url . "\n";

        wp_mail($emails, $subject, $message);
    }

    /**
     * Get the number of trees (QR rows) currently assigned to a team.
     *
     * @param int $team_id Team ID.
     * @return int Returns 0 when team ID is invalid, no trees are assigned, or count lookup fails.
     */
    public function get_team_tree_count($team_id) {
        $team_id = (int) $team_id;
        if ($team_id <= 0) {
            return 0;
        }

        global $wpdb;
        $team_table = $wpdb->prefix . 'qr_tracker';
        $team_tree_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$team_table} WHERE team_id = %d",
            $team_id
        ));
        if ($team_tree_count === null || $team_tree_count === false) {
            return 0;
        }
        return (int) $team_tree_count;
    }

    private function render_team_management_access_request_page($team, $request_qr_code = null, $notice = '') {
        $user_id = get_current_user_id();
        $existing_request = null;
        if ($request_qr_code) {
            $existing_request = $this->get_qr_access_request((int) $request_qr_code->id, (int) $user_id);
        }

        status_header(403);
        nocache_headers();
        get_header();
        echo '<div class="wrap" style="max-width:900px;margin:40px auto;padding:20px;">';
        echo '<h1>Access Required</h1>';
        echo '<p>You do not currently have permission to manage this team.</p>';
        echo '<p><strong>Team:</strong> ' . esc_html($team->name) . '</p>';
        echo wp_kses_post($notice);

        if (!$request_qr_code) {
            echo '<p>Access requests are unavailable for this team right now.</p>';
            echo '</div>';
            get_footer();
            exit;
        }

        if ($existing_request && $existing_request->status === 'pending') {
            echo '<div class="notice notice-info" style="padding:10px;margin:10px 0;"><p>Your access request is pending review.</p></div>';
        } else {
            echo '<p>Request team access and a reviewer will assess it.</p>';
            echo '<form method="post">';
            wp_nonce_field('qr_manage_team_request_' . (int) $team->id);
            echo '<p><button type="submit" name="qr_request_access_submit" class="button button-primary">Request Team Access</button></p>';
            echo '</form>';
        }

        echo '</div>';
        get_footer();
        exit;
    }

    private function render_team_management_page($team) {
        $qr_codes = $this->teams->get_team_qr_codes((int) $team->id);
        $team_management_url = $this->generate_team_management_url((int) $team->id);

        status_header(200);
        nocache_headers();
        get_header();
        echo '<div class="wrap" style="max-width:900px;margin:40px auto;padding:20px;">';
        echo '<h1>Manage Team QR Codes</h1>';
        echo '<p><strong>Team:</strong> ' . esc_html($team->name) . '</p>';

        if (empty($qr_codes)) {
            echo '<p>No QR codes are currently assigned to this team.</p>';
            echo '</div>';
            get_footer();
            exit;
        }

        echo '<p>Select a QR code to edit its details.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Postcode</th><th>City</th><th>Tree</th><th>Label</th><th>Action</th></tr></thead><tbody>';
        foreach ($qr_codes as $qr_code) {
            $management_url = add_query_arg(['team_qr_id' => (int) $qr_code->id], $team_management_url);
            echo '<tr>';
            echo '<td>' . esc_html($qr_code->postcode) . '</td>';
            echo '<td>' . esc_html($qr_code->city) . '</td>';
            echo '<td>' . esc_html($qr_code->tree) . '</td>';
            echo '<td>' . esc_html($qr_code->label) . '</td>';
            echo '<td><a class="button button-primary" href="' . esc_url($management_url) . '">Manage QR Code</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        get_footer();
        exit;
    }

    public function generate_unique_short_code($length = self::SHORT_CODE_MIN_LENGTH) {
        global $wpdb;
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max_index = strlen($characters) - 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, $max_index)];
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
                $code
            ));
            if (!$exists) {
                return $code;
            }
        }

        do {
            $fallback = strtolower(wp_generate_password($length + 2, false, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
                $fallback
            ));
        } while ($exists);

        return $fallback;
    }

    private function init_woocommerce_tree_fields() {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_tree_checkout_fields']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_tree_checkout_fields'], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_tree_checkout_fields_to_cart'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_tree_checkout_fields_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_tree_checkout_fields_to_order_items'], 10, 3);
        add_action('woocommerce_order_status_completed', [$this, 'create_qr_records_for_completed_order'], 10, 1);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_legacy_tree_item_meta_keys']);
    }

    public function hide_legacy_tree_item_meta_keys($hidden_meta_keys) {
        $legacy_keys = [
            '_purchaser_type',
            '_contact_emails',
            '_report_emails',
            '_org_or_individual_name',
            '_referral_code',
            '_pay_forward_type',
            '_pay_forward_contact',
            '_qr_tree_postcode',
            '_qr_tree_city',
            '_qr_tree_tree',
            '_qr_tree_label',
            '_qr_tree_message_1',
            '_qr_tree_message_2',
            '_qr_tree_shop_link',
            '_qr_tree_shop_logo',
            '_qr_tree_show_shop_link',
        ];

        return array_unique(array_merge($hidden_meta_keys, $legacy_keys));
    }

    private function is_tree_product($product_id) {
        $configured_ids = get_option('qr_tracker_tree_product_ids', []);
        if (!is_array($configured_ids) || empty($configured_ids)) {
            return false;
        }

        $configured_ids = array_values(array_unique(array_filter(array_map('intval', $configured_ids))));
        if (in_array((int) $product_id, $configured_ids, true)) {
            return true;
        }

        $parent_id = wp_get_post_parent_id($product_id);
        return $parent_id ? in_array((int) $parent_id, $configured_ids, true) : false;
    }

    public function render_tree_checkout_fields() {
        if (!function_exists('woocommerce_form_field')) {
            return;
        }

        global $product;
        if (!$product || !$this->is_tree_product($product->get_id())) {
            return;
        }

        echo '<div class="advent-tree-fields">';

        woocommerce_form_field('purchaser_type', [
            'type'    => 'select',
            'class'   => ['form-row-wide'],
            'label'   => 'Purchasing as',
            'options' => [
                'individual' => 'Individual',
                'org'        => 'Organization',
            ],
        ], isset($_POST['purchaser_type']) ? sanitize_text_field(wp_unslash($_POST['purchaser_type'])) : 'individual');

        woocommerce_form_field('contact_emails', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Contact email(s)',
            'description' => 'Separate multiple emails with commas, spaces, or semicolons.',
            'required'    => true,
        ], isset($_POST['contact_emails']) ? sanitize_text_field(wp_unslash($_POST['contact_emails'])) : '');

        woocommerce_form_field('report_emails', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Report email(s)',
            'description' => 'Weekly report recipient(s), separated by commas, spaces, or semicolons.',
        ], isset($_POST['report_emails']) ? sanitize_text_field(wp_unslash($_POST['report_emails'])) : '');

        woocommerce_form_field('org_or_individual_name', [
            'type'     => 'text',
            'class'    => ['form-row-wide'],
            'label'    => 'Organization / Individual Name',
            'required' => true,
        ], isset($_POST['org_or_individual_name']) ? sanitize_text_field(wp_unslash($_POST['org_or_individual_name'])) : '');

        woocommerce_form_field('referral_code', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Referral Code',
            'description' => 'Optional sharable referral code.',
        ], isset($_POST['referral_code']) ? sanitize_text_field(wp_unslash($_POST['referral_code'])) : '');

        woocommerce_form_field('pay_forward_type', [
            'type'    => 'select',
            'class'   => ['form-row-wide'],
            'label'   => 'Pay a Tree Forward?',
            'options' => [
                ''         => 'No',
                'specific' => 'Yes - for a specific person',
                'general'  => 'Yes - for anyone',
            ],
        ], isset($_POST['pay_forward_type']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_type'])) : '');

        woocommerce_form_field('pay_forward_contact', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Pay Forward Recipient (email or name)',
            'placeholder' => 'Leave blank if general',
        ], isset($_POST['pay_forward_contact']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_contact'])) : '');

        woocommerce_form_field('qr_tree_postcode', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Postcode',
            'required'    => true,
        ], isset($_POST['qr_tree_postcode']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_postcode'])) : '');

        woocommerce_form_field('qr_tree_city', [
            'type'        => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'City',
            'required'    => true,
        ], isset($_POST['qr_tree_city']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_city'])) : '');

        woocommerce_form_field('qr_tree_tree', [
            'type'     => 'text',
            'class'    => ['form-row-wide'],
            'label'    => 'Tree',
            'required' => true,
        ], isset($_POST['qr_tree_tree']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_tree'])) : '');

        woocommerce_form_field('qr_tree_label', [
            'type'     => 'text',
            'class'       => ['form-row-wide'],
            'label'       => 'Label',
            'required'    => true,
        ], isset($_POST['qr_tree_label']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_label'])) : '');

        woocommerce_form_field('qr_tree_message_1', [
            'type'    => 'textarea',
            'class'   => ['form-row-wide'],
            'label'   => 'Message 1',
        ], isset($_POST['qr_tree_message_1']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_1'])) : '');

        woocommerce_form_field('qr_tree_message_2', [
            'type'    => 'textarea',
            'class'   => ['form-row-wide'],
            'label'   => 'Message 2',
        ], isset($_POST['qr_tree_message_2']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_2'])) : '');

        woocommerce_form_field('qr_tree_shop_link', [
            'type'        => 'url',
            'class'       => ['form-row-wide'],
            'label'       => 'Shop Link',
            'placeholder' => 'https://example.com/shop',
        ], isset($_POST['qr_tree_shop_link']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_link'])) : '');

        woocommerce_form_field('qr_tree_shop_logo', [
            'type'        => 'url',
            'class'       => ['form-row-wide'],
            'label'       => 'Shop Logo URL',
            'placeholder' => 'https://example.com/logo.png',
        ], isset($_POST['qr_tree_shop_logo']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_logo'])) : '');

        woocommerce_form_field('qr_tree_show_shop_link', [
            'type'    => 'checkbox',
            'class'   => ['form-row-wide'],
            'label'   => 'Display shop logo link for this QR code',
            'default' => 1,
        ], isset($_POST['qr_tree_show_shop_link']) ? 1 : 0);

        echo '</div>';
    }

    private function sanitize_email_list($raw_value) {
        $emails = preg_split('/[\s,;]+/', (string) $raw_value, -1, PREG_SPLIT_NO_EMPTY);
        $sanitized = [];
        foreach ($emails as $email) {
            $clean = sanitize_email($email);
            if (!empty($clean) && is_email($clean)) {
                $sanitized[] = $clean;
            }
        }
        return implode(', ', array_unique($sanitized));
    }

    public function validate_tree_checkout_fields($passed, $product_id) {
        if (!$this->is_tree_product($product_id)) {
            return $passed;
        }

        $name = isset($_POST['org_or_individual_name']) ? sanitize_text_field(wp_unslash($_POST['org_or_individual_name'])) : '';
        if (empty($name)) {
            wc_add_notice('Please enter an organization or individual name.', 'error');
            return false;
        }

        $contact_emails = isset($_POST['contact_emails']) ? $this->sanitize_email_list(wp_unslash($_POST['contact_emails'])) : '';
        if (empty($contact_emails)) {
            wc_add_notice('Please enter at least one valid contact email.', 'error');
            return false;
        }

        $report_emails_raw = isset($_POST['report_emails']) ? wp_unslash($_POST['report_emails']) : '';
        if (!empty($report_emails_raw) && empty($this->sanitize_email_list($report_emails_raw))) {
            wc_add_notice('Please enter valid report email(s).', 'error');
            return false;
        }

        $pay_forward_type = isset($_POST['pay_forward_type']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_type'])) : '';
        $pay_forward_contact = isset($_POST['pay_forward_contact']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_contact'])) : '';
        if ($pay_forward_type === 'specific' && empty($pay_forward_contact)) {
            wc_add_notice('Please provide a recipient for the pay-forward tree.', 'error');
            return false;
        }

        $postcode = isset($_POST['qr_tree_postcode']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['qr_tree_postcode']))) : '';
        if (empty($postcode)) {
            wc_add_notice('Please enter a postcode.', 'error');
            return false;
        }

        $city = isset($_POST['qr_tree_city']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_city'])) : '';
        if (empty($city)) {
            wc_add_notice('Please enter a city.', 'error');
            return false;
        }

        $tree = isset($_POST['qr_tree_tree']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_tree'])) : '';
        if (empty($tree)) {
            wc_add_notice('Please enter a tree value.', 'error');
            return false;
        }

        $label = isset($_POST['qr_tree_label']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_label'])) : '';
        if (empty($label)) {
            wc_add_notice('Please enter a label.', 'error');
            return false;
        }

        $shop_link = isset($_POST['qr_tree_shop_link']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_link'])) : '';
        if (!empty($shop_link) && !filter_var($shop_link, FILTER_VALIDATE_URL)) {
            wc_add_notice('Please provide a valid shop link URL.', 'error');
            return false;
        }

        $shop_logo = isset($_POST['qr_tree_shop_logo']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_logo'])) : '';
        if (!empty($shop_logo) && !filter_var($shop_logo, FILTER_VALIDATE_URL)) {
            wc_add_notice('Please provide a valid shop logo URL.', 'error');
            return false;
        }

        return $passed;
    }

    public function add_tree_checkout_fields_to_cart($cart_item_data, $product_id) {
        if (!$this->is_tree_product($product_id)) {
            return $cart_item_data;
        }

        $field_values = [
            'purchaser_type' => isset($_POST['purchaser_type']) ? sanitize_text_field(wp_unslash($_POST['purchaser_type'])) : '',
            'contact_emails' => isset($_POST['contact_emails']) ? $this->sanitize_email_list(wp_unslash($_POST['contact_emails'])) : '',
            'report_emails' => isset($_POST['report_emails']) ? $this->sanitize_email_list(wp_unslash($_POST['report_emails'])) : '',
            'org_or_individual_name' => isset($_POST['org_or_individual_name']) ? sanitize_text_field(wp_unslash($_POST['org_or_individual_name'])) : '',
            'referral_code' => isset($_POST['referral_code']) ? sanitize_text_field(wp_unslash($_POST['referral_code'])) : '',
            'pay_forward_type' => isset($_POST['pay_forward_type']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_type'])) : '',
            'pay_forward_contact' => isset($_POST['pay_forward_contact']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_contact'])) : '',
            'qr_tree_postcode' => isset($_POST['qr_tree_postcode']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['qr_tree_postcode']))) : '',
            'qr_tree_city' => isset($_POST['qr_tree_city']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_city'])) : '',
            'qr_tree_tree' => isset($_POST['qr_tree_tree']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_tree'])) : '',
            'qr_tree_label' => isset($_POST['qr_tree_label']) ? sanitize_text_field(wp_unslash($_POST['qr_tree_label'])) : '',
            'qr_tree_message_1' => isset($_POST['qr_tree_message_1']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_1'])) : '',
            'qr_tree_message_2' => isset($_POST['qr_tree_message_2']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_2'])) : '',
            'qr_tree_shop_link' => isset($_POST['qr_tree_shop_link']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_link'])) : '',
            'qr_tree_shop_logo' => isset($_POST['qr_tree_shop_logo']) ? esc_url_raw(wp_unslash($_POST['qr_tree_shop_logo'])) : '',
            'qr_tree_show_shop_link' => isset($_POST['qr_tree_show_shop_link']) ? 1 : 0,
        ];

        foreach ($field_values as $key => $value) {
            if ($value !== '' || $key === 'qr_tree_show_shop_link') {
                $cart_item_data[$key] = $value;
            }
        }

        return $cart_item_data;
    }

    public function display_tree_checkout_fields_in_cart($item_data, $cart_item) {
        $labels = [
            'purchaser_type' => 'Purchasing As',
            'contact_emails' => 'Contact Email(s)',
            'report_emails' => 'Report Email(s)',
            'org_or_individual_name' => 'Organization / Individual Name',
            'referral_code' => 'Referral Code',
            'pay_forward_type' => 'Pay Forward',
            'pay_forward_contact' => 'Pay Forward Recipient',
            'qr_tree_postcode' => 'Postcode',
            'qr_tree_city' => 'City',
            'qr_tree_tree' => 'Tree',
            'qr_tree_label' => 'Label',
            'qr_tree_message_1' => 'Message 1',
            'qr_tree_message_2' => 'Message 2',
            'qr_tree_shop_link' => 'Shop Link',
            'qr_tree_shop_logo' => 'Shop Logo URL',
            'qr_tree_show_shop_link' => 'Show Shop Link',
        ];

        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $cart_item) && $cart_item[$key] !== '') {
                $display_value = $key === 'qr_tree_show_shop_link'
                    ? (((int) $cart_item[$key]) === 1 ? 'Yes' : 'No')
                    : $cart_item[$key];
                $item_data[] = [
                    'key' => $label,
                    'value' => wp_kses_post($display_value),
                ];
            }
        }

        return $item_data;
    }

    public function save_tree_checkout_fields_to_order_items($item, $cart_item_key, $values) {
        $labels = [
            'purchaser_type' => 'Purchasing As',
            'contact_emails' => 'Contact Email(s)',
            'report_emails' => 'Report Email(s)',
            'org_or_individual_name' => 'Organization / Individual Name',
            'referral_code' => 'Referral Code',
            'pay_forward_type' => 'Pay Forward',
            'pay_forward_contact' => 'Pay Forward Recipient',
            'qr_tree_postcode' => 'Postcode',
            'qr_tree_city' => 'City',
            'qr_tree_tree' => 'Tree',
            'qr_tree_label' => 'Label',
            'qr_tree_message_1' => 'Message 1',
            'qr_tree_message_2' => 'Message 2',
            'qr_tree_shop_link' => 'Shop Link',
            'qr_tree_shop_logo' => 'Shop Logo URL',
            'qr_tree_show_shop_link' => 'Show Shop Link',
        ];

        foreach ($labels as $field => $label) {
            if (array_key_exists($field, $values) && $values[$field] !== '') {
                $stored_value = $field === 'qr_tree_show_shop_link' ? (int) $values[$field] : $values[$field];
                $display_value = $field === 'qr_tree_show_shop_link'
                    ? (((int) $stored_value) === 1 ? 'Yes' : 'No')
                    : $stored_value;
                $item->add_meta_data($label, $display_value);
                $item->add_meta_data('_' . $field, $stored_value);
            }
        }
    }

    private function get_tree_field_from_order_item($item, $field_key, $display_label = '') {
        $hidden_value = $item->get_meta('_' . $field_key, true);
        if ($hidden_value !== '') {
            return $hidden_value;
        }
        if ($display_label !== '') {
            return $item->get_meta($display_label, true);
        }
        return '';
    }

    private function insert_tree_qr_record($tree_data) {
        global $wpdb;

        $short_code = $this->generate_unique_short_code();
        $url = $this->generate_tracker_url($tree_data['postcode'], $tree_data['city'], $tree_data['tree'], $short_code);

        $wpdb->insert($this->main_table, [
            'url' => $url,
            'short_code' => $short_code,
            'edit_token' => $this->generate_unique_edit_token(),
            'postcode' => $tree_data['postcode'],
            'city' => $tree_data['city'],
            'tree' => $tree_data['tree'],
            'label' => $tree_data['label'],
            'message_1' => $tree_data['message_1'],
            'message_2' => $tree_data['message_2'],
            'shop_link' => $tree_data['shop_link'],
            'shop_logo' => $tree_data['shop_logo'],
            'show_shop_link' => $tree_data['show_shop_link'],
        ]);

        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    public function create_qr_records_for_completed_order($order_id) {
        if (empty($order_id)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_qr_tracker_rows_created', true)) {
            return;
        }

        $inserted_count = 0;

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $variation_id = (int) $item->get_variation_id();
            $product_id = $variation_id > 0 ? $variation_id : (int) $item->get_product_id();

            if (!$product_id || !$this->is_tree_product($product_id)) {
                continue;
            }

            $postcode = strtoupper(sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_postcode', 'Postcode')));
            $city = sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_city', 'City'));
            $tree = sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_tree', 'Tree'));
            $label = sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_label', 'Label'));
            $message_1 = wp_kses_post($this->get_tree_field_from_order_item($item, 'qr_tree_message_1', 'Message 1'));
            $message_2 = wp_kses_post($this->get_tree_field_from_order_item($item, 'qr_tree_message_2', 'Message 2'));
            $shop_link = esc_url_raw($this->get_tree_field_from_order_item($item, 'qr_tree_shop_link', 'Shop Link'));
            $shop_logo = esc_url_raw($this->get_tree_field_from_order_item($item, 'qr_tree_shop_logo', 'Shop Logo URL'));
            $show_shop_link_raw = $this->get_tree_field_from_order_item($item, 'qr_tree_show_shop_link', 'Show Shop Link');
            $show_shop_link = in_array(strtolower((string) $show_shop_link_raw), ['1', 'yes', 'true'], true) ? 1 : 0;

            if (empty($postcode) || empty($city) || empty($tree) || empty($label)) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            for ($i = 0; $i < $quantity; $i++) {
                $record_id = $this->insert_tree_qr_record([
                    'postcode' => $postcode,
                    'city' => $city,
                    'tree' => $tree,
                    'label' => $quantity > 1 ? $label . ' #' . ($i + 1) : $label,
                    'message_1' => $message_1,
                    'message_2' => $message_2,
                    'shop_link' => $shop_link,
                    'shop_logo' => $shop_logo,
                    'show_shop_link' => $show_shop_link,
                ]);

                if ($record_id > 0) {
                    $inserted_count++;
                }
            }
        }

        if ($inserted_count > 0) {
            $order->update_meta_data('_qr_tracker_rows_created', 1);
            $order->save();
        }
    }
}

new QRCodeTracker();
