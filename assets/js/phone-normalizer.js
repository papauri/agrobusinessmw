/* AgroBusiness Malawi - user-friendly phone input normalization.
 *
 * Database contract: store canonical international numbers only.
 * User contract: accept common Malawi local forms and international forms.
 *
 * Examples:
 *   0888123456    -> +265888123456
 *   888123456     -> +265888123456
 *   0971234567    -> +265971234567
 *   +265888123456 -> +265888123456
 *   +447700900123 -> +447700900123
 */
(function () {
    'use strict';

    const MALAWI = '265';

    function digits(value) {
        return String(value == null ? '' : value).replace(/[^0-9+]/g, '');
    }

    function normalizePhone(value, defaultCountryCode = MALAWI) {
        let raw = digits(value).replace(/^\++/, '+');
        if (!raw) return '';

        // Already international: preserve the + and remove formatting.
        if (raw.charAt(0) === '+') {
            const international = '+' + raw.slice(1).replace(/\D/g, '');
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        raw = raw.replace(/\D/g, '');

        // Allow a country-code form without '+', e.g. 265888123456.
        if (raw.indexOf(defaultCountryCode) === 0 && raw.length >= 10) {
            const international = '+' + raw;
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        // Malawi local mobile form: 0XXXXXXXXX -> +265XXXXXXXXX.
        if (defaultCountryCode === MALAWI && /^0[0-9]{9}$/.test(raw)) {
            const international = '+265' + raw.slice(1);
            return /^\+[1-9][0-9]{7,14}$/.test(international) ? international : null;
        }

        // Malawi local form without the leading zero: 8XXXXXXXX / 9XXXXXXXX.
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
        if (!value) return true; // Optional remains optional.

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
        const fields = (root || document).querySelectorAll
            ? (root || document).querySelectorAll('input, textarea')
            : [];
        let valid = true;
        fields.forEach(function (field) {
            if (fieldIsPhone(field) && !normalizeField(field)) valid = false;
        });
        return valid;
    }

    // Expose the same normalization function to application code and future forms.
    window.AgroPhone = {
        normalize: normalizePhone,
        normalizeField: normalizeField,
        normalizeFields: normalizePhoneFields
    };

    // Normalize before browser validation/submission and before existing bubble listeners.
    document.addEventListener('click', function (event) {
        const submitter = event.target.closest
            ? event.target.closest('button[type="submit"], input[type="submit"], #reg-submit-btn')
            : null;
        if (!submitter) return;
        normalizePhoneFields(submitter.form || document);
    }, true);

    document.addEventListener('submit', function (event) {
        if (!normalizePhoneFields(event.target)) event.preventDefault();
    }, true);

    // Give users immediate feedback when leaving a phone field, while retaining their
    // familiar local format until it is validated/submitted.
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
