<?php
/**
 * AgroBusiness Malawi — registration over USSD.
 *
 * A farmer with a feature phone could read prices, weather, sellers and buyers,
 * and could not join. `onboarding_applications.channel` has been
 * enum('web','ussd') since the schema was written, so this was always intended;
 * nothing ever implemented it.
 *
 * NO VALIDATION LIVES HERE. This file owns the menu — which question comes
 * next, what the caller sees, how the answer is collected. What counts as a
 * valid application, what counts as a duplicate, how a reference is minted and
 * the INSERT itself are all config/registration.php, shared with register.php.
 * Adding a second set of rules here is exactly the mistake this project spent a
 * pass undoing.
 *
 * ── STATE LIVES IN THE SESSION FILE, NOT IN `text` ──────────────────────────
 *
 * Africa's Talking sends the whole accumulated input on every request, and the
 * rest of this menu system replays it through parse_navigation(). That cannot
 * work here, for two reasons:
 *
 *   1. parse_navigation() reads '0' as Back and '9' as Next Page, and neither is
 *      distinguishable from a legitimate answer. Crop 9 is Beans. A caller
 *      choosing Beans would have their answer eaten as pagination.
 *   2. Positional indexing into the raw tokens breaks the moment somebody visits
 *      another menu and comes back — "1*7*0*10*..." has the register option in a
 *      different slot.
 *
 * So each request contributes exactly ONE new answer: the last token. The step
 * and the answers so far are kept in the session file that already carries the
 * language, and `seen` records how many tokens have been consumed. If a request
 * arrives with no more tokens than we have already processed — a gateway retry,
 * which does happen — the current page is re-rendered rather than the state
 * being advanced twice.
 */

require_once dirname(__DIR__) . '/config/registration.php';

/** Main-menu position that starts registration. Two digits: 1-9 were taken. */
const USSD_REGISTER_OPTION = '10';

/** The questions, in order. `business` is skipped for a farmer. */
const USSD_REG_STEPS = ['role', 'name', 'district', 'village', 'crops', 'business', 'confirm'];

function ussd_reg_new(): array
{
    return ['step' => 'role', 'seen' => 0, 'data' => [], 'district_page' => 1];
}

/** The step after this one, skipping `business` for a farmer. */
function ussd_reg_next(string $step, array $data): string
{
    $i = array_search($step, USSD_REG_STEPS, true);
    $next = USSD_REG_STEPS[$i + 1] ?? 'confirm';
    if ($next === 'business' && ($data['user_type'] ?? '') === 'farmer') {
        return 'confirm';
    }
    return $next;
}

/**
 * The crops a caller can pick, numbered.
 *
 * Ordered by name so the numbering is stable between the page the caller reads
 * and the answer they send — ordering by id would renumber the list the day a
 * crop is added, which is fine, but name order also matches the web form.
 */
function ussd_reg_crops(mysqli $db): array
{
    $rows = [];
    $res = $db->query('SELECT id, name FROM crops ORDER BY name ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'name' => $row['name']];
        $res->free();
    }
    return $rows;
}

/** "1 Beans 2 Coffee 3 Cotton …" — compact, because the page is 182 characters. */
function ussd_reg_crop_menu(array $crops): string
{
    $parts = [];
    foreach ($crops as $i => $crop) {
        $parts[] = ($i + 1) . ' ' . $crop['name'];
    }
    return implode(' ', $parts);
}

/**
 * Turn "1,3" / "1 3" / "13" into crop ids.
 *
 * Comma and space are both accepted because a feature-phone keypad makes commas
 * awkward. A bare run of digits is NOT split into single digits — "13" means
 * crop 13, not crops 1 and 3 — because guessing there would silently register
 * somebody for the wrong crops.
 */
