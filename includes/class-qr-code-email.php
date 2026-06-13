<?php

if (!defined('ABSPATH')) {
    exit;
}

class QRCodeTracker_Email {

    private $tracker;

    public function __construct($tracker) {
        $this->tracker = $tracker;
    }

    /**
     * Send the welcome-to-the-network email to the buyer.
     *
     * @param WC_Order $order
     * @param array    $qr_records  Array of stdObjects from wp_qr_tracker (each having url, short_code, city, postcode, tree, label, etc.)
     * @param string   $purchaser_name
     */
    public function send_welcome_email($order, array $qr_records, $purchaser_name) {
        if (!get_option('qr_tracker_welcome_email_enabled', 1)) {
            return;
        }

        $email = $order->get_billing_email();
        if (empty($email) || !is_email($email)) {
            return;
        }

        $subject = get_option('qr_tracker_welcome_email_subject', 'Welcome to the Advent Tree Network!');
        $template = get_option('qr_tracker_welcome_email_body', '');
        if (empty($template)) {
            $template = $this->get_default_template();
        }

        $body = $this->process_shortcodes($template, $order, $qr_records, $purchaser_name);
        $body = $this->wrap_in_email_shell($body);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, $subject, $body, $headers);
    }

    /**
     * Replace email shortcodes with real values.
     */
    public function process_shortcodes($template, $order, array $qr_records, $purchaser_name) {
        $first = !empty($qr_records) ? $qr_records[0] : null;

        $social_share_link = '';
        if ($first && !empty($first->short_code)) {
            $social_share_link = $this->tracker->generate_anonymous_tracker_url($first->short_code);
        }

        $map = [
            '[buyer_name]'       => esc_html($purchaser_name),
            '[order_number]'     => esc_html($order->get_order_number()),
            '[site_name]'        => esc_html(get_bloginfo('name')),
            '[city]'             => $first ? esc_html($first->city) : '',
            '[postcode]'         => $first ? esc_html($first->postcode) : '',
            '[social_share_link]' => esc_url($social_share_link),
            '[qr_codes]'         => $this->render_qr_codes_block($qr_records),
        ];

        return str_replace(array_keys($map), array_values($map), $template);
    }

    /**
     * Render a table of QR code images with download links, one row per tree.
     */
    private function render_qr_codes_block(array $records) {
        if (empty($records)) {
            return '';
        }

        $html = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">';

        foreach ($records as $record) {
            if (empty($record->short_code)) {
                continue;
            }

            $label        = esc_html($record->label ?: $record->tree ?: $record->short_code);
            $img_url      = add_query_arg('qr_img', rawurlencode($record->short_code), home_url('/'));
            $download_url = add_query_arg(['qr_img' => rawurlencode($record->short_code), 'qr_dl' => '1'], home_url('/'));
            $social_url   = esc_url($this->tracker->generate_anonymous_tracker_url($record->short_code));

            $html .= '<tr>';
            $html .= '<td style="padding: 16px 0; text-align: center; border-bottom: 1px solid #e5e7eb;">';
            $html .= '<p style="margin: 0 0 8px; font-size: 16px; font-weight: bold; color: #1a1a1a;">' . $label . '</p>';
            $html .= '<img src="' . esc_url($img_url) . '" alt="QR Code for ' . $label . '" width="200" height="200" style="display: block; margin: 0 auto 12px;" />';
            $html .= '<p style="margin: 0 0 6px;">';
            $html .= '<a href="' . esc_url($download_url) . '" style="display: inline-block; background: #2d5a27; color: #fff; padding: 8px 18px; border-radius: 4px; text-decoration: none; font-size: 14px;">Download QR Code</a>';
            $html .= '</p>';
            if ($social_url) {
                $html .= '<p style="margin: 6px 0 0; font-size: 13px; color: #555;">Share link: <a href="' . $social_url . '" style="color: #2d5a27;">' . $social_url . '</a></p>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Wrap the template body in a minimal HTML email shell.
     */
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

    /**
     * Default "Welcome to the Advent Tree Network" email template.
     */
    public function get_default_template() {
        return '<p>Dear [buyer_name],</p>

<p>Welcome to the <strong>Advent Tree Network</strong>! We are so glad you are part of this growing community. Your tree has been registered and your unique QR code is ready to go.</p>

<h2 style="color:#2d5a27;">Your QR Code</h2>
<p>Below you will find your personal QR code. You can download it and display it on your tree so visitors can scan it and connect with the network.</p>

[qr_codes]

<h2 style="color:#2d5a27;">Share the Network</h2>
<p>Help us grow the Advent Tree Network! Use your personal share link below to spread the word on social media. Every share helps connect more people to the joy of Advent.</p>

<p style="text-align:center;margin:20px 0;">
  <a href="[social_share_link]" style="display:inline-block;background:#2d5a27;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:16px;font-weight:bold;">Share My Tree Link</a>
</p>

<p style="font-size:13px;color:#555;">Or copy this link: <a href="[social_share_link]" style="color:#2d5a27;">[social_share_link]</a></p>

<p>Thank you for being part of something special. We look forward to a wonderful Advent season with you.</p>

<p>Warmly,<br>
The [site_name] Team</p>';
    }
}
