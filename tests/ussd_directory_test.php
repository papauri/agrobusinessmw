<?php
/**
 * USSD Find Sellers / Find Buyers, against a real database.
 *
 *   php tests/ussd_directory_test.php   # NEEDS a database (.env + MySQL)
 *
 * Not in tests/run.sh — run.sh is static-only. See tests/README.md.
 *
 * WHAT IT GUARDS
 *   1. A listing whose phone number is NULL says so, instead of rendering
 *      "Name: " with a blank after the colon. `phone_number` is nullable and
 *      every row in production is NULL today, so this was the whole page there,
 *      not a corner case.
 *   2. Every listing says what the contact deals in. That is the whole reason to
 *      ring one of them, and USSD showed nothing at all.
 *   3. A page never exceeds what Africa's Talking will deliver. A CON response
 *      is capped at 182 bytes and truncated mid-line beyond that — which would
 *      cut a phone number in half. Chichewa's back menu is 11 bytes longer than
 *      English's, so both languages are checked.
 *
 * NOT guarded, because it is not reachable: a listing with no contact row at
 * all. `sellers.contact_id` is `int NOT NULL` with an ON DELETE RESTRICT
 * foreign key, so the database refuses to create one. The query uses a LEFT
 * JOIN anyway, to match api.php, but that is defensive and this test does not
 * pretend otherwise.
 *
 * It writes fixtures to the configured database and removes them afterwards.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../ussd/helpers.php';
require __DIR__ . '/../ussd/menus.php';   // defines $menu_texts

$pass = 0;
$fail = 0;
function check(bool $ok, string $what): void
{
    global $pass, $fail;
    if ($ok) { $pass++; printf("  ok    %s\n", $what); }
    else     { $fail++; printf("  FAIL  %s\n", $what); }
}

$db  = agro_db_connect();
$tag = 'USSDTEST' . bin2hex(random_bytes(3));

// A district of its own so the fixtures cannot collide with the seed data.
$districtId = (int)$db->query('SELECT id FROM districts ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$cropIds = [];
$res = $db->query('SELECT id FROM crops ORDER BY id LIMIT 3');
while ($row = $res->fetch_assoc()) $cropIds[] = (int)$row['id'];
if (count($cropIds) < 3) { fwrite(STDERR, "needs three rows in `crops`\n"); exit(1); }

$madeSellers = [];
$madeBuyers  = [];
$madeContacts = ['seller' => [], 'buyer' => []];

// A contact row is always created: `sellers.contact_id` is NOT NULL, so there is
// no such thing as a seller without one. Passing $phone = null gives the row a
// NULL phone_number, which is what production's rows actually look like.
function make_seller(mysqli $db, string $name, int $districtId, ?string $phone, array $cropIds): int
{
    global $madeSellers, $madeContacts;
    $c = $db->prepare('INSERT INTO seller_contact_details (phone_number, address) VALUES (?,?)');
    $addr = 'Test';
    $c->bind_param('ss', $phone, $addr);
    $c->execute();
    $c->close();
    $contactId = (int)$db->insert_id;
    $madeContacts['seller'][] = $contactId;
    $stmt = $db->prepare('INSERT INTO sellers (name, district_id, contact_id) VALUES (?,?,?)');
    $stmt->bind_param('sii', $name, $districtId, $contactId);
    $stmt->execute();
    $stmt->close();
    $id = (int)$db->insert_id;
    $madeSellers[] = $id;
    foreach ($cropIds as $cid) $db->query("INSERT IGNORE INTO seller_crops (seller_id, crop_id) VALUES ($id, $cid)");
    return $id;
}

function make_buyer(mysqli $db, string $name, int $districtId, ?string $phone, array $cropIds): int
{
    global $madeBuyers, $madeContacts;
    $c = $db->prepare('INSERT INTO buyer_contact_details (phone_number, address) VALUES (?,?)');
    $addr = 'Test';
    $c->bind_param('ss', $phone, $addr);
    $c->execute();
    $c->close();
    $contactId = (int)$db->insert_id;
    $madeContacts['buyer'][] = $contactId;
    $stmt = $db->prepare('INSERT INTO buyers (name, district_id, contact_id) VALUES (?,?,?)');
    $stmt->bind_param('sii', $name, $districtId, $contactId);
    $stmt->execute();
    $stmt->close();
    $id = (int)$db->insert_id;
    $madeBuyers[] = $id;
    foreach ($cropIds as $cid) $db->query("INSERT IGNORE INTO buyer_crops (buyer_id, crop_id) VALUES ($id, $cid)");
    return $id;
}

$labels = [
    'en' => ['no_number' => $menu_texts['directory']['no_number']['en'], 'no_crops' => $menu_texts['directory']['no_crops']['en']],
    'ci' => ['no_number' => $menu_texts['directory']['no_number']['ci'], 'no_crops' => $menu_texts['directory']['no_crops']['ci']],
];

try {
    // Baseline: what the district already holds, so the assertions below are
    // about the fixtures and not about the seed data.
    $before = count(ussd_directory_lines($db, 'seller', $districtId, $labels['en']));

    // ── Fixtures ─────────────────────────────────────────────────────────────
    make_seller($db, "$tag Awiche", $districtId, '+265 881 000 001', [$cropIds[0], $cropIds[1]]);
    make_seller($db, "$tag Bemani", $districtId, null, [$cropIds[0]]);                 // NULL phone_number
    make_seller($db, "$tag Chuma",  $districtId, '+265 881 000 003', []);              // NO crops
    make_seller($db, "$tag Dziko",  $districtId, '+265 881 000 004', $cropIds);        // 3 crops → "+1"
    make_seller($db, "$tag Ekari",  $districtId, '+265 881 000 005', [$cropIds[2]]);
    make_seller($db, "$tag Fumbani", $districtId, '+265 881 000 006', [$cropIds[1]]);

    $lines = ussd_directory_lines($db, 'seller', $districtId, $labels['en']);
    check(count($lines) === $before + 6, 'all six sellers are listed');

    // ── 1. The NULL phone number ─────────────────────────────────────────────
    $bemani = array_values(array_filter($lines, fn($l) => str_contains($l, 'Bemani')));
    check(count($bemani) === 1, 'a seller whose phone number is NULL is still listed');
    check(count($bemani) === 1 && str_contains($bemani[0], $labels['en']['no_number']),
        'and says so, instead of leaving a blank after the colon');

    // ── 2. Crops ─────────────────────────────────────────────────────────────
    $awiche = array_values(array_filter($lines, fn($l) => str_contains($l, 'Awiche')))[0] ?? '';
    $cropNames = [];
    $r = $db->query('SELECT name FROM crops WHERE id IN (' . implode(',', array_slice($cropIds, 0, 2)) . ') ORDER BY name');
    while ($row = $r->fetch_assoc()) $cropNames[] = $row['name'];
    check(str_contains($awiche, $cropNames[0]) && str_contains($awiche, $cropNames[1]),
        'a listing names the crops it deals in (' . implode(', ', $cropNames) . ')');

    $chuma = array_values(array_filter($lines, fn($l) => str_contains($l, 'Chuma')))[0] ?? '';
    check(str_contains($chuma, $labels['en']['no_crops']),
        'a listing with no crop links says so rather than trailing off');

    $dziko = array_values(array_filter($lines, fn($l) => str_contains($l, 'Dziko')))[0] ?? '';
    check(str_contains($dziko, '+1'), 'a listing with more crops than fit shows the overflow count');

    // ── 3. Byte budget, both languages ───────────────────────────────────────
    foreach (['en', 'ci'] as $lang) {
        $suffix = $menu_texts['back_option'][$lang];
        $budget = ussd_page_budget($suffix);
        $langLines = ussd_directory_lines($db, 'seller', $districtId, $labels[$lang]);
        $body = ussd_fit_lines($langLines, $budget, $menu_texts['directory']['more'][$lang]);
        $page = 'CON ' . $body . $suffix;

        check(strlen($page) <= 182, "[$lang] the whole CON page fits in 182 bytes (" . strlen($page) . ')');
        check($body !== '', "[$lang] something is actually shown");

        // No partial listings: every line except the "+N more" note must be one
        // of the lines we generated, byte for byte. The note is matched by a
        // pattern built from the template — comparing against the template with
        // {n} blanked would never match "+4 more" and would silently classify
        // the note as a mangled listing.
        $notePattern = '/^' . str_replace('\{n\}', '\d+',
            preg_quote($menu_texts['directory']['more'][$lang], '/')) . '$/u';
        $bodyLines = explode("\n", $body);
        $noteCount = 0;
        $intact = true;
        foreach ($bodyLines as $bl) {
            if (preg_match($notePattern, $bl)) { $noteCount++; continue; }
            if (!in_array($bl, $langLines, true)) $intact = false;
        }
        check($intact, "[$lang] no listing was cut in half to make it fit");

        // The dropped count must be honest.
        $shown = count($bodyLines) - $noteCount;
        if ($noteCount > 0) {
            preg_match('/\+(\d+)/', $bodyLines[count($bodyLines) - 1], $m);
            check((int)($m[1] ?? 0) === count($langLines) - $shown,
                "[$lang] the \"+N more\" count matches what was dropped (" . ($m[1] ?? '?') . ')');
        } else {
            check($shown === count($langLines), "[$lang] everything fit, so no overflow note");
        }
    }

    // ── 4. Buyers behave identically ─────────────────────────────────────────
    make_buyer($db, "$tag Gonthi", $districtId, null, [$cropIds[0]]);   // NULL phone_number
    make_buyer($db, "$tag Hara",   $districtId, '+265 882 000 002', [$cropIds[1]]);
    $bLines = ussd_directory_lines($db, 'buyer', $districtId, $labels['en']);
    $gonthi = array_values(array_filter($bLines, fn($l) => str_contains($l, 'Gonthi')));
    check(count($gonthi) === 1 && str_contains($gonthi[0], $labels['en']['no_number']),
        'a buyer with a NULL phone number says so too');
    $hara = array_values(array_filter($bLines, fn($l) => str_contains($l, 'Hara')))[0] ?? '';
    check($hara !== '' && !str_contains($hara, '*'), 'buyers carry no rating (only sellers are rated)');

    // ── 5. Ratings do not fan out against crops ──────────────────────────────
    // A seller with three crops and two ratings: joined naively, each rating is
    // counted three times. The average is the same either way, but the row
    // count is not — and the next aggregate added here would be wrong.
    $rated = $madeSellers[3];   // "Dziko", three crops
    $db->query("INSERT INTO ratings (seller_id, rating_value) VALUES ($rated, 5), ($rated, 1)");
    $lines = ussd_directory_lines($db, 'seller', $districtId, $labels['en']);
    $dzikoLines = array_values(array_filter($lines, fn($l) => str_contains($l, 'Dziko')));
    check(count($dzikoLines) === 1, 'a seller with several crops and several ratings yields exactly one line');
    check(str_contains($dzikoLines[0], '3.0*'), 'the rating is the average of 5 and 1, not a fanned-out value');

} finally {
    $db->query("DELETE FROM ratings WHERE seller_id IN (" . (implode(',', $madeSellers) ?: '0') . ")");
    foreach ($madeSellers as $id) $db->query("DELETE FROM sellers WHERE id=$id");
    foreach ($madeBuyers  as $id) $db->query("DELETE FROM buyers WHERE id=$id");
    foreach ($madeContacts['seller'] as $id) $db->query("DELETE FROM seller_contact_details WHERE id=$id");
    foreach ($madeContacts['buyer']  as $id) $db->query("DELETE FROM buyer_contact_details WHERE id=$id");
    $left = (int)$db->query("SELECT COUNT(*) c FROM sellers WHERE name LIKE '$tag%'")->fetch_assoc()['c']
          + (int)$db->query("SELECT COUNT(*) c FROM buyers  WHERE name LIKE '$tag%'")->fetch_assoc()['c'];
    check($left === 0, 'fixtures cleaned up');
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
