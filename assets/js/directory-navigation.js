/* AgroBusiness Malawi — simple contact-first Sellers / Buyers directory. */
(function () {
    'use strict';

    const PAGE_TO_TYPE = { sellers: 'seller', buyers: 'buyer' };
    const TYPE_TO_PAGE = { seller: 'sellers.php', buyer: 'buyers.php' };
    const pageName = (location.pathname.split('/').pop() || 'index.php').replace(/\.php$/, '');
    const directoryType = PAGE_TO_TYPE[pageName] || null;
    const cache = {};
    let detailModal = null;

    if (!directoryType) return;

    const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

    function pageUrl(type, params) {
        const q = new URLSearchParams();
        Object.keys(params || {}).forEach(k => { if (params[k]) q.set(k, params[k]); });
        return TYPE_TO_PAGE[type] + (q.toString() ? '?' + q.toString() : '');
    }

    async function loadAll(type) {
        if (cache[type]) return cache[type];
        const apiType = type === 'seller' ? 'sellers' : 'buyers';
        const response = await fetch('api.php?action=' + apiType, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) throw new Error('Directory API HTTP ' + response.status);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Unable to load contacts');
        cache[type] = Array.isArray(data.data) ? data.data : [];
        return cache[type];
    }

    function ensureDetailModal() {
        if (detailModal) return detailModal;
        detailModal = document.createElement('div');
        detailModal.id = 'directory-detail-modal';
        detailModal.className = 'modal';
        detailModal.innerHTML = '<div class="modal-backdrop" data-directory-close></div><div class="modal-content directory-detail-modal-content"><div class="modal-header"><h2 id="directory-detail-title">Contact</h2><button type="button" class="modal-close" data-directory-close aria-label="Close"><span class="material-symbols-rounded">close</span></button></div><div class="modal-body" id="directory-detail-body"></div></div>';
        document.body.appendChild(detailModal);
        const close = () => {
            detailModal.classList.remove('active');
            if (history.state && history.state.directoryDetail) history.back();
        };
        detailModal.addEventListener('click', function (e) {
            if (e.target.closest('[data-directory-close]')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && detailModal.classList.contains('active')) close();
        });
        return detailModal;
    }

    // wa.me wants the number with no plus and no separators.
    function whatsappLink(phone) {
        const digits = String(phone || '').replace(/[^0-9]/g, '');
        return digits.length >= 8 ? 'https://wa.me/' + digits : '';
    }

    function openDetail(row, type, push, district) {
        const modal = ensureDetailModal();
        if (push) {
            history.pushState({ directoryDetail: true, type, id: Number(row.id), district: district || '' }, '', pageUrl(type, { [type + '_id']: row.id, district_id: district || '' }));
        }
        const title = document.getElementById('directory-detail-title');
        const body = document.getElementById('directory-detail-body');
        const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean);
        const wa = whatsappLink(row.phone_number);
        title.textContent = row.name || (type === 'seller' ? 'Seller' : 'Buyer');

        const actions = [];
        if (row.phone_number) actions.push('<a class="directory-action primary" href="tel:' + esc(row.phone_number) + '"><span class="material-symbols-rounded" aria-hidden="true">call</span> Call</a>');
        if (wa) actions.push('<a class="directory-action whatsapp" href="' + esc(wa) + '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded" aria-hidden="true">chat</span> WhatsApp</a>');
        if (row.email) actions.push('<a class="directory-action" href="mailto:' + esc(row.email) + '"><span class="material-symbols-rounded" aria-hidden="true">mail</span> Email</a>');
        actions.push('<button type="button" class="directory-action" id="directory-share-contact"><span class="material-symbols-rounded" aria-hidden="true">share</span> Share</button>');

        body.innerHTML = '<div class="directory-contact-hero"><div class="directory-avatar" aria-hidden="true">' + (type === 'seller' ? '🌾' : '🏢') + '</div><div><span class="directory-role">' + (type === 'seller' ? 'Seller' : 'Buyer') + '</span><h3>' + esc(row.name) + '</h3><p>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</p></div></div>'
            + (crops.length ? '<div class="directory-detail-section"><span class="directory-label">Crops</span><div class="directory-crop-list">' + crops.map(c => '<span>' + esc(c) + '</span>').join('') + '</div></div>' : '')
            + (row.phone_number ? '<div class="directory-detail-section"><span class="directory-label">Phone</span><p class="directory-contact-value">' + esc(row.phone_number) + '</p></div>' : '')
            + (row.email ? '<div class="directory-detail-section"><span class="directory-label">Email</span><p class="directory-contact-value">' + esc(row.email) + '</p></div>' : '')
            + (!row.phone_number && !row.email ? '<p class="directory-no-contact">No contact details are on file for this listing yet.</p>' : '')
            + '<div class="directory-detail-actions">' + actions.join('') + '</div>';

        const shareBtn = document.getElementById('directory-share-contact');
        shareBtn.addEventListener('click', function () {
            const url = location.href;
            const shareData = { title: row.name || type, text: [row.name, row.district_name, row.phone_number].filter(Boolean).join(' · '), url };
            if (navigator.share) { navigator.share(shareData).catch(() => {}); return; }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(() => {
                    shareBtn.classList.add('copied');
                    const label = shareBtn.lastChild;
                    if (label) label.textContent = ' Link copied';
                    setTimeout(() => { shareBtn.classList.remove('copied'); if (label) label.textContent = ' Share'; }, 2000);
                }).catch(() => {});
                return;
            }
            // Neither API available (older Android WebViews): show the URL so the
            // user can copy it by hand rather than have the button do nothing.
            window.prompt('Copy this link:', url);
        });

        modal.classList.add('active');
        // Focus the dialog so a keyboard or screen-reader user lands inside it.
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();
    }

    async function render(type, rows, district) {
        const area = document.getElementById('content-area');
        if (!area) return;
        const districts = new Map();
        rows.forEach(r => { if (r.district_id && r.district_name) districts.set(String(r.district_id), r.district_name); });
        const options = [...districts.entries()].sort((a,b) => a[1].localeCompare(b[1])).map(([id,name]) => '<option value="' + esc(id) + '"' + (String(id) === String(district) ? ' selected' : '') + '>' + esc(name) + '</option>').join('');
        const label = type === 'seller' ? 'Sellers' : 'Buyers';
        area.innerHTML = '<section class="directory-page"><div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span><h2>Find ' + label + '</h2><p>Search contacts first. Use district only to narrow the results.</p></div><div class="directory-total"><strong>' + rows.length + '</strong><span>' + label + '</span></div></div><div class="directory-controls"><label class="directory-search"><span class="material-symbols-rounded">search</span><input id="directory-search" type="search" placeholder="Search ' + label.toLowerCase() + ', crops, phone…" autocomplete="off"></label><label class="directory-district"><span>District</span><select id="directory-district"><option value="">All districts</option>' + options + '</select></label></div><div class="directory-count" id="directory-count"></div><div class="directory-grid" id="directory-grid"></div></section>';
        const search = area.querySelector('#directory-search');
        const select = area.querySelector('#directory-district');
        const count = area.querySelector('#directory-count');
        const grid = area.querySelector('#directory-grid');
        function apply() {
            const term = search.value.trim().toLowerCase();
            const selected = select.value;
            const filtered = rows.filter(row => {
                const hay = [row.name,row.district_name,row.phone_number,row.email,row.address,row.crops_display].join(' ').toLowerCase();
                return (!term || hay.includes(term)) && (!selected || String(row.district_id) === selected);
            });
            count.textContent = filtered.length + ' ' + label.toLowerCase() + ' showing';
            grid.innerHTML = filtered.length ? filtered.map(row => {
                const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean).slice(0,4);
                return '<button type="button" class="directory-card" data-id="' + Number(row.id) + '"><span class="directory-card-icon">' + (type === 'seller' ? '🌾' : '🏢') + '</span><span class="directory-card-main"><strong>' + esc(row.name) + '</strong><small>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</small><span class="directory-crops">' + crops.map(c => '<em>' + esc(c) + '</em>').join('') + '</span></span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>';
            }).join('') : '<div class="directory-empty"><span>🌱</span><strong>No contacts found</strong><p>Try another search or district.</p></div>';
            grid.querySelectorAll('[data-id]').forEach(card => card.onclick = () => { const row = rows.find(r => Number(r.id) === Number(card.dataset.id)); if (row) openDetail(row, type, true, selected); });
        }
        search.oninput = apply;
        select.onchange = function () {
            const value = select.value;
            history.pushState({ directory: true, type, district: value }, '', pageUrl(type, { district_id: value }));
            apply();
        };
        apply();
    }

    async function openDirectory(type, district) {
        try {
            if (window.app && typeof window.app.showScreen === 'function') window.app.showScreen('content');
            const rows = await loadAll(type);
            await render(type, rows, district || '');
        } catch (error) {
            console.error('Directory load failed:', error);
            const area = document.getElementById('content-area');
            if (area) area.innerHTML = '<div class="directory-empty"><strong>Unable to load contacts</strong><p>' + esc(error.message) + '</p></div>';
        }
    }

    function start() {
        const qs = new URLSearchParams(location.search);
        const id = Number(qs.get(directoryType + '_id') || 0);
        const district = qs.get('district_id') || '';
        loadAll(directoryType).then(async rows => {
            // Always render the directory first. A shared link like
            // sellers.php?seller_id=7 used to open the modal over an empty page,
            // so closing it left the user staring at nothing.
            await openDirectory(directoryType, district);
            if (id) {
                const row = rows.find(r => Number(r.id) === id);
                if (row) openDetail(row, directoryType, false, district);
            }
        }).catch(error => {
            console.error('Directory start failed:', error);
            openDirectory(directoryType, district);
        });
    }

    window.addEventListener('popstate', function () {
        const qs = new URLSearchParams(location.search);
        const id = Number(qs.get(directoryType + '_id') || 0);
        const district = qs.get('district_id') || '';
        if (id) loadAll(directoryType).then(async rows => {
            await openDirectory(directoryType, district);
            const row = rows.find(r => Number(r.id) === id);
            if (row) openDetail(row, directoryType, false, district);
        });
        else {
            if (detailModal) detailModal.classList.remove('active');
            openDirectory(directoryType, district);
        }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
