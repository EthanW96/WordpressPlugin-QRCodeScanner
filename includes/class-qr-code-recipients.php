<?php
/**
 * Who receives the update emails, and the stats each one is sent.
 *
 * A recipient is one email address. Their trees are every QR code linked to
 * that email (see QRCodeTracker_Recipient_Links), plus — when enabled — every
 * tree in a team they belong to. Users with site-wide QR Tracker rights are
 * left out of the team route: admins are added to every team they create and
 * would otherwise get updates for all of them.
 */
class QRCodeTracker_Recipients {

    // Per-tree breakdown slots in the email (T1…T3). Purchasers with more
    // trees still get their totals, MORETREES and TREELIST.
    const TREE_SLOTS = 3;

    // Mailchimp's limit for a text merge field.
    const TEXT_FIELD_MAX = 255;

    const OPTION_INCLUDE_TEAM = 'qr_tracker_updates_include_team_members';

    private $teams;
    private $trees = null; // [id => row], loaded once per request

    public function __construct($teams) {
        $this->teams = $teams;
    }

    public static function include_team_members() {
        return (bool) get_option(self::OPTION_INCLUDE_TEAM, 1);
    }

    /**
     * The merge fields this sends, as [TAG => [label, Mailchimp type]].
     * Number fields always hold a number; slot fields are text so an unused
     * slot can be cleared with an empty value.
     */
    public static function merge_field_definitions() {
        $fields = [
            'TREES'     => ['Trees owned', 'number'],
            'MYSCANS'   => ['Scans of their trees', 'number'],
            'MYSHARES'  => ['Social shares of their trees', 'number'],
            'MYCLICKS'  => ['Website button clicks on their trees', 'number'],
            'TEAMSCANS' => ['Scans across their team(s)', 'number'],
            'NETSCANS'  => ['Scans across the whole network', 'number'],
            'NETTREES'  => ['Trees in the whole network', 'number'],
            'MORETREES' => ['Trees beyond the per-tree breakdown', 'number'],
            'TREELIST'  => ['All their trees with scans', 'text'],
        ];
        for ($slot = 1; $slot <= self::TREE_SLOTS; $slot++) {
            $fields["T{$slot}NAME"]   = ["Tree {$slot} name", 'text'];
            $fields["T{$slot}SCANS"]  = ["Tree {$slot} scans", 'text'];
            $fields["T{$slot}CLICKS"] = ["Tree {$slot} website clicks", 'text'];
            $fields["T{$slot}URL"]    = ["Tree {$slot} page link", 'text'];
        }
        return $fields;
    }

    // ------------------------------------------------------------------
    // Recipients
    // ------------------------------------------------------------------

    /**
     * @return array [email => ['email', 'name', 'tree_ids' => int[], 'via' => string[]]]
     */
    public function get_recipients() {
        $trees      = $this->trees();
        $recipients = [];

        foreach (QRCodeTracker_Recipient_Links::live_resolved(array_fill_keys(array_keys($trees), true)) as $link) {
            $this->add($recipients, $link->email, (string) $link->name, (int) $link->resolved_tracker_id, $link->source);
        }

        if (self::include_team_members()) {
            $this->add_team_members($recipients, $trees);
        }

        ksort($recipients);
        return $recipients;
    }

    private function add_team_members(array &$recipients, array $trees) {
        $by_team = [];
        foreach ($trees as $tree) {
            if (!empty($tree->team_id)) {
                $by_team[(int) $tree->team_id][] = (int) $tree->id;
            }
        }

        foreach ($by_team as $team_id => $tree_ids) {
            foreach ((array) $this->teams->get_team_members($team_id) as $member) {
                if ($this->is_site_wide_admin((int) $member->ID)) {
                    continue;
                }
                foreach ($tree_ids as $tree_id) {
                    $this->add($recipients, $member->user_email, (string) $member->display_name, $tree_id, 'team');
                }
            }
        }
    }

    private function is_site_wide_admin($user_id) {
        return user_can($user_id, 'manage_options') || user_can($user_id, 'qr_tracker_manage_all_teams');
    }

    private function add(array &$recipients, $email, $name, $tree_id, $via) {
        $email = QRCodeTracker_Recipient_Links::normalize_email($email);
        if ($email === '' || !is_email($email)) {
            return;
        }
        if (!isset($recipients[$email])) {
            $recipients[$email] = ['email' => $email, 'name' => '', 'tree_ids' => [], 'via' => []];
        }
        if ($recipients[$email]['name'] === '' && $name !== '') {
            $recipients[$email]['name'] = $name;
        }
        $recipients[$email]['tree_ids'][$tree_id] = $tree_id;
        $recipients[$email]['via'][$via]          = $via;
    }

    // ------------------------------------------------------------------
    // Stats
    // ------------------------------------------------------------------

    /**
     * Merge field values for every recipient.
     *
     * @return array [email => ['recipient' => array, 'fields' => [TAG => value]]]
     */
    public function build_all() {
        $trees   = $this->trees();
        $clicks  = QRCodeTracker_Clicks::counts_for(array_keys($trees));
        $network = $this->network_totals($trees);
        $teams   = $this->team_scan_totals($trees);

        $built = [];
        foreach ($this->get_recipients() as $email => $recipient) {
            $built[$email] = [
                'recipient' => $recipient,
                'fields'    => $this->fields_for($recipient, $trees, $clicks, $network, $teams),
            ];
        }
        return $built;
    }

