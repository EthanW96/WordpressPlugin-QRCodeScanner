<?php

class QRCodeTracker_WooCommerce {
    private const DEFAULT_REFERRAL_PREFIX = 'REF';
    private const DEFAULT_REFERRAL_CODE_LENGTH = 8;
    private const DEFAULT_POSTCODE_PREFIX = 'WC';
    private const DEFAULT_CITY_NAME = 'Woo';

    private $tracker;
    private $main_table;

    public function __construct($tracker) {
        global $wpdb;
        $this->tracker = $tracker;
        $this->main_table = $wpdb->prefix . 'qr_tracker';

        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('woocommerce_checkout_fields', [$this, 'add_checkout_fields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_checkout_fields'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_checkout_fields'], 10, 2);
        add_action('woocommerce_order_status_processing', [$this, 'create_qr_codes_for_order']);
        add_action('woocommerce_order_status_completed', [$this, 'create_qr_codes_for_order']);
    }

    public function add_checkout_fields($fields) {
        $fields['order']['qr_tree_contact_emails'] = [
            'type' => 'textarea',
            'label' => __('Tree Contact Email(s)', 'qr-code-tracker'),
            'required' => true,
            'placeholder' => __('comma separated', 'qr-code-tracker'),
            'priority' => 25,
        ];

        $fields['order']['qr_tree_report_emails'] = [
            'type' => 'textarea',
            'label' => __('Weekly Report Email(s)', 'qr-code-tracker'),
            'required' => false,
            'placeholder' => __('comma separated', 'qr-code-tracker'),
            'priority' => 26,
        ];

        $fields['order']['qr_tree_buyer_name'] = [
            'type' => 'text',
            'label' => __('Org Name / Individual Name', 'qr-code-tracker'),
            'required' => true,
            'priority' => 27,
        ];

        $fields['order']['qr_tree_referral_code'] = [
            'type' => 'text',
            'label' => __('Referral Code (optional)', 'qr-code-tracker'),
            'required' => false,
            'priority' => 28,
        ];

        $fields['order']['qr_tree_pay_forward_type'] = [
            'type' => 'select',
            'label' => __('Pay a Tree Forward', 'qr-code-tracker'),
            'required' => false,
            'priority' => 29,
            'options' => [
                '' => __('No', 'qr-code-tracker'),
                'general' => __('Yes - General', 'qr-code-tracker'),
                'specific' => __('Yes - Specific Person', 'qr-code-tracker'),
            ],
        ];

        $fields['order']['qr_tree_pay_forward_recipient'] = [
            'type' => 'text',
            'label' => __('Pay Forward Recipient (if specific)', 'qr-code-tracker'),
            'required' => false,
            'priority' => 30,
        ];

        return $fields;
    }

    public function save_checkout_fields($order, $data) {
        $contact_emails = $this->normalize_emails(isset($_POST['qr_tree_contact_emails']) ? wp_unslash($_POST['qr_tree_contact_emails']) : '');
        $report_emails = $this->normalize_emails(isset($_POST['qr_tree_report_emails']) ? wp_unslash($_POST['qr_tree_report_emails']) : '');
        $buyer_name = sanitize_text_field(isset($_POST['qr_tree_buyer_name']) ? wp_unslash($_POST['qr_tree_buyer_name']) : '');
        $referral_code = sanitize_text_field(isset($_POST['qr_tree_referral_code']) ? wp_unslash($_POST['qr_tree_referral_code']) : '');
        $pay_forward_type = sanitize_text_field(isset($_POST['qr_tree_pay_forward_type']) ? wp_unslash($_POST['qr_tree_pay_forward_type']) : '');
        $pay_forward_recipient = sanitize_text_field(isset($_POST['qr_tree_pay_forward_recipient']) ? wp_unslash($_POST['qr_tree_pay_forward_recipient']) : '');

        $order->update_meta_data('_qr_tree_contact_emails', $contact_emails);
        $order->update_meta_data('_qr_tree_report_emails', $report_emails);
        $order->update_meta_data('_qr_tree_buyer_name', $buyer_name);
        $order->update_meta_data('_qr_tree_referral_code', $referral_code);
        $order->update_meta_data('_qr_tree_pay_forward_type', $pay_forward_type);
        $order->update_meta_data('_qr_tree_pay_forward_recipient', $pay_forward_recipient);
    }

    public function validate_checkout_fields($data, $errors) {
        $contact_emails = $this->normalize_emails(isset($_POST['qr_tree_contact_emails']) ? wp_unslash($_POST['qr_tree_contact_emails']) : '');
        if ($contact_emails === '') {
            $errors->add('qr_tree_contact_emails', __('Please enter at least one valid tree contact email.', 'qr-code-tracker'));
        }
    }

    public function create_qr_codes_for_order($order_id) {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $already_created = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->main_table} WHERE woocommerce_order_id = %d",
            $order_id
        ));
        if ($already_created > 0) {
            return;
        }

