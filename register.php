<?php
/**
 * AgroBusiness Malawi — Registration.
 *
 * This page is the ONE registration implementation. It owns the form, the
 * client bundle (assets/js/register.js, assets/css/register.css) and every
 * server-side step: validation, phone canonicalisation, duplicate protection,
 * reference generation, persistence to onboarding_applications and the
 * confirmation emails.
 *
 * Do not add a registration modal, do not move any of this into index.php and
 * do not add a second submit endpoint. Earlier revisions of this project had
 * three competing registration flows writing different data through different
 * validators; that is what this file replaced.
 *
 * Request handling:
 *   GET  register.php                 → renders the form
 *   GET  register.php?action=preflight → JSON duplicate check while the user types
 *   POST register.php                 → JSON submit
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/phone.php';
require_once __DIR__ . '/config/mailer.php';

$pageTitle  = 'Register — AgroBusiness Malawi';
$pageDesc   = 'Register as a farmer, seller or buyer on AgroBusiness Malawi.';
$pageStyles = ['assets/css/register.css?v=20260816-0002'];

/** Emit JSON and stop. Errors always carry HTTP 200 so the client can read them. */
function register_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send the JSON response now and keep running.
 *
 * Registration sends two emails, and send_smtp_email() opens a TLS socket with a
 * connect timeout. When the mail host is slow or unreachable that is 15 seconds
 * per message, so the applicant sat on "Submitting…" for half a minute while
 * their application was already safely in the database. Measured at 30s here
 * with an unreachable mail host.
 *
 * So: flush the answer, close the connection, then do the mail. The applicant
 * gets their reference immediately and the notifications happen on our time,
 * not theirs.
 *
 * fastcgi_finish_request() is the clean way and exists under PHP-FPM, which is
 * what cPanel runs. Everything else falls back to declaring Content-Length and
 * flushing, which lets the browser finish the response even though PHP is still
 * working. On the built-in dev server neither fully detaches — that only affects
 * local development.
 */
function register_respond_then_continue(array $payload): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // The applicant is gone by the time the mail runs; do not let their
    // disconnect kill the script half way through sending it.
    ignore_user_abort(true);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/**
 * Language for this request.
 *
 * The reader's language lives in localStorage (see assets/js/i18n.js), which the
 * server cannot see, so the client sends it with the request. Whitelisted, and
 * English on anything unexpected — a bad `lang` must never break a registration.
 */
function register_lang(?string $requested = null): string
{
    static $lang = null;
    if ($requested !== null) {
        $lang = in_array($requested, ['en', 'ci'], true) ? $requested : 'en';
    }
    return $lang ?? 'en';
}

/**
 * Every message a registering farmer can see, in both languages.
 *
 * Kept here rather than in the client because these are the authoritative
 * validation results — the browser's copies in assets/js/register.js are for
 * instant feedback, and the server has the last word. {placeholders} are
 * substituted by register_t().
 */
