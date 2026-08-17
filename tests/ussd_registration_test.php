<?php
/**
 * Registration over USSD, driven through the real handler.
 *
 *   php tests/ussd_registration_test.php   # NEEDS a database (.env + MySQL)
 *
 * Not in tests/run.sh — run.sh is static-only. See tests/README.md.
 *
 * It calls process_ussd() with the gateway's own POST fields, exactly as
 * ussd/index.php does, so what is under test is the whole path: menu → step
 * machine → config/registration.php → the INSERT. Testing the step function on
 * its own would prove the questions and say nothing about whether the menu ever
 * reaches them; that mistake was made once already, in promotion_test.php.
 *
 * WHAT IT GUARDS
 *   - A farmer with a feature phone can actually complete a registration, and
 *     the row lands with channel='ussd' and the caller's own MSISDN.
 *   - Crop 9 is selectable. This is why registration does not go through
 *     parse_navigation(): that reads '9' as Next Page, so the ninth crop would
 *     be swallowed by pagination and silently never chosen.
 *   - A gateway retry — the same accumulated text delivered twice — does not
 *     advance the flow or insert twice.
 *   - Somebody already registered is told so before being asked six questions.
 *   - Sellers and buyers are asked for a business name; farmers are not.
 *   - Every page fits the 182-character CON limit in both languages.
 */

require_once __DIR__ . '/../config/database.php';

// The same include set as ussd/index.php, in the same order.
$_POST = [];
require_once __DIR__ . '/../ussd/config.php';        // defines $mysqli, $district_coords
require_once __DIR__ . '/../ussd/menus.php';         // $menu_texts, $valid_options
require_once __DIR__ . '/../ussd/helpers.php';
require_once __DIR__ . '/../ussd/weather.php';
require_once __DIR__ . '/../ussd/registration.php';
require_once __DIR__ . '/../ussd/logic.php';         // $district_map, process_ussd()

$pass = 0;
$fail = 0;
function check(bool $ok, string $what): void
{
    global $pass, $fail;
    if ($ok) { $pass++; printf("  ok    %s\n", $what); }
    else     { $fail++; printf("  FAIL  %s\n", $what); }
}

$db = agro_db_connect();

/** One gateway request. $text is the FULL accumulated input, as AT sends it. */
function ussd(string $session, string $phone, string $text): string
{
    global $mysqli, $menu_texts, $valid_options, $practice_types;
    $_POST = [
        'sessionId'   => $session,
        'phoneNumber' => $phone,
        'serviceCode' => '*384*1#',
        'text'        => $text,
    ];
    return process_ussd($mysqli, $menu_texts, $valid_options, $practice_types ?? []);
}

/** Walk a list of accumulated-text states, returning the last response. */
function walk(string $session, string $phone, array $texts): string
{
    $out = '';
    foreach ($texts as $t) $out = ussd($session, $phone, $t);
    return $out;
}

// +265 then a 9-digit national number starting 8 or 9 — the only shape
// config/phone.php accepts for Malawi. An earlier version of this helper emitted
// +26588 + 8 digits, which is a TEN-digit national part; every registration was
// correctly refused and the test looked like a code failure.
function fresh_phone(): string { return '+2658' . random_int(10000000, 99999999); }
function fresh_session(): string { return 'test' . bin2hex(random_bytes(4)); }

// Every fixture name carries a per-run tag. Without it the cleanup check also
// counts rows left behind by manual testing against the same database, and
// reports them as this run's leak.
$tag = 'T' . bin2hex(random_bytes(3));
$made = [];
$sessions = [];

