<?php
/**
 * Aliases keep a merged-away QR code working.
 *
 * When a merge removes a QR code and the user chose to keep it working, its
 * short code, URL and postcode/city/tree are recorded here against the QR
 * code it was merged into. Every lookup on the scan path consults these only
 * after its normal lookups have found nothing, so a code that resolves today
 * resolves exactly the same way — aliases can only rescue requests that would
 * otherwise have matched no QR code at all.
 */
class QRCodeTracker_Aliases {

    // Log sources for visits that arrived through a merged-away code. The
    // social one keeps the "social_" prefix that reports use to tell social
    // shares from scans (see QRCodeTracker::get_scan_only_log_condition()).
    const SCAN_SOURCE        = 'merged_alias';
    const SOCIAL_SCAN_SOURCE = 'social_merged_alias';

    // Autoloaded flag set when the first alias is created. Until then every
    // lookup below returns immediately, so the scan path — which runs on every
    // front-end request — makes no extra queries on sites that never merge.
    const OPTION_HAS_ALIASES = 'qr_tracker_has_aliases';

    public static function any_exist() {
        return (bool) get_option(self::OPTION_HAS_ALIASES, 0);
    }

    /**
     * Record that aliases exist. Called inside the merge transaction, so it
     * commits or rolls back together with the aliases themselves.
     */
    public static function mark_exist() {
        if (!self::any_exist()) {
            update_option(self::OPTION_HAS_ALIASES, 1, true);
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'qr_tracker_aliases';
    }

    private static function main_table() {
        global $wpdb;
        return $wpdb->prefix . 'qr_tracker';
    }

    /**
     * Whether a short code is in use by a live QR code or retired by a merge.
     *
     * A retired code is still printed on physical QR codes, so issuing it to a
     * new tree would silently send those scans to a stranger's tree.
     */
    public static function is_short_code_taken($short_code) {
        global $wpdb;

        $in_main = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::main_table() . " WHERE short_code = %s LIMIT 1",
            $short_code
        ));
        if ($in_main) {
            return true;
        }

        return self::find_by_short_code($short_code) !== null;
    }

    /**
     * The alias recorded for a retired short code.
     *
     * @return object|null Alias row.
     */
    public static function find_by_short_code($short_code) {
        $short_code = (string) $short_code;
        if ($short_code === '' || !self::any_exist()) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE short_code = %s ORDER BY id DESC LIMIT 1",
            $short_code
        ));
    }

    /**
     * The alias recorded for a retired postcode/city/tree.
     *
     * @return object|null Alias row.
     */
    public static function find_by_location($postcode, $city, $tree) {
        if ((string) $postcode === '' || (string) $city === '' || (string) $tree === '' || !self::any_exist()) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE postcode = %s AND city = %s AND tree = %s ORDER BY id DESC LIMIT 1",
            $postcode, $city, $tree
        ));
    }

    /**
     * The alias recorded for any of a set of retired URL variants.
     *
     * @param string[] $urls URL variants, as tried by the exact-URL lookups.
     * @return object|null Alias row.
     */
    public static function find_by_urls(array $urls) {
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        if (empty($urls) || !self::any_exist()) {
            return null;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE url IN ($placeholders) ORDER BY id DESC LIMIT 1",
            $urls
        ));
    }

    /**
     * Load the live QR code an alias points at.
     *
     * Returns null when the target has since been deleted, which leaves the
     * request unresolved — the same outcome it would have had without aliases.
     *
     * @param object|null $alias Alias row.
     * @return object|null QR code row.
     */
    public static function load_target($alias) {
        if (!$alias) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::main_table() . " WHERE id = %d",
            (int) $alias->target_tracker_id
        ));
    }
}
