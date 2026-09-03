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
 * White Knight Roadside — Admin
 * Configuration TEMPLATE. Copy to config.php and fill in the database
 * credentials — config.php itself is gitignored so real credentials never
 * reach a repository.
 *
 *   cp config.example.php config.php      (or copy in Explorer)
 *
 * Every credential can instead come from an environment variable, so a
 * deployment can keep this file empty of secrets entirely.
 * A PRODUCTION config is not written by hand at all — data/setup.php
 * option 5 generates one with a real admin hash and debug off.
 */

return [
    // --- Database -------------------------------------------------------
    // MySQL 8 / MariaDB is the target, in production and locally alike.
    // Create the database and import data/schema.mysql.sql before first run —
    // see README.
    'db' => [
        'driver'   => getenv('WKR_DB_DRIVER') ?: 'mysql',

        'host'     => getenv('WKR_DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('WKR_DB_PORT') ?: '3306',
        'database' => getenv('WKR_DB_NAME') ?: 'wkr_admin',
        'username' => getenv('WKR_DB_USER') ?: '',            // ← fill in
        'password' => getenv('WKR_DB_PASS') ?: '',            // ← fill in

        // Only used when driver is 'sqlite' — which the test suite does, so it
        // can run without a server. Not a production path.
        'path'     => __DIR__ . '/storage/wkr.sqlite',
    ],

    // --- Business defaults (editable in Settings) -----------------------
    'company' => [
        'name'    => 'White Knight Roadside, LLC',
        'short'   => 'White Knight Roadside',
        'tagline' => 'We Answer the Call',
        'phone'   => '(503) 764-3154',
        'email'   => 'admin@wkrllc.com',
        'city'    => 'Portland',
        'state'   => 'OR',
        // Applied in App::boot(), never inherited from php.ini — document
        // numbers are date-keyed and signature times are evidence, so the
        // clock is not left to whatever the host happens to ship.
        'tz'      => getenv('WKR_TZ') ?: 'America/Los_Angeles',
    ],

    // --- Hard business rules (see docs/BUSINESS_RULES.md) ----------------
    'rules' => [
        'authorization_threshold'  => 200.00, // estimate total above which a signature is required
        'variance_abs'             => 200.00, // invoice vs estimate: absolute $ trigger
        'variance_pct'             => 0.10,   // invoice vs estimate: percentage trigger
        'default_labor_rate'       => 125.00,
        'drive_time_rate'          => 81.25,  // 65% of labor rate
        'mileage_rate'             => 0.72,
        'default_tax_rate'         => 0.0,    // Oregon has no sales tax
        'eta_minutes'              => ['EMERGENCY' => 30, 'URGENT' => 60, 'STANDARD' => 120, 'APPOINTMENT' => 0],
    ],

    // Which driver backs each third-party service. Every one is swappable
    // without touching business logic — see docs/INTEGRATIONS.md.
    // The default drivers are self-contained: every flow works end to end
    // with no account anywhere.
    'integrations' => [
        'sms'      => 'outbox',      // outbox | telnyx
        'payments' => 'manual',      // manual | square
        'vin'      => 'structural',
        'geocoder' => 'osm',         // osm | google | manual
    ],

    'app' => [
        'debug'   => true,           // local development only — never true on a public server
        'name'    => 'White Knight Roadside — Admin',
        'version' => '1.0.0-mvp',
    ],

    // --- First-boot install ----------------------------------------------
    // When the application starts against an empty database it installs
    // itself: schema, then seed data according to 'mode'.
    //
    //   clean    admin login, settings, markup tiers — no business data
    //   catalog  … plus the example Products & Services price book
    //   demo     … plus example staff, customers and jobs (dev only)
    //
    // 'admin' names the REAL admin login and is what decides which of the two
    // accounts the install seeds:
    //
    //   password_hash empty  a TEMPORARY login at admin@setup.com / admin123,
    //                        flagged is_setup. Creating a real admin under
    //                        Admin → Users deactivates it. The email below is
    //                        ignored in this case, so a working address is
    //                        never seeded with a published password.
    //   password_hash set    a REAL admin at the email below, not flagged,
    //                        never auto-retired. `php data/setup.php` option 5
    //                        fills this in with a bcrypt hash, which is how a
    //                        public server comes up without a known password.
    //
    // Fill these in with your own details — nothing here is a shipped default.
    'install' => [
        'mode'     => 'demo',
        'base_url' => '',
        'admin'    => [
            'first_name'    => '',
            'last_name'     => '',
            'email'         => '',
            'password_hash' => '',
        ],
    ],
];
