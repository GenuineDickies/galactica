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
 * Prove the routing driver works from THIS host — the one that will call it.
 *
 *   php data/route_check.php
 *
 * Asks the configured RoutePlanner for a fixed Portland test route (downtown →
 * the airport) and prints the outcome. Never prints the API key. Exists
 * because a key restriction (wrong IP, missing Routes API) only shows itself
 * from the server the calls actually leave — a browser test proves nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

App::boot($cfg);
Db::boot($cfg['db']);

$planner = Integrations::routes();
echo 'Routing driver: ' . $planner->driverName() . "\n";
echo 'Maps key stored: ' . (trim((string) App::setting('google_maps_key', '')) !== '' ? 'yes' : 'NO') . "\n";

// Pioneer Courthouse Square → PDX. Roughly 12 road miles; sanity-check range.
$r = $planner->route(45.5189700, -122.6792900, 45.5886800, -122.5975000);
if (!$r['ok']) {
    echo 'FAIL: ' . $r['reason'] . "\n";
    exit(1);
}
echo sprintf("OK: %.1f mi · %d min (downtown Portland → PDX)\n", $r['miles'], $r['minutes']);
$sane = $r['miles'] > 5 && $r['miles'] < 25 && $r['minutes'] > 5 && $r['minutes'] < 90;
echo $sane ? "Result is in the sane range for that trip.\n" : "WARNING: result outside the expected range — check the response.\n";
exit($sane ? 0 : 1);
