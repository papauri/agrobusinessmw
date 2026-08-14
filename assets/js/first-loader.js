(function () {
    'use strict';

    // Agricultural pencil-sketch first-visit loader.
    var FIRST_VISIT_MS = 3200;
    var RETURNING_MS = 650;
    var FLAG = 'agrobusiness:first-loader-seen';

    function injectSketchStyles() {
        if (document.getElementById('agro-sketch-loader-styles')) return;
        var style = document.createElement('style');
        style.id = 'agro-sketch-loader-styles';
        style.textContent = `
            #loading-screen.agro-sketch-loader {
                background:#f4efe4;
                color:#3d382f;
                overflow:hidden;
                isolation:isolate;
            }
            #loading-screen.agro-sketch-loader::before,
            #loading-screen.agro-sketch-loader::after {
                content:""; position:absolute; inset:0; pointer-events:none;
            }
            #loading-screen.agro-sketch-loader::before {
                opacity:.18;
                background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180' viewBox='0 0 180 180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.18'/%3E%3C/svg%3E");
                mix-blend-mode:multiply;
            }
            #loading-screen.agro-sketch-loader::after {
                opacity:.28;
                background:repeating-linear-gradient(0deg,transparent 0 5px,rgba(63,56,47,.035) 6px,transparent 7px);
            }
            .agro-sketch-art { position:relative; width:min(88vw,520px); height:290px; margin:0 auto .5rem; }
            .agro-sketch-paper { position:absolute; inset:0; }
            .agro-sketch-paper svg { width:100%; height:100%; overflow:visible; }
            .agro-sketch-line { fill:none; stroke:#4b453b; stroke-width:1.45; stroke-linecap:round; stroke-linejoin:round; opacity:.78; }
            .agro-sketch-light { fill:none; stroke:#81786b; stroke-width:.8; stroke-linecap:round; opacity:.58; }
            .agro-sketch-dark { fill:none; stroke:#302b24; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; opacity:.8; }
            .agro-sketch-fill { fill:#756b5d; opacity:.10; }
            .agro-sketch-title { font-family:Georgia,'Times New Roman',serif!important; font-size:clamp(2.1rem,7vw,3.5rem)!important; letter-spacing:.015em; color:#302b24!important; }
            .agro-sketch-subtitle { font-family:Georgia,'Times New Roman',serif!important; font-style:italic; color:#6f665a!important; letter-spacing:.08em; }
            .agro-sketch-rule { width:min(260px,65vw); height:1px; background:#807568; opacity:.45; margin:.75rem auto 1rem; }
            .agro-sketch-progress { width:min(330px,72vw)!important; height:4px!important; border:1px solid rgba(75,69,59,.35); background:rgba(255,255,255,.35)!important; border-radius:0!important; overflow:hidden; }
            .agro-sketch-progress .progress-bar { height:100%; background:#4b453b!important; border-radius:0!important; box-shadow:none!important; transition:width .08s linear; }
            .agro-sketch-loader .loading-text { font-family:Georgia,'Times New Roman',serif; color:#62594e; font-size:.82rem; letter-spacing:.05em; }
            .agro-sketch-stats { display:flex; justify-content:center; gap:.55rem; flex-wrap:wrap; margin-top:1.25rem; }
            .agro-sketch-stats .stat-pill { background:transparent!important; border:1px solid rgba(75,69,59,.28)!important; border-radius:2px!important; color:#5e554a!important; box-shadow:none!important; font-family:Georgia,'Times New Roman',serif; font-size:.72rem; letter-spacing:.04em; }
            .agro-sketch-privacy { display:inline-block; margin-top:1.1rem; color:#675e52!important; text-decoration:underline; text-underline-offset:3px; font-size:.72rem; }
            .agro-sketch-caption { font-family:Georgia,'Times New Roman',serif; font-size:.7rem; color:#776e62; margin-top:.35rem; letter-spacing:.06em; }
            @media (prefers-reduced-motion:reduce) {
                .agro-sketch-line,.agro-sketch-light { animation:none!important; }
            }
        `;
        document.head.appendChild(style);
    }

    function buildSketch(loader) {
        loader.classList.add('agro-sketch-loader');
        loader.innerHTML = `
            <div class="loading-content">
                <div class="agro-sketch-art" aria-hidden="true">
                    <div class="agro-sketch-paper">
                        <svg viewBox="0 0 520 290" role="presentation">
                            <!-- Malawi field contours -->
                            <path class="agro-sketch-light" d="M28 238 C95 218 128 229 184 216 S290 218 343 202 S440 211 494 190"/>
                            <path class="agro-sketch-light" d="M18 252 C92 232 136 245 194 230 S294 237 352 216 S443 224 505 205"/>
                            <path class="agro-sketch-light" d="M54 266 C126 247 174 257 228 243 S331 247 389 232 S454 239 490 229"/>
                            <!-- maize stalk -->
                            <path class="agro-sketch-dark" d="M250 224 C249 190 250 147 252 104"/>
                            <path class="agro-sketch-line" d="M251 171 C226 153 209 143 184 143 C203 160 222 170 250 179"/>
                            <path class="agro-sketch-line" d="M251 192 C274 177 294 169 321 168 C301 185 278 195 251 201"/>
                            <path class="agro-sketch-line" d="M252 134 C230 121 215 109 202 93 C222 102 240 113 253 122"/>
                            <!-- maize ear -->
                            <path class="agro-sketch-dark" d="M252 105 C239 91 238 73 250 57 C265 70 269 89 252 105 Z"/>
                            <path class="agro-sketch-light" d="M246 64 l10 37 M242 72 l13 25 M252 60 l-2 43"/>
                            <!-- loose pencil hatching -->
                            <path class="agro-sketch-light" d="M220 220 l-18 14 M228 218 l-17 15 M284 221 l18 13 M293 218 l18 14 M166 231 l-16 10 M352 224 l18 11"/>
                            <!-- farmer hand-tool silhouette -->
                            <path class="agro-sketch-line" d="M104 225 C112 198 130 179 151 171"/>
                            <path class="agro-sketch-dark" d="M148 173 l31 -27"/>
                            <path class="agro-sketch-line" d="M178 146 l13 -5 l-7 12 Z"/>
                            <!-- sun / field mark -->
                            <circle class="agro-sketch-light" cx="410" cy="75" r="24"/>
                            <path class="agro-sketch-light" d="M410 42 v-12 M410 108 v12 M377 75 h-12 M443 75 h12 M387 52 l-9 -9 M433 98 l9 9 M433 52 l9 -9 M387 98 l-9 9"/>
                            <!-- tiny birds -->
                            <path class="agro-sketch-light" d="M93 73 q7 -7 14 0 q7 -7 14 0 M339 58 q6 -6 12 0 q6 -6 12 0"/>
                        </svg>
                    </div>
                </div>
                <h1 class="loading-title agro-sketch-title">AgroBusiness</h1>
                <p class="loading-subtitle agro-sketch-subtitle">Malawi · grown from the ground up</p>
                <div class="agro-sketch-rule"></div>
                <div class="loading-progress agro-sketch-progress"><div class="progress-bar"></div></div>
                <p class="loading-text">Preparing the fields · 0%</p>
                <p class="agro-sketch-caption">Crops · markets · weather · farmers</p>
                <a class="loader-privacy agro-sketch-privacy" href="privacy.php">Privacy Policy</a>
            </div>
            <div class="loading-stats agro-sketch-stats">
                <div class="stat-pill"><span class="stat-count" id="loading-districts">28</span> Districts</div>
                <div class="stat-pill"><span class="stat-count" id="loading-crops">9</span> Crops</div>
                <div class="stat-pill">Live Weather</div>
                <div class="stat-pill">24/7 Prices</div>
            </div>
        `;
    }

    function boot() {
        var loader = document.getElementById('loading-screen');
        if (!loader) return;

        injectSketchStyles();
        buildSketch(loader);

        var firstVisit = false;
        try { firstVisit = localStorage.getItem(FLAG) !== '1'; } catch (e) { firstVisit = true; }
        var duration = firstVisit ? FIRST_VISIT_MS : RETURNING_MS;
        var started = performance.now();
        var released = false;

        loader.setAttribute('aria-live', 'polite');
        loader.setAttribute('data-first-visit', firstVisit ? 'true' : 'false');

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
                    ? ['Sketching the fields', 'Mapping districts', 'Gathering crop data', 'Reading the weather', 'Almost ready']
                    : ['Opening AgroBusiness', 'Almost ready'];
                var index = Math.min(messages.length - 1, Math.floor((pct / 100) * messages.length));
                text.textContent = messages[index] + ' · ' + pct + '%';
            }
            if (elapsed >= duration) { release(); return; }
            requestAnimationFrame(update);
        }

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
