# My Apps Hub

A single sign-in launcher for your personal apps. Public apps are visible
to anyone; private apps only show up for people explicitly granted access.

See [SETUP.md](SETUP.md) for deployment (database, API, granting access,
and how single sign-on with other apps works).

## Files

- `index.html` — the whole app (HTML, CSS, and JS in one file): a tab strip
  of every app you can currently see, a login/signup card, and a tile view
  per app with an "Open App" button
- `admin.html` — pick a user by email, check which apps they should have,
  save. Requires `users.is_admin = 1` on your own account.
- `api/api.php` — backend: signup/login/sessions, `listApps` (returns every
  public app plus whatever private apps the current visitor has been
  granted), and the admin-only actions behind `admin.html`
  (`adminLookupUser`, `adminGetUserAccess`, `adminSaveAccess`)
- `api/schema.sql` — extends the shared `apps`/`app_access`/`users` tables
  (from T-Minus/Shed Inventory) with display fields (description, icon,
  color, launch URL, SSO flag), an edit-level flag, and an admin flag; seeds
  all current apps
- `api/config.example.php` — copy to `api/config.php` with real DB
  credentials (gitignored)

## How access works

- `apps.is_public` — `1` means visible to everyone, no login needed.
- `app_access` — `(user_id, app_id)` grants a specific app to a specific
  user. For a private app, no grant means no tile at all. For a public app,
  a grant instead means **edit rights** on top of the access everyone
  already has — see `can_edit` below.
- `app_access.can_edit` — always `1` for private-app grants (no view/edit
  split there yet). For a public app, this is the whole point of the grant:
  everyone can use the app, only `can_edit = 1` users can edit its content.
  See SETUP.md's "Public apps with editors" section — and its Roadmap note
  that this isn't enforced yet by Senior Family Cookbook/Living Lean, since
  neither is wired into MyDataWorld yet.
- `apps.sso_enabled` — apps that have been updated to accept a `?token=`
  handoff from the Hub, skipping their own login screen.
- `users.is_admin` — required to use `admin.html`.
