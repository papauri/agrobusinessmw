<?php
/**
 * Shared script includes. Set $service (e.g. 'crop-prices') before including to make
 * the page boot straight into that function; leave unset for the home/dashboard page.
 */
$service = $service ?? null;
?>
<script>
    // Per-page entry point read by app.js boot hook. null = home/dashboard.
    window.AGRO_PAGE = <?= $service ? json_encode($service) : 'null' ?>;
</script>
<script>
    // Shared navigation drawer — self-contained, no app.js dependency.
    (function () {
        function nav() { return document.getElementById('app-nav'); }
        function open() {
            const n = nav(); if (!n) return;
            n.classList.add('open');
            n.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            const n = nav(); if (!n) return;
            n.classList.remove('open');
            n.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        document.addEventListener('click', function (e) {
            if (e.target.closest('.nav-toggle')) { e.preventDefault(); open(); return; }
            if (e.target.closest('[data-nav-close]')) { close(); return; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        document.addEventListener('DOMContentLoaded', function () {
            const here = (location.pathname.split('/').pop() || 'index.php');
            document.querySelectorAll('#app-nav .app-nav-link').forEach(function (a) {
                const key = a.getAttribute('data-nav');
                if ((here === '' || here === 'index.php') ? key === 'home' : key === here) {
                    a.classList.add('is-current');
                }
            });
        });
    })();
</script>
<script src="assets/js/app.js" defer></script>
<script src="assets/js/config.js"></script>
<script src="assets/js/sortable-table.js" defer></script>
<script src="assets/js/price-report-story.js" defer></script>
<link rel="stylesheet" href="assets/css/price-report-story.css">

<script>
    window.addEventListener('load', function () {
        if ('performance' in window && performance.getEntriesByType) {
            setTimeout(() => {
                const nav = performance.getEntriesByType('navigation')[0];
                if (nav && nav.loadEventEnd > 0) {
                    console.log('Page load time:', Math.round(nav.loadEventEnd) + 'ms');
                }
            }, 0);
        }

        // AgroBusiness no longer owns every service worker on the shared origin.
        // Only retire the legacy worker shipped by this project. This prevents a
        // separate application (for example another site under the same domain)
        // from being accidentally unregistered.
        if ('serviceWorker' in navigator) {
            const agroWorkerUrl = new URL('sw.js', window.location.href).href;
            navigator.serviceWorker.getRegistrations().then(regs => {
                regs.forEach(reg => {
                    if (reg.scriptURL === agroWorkerUrl) {
                        reg.unregister();
                    }
                });
            }).catch(() => {});
        }
    });
</script>

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "AgroBusiness Malawi",
    "description": "Smart agricultural platform for Malawian farmers with live weather, crop prices, and market insights",
    "url": "https://agrobusinessmw.com",
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "Any",
    "offers": { "@type": "Offer", "price": "0", "priceCurrency": "USD" },
    "author": { "@type": "Organization", "name": "AgroBusiness Malawi" }
}
</script>
