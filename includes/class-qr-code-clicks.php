<?php
/**
 * Counts clicks on a tree's Church / Organisation Website button.
 *
 * The button links to /?qr_go=<short_code>, which records the click and
 * redirects to that tree's stored website. The destination always comes from
 * the database, never from the request, so the endpoint cannot be used as an
 * open redirect. Clicks live in their own table, apart from the scan logs,
 * so scan counts and reports are unaffected.
 */
class QRCodeTracker_Clicks {

    const QUERY_VAR = 'qr_go';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'qr_tracker_clicks';
    }

    /**
     * Tracking URL for a tree's website button, or '' when the tree cannot be
     * tracked (no short code) and the plain link should be used instead.
     */
    public static function tracking_url($tracker) {
        if (!$tracker || empty($tracker->short_code) || empty($tracker->church_org_website)) {
            return '';
        }
        return add_query_arg(self::QUERY_VAR, rawurlencode((string) $tracker->short_code), home_url('/'));
    }

    /**
     * init hook: handle /?qr_go=<short_code>.
     */
    public function handle_request() {
        if (empty($_GET[self::QUERY_VAR])) {
            return;
        }

        $short_code = strtolower(sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])));
        $tracker    = $this->find_tracker($short_code);
        $target     = ($tracker && !empty($tracker->church_org_website)) ? esc_url_raw($tracker->church_org_website) : '';

        if ($target === '') {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }

        // A failure to record never stops the visitor reaching the website.
        $this->record_click((int) $tracker->id);

        // wp_redirect, not wp_safe_redirect: church websites are external by
        // design, and the target is the stored value, not request input.
        wp_redirect($target, 302);
        exit;
    }

    private function find_tracker($short_code) {
        if ($short_code === '') {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, church_org_website FROM {$wpdb->prefix}qr_tracker WHERE short_code = %s LIMIT 1",
            $short_code
        ));
        if ($row) {
            return $row;
        }

        // A merged-away code's button keeps counting for the code it became.
        return QRCodeTracker_Aliases::load_target(QRCodeTracker_Aliases::find_by_short_code($short_code));
    }

    private function record_click($tracker_id) {
        global $wpdb;

        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $user_agent  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $inserted = $wpdb->insert(self::table(), [
            'tracker_id'   => $tracker_id,
            'clicked_at'   => current_time('mysql', 1),
            'visitor_hash' => hash('sha256', $remote_addr . '|' . $user_agent),
        ]);

        if ($inserted === false) {
            error_log('[QR Tracker clicks] Could not record click for QR code ' . $tracker_id . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Click totals per QR code.
     *
     * @param int[] $tracker_ids
     * @return array [tracker_id => clicks]
     */
    public static function counts_for(array $tracker_ids) {
        $tracker_ids = array_values(array_unique(array_filter(array_map('intval', $tracker_ids))));
        if (empty($tracker_ids)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($tracker_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tracker_id, COUNT(*) AS clicks FROM " . self::table() . " WHERE tracker_id IN ($placeholders) GROUP BY tracker_id",
            $tracker_ids
        ));

        $counts = array_fill_keys($tracker_ids, 0);
        foreach ((array) $rows as $row) {
            $counts[(int) $row->tracker_id] = (int) $row->clicks;
        }
        return $counts;
    }
}
