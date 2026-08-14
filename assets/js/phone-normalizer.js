/* AgroBusiness Malawi - user-friendly phone input normalization.
 * Database: canonical international numbers only.
 * User input: common Malawi local formats and international formats.
 */
(function () {
    'use strict';

    const MALAWI = '265';

    function normalizePhone(value, defaultCountryCode = MALAWI) {
        let raw = String(value == null ? '' : value).trim().replace(/[^0-9+]/g, '');
        if (!raw) return '';
        raw = raw.replace(/^\++/, '+');

        // Already international.
        if (raw.charAt(0) === '+') {
            const international = '+' + raw.slice(1).replace(/\D/g, '');
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        raw = raw.replace(/\D/g, '');

        // Country code supplied without '+'.
        if (raw.indexOf(defaultCountryCode) === 0 && raw.length >= 10) {
            const international = '+' + raw;
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        // Malawi local mobile: 0888123456 / 0971234567 -> +265888123456 / +265971234567.
        if (defaultCountryCode === MALAWI && /^0[0-9]{9}$/.test(raw)) {
            const international = '+265' + raw.slice(1);
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        // Malawi local without leading zero: 888123456 / 971234567.
        if (defaultCountryCode === MALAWI && /^[89][0-9]{8}$/.test(raw)) {
            const international = '+265' + raw;
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        return null;
    }

    function fieldIsPhone(field) {
        if (!field || field.disabled) return false;
        const id = (field.id || '').toLowerCase();
        const name = (field.name || '').toLowerCase();
        const autocomplete = (field.autocomplete || '').toLowerCase();
        return field.type === 'tel'
            || /phone|mobile|whatsapp/.test(id)
            || /phone|mobile|whatsapp/.test(name)
            || autocomplete === 'tel';
    }

    function normalizeField(field) {
        if (!fieldIsPhone(field)) return true;
        const value = String(field.value || '').trim();
        if (!value) return true;
        const normalized = normalizePhone(value);
        if (!normalized) {
            field.setCustomValidity('Enter a valid phone number, e.g. 0888 123 456 or +265 888 123 456.');
            return false;
        }
        field.setCustomValidity('');
        field.value = normalized;
        return true;
    }

    function normalizePhoneFields(root) {
        const scope = root || document;
        const fields = scope.querySelectorAll ? scope.querySelectorAll('input, textarea') : [];
        let valid = true;
        fields.forEach(function (field) {
            if (fieldIsPhone(field) && !normalizeField(field)) valid = false;
        });
        return valid;
    }

    window.AgroPhone = {
        normalize: normalizePhone,
        normalizeField: normalizeField,
        normalizeFields: normalizePhoneFields
    };

    // Capture phase runs before the application's existing click handlers.
    // This catches both the registration Next button and final Submit button.
    document.addEventListener('click', function (event) {
        const target = event.target.closest ? event.target.closest('#reg-step2-next, #reg-submit-btn, button[type="submit"], input[type="submit"]') : null;
        if (!target) return;
        normalizePhoneFields(document.getElementById('register-modal') || document);
    }, true);

    document.addEventListener('submit', function (event) {
        if (!normalizePhoneFields(event.target)) event.preventDefault();
    }, true);

    document.addEventListener('blur', function (event) {
        if (!fieldIsPhone(event.target)) return;
        const field = event.target;
        const value = String(field.value || '').trim();
        if (!value) {
            field.setCustomValidity('');
            return;
        }
        const normalized = normalizePhone(value);
        field.setCustomValidity(normalized ? '' : 'Enter a valid phone number, e.g. 0888 123 456 or +265 888 123 456.');
    }, true);
})();
