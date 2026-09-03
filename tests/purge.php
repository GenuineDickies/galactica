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
 * data/purge.php — removes a named record and everything under it.
 *
 * A tool that deletes rows earns a test more than most. The failures worth
 * guarding against are not "did it delete" but what it leaves behind:
 *
 *   - a journal entry whose lines are gone, or lines with no entry, either of
 *     which silently unbalances the books;
 *   - a reversal still pointing at an original that no longer exists;
 *   - a square_transactions row deleted along with the test invoice that
 *     happened to be linked to it — the one loss in this system that cannot
 *     be undone from any other source.
 *
 * Everything here builds its own fixture and purges it, so the test leaves the
 * database exactly as it found it.
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

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  \033[32mPASS\033[0m $what\n"; return; }
    $fail++;
    echo "  \033[31mFAIL\033[0m $what (want " . var_export($want, true)
       . ", got " . var_export($got, true) . ")\n";
}
function section(string $s): void { echo "\n\033[1m== $s\033[0m\n"; }

$php  = PHP_BINARY;
$tool = $root . '/data/purge.php';
$run  = static function (array $args) use ($php, $tool): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' '
         . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
};

$integrity = static function (): array {
    return [
        'square'   => (int) Db::val('SELECT COALESCE(SUM(debit_cents),0)  FROM journal_lines', [], 0)
                   === (int) Db::val('SELECT COALESCE(SUM(credit_cents),0) FROM journal_lines', [], 0),
        'orphanL'  => (int) Db::val('SELECT COUNT(*) FROM journal_lines l
                        LEFT JOIN journal_entries e ON e.id = l.entry_id WHERE e.id IS NULL', [], 0),
        'emptyE'   => (int) Db::val('SELECT COUNT(*) FROM journal_entries e
                        LEFT JOIN journal_lines l ON l.entry_id = e.id WHERE l.id IS NULL', [], 0),
        'lostRev'  => (int) Db::val('SELECT COUNT(*) FROM journal_entries e
                        WHERE e.reverses_entry_id IS NOT NULL AND NOT EXISTS
                        (SELECT 1 FROM journal_entries o WHERE o.id = e.reverses_entry_id)', [], 0),
    ];
};

/* ---- fixture -------------------------------------------------------- */
$tag = 'PURGETEST-' . bin2hex(random_bytes(3));

$custId = Db::insert('customers', [
    'customer_type' => 'INDIVIDUAL', 'first_name' => 'Purge', 'last_name' => $tag,
    'phone_e164' => '+15035550199', 'created_at' => now(), 'updated_at' => now(),
]);
$invId = Db::insert('invoices', [
    'doc_number' => 'INV-' . $tag, 'customer_id' => $custId, 'status' => 'DRAFT',
    'subtotal' => '100.00', 'total' => '100.00', 'balance_due' => '100.00',
    'created_at' => now(), 'updated_at' => now(),
]);
/* Inserted directly rather than through Lines::add(), because the subject here
 * is purge, not line creation — a fixture that depends on the line API would
 * fail this test whenever that API changed, for reasons having nothing to do
 * with deletion. */
Db::insert('doc_lines', [
    'doc_type' => 'INV', 'doc_id' => $invId, 'line_no' => 1,
    'sku' => 'SVC-TEST', 'item_type' => 'SERVICE', 'name' => 'Purge fixture',
    'qty' => '1.00', 'unit_price' => '100.00', 'line_total' => '100.00',
]);

$entryId = Ledger::post('INV', [
    ['account' => Posting::AR, 'debit'  => '100.00'],
    ['account' => '4000',      'credit' => '100.00'],
], 'purge fixture', $invId, 'INV-' . $tag);

/* A mirror row linked to the fixture. This is the row that must SURVIVE. */
$sqId = 'sqtest_' . $tag;
Db::insert('square_transactions', [
    'square_id' => $sqId, 'object_type' => 'PAYMENT', 'amount' => '100.00',
    'invoice_id' => $invId, 'posted_entry_id' => $entryId,
    'occurred_at' => now(), 'first_seen_at' => now(), 'last_synced_at' => now(),
]);

$before = $integrity();

section('the fixture is there and the books are sound to begin with');
check('invoice exists',  Db::one('SELECT id FROM invoices WHERE id = ?', [$invId]) !== null, true);
check('entry exists',    Db::one('SELECT id FROM journal_entries WHERE id = ?', [$entryId]) !== null, true);
check('books square',    $before['square'], true);

section('a purge with no target refuses and changes nothing');
$r = $run([]);
check('exit code 1',            $r['code'], 1);
check('says name one thing',    str_contains($r['out'], 'Name exactly one thing'), true);
check('points at wipe.php',     str_contains($r['out'], 'wipe.php'), true);
check('invoice untouched',      Db::one('SELECT id FROM invoices WHERE id = ?', [$invId]) !== null, true);

section('without --yes it only previews');
$r = $run(['--invoice', 'INV-' . $tag]);
check('exit code 0',        $r['code'], 0);
check('says nothing changed', str_contains($r['out'], 'Nothing was changed'), true);
check('invoice still there',  Db::one('SELECT id FROM invoices WHERE id = ?', [$invId]) !== null, true);
check('entry still there',    Db::one('SELECT id FROM journal_entries WHERE id = ?', [$entryId]) !== null, true);

section('an unknown target is refused, not guessed at');
$r = $run(['--invoice', 'INV-DOES-NOT-EXIST']);
check('exit code 1', $r['code'], 1);
check('says no match', str_contains($r['out'], 'No invoice matches'), true);

section('--yes removes the record and everything under it');
$r = $run(['--invoice', 'INV-' . $tag, '--yes']);
check('exit code 0',      $r['code'], 0);
check('reports done',     str_contains($r['out'], 'Done'), true);
check('invoice gone',     Db::one('SELECT id FROM invoices WHERE id = ?', [$invId]), null);
check('its lines gone',   (int) Db::val("SELECT COUNT(*) FROM doc_lines WHERE doc_type='INV' AND doc_id = ?", [$invId], 0), 0);
check('its entry gone',   Db::one('SELECT id FROM journal_entries WHERE id = ?', [$entryId]), null);
check('entry lines gone', (int) Db::val('SELECT COUNT(*) FROM journal_lines WHERE entry_id = ?', [$entryId], 0), 0);

section('THE MIRROR SURVIVES — only the link is cleared');
/* square_transactions is six years of real charges and the only copy. A test
 * invoice linked to one must never be able to take it down with it. */
$sq = Db::one('SELECT * FROM square_transactions WHERE square_id = ?', [$sqId]);
check('the transaction row is still there', $sq !== null, true);
/* ?? is no good here: the value under test IS null, and ?? would report a
 * correctly-cleared link identically to a missing column. */
check('invoice link cleared',      array_key_exists('invoice_id', $sq) && $sq['invoice_id'] === null, true);
check('posted entry link cleared', array_key_exists('posted_entry_id', $sq) && $sq['posted_entry_id'] === null, true);
check('the money is untouched',    $sq['amount'] ?? '', '100.00');

section('the books are exactly as sound afterwards');
$after = $integrity();
check('still square',                     $after['square'], true);
check('no orphaned journal lines',        $after['orphanL'], $before['orphanL']);
check('no entries left without lines',    $after['emptyE'], $before['emptyE']);
check('no reversal lost its original',    $after['lostRev'], $before['lostRev']);

/* ---- clean up what the test itself made ----------------------------- */
Db::q('DELETE FROM square_transactions WHERE square_id = ?', [$sqId]);
$run(['--customer', (string) $custId, '--yes']);
check('the fixture customer is gone too',
    Db::one('SELECT id FROM customers WHERE id = ?', [$custId]), null);

echo "\n\033[1m$pass passed, $fail failed\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
