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
 * Deploy over SSH.
 *
 *   php data/deploy-ssh.php app/Domain.php public/index.php   push named files
 *   php data/deploy-ssh.php --all                             push everything
 *   php data/deploy-ssh.php --list                            show what --all sends
 *   php data/deploy-ssh.php --check                           test the connection only
 *   php data/deploy-ssh.php --run "php data/whatever.php"     run a command on the host
 *   php data/deploy-ssh.php --put local.csv /tmp/x.csv        send a file that is not code
 *
 * WHY THIS EXISTS ALONGSIDE data/deploy.php. FTP is the lowest common
 * denominator and stays as the fallback, because some hosts offer nothing
 * else. Where SSH is available it is better in three ways that matter:
 *
 *   - It authenticates with a KEY. No password is stored, typed or logged.
 *   - It can prove what landed. FTP verifies by reading the file back through
 *     the same connection that just wrote it; this compares a SHA-256 computed
 *     ON THE SERVER against one computed here. Those answer different
 *     questions, and only the second one answers "is the file correct".
 *   - It can run a command afterwards, so linting what was just uploaded, or
 *     applying a migration, is part of the deploy rather than a second trip.
 *
 * NOTHING IN THIS FILE IS SPECIFIC TO ANY ONE INSTALL. It is written for
 * tenants: every address comes from deploy/ssh.php and every credential from
 * the environment or a private store outside the project tree, exactly as
 * data/deploy.php already does for FTP. deploy/ is excluded from every upload
 * and from version control, so one operator's details can never reach another
 * operator's server — or a repository.
 *
 * It deliberately does NOT read ~/.ssh/config. A host alias is convenient on
 * the machine that defined it and meaningless everywhere else; a tool that
 * depended on one would work for whoever wrote it and fail for everybody else.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/data/deploy-manifest.php';
require_once $root . '/data/deploy-ssh-lib.php';

/* WHAT SHIPS is answerable without credentials, and should be: an operator
 * setting this up for the first time will reasonably want to see the list
 * before handing over a key. Kept ahead of the config check for that reason. */
if (array_slice($argv, 1) === ['--list']) {
    /* Peek at the config if there is one, but never require it — and never
     * index the result of a failed include, which is `false` and warns. */
    $peek = is_file($root . '/deploy/ssh.php') ? @include $root . '/deploy/ssh.php' : false;
    $wr   = (is_array($peek) && trim((string) ($peek['web_root'] ?? '')) !== '')
        ? trim((string) $peek['web_root'], '/') : 'public_html';
    foreach (deploy_order(deploy_file_list($root)) as $f) {
        fwrite(STDOUT, sprintf("  %-52s → %s\n", $f, deploy_remote_path($f, $wr)));
    }
    exit(0);
}

/* ---------------------------------------------------------------- config -- */

$cfgFile = $root . '/deploy/ssh.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, <<<TXT

    No SSH deploy settings found.

    Create deploy/ssh.php with the details of YOUR host. That folder is
    excluded from version control and from every upload, so what you put
    there stays on this machine.

        <?php
        require_once __DIR__ . '/../data/secrets.php';

        return [
            // Where the host is. Safe to write here.
            'host'     => 'ssh.example.com',
            'port'     => 22,

            // Where the application lives on the server: the folder that
            // holds app/, data/ and the web root.
            'dir'      => '/home/you/www/example.com',

            // The folder the host actually serves inside that directory.
            // public/ is uploaded AS this folder.
            'web_root' => 'public_html',

            // The login and the key. NOT written here — they come from the
            // environment or the private store outside the project tree,
            // the same way the FTP credentials do. Set WKR_SSH_USER and
            // WKR_SSH_KEY, or add ssh_user / ssh_key to the store.
            'user'     => wkr_secret('WKR_SSH_USER', 'ssh_user'),
            'key'      => wkr_secret('WKR_SSH_KEY',  'ssh_key'),
        ];

    The key value is a PATH to your private key file, never the key itself.

    TXT);
    exit(1);
}

$cfg = require $cfgFile;

foreach ([['host', 'the hostname'], ['dir', 'the application directory'],
          ['user', 'WKR_SSH_USER / ssh_user'], ['key', 'WKR_SSH_KEY / ssh_key']] as [$k, $what]) {
    if (trim((string) ($cfg[$k] ?? '')) === '') {
        fwrite(STDERR, "deploy/ssh.php is missing '$k' ($what). Nothing was sent.\n");
        exit(1);
    }
}
if (!is_file((string) $cfg['key'])) {
    fwrite(STDERR, "The private key was not found at: {$cfg['key']}\n"
        . "'key' must be a PATH to the key file, not the key itself.\n");
    exit(1);
}

$host    = (string) $cfg['host'];
$port    = (string) ($cfg['port'] ?: 22);
$user    = (string) $cfg['user'];
$key     = (string) $cfg['key'];
$dir     = rtrim((string) $cfg['dir'], '/');
$webRoot = trim((string) ($cfg['web_root'] ?: 'public_html'), '/');

/* ------------------------------------------------------------- plumbing -- */

/* Transport lives in data/deploy-ssh-lib.php — the setup wizard uploads over
 * the same connection, and "did this file land correctly" must not have two
 * implementations that can disagree. */

$conn = ['host' => $host, 'port' => $port, 'user' => $user, 'key' => $key];

