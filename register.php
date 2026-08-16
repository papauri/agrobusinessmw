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
$pageStyles = ['assets/css/register.css?v=20260816-0001'];

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

/** A registration error the applicant is allowed to see, optionally tied to a field. */
final class RegistrationError extends RuntimeException
{
    public string $field;
    public function __construct(string $message, string $field = '')
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

/**
 * Normalise a contact number or explain, in the applicant's terms, why it could
 * not be. Shared by phone and WhatsApp so both obey the same database contract.
 */
function register_require_phone(string $raw, string $label, string $field): string
{
    $normalized = agro_normalize_phone($raw);
    if ($normalized === null) {
        throw new RegistrationError(
            $label . ' is not valid. Enter a Malawi number as 0888 123 456, '
            . 'or an international number with its country code, e.g. +44 7700 900123.',
            $field
        );
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
    $checks = [
        ['field' => 'phone',       'label' => 'phone number',
         'sql'   => 'SELECT application_ref, status FROM onboarding_applications
                     WHERE phone_number = ? OR whatsapp_number = ? LIMIT 1',
         'args'  => [$phone, $phone]],
    ];

    if ($whatsapp !== null) {
        $checks[] = ['field' => 'whatsapp', 'label' => 'WhatsApp number',
            'sql'  => 'SELECT application_ref, status FROM onboarding_applications
                       WHERE phone_number = ? OR whatsapp_number = ? LIMIT 1',
            'args' => [$whatsapp, $whatsapp]];
    }
    if ($email !== null) {
        $checks[] = ['field' => 'email', 'label' => 'email address',
            'sql'  => "SELECT application_ref, status FROM onboarding_applications
                       WHERE email IS NOT NULL AND email <> '' AND email = ? LIMIT 1",
            'args' => [$email]];
    }
    if ($nationalId !== null) {
        $checks[] = ['field' => 'national_id', 'label' => 'National ID',
            'sql'  => "SELECT application_ref, status FROM onboarding_applications
                       WHERE national_id IS NOT NULL AND national_id <> '' AND national_id = ? LIMIT 1",
            'args' => [$nationalId]];
    }

    $found = [];
    foreach ($checks as $check) {
        $stmt = $db->prepare($check['sql']);
        if (!$stmt) {
            throw new RegistrationError('Existing applications could not be checked. Please try again.');
        }
        $stmt->bind_param(str_repeat('s', count($check['args'])), ...$check['args']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RegistrationError('Existing applications could not be checked. Please try again.');
        }
        $row = agro_stmt_one($stmt);
        $stmt->close();
        if ($row) {
            $found[] = [
                'field'  => $check['field'],
                'label'  => $check['label'],
                'ref'    => (string)$row['application_ref'],
                'status' => (string)$row['status'],
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
        throw new RegistrationError('Choose whether you are registering as a farmer, seller or buyer.', 'user_type');
    }
    if (mb_strlen($fullName) < 2) {
        throw new RegistrationError('Enter your full name.', 'full_name');
    }
    if (mb_strlen($fullName) > 150) {
        throw new RegistrationError('That name is too long. Use 150 characters or fewer.', 'full_name');
    }

    $phone = register_require_phone($phoneInput, 'Phone number', 'phone_number');

    $whatsapp = null;
    if ($waInput !== '') {
        $whatsapp = register_require_phone($waInput, 'WhatsApp number', 'whatsapp_number');
    }

    // Email stays optional. An empty string becomes NULL so it can never collide
    // with another applicant's empty email in the duplicate check.
    $email = null;
    if ($emailInput !== '') {
        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL) || mb_strlen($emailInput) > 190) {
            throw new RegistrationError('Enter a valid email address, or leave it blank.', 'email');
        }
        $email = $emailInput;
    }

    $nationalId = null;
    if ($nidInput !== '') {
        if (!preg_match('/^[A-Za-z0-9\- ]{4,32}$/', $nidInput)) {
            throw new RegistrationError('Enter a valid National ID, or leave it blank.', 'national_id');
        }
        $nationalId = strtoupper(preg_replace('/\s+/', '', $nidInput));
    }

    if ($districtId <= 0) {
        throw new RegistrationError('Select your district.', 'district_id');
    }
    if (mb_strlen($village) < 2) {
        throw new RegistrationError('Enter your village or town.', 'village');
    }
    if (mb_strlen($village) > 120) {
        throw new RegistrationError('That village or town name is too long.', 'village');
    }
    if ($userType !== 'farmer' && $business === '') {
        throw new RegistrationError('Enter your business or organisation name.', 'business_name');
    }
    if (mb_strlen($business) > 150) {
        throw new RegistrationError('That business name is too long.', 'business_name');
    }

    // The district must exist. Never trust the id the browser sent back.
    $stmt = $db->prepare('SELECT name FROM districts WHERE id = ? LIMIT 1');
    if (!$stmt) throw new RegistrationError('The district could not be verified. Please try again.');
    $stmt->bind_param('i', $districtId);
    $stmt->execute();
    $districtRow = agro_stmt_one($stmt);
    $stmt->close();
    if (!$districtRow) {
        throw new RegistrationError('That district could not be found. Choose one from the list.', 'district_id');
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
        throw new RegistrationError('Select at least one crop.', 'crops');
    }
    if (count($cropIds) > 40) {
        throw new RegistrationError('Select fewer crops.', 'crops');
    }
    $placeholders = implode(',', array_fill(0, count($cropIds), '?'));
    $cropStmt = $db->prepare("SELECT name FROM crops WHERE id IN ($placeholders) ORDER BY name ASC");
    if (!$cropStmt) throw new RegistrationError('Your crop selection could not be verified. Please try again.');
    $ids = array_values($cropIds);
    $cropStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $cropStmt->execute();
    $cropRows = agro_stmt_all($cropStmt);
    $cropStmt->close();
    if (count($cropRows) !== count($ids)) {
        throw new RegistrationError('One of the crops you selected is no longer available. Review your selection.', 'crops');
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
    throw new RegistrationError('Could not allocate an application reference. Please try again.');
}

/** Notify the applicant and the review team. Never blocks a saved application. */
function register_notify(array $app, string $ref): void
{
    $roleLabel = ucfirst($app['user_type']);

    if ($app['email'] !== null) {
        $html = email_html(
            '<h2 style="margin:0 0 16px;font-size:20px;color:#1f2937;">Application received</h2>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#374151;">Dear <strong>'
            . htmlspecialchars($app['full_name']) . '</strong>,</p>'
            . '<p style="margin:0 0 20px;font-size:15px;color:#374151;">Thank you for registering with '
            . 'AgroBusiness Malawi. Your application has been received and is under review.</p>'
            . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f9fafb;'
            . 'border-radius:6px;border:1px solid #e5e7eb;margin-bottom:24px;"><tbody>'
            . email_row('Reference', $ref)
            . email_row('Role', $roleLabel)
            . email_row('District', $app['district_name'])
            . email_row('Village / town', $app['village'])
            . email_row('Phone', $app['phone_number'])
            . '</tbody></table>'
            . '<p style="margin:0 0 24px;font-size:15px;color:#374151;">We will review your application and '
            . 'notify you within <strong>2&ndash;3 business days</strong>. Keep your reference number — you '
            . 'can check your status with it at any time.</p>'
            . '<a href="https://agrobusinessmw.com/status.php" style="display:inline-block;background:#16a34a;'
            . 'color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;'
            . 'font-size:15px;">Check application status</a>'
        );
        send_smtp_email($app['email'], "Application received — {$ref}", $html);
    }

    $adminEmail = trim((string)($_ENV['Username'] ?? 'info@promanaged-it.com'));
    if ($adminEmail === '') return;

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
        . email_row('Submitted', date('d M Y, H:i'))
        . '</tbody></table>'
    );
    send_smtp_email($adminEmail, "New application: {$ref} — {$roleLabel}", $adminHtml);
}

// ─── Preflight: warn about duplicates before the applicant finishes the form ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'preflight') {
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
        if (!is_array($body)) {
            throw new RegistrationError('The registration could not be read. Please try again.');
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
                'That ' . $first['label'] . ' is already registered under application '
                . $first['ref'] . ' (' . $first['status'] . '). Use "Check status" instead of registering again.',
                $first['field'] === 'phone' ? 'phone_number'
                    : ($first['field'] === 'whatsapp' ? 'whatsapp_number' : $first['field'])
            );
        }

