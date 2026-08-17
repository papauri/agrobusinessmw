<?php
// === USSD Configuration ===

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ussd_errors.log');

// Load credentials from .env — same source as the web app
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

function get_db_connection($max_retries = 2) {
    $host = $_ENV['DB_HOST'] ?? '';
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';
    $db   = $_ENV['DB_NAME'] ?? '';
    // DB_PORT was read from .env by config/database.php and ignored here, so
    // this connector could only ever reach MySQL on 3306. On a host that moves
    // the port, the web app worked and the USSD channel was simply dead.
    $port = (int)($_ENV['DB_PORT'] ?? 3306) ?: 3306;

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        // PHP 8.1+ makes mysqli throw by default (mysqli_report(MYSQLI_REPORT_
        // ERROR|MYSQLI_REPORT_STRICT)). The constructor therefore raises before
        // `connect_error` is ever read, which made the retry loop and the
        // graceful "END System error" below unreachable: the gateway got an
        // uncaught exception and an HTTP 500 instead of a valid USSD reply, and
        // the caller's session broke rather than ending politely.
        try {
            $mysqli = new mysqli($host, $user, $pass, $db, $port);
            if (!$mysqli->connect_error) {
                $mysqli->set_charset('utf8mb4');
                return $mysqli;
            }
            $why = $mysqli->connect_error;
        } catch (mysqli_sql_exception $e) {
            $why = $e->getMessage();
        }
        error_log("USSD DB connection failed (attempt $attempt): " . $why);
        if ($attempt < $max_retries) sleep(1);
    }

    error_log("USSD: all DB connection attempts failed");
    // Always a valid USSD reply. The gateway shows the caller whatever it gets;
    // an error page is not something a feature phone can render.
    echo "END System error. Please try again later.";
    ob_end_flush();
    exit;
}

$mysqli = get_db_connection();

// District coordinates for weather (keys match district DB IDs)
$district_coords = [
    '1'  => ['lat' => -13.9833, 'lon' => 33.7833, 'name' => 'Lilongwe'],
    '2'  => ['lat' => -15.7861, 'lon' => 35.0058, 'name' => 'Blantyre'],
    '3'  => ['lat' => -11.4581, 'lon' => 34.0156, 'name' => 'Mzuzu'],
    '4'  => ['lat' => -13.7986, 'lon' => 33.6856, 'name' => 'Mchinji'],
    '5'  => ['lat' => -13.3744, 'lon' => 34.0033, 'name' => 'Ntchisi'],
    '6'  => ['lat' => -14.3833, 'lon' => 34.3333, 'name' => 'Dedza'],
    '7'  => ['lat' => -13.0333, 'lon' => 33.4833, 'name' => 'Kasungu'],
    '8'  => ['lat' => -11.6000, 'lon' => 34.3000, 'name' => 'Nkhata Bay'],
    '9'  => ['lat' => -10.9833, 'lon' => 34.0167, 'name' => 'Rumphi'],
    '10' => ['lat' => -9.9333,  'lon' => 33.9333, 'name' => 'Karonga'],
    '11' => ['lat' => -16.0667, 'lon' => 35.1333, 'name' => 'Thyolo'],
    '12' => ['lat' => -9.7167,  'lon' => 33.2667, 'name' => 'Chitipa'],
    '13' => ['lat' => -14.4833, 'lon' => 35.2667, 'name' => 'Mangochi'],
    '14' => ['lat' => -16.9167, 'lon' => 35.2667, 'name' => 'Chikwawa'],
    '15' => ['lat' => -15.6833, 'lon' => 34.9667, 'name' => 'Zomba'],
    '16' => ['lat' => -12.9167, 'lon' => 34.3000, 'name' => 'Nkhotakota'],
    '17' => ['lat' => -14.8167, 'lon' => 35.6500, 'name' => 'Ntcheu'],
    '18' => ['lat' => -14.8167, 'lon' => 35.6500, 'name' => 'Balaka'],
    '19' => ['lat' => -15.3833, 'lon' => 35.3333, 'name' => 'Mulanje'],
    '20' => ['lat' => -14.4667, 'lon' => 35.3167, 'name' => 'Machinga'],
    '21' => ['lat' => -15.3000, 'lon' => 34.9167, 'name' => 'Phalombe'],
    '22' => ['lat' => -13.6333, 'lon' => 32.6333, 'name' => 'Dowa'],
    '23' => ['lat' => -12.1333, 'lon' => 34.0167, 'name' => 'Likoma'],
    '24' => ['lat' => -14.0000, 'lon' => 33.7833, 'name' => 'Salima'],
];
?>
