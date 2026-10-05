<?php
/**
 * Links orders placed before recipient links existed to the QR codes they
 * created.
 *
 * Orders never recorded which QR codes they created, so this matches each
 * tree line item to QR codes with the same postcode, city and team, created
 * close to the order's time. plan() only reads; apply() re-plans and writes
 * only what still matches what the admin reviewed. Nothing but the recipient
 * links table is ever written.
 */
class QRCodeTracker_Link_Backfill {

    const STATUS_MATCHED   = 'matched';
    const STATUS_AMBIGUOUS = 'ambiguous';
    const STATUS_PARTIAL   = 'partial';
    const STATUS_UNMATCHED = 'unmatched';

    // QR codes are created while the order is processed, so they sit close to
    // the order's time. The window is wide enough to absorb any difference
    // between the database's clock and WooCommerce's, which shifts every
    // candidate equally and so never changes which one is closest.
    const WINDOW_SECONDS = DAY_IN_SECONDS;

    private $tracker;

    public function __construct($tracker) {
        $this->tracker = $tracker;
    }

    /**
     * Proposed links for every order that created QR codes and has none yet.
     *
     * @return array List of ['order_id', 'email', 'name', 'date', 'status',
     *               'tracker_ids', 'items' => [...]], newest order first.
     */
    public function plan() {
        $claimed = QRCodeTracker_Recipient_Links::order_claimed_tracker_ids();
        $linked  = QRCodeTracker_Recipient_Links::linked_order_ids();
        $plan    = [];

        foreach ($this->orders_with_trees() as $order) {
            if (isset($linked[(int) $order->get_id()])) {
                continue;
            }
            $entry = $this->plan_order($order, $claimed);
            if ($entry === null) {
                continue;
            }
            foreach ($entry['tracker_ids'] as $tracker_id) {
                $claimed[$tracker_id] = true;
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    /**
     * Write the proposed links for the chosen orders, but only where the fresh
     * proposal still equals what was shown.
     *
     * @param array $approved [order_id => "comma,separated,tracker,ids" as shown]
     * @return array ['linked' => int orders, 'links' => int, 'skipped' => int orders]
     */
    public function apply(array $approved) {
        $result = ['linked' => 0, 'links' => 0, 'skipped' => 0];

        foreach ($this->plan() as $entry) {
            $order_id = (int) $entry['order_id'];
            if (!isset($approved[$order_id])) {
                continue;
            }
            if ((string) $approved[$order_id] !== implode(',', $entry['tracker_ids']) || empty($entry['tracker_ids'])) {
                $result['skipped']++;
                continue;
            }

            $result['links'] += QRCodeTracker_Recipient_Links::link_automatically(
                $entry['email'], $entry['name'], $entry['tracker_ids'], $order_id, QRCodeTracker_Recipient_Links::SOURCE_BACKFILL
            );
            $result['linked']++;
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------

    private function orders_with_trees() {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        return wc_get_orders([
            'limit'      => -1,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => [['key' => '_qr_tracker_rows_created', 'value' => '1']],
        ]);
    }

    private function plan_order($order, array $claimed) {
        $order_time = $this->order_timestamp($order);
        $items      = [];
        $ids        = [];

        foreach ($order->get_items('line_item') as $item) {
            $wanted = $this->tracker->describe_order_tree_item($item, $order);
            if ($wanted === null) {
                continue;
            }
            $match   = $this->match_item($wanted, $order_time, $claimed + array_fill_keys($ids, true));
            $ids     = array_merge($ids, $match['tracker_ids']);
            $items[] = $wanted + $match;
        }

        if (empty($items)) {
            return null;
        }

        return [
            'order_id'    => (int) $order->get_id(),
            'edit_url'    => $order->get_edit_order_url(),
            'email'       => QRCodeTracker_Recipient_Links::normalize_email($order->get_billing_email()),
            'name'        => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'date'        => gmdate('Y-m-d H:i', $order_time),
            'status'      => $this->overall_status($items),
            'tracker_ids' => $ids,
            'items'       => $items,
        ];
    }

    /**
     * The QR codes created for one tree line item: same postcode, city and
     * team, not yet linked to another order, closest to the order's time.
     */
    private function match_item(array $wanted, $order_time, array $claimed) {
        $candidates = [];
        foreach ($this->candidates($wanted) as $row) {
            $distance = abs(strtotime($row->created_at . ' UTC') - $order_time);
            if (!isset($claimed[(int) $row->id]) && $distance <= self::WINDOW_SECONDS) {
                $candidates[] = ['id' => (int) $row->id, 'label' => $row->label ?: $row->tree, 'distance' => $distance];
            }
        }

        usort($candidates, function ($a, $b) { return $a['distance'] <=> $b['distance']; });
        $chosen = array_slice($candidates, 0, $wanted['quantity']);

        return [
            'tracker_ids' => array_column($chosen, 'id'),
            'labels'      => array_column($chosen, 'label'),
            'candidates'  => count($candidates),
            'status'      => $this->item_status(count($candidates), $wanted['quantity']),
            'near_misses' => count($chosen) < $wanted['quantity'] ? $this->near_misses($wanted, $claimed) : [],
        ];
    }

    private function candidates(array $wanted) {
        global $wpdb;

        // Orders from before "Purchasing as" existed carry no team, and their
        // QR codes have none (or were later moved to Default Team), so for
        // those the match rests on postcode, city and time alone.
        if ($wanted['team_name'] === '') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT id, label, tree, created_at FROM {$wpdb->prefix}qr_tracker WHERE UPPER(postcode) = %s AND LOWER(city) = %s",
                $wanted['postcode'], $wanted['city']
            ));
        }

        // Team names are compared with backslashes removed on both sides: some
        // were saved with escaped apostrophes ("Ethan\'s Team") while an order
        // may hold the clean name, and the two must still match.
        $wanted_team = self::comparable_team_name($wanted['team_name']);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.label, q.tree, q.created_at, t.name AS team_name
             FROM {$wpdb->prefix}qr_tracker q
             LEFT JOIN {$wpdb->prefix}qr_tracker_teams t ON t.id = q.team_id
             WHERE UPPER(q.postcode) = %s AND LOWER(q.city) = %s",
            $wanted['postcode'], $wanted['city']
        ));

        return array_values(array_filter((array) $rows, function ($row) use ($wanted_team) {
            return self::comparable_team_name($row->team_name) === $wanted_team;
        }));
    }

