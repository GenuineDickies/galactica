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
 * Location-capture flow — the parts that must not regress:
 *
 *   1. Db::migrate() adds the location_requests table and the snapshot
 *      columns on service_requests / estimates, additively.
 *   2. LocationRequest lifecycle: issue → open → supersede → expire →
 *      receive, with the one-shot and 4-hour rules enforced.
 *   3. The SR-stage SMS gate: consent checkbox + valid E.164, and blocked
 *      sends recorded, never silently dropped.
 *
 * Run:  php tests/location_capture.php
 * Uses the configured database (same as the app). Rows created here are
 * clearly marked and removed at the end.
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

$pass = 0; $fail = 0;
function ok(bool $cond, string $what): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $what\n"; }
    else       { $fail++; echo "FAIL  $what\n"; }
}

/* 1 ── migration ----------------------------------------------------- */
Db::migrate();
$mysql = Db::driver() === 'mysql';
$col = function (string $table, string $name) use ($mysql): bool {
    return $mysql
        ? (bool) Db::one(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $name])
        : in_array($name, array_column(Db::all("PRAGMA table_info($table)"), 'name'), true);
};
ok($col('location_requests', 'token'),                 'location_requests table exists');
ok($col('service_requests', 'nearest_address'),        'service_requests.nearest_address added');
ok($col('service_requests', 'nearest_intersection'),   'service_requests.nearest_intersection added');
ok($col('service_requests', 'location_captured_at'),   'service_requests.location_captured_at added');
ok($col('estimates', 'nearest_address'),               'estimates.nearest_address added');
ok($col('estimates', 'location_captured_at'),          'estimates.location_captured_at added');

/* 2 ── LocationRequest lifecycle ------------------------------------- */
$srId = Db::insert('service_requests', [
    'doc_number'     => 'TEST-LOC-' . bin2hex(random_bytes(4)),
    'channel'        => 'PHONE',
    'status'         => 'PENDING',
    'reported_name'  => '__location_capture_test__',
    'reported_phone' => '+15035550123',
    'comms_consent'  => 1,
    'created_at'     => now(),
    'updated_at'     => now(),
]);

$a = LocationRequest::issue('SR', $srId, '+15035550123', $srId);
ok($a !== [] && strlen((string) $a['token']) === 48,   'issue() returns a 48-char token');
ok($a['status'] === 'OPEN',                            'new request is OPEN');
ok(strtotime((string) $a['expires_at']) <= time() + LocationRequest::EXPIRY_HOURS * 3600 + 5,
                                                       'expiry is within ' . LocationRequest::EXPIRY_HOURS . ' hours');

$b = LocationRequest::issue('SR', $srId, '+15035550123', $srId);
$a2 = LocationRequest::byToken((string) $a['token']);
ok($a2['status'] === 'VOID',                           'a newer link supersedes the old one');
ok(LocationRequest::openFor('SR', $srId)['id'] === $b['id'], 'openFor() finds only the live link');

// Expiry is decided at read time.
Db::update('location_requests', (int) $b['id'], ['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
ok(LocationRequest::openFor('SR', $srId) === null,     'an expired link is no longer open');
ok(LocationRequest::byToken((string) $b['token'])['status'] === 'EXPIRED',
                                                       'the first touch after the deadline marks it EXPIRED');

$c = LocationRequest::issue('SR', $srId, '+15035550123', $srId);
LocationRequest::markReceived($c, 45.5231000, -122.6765000, 12.0,
    '1234 SE Stark St, Portland, OR 97214', 'SE Stark St & SE 12th Ave', 'osm');
$c2 = LocationRequest::byToken((string) $c['token']);
ok($c2['status'] === 'RECEIVED',                       'markReceived() closes the link (one-shot)');
ok(abs((float) $c2['latitude'] - 45.5231) < 1e-6,      'coordinates stored exactly');
ok($c2['nearest_intersection'] === 'SE Stark St & SE 12th Ave', 'intersection stored');
ok(LocationRequest::receivedFor('SR', $srId)['id'] === $c['id'], 'receivedFor() finds the answer');

/* 3 ── the SR-stage SMS gate ----------------------------------------- */
$sr = Db::one('SELECT * FROM service_requests WHERE id = ?', [$srId]);

$g1 = Sms::queueForRequest($sr, 'locate', ['{link}' => 'https://example.test/locate/x']);
ok($g1['ok'] === true,                                 'consent + valid phone → send allowed');

$srNoConsent = array_merge($sr, ['comms_consent' => 0]);
$g2 = Sms::queueForRequest($srNoConsent, 'locate', ['{link}' => 'x']);
ok($g2['ok'] === false,                                'no intake consent → blocked');

$srBadPhone = array_merge($sr, ['reported_phone' => 'nonsense']);
$g3 = Sms::queueForRequest($srBadPhone, 'locate', ['{link}' => 'x']);
ok($g3['ok'] === false,                                'unparseable phone → blocked');

$blocked = Db::one(
    "SELECT * FROM messages WHERE service_request_id = ? AND status = 'BLOCKED' ORDER BY id DESC", [$srId]);
ok($blocked !== null && $blocked['blocked_reason'] !== '', 'blocked sends are recorded, never dropped');

/* ── clean up -------------------------------------------------------- */
Db::q('DELETE FROM location_requests WHERE service_request_id = ?', [$srId]);
Db::q('DELETE FROM messages WHERE service_request_id = ?', [$srId]);
Db::q('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', ['service_request', $srId]);
Db::q('DELETE FROM service_requests WHERE id = ?', [$srId]);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
