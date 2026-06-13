# WooCommerce Integration

The plugin integrates with WooCommerce to automatically generate QR codes when customers purchase designated tree products.

---

## How It Works

1. You mark one or more WooCommerce products as **tree products** in Settings.
2. When a customer adds a tree product to their cart and views the product page (or checkout), custom tree fields appear.
3. When the order status changes to **Completed**, the plugin automatically:
   - Creates one QR record per tree in the order (respecting quantity).
   - Numbers trees within the same postcode (e.g. "Tree 1", "Tree 2").
   - Assigns the QR codes to a team (individual or named organization).
   - Sends a [welcome email](Welcome-Email) to the purchaser.

---

## Setting Up Tree Products

1. Go to **QR Tracker → Settings → WooCommerce**.
2. In **Tree Product Selection**, hold Ctrl/Cmd and click to select all products that are tree products.
3. Save settings.

---

## Custom Fields Collected at Purchase

These fields are shown to the customer either on the product page or at checkout, depending on your [Tree Field Layout](#tree-field-layout) configuration.

| Field | Description |
|---|---|
| **Purchaser Type** | "Individual" or a team/organization name |
| **First Name** | Purchaser first name (shown for individuals) |
| **Last Name** | Purchaser last name (shown for individuals) |
| **Postcode** | Postcode of the tree location |
| **City** | City of the tree location |
| **Message 1** | First custom message for the QR scan page |
| **Message 2** | Second custom message for the QR scan page |
| **Pay Forward Type** | None / Specific person / General |
| **Pay Forward Recipient** | Name or contact for the pay-forward recipient |

> Fields are conditionally shown — for example, First/Last Name only appear when Purchaser Type is set to Individual.

---

## Tree Field Layout

The **Tree Field Layout** drag-and-drop in Settings lets you split fields between two stages:

- **Product View** — customer fills these in on the product page before adding to cart
- **Checkout Screen** — customer fills these in at the WooCommerce checkout step

This allows sensitive or detailed fields to be deferred to checkout where the customer is more committed.

---

## Multiple Trees in One Order

If a customer purchases quantity > 1 of a tree product, the plugin generates one QR code per unit. Trees in the same postcode are auto-numbered sequentially ("Tree 1", "Tree 2", etc.).

---

## Team Assignment on Purchase

- If the purchaser selects **Individual**, a private team is created for that buyer.
- If the purchaser enters a **team/organization name**, a shared team is created (or an existing team with that name is reused).

Team members receive a **management link** in the welcome email, allowing them to view and update their QR codes without needing a WordPress login upfront.

---

## Pay a Tree Forward

If the **Pay a Tree Forward** product is configured:
- It appears as an optional add-on during the tree purchase flow.
- The customer can choose to pay forward to a specific person or generally.
- This data is captured in the QR record for reporting purposes.

---

## Troubleshooting WooCommerce Integration

**QR codes not created after purchase:**
- Confirm the product is selected under **Tree Product Selection** in Settings.
- Confirm the order status reached **Completed** (not just Processing).
- Check that all required tree fields were filled in at checkout.

**Welcome email not sent:**
- Confirm **Enable Welcome Email** is toggled on in Settings → Welcome Email.
- Check WordPress email delivery (consider a transactional email plugin like WP Mail SMTP).
