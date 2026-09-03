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
 * Unit tests for ServiceCategory. Pure — no database, no server.
 *   php tests/service_category.php
 *
 * The rule under test is the demount test: a job is Mobile Tire when the tire
 * has to come off the WHEEL, and Roadside when it does not, regardless of how
 * stranded the customer is and regardless of whether the wheel came off the
 * vehicle — a plug is commonly done with the wheel off the car and the tire
 * still on it. Everything else here exists to keep the dispatch default from
 * quietly becoming the dispatch decision.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Domain.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

section('the set is closed');
check('five categories', count(ServiceCategory::ALL), 5);
check('ROADSIDE valid', ServiceCategory::isValid('ROADSIDE'), true);
check('TIRE valid',     ServiceCategory::isValid('TIRE'), true);
check('MECHANIC valid', ServiceCategory::isValid('MECHANIC'), true);
check('TOWING valid',   ServiceCategory::isValid('TOWING'), true);
check('OTHER valid',    ServiceCategory::isValid('OTHER'), true);
check('junk rejected',  ServiceCategory::isValid('SUBLET'), false);
check('null rejected',  ServiceCategory::isValid(null), false);
check('empty rejected', ServiceCategory::isValid(''), false);
check('lowercase rejected', ServiceCategory::isValid('roadside'), false);

section('every service type has a default, and it is a real category');
foreach (array_keys(ServiceCategory::FROM_SERVICE_TYPE) as $type) {
    check("default for $type is valid",
        ServiceCategory::isValid(ServiceCategory::fromServiceType($type)), true);
}
check('unknown type falls to OTHER', ServiceCategory::fromServiceType('TELEPORT'), 'OTHER');
check('null type falls to OTHER',    ServiceCategory::fromServiceType(null), 'OTHER');

section('the defaults themselves');
check('jump start is roadside',   ServiceCategory::fromServiceType('JUMPSTART'), 'ROADSIDE');
check('lockout is roadside',      ServiceCategory::fromServiceType('LOCKOUT'), 'ROADSIDE');
check('fuel is roadside',         ServiceCategory::fromServiceType('FUEL'), 'ROADSIDE');
// The demount test, both sides of it.
check('spare swap is roadside',   ServiceCategory::fromServiceType('TIRE_SWAP'), 'ROADSIDE');
check('plug is roadside',         ServiceCategory::fromServiceType('TIRE_PLUG'), 'ROADSIDE');
check('internal patch is tire',   ServiceCategory::fromServiceType('TIRE_PATCH'), 'TIRE');
check('tire delivery is tire',    ServiceCategory::fromServiceType('TIRE_DELIVERY'), 'TIRE');
// A part comes off and a part goes on. A battery swap is a light repair,
// not a roadside errand — it is a part sale with labour attached.
check('parts install is repair',  ServiceCategory::fromServiceType('PARTS_INSTALL'), 'MECHANIC');
check('battery swap is repair',   ServiceCategory::fromServiceType('BATTERY_SWAP'), 'MECHANIC');
check('diagnostic is repair',     ServiceCategory::fromServiceType('DIAGNOSTIC'), 'MECHANIC');
// The vehicle moves. A winch-out is recovery, which is the tow trade's work.
check('winch out is towing',      ServiceCategory::fromServiceType('WINCH_OUT'), 'TOWING');
check('flatbed tow is towing',    ServiceCategory::fromServiceType('FLATBED_TOW'), 'TOWING');
check('standard tow is towing',   ServiceCategory::fromServiceType('STANDARD_TOW'), 'TOWING');

section('the category gates the type, not the other way round');
check('roadside offers five',  count(ServiceCategory::serviceTypes('ROADSIDE')), 5);
check('tire offers two',       count(ServiceCategory::serviceTypes('TIRE')), 2);
check('repair offers three',   count(ServiceCategory::serviceTypes('MECHANIC')), 3);
check('towing offers three',   count(ServiceCategory::serviceTypes('TOWING')), 3);
check('other offers one',      count(ServiceCategory::serviceTypes('OTHER')), 1);
// An unknown category must offer NOTHING rather than everything: a junk value
// widening the menu is how a mismatched pair gets in.
check('junk category offers nothing', ServiceCategory::serviceTypes('SUBLET'), []);
check('null category offers nothing', ServiceCategory::serviceTypes(null), []);
check('every offered type belongs to the category it was offered for', (function () {
    foreach (array_keys(ServiceCategory::ALL) as $cat) {
        foreach (ServiceCategory::serviceTypes($cat) as $t) {
            if (ServiceCategory::FROM_SERVICE_TYPE[$t] !== $cat) { return $cat . '/' . $t; }
        }
    }
    return true;
})(), true);
check('every type is offered by exactly one category', (function () {
    $seen = [];
    foreach (array_keys(ServiceCategory::ALL) as $cat) {
        foreach (ServiceCategory::serviceTypes($cat) as $t) { $seen[$t] = ($seen[$t] ?? 0) + 1; }
    }
    return array_keys(array_filter($seen, fn ($n) => $n !== 1));
})(), []);
check('the offer list is every live type', (function () {
    $offered = [];
    foreach (array_keys(ServiceCategory::ALL) as $cat) {
        $offered = array_merge($offered, ServiceCategory::serviceTypes($cat));
    }
    sort($offered);
    $live = array_diff(array_keys(ServiceCategory::FROM_SERVICE_TYPE), ServiceCategory::RETIRED);
    sort($live);
    return $offered === $live;
})(), true);

