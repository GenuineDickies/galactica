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
 * Classify imported Square history as business or personal.
 *
 *   php data/square_classify.php --analyze          what is there, change nothing
 *   php data/square_classify.php --review           the rows worth a human look
 *   php data/square_classify.php --business --dry-run
 *   php data/square_classify.php --business         mark everything unreviewed as BUSINESS
 *   php data/square_classify.php --set 123,456 --as PERSONAL
 *   php data/square_classify.php --undo 123         back to UNREVIEWED
 *
 * WHY THIS EXISTS AND WHY IT DEFAULTS TO BUSINESS.
 *
 * The mirror holds 5,857 rows. Reviewing them one at a time is not work anyone
 * will finish, and an unreviewed mirror never reaches the ledger, so the
 * import would have been for nothing.
 *
 * The account is a MERCHANT account: money arriving is a customer paying for
 * work. Personal SPENDING does not appear here at all — that is on bank and
 * card statements this application never sees. The owner's own estimate is
 * one or two non-business charges in six years. So BUSINESS is the honest
 * default and the job is to surface the handful of exceptions, not to
 * interrogate every row.
 *
 * CLASSIFYING IS NOT POSTING. Nothing here writes a journal entry. It records
 * a judgement; posting is a separate, later, deliberate step.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$args  = array_slice($argv, 1);
$has   = static fn(string $f): bool => in_array($f, $args, true);
$value = static function (string $prefix) use ($args): ?string {
    foreach ($args as $i => $a) {
        if ($a === $prefix && isset($args[$i + 1])) { return $args[$i + 1]; }
        if (str_starts_with($a, $prefix . '=')) { return substr($a, strlen($prefix) + 1); }
    }
    return null;
};

const CLASSES = ['UNREVIEWED', 'BUSINESS', 'PERSONAL', 'TRANSFER'];

