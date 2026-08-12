# My Apps Hub — Setup

A single login page for all your personal apps: public apps are open to
anyone, private apps only show up (and only open) for people you've
explicitly granted access to. Built on **MyDataWorld**, the same shared
database T-Minus and Shed Inventory already use.

## 1. Update the database

Open **phpMyAdmin**, select the MyDataWorld database, go to the **SQL** tab,
and run everything in [`api/schema.sql`](api/schema.sql). It's safe to run
even if `users`/`sessions`/`apps`/`app_access` already exist (from T-Minus
or Shed Inventory) — those use `CREATE TABLE IF NOT EXISTS`. This also adds
four new columns to `apps` (description, icon, color, launch URL) and seeds
rows for all 15 current apps with the public/private list you gave me.

## 2. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` — same credentials as your other
   MyDataWorld apps.
2. Upload the whole `api/` folder via FTP/File Manager — e.g.
   `seniorfamily.org/my-apps-hub-api/`.

## 3. Point the app at your API

In `index.html`:

```js
const API_URL = 'https://seniorfamily.org/my-apps-hub-api/api.php';
```

## 4. Publish

Either upload `index.html` to seniorfamily.org next to `personalapps.html`,
or push this repo and turn on GitHub Pages (Settings → Pages → Deploy from
branch → `main` / `/(root)`) — your call, both work the same way the other
apps do.

## 5. Create your account and grant yourself the private apps

1. Open the deployed Hub and sign up with your email and a password.
2. Run the bootstrap query at the bottom of `api/schema.sql` (uncomment it,
   fill in your email) to grant yourself every private app in one shot —
   otherwise you'd be locked out of your own apps on day one.

## Adding another person

1. Have them sign up through the Hub — this only creates their login.
2. Grant them whichever private apps they should see:
   ```sql
   INSERT INTO app_access (user_id, app_id)
   SELECT u.id, a.id FROM users u, apps a
   WHERE u.username = 'them@example.com' AND a.app_key = 'shed-inventory';
   ```
   Repeat the last line's `app_key` for each app they need. Nothing to do
   for public apps — everyone already sees those.

(The plan is to eventually build an admin screen for this — pick an app,
paste in a list of emails, and have it email each person letting them know
the app's been added for them. Not built yet; today this is a manual SQL
step. `app_access` already has everything that screen would need to write
to, so building it later is additive, not a redesign.)

## Single sign-on (SSO) with other apps

Apps with `sso_enabled = 1` in the `apps` table get launched with the
current session token appended to their URL
(`https://.../?token=abc123...`), so a user who's already logged into the
Hub doesn't have to log in again inside that app. Right now that's
**T-Minus** and **Shed Inventory** — the two other apps that also live on
MyDataWorld. Each of those apps' `index.html` needed a small update to look
for `?token=` on load and use it instead of showing its own login screen
(done as part of this rollout).

Every other app either has its own separate login system (Master
Checklist, PWI Weight Tracker — Google Sheets-backed) or none at all — SSO
doesn't apply to those either way, since they were never sharing this login
to begin with. Marking one of those apps "private" here controls whether
its tile shows up in the Hub; it does **not** add real login protection to
an app that doesn't already have one. `Choir Admin Panel` already has its
own password protection, so that one's covered regardless.

## Notes

- Public apps show up in the tab strip for anyone, logged in or not.
- Private apps only appear once you're logged in **and** have a matching
  `app_access` row — no partial "locked" tile is shown for apps you don't
  have; they're just not in the list at all.
- Access is per-app, not per-household or per-role — matches the simple
  binary model used everywhere else so far (see Shed Inventory's own
  household-access notes for the same philosophy).
