# My Apps Hub — Setup

A single login page for all your personal apps: every app — public or
private — only shows up (and only opens) for people you've explicitly
granted access to. `is_public` no longer bypasses that; it's just
descriptive metadata now (see "Access and Edit, per app" below). Built on
**MyDataWorld**, the same shared database T-Minus and Shed Inventory
already use.

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
   MyDataWorld apps — plus `SITE_URL` (the Hub's own public URL, used to
   build the links in reset/invitation emails) and what those emails send
   through: `BREVO_API_KEY` (sign up free at brevo.com, verify a sender
   under Settings -> Senders, Domains & Dedicated IPs, then generate a key
   under Settings -> SMTP & API -> API Keys) and `FROM_EMAIL` (must be the
   address you verified as a sender in Brevo).
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
2. Open the Admin Tool tile, enter their email, and set each app's
   dropdown to **No access**, **Access**, or **Access + Edit**. Save.
   - **No access** — the app doesn't show up for them at all, public or not.
   - **Access** — they see the tile and can open/use the app.
   - **Access + Edit** — access, plus edit rights inside apps that check for
     it (see "Access and Edit, per app" below). Apps marked `(Public)` are
     just informational — that flag no longer changes what's granted.
3. If the email isn't found yet, the tool offers to **send an invitation**
   instead — see below.

## Inviting someone who doesn't have an account yet

If `adminLookupUser` comes back empty, `admin.html` shows a **Send
Invitation** button instead of the access grid. Clicking it lets you set
the same per-app dropdown (defaulting every app to **No access**) before
anyone has signed up:

1. Enter their email, click **Send Invitation** when the "no account"
   message appears, set each app's access level, and send.
2. This creates their `users` row **immediately** — `password_hash = NULL`
   marks it as not yet activated — and grants `app_access` for whatever was
   set right away too, exactly like a normal grant. Nothing about their
   access is deferred until they finish signing up; a pending invitation is
   purely "this account exists and is authorized, it just has no password
   yet." A row in `invitations` (14-day expiry) ties it to who invited them
   and drives the emailed link (`index.html?invite=<token>`).
3. Their activation screen shows who invited them and which apps (pulled
   live from `app_access`, so it always reflects the real current grants),
   with the email field pre-filled and locked. Finishing that screen just
   sets their password on the already-existing account — no separate
   account-creation step, and no grants to redeem at that point since they
   already happened in step 2.
4. **If they try to log in before activating**: the login screen
   auto-redirects them into that same activation screen (using the still-
   valid invitation token) instead of just failing with "invalid password."
   This covers someone who lost the email, bookmarked the Hub directly, or
   just tried logging in out of habit. If the invitation has since expired,
   they get a plain error telling them to ask for a new one instead.
5. Once looked up again, an invited-but-not-activated account shows the
   **normal access grid** (found — you can Save changes to their apps any
   time, same as anyone else) plus a **Resend Invitation Email** button.
   Resend re-syncs `app_access` to whatever's currently checked and sends a
   fresh link/expiry — so it also works as "adjust + notify" in one click,
   even before they've activated.
6. An invite token only works once, only for its own email, and only while
   unexpired. There's still no revoke UI; to cancel an invitation outright
   (and the account it created), delete the `users` row directly via
   phpMyAdmin (cascades to `app_access`/`invitations`/`sessions`).

## Access and Edit, per app (`app_access` + `can_edit`)

Every app needs an explicit `app_access` row before it shows up for
someone at all — that row is "Access": they can see the tile and open/use
the app. `can_edit` is a second, independent tier on top of that same row:
"Access + Edit" also lets them edit the app's content, for apps built to
check it. **Senior Family Cookbook** is the motivating case — anyone with
Access can browse/scale/favorite/shopping-list, but only people with
Access + Edit can add or edit recipes.

`admin.html`'s per-app dropdown (No access / Access / Access + Edit) maps
directly to "no row" / "row, `can_edit = 0`" / "row, `can_edit = 1`". Edit
always implies access — there's no way to grant edit without access.

**Important caveat**: `can_edit` only takes effect for apps that actually
check it. Right now, only apps built to consult MyDataWorld can enforce
this at all — for everything else, Access is the only real gate (whether
someone can see the tile), and `can_edit` doesn't mean anything further
inside that app yet.

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

- Every app — public or private — only appears once you're logged in
  **and** have a matching `app_access` row. Anonymous visitors always see
  an empty tab strip and just the sign-in card; there's no way to hold a
  grant without an account. No partial "locked" tile is shown for apps you
  don't have — they're just not in the list at all.
- `is_public` is purely descriptive now (shown as a `(Public)` tag in
  `admin.html`) — it doesn't change who can see or open an app.
- Access/Edit is per-app, not per-household or per-role — matches the
  simple model used everywhere else so far (see Shed Inventory's own
  household-access notes for the same philosophy).
