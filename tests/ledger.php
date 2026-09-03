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
 * Unit tests for the Ledger posting service. Pure — no database, no server.
 *   php tests/ledger.php
 *
 * Only the arithmetic and the rules are exercised here: balance checking,
 * one-side-per-line, reversal flipping, period keys. The write path (post,
 * reverse, trial balance) needs a database and belongs in the integration
 * test alongside pricing_integration.php.
 *
 * The one-cent tests are the point of this file. A ledger that rounds an
 * out-of-balance entry into balance is worse than no ledger, because it
 * looks authoritative and is wrong.
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

/** Shorthand: a debit line and a credit line, amounts in dollar strings. */
function dr(string $acct, string $amt, string $memo = ''): array {
    return ['account' => $acct, 'debit' => $amt, 'memo' => $memo];
}
function cr(string $acct, string $amt, string $memo = ''): array {
    return ['account' => $acct, 'credit' => $amt, 'memo' => $memo];
}

section('a balanced entry is accepted');
/* Sell a battery for $260 with a $22 core: the customer owes $282, of which
 * $260 is earned and $22 is held. The worked example from the docs. */
$invoice = [
    dr('1100', '282.00', 'Accounts Receivable'),
    cr('4010', '260.00', 'Parts Sales Revenue'),
    cr('2050', '22.00',  'Core Deposits Payable'),
];
check('battery + core balances',        Ledger::validate($invoice), []);
check('total is one side, not both',    Ledger::totalCents($invoice), 28200);

section('an unbalanced entry is refused — never rounded');
$offByACent = [dr('1100', '282.00'), cr('4010', '260.00'), cr('2050', '21.99')];
check('one cent short is refused',      count(Ledger::validate($offByACent)) > 0, true);
$offByALot = [dr('1100', '282.00'), cr('4010', '260.00')];
check('missing leg is refused',         count(Ledger::validate($offByALot)) > 0, true);
check('error names the difference',
    str_contains(Ledger::validate($offByACent)[0], '0.01'), true);

section('a line is one side or the other');
$bothSides = [
    ['account' => '1100', 'debit' => '10.00', 'credit' => '10.00'],
    cr('4000', '10.00'),
];
check('debit and credit on one line refused', count(Ledger::validate($bothSides)) > 0, true);
$noAmount = [['account' => '1100'], cr('4000', '10.00')];
check('a line with no amount refused',  count(Ledger::validate($noAmount)) > 0, true);
$negative = [
    ['account' => '1100', 'debit_cents' => -1000],
    ['account' => '4000', 'credit_cents' => -1000],
];
check('negative amounts refused',       count(Ledger::validate($negative)) > 0, true);

section('an entry needs two sides to be an entry');
check('single line refused',            count(Ledger::validate([dr('1100','10.00')])) > 0, true);
check('no lines refused',               count(Ledger::validate([])) > 0, true);

section('account numbers are validated');
$badAcct = [dr('cash', '10.00'), cr('4000', '10.00')];
check('non-numeric account refused',    count(Ledger::validate($badAcct)) > 0, true);
$noAcct  = [dr('', '10.00'), cr('4000', '10.00')];
check('empty account refused',          count(Ledger::validate($noAcct)) > 0, true);

section('integer cents in, integer cents out — no float drift');
/* Three lines that a float would not sum cleanly. */
$thirds = [dr('1100', '0.10'), cr('4000', '0.03'), cr('4010', '0.07')];
check('0.10 = 0.03 + 0.07',             Ledger::validate($thirds), []);
$cents = Ledger::normalizeLine(['account' => '1100', 'debit' => '1234.56']);
check('dollars parse to cents',         $cents['debit_cents'], 123456);
check('credit side defaults to zero',   $cents['credit_cents'], 0);
$explicit = Ledger::normalizeLine(['account' => '2050', 'credit_cents' => 2200]);
check('cents pass through untouched',   $explicit['credit_cents'], 2200);

section('reversal flips every line exactly');
$flipped = Ledger::flip($invoice);
check('debit became a credit',          $flipped[0]['credit_cents'], 28200);
check('…and carries no debit',          $flipped[0]['debit_cents'], 0);
check('credit became a debit',          $flipped[1]['debit_cents'], 26000);
check('account is unchanged',           $flipped[2]['account'], '2050');
check('the reversal itself balances',   Ledger::validate($flipped), []);
check('flipping twice is the original',
    Ledger::flip($flipped)[0]['debit_cents'], 28200);

/* reverse() feeds stored journal_lines rows back through normalizeLine, and
 * those call the column account_number, not account. Without the alias every
 * reversal normalised to a blank account and was refused as unbalanced. */
