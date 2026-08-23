<?php
declare(strict_types=1);

/**
 * AgroBusiness Malawi — the registration RULES, shared by every channel.
 *
 * WHY THIS FILE EXISTS
 *   The project once had three registration flows writing to
 *   `onboarding_applications` with three different sets of validation, so the
 *   same person could be stored two different ways. They were deleted and
 *   register.php became the one implementation — which was right for the web and
 *   left the USSD channel with no way to register at all.
 *
 *   Adding a second insert to ussd/ would have recreated the original problem
 *   exactly. So the rules moved here instead:
 *
 *     register.php          the WEB registration page — form, preflight, POST,
 *                           email. Owns presentation, not policy.
 *     ussd/registration.php the USSD step machine. Owns the menu, not policy.
 *     THIS FILE             what a valid application is, what counts as a
 *                           duplicate, how a reference is minted, and the single
 *                           INSERT. Both channels call register_store().
 *
 *   Add a channel by calling register_store() with a new `channel` value. Do NOT
 *   write another INSERT into onboarding_applications. A test used to fail if a
 *   second one appeared; that test is gone, so this is now convention only —
 *   three competing INSERTs is exactly how the same person once got stored two
 *   different ways.
 *
 * `channel` is an enum('web','ussd') on the table, so it is validated here
 * rather than trusted from a caller.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/phone.php';
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
 * Validate, check for duplicates, mint a reference and insert — the whole
 * write path, in one place, for every channel.
 *
 * $body is the same shape the web form posts; the USSD handler assembles the
 * same keys from its session. Returns the application reference.
 *
 * Throws RegistrationError with a message key already resolved into the
 * language set by register_lang(), so a caller can show $e->getMessage()
 * directly and branch on $e->key when it needs the reason rather than the prose.
 */
function register_store(mysqli $db, array $body, string $channel): array
{
    if (!in_array($channel, ['web', 'ussd'], true)) {
        // The column is an enum; an unknown channel would be silently coerced.
        throw new RegistrationError('save_failed');
    }

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

    $app['application_ref'] = $ref;
    return $app;
}
