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
 * Drops the application's tables and rebuilds them from Schema, then seeds.
 * Used by the test suite so every run starts from the same known state.
 *
 * Deliberately noisy about which database it is about to empty: this is the one
 * script in the project that destroys data.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) { require $f; }

App::boot($cfg);
Db::boot($cfg['db']);

$driver = Db::driver();
$where  = $driver === 'mysql'
    ? sprintf('%s@%s:%s/%s', $cfg['db']['username'], $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['database'])
    : $cfg['db']['path'];

fwrite(STDERR, "reset: $driver $where\n");

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
seed_reference_data();

fwrite(STDERR, "reset: seeded\n");
