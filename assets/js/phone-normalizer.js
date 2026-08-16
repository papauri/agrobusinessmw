/* AgroBusiness Malawi — canonical phone number normalisation (browser side).
 *
 * The database stores contact numbers in E.164 only, e.g. +265888123456.
 * This file is the browser's single authority for producing that format and it
 * is a deliberate, line-for-line mirror of config/phone.php. If you change a
 * rule here, change it there too — a divergence writes wrong numbers into the
 * database, and a farmer whose number is wrong simply never gets contacted.
 *
 * Accepted input, in the order it is tested:
 *   +265 888 123 456 / +44 7700 900123  explicit international, kept as-is
 *   00265888123456                      international access code, becomes +
 *   265888123456                        Malawi country code without the +
 *   0888 123 456                        Malawi national format (trunk 0)
 *   888123456                           Malawi mobile without the trunk 0
 *
 * Anything else is rejected rather than guessed. A bare nine-digit number that
 * is not a Malawi mobile prefix could belong to any country, so stamping +265
 * on it would be a guess, not a normalisation.
 */
(function () {
    'use strict';

    /* Malawi mobile national numbers: 9 digits beginning 8 or 9. */
    const MW_MOBILE = /^[89][0-9]{8}$/;
    /* Malawi fixed lines: 7-8 digits beginning 1. */
    const MW_FIXED = /^1[0-9]{6,7}$/;
    /* E.164: leading +, non-zero country digit, then 7-14 more digits. */
    const E164 = /^\+[1-9][0-9]{7,14}$/;

    /* Final gate applied to every candidate.
     *
     * Beyond E.164 shape we can only check numbering plans we actually know, and
     * Malawi is the one we know. This catches the very common "265" + "0888123456"
     * typo, where the person types the country code AND keeps the trunk zero: the
     * result is 13 digits and passes E.164 happily, but it is not a real number.
     * Numbers from other countries are checked for E.164 shape only — inventing
     * length rules for plans we have not verified would reject valid numbers. */
    function acceptable(candidate) {
        if (!E164.test(candidate)) return false;
        if (candidate.startsWith('+265')) {
            const national = candidate.slice(4);
            return MW_MOBILE.test(national) || MW_FIXED.test(national);
        }
        return true;
    }

    function normalizePhone(value) {
        let raw = String(value == null ? '' : value).trim();
        if (!raw) return null;

        raw = raw.replace(/[^0-9+]/g, '').replace(/^\++/, '+');
        if (!raw || raw === '+') return null;

        // Explicitly international — trust the country code the user gave us.
        if (raw.charAt(0) === '+') {
            const candidate = '+' + raw.slice(1).replace(/\D/g, '');
            return acceptable(candidate) ? candidate : null;
        }

        // A plus may not survive a form or a copy/paste; 00 means the same thing.
        if (raw.startsWith('00')) {
            const candidate = '+' + raw.slice(2);
            return acceptable(candidate) ? candidate : null;
        }

        // Malawi country code typed without the plus.
        if (raw.startsWith('265')) {
            const candidate = '+' + raw;
            return acceptable(candidate) ? candidate : null;
        }

        // Malawi national format: trunk 0 followed by the national number.
        if (raw.charAt(0) === '0') {
            const national = raw.slice(1);
            if (MW_MOBILE.test(national) || MW_FIXED.test(national)) {
                const candidate = '+265' + national;
                return acceptable(candidate) ? candidate : null;
            }
            return null;
        }

        // Malawi mobile typed without the trunk 0. Restricted to the 8/9 mobile
        // prefixes precisely so this cannot swallow a foreign national number.
        if (MW_MOBILE.test(raw)) {
            const candidate = '+265' + raw;
            return acceptable(candidate) ? candidate : null;
        }

        return null;
    }

    function isE164(value) {
        return typeof value === 'string' && E164.test(value);
    }

    const INVALID_MESSAGE = 'Enter a Malawi number as 0888 123 456, or an international number with its country code, e.g. +44 7700 900123.';

    function fieldIsPhone(field) {
        if (!field || field.disabled) return false;
        const id = (field.id || '').toLowerCase();
        const name = (field.name || '').toLowerCase();
        return field.type === 'tel'
            || /phone|mobile|whatsapp/.test(id)
            || /phone|mobile|whatsapp/.test(name);
    }

    /* Rewrite one field to canonical form. Returns false when it cannot be. */
    function normalizeField(field) {
        if (!fieldIsPhone(field)) return true;
        const value = String(field.value || '').trim();
        if (!value) {
            field.setCustomValidity('');
            return true;
        }
        const normalized = normalizePhone(value);
        if (!normalized) {
            field.setCustomValidity(INVALID_MESSAGE);
            return false;
        }
        field.setCustomValidity('');
        field.value = normalized;
        return true;
    }

    function normalizeFields(root) {
        const scope = root || document;
        if (!scope.querySelectorAll) return true;
        let valid = true;
        scope.querySelectorAll('input, textarea').forEach(function (field) {
            if (fieldIsPhone(field) && !normalizeField(field)) valid = false;
        });
        return valid;
    }

    window.AgroPhone = {
        normalize: normalizePhone,
        isE164: isE164,
        invalidMessage: INVALID_MESSAGE,
        normalizeField: normalizeField,
        normalizeFields: normalizeFields
    };

    // Any form that carries a phone field gets canonicalised on submit. The
    // registration page validates its own fields first and never reaches here
    // with a bad number; this covers the community price report form.
    document.addEventListener('submit', function (event) {
        if (!normalizeFields(event.target)) event.preventDefault();
    }, true);

    // Canonicalise on blur so the user sees the stored form immediately.
    document.addEventListener('blur', function (event) {
        if (!fieldIsPhone(event.target)) return;
        const field = event.target;
        const value = String(field.value || '').trim();
        if (!value) {
            field.setCustomValidity('');
            return;
        }
        const normalized = normalizePhone(value);
        if (normalized) {
            field.value = normalized;
            field.setCustomValidity('');
        } else {
            field.setCustomValidity(INVALID_MESSAGE);
        }
    }, true);
})();
