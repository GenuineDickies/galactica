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
 * One-shot cleanup, 2026-08-31: null every stored promised_eta.
 *
 *   php data/clear-fabricated-etas.php
 *
 * Until today promised_eta was clocked automatically from the priority at
 * intake — a promise nobody made. The field is manual-only now (recorded only
 * when a dispatcher actually quoted the caller a time), so every value that
 * exists predates the change and is known to be fabricated. Clearing them is
 * a correction, not a deletion of testimony; the audit trail records it.
 * Safe to run twice — the second run finds nothing to clear.
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

$n = Db::q('UPDATE service_requests SET promised_eta = NULL WHERE promised_eta IS NOT NULL')->rowCount();
Db::insert('audit_log', [
    'entity_type' => 'service_request',
    'entity_id'   => 0,
    'action'      => 'promised_eta:cleared',
    'detail'      => "auto-derived ETAs nulled on $n requests — promised_eta is manual-only as of 2026-08-31; these were promises nobody made",
    'created_at'  => now(),
]);
echo $n . " fabricated promised ETAs cleared.\n";
