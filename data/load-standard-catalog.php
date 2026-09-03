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
 * Load the standard price book into an EXISTING catalog, additively.
 *
 *   php data/load-standard-catalog.php
 *
 * Rows come from catalog_seed_rows() in data/seed.php — the same single
 * source seed_catalog() writes on a fresh install — so there is exactly one
 * price book to maintain. Differences from seed_catalog():
 *
 *   - a SKU that already exists is left alone
 *   - an item the operator already carries under their own SKU is skipped by
 *     intent, not just by code: the seed's jump start is not added beside an
 *     existing "Jump Start", nor its donut swap beside an existing spare swap
 *   - TOWING services are skipped — White Knight does not tow (see the seed's
 *     own comment); run with --towing to include them
 *
 * Every insert is audit-logged. Missing GL accounts are reported at the end
 * rather than blocking: gl_accounts is deletable by design, so the operator
 * decides whether to add the account or repoint the item at /catalog.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
require $root . '/data/seed.php';   // defines catalog_seed_rows(); runs nothing

App::boot($cfg);
Db::boot($cfg['db']);

$withTowing = in_array('--towing', $argv, true);

/* An operator's own item covers the seed's version of the same service.
 * Candidate SKU => name patterns that mean "already carried". */
$covers = [
    'SVC-JUMP-STD'    => ['%jump%'],
    'SVC-TIRE-CHANGE' => ['%spare%'],
    'MISC-CHARGE'     => ['%miscellaneous%'],
];

$added = 0; $skipped = [];
foreach (catalog_seed_rows() as $row) {
    if (!$withTowing && ($row['service_category'] ?? '') === 'TOWING') {
        $skipped[] = $row['sku'] . ' (towing — not offered)';
        continue;
    }
    if (Db::one('SELECT id FROM catalog_items WHERE sku = ?', [$row['sku']])) {
        $skipped[] = $row['sku'] . ' (SKU exists)';
        continue;
    }
    $dup = null;
    foreach ($covers[$row['sku']] ?? [] as $pat) {
        $dup = Db::one('SELECT sku, name FROM catalog_items WHERE lower(name) LIKE ?', [$pat]) ?: $dup;
    }
    if ($dup) {
        $skipped[] = $row['sku'] . ' (covered by ' . $dup['sku'] . ' — ' . $dup['name'] . ')';
        continue;
    }

    $id = Db::insert('catalog_items', $row + ['sort_order' => 0]);
    Audit::log('catalog_item', $id, 'created',
        $row['sku'] . ' — standard price book (data/load-standard-catalog.php)');
    echo sprintf("  + %-18s %-45s %s\n", $row['sku'], $row['name'],
        $row['pricing_model'] === 'ESTIMATE' ? 'quote required' : money((float) $row['unit_price']));
    $added++;
}

foreach ($skipped as $s) { echo "  · skipped $s\n"; }

/* The accounts the loaded items post to must actually exist. */
$codes = [];
foreach (Db::all("SELECT DISTINCT revenue_account AS c FROM catalog_items WHERE revenue_account <> ''
                  UNION SELECT DISTINCT cogs_account FROM catalog_items WHERE cogs_account <> ''") as $r) {
    $codes[] = (string) $r['c'];
}
$missing = [];
foreach ($codes as $c) {
    if (!Db::one('SELECT id FROM gl_accounts WHERE account_number = ?', [$c])) { $missing[] = $c; }
}
if ($missing) {
    echo 'WARNING: catalog items reference GL accounts that do not exist: '
       . implode(', ', $missing) . ". Add them at /accounts or repoint the items.\n";
}

echo "$added added, " . count($skipped) . " skipped.\n";
