<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
/**
 * Pull a model year into vehicle_catalog from NHTSA vPIC.
 *
 *   php data/refresh_vehicles.php 2027
 *   php data/refresh_vehicles.php 2027 2028
 *
 * WHY THIS EXISTS. The bundled data/vehicle-models.csv covers 1992–2026 and is
 * frozen upstream — when the 2027 models start rolling onto trucks, someone
 * runs this once and the dropdowns know about them. vPIC is US-government
 * public domain: no key, no account, no charge.
 *
 * HOW IT ASKS. vPIC has no "everything for a year" endpoint — models are
 * fetched per make. Asking for its full make list would drag in thousands of
 * one-off manufacturers, so instead this asks for the makes the catalog
 * already knows (66 of them, one request each), which is exactly the market
 * the CSV drew and far inside vPIC's fair use. A make with no models for the
 * year — retired brands, not-yet-published years — simply contributes nothing.
 * Every request is written to api_log either way, like every outside call.
 *
 * Additive and idempotent like seed_vehicles.php: rows are only ever added,
 * with source 'vpic', and a re-run adds only what the first run missed.
 * Nothing references this table, so a partial run leaves nothing broken.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

App::boot($cfg);
Db::boot($cfg['db']);
Db::migrate();

$years = array_values(array_filter(array_map('intval', array_slice($argv, 1)), fn ($y) => $y >= 1980 && $y <= 2100));
if ($years === []) {
    fwrite(STDERR, "Usage: php data/refresh_vehicles.php <year> [year …]   e.g. 2027\n");
    exit(1);
}

$makes = array_column(Db::all('SELECT DISTINCT make FROM vehicle_catalog ORDER BY make'), 'make');
if ($makes === []) {
    fwrite(STDERR, "vehicle_catalog is empty — run php data/seed_vehicles.php first, so there is a make list to ask about.\n");
    exit(1);
}

$have = [];
foreach (Db::all('SELECT year, make, model FROM vehicle_catalog') as $r) {
    $have[$r['year'] . '|' . $r['make'] . '|' . $r['model']] = true;
}

$base = 'https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMakeYear/make/%s/modelyear/%d?format=json';

foreach ($years as $year) {
    $added = 0;
    $failed = 0;
    foreach ($makes as $make) {
        $res  = Http::json('GET', sprintf($base, rawurlencode($make), $year));
        $rows = $res['body']['Results'] ?? null;
        $ok   = $res['error'] === '' && is_array($rows);

        ApiLog::write('vehicles', 'vpic', 'models_for_make_year', $make . ' ' . $year,
            $ok, $ok ? count($rows) . ' models' : ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status']));

        if (!$ok) { $failed++; fwrite(STDERR, "  $make $year: " . ($res['error'] ?: 'HTTP ' . $res['status']) . "\n"); continue; }

        foreach ($rows as $row) {
            $model = trim((string) ($row['Model_Name'] ?? ''));
            /* vPIC replies with its own casing of the make ("HONDA"); keep the
             * catalog's spelling so one make never splits into two entries. */
            if ($model === '') { continue; }
            $key = $year . '|' . $make . '|' . $model;
            if (isset($have[$key])) { continue; }
            Db::insert('vehicle_catalog', [
                'year' => $year, 'make' => $make, 'model' => substr($model, 0, 64),
                'source' => 'vpic', 'created_at' => now(),
            ]);
            $have[$key] = true;
            $added++;
        }
        usleep(250_000);   // politeness between requests; vPIC asks big jobs to spread out
    }
    fwrite(STDOUT, "$year: $added models added across " . count($makes) . " makes"
        . ($failed ? " ($failed requests failed — re-run to fill gaps)" : '') . ".\n");
}
