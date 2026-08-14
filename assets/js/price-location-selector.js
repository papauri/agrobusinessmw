(function () {
    'use strict';

    if ((location.pathname.split('/').pop() || 'index.php') !== 'prices.php') return;

    const LOCATION_URL = 'price-locations.php';
    const SUBMIT_URL = 'price-submit.php';

    const esc = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function findForm() {
        return Array.from(document.querySelectorAll('#content-area form, form')).find(form => {
            const text = (form.textContent || '').toLowerCase();
            return text.includes('price') || form.querySelector('input[type="number"]');
        });
    }

    function findControl(form, patterns, selector) {
        const controls = Array.from(form.querySelectorAll(selector));
        return controls.find(el => patterns.some(p => p.test((el.name || '') + ' ' + (el.id || '') + ' ' + (el.placeholder || '')))) || null;
    }

    function optionMarkup(items, placeholder) {
        return '<option value="">' + esc(placeholder) + '</option>' + items.map(item =>
            '<option value="' + item.id + '" data-district-id="' + item.district_id + '">' + esc(item.name) + '</option>'
        ).join('');
    }

    function renderLocationFields(form, data) {
        if (form.querySelector('[data-price-location-selector]')) return;

        const oldDistrict = findControl(form, [/district/i], 'select,input');
        const email = findControl(form, [/email/i], 'input[type="email"],input');
        if (email) {
            email.required = false;
            email.removeAttribute('required');
            const label = form.querySelector('label[for="' + CSS.escape(email.id || '') + '"]');
            if (label) label.innerHTML = label.textContent.replace(/\s*\*\s*$/, '') + ' <span class="optional-label">(optional)</span>';
        }

        if (oldDistrict) {
            oldDistrict.dataset.priceLegacyLocation = '1';
            oldDistrict.closest('.form-group, .form-field, .field, .input-group')?.classList.add('price-legacy-location-hidden');
            if (!oldDistrict.closest('.form-group, .form-field, .field, .input-group')) oldDistrict.style.display = 'none';
        }

        const wrap = document.createElement('div');
        wrap.className = 'price-location-selector';
        wrap.dataset.priceLocationSelector = '1';
        wrap.innerHTML = `
            <div class="price-location-heading">
                <div>
                    <span class="price-location-kicker">WHERE DID YOU SEE IT?</span>
                    <h3>Market and area</h3>
                    <p>Choose either, both, or neither. These details are optional.</p>
                </div>
                <span class="price-location-optional">Optional</span>
            </div>
            <div class="price-location-grid">
                <div class="price-location-column">
                    <label for="price-market-select">Market <span>(optional)</span></label>
                    <select id="price-market-select" name="market_id">
                        ${optionMarkup(data.markets, 'Select a market')}
                    </select>
                    <small>Example: Area 2 Market, Ndirande Market, ADMARC markets.</small>
                </div>
                <div class="price-location-column">
                    <label for="price-area-select">Area <span>(optional)</span></label>
                    <select id="price-area-select" name="area_id">
                        ${optionMarkup(data.areas, 'Select an area')}
                    </select>
                    <small>Example: Area 49, Soche, Chibavi, Zomba Central.</small>
                </div>
            </div>
            <input type="hidden" name="district_id" id="price-location-district" value="">
            <div class="price-location-note">No market or area? That is fine — you can still submit the price.</div>
        `;

        const firstField = form.querySelector('select, input[type="number"], input[type="text"]');
        if (firstField) firstField.closest('.form-group, .form-field, .field, .input-group')?.after(wrap) || form.prepend(wrap);
        else form.prepend(wrap);

        const market = wrap.querySelector('#price-market-select');
        const area = wrap.querySelector('#price-area-select');
        const district = wrap.querySelector('#price-location-district');

        function applyFilter(source, target, targetItems) {
            const selected = source.value ? Number(source.selectedOptions[0]?.dataset.districtId || 0) : 0;
            const current = target.value;
            target.innerHTML = optionMarkup(selected ? targetItems.filter(x => Number(x.district_id) === selected) : targetItems, target === market ? 'Select a market' : 'Select an area');
            if (current && Array.from(target.options).some(o => o.value === current)) target.value = current;
        }
        function syncDistrict() {
            const md = Number(market.selectedOptions[0]?.dataset.districtId || 0);
            const ad = Number(area.selectedOptions[0]?.dataset.districtId || 0);
            district.value = md || ad || (oldDistrict && oldDistrict.value) || '';
            if (md) applyFilter(market, area, data.areas);
            else if (ad) applyFilter(area, market, data.markets);
        }
        market.addEventListener('change', syncDistrict);
        area.addEventListener('change', syncDistrict);
    }

    function prepareSubmit(form) {
        if (form.dataset.priceSubmitBound === '1') return;
        form.dataset.priceSubmitBound = '1';
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();

            const data = new FormData(form);
            const email = data.get('email');
            if (email === null) data.set('email', '');
            const market = data.get('market_id');
            const area = data.get('area_id');
            data.set('market_id', market || '0');
            data.set('area_id', area || '0');

            const button = form.querySelector('button[type="submit"], input[type="submit"]');
            const original = button ? (button.textContent || button.value) : '';
            if (button) { button.disabled = true; if ('value' in button) button.value = 'Submitting…'; else button.textContent = 'Submitting…'; }

            try {
                const response = await fetch(SUBMIT_URL, { method: 'POST', body: data, headers: { 'Accept': 'application/json' } });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error || 'Submission failed.');
                form.reset();
                const district = form.querySelector('#price-location-district'); if (district) district.value = '';
                alert(result.message || 'Your price has been submitted for review.');
            } catch (error) {
                alert(error.message || 'We could not submit the price. Please try again.');
            } finally {
                if (button) { button.disabled = false; if ('value' in button) button.value = original; else button.textContent = original; }
            }
        }, true);
    }

    async function boot() {
        try {
            const response = await fetch(LOCATION_URL, { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            if (!data.success) return;
            const form = findForm();
            if (!form) return;
            renderLocationFields(form, data);
            prepareSubmit(form);
        } catch (error) {
            console.warn('Price location catalogue unavailable:', error);
        }
    }

    function observe() {
        boot();
        const area = document.getElementById('content-area');
        if (!area) return;
        const observer = new MutationObserver(() => boot());
        observer.observe(area, { childList: true, subtree: true });
        setTimeout(boot, 500);
        setTimeout(boot, 1500);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observe, { once: true });
    else observe();
})();