try {
    // ── 1. The option exists and the pages fit ───────────────────────────────
    foreach (['en' => '10 Register', 'ci' => '10 Lembetsani'] as $lang => $needle) {
        check(str_contains($menu_texts['main_menu'][$lang], $needle),
            "[$lang] the main menu offers registration (\"$needle\")");
        check(mb_strlen('CON ' . $menu_texts['main_menu'][$lang]) <= 182,
            "[$lang] the main menu fits 182 chars (" . mb_strlen('CON ' . $menu_texts['main_menu'][$lang]) . ')');
    }
    $over = [];
    foreach ($menu_texts['registration'] as $key => $entry) {
        foreach (['en', 'ci'] as $lang) {
            $len = mb_strlen('CON ' . ($entry[$lang] ?? ''));
            if ($len > 182) $over[] = "$key/$lang=$len";
        }
    }
    check(!$over, 'every registration page fits 182 chars (' . (implode(', ', $over) ?: 'all') . ')');

    // ── 2. A farmer registers, start to finish ───────────────────────────────
    $phone = fresh_phone();
    $s = fresh_session(); $sessions[] = $s;
    $steps = [
        '1',
        '1*10',
        '1*10*1',
        '1*10*1*Thandiwe Mwale '.$tag.'',
        '1*10*1*Thandiwe Mwale '.$tag.'*1',
        '1*10*1*Thandiwe Mwale '.$tag.'*1*Chilomoni',
        '1*10*1*Thandiwe Mwale '.$tag.'*1*Chilomoni*1,5',
    ];
    $confirmPage = walk($s, $phone, $steps);
    check(str_contains($confirmPage, 'Thandiwe Mwale ' . $tag) && str_contains($confirmPage, '1 Send'),
        'the confirmation page summarises the application');
    check(!str_contains($confirmPage, 'business') && !str_contains($confirmPage, 'Business'),
        'a farmer is never asked for a business name');

    $done = ussd($s, $phone, '1*10*1*Thandiwe Mwale '.$tag.'*1*Chilomoni*1,5*1');
    check(str_starts_with($done, 'END'), 'submitting ends the session');
    preg_match('/AGR-\d{8}-[A-Z0-9]+/', $done, $m);
    $ref = $m[0] ?? '';
    check($ref !== '', "and returns an application reference ($ref)");
    if ($ref) $made[] = $ref;

    $row = $db->query("SELECT * FROM onboarding_applications WHERE application_ref='" . $db->real_escape_string($ref) . "'")->fetch_assoc();
    check($row !== null, 'the application is in the database');
    check(($row['channel'] ?? '') === 'ussd', "stored with channel='ussd' (got '" . ($row['channel'] ?? '') . "')");
    check(($row['phone_number'] ?? '') === $phone, "with the CALLER'S OWN number, never typed (" . ($row['phone_number'] ?? '') . ')');
    check(($row['user_type'] ?? '') === 'farmer', 'as a farmer');
    check(($row['village'] ?? '') === 'Chilomoni', 'with their village');
    check(($row['status'] ?? '') === 'pending', 'pending review, like every other channel');

    // Crops 1 and 5 of the name-ordered list.
    $expected = [];
    $r = $db->query('SELECT name FROM crops ORDER BY name ASC');
    $all = [];
    while ($c = $r->fetch_assoc()) $all[] = $c['name'];
    $expected = [$all[0], $all[4]];
    sort($expected);
    $stored = array_map('trim', explode(',', (string)($row['crops_of_interest'] ?? '')));
    sort($stored);
    check($stored === $expected, 'and the crops they picked (' . implode(', ', $stored) . ')');

    // ── 3. Crop 9 — the reason registration bypasses parse_navigation ────────
    $phone9 = fresh_phone();
    $s9 = fresh_session(); $sessions[] = $s9;
    $base = ['1', '1*10', '1*10*1', '1*10*1*Ninth Crop '.$tag.'', '1*10*1*Ninth Crop '.$tag.'*1', '1*10*1*Ninth Crop '.$tag.'*1*Village'];
    walk($s9, $phone9, $base);
    $page = ussd($s9, $phone9, '1*10*1*Ninth Crop '.$tag.'*1*Village*9');
    check(str_contains($page, $all[8]),
        "crop 9 ({$all[8]}) is selectable — '9' is Next Page everywhere else in this menu");
    $done9 = ussd($s9, $phone9, '1*10*1*Ninth Crop '.$tag.'*1*Village*9*1');
    preg_match('/AGR-\d{8}-[A-Z0-9]+/', $done9, $m9);
    if (!empty($m9[0])) $made[] = $m9[0];
    $row9 = $db->query("SELECT crops_of_interest FROM onboarding_applications WHERE application_ref='" . $db->real_escape_string($m9[0] ?? '') . "'")->fetch_assoc();
    check(($row9['crops_of_interest'] ?? '') === $all[8], 'and is what actually gets stored');

    // ── 4. A gateway retry must not advance or double-insert ─────────────────
    $phoneR = fresh_phone();
    $sR = fresh_session(); $sessions[] = $sR;
    walk($sR, $phoneR, ['1', '1*10', '1*10*1', '1*10*1*Retry Test '.$tag.'']);
    $first  = ussd($sR, $phoneR, '1*10*1*Retry Test '.$tag.'*1');       // district chosen → asks village
    $repeat = ussd($sR, $phoneR, '1*10*1*Retry Test '.$tag.'*1');       // same text again
    check($first === $repeat, 'a repeated request redraws the same page instead of advancing');

    $countBefore = (int)$db->query("SELECT COUNT(*) c FROM onboarding_applications WHERE channel='ussd'")->fetch_assoc()['c'];
    walk($sR, $phoneR, ['1*10*1*Retry Test '.$tag.'*1*Village', '1*10*1*Retry Test '.$tag.'*1*Village*2']);
    $submit1 = ussd($sR, $phoneR, '1*10*1*Retry Test '.$tag.'*1*Village*2*1');
    $submit2 = ussd($sR, $phoneR, '1*10*1*Retry Test '.$tag.'*1*Village*2*1');   // duplicate delivery
    preg_match('/AGR-\d{8}-[A-Z0-9]+/', $submit1, $mr);
    if (!empty($mr[0])) $made[] = $mr[0];
    $countAfter = (int)$db->query("SELECT COUNT(*) c FROM onboarding_applications WHERE channel='ussd'")->fetch_assoc()['c'];
    check($countAfter === $countBefore + 1, "a re-delivered submit inserts once, not twice (+" . ($countAfter - $countBefore) . ')');
    // The second delivery is NOT asserted to return END. A successful submit
    // deletes the session file, so a re-delivery arrives with no state and is
    // read as ordinary menu navigation — which is harmless and, in practice,
    // impossible: the gateway closes the session on END. What matters is the
    // row count above, and that nothing throws.
    check($submit2 !== '', 'and the second delivery still answers rather than erroring');

    // ── 5. Already registered, said up front ─────────────────────────────────
    $sDup = fresh_session(); $sessions[] = $sDup;
    $dup = walk($sDup, $phone, ['1', '1*10']);
    check(str_starts_with($dup, 'END') && str_contains($dup, $ref),
        'a number that already applied is told so at the first step, with its reference');

    // ── 6. A seller IS asked for a business name ─────────────────────────────
    $phoneS = fresh_phone();
    $sS = fresh_session(); $sessions[] = $sS;
    walk($sS, $phoneS, ['1', '1*10', '1*10*2', '1*10*2*Yamikani Phiri '.$tag.'', '1*10*2*Yamikani Phiri '.$tag.'*2', '1*10*2*Yamikani Phiri '.$tag.'*2*Ndirande']);
    $bizPage = ussd($sS, $phoneS, '1*10*2*Yamikani Phiri '.$tag.'*2*Ndirande*5');
    check(str_contains($bizPage, $menu_texts['registration']['business']['en']),
        'a seller is asked for their business name');
    $doneS = walk($sS, $phoneS, ['1*10*2*Yamikani Phiri '.$tag.'*2*Ndirande*5*Phiri Traders', '1*10*2*Yamikani Phiri '.$tag.'*2*Ndirande*5*Phiri Traders*1']);
    preg_match('/AGR-\d{8}-[A-Z0-9]+/', $doneS, $ms);
    if (!empty($ms[0])) $made[] = $ms[0];
    $rowS = $db->query("SELECT user_type, business_name FROM onboarding_applications WHERE application_ref='" . $db->real_escape_string($ms[0] ?? '') . "'")->fetch_assoc();
    check(($rowS['user_type'] ?? '') === 'seller' && ($rowS['business_name'] ?? '') === 'Phiri Traders',
        'and it is stored against a seller');

    // ── 7. Cancelling saves nothing ──────────────────────────────────────────
    $phoneC = fresh_phone();
    $sC = fresh_session(); $sessions[] = $sC;
    walk($sC, $phoneC, ['1', '1*10', '1*10*1', '1*10*1*Cancel Me '.$tag.'', '1*10*1*Cancel Me '.$tag.'*1', '1*10*1*Cancel Me '.$tag.'*1*Village', '1*10*1*Cancel Me '.$tag.'*1*Village*1']);
    $cancelled = ussd($sC, $phoneC, '1*10*1*Cancel Me '.$tag.'*1*Village*1*0');
    check(str_starts_with($cancelled, 'END'), 'cancelling ends the session');
    $left = (int)$db->query("SELECT COUNT(*) c FROM onboarding_applications WHERE full_name='Cancel Me " . $tag . "'")->fetch_assoc()['c'];
    check($left === 0, 'and stores nothing');

    // ── 8. A number the app cannot store is refused, not guessed at ──────────
    $sB = fresh_session(); $sessions[] = $sB;
    $bad = walk($sB, '12345', ['1', '1*10']);
    check(str_starts_with($bad, 'END') && str_contains($bad, 'agrobusinessmw.com'),
        'an unreadable MSISDN is refused with somewhere else to go');

    // ── 9. Chichewa all the way through ──────────────────────────────────────
    $phoneCi = fresh_phone();
    $sCi = fresh_session(); $sessions[] = $sCi;
    walk($sCi, $phoneCi, ['00']);   // language lives in the session; 00 toggles it
    $rolePage = ussd($sCi, $phoneCi, '00*1*10');
    check(str_contains($rolePage, 'LEMBETSANI'), 'the flow runs in Chichewa when the caller chose it');
    walk($sCi, $phoneCi, ['00*1*10*1', '00*1*10*1*Chisomo Banda '.$tag.'', '00*1*10*1*Chisomo Banda '.$tag.'*1', '00*1*10*1*Chisomo Banda '.$tag.'*1*Mudzi']);
    $ciCrops = ussd($sCi, $phoneCi, '00*1*10*1*Chisomo Banda '.$tag.'*1*Mudzi*3');
    check(str_contains($ciCrops, 'Onani:') && str_contains($ciCrops, 'Mlimi'),
        'including the confirmation page');
    $doneCi = ussd($sCi, $phoneCi, '00*1*10*1*Chisomo Banda '.$tag.'*1*Mudzi*3*1');
    check(str_contains($doneCi, 'Mwalembetsa'), 'and the receipt');
    preg_match('/AGR-\d{8}-[A-Z0-9]+/', $doneCi, $mci);
    if (!empty($mci[0])) $made[] = $mci[0];

} finally {
    foreach ($made as $ref) {
        $db->query("DELETE FROM onboarding_applications WHERE application_ref='" . $db->real_escape_string($ref) . "'");
    }
    $db->query("DELETE FROM onboarding_applications WHERE full_name LIKE '%" . $tag . "'");
    foreach ($sessions as $s) {
        $f = __DIR__ . '/../ussd/sessions/' . $s . '.json';
        if (file_exists($f)) unlink($f);
    }
    $leftover = (int)$db->query("SELECT COUNT(*) c FROM onboarding_applications WHERE channel='ussd' AND full_name LIKE '%" . $tag . "'")->fetch_assoc()['c'];
    check($leftover === 0, 'fixtures cleaned up');
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
