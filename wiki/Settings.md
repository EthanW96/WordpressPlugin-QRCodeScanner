# Settings

All plugin settings live at **QR Tracker → Settings** in WordPress Admin. Settings are divided into tabs.

---

## General Tab

| Setting | Description |
|---|---|
| **Delete Data on Uninstall** | When checked, all plugin database tables and options are permanently deleted when the plugin is removed. Default: off. |
| **Visit Tracking Debug Mode** | Displays an on-page debug panel on QR scan pages showing tracking status and timing info. Useful for troubleshooting. Default: off. |

---

## Shop Link Tab

| Setting | Description |
|---|---|
| **Default Shop Link** | The URL used as the "shop" button on QR scan pages when no QR-code-specific shop link is set. |
| **Default Shop Logo** | Image URL for the shop logo shown in the shop link banner. |

> Individual QR codes can override these defaults with their own Shop Link field.

---

## WooCommerce Tab

### Tree Product Selection

A multi-select list of all WooCommerce products. Any product selected here will show the tree-specific custom fields when a customer views or purchases it.

### Pay a Tree Forward Product

A single product selector for the "Pay a Tree Forward" add-on product, which adds a fee to the order.

### Tree Field Layout

A drag-and-drop interface with two columns:

- **Product View** — fields shown on the product page before checkout
- **Checkout Screen** — fields shown at the WooCommerce checkout step

Drag any field between the two columns to control when customers see it. Changes take effect immediately after saving.

### Tree Field Text Overrides

For each tree field, you can customize:
- **Label** — the visible field name shown to customers
- **Helper Text** — descriptive text shown beneath the field

Fields available for override:

| Field | Default Label |
|---|---|
| Purchaser Type | Individual or Team Name |
| First Name | First Name |
| Last Name | Last Name |
| Postcode | Postcode |
| City | City |
| Message 1 | Message 1 |
| Message 2 | Message 2 |
| Pay Forward Type | Pay a Tree Forward |
| Pay Forward Recipient | Recipient Contact |

---

## Welcome Email Tab

See the dedicated [Welcome Email](Welcome-Email) page for full documentation.

| Setting | Description |
|---|---|
| **Enable Welcome Email** | Toggle to send an automated email when a tree order completes. |
| **Email Subject** | Customizable subject line. Supports plain text. |
| **Email Body** | Full HTML editor with media support. Supports dynamic shortcodes. |
