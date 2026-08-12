# My Apps Hub

A single sign-in launcher for your personal apps. Public apps are visible
to anyone; private apps only show up for people explicitly granted access.

See [SETUP.md](SETUP.md) for deployment (database, API, granting access,
and how single sign-on with other apps works).

## Files

- `index.html` — the whole app (HTML, CSS, and JS in one file): a tab strip
  of every app you can currently see, a login/signup card, and a tile view
  per app with an "Open App" button
- `api/api.php` — backend: signup/login/sessions, and `listApps` (returns
  every public app plus whatever private apps the current visitor has been
  granted)
- `api/schema.sql` — extends the shared `apps` table (from T-Minus/Shed
  Inventory) with display fields (description, icon, color, launch URL,
  SSO flag) and seeds all current apps
- `api/config.example.php` — copy to `api/config.php` with real DB
  credentials (gitignored)

## How access works

- `apps.is_public` — `1` means visible to everyone, no login needed.
- `app_access` — `(user_id, app_id)` grants a specific private app to a
  specific user. No grant, no tile.
- `apps.sso_enabled` — apps that have been updated to accept a `?token=`
  handoff from the Hub, skipping their own login screen.
