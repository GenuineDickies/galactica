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
 * Unit tests for descriptor normalising and expense rule matching. Pure.
 *   php tests/expense_rules.php
 *
 * The descriptors below are real shapes, not invented ones: processor prefixes,
 * truncation, store numbers, trailing city/state, authorisation numbers. The
 * normaliser is only worth having if it survives those, and the failures that
 * matter are the quiet ones — a rule that matches too much, or a merchant name
 * eaten by a rule meant for a reference number.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/Domain.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s\n        want %s\n         got %s\n", $label, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/** The seed, in the shape match() expects. */
function seeded(): array {
    $out = [];
    $i = 0;
    foreach (ExpenseRules::SEED as $row) {
        [$pattern, $account, $vendor, $priority] = $row;
        $out[] = ['id' => ++$i, 'pattern' => $pattern, 'is_regex' => (int) ($row[4] ?? 0),
                  'account_code' => $account, 'vendor_name' => $vendor,
                  'priority' => $priority, 'is_active' => 1,
                  'classification' => (string) ($row[5] ?? 'BUSINESS')];
    }
    usort($out, static fn($a, $b) => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
    return $out;
}
function fileTo(string $descriptor): ?string {
    $r = ExpenseRules::match(Descriptor::normalize($descriptor), seeded());
    return $r['account_code'] ?? null;
}

section('processor prefixes come off');
check('SQ * is stripped',
    Descriptor::normalize('SQ *NAPA AUTO PARTS'), 'NAPA AUTO PARTS');
check('TST* is stripped',
    Descriptor::normalize('TST* CORNER CAFE'), 'CORNER CAFE');
check('PAYPAL * is stripped',
    Descriptor::normalize('PAYPAL *ROCKAUTO'), 'ROCKAUTO');
check('only one prefix is taken, not a chain',
    Descriptor::normalize('SQ *SQ *ODD'), 'SQ ODD');

section('bank bookkeeping words come off');
check('POS DEBIT',
    Descriptor::normalize('POS DEBIT NAPA AUTO PARTS'), 'NAPA AUTO PARTS');
check('CHECKCARD',
    Descriptor::normalize('CHECKCARD HARBOR FREIGHT'), 'HARBOR FREIGHT');
check('a noise word INSIDE the name is left alone',
    Descriptor::normalize('WITHDRAWAL SERVICES INC'), 'SERVICES INC');

section('store numbers, dates, locations and references');
check('store number',
    Descriptor::normalize('MOTEL FIVE EXPRSS #34'), 'MOTEL FIVE EXPRSS');
check('trailing date',
    Descriptor::normalize('NAPA AUTO PARTS 08/14'), 'NAPA AUTO PARTS');
check('trailing date with year',
    Descriptor::normalize('NAPA AUTO PARTS 08/14/25'), 'NAPA AUTO PARTS');
check('city and state',
    Descriptor::normalize('HARBOR FREIGHT MEDFORD OR'), 'HARBOR FREIGHT');
check('long authorisation number',
    Descriptor::normalize('LES SCHWAB 000123456789'), 'LES SCHWAB');
check('the whole mess at once',
    Descriptor::normalize('SQ *NAPA AUTO PARTS #4471 MEDFORD OR 08/14'), 'NAPA AUTO PARTS');

section('what the normaliser must NOT eat');
/* Each of these is a way to lose a merchant by being too clever. */
check('a short number is part of the name',
    Descriptor::normalize('76 STATION'), '76 STATION');
check('a name starting with a digit survives',
    Descriptor::normalize('4 WHEEL PARTS'), '4 WHEEL PARTS');
check('a two-letter word that is not a state survives',
    Descriptor::normalize('TOOLS AND MORE'), 'TOOLS AND MORE');
check('an apostrophe survives',
    Descriptor::normalize("O'REILLY AUTO PARTS"), "O'REILLY AUTO PARTS");
check('an ampersand survives',
    Descriptor::normalize('AT&T MOBILITY'), 'AT&T MOBILITY');
check('a descriptor that is ONLY a reference is kept, not emptied',
    Descriptor::normalize('000123456789'), '000123456789');

section('empty and thin input');
check('empty in, empty out',            Descriptor::normalize(''), '');
check('whitespace in, empty out',       Descriptor::normalize('   '), '');
check('a two-character key is too thin', Descriptor::tooThin('AB'), true);
check('three characters is enough',      Descriptor::tooThin('ABC'), false);
check('spaces do not count toward length', Descriptor::tooThin('A B'), true);

section('matching files real descriptors to real accounts');
check('NAPA is job parts',              fileTo('SQ *NAPA AUTO PARTS #4471'), '5000');
check("O'Reilly, however it is spelled", fileTo('OREILLY AUTO 00012345'), '5000');
check('Les Schwab is job parts',        fileTo('LES SCHWAB TIRE CTR MEDFORD OR'), '5000');
check('Harbor Freight is tools, not parts', fileTo('HARBOR FREIGHT TOOLS 442'), '6600');
check('Verizon is communications',      fileTo('VERIZON WIRELESS 08/01'), '6130');
check('Progressive is vehicle insurance', fileTo('PROGRESSIVE INSURANCE'), '6250');
check('Telnyx is SMS, not general software', fileTo('TELNYX LLC'), '6150');
check('an unknown merchant gets NO suggestion', fileTo('BOBS MYSTERY EMPORIUM'), null);

section('specific beats general — the priority test');
/* Both rules match this descriptor. Fuel must win, or every tank of diesel
 * files itself as shop supplies. */
check('COSTCO GAS is fuel, not supplies', fileTo('COSTCO GAS #0417 MEDFORD OR'), '5030');
check('plain COSTCO is still supplies',   fileTo('COSTCO WHSE #0417'), '6400');
check('FRED MEYER FUEL is fuel',          fileTo('FRED MEYER FUEL CTR'), '5030');

section('a thin key never matches');
check('two characters match nothing',   ExpenseRules::match('AB', seeded()), null);
check('an empty key matches nothing',   ExpenseRules::match('', seeded()), null);

section('inactive rules are skipped');
$off = [['id' => 1, 'pattern' => 'NAPA', 'is_regex' => 0, 'account_code' => '5000',
         'priority' => 1, 'is_active' => 0]];
check('an inactive rule does not fire', ExpenseRules::match('NAPA AUTO', $off), null);

section('validation refuses what would break an import');
$chart = array_column(Accounts::DEFAULTS, 0);
check('a good rule passes',
    ExpenseRules::validate(['pattern' => 'NAPA', 'account_code' => '5000'], $chart), []);
check('no pattern is refused',
    count(ExpenseRules::validate(['pattern' => '', 'account_code' => '5000'], $chart)) > 0, true);
check('a two-character pattern is refused',
    count(ExpenseRules::validate(['pattern' => 'AB', 'account_code' => '5000'], $chart)) > 0, true);
check('an account not in the chart is refused',
    count(ExpenseRules::validate(['pattern' => 'NAPA', 'account_code' => '9999'], $chart)) > 0, true);
check('and the error names the account',
    str_contains(ExpenseRules::validate(['pattern' => 'NAPA', 'account_code' => '9999'], $chart)[0], '9999'), true);
check('a broken regex is refused before it can throw mid-import',
    count(ExpenseRules::validate(
        ['pattern' => 'NAPA(', 'is_regex' => 1, 'account_code' => '5000'], $chart)) > 0, true);
check('a valid regex passes',
    ExpenseRules::validate(
        ['pattern' => 'NAPA|CARQUEST', 'is_regex' => 1, 'account_code' => '5000'], $chart), []);
check('an unknown classification is refused',
    count(ExpenseRules::validate(
        ['pattern' => 'NAPA', 'account_code' => '5000', 'classification' => 'MAYBE'], $chart)) > 0, true);

section('regex rules work when asked for');
$rx = [['id' => 1, 'pattern' => 'NAPA|CARQUEST|GENUINE PARTS', 'is_regex' => 1,
        'account_code' => '5000', 'priority' => 1, 'is_active' => 1]];
check('a regex rule matches its alternatives',
    ExpenseRules::match('CARQUEST AUTO', $rx)['account_code'] ?? null, '5000');
check('and does not match something else',
    ExpenseRules::match('HARBOR FREIGHT', $rx), null);

section('a prepaid card load is a transfer, not a purchase');
/* Loading a prepaid card moves money between two places the business owns. It
 * has bought nothing yet, and treating it as an expense would claim a deduction
 * with no substantiation behind it. */
function ruleFor(string $descriptor): ?array {
    return ExpenseRules::match(Descriptor::normalize($descriptor), seeded());
}
check('a card load goes to the prepaid asset account', fileTo('VISA 4282'), '1030');
check('any four-digit card, not just the ones seen',   fileTo('VISA 9911'), '1030');
check('and it is classified as a TRANSFER',
    ruleFor('VISA 2173')['classification'] ?? null, 'TRANSFER');
check('1030 is an asset in the chart',
    (static function () {
        foreach (Accounts::DEFAULTS as [$n, $name, $type]) {
            if ($n === '1030') { return $type; }
        }
        return null;
    })(), 'ASSET');
check('a real merchant is still an expense, not a transfer',
    ruleFor('AUTOZONE')['classification'] ?? null, 'BUSINESS');

section('a short rule cannot swallow the business name');
/* "Ads" is how Square renders Google Ads on some rows. A substring rule for it
 * would also match ROADSIDE — this business's own name — and quietly file every
 * such line to advertising. The seeded rule is an anchored regex for exactly
 * this reason, and these two assertions are what keep it that way. */
check('a bare ADS rule files Google Ads',      fileTo('Ads'), '6110');
check('and does NOT match ROADSIDE',           fileTo('WHITE KNIGHT ROADSIDE'), null);
check('nor anything else containing ADS',      fileTo('BROADSIDE MARINE'), null);

section('the seed itself is sane');
$chartNumbers = array_column(Accounts::DEFAULTS, 0);
$bad = 0; $short = 0; $unanchored = 0;
foreach (ExpenseRules::SEED as $row) {
    [$pattern, $account, $vendor, $priority] = $row;
    $isRegex = (int) ($row[4] ?? 0);
    if (!in_array($account, $chartNumbers, true)) { $bad++; }
    if (strlen($pattern) < 3) { $short++; }
    /* Anything four characters or shorter is substring-dangerous and must be
     * anchored. This is the check that would have caught 'ADS' before it
     * shipped rather than after. */
    if (!$isRegex && strlen($pattern) <= 4 && !str_contains($pattern, ' ')) { $unanchored++; }
}
check('no short unanchored pattern can swallow a longer word', $unanchored, 0);
check('every seeded rule points at a real account', $bad, 0);
check('no seeded pattern is dangerously short',     $short, 0);
check('the seed is not empty',                      count(ExpenseRules::SEED) > 50, true);

printf("\n\033[1m%d passed, %d failed\033[0m\n\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
