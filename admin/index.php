<?php
/**
 * AgroBusiness Malawi — Admin Panel
 * Review and approve/deny KYC onboarding applications.
 *
 * Access: https://agrobusinessmw.com/admin/
 * Protected by a username + password login (credentials in the admin_users table,
 * seeded from ADMIN_USER / ADMIN_PASSWORD in .env on first run).
 *
 * Quick setup:
 *   Add to .env:  ADMIN_USER=... and ADMIN_PASSWORD=...
 *   Then access via browser — login prompt will appear.
 */

session_start();
error_reporting(0);
ini_set('display_errors', 0);

// ─── CSRF TOKEN ───────────────────────────────────────────────────────────────
// Minted at session-start time — BEFORE the login gate — so the login form,
// which is served to an unauthenticated visitor, carries a valid token too.
// One token per session, reused for every mutating POST.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Validate the CSRF token on a state-changing POST. Constant-time compare via
// hash_equals(). Must be called (and must pass) before any DB write.
function csrf_valid(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// ─── LOAD ENV ─────────────────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

// ─── DB CONNECTION ────────────────────────────────────────────────────────────
$host    = $_ENV['DB_HOST'] ?? '';
$db      = @new mysqli($host, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', $_ENV['DB_NAME'] ?? '', (int)($_ENV['DB_PORT'] ?? 3306));
if ($db->connect_error) die('<p style="color:red">DB connection failed.</p>');
$db->set_charset('utf8mb4');

// FEWS reference-rate helpers — called directly by the price-refresh handler below.
require_once dirname(__DIR__) . '/config/fews.php';

// ─── ADMIN AUTH ───────────────────────────────────────────────────────────────
// Credentials live in the `admin_users` table (created/seeded from .env on
// first run by api.php's admin_get_user()) — never hardcoded here.
$db->query(
    "CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);
$adminRow = ($res = $db->query("SELECT username, password_hash FROM admin_users LIMIT 1")) ? $res->fetch_assoc() : null;
if (!$adminRow) {
    $seedUser  = $_ENV['ADMIN_USER'] ?? 'admin';
    $seedPass  = $_ENV['ADMIN_PASSWORD'] ?? bin2hex(random_bytes(8));
    $hash = password_hash($seedPass, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
    $stmt->bind_param('ss', $seedUser, $hash);
    $stmt->execute();
    $adminRow = ['username' => $seedUser, 'password_hash' => $hash];
}

// ─── LOGIN THROTTLE ───────────────────────────────────────────────────────────
// Additive, lazily-created audit + throttle table. Never altered, never dropped.
$db->query(
    "CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(100) NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Read the client IP straight from REMOTE_ADDR — the real TCP peer. NEVER trust
// X-Forwarded-For here: it is an attacker-controlled request header, so a client
// could set a fresh value on every request and defeat the throttle entirely.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$LOGIN_MAX_FAILS = 5;   // failures per IP...
$LOGIN_WINDOW_M  = 15;  // ...within this many minutes → locked out.

// Count recent failures for an IP. FAIL OPEN on any DB error (return 0): a broken
// or unreadable attempts table must never permanently brick the admin panel. The
// password is still required regardless — this only governs the throttle.
function admin_recent_fails(mysqli $db, string $ip, int $windowMinutes): int {
    $n = 0;
    if ($stmt = $db->prepare(
        "SELECT COUNT(*) FROM admin_login_attempts
         WHERE ip = ? AND success = 0
           AND attempted_at > (NOW() - INTERVAL ? MINUTE)"
    )) {
        $stmt->bind_param('si', $ip, $windowMinutes);
        if ($stmt->execute()) {
            $stmt->bind_result($n);
            $stmt->fetch();
        }
        $stmt->close();
    }
    return (int) $n;
}

// Record one attempt. Stores IP, username, timestamp and success flag ONLY — the
// submitted password is never written here, in any form (plaintext or hashed).
function admin_record_attempt(mysqli $db, string $ip, ?string $username, bool $success): void {
    if ($stmt = $db->prepare(
        "INSERT INTO admin_login_attempts (ip, username, success) VALUES (?, ?, ?)"
    )) {
        $flag = $success ? 1 : 0;
        $stmt->bind_param('ssi', $ip, $username, $flag);
        $stmt->execute();
        $stmt->close();
    }
}

// On a successful login, clear this IP's prior failures so a stale window can't
// keep blocking a legitimate admin who has just authenticated.
function admin_clear_fails(mysqli $db, string $ip): void {
    if ($stmt = $db->prepare("DELETE FROM admin_login_attempts WHERE ip = ? AND success = 0")) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->close();
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    if (isset($_POST['password'])) {
        // Reject cross-site / tokenless login POSTs before touching credentials.
        // Generic message — no hint about whether the failure was CSRF or creds.
        if (!csrf_valid()) {
            showLogin('Invalid credentials.');
            exit;
        }

        $submittedUser = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';

        // Throttle BEFORE password_verify() so we never burn bcrypt cycles on an
        // attacker. The blocked attempt is still recorded (keeps the window rolling).
        if (admin_recent_fails($db, $clientIp, $LOGIN_WINDOW_M) >= $LOGIN_MAX_FAILS) {
            admin_record_attempt($db, $clientIp, $submittedUser, false);
            showLogin('Too many failed attempts. Please try again in about ' . $LOGIN_WINDOW_M . ' minutes.');
            exit;
        }

        if ($submittedUser === $adminRow['username']
            && password_verify($_POST['password'], $adminRow['password_hash'])) {
            admin_record_attempt($db, $clientIp, $submittedUser, true);
            admin_clear_fails($db, $clientIp);
            $_SESSION['admin_logged_in'] = true;
        } else {
            admin_record_attempt($db, $clientIp, $submittedUser, false);
            showLogin('Invalid credentials.');
            exit;
        }
    } else {
        showLogin(null);
        exit;
    }
}

// Reference-price override store (district_id 0 = all districts). Created lazily.
$db->query("CREATE TABLE IF NOT EXISTS price_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crop_id INT NOT NULL,
  district_id INT NOT NULL DEFAULT 0,
  price_per_kg DECIMAL(10,2) NOT NULL,
  note VARCHAR(255) NULL,
  set_by VARCHAR(50) NOT NULL DEFAULT 'admin',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_crop_district (crop_id, district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

// ─── APPLICANT → DIRECTORY PROMOTION ──────────────────────────────────────────
/**
 * Promote an approved applicant into the public directory tables.
 *
 *   user_type = 'seller' → INSERT seller_contact_details, then sellers
 *   user_type = 'buyer'  → INSERT buyer_contact_details,  then buyers
 *   user_type = 'farmer' → deliberate NO-OP, see the branch below
 *
 * MUST be called from inside the caller's transaction. The contact row and the
 * directory row have to land together (a failure between them leaves an orphan
 * contact row forever), and they have to land together with the status UPDATE —
 * otherwise an applicant ends up 'approved' and emailed but absent from the
 * directory, which is exactly the bug this function exists to close.
 *
 * Throws RuntimeException on any failure so the caller rolls everything back and
 * can surface the reason to the admin. Returns the directory table the applicant
 * was added to, or '' when nothing was promoted.
 */
function admin_promote_applicant(mysqli $db, array $app): string
{
    $userType = (string)($app['user_type'] ?? '');

    // ── farmer (and anything that is not a seller/buyer) — EXPLICIT NO-OP, BY
    //    DESIGN. Do NOT "fix" this by adding a farmers table insert: farmers have
    //    no directory table of their own, and their approval is tracked in
    //    onboarding_applications only. This branch is intentional, not an
    //    oversight — it mirrors the same decision in the API's old admin_review.
    if ($userType !== 'seller' && $userType !== 'buyer') {
        return '';
    }

    // ── district_id is validated here rather than left to MySQL to decide.
    //    `sellers`.district_id and `buyers`.district_id are `int NOT NULL` with an
    //    FK to districts(id) (p601229_AgroBusiness_MW.sql:475/851 for sellers,
    //    :58/800 for buyers), and an application can carry a null or stale
    //    district. Without this check a null silently becomes 0 and the INSERT
    //    dies on the FK with an opaque error mid-transaction.
    $districtId = isset($app['district_id']) && $app['district_id'] !== null ? (int)$app['district_id'] : 0;
    if ($districtId <= 0) {
        throw new RuntimeException('the application has no district on file, so it cannot be listed in the directory');
    }
    $dStmt = $db->prepare("SELECT id FROM districts WHERE id = ?");
    if (!$dStmt) {
        throw new RuntimeException('the district check could not be prepared');
    }
    $dStmt->bind_param('i', $districtId);
    $foundDistrict = 0;
    $dOk = $dStmt->execute();
    if ($dOk) {
        $dStmt->bind_result($foundDistrict);
        $dStmt->fetch();
    }
    $dStmt->close();
    if (!$dOk || (int)$foundDistrict !== $districtId) {
        throw new RuntimeException("district #{$districtId} on the application does not exist");
    }

    $name    = (string)($app['full_name'] ?? '');
    $phone   = $app['phone_number'] ?? null;
    $email   = $app['email'] ?? null;
    $address = $app['village'] ?? '';   // village is the applicant's address line

    // Both branches spell their SQL out in full literals. Nothing is interpolated
    // into a statement — not even the table name — so there is no path from any
    // applicant value into the SQL text.
    if ($userType === 'seller') {
        $cStmt = $db->prepare("INSERT INTO seller_contact_details (phone_number, email, address) VALUES (?,?,?)");
        if (!$cStmt) {
            throw new RuntimeException('the seller contact insert could not be prepared');
        }
        $cStmt->bind_param('sss', $phone, $email, $address);
        $cOk = $cStmt->execute();
        $cStmt->close();
        if (!$cOk) {
            throw new RuntimeException('the seller contact row could not be saved');
        }
        $contactId = (int)$db->insert_id;
        if ($contactId <= 0) {
            throw new RuntimeException('the seller contact row returned no id');
        }

        $sStmt = $db->prepare("INSERT INTO sellers (name, district_id, contact_id) VALUES (?,?,?)");
        if (!$sStmt) {
            throw new RuntimeException('the seller insert could not be prepared');
        }
        $sStmt->bind_param('sii', $name, $districtId, $contactId);
        $sOk = $sStmt->execute();
        $sStmt->close();
        if (!$sOk) {
            throw new RuntimeException('the seller directory row could not be saved');
        }
        return 'sellers';
    }

    $cStmt = $db->prepare("INSERT INTO buyer_contact_details (phone_number, email, address) VALUES (?,?,?)");
    if (!$cStmt) {
        throw new RuntimeException('the buyer contact insert could not be prepared');
    }
    $cStmt->bind_param('sss', $phone, $email, $address);
    $cOk = $cStmt->execute();
    $cStmt->close();
    if (!$cOk) {
        throw new RuntimeException('the buyer contact row could not be saved');
    }
    $contactId = (int)$db->insert_id;
    if ($contactId <= 0) {
        throw new RuntimeException('the buyer contact row returned no id');
    }

    $bStmt = $db->prepare("INSERT INTO buyers (name, district_id, contact_id) VALUES (?,?,?)");
    if (!$bStmt) {
        throw new RuntimeException('the buyer insert could not be prepared');
    }
    $bStmt->bind_param('sii', $name, $districtId, $contactId);
    $bOk = $bStmt->execute();
    $bStmt->close();
    if (!$bOk) {
        throw new RuntimeException('the buyer directory row could not be saved');
    }
    return 'buyers';
}

// ─── HANDLE APPROVE / DENY ────────────────────────────────────────────────────
$actionMsg = '';
$actionErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id']) && csrf_valid()) {
    $id     = (int)$_POST['review_id'];
    $action = $_POST['review_action'] ?? '';
    $notes  = trim($_POST['review_notes'] ?? '');

    if (in_array($action, ['approve', 'deny']) && $id > 0) {
        $status = $action === 'approve' ? 'approved' : 'denied';
        $denial = $action === 'deny' ? $notes : null;
        $aNote  = $action === 'approve' ? $notes : null;

        $app        = null;
        $promotedTo = '';
        $committed  = false;

        // One transaction covers the whole decision: read the applicant, promote
        // them into the directory, THEN write the status. Ordering matters as much
        // as the transaction does — the status UPDATE is the LAST statement, so a
        // failed promotion aborts before anyone is marked approved. (That ordering
        // also holds if onboarding_applications should turn out to be a
        // non-transactional engine, which the schema of record does not state.)
        // No mail is sent until the commit succeeds.
        try {
            $db->begin_transaction();

            // Read BEFORE the UPDATE — we need the applicant's *current* status to
            // decide whether this is a genuine transition, plus the columns the
            // promotion needs. FOR UPDATE locks the row so two concurrent approvals
            // of the same id serialise here instead of both seeing 'pending'.
            $s2 = $db->prepare(
                "SELECT full_name, email, application_ref, user_type, phone_number, district_id, village, status
                 FROM onboarding_applications WHERE id=? FOR UPDATE"
            );
            if (!$s2) {
                throw new RuntimeException('the applicant lookup could not be prepared');
            }
            $s2->bind_param('i', $id);
            if (!$s2->execute()) {
                throw new RuntimeException('the applicant lookup failed');
            }
            // No get_result(): mysqlnd is not guaranteed on the host. Bind + fetch
            // instead. The bind_result list below matches the SELECT list above
            // one-for-one, in order — 8 columns, 8 variables.
            $s2->bind_result($fullName, $email, $appRef, $userType, $phoneNumber, $districtId, $village, $currentStatus);
            $app = $s2->fetch()
                ? [
                    'full_name'       => $fullName,
                    'email'           => $email,
                    'application_ref' => $appRef,
                    'user_type'       => $userType,
                    'phone_number'    => $phoneNumber,
                    'district_id'     => $districtId,
                    'village'         => $village,
                    'status'          => $currentStatus,
                  ]
                : null;
            $s2->close();

            if ($app === null) {
                throw new RuntimeException('no application with that id exists');
            }

            // Double-promotion guard. The guard chosen is the STATUS TRANSITION:
            // promote only when the row was not already 'approved'. Reason: the
            // directory tables carry no back-reference to the application (see
            // `sellers` — id/name/district_id/contact_id only), so an "is there
            // already a directory row?" check could only guess by matching on name
            // or phone, which is fuzzy and would wrongly block genuine namesakes.
            // The application's own status is authoritative, and reading it under
            // FOR UPDATE inside this transaction makes the check race-safe.
            if ($action === 'approve' && $app['status'] !== 'approved') {
                $promotedTo = admin_promote_applicant($db, $app);
            }

            $stmt = $db->prepare(
                "UPDATE onboarding_applications SET status=?, admin_notes=?, denial_reason=?, reviewed_at=NOW() WHERE id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('the status update could not be prepared');
            }
            $stmt->bind_param('sssi', $status, $aNote, $denial, $id);
            $sOk = $stmt->execute();
            $stmt->close();
            if (!$sOk) {
                throw new RuntimeException('the status update failed');
            }

            $db->commit();
            $committed = true;
        } catch (Throwable $e) {
            // Roll back defensively — if the connection itself is what failed, the
            // rollback can throw too, and that must not mask the real reason.
            try { $db->rollback(); } catch (Throwable $ignored) { /* connection already gone */ }
            $actionErr = "Application #{$id} was NOT {$status} — the change was rolled back: "
                       . $e->getMessage() . '. Nothing was saved and no email was sent.';
        } finally {
            // begin_transaction() only restores autocommit implicitly; put the
            // connection back into a known state for the handlers that follow.
            $db->autocommit(true);
        }

        if ($committed && $app && $app['email']) {
            if ($action === 'approve') {
                $subj = "AgroBusiness Malawi — Application Approved! ({$app['application_ref']})";
                $body = "Dear {$app['full_name']},\n\nYour application has been APPROVED.\n"
                      . ($notes ? "Notes: {$notes}\n" : '')
                      . "\nWelcome to AgroBusiness Malawi!";
            } else {
                $subj = "AgroBusiness Malawi — Application Update ({$app['application_ref']})";
                $body = "Dear {$app['full_name']},\n\nYour application could not be approved.\n"
                      . ($notes ? "Reason: {$notes}\n" : '')
                      . "\nContact us if you have questions.";
            }
            @mail($app['email'], $subj, $body, "From: noreply@agrobusinessmw.com\r\nContent-Type: text/plain; charset=utf-8");
        }

        if ($committed) {
            $actionMsg = "Application #{$id} {$status}."
                       . ($promotedTo !== '' ? " Added to the {$promotedTo} directory." : '');
        }
    }
}

// ─── HANDLE COMMUNITY PRICE REVIEW (approve / reject) ─────────────────────────
$priceMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['price_review_id']) && csrf_valid()) {
    $pid   = (int)$_POST['price_review_id'];
    $pact  = $_POST['price_action'] ?? '';
    $pnote = trim($_POST['price_notes'] ?? '');
    $pmap  = ['approve' => 'approved', 'reject' => 'rejected'];
    if (isset($pmap[$pact]) && $pid > 0) {
        $pstatus = $pmap[$pact];
        $pn = $pact === 'reject' ? ($pnote !== '' ? $pnote : null) : null;
        $ps = $db->prepare("UPDATE crowdsourced_prices SET status=?, flag_reason=?, reviewed_by='admin', reviewed_at=NOW() WHERE id=?");
        if ($ps) {
            $ps->bind_param('ssi', $pstatus, $pn, $pid);
            $ps->execute();
            $priceMsg = "Price report #{$pid} {$pstatus}.";
        }
    }
}

// ─── HANDLE PRICE MANAGEMENT (refresh source / manual price / override) ───────
$pmMsg = '';
$pmErr = '';

// a) Refresh reference prices from the upstream source (FEWS). Runs the FEWS
//    fetch directly (this handler is already past the login gate — no token needed).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_source']) && csrf_valid()) {
    $cacheFile = dirname(__DIR__) . '/config/fews_prices_cache.json';
    if (file_exists($cacheFile)) unlink($cacheFile);
    $result = fews_get_prices($db);
    $rows   = count($result['data'] ?? []);
    if (empty($result['data']) && !empty($result['error'])) {
        $pmErr = 'Refresh failed: ' . htmlspecialchars($result['error']) . '.';
    } else {
        $pmMsg = "Reference prices refreshed from source — {$rows} rows"
               . (!empty($result['error']) ? " (source note: {$result['error']})" : '') . '.';
    }
}

// b) Manually add an approved community price.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['manual_mode'] ?? '') === 'community' && csrf_valid()) {
    $cid = (int)($_POST['m_crop_id'] ?? 0);
    $did = (int)($_POST['m_district_id'] ?? 0);
    $kg  = (float)($_POST['m_price_kg'] ?? 0);
    $mkt = trim($_POST['m_market'] ?? '');
    if ($cid && $did && $kg > 0) {
        $bag = round($kg * 50, 2);
        $q = $db->prepare("INSERT INTO crowdsourced_prices
            (crop_id, district_id, price_per_kg, price_per_bag, unit, market_name,
             submitted_by, channel, verified, status, is_member, created_at)
            VALUES (?, ?, ?, ?, 'kg', ?, 'admin', 'web', 1, 'approved', 0, NOW())");
        if ($q) { $q->bind_param('iidds', $cid, $did, $kg, $bag, $mkt); $q->execute();
            $pmMsg = 'Community price added and approved.'; }
    } else {
        $pmErr = 'Provide a crop, district and a price greater than zero.';
    }
}

// c) Manually set / update a reference override (district 0 = all districts).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['manual_mode'] ?? '') === 'reference' && csrf_valid()) {
    $cid  = (int)($_POST['m_crop_id'] ?? 0);
    $did  = (int)($_POST['m_district_id'] ?? 0);
    $kg   = (float)($_POST['m_price_kg'] ?? 0);
    $note = trim($_POST['m_note'] ?? '');
    if ($cid && $kg > 0) {
        $q = $db->prepare("INSERT INTO price_overrides (crop_id, district_id, price_per_kg, note, set_by)
            VALUES (?, ?, ?, ?, 'admin')
            ON DUPLICATE KEY UPDATE price_per_kg=VALUES(price_per_kg), note=VALUES(note), set_by='admin'");
        if ($q) { $q->bind_param('iids', $cid, $did, $kg, $note); $q->execute();
            $pmMsg = 'Reference override saved.'; }
    } else {
        $pmErr = 'Provide a crop and a price greater than zero.';
    }
}

// d) Delete a reference override.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_override_id']) && csrf_valid()) {
    $oid = (int)$_POST['delete_override_id'];
    if ($oid > 0) {
        $q = $db->prepare("DELETE FROM price_overrides WHERE id=?");
        if ($q) { $q->bind_param('i', $oid); $q->execute(); $pmMsg = 'Override removed.'; }
    }
}

// Reference data for the price-management form.
$pmCrops = [];
if ($r = $db->query("SELECT id, name FROM crops ORDER BY name")) { while ($x = $r->fetch_assoc()) $pmCrops[] = $x; }
$pmDistricts = [];
if ($r = $db->query("SELECT id, name FROM districts ORDER BY name")) { while ($x = $r->fetch_assoc()) $pmDistricts[] = $x; }
$overrides = [];
if ($r = $db->query("SELECT o.id, o.crop_id, o.district_id, o.price_per_kg, o.note, o.updated_at,
                            c.name AS crop_name, d.name AS district_name
                     FROM price_overrides o JOIN crops c ON c.id = o.crop_id
                     LEFT JOIN districts d ON d.id = o.district_id
                     ORDER BY c.name, d.name")) { while ($x = $r->fetch_assoc()) $overrides[] = $x; }

// ─── FETCH APPLICATIONS ───────────────────────────────────────────────────────
$filterStatus = in_array($_GET['status'] ?? 'pending', ['pending','approved','denied','all'])
    ? ($_GET['status'] ?? 'pending') : 'pending';

$result = $db->query(
    "SELECT a.*, d.name as district_name
     FROM onboarding_applications a
     LEFT JOIN districts d ON a.district_id = d.id
     ORDER BY a.created_at DESC
     LIMIT 200"
);
$apps = [];
while ($row = $result->fetch_assoc()) $apps[] = $row;

// ─── COUNTS ───────────────────────────────────────────────────────────────────
$counts = [];
foreach (['pending','approved','denied'] as $s) {
    $r = $db->query("SELECT COUNT(*) as n FROM onboarding_applications WHERE status='{$s}'");
    $counts[$s] = $r->fetch_assoc()['n'];
}

// ─── FETCH COMMUNITY PRICES AWAITING REVIEW ───────────────────────────────────
// Guarded: the review columns are added by the community-price-review migration.
$priceReviewAvailable = false;
$pendingPrices = [];
$colCheck = $db->query("SHOW COLUMNS FROM crowdsourced_prices LIKE 'status'");
if ($colCheck && $colCheck->num_rows > 0) {
    $priceReviewAvailable = true;
    $pr = $db->query(
        "SELECT cp.id, c.name AS crop_name, d.name AS district_name, cp.market_name,
                cp.price_per_kg, cp.price_per_bag, cp.unit, cp.submitted_by, cp.channel,
                cp.status, cp.is_member, cp.flag_reason, cp.created_at
         FROM crowdsourced_prices cp
         JOIN crops c ON cp.crop_id = c.id
         LEFT JOIN districts d ON cp.district_id = d.id
         WHERE cp.status IN ('pending','flagged')
         ORDER BY cp.created_at ASC LIMIT 200"
    );
    if ($pr) while ($row = $pr->fetch_assoc()) $pendingPrices[] = $row;
}

// ─── LOGOUT ───────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

// ─── RENDER ───────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — AgroBusiness Malawi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', system-ui, sans-serif; background: #f5f2eb; color: #3e3930; min-height: 100vh; }
a { color: #8B7355; text-decoration: none; transition: color 0.18s ease; }
a:hover { color: #7a6448; }
.top-bar { background: #fff; border-bottom: 1px solid #e8e2d9; padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(70,60,50,0.06); }
.top-bar h1 { font-family: 'DM Serif Display', Georgia, serif; font-size: 1.25rem; font-weight: 400; color: #3e3930; }
.top-bar small { color: #6b5f52; }
.logout { padding: .5rem 1.2rem; background: transparent; border: 1.5px solid #d5cfc4; border-radius: 6px; color: #6b5f52; font-size: .85rem; font-weight: 600; cursor: pointer; transition: all 0.18s ease; }
.logout:hover { background: #b94040; border-color: #b94040; color: #fff; }
.container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
.stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 1.5rem; margin-bottom: 2rem; }
.stat-box { background: #fff; border: 1px solid #e8e2d9; border-radius: 12px; padding: 1.5rem; text-align: center; border-top: 3px solid; }
.stat-box:nth-child(1) { border-top-color: #f59e0b; }
.stat-box:nth-child(2) { border-top-color: #22c55e; }
.stat-box:nth-child(3) { border-top-color: #ef4444; }
.stat-num { font-size: 2.5rem; font-weight: 800; margin-bottom: .5rem; }
.stat-label { font-size: .85rem; color: #6b5f52; margin-top: .25rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
.pending-color  { color: #f59e0b; }
.approved-color { color: #22c55e; }
.denied-color   { color: #ef4444; }
.filter-tabs { display: flex; gap: .5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.tab { padding: .6rem 1.2rem; border-radius: 8px; background: #f5f2eb; color: #6b5f52; font-size: .85rem; font-weight: 600; border: 1px solid #e8e2d9; transition: all 0.18s ease; cursor: pointer; }
.tab.active { background: #8B7355; color: #fff; border-color: #8B7355; }
.tab:hover:not(.active) { background: #faf8f4; border-color: #d5cfc4; }
.table-tools { display: grid; grid-template-columns: minmax(220px, 1fr) repeat(3, minmax(140px, 180px)); gap: .75rem; margin-bottom: 1rem; }
.table-tools input, .table-tools select { padding: .7rem .85rem; border: 1px solid #d5cfc4; border-radius: 8px; background: #fff; color: #3e3930; font-size: .86rem; }
.table-tools input:focus, .table-tools select:focus { outline: none; border-color: #8B7355; box-shadow: 0 0 0 3px rgba(139,115,85,0.1); }
.table-stats { color: #6b5f52; font-size: .85rem; margin-bottom: .75rem; }
.msg { padding: 1rem 1.25rem; background: rgba(74,124,89,.1); border: 1px solid rgba(74,124,89,.3); border-radius: 8px; color: #4a7c59; margin-bottom: 1.5rem; font-weight: 600; }
table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e8e2d9; }
th { background: #f5f2eb; padding: .875rem 1rem; text-align: left; font-size: .78rem; color: #6b5f52; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; border-bottom: 2px solid #d5cfc4; }
td { padding: .75rem 1rem; border-bottom: 1px solid #ede9e0; font-size: .875rem; vertical-align: top; color: #3e3930; }
tr:nth-child(even) td { background: #faf8f4; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f0ece4; transition: background 0.15s ease; }
.badge { display: inline-block; padding: .35rem .8rem; border-radius: 20px; font-size: .75rem; font-weight: 700; }
.badge-pending  { background: rgba(245,158,11,.1); color: #b87c0b; }
.badge-approved { background: rgba(74,124,89,.1); color: #4a7c59; }
.badge-denied   { background: rgba(185,64,64,.1); color: #b94040; }
.badge-web  { background: rgba(139,115,85,.1); color: #8B7355; }
.badge-ussd { background: rgba(200,134,10,.1); color: #c8860a; }
.actions form { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
.actions input[type=text] { padding: .5rem .8rem; background: #faf8f4; border: 1px solid #d5cfc4; border-radius: 6px; color: #3e3930; font-size: .8rem; width: 140px; transition: all 0.18s ease; }
.actions input[type=text]:focus { outline: none; border-color: #8B7355; background: #fff; box-shadow: 0 0 0 3px rgba(139,115,85,0.1); }
.btn-approve { padding: .5rem 1rem; background: #4a7c59; border: none; border-radius: 6px; color: #fff; cursor: pointer; font-size: .8rem; font-weight: 600; transition: all 0.18s ease; }
.btn-approve:hover { background: #3d6549; box-shadow: 0 2px 8px rgba(74,124,89,0.3); transform: translateY(-1px); }
.btn-deny { padding: .5rem 1rem; background: #b94040; border: none; border-radius: 6px; color: #fff; cursor: pointer; font-size: .8rem; font-weight: 600; transition: all 0.18s ease; }
.btn-deny:hover { background: #a23a3a; box-shadow: 0 2px 8px rgba(185,64,64,0.3); transform: translateY(-1px); }
.empty { text-align: center; padding: 3rem; color: #9d9087; font-size: 1rem; }
.ref { font-size: .75rem; font-family: monospace; color: #8B7355; font-weight: 600; }
@media (max-width: 640px) {
    .stats { grid-template-columns: 1fr; }
    table { font-size: .78rem; }
    th, td { padding: .5rem .6rem; }
    .actions input[type=text] { width: 100px; }
}
</style>
</head>
<body>

<div class="top-bar">
    <div>
        <h1>🌾 AgroBusiness Malawi — Admin</h1>
        <small>KYC Application Review</small>
    </div>
    <a href="?logout=1" class="logout">Logout</a>
</div>

<div class="container">

    <!-- Stats -->
    <div class="stats">
        <div class="stat-box">
            <div class="stat-num pending-color"><?= $counts['pending'] ?></div>
            <div class="stat-label">Pending Review</div>
        </div>
        <div class="stat-box">
            <div class="stat-num approved-color"><?= $counts['approved'] ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-box">
            <div class="stat-num denied-color"><?= $counts['denied'] ?></div>
            <div class="stat-label">Denied</div>
        </div>
    </div>

    <?php if ($actionMsg): ?>
    <div class="msg">✅ <?= htmlspecialchars($actionMsg) ?></div>
    <?php endif; ?>

    <?php if ($actionErr): ?>
    <div class="msg" style="background:rgba(185,64,64,.08);border-color:rgba(185,64,64,.3);color:#b94040">⚠️ <?= htmlspecialchars($actionErr) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-tabs">
        <?php foreach (['pending','approved','denied','all'] as $s): ?>
        <button type="button" class="tab <?= $filterStatus === $s ? 'active' : '' ?>" data-status="<?= $s ?>">
            <?= ucfirst($s) ?> <?= $s !== 'all' ? "({$counts[$s]})" : '' ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="table-tools">
        <input type="search" id="table-search" placeholder="Search ref, name, phone, district, channel...">
        <select id="type-filter">
            <option value="all">All types</option>
            <option value="farmer">Farmer</option>
            <option value="seller">Seller</option>
            <option value="buyer">Buyer</option>
        </select>
        <select id="channel-filter">
            <option value="all">All channels</option>
            <option value="web">Web</option>
            <option value="ussd">USSD</option>
        </select>
        <select id="district-filter">
            <option value="all">All districts</option>
            <?php foreach (array_unique(array_filter(array_column($apps, 'district_name'))) as $district): ?>
            <option value="<?= htmlspecialchars(strtolower($district)) ?>"><?= htmlspecialchars($district) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="table-stats" id="table-stats">Showing <?= count($apps) ?> applications</div>

    <!-- Applications table -->
    <?php if (empty($apps)): ?>
    <div class="empty">No applications found.</div>
    <?php else: ?>
    <table id="applications-table" class="sortable">
        <thead>
            <tr>
                <th>Ref</th>
                <th>Type</th>
                <th>Name</th>
                <th>Phone</th>
                <th>National ID</th>
                <th>District</th>
                <th>Channel</th>
                <th>Date</th>
                <th>Status</th>
                <th data-no-sort>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apps as $a): ?>
        <tr data-type="<?= htmlspecialchars(strtolower($a['user_type'])) ?>" data-channel="<?= htmlspecialchars(strtolower($a['channel'])) ?>" data-district="<?= htmlspecialchars(strtolower($a['district_name'] ?? '')) ?>" data-status="<?= htmlspecialchars($a['status']) ?>" data-search="<?= htmlspecialchars(strtolower(implode(' ', [$a['application_ref'], $a['user_type'], $a['full_name'], $a['email'], $a['phone_number'], $a['national_id'], $a['district_name'], $a['channel'], $a['status']]))) ?>">
            <td><span class="ref"><?= htmlspecialchars($a['application_ref']) ?></span></td>
            <td><?= htmlspecialchars(ucfirst($a['user_type'])) ?></td>
            <td><?= htmlspecialchars($a['full_name']) ?><br>
                <?php if ($a['email']): ?><small style="color:#a3a3a3"><?= htmlspecialchars($a['email']) ?></small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($a['phone_number']) ?></td>
            <td><?= $a['national_id'] ? htmlspecialchars($a['national_id']) : '<span style="color:#6b6b6b">—</span>' ?></td>
            <td><?= $a['district_name'] ? htmlspecialchars($a['district_name']) : '<span style="color:#6b6b6b">—</span>' ?></td>
            <td><span class="badge badge-<?= $a['channel'] ?>"><?= strtoupper($a['channel']) ?></span></td>
            <td data-sort-value="<?= strtotime($a['created_at']) ?>" style="font-size:.78rem;color:#a3a3a3"><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
            <td>
                <span class="badge badge-<?= $a['status'] ?>"><?= strtoupper($a['status']) ?></span>
                <?php if ($a['denial_reason']): ?>
                <br><small style="color:#6b6b6b;font-size:.72rem"><?= htmlspecialchars(mb_substr($a['denial_reason'], 0, 50)) ?></small>
                <?php endif; ?>
            </td>
            <td class="actions">
                <?php if ($a['status'] === 'pending'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="review_id" value="<?= $a['id'] ?>">
                    <input type="text" name="review_notes" placeholder="Notes (optional)">
                    <button type="submit" name="review_action" value="approve" class="btn-approve">Approve</button>
                    <button type="submit" name="review_action" value="deny" class="btn-deny">Deny</button>
                </form>
                <?php else: ?>
                <span style="color:#6b6b6b;font-size:.8rem">Reviewed</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Community price review queue -->
    <h2 style="margin:2.5rem 0 1rem;font-family:Inter,system-ui,sans-serif;font-size:1.25rem;color:#3e3930">
        🧺 Community Price Review
        <?php if ($priceReviewAvailable): ?><span style="font-size:.85rem;color:#8B7355">(<?= count($pendingPrices) ?> awaiting)</span><?php endif; ?>
    </h2>
    <?php if ($priceMsg): ?>
    <div class="msg">✅ <?= htmlspecialchars($priceMsg) ?></div>
    <?php endif; ?>

    <?php if (!$priceReviewAvailable): ?>
    <div class="empty">
        Price review is not active yet — the <code>crowdsourced_prices.status</code>
        column is missing on this database.
    </div>
    <?php elseif (empty($pendingPrices)): ?>
    <div class="empty">No community prices awaiting review. 🎉</div>
    <?php else: ?>
    <table id="prices-table" class="sortable">
        <thead>
            <tr>
                <th>Crop</th>
                <th>District / Market</th>
                <th>Price/kg</th>
                <th>Price/bag</th>
                <th>Submitted by</th>
                <th>Channel</th>
                <th>Member</th>
                <th>Status</th>
                <th>Date</th>
                <th data-no-sort>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingPrices as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['crop_name']) ?></td>
                <td><?= htmlspecialchars($p['district_name'] ?? '—') ?>
                    <?php if ($p['market_name']): ?><br><small style="color:#a3a3a3"><?= htmlspecialchars($p['market_name']) ?></small><?php endif; ?>
                </td>
                <td data-sort-value="<?= (float)$p['price_per_kg'] ?>">MWK <?= number_format((float)$p['price_per_kg']) ?></td>
                <td data-sort-value="<?= (float)$p['price_per_bag'] ?>">MWK <?= number_format((float)$p['price_per_bag']) ?></td>
                <td><?= htmlspecialchars($p['submitted_by']) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($p['channel']) ?>"><?= strtoupper($p['channel']) ?></span></td>
                <td><?= $p['is_member'] ? '✅' : '<span style="color:#6b6b6b">—</span>' ?></td>
                <td>
                    <span class="badge badge-<?= $p['status'] === 'flagged' ? 'denied' : 'pending' ?>"><?= strtoupper($p['status']) ?></span>
                    <?php if ($p['flag_reason']): ?><br><small style="color:#b94040;font-size:.72rem"><?= htmlspecialchars($p['flag_reason']) ?></small><?php endif; ?>
                </td>
                <td data-sort-value="<?= strtotime($p['created_at']) ?>" style="font-size:.78rem;color:#a3a3a3"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="price_review_id" value="<?= $p['id'] ?>">
                        <input type="text" name="price_notes" placeholder="Reason (if rejecting)">
                        <button type="submit" name="price_action" value="approve" class="btn-approve">Approve</button>
                        <button type="submit" name="price_action" value="reject" class="btn-deny">Reject</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Price management -->
    <h2 style="margin:2.5rem 0 1rem;font-family:Inter,system-ui,sans-serif;font-size:1.25rem;color:#3e3930">
        📈 Price Management
    </h2>
    <?php if ($pmMsg): ?><div class="msg">✅ <?= htmlspecialchars($pmMsg) ?></div><?php endif; ?>
    <?php if ($pmErr): ?><div class="msg" style="background:rgba(185,64,64,.08);border-color:rgba(185,64,64,.3);color:#b94040">⚠️ <?= $pmErr ?></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">
        <!-- Refresh from source -->
        <div style="background:#fff;border:1px solid #e8e2d9;border-radius:12px;padding:1.5rem">
            <h3 style="font-size:1rem;margin-bottom:.5rem;color:#3e3930">Update prices from source</h3>
            <p style="font-size:.85rem;color:#6b5f52;margin-bottom:1rem;line-height:1.5">
                Re-fetches the AgroBiz reference rates from the upstream source and refreshes the cache.
            </p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" name="refresh_source" value="1" class="btn-approve">Refresh from source</button>
            </form>
        </div>

        <!-- Manual price -->
        <div style="background:#fff;border:1px solid #e8e2d9;border-radius:12px;padding:1.5rem">
            <h3 style="font-size:1rem;margin-bottom:1rem;color:#3e3930">Set an individual price</h3>
            <form method="post" id="manual-price-form" style="display:grid;gap:.75rem">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div style="display:flex;gap:1rem;font-size:.85rem;color:#6b5f52">
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer">
                        <input type="radio" name="manual_mode" value="community" checked> Community price
                    </label>
                    <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer">
                        <input type="radio" name="manual_mode" value="reference"> Reference override
                    </label>
                </div>
                <select name="m_crop_id" required style="padding:.6rem;border:1px solid #d5cfc4;border-radius:8px;background:#fff">
                    <option value="">— select crop —</option>
                    <?php foreach ($pmCrops as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                </select>
                <select name="m_district_id" id="m_district" style="padding:.6rem;border:1px solid #d5cfc4;border-radius:8px;background:#fff">
                    <option value="0" data-ref-only>All districts (reference only)</option>
                    <?php foreach ($pmDistricts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="m_market" id="m_market" placeholder="Market name (community price)" style="padding:.6rem;border:1px solid #d5cfc4;border-radius:8px;background:#fff">
                <input type="text" name="m_note" id="m_note" placeholder="Note (reference override)" style="padding:.6rem;border:1px solid #d5cfc4;border-radius:8px;background:#fff;display:none">
                <input type="number" name="m_price_kg" min="1" step="0.01" required placeholder="Price per kg (MWK)" style="padding:.6rem;border:1px solid #d5cfc4;border-radius:8px;background:#fff">
                <button type="submit" class="btn-approve">Save price</button>
            </form>
        </div>
    </div>

    <!-- Current reference overrides -->
    <?php if (!empty($overrides)): ?>
    <h3 style="font-size:1rem;margin:1.5rem 0 .75rem;color:#3e3930">Active reference overrides (<?= count($overrides) ?>)</h3>
    <table>
        <thead><tr><th>Crop</th><th>District</th><th>Price/kg</th><th>Note</th><th>Updated</th><th data-no-sort>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($overrides as $o): ?>
            <tr>
                <td><?= htmlspecialchars($o['crop_name']) ?></td>
                <td><?= (int)$o['district_id'] === 0 ? '<em>All districts</em>' : htmlspecialchars($o['district_name'] ?? '—') ?></td>
                <td>MWK <?= number_format((float)$o['price_per_kg']) ?></td>
                <td><?= $o['note'] ? htmlspecialchars($o['note']) : '<span style="color:#9d9087">—</span>' ?></td>
                <td style="font-size:.78rem;color:#a3a3a3"><?= date('d/m/Y H:i', strtotime($o['updated_at'])) ?></td>
                <td class="actions">
                    <form method="post" onsubmit="return confirm('Remove this override?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="delete_override_id" value="<?= (int)$o['id'] ?>">
                        <button type="submit" class="btn-deny">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>
<script>
// Toggle market vs note field based on the selected manual-price mode.
(function () {
    const form = document.getElementById('manual-price-form');
    if (!form) return;
    const market = document.getElementById('m_market');
    const note = document.getElementById('m_note');
    const district = document.getElementById('m_district');
    function apply() {
        const mode = form.querySelector('input[name="manual_mode"]:checked').value;
        const isCommunity = mode === 'community';
        market.style.display = isCommunity ? '' : 'none';
        note.style.display = isCommunity ? 'none' : '';
        // Community requires a specific district; "All districts" is reference-only.
        district.querySelector('option[data-ref-only]').disabled = isCommunity;
        if (isCommunity && district.value === '0') district.value = '';
    }
    form.querySelectorAll('input[name="manual_mode"]').forEach(r => r.addEventListener('change', apply));
    apply();
})();
</script>
<script>
const rows = Array.from(document.querySelectorAll('#applications-table tbody tr'));
const search = document.getElementById('table-search');
const typeFilter = document.getElementById('type-filter');
const channelFilter = document.getElementById('channel-filter');
const districtFilter = document.getElementById('district-filter');
const tabs = document.querySelectorAll('.filter-tabs .tab');
const stats = document.getElementById('table-stats');
let activeStatus = '<?= $filterStatus ?>';

function applyTableFilters() {
    const term = search.value.trim().toLowerCase();
    const type = typeFilter.value;
    const channel = channelFilter.value;
    const district = districtFilter.value;
    let visible = 0;

    rows.forEach(row => {
        const show = (!term || row.dataset.search.includes(term))
            && (type === 'all' || row.dataset.type === type)
            && (channel === 'all' || row.dataset.channel === channel)
            && (district === 'all' || row.dataset.district === district)
            && (activeStatus === 'all' || row.dataset.status === activeStatus);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    stats.textContent = `Showing ${visible} of ${rows.length} applications`;
}

tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeStatus = tab.dataset.status;
        applyTableFilters();
    });
});

[search, typeFilter, channelFilter, districtFilter].forEach(control => {
    if (!control) return;
    control.addEventListener('input', applyTableFilters);
    control.addEventListener('change', applyTableFilters);
});

applyTableFilters();
</script>
<script src="../assets/js/sortable-table.js"></script>
</body>
</html>

<?php
// ─── LOGIN PAGE ───────────────────────────────────────────────────────────────
function showLogin(?string $error): void {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — AgroBusiness</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', system-ui, sans-serif; background: #f5f2eb; color: #3e3930; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.card { background: #fff; border: 1px solid #e8e2d9; border-radius: 16px; padding: 2.5rem; width: 100%; max-width: 360px; box-shadow: 0 8px 24px rgba(70,60,50,0.12); }
h2 { margin-bottom: 1.5rem; font-family: 'DM Serif Display', Georgia, serif; font-size: 1.6rem; text-align: center; color: #3e3930; font-weight: 400; }
label { display: block; font-size: .85rem; color: #6b5f52; margin-bottom: .4rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
input { width: 100%; padding: .875rem 1rem; background: #faf8f4; border: 1.5px solid #d5cfc4; border-radius: 8px; color: #3e3930; font-size: .95rem; margin-bottom: 1.25rem; outline: none; font-family: inherit; transition: all 0.18s ease; }
input:focus { border-color: #8B7355; background: #fff; box-shadow: 0 0 0 3px rgba(139,115,85,0.1); }
button { width: 100%; padding: .875rem; background: #8B7355; border: none; border-radius: 8px; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.18s ease; }
button:hover { background: #7a6448; box-shadow: 0 6px 20px rgba(139,115,85,0.3); transform: translateY(-2px); }
.error { color: #b94040; font-size: .85rem; margin-bottom: 1rem; text-align: center; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
    <h2>🌾 Admin Login</h2>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required>
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password" required>
        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>
<?php
}
