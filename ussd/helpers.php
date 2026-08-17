<?php
// === Helper Functions ===
// Reusable functions for database queries and error handling

function get_fews_ussd_prices($mysqli, $language) {
    $cacheFile = dirname(__DIR__) . '/config/fews_prices_cache.json';
    $ttl = 6 * 3600;
    $cached = null;

    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
    }

    if (!$cached || !isset($cached['fews']) || (time() - (int)($cached['fetched_at'] ?? 0)) >= $ttl) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $json = null;
        if ($host) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/../api.php?action=dual_crop_prices';
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'AgroBusiness-Malawi-USSD/1.0']]);
            $raw = @file_get_contents($url, false, $ctx);
            $json = $raw ? json_decode($raw, true) : null;
        }
        if (is_array($json) && (!empty($json['fews']) || !empty($json['community']))) {
            $cached = ['fews' => $json['fews'] ?? [], 'community' => $json['community'] ?? [], 'fetched_at' => time()];
            @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
        }
    }

    $fewsRows = is_array($cached) ? ($cached['fews'] ?? $cached['data'] ?? []) : [];
    $communityRows = is_array($cached) ? ($cached['community'] ?? []) : [];
    if (!$fewsRows && !$communityRows) return '';

    $cropMap = [];
    $r = $mysqli->query("SELECT id, name FROM crops");
    while ($row = $r->fetch_assoc()) {
        $cropMap[] = ['name' => $row['name'], 'match' => strtolower($row['name'])];
    }

    $aliases = [
        'maize' => ['maize', 'maize grain'],
        'rice' => ['rice', 'rice milled'],
        'beans' => ['beans', 'bean', 'cowpeas', 'cowpea'],
        'groundnuts' => ['groundnut', 'groundnuts', 'peanut'],
        'soybeans' => ['soybean', 'soybeans', 'soya'],
    ];

    $fewsLines = [];
    $seen = [];
    foreach ($fewsRows as $row) {
        $product = strtolower($row['product'] ?? $row['crop_name'] ?? '');
        $matched = null;
        foreach ($cropMap as $crop) {
            foreach ($aliases[$crop['match']] ?? [$crop['match']] as $term) {
                if (strpos($product, $term) !== false) {
                    $matched = $crop['name'];
                    break 2;
                }
            }
        }
        if (!$matched || isset($seen[$matched])) continue;
        $seen[$matched] = true;

        $price = $row['value'] ?? $row['price'] ?? null;
        if ($price === null) continue;
        $market = $row['market'] ?? $row['market_name'] ?? '';
        $unit = $row['unit'] ?? 'kg';
        $fewsLines[] = $matched . ': MWK' . round((float)$price) . '/' . $unit . ($market ? ' - ' . $market : '');
        if (count($fewsLines) >= 5) break;
    }

    $communityLines = [];
    foreach ($communityRows as $row) {
        $crop = $row['crop_name'] ?? '';
        $avg = $row['avg_price'] ?? null;
        if (!$crop || $avg === null) continue;
        $market = $row['market_name'] ?? $row['district_name'] ?? '';
        $unit = $row['unit'] ?? 'kg';
        $reports = (int)($row['report_count'] ?? 0);
        $communityLines[] = $crop . ': Avg MWK' . round((float)$avg) . '/' . $unit . ($market ? ' - ' . $market : '') . ($reports ? ' (' . $reports . ' reports)' : '');
        if (count($communityLines) >= 5) break;
    }

    $sections = [];
    if ($fewsLines) $sections[] = "AgroBiz reference rates:\n" . implode("\n", $fewsLines);
    if ($communityLines) $sections[] = "Community prices from farmers/traders:\n" . implode("\n", $communityLines);

    return $sections ? implode("\n\n", $sections) : '';
}

// A standard Malawi produce bag is 50 kg. Used to derive a per-bag price
// from a per-kg price for USSD display.
if (!defined('USSD_BAG_KG')) define('USSD_BAG_KG', 50);

