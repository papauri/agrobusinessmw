/* AgroBusiness Malawi — Market Insights page
 * Information first. District is a secondary filter, never the entry step.
 * Uses the existing market_insights API/data model and does not alter Buyers/Sellers.
 */
(function () {
    'use strict';

    const esc = (value) => String(value ?? '').replace(/[&<>\"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;'
    }[c]));

    const copy = {
        en: {
            home: 'Home', title: 'Market Insights', eyebrow: 'AgroBusiness Malawi',
            intro: 'Market information for every district, at a glance. Search it, or narrow to one district when you need local detail.',
            updates: 'market updates', districts: 'districts covered',
            searchLabel: 'Search information', searchPlaceholder: 'Search markets, crops or information…',
            districtLabel: 'District', optional: 'optional', allMalawi: 'All Malawi',
            happening: 'What is happening?', update: 'update', updatesPlural: 'updates',
            marketInfo: 'Market information', noInfo: 'No market information yet',
            noInfoText: 'There are no approved insights for this selection.', failed: 'Failed to load market insights',
            selectedDistrict: 'Selected district'
        },
        ci: {
            home: 'Kunyumba', title: 'Zidziwitso za Msika', eyebrow: 'AgroBusiness Malawi',
            intro: 'Dziwani zambiri zofunika za msika mwachidule. Yambani ndi zatsopano, kenako sankhani chigawo ngati mukufuna zambiri za komwe muli.',
            updates: 'zidziwitso za msika', districts: 'zigawo zomwe zilipo',
            searchLabel: 'Sakani zidziwitso', searchPlaceholder: 'Sakani misika, mbewu kapena zidziwitso…',
            districtLabel: 'Chigawo', optional: 'sikofunikira', allMalawi: 'Malawi onse',
            happening: 'Zikuchitika chiyani?', update: 'chidziwitso', updatesPlural: 'zidziwitso',
            marketInfo: 'Zidziwitso za msika', noInfo: 'Palibe zidziwitso za msika pano',
            noInfoText: 'Palibe zidziwitso zovomerezeka pa chisankhochi.', failed: 'Zalephera kutsegula zidziwitso za msika',
            selectedDistrict: 'Chigawo chosankhidwa'
        }
    };

    function lang(app) { return app.currentLang === 'ci' ? copy.ci : copy.en; }

    function getDistrictId() {
        return new URLSearchParams(window.location.search).get('district_id') || 'all';
    }

    function setUrl(districtId, replace = false) {
        const url = new URL(window.location.href);
        if (!districtId || districtId === 'all') url.searchParams.delete('district_id');
        else url.searchParams.set('district_id', districtId);
        const state = { screen: 'content', view: 'market_insights_page', districtId: districtId || 'all' };
        const path = url.pathname + (url.search ? url.search : '');
        if (replace) history.replaceState(state, '', path);
        else history.pushState(state, '', path);
    }

    // One request for the whole country. api.php's market_insights action treats
    // district_id as optional, so the page fetches everything once and refines in
    // the browser. This used to be 28 parallel requests — one per district — on
    // every load and every filter change.
    async function fetchInsights(app) {
        const response = await app.apiCall('api.php?action=market_insights');
        if (!response.success) throw new Error(response.error || 'Failed to load market insights');
        return (response.data || []).map(item => ({
            id: item.id,
            district_id: Number(item.district_id),
            district_name: item.district_name || '',
            insight_en: item.insight_en || '',
            insight_ci: item.insight_ci || ''
        }));
    }

    function updateCount(count, t) { return `${count} ${count === 1 ? t.update : t.updatesPlural}`; }

    function render(app, rows, selectedDistrict, replace = false) {
        const area = document.getElementById('content-area');
        if (!area) return;
        const t = lang(app);
        const districts = app._marketInsightDistricts || [];
        const selectedName = selectedDistrict === 'all'
            ? t.allMalawi
            : (districts.find(d => String(d.id) === String(selectedDistrict))?.name || t.selectedDistrict);
        const filtered = selectedDistrict === 'all' ? rows : rows.filter(r => String(r.district_id) === String(selectedDistrict));
        const districtCount = new Set(filtered.map(r => r.district_id)).size;

        setUrl(selectedDistrict, replace);
        area.dataset.view = 'market_insights_page';
        area.innerHTML = `
            <section class="mi-page" aria-labelledby="mi-title">
                <nav class="mi-breadcrumb" aria-label="Breadcrumb">
                    <a href="index.php">${esc(t.home)}</a>
                    <span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>
                    <strong>${esc(t.title)}</strong>
                    ${selectedDistrict !== 'all' ? `<span class="material-symbols-rounded" aria-hidden="true">chevron_right</span><strong>${esc(selectedName)}</strong>` : ''}
                </nav>
                <header class="mi-hero">
                    <div class="mi-hero-copy">
                        <span class="mi-eyebrow">${esc(t.eyebrow)}</span>
                        <h2 id="mi-title">${esc(t.title)}</h2>
                        <p>${esc(t.intro)}</p>
                    </div>
                    <div class="mi-hero-sketch" aria-hidden="true">🌾</div>
                </header>
                <section class="mi-overview" aria-label="${esc(t.title)} summary">
                    <article><span class="mi-stat-icon" aria-hidden="true">📊</span><div><strong>${filtered.length}</strong><span>${esc(t.updates)}</span></div></article>
                    <article><span class="mi-stat-icon" aria-hidden="true">📍</span><div><strong>${districtCount}</strong><span>${esc(t.districts)}</span></div></article>
                </section>
                <section class="mi-toolbar" aria-label="${esc(t.title)} filters">
                    <div>
                        <label for="mi-search">${esc(t.searchLabel)}</label>
                        <div class="mi-search"><span class="material-symbols-rounded" aria-hidden="true">search</span><input id="mi-search" type="search" placeholder="${esc(t.searchPlaceholder)}" autocomplete="off" enterkeyhint="search" aria-controls="mi-grid"></div>
                    </div>
                    <div>
                        <label for="mi-district">${esc(t.districtLabel)} <span>${esc(t.optional)}</span></label>
                        <select id="mi-district" aria-controls="mi-grid">
                            <option value="all" ${selectedDistrict === 'all' ? 'selected' : ''}>${esc(t.allMalawi)}</option>
                            ${districts.map(d => `<option value="${esc(d.id)}" ${String(d.id) === String(selectedDistrict) ? 'selected' : ''}>${esc(d.name)}</option>`).join('')}
                        </select>
                    </div>
                </section>
                <div class="mi-section-head">
                    <div><span class="mi-section-kicker">${esc(selectedName)}</span><h3>${esc(t.happening)}</h3></div>
                    <span id="mi-count" aria-live="polite">${updateCount(filtered.length, t)}</span>
                </div>
                <div class="mi-grid" id="mi-grid">
                    ${filtered.map(item => {
                        const insight = item[app.currentLang === 'ci' ? 'insight_ci' : 'insight_en'] || item.insight_en || item.insight_ci || '';
                        const searchable = `${item.district_name || ''} ${item.insight_en || ''} ${item.insight_ci || ''}`.toLowerCase();
                        // The heading is the district. market_insights holds only
                        // district_id, insight_en and insight_ci — there is no
                        // title, topic or date column, so rendering one would put
                        // the same placeholder on every card.
                        return `<article class="mi-card" data-district="${esc(item.district_id)}" data-search="${esc(searchable)}">
                            <div class="mi-card-top"><span class="mi-pin" aria-hidden="true">📍</span><span class="mi-district">${esc(item.district_name || 'Malawi')}</span></div>
                            <h4>${esc(item.district_name || 'Malawi')}</h4>
                            <p>${esc(insight || (app.currentLang === 'ci' ? 'Zidziwitso za msika zilipo pa derali.' : 'Market information is available for this area.'))}</p>
                            <div class="mi-card-foot"><span>${esc(t.marketInfo)}</span><span class="material-symbols-rounded" aria-hidden="true">trending_up</span></div>
                        </article>`;
                    }).join('') || `<div class="mi-empty"><span aria-hidden="true">🌱</span><strong>${esc(t.noInfo)}</strong><p>${esc(t.noInfoText)}</p></div>`}
                </div>
            </section>
        `;

        const search = area.querySelector('#mi-search');
        const select = area.querySelector('#mi-district');
        const grid = area.querySelector('#mi-grid');
        const count = area.querySelector('#mi-count');
        if (!search || !select || !grid || !count) return;
        const apply = () => {
            const term = search.value.trim().toLowerCase();
            let visible = 0;
            grid.querySelectorAll('.mi-card').forEach(card => {
                const show = !term || (card.dataset.search || '').includes(term);
                card.hidden = !show;
                if (show) visible++;
            });
            count.textContent = updateCount(visible, t);
        };
        search.addEventListener('input', apply);
        select.addEventListener('change', () => render(app, rows, select.value, false));
    }

    async function open(app, district = null, push = true) {
        app.showScreen('content');
        const t = lang(app);
        const title = document.getElementById('content-title');
        if (title) title.textContent = app.texts[app.currentLang].market_insights || t.title;
        app.showLoading();
        try {
            if (!app._marketInsightDistricts) app._marketInsightDistricts = await app.loadDistricts();
            const rows = await fetchInsights(app);
            render(app, rows, district || 'all', !push);
        } catch (error) {
            console.error('Market insights page failed:', error);
            app.showError(t.failed);
        }
    }

    function install() {
        // This controller owns market-insights.php and nothing else. It used to
        // wrap app.openService so the dashboard tile rendered here too, but
        // openService now navigates to this page directly, so there is one route
        // in and no wrapper whose behaviour depends on script load order.
        if (window.AGRO_PAGE !== 'market-insights') return;
        if (!window.app || window.app._marketInsightsPageInstalled) return;

        const app = window.app;
        app._marketInsightsPageInstalled = true;

        // Back/forward within the page (district refinements) replay through here.
        const originalReplay = typeof app._replayState === 'function' ? app._replayState.bind(app) : null;
        app._replayState = function (state) {
            if (state && state.view === 'market_insights_page') { open(app, state.districtId || 'all', false); return; }
            if (originalReplay) return originalReplay(state);
        };

        open(app, getDistrictId(), false);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
})();
