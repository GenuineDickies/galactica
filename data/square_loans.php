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
 * Square Capital advances — the figures the API does not expose.
 *
 *   php data/square_loans.php                 what is recorded
 *   php data/square_loans.php --seed          write the known advances
 *   php data/square_loans.php --reconcile     compare against the payout entries
 *
 * WHY THESE ARE HARD-CODED RATHER THAN FETCHED.
 *
 * Square publishes no API for Capital. The advance amount, the fee and the
 * total owed exist only on one dashboard page per advance. They were read off
 * those pages and written down here, which makes this file a RECORD rather
 * than a derivation — and means it must be updated by hand when a new advance
 * is taken.
 *
 * WHY THE FEE RATIO MATTERS. A Square Capital advance is not an
 * interest-bearing loan: one fixed fee, repaid as a share of daily sales.
 * There is no amortisation schedule, so every repayment splits on a single
 * ratio fixed at origination — fee ÷ total owed. Get that ratio wrong and the
 * error lands in a deductible expense account.
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

$args = array_slice($argv, 1);
$has  = static fn(string $f): bool => in_array($f, $args, true);

/* [plan, amount, fee, total owed, paid off on, status]
 * Read from the Square dashboard, one page per advance, 2026-08-18. */
const ADVANCES = [
    ['P-4PDFG8', 5520.00, 889.00, 6409.00, null,         'OPEN'],
    ['P-54YAZK', 3940.00, 611.00, 4551.00, '2026-04-26', 'PAID'],
    ['P-70MHAB', 3060.00, 493.00, 3553.00, '2026-01-19', 'PAID'],
    ['P-6H040D', 1590.00, 258.00, 1848.00, '2025-09-04', 'PAID'],
    ['P-1CV697', 2760.00, 447.00, 3207.00, '2025-06-13', 'PAID'],
    ['P-2K814D', 3960.00, 576.00, 4536.00, '2024-09-22', 'PAID'],
    ['P-17HV8G', 4930.00, 725.00, 5655.00, '2024-03-09', 'PAID'],
    ['P-1RDHRG', 1610.00, 229.00, 1839.00, '2022-12-08', 'PAID'],
    ['P-1C1QAV',  800.00,  82.00,  882.00, '2022-08-18', 'PAID'],
    ['P-1ZJDVZ', 1550.00, 159.00, 1709.00, '2021-11-28', 'PAID'],
    ['P-3V1S2Y', 1950.00, 200.00, 2150.00, '2021-06-11', 'PAID'],
];

if ($has('--seed')) {
    $n = 0;
    foreach (ADVANCES as [$plan, $amt, $fee, $owed, $paidOn, $status]) {
        $existing = Db::one('SELECT id FROM square_loans WHERE plan_id = ?', [$plan]);
        $data = [
            'loan_amount' => number_format($amt, 2, '.', ''),
            'loan_fee'    => number_format($fee, 2, '.', ''),
            'total_owed'  => number_format($owed, 2, '.', ''),
            'total_paid'  => number_format($status === 'PAID' ? $owed : 2874.21, 2, '.', ''),
            'balance'     => number_format($status === 'PAID' ? 0 : 3534.79, 2, '.', ''),
            'status'      => $status,
            'paid_off_on' => $paidOn,
            'updated_at'  => now(),
        ];
        if ($existing) {
            Db::update('square_loans', (int) $existing['id'], $data);
        } else {
            Db::insert('square_loans', $data + ['plan_id' => $plan, 'created_at' => now()]);
        }
        $n++;
    }
    Audit::log('system', 0, 'square:loans-recorded', $n . ' advances');
    printf("%d advances recorded.\n\n", $n);
}

/* ---- the summary ------------------------------------------------------ */
$rows = Db::all('SELECT * FROM square_loans ORDER BY paid_off_on IS NULL DESC, paid_off_on DESC');
if (!$rows) { echo "Nothing recorded. Run with --seed.\n"; exit(0); }

printf("%-10s %12s %10s %12s %8s  %-10s %s\n",
    'PLAN', 'ADVANCED', 'FEE', 'TOTAL OWED', 'FEE %', 'PAID OFF', 'STATUS');
echo str_repeat('-', 84), "\n";

$amt = 0; $fee = 0; $owed = 0;
foreach ($rows as $r) {
    $a = Markup::toCents($r['loan_amount']);
    $f = Markup::toCents($r['loan_fee']);
    $o = Markup::toCents($r['total_owed']);
    $amt += $a; $fee += $f; $owed += $o;
    printf("%-10s %12s %10s %12s %7.2f%%  %-10s %s\n",
        $r['plan_id'], money($r['loan_amount']), money($r['loan_fee']), money($r['total_owed']),
        $o > 0 ? ($f / $o) * 100 : 0, $r['paid_off_on'] ?: '—', $r['status']);
}
echo str_repeat('-', 84), "\n";
printf("%-10s %12s %10s %12s %7.2f%%\n", count($rows) . ' advances',
    money(Markup::centsToStr($amt)), money(Markup::centsToStr($fee)), money(Markup::centsToStr($owed)),
    $owed > 0 ? ($fee / $owed) * 100 : 0);

