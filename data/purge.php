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
 * PURGE — remove ONE named record and everything hanging off it.
 *
 *   php data/purge.php --invoice INV-20260819-001
 *   php data/purge.php --customer 42
 *   php data/purge.php --sr SR-20260819-003
 *   php data/purge.php --journal JE-000123
 *
 * Add --yes to actually do it. Without --yes you get the blast radius and
 * nothing is touched, because the safe thing should be what happens when you
 * forget to think.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS, AND HOW IT DIFFERS FROM data/wipe.php
 * ---------------------------------------------------------------------------
 *
 * wipe.php destroys a whole database and is gated on wipe-policy.php, which
 * refuses any non-local host outright. That gate is correct for what it
 * guards: losing the company. It is the wrong instrument for "delete the test
 * invoice I just made", and having only the big hammer meant routine
 * development cleanup on the live install had no sanctioned path at all.
 *
 * So this tool is deliberately the opposite shape:
 *
 *   - It CANNOT operate without an explicit named target. There is no --all,
 *     no --everything, no bare invocation that does something. An unbounded
 *     purge is a wipe, and wipe.php already owns that with its own gate.
 *   - It runs anywhere, production included, because that is where an
 *     application still in development accumulates test records.
 *   - It removes rows outright rather than voiding them. A void is the right
 *     correction for a real document that really happened. A test invoice
 *     never happened, and leaving voided fiction in the books forever makes
 *     the ledger harder to read, not more honest.
 *
 * ---------------------------------------------------------------------------
 * THE ONE THING IT WILL NOT DELETE
 * ---------------------------------------------------------------------------
 *
 * square_transactions. That table is the mirror of six years of real charges
 * and is the only copy of records that cannot be rebuilt from anywhere else —
 * Square's own cursors will not re-serve them reliably. If a purged invoice is
 * linked to one, the LINK is cleared (invoice_id, payment_id, posted_entry_id
 * back to null, classification left alone) and the transaction row itself
 * survives, returning to the unlinked state it had before someone matched it.
 *
 * Deleting a square_transactions row to tidy up a test would trade something
 * irreplaceable for something worthless.
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

const PURGE_LOG = 'data/purge.log';

$args = array_slice($argv, 1);
$opt = static function (string $flag) use ($args): ?string {
    $i = array_search($flag, $args, true);
    if ($i === false) { return null; }
    return $args[$i + 1] ?? '';
};
$has = static fn(string $f): bool => in_array($f, $args, true);

$commit = $has('--yes');

/* ------------------------------------------------------------------ */
/* Work out what we were asked to remove                              */
/* ------------------------------------------------------------------ */

$targets = array_filter([
    'invoice'  => $opt('--invoice'),
    'customer' => $opt('--customer'),
    'sr'       => $opt('--sr'),
    'journal'  => $opt('--journal'),
], static fn($v) => $v !== null);

if (count($targets) !== 1) {
    fwrite(STDERR, <<<TXT

      Name exactly one thing to purge.

        --invoice  <id or INV-…>    the invoice, its lines, payments, receipts,
                                    payment links, core records and journal entries
        --customer <id>             the customer and their whole history
        --sr       <id or SR-…>     a service request and everything downstream
        --journal  <id or JE-…>     one journal entry and its lines

      Add --yes to commit. Without it you get a preview.

      There is no --all. Removing everything is a wipe, and that lives in
      data/wipe.php behind data/wipe-policy.php.


    TXT);
    exit(1);
}

$kind  = array_key_first($targets);
$given = trim((string) $targets[$kind]);
if ($given === '') { fwrite(STDERR, "  --$kind needs a value.\n"); exit(1); }

/* ------------------------------------------------------------------ */
/* Resolve the target to a row                                        */
/* ------------------------------------------------------------------ */

/** Find by primary key if numeric, else by the table's human document number. */
$resolve = static function (string $table, string $numberCol, string $given): ?array {
    if (ctype_digit($given)) {
        $r = Db::one("SELECT * FROM $table WHERE id = ?", [(int) $given]);
        if ($r) { return $r; }
    }
    return Db::one("SELECT * FROM $table WHERE $numberCol = ?", [$given]) ?: null;
};

$row = match ($kind) {
    'invoice'  => $resolve('invoices', 'doc_number', $given),
    'sr'       => $resolve('service_requests', 'doc_number', $given),
    'journal'  => $resolve('journal_entries', 'entry_no', $given),
    'customer' => ctype_digit($given) ? Db::one('SELECT * FROM customers WHERE id = ?', [(int) $given]) : null,
};

if (!$row) {
    fwrite(STDERR, "\n  No $kind matches '$given'.\n\n");
    exit(1);
}

/* ------------------------------------------------------------------ */
/* Gather the blast radius                                            */
/* ------------------------------------------------------------------ */

$ids = static fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
$in  = static function (array $list): string {
    return $list ? '(' . implode(',', array_map('intval', $list)) . ')' : '(0)';
};

