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
 * Signing helper for the webhook tests. Produces exactly what Telnyx and Square
 * produce, so the verification path is exercised for real rather than mocked.
 *
 *   php tests/sign.php keypair
 *   php tests/sign.php telnyx <secret-key-b64> <timestamp> <body>
 *   php tests/sign.php square <signature-key> <notification-url> <body>
 */
declare(strict_types=1);

$cmd = $argv[1] ?? '';

if ($cmd === 'keypair') {
    $pair = sodium_crypto_sign_keypair();
    echo base64_encode(sodium_crypto_sign_secretkey($pair)), ' ',
         base64_encode(sodium_crypto_sign_publickey($pair)), "\n";
    exit;
}

if ($cmd === 'telnyx') {
    [$sk, $ts, $body] = [base64_decode($argv[2], true), $argv[3], $argv[4]];
    echo base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $sk)), "\n";
    exit;
}

if ($cmd === 'square') {
    [$key, $url, $body] = [$argv[2], $argv[3], $argv[4]];
    echo base64_encode(hash_hmac('sha256', $url . $body, $key, true)), "\n";
    exit;
}

fwrite(STDERR, "unknown command\n");
exit(1);
