/* AgroBusiness Malawi — contact-first Sellers / Buyers directory. */
(function () {
    'use strict';

    const PAGE_TO_TYPE = { sellers: 'seller', buyers: 'buyer' };
    const TYPE_TO_PAGE = { seller: 'sellers.php', buyer: 'buyers.php' };
    const pageName = String(window.AGRO_PAGE || '').replace(/\.php$/, '');
    const directoryType = PAGE_TO_TYPE[pageName] || null;
    const cache = { seller: null, buyer: null };
    let detailModal = null;
    let installed = false;

    if (!directoryType) return;

    const esc = value => window.escapeHtml
        ? window.escapeHtml(value)
        : String(value == null ? '' : value).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

    function directoryUrl(type, district) {
        const q = new URLSearchParams();
        if (district) q.set('district_id', district);
        return TYPE_TO_PAGE[type] + (q.toString() ? '?' + q.toString() : '');
    }

    function detailUrl(type, id, district) {
        const q = new URLSearchParams();
        q.set(type + '_id', String(id));
        if (district) q.set('district_id', String(district));
        return TYPE_TO_PAGE[type] + '?' + q.toString();
    }

    async function loadAll(type) {
        if (cache[type]) return cache[type];
        const response = await fetch('directory-api.php?type=' + encodeURIComponent(type === 'seller' ? 'sellers' : 'buyers') + '&district_id=0', { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Unable to load directory');
        cache[type] = Array.isArray(data.data) ? data.data : [];
        return cache[type];
    }

    function pushDirectory(type, district) {
        history.pushState({ screen:'content', view:type + '_directory', type, district:district || '' }, '', directoryUrl(type, district));
    }

    function pushDetail(type, row, district) {
        history.pushState({ screen:'content', view:type + '_detail', type, id:Number(row.id), district:district || '' }, '', detailUrl(type, row.id, district));
    }

    function ensureDetailModal() {
        if (detailModal) return detailModal;
        detailModal = document.createElement('div');
        detailModal.id = 'directory-detail-modal';
        detailModal.className = 'modal';
        detailModal.setAttribute('role', 'dialog');
        detailModal.setAttribute('aria-modal', 'true');
        detailModal.innerHTML = '<div class="modal-backdrop" data-directory-close></div><div class="modal-content directory-detail-modal-content"><div class="modal-header"><h2 id="directory-detail-title">Contact</h2><button type="button" class="modal-close" aria-label="Close" data-directory-close><span class="material-symbols-rounded">close</span></button></div><div class="modal-body" id="directory-detail-body"></div></div>';
        document.body.appendChild(detailModal);
        detailModal.addEventListener('click', function (event) {
            if (!event.target.closest('[data-directory-close]')) return;
            if (window.app && typeof window.app.closeModal === 'function') window.app.closeModal(detailModal);
            else detailModal.classList.remove('active');
            if (history.state && /_detail$/.test(history.state.view || '')) history.back();
        });
        return detailModal;
    }

    function openDetail(row, type, push, district) {
        const modal = ensureDetailModal();
        if (push) pushDetail(type, row, district);
        const title = document.getElementById('directory-detail-title');
        const body = document.getElementById('directory-detail-body');
        const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean);
        title.textContent = row.name || (type === 'seller' ? 'Seller' : 'Buyer');
        body.innerHTML = '<div class="directory-contact-hero"><div class="directory-avatar">' + (type === 'seller' ? '🌾' : '🏢') + '</div><div><span class="directory-role">' + (type === 'seller' ? 'Seller' : 'Buyer') + '</span><h3>' + esc(row.name) + '</h3><p>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</p></div></div>'
            + (crops.length ? '<div class="directory-detail-section"><span class="directory-label">Crops</span><div class="directory-crop-list">' + crops.map(c => '<span>' + esc(c) + '</span>').join('') + '</div></div>' : '')
            + '<div class="directory-detail-actions">'
            + (row.phone_number ? '<a class="directory-action primary" href="tel:' + esc(row.phone_number) + '"><span class="material-symbols-rounded">call</span> Call ' + esc(row.phone_number) + '</a>' : '')
            + (row.email ? '<a class="directory-action" href="mailto:' + esc(row.email) + '"><span class="material-symbols-rounded">mail</span> Email</a>' : '')
            + '<button type="button" class="directory-action" id="directory-share-contact"><span class="material-symbols-rounded">share</span> Share</button></div>';
        const share = document.getElementById('directory-share-contact');
        if (share) share.onclick = function () {
            const url = new URL(detailUrl(type, row.id, district), location.href).href;
            if (navigator.share) navigator.share({ title: row.name || type, url: url }).catch(function(){});
            else if (navigator.clipboard) navigator.clipboard.writeText(url);
            else window.prompt('Copy this link:', url);
        };
        if (window.app && typeof window.app.openModal === 'function') window.app.openModal(modal);
        else modal.classList.add('active');
    }

    async function render(type, rows, district, push) {
        const area = document.getElementById('content-area');
        if (!area) return;
        if (push) pushDirectory(type, district);

        const map = new Map();
        rows.forEach(function (row) { if (row.district_id && row.district_name) map.set(String(row.district_id), row.district_name); });
        const options = Array.from(map.entries()).sort(function(a,b){ return a[1].localeCompare(b[1]); }).map(function(entry){
            return '<option value="' + esc(entry[0]) + '"' + (String(entry[0]) === String(district) ? ' selected' : '') + '>' + esc(entry[1]) + '</option>';
        }).join('');
        const label = type === 'seller' ? 'Sellers' : 'Buyers';

        area.innerHTML = '<section class="directory-page"><div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span><h2>Find ' + label + '</h2><p>Search contacts first. Use district only when you want to narrow the results.</p></div><div class="directory-total"><strong>' + rows.length + '</strong><span>' + label + '</span></div></div><div class="directory-controls"><label class="directory-search"><span class="material-symbols-rounded">search</span><input id="directory-search" type="search" placeholder="Search ' + label.toLowerCase() + ', crops, phone…" autocomplete="off"></label><label class="directory-district"><span>District</span><select id="directory-district"><option value="">All districts</option>' + options + '</select></label></div><div class="directory-count" id="directory-count"></div><div class="directory-grid" id="directory-grid"></div></section>';

        const search = area.querySelector('#directory-search');
        const select = area.querySelector('#directory-district');
        const count = area.querySelector('#directory-count');
        const grid = area.querySelector('#directory-grid');

        function apply() {
            const term = search.value.trim().toLowerCase();
            const selected = select.value;
            const filtered = rows.filter(function(row){
                const hay = [row.name, row.district_name, row.phone_number, row.email, row.address, row.crops_display].join(' ').toLowerCase();
                return (!term || hay.indexOf(term) !== -1) && (!selected || String(row.district_id) === String(selected));
            });
            count.textContent = filtered.length + ' ' + type + (filtered.length === 1 ? '' : 's') + ' showing';
            grid.innerHTML = filtered.length ? filtered.map(function(row){
                const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean).slice(0,4);
                return '<button type="button" class="directory-card" data-directory-id="' + Number(row.id) + '"><span class="directory-card-icon">' + (type === 'seller' ? '🌾' : '🏢') + '</span><span class="directory-card-main"><strong>' + esc(row.name) + '</strong><small>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</small><span class="directory-crops">' + crops.map(c => '<em>' + esc(c) + '</em>').join('') + '</span></span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>';
            }).join('') : '<div class="directory-empty"><span>🌱</span><strong>No contacts found</strong><p>Try another search or district.</p></div>';
            grid.querySelectorAll('[data-directory-id]').forEach(function(card){
                card.onclick = function(){ const row = rows.find(r => Number(r.id) === Number(card.dataset.directoryId)); if (row) openDetail(row, type, true, select.value); };
            });
        }
        search.oninput = apply;
        select.onchange = function(){ pushDirectory(type, select.value); apply(); };
        apply();
    }

    async function openDirectory(type, district, push) {
        try {
            if (window.app && typeof window.app.showScreen === 'function') window.app.showScreen('content');
            const rows = await loadAll(type);
            await render(type, rows, district || '', push);
        } catch (error) {
            console.error('Directory load failed:', error);
            const area = document.getElementById('content-area');
            if (area) area.innerHTML = '<div class="directory-empty"><strong>Unable to load contacts</strong><p>Please refresh and try again.</p></div>';
        }
    }

    function install() {
        if (installed || !window.app || typeof window.app.openService !== 'function') return !!installed;
        installed = true;
        const originalOpenService = window.app.openService.bind(window.app);
        window.app.openService = function(service) {
            if (service === 'sellers' || service === 'buyers') {
                openDirectory(service === 'sellers' ? 'seller' : 'buyer', '', true);
                return;
            }
            return originalOpenService(service);
        };
        if (typeof window.app._replayState === 'function') {
            const originalReplay = window.app._replayState.bind(window.app);
            window.app._replayState = function(state) {
                if (state && (state.view === 'seller_directory' || state.view === 'buyer_directory')) { openDirectory(state.type, state.district || '', false); return; }
                if (state && (state.view === 'seller_detail' || state.view === 'buyer_detail')) {
                    loadAll(state.type).then(function(rows){ const row = rows.find(r => Number(r.id) === Number(state.id)); if (row) openDetail(row, state.type, false, state.district || ''); else openDirectory(state.type, state.district || '', false); });
                    return;
                }
                originalReplay(state);
            };
        }
        return true;
    }

    function boot() {
        if (install()) {
            const qs = new URL(location.href).searchParams;
            const id = Number(qs.get(directoryType + '_id') || 0);
            const district = qs.get('district_id') || '';
            if (id) setTimeout(function(){ loadAll(directoryType).then(function(rows){ const row = rows.find(r => Number(r.id) === id); if (row) openDetail(row, directoryType, false, district); else openDirectory(directoryType, district, false); }); }, 0);
            return;
        }
        setTimeout(boot, 25);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once:true });
    else boot();

    window.addEventListener('popstate', function(){
        if (!installed) return;
        const path = location.pathname.split('/').pop() || '';
        if (path !== TYPE_TO_PAGE[directoryType]) return;
        const qs = new URL(location.href).searchParams;
        const id = Number(qs.get(directoryType + '_id') || 0);
        const district = qs.get('district_id') || '';
        if (id) loadAll(directoryType).then(function(rows){ const row = rows.find(r => Number(r.id) === id); if (row) openDetail(row, directoryType, false, district); });
        else openDirectory(directoryType, district, false);
    });
})();