const REGISTRATION_STRINGS = [
    'phone_invalid' => [
        'en' => '{label} is not valid. Enter a Malawi number as 0888 123 456, or an international number with its country code, e.g. +44 7700 900123.',
        'ci' => '{label} siyolondola. Lembani nambala ya ku Malawi motere 0888 123 456, kapena nambala ya kunja ndi nambala ya dziko, mwachitsanzo +44 7700 900123.',
    ],
    'label_phone' => ['en' => 'Phone number', 'ci' => 'Nambala ya foni'],
    'label_whatsapp' => ['en' => 'WhatsApp number', 'ci' => 'Nambala ya WhatsApp'],

    'dup_check_failed' => [
        'en' => 'Existing applications could not be checked. Please try again.',
        'ci' => 'Sitinathe kuyang\'ana mafomu omwe alipo kale. Yesaninso.',
    ],
    'duplicate' => [
        'en' => 'That {label} is already registered under application {ref} ({status}). Use "Check status" instead of registering again.',
        'ci' => 'Zambiri izi zalembetsedwa kale pa fomu {ref} ({status}): {label}. Gwiritsani ntchito "Onani mkhalidwe" m\'malo molembetsanso.',
    ],
    'dup_label_phone' => ['en' => 'phone number', 'ci' => 'nambala ya foni'],
    'dup_label_whatsapp' => ['en' => 'WhatsApp number', 'ci' => 'nambala ya WhatsApp'],
    'dup_label_email' => ['en' => 'email address', 'ci' => 'imelo'],
    'dup_label_national_id' => ['en' => 'National ID', 'ci' => 'chiphaso cha dziko'],

    'status_pending' => ['en' => 'pending', 'ci' => 'ikuyembekezera'],
    'status_approved' => ['en' => 'approved', 'ci' => 'yavomerezedwa'],
    'status_denied' => ['en' => 'denied', 'ci' => 'yakanidwa'],

    'user_type_required' => [
        'en' => 'Choose whether you are registering as a farmer, seller or buyer.',
        'ci' => 'Sankhani ngati mukulembetsa ngati mlimi, wogulitsa kapena wogula.',
    ],
    'name_required' => ['en' => 'Enter your full name.', 'ci' => 'Lembani dzina lanu lonse.'],
    'name_too_long' => [
        'en' => 'That name is too long. Use 150 characters or fewer.',
        'ci' => 'Dzinali ndi lalitali kwambiri. Gwiritsani ntchito zilembo 150 kapena zochepera.',
    ],
    'email_invalid' => [
        'en' => 'Enter a valid email address, or leave it blank.',
        'ci' => 'Lembani imelo yolondola, kapena musalembe kalikonse.',
    ],
    'national_id_invalid' => [
        'en' => 'Enter a valid National ID, or leave it blank.',
        'ci' => 'Lembani chiphaso cha dziko cholondola, kapena musalembe kalikonse.',
    ],
    'district_required' => ['en' => 'Select your district.', 'ci' => 'Sankhani chigawo chanu.'],
    'district_unknown' => [
        'en' => 'That district could not be found. Choose one from the list.',
        'ci' => 'Chigawo chimenechi sichinapezeke. Sankhani chimodzi pa mndandandawu.',
    ],
    'district_check_failed' => [
        'en' => 'The district could not be verified. Please try again.',
        'ci' => 'Sitinathe kutsimikizira chigawo. Yesaninso.',
    ],
    'village_required' => ['en' => 'Enter your village or town.', 'ci' => 'Lembani mudzi kapena tauni yanu.'],
    'village_too_long' => [
        'en' => 'That village or town name is too long.',
        'ci' => 'Dzina la mudzi kapena tauni ndi lalitali kwambiri.',
    ],
    'business_required' => [
        'en' => 'Enter your business or organisation name.',
        'ci' => 'Lembani dzina la bizinesi kapena bungwe lanu.',
    ],
    'business_too_long' => [
        'en' => 'That business name is too long.',
        'ci' => 'Dzina la bizinesi ndi lalitali kwambiri.',
    ],
    'crops_required' => ['en' => 'Select at least one crop.', 'ci' => 'Sankhani mbewu imodzi kapena kupitirira.'],
    'crops_too_many' => ['en' => 'Select fewer crops.', 'ci' => 'Sankhani mbewu zochepa.'],
    'crops_check_failed' => [
        'en' => 'Your crop selection could not be verified. Please try again.',
        'ci' => 'Sitinathe kutsimikizira mbewu zomwe mwasankha. Yesaninso.',
    ],
    'crops_unknown' => [
        'en' => 'One of the crops you selected is no longer available. Review your selection.',
        'ci' => 'Mbewu ina yomwe mwasankha sikupezekanso. Onaninso zomwe mwasankha.',
    ],
    'ref_failed' => [
        'en' => 'Could not allocate an application reference. Please try again.',
        'ci' => 'Sitinathe kupereka nambala yodziwikira. Yesaninso.',
    ],
    'body_unreadable' => [
        'en' => 'The registration could not be read. Please try again.',
        'ci' => 'Fomu yanu sinawerengeke. Yesaninso.',
    ],
    'save_failed' => [
        'en' => 'The registration could not be saved. Please try again.',
        'ci' => 'Fomu yanu sinasungidwe. Yesaninso.',
    ],
    'duplicate_race' => [
        'en' => 'An application with these details already exists. Use "Check status" to track it.',
        'ci' => 'Fomu ya zambiri zimenezi ilipo kale. Gwiritsani ntchito "Onani mkhalidwe" kuti muitsatire.',
    ],
    'unavailable' => [
        'en' => 'Registration is temporarily unavailable. Please try again shortly.',
        'ci' => 'Kulembetsa sikukugwira ntchito pakadali pano. Yesaninso posachedwa.',
    ],
];

