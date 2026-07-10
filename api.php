<?php
// AgroBusiness Malawi - Complete API Endpoints
// Place this file at: /home/p601229/public_html/agrobusinessmw/api.php

// --- CRITICAL FIX 1: ERROR CATCHING & JSON HEADERS ---
// This ensures even a crash returns readable JSON
http_response_code(200); // Force OK so frontend can read the error message
register_shutdown_function(function () {
    $error = error_get_last();
    // Catch hard crashes (Fatal Errors)
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        if (ob_get_length()) ob_clean(); // Clear any partial HTML output
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal PHP Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit;
    }
});

// Disable HTML error printing (breaks JSON)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// ── Compatibility helpers: get_result() requires mysqlnd which may not be available ──
function stmt_fetch_all(mysqli_stmt $stmt): array
{
    $meta = $stmt->result_metadata();
    if (!$meta) return [];
    $fields = [];
    $row = [];
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $fields[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $fields);
    $rows = [];
    while ($stmt->fetch()) {
        $rows[] = array_map(fn($v) => $v, $row);
    }
    $meta->free();
    $stmt->free_result();
    return $rows;
}
function stmt_fetch_one(mysqli_stmt $stmt): ?array
{
    $rows = stmt_fetch_all($stmt);
    return $rows[0] ?? null;
}

// FEWS reference-rate helpers (fews_get_prices/fews_fetch_prices/fews_district_map/fews_match_district)
require_once __DIR__ . '/config/fews.php';

// ── Community price moderation helpers (Phase 1: statistical gate) ──────────
/** Median of a numeric list — outlier-resistant central value. */
function cp_median(array $vals): float
{
    $vals = array_values(array_filter(array_map('floatval', $vals), fn($v) => $v > 0));
    sort($vals);
    $n = count($vals);
    if ($n === 0) return 0.0;
    $mid = intdiv($n, 2);
    return $n % 2 ? (float)$vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
}

/**
 * Approved reference prices (per kg) used by the submission gate and the admin
 * queue. Prefers the crop+district scope; falls back to crop-wide when thin.
 * A 45-day window keeps the baseline current.
 */
function cp_reference_prices(mysqli $db, int $crop_id, ?int $district_id): array
{
    $out = [];
    if ($district_id) {
        $s = $db->prepare("SELECT price_per_kg FROM crowdsourced_prices WHERE status='approved' AND crop_id=? AND district_id=? AND created_at >= (NOW() - INTERVAL 45 DAY)");
        $s->bind_param('ii', $crop_id, $district_id);
        $s->execute();
        foreach (stmt_fetch_all($s) as $r) $out[] = (float)$r['price_per_kg'];
    }
    if (count($out) < 3) {
        $s = $db->prepare("SELECT price_per_kg FROM crowdsourced_prices WHERE status='approved' AND crop_id=? AND created_at >= (NOW() - INTERVAL 45 DAY)");
        $s->bind_param('i', $crop_id);
        $s->execute();
        $out = [];
        foreach (stmt_fetch_all($s) as $r) $out[] = (float)$r['price_per_kg'];
    }
    return $out;
}

/**
 * Send an HTML + plain-text multipart email via SMTPS (port 465 / implicit TLS).
 * Falls back to PHP mail() if the socket connection fails.
 *
 * @param string $to        Primary recipient address
 * @param string $subject   Email subject
 * @param string $htmlBody  Full HTML body
 * @param string $plainBody Plain-text fallback (auto-stripped from HTML if empty)
 * @param string $cc        Optional CC address (single address)
 * @return bool
 */
