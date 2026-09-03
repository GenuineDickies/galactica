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
declare(strict_types=1);

/**
 * The temporary setup admin.
 *
 *   php tests/setup_admin.php          (WKR_DB_PASS=... as usual)
 *
 * Exercises Rules::setupAdminHeal() and Rules::retireSetupAdmins() against
 * the configured database, then puts every row it touched back the way it
 * was. The fixture admin it inserts is removed at the end — that delete is
 * test cleanup, not application behaviour.
 */

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }

App::boot($cfg);
Db::boot($cfg['db']);

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; fwrite(STDOUT, "  \033[32mPASS\033[0m $label\n"); }
    else       { $fail++; fwrite(STDOUT, "  \033[31mFAIL\033[0m $label\n"); }
}

$FIXTURE = 'setup-admin-test@example.invalid';
Db::q('DELETE FROM users WHERE email = ?', [$FIXTURE]);   // stale fixture from a broken run

// The configured admin — the owner's own login on a production config. It must
// come out of every path below UNFLAGGED: flagging it is what previously armed
// retireSetupAdmins() to deactivate the account its owner was signed in with.
$configured = strtolower(trim((string) (App::config('install', [])['admin']['email'] ?? '')));

// The suite needs the temporary login present. A database seeded from a real
// production config has none, so stand one up and take it away again at the end.
$seeded = Db::one('SELECT * FROM users WHERE LOWER(email) = ?', [Rules::SETUP_EMAIL]);
$temp   = $seeded === null;
if ($temp) {
    Db::insert('users', [
        'role' => 'ADMIN', 'first_name' => 'Setup', 'last_name' => 'Admin',
        'email' => Rules::SETUP_EMAIL, 'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'is_active' => 1, 'is_setup' => 1, 'can_accept_jobs' => 0, 'created_at' => now(),
    ]);
}

fwrite(STDOUT, "\n\033[1m== heal: flags the throwaway address, spares the configured admin\033[0m\n");
Rules::setupAdminHeal();
Rules::setupAdminHeal();                                   // second call must be a no-op
$setup = Db::one('SELECT * FROM users WHERE is_setup = 1');
ok($setup !== null, 'the throwaway login is flagged is_setup');
ok($setup !== null && strtolower($setup['email']) === Rules::SETUP_EMAIL,
    'the flagged row is ' . Rules::SETUP_EMAIL . ', never a configured address');
ok((int) Db::val('SELECT COUNT(*) FROM users WHERE is_setup = 1') === 1, 'heal never flags a second row');
if ($configured !== '' && $configured !== Rules::SETUP_EMAIL) {
    ok((int) Db::val('SELECT COUNT(*) FROM users WHERE LOWER(email) = ? AND is_setup = 1', [$configured]) === 0,
        'the configured admin is never flagged — heal clears it if an old seed set it');
}

$wasActive = $setup !== null && (int) $setup['is_active'] === 1;

fwrite(STDOUT, "\n\033[1m== retire: only once a real admin exists\033[0m\n");
if ($setup !== null && !$wasActive) {
    // Put the setup admin back to active for the scenario, restored below.
    Db::update('users', (int) $setup['id'], ['is_active' => 1]);
}
$realBefore = (int) Db::val(
    "SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1 AND is_setup = 0"
);
if ($realBefore === 0) {
    ok(Rules::retireSetupAdmins() === [], 'no real admin yet -> setup login stays active');
    ok((int) Db::val('SELECT is_active FROM users WHERE id = ?', [(int) $setup['id']]) === 1,
        'setup admin still active');
} else {
    fwrite(STDOUT, "  (a real admin already exists in this database — skipping the stays-active case)\n");
}

$fixtureId = Db::insert('users', [
    'role' => 'ADMIN', 'first_name' => 'Test', 'last_name' => 'Fixture',
    'email' => $FIXTURE, 'password_hash' => password_hash('irrelevant-1', PASSWORD_DEFAULT),
    'is_active' => 1, 'is_setup' => 0, 'can_accept_jobs' => 0, 'created_at' => now(),
]);

$retired = Rules::retireSetupAdmins();
ok($realBefore > 0 || count($retired) === 1, 'real admin created -> setup login retired');
ok((int) Db::val('SELECT is_active FROM users WHERE id = ?', [(int) $setup['id']]) === 0,
    'setup admin is now inactive');
ok((int) Db::val('SELECT COUNT(*) FROM users WHERE id = ?', [(int) $setup['id']]) === 1,
    'setup admin row still exists — nothing deleted');
ok(Rules::retireSetupAdmins() === [], 'second retire call is a no-op');

if ($configured !== '' && $configured !== Rules::SETUP_EMAIL) {
    ok((int) Db::val('SELECT COUNT(*) FROM users WHERE LOWER(email) = ? AND is_active = 0', [$configured]) === 0,
        'the configured admin survived the retire — it was never deactivated');
}

fwrite(STDOUT, "\n\033[1m== retire never signs the operator out from under themselves\033[0m\n");
Db::update('users', (int) $setup['id'], ['is_active' => 1]);
$_SESSION['user'] = ['id' => (int) $setup['id'], 'role' => 'ADMIN'];
ok(Rules::retireSetupAdmins() === [], 'the signed-in account is skipped');
ok((int) Db::val('SELECT is_active FROM users WHERE id = ?', [(int) $setup['id']]) === 1,
    'signed-in setup admin stays active');
unset($_SESSION['user']);

// ---- restore ------------------------------------------------------------
if ($temp) { Db::q('DELETE FROM users WHERE id = ?', [(int) $setup['id']]); }
else       { Db::update('users', (int) $setup['id'], ['is_active' => $wasActive ? 1 : 0]); }
Db::q('DELETE FROM users WHERE id = ?', [$fixtureId]);
Db::q("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id IN (?, ?)", [(int) $setup['id'], $fixtureId]);

fwrite(STDOUT, "\n\033[1m$pass passed, $fail failed\033[0m\n");
exit($fail === 0 ? 0 : 1);