/** Resolve a message key in the request language, substituting {placeholders}. */
function register_t(string $key, array $vars = []): string
{
    $entry = REGISTRATION_STRINGS[$key] ?? null;
    if ($entry === null) return $key;

    // Fall back to English rather than showing nothing if a translation is missing.
    $text = $entry[register_lang()] ?? $entry['en'];
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

/**
 * A registration error the applicant is allowed to see, optionally tied to a
 * field. Carries a message KEY so the text is resolved in the reader's language
 * at the point it is thrown.
 */
final class RegistrationError extends RuntimeException
{
    public string $field;
    public string $key;

    public function __construct(string $key, string $field = '', array $vars = [])
    {
        $this->key = $key;
        $this->field = $field;
        parent::__construct(register_t($key, $vars));
    }
}

/**
 * Normalise a contact number or explain, in the applicant's terms, why it could
 * not be. Shared by phone and WhatsApp so both obey the same database contract.
 */
function register_require_phone(string $raw, string $labelKey, string $field): string
{
    $normalized = agro_normalize_phone($raw);
    if ($normalized === null) {
        throw new RegistrationError('phone_invalid', $field, ['label' => register_t($labelKey)]);
    }
    return $normalized;
}

/**
 * Look for an existing application that already claims one of this person's
 * contact identifiers. Phone and WhatsApp are checked against BOTH stored
 * columns, because one person's phone is another person's WhatsApp number and
 * either collision means the same human already has an application.
 *
 * @return array<int, array{field:string,label:string,ref:string,status:string}>
 */
function register_find_duplicates(
    mysqli $db,
    string $phone,
    ?string $whatsapp,
    ?string $email,
    ?string $nationalId
): array {
    // Labels are resolved from the field name via register_t('dup_label_*'), so
    // the message comes back in the reader's language.
    $checks = [
        ['field' => 'phone',
         'sql'   => 'SELECT application_ref, status FROM onboarding_applications
                     WHERE phone_number = ? OR whatsapp_number = ? LIMIT 1',
         'args'  => [$phone, $phone]],
    ];

    if ($whatsapp !== null) {
        $checks[] = ['field' => 'whatsapp',
            'sql'  => 'SELECT application_ref, status FROM onboarding_applications
                       WHERE phone_number = ? OR whatsapp_number = ? LIMIT 1',
            'args' => [$whatsapp, $whatsapp]];
    }
    if ($email !== null) {
        $checks[] = ['field' => 'email',
            'sql'  => "SELECT application_ref, status FROM onboarding_applications
                       WHERE email IS NOT NULL AND email <> '' AND email = ? LIMIT 1",
            'args' => [$email]];
    }
    if ($nationalId !== null) {
        $checks[] = ['field' => 'national_id',
            'sql'  => "SELECT application_ref, status FROM onboarding_applications
                       WHERE national_id IS NOT NULL AND national_id <> '' AND national_id = ? LIMIT 1",
            'args' => [$nationalId]];
    }

    $found = [];
    foreach ($checks as $check) {
        $stmt = $db->prepare($check['sql']);
        if (!$stmt) {
            throw new RegistrationError('dup_check_failed');
        }
        $stmt->bind_param(str_repeat('s', count($check['args'])), ...$check['args']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RegistrationError('dup_check_failed');
        }
        $row = agro_stmt_one($stmt);
        $stmt->close();
        if ($row) {
            $found[] = [
                'field'        => $check['field'],
                'label'        => register_t('dup_label_' . $check['field']),
                'ref'          => (string)$row['application_ref'],
                // Raw status for logic, translated for display. Returning only
                // the translated form made the caller translate it twice.
                'status'       => (string)$row['status'],
                'status_label' => register_t('status_' . (string)$row['status']),
            ];
        }
    }
    return $found;
}

/**
 * Read and validate the applicant's details from a decoded request body.
 * Returns the values in exactly the shape the INSERT needs.
 */
function register_validate(mysqli $db, array $body): array
{
    $userType   = trim((string)($body['user_type'] ?? ''));
    $fullName   = trim((string)($body['full_name'] ?? ''));
    $phoneInput = trim((string)($body['phone_number'] ?? ''));
    $waInput    = trim((string)($body['whatsapp_number'] ?? ''));
    $emailInput = trim((string)($body['email'] ?? ''));
    $nidInput   = trim((string)($body['national_id'] ?? ''));
    $districtId = (int)($body['district_id'] ?? 0);
    $village    = trim((string)($body['village'] ?? ''));
    $business   = trim((string)($body['business_name'] ?? ''));

    if (!in_array($userType, ['farmer', 'seller', 'buyer'], true)) {
        throw new RegistrationError('user_type_required', 'user_type');
    }
    if (mb_strlen($fullName) < 2) {
        throw new RegistrationError('name_required', 'full_name');
    }
    if (mb_strlen($fullName) > 150) {
        throw new RegistrationError('name_too_long', 'full_name');
    }

    $phone = register_require_phone($phoneInput, 'label_phone', 'phone_number');

    $whatsapp = null;
    if ($waInput !== '') {
        $whatsapp = register_require_phone($waInput, 'label_whatsapp', 'whatsapp_number');
    }

    // Email stays optional. An empty string becomes NULL so it can never collide
    // with another applicant's empty email in the duplicate check.
    $email = null;
    if ($emailInput !== '') {
        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL) || mb_strlen($emailInput) > 190) {
            throw new RegistrationError('email_invalid', 'email');
        }
        $email = $emailInput;
    }

    $nationalId = null;
    if ($nidInput !== '') {
        if (!preg_match('/^[A-Za-z0-9\- ]{4,32}$/', $nidInput)) {
            throw new RegistrationError('national_id_invalid', 'national_id');
        }
        $nationalId = strtoupper(preg_replace('/\s+/', '', $nidInput));
    }

    if ($districtId <= 0) {
        throw new RegistrationError('district_required', 'district_id');
    }
    if (mb_strlen($village) < 2) {
        throw new RegistrationError('village_required', 'village');
    }
    if (mb_strlen($village) > 120) {
        throw new RegistrationError('village_too_long', 'village');
    }
    if ($userType !== 'farmer' && $business === '') {
        throw new RegistrationError('business_required', 'business_name');
    }
    if (mb_strlen($business) > 150) {
        throw new RegistrationError('business_too_long', 'business_name');
    }

    // The district must exist. Never trust the id the browser sent back.
    $stmt = $db->prepare('SELECT name FROM districts WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RegistrationError('district_check_failed');
    $stmt->bind_param('i', $districtId);
    $stmt->execute();
    $districtRow = agro_stmt_one($stmt);
    $stmt->close();
    if (!$districtRow) {
        throw new RegistrationError('district_unknown', 'district_id');
    }

    // Crops are validated against the crops table by id, then stored by name.
    // Taking the names from the database rather than the request means a crafted
    // request cannot write arbitrary text into crops_of_interest.
    $cropIds = [];
    foreach ((array)($body['crop_ids'] ?? []) as $candidate) {
        $id = (int)$candidate;
        if ($id > 0) $cropIds[$id] = $id;
    }
    if (!$cropIds) {
        throw new RegistrationError('crops_required', 'crops');
    }
    if (count($cropIds) > 40) {
        throw new RegistrationError('crops_too_many', 'crops');
    }
    $placeholders = implode(',', array_fill(0, count($cropIds), '?'));
    $cropStmt = $db->prepare("SELECT name FROM crops WHERE id IN ($placeholders) ORDER BY name ASC");
    if (!$cropStmt) throw new RegistrationError('crops_check_failed');
    $ids = array_values($cropIds);
    $cropStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $cropStmt->execute();
    $cropRows = agro_stmt_all($cropStmt);
    $cropStmt->close();
    if (count($cropRows) !== count($ids)) {
        throw new RegistrationError('crops_unknown', 'crops');
    }
    $cropNames = array_map(static fn(array $r): string => (string)$r['name'], $cropRows);

    return [
        'user_type'         => $userType,
        'full_name'         => $fullName,
        'phone_number'      => $phone,
        'whatsapp_number'   => $whatsapp,
        'email'             => $email,
        'national_id'       => $nationalId,
        'district_id'       => $districtId,
        'district_name'     => (string)$districtRow['name'],
        'village'           => $village,
        'crops_of_interest' => implode(', ', $cropNames),
        'business_name'     => $userType === 'farmer' ? null : $business,
    ];
}

