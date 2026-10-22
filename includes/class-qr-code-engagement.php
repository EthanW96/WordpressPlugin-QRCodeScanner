<?php
/**
 * Measures how long people keep a tree's page open after scanning it.
 *
 * When a visit is recorded, the tree page gets a small script that counts
 * only the time the page is actually visible and reports it with
 * navigator.sendBeacon when the visitor leaves or switches away. Each report
 * carries a token signed for that one visit, so it can only ever describe
 * the visit it came from. Reports are capped, accepted only for a few hours
 * after the visit, and only raise a visit's recorded time, never add to it.
 *
 * Time is stored per scan-log row; the tree is found through the log, so a
 * merge that moves a visit moves its engagement too.
 */
class QRCodeTracker_Engagement {

    const POST_FLAG = 'qr_engage';

    // Longest a single visit can count for, so a page left open all
    // afternoon does not distort the averages.
    const MAX_SECONDS = 1800;

    // Reports for a visit are accepted for this long after it was logged.
    const REPORT_WINDOW_SECONDS = 3 * HOUR_IN_SECONDS;

    // How often the page re-reports while it stays open.
    const HEARTBEAT_SECONDS = 15;

    private $log_id = 0;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'qr_tracker_engagement';
    }

    /**
     * Called by track_visit() once a visit is logged on this request.
     */
    public function set_visit($log_id) {
        $this->log_id = (int) $log_id;
    }

    private static function token($log_id) {
        return wp_hash('qr_engage|' . (int) $log_id);
    }

    // ------------------------------------------------------------------
    // Front end
    // ------------------------------------------------------------------

    /**
     * wp_footer: print the timer for a page view that logged a visit.
     */
    public function render_script() {
        if ($this->log_id <= 0 || is_admin()) {
            return;
        }

        $config = wp_json_encode([
            'url'       => home_url('/'),
            'flag'      => self::POST_FLAG,
            'log'       => $this->log_id,
            'token'     => self::token($this->log_id),
            'max'       => self::MAX_SECONDS,
            'heartbeat' => self::HEARTBEAT_SECONDS,
        ]);

        // Counts visible time only; reports on leaving and every heartbeat.
        echo '<script>(function(c){if(!navigator.sendBeacon){return;}'
            . 'var total=0,since=document.visibilityState==="visible"?Date.now():null,sent=-1;'
            . 'function seconds(){var s=total+(since?(Date.now()-since)/1000:0);return Math.min(Math.round(s),c.max);}'
            . 'function report(){var s=seconds();if(s<=sent){return;}sent=s;var d=new FormData();'
            . 'd.append(c.flag,"1");d.append("log",c.log);d.append("token",c.token);d.append("seconds",s);navigator.sendBeacon(c.url,d);}'
            . 'document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible"){since=Date.now();}'
            . 'else{if(since){total+=(Date.now()-since)/1000;since=null;}report();}});'
            . 'window.addEventListener("pagehide",report);setInterval(function(){if(since){report();}},c.heartbeat*1000);'
            . '})(' . $config . ');</script>';
    }

    // ------------------------------------------------------------------
    // Receiving reports
    // ------------------------------------------------------------------

    /**
     * init: accept a beacon report. Always answers 204 so nothing about the
     * check is revealed; invalid reports are simply not stored.
     */
    public function handle_report() {
        if (empty($_POST[self::POST_FLAG]) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $log_id  = isset($_POST['log']) ? absint($_POST['log']) : 0;
        $token   = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $seconds = isset($_POST['seconds']) ? min(absint($_POST['seconds']), self::MAX_SECONDS) : 0;

        if ($log_id > 0 && $seconds > 0 && hash_equals(self::token($log_id), $token) && $this->visit_is_recent($log_id)) {
            $this->store($log_id, $seconds);
        }

        status_header(204);
        exit;
    }

    private function visit_is_recent($log_id) {
        global $wpdb;
        $scanned_at = $wpdb->get_var($wpdb->prepare(
            "SELECT scanned_at FROM {$wpdb->prefix}qr_tracker_logs WHERE id = %d",
            $log_id
        ));
        if (!$scanned_at) {
            return false;
        }
        return strtotime($scanned_at . ' UTC') >= time() - self::REPORT_WINDOW_SECONDS;
    }

    /**
     * Keep the longest time reported for a visit: repeated reports from the
     * same page raise it, they never add up.
     */
    private function store($log_id, $seconds) {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (log_id, seconds, updated_at) VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE seconds = GREATEST(seconds, VALUES(seconds)), updated_at = VALUES(updated_at)",
            $log_id, $seconds, current_time('mysql', 1)
        ));
        if ($result === false) {
            error_log('[QR Tracker engagement] Could not store time for visit ' . $log_id . ': ' . $wpdb->last_error);
        }
    }

    // ------------------------------------------------------------------
    // Averages
    // ------------------------------------------------------------------

    /**
     * Total seconds and number of measured visits per QR code.
     *
     * @return array [tracker_id => ['seconds' => int, 'visits' => int]]
     */
    public static function totals_by_tracker() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT l.tracker_id, SUM(e.seconds) AS seconds, COUNT(*) AS visits
             FROM " . self::table() . " e
             JOIN {$wpdb->prefix}qr_tracker_logs l ON l.id = e.log_id
             GROUP BY l.tracker_id"
        );

        $totals = [];
        foreach ((array) $rows as $row) {
            $totals[(int) $row->tracker_id] = ['seconds' => (int) $row->seconds, 'visits' => (int) $row->visits];
        }
        return $totals;
    }

    /**
     * Average over a set of trees, weighting each measured visit equally.
     */
    public static function average_seconds(array $totals, array $tracker_ids) {
        $seconds = 0;
        $visits  = 0;
        foreach ($tracker_ids as $tracker_id) {
            $seconds += $totals[$tracker_id]['seconds'] ?? 0;
            $visits  += $totals[$tracker_id]['visits'] ?? 0;
        }
        return $visits > 0 ? (int) round($seconds / $visits) : 0;
    }

    /**
     * "1m 25s", "45s", "0s".
     */
    public static function format($seconds) {
        $seconds = max(0, (int) $seconds);
        $minutes = intdiv($seconds, 60);
        $rest    = $seconds % 60;
        return $minutes > 0 ? sprintf('%dm %02ds', $minutes, $rest) : sprintf('%ds', $rest);
    }
}
