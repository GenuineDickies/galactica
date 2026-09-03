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
 * Live probe of the OSM reverse-geocoding driver — NOT part of the regular
 * suite, because it talks to nominatim.openstreetmap.org and overpass-api.de
 * for real. Run it by hand when touching the driver:
 *
 *   php tests/geocode_live.php
 *
 * Uses a fixed point in Portland (SE 12th & Stark area) and prints what the
 * driver resolves. Failing lookups are not failures of the app — the flow
 * stores coordinates first and treats the lookup as best-effort.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
App::boot($cfg);
Db::boot($cfg['db']);

$geo = new OsmGeocoder();
$t0  = microtime(true);
$res = $geo->reverse(45.5202000, -122.6534000);   // SE Stark & 12th-ish, Portland
$ms  = round((microtime(true) - $t0) * 1000);

printf("driver:       %s (%d ms)\n", $geo->driverName(), $ms);
printf("address:      %s\n", $res['address'] ?? '(none)');
printf("intersection: %s\n", $res['intersection'] ?? '(none)');
printf("confidence:   %s\n", $res['confidence']);

exit($res['address'] !== null || $res['intersection'] !== null ? 0 : 1);
