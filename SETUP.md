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

## 5. Create your account, make yourself an admin, and grant yourself access

1. Open the deployed Hub and sign up with your email and a password.
2. Run:
   ```sql
   UPDATE users SET is_admin = 1 WHERE username = 'you@example.com';
   ```
3. Either run the bootstrap query at the bottom of `api/schema.sql`
   (uncomment it, fill in your email) to grant yourself every private app
   in one shot, or open `admin.html` and check the boxes yourself now that
   you're an admin. Either way — otherwise you'd be locked out of your own
   apps on day one.

## Adding another person (via `admin.html`)

1. Have them sign up through the Hub — this only creates their login,
   nothing more.
2. Open `admin.html` (linked from the bottom of the main Hub page — or just
   go there directly), enter their email, and check whichever apps they
   should have. Save.
   - For a **private** app, checking the box is what lets them see/open it
     at all.
   - For a **public** app, everyone can already open it — checking the box
     here grants **edit** rights specifically (see "Public apps with
     editors" below). Those are marked `(Public — box grants editing)` in
     the list so it's not ambiguous which kind of grant you're making.
3. If the email isn't found yet, `admin.html` tells you so and lets you
   search again — it never creates an account for someone; they have to
   sign up themselves first.

`admin.html` requires `users.is_admin = 1` on your account (step 2 above) —
anyone else who's merely logged in gets a 403 if they try to use it.

## Public apps with editors (`can_edit`)

Some public apps need a middle ground: anyone can use the app, but only
specific people should be able to edit its content. **Senior Family
Cookbook** is the motivating case — everyone can browse/scale/favorite/
shopping-list, but only people you authorize should be able to add or edit
recipes.

`app_access.can_edit` is what models this. For a private app it's always
`1` (no view-only tier exists there yet — having a grant at all means full
access). For a public app, a grant means "can edit"; no grant just means
"uses it like everyone else." `admin.html`'s checkbox always creates grants
with `can_edit = 1`, since that's the only kind of grant a public app needs
today.

**Important caveat**: this only takes effect for apps that actually check
it. Right now, only apps built to consult MyDataWorld can enforce this at
all.

## Roadmap

- **Revisit Senior Family Cookbook and Living Lean.** Both currently run on
  a separate database (the old "RecipeFile" backend), not MyDataWorld — so
  `can_edit` grants made here for the Cookbook are recorded correctly but
  not enforced anywhere yet. Needs the same kind of retrofit T-Minus and
  Shed Inventory got for SSO: give the Cookbook a real login against
  MyDataWorld and have it check `can_edit` before allowing a recipe to be
  added/edited. Until then, editing there is open to whoever it's open to
  today (check that app's own code for however it currently gates writes,
  if at all).
- Email notification on grant (mentioned as a nice-to-have when this was
  still a future idea) — `admin.html` doesn't send anything yet; it just
  updates `app_access` silently. Adding an email step later is additive,
  not a redesign.

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
