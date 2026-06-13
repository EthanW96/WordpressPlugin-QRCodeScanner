# Permissions

The plugin defines a granular set of capabilities that can be assigned to any WordPress user role. If you have the **Members** plugin installed, you can manage these from **Members → Roles**.

---

## Default Role Assignments

Out of the box, capabilities are assigned as follows:

| Capability | Admin | Editor | Author | Contributor | Subscriber |
|---|:---:|:---:|:---:|:---:|:---:|
| View QR Codes | ✓ | ✓ | ✓ | ✓ | ✓ |
| Create QR Codes | ✓ | ✓ | ✓ | ✓ | |
| Edit QR Codes | ✓ | ✓ | ✓ | | |
| Delete QR Codes | ✓ | | | | |
| Download QR Codes | ✓ | ✓ | ✓ | | |
| Manage QR Codes (all above) | ✓ | | | | |
| View Teams | ✓ | ✓ | | | |
| Create Teams | ✓ | | | | |
| Edit Teams | ✓ | | | | |
| Delete Teams | ✓ | | | | |
| Assign Users to Teams | ✓ | | | | |
| Remove Users from Teams | ✓ | | | | |
| Assign QR Codes to Teams | ✓ | | | | |
| Manage Teams (all above) | ✓ | | | | |
| View Reports | ✓ | ✓ | ✓ | | |
| View Scan Logs | ✓ | ✓ | | | |
| View Analytics | ✓ | ✓ | | | |
| Export Data | ✓ | | | | |
| View Settings | ✓ | | | | |
| Manage Settings | ✓ | | | | |
| Manage Permissions | ✓ | | | | |
| View All Data (Super Admin) | ✓ | | | | |
| Manage All Teams (Super Admin) | ✓ | | | | |
| Review QR Access Requests | ✓ | | | | |

---

## Capability Reference

### QR Code Capabilities

| Capability Slug | Description |
|---|---|
| `qr_tracker_view_qr_codes` | View the QR Tracker list and individual QR codes |
| `qr_tracker_create_qr_codes` | Create new QR codes |
| `qr_tracker_edit_qr_codes` | Edit existing QR codes |
| `qr_tracker_delete_qr_codes` | Delete QR codes |
| `qr_tracker_download_qr_codes` | Download QR code PNG images |
| `qr_tracker_manage_qr_codes` | All QR code capabilities combined |

### Team Capabilities

| Capability Slug | Description |
|---|---|
| `qr_tracker_view_teams` | View the Teams list |
| `qr_tracker_view_team_members` | View who is in a team |
| `qr_tracker_create_teams` | Create new teams |
| `qr_tracker_edit_teams` | Edit team settings |
| `qr_tracker_delete_teams` | Delete teams |
| `qr_tracker_assign_users_to_teams` | Add users to a team |
| `qr_tracker_remove_users_from_teams` | Remove users from a team |
| `qr_tracker_assign_qr_codes_to_teams` | Assign or move QR codes between teams |
| `qr_tracker_manage_teams` | All team capabilities combined |

### Reporting Capabilities

| Capability Slug | Description |
|---|---|
| `qr_tracker_view_reports` | Access the Reports page |
| `qr_tracker_view_scan_logs` | Access the Scan Logs page |
| `qr_tracker_view_analytics` | View analytics and stats |
| `qr_tracker_export_data` | Export CSV reports |

### Admin Capabilities

| Capability Slug | Description |
|---|---|
| `qr_tracker_view_settings` | View the Settings page |
| `qr_tracker_manage_settings` | Edit settings |
| `qr_tracker_manage_permissions` | Manage role capabilities |
| `qr_tracker_view_all_data` | Super admin — view all QR codes regardless of team |
| `qr_tracker_manage_all_teams` | Super admin — manage any team |
| `qr_tracker_review_qr_access_requests` | Approve or deny team access requests |

---

## Special Role: Access Request Manager

Any user with `qr_tracker_review_qr_access_requests` can review and approve/deny access requests submitted via the team management page. You can assign this capability to a custom role without granting full admin access.

---

## Customizing Permissions with the Members Plugin

1. Install and activate the **Members** plugin.
2. Go to **Members → Roles**.
3. Select a role to edit.
4. Find the **QR Tracker** capability group.
5. Toggle individual capabilities on or off.
6. Save.
