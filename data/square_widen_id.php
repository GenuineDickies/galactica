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
 * Widen square_transactions.square_id from VARCHAR(64) to VARCHAR(191).
 *
 *   php data/square_widen_id.php --check    report only, change nothing
 *   php data/square_widen_id.php --apply
 *
 * WHY THIS IS A SCRIPT AND NOT PART OF migrate().
 *
 * Db::migrate() is additive by construction: it creates missing tables and
 * appends missing columns, and deliberately never drops, renames or RETYPES
 * anything. That rule is worth keeping — a migration that silently retypes
 * columns is a migration that can silently lose data.
 *
 * Widening a VARCHAR is the one shape of retype that cannot lose anything,
 * but it still deserves to be an explicit, named, reviewable act rather than
 * something that happens as a side effect of a deploy.
 *
 * THE BUG. Square's refund ids are longer than 64 characters. MySQL, outside
 * strict mode, truncated them on insert without raising anything. Every later
 * sync then looked up the FULL id, found nothing, tried to insert, and hit the
 * unique index against the truncated copy — a fatal duplicate-key error part
 * way through the run, with no output and no summary. Refunds only, because
 * payment and payout ids are shorter.
 *
 * Nothing is deleted here. The truncated rows keep their data; SquareSync
 * repairs each one's id in place the next time that object is seen, by
 * matching on the 64-character prefix.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
App::boot($cfg);
Db::boot($cfg['db']);

$apply = in_array('--apply', array_slice($argv, 1), true);

if (Db::driver() !== 'mysql') {
    echo "SQLite does not enforce VARCHAR length, so nothing needs widening.\n";
    exit(0);
}

$col = Db::one(
    "SELECT character_maximum_length AS len, column_type AS type
     FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'square_transactions'
       AND column_name = 'square_id'"
);

if ($col === null) {
    fwrite(STDERR, "square_transactions.square_id does not exist. Apply the schema first.\n");
    exit(1);
}

printf("square_id is currently %s\n", $col['type']);

/* EXACTLY 64 is the suspicious length — that is where the old column cut.
 * Anything LONGER than 64 has already been repaired and is proof the fix
 * worked, so counting ">= 64" lumps the problem in with the cure and reads
 * as though nothing improved. */
$clipped  = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE CHAR_LENGTH(square_id) = 64", [], 0);
$repaired = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE CHAR_LENGTH(square_id) > 64", [], 0);
$total    = (int) Db::val("SELECT COUNT(*) FROM square_transactions", [], 0);
$distinct = (int) Db::val("SELECT COUNT(DISTINCT square_id) FROM square_transactions", [], 0);

printf("Ids of exactly 64 characters (still clipped): %d\n", $clipped);
printf("Ids longer than 64 (already repaired):        %d\n", $repaired);
printf("Rows: %d · distinct ids: %d%s\n", $total, $distinct,
    $total === $distinct ? ' — no duplicates' : ' — DUPLICATES PRESENT');

if ($clipped > 0) {
    foreach (Db::all(
        "SELECT object_type, COUNT(*) n FROM square_transactions
         WHERE CHAR_LENGTH(square_id) = 64 GROUP BY object_type") as $r) {
        printf("  still clipped: %-8s %d\n", $r['object_type'], (int) $r['n']);
    }
}

if ((int) $col['len'] >= 191) {
    echo "\nAlready wide enough. Nothing to do.\n";
    exit(0);
}

if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to widen the column.\n";
    echo "Widening cannot lose data: every existing value already fits.\n";
    exit(0);
}

try {
    Db::pdo()->exec("ALTER TABLE square_transactions MODIFY square_id VARCHAR(191) NOT NULL");
} catch (Throwable $e) {
    fwrite(STDERR, "\nThe change did not complete: " . $e->getMessage() . "\n");
    exit(1);
}

Audit::log('system', 0, 'schema:widened', 'square_transactions.square_id -> VARCHAR(191)');

echo "\nWidened to VARCHAR(191).\n";
echo "Now re-run:  php data/square_sync.php --all\n";
echo "Truncated ids are repaired in place as each object is seen again.\n";
echo "Nothing is deleted and no row is duplicated.\n";
