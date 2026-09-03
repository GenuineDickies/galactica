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
 * First-run installer.
 *
 * Three install options:
 *
 *   php data/install.php            CLEAN INSTALL — admin login, settings and
 *                                   markup tiers only. No business data.
 *   php data/install.php --catalog  CLEAN WITH CATALOG — clean install plus the
 *                                   example Products & Services price book.
 *   php data/install.php --demo     FULL DEMO — everything: example staff,
 *                                   customers and jobs. Dev and testing only.
 *
 *   php data/install.php --dump     write data/schema.mysql.sql and stop
 *
 * Reads config.php, so it targets whatever database the application targets.
 * It creates tables but never drops them: running it against a live database is
 * safe, and pointing it at the wrong one cannot destroy anything.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) { require $f; }

$args = array_slice($argv, 1);

if (in_array('--dump', $args, true)) {
    $sql = "-- White Knight Roadside — MySQL 8 / MariaDB schema\n"
         . "-- Generated from app/Schema.php. Do not edit by hand: regenerate with\n"
         . "--   php data/install.php --dump\n\n"
         . implode(";\n\n", Schema::statements('mysql')) . ";\n";
    file_put_contents($root . '/data/schema.mysql.sql', $sql);
    fwrite(STDOUT, "Wrote data/schema.mysql.sql (" . number_format(strlen($sql)) . " bytes)\n");
    exit;
}

App::boot($cfg);
Db::boot($cfg['db']);

$driver = Db::driver();
fwrite(STDOUT, "Database: $driver\n");

try {
    Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR,
        "\nCould not connect.\n\n" .
        ($driver === 'mysql'
            ? "Create the database and user first, then set the credentials in config.php\n" .
              "or in the environment (WKR_DB_NAME, WKR_DB_USER, WKR_DB_PASS):\n\n" .
              "  CREATE DATABASE wkr_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" .
              "  CREATE USER 'wkr'@'localhost' IDENTIFIED BY 'a-real-password';\n" .
              "  GRANT ALL PRIVILEGES ON wkr_admin.* TO 'wkr'@'localhost';\n\n"
            : "Check that storage/ exists and is writable.\n\n") .
        $e->getMessage() . "\n");
    exit(1);
}

Db::migrate();
fwrite(STDOUT, "Schema is up to date.\n");

/* Vehicle reference data, in every install mode — it is a fact table about
 * the US car market, not business data, so even a clean install gets the
 * year/make/model dropdowns. Additive and idempotent, so re-running install
 * against a live database only fills gaps. */
require $root . '/data/seed_vehicles.php';
$veh = seed_vehicle_catalog($root . '/data/vehicle-models.csv');
fwrite(STDOUT, $veh['ok']
    ? "Vehicle catalog: {$veh['added']} added, {$veh['present']} total.\n"
    : "Vehicle catalog skipped: {$veh['error']}\n");

require $root . '/data/seed.php';

if (Db::val('SELECT COUNT(*) FROM users') > 0) {
    fwrite(STDOUT, "Users already exist — nothing was seeded.\n");
    exit;
}

$demo    = in_array('--demo', $args, true);
$catalog = $demo || in_array('--catalog', $args, true);

seed_core();
if ($catalog) { seed_catalog(); }
if ($demo)    { seed_staff(); seed_demo_data(); }

fwrite(STDOUT,
    ($demo ? 'Full Demo' : ($catalog ? 'Clean with Catalog' : 'Clean Install')) .
    ": seeded the admin login, settings and markup tiers." .
    ($catalog ? " Example catalog loaded." : " Catalog is empty — build yours at /catalog.") . "\n\n" .
    (Db::val("SELECT COUNT(*) FROM users WHERE is_setup = 1") > 0
        ? '  ' . Rules::SETUP_EMAIL . " / admin123   TEMPORARY setup admin\n"
        : '  ' . Db::val("SELECT email FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")
          . "   (the admin configured in config.php — sign in with the password you set)\n") .
    ($demo ? "  dispatch@wkrllc.com / dispatch123\n  tech@wkrllc.com / tech123\n" : "") .
    (Db::val("SELECT COUNT(*) FROM users WHERE is_setup = 1") > 0
        ? "\nThe setup admin is temporary: create your real admin account under\n" .
          "Admin -> Users and the setup login deactivates itself automatically.\n"
        : "\nNo temporary login was seeded — config.php names a real admin.\n"));
