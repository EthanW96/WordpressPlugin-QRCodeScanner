# Shortcodes

The plugin provides shortcodes you can embed on any WordPress page or post. These shortcodes are context-aware — they automatically display data for the QR code that was scanned to reach the page.

---

## `[qr_tracker_message_1]`

Displays the first custom message stored on the QR code that was scanned.

**Usage:**
```
[qr_tracker_message_1]
```

**Notes:**
- Returns nothing if no QR code context is detected (e.g. a direct page visit with no `?qr=` parameter).
- The message is set per QR code in the QR Tracker admin or during WooCommerce checkout.

---

## `[qr_tracker_message_2]`

Displays the second custom message stored on the scanned QR code.

**Usage:**
```
[qr_tracker_message_2]
```

---

## `[qr_tracker_shop_link]`

Displays a shop logo banner and clickable link. Uses the QR code's individual shop link if set, otherwise falls back to the **Default Shop Link** in Settings.

**Usage:**
```
[qr_tracker_shop_link]
```

**Optional attributes:**

| Attribute | Default | Description |
|---|---|---|
| `class` | _(none)_ | Additional CSS class(es) on the wrapper element |
| `target` | `_blank` | Link target attribute |
| `max_width` | `200px` | Maximum width of the logo image |

**Example:**
```
[qr_tracker_shop_link class="my-shop-banner" max_width="150px"]
```

---

## `[qr_tracker_popup]`

Renders a button that, when clicked, opens a modal popup containing both custom messages (Message 1 and Message 2) for the scanned QR code.

**Usage:**
```
[qr_tracker_popup]
```

**Optional attributes:**

| Attribute | Default | Description |
|---|---|---|
| `text` | `Show Messages` | Button label text |
| `class` | _(none)_ | Additional CSS class(es) on the button |
| `style` | _(none)_ | Inline CSS styles on the button |

**Example:**
```
[qr_tracker_popup text="View Your Tree's Messages" class="btn btn-primary"]
```

---

## Where to Use Shortcodes

Add these shortcodes to the WordPress page that your QR codes point to. When someone scans a QR code that links to `https://yoursite.com/tree-page/?qr=abc123`, the shortcodes on `tree-page` will automatically show the messages and shop link for the `abc123` QR code.

---

## Auto-Popup Setting

Separately from the `[qr_tracker_popup]` shortcode, the plugin can auto-show the popup on page load via settings:

- **Auto-show popup** — toggle in Settings
- **Popup delay** — milliseconds before the popup appears
- **Popup position** — where on screen the popup appears (e.g. bottom-right)
- **Popup theme** — visual theme for the popup

These settings apply site-wide on any page visited with an active QR scan context.
