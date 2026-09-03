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
 * Unit tests for the Markup pricing service. Pure — no database, no server.
 *   php tests/markup.php
 *
 * Tiers are passed in explicitly so the maths is tested in isolation from
 * whatever matrix happens to be stored.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Domain.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/** The industry-default matrix, as stored rows would look. */
function tiers(): array {
    $out = []; $i = 0;
    foreach (Markup::DEFAULTS as [$min, $max, $pct]) {
        $out[] = ['id' => ++$i, 'min_cost' => number_format($min, 2, '.', ''),
                  'max_cost' => $max === null ? null : number_format($max, 2, '.', ''),
                  'markup_pct' => number_format($pct, 2, '.', '')];
    }
    return $out;
}
$T = tiers();

section('money ⇄ cents parsing (no floats)');
check('toCents 10.00',       Markup::toCents('10.00'), 1000);
check('toCents $1,500.01',   Markup::toCents('$1,500.01'), 150001);
check('toCents 0',           Markup::toCents(0), 0);
check('toCents null',        Markup::toCents(null), 0);
check('centsToStr 8752',     Markup::centsToStr(8752), '87.52');
check('centsToStr 1000000',  Markup::centsToStr(1000000), '10000.00');
check('centsToStr 5',        Markup::centsToStr(5), '0.05');

section('tier selection — boundary belongs to the LOWER tier');
check('$5.00  -> tier1 (200%)',  Markup::tierFor(500,   $T)['id'], 1);
check('$10.00 boundary -> tier1', Markup::tierFor(1000,  $T)['id'], 1);
check('$10.01 -> tier2 (100%)',  Markup::tierFor(1001,  $T)['id'], 2);
check('$50.00 boundary -> tier2', Markup::tierFor(5000,  $T)['id'], 2);
check('$50.01 -> tier3 (75%)',   Markup::tierFor(5001,  $T)['id'], 3);
check('$200.00 boundary -> tier3',Markup::tierFor(20000, $T)['id'], 3);
check('$500.00 boundary -> tier4',Markup::tierFor(50000, $T)['id'], 4);
check('$1,500.00 boundary -> t5', Markup::tierFor(150000,$T)['id'], 5);
check('$1,500.01 -> top tier',   Markup::tierFor(150001,$T)['id'], 6);
check('$25,000 engine -> top',   Markup::tierFor(2500000,$T)['id'], 6);

section('price suggestion — cost + cost×markup, commercial rounding');
check('$10.00 @200% = $30.00',   Markup::suggest('10.00',   $T)['price'], '30.00');
check('$10.01 @100% = $20.02',   Markup::suggest('10.01',   $T)['price'], '20.02');
check('$50.00 @100% = $100.00',  Markup::suggest('50.00',   $T)['price'], '100.00');
check('$50.01 @75%  = $87.52',   Markup::suggest('50.01',   $T)['price'], '87.52'); // 87.5175 -> 87.52
check('$200.00 @75% = $350.00',  Markup::suggest('200.00',  $T)['price'], '350.00');
check('$50.02 @75% = $87.54 (½ up)',Markup::suggest('50.02', $T)['price'], '87.54'); // 8753.5c -> 8754c
check('$0.02 @200% = $0.06',     Markup::suggest('0.02',   $T)['price'], '0.06'); // in tier1, exact
check('$1,500.01 @25% = $1875.01',Markup::suggest('1500.01',$T)['price'], '1875.01');
check('$8,000 engine @25% = 10000',Markup::suggest('8000.00',$T)['price'], '10000.00');
check('markup_pct echoed',       Markup::suggest('50.01',   $T)['markup_pct'], '75.00');

section('zero / null cost -> needs pricing, never $0 quote');
check('cost 0 needs pricing',    Markup::suggest('0.00', $T)['needs_pricing'], true);
check('cost 0 price null',       Markup::suggest('0.00', $T)['price'], null);
check('cost null needs pricing', Markup::suggest(null,   $T)['needs_pricing'], true);
check('cost >0 does not',        Markup::suggest('5.00', $T)['needs_pricing'], false);

section('0% pass-through tier is allowed');
$pt = [['id'=>1,'min_cost'=>'0.00','max_cost'=>null,'markup_pct'=>'0.00']];
check('$123.45 @0% = $123.45',   Markup::suggest('123.45', $pt)['price'], '123.45');

section('profit and margin');
check('profit $ (price 30, cost 10)', Markup::profitCents('30.00','10.00'), 2000);
check('margin % (price 100, cost 25)',Markup::marginPct('100.00','25.00'), '75.0');
check('margin % (price 30, cost 10)', Markup::marginPct('30.00','10.00'),  '66.7'); // 20/30
check('margin % price 0 -> null',     Markup::marginPct('0.00','0.00'),    null);

section('matrix validation');
check('defaults are valid',      Markup::validate($T), []);
$overlap = [['min_cost'=>'0.00','max_cost'=>'10.00','markup_pct'=>'200'],
            ['min_cost'=>'10.00','max_cost'=>null,'markup_pct'=>'100']];
check('overlap rejected',        count(Markup::validate($overlap)) > 0, true);
$gap = [['min_cost'=>'0.00','max_cost'=>'10.00','markup_pct'=>'200'],
        ['min_cost'=>'10.02','max_cost'=>null,'markup_pct'=>'100']];
check('gap rejected',            count(Markup::validate($gap)) > 0, true);
$neg = [['min_cost'=>'0.00','max_cost'=>null,'markup_pct'=>'-5']];
check('negative percent rejected',count(Markup::validate($neg)) > 0, true);
$closedTop = [['min_cost'=>'0.00','max_cost'=>'10.00','markup_pct'=>'200']];
check('closed top tier rejected',count(Markup::validate($closedTop)) > 0, true);
$badStart = [['min_cost'=>'5.00','max_cost'=>null,'markup_pct'=>'200']];
check('first tier must start at 0',count(Markup::validate($badStart)) > 0, true);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