/**
 * Generate an application reference that is not already taken.
 * Format AGR-YYYYMMDD-XXXXX, matched by the status lookup and the admin panel.
 */
function register_reference(mysqli $db): string
{
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $ref = 'AGR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $stmt = $db->prepare('SELECT id FROM onboarding_applications WHERE application_ref = ? LIMIT 1');
        if (!$stmt) return $ref; // the UNIQUE key is the real guarantee
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $taken = agro_stmt_one($stmt) !== null;
        $stmt->close();
        if (!$taken) return $ref;
    }
    throw new RegistrationError('ref_failed');
}

/**
 * Notify the applicant and the review team.
 *
 * The applicant's copy goes out in the language they registered in — someone who
 * filled the form in Chichewa should not get an English email back. The team's
 * copy is always English; that is the language the review panel is in.
 *
 * Never blocks a saved application: the caller has already responded.
 */
function register_notify(array $app, string $ref): void
{
    $ci = register_lang() === 'ci';
    $roleLabel = ucfirst($app['user_type']);
    $roleCi = ['farmer' => 'Mlimi', 'seller' => 'Wogulitsa', 'buyer' => 'Wogula'][$app['user_type']] ?? $roleLabel;

    if ($app['email'] !== null) {
        $subject = $ci
            ? "Fomu yanu yalandiridwa — {$ref}"
            : "Application received — {$ref}";

        $rows = $ci
            ? [
                ['Nambala yodziwikira', $ref],
                ['Udindo', $roleCi],
                ['Chigawo', $app['district_name']],
                ['Mudzi / tauni', $app['village']],
                ['Foni', $app['phone_number']],
            ]
            : [
                ['Reference', $ref],
                ['Role', $roleLabel],
                ['District', $app['district_name']],
                ['Village / town', $app['village']],
                ['Phone', $app['phone_number']],
            ];

        $rowsHtml = '';
        foreach ($rows as [$label, $value]) $rowsHtml .= email_row($label, (string)$value);

        $body = $ci
            ? '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">Fomu yanu yalandiridwa</h2>'
                . '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Wolemekezeka <strong>'
                . htmlspecialchars($app['full_name']) . '</strong>,</p>'
                . '<p style="margin:0 0 20px;font-size:15px;color:#374151;">Zikomo polembetsa ndi '
                . 'AgroBusiness Malawi. Fomu yanu yalandiridwa ndipo ikuyang\'aniridwa.</p>'
                . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;'
                . 'border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;"><tbody>' . $rowsHtml
                . '</tbody></table>'
                . '<p style="margin:0 0 24px;font-size:15px;color:#374151;">Tidzayang\'ana fomu yanu ndipo '
                . 'tidzakudziwitsani m\'masiku <strong>2&ndash;3</strong> a ntchito. Sungani nambala yanu '
                . 'yodziwikira — mudzaifuna kuti muone mmene fomu yanu ikuyendera.</p>'
                . '<a href="https://agrobusinessmw.com/status.php" style="display:inline-block;background:#16a34a;'
                . 'color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;'
                . 'font-size:15px;">Onani mkhalidwe wa fomu</a>'
            : '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">Application received</h2>'
                . '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Dear <strong>'
                . htmlspecialchars($app['full_name']) . '</strong>,</p>'
                . '<p style="margin:0 0 20px;font-size:15px;color:#374151;">Thank you for registering with '
                . 'AgroBusiness Malawi. Your application has been received and is under review.</p>'
                . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;'
                . 'border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;"><tbody>' . $rowsHtml
                . '</tbody></table>'
                . '<p style="margin:0 0 24px;font-size:15px;color:#374151;">We will review your application and '
                . 'notify you within <strong>2&ndash;3 business days</strong>. Keep your reference number — you '
                . 'can check your status with it at any time.</p>'
                . '<a href="https://agrobusinessmw.com/status.php" style="display:inline-block;background:#16a34a;'
                . 'color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;'
                . 'font-size:15px;">Check application status</a>';

        send_smtp_email($app['email'], $subject, email_html($body));
    }

    $adminEmail = trim((string)($_ENV['Username'] ?? 'info@promanaged-it.com'));
    if ($adminEmail === '') return;

    // Always English: this goes to the review team, not the applicant.
    $adminHtml = email_html(
        '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">New application submitted</h2>'
        . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;'
        . 'border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;"><tbody>'
        . email_row('Reference', $ref)
        . email_row('Full name', $app['full_name'])
        . email_row('Role', $roleLabel)
        . email_row('Phone', $app['phone_number'])
        . email_row('WhatsApp', $app['whatsapp_number'] ?? '—')
        . email_row('Email', $app['email'] ?? '—')
        . email_row('National ID', $app['national_id'] ?? '—')
        . email_row('District', $app['district_name'])
        . email_row('Village / town', $app['village'])
        . email_row('Business', $app['business_name'] ?? '—')
        . email_row('Crops', $app['crops_of_interest'] ?: '—')
        . email_row('Language', register_lang() === 'ci' ? 'Chichewa' : 'English')
        . email_row('Submitted', date('d M Y, H:i'))
        . '</tbody></table>'
    );
    send_smtp_email($adminEmail, "New application: {$ref} — {$roleLabel}", $adminHtml);
}

