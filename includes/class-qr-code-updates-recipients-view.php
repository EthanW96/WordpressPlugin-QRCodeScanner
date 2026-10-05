<?php
/**
 * The Recipients tab of Purchaser Updates: link this season's existing orders
 * to their QR codes (a dry run first), and add, remove or reassign who
 * receives updates about any QR code.
 */
class QRCodeTracker_Updates_Recipients_View {

    const STATUS_LABELS = [
        QRCodeTracker_Link_Backfill::STATUS_MATCHED   => ['Matched', '#00a32a'],
        QRCodeTracker_Link_Backfill::STATUS_AMBIGUOUS => ['More candidates than trees — closest proposed', '#dba617'],
        QRCodeTracker_Link_Backfill::STATUS_PARTIAL   => ['Fewer QR codes found than trees bought', '#dba617'],
        QRCodeTracker_Link_Backfill::STATUS_UNMATCHED => ['No QR codes found', '#b32d2e'],
    ];

    const SOURCE_LABELS = [
        QRCodeTracker_Recipient_Links::SOURCE_ORDER    => 'order',
        QRCodeTracker_Recipient_Links::SOURCE_BACKFILL => 'matched',
        QRCodeTracker_Recipient_Links::SOURCE_MANUAL   => 'added by hand',
    ];

    private $teams;
    private $team_names = null;

    public function __construct($teams) {
        $this->teams = $teams;
    }

    /**
     * @param array      $codes    QR codes this user can access.
     * @param array      $links    Live links grouped by tracker id.
     * @param array|null $backfill Backfill plan, when the check was run.
     */
    public function render(array $codes, array $links, $backfill) {
        $this->render_backfill($backfill);
        $this->render_editor($codes, $links);
    }

    // ------------------------------------------------------------------
    // Backfill
    // ------------------------------------------------------------------

    private function render_backfill($backfill) {
        echo '<h2>Link existing orders</h2>';
        echo '<p class="description" style="max-width:780px">New orders are linked to their QR codes automatically. Orders placed before this existed are matched here by postcode, city, team and order time. '
            . 'Nothing is saved until you review the matches and confirm. Anything can be corrected afterwards in the list below.</p>';

        if ($backfill === null) {
            echo '<p><a class="button" href="' . esc_url(QRCodeTracker_Updates_Page::url(['tab' => QRCodeTracker_Updates_Page::TAB_RECIPIENTS, 'backfill' => 1])) . '">Find matches for existing orders</a></p>';
            return;
        }
        if (empty($backfill)) {
            echo '<p class="qr-updates-ok">Every order that created QR codes is already linked.</p>';
            return;
        }

        echo QRCodeTracker_Updates_Page::form_open('apply_backfill', QRCodeTracker_Updates_Page::TAB_RECIPIENTS);
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped"><thead><tr>';
        echo '<th style="width:30px"></th><th>Order</th><th>Email</th><th>Trees bought</th><th>Proposed QR codes</th><th>Result</th>';
        echo '</tr></thead><tbody>';
        foreach ($backfill as $entry) {
            $this->render_backfill_row($entry);
        }
        echo '</tbody></table></div>';
        echo '<p class="description">Only matched orders are ticked. Tick others after checking them, or link them by hand below.</p>';
        submit_button('Link selected orders');
        echo '</form>';
    }

    private function render_backfill_row(array $entry) {
        list($label, $colour) = self::STATUS_LABELS[$entry['status']];
        $can_link = !empty($entry['tracker_ids']);
        $checked  = $entry['status'] === QRCodeTracker_Link_Backfill::STATUS_MATCHED;

        echo '<tr><td>';
        if ($can_link) {
            echo '<input type="checkbox" name="approve[' . (int) $entry['order_id'] . ']" value="' . esc_attr(implode(',', $entry['tracker_ids'])) . '"' . checked($checked, true, false) . '>';
        }
        echo '</td>';
        echo '<td><a href="' . esc_url($entry['edit_url']) . '">#' . (int) $entry['order_id'] . '</a><br><span class="description">' . esc_html($entry['date']) . ' UTC</span></td>';
        echo '<td>' . esc_html($entry['email']) . '<br><span class="description">' . esc_html($entry['name']) . '</span></td>';

        $wanted = [];
        $found  = [];
        foreach ($entry['items'] as $item) {
            $wanted[] = sprintf('%d × %s / %s (%s)', $item['quantity'], $item['postcode'], $item['city'], $item['team_name'] !== '' ? $item['team_name'] : 'no team');
            $line = empty($item['labels']) ? '—' : implode(', ', $item['labels']);
            if (!empty($item['near_misses'])) {
                $line .= ' (same postcode & city but not matched: ' . implode('; ', $item['near_misses']) . ')';
            }
            $found[] = $line;
        }
        echo '<td>' . esc_html(implode('; ', $wanted)) . '</td>';
        echo '<td>' . esc_html(implode('; ', $found)) . '</td>';
        echo '<td style="color:' . esc_attr($colour) . ';font-weight:600">' . esc_html($label) . '</td></tr>';
    }

