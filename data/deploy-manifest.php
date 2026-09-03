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
 * WHAT SHIPS, AND WHAT NEVER DOES. One list, read by every deployer.
 *
 * There is more than one way to push this application at a host — FTP for
 * hosts that offer nothing else, SSH where it is available — and each of them
 * needs to know which files are safe to send. That question has exactly one
 * right answer, so it is written down once here rather than copied into each
 * tool, where the copies would drift and the drift would be silent until
 * something private landed on a public server.
 *
 * The exclusions are not tidiness. Each one is load-bearing:
 *
 *   deploy/            credentials and the generated production config
 *   config.php         the DEVELOPMENT config — wrong database, debug on
 *   data/wipe-policy.php  per-install, and catastrophic if it travels: a
 *                      policy naming the local database would, on a server
 *                      whose database happens to share that name, authorise
 *                      wiping production. On the server the database host IS
 *                      localhost, so the remote-host guard does not catch it
 *                      either. Its ABSENCE is the protection.
 *   tests/, knowledge/ not needed at runtime; smaller surface is safer
 *   storage/, backups/ live data belonging to whichever install made it
 */
declare(strict_types=1);

/** Directories whose entire contents stay behind. */
function deploy_skip_dirs(): array
{
    return ['.git', '.github', 'deploy', 'tests', 'knowledge', 'storage',
            'backups', 'node_modules', 'public/storage'];
}

/** Individual files that stay behind, whatever directory they sit in. */
function deploy_skip_files(): array
{
    return ['config.php', 'config.example.php', 'start-wkr.bat', 'setup-wkr.bat',
            'AGENTS.md', 'PROJECT_INSTRUCTIONS.md',
            'data/wipe-policy.php', 'data/wipe-attempts.log'];
}

/**
 * Every file that may be uploaded, relative to the project root, sorted.
 *
 * @return string[]
 */
function deploy_file_list(string $root): array
{
    $skipDirs  = deploy_skip_dirs();
    $skipFiles = deploy_skip_files();
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        foreach ($skipDirs as $d) { if (str_starts_with($rel, $d . '/')) { continue 2; } }
        if (in_array($rel, $skipFiles, true) || str_ends_with($rel, '.log')) { continue; }
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

/**
 * True when a named file must never be uploaded. Named files given on the
 * command line bypass the directory walk, so they are checked separately —
 * otherwise `deploy config.php` would cheerfully overwrite production's.
 */
function deploy_is_forbidden(string $rel): bool
{
    $rel = str_replace('\\', '/', ltrim($rel, '/'));
    foreach (deploy_skip_dirs() as $d) { if (str_starts_with($rel, $d . '/')) { return true; } }
    return in_array($rel, deploy_skip_files(), true);
}

/**
 * Where a project-relative path lands on the host.
 *
 * Shared hosts pin each site's web root to a folder they name — public_html,
 * htdocs, www — with no way to repoint it at the project's own public/. So
 * public/ is uploaded AS that folder and everything else lands beside it,
 * outside the web root entirely, which protects it better than any .htaccess.
 */
function deploy_remote_path(string $rel, string $webRoot): string
{
    $webRoot = trim($webRoot, '/');
    return ($webRoot !== 'public' && str_starts_with($rel, 'public/'))
        ? $webRoot . '/' . substr($rel, 7)
        : $rel;
}

/**
 * Order files so the front controller lands LAST.
 *
 * public/index.php names every route and every controller class. If it arrives
 * before the class it references, the site answers 500 for the seconds or
 * minutes in between — not a broken page, the whole site. Shipping it last
 * makes the window the other way round: new code sits unused until the router
 * that reaches it appears.
 *
 * @param string[] $files
 * @return string[]
 */
function deploy_order(array $files): array
{
    $last = [];
    $rest = [];
    foreach ($files as $f) {
        if ($f === 'public/index.php') { $last[] = $f; } else { $rest[] = $f; }
    }
    return array_merge($rest, $last);
}
