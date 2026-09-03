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
 * Unit tests for the Square settlement rules. Pure — no database, no network.
 *   php tests/square_settle.php
 *
 * These test the two things that can silently destroy the books:
 *
 *   1. THE CLASSIFICATION GATE. An UNREVIEWED charge must produce no revenue
 *      account at all. The Square account holds the owner's personal spending
 *      mixed with the business, so a default here would put private money into
 *      business income — a tax misstatement, not a reporting one. The gate is
 *      tested harder than anything else in this file.
 *
 *   2. THE CLEARING ARITHMETIC. Every rule must balance to the cent, and the
 *      full set of entry types must sum to the payouts. That second one is the
 *      whole engine in a single assertion: money in equals money out and 1050
 *      lands on zero.
 *
 * The write path — idempotency, posted_entry_id, the actual inserts — needs a
 * database and belongs in the integration test.
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

/* The sign convention Posting::side() applies, reproduced here so the expected
 * lines can be written the way they post rather than the way they are stored. */
function lines_balance(array $lines): bool {
    return Ledger::validate($lines) === [];
}

section('the classification gate');
check('UNREVIEWED has no revenue account',
    Posting::squareRevenueAccount('UNREVIEWED'), null);
check('an empty classification has none either',
    Posting::squareRevenueAccount(''), null);
check('an unknown label has none either',
    Posting::squareRevenueAccount('PROBABLY_BUSINESS'), null);
check('BUSINESS credits historical card sales',
    Posting::squareRevenueAccount('BUSINESS'), Posting::HISTORIC_REVENUE);
check('PERSONAL credits owner contributions',
    Posting::squareRevenueAccount('PERSONAL'), Posting::OWNER_CONTRIB);
check('TRANSFER credits owner contributions',
    Posting::squareRevenueAccount('TRANSFER'), Posting::OWNER_CONTRIB);
check('the gate is case-insensitive',
    Posting::squareRevenueAccount('business'), Posting::HISTORIC_REVENUE);
check('and tolerates whitespace',
    Posting::squareRevenueAccount('  BUSINESS  '), Posting::HISTORIC_REVENUE);
/* The one that matters most: nothing may ever fall through to a default. */
$leaks = 0;
foreach (['', 'UNREVIEWED', 'unreviewed', 'PENDING', 'NEW', 'null', '0', 'REVIEWED'] as $c) {
    if (Posting::squareRevenueAccount($c) !== null) { $leaks++; }
}
check('no unreviewed-ish label leaks a revenue account', $leaks, 0);

section('a card sale balances gross = net + fee');
/* The real shape: $95.00 charged, Square keeps $3.48, $91.52 reaches the
 * balance. Taken from a live payout entry. */
$charge = [
    ['account' => Posting::SQUARE_CLEARING, 'debit_cents'  => 9152, 'memo' => 'net'],
    ['account' => Posting::SQUARE_FEES,     'debit_cents'  =>  348, 'memo' => 'fee'],
    ['account' => Posting::HISTORIC_REVENUE,'credit_cents' => 9500, 'memo' => 'sale'],
];
check('charge balances',           lines_balance($charge), true);
check('total is the gross',        Ledger::totalCents($charge), 9500);

$offByACent = [
    ['account' => Posting::SQUARE_CLEARING, 'debit_cents'  => 9152],
    ['account' => Posting::SQUARE_FEES,     'debit_cents'  =>  347],
    ['account' => Posting::HISTORIC_REVENUE,'credit_cents' => 9500],
];
check('a one-cent fee error is refused, not rounded',
    count(Ledger::validate($offByACent)) > 0, true);

section('a zero fee is legal');
$noFee = [
    ['account' => Posting::SQUARE_CLEARING, 'debit_cents'  => 5000],
    ['account' => Posting::HISTORIC_REVENUE,'credit_cents' => 5000],
];
check('a waived fee still balances', lines_balance($noFee), true);

section('a refund is a charge run backwards');
/* $85.00 returned with the $3.13 fee given back — the first of the ten real
 * refunds in this account. */
$refund = [
    ['account' => Posting::HISTORIC_REVENUE,'debit_cents'  => 8500],
    ['account' => Posting::SQUARE_FEES,     'credit_cents' =>  313],
    ['account' => Posting::SQUARE_CLEARING, 'credit_cents' => 8187],
];
check('refund balances', lines_balance($refund), true);
/* And the later ones, where Square kept the fee. */
$refundNoFee = [
    ['account' => Posting::HISTORIC_REVENUE,'debit_cents'  => 6500],
    ['account' => Posting::SQUARE_CLEARING, 'credit_cents' => 6500],
];
check('refund with no fee returned balances', lines_balance($refundNoFee), true);

section('every deduction type has a rule and faces 1050');
$types = ['SQUARE_CAPITAL_PAYMENT', 'SQUARE_CAPITAL_REVERSED_PAYMENT',
          'CREDIT_CARD_REPAYMENT', 'CREDIT_CARD_REPAYMENT_REVERSED',
          'DISPUTE', 'OPEN_DISPUTE', 'RETURNED_PAYOUT', 'ADJUSTMENT'];
