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
 * Interactive setup. The front door for installing — asks which type of install
 * to perform, prepares a public-server deployment, or uninstalls. The
 * scriptable equivalents are data/install.php (non-destructive, first run) and
 * data/wipe.php (destructive reset).
 *
 *   php data/setup.php
 *
 * Options 1–4 operate on the LOCAL database config.php points at. Option 5
 * touches no database at all: it collects the details of a server that already
 * exists — the database created in the host's control panel, the public URL, a
 * real admin login — and writes deploy/config.php. Uploaded as config.php, that
 * file makes the application install itself on its first request, seeded the
 * way a public server must be: clean, with a real password, never demo data.
 *
 * Anything destructive asks you to type the database name back, so a wrong
 * target dies at the prompt, not in the data.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) { require $f; }
require $root . '/data/seed.php';
/* The credential loader, loaded once here rather than only inside the two save
 * functions that need it. save_to_store() also calls wkr_secret_store(), and it
 * was reachable only because save_ftp_settings happened to require the loader
 * first — a dependency on call order that nothing stated. */
require $root . '/data/secrets.php';

App::boot($cfg);
Db::boot($cfg['db']);

$driver = Db::driver();
$dbname = $driver === 'mysql' ? $cfg['db']['database'] : basename($cfg['db']['path']);
$where  = $driver === 'mysql'
    ? sprintf('%s@%s:%s/%s', $cfg['db']['username'], $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['database'])
    : $cfg['db']['path'];

// Everything wipe.php drops, plus markup_tiers: an uninstall leaves nothing.
$tables = ['journal_lines', 'journal_entries', 'square_sync_state', 'expense_rules', 'bank_transactions',
           'bank_sources', 'square_payout_entries', 'square_loans', 'square_customers', 'square_transactions',
           'core_records', 'location_requests', 'signature_requests', 'api_log', 'attachments', 'audit_log',
           'doc_lines', 'receipts', 'payments', 'payment_links', 'invoices', 'work_orders', 'estimates',
           'service_requests', 'messages', 'expenses', 'vehicles', 'customers', 'catalog_items', 'markup_tiers',
           'gl_account_tombstones', 'gl_accounts', 'settings', 'users', 'doc_counters'];

function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    if ($line === false) { fwrite(STDOUT, "\nAborted.\n"); exit(1); }
    return trim($line);
}

function ask_default(string $prompt, string $default): string
{
    $v = ask($default !== '' ? "$prompt [$default]: " : "$prompt: ");
    return $v !== '' ? $v : $default;
}

function ask_required(string $prompt, string $default = ''): string
{
    while (true) {
        $v = ask_default($prompt, $default);
        if ($v !== '') { return $v; }
        fwrite(STDOUT, "  This one is required.\n");
    }
}

/**
 * Like ask_required, but never echoes a saved secret back as a visible
 * default. Typing is still visible — this is a console wizard, and the
 * script says so — but a password already on disk is not reprinted just
 * because the user is re-running setup.
 */
function ask_secret(string $prompt, string $saved = ''): string
{
    while (true) {
        $v = ask($saved !== '' ? "$prompt [Enter to keep the saved one]: " : "$prompt: ");
        if ($v !== '') { return $v; }
        if ($saved !== '') { return $saved; }
        fwrite(STDOUT, "  This one is required.\n");
    }
}

function drop_all(array $tables, string $driver): void
{
    if ($driver === 'mysql') { Db::q('SET FOREIGN_KEY_CHECKS = 0'); }
    foreach ($tables as $t) { Db::q("DROP TABLE IF EXISTS $t"); }
    if ($driver === 'mysql') { Db::q('SET FOREIGN_KEY_CHECKS = 1'); }
}

/**
 * What a server needs, relative to the project root. Local-only and private
 * material stays home: the dev config (the generated one replaces it), tests,
 * source notes, backups, logs, launcher scripts, and version control.
 */