$plan = [];                 // table => list of ids to delete
$unlink = [];               // square_transactions rows to detach
$id = (int) $row['id'];

/* Walk DOWN the chain from whatever was named. Each step collects ids; the
 * deletion order is decided separately, children first. */
$customers = $kind === 'customer' ? [$id] : [];
$srs = $ests = $wos = $invs = $pays = $entries = [];

if ($kind === 'sr')      { $srs = [$id]; }
if ($kind === 'invoice') { $invs = [$id]; }
if ($kind === 'journal') { $entries = [$id]; }

$merge = static fn(array $a, array $b): array => array_values(array_unique(array_merge($a, $b)));

if ($customers) {
    $srs  = $ids(Db::all('SELECT id FROM service_requests WHERE customer_id = ?', [$id]));
    $ests = $ids(Db::all('SELECT id FROM estimates WHERE customer_id = ?', [$id]));
    $invs = $ids(Db::all('SELECT id FROM invoices WHERE customer_id = ?', [$id]));
}

/* Every priced document carries service_request_id as well as its immediate
 * parent, so the chain can be walked from any point without inferring links
 * from a customer/vehicle pair. */
if ($srs) {
    $ests = $merge($ests, $ids(Db::all('SELECT id FROM estimates    WHERE service_request_id IN ' . $in($srs))));
    $wos  = $merge($wos,  $ids(Db::all('SELECT id FROM work_orders  WHERE service_request_id IN ' . $in($srs))));
    $invs = $merge($invs, $ids(Db::all('SELECT id FROM invoices     WHERE service_request_id IN ' . $in($srs))));
}
if ($ests) {
    $wos  = $merge($wos,  $ids(Db::all('SELECT id FROM work_orders WHERE estimate_id IN ' . $in($ests))));
    $invs = $merge($invs, $ids(Db::all('SELECT id FROM invoices    WHERE estimate_id IN ' . $in($ests))));
}
if ($wos) {
    $invs = $merge($invs, $ids(Db::all('SELECT id FROM invoices WHERE work_order_id IN ' . $in($wos))));
}
if ($invs) {
    $pays = $ids(Db::all('SELECT id FROM payments WHERE invoice_id IN ' . $in($invs)));
}

/* Journal entries reached through their source document, plus any reversal
 * pointing back at them — a reversal orphaned from its original is worse than
 * either row alone, so they always travel together. */
$sourced = [];
if ($invs) { $sourced = array_merge($sourced, $ids(Db::all(
    "SELECT id FROM journal_entries WHERE source_type = 'INV' AND source_id IN " . $in($invs)))); }
if ($pays) { $sourced = array_merge($sourced, $ids(Db::all(
    "SELECT id FROM journal_entries WHERE source_type = 'PAY' AND source_id IN " . $in($pays)))); }
if ($invs) {
    $cores = $ids(Db::all('SELECT id FROM core_records WHERE invoice_id IN ' . $in($invs)));
    if ($cores) { $sourced = array_merge($sourced, $ids(Db::all(
        "SELECT id FROM journal_entries WHERE source_type = 'CORE' AND source_id IN " . $in($cores)))); }
}
$entries = $merge($entries, $sourced);
if ($entries) {
    $entries = $merge($entries, $ids(Db::all(
        'SELECT id FROM journal_entries WHERE reverses_entry_id IN ' . $in($entries))));
}

/* ------------------------------------------------------------------ */
/* A closed period is a hard stop                                     */
/* ------------------------------------------------------------------ */

if ($entries) {
    $locked = Db::all('SELECT DISTINCT period_key FROM journal_entries WHERE id IN ' . $in($entries));
    $bad = [];
    foreach ($locked as $p) {
        if (Ledger::periodIsClosed((string) $p['period_key'])) { $bad[] = (string) $p['period_key']; }
    }
    if ($bad) {
        fwrite(STDERR, "\n  REFUSED — this would remove journal entries from a CLOSED period ("
            . implode(', ', $bad) . ").\n"
            . "  Reopen the period deliberately if that is really what you want.\n\n");
        exit(1);
    }
}

/* ------------------------------------------------------------------ */
/* Assemble the delete plan, children before parents                  */
/* ------------------------------------------------------------------ */

$cores = $invs ? $ids(Db::all('SELECT id FROM core_records WHERE invoice_id IN ' . $in($invs))) : [];
if ($customers) {
    $cores = $merge($cores, $ids(Db::all('SELECT id FROM core_records WHERE customer_id IN ' . $in($customers))));
}

$add = static function (string $table, string $sql, array $bind = []) use (&$plan): void {
    $n = (int) Db::val("SELECT COUNT(*) FROM $table WHERE $sql", $bind, 0);
    if ($n > 0) { $plan[] = [$table, $sql, $bind, $n]; }
};

