<?php
/**
 * Commits a QR code merge that the user has reviewed as a dry run.
 *
 * Everything happens in one database transaction. The selected rows are
 * locked first and the plan is rebuilt from the locked data, then compared
 * with the fingerprint of the dry run the user confirmed: if anything changed
 * in between, nothing is written. Every write checks how many rows it
 * touched, and any surprise rolls the whole merge back.
 */
class QRCodeTracker_Merge_Executor {

    // Maximum ids per IN (...) clause.
    const CHUNK_SIZE = 500;

    private $planner;
    private $main_table;
    private $log_table;
    private $access_requests_table;
    private $merges_table;

    public function __construct(QRCodeTracker_Merge_Planner $planner) {
        global $wpdb;
        $this->planner               = $planner;
        $this->main_table            = $wpdb->prefix . 'qr_tracker';
        $this->log_table             = $wpdb->prefix . 'qr_tracker_logs';
        $this->access_requests_table = $wpdb->prefix . 'qr_tracker_access_requests';
        $this->merges_table          = $wpdb->prefix . 'qr_tracker_merges';
    }

    /**
     * @param array  $input                The plan input the dry run was built from.
     * @param string $expected_fingerprint Fingerprint shown with the dry run.
     * @param int    $confirm_count        Number of removed codes the user typed.
     * @return array ['ok' => bool, 'message' => string, 'merge_id' => int]
     */
    public function execute(array $input, $expected_fingerprint, $confirm_count) {
        global $wpdb;

        if ($wpdb->query('START TRANSACTION') === false) {
            return $this->failure('Could not start a database transaction, so nothing was changed.');
        }

        try {
            $this->lock_rows($input);
            $preview = $this->planner->build($input);
            $this->assert_committable($preview, $expected_fingerprint, $confirm_count);

            $merge_id = $this->record_merge($preview);
            $this->move_logs($preview);
            $this->delete_discarded_logs($preview);
            $this->assert_removed_have_no_logs($preview);
            $this->update_kept($preview);
            $this->apply_access_requests($preview);
            $this->repoint_existing_aliases($preview);
            $this->create_aliases($preview, $merge_id);
            $this->delete_removed($preview);

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('The final commit failed.');
            }
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            error_log('[QR Tracker merge] Rolled back: ' . $exception->getMessage() . ' | input: ' . wp_json_encode($input));
            return $this->failure($exception->getMessage() . ' The merge was rolled back — nothing was changed.');
        }

