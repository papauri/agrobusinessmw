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

    // Bilingual copy. Follows the same `preferredLanguage` the dashboard writes
    // (assets/js/i18n.js), so a reader who chose Chichewa on the home page gets
    // Chichewa here too. Keys are grouped by where they appear.
    const copy = {
        en: {
            findSellers: 'Find Sellers', findBuyers: 'Find Buyers',
            sellers: 'Sellers', buyers: 'Buyers',
            seller: 'Seller', buyer: 'Buyer',
            intro: 'Search contacts first. Use district only to narrow the results.',
            searchSellers: 'Search sellers, crops, phone…',
            searchBuyers: 'Search buyers, crops, phone…',
            district: 'District', allDistricts: 'All districts',
            showingSellers: '{n} sellers showing', showingBuyers: '{n} buyers showing',
            emptyTitle: 'No contacts found', emptyBody: 'Try another search or district.',
            crops: 'Crops', phone: 'Phone', email: 'Email',
            call: 'Call', whatsapp: 'WhatsApp', share: 'Share', copied: 'Link copied',
            noContact: 'No contact details are on file for this listing yet.',
            contact: 'Contact', close: 'Close',
            loadFailed: 'Unable to load contacts'
        },
        ci: {
            findSellers: 'Pezani Ogulitsa', findBuyers: 'Pezani Ogula',
            sellers: 'Ogulitsa', buyers: 'Ogula',
            seller: 'Wogulitsa', buyer: 'Wogula',
            intro: 'Yambani ndi kufufuza. Gwiritsani ntchito chigawo pokhapokha mukufuna kuchepetsa zotsatira.',
            searchSellers: 'Sakani ogulitsa, mbewu, nambala…',
            searchBuyers: 'Sakani ogula, mbewu, nambala…',
            district: 'Chigawo', allDistricts: 'Zigawo zonse',
            showingSellers: 'Ogulitsa {n} akuoneka', showingBuyers: 'Ogula {n} akuoneka',
            emptyTitle: 'Palibe amene wapezeka', emptyBody: 'Yesani kufufuza kwina kapena chigawo china.',
            crops: 'Mbewu', phone: 'Foni', email: 'Imelo',
            call: 'Imbani', whatsapp: 'WhatsApp', share: 'Gawanani', copied: 'Ulalo wakopedwa',
            noContact: 'Palibe zambiri zolumikizirana zomwe zalembedwa pa uyu.',
            contact: 'Wolumikizana naye', close: 'Tsekani',
            loadFailed: 'Sitinathe kutsitsa mayina'
        }
    };

    const lang = () => (window.AgroLang ? window.AgroLang.current() : 'en');
    const t = (key, vars) => {
        let text = (copy[lang()] && copy[lang()][key]) || copy.en[key] || key;
        if (vars) Object.keys(vars).forEach(k => { text = text.replace('{' + k + '}', vars[k]); });
        return text;
    };

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
        if (!data.success) throw new Error(data.error || t('loadFailed'));
        cache[type] = Array.isArray(data.data) ? data.data : [];
        return cache[type];
    }

    function ensureDetailModal() {
        if (detailModal) return detailModal;
        detailModal = document.createElement('div');
        detailModal.id = 'directory-detail-modal';
        detailModal.className = 'modal';
        detailModal.innerHTML = '<div class="modal-backdrop" data-directory-close></div><div class="modal-content directory-detail-modal-content"><div class="modal-header"><h2 id="directory-detail-title"></h2><button type="button" class="modal-close" data-directory-close aria-label="Close"><span class="material-symbols-rounded">close</span></button></div><div class="modal-body" id="directory-detail-body"></div></div>';
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
        title.textContent = row.name || t(type === 'seller' ? 'seller' : 'buyer');

        const actions = [];
        if (row.phone_number) actions.push('<a class="directory-action primary" href="tel:' + esc(row.phone_number) + '"><span class="material-symbols-rounded" aria-hidden="true">call</span> ' + esc(t('call')) + '</a>');
        if (wa) actions.push('<a class="directory-action whatsapp" href="' + esc(wa) + '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded" aria-hidden="true">chat</span> ' + esc(t('whatsapp')) + '</a>');
        if (row.email) actions.push('<a class="directory-action" href="mailto:' + esc(row.email) + '"><span class="material-symbols-rounded" aria-hidden="true">mail</span> ' + esc(t('email')) + '</a>');
        actions.push('<button type="button" class="directory-action" id="directory-share-contact"><span class="material-symbols-rounded" aria-hidden="true">share</span> ' + esc(t('share')) + '</button>');

        body.innerHTML = '<div class="directory-contact-hero"><div class="directory-avatar" aria-hidden="true">' + (type === 'seller' ? '🌾' : '🏢') + '</div><div><span class="directory-role">' + esc(t(type === 'seller' ? 'seller' : 'buyer')) + '</span><h3>' + esc(row.name) + '</h3><p>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</p></div></div>'
            + (crops.length ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('crops')) + '</span><div class="directory-crop-list">' + crops.map(c => '<span>' + esc(c) + '</span>').join('') + '</div></div>' : '')
            + (row.phone_number ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('phone')) + '</span><p class="directory-contact-value">' + esc(row.phone_number) + '</p></div>' : '')
            + (row.email ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('email')) + '</span><p class="directory-contact-value">' + esc(row.email) + '</p></div>' : '')
            + (!row.phone_number && !row.email ? '<p class="directory-no-contact">' + esc(t('noContact')) + '</p>' : '')
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
                    if (label) label.textContent = ' ' + t('copied');
                    setTimeout(() => { shareBtn.classList.remove('copied'); if (label) label.textContent = ' ' + t('share'); }, 2000);
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
        const isSeller = type === 'seller';
        const label = t(isSeller ? 'sellers' : 'buyers');
        const heading = t(isSeller ? 'findSellers' : 'findBuyers');
        const searchPlaceholder = t(isSeller ? 'searchSellers' : 'searchBuyers');
        area.innerHTML = '<section class="directory-page">'
            + '<div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span>'
            + '<h2>' + esc(heading) + '</h2><p>' + esc(t('intro')) + '</p></div>'
            + '<div class="directory-total"><strong>' + rows.length + '</strong><span>' + esc(label) + '</span></div></div>'
            + '<div class="directory-controls">'
            + '<label class="directory-search"><span class="material-symbols-rounded" aria-hidden="true">search</span>'
            + '<input id="directory-search" type="search" placeholder="' + esc(searchPlaceholder) + '" autocomplete="off"></label>'
            + '<label class="directory-district"><span>' + esc(t('district')) + '</span>'
            + '<select id="directory-district"><option value="">' + esc(t('allDistricts')) + '</option>' + options + '</select></label>'
            + '</div><div class="directory-count" id="directory-count"></div>'
            + '<div class="directory-grid" id="directory-grid"></div></section>';
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
            count.textContent = t(isSeller ? 'showingSellers' : 'showingBuyers', { n: filtered.length });
            grid.innerHTML = filtered.length ? filtered.map(row => {
                const crops = String(row.crops_display || '').split(',').map(s => s.trim()).filter(Boolean).slice(0,4);
                return '<button type="button" class="directory-card" data-id="' + Number(row.id) + '"><span class="directory-card-icon">' + (type === 'seller' ? '🌾' : '🏢') + '</span><span class="directory-card-main"><strong>' + esc(row.name) + '</strong><small>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</small><span class="directory-crops">' + crops.map(c => '<em>' + esc(c) + '</em>').join('') + '</span></span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>';
            }).join('') : '<div class="directory-empty"><span aria-hidden="true">🌱</span><strong>' + esc(t('emptyTitle')) + '</strong><p>' + esc(t('emptyBody')) + '</p></div>';
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
            if (area) area.innerHTML = '<div class="directory-empty"><strong>' + esc(t('loadFailed')) + '</strong><p>' + esc(error.message) + '</p></div>';
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

    // Follow the language switcher. The rows are already cached, so this is a
    // re-render rather than a refetch, and the user keeps their place.
    if (window.AgroLang) {
        window.AgroLang.onChange(function () {
            const qs = new URLSearchParams(location.search);
            if (Number(qs.get(directoryType + '_id') || 0)) return; // detail view handles itself
            openDirectory(directoryType, qs.get('district_id') || '');
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
