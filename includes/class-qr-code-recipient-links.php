<?php
/**
 * Which email receives update emails about which QR code.
 *
 * Links come from three places: an order's billing email when its trees are
 * created (source "order"), the backfill for orders placed before this
 * existed (source "backfill"), and an admin (source "manual"). An admin can
 * add, remove or reassign any link at any time, including automatic ones.
 *
 * There is one row per (email, QR code). Automatic linking only ever inserts
 * a row that does not exist yet, so it can never undo an admin's edit: a link
 * an admin removed stays as a row with is_removed = 1 rather than being
 * deleted, and automatic linking will not add it back.
 */
class QRCodeTracker_Recipient_Links {

    const SOURCE_ORDER    = 'order';
    const SOURCE_BACKFILL = 'backfill';
    const SOURCE_MANUAL   = 'manual';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'qr_tracker_recipient_links';
    }

    public static function normalize_email($email) {
        return strtolower(trim(sanitize_email((string) $email)));
    }

    // ------------------------------------------------------------------
    // Automatic linking
    // ------------------------------------------------------------------

    /**
     * Link QR codes to an email automatically. Existing rows for the same
     * (email, QR code) — live, manual or removed — are left untouched.
     *
     * @return int Links created.
     */
    public static function link_automatically($email, $name, array $tracker_ids, $order_id, $source) {
        $email = self::normalize_email($email);
        if ($email === '' || !is_email($email)) {
            return 0;
        }

        global $wpdb;
        $created = 0;
        foreach (array_unique(array_map('intval', $tracker_ids)) as $tracker_id) {
            if ($tracker_id <= 0) {
                continue;
            }
            // INSERT IGNORE relies on the (email, tracker_id) unique key, so a
            // row an admin has edited or removed is never overwritten.
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . " (email, name, tracker_id, order_id, source, created_at) VALUES (%s, %s, %d, %d, %s, %s)",
                $email, (string) $name, $tracker_id, (int) $order_id, $source, current_time('mysql', 1)
            ));
            if ($result === false) {
                error_log('[QR Tracker links] Could not link ' . $tracker_id . ' to an order email: ' . $wpdb->last_error);
                continue;
            }
            $created += (int) $result;
        }
        return $created;
    }

    // ------------------------------------------------------------------
    // Admin edits
    // ------------------------------------------------------------------

    /**
     * Add (or restore) a link by hand.
     *
     * @return true|string True, or an error message.
     */
    public static function add_manual($email, $name, $tracker_id) {
        $email = self::normalize_email($email);
        if ($email === '' || !is_email($email)) {
            return 'Enter a valid email address.';
        }

        global $wpdb;
        $existing = self::find($email, $tracker_id);
        if ($existing) {
            $result = $wpdb->update(self::table(), [
                'name'       => (string) $name !== '' ? (string) $name : $existing->name,
                'is_removed' => 0,
                'is_manual'  => 1,
                'updated_by' => get_current_user_id(),
                'updated_at' => current_time('mysql', 1),
            ], ['id' => (int) $existing->id]);
        } else {
            $result = $wpdb->insert(self::table(), [
                'email'      => $email,
                'name'       => (string) $name,
                'tracker_id' => (int) $tracker_id,
                'source'     => self::SOURCE_MANUAL,
                'is_manual'  => 1,
                'updated_by' => get_current_user_id(),
                'created_at' => current_time('mysql', 1),
                'updated_at' => current_time('mysql', 1),
            ]);
        }

        if ($result === false) {
            error_log('[QR Tracker links] Could not add a link: ' . $wpdb->last_error);
            return 'The link could not be saved.';
        }
        return true;
    }

    /**
     * Remove a link by hand. The row is kept, flagged removed, so automatic
     * linking never re-adds it.
     *
     * @return true|string True, or an error message.
     */
    public static function remove_manual($link_id) {
        global $wpdb;
        $result = $wpdb->update(self::table(), [
            'is_removed' => 1,
            'is_manual'  => 1,
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql', 1),
        ], ['id' => (int) $link_id]);

        if ($result === false) {
            error_log('[QR Tracker links] Could not remove link ' . (int) $link_id . ': ' . $wpdb->last_error);
            return 'The link could not be removed.';
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public static function find($email, $tracker_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE email = %s AND tracker_id = %d",
            self::normalize_email($email), (int) $tracker_id
        ));
    }

    public static function get($link_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", (int) $link_id));
    }

    /**
     * Live links grouped by QR code, for the editor.
     *
     * @return array [tracker_id => link rows]
     */
    public static function live_by_tracker() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE is_removed = 0 ORDER BY email ASC");

        $grouped = [];
        foreach ((array) $rows as $row) {
            $grouped[(int) $row->tracker_id][] = $row;
        }
        return $grouped;
    }

    /**
     * Every live link, with its QR code resolved through merges: a link to a
     * merged-away code counts for the code it was merged into, and a link to
     * a deleted code is dropped.
     *
     * @param array $live_ids Set of existing QR code ids (id => true).
     * @return array Link rows with an added resolved_tracker_id.
     */
    public static function live_resolved(array $live_ids) {
        global $wpdb;
        $rows    = $wpdb->get_results("SELECT * FROM " . self::table() . " WHERE is_removed = 0");
        $aliases = self::alias_targets();

        $resolved = [];
        foreach ((array) $rows as $row) {
            $tracker_id = (int) $row->tracker_id;
            if (!isset($live_ids[$tracker_id]) && isset($aliases[$tracker_id])) {
                $tracker_id = $aliases[$tracker_id];
            }
            if (!isset($live_ids[$tracker_id])) {
                continue;
            }
            $row->resolved_tracker_id = $tracker_id;
            $resolved[] = $row;
        }
        return $resolved;
    }

    /**
     * Order ids that already have at least one link, so the backfill skips
     * them.
     *
     * @return array Set of order ids (id => true).
     */
    public static function linked_order_ids() {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT DISTINCT order_id FROM " . self::table() . " WHERE order_id IS NOT NULL AND order_id > 0");
        return array_fill_keys(array_map('intval', (array) $ids), true);
    }

    /**
     * QR code ids already linked to some order, so the backfill does not
     * hand the same tree to a second order.
     *
     * @return array Set of tracker ids (id => true).
     */
    public static function order_claimed_tracker_ids() {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT DISTINCT tracker_id FROM " . self::table() . " WHERE order_id IS NOT NULL AND order_id > 0");
        return array_fill_keys(array_map('intval', (array) $ids), true);
    }

    private static function alias_targets() {
        if (!QRCodeTracker_Aliases::any_exist()) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT original_tracker_id, target_tracker_id FROM " . QRCodeTracker_Aliases::table());

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->original_tracker_id] = (int) $row->target_tracker_id;
        }
        return $map;
    }
}