// ─── Preflight: warn about duplicates before the applicant finishes the form ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'preflight') {
    register_lang((string)($_GET['lang'] ?? 'en'));
    try {
        $phone = agro_normalize_phone($_GET['phone_number'] ?? '');
        if ($phone === null) {
            register_json(['success' => true, 'matches' => []]);
        }
        $waRaw    = trim((string)($_GET['whatsapp_number'] ?? ''));
        $whatsapp = $waRaw === '' ? null : agro_normalize_phone($waRaw);

        $emailRaw = trim((string)($_GET['email'] ?? ''));
        $email    = ($emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) ? $emailRaw : null;

        $nidRaw = trim((string)($_GET['national_id'] ?? ''));
        $nid    = $nidRaw === '' ? null : strtoupper(preg_replace('/\s+/', '', $nidRaw));

        $db = agro_db_connect();
        $matches = register_find_duplicates($db, $phone, $whatsapp, $email, $nid);
        $db->close();

        register_json(['success' => true, 'matches' => $matches]);
    } catch (Throwable $e) {
        // A preflight failure must never block a registration; the POST handler
        // performs the authoritative check against the same data.
        register_json(['success' => false, 'matches' => [], 'error' => 'Duplicate check unavailable.']);
    }
}

// ─── Submit ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = null;
    try {
        $raw  = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        // Adopt the language before the first possible throw, so even a
        // malformed-body error comes back in the reader's language.
        register_lang(is_array($body) ? (string)($body['lang'] ?? 'en') : 'en');
        if (!is_array($body)) {
            throw new RegistrationError('body_unreadable');
        }

        $db  = agro_db_connect();
        $app = register_validate($db, $body);

        $duplicates = register_find_duplicates(
            $db,
            $app['phone_number'],
            $app['whatsapp_number'],
            $app['email'],
            $app['national_id']
        );
        if ($duplicates) {
            $first = $duplicates[0];
            throw new RegistrationError(
                'duplicate',
                $first['field'] === 'phone' ? 'phone_number'
                    : ($first['field'] === 'whatsapp' ? 'whatsapp_number' : $first['field']),
                [
                    'label'  => $first['label'],
                    'ref'    => $first['ref'],
                    'status' => $first['status_label'],
                ]
            );
        }

        $ref  = register_reference($db);
        $stmt = $db->prepare(
            'INSERT INTO onboarding_applications
                (application_ref, user_type, full_name, phone_number, whatsapp_number, email,
                 national_id, district_id, village, crops_of_interest, business_name, channel)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$stmt) throw new RegistrationError('save_failed');

        $channel = 'web';
        $stmt->bind_param(
            'sssssssissss',
            $ref,
            $app['user_type'],
            $app['full_name'],
            $app['phone_number'],
            $app['whatsapp_number'],
            $app['email'],
            $app['national_id'],
            $app['district_id'],
            $app['village'],
            $app['crops_of_interest'],
            $app['business_name'],
            $channel
        );
        if (!$stmt->execute()) {
            // 1062 is the UNIQUE key catching a race the SELECT above could not.
            $duplicateKey = $stmt->errno === 1062;
            $stmt->close();
            throw new RegistrationError($duplicateKey ? 'duplicate_race' : 'save_failed');
        }
        $stmt->close();

        $db->close();

        // The application is committed. Answer the applicant NOW, then send the
        // notifications — mail must never be on the critical path of a farmer
        // waiting to see their reference number.
        register_respond_then_continue([
            'success'         => true,
            'reference'       => $ref,
            'phone_number'    => $app['phone_number'],
            'whatsapp_number' => $app['whatsapp_number'],
        ]);

        // Best effort from here on. A dead SMTP server must never make a saved
        // application look like a failure, and the response has already gone.
        try {
            register_notify($app, $ref);
        } catch (Throwable $mailError) {
            error_log('Registration notification failed for ' . $ref . ': ' . $mailError->getMessage());
        }
        exit;
    } catch (RegistrationError $e) {
        if ($db instanceof mysqli) $db->close();
        register_json(['success' => false, 'error' => $e->getMessage(), 'field' => $e->field, 'code' => $e->key]);
    } catch (Throwable $e) {
        if ($db instanceof mysqli) $db->close();
        // Never leak a driver message, a query or a credential to the browser.
        error_log('Registration failed: ' . $e->getMessage());
        register_json(['success' => false, 'error' => register_t('unavailable'), 'field' => '']);
    }
}

