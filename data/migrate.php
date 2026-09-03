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
 * Apply the additive schema upgrade to an EXISTING database and stop.
 *
 *   php data/migrate.php
 *
 * Db::migrate() ALTER-adds any column that app/Schema.php declares and the
 * live database lacks. It never drops, renames, or rewrites anything —
 * install.php runs the same call, but also seeds; this script exists so a
 * deployed install can pick up new columns without touching its data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

App::boot($cfg);
Db::boot($cfg['db']);

$pending = Db::pending();
if ($pending === []) {
    echo "Nothing to do — the live schema already matches app/Schema.php.\n";
    exit(0);
}
foreach ($pending as $table => $cols) {
    if ($cols === []) { echo "CREATE $table (missing entirely)\n"; continue; }
    foreach ($cols as $name => $decl) { echo "ALTER  $table ADD $name $decl\n"; }
}
Db::migrate();
echo "Applied.\n";
