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
 * Retire estimates.service_address in favour of nearest_address.
 *
 *   php data/drop-service-address.php            → report only, changes nothing
 *   php data/drop-service-address.php --backfill → copy the data across
 *   php data/drop-service-address.php --drop     → DROP the column (one way)
 *
 * WHY A SCRIPT AND NOT A MIGRATION. Db::migrate() is additive by construction:
 * it creates missing tables and adds missing columns, and never drops, renames
 * or retypes anything. That is a deliberate safety property — it means a
 * deployment can never destroy a column, and it means this cannot ride along
 * with one. Dropping is a decision, so it gets a command.
 *
 * THE ORDER MATTERS AND IT IS NOT OPTIONAL.
 *   1. --backfill  (safe, repeatable, additive)
 *   2. ship the code that reads nearest_address, and check the screens
 *   3. --drop      (irreversible; the data is gone)
 * Run 3 before 2 and every estimate loses its address on a live site.
 *
 * A DROP CANNOT BE UNDONE. --drop writes the whole column to a timestamped
 * file under backups/ first. That file is the only way back.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$argvv    = $argv ?? [];
$backfill = in_array('--backfill', $argvv, true);
$drop     = in_array('--drop', $argvv, true);

/* ---- Is the column even there? ------------------------------------- */
$cols = [];
foreach (Db::all(Db::driver() === 'mysql' ? 'SHOW COLUMNS FROM estimates' : "PRAGMA table_info(estimates)") as $c) {
    $cols[] = strtolower((string) ($c['Field'] ?? $c['name'] ?? ''));
}
if (!in_array('service_address', $cols, true)) {
    echo "estimates.service_address is already gone. Nothing to do.\n";
    exit(0);
}

/* ---- What is actually in it? ---------------------------------------- */
$total   = (int) Db::val('SELECT COUNT(*) FROM estimates');
$hasOld  = (int) Db::val("SELECT COUNT(*) FROM estimates WHERE service_address IS NOT NULL AND service_address <> ''");
$wouldFill = (int) Db::val(
    "SELECT COUNT(*) FROM estimates
      WHERE service_address IS NOT NULL AND service_address <> ''
        AND (nearest_address IS NULL OR nearest_address = '')"
);
$conflict = (int) Db::val(
    "SELECT COUNT(*) FROM estimates
      WHERE service_address IS NOT NULL AND service_address <> ''
        AND nearest_address IS NOT NULL AND nearest_address <> ''
        AND service_address <> nearest_address"
);

printf("estimates: %d rows · %d carry a service_address\n", $total, $hasOld);
printf("  %d would be copied into an empty nearest_address\n", $wouldFill);
printf("  %d already have a DIFFERENT nearest_address and will be left alone\n", $conflict);

/* How many of the old values are not addresses at all? Worth knowing before
 * they become the address of record. */
$bad = [];
foreach (Db::all("SELECT id, doc_number, service_address, city, state FROM estimates
                   WHERE service_address IS NOT NULL AND service_address <> ''") as $r) {
    if (!Address::check($r['service_address'], $r['city'], $r['state'])['ok']) {
        $bad[] = $r;
    }
}
if ($bad) {
    printf("\n  %d of those are not physical addresses and will need a human:\n", count($bad));
    foreach (array_slice($bad, 0, 10) as $r) {
        printf("    %-22s %s\n", $r['doc_number'], $r['service_address']);
    }
    if (count($bad) > 10) { printf("    … and %d more\n", count($bad) - 10); }
}

if (!$backfill && !$drop) {
    echo "\nReport only. Re-run with --backfill to copy the data, then --drop once the\n"
       . "screens have been checked. Read the header of this file before dropping.\n";
    exit(0);
}

/* ---- Backfill -------------------------------------------------------- */
if ($backfill) {
    Db::q("UPDATE estimates SET nearest_address = service_address, updated_at = updated_at
            WHERE service_address IS NOT NULL AND service_address <> ''
              AND (nearest_address IS NULL OR nearest_address = '')");
    printf("\nbackfilled %d rows into nearest_address.\n", $wouldFill);
    Audit::log('system', 0, 'schema:backfill',
        "estimates.service_address → nearest_address ($wouldFill rows)");
}

/* ---- Drop ------------------------------------------------------------ */
if ($drop) {
    $dir = $root . '/backups';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $file = $dir . '/service_address-' . date('Ymd-His') . '.json';

    $rows = Db::all('SELECT id, doc_number, service_address FROM estimates
                      WHERE service_address IS NOT NULL AND service_address <> \'\'');
    file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    printf("\nwrote %d rows to %s\n", count($rows), $file);

    $still = (int) Db::val(
        "SELECT COUNT(*) FROM estimates
          WHERE service_address IS NOT NULL AND service_address <> ''
            AND (nearest_address IS NULL OR nearest_address = '')"
    );
    if ($still > 0) {
        fwrite(STDERR, "\nREFUSED: $still rows still hold a service_address that never reached\n"
            . "nearest_address. Run --backfill first. Nothing was dropped.\n");
        exit(1);
    }

    Db::q('ALTER TABLE estimates DROP COLUMN service_address');
    Audit::log('system', 0, 'schema:dropped', 'estimates.service_address · backup ' . basename($file));
    echo "dropped estimates.service_address.\n";
}
