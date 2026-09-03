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
 * The integration log renders, and says what each row MEANS.
 *
 * A view test rather than a unit test, because the failure this guards against
 * is a template one: an undefined variable, a helper that is not loaded, a
 * badge tone that has no CSS behind it. Requesting the route only proves the
 * router works — an authenticated request with rows in it is what proves the
 * page does.
 *
 * The rows below are the shapes that actually matter: the success, the two
 * different kinds of rejection, and the one that LOOKS like a failure but is
 * the location guard working correctly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

ini_set('display_errors', '1');
error_reporting(E_ALL);   // an undefined index in a view must fail this test

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Services.php';
App::boot($cfg);

$pass = 0; $fail = 0;
function check(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  \033[32mPASS\033[0m $what\n"; return; }
    $fail++;
    echo "  \033[31mFAIL\033[0m $what (want " . var_export($want, true)
       . ", got " . var_export($got, true) . ")\n";
}
function section(string $s): void { echo "\n\033[1m== $s\033[0m\n"; }

/* Every operation the webhook handler can write, plus a plain outside call. */
$rows = [
    ['id' => 7, 'service' => 'payment', 'driver' => 'square', 'operation' => 'webhook:payment',
     'reference' => 'sqpay_OK', 'ok' => 1, 'detail' => '$85.00 recorded against INV-20260818-004',
     'created_at' => date('Y-m-d H:i:s')],
    ['id' => 6, 'service' => 'payment', 'driver' => 'square', 'operation' => 'webhook:not-ours',
     'reference' => 'sqpay_THEIRS', 'ok' => 1, 'detail' => 'Location LXOTHER is not this business — ignored.',
     'created_at' => date('Y-m-d H:i:s')],
    ['id' => 5, 'service' => 'payment', 'driver' => 'square', 'operation' => 'webhook:rejected',
     'reference' => '203.0.113.9', 'ok' => 0, 'detail' => 'Signature did not verify · 203.0.113.9',
     'created_at' => date('Y-m-d H:i:s')],
    ['id' => 4, 'service' => 'payment', 'driver' => 'square', 'operation' => 'webhook:orphan',
     'reference' => 'sqpay_LOOSE', 'ok' => 0, 'detail' => 'No invoice matches order ord_77',
     'created_at' => date('Y-m-d H:i:s')],
    /* A row whose operation is NOT in the meanings map, and whose optional
     * columns are null — the shape most likely to throw. */
    ['id' => 3, 'service' => 'sms', 'driver' => 'telnyx', 'operation' => 'send',
     'reference' => null, 'ok' => 1, 'detail' => null,
     'created_at' => date('Y-m-d H:i:s')],
];

$render = static function (array $rows, array $f, int $failures, int $total): string {
    $services = [['service' => 'payment', 'n' => 4, 'bad' => 2],
                 ['service' => 'sms',     'n' => 1, 'bad' => 0]];
    $per_page = 200;
    ob_start();
    include dirname(__DIR__) . '/app/Views/pages/api_log.php';
    return (string) ob_get_clean();
};

$blank = ['service' => '', 'outcome' => '', 'q' => ''];

section('the page renders with every row shape the handler can write');
$html = $render($rows, $blank, 2, 5);
check('it produced output',            strlen($html) > 500, true);
check('no PHP notice leaked in',       str_contains($html, 'Warning'), false);
check('no undefined-variable notice',  str_contains($html, 'Undefined'), false);

section('a row says in words what its code means');
/* The whole reason the screen exists: "webhook:rejected" on its own tells a
 * non-engineer nothing, and the signature-key/URL mismatch it usually means
 * is the single most likely thing to be wrong. */
check('the raw code is shown',      str_contains($html, 'webhook:rejected'), true);
check('and so is the explanation',  str_contains($html, 'signature key in Settings'), true);
check('the URL trap is named',      str_contains($html, 'character-for-character'), true);

section('a foreign location reads as ignored, not as a failure');
/* ok = 1 with a rejection-sounding name. Green would misrepresent it and red
 * would send somebody chasing a fault that does not exist. */
check('badged IGNORED',    str_contains($html, '>IGNORED<'), true);
check('and toned neutral', str_contains($html, 'badge--slate'), true);

section('badge tones all exist in the stylesheet');
/* A tone with no CSS behind it renders as an unstyled word — the failure that
 * a route test cannot see. */
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
preg_match_all('/badge--([a-z]+)/', $html, $m);
foreach (array_unique($m[1]) as $tone) {
    check("badge--$tone is styled", str_contains($css, '.badge--' . $tone), true);
}

section('a null reference or detail does not break the row');
check('nulls render as a dash', substr_count($html, '>—<') >= 2, true);

section('the empty and unfiltered states both render');
$none = $render([], ['service' => 'payment', 'outcome' => 'fail', 'q' => 'zzz'], 0, 0);
check('empty state shown',      str_contains($none, 'Nothing matches'), true);
check('clear link offered',     str_contains($none, 'Clear'), true);
$virgin = $render([], $blank, 0, 0);
check('a never-used log explains itself', str_contains($virgin, 'Nothing has been logged yet'), true);

section('a clean log is not reported as a problem');
$clean = $render([$rows[0]], $blank, 0, 1);
check('says no failures', str_contains($clean, 'No failures on record'), true);

echo "\n\033[1m$pass passed, $fail failed\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
