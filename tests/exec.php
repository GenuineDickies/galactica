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
 * Test fixtures that need to write. Goes through the application's own config
 * so the suite behaves identically on MySQL and SQLite.
 *
 *   php tests/exec.php setting <key> <value>
 *   php tests/exec.php link <invoice-id> <order-id>
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/app/Db.php';
require $root . '/app/Schema.php';
require $root . '/app/helpers.php';

Db::boot($cfg['db']);

switch ($argv[1] ?? '') {
    case 'setting':
        Db::q('DELETE FROM settings WHERE skey = ?', [$argv[2]]);
        Db::insert('settings', ['skey' => $argv[2], 'svalue' => $argv[3]]);
        break;

    case 'link':
        Db::insert('payment_links', [
            'invoice_id' => (int) $argv[2],
            'provider'   => 'square',
            'link_id'    => 'LNK-TEST',
            'order_id'   => $argv[3],
            'url'        => 'https://example.test/pay',
            'amount'     => 25.00,
            'status'     => 'OPEN',
            'created_at' => now(),
        ]);
        break;

    case 'pin':
        // A location pin on a service request, as if the dispatcher dropped
        // one — promotion refuses a request with no position.
        Db::update('service_requests', (int) $argv[2], [
            'latitude'             => sprintf('%.7F', (float) $argv[3]),
            'longitude'            => sprintf('%.7F', (float) $argv[4]),
            'location_captured_at' => now(),
            'updated_at'           => now(),
        ]);
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}
