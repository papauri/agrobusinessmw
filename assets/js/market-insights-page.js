/* AgroBusiness Malawi — Market Insights page
 * Keeps the existing API/data model but presents information first.
 * District is a secondary filter, never the entry step.
 */
(function () {
    'use strict';

    const esc = (value) => String(value ?? '').replace(/[&<>\"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;'
    }[c]));

    function getDistrictId() {
        return new URLSearchParams(window.location.search).get('district_id') || 'all';
    }

    function setUrl(districtId, replace = false) {
        const url = new URL(window.location.href);
        if (!districtId || districtId === 'all') url.searchParams.delete('district_id');
        else url.searchParams.set('district_id', districtId);
        const state = { screen: 'content', view: 'market_insights_page', districtId: districtId || 'all' };
        if (replace) history.replaceState(state, '', url.pathname + (url.search ? url.search : ''));
        else history.pushState(state, '', url.pathname + (url.search ? url.search : ''));
    }

    async function fetchInsights(app) {
        const districts = await app.loadDistricts();
        const valid = districts || [];
        const results = await Promise.all(valid.map(async d => {
            try {
                const response = await app.apiCall(`api.php?action=market_insights&district_id=${encodeURIComponent(d.id)}`);
                if (!response.success) return [];
                return (response.data || []).map(item => ({ ...item, district_id: Number(d.id), district_name: item.district_name || d.name }));
            } catch (_) {
                return [];
            }
        }));
        const seen = new Set();
        return results.flat().filter(item => {
            const key = `${item.district_id}|${item.id || item.insight_id || item.insight_en || ''}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function relativeDate(value) {
        if (!value) return '';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return '';
        const days = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (days <= 0) return 'Today';
        if (days === 1) return 'Yesterday';
        if (days < 7) return `${days} days ago`;
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function render(app, rows, selectedDistrict, replace = false) {
        const area = document.getElementById('content-area');
        if (!area) return;
        const districts = app._marketInsightDistricts || [];
        const selectedName = selectedDistrict === 'all'
            ? 'All Malawi'
            : (districts.find(d => String(d.id) === String(selectedDistrict))?.name || 'Selected district');
        const filtered = selectedDistrict === 'all'
            ? rows
            : rows.filter(r => String(r.district_id) === String(selectedDistrict));
        const latest = [...filtered].sort((a, b) => new Date(b.updated_at || b.created_at || b.date || 0) - new Date(a.updated_at || a.created_at || a.date || 0));
        const districtCount = new Set(filtered.map(r => r.district_id)).size;
        const cropLike = filtered.length;

        setUrl(selectedDistrict, replace);
        area.dataset.view = 'market_insights_page';
        const t = app.currentLang === 'ci';
        area.innerHTML = `
            <section class="mi-page">
                <nav class="mi-breadcrumb" aria-label="Breadcrumb">
                    <a href="index.php">Home</a><span aria-hidden="true">chevron_right</span><strong>Market Insights</strong>
                    ${selectedDistrict !== 'all' ? `<span aria-hidden="true">chevron_right</span><strong>${esc(selectedName)}</strong>` : ''}
                </nav>

                <header class="mi-hero">
                    <div class="mi-hero-copy">
                        <span class="mi-eyebrow">AgroBusiness Malawi</span>
                        <h2>Market Insights</h2>
                        <p>Useful market information at a glance. Start with the latest information, then narrow it to a district when you need a local view.</p>
                    </div>
                    <div class="mi-hero-sketch" aria-hidden="true">🌾</div>
                </header>

                <section class="mi-overview" aria-label="Market insight summary">
                    <article><span class="mi-stat-icon">📊</span><div><strong>${filtered.length}</strong><span>market updates</span></div></article>
                    <article><span class="mi-stat-icon">📍</span><div><strong>${districtCount}</strong><span>district${districtCount === 1 ? '' : 's'} covered</span></div></article>
                    <article><span class="mi-stat-icon">🕒</span><div><strong>${latest.length ? relativeDate(latest[0].updated_at || latest[0].created_at || latest[0].date) || 'Latest' : '—'}</strong><span>most recent update</span></div></article>
                </section>

                <section class="mi-toolbar" aria-label="Market insight filters">
                    <div>
                        <label for="mi-search">Search information</label>
                        <div class="mi-search"><span class="material-symbols-rounded">search</span><input id="mi-search" type="search" placeholder="Search markets, crops or information…" autocomplete="off"></div>
                    </div>
                    <div>
                        <label for="mi-district">District <span>optional</span></label>
                        <select id="mi-district">
                            <option value="all" ${selectedDistrict === 'all' ? 'selected' : ''}>All Malawi</option>
                            ${districts.map(d => `<option value="${esc(d.id)}" ${String(d.id) === String(selectedDistrict) ? 'selected' : ''}>${esc(d.name)}</option>`).join('')}
                        </select>
                    </div>
                </section>

                <div class="mi-section-head"><div><span class="mi-section-kicker">${esc(selectedName)}</span><h3>What is happening?</h3></div><span id="mi-count">${filtered.length} update${filtered.length === 1 ? '' : 's'}</span></div>

                <div class="mi-grid" id="mi-grid">
                    ${latest.map((item, i) => `
                        <article class="mi-card" data-district="${esc(item.district_id)}" data-search="${esc(`${item.district_name || ''} ${item.insight_en || ''} ${item.insight_ci || ''}`.toLowerCase())}">
                            <div class="mi-card-top"><span class="mi-pin">📍</span><span class="mi-district">${esc(item.district_name || 'Malawi')}</span><span class="mi-latest">${relativeDate(item.updated_at || item.created_at || item.date) || 'Latest'}</span></div>
                            <h4>${esc(item.title || item.topic || 'Market update')}</h4>
                            <p>${esc(item[t ? 'insight_ci' : 'insight_en'] || item.insight_en || item.insight_ci || 'Market information is available for this area.')}</p>
                            <div class="mi-card-foot"><span>Market information</span><span class="material-symbols-rounded">trending_up</span></div>
                        </article>
                    `).join('') || `<div class="mi-empty"><span>🌱</span><strong>No market information yet</strong><p>There are no approved insights for this selection.</p></div>`}
                </div>
            </section>
        `;

        const search = area.querySelector('#mi-search');
        const select = area.querySelector('#mi-district');
        const grid = area.querySelector('#mi-grid');
        const count = area.querySelector('#mi-count');

        const apply = () => {
            const term = search.value.trim().toLowerCase();
            let visible = 0;
            grid.querySelectorAll('.mi-card').forEach(card => {
                const show = !term || card.dataset.search.includes(term);
                card.hidden = !show;
                if (show) visible++;
            });
            count.textContent = `${visible} update${visible === 1 ? '' : 's'}`;
        };

        search.addEventListener('input', apply);
        select.addEventListener('change', () => {
            render(app, rows, select.value, false);
        });
    }

    async function open(app, district = null, push = true) {
        app.showScreen('content');
        const title = document.getElementById('content-title');
        if (title) title.textContent = app.texts[app.currentLang].market_insights || 'Market Insights';
        app.showLoading();
        try {
            const rows = await fetchInsights(app);
            const districts = app._marketInsightDistricts || [];
            if (!districts.length) app._marketInsightDistricts = await app.loadDistricts();
            render(app, rows, district || 'all', !push);
            if (push) setUrl(district || 'all', false);
        } catch (error) {
            console.error('Market insights page failed:', error);
            app.showError('Failed to load market insights');
        }
    }

    function install() {
        if (!window.app) return;
        const app = window.app;
        const originalOpenService = app.openService.bind(app);
        app.openService = function (service) {
            if (service === 'market-insights') {
                open(app, 'all', true);
                return;
            }
            return originalOpenService(service);
        };

        const originalReplay = typeof app._replayState === 'function' ? app._replayState.bind(app) : null;
        app._replayState = function (state) {
            if (state && state.view === 'market_insights_page') {
                open(app, state.districtId || 'all', false);
                return;
            }
            if (originalReplay) return originalReplay(state);
        };

        if (window.AGRO_PAGE === 'market-insights') {
            open(app, getDistrictId(), false);
        }
    }

    // app.js is deferred and therefore executes before this deferred script.
    // That gives us a deterministic hook without changing app.js or its other services.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
})();
