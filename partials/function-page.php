<?php
/**
 * Shared body for every standalone function page. Set before including:
 *   $service    — service key read by app.js (e.g. 'crop-prices'); drives auto-open.
 *   $pageTitle  — <title>
 *   $pageDesc   — meta description
 *   $introHtml  — optional static HTML pre-filled into #content-area (register/status use this).
 */
$introHtml = $introHtml ?? '';
include __DIR__ . '/head.php';
?>

<body>
    <a href="#content-area" class="skip-link">Skip to main content</a>

    <!-- Loading Screen -->
    <div id="loading-screen" class="loading-screen" role="status" aria-label="Loading application">
        <div class="loading-content">
            <div class="loading-icon">🌾</div>
            <h1 class="loading-title">AgroBusiness</h1>
            <p class="loading-subtitle">Malawi</p>
            <div class="loading-progress">
                <div class="progress-bar"></div>
            </div>
            <p class="loading-text">Initializing...</p>
        </div>
        <div class="loading-stats">
            <div class="stat-pill"><span class="stat-count" id="loading-districts">28</span> Districts</div>
            <div class="stat-pill"><span class="stat-count" id="loading-crops">9</span> Crops</div>
            <div class="stat-pill">Live Weather</div>
            <div class="stat-pill">24/7 Prices</div>
        </div>
    </div>

    <noscript>
        <div style="text-align: center; padding: 2rem; background: var(--bg); color: var(--text);">
            <h2>JavaScript Required</h2>
            <p>AgroBusiness Malawi requires JavaScript to function properly. Please enable JavaScript to continue.</p>
        </div>
    </noscript>

    <?php
    if ($introHtml !== '') {
        ob_start();
        include __DIR__ . '/content-screen.php';
        $cs = ob_get_clean();
        echo str_replace('<!-- Dynamic content -->', $introHtml, $cs);
    } else {
        include __DIR__ . '/content-screen.php';
    }
    ?>
    <?php include __DIR__ . '/modals.php'; ?>
    <?php include __DIR__ . '/nav.php'; ?>
    <?php include __DIR__ . '/footer.php'; ?>
    <?php include __DIR__ . '/scripts.php'; ?>
</body>

</html>
