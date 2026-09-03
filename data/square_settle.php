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
 * Square settlement — take the mirrored history to the ledger.
 *
 *   php data/square_settle.php                 what would post, and what blocks it
 *   php data/square_settle.php --commit        actually post it
 *   php data/square_settle.php --fix-capital   correct the #14 repayment entries
 *   php data/square_settle.php --from 2021-01-01 --to 2021-12-31
 *
 * Run data/square_import_deposits.php first if the payouts API does not reach
 * back over the whole period — see that file for why it has to exist.
 *
 * DRY RUN IS THE DEFAULT AND THAT IS DELIBERATE. This walks six years of money
 * and writes several thousand journal entries. A flag that has to be typed to
 * write anything is cheap; discovering afterwards that a run posted personal
 * spending as business income is not.
 *
 * WHAT IT REFUSES TO DO. It will not commit while any charge in range is still
 * UNREVIEWED. The Square account carries the owner's personal spending mixed in
 * with the business, and the classification at /square is the only thing that
 * tells them apart. Posting an unclassified charge would be a tax misstatement
 * dressed up as a tidy report, so an unclassified charge stops the run rather
 * than being skipped quietly.
 *
 * ORDER MATTERS. Charges and deductions move money INTO and around 1050;
 * payouts take it out. Posting payouts first would show the clearing account
 * deeply negative in between — briefly wrong rather than lastingly wrong, but
 * a run that dies halfway would leave it that way. So everything that fills
 * 1050 posts before anything that empties it.
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
Accounts::ensureSeeded();

$args  = array_slice($argv, 1);
$has   = static fn(string $f): bool => in_array($f, $args, true);
$value = static function (string $f) use ($args): ?string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string) $args[$i + 1] : null;
};

$commit = $has('--commit');
$from   = $value('--from') ?? '';
$to     = $value('--to') ?? '';
$m      = static fn(int $c): string => money(Markup::centsToStr($c));

/* Date filter shared by every query below, so --from/--to mean the same thing
 * everywhere: the date the money moved, not the date it was imported. */
$range = static function (string $col) use ($from, $to): array {
    $sql = ''; $args = [];
    if ($from !== '') { $sql .= " AND $col >= ?"; $args[] = $from . ' 00:00:00'; }
    if ($to   !== '') { $sql .= " AND $col <= ?"; $args[] = $to   . ' 23:59:59'; }
    return [$sql, $args];
};

echo "\n\033[1mSquare settlement\033[0m";
if ($from !== '' || $to !== '') { echo '  ' . ($from ?: 'start') . ' → ' . ($to ?: 'now'); }
echo $commit ? "  \033[31mCOMMITTING\033[0m\n" : "  (dry run — nothing will be written)\n";