if ($entries) { $add('journal_lines', 'entry_id IN ' . $in($entries)); }
if ($pays)    { $add('receipts', 'payment_id IN ' . $in($pays)); }
if ($invs)    { $add('receipts', 'invoice_id IN ' . $in($invs)); }
if ($pays)    { $add('payments', 'id IN ' . $in($pays)); }
if ($invs)    { $add('payment_links', 'invoice_id IN ' . $in($invs)); }
if ($cores)   { $add('core_records', 'id IN ' . $in($cores)); }
if ($invs)    { $add('doc_lines', "doc_type = 'INV' AND doc_id IN " . $in($invs)); }
if ($ests)    { $add('doc_lines', "doc_type = 'EST' AND doc_id IN " . $in($ests)); }
if ($wos)     { $add('doc_lines', "doc_type = 'WO'  AND doc_id IN " . $in($wos)); }
if ($entries) { $add('journal_entries', 'id IN ' . $in($entries)); }
if ($invs)    { $add('invoices', 'id IN ' . $in($invs)); }
if ($wos)     { $add('work_orders', 'id IN ' . $in($wos)); }
if ($ests)    { $add('estimates', 'id IN ' . $in($ests)); }
if ($srs)     { $add('service_requests', 'id IN ' . $in($srs)); }

foreach ([['INV', $invs], ['EST', $ests], ['WO', $wos], ['SR', $srs]] as [$dt, $list]) {
    if ($list) {
        $add('signature_requests', "doc_type = '$dt' AND doc_id IN " . $in($list));
        $add('location_requests',  "doc_type = '$dt' AND doc_id IN " . $in($list));
    }
}
if ($invs)    { $add('audit_log', "entity_type = 'invoice' AND entity_id IN " . $in($invs)); }
if ($cores)   { $add('audit_log', "entity_type = 'core_record' AND entity_id IN " . $in($cores)); }
if ($entries) { $add('audit_log', "entity_type = 'journal_entry' AND entity_id IN " . $in($entries)); }
if ($invs)    { $add('attachments', "entity_type = 'invoice' AND entity_id IN " . $in($invs)); }

if ($customers) {
    $add('messages', 'customer_id IN ' . $in($customers));
    $add('vehicles', 'customer_id IN ' . $in($customers));
    $add('customers', 'id IN ' . $in($customers));
}

/* The mirror is detached, never deleted. */
$detach = [];
if ($invs) { $detach[] = ['invoice_id', $invs]; }
if ($pays) { $detach[] = ['payment_id', $pays]; }
if ($entries) { $detach[] = ['posted_entry_id', $entries]; }
foreach ($detach as [$col, $list]) {
    $n = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE $col IN " . $in($list), [], 0);
    if ($n > 0) { $unlink[] = [$col, $list, $n]; }
}

/* ------------------------------------------------------------------ */
/* Report, then act                                                   */
/* ------------------------------------------------------------------ */

$label = match ($kind) {
    'invoice'  => 'Invoice ' . ($row['doc_number'] ?? $row['id']),
    'sr'       => 'Service request ' . ($row['doc_number'] ?? $row['id']),
    'journal'  => 'Journal entry ' . ($row['entry_no'] ?? $row['id']),
    'customer' => 'Customer #' . $row['id'] . ' ' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
};

printf("\n  Database: %s @ %s\n", $cfg['db']['database'] ?? '?', $cfg['db']['host'] ?? 'local');
printf("  Target:   %s\n\n", $label);

if (!$plan && !$unlink) { echo "  Nothing to remove.\n\n"; exit(0); }

$total = 0;
foreach ($plan as [$table, $sql, $bind, $n]) {
    printf("    delete  %-20s %5d row%s\n", $table, $n, $n === 1 ? '' : 's');
    $total += $n;
}
foreach ($unlink as [$col, $list, $n]) {
    printf("    detach  %-20s %5d row%s  (%s cleared, row kept)\n",
        'square_transactions', $n, $n === 1 ? '' : 's', $col);
}
printf("\n  %d row%s would be deleted.\n", $total, $total === 1 ? '' : 's');

if (!$commit) {
    echo "\n  Nothing was changed. Re-run with --yes to commit.\n\n";
    exit(0);
}

Db::tx(static function () use ($plan, $unlink): void {
    foreach ($unlink as [$col, $list]) {
        $ins = '(' . implode(',', array_map('intval', $list)) . ')';
        Db::q("UPDATE square_transactions SET $col = NULL WHERE $col IN $ins");
    }
    foreach ($plan as [$table, $sql, $bind]) {
        Db::q("DELETE FROM $table WHERE $sql", $bind);
    }
});

$line = sprintf("%s  PURGED  %-40s %d rows  (%s)%s",
    date('Y-m-d H:i:s'), $label, $total, $cfg['db']['database'] ?? '?', PHP_EOL);
@file_put_contents($root . '/' . PURGE_LOG, $line, FILE_APPEND);

printf("\n  Done — %d row%s removed. Logged to %s\n\n", $total, $total === 1 ? '' : 's', PURGE_LOG);
exit(0);
