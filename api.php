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
        // The detail goes to the server log, NOT to the browser. This response
        // used to carry the PHP message, the absolute file path and the line
        // number, which handed any visitor a map of the server's filesystem.
        error_log(sprintf(
            'api.php fatal: %s in %s:%d',
            $error['message'],
            $error['file'],
            $error['line']
        ));
        echo json_encode([
            'success' => false,
            'error'   => 'The service hit an unexpected error. Please try again shortly.',
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

/**
 * Split a concatenated crop list into a clean, de-duplicated, sorted array.
 *
 * Two callers with two separators: the seller/buyer directories GROUP_CONCAT
 * with a newline (a crop name can contain a comma one day; it will never
 * contain a newline), and the farmer directory reads the ", "-joined text
 * register.php wrote into onboarding_applications.crops_of_interest.
 */
function agro_split_crops(?string $value, string $separator): array
{
    if ($value === null || $value === '') return [];
    $parts = array_map('trim', explode($separator, $value));
    $parts = array_values(array_unique(array_filter($parts, static fn($p) => $p !== '')));
    sort($parts, SORT_NATURAL | SORT_FLAG_CASE);
    return $parts;
}

/**
 * Escape the LIKE metacharacters in a value that is going into a LIKE pattern.
 *
 * Binding a parameter stops it changing the SQL, but it does not stop it
 * changing the *pattern*: `crop=%` bound into `LIKE CONCAT('%, ', ?, ', %')`
 * matches every row. Backslash first, or it re-escapes its own output.
 */
function agro_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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

// Shared branded-email helpers (send_smtp_email/email_html/email_row).
require_once __DIR__ . '/config/mailer.php';

// Canonical phone normalisation, shared with register.php and the browser.
require_once __DIR__ . '/config/phone.php';

// Set JSON header & CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- LOAD .ENV CREDENTIALS ---
// agro_load_env() is the single copy of this loader (config/database.php). The
// version that used to live here indexed $line[0] without trimming, so a line of
// pure whitespace raised an "uninitialized string offset" notice.
require_once __DIR__ . '/config/database.php';
agro_load_env();

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
        // mysqli_connect_error() names the database user and host on an
        // access-denied ("Access denied for user 'x'@'y'"). Log it, do not serve it.
        error_log('api.php database connection failed: ' . mysqli_connect_error());
        throw new Exception('The service is temporarily unavailable. Please try again shortly.');
    }

    $mysqli->set_charset('utf8mb4');
} catch (Exception $e) {
    ob_clean();
    // Still HTTP 200 with success:false — the frontend reads the body, not the
    // status. The message is deliberately generic; the detail is in the log.
    http_response_code(200);
    echo json_encode([
        'success'   => false,
        'error'     => $e->getMessage(),
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
            // district_id is OPTIONAL. Omit it and every district's insights come
            // back in one response.
            //
            // The Market Insights page used to call this action once per district
            // — 28 requests on page load and again on every refinement. On a 2G
            // connection in rural Malawi that is the difference between a page
            // that loads and one that does not. The page is information-first, so
            // it needs the whole set anyway.
            $district_id = (int)($_GET['district_id'] ?? 0);

            $query = "SELECT mi.id, mi.district_id, d.name AS district_name,
                             mi.insight_en, mi.insight_ci
                      FROM market_insights mi
                      JOIN districts d ON mi.district_id = d.id";

            if ($district_id > 0) {
                $query .= " WHERE mi.district_id = ?";
            }
            $query .= " ORDER BY d.name ASC, mi.id DESC";

            $stmt = $mysqli->prepare($query);
            if (!$stmt) throw new Exception('Market insights query could not be prepared.');
            if ($district_id > 0) $stmt->bind_param('i', $district_id);
            if (!$stmt->execute()) throw new Exception('Market insights query failed.');
            $insights = stmt_fetch_all($stmt);

            echo json_encode([
                'success' => true,
                'data' => $insights,
                'count' => count($insights),
                'timestamp' => date('c')
            ]);
            break;

        // ── DIRECTORY: sellers and buyers ───────────────────────────────
        // Contact-first. district_id and crop are OPTIONAL refinements, not
        // required entry steps: with neither, the whole national directory comes
        // back and the page filters it in the browser.
        //
        // This replaced a separate directory-api.php that ran a near-identical
        // query with its own database bootstrap. One query, one place.
        //
        // The contact-details join is a LEFT JOIN on purpose. An inner join
        // silently hid every seller whose contact row was missing, which looked
        // exactly like "there are no sellers in your district".
        case 'sellers':
        case 'buyers':
            $isSellers   = $action === 'sellers';
            $district_id = (int)($_GET['district_id'] ?? 0);
            $crop        = trim($_GET['crop'] ?? '');

            // The two branches spell their SQL out in full rather than
            // interpolating table names. Slightly longer, impossible to misread.
            if ($isSellers) {
                $query = "SELECT s.id, s.name, s.district_id, d.name AS district_name,
                                 scd.phone_number, scd.whatsapp_number, scd.email, scd.address,
                                 GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '\n') AS crops_concat
                          FROM sellers s
                          JOIN districts d ON s.district_id = d.id
                          LEFT JOIN seller_contact_details scd ON s.contact_id = scd.id
                          LEFT JOIN seller_crops sc ON s.id = sc.seller_id
                          LEFT JOIN crops c ON sc.crop_id = c.id
                          WHERE 1 = 1";
            } else {
                $query = "SELECT b.id, b.name, b.district_id, d.name AS district_name,
                                 bcd.phone_number, bcd.whatsapp_number, bcd.email, bcd.address,
                                 GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '\n') AS crops_concat
                          FROM buyers b
                          JOIN districts d ON b.district_id = d.id
                          LEFT JOIN buyer_contact_details bcd ON b.contact_id = bcd.id
                          LEFT JOIN buyer_crops bc ON b.id = bc.buyer_id
                          LEFT JOIN crops c ON bc.crop_id = c.id
                          WHERE 1 = 1";
            }

            $types  = '';
            $params = [];
            if ($district_id > 0) {
                $query .= $isSellers ? " AND s.district_id = ?" : " AND b.district_id = ?";
                $types .= 'i';
                $params[] = $district_id;
            }
            if ($crop !== '') {
                $query .= $isSellers
                    ? " AND s.id IN (SELECT sc2.seller_id FROM seller_crops sc2 JOIN crops c2 ON sc2.crop_id = c2.id WHERE c2.name = ?)"
                    : " AND b.id IN (SELECT bc2.buyer_id FROM buyer_crops bc2 JOIN crops c2 ON bc2.crop_id = c2.id WHERE c2.name = ?)";
                $types .= 's';
                $params[] = $crop;
            }
            // Every selected non-aggregate column is grouped, so this is correct
            // under ONLY_FULL_GROUP_BY as well as without it.
            $query .= $isSellers
                ? " GROUP BY s.id, s.name, s.district_id, d.name, scd.phone_number, scd.whatsapp_number, scd.email, scd.address ORDER BY s.name ASC"
                : " GROUP BY b.id, b.name, b.district_id, d.name, bcd.phone_number, bcd.whatsapp_number, bcd.email, bcd.address ORDER BY b.name ASC";

            $stmt = $mysqli->prepare($query);
            if (!$stmt) throw new Exception('Directory query could not be prepared.');
            if ($types !== '') $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new Exception('Directory query failed.');
            $rows = stmt_fetch_all($stmt);

            // Each row carries its crops twice: `crops` for code and
            // `crops_display` for people.
            //
            // The GROUP_CONCAT separator above is a newline, not ", ". That is
            // deliberate. The browser builds the crop filter by splitting this
            // value, and splitting on a comma silently breaks the day someone
            // adds a crop named "Beans, Sugar". A newline cannot occur inside a
            // crops.name, so the split is exact. (`'\n'` sits in a
            // double-quoted PHP string, so PHP — not MySQL — turns it into the
            // newline before the statement is ever prepared.)
            foreach ($rows as &$row) {
                $row['crops'] = agro_split_crops($row['crops_concat'] ?? '', "\n");
                $row['crops_display'] = implode(', ', $row['crops']);
                unset($row['crops_concat']);
            }
            unset($row);

            echo json_encode([
                'success'   => true,
                'data'      => $rows,
                'count'     => count($rows),
                'timestamp' => date('c'),
            ]);
            break;

        // ── DIRECTORY: farmers ──────────────────────────────────────────────
        // Approved farmers, newest first. Same contact-first shape as the seller
        // and buyer directories with one deliberate difference: NO CONTACT
        // DETAILS ARE SELECTED AT ALL.
        //
        // Farmers have no directory table — approval is recorded on the
        // application itself (admin/index.php promotes only sellers and buyers),
        // so this reads onboarding_applications directly. That table also holds
        // phone_number, whatsapp_number, email and national_id, and privacy.php
        // promises a public listing only for "a buyer or seller directory".
        // Nobody who registered as a farmer agreed to have their number
        // published, so the query does not fetch those columns. The omission —
        // not a filter further down the stack — is what makes the leak
        // impossible.
        //
        // Pending and denied applications are excluded for the same reason
        // sellers and buyers appear only once approved: an unreviewed
        // application is not a vetted listing.
        case 'farmers':
            $district_id = (int)($_GET['district_id'] ?? 0);
            $crop        = trim($_GET['crop'] ?? '');

            $query = "SELECT oa.id, oa.full_name AS name, oa.district_id, d.name AS district_name,
                             oa.village, oa.crops_of_interest AS crops_concat, oa.created_at
                      FROM onboarding_applications oa
                      JOIN districts d ON oa.district_id = d.id
                      WHERE oa.user_type = 'farmer' AND oa.status = 'approved'";

            $types  = '';
            $params = [];
            if ($district_id > 0) {
                $query .= " AND oa.district_id = ?";
                $types .= 'i';
                $params[] = $district_id;
            }
            if ($crop !== '') {
                // crops_of_interest is register.php's ", "-joined list of names
                // taken from the crops table, so a whole-token match is exact.
                // Wrapping both sides in ", " stops "Beans" matching "Soybeans",
                // and the LIKE metacharacters in the parameter are escaped so a
                // crafted crop=% cannot turn this into "match everything".
                $query .= " AND CONCAT(', ', oa.crops_of_interest, ', ') LIKE CONCAT('%, ', ?, ', %')";
                $types .= 's';
                $params[] = agro_escape_like($crop);
            }
            $query .= " ORDER BY oa.created_at DESC, oa.id DESC";

            $stmt = $mysqli->prepare($query);
            if (!$stmt) throw new Exception('Farmer directory query could not be prepared.');
            if ($types !== '') $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new Exception('Farmer directory query failed.');
            $rows = stmt_fetch_all($stmt);

            foreach ($rows as &$row) {
                $row['crops'] = agro_split_crops($row['crops_concat'] ?? '', ',');
                $row['crops_display'] = implode(', ', $row['crops']);
                unset($row['crops_concat']);
            }
            unset($row);

            echo json_encode([
                'success'   => true,
                'data'      => $rows,
                'count'     => count($rows),
                'timestamp' => date('c'),
            ]);
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
        // Registration (submit_application / check_duplicate) deliberately does
        // NOT live here. register.php owns the whole registration flow: it
        // validates, canonicalises phone and WhatsApp numbers via config/phone.php,
        // checks duplicates, writes onboarding_applications and sends the emails.
        // These two actions were a second, weaker path into the same table — they
        // accepted un-normalised phone numbers and skipped WhatsApp entirely — so
        // the same person could exist twice in different formats. Do not add them
        // back; extend register.php instead.

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

            // ADMARC official floor prices — admin-maintained, never fetched.
            // A failure here must not take the whole price page down: FEWS and
            // community prices stand on their own.
            $admarc = [];
            $admarc_error = null;
            try {
                $admarc = admarc_effective_prices($mysqli, $crop_id);
            } catch (Throwable $ae) {
                $admarc_error = $ae->getMessage();
            }

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
                'admarc'           => $admarc,
                'fews_count'       => count($fews),
                'community_count'  => count($community),
                'admarc_count'     => count($admarc),
                'admarc_error'     => $admarc_error,
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
                // Canonicalise to the same E.164 the registration flow stores, so
                // the member lookup below compares like with like.
                $canonicalPhone = agro_normalize_phone($phone);
                if ($canonicalPhone === null) {
                    throw new Exception('Enter a Malawi number as 0888 123 456, or an international number with its country code.');
                }
                $phone = $canonicalPhone;
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
            throw new Exception('Unknown action. Available actions: test, districts, crops, crop_prices, dual_crop_prices, markets, submit_price, market_insights, sellers, buyers, pest_control, farming_tips, basic_info, check_application.');
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
/**
 * ADMARC official prices in force today.
 *
 * ADMARC is ADMIN-MAINTAINED, not fetched. The admarc.mw domain stopped
 * resolving, so the old scrape (commit 9de275c) cannot be revived and nothing
 * here reaches the network. Figures are entered by hand in the admin panel and
 * every row carries the source it was taken from.
 *
 * Resolution rules, applied in PHP because the "latest row not in the future"
 * pick is per crop+district:
 *   - only rows with effective_from <= today are eligible, so a price can be
 *     staged ahead of a season opening without showing early;
 *   - the newest eligible effective_from wins, which is why a price change is a
 *     new row rather than an edit — the history stays auditable;
 *   - a district-specific row (district_id > 0) beats the national one
 *     (district_id 0) for that district, matching how price_overrides resolves.
 */
function admarc_effective_prices(mysqli $db, ?int $crop_id): array
{
    // The table arrives via migrations/2026-08-23-admarc-prices.sql; a
    // deployment that has not run it yet should degrade to "no ADMARC data"
    // rather than error, exactly as cp_apply_overrides does for price_overrides.
    $chk = $db->query("SHOW TABLES LIKE 'admarc_prices'");
    if (!$chk || $chk->num_rows === 0) return [];

    $sql = "SELECT a.crop_id, c.name AS crop_name, a.district_id, d.name AS district_name,
                   a.price_per_kg, a.price_per_bag, a.unit, a.season,
                   a.effective_from, a.source_note, a.updated_at
            FROM admarc_prices a
            JOIN crops c ON c.id = a.crop_id
            LEFT JOIN districts d ON d.id = a.district_id
            WHERE a.effective_from <= CURDATE()";
    if ($crop_id) {
        $stmt = $db->prepare($sql . " AND a.crop_id = ?");
        if (!$stmt) return [];
        $stmt->bind_param('i', $crop_id);
    } else {
        $stmt = $db->prepare($sql);
        if (!$stmt) return [];
    }
    $stmt->execute();
    $rows = stmt_fetch_all($stmt);

    // Keep only the newest eligible row per crop+district.
    $best = [];
    foreach ($rows as $r) {
        $key = $r['crop_id'] . '|' . $r['district_id'];
        if (!isset($best[$key]) || $r['effective_from'] > $best[$key]['effective_from']) {
            $best[$key] = $r;
        }
    }

    $out = [];
    foreach ($best as $r) {
        $out[] = [
            'crop_id'        => (int)$r['crop_id'],
            'crop_name'      => $r['crop_name'],
            'district_id'    => (int)$r['district_id'] ?: null,
            'district_name'  => (int)$r['district_id'] ? $r['district_name'] : null,
            'national'       => (int)$r['district_id'] === 0,
            'price'          => (float)$r['price_per_kg'],
            'price_per_bag'  => $r['price_per_bag'] !== null ? (float)$r['price_per_bag'] : null,
            'unit'           => $r['unit'] ?: 'kg',
            'season'         => $r['season'],
            'effective_from' => $r['effective_from'],
            'source_note'    => $r['source_note'],
            'updated_at'     => $r['updated_at'],
        ];
    }

    usort($out, fn($a, $b) => strcmp((string)$a['crop_name'], (string)$b['crop_name'])
        ?: ((int)$a['national'] <=> (int)$b['national']));

    return $out;
}

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
