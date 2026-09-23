<?php
/**
 * Builds and validates a QR code merge plan, producing the dry run.
 *
 * Every selected QR code is either kept or removed. Kept codes keep their own
 * identity (short code, URL, postcode/city/tree), because that identity is
 * what is printed on the physical code; everything else on a kept code can be
 * taken from any selected code. Each removed code's visits are allocated
 * across the kept codes, and anything left unallocated is discarded.
 *
 * The planner only reads. It is run once for the dry run and again by the
 * executor at commit time, so what is committed is always recomputed from the
 * database rather than trusted from the browser.
 */
class QRCodeTracker_Merge_Planner {

    const ROLE_KEEP   = 'keep';
    const ROLE_REMOVE = 'remove';

    const TYPE_SCAN   = 'scan';
    const TYPE_SOCIAL = 'social';

    // Fields on a kept QR code that can be taken from any selected QR code.
    const PICKABLE_FIELDS = [
        'label'              => 'Label',
        'reporting_id'       => 'Reporting ID',
        'team_id'            => 'Team',
        'message_1'          => 'Message 1',
        'message_2'          => 'Message 2',
        'church_org_website' => 'Church / Organisation Website',
        'shop_link'          => 'Shop Link',
        'shop_logo'          => 'Shop Logo',
        'show_popup'         => 'Show Popup',
        'show_shop_link'     => 'Show Shop Link',
        'created_at'         => 'Created',
    ];

    // A removed code created this recently is treated as likely purchased and
    // printed for the current season, and warned about more loudly.
    const RECENT_DAYS = 365;

    const MIN_SELECTED = 2;

    // Access request statuses, strongest first, for resolving collisions.
    const ACCESS_STATUS_RANK = ['approved' => 3, 'pending' => 2, 'denied' => 1];

    private $teams;
    private $main_table;
    private $log_table;
    private $access_requests_table;

    // Notes from normalising the current plan (e.g. visits that arrived after
    // the allocation was set). Kept out of the plan itself so the plan stays
    // identical when rebuilt at commit time.
    private $notes = [];

    public function __construct($teams) {
        global $wpdb;
        $this->teams                 = $teams;
        $this->main_table            = $wpdb->prefix . 'qr_tracker';
        $this->log_table             = $wpdb->prefix . 'qr_tracker_logs';
        $this->access_requests_table = $wpdb->prefix . 'qr_tracker_access_requests';
    }

    /**
     * Build the dry run for a submitted plan.
     *
     * @param array $input Raw plan input (the qr_merge form array).
     * @return array Dry run: plan, rows, results, removals, side effects,
     *               warnings, errors and fingerprint. Only commit when errors
     *               is empty.
     */
    public function build(array $input) {
        $this->notes = [];
        $ids  = $this->parse_ids($input);
        $rows = $this->load_rows($ids);

        $errors = $this->validate_selection($ids, $rows);
        if (!empty($errors)) {
            return $this->failed($errors);
        }

        $plan   = $this->normalize_plan($input, $rows);
        $errors = array_merge($this->check_permissions($rows, $plan), $this->validate_plan($plan, $rows));
        if (!empty($errors)) {
            return $this->failed($errors, $plan, $rows);
        }

        $timelines  = $this->load_log_timelines($plan['remove_ids']);
        $assignment = $this->assign_logs($plan, $rows, $timelines);
        $results    = $this->compute_results($plan, $rows, $assignment, $timelines);
        $access     = $this->plan_access_requests($plan);
        $aliases    = $this->plan_existing_aliases($plan);

        $errors = $this->check_transaction_support();

        return [
            'plan'            => $plan,
            'rows'            => $rows,
            'results'         => $results,
            'assignment'      => $assignment,
            'log_summary'     => $this->summarize_logs($assignment, $timelines),
            'access_requests' => $access,
            'existing_aliases'=> $aliases,
            'warnings'        => array_merge($this->notes, $this->collect_warnings($plan, $rows, $assignment, $timelines)),
            'errors'          => $errors,
            'fingerprint'     => $this->fingerprint($plan, $rows, $timelines, $access, $aliases),
        ];
    }

    private function failed(array $errors, array $plan = [], array $rows = []) {
        return ['plan' => $plan, 'rows' => $rows, 'errors' => $errors, 'warnings' => []];
    }

    // ------------------------------------------------------------------
    // Selection
    // ------------------------------------------------------------------

