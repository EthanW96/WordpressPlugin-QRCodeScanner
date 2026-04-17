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

        // Preferred matching by unique short code.
        $short_code = isset($_GET['qr']) ? sanitize_text_field($_GET['qr']) : '';
        if (!empty($short_code)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->main_table} WHERE short_code = %s",
                $short_code
            ));
        } else {
            $row = null;
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

    public function generate_unique_short_code($length = 6) {
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
