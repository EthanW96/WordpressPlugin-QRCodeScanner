# Installation

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- WooCommerce (required for automatic QR generation on purchase)
- The **Members** plugin (optional — required only if you want role-based permission management)

---

## Installing the Plugin

### Option A — Upload via WordPress Admin (Recommended)

1. Download the latest `qr_code_tracker.zip` from the [GitHub Releases page](https://github.com/EthanW96/WordpressPlugin-QRCodeScanner/releases).
2. In WordPress Admin, go to **Plugins → Add New Plugin**.
3. Click **Upload Plugin**.
4. Choose the downloaded zip file and click **Install Now**.
5. Click **Activate Plugin**.

### Option B — Manual FTP/SFTP Upload

1. Download and unzip `qr_code_tracker.zip`.
2. Upload the `qr_code_tracker` folder to `/wp-content/plugins/` on your server.
3. In WordPress Admin, go to **Plugins → Installed Plugins**.
4. Find **QR Code Tracker** and click **Activate**.

---

## After Activation

The plugin will automatically:
- Create the required database tables (`wp_qr_tracker`, `wp_qr_tracker_logs`, `wp_qr_tracker_teams`, `wp_qr_tracker_user_teams`, `wp_qr_tracker_access_requests`).
- Register default role capabilities for Administrator, Editor, Author, Contributor, and Subscriber.
- Add the **QR Tracker** menu to your WordPress Admin sidebar.

Proceed to [Getting Started](Getting-Started) for first-time configuration.

---

## Uninstalling

To remove the plugin and optionally delete all data:

1. Before deactivating, go to **QR Tracker → Settings → General**.
2. Check **Delete Data on Uninstall** if you want all plugin tables and options permanently removed.
3. Go to **Plugins → Installed Plugins**, deactivate, then delete the plugin.

> **Warning:** Enabling "Delete Data on Uninstall" will permanently erase all QR codes, scan logs, teams, and settings. This cannot be undone.
