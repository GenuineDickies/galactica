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
 * The signature sequencing rule.
 *   php tests/signature_gate.php
 *
 * The ESTIMATE only ever needs verbal approval — that is what releases the
 * technician. The customer's signature lives on the WORK ORDER, and it is what
 * releases the work. It may be taken on the technician's device or through a
 * link texted to the customer; both land in the same column.
 *
 *   dispatchGate    — priced + authorized (verbal counts). Releases the truck.
 *   workBeginsGate  — work order signed, when over the threshold. Releases the wrench.
 *
 * The completion sign-off is deliberately NOT gated: a customer cannot be
 * compelled to agree the job was done well.
 *
 * Pure: config is stubbed, no database and no server.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core.php';     // App::config
require dirname(__DIR__) . '/app/Domain.php';
require dirname(__DIR__) . '/app/Controllers/WorkOrderController.php';  // FLOW

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/* Rules reads thresholds through App::config('rules'); stub just that, and
   stub the one Lines call dispatchGate makes so this stays database-free. */
App::boot(['rules' => [
    'authorization_threshold' => 200.00,
    'variance_abs'            => 200.00,
    'variance_pct'            => 0.10,
]]);

/** An estimate row as the database would hand it back. Verbal approval only. */
function est(float $total, string $method = 'VERBAL', bool $authorized = true): array {
    return [
        'id'                   => 1,
        'doc_number'           => 'EST-20260729-001',
        'total'                => $total,
        'authorized_by'        => 'Dana Reyes',
        'authorization_method' => $method,
        'authorized_at'        => $authorized ? '2026-07-29 09:00:00' : null,
        'vehicle_id'           => 7,
    ];
}
/** A work order row. The signature lives HERE, not on the estimate. */
function wo(string $sig = '', ?string $signedAt = null, ?string $startedAt = null, string $method = 'IN_PERSON'): array {
    return [
        'id'               => 1,
        'doc_number'       => 'WOR-20260729-001',
        'auth_signature'   => $sig,
        'auth_signed_at'   => $signedAt,
        'auth_method'      => $method,
        'work_started_at'  => $startedAt,
    ];
}
const SIG = 'data:image/png;base64,iVBORw0KGgo=';

section('threshold');
check('$200.00 is not over the threshold', Rules::signatureRequired(200.00), false);
check('$200.01 is over',                   Rules::signatureRequired(200.01), true);
check('$600.00 is over',                   Rules::signatureRequired(600.00), true);

section('a small job needs no signature at all');
$small = est(150.00);
check('no signature required',  Rules::workAuthRequired($small), false);
check('nothing pending',        Rules::signaturePending($small, wo()), false);
check('work may begin unsigned', Rules::workBeginsGate($small, wo())['ok'], true);

section('a large job: verbal approval releases the truck, not the wrench');
$big = est(600.00);
check('signature required',    Rules::workAuthRequired($big), true);
check('flagged as pending',    Rules::signaturePending($big, wo()), true);
check('work may NOT begin',    Rules::workBeginsGate($big, wo())['ok'], false);
check('reason names the work order',
    str_contains(Rules::workBeginsGate($big, wo())['reason'], 'signs this work order'), true);
check('reason offers both paths',
    str_contains(Rules::workBeginsGate($big, wo())['reason'], 'text them the link'), true);

section('… and the job cannot be closed while unauthorized');
$gate = Rules::workOrderCompletionGate(wo(), $big);
check('completion blocked',   $gate['ok'], false);
check('reason cites authorization', str_contains($gate['reason'], 'never authorized the work'), true);

section('signed in person on the technician device');
$inPerson = wo(SIG, '2026-07-29 10:00:00', null, 'IN_PERSON');
check('authorization satisfied', Rules::workAuthSigned($inPerson), true);
check('nothing pending',         Rules::signaturePending($big, $inPerson), false);
check('work may begin',          Rules::workBeginsGate($big, $inPerson)['ok'], true);