function send_smtp_email(string $to, string $subject, string $htmlBody, string $plainBody = '', string $cc = ''): bool
{
    $smtpHost = trim($_ENV['Outgoing Server'] ?? 'blue.webhostingireland.ie');
    $smtpPort = (int)trim($_ENV['SMTP Port']  ?? '465');
    $smtpUser = trim($_ENV['Username']        ?? '');
    $smtpPass = trim($_ENV['Password']        ?? '');
    $fromAddr = $smtpUser ?: 'noreply@agrobusinessmw.com';
    $fromName = 'AgroBusiness Malawi';

    if ($plainBody === '') {
        $plainBody = trim(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/p>/i'], "\n", $htmlBody)));
    }

    $boundary = 'agro_' . md5(uniqid('', true));
    $msgId    = '<' . uniqid('agro-') . '@agrobusinessmw.com>';

    $ccLine = $cc ? "Cc: {$cc}\r\n" : '';
    $rawMsg = "From: {$fromName} <{$fromAddr}>\r\n"
            . "To: {$to}\r\n"
            . $ccLine
            . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
            . "Message-ID: {$msgId}\r\n"
            . "Date: " . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $plainBody . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $htmlBody . "\r\n"
            . "--{$boundary}--";

    // Dot-stuffing: lone dots on a line must be doubled
    $rawMsg = preg_replace('/^\.$/m', '..', $rawMsg);

    $ctx = stream_context_create([
        'ssl' => [
            // Peer verification disabled for shared-hosting certs not in local CA bundle.
            // Connection is still encrypted.
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $socket = @stream_socket_client(
        "ssl://{$smtpHost}:{$smtpPort}",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$socket) {
        $headers = "From: {$fromName} <{$fromAddr}>\r\n"
                 . ($cc ? "Cc: {$cc}\r\n" : '')
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/html; charset=utf-8";
        return @mail($to, $subject, $htmlBody, $headers);
    }

    stream_set_timeout($socket, 10);

    $readResp = function () use ($socket): string {
        $last = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false || $line === '') break;
            $last = $line;
            if (strlen($line) >= 4 && $line[3] !== '-') break;
        }
        return $last;
    };
    $write = function (string $cmd) use ($socket): void { fwrite($socket, $cmd . "\r\n"); };

    $readResp(); // greeting
    $helo = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $write("EHLO {$helo}");
    $readResp();

    $write('AUTH LOGIN');
    $readResp();
    $write(base64_encode($smtpUser));
    $readResp();
    $write(base64_encode($smtpPass));
    $authResp = $readResp();

    if (substr($authResp, 0, 3) !== '235') {
        $write('QUIT');
        fclose($socket);
        return false;
    }

    $write("MAIL FROM:<{$fromAddr}>");
    $readResp();
    $write("RCPT TO:<{$to}>");
    $readResp();
    if ($cc) {
        $write("RCPT TO:<{$cc}>");
        $readResp();
    }
    $write('DATA');
    $readResp();

    fwrite($socket, $rawMsg . "\r\n.\r\n");
    $dataResp = $readResp();

    $write('QUIT');
    $readResp();
    fclose($socket);

    return substr($dataResp, 0, 3) === '250';
}

/**
 * Wrap content in the branded HTML email shell.
 */
function email_html(string $bodyContent): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f5f2eb;font-family:Arial,Helvetica,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f2eb;">'
        . '<tr><td align="center" style="padding:40px 16px;">'
        . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
        // Header
        . '<tr><td style="background:#16a34a;padding:28px 36px;">'
        . '<p style="margin:0;font-size:11px;color:#bbf7d0;letter-spacing:0.12em;text-transform:uppercase;">AgroBusiness Malawi</p>'
        . '<h1 style="margin:4px 0 0;color:#ffffff;font-size:22px;font-weight:700;line-height:1.3;">Agricultural Platform</h1>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:36px;">'
        . $bodyContent
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:#f5f2eb;padding:20px 36px;border-top:1px solid #e5e0d8;">'
        . '<p style="margin:0;font-size:12px;color:#8B7355;text-align:center;">AgroBusiness Malawi &bull; Empowering Malawian Farmers<br>'
        . '<a href="https://agrobusinessmw.com" style="color:#16a34a;text-decoration:none;">agrobusinessmw.com</a></p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Reusable info row for detail tables in admin notification emails. */
function email_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="padding:8px 12px;font-size:13px;color:#6b7280;width:130px;border-bottom:1px solid #f0ece4;">' . htmlspecialchars($label) . '</td>'
        . '<td style="padding:8px 12px;font-size:13px;color:#1f2937;font-weight:600;border-bottom:1px solid #f0ece4;">' . htmlspecialchars($value) . '</td>'
        . '</tr>';
}

// Set JSON header & CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- LOAD .ENV CREDENTIALS ---
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host     = $_ENV['DB_HOST']     ?? '';
$username = $_ENV['DB_USER']     ?? '';
$password = $_ENV['DB_PASS']     ?? '';
$database = $_ENV['DB_NAME']     ?? '';
$port     = (int)($_ENV['DB_PORT'] ?? 3306);

// Connect to database
try {
    if ($host === '' || $username === '' || $database === '') {
        throw new Exception('Database credentials are missing from .env.');
    }
    if (!class_exists('mysqli')) {
        throw new Exception("Critical Error: MySQLi extension is not loaded in php.ini.");
    }

    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10); // 10s Timeout

    // Suppress warnings to handle errors manually
    if (!@$mysqli->real_connect($host, $username, $password, $database, $port)) {
        throw new Exception("Connect Failed: " . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');
} catch (Exception $e) {
    ob_clean();
    // Return 200 OK with error details so the App can display it
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'hint' => "Check database credentials in .env.",
        'environment' => $_SERVER['HTTP_HOST'] ?? 'unknown',
        'timestamp' => date('c')
    ]);
    exit;
}

/**
 * Admin credentials live in the `admin_users` table, not hardcoded in source.
 * On first run the table is created and seeded once from .env (if present),
 * so existing deployments migrate without a manual step.
 */
function admin_get_user(mysqli $mysqli): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );

    $result = $mysqli->query("SELECT username, password_hash FROM admin_users LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    if (!$row) {
        $seedUser  = $_ENV['ADMIN_USER'] ?? 'admin';
        $seedPass  = $_ENV['ADMIN_PASSWORD'] ?? bin2hex(random_bytes(8));
        $hash = password_hash($seedPass, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare(
            "INSERT INTO admin_users (username, password_hash) VALUES (?, ?)"
        );
        $stmt->bind_param('ss', $seedUser, $hash);
        $stmt->execute();
        $row = ['username' => $seedUser, 'password_hash' => $hash];
    }

    return $cached = $row;
}