    private function fields_for(array $recipient, array $trees, array $clicks, array $network, array $teams) {
        $mine = [];
        foreach ($recipient['tree_ids'] as $tree_id) {
            $mine[] = $trees[$tree_id];
        }
        usort($mine, function ($a, $b) { return (int) $b->scan_count <=> (int) $a->scan_count; });

        $team_ids = array_unique(array_filter(array_map(function ($tree) { return (int) $tree->team_id; }, $mine)));

        $fields = [
            'TREES'     => count($mine),
            'MYSCANS'   => array_sum(array_map(function ($tree) { return (int) $tree->scan_count; }, $mine)),
            'MYSHARES'  => array_sum(array_map(function ($tree) { return (int) $tree->social_share_count; }, $mine)),
            'MYCLICKS'  => array_sum(array_map(function ($tree) use ($clicks) { return $clicks[(int) $tree->id] ?? 0; }, $mine)),
            'TEAMSCANS' => array_sum(array_map(function ($team_id) use ($teams) { return $teams[$team_id] ?? 0; }, $team_ids)),
            'NETSCANS'  => $network['scans'],
            'NETTREES'  => $network['trees'],
            'MORETREES' => max(0, count($mine) - self::TREE_SLOTS),
            'TREELIST'  => $this->tree_list($mine),
        ];

        $first_name = $this->first_name($recipient['name']);
        if ($first_name !== '') {
            // Only sent when known; the sync also drops it for contacts that already
            // have a first name in Mailchimp, so it only ever fills a blank.
            $fields['FNAME'] = $first_name;
        }

        return $fields + $this->slot_fields($mine, $clicks);
    }

    private function slot_fields(array $mine, array $clicks) {
        $fields = [];
        for ($slot = 1; $slot <= self::TREE_SLOTS; $slot++) {
            $tree = $mine[$slot - 1] ?? null;
            // Empty values clear a slot left over from an earlier sync.
            $fields["T{$slot}NAME"]   = $tree ? self::tree_name($tree) : '';
            $fields["T{$slot}SCANS"]  = $tree ? (string) (int) $tree->scan_count : '';
            $fields["T{$slot}CLICKS"] = $tree ? (string) ($clicks[(int) $tree->id] ?? 0) : '';
            $fields["T{$slot}URL"]    = $tree ? (string) $tree->url : '';
        }
        return $fields;
    }

    /**
     * "Riverside 43 · Home 12 · …", cut to fit a Mailchimp text field.
     */
    private function tree_list(array $mine) {
        $parts = array_map(function ($tree) { return self::tree_name($tree) . ' ' . (int) $tree->scan_count; }, $mine);
        $list  = implode(' · ', $parts);
        if (mb_strlen($list) <= self::TEXT_FIELD_MAX) {
            return $list;
        }
        return rtrim(mb_substr($list, 0, self::TEXT_FIELD_MAX - 1)) . '…';
    }

    public static function tree_name($tree) {
        $raw  = ($tree->label !== '' && $tree->label !== null) ? $tree->label : $tree->tree;
        $name = trim(wp_strip_all_tags(self::unescape($raw)));
        return mb_substr($name !== '' ? $name : 'Tree', 0, 100);
    }

    /**
     * Undo the apostrophe escaping some labels and team names were saved
     * with. Each re-save added another layer ("St Gabriel\\\'s"), so strip
     * until nothing changes. Display only: stored values are left as they are.
     */
    public static function unescape($value) {
        $value = (string) $value;
        do {
            $previous = $value;
            $value    = stripslashes($value);
        } while ($value !== $previous);
        return $value;
    }

    private function first_name($name) {
        $name = trim(self::unescape($name));
        // A display name that is really an email address is no greeting.
        if ($name === '' || strpos($name, '@') !== false) {
            return '';
        }
        return mb_substr(strtok($name, ' '), 0, 100);
    }

    private function network_totals(array $trees) {
        return [
            'scans' => array_sum(array_map(function ($tree) { return (int) $tree->scan_count; }, $trees)),
            'trees' => count($trees),
        ];
    }

    private function team_scan_totals(array $trees) {
        $totals = [];
        foreach ($trees as $tree) {
            if (!empty($tree->team_id)) {
                $totals[(int) $tree->team_id] = ($totals[(int) $tree->team_id] ?? 0) + (int) $tree->scan_count;
            }
        }
        return $totals;
    }

    /**
     * Every QR code, keyed by id. Loaded once: recipients and stats both need it.
     */
    public function trees() {
        if ($this->trees === null) {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT id, url, short_code, postcode, city, tree, label, team_id, scan_count, social_share_count FROM {$wpdb->prefix}qr_tracker"
            );
            $this->trees = [];
            foreach ((array) $rows as $row) {
                $this->trees[(int) $row->id] = $row;
            }
        }
        return $this->trees;
    }
}