/* ---- --fix-capital ----------------------------------------------------- */
if ($has('--fix-capital')) {
    $throughPayouts = -(int) round((float) Db::val(
        "SELECT COALESCE(SUM(net_amount),0) FROM square_payout_entries
         WHERE entry_type IN ('SQUARE_CAPITAL_PAYMENT','SQUARE_CAPITAL_REVERSED_PAYMENT')",
        [], 0) * 100);

    $repaid = (int) Db::val(
        "SELECT COALESCE(SUM(total_cents),0) FROM journal_entries
         WHERE source_type = 'ADJ' AND is_reversal = 0 AND reversed_by_id IS NULL
           AND memo LIKE '%repaid%'", [], 0);

    echo "\nCAPITAL REPAYMENT CORRECTION\n";
    printf("  repaid per the loan statements      %14s\n", $m($repaid));
    printf("  of which visible in payout entries  %14s   → moves to 1050\n", $m($throughPayouts));
    printf("  residual, never in a payout         %14s   → stays on 1010\n", $m($repaid - $throughPayouts));

    if (!$commit) {
        echo "\n  Add --commit to reverse the old entries and re-post the split.\n\n";
        exit(0);
    }

    $before = Ledger::balanceCents(Posting::CAPITAL_LOAN);
    $res    = Posting::capitalRepaymentCorrection($throughPayouts);
    $after  = Ledger::balanceCents(Posting::CAPITAL_LOAN);

    /* 2100 IS EXPECTED TO MOVE HERE, AND TO MOVE BACK LATER.
     *
     * Reversing the repayments hands the whole $32,804.21 back to the debt;
     * only the residual is re-posted now. The other $26,751.34 arrives as the
     * settlement run posts each SQUARE_CAPITAL_PAYMENT entry against 1050. So
     * the balance is deliberately overstated between these two runs, and the
     * check that means anything is whether it lands where it started once they
     * have posted. An earlier version asserted "unchanged" right here and
     * failed the moment it was first run — correctly reporting a problem with
     * the assertion rather than with the books. */
    $expected = $before + $throughPayouts;

    printf("\n  reversed %d entr%s\n", $res['reversed'], $res['reversed'] === 1 ? 'y' : 'ies');
    printf("  2100 was %s, now %s\n", $m($before), $m($after));
    printf("  %s\n", $after === $expected
        ? "  \033[32m✓ as expected — it returns to " . $m($before)
          . " when the capital deductions post\033[0m"
        : "  \033[31m✗ expected " . $m($expected) . " at this stage — investigate\033[0m");
    printf("  trial balance squares: %s\n\n", Ledger::trialBalanceIsSquare() ? 'yes' : 'NO');
    exit($after === $expected ? 0 : 1);
}

/* ---- the main walk ----------------------------------------------------- */

[$entryWhere, $entryArgs] = $range('e.effective_at');
[$payWhere,   $payArgs]   = $range('t.occurred_at');

/* CHARGES. Joined to the payment that carries the classification — an entry
 * whose payment is missing cannot be posted, and saying so is more useful than
 * skipping it. */
$charges = Db::all(
    "SELECT e.*, t.classification, t.occurred_at, t.square_id, t.status AS pay_status
     FROM square_payout_entries e
     LEFT JOIN square_transactions t
            ON t.square_id = e.related_square_id AND t.object_type = 'PAYMENT'
     WHERE e.entry_type = 'CHARGE' AND e.posted_entry_id IS NULL $entryWhere
     ORDER BY t.occurred_at, e.id",
    $entryArgs
);

$unclassified = 0; $orphan = 0; $chargeCents = 0; $feeCents = 0;
foreach ($charges as $c) {
    if ($c['square_id'] === null) { $orphan++; continue; }
    if (Posting::squareRevenueAccount((string) $c['classification']) === null) { $unclassified++; continue; }
    $chargeCents += Markup::toCents($c['net_amount']);
    $feeCents    += Markup::toCents($c['fee_amount']);
}

/* DEDUCTIONS. Everything that is not a charge. No classification needed: the
 * TYPE determines the treatment, which is why these were never gated. */
$deductions = Db::all(
    "SELECT e.* FROM square_payout_entries e
     WHERE e.entry_type <> 'CHARGE' AND e.posted_entry_id IS NULL $entryWhere
     ORDER BY e.effective_at, e.id",
    $entryArgs
);

/* UNSETTLED. Cash and external tenders recorded in Square that never moved
 * through it — no fee, no payout, and they must not touch 1050. */
$unsettled = Db::all(
    "SELECT t.* FROM square_transactions t
     WHERE t.object_type = 'PAYMENT' AND t.status = 'COMPLETED'
       AND t.source_type IN ('CASH','EXTERNAL') AND t.posted_entry_id IS NULL $payWhere
     ORDER BY t.occurred_at",
    $payArgs
);

/* PAYOUTS. Last, because they empty what the rows above fill. */
$payouts = Db::all(
    "SELECT t.* FROM square_transactions t
     WHERE t.object_type = 'PAYOUT' AND t.posted_entry_id IS NULL $payWhere
     ORDER BY t.occurred_at",
    $payArgs
);

$dedCents = 0;
foreach ($deductions as $d) { $dedCents += Markup::toCents($d['net_amount']); }
$payCents = 0;
foreach ($payouts as $p) { $payCents += Markup::toCents($p['amount']); }
$unsCents = 0;
foreach ($unsettled as $u) { $unsCents += Markup::toCents($u['amount']); }

echo "\nWHAT IS WAITING\n";
printf("  %-34s %6d  %14s\n", 'card sales (net into 1050)', count($charges), $m($chargeCents));
printf("  %-34s %6s  %14s\n", '  of which Square fees to 7010', '', $m($feeCents));
printf("  %-34s %6d  %14s\n", 'deductions before payout', count($deductions), $m($dedCents));
printf("  %-34s %6d  %14s\n", 'payouts to Checking', count($payouts), $m($payCents));
printf("  %-34s %6d  %14s\n", 'cash / external (never at Square)', count($unsettled), $m($unsCents));

$residual = $chargeCents + $dedCents - $payCents;
printf("\n  %-34s %6s  %14s\n", 'would leave in 1050', '', $m($residual));

if ($orphan > 0) {
    printf("\n  \033[31m%d charge entr%s point at a payment that is not in the mirror.\033[0m\n",
        $orphan, $orphan === 1 ? 'y does' : 'ies do');
    echo "  Re-run data/square_sync.php before settling — a charge with no payment\n";
    echo "  behind it has no classification, and so no revenue account.\n";
}

if ($unclassified > 0) {
    printf("\n  \033[31m%d charge%s still UNREVIEWED.\033[0m Nothing will post until they are classified.\n",
        $unclassified, $unclassified === 1 ? ' is' : 's are');
    echo "  Review them at /square, or bulk-mark with data/square_classify.php --business\n";
    echo "  once you have looked through the lenses there.\n";
}

if (!$commit) {
    echo "\nDry run. Add --commit to post.\n\n";
    exit(0);
}

if ($unclassified > 0 || $orphan > 0) {
    fwrite(STDERR, "\nRefusing to commit while charges are unclassified or orphaned.\n\n");
    exit(1);
}

/* ---- write ------------------------------------------------------------- */

$before = Ledger::balanceCents(Posting::SQUARE_CLEARING);
$posted = ['charge' => 0, 'deduction' => 0, 'unsettled' => 0, 'payout' => 0];
$failed = 0;

$run = static function (string $bucket, array $rows, callable $fn) use (&$posted, &$failed): void {
    $n = count($rows);
    foreach ($rows as $i => $row) {
        try {
            if ($fn($row) !== null) { $posted[$bucket]++; }
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDERR, sprintf("  · %s: %s\n", $bucket, $e->getMessage()));
        }
        if ($n > 200 && ($i + 1) % 250 === 0) {
            printf("  %s %d/%d\n", $bucket, $i + 1, $n);
        }
    }
};

