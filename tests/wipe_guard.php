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
 * Tests for the wipe guard. Pure — writes throwaway policy files to a temp
 * directory and never touches a database.
 *   php tests/wipe_guard.php
 *
 * The property under test is that everything except one exact shape is a
 * refusal. Most of these assert a NO, which is the point.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Guard.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

$root = sys_get_temp_dir() . '/wkr_guard_' . bin2hex(random_bytes(4));
mkdir($root . '/data', 0777, true);
register_shutdown_function(static function () use ($root) {
    @unlink($root . '/data/wipe-policy.php');
    @unlink($root . '/data/wipe-attempts.log');
    @rmdir($root . '/data');
    @rmdir($root);
});

/** Write a policy file whose body is the given PHP literal. */
function policy(string $root, string $body): void {
    file_put_contents($root . '/data/wipe-policy.php', "<?php\nreturn " . $body . ";\n");
}
function allowed(string $root, array $db): bool {
    return WipeGuard::check($root, $db)['allowed'];
}

$local = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'wkr_admin'];

section('the one shape that says yes');
policy($root, "['allow_wipe' => true, 'databases' => ['wkr_admin']]");
check('exact true + named database', allowed($root, $local), true);

section('fails closed');
@unlink($root . '/data/wipe-policy.php');
check('missing policy file is a refusal', allowed($root, $local), false);
file_put_contents($root . '/data/wipe-policy.php', "<?php\nreturn 'not an array';\n");
check('non-array policy is a refusal', allowed($root, $local), false);
policy($root, "['databases' => ['wkr_admin']]");
check('absent allow_wipe is a refusal', allowed($root, $local), false);
policy($root, "['allow_wipe' => true]");
check('no databases list is a refusal', allowed($root, $local), false);
policy($root, "['allow_wipe' => true, 'databases' => []]");
check('empty databases list is a refusal', allowed($root, $local), false);

section('the switch is strict — a typo is not permission');
foreach (['1' => '1', "'true'" => "'true'", "'yes'" => "'yes'", 'null' => 'null', 'false' => 'false'] as $label => $lit) {
    policy($root, "['allow_wipe' => $lit, 'databases' => ['wkr_admin']]");
    check("allow_wipe = $label is a refusal", allowed($root, $local), false);
}

section('the owner saying no is final');
policy($root, "['allow_wipe' => false, 'databases' => ['wkr_admin']]");
check('locked database refuses', allowed($root, $local), false);
// The refusal must not depend on how the process was invoked. These are the
// levers something working from stale context would reach for.
putenv('WKR_ALLOW_WIPE=1');
putenv('WKR_FORCE=1');
$_ENV['WKR_ALLOW_WIPE'] = '1';
$_SERVER['argv'] = ['wipe.php', '--force', '--yes', '--allow-wipe'];
check('environment variables do not unlock it', allowed($root, $local), false);
check('command-line flags do not unlock it',    allowed($root, $local), false);
putenv('WKR_ALLOW_WIPE'); putenv('WKR_FORCE'); unset($_ENV['WKR_ALLOW_WIPE']);

section('a permissive policy cannot travel to another database');
policy($root, "['allow_wipe' => true, 'databases' => ['wkr_admin']]");
check('unnamed database refuses',
    allowed($root, ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'wkr_production']), false);
check('empty database name refuses',
    allowed($root, ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => '']), false);
check('named database still allowed', allowed($root, $local), true);

section('remote hosts are never wipeable, whatever the policy says');
policy($root, "['allow_wipe' => true, 'databases' => ['wkr_admin']]");
check('remote host refuses even when named',
    allowed($root, ['driver' => 'mysql', 'host' => 'galactica.wkrllc.com', 'database' => 'wkr_admin']), false);
check('a policy naming production and remote still refuses',
    allowed($root, ['driver' => 'mysql', 'host' => '203.0.113.9', 'database' => 'wkr_admin']), false);
check('localhost is fine',
    allowed($root, ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'wkr_admin']), true);

section('both outcomes are recorded to a file, since a wipe drops tables');
@unlink($root . '/data/wipe-attempts.log');
policy($root, "['allow_wipe' => false, 'databases' => ['wkr_admin']]");
$r = new ReflectionMethod(WipeGuard::class, 'record');
$r->setAccessible(true);
$r->invoke(null, $root, 'wkr_admin', WipeGuard::check($root, $local));
$log = (string) @file_get_contents($root . '/data/wipe-attempts.log');
check('refusal is logged', str_contains($log, 'REFUSED'), true);
check('log names the database', str_contains($log, 'wkr_admin'), true);

section('the shipped policy and the live wipe script agree');
$realRoot = dirname(__DIR__);
check('a real policy file exists', is_file($realRoot . '/data/wipe-policy.php'), true);
$wipe = (string) file_get_contents($realRoot . '/data/wipe.php');
check('wipe.php calls the guard', str_contains($wipe, 'WipeGuard::requireAllowed'), true);
// The guard has to run before the connection opens, or a refusal comes too late.
check('the guard runs before Db::boot',
    strpos($wipe, 'WipeGuard::requireAllowed') < strpos($wipe, 'Db::boot'), true);
check('wipe.php has no force flag', str_contains($wipe, '--force'), false);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
