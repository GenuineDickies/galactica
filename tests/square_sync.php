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
 * Unit tests for the Square mirror's payload reading. Pure — no network, no
 * database, no Square account. Fixtures are the shapes Square's API documents.
 *
 *   php tests/square_sync.php
 *
 * The money assertions are the point. Square reports in minor units as
 * integers; anything that multiplies those by a float loses cents, and cents
 * lost at the API boundary are never recovered because there is nothing left
 * to compare against.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $l, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/** A completed card payment with a tip and a processing fee. */
$payment = [
    'id'            => 'sqpay_ABC123',
    'created_at'    => '2026-08-14T17:04:22Z',
    'amount_money'  => ['amount' => 36700, 'currency' => 'USD'],
    'tip_money'     => ['amount' => 1000,  'currency' => 'USD'],
    'total_money'   => ['amount' => 37700, 'currency' => 'USD'],
    'processing_fee' => [
        ['effective_at' => '2026-08-14T19:00:00Z', 'type' => 'INITIAL',
         'amount_money' => ['amount' => 1123, 'currency' => 'USD']],
    ],
    'status'       => 'COMPLETED',
    'source_type'  => 'CARD',
    'location_id'  => 'LOC_WKR',
    'order_id'     => 'ord_9988',
    'reference_id' => 'INV-20260814-001',
    'note'         => 'Roadside service',
    'receipt_url'  => 'https://squareup.com/receipt/preview/sqpay_ABC123',
    'card_details' => [
        'entry_method' => 'KEYED',
        'card' => ['card_brand' => 'VISA', 'last_4' => '4242'],
    ],
];

section('a sandbox import is refused before it can touch the real history');
/* square_transactions holds six years of real charges and has no column
 * recording which environment a row came from. A sandbox row and a real one
 * are indistinguishable once written, and square_sync_state's cursors are
 * keyed only by object_type — so one accidental sandbox run corrupts the only
 * copy of a record that cannot be rebuilt from anywhere else. */
$prod    = new SquareSync('tok', 'production');
$sandbox = new SquareSync('tok', 'sandbox');
$unset   = new SquareSync('',    'production');

check('production may import',      $prod->refusalReason(), '');
check('sandbox is refused',         str_contains($sandbox->refusalReason(), 'Refusing to import'), true);
check('the refusal names sandbox',  str_contains($sandbox->refusalReason(), 'sandbox'), true);
check('no token is refused too',    str_contains($unset->refusalReason(), 'not configured'), true);

/* The refusal must stop the import, not merely be reported alongside one. */
foreach (['importAll', 'importCustomers', 'importPayoutEntries'] as $entry) {
    $r = $sandbox->$entry();
    check($entry . ' imports nothing in sandbox', $r['imported'], 0);
    check($entry . ' explains why',               count($r['errors']) > 0, true);
}
/* Reported once, not once per object type — importAll fans out to three. */
check('importAll refuses once, not three times', count($sandbox->importAll()['errors']), 1);

section('money comes across in integer cents, never through a float');
check('gross',        SquareSync::money($payment['amount_money']), '367.00');
check('tip',          SquareSync::money($payment['tip_money']), '10.00');
check('a missing money object is zero', SquareSync::money(null), '0.00');
check('fee total',    SquareSync::feeTotal($payment), '11.23');
/* 367.00 + 10.00 tip - 11.23 fee = 365.77 reaching the bank. */
check('net to the bank', SquareSync::netTotal($payment), '365.77');

section('several withheld fees add up');
$twoFees = $payment;
$twoFees['processing_fee'][] = ['amount_money' => ['amount' => 77, 'currency' => 'USD']];
check('fees sum',     SquareSync::feeTotal($twoFees), '12.00');
check('net drops by the extra fee', SquareSync::netTotal($twoFees), '365.00');

section('no fee reported yet — a payment Square has not settled');
$unsettled = $payment;
unset($unsettled['processing_fee']);
check('fee is zero',  SquareSync::feeTotal($unsettled), '0.00');
check('net is gross plus tip', SquareSync::netTotal($unsettled), '377.00');

section('a payment flattens to the mirror columns');
$row = SquareSync::shape('PAYMENT', $payment);
check('square id',        $row['square_id'], 'sqpay_ABC123');
check('object type',      $row['object_type'], 'PAYMENT');
check('location',         $row['location_id'], 'LOC_WKR');
check('order id',         $row['order_id'], 'ord_9988');
check('status upper-cased', $row['status'], 'COMPLETED');
check('card brand',       $row['card_brand'], 'VISA');
check('last four',        $row['card_last4'], '4242');
check('entry method',     $row['entry_method'], 'KEYED');
check('reference kept',   $row['reference_id'], 'INV-20260814-001');
/* Stored in the application's timezone, matching every other timestamp in
 * the database. Storing Square's UTC put a seven-hour skew beside its own
 * neighbours and moved evening jobs onto the following day. */
