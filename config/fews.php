<?php
// FEWS reference-rate fetch + file cache (rates sourced upstream, presented under the AgroBiz brand).

if (!function_exists('fews_get_prices')) {
    function fews_get_prices($db)
    {
        $cacheFile = __DIR__ . '/fews_prices_cache.json';
        $ttl = 6 * 3600;

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['data']) && (time() - (int)($cached['fetched_at'] ?? 0)) < $ttl) {
                return $cached;
            }
        }

        $fresh = fews_fetch_prices($db);
        $fresh['fetched_at'] = time();
        @file_put_contents($cacheFile, json_encode($fresh), LOCK_EX);
        return $fresh;
    }
}

if (!function_exists('fews_fetch_prices')) {
    function fews_fetch_prices($db)
    {
        $sourceUrl = 'https://fdw.fews.net/api/marketpricefacts/?format=json&country_code=MW&ordering=-period_date&page_size=250';
        $ctx = stream_context_create(['http' => [
            'timeout' => 20,
            'user_agent' => 'AgroBusiness-Malawi/1.0',
        ]]);
        $raw = @file_get_contents($sourceUrl, false, $ctx);
        if (!$raw) {
            return ['data' => [], 'source_url' => $sourceUrl, 'error' => 'Reference rates unavailable. Showing community prices only.'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            return ['data' => [], 'source_url' => $sourceUrl, 'error' => 'Reference rate source returned an unexpected response.'];
        }

        $cropMap = [];
        $r = $db->query("SELECT id, name FROM crops");
        while ($row = $r->fetch_assoc()) {
            $cropMap[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'match' => strtolower($row['name'])];
        }

        $aliases = [
            'maize' => ['maize', 'maize grain'],
            'rice' => ['rice', 'rice milled'],
            'beans' => ['beans', 'bean', 'cowpeas', 'cowpea'],
            'groundnuts' => ['groundnut', 'groundnuts', 'peanut'],
            'cassava' => ['cassava'],
            'sorghum' => ['sorghum'],
            'millet' => ['millet'],
            'soybeans' => ['soybean', 'soybeans', 'soya'],
            'tobacco' => ['tobacco'],
        ];

        $districtMap = fews_district_map($db);

        $rows = [];
        $seen = [];
        foreach ($json['results'] as $item) {
            if (($item['country_code'] ?? '') !== 'MW' || !isset($item['value'])) continue;

            $product = strtolower($item['product'] ?? '');
            $matched = null;
            foreach ($cropMap as $crop) {
                $terms = $aliases[$crop['match']] ?? [$crop['match']];
                foreach ($terms as $term) {
                    if (strpos($product, $term) !== false) {
                        $matched = $crop;
                        break 2;
                    }
                }
            }
            if (!$matched) continue;

            $key = $matched['id'] . '|' . ($item['market'] ?? '') . '|' . ($item['period_date'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $marketName = $item['market'] ?? '';
            $district = fews_match_district($marketName, $districtMap);

            $rows[] = [
                'crop_id' => $matched['id'],
                'crop_name' => $matched['name'],
                'district_id' => $district['id'],
                'district_name' => $district['name'] ?: ($item['admin_1'] ?? ''),
                'market_name' => $marketName,
                'price' => (float)$item['value'],
                'price_type' => $item['price_type'] ?? 'Retail',
                'unit' => $item['unit'] ?? 'kg',
                'currency' => $item['currency'] ?? 'MWK',
                'price_date' => $item['period_date'] ?? null,
                // Origin is presented under the platform's own brand, not the upstream source.
                'source_organization' => 'AgroBiz Reference',
            ];
        }

        return [
            'data' => $rows,
            'source_url' => $sourceUrl,
            'error' => empty($rows) ? 'No reference rates matched local crops.' : null,
        ];
    }
}

if (!function_exists('fews_district_map')) {
    function fews_district_map($db)
    {
        $map = [];
        $r = $db->query("SELECT id, name FROM districts");
        while ($row = $r->fetch_assoc()) {
            $map[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'match' => strtolower($row['name'])];
        }
        return $map;
    }
}

if (!function_exists('fews_match_district')) {
    function fews_match_district($marketName, $districtMap)
    {
        $market = strtolower($marketName);
        foreach ($districtMap as $district) {
            if (strpos($market, $district['match']) !== false) {
                return ['id' => $district['id'], 'name' => $district['name']];
            }
        }
        return ['id' => null, 'name' => ''];
    }
}
