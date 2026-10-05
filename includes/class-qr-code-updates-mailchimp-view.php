<?php
/**
 * The Mailchimp tab of Purchaser Updates: connection settings, sync status,
 * the dry-run preview and the merge-tag reference.
 */
class QRCodeTracker_Updates_Mailchimp_View {

    const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // Rows shown in the preview before it is truncated.
    const PREVIEW_LIMIT = 500;

    /**
     * @param array      $settings  QRCodeTracker_Mailchimp_Sync::settings()
     * @param array      $lists     ['ok', 'lists' => [id => name], 'error']
     * @param array      $last      Last sync record.
     * @param int|false  $next      Next scheduled run timestamp.
     * @param array|null $preview   QRCodeTracker_Recipients::build_all(), when requested.
     */
    public function render(array $settings, array $lists, array $last, $next, $preview) {
        echo '<p class="description" style="max-width:780px">Mailchimp sends the update emails. This page keeps a Mailchimp audience up to date with each recipient\'s tree numbers as merge fields. '
            . 'Write and schedule the emails in Mailchimp (e.g. every Saturday) and use the merge tags listed below. Nothing is synced until Mailchimp is connected.</p>';

        $this->render_settings($settings, $lists);
        $this->render_status($settings, $last, $next);
        $this->render_preview($preview);
        $this->render_reference();
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private function render_settings(array $settings, array $lists) {
        echo '<h2>Connection and schedule</h2>';
        echo QRCodeTracker_Updates_Page::form_open('save_settings', QRCodeTracker_Updates_Page::TAB_MAILCHIMP);
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->row('API key', $this->api_key_field($settings['api_key']));
        $this->row('Audience', $this->audience_field($settings, $lists));
        $this->row('Tag', '<input type="text" name="mc[tag]" class="regular-text" value="' . esc_attr($settings['tag']) . '" placeholder="e.g. Advent Tree 2026">'
            . '<p class="description">Added to every synced contact, so the emails can be sent to just this segment. Leave blank for no tag.</p>');
        $this->row('Recipients', '<label><input type="checkbox" name="mc[include_team]" value="1"' . checked($settings['include_team'], true, false) . '> Also include team members</label>'
            . '<p class="description">Members of a tree\'s team receive that team\'s trees, as well as the people who bought them. Site-wide QR Tracker admins are never included this way.</p>');
        $this->row('Weekly sync', $this->schedule_fields($settings));
        $this->row('Test mode', '<textarea name="mc[test_emails]" rows="3" class="large-text code" style="max-width:420px" placeholder="you@example.com">'
            . esc_textarea(implode("\n", $settings['test_emails'])) . '</textarea>'
            . '<p class="description"><strong>While any address is listed here, a sync sends only these recipients and nobody else</strong> — use it to try a sync safely. '
            . 'Each address must be a recipient (link it to a QR code on the Recipients tab). Empty it to sync everyone.</p>');

        echo '</tbody></table>';
        submit_button('Save settings');
        echo '</form>';
    }

    private function api_key_field($api_key) {
        if ($api_key === '') {
            return '<input type="password" name="mc[api_key]" class="regular-text" autocomplete="off" placeholder="xxxxxxxxxxxxxxxx-us21">'
                . '<p class="description">Mailchimp → Profile → Extras → API keys.</p>';
        }
        // The saved key is never sent back to the browser: only its ending.
        return '<p>Connected with a key ending <code>' . esc_html(substr($api_key, -4)) . '</code>.</p>'
            . '<input type="password" name="mc[api_key]" class="regular-text" autocomplete="off" placeholder="Paste a new key to replace it">'
            . '<p><label><input type="checkbox" name="mc[clear_api_key]" value="1"> Disconnect (remove the saved key)</label></p>';
    }

    private function audience_field(array $settings, array $lists) {
        if ($settings['api_key'] === '') {
            return '<p class="description">Save an API key first.</p><input type="hidden" name="mc[list_id]" value="' . esc_attr($settings['list_id']) . '">';
        }
        if (!$lists['ok']) {
            $error = $lists['error'] !== '' ? $lists['error'] : 'Save the API key, then pick an audience.';
            return '<p class="qr-updates-error">' . esc_html($error) . '</p>'
                . '<input type="text" name="mc[list_id]" class="regular-text" value="' . esc_attr($settings['list_id']) . '" placeholder="Audience ID">';
        }

        $html = '<select name="mc[list_id]"><option value="">— Choose an audience —</option>';
        foreach ($lists['lists'] as $id => $name) {
            $html .= '<option value="' . esc_attr($id) . '"' . selected($settings['list_id'], $id, false) . '>' . esc_html($name) . '</option>';
        }
        return $html . '</select><p class="description">Test with a separate test audience before pointing this at your real one.</p>';
    }

    private function schedule_fields(array $settings) {
        $html = '<label><input type="checkbox" name="mc[auto]" value="1"' . checked($settings['auto'], true, false) . '> Sync automatically every week</label><br>';
        $html .= '<select name="mc[day]">';
        foreach (self::WEEKDAYS as $index => $day) {
            $html .= '<option value="' . (int) $index . '"' . selected($settings['day'], $index, false) . '>' . esc_html($day) . '</option>';
        }
        $html .= '</select> at <input type="time" name="mc[time]" value="' . esc_attr($settings['time']) . '"> <span class="description">(' . esc_html(wp_timezone_string()) . ')</span>';
        return $html . '<p class="description">Pick a time before your Mailchimp send, so the numbers are fresh. WordPress runs scheduled tasks when the site gets a visit; for exact timing ask your host to run <code>wp-cron.php</code> from a server cron.</p>';
    }

    private function row($label, $field_html) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field_html . '</td></tr>';
    }

