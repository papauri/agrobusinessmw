<?php
/**
 * Contract tests for config/phone.php.
 *
 * The database stores E.164 only. These cases are the contract: what the app
 * accepts from a farmer typing on a phone, and — just as important — what it
 * refuses to guess. Run with:
 *
 *   php tests/phone_test.php
 *
 * assets/js/phone-normalizer.js must agree with every line of this file.
 * tests/phone_test.mjs runs the same table through the browser implementation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/phone.php';

/** [input, expected] — null means "must be rejected". */
$cases = [
    // ── Malawi national format, the common case ──────────────────────────
    ['0888123456',           '+265888123456'],
    ['0888 123 456',         '+265888123456'],
    ['0888-123-456',         '+265888123456'],
    ['(0888) 123 456',       '+265888123456'],
    ['0888.123.456',         '+265888123456'],
    [' 0888 123 456 ',       '+265888123456'],
    ['0991234567',           '+265991234567'],
    ['01234567',             '+2651234567'],     // fixed line

    // ── Malawi without the trunk zero ────────────────────────────────────
    ['888123456',            '+265888123456'],
    ['991234567',            '+265991234567'],

    // ── Malawi country code, with and without the plus ───────────────────
    ['265888123456',         '+265888123456'],
    ['+265888123456',        '+265888123456'],
    ['+265 888 123 456',     '+265888123456'],
    ['+265-888-123-456',     '+265888123456'],
    ['00265888123456',       '+265888123456'],
    ['++265888123456',       '+265888123456'],

    // ── Explicitly international: the country code is trusted ────────────
    ['+447700900123',        '+447700900123'],
    ['+44 7700 900123',      '+447700900123'],
    ['00447700900123',       '+447700900123'],
    ['+1 202 555 0143',      '+12025550143'],
    ['+91 98765 43210',      '+919876543210'],
    ['+27821234567',         '+27821234567'],

    // ── Rejected: ambiguous. Guessing a country writes a wrong number. ───
    ['712345678',            null],   // 9 digits, not a Malawi mobile prefix
    ['0712345678',           null],   // trunk zero, still not a Malawi prefix
    ['123456789',            null],
    ['12345678',             null],

    // ── Rejected: malformed ──────────────────────────────────────────────
    ['',                     null],
    ['abc',                  null],
    ['12345',                null],
    ['0',                    null],
    ['+',                    null],
    ['++',                   null],
    ['+0123456789',          null],   // country code cannot start with 0
    ['+2658',                null],   // too short
    ['+2658881234567890123', null],   // too long
    ['0888123',              null],   // short for a Malawi mobile
    ['08881234567',          null],   // long for a Malawi mobile

    // ── Rejected: country code AND trunk zero, a very common typo ────────
    ['2650888123456',        null],
    ['+2650888123456',       null],

    // ── Junk characters are stripped, not rejected ───────────────────────
    ['tel:0888123456',       '+265888123456'],
    ['0888123456x',          '+265888123456'],
];

$failures = 0;
foreach ($cases as [$input, $expected]) {
    $actual = agro_normalize_phone($input);
    if ($actual !== $expected) {
        $failures++;
        printf(
            "FAIL  %-24s got %-18s want %s\n",
            var_export($input, true),
            var_export($actual, true),
            var_export($expected, true)
        );
    }
}

$total = count($cases);
if ($failures === 0) {
    echo "phone_test.php: {$total}/{$total} cases pass\n";
    exit(0);
}
echo "phone_test.php: {$failures} of {$total} cases FAILED\n";
exit(1);
