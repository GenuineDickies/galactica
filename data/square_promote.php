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
 * Promote the Square customer directory into the real customer base.
 *
 *   php data/square_promote.php --analyze            what is in there, change nothing
 *   php data/square_promote.php --dupes              show the duplicate clusters
 *   php data/square_promote.php --promote --dry-run  what would be created
 *   php data/square_promote.php --promote
 *
 * THE PHONE NUMBER IS THE IDENTITY. Not the name.
 *
 * A roadside customer is identified by the number that rings in. Names in a
 * six-year directory are typed by whoever was holding the phone at 2am — the
 * same person appears as "Bob", "Robert S", "bob smith" and blank. Matching on
 * name would split one customer four ways and, worse, would merge two
 * different people who share a common one. The number is the thing that
 * actually identifies a caller, so it is what the merge is built on.
 *
 * Rows with no usable phone number are NOT promoted. customers.phone_e164 is
 * NOT NULL and a customer record that cannot be dialled or texted is of no use
 * to a dispatcher; those stay in the mirror where they can still be read.
 *
 * Nothing here is destructive. Promotion INSERTs into customers and records
 * the link on the mirror row. An already-promoted row is skipped, so this can
 * be run repeatedly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

/* Errors to the terminal. A CLI tool that dies silently part way through a
 * run is indistinguishable from one the host killed — which cost real time
 * on the Square sync before the cause was found. */
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$args = array_slice($argv, 1);
$has  = static fn(string $f): bool => in_array($f, $args, true);

/** Best display name in a cluster: the longest that has both parts, else the longest. */
function bestName(array $rows): array
{
    $best = ['given' => '', 'family' => '', 'company' => ''];
    $score = -1;
    foreach ($rows as $r) {
        $g = trim((string) $r['given_name']);
        $f = trim((string) $r['family_name']);
        $c = trim((string) $r['company_name']);
        $s = ($g !== '' ? 2 : 0) + ($f !== '' ? 2 : 0) + strlen($g . $f) / 100;
        if ($s > $score) { $score = $s; $best = ['given' => $g, 'family' => $f, 'company' => $c]; }
    }
    return $best;
}

/* ---- clusters: one per usable phone number --------------------------- */
function clusters(): array
{
    $rows = Db::all(
        "SELECT * FROM square_customers WHERE phone_e164 <> '' ORDER BY phone_e164, id"
    );
    $out = [];
    foreach ($rows as $r) { $out[(string) $r['phone_e164']][] = $r; }
    return $out;
}

/* ---- --analyze -------------------------------------------------------- */
if ($has('--analyze') || $args === []) {
    $total     = (int) Db::val('SELECT COUNT(*) FROM square_customers', [], 0);
    $noPhone   = (int) Db::val("SELECT COUNT(*) FROM square_customers WHERE phone_e164 = ''", [], 0);
    $promoted  = (int) Db::val('SELECT COUNT(*) FROM square_customers WHERE promoted_customer_id IS NOT NULL', [], 0);
    $noName    = (int) Db::val("SELECT COUNT(*) FROM square_customers WHERE given_name = '' AND family_name = '' AND company_name = ''", [], 0);
    $withJobs  = (int) Db::val('SELECT COUNT(*) FROM square_customers WHERE payment_count > 0', [], 0);
    $repeat    = (int) Db::val('SELECT COUNT(*) FROM square_customers WHERE payment_count > 1', [], 0);
    $existing  = (int) Db::val('SELECT COUNT(*) FROM customers', [], 0);

    $cl = clusters();
    $dupes = array_filter($cl, static fn($g) => count($g) > 1);

    printf("Directory rows                       %6d\n", $total);
    printf("  already promoted                   %6d\n", $promoted);
    printf("  no usable phone (cannot promote)   %6d\n", $noPhone);
    printf("  no name at all                     %6d\n", $noName);
    printf("  with at least one completed job    %6d\n", $withJobs);
    printf("  repeat customers                   %6d\n", $repeat);
    printf("\nDistinct phone numbers               %6d\n", count($cl));
    printf("  numbers appearing more than once   %6d\n", count($dupes));
    printf("  rows collapsed by merging          %6d\n", $total - $noPhone - count($cl));
    printf("\nCustomers already in the app         %6d\n", $existing);
    printf("\nPromoting would create about %d customers.\n", count($cl));
    echo "\nNothing was changed. Use --dupes to inspect, --promote --dry-run to preview.\n";
    exit(0);
}

/* ---- --dupes ---------------------------------------------------------- */
if ($has('--dupes')) {
    $shown = 0;
    foreach (clusters() as $phone => $rows) {
        if (count($rows) < 2) { continue; }
        $b = bestName($rows);
        printf("\n%s  (%d entries -> \"%s\")\n", $phone, count($rows),
            trim($b['given'] . ' ' . $b['family']) ?: ($b['company'] ?: '(no name)'));
        foreach ($rows as $r) {
            printf("    %-28s %-22s %2d jobs  %10s  %s\n",
                trim(((string) $r['given_name']) . ' ' . ((string) $r['family_name'])) ?: '(no name)',
                substr((string) $r['email_address'], 0, 22),
                (int) $r['payment_count'], money($r['payment_total']),
                substr((string) $r['square_customer_id'], 0, 12));
        }
        if (++$shown >= 40) { echo "\n… more not shown.\n"; break; }
    }
    if ($shown === 0) { echo "No duplicate phone numbers.\n"; }
    exit(0);
}

