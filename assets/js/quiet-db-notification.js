/* Hide the legacy connection-status toast from the public page.
   The application still handles API failures internally; this only removes
   the implementation-detail message from the user-facing UI. */
(function () {
    'use strict';

    const isDatabaseStatus = (node) => {
        const text = (node && node.textContent || '').trim().toLowerCase();
        return text === 'database connected' || text === 'database connection failed';
    };

    const removeDatabaseStatus = () => {
        document.querySelectorAll('.notification').forEach(node => {
            if (isDatabaseStatus(node)) node.remove();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeDatabaseStatus, { once: true });
    } else {
        removeDatabaseStatus();
    }

    new MutationObserver(removeDatabaseStatus).observe(document.documentElement, {
        childList: true,
        subtree: true
    });
})();
