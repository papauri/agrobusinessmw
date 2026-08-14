<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: ./');
    exit;
}

$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
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
    http_response_code(503);
    exit('Database unavailable.');
}
$db->set_charset('utf8mb4');

/*
 * This page is deliberately backed by price_review_audit rather than only
 * crowdsourced_prices. The latter contains current state; the audit table is
 * the historical source of truth for what changed, when, and by whom.
 */
$rows = [];
$sql = "SELECT
            pra.id AS audit_id,
            pra.price_report_id,
            pra.event_type,
            c.name AS crop_name,
            d.name AS district_name,
            pra.market_name,
            pra.price_per_kg,
            pra.price_per_bag,
            pra.unit,
            pra.submitted_by,
            pra.channel,
            pra.verified,
            pra.status,
            pra.is_member,
            pra.flag_reason,
            pra.reviewed_by,
            pra.reviewed_at,
            pra.old_status,
            pra.new_status,
            pra.old_price_per_kg,
            pra.new_price_per_kg,
            pra.old_price_per_bag,
            pra.new_price_per_bag,
            pra.old_market_name,
            pra.new_market_name,
            pra.old_flag_reason,
            pra.new_flag_reason,
            pra.event_at
        FROM price_review_audit pra
        LEFT JOIN crops c ON pra.crop_id = c.id
        LEFT JOIN districts d ON pra.district_id = d.id
        ORDER BY pra.event_at DESC, pra.id DESC
        LIMIT 1000";

if ($r = $db->query($sql)) {
    while ($row = $r->fetch_assoc()) $rows[] = $row;
}

$eventCounts = [];
foreach ($rows as $row) {
    $event = $row['event_type'] ?: 'unknown';
    $eventCounts[$event] = ($eventCounts[$event] ?? 0) + 1;
}
ksort($eventCounts);

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    if ($value === null || $value === '') return '—';
    return 'MWK ' . number_format((float)$value, 2);
}

