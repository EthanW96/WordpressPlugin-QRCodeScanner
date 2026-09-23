<?php
/**
 * The setup steps of the merge screen: select, keep-or-remove, configure.
 */
class QRCodeTracker_Merge_View {

    private $format;

    public function __construct(QRCodeTracker_Merge_Format $format) {
        $this->format = $format;
    }

    // ------------------------------------------------------------------
    // Step 1: select
    // ------------------------------------------------------------------

    public function render_select(array $codes, array $preselected, array $errors) {
        $f = $this->format;

        echo $f->steps(QRCodeTracker_Merge_Page::STAGE_SELECT);
        echo $f->notices($errors);
        echo '<p class="qr-merge-intro">Pick the QR codes involved. On the next steps you choose which ones are kept, where the removed ones\' visits go, and which details survive. Nothing changes until you confirm a dry run.</p>';

        if (empty($codes)) {
            echo $f->notices(['There are no QR codes you can merge.'], 'info');
            return;
        }

        echo $f->form_open(QRCodeTracker_Merge_Page::STAGE_ROLES, 'qr-merge-select-form');
        echo '<p><label for="qr-merge-filter" class="screen-reader-text">Filter QR codes</label>';
        echo '<input type="search" id="qr-merge-filter" class="regular-text" placeholder="Filter by postcode, city, tree, label, team or short code…"></p>';

        echo '<div class="qr-merge-scroll"><table class="widefat striped qr-merge-select-table"><thead><tr>';
        echo '<th class="check-column"></th><th>Postcode</th><th>City</th><th>Tree</th><th>Label</th><th>Team</th><th>Short code</th><th>Scans</th><th>Social</th><th>Created</th>';
        echo '</tr></thead><tbody>';
        foreach ($codes as $code) {
            $this->render_select_row($code, in_array((int) $code->id, $preselected, true));
        }
        echo '</tbody></table></div>';

        echo '<p class="qr-merge-actions"><span class="qr-merge-selected-count" aria-live="polite">0 selected</span> ';
        echo '<button type="submit" class="button button-primary qr-merge-continue" disabled>Continue</button></p>';
        echo '</form>';
    }

    private function render_select_row($code, $checked) {
        $f      = $this->format;
        $search = strtolower(implode(' ', [$code->postcode, $code->city, $code->tree, $code->label, $code->short_code, wp_strip_all_tags($f->value('team_id', $code->team_id))]));

        echo '<tr data-search="' . esc_attr($search) . '">';
        echo '<th class="check-column"><input type="checkbox" name="qr_merge[ids][]" value="' . (int) $code->id . '"' . checked($checked, true, false) . ' aria-label="Select ' . esc_attr($f->planner()->describe($code)) . '"></th>';
        echo '<td>' . esc_html($code->postcode) . '</td>';
        echo '<td>' . esc_html($code->city) . '</td>';
        echo '<td>' . esc_html($code->tree) . '</td>';
        echo '<td>' . $f->value('label', $code->label) . '</td>';
        echo '<td>' . $f->value('team_id', $code->team_id) . '</td>';
        echo '<td><code>' . esc_html((string) $code->short_code) . '</code></td>';
        echo '<td>' . number_format((int) $code->scan_count) . '</td>';
        echo '<td>' . number_format((int) $code->social_share_count) . '</td>';
        echo '<td>' . esc_html(substr((string) $code->created_at, 0, 10)) . '</td>';
        echo '</tr>';
    }

    // ------------------------------------------------------------------
    // Step 2: keep or remove
    // ------------------------------------------------------------------

    public function render_roles(array $dry_run) {
        $f    = $this->format;
        $plan = $dry_run['plan'];

        echo $f->steps(QRCodeTracker_Merge_Page::STAGE_ROLES);
        echo $f->notices($dry_run['errors']);
        echo '<p class="qr-merge-intro"><strong>Keep</strong>: the QR code survives with its own printed code, URL and postcode/city/tree. ';
        echo '<strong>Remove</strong>: the row is merged into a kept code. By default its printed code keeps working and opens the kept code instead.</p>';
        echo '<p class="description">One kept and several removed is a classic merge. One removed and several kept spreads its visits across them.</p>';

        echo $f->form_open(null);
        echo $f->hidden_inputs(['ids' => $plan['ids']], QRCodeTracker_Merge_Page::INPUT_KEY);

        echo '<table class="widefat striped qr-merge-roles-table"><thead><tr>';
        echo '<th>QR code</th><th>Team</th><th>Short code</th><th>Scans</th><th>Social</th><th>Created</th><th>Keep</th><th>Remove</th>';
        echo '</tr></thead><tbody>';
        foreach ($dry_run['rows'] as $id => $row) {
            $this->render_role_row($row, $plan['roles'][$id]);
        }
        echo '</tbody></table>';

        echo '<p class="qr-merge-actions">';
        echo $f->stage_button(QRCodeTracker_Merge_Page::STAGE_CONFIGURE, 'Continue', 'button button-primary');
        echo ' ' . $f->stage_button(QRCodeTracker_Merge_Page::STAGE_SELECT, 'Back');
        echo '</p></form>';
    }

