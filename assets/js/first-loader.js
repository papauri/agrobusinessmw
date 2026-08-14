(function () {
    'use strict';

    // Cinematic first-visit loader: 3.2s once, then a short reveal on return visits.
    var FIRST_VISIT_MS = 3200;
    var RETURNING_MS = 650;
    var FLAG = 'agrobusiness:first-loader-seen';

    function boot() {
        var loader = document.getElementById('loading-screen');
        if (!loader) return;

        var firstVisit = false;
        try { firstVisit = localStorage.getItem(FLAG) !== '1'; } catch (e) { firstVisit = true; }
        var duration = firstVisit ? FIRST_VISIT_MS : RETURNING_MS;
        var started = performance.now();
        var released = false;

        loader.setAttribute('aria-live', 'polite');
        loader.setAttribute('data-first-visit', firstVisit ? 'true' : 'false');

        if (!loader.querySelector('.loader-privacy')) {
            var privacy = document.createElement('a');
            privacy.className = 'loader-privacy';
            privacy.href = 'privacy.php';
            privacy.textContent = 'Privacy Policy';
            privacy.setAttribute('aria-label', 'Read the AgroBusiness Malawi privacy policy');
            (loader.querySelector('.loading-content') || loader).appendChild(privacy);
        }

        var text = loader.querySelector('.loading-text');
        var bar = loader.querySelector('.progress-bar');

        function release() {
            if (released) return;
            released = true;
            try { localStorage.setItem(FLAG, '1'); } catch (e) {}
            loader.classList.add('loader-ready');
            setTimeout(function () { loader.style.display = 'none'; }, 700);
        }

        function update() {
            if (released) return;
            var elapsed = performance.now() - started;
            var pct = Math.min(100, Math.round((elapsed / duration) * 100));
            if (bar) bar.style.width = pct + '%';
            if (text) {
                var messages = firstVisit
                    ? ['Preparing your market', 'Loading districts', 'Checking crop data', 'Preparing weather', 'Almost ready']
                    : ['Opening AgroBusiness', 'Almost ready'];
                var index = Math.min(messages.length - 1, Math.floor((pct / 100) * messages.length));
                text.textContent = messages[index] + ' · ' + pct + '%';
            }
            if (elapsed >= duration) { release(); return; }
            requestAnimationFrame(update);
        }

        // Defer the existing app's early hide call until the cinematic minimum ends.
        var attempts = 0;
        var patchTimer = setInterval(function () {
            attempts++;
            if (window.app && typeof window.app.hideLoadingScreen === 'function' && !window.app.__loaderPatched) {
                var originalHide = window.app.hideLoadingScreen.bind(window.app);
                window.app.hideLoadingScreen = function () {
                    var remaining = Math.max(0, duration - (performance.now() - started));
                    if (remaining === 0) originalHide();
                    else setTimeout(originalHide, remaining);
                };
                window.app.__loaderPatched = true;
                clearInterval(patchTimer);
            } else if (attempts > 120) clearInterval(patchTimer);
        }, 25);

        // Safety net for direct style/class mutations by the existing boot code.
        var observer = new MutationObserver(function () {
            if (released) return;
            loader.style.opacity = '1';
            loader.style.transform = 'none';
            loader.style.filter = 'none';
            loader.style.pointerEvents = 'auto';
        });
        observer.observe(loader, { attributes: true, attributeFilter: ['style', 'class'] });
        update();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
