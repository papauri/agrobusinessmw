<?php
/**
 * Shared content screen — the surface every function page renders into.
 * app.js openService() targets #content-area; the boot hook opens the right service.
 */
?>
<!-- Content Screen -->
<div id="content" class="screen">
    <header class="header" role="banner">
        <div class="header-content">
            <button id="back-btn" class="back-btn" aria-label="Go back">
                <span class="material-symbols-rounded">arrow_back</span>
            </button>
            <button class="nav-toggle" aria-label="Open menu" aria-controls="app-nav">
                <span class="material-symbols-rounded">menu</span>
            </button>
            <h1 id="content-title" class="content-title">Service</h1>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <a href="index.php" class="home-btn" aria-label="Home" title="Home">
                    <span class="material-symbols-rounded">home</span>
                </a>
                <button class="share-btn" id="share-btn" aria-label="Share content">
                    <span class="material-symbols-rounded">share</span>
                </button>
                <div class="lang-switcher-smart content-lang-switcher">
                    <button class="lang-toggle lang-btn-content" id="content-lang-btn" aria-label="Change language" aria-expanded="false">
                        <span class="lang-flag-content" id="content-flag">🇬🇧</span>
                        <span class="lang-code-content" id="content-lang-code">EN</span>
                        <span class="lang-dropdown-arrow">▼</span>
                    </button>
                    <div class="lang-dropdown content-lang-dropdown" id="content-lang-dropdown" role="menu">
                        <button class="lang-option-smart" data-lang="en" role="menuitem" aria-label="Switch to English">
                            <span class="option-flag">🇬🇧</span>
                            <span class="option-name">English</span>
                            <span class="option-code">EN</span>
                        </button>
                        <button class="lang-option-smart" data-lang="ci" role="menuitem" aria-label="Switch to Chichewa">
                            <span class="option-flag">🇲🇼</span>
                            <span class="option-name">Chichewa</span>
                            <span class="option-code">CI</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content" role="main">
        <div class="container">
            <div id="content-area" class="content-area" aria-live="polite">
                <!-- Dynamic content -->
            </div>
        </div>
    </main>
</div>
