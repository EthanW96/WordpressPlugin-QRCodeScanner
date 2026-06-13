# Troubleshooting

---

## QR Codes Not Being Created After Purchase

**Symptoms:** Order completes but no QR code appears in QR Tracker.

**Checklist:**
1. Confirm the purchased product is selected under **QR Tracker → Settings → WooCommerce → Tree Product Selection**.
2. Confirm the order status is **Completed** — QR codes are only created on order completion, not on payment or processing.
3. Check that the customer filled in all required tree fields (Postcode, City) at checkout.
4. Verify WooCommerce order meta by going to the order in **WooCommerce → Orders** and inspecting custom fields in the order detail.

---

## Welcome Email Not Sending

**Symptoms:** Purchase completes but the buyer doesn't receive an email.

**Checklist:**
1. Confirm **Enable Welcome Email** is toggled on in **QR Tracker → Settings → Welcome Email**.
2. Check WordPress's default email sending with a plugin like [WP Mail Log](https://wordpress.org/plugins/wp-mail-log/) to see if emails are being sent at all.
3. WordPress's default `wp_mail` uses PHP `mail()` which many hosts block or flag as spam. Install **WP Mail SMTP** and configure it with a transactional email service (SendGrid, Postmark, Mailgun).
4. Check the buyer's spam/junk folder.

---

## Scans Not Being Recorded

**Symptoms:** QR code is scanned but scan count doesn't increase.

**Checklist:**
1. Enable **Visit Tracking Debug Mode** in **QR Tracker → Settings → General**, then scan the QR code and look for the debug panel on the page.
2. Confirm the QR code URL uses the correct format: `/?qr=SHORTCODE` or `/SHORTCODE`.
3. If using path-based URLs (`/SHORTCODE`), confirm your WordPress permalink structure is set to something other than "Plain" — go to **Settings → Permalinks** and re-save.
4. Check for caching plugins that may be serving a cached page and bypassing the scan tracking code. Exclude QR scan URLs from your cache.

---

## Path-Based Short Links Not Working (`/SHORTCODE`)

**Symptoms:** Visiting `https://yoursite.com/abc123` gives a 404.

**Solution:**
1. Go to **WordPress Admin → Settings → Permalinks**.
2. Click **Save Changes** (even without changing anything) — this flushes the rewrite rules.
3. If still broken, check that your `.htaccess` file (Apache) or nginx config is correctly configured for WordPress rewrites.

---

## Team Management Link Not Working

**Symptoms:** Clicking the management link from the welcome email shows an access denied or 404 error.

**Checklist:**
1. The link is signed — confirm it hasn't been modified or truncated (some email clients wrap long URLs).
2. If the team was deleted after the email was sent, the link will no longer work.
3. The user may need to log in or create a WordPress account first — the page will prompt them.

---

## QR Code Image Not Displaying

**Symptoms:** QR code image broken or returning an error.

**Checklist:**
1. Try accessing the image URL directly: `https://yoursite.com/?qr_img=SHORTCODE`
2. Confirm the short code is correct (no extra characters or spaces).
3. Confirm the QR record still exists in **QR Tracker** — it may have been deleted.
4. Check for PHP errors in your server error log — the image is generated server-side using the `endroid/qr-code` library.

---

## Settings Not Saving

**Symptoms:** Changes to settings don't persist after saving.

**Checklist:**
1. Confirm your WordPress user has the `qr_tracker_manage_settings` capability.
2. Check for a nonce verification failure — this can happen if your session expired. Log out and back in, then try again.
3. Check for JavaScript errors in the browser console that might be blocking the form submission.

---

## Enabling Debug Mode

For general troubleshooting, enable **Visit Tracking Debug Mode** in **QR Tracker → Settings → General**. This outputs a debug panel on scan pages with detailed tracking information.

**Remember to disable debug mode before going back to production.**

---

## Getting Further Help

- Check the [GitHub Issues page](https://github.com/EthanW96/WordpressPlugin-QRCodeScanner/issues) for known bugs or to report a new one.
- Review recent changes in the [GitHub Releases](https://github.com/EthanW96/WordpressPlugin-QRCodeScanner/releases).
