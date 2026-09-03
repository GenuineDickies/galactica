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
 * Unit tests for Lines::taxCents — per-line tax. Pure — no database, no server.
 *   php tests/line_tax.php
 *
 * The rule (decided 2026-08-27, for US-wide tenant accounts): each taxable
 * line's tax is rounded to the cent, then summed — never taxable-subtotal ×
 * rate rounded once. Several cases below pin the exact documents where the
 * two methods disagree, so a "simplification" back to aggregate fails loudly.
 * Lines::totals wiring (DB-backed) is exercised by tests/pricing_integration.
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

section('nothing to tax');
check('no lines',              Lines::taxCents([], 0.03), 0);
check('zero rate',             Lines::taxCents([1649], 0.0), 0);
check('negative rate',         Lines::taxCents([1649], -0.03), 0);
check('zero/negative bases',   Lines::taxCents([0, -500], 0.03), 0);

section('per-line rounding — where the methods disagree');
check('one line 16.49 @ 3%',   Lines::taxCents([1649], 0.03), 49);          // 49.47 → 49
check('two lines 16.49 @ 3%',  Lines::taxCents([1649, 1649], 0.03), 98);    // aggregate says 99
check('half rounds away',      Lines::taxCents([1650], 0.03), 50);          // 49.5 → 50
check('two halves round up',   Lines::taxCents([1650, 1650], 0.03), 100);   // aggregate says 99
check('three lines @ 8.25%',   Lines::taxCents([1000, 2000, 3000], 0.0825), 496); // 83+165+248; aggregate 495

section('per-line rounding — where they agree');
check('clean multiples',       Lines::taxCents([1000, 2000], 0.10), 300);
check('single line exact',     Lines::taxCents([2200], 0.05), 110);

section('document discount is allocated pro rata before tax');
check('even split',            Lines::taxCents([1000, 1000], 0.10, 100), 190);  // 950+950 → 95+95
check('uneven split',          Lines::taxCents([1000, 500], 0.10, 100), 140);   // 934→93, 466→47
check('discount swallows all', Lines::taxCents([500, 500], 0.10, 2000), 0);
check('negative discount = 0', Lines::taxCents([1000], 0.10, -50), 100);
check('allocation is exact',   Lines::taxCents([333, 333, 334], 1.0, 100), 900); // Σbases − disc, to the cent

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