    // ------------------------------------------------------------------
    // Link editor
    // ------------------------------------------------------------------

    private function render_editor(array $codes, array $links) {
        echo '<h2>Who receives updates about each QR code</h2>';
        echo '<p class="description" style="max-width:780px">Add an email to any QR code (including codes not bought through the shop, or a gift recipient), or remove one. '
            . 'Removing a link is remembered: automatic linking will not add it back. To reassign a tree, add the new email and remove the old one. '
            . 'Team members are included automatically when that setting is on, and are not listed here.</p>';

        if (empty($codes)) {
            echo '<p>There are no QR codes you can manage.</p>';
            return;
        }

        echo '<p><input type="search" id="qr-updates-filter" class="regular-text" placeholder="Filter by postcode, city, tree, label, team or email…" aria-label="Filter QR codes"></p>';
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped qr-updates-codes"><thead><tr>';
        echo '<th>QR code</th><th>Team</th><th>Receives updates</th><th>Add a recipient</th>';
        echo '</tr></thead><tbody>';
        foreach ($codes as $code) {
            $this->render_editor_row($code, $links[(int) $code->id] ?? []);
        }
        echo '</tbody></table></div>';
        $this->render_filter_script();
    }

    private function render_editor_row($code, array $code_links) {
        $team   = $this->team_name($code->team_id);
        $emails = implode(' ', array_map(function ($link) { return $link->email; }, $code_links));
        $search = strtolower(implode(' ', [$code->postcode, $code->city, $code->tree, $code->label, $team, $emails]));

        echo '<tr data-search="' . esc_attr($search) . '">';
        echo '<td>' . esc_html($code->postcode . ' / ' . $code->city . ' / ' . $code->tree) . '<br><span class="description">' . esc_html((string) $code->label) . '</span></td>';
        echo '<td>' . esc_html($team) . '</td><td>';

        if (empty($code_links)) {
            echo '<em class="description">Nobody</em>';
        }
        foreach ($code_links as $link) {
            echo $this->link_chip($link);
        }
        echo '</td><td>' . $this->add_form((int) $code->id) . '</td></tr>';
    }

    private function link_chip($link) {
        $source = self::SOURCE_LABELS[$link->source] ?? $link->source;
        $html   = '<span class="qr-updates-chip" title="' . esc_attr($link->name) . '">' . esc_html($link->email)
            . ' <span class="description">(' . esc_html($source) . ($link->is_manual && $link->source !== QRCodeTracker_Recipient_Links::SOURCE_MANUAL ? ', edited' : '') . ')</span>';
        $html  .= QRCodeTracker_Updates_Page::form_open('remove_link', QRCodeTracker_Updates_Page::TAB_RECIPIENTS);
        $html  .= '<input type="hidden" name="link_id" value="' . (int) $link->id . '">';
        $html  .= '<button type="submit" aria-label="' . esc_attr('Remove ' . $link->email) . '" title="Remove">✕</button></form>';
        return $html . '</span>';
    }

    private function add_form($tracker_id) {
        $html  = QRCodeTracker_Updates_Page::form_open('add_link', QRCodeTracker_Updates_Page::TAB_RECIPIENTS);
        $html .= '<input type="hidden" name="tracker_id" value="' . (int) $tracker_id . '">';
        $html .= '<input type="email" name="email" required placeholder="email@example.com" aria-label="Email" style="width:190px"> ';
        $html .= '<input type="text" name="name" placeholder="Name (optional)" aria-label="Name" style="width:130px"> ';
        return $html . '<button type="submit" class="button button-small">Add</button></form>';
    }

    private function team_name($team_id) {
        if (empty($team_id)) {
            return 'No team';
        }
        if ($this->team_names === null) {
            $this->team_names = [];
            foreach ((array) $this->teams->get_all_teams() as $team) {
                $this->team_names[(int) $team->id] = $team->name;
            }
        }
        return $this->team_names[(int) $team_id] ?? 'Team #' . (int) $team_id;
    }

    /**
     * Filtering only hides rows, so it works with or without JavaScript.
     */
    private function render_filter_script() {
        echo '<script>(function(){var f=document.getElementById("qr-updates-filter");if(!f){return;}'
            . 'var rows=document.querySelectorAll(".qr-updates-codes tbody tr");'
            . 'f.addEventListener("input",function(){var t=f.value.trim().toLowerCase();'
            . 'rows.forEach(function(r){r.hidden=t!==""&&(r.getAttribute("data-search")||"").indexOf(t)===-1;});});})();</script>';
    }
}
