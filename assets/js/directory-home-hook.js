/* Route dashboard Sellers / Buyers actions to their contact-first pages. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const page = location.pathname.split('/').pop() || 'index.php';
        // This hook is for the dashboard only. On sellers.php / buyers.php the
        // directory page owns app.openService; wrapping it here would redirect the
        // page to itself and can create a reload loop before the directory renders.
        if (page === 'sellers.php' || page === 'buyers.php') return;

        const app = window.app;
        if (!app || typeof app.openService !== 'function') return;
        const original = app.openService.bind(app);
        app.openService = function (service) {
            if (service === 'sellers') {
                window.location.href = 'sellers.php';
                return;
            }
            if (service === 'buyers') {
                window.location.href = 'buyers.php';
                return;
            }
            return original(service);
        };
    }, { once: true });
})();
