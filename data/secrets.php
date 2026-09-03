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
 * Credential lookup for the deploy tooling.
 *
 * WHY THIS FILE LIVES IN data/ AND NOT IN deploy/.
 *
 * It used to be deploy/secrets.php, and that was a mistake that only ever
 * showed itself on a machine that had never run before. deploy/ is excluded
 * from version control AND from every upload — correctly, because it holds
 * generated per-operator values. But this file holds no values. It is the
 * LOADER: three pure functions that go and find the values. Code every install
 * needs, stored in the one directory that never travels.
 *
 * The failure was silent and total. data/setup.php — the wizard whose entire
 * job is to create deploy/ — opened with `require_once deploy/secrets.php`
 * twenty-four lines before the `mkdir(deploy/)` that would have created the
 * folder. On a fresh checkout the wizard died on its first save, and the error
 * named a file the operator had never heard of and could not have supplied.
 *
 * So: the loader ships, the values do not. deploy/ still holds nothing but
 * generated addresses, and the credentials themselves still live outside the
 * project tree entirely. That separation is the point and it is unchanged —
 * what changed is that the mechanism reading them can now reach a machine that
 * has never been set up.
 *
 * Why data/ rather than app/: the only three callers are data/setup.php,
 * data/deploy.php and data/deploy-ssh.php, all CLI, none of them touched by a
 * web request. app/ is where the running application lives, and deploy tooling
 * is not part of the running application.
 *
 * ---------------------------------------------------------------------------
 *
 * The original reason this file exists at all: deploy/ftp.php used to carry the
 * FTP password as a literal `?:` fallback. The fallback defeated the point of
 * the getenv() in front of it — with no variable set, the literal WAS the
 * credential, sitting in plaintext inside the project folder. Nothing shipped
 * it (deploy/ is excluded from every upload list, twice), but "it never leaves
 * the machine" is a weaker guarantee than "it is not there".
 *
 * Lookup order, first non-empty wins:
 *
 *   1. The environment variable. Suits CI, or a one-off override on the
 *      command line, and takes precedence so a temporary value never has to be
 *      written down anywhere.
 *   2. The private store, OUTSIDE the project tree — %USERPROFILE%\.wkr\secrets.php
 *      by default, or wherever WKR_SECRETS_FILE points. Outside the tree is the
 *      point: no deploy, archive, or stray copy of this folder can carry it.
 *   3. Nothing. The caller reports which key is missing and stops. A missing
 *      credential must fail loudly — silently falling back to a live production
 *      password is the exact behaviour being removed here.
 *
 * The store is a plain PHP file returning an array:
 *
 *     <?php return ['ftp_user' => '…', 'ftp_pass' => '…'];
 *
 * This is not encryption, and does not pretend to be. On a single-user machine
 * an environment variable and a file are equally readable by anything running
 * as that user. What it buys is that credentials live in exactly one place,
 * outside anything that gets copied or deployed, and that their absence is an
 * error rather than a silent fallback.
 *
 * EVERY DEFINITION IS GUARDED. An install that predates the move still has a
 * deploy/secrets.php, and a generated deploy/ssh.php written before the move
 * still requires it. Both files may therefore be loaded in the same process,
 * and an unguarded redeclare is a fatal error — which would turn a tidying
 * change into exactly the kind of breakage it was meant to prevent.
 */

/** Absolute path to the private credential store. */
if (!function_exists('wkr_secret_store')) {
    function wkr_secret_store(): string
    {
        $override = getenv('WKR_SECRETS_FILE');
        if (is_string($override) && $override !== '') { return $override; }

        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        return $home !== ''
            ? rtrim(str_replace('\\', '/', $home), '/') . '/.wkr/secrets.php'
            : '';
    }
}

/**
 * Read and validate the store.
 *
 * A hand-edited store WILL eventually have a comma or a bracket in the wrong
 * place — that is the normal cost of a file people type into. A syntax error
 * inside a require()d file is an E_COMPILE_ERROR, which no try/catch can
 * intercept: PHP prints "Parse error" and the whole process dies. Deploying
 * would then fail with a message about a file that has nothing to do with
 * deploying, which is a miserable thing to debug at the wrong moment.
 *
 * token_get_all() with TOKEN_PARSE throws a catchable ParseError instead, so
 * the file is checked BEFORE it is included and a bad store degrades to "no
 * credentials found" — which the caller already reports clearly.
 *
 * @return array<string,string>
 */
if (!function_exists('wkr_secret_load')) {
    function wkr_secret_load(string $path): array
    {
        if ($path === '' || !is_file($path)) { return []; }

        $src = @file_get_contents($path);
        if ($src === false) { return []; }

        try {
            token_get_all($src, TOKEN_PARSE);
        } catch (ParseError $e) {
            fwrite(STDERR, "The credential store has a syntax error and was ignored:\n"
                         . '  ' . $path . "\n"
                         . '  ' . $e->getMessage() . "\n"
                         . "  Every entry belongs INSIDE the return [ … ]; and ends with a comma.\n");
            return [];
        }

        try {
            $loaded = require $path;
        } catch (Throwable) {
            return [];
        }

        return is_array($loaded) ? $loaded : [];
    }
}

/**
 * One credential, by environment variable name and store key.
 *
 * @param string $env Environment variable checked first, e.g. WKR_FTP_PASS
 * @param string $key Key in the private store, e.g. ftp_pass
 * @return string '' when neither source has it
 */
if (!function_exists('wkr_secret')) {
    function wkr_secret(string $env, string $key): string
    {
        $fromEnv = getenv($env);
        if (is_string($fromEnv) && $fromEnv !== '') { return $fromEnv; }

        static $store = null;
        if ($store === null) {
            $store = wkr_secret_load(wkr_secret_store());
        }

        $v = $store[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}

/**
 * What to tell an operator when a credential is missing. Names both places it
 * could come from, because "no password" is otherwise indistinguishable from
 * "wrong file".
 */
if (!function_exists('wkr_secret_missing')) {
    function wkr_secret_missing(string $env, string $key): string
    {
        $path = wkr_secret_store() ?: '(no home directory — set WKR_SECRETS_FILE)';
        return "Missing credential '$key'.\n"
             . "  Set the environment variable $env,\n"
             . "  or add '$key' => '…' to $path\n";
    }
}
