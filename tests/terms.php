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
 * Unit tests for business accounts: payment terms, invoice due dates, the
 * customer type gate, and the display-name rule. Pure — no database, no server.
 *   php tests/terms.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Domain.php';
require dirname(__DIR__) . '/app/helpers.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

section('terms label → days (COD is the default, never implied by type)');
check('DUE_ON_RECEIPT → null (COD)', Rules::termsDays('DUE_ON_RECEIPT'), null);
check('NET_15 → 15',                 Rules::termsDays('NET_15'), 15);
check('NET_30 → 30',                 Rules::termsDays('NET_30'), 30);
check('null → null (legacy rows)',   Rules::termsDays(null), null);
check('empty → null',                Rules::termsDays(''), null);
check('unknown label → null',        Rules::termsDays('NET_45'), null);
check('lowercase tolerated',         Rules::termsDays('net_30'), 30);
check('whitespace tolerated',        Rules::termsDays(' NET_15 '), 15);

section('invoice due date — from the snapshot, COD means due on receipt');
check('COD: due = issued',        Rules::invoiceDueAt('2026-07-28 14:30:00', 'DUE_ON_RECEIPT'), '2026-07-28 14:30:00');
check('NULL terms: due = issued', Rules::invoiceDueAt('2026-07-28 14:30:00', null), '2026-07-28 14:30:00');
check('Net 15: issue + 15 days',  Rules::invoiceDueAt('2026-07-28 14:30:00', 'NET_15'), '2026-08-12 14:30:00');
check('Net 30: issue + 30 days',  Rules::invoiceDueAt('2026-07-28 14:30:00', 'NET_30'), '2026-08-27 14:30:00');
check('Net 30 across month end',  Rules::invoiceDueAt('2026-01-31 09:00:00', 'NET_30'), '2026-03-02 09:00:00');
check('Net 15 at 23:59 keeps the clock time', Rules::invoiceDueAt('2026-07-28 23:59:59', 'NET_15'), '2026-08-12 23:59:59');
check('Net 30 across a year end', Rules::invoiceDueAt('2026-12-15 12:00:00', 'NET_30'), '2027-01-14 12:00:00');

section('customer gate — a person or a business entity, never a blur');
check('person with a name: ok',             Rules::customerGate('INDIVIDUAL', '', 'Rachel', 'Nguyen')['ok'], true);
check('person, first name only: ok',        Rules::customerGate('INDIVIDUAL', '', 'Rachel', '')['ok'], true);
check('person without a name: fails',       Rules::customerGate('INDIVIDUAL', '')['ok'], false);
check('person, whitespace name: fails',     Rules::customerGate('INDIVIDUAL', '', '  ', ' ')['ok'], false);
check('commercial, no company: fails',      Rules::customerGate('COMMERCIAL', '')['ok'], false);
check('commercial, blank company: fails',   Rules::customerGate('COMMERCIAL', '   ')['ok'], false);
check('commercial with company: ok',        Rules::customerGate('COMMERCIAL', 'Cascade Motor Club')['ok'], true);
check('business needs no contact person',   Rules::customerGate('COMMERCIAL', 'Cascade Motor Club', '', '')['ok'], true);
check('fleet, no company: fails',           Rules::customerGate('FLEET', '')['ok'], false);
check('fleet with company: ok',             Rules::customerGate('FLEET', 'Rose City PM')['ok'], true);
check('lowercase type tolerated',           Rules::customerGate('fleet', '')['ok'], false);

section('hard separation — only a business carries a company name');
check('isBusinessType COMMERCIAL', Rules::isBusinessType('COMMERCIAL'), true);
check('isBusinessType FLEET',      Rules::isBusinessType('FLEET'), true);
check('isBusinessType INDIVIDUAL', Rules::isBusinessType('INDIVIDUAL'), false);
check('business keeps its company',       Rules::accountCompany('FLEET', ' Rose City PM '), 'Rose City PM');
check('person: company always cleared',   Rules::accountCompany('INDIVIDUAL', 'Side Hustle LLC'), '');

section('display name — one rule everywhere');
$retail   = ['customer_type' => 'INDIVIDUAL', 'first_name' => 'Rachel', 'last_name' => 'Nguyen', 'company' => ''];
$retailCo = ['customer_type' => 'INDIVIDUAL', 'first_name' => 'Tom', 'last_name' => 'Bradley', 'company' => 'Tom\'s Hauling'];
$biz      = ['customer_type' => 'COMMERCIAL', 'first_name' => 'Dispatch', 'last_name' => 'Desk', 'company' => 'Cascade Motor Club'];
$fleet    = ['customer_type' => 'FLEET', 'first_name' => '', 'last_name' => '', 'company' => 'Rose City PM'];
$joined   = ['first_name' => 'Ellis', 'last_name' => 'Vance', 'company' => ''];   // JOIN row, no customer_type

check('is_business: individual', customer_is_business($retail), false);
check('is_business: commercial', customer_is_business($biz), true);
check('is_business: fleet',      customer_is_business($fleet), true);

check('retail shows the person',                    customer_name($retail), 'Rachel Nguyen');
check('retail with a company still shows the person', customer_name($retailCo), 'Tom Bradley');
check('business (customer-facing) shows the company', customer_name($biz), 'Cascade Motor Club');
check('business (internal) shows Company (Contact)',  customer_name($biz, true), 'Cascade Motor Club (Dispatch Desk)');
check('business with no contact: company only',       customer_name($fleet, true), 'Rose City PM');
check('JOIN row without type falls back company-first', customer_name($joined), 'Ellis Vance');

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
