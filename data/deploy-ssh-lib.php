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
 * Moving files over SSH. Shared by data/deploy-ssh.php and data/setup.php.
 *
 * Two callers need to do the same three things — connect, copy, prove what
 * landed — and a copy each would be two implementations of "is this file
 * correct", which is not a question that should have two answers.
 *
 * Everything takes an explicit connection array. Nothing here reads
 * ~/.ssh/config: a host alias is convenient on the machine that defined it and
 * meaningless everywhere else, so a tool relying on one works for whoever
 * wrote it and fails for every tenant.
 *
 * $conn = ['host','port','user','key']  — key is a PATH to a private key file.
 */
declare(strict_types=1);

/**
 * Options common to ssh and scp.
 *
 * BatchMode fails immediately rather than blocking on a passphrase prompt that
 * nothing is there to answer — a deploy that hangs forever is worse than one
 * that stops and says why. accept-new trusts a first-time host but still
 * refuses a host whose key has CHANGED, which is the case worth catching.
 */
function wkr_ssh_opts(array $c, bool $forScp = false): array
{
    return ['-i', (string) $c['key'],
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=20',
            '-o', 'StrictHostKeyChecking=accept-new',
            $forScp ? '-P' : '-p', (string) ($c['port'] ?: 22)];
}

/**
 * Run a command on the host.
 *
 * @return array{0:int,1:string} exit code, combined output
 */
function wkr_ssh_run(string $cmd, array $c): array
{
    $parts = array_merge(['ssh'], wkr_ssh_opts($c), [$c['user'] . '@' . $c['host'], $cmd]);
    $line  = implode(' ', array_map('escapeshellarg', $parts));
    $out   = [];
    exec($line . ' 2>&1', $out, $code);
    return [$code, trim(implode("\n", $out))];
}

/** Copy one local file to an absolute remote path. '' on success, else the error. */
function wkr_ssh_put(string $local, string $remote, array $c): string
{
    /* The parent directory will not exist on a first deploy. */
    wkr_ssh_run('mkdir -p ' . escapeshellarg(dirname($remote)), $c);

    $parts = array_merge(['scp'], wkr_ssh_opts($c, true),
        [$local, $c['user'] . '@' . $c['host'] . ':' . $remote]);
    $line  = implode(' ', array_map('escapeshellarg', $parts));
    $out   = [];
    exec($line . ' 2>&1', $out, $code);
    return $code === 0 ? '' : trim(implode(' ', $out));
}

/**
 * Did it land intact? Hash on the server, hash here, compare.
 *
 * This is the check FTP cannot make. Reading a file back down the same
 * connection that wrote it proves only that the connection is consistent with
 * itself; hashing on the far end proves the bytes on that disk are the bytes
 * that were meant. sha256sum on Linux, shasum on hosts that lack it.
 */
function wkr_ssh_verify(string $local, string $remote, array $c): bool
{
    [$code, $out] = wkr_ssh_run(
        'sha256sum ' . escapeshellarg($remote) . " 2>/dev/null | cut -d' ' -f1"
        . ' || shasum -a 256 ' . escapeshellarg($remote) . " 2>/dev/null | cut -d' ' -f1", $c);
    if ($code !== 0 || $out === '') { return false; }
    return strtolower(trim($out)) === strtolower((string) hash_file('sha256', $local));
}

/**
 * Can we reach the host, and is the application directory there?
 * Returns '' when everything is fine, otherwise a sentence naming the problem.
 */
function wkr_ssh_probe(array $c, string $dir): string
{
    if (trim((string) $c['key']) === '' || !is_file((string) $c['key'])) {
        return "The private key was not found at: {$c['key']}\n"
             . "That setting is a PATH to your key file, not the key itself.";
    }
    [$code, $out] = wkr_ssh_run('test -d ' . escapeshellarg($dir) . ' && echo ok', $c);
    if ($code !== 0) {
        $hint = '';
        if (stripos($out, 'permission denied') !== false) {
            $hint = "\n  The host refused the key. Check that its public half is in\n"
                  . "  ~/.ssh/authorized_keys on the server, and that the username is right.";
        } elseif (stripos($out, 'could not resolve') !== false) {
            $hint = "\n  That hostname does not resolve. Check it against the control panel.";
        } elseif (stripos($out, 'connection refused') !== false || stripos($out, 'timed out') !== false) {
            $hint = "\n  Nothing answered on that port. Many hosts use a non-standard SSH port\n"
                  . "  and require SSH to be switched on in the control panel first.";
        }
        return "Could not connect: $out$hint";
    }
    if (trim($out) !== 'ok') {
        return "Connected, but $dir does not exist on the server.\n"
             . "  That is the folder holding app/, data/ and the web root.";
    }
    return '';
}
