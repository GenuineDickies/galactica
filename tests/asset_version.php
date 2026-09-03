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
 * Regression tests for asset(). Pure — no database, no server.
 *   php tests/asset_version.php
 *
 * WHY THIS FILE EXISTS. asset() computed the web root as `<root>/public`. On
 * shared hosting the served folder is named by the host — public_html — so
 * is_file() failed, no `?v=` was appended, and every CSS and JS change shipped
 * since launch was invisible to any browser with a warm cache. It failed in
 * total silence: an unversioned URL looks exactly like a correct one, so
 * nothing broke visibly and nobody had reason to look. It surfaced only when a
 * deployed map refused to appear and the file on the server turned out to be
 * 20KB larger than the file the browser had.
 *
 * The rule under test: a URL from asset() ALWAYS carries a version, whatever
 * the web root is called and even when the file cannot be found at all. The
 * fallback matters as much as the happy path — a missing version is the
 * failure mode that hides itself.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core.php';
require dirname(__DIR__) . '/app/helpers.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n",
        $label, var_export($want, true), var_export($got, true)); }
}
function ok(string $label, bool $cond): void { check($label, $cond, true); }
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/* A throwaway tree standing in for each hosting layout. asset() memoises the
 * directory it resolved, so each case runs in its own child process. */
$mode = $argv[1] ?? 'all';

if ($mode === 'child') {
    $dir  = $argv[2];
    $name = $argv[3];
    $want = $argv[4] ?? 'assets/js/app.js';
    App::boot(['app' => ['version' => '9.9.9-test', 'debug' => false, 'public_dir' => $dir . '/' . $name],
               'company' => ['tz' => 'UTC'], 'db' => []]);
    echo asset($want), "\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/wkr_asset_' . getmypid();
$made = [];
foreach (['public', 'public_html', 'htdocs'] as $name) {
    $d = $tmp . '/' . $name . '/assets/js';
    mkdir($d, 0777, true);
    file_put_contents($d . '/app.js', '// test');
    touch($d . '/app.js', 1700000000);
    $made[] = $name;
}

section('every web-root name yields a version');
foreach ($made as $name) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child '
        . escapeshellarg($tmp) . ' ' . escapeshellarg($name) . ' 2>&1', $out);
    $url = trim(implode('', $out));
    ok("$name emits a version", str_contains($url, '?v='));
    ok("$name uses the file's mtime", str_contains($url, '?v=1700000000'));
}

section('a bad public_dir still resolves through the fallback');
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child '
    . escapeshellarg($tmp) . ' ' . escapeshellarg('does_not_exist') . ' 2>&1', $out);
$url = trim(implode('', $out));
ok('still versioned', str_contains($url, '?v='));
/* The fallback finds the repository's own public/ and uses the REAL mtime of
 * the real file — which is the behaviour we want, not the app version. */
ok('uses a real mtime, not the fallback constant', (bool) preg_match('/\?v=\d{9,}$/', $url));

section('a file that exists nowhere still gets a version');
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child '
    . escapeshellarg($tmp) . ' ' . escapeshellarg('does_not_exist')
    . ' ' . escapeshellarg('assets/js/no-such-file-anywhere.js') . ' 2>&1', $out);
$url = trim(implode('', $out));
ok('still versioned', str_contains($url, '?v='));
ok('falls back to the app version', str_contains($url, '?v=9.9.9-test'));
ok('never bare — this is the failure mode that hid itself',
    !preg_match('/\.js$/', $url));

section('the front controller records the web root');
$idx = file_get_contents(dirname(__DIR__) . '/public/index.php');
ok("index.php sets app.public_dir", str_contains($idx, "\$cfg['app']['public_dir'] = __DIR__;"));
ok('it is set before App::boot', strpos($idx, "public_dir") < strpos($idx, 'App::boot'));

section('asset() does not hardcode a web-root name');
$src = file_get_contents(dirname(__DIR__) . '/app/helpers.php');
preg_match('/function asset\(.*?\n\}/s', $src, $m);
$fn = $m[0] ?? '';
ok('reads public_dir from config', str_contains($fn, "public_dir"));

/* Tidy up. */
foreach (['public', 'public_html', 'htdocs'] as $name) {
    @unlink($tmp . '/' . $name . '/assets/js/app.js');
    @rmdir($tmp . '/' . $name . '/assets/js');
    @rmdir($tmp . '/' . $name . '/assets');
    @rmdir($tmp . '/' . $name);
}
@rmdir($tmp);

printf("\n%d passed, %d failed\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
