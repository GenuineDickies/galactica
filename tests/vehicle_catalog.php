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
 * Vehicle catalog: the seed import and the queries behind /vehicles/options.
 *
 *   php tests/vehicle_catalog.php
 *
 * Self-contained: builds a throwaway SQLite database in the system temp dir,
 * so it never touches the configured database and needs no credentials.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }

$tmp = tempnam(sys_get_temp_dir(), 'wkr_vehcat_') . '.sqlite';
$cfg['db'] = ['driver' => 'sqlite', 'path' => $tmp];
App::boot($cfg);
Db::boot($cfg['db']);
Db::migrate();

require $root . '/data/seed_vehicles.php';

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s\n       got %s, wanted %s\n", $l, var_export($got, true), var_export($want, true)); }
}

echo "Import\n";
$first = seed_vehicle_catalog($root . '/data/vehicle-models.csv');
check('import reports ok',        $first['ok'], true);
check('import added rows',        $first['added'] > 10000, true);
check('added equals present on a fresh table', $first['added'], $first['present']);
check('table row count matches',  (int) Db::val('SELECT COUNT(*) FROM vehicle_catalog'), $first['present']);

echo "Idempotence\n";
$second = seed_vehicle_catalog($root . '/data/vehicle-models.csv');
check('second run adds nothing',  $second['added'], 0);
check('second run same total',    $second['present'], $first['present']);

$missing = seed_vehicle_catalog($root . '/data/no-such-file.csv');
check('missing file fails soft',  $missing['ok'], false);

echo "Options queries (what /vehicles/options runs)\n";
$years = array_map('intval', array_column(Db::all('SELECT DISTINCT year FROM vehicle_catalog ORDER BY year DESC'), 'year'));
check('newest year first',        $years[0], 2026);
check('oldest year last',         $years[count($years) - 1], 1992);
check('every year in between',    count($years), 2026 - 1992 + 1);

$makes = array_column(Db::all('SELECT DISTINCT make FROM vehicle_catalog WHERE year = ? ORDER BY make', [2024]), 'make');
check('2024 has makes',           count($makes) > 20, true);
check('Ford is a 2024 make',      in_array('Ford', $makes, true), true);

$models = array_column(Db::all('SELECT DISTINCT model FROM vehicle_catalog WHERE year = ? AND make = ? ORDER BY model', [2024, 'Honda']), 'model');
check('2024 Honda has models',    count($models) > 3, true);
check('Civic among them',         in_array('Civic', $models, true), true);

$none = Db::all('SELECT DISTINCT model FROM vehicle_catalog WHERE year = ? AND make = ? ORDER BY model', [2024, 'Studebaker']);
check('unknown make returns empty, not error', $none, []);

@unlink($tmp);
printf("\n%d passed, %d failed\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
