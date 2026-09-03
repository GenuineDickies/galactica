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
 * Scalar query helper for the test suite, going through the application's own
 * config so the assertions run against whichever engine the app is using.
 *
 *   php tests/query.php "select count(*) from users"
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/app/Db.php';
require $root . '/app/Schema.php';

Db::boot($cfg['db']);
echo (string) Db::val($argv[1] ?? 'SELECT 1');
