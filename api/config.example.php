<?php
// Copy this file to config.php, fill in DB_PASS, and upload config.php via FTP/File Manager.
// config.php is gitignored — it should never be committed.

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// How long a login session stays valid.
define('SESSION_LIFETIME_DAYS', 30);

// Password-reset and invitation emails send through Brevo's HTTP API (see
// sendMail() in api.php) rather than the shared host's local mail() or any
// SMTP provider — shared hosts commonly redirect outbound SMTP to their
// own mail server regardless of destination (Turbify does; confirmed via a
// TLS certificate mismatch when trying to reach an external SMTP host), so
// SMTP-based sending isn't reliable from this kind of host. Brevo's API is
// plain HTTPS, unaffected by that.
//
// Sign up at brevo.com (free tier is far more than this app needs), verify
// FROM_EMAIL as a sender under Settings -> Senders, Domains & Dedicated
// IPs, then generate a key under Settings -> SMTP & API -> API Keys.
define('BREVO_API_KEY', 'PUT_YOUR_BREVO_API_KEY_HERE');
define('FROM_EMAIL', 'PUT_YOUR_VERIFIED_SENDER_EMAIL_HERE');

// Base URL of the deployed Hub page itself (not the API) — used to build
// the clickable links in reset/invitation emails.
define('SITE_URL', 'https://seniorfamily.org/my-apps-hub.html');
