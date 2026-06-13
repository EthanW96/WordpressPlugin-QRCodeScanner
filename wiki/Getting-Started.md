# Getting Started

Follow this checklist after installing and activating the plugin for the first time.

---

## Step 1 — Configure General Settings

Go to **QR Tracker → Settings → General**.

- Set your **Default Shop Link** — this URL appears on every QR code scan page as a "shop" button unless a QR code has its own shop link.
- Optionally set a **Default Shop Logo** image URL.

---

## Step 2 — Configure WooCommerce (if using tree purchases)

Go to **QR Tracker → Settings → WooCommerce**.

1. Select which WooCommerce products are **tree products** — customers buying these will see tree-specific checkout fields.
2. Optionally select the **Pay a Tree Forward** product.
3. Use the **Tree Field Layout** drag-and-drop interface to decide which fields appear on the product page vs. at checkout.

See [WooCommerce Integration](WooCommerce-Integration) for full details.

---

## Step 3 — Set Up the Welcome Email (optional)

Go to **QR Tracker → Settings → Welcome Email**.

- Enable the welcome email toggle.
- Customize the subject and body using the available shortcodes (e.g. `[qr_codes]`, `[buyer_name]`).

See [Welcome Email](Welcome-Email) for all shortcodes and tips.

---

## Step 4 — Create Your First QR Code

Go to **QR Tracker** (main page).

- Click **Add New** (or the equivalent create button).
- Enter a **Postcode**, **City**, and **Tree** identifier.
- Optionally add a **Label**, **Reporting ID**, and custom messages.
- Save — the plugin generates a unique short code and QR image.
- Click **Download** to save the QR code PNG.

See [QR Code Management](QR-Code-Management) for full details.

---

## Step 5 — Embed Shortcodes on Your Scan Landing Page (optional)

If your QR codes point to a WordPress page, you can embed dynamic content using shortcodes like `[qr_tracker_message_1]` or `[qr_tracker_shop_link]`.

See [Shortcodes](Shortcodes) for all available shortcodes.

---

## Step 6 — Review Permissions

If you use role-based access control (via the Members plugin), review which capabilities are assigned to each role.

See [Permissions](Permissions) for the full capability list.
