(function () {
    'use strict';

    // AgroBusiness Malawi — first-visit agricultural field-notebook loader.
    // The loader is deliberately calm, tactile and recognisably agricultural.
    var FIRST_VISIT_MS = 5200;
    var RETURNING_MS = 700;
    var FLAG = 'agrobusiness:first-loader-seen';

    function injectSketchStyles() {
        if (document.getElementById('agro-field-loader-styles')) return;
        var style = document.createElement('style');
        style.id = 'agro-field-loader-styles';
        style.textContent = `
            #loading-screen.agro-field-loader { background:#f3eee3; color:#332f29; overflow:hidden; isolation:isolate; font-family:Georgia,'Times New Roman',serif; }
            #loading-screen.agro-field-loader::before,#loading-screen.agro-field-loader::after { content:""; position:absolute; inset:0; pointer-events:none; }
            #loading-screen.agro-field-loader::before { opacity:.22; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160'%3E%3Cfilter id='grain'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.78' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23grain)' opacity='.15'/%3E%3C/svg%3E"); mix-blend-mode:multiply; }
            #loading-screen.agro-field-loader::after { opacity:.22; background:repeating-linear-gradient(0deg,transparent 0 6px,rgba(57,50,41,.035) 7px,transparent 8px); }
            .agro-field-stage { position:relative; width:min(94vw,680px); height:350px; margin:0 auto -.25rem; }
            .agro-field-stage svg { width:100%; height:100%; overflow:visible; }
            .agro-pencil { fill:none; stroke:#4a443a; stroke-linecap:round; stroke-linejoin:round; }
            .agro-pencil-fine { stroke-width:1; opacity:.48; }
            .agro-pencil-mid { stroke-width:1.45; opacity:.72; }
            .agro-pencil-bold { stroke-width:2.2; opacity:.82; }
            .agro-sketch-draw { stroke-dasharray:900; stroke-dashoffset:900; animation:agro-draw 3.4s cubic-bezier(.45,.05,.55,.95) forwards; }
            .agro-sketch-draw.delay-1 { animation-delay:.25s; }
            .agro-sketch-draw.delay-2 { animation-delay:.65s; }
            .agro-sketch-draw.delay-3 { animation-delay:1.05s; }
            .agro-sketch-sway { transform-origin:250px 270px; animation:agro-sway 4s ease-in-out infinite; }
            .agro-sketch-float { animation:agro-float 3.8s ease-in-out infinite; }
            .agro-sketch-pulse { animation:agro-pulse 3.2s ease-in-out infinite; }
            @keyframes agro-draw { to { stroke-dashoffset:0; } }
            @keyframes agro-sway { 0%,100%{transform:rotate(-.8deg)} 50%{transform:rotate(.8deg)} }
            @keyframes agro-float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-4px)} }
            @keyframes agro-pulse { 0%,100%{opacity:.38} 50%{opacity:.82} }
            .agro-field-label { fill:#665d51; font:italic 12px Georgia,'Times New Roman',serif; letter-spacing:.08em; }
            .agro-field-title { font-family:Georgia,'Times New Roman',serif!important; font-size:clamp(2.2rem,7vw,3.8rem)!important; font-weight:400; letter-spacing:-.025em; color:#302b25!important; }
            .agro-field-subtitle { font-family:Georgia,'Times New Roman',serif!important; font-style:italic; color:#746b5f!important; letter-spacing:.06em; font-size:.88rem!important; }
            .agro-field-rule { width:min(360px,70vw); height:1px; background:#807466; opacity:.38; margin:.7rem auto 1rem; position:relative; }
            .agro-field-rule::after { content:'✦'; position:absolute; left:50%; top:50%; transform:translate(-50%,-54%); background:#f3eee3; padding:0 .45rem; color:#8a7a64; font-size:.65rem; }
            .agro-field-progress-wrap { width:min(380px,76vw); margin:0 auto; }
            .agro-field-progress { width:100%!important; height:7px!important; border:1px solid rgba(74,68,58,.38); background:rgba(255,255,255,.32)!important; border-radius:1px!important; padding:1px; overflow:hidden; }
            .agro-field-progress .progress-bar { height:100%; background:#51483c!important; border-radius:0!important; box-shadow:none!important; transition:width .08s linear; }
            .agro-field-loader .loading-text { color:#62594d; font-family:Georgia,'Times New Roman',serif; font-size:.8rem; letter-spacing:.07em; min-height:1.4em; }
            .agro-field-caption { color:#82786b; font-size:.67rem; letter-spacing:.11em; text-transform:uppercase; margin-top:.35rem; }
            .agro-field-stats { display:flex; justify-content:center; gap:.5rem; flex-wrap:wrap; max-width:620px; margin:1.15rem auto 0; }
            .agro-field-stats .stat-pill { background:rgba(255,255,255,.18)!important; border:1px solid rgba(74,68,58,.25)!important; border-radius:2px!important; color:#5f574c!important; box-shadow:none!important; padding:.55rem .85rem!important; font-family:Georgia,'Times New Roman',serif; font-size:.68rem; letter-spacing:.04em; }
            .agro-field-stats .stat-count { display:inline!important; font-size:.95rem!important; color:#4f473c!important; margin-right:.2rem; }
            .agro-field-privacy { display:inline-block; margin-top:.85rem; color:#655c50!important; font-size:.69rem; text-decoration:underline; text-underline-offset:3px; }
            .agro-field-note { position:absolute; left:3%; top:11%; color:#85796a; font:italic 11px Georgia,'Times New Roman',serif; transform:rotate(-4deg); opacity:.7; }
            .agro-field-stamp { position:absolute; right:3%; top:13%; border:1px solid rgba(104,83,60,.34); color:#776750; padding:.35rem .5rem; font:10px Georgia,'Times New Roman',serif; letter-spacing:.12em; text-transform:uppercase; transform:rotate(5deg); opacity:.7; }
            @media (max-width:600px) { .agro-field-stage { height:300px; } .agro-field-note,.agro-field-stamp { display:none; } .agro-field-stats { max-width:340px; } }
            @media (prefers-reduced-motion:reduce) { .agro-sketch-draw { animation:none!important; stroke-dashoffset:0!important; } .agro-sketch-sway,.agro-sketch-float,.agro-sketch-pulse { animation:none!important; } }
        `;
        document.head.appendChild(style);
    }

    function buildSketch(loader) {
        loader.classList.add('agro-field-loader');
        loader.innerHTML = `
            <div class="loading-content">
                <div class="agro-field-stage" aria-hidden="true">
                    <span class="agro-field-note">Field notebook · Malawi</span>
                    <span class="agro-field-stamp">Season · Market · Weather</span>
                    <svg viewBox="0 0 680 350" role="presentation">
                        <g class="agro-sketch-float"><circle class="agro-pencil agro-pencil-fine agro-sketch-pulse" cx="550" cy="72" r="30"/><path class="agro-pencil agro-pencil-fine" d="M550 30v-15M550 114v15M508 72h-15M592 72h15M520 42l-11-11M580 102l11 11M580 42l11-11M520 102l-11 11"/><path class="agro-pencil agro-pencil-fine" d="M92 76q10-9 20 0q10-9 20 0M382 55q8-7 16 0q8-7 16 0"/></g>
                        <path class="agro-pencil agro-pencil-fine agro-sketch-draw" d="M30 216 C105 176 145 191 204 170 S313 191 376 157 S491 174 555 145 S624 151 662 132"/>
                        <path class="agro-pencil agro-pencil-fine agro-sketch-draw delay-1" d="M20 232 C92 198 145 210 210 189 S316 208 384 176 S486 191 553 163 S625 170 672 151"/>
                        <path class="agro-pencil agro-pencil-fine agro-sketch-draw delay-1" d="M15 257 C92 233 137 245 207 229 S322 238 389 216 S501 224 670 198"/>
                        <path class="agro-pencil agro-pencil-fine agro-sketch-draw delay-2" d="M18 278 C92 253 151 266 216 248 S326 259 402 237 S521 244 672 218"/>
                        <path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-2" d="M30 303 C110 275 163 290 229 269 S343 279 418 258 S535 267 660 242"/>
                        <path class="agro-pencil agro-pencil-fine agro-sketch-draw delay-3" d="M78 326 C150 302 194 314 252 296 S350 303 431 281 S537 290 617 270"/>
                        <g class="agro-sketch-sway"><path class="agro-pencil agro-pencil-bold agro-sketch-draw delay-1" d="M292 292 C291 251 292 207 294 151 C295 126 296 105 300 84"/><path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-2" d="M293 228 C263 204 236 192 208 190 C229 211 257 226 293 240"/><path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-2" d="M294 254 C326 229 354 219 388 220 C363 241 331 254 294 267"/><path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-3" d="M296 190 C268 172 248 155 231 135 C255 145 277 159 297 176"/><path class="agro-pencil agro-pencil-bold agro-sketch-draw delay-3" d="M300 84 C284 67 284 45 299 27 C316 47 319 67 300 84 Z"/><path class="agro-pencil agro-pencil-fine" d="M293 36l10 43M289 48l12 27M301 34l-2 45M291 205l-21 9M303 238l24-11M287 276l-18 12"/></g>
                        <path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-2" d="M118 282 C129 246 151 214 180 185"/><path class="agro-pencil agro-pencil-bold agro-sketch-draw delay-3" d="M177 187 L213 154"/><path class="agro-pencil agro-pencil-mid agro-sketch-draw delay-3" d="M211 153 l20 -7 l-10 18 Z"/>
                        <path class="agro-pencil agro-pencil-fine" d="M84 292l-14 8M96 289l-13 9M467 282l17 9M479 278l18 9M520 252l16 8M532 248l17 8"/>
                    </svg>
                </div>
                <h1 class="loading-title agro-field-title">AgroBusiness</h1>
                <p class="loading-subtitle agro-field-subtitle">Malawi · grown from the ground up</p>
                <div class="agro-field-rule"></div>
                <div class="agro-field-progress-wrap"><div class="loading-progress agro-field-progress" aria-hidden="true"><div class="progress-bar"></div></div></div>
                <p class="loading-text">Preparing the fields · 0%</p>
                <p class="agro-field-caption">Crops · markets · weather · farmers</p>
                <a class="loader-privacy agro-field-privacy" href="privacy.php">Privacy Policy</a>
            </div>
            <div class="loading-stats agro-field-stats"><div class="stat-pill"><span class="stat-count" id="loading-districts">28</span> districts</div><div class="stat-pill"><span class="stat-count" id="loading-crops">9</span> crops</div><div class="stat-pill">live weather</div><div class="stat-pill">market prices</div></div>
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
                var messages = firstVisit ? ['Sketching the fields','Mapping Malawi','Gathering crop data','Reading the weather','Opening the market'] : ['Opening AgroBusiness','Almost ready'];
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
                    if (remaining === 0) originalHide(); else setTimeout(originalHide, remaining);
                };
                window.app.__loaderPatched = true;
                clearInterval(patchTimer);
            } else if (attempts > 120) clearInterval(patchTimer);
        }, 25);
        var observer = new MutationObserver(function () {
            if (released) return;
            loader.style.opacity = '1'; loader.style.transform = 'none'; loader.style.filter = 'none'; loader.style.pointerEvents = 'auto';
        });
        observer.observe(loader, { attributes: true, attributeFilter: ['style', 'class'] });
        update();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
})();