function ussd_reg_parse_crops(string $input, array $crops): array
{
    $tokens = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ids = [];
    foreach ($tokens as $token) {
        if (!ctype_digit($token)) return [];          // anything non-numeric is a mistake, not a crop
        $index = (int)$token;
        if ($index < 1 || $index > count($crops)) return [];
        $ids[$crops[$index - 1]['id']] = $crops[$index - 1]['id'];
    }
    return array_values($ids);
}

/** Districts on one page of the shared picker, as [position => district_id]. */
function ussd_reg_district_page(array $district_map, int $page): array
{
    return $district_map[$page] ?? [];
}

/**
 * Render the page for the current step.
 *
 * `$note` is an optional one-line complaint about the previous answer, shown
 * above the question so the caller sees what went wrong without losing the
 * question itself.
 */
function ussd_reg_render(mysqli $db, array $menu_texts, string $lang, array $reg, array $district_map, string $note = ''): string
{
    $t = fn(string $key, array $vars = []) => ussd_reg_text($menu_texts, $lang, $key, $vars);
    $prefix = $note !== '' ? $note . "\n" : '';

    switch ($reg['step']) {
        case 'role':
            return 'CON ' . $prefix . $t('role');
        case 'name':
            return 'CON ' . $prefix . $t('name');
        case 'district':
            $page = $reg['district_page'] ?? 1;
            return 'CON ' . $prefix . $menu_texts['district_selection'][$lang][$page];
        case 'village':
            return 'CON ' . $prefix . $t('village');
        case 'crops':
            $crops = ussd_reg_crops($db);
            return 'CON ' . $prefix . $t('crops') . "\n" . ussd_reg_crop_menu($crops);
        case 'business':
            return 'CON ' . $prefix . $t('business');
        case 'confirm':
            $d = $reg['data'];
            $summary = ($d['full_name'] ?? '') . ', ' . ($d['district_name'] ?? '')
                     . "\n" . $t('role_' . ($d['user_type'] ?? 'farmer'))
                     . ': ' . implode(',', $d['crop_names'] ?? []);
            return 'CON ' . $prefix . $t('confirm', ['summary' => $summary]);
    }
    return 'CON ' . $t('role');
}

