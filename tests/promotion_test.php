<?php
/**
 * Approval → directory promotion, run against a real database.
 *
 *   php tests/phone_test.php   # no database needed
 *   php tests/promotion_test.php   # THIS ONE NEEDS A DATABASE (.env + MySQL)
 *
 * It is not in tests/run.sh for that reason — run.sh is static-only. See
 * tests/README.md.
 *
 * WHAT IT GUARDS
 *   Approving a seller or buyer must link them to the crops they named at
 *   registration, so the directory can say what they deal in and the crop
 *   filter can find them. Before this, seller_crops / buyer_crops were never
 *   written by any code path and every newly approved contact showed no crops.
 *
 * HOW IT RUNS THE REAL CODE
 *   admin/index.php is a page, not a library: including it starts a session and
 *   renders the login gate. So the two promotion functions are sliced out of
 *   the real source between two anchors and evaluated. That means this test
 *   executes the shipped source text, not a copy of it — and if either anchor
 *   moves, the slice fails loudly instead of quietly testing nothing.
 *
 * It writes to the configured database and cleans up after itself.
 */

require_once __DIR__ . '/../config/database.php';

$pass = 0;
$fail = 0;
function check(bool $ok, string $what): void
{
    global $pass, $fail;
    if ($ok) { $pass++; printf("  ok    %s\n", $what); }
    else     { $fail++; printf("  FAIL  %s\n", $what); }
}

// ─── Load the real promotion functions ───────────────────────────────────────
$adminSrc = file_get_contents(__DIR__ . '/../admin/index.php');
$start = strpos($adminSrc, '/**' . PHP_EOL . ' * Link an approved seller/buyer to the crops');
$end   = strpos($adminSrc, '// ─── HANDLE APPROVE / DENY');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "promotion_test: could not slice the promotion functions out of admin/index.php.\n"
        . "Both anchors must be present and in order:\n"
        . "  start: the docblock beginning '* Link an approved seller/buyer to the crops'\n"
        . "  end:   the comment '// ─── HANDLE APPROVE / DENY'\n");
    exit(1);
}
eval(substr($adminSrc, $start, $end - $start));
check(function_exists('admin_promote_applicant'), 'admin_promote_applicant loaded from admin/index.php');
check(function_exists('admin_link_applicant_crops'), 'admin_link_applicant_crops loaded from admin/index.php');

$db = agro_db_connect();