/* ---- --promote -------------------------------------------------------- */
if ($has('--promote')) {
    $dry = $has('--dry-run');
    echo $dry ? "DRY RUN — nothing will be written.\n\n" : "Promoting.\n\n";

    $created = 0; $linked = 0; $merged = 0; $skipped = 0;

    foreach (clusters() as $phone => $rows) {
        /* Already promoted? Link the rest of the cluster to the same customer
         * rather than making a second one. */
        $existingId = null;
        foreach ($rows as $r) {
            if ($r['promoted_customer_id'] !== null) { $existingId = (int) $r['promoted_customer_id']; break; }
        }

        /* A customer with this number may already exist from ordinary use of
         * the app. The phone is the identity, so that record wins — importing
         * must never create a second customer for a number already on file. */
        if ($existingId === null) {
            $live = Db::val('SELECT id FROM customers WHERE phone_e164 = ?', [$phone]);
            if ($live !== null) { $existingId = (int) $live; $merged++; }
        }

        $b     = bestName($rows);
        $jobs  = array_sum(array_map(static fn($r) => (int) $r['payment_count'], $rows));
        $spend = array_sum(array_map(static fn($r) => Markup::toCents($r['payment_total']), $rows));
        /* min() on an EMPTY array is a ValueError in PHP 8, not a warning
         * returning false. A cluster where nobody has a recorded first job —
         * a directory entry that never bought anything — killed the whole run
         * silently at that point. Guarded rather than assumed non-empty. */
        $dates = array_filter(array_map(static fn($r) => (string) $r['first_seen_job'], $rows));
        $first = $dates === [] ? null : min($dates);
        $email = '';
        foreach ($rows as $r) { if (trim((string) $r['email_address']) !== '') { $email = (string) $r['email_address']; break; } }

        if ($existingId === null) {
            if ($dry) {
                $created++;
                if ($created <= 15) {
                    printf("  create  %-26s %-16s %2d jobs %10s\n",
                        trim($b['given'] . ' ' . $b['family']) ?: ($b['company'] ?: '(no name)'),
                        $phone, $jobs, money(Markup::centsToStr($spend)));
                }
                continue;
            }

            $note = sprintf(
                "Imported from Square. %d completed job%s totalling %s%s.",
                $jobs, $jobs === 1 ? '' : 's', money(Markup::centsToStr($spend)),
                $first ? ', first on ' . substr((string) $first, 0, 10) : ''
            );

            /* EVERY name seen on this number is kept, not just the chosen one.
             *
             * Merging on the phone number is right — the number is what rings,
             * and it is how a caller is recognised. But a number is not always
             * one person. The real directory holds "Michael tracy" and "Mike
             * tracy" (one person, twice) alongside "ernestina orozco" and
             * "Salvador orozco" (two people, one household) and a moving
             * company's line used by two different staff. Choosing a single
             * display name and dropping the others would erase somebody who
             * genuinely called.
             *
             * So the record carries the best name, and the note carries all of
             * them. Nothing is lost, and a dispatcher seeing the second name
             * on an inbound call knows immediately that it is the same number. */
            $names = [];
            foreach ($rows as $r) {
                $n = trim(((string) $r['given_name']) . ' ' . ((string) $r['family_name']));
                if ($n !== '' && !in_array($n, $names, true)) { $names[] = $n; }
            }
            if (count($names) > 1) {
                $note .= ' Also seen as: ' . implode(', ', array_slice($names, 1)) . '.';
            }
            if (count($rows) > 1) {
                $note .= ' ' . count($rows) . ' Square entries merged on this number.';
            }

            $existingId = Db::insert('customers', [
                /* INDIVIDUAL unless a company name is all we have. A fleet
                 * account is one where the BUSINESS owns the vehicles, which
                 * a Square receipt cannot tell us — so this never guesses
                 * COMMERCIAL, and mis-set accounts are corrected by hand. */
                'customer_type' => 'INDIVIDUAL',
                'first_name'    => $b['given'] ?: null,
                'last_name'     => $b['family'] ?: null,
                'company'       => $b['company'] ?: null,
                'phone_e164'    => $phone,
                'email'         => $email ?: null,
                /* sms_approved stays 0. Consent is not transferable: paying by
                 * card in 2021 is not permission to be texted in 2026, and
                 * 10DLC compliance turns on that distinction. */
                'sms_approved'  => 0,
                'notes'         => $note,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            Audit::log('customer', $existingId, 'imported:square', $phone);
            $created++;
        }

        foreach ($rows as $r) {
            if ($r['promoted_customer_id'] !== null) { $skipped++; continue; }
            if (!$dry) {
                Db::update('square_customers', (int) $r['id'], [
                    'promoted_customer_id' => $existingId,
                    'promoted_at'          => now(),
                ]);
            }
            $linked++;
        }
    }

    printf("\n%s%d customers %s · %d directory rows linked · %d matched an existing customer · %d already done\n",
        $dry ? 'Would create ' : '', $created, $dry ? '' : 'created', $linked, $merged, $skipped);

    if ($dry) { echo "\nNothing was written. Re-run without --dry-run to apply.\n"; }
    else {
        echo "\nSMS consent was NOT imported — every record is opt-out until the\n";
        echo "customer agrees again. Paying by card years ago is not consent to be texted.\n";
    }
    exit(0);
}

fwrite(STDERR, "Unknown option. Try --analyze, --dupes, or --promote --dry-run.\n");
exit(1);
