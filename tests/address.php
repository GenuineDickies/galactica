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
 * Unit tests for Address. Pure — no database, no server.
 *   php tests/address.php
 *
 * The rule under test is the line between an ADDRESS and a DESCRIPTION. A
 * stranded caller describes where they are; a document needs somewhere to
 * print and a truck needs somewhere to drive. Those are different things, and
 * conflating them is how "blue sedan on the shoulder" ends up on an estimate
 * the customer signs.
 *
 * The bias is deliberate: when this is unsure it should REJECT, because the
 * cost of rejecting is a dispatcher dropping a pin, and the cost of accepting
 * is a bad address on a contract.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Domain.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n",
        $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

section('real addresses are accepted');
foreach ([
    '842 SE Morrison St'                => 'city street',
    '1220 NW Everett St'                => 'directional prefix',
    '12345 Highway 26'                  => 'rural highway with a number',
    '4 NE Broadway'                     => 'single-digit number',
    '10250 SW Greenburg Rd Suite 111'   => 'suite on the line',
    '1200 NW Front Ave'                 => 'street literally named Front',
    '8600 NE Marine Dr'                 => 'street named Marine',
    '123A W Burnside St'                => 'lettered number',
    '2500 1/2 SE Powell Blvd'           => 'halves fraction',
] as $line => $why) {
    check($why, Address::check($line, 'Portland', 'OR')['ok'], true);
}

section('descriptions are refused');
foreach ([
    'I-84 EB near Exit 9'                     => 'highway with an exit',
    '5 miles north of Sandy'                  => 'number first, still a description',
    'Milepost 34 on Highway 26'               => 'milepost',
    '26 westbound past the weigh station'     => 'direction and landmark',
    'Blue sedan on the shoulder'              => 'no number at all',
    'In the parking lot at 300 SW 6th Ave'    => 'landmark wrapper',
    '300 SW 6th Ave, across from the library' => 'address plus a landmark tail',
    'Between exits 14 and 15'                 => 'between',
    'Rest area on I-5 southbound'             => 'rest area',
] as $line => $why) {
    check($why, Address::check($line, 'Portland', 'OR')['ok'], false);
}

section('a refusal always says why');
$r = Address::check('Blue sedan on the shoulder', 'Portland', 'OR');
check('reason is not empty', $r['reason'] !== '', true);
check('empty input is refused', Address::check('', 'Portland', 'OR')['ok'], false);
check('empty input names the fix',
    str_contains(Address::check('', 'Portland', 'OR')['reason'], 'pin'), true);

section('city and state are required, ZIP is not');
check('no city refused',    Address::check('842 SE Morrison St', '', 'OR')['ok'], false);
check('no state refused',   Address::check('842 SE Morrison St', 'Portland', '')['ok'], false);
check('no ZIP accepted',    Address::check('842 SE Morrison St', 'Portland', 'OR')['ok'], true);
check('ZIP kept when given',
    Address::check('842 SE Morrison St', 'Portland', 'OR', '97214')['postal'], '97214');
check('junk state refused', Address::check('842 SE Morrison St', 'Portland', 'ZZ')['ok'], false);
check('long state accepted','OR' === Address::check('842 SE Morrison St', 'Portland', 'Oregon')['state'], true);

section('city and state may ride inside the line');
$r = Address::check('1220 NW Everett St, Portland, OR 97209');
check('accepted',   $r['ok'],     true);
check('line split', $r['line'],   '1220 NW Everett St');
check('city split', $r['city'],   'Portland');
check('state split',$r['state'],  'OR');
check('zip split',  $r['postal'], '97209');

section('explicit fields beat what the line carried');
$r = Address::check('1220 NW Everett St, Portland, OR 97209', 'Beaverton', 'WA');
check('city wins',  $r['city'],  'Beaverton');
check('state wins', $r['state'], 'WA');

section('split is not fooled by short input');
check('two parts stay whole', Address::split('842 SE Morrison St, Portland')['line'],
    '842 SE Morrison St, Portland');
check('empty is empty', Address::split('')['line'], '');

section('oneLine renders the way it prints');
check('with zip',    Address::oneLine('842 SE Morrison St', 'Portland', 'OR', '97214'),
    '842 SE Morrison St, Portland, OR 97214');
check('without zip', Address::oneLine('842 SE Morrison St', 'Portland', 'OR'),
    '842 SE Morrison St, Portland, OR');
check('line only',   Address::oneLine('842 SE Morrison St', '', ''), '842 SE Morrison St');

section('looksPhysical is structure only');
check('shape ok without city/state', Address::looksPhysical('842 SE Morrison St'), true);
check('description rejected',        Address::looksPhysical('near the Exit 9 ramp'), false);
check('null rejected',               Address::looksPhysical(null), false);

printf("\n%d passed, %d failed\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