$storedRow = ['account_number' => '2050', 'debit_cents' => 0, 'credit_cents' => 2200];
check('a stored row keeps its account',
    Ledger::normalizeLine($storedRow)['account'], '2050');
check('a stored row flips',             Ledger::flip([$storedRow])[0]['debit_cents'], 2200);

section('the full core lifecycle nets 2050 to zero');
/* Charged on the invoice, refunded to the customer, credited by the supplier.
 * Summed across all three entries, Core Deposits Payable must be flat. */
$coreCharged  = [dr('1100','282.00'), cr('4010','260.00'), cr('2050','22.00')];
$coreRefunded = [dr('2050','22.00'),  cr('1010','22.00')];
$coreCredited = [dr('1010','22.00'),  cr('2050','22.00')];
$supplierPaid = [dr('2050','22.00'),  cr('1010','22.00')];

$net2050 = 0;
foreach ([$coreCharged, $coreRefunded, $coreCredited, $supplierPaid] as $entry) {
    check('lifecycle entry balances', Ledger::validate($entry), []);
    foreach ($entry as $raw) {
        $l = Ledger::normalizeLine($raw);
        if ($l['account'] === '2050') { $net2050 += $l['credit_cents'] - $l['debit_cents']; }
    }
}
check('2050 nets to zero when settled', $net2050, 0);

section('a forfeited core moves exactly the core value into revenue');
$forfeited = [dr('2050', '22.00'), cr('4030', '22.00', 'Core forfeited')];
check('forfeiture balances',            Ledger::validate($forfeited), []);
check('…and moves 2200 cents',          Ledger::totalCents($forfeited), 2200);

section('period keys');
check('key from a date',                Ledger::periodKey('2026-08-16'), '2026-08');
check('key from a year end',            Ledger::periodKey('2026-12-31'), '2026-12');

section('account type direction');
check('assets are debit-positive',      in_array('ASSET', Ledger::DEBIT_POSITIVE, true), true);
check('liabilities are credit-positive',in_array('LIABILITY', Ledger::CREDIT_POSITIVE, true), true);
check('revenue is credit-positive',     in_array('REVENUE', Ledger::CREDIT_POSITIVE, true), true);
check('COGS is debit-positive',         in_array('COGS', Ledger::DEBIT_POSITIVE, true), true);
check('every type has a direction',
    count(array_diff(Accounts::TYPES, array_merge(Ledger::DEBIT_POSITIVE, Ledger::CREDIT_POSITIVE))), 0);

section('the seeded chart can express a full entry');
$numbers = array_column(Accounts::DEFAULTS, 0);
foreach (['1010','1100','2020','2050','3300','4010','5000','6150','7010'] as $needed) {
    check("chart has $needed", in_array($needed, $numbers, true), true);
}
check('no duplicate account numbers',   count($numbers) === count(array_unique($numbers)), true);
check('every account has a known type',
    count(array_diff(array_column(Accounts::DEFAULTS, 2), Accounts::TYPES)), 0);
/* Every account seeded must satisfy Accounts::create's own format rule —
 * otherwise the seed can write a number the form would reject. */
$badFormat = array_filter($numbers, fn($n) => !preg_match('/^\d{3,8}$/', $n));
check('all numbers are 3-8 digits',     $badFormat, []);

section('accounts the running application already points at must exist');
/* Every account code data/seed.php writes onto a catalog item or an expense.
 * A tag pointing at an account that was never seeded is how the price book
 * silently lost its codes once already, so this list guards against the seed
 * and the chart drifting apart again.
 *
 * Kept as a literal rather than parsed out of seed.php: the point is to fail
 * when someone adds a code to one file and not the other, which a derived
 * list could not detect. If this fails, reconcile — do not just edit the list.
 *
 * NB these are the SETTLED five-account revenue set. The per-service tree
 * (4110 Battery Sales, 4200 Fuel Delivery, 4400 Platform…) that appears in
 * older backups under backups/ and in knowledge/ is superseded and was never
 * seeded; asserting on it here is what produced a false failure once. */
foreach (['2050','4000','4010','4020','4030','5000','5030','5090','6150'] as $referenced) {
    check("referenced $referenced is seeded", in_array($referenced, $numbers, true), true);
}

section('posting matrix — where the money lands');
/* A card payment must NOT debit Checking. The money sits in Square Clearing
 * until the processor transfers it days later, minus fees; debiting Checking
 * on the day of the swipe shows cash that is not in the bank and makes the
 * account impossible to reconcile against a statement. */
