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
 * Integration tests for markup pricing against a real database: snapshotting,
 * immutability after a matrix edit, and override persistence. Drives the actual
 * Lines::add / Lines::copy / Markup code, not HTTP.
 *
 *   WKR_DB_PASS=… php tests/pricing_integration.php
 *
 * Runs against whatever database config.php points at; it creates throwaway
 * catalog items and a throwaway estimate, so use a scratch database.
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

/* THIS SUITE WIPES markup_tiers AND RE-SEEDS THE DEFAULTS. On a database
 * with a customised matrix that is destruction, not testing — so it refuses
 * to run unless the database name looks like a scratch one, the same
 * fail-closed posture as the wipe guard (added 2026-08-27). Override
 * deliberately with WKR_ALLOW_PRICING_TEST=1 when the target really is
 * disposable. */
$dbName = (string) ($cfg['db']['database'] ?? ($cfg['db']['path'] ?? ''));
if (getenv('WKR_ALLOW_PRICING_TEST') !== '1'
    && !preg_match('/test|scratch|dev/i', $dbName)) {
    fwrite(STDERR, "REFUSED: this suite deletes and re-seeds markup_tiers, and '$dbName'\n"
        . "does not look like a scratch database. Point config.php at one, or run:\n"
        . "  WKR_ALLOW_PRICING_TEST=1 php tests/pricing_integration.php\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $l, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

// Fresh matrix so the test is independent of whatever is stored.
Db::q('DELETE FROM markup_tiers');
$i = 0;
foreach (Markup::DEFAULTS as [$min, $max, $pct]) {
    Db::insert('markup_tiers', ['min_cost' => number_format($min, 2, '.', ''),
        'max_cost' => $max === null ? null : number_format($max, 2, '.', ''),
        'markup_pct' => number_format($pct, 2, '.', ''), 'sort_order' => $i++]);
}

/** Throwaway catalog item with a given cost. */
function tempItem(string $name, float $cost, float $price = 0.0, string $type = 'PART'): int {
    return Db::insert('catalog_items', ['sku' => 'TEST-' . bin2hex(random_bytes(3)), 'item_type' => $type,
        'category' => 'Test', 'name' => $name, 'description' => '', 'pricing_model' => 'FLAT',
        'unit_price' => $price, 'unit_cost' => $cost, 'uom' => 'each', 'taxable' => 1, 'sort_order' => 0]);
}
/** Throwaway estimate to hang lines on. */
$estId = Db::insert('estimates', ['doc_number' => 'EST-TEST-' . bin2hex(random_bytes(2)),
    'service_request_id' => 0, 'customer_id' => 0, 'status' => 'DRAFT', 'tax_rate' => 0,
    'created_at' => now(), 'updated_at' => now()]);

section('snapshot on add — $100 part @ 75% tier -> $175, qty 2');
$part = tempItem('Test Alternator', 100.00);
$lineId = Lines::add('EST', $estId, $part, 2.0);      // no overrides
$line = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId]);
check('unit_cost snapshot',   $line['unit_cost'],       '100.00');
check('markup_pct snapshot',  $line['markup_pct'],      '75.00');
check('suggested_price',      $line['suggested_price'], '175.00');
check('unit_price = suggested',$line['unit_price'],     '175.00');
check('not overridden',       (int) $line['price_overridden'], 0);
check('line_total 2×175',     $line['line_total'],      '350.00');

section('matrix edit does NOT change the existing line (immutability)');
Db::q('UPDATE markup_tiers SET markup_pct = 10.00 WHERE min_cost = 50.01');  // 75% -> 10%
$again = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId]);
check('snapshot price unchanged',  $again['unit_price'],  '175.00');
check('snapshot markup unchanged', $again['markup_pct'],  '75.00');
$lineId2 = Lines::add('EST', $estId, $part, 1.0);       // a NEW line sees the new matrix
$new = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId2]);
check('new line uses edited matrix (10%)', $new['unit_price'], '110.00');
// restore matrix for the rest
Db::q('UPDATE markup_tiers SET markup_pct = 75.00 WHERE min_cost = 50.01');

section('manual override is recorded and keeps the suggestion alongside');
$lineId3 = Lines::add('EST', $estId, $part, 1.0, 200.00, '', null, true);   // priceOverride 200, overridden
$ov = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId3]);
check('override price kept',       $ov['unit_price'],       '200.00');
check('override flag set',         (int) $ov['price_overridden'], 1);
check('suggestion still snapshotted', $ov['suggested_price'], '175.00');

section('override inferred when no flag passed but price differs');
$lineId4 = Lines::add('EST', $estId, $part, 1.0, 250.00);   // differs from 175 suggestion
$inf = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId4]);
check('inferred override', (int) $inf['price_overridden'], 1);

section('zero-cost part -> needs pricing is refused, never auto-$0');
$free  = tempItem('Customer-supplied filter', 0.00, 0.00);
$threw = false;
try { Lines::add('EST', $estId, $free, 1.0); } catch (RuntimeException $e) { $threw = true; }
check('blank price on a needs-pricing item is refused', $threw, true);
// An explicit 0.00 is a decision (a deliberate no-charge line) and stays legal.
$lineId5 = Lines::add('EST', $estId, $free, 1.0, 0.00);
$z = Db::one('SELECT * FROM doc_lines WHERE id = ?', [$lineId5]);
check('markup null on zero cost',      $z['markup_pct'],      null);
check('suggested null on zero cost',   $z['suggested_price'], null);
check('explicit 0.00 kept',            $z['unit_price'],      '0.00');

section('copy to work order preserves the snapshot');
$woId = Db::insert('work_orders', ['doc_number' => 'WOR-TEST-' . bin2hex(random_bytes(2)),
    'estimate_id' => $estId, 'service_request_id' => 0, 'status' => 'ASSIGNED',
    'created_at' => now(), 'updated_at' => now()]);
Lines::copy('EST', $estId, 'WO', $woId);
$copied = Db::one("SELECT * FROM doc_lines WHERE doc_type='WO' AND doc_id=? AND markup_pct='75.00' LIMIT 1", [$woId]);
check('copied line keeps markup snapshot',     $copied['markup_pct'],      '75.00');
check('copied line keeps suggested snapshot',  $copied['suggested_price'], '175.00');

section('customer-facing templates never render cost/markup');
$print = file_get_contents($root . '/app/Views/pages/doc_print.php');
$checkout = file_get_contents($root . '/app/Views/pages/checkout.php');
check('doc_print has no unit_cost',   strpos($print, 'unit_cost') === false, true);
check('doc_print has no markup',      strpos($print, 'markup') === false, true);
check('checkout has no unit_cost',    strpos($checkout, 'unit_cost') === false, true);

// Clean up throwaway rows.
Db::q('DELETE FROM doc_lines WHERE doc_type IN (?,?) AND doc_id IN (?,?)', ['EST', 'WO', $estId, $woId]);
Db::q('DELETE FROM estimates WHERE id = ?', [$estId]);
Db::q('DELETE FROM work_orders WHERE id = ?', [$woId]);
Db::q("DELETE FROM catalog_items WHERE sku LIKE 'TEST-%'");

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
