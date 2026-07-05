<?php
$pageTitle = 'AgroBusiness Malawi - Agricultural Platform';
$pageDesc  = 'Complete agricultural platform with live weather, crop prices, and market insights for Malawian farmers. 28 districts coverage.';
include __DIR__ . '/partials/head.php';
?>

<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

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

    <!-- Language Selection -->
    <div id="language-selection" class="screen active" role="main" aria-label="Language selection">
        <div class="container lang-container">
            <div class="lang-header">
                <div class="lang-icon">🌾</div>
                <h1 data-text="welcome">Welcome to AgroBusiness</h1>
                <p data-text="subtitle">Your agricultural platform</p>
            </div>
            <div class="lang-cards">
                <div class="lang-card" data-lang="en" lang="en" role="button" tabindex="0" aria-label="Select English">
                    <div class="lang-card-content">
                        <h2>English</h2>
                        <p>International Language</p>
                        <span class="lang-arrow">→</span>
                    </div>
                </div>
                <div class="lang-card" data-lang="ci" lang="ny" role="button" tabindex="0" aria-label="Select Chichewa">
                    <div class="lang-card-content">
                        <h2>Chichewa</h2>
                        <p>Chinenero cha Malawi</p>
                        <span class="lang-arrow">→</span>
                    </div>
                </div>
            </div>
            <div class="hero-ctas" role="group" aria-label="Primary actions">
                <a class="btn-primary" href="prices.php" aria-label="View prices">Get Prices</a>
                <a class="btn-secondary" href="register.php" aria-label="Register">Register</a>
            </div>
        </div>
    </div>

    <!-- Main Dashboard -->
    <div id="dashboard" class="screen">
        <header class="header" role="banner" aria-label="Main navigation">
            <div class="header-content">
                <div class="brand">
                    <button class="nav-toggle" aria-label="Open menu" aria-controls="app-nav">
                        <span class="material-symbols-rounded">menu</span>
                    </button>
                    <span class="brand-icon">🌾</span>
                    <div class="brand-text">
                        <span class="brand-name">AgroBusiness</span>
                        <span class="brand-location">Malawi</span>
                    </div>
                </div>
                <div class="lang-switcher-smart">
                    <button class="lang-toggle" id="current-lang-btn" aria-label="Change language" aria-expanded="false">
                        <span id="current-flag">🇬🇧</span>
                        <span id="current-lang" class="lang-code">EN</span>
                        <span class="lang-dropdown-arrow">▼</span>
                    </button>
                    <div class="lang-dropdown" id="lang-dropdown" role="menu">
                        <button class="lang-option-smart" data-lang="en" role="menuitem" aria-label="Switch to English">
                            <span class="option-flag">🇬🇧</span><span class="option-name">English</span><span class="option-code">EN</span>
                        </button>
                        <button class="lang-option-smart" data-lang="ci" role="menuitem" aria-label="Switch to Chichewa">
                            <span class="option-flag">🇲🇼</span><span class="option-name">Chichewa</span><span class="option-code">CI</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="main-content" id="main-content" role="main">
            <div class="container">
                <section class="dash-hero" aria-labelledby="dash-hero-title">
                    <div class="dash-hero-text">
                        <p class="dash-hero-eyebrow">🌾 AgroBusiness Malawi</p>
                        <h1 id="dash-hero-title">Smarter farming decisions, every day.</h1>
                        <p class="dash-hero-sub">Live crop prices, weather and market intelligence for all 28 districts —
                            in English and Chichewa, on any phone.</p>
                        <div class="dash-hero-cta">
                            <a class="btn-primary" href="prices.php">View Crop Prices</a>
                            <a class="btn-secondary" href="register.php">Register Free</a>
                        </div>
                    </div>
                    <div class="dash-stats" role="list" aria-label="Platform coverage">
                        <div class="dash-stat" role="listitem">
                            <span class="dash-stat-num">28</span><span class="dash-stat-label">Districts</span>
                        </div>
                        <div class="dash-stat" role="listitem">
                            <span class="dash-stat-num">9</span><span class="dash-stat-label">Crops</span>
                        </div>
                        <div class="dash-stat" role="listitem">
                            <span class="dash-stat-num">Live</span><span class="dash-stat-label">Weather</span>
                        </div>
                        <div class="dash-stat" role="listitem">
                            <span class="dash-stat-num">24/7</span><span class="dash-stat-label">Prices</span>
                        </div>
                    </div>
                </section>

                <section class="services" aria-labelledby="services-heading">
                    <h2 id="services-heading" class="section-title">Agricultural Services</h2>
                    <div class="services-grid">
                        <a class="service-card" href="prices.php" aria-label="View crop prices">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <circle cx="24" cy="24" r="22" fill="currentColor" opacity=".12" />
                                    <path d="M24 6c-1.1 0-2 .9-2 2v1.1A9 9 0 0 0 24 27a9 9 0 0 0 2-17.9V8c0-1.1-.9-2-2-2Z" fill="currentColor" />
                                    <path d="M18 16c-3.3 1.5-5 4.2-5 7.2 0 4.7 4.9 8.8 11 8.8s11-4.1 11-8.8c0-3-1.7-5.7-5-7.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
                                    <line x1="24" y1="32" x2="24" y2="40" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <line x1="18" y1="38" x2="30" y2="38" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <text x="24" y="26" font-family="sans-serif" font-size="10" font-weight="700" text-anchor="middle" fill="currentColor">MK</text>
                                </svg>
                            </div>
                            <h3 data-text="crop_prices">Crop Prices</h3>
                            <p data-text="crop_prices_desc">Live market prices</p>
                        </a>

                        <a class="service-card" href="weather.php" aria-label="Check weather">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <circle cx="20" cy="19" r="7" fill="currentColor" />
                                    <path d="M20 8V6M20 32v-2M9 19H7M33 19h-2M12.1 11.1 10.7 9.7M27.9 11.1l1.4-1.4M12.1 26.9l-1.4 1.4M27.9 26.9l1.4 1.4" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <rect x="8" y="30" width="32" height="10" rx="5" fill="currentColor" opacity=".25" />
                                    <rect x="14" y="28" width="22" height="10" rx="5" fill="currentColor" opacity=".5" />
                                    <rect x="20" y="26" width="20" height="10" rx="5" fill="currentColor" />
                                </svg>
                            </div>
                            <h3 data-text="weather">Weather</h3>
                            <p data-text="weather_desc">7-day forecast</p>
                        </a>

                        <a class="service-card" href="market-insights.php" aria-label="View market insights">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect x="6" y="30" width="8" height="12" rx="2" fill="currentColor" opacity=".5" />
                                    <rect x="18" y="22" width="8" height="20" rx="2" fill="currentColor" opacity=".7" />
                                    <rect x="30" y="14" width="8" height="28" rx="2" fill="currentColor" />
                                    <polyline points="10,28 22,20 34,12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                                    <circle cx="10" cy="28" r="2.5" fill="currentColor" />
                                    <circle cx="22" cy="20" r="2.5" fill="currentColor" />
                                    <circle cx="34" cy="12" r="2.5" fill="currentColor" />
                                </svg>
                            </div>
                            <h3 data-text="market_insights">Market Insights</h3>
                            <p data-text="market_insights_desc">District intelligence</p>
                        </a>

                        <a class="service-card" href="sellers.php" aria-label="Find sellers">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <circle cx="24" cy="14" r="7" fill="currentColor" />
                                    <path d="M10 38c0-7.7 6.3-14 14-14s14 6.3 14 14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none" />
                                    <path d="M30 28 l4-4 6 6-4 4-6-6Z" fill="currentColor" opacity=".7" />
                                    <line x1="36" y1="32" x2="42" y2="38" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                            </div>
                            <h3 data-text="find_sellers">Sellers</h3>
                            <p data-text="find_sellers_desc">Connect with suppliers</p>
                        </a>

                        <a class="service-card" href="buyers.php" aria-label="Find buyers">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect x="8" y="20" width="32" height="22" rx="3" fill="currentColor" opacity=".2" stroke="currentColor" stroke-width="2" />
                                    <path d="M6 20 L24 8 L42 20" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                                    <rect x="18" y="28" width="12" height="14" rx="2" fill="currentColor" opacity=".6" />
                                </svg>
                            </div>
                            <h3 data-text="find_buyers">Buyers</h3>
                            <p data-text="find_buyers_desc">Find markets</p>
                        </a>

                        <a class="service-card" href="pest-control.php" aria-label="Pest control info">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M24 6 C14 6 8 14 8 22 C8 32 16 42 24 42 C32 42 40 32 40 22 C40 14 34 6 24 6Z" fill="currentColor" opacity=".15" stroke="currentColor" stroke-width="2" />
                                    <path d="M24 42 V24" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                    <path d="M24 24 C20 20 14 20 10 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                    <path d="M24 24 C28 20 34 20 38 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                    <circle cx="36" cy="12" r="8" fill="#ef4444" />
                                    <line x1="33" y1="12" x2="39" y2="12" stroke="white" stroke-width="2.5" stroke-linecap="round" />
                                    <line x1="36" y1="9" x2="36" y2="15" stroke="white" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                            </div>
                            <h3 data-text="pest_control">Pest Control</h3>
                            <p data-text="pest_control_desc">Prevention &amp; solutions</p>
                        </a>

                        <a class="service-card" href="farming-tips.php" aria-label="Farming tips">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <line x1="24" y1="14" x2="24" y2="42" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                    <ellipse cx="24" cy="10" rx="6" ry="9" fill="currentColor" />
                                    <ellipse cx="16" cy="16" rx="5" ry="8" fill="currentColor" transform="rotate(-25 16 16)" />
                                    <ellipse cx="32" cy="16" rx="5" ry="8" fill="currentColor" transform="rotate(25 32 16)" />
                                </svg>
                            </div>
                            <h3 data-text="farming_tips">Farming Tips</h3>
                            <p data-text="farming_tips_desc">Expert practices</p>
                        </a>

                        <a class="service-card" href="farming-guide.php" aria-label="Farming guide">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <circle cx="24" cy="24" r="22" fill="currentColor" opacity=".08" />
                                    <path d="M14 34h20" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <path d="M24 34v-12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <path d="M18 28c0-2 2-4 6-6 4 2 6 4 6 6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none" />
                                    <path d="M10 18h28" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <path d="M14 14v-4h20v4" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                </svg>
                            </div>
                            <h3 data-text="farming_guide">Farming Guide</h3>
                            <p data-text="farming_guide_desc">Step-by-step planting and care</p>
                        </a>

                        <a class="service-card" href="basic-info.php" aria-label="Basic info">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect x="10" y="6" width="28" height="36" rx="4" fill="currentColor" opacity=".15" stroke="currentColor" stroke-width="2" />
                                    <line x1="16" y1="16" x2="32" y2="16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <line x1="16" y1="22" x2="32" y2="22" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <line x1="16" y1="28" x2="26" y2="28" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                                    <circle cx="36" cy="36" r="8" fill="currentColor" />
                                    <text x="36" y="40" font-family="sans-serif" font-size="11" font-weight="700" text-anchor="middle" fill="white">i</text>
                                </svg>
                            </div>
                            <h3 data-text="basic_info">Basic Info</h3>
                            <p data-text="basic_info_desc">Essential knowledge</p>
                        </a>

                        <a class="service-card service-card--accent" href="register.php" aria-label="Register account">
                            <div class="service-icon">
                                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <rect x="10" y="6" width="28" height="36" rx="4" fill="currentColor" opacity=".15" stroke="currentColor" stroke-width="2" />
                                    <rect x="18" y="4" width="12" height="6" rx="3" fill="currentColor" />
                                    <polyline points="17,26 22,31 32,20" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                                </svg>
                            </div>
                            <h3>Register</h3>
                            <p>Join as Farmer, Seller or Buyer</p>
                        </a>
                    </div>
                </section>
            </div>

            <footer class="dash-footer">
                <div class="container dash-footer-inner">
                    <div class="dash-footer-brand">
                        <span class="brand-icon">🌾</span>
                        <div>
                            <strong>AgroBusiness Malawi</strong>
                            <span>Growing knowledge for every farmer.</span>
                        </div>
                    </div>
                    <nav class="dash-footer-links" aria-label="Footer">
                        <a href="prices.php">Prices</a>
                        <a href="weather.php">Weather</a>
                        <a href="sellers.php">Sellers</a>
                        <a href="buyers.php">Buyers</a>
                        <a href="register.php">Register</a>
                        <a href="status.php">Check Status</a>
                    </nav>
                    <p class="dash-footer-copy">© <?= date('Y') ?> AgroBusiness Malawi · Available on web &amp; USSD</p>
                </div>
            </footer>
        </main>
    </div>

    <?php include __DIR__ . '/partials/content-screen.php'; ?>
    <?php include __DIR__ . '/partials/modals.php'; ?>
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <?php $service = null; include __DIR__ . '/partials/scripts.php'; ?>
</body>

</html>