check('occurred at is local, not UTC',
    $row['occurred_at'],
    (new DateTimeImmutable('2026-08-14T17:04:22Z'))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'));
check('raw payload kept', str_contains((string) $row['raw'], 'sqpay_ABC123'), true);

section('an object with no id is skipped rather than imported blank');
check('no id -> null',    SquareSync::shape('PAYMENT', ['amount_money' => ['amount' => 100]]), null);
check('empty id -> null', SquareSync::shape('PAYMENT', ['id' => '']), null);

section('a refund flattens too');
$refund = [
    'id'           => 'sqref_XYZ',
    'created_at'   => '2026-08-15T09:00:00Z',
    'amount_money' => ['amount' => 2200, 'currency' => 'USD'],
    'status'       => 'completed',
    'location_id'  => 'LOC_WKR',
    'order_id'     => 'ord_9988',
];
$rrow = SquareSync::shape('REFUND', $refund);
check('refund id',        $rrow['square_id'], 'sqref_XYZ');
check('refund type',      $rrow['object_type'], 'REFUND');
check('refund amount',    $rrow['amount'], '22.00');
check('lower-case status is normalised', $rrow['status'], 'COMPLETED');

section('a payout uses its own date field');
$payout = [
    'id'          => 'sqpo_555',
    'payout_date' => '2026-08-16',
    'amount_money'=> ['amount' => 121500, 'currency' => 'USD'],
    'status'      => 'PAID',
    'location_id' => 'LOC_WKR',
];
$prow = SquareSync::shape('PAYOUT', $payout);
check('payout amount',    $prow['amount'], '1215.00');
check('payout date used when created_at is absent',
    substr((string) $prow['occurred_at'], 0, 10), '2026-08-16');

section('long free text is truncated to its column, not silently dropped');
$long = $payment;
$long['note'] = str_repeat('x', 400);
$long['reference_id'] = str_repeat('r', 200);
check('note fits 255',       strlen((string) SquareSync::shape('PAYMENT', $long)['note']), 255);
check('reference fits 120',  strlen((string) SquareSync::shape('PAYMENT', $long)['reference_id']), 120);

section('nothing here decides business or personal');
/* The mirror imports; a human classifies. shape() must not emit a
 * classification at all — if it ever did, an automated import could overwrite
 * a judgement only the owner is entitled to make, and personal spending would
 * find its way into business income. */
check('shape sets no classification', array_key_exists('classification', $row), false);
check('shape sets no ledger entry',   array_key_exists('posted_entry_id', $row), false);

section('the circumstantial detail that identifies a charge');
/* Six years of charges with no documents behind them are told apart by which
 * device rang them up, which Square app, what the customer saw on their
 * statement, and the receipt number — not by the amount. The first mapping
 * kept eight fields out of sixty and left all of this in the raw blob. */
$detailed = SquareSync::shape('PAYMENT', $payment + [
    'receipt_number'      => 'Ab12',
    'application_details' => ['square_product' => 'VIRTUAL_TERMINAL'],
    'device_details'      => ['device_name' => "Jason's iPhone"],
    'team_member_id'      => 'TM_9',
]);
check('receipt number',      $detailed['receipt_number'], 'Ab12');
check('which Square app',    $detailed['square_product'], 'VIRTUAL_TERMINAL');
check('which device',        $detailed['device_name'], "Jason's iPhone");
check('who took it',         $detailed['team_member_id'], 'TM_9');
check('receipt url kept',    $row['receipt_url'], 'https://squareup.com/receipt/preview/sqpay_ABC123');

$carded = $payment;
$carded['card_details']['statement_description'] = 'SQ *WHITE KNIGHT';
$carded['card_details']['avs_status'] = 'AVS_ACCEPTED';
$carded['card_details']['cvv_status'] = 'CVV_ACCEPTED';
$carded['card_details']['card'] += ['fingerprint' => 'fp_abc', 'card_type' => 'CREDIT',
                                    'bin' => '424242', 'exp_month' => 8, 'exp_year' => 2027];
$c2 = SquareSync::shape('PAYMENT', $carded);
check('statement description', $c2['statement_desc'], 'SQ *WHITE KNIGHT');
/* The fingerprint is the quiet one: Square hashes the physical card, so it
 * groups a repeat customer across six years even where no name was captured. */
check('card fingerprint',    $c2['card_fingerprint'], 'fp_abc');
check('card type',           $c2['card_type'], 'CREDIT');
check('issuer prefix',       $c2['card_bin'], '424242');
check('expiry is assembled', $c2['card_exp'], '08/2027');
check('address check',       $c2['avs_status'], 'AVS_ACCEPTED');
check('no expiry -> empty',  SquareSync::cardExp([]), '');
check('half an expiry -> empty', SquareSync::cardExp(['exp_month' => 8]), '');

/* 990 declines in this account and not one could say why until now. */
$declined = $payment;
$declined['status'] = 'FAILED';
$declined['card_details']['errors'] = [['code' => 'GENERIC_DECLINE', 'detail' => 'Card declined by issuer.']];
$d2 = SquareSync::shape('PAYMENT', $declined);
check('why it was refused',  $d2['decline_code'], 'GENERIC_DECLINE');
check('and in plain words',  $d2['decline_detail'], 'Card declined by issuer.');
check('a good payment has no decline reason', $row['decline_code'], '');

section('identity is read from the fields that actually exist');
/* A census over six years of this account's payloads found: customer_id on
 * 40%, cardholder_name on 6%, billing_address on 1%, shipping_address on
 * 0.2%. An earlier version read shipping only and captured almost nothing. */
check('customer id captured',   $row['square_customer_id'], '');
$withCust = SquareSync::shape('PAYMENT', $payment + ['customer_id' => 'CUST_77']);
check('customer id when present', $withCust['square_customer_id'], 'CUST_77');
check('cardholder name captured', $row['cardholder_name'], '');

$named = $payment;
$named['card_details']['card']['cardholder_name'] = 'DALE GRIBBLE';
check('cardholder name wins',   SquareSync::nameFrom($named), 'DALE GRIBBLE');

$billed = $payment;
$billed['billing_address'] = ['first_name' => 'Peggy', 'last_name' => 'Hill'];
check('billing name is next',   SquareSync::nameFrom($billed), 'Peggy Hill');

$emailed = $payment;
$emailed['buyer_email_address'] = 'someone@example.com';
check('email is the last resort', SquareSync::nameFrom($emailed), 'someone@example.com');
check('no name at all is empty', SquareSync::nameFrom(['id' => 'x']), '');

section('phone numbers — a wrong one texts a stranger');
check('ten digits get +1',      SquareSync::e164('5035551234'), '+15035551234');
check('formatting is ignored',  SquareSync::e164('(503) 555-1234'), '+15035551234');
check('leading 1 is the same number', SquareSync::e164('1-503-555-1234'), '+15035551234');
check('already E.164',          SquareSync::e164('+15035551234'), '+15035551234');
/* Anything that is not recognisably a number is left EMPTY rather than
 * guessed at. This application sends texts; a fabricated number reaches
 * somebody who never called. */
check('too short is refused',   SquareSync::e164('5551234'), '');
check('junk is refused',        SquareSync::e164('n/a'), '');
check('empty is refused',       SquareSync::e164(''), '');

section('a callback from another location is not ours');
/* Square delivers callbacks for the whole merchant account. This account also
 * carries the owner's personal activity, so "did this happen at OUR location"
 * has to be answered before any business logic runs — the previous behaviour
 * only avoided acting on foreign payments because their order id failed to
 * match a row in payment_links, which is an accident rather than a boundary. */
/* The expected location is passed in rather than written to settings because
 * App::setting() caches for the life of the process — a test that wrote the
 * row could only ever exercise one location per run. */
check('our location passes',
    SquarePaymentGateway::isOurLocation(['location_id' => 'LOC_WKR'], 'LOC_WKR'), true);
check('another location is refused',
    SquarePaymentGateway::isOurLocation(['location_id' => 'LOC_SOMEONE_ELSE'], 'LOC_WKR'), false);
/* Unknown is treated as NOT ours. The cost of refusing a callback we cannot
 * place is a log line; the cost of accepting one is a stranger's money landing
 * on a customer's invoice. */
check('a callback with no location is refused',
    SquarePaymentGateway::isOurLocation(['location_id' => ''], 'LOC_WKR'), false);
check('a callback missing the key entirely is refused',
    SquarePaymentGateway::isOurLocation([], 'LOC_WKR'), false);
/* A near miss must not pass. A comparison that was loose about length or case
 * would let a sibling location through. */
check('a location that merely starts the same is refused',
    SquarePaymentGateway::isOurLocation(['location_id' => 'LOC_WKR2'], 'LOC_WKR'), false);
check('case must match',
    SquarePaymentGateway::isOurLocation(['location_id' => 'loc_wkr'], 'LOC_WKR'), false);

/* With nothing configured the check cannot be made, so it passes — a
 * half-configured install must not silently drop its own payments. */
check('unconfigured passes rather than dropping everything',
    SquarePaymentGateway::isOurLocation(['location_id' => 'ANYTHING'], ''), true);

section('the parsed callback carries the location');
$paymentEvent = json_encode(['type' => 'payment.updated', 'data' => ['object' => ['payment' => [
    'id' => 'sqpay_1', 'order_id' => 'ord_1', 'location_id' => 'LOC_WKR', 'status' => 'COMPLETED',
    'amount_money' => ['amount' => 8500, 'currency' => 'USD'],
]]]]);
$gw  = new SquarePaymentGateway('tok', 'LOC_WKR', 'sandbox', 'sig');
$out = $gw->parseWebhook($paymentEvent);
check('location came through',   $out['location_id'], 'LOC_WKR');
check('kind is payment',         $out['kind'], 'payment');
/* Cast because PHP's / returns int when the division is exact: 8500/100 is
 * int(85) while 8501/100 is float(85.01). Everything downstream takes either. */
check('amount in dollars',       (float) $out['amount'], 85.0);

section('checkpoints must come back out as RFC 3339');
/* Square requires "2026-08-16T18:12:42Z". The checkpoint lives in a DATETIME
 * column and MySQL stores it as "2026-08-16 18:12:42" — space, no zone.
 * Handing that straight back to the API failed EVERY incremental run with
 * "begin_time must be in RFC 3339 format". Only --all worked, because it
 * passes a hand-written literal, which is exactly why it stayed hidden. */
check('already RFC 3339 is untouched',
    SquareSync::rfc3339('2026-08-16T18:12:42Z'), '2026-08-16T18:12:42Z');
/* A naive value came from THIS application's own columns, which hold local
 * time, so it is converted to UTC rather than relabelled. Asserted against
 * the ambient zone so the test is honest wherever it runs. */
$localToUtc = static fn(string $v): string =>
    gmdate('Y-m-d\\TH:i:s\\Z', strtotime($v));
check('a naive local value converts to UTC',
    SquareSync::rfc3339('2026-08-16 18:12:42'), $localToUtc('2026-08-16 18:12:42'));
check('a bare date converts too',
    SquareSync::rfc3339('2026-08-16'), $localToUtc('2026-08-16'));
check('an offset is preserved',
    SquareSync::rfc3339('2026-08-16T18:12:42+00:00'), '2026-08-16T18:12:42+00:00');
check('fractional seconds are preserved',
    SquareSync::rfc3339('2026-08-16T18:12:42.123Z'), '2026-08-16T18:12:42.123Z');
check('empty stays empty',        SquareSync::rfc3339(''), '');
check('whitespace stays empty',   SquareSync::rfc3339('   '), '');
check('unparseable stays empty',  SquareSync::rfc3339('not a date'), '');

section('a Square customer flattens to the directory columns');
$cust = [
    'id'            => 'CUST_77',
    'given_name'    => 'Hank',
    'family_name'   => 'Hill',
    'company_name'  => 'Strickland Propane',
    'phone_number'  => '(503) 555-9090',
    'email_address' => 'hank@example.com',
    'created_at'    => '2021-06-02T14:00:00Z',
    'address'       => [
        'address_line_1' => '84 Rainey St',
        'locality'       => 'Portland',
        'administrative_district_level_1' => 'OR',
        'postal_code'    => '97210',
    ],
];
$crow = SquareSync::shapeCustomer($cust);
check('square customer id',     $crow['square_customer_id'], 'CUST_77');
check('given name',             $crow['given_name'], 'Hank');
check('family name',            $crow['family_name'], 'Hill');
check('company',                $crow['company_name'], 'Strickland Propane');
check('phone normalised',       $crow['phone_e164'], '+15035559090');
check('raw phone kept as typed',$crow['phone_number'], '(503) 555-9090');
check('city',                   $crow['city'], 'Portland');
check('state',                  $crow['state'], 'OR');
check('a customer with no id is skipped', SquareSync::shapeCustomer(['given_name' => 'Nobody']), null);

section('importing a customer decides nothing about the customer base');
check('no promotion link set',  array_key_exists('promoted_customer_id', $crow), false);
check('no job totals invented', array_key_exists('payment_count', $crow), false);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
