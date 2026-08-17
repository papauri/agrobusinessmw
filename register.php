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

// The registration RULES — validation, duplicates, the reference, the single
// INSERT — live in config/registration.php and are shared with the USSD channel.
// This file owns the web presentation of them: the form, the JSON contract and
// the notification email. Do not reintroduce a second copy of the rules here.
require_once __DIR__ . '/config/registration.php';
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

        $db = agro_db_connect();

        // Validation, duplicate detection, the reference and the INSERT are all
        // register_store(). The USSD channel calls the same function with
        // channel 'ussd', which is the whole point of it existing.
        $app = register_store($db, $body, 'web');
        $ref = $app['application_ref'];

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
