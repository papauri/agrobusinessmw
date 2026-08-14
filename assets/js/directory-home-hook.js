/* Route dashboard Sellers / Buyers actions to their contact-first pages. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
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
