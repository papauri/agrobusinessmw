(function () {
    'use strict';

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
            close: 'I understand',
            open: 'How price reporting works'
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
            close: 'Ndazindikira',
            open: 'Mmene malipoti a mitengo amagwirira ntchito'
        }
    };

    function lang() {
        return document.documentElement.getAttribute('data-user-lang') === 'ci' ? 'ci' : 'en';
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
        return '<section class="price-report-story" data-price-story>' +
            '<div class="price-story-art">' + sketch() + '</div>' +
            '<div class="price-story-copy">' +
                '<p class="price-story-eyebrow">' + c.eyebrow + '</p>' +
                '<h2>' + c.title + '</h2>' +
                '<p class="price-story-intro">' + c.intro + '</p>' +
                '<div class="price-story-path">' + c.steps.map(function (step) {
                    return '<article class="price-story-step"><span class="price-story-number">' + step[0] + '</span><div><h3>' + step[1] + '</h3><p>' + step[2] + '</p></div></article>';
                }).join('') + '</div>' +
                '<div class="price-story-approval"><span class="material-symbols-rounded" aria-hidden="true">verified</span><p>' + c.approval + '</p></div>' +
                '<p class="price-story-promise">' + c.promise + '</p>' +
                '<button type="button" class="price-story-dismiss">' + c.close + '</button>' +
            '</div>' +
        '</section>';
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

        var story = document.createElement('div');
        story.innerHTML = storyMarkup();
        var node = story.firstElementChild;
        if (reportForm) reportForm.parentNode.insertBefore(node, reportForm);
        else area.insertBefore(node, area.firstChild);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.price-story-dismiss');
        if (!button) return;
        var story = button.closest('[data-price-story]');
        if (story) {
            story.classList.add('is-collapsed');
            setTimeout(function () { story.remove(); }, 280);
        }
    });

    function boot() {
        inject();
        var area = document.getElementById('content-area');
        if (!area) return;
        var observer = new MutationObserver(function () { inject(); });
        observer.observe(area, { childList: true, subtree: true });
        setTimeout(inject, 250);
        setTimeout(inject, 900);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();