function deploy_file_list(string $root): array
{
    $skipDirs  = ['.git', '.github', 'deploy', 'tests', 'knowledge', 'storage', 'backups', 'node_modules', 'public/storage'];
    $skipFiles = ['config.php', 'config.example.php', 'start-wkr.bat', 'setup-wkr.bat', 'AGENTS.md', 'PROJECT_INSTRUCTIONS.md'];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
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

/** One file up, over FTPS or SFTP via curl. Returns '' on success, else the error text. */
function deploy_put(string $local, string $url, string $user, string $pass, bool $insecure): string
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

function deploy_url(string $base, string $dir, string $rel): string
{
    $path = ltrim(trim($dir, '/') . '/' . $rel, '/');
    return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Prove the connection, the login and the folder BEFORE seventy files try to
 * use them. Returns '' when the remote folder lists; otherwise a plain-words
 * explanation of what is wrong (the raw error is kept in parentheses).
 */
function deploy_probe(string $base, string $dir, string $user, string $pass, bool $insecure): string
{
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
    $ok   = curl_exec($ch) !== false;
    $err  = curl_error($ch) ?: 'unknown error';
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok) { return ''; }

    $host = (string) parse_url($base, PHP_URL_HOST);
    if (stripos($err, 'certificate') !== false)      { return $err; }   // caller offers the trust question
    if (stripos($err, 'resolve') !== false)          { return "No FTP server was found at \"$host\" — that name does not resolve. Check the FTP hostname against the control panel. ($err)"; }
    // Order matters: curl words a missing folder as "denied you to change to
    // the given directory", which must not read as a password problem.
    if ($code === 550 || stripos($err, 'directory') !== false) {
        return "The FTP server at $host accepted the login, but the folder was not found. ($err)";
    }
    if ($code === 530 || stripos($err, 'denied') !== false || stripos($err, 'login') !== false || stripos($err, 'authentication') !== false) {
        return "The FTP server at $host refused the username or password. ($err)";
    }
    if (stripos($err, 'timed out') !== false)        { return "The FTP server at $host did not answer — check the host and port. ($err)"; }
    return "FTP server at $host: $err";
}

/** Read a remote file back, or null. The proof that an upload really landed. */
function deploy_get(string $url, string $user, string $pass, bool $insecure): ?string
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

/**
 * Write a file that carries live credentials: owner-only, in an owner-only
 * directory, every time — not just when the directory is first created.
 * Everything under deploy/ goes through here, so "saved securely" is one
 * decision made once rather than a chmod remembered at each call site.
 *
 * chmod is a no-op on Windows; the directory is still created 0700 so a
 * project copied to a POSIX box does not arrive world-readable.
 */
function save_secret(string $root, string $rel, string $content): void
{
    $dir = dirname($root . '/' . $rel);
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    @chmod($dir, 0700);
    file_put_contents($root . '/' . $rel, $content);
    @chmod($root . '/' . $rel, 0600);
}

/**
 * What was proven last time, so a re-run does not ask for live credentials
 * again. deploy/config.php is the record of the database and the admin;
 * deploy/ftp.php the record of the upload account. Both are gitignored and
 * never uploaded. Returns [] when there is nothing saved yet.
 */
function saved_config(string $root): array
{
    $f = $root . '/deploy/config.php';
    if (!is_file($f)) { return []; }
    try { $c = require $f; } catch (Throwable) { return []; }
    return is_array($c) ? $c : [];
}

/**
 * Record what has actually been PROVEN, and when. Written only after a real
 * check passed, so a later run can distinguish credentials that once worked
 * from credentials that were merely typed in.
 */
function save_verified(string $root, array $marks): void
{
    $f     = $root . '/deploy/state.php';
    $state = is_file($f) ? (array) (require $f) : [];
    $state = array_merge($state, $marks);
    $out   = "<?php\n"
        . "/**\n"
        . " * White Knight Roadside — Admin\n"
        . " * What data/setup.php has PROVEN about this deployment, and when.\n"
        . " * Written only after a check actually passed. Never uploaded, never\n"
        . " * committed — deploy/ is excluded from every upload.\n"
        . " */\n\n"
        . "return " . export_array($state) . ";\n";
    save_secret($root, 'deploy/state.php', $out);
}

/**
 * Persist the PROVEN FTP details to deploy/ftp.php, so the next deploy —
 * this wizard or the non-interactive data/deploy.php — starts from working
 * values instead of a blank prompt. Written only after the probe has
 * accepted the connection, the login and the folder.
 */
/**
 * Persist the PROVEN SSH details to deploy/ssh.php, so data/deploy-ssh.php —
 * and the next run of this wizard — need none of it typed again.
 *
 * Same split as the FTP settings below, for the same reason: addresses are
 * written here, the login and the key path are NOT. They come from the
 * environment or the private store outside the project tree, so a copy of this
 * folder is useless to anyone who takes it.
 *
 * The key is referenced by PATH and never read, copied or written by this
 * program. Private key material does not belong in a project directory, and a
 * tool that put it there would be teaching every tenant a bad habit.
 */
function save_ssh_settings(string $root, array $v): void
{
    /* The loader ships with the project; deploy/ does not exist yet on a first
     * run and is created below. Requiring it from deploy/ was the bug that made
     * this wizard unrunnable on a fresh checkout. */
    require_once $root . '/data/secrets.php';

    $content = "<?php\n"
        . "/**\n"
        . " * White Knight Roadside — Admin\n"
        . " * SSH deploy settings, saved by data/setup.php on " . date('Y-m-d') . " after a\n"
        . " * successful connection. Used by data/deploy-ssh.php.\n"
        . " * NEVER uploaded, never committed — deploy/ is excluded from both.\n"
        . " *\n"
        . " * The username and the key PATH are not written here. They come from the\n"
        . " * environment or the private store outside the project tree — see\n"
        . " * data/secrets.php. Set WKR_SSH_USER and WKR_SSH_KEY, or add ssh_user\n"
        . " * and ssh_key to the store.\n"
        . " */\n\n"
        . "require_once __DIR__ . '/../data/secrets.php';\n\n"
        . "return [\n"
        . "    'host'     => wkr_secret('WKR_SSH_HOST', 'ssh_host') ?: " . var_export($v['host'], true) . ",\n"
        . "    'port'     => wkr_secret('WKR_SSH_PORT', 'ssh_port') ?: " . var_export($v['port'], true) . ",\n"
        . "    'user'     => wkr_secret('WKR_SSH_USER', 'ssh_user') ?: " . var_export($v['user'], true) . ",\n"
        . "    'key'      => wkr_secret('WKR_SSH_KEY',  'ssh_key')  ?: " . var_export($v['key'], true) . ",\n"
        . "    'dir'      => wkr_secret('WKR_SSH_DIR',  'ssh_dir')  ?: " . var_export($v['dir'], true) . ",\n"
        . "    'web_root' => wkr_secret('WKR_SSH_WEBROOT', 'ssh_web_root') ?: " . var_export($v['web_root'], true) . ",\n"
        . "];\n";

    if (!is_dir($root . '/deploy')) { mkdir($root . '/deploy', 0775, true); }
    file_put_contents($root . '/deploy/ssh.php', $content);
    @chmod($root . '/deploy/ssh.php', 0600);
}

function save_ftp_settings(string $root, array $v): void
{
    require_once $root . '/data/secrets.php';

    // Addresses stay in the project file; the login does not. Writing the
    // password here as a `?:` fallback is what this split exists to stop —
    // see data/secrets.php.
    $content = "<?php\n"
        . "/**\n"
        . " * White Knight Roadside — Admin\n"
        . " * FTP settings, saved by data/setup.php on " . date('Y-m-d') . " after a successful\n"
        . " * connection. Used by data/deploy.php and pre-filled by setup option 5.\n"
        . " * NEVER uploaded, never committed — deploy/ is excluded from every upload.\n"
        . " *\n"
        . " * The username and password are NOT here. They come from the environment\n"
        . " * or the private store outside the project tree — see data/secrets.php.\n"
        . " */\n\n"
        . "require_once __DIR__ . '/../data/secrets.php';\n\n"
        . "return [\n"
        . "    'protocol' => " . var_export($v['protocol'], true) . ",\n"
        . "    'host'     => wkr_secret('WKR_FTP_HOST', 'ftp_host') ?: " . var_export($v['host'], true) . ",\n"
        . "    'port'     => wkr_secret('WKR_FTP_PORT', 'ftp_port') ?: " . var_export($v['port'], true) . ",\n"
        . "    'user'     => wkr_secret('WKR_FTP_USER', 'ftp_user'),\n"
        . "    'pass'     => wkr_secret('WKR_FTP_PASS', 'ftp_pass'),\n"
        . "    'dir'      => wkr_secret('WKR_FTP_DIR', 'ftp_dir') ?: " . var_export($v['dir'], true) . ",\n"
        . "    'web_root' => wkr_secret('WKR_FTP_WEBROOT', 'ftp_web_root') ?: " . var_export($v['web_root'], true) . ",\n"
        . "    'insecure' => " . var_export((bool) $v['insecure'], true) . ",\n"
        . "];\n";
    save_secret($root, 'deploy/ftp.php', $content);

    save_to_store(['ftp_user' => (string) $v['user'], 'ftp_pass' => (string) $v['pass']]);
}

/**
 * Merge credentials into the private store outside the project tree, creating
 * it if absent. Existing keys are preserved, so writing FTP details does not
 * discard anything else kept there.
 *
 * Returns the path written, or '' when there is nowhere to write — in which
 * case the caller falls back to telling the operator to set the environment
 * variables by hand.
 */
function save_to_store(array $pairs): string
{
    $path = wkr_secret_store();
    if ($path === '') { return ''; }

    $existing = [];
    if (is_file($path)) {
        try {
            $loaded = require $path;
            if (is_array($loaded)) { $existing = $loaded; }
        } catch (Throwable) {
            $existing = [];
        }
    }

    foreach ($pairs as $k => $v) {
        if ($v !== '') { $existing[$k] = $v; }
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { return ''; }
    @chmod($dir, 0700);

    $body = "<?php\n"
        . "/**\n"
        . " * White Knight Roadside — private credential store.\n"
        . " * Written by data/setup.php. Lives OUTSIDE the project tree on purpose:\n"
        . " * nothing that copies, archives or deploys the project can carry it.\n"
        . " * Read through wkr_secret() — see data/secrets.php.\n"
        . " */\n\n"
        . "return [\n";
    foreach ($existing as $k => $v) {
        $body .= '    ' . var_export((string) $k, true) . ' => ' . var_export((string) $v, true) . ",\n";
    }
    $body .= "];\n";

    if (file_put_contents($path, $body) === false) { return ''; }
    @chmod($path, 0600);
    return $path;
}

/** Short-syntax array export, because var_export()'s array() sprawl has no place in a config file. */
function export_array(array $a, int $depth = 1): string
{
    $pad = str_repeat('    ', $depth);
    $out = "[\n";
    foreach ($a as $k => $v) {
        $key = var_export($k, true);
        $val = is_array($v) ? export_array($v, $depth + 1) : var_export($v, true);
        $out .= "$pad$key => $val,\n";
    }
    return $out . str_repeat('    ', $depth - 1) . ']';
}

// The local database is only needed for options 1–4. If it is down, say so
// and carry on — preparing a server deployment must not depend on the local stack.
$localDbOk = true;
$hasData   = false;
try {
    Db::pdo();
    try { $hasData = (int) Db::val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable) { /* fresh */ }
} catch (Throwable $e) {
    $localDbOk = false;
}

fwrite(STDOUT,
    "\nWhite Knight Roadside — Setup\n" .
    'Target: ' . ($localDbOk ? "$driver $where" . ($hasData ? '  (contains data)' : '  (empty)')
                             : "$driver $where  (NOT REACHABLE — options 1-4 unavailable)") . "\n\n" .
    "  [1] Clean Install       admin login, settings, markup tiers — no business data\n" .
    "  [2] Clean with Catalog  … plus the example Products & Services price book\n" .
    "  [3] Full Demo           … plus example staff, customers and jobs (dev only)\n" .
    "  [4] Uninstall           drop every application table — removes ALL data\n" .
    "  [5] Public server       collect server details → write deploy/config.php\n" .
    "  [q] Quit\n\n");

$choice = strtolower(ask('Choose [1/2/3/4/5/q]: '));
if (!in_array($choice, ['1', '2', '3', '4', '5'], true)) { fwrite(STDOUT, "Nothing done.\n"); exit; }

/* ======================================================================
 * [5] Public server — collect what an already-created server needs.
 *
 * Nothing here touches any database. The database is created in the host's
 * control panel beforehand; this flow records how to reach it, what the site
 * is called from outside, and who the admin is — and bakes all of it into
 * deploy/config.php. Uploaded as config.php, the application's own
 * first-boot installer does the rest on the server itself, which is also
 * why no remote connection is required from this machine.
 * ==================================================================== */
if ($choice === '5') {
    // Everything proven by an earlier run, so live credentials are typed once
    // and only once. Empty on a first run, in which case every prompt below
    // falls back to a generic default — never to whoever built this.
    $prev  = saved_config($root);
    $pDb   = (array) ($prev['db'] ?? []);
    $pIns  = (array) ($prev['install'] ?? []);
    $pAdm  = (array) ($pIns['admin'] ?? []);
    $state = is_file($root . '/deploy/state.php') ? (array) (require $root . '/deploy/state.php') : [];

    fwrite(STDOUT,
        "\nPublic server deployment\n" .
        "Have these ready from your hosting control panel: the MySQL database\n" .
        "name, its user and password, and the site's public URL.\n\n");

    if ($prev !== []) {
        $when = (string) ($state['db_verified_at'] ?? '');
        $how  = (string) ($state['db_verified_by'] ?? '');
        fwrite(STDOUT,
            "Found saved details from a previous run in deploy/config.php — every\n" .
            "prompt below is pre-filled with them, so press Enter to keep a value.\n" .
            ($when !== ''
                ? "The database credentials were last PROVEN on $when"
                  . ($how !== '' ? " — $how" : '') . ".\n\n"
                : "Nothing has been proven about them yet.\n\n"));
    }

    // --- The site --------------------------------------------------------
    $url = '';
    while ($url === '') {
        $url = rtrim(ask_required('Public URL of the admin site (e.g. https://admin.example.com)',
                                  (string) ($pIns['base_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s/]+#', $url)) {
            fwrite(STDOUT, "  That does not look like a URL — include the https:// part.\n");
            $url = '';
        } elseif (str_starts_with($url, 'http://')) {
            fwrite(STDOUT, "  Plain http will break the customer location page — phone browsers only\n" .
                           "  allow GPS over https. Most hosts issue a Let's Encrypt certificate for free.\n");
            if (strtolower(ask('  Continue with http anyway? [y/N]: ')) !== 'y') { $url = ''; }
        }
    }

    // --- The database (already created in the host's control panel) -------
    fwrite(STDOUT, "\nDatabase — as created in the hosting control panel. The application\n" .
                   "connects from the same server it runs on, so the host is usually localhost.\n\n");
    // The database server, not the FTP server — the classic mix-up, caught
    // here because the wrong answer produces a site that cannot boot.
    $dbHost = '';
    $dbPort = '';
    while ($dbHost === '') {
        $dbHost = ask_default('DB host', (string) ($pDb['host'] ?? '') ?: 'localhost');
        $dbPort = ask_default('DB port', (string) ($pDb['port'] ?? '') ?: '3306');
        if (stripos($dbHost, 'ftp') !== false || $dbPort === '21' || $dbPort === '22') {
            fwrite(STDOUT, "  That looks like the FTP server. This asks for the DATABASE server,\n" .
                           "  which the site reaches from inside the hosting account — almost always\n" .
                           "  localhost, port 3306. FTP details come later, for the upload.\n");
            if (strtolower(ask('  Use these values anyway? [y/N]: ')) !== 'y') { $dbHost = ''; $dbPort = ''; }
        }
    }
    $dbName = ask_required('DB name', (string) ($pDb['database'] ?? ''));
    $dbUser = ask_required('DB user', (string) ($pDb['username'] ?? ''));
    $dbPass = ask_secret('DB password  (typed visibly — run this somewhere private)',
                         (string) ($pDb['password'] ?? ''));

    // --- What first boot should seed --------------------------------------
    fwrite(STDOUT, "\nWhat should the server start with?\n" .
                   "  [1] Clean — settings, markup tiers, your admin login. Build the catalog at /catalog.\n" .
                   "  [2] Clean with the example Products & Services price book\n" .
                   "  (The demo dataset is deliberately not offered for a public server.)\n\n");
    $modeDefault = ((string) ($pIns['mode'] ?? '') === 'catalog') ? '2' : '1';
    $mode = ask_default('Choose [1/2]', $modeDefault) === '2' ? 'catalog' : 'clean';

    // --- The admin login ---------------------------------------------------
    // Defaults come from what was saved, never from a name baked into this
    // script: whoever installs this is not whoever wrote it.
    fwrite(STDOUT, "\nAdmin login for the public server. This is a REAL admin — the throwaway\n" .
                   "setup login (" . Rules::SETUP_EMAIL . " / admin123) is never created there.\n\n");

    $adHash  = (string) ($pAdm['password_hash'] ?? '');
    $adFirst = ask_default('Admin first name', (string) ($pAdm['first_name'] ?? ''));
    $adLast  = ask_default('Admin last name',  (string) ($pAdm['last_name'] ?? ''));
    $adEmail = '';
    while ($adEmail === '') {
        $adEmail = ask_default('Admin email (the login)', (string) ($pAdm['email'] ?? ''));
        if (!filter_var($adEmail, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDOUT, "  Not a valid email address.\n");
            $adEmail = '';
        }
    }

    // A saved hash is a working production password. Re-running setup to
    // change an FTP detail must not silently reset the login the operator is
    // already using, so keeping it is the default and is offered explicitly.
    $keepPw = $adHash !== '' && strtolower($pAdm['email'] ?? '') === strtolower($adEmail)
        && strtolower(ask("Keep the existing password for $adEmail? [Y/n]: ")) !== 'n';

    if (!$keepPw) {
        $adPass = '';
        while ($adPass === '') {
            $adPass = ask_required('Admin password (10+ characters; typed visibly)');
            if (strlen($adPass) < 10) {
                fwrite(STDOUT, "  Too short — this guards a public login page. 10 characters minimum.\n");
                $adPass = '';
            } elseif (stripos($adPass, 'admin123') !== false) {
                fwrite(STDOUT, "  Not that one.\n");
                $adPass = '';
            } elseif (ask('Type it again to confirm: ') !== $adPass) {
                fwrite(STDOUT, "  They did not match — start over.\n");
                $adPass = '';
            }
        }
        $adHash = password_hash($adPass, PASSWORD_DEFAULT);
    }

    // --- Reachability probe -------------------------------------------------
    // Tried every time rather than on request: it costs six seconds and it is
    // the only check that can catch a wrong password before the upload. A
    // refusal proves nothing either way — shared hosts keep MySQL closed to
    // the internet unless an address is allow-listed, and the application
    // connects from the server, not from here. So a failure is reported and
    // the run continues; only a SUCCESS is recorded as proof.
    $dbProven = '';
    fwrite(STDOUT, "\n  Checking the database server at $dbHost from this machine… ");
    try {
        new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass,
                [PDO::ATTR_TIMEOUT => 6, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbProven = 'direct connection from the setup machine';
        fwrite(STDOUT, "connected.\n  These credentials are confirmed working.\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, "not reachable.\n  " . $e->getMessage() . "\n" .
                       "  That is NORMAL on shared hosting — the database server only accepts\n" .
                       "  connections from inside the hosting account, unless your IP is\n" .
                       "  allow-listed (often under a \"Remote MySQL\" panel). The web server\n" .
                       "  will reach it fine from inside, and the site's first request at the\n" .
                       "  end of this run is the real proof. Double-check the database name,\n" .
                       "  user and password against the control panel and continue.\n");
    }

    // --- Summary and write -------------------------------------------------
    fwrite(STDOUT,
        "\nAbout to write deploy/config.php with:\n" .
        "    Site URL:     $url\n" .
        "    Database:     $dbUser@$dbHost:$dbPort/$dbName\n" .
        "    First boot:   " . ($mode === 'catalog' ? 'clean + example catalog' : 'clean') . "\n" .
        "    Admin login:  $adEmail  ($adFirst $adLast)" . ($keepPw ? '  (password unchanged)' : '  (new password)') . "\n" .
        "    Database:     " . ($dbProven !== '' ? 'credentials PROVEN just now' : 'not provable from here — the site\'s first request will settle it') . "\n" .
        "    Debug:        off\n\n");
    if (strtolower(ask('Write it? [Y/n]: ')) === 'n') { fwrite(STDOUT, "Nothing written.\n"); exit; }

    $company = $cfg['company'];
    $company['email'] = $company['email'] ?: $adEmail;

    $content = "<?php\n"
        . "/**\n"
        . " * White Knight Roadside — Admin\n"
        . " * PRODUCTION configuration — generated by data/setup.php on " . date('Y-m-d') . ".\n"
        . " *\n"
        . " * Contains live database credentials and the admin password hash.\n"
        . " * Upload to the server AS config.php, replacing the development one.\n"
        . " * Keep it out of any repository and off any shared drive.\n"
        . " *\n"
        . " * First request against the empty database installs the schema and seeds\n"
        . " * it per the 'install' block below — then log in and change nothing by\n"
        . " * hand in the database, ever.\n"
        . " */\n\n"
        . "return [\n"
        . "    'db' => [\n"
        . "        'driver'   => getenv('WKR_DB_DRIVER') ?: 'mysql',\n"
        . "        'host'     => getenv('WKR_DB_HOST') ?: " . var_export($dbHost, true) . ",\n"
        . "        'port'     => getenv('WKR_DB_PORT') ?: " . var_export($dbPort, true) . ",\n"
        . "        'database' => getenv('WKR_DB_NAME') ?: " . var_export($dbName, true) . ",\n"
        . "        'username' => getenv('WKR_DB_USER') ?: " . var_export($dbUser, true) . ",\n"
        . "        'password' => getenv('WKR_DB_PASS') ?: " . var_export($dbPass, true) . ",\n"
        . "        'path'     => __DIR__ . '/storage/wkr.sqlite',\n"
        . "    ],\n\n"
        . "    'company' => " . export_array($company) . ",\n\n"
        . "    'rules' => " . export_array($cfg['rules']) . ",\n\n"
        . "    'integrations' => " . export_array($cfg['integrations']) . ",\n\n"
        . "    'app' => [\n"
        . "        'debug'   => false,\n"
        . "        'name'    => " . var_export($cfg['app']['name'], true) . ",\n"
        . "        'version' => " . var_export($cfg['app']['version'], true) . ",\n"
        . "    ],\n\n"
        . "    'install' => [\n"
        . "        'mode'     => " . var_export($mode, true) . ",\n"
        . "        'base_url' => " . var_export($url, true) . ",\n"
        . "        'admin'    => [\n"
        . "            'first_name'    => " . var_export($adFirst, true) . ",\n"
        . "            'last_name'     => " . var_export($adLast, true) . ",\n"
        . "            'email'         => " . var_export($adEmail, true) . ",\n"
        . "            'password_hash' => " . var_export($adHash, true) . ",\n"
        . "        ],\n"
        . "    ],\n"
        . "];\n";

    save_secret($root, 'deploy/config.php', $content);
    if ($dbProven !== '') {
        save_verified($root, ['db_verified_at' => date('Y-m-d H:i'), 'db_verified_by' => $dbProven]);
    }

    fwrite(STDOUT, "\nWrote deploy/config.php — owner-only, inside an owner-only deploy/ folder,\n" .
                   "gitignored and never uploaded by a routine deploy. Re-run this option and\n" .
                   "every answer above comes back pre-filled.\n");

    /* --- Upload the application ----------------------------------------
     * Everything the server needs, sent over FTPS or SFTP with the account
     * from the hosting control panel. The generated config goes up AS
     * config.php, so the server never sees the development one. Re-running
     * is always safe: files are simply replaced.
     * ------------------------------------------------------------------ */
    $files    = deploy_file_list($root);
    $uploaded = false;

    $ans = strtolower(ask("\nUpload the application to the server now? [Y/n/list]: "));
    if ($ans === 'list') {
        foreach ($files as $f) { fwrite(STDOUT, "    $f\n"); }
        fwrite(STDOUT, '    (plus deploy/config.php uploaded as config.php — ' . (count($files) + 1) . " files)\n");
        $ans = strtolower(ask("\nUpload now? [Y/n]: "));
    }

    /* --- How should the files travel? ----------------------------------
     * Asked rather than assumed. FTP works on every shared host and needs
     * nothing switched on; SSH is better where it exists but has to be
     * enabled in the control panel first and needs a key pair already set
     * up, which is real work an operator may not have done yet. Guessing
     * either way strands somebody, so the question gets asked once and the
     * answer is remembered.
     * ------------------------------------------------------------------ */
    $method = 'ftp';
    if ($ans !== 'n') {
        $sshSaved = is_file($root . '/deploy/ssh.php') ? (array) (require $root . '/deploy/ssh.php') : [];
        $default  = $sshSaved !== [] ? '2' : '1';
        fwrite(STDOUT,
            "\nHow should the files reach the server?\n\n" .
            "  [1] FTP / FTPS   Works on every shared host. Username and password.\n" .
            "  [2] SSH / SCP    Needs SSH switched on in the control panel and a key\n" .
            "                   pair already set up. Authenticates with the key — no\n" .
            "                   password stored — and every file is checked against a\n" .
            "                   checksum taken on the server after it lands.\n\n" .
            ($sshSaved !== [] ? "  SSH details from a previous run were found.\n\n" : ''));
        $method = ask_default('Choose [1/2]', $default) === '2' ? 'ssh' : 'ftp';
    }

    /* --- SSH upload ---------------------------------------------------- */
    if ($ans !== 'n' && $method === 'ssh') {
        require_once $root . '/data/deploy-ssh-lib.php';

        fwrite(STDOUT,
            "\nSSH access — switched on in the hosting control panel, with your public\n" .
            "key added there. The username is usually not your panel login, and many\n" .
            "hosts use a port other than 22.\n\n");

        $sHost = (string) ($sshSaved['host'] ?? '');
        $sPort = (string) ($sshSaved['port'] ?? '22');
        $sUser = (string) ($sshSaved['user'] ?? '');
        $sKey  = (string) ($sshSaved['key']  ?? '');
        $sDir  = (string) ($sshSaved['dir']  ?? '');
        $sRoot = (string) ($sshSaved['web_root'] ?? 'public_html');

        while (true) {
            $sHost = ask_required('SSH host', $sHost !== '' ? $sHost : (string) parse_url($url, PHP_URL_HOST));
            $sPort = ask_default('Port', $sPort !== '' ? $sPort : '22');
            $sUser = ask_required('SSH username', $sUser);
            $sKey  = ask_required("Path to your PRIVATE key file on THIS machine\n"
                . '  (the key itself is never copied into the project)', $sKey);
            $sDir  = ask_required("Application folder on the server — the directory that will\n"
                . '  hold app/, data/ and the web root', $sDir);
            $sRoot = trim(ask_default("Web root folder the host serves inside that folder\n"
                . '  (public_html on most shared hosts)', $sRoot !== '' ? $sRoot : 'public_html'), '/');

            $conn = ['host' => $sHost, 'port' => $sPort, 'user' => $sUser, 'key' => $sKey];
            fwrite(STDOUT, "\n  Checking {$sUser}@{$sHost}:{$sPort} … ");
            $why = wkr_ssh_probe($conn, rtrim($sDir, '/'));
            if ($why === '') { fwrite(STDOUT, "connected.\n"); break; }

            fwrite(STDOUT, "FAILED\n\n  $why\n");
            /* A folder that does not exist yet is normal on a first deploy. */
            if (str_contains($why, 'does not exist')
                && strtolower(ask("\n  Create it during the upload and continue? [Y/n]: ")) !== 'n') {
                wkr_ssh_run('mkdir -p ' . escapeshellarg(rtrim($sDir, '/')), $conn);
                fwrite(STDOUT, "  Created.\n");
                break;
            }
            if (strtolower(ask("\n  Correct the details and try again? [Y/n]: ")) === 'n') {
                fwrite(STDOUT, "\nNothing was uploaded. deploy/config.php is kept — run option 5\nagain once the SSH details are straightened out.\n");
                exit(1);
            }
            fwrite(STDOUT, "\n");
        }

        save_ssh_settings($root, ['host' => $sHost, 'port' => $sPort, 'user' => $sUser,
                                  'key' => $sKey, 'dir' => rtrim($sDir, '/'), 'web_root' => $sRoot]);
        fwrite(STDOUT, "\n  Saved to deploy/ssh.php — routine deploys can now use\n  data/deploy-ssh.php without retyping any of this.\n\n");

        $sDir   = rtrim($sDir, '/');
        $queue  = deploy_order($files);
        $total  = count($queue) + 1;
        $n      = 0;
        $failed = '';

        foreach ($queue as $rel) {
            $n++;
            fwrite(STDOUT, sprintf("\r  %3d/%d  %-56s", $n, $total, substr($rel, 0, 56)));
            $remote = $sDir . '/' . deploy_remote_path($rel, $sRoot);
            $err = wkr_ssh_put($root . '/' . $rel, $remote, $conn);
            if ($err !== '') { $err = wkr_ssh_put($root . '/' . $rel, $remote, $conn); }
            if ($err !== '') { $failed = "$rel — $err"; break; }
            if (!wkr_ssh_verify($root . '/' . $rel, $remote, $conn)) {
                $failed = "$rel — landed, but the server's checksum does not match";
                break;
            }
        }

        /* The generated config goes up AS config.php, so the server never sees
         * the development one. Last, because it is what makes the site live. */
        if ($failed === '') {
            $n++;
            fwrite(STDOUT, sprintf("\r  %3d/%d  %-56s", $n, $total, 'config.php (production)'));
            $remote = $sDir . '/config.php';
            $err = wkr_ssh_put($root . '/deploy/config.php', $remote, $conn);
            if ($err !== '') { $err = wkr_ssh_put($root . '/deploy/config.php', $remote, $conn); }
            if ($err !== '')                                              { $failed = "config.php — $err"; }
            elseif (!wkr_ssh_verify($root . '/deploy/config.php', $remote, $conn)) { $failed = 'config.php — checksum mismatch'; }
        }

        if ($failed !== '') {
            fwrite(STDOUT, "\n\nSSH UPLOAD to $sHost FAILED at $failed\n"
                . "(It was retried once before giving up.) Nothing more was sent.\n");
            exit(1);
        }

        fwrite(STDOUT, "\r  " . str_repeat(' ', 70) . "\r");
        fwrite(STDOUT, "  $total files uploaded, each verified by SHA-256 taken on the server.\n");
        $uploaded = true;
    }

    if ($ans !== 'n' && $method === 'ftp') {
        fwrite(STDOUT, "\nFTP account — created in the hosting control panel (Site → FTP accounts\nor similar). The username is usually not your panel login.\n\n");

        // Start from the last PROVEN details, if any — saved to deploy/ftp.php
        // after every successful connection. A repeat deploy is then just
        // Enter, Enter, Enter.
        $saved = is_file($root . '/deploy/ftp.php') ? (array) (require $root . '/deploy/ftp.php') : [];
        $proto = ($saved['protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';
        $fHost = (string) ($saved['host'] ?? '');
        $fPort = (string) ($saved['port'] ?? '');
        $fUser = (string) ($saved['user'] ?? '');
        $fPass = (string) ($saved['pass'] ?? '');
        $fDir  = (string) ($saved['dir']  ?? '/');
        $insecure = (bool) ($saved['insecure'] ?? false);

        // Nothing uploads until the connection, the login and the folder have
        // all been PROVEN. A wrong answer re-asks with the previous values as
        // defaults — a typo costs one prompt, never the whole run, and never
        // a silent pile of files in the wrong place.
        while (true) {
            $proto = ask_default('Protocol — [1] FTPS (usual, port 21) or [2] SFTP (port 22)', $proto === 'sftp' ? '2' : '1') === '2' ? 'sftp' : 'ftp';
            $fHost = ask_required('FTP host', $fHost !== '' ? $fHost : (string) parse_url($url, PHP_URL_HOST));
            $fPort = ask_default('Port', $fPort !== '' ? $fPort : ($proto === 'sftp' ? '22' : '21'));
            $fUser = ask_required('FTP username', $fUser);
            $fPass = ask_required('FTP password  (typed visibly)', $fPass);

            // A folder on the server, not a web address — saying so is not
            // enough, because a URL is the most natural wrong answer there is.
            $dirOk = false;
            while (!$dirOk) {
                $fDir = ask_default("SITE folder on the server — a directory path as the file manager\n  shows it, NOT a web address. If the FTP account is anchored to the\n  site's folder already, just accept /", $fDir);
                if (str_contains($fDir, '://')) {
                    fwrite(STDOUT, "  That is a web address. This asks WHERE ON THE SERVER the files go —\n" .
                                   "  a folder path, like / . Check the FTP account's directory in the\n" .
                                   "  hosting control panel.\n");
                    $fDir = '/';
                } else { $dirOk = true; }
            }

            $base = $proto . '://' . $fHost . ':' . $fPort;
            fwrite(STDOUT, "\n  Checking the FTP server at $fHost — connection, login, folder… ");
            $probe = deploy_probe($base, $fDir, $fUser, $fPass, $insecure);

            // Shared-host FTP very often presents a certificate that does not
            // match the hostname. That is the FileZilla "trust this cert?"
            // moment — ask the same question once, out loud, and go on.
            if ($probe !== '' && stripos($probe, 'certificate') !== false && !$insecure) {
                fwrite(STDOUT, "\n  The FTP server's TLS certificate could not be verified:\n    $probe\n" .
                               "  This is common on shared hosting — the FTP server's certificate rarely\n  matches the domain.\n");
                if (strtolower(ask('  Continue with the unverified certificate? [y/N]: ')) === 'y') {
                    $insecure = true;
                    $probe = deploy_probe($base, $fDir, $fUser, $fPass, true);
                }
            }

            if ($probe === '') { fwrite(STDOUT, "connected — login accepted, folder found.\n"); break; }

            fwrite(STDOUT, "FAILED.\n  $probe\n");

            // A folder that does not exist yet is the normal case on a first
            // deploy — offer to create it rather than treating it as a typo.
            if (stripos($probe, 'folder was not found') !== false
                && strtolower(ask('  Create that folder during the upload and continue? [Y/n]: ')) !== 'n') {
                fwrite(STDOUT, "  It will be created as the upload runs.\n");
                break;
            }

            if (strtolower(ask("\n  Correct the details and try again? [Y/n]: ")) === 'n') {
                fwrite(STDOUT, "\nNothing was uploaded. deploy/config.php is kept — run option 5 again\nonce the FTP details are straightened out.\n");
                exit(1);
            }
            fwrite(STDOUT, "\n");
        }

        // Where the host actually serves from. Shared hosts pin each site's
        // web root to a folder they name — public_html, htdocs, www — with no
        // way to repoint it at the project's public/. So public/ is uploaded
        // AS that folder, and everything else lands beside it: outside the
        // web root entirely, which protects it better than any .htaccess.
        $webRoot = trim(ask_default("Web root folder the host serves inside that site folder\n  (public_html on most shared hosts; answer public if the host really\n  does serve the project's own public/ folder)", (string) ($saved['web_root'] ?? 'public_html')), '/');

        // The details are proven — keep them. data/deploy.php pushes routine
        // changes with these, and re-running this wizard pre-fills from them.
        save_ftp_settings($root, [
            'protocol' => $proto, 'host' => $fHost, 'port' => $fPort,
            'user' => $fUser, 'pass' => $fPass, 'dir' => $fDir,
            'web_root' => $webRoot, 'insecure' => $insecure,
        ]);
        fwrite(STDOUT, "\n  Saved to deploy/ftp.php — routine deploys can now use data/deploy.php\n  without retyping any of this.\n\n");
        $map = fn (string $rel): string =>
            ($webRoot !== 'public' && str_starts_with($rel, 'public/')) ? $webRoot . '/' . substr($rel, 7) : $rel;

        $total  = count($files) + 1;
        $n      = 0;
        $failed = '';

        foreach ($files as $rel) {
            $n++;
            fwrite(STDOUT, sprintf("\r  %3d/%d  %-60s", $n, $total, substr($rel, 0, 60)));
            $err = deploy_put($root . '/' . $rel, deploy_url($base, $fDir, $map($rel)), $fUser, $fPass, $insecure);
            if ($err !== '') {
                // One automatic retry — transient hiccups are routine on shared FTP.
                $err = deploy_put($root . '/' . $rel, deploy_url($base, $fDir, $map($rel)), $fUser, $fPass, $insecure);
            }
            if ($err !== '') { $failed = "$rel — $err"; break; }
        }

        if ($failed === '') {
            $n++;
            fwrite(STDOUT, sprintf("\r  %3d/%d  %-60s", $n, $total, 'config.php (production)'));
            $err = deploy_put($root . '/deploy/config.php', deploy_url($base, $fDir, 'config.php'), $fUser, $fPass, $insecure);
            if ($err !== '') {
                $err = deploy_put($root . '/deploy/config.php', deploy_url($base, $fDir, 'config.php'), $fUser, $fPass, $insecure);
            }
            if ($err !== '') { $failed = "config.php — $err"; }
        }

        if ($failed !== '') {
            fwrite(STDOUT, "\n\nFTP UPLOAD to $fHost FAILED at $failed\n" .
                "(It was retried once before giving up.) Nothing more was sent. Check the\n" .
                "message above, fix what it names, and run option 5 again — re-uploading\n" .
                "is safe, files are simply replaced. deploy/config.php is kept, so the\n" .
                "earlier answers do not need retyping.\n");
            exit(1);
        }

        // Trust, then verify: read the uploaded config straight back off the
        // server and compare bytes. If this passes, the files are truly there.
        $back     = deploy_get(deploy_url($base, $fDir, 'config.php'), $fUser, $fPass, $insecure);
        $verified = $back !== null && $back === (string) file_get_contents($root . '/deploy/config.php');

        $uploaded = true;
        fwrite(STDOUT, "\n\nUploaded $total files to $fDir on $fHost" .
            ($webRoot !== 'public' ? " — public/ went in as $webRoot/, everything else beside it, outside the web root." : '.') . "\n" .
            ($verified
                ? "Verified: config.php was read back from the FTP server byte-identical.\n"
                : "WARNING: config.php could not be read back from the FTP server to verify\n" .
                  "the upload. The transfers reported success, but open the file manager\n" .
                  "and confirm the files are where they should be before going further.\n"));

        // First boot: one request against the empty database installs
        // everything. Doing it now means setup ends with a working site.
        if (strtolower(ask("\nOpen $url now to finish the installation? [Y/n]: ")) !== 'n') {
            $ch = curl_init($url . '/login');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                                    CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90]);
            $body = (string) curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($code === 200 && stripos($body, 'Sign in') !== false) {
                // The application reached MySQL from inside the hosting
                // account and installed itself. Nothing else proves the
                // database credentials as well as this does, so record it.
                save_verified($root, [
                    'db_verified_at'   => date('Y-m-d H:i'),
                    'db_verified_by'   => 'the live site booted and installed itself',
                    'site_verified_at' => date('Y-m-d H:i'),
                    'site_url'         => $url,
                ]);
                fwrite(STDOUT, "\nThe site is up. That first request created every table and seeded the\n" .
                    "settings — log in as $adEmail with " . ($keepPw ? "your existing password.\n" : "the password you chose.\n") .
                    "\nThe database credentials are now PROVEN: the application connected to\n" .
                    "MySQL from inside the hosting account. Saved to deploy/ — the next run\n" .
                    "of this wizard, and data/deploy.php, start from these working values.\n");
            } elseif ($code === 0) {
                fwrite(STDOUT, "\nNo web server answered at $url ($cerr).\n" .
                    "The files are on the FTP server — this is about the web address: the\n" .
                    "domain may not exist in the hosting panel yet, or DNS is still settling.\n" .
                    "The moment the URL loads, the site installs itself.\n");
            } else {
                fwrite(STDOUT, "\nThe web server at $url answered HTTP $code — not the login page yet.\n" .
                    "The files are on the server; this is about what the web server chooses\n" .
                    "to show. The usual causes, in order: the host's under-construction /\n" .
                    "coming-soon page is still switched on; a leftover placeholder file\n" .
                    "(Default.html or similar) sits in the web root — delete it in the file\n" .
                    "manager; or the application cannot reach the database server, which the\n" .
                    "page will say plainly. Fix and reload — nothing needs re-uploading.\n");
            }
        }
    }

    if (!$uploaded) {
        fwrite(STDOUT,
            "\nTo go live by hand:\n" .
            "  1. Upload the project to the site's folder: the CONTENTS of public/ go\n" .
            "     into the folder the host serves (usually public_html); app/, data/,\n" .
            "     docs/ and the rest sit beside that folder, outside the web root.\n" .
            "  2. Upload deploy/config.php as config.php, beside app/.\n" .
            "  3. Open $url — the first request creates every table and seeds it.\n");
    }
    fwrite(STDOUT,
        "\nAfter first sign-in as $adEmail: add dispatcher/technician logins under\n" .
        "Admin → Users. Settings already carries the base URL; add messaging /\n" .
        "payment / geocoding keys there when ready — SMS holds in the outbox until\n" .
        "then. The admin password itself was never stored; only its bcrypt hash is\n" .
        "in the config.\n\n" .
        "Saved for next time, all owner-only inside deploy/ and excluded from every\n" .
        "upload and from version control:\n" .
        "    deploy/config.php   database credentials, site URL, admin login\n" .
        "    deploy/ftp.php      the upload account\n" .
        "    deploy/state.php    what has been proven, and when\n" .
        "Keep that folder off any shared drive.\n");
    exit;
}

if (!$localDbOk) {
    fwrite(STDERR, "Could not connect to the LOCAL database server ($where) — options 1-4 work\non the development database and need it running.\n");
    exit(1);
}

// Every option below destroys whatever is in the database.
if ($hasData) {
    fwrite(STDOUT,
        "\nThis will DESTROY all data in the database below. Take a backup first (see README).\n\n" .
        "    Database name:  $dbname\n\n");
    if (ask('Type the database name exactly as shown above to continue: ') !== $dbname) {
        fwrite(STDOUT, "Name did not match. Nothing done.\n");
        exit(1);
    }
}

drop_all($tables, $driver);

if ($choice === '4') {
    fwrite(STDOUT, "\nUninstalled — all application tables dropped.\n" .
                   "The database itself and config.php were left in place.\n");
    exit;
}

Db::migrate();
seed_core();
if ($choice === '2' || $choice === '3') { seed_catalog(); }
if ($choice === '3')                    { seed_staff(); seed_demo_data(); }

$mode = ['1' => 'Clean Install', '2' => 'Clean with Catalog', '3' => 'Full Demo'][$choice];
fwrite(STDOUT, sprintf(
    "\n%s complete. users=%d  catalog=%d  customers=%d\n\n%s%s",
    $mode,
    (int) Db::val('SELECT COUNT(*) FROM users'),
    (int) Db::val('SELECT COUNT(*) FROM catalog_items'),
    (int) Db::val('SELECT COUNT(*) FROM customers'),
    (int) Db::val('SELECT COUNT(*) FROM users WHERE is_setup = 1') > 0
        ? "TEMPORARY setup admin (deactivates itself once you create a real admin):\n"
          . '  ' . Rules::SETUP_EMAIL . " / admin123\n"
        : "Admin login: " . Db::val("SELECT email FROM users WHERE role = 'ADMIN' ORDER BY id LIMIT 1")
          . "\n  (configured in config.php — sign in with the password you set there)\n",
    $choice === '3'
        ? "  dispatch@wkrllc.com / dispatch123\n  tech@wkrllc.com / tech123\n"
        : "Add dispatcher and technician logins under Admin.\n"
));
