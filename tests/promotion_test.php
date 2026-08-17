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

// ─── Load the real code out of admin/index.php ───────────────────────────────
/**
 * Return the source between two anchors, or fail loudly.
 *
 * Loud is the point. If an anchor moves, this must stop the run rather than
 * quietly evaluate an empty string and report a suite that tested nothing.
 */
function admin_slice(string $startAnchor, string $endAnchor): string
{
    static $src = null;
    if ($src === null) $src = file_get_contents(__DIR__ . '/../admin/index.php');

    $start = strpos($src, $startAnchor);
    $end   = strpos($src, $endAnchor);
    if ($start === false || $end === false || $end <= $start) {
        fwrite(STDERR, "promotion_test: could not slice admin/index.php.\n"
            . "  start anchor: " . var_export($startAnchor, true) . ($start === false ? "  NOT FOUND\n" : "\n")
            . "  end anchor:   " . var_export($endAnchor, true) . ($end === false ? "  NOT FOUND\n" : "\n"));
        exit(1);
    }
    return substr($src, $start, $end - $start);
}

eval(admin_slice(
    '/**' . PHP_EOL . ' * Link an approved seller/buyer to the crops',
    '// ─── HANDLE APPROVE / DENY'
));
check(function_exists('admin_promote_applicant'), 'admin_promote_applicant loaded from admin/index.php');
check(function_exists('admin_link_applicant_crops'), 'admin_link_applicant_crops loaded from admin/index.php');
check(function_exists('admin_demote_applicant'), 'admin_demote_applicant loaded from admin/index.php');
check(function_exists('admin_find_directory_row'), 'admin_find_directory_row loaded from admin/index.php');

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

    // ── 6. Denial removes the listing ────────────────────────────────────────
    // The defect this closes: the application said "denied" and the site went on
    // publishing the person's name and phone number.
    $denied = fixture('seller', $tag, $districtId, $cropNames);
    admin_promote_applicant($db, $denied);
    $deniedId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $deniedContact = (int)$db->query("SELECT contact_id FROM sellers WHERE id=$deniedId")->fetch_assoc()['contact_id'];
    $cropLinks = (int)$db->query("SELECT COUNT(*) c FROM seller_crops WHERE seller_id=$deniedId")->fetch_assoc()['c'];
    check($cropLinks === 2, 'the seller is listed with their crops before denial');

    $target = admin_demote_applicant($db, $denied);
    check($target === 'sellers', "denying an approved seller returns 'sellers' (got '$target')");
    check((int)$db->query("SELECT COUNT(*) c FROM sellers WHERE id=$deniedId")->fetch_assoc()['c'] === 0,
        'the directory row is gone');
    check((int)$db->query("SELECT COUNT(*) c FROM seller_contact_details WHERE id=$deniedContact")->fetch_assoc()['c'] === 0,
        'the contact row is gone too, not orphaned');
    check((int)$db->query("SELECT COUNT(*) c FROM seller_crops WHERE seller_id=$deniedId")->fetch_assoc()['c'] === 0,
        'the crop links went with it (FK cascade)');

    // ── 7. Re-approval after denial works ────────────────────────────────────
    // It did not before. `uniq_seller_contact_phone` rejected the second contact
    // insert, so the second approval threw and rolled back and the applicant
    // could never be approved again. Freeing the number on denial is the fix.
    $target = admin_promote_applicant($db, $denied);
    check($target === 'sellers', "the same applicant can be approved again (got '$target')");
    $againId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['sellers'][] = $againId;
    check($againId !== $deniedId, 'and gets a fresh directory row');
    check((int)$db->query("SELECT COUNT(*) c FROM seller_crops WHERE seller_id=$againId")->fetch_assoc()['c'] === 2,
        'with their crops linked again');

    // ── 8. Promoting an already-listed applicant is a no-op, not a crash ─────
    $target = admin_promote_applicant($db, $denied);
    check($target === '', 'promoting someone already listed returns "" instead of raising a duplicate key');
    check((int)$db->query("SELECT COUNT(*) c FROM sellers WHERE name LIKE '$tag%' AND id >= $againId")->fetch_assoc()['c'] === 1,
        'and does not create a second row');

    // ── 9. Denying someone who was never listed is not an error ──────────────
    $never = fixture('buyer', $tag, $districtId, $cropNames);
    $target = admin_demote_applicant($db, $never);
    check($target === '', 'denying an applicant with no directory row returns "" quietly');

    // ── 10. A farmer denial is a no-op ───────────────────────────────────────
    $target = admin_demote_applicant($db, fixture('farmer', $tag, $districtId, $cropNames));
    check($target === '', 'denying a farmer touches no directory table');

    // ── 11. Denial removes ONLY that person ──────────────────────────────────
    // The match is the contact's UNIQUE phone number, so a namesake in the same
    // district with a different number must survive. This is the assertion that
    // would catch a match widened to the name.
    $keepA = fixture('seller', $tag, $districtId, $cropNames);
    $keepB = fixture('seller', $tag, $districtId, $cropNames);
    $keepB['full_name'] = $keepA['full_name'];        // same name, different phone
    admin_promote_applicant($db, $keepA);
    $keepAId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    admin_promote_applicant($db, $keepB);
    $keepBId = (int)$db->query("SELECT id FROM sellers WHERE name LIKE '$tag%' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
    $made['sellers'][] = $keepBId;
    admin_demote_applicant($db, $keepA);
    check((int)$db->query("SELECT COUNT(*) c FROM sellers WHERE id=$keepAId")->fetch_assoc()['c'] === 0,
        'the denied namesake is removed');
    check((int)$db->query("SELECT COUNT(*) c FROM sellers WHERE id=$keepBId")->fetch_assoc()['c'] === 1,
        'the other namesake, on a different number, is untouched');

    // ── 12. The REVIEW HANDLER itself, not just the functions ────────────────
    //
    // Everything above calls admin_demote_applicant() directly, which proves the
    // function and says nothing about whether the admin panel ever calls it.
    // Deleting the deny branch from the handler leaves all of it passing — I
    // checked. So this section evaluates the real handler block, with a real
    // onboarding_applications row, and drives it through approve → deny →
    // approve exactly as the form does.
    $handler = admin_slice(
        '// ─── HANDLE APPROVE / DENY',
        '// ─── HANDLE COMMUNITY PRICE REVIEW'
    );

    $ref = 'AGR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $appPhone = '+2659' . random_int(10000000, 99999999);
    $ins = $db->prepare(
        "INSERT INTO onboarding_applications
            (application_ref, user_type, full_name, phone_number, district_id, village, crops_of_interest, channel, status)
         VALUES (?,'seller',?,?,?,'Test Village',?, 'web','pending')"
    );
    $applicantName = "$tag Handler";
    $cropsCsv = implode(', ', $cropNames);
    $ins->bind_param('sssis', $ref, $applicantName, $appPhone, $districtId, $cropsCsv);
    $ins->execute();
    $ins->close();
    $appId = (int)$db->insert_id;

    // The handler reads $_POST and $_SERVER, and calls csrf_valid(). The token
    // check is admin/index.php's own and is not part of what this exercises, so
    // it is stubbed — the CSRF behaviour is a separate concern from whether a
    // denial reaches the directory.
    if (!function_exists('csrf_valid')) {
        eval('function csrf_valid(): bool { return true; }');
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';

    /** Run the real handler once, returning [message, error]. */
    $runHandler = function (string $verb, int $applicationId) use ($handler, $db): array {
        $_POST = ['review_id' => (string)$applicationId, 'review_action' => $verb, 'review_notes' => ''];
        $actionMsg = $actionErr = '';
        eval($handler);
        return [$actionMsg, $actionErr];
    };

    [$msg, $err] = $runHandler('approve', $appId);
    $listed = (int)$db->query("SELECT COUNT(*) c FROM sellers s JOIN seller_contact_details d ON s.contact_id=d.id WHERE d.phone_number='$appPhone'")->fetch_assoc()['c'];
    $status = $db->query("SELECT status FROM onboarding_applications WHERE id=$appId")->fetch_assoc()['status'];
    check($err === '' && $listed === 1 && $status === 'approved',
        "the handler approves and lists the seller (listed=$listed status=$status err=" . ($err ?: 'none') . ')');
    check(str_contains($msg, 'Added to the sellers directory'), "and says so: \"$msg\"");

    [$msg, $err] = $runHandler('deny', $appId);
    $listed = (int)$db->query("SELECT COUNT(*) c FROM sellers s JOIN seller_contact_details d ON s.contact_id=d.id WHERE d.phone_number='$appPhone'")->fetch_assoc()['c'];
    $orphan = (int)$db->query("SELECT COUNT(*) c FROM seller_contact_details WHERE phone_number='$appPhone'")->fetch_assoc()['c'];
    $status = $db->query("SELECT status FROM onboarding_applications WHERE id=$appId")->fetch_assoc()['status'];
    check($err === '' && $listed === 0 && $orphan === 0 && $status === 'denied',
        "DENYING THROUGH THE HANDLER REMOVES THE LISTING (listed=$listed orphan=$orphan status=$status err=" . ($err ?: 'none') . ')');
    check(str_contains($msg, 'Removed from the sellers directory'), "and says so: \"$msg\"");

    [$msg, $err] = $runHandler('approve', $appId);
    $listed = (int)$db->query("SELECT COUNT(*) c FROM sellers s JOIN seller_contact_details d ON s.contact_id=d.id WHERE d.phone_number='$appPhone'")->fetch_assoc()['c'];
    $status = $db->query("SELECT status FROM onboarding_applications WHERE id=$appId")->fetch_assoc()['status'];
    check($err === '' && $listed === 1 && $status === 'approved',
        "and the applicant can be re-approved afterwards (listed=$listed status=$status err=" . ($err ?: 'none') . ')');

    // Leave nothing behind.
    $leftover = $db->query("SELECT s.id, s.contact_id FROM sellers s JOIN seller_contact_details d ON s.contact_id=d.id WHERE d.phone_number='$appPhone'")->fetch_assoc();
    if ($leftover) {
        $made['sellers'][] = (int)$leftover['id'];
    }
    $db->query("DELETE FROM onboarding_applications WHERE id=$appId");

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
