<?php
/**
 * Per-QR-code language: send a scan to the home page in that code's
 * language (e.g. Welsh) using WPML.
 *
 * The switch happens when a code is scanned, so codes already printed with
 * the default address work unchanged. It runs before the visit is recorded
 * (track_visit() is on 'wp' priority 0, this is priority -1), so the
 * redirected page records the scan once, with time-on-page and click
 * tracking working as normal.
 *
 * Everything here is inert without WPML, or when a code has no language.
 */
class QRCodeTracker_Language {

    // Added to the redirect target. If the translated page still is not in
    // the code's language (e.g. no translation exists), the visitor simply
    // stays there instead of being redirected again.
    const LOOP_FLAG = 'qr_lang';

    private $tracker;

    public function __construct($tracker) {
        $this->tracker = $tracker;
    }

    /**
     * Active WPML languages as [code => name], or [] without WPML.
     */
    public static function languages() {
        $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (!is_array($active)) {
            return [];
        }

        $languages = [];
        foreach ($active as $code => $language) {
            $name = $language['translated_name'] ?? ($language['native_name'] ?? $code);
            $languages[(string) $code] = (string) $name;
        }
        return $languages;
    }

    public static function is_available() {
        return !empty(self::languages());
    }

    /**
     * A submitted language code if WPML has it active, otherwise '' (default).
     */
    public static function sanitize($code) {
        $code = sanitize_key((string) $code);
        return ($code !== '' && isset(self::languages()[$code])) ? $code : '';
    }

    /**
     * Admin <select> for a QR code's language, or '' without WPML.
     */
    public static function field_html($current) {
        $languages = self::languages();
        if (empty($languages)) {
            return '';
        }

        $html = '<tr><th><label for="qr_language">Language:</label></th><td><select name="qr_language" id="qr_language">';
        $html .= '<option value="">Default (site language)</option>';
        foreach ($languages as $code => $name) {
            $html .= '<option value="' . esc_attr($code) . '"' . selected((string) $current, $code, false) . '>' . esc_html($name) . '</option>';
        }
        $html .= '</select><p class="description">When this QR code is scanned, open the home page in this language. Works for codes already printed.</p></td></tr>';
        return $html;
    }

    // ------------------------------------------------------------------
    // Scan-time redirect
    // ------------------------------------------------------------------

    /**
     * 'wp' priority -1: if the scanned code has a language and this page is
     * not in it, redirect to the home page in that language, keeping the
     * code so the visit is recorded there.
     */
    public function maybe_redirect() {
        if (is_admin() || wp_doing_ajax() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET[self::LOOP_FLAG])) {
            return;
        }

        $request = $this->requested_code();
        if ($request === null) {
            return;
        }

        $language = (string) ($request['row']->language ?? '');
        if ($language === '' || $language === (string) apply_filters('wpml_current_language', null)) {
            return;
        }

        $args = ['qr' => $request['code'], self::LOOP_FLAG => 1];
        if ($request['social']) {
            // Keep a social link counted as a social share after the redirect.
            $args['qr_source'] = 'social';
        }

        $target = add_query_arg($args, self::home_url_for($language));
        wp_safe_redirect($target, 302);
        exit;
    }

    /**
     * The home page's address in a language.
     *
     * Taken from WPML's language list, which holds each language's version
     * of the current page (the home page, for a scan) — the same addresses
     * its language switcher links to. wpml_permalink() is only a fallback:
     * for the home page it can return the address unchanged.
     */
    private static function home_url_for($language) {
        $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        $url    = is_array($active) ? (string) ($active[$language]['url'] ?? '') : '';
        if ($url === '') {
            $url = (string) apply_filters('wpml_permalink', home_url('/'), $language);
        }
        // Drop any QR parameters the page's own address carried; the caller
        // sets them fresh.
        return remove_query_arg(['qr', 'qr_source', self::LOOP_FLAG], $url);
    }

    /**
     * The code this request is for and its QR code row (following merges),
     * or null.
     *
     * @return array|null ['code' => string, 'row' => object, 'social' => bool]
     */
    private function requested_code() {
        $social = isset($_GET['qr_source']) && sanitize_text_field(wp_unslash($_GET['qr_source'])) === 'social';
        $code   = isset($_GET['qr']) ? sanitize_text_field(wp_unslash($_GET['qr'])) : '';

        if ($code === '' && function_exists('is_404') && is_404()) {
            // The social link: the short code as the whole path.
            $code   = (string) $this->tracker->get_path_short_code_from_request();
            $social = true;
        }
        if ($code === '') {
            return null;
        }

        $row = $this->find_row($code);
        return $row ? ['code' => $code, 'row' => $row, 'social' => $social] : null;
    }

    private function find_row($code) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, language FROM {$wpdb->prefix}qr_tracker WHERE short_code = %s LIMIT 1",
            $code
        ));
        return $row ?: QRCodeTracker_Aliases::load_target(QRCodeTracker_Aliases::find_by_short_code($code));
    }
}
