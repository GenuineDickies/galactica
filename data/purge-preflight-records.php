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
 * ONE-TIME, PRE-LAUNCH ONLY. Clear the test traffic entered while building,
 * so the install opens for business with an empty ledger.
 *
 *   php data/purge-preflight-records.php            list what would go, delete nothing
 *   php data/purge-preflight-records.php --apply    delete it
 *   php data/purge-preflight-records.php --apply --keep-numbering
 *                                                   leave doc_counters where they are
 *
 * WHAT IT REMOVES. The document chain and the parties to it: service requests,
 * estimates, work orders, invoices, payments, receipts, payment links, lines,
 * attachments, messages, customers, vehicles — and the audit rows that describe
 * them. Document numbering resets to 001 unless --keep-numbering.
 *
 * WHAT IT KEEPS. Users, settings, markup tiers, the catalog, GL accounts. The
 * configuration of the business is not its trading history.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS AN EXCEPTION, AND WHY IT IS SAFE TO HAVE AROUND
 *
 * This application does not delete things. Voids and credits, an append-only
 * audit_log — that is the whole design, because a system that can quietly
 * remove a payment cannot be trusted about any of them. This script breaks
 * that rule exactly once, for records that were never real: nobody called,
 * nothing was dispatched, no money moved.
 *
 * So it will not take anyone's word for that. It REFUSES if the database shows
 * any sign of genuine trading — a payment, a receipt, a sent invoice — because
 * beyond that point "these are only tests" has stopped being true and the
 * honest instruments are a void and a credit note. There is no --force. The
 * guard is the point of the script; a switch to defeat it would make this an
 * ordinary deleter with a comment claiming otherwise.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$args    = array_slice($argv, 1);
$apply   = in_array('--apply', $args, true);
$keepNum = in_array('--keep-numbering', $args, true);

$where = Db::driver() === 'mysql'
    ? sprintf('%s@%s/%s', $cfg['db']['username'], $cfg['db']['host'], $cfg['db']['database'])
    : (string) $cfg['db']['path'];
fwrite(STDOUT, "Target: " . Db::driver() . " $where\n");
fwrite(STDOUT, 'Mode:   ' . ($apply ? 'APPLY — records will be deleted' : 'dry run — nothing will be deleted') . "\n\n");

/** COUNT that survives a table this install has never created. */
$count = static function (string $t): int {
    try { return (int) Db::val("SELECT COUNT(*) FROM $t"); } catch (Throwable) { return -1; }
};

/* ---- The refusal. Checked before anything is read for deletion. ---------- */
$evidence = [];
foreach ([
    'payments'  => 'a payment has been taken',
    'receipts'  => 'a receipt has been issued',
] as $t => $why) {
    $n = $count($t);
    if ($n > 0) { $evidence[] = "$why ($n row(s) in $t)"; }
}
$sentInvoices = 0;
try {
    $sentInvoices = (int) Db::val("SELECT COUNT(*) FROM invoices WHERE status <> 'DRAFT'");
} catch (Throwable) { $sentInvoices = 0; }
if ($sentInvoices > 0) { $evidence[] = "an invoice has left draft ($sentInvoices row(s))"; }

if ($evidence !== []) {
    fwrite(STDERR, "REFUSED — this database has traded:\n");
    foreach ($evidence as $e) { fwrite(STDERR, "  · $e\n"); }
    fwrite(STDERR,
        "\nThis script is for clearing test traffic before launch, and that is no\n"
      . "longer what these records are. Money that moved gets voided or credited,\n"
      . "not deleted. Nothing has been changed.\n");
    exit(1);
}

/* ---- What would go. Children first, parents last. ----------------------- */
/* api_log IS PERSONAL DATA, despite reading like telemetry. Its `reference`
 * column holds the thing each outside call was about, and for the drivers this
 * app uses that is a customer's mobile number on an SMS row and their GPS
 * position on a geocode row — with the reverse-geocoded street address sitting
 * in `detail` beside it. A purge that clears customers and leaves this behind
 * has kept a list of who was texted and where they were standing. */
$tables = ['doc_lines', 'attachments', 'messages', 'payment_links', 'receipts', 'payments',
           'invoices', 'work_orders', 'estimates', 'service_requests', 'vehicles', 'customers',
           'expenses', 'api_log', 'audit_log'];

fwrite(STDOUT, "Records to remove\n");
$total = 0;
foreach ($tables as $t) {
    $n = $count($t);
    if ($n < 0) { fwrite(STDOUT, sprintf("  %-16s (no such table)\n", $t)); continue; }
    $total += $n;
    fwrite(STDOUT, sprintf("  %-16s %d\n", $t, $n));
}

fwrite(STDOUT, "\nKept\n");
foreach (['users', 'settings', 'markup_tiers', 'catalog_items', 'gl_accounts'] as $t) {
    $n = $count($t);
    if ($n >= 0) { fwrite(STDOUT, sprintf("  %-16s %d\n", $t, $n)); }
}

$counters = $count('doc_counters');
fwrite(STDOUT, "\nNumbering\n  doc_counters     " . ($counters < 0 ? '(no such table)' : (string) $counters)
    . ($keepNum ? "   — left as is\n" : "   — cleared, next document is 001\n"));

if (!$apply) {
    fwrite(STDOUT, "\n$total record(s) would be removed. Re-run with --apply to do it.\n");
    exit(0);
}

/* ---- Do it. -------------------------------------------------------------- */
$mysql = Db::driver() === 'mysql';
if ($mysql) { Db::q('SET FOREIGN_KEY_CHECKS = 0'); }
$done = [];
foreach ($tables as $t) {
    if ($count($t) < 0) { continue; }
    Db::q("DELETE FROM $t");
    $done[] = $t;
}
if (!$keepNum && $counters >= 0) { Db::q('DELETE FROM doc_counters'); $done[] = 'doc_counters'; }
if ($mysql) { Db::q('SET FOREIGN_KEY_CHECKS = 1'); }

/* The audit_log was emptied with everything else — it described the records
 * that are gone. Leave one entry behind saying so, because a log that jumps
 * from nothing to the first real job with no explanation is its own puzzle. */
Audit::log('system', 0, 'purged',
    'Pre-launch purge of test records: ' . $total . ' row(s) across '
    . implode(', ', $done) . '. Configuration, users and catalog retained.');

fwrite(STDOUT, "\nDone — $total record(s) removed.\n");
foreach ($tables as $t) {
    $n = $count($t);
    if ($n > 0) { fwrite(STDERR, "  WARNING: $t still holds $n row(s)\n"); }
}
