<?php
// Public location catalogue for community price reporting.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

function fail_json(string $message, int $status = 500): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$db = @new mysqli(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? '',
    (int)($_ENV['DB_PORT'] ?? 3306)
);
if ($db->connect_error) fail_json('Database unavailable.');
$db->set_charset('utf8mb4');

$districts = [];
if ($r = $db->query("SELECT id, name FROM districts ORDER BY name")) {
    while ($row = $r->fetch_assoc()) $districts[] = ['id' => (int)$row['id'], 'name' => $row['name']];
}

$markets = [];
if ($r = $db->query("SELECT id, district_id, name FROM price_markets WHERE active=1 ORDER BY name")) {
    while ($row = $r->fetch_assoc()) $markets[] = ['id' => (int)$row['id'], 'district_id' => (int)$row['district_id'], 'name' => $row['name']];
}

$areas = [];
if ($r = $db->query("SELECT id, district_id, name, city_name FROM price_areas WHERE active=1 ORDER BY name")) {
    while ($row = $r->fetch_assoc()) $areas[] = ['id' => (int)$row['id'], 'district_id' => (int)$row['district_id'], 'name' => $row['name'], 'city_name' => $row['city_name']];
}

echo json_encode([
    'success' => true,
    'districts' => $districts,
    'markets' => $markets,
    'areas' => $areas
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