// Get action parameter
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'test':
            // Test database connection
            $result = $mysqli->query("SELECT COUNT(*) as count FROM districts");
            if ($result) {
                $row = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'message' => 'Database connection successful',
                    'districts_count' => $row['count'],
                    'environment' => $_SERVER['HTTP_HOST'] ?? 'unknown',
                    'timestamp' => date('c')
                ]);
            } else {
                throw new Exception('Test query failed');
            }
            break;

        case 'districts':
            // Get all districts
            $query = "SELECT id, name FROM districts ORDER BY name ASC";
            $result = $mysqli->query($query);

            if (!$result) {
                throw new Exception('Districts query failed: ' . $mysqli->error);
            }

            $districts = [];
            while ($row = $result->fetch_assoc()) {
                $districts[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $districts,
                'count' => count($districts),
                'timestamp' => date('c')
            ]);
            break;

        case 'crops':
            // Get all crops
            $query = "SELECT id, name FROM crops ORDER BY name ASC";
            $result = $mysqli->query($query);

            if (!$result) {
                throw new Exception('Crops query failed: ' . $mysqli->error);
            }

            $crops = [];
            while ($row = $result->fetch_assoc()) {
                $crops[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $crops,
                'count' => count($crops),
                'timestamp' => date('c')
            ]);
            break;

        case 'crop_prices':
            // Get crop prices
            $query = "
                SELECT
                    c.id,
                    c.name,
                    COALESCE(cp.min_price, '') AS min_price,
                    COALESCE(cp.market_price, '') AS market_price,
                    COALESCE(cp.unit, 'kg') AS unit
                FROM crops c
                LEFT JOIN crop_prices cp ON c.id = cp.crop_id
                ORDER BY c.name ASC
            ";

            $result = $mysqli->query($query);

            if (!$result) {
                throw new Exception('Crop prices query failed: ' . $mysqli->error);
            }

            $crops = [];
            while ($row = $result->fetch_assoc()) {
                $crops[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $crops,
                'count' => count($crops),
                'timestamp' => date('c')
            ]);
            break;

        case 'market_insights':
            // Get market insights for a district
            $district_id = (int)($_GET['district_id'] ?? 0);

            if (!$district_id) {
                throw new Exception('District ID is required');
            }

            $query = "
                SELECT
                    mi.id,
                    mi.district_id,
                    d.name as district_name,
                    mi.insight_en,
                    mi.insight_ci
                FROM market_insights mi
                JOIN districts d ON mi.district_id = d.id
                WHERE mi.district_id = ?
                ORDER BY mi.id DESC
            ";

            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $district_id);
            $stmt->execute();
            $insights = stmt_fetch_all($stmt);

            echo json_encode([
                'success' => true,
                'data' => $insights,
                'count' => count($insights),
                'timestamp' => date('c')
            ]);
            break;

        case 'sellers':
            $district_id = (int)($_GET['district_id'] ?? 0);
            $crop        = trim($_GET['crop'] ?? '');

            if (!$district_id) {
                throw new Exception('District ID is required');
            }

            $query = "
                SELECT s.id, s.name, s.district_id, d.name as district_name,
                       scd.phone_number, scd.email, scd.address,
                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') as crops_display,
                       ROUND(AVG(r.rating_value), 1) as rating
                FROM sellers s
                JOIN districts d ON s.district_id = d.id
                JOIN seller_contact_details scd ON s.contact_id = scd.id
                LEFT JOIN seller_crops sc ON s.id = sc.seller_id
                LEFT JOIN crops c ON sc.crop_id = c.id
                LEFT JOIN ratings r ON s.id = r.seller_id
                WHERE s.district_id = ?";

            if ($crop !== '') {
                $query .= " AND s.id IN (
                    SELECT sc2.seller_id FROM seller_crops sc2
                    JOIN crops c2 ON sc2.crop_id = c2.id WHERE c2.name = ?)";
            }

            $query .= " GROUP BY s.id ORDER BY ROUND(AVG(r.rating_value), 1) DESC, s.name ASC";

            $stmt = $mysqli->prepare($query);
            if ($crop !== '') {
                $stmt->bind_param('is', $district_id, $crop);
            } else {
                $stmt->bind_param('i', $district_id);
            }
            $stmt->execute();
            $sellers = stmt_fetch_all($stmt);

            echo json_encode(['success' => true, 'data' => $sellers, 'count' => count($sellers), 'timestamp' => date('c')]);
            break;

        case 'buyers':
            $district_id = (int)($_GET['district_id'] ?? 0);
            $crop        = trim($_GET['crop'] ?? '');

            if (!$district_id) {
                throw new Exception('District ID is required');
            }

            $query = "
                SELECT b.id, b.name, b.district_id, d.name as district_name,
                       bcd.phone_number, bcd.email, bcd.address,
                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') as crops_display
                FROM buyers b
                JOIN districts d ON b.district_id = d.id
                JOIN buyer_contact_details bcd ON b.contact_id = bcd.id
                LEFT JOIN buyer_crops bc ON b.id = bc.buyer_id
                LEFT JOIN crops c ON bc.crop_id = c.id
                WHERE b.district_id = ?";

            if ($crop !== '') {
                $query .= " AND b.id IN (
                    SELECT bc2.buyer_id FROM buyer_crops bc2
                    JOIN crops c2 ON bc2.crop_id = c2.id WHERE c2.name = ?)";
            }

            $query .= " GROUP BY b.id ORDER BY b.name ASC";

            $stmt = $mysqli->prepare($query);
            if ($crop !== '') {
                $stmt->bind_param('is', $district_id, $crop);
            } else {
                $stmt->bind_param('i', $district_id);
            }
            $stmt->execute();
            $buyers = stmt_fetch_all($stmt);

            echo json_encode(['success' => true, 'data' => $buyers, 'count' => count($buyers), 'timestamp' => date('c')]);
            break;

        case 'pest_control':
            // Get pest control tips
            $crop_id = (int)($_GET['crop_id'] ?? 0);
            $district_id = (int)($_GET['district_id'] ?? 0);

            if (!$crop_id || !$district_id) {
                throw new Exception('Crop ID and District ID are required');
            }

            $query = "
                SELECT
                    pct.id,
                    pct.crop_id,
                    pct.district_id,
                    c.name as crop_name,
                    d.name as district_name,
                    pct.tip_en,
                    pct.tip_ci
                FROM pest_control_tips pct
                JOIN crops c ON pct.crop_id = c.id
                JOIN districts d ON pct.district_id = d.id
                WHERE pct.crop_id = ? AND pct.district_id = ?
                ORDER BY pct.id ASC
            ";

            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('ii', $crop_id, $district_id);
            $stmt->execute();
            $tips = stmt_fetch_all($stmt);

            echo json_encode([
                'success' => true,
                'data' => $tips,
                'count' => count($tips),
                'timestamp' => date('c')
            ]);
            break;

        case 'farming_tips':
            // Get farming tips for a crop
            $crop_id = (int)($_GET['crop_id'] ?? 0);

            if (!$crop_id) {
                throw new Exception('Crop ID is required');
            }

            $query = "
                SELECT
                    fbp.id,
                    fbp.crop_id,
                    c.name as crop_name,
                    fbp.practice_type,
                    fbp.practice_en,
                    fbp.practice_ci
                FROM farming_best_practices fbp
                JOIN crops c ON fbp.crop_id = c.id
                WHERE fbp.crop_id = ?
                ORDER BY fbp.practice_type ASC
            ";

            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $crop_id);
            $stmt->execute();
            $practices = stmt_fetch_all($stmt);

            echo json_encode([
                'success' => true,
                'data' => $practices,
                'count' => count($practices),
                'timestamp' => date('c')
            ]);
            break;

        case 'basic_info':
            // Get basic farming information
            $query = "
                SELECT
                    id,
                    topic,
                    info_en,
                    info_ci
                FROM basic_farming_info
                ORDER BY id ASC
            ";

            $result = $mysqli->query($query);

            if (!$result) {
                throw new Exception('Basic info query failed: ' . $mysqli->error);
            }

            $info = [];
            while ($row = $result->fetch_assoc()) {
                $info[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $info,
                'count' => count($info),
                'timestamp' => date('c')
            ]);
            break;

        // ── ONBOARDING: Submit application ──────────────────────────
        case 'submit_application':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }

            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $userType  = trim($body['user_type']  ?? '');
            $fullName  = trim($body['full_name']   ?? '');
            $phone     = trim($body['phone_number'] ?? '');
            $email     = trim($body['email']        ?? '');
            $nationalId = trim($body['national_id'] ?? '');
            $districtId = (int)($body['district_id'] ?? 0) ?: null;
            $village   = trim($body['village']      ?? '');
            $crops     = trim($body['crops_of_interest'] ?? '');
            $business  = trim($body['business_name'] ?? '');
            $channel   = in_array($body['channel'] ?? '', ['web', 'ussd']) ? $body['channel'] : 'web';

            if (!in_array($userType, ['farmer', 'seller', 'buyer'])) {
                throw new Exception('Invalid user type');
            }
            if (strlen($fullName) < 2) {
                throw new Exception('Full name is required');
            }
            if (!preg_match('/^\+?[0-9\s\-]{8,20}$/', $phone)) {
                throw new Exception('Valid phone number is required');
            }
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Valid email is required');
            }
            if (!$districtId) {
                throw new Exception('District is required');
            }
            if (!$village || strlen($village) < 2) {
                throw new Exception('Village / town is required');
            }
            if (in_array($userType, ['seller', 'buyer']) && $business === '') {
                throw new Exception('Business name is required for sellers and buyers');
            }

            $districtStmt = $mysqli->prepare("SELECT id FROM districts WHERE id = ?");
            $districtStmt->bind_param('i', $districtId);
            $districtStmt->execute();
            if (!stmt_fetch_one($districtStmt)) {
                throw new Exception('Selected district is invalid');
            }

            if ($userType === 'farmer') {
                $business = null;
            }

            // Duplicate check — one application per person (phone, email, or national ID)
            $dupSql = "SELECT application_ref, status, user_type
                       FROM onboarding_applications
                       WHERE phone_number = ?
                          OR (? <> '' AND email <> '' AND email = ?)
                          OR (? <> '' AND national_id <> '' AND national_id = ?)
                       LIMIT 1";
            $dupStmt = $mysqli->prepare($dupSql);
            $dupStmt->bind_param('sssss', $phone, $email, $email, $nationalId, $nationalId);
            $dupStmt->execute();
            $dup = stmt_fetch_one($dupStmt);
            if ($dup) {
                $dupStatus = ucfirst($dup['status']);
                $dupType   = ucfirst($dup['user_type']);
                throw new Exception(
                    "An application for this phone number, email or National ID already exists. " .
                    "Reference: {$dup['application_ref']} ({$dupType} — {$dupStatus}). " .
                    "Use the 'Already applied? Check status' button to track your application."
                );
            }

            // Generate unique reference
            $ref = 'AGR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

            $stmt = $mysqli->prepare(
                "INSERT INTO onboarding_applications
                 (application_ref, user_type, full_name, phone_number, email, national_id,
                  district_id, village, crops_of_interest, business_name, channel)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'ssssssissss',
                $ref,
                $userType,
                $fullName,
                $phone,
                $email,
                $nationalId,
                $districtId,
                $village,
                $crops,
                $business,
                $channel
            );
            $stmt->execute();

            $adminEmail = trim($_ENV['Username'] ?? 'info@promanaged-it.com');
            $adminCc    = 'johnpaulchirwa@gmail.com';
            $roleLabel  = ucfirst($userType);

            // Confirmation email to applicant
            if ($email) {
                $html = email_html(
                    '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">Application Received!</h2>'
                    . '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Dear <strong>' . htmlspecialchars($fullName) . '</strong>,</p>'
                    . '<p style="margin:0 0 20px;font-size:15px;color:#374151;">Thank you for registering with AgroBusiness Malawi. Your application has been received and is currently under review.</p>'
                    . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;">'
                    . '<tbody>'
                    . email_row('Reference', $ref)
                    . email_row('Role', $roleLabel)
                    . email_row('District', $village)
                    . '</tbody></table>'
                    . '<p style="margin:0 0 24px;font-size:15px;color:#374151;">We will review your application and notify you within <strong>2–3 business days</strong>. You can also check your status anytime using your reference number.</p>'
                    . '<a href="https://agrobusinessmw.com/?ref=' . urlencode($ref) . '" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;font-size:15px;">Check Application Status</a>'
                    . '<p style="margin:28px 0 0;font-size:13px;color:#6b7280;">If you have any questions, contact us at <a href="mailto:info@agrobusinessmw.com" style="color:#16a34a;">info@agrobusinessmw.com</a></p>'
                );
                send_smtp_email($email, "Application Received — {$ref}", $html);
            }

            // Admin notification
            $districtStmt2 = $mysqli->prepare("SELECT name FROM districts WHERE id=?");
            $districtStmt2->bind_param('i', $districtId);
            $districtStmt2->execute();
            $districtRow = stmt_fetch_one($districtStmt2);
            $districtName = $districtRow['name'] ?? 'Unknown';

            $adminHtml = email_html(
                '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">New Application Submitted</h2>'
                . '<p style="margin:0 0 20px;font-size:15px;color:#374151;">A new registration application has been submitted and is awaiting your review.</p>'
                . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;">'
                . '<tbody>'
                . email_row('Reference', $ref)
                . email_row('Full Name', $fullName)
                . email_row('Role', $roleLabel)
                . email_row('Phone', $phone)
                . email_row('Email', $email ?: '—')
                . email_row('District', $districtName)
                . email_row('Village', $village)
                . email_row('Business', $business ?: '—')
                . email_row('Crops', $crops ?: '—')
                . email_row('Channel', strtoupper($channel))
                . email_row('Submitted', date('d M Y, H:i'))
                . '</tbody></table>'
                . '<a href="https://agrobusinessmw.com/?admin" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;font-size:15px;">Review in Admin Panel</a>'
            );
            send_smtp_email($adminEmail, "New Application: {$ref} — {$roleLabel}", $adminHtml, '', $adminCc);

            echo json_encode([
                'success'   => true,
                'message'   => 'Application submitted successfully',
                'ref'       => $ref,
                'timestamp' => date('c')
            ]);
            break;

        // ── ONBOARDING: Real-time duplicate check ───────────────────
        // Lets the registration form warn the user BEFORE they finish, so nobody
        // creates several accounts. phone/email/national_id are hard duplicates;
        // full_name is a soft match (names are not unique) returned as a warning.
        case 'check_duplicate':
            $phone      = trim($_GET['phone'] ?? '');
            $email      = trim($_GET['email'] ?? '');
            $nationalId = trim($_GET['national_id'] ?? '');
            $fullName   = trim($_GET['full_name'] ?? '');
            $matches    = [];

            $lookup = function ($sql, $val, $field, $hard) use ($mysqli, &$matches) {
                $st = $mysqli->prepare($sql);
                $st->bind_param('s', $val);
                $st->execute();
                $row = stmt_fetch_one($st);
                if ($row) {
                    $matches[] = [
                        'field'  => $field,
                        'hard'   => $hard,
                        'ref'    => $row['application_ref'],
                        'status' => $row['status'],
                        'type'   => $row['user_type'],
                    ];
                }
            };

            if ($phone !== '') {
                $lookup("SELECT application_ref, status, user_type FROM onboarding_applications WHERE phone_number = ? LIMIT 1", $phone, 'phone', true);
            }
            if ($email !== '') {
                $lookup("SELECT application_ref, status, user_type FROM onboarding_applications WHERE email <> '' AND email = ? LIMIT 1", $email, 'email', true);
            }
            if ($nationalId !== '') {
                $lookup("SELECT application_ref, status, user_type FROM onboarding_applications WHERE national_id <> '' AND national_id = ? LIMIT 1", $nationalId, 'national_id', true);
            }
            if ($fullName !== '') {
                $lookup("SELECT application_ref, status, user_type FROM onboarding_applications WHERE full_name = ? LIMIT 1", $fullName, 'name', false);
            }

            echo json_encode([
                'success' => true,
                'exists'  => count($matches) > 0,
                'matches' => $matches,
            ]);
            break;

        // ── ONBOARDING: Check application status ────────────────────
        case 'check_application':
            $ref  = strtoupper(trim($_GET['ref'] ?? ''));
            if (!$ref) throw new Exception('Reference number is required');

            $stmt = $mysqli->prepare(
                "SELECT a.application_ref, a.user_type, a.full_name, a.status,
                        a.denial_reason, a.created_at, a.reviewed_at,
                        d.name as district_name
                 FROM onboarding_applications a
                 LEFT JOIN districts d ON a.district_id = d.id
                 WHERE a.application_ref = ?"
            );
            $stmt->bind_param('s', $ref);
            $stmt->execute();
            $row = stmt_fetch_one($stmt);

            if (!$row) throw new Exception('Application not found');

            echo json_encode(['success' => true, 'data' => $row, 'timestamp' => date('c')]);
            break;

        // ─── PRICE DATA ─────────────────────────────────────────────────────────

        case 'dual_crop_prices':
            $crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;

            $fews_cache = ['data' => [], 'source_url' => null, 'fetched_at' => null, 'error' => null];
            try {
                $fews_cache = fews_get_prices($mysqli);
            } catch (Throwable $fe) {
                $fews_cache['error'] = $fe->getMessage();
            }
            $fews = $fews_cache['data'] ?? [];
            if ($crop_id) {
                $fews = array_values(array_filter($fews, function ($r) use ($crop_id) {
                    return (int)$r['crop_id'] === $crop_id;
                }));
            }

            // Admin-set reference overrides take precedence over the upstream rate
            // and fill gaps where the source has no data for a crop/district.
            $fews = cp_apply_overrides($mysqli, $fews, $crop_id);

            // Community prices: only APPROVED reports from the last 45 days count.
            // The headline value is the MEDIAN (outlier-resistant); a group with
            // 3+ reports is "confirmed". Aggregation is done in PHP so we can use a
            // true median rather than the average.
            $community = [];
            $community_error = null;
            try {
                $sql = "SELECT cp.crop_id, c.name AS crop_name, cp.district_id, d.name AS district_name,
                               cp.market_name, cp.price_per_kg, cp.price_per_bag, cp.unit, cp.created_at
                        FROM crowdsourced_prices cp
                        JOIN crops c ON cp.crop_id = c.id
                        LEFT JOIN districts d ON cp.district_id = d.id
                        WHERE cp.status = 'approved' AND cp.created_at >= (NOW() - INTERVAL 45 DAY)";
                if ($crop_id) {
                    $stmt2 = $mysqli->prepare($sql . " AND cp.crop_id = ?");
                    if (!$stmt2) throw new Exception($mysqli->error);
                    $stmt2->bind_param('i', $crop_id);
                } else {
                    $stmt2 = $mysqli->prepare($sql);
                    if (!$stmt2) throw new Exception($mysqli->error);
                }
                $stmt2->execute();
                $raw = stmt_fetch_all($stmt2);

                $groups = [];
                foreach ($raw as $r) {
                    $key = $r['crop_id'] . '|' . ($r['district_id'] ?? '0') . '|' . ($r['market_name'] ?? '');
                    if (!isset($groups[$key])) $groups[$key] = ['meta' => $r, 'kg' => [], 'bag' => [], 'ts' => []];
                    $groups[$key]['kg'][]  = (float)$r['price_per_kg'];
                    $groups[$key]['bag'][] = (float)$r['price_per_bag'];
                    $groups[$key]['ts'][]  = $r['created_at'];
                }
                foreach ($groups as $g) {
                    $count = count($g['kg']);
                    $community[] = [
                        'crop_id'       => (int)$g['meta']['crop_id'],
                        'crop_name'     => $g['meta']['crop_name'],
                        'district_id'   => $g['meta']['district_id'] !== null ? (int)$g['meta']['district_id'] : null,
                        'district_name' => $g['meta']['district_name'],
                        'market_name'   => $g['meta']['market_name'],
                        'avg_price'     => round(cp_median($g['kg'])),   // headline = median of approved
                        'avg_price_bag' => round(cp_median($g['bag'])),
                        'min_price'     => round(min($g['kg'])),
                        'min_price_bag' => round(min($g['bag'])),
                        'max_price'     => round(max($g['kg'])),
                        'max_price_bag' => round(max($g['bag'])),
                        'report_count'  => $count,
                        'confirmed'     => $count >= 3,
                        'last_reported' => max($g['ts']),
                        'unit'          => $g['meta']['unit'] ?? 'kg',
                    ];
                }
                // Confirmed first, then most reports, then most recent.
                usort($community, fn($a, $b) => ((int)$b['confirmed'] <=> (int)$a['confirmed'])
                    ?: ($b['report_count'] <=> $a['report_count'])
                    ?: strcmp((string)$b['last_reported'], (string)$a['last_reported']));
            } catch (Throwable $ce) {
                $community_error = $ce->getMessage();
            }

            echo json_encode([
                'success'          => true,
                'fews'             => $fews,
                'community'        => $community,
                'fews_count'       => count($fews),
                'community_count'  => count($community),
                'fews_source'      => $fews_cache['source_url'] ?? null,
                'fews_cached_at'   => $fews_cache['fetched_at'] ?? null,
                'fews_error'       => $fews_cache['error'] ?? null,
                'community_error'  => $community_error,
            ]);
            break;

        case 'markets':
            // Markets/locations for a district (each district can have many).
            $district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
            if (!$district_id) throw new Exception('district_id is required.');
            $stmt = $mysqli->prepare("SELECT id, name FROM markets WHERE district_id = ? ORDER BY name");
            $stmt->bind_param('i', $district_id);
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => stmt_fetch_all($stmt), 'timestamp' => date('c')]);
            break;

        case 'submit_price':
            // Farmer submits a crowdsourced price
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $crop_id    = (int)($body['crop_id']    ?? 0);
            $district_id = isset($body['district_id']) ? (int)$body['district_id'] : null;
            $unit       = preg_replace('/[^a-zA-Z\/]/', '', $body['unit'] ?? 'kg');
            $market     = mb_substr(trim($body['market_name'] ?? ''), 0, 200);
            $phone      = mb_substr(trim($body['phone'] ?? 'anonymous'), 0, 50);
            $email      = mb_substr(trim($body['email'] ?? ''), 0, 200);
            $channel    = in_array($body['channel'] ?? 'web', ['web', 'ussd']) ? ($body['channel'] ?? 'web') : 'web';

            // Accept a price per kg OR per 50kg bag — whichever the reporter entered.
            $BAG_KG   = 50;
            $price    = (float)($body['price_per_kg']  ?? 0);   // canonical: per kg
            $bagInput = (float)($body['price_per_bag'] ?? 0);
            if ($price <= 0 && $bagInput > 0) $price = round($bagInput / $BAG_KG, 2);

            if (!$crop_id) {
                throw new Exception('crop_id is required.');
            }
            if ($price <= 0) {
                throw new Exception('Enter a price per kg or per bag.');
            }
            if ($price > 100000) {
                throw new Exception('Price seems too high. Please check the amount in MWK.');
            }
            // Web reports require full context so the price is useful and reviewable.
            if ($channel === 'web') {
                if (!$district_id)                                throw new Exception('District is required.');
                if (mb_strlen($market) < 2)                       throw new Exception('Market / location is required.');
                if (!preg_match('/^\+?[0-9\s\-]{8,20}$/', $phone)) throw new Exception('A valid phone number is required.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL))   throw new Exception('A valid email is required.');
            }

            // Match submitter to an approved member by the trailing phone digits
            // (tolerates +265 / 0 / spacing differences). Members can be auto-approved.
            $is_member = 0;
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) >= 8) {
                $tail = '%' . substr($digits, -9);
                $ms = $mysqli->prepare("SELECT id FROM onboarding_applications WHERE status='approved' AND REPLACE(REPLACE(REPLACE(phone_number,' ',''),'-',''),'+','') LIKE ? LIMIT 1");
                $ms->bind_param('s', $tail);
                $ms->execute();
                if (stmt_fetch_one($ms)) $is_member = 1;
            }

            // Statistical gate: compare against the median of approved reference prices.
            $refs   = cp_reference_prices($mysqli, $crop_id, $district_id);
            $status = 'pending';   // default: held for review (non-member or cold start)
            $flag   = null;
            if (count($refs) >= 3) {
                $median = cp_median($refs);
                $inBand = $median > 0 && $price >= $median * 0.4 && $price <= $median * 2.5;
                if ($inBand) {
                    $status = $is_member ? 'approved' : 'pending';
                } else {
                    $status = $is_member ? 'flagged' : 'pending';
                    $flag   = 'Outside reference band (~' . round($median) . ' MWK/kg)';
                }
            }

            // Keep the reporter's actual bag figure when given; otherwise derive it.
            $price_per_bag = $bagInput > 0 ? round($bagInput, 2) : round($price * $BAG_KG, 2);
            $emailVal = $email !== '' ? $email : null;

            // Link to a market for this district (find-or-create), so each district
            // accumulates its own list of markets/locations.
            $market_id = null;
            if ($district_id && $market !== '') {
                $mk = $mysqli->prepare("INSERT IGNORE INTO markets (district_id, name) VALUES (?, ?)");
                $mk->bind_param('is', $district_id, $market);
                $mk->execute();
                $sel = $mysqli->prepare("SELECT id FROM markets WHERE district_id = ? AND name = ? LIMIT 1");
                $sel->bind_param('is', $district_id, $market);
                $sel->execute();
                $mrow = stmt_fetch_one($sel);
                $market_id = $mrow ? (int)$mrow['id'] : null;
            }

            $stmt = $mysqli->prepare(
                "INSERT INTO crowdsourced_prices
                 (crop_id, district_id, price_per_kg, price_per_bag, unit, market_name, market_id, submitted_by, email, channel, status, is_member, flag_reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('iiddssissssis', $crop_id, $district_id, $price, $price_per_bag, $unit, $market, $market_id, $phone, $emailVal, $channel, $status, $is_member, $flag);
            $stmt->execute();

            $msg = $status === 'approved'
                ? 'Price confirmed and published. Thank you for helping fellow farmers!'
                : ($status === 'flagged'
                    ? 'Thank you! Your price looks unusual, so our team will check it before it shows.'
                    : 'Thank you! Your price has been received and will appear once reviewed.');
            echo json_encode([
                'success'   => true,
                'status'    => $status,
                'is_member' => (bool)$is_member,
                'message'   => $msg,
                'id'        => $mysqli->insert_id,
            ]);
            break;

        default:
            throw new Exception('Invalid action specified. Available actions: test, districts, crops, crop_prices, dual_crop_prices, markets, submit_price, market_insights, sellers, buyers, pest_control, farming_tips, basic_info, submit_application, check_application');
    }
} catch (Throwable $e) {
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success'   => false,
        'error'     => $e->getMessage(),
        'action'    => $action ?? '',
        'timestamp' => date('c')
    ]);
}

