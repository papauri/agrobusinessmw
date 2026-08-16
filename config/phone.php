<?php
/**
 * AgroBusiness Malawi — canonical phone number normalisation (server side).
 *
 * The database stores contact numbers in one format only: E.164, e.g.
 * +265888123456. This file is the single authority for turning what a person
 * actually types into that format, and it is deliberately conservative.
 *
 * Accepted input, in the order it is tested:
 *   +265 888 123 456 / +44 7700 900123  explicit international, kept as-is
 *   00265888123456                      international access code, becomes +
 *   265888123456                        Malawi country code without the +
 *   0888 123 456                        Malawi national format (trunk 0)
 *   888123456                           Malawi mobile without the trunk 0
 *
 * Anything else is REJECTED rather than guessed. A bare 9-digit number that is
 * not a Malawi mobile prefix could belong to any country on earth; silently
 * stamping +265 on it would write a wrong number into the database and the
 * farmer would never be reached. Callers must show the user an error telling
 * them to include their country code.
 *
 * assets/js/phone-normalizer.js implements exactly these rules in the browser.
 * If you change one, change the other.
 */

if (defined('AGRO_PHONE_LOADED')) return;
define('AGRO_PHONE_LOADED', true);

/** Malawi mobile national numbers are 9 digits beginning 8 or 9 (e.g. 888123456, 991234567). */
const AGRO_MW_MOBILE = '/^[89][0-9]{8}$/';
/** Malawi fixed lines are 8 digits beginning 1 (e.g. 01234567 -> 1234567). */
const AGRO_MW_FIXED  = '/^1[0-9]{6,7}$/';
/** E.164: a leading +, a non-zero country digit, then 7-14 more digits. */
const AGRO_E164      = '/^\+[1-9][0-9]{7,14}$/';

/**
 * Final gate applied to every candidate.
 *
 * Beyond E.164 shape we can only check numbering plans we actually know, and
 * Malawi is the one we know. This catches the very common "265" + "0888123456"
 * typo, where the person types the country code AND keeps the trunk zero: the
 * result is 13 digits and passes E.164 happily, but it is not a real number.
 * Numbers from other countries are checked for E.164 shape only — inventing
 * length rules for plans we have not verified would reject valid numbers.
 */
function agro_phone_acceptable(string $candidate): bool
{
    if (!preg_match(AGRO_E164, $candidate)) return false;

    if (str_starts_with($candidate, '+265')) {
        $national = substr($candidate, 4);
        return (bool)(preg_match(AGRO_MW_MOBILE, $national) || preg_match(AGRO_MW_FIXED, $national));
    }
    return true;
}

/**
 * Normalise a phone number to E.164, or return null if it cannot be normalised
 * without guessing which country it belongs to.
 */
function agro_normalize_phone(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') return null;

    // Keep only digits and a leading plus; people type spaces, dashes, brackets.
    $raw = preg_replace('/[^0-9+]/', '', $raw);
    $raw = preg_replace('/^\++/', '+', (string)$raw);
    if ($raw === '' || $raw === '+') return null;

    // Explicitly international — trust the country code the user gave us.
    if ($raw[0] === '+') {
        $candidate = '+' . preg_replace('/\D/', '', substr($raw, 1));
        return agro_phone_acceptable($candidate) ? $candidate : null;
    }

    // A plus may not survive a form or a copy/paste; 00 means the same thing.
    if (str_starts_with($raw, '00')) {
        $candidate = '+' . substr($raw, 2);
        return agro_phone_acceptable($candidate) ? $candidate : null;
    }

    // Malawi country code typed without the plus (265...).
    if (str_starts_with($raw, '265')) {
        $candidate = '+' . $raw;
        return agro_phone_acceptable($candidate) ? $candidate : null;
    }

    // Malawi national format: a trunk 0 followed by the national number.
    if ($raw[0] === '0') {
        $national = substr($raw, 1);
        if (preg_match(AGRO_MW_MOBILE, $national) || preg_match(AGRO_MW_FIXED, $national)) {
            $candidate = '+265' . $national;
            return agro_phone_acceptable($candidate) ? $candidate : null;
        }
        return null;
    }

    // Malawi mobile typed without the trunk 0. Restricted to the 8/9 mobile
    // prefixes precisely so this cannot swallow a foreign national number.
    if (preg_match(AGRO_MW_MOBILE, $raw)) {
        $candidate = '+265' . $raw;
        return agro_phone_acceptable($candidate) ? $candidate : null;
    }

    return null;
}

/** True when the value is already a canonical E.164 number. */
function agro_is_e164(?string $value): bool
{
    return is_string($value) && $value !== '' && (bool)preg_match(AGRO_E164, $value);
}