    // ------------------------------------------------------------------
    // Status and sync
    // ------------------------------------------------------------------

    private function render_status(array $settings, array $last, $next) {
        echo '<h2>Sync</h2>';
        echo '<table class="widefat striped" style="max-width:780px"><tbody>';
        echo '<tr><th style="width:200px">Next scheduled sync</th><td>' . ($next ? esc_html(wp_date('l j F Y, H:i', $next)) : '<em>Not scheduled</em>') . '</td></tr>';
        echo '<tr><th>Last sync</th><td>' . $this->last_sync_summary($last) . '</td></tr>';
        echo '</tbody></table>';

        echo '<p><a class="button" href="' . esc_url(QRCodeTracker_Updates_Page::url(['preview' => 1])) . '#qr-updates-preview">Preview what would be sent</a> ';
        if (QRCodeTracker_Mailchimp_Sync::is_configured()) {
            echo QRCodeTracker_Updates_Page::form_open('sync_now', QRCodeTracker_Updates_Page::TAB_MAILCHIMP, 'qr-updates-inline-form');
            echo '<button type="submit" class="button button-primary">Sync now</button></form>';
        }
        echo '</p>';
    }

    private function last_sync_summary(array $last) {
        if (empty($last)) {
            return '<em>Never</em>';
        }

        $html = esc_html(wp_date('j M Y, H:i', strtotime($last['time'] . ' UTC'))) . ' (' . esc_html($last['trigger']) . ') — '
            . '<span class="' . ($last['ok'] ? 'qr-updates-ok' : 'qr-updates-error') . '">' . esc_html($last['message']) . '</span>';

        if (!empty($last['batch_id'])) {
            $html .= '<br>Mailchimp batch <code>' . esc_html($last['batch_id']) . '</code>: ' . esc_html($last['batch_status'] ?? 'pending');
            if (isset($last['total_ops'])) {
                $html .= sprintf(' — %d of %d operations done, %d with errors.', (int) $last['finished_ops'], (int) $last['total_ops'], (int) $last['errored_ops']);
            }
            if (!empty($last['status_error'])) {
                $html .= ' <span class="qr-updates-error">Could not check its progress: ' . esc_html($last['status_error']) . '</span>';
            }
            if (!empty($last['errored_ops']) && !empty($last['response_url'])) {
                $html .= ' <a href="' . esc_url($last['response_url']) . '">Download Mailchimp\'s error details</a>.';
            }
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    private function render_preview($preview) {
        if ($preview === null) {
            return;
        }

        echo '<h2 id="qr-updates-preview">Preview — what the next sync sends</h2>';
        if (empty($preview)) {
            echo '<p>No recipients yet. Link purchasers to their QR codes on the <a href="' . esc_url(QRCodeTracker_Updates_Page::url(['tab' => QRCodeTracker_Updates_Page::TAB_RECIPIENTS])) . '">Recipients</a> tab.</p>';
            return;
        }

        $first   = reset($preview);
        $to_send = QRCodeTracker_Mailchimp_Sync::filter_for_sync($preview);
        echo '<p>' . count($preview) . ' recipient(s). Network: ' . number_format((int) $first['fields']['NETSCANS']) . ' scans across ' . number_format((int) $first['fields']['NETTREES']) . ' trees.</p>';
        if (count($to_send) < count($preview)) {
            echo '<div class="notice notice-warning inline"><p><strong>Test mode is on:</strong> a sync sends only ' . count($to_send) . ' of these ' . count($preview) . ' recipient(s), marked “Sent”. Everyone else is left out.</p></div>';
        }
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped"><thead><tr>';
        echo '<th>Sent</th><th>Email</th><th>Name</th><th>Via</th><th>Trees</th><th>Scans</th><th>Shares</th><th>Clicks</th><th>Team scans</th><th>Breakdown</th>';
        echo '</tr></thead><tbody>';

        foreach (array_slice($preview, 0, self::PREVIEW_LIMIT) as $email => $entry) {
            $fields = $entry['fields'];
            echo '<tr><td>' . (isset($to_send[$email]) ? '<span class="qr-updates-ok">Sent</span>' : '<span class="description">Skipped</span>') . '</td>';
            echo '<td>' . esc_html($email) . '</td><td>' . esc_html($entry['recipient']['name']) . '</td>';
            echo '<td>' . esc_html(implode(', ', $entry['recipient']['via'])) . '</td>';
            foreach (['TREES', 'MYSCANS', 'MYSHARES', 'MYCLICKS', 'TEAMSCANS'] as $tag) {
                echo '<td>' . number_format((int) $fields[$tag]) . '</td>';
            }
            echo '<td>' . esc_html($fields['TREELIST']) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        if (count($preview) > self::PREVIEW_LIMIT) {
            echo '<p class="description">Showing the first ' . (int) self::PREVIEW_LIMIT . '. All of them are synced.</p>';
        }
    }

    // ------------------------------------------------------------------
    // Merge tag reference
    // ------------------------------------------------------------------

    private function render_reference() {
        echo '<h2>Merge tags for your Mailchimp emails</h2>';
        echo '<table class="widefat striped" style="max-width:780px"><thead><tr><th>Tag</th><th>Contains</th></tr></thead><tbody>';
        echo '<tr><td><code>*|FNAME|*</code></td><td>First name — only filled in when the contact has none in Mailchimp; an existing name is never overwritten</td></tr>';
        foreach (QRCodeTracker_Recipients::merge_field_definitions() as $tag => $definition) {
            echo '<tr><td><code>*|' . esc_html($tag) . '|*</code></td><td>' . esc_html($definition[0]) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<p>A per-tree breakdown that only shows the trees someone owns — paste into a Mailchimp text block:</p>';
        echo '<textarea readonly rows="12" class="large-text code" style="max-width:780px">' . esc_textarea($this->breakdown_snippet()) . '</textarea>';
        echo '<p class="description">Mailchimp shows videos as a thumbnail linking to the video (use its Video content block); email clients cannot play video inline.</p>';
    }

    private function breakdown_snippet() {
        $lines = ['Your trees have been scanned *|MYSCANS|* times, and your website button clicked *|MYCLICKS|* times.', ''];
        for ($slot = 1; $slot <= QRCodeTracker_Recipients::TREE_SLOTS; $slot++) {
            $lines[] = "*|IF:T{$slot}NAME|*";
            $lines[] = "*|T{$slot}NAME|*: *|T{$slot}SCANS|* scans, *|T{$slot}CLICKS|* website clicks — *|T{$slot}URL|*";
            $lines[] = '*|END:IF|*';
        }
        // Plain IF checks only: they work on every Mailchimp plan, where
        // comparisons such as MORETREES>0 may be rejected as invalid.
        $lines[] = '*|IF:T2NAME|*';
        $lines[] = 'All your trees at a glance: *|TREELIST|*';
        $lines[] = '*|END:IF|*';
        $lines[] = '';
        $lines[] = 'Across the whole Advent Tree Network: *|NETSCANS|* scans of *|NETTREES|* trees.';
        return implode("\n", $lines);
    }
}
