# Reports & Analytics

The plugin provides three levels of scan reporting, all accessible under the **QR Tracker** admin menu.

---

## Scan Logs

**QR Tracker → Scan Logs**

A chronological list of every individual scan event. Columns include:
- Date/Time (UTC)
- QR code (postcode / city / tree)
- Scan source
- Visitor hash
- Request URI

Use this page to audit specific scans or investigate unusual activity.

### Exporting Scan Logs

Click **Export CSV** on the Scan Logs page to download all visible log entries. You can filter first to narrow the export.

---

## Reports

**QR Tracker → Reports**

The Reports page provides aggregated analytics with filter and grouping options.

### Report Types

| Report Type | Description |
|---|---|
| **Breakdown** | One row per QR code showing scan count, social share count, last scan date, and team |
| **Rollup** | Aggregated by postcode or city — useful for seeing how a region is performing |
| **Single QR Report** | Detailed analytics for one specific QR code |
| **City Report** | All QR codes and scans for a specific city |
| **Reporting ID Report** | All QR codes grouped by your custom Reporting ID field |

### Filters Available

- Date range (from / to)
- Postcode
- City
- Team
- Reporting ID
- Scan source

### Exporting Reports

Every report view has an **Export CSV** button that downloads the filtered results.

---

## Individual QR Code Report

Click **View Report** next to any QR code in the main QR Tracker list (or in the Breakdown report) to open a detailed view for that single QR code.

Shows:
- Total scan count and social share count
- Scan timeline chart
- Day-by-day breakdown
- Full scan log for that code

---

## City Report

Accessible via a direct admin URL or from report drill-downs. Shows all QR codes in a city with their scan counts and team assignments.

---

## Reporting ID Report

If you assign a **Reporting ID** to your QR codes (e.g. a campaign name or event code), the Reporting ID report groups all matching QR codes together with aggregate stats — useful for tracking a specific campaign across multiple locations.

---

## Key Metrics Explained

| Metric | Definition |
|---|---|
| **Scan Count** | Total `?qr=SHORTCODE` hits (direct QR scans) |
| **Social Share Count** | Total `/SHORTCODE` path-based hits (social link clicks) |
| **Unique Visitors** | Count of distinct visitor hashes |
| **Last Scanned** | Timestamp of the most recent scan log entry |
| **Last Social Shared** | Timestamp of the most recent social link hit |
