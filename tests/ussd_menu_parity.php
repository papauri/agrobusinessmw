<?php
/**
 * en/ci parity for the USSD menu strings.
 *
 *   php tests/ussd_menu_parity.php        # no database, no network
 *
 * tests/i18n_parity.py covers the five web translation tables. `ussd/menus.php`
 * is the sixth and was not covered by anything: a USSD string added in English
 * only would ship, and a caller who chose Chichewa would get a blank menu —
 * `$menu_texts[...][$language]` on a missing key is an empty string, not an
 * error, so it fails silently on a feature phone where nobody can report it.
 *
 * The file is data, so this walks the real array rather than parsing text:
 * every node that carries an 'en' must carry a 'ci', and vice versa.
 */

require __DIR__ . '/../ussd/menus.php';   // defines $menu_texts

$problems = [];
$checked  = 0;

/**
 * Walk the tree. A node is a "string node" when it has an 'en' or a 'ci' key;
 * anything else is a container and is recursed into.
 */
function walk($node, string $path, array &$problems, int &$checked): void
{
    if (!is_array($node)) return;

    $hasEn = array_key_exists('en', $node);
    $hasCi = array_key_exists('ci', $node);

    if ($hasEn || $hasCi) {
        $checked++;
        if (!$hasCi) $problems[] = "$path: has 'en', no 'ci'";
        if (!$hasEn) $problems[] = "$path: has 'ci', no 'en'";

        // Paginated menus hold en/ci => [1 => …, 2 => …]. The page numbers must
        // match too, or page 3 exists in English and not in Chichewa.
        if ($hasEn && $hasCi && is_array($node['en']) && is_array($node['ci'])) {
            $onlyEn = array_diff(array_keys($node['en']), array_keys($node['ci']));
            $onlyCi = array_diff(array_keys($node['ci']), array_keys($node['en']));
            foreach ($onlyEn as $k) $problems[] = "$path: page '$k' in en, not in ci";
            foreach ($onlyCi as $k) $problems[] = "$path: page '$k' in ci, not in en";
        }
        return;
    }

    foreach ($node as $key => $child) {
        walk($child, $path === '' ? (string)$key : "$path.$key", $problems, $checked);
    }
}

walk($menu_texts, '', $problems, $checked);

if (!$checked) {
    fwrite(STDERR, "ussd_menu_parity: parsed zero string nodes — has \$menu_texts moved?\n");
    exit(1);
}

foreach ($problems as $p) echo "  FAIL  $p\n";
printf("ussd_menu_parity: %d string node(s), %d gap(s)\n", $checked, count($problems));
exit($problems ? 1 : 0);
