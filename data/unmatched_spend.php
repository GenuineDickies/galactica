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
 * List the spending the rules engine could not categorise, worst first.
 *
 *   php data/unmatched_spend.php spend.json [limit]
 *
 * Shows the raw descriptor as the bank wrote it, not just the match key, because
 * the raw form is what an operator will recognise. Every row here is currently
 * counted as profit for want of a category, so this list is the shortest path to
 * a smaller taxable figure.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }
ini_set('display_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/app/Domain.php';

$file  = $argv[1] ?? '';
$limit = (int) ($argv[2] ?? 40);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php data/unmatched_spend.php <spend.json> [limit]\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($file), true) ?: [];

$rules = []; $i = 0;
foreach (ExpenseRules::SEED as $r) {
    $rules[] = ['id' => ++$i, 'pattern' => $r[0], 'is_regex' => (int) ($r[4] ?? 0),
                'account_code' => $r[1], 'priority' => $r[3], 'is_active' => 1];
}
usort($rules, static fn($a, $b) => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);

$TRANSFER = ['/^FUNDS TRANSFER/', '/^TRANSFER/', '/^ATM WITHDRAWAL/', '/^VISA \d{3,4}$/',
             '/^CASH APP/', '/^VENMO/', '/^CREDIT CARD PAYMENT/', '/^INTERNAL TRANSFER/',
             '/^WITHDRAWAL/', '/^OVERDRAFT/'];

$g = [];
foreach ($rows as $r) {
    $key = Descriptor::normalize((string) $r['desc']);
    foreach ($TRANSFER as $p) { if (preg_match($p, $key)) { continue 2; } }
    if (ExpenseRules::match($key, $rules) !== null) { continue; }

    $k = $key === '' ? '(blank)' : $key;
    $g[$k]['n']     = ($g[$k]['n'] ?? 0) + 1;
    $g[$k]['cents'] = ($g[$k]['cents'] ?? 0) + (int) $r['cents'];
    $g[$k]['raw']   = $g[$k]['raw'] ?? (string) $r['desc'];
    $g[$k]['years'][(string) $r['y']] = true;
    $g[$k]['src'][(string) $r['src']] = true;
}
uasort($g, static fn($a, $b) => $b['cents'] <=> $a['cents']);

$total = 0; foreach ($g as $v) { $total += $v['cents']; }
printf("%d distinct descriptors, \$%s uncategorised in total\n\n",
    count($g), number_format($total / 100, 2));
printf("%-30s %5s %11s  %-16s %s\n", 'AS THE BANK WROTE IT', 'N', 'AMOUNT', 'YEARS', 'WHERE');
echo str_repeat('-', 92), "\n";

$n = 0; $shown = 0;
foreach ($g as $key => $v) {
    $yrs = array_keys($v['years']); sort($yrs);
    printf("%-30s %5d %11s  %-16s %s\n",
        substr($v['raw'], 0, 30), $v['n'], number_format($v['cents'] / 100, 2),
        implode(',', array_map(static fn($y) => substr((string) $y, 2), $yrs)),
        implode('+', array_keys($v['src'])));
    $shown += $v['cents'];
    if (++$n >= $limit) { break; }
}
printf("\ntop %d cover \$%s of \$%s (%.0f%%)\n",
    $n, number_format($shown / 100, 2), number_format($total / 100, 2), 100 * $shown / max(1, $total));
