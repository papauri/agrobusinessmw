/**
 * "How price reporting works" — the explainer above the price report form.
 *
 * This is OPTIONAL DETAIL, so it ships COLLAPSED. Expanded it runs ~1800px and
 * pushed the first form field ~2900px down the pane: someone who tapped
 * "Report a Price" to report a price had to scroll past the whole story to
 * reach the crop selector. The short practical summary the form needs (.pr-note)
 * is always visible; this is the long version for anyone who wants it.
 *
 * The dismiss button used to call remove() on the section. The MutationObserver
 * below watches #content-area, saw that removal, found no [data-price-story],
 * and re-injected the section immediately — so the panel could not be closed at
 * all. Nothing is removed now: the toggle flips one attribute, and the choice is
 * remembered in localStorage so it survives navigation and reloads.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'agroPriceStoryOpen';

    var COPY = {
        en: {
            eyebrow: 'Before your price enters the market story',
            title: 'Your price has a journey.',
            intro: 'Think of your report as a note from your field or market. You share what you saw, and AgroBusiness helps turn many trusted notes into a clearer picture for farmers.',
            steps: [
                ['01', 'You tell us what you saw', 'Choose the crop, where you saw the price, and the price per kg. Be as accurate as you can.'],
                ['02', 'We check the note', 'Your report passes automatic checks. If it looks unusual, it can be held for a closer look.'],
                ['03', 'A trusted price takes shape', 'Approved reports are combined using the median so one unusual number does not distort the market picture.'],
                ['04', 'Farmers see the signal', 'Only approved reports contribute to the displayed crop price. Older reports are left out so the picture stays current.']
            ],
            approval: 'Important: price reports are subject to approval. A report may be approved automatically when it passes the checks, or held for admin review. Pending, flagged and rejected reports do not count toward the displayed price.',
            promise: 'Report what you genuinely observed. One careful note can help another farmer make a better decision.',
            close: 'Hide this',
            // Distinct from the .pr-note heading directly above this toggle, which is
            // also "How price reporting works" — two identical headings stacked read
            // as a repeat rather than as a thing you can open.
            open: 'What happens to your report'
        },
        ci: {
            eyebrow: 'Musanatumize mtengo wanu',
            title: 'Mtengo wanu uli ndi ulendo.',
            intro: 'Ganizirani lipoti lanu ngati uthenga wochokera kumunda kapena kumsika. Mumatiuza zimene munaona, ndipo AgroBusiness imathandiza kusonkhanitsa mauthengawo kuti alimi apeze chithunzi chomveka cha msika.',
            steps: [
                ['01', 'Mumatiuza zimene munaona', 'Sankhani mbeu, kumene munaona mtengowo, ndi mtengo pa kg. Lembani molondola momwe mungathere.'],
                ['02', 'Timayang’ana lipotilo', 'Lipotilo limadutsa pa ma check a makina. Ngati likuwoneka lachilendo, lingayimitsidwe kuti liyang’anidwe.'],
                ['03', 'Mtengo wodalirika umapangidwa', 'Malipoti ovomerezeka amaphatikizidwa pogwiritsa ntchito median kuti nambala imodzi yosiyana isasokoneze chithunzi cha msika.'],
                ['04', 'Alimi amaona chizindikiro cha msika', 'Malipoti ovomerezeka okha ndi amene amalowa pa mtengo wowonetsedwa. Malipoti akale amachotsedwa kuti chithunzicho chikhale chatsopano.']
            ],
            approval: 'Chofunika: malipoti a mitengo amayenera kuvomerezedwa. Lipoti lingavomerezedwe ndi makina likadutsa ma check, kapena liyimitsidwe kuti admin aliyang’ane. Pending, flagged ndi rejected siziphatikizidwa pa mtengo wowonetsedwa.',
            promise: 'Nenani mtengo umene munaona kwenikweni. Lipoti limodzi lolondola lingathandize mlimi wina kupanga chisankho chabwino.',
            close: 'Bisani izi',
            open: 'Zimene zimachitika ndi lipoti lanu'
        }
    };

    function lang() {
        return document.documentElement.getAttribute('data-user-lang') === 'ci' ? 'ci' : 'en';
    }

    /** Collapsed unless the reader has explicitly opened it before. */
    function isOpen() {
        try { return window.localStorage.getItem(STORAGE_KEY) === '1'; }
        catch (e) { return false; }
    }

    function rememberOpen(open) {
        try { window.localStorage.setItem(STORAGE_KEY, open ? '1' : '0'); }
        catch (e) { /* private mode — the session still works, it just won't persist */ }
    }

    function escapeText(value) {
        var d = document.createElement('div');
        d.textContent = String(value == null ? '' : value);
        return d.innerHTML;
    }

    function sketch() {
        return '<svg viewBox="0 0 160 100" aria-hidden="true" class="price-story-sketch">' +
            '<path d="M10 78 C38 63 59 67 83 54 S123 55 151 40"/>' +
            '<path d="M12 86 C40 72 60 76 87 63 S125 64 151 50"/>' +
            '<path d="M78 67 C76 52 77 37 81 18"/>' +
            '<path d="M80 39 C67 30 57 23 49 15 C63 18 73 24 81 32"/>' +
            '<path d="M79 49 C92 40 103 37 116 39 C105 48 94 52 79 55"/>' +
            '<path d="M81 18 C75 12 76 5 81 1 C87 7 88 13 81 18 Z"/>' +
            '<path d="M125 21 q8 -7 16 0 q8 -7 16 0"/>' +
            '<circle cx="27" cy="25" r="11"/>' +
            '</svg>';
    }

    function storyMarkup() {
        var c = COPY[lang()];
        var open = isOpen();
        return '<section class="price-report-story" data-price-story data-open="' + (open ? 'true' : 'false') + '">' +
            '<button type="button" class="price-story-toggle" aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="price-story-body">' +
                '<span class="material-symbols-rounded price-story-icon" aria-hidden="true">info</span>' +
                '<span class="price-story-toggle-label">' + escapeText(c.open) + '</span>' +
                '<span class="material-symbols-rounded price-story-chevron" aria-hidden="true">expand_more</span>' +
            '</button>' +
            '<div class="price-story-body" id="price-story-body"' + (open ? '' : ' hidden') + '>' +
                '<div class="price-story-art">' + sketch() + '</div>' +
                '<div class="price-story-copy">' +
                    '<p class="price-story-eyebrow">' + escapeText(c.eyebrow) + '</p>' +
                    '<h2>' + escapeText(c.title) + '</h2>' +
                    '<p class="price-story-intro">' + escapeText(c.intro) + '</p>' +
                    '<div class="price-story-path">' + c.steps.map(function (step) {
                        return '<article class="price-story-step"><span class="price-story-number">' + escapeText(step[0]) +
                            '</span><div><h3>' + escapeText(step[1]) + '</h3><p>' + escapeText(step[2]) + '</p></div></article>';
                    }).join('') + '</div>' +
                    '<div class="price-story-approval"><span class="material-symbols-rounded" aria-hidden="true">verified</span><p>' + escapeText(c.approval) + '</p></div>' +
                    '<p class="price-story-promise">' + escapeText(c.promise) + '</p>' +
                    '<button type="button" class="price-story-dismiss">' + escapeText(c.close) + '</button>' +
                '</div>' +
            '</div>' +
        '</section>';
    }

    function setOpen(section, open, remember) {
        var body = section.querySelector('.price-story-body');
        var toggle = section.querySelector('.price-story-toggle');
        section.setAttribute('data-open', open ? 'true' : 'false');
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (body) body.hidden = !open;
        if (remember) rememberOpen(open);
    }

    // The observer is paused around our own DOM writes so injecting cannot
    // retrigger inject() through the mutation it just caused.
    var observer = null;
    function withObserverPaused(fn) {
        if (observer) observer.disconnect();
        try { fn(); }
        finally { if (observer) observeArea(); }
    }

    function inject() {
        if ((location.pathname.split('/').pop() || 'index.php') !== 'prices.php') return;
        var area = document.getElementById('content-area');
        if (!area || area.querySelector('[data-price-story]')) return;

        var forms = area.querySelectorAll('form');
        var reportForm = null;
        forms.forEach(function (form) {
            var text = (form.textContent || '').toLowerCase();
            if (!reportForm && (text.indexOf('price') !== -1 || text.indexOf('report') !== -1 || form.querySelector('input[type="number"]'))) reportForm = form;
        });

        withObserverPaused(function () {
            var story = document.createElement('div');
            story.innerHTML = storyMarkup();
            var node = story.firstElementChild;
            if (reportForm) reportForm.parentNode.insertBefore(node, reportForm);
            else area.insertBefore(node, area.firstChild);
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest && event.target.closest('.price-story-toggle');
        if (toggle) {
            var section = toggle.closest('[data-price-story]');
            if (section) setOpen(section, section.getAttribute('data-open') !== 'true', true);
            return;
        }
        // "Hide this" collapses the panel rather than removing it. Removing it
        // was what the observer kept undoing.
        var dismiss = event.target.closest && event.target.closest('.price-story-dismiss');
        if (!dismiss) return;
        var owner = dismiss.closest('[data-price-story]');
        if (!owner) return;
        setOpen(owner, false, true);
        var head = owner.querySelector('.price-story-toggle');
        if (head && typeof head.focus === 'function') head.focus();   // keep keyboard focus in place
    });

    function observeArea() {
        var area = document.getElementById('content-area');
        if (!area || !observer) return;
        observer.observe(area, { childList: true, subtree: true });
    }

    // Re-render in the reader's language when they switch it, keeping whatever
    // open/closed state they chose.
    function bindLanguage() {
        var apply = function () {
            var existing = document.querySelector('[data-price-story]');
            if (!existing) return;
            withObserverPaused(function () {
                var holder = document.createElement('div');
                holder.innerHTML = storyMarkup();
                existing.replaceWith(holder.firstElementChild);
            });
        };
        if (window.AgroLang && typeof window.AgroLang.onChange === 'function') window.AgroLang.onChange(apply);
        else document.addEventListener('agro:langchange', apply);
    }

    function boot() {
        var area = document.getElementById('content-area');
        if (!area) return;
        observer = new MutationObserver(function () { inject(); });
        inject();
        observeArea();
        bindLanguage();
        setTimeout(inject, 250);
        setTimeout(inject, 900);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
