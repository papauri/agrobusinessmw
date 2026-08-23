<?php
/**
 * Community price review endpoint.
 *
 * Review identity comes from the authenticated admin_user_id stored by the
 * admin authentication gateway. The browser never gets to choose reviewer
 * identity, and the endpoint fails closed if an old/non-gateway session reaches
 * this action.
 */

session_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST required.']);
}

if (!isset($_SESSION['admin_logged_in'], $_SESSION['admin_user_id'])
    || $_SESSION['admin_logged_in'] !== true
    || (int)$_SESSION['admin_user_id'] <= 0) {
    respond(401, ['ok' => false, 'error' => 'Authentication required.']);
}

$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    respond(403, ['ok' => false, 'error' => 'Invalid request token.']);
}

$action = $_POST['price_action'] ?? '';
$priceId = (int)($_POST['price_review_id'] ?? 0);
$note = trim((string)($_POST['price_notes'] ?? ''));
$statusMap = ['approve' => 'approved', 'reject' => 'rejected'];
if ($priceId <= 0 || !isset($statusMap[$action])) {
    respond(422, ['ok' => false, 'error' => 'Invalid price review request.']);
}

$envFile = dirname(__DIR__) . '/.env';
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
if ($db->connect_error) {
    respond(503, ['ok' => false, 'error' => 'Database unavailable.']);
}
$db->set_charset('utf8mb4');

$adminId = (int)$_SESSION['admin_user_id'];
$identity = $db->prepare("SELECT id, username FROM admin_users WHERE id=? LIMIT 1");
if (!$identity) {
    respond(503, ['ok' => false, 'error' => 'Admin identity lookup could not be prepared.']);
}
$identity->bind_param('i', $adminId);
if (!$identity->execute()) {
    $identity->close();
    respond(503, ['ok' => false, 'error' => 'Admin identity lookup failed.']);
}
$identity->bind_result($resolvedId, $reviewer);
if (!$identity->fetch() || (int)$resolvedId !== $adminId || $reviewer === '') {
    $identity->close();
    respond(401, ['ok' => false, 'error' => 'Admin identity is no longer valid.']);
}
$identity->close();

$newStatus = $statusMap[$action];
$newReason = $action === 'reject' ? ($note !== '' ? $note : null) : null;

try {
    $db->begin_transaction();

    $read = $db->prepare(
        "SELECT id, status FROM crowdsourced_prices
         WHERE id = ? AND status IN ('pending','flagged')
         FOR UPDATE"
    );
    if (!$read) throw new RuntimeException('review lookup could not be prepared');
    $read->bind_param('i', $priceId);
    if (!$read->execute()) throw new RuntimeException('review lookup failed');
    $read->bind_result($lockedId, $oldStatus);
    if (!$read->fetch()) {
        $read->close();
        throw new RuntimeException('price report is missing or has already been reviewed');
    }
    $read->close();

    $update = $db->prepare(
        "UPDATE crowdsourced_prices
         SET status = ?, flag_reason = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?"
    );
    if (!$update) throw new RuntimeException('review update could not be prepared');
    $update->bind_param('sssi', $newStatus, $newReason, $reviewer, $priceId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('review update failed');
    }
    $update->close();

    $db->commit();
    respond(200, [
        'ok' => true,
        'price_report_id' => $priceId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'reviewed_by' => $reviewer,
    ]);
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $ignored) {}
    // Under PHP 8 mysqli raises mysqli_sql_exception, so getMessage() can carry
    // the query text and column names. Log the detail, return the one fact the
    // reviewer needs: their decision did not save.
    error_log('Price review failed for #' . $priceId . ': ' . $e->getMessage());
    respond(409, ['ok' => false, 'error' => 'The review could not be saved. Reload and try again.']);
}
