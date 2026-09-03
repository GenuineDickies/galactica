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
 * Rebuild missing payout history from a Square transactions CSV export.
 *
 *   php data/square_import_deposits.php <file.csv>            what it would add
 *   php data/square_import_deposits.php <file.csv> --commit   add it
 *
 * WHY THIS EXISTS. Square's Payouts API does not reach back far enough. In this
 * account it begins 2020-12-30, and every card sale before that settled to the
 * bank leaving no payout record the API will admit to. Without them a year of
 * real revenue could only be posted as a single invented aggregate, with a
 * settlement date nobody could point at.
 *
 * The dashboard CSV export has what the API withholds: `Deposit ID` and
 * `Deposit Date` on every row. That is the same fact the API would have given —
 * which sale landed in which transfer, and when — so reconstructing payout rows
 * from it is not a fabrication. It is the same data through a different door,
 * and the raw CSV line is kept on every row so the provenance is never in doubt.
 *
 * SYNTHETIC IDS ARE PREFIXED so nothing can mistake them for API objects:
 * `dep:<DepositID>` for a payout, `csv:<PaymentID>` for a charge entry. A later
 * sync writing genuine API rows cannot collide with them, and one query
 * separates reconstructed history from fetched history forever.
 *
 * WHAT IT REFUSES TO TOUCH. A deposit containing any sale the API already knows
 * about is skipped whole. The two datasets overlap at the boundary — the last
 * two deposits of Dec 2020 also exist in the API — and importing half of a
 * deposit would put money into the clearing account twice. Whole deposits or
 * nothing.
 *
 * It writes to the MIRROR only. Nothing here posts to the ledger; that is
 * data/square_settle.php, which then treats this history exactly like any
 * other, with no special case anywhere in the posting rules.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$args   = array_slice($argv, 1);
$commit = in_array('--commit', $args, true);
$file   = '';
foreach ($args as $a) { if (!str_starts_with($a, '--')) { $file = $a; break; } }

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "\nUsage: php data/square_import_deposits.php <transactions.csv> [--commit]\n\n"
        . "  Export it from the Square dashboard: Reports → Transactions → Export,\n"
        . "  covering the period BEFORE the payouts API starts.\n\n");
    exit(1);
}

/**
 * "$1,234.56", "-$1.13" and "($1.13)" to integer cents.
 *
 * Parsed as a string and handed to Markup::toCents so there is exactly one
 * rounding implementation in this system. A float multiply here would be a
 * second one, disagreeing with the first about a half-cent somewhere in six
 * years of rows.
 */
function csv_cents(?string $s): int
{
    $s = trim((string) $s);
    if ($s === '' || $s === '-') { return 0; }
    $neg = str_starts_with($s, '-') || str_starts_with($s, '(');
    $s   = str_replace(['$', ',', '(', ')', '-', ' '], '', $s);
    if ($s === '') { return 0; }
    $c = Markup::toCents($s);
    return $neg ? -$c : $c;
}

/* ---- read --------------------------------------------------------------- */

$fh = fopen($file, 'r');
if ($fh === false) { fwrite(STDERR, "Cannot read $file\n"); exit(1); }

$header = fgetcsv($fh);
if ($header === false) { fwrite(STDERR, "Empty file.\n"); exit(1); }
/* Excel and the Square export both like a UTF-8 BOM, which silently becomes
 * part of the first column name and makes "Date" unfindable. */
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

$need = ['Payment ID', 'Deposit ID', 'Deposit Date', 'Total Collected', 'Fees', 'Net Total', 'Date', 'Time'];
$idx  = [];
foreach ($need as $col) {
    $i = array_search($col, $header, true);
    if ($i === false) {
        fwrite(STDERR, "This CSV has no '$col' column — is it a Square transactions export?\n");
        exit(1);
    }
    $idx[$col] = $i;
}

$deposits = [];   // deposit id => ['date' => …, 'rows' => [...]]
$zero = 0; $nodep = 0; $read = 0;

while (($row = fgetcsv($fh)) !== false) {
    if ($row === [null] || $row === []) { continue; }
    $read++;
    $get = static fn(string $c): string => trim((string) ($row[$idx[$c]] ?? ''));

    $gross = csv_cents($get('Total Collected'));
    if ($gross === 0) { $zero++; continue; }

    $dep = $get('Deposit ID');
    if ($dep === '') { $nodep++; continue; }

    $deposits[$dep] ??= ['date' => $get('Deposit Date'), 'rows' => []];
    $deposits[$dep]['rows'][] = [
        'payment_id' => $get('Payment ID'),
        'gross'      => $gross,
        /* Fees arrive negative in the export — it is money leaving. Stored
         * positive here to match the API's payout entries, where the fee is a
         * positive amount subtracted from gross. */
        'fee'        => -csv_cents($get('Fees')),
        'net'        => csv_cents($get('Net Total')),
        'at'         => $get('Date') . ' ' . ($get('Time') ?: '00:00:00'),
        'raw'        => array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), '')),
    ];
}
fclose($fh);

/* ---- decide ------------------------------------------------------------- */