    private function parse_ids(array $input) {
        $raw = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $raw))));
        sort($ids);
        return $ids;
    }

    private function load_rows(array $ids) {
        if (empty($ids)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->main_table} WHERE id IN ($placeholders) ORDER BY id ASC",
            $ids
        ));

        $rows = [];
        foreach ((array) $results as $row) {
            $rows[(int) $row->id] = $row;
        }
        return $rows;
    }

    private function validate_selection(array $ids, array $rows) {
        if (count($ids) < self::MIN_SELECTED) {
            return ['Select at least ' . self::MIN_SELECTED . ' QR codes to merge.'];
        }

        $missing = array_diff($ids, array_keys($rows));
        if (!empty($missing)) {
            return ['These QR codes no longer exist: ID ' . implode(', ', $missing) . '. Start the merge again.'];
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Plan normalisation — fills every unspecified choice with its safe default
    // ------------------------------------------------------------------

    private function normalize_plan(array $input, array $rows) {
        $roles      = $this->normalize_roles($input, $rows);
        $keep_ids   = array_keys(array_filter($roles, function ($role) { return $role === self::ROLE_KEEP; }));
        $remove_ids = array_keys(array_filter($roles, function ($role) { return $role === self::ROLE_REMOVE; }));

        $plan = [
            'ids'          => array_keys($rows),
            'roles'        => $roles,
            'keep_ids'     => $keep_ids,
            'remove_ids'   => $remove_ids,
            'fields'       => $this->normalize_fields($input, $keep_ids),
            'rewrite_logs' => !isset($input['configured']) || !empty($input['rewrite_logs']),
        ];

        $plan['alloc'] = [
            self::TYPE_SCAN   => $this->normalize_allocation($input, self::TYPE_SCAN, $rows, $keep_ids, $remove_ids),
            self::TYPE_SOCIAL => $this->normalize_allocation($input, self::TYPE_SOCIAL, $rows, $keep_ids, $remove_ids),
        ];
        $plan['alias'] = $this->normalize_aliases($input, $keep_ids, $remove_ids, $plan['alloc']);

        // The counts the allocation above was made against. Carried with the
        // plan so that visits arriving later can be recognised as new.
        $plan['seen'] = [];
        foreach ([self::TYPE_SCAN, self::TYPE_SOCIAL] as $type) {
            foreach ($remove_ids as $remove_id) {
                $plan['seen'][$type][$remove_id] = $this->count_of($rows[$remove_id], $type);
            }
        }

        return $plan;
    }

    private function count_of($row, $type) {
        return (int) ($type === self::TYPE_SCAN ? $row->scan_count : $row->social_share_count);
    }

    /**
     * Roles default to keeping the QR code with the most scans — the most
     * established printed code — and removing the rest.
     */
    private function normalize_roles(array $input, array $rows) {
        $submitted = isset($input['role']) && is_array($input['role']) ? $input['role'] : [];

        $default_keep = null;
        foreach ($rows as $id => $row) {
            if ($default_keep === null || (int) $row->scan_count > (int) $rows[$default_keep]->scan_count) {
                $default_keep = $id;
            }
        }

        $roles = [];
        foreach ($rows as $id => $row) {
            $role = isset($submitted[$id]) ? (string) $submitted[$id] : '';
            if ($role !== self::ROLE_KEEP && $role !== self::ROLE_REMOVE) {
                $role = ($id === $default_keep) ? self::ROLE_KEEP : self::ROLE_REMOVE;
            }
            $roles[$id] = $role;
        }
        return $roles;
    }

    /**
     * Each kept code's fields default to its own values.
     */
    private function normalize_fields(array $input, array $keep_ids) {
        $submitted = isset($input['field']) && is_array($input['field']) ? $input['field'] : [];

        $fields = [];
        foreach ($keep_ids as $keep_id) {
            foreach (array_keys(self::PICKABLE_FIELDS) as $field) {
                $source = isset($submitted[$keep_id][$field]) ? absint($submitted[$keep_id][$field]) : 0;
                $fields[$keep_id][$field] = $source > 0 ? $source : $keep_id;
            }
        }
        return $fields;
    }

    /**
     * Allocation of one visit type. A removed code with no submitted
     * allocation sends its whole count to the first kept code, so the default
     * never discards anything — only an amount the user actually lowered can.
     */
    private function normalize_allocation(array $input, $type, array $rows, array $keep_ids, array $remove_ids) {
        $key        = ($type === self::TYPE_SCAN) ? 'alloc_scan' : 'alloc_social';
        $submitted  = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : [];
        $default_to = reset($keep_ids);

        $alloc = [];
        foreach ($remove_ids as $remove_id) {
            $count      = $this->count_of($rows[$remove_id], $type);
            $configured = isset($submitted[$remove_id]) && is_array($submitted[$remove_id]);
            foreach ($keep_ids as $keep_id) {
                if (!$configured) {
                    $amount = ($keep_id === $default_to) ? $count : 0;
                } else {
                    $amount = isset($submitted[$remove_id][$keep_id]) ? (int) $submitted[$remove_id][$keep_id] : 0;
                }
                $alloc[$remove_id][$keep_id] = $amount;
            }

            if ($configured) {
                $alloc[$remove_id] = $this->absorb_new_visits($input, $type, $rows[$remove_id], $alloc[$remove_id]);
            }
        }
        return $alloc;
    }

    /**
     * Visits that arrived after the allocation was set were never seen by the
     * user, so they must not fall into "discarded" by default. They go to the
     * kept code already receiving the most of this code's visits.
     */
    private function absorb_new_visits(array $input, $type, $row, array $amounts) {
        $seen    = isset($input['seen'][$type][(int) $row->id]) ? (int) $input['seen'][$type][(int) $row->id] : null;
        $arrived = ($seen === null) ? 0 : $this->count_of($row, $type) - $seen;
        if ($arrived <= 0) {
            return $amounts;
        }

        $receiver = array_search(max($amounts), $amounts, true);
        $amounts[$receiver] += $arrived;

        $this->notes[] = [
            'level'   => 'info',
            'message' => sprintf(
                '%d new %s arrived on QR code %s after you set the allocation. %s added to the visits going to QR code ID %d, not discarded.',
                $arrived, $this->noun($arrived, $type), $this->describe($row),
                $arrived === 1 ? 'It was' : 'They were', $receiver
            ),
        ];
        return $amounts;
    }

    /**
     * Each removed code keeps working by default, pointing at the kept code
     * that receives most of its visits.
     */
    private function normalize_aliases(array $input, array $keep_ids, array $remove_ids, array $alloc) {
        $submitted = isset($input['alias']) && is_array($input['alias']) ? $input['alias'] : [];

        $aliases = [];
        foreach ($remove_ids as $remove_id) {
            $choice = array_key_exists($remove_id, $submitted) ? absint($submitted[$remove_id]) : null;

            // 0 is an explicit "stop working"; anything else must be a kept
            // code, and an unset or no-longer-valid choice falls back to the
            // safe default of keeping the code working.
            if ($choice === 0 || in_array($choice, $keep_ids, true)) {
                $aliases[$remove_id] = $choice;
                continue;
            }
            $aliases[$remove_id] = $this->largest_recipient($remove_id, $keep_ids, $alloc);
        }
        return $aliases;
    }

    private function largest_recipient($remove_id, array $keep_ids, array $alloc) {
        $best       = reset($keep_ids);
        $best_total = -1;
        foreach ($keep_ids as $keep_id) {
            $total = $alloc[self::TYPE_SCAN][$remove_id][$keep_id] + $alloc[self::TYPE_SOCIAL][$remove_id][$keep_id];
            if ($total > $best_total) {
                $best       = $keep_id;
                $best_total = $total;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private function check_permissions(array $rows, array $plan) {
        if (!QRCodeTracker_Permissions::can_edit_qr_codes() || !QRCodeTracker_Permissions::can_delete_qr_codes()) {
            return ['Merging needs both the Edit QR Codes and Delete QR Codes permissions.'];
        }

        $user_id = get_current_user_id();
        $errors  = [];
        foreach ($rows as $id => $row) {
            if (!empty($row->team_id) && !$this->teams->user_can_access_team($user_id, (int) $row->team_id)) {
                $errors[] = 'You do not have access to the team that owns QR code ' . $this->describe($row) . '.';
            }
        }

        if ($this->changes_any_team($plan, $rows) && !QRCodeTracker_Permissions::can_assign_qr_codes_to_teams()) {
            $errors[] = 'Changing a kept QR code\'s team needs the Assign QR Codes to Teams permission.';
        }

        return $errors;
    }

    private function changes_any_team(array $plan, array $rows) {
        foreach ($plan['keep_ids'] as $keep_id) {
            $source = $plan['fields'][$keep_id]['team_id'];
            if ((int) $rows[$source]->team_id !== (int) $rows[$keep_id]->team_id) {
                return true;
            }
        }
        return false;
    }

    private function validate_plan(array $plan, array $rows) {
        if (empty($plan['keep_ids'])) {
            return ['Mark at least one QR code to keep.'];
        }
        if (empty($plan['remove_ids'])) {
            return ['Mark at least one QR code to remove — otherwise there is nothing to merge.'];
        }

        $errors = [];
        foreach ($plan['fields'] as $keep_id => $fields) {
            foreach ($fields as $field => $source) {
                if (!isset($rows[$source])) {
                    $errors[] = 'The source chosen for ' . self::PICKABLE_FIELDS[$field] . ' is not one of the selected QR codes.';
                }
            }
        }

        foreach ($plan['remove_ids'] as $remove_id) {
            $errors = array_merge($errors, $this->validate_allocation($plan, $rows[$remove_id]));

            $alias = $plan['alias'][$remove_id];
            if ($alias !== 0 && !in_array($alias, $plan['keep_ids'], true)) {
                $errors[] = 'QR code ' . $this->describe($rows[$remove_id]) . ' can only keep working by pointing at a QR code being kept.';
            }
        }

        return $errors;
    }

    private function validate_allocation(array $plan, $row) {
        $errors  = [];
        $columns = [self::TYPE_SCAN => 'scan_count', self::TYPE_SOCIAL => 'social_share_count'];

        foreach ($columns as $type => $column) {
            $amounts = $plan['alloc'][$type][(int) $row->id];
            if (min($amounts) < 0) {
                $errors[] = 'Allocations cannot be negative (QR code ' . $this->describe($row) . ').';
            }
            if (array_sum($amounts) > (int) $row->$column) {
                $errors[] = sprintf(
                    'QR code %s has %s but %d are allocated.',
                    $this->describe($row), $this->count_label($row->$column, $type), array_sum($amounts)
                );
            }
        }
        return $errors;
    }

    /**
     * Refuse to merge on tables that cannot roll back. A half-applied merge
     * could leave a QR code deleted with its visits not yet moved.
     */
    private function check_transaction_support() {
        global $wpdb;

        $tables = [$this->main_table, $this->log_table, $this->access_requests_table, QRCodeTracker_Aliases::table(), $wpdb->prefix . 'qr_tracker_merges'];
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $engines = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $tables
        ));

        $errors = [];
        foreach ((array) $engines as $engine) {
            if (strcasecmp((string) $engine->ENGINE, 'InnoDB') !== 0) {
                $errors[] = sprintf(
                    'Table %s uses the %s storage engine, which cannot roll back a failed merge. Merging is disabled until it is converted to InnoDB.',
                    $engine->TABLE_NAME, $engine->ENGINE
                );
            }
        }
        return $errors;
    }

    // ------------------------------------------------------------------
    // Visit logs
    // ------------------------------------------------------------------

    /**
     * Every log row of each removed code, oldest first, split by type.
     *
     * @return array [remove_id => ['scan' => [log rows], 'social' => [log rows]]]
     */
    private function load_log_timelines(array $remove_ids) {
        $timelines = [];
        foreach ($remove_ids as $remove_id) {
            $timelines[$remove_id] = [self::TYPE_SCAN => [], self::TYPE_SOCIAL => []];
        }
        if (empty($remove_ids)) {
            return $timelines;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($remove_ids), '%d'));
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tracker_id, scanned_at, scan_source FROM {$this->log_table} WHERE tracker_id IN ($placeholders) ORDER BY scanned_at ASC, id ASC",
            $remove_ids
        ));

        $social_prefix = QRCodeTracker::get_social_scan_source_prefix();
        foreach ((array) $logs as $log) {
            $is_social = strpos((string) $log->scan_source, $social_prefix) === 0;
            $timelines[(int) $log->tracker_id][$is_social ? self::TYPE_SOCIAL : self::TYPE_SCAN][] = $log;
        }
        return $timelines;
    }

    /**
     * Decide where every log row of every removed code goes.
     *
     * Log rows are handed out oldest first, following the count allocation in
     * the order the kept codes are listed. If a removed code's whole count is
     * kept, every one of its log rows moves — surplus rows go with the last
     * receiving code — so rows are only ever deleted for visits the user chose
     * to discard.
     *
     * @return array [remove_id => [type => ['move' => [keep_id => log ids], 'delete' => log ids]]]
     */
    private function assign_logs(array $plan, array $rows, array $timelines) {
        $columns    = [self::TYPE_SCAN => 'scan_count', self::TYPE_SOCIAL => 'social_share_count'];
        $assignment = [];

        foreach ($plan['remove_ids'] as $remove_id) {
            foreach ($columns as $type => $column) {
                $amounts   = $plan['alloc'][$type][$remove_id];
                $keeps_all = array_sum($amounts) >= (int) $rows[$remove_id]->$column;
                $assignment[$remove_id][$type] = $this->split_logs($timelines[$remove_id][$type], $amounts, $keeps_all);
            }
        }
        return $assignment;
    }

    private function split_logs(array $logs, array $amounts, $keeps_all) {
        $ids    = array_map(function ($log) { return (int) $log->id; }, $logs);
        $move   = [];
        $offset = 0;
        $last_receiver = null;

        foreach ($amounts as $keep_id => $amount) {
            $move[$keep_id] = array_slice($ids, $offset, max(0, $amount));
            $offset += count($move[$keep_id]);
            if ($amount > 0) {
                $last_receiver = $keep_id;
            }
        }

        $leftover = array_slice($ids, $offset);
        if ($keeps_all && $last_receiver !== null) {
            $move[$last_receiver] = array_merge($move[$last_receiver], $leftover);
            $leftover = [];
        }

        return ['move' => $move, 'delete' => $leftover];
    }

    /**
     * How many log rows go where, and the date range they cover, so the dry
     * run can show exactly which visits are moved and which are discarded.
     *
     * @return array [remove_id => [type => ['move' => [keep_id => range], 'delete' => range, 'total' => int]]]
     *               where range is ['count' => int, 'from' => date|null, 'to' => date|null]
     */
    private function summarize_logs(array $assignment, array $timelines) {
        $summary = [];
        foreach ($assignment as $remove_id => $types) {
            foreach ($types as $type => $split) {
                $logs = $timelines[$remove_id][$type];
                $moves = [];
                foreach ($split['move'] as $keep_id => $ids) {
                    $moves[$keep_id] = $this->date_range($logs, $ids);
                }
                $summary[$remove_id][$type] = [
                    'move'   => $moves,
                    'delete' => $this->date_range($logs, $split['delete']),
                    'total'  => count($logs),
                ];
            }
        }
        return $summary;
    }

    private function date_range(array $logs, array $ids) {
        $dates = $this->log_dates($logs, $ids);
        return [
            'count' => count($ids),
            'from'  => empty($dates) ? null : min($dates),
            'to'    => empty($dates) ? null : max($dates),
        ];
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    /**
     * The final state of every kept code.
     *
     * @return array [keep_id => ['before' => row, 'after' => [column => value], 'sources' => [column => source id]]]
     */
    private function compute_results(array $plan, array $rows, array $assignment, array $timelines) {
        $results = [];
        foreach ($plan['keep_ids'] as $keep_id) {
            $keep  = $rows[$keep_id];
            $after = (array) $keep;

            foreach ($plan['fields'][$keep_id] as $field => $source) {
                $after[$field] = $rows[$source]->$field;
            }

            $after['scan_count']         = (int) $keep->scan_count + $this->received($plan, self::TYPE_SCAN, $keep_id);
            $after['social_share_count'] = (int) $keep->social_share_count + $this->received($plan, self::TYPE_SOCIAL, $keep_id);
            $after['last_scanned']       = $this->latest_date($keep->last_scanned, $plan, $rows, $assignment, $timelines, self::TYPE_SCAN, $keep_id);
            $after['last_social_shared'] = $this->latest_date($keep->last_social_shared, $plan, $rows, $assignment, $timelines, self::TYPE_SOCIAL, $keep_id);

            $results[$keep_id] = ['before' => $keep, 'after' => $after, 'sources' => $plan['fields'][$keep_id]];
        }
        return $results;
    }

    private function received(array $plan, $type, $keep_id) {
        $total = 0;
        foreach ($plan['remove_ids'] as $remove_id) {
            $total += $plan['alloc'][$type][$remove_id][$keep_id];
        }
        return $total;
    }

    /**
     * Latest visit date for a kept code: its own, or the newest log row it
     * receives. A removed code's own last-visit date only counts when all of
     * its visits of that type are kept, since otherwise that visit may be one
     * of the discarded ones.
     */
    private function latest_date($own, array $plan, array $rows, array $assignment, array $timelines, $type, $keep_id) {
        $column = ($type === self::TYPE_SCAN) ? 'last_scanned' : 'last_social_shared';
        $count  = ($type === self::TYPE_SCAN) ? 'scan_count' : 'social_share_count';
        $dates  = [(string) $own];

        foreach ($plan['remove_ids'] as $remove_id) {
            $moved = $assignment[$remove_id][$type]['move'][$keep_id];
            $dates = array_merge($dates, $this->log_dates($timelines[$remove_id][$type], $moved));

            $keeps_all = array_sum($plan['alloc'][$type][$remove_id]) >= (int) $rows[$remove_id]->$count;
            if ($keeps_all && $plan['alloc'][$type][$remove_id][$keep_id] > 0) {
                $dates[] = (string) $rows[$remove_id]->$column;
            }
        }

        $dates = array_filter($dates);
        return empty($dates) ? null : max($dates);
    }

    private function log_dates(array $logs, array $ids) {
        $wanted = array_flip($ids);
        $dates  = [];
        foreach ($logs as $log) {
            if (isset($wanted[(int) $log->id])) {
                $dates[] = (string) $log->scanned_at;
            }
        }
        return $dates;
    }

    // ------------------------------------------------------------------
    // Side effects on related tables
    // ------------------------------------------------------------------

    /**
     * Access requests on removed codes move to the code the removed one points
     * at (or the first kept code). The table allows one request per user per
     * QR code, so where two would collide the strongest status is kept.
     *
     * @return array ['repoint' => [request_id => keep_id], 'delete' => [request ids], 'rows' => [request rows]]
     */
    private function plan_access_requests(array $plan) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($plan['ids']), '%d'));
        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->access_requests_table} WHERE qr_id IN ($placeholders) ORDER BY id ASC",
            $plan['ids']
        ));

        $winners = []; // "keep_id:user_id" => request row
        $repoint = [];
        $delete  = [];
        foreach ((array) $requests as $request) {
            $destination = $this->request_destination($plan, (int) $request->qr_id);
            $slot        = $destination . ':' . (int) $request->user_id;

            if (!isset($winners[$slot])) {
                $winners[$slot] = $request;
                continue;
            }
            if ($this->status_rank($request) > $this->status_rank($winners[$slot])) {
                $delete[]       = (int) $winners[$slot]->id;
                $winners[$slot] = $request;
            } else {
                $delete[] = (int) $request->id;
            }
        }

        foreach ($winners as $slot => $request) {
            $destination = (int) strtok($slot, ':');
            if ((int) $request->qr_id !== $destination) {
                $repoint[(int) $request->id] = $destination;
            }
        }

        return ['repoint' => $repoint, 'delete' => $delete, 'rows' => (array) $requests];
    }

    private function request_destination(array $plan, $qr_id) {
        if (in_array($qr_id, $plan['keep_ids'], true)) {
            return $qr_id;
        }
        return $plan['alias'][$qr_id] ?: reset($plan['keep_ids']);
    }

    private function status_rank($request) {
        return isset(self::ACCESS_STATUS_RANK[$request->status]) ? self::ACCESS_STATUS_RANK[$request->status] : 0;
    }

    /**
     * Aliases from earlier merges that point at a code being removed. They are
     * repointed, never dropped — dropping them would silently kill codes that
     * an earlier merge promised to keep working.
     *
     * @return array ['repoint' => [alias_id => keep_id], 'rows' => [alias rows]]
     */
    private function plan_existing_aliases(array $plan) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($plan['remove_ids']), '%d'));
        $aliases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . QRCodeTracker_Aliases::table() . " WHERE target_tracker_id IN ($placeholders) ORDER BY id ASC",
            $plan['remove_ids']
        ));

        $repoint = [];
        foreach ((array) $aliases as $alias) {
            $removed = (int) $alias->target_tracker_id;
            $repoint[(int) $alias->id] = $plan['alias'][$removed] ?: reset($plan['keep_ids']);
        }
        return ['repoint' => $repoint, 'rows' => (array) $aliases];
    }

    // ------------------------------------------------------------------
    // Warnings and fingerprint
    // ------------------------------------------------------------------

    private function collect_warnings(array $plan, array $rows, array $assignment, array $timelines) {
        $warnings = [];
        foreach ($plan['remove_ids'] as $remove_id) {
            $row = $rows[$remove_id];

            if ($plan['alias'][$remove_id] === 0) {
                $warnings[] = [
                    'level'   => $this->is_recent($row) ? 'danger' : 'warning',
                    'message' => $this->stop_working_message($row),
                ];
            }

            foreach ([self::TYPE_SCAN, self::TYPE_SOCIAL] as $type) {
                $warnings = array_merge($warnings, $this->visit_warnings($plan, $row, $type, $assignment, $timelines));
            }
        }
        return $warnings;
    }

    private function stop_working_message($row) {
        $message = 'QR code ' . $this->describe($row) . ' will STOP WORKING. Anyone scanning a printed copy will land on a page with no tree.';
        if ($this->is_recent($row)) {
            $message .= ' It was created in the last ' . self::RECENT_DAYS . ' days, so it is likely a purchased code in circulation this season.';
        }
        return $message;
    }

    private function visit_warnings(array $plan, $row, $type, array $assignment, array $timelines) {
        $column    = ($type === self::TYPE_SCAN) ? 'scan_count' : 'social_share_count';
        $count     = (int) $row->$column;
        $discarded = $count - array_sum($plan['alloc'][$type][(int) $row->id]);
        $logs      = count($timelines[(int) $row->id][$type]);
        $warnings  = [];

        if ($discarded > 0) {
            $warnings[] = [
                'level'   => 'danger',
                'message' => sprintf(
                    '%d of %s on QR code %s will be permanently discarded (%s deleted).',
                    $discarded, $this->count_label($count, $type), $this->describe($row),
                    $this->log_rows_label(count($assignment[(int) $row->id][$type]['delete']))
                ),
            ];
        }
        if ($logs !== $count) {
            $warnings[] = [
                'level'   => 'info',
                'message' => sprintf(
                    'QR code %s shows %s but has %s. Counts follow your allocation; log rows move with them.',
                    $this->describe($row), $this->count_label($count, $type), $this->log_rows_label($logs)
                ),
            ];
        }
        return $warnings;
    }

    /**
     * A hash of the plan and every piece of data it depends on. The executor
     * recomputes it and refuses to commit if anything changed since the dry
     * run was shown — a scan arriving mid-review, say.
     */
    private function fingerprint(array $plan, array $rows, array $timelines, array $access, array $aliases) {
        $log_ids = [];
        foreach ($timelines as $remove_id => $types) {
            foreach ($types as $type => $logs) {
                $log_ids[$remove_id][$type] = array_map(function ($log) { return (int) $log->id; }, $logs);
            }
        }

        return hash('sha256', wp_json_encode([
            'plan'     => $plan,
            'rows'     => $rows,
            'logs'     => $log_ids,
            'requests' => array_map(function ($request) { return [(int) $request->id, (int) $request->qr_id, $request->status]; }, $access['rows']),
            'aliases'  => array_map(function ($alias) { return [(int) $alias->id, (int) $alias->target_tracker_id]; }, $aliases['rows']),
        ]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function is_recent($row) {
        if (empty($row->created_at)) {
            return false;
        }
        $created = strtotime($row->created_at . ' UTC');
        return $created !== false && $created >= time() - self::RECENT_DAYS * DAY_IN_SECONDS;
    }

    public function describe($row) {
        return sprintf('%s / %s / %s (ID %d)', $row->postcode, $row->city, $row->tree, (int) $row->id);
    }

    private function log_rows_label($count) {
        return number_format((int) $count) . ((int) $count === 1 ? ' log row' : ' log rows');
    }

    /**
     * "scan" or "scans", "social share" or "social shares", to suit a count.
     */
    public function noun($count, $type) {
        $singular = $type === self::TYPE_SCAN ? 'scan' : 'social share';
        return (int) $count === 1 ? $singular : $singular . 's';
    }

    /**
     * "1 scan", "3 scans", "1 social share", …
     */
    public function count_label($count, $type) {
        return number_format((int) $count) . ' ' . $this->noun($count, $type);
    }
}
