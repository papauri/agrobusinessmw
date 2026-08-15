<?php
/**
 * AgroBusiness Malawi — registration contact finalization.
 *
 * The existing submit_application endpoint remains responsible for creating
 * the application and sending its normal notifications. This endpoint is a
 * narrow second step that validates and stores canonical contact numbers after
 * the application reference has been created.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

function fail(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function canonical_phone(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = preg_replace('/[^0-9+]/', '', $value);
    $value = preg_replace('/^\++/', '+', $value);
    if ($value === '') return null;

    // International form supplied directly.
    if ($value[0] === '+') {
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) ? $value : null;
    }

    // Malawi country code without '+'.
    if (str_starts_with($value, '265') && strlen($value) >= 10) {
        $international = '+' . $value;
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $international) ? $international : null;
    }

    // Malawi local mobile: 0888123456 / 0971234567.
    if (preg_match('/^0[0-9]{9}$/', $value)) {
        $international = '+265' . substr($value, 1);
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $international) ? $international : null;
    }

    // Malawi local mobile without the leading zero.
    if (preg_match('/^[89][0-9]{8}$/', $value)) {
        $international = '+265' . $value;
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $international) ? $international : null;
    }

    return null;
}

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host = $_ENV['DB_HOST'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$port = (int)($_ENV['DB_PORT'] ?? 3306);

if (!$host || !$user || !$name) fail('Database configuration is missing.', 500);

$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
if (!@$db->real_connect($host, $user, $pass, $name, $port)) {
    fail('Database connection failed.', 500);
}
$db->set_charset('utf8mb4');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$ref = strtoupper(trim((string)($body['application_ref'] ?? '')));
$phone = canonical_phone($body['phone_number'] ?? null);
$whatsapp = canonical_phone($body['whatsapp_number'] ?? null);

if (!preg_match('/^AGR-[0-9]{8}-[A-Z0-9]{5}$/', $ref)) {
    fail('Invalid application reference.');
}
if ($phone === null) {
    fail('A valid international phone number is required.');
}
if (($body['whatsapp_number'] ?? '') !== '' && $whatsapp === null) {
    fail('WhatsApp number is invalid.');
}

// Verify the reference belongs to the supplied canonical phone before allowing
// contact details to be changed. This prevents an arbitrary reference from being
// used to alter another application.
$check = $db->prepare('SELECT id FROM onboarding_applications WHERE application_ref = ? AND phone_number = ? LIMIT 1');
if (!$check) fail('Could not validate application.', 500);
$check->bind_param('ss', $ref, $phone);
$check->execute();
$result = $check->get_result();
if (!$result || !$result->fetch_assoc()) {
    fail('Application and phone number do not match.');
}

$update = $db->prepare('UPDATE onboarding_applications SET phone_number = ?, whatsapp_number = ? WHERE application_ref = ?');
if (!$update) fail('Could not prepare contact update.', 500);
$update->bind_param('sss', $phone, $whatsapp, $ref);
if (!$update->execute()) {
    fail('Could not save contact details.', 500);
}

echo json_encode([
    'success' => true,
    'phone_number' => $phone,
    'whatsapp_number' => $whatsapp,
]);