$known = [];   // payment ids the API already accounts for
foreach (Db::all("SELECT related_square_id FROM square_payout_entries
                  WHERE entry_type = 'CHARGE' AND related_square_id IS NOT NULL") as $r) {
    $known[(string) $r['related_square_id']] = true;
}

$plan = []; $skipDeposits = 0; $skipCharges = 0; $missing = 0;
foreach ($deposits as $depId => $d) {
    $overlaps = false;
    foreach ($d['rows'] as $r) { if (isset($known[$r['payment_id']])) { $overlaps = true; break; } }
    if ($overlaps) { $skipDeposits++; $skipCharges += count($d['rows']); continue; }
    $plan[$depId] = $d;
}

/* Every charge must reach a payment row in the mirror, because that row is
 * where the classification lives and no revenue can post without it. */
$ids = [];
foreach ($plan as $d) { foreach ($d['rows'] as $r) { $ids[] = $r['payment_id']; } }
$found = [];
foreach (array_chunk($ids, 500) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    foreach (Db::all("SELECT square_id FROM square_transactions
                      WHERE object_type = 'PAYMENT' AND square_id IN ($in)", $chunk) as $r) {
        $found[(string) $r['square_id']] = true;
    }
}
foreach ($ids as $i) { if (!isset($found[$i])) { $missing++; } }

$gross = 0; $fee = 0; $net = 0; $charges = 0;
foreach ($plan as $d) {
    foreach ($d['rows'] as $r) { $gross += $r['gross']; $fee += $r['fee']; $net += $r['net']; $charges++; }
}
$m = static fn(int $c): string => money(Markup::centsToStr($c));

echo "\n\033[1mSquare deposit reconstruction\033[0m  " . basename($file);
echo $commit ? "  \033[31mCOMMITTING\033[0m\n" : "  (dry run)\n";
printf("\n  rows read                        %6d\n", $read);
printf("  zero-amount rows ignored         %6d\n", $zero);
printf("  rows with no deposit             %6d\n", $nodep);
printf("  deposits already in the API      %6d  (%d charges — left alone)\n", $skipDeposits, $skipCharges);
printf("\n  deposits to add                  %6d\n", count($plan));
printf("  charges to add                   %6d\n", $charges);
printf("  gross                            %14s\n", $m($gross));
printf("  Square fees                      %14s\n", $m($fee));
printf("  net into those deposits          %14s\n", $m($net));

if ($gross - $fee !== $net) {
    printf("\n  \033[31mgross - fees != net, off by %s\033[0m — the export is inconsistent, stopping.\n\n",
        $m(abs($gross - $fee - $net)));
    exit(1);
}
printf("  %s\n", "\033[32m✓ gross - fees = net\033[0m");

if ($missing > 0) {
    printf("\n  \033[31m%d charge%s point at a payment that is not in the mirror.\033[0m\n",
        $missing, $missing === 1 ? '' : 's');
    echo "  Run data/square_sync.php first — without the payment row there is no\n";
    echo "  classification, and so no revenue account.\n";
}

if (!$commit) { echo "\nDry run. Add --commit to write.\n\n"; exit(0); }
if ($missing > 0) { fwrite(STDERR, "\nRefusing to commit with missing payment rows.\n\n"); exit(1); }

/* ---- write -------------------------------------------------------------- */

$madeDeposits = 0; $madeCharges = 0; $already = 0;

foreach ($plan as $depId => $d) {
    $payoutId = 'dep:' . $depId;
    $depNet   = 0;
    foreach ($d['rows'] as $r) { $depNet += $r['net']; }
    $when = $d['date'] . ' 00:00:00';

    Db::tx(static function () use ($payoutId, $depId, $depNet, $when, $d, &$madeDeposits, &$madeCharges, &$already) {
        $existing = Db::val('SELECT id FROM square_transactions WHERE square_id = ?', [$payoutId]);
        if ($existing === null) {
            $payoutRowId = Db::insert('square_transactions', [
                'square_id'      => $payoutId,
                'object_type'    => 'PAYOUT',
                'status'         => 'PAID',
                'amount'         => Markup::centsToStr($depNet),
                'net_amount'     => Markup::centsToStr($depNet),
                'currency'       => 'USD',
                'occurred_at'    => $when,
                'classification' => 'UNREVIEWED',
                'note'           => 'Reconstructed from the Square transactions CSV export',
                'raw'            => json_encode(['source' => 'csv', 'deposit_id' => $depId],
                                                JSON_UNESCAPED_SLASHES),
                'first_seen_at'  => now(),
                'last_synced_at' => now(),
            ]);
            $madeDeposits++;
        } else {
            $payoutRowId = (int) $existing;
        }

        foreach ($d['rows'] as $r) {
            $entryId = 'csv:' . $r['payment_id'];
            if (Db::val('SELECT id FROM square_payout_entries WHERE square_entry_id = ?', [$entryId]) !== null) {
                $already++;
                continue;
            }
            Db::insert('square_payout_entries', [
                'square_entry_id'   => $entryId,
                'payout_square_id'  => $payoutId,
                'payout_row_id'     => $payoutRowId,
                'entry_type'        => 'CHARGE',
                /* The SALE's timestamp, not the deposit's. squareCharge() dates
                 * the entry from the payment anyway, and keeping the real one
                 * here means a date filter over this table means what it says. */
                'effective_at'      => $r['at'],
                'gross_amount'      => Markup::centsToStr($r['gross']),
                'fee_amount'        => Markup::centsToStr($r['fee']),
                'net_amount'        => Markup::centsToStr($r['net']),
                'currency'          => 'USD',
                'related_square_id' => $r['payment_id'],
                'raw'               => json_encode(['source' => 'csv'] + $r['raw'], JSON_UNESCAPED_SLASHES),
                'first_seen_at'     => now(),
                'last_synced_at'    => now(),
            ]);
            $madeCharges++;
        }
    });
}

Audit::log('system', 0, 'square:deposits-imported',
    $madeDeposits . ' deposits and ' . $madeCharges . ' charges rebuilt from ' . basename($file));

printf("\n  deposits written                 %6d\n", $madeDeposits);
printf("  charges written                  %6d\n", $madeCharges);
if ($already > 0) { printf("  already present                  %6d\n", $already); }
echo "\n  Next: php data/square_settle.php   to see what it would post.\n\n";
