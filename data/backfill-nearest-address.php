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
 * Re-derive the nearest address for captured positions that never got one.
 *
 *   php data/backfill-nearest-address.php
 *
 * A locate-link answer enriches at capture time; while the geocoding driver
 * was broken (Google REQUEST_DENIED, 2026-08-30/31) captures stored the pin
 * and nothing else. This walks every service request and estimate that has
 * coordinates but no nearest_address, runs the reverse lookup they missed,
 * and fills exactly what capture would have: nearest address, intersection,
 * and — on requests with no city — city/state/ZIP. Typed values are never
 * overwritten. Safe to run twice; audit-logged per document. Sleeps between
 * lookups out of respect for the OSM driver's rate limits.
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

$geo  = Integrations::geocoder();
$done = 0;
$miss = 0;

foreach (['service_requests' => 'service_request', 'estimates' => 'estimate'] as $table => $entity) {
    $rows = Db::all(
        "SELECT * FROM $table
         WHERE latitude IS NOT NULL AND longitude IS NOT NULL
           AND (nearest_address IS NULL OR nearest_address = '')"
    );
    foreach ($rows as $doc) {
        $near = $geo->reverse((float) $doc['latitude'], (float) $doc['longitude']);
        $addr  = $near['address'] !== null ? substr((string) $near['address'], 0, 250) : null;
        $cross = $near['intersection'] !== null ? substr((string) $near['intersection'], 0, 155) : null;

        if ($addr === null && $cross === null && ($near['city'] ?? null) === null) {
            $miss++;
            echo "  $table #{$doc['id']}: no address near the pin (confidence {$near['confidence']})\n";
            sleep(1);
            continue;
        }

        $set = ['nearest_address' => $addr, 'nearest_intersection' => $cross, 'updated_at' => now()];
        if ($table === 'service_requests' && (string) $doc['city'] === '') {
            if (($near['city'] ?? null) !== null)  { $set['city'] = (string) $near['city']; }
            if (($near['state'] ?? null) !== null) { $set['state'] = (string) $near['state']; }
            if (($near['postal_code'] ?? null) !== null && (string) $doc['postal_code'] === '') {
                $set['postal_code'] = (string) $near['postal_code'];
            }
        }
        Db::update($table, (int) $doc['id'], $set);
        Audit::log($entity, (int) $doc['id'], 'location:backfilled',
            'nearest address derived after the fact — capture-time lookup had failed (geocoder outage): '
            . ($addr ?? $cross ?? '—'));
        $done++;
        echo "  $table #{$doc['id']}: " . ($addr ?? $cross) . "\n";
        sleep(1);
    }
}

// The answered locate links behind those documents get the same repair.
foreach (Db::all(
    "SELECT * FROM location_requests
     WHERE status = 'RECEIVED' AND latitude IS NOT NULL
       AND (nearest_address IS NULL OR nearest_address = '') AND doc_type <> 'WO'"
) as $lr) {
    $near  = $geo->reverse((float) $lr['latitude'], (float) $lr['longitude']);
    $addr  = $near['address'] !== null ? substr((string) $near['address'], 0, 250) : null;
    $cross = $near['intersection'] !== null ? substr((string) $near['intersection'], 0, 155) : null;
    if ($addr !== null || $cross !== null) {
        Db::update('location_requests', (int) $lr['id'],
            ['nearest_address' => $addr, 'nearest_intersection' => $cross]);
    }
    sleep(1);
}

echo "$done filled" . ($miss ? ", $miss had nothing addressable nearby" : '') . ".\n";
