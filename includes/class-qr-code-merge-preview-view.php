<?php
/**
 * The dry run: exactly what a merge will do, and the confirmation to commit.
 */
class QRCodeTracker_Merge_Preview_View {

    // Columns shown for a kept code's before/after comparison, beyond the
    // pickable fields.
    const COUNT_COLUMNS = [
        'scan_count'         => 'Scans',
        'social_share_count' => 'Social shares',
        'last_scanned'       => 'Last scanned',
        'last_social_shared' => 'Last social share',
    ];

    private $format;

    public function __construct(QRCodeTracker_Merge_Format $format) {
        $this->format = $format;
    }

    /**
     * @param array $dry_run Planner output.
     * @param array $notices Extra notices, e.g. a rolled-back commit: [['level' => ..., 'message' => ...]].
     */
    public function render(array $dry_run, array $notices = []) {
        $f    = $this->format;
        $plan = $dry_run['plan'];

        echo $f->steps(QRCodeTracker_Merge_Page::STAGE_PREVIEW);
        foreach ($notices as $notice) {
            echo $f->notices([$notice['message']], $notice['level']);
        }
        echo $f->notices($dry_run['errors']);

        echo '<p class="qr-merge-intro"><strong>This is a dry run — nothing has changed yet.</strong> Review it, then confirm at the bottom.</p>';

        $this->render_warnings($dry_run['warnings']);

        if (!empty($dry_run['results'])) {
            echo '<h2>After the merge, these QR codes remain</h2>';
            foreach ($dry_run['results'] as $keep_id => $result) {
                $this->render_result($dry_run, $keep_id, $result);
            }

            echo '<h2>These QR codes are removed</h2>';
            $this->render_removed($dry_run);
            $this->render_side_effects($dry_run);
        }

        $this->render_actions($dry_run);
    }

