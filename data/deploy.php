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
declare(strict_types=1);

/**
 * White Knight Roadside — Admin
 * Non-interactive deploy: push files to the production server over FTPS.
 *
 *   php data/deploy.php app/Domain.php public/index.php   upload named files
 *   php data/deploy.php --all                             upload the whole application
 *   php data/deploy.php --list                            show what --all would send
 *
 * Credentials come from deploy/ftp.php (or WKR_FTP_* environment variables) —
 * see that file for where the values come from. This script NEVER touches
 * config.php on the server: the production config is written once by
 * data/setup.php option 5 and left alone. It also never touches any database.
 *
 * Same rules as setup.php's uploader: public/ lands as the host's web root
 * folder (public_html), everything else beside it, outside the web root.
 * Every PHP file is linted locally first — a parse error never ships.
 * Every uploaded file is read back and byte-compared. Nothing lands silently.
 */

$root = dirname(__DIR__);

// --- Credentials -----------------------------------------------------------
// The loader is required here rather than relied upon: wkr_secret_missing() is
// called below, and until now it existed only because deploy/ftp.php happened
// to pull it in. An operator whose ftp.php predates the move, or was written by
// hand, would have hit "undefined function" instead of the message that names
// the missing credential.
require_once $root . '/data/secrets.php';

$credsFile = $root . '/deploy/ftp.php';
if (!is_file($credsFile)) {
    fwrite(STDERR, "deploy/ftp.php is missing. Create it from the template in the repo,\nor set the WKR_FTP_* environment variables.\n");
    exit(1);
}
$ftp = require $credsFile;

// The username and password are deliberately absent from deploy/ftp.php — they
// come from the environment or from the private store outside the project tree.
// Say which one is missing and where it is looked for, because "deploy failed"
// with no further detail sends an operator hunting through the wrong file.
$credKeys = ['host' => ['WKR_FTP_HOST', 'ftp_host'],
             'user' => ['WKR_FTP_USER', 'ftp_user'],
             'pass' => ['WKR_FTP_PASS', 'ftp_pass']];
foreach ($credKeys as $k => [$env, $storeKey]) {
    if (($ftp[$k] ?? '') === '') {
        fwrite(STDERR, wkr_secret_missing($env, $storeKey)
            . "\nThese are the FTP ACCOUNT details from the hosting control panel\n"
            . "(SiteGround: Site Tools → Site → FTP Accounts). They are not the\n"
            . "database credentials and not the Site Tools login.\n");
        exit(1);
    }
}
$base     = ($ftp['protocol'] === 'sftp' ? 'sftp' : 'ftp') . '://' . $ftp['host'] . ':' . ($ftp['port'] ?: '21');
$dir      = (string) ($ftp['dir'] ?: '/');
$webRoot  = trim((string) ($ftp['web_root'] ?: 'public_html'), '/');
$insecure = (bool) ($ftp['insecure'] ?? true);
$user     = (string) $ftp['user'];
$pass     = (string) $ftp['pass'];

// --- What to send ----------------------------------------------------------
/* What ships is defined once, in data/deploy-manifest.php, and read by every
 * deployer. It used to be a private copy in this file; a second tool meant a
 * second copy, and two lists of "what must never reach a server" that drift
 * apart silently is precisely the failure nobody notices until it matters. */
require_once __DIR__ . '/deploy-manifest.php';

function file_list(string $root): array { return deploy_file_list($root); }

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "Nothing to do. Name the files to push, or use --all / --list.\n  php data/deploy.php app/Domain.php public/index.php\n");
    exit(1);
}

if ($args === ['--list']) {
    foreach (file_list($root) as $f) { fwrite(STDOUT, "  $f\n"); }
    exit(0);
}

if ($args === ['--all']) {
    $files = file_list($root);
} else {
    $files = [];
    foreach ($args as $a) {
        $rel = str_replace('\\', '/', ltrim($a, '/\\'));
        if (!is_file($root . '/' . $rel)) {
            fwrite(STDERR, "Not a file in the project: $rel\n");
            exit(1);
        }
        if ($rel === 'config.php' || str_starts_with($rel, 'deploy/')) {
            fwrite(STDERR, "Refusing to upload $rel — the server's config.php is written by\ndata/setup.php option 5 and never overwritten by a routine deploy.\n");
            exit(1);
        }
        $files[] = $rel;
    }
}

