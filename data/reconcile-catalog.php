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
 * Bring an already-installed price book back into line with data/seed.php.
 *
 *   php data/reconcile-catalog.php                    say what would change, change nothing
 *   php data/reconcile-catalog.php --apply            make the changes
 *   php data/reconcile-catalog.php --apply --prices   also restore seeded prices
 *   php data/reconcile-catalog.php --apply --zero-prices --services-only
 *                                                     structure only, nothing priced
 *
 * --zero-prices     new items land at 0.00 with pricing_model ESTIMATE, so the
 *                   category, account and VIN rules are right and NO price is
 *                   invented. Nothing can be billed until a human sets a
 *                   number. Affects inserts only — an item that already exists
 *                   is never re-zeroed, because that would wipe real pricing.
 * --services-only   only item_type SERVICE. Fees and parts are left for the
 *                   operator; a $0 call-out fee is worse than no call-out fee.
 *
 * WHY THIS EXISTS. seed_catalog() only runs at install. When the service
 * categories were restructured — battery work to Mobile Repair, winch-out to
 * Towing, the tire jobs split — the seed learned the new shape and every
 * database already installed kept the old one. Those installs can dispatch a
 * Battery Replacement and have no battery item in that category to bill it.
 *
 * WHERE THE FACTS COME FROM. catalog_seed_rows(), the same function
 * seed_catalog() writes from. This script holds NO catalog data of its own.
 * The first version did, as a hand-written list of what had moved where, and
 * it was wrong immediately: it moved the battery labour and forgot the battery
 * parts. A second copy of the price book is a second price book.
 *
 * WHAT IT WILL NOT DO. It is not a wipe, it has nothing to do with
 * data/wipe.php or the wipe policy, and it drops, truncates and deletes
 * nothing. It only ever touches SKUs that seed_catalog() ships. An item the
 * operator created is theirs and is never read, judged or altered.
 *
 * PRICES ARE HELD BACK BY DEFAULT, behind --prices. Everything else here is a
 * structural fact the application reasons about — which category dispatches
 * it, which account it posts to, whether it needs a VIN — and a stale one is a
 * bug. A price is a commercial decision. An operator who repriced a jump start
 * meant to, and having a maintenance script quietly reset it to the example
 * number is how a shop invoices at the wrong rate without noticing.
 *
 * Safe to run twice; the second run reports nothing to do.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/data/seed.php';
App::boot($cfg);
Db::boot($cfg['db']);

$args     = array_slice($argv, 1);
$apply    = in_array('--apply', $args, true);
$prices   = in_array('--prices', $args, true);
$zero     = in_array('--zero-prices', $args, true);
$svcOnly  = in_array('--services-only', $args, true);

if ($prices && $zero) {
    fwrite(STDERR, "--prices and --zero-prices contradict each other. Pick one.\n");
    exit(1);
}

/* Columns this script is willing to correct. unit_price and unit_cost join the
 * list only when --prices is given; description, is_active and sort_order never
 * do — those are the operator's, on a seeded item as much as on their own. */
$fields = ['item_type', 'category', 'service_category', 'name', 'pricing_model',
           'uom', 'taxable', 'vehicle_not_required', 'warranty_months',
           'revenue_account', 'cogs_account', 'is_misc'];
if ($prices) { $fields[] = 'unit_price'; $fields[] = 'unit_cost'; }

$where = Db::driver() === 'mysql'
    ? sprintf('%s@%s/%s', $cfg['db']['username'], $cfg['db']['host'], $cfg['db']['database'])
    : (string) $cfg['db']['path'];
fwrite(STDOUT, "Target: " . Db::driver() . " $where\n");
fwrite(STDOUT, 'Mode:   ' . ($apply ? 'APPLY' : 'dry run — nothing will be written') . "\n");
fwrite(STDOUT, 'Prices: ' . ($prices ? 'restored from the seed'
    : ($zero ? 'NEW items land at 0.00 / ESTIMATE — nothing priced'
             : 'left alone (pass --prices to restore)')) . "\n");
fwrite(STDOUT, 'Scope:  ' . ($svcOnly ? 'services only — fees and parts skipped' : 'the whole seeded price book') . "\n\n");

/* Values differ across drivers and PDO settings — MySQL hands back '1' where
 * the seed holds 1, and DECIMAL(12,2) comes back '85.00' for 85.0. Comparing
 * those as strings reports a change on every run forever. */
$same = static function (string $field, mixed $live, mixed $want): bool {
    if (in_array($field, ['unit_price', 'unit_cost'], true)) {
        return bccomp(number_format((float) $live, 2, '.', ''),
                      number_format((float) $want, 2, '.', ''), 2) === 0;
    }
    if (in_array($field, ['taxable', 'vehicle_not_required', 'warranty_months', 'is_misc'], true)) {
        return (int) $live === (int) $want;
    }
    return (string) $live === (string) $want;
};

$updated = 0; $inserted = 0; $edits = 0;
$sort    = (int) Db::val('SELECT COALESCE(MAX(sort_order), 0) FROM catalog_items');