    private function render_warnings(array $warnings) {
        if (empty($warnings)) {
            return;
        }

        $levels = ['danger' => 'error', 'warning' => 'warning', 'info' => 'info'];
        echo '<div class="qr-merge-warnings">';
        foreach ($warnings as $warning) {
            $level = isset($levels[$warning['level']]) ? $levels[$warning['level']] : 'info';
            echo '<div class="notice notice-' . esc_attr($level) . ' inline qr-merge-notice qr-merge-level-' . esc_attr($warning['level']) . '"><p>' . esc_html($warning['message']) . '</p></div>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Kept codes
    // ------------------------------------------------------------------

    private function render_result(array $dry_run, $keep_id, array $result) {
        $f      = $this->format;
        $before = $result['before'];
        $after  = $result['after'];

        echo '<div class="qr-merge-card">';
        echo '<h3>' . $f->describe($before) . '</h3>';
        echo '<p class="description">Printed code unchanged: <code>' . esc_html((string) $before->short_code) . '</code> · ' . esc_html((string) $before->url) . '</p>';

        echo '<table class="widefat qr-merge-diff-table"><thead><tr><th>Field</th><th>Now</th><th>After merge</th><th>Taken from</th></tr></thead><tbody>';
        foreach (QRCodeTracker_Merge_Planner::PICKABLE_FIELDS as $field => $label) {
            $source = $dry_run['rows'][$result['sources'][$field]];
            $this->render_diff_row($label, $f->value($field, $before->$field), $f->value($field, $after[$field]), $f->describe($source));
        }
        foreach (self::COUNT_COLUMNS as $column => $label) {
            $is_date = strpos($column, 'last_') === 0;
            $now     = $is_date ? $f->datetime($before->$column) : number_format((int) $before->$column);
            $later   = $is_date ? $f->datetime($after[$column]) : number_format((int) $after[$column]);
            $this->render_diff_row($label, $now, $later, $is_date ? 'Latest visit kept' : 'Own + received');
        }
        echo '</tbody></table></div>';
    }

    /**
     * One comparison row. All three values arrive already escaped.
     */
    private function render_diff_row($label, $now, $after, $source) {
        $changed = ($now !== $after);
        echo '<tr' . ($changed ? ' class="is-changed"' : '') . '>';
        echo '<th scope="row">' . esc_html($label) . ($changed ? ' <span class="qr-merge-badge">changed</span>' : '') . '</th>';
        echo '<td>' . $now . '</td><td>' . $after . '</td><td>' . $source . '</td></tr>';
    }

    // ------------------------------------------------------------------
    // Removed codes
    // ------------------------------------------------------------------

    private function render_removed(array $dry_run) {
        $f    = $this->format;
        $plan = $dry_run['plan'];

        echo '<table class="widefat qr-merge-removed-table"><thead><tr>';
        echo '<th>QR code</th><th>Printed code afterwards</th><th>Scans</th><th>Social shares</th>';
        echo '</tr></thead><tbody>';
        foreach ($plan['remove_ids'] as $remove_id) {
            $row   = $dry_run['rows'][$remove_id];
            $alias = $plan['alias'][$remove_id];

            echo '<tr>';
            echo '<td>' . $f->describe($row) . '<br><span class="description">Created ' . esc_html(substr((string) $row->created_at, 0, 10)) . '</span></td>';
            echo '<td>' . $this->alias_outcome($dry_run, $row, $alias) . '</td>';
            echo '<td>' . $this->visit_breakdown($dry_run, $remove_id, QRCodeTracker_Merge_Planner::TYPE_SCAN) . '</td>';
            echo '<td>' . $this->visit_breakdown($dry_run, $remove_id, QRCodeTracker_Merge_Planner::TYPE_SOCIAL) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function alias_outcome(array $dry_run, $row, $alias) {
        $code = '<code>' . esc_html((string) $row->short_code) . '</code> ';
        if ($alias === 0) {
            return $code . '<strong class="qr-merge-danger">STOPS WORKING</strong>';
        }
        return $code . '<span class="qr-merge-ok">keeps working</span>, opens ' . $this->format->describe($dry_run['rows'][$alias]);
    }

    private function visit_breakdown(array $dry_run, $remove_id, $type) {
        $f       = $this->format;
        $plan    = $dry_run['plan'];
        $summary = $dry_run['log_summary'][$remove_id][$type];
        $lines   = [];

        foreach ($plan['alloc'][$type][$remove_id] as $keep_id => $amount) {
            if ($amount <= 0 && (int) $summary['move'][$keep_id]['count'] === 0) {
                continue;
            }
            $lines[] = number_format($amount) . ' → ' . esc_html($dry_run['rows'][$keep_id]->postcode . ' / ' . $dry_run['rows'][$keep_id]->tree)
                . '<br><span class="description">' . $f->range($summary['move'][$keep_id]) . '</span>';
        }

        $column    = ($type === QRCodeTracker_Merge_Planner::TYPE_SCAN) ? 'scan_count' : 'social_share_count';
        $discarded = (int) $dry_run['rows'][$remove_id]->$column - array_sum($plan['alloc'][$type][$remove_id]);
        if ($discarded > 0 || (int) $summary['delete']['count'] > 0) {
            $lines[] = '<strong class="qr-merge-danger">' . number_format($discarded) . ' discarded</strong>'
                . '<br><span class="description">' . $f->range($summary['delete']) . ' deleted</span>';
        }

        return empty($lines) ? '<em class="qr-merge-empty">none</em>' : implode('<hr class="qr-merge-hr">', $lines);
    }

    private function render_side_effects(array $dry_run) {
        $requests = $dry_run['access_requests'];
        $aliases  = $dry_run['existing_aliases'];
        $items    = [];

        if (!empty($requests['repoint'])) {
            $items[] = count($requests['repoint']) . ' team access request(s) move to the kept QR code.';
        }
        if (!empty($requests['delete'])) {
            $items[] = count($requests['delete']) . ' duplicate access request(s) are removed; for each user the strongest status (approved, then pending, then denied) is kept.';
        }
        if (!empty($aliases['repoint'])) {
            $items[] = count($aliases['repoint']) . ' code(s) kept working by an earlier merge currently open a code being removed; they will open the kept code instead.';
        }
        if (!empty($dry_run['plan']['rewrite_logs'])) {
            $items[] = 'Moved visits are relabelled with the kept code\'s postcode, city and tree.';
        } else {
            $items[] = 'Moved visits keep their original postcode, city and tree, so removed trees still appear as their own lines in reports.';
        }
        $items[] = 'A full snapshot of every removed row, deleted visit and changed record is stored with the merge.';

        echo '<h2>Also</h2><ul class="qr-merge-list">';
        foreach ($items as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul>';
    }

    // ------------------------------------------------------------------
    // Confirm / back
    // ------------------------------------------------------------------

    private function render_actions(array $dry_run) {
        $f     = $this->format;
        $plan  = $dry_run['plan'];
        $input = $f->hidden_inputs($f->plan_to_input($plan), QRCodeTracker_Merge_Page::INPUT_KEY);

        echo '<div class="qr-merge-confirm">';
        if (empty($dry_run['errors'])) {
            $count = count($plan['remove_ids']);
            echo $f->form_open(QRCodeTracker_Merge_Page::STAGE_COMMIT, 'qr-merge-commit-form');
            echo $input;
            echo '<input type="hidden" name="qr_merge_fingerprint" value="' . esc_attr($dry_run['fingerprint']) . '">';
            echo '<h2>Confirm</h2>';
            echo '<p><label for="qr-merge-confirm">Type <strong>' . (int) $count . '</strong> — the number of QR codes being removed — to confirm:</label><br>';
            echo '<input type="text" inputmode="numeric" autocomplete="off" id="qr-merge-confirm" name="qr_merge_confirm" class="small-text" data-expected="' . (int) $count . '"></p>';
            echo '<p><button type="submit" class="button button-primary qr-merge-commit" disabled>Merge now</button></p>';
            echo '</form>';
        } else {
            echo '<p>Fix the problems above before this merge can run.</p>';
        }

        echo $f->form_open(QRCodeTracker_Merge_Page::STAGE_CONFIGURE, 'qr-merge-back-form');
        echo $input;
        echo '<p><button type="submit" class="button">Back and change choices</button></p>';
        echo '</form></div>';
    }

    public function render_success(array $result) {
        echo '<div class="notice notice-success inline qr-merge-notice"><p>' . esc_html($result['message']) . '</p></div>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=qr-tracker')) . '">Back to QR codes</a> ';
        echo '<a class="button" href="' . esc_url(QRCodeTracker_Merge_Page::url()) . '">Start another merge</a></p>';
    }
}
