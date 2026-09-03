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
 * Deleting a chart-of-accounts row, and having it stay deleted.
 *   php tests/account_delete.php
 *
 * Runs against a throwaway SQLite file, so it needs no server and never
 * touches the working database.
 *
 * THE PROPERTY UNDER TEST is not "delete removes a row" — that is one line of
 * SQL and would pass without any of this. It is that a deleted DEFAULT stays
 * deleted. Accounts::ensureSeeded() is additive and runs on every read, so
 * without a tombstone the row reappears on the next page load and the Delete
 * button silently does nothing. That failure is invisible in a unit test that
 * only checks the DELETE, which is exactly why it is checked here.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }

/* A scratch SQLite database, removed on the way out. */
$dbFile = sys_get_temp_dir() . '/wkr_acctdel_' . bin2hex(random_bytes(4)) . '.sqlite';
register_shutdown_function(static function () use ($dbFile) { @unlink($dbFile); });

$cfg['db'] = ['driver' => 'sqlite', 'path' => $dbFile];
App::boot($cfg);
Db::boot($cfg['db']);
Db::migrate();

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/** Is this account number currently a row? */
function present(string $n): bool {
    return (bool) Db::one('SELECT id FROM gl_accounts WHERE account_number = ?', [$n]);
}
function idOf(string $n): int {
    return (int) Db::val('SELECT id FROM gl_accounts WHERE account_number = ?', [$n], 0);
}

section('the table seeds itself');
Accounts::ensureSeeded();
$seeded = count(Accounts::all());
check('every default is present', $seeded, count(Accounts::DEFAULTS));
check('the tombstone table exists', Db::tableExists('gl_account_tombstones'), true);
check('nothing is tombstoned yet', Accounts::tombstoned(), []);

section('deleting a seeded default');
check('5040 starts present', present('5040'), true);
check('delete reports no errors', Accounts::delete(idOf('5040')), []);
check('the row is gone', present('5040'), false);
check('and it is tombstoned', Accounts::tombstoned(), ['5040']);

section('THE POINT — the seeder does not undo it');
Accounts::ensureSeeded();
check('still gone after a re-seed', present('5040'), false);
Accounts::all();          // all() seeds too, the path the accounts page takes
Accounts::forType('COGS');
check('still gone after all() and forType()', present('5040'), false);
check('the chart is one shorter', count(Accounts::all()), $seeded - 1);

section('re-adding by hand clears the tombstone');
check('create reports no errors', Accounts::create('5040', 'COGS — Merchant Processing Fees', 'COGS'), []);
check('the row is back', present('5040'), true);
check('the tombstone is cleared', Accounts::tombstoned(), []);
check('re-seeding does not duplicate it',
    (int) Db::val('SELECT COUNT(*) FROM gl_accounts WHERE account_number = ?', ['5040'], 0), 1);

section('an operator-created account needs no tombstone');
check('created', Accounts::create('4999', 'Scratch Revenue', 'REVENUE'), []);
check('deleted', Accounts::delete(idOf('4999')), []);
check('no tombstone written — nothing would re-seed it', Accounts::tombstoned(), []);
Accounts::ensureSeeded();
check('and it stays gone', present('4999'), false);

section('deleting is reported, not refused, when the number is in use');
Accounts::create('4998', 'Used Revenue', 'REVENUE');
Db::insert('catalog_items', [
    'sku' => 'TST-1', 'name' => 'Test item', 'item_type' => 'SERVICE',
    'revenue_account' => '4998', 'is_active' => 1,
]);
check('usage sees the catalog item', Accounts::usage('4998'), ['catalog_items' => 1]);
check('delete still succeeds', Accounts::delete(idOf('4998')), []);
check('the account is gone', present('4998'), false);
check('the catalog item survives with its number intact',
    Db::val('SELECT revenue_account FROM catalog_items WHERE sku = ?', ['TST-1'], ''), '4998');

section('a missing account');
check('deleting an unknown id is an error, not a crash',
    Accounts::delete(999999),
    ['That account is not in the chart of accounts — it may have been deleted. Refresh the page and pick again.']);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
