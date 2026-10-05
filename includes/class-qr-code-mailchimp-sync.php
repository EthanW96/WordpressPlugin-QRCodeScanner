<?php
/**
 * Keeps a Mailchimp audience's merge fields in step with each recipient's
 * tree stats, on a weekly schedule and on demand.
 *
 * The plugin never sends email itself: the emails are written and scheduled
 * in Mailchimp. Members are written with status_if_new, never status, so an
 * existing contact's subscription status — including an unsubscribe — is
 * never changed. Nothing runs until an API key and audience are saved.
 */
class QRCodeTracker_Mailchimp_Sync {

    const CRON_HOOK = 'qr_tracker_mailchimp_sync';

    const OPTION_API_KEY  = 'qr_tracker_mc_api_key';
    const OPTION_LIST_ID  = 'qr_tracker_mc_list_id';
    const OPTION_TAG      = 'qr_tracker_mc_tag';
    const OPTION_AUTO     = 'qr_tracker_mc_auto_sync';
    const OPTION_DAY      = 'qr_tracker_mc_sync_day';
    const OPTION_TIME     = 'qr_tracker_mc_sync_time';
    const OPTION_LAST     = 'qr_tracker_mc_last_sync';
    const OPTION_TEST     = 'qr_tracker_mc_test_emails';

    // Runs up to this size are sent contact by contact, so the result and
    // any per-contact error are known at once (test mode always is). Larger
    // runs go as one Mailchimp batch, processed in the background.
    const DIRECT_LIMIT = 25;

    // Friday evening, so the numbers are fresh for a Saturday send.
    const DEFAULT_DAY  = 5;
    const DEFAULT_TIME = '18:00';

    private $recipients;

