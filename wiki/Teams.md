# Teams

Teams let you group QR codes under a shared organization so multiple people can manage them together.

---

## What Is a Team?

A team is an organization or group that owns one or more QR codes. Teams can be:

- **Public** — visible to all admin users
- **Private** — only visible to team members and admins

Each team has:
- A name and optional description
- A city association
- Optional prefill messages (which can be locked to prevent member edits)
- A list of member users with roles (Admin or Member)

---

## How Teams Are Created

### Automatically (via WooCommerce)

When a customer purchases a tree product and enters a **team/organization name** (instead of selecting "Individual"), the plugin automatically creates a team with that name and assigns the QR codes to it. If a team with that name already exists, the QR codes are added to it.

For **individual** purchases, a private personal team is created for that buyer.

### Manually (via Admin)

1. Go to **QR Tracker → Teams**.
2. Click **Add New Team**.
3. Fill in the team name, description, city, and privacy setting.
4. Save.

---

## Team Roles

| Role | Permissions |
|---|---|
| **Admin** | Full access to the team — edit settings, manage members, update QR codes |
| **Member** | Can view and update team QR codes but cannot change team settings or membership |

---

## Managing Team Members

From **QR Tracker → Teams**, click a team to open it. You can:
- Add WordPress users as admins or members
- Remove users from the team
- View current member list

---

## Prefill Messages

Teams can set a **prefill message** that auto-populates the message fields for QR codes in the team. Optionally, you can **lock** the prefill to prevent members from overriding it on individual QR codes.

---

## Team Management Link

Non-admin team members (and purchasers who aren't WordPress users) access their team via a **management link** — a signed URL sent in the welcome email or access approval notification. The link:

- Does not require a WordPress account to follow
- Prompts the user to log in or create an account if needed
- If the user has no team access, shows a form to request access

---

## Access Request Workflow

When someone follows a management link but doesn't have access to the team:

1. They click **Request Access** on the management page.
2. An email is sent to users with the `qr_tracker_review_qr_access_requests` capability.
3. The reviewer can **Approve** or **Deny** the request.
4. The requester is notified by email of the outcome.
5. If approved, the user is added as a Member of the team.

---

## Team Statistics

Each team page shows:
- Total number of QR codes in the team
- Aggregate scan counts
- Links to individual QR code reports

---

## Deleting a Team

Deleting a team does **not** delete the QR codes — it only removes the team association. QR codes become unassigned and remain in the system.
