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
 * The wipe guard.
 *
 * Destroying data is gated on ONE file that the owner controls:
 * data/wipe-policy.php. Nothing else can authorise it.
 *
 * What this deliberately does NOT honour, because these are exactly the levers
 * reached for by someone — or something — working from stale context:
 *
 *   - command-line flags. There is no --force, and adding one would defeat the
 *     point. The check runs before arguments are parsed and takes none.
 *   - environment variables. WKR_ALLOW_WIPE and anything like it are ignored.
 *     An env var is a thing a script sets; the policy is a thing a person edits.
 *   - interactive confirmation. "Are you sure? y/n" is answered by whatever is
 *     driving the terminal, which may not be a human.
 *   - the database's own settings table. A wipe destroys it, so it cannot be
 *     the thing that authorises a wipe.
 *
 * It fails CLOSED. A missing policy file, an unreadable one, a malformed one, or
 * one that does not name the database being targeted all mean NO. The only way
 * to a yes is a policy file that exists, parses, says exactly true, and names
 * the database by name.
 *
 * Honest about its limits: this stops accidents, stale instructions and
 * confident misremembering. It is not a defence against something that edits
 * this file or wipe-policy.php first. Nothing living in the repo can be. If you
 * want a control that survives that, revoke DROP and TRUNCATE from the
 * application's MySQL user — that one is enforced outside this codebase.
 */
declare(strict_types=1);

final class WipeGuard
{
    public const POLICY_FILE = 'data/wipe-policy.php';
    public const LOG_FILE    = 'data/wipe-attempts.log';

    /**
     * Decide whether a wipe of this database is permitted.
     * Returns ['allowed' => bool, 'reason' => string].
     *
     * Every path that is not an explicit, database-matched `true` is a refusal.
     */
    public static function check(string $root, array $dbCfg): array
    {
        $path = $root . '/' . self::POLICY_FILE;

        if (!is_file($path)) {
            return self::no('there is no ' . self::POLICY_FILE . '. Absent policy means no.');
        }
        if (!is_readable($path)) {
            return self::no(self::POLICY_FILE . ' cannot be read.');
        }

        try {
            $policy = require $path;
        } catch (Throwable $e) {
            return self::no(self::POLICY_FILE . ' did not load: ' . $e->getMessage());
        }
        if (!is_array($policy)) {
            return self::no(self::POLICY_FILE . ' did not return an array.');
        }

        // Strict true only. 1, '1', 'true', 'yes' and null are all refusals —
        // a loose comparison here is how a typo becomes permission.
        if (($policy['allow_wipe'] ?? null) !== true) {
            return self::no('allow_wipe is not true in ' . self::POLICY_FILE
                . '. The owner has locked this database.');
        }

        $target = (string) ($dbCfg['database'] ?? $dbCfg['path'] ?? '');
        if ($target === '') {
            return self::no('the target database could not be determined from config.php.');
        }

        // Naming the database is what stops a permissive local policy from
        // travelling to production with the code and authorising it there.
        $named = $policy['databases'] ?? null;
        if (!is_array($named) || $named === []) {
            return self::no(self::POLICY_FILE . ' does not list any databases.');
        }
        if (!in_array($target, $named, true)) {
            return self::no("'" . $target . "' is not named in " . self::POLICY_FILE
                . '. Allowed: ' . implode(', ', array_map('strval', $named)) . '.');
        }

        // A second, independent gate. Production is not a local socket, and no
        // policy file is allowed to say otherwise.
        $host = strtolower((string) ($dbCfg['host'] ?? 'localhost'));
        if (($dbCfg['driver'] ?? '') === 'mysql'
            && !in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)) {
            return self::no("the target host is '" . $host
                . "', which is not local. Remote databases are never wipeable from here.");
        }

        return ['allowed' => true, 'reason' => 'permitted by ' . self::POLICY_FILE
            . ' for ' . $target];
    }

    /**
     * Enforce the policy, or stop the process. Called before anything
     * destructive happens and before any argument is read.
     */
    public static function requireAllowed(string $root, array $dbCfg): void
    {
        $verdict = self::check($root, $dbCfg);
        $target  = (string) ($dbCfg['database'] ?? $dbCfg['path'] ?? '?');
        self::record($root, $target, $verdict);

        if ($verdict['allowed']) {
            fwrite(STDOUT, "Wipe policy: allowed — " . $verdict['reason'] . "\n");
            return;
        }

        fwrite(STDERR,
            "\n  REFUSED — this database was not wiped.\n\n"
            . '  Target: ' . $target . "\n"
            . '  Reason: ' . $verdict['reason'] . "\n\n"
            . "  This is not overridable by a flag, an environment variable or an\n"
            . "  argument, and asking again will not change it. If you intend to\n"
            . "  allow it, edit " . self::POLICY_FILE . " yourself.\n\n");
        exit(1);
    }

    /** Both outcomes are recorded, to a file, because a wipe destroys tables. */
    private static function record(string $root, string $target, array $verdict): void
    {
        $line = sprintf("%s  %-8s %-24s %s%s",
            date('Y-m-d H:i:s'),
            $verdict['allowed'] ? 'ALLOWED' : 'REFUSED',
            $target,
            $verdict['reason'],
            PHP_EOL);
        @file_put_contents($root . '/' . self::LOG_FILE, $line, FILE_APPEND);
    }

    private static function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