check('card goes to Square Clearing',  Posting::cashAccountFor('CARD'), '1050');
check('cash goes to Cash on Hand',     Posting::cashAccountFor('CASH'), '1000');
check('check goes to Checking',        Posting::cashAccountFor('CHECK'), '1010');
check('ACH goes to Checking',          Posting::cashAccountFor('ACH'), '1010');
check('provider remit goes to Checking', Posting::cashAccountFor('PROVIDER'), '1010');
check('method is case-insensitive',    Posting::cashAccountFor('card'), '1050');

check('card expense credits the card', Posting::fundingAccountFor('CARD'), '2010');
check('cash expense credits cash',     Posting::fundingAccountFor('CASH'), '1000');
check('check expense credits checking',Posting::fundingAccountFor('CHECK'), '1010');

section('revenue account falls back by item type, never to nothing');
check('a set account wins',
    Posting::revenueAccountFor(['revenue_account' => '4020', 'item_type' => 'PART']), '4020');
check('a part with none -> parts sales',
    Posting::revenueAccountFor(['revenue_account' => '', 'item_type' => 'PART']), '4010');
check('a service with none -> labour',
    Posting::revenueAccountFor(['item_type' => 'SERVICE']), '4000');
check('a fee with none -> fees',
    Posting::revenueAccountFor(['item_type' => 'FEE']), '4030');
check('an unknown type still resolves',
    Posting::revenueAccountFor(['item_type' => 'MYSTERY']), '4000');

section('revenue split nets per account');
$invLines = [
    ['line_total' => '260.00', 'discount_amount' => '0.00',  'revenue_account' => '4010', 'item_type' => 'PART'],
    ['line_total' => '85.00',  'discount_amount' => '0.00',  'revenue_account' => '4000', 'item_type' => 'SERVICE'],
    ['line_total' => '22.00',  'discount_amount' => '0.00',  'revenue_account' => '2050', 'item_type' => 'FEE'],
    ['line_total' => '65.00',  'discount_amount' => '15.00', 'revenue_account' => '4000', 'item_type' => 'SERVICE'],
];
$split = Posting::revenueSplit($invLines);
/* PHP coerces a numeric-string array key to an int, so these come back as
 * 4010 not '4010'. Anything consuming the split must cast back — forgetting
 * to threw a TypeError out of Posting::side the first time it ran. */
check('keys come back as ints',        array_key_first($split), 4010);
check('parts total',                   $split['4010'], 26000);
check('two service lines combine',     $split['4000'], 8500 + 5000);
check('the core line credits 2050',    $split['2050'], 2200);
check('discount reduces its own line', array_sum($split), 26000 + 8500 + 2200 + 5000);

$zeroed = Posting::revenueSplit([
    ['line_total' => '50.00', 'discount_amount' => '50.00', 'revenue_account' => '4000', 'item_type' => 'SERVICE'],
]);
check('a fully discounted line drops out', $zeroed, []);

/* A discount larger than its own line is a net DEBIT to revenue. Emitted as a
 * negative credit it would be refused — correctly, a negative credit is a
 * debit wearing the wrong label — so the split stays signed and side() picks. */
$overDiscounted = Posting::revenueSplit([
    ['line_total' => '50.00', 'discount_amount' => '75.00', 'revenue_account' => '4000', 'item_type' => 'SERVICE'],
]);
check('an over-discounted line goes negative', $overDiscounted['4000'], -2500);

section('a built invoice entry balances');
/* The shape Posting::invoiceIssued produces, assembled by hand: receivable
 * debited for the total, revenue and tax credited. */
$total = 26000 + 8500 + 2200 + 5000;   // 417.00, no tax in Oregon
$built = [['account' => '1100', 'debit_cents' => $total]];
foreach (Posting::revenueSplit($invLines) as $acct => $cents) {
    $built[] = ['account' => $acct, 'credit_cents' => $cents];
}
check('invoice entry balances',        Ledger::validate($built), []);
check('receivable equals the total',   $built[0]['debit_cents'], 41700);

section('a payment entry balances, and a tip is not receivable');
$payWithTip = [
    ['account' => '1050', 'debit_cents'  => 41700 + 1000],
    ['account' => '1100', 'credit_cents' => 41700],
    ['account' => '4300', 'credit_cents' => 1000],
];
check('payment with tip balances',     Ledger::validate($payWithTip), []);
check('receivable relieved by the invoice amount only', $payWithTip[1]['credit_cents'], 41700);

section('a Square Capital advance balances, and lands on the right liability');
/* Fixed-fee advance, not interest. The whole obligation is the liability; the
 * fee is expensed at origination; repayments reduce the liability. What must
 * hold is that 2100 ends up holding exactly what Square says is outstanding. */