/* ---- --reconcile ------------------------------------------------------ */
if ($has('--reconcile')) {
    $paidPerLedger = (int) Db::val(
        "SELECT COALESCE(SUM(gross_amount),0) * -100 FROM square_payout_entries
         WHERE entry_type IN ('SQUARE_CAPITAL_PAYMENT','SQUARE_CAPITAL_REVERSED_PAYMENT')", [], 0
    );
    $outstanding = (int) Db::val("SELECT COALESCE(SUM(balance),0)*100 FROM square_loans", [], 0);
    $shouldHavePaid = $owed - $outstanding;

    echo "\nRECONCILIATION\n";
    printf("  Total obligation across all advances   %14s\n", money(Markup::centsToStr($owed)));
    printf("  Still outstanding                      %14s\n", money(Markup::centsToStr($outstanding)));
    printf("  Therefore repaid to date               %14s\n", money(Markup::centsToStr($shouldHavePaid)));
    printf("  Seen in payout entries                 %14s\n", money(Markup::centsToStr($paidPerLedger)));
    printf("  Difference                             %14s\n",
        money(Markup::centsToStr($shouldHavePaid - $paidPerLedger)));
    /* WHERE the difference sits decides how to post it. If it is concentrated
     * in the years before payout entries exist, one opening entry per advance
     * covers it honestly. If it is spread evenly across years that DO have
     * entries, something else is going on and posting would bake in an error. */
    echo "\nCAPITAL REPAYMENTS SEEN, BY YEAR\n";
    foreach (Db::all(
        "SELECT SUBSTRING(effective_at,1,4) y, COUNT(*) n,
                COALESCE(SUM(gross_amount),0) * -1 t
         FROM square_payout_entries
         WHERE entry_type IN ('SQUARE_CAPITAL_PAYMENT','SQUARE_CAPITAL_REVERSED_PAYMENT')
         GROUP BY SUBSTRING(effective_at,1,4) ORDER BY y") as $r) {
        printf("  %-6s %5d payments  %12s\n", $r['y'], (int) $r['n'], money($r['t']));
    }

    $first = (string) Db::val(
        "SELECT MIN(effective_at) FROM square_payout_entries
         WHERE entry_type = 'SQUARE_CAPITAL_PAYMENT'", [], ''
    );
    printf("\n  Earliest capital repayment seen: %s\n", substr($first, 0, 10) ?: 'none');

    echo "\n  Loans that closed BEFORE that date were repaid where no entry data\n";
    echo "  exists, and account for the bulk of any difference:\n";
    foreach (Db::all(
        "SELECT plan_id, total_owed, paid_off_on FROM square_loans
         WHERE paid_off_on IS NOT NULL AND paid_off_on < ? ORDER BY paid_off_on",
        [substr($first, 0, 10) ?: '1970-01-01']) as $r) {
        printf("    %-10s %10s  closed %s\n", $r['plan_id'], money($r['total_owed']), $r['paid_off_on']);
    }

    echo "\n  A difference is expected, not alarming: payout entries only exist from\n";
    echo "  Dec 2020, repayments can also be taken from the Square balance without\n";
    echo "  passing through a payout, and the 60-day minimum can be paid directly.\n";
    echo "  It is worth understanding before any of this posts to the ledger.\n";
}

/* ---- --post ----------------------------------------------------------- */
if ($has('--post')) {
    $dry = $has('--dry-run');
    echo "\n" . ($dry ? "DRY RUN — nothing will be written.\n" : "Posting to the ledger.\n") . "\n";

    $before2100 = Ledger::balanceCents(Posting::CAPITAL_LOAN);
    $before7030 = Ledger::balanceCents(Posting::CAPITAL_FEE);
    $posted = 0; $skipped = 0;

    foreach (Db::all('SELECT * FROM square_loans ORDER BY paid_off_on IS NULL, paid_off_on') as $l) {
        if ($l['posted_entry_id'] !== null) { $skipped++; continue; }

        $owed    = Markup::toCents($l['total_owed']);
        $balance = Markup::toCents($l['balance']);
        $repaid  = $owed - $balance;

        printf("  %-10s advance %10s  fee %9s  repaid %11s  leaves %10s\n",
            $l['plan_id'], money($l['loan_amount']), money($l['loan_fee']),
            money(Markup::centsToStr($repaid)), money($l['balance']));

        if ($dry) { $posted++; continue; }

        try {
            $ids = Posting::capitalAdvance($l);
            if ($ids['origination'] !== null) {
                Db::update('square_loans', (int) $l['id'], ['posted_entry_id' => $ids['origination']]);
                $posted++;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '  · ' . $l['plan_id'] . ': ' . $e->getMessage() . "\n");
        }
    }

    printf("\n%s%d advance%s%s · %d already posted\n",
        $dry ? 'Would post ' : 'Posted ', $posted, $posted === 1 ? '' : 's',
        $dry ? '' : '', $skipped);

    if (!$dry) {
        $after2100 = Ledger::balanceCents(Posting::CAPITAL_LOAN);
        $after7030 = Ledger::balanceCents(Posting::CAPITAL_FEE);
        $expected  = (int) Db::val("SELECT COALESCE(SUM(balance),0)*100 FROM square_loans", [], 0);

        echo "\nAFTER POSTING\n";
        printf("  2100 Square Capital Loan      %14s\n", money(Markup::centsToStr($after2100 - $before2100)));
        printf("  Square says still outstanding %14s\n", money(Markup::centsToStr($expected)));
        printf("  %s\n", ($after2100 - $before2100) === $expected
            ? '  ✓ the liability matches the dashboard'
            : '  ✗ MISMATCH — investigate before trusting the balance sheet');
        printf("  7030 Financing Interest & Fees %13s\n", money(Markup::centsToStr($after7030 - $before7030)));
        printf("\n  Trial balance squares: %s\n", Ledger::trialBalanceIsSquare() ? 'yes' : 'NO');
    } else {
        echo "\nNothing was written. Re-run without --dry-run to post.\n";
    }
    exit(0);
}

echo "\nThe fee is expensed at origination (7030); the advance and its repayments\n";
echo "move through 2100. Run --post --dry-run to preview.\n";
exit(0);