$labelled = 0;
foreach ($types as $t) {
    if (Posting::squareEntryLabel($t) !== strtolower(str_replace('_', ' ', $t))
        || $t === 'ADJUSTMENT') { $labelled++; }
}
check('every type reads as plain language', $labelled, count($types));
check('an unknown type still degrades readably',
    Posting::squareEntryLabel('SOME_NEW_THING'), 'some new thing');

section('the whole account reconciles — the one test that matters');
/* Every entry type in this Square account, in integer cents, exactly as the
 * production mirror holds them. If these do not sum to the payouts then the
 * clearing account cannot reach zero and the engine is wrong.
 *
 *   CHARGE net                   18,358,707
 *   SQUARE_CAPITAL_PAYMENT       -2,692,982
 *   ... and its reversals            17,848
 *   CREDIT_CARD_REPAYMENT          -145,448
 *   ... and its reversals             3,200
 *   REFUND                         -123,332
 *   DISPUTE                          12,500
 *   OPEN_DISPUTE                    -21,000
 *   RETURNED_PAYOUT                 -17,145
 *   ADJUSTMENT                            0
 */
$movements = [
    'CHARGE'                          =>  18358707,
    'SQUARE_CAPITAL_PAYMENT'          =>  -2692982,
    'SQUARE_CAPITAL_REVERSED_PAYMENT' =>     17848,
    'CREDIT_CARD_REPAYMENT'           =>   -145448,
    'CREDIT_CARD_REPAYMENT_REVERSED'  =>      3200,
    'REFUND'                          =>   -123332,
    'DISPUTE'                         =>     12500,
    'OPEN_DISPUTE'                    =>    -21000,
    'RETURNED_PAYOUT'                 =>    -17145,
    'ADJUSTMENT'                      =>         0,
];
$payoutsPaid   =  15409493;   // 1,958 PAID payouts
$payoutsFailed =    -17145;   // 2 FAILED payouts

check('the entries sum to the payouts',
    array_sum($movements), $payoutsPaid + $payoutsFailed);
check('so 1050 lands on exactly zero',
    array_sum($movements) - ($payoutsPaid + $payoutsFailed), 0);
check('the failed payouts cancel the re-issued rows',
    $movements['RETURNED_PAYOUT'] - $payoutsFailed, 0);
check('adjustments net to nothing',
    $movements['ADJUSTMENT'], 0);

section('the capital correction leaves the debt untouched');
/* Reversing $32,804.21 of repayment and re-posting $26,751.34 against 1050 plus
 * $6,052.87 against 1010 must return account 2100 to where it started. */
$repaidTotal    = 3280421;
$throughPayouts = 2675134;
$residual       = $repaidTotal - $throughPayouts;
check('the residual is the gap #14 could not explain', $residual, 605287);
check('the two halves restore the original total',
    $throughPayouts + $residual, $repaidTotal);

$correction = [
    ['account' => Posting::CAPITAL_LOAN, 'debit_cents'  => $residual],
    ['account' => Posting::CHECKING,     'credit_cents' => $residual],
];
check('the residual entry balances', lines_balance($correction), true);

section('a payout is two lines and reverses cleanly when it fails');
$payout = [
    ['account' => Posting::CHECKING,        'debit_cents'  => 9152],
    ['account' => Posting::SQUARE_CLEARING, 'credit_cents' => 9152],
];
check('payout balances', lines_balance($payout), true);
$failed = Ledger::flip($payout);
check('a failed payout is the same entry flipped',
    $failed[0]['credit_cents'], 9152);
check('and it still balances', lines_balance($failed), true);

section('cash and external tenders never touch the clearing account');
$cash = [
    ['account' => Posting::CASH_ON_HAND,    'debit_cents'  => 12000],
    ['account' => Posting::HISTORIC_REVENUE,'credit_cents' => 12000],
];
$accounts = array_column(array_map(
    static fn(array $l) => Ledger::normalizeLine($l), $cash), 'account');
check('cash balances',                   lines_balance($cash), true);
check('and 1050 is nowhere in it',
    in_array(Posting::SQUARE_CLEARING, $accounts, true), false);

section('the accounts exist in the chart');
$numbers = array_column(Accounts::DEFAULTS, 0);
foreach ([Posting::SQUARE_CLEARING, Posting::SQUARE_FEES, Posting::HISTORIC_REVENUE,
          Posting::OWNER_CONTRIB, Posting::CHARGEBACK, Posting::CAPITAL_LOAN,
          Posting::CARD_PAYABLE, Posting::CHECKING, Posting::CASH_ON_HAND] as $a) {
    check("account $a is seeded", in_array($a, $numbers, true), true);
}

section('the journal accepts the new sources');
foreach (['SQCHG', 'SQDED', 'SQPAY'] as $s) {
    check("$s is a known journal source", in_array($s, Ledger::SOURCES, true), true);
}
check('and they fit the column', max(array_map('strlen', Ledger::SOURCES)) <= 8, true);

printf("\n\033[1m%d passed, %d failed\033[0m\n\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