fwrite(STDOUT, "Seeded items that have drifted\n");
foreach (catalog_seed_rows() as $want) {
    $sku  = (string) $want['sku'];
    if ($svcOnly && $want['item_type'] !== 'SERVICE') { continue; }
    $live = Db::one('SELECT * FROM catalog_items WHERE sku = ?', [$sku]);

    if (!$live) {
        /* Structure from the seed, price from nobody. ESTIMATE rather than
         * FLAT 0.00 on purpose: a FLAT zero is a real price that happens to be
         * nothing, and it would quietly add a $0 line to an invoice. ESTIMATE
         * makes the document say the work needs quoting, which is the truth
         * until somebody sets a rate. */
        if ($zero) {
            $want['unit_price']    = 0;
            $want['unit_cost']     = 0;
            $want['pricing_model'] = 'ESTIMATE';
        }
        $inserted++;
        fwrite(STDOUT, sprintf("  + %-18s %-9s %-9s %s\n", $sku,
            (string) $want['service_category'], $want['pricing_model'], $want['name']));
        if ($apply) {
            $id = Db::insert('catalog_items', ['sort_order' => ++$sort] + $want);
            Audit::log('catalog_item', $id, 'created', $sku . ' — added by catalog reconcile'
                . ($zero ? ' (unpriced: needs a rate before it can be billed)' : ''));
        }
        continue;
    }

    /* An item that already exists still gets its STRUCTURE corrected — the
     * category it dispatches under, the account it posts to. What it never
     * gets is a price change: unit_price and unit_cost are only in $fields
     * when --prices was asked for. --zero-prices governs what a NEW row starts
     * life as; it is not licence to erase pricing somebody entered. */

    /* An UNPRICED PLACEHOLDER is one this script created with --zero-prices and
     * nobody has priced since: 0.00 and still ESTIMATE. Its pricing_model is
     * not drift, it is the placeholder doing its job, and "correcting" it back
     * to the seed's FLAT would hand it a real price of nothing — a $0 line
     * that adds to an invoice silently. Leave the model alone until a rate
     * exists; then it is an ordinary item and the seed's model applies again.
     * Without this the script is not idempotent: every run flips all 19 back. */
    $placeholder = (string) ($live['pricing_model'] ?? '') === 'ESTIMATE'
        && (float) ($live['unit_price'] ?? 0) == 0.0;

    $diff = [];
    foreach ($fields as $f) {
        if ($f === 'pricing_model' && $placeholder && !$prices) { continue; }
        if (!$same($f, $live[$f] ?? null, $want[$f])) { $diff[$f] = $want[$f]; }
    }
    if ($diff === []) { continue; }

    $updated++; $edits += count($diff);
    fwrite(STDOUT, "  ~ $sku\n");
    foreach ($diff as $f => $v) {
        fwrite(STDOUT, sprintf("      %-20s %s → %s\n", $f,
            var_export($live[$f] ?? null, true), var_export($v, true)));
    }
    if ($apply) {
        Db::update('catalog_items', (int) $live['id'], $diff);
        Audit::log('catalog_item', (int) $live['id'], 'updated',
            'catalog reconcile: ' . implode(', ', array_keys($diff)));
    }
}

/* Items the operator added are reported and left completely alone. Saying how
 * many there are is the difference between "nothing else changed" and "nothing
 * else was looked at". */
$seededSkus = array_column(catalog_seed_rows(), 'sku');
$theirs     = (int) Db::val('SELECT COUNT(*) FROM catalog_items WHERE sku NOT IN ('
    . implode(',', array_fill(0, count($seededSkus), '?')) . ')', $seededSkus);

/* The check that actually matters: can every job intake offers be invoiced?
 * A category with no active item is a call you can take and cannot bill. */
fwrite(STDOUT, "\nCoverage — every offered service needs a billable item in its category\n");
$gaps = 0;
foreach (array_keys(ServiceCategory::ALL) as $cat) {
    $offers = count(ServiceCategory::serviceTypes($cat));
    $n = (int) Db::val("SELECT COUNT(*) FROM catalog_items
                        WHERE item_type = 'SERVICE' AND service_category = ? AND is_active = 1", [$cat]);
    if ($offers > 0 && $n === 0) { $gaps++; }
    fwrite(STDOUT, sprintf("  %-9s %d service(s) offered, %d item(s)%s\n",
        $cat, $offers, $n, ($offers > 0 && $n === 0) ? '   *** NOTHING TO BILL ***' : ''));
}

fwrite(STDOUT, "\nOperator's own items, untouched: $theirs\n");

if ($updated === 0 && $inserted === 0) {
    fwrite(STDOUT, "\nNothing to do — this catalog already matches the seed.\n");
} else {
    fwrite(STDOUT, sprintf("\n%d item(s) corrected (%d field(s)), %d added.%s\n",
        $updated, $edits, $inserted,
        $apply ? '' : ' Re-run with --apply to make them.'));
}

if ($gaps > 0) {
    fwrite(STDERR, "\nWARNING: $gaps categor(y/ies) can be dispatched but not billed.\n"
        . "Add an item under /catalog for each, or those calls cannot be invoiced.\n");
    exit(1);
}