        $contact_emails = (string) $order->get_meta('_qr_tree_contact_emails');
        $report_emails = (string) $order->get_meta('_qr_tree_report_emails');
        $buyer_name = sanitize_text_field((string) $order->get_meta('_qr_tree_buyer_name'));
        $referral_code = sanitize_text_field((string) $order->get_meta('_qr_tree_referral_code'));
        $pay_forward_type = sanitize_text_field((string) $order->get_meta('_qr_tree_pay_forward_type'));
        $pay_forward_recipient = sanitize_text_field((string) $order->get_meta('_qr_tree_pay_forward_recipient'));

        if ($referral_code === '') {
            $referral_code = self::DEFAULT_REFERRAL_PREFIX . strtoupper($this->tracker->generate_unique_tracker_code(self::DEFAULT_REFERRAL_CODE_LENGTH));
        }

        $referral_url = add_query_arg('ref', rawurlencode($referral_code), home_url('/'));
        $billing_postcode = strtoupper(sanitize_text_field((string) $order->get_billing_postcode()));
        $billing_city = sanitize_text_field((string) $order->get_billing_city());

        $line_items = $order->get_items('line_item');
        foreach ($line_items as $item_id => $item) {
            $quantity = max(1, (int) $item->get_quantity());
            $product_name = sanitize_text_field((string) $item->get_name());

            for ($i = 1; $i <= $quantity; $i++) {
                $unique = $this->tracker->generate_unique_tracker_url(6);

                $tree_value = $this->sanitize_tree_value($product_name, $item_id, $i);
                // Billing postcode is uppercased before sanitizing, so only A-Z and 0-9 are allowed here.
                $postcode_value = $billing_postcode !== '' ? preg_replace('/[^A-Z0-9]/', '', $billing_postcode) : self::DEFAULT_POSTCODE_PREFIX . $order_id;
                $city_value = $billing_city !== '' ? preg_replace('/[^A-Za-z0-9-]/', '', $billing_city) : self::DEFAULT_CITY_NAME;

                $label = sprintf('Order %d %s (%d/%d)', $order_id, $product_name, $i, $quantity);
                $wpdb->insert($this->main_table, [
                    'url' => $unique['url'],
                    'legacy_url' => null,
                    'unique_code' => $unique['code'],
                    'postcode' => $postcode_value,
                    'city' => $city_value,
                    'tree' => $tree_value,
                    'label' => $label,
                    'reporting_id' => 'WC-' . $order_id,
                    'message_1' => 'Merry Christmas',
                    'message_2' => '',
                    'show_popup' => 1,
                    'show_shop_link' => 1,
                    'qr_source' => 'woocommerce',
                    'purchase_status' => 'pending_review',
                    'contact_emails' => $contact_emails,
                    'report_emails' => $report_emails,
                    'buyer_name' => $buyer_name,
                    'referral_code' => $referral_code,
                    'referral_url' => $referral_url,
                    'pay_tree_forward_type' => $pay_forward_type,
                    'pay_tree_forward_recipient' => $pay_forward_recipient,
                    'woocommerce_order_id' => $order_id,
                    'woocommerce_item_id' => $item_id,
                ]);
            }
        }
    }

    private function sanitize_tree_value($product_name, $item_id, $index) {
        $sanitized = preg_replace('/[^A-Za-z0-9-]/', '-', strtolower($product_name));
        $sanitized = trim(preg_replace('/-+/', '-', $sanitized), '-');
        if ($sanitized === '') {
            $sanitized = 'tree';
        }

        return $sanitized . '-' . $item_id . '-' . $index;
    }

    private function normalize_emails($raw) {
        $parts = preg_split('/[\s,;]+/', (string) $raw);
        $emails = [];
        foreach ((array) $parts as $part) {
            $email = sanitize_email($part);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return implode(', ', array_unique($emails));
    }
}
