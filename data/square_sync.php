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
 * Import Square history into the mirror table.
 *
 *   php data/square_sync.php --locations     list the account's Locations, import nothing
 *   php data/square_sync.php                 import anything new since the last run
 *   php data/square_sync.php --all           re-pull the entire history from the beginning
 *   php data/square_sync.php --since=2025-01-01
 *   php data/square_sync.php --status        what is in the mirror right now
 *
 * NOTHING THIS SCRIPT DOES TOUCHES THE LEDGER. It fills square_transactions
 * and stops. Every imported row lands UNREVIEWED, because the account being
 * mirrored carries business and personal activity together and only a human
 * can say which is which. Classifying is a separate, deliberate step.
 *
 * Re-running is safe. Rows are keyed on Square's own id, so a second pull of
 * the same object updates it rather than adding a duplicate — which is why
 * --all is a repair tool rather than a dangerous one.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
App::boot($cfg);
Db::boot($cfg['db']);

$args  = array_slice($argv, 1);
$has   = static fn(string $flag): bool => in_array($flag, $args, true);
$value = static function (string $prefix) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, $prefix)) { return substr($a, strlen($prefix)); }
    }
    return null;
};

$sync = SquareSync::fromSettings();

/* ---- --doctor: why is Square not connected? -------------------------- */
/* Reports whether each setting is PRESENT and how long it is. It never
 * prints a credential — the length is enough to tell "empty" from "pasted
 * with a stray space" from "looks about right", and printing the token
 * itself would put it in a terminal scrollback and a deploy log. */
if ($has('--doctor')) {
    $rows = [
        'driver_payments'      => false,
        'square_environment'   => false,
        'square_location_id'   => false,
        'square_access_token'  => true,
        'square_signature_key' => true,
        'app_base_url'         => false,
    ];
    printf("%-22s %s\n", 'SETTING', 'STATE');
    foreach ($rows as $key => $secret) {
        $v = trim((string) App::setting($key, ''));
        $state = $v === ''
            ? '(not set)'
            : ($secret ? sprintf('set — %d characters', strlen($v)) : $v);
        printf("%-22s %s\n", $key, $state);
    }

    $raw = Db::one('SELECT skey FROM settings WHERE skey = ?', ['square_access_token']);
    echo "\nRow in the settings table: " . ($raw ? 'yes' : 'NO — the save never wrote it') . "\n";
    echo 'Sync would run: ' . ($sync->isLive() ? 'yes' : 'no') . "\n";
    exit(0);
}

if (!$sync->isLive()) {
    fwrite(STDERR, "Square is not configured. Add the access token in Settings first.\n");
    fwrite(STDERR, "Run  php data/square_sync.php --doctor  to see which setting is missing.\n");
    exit(1);
}

