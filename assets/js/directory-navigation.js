/* AgroBusiness Malawi — contact-first Sellers / Buyers directory. */
(function () {
    'use strict';

    const INITIAL_URL = window.location.href;
    const PAGE_TO_TYPE = { sellers: 'seller', buyers: 'buyer' };
    const TYPE_TO_PAGE = { seller: 'sellers.php', buyer: 'buyers.php' };
    const cache = { seller: null, buyer: null };
    let detailModal = null;

    const pageName = String(window.AGRO_PAGE || '').replace(/\.php$/, '');
    const directoryType = PAGE_TO_TYPE[pageName] || null;

    // app.js boots immediately on standalone pages. Its old Sellers / Buyers branch
    // opens the district picker. Suppress that boot path before app.js runs.
    let savedLanguageFlag = null;
    let languageFlagRestored = false;
    if (directoryType) {
        window.__AGRO_DIRECTORY_SERVICE = pageName;
        window.AGRO_PAGE = null;
        savedLanguageFlag = localStorage.getItem('hasSelectedLanguage');
        if (savedLanguageFlag !== null) localStorage.setItem('hasSelectedLanguage', 'false');
    }
    function restoreLanguageFlag() {
        if (!directoryType || languageFlagRestored || savedLanguageFlag === null) return;
        languageFlagRestored = true;
        localStorage.setItem('hasSelectedLanguage', savedLanguageFlag);
    }
    if (directoryType) window.addEventListener('pagehide', restoreLanguageFlag, { once: true });

    const esc = (v) => window.escapeHtml
        ? window.escapeHtml(v)
        : String(v == null ? '' : v).replace(/[&<>\"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#39;' }[c]));

    function directoryUrl(type, district) {
        const qs = new URLSearchParams();
        if (district) qs.set('district_id', district);
        return TYPE_TO_PAGE[type] + (qs.toString() ? '?' + qs.toString() : '');
    }
    function detailUrl(type, id, district) {
        const qs = new URLSearchParams();
        qs.set(type + '_id', String(id));
        if (district) qs.set('district_id', String(district));
        return TYPE_TO_PAGE[type] + '?' + qs.toString();
    }
    function pushDirectoryState(type, district) {
        history.pushState({ screen: 'content', view: type + '_directory', type, district: district || '' }, '', directoryUrl(type, district));
    }
    function pushDetailState(type, row, district) {
        history.pushState({ screen: 'content', view: type + '_detail', type, id: Number(row.id), district: district || '' }, '', detailUrl(type, row.id, district));
    }

    // One request for the whole directory. The previous implementation made one
    // request per district (28 requests) and still allowed the old district modal
    // to win the initial render race.
    async function loadAll(type) {
        if (cache[type]) return cache[type];
        const response = await fetch(`directory-api.php?type=${encodeURIComponent(type)}&district_id=0`, { headers: { 'Accept': 'application/json' } });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || `Unable to load ${type}s`);
        cache[type] = data.data || [];
        return cache[type];
    }

    function breadcrumb(type, districtName, contactName) {
        const label = type === 'seller' ? 'Sellers' : 'Buyers';
        return `<nav class="directory-breadcrumbs" aria-label="Breadcrumb"><a href="index.php" class="directory-crumb" data-breadcrumb="home"><span class="material-symbols-rounded" aria-hidden="true">home</span><span>Home</span></a><span class="directory-crumb-separator" aria-hidden="true">chevron_right</span><a href="${TYPE_TO_PAGE[type]}" class="directory-crumb" data-breadcrumb="directory">${label}</a>${districtName ? `<span class="directory-crumb-separator" aria-hidden="true">chevron_right</span><span class="directory-crumb directory-crumb-current">${esc(districtName)}</span>` : ''}${contactName ? `<span class="directory-crumb-separator" aria-hidden="true">chevron_right</span><span class="directory-crumb directory-crumb-current directory-crumb-contact">${esc(contactName)}</span>` : ''}</nav>`;
    }

    function showShareNotice(message) {
        const old = document.getElementById('directory-share-notice');
        if (old) old.remove();
        const notice = document.createElement('div');
        notice.id = 'directory-share-notice';
        notice.className = 'directory-share-notice';
        notice.textContent = message;
        document.body.appendChild(notice);
        requestAnimationFrame(() => notice.classList.add('is-visible'));
        setTimeout(() => { notice.classList.remove('is-visible'); setTimeout(() => notice.remove(), 220); }, 1800);
    }

    function shareContact(row, type, district) {
        const title = row.name || (type === 'seller' ? 'Seller' : 'Buyer');
        const url = new URL(detailUrl(type, row.id, district), window.location.href).href;
        const text = `${title} — ${type === 'seller' ? 'Seller' : 'Buyer'}${row.district_name ? ` in ${row.district_name}` : ''}`;
        if (navigator.share) navigator.share({ title, text, url }).catch(() => {});
        else if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(() => showShareNotice('Link copied')).catch(() => window.prompt('Copy this link:', url));
        else window.prompt('Copy this link:', url);
    }

    function ensureDetailModal() {
        if (detailModal) return detailModal;
        detailModal = document.createElement('div');
        detailModal.id = 'directory-detail-modal';
        detailModal.className = 'modal';
        detailModal.setAttribute('role', 'dialog');
        detailModal.setAttribute('aria-modal', 'true');
        detailModal.innerHTML = `<div class="modal-backdrop" data-directory-close></div><div class="modal-content directory-detail-modal-content"><div class="modal-header"><h2 id="directory-detail-title">Contact</h2><button type="button" class="modal-close" aria-label="Close" data-directory-close><span class="material-symbols-rounded">close</span></button></div><div class="directory-modal-breadcrumb" id="directory-modal-breadcrumb"></div><div class="modal-body" id="directory-detail-body"></div></div>`;
        document.body.appendChild(detailModal);
        detailModal.addEventListener('click', e => {
            const crumb = e.target.closest('[data-breadcrumb]');
            if (crumb) {
                e.preventDefault();
                if (crumb.dataset.breadcrumb === 'home') { restoreLanguageFlag(); window.location.href = 'index.php'; return; }
                if (history.state && /_detail$/.test(history.state.view || '')) history.back();
                return;
            }
            if (!e.target.closest('[data-directory-close]')) return;
            if (window.app && typeof window.app.closeModal === 'function') window.app.closeModal(detailModal); else detailModal.classList.remove('active');
            if (history.state && /_detail$/.test(history.state.view || '')) history.back();
        });
        return detailModal;
    }

    function openDetail(row, type, shouldPush, district) {
        const modal = ensureDetailModal();
        if (shouldPush) pushDetailState(type, row, district);
        const title = document.getElementById('directory-detail-title');
        const body = document.getElementById('directory-detail-body');
        const crumb = document.getElementById('directory-modal-breadcrumb');
        const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean);
        if (title) title.textContent = row.name || (type === 'seller' ? 'Seller' : 'Buyer');
        if (crumb) crumb.innerHTML = breadcrumb(type, row.district_name || '', row.name || 'Contact');
        if (body) body.innerHTML = `<div class="directory-contact-hero"><div class="directory-avatar">${type === 'seller' ? '🌾' : '🏢'}</div><div><span class="directory-role">${type === 'seller' ? 'Seller' : 'Buyer'}</span><h3>${esc(row.name)}</h3><p>${esc(row.district_name || '')}${row.address ? ' · ' + esc(row.address) : ''}</p></div></div>${crops.length ? `<div class="directory-detail-section"><span class="directory-label">Crops</span><div class="directory-crop-list">${crops.map(c => `<span>${esc(c)}</span>`).join('')}</div></div>` : ''}<div class="directory-detail-actions">${row.phone_number ? `<a class="directory-action primary" href="tel:${esc(row.phone_number)}"><span class="material-symbols-rounded">call</span> Call ${esc(row.phone_number)}</a>` : ''}${row.email ? `<a class="directory-action" href="mailto:${esc(row.email)}"><span class="material-symbols-rounded">mail</span> Email</a>` : ''}<button type="button" class="directory-action directory-share-action" id="directory-share-contact"><span class="material-symbols-rounded">share</span> Share</button>${!row.phone_number && !row.email ? '<p class="directory-muted">No contact details listed.</p>' : ''}</div>`;
        const share = document.getElementById('directory-share-contact');
        if (share) share.addEventListener('click', () => shareContact(row, type, district));
        if (window.app && typeof window.app.openModal === 'function') window.app.openModal(modal); else modal.classList.add('active');
    }

    async function render(type, rows, district, shouldPush) {
        if (shouldPush) pushDirectoryState(type, district);
        const area = document.getElementById('content-area');
        if (!area) return;
        const districts = (window.app && typeof window.app.loadDistricts === 'function') ? await window.app.loadDistricts() : [];
        const districtMap = new Map(districts.map(d => [String(d.id), d.name]));
        rows.forEach(r => { if (r.district_id && r.district_name) districtMap.set(String(r.district_id), r.district_name); });
        const districtOptions = [...districtMap.entries()].sort((a, b) => a[1].localeCompare(b[1])).map(([id, name]) => `<option value="${esc(id)}" ${String(id) === String(district) ? 'selected' : ''}>${esc(name)}</option>`).join('');
        const districtName = district ? (districtMap.get(String(district)) || '') : '';
        const title = type === 'seller' ? 'Find Sellers' : 'Find Buyers';
        const subtitle = type === 'seller' ? 'Sellers are shown first. Narrow the list by district or search.' : 'Buyers are shown first. Narrow the list by district or search.';

        area.innerHTML = `<section class="directory-page">${breadcrumb(type, districtName, '')}<div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span><h2>${title}</h2><p>${subtitle}</p></div><div class="directory-total"><strong>${rows.length}</strong><span>${type}s</span></div></div><div class="directory-controls"><label class="directory-search"><span class="material-symbols-rounded">search</span><input id="directory-search" type="search" placeholder="Search ${type}s, crops, phone…" autocomplete="off"></label><label class="directory-district"><span>District</span><select id="directory-district"><option value="">All districts</option>${districtOptions}</select></label></div><div class="directory-count" id="directory-count"></div><div class="directory-grid" id="directory-grid"></div></section>`;

        const search = area.querySelector('#directory-search');
        const select = area.querySelector('#directory-district');
        const grid = area.querySelector('#directory-grid');
        const count = area.querySelector('#directory-count');
        function apply() {
            const term = search.value.trim().toLowerCase();
            const selected = select.value;
            const filtered = rows.filter(r => {
                const hay = `${r.name || ''} ${r.district_name || ''} ${r.phone_number || ''} ${r.email || ''} ${r.address || ''} ${r.crops_display || ''}`.toLowerCase();
                return (!term || hay.includes(term)) && (!selected || String(r.district_id) === String(selected));
            });
            count.textContent = `${filtered.length} ${type}${filtered.length === 1 ? '' : 's'} showing`;
            grid.innerHTML = filtered.length ? filtered.map(r => {
                const crops = String(r.crops_display || '').split(',').map(s => s.trim()).filter(Boolean).slice(0, 4);
                return `<button type="button" class="directory-card" data-directory-id="${Number(r.id)}"><span class="directory-card-icon">${type === 'seller' ? '🌾' : '🏢'}</span><span class="directory-card-main"><strong>${esc(r.name)}</strong><small>${esc(r.district_name || '')}${r.address ? ' · ' + esc(r.address) : ''}</small><span class="directory-crops">${crops.map(c => `<em>${esc(c)}</em>`).join('')}</span></span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>`;
            }).join('') : '<div class="directory-empty"><span>🌱</span><strong>No contacts found</strong><p>Try another district or search term.</p></div>';
            grid.querySelectorAll('[data-directory-id]').forEach(card => card.addEventListener('click', () => {
                const row = rows.find(r => Number(r.id) === Number(card.dataset.directoryId));
                if (row) openDetail(row, type, true, select.value);
            }));
        }
        search.addEventListener('input', apply);
        select.addEventListener('change', () => { pushDirectoryState(type, select.value); apply(); });
        apply();
    }

    async function openDirectory(type, district, shouldPush) {
        try {
            if (window.app && typeof window.app.showScreen === 'function') window.app.showScreen('content');
            if (window.app && typeof window.app.showLoading === 'function') window.app.showLoading();
            const rows = await loadAll(type);
            await render(type, rows, district || '', shouldPush);
        } catch (e) {
            console.error('Directory load failed:', e);
            if (window.app && typeof window.app.showError === 'function') window.app.showError('Could not load contacts. Please try again.');
        }
    }

    async function replayPage() {
        if (!directoryType) return;
        const initial = new URL(INITIAL_URL, location.href);
        const qs = initial.searchParams;
        const id = Number(qs.get(directoryType + '_id') || 0);
        const district = qs.get('district_id') || '';
        const rows = await loadAll(directoryType);
        if (initial.search) history.replaceState({ screen: 'content', view: id ? directoryType + '_detail' : directoryType + '_directory', type: directoryType, id: id || undefined, district }, '', initial.pathname + initial.search);
        if (id) {
            const row = rows.find(r => Number(r.id) === id);
            if (row) openDetail(row, directoryType, false, district); else await render(directoryType, rows, district, false);
        } else await render(directoryType, rows, district, false);
    }

    function install() {
        if (!window.app) return false;
        const originalOpenService = window.app.openService.bind(window.app);
        window.app.openService = function (service) {
            if (service === 'sellers' || service === 'buyers') {
                openDirectory(service === 'sellers' ? 'seller' : 'buyer', '', true);
                return;
            }
            return originalOpenService(service);
        };
        if (typeof window.app._replayState === 'function') {
            const originalReplay = window.app._replayState.bind(window.app);
            window.app._replayState = function (state) {
                if (state && (state.view === 'seller_directory' || state.view === 'buyer_directory')) { openDirectory(state.type, state.district || '', false); return; }
                if (state && (state.view === 'seller_detail' || state.view === 'buyer_detail')) {
                    loadAll(state.type).then(rows => { const row = rows.find(r => Number(r.id) === Number(state.id)); if (row) openDetail(row, state.type, false, state.district || ''); else openDirectory(state.type, state.district || '', false); });
                    return;
                }
                originalReplay(state);
            };
        }
        return true;
    }

    function boot() {
        if (!directoryType) return;
        if (install()) {
            // Keep the temporary false value until this page leaves. That prevents
            // a late async _bootPage() from redirecting this standalone directory.
            replayPage();
            return;
        }
        setTimeout(boot, 50);
    }

    if (directoryType) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
    }
})();