        $ref  = register_reference($db);
        $stmt = $db->prepare(
            'INSERT INTO onboarding_applications
                (application_ref, user_type, full_name, phone_number, whatsapp_number, email,
                 national_id, district_id, village, crops_of_interest, business_name, channel)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$stmt) throw new RegistrationError('The registration could not be saved. Please try again.');

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
            throw new RegistrationError(
                $duplicateKey
                    ? 'An application with these details already exists. Use "Check status" to track it.'
                    : 'The registration could not be saved. Please try again.'
            );
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
        register_json(['success' => false, 'error' => $e->getMessage(), 'field' => $e->field]);
    } catch (Throwable $e) {
        if ($db instanceof mysqli) $db->close();
        // Never leak a driver message, a query or a credential to the browser.
        error_log('Registration failed: ' . $e->getMessage());
        register_json(['success' => false, 'error' => 'Registration is temporarily unavailable. Please try again shortly.', 'field' => '']);
    }
}

include __DIR__ . '/partials/head.php';
?>
<body class="register-page-body">
<?php include __DIR__ . '/partials/nav.php'; ?>
<header class="header register-topbar" role="banner">
    <div class="header-content">
        <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="app-nav"><span class="material-symbols-rounded" aria-hidden="true">menu</span></button>
        <h1 class="content-title">Register</h1>
        <a href="index.php" class="home-btn" aria-label="Home" title="Home"><span class="material-symbols-rounded" aria-hidden="true">home</span></a>
    </div>
