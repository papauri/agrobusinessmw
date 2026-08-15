<?php
/**
 * AgroBusiness Malawi — Registration
 *
 * This page owns the web registration flow. GET renders the form; POST validates,
 * normalizes contact numbers, checks duplicates, and stores the application.
 */
$pageTitle = 'Register — AgroBusiness Malawi';
$pageDesc  = 'Register as a farmer, seller or buyer on AgroBusiness Malawi.';

function register_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function register_normalize_phone(string $value, string $defaultCountry = '265'): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $value = preg_replace('/[\s().-]+/', '', $value);
    if ($value === '') return null;

    if (str_starts_with($value, '00')) {
        $value = '+' . substr($value, 2);
    } elseif (!str_starts_with($value, '+') && preg_match('/^0[0-9]{9}$/', $value)) {
        $value = '+' . $defaultCountry . substr($value, 1);
    } elseif (!str_starts_with($value, '+') && preg_match('/^[1-9][0-9]{8}$/', $value)) {
        $value = '+' . $defaultCountry . $value;
    }

    return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) ? $value : null;
}

function register_env(): void {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

register_env();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) throw new RuntimeException('Invalid registration request.');

        $userType   = trim((string)($body['user_type'] ?? ''));
        $fullName   = trim((string)($body['full_name'] ?? ''));
        $phoneInput = trim((string)($body['phone_number'] ?? ''));
        $waInput    = trim((string)($body['whatsapp_number'] ?? ''));
        $emailInput = trim((string)($body['email'] ?? ''));
        $nationalInput = trim((string)($body['national_id'] ?? ''));
        $districtId = (int)($body['district_id'] ?? 0);
        $village    = trim((string)($body['village'] ?? ''));
        $crops      = trim((string)($body['crops_of_interest'] ?? ''));
        $business   = trim((string)($body['business_name'] ?? ''));

        if (!in_array($userType, ['farmer', 'seller', 'buyer'], true)) throw new RuntimeException('Select whether you are registering as a farmer, seller or buyer.');
        if (mb_strlen($fullName) < 2) throw new RuntimeException('Full name is required.');

        $phone = register_normalize_phone($phoneInput);
        if (!$phone) throw new RuntimeException('Enter a valid phone number. Example: 0888 123 456 or +447700900123.');

        $whatsapp = register_normalize_phone($waInput);
        if ($waInput !== '' && !$whatsapp) throw new RuntimeException('Enter a valid WhatsApp number or leave it blank.');
        $email = $emailInput !== '' ? $emailInput : null;
        $nationalId = $nationalInput !== '' ? $nationalInput : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address or leave it blank.');
        if ($districtId <= 0) throw new RuntimeException('District is required.');
        if (mb_strlen($village) < 2) throw new RuntimeException('Village / town is required.');
        if ($userType !== 'farmer' && $business === '') throw new RuntimeException('Business name is required for sellers and buyers.');
        if ($crops === '') throw new RuntimeException('Select at least one crop.');

        $host = $_ENV['DB_HOST'] ?? '';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $name = $_ENV['DB_NAME'] ?? '';
        $port = (int)($_ENV['DB_PORT'] ?? 3306);
        if ($host === '' || $user === '' || $name === '') throw new RuntimeException('Database configuration is incomplete.');

        $db = mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        if (!@$db->real_connect($host, $user, $pass, $name, $port)) throw new RuntimeException('Could not connect to the registration database.');
        $db->set_charset('utf8mb4');

        $districtStmt = $db->prepare('SELECT id FROM districts WHERE id = ? LIMIT 1');
        if (!$districtStmt) throw new RuntimeException('Could not validate the district.');
        $districtStmt->bind_param('i', $districtId);
        $districtStmt->execute();
        $districtStmt->bind_result($foundDistrict);
        $hasDistrict = $districtStmt->fetch();
        $districtStmt->close();
        if (!$hasDistrict) throw new RuntimeException('The selected district could not be found.');

        $dupSql = 'SELECT application_ref FROM onboarding_applications WHERE phone_number = ? OR (? IS NOT NULL AND email = ?) OR (? IS NOT NULL AND national_id = ?) LIMIT 1';
        $dup = $db->prepare($dupSql);
        if (!$dup) throw new RuntimeException('Could not check existing applications.');
        $dup->bind_param('sssss', $phone, $email, $email, $nationalId, $nationalId);
        $dup->execute();
        $dup->bind_result($existingRef);
        if ($dup->fetch()) {
            $dup->close();
            throw new RuntimeException("An application for this phone number, email or National ID already exists. Reference: {$existingRef}.");
        }
        $dup->close();

        if ($whatsapp !== null) {
            $waDup = $db->prepare('SELECT application_ref FROM onboarding_applications WHERE whatsapp_number = ? LIMIT 1');
            if (!$waDup) throw new RuntimeException('Could not check the WhatsApp number.');
            $waDup->bind_param('s', $whatsapp);
            $waDup->execute();
            $waDup->bind_result($waRef);
            if ($waDup->fetch()) {
                $waDup->close();
                throw new RuntimeException("That WhatsApp number is already associated with application {$waRef}.");
            }
            $waDup->close();
        }

        if ($userType === 'farmer') $business = null;
        $ref = 'AGR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $stmt = $db->prepare('INSERT INTO onboarding_applications (application_ref, user_type, full_name, phone_number, whatsapp_number, email, national_id, district_id, village, crops_of_interest, business_name, channel) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$stmt) throw new RuntimeException('Could not prepare the registration.');
        $channel = 'web';
        $stmt->bind_param('sssssssissss', $ref, $userType, $fullName, $phone, $whatsapp, $email, $nationalId, $districtId, $village, $crops, $business, $channel);
        if (!$stmt->execute()) throw new RuntimeException('The registration could not be saved.');
        $stmt->close();
        $db->close();

        register_json(['success' => true, 'reference' => $ref, 'phone_number' => $phone, 'whatsapp_number' => $whatsapp]);
    } catch (Throwable $e) {
        register_json(['success' => false, 'error' => $e->getMessage()], 200);
    }
}