// A district and two real crops to build the fixture from.
$districtId = (int)$db->query('SELECT id FROM districts ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$crops = [];
$res = $db->query('SELECT id, name FROM crops ORDER BY name LIMIT 2');
while ($row = $res->fetch_assoc()) $crops[(int)$row['id']] = $row['name'];
if (count($crops) < 2) {
    fwrite(STDERR, "promotion_test: needs at least two rows in `crops`.\n");
    exit(1);
}
$cropNames = array_values($crops);
$cropIds   = array_keys($crops);

$tag   = 'PROMOTEST-' . bin2hex(random_bytes(4));
$made  = ['sellers' => [], 'buyers' => [], 'seller_contact_details' => [], 'buyer_contact_details' => []];

/** Build the array shape admin/index.php's SELECT produces for one applicant. */
function fixture(string $type, string $tag, int $districtId, array $cropNames): array
{
    return [
        'user_type'         => $type,
        'full_name'         => $tag . ' ' . ucfirst($type),
        'phone_number'      => '+2659' . random_int(10000000, 99999999),
        'whatsapp_number'   => null,
        'email'             => null,
        'district_id'       => $districtId,
        'village'           => 'Test Village',
        'crops_of_interest' => implode(', ', $cropNames),
    ];
}

try {
    // ── 1. A seller is linked to every crop they named ───────────────────────
    $target = admin_promote_applicant($db, fixture('seller', $tag, $districtId, $cropNames));
    check($target === 'sellers', "promoting a seller returns 'sellers' (got '$target')");

    $sellerId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['sellers'][] = $sellerId;
    check($sellerId > 0, 'the seller directory row was created');

    $linked = [];
    $res = $db->query("SELECT c.name FROM seller_crops sc JOIN crops c ON sc.crop_id=c.id WHERE sc.seller_id=$sellerId ORDER BY c.name");
    while ($row = $res->fetch_assoc()) $linked[] = $row['name'];
    check($linked === $cropNames,
        'seller_crops holds both named crops (' . implode(', ', $linked) . ')');

    // ── 2. The same is true for a buyer ──────────────────────────────────────
    $target = admin_promote_applicant($db, fixture('buyer', $tag, $districtId, $cropNames));
    check($target === 'buyers', "promoting a buyer returns 'buyers' (got '$target')");

    $buyerId = (int)$db->query("SELECT id FROM buyers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['buyers'][] = $buyerId;
    $linked = [];
    $res = $db->query("SELECT c.name FROM buyer_crops bc JOIN crops c ON bc.crop_id=c.id WHERE bc.buyer_id=$buyerId ORDER BY c.name");
    while ($row = $res->fetch_assoc()) $linked[] = $row['name'];
    check($linked === $cropNames, 'buyer_crops holds both named crops (' . implode(', ', $linked) . ')');

    // ── 3. A crop name with no row in `crops` is skipped, not invented ───────
    $app = fixture('seller', $tag, $districtId, [$cropNames[0], 'Notacrop ' . $tag]);
    admin_promote_applicant($db, $app);
    $skipId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['sellers'][] = $skipId;
    $linked = [];
    $res = $db->query("SELECT c.name FROM seller_crops sc JOIN crops c ON sc.crop_id=c.id WHERE sc.seller_id=$skipId");
    while ($row = $res->fetch_assoc()) $linked[] = $row['name'];
    check($linked === [$cropNames[0]], 'an unknown crop name is skipped, the known one still links');

    // ── 4. No crops on the application is not an error ───────────────────────
    $app = fixture('buyer', $tag, $districtId, []);
    $app['crops_of_interest'] = null;
    $target = admin_promote_applicant($db, $app);
    $emptyId = (int)$db->query("SELECT id FROM buyers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['buyers'][] = $emptyId;
    $count = (int)$db->query("SELECT COUNT(*) c FROM buyer_crops WHERE buyer_id=$emptyId")->fetch_assoc()['c'];
    check($target === 'buyers' && $count === 0, 'an application with no crops promotes cleanly with zero links');

    // ── 5. A farmer is still an explicit no-op ───────────────────────────────
    $before = (int)$db->query('SELECT COUNT(*) c FROM sellers')->fetch_assoc()['c']
            + (int)$db->query('SELECT COUNT(*) c FROM buyers')->fetch_assoc()['c'];
    $target = admin_promote_applicant($db, fixture('farmer', $tag, $districtId, $cropNames));
    $after = (int)$db->query('SELECT COUNT(*) c FROM sellers')->fetch_assoc()['c']
           + (int)$db->query('SELECT COUNT(*) c FROM buyers')->fetch_assoc()['c'];
    check($target === '' && $before === $after, 'a farmer is not promoted into any directory table');

} finally {
    // FK cascades take seller_crops / buyer_crops with the parent row.
    foreach ($made['sellers'] as $id) {
        $contact = $db->query("SELECT contact_id FROM sellers WHERE id=$id")->fetch_assoc()['contact_id'] ?? null;
        $db->query("DELETE FROM sellers WHERE id=$id");
        if ($contact) $db->query("DELETE FROM seller_contact_details WHERE id=" . (int)$contact);
    }
    foreach ($made['buyers'] as $id) {
        $contact = $db->query("SELECT contact_id FROM buyers WHERE id=$id")->fetch_assoc()['contact_id'] ?? null;
        $db->query("DELETE FROM buyers WHERE id=$id");
        if ($contact) $db->query("DELETE FROM buyer_contact_details WHERE id=" . (int)$contact);
    }
    $left = (int)$db->query("SELECT COUNT(*) c FROM sellers WHERE name LIKE '$tag%'")->fetch_assoc()['c']
          + (int)$db->query("SELECT COUNT(*) c FROM buyers WHERE name LIKE '$tag%'")->fetch_assoc()['c'];
    check($left === 0, 'fixtures cleaned up');
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