</header>
<main id="content-area" class="register-page">
    <div class="register-shell">
        <nav class="register-breadcrumbs" aria-label="Breadcrumb"><a href="index.php">Home</a><span class="material-symbols-rounded" aria-hidden="true">chevron_right</span><strong>Register</strong></nav>
        <header class="register-header">
            <span class="register-eyebrow">AgroBusiness Malawi</span>
            <h2>Join the agricultural community</h2>
            <p>Register as a farmer, seller or buyer. Only your phone number is required — WhatsApp, email and National ID are optional.</p>
        </header>

        <form id="registration-form" novalidate>
            <ol class="register-progress" aria-label="Registration progress">
                <li class="active" data-progress="1"><span>1</span>Role</li>
                <li data-progress="2"><span>2</span>Details</li>
                <li data-progress="3"><span>3</span>Crops</li>
                <li data-progress="4"><span>4</span>Review</li>
            </ol>

            <section class="register-step active" data-step="1" aria-labelledby="step-1-heading">
                <h3 id="step-1-heading">How will you use AgroBusiness?</h3>
                <div class="register-role-grid" role="radiogroup" aria-labelledby="step-1-heading">
                    <button type="button" class="register-role" data-role="farmer" role="radio" aria-checked="false"><span aria-hidden="true">🧑‍🌾</span><strong>Farmer</strong><small>Grow and manage crops</small></button>
                    <button type="button" class="register-role" data-role="seller" role="radio" aria-checked="false"><span aria-hidden="true">🏪</span><strong>Seller</strong><small>Sell agricultural products</small></button>
                    <button type="button" class="register-role" data-role="buyer" role="radio" aria-checked="false"><span aria-hidden="true">🏢</span><strong>Buyer</strong><small>Source agricultural products</small></button>
                </div>
                <p class="register-error" id="error-user_type" role="alert"></p>
                <p class="register-help">Already applied? <a href="status.php">Check your application status</a>.</p>
            </section>

            <section class="register-step" data-step="2" hidden aria-labelledby="step-2-heading">
                <h3 id="step-2-heading">Your contact details</h3>
                <div class="register-grid">
                    <div class="register-field">
                        <label for="reg-full-name">Full name <abbr title="required">*</abbr></label>
                        <input id="reg-full-name" name="full_name" type="text" autocomplete="name" maxlength="150" required aria-describedby="error-full_name">
                        <p class="register-error" id="error-full_name" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-phone">Phone number <abbr title="required">*</abbr></label>
                        <input id="reg-phone" name="phone_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" placeholder="0888 123 456" required aria-describedby="reg-phone-help error-phone_number">
                        <small id="reg-phone-help" class="register-hint">Malawi numbers are saved as +265…. For any other country, include the country code, e.g. +44 7700 900123.</small>
                        <p class="register-error" id="error-phone_number" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-whatsapp">WhatsApp number <span class="register-optional">(optional)</span></label>
                        <input id="reg-whatsapp" name="whatsapp_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="24" placeholder="Same format as phone" aria-describedby="reg-whatsapp-help error-whatsapp_number">
                        <small id="reg-whatsapp-help" class="register-hint">Leave blank if you do not use WhatsApp.</small>
                        <p class="register-error" id="error-whatsapp_number" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-email">Email <span class="register-optional">(optional)</span></label>
                        <input id="reg-email" name="email" type="email" autocomplete="email" maxlength="190" aria-describedby="error-email">
                        <p class="register-error" id="error-email" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-national-id">National ID <span class="register-optional">(optional)</span></label>
                        <input id="reg-national-id" name="national_id" type="text" autocomplete="off" maxlength="32" aria-describedby="error-national_id">
                        <p class="register-error" id="error-national_id" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-village">Village / town <abbr title="required">*</abbr></label>
                        <input id="reg-village" name="village" type="text" autocomplete="address-level2" maxlength="120" required aria-describedby="error-village">
                        <p class="register-error" id="error-village" role="alert"></p>
                    </div>
                    <div class="register-field">
                        <label for="reg-district">District <abbr title="required">*</abbr></label>
                        <select id="reg-district" name="district_id" required aria-describedby="error-district_id">
                            <option value="">Loading districts…</option>
                        </select>
                        <p class="register-error" id="error-district_id" role="alert"></p>
                    </div>
                    <div class="register-field" id="business-field" hidden>
                        <label for="reg-business-name">Business / organisation name <abbr title="required">*</abbr></label>
                        <input id="reg-business-name" name="business_name" type="text" autocomplete="organization" maxlength="150" aria-describedby="error-business_name">
                        <p class="register-error" id="error-business_name" role="alert"></p>
                    </div>
                </div>
                <p class="register-error" id="error-step-2" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back>Back</button>
                    <button type="button" class="btn-primary" data-next>Continue</button>
                </div>
            </section>

            <section class="register-step" data-step="3" hidden aria-labelledby="step-3-heading">
                <h3 id="step-3-heading">What do you grow or trade?</h3>
                <p class="register-help">Select at least one crop.</p>
                <div id="reg-crops" class="register-crops" role="group" aria-labelledby="step-3-heading">Loading crops…</div>
                <p class="register-error" id="error-crops" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back>Back</button>
                    <button type="button" class="btn-primary" data-next>Review</button>
                </div>
            </section>

            <section class="register-step" data-step="4" hidden aria-labelledby="step-4-heading">
                <h3 id="step-4-heading">Review your registration</h3>
                <dl id="reg-review" class="register-review"></dl>
                <p class="register-note">By submitting you agree to KYC verification. We will review your application and give you a reference number.</p>
                <p class="register-error" id="error-submit" role="alert"></p>
                <div class="register-actions">
                    <button type="button" class="btn-secondary" data-back>Back</button>
                    <button type="submit" class="btn-primary" id="reg-submit">Submit application</button>
                </div>
            </section>

            <section class="register-success" hidden id="register-success" aria-live="polite">
                <div class="register-success-mark" aria-hidden="true">✓</div>
                <h3>Application submitted</h3>
                <p>Your reference number is</p>
                <strong id="reg-reference"></strong>
                <p>Keep this number safe — you need it to check your application status.</p>
                <div class="register-actions">
                    <a class="btn-primary" href="status.php">Check status</a>
                    <a class="btn-secondary" href="index.php">Back to home</a>
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
<script src="assets/js/phone-normalizer.js?v=20260816-0001"></script>
<script src="assets/js/register.js?v=20260816-0001" defer></script>
</body>
</html>
