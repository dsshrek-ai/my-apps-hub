# My Apps Hub

A single sign-in launcher for your personal apps. Every app — public or
private — only shows up for people explicitly granted access; there's no
more anonymous or "public, no grant needed" browsing.

See [SETUP.md](SETUP.md) for deployment (database, API, granting access,
and how single sign-on with other apps works).

## Files

- `index.html` — the whole app (HTML, CSS, and JS in one file): a tab strip
  of every app you can currently see, a login/signup card (also handles
  "forgot password" and invitation links), and a tile view per app with an
  "Open App" button
- `admin.html` — pick a user by email, check which apps they should have,
  save. If the email doesn't have an account yet, offers to send an
  invitation instead (which creates the account and grants right away —
  see SETUP.md); if it's found but not yet activated, offers to resend.
  Requires `users.is_admin = 1` on your own account.
- `api/api.php` — backend: signup/login/sessions, `listApps` (returns every
  public app plus whatever private apps the current visitor has been
  granted), password reset (`requestPasswordReset`/`resetPassword`),
  invitations (`getInvitation`, and the admin-only `adminAppList`/
  `adminSendInvitation`), and the rest of the admin-only actions behind
  `admin.html` (`adminLookupUser`, `adminGetUserAccess`, `adminSaveAccess`).
  `login` auto-detects a not-yet-activated account and hands the client its
  pending invitation token instead of just failing.
- `api/schema.sql` — extends the shared `apps`/`app_access`/`users` tables
  (from T-Minus/Shed Inventory) with display fields (description, icon,
  color, launch URL, SSO flag), an edit-level flag, an admin flag,
  `password_resets`, and `invitations` (tied to a pre-created `users` row —
  see SETUP.md); seeds all current apps
- `api/config.example.php` — copy to `api/config.php` with real DB
  credentials, a From: address, and the Hub's own public URL (gitignored)

## How access works

- `app_access` — `(user_id, app_id)`. No row means no tile at all, for any
  app, public or private. A row is "Access": can see and use the app.
- `app_access.can_edit` — a second tier on that same row: `0` = access
  only, `1` = access + edit rights, for apps built to check it. Edit always
  implies access. See SETUP.md's "Access and Edit, per app" section —
  Senior Family Cookbook and Living Lean are the current example.
- `apps.is_public` — purely descriptive now (shown as a `(Public)` tag in
  `admin.html`); it no longer bypasses the `app_access` check.
- `apps.sso_enabled` — apps that have been updated to accept a `?token=`
  handoff from the Hub, skipping their own login screen.
- `users.is_admin` — required to use `admin.html`.
