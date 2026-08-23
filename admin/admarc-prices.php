<?php
/**
 * ADMARC official price editor.
 *
 * ADMARC prices are ENTERED BY HAND. There is no feed: admarc.mw stopped
 * resolving, so the live scrape that commit 9de275c built cannot be revived and
 * this page never reaches the network. Whoever types a figure in also records
 * where they got it, because an unattributed official floor price is not worth
 * publishing — farmers use it to judge whether a trader's offer is fair.
 *
 * A price change is a NEW ROW, not an edit: (crop_id, district_id,
 * effective_from) is UNIQUE and api.php serves the newest row that is not in the
 * future. That keeps the history of what the official price was on any given
 * day. Editing in place would silently rewrite that history, so this page only
 * inserts and deletes.
 *
 * Auth and CSRF follow admin/price-review.php: identity comes from the session
 * the gateway established, never from the request.
 */

session_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once dirname(__DIR__) . '/config/database.php';

// Fail closed — an old or non-gateway session does not get in.
if (!isset($_SESSION['admin_logged_in'], $_SESSION['admin_user_id'])
    || $_SESSION['admin_logged_in'] !== true
    || (int)$_SESSION['admin_user_id'] <= 0) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$notice = null;
$error  = null;

try {
    $db = agro_db_connect();
} catch (Throwable $e) {
    // The detail goes nowhere near the browser.
    http_response_code(503);
    echo 'The database is unavailable.';
    exit;
}

$adminName = 'admin';
$who = $db->prepare("SELECT username FROM admin_users WHERE id = ? LIMIT 1");
if ($who) {
    $uid = (int)$_SESSION['admin_user_id'];
    $who->bind_param('i', $uid);
    $who->execute();
    $row = agro_stmt_one($who);
    $who->close();
    if ($row) $adminName = $row['username'];
}