    private static function comparable_team_name($name) {
        return strtolower(trim(QRCodeTracker_Recipients::unescape($name)));
    }

    /**
     * Unclaimed QR codes with the same postcode and city, whatever their team
     * or age, so an admin can see why a line found no match.
     */
    private function near_misses(array $wanted, array $claimed) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.label, q.tree, q.created_at, t.name AS team_name
             FROM {$wpdb->prefix}qr_tracker q
             LEFT JOIN {$wpdb->prefix}qr_tracker_teams t ON t.id = q.team_id
             WHERE UPPER(q.postcode) = %s AND LOWER(q.city) = %s
             ORDER BY q.id ASC LIMIT 5",
            $wanted['postcode'], $wanted['city']
        ));

        $misses = [];
        foreach ((array) $rows as $row) {
            if (!isset($claimed[(int) $row->id])) {
                $misses[] = sprintf('%s (team %s, created %s)', $row->label ?: $row->tree, QRCodeTracker_Recipients::unescape($row->team_name ?: 'none'), $row->created_at);
            }
        }
        return $misses;
    }

    private function item_status($candidates, $quantity) {
        if ($candidates === 0) {
            return self::STATUS_UNMATCHED;
        }
        if ($candidates < $quantity) {
            return self::STATUS_PARTIAL;
        }
        return $candidates === $quantity ? self::STATUS_MATCHED : self::STATUS_AMBIGUOUS;
    }

    /**
     * The weakest item decides the order's status.
     */
    private function overall_status(array $items) {
        $rank = [self::STATUS_UNMATCHED => 0, self::STATUS_PARTIAL => 1, self::STATUS_AMBIGUOUS => 2, self::STATUS_MATCHED => 3];
        $worst = self::STATUS_MATCHED;
        foreach ($items as $item) {
            if ($rank[$item['status']] < $rank[$worst]) {
                $worst = $item['status'];
            }
        }
        return $worst;
    }

    private function order_timestamp($order) {
        $date = $order->get_date_paid() ?: $order->get_date_created();
        return $date ? $date->getTimestamp() : time();
    }
}
