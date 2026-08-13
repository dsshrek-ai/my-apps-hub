<?php
// Copy this file to config.php, fill in DB_PASS, and upload config.php via FTP/File Manager.
// config.php is gitignored — it should never be committed.

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// How long a login session stays valid.
define('SESSION_LIFETIME_DAYS', 30);

// From: address for password-reset and invitation emails. Use an address at
// your own domain — shared hosting mail() is more likely to get flagged as
// spam if the From: domain doesn't match the sending server.
define('FROM_EMAIL', 'noreply@seniorfamily.org');

// Base URL of the deployed Hub page itself (not the API) — used to build
// the clickable links in reset/invitation emails.
define('SITE_URL', 'https://seniorfamily.org/my-apps-hub.html');
