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
 * Categorise a batch of bank descriptors and print a year-by-account summary.
 *
 *   php data/categorise_spend.php spend.json
 *
 * Input is a JSON array of {src, y, desc, cents}. Output is a table of GL
 * account by year, plus the transfer rows and the unmatched tail kept separate.
 *
 * WHY THIS IS A SCRIPT AND NOT A ONE-OFF. The categorisation must come from
 * ExpenseRules and Descriptor — the same code the application will use when
 * these rows post for real. Reimplementing the matching in Python to "just get
 * the numbers" would produce figures that no later run could reproduce, which
 * is how a reconciliation becomes a story.
 *
 * Nothing here writes to the database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

require dirname(__DIR__) . '/app/Domain.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php data/categorise_spend.php <spend.json>\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($file), true);
if (!is_array($rows)) { fwrite(STDERR, "Could not read that JSON.\n"); exit(1); }

/* The seed, ordered exactly as ExpenseRules::active() would order it. */
$rules = [];
$i = 0;
foreach (ExpenseRules::SEED as $r) {
    $rules[] = ['id' => ++$i, 'pattern' => $r[0], 'is_regex' => (int) ($r[4] ?? 0),
                'account_code' => $r[1], 'vendor_name' => $r[2], 'priority' => $r[3],
                'is_active' => 1, 'classification' => (string) ($r[5] ?? 'BUSINESS')];
}
usort($rules, static fn($a, $b) => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);

/* Movements between accounts the business owns, or out to the owner. These are
 * NOT spending and must never reach an expense line — counting a transfer as an
 * expense is the single easiest way to invent a deduction. */
$TRANSFER = ['/^FUNDS TRANSFER/', '/^TRANSFER/', '/^ATM WITHDRAWAL/', '/^VISA \d{3,4}$/',
             '/^CASH APP/', '/^VENMO/', '/^CREDIT CARD PAYMENT/', '/^INTERNAL TRANSFER/',
             '/^WITHDRAWAL/', '/^OVERDRAFT/'];

$byAcct = [];      // account => year => cents
$transfers = [];   // year => cents
$unmatched = [];   // year => cents
$unmatchedBy = []; // descriptor => [n, cents]
$years = [];

foreach ($rows as $r) {
    $y = (string) $r['y'];
    $c = (int) $r['cents'];
    $years[$y] = true;
    $key = Descriptor::normalize((string) $r['desc']);

    $isTransfer = false;
    foreach ($TRANSFER as $p) { if (preg_match($p, $key)) { $isTransfer = true; break; } }
    if ($isTransfer) { $transfers[$y] = ($transfers[$y] ?? 0) + $c; continue; }

    $rule = ExpenseRules::match($key, $rules);
    if ($rule === null) {
        $unmatched[$y] = ($unmatched[$y] ?? 0) + $c;
        $d = $key === '' ? '(blank)' : $key;
        $unmatchedBy[$d] = [($unmatchedBy[$d][0] ?? 0) + 1, ($unmatchedBy[$d][1] ?? 0) + $c];
        continue;
    }
    $a = $rule['account_code'];
    $byAcct[$a][$y] = ($byAcct[$a][$y] ?? 0) + $c;
}

$years = array_keys($years);
sort($years);
$names = [];
foreach (Accounts::DEFAULTS as [$n, $nm, $t]) { $names[$n] = $nm; }
$m = static fn(int $c): string => number_format($c / 100, 0);

printf("%-6s %-34s", 'ACCT', 'NAME');
foreach ($years as $y) { printf("%10s", $y); }
printf("%12s\n", 'TOTAL');
echo str_repeat('-', 41 + 10 * count($years) + 12), "\n";

$colTot = [];
uksort($byAcct, static fn($a, $b) => $a <=> $b);
foreach ($byAcct as $acct => $yy) {
    printf("%-6s %-34s", $acct, substr($names[$acct] ?? '?', 0, 34));
    $t = 0;
    foreach ($years as $y) {
        $v = $yy[$y] ?? 0; $t += $v;
        $colTot[$y] = ($colTot[$y] ?? 0) + $v;
        printf("%10s", $v ? $m($v) : '');
    }
    printf("%12s\n", $m($t));
}
echo str_repeat('-', 41 + 10 * count($years) + 12), "\n";
printf("%-41s", 'CATEGORISED SPENDING');
$gt = 0;
foreach ($years as $y) { printf("%10s", $m($colTot[$y] ?? 0)); $gt += $colTot[$y] ?? 0; }
printf("%12s\n", $m($gt));

printf("%-41s", 'unmatched — needs review');
$ut = 0;
foreach ($years as $y) { printf("%10s", $m($unmatched[$y] ?? 0)); $ut += $unmatched[$y] ?? 0; }
printf("%12s\n", $m($ut));

printf("%-41s", 'transfers — NOT spending');
$tt = 0;
foreach ($years as $y) { printf("%10s", $m($transfers[$y] ?? 0)); $tt += $transfers[$y] ?? 0; }
printf("%12s\n", $m($tt));

printf("\ncategorised %.0f%% of non-transfer spending\n", 100 * $gt / max(1, $gt + $ut));

echo "\nBIGGEST UNMATCHED DESCRIPTORS\n";
uasort($unmatchedBy, static fn($a, $b) => $b[1] <=> $a[1]);
$n = 0;
foreach ($unmatchedBy as $d => [$cnt, $c]) {
    printf("  %4d  %10s   %s\n", $cnt, $m($c), substr($d, 0, 46));
    if (++$n >= 25) { break; }
}
