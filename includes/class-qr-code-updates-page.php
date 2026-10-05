<?php
require_once __DIR__ . '/class-qr-code-updates-mailchimp-view.php';
require_once __DIR__ . '/class-qr-code-updates-recipients-view.php';

/**
 * The "Purchaser Updates" admin screen: connect Mailchimp, preview and sync
 * the stats each recipient is sent, and manage who receives updates about
 * which QR code.
 *
 * Every POST is nonced and needs the Manage Settings permission; link edits
 * also re-check team access to the QR code being changed.
 */
class QRCodeTracker_Updates_Page {

    const PAGE_SLUG    = 'qr-purchaser-updates';
    const NONCE_ACTION = 'qr_tracker_purchaser_updates';

    const TAB_MAILCHIMP  = 'mailchimp';
    const TAB_RECIPIENTS = 'recipients';

    private $tracker;
    private $teams;
    private $mailchimp_view;
    private $recipients_view;
    private $notices = [];

    public function __construct($tracker, $teams) {
        $this->tracker         = $tracker;
        $this->teams           = $teams;
        $this->mailchimp_view  = new QRCodeTracker_Updates_Mailchimp_View();
        $this->recipients_view = new QRCodeTracker_Updates_Recipients_View($teams);
    }

    public static function can_manage() {
        return QRCodeTracker_Permissions::can_manage_settings();
    }