// ─── ADMIN REFERENCE-PRICE OVERRIDES ────────────────────────────────────────
// Admin-set prices in `price_overrides` override the upstream reference rate for
// a crop (district_id = 0 → all districts, else a specific district) and inject a
// synthetic reference row where the source has no data for that crop/district.
function cp_apply_overrides(mysqli $db, array $fews, ?int $crop_id): array
{
    // Table is created lazily by the admin panel; tolerate its absence.
    $chk = $db->query("SHOW TABLES LIKE 'price_overrides'");
    if (!$chk || $chk->num_rows === 0) return $fews;

    $sql = "SELECT o.crop_id, o.district_id, o.price_per_kg, o.note,
                   c.name AS crop_name, d.name AS district_name
            FROM price_overrides o
            JOIN crops c ON c.id = o.crop_id
            LEFT JOIN districts d ON d.id = o.district_id";
    if ($crop_id) $sql .= " WHERE o.crop_id = " . (int)$crop_id;
    $res = $db->query($sql);
    if (!$res || $res->num_rows === 0) return $fews;

    $specific = [];   // [crop_id][district_id] = override row
    $national = [];    // [crop_id] = override row (district_id 0)
    while ($o = $res->fetch_assoc()) {
        $cid = (int)$o['crop_id'];
        $did = (int)$o['district_id'];
        if ($did === 0) $national[$cid] = $o;
        else $specific[$cid][$did] = $o;
    }

    // Apply to existing reference rows; track which overrides matched something.
    $usedSpecific = [];
    $usedNational = [];
    foreach ($fews as &$row) {
        $cid = (int)$row['crop_id'];
        $did = (int)($row['district_id'] ?? 0);
        if (isset($specific[$cid][$did])) {
            $row['price'] = (float)$specific[$cid][$did]['price_per_kg'];
            $row['overridden'] = true;
            $usedSpecific[$cid . '|' . $did] = true;
        } elseif (isset($national[$cid])) {
            $row['price'] = (float)$national[$cid]['price_per_kg'];
            $row['overridden'] = true;
            $usedNational[$cid] = true;
        }
    }
    unset($row);

    // Inject synthetic reference rows for overrides that matched no source row.
    foreach ($specific as $cid => $byDistrict) {
        foreach ($byDistrict as $did => $o) {
            if (isset($usedSpecific[$cid . '|' . $did])) continue;
            $fews[] = [
                'crop_id'       => $cid,
                'crop_name'     => $o['crop_name'],
                'district_id'   => $did,
                'district_name' => $o['district_name'] ?? '',
                'market_name'   => 'Admin reference',
                'price'         => (float)$o['price_per_kg'],
                'price_type'    => 'Reference',
                'overridden'    => true,
            ];
        }
    }
    foreach ($national as $cid => $o) {
        if (isset($usedNational[$cid])) continue;
        $fews[] = [
            'crop_id'       => $cid,
            'crop_name'     => $o['crop_name'],
            'district_id'   => 0,
            'district_name' => '',
            'market_name'   => 'Admin reference',
            'price'         => (float)$o['price_per_kg'],
            'price_type'    => 'Reference',
            'overridden'    => true,
        ];
    }

    return $fews;
}


// Close database connection
if (isset($mysqli)) {
    $mysqli->close();
}