include __DIR__ . '/partials/head.php';
?>
<body class="register-page-body">
<?php include __DIR__ . '/partials/nav.php'; ?>
<header class="header register-topbar" role="banner">
    <div class="header-content">
        <button class="nav-toggle" aria-label="Open menu" aria-controls="app-nav"><span class="material-symbols-rounded">menu</span></button>
        <h1 class="content-title">Register</h1>
        <a href="index.php" class="home-btn" aria-label="Home" title="Home"><span class="material-symbols-rounded">home</span></a>
    </div>
</header>
<main id="content-area" class="register-page">
    <div class="register-shell">
        <nav class="register-breadcrumbs" aria-label="Breadcrumb"><a href="index.php">Home</a><span class="material-symbols-rounded">chevron_right</span><strong>Register</strong></nav>
        <header class="register-header">
            <span class="register-eyebrow">AgroBusiness Malawi</span>
            <h2>Join the agricultural community</h2>
            <p>Register as a farmer, seller or buyer. Your phone is required; WhatsApp and email are optional.</p>
        </header>
        <form id="registration-form" novalidate>
            <div class="register-progress" aria-label="Registration progress"><span class="active" data-progress="1">1</span><i></i><span data-progress="2">2</span><i></i><span data-progress="3">3</span><i></i><span data-progress="4">4</span></div>
            <section class="register-step active" data-step="1">
                <h3>How will you use AgroBusiness?</h3>
                <div class="register-role-grid">
                    <button type="button" class="register-role" data-role="farmer"><span>🧑‍🌾</span><strong>Farmer</strong><small>Grow and manage crops</small></button>
                    <button type="button" class="register-role" data-role="seller"><span>🏪</span><strong>Seller</strong><small>Sell agricultural products</small></button>
                    <button type="button" class="register-role" data-role="buyer"><span>🏢</span><strong>Buyer</strong><small>Source agricultural products</small></button>
                </div>
                <p class="register-error" id="role-error"></p>
            </section>
            <section class="register-step" data-step="2" hidden>
                <h3>Your contact details</h3>
                <div class="register-grid">
                    <label>Full name *<input id="reg-full-name" autocomplete="name" required></label>
                    <label>Phone number *<input id="reg-phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0888 123 456 or +44 7700 900123" required><small>Malawi local numbers are converted to +265. International numbers should include +country code.</small></label>
                    <label>WhatsApp number <span>(optional)</span><input id="reg-whatsapp" type="tel" inputmode="tel" autocomplete="tel" placeholder="Same format as phone"><small>Leave blank if you do not use WhatsApp.</small></label>
                    <label>Email <span>(optional)</span><input id="reg-email" type="email" autocomplete="email"></label>
                    <label>National ID <span>(optional)</span><input id="reg-national-id" autocomplete="off"></label>
                    <label>Village / Town *<input id="reg-village" required></label>
                    <label>District *<select id="reg-district" required><option value="">Loading districts…</option></select></label>
                    <label id="business-wrap">Business / organisation name *<input id="reg-business-name"></label>
                </div>
                <p class="register-error" id="contact-error"></p>
                <div class="register-actions"><button type="button" class="btn-secondary" data-back>Back</button><button type="button" class="btn-primary" data-next>Continue</button></div>
            </section>
            <section class="register-step" data-step="3" hidden>
                <h3>What do you grow or trade?</h3>
                <p>Select at least one crop.</p>
                <div id="reg-crops" class="register-crops">Loading crops…</div>
                <p class="register-error" id="crop-error"></p>
                <div class="register-actions"><button type="button" class="btn-secondary" data-back>Back</button><button type="button" class="btn-primary" data-next>Review</button></div>
            </section>
            <section class="register-step" data-step="4" hidden>
                <h3>Review your registration</h3>
                <div id="reg-review" class="register-review"></div>
                <p class="register-note">By submitting, you agree to KYC verification. We will review your application and provide a reference number.</p>
                <p class="register-error" id="submit-error"></p>
                <div class="register-actions"><button type="button" class="btn-secondary" data-back>Back</button><button type="submit" class="btn-primary" id="reg-submit">Submit application</button></div>
            </section>
            <section class="register-success" hidden id="register-success"><div>✓</div><h3>Application submitted</h3><p>Your reference number is</p><strong id="reg-reference"></strong><p>Keep this number to check your application status.</p><a class="btn-primary" href="status.php">Check status</a></section>
        </form>
    </div>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
<script src="assets/js/phone-normalizer.js?v=20260815-0002"></script>
<script src="assets/js/register.js?v=20260815-0001" defer></script>
<link rel="stylesheet" href="assets/css/register.css?v=20260815-0001">
<script>
(function(){
  function nav(){return document.getElementById('app-nav');}
  function open(){var n=nav();if(!n)return;n.classList.add('open');n.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}
  function close(){var n=nav();if(!n)return;n.classList.remove('open');n.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  document.addEventListener('click',function(e){if(e.target.closest('.nav-toggle')){e.preventDefault();open();}else if(e.target.closest('[data-nav-close]'))close();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
})();
</script>
</body></html>