echo "\nPOSTING\n";
$run('charge', $charges, static fn(array $c) => Posting::squareCharge($c, [
    'classification' => $c['classification'],
    'occurred_at'    => $c['occurred_at'],
    'square_id'      => $c['square_id'],
]));
$run('deduction', $deductions, static fn(array $d) => Posting::squareDeduction($d));
$run('unsettled', $unsettled,  static fn(array $u) => Posting::squareUnsettled($u));
$run('payout',    $payouts,    static fn(array $p) => Posting::squarePayout($p));

$after = Ledger::balanceCents(Posting::SQUARE_CLEARING);

echo "\nRESULT\n";
foreach ($posted as $k => $n) { printf("  %-12s %6d posted\n", $k, $n); }
if ($failed > 0) { printf("  \033[31m%-12s %6d failed\033[0m\n", 'errors', $failed); }

printf("\n  1050 Square Clearing  %s → %s\n", $m($before), $m($after));
printf("  %s\n", $after === 0
    ? "  \033[32m✓ the clearing account is empty — every sale followed to the bank\033[0m"
    : '  ' . $m($after) . ' still at Square (correct only for sales not yet paid out)');
printf("  trial balance squares: %s\n\n", Ledger::trialBalanceIsSquare() ? 'yes' : 'NO');

exit($failed > 0 ? 1 : 0);
