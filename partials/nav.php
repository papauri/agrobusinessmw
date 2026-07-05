<?php /* Shared slide-in navigation drawer. Included once per page; opened by any .nav-toggle button. */ ?>
<div id="app-nav" class="app-nav" role="dialog" aria-modal="true" aria-label="Site navigation" aria-hidden="true">
    <div class="app-nav-backdrop" data-nav-close></div>
    <nav class="app-nav-panel" aria-label="Main">
        <div class="app-nav-head">
            <span class="app-nav-brand"><span class="brand-icon">🌾</span> AgroBusiness</span>
            <button class="app-nav-close" data-nav-close aria-label="Close navigation">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <a class="app-nav-link" href="index.php" data-nav="home"><span class="material-symbols-rounded">home</span> Home</a>
        <a class="app-nav-link" href="prices.php" data-nav="prices.php"><span class="material-symbols-rounded">payments</span> Crop Prices</a>
        <a class="app-nav-link" href="weather.php" data-nav="weather.php"><span class="material-symbols-rounded">partly_cloudy_day</span> Weather</a>
        <a class="app-nav-link" href="market-insights.php" data-nav="market-insights.php"><span class="material-symbols-rounded">insights</span> Market Insights</a>
        <a class="app-nav-link" href="sellers.php" data-nav="sellers.php"><span class="material-symbols-rounded">storefront</span> Sellers</a>
        <a class="app-nav-link" href="buyers.php" data-nav="buyers.php"><span class="material-symbols-rounded">shopping_basket</span> Buyers</a>
        <a class="app-nav-link" href="pest-control.php" data-nav="pest-control.php"><span class="material-symbols-rounded">pest_control</span> Pest Control</a>
        <a class="app-nav-link" href="farming-tips.php" data-nav="farming-tips.php"><span class="material-symbols-rounded">tips_and_updates</span> Farming Tips</a>
        <a class="app-nav-link" href="farming-guide.php" data-nav="farming-guide.php"><span class="material-symbols-rounded">menu_book</span> Farming Guide</a>
        <a class="app-nav-link" href="basic-info.php" data-nav="basic-info.php"><span class="material-symbols-rounded">info</span> Basic Info</a>
        <div class="app-nav-sep"></div>
        <a class="app-nav-link" href="register.php" data-nav="register.php"><span class="material-symbols-rounded">app_registration</span> Register</a>
        <a class="app-nav-link" href="status.php" data-nav="status.php"><span class="material-symbols-rounded">fact_check</span> Check Status</a>
    </nav>
</div>