section('retired types are accepted but never offered');
// The split renamed the job. A row written before it keeps what it was written
// with — nobody went back and asked which of the new jobs it actually was.
foreach (ServiceCategory::RETIRED as $t) {
    check("$t still classifies", ServiceCategory::isValid(ServiceCategory::fromServiceType($t)), true);
    check("$t is not offered",
        in_array($t, ServiceCategory::serviceTypes(ServiceCategory::fromServiceType($t)), true), false);
    check("$t is still accepted",
        ServiceCategory::allows(ServiceCategory::fromServiceType($t), $t), true);
}
check('pre-split tire stays roadside',  ServiceCategory::fromServiceType('TIRE'), 'ROADSIDE');
check('pre-split battery is repair now', ServiceCategory::fromServiceType('BATTERY'), 'MECHANIC');
check('pre-split recovery is towing now', ServiceCategory::fromServiceType('RECOVERY'), 'TOWING');
check('nothing is ambiguous any more', ServiceCategory::AMBIGUOUS, []);
check('needsDispatchDecision is dead', ServiceCategory::needsDispatchDecision('TIRE'), false);

section('allows: the accept list is the offer list plus the retired types');
check('roadside allows a plug',        ServiceCategory::allows('ROADSIDE', 'TIRE_PLUG'), true);
check('roadside refuses a patch',      ServiceCategory::allows('ROADSIDE', 'TIRE_PATCH'), false);
check('towing refuses a jump start',   ServiceCategory::allows('TOWING', 'JUMPSTART'), false);
check('repair allows a battery swap',  ServiceCategory::allows('MECHANIC', 'BATTERY_SWAP'), true);
check('junk category allows nothing',  ServiceCategory::allows('SUBLET', 'JUMPSTART'), false);
check('null category allows nothing',  ServiceCategory::allows(null, 'JUMPSTART'), false);
check('unknown type is not allowed',   ServiceCategory::allows('ROADSIDE', 'TELEPORT'), false);

section('coerceServiceType: the category wins, and never invents a foreign type');
check('an eligible type passes through',
    ServiceCategory::coerceServiceType('TIRE', 'TIRE_PATCH'), 'TIRE_PATCH');
check('a foreign type is replaced from the category',
    ServiceCategory::coerceServiceType('TIRE', 'JUMPSTART'), 'TIRE_PATCH');
check('junk is replaced from the category',
    ServiceCategory::coerceServiceType('TOWING', 'NONSENSE'), 'WINCH_OUT');
check('a retired type survives its own category',
    ServiceCategory::coerceServiceType('ROADSIDE', 'TIRE'), 'TIRE');
check('the replacement always belongs to the category', (function () {
    foreach (array_keys(ServiceCategory::ALL) as $cat) {
        $got = ServiceCategory::coerceServiceType($cat, 'NONSENSE');
        if (!ServiceCategory::allows($cat, $got)) { return $cat . '=>' . $got; }
    }
    return true;
})(), true);

section('coerce never invents a value and never returns junk');
check('valid passes through',    ServiceCategory::coerce('MECHANIC', 'TIRE_PATCH'), 'MECHANIC');
check('junk falls to the type default', ServiceCategory::coerce('NONSENSE', 'DIAGNOSTIC'), 'MECHANIC');
check('empty falls to the type default', ServiceCategory::coerce('', 'FUEL'), 'ROADSIDE');
check('null falls to the type default',  ServiceCategory::coerce(null, 'JUMPSTART'), 'ROADSIDE');
check('junk with no type falls to OTHER', ServiceCategory::coerce('NONSENSE', null), 'OTHER');
// The override has to survive: a dispatcher who says TIRE on a tire call must
// not have it reset to the ROADSIDE default on the next save.
check('override survives the default', ServiceCategory::coerce('TIRE', 'TIRE'), 'TIRE');

