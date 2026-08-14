<?php
/**
 * Admin authentication gateway.
 *
 * All /admin/ and /admin/index.php requests are routed here by .htaccess so
 * the legacy dashboard can remain presentation-focused while authentication
 * records the exact admin account in the session for identity-aware actions.
 */

session_start();
error_reporting(0);
ini_set('display_errors', 0);

function gateway_env(): void
{
    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

gateway_env();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function gateway_db(): mysqli
{
    $db = @new mysqli(
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        $_ENV['DB_NAME'] ?? '',
        (int)($_ENV['DB_PORT'] ?? 3306)
    );
    if ($db->connect_error) {
        http_response_code(503);
        exit('Database unavailable.');
    }
    $db->set_charset('utf8mb4');
    return $db;
}

function gateway_login(string $error = ''): void
{
    $csrf = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
    $message = $error !== '' ? '<p style="color:#b94040;font-size:.85rem;margin-bottom:1rem;text-align:center;font-weight:600">'
        . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Admin Login — AgroBusiness</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f2eb;color:#3e3930;font-family:system-ui,-apple-system,sans-serif}.card{width:min(360px,calc(100% - 2rem));background:#fff;border:1px solid #e8e2d9;border-radius:16px;padding:2.5rem;box-shadow:0 8px 24px rgba(70,60,50,.12)}h1{margin:0 0 1.5rem;text-align:center;font:400 1.6rem Georgia,serif}label{display:block;font-size:.8rem;font-weight:700;color:#6b5f52;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem}input{width:100%;padding:.875rem 1rem;margin-bottom:1.15rem;border:1.5px solid #d5cfc4;border-radius:8px;background:#faf8f4;font:inherit}button{width:100%;padding:.875rem;border:0;border-radius:8px;background:#8B7355;color:#fff;font-weight:700;font-size:1rem;cursor:pointer}</style></head><body><main class="card">'
       . '<h1>🌾 Admin Login</h1>' . $message
       . '<form method="post" action="./"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
       . '<label>Username</label><input name="username" autocomplete="username" required>'
       . '<label>Password</label><input type="password" name="password" autocomplete="current-password" required>'
       . '<button type="submit">Login</button></form></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        gateway_login('Invalid credentials.');
    }

    $db = gateway_db();
    $db->query("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $db->query("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(100) NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $adminUser = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $fails = 0;
    if ($s = $db->prepare("SELECT COUNT(*) FROM admin_login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)")) {
        $s->bind_param('s', $clientIp);
        if ($s->execute()) { $s->bind_result($fails); $s->fetch(); }
        $s->close();
    }
    if ((int)$fails >= 5) {
        gateway_login('Too many failed attempts. Please try again in about 15 minutes.');
    }

    $stmt = $db->prepare("SELECT id, username, password_hash FROM admin_users WHERE username=? LIMIT 1");
    $admin = null;
    if ($stmt) {
        $stmt->bind_param('s', $adminUser);
        if ($stmt->execute()) {
            $stmt->bind_result($adminId, $adminName, $adminHash);
            if ($stmt->fetch()) {
                $admin = ['id' => (int)$adminId, 'username' => (string)$adminName, 'password_hash' => (string)$adminHash];
            }
        }
        $stmt->close();
    }

    $ok = $admin !== null && password_verify((string)$_POST['password'], $admin['password_hash']);
    $audit = $db->prepare("INSERT INTO admin_login_attempts (ip, username, success) VALUES (?, ?, ?)");
    if ($audit) {
        $flag = $ok ? 1 : 0;
        $audit->bind_param('ssi', $clientIp, $adminUser, $flag);
        $audit->execute();
        $audit->close();
    }

    if (!$ok) {
        gateway_login('Invalid credentials.');
    }

    // Authentication is the privilege boundary: rotate the session before
    // storing the authenticated identity to prevent session fixation.
    session_regenerate_id(false);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_login_at'] = time();
    $_SESSION['admin_login_ip'] = $clientIp;

    if ($clear = $db->prepare("DELETE FROM admin_login_attempts WHERE ip=? AND success=0")) {
        $clear->bind_param('s', $clientIp);
        $clear->execute();
        $clear->close();
    }

    header('Location: ./');
    exit;
}

// Normal GETs are rendered by the existing dashboard. The gateway only owns
// authentication; it deliberately does not duplicate the dashboard logic.
require dirname(__DIR__) . '/admin/index.php';
