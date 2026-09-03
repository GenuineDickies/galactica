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
 * Run every ledger report against the live database and print what it says.
 *
 *   php data/books_check.php            headline from each report
 *   php data/books_check.php --full     with the rows
 *
 * Read-only. It exists because a report is easy to render and hard to trust:
 * seeing the trial balance actually square, against real data, is the check
 * that matters, and doing it from the CLI beats squinting at a browser.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$full = in_array('--full', array_slice($argv, 1), true);

foreach (array_keys(LedgerReports::REPORTS) as $key) {
    $r = LedgerReports::run($key, [
        'account' => '1100',
        'from'    => '2000-01-01',
        'to'      => date('Y-m-d'),
    ]);

    printf("\n\033[1m%s\033[0m  (%s)\n", $r['title'], $key);
    if ($r['subtitle'] !== '') { printf("  %s\n", $r['subtitle']); }
    printf("  %d row%s · %s\n", count($r['rows']), count($r['rows']) === 1 ? '' : 's',
        $r['ok'] ? "\033[32mok\033[0m" : "\033[31mNOT OK\033[0m");

    if ($full && $r['rows']) {
        foreach (array_slice($r['rows'], 0, 12) as $row) {
            echo '    ' . implode('  |  ', array_map(static fn($c) => (string) $c, $row)) . "\n";
        }
        if (count($r['rows']) > 12) { printf("    … %d more\n", count($r['rows']) - 12); }
    }
    if (!empty($r['totals'])) {
        echo "    TOTAL: " . implode('  |  ', array_filter(array_map(static fn($c) => (string) $c, $r['totals']))) . "\n";
    }
    if ($r['note'] !== '') { printf("  note: %s\n", wordwrap($r['note'], 96, "\n        ")); }
}

echo "\n";
printf("Trial balance squares: %s\n", Ledger::trialBalanceIsSquare() ? 'YES' : 'NO');
exit(0);