// --- Lint before shipping --------------------------------------------------
foreach ($files as $rel) {
    if (!str_ends_with($rel, '.php')) { continue; }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "PARSE ERROR in $rel — nothing was uploaded:\n  " . implode("\n  ", $out) . "\n");
        exit(1);
    }
}

// --- Transfer helpers (same behaviour as setup.php's uploader) -------------
function put(string $local, string $url, string $user, string $pass, bool $insecure): string
{
    $fh = fopen($local, 'rb');
    if ($fh === false) { return 'could not read the local file'; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD                  => true,
        CURLOPT_INFILE                  => $fh,
        CURLOPT_INFILESIZE              => filesize($local) ?: 0,
        CURLOPT_USERPWD                 => $user . ':' . $pass,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
        CURLOPT_USE_SSL                 => CURLUSESSL_TRY,
        CURLOPT_SSL_VERIFYPEER          => !$insecure,
        CURLOPT_SSL_VERIFYHOST          => $insecure ? 0 : 2,
        CURLOPT_CONNECTTIMEOUT          => 15,
        CURLOPT_TIMEOUT                 => 180,
        CURLOPT_RETURNTRANSFER          => true,
    ]);
    $ok  = curl_exec($ch) !== false;
    $err = $ok ? '' : (curl_error($ch) ?: 'unknown transfer error');
    curl_close($ch);
    fclose($fh);
    return $err;
}

function get(string $url, string $user, string $pass, bool $insecure): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_TRY,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    return $out === false ? null : (string) $out;
}

function remote_url(string $base, string $dir, string $rel): string
{
    $path = ltrim(trim($dir, '/') . '/' . $rel, '/');
    return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

// --- Prove the connection before anything moves ----------------------------
fwrite(STDOUT, 'Checking ' . $ftp['host'] . ' — connection, login, folder… ');
$ch = curl_init($base . '/' . ltrim(trim($dir, '/') . '/', '/'));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_DIRLISTONLY    => true,
    CURLOPT_USERPWD        => $user . ':' . $pass,
    CURLOPT_USE_SSL        => CURLUSESSL_TRY,
    CURLOPT_SSL_VERIFYPEER => !$insecure,
    CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 30,
]);
$ok  = curl_exec($ch) !== false;
$err = curl_error($ch) ?: 'unknown error';
curl_close($ch);
if (!$ok) {
    fwrite(STDOUT, "FAILED.\n");
    fwrite(STDERR, "  $err\n  Nothing was uploaded. Check deploy/ftp.php against the control panel.\n");
    exit(1);
}
fwrite(STDOUT, "connected.\n");

// public/ is served AS the host's web root folder; everything else lands
// beside it, outside the web root.
$map = fn (string $rel): string =>
    ($webRoot !== 'public' && str_starts_with($rel, 'public/')) ? $webRoot . '/' . substr($rel, 7) : $rel;

$total = count($files);
$n     = 0;
foreach ($files as $rel) {
    $n++;
    $to = $map($rel);
    fwrite(STDOUT, sprintf("  %3d/%d  %-64s", $n, $total, substr("$rel → $to", 0, 64)));
    $url = remote_url($base, $dir, $to);

    $err = put($root . '/' . $rel, $url, $user, $pass, $insecure);
    if ($err !== '') { $err = put($root . '/' . $rel, $url, $user, $pass, $insecure); }  // one retry
    if ($err !== '') {
        fwrite(STDOUT, "FAILED\n");
        fwrite(STDERR, "  $err\n  Stopped here. Files before this one were uploaded and verified;\n  re-running is safe — files are simply replaced.\n");
        exit(1);
    }

    // Trust, then verify: read it straight back and compare bytes.
    $back = get($url, $user, $pass, $insecure);
    if ($back !== (string) file_get_contents($root . '/' . $rel)) {
        fwrite(STDOUT, "UPLOADED, BUT UNVERIFIED\n");
        fwrite(STDERR, "  The file read back from the server did not match what was sent.\n  Open the host's file manager and check it before going further.\n");
        exit(1);
    }
    fwrite(STDOUT, "ok\n");
}

fwrite(STDOUT, "\nDone — $total file" . ($total === 1 ? '' : 's') . " uploaded and read back byte-identical.\n");