$advanced = 552000; $fee = 88900; $owed = 640900; $balance = 353479;
check('advance plus fee equals total owed', $advanced + $fee, $owed);

$origination = [
    ['account' => Posting::CHECKING,     'debit_cents'  => $advanced],
    ['account' => Posting::CAPITAL_FEE,  'debit_cents'  => $fee],
    ['account' => Posting::CAPITAL_LOAN, 'credit_cents' => $owed],
];
check('origination balances',        Ledger::validate($origination), []);

$repaid = $owed - $balance;
$repayment = [
    ['account' => Posting::CAPITAL_LOAN, 'debit_cents'  => $repaid],
    ['account' => Posting::CHECKING,     'credit_cents' => $repaid],
];
check('repayment balances',          Ledger::validate($repayment), []);
/* 2100 is credit-positive, so the liability left is owed less repaid. */
check('the liability left equals the dashboard balance', $owed - $repaid, $balance);
check('the fee went to an expense account',
    str_starts_with(Posting::CAPITAL_FEE, '7'), true);
check('the debt went to a liability account',
    str_starts_with(Posting::CAPITAL_LOAN, '2'), true);

/* A fee that does not reconcile would silently misstate a deductible expense,
 * so capitalAdvance refuses rather than rounding. */
check('a mismatched fee is refused',
    Ledger::validate([
        ['account' => Posting::CHECKING,     'debit_cents'  => $advanced],
        ['account' => Posting::CAPITAL_FEE,  'debit_cents'  => $fee + 1],
        ['account' => Posting::CAPITAL_LOAN, 'credit_cents' => $owed],
    ]) === [], false);

section('the core state machine only allows legal moves');
check('charged -> collected',        Cores::canMove(Cores::CHARGED, Cores::COLLECTED), true);
check('charged -> forfeited',        Cores::canMove(Cores::CHARGED, Cores::FORFEITED), true);
check('collected -> returned',       Cores::canMove(Cores::COLLECTED, Cores::RETURNED), true);
check('returned -> credited',        Cores::canMove(Cores::RETURNED, Cores::CREDITED), true);
check('credited -> settled',         Cores::canMove(Cores::CREDITED, Cores::SETTLED), true);

/* A core cannot jump the physical chain. The supplier cannot credit a part
 * that was never returned, and a settled or forfeited core is finished — the
 * money has moved and moving it again would double the entry. */
check('charged -> credited refused', Cores::canMove(Cores::CHARGED, Cores::CREDITED), false);
check('charged -> returned refused', Cores::canMove(Cores::CHARGED, Cores::RETURNED), false);
check('settled -> anything refused', Cores::NEXT[Cores::SETTLED], []);
check('forfeited -> anything refused', Cores::NEXT[Cores::FORFEITED], []);
check('forfeited cannot be settled', Cores::canMove(Cores::FORFEITED, Cores::SETTLED), false);

section('open means the money is still held');
check('charged is open',             in_array(Cores::CHARGED, Cores::OPEN, true), true);
check('collected is open',           in_array(Cores::COLLECTED, Cores::OPEN, true), true);
check('returned is open',            in_array(Cores::RETURNED, Cores::OPEN, true), true);
check('credited is open',            in_array(Cores::CREDITED, Cores::OPEN, true), true);
/* Settled and forfeited are NOT open — 2050 has been cleared for both. If
 * either counted as open the outstanding-liability figure would never fall. */
check('settled is not open',         in_array(Cores::SETTLED, Cores::OPEN, true), false);
check('forfeited is not open',       in_array(Cores::FORFEITED, Cores::OPEN, true), false);

check('every status has a transition rule',
    count(array_diff(
        array_merge(Cores::OPEN, [Cores::SETTLED, Cores::FORFEITED]),
        array_keys(Cores::NEXT)
    )), 0);

section('a forfeited core is the only path from held to earned');
/* Every other transition moves cash against the liability. Only forfeiture
 * moves the liability into revenue, and only once. */
$toRevenue = [];
foreach (Cores::NEXT as $from => $tos) {
    foreach ($tos as $to) {
        if ($to === Cores::FORFEITED) { $toRevenue[] = $from; }
    }
}
check('forfeiture reachable from charged and collected only',
    $toRevenue, [Cores::CHARGED, Cores::COLLECTED]);
check('the forfeit account is a revenue account',
    str_starts_with(Posting::CORE_FORFEIT_REVENUE, '4'), true);
check('the holding account is the liability',
    Posting::CORE_PAYABLE_ACCT, '2050');

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
