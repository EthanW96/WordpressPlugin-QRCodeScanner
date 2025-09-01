<?php
// Popup display logic for QR Code Tracker
class QRCodeTracker_Popup {
    private $tracker;

    public function __construct($tracker) {
        $this->tracker = $tracker;
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_popup']);
        add_action('wp_ajax_qr_tracker_get_messages', [$this, 'ajax_get_messages']);
        add_action('wp_ajax_nopriv_qr_tracker_get_messages', [$this, 'ajax_get_messages']);
        add_shortcode('qr_tracker_popup', [$this, 'popup_shortcode']);
    }

    /**
     * Enqueue necessary scripts and styles for the popup
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'qr-tracker-popup',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/qr-tracker-popup.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('qr-tracker-popup', 'qrTrackerPopup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qr_tracker_popup_nonce'),
            'showPopup' => $this->should_show_popup(),
            'settings' => $this->get_popup_settings()
        ]);

        wp_enqueue_style(
            'qr-tracker-popup-style',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/qr-tracker-popup.css',
            [],
            '1.0.0'
        );
    }

    /**
     * Check if popup should be shown based on current QR code scan
     */
    private function should_show_popup() {
        $current_tracker = $this->tracker->get_current_tracker();
        return $current_tracker && 
               !empty($current_tracker->message_1) && 
               !empty($current_tracker->message_2) && 
               $current_tracker->show_popup;
    }

    /**
     * Get shop link HTML for popup
     */
    private function get_shop_link_html() {
        $current_tracker = $this->tracker->get_current_tracker();
        
        if (!$current_tracker || !$current_tracker->show_shop_link) {
            return '';
        }
        
        // Get shop link and logo from QR code entry, fallback to defaults
        $shop_link = !empty($current_tracker->shop_link) ? $current_tracker->shop_link : get_option('qr_tracker_default_shop_link', '');
        $shop_logo = !empty($current_tracker->shop_logo) ? $current_tracker->shop_logo : get_option('qr_tracker_default_shop_logo', '');
        
        if (empty($shop_link) || empty($shop_logo)) {
            return '';
        }
        
        $output = '<div class="qr-shop-link">';
        $output .= '<a href="' . esc_url($shop_link) . '" target="_blank" rel="noopener noreferrer">';
        $output .= '<img src="' . esc_url($shop_logo) . '" alt="Shop Logo" style="max-width: 200px; height: auto;">';
        $output .= '</a>';
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render the popup HTML structure
     */
    public function render_popup() {
        if (!$this->should_show_popup()) {
            return;
        }

        $current_tracker = $this->tracker->get_current_tracker();
        $shop_link_html = $this->get_shop_link_html();
        ?>
        <div id="qr-tracker-popup" class="qr-tracker-popup" style="display: none;">
            <div class="qr-tracker-popup-content">
                <div class="qr-tracker-popup-header">
                    <button class="qr-tracker-popup-close" id="qr-tracker-popup-close">&times;</button>
                </div>
                <div class="qr-tracker-popup-body">
                    <div class="qr-tracker-message-container">
                        <div class="qr-tracker-message" id="qr-tracker-message-1">
                            <?php echo wp_kses_post($current_tracker->message_1); ?>
                        </div>
                        <div class="qr-tracker-message" id="qr-tracker-message-2">
                            <?php echo wp_kses_post($current_tracker->message_2); ?>
                        </div>
                        <?php if (!empty($shop_link_html)): ?>
                            <div class="qr-shop-link-container">
                                <?php echo $shop_link_html; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to get messages for popup
     */
    public function ajax_get_messages() {
        check_ajax_referer('qr_tracker_popup_nonce', 'nonce');

        $current_tracker = $this->tracker->get_current_tracker();
        
        if (!$current_tracker) {
            wp_send_json_error('No QR code data found');
            return;
        }

        $response = [
            'success' => true,
            'message_1' => $current_tracker->message_1,
            'message_2' => $current_tracker->message_2,
            'postcode' => $current_tracker->postcode,
            'tree' => $current_tracker->tree,
            'shop_link_html' => $this->get_shop_link_html()
        ];

        wp_send_json($response);
    }

    /**
     * Shortcode to manually trigger popup
     */
    public function popup_shortcode($atts) {
        $atts = shortcode_atts([
            'text' => 'Show Messages',
            'class' => 'qr-tracker-popup-trigger',
            'style' => ''
        ], $atts);

        $current_tracker = $this->tracker->get_current_tracker();
        if (!$current_tracker || (empty($current_tracker->message_1) && empty($current_tracker->message_2)) || !$current_tracker->show_popup) {
            return '';
        }

        $button_text = esc_html($atts['text']);
        $button_class = esc_attr($atts['class']);
        $button_style = esc_attr($atts['style']);

        return sprintf(
            '<button type="button" class="%s" style="%s" onclick="QRTrackerPopup.show();">%s</button>',
            $button_class,
            $button_style,
            $button_text
        );
    }

    /**
     * Get popup settings from WordPress options
     */
    public function get_popup_settings() {
        return [
            'auto_show' => get_option('qr_tracker_popup_auto_show', true),
            'delay' => get_option('qr_tracker_popup_delay', 1000),
            'position' => get_option('qr_tracker_popup_position', 'bottom-right'),
            'theme' => get_option('qr_tracker_popup_theme', 'default')
        ];
    }
} 