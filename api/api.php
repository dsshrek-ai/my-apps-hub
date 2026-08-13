<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// This is a JSON API — never let a raw PHP warning/error/exception (e.g. a
// missing table, bad DB credentials) leak out as HTML and break the client's
// JSON parsing. Log the real message server-side, but always respond JSON.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
  error_log('my-apps-hub api.php: ' . $e->getMessage());
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['success' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// Best-effort — a failed send here should never surface as an API error the
// caller has to handle (there's nothing they could do about it anyway).
function sendMail(string $to, string $subject, string $body): void {
  $headers = 'From: ' . FROM_EMAIL . "\r\n";
  @mail($to, $subject, $body, $headers);
}

// ---- Auth helpers ----

// Like requireUser, but returns null instead of failing when there's no (or
// an invalid) bearer token — used by listApps, which must still work for
// anonymous visitors browsing the public apps.
function optionalUser(): ?array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    return null;
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $result ?: null;
}

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name, u.is_admin
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$result) {
    fail('Session expired or invalid — please log in again', 401);
  }
  return $result;
}

// Used by every admin.html action — a normal login isn't enough, the
// account also needs users.is_admin = 1 (set by hand in the database; see
// SETUP.md).
function requireAdmin(): array {
  $user = requireUser();
  if (!(bool)($user['is_admin'] ?? false)) {
    fail('Not authorized', 403);
  }
  return $user;
}

// ---- Router ----

$action = $_GET['action'] ?? '';