/** Look up a registration string; falls back to English, then to the key. */
function ussd_reg_text(array $menu_texts, string $lang, string $key, array $vars = []): string
{
    $entry = $menu_texts['registration'][$key] ?? null;
    $text  = is_array($entry) ? ($entry[$lang] ?? $entry['en'] ?? $key) : $key;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

/**
 * Advance the registration by one answer and return the page to show.
 *
 * $reg is by reference: the caller writes it back into the session file. A
 * returned "END …" means the flow is over and the caller should clear it.
 */
function ussd_registration_step(
    mysqli $db,
    array $menu_texts,
    string $lang,
    string $callerPhone,
    array &$reg,
    array $tokens,
    array $district_map
): string {
    $t = fn(string $key, array $vars = []) => ussd_reg_text($menu_texts, $lang, $key, $vars);

    // The caller's own MSISDN is the phone number — they never type it, which is
    // the single biggest advantage this channel has over the web form. It still
    // goes through the canonical normaliser: the gateway is not guaranteed to
    // send E.164, and a number this app cannot store is worth refusing loudly
    // rather than guessing at.
    $phone = agro_normalize_phone($callerPhone);
    if ($phone === null) {
        return 'END ' . $t('bad_phone');
    }

    $count = count($tokens);

    // ── Fresh start ─────────────────────────────────────────────────────────
    if (!$reg) {
        $reg = ussd_reg_new();
        $reg['seen'] = $count;

        // Tell them now, not after six questions, if they already applied.
        $existing = register_find_duplicates($db, $phone, null, null, null);
        if ($existing) {
            $reg = [];
            return 'END ' . $t('already', [
                'ref'    => $existing[0]['ref'],
                'status' => $t('status_' . $existing[0]['status']),
            ]);
        }
        return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map);
    }

    // ── A retry with nothing new: redraw, do not advance ────────────────────
    if ($count <= ($reg['seen'] ?? 0)) {
        return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map);
    }
    $reg['seen'] = $count;
    $input = trim((string)($tokens[$count - 1] ?? ''));

    // '0' backs out of registration entirely from the first question.
    if ($reg['step'] === 'role' && $input === '0') {
        $reg = [];
        return 'CON ' . $menu_texts['main_menu'][$lang];
    }

    switch ($reg['step']) {
        case 'role':
            $roles = ['1' => 'farmer', '2' => 'seller', '3' => 'buyer'];
            if (!isset($roles[$input])) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_choice'));
            }
            $reg['data']['user_type'] = $roles[$input];
            break;

        case 'name':
            // Length is checked here only to keep the caller from losing a long
            // answer to a database error later; the authoritative limits are in
            // register_validate() and both channels hit them.
            if (mb_strlen($input) < 2) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_name'));
            }
            $reg['data']['full_name'] = mb_substr($input, 0, 200);
            break;

        case 'district':
            $page = $reg['district_page'] ?? 1;
            if ($input === '9' && $page < count($district_map)) {
                $reg['district_page'] = $page + 1;
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map);
            }
            if ($input === '0' && $page > 1) {
                $reg['district_page'] = $page - 1;
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map);
            }
            $districtId = ussd_reg_district_page($district_map, $page)[(int)$input] ?? null;
            if (!$districtId) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_choice'));
            }
            // Read the name back for the confirmation page rather than trusting
            // the map — if the two ever disagree, the database wins.
            $stmt = $db->prepare('SELECT name FROM districts WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $districtId);
            $stmt->execute();
            $name = '';
            $stmt->bind_result($name);
            $found = $stmt->fetch();
            $stmt->close();
            if (!$found) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_choice'));
            }
            $reg['data']['district_id']   = $districtId;
            $reg['data']['district_name'] = $name;
            break;

        case 'village':
            if (mb_strlen($input) < 2) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_village'));
            }
            $reg['data']['village'] = mb_substr($input, 0, 120);
            break;

        case 'crops':
            $crops = ussd_reg_crops($db);
            $ids   = ussd_reg_parse_crops($input, $crops);
            if (!$ids) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_crops'));
            }
            $byId = [];
            foreach ($crops as $crop) $byId[$crop['id']] = $crop['name'];
            $reg['data']['crop_ids']   = $ids;
            $reg['data']['crop_names'] = array_map(fn($id) => $byId[$id], $ids);
            break;

        case 'business':
            if (mb_strlen($input) < 2) {
                return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map, $t('bad_business'));
            }
            $reg['data']['business_name'] = mb_substr($input, 0, 200);
            break;

        case 'confirm':
            if ($input !== '1') {
                $reg = [];
                return 'END ' . $t('cancelled');
            }
            $d = $reg['data'];
            try {
                // register_lang() is what config/registration.php resolves its
                // messages through, so a validation failure comes back in the
                // language the caller is reading the menu in.
                register_lang($lang);
                $app = register_store($db, [
                    'user_type'     => $d['user_type'] ?? '',
                    'full_name'     => $d['full_name'] ?? '',
                    'phone_number'  => $phone,
                    'district_id'   => $d['district_id'] ?? 0,
                    'village'       => $d['village'] ?? '',
                    'crop_ids'      => $d['crop_ids'] ?? [],
                    'business_name' => $d['business_name'] ?? '',
                ], 'ussd');
            } catch (RegistrationError $e) {
                $reg = [];
                // The message is already localised and already safe to show — it
                // was written for an applicant to read.
                return 'END ' . $e->getMessage();
            } catch (Throwable $e) {
                $reg = [];
                error_log('USSD registration failed: ' . $e->getMessage());
                return 'END ' . $t('failed');
            }
            $reg = [];
            return 'END ' . $t('done', ['ref' => $app['application_ref']]);
    }

    $reg['step'] = ussd_reg_next($reg['step'], $reg['data']);
    return ussd_reg_render($db, $menu_texts, $lang, $reg, $district_map);
}