    private function render_role_row($row, $role) {
        $f    = $this->format;
        $id   = (int) $row->id;
        $name = 'qr_merge[role][' . $id . ']';

        echo '<tr>';
        echo '<td>' . $f->describe($row) . $this->recent_badge($row) . '</td>';
        echo '<td>' . $f->value('team_id', $row->team_id) . '</td>';
        echo '<td><code>' . esc_html((string) $row->short_code) . '</code></td>';
        echo '<td>' . number_format((int) $row->scan_count) . '</td>';
        echo '<td>' . number_format((int) $row->social_share_count) . '</td>';
        echo '<td>' . esc_html(substr((string) $row->created_at, 0, 10)) . '</td>';
        foreach ([QRCodeTracker_Merge_Planner::ROLE_KEEP, QRCodeTracker_Merge_Planner::ROLE_REMOVE] as $option) {
            echo '<td><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($option) . '"' . checked($role, $option, false) . ' aria-label="' . esc_attr(ucfirst($option) . ' ' . $f->planner()->describe($row)) . '"></td>';
        }
        echo '</tr>';
    }

    private function recent_badge($row) {
        if (!$this->format->planner()->is_recent($row)) {
            return '';
        }
        return ' <span class="qr-merge-badge qr-merge-badge-recent" title="Created in the last ' . (int) QRCodeTracker_Merge_Planner::RECENT_DAYS . ' days — likely purchased and printed this season">This season</span>';
    }

    // ------------------------------------------------------------------
    // Step 3: configure
    // ------------------------------------------------------------------

    public function render_configure(array $dry_run) {
        $f    = $this->format;
        $plan = $dry_run['plan'];

        echo $f->steps(QRCodeTracker_Merge_Page::STAGE_CONFIGURE);
        echo $f->notices($dry_run['errors']);

        echo $f->form_open(null, 'qr-merge-configure-form');
        echo $f->hidden_inputs(['ids' => $plan['ids'], 'role' => $plan['roles'], 'configured' => 1, 'seen' => $plan['seen']], QRCodeTracker_Merge_Page::INPUT_KEY);

        echo '<h2>Visits and printed codes of the removed QR codes</h2>';
        foreach ($plan['remove_ids'] as $remove_id) {
            $this->render_removed_card($dry_run, $remove_id);
        }

        echo '<h2>Details of the kept QR codes</h2>';
        echo '<p class="description">A kept code always keeps its own short code, URL and postcode/city/tree — that is what is printed on it. Everything below can be taken from any selected code.</p>';
        foreach ($plan['keep_ids'] as $keep_id) {
            $this->render_field_grid($dry_run, $keep_id);
        }

        $this->render_rewrite_option($plan);

        echo '<p class="qr-merge-actions">';
        echo $f->stage_button(QRCodeTracker_Merge_Page::STAGE_PREVIEW, 'Show dry run', 'button button-primary');
        echo ' ' . $f->stage_button(QRCodeTracker_Merge_Page::STAGE_ROLES, 'Back to keep or remove');
        echo '</p></form>';
    }

    private function render_removed_card(array $dry_run, $remove_id) {
        $f    = $this->format;
        $plan = $dry_run['plan'];
        $row  = $dry_run['rows'][$remove_id];

        echo '<div class="qr-merge-card">';
        echo '<h3>Removing ' . $f->describe($row) . $this->recent_badge($row) . '</h3>';

        echo '<table class="widefat qr-merge-alloc-table" data-remove="' . (int) $remove_id . '"><thead><tr>';
        echo '<th>Send visits to</th>';
        echo '<th>Scans <span class="description">(of ' . number_format((int) $row->scan_count) . ')</span></th>';
        echo '<th>Social shares <span class="description">(of ' . number_format((int) $row->social_share_count) . ')</span></th>';
        echo '</tr></thead><tbody>';
        foreach ($plan['keep_ids'] as $keep_id) {
            echo '<tr><td>' . $f->describe($dry_run['rows'][$keep_id]) . '</td>';
            echo '<td>' . $this->alloc_input('alloc_scan', $remove_id, $keep_id, $plan['alloc']['scan'][$remove_id][$keep_id], (int) $row->scan_count, 'scan') . '</td>';
            echo '<td>' . $this->alloc_input('alloc_social', $remove_id, $keep_id, $plan['alloc']['social'][$remove_id][$keep_id], (int) $row->social_share_count, 'social') . '</td></tr>';
        }
        echo '<tr class="qr-merge-discard-row"><th>Discarded (permanently deleted)</th>';
        echo '<td><span class="qr-merge-discard" data-type="scan" data-total="' . (int) $row->scan_count . '">0</span></td>';
        echo '<td><span class="qr-merge-discard" data-type="social" data-total="' . (int) $row->social_share_count . '">0</span></td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">Visits are handed out oldest first, in the order listed. Anything not allocated is discarded.</p>';

        $this->render_alias_choice($dry_run, $remove_id);
        echo '</div>';
    }