function ussd_is_kg(?string $unit): bool {
    return in_array(strtolower(trim((string)$unit)), ['kg', 'kilogram', 'kilo', ''], true);
}

function ussd_bag_price($per_kg): string {
    return 'MWK' . number_format((float)$per_kg * USSD_BAG_KG);
}

// mysqlnd-free fetch: works without get_result() / mysqlnd driver
function ussd_fetch_all(mysqli_stmt $stmt): array {
    $meta = $stmt->result_metadata();
    if (!$meta) return [];
    $fields = []; $row = [];
    while ($f = $meta->fetch_field()) { $row[$f->name] = null; $fields[] = &$row[$f->name]; }
    call_user_func_array([$stmt, 'bind_result'], $fields);
    $rows = [];
    while ($stmt->fetch()) $rows[] = array_map(fn($v) => $v, $row);
    $meta->free(); $stmt->free_result(); $stmt->close();
    return $rows;
}

// ─── Directory (Find Sellers / Find Buyers) ──────────────────────────────────
//
// These replaced two inline queries in logic.php that had drifted away from
// api.php's version of the same listing.
//
//   1. A NULL phone number rendered as nothing at all. The old line was
//      "{name}: {phone_number}", and `seller_contact_details.phone_number` is
//      nullable — so the caller got "Chikondi Nkhoma: " and a blank where the
//      number should be, with no way to tell a missing number from a display
//      bug. Every one of the 14 rows in production has a NULL phone today, so
//      this was not a corner case there; it was the whole page.
//   2. They listed no crops at all, so the one thing a caller needs in order to
//      choose who to ring was the one thing they could not see. That is only
//      fixable now: nothing wrote seller_crops / buyer_crops until
//      admin_link_applicant_crops() was added on 2026-08-17.
//
// The joins onto the contact tables were also changed from INNER to LEFT, to
// match api.php. That one is defence in depth, NOT a bug fix, and the
// distinction is worth keeping straight: `sellers.contact_id` is `int NOT NULL`
// with an `ON DELETE RESTRICT` foreign key (p601229_AgroBusiness_MW.sql:717,
// :1260), so a listing with no contact row cannot exist and the inner join
// could not have dropped one. It costs nothing and it stops the two channels
// answering differently if that constraint is ever relaxed.

/**
 * Bytes available for the body of one USSD page.
 *
 * Africa's Talking caps a CON response at 182 bytes and truncates anything
 * longer — mid-word, mid-phone-number, without warning. "CON " and the trailing
 * back menu are spent before a single listing is written, and the Chichewa back
 * menu is 11 bytes longer than the English one, so the budget is derived from
 * the actual suffix rather than assumed.
 */
function ussd_page_budget(string $suffix): int {
    return max(40, 182 - strlen('CON ') - strlen($suffix));
}

/**
 * Fit whole lines into a byte budget, then say how many did not fit.
 *
 * Never returns a partial line: a truncated phone number is worse than an
 * absent one. `$more_template` carries a `{n}` placeholder and is measured at
 * its worst case (every line dropped), so the result cannot exceed the budget
 * however the count falls.
 */
function ussd_fit_lines(array $lines, int $budget, string $more_template): string {
    if (!$lines) return '';
    $worst_note = count($lines) > 1
        ? strlen("\n" . str_replace('{n}', (string)count($lines), $more_template))
        : 0;

    $kept = 0;
    $used = 0;
    foreach ($lines as $i => $line) {
        $cost = strlen($line) + ($kept > 0 ? 1 : 0);   // +1 for the joining newline
        $is_last = ($i === count($lines) - 1);
        $reserve = $is_last ? 0 : $worst_note;
        if ($used + $cost + $reserve > $budget) break;
        $used += $cost;
        $kept++;
    }

    if ($kept === 0) $kept = 1;   // one over-long line beats an empty page
    $dropped = count($lines) - $kept;
    $body = implode("\n", array_slice($lines, 0, $kept));
    return $dropped > 0
        ? $body . "\n" . str_replace('{n}', (string)$dropped, $more_template)
        : $body;
}