include __DIR__ . '/partials/head.php';
?>
<body class="register-page-body">
<?php include __DIR__ . '/partials/nav.php'; ?>
<header class="header register-topbar" role="banner">
    <div class="header-content">
        <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="app-nav"><span class="material-symbols-rounded" aria-hidden="true">menu</span></button>
        <h1 class="content-title" data-i18n="pageTitle">Register</h1>
        <div class="register-topbar-actions">
            <!-- Registration is a standalone page, so it carries its own language
                 switcher: someone arriving here straight from the nav must be able
                 to read the form without going back to the dashboard first. -->
            <button type="button" class="register-lang-toggle" id="register-lang-toggle" aria-label="Change language">
                <span class="register-lang-flag" id="register-lang-flag" aria-hidden="true">🇬🇧</span>
                <span class="register-lang-code" id="register-lang-code">EN</span>
            </button>
            <a href="index.php" class="home-btn" aria-label="Home" title="Home"><span class="material-symbols-rounded" aria-hidden="true">home</span></a>
        </div>
    </div>
</header>
<main id="content-area" class="register-page">
    <div class="register-shell">
        <nav class="register-breadcrumbs" aria-label="Breadcrumb"><a href="index.php" data-i18n="home">Home</a><span class="material-symbols-rounded" aria-hidden="true">chevron_right</span><strong data-i18n="pageTitle">Register</strong></nav>
        <header class="register-header">
            <span class="register-eyebrow">AgroBusiness Malawi</span>
            <h2 data-i18n="heading">Join the agricultural community</h2>
            <p data-i18n="intro">Register as a farmer, seller or buyer. Only your phone number is required — WhatsApp, email and National ID are optional.</p>
        </header>

        <form id="registration-form" novalidate>
            <ol class="register-progress" aria-label="Registration progress">
                <li class="active" data-progress="1"><span>1</span><i data-i18n="stepRole">Role</i></li>
                <li data-progress="2"><span>2</span><i data-i18n="stepDetails">Details</i></li>
                <li data-progress="3"><span>3</span><i data-i18n="stepCrops">Crops</i></li>
                <li data-progress="4"><span>4</span><i data-i18n="stepReview">Review</i></li>
            </ol>

            <section class="register-step active" data-step="1" aria-labelledby="step-1-heading">
                <h3 id="step-1-heading" data-i18n="roleHeading">How will you use AgroBusiness?</h3>
                <div class="register-role-grid" role="radiogroup" aria-labelledby="step-1-heading">
                    <button type="button" class="register-role" data-role="farmer" role="radio" aria-checked="false"><span aria-hidden="true">🧑‍🌾</span><strong data-i18n="roleFarmer">Farmer</strong><small data-i18n="roleFarmerDesc">Grow and manage crops</small></button>
                    <button type="button" class="register-role" data-role="seller" role="radio" aria-checked="false"><span aria-hidden="true">🏪</span><strong data-i18n="roleSeller">Seller</strong><small data-i18n="roleSellerDesc">Sell agricultural products</small></button>
                    <button type="button" class="register-role" data-role="buyer" role="radio" aria-checked="false"><span aria-hidden="true">🏢</span><strong data-i18n="roleBuyer">Buyer</strong><small data-i18n="roleBuyerDesc">Source agricultural products</small></button>
                </div>
                <p class="register-error" id="error-user_type" role="alert"></p>
                <p class="register-help"><span data-i18n="alreadyApplied">Already applied?</span> <a href="status.php" data-i18n="checkStatusLink">Check your application status</a>.</p>
            </section>

            <section class="register-step" data-step="2" hidden aria-labelledby="step-2-heading">
                <h3 id="step-2-heading" data-i18n="detailsHeading">Your contact details</h3>
                <div class="register-grid">
                    <div class="register-field">
                        <label for="reg-full-name"><span data-i18n="fieldFullName">Full name</span> <abbr title="required">*</abbr></label>
                        <input id="reg-full-name" name="full_name" type="text" autocomplete="name" maxlength="150" required aria-describedby="error-full_name">
                        <p class="register-error" id="error-full_name" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-phone"><span data-i18n="fieldPhone">Phone number</span> <abbr title="required">*</abbr></label>
                        <input id="reg-phone" name="phone_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" placeholder="0888 123 456" required aria-describedby="reg-phone-help error-phone_number">
                        <small id="reg-phone-help" class="register-hint" data-i18n="phoneHint">Malawi numbers are saved as +265…. For any other country, include the country code, e.g. +44 7700 900123.</small>
                        <p class="register-error" id="error-phone_number" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-whatsapp"><span data-i18n="fieldWhatsapp">WhatsApp number</span> <span class="register-optional" data-i18n="optional">(optional)</span></label>
                        <input id="reg-whatsapp" name="whatsapp_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" data-i18n-placeholder="whatsappPlaceholder" placeholder="Same format as phone" aria-describedby="reg-whatsapp-help error-whatsapp_number">
                        <small id="reg-whatsapp-help" class="register-hint" data-i18n="whatsappHint">Leave blank if you do not use WhatsApp.</small>
                        <p class="register-error" id="error-whatsapp_number" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-email"><span data-i18n="fieldEmail">Email</span> <span class="register-optional" data-i18n="optional">(optional)</span></label>
                        <input id="reg-email" name="email" type="email" autocomplete="email" maxlength="190" aria-describedby="error-email">
                        <p class="register-error" id="error-email" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-national-id"><span data-i18n="fieldNationalId">National ID</span> <span class="register-optional" data-i18n="optional">(optional)</span></label>
                        <input id="reg-national-id" name="national_id" type="text" autocomplete="off" maxlength="32" aria-describedby="error-national_id">
                        <p class="register-error" id="error-national_id" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-village"><span data-i18n="fieldVillage">Village / town</span> <abbr title="required">*</abbr></label>
                        <input id="reg-village" name="village" type="text" autocomplete="address-level2" maxlength="120" required aria-describedby="error-village">
                        <p class="register-error" id="error-village" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-district"><span data-i18n="fieldDistrict">District</span> <abbr title="required">*</abbr></label>
                        <select id="reg-district" name="district_id" required aria-describedby="error-district_id">
                            <option value="">Loading districts…</option>
                        </select>
                        <p class="register-error" id="error-district_id" role="alert"></p>
                    </div>
                    <div class="register-field" id="business-field" hidden>
                        <label for="reg-business-name"><span data-i18n="fieldBusiness">Business / organisation name</span> <abbr title="required">*</abbr></label>
                        <input id="reg-business-name" name="business_name" type="text" autocomplete="organization" maxlength="150" aria-describedby="error-business_name">
                        <p class="register-error" id="error-business_name" role="alert"></p>
                    </div>
                </div>
                <p class="register-error" id="error-step-2" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back data-i18n="back">Back</button>
                    <button type="button" class="btn-primary" data-next data-i18n="continue">Continue</button>
                </div>
            </section>

            <section class="register-step" data-step="3" hidden aria-labelledby="step-3-heading">
                <h3 id="step-3-heading" data-i18n="cropsHeading">What do you grow or trade?</h3>
                <p class="register-help" data-i18n="cropsHelp">Select at least one crop.</p>
                <div id="reg-crops" class="register-crops" role="group" aria-labelledby="step-3-heading">Loading crops…</div>
                <p class="register-error" id="error-crops" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back data-i18n="back">Back</button>
                    <button type="button" class="btn-primary" data-next data-i18n="review">Review</button>
                </div>
            </section>

            <section class="register-step" data-step="4" hidden aria-labelledby="step-4-heading">
                <h3 id="step-4-heading" data-i18n="reviewHeading">Review your registration</h3>
                <dl id="reg-review" class="register-review"></dl>
                <p class="register-note" data-i18n="kycNote">By submitting you agree to KYC verification. We will review your application and give you a reference number.</p>
                <p class="register-error" id="error-submit" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back data-i18n="back">Back</button>
                    <button type="submit" class="btn-primary" id="reg-submit" data-i18n="submit">Submit application</button>
                </div>
            </section>

            <section class="register-success" hidden id="register-success" aria-live="polite">
                <div class="register-success-mark" aria-hidden="true">✓</div>
                <h3 data-i18n="successHeading">Application submitted</h3>
                <p data-i18n="successRefLabel">Your reference number is</p>
                <strong id="reg-reference"></strong>
                <p data-i18n="successKeep">Keep this number safe — you need it to check your application status.</p>
                <div class="register-actions">
                    <a class="btn-primary" href="status.php" data-i18n="checkStatus">Check status</a>
                    <a class="btn-secondary" href="index.php" data-i18n="backHome">Back to home</a>
                </div>
            </section>
        </form>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script>
(function () {
    function nav() { return document.getElementById('app-nav'); }
    function open() { var n = nav(); if (!n) return; n.classList.add('open'); n.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
    function close() { var n = nav(); if (!n) return; n.classList.remove('open'); n.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
    document.addEventListener('click', function (e) { if (e.target.closest('.nav-toggle')) { e.preventDefault(); open(); } else if (e.target.closest('[data-nav-close]')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<script src="assets/js/i18n.js?v=20260816-0002"></script>
<script src="assets/js/phone-normalizer.js?v=20260816-0002"></script>
<script src="assets/js/register.js?v=20260816-0002" defer></script>
</body>
</html>