/* ----------------------------------------------------------------- args -- */

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "Nothing to do. Name the files to push, or use --all / --list / --check.\n"
        . "  php data/deploy-ssh.php app/Domain.php public/index.php\n");
    exit(1);
}

/* Named files are checked BEFORE the connection is opened. A request to send
 * something that must never travel is an operator error worth refusing loudly,
 * and refusing it before touching the network makes that unambiguous. */
if ($args !== ['--all'] && $args !== ['--check']
    && ($args[0] ?? '') !== '--run' && ($args[0] ?? '') !== '--put') {
    foreach ($args as $rel) {
        $rel = str_replace('\\', '/', $rel);
        if (deploy_is_forbidden($rel)) {
            fwrite(STDERR, "REFUSED: $rel never ships — see data/deploy-manifest.php.\nNothing was sent.\n");
            exit(1);
        }
        if (!is_file($root . '/' . $rel)) {
            fwrite(STDERR, "Not found: $rel\nNothing was sent.\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "Checking {$user}@{$host}:{$port} … ");
[$code, $out] = wkr_ssh_run('test -d ' . escapeshellarg($dir) . ' && echo ok', $conn);
if ($code !== 0) {
    fwrite(STDOUT, "FAILED\n\n$out\n\nNothing was sent.\n");
    exit(1);
}
if (trim($out) !== 'ok') {
    fwrite(STDOUT, "connected, but $dir does not exist on the host.\nNothing was sent.\n");
    exit(1);
}
fwrite(STDOUT, "connected.\n");

if ($args === ['--check']) { exit(0); }

if ($args[0] === '--run') {
    $cmd = (string) ($args[1] ?? '');
    if ($cmd === '') { fwrite(STDERR, "--run needs a command.\n"); exit(1); }
    /* exec() goes through cmd.exe on Windows, which truncates a command line at
     * 8191 characters — silently, and the far end then runs the fragment. That
     * looked like "the append did nothing" for a long time. Anything larger is
     * a file, and files have --put. */
    if (strlen($cmd) > 6000) {
        fwrite(STDERR, "That command is " . strlen($cmd) . " characters. Windows truncates a\n"
            . "command line at 8191 and the remote end would run the fragment. Put the\n"
            . "payload in a file and send it with --put instead.\n");
        exit(1);
    }
    [$code, $out] = wkr_ssh_run('cd ' . escapeshellarg($dir) . ' && ' . $cmd, $conn);
    fwrite(STDOUT, $out . "\n");
    exit($code);
}

/* --put: one local file to an absolute remote path, verified by hash.
 *
 * Not part of a deploy and deliberately outside the manifest: the manifest
 * governs what the APPLICATION is made of, and this sends things that are not
 * the application — a CSV export to be imported, a dump to be restored. Those
 * have no business in deploy_file_list(), and squeezing them through a base64
 * pipeline in --run is how the 8191-character truncation above was discovered. */
if ($args[0] === '--put') {
    $local  = (string) ($args[1] ?? '');
    $remote = (string) ($args[2] ?? '');
    if ($local === '' || $remote === '') {
        fwrite(STDERR, "--put needs a local file and an absolute remote path.\n");
        exit(1);
    }
    if (!is_file($local)) { fwrite(STDERR, "Not found: $local\n"); exit(1); }
    if (!str_starts_with($remote, '/')) {
        fwrite(STDERR, "The remote path must be absolute, so there is no doubt where it lands.\n");
        exit(1);
    }

    $err = wkr_ssh_put($local, $remote, $conn);
    if ($err !== '') { fwrite(STDERR, "FAILED: $err\n"); exit(1); }

    $ok = wkr_ssh_verify($local, $remote, $conn);
    printf("  %s → %s  %s\n", basename($local), $remote,
        $ok ? 'ok (sha256 verified)' : 'UPLOADED BUT HASH MISMATCH');
    exit($ok ? 0 : 1);
}

$files = $args === ['--all'] ? deploy_file_list($root) : $args;
$files = deploy_order(array_map(fn($f) => str_replace('\\', '/', $f), $files));
$total = count($files);
$n     = 0;
$bad   = '';

foreach ($files as $rel) {
    $n++;
    $remote = $dir . '/' . deploy_remote_path($rel, $webRoot);
    fwrite(STDOUT, sprintf("  %3d/%d  %-52s ", $n, $total, substr($rel, 0, 52)));

    $err = wkr_ssh_put($root . '/' . $rel, $remote, $conn);
    if ($err !== '') { $err = wkr_ssh_put($root . '/' . $rel, $remote, $conn); }   // one retry
    if ($err !== '') { fwrite(STDOUT, "FAILED\n"); $bad = "$rel — $err"; break; }

    if (!wkr_ssh_verify($root . '/' . $rel, $remote, $conn)) {
        fwrite(STDOUT, "MISMATCH\n");
        $bad = "$rel — uploaded, but the server's checksum does not match";
        break;
    }
    fwrite(STDOUT, "ok\n");
}

if ($bad !== '') {
    fwrite(STDERR, "\nDEPLOY STOPPED at $bad\n"
        . "Everything before this point did land. Fix and re-run — this tool only\n"
        . "sends what it is asked for, so re-running is safe.\n");
    exit(1);
}

fwrite(STDOUT, "\nDone — $total file" . ($total === 1 ? '' : 's') . " uploaded and verified by SHA-256 on the server.\n");