/**
 * One district's sellers or buyers, as formatted USSD lines.
 *
 * `$type` is 'seller' or 'buyer' and never reaches the SQL — both branches are
 * full literals, so no value chosen by a caller can shape the statement.
 *
 * The average rating is a scalar subquery rather than a joined AVG on purpose:
 * `ratings` and `seller_crops` joined into the same row set fan out against each
 * other, and an aggregate computed over that product is a bug waiting for the
 * first seller who has both several ratings and several crops.
 */
function ussd_directory_lines(mysqli $mysqli, string $type, int $district_id, array $labels): array {
    $sellers = ($type === 'seller');

    $sql = $sellers
        ? "SELECT s.name,
                  scd.phone_number,
                  (SELECT AVG(r.rating_value) FROM ratings r WHERE r.seller_id = s.id) AS avg_rating,
                  GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '\n') AS crops_concat
           FROM sellers s
           LEFT JOIN seller_contact_details scd ON s.contact_id = scd.id
           LEFT JOIN seller_crops sc ON s.id = sc.seller_id
           LEFT JOIN crops c ON sc.crop_id = c.id
           WHERE s.district_id = ?
           GROUP BY s.id, s.name, scd.phone_number
           ORDER BY s.name"
        : "SELECT b.name,
                  bcd.phone_number,
                  NULL AS avg_rating,
                  GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '\n') AS crops_concat
           FROM buyers b
           LEFT JOIN buyer_contact_details bcd ON b.contact_id = bcd.id
           LEFT JOIN buyer_crops bc ON b.id = bc.buyer_id
           LEFT JOIN crops c ON bc.crop_id = c.id
           WHERE b.district_id = ?
           GROUP BY b.id, b.name, bcd.phone_number
           ORDER BY b.name";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { error_log('USSD directory prepare failed: ' . $mysqli->error); return []; }
    $stmt->bind_param('i', $district_id);
    if (!$stmt->execute()) { error_log('USSD directory execute failed'); return []; }
    $rows = ussd_fetch_all($stmt);

    $lines = [];
    foreach ($rows as $row) {
        // Newline separator, matching api.php: a crop name may one day contain a
        // comma, and it will never contain a newline.
        $crops = array_values(array_filter(array_map('trim', explode("\n", (string)$row['crops_concat']))));
        if ($crops) {
            $shown = array_slice($crops, 0, 2);
            $crop_text = implode(',', $shown);
            if (count($crops) > count($shown)) $crop_text .= '+' . (count($crops) - count($shown));
        } else {
            $crop_text = $labels['no_crops'];
        }

        // Spaces stripped from the number: legacy rows hold '+265 881 123 456'
        // and every byte counts here. It stays dialable either way.
        $phone = preg_replace('/\s+/', '', (string)$row['phone_number']);
        if ($phone === '') $phone = $labels['no_number'];

        $rating = ($row['avg_rating'] !== null && $row['avg_rating'] !== '')
            ? ' ' . number_format((float)$row['avg_rating'], 1) . '*'
            : '';

        $lines[] = "{$row['name']}: {$phone}{$rating} | {$crop_text}";
    }
    return $lines;
}

