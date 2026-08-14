/* AgroBusiness Malawi — contact directory + URL/history navigation. */
(function () {
    'use strict';
    const INITIAL_URL = window.location.href;
    const PAGE_TO_TYPE = { sellers: 'seller', buyers: 'buyer' };
    const TYPE_TO_PAGE = { seller: 'sellers.php', buyer: 'buyers.php' };
    const cache = { seller: null, buyer: null };
    let modal = null;
    const esc = (v) => window.escapeHtml ? window.escapeHtml(v) : String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function directoryUrl(type, district) { const qs = new URLSearchParams(); if (district) qs.set('district_id', district); return TYPE_TO_PAGE[type] + (qs.toString() ? '?' + qs.toString() : ''); }
    function detailUrl(type, id, district) { const qs = new URLSearchParams(); qs.set(type + '_id', String(id)); if (district) qs.set('district_id', String(district)); return TYPE_TO_PAGE[type] + '?' + qs.toString(); }
    function pushDirectoryState(type, district) { history.pushState({ screen: 'content', view: type + '_directory', type, district: district || '' }, '', directoryUrl(type, district)); }
    function pushDetailState(type, row, district) { history.pushState({ screen: 'content', view: type + '_detail', type, id: Number(row.id), district: district || '' }, '', detailUrl(type, row.id, district)); }
    async function fetchDistrictRows(type, districtId) { const action = type === 'seller' ? 'sellers' : 'buyers'; const response = await fetch(`api.php?action=${action}&district_id=${encodeURIComponent(districtId)}`); const data = await response.json(); if (!data.success) throw new Error(data.error || `Unable to load ${type}s`); return data.data || []; }
    async function loadAll(type) { if (cache[type]) return cache[type]; if (!window.app || typeof window.app.loadDistricts !== 'function') return []; const districts = await window.app.loadDistricts(); const batches = await Promise.all(districts.map(d => fetchDistrictRows(type, d.id).catch(() => []))); cache[type] = batches.flat(); return cache[type]; }

    function breadcrumb(type, districtName, contactName) {
        const label = type === 'seller' ? 'Sellers' : 'Buyers';
        return `<nav class="directory-breadcrumbs" aria-label="Breadcrumb">
            <a href="index.php" class="directory-crumb" data-breadcrumb="home"><span class="material-symbols-rounded" aria-hidden="true">home</span><span>Home</span></a>
            <span class="directory-crumb-separator" aria-hidden="true">chevron_right</span>
            <a href="${TYPE_TO_PAGE[type]}" class="directory-crumb" data-breadcrumb="directory"><span>${label}</span></a>
            ${districtName ? `<span class="directory-crumb-separator" aria-hidden="true">chevron_right</span><span class="directory-crumb directory-crumb-current"><span>${esc(districtName)}</span></span>` : ''}
            ${contactName ? `<span class="directory-crumb-separator" aria-hidden="true">chevron_right</span><span class="directory-crumb directory-crumb-current directory-crumb-contact"><span>${esc(contactName)}</span></span>` : ''}
        </nav>`;
    }

    function ensureModal() {
        if (modal) return modal;
        modal = document.createElement('div'); modal.id = 'directory-detail-modal'; modal.className = 'modal'; modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = `<div class="modal-backdrop" data-directory-close></div><div class="modal-content directory-detail-modal-content"><div class="modal-header"><h2 id="directory-detail-title">Contact</h2><button type="button" class="modal-close" aria-label="Close" data-directory-close><span class="material-symbols-rounded">close</span></button></div><div class="directory-modal-breadcrumb" id="directory-modal-breadcrumb"></div><div class="modal-body" id="directory-detail-body"></div></div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', e => {
            const crumb = e.target.closest('[data-breadcrumb]');
            if (crumb) {
                e.preventDefault();
                if (crumb.dataset.breadcrumb === 'directory' && history.state && /_detail$/.test(history.state.view || '')) history.back();
                else if (crumb.dataset.breadcrumb === 'home') window.location.href = 'index.php';
                return;
            }
            if (!e.target.closest('[data-directory-close]')) return;
            if (window.app && typeof window.app.closeModal === 'function') window.app.closeModal(modal); else modal.classList.remove('active');
            if (history.state && /_detail$/.test(history.state.view || '')) history.back();
        });
        return modal;
    }

    function openDetail(row, type, shouldPush, district) {
        const m = ensureModal(); if (shouldPush) pushDetailState(type, row, district);
        const title = document.getElementById('directory-detail-title'), body = document.getElementById('directory-detail-body'), crumb = document.getElementById('directory-modal-breadcrumb');
        const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean);
        if (title) title.textContent = row.name || (type === 'seller' ? 'Seller' : 'Buyer');
        if (crumb) crumb.innerHTML = breadcrumb(type, row.district_name || '', row.name || 'Contact');
        if (body) body.innerHTML = `<div class="directory-contact-hero"><div class="directory-avatar">${type === 'seller' ? '🌾' : '🏢'}</div><div><span class="directory-role">${type === 'seller' ? 'Seller' : 'Buyer'}</span><h3>${esc(row.name)}</h3><p>${esc(row.district_name || '')}${row.address ? ' · ' + esc(row.address) : ''}</p></div></div>${crops.length ? `<div class="directory-detail-section"><span class="directory-label">Crops</span><div class="directory-crop-list">${crops.map(c => `<span>${esc(c)}</span>`).join('')}</div></div>` : ''}<div class="directory-detail-actions">${row.phone_number ? `<a class="directory-action primary" href="tel:${esc(row.phone_number)}"><span class="material-symbols-rounded">call</span> Call ${esc(row.phone_number)}</a>` : '<p class="directory-muted">No phone number listed.</p>'}${row.email ? `<a class="directory-action" href="mailto:${esc(row.email)}"><span class="material-symbols-rounded">mail</span> Email</a>` : ''}</div>`;
        if (window.app && typeof window.app.openModal === 'function') window.app.openModal(m); else m.classList.add('active');
    }

    function render(type, rows, district, shouldPush) {
        if (shouldPush) pushDirectoryState(type, district);
        const area = document.getElementById('content-area'); if (!area) return;
        const districts = new Map(); rows.forEach(r => { if (r.district_id && r.district_name) districts.set(String(r.district_id), r.district_name); });
        const districtOptions = [...districts.entries()].sort((a, b) => a[1].localeCompare(b[1])).map(([id, name]) => `<option value="${esc(id)}" ${String(id) === String(district) ? 'selected' : ''}>${esc(name)}</option>`).join('');
        const districtName = district ? (districts.get(String(district)) || '') : '';
        const title = type === 'seller' ? 'Find Sellers' : 'Find Buyers'; const subtitle = type === 'seller' ? 'See sellers first, then narrow the list by district.' : 'See buyers first, then narrow the list by district.';
        area.innerHTML = `<section class="directory-page">${breadcrumb(type, districtName, '')}<div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span><h2>${title}</h2><p>${subtitle}</p></div><div class="directory-total"><strong>${rows.length}</strong><span>${type}s</span></div></div><div class="directory-controls"><label class="directory-search"><span class="material-symbols-rounded">search</span><input id="directory-search" type="search" placeholder="Search ${type}s, crops, phone…" autocomplete="off"></label><label class="directory-district"><span>District</span><select id="directory-district"><option value="">All districts</option>${districtOptions}</select></label></div><div class="directory-count" id="directory-count"></div><div class="directory-grid" id="directory-grid"></div></section>`;
        const search = area.querySelector('#directory-search'), select = area.querySelector('#directory-district'), grid = area.querySelector('#directory-grid'), count = area.querySelector('#directory-count');
        area.querySelectorAll('[data-breadcrumb]').forEach(link => link.addEventListener('click', e => {
            if (link.dataset.breadcrumb === 'home') return;
            e.preventDefault();
            if (link.dataset.breadcrumb === 'directory') {
                if (district) { history.pushState({ screen: 'content', view: type + '_directory', type, district: '' }, '', directoryUrl(type, '')); openDirectory(type, '', false); }
            }
        }));
        function apply() {
            const term = search.value.trim().toLowerCase(), selected = select.value;
            const filtered = rows.filter(r => { const hay = `${r.name || ''} ${r.district_name || ''} ${r.phone_number || ''} ${r.email || ''} ${r.address || ''} ${r.crops_display || ''}`.toLowerCase(); return (!term || hay.includes(term)) && (!selected || String(r.district_id) === String(selected)); });
            count.textContent = `${filtered.length} ${type}${filtered.length === 1 ? '' : 's'} showing`;
            grid.innerHTML = filtered.length ? filtered.map(r => { const crop = String(r.crops_display || '').split(',').map(s => s.trim()).filter(Boolean); return `<button type="button" class="directory-card" data-directory-id="${Number(r.id)}"><span class="directory-card-icon">${type === 'seller' ? '🌾' : '🏢'}</span><span class="directory-card-main"><strong>${esc(r.name)}</strong><small>${esc(r.district_name || '')}${r.address ? ' · ' + esc(r.address) : ''}</small><span class="directory-crops">${crop.slice(0, 4).map(c => `<em>${esc(c)}</em>`).join('')}</span></span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>`; }).join('') : '<div class="directory-empty"><span>🌱</span><strong>No contacts found</strong><p>Try another district or search term.</p></div>';
            grid.querySelectorAll('[data-directory-id]').forEach(card => card.addEventListener('click', () => { const row = rows.find(r => Number(r.id) === Number(card.dataset.directoryId)); if (row) openDetail(row, type, true, select.value); }));
        }
        search.addEventListener('input', apply); select.addEventListener('change', () => { pushDirectoryState(type, select.value); apply(); }); apply();
    }
    async function openDirectory(type, district, shouldPush) { try { if (window.app && typeof window.app.showScreen === 'function') window.app.showScreen('content'); if (window.app && typeof window.app.showLoading === 'function') window.app.showLoading(); const rows = await loadAll(type); render(type, rows, district || '', shouldPush); } catch (e) { console.error('Directory load failed:', e); if (window.app && typeof window.app.showError === 'function') window.app.showError('Could not load contacts. Please try again.'); } }
    async function replayPage() {
        const page = location.pathname.split('/').pop() || 'index.php', type = PAGE_TO_TYPE[page.replace('.php', '')]; if (!type) return;
        const initial = new URL(INITIAL_URL, location.href), qs = initial.searchParams, id = Number(qs.get(type + '_id') || 0), district = qs.get('district_id') || '', rows = await loadAll(type);
        if (initial.search) history.replaceState({ screen: 'content', view: id ? type + '_detail' : type + '_directory', type, id: id || undefined, district }, '', initial.pathname + initial.search);
        if (id) { const row = rows.find(r => Number(r.id) === id); if (row) openDetail(row, type, false, district); else render(type, rows, district, false); } else render(type, rows, district, false);
    }
    function install() {
        if (!window.app) return;
        const originalOpenService = window.app.openService.bind(window.app);
        window.app.openService = function (service) { if (service === 'sellers' || service === 'buyers') { openDirectory(service === 'sellers' ? 'seller' : 'buyer', '', true); return; } return originalOpenService(service); };
        if (typeof window.app._replayState === 'function') {
            const originalReplay = window.app._replayState.bind(window.app);
            window.app._replayState = function (state) {
                if (state && (state.view === 'seller_directory' || state.view === 'buyer_directory')) { openDirectory(state.type, state.district || '', false); return; }
                if (state && (state.view === 'seller_detail' || state.view === 'buyer_detail')) { loadAll(state.type).then(rows => { const row = rows.find(r => Number(r.id) === Number(state.id)); if (row) openDetail(row, state.type, false, state.district || ''); else openDirectory(state.type, state.district || '', false); }); return; }
                originalReplay(state);
            };
        }
        if (window.AGRO_PAGE === 'sellers' || window.AGRO_PAGE === 'buyers') setTimeout(replayPage, 0);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
