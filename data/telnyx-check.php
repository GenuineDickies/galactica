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
 * What Telnyx thinks our messaging configuration is. THE SERVER ONLY.
 *
 *   php data/telnyx-check.php        (over SSH, on the live host)
 *
 * The same check is on the admin site under Admin → Telnyx check, which is the
 * easier route on hosting without shell access. Both render TelnyxAudit, so
 * they cannot drift apart.
 *
 * Why it refuses to run locally: half the answer comes from the settings of
 * whichever database this process is pointed at — the profile id, the signing
 * public key, the base URL callbacks are expected on. Run from a workstation
 * against a development database, those values describe a database Telnyx has
 * never sent anything to, while the output looks exactly like a report about
 * production. A check that can quietly describe the wrong machine is worse than
 * no check, because it is believed.
 *
 * The test for "am I on the server" is the presence of deploy/. That folder is
 * excluded from every upload list in data/deploy.php and data/setup.php, and is
 * refused explicitly by name if anyone tries to send it. If it is here, this is
 * a workstation checkout.
 *
 * Read-only. It changes nothing, here or at Telnyx.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

if (is_dir($root . '/deploy')) {
    fwrite(STDERR,
        "Refusing to run: deploy/ is present, so this is a workstation checkout,\n" .
        "not the server. The profile id, public key and base URL would be read\n" .
        "from the local database and reported as though they were production.\n\n" .
        "Run it on the live host over SSH, or open Admin → Telnyx check on the\n" .
        "site, which runs this same audit with the server's own settings.\n");
    exit(2);
}

$cfg = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

App::boot($cfg);
Db::boot($cfg['db']);

/* ------------------------------------------------------------------ */

function line(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function mark(string $level): string
{
    return match ($level) {
        'ok'    => "  \033[32m✓\033[0m ",
        'warn'  => "  \033[33m!\033[0m ",
        default => "  \033[31m✗\033[0m ",
    };
}

$audit = TelnyxAudit::run();
$i     = $audit['install'];

line();
line("\033[1mTelnyx configuration check\033[0m");
line(str_repeat('-', 62));

line();
line("\033[1mThis install\033[0m");
line('  SMS driver           ' . $i['driver']);
line('  base URL             ' . ($i['base_url'] ?: '(none)'));
line('  answers callbacks at ' . $i['expected_hook']);
line('  messaging profile    ' . ($i['profile_id'] !== '' ? $i['profile_id'] : '(none configured)'));
line($i['has_public_key'] ? mark('ok') . 'Signing public key configured.'
                          : mark('bad') . 'No public key — every callback is refused before it is read.');
line($i['has_sodium'] ? mark('ok') . 'sodium extension present.'
                      : mark('bad') . 'No sodium extension — no callback can ever verify.');

line();
line("\033[1mTelnyx says\033[0m");

if ($audit['error'] !== '') {
    line(mark('bad') . $audit['error']);
}

foreach ($audit['profiles'] as $p) {
    line();
    line('  ' . $p['name'] . '  ' . $p['id']
        . ($p['is_ours'] ? "  \033[1m← the profile this install sends through\033[0m" : ''));
    foreach ($p['findings'] as $f) {
        line(mark($f['level']) . $f['text']);
        if (($f['fix'] ?? '') !== '') { line('      ' . $f['fix']); }
    }
}

line();
line(str_repeat('-', 62));
line($audit['problems'] === 0
    ? "  \033[32mNothing found that would stop a delivery receipt.\033[0m"
    : "  \033[31m" . $audit['problems'] . ' problem(s) above would stop delivery receipts.' . "\033[0m");
line();

exit($audit['problems'] === 0 ? 0 : 1);
