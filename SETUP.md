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
   MyDataWorld apps — plus `FROM_EMAIL` (the address password-reset and
   invitation emails come from) and `SITE_URL` (the Hub's own public URL,
   used to build the links in those emails).
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

## Password reset

"Forgot password?" on the login screen now actually works: it emails a
one-time link (`index.html?resetToken=<token>`, valid for 1 hour) to set a
new password. A few things worth knowing:

- It always says "if that email has an account, a reset link is on its
  way," whether or not the email is registered — so the form can't be used
  to check who has an account here.
- Setting a new password signs every device out (deletes all of that
  user's sessions) — a reasonable default for "something's wrong enough
  with my password that I need a new one."
- This depends on `mail()` actually working on your host — same caveat as
  Choir Admin Panel's absence-notification email. If a reset email never
  arrives, check your host's mail logs before assuming the code is at
  fault.

## Adding another person (via the Admin Tool)

`admin.html` is registered as its own private app in `apps` (`admin-tool`,
`🔑`), so it shows up as a normal tile in the Hub — like everything else,
just granted to almost nobody. Two separate gates protect it, and it's
worth knowing they're different:

- **`app_access` for `admin-tool`** only controls whether the *tile* shows
  up in someone's tab strip. It's a convenience, not a lock — `admin.html`
  is a public URL like any other page.
- **`users.is_admin = 1`** is the real gate — every admin action in
  `api.php` checks this server-side and returns a 403 without it,
  regardless of how someone got to the page.

So: grant yourself both (the bootstrap query below already grants every
private app, `admin-tool` included, so one run covers it) — but if you ever
want someone to see the tile without being able to actually act as an
admin, that's not possible with just an `app_access` grant; only
`is_admin` matters for real. In practice, only ever set `is_admin = 1` for
people you'd trust with full access to everything anyway.

To grant someone access to a normal app:

1. Have them sign up through the Hub — this only creates their login,
   nothing more.
2. Open the Admin Tool tile, enter their email, and check whichever apps
   they should have. Save.
   - For a **private** app, checking the box is what lets them see/open it
     at all.
   - For a **public** app, everyone can already open it — checking the box
     here grants **edit** rights specifically (see "Public apps with
     editors" below). Those are marked `(Public — box grants editing)` in
     the list so it's not ambiguous which kind of grant you're making.
3. If the email isn't found yet, the tool offers to **send an invitation**
   instead — see below.

## Inviting someone who doesn't have an account yet

If `adminLookupUser` comes back empty, `admin.html` shows a **Send
Invitation** button instead of the access grid. Clicking it lets you pick
which apps to grant (same checkbox list, same `(Public — box grants
editing)` meaning as a normal grant) before anyone has signed up:

1. Enter their email, click **Send Invitation** when the "no account"
   message appears, check whichever apps they should have, and send.
2. This creates a row in `invitations` (14-day expiry) plus one
   `invitation_apps` row per app you checked, and emails them a link
   (`index.html?invite=<token>`).
3. Their signup screen shows who invited them and which apps, with the
   email field pre-filled and locked to the invited address. The moment
   they finish signing up, the invited apps are granted (`can_edit = 1`)
   automatically and the invitation is marked accepted — no separate trip
   to the admin tool needed afterward.
4. An invite token only works once and only for its own email — if it's
   expired or already used, signup still works normally, it just won't have
   the "you're invited" banner or the automatic grants.
5. **Resending**: entering the same email again and clicking Send
   Invitation updates the existing pending invitation (fresh token, fresh
   14-day expiry, whatever app selection you just submitted) and re-sends
   the email — the old link stops working. There's still no revoke UI; to
   cancel an invitation outright, delete its row directly in `invitations`
   via phpMyAdmin.

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

- ~~Revisit Senior Family Cookbook and Living Lean~~ — **done.** Both moved
  off the old standalone "RecipeFile" database onto MyDataWorld
  (`cookbook_recipes`/`cookbook_tags`/`cookbook_recipe_tags`), and recipe
  add/edit/delete now checks `app_access.can_edit` for the `senior-family-
  cookbook`/`living-lean` app_key, replacing the old per-account
  `users.collection` restriction that lived only in that standalone
  database. Both are still public apps — browsing/scaling/favoriting/
  shopping-lists stay open to everyone, only writes are gated. See that
  repo's `api/api.php` for the pattern; it's the same auth/authorization
  shape as Choir Admin Panel, applied to a `can_edit`-on-a-public-app case
  instead of a private-app case.
- Email notification on grant (mentioned as a nice-to-have when this was
  still a future idea) — `admin.html` doesn't send anything yet; it just
  updates `app_access` silently. Adding an email step later is additive,
  not a redesign.

## Single sign-on (SSO) with other apps

Apps with `sso_enabled = 1` in the `apps` table get launched with the
current session token appended to their URL
(`https://.../?token=abc123...`), so a user who's already logged into the
Hub doesn't have to log in again inside that app. Right now that's
**T-Minus**, **Shed Inventory**, **PWI Weight Tracker**, **Choir Admin
Panel**, **SOJO Membership**, **Senior Family Cookbook**, and **Living
Lean** — the other apps that also live on MyDataWorld. For the two public
cookbook apps, SSO
doesn't skip a login screen (they never had one to browse) — it just means
a Hub-launched visit shows up already in edit mode if that account has
`can_edit`, instead of needing to log in a second time inside the app to
unlock the Add/Edit/Delete buttons. Each app needed a small update to look
for `?token=` on load and use it instead of
showing its own login screen (done as part of each app's rollout).

Every other app either has its own separate login system (Master
Checklist) or none at all — SSO doesn't apply to those either way, since
they were never sharing this login to begin with. Marking one of those apps
"private" here controls whether its tile shows up in the Hub; it does
**not** add real login protection to an app that doesn't already have one.

## Notes

- Public apps show up in the tab strip for anyone, logged in or not.
- Private apps only appear once you're logged in **and** have a matching
  `app_access` row — no partial "locked" tile is shown for apps you don't
  have; they're just not in the list at all.
- Access is per-app, not per-household or per-role — matches the simple
  binary model used everywhere else so far (see Shed Inventory's own
  household-access notes for the same philosophy).
