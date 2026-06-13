# QR Code Management

The main QR Tracker admin page (**QR Tracker** in the sidebar) is where you create, view, edit, and download QR codes.

---

## Creating a QR Code

1. Go to **QR Tracker** in WordPress Admin.
2. Click the **Add New** button.
3. Fill in the required fields:

| Field | Required | Description |
|---|---|---|
| Postcode | Yes | Postcode of the tree location |
| City | Yes | City of the tree location |
| Tree | Yes | Tree identifier (e.g. "Tree 1", "Oak", "Front Yard") |
| Label | No | Human-friendly display name |
| Reporting ID | No | Custom ID for grouping in reports |
| Message 1 | No | First custom message shown on the scan page |
| Message 2 | No | Second custom message shown on the scan page |
| Shop Link | No | Overrides the default shop URL for this QR code |

4. Click **Save**. The plugin generates:
   - A unique **short code** (6–16 lowercase alphanumeric characters)
   - An **edit token** for secure direct access
   - A QR code image

---

## Viewing QR Codes

The main **QR Tracker** list shows all QR codes with:
- Short code
- Postcode / City / Tree
- Label
- Scan count
- Social share count
- Team assignment
- Quick action links (Edit, Download, View Report)

---

## Editing a QR Code

Click **Edit** on any QR code in the list. You can update the label, messages, shop link, reporting ID, and team assignment. The short code and edit token are fixed after creation.

---

## Downloading a QR Code

Click **Download** next to any QR code to download a high-resolution PNG file. You can also access the image directly at:

```
https://yoursite.com/?qr_img=SHORTCODE&qr_dl=1
```

To display it inline (e.g. in an `<img>` tag):

```
https://yoursite.com/?qr_img=SHORTCODE
```

---

## QR Code URL Formats

The plugin supports several URL formats for scanning and sharing:

| Format | Example | Use case |
|---|---|---|
| Query string | `/?qr=abc123` | Standard QR code scan URL |
| Path-based | `/abc123` | Social share link (no redirect) |
| Legacy | `/?postcode=XX1&city=London&tree=Tree1` | Older QR codes — auto-redirects to short code format |

> **Path-based URLs** load the page content in-place without changing the browser URL, making them ideal for social media sharing because the short link stays visible.

---

## Deleting a QR Code

Deleting a QR code also removes its scan logs. This action is permanent. Use the delete action on the main QR Tracker list page.

---

## Auto-Generated QR Codes (via WooCommerce)

When a customer purchases a tree product, QR codes are created automatically — one per tree in the order. These follow the same structure as manually created codes. See [WooCommerce Integration](WooCommerce-Integration) for details.
