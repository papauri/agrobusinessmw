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

$db = @new mysqli($_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', $_ENV['DB_NAME'] ?? '', (int)($_ENV['DB_PORT'] ?? 3306));
if ($db->connect_error) { http_response_code(503); exit('Database unavailable.'); }
$db->set_charset('utf8mb4');

$rows = [];
$sql = "SELECT cp.id, c.name AS crop_name, d.name AS district_name, cp.market_name,
               cp.price_per_kg, cp.price_per_bag, cp.unit, cp.submitted_by, cp.channel,
               cp.verified, cp.status, cp.is_member, cp.flag_reason, cp.created_at,
               cp.reviewed_by, cp.reviewed_at
        FROM crowdsourced_prices cp
        JOIN crops c ON cp.crop_id = c.id
        LEFT JOIN districts d ON cp.district_id = d.id
        ORDER BY cp.created_at DESC LIMIT 500";
if ($r = $db->query($sql)) while ($row = $r->fetch_assoc()) $rows[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Price Review Audit — AgroBusiness Malawi</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f2eb;color:#3e3930;font-family:'DM Sans',system-ui,sans-serif}.wrap{max-width:1280px;margin:auto;padding:2rem 1rem}.top{display:flex;justify-content:space-between;gap:1rem;align-items:end;margin-bottom:1.5rem}.eyebrow{text-transform:uppercase;letter-spacing:.08em;color:#8B7355;font-size:.75rem;font-weight:700}.top h1{font-family:'DM Serif Display',serif;font-weight:400;margin:.35rem 0;font-size:2rem}.back{color:#8B7355;font-weight:600}.card{background:#fff;border:1px solid #e8e2d9;border-radius:14px;overflow:auto;box-shadow:0 8px 24px rgba(70,60,50,.08)}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{text-align:left;padding:.8rem;border-bottom:1px solid #eee9e1;vertical-align:top}th{background:#faf8f4;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#6b5f52;position:sticky;top:0}td{font-size:.84rem}.muted{color:#8f8478;font-size:.76rem}.pill{display:inline-block;border-radius:999px;padding:.2rem .55rem;font-size:.7rem;font-weight:700}.pending{background:#fff3cd;color:#8a6500}.approved{background:#dcfce7;color:#166534}.rejected,.flagged{background:#fee2e2;color:#991b1b}.detail{line-height:1.55}.detail strong{color:#3e3930}.empty{padding:2rem;text-align:center;color:#6b5f52}
</style></head>
<body><div class="wrap">
<div class="top"><div><div class="eyebrow">AgroBusiness Malawi · Admin</div><h1>Price Review Audit</h1><p class="muted">Complete history: what was submitted, who submitted it, when it arrived, and who/when it was reviewed.</p></div><a class="back" href="./">← Back to Admin</a></div>
<div class="card">
<?php if (!$rows): ?><div class="empty">No price reports found.</div><?php else: ?>
<table><thead><tr><th>What</th><th>Where</th><th>Price</th><th>Who submitted</th><th>When submitted</th><th>Review</th><th>Reason / notes</th></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr>
<td class="detail"><strong><?= htmlspecialchars($p['crop_name']) ?></strong><br><?= htmlspecialchars($p['unit'] ?: 'kg') ?> · #<?= (int)$p['id'] ?></td>
<td class="detail"><?= htmlspecialchars($p['district_name'] ?? '—') ?><br><?= htmlspecialchars($p['market_name'] ?: 'Market not specified') ?></td>
<td class="detail"><strong>MWK <?= number_format((float)$p['price_per_kg'],2) ?>/kg</strong><br><span class="muted">MWK <?= number_format((float)$p['price_per_bag'],2) ?>/50kg</span></td>
<td class="detail"><strong><?= htmlspecialchars($p['submitted_by'] ?: 'Unknown') ?></strong><br><span class="muted"><?= strtoupper(htmlspecialchars($p['channel'] ?: 'unknown')) ?><?= $p['is_member'] ? ' · Member' : '' ?></span></td>
<td><?= htmlspecialchars($p['created_at']) ?></td>
<td class="detail"><span class="pill <?= htmlspecialchars($p['status']) ?>"><?= strtoupper(htmlspecialchars($p['status'])) ?></span><br><span class="muted"><?= htmlspecialchars($p['reviewed_by'] ?: 'Not yet reviewed') ?> · <?= htmlspecialchars($p['reviewed_at'] ?: '—') ?></span></td>
<td><?= htmlspecialchars($p['flag_reason'] ?: '—') ?></td>
</tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
</div></div></body></html>
