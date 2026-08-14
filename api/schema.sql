-- ============================================================
-- My Apps Hub — schema for MyDataWorld
-- Run this in phpMyAdmin's SQL tab against the MyDataWorld database.
-- ============================================================

-- ---------- PLATFORM TABLES (shared across every MyDataWorld app) ----------
-- These already exist from T-Minus/Shed-Inventory; idempotent so this file
-- also works standalone on a fresh database.

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  app_key     VARCHAR(50) NOT NULL UNIQUE,
  name        VARCHAR(100) NOT NULL,
  is_public   TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_access (
  user_id     INT NOT NULL,
  app_id      INT NOT NULL,
  granted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, app_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: admin flag + edit-level access ----------
-- Run once. is_admin gates the new admin.html tool. can_edit distinguishes
-- "can open this app" from "can edit within it" — only meaningful for
-- public apps today (a public app needs no app_access row to be *used*,
-- so a row there means "this person can edit," not "has access"). Defaults
-- to 1 so every existing grant keeps meaning what it already means: full
-- access to a private app.

ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE app_access ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 1;

-- One-time: make yourself an admin so admin.html will let you in.
-- UPDATE users SET is_admin = 1 WHERE username = 'you@example.com';

-- ---------- SCHEMA CHANGE: display metadata for the Hub's tiles ----------
-- `apps` previously only needed app_key/name/is_public for the access-check
-- use case. The Hub also needs to render a tile per app, so this adds the
-- same fields personalapps.html's cards already carry. Run once — if you
-- run this file again later, comment these four ALTERs back out.

ALTER TABLE apps ADD COLUMN description      VARCHAR(255) NULL;
ALTER TABLE apps ADD COLUMN icon_emoji       VARCHAR(10)  NULL;
ALTER TABLE apps ADD COLUMN icon_color_class VARCHAR(20)  NULL;
ALTER TABLE apps ADD COLUMN launch_url       VARCHAR(255) NULL;
-- Whether the Hub should hand off the session token on launch (?token=...)
-- so the app can skip its own login screen. Only apps updated to accept
-- that handoff should have this set — see SETUP.md.
ALTER TABLE apps ADD COLUMN sso_enabled      TINYINT(1) NOT NULL DEFAULT 0;

-- ---------- SEED / UPDATE every current app ----------
-- Safe to run again later (e.g. after adding a new app's row by hand) —
-- ON DUPLICATE KEY UPDATE just refreshes the display info without touching
-- app_access grants. This also fills in display info for 'shed-inventory',
-- whose row already exists from that app's own schema.sql.

INSERT INTO apps (app_key, name, is_public, description, icon_emoji, icon_color_class, launch_url, sso_enabled) VALUES
  ('sojo-membership', 'SOJO Membership', 0,
   'For tracking membership in the SOJO Choral Arts Group. Designed for section leaders.',
   '🎵', 'icon-purple', 'https://dsshrek-ai.github.io/sojo-app/', 1),

  ('south-jordan-choral-arts', 'SoJo Choral Arts Seasons Chorale', 0,
   'The member site for SOJO Choral Arts Seasons Chorale Choir — rehearsal schedule, announcements, volunteer signups, and more.',
   '🎶', 'icon-blue', 'https://dsshrek-ai.github.io/SoJoMemberApp/', 0),

  ('choir-admin-panel', 'Choir Admin Panel', 0,
   'Maintenance panel for the South Jordan Choral Arts site — add, edit, and delete schedule entries, songs, announcements, and more. For the director and section leaders.',
   '🔐', 'icon-blue', 'https://dsshrek-ai.github.io/SoJoMemberApp/admin.html', 1),

  ('winco-staples', 'Winco Staples', 0,
   'A personal shopping list app for keeping staple grocery items stocked. Tracks quantities and estimated costs at a glance.',
   '🛒', 'icon-green', 'https://dsshrek-ai.github.io/winco-staples/', 0),

  ('mindgame', 'MindGame', 1,
   'A colorful deduction game in the spirit of Mastermind — but with its own unique twist on scoring. Includes a daily challenge mode.',
   '🎯', 'icon-orange', 'https://dsshrek-ai.github.io/mindgame/', 0),

  ('senior-family-cookbook', 'Senior Family Cookbook', 1,
   'A living repository of family recipes — open to everyone, related or not! Want to add yours? Just contact Dennis.',
   '📖', 'icon-rose', 'https://dsshrek-ai.github.io/senior-family-cookbook/', 1),

  ('digital-designs', 'Digital Designs', 1,
   'A logic gate puzzle game — flip switches to match the target output. Circuits grow more complex as you advance through the levels.',
   '⚡', 'icon-teal', 'https://dsshrek-ai.github.io/digital-designs/', 0),

  ('living-lean', 'Living Lean', 1,
   'A personal collection of low-carb, low-calorie recipes — built for the long haul, not just a diet. Install it on your home screen for quick access anytime.',
   '🥗', 'icon-teal', 'https://dsshrek-ai.github.io/senior-family-cookbook/living-lean/', 1),

  ('ico-vex', 'IcoVex', 1,
   'A tile-swapping puzzle game — arrange the board so every shared edge between tiles shows a matching icon. Multiple grid sizes and difficulty levels.',
   '🧩', 'icon-blue', 'https://dsshrek-ai.github.io/ico-vex/', 0),

  ('travel-scheduler', 'Travel Scheduler', 0,
   'Plan and organize travel itineraries — activities, locations, dates, and times all in one place. Backed by Google Sheets.',
   '✈️', 'icon-blue', 'https://dsshrek-ai.github.io/travel-scheduler/', 0),

  ('master-checklist', 'Master Checklist', 0,
   'A task and checklist manager — organized by named lists with location, date, time, and assignee fields, indentable into sub-items. Each login''s lists are private.',
   '✅', 'icon-orange', 'https://dsshrek-ai.github.io/master-checklist/', 0),

  ('pwi-weight-tracker', 'PWI Weight Tracker', 0,
   'Log daily weigh-ins for the family — plus gym, walk, and Zumba check-ins — and see how each person is tracking, with a color-coded readout and weekly activity charts.',
   '⚖️', 'icon-green', 'https://dsshrek-ai.github.io/OurWeightLoss/', 1),

  ('t-minus', 'T-Minus', 0,
   'A countdown tracker — log an event''s date and time and watch a live countdown tick down, with a progress bar showing how far along you are.',
   '🚀', 'icon-orange', 'https://dsshrek-ai.github.io/T-Minus/', 1),

  ('bingo-night', 'Bingo Night', 1,
   'A live, multiplayer Bingo game — pick a theme, get a random card, and mark squares as you notice them. No login, just type your name.',
   '🎬', 'icon-purple', 'https://dsshrek-ai.github.io/bingo-night/', 0),

  ('shed-inventory', 'Shed Inventory', 0,
   'Track what''s stored where across the sheds — shed, shelf, and level, right down to the bin. Shared only with household members you invite.',
   '🧰', 'icon-orange', 'https://dsshrek-ai.github.io/shed-inventory/', 1),

  ('admin-tool', 'Admin Tool', 0,
   'Manage who has access to which apps. Grant sparingly.',
   '🔑', 'icon-rose', 'https://seniorfamily.org/admin.html', 0)
ON DUPLICATE KEY UPDATE
  name             = VALUES(name),
  is_public        = VALUES(is_public),
  description      = VALUES(description),
  icon_emoji       = VALUES(icon_emoji),
  icon_color_class = VALUES(icon_color_class),
  launch_url       = VALUES(launch_url),
  sso_enabled      = VALUES(sso_enabled);

-- ---------- SCHEMA CHANGE: self-serve password reset ----------

CREATE TABLE IF NOT EXISTS password_resets (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: admin-sent invitations ----------
-- An invitation reserves a set of app grants for an email address that
-- hasn't signed up yet. invitation_apps lists which apps (beyond whatever's
-- already public) the invitee will be granted (can_edit = 1) the moment
-- they complete signup via the emailed link.

CREATE TABLE IF NOT EXISTS invitations (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(100) NOT NULL,
  token        CHAR(64) NOT NULL UNIQUE,
  invited_by   INT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at   TIMESTAMP NOT NULL,
  accepted_at  TIMESTAMP NULL,
  FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Superseded by the "pre-create the account at invite time" change further
-- below (invitations now carries user_id and app_access is the source of
-- truth for which apps an invitee gets) -- left in place harmlessly rather
-- than dropped.
CREATE TABLE IF NOT EXISTS invitation_apps (
  invitation_id  INT NOT NULL,
  app_id         INT NOT NULL,
  PRIMARY KEY (invitation_id, app_id),
  FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE,
  FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: pre-create the account at invite time ----------
-- password_hash is now nullable: NULL means "invited but hasn't set a
-- password yet" -- an account (and its app_access grants) that fully
-- exists before the person has done anything. invitations gets a user_id
-- so login can recognize "this account is pending activation" and find
-- the still-valid invitation to auto-continue into, instead of just
-- failing the login attempt outright.
-- Run once.

ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL;
ALTER TABLE invitations ADD COLUMN user_id INT NULL AFTER email;
ALTER TABLE invitations ADD CONSTRAINT fk_invitations_user
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ============================================================
-- BOOTSTRAP: after you've signed up through the Hub, run this once
-- (replace the email) to grant yourself every private app so you're not
-- locked out of your own apps on day one. Skip any app_key you don't want.
-- ============================================================
-- INSERT INTO app_access (user_id, app_id)
-- SELECT u.id, a.id FROM users u, apps a
-- WHERE u.username = 'you@example.com' AND a.is_public = 0;
