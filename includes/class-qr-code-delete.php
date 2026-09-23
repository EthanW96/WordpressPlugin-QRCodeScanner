<?php
/**
 * Guarded deletion of a QR code from the Tracked QR Codes table.
 *
 * Only QR codes with no recorded visits can be deleted, but that includes a
 * code that has been purchased and printed and simply not scanned yet. So
 * deleting is a two-step flow: a nonced link opens a confirmation page, and
 * the delete itself is a nonced POST that only succeeds when the user types
 * the code's short code. Every rule is re-checked on the POST.
 */
class QRCodeTracker_Delete {

    const CONFIRM_ACTION = 'qr_tracker_delete_confirm_';
    const DELETE_ACTION  = 'qr_tracker_delete_';

    // A code created this recently is likely purchased and printed for the
    // current season, so the confirmation warns about it prominently.
    const RECENT_DAYS = 365;

    // Typed instead of the short code for legacy rows that have none.
    const FALLBACK_CONFIRM_WORD = 'DELETE';

    private $main_table;
    private $teams;

    public function __construct($teams) {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->teams      = $teams;
    }

    /**
     * The Delete link for a row of the Tracked QR Codes table.
     */
    public static function link_url($qr_id) {
        $url = add_query_arg(['page' => 'qr-tracker', 'delete_id' => (int) $qr_id], admin_url('admin.php'));
        return wp_nonce_url($url, self::CONFIRM_ACTION . (int) $qr_id);
    }

    /**
     * Handle a delete request, if there is one.
     *
     * @return bool True when the confirmation page was rendered and the rest
     *              of the QR code screen should not be.
     */
    public function handle() {
        if (isset($_POST['qr_delete_submit'])) {
            $this->handle_delete();
            return false;
        }
        if (isset($_GET['delete_id'])) {
            return $this->handle_confirm_page();
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Step 1: confirmation page
    // ------------------------------------------------------------------

    private function handle_confirm_page() {
        $qr_id = absint($_GET['delete_id']);
        check_admin_referer(self::CONFIRM_ACTION . $qr_id);

        $row = $this->load_deletable($qr_id);
        if (!$row) {
            return false;
        }

        $this->render_confirm_page($row);
        return true;
    }

    private function render_confirm_page($row) {
        $expected = $this->expected_confirmation($row);

        echo '<h2>Delete QR code</h2>';
        if ($this->is_recent($row)) {
            echo '<div class="notice notice-error inline"><p><strong>This QR code was created in the last ' . (int) self::RECENT_DAYS . ' days.</strong> '
                . 'It is likely a purchased code that has been printed for this season and simply has not been scanned yet. '
                . 'Deleting it means anyone scanning a printed copy lands on a page with no tree.</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:640px"><tbody>';
        $this->detail_row('Postcode / City / Tree', $row->postcode . ' / ' . $row->city . ' / ' . $row->tree);
        $this->detail_row('Label', (string) $row->label);
        $this->detail_row('Short code', (string) $row->short_code);
        $this->detail_row('URL', (string) $row->url);
        $this->detail_row('Created', (string) $row->created_at);
        echo '</tbody></table>';

        echo '<p>If this code may already be printed, consider <a href="' . esc_url(QRCodeTracker_Merge_Page::url(['merge_id' => (int) $row->id])) . '">merging it into another QR code</a> instead: '
            . 'the merge can keep its printed code working.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=qr-tracker')) . '">';
        wp_nonce_field(self::DELETE_ACTION . (int) $row->id);
        echo '<input type="hidden" name="qr_delete_id" value="' . (int) $row->id . '">';
        echo '<p><label for="qr-delete-confirm">To permanently delete this QR code, type <code>' . esc_html($expected) . '</code>:</label><br>';
        echo '<input type="text" id="qr-delete-confirm" name="qr_delete_confirm" class="regular-text" autocomplete="off" required></p>';
        echo '<p><button type="submit" name="qr_delete_submit" value="1" class="button button-primary button-large" style="background:#d63638;border-color:#d63638">Delete permanently</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-tracker')) . '">Cancel</a></p>';
        echo '</form>';
    }

    private function detail_row($label, $value) {
        echo '<tr><th scope="row" style="width:200px">' . esc_html($label) . '</th><td>'
            . ($value === '' ? '<em>(empty)</em>' : esc_html($value)) . '</td></tr>';
    }

    // ------------------------------------------------------------------
    // Step 2: the delete itself
    // ------------------------------------------------------------------

    private function handle_delete() {
        global $wpdb;

        $qr_id = isset($_POST['qr_delete_id']) ? absint($_POST['qr_delete_id']) : 0;
        check_admin_referer(self::DELETE_ACTION . $qr_id);

        $row = $this->load_deletable($qr_id);
        if (!$row) {
            return;
        }

        $typed = isset($_POST['qr_delete_confirm']) ? trim(sanitize_text_field(wp_unslash($_POST['qr_delete_confirm']))) : '';
        if (strcasecmp($typed, $this->expected_confirmation($row)) !== 0) {
            $this->notice('error', 'The confirmation did not match, so QR code ' . $this->describe($row) . ' was not deleted.');
            return;
        }

        $deleted = $wpdb->delete($this->main_table, ['id' => (int) $row->id]);
        if ($deleted !== 1) {
            error_log('[QR Tracker delete] Failed to delete QR code ' . (int) $row->id . ': ' . $wpdb->last_error);
            $this->notice('error', 'QR code ' . $this->describe($row) . ' could not be deleted. Nothing was changed.');
            return;
        }

        $this->notice('updated', 'QR code ' . $this->describe($row) . ' was deleted.');
    }

    // ------------------------------------------------------------------
    // Shared rules
    // ------------------------------------------------------------------

    /**
     * The row, if this user may delete it; otherwise a notice and null.
     * Applied on both steps: permission, team access, and no visits.
     */
    private function load_deletable($qr_id) {
        global $wpdb;

        if (!QRCodeTracker_Permissions::can_delete_qr_codes()) {
            $this->notice('error', 'You do not have permission to delete QR codes.');
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $qr_id));
        if (!$row) {
            $this->notice('error', 'QR Code not found.');
            return null;
        }
        if ($row->team_id && !$this->teams->user_can_access_team(get_current_user_id(), $row->team_id)) {
            $this->notice('error', 'You do not have permission to delete this QR code.');
            return null;
        }
        if ((int) $row->scan_count !== 0 || (int) $row->social_share_count !== 0) {
            echo '<div class="error"><p>Cannot delete QR code with existing scan or social share data. Use <a href="'
                . esc_url(QRCodeTracker_Merge_Page::url(['merge_id' => (int) $row->id])) . '">Merge QR Codes</a> instead.</p></div>';
            return null;
        }

        return $row;
    }

    private function expected_confirmation($row) {
        return !empty($row->short_code) ? (string) $row->short_code : self::FALLBACK_CONFIRM_WORD;
    }

    private function is_recent($row) {
        if (empty($row->created_at)) {
            return false;
        }
        $created = strtotime($row->created_at . ' UTC');
        return $created !== false && $created >= time() - self::RECENT_DAYS * DAY_IN_SECONDS;
    }

    private function describe($row) {
        return sprintf('%s / %s / %s (ID %d)', $row->postcode, $row->city, $row->tree, (int) $row->id);
    }

    private function notice($class, $message) {
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }
}
