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
 * Find columns that may be silently truncating data.
 *
 *   php data/audit_widths.php
 *   php data/audit_widths.php --all     include low-risk columns too
 *
 * WHY. MySQL outside strict mode does not refuse a value that is too long for
 * its column — it cuts it and carries on. Nothing throws, nothing logs, and
 * the damage only surfaces much later as a lookup that mysteriously misses.
 *
 * That is exactly how square_transactions.square_id failed: Square's refund
 * ids are longer than the VARCHAR(64) it was given, so every id was clipped on
 * the way in. Later syncs searched for the FULL id, found nothing, inserted,
 * and died on the unique index against the clipped copy.
 *
 * THE SIGNATURE of that failure is a column whose longest stored value is
 * exactly its declared maximum. Real data rarely lands precisely on the limit;
 * truncated data always does. This reports every column where that holds.
 *
 * READ-ONLY. It counts and samples. It changes nothing.
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

if (Db::driver() !== 'mysql') {
    echo "SQLite does not enforce VARCHAR length — nothing can be truncated there.\n";
    exit(0);
}

$showAll = in_array('--all', array_slice($argv, 1), true);

/*
 * Columns that legitimately sit at their limit and are NOT evidence of a bug:
 * a two-letter state is always two letters, a currency code always three.
 * Listing them explicitly keeps the report short enough to actually be read —
 * an audit nobody finishes is an audit that finds nothing.
 */
$expected = [
    'state', 'currency', 'card_last4', 'phone_e164', 'phone2_e164',
    'doc_type', 'postal_code', 'uom', 'date_key',
];

$cols = Db::all(
    "SELECT table_name AS t, column_name AS c, character_maximum_length AS len
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND data_type IN ('varchar','char')
       AND character_maximum_length IS NOT NULL
     ORDER BY table_name, ordinal_position"
);

$flagged = 0;
$checked = 0;
printf("%-24s %-22s %6s %8s %8s  %s\n", 'TABLE', 'COLUMN', 'MAX', 'ROWS', 'AT MAX', 'SAMPLE');
echo str_repeat('-', 108), "\n";

foreach ($cols as $col) {
    $t   = (string) $col['t'];
    $c   = (string) $col['c'];
    $len = (int) $col['len'];

    if (!$showAll && in_array($c, $expected, true)) { continue; }

    try {
        $atMax = (int) Db::val("SELECT COUNT(*) FROM `$t` WHERE CHAR_LENGTH(`$c`) = ?", [$len], 0);
    } catch (Throwable) {
        continue;   // view, or a table we cannot read — not the audit's business
    }
    $checked++;
    if ($atMax === 0) { continue; }

    $total  = (int) Db::val("SELECT COUNT(*) FROM `$t`", [], 0);
    $sample = (string) (Db::val("SELECT `$c` FROM `$t` WHERE CHAR_LENGTH(`$c`) = ? LIMIT 1", [$len], '') ?? '');

    $flagged++;
    printf("%-24s %-22s %6d %8d %8d  %s\n",
        substr($t, 0, 24), substr($c, 0, 22), $len, $total, $atMax,
        substr(preg_replace('/\s+/', ' ', $sample) ?? '', 0, 34));
}

echo str_repeat('-', 108), "\n";
printf("%d columns checked, %d sitting exactly at their limit.\n", $checked, $flagged);

if ($flagged === 0) {
    echo "\nNothing looks truncated.\n";
    exit(0);
}

echo "\nEach row above is a SUSPICION, not a verdict. A value that happens to be\n";
echo "exactly the column width is fine; a value that was CUT to it is not. Judge\n";
echo "by the sample: an id or URL ending mid-token was truncated, a tidy word was\n";
echo "not. Widening is safe and additive — see data/square_widen_id.php for the\n";
echo "shape of that fix.\n";
exit(0);
