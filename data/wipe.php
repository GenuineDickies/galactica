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
 * Reset the database to a clean, sellable state. DESTRUCTIVE — it drops every
 * table and rebuilds the schema. Take a backup first (see README / Deploying).
 *
 *   php data/wipe.php             CLEAN INSTALL — admin login + default settings,
 *                                 an empty catalog, numbering at 001
 *   php data/wipe.php --catalog   CLEAN WITH CATALOG — plus the example
 *                                 Products & Services price book
 *   php data/wipe.php --demo      FULL DEMO — example staff, customers and jobs
 *                                 (dev and testing only)
 *
 * Reads config.php, so it targets whatever database the application targets.
 *
 * GATED ON data/wipe-policy.php, which the owner controls. If that file says
 * no — or is missing, malformed, or does not name this database — this script
 * refuses and exits before touching anything. There is no override: no flag,
 * no environment variable, no argument. See app/Guard.php.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/app/Guard.php';

/* Before the arguments are read, before the connection is opened, before any
 * table is named. Exits non-zero if the owner has locked this database. */
WipeGuard::requireAllowed($root, $cfg['db']);

foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) { require $f; }

App::boot($cfg);
Db::boot($cfg['db']);

$args    = array_slice($argv, 1);
$demo    = in_array('--demo', $args, true);
$catalog = $demo || in_array('--catalog', $args, true);

$driver = Db::driver();
$where  = $driver === 'mysql'
    ? sprintf('%s@%s:%s/%s', $cfg['db']['username'], $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['database'])
    : $cfg['db']['path'];

fwrite(STDOUT, "Target: $driver $where\n");
fwrite(STDOUT, 'Mode:   ' . ($demo ? 'Full Demo' : ($catalog ? 'Clean with Catalog' : 'Clean Install')) . "\n");

$tables = ['journal_lines', 'journal_entries', 'square_sync_state', 'expense_rules', 'bank_transactions',
           'bank_sources', 'square_payout_entries', 'square_loans', 'square_customers', 'square_transactions',
           'core_records', 'location_requests', 'signature_requests', 'api_log', 'attachments', 'audit_log',
           'doc_lines', 'receipts', 'payments', 'payment_links', 'invoices', 'work_orders', 'estimates',
           'service_requests', 'messages', 'expenses', 'vehicles', 'customers', 'catalog_items', 'markup_tiers',
           'gl_account_tombstones', 'gl_accounts', 'settings', 'users', 'doc_counters'];

if ($driver === 'mysql') {
    Db::q('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) { Db::q("DROP TABLE IF EXISTS $t"); }
    Db::q('SET FOREIGN_KEY_CHECKS = 1');
} else {
    foreach ($tables as $t) { Db::q("DROP TABLE IF EXISTS $t"); }
}

Db::migrate();

require $root . '/data/seed.php';
seed_core();
if ($catalog) { seed_catalog(); }
if ($demo)    { seed_staff(); seed_demo_data(); }

$counts = [
    'users'     => (int) Db::val('SELECT COUNT(*) FROM users'),
    'settings'  => (int) Db::val('SELECT COUNT(*) FROM settings'),
    'catalog'   => (int) Db::val('SELECT COUNT(*) FROM catalog_items'),
    'customers' => (int) Db::val('SELECT COUNT(*) FROM customers'),
    'invoices'  => (int) Db::val('SELECT COUNT(*) FROM invoices'),
];

fwrite(STDOUT, sprintf(
    "Done. users=%d  settings=%d  catalog=%d  customers=%d  invoices=%d\n",
    $counts['users'], $counts['settings'], $counts['catalog'], $counts['customers'], $counts['invoices']
));

fwrite(STDOUT,
    "\nTEMPORARY setup admin (deactivates itself once you create a real admin):\n" .
    '  ' . Rules::SETUP_EMAIL . " / admin123\n" .
    ($demo ? "  dispatch@wkrllc.com / dispatch123\n  tech@wkrllc.com / tech123\n"
           : "Create your real admin, dispatcher and technician logins under Admin.\n"));