function execute_query($mysqli, $query, $params = [], $types = '', $format_callback) {
    if (empty($params)) {
        $result = $mysqli->query($query);
        if ($result && $result->num_rows > 0) {
            $output = '';
            while ($row = $result->fetch_assoc()) { $output .= $format_callback($row); }
            $result->free();
            return rtrim($output);
        }
        return false;
    }
    $stmt = $mysqli->prepare($query);
    if (!$stmt) { error_log('Prepare failed: ' . $mysqli->error); return false; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = ussd_fetch_all($stmt);
    if (!$rows) return false;
    $output = '';
    foreach ($rows as $row) { $output .= $format_callback($row); }
    return rtrim($output) ?: false;
}

// Crop Prices: all crops in one district (community first, national fallback)
function get_prices_by_district(mysqli $mysqli, int $district_id, string $lang): string {
    $stmt = $mysqli->prepare(
        "SELECT c.name AS crop_name, ROUND(AVG(cp.price_per_kg),0) AS avg_price, cp.unit
         FROM crowdsourced_prices cp JOIN crops c ON cp.crop_id = c.id
         WHERE cp.district_id = ?
         GROUP BY cp.crop_id, cp.unit ORDER BY c.name"
    );
    if ($stmt) {
        $stmt->bind_param('i', $district_id);
        $stmt->execute();
        $rows = ussd_fetch_all($stmt);
        if ($rows) {
            return implode("\n", array_map(function ($r) {
                $line = "{$r['crop_name']}: MWK{$r['avg_price']}/{$r['unit']}";
                if (ussd_is_kg($r['unit'])) $line .= "\n  50kg bag: " . ussd_bag_price($r['avg_price']);
                return $line;
            }, $rows));
        }
    }
    // National reference fallback
    $r = $mysqli->query("SELECT c.name, cp.min_price, cp.market_price, cp.unit FROM crop_prices cp JOIN crops c ON cp.crop_id = c.id ORDER BY c.name");
    if (!$r) return '';
    $lines = [];
    while ($row = $r->fetch_assoc()) {
        $line = "{$row['name']}: MWK{$row['min_price']}-{$row['market_price']}/{$row['unit']}";
        if (ussd_is_kg($row['unit'])) {
            $line .= "\n  50kg bag: " . ussd_bag_price($row['min_price']) . '-' . ussd_bag_price($row['market_price']);
        }
        $lines[] = $line;
    }
    return $lines ? "(National ref)\n" . implode("\n", $lines) : '';
}

// Crop Prices: one crop in one district (community first, national fallback)
function get_prices_by_crop_district(mysqli $mysqli, int $crop_id, int $district_id, string $lang): string {
    $stmt = $mysqli->prepare(
        "SELECT c.name AS crop_name, d.name AS district_name,
                ROUND(AVG(cp.price_per_kg),0) AS avg_price,
                MIN(cp.price_per_kg) AS min_price, MAX(cp.price_per_kg) AS max_price,
                cp.unit, COUNT(*) AS reports
         FROM crowdsourced_prices cp
         JOIN crops c ON cp.crop_id = c.id JOIN districts d ON cp.district_id = d.id
         WHERE cp.crop_id = ? AND cp.district_id = ?
         GROUP BY cp.unit"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $crop_id, $district_id);
        $stmt->execute();
        $rows = ussd_fetch_all($stmt);
        if ($rows) {
            $r = $rows[0];
            $out = "{$r['crop_name']} - {$r['district_name']}:\n"
                 . "Avg: MWK{$r['avg_price']}/{$r['unit']}\n";
            if (ussd_is_kg($r['unit'])) $out .= "50kg bag: " . ussd_bag_price($r['avg_price']) . "\n";
            $out .= "Range: MWK{$r['min_price']}-{$r['max_price']}\n"
                 . "({$r['reports']} farmer reports)";
            return $out;
        }
    }
    // National reference fallback
    $stmt2 = $mysqli->prepare(
        "SELECT c.name, cp.min_price, cp.market_price, cp.unit FROM crop_prices cp JOIN crops c ON cp.crop_id = c.id WHERE cp.crop_id = ?"
    );
    if ($stmt2) {
        $stmt2->bind_param('i', $crop_id);
        $stmt2->execute();
        $rows2 = ussd_fetch_all($stmt2);
        if ($rows2) {
            $r = $rows2[0];
            $out = "{$r['name']} (National ref):\nMin: MWK{$r['min_price']}/{$r['unit']}\nMkt: MWK{$r['market_price']}/{$r['unit']}";
            if (ussd_is_kg($r['unit'])) $out .= "\n50kg bag: " . ussd_bag_price($r['min_price']) . '-' . ussd_bag_price($r['market_price']);
            return $out;
        }
    }
    return '';
}

function get_error($menu_texts, $type, $language) {
    return $menu_texts['errors'][$type][$language];
}
?>