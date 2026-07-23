<?php
/*
Plugin Name: QR Code Tracker
Description: Generate and track QR code links with query strings, including scan tracking and postcode rollups, plus dynamic HTML messages via shortcodes.
Version: 1.0.3
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
require_once __DIR__ . '/includes/class-qr-code-email.php';

// 1. Add QR code library import at the top (after class QRCodeTracker {)
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QRCodeTracker {
    private const SHORT_CODE_MIN_LENGTH = 6;
    private const SHORT_CODE_MAX_LENGTH = 16;
    private const EDIT_TOKEN_LENGTH = 32;
    private const EDIT_TOKEN_MIN_LENGTH = 16;
    private const EDIT_TOKEN_MAX_LENGTH = 64;
    private const TREE_FIELD_KEYS = [
        'purchaser_type',
        'individual_first_name',
        'individual_last_name',
        'qr_tree_postcode',
        'qr_tree_city',
        'qr_tree_message_1',
        'qr_tree_message_2',
        'church_org_website',
        'pay_forward_type',
        'pay_forward_contact',
    ];

    // A3 print-sheet layout (portrait, print resolution).
    private const A3_PRINT_DPI                = 300;
    private const A3_WIDTH_MM                 = 297;
    private const A3_HEIGHT_MM                = 420;
    private const A3_MARGIN_RATIO             = 0.09;  // side/top margin as a fraction of width
    private const A3_TITLE_LINE_SPACING_RATIO = 0.12;  // gap between title lines, fraction of font size
    private const A3_ID_FONT_RATIO            = 0.008; // short_code label height, fraction of canvas height
    private const A3_QR_RENDER_SCALE          = 20;    // native QR px-per-module before scaling to fit
    private const A3_TITLE_LINES              = ['Merry', 'Christmas'];
    private const A3_TITLE_FONT_RELATIVE_PATH = '/assets/fonts/Quicksand-Bold.ttf'; // "Merry Christmas" title face
    private const A3_FONT_RELATIVE_PATH       = '/assets/fonts/Fredoka-Regular.ttf'; // short_code identifier face

    // Message 2 combined button ([qr_tracker_message_2_button]).
    private const MESSAGE_2_BUTTON_DEFAULT_LABEL = 'Click Here';
    private const MESSAGE_2_BUTTON_STYLE         = 'background-color: #af691c;color: white;font-size: 14px';

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
    private $email;
    private $tree_checkout_field_overrides_cache = null;
    private $tree_individual_fields_toggle_script_printed = false;
    private $tree_field_description_visibility_style_printed = false;

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
        $this->email = new QRCodeTracker_Email($this);
        $this->admin = new QRCodeTracker_Admin($this, $this->teams);
        add_action('admin_menu', [$this->admin, 'admin_menu']);

        // Initialize permissions system
        new QRCodeTracker_Permissions();

        $this->export = new QRCodeTracker_Export();
        add_action('admin_action_qr_tracker_export', [$this->export, 'handle_csv_export']);

        // Initialize popup functionality
        $this->popup = new QRCodeTracker_Popup($this);

        add_action('parse_request', [$this, 'maybe_redirect_query_url_to_short_code'], 1);
        add_action('wp', [$this, 'track_visit'], 0);
        add_action('template_redirect', [$this, 'handle_legacy_short_code_redirect'], 0);
        add_action('template_redirect', [$this, 'handle_qr_management_request']);
        add_action('template_redirect', [$this, 'handle_anonymous_short_code_redirect']);
        add_action('wp_footer', [$this, 'render_visit_debug_message'], 99);
        add_shortcode('qr_tracker_message_1', [$this, 'shortcode_message_1']);
        add_shortcode('qr_tracker_message_2', [$this, 'shortcode_message_2']);
        add_shortcode('qr_tracker_message_2_button', [$this, 'shortcode_message_2_button_combined']);
        add_shortcode('qr_tracker_shop_link', [$this, 'shortcode_shop_link']);
        add_action('admin_action_qr_tracker_download_qr', [$this, 'handle_download_qr']);
        add_action('init', [$this, 'handle_public_qr_image_request'], 1);
        $this->init_woocommerce_tree_fields();
    }

    public function get_email_handler() {
        return $this->email;
    }

    public static function get_social_scan_source_prefix() {
        return 'social_';
    }

    public static function get_scan_only_log_condition($table_alias = 'l') {
        $column = !empty($table_alias) ? $table_alias . '.scan_source' : 'scan_source';

        return sprintf(
            "(%s IS NULL OR LEFT(%s, 7) != '%s')",
            $column,
            $column,
            self::get_social_scan_source_prefix()
        );
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
        $is_social_share = $this->is_social_share_request();

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
            $scan_source = $is_social_share ? 'social_query_short_code' : 'query_short_code';
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
                    // Path-based short codes are the social link — always count as social share.
                    $is_social_share = true;
                    $scan_source = 'social_link';
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
                $scan_source = $is_social_share ? 'social_postcode_city_tree' : 'postcode_city_tree';
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
                $scan_source = $is_social_share ? 'social_legacy_url_match' : 'legacy_url_match';
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

            $logged_at = current_time('mysql', 1);

            if ($is_social_share) {
                $update_result = $wpdb->update(
                    $this->main_table,
                    [
                        'social_share_count' => ((int) $row->social_share_count) + 1,
                        'last_social_shared' => $logged_at,
                    ],
                    ['id' => $row->id]
                );
            } else {
                $update_result = $wpdb->update(
                    $this->main_table,
                    [
                        'scan_count' => $row->scan_count + 1,
                        'last_scanned' => $logged_at
                    ],
                    ['id' => $row->id]
                );
            }

            $insert_result = $wpdb->insert($this->log_table, [
                'tracker_id' => $row->id,
                'postcode' => $row->postcode,
                'city' => $row->city,
                'tree' => $row->tree,
                'scanned_at' => $logged_at,
                'scan_source' => $scan_source,
                'request_uri' => $request_uri,
                'visitor_hash' => $visitor_hash
            ]);

            $recorded = ($update_result !== false && $insert_result !== false);
            if ($recorded) {
                $message = $is_social_share
                    ? 'Social share visit recorded successfully.'
                    : 'Visit recorded successfully.';
                $this->set_visit_debug(true, $message, $scan_source, $visit_start);
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

        if (!function_exists('is_404') || !is_404()) {
            return;
        }

        if (isset($_GET['qr']) && $_GET['qr'] !== '') {
            return;
        }

        $short_code = $this->get_path_short_code_from_request();
        if (empty($short_code)) {
            return;
        }

        // Anonymous short codes are handled by handle_anonymous_short_code_redirect,
        // which serves the destination content in-place so the browser URL stays as
        // the short link. Skip them here to avoid an early redirect.
        if ($this->is_anonymous_short_code($short_code)) {
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

        $redirect_url = add_query_arg('_qr_redirect', '1', $validated_url);
        wp_safe_redirect($redirect_url, 302);
        exit;
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

    /**
     * Returns true when $code is a valid anonymous short code (all lowercase
     * alphanumeric, within the configured min/max length).
     */
    private function is_anonymous_short_code($code) {
        $pattern = '/^[a-z0-9]{' . self::SHORT_CODE_MIN_LENGTH . ',' . self::SHORT_CODE_MAX_LENGTH . '}$/';
        return (bool) preg_match($pattern, strtolower((string) $code));
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

    private function is_social_share_request() {
        $qr_source = isset($_GET['qr_source']) ? sanitize_text_field(wp_unslash($_GET['qr_source'])) : '';
        return $qr_source === 'social';
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
        return do_shortcode('Thanks for visiting! But don\'t stop — there\'s more to discover. Continue the journey above, request a free booklet, or send us a question or comment and we\'ll be in touch.');
    }

    /**
     * Combined shortcode [qr_tracker_message_2_button]: renders Message 2 followed
     * by a button linking to the scanned tree's Church / Organisation Website.
     *
     * Both the link and label are supplied automatically from the current tracker,
     * so no attributes are required. Optional overrides:
     *   [qr_tracker_message_2_button label="Read More"]
     *   [qr_tracker_message_2_button url="https://example.com"]
     */
    public function shortcode_message_2_button_combined($atts = []) {
        $atts = shortcode_atts([
            'url'   => '',
            'label' => self::MESSAGE_2_BUTTON_DEFAULT_LABEL,
        ], $atts, 'qr_tracker_message_2_button');

        // Part 1: the Message 2 content (mirrors [qr_tracker_message_2]).
        $message_html = $this->shortcode_message_2();

        // Part 2: resolve the button URL — an explicit attribute wins, otherwise
        // pull the Church / Organisation Website from the current tracker.
        $url = trim((string) $atts['url']);
        if ($url === '') {
            $tracker = $this->get_current_tracker();
            if ($tracker && !empty($tracker->church_org_website)) {
                $url = (string) $tracker->church_org_website;
            }
        }

        // With no destination, output the message alone rather than a dead button.
        if ($url === '') {
            return $message_html;
        }

        $label = ($atts['label'] !== '') ? $atts['label'] : self::MESSAGE_2_BUTTON_DEFAULT_LABEL;

        $button_html = sprintf(
            '<p style="margin:12px 0;">'
                . '<a class="button qr-message-2-button" style="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>'
                . '</p>',
            esc_attr(self::MESSAGE_2_BUTTON_STYLE),
            esc_url($url),
            esc_html($label)
        );

        return $message_html . $button_html;
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

    /**
     * Render the QR code as a raw GD image resource (no PNG round-trip),
     * so it can be composited onto another canvas.
     *
     * @return \GdImage|resource|null
     */
    private function render_qr_gd_resource($url) {
        try {
            $options = new QROptions([
                'outputType'     => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'       => QRCode::ECC_H,
                'scale'          => self::A3_QR_RENDER_SCALE,
                'returnResource' => true,
                // No built-in quiet zone: the white A3 canvas around the code
                // already provides the surrounding quiet space, so the printed
                // QR sits flush with no extra padding border.
                'addQuietzone'   => false,
            ]);
            $qrcode   = new QRCode($options);
            $resource = $qrcode->render($url);
        } catch (\Throwable $e) {
            error_log('QR Tracker: failed to render QR resource for A3 sheet: ' . $e->getMessage());
            return null;
        }

        if ($resource instanceof \GdImage || is_resource($resource)) {
            return $resource;
        }
        return null;
    }

    /**
     * Find the largest font size at which every title line fits within $target_width.
     * Measures against a probe size and scales by the widest line.
     */
    private function fit_font_to_width($font_path, array $lines, $target_width) {
        $probe_size = 100.0;
        $widest     = 1;
        foreach ($lines as $line) {
            $bbox  = imagettfbbox($probe_size, 0, $font_path, $line);
            $width = abs($bbox[2] - $bbox[0]);
            if ($width > $widest) {
                $widest = $width;
            }
        }
        return $probe_size * ($target_width / $widest);
    }

    /**
     * Build a print-ready A3 sheet (portrait, 300 DPI): the "Merry Christmas"
     * title across the top, the QR code centred below it, and the short_code in
     * small grey text at the bottom-right so each printed sheet can be matched
     * back to the correct client.
     *
     * @param string $url        The tracker URL encoded in the QR code.
     * @param string $short_code The unique short code, printed as the identifier.
     * @return string|null       Raw PNG bytes, or null if the image toolchain is unavailable.
     */
    public function render_a3_print_sheet($url, $short_code) {
        if (!extension_loaded('gd') || !function_exists('imagettftext')) {
            error_log('QR Tracker: cannot build A3 sheet - GD/FreeType unavailable.');
            return null;
        }

        $font_path = __DIR__ . self::A3_FONT_RELATIVE_PATH;
        if (!is_readable($font_path)) {
            error_log('QR Tracker: cannot build A3 sheet - font missing at ' . $font_path);
            return null;
        }

        // Title uses Quicksand Bold; fall back to the identifier font if it's
        // missing so the sheet still renders (logged rather than failing outright).
        $title_font_path = __DIR__ . self::A3_TITLE_FONT_RELATIVE_PATH;
        if (!is_readable($title_font_path)) {
            error_log('QR Tracker: A3 title font missing at ' . $title_font_path . ' - falling back to ' . $font_path);
            $title_font_path = $font_path;
        }

        $canvas_width  = (int) round(self::A3_WIDTH_MM  / 25.4 * self::A3_PRINT_DPI);
        $canvas_height = (int) round(self::A3_HEIGHT_MM / 25.4 * self::A3_PRINT_DPI);

        // A canvas this size is memory-hungry; request headroom where the host allows it.
        @ini_set('memory_limit', '512M');

        $canvas = imagecreatetruecolor($canvas_width, $canvas_height);
        if (!$canvas) {
            error_log('QR Tracker: cannot build A3 sheet - imagecreatetruecolor failed.');
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        $grey  = imagecolorallocate($canvas, 120, 120, 120);
        imagefilledrectangle($canvas, 0, 0, $canvas_width, $canvas_height, $white);

        $margin            = (int) round($canvas_width * self::A3_MARGIN_RATIO);
        $target_text_width = $canvas_width - (2 * $margin);

        // --- Title: one entry per line, centred, auto-sized so the widest line fits. ---
        $title_font_size = $this->fit_font_to_width($title_font_path, self::A3_TITLE_LINES, $target_text_width);
        $line_spacing    = (int) round($title_font_size * self::A3_TITLE_LINE_SPACING_RATIO);
        $cursor_y        = $margin; // top of the title block

        foreach (self::A3_TITLE_LINES as $line) {
            $bbox        = imagettfbbox($title_font_size, 0, $title_font_path, $line);
            $text_width  = abs($bbox[2] - $bbox[0]);
            $text_height = abs($bbox[7] - $bbox[1]);
            $draw_x      = (int) round(($canvas_width - $text_width) / 2) - $bbox[0];
            $baseline_y  = $cursor_y + $text_height;
            imagettftext($canvas, $title_font_size, 0, $draw_x, $baseline_y, $black, $title_font_path, $line);
            $cursor_y = $baseline_y + $line_spacing;
        }

        // --- QR code: rendered crisp, then scaled to fill the space below the title. ---
        $space_top    = $cursor_y + $margin;            // leave a margin-sized gap under the title
        $space_bottom = $canvas_height - $margin;
        $qr_size      = min($target_text_width, max(0, $space_bottom - $space_top));

        $qr_image = $this->render_qr_gd_resource($url);
        if ($qr_image && $qr_size > 0) {
            $qr_native = imagesx($qr_image);
            $qr_dest_x = (int) round(($canvas_width - $qr_size) / 2);
            $qr_dest_y = (int) round($space_top + max(0, (($space_bottom - $space_top) - $qr_size) / 2));

            // Nearest-neighbour scaling keeps the modules sharp and reliably scannable.
            imagecopyresized(
                $canvas, $qr_image,
                $qr_dest_x, $qr_dest_y, 0, 0,
                $qr_size, $qr_size, $qr_native, $qr_native
            );
            imagedestroy($qr_image);
        }

        // --- Identifier: short_code in small grey text, bottom-right corner. ---
        if (!empty($short_code)) {
            $id_font_size = max(8.0, $canvas_height * self::A3_ID_FONT_RATIO);
            $id_text      = (string) $short_code;
            $bbox         = imagettfbbox($id_font_size, 0, $font_path, $id_text);
            $id_width     = abs($bbox[2] - $bbox[0]);
            $id_x         = $canvas_width - $margin - $id_width - $bbox[0];
            $id_y         = $canvas_height - (int) round($margin / 2); // baseline
            imagettftext($canvas, $id_font_size, 0, (int) round($id_x), $id_y, $grey, $font_path, $id_text);
        }

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);

        return $png;
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

        // Optional A3 print sheet: "Merry Christmas" title + QR + short_code identifier.
        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : '';
        if ($format === 'a3') {
            $sheet = $this->render_a3_print_sheet($url, $row->short_code);
            if ($sheet === null) {
                wp_die('Unable to generate the A3 print sheet on this server (image support is unavailable). See the error log for details.');
            }
            $postcode = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->postcode);
            $city     = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->city);
            $tree     = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->tree);
            $filename = ($postcode ?: 'qr') . ($city ? '-' . $city : '') . ($tree ? '-' . $tree : '') . '-A3.png';
            header('Content-Type: image/png');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sheet));
            echo $sheet;
            exit;
        }

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

    /**
     * Public endpoint: /?qr_img=SHORT_CODE serves the QR PNG inline.
     * /?qr_img=SHORT_CODE&qr_dl=1 serves it as a download attachment.
     * No authentication required — the short_code is already public (embedded in the QR URL).
     */
    public function handle_public_qr_image_request() {
        if (empty($_GET['qr_img'])) {
            return;
        }
        global $wpdb;
        $short_code = strtolower(sanitize_text_field(wp_unslash($_GET['qr_img'])));
        if (empty($short_code)) {
            return;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, url, postcode, city, tree FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
            $short_code
        ));
        if (!$row) {
            status_header(404);
            exit;
        }
        $is_download = !empty($_GET['qr_dl']);
        $scale       = $is_download ? 20 : 5;
        $options     = new QROptions([
            'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'    => QRCode::ECC_H,
            'scale'       => $scale,
            'imageBase64' => false,
        ]);
        $qrcode    = new QRCode($options);
        $imageData = $qrcode->render($row->url);

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($imageData));
        if ($is_download) {
            $postcode = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->postcode);
            $city     = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->city);
            $tree     = preg_replace('/[^a-zA-Z0-9_-]/', '', $row->tree);
            $filename = ($postcode ?: 'qr') . ($city ? '-' . $city : '') . ($tree ? '-' . $tree : '') . '.png';
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        } else {
            header('Content-Disposition: inline; filename="qr-' . $short_code . '.png"');
            header('Cache-Control: public, max-age=86400');
        }
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

    public function generate_social_share_url($short_code) {
        $short_code = strtolower(sanitize_text_field((string) $short_code));
        if (empty($short_code)) {
            return '';
        }

        return add_query_arg(
            [
                'qr' => $short_code,
                'qr_source' => 'social',
            ],
            home_url('/')
        );
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

    public function maybe_redirect_query_url_to_short_code($wp = null) {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if (isset($_GET['qr']) || isset($_GET['_qr_redirect']) || isset($_GET['qr_manage']) || isset($_GET['qr_manage_team'])) {
            return;
        }

        $postcode = isset($_GET['postcode']) ? sanitize_text_field(wp_unslash($_GET['postcode'])) : '';
        $city = isset($_GET['city']) ? sanitize_text_field(wp_unslash($_GET['city'])) : '';
        $tree = isset($_GET['tree']) ? sanitize_text_field(wp_unslash($_GET['tree'])) : '';

        if ($postcode === '' || $city === '' || $tree === '') {
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT short_code FROM {$this->main_table} WHERE postcode = %s AND city = %s AND tree = %s ORDER BY id DESC LIMIT 1",
            $postcode,
            $city,
            $tree
        ));

        if (!$row || empty($row->short_code)) {
            return;
        }

        $short_code = strtolower(sanitize_text_field((string) $row->short_code));
        if ($short_code === '') {
            return;
        }

        // Always redirect legacy postcode/city/tree links to the ?qr= URL so the
        // visit is tracked as a QR scan, not a social share. The path-based short
        // link (/{shortcode}) is reserved for the social link.
        $target_url = add_query_arg(['qr' => $short_code], home_url('/'));

        if (empty($target_url)) {
            return;
        }

        $current_url = home_url(add_query_arg([]));
        if (untrailingslashit($current_url) === untrailingslashit($target_url)) {
            return;
        }

        wp_safe_redirect($target_url, 302);
        exit;
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

        // Only serve in-place for internal URLs; skip short codes that point
        // to external destinations (wp_validate_redirect returns '' for those).
        if (empty(wp_validate_redirect($row->url, ''))) {
            return;
        }

        // Serve the destination content at the short-link URL without redirecting
        // the browser. Rebuild the main query against the internal destination URL
        // so theme/plugin code sees a consistent queried object and global post.
        $target_path = wp_parse_url($row->url, PHP_URL_PATH);
        $target_query = wp_parse_url($row->url, PHP_URL_QUERY);
        if (!is_string($target_path) || $target_path === '') {
            $target_path = '/';
        }

        $target_query_vars = [];
        if (is_string($target_query) && $target_query !== '') {
            wp_parse_str($target_query, $target_query_vars);
        }

        $normalized_path = trim($target_path, '/');
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (is_string($home_path)) {
            $home_path = trim($home_path, '/');
            if ($home_path !== '') {
                if (strpos($normalized_path, $home_path . '/') === 0) {
                    $normalized_path = substr($normalized_path, strlen($home_path) + 1);
                } elseif ($normalized_path === $home_path) {
                    $normalized_path = '';
                }
            }
        }

        if ($normalized_path === '') {
            if (get_option('show_on_front') === 'page') {
                $front_page_id = (int) get_option('page_on_front');
                if ($front_page_id > 0 && !isset($target_query_vars['page_id']) && !isset($target_query_vars['pagename'])) {
                    $target_query_vars['page_id'] = $front_page_id;
                }
            }
        } elseif (!isset($target_query_vars['page_id']) && !isset($target_query_vars['pagename']) && !isset($target_query_vars['name'])) {
            $target_post_id = url_to_postid(home_url('/' . ltrim($normalized_path, '/')));
            if ($target_post_id > 0) {
                $target_query_vars['page_id'] = $target_post_id;
            } else {
                $target_query_vars['pagename'] = $normalized_path;
            }
        }

        global $wp_query, $wp_the_query;
        $wp_query->query($target_query_vars);
        $wp_query->is_404 = false;
        $wp_the_query = $wp_query;

        if (!empty($wp_query->post) && $wp_query->post instanceof WP_Post) {
            $GLOBALS['post'] = $wp_query->post;
            setup_postdata($GLOBALS['post']);
        }

        status_header(200);
        nocache_headers();
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
                'label'              => isset($_POST['qr_label']) ? sanitize_text_field(wp_unslash($_POST['qr_label'])) : '',
                'reporting_id'       => isset($_POST['qr_reporting_id']) ? sanitize_text_field(wp_unslash($_POST['qr_reporting_id'])) : '',
                'message_1'          => isset($_POST['qr_message_1']) ? wp_kses_post(wp_unslash($_POST['qr_message_1'])) : '',
                'message_2'          => isset($_POST['qr_message_2']) ? wp_kses_post(wp_unslash($_POST['qr_message_2'])) : '',
                'church_org_website' => isset($_POST['qr_church_org_website']) ? esc_url_raw(wp_unslash($_POST['qr_church_org_website'])) : '',
            ];

            $update_result = $wpdb->update($this->main_table, $update_data, ['id' => $qr_code->id]);
            if ($update_result === false) {
                $notice = '<div class="notice notice-error" style="padding:10px;margin:10px 0;"><p>Unable to update QR code details. Please try again.</p></div>';
            } else {
                $qr_code = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $qr_code->id));
                $notice = '<div class="notice notice-success" style="padding:10px;margin:10px 0;"><p>QR code details updated.</p></div>';
            }
        }

        // Enqueue the visual editor scripts before wp_head() fires inside get_header().
        wp_enqueue_editor();

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
        echo '<table class="form-table" style="width:100%;">';
        echo '<tr><th><label for="qr_label">Label</label></th><td><input type="text" id="qr_label" name="qr_label" value="' . esc_attr($qr_code->label) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="qr_reporting_id">Reporting ID</label></th><td><input type="text" id="qr_reporting_id" name="qr_reporting_id" value="' . esc_attr($qr_code->reporting_id) . '" class="regular-text"></td></tr>';

        // Message 1 — visual editor
        echo '<tr><th style="vertical-align:top;padding-top:10px;"><label>Message 1</label></th><td>';
        wp_editor(
            $qr_code->message_1 ?? '',
            'qr_message_1',
            [
                'textarea_name' => 'qr_message_1',
                'media_buttons' => false,
                'textarea_rows' => 6,
                'teeny'         => false,
            ]
        );
        echo '</td></tr>';

        // Message 2 — visual editor
        echo '<tr><th style="vertical-align:top;padding-top:10px;"><label>Message 2</label></th><td>';
        wp_editor(
            $qr_code->message_2 ?? '',
            'qr_message_2',
            [
                'textarea_name' => 'qr_message_2',
                'media_buttons' => false,
                'textarea_rows' => 6,
                'teeny'         => false,
            ]
        );
        echo '</td></tr>';

        echo '<tr><th><label for="qr_church_org_website">Church / Organisation Website Link</label></th><td><input type="url" id="qr_church_org_website" name="qr_church_org_website" value="' . esc_attr($qr_code->church_org_website ?? '') . '" class="regular-text" placeholder="https://example.com"></td></tr>';
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
        add_action('woocommerce_after_order_notes', [$this, 'render_tree_checkout_fields_on_checkout']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_tree_checkout_fields'], 10, 2);
        add_action('woocommerce_checkout_process', [$this, 'validate_tree_checkout_fields_on_checkout']);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_tree_checkout_fields_to_cart'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_tree_checkout_fields_in_cart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_tree_checkout_fields_to_order_items'], 10, 3);
        add_action('woocommerce_checkout_order_processed', [$this, 'clear_tree_checkout_session_values']);
        add_action('woocommerce_checkout_order_processed', [$this, 'create_qr_records_for_completed_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'create_qr_records_for_completed_order'], 10, 1);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_legacy_tree_item_meta_keys']);
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_pay_forward_cart_fee']);
    }

    public function get_tree_checkout_field_choices() {
        $default_labels = $this->get_default_tree_checkout_field_labels();
        $overrides = $this->get_tree_checkout_field_overrides();
        $field_choices = [];
        foreach (self::TREE_FIELD_KEYS as $field_key) {
            if (isset($overrides[$field_key]['label']) && $overrides[$field_key]['label'] !== '') {
                $field_choices[$field_key] = $overrides[$field_key]['label'];
            } elseif (isset($default_labels[$field_key])) {
                $field_choices[$field_key] = $default_labels[$field_key];
            } else {
                $field_choices[$field_key] = $field_key;
            }
        }

        return $field_choices;
    }

    private function get_default_tree_checkout_field_labels() {
        return [
            'purchaser_type'        => 'Purchasing As',
            'individual_first_name' => 'First Name',
            'individual_last_name'  => 'Last Name',
            'qr_tree_postcode'      => 'Postcode',
            'qr_tree_city'          => 'City',
            'qr_tree_message_1'     => 'Message 1',
            'qr_tree_message_2'     => 'Message 2',
            'church_org_website'    => 'Church / Organisation Website Link',
            'pay_forward_type'      => 'Pay a Tree Forward?',
            'pay_forward_contact'   => 'Pay Forward Recipient',
        ];
    }

    private function get_default_tree_checkout_field_descriptions() {
        return [
            'individual_first_name' => 'Required when Purchasing as is set to Individual.',
            'individual_last_name'  => 'Required when Purchasing as is set to Individual.',
            'qr_tree_message_1'     => 'Suggestion: "Merry Christmas from [Name]!"',
            'qr_tree_message_2'     => 'Suggestions: Click Here, Read More, Email. To render a button in Message 2, use [qr_message_2_button ...] only when that shortcode is available in your site (check with your site admin/plugin docs).',
        ];
    }

    public function sanitize_tree_checkout_field_overrides($overrides) {
        if (!is_array($overrides)) {
            return [];
        }

        $sanitized = [];
        foreach (self::TREE_FIELD_KEYS as $field_key) {
            if (!isset($overrides[$field_key]) || !is_array($overrides[$field_key])) {
                continue;
            }

            $label = isset($overrides[$field_key]['label']) ? sanitize_text_field($overrides[$field_key]['label']) : '';
            $description = isset($overrides[$field_key]['description']) ? wp_kses_post($overrides[$field_key]['description']) : '';

            if ($label === '' && $description === '') {
                continue;
            }

            $sanitized[$field_key] = [];
            if ($label !== '') {
                $sanitized[$field_key]['label'] = $label;
            }
            if ($description !== '') {
                $sanitized[$field_key]['description'] = $description;
            }
        }

        return $sanitized;
    }

    public function get_tree_checkout_field_overrides() {
        if ($this->tree_checkout_field_overrides_cache === null) {
            $this->tree_checkout_field_overrides_cache = $this->sanitize_tree_checkout_field_overrides(get_option('qr_tracker_tree_field_overrides', []));
        }
        return $this->tree_checkout_field_overrides_cache;
    }

    public function reset_tree_checkout_field_override_cache() {
        $this->tree_checkout_field_overrides_cache = null;
    }

    private function get_tree_field_label($field_key, $default = '') {
        $overrides = $this->get_tree_checkout_field_overrides();
        if (isset($overrides[$field_key]['label']) && $overrides[$field_key]['label'] !== '') {
            return $overrides[$field_key]['label'];
        }

        $defaults = $this->get_default_tree_checkout_field_labels();
        if ($default === '' && isset($defaults[$field_key])) {
            $default = $defaults[$field_key];
        }

        return $default;
    }

    private function get_tree_field_description($field_key, $default = '') {
        $overrides = $this->get_tree_checkout_field_overrides();
        if (isset($overrides[$field_key]['description']) && $overrides[$field_key]['description'] !== '') {
            return $overrides[$field_key]['description'];
        }

        $defaults = $this->get_default_tree_checkout_field_descriptions();
        if ($default === '' && isset($defaults[$field_key])) {
            $default = $defaults[$field_key];
        }

        return $default;
    }

    private function get_default_tree_checkout_field_layout() {
        return [
            'product' => self::TREE_FIELD_KEYS,
            'checkout' => [],
        ];
    }

    public function sanitize_tree_checkout_field_layout($layout) {
        $default_layout = $this->get_default_tree_checkout_field_layout();
        if (!is_array($layout)) {
            return $default_layout;
        }

        $known_keys = self::TREE_FIELD_KEYS;
        $product = isset($layout['product']) && is_array($layout['product']) ? $layout['product'] : [];
        $checkout = isset($layout['checkout']) && is_array($layout['checkout']) ? $layout['checkout'] : [];

        $product = array_values(array_unique(array_filter(array_map('sanitize_key', $product), function ($field_key) use ($known_keys) {
            return in_array($field_key, $known_keys, true);
        })));

        $checkout = array_values(array_unique(array_filter(array_map('sanitize_key', $checkout), function ($field_key) use ($known_keys, $product) {
            return in_array($field_key, $known_keys, true) && !in_array($field_key, $product, true);
        })));

        $paired_keys = ['pay_forward_type', 'pay_forward_contact'];
        $product_pair_count = count(array_intersect($paired_keys, $product));
        $checkout_pair_count = count(array_intersect($paired_keys, $checkout));
        if ($product_pair_count === 1 || $checkout_pair_count === 1) {
            foreach ($paired_keys as $paired_key) {
                $checkout = array_values(array_diff($checkout, [$paired_key]));
                if (!in_array($paired_key, $product, true)) {
                    $product[] = $paired_key;
                }
            }
        }

        $remaining = array_values(array_diff($known_keys, array_merge($product, $checkout)));
        $product = array_values(array_merge($product, $remaining));

        return [
            'product' => $product,
            'checkout' => $checkout,
        ];
    }

    public function get_tree_checkout_field_layout() {
        return $this->sanitize_tree_checkout_field_layout(get_option('qr_tracker_tree_field_layout', $this->get_default_tree_checkout_field_layout()));
    }

    private function has_tree_product_in_cart() {
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = isset($cart_item['variation_id']) && (int) $cart_item['variation_id'] > 0
                ? (int) $cart_item['variation_id']
                : (isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0);
            if ($product_id > 0 && $this->is_tree_product($product_id)) {
                return true;
            }
        }

        return false;
    }

    private function get_tree_field_keys_for_context($context) {
        $layout = $this->get_tree_checkout_field_layout();
        return $context === 'checkout'
            ? $layout['checkout']
            : $layout['product'];
    }

    private function get_tree_field_display_order() {
        return array_values(array_unique(array_merge(
            $this->get_tree_field_keys_for_context('product'),
            $this->get_tree_field_keys_for_context('checkout'),
            self::TREE_FIELD_KEYS
        )));
    }

    private function get_tree_checkout_session_values() {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return [];
        }
        $stored_values = WC()->session->get('qr_tracker_checkout_tree_fields', []);
        if (!is_array($stored_values)) {
            return [];
        }

        $sanitized_values = [];
        foreach ($stored_values as $field_key => $field_value) {
            $field_key = sanitize_key($field_key);
            if (!in_array($field_key, self::TREE_FIELD_KEYS, true)) {
                continue;
            }
            $sanitized_values[$field_key] = $this->sanitize_tree_field_value($field_key, $field_value);
        }

        return $sanitized_values;
    }

    private function store_tree_checkout_session_values($field_keys) {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }

        $stored_values = [];
        foreach ($field_keys as $field_key) {
            $stored_values[$field_key] = $this->get_tree_field_value_from_request($field_key);
        }
        if (isset($stored_values['pay_forward_contact']) && (empty($stored_values['pay_forward_type']) || $stored_values['pay_forward_type'] !== 'specific')) {
            $stored_values['pay_forward_contact'] = '';
        }
        WC()->session->set('qr_tracker_checkout_tree_fields', $stored_values);
    }

    public function clear_tree_checkout_session_values($order_id = 0) {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }
        WC()->session->__unset('qr_tracker_checkout_tree_fields');
    }

    public function hide_legacy_tree_item_meta_keys($hidden_meta_keys) {
        $legacy_keys = [
            '_purchaser_type',
            '_contact_emails',
            '_report_emails',
            '_org_or_individual_name',
            '_individual_first_name',
            '_individual_last_name',
            '_referral_code',
            '_referral_link',
            '_discount_code',
            '_delivery_or_collection',
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

        $product_field_keys = $this->get_tree_field_keys_for_context('product');
        if (empty($product_field_keys)) {
            return;
        }

        $this->render_tree_field_description_visibility_style();
        echo '<div class="advent-tree-fields">';
        foreach ($product_field_keys as $field_key) {
            $this->render_tree_checkout_field($field_key);
        }
        echo '</div>';
        $this->render_tree_individual_fields_toggle_script();
    }

    public function render_tree_checkout_fields_on_checkout($checkout) {
        if (!function_exists('woocommerce_form_field') || !$this->has_tree_product_in_cart()) {
            return;
        }

        $checkout_field_keys = $this->get_tree_field_keys_for_context('checkout');
        if (empty($checkout_field_keys)) {
            return;
        }

        $this->render_tree_field_description_visibility_style();
        echo '<div id="qr-tracker-checkout-fields"><h3>Tree Product Details</h3>';
        foreach ($checkout_field_keys as $field_key) {
            $this->render_tree_checkout_field($field_key);
        }
        echo '</div>';
        $this->render_tree_individual_fields_toggle_script();
    }

    private function render_tree_individual_fields_toggle_script() {
        if ($this->tree_individual_fields_toggle_script_printed) {
            return;
        }

        $this->tree_individual_fields_toggle_script_printed = true;

        // Build a map of team name → prefill + lock values for client-side use.
        $team_prefills = [];
        if (is_object($this->teams) && method_exists($this->teams, 'get_public_teams')) {
            $public_teams = $this->teams->get_public_teams();
            if (is_array($public_teams)) {
                foreach ($public_teams as $team) {
                    $team_prefills[sanitize_text_field($team->name)] = [
                        'message_1' => isset($team->prefill_message_1) ? $team->prefill_message_1 : '',
                        'lock_1'    => !empty($team->lock_message_1),
                        'message_2' => isset($team->prefill_message_2) ? $team->prefill_message_2 : '',
                        'lock_2'    => !empty($team->lock_message_2),
                    ];
                }
            }
        }
        $team_prefills_json = wp_json_encode($team_prefills);

        echo '<script>';
        echo '(function(){';
        echo 'var teamPrefills=' . $team_prefills_json . ';';
        echo 'function updateIndividualNameFieldVisibility(){';
        echo 'var purchaserTypeField=document.querySelector("select[name=\"purchaser_type\"]");';
        echo 'var firstNameWrapper=document.getElementById("individual_first_name_field");';
        echo 'var lastNameWrapper=document.getElementById("individual_last_name_field");';
        echo 'var firstNameInput=document.querySelector("input[name=\"individual_first_name\"]");';
        echo 'var lastNameInput=document.querySelector("input[name=\"individual_last_name\"]");';
        echo 'var showFields=!!(purchaserTypeField&&purchaserTypeField.value==="individual");';
        echo 'if(firstNameWrapper){firstNameWrapper.style.display=showFields?"":"none";}';
        echo 'if(lastNameWrapper){lastNameWrapper.style.display=showFields?"":"none";}';
        echo 'if(firstNameInput){firstNameInput.disabled=!showFields;}';
        echo 'if(lastNameInput){lastNameInput.disabled=!showFields;}';
        echo '}';
        echo 'function applyPrefillToField(textarea,value,locked){';
        echo 'if(!textarea){return;}';
        echo 'if(value!==""){textarea.value=value;}else{textarea.value="";}';
        echo 'textarea.readOnly=!!(value!==""&&locked);';
        echo 'textarea.style.background=(value!==""&&locked)?"#f0f0f0":"";';
        echo 'var desc=textarea.closest(".form-row")||textarea.parentNode;';
        echo 'var hint=desc?desc.querySelector(".description"):null;';
        echo 'if(hint){';
        echo 'if(value!==""&&locked){hint.textContent="This message has been set by your organisation and cannot be changed.";}';
        echo 'else if(value!==""){hint.textContent="Pre-filled by your organisation — you may edit this.";}';
        echo '}';
        echo '}';
        echo 'function updateTeamPrefillFields(){';
        echo 'var purchaserTypeField=document.querySelector("select[name=\"purchaser_type\"]");';
        echo 'if(!purchaserTypeField){return;}';
        echo 'var selectedTeam=purchaserTypeField.value;';
        echo 'var prefill=teamPrefills[selectedTeam]||null;';
        echo 'var msg1=document.querySelector("textarea[name=\"qr_tree_message_1\"]");';
        echo 'var msg2=document.querySelector("textarea[name=\"qr_tree_message_2\"]");';
        echo 'applyPrefillToField(msg1,prefill?prefill.message_1:"",prefill?prefill.lock_1:false);';
        echo 'applyPrefillToField(msg2,prefill?prefill.message_2:"",prefill?prefill.lock_2:false);';
        echo '}';
        echo 'function updatePayForwardContactVisibility(){';
        echo 'var payForwardTypeField=document.querySelector("select[name=\"pay_forward_type\"]");';
        echo 'var contactWrapper=document.getElementById("pay_forward_contact_field");';
        echo 'var contactInput=document.querySelector("input[name=\"pay_forward_contact\"]");';
        echo 'var showContact=!!(payForwardTypeField&&payForwardTypeField.value==="specific");';
        echo 'if(contactWrapper){contactWrapper.style.display=showContact?"":"none";}';
        echo 'if(contactInput){contactInput.disabled=!showContact;if(!showContact){contactInput.value="";}}';
        echo '}';
        echo 'document.addEventListener("change",function(event){';
        echo 'if(!event.target){return;}';
        echo 'if(event.target.name==="purchaser_type"){updateIndividualNameFieldVisibility();updateTeamPrefillFields();}';
        echo 'if(event.target.name==="pay_forward_type"){updatePayForwardContactVisibility();}';
        echo '});';
        echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){updateIndividualNameFieldVisibility();updateTeamPrefillFields();updatePayForwardContactVisibility();});}else{updateIndividualNameFieldVisibility();updateTeamPrefillFields();updatePayForwardContactVisibility();}';
        echo 'if(window.jQuery&&window.jQuery(document.body)){window.jQuery(document.body).on("updated_checkout",function(){updateIndividualNameFieldVisibility();updateTeamPrefillFields();updatePayForwardContactVisibility();});}';
        echo '})();';
        echo '</script>';
    }

    private function render_tree_field_description_visibility_style() {
        if ($this->tree_field_description_visibility_style_printed) {
            return;
        }

        $this->tree_field_description_visibility_style_printed = true;
        $css = '.advent-tree-fields .woocommerce-input-wrapper .description,#qr-tracker-checkout-fields .woocommerce-input-wrapper .description{display:block!important;visibility:visible!important;opacity:1!important;}';
        $style_handle = 'qr-tracker-tree-field-description-visibility';

        if (function_exists('wp_register_style') && function_exists('wp_enqueue_style') && function_exists('wp_add_inline_style') && function_exists('wp_print_styles')) {
            if (!wp_style_is($style_handle, 'registered')) {
                wp_register_style($style_handle, false, [], null);
            }
            wp_enqueue_style($style_handle);
            wp_add_inline_style($style_handle, $css);
            wp_print_styles($style_handle);
            return;
        }

        echo '<style>' . $css . '</style>';
    }

    private function render_customizable_tree_form_field($field_key, $args, $value = '') {
        $args['label'] = $this->get_tree_field_label($field_key, $args['label'] ?? '');
        $description = $this->get_tree_field_description($field_key, $args['description'] ?? '');
        if ($description !== '') {
            $args['description'] = $description;
        } else {
            unset($args['description']);
        }
        woocommerce_form_field($field_key, $args, $value);
    }

    private function render_tree_checkout_field($field_key) {
        switch ($field_key) {
            case 'purchaser_type':
                $this->render_customizable_tree_form_field('purchaser_type', [
                    'type'     => 'select',
                    'class'    => ['form-row-wide'],
                    'label'    => 'Purchasing as',
                    'required' => true,
                    'options'  => $this->get_purchaser_type_options(),
                ], $this->get_tree_field_value_from_request('purchaser_type', ''));
                break;
            case 'individual_first_name':
                // Pulled from WooCommerce billing address automatically.
                break;
            case 'individual_last_name':
                // Pulled from WooCommerce billing address automatically.
                break;
            case 'qr_tree_postcode':
                $this->render_customizable_tree_form_field('qr_tree_postcode', [
                    'type'     => 'text',
                    'class'    => ['form-row-wide'],
                    'label'    => 'Postcode',
                    'required' => true,
                ], $this->get_tree_field_value_from_request('qr_tree_postcode'));
                break;
            case 'qr_tree_city':
                $this->render_customizable_tree_form_field('qr_tree_city', [
                    'type'     => 'text',
                    'class'    => ['form-row-wide'],
                    'label'    => 'City',
                    'required' => true,
                ], $this->get_tree_field_value_from_request('qr_tree_city'));
                break;
            case 'qr_tree_message_1':
                $team_prefill = $this->get_selected_team_prefill();
                $has_prefill_1 = $team_prefill && isset($team_prefill->prefill_message_1) && $team_prefill->prefill_message_1 !== '';
                if ($has_prefill_1 && !empty($team_prefill->lock_message_1)) {
                    $this->render_customizable_tree_form_field('qr_tree_message_1', [
                        'type'              => 'textarea',
                        'class'             => ['form-row-wide'],
                        'label'             => 'Message 1',
                        'custom_attributes' => ['readonly' => 'readonly'],
                        'description'       => 'This message has been set by your organisation and cannot be changed.',
                    ], $team_prefill->prefill_message_1);
                } elseif ($has_prefill_1) {
                    $this->render_customizable_tree_form_field('qr_tree_message_1', [
                        'type'        => 'textarea',
                        'class'       => ['form-row-wide'],
                        'label'       => 'Message 1',
                        'description' => 'Pre-filled by your organisation — you may edit this.',
                    ], $this->get_tree_field_value_from_request('qr_tree_message_1', $team_prefill->prefill_message_1));
                } else {
                    $this->render_customizable_tree_form_field('qr_tree_message_1', [
                        'type'        => 'textarea',
                        'class'       => ['form-row-wide'],
                        'label'       => 'Message 1',
                        'description' => 'Suggestion: "Merry Christmas from [Name]!"',
                    ], $this->get_tree_field_value_from_request('qr_tree_message_1'));
                }
                break;
            case 'qr_tree_message_2':
                $team_prefill = $this->get_selected_team_prefill();
                $has_prefill_2 = $team_prefill && isset($team_prefill->prefill_message_2) && $team_prefill->prefill_message_2 !== '';
                if ($has_prefill_2 && !empty($team_prefill->lock_message_2)) {
                    $this->render_customizable_tree_form_field('qr_tree_message_2', [
                        'type'              => 'textarea',
                        'class'             => ['form-row-wide'],
                        'label'             => 'Message 2',
                        'custom_attributes' => ['readonly' => 'readonly'],
                        'description'       => 'This message has been set by your organisation and cannot be changed.',
                    ], $team_prefill->prefill_message_2);
                } elseif ($has_prefill_2) {
                    $this->render_customizable_tree_form_field('qr_tree_message_2', [
                        'type'        => 'textarea',
                        'class'       => ['form-row-wide'],
                        'label'       => 'Message 2',
                        'description' => 'Pre-filled by your organisation — you may edit this.',
                    ], $this->get_tree_field_value_from_request('qr_tree_message_2', $team_prefill->prefill_message_2));
                } else {
                    $this->render_customizable_tree_form_field('qr_tree_message_2', [
                        'type'        => 'textarea',
                        'class'       => ['form-row-wide'],
                        'label'       => 'Message 2',
                        'description' => 'Suggestions: Click Here, Read More, Email. To render a button in Message 2, use [qr_message_2_button ...] only when that shortcode is available in your site (check with your site admin/plugin docs).',
                    ], $this->get_tree_field_value_from_request('qr_tree_message_2'));
                }
                break;
            case 'church_org_website':
                $this->render_customizable_tree_form_field('church_org_website', [
                    'type'        => 'text',
                    'class'       => ['form-row-wide'],
                    'label'       => 'Church / Organisation Website Link',
                    'placeholder' => 'https://example.com',
                ], $this->get_tree_field_value_from_request('church_org_website'));
                break;
            case 'pay_forward_type':
                $this->render_customizable_tree_form_field('pay_forward_type', [
                    'type'    => 'select',
                    'class'   => ['form-row-wide'],
                    'label'   => 'Pay a Tree Forward?',
                    'options' => [
                        ''         => 'No',
                        'specific' => 'Yes - for a specific person',
                        'general'  => 'Yes - for anyone',
                    ],
                ], $this->get_tree_field_value_from_request('pay_forward_type'));
                break;
            case 'pay_forward_contact':
                $this->render_customizable_tree_form_field('pay_forward_contact', [
                    'type'              => 'text',
                    'class'             => ['form-row-wide'],
                    'label'             => 'Pay Forward Recipient (email or name)',
                    'placeholder'       => 'Enter their email or name',
                    'custom_attributes' => ['autocomplete' => 'off'],
                ], $this->get_tree_field_value_from_request('pay_forward_contact'));
                break;
        }
    }

    private function get_purchaser_type_options() {
        $options = [
            ''           => '— Please select —',
            'individual' => 'Individual',
        ];

        if (!is_object($this->teams) || !method_exists($this->teams, 'get_all_teams')) {
            return $options;
        }

        $teams = $this->teams->get_public_teams();
        if (!is_array($teams)) {
            return $options;
        }

        foreach ($teams as $team) {
            if (!isset($team->name)) {
                continue;
            }

            $team_name = sanitize_text_field($team->name);
            if ($team_name === '') {
                continue;
            }

            $options[$team_name] = $team_name;
        }

        return $options;
    }

    private function get_selected_team_prefill() {
        $purchaser_type = $this->get_tree_field_value_from_request('purchaser_type', '');
        if ($purchaser_type === '' || $purchaser_type === 'individual') {
            return null;
        }
        if (!is_object($this->teams) || !method_exists($this->teams, 'get_team_by_name')) {
            return null;
        }
        return $this->teams->get_team_by_name($purchaser_type);
    }

    private function get_tree_field_value_from_request($field_key, $default = '') {
        if (!isset($_POST[$field_key])) {
            return $default;
        }

        return $this->sanitize_tree_field_value($field_key, wp_unslash($_POST[$field_key]));
    }

    private function sanitize_tree_field_value($field_key, $raw_value) {
        switch ($field_key) {
            case 'purchaser_type':
                $value = sanitize_text_field($raw_value);
                $valid_options = $this->get_purchaser_type_options();
                return isset($valid_options[$value]) ? $value : '';
            case 'qr_tree_postcode':
                return strtoupper(preg_replace('/[^A-Z0-9]/i', '', sanitize_text_field($raw_value)));
            case 'qr_tree_city':
                return preg_replace('/[^A-Za-z0-9-]/', '', sanitize_text_field($raw_value));
            case 'qr_tree_message_1':
            case 'qr_tree_message_2':
                return wp_kses_post($raw_value);
            case 'church_org_website':
                return esc_url_raw($raw_value);
            default:
                return sanitize_text_field($raw_value);
        }
    }



    public function validate_tree_checkout_fields($passed, $product_id) {
        if (!$this->is_tree_product($product_id)) {
            return $passed;
        }

        return $this->validate_tree_checkout_fields_by_context($this->get_tree_field_keys_for_context('product')) ? $passed : false;
    }

    public function validate_tree_checkout_fields_on_checkout() {
        if (!$this->has_tree_product_in_cart()) {
            return;
        }

        $checkout_fields = $this->get_tree_field_keys_for_context('checkout');
        if ($this->validate_tree_checkout_fields_by_context($checkout_fields)) {
            $this->store_tree_checkout_session_values($checkout_fields);
        }
    }

    private function validate_tree_checkout_fields_by_context($field_keys) {
        if (empty($field_keys)) {
            return true;
        }

        $field_lookup = array_flip($field_keys);

        if (isset($field_lookup['pay_forward_type']) && isset($field_lookup['pay_forward_contact'])) {
            $pay_forward_type = $this->get_tree_field_value_from_request('pay_forward_type');
            $pay_forward_contact = $this->get_tree_field_value_from_request('pay_forward_contact');
            if ($pay_forward_type === 'specific' && $pay_forward_contact === '') {
                wc_add_notice('Please provide a recipient for the pay-forward tree.', 'error');
                return false;
            }
        }

        if (isset($field_lookup['purchaser_type']) && $this->get_tree_field_value_from_request('purchaser_type') === '') {
            wc_add_notice('Please select who you are purchasing as.', 'error');
            return false;
        }

        if (isset($field_lookup['qr_tree_postcode']) && $this->get_tree_field_value_from_request('qr_tree_postcode') === '') {
            wc_add_notice('Please enter a postcode.', 'error');
            return false;
        }

        if (isset($field_lookup['qr_tree_city']) && $this->get_tree_field_value_from_request('qr_tree_city') === '') {
            wc_add_notice('Please enter a city.', 'error');
            return false;
        }

        return true;
    }

    public function add_tree_checkout_fields_to_cart($cart_item_data, $product_id) {
        if (!$this->is_tree_product($product_id)) {
            return $cart_item_data;
        }

        $field_values = [
            'purchaser_type'        => isset($_POST['purchaser_type']) ? $this->sanitize_tree_field_value('purchaser_type', wp_unslash($_POST['purchaser_type'])) : '',
            'individual_first_name' => isset($_POST['individual_first_name']) ? sanitize_text_field(wp_unslash($_POST['individual_first_name'])) : '',
            'individual_last_name'  => isset($_POST['individual_last_name']) ? sanitize_text_field(wp_unslash($_POST['individual_last_name'])) : '',
            'pay_forward_type'      => isset($_POST['pay_forward_type']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_type'])) : '',
            'pay_forward_contact'   => (isset($_POST['pay_forward_type']) && sanitize_text_field(wp_unslash($_POST['pay_forward_type'])) === 'specific') ? (isset($_POST['pay_forward_contact']) ? sanitize_text_field(wp_unslash($_POST['pay_forward_contact'])) : '') : '',
            'qr_tree_postcode'      => isset($_POST['qr_tree_postcode']) ? $this->sanitize_tree_field_value('qr_tree_postcode', wp_unslash($_POST['qr_tree_postcode'])) : '',
            'qr_tree_city'          => isset($_POST['qr_tree_city']) ? $this->sanitize_tree_field_value('qr_tree_city', wp_unslash($_POST['qr_tree_city'])) : '',
            'qr_tree_message_1'     => isset($_POST['qr_tree_message_1']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_1'])) : '',
            'qr_tree_message_2'     => isset($_POST['qr_tree_message_2']) ? wp_kses_post(wp_unslash($_POST['qr_tree_message_2'])) : '',
            'church_org_website'    => isset($_POST['church_org_website']) ? $this->sanitize_tree_field_value('church_org_website', wp_unslash($_POST['church_org_website'])) : '',
        ];

        foreach ($field_values as $key => $value) {
            if ($value !== '') {
                $cart_item_data[$key] = $value;
            }
        }

        return $cart_item_data;
    }

    public function display_tree_checkout_fields_in_cart($item_data, $cart_item) {
        $all_labels = $this->get_tree_checkout_field_choices();
        foreach ($this->get_tree_field_display_order() as $key) {
            if (!isset($all_labels[$key])) {
                continue;
            }
            if (array_key_exists($key, $cart_item) && $cart_item[$key] !== '') {
                $display_value = $key === 'qr_tree_show_shop_link'
                    ? (((int) $cart_item[$key]) === 1 ? 'Yes' : 'No')
                    : $cart_item[$key];
                $item_data[] = [
                    'key' => $all_labels[$key],
                    'value' => wp_kses_post($display_value),
                ];
            }
        }

        return $item_data;
    }

    public function save_tree_checkout_fields_to_order_items($item, $cart_item_key, $values) {
        $all_labels = $this->get_tree_checkout_field_choices();
        $session_checkout_values = $this->get_tree_checkout_session_values();

        foreach ($this->get_tree_field_display_order() as $field) {
            if (!isset($all_labels[$field])) {
                continue;
            }

            if (array_key_exists($field, $values)) {
                $field_value = $values[$field];
            } elseif (isset($session_checkout_values[$field])) {
                $field_value = $session_checkout_values[$field];
            } else {
                $field_value = '';
            }

            if ($field_value !== '') {
                $item->add_meta_data($all_labels[$field], $field_value);
                $item->add_meta_data('_' . $field, $field_value);
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

        $row = [
            'url'        => $url,
            'short_code' => $short_code,
            'edit_token' => $this->generate_unique_edit_token(),
            'postcode'   => $tree_data['postcode'],
            'city'       => $tree_data['city'],
            'tree'       => $tree_data['tree'],
            'label'      => $tree_data['label'],
            'message_1'           => $tree_data['message_1'],
            'message_2'           => $tree_data['message_2'],
            'church_org_website'  => $tree_data['church_org_website'] ?? '',
        ];

        if (!empty($tree_data['team_id'])) {
            $row['team_id'] = (int) $tree_data['team_id'];
        }

        $wpdb->insert($this->main_table, $row);

        return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    }

    private function count_trees_for_postcode($postcode) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->main_table} WHERE postcode = %s",
            $postcode
        ));
    }

    private function tree_combination_exists($postcode, $city, $tree) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->main_table} WHERE LOWER(postcode) = LOWER(%s) AND LOWER(city) = LOWER(%s) AND LOWER(tree) = LOWER(%s) LIMIT 1",
            $postcode, $city, $tree
        ));
    }

    public function add_pay_forward_cart_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $pay_forward_product_id = (int) get_option('qr_tracker_pay_forward_product_id', 0);
        if ($pay_forward_product_id <= 0) {
            return;
        }

        $product = wc_get_product($pay_forward_product_id);
        if (!$product) {
            return;
        }

        $price = (float) $product->get_price();
        if ($price <= 0) {
            return;
        }

        // Check whether any tree item in the cart has pay-forward selected.
        // pay_forward_type is stored in cart item data (product-page field) or session (checkout field).
        $has_pay_forward = false;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['pay_forward_type'])) {
                $has_pay_forward = true;
                break;
            }
        }

        // Also check the session for checkout-page placement.
        if (!$has_pay_forward) {
            $session_values = $this->get_tree_checkout_session_values();
            if (!empty($session_values['pay_forward_type'])) {
                $has_pay_forward = true;
            }
        }

        if (!$has_pay_forward) {
            return;
        }

        $cart->add_fee('Pay a Tree Forward', $price, true);
    }

    /**
     * Resolve or create the team for a WooCommerce order item based on purchaser_type.
     *
     * - If purchaser_type is 'individual': creates a new private team named
     *   "{first_name} {last_name}" and returns its ID plus metadata used for emailing.
     * - Otherwise: treats purchaser_type as a team name, looks it up, and returns its ID.
     *
     * @param object $item WC_Order_Item_Product
     * @return array {
     *     @type int    $team_id       Team ID, or 0 if unresolvable.
     *     @type bool   $is_individual True when a new private individual team was created.
     *     @type string $purchaser_name Full name of the individual purchaser (when is_individual is true).
     * }
     */
    private function resolve_team_id_for_order_item($item, $order = null) {
        $all_labels  = $this->get_tree_checkout_field_choices();
        $purchaser_type_label = isset($all_labels['purchaser_type']) ? $all_labels['purchaser_type'] : 'Purchasing As';

        $purchaser_type = sanitize_text_field(
            $this->get_tree_field_from_order_item($item, 'purchaser_type', $purchaser_type_label)
        );

        if ($purchaser_type === 'individual') {
            $first_name_label = isset($all_labels['individual_first_name']) ? $all_labels['individual_first_name'] : 'First Name';
            $last_name_label  = isset($all_labels['individual_last_name'])  ? $all_labels['individual_last_name']  : 'Last Name';

            $first_name = sanitize_text_field($this->get_tree_field_from_order_item($item, 'individual_first_name', $first_name_label));
            $last_name  = sanitize_text_field($this->get_tree_field_from_order_item($item, 'individual_last_name',  $last_name_label));

            // Fall back to billing address if not captured on the product form.
            if ($first_name === '' && $order) {
                $first_name = sanitize_text_field($order->get_billing_first_name());
            }
            if ($last_name === '' && $order) {
                $last_name = sanitize_text_field($order->get_billing_last_name());
            }

            $team_name = trim($first_name . ' ' . $last_name);
            if ($team_name === '') {
                $team_name = 'Individual';
            }

            // Create a new private team for this individual purchase.
            $team_id = $this->teams->create_team($team_name, '', '', 1);
            return [
                'team_id'        => $team_id ? (int) $team_id : 0,
                'is_individual'  => true,
                'purchaser_name' => $team_name,
            ];
        }

        // purchaser_type holds the team name — look it up.
        if ($purchaser_type !== '') {
            $team = $this->teams->get_team_by_name($purchaser_type);
            if ($team) {
                return [
                    'team_id'        => (int) $team->id,
                    'is_individual'  => false,
                    'purchaser_name' => '',
                ];
            }
        }

        return ['team_id' => 0, 'is_individual' => false, 'purchaser_name' => ''];
    }


    public function create_qr_records_for_completed_order($order_id) {
        if (empty($order_id)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Process each order once. Historical orders carry _qr_tracker_rows_created;
        // orders processed since per-product emails were added also carry
        // _qr_tracker_welcome_sent (covers products that email without creating rows).
        if ($order->get_meta('_qr_tracker_rows_created', true) || $order->get_meta('_qr_tracker_welcome_sent', true)) {
            return;
        }

        $product_emails = get_option('qr_tracker_product_emails', []);
        if (!is_array($product_emails)) {
            $product_emails = [];
        }

        $inserted_count = 0;
        // One bucket per distinct product that should receive a welcome email,
        // keyed by the email product id so each product yields a single email.
        $buckets = [];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $variation_id = (int) $item->get_variation_id();
            $product_id   = $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
            if (!$product_id) {
                continue;
            }

            $is_tree    = $this->is_tree_product($product_id);
            $email_pid  = $this->resolve_welcome_email_product_id($product_id, $product_emails);
            $has_custom = isset($product_emails[$email_pid]);

            // Only email about tree products (which carry QR codes) or products
            // that have their own configured welcome email.
            if (!$is_tree && !$has_custom) {
                continue;
            }

            if (!isset($buckets[$email_pid])) {
                $buckets[$email_pid] = ['record_ids' => [], 'is_individual' => false, 'purchaser_name' => ''];
            }

            if (!$is_tree) {
                continue; // Non-tree custom-email product: no QR records to create.
            }

            $postcode           = strtoupper(sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_postcode', 'Postcode')));
            $city               = strtolower(sanitize_text_field($this->get_tree_field_from_order_item($item, 'qr_tree_city', 'City')));
            $message_1          = wp_kses_post($this->get_tree_field_from_order_item($item, 'qr_tree_message_1', 'Message 1'));
            $message_2          = wp_kses_post($this->get_tree_field_from_order_item($item, 'qr_tree_message_2', 'Message 2'));
            $church_org_website = esc_url_raw($this->get_tree_field_from_order_item($item, 'church_org_website', 'Church / Organisation Website Link'));

            if (empty($postcode) || empty($city)) {
                continue;
            }

            // Resolve the team this purchase belongs to.
            $team_info = $this->resolve_team_id_for_order_item($item, $order);
            $team_id   = $team_info['team_id'];
            if ($team_info['is_individual']) {
                $buckets[$email_pid]['is_individual'] = true;
            }
            if ($buckets[$email_pid]['purchaser_name'] === '' && $team_info['purchaser_name'] !== '') {
                $buckets[$email_pid]['purchaser_name'] = $team_info['purchaser_name'];
            }

            $quantity       = max(1, (int) $item->get_quantity());
            $existing_count = $this->count_trees_for_postcode($postcode);
            $item_inserted  = 0;

            for ($i = 0; $i < $quantity; $i++) {
                $tree_number = $existing_count + $item_inserted + 1;
                $tree_label  = 'Tree' . $tree_number;

                // Guard against race-condition collisions: skip numbers already taken.
                while ($this->tree_combination_exists($postcode, $city, $tree_label)) {
                    $tree_number++;
                    $tree_label = 'Tree' . $tree_number;
                }

                $record_id = $this->insert_tree_qr_record([
                    'postcode'           => $postcode,
                    'city'               => $city,
                    'tree'               => $tree_label,
                    'label'              => $tree_label,
                    'message_1'          => $message_1,
                    'message_2'          => $message_2,
                    'team_id'            => $team_id,
                    'church_org_website' => $church_org_website,
                ]);

                if ($record_id > 0) {
                    $inserted_count++;
                    $item_inserted++;
                    $buckets[$email_pid]['record_ids'][] = $record_id;
                }
            }
        }

        if (empty($buckets)) {
            return;
        }

        // Send one welcome email per distinct product, each with its own QR codes.
        $emails_sent  = 0;
        $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        foreach ($buckets as $email_pid => $bucket) {
            $records    = $this->fetch_qr_records_by_ids($bucket['record_ids']);
            $has_custom = isset($product_emails[$email_pid]);

            // Nothing to say: a tree product that produced no records and has no custom email.
            if (empty($records) && !$has_custom) {
                continue;
            }

            $name = $bucket['purchaser_name'] !== '' ? $bucket['purchaser_name'] : $billing_name;
            if ($this->email->send_welcome_email($order, $records, $name, $bucket['is_individual'], (int) $email_pid)) {
                $emails_sent++;
            }
        }

        if ($inserted_count > 0) {
            $order->update_meta_data('_qr_tracker_rows_created', 1);
        }
        if ($inserted_count > 0 || $emails_sent > 0) {
            $order->update_meta_data('_qr_tracker_welcome_sent', 1);
            $order->save();
        }
    }

    /**
     * Resolve the product id used to look up a welcome-email override for a line
     * item, and to group line items into one email per product. A custom email
     * configured on the exact product (or variation) wins; otherwise variations
     * are grouped under their parent so one product yields one email.
     *
     * @param int   $product_id     Line-item product/variation id.
     * @param array $product_emails Configured per-product overrides, keyed by id.
     * @return int
     */
    private function resolve_welcome_email_product_id($product_id, array $product_emails) {
        $product_id = (int) $product_id;
        if (isset($product_emails[$product_id])) {
            return $product_id;
        }
        $parent_id = (int) wp_get_post_parent_id($product_id);
        if ($parent_id > 0 && isset($product_emails[$parent_id])) {
            return $parent_id;
        }
        return $parent_id > 0 ? $parent_id : $product_id;
    }

    /**
     * Fetch QR tracker rows for the given ids (empty in, empty out).
     *
     * @param int[] $ids
     * @return array stdClass rows.
     */
    private function fetch_qr_records_by_ids(array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $records = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT id, url, short_code, postcode, city, tree, label, team_id FROM {$this->main_table} WHERE id IN ($placeholders)",
                ...$ids
            )
        );
        return $records ?: [];
    }
}

new QRCodeTracker();
