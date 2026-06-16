<?php
if (!defined('ABSPATH')) exit;

class QRCodeTracker_OrgRequests {

    private $table;
    private $teams;

    public function __construct($teams) {
        global $wpdb;
        $this->table = $wpdb->prefix . 'qr_tracker_org_requests';
        $this->teams = $teams;

        add_shortcode('qr_tracker_org_request_form', [$this, 'render_request_form']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_recaptcha']);
        add_action('admin_post_nopriv_qr_tracker_org_request', [$this, 'handle_form_submission']);
        add_action('admin_post_qr_tracker_org_request', [$this, 'handle_form_submission']);
    }

    // ─── Public form ──────────────────────────────────────────────────────────

    private function recaptcha_is_configured() {
        return get_option('qr_tracker_recaptcha_site_key', '') !== ''
            && get_option('qr_tracker_recaptcha_api_key', '') !== ''
            && get_option('qr_tracker_recaptcha_project_id', '') !== '';
    }

    public function maybe_enqueue_recaptcha() {
        if (!$this->recaptcha_is_configured()) {
            return;
        }
        $site_key = get_option('qr_tracker_recaptcha_site_key', '');
        // Enterprise JS — loaded with ?render=SITE_KEY so execute() is available immediately
        wp_enqueue_script(
            'google-recaptcha-enterprise',
            'https://www.google.com/recaptcha/enterprise.js?render=' . rawurlencode($site_key),
            [],
            null,
            false // load in <head> so it's ready before the inline script runs
        );
    }

    public function render_request_form($atts) {
        $site_key    = get_option('qr_tracker_recaptcha_site_key', '');
        $use_captcha = $this->recaptcha_is_configured();
        $result      = $this->get_form_result_message();
        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';
        $form_id     = 'qr-org-request-form-' . wp_rand(1000, 9999);

        ob_start();
        ?>
        <div class="qr-org-request-wrap" style="max-width:560px;">
            <?php if ($result): ?>
                <div class="qr-org-request-notice" style="padding:12px 16px;margin-bottom:20px;border-radius:4px;border-left:4px solid <?php echo $result['type'] === 'success' ? '#2d5a27' : '#d63638'; ?>;background:<?php echo $result['type'] === 'success' ? '#f0f7ee' : '#fcf0f1'; ?>;">
                    <?php echo esc_html($result['message']); ?>
                    <?php if ($result['type'] === 'success' && !empty($redirect_to)): ?>
                        <p style="margin:10px 0 0;"><a href="<?php echo esc_url($redirect_to); ?>" style="color:#2d5a27;font-weight:600;">&larr; Back to shop</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($result) || $result['type'] !== 'success'): ?>
            <form id="<?php echo esc_attr($form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('qr_tracker_org_request', 'qr_org_request_nonce'); ?>
                <input type="hidden" name="action" value="qr_tracker_org_request">
                <input type="hidden" name="qr_org_form_page" value="<?php echo esc_url(get_permalink() ?: home_url('/')); ?>">
                <?php if ($redirect_to !== ''): ?>
                <input type="hidden" name="qr_org_redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                <?php endif; ?>
                <?php if ($use_captcha): ?>
                <input type="hidden" name="qr_recaptcha_token" id="<?php echo esc_attr($form_id); ?>-token" value="">
                <?php endif; ?>

                <!-- Honeypot — hidden from real users -->
                <div style="display:none!important;visibility:hidden;position:absolute;left:-9999px;" aria-hidden="true">
                    <input type="text" name="qr_org_hp_field" value="" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="qr_org_form_time" value="<?php echo esc_attr(time()); ?>">

                <p style="margin-bottom:16px;">
                    <label for="qr_org_name" style="display:block;font-weight:600;margin-bottom:4px;">Organisation Name <span style="color:#d63638;">*</span></label>
                    <input type="text" id="qr_org_name" name="qr_org_name" required style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:15px;" value="<?php echo esc_attr(isset($_GET['org']) ? sanitize_text_field(wp_unslash($_GET['org'])) : ''); ?>">
                </p>
                <p style="margin-bottom:16px;">
                    <label for="qr_org_person_name" style="display:block;font-weight:600;margin-bottom:4px;">Your Name <span style="color:#d63638;">*</span></label>
                    <input type="text" id="qr_org_person_name" name="qr_org_person_name" required style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:15px;">
                </p>
                <p style="margin-bottom:16px;">
                    <label for="qr_org_email" style="display:block;font-weight:600;margin-bottom:4px;">Your Email <span style="color:#d63638;">*</span></label>
                    <input type="email" id="qr_org_email" name="qr_org_email" required style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:15px;">
                </p>

                <p style="margin-bottom:0;">
                    <button type="submit" id="<?php echo esc_attr($form_id); ?>-btn" style="background:#2d5a27;color:#fff;padding:10px 24px;border:none;border-radius:4px;font-size:15px;cursor:pointer;font-weight:600;">Submit Request</button>
                </p>
            </form>

            <?php if ($use_captcha): ?>
            <script>
            (function() {
                var formId   = <?php echo wp_json_encode($form_id); ?>;
                var siteKey  = <?php echo wp_json_encode($site_key); ?>;
                var form     = document.getElementById(formId);
                var tokenEl  = document.getElementById(formId + '-token');
                var btn      = document.getElementById(formId + '-btn');
                if (!form || !tokenEl || !btn) return;

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    btn.disabled = true;
                    btn.textContent = 'Verifying…';

                    grecaptcha.enterprise.ready(function() {
                        grecaptcha.enterprise.execute(siteKey, { action: 'org_request' })
                            .then(function(token) {
                                tokenEl.value = token;
                                form.submit();
                            })
                            .catch(function() {
                                btn.disabled = false;
                                btn.textContent = 'Submit Request';
                                alert('reCAPTCHA verification failed. Please try again.');
                            });
                    });
                });
            })();
            </script>
            <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_form_result_message() {
        if (!isset($_GET['qr_org_status'])) {
            return null;
        }
        $messages = [
            'success' => ['type' => 'success', 'message' => 'Your request has been submitted! We\'ll review it and let you know by email once a decision has been made.'],
            'exists'  => ['type' => 'error',   'message' => 'An organisation with that name already exists. Please check the purchasing dropdown — it should already be available.'],
            'error'   => ['type' => 'error',   'message' => 'Something went wrong. Please try again.'],
            'spam'    => ['type' => 'error',   'message' => 'Your submission could not be verified. Please try again.'],
        ];
        $status = sanitize_key(wp_unslash($_GET['qr_org_status']));
        return $messages[$status] ?? null;
    }

    // ─── Form submission ───────────────────────────────────────────────────────

    public function handle_form_submission() {
        if (!isset($_POST['qr_org_request_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['qr_org_request_nonce'])), 'qr_tracker_org_request')) {
            wp_die('Invalid request.', 'Error', ['response' => 400]);
        }

        $form_page   = !empty($_POST['qr_org_form_page'])
            ? esc_url_raw(wp_unslash($_POST['qr_org_form_page']))
            : home_url('/');
        $form_page   = remove_query_arg(['qr_org_status', 'redirect_to'], $form_page);
        $redirect_to = !empty($_POST['qr_org_redirect_to'])
            ? esc_url_raw(wp_unslash($_POST['qr_org_redirect_to']))
            : '';

        // Honeypot check
        if (!empty($_POST['qr_org_hp_field'])) {
            wp_safe_redirect(add_query_arg('qr_org_status', 'spam', $form_page));
            exit;
        }

        // Minimum time check (3 seconds to fill the form)
        $form_time = isset($_POST['qr_org_form_time']) ? (int) $_POST['qr_org_form_time'] : 0;
        if ($form_time > 0 && (time() - $form_time) < 3) {
            wp_safe_redirect(add_query_arg('qr_org_status', 'spam', $form_page));
            exit;
        }

        // reCAPTCHA Enterprise verification
        if ($this->recaptcha_is_configured()) {
            $token = isset($_POST['qr_recaptcha_token'])
                ? sanitize_text_field(wp_unslash($_POST['qr_recaptcha_token']))
                : '';
            if (!$this->verify_recaptcha_enterprise($token)) {
                wp_safe_redirect(add_query_arg('qr_org_status', 'spam', $form_page));
                exit;
            }
        }

        $org_name    = sanitize_text_field(wp_unslash($_POST['qr_org_name'] ?? ''));
        $person_name = sanitize_text_field(wp_unslash($_POST['qr_org_person_name'] ?? ''));
        $email       = sanitize_email(wp_unslash($_POST['qr_org_email'] ?? ''));

        if ($org_name === '' || $person_name === '' || !is_email($email)) {
            wp_safe_redirect(add_query_arg('qr_org_status', 'error', $form_page));
            exit;
        }

        // Check if org already exists as a team
        if (is_object($this->teams) && method_exists($this->teams, 'get_team_by_name')) {
            $existing = $this->teams->get_team_by_name($org_name);
            if ($existing && !empty($existing->id)) {
                wp_safe_redirect(add_query_arg('qr_org_status', 'exists', $form_page));
                exit;
            }
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            $this->table,
            [
                'org_name'        => $org_name,
                'requester_name'  => $person_name,
                'requester_email' => $email,
                'status'          => 'pending',
                'requested_at'    => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            wp_safe_redirect(add_query_arg('qr_org_status', 'error', $form_page));
            exit;
        }

        $request = $this->get_request($wpdb->insert_id);
        if ($request) {
            $this->send_requester_confirmation($request);
            $this->send_admin_notifications($request);
        }

        $success_url = add_query_arg('qr_org_status', 'success', $form_page);
        if ($redirect_to !== '') {
            $success_url = add_query_arg('redirect_to', rawurlencode($redirect_to), $success_url);
        }
        wp_safe_redirect($success_url);
        exit;
    }

    private function verify_recaptcha_enterprise($token) {
        if (empty($token)) {
            return false;
        }
        $site_key   = get_option('qr_tracker_recaptcha_site_key', '');
        $api_key    = get_option('qr_tracker_recaptcha_api_key', '');
        $project_id = get_option('qr_tracker_recaptcha_project_id', '');
        $threshold  = (float) get_option('qr_tracker_recaptcha_threshold', 0.5);

        $endpoint = add_query_arg(
            'key',
            $api_key,
            'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($project_id) . '/assessments'
        );

        $result = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode([
                'event' => [
                    'token'          => $token,
                    'siteKey'        => $site_key,
                    'expectedAction' => 'org_request',
                    'userIpAddress'  => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                    'userAgent'      => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
                ],
            ]),
            'timeout' => 10,
        ]);

        if (is_wp_error($result)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($result), true);

        // Token must be valid
        if (empty($data['tokenProperties']['valid'])) {
            return false;
        }

        // Action must match what we sent from the JS
        if (($data['tokenProperties']['action'] ?? '') !== 'org_request') {
            return false;
        }

        // Score must meet the configured threshold
        $score = isset($data['riskAnalysis']['score']) ? (float) $data['riskAnalysis']['score'] : 0.0;
        return $score >= $threshold;
    }

    // ─── DB methods ───────────────────────────────────────────────────────────

    public function get_request($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            (int) $id
        ));
    }

    public function get_requests($status = null, $limit = 200) {
        global $wpdb;
        if ($status !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY requested_at DESC LIMIT %d",
                $status,
                $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY requested_at DESC LIMIT %d",
            $limit
        ));
    }

    public function get_requests_reviewed($limit = 200) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status != 'pending' ORDER BY reviewed_at DESC LIMIT %d",
            $limit
        ));
    }

    public function get_pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'");
    }

    public function approve_request($id) {
        global $wpdb;
        $request = $this->get_request($id);
        if (!$request || $request->status !== 'pending') {
            return false;
        }

        // Create the team
        if (is_object($this->teams) && method_exists($this->teams, 'create_team')) {
            $this->teams->create_team($request->org_name);
        }

        $wpdb->update(
            $this->table,
            [
                'status'      => 'approved',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => get_current_user_id(),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        $this->send_approval_notification($request);
        return true;
    }

    public function deny_request($id) {
        global $wpdb;
        $request = $this->get_request($id);
        if (!$request || $request->status !== 'pending') {
            return false;
        }
        $wpdb->update(
            $this->table,
            [
                'status'      => 'denied',
                'reviewed_at' => current_time('mysql'),
                'reviewed_by' => get_current_user_id(),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        return true;
    }

    // ─── Emails ───────────────────────────────────────────────────────────────

    private function send_requester_confirmation($request) {
        if (!get_option('qr_tracker_org_req_confirmation_enabled', 1)) {
            return;
        }
        $subject  = get_option('qr_tracker_org_req_confirmation_subject', 'Your organisation request has been received');
        $template = get_option('qr_tracker_org_req_confirmation_body', '');
        if (empty($template)) {
            $template = $this->get_default_confirmation_template();
        }
        $body = $this->process_email_shortcodes($template, $request);
        $body = $this->wrap_in_email_shell($body);
        wp_mail($request->requester_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private function send_admin_notifications($request) {
        if (!get_option('qr_tracker_org_req_admin_notify_enabled', 1)) {
            return;
        }
        $review_link = admin_url('admin.php?page=qr-org-requests');
        $subject     = get_option('qr_tracker_org_req_admin_notify_subject', 'New organisation request: [org_name]');
        $subject     = $this->process_email_shortcodes($subject, $request, ['review_link' => $review_link], true);
        $template    = get_option('qr_tracker_org_req_admin_notify_body', '');
        if (empty($template)) {
            $template = $this->get_default_admin_notification_template();
        }
        $body = $this->process_email_shortcodes($template, $request, ['review_link' => $review_link]);
        $body = $this->wrap_in_email_shell($body);

        $admins = get_users(['role' => 'administrator', 'fields' => ['user_email']]);
        foreach ($admins as $admin) {
            if (is_email($admin->user_email)) {
                wp_mail($admin->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }
    }

    private function send_approval_notification($request) {
        if (!get_option('qr_tracker_org_req_approval_enabled', 1)) {
            return;
        }
        $subject   = get_option('qr_tracker_org_req_approval_subject', 'Your organisation request has been approved!');
        $shop_link = get_option('qr_tracker_default_shop_link', '');
        if (empty($shop_link) && function_exists('wc_get_page_id')) {
            $shop_page_id = wc_get_page_id('shop');
            if ($shop_page_id > 0) {
                $shop_link = get_permalink($shop_page_id);
            }
        }
        if (empty($shop_link)) {
            $shop_link = home_url('/');
        }
        $template = get_option('qr_tracker_org_req_approval_body', '');
        if (empty($template)) {
            $template = $this->get_default_approval_template();
        }
        $body = $this->process_email_shortcodes($template, $request, ['shop_link' => $shop_link]);
        $body = $this->wrap_in_email_shell($body);
        wp_mail($request->requester_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private function process_email_shortcodes($template, $request, $extra = [], $is_subject = false) {
        $shop_link   = isset($extra['shop_link'])   ? $extra['shop_link']   : get_option('qr_tracker_default_shop_link', home_url('/'));
        $review_link = isset($extra['review_link']) ? $extra['review_link'] : admin_url('admin.php?page=qr-org-requests');

        // Subject lines are plain text — no HTML encoding. Bodies use esc_html() to be safe in HTML context.
        $escape = $is_subject ? 'sanitize_text_field' : 'esc_html';

        $map = [
            '[org_name]'        => $escape($request->org_name),
            '[requester_name]'  => $escape($request->requester_name),
            '[requester_email]' => $escape($request->requester_email),
            '[site_name]'       => $escape(get_bloginfo('name')),
            '[shop_link]'       => esc_url($shop_link),
            '[review_link]'     => esc_url($review_link),
        ];
        return str_replace(array_keys($map), array_values($map), $template);
    }

    private function wrap_in_email_shell($body) {
        $site_name = esc_html(get_bloginfo('name'));
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $site_name . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Georgia,serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;">
<tr><td align="center" style="padding:32px 16px;">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
<tr><td style="background:#2d5a27;padding:28px 32px;text-align:center;">
<h1 style="margin:0;color:#fff;font-size:26px;font-family:Georgia,serif;">' . $site_name . '</h1>
</td></tr>
<tr><td style="padding:32px;color:#1a1a1a;font-size:16px;line-height:1.7;">
' . $body . '
</td></tr>
<tr><td style="background:#f3f4f6;padding:20px 32px;text-align:center;font-size:12px;color:#888;">
&copy; ' . date('Y') . ' ' . $site_name . '. All rights reserved.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
    }

    // ─── Default email templates ──────────────────────────────────────────────

    public function get_default_confirmation_template() {
        return '<p>Dear [requester_name],</p>

<p>Thank you for submitting a request to add <strong>[org_name]</strong> to the [site_name] network.</p>

<p>We have received your request and our team will review it shortly. You will receive another email once a decision has been made.</p>

<p>If you have any questions in the meantime, please don\'t hesitate to get in touch.</p>

<p>Warm regards,<br>
The [site_name] Team</p>';
    }

    public function get_default_admin_notification_template() {
        return '<p>Hello,</p>

<p>A new organisation request has been submitted on <strong>[site_name]</strong>.</p>

<table style="border-collapse:collapse;width:100%;margin:16px 0;">
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;width:40%;border:1px solid #e5e7eb;">Organisation Name</td><td style="padding:8px 12px;border:1px solid #e5e7eb;">[org_name]</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;border:1px solid #e5e7eb;">Requester Name</td><td style="padding:8px 12px;border:1px solid #e5e7eb;">[requester_name]</td></tr>
<tr><td style="padding:8px 12px;background:#f3f4f6;font-weight:600;border:1px solid #e5e7eb;">Requester Email</td><td style="padding:8px 12px;border:1px solid #e5e7eb;">[requester_email]</td></tr>
</table>

<p style="text-align:center;margin:24px 0;">
<a href="[review_link]" style="display:inline-block;background:#2d5a27;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:15px;font-weight:bold;">Review Request in WordPress</a>
</p>

<p style="font-size:13px;color:#555;">Or copy this link: <a href="[review_link]" style="color:#2d5a27;">[review_link]</a></p>

<p>The [site_name] Team</p>';
    }

    public function get_default_approval_template() {
        return '<p>Dear [requester_name],</p>

<p>Great news! Your request to add <strong>[org_name]</strong> to the [site_name] network has been approved.</p>

<p>[org_name] is now available in the &ldquo;Purchasing As&rdquo; dropdown when buying a tree. You can head back to our shop and select your organisation when making a purchase.</p>

<p style="text-align:center;margin:24px 0;">
<a href="[shop_link]" style="display:inline-block;background:#2d5a27;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:15px;font-weight:bold;">Go to the Shop</a>
</p>

<p>Thank you for being part of the [site_name] community!</p>

<p>Warm regards,<br>
The [site_name] Team</p>';
    }
}