    public static function url(array $args = []) {
        return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $args), admin_url('admin.php'));
    }

    public function render() {
        if (!self::can_manage()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_manage_settings');
            return;
        }

        $tab = (isset($_GET['tab']) && $_GET['tab'] === self::TAB_RECIPIENTS) ? self::TAB_RECIPIENTS : self::TAB_MAILCHIMP;
        $this->handle_post();

        echo '<div class="wrap qr-updates">';
        echo '<style>.qr-updates-ok{color:#00a32a}.qr-updates-error{color:#b32d2e;font-weight:600}'
            . '.qr-updates-chip{display:inline-block;margin:2px 4px 2px 0;padding:2px 6px;background:#f0f0f1;border-radius:3px}'
            . '.qr-updates-chip form{display:inline}.qr-updates-chip button{border:0;background:none;color:#b32d2e;cursor:pointer;padding:0 2px}'
            . '.qr-updates-codes tr[hidden]{display:none}</style>';
        echo '<h1>Purchaser Updates</h1>';
        $this->render_tabs($tab);
        foreach ($this->notices as $notice) {
            echo '<div class="notice notice-' . esc_attr($notice[0]) . ' inline"><p>' . esc_html($notice[1]) . '</p></div>';
        }

        if ($tab === self::TAB_RECIPIENTS) {
            $this->render_recipients_tab();
        } else {
            $this->render_mailchimp_tab();
        }
        echo '</div>';
    }

    private function render_tabs($current) {
        $tabs = [self::TAB_MAILCHIMP => 'Mailchimp sync', self::TAB_RECIPIENTS => 'Recipients'];
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            $class = 'nav-tab' . ($tab === $current ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(self::url(['tab' => $tab])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_mailchimp_tab() {
        $sync = $this->tracker->get_mailchimp_sync();
        $this->mailchimp_view->render(
            QRCodeTracker_Mailchimp_Sync::settings(),
            // Needs only the key: the audience is what this list lets you pick.
            $sync->get_lists(),
            $sync->refresh_status(),
            QRCodeTracker_Mailchimp_Sync::next_scheduled(),
            isset($_GET['preview']) ? $this->tracker->get_recipients()->build_all() : null
        );
    }

    private function render_recipients_tab() {
        $backfill = new QRCodeTracker_Link_Backfill($this->tracker);
        $this->recipients_view->render(
            $this->teams->get_accessible_qr_codes(),
            QRCodeTracker_Recipient_Links::live_by_tracker(),
            isset($_GET['backfill']) ? $backfill->plan() : null
        );
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    private function handle_post() {
        if (empty($_POST['qr_updates_action'])) {
            return;
        }
        check_admin_referer(self::NONCE_ACTION);

        $input = wp_unslash($_POST);
        switch (sanitize_key($input['qr_updates_action'])) {
            case 'save_settings':
                $this->save_settings($input);
                return;
            case 'sync_now':
                $this->sync_now();
                return;
            case 'add_link':
                $this->add_link($input);
                return;
            case 'remove_link':
                $this->remove_link($input);
                return;
            case 'apply_backfill':
                $this->apply_backfill($input);
                return;
        }
    }

    private function save_settings(array $input) {
        $errors = $this->tracker->get_mailchimp_sync()->save_settings(isset($input['mc']) && is_array($input['mc']) ? $input['mc'] : []);
        foreach ($errors as $error) {
            $this->notices[] = ['error', $error];
        }
        if (empty($errors)) {
            $this->notices[] = ['success', 'Settings saved.'];
        }
    }

    private function sync_now() {
        $result = $this->tracker->get_mailchimp_sync()->run('manual');
        $this->notices[] = [$result['ok'] ? 'success' : 'error', $result['message']];
    }

    private function add_link(array $input) {
        $tracker = $this->load_editable_tracker($input['tracker_id'] ?? 0);
        if (!$tracker) {
            return;
        }
        $result = QRCodeTracker_Recipient_Links::add_manual($input['email'] ?? '', sanitize_text_field($input['name'] ?? ''), (int) $tracker->id);
        $this->notices[] = $result === true
            ? ['success', 'Linked ' . QRCodeTracker_Recipient_Links::normalize_email($input['email'] ?? '') . ' to ' . $this->describe($tracker) . '.']
            : ['error', $result];
    }

    private function remove_link(array $input) {
        $link = QRCodeTracker_Recipient_Links::get((int) ($input['link_id'] ?? 0));
        if (!$link) {
            $this->notices[] = ['error', 'That link no longer exists.'];
            return;
        }
        $tracker = $this->load_editable_tracker($link->tracker_id);
        if (!$tracker) {
            return;
        }
        $result = QRCodeTracker_Recipient_Links::remove_manual((int) $link->id);
        $this->notices[] = $result === true
            ? ['success', 'Removed ' . $link->email . ' from ' . $this->describe($tracker) . '. Automatic linking will not add it back.']
            : ['error', $result];
    }

    private function apply_backfill(array $input) {
        $approved = isset($input['approve']) && is_array($input['approve']) ? $input['approve'] : [];
        $approved = array_map('sanitize_text_field', $approved);
        if (empty($approved)) {
            $this->notices[] = ['warning', 'No orders were selected.'];
            return;
        }

        $result  = (new QRCodeTracker_Link_Backfill($this->tracker))->apply($approved);
        $message = sprintf('Linked %d order(s) — %d new link(s).', $result['linked'], $result['links']);
        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d order(s) were skipped because their matches changed since you reviewed them; run the check again.', $result['skipped']);
        }
        $this->notices[] = [$result['skipped'] > 0 ? 'warning' : 'success', $message];
    }

    /**
     * The QR code, if it exists and this user may edit its team's links.
     */
    private function load_editable_tracker($tracker_id) {
        global $wpdb;
        $tracker = $wpdb->get_row($wpdb->prepare(
            "SELECT id, postcode, city, tree, team_id FROM {$wpdb->prefix}qr_tracker WHERE id = %d",
            (int) $tracker_id
        ));
        if (!$tracker) {
            $this->notices[] = ['error', 'That QR code no longer exists.'];
            return null;
        }
        if (!empty($tracker->team_id) && !$this->teams->user_can_access_team(get_current_user_id(), (int) $tracker->team_id)) {
            $this->notices[] = ['error', 'You do not have access to the team that owns that QR code.'];
            return null;
        }
        return $tracker;
    }

    private function describe($tracker) {
        return sprintf('%s / %s / %s', $tracker->postcode, $tracker->city, $tracker->tree);
    }

    /**
     * Opening tag, nonce and action for a form on this page.
     */
    public static function form_open($action, $tab, $extra_class = '') {
        $inline = ($extra_class === 'qr-updates-inline-form') ? ' style="display:inline"' : '';
        $html  = '<form method="post" action="' . esc_url(self::url(['tab' => $tab])) . '" class="' . esc_attr($extra_class) . '"' . $inline . '>';
        $html .= wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="qr_updates_action" value="' . esc_attr($action) . '">';
        return $html;
    }
}
