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
 * Load data/vehicle-models.csv into vehicle_catalog — the reference list of
 * known year/make/model combinations behind the intake form's dropdowns.
 *
 *   php data/seed_vehicles.php            add whatever the table is missing
 *
 * Safe to run any number of times, against any database, at any point in its
 * life: it only ever ADDS rows that are not there yet. It never updates and
 * never deletes, because this table is pure reference data — nothing points
 * at it, documents store their vehicle text themselves, and a row that has
 * been imported is a fact about the US car market that does not change.
 *
 * WHERE THE DATA COMES FROM. data/vehicle-models.csv is a cleaned merge of
 * the 1992–2026 files from github.com/abhionlyone/us-car-models-data
 * (CC-BY 4.0 — see knowledge/EXTERNAL-SOURCES.md for the attribution note).
 * That dataset is frozen; later model years come from NHTSA vPIC via
 * data/refresh_vehicles.php, which writes the same table with source 'vpic'.
 *
 * This file is both a CLI script and a library: install.php requires it and
 * calls seed_vehicle_catalog() itself, so a fresh install gets the dropdowns
 * without a second command.
 */
declare(strict_types=1);

function seed_vehicle_catalog(string $csvPath): array
{
    if (!is_file($csvPath)) {
        return ['ok' => false, 'error' => 'missing ' . $csvPath, 'added' => 0, 'present' => 0];
    }

    /* The whole table fits comfortably in memory (11.5k rows), and one set
     * lookup per CSV line beats one SELECT per CSV line by three orders of
     * magnitude. Keyed on the exact stored text — the import is byte-faithful,
     * no case folding, so what the dropdown offers is what the file said. */
    $have = [];
    foreach (Db::all('SELECT year, make, model FROM vehicle_catalog') as $r) {
        $have[$r['year'] . '|' . $r['make'] . '|' . $r['model']] = true;
    }

    $fh = fopen($csvPath, 'r');
    fgetcsv($fh);                                   // header: year,make,model
    $added = 0;

    Db::tx(function () use ($fh, &$have, &$added) {
        while (($row = fgetcsv($fh)) !== false) {
            [$year, $make, $model] = [(int) ($row[0] ?? 0), trim((string) ($row[1] ?? '')), trim((string) ($row[2] ?? ''))];
            if ($year < 1900 || $make === '' || $model === '') { continue; }
            $key = $year . '|' . $make . '|' . $model;
            if (isset($have[$key])) { continue; }
            Db::insert('vehicle_catalog', [
                'year' => $year, 'make' => $make, 'model' => $model,
                'source' => 'csv', 'created_at' => now(),
            ]);
            $have[$key] = true;
            $added++;
        }
    });
    fclose($fh);

    return ['ok' => true, 'error' => '', 'added' => $added, 'present' => count($have)];
}

/* ---- CLI entry point ------------------------------------------------- */
if (realpath($argv[0] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $cfg  = require $root . '/config.php';
    foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }

    App::boot($cfg);
    Db::boot($cfg['db']);
    Db::migrate();

    $out = seed_vehicle_catalog($root . '/data/vehicle-models.csv');
    if (!$out['ok']) { fwrite(STDERR, $out['error'] . "\n"); exit(1); }
    fwrite(STDOUT, "vehicle_catalog: {$out['added']} added, {$out['present']} total.\n");
}