/* ---- --analyze ------------------------------------------------------- */
if ($has('--analyze') || $args === []) {
    echo "WHAT IS IN THE MIRROR\n\n";
    printf("%-10s %-12s %8s %14s\n", 'TYPE', 'CLASS', 'COUNT', 'TOTAL');
    foreach (Db::all(
        "SELECT object_type, classification, COUNT(*) n, COALESCE(SUM(amount),0) t
         FROM square_transactions GROUP BY object_type, classification
         ORDER BY object_type, classification") as $r) {
        printf("%-10s %-12s %8d %14s\n", $r['object_type'], $r['classification'], (int) $r['n'], money($r['t']));
    }

    $unreviewed = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE classification = 'UNREVIEWED'", [], 0);
    $completed  = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE object_type='PAYMENT' AND status='COMPLETED'", [], 0);
    $failed     = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE object_type='PAYMENT' AND status<>'COMPLETED'", [], 0);

    printf("\nUnreviewed: %d\n", $unreviewed);
    printf("Completed payments: %d · not completed: %d\n", $completed, $failed);
    echo "\nA declined card is not money and will never post, whatever it is classified as.\n";
    echo "Run --review to see the rows worth a second look before bulk-marking.\n";
    exit(0);
}

/* ---- --review: the outliers ------------------------------------------
 * Six years of roadside jobs sit in a fairly tight band. What is worth a
 * human's attention is what falls outside it, so this shows the extremes and
 * anything that does not look like a job — not a random sample. */
if ($has('--review')) {
    $stats = Db::one(
        "SELECT COUNT(*) n, AVG(amount) avg, MAX(amount) max
         FROM square_transactions WHERE object_type='PAYMENT' AND status='COMPLETED'"
    ) ?? ['n' => 0, 'avg' => 0, 'max' => 0];

    printf("%d completed payments · average %s · largest %s\n\n",
        (int) $stats['n'], money($stats['avg']), money($stats['max']));

    echo "TEN LARGEST — an unusually big charge is the most likely non-job\n";
    foreach (Db::all(
        "SELECT id, occurred_at, amount, customer_name, note, classification
         FROM square_transactions
         WHERE object_type='PAYMENT' AND status='COMPLETED'
         ORDER BY amount DESC LIMIT 10") as $r) {
        printf("  #%-6d %s %10s  %-22s %-12s %s\n", (int) $r['id'], substr((string) $r['occurred_at'], 0, 10),
            money($r['amount']), substr((string) $r['customer_name'], 0, 22),
            $r['classification'], substr((string) $r['note'], 0, 30));
    }

    echo "\nTEN SMALLEST NON-ZERO — a $1 charge is rarely a roadside job\n";
    foreach (Db::all(
        "SELECT id, occurred_at, amount, customer_name, note, classification
         FROM square_transactions
         WHERE object_type='PAYMENT' AND status='COMPLETED' AND amount > 0
         ORDER BY amount ASC LIMIT 10") as $r) {
        printf("  #%-6d %s %10s  %-22s %-12s %s\n", (int) $r['id'], substr((string) $r['occurred_at'], 0, 10),
            money($r['amount']), substr((string) $r['customer_name'], 0, 22),
            $r['classification'], substr((string) $r['note'], 0, 30));
    }

    $notCard = Db::all(
        "SELECT id, occurred_at, amount, source_type, note, classification
         FROM square_transactions
         WHERE object_type='PAYMENT' AND status='COMPLETED' AND source_type <> 'CARD'
         ORDER BY amount DESC LIMIT 15");
    if ($notCard) {
        echo "\nNOT TAKEN ON A CARD — cash and other tenders, worth a glance\n";
        foreach ($notCard as $r) {
            printf("  #%-6d %s %10s  %-10s %-12s %s\n", (int) $r['id'], substr((string) $r['occurred_at'], 0, 10),
                money($r['amount']), (string) $r['source_type'], $r['classification'], substr((string) $r['note'], 0, 30));
        }
    }

    /* A cluster of tiny charges is the signature of card-reader testing, not
     * of work done. Counting them as a group matters more than listing them:
     * ten $1 charges is somebody checking a terminal works, and that is not
     * revenue however it is classified. */
    $tiny = Db::one(
        "SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM square_transactions
         WHERE object_type='PAYMENT' AND status='COMPLETED' AND amount > 0 AND amount < 5"
    ) ?? ['n' => 0, 't' => 0];
    printf("\nUNDER \$5: %d charges totalling %s — usually terminal tests, not jobs.\n",
        (int) $tiny['n'], money($tiny['t']));

    $cash = Db::one(
        "SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM square_transactions
         WHERE object_type='PAYMENT' AND status='COMPLETED' AND source_type <> 'CARD'"
    ) ?? ['n' => 0, 't' => 0];
    printf("NOT ON A CARD: %d payments totalling %s — cash jobs keyed into Square.\n",
        (int) $cash['n'], money($cash['t']));

    echo "\nMark anything that is not White Knight work:\n";
    echo "  php data/square_classify.php --set 123,456 --as PERSONAL\n";
    exit(0);
}

/* ---- --set / --undo --------------------------------------------------- */
if ($has('--set') || $has('--undo')) {
    $ids = array_filter(array_map('intval', explode(',', (string) ($value('--set') ?? $value('--undo') ?? ''))));
    if (!$ids) { fwrite(STDERR, "No ids given.\n"); exit(1); }

    $as = $has('--undo') ? 'UNREVIEWED' : strtoupper((string) ($value('--as') ?? ''));
    if (!in_array($as, CLASSES, true)) {
        fwrite(STDERR, "--as must be one of: " . implode(', ', CLASSES) . "\n");
        exit(1);
    }

    $user = Auth::user();
    foreach ($ids as $id) {
        $row = Db::one('SELECT * FROM square_transactions WHERE id = ?', [$id]);
        if ($row === null) { printf("  #%d not found\n", $id); continue; }
        if ($row['posted_entry_id'] !== null) {
            printf("  #%d has already posted to the ledger — reclassifying it now would leave the entry behind. Reverse the entry first.\n", $id);
            continue;
        }
        Db::update('square_transactions', $id, [
            'classification' => $as,
            'classified_by'  => $user['id'] ?? null,
            'classified_at'  => now(),
        ]);
        Audit::log('square_transaction', $id, 'classified', $row['classification'] . ' -> ' . $as);
        printf("  #%-6d %10s  %s -> %s\n", $id, money($row['amount']), $row['classification'], $as);
    }
    exit(0);
}

/* ---- --business: the bulk default ------------------------------------- */
if ($has('--business')) {
    $dry = $has('--dry-run');

    $n = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE classification = 'UNREVIEWED'", [], 0);
    if ($n === 0) { echo "Nothing is unreviewed.\n"; exit(0); }

    printf("%s%d unreviewed row%s as BUSINESS.\n",
        $dry ? 'Would mark ' : 'Marking ', $n, $n === 1 ? '' : 's');

    if ($dry) {
        echo "\nNothing was written. Run --review first if you have not, then re-run without --dry-run.\n";
        exit(0);
    }

    $user = Auth::user();
    Db::q(
        "UPDATE square_transactions
         SET classification = 'BUSINESS', classified_by = ?, classified_at = ?
         WHERE classification = 'UNREVIEWED'",
        [$user['id'] ?? null, now()]
    );
    Audit::log('system', 0, 'square:classified', $n . ' rows marked BUSINESS in bulk');

    printf("\nDone. %d rows are now BUSINESS.\n", $n);
    echo "Anything you spot later can be corrected:\n";
    echo "  php data/square_classify.php --set <id> --as PERSONAL\n\n";
    echo "NOTHING HAS POSTED. Classifying records a judgement; posting is a separate step.\n";
    exit(0);
}

fwrite(STDERR, "Try --analyze, --review, --business, or --set <ids> --as PERSONAL.\n");
exit(1);
