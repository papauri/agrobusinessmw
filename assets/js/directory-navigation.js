/* AgroBusiness Malawi — simple contact-first Sellers / Buyers directory. */
(function () {
    'use strict';

    // Three directories, one controller. Sellers and buyers are contact-first
    // listings promoted out of an approved application; farmers are the people
    // who registered to farm and have no contact details published (see the
    // `farmers` action in api.php for why). They share every control on the
    // page — search, district, crop — so they share the code that draws them.
    const PAGE_TO_TYPE = { sellers: 'seller', buyers: 'buyer', farmers: 'farmer' };
    const TYPE_TO_PAGE = { seller: 'sellers.php', buyer: 'buyers.php', farmer: 'farmers.php' };
    const TYPE_TO_ACTION = { seller: 'sellers', buyer: 'buyers', farmer: 'farmers' };
    const TYPE_TO_ICON = { seller: '🌾', buyer: '🏢', farmer: '🧑‍🌾' };
    const pageName = (location.pathname.split('/').pop() || 'index.php').replace(/\.php$/, '');
    const directoryType = PAGE_TO_TYPE[pageName] || null;
    const cache = {};
    let detailModal = null;

    if (!directoryType) return;

    // A farmer listing publishes no phone, WhatsApp or email — the API does not
    // send them. Every contact-shaped branch below asks this rather than
    // testing the type again in six places.
    const hasContact = type => type !== 'farmer';

    // Bilingual copy. Follows the same `preferredLanguage` the dashboard writes
    // (assets/js/i18n.js), so a reader who chose Chichewa on the home page gets
    // Chichewa here too. Keys are grouped by where they appear.
    const copy = {
        en: {
            findSellers: 'Find Sellers', findBuyers: 'Find Buyers', findFarmers: 'Registered Farmers',
            sellers: 'Sellers', buyers: 'Buyers', farmers: 'Farmers',
            seller: 'Seller', buyer: 'Buyer', farmer: 'Farmer',
            intro: 'Search contacts first. Use district and crop only to narrow the results.',
            farmersIntro: 'Everyone who has registered as a farmer, newest first. Search by name, district or crop.',
            searchSellers: 'Search sellers, crops, phone…',
            searchBuyers: 'Search buyers, crops, phone…',
            searchFarmers: 'Search farmers, crops, village…',
            district: 'District', allDistricts: 'All districts',
            cropFilter: 'Crop', allCrops: 'All crops',
            showingSellers: '{n} sellers showing', showingBuyers: '{n} buyers showing',
            showingFarmers: '{n} farmers showing',
            emptyTitle: 'No contacts found', emptyBody: 'Try another search, district or crop.',
            farmersEmptyTitle: 'No farmers listed yet',
            farmersEmptyBody: 'Farmers appear here once their registration has been approved.',
            crops: 'Crops', noCrops: 'No crops listed', moreCrops: '+{n} more',
            phone: 'Phone', email: 'Email', village: 'Village', registered: 'Registered',
            call: 'Call', whatsapp: 'WhatsApp', share: 'Share', copied: 'Link copied',
            noContact: 'No contact details are on file for this listing yet.',
            farmerPrivacy: 'Farmer contact details are kept private. Register as a buyer or seller to be listed with your own contact details.',
            contact: 'Contact', close: 'Close',
            loadFailed: 'Unable to load contacts'
        },
        ci: {
            findSellers: 'Pezani Ogulitsa', findBuyers: 'Pezani Ogula', findFarmers: 'Alimi Olembetsa',
            sellers: 'Ogulitsa', buyers: 'Ogula', farmers: 'Alimi',
            seller: 'Wogulitsa', buyer: 'Wogula', farmer: 'Mlimi',
            intro: 'Yambani ndi kufufuza. Gwiritsani ntchito chigawo ndi mbewu pokhapokha mukufuna kuchepetsa zotsatira.',
            farmersIntro: 'Onse amene alembetsa ngati alimi, atsopano poyamba. Sakani ndi dzina, chigawo kapena mbewu.',
            searchSellers: 'Sakani ogulitsa, mbewu, nambala…',
            searchBuyers: 'Sakani ogula, mbewu, nambala…',
            searchFarmers: 'Sakani alimi, mbewu, mudzi…',
            district: 'Chigawo', allDistricts: 'Zigawo zonse',
            cropFilter: 'Mbewu', allCrops: 'Mbewu zonse',
            showingSellers: 'Ogulitsa {n} akuoneka', showingBuyers: 'Ogula {n} akuoneka',
            showingFarmers: 'Alimi {n} akuoneka',
            emptyTitle: 'Palibe amene wapezeka', emptyBody: 'Yesani kufufuza kwina, chigawo kapena mbewu ina.',
            farmersEmptyTitle: 'Palibe mlimi amene walembedwa',
            farmersEmptyBody: 'Alimi amaonekera pano kalembera wawo akavomerezedwa.',
            crops: 'Mbewu', noCrops: 'Palibe mbewu yolembedwa', moreCrops: '+{n} zina',
            phone: 'Foni', email: 'Imelo', village: 'Mudzi', registered: 'Analembetsa',
            call: 'Imbani', whatsapp: 'WhatsApp', share: 'Gawanani', copied: 'Ulalo wakopedwa',
            noContact: 'Palibe zambiri zolumikizirana zomwe zalembedwa pa uyu.',
            farmerPrivacy: 'Nambala za alimi sitizionetsa poyera. Lembetsani ngati wogula kapena wogulitsa kuti nambala yanu ilembedwe.',
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

    /* The crop list a row deals in. api.php sends `crops` as a real array —
     * built by splitting a GROUP_CONCAT on a newline rather than a comma, so a
     * crop name containing a comma cannot break it. The `crops_display` string
     * is for reading, never for parsing. */
    function cropsOf(row) {
        return Array.isArray(row.crops) ? row.crops.filter(Boolean) : [];
    }

    /** Every distinct crop across the loaded rows, sorted — the filter options. */
    function cropOptions(rows) {
        const seen = new Set();
        rows.forEach(row => cropsOf(row).forEach(crop => seen.add(crop)));
        return [...seen].sort((a, b) => a.localeCompare(b));
    }

    function formatDate(value) {
        if (!value) return '';
        // iOS Safari rejects 'YYYY-MM-DD HH:MM:SS'; it wants the T separator.
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (isNaN(parsed.getTime())) return '';
        return parsed.toLocaleDateString(lang() === 'ci' ? 'ny-MW' : 'en-GB',
            { year: 'numeric', month: 'short', day: 'numeric' });
    }

    async function loadAll(type) {
        if (cache[type]) return cache[type];
        const apiType = TYPE_TO_ACTION[type];
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

    /* wa.me wants the number with no plus and no separators.
     *
     * Prefer the contact's dedicated whatsapp_number and fall back to their
     * phone. The contact tables have carried a whatsapp_number column all along
     * and nothing read it, so every WhatsApp button pointed at the landline or
     * office number whether or not that number was on WhatsApp. */
    function whatsappNumber(row) {
        return row.whatsapp_number || row.phone_number || '';
    }

    function whatsappLink(number) {
        const digits = String(number || '').replace(/[^0-9]/g, '');
        return digits.length >= 8 ? 'https://wa.me/' + digits : '';
    }

    function openDetail(row, type, push, district, crop) {
        const modal = ensureDetailModal();
        if (push) {
            history.pushState({ directoryDetail: true, type, id: Number(row.id), district: district || '', crop: crop || '' }, '', pageUrl(type, { [type + '_id']: row.id, district_id: district || '', crop: crop || '' }));
        }
        const title = document.getElementById('directory-detail-title');
        const body = document.getElementById('directory-detail-body');
        const crops = cropsOf(row);
        const waNumber = whatsappNumber(row);
        const wa = whatsappLink(waNumber);
        // Only worth showing separately when it differs from the phone number.
        const waIsDistinct = Boolean(row.whatsapp_number) && row.whatsapp_number !== row.phone_number;
        const registered = formatDate(row.created_at);
        title.textContent = row.name || t(type);

        const actions = [];
        if (row.phone_number) actions.push('<a class="directory-action primary" href="tel:' + esc(row.phone_number) + '"><span class="material-symbols-rounded" aria-hidden="true">call</span> ' + esc(t('call')) + '</a>');
        if (wa) actions.push('<a class="directory-action whatsapp" href="' + esc(wa) + '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-rounded" aria-hidden="true">chat</span> ' + esc(t('whatsapp')) + '</a>');
        if (row.email) actions.push('<a class="directory-action" href="mailto:' + esc(row.email) + '"><span class="material-symbols-rounded" aria-hidden="true">mail</span> ' + esc(t('email')) + '</a>');
        actions.push('<button type="button" class="directory-action" id="directory-share-contact"><span class="material-symbols-rounded" aria-hidden="true">share</span> ' + esc(t('share')) + '</button>');

        // Crops come first for a farmer: it is the only thing this listing is
        // for. For a seller or buyer the crops sit above the contact block for
        // the same reason — you pick who to call by what they deal in.
        body.innerHTML = '<div class="directory-contact-hero"><div class="directory-avatar" aria-hidden="true">' + TYPE_TO_ICON[type] + '</div><div><span class="directory-role">' + esc(t(type)) + '</span><h3>' + esc(row.name) + '</h3><p>' + esc(row.district_name || '') + (row.address ? ' · ' + esc(row.address) : '') + '</p></div></div>'
            + '<div class="directory-detail-section"><span class="directory-label">' + esc(t('crops')) + '</span>'
            + (crops.length
                ? '<div class="directory-crop-list">' + crops.map(c => '<span>' + esc(c) + '</span>').join('') + '</div>'
                : '<p class="directory-contact-value directory-muted">' + esc(t('noCrops')) + '</p>')
            + '</div>'
            + (type === 'farmer' && row.village ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('village')) + '</span><p class="directory-contact-value">' + esc(row.village) + '</p></div>' : '')
            + (type === 'farmer' && registered ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('registered')) + '</span><p class="directory-contact-value">' + esc(registered) + '</p></div>' : '')
            + (row.phone_number ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('phone')) + '</span><p class="directory-contact-value">' + esc(row.phone_number) + '</p></div>' : '')
            + (waIsDistinct ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('whatsapp')) + '</span><p class="directory-contact-value">' + esc(row.whatsapp_number) + '</p></div>' : '')
            + (row.email ? '<div class="directory-detail-section"><span class="directory-label">' + esc(t('email')) + '</span><p class="directory-contact-value">' + esc(row.email) + '</p></div>' : '')
            + (type === 'farmer' ? '<p class="directory-no-contact">' + esc(t('farmerPrivacy')) + '</p>' : '')
            + (hasContact(type) && !row.phone_number && !row.whatsapp_number && !row.email ? '<p class="directory-no-contact">' + esc(t('noContact')) + '</p>' : '')
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

    // Card subtitle: where they are. A seller/buyer carries a contact address,
    // a farmer carries a village.
    function placeLine(row, type) {
        const parts = [row.district_name || ''];
        const detail = type === 'farmer' ? row.village : row.address;
        if (detail) parts.push(detail);
        return parts.filter(Boolean).join(' · ');
    }

    /* The crops strip on a card.
     *
     * It is always rendered, including when there are none. "What do they
     * deal in" is the question the directory exists to answer, so a card that
     * simply omits the row leaves the reader unable to tell whether the
     * listing has no crops or whether the page forgot to show them. */
    function cropStrip(row) {
        const crops = cropsOf(row);
        const shown = crops.slice(0, 3);
        const rest = crops.length - shown.length;
        const chips = shown.map(c => '<em>' + esc(c) + '</em>').join('')
            + (rest > 0 ? '<em class="directory-crop-more">' + esc(t('moreCrops', { n: rest })) + '</em>' : '');
        return '<span class="directory-crops"><span class="directory-crops-label">' + esc(t('crops')) + '</span>'
            + (crops.length ? chips : '<em class="directory-crop-none">' + esc(t('noCrops')) + '</em>')
            + '</span>';
    }

    async function render(type, rows, district, crop) {
        const area = document.getElementById('content-area');
        if (!area) return;
        const districts = new Map();
        rows.forEach(r => { if (r.district_id && r.district_name) districts.set(String(r.district_id), r.district_name); });
        const districtOptions = [...districts.entries()].sort((a,b) => a[1].localeCompare(b[1])).map(([id,name]) => '<option value="' + esc(id) + '"' + (String(id) === String(district) ? ' selected' : '') + '>' + esc(name) + '</option>').join('');
        // Only crops somebody in this directory actually deals in. Listing all
        // nine crops when six of them match nobody just hands the reader six
        // ways to reach an empty page.
        const cropChoices = cropOptions(rows);
        const cropOpts = cropChoices.map(name => '<option value="' + esc(name) + '"' + (name === crop ? ' selected' : '') + '>' + esc(name) + '</option>').join('');
        const isFarmer = type === 'farmer';
        const label = t(type === 'seller' ? 'sellers' : type === 'buyer' ? 'buyers' : 'farmers');
        const heading = t(type === 'seller' ? 'findSellers' : type === 'buyer' ? 'findBuyers' : 'findFarmers');
        const searchPlaceholder = t(type === 'seller' ? 'searchSellers' : type === 'buyer' ? 'searchBuyers' : 'searchFarmers');
        const countKey = type === 'seller' ? 'showingSellers' : type === 'buyer' ? 'showingBuyers' : 'showingFarmers';
        area.innerHTML = '<section class="directory-page">'
            + '<div class="directory-hero"><div><span class="directory-eyebrow">AgroBusiness Malawi</span>'
            + '<h2>' + esc(heading) + '</h2><p>' + esc(t(isFarmer ? 'farmersIntro' : 'intro')) + '</p></div>'
            + '<div class="directory-total"><strong>' + rows.length + '</strong><span>' + esc(label) + '</span></div></div>'
            + '<div class="directory-controls">'
            + '<label class="directory-search"><span class="material-symbols-rounded" aria-hidden="true">search</span>'
            + '<input id="directory-search" type="search" placeholder="' + esc(searchPlaceholder) + '" autocomplete="off"></label>'
            + '<label class="directory-district"><span>' + esc(t('district')) + '</span>'
            + '<select id="directory-district"><option value="">' + esc(t('allDistricts')) + '</option>' + districtOptions + '</select></label>'
            + '<label class="directory-district directory-crop-filter"><span>' + esc(t('cropFilter')) + '</span>'
            + '<select id="directory-crop"><option value="">' + esc(t('allCrops')) + '</option>' + cropOpts + '</select></label>'
            + '</div><div class="directory-count" id="directory-count"></div>'
            + '<div class="directory-grid" id="directory-grid"></div></section>';
        const search = area.querySelector('#directory-search');
        const select = area.querySelector('#directory-district');
        const cropSelect = area.querySelector('#directory-crop');
        const count = area.querySelector('#directory-count');
        const grid = area.querySelector('#directory-grid');
        function apply() {
            const term = search.value.trim().toLowerCase();
            const selected = select.value;
            const selectedCrop = cropSelect.value;
            const filtered = rows.filter(row => {
                const hay = [row.name,row.district_name,row.village,row.phone_number,row.whatsapp_number,row.email,row.address,row.crops_display].join(' ').toLowerCase();
                // Whole-name match against the crop array, never a substring of
                // the joined string: "Beans" must not select every Soybeans grower.
                const cropOk = !selectedCrop || cropsOf(row).indexOf(selectedCrop) !== -1;
                return (!term || hay.includes(term)) && (!selected || String(row.district_id) === selected) && cropOk;
            });
            count.textContent = t(countKey, { n: filtered.length });
            grid.innerHTML = filtered.length ? filtered.map(row =>
                '<button type="button" class="directory-card" data-id="' + Number(row.id) + '"><span class="directory-card-icon">' + TYPE_TO_ICON[type] + '</span><span class="directory-card-main"><strong>' + esc(row.name) + '</strong><small>' + esc(placeLine(row, type)) + '</small>' + cropStrip(row) + '</span><span class="material-symbols-rounded directory-card-arrow">chevron_right</span></button>'
            ).join('') : '<div class="directory-empty"><span aria-hidden="true">🌱</span><strong>' + esc(t(isFarmer && !rows.length ? 'farmersEmptyTitle' : 'emptyTitle')) + '</strong><p>' + esc(t(isFarmer && !rows.length ? 'farmersEmptyBody' : 'emptyBody')) + '</p></div>';
            grid.querySelectorAll('[data-id]').forEach(card => card.onclick = () => { const row = rows.find(r => Number(r.id) === Number(card.dataset.id)); if (row) openDetail(row, type, true, selected, selectedCrop); });
        }
        // Both filters push the same kind of history entry, so Back peels them
        // off one at a time in the order the reader applied them.
        function pushAndApply() {
            history.pushState({ directory: true, type, district: select.value, crop: cropSelect.value }, '',
                pageUrl(type, { district_id: select.value, crop: cropSelect.value }));
            apply();
        }
        search.oninput = apply;
        select.onchange = pushAndApply;
        cropSelect.onchange = pushAndApply;
        apply();
    }

    async function openDirectory(type, district, crop) {
        try {
            if (window.app && typeof window.app.showScreen === 'function') window.app.showScreen('content');
            const rows = await loadAll(type);
            await render(type, rows, district || '', crop || '');
        } catch (error) {
            console.error('Directory load failed:', error);
            const area = document.getElementById('content-area');
            if (area) area.innerHTML = '<div class="directory-empty"><strong>' + esc(t('loadFailed')) + '</strong><p>' + esc(error.message) + '</p></div>';
        }
    }

    /** The filter state carried in the URL — one reader, so it cannot drift. */
    function urlState() {
        const qs = new URLSearchParams(location.search);
        return {
            id: Number(qs.get(directoryType + '_id') || 0),
            district: qs.get('district_id') || '',
            crop: qs.get('crop') || ''
        };
    }

    function start() {
        const { id, district, crop } = urlState();
        loadAll(directoryType).then(async rows => {
            // Always render the directory first. A shared link like
            // sellers.php?seller_id=7 used to open the modal over an empty page,
            // so closing it left the user staring at nothing.
            await openDirectory(directoryType, district, crop);
            if (id) {
                const row = rows.find(r => Number(r.id) === id);
                if (row) openDetail(row, directoryType, false, district, crop);
            }
        }).catch(error => {
            console.error('Directory start failed:', error);
            openDirectory(directoryType, district, crop);
        });
    }

    window.addEventListener('popstate', function () {
        const { id, district, crop } = urlState();
        if (id) loadAll(directoryType).then(async rows => {
            await openDirectory(directoryType, district, crop);
            const row = rows.find(r => Number(r.id) === id);
            if (row) openDetail(row, directoryType, false, district, crop);
        });
        else {
            if (detailModal) detailModal.classList.remove('active');
            openDirectory(directoryType, district, crop);
        }
    });

    // Follow the language switcher. The rows are already cached, so this is a
    // re-render rather than a refetch, and the user keeps their place.
    if (window.AgroLang) {
        window.AgroLang.onChange(function () {
            const { id, district, crop } = urlState();
            if (id) return; // detail view handles itself
            openDirectory(directoryType, district, crop);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