        return [
            'ok'       => true,
            'merge_id' => $merge_id,
            'message'  => $this->summary($preview, $merge_id),
        ];
    }

    private function failure($message) {
        return ['ok' => false, 'message' => $message, 'merge_id' => 0];
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * Lock the selected rows so a concurrent scan waits for the merge rather
     * than updating a row mid-merge.
     */
    private function lock_rows(array $input) {
        global $wpdb;
        $ids = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('absint', $input['ids'])) : [];
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->main_table} WHERE id IN ($placeholders) FOR UPDATE",
            array_values($ids)
        ));
        // get_col() returns an empty array on failure, so check the error;
        // wpdb clears last_error at the start of every query.
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not lock the selected QR codes: ' . $wpdb->last_error);
        }
    }

    private function assert_committable(array $preview, $expected_fingerprint, $confirm_count) {
        if (!empty($preview['errors'])) {
            throw new RuntimeException(implode(' ', $preview['errors']));
        }
        if (!is_string($expected_fingerprint) || !hash_equals($preview['fingerprint'], $expected_fingerprint)) {
            throw new RuntimeException('These QR codes changed after the dry run was shown (a new scan, or someone else editing them). Review the new dry run and confirm again.');
        }
        if ((int) $confirm_count !== count($preview['plan']['remove_ids'])) {
            throw new RuntimeException('The confirmation number did not match the number of QR codes being removed.');
        }
    }

    /**
     * Every log row of a removed code must have been moved or deleted by now.
     * Anything left would be orphaned when the row is deleted.
     */
    private function assert_removed_have_no_logs(array $preview) {
        global $wpdb;
        $ids          = $preview['plan']['remove_ids'];
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $remaining    = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->log_table} WHERE tracker_id IN ($placeholders)",
            $ids
        ));

        if ($remaining > 0) {
            throw new RuntimeException($remaining . ' visit log rows arrived on the removed QR codes during the merge.');
        }
    }

    /**
     * Run a write and require it to touch exactly the expected rows.
     */
    private function expect_rows($result, $expected, $what) {
        global $wpdb;
        if ($result === false) {
            throw new RuntimeException('Failed to ' . $what . ': ' . $wpdb->last_error);
        }
        if ($expected !== null && (int) $result !== (int) $expected) {
            throw new RuntimeException(sprintf('Expected to %s %d rows but %d matched.', $what, $expected, (int) $result));
        }
    }

    // ------------------------------------------------------------------
    // Audit record
    // ------------------------------------------------------------------

    /**
     * Store the merge with a snapshot of everything it removes or changes,
     * written first so it exists for every merge that commits.
     */
    private function record_merge(array $preview) {
        global $wpdb;

        $inserted = $wpdb->insert($this->merges_table, [
            'merged_by'   => get_current_user_id(),
            'merged_at'   => current_time('mysql', 1),
            'kept_ids'    => wp_json_encode($preview['plan']['keep_ids']),
            'removed_ids' => wp_json_encode($preview['plan']['remove_ids']),
            'plan'        => wp_json_encode([
                'plan'       => $preview['plan'],
                'assignment' => $preview['assignment'],
                'after'      => array_map(function ($result) { return $result['after']; }, $preview['results']),
            ]),
            'snapshot'    => wp_json_encode($this->build_snapshot($preview)),
        ]);

        if (!$inserted) {
            throw new RuntimeException('Could not write the merge audit record: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    private function build_snapshot(array $preview) {
        return [
            'rows'             => $preview['rows'],
            'deleted_logs'     => $this->fetch_logs($this->ids_to_delete($preview), '*'),
            'moved_logs'       => $this->fetch_logs($this->ids_to_move($preview), 'id, tracker_id, postcode, city, tree'),
            'access_requests'  => $preview['access_requests']['rows'],
            'existing_aliases' => $preview['existing_aliases']['rows'],
        ];
    }

    private function fetch_logs(array $ids, $columns) {
        global $wpdb;
        $logs = [];
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $logs = array_merge($logs, (array) $wpdb->get_results($wpdb->prepare(
                "SELECT {$columns} FROM {$this->log_table} WHERE id IN ($placeholders)",
                $chunk
            )));
        }
        return $logs;
    }

    private function ids_to_move(array $preview) {
        $ids = [];
        foreach ($preview['assignment'] as $types) {
            foreach ($types as $split) {
                foreach ($split['move'] as $log_ids) {
                    $ids = array_merge($ids, $log_ids);
                }
            }
        }
        return $ids;
    }

    private function ids_to_delete(array $preview) {
        $ids = [];
        foreach ($preview['assignment'] as $types) {
            foreach ($types as $split) {
                $ids = array_merge($ids, $split['delete']);
            }
        }
        return $ids;
    }

    // ------------------------------------------------------------------
    // Visit logs
    // ------------------------------------------------------------------

    private function move_logs(array $preview) {
        global $wpdb;

        foreach ($preview['assignment'] as $remove_id => $types) {
            foreach ($types as $split) {
                foreach ($split['move'] as $keep_id => $log_ids) {
                    $keep = $preview['rows'][$keep_id];
                    foreach (array_chunk($log_ids, self::CHUNK_SIZE) as $chunk) {
                        $this->expect_rows(
                            $this->move_log_chunk($chunk, (int) $remove_id, $keep, $preview['plan']['rewrite_logs']),
                            count($chunk),
                            'move visit logs'
                        );
                    }
                }
            }
        }
    }

    /**
     * Repoint a chunk of log rows at a kept code. When requested, the rows'
     * own postcode/city/tree are rewritten too: reports group on the log's
     * copy of those, so without it the removed tree still shows as its own
     * line in reports.
     */
    private function move_log_chunk(array $chunk, $remove_id, $keep, $rewrite) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

        if ($rewrite) {
            $sql    = "UPDATE {$this->log_table} SET tracker_id = %d, postcode = %s, city = %s, tree = %s WHERE tracker_id = %d AND id IN ($placeholders)";
            $params = array_merge([(int) $keep->id, $keep->postcode, $keep->city, $keep->tree, $remove_id], $chunk);
        } else {
            $sql    = "UPDATE {$this->log_table} SET tracker_id = %d WHERE tracker_id = %d AND id IN ($placeholders)";
            $params = array_merge([(int) $keep->id, $remove_id], $chunk);
        }

        return $wpdb->query($wpdb->prepare($sql, $params));
    }

    private function delete_discarded_logs(array $preview) {
        global $wpdb;

        foreach ($preview['assignment'] as $remove_id => $types) {
            foreach ($types as $split) {
                foreach (array_chunk($split['delete'], self::CHUNK_SIZE) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    $result = $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$this->log_table} WHERE tracker_id = %d AND id IN ($placeholders)",
                        array_merge([(int) $remove_id], $chunk)
                    ));
                    $this->expect_rows($result, count($chunk), 'delete discarded visit logs');
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // QR code rows
    // ------------------------------------------------------------------

    /**
     * Write each kept code's final values. Identity columns (short code, URL,
     * postcode/city/tree) are never written: a kept code stays the same
     * printed code.
     */
    private function update_kept(array $preview) {
        global $wpdb;

        $columns = array_merge(
            array_keys(QRCodeTracker_Merge_Planner::PICKABLE_FIELDS),
            ['scan_count', 'social_share_count', 'last_scanned', 'last_social_shared']
        );

        foreach ($preview['results'] as $keep_id => $result) {
            $values = array_intersect_key($result['after'], array_flip($columns));
            $updated = $wpdb->update($this->main_table, $values, ['id' => (int) $keep_id]);
            // 0 is fine: the kept row may already hold exactly these values.
            $this->expect_rows($updated, null, 'update kept QR code ' . (int) $keep_id);
        }
    }

    private function delete_removed(array $preview) {
        global $wpdb;
        foreach ($preview['plan']['remove_ids'] as $remove_id) {
            $deleted = $wpdb->delete($this->main_table, ['id' => (int) $remove_id]);
            $this->expect_rows($deleted, 1, 'delete removed QR code ' . (int) $remove_id);
        }
    }

    // ------------------------------------------------------------------
    // Related tables
    // ------------------------------------------------------------------

    /**
     * Losing duplicates are deleted before winners move, so a winner never
     * collides with the request it is replacing on the unique (qr_id, user_id).
     */
    private function apply_access_requests(array $preview) {
        global $wpdb;

        foreach ($preview['access_requests']['delete'] as $request_id) {
            $deleted = $wpdb->delete($this->access_requests_table, ['id' => (int) $request_id]);
            $this->expect_rows($deleted, 1, 'remove a duplicate access request');
        }

        foreach ($preview['access_requests']['repoint'] as $request_id => $keep_id) {
            $updated = $wpdb->update($this->access_requests_table, ['qr_id' => (int) $keep_id], ['id' => (int) $request_id]);
            $this->expect_rows($updated, 1, 'move an access request');
        }
    }

    private function repoint_existing_aliases(array $preview) {
        global $wpdb;
        foreach ($preview['existing_aliases']['repoint'] as $alias_id => $keep_id) {
            $updated = $wpdb->update(QRCodeTracker_Aliases::table(), ['target_tracker_id' => (int) $keep_id], ['id' => (int) $alias_id]);
            $this->expect_rows($updated, 1, 'repoint an earlier merged code');
        }
    }

    private function create_aliases(array $preview, $merge_id) {
        global $wpdb;

        foreach ($preview['plan']['alias'] as $remove_id => $keep_id) {
            if ($keep_id === 0) {
                continue;
            }
            QRCodeTracker_Aliases::mark_exist();
            $removed  = $preview['rows'][$remove_id];
            $inserted = $wpdb->insert(QRCodeTracker_Aliases::table(), [
                'merge_id'            => (int) $merge_id,
                'original_tracker_id' => (int) $remove_id,
                'target_tracker_id'   => (int) $keep_id,
                'short_code'          => $removed->short_code,
                'url'                 => $removed->url,
                'postcode'            => $removed->postcode,
                'city'                => $removed->city,
                'tree'                => $removed->tree,
                'created_at'          => current_time('mysql', 1),
            ]);
            $this->expect_rows($inserted, 1, 'keep QR code ' . (int) $remove_id . ' working');
        }
    }

    private function summary(array $preview, $merge_id) {
        $plan    = $preview['plan'];
        $working = count(array_filter($plan['alias']));
        $stopped = count($plan['remove_ids']) - $working;

        $message = sprintf(
            'Merge #%d complete: %d QR code(s) kept, %d removed.',
            $merge_id, count($plan['keep_ids']), count($plan['remove_ids'])
        );
        if ($working > 0) {
            $message .= sprintf(' %d removed code(s) keep working and now open the QR code they were merged into.', $working);
        }
        if ($stopped > 0) {
            $message .= sprintf(' %d removed code(s) no longer work.', $stopped);
        }
        return $message . ' A full snapshot of the removed data is stored with the merge record.';
    }
}