section('labels');
check('roadside label', ServiceCategory::label('ROADSIDE'), 'Roadside Services');
check('tire label',     ServiceCategory::label('TIRE'), 'Advanced Tire Services');
check('repair label',   ServiceCategory::label('MECHANIC'), 'Mobile Repair Services');
check('towing label',   ServiceCategory::label('TOWING'), 'Towing Services');
check('junk label',     ServiceCategory::label('NONSENSE'), '—');
check('null label',     ServiceCategory::label(null), '—');

section('work-order transitions never reopen terminal records');
check('pending can be assigned', Rules::workOrderTransitions('PENDING'), ['ASSIGNED', 'CANCELLED', 'NO_SHOW']);
check('on-site can begin work', Rules::workOrderTransitions('ON_SITE'), ['IN_PROGRESS', 'CANCELLED', 'NO_SHOW']);
check('in-progress cannot return to an earlier state', Rules::workOrderTransitions('IN_PROGRESS'), ['CANCELLED', 'NO_SHOW']);
check('completed has no transitions', Rules::workOrderTransitions('COMPLETED'), []);
check('cancelled has no transitions', Rules::workOrderTransitions('CANCELLED'), []);

section('reclassification is dispatch vs field, and only when both are known');
$sr = fn(?string $c) => ['service_category' => $c];
$wo = fn(?string $c) => ['service_category' => $c];
check('same category is not a reclassification',
    ServiceCategory::reclassification($sr('ROADSIDE'), $wo('ROADSIDE')), null);
check('uncategorised work order is not a reclassification',
    ServiceCategory::reclassification($sr('ROADSIDE'), $wo(null)), null);
check('uncategorised request is not a reclassification',
    ServiceCategory::reclassification($sr(null), $wo('TIRE')), null);
check('junk is not a reclassification',
    ServiceCategory::reclassification($sr('ROADSIDE'), $wo('NONSENSE')), null);
// The case this whole column exists for: "flat tire" rolled roadside, the
// puncture was in the shoulder, the tire had to come off the wheel.
check('roadside -> tire is reported',
    ServiceCategory::reclassification($sr('ROADSIDE'), $wo('TIRE')),
    ['from' => 'ROADSIDE', 'to' => 'TIRE']);
// The other one that happens in the field: "my truck is stuck" is taken as a
// winch-out and turns out to need a deck.
check('roadside -> towing is reported',
    ServiceCategory::reclassification($sr('ROADSIDE'), $wo('TOWING')),
    ['from' => 'ROADSIDE', 'to' => 'TOWING']);
check('missing keys entirely are safe',
    ServiceCategory::reclassification([], []), null);

section('the seeded catalog agrees with the demount test');
$seed = file_get_contents(dirname(__DIR__) . '/data/seed.php');
$expect = [
    'SVC-TIRE-CHANGE'  => 'ROADSIDE',  // spare bolts on, tire stays on its rim
    'SVC-TIRE-PLUG'    => 'ROADSIDE',  // wheel often off the vehicle, tire never off the rim
    'SVC-TIRE-PATCH'   => 'TIRE',      // bead broken, tire off the rim
    'SVC-TIRE-DELIVERY'=> 'TIRE',      // bead broken, tire off the rim
    'SVC-MOUNT-BAL'    => 'TIRE',      // bead broken, tire off the rim
    'SVC-JUMP-STD'     => 'ROADSIDE',
    'SVC-BATT-INSTALL' => 'MECHANIC',  // a part comes off and a part goes on
    'SVC-BATT-TEST'    => 'MECHANIC',
    'SVC-MECH-HOURLY'  => 'MECHANIC',
    'SVC-DIAG-STD'     => 'MECHANIC',
    'SVC-WINCH-STD'    => 'TOWING',    // recovery is the tow trade's work
    'SVC-TOW-FLATBED'  => 'TOWING',
    'SVC-TOW-STANDARD' => 'TOWING',
];
foreach ($expect as $sku => $want) {
    $line = '';
    foreach (explode("\n", $seed) as $l) {
        if (str_contains($l, "'" . $sku . "'")) { $line = $l; break; }
    }
    check("$sku is $want", $line !== '' && str_contains($line, "'" . $want . "'"), true);
}

section('no catalog item points at an account that does not exist');
$known = array_column(Accounts::DEFAULTS, 0);
preg_match_all("/'(\d{4})'\s*,\s*'(\d{4})?'\]/", $seed, $m, PREG_SET_ORDER);
$orphans = [];
foreach ($m as $hit) {
    foreach ([$hit[1], $hit[2] ?? ''] as $code) {
        if ($code !== '' && !in_array($code, $known, true)) { $orphans[] = $code; }
    }
}
check('no orphan account codes in the seeded catalog', array_values(array_unique($orphans)), []);

printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
