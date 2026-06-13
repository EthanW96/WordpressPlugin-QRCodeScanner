# Welcome Email

The welcome email is automatically sent to a customer when their tree order is completed. It contains their QR code images, download links, share links, and (for team purchases) a management link.

---

## Enabling the Welcome Email

1. Go to **QR Tracker → Settings → Welcome Email**.
2. Toggle **Enable Welcome Email** to on.
3. Customize the subject and body.
4. Save.

---

## Available Shortcodes

Use these shortcodes anywhere in the email subject or body:

| Shortcode | Output |
|---|---|
| `[buyer_name]` | The purchaser's full name |
| `[order_number]` | The WooCommerce order number |
| `[site_name]` | Your WordPress site name |
| `[city]` | The tree's city |
| `[postcode]` | The tree's postcode |
| `[social_share_link]` | The social share URL for the first tree in the order |
| `[qr_codes]` | An HTML table with QR images, download links, and share links for every tree in the order |
| `[management_link]` | A link to the team management page (only included for non-individual/team purchases) |

---

## Example Email Body

```html
<p>Hi [buyer_name],</p>

<p>Thank you for your Advent Tree purchase! Your order #[order_number] is confirmed.</p>

<p>Below are your QR code(s) for [city], [postcode]:</p>

[qr_codes]

<p>Share your tree's page with friends and family using this link:<br>
<a href="[social_share_link]">[social_share_link]</a></p>

[management_link]

<p>Thanks,<br>[site_name] Team</p>
```

---

## The `[qr_codes]` Block

This shortcode outputs an HTML table — one row per QR code in the order. Each row includes:
- The QR code image
- A download link for the PNG
- The social share link

If the order contained multiple trees, all are listed in one table.

---

## The `[management_link]` Block

For **team/organization purchases** (non-individual), this shortcode outputs a paragraph with a secure link that allows the team to manage their QR codes. The link is signed and expires if the team or QR record changes significantly.

For **individual purchases**, this shortcode outputs nothing.

---

## Tips

- Use your WordPress theme's standard email template by keeping the body wrapped in standard HTML — WordPress's `wp_mail` sends HTML if the body contains HTML tags.
- Test delivery using a transactional email service (e.g. WP Mail SMTP with SendGrid or Postmark) to avoid spam filters.
- If you want to preview the email without a real purchase, trigger a test order using WooCommerce's order status editing.