/* ---- --status: read the mirror, call nothing ------------------------- */
if ($has('--status')) {
    $rows = Db::all(
        "SELECT object_type, classification, COUNT(*) n, COALESCE(SUM(amount),0) total
         FROM square_transactions GROUP BY object_type, classification
         ORDER BY object_type, classification"
    );
    if (!$rows) { echo "The mirror is empty. Run without --status to import.\n"; exit(0); }

    printf("%-10s %-12s %8s %14s\n", 'TYPE', 'CLASS', 'COUNT', 'TOTAL');
    foreach ($rows as $r) {
        printf("%-10s %-12s %8d %14s\n", $r['object_type'], $r['classification'], (int) $r['n'], money($r['total']));
    }

    /* A gross total that silently includes failed and cancelled attempts is
     * not revenue, it is traffic. Break it out by status so the difference is
     * visible before anybody quotes the headline figure at a tax return. */
    echo "\nPAYMENTS BY STATUS — only COMPLETED is money you kept\n";
    foreach (Db::all(
        "SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) gross,
                COALESCE(SUM(tip_amount),0) tips, COALESCE(SUM(fee_amount),0) fees,
                COALESCE(SUM(refunded_amount),0) refunded
         FROM square_transactions WHERE object_type = 'PAYMENT'
         GROUP BY status ORDER BY gross DESC") as $r) {
        printf("  %-12s %6d  gross %13s  tips %11s  fees %11s  refunded %11s\n",
            $r['status'] ?: '(none)', (int) $r['n'], money($r['gross']),
            money($r['tips']), money($r['fees']), money($r['refunded']));
    }

    echo "\nRANGE COVERED\n";
    foreach (Db::all(
        "SELECT object_type, MIN(occurred_at) oldest, MAX(occurred_at) newest, COUNT(*) n
         FROM square_transactions GROUP BY object_type ORDER BY object_type") as $r) {
        printf("  %-8s %6d rows   %s  ..  %s\n", $r['object_type'], (int) $r['n'],
            substr((string) $r['oldest'], 0, 10), substr((string) $r['newest'], 0, 10));
    }

    echo "\nBY YEAR — completed payments only\n";
    foreach (Db::all(
        "SELECT SUBSTRING(occurred_at,1,4) y, COUNT(*) n,
                COALESCE(SUM(amount),0) gross, COALESCE(SUM(fee_amount),0) fees
         FROM square_transactions
         WHERE object_type = 'PAYMENT' AND status = 'COMPLETED'
         GROUP BY SUBSTRING(occurred_at,1,4) ORDER BY y") as $r) {
        printf("  %-6s %6d jobs   gross %13s   fees %11s\n",
            $r['y'], (int) $r['n'], money($r['gross']), money($r['fees']));
    }

    $linked = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE invoice_id IS NOT NULL", [], 0);
    printf("\nMatched to an invoice in this app: %d of %d\n", $linked,
        (int) Db::val("SELECT COUNT(*) FROM square_transactions", [], 0));
    foreach (Db::all('SELECT * FROM square_sync_state ORDER BY object_type') as $s) {
        printf("\n%s — last run %s, through %s\n  %s\n",
            $s['object_type'], $s['last_run_at'] ?: 'never', $s['cursor_time'] ?: 'the beginning', $s['last_result'] ?: '');
    }
    exit(0);
}

/* ---- --fields: what did Square actually attach to these payments? ----
 * Reports which keys are PRESENT across the stored payloads and how often.
 * Key names and counts only — no values — so it can be read in a terminal
 * and pasted into a log without exposing a customer's details. */
if ($has('--fields')) {
    $rows = Db::all("SELECT raw FROM square_transactions WHERE object_type = 'PAYMENT' AND raw IS NOT NULL");
    if (!$rows) { echo "Nothing imported yet.\n"; exit(0); }

    $seen = [];
    $walk = static function (array $d, string $prefix) use (&$walk, &$seen): void {
        foreach ($d as $k => $v) {
            if (is_int($k)) { $k = '[]'; }
            $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            $seen[$path] = ($seen[$path] ?? 0) + 1;
            if (is_array($v) && $v !== []) { $walk($v, $path); }
        }
    };
    foreach ($rows as $r) {
        $d = json_decode((string) $r['raw'], true);
        if (is_array($d)) { $walk($d, ''); }
    }

    arsort($seen);
    $total = count($rows);
    $depth = (int) ($value('--depth=') ?? 2);
    printf("%d payment payloads examined.\n\n%-58s %8s %7s\n", $total, 'FIELD', 'PRESENT', 'OF ALL');
    foreach ($seen as $path => $n) {
        if (substr_count($path, '.') > $depth) { continue; }
        printf("%-58s %8d %6.1f%%\n", $path, $n, ($n / $total) * 100);
    }
    exit(0);
}

/* ---- --dump <id>: one payload, whole, as Square sent it ---------------
 * The census says which fields EXIST; this says what they contain. Both are
 * needed before deciding what deserves promoting out of the raw blob into a
 * column of its own. */
if ($value('--dump') !== null) {
    $id  = (int) $value('--dump');
    $row = Db::one('SELECT * FROM square_transactions WHERE id = ?', [$id]);
    if ($row === null) { fwrite(STDERR, "No such row.\n"); exit(1); }
    echo json_encode(json_decode((string) $row['raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

/* ---- --locations: what does this account actually contain? ----------- */
if ($has('--locations')) {
    $locs = $sync->locations();
    if (!$locs) { fwrite(STDERR, "No locations returned. Check the access token and environment.\n"); exit(1); }
    printf("%-24s %-28s %-10s %s\n", 'ID', 'NAME', 'STATUS', 'CURRENCY');
    foreach ($locs as $l) {
        printf("%-24s %-28s %-10s %s\n", $l['id'], substr($l['name'], 0, 28), $l['status'], $l['currency']);
    }
    echo "\nNothing was imported. This only lists what the account contains.\n";
    exit(0);
}

/* ---- --probe: one minimal request per endpoint, nothing written ------
 * Added while chasing a sync that died silently on the refunds endpoint with
 * no exception and no fatal — the signature of a killed process rather than a
 * thrown error. This does the smallest possible call to each endpoint and
 * reports status and timing, so "the API is refusing us" can be told apart
 * from "our loop is at fault". */
if ($has('--probe')) {
    $token = trim((string) App::setting('square_access_token', ''));
    $base  = App::setting('square_environment', 'sandbox') === 'production'
        ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $head  = ['Authorization' => 'Bearer ' . $token, 'Square-Version' => '2025-01-23'];

    printf("memory_limit=%s  max_execution_time=%s\n\n",
        ini_get('memory_limit'), ini_get('max_execution_time'));
    printf("%-46s %7s %8s  %s\n", 'ENDPOINT', 'STATUS', 'SECONDS', 'NOTE');

    foreach ([
        '/v2/locations'  => [],
        '/v2/payments'   => ['limit' => 1],
        '/v2/refunds'    => ['limit' => 1],
        '/v2/refunds (sorted)' => ['limit' => 1, 'sort_order' => 'ASC'],
        '/v2/refunds (from 2015)' => ['limit' => 1, 'begin_time' => '2015-01-01T00:00:00Z', 'sort_order' => 'ASC'],
        '/v2/payouts'    => ['limit' => 1],
        '/v2/customers'  => ['limit' => 1],
    ] as $label => $q) {
        $path = trim(explode('(', $label)[0]);
        $url  = $base . $path . ($q ? '?' . http_build_query($q) : '');
        $t    = microtime(true);
        $res  = Http::json('GET', $url, $head);
        $secs = round(microtime(true) - $t, 2);

        $note = '';
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $note = (string) ($res['body']['errors'][0]['code'] ?? '')
                  . ' ' . (string) ($res['body']['errors'][0]['detail'] ?? $res['error']);
        } else {
            $k = trim((string) array_key_first(array_diff_key($res['body'], ['cursor' => 1])) ?: '');
            $note = $k !== '' ? $k . ': ' . count($res['body'][$k] ?? []) . ' returned' : 'empty response';
        }
        printf("%-46s %7d %8.2f  %s\n", $label, $res['status'], $secs, trim($note));
        flush();
    }
    exit(0);
}

/* ---- --orders: what was actually SOLD --------------------------------
 * A payment says "$85 arrived". The ORDER behind it says what for — line
 * items, names, quantities. For six years of jobs with no estimates or
 * invoices behind them, that is the difference between a bank statement and
 * a work history. Read-only probe. */
if ($has('--orders')) {
    $token = trim((string) App::setting('square_access_token', ''));
    $loc   = trim((string) App::setting('square_location_id', ''));
    $base  = App::setting('square_environment', 'sandbox') === 'production'
        ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $head  = ['Authorization' => 'Bearer ' . $token, 'Square-Version' => '2025-01-23'];

    $res = Http::json('POST', $base . '/v2/orders/search', $head, [
        'location_ids' => [$loc],
        'limit'        => 20,
        'query'        => ['sort' => ['sort_field' => 'CREATED_AT', 'sort_order' => 'DESC']],
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        printf("HTTP %d — %s\n", $res['status'],
            (string) ($res['body']['errors'][0]['detail'] ?? $res['error']));
        exit(1);
    }

    $orders = $res['body']['orders'] ?? [];
    printf("%d orders returned.\n\n", count($orders));

    $withItems = 0;
    foreach (array_slice($orders, 0, 10) as $o) {
        $items = $o['line_items'] ?? [];
        if ($items) { $withItems++; }
        printf("%s  %-10s  %d line item%s\n",
            substr((string) ($o['created_at'] ?? ''), 0, 10),
            money(((int) ($o['total_money']['amount'] ?? 0)) / 100),
            count($items), count($items) === 1 ? '' : 's');
        foreach ($items as $li) {
            printf("     %-42s x%-4s %10s\n",
                substr((string) ($li['name'] ?? '(unnamed)'), 0, 42),
                (string) ($li['quantity'] ?? '1'),
                money(((int) ($li['total_money']['amount'] ?? 0)) / 100));
        }
    }
    printf("\n%d of the first 10 carried line items.\n", $withItems);
    exit(0);
}

/* ---- --entries: what actually made up a payout -----------------------
 * A payout row is only a header — "$412 landed on Tuesday". The ENTRIES
 * underneath it itemise every movement in the Square balance that made up
 * that figure: charges, refunds, fees, disputes, and any spending on a Square
 * Card. Spending is the half of the account never imported, and this is where
 * Square keeps it. Read-only probe. */
if ($has('--entries')) {
    $token = trim((string) App::setting('square_access_token', ''));
    $base  = App::setting('square_environment', 'sandbox') === 'production'
        ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $head  = ['Authorization' => 'Bearer ' . $token, 'Square-Version' => '2025-01-23'];

    /* A handful of recent payouts is enough to learn which entry types this
     * account actually produces. */
    $payouts = Db::all(
        "SELECT square_id, occurred_at, amount FROM square_transactions
         WHERE object_type = 'PAYOUT' ORDER BY occurred_at DESC LIMIT ?",
        [(int) ($value('--limit=') ?? 8)]
    );

    $types = [];
    foreach ($payouts as $p) {
        $res = Http::json('GET', $base . '/v2/payouts/' . rawurlencode((string) $p['square_id'])
            . '/payout-entries?limit=100', $head);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            printf("%s  HTTP %d  %s\n", substr((string) $p['occurred_at'], 0, 10), $res['status'],
                (string) ($res['body']['errors'][0]['detail'] ?? $res['error']));
            continue;
        }
        $entries = $res['body']['payout_entries'] ?? [];
        if (!$has('--quiet')) {
            printf("%s  payout %s  %d entries\n", substr((string) $p['occurred_at'], 0, 10),
                money($p['amount']), count($entries));
        }
        foreach ($entries as $e) {
            $t = (string) ($e['type'] ?? '?');
            $types[$t]['n'] = ($types[$t]['n'] ?? 0) + 1;
            $types[$t]['c'] = ($types[$t]['c'] ?? 0) + (int) ($e['gross_amount_money']['amount'] ?? 0);
        }
    }

    echo "\nENTRY TYPES SEEN\n";
    uasort($types, static fn($a, $b) => abs($b['c']) <=> abs($a['c']));
    foreach ($types as $t => $v) {
        printf("  %-28s %5d  %14s\n", $t, $v['n'], money(Markup::centsToStr((int) $v['c'])));
    }
    echo "\nAnything that is not CHARGE / REFUND / FEE is money moving that the\n";
    echo "payments import never saw.\n";
    exit(0);
}

/* ---- --backfill: re-read stored payloads, no API calls ---------------
 * When a column is added AFTER an import, the data for it is usually already
 * sitting in the raw payload that was saved at the time. Re-pulling the whole
 * history from Square to fill in three columns costs thousands of API calls
 * and rewrites every row; reading what is already on disk costs one pass.
 *
 * Only ever writes the identity columns. Never touches classification, never
 * touches promoted links, never touches money. */
if ($has('--backfill')) {
    $done = 0; $changed = 0; $retimed = 0;
    $batch = 500; $lastId = 0;

    echo "Backfilling from stored payloads. No API calls.\n";
    while (true) {
        $rows = Db::all(
            "SELECT * FROM square_transactions
             WHERE id > ? AND raw IS NOT NULL
             ORDER BY id LIMIT $batch",
            [$lastId]
        );
        if (!$rows) { break; }

        foreach ($rows as $r) {
            $lastId = (int) $r['id'];
            $done++;
            $d = json_decode((string) $r['raw'], true);
            if (!is_array($d)) { continue; }

            $update = [];

            /* Re-time to the application's timezone. These rows were written
             * holding Square's UTC while every other timestamp in the database
             * is local — a seven-hour skew that moved evening jobs onto the
             * following day, and jobs near 31 December into the following
             * year's revenue. Recomputed from the saved payload, so the
             * original instant is never guessed at. */
            $local = SquareSync::localStamp((string) ($d['created_at'] ?? ($d['payout_date'] ?? '')));
            if ($local !== '' && $local !== (string) $r['occurred_at']) {
                $update['occurred_at'] = $local;
                $retimed++;
            }

            /* Re-shape the whole row from the payload rather than picking
             * fields by hand. shape() is the single definition of what a
             * Square object maps to; duplicating a subset of it here is how
             * the two drift apart and a column silently stops being filled. */
            $shaped = SquareSync::shape((string) $r['object_type'], $d);
            if ($shaped !== null) {
                foreach ($shaped as $col => $val) {
                    /* Never touched by a backfill: identity, the human's
                     * judgement, the ledger link, and the bookkeeping columns
                     * this loop is not responsible for. */
                    if (in_array($col, ['square_id', 'raw', 'occurred_at'], true)) { continue; }
                    if ((string) ($r[$col] ?? '') !== (string) $val) { $update[$col] = $val; }
                }
            }

            if ($update === []) { continue; }
            Db::update('square_transactions', (int) $r['id'], $update);
            $changed++;
        }
        printf("  %d scanned, %d updated, %d re-timed\r", $done, $changed, $retimed);
    }

    printf("\n%d rows scanned, %d updated, %d re-timed to the local zone.\n", $done, $changed, $retimed);
    printf("With a Square customer id: %d\n",
        (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE square_customer_id <> ''", [], 0));
    printf("With a name of some kind:  %d\n",
        (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE customer_name <> ''", [], 0));
    exit(0);
}

/* ---- --payout-entries: what each payout was actually made of ---------
 * The slow one — a request per payout. Resumable by design: it only asks for
 * payouts it has no entries for, so re-running continues rather than repeats. */
if ($has('--payout-entries')) {
    $sync->onPage(static function (string $t, int $page, int $imp, int $upd, int $skip): void {
        printf("  %d pages · %d new, %d changed, %d unchanged\n", $page, $imp, $upd, $skip);
        flush();
    });

    $todo = (int) Db::val(
        "SELECT COUNT(*) FROM square_transactions t WHERE t.object_type='PAYOUT'
         AND NOT EXISTS (SELECT 1 FROM square_payout_entries e WHERE e.payout_square_id = t.square_id)",
        [], 0
    );
    printf("%d payout%s still need their entries.\n", $todo, $todo === 1 ? '' : 's');

    $t0  = microtime(true);
    $res = $sync->importPayoutEntries($has('--all'));
    printf("\n%d imported, %d updated, %d unchanged, in %ss.\n",
        $res['imported'], $res['updated'], $res['skipped'], round(microtime(true) - $t0, 1));

    foreach ($res['errors'] as $e) { fwrite(STDERR, '  · ' . $e . "\n"); }
    if (!empty($res['stopped_early'])) {
        echo "\nStopped at the time limit. Run the same command again to continue —\n";
        echo "it only asks for payouts it has no entries for.\n";
    }

    echo "\nBY TYPE\n";
    foreach (Db::all(
        "SELECT entry_type, COUNT(*) n, COALESCE(SUM(gross_amount),0) t
         FROM square_payout_entries GROUP BY entry_type ORDER BY ABS(SUM(gross_amount)) DESC") as $r) {
        printf("  %-30s %6d %14s\n", $r['entry_type'], (int) $r['n'], money($r['t']));
    }
    exit($res['errors'] ? 1 : 0);
}

/* ---- --customers: mirror the directory where the names actually are -- */
if ($has('--customers')) {
    echo "Importing Square's customer directory.\n";
    $r = $sync->importCustomers();
    printf("\n%d imported, %d updated, across %d pages.\n", $r['imported'], $r['updated'], $r['pages']);
    foreach ($r['errors'] as $e) { fwrite(STDERR, '  · ' . $e . "\n"); }

    $withPhone = (int) Db::val("SELECT COUNT(*) FROM square_customers WHERE phone_e164 <> ''", [], 0);
    $withJobs  = (int) Db::val("SELECT COUNT(*) FROM square_customers WHERE payment_count > 0", [], 0);
    $repeat    = (int) Db::val("SELECT COUNT(*) FROM square_customers WHERE payment_count > 1", [], 0);
    $total     = (int) Db::val("SELECT COUNT(*) FROM square_customers", [], 0);

    printf("\n%d in the directory · %d with a usable phone number · %d with at least one completed job · %d repeat customers\n",
        $total, $withPhone, $withJobs, $repeat);
    echo "None has been added to the customer base yet — that is a separate, deliberate step.\n";
    exit($r['errors'] ? 1 : 0);
}

/* ---- the import ------------------------------------------------------ */
$since = $value('--since=');
if ($has('--all')) {
    /* Square rejects a begin_time it considers unreasonable, so "everything"
     * is expressed as a date comfortably before the business existed rather
     * than as the Unix epoch. */
    $since = '2015-01-01T00:00:00Z';
    echo "Re-pulling the ENTIRE history. Existing rows update in place; nothing duplicates.\n";
} elseif ($since !== null) {
    $since = substr($since, 0, 10) . 'T00:00:00Z';
    echo "Importing from $since.\n";
} else {
    echo "Importing anything new since the last run. Use --all to re-pull everything.\n";
}

/* Progress as it happens. A run that prints nothing until it finishes is
 * indistinguishable from a run the host has silently killed — which is
 * exactly how the first six-year re-pull was lost. */
$sync->onPage(static function (string $type, int $page, int $imp, int $upd, int $skip): void {
    printf("  %-8s page %-3d  %d new, %d changed, %d unchanged  [mem %.1fMB]\n",
        $type, $page, $imp, $upd, $skip, memory_get_usage(true) / 1048576);
    flush();
});

/* --type=REFUND runs one object type on its own. Added while chasing a sync
 * that died at the same point every run: being able to start AT the failing
 * phase, rather than after 39 pages of something else, is the difference
 * between a diagnosis and a guess. */
$only = $value('--type=');
$t0   = microtime(true);
if ($only !== null) {
    $only = strtoupper($only);
    printf("Running %s only.\n", $only);
    $res = $sync->importType($only, $since);
} else {
$res = $sync->importAll($since);
}
$secs = round(microtime(true) - $t0, 1);

printf("\n%d imported, %d updated, %d unchanged, across %d pages in %ss.\n",
    $res['imported'], $res['updated'], $res['skipped'], $res['pages'], $secs);

if (!empty($res['stopped_early'])) {
    echo "\nStopped at the time limit before the host could kill it. Progress is\n";
    echo "checkpointed — run the same command again to continue where it left off.\n";
}

if ($res['errors']) {
    fwrite(STDERR, "\nProblems:\n");
    foreach ($res['errors'] as $e) { fwrite(STDERR, '  · ' . $e . "\n"); }
}

$unreviewed = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE classification = 'UNREVIEWED'", [], 0);
if ($unreviewed > 0) {
    printf("\n%d transaction%s awaiting review. None of them has touched the ledger,\n",
        $unreviewed, $unreviewed === 1 ? '' : 's');
    echo "and none will until each is marked business or personal.\n";
}

exit($res['errors'] ? 1 : 0);
