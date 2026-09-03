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
 * Integration tests for Rules::duplicateCustomer against a real database —
 * the one rule that runs before any customer record is created (SR promotion
 * and /customers/new both call it).
 *
 *   WKR_DB_PASS=… php tests/duplicates.php
 *
 * Creates throwaway customers with unmistakable names/numbers and deletes
 * exactly those rows at the end.
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

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $l, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

$tag   = bin2hex(random_bytes(3));
$pA    = '+1999' . random_int(1000000, 9999999);   // unmistakably fake numbers
$pA2   = '+1998' . random_int(1000000, 9999999);
$pB    = '+1997' . random_int(1000000, 9999999);
$pNone = '+1996' . random_int(1000000, 9999999);

$idA = Db::insert('customers', ['customer_type' => 'INDIVIDUAL', 'first_name' => 'Testdup' . $tag,
    'last_name' => 'Person', 'company' => '', 'phone_e164' => $pA, 'phone2_e164' => $pA2,
    'created_at' => now(), 'updated_at' => now()]);
$idB = Db::insert('customers', ['customer_type' => 'FLEET', 'first_name' => '', 'last_name' => '',
    'company' => 'Testdup Towing ' . $tag, 'phone_e164' => $pB,
    'created_at' => now(), 'updated_at' => now()]);

section('individual — exact');
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'TESTDUP' . $tag, 'person', $pA);
check('same name+phone (case-insensitive) is exact', $d['level'], 'exact');
check('…and it is that record', (int) $d['match']['id'], $idA);
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'Testdup' . $tag, 'Person', $pA2);
check('second phone counts for exact too', $d['level'], 'exact');

section('individual — possible');
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'Completely', 'Different', $pA);
check('known phone under another name is possible', $d['level'], 'possible');
check('…pointing at the phone holder', (int) $d['match']['id'], $idA);
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'Testdup' . $tag, 'Person', $pNone);
check('known name under another phone is possible', $d['level'], 'possible');
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'Testdup' . $tag, 'Person', null);
check('known name with no phone at all is possible', $d['level'], 'possible');

section('individual — clean');
$d = Rules::duplicateCustomer('INDIVIDUAL', '', 'Nobody' . $tag, 'Here', $pNone);
check('unknown name + unknown phone is clean', $d['level'], null);
check('…with no match row', $d['match'], null);

section('business — exact / possible');
$d = Rules::duplicateCustomer('FLEET', 'testdup towing ' . $tag, '', '', $pB);
check('same company+phone (case-insensitive) is exact', $d['level'], 'exact');
check('…and it is that record', (int) $d['match']['id'], $idB);
$d = Rules::duplicateCustomer('COMMERCIAL', 'Testdup Towing ' . $tag, '', '', $pNone);
check('known company under another phone is possible', $d['level'], 'possible');
$d = Rules::duplicateCustomer('FLEET', 'Some Other Co ' . $tag, '', '', $pB);
check('known phone under another company is possible', $d['level'], 'possible');
check('…pointing at the phone holder', (int) $d['match']['id'], $idB);

section('blank identities never match blank rows');
$d = Rules::duplicateCustomer('FLEET', '', '', '', $pNone);
check('blank company matches nothing', $d['level'], null);

// Exactly the rows this test created, nothing else.
Db::q('DELETE FROM customers WHERE id IN (?, ?)', [$idA, $idB]);

printf("\n%d passed, %d failed\n", $PASS, $FAIL);
exit($FAIL ? 1 : 0);