section('signed remotely from a texted link — same gate, same effect');
$remote = wo(SIG, '2026-07-29 10:00:00', null, 'SMS');
check('authorization satisfied', Rules::workAuthSigned($remote), true);
check('work may begin',          Rules::workBeginsGate($big, $remote)['ok'], true);
check('the channel is recorded', $remote['auth_method'], 'SMS');

section('the recorded order of signature and work start');
check('signed 10:00, work started 10:05 — fine',
    Rules::signatureprecededWork(wo(SIG, '2026-07-29 10:00:00', '2026-07-29 10:05:00')), true);
check('signed at the same minute work started — fine',
    Rules::signatureprecededWork(wo(SIG, '2026-07-29 10:00:00', '2026-07-29 10:00:00')), true);
check('signed 10:00, work started 09:50 — breach',
    Rules::signatureprecededWork(wo(SIG, '2026-07-29 10:00:00', '2026-07-29 09:50:00')), false);
$late = wo(SIG, '2026-07-29 10:00:00', '2026-07-29 09:50:00');
check('completion refused when work preceded the signature',
    Rules::workOrderCompletionGate($late, $big)['ok'], false);
check('reason names the ordering problem',
    str_contains(Rules::workOrderCompletionGate($late, $big)['reason'], 'signed after work had already started'), true);

section('missing timestamps are not treated as a breach');
check('no work_started_at (job predates the column)',
    Rules::signatureprecededWork(wo(SIG, '2026-07-29 10:00:00', null)), true);
check('no auth_signed_at (small job, never needed one)',
    Rules::signatureprecededWork(wo('', null, '2026-07-29 10:00:00')), true);
check('small job still completable',
    Rules::workOrderCompletionGate(wo('', null, '2026-07-29 10:00:00'), est(150.00))['ok'], true);

section('IN_PROGRESS sits between arrival and completion');
check('flow order', WorkOrderController::FLOW,
    ['PENDING', 'ASSIGNED', 'EN_ROUTE', 'ON_SITE', 'IN_PROGRESS', 'COMPLETED']);

section('whitespace is not a signature');
check('blank-ish signature rejected', Rules::workAuthSigned(wo("  \n ")), false);
check('and so the gate still holds',  Rules::workBeginsGate($big, wo("  \n "))['ok'], false);

section('signature requests carry both purposes');
check('AUTH is described',       array_key_exists('AUTH', SignatureRequest::PURPOSES), true);
check('COMPLETION is described', array_key_exists('COMPLETION', SignatureRequest::PURPOSES), true);

section('the SMS templates are 10DLC-shaped');
foreach (['sign_auth', 'sign_done'] as $tpl) {
    $body = Sms::TEMPLATES[$tpl];
    check("$tpl carries the brand",   str_contains($body, '{co}'), true);
    check("$tpl carries the link",    str_contains($body, '{link}'), true);
    check("$tpl carries the opt-out", str_contains($body, 'Reply STOP to opt out'), true);
}

section('the clock these timestamps are written against');
/* The ordering evidence above is only worth anything if the clock is the
   business's own. php.ini is not trusted for this — local PHP bundles often
   ship a European timezone, which dated document numbers a day ahead of Oregon. */
$cfg = require dirname(__DIR__) . '/config.php';
check('config pins a timezone',     ($cfg['company']['tz'] ?? '') !== '', true);
App::boot($cfg);
check('App::boot applies it',       date_default_timezone_get(), $cfg['company']['tz']);
check('not left on a host default', in_array(date_default_timezone_get(), ['UTC', 'Europe/Berlin'], true), false);
/* Re-boot the stubbed rules the rest of the suite relies on. */
App::boot(['company' => $cfg['company'], 'rules' => [
    'authorization_threshold' => 200.00, 'variance_abs' => 200.00, 'variance_pct' => 0.10,
]]);

section('the variance rule is untouched by any of this');
check('lesser of $200 / 10% — small job', Rules::varianceThreshold(600.00), 60.00);
check('lesser of $200 / 10% — large job', Rules::varianceThreshold(5000.00), 200.00);
check('within tolerance',  Rules::varianceNeedsAuth(600.00, 650.00), false);
check('beyond tolerance',  Rules::varianceNeedsAuth(600.00, 700.00), true);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