    public function __construct(QRCodeTracker_Recipients $recipients) {
        $this->recipients = $recipients;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public static function settings() {
        return [
            'api_key'      => (string) get_option(self::OPTION_API_KEY, ''),
            'list_id'      => (string) get_option(self::OPTION_LIST_ID, ''),
            'tag'          => (string) get_option(self::OPTION_TAG, ''),
            'auto'         => (bool) get_option(self::OPTION_AUTO, 0),
            'day'          => (int) get_option(self::OPTION_DAY, self::DEFAULT_DAY),
            'time'         => (string) get_option(self::OPTION_TIME, self::DEFAULT_TIME),
            'include_team' => QRCodeTracker_Recipients::include_team_members(),
            'test_emails'  => self::test_emails(),
        ];
    }

    /**
     * Test mode: when any addresses are set, a sync sends only those
     * recipients and nobody else. Lets a sync be tried against a real
     * audience without adding real purchasers to it.
     *
     * @return string[] Normalised addresses; empty when test mode is off.
     */
    public static function test_emails() {
        $saved = get_option(self::OPTION_TEST, []);
        return is_array($saved) ? $saved : [];
    }

    /**
     * The recipients a sync would actually send, honouring test mode.
     */
    public static function filter_for_sync(array $built) {
        $test = self::test_emails();
        return empty($test) ? $built : array_intersect_key($built, array_flip($test));
    }

    public static function is_configured() {
        $settings = self::settings();
        return $settings['api_key'] !== '' && $settings['list_id'] !== '';
    }

    /**
     * Save settings from the admin form, then reschedule.
     *
     * @param array $input Unslashed form values.
     * @return string[] Problems found (empty when all saved).
     */
    public function save_settings(array $input) {
        $errors = [];

        $api_key = isset($input['api_key']) ? trim(sanitize_text_field($input['api_key'])) : '';
        if (!empty($input['clear_api_key'])) {
            delete_option(self::OPTION_API_KEY);
        } elseif ($api_key !== '') {
            if (QRCodeTracker_Mailchimp_Client::data_center($api_key) === '') {
                $errors[] = 'That does not look like a Mailchimp API key (it should end in something like "-us21"). It was not saved.';
            } else {
                update_option(self::OPTION_API_KEY, $api_key, false);
            }
        }

        update_option(self::OPTION_LIST_ID, sanitize_text_field($input['list_id'] ?? ''), false);
        update_option(self::OPTION_TAG, mb_substr(sanitize_text_field($input['tag'] ?? ''), 0, 100), false);
        update_option(self::OPTION_AUTO, empty($input['auto']) ? 0 : 1, false);
        update_option(QRCodeTracker_Recipients::OPTION_INCLUDE_TEAM, empty($input['include_team']) ? 0 : 1, false);

        $test = [];
        foreach (preg_split('/[\s,;]+/', (string) ($input['test_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $candidate) {
            $email = QRCodeTracker_Recipient_Links::normalize_email($candidate);
            if ($email !== '' && is_email($email)) {
                $test[$email] = $email;
            } else {
                $errors[] = '"' . $candidate . '" is not a valid email address, so it was left out of test mode.';
            }
        }
        update_option(self::OPTION_TEST, array_values($test), false);

        $day = isset($input['day']) ? (int) $input['day'] : self::DEFAULT_DAY;
        update_option(self::OPTION_DAY, ($day >= 0 && $day <= 6) ? $day : self::DEFAULT_DAY, false);

        $time = isset($input['time']) ? (string) $input['time'] : '';
        update_option(self::OPTION_TIME, preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULT_TIME, false);

        $this->schedule_next();
        return $errors;
    }

    private function client() {
        return new QRCodeTracker_Mailchimp_Client(self::settings()['api_key']);
    }

    public function get_lists() {
        $settings = self::settings();
        if ($settings['api_key'] === '') {
            return ['ok' => false, 'lists' => [], 'error' => ''];
        }
        return $this->client()->get_lists();
    }

    // ------------------------------------------------------------------
    // Sync
    // ------------------------------------------------------------------

    /**
     * Push every recipient's merge fields to Mailchimp as one batch.
     *
     * @param string $trigger "manual" or "schedule", recorded with the result.
     * @return array ['ok' => bool, 'message' => string]
     */
    public function run($trigger) {
        if (!self::is_configured()) {
            return $this->record($trigger, false, 'Mailchimp is not connected yet.');
        }

        $settings = self::settings();
        $fields   = $this->ensure_merge_fields($settings['list_id']);
        if ($fields !== true) {
            return $this->record($trigger, false, $fields);
        }

        $built = self::filter_for_sync($this->recipients->build_all());
        if (empty($built)) {
            $why = empty(self::test_emails())
                ? 'There are no recipients to sync yet.'
                : 'Test mode is on and none of its addresses is a recipient. Link a test email to a QR code on the Recipients tab first.';
            return $this->record($trigger, true, $why);
        }

        $mode       = empty(self::test_emails()) ? '' : ' (test mode — only the test addresses)';
        $operations = $this->build_operations($built, $settings);

        if (count($built) <= self::DIRECT_LIMIT) {
            return $this->run_direct($trigger, $operations, count($built), $mode);
        }

        $response = $this->client()->start_batch($operations);
        if (!$response['ok']) {
            return $this->record($trigger, false, $response['error']);
        }

        return $this->record($trigger, true, sprintf('Sent %d recipient(s) to Mailchimp%s. Mailchimp processes the batch in the background.', count($built), $mode), [
            'batch_id'     => (string) ($response['data']['id'] ?? ''),
            'batch_status' => (string) ($response['data']['status'] ?? 'pending'),
            'recipients'   => count($built),
        ]);
    }

    /**
     * Send each operation as its own request and report every failure by
     * contact, so a small sync's outcome is known immediately.
     */
    private function run_direct($trigger, array $operations, $recipient_count, $mode) {
        $client = $this->client();
        $errors = [];

        foreach ($operations as $operation) {
            $response = $client->request($operation['method'], $operation['path'], json_decode($operation['body'], true));
            if (!$response['ok']) {
                $body     = json_decode($operation['body'], true);
                $who      = $body['email_address'] ?? basename(dirname($operation['path'])) . ' (tag)';
                $errors[] = $who . ': ' . $response['error'];
            }
        }

        if (!empty($errors)) {
            return $this->record($trigger, false, sprintf('Synced %d recipient(s)%s with %d error(s): %s', $recipient_count, $mode, count($errors), implode(' | ', $errors)));
        }
        return $this->record($trigger, true, sprintf('Synced %d recipient(s) to Mailchimp%s. Done.', $recipient_count, $mode));
    }

    /**
     * Create any merge field this sync needs that the audience lacks.
     *
     * @return true|string True, or what went wrong.
     */
    public function ensure_merge_fields($list_id) {
        $client   = $this->client();
        $existing = $client->get_merge_field_tags($list_id);
        if (!$existing['ok']) {
            return $existing['error'];
        }

        foreach (QRCodeTracker_Recipients::merge_field_definitions() as $tag => $definition) {
            if (in_array($tag, $existing['tags'], true)) {
                continue;
            }
            $created = $client->create_merge_field($list_id, $tag, $definition[0], $definition[1]);
            if (!$created['ok']) {
                return 'Could not create the ' . $tag . ' merge field: ' . $created['error'];
            }
        }
        return true;
    }

    /**
     * One PUT per recipient, plus a tag request when a tag is configured.
     * status_if_new only applies to contacts Mailchimp does not have yet.
     */
    private function build_operations(array $built, array $settings) {
        $list       = '/lists/' . rawurlencode($settings['list_id']) . '/members/';
        $operations = [];

        foreach ($built as $email => $entry) {
            $path = $list . QRCodeTracker_Mailchimp_Client::subscriber_hash($email);
            $operations[] = [
                'method' => 'PUT',
                'path'   => $path,
                'body'   => wp_json_encode([
                    'email_address' => $email,
                    'status_if_new' => 'subscribed',
                    'merge_fields'  => $entry['fields'],
                ]),
            ];
            if ($settings['tag'] !== '') {
                $operations[] = [
                    'method' => 'POST',
                    'path'   => $path . '/tags',
                    'body'   => wp_json_encode(['tags' => [['name' => $settings['tag'], 'status' => 'active']]]),
                ];
            }
        }
        return $operations;
    }

    /**
     * Update the last sync's batch progress from Mailchimp.
     */
    public function refresh_status() {
        $last = self::last_sync();
        if (empty($last['batch_id']) || ($last['batch_status'] ?? '') === 'finished' || !self::is_configured()) {
            return $last;
        }

        $response = $this->client()->get_batch($last['batch_id']);
        // Shown on the page, not stored: a failed check must not look like "pending".
        $last['status_error'] = $response['ok'] ? '' : $response['error'];
        if ($response['ok']) {
            $data = $response['data'];
            $last['batch_status']  = (string) ($data['status'] ?? '');
            $last['finished_ops']  = (int) ($data['finished_operations'] ?? 0);
            $last['errored_ops']   = (int) ($data['errored_operations'] ?? 0);
            $last['total_ops']     = (int) ($data['total_operations'] ?? 0);
            $last['response_url']  = (string) ($data['response_body_url'] ?? '');
            update_option(self::OPTION_LAST, $last, false);
        }
        return $last;
    }

    public static function last_sync() {
        $last = get_option(self::OPTION_LAST, []);
        return is_array($last) ? $last : [];
    }

    private function record($trigger, $ok, $message, array $extra = []) {
        update_option(self::OPTION_LAST, array_merge([
            'time'    => current_time('mysql', 1),
            'trigger' => $trigger,
            'ok'      => (bool) $ok,
            'message' => $message,
        ], $extra), false);

        if (!$ok) {
            error_log('[QR Tracker Mailchimp] Sync (' . $trigger . ') failed: ' . $message);
        }
        return ['ok' => (bool) $ok, 'message' => $message];
    }

    // ------------------------------------------------------------------
    // Schedule
    // ------------------------------------------------------------------

    public function run_scheduled() {
        $this->run('schedule');
        $this->schedule_next();
    }

    /**
     * Schedule the next weekly sync, or clear it when auto-sync is off.
     */
    public function schedule_next() {
        self::clear_schedule();

        $settings = self::settings();
        if (!$settings['auto'] || !self::is_configured()) {
            return;
        }
        wp_schedule_single_event(self::next_run_timestamp($settings['day'], $settings['time']), self::CRON_HOOK);
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * The next occurrence of the weekday (0 = Sunday) and time, in the site's
     * timezone.
     */
    public static function next_run_timestamp($day, $time) {
        $zone = wp_timezone();
        $now  = new DateTimeImmutable('now', $zone);
        list($hour, $minute) = array_map('intval', explode(':', $time));

        $next = $now->setTime($hour, $minute);
        $days = ((int) $day - (int) $now->format('w') + 7) % 7;
        $next = $next->modify('+' . $days . ' days');
        if ($next <= $now) {
            $next = $next->modify('+7 days');
        }
        return $next->getTimestamp();
    }

    public static function next_scheduled() {
        return wp_next_scheduled(self::CRON_HOOK);
    }
}
