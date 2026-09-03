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
 * Prove the geocoder works from THIS host — data/route_check.php's twin.
 *
 *   php data/geo_check.php
 *
 * Prints the configured driver, then runs one reverse lookup (a fixed
 * Portland point) and one forward lookup (a fixed Portland address) through
 * it, plus the last few geocode rows from api_log. Never prints the API key.
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

$geo = Integrations::geocoder();
echo 'Geocoding driver: ' . $geo->driverName()
   . ' (setting: ' . (trim((string) App::setting('driver_geocoder', '')) ?: '(unset — default)') . ")\n";

// Pioneer Courthouse Square. A reverse that fails here fails everywhere.
$r = $geo->reverse(45.5189700, -122.6792900);
echo 'Reverse: ' . ($r['address'] !== null
    ? 'OK — ' . $r['address'] . ' · ' . ($r['city'] ?? '?') . ', ' . ($r['state'] ?? '?')
    : 'NO ADDRESS (confidence: ' . $r['confidence'] . ')') . "\n";

$f = $geo->geocode('701 SW 6th Ave, Portland, OR');
echo 'Forward: ' . (($f['lat'] ?? null) !== null
    ? sprintf('OK — %.5f, %.5f (%s)', $f['lat'], $f['lng'], $f['confidence'])
    : 'NOT PLACED (confidence: ' . $f['confidence'] . ')') . "\n";

/* When the driver is Google and a call fails, surface Google's own words —
 * the driver logs only the status, and "REQUEST_DENIED" without its
 * error_message is a symptom, not a diagnosis. Prints the reason, never the key. */
if ($geo->driverName() === 'google' && ($r['address'] === null || ($f['lat'] ?? null) === null)) {
    $key = trim((string) App::setting('google_maps_key', ''));
    $res = Http::json('GET', 'https://maps.googleapis.com/maps/api/geocode/json?'
        . http_build_query(['address' => '701 SW 6th Ave, Portland, OR', 'key' => $key]));
    echo 'Google says: ' . (string) ($res['body']['status'] ?? '(no status)')
       . ' — ' . (string) ($res['body']['error_message'] ?? '(no message)') . "\n";
}

echo "Recent geocode calls:\n";
foreach (Db::all("SELECT created_at, driver, operation, ok, detail FROM api_log WHERE service = 'geocode' ORDER BY id DESC LIMIT 6") as $row) {
    echo '  ' . $row['created_at'] . '  ' . $row['driver'] . ' ' . $row['operation']
       . ' ok=' . $row['ok'] . '  ' . substr((string) $row['detail'], 0, 70) . "\n";
}