    private function alloc_input($key, $remove_id, $keep_id, $value, $max, $type) {
        $name = 'qr_merge[' . $key . '][' . (int) $remove_id . '][' . (int) $keep_id . ']';
        return '<input type="number" class="small-text qr-merge-alloc" data-type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . (int) $value . '" min="0" max="' . (int) $max . '" step="1">';
    }

    private function render_alias_choice(array $dry_run, $remove_id) {
        $f       = $this->format;
        $plan    = $dry_run['plan'];
        $row     = $dry_run['rows'][$remove_id];
        $current = $plan['alias'][$remove_id];
        $name    = 'qr_merge[alias][' . (int) $remove_id . ']';

        echo '<p><label for="qr-merge-alias-' . (int) $remove_id . '"><strong>Its printed code <code>' . esc_html((string) $row->short_code) . '</code> after the merge:</strong></label><br>';
        echo '<select id="qr-merge-alias-' . (int) $remove_id . '" name="' . esc_attr($name) . '" class="qr-merge-alias">';
        foreach ($plan['keep_ids'] as $keep_id) {
            echo '<option value="' . (int) $keep_id . '"' . selected($current, $keep_id, false) . '>Keeps working — opens ' . $f->describe($dry_run['rows'][$keep_id]) . '</option>';
        }
        echo '<option value="0"' . selected($current, 0, false) . '>Stops working — printed copies land on a page with no tree</option>';
        echo '</select></p>';
    }

    private function render_field_grid(array $dry_run, $keep_id) {
        $f    = $this->format;
        $plan = $dry_run['plan'];
        $keep = $dry_run['rows'][$keep_id];

        echo '<div class="qr-merge-card">';
        echo '<h3>Keeping ' . $f->describe($keep) . '</h3>';
        echo '<p class="description">Stays <code>' . esc_html((string) $keep->short_code) . '</code> · ' . esc_html((string) $keep->url) . '</p>';

        echo '<div class="qr-merge-scroll"><table class="widefat qr-merge-field-table"><thead><tr><th>Field</th>';
        foreach ($dry_run['rows'] as $id => $row) {
            $class = ($id === $keep_id) ? ' class="is-own"' : '';
            echo '<th' . $class . '>' . esc_html($row->postcode . ' / ' . $row->tree) . '<br><span class="description">ID ' . (int) $id . ($id === $keep_id ? ' · this code' : '') . '</span></th>';
        }
        echo '</tr></thead><tbody>';

        foreach (QRCodeTracker_Merge_Planner::PICKABLE_FIELDS as $field => $label) {
            $this->render_field_row($dry_run, $keep_id, $field, $label);
        }
        echo '</tbody></table></div></div>';
    }

    private function render_field_row(array $dry_run, $keep_id, $field, $label) {
        $f      = $this->format;
        $chosen = $dry_run['plan']['fields'][$keep_id][$field];
        $name   = 'qr_merge[field][' . (int) $keep_id . '][' . $field . ']';

        echo '<tr><th scope="row">' . esc_html($label) . '</th>';
        foreach ($dry_run['rows'] as $id => $row) {
            $input_id = 'qr-merge-f-' . (int) $keep_id . '-' . $field . '-' . (int) $id;
            echo '<td' . ($id === $keep_id ? ' class="is-own"' : '') . '>';
            echo '<label for="' . esc_attr($input_id) . '"><input type="radio" id="' . esc_attr($input_id) . '" name="' . esc_attr($name) . '" value="' . (int) $id . '"' . checked($chosen, $id, false) . '> ';
            echo $f->value($field, $row->$field) . '</label></td>';
        }
        echo '</tr>';
    }

    private function render_rewrite_option(array $plan) {
        echo '<div class="qr-merge-card"><label><input type="checkbox" name="qr_merge[rewrite_logs]" value="1"' . checked($plan['rewrite_logs'], true, false) . '> ';
        echo '<strong>Relabel moved visits with the kept code\'s postcode, city and tree</strong></label>';
        echo '<p class="description">Recommended. Reports group visits by the postcode/city/tree stored on each visit, so without this a removed tree keeps appearing as its own line in reports. Each visit\'s original link is still recorded.</p></div>';
    }
}
