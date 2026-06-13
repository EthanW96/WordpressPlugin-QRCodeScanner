# Scanning & Tracking

The plugin records every visit made through a QR code URL and categorizes each scan by its source.

---

## Scan URL Formats

The plugin recognizes these incoming URL patterns and handles them differently:

| URL Format | Example | Tracked As |
|---|---|---|
| Query string scan | `/?qr=abc123` | `query` |
| Path-based share | `/abc123` | `social_link` |
| Legacy format | `/?postcode=XX1&city=London&tree=Tree1` | `legacy` — redirects to `?qr=` format |
| Social with source | `/abc123?qr_source=social` | `social_link` |

---

## What Gets Recorded

For every scan, the plugin logs:

| Field | Description |
|---|---|
| Tracker ID | Which QR code was scanned |
| Scanned At | UTC timestamp |
| Scan Source | `query`, `social_link`, `legacy`, `postcode-city-tree`, or `url_match` |
| Visitor Hash | SHA-256 hash of the visitor's IP address + user agent (not personally identifiable) |
| Request URI | The full URL path and query string |

---

## Visitor Deduplication

The plugin uses a **visitor hash** (SHA-256 of IP + user agent) to detect repeat visitors. This means:

- The same person scanning multiple times appears as multiple scan log entries.
- Reports can distinguish unique vs. repeat visitors.
- No personally identifiable data (IP addresses, names) is stored in the logs.

---

## Scan Counts

Each QR record stores two running counters:

- **Scan Count** — incremented on every `?qr=` query string hit
- **Social Share Count** — incremented on every path-based (`/shortcode`) hit

These are visible in the QR Tracker list view and individual QR reports.

---

## Path-Based Social Shares

When a QR code is shared socially (e.g. on Facebook or Instagram), the shared URL is the path-based short link: `https://yoursite.com/abc123`.

When someone clicks that shared link:
1. The plugin detects it is a path-based request (not a `?qr=` scan).
2. It loads the destination page content **in-place** — the browser URL stays as `/abc123` rather than redirecting.
3. The visit is logged as a `social_link` scan source.
4. The social share count for that QR code is incremented.

This design makes the short link look clean when shared on social media.

---

## Legacy URL Handling

Older QR codes may use the format `/?postcode=XX1&city=London&tree=Tree1`. When the plugin detects this format:
1. It looks up the QR record by postcode/city/tree.
2. Redirects to the canonical `?qr=SHORTCODE` format.
3. Logs the scan as `legacy` source.

---

## Debug Mode

If you need to troubleshoot scan tracking, enable **Visit Tracking Debug Mode** in **QR Tracker → Settings → General**. This displays an on-page panel on scan pages showing:
- Whether a QR record was matched
- Scan source detected
- Timing information

Disable debug mode in production.

---

## Scan Log Storage

All scan records are stored in the `wp_qr_tracker_logs` database table. Logs are not automatically pruned — they accumulate over time. Use the [Reports & Analytics](Reports-and-Analytics) page and CSV export to archive and review old data.
