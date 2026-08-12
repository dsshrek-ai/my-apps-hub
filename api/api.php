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

  case 'signup': {
    $body = jsonBody();
    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $displayName = trim((string)($body['displayName'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      fail('A valid email address is required');
    }
    if (strlen($password) < 8) {
      fail('Password must be at least 8 characters');
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($exists) {
      fail('An account with that email already exists — log in instead', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = db()->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?, ?, ?)');
    $ins->bind_param('sss', $email, $hash, $displayName);
    $ins->execute();
    $userId = $ins->insert_id;
    $ins->close();

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

    $stmt = db()->prepare('SELECT id, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
      respond(['found' => false]);
    }
    respond(['found' => true, 'userId' => (int)$user['id'], 'displayName' => $user['display_name']]);
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
