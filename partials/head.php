<?php
/**
 * Shared <head> for every page. Set these before including:
 *   $pageTitle (string)  — <title> + og:title
 *   $pageDesc  (string)  — meta description
 * Falls back to sensible defaults if unset.
 */
$pageTitle = $pageTitle ?? 'AgroBusiness Malawi';
$pageDesc  = $pageDesc  ?? 'Agricultural platform for Malawian farmers — live weather, crop prices, market insights across 28 districts.';
?>
<!DOCTYPE html>
<html lang="en" data-user-lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, shrink-to-fit=no">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta name="keywords"
        content="agriculture malawi, farming, crop prices, weather forecast, market insights, pest control, farming tips">
    <meta name="author" content="AgroBusiness Malawi">

    <!-- PWA & Mobile -->
    <meta name="theme-color" content="#f5f2eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="AgroBusiness">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16x16.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192x192.png">

    <!-- Preload Critical Resources -->
    <link rel="preload" href="assets/css/style.css" as="style">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://api.open-meteo.com">

    <!-- Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,300..600,0..1,0&display=block"
        media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,300..600,0..1,0&display=block">
    </noscript>

    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta property="og:image" content="/assets/icons/icon-512x512.png">
    <meta property="og:url" content="https://agrobusinessmw.com">
    <meta property="og:type" content="website">

    <!-- Styles -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Inline Critical CSS for Loading Screen -->
    <style>
        :root {
            --bg: #f5f2eb;
            --text: #3e3930;
            --accent: #8B7355;
            --gold: #C8A45A;
            --border: #d5cfc4;
            --surface: #ffffff;
            --muted: #9a8f83;
        }

        .skip-link {
            position: fixed;
            top: 0;
            left: 50%;
            transform: translateX(-50%) translateY(-100%);
            background: #000;
            color: white;
            padding: 8px 16px;
            z-index: 10000;
            text-decoration: none;
            border-radius: 0 0 4px 4px;
            transition: transform 0.2s ease;
            white-space: nowrap;
        }

        .skip-link:focus {
            transform: translateX(-50%) translateY(0);
        }

        .loading-state {
            text-align: center;
            padding: 2rem;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #ede9e0;
            border-top: 4px solid #8B7355;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