// ─── Write actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid request token. Reload the page and try again.';
    } elseif (($_POST['do'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("DELETE FROM admarc_prices WHERE id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $notice = $st->affected_rows ? 'Price removed.' : 'That price no longer exists.';
            $st->close();
        }
    } elseif (($_POST['do'] ?? '') === 'add') {
        $cropId     = (int)($_POST['crop_id'] ?? 0);
        $districtId = (int)($_POST['district_id'] ?? 0);
        $perKg      = trim((string)($_POST['price_per_kg'] ?? ''));
        $perBagRaw  = trim((string)($_POST['price_per_bag'] ?? ''));
        $season     = trim((string)($_POST['season'] ?? ''));
        $effective  = trim((string)($_POST['effective_from'] ?? ''));
        $source     = trim((string)($_POST['source_note'] ?? ''));

        $d = DateTime::createFromFormat('Y-m-d', $effective);
        $dateOk = $d && $d->format('Y-m-d') === $effective;

        if ($cropId <= 0) {
            $error = 'Choose a crop.';
        } elseif (!is_numeric($perKg) || (float)$perKg <= 0) {
            $error = 'Price per kg must be a number greater than zero.';
        } elseif ($perBagRaw !== '' && (!is_numeric($perBagRaw) || (float)$perBagRaw <= 0)) {
            $error = 'Price per bag must be a number greater than zero, or left blank.';
        } elseif (!$dateOk) {
            $error = 'Effective date must be a real date (YYYY-MM-DD).';
        } elseif ($source === '') {
            // Deliberately mandatory. See the file header.
            $error = 'Record where this figure came from — an ADMARC or Ministry notice, and its date.';
        } else {
            $perKgF  = (float)$perKg;
            $perBagF = $perBagRaw === '' ? null : (float)$perBagRaw;
            $seasonV = $season === '' ? null : $season;

            $st = $db->prepare(
                "INSERT INTO admarc_prices
                    (crop_id, district_id, price_per_kg, price_per_bag, unit, season, effective_from, source_note, set_by)
                 VALUES (?,?,?,?, 'kg', ?,?,?,?)"
            );
            if (!$st) {
                $error = 'Could not save the price.';
            } else {
                $st->bind_param('iiddssss', $cropId, $districtId, $perKgF, $perBagF, $seasonV, $effective, $source, $adminName);
                if ($st->execute()) {
                    $notice = 'Price saved.';
                } else {
                    // 1062 is the UNIQUE (crop, district, effective_from) key.
                    $error = $db->errno === 1062
                        ? 'A price for that crop, scope and effective date already exists. Delete it first, or use a different effective date.'
                        : 'Could not save the price.';
                }
                $st->close();
            }
        }
    }
}

// ─── Read for display ───────────────────────────────────────────────────────
$crops = [];
$r = $db->query("SELECT id, name FROM crops ORDER BY name");
while ($r && $x = $r->fetch_assoc()) $crops[] = $x;

$districts = [];
$r = $db->query("SELECT id, name FROM districts ORDER BY name");
while ($r && $x = $r->fetch_assoc()) $districts[] = $x;

$prices = [];
$r = $db->query(
    "SELECT a.*, c.name AS crop_name, d.name AS district_name,
            (a.effective_from > CURDATE()) AS future
     FROM admarc_prices a
     JOIN crops c ON c.id = a.crop_id
     LEFT JOIN districts d ON d.id = a.district_id
     ORDER BY c.name, a.district_id, a.effective_from DESC"
);
while ($r && $x = $r->fetch_assoc()) $prices[] = $x;

$token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ADMARC Prices — AgroBusiness Malawi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', system-ui, sans-serif; background: #f5f2eb; color: #3e3930; min-height: 100vh; }
a { color: #8B7355; text-decoration: none; }
a:hover { color: #7a6448; }
.top-bar { background: #fff; border-bottom: 1px solid #e8e2d9; padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(70,60,50,0.06); }
.top-bar h1 { font-family: 'DM Serif Display', Georgia, serif; font-size: 1.25rem; font-weight: 400; }
.top-bar small { color: #6b5f52; }
.container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
.card { background: #fff; border: 1px solid #e8e2d9; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
.card h2 { font-family: 'DM Serif Display', Georgia, serif; font-weight: 400; font-size: 1.1rem; margin-bottom: .35rem; }
.card p.hint { color: #6b5f52; font-size: .85rem; margin-bottom: 1.25rem; line-height: 1.5; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; }
label { display: block; font-size: .78rem; color: #6b5f52; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .4rem; }
input, select { width: 100%; min-height: 44px; padding: .7rem .85rem; border: 1px solid #d5cfc4; border-radius: 8px; background: #fff; color: #3e3930; font-size: .9rem; font-family: inherit; }
input:focus, select:focus { outline: none; border-color: #8B7355; box-shadow: 0 0 0 3px rgba(139,115,85,0.1); }
.btn { min-height: 44px; padding: .7rem 1.4rem; background: #8B7355; border: none; border-radius: 8px; color: #fff; cursor: pointer; font-size: .875rem; font-weight: 600; font-family: inherit; }
.btn:hover { background: #7a6448; }
.btn-del { min-height: 44px; min-width: 44px; padding: .5rem 1rem; background: transparent; border: 1.5px solid #d5cfc4; border-radius: 6px; color: #6b5f52; cursor: pointer; font-size: .8rem; font-weight: 600; font-family: inherit; }
.btn-del:hover { background: #b94040; border-color: #b94040; color: #fff; }
.msg { padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; font-size: .9rem; }
.msg-ok  { background: rgba(74,124,89,.1); border: 1px solid rgba(74,124,89,.3); color: #4a7c59; }
.msg-err { background: rgba(185,64,64,.1); border: 1px solid rgba(185,64,64,.3); color: #b94040; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e8e2d9; }
th { background: #f5f2eb; padding: .875rem 1rem; text-align: left; font-size: .78rem; color: #6b5f52; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; border-bottom: 2px solid #d5cfc4; white-space: nowrap; }
td { padding: .75rem 1rem; border-bottom: 1px solid #ede9e0; font-size: .875rem; vertical-align: middle; }
tr:nth-child(even) td { background: #faf8f4; }
tr:last-child td { border-bottom: none; }
.badge { display: inline-block; padding: .3rem .7rem; border-radius: 20px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
.badge-nat { background: rgba(139,115,85,.12); color: #8B7355; }
.badge-dist { background: rgba(200,134,10,.14); color: #c8860a; }
.badge-future { background: rgba(245,158,11,.12); color: #b87c0b; }
.empty { text-align: center; padding: 3rem 1rem; color: #6b5f52; }
.src { color: #6b5f52; font-size: .8rem; max-width: 26ch; }
@media (max-width: 560px) { .top-bar, .container { padding-left: 1rem; padding-right: 1rem; } }
</style>
</head>
<body>
<div class="top-bar">
  <div>
    <h1>ADMARC Official Prices</h1>
    <small>Signed in as <?= h($adminName) ?></small>
  </div>
  <a href="index.php">&larr; Back to admin</a>
</div>

<div class="container">

  <?php if ($notice): ?><div class="msg msg-ok"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg msg-err"><?= h($error) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add a price</h2>
    <p class="hint">
      These figures are typed in by hand — ADMARC publishes no feed this site can read.
      A change is recorded as a new row with its own effective date, so the older
      figure stays on record; the site shows the most recent one that has taken effect.
      Leave the district as <strong>All districts</strong> unless ADMARC set a
      price for one district specifically.
    </p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
      <input type="hidden" name="do" value="add">
      <div class="grid">
        <div>
          <label for="crop_id">Crop</label>
          <select id="crop_id" name="crop_id" required>
            <option value="">Choose…</option>
            <?php foreach ($crops as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="district_id">Scope</label>
          <select id="district_id" name="district_id">
            <option value="0">All districts (national)</option>
            <?php foreach ($districts as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="price_per_kg">Price per kg (MWK)</label>
          <input id="price_per_kg" name="price_per_kg" type="number" step="0.01" min="0.01" required>
        </div>
        <div>
          <label for="price_per_bag">Per 50kg bag (optional)</label>
          <input id="price_per_bag" name="price_per_bag" type="number" step="0.01" min="0.01">
        </div>
        <div>
          <label for="effective_from">Effective from</label>
          <input id="effective_from" name="effective_from" type="date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div>
          <label for="season">Season (optional)</label>
          <input id="season" name="season" type="text" placeholder="2026/27" maxlength="32">
        </div>
      </div>
      <div style="margin-top:1rem">
        <label for="source_note">Source — required</label>
        <input id="source_note" name="source_note" type="text" maxlength="255" required
               placeholder="e.g. ADMARC notice, 12 August 2026 / Ministry of Agriculture press release">
      </div>
      <div style="margin-top:1.25rem">
        <button class="btn" type="submit">Save price</button>
      </div>
    </form>
  </div>

  <div class="card" style="padding-bottom:.5rem">
    <h2>Current prices</h2>
    <p class="hint">
      <?= count($prices) ?> recorded.
      A row marked <span class="badge badge-future">not yet in effect</span> is staged for a
      future date and is not shown on the site until then.
    </p>
  </div>

  <?php if (!$prices): ?>
    <div class="card empty">
      No ADMARC prices recorded yet. Until one is added, the site shows only the
      Global Benchmark rate and community-reported prices — it does not display
      a placeholder or a guess.
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Crop</th><th>Scope</th><th>Per kg</th><th>Per bag</th>
            <th>Effective</th><th>Season</th><th>Source</th><th>Set by</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($prices as $p): ?>
          <tr>
            <td><strong><?= h($p['crop_name']) ?></strong></td>
            <td>
              <?php if ((int)$p['district_id'] === 0): ?>
                <span class="badge badge-nat">National</span>
              <?php else: ?>
                <span class="badge badge-dist"><?= h($p['district_name'] ?? 'Unknown') ?></span>
              <?php endif; ?>
            </td>
            <td>MK <?= h(number_format((float)$p['price_per_kg'], 2)) ?></td>
            <td><?= $p['price_per_bag'] !== null ? 'MK ' . h(number_format((float)$p['price_per_bag'], 2)) : '—' ?></td>
            <td>
              <?= h($p['effective_from']) ?>
              <?php if ($p['future']): ?><br><span class="badge badge-future">not yet in effect</span><?php endif; ?>
            </td>
            <td><?= h($p['season'] ?? '—') ?></td>
            <td class="src"><?= h($p['source_note'] ?? '—') ?></td>
            <td><?= h($p['set_by']) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Remove this ADMARC price? This deletes the record of what the official price was from <?= h($p['effective_from']) ?>.');">
                <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn-del" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