switch ($action) {

  // Handles two cases with one action: a brand-new self-serve signup
  // (INSERT), or activating an account an admin pre-created at invite time
  // (UPDATE — that row already has its app_access grants, just password_hash
  // IS NULL). Distinguishing them is just "does a row for this email
  // already exist with no password set yet."
  case 'signup': {
    $body = jsonBody();
    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $displayName = trim((string)($body['displayName'] ?? ''));
    $inviteToken = trim((string)($body['inviteToken'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      fail('A valid email address is required');
    }
    if (strlen($password) < 8) {
      fail('Password must be at least 8 characters');
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && $existing['password_hash'] !== null) {
      fail('An account with that email already exists — log in instead', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
      $userId = (int)$existing['id'];
      $dn = $displayName !== '' ? $displayName : null;
      $upd = db()->prepare('UPDATE users SET password_hash = ?, display_name = ? WHERE id = ?');
      $upd->bind_param('ssi', $hash, $dn, $userId);
      $upd->execute();
      $upd->close();
    } else {
      $ins = db()->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?, ?, ?)');
      $ins->bind_param('sss', $email, $hash, $displayName);
      $ins->execute();
      $userId = $ins->insert_id;
      $ins->close();
    }

    // Mark whichever invitation this came from as accepted — by token if
    // one was passed (arrived via the emailed link), otherwise any
    // still-pending invitation for this account (arrived by retyping the
    // invited email into "Create an account" instead of using the link).
    if ($inviteToken !== '') {
      $mark = db()->prepare(
        'UPDATE invitations SET accepted_at = NOW() WHERE token = ? AND user_id = ? AND accepted_at IS NULL'
      );
      $mark->bind_param('si', $inviteToken, $userId);
      $mark->execute();
      $mark->close();
    } else {
      $mark = db()->prepare('UPDATE invitations SET accepted_at = NOW() WHERE user_id = ? AND accepted_at IS NULL');
      $mark->bind_param('i', $userId);
      $mark->execute();
      $mark->close();
    }

    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $sess = db()->prepare(
      'INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $sess->bind_param('sii', $token, $userId, $days);
    $sess->execute();
    $sess->close();

    respond(['token' => $token, 'displayName' => $displayName ?: $email]);
  }

  case 'login': {
    $body = jsonBody();
    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($email === '' || $password === '') {
      fail('Email and password are required');
    }

    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // An account pre-created at invite time has no password yet. Rather
    // than just failing here, look for the still-valid invitation and hand
    // the client its token so it can jump straight into activation instead
    // of a dead-end "invalid password."
    if ($user && $user['password_hash'] === null) {
      $inv = db()->prepare(
        'SELECT token FROM invitations WHERE user_id = ? AND expires_at > NOW() AND accepted_at IS NULL
         ORDER BY id DESC LIMIT 1'
      );
      $inv->bind_param('i', $user['id']);
      $inv->execute();
      $invitation = $inv->get_result()->fetch_assoc();
      $inv->close();

      if ($invitation) {
        respond(['needsActivation' => true, 'inviteToken' => $invitation['token']]);
      }
      fail('This account hasn\'t set a password yet, and its invitation has expired — ask to be re-invited.', 401);
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
      fail('Invalid email or password', 401);
    }

    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare(
      'INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();

    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $body = jsonBody();
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['success' => true]);
  }

  // Always responds success, whether or not the email exists — otherwise
  // this endpoint would let anyone check which emails have accounts here.
  case 'requestPasswordReset': {
    $body = jsonBody();
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '') {
      fail('Email is required');
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
      $token = bin2hex(random_bytes(32));
      $ins = db()->prepare(
        'INSERT INTO password_resets (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
      );
      $ins->bind_param('si', $token, $user['id']);
      $ins->execute();
      $ins->close();

      $link = SITE_URL . (strpos(SITE_URL, '?') !== false ? '&' : '?') . 'resetToken=' . $token;
      sendMail(
        $email,
        'Reset your My Apps Hub password',
        "We received a request to reset your My Apps Hub password.\n\n" .
        "Click the link below to choose a new password (this link expires in 1 hour):\n$link\n\n" .
        "If you didn't request this, you can safely ignore this email — your password won't change unless you click the link above."
      );
    }

    respond(['success' => true]);
  }

  case 'resetPassword': {
    $body = jsonBody();
    $token = trim((string)($body['token'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($token === '') {
      fail('Reset link is missing its token');
    }
    if (strlen($password) < 8) {
      fail('Password must be at least 8 characters');
    }

    $stmt = db()->prepare(
      'SELECT pr.user_id, u.username FROM password_resets pr
       JOIN users u ON u.id = pr.user_id
       WHERE pr.token = ? AND pr.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reset) {
      fail('This reset link is invalid or has expired — request a new one', 400);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $upd->bind_param('si', $hash, $reset['user_id']);
    $upd->execute();
    $upd->close();

    // Used tokens shouldn't work twice, and a password reset is a
    // reasonable moment to sign every device back out.
    $del = db()->prepare('DELETE FROM password_resets WHERE user_id = ?');
    $del->bind_param('i', $reset['user_id']);
    $del->execute();
    $del->close();

    $delSessions = db()->prepare('DELETE FROM sessions WHERE user_id = ?');
    $delSessions->bind_param('i', $reset['user_id']);
    $delSessions->execute();
    $delSessions->close();

    respond(['success' => true, 'username' => $reset['username']]);
  }

  // Public (the token itself is the credential) — lets the signup page show
  // who invited this person and which apps before they've created an account.
  case 'getInvitation': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
      fail('token is required');
    }

    $stmt = db()->prepare(
      'SELECT i.user_id, i.email, u.display_name AS invited_by_name
       FROM invitations i
       LEFT JOIN users u ON u.id = i.invited_by
       WHERE i.token = ? AND i.expires_at > NOW() AND i.accepted_at IS NULL'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $invitation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invitation) {
      fail('This invitation is invalid or has expired', 404);
    }

    // app_access (not invitation_apps) is the source of truth now — the
    // grants exist for real as soon as the invitation is sent, so this
    // shows whatever the account is actually currently authorized for.
    $apps = db()->prepare(
      'SELECT a.name, a.icon_emoji FROM app_access aa
       JOIN apps a ON a.id = aa.app_id WHERE aa.user_id = ? ORDER BY a.name'
    );
    $apps->bind_param('i', $invitation['user_id']);
    $apps->execute();
    $appRows = $apps->get_result()->fetch_all(MYSQLI_ASSOC);
    $apps->close();

    respond([
      'email' => $invitation['email'],
      'invitedByName' => $invitation['invited_by_name'],
      'apps' => array_map(fn($r) => ['name' => $r['name'], 'iconEmoji' => $r['icon_emoji']], $appRows),
    ]);
  }

  // Every app the current visitor is allowed to see: all public apps, plus
  // (if logged in) any private app they've been explicitly granted. Works
  // for anonymous visitors too — they just get the public list.
  case 'listApps': {
    $user = optionalUser();

    if ($user) {
      $stmt = db()->prepare(
        'SELECT DISTINCT a.app_key, a.name, a.description, a.icon_emoji, a.icon_color_class,
                a.launch_url, a.is_public, a.sso_enabled
         FROM apps a
         LEFT JOIN app_access aa ON aa.app_id = a.id AND aa.user_id = ?
         WHERE a.is_public = 1 OR aa.user_id IS NOT NULL
         ORDER BY a.name'
      );
      $stmt->bind_param('i', $user['id']);
    } else {
      $stmt = db()->prepare(
        'SELECT app_key, name, description, icon_emoji, icon_color_class, launch_url, is_public, sso_enabled
         FROM apps WHERE is_public = 1 ORDER BY name'
      );
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    respond([
      'loggedIn' => $user !== null,
      'displayName' => $user['display_name'] ?? null,
      'apps' => array_map(fn($r) => [
        'appKey' => $r['app_key'],
        'name' => $r['name'],
        'description' => $r['description'],
        'iconEmoji' => $r['icon_emoji'],
        'iconColorClass' => $r['icon_color_class'],
        'launchUrl' => $r['launch_url'],
        'isPublic' => (bool)$r['is_public'],
        'ssoEnabled' => (bool)$r['sso_enabled'],
      ], $rows),
    ]);
  }

  // ---------- Admin (admin.html) ----------

  case 'adminLookupUser': {
    requireAdmin();
    $email = trim((string)($_GET['email'] ?? ''));
    if ($email === '') {
      fail('email is required');
    }

    $stmt = db()->prepare('SELECT id, display_name, password_hash FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
      respond(['found' => false]);
    }
    respond([
      'found' => true,
      'userId' => (int)$user['id'],
      'displayName' => $user['display_name'],
      'activated' => $user['password_hash'] !== null,
    ]);
  }

  // Every app in the system, with no per-user grant info — used to build
  // the checkbox grid for inviting someone who doesn't have an account yet.
  case 'adminAppList': {
    requireAdmin();
    $rows = db()->query('SELECT id AS app_id, app_key, name, icon_emoji, is_public FROM apps ORDER BY name')
      ->fetch_all(MYSQLI_ASSOC);
    respond(['apps' => array_map(fn($r) => [
      'appId' => (int)$r['app_id'],
      'appKey' => $r['app_key'],
      'name' => $r['name'],
      'iconEmoji' => $r['icon_emoji'],
      'isPublic' => (bool)$r['is_public'],
    ], $rows)]);
  }

  // Body: { email, appIds: [...] }. The account and its app_access grants
  // are created for real right now, not deferred until the invitee signs
  // up — password_hash stays NULL until they do (see the `login`/`signup`
  // handling of that). Sending to an email that's already fully activated
  // (has a password) is refused; sending to one that's already invited but
  // not yet activated re-syncs its grants to whatever's checked now and
  // resends the email with a fresh token/expiry — this one action doubles
  // as "resend."
  case 'adminSendInvitation': {
    $admin = requireAdmin();
    $body = jsonBody();
    $email = trim((string)($body['email'] ?? ''));
    $appIds = is_array($body['appIds'] ?? null) ? array_map('intval', $body['appIds']) : [];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      fail('A valid email address is required');
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && $existing['password_hash'] !== null) {
      fail('An account with that email already exists — grant access directly instead', 409);
    }

    if ($existing) {
      $userId = (int)$existing['id'];
    } else {
      $ins = db()->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?, NULL, NULL)');
      $ins->bind_param('s', $email);
      $ins->execute();
      $userId = $ins->insert_id;
      $ins->close();
    }

    // Sync app_access to exactly what's checked now — same insert/delete
    // diff adminSaveAccess does, so this also works as a plain "adjust this
    // pending invite's apps" action even without resending the email.
    $allApps = db()->query('SELECT id FROM apps')->fetch_all(MYSQLI_ASSOC);
    $checked = array_flip($appIds);
    $insertGrant = db()->prepare(
      'INSERT INTO app_access (user_id, app_id, can_edit) VALUES (?, ?, 1)
       ON DUPLICATE KEY UPDATE can_edit = 1'
    );
    $deleteGrant = db()->prepare('DELETE FROM app_access WHERE user_id = ? AND app_id = ?');
    foreach ($allApps as $a) {
      $appId = (int)$a['id'];
      if (isset($checked[$appId])) {
        $insertGrant->bind_param('ii', $userId, $appId);
        $insertGrant->execute();
      } else {
        $deleteGrant->bind_param('ii', $userId, $appId);
        $deleteGrant->execute();
      }
    }
    $insertGrant->close();
    $deleteGrant->close();

    // Refresh the pending invitation's token/expiry, or create one.
    $token = bin2hex(random_bytes(32));
    $pending = db()->prepare('SELECT id FROM invitations WHERE user_id = ? AND accepted_at IS NULL');
    $pending->bind_param('i', $userId);
    $pending->execute();
    $pendingInvite = $pending->get_result()->fetch_assoc();
    $pending->close();

    if ($pendingInvite) {
      $upd = db()->prepare(
        'UPDATE invitations SET token = ?, invited_by = ?, expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id = ?'
      );
      $upd->bind_param('sii', $token, $admin['id'], $pendingInvite['id']);
      $upd->execute();
      $upd->close();
    } else {
      $ins2 = db()->prepare(
        'INSERT INTO invitations (email, user_id, token, invited_by, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))'
      );
      $ins2->bind_param('sisi', $email, $userId, $token, $admin['id']);
      $ins2->execute();
      $ins2->close();
    }

    // List whatever's actually granted now (app_access is the source of
    // truth), not just what was passed in.
    $nameStmt = db()->prepare(
      'SELECT a.name FROM app_access aa JOIN apps a ON a.id = aa.app_id WHERE aa.user_id = ? ORDER BY a.name'
    );
    $nameStmt->bind_param('i', $userId);
    $nameStmt->execute();
    $appNames = array_map(fn($r) => $r['name'], $nameStmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $nameStmt->close();

    $link = SITE_URL . (strpos(SITE_URL, '?') !== false ? '&' : '?') . 'invite=' . $token;
    $appsList = $appNames ? implode(', ', $appNames) : '(no extra apps — just the public ones)';
    sendMail(
      $email,
      "You're invited to My Apps Hub",
      "You've been invited to join My Apps Hub at SeniorFamily.org by " . ($admin['display_name'] ?: 'Dennis Senior') . ".\n\n" .
      "Click the link below to create your login:\n$link\n\n" .
      "Apps you've been invited to: $appsList\n\n" .
      "If you weren't expecting this, you can safely ignore this email."
    );

    respond(['success' => true, 'userId' => $userId]);
  }

  // Every app in the system, with whether this specific user currently has
  // a grant (and can_edit) for each.
  case 'adminGetUserAccess': {
    requireAdmin();
    $userId = (int)($_GET['userId'] ?? 0);
    if ($userId <= 0) {
      fail('userId is required');
    }

    $stmt = db()->prepare(
      'SELECT a.id AS app_id, a.app_key, a.name, a.icon_emoji, a.is_public,
              aa.user_id IS NOT NULL AS has_grant, IFNULL(aa.can_edit, 0) AS can_edit
       FROM apps a
       LEFT JOIN app_access aa ON aa.app_id = a.id AND aa.user_id = ?
       ORDER BY a.name'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    respond(['apps' => array_map(fn($r) => [
      'appId' => (int)$r['app_id'],
      'appKey' => $r['app_key'],
      'name' => $r['name'],
      'iconEmoji' => $r['icon_emoji'],
      'isPublic' => (bool)$r['is_public'],
      'granted' => (bool)$r['has_grant'],
    ], $rows)]);
  }

  // Body: { userId, grants: [{ appId, granted }, ...] } — one entry per app
  // in the system, reflecting the current state of every checkbox. Diffs
  // against what's already in app_access: inserts newly-checked apps
  // (can_edit = 1), deletes newly-unchecked ones, leaves everything else
  // alone (so an existing grant's granted_at/can_edit isn't disturbed just
  // because the box was already checked).
  case 'adminSaveAccess': {
    requireAdmin();
    $body = jsonBody();
    $userId = (int)($body['userId'] ?? 0);
    $grants = is_array($body['grants'] ?? null) ? $body['grants'] : [];
    if ($userId <= 0) {
      fail('userId is required');
    }

    $insert = db()->prepare(
      'INSERT INTO app_access (user_id, app_id, can_edit) VALUES (?, ?, 1)
       ON DUPLICATE KEY UPDATE user_id = user_id'
    );
    $delete = db()->prepare('DELETE FROM app_access WHERE user_id = ? AND app_id = ?');

    foreach ($grants as $g) {
      $appId = (int)($g['appId'] ?? 0);
      $granted = (bool)($g['granted'] ?? false);
      if ($appId <= 0) {
        continue;
      }
      if ($granted) {
        $insert->bind_param('ii', $userId, $appId);
        $insert->execute();
      } else {
        $delete->bind_param('ii', $userId, $appId);
        $delete->execute();
      }
    }
    $insert->close();
    $delete->close();

    respond(['success' => true]);
  }

  default:
    fail('Unknown action', 404);
}