function event_class(string $event): string {
    if ($event === 'approved') return 'approved';
    if ($event === 'rejected' || $event === 'denied') return 'rejected';
    if ($event === 'flagged') return 'flagged';
    if ($event === 'submitted' || $event === 'baseline') return 'submitted';
    return 'changed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Price Review Audit — AgroBusiness Malawi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/footer.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f2eb;color:#3e3930;font-family:'DM Sans',system-ui,sans-serif}
.wrap{max-width:1400px;margin:auto;padding:2rem 1rem}
.top{display:flex;justify-content:space-between;gap:1rem;align-items:end;margin-bottom:1.5rem}
.eyebrow{text-transform:uppercase;letter-spacing:.08em;color:#8B7355;font-size:.75rem;font-weight:700}
.top h1{font-family:'DM Serif Display',serif;font-weight:400;margin:.35rem 0;font-size:2rem}
.top p{margin:.25rem 0 0;color:#6b5f52;max-width:780px;line-height:1.5}
.back{color:#8B7355;font-weight:600}
.stats{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem}
.stat{background:#fff;border:1px solid #e8e2d9;border-radius:10px;padding:.65rem .9rem;font-size:.8rem;color:#6b5f52}
.stat strong{color:#3e3930;margin-left:.25rem}
.card{background:#fff;border:1px solid #e8e2d9;border-radius:14px;overflow:auto;box-shadow:0 8px 24px rgba(70,60,50,.08)}
table{width:100%;border-collapse:collapse;min-width:1250px}
th,td{text-align:left;padding:.75rem;border-bottom:1px solid #eee9e1;vertical-align:top}
th{background:#faf8f4;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#6b5f52;position:sticky;top:0;z-index:1}
td{font-size:.82rem}.muted{color:#8f8478;font-size:.74rem}.detail{line-height:1.5}.detail strong{color:#3e3930}
.pill{display:inline-block;border-radius:999px;padding:.2rem .55rem;font-size:.68rem;font-weight:700;text-transform:uppercase}
.approved{background:#dcfce7;color:#166534}.rejected,.flagged{background:#fee2e2;color:#991b1b}.submitted{background:#e8f0ff;color:#315a9b}.changed{background:#f3e8ff;color:#6b21a8}
.diff{margin-top:.35rem;padding:.35rem .5rem;background:#faf8f4;border-radius:6px;font-size:.72rem;line-height:1.45}
.empty{padding:2rem;text-align:center;color:#6b5f52}
@media(max-width:700px){.top{align-items:start;flex-direction:column}.wrap{padding:1rem .75rem}}
</style>
</head>
<body>
<div class="wrap">
<div class="top">
    <div>
        <div class="eyebrow">AgroBusiness Malawi · Admin</div>
        <h1>Price Review Audit</h1>
        <p>Immutable review history: what was submitted, where it came from, what changed, who reviewed it, and when the event occurred.</p>
    </div>
    <a class="back" href="./">← Back to Admin</a>
</div>

<div class="stats">
    <div class="stat">Audit events <strong><?= count($rows) ?></strong></div>
    <?php foreach ($eventCounts as $event => $count): ?>
        <div class="stat"><?= h($event) ?> <strong><?= (int)$count ?></strong></div>
    <?php endforeach; ?>
</div>

<div class="card">
<?php if (!$rows): ?>
    <div class="empty">No audit history found.</div>
<?php else: ?>
<table>
<thead><tr>
    <th>Event</th>
    <th>Report</th>
    <th>What</th>
    <th>Where</th>
    <th>Submitted by</th>
    <th>Price</th>
    <th>Status change</th>
    <th>Price change</th>
    <th>Reviewer</th>
    <th>Reason / change</th>
    <th>When</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $p): ?>
<tr>
    <td><span class="pill <?= h(event_class($p['event_type'])) ?>"><?= h($p['event_type']) ?></span></td>
    <td class="detail"><strong>#<?= (int)$p['price_report_id'] ?></strong><br><span class="muted">audit #<?= (int)$p['audit_id'] ?></span></td>
    <td class="detail"><strong><?= h($p['crop_name'] ?: 'Unknown crop') ?></strong><br><?= h($p['unit'] ?: 'kg') ?></td>
    <td class="detail"><?= h($p['district_name'] ?: '—') ?><br><?= h($p['market_name'] ?: 'Market not specified') ?></td>
    <td class="detail"><strong><?= h($p['submitted_by'] ?: 'Unknown') ?></strong><br><span class="muted"><?= strtoupper(h($p['channel'] ?: 'unknown')) ?><?= $p['is_member'] ? ' · Member' : '' ?></span></td>
    <td class="detail"><strong><?= money($p['price_per_kg']) ?>/kg</strong><br><span class="muted"><?= money($p['price_per_bag']) ?>/bag</span></td>
    <td>
        <?php if ($p['old_status'] !== null || $p['new_status'] !== null): ?>
            <div class="diff"><strong><?= h($p['old_status'] ?: '—') ?></strong> → <strong><?= h($p['new_status'] ?: '—') ?></strong></div>
        <?php else: ?>—<?php endif; ?>
    </td>
    <td>
        <?php if ($p['old_price_per_kg'] !== null || $p['new_price_per_kg'] !== null): ?>
            <div class="diff"><?= money($p['old_price_per_kg']) ?> → <?= money($p['new_price_per_kg']) ?></div>
        <?php else: ?>—<?php endif; ?>
    </td>
    <td class="detail">
        <strong><?= h($p['reviewed_by'] ?: 'Not reviewed') ?></strong><br>
        <span class="muted"><?= h($p['reviewed_at'] ?: '—') ?></span>
    </td>
    <td class="detail">
        <?= h($p['new_flag_reason'] ?: $p['flag_reason'] ?: '—') ?>
        <?php if ($p['old_market_name'] !== null && $p['new_market_name'] !== null && $p['old_market_name'] !== $p['new_market_name']): ?>
            <div class="diff">Market: <?= h($p['old_market_name']) ?> → <?= h($p['new_market_name']) ?></div>
        <?php endif; ?>
    </td>
    <td class="muted"><?= h($p['event_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
