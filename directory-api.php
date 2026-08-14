<?php
// Read-only directory API for the contact-first Sellers / Buyers directory.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

try {
    if ($host === '' || $user === '' || $name === '') throw new Exception('Database configuration is missing.');
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (!@$db->real_connect($host, $user, $pass, $name, $port)) {
        throw new Exception('Database connection failed.');
    }
    $db->set_charset('utf8mb4');

    $type = $_GET['type'] ?? '';
    if (!in_array($type, ['sellers', 'buyers'], true)) throw new Exception('Invalid directory type.');

    $districtId = (int)($_GET['district_id'] ?? 0);
    $crop = trim($_GET['crop'] ?? '');

    if ($type === 'sellers') {
        $sql = "SELECT s.id, s.name, s.district_id, d.name AS district_name,
                       scd.phone_number, scd.email, scd.address,
                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS crops_display,
                       ROUND(AVG(r.rating_value), 1) AS rating
                FROM sellers s
                JOIN districts d ON s.district_id = d.id
                JOIN seller_contact_details scd ON s.contact_id = scd.id
                LEFT JOIN seller_crops sc ON s.id = sc.seller_id
                LEFT JOIN crops c ON sc.crop_id = c.id
                LEFT JOIN ratings r ON s.id = r.seller_id
                WHERE 1=1";
        $types = '';
        $params = [];
        if ($districtId > 0) { $sql .= ' AND s.district_id = ?'; $types .= 'i'; $params[] = $districtId; }
        if ($crop !== '') {
            $sql .= " AND s.id IN (SELECT sc2.seller_id FROM seller_crops sc2 JOIN crops c2 ON sc2.crop_id = c2.id WHERE c2.name = ?)";
            $types .= 's'; $params[] = $crop;
        }
        $sql .= ' GROUP BY s.id ORDER BY s.name ASC';
    } else {
        $sql = "SELECT b.id, b.name, b.district_id, d.name AS district_name,
                       bcd.phone_number, bcd.email, bcd.address,
                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS crops_display
                FROM buyers b
                JOIN districts d ON b.district_id = d.id
                JOIN buyer_contact_details bcd ON b.contact_id = bcd.id
                LEFT JOIN buyer_crops bc ON b.id = bc.buyer_id
                LEFT JOIN crops c ON bc.crop_id = c.id
                WHERE 1=1";
        $types = '';
        $params = [];
        if ($districtId > 0) { $sql .= ' AND b.district_id = ?'; $types .= 'i'; $params[] = $districtId; }
        if ($crop !== '') {
            $sql .= " AND b.id IN (SELECT bc2.buyer_id FROM buyer_crops bc2 JOIN crops c2 ON bc2.crop_id = c2.id WHERE c2.name = ?)";
            $types .= 's'; $params[] = $crop;
        }
        $sql .= ' GROUP BY b.id ORDER BY b.name ASC';
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Directory query could not be prepared.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []]);
}
