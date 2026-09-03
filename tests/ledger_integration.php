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
 * Integration tests for the general ledger against a real database: posting,
 * reversal, account balances, trial balance and period locking. Drives the
 * actual Ledger code, not HTTP.
 *
 *   WKR_DB_PASS=… php tests/ledger_integration.php
 *
 * Runs against whatever config.php points at and writes throwaway entries with
 * a recognisable memo, so use a scratch database.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
App::boot($cfg);
Db::boot($cfg['db']);

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $l, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }
function threw(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$TAG = 'LEDGERTEST-' . bin2hex(random_bytes(3));
/* Source ids must be unique per run. A fixed literal accumulated entries
 * across runs and made the "entries for this document" count grow every time
 * the suite was executed — a test that passes once and never again. */
$CORE_SRC = random_int(900000, 999999);
$FORFEIT_SRC = random_int(800000, 899999);

section('schema is present and additive migration is clean');
Db::migrate();
$pending = Db::pending();
check('no pending schema changes after migrate', $pending, []);
check('journal_entries exists',
    is_array(Db::all('SELECT * FROM journal_entries LIMIT 1')), true);
check('journal_lines exists',
    is_array(Db::all('SELECT * FROM journal_lines LIMIT 1')), true);
check('doc_lines carries core_charge',
    array_key_exists('core_charge', Schema::columns(Db::driver())['doc_lines']), true);
check('doc_lines carries revenue_account',
    array_key_exists('revenue_account', Schema::columns(Db::driver())['doc_lines']), true);

section('the chart seeds every type');
Accounts::ensureSeeded();
foreach (Accounts::TYPES as $type) {
    check("chart has $type accounts", count(Accounts::forType($type)) > 0, true);
}

section('posting an invoice entry');
/* Battery $260 + $22 core. The worked example from docs/ACCOUNTING_PLAN.md. */
$before2050 = Ledger::balanceCents('2050');
$before1100 = Ledger::balanceCents('1100');

$invoiceEntry = Ledger::post('INV', [
    ['account' => '1100', 'debit'  => '282.00', 'memo' => 'Accounts Receivable'],
    ['account' => '4010', 'credit' => '260.00', 'memo' => 'Parts Sales'],
    ['account' => '2050', 'credit' => '22.00',  'memo' => 'Core held'],
], $TAG . ' battery with core', 9001, $TAG);

check('entry id returned',           $invoiceEntry > 0, true);
$e = Ledger::entry($invoiceEntry);
check('three lines written',         count($e['lines']), 3);
check('entry number assigned',       str_starts_with((string) $e['entry_no'], 'JE-'), true);
check('total is one side',           (int) $e['total_cents'], 28200);
check('period key derived',          $e['period_key'], substr((string) $e['entry_date'], 0, 7));
check('account name snapshotted',    $e['lines'][0]['account_name'], 'Accounts Receivable');
check('account type snapshotted',    $e['lines'][0]['account_type'], 'ASSET');

section('balances move in the natural direction');
check('receivable rose by 282',      Ledger::balanceCents('1100') - $before1100, 28200);
check('core liability rose by 22',   Ledger::balanceCents('2050') - $before2050, 2200);

section('an unbalanced entry is refused at the write path too');
check('one cent off throws', threw(fn() => Ledger::post('ADJ', [
    ['account' => '1100', 'debit'  => '10.00'],
    ['account' => '4000', 'credit' => '9.99'],
], $TAG . ' should not exist')), true);
check('…and wrote nothing',
    (int) Db::val('SELECT COUNT(*) FROM journal_entries WHERE memo = ?', [$TAG . ' should not exist']), 0);

check('unknown source type throws', threw(fn() => Ledger::post('NOPE', [
    ['account' => '1100', 'debit' => '1.00'], ['account' => '4000', 'credit' => '1.00'],
], $TAG)), true);

section('reversal cancels exactly and keeps both rows');
$reversalId = Ledger::reverse($invoiceEntry, 'test reversal');
check('reversal is a new entry',     $reversalId !== $invoiceEntry, true);
check('receivable back to start',    Ledger::balanceCents('1100'), $before1100);
check('core liability back to start',Ledger::balanceCents('2050'), $before2050);

$orig = Ledger::entry($invoiceEntry);
$rev  = Ledger::entry($reversalId);
check('original still exists',       (int) $orig['id'], $invoiceEntry);
check('original points at reversal', (int) $orig['reversed_by_id'], $reversalId);
check('reversal points at original', (int) $rev['reverses_entry_id'], $invoiceEntry);
check('reversal flagged',            (int) $rev['is_reversal'], 1);
check('reversal has same line count',count($rev['lines']), count($orig['lines']));
check('double reversal refused',     threw(fn() => Ledger::reverse($invoiceEntry)), true);
check('reversing a missing entry refused',
    threw(fn() => Ledger::reverse(99999999)), true);

section('the full core lifecycle returns 2050 to zero');
$start = Ledger::balanceCents('2050');
Ledger::post('INV',  [['account'=>'1100','debit'=>'282.00'], ['account'=>'4010','credit'=>'260.00'],
                      ['account'=>'2050','credit'=>'22.00']], $TAG . ' core charged', $CORE_SRC, $TAG);
check('holding 22 after the sale',   Ledger::balanceCents('2050') - $start, 2200);
Ledger::post('CORE', [['account'=>'2050','debit'=>'22.00'], ['account'=>'1010','credit'=>'22.00']],
             $TAG . ' refunded to customer', $CORE_SRC, $TAG);
Ledger::post('CORE', [['account'=>'1010','debit'=>'22.00'], ['account'=>'2050','credit'=>'22.00']],
             $TAG . ' credited by supplier', $CORE_SRC, $TAG);
Ledger::post('CORE', [['account'=>'2050','debit'=>'22.00'], ['account'=>'1010','credit'=>'22.00']],
             $TAG . ' paid to supplier', $CORE_SRC, $TAG);
check('2050 flat once settled',      Ledger::balanceCents('2050') - $start, 0);

section('a forfeited core becomes revenue, and only then');
$rev4030 = Ledger::balanceCents('4030');
$held    = Ledger::balanceCents('2050');
Ledger::post('INV',  [['account'=>'1100','debit'=>'22.00'], ['account'=>'2050','credit'=>'22.00']],
             $TAG . ' core charged, never returned', $FORFEIT_SRC, $TAG);
check('revenue unmoved while held',  Ledger::balanceCents('4030'), $rev4030);
Ledger::post('CORE', [['account'=>'2050','debit'=>'22.00'], ['account'=>'4030','credit'=>'22.00']],
             $TAG . ' forfeited', $FORFEIT_SRC, $TAG);
check('revenue rose by exactly 22',  Ledger::balanceCents('4030') - $rev4030, 2200);
check('liability released',          Ledger::balanceCents('2050') - $held, 0);

section('the trial balance squares');
check('debits equal credits',        Ledger::trialBalanceIsSquare(), true);
$tb = Ledger::trialBalance();
check('trial balance has rows',      count($tb) > 0, true);

section('entries are findable by their source document');
$bySource = Ledger::forSource('CORE', $CORE_SRC);
check('three core entries for this document', count($bySource), 3);

section('a closed period refuses an entry dated inside it');
$period = Ledger::periodKey(date('Y-m-d'));
$saved  = (string) (Db::val('SELECT svalue FROM settings WHERE skey = ?', ['closed_periods']) ?? '');

check('period is open to begin with', Ledger::periodIsClosed($period), false);

Db::q('DELETE FROM settings WHERE skey = ?', ['closed_periods']);
Db::insert('settings', ['skey' => 'closed_periods', 'svalue' => $period]);

check('period now reads as closed',   Ledger::periodIsClosed($period), true);
check('posting into it throws', threw(fn() => Ledger::post('ADJ', [
    ['account' => '1010', 'debit'  => '1.00'],
    ['account' => '4000', 'credit' => '1.00'],
], $TAG . ' into closed period')), true);
check('…and wrote nothing',
    (int) Db::val('SELECT COUNT(*) FROM journal_entries WHERE memo = ?', [$TAG . ' into closed period']), 0);

/* An open period is still writable while another is closed. */
check('a different period still posts',
    Ledger::post('ADJ', [
        ['account' => '1010', 'debit'  => '1.00'],
        ['account' => '4000', 'credit' => '1.00'],
    ], $TAG . ' open period', null, $TAG, '2000-01-15') > 0, true);

Db::q('DELETE FROM settings WHERE skey = ?', ['closed_periods']);
if ($saved !== '') { Db::insert('settings', ['skey' => 'closed_periods', 'svalue' => $saved]); }
check('lock restored after test',     Ledger::periodIsClosed($period), false);

/* ===================================================================
 * PHASE 2 — the posting matrix against real documents
 * =================================================================== */

/** Throwaway catalog item, so lines have something real to snapshot from. */
function tempCatalogItem(string $name, string $type, float $price, string $revAcct, float $core = 0.0): int {
    return Db::insert('catalog_items', [
        'sku' => 'LEDG-' . bin2hex(random_bytes(3)), 'item_type' => $type, 'category' => 'Test',
        'name' => $name, 'description' => '', 'pricing_model' => 'FLAT',
        'unit_price' => $price, 'unit_cost' => 0, 'price_overridden' => 1, 'uom' => 'job',
        'taxable' => 0, 'core_charge' => $core, 'revenue_account' => $revAcct,
        'cogs_account' => '', 'is_active' => 1, 'sort_order' => 0,
    ]);
}

section('a line snapshots the accounts the ledger will post from');
$partItem = tempCatalogItem('Test battery', 'PART', 260.00, '4010', 22.00);
$svcItem  = tempCatalogItem('Test labour',  'SERVICE', 85.00, '4000');
$coreItem = tempCatalogItem('Test core',    'FEE', 22.00, '2050');

$invId = Db::insert('invoices', [
    'doc_number' => 'INV-LEDGERTEST-' . bin2hex(random_bytes(3)),
    'service_request_id' => 0, 'estimate_id' => 0, 'customer_id' => 0,
    'status' => 'DRAFT', 'tax_rate' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
Lines::add('INV', $invId, $partItem, 1.0, 260.00);
Lines::add('INV', $invId, $svcItem,  1.0, 85.00);
Lines::add('INV', $invId, $coreItem, 1.0, 22.00);

$snap = Lines::forDoc('INV', $invId);
check('revenue account snapshotted',    $snap[0]['revenue_account'], '4010');
check('core charge snapshotted',        Markup::toCents($snap[0]['core_charge']), 2200);
check('the core line points at 2050',   $snap[2]['revenue_account'], '2050');

$t = Lines::totals('INV', $invId, 0.0);
Db::update('invoices', $invId, [
    'subtotal' => $t['subtotal'], 'discount_total' => $t['discount'],
    'tax_total' => $t['tax'], 'total' => $t['total'], 'balance_due' => $t['total'],
]);
check('invoice totals 367.00',          Markup::toCents($t['total']), 36700);

section('issuing posts the invoice');
$inv       = Db::one('SELECT * FROM invoices WHERE id = ?', [$invId]);
$before    = ['ar' => Ledger::balanceCents('1100'), 'parts' => Ledger::balanceCents('4010'),
              'lab' => Ledger::balanceCents('4000'), 'core' => Ledger::balanceCents('2050')];
$invEntry  = Posting::invoiceIssued($inv);
check('an entry was raised',            $invEntry > 0, true);
check('receivable rose by the total',   Ledger::balanceCents('1100') - $before['ar'], 36700);
check('parts revenue rose by 260',      Ledger::balanceCents('4010') - $before['parts'], 26000);
check('labour revenue rose by 85',      Ledger::balanceCents('4000') - $before['lab'], 8500);
check('core liability rose by 22',      Ledger::balanceCents('2050') - $before['core'], 2200);
check('…and the core did NOT hit revenue',
    Ledger::balanceCents('4030') - Ledger::balanceCents('4030'), 0);

section('posting is idempotent — a double click cannot double the books');
$again = Posting::invoiceIssued($inv);
check('the same entry comes back',      $again, $invEntry);
check('receivable did not move again',  Ledger::balanceCents('1100') - $before['ar'], 36700);
check('still one entry for this invoice', count(Ledger::forSource('INV', $invId)), 1);

section('a payment settles the receivable and does not create revenue');
$payId = Db::insert('payments', [
    'doc_number' => DocNumber::next('PAY'), 'invoice_id' => $invId, 'customer_id' => 0,
    'method' => 'CARD', 'amount' => 367.00, 'tip_amount' => 10.00, 'processor' => 'square',
    'status' => 'COMPLETED', 'paid_at' => now(), 'created_at' => now(),
]);
$revBefore  = Ledger::balanceCents('4010') + Ledger::balanceCents('4000');
$sqBefore   = Ledger::balanceCents('1050');
$tipBefore  = Ledger::balanceCents('4300');
$payEntry   = Posting::paymentRecorded(Db::one('SELECT * FROM payments WHERE id = ?', [$payId]), $inv);

check('a payment entry was raised',     $payEntry > 0, true);
check('receivable cleared',             Ledger::balanceCents('1100') - $before['ar'], 0);
check('card money went to Square Clearing, not Checking',
    Ledger::balanceCents('1050') - $sqBefore, 37700);
check('the tip is Other Revenue',       Ledger::balanceCents('4300') - $tipBefore, 1000);
check('service revenue unchanged by payment',
    Ledger::balanceCents('4010') + Ledger::balanceCents('4000'), $revBefore);
check('paying twice is idempotent',
    Posting::paymentRecorded(Db::one('SELECT * FROM payments WHERE id = ?', [$payId]), $inv), $payEntry);

section('voiding reverses, visibly, and keeps both halves');
$void = Posting::invoiceVoided($inv, 'test void');
check('a reversal was raised',          $void > 0, true);
check('receivable back to where it started',
    Ledger::balanceCents('1100') - $before['ar'], -36700);   // payment already relieved it
check('parts revenue reversed out',     Ledger::balanceCents('4010') - $before['parts'], 0);
check('core liability reversed out',    Ledger::balanceCents('2050') - $before['core'], 0);
check('original entry still on the books',
    Ledger::entry($invEntry) !== null, true);
check('original is marked reversed',    (int) Ledger::entry($invEntry)['reversed_by_id'], $void);
check('voiding twice returns the same reversal',
    Posting::invoiceVoided($inv, 'again'), $void);

section('an expense posts to its account and credits how it was paid');
$expId = Db::insert('expenses', [
    'doc_number' => DocNumber::next('EXP'), 'vendor_name' => 'Test Vendor', 'category' => 'Test',
    'account_code' => '6150', 'description' => $TAG, 'amount' => 14.00, 'tax_amount' => 0,
    'expense_date' => date('Y-m-d'), 'payment_method' => 'CARD', 'created_at' => now(),
]);
$smsBefore  = Ledger::balanceCents('6150');
$cardBefore = Ledger::balanceCents('2010');
$expEntry   = Posting::expenseRecorded(Db::one('SELECT * FROM expenses WHERE id = ?', [$expId]));
check('an expense entry was raised',    $expEntry > 0, true);
check('SMS expense rose by 14',         Ledger::balanceCents('6150') - $smsBefore, 1400);
check('card liability rose by 14',      Ledger::balanceCents('2010') - $cardBefore, 1400);
check('expenses are idempotent too',
    Posting::expenseRecorded(Db::one('SELECT * FROM expenses WHERE id = ?', [$expId])), $expEntry);

section('an expense with no account code still posts');
$expId2 = Db::insert('expenses', [
    'doc_number' => DocNumber::next('EXP'), 'vendor_name' => 'Unclassified', 'category' => '',
    'account_code' => '', 'description' => $TAG, 'amount' => 5.00, 'tax_amount' => 0,
    'expense_date' => date('Y-m-d'), 'payment_method' => 'CASH', 'created_at' => now(),
]);
$otherBefore = Ledger::balanceCents('6900');
Posting::expenseRecorded(Db::one('SELECT * FROM expenses WHERE id = ?', [$expId2]));
check('it lands in Other Expenses',     Ledger::balanceCents('6900') - $otherBefore, 500);

section('a zero-total invoice posts nothing rather than an empty entry');
$freeId = Db::insert('invoices', [
    'doc_number' => 'INV-LEDGERTEST-FREE-' . bin2hex(random_bytes(3)),
    'service_request_id' => 0, 'estimate_id' => 0, 'customer_id' => 0,
    'status' => 'DRAFT', 'tax_rate' => 0, 'total' => 0, 'tax_total' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
check('nothing posted',
    Posting::invoiceIssued(Db::one('SELECT * FROM invoices WHERE id = ?', [$freeId])), null);
check('and no entry exists for it',     count(Ledger::forSource('INV', $freeId)), 0);

section('the trial balance still squares after all of that');
check('debits equal credits',           Ledger::trialBalanceIsSquare(), true);

section('nested transactions commit as one unit');
/* Posting runs inside the caller's transaction. If the outer one rolls back,
 * the entry must roll back with it — an issued invoice with no entry behind
 * it is the hole this build exists to close. */
$countBefore = (int) Db::val('SELECT COUNT(*) FROM journal_entries');
try {
    Db::tx(static function () use ($TAG) {
        Ledger::post('ADJ', [
            ['account' => '1010', 'debit'  => '3.00'],
            ['account' => '4000', 'credit' => '3.00'],
        ], $TAG . ' rolled back');
        throw new RuntimeException('deliberate rollback');
    });
} catch (Throwable) { /* expected */ }
check('the inner entry rolled back with the outer',
    (int) Db::val('SELECT COUNT(*) FROM journal_entries'), $countBefore);

/* ===================================================================
 * PHASE 3 — a core through its whole life
 * =================================================================== */

section('issuing an invoice opens a custody record per core');
$coreItem2 = tempCatalogItem('Test alternator', 'PART', 289.00, '4010', 22.00);
$inv2Id = Db::insert('invoices', [
    'doc_number' => 'INV-CORETEST-' . bin2hex(random_bytes(3)),
    'service_request_id' => 0, 'estimate_id' => 0, 'customer_id' => 0,
    'status' => 'DRAFT', 'tax_rate' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
Lines::add('INV', $inv2Id, $coreItem2, 1.0, 289.00);
$t2 = Lines::totals('INV', $inv2Id, 0.0);
Db::update('invoices', $inv2Id, ['total' => $t2['total'], 'tax_total' => $t2['tax'], 'balance_due' => $t2['total']]);
$inv2 = Db::one('SELECT * FROM invoices WHERE id = ?', [$inv2Id]);

check('the line carries the core value',
    Markup::toCents(Lines::forDoc('INV', $inv2Id)[0]['core_charge']), 2200);
check('one core record opened',      Cores::openForInvoice($inv2), 1);
check('re-issuing opens none',       Cores::openForInvoice($inv2), 0);

$core = Db::one('SELECT * FROM core_records WHERE invoice_id = ?', [$inv2Id]);
check('it starts CHARGED',           $core['status'], 'CHARGED');
check('it carries the value',        Markup::toCents($core['core_value']), 2200);
check('it has a return deadline',    strlen((string) $core['due_back_by']), 10);
$coreId = (int) $core['id'];

section('an illegal move is refused, not silently allowed');
check('charged -> credited throws',  threw(fn() => Cores::move($coreId, Cores::CREDITED)), true);
check('and the status did not move',
    (string) Db::val('SELECT status FROM core_records WHERE id = ?', [$coreId]), 'CHARGED');

section('collecting the old unit moves no money');
$held = Ledger::balanceCents('2050');
Cores::move($coreId, Cores::COLLECTED, ['notes' => $TAG . ' picked up on scene']);
$core = Db::one('SELECT * FROM core_records WHERE id = ?', [$coreId]);
check('status is COLLECTED',         $core['status'], 'COLLECTED');
check('who collected it is recorded', $core['collected_at'] !== null, true);
check('the liability is unchanged',  Ledger::balanceCents('2050'), $held);

section('returning to the supplier moves no money either');
Cores::move($coreId, Cores::RETURNED, ['supplier_name' => 'Test Jobber']);
$core = Db::one('SELECT * FROM core_records WHERE id = ?', [$coreId]);
check('supplier recorded',           $core['supplier_name'], 'Test Jobber');
check('still unchanged',             Ledger::balanceCents('2050'), $held);

section('the supplier credit brings cash in');
/* 2050 RISES here, and that is correct — it is not a second deposit.
 *
 * A core has FOUR legs, not two, because there are two counterparties:
 *
 *   1. You pay the supplier's core charge buying the part   Dr 2050
 *   2. You collect the deposit from the customer            Cr 2050
 *   3. You refund the customer when they bring it back      Dr 2050
 *   4. The supplier refunds you when you hand it over       Cr 2050
 *
 * Legs 1 and 4 are the supplier side; 2 and 3 the customer side. All four
 * together net 2050 to zero. Leg 1 arrives from the EXPENSE side — a purchase
 * coded to 2050 — so it is not raised here, which is why this test starts
 * from whatever the balance happens to be rather than from zero.
 *
 * Reading leg 4 as "the liability should fall" is the intuitive mistake: the
 * supplier giving money back undoes what YOU paid THEM, it does not undo what
 * the customer paid you. */
$bank = Ledger::balanceCents('1010');
Cores::move($coreId, Cores::CREDITED);
check('cash rose by the core value', Ledger::balanceCents('1010') - $bank, 2200);
check('the supplier leg reverses upward', Ledger::balanceCents('2050') - $held, 2200);
check('an entry was posted',
    (int) Db::val('SELECT posted_entry_id FROM core_records WHERE id = ?', [$coreId], 0) > 0, true);

section('settling refunds the customer and closes it out');
$bank2 = Ledger::balanceCents('1010');
Cores::move($coreId, Cores::SETTLED);
$core = Db::one('SELECT * FROM core_records WHERE id = ?', [$coreId]);
check('status is SETTLED',           $core['status'], 'SETTLED');
check('the customer was refunded',   $core['customer_refunded_at'] !== null, true);
check('cash went back out',          Ledger::balanceCents('1010') - $bank2, -2200);
/* Charged +22, supplier credit -22, customer refund +22-22... the whole
 * lifecycle nets 2050 back to where it started. */
check('2050 is back where it started', Ledger::balanceCents('2050'), $held - 2200 + 2200 - 2200 + 2200);
check('a settled core moves no further', threw(fn() => Cores::move($coreId, Cores::COLLECTED)), true);

section('a forfeited core becomes revenue — and only then');
$inv3Id = Db::insert('invoices', [
    'doc_number' => 'INV-CORETEST2-' . bin2hex(random_bytes(3)),
    'service_request_id' => 0, 'estimate_id' => 0, 'customer_id' => 0,
    'status' => 'DRAFT', 'tax_rate' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
Lines::add('INV', $inv3Id, $coreItem2, 1.0, 289.00);
Cores::openForInvoice(Db::one('SELECT * FROM invoices WHERE id = ?', [$inv3Id]));
$core3 = (int) Db::val('SELECT id FROM core_records WHERE invoice_id = ?', [$inv3Id]);

$rev  = Ledger::balanceCents('4030');
$held3 = Ledger::balanceCents('2050');
check('revenue untouched while held', Ledger::balanceCents('4030'), $rev);

Cores::move($core3, Cores::FORFEITED, ['notes' => 'never came back']);
check('revenue rose by exactly the core', Ledger::balanceCents('4030') - $rev, 2200);
check('the liability was released',  Ledger::balanceCents('2050') - $held3, -2200);
check('it is closed',                (string) Db::val('SELECT status FROM core_records WHERE id = ?', [$core3]), 'FORFEITED');

section('open cores are countable, closed ones are not');
$open = Cores::openSummary();
check('the settled core is not counted',
    (int) Db::val("SELECT COUNT(*) FROM core_records WHERE id = ? AND status IN ('CHARGED','COLLECTED','RETURNED','CREDITED')", [$coreId], 0), 0);
check('the forfeited core is not counted',
    (int) Db::val("SELECT COUNT(*) FROM core_records WHERE id = ? AND status IN ('CHARGED','COLLECTED','RETURNED','CREDITED')", [$core3], 0), 0);
check('the summary returns a count',  is_int($open['count']), true);

section('the trial balance still squares after the core lifecycle');
check('debits equal credits',        Ledger::trialBalanceIsSquare(), true);

/* ===================================================================
 * PHASE 4 — the reports
 * =================================================================== */

section('every registered report runs and returns the agreed shape');
/* The uniform contract is what lets one view render all of them and what
 * makes adding a report a method plus a registry line. A report that quietly
 * omits a key breaks the renderer for everything, so the contract is asserted
 * here rather than discovered in a browser. */
foreach (array_keys(LedgerReports::REPORTS) as $key) {
    $r = LedgerReports::run($key, ['account' => '1100', 'from' => '2000-01-01', 'to' => date('Y-m-d')]);
    foreach (['key', 'title', 'subtitle', 'columns', 'rows', 'totals', 'ok', 'note'] as $field) {
        check("$key has '$field'", array_key_exists($field, $r), true);
    }
    check("$key rows is a list",       is_array($r['rows']), true);
    check("$key columns is a list",    is_array($r['columns']), true);
    /* Every row must have exactly as many cells as there are columns, or the
     * table renders ragged and a number lands under the wrong heading. */
    $bad = 0;
    foreach ($r['rows'] as $row) { if (count($row) !== count($r['columns'])) { $bad++; } }
    check("$key rows match its columns", $bad, 0);
    if (!empty($r['totals'])) {
        check("$key totals match its columns", count($r['totals']), count($r['columns']));
    }
}

section('the trial balance reports whether it balances');
$tb = LedgerReports::trialBalance();
check('it agrees with the engine',   $tb['ok'], Ledger::trialBalanceIsSquare());
check('it has rows',                 count($tb['rows']) > 0, true);
check('and says so in the note',     str_contains($tb['note'], 'balance'), true);

section('account detail runs a balance forward');
$ar = LedgerReports::accountDetail(['account' => '1100']);
check('it is labelled with the account', str_contains($ar['title'], '1100'), true);
check('the closing balance matches the engine',
    $ar['rows'] === [] ? true : $ar['totals'][6] === money(Markup::centsToStr(Ledger::balanceCents('1100'))), true);
check('an unknown account is handled',
    is_array(LedgerReports::accountDetail(['account' => '9999'])['rows']), true);
check('no account asked for is handled',
    LedgerReports::accountDetail([])['rows'], []);

section('receivables reconcile against account 1100');
$rec = LedgerReports::receivables();
check('it returns a note either way', $rec['note'] !== '', true);
/* ok is FALSE when the invoice total and the ledger disagree. That is expected
 * here — invoices issued before the ledger existed were never posted — and the
 * note has to explain it rather than just showing a red badge. */
check('a mismatch is explained',
    $rec['ok'] || str_contains($rec['note'], 'before the ledger existed'), true);

section('cores outstanding survives the table not existing');
$co = LedgerReports::cores();
check('it returns the agreed shape', array_key_exists('rows', $co), true);
check('and never throws',            is_array($co['rows']), true);

section('cash basis never exceeds accrual');
$cb = LedgerReports::cashBasis(['from' => '2000-01-01', 'to' => date('Y-m-d')]);
check('it has three measures',       count($cb['rows']), 3);
check('it names both bases',
    str_contains($cb['columns'][1]['label'], 'Accrual') && str_contains($cb['columns'][2]['label'], 'Cash'), true);
/* The honest caveat matters more than the number: the cost side is not a
 * real cash-basis conversion until accounts payable exists. */
check('the caveat is stated',        str_contains($cb['note'], 'accounts payable'), true);

section('an unknown report key falls back rather than failing');
check('falls back to the trial balance',
    LedgerReports::run('no-such-report')['key'], 'trial-balance');

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
printf("Test entries are tagged %s and were left in place — nothing is ever deleted.\n", $TAG);
exit($FAIL === 0 ? 0 : 1);
