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
 * Front controller. Everything enters here.
 */

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';

/* THE WEB ROOT IS WHEREVER THIS FILE IS.
 *
 * Locally that folder is `public/`; on shared hosting the host serves a folder
 * it names itself — public_html, htdocs, www — and data/deploy.php renames
 * `public/` to match on upload. The application used to assume `public/`, so
 * asset() could not find its own files in production, emitted no `?v=` version,
 * and every CSS and JS change since launch was invisible to any warm cache.
 *
 * Nothing here needs configuring, because this file cannot be anywhere else:
 * being inside the served folder is what makes it the front controller. Deriving
 * the path beats declaring it — a declared one can be wrong. */
$cfg['app']['public_dir'] = __DIR__;

date_default_timezone_set($cfg['company']['tz'] ?? 'America/Los_Angeles');
error_reporting($cfg['app']['debug'] ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', $cfg['app']['debug'] ? '1' : '0');

foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain', 'Markdown'] as $f) {
    require $root . '/app/' . $f . '.php';
}
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) {
    require $f;
}

App::boot($cfg);
Db::boot($cfg['db']);

/* Security headers on every response, set centrally so no page can forget
 * them. Frames are denied outright — the signing and checkout pages are
 * exactly what a clickjacking frame would target. The CSP is a baseline:
 * same-origin everything, inline styles/scripts allowed because the views
 * use them, data: images for signature strips, and OpenStreetMap tile
 * images for the pin maps and OSM's embed page framed by map_embed() —
 * the only two external hosts the app touches. frame-src governs what WE
 * may frame; frame-ancestors 'none' still stops anyone framing US.
 * Tighten, never loosen. */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.tile.openstreetmap.org; "
    . "frame-src https://www.openstreetmap.org; frame-ancestors 'none'; "
    . "base-uri 'self'; form-action 'self'");

/* Whether the session cookie is HTTPS-only is decided from the request and
 * config.php — never from a database row. The settings table is an admin
 * text field (and a DB read before session_start(), which put the friendly
 * 503 below out of reach during an outage); a blank scheme there must not
 * silently strip Secure from every staff session. */
$secureCookie = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || str_starts_with(strtolower((string) ($cfg['install']['base_url'] ?? '')), 'https://');
if ($secureCookie) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secureCookie,
]);
session_name('wkr_admin');
session_start();

/**
 * First run.
 *
 * A missing table is recoverable — build the schema and seed. A database that
 * cannot be reached at all is not, and the operator needs to be told which one
 * it was trying to reach rather than shown a stack trace.
 */
try {
    Db::pdo();
} catch (Throwable $e) {
    http_response_code(503);
    /* This branch runs before any auth, for ANYONE who hits the site during
     * an outage — so the public sees a generic line, and the connection
     * detail (driver, user@host, database name — reconnaissance to a
     * stranger, directions to the operator) shows only with debug on,
     * which production never has. The operator's copy of the detail goes
     * to the error log either way (fixed 2026-08-27). */
    $db = $cfg['db'];
    error_log('DB unreachable: driver=' . $db['driver']
        . ($db['driver'] === 'mysql'
            ? ' server=' . $db['username'] . '@' . $db['host'] . ':' . $db['port'] . ' database=' . $db['database']
            : ' path=' . ($db['path'] ?? ''))
        . ' — ' . $e->getMessage());
    echo '<pre style="font:14px/1.7 ui-monospace,monospace;padding:32px;max-width:760px">';
    echo "This site is temporarily unavailable. Please try again shortly.\n";
    if ($cfg['app']['debug']) {
        echo "\nCannot reach the database.\n\n";
        echo 'Driver:   ' . e($db['driver']) . "\n";
        if ($db['driver'] === 'mysql') {
            echo 'Server:   ' . e($db['username'] . '@' . $db['host'] . ':' . $db['port']) . "\n";
            echo 'Database: ' . e($db['database']) . "\n\n";
            echo "Create it and import the schema, then reload:\n\n";
            echo "  mysql -u root -p -e \"CREATE DATABASE " . e($db['database']) . " CHARACTER SET utf8mb4\"\n";
            echo "  mysql -u " . e($db['username']) . " -p " . e($db['database']) . " < data/schema.mysql.sql\n";
            echo "  php data/install.php\n\n";
            echo "Credentials live in config.php, or in WKR_DB_NAME / WKR_DB_USER / WKR_DB_PASS.\n";
        } else {
            echo 'Path:     ' . e($db['path']) . "\n\nCheck that storage/ exists and is writable.\n";
        }
        echo "\n" . e($e->getMessage()) . "\n";
    }
    echo '</pre>';
    exit;
}

try {
    Db::val('SELECT 1 FROM users LIMIT 1');
} catch (Throwable) {
    // Empty database: the application installs itself on first request.
    // WHAT it seeds is config.php's decision ('install' => 'mode'), because
    // first boot happens on a public server too — and a public server must
    // come up clean, not with demo customers. See data/setup.php option 5.
    Db::migrate();
    require $root . '/data/seed.php';
    $mode = (string) (App::config('install', [])['mode'] ?? 'demo');
    seed_core();
    if ($mode === 'catalog' || $mode === 'demo') { seed_catalog(); }
    if ($mode === 'demo')                        { seed_staff(); seed_demo_data(); }
}

/**
 * Provider callbacks carry a cryptographic signature instead of a session
 * token, so the CSRF check — which exists to protect a browser session — does
 * not apply and would reject every one of them. Each webhook route verifies its
 * own signature before it looks at the body; see WebhookController.
 */
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = substr($uri, strlen(base_path())) ?: '/';

if (!str_starts_with($path, '/webhooks/') && $path !== '/webhook.php') {
    csrf_check();
}

$router = new Router();

/* ---- Provider callbacks (unauthenticated, signature-verified) -------- */
$router->post('/webhooks/telnyx', [WebhookController::class, 'telnyx']);
// Failover alias — same verified handler, under the name the Telnyx
// failover field was given. Signature checking is identical on both.
$router->post('/webhook.php',     [WebhookController::class, 'telnyx']);
$router->post('/webhooks/square', [WebhookController::class, 'square']);

/* ---- Customer checkout (unauthenticated, unguessable token) ---------- */
$router->get ('/pay/{token}', [CheckoutController::class, 'show']);
$router->post('/pay/{token}', [CheckoutController::class, 'pay']);

/* ---- Customer signing (unauthenticated, unguessable token) ----------- */
$router->get ('/sign/{token}', [SignController::class, 'show']);
$router->post('/sign/{token}', [SignController::class, 'sign']);

// The location page a stranded caller opens from a text. Public for the same
// reason as /sign: the token is the access control.
$router->get ('/locate/{token}', [LocateController::class, 'show']);
$router->post('/locate/{token}', [LocateController::class, 'capture']);

/* ---- Auth ---------------------------------------------------------- */
$router->get('/login',  [AuthController::class, 'form']);
$router->post('/login', [AuthController::class, 'submit']);
$router->post('/logout', [AuthController::class, 'logout']);

/* ---- Dashboard ----------------------------------------------------- */
$router->get('/',          [DashboardController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index']);

/* ---- Service requests ---------------------------------------------- */
$router->get ('/service-requests',              [ServiceRequestController::class, 'index']);
$router->get ('/service-requests/new',          [ServiceRequestController::class, 'create']);
$router->post('/service-requests',              [ServiceRequestController::class, 'store']);
$router->get ('/service-requests/{id}',         [ServiceRequestController::class, 'show']);
$router->post('/service-requests/{id}/edit',    [ServiceRequestController::class, 'update']);
$router->post('/service-requests/{id}/status',  [ServiceRequestController::class, 'status']);
$router->post('/service-requests/{id}/notes',   [ServiceRequestController::class, 'notes']);
$router->post('/service-requests/{id}/promote', [ServiceRequestController::class, 'promote']);
$router->post('/service-requests/{id}/sms',     [ServiceRequestController::class, 'sendSms']);
$router->post('/service-requests/{id}/locate-link', [ServiceRequestController::class, 'sendLocateLink']);

/* ---- Estimates ------------------------------------------------------ */
$router->get ('/estimates',                    [EstimateController::class, 'index']);
$router->get ('/estimates/{id}',               [EstimateController::class, 'show']);
$router->get ('/estimates/{id}/print',         [EstimateController::class, 'printable']);
$router->post('/estimates/{id}/lines',         [EstimateController::class, 'addLine']);
$router->post('/estimates/{id}/lines/{lineId}/delete', [EstimateController::class, 'delLine']);
$router->post('/estimates/{id}/vehicle',       [EstimateController::class, 'attachVehicle']);
$router->post('/estimates/{id}/po',            [EstimateController::class, 'setPo']);
$router->post('/estimates/{id}/send',          [EstimateController::class, 'send']);
$router->post('/estimates/{id}/locate-link',   [EstimateController::class, 'sendLocateLink']);
$router->post('/estimates/{id}/authorize',     [EstimateController::class, 'authorize']);
$router->post('/estimates/{id}/decline',       [EstimateController::class, 'decline']);
$router->post('/estimates/{id}/dispatch',      [EstimateController::class, 'dispatchWork']);
$router->post('/estimates/{id}/invoice',       [EstimateController::class, 'toInvoice']);

/* ---- Work orders ---------------------------------------------------- */
$router->get ('/work-orders',                  [WorkOrderController::class, 'index']);
$router->get ('/work-orders/{id}',             [WorkOrderController::class, 'show']);
$router->post('/work-orders/{id}/assign',      [WorkOrderController::class, 'assign']);
$router->post('/work-orders/{id}/status',      [WorkOrderController::class, 'status']);
$router->post('/work-orders/{id}/locate-link', [WorkOrderController::class, 'sendTechLocateLink']);
$router->post('/work-orders/{id}/eta-suggest', [WorkOrderController::class, 'etaSuggest']);
$router->post('/work-orders/{id}/lines',       [WorkOrderController::class, 'addLine']);
$router->post('/work-orders/{id}/lines/{lineId}/delete', [WorkOrderController::class, 'delLine']);
$router->post('/work-orders/{id}/po',          [WorkOrderController::class, 'setPo']);
$router->post('/work-orders/{id}/category',    [WorkOrderController::class, 'recategorise']);
$router->post('/work-orders/{id}/vin',         [WorkOrderController::class, 'captureVin']);
$router->post('/work-orders/{id}/sign',      [WorkOrderController::class, 'signInPerson']);
$router->post('/work-orders/{id}/sign-link', [WorkOrderController::class, 'sendSignLink']);
$router->post('/work-orders/{id}/complete',    [WorkOrderController::class, 'complete']);
$router->post('/work-orders/{id}/photo',       [WorkOrderController::class, 'photo']);

/* ---- Diagnostic reports (customer-facing findings, under the work order) - */
$router->get ('/work-orders/{id}/diagnostic',  [DiagnosticController::class, 'edit']);
$router->post('/work-orders/{id}/diagnostic',  [DiagnosticController::class, 'save']);
$router->post('/diagnostics/{id}/issue',       [DiagnosticController::class, 'issue']);
$router->post('/diagnostics/{id}/options',     [DiagnosticController::class, 'addOption']);
$router->get ('/diagnostics/{id}/print',       [DiagnosticController::class, 'printable']);

/* ---- Invoices -------------------------------------------------------- */
$router->get ('/invoices',                    [InvoiceController::class, 'index']);
$router->post('/invoices/create/{woId}',      [InvoiceController::class, 'createFromWo']);
$router->get ('/invoices/{id}',               [InvoiceController::class, 'show']);
$router->get ('/invoices/{id}/print',         [InvoiceController::class, 'printable']);
$router->post('/invoices/{id}/lines',         [InvoiceController::class, 'addLine']);
$router->post('/invoices/{id}/lines/{lineId}/delete', [InvoiceController::class, 'delLine']);
$router->post('/invoices/{id}/authorize',     [InvoiceController::class, 'authorizeVariance']);
$router->post('/invoices/{id}/po',            [InvoiceController::class, 'setPo']);
$router->post('/invoices/{id}/no-vehicle',    [InvoiceController::class, 'noVehicle']);
$router->post('/invoices/{id}/issue',         [InvoiceController::class, 'issue']);
$router->post('/invoices/{id}/void',          [InvoiceController::class, 'void']);

/* ---- Payments / receipts --------------------------------------------- */
$router->get ('/payments',                  [PaymentController::class, 'index']);
$router->post('/payments/take/{invoiceId}', [PaymentController::class, 'take']);
$router->post('/payments/link/{invoiceId}', [PaymentController::class, 'link']);
$router->post('/payments/{id}/overpayment', [PaymentController::class, 'resolveOverpayment']);
$router->get ('/receipts/{id}',             [PaymentController::class, 'receipt']);

/* ---- Records ---------------------------------------------------------- */
$router->get ('/customers',            [CustomerController::class, 'index']);
$router->get ('/customers/new',        [CustomerController::class, 'create']);
$router->get ('/customers/search',     [CustomerController::class, 'search']);
$router->post('/customers',            [CustomerController::class, 'store']);
$router->get ('/customers/{id}',       [CustomerController::class, 'show']);
$router->post('/customers/{id}',       [CustomerController::class, 'update']);

$router->get ('/vehicles',             [VehicleController::class, 'index']);
$router->get ('/vehicles/new',         [VehicleController::class, 'create']);
$router->get ('/vehicles/options',     [VehicleController::class, 'options']);   // before {id}: 'options' is not an id
$router->post('/vehicles',             [VehicleController::class, 'store']);
$router->get ('/vehicles/{id}',        [VehicleController::class, 'show']);

$router->get ('/catalog',              [CatalogController::class, 'index']);
$router->post('/catalog',              [CatalogController::class, 'store']);
$router->post('/catalog/suggest-sku',  [CatalogController::class, 'suggestSku']);
$router->post('/pricing/suggest',      [CatalogController::class, 'suggestPrice']);
$router->get ('/accounts',             [AccountController::class, 'index']);
$router->post('/accounts',             [AccountController::class, 'store']);
$router->post('/accounts/{id}/rename', [AccountController::class, 'rename']);
$router->post('/accounts/{id}/toggle', [AccountController::class, 'toggle']);
$router->post('/accounts/{id}/delete', [AccountController::class, 'destroy']);

$router->get ('/markup',               [MarkupController::class, 'index']);
$router->post('/markup',               [MarkupController::class, 'save']);
$router->post('/catalog/{id}/toggle',  [CatalogController::class, 'toggle']);
$router->post('/catalog/{id}/edit',    [CatalogController::class, 'update']);

/* ---- Square history (the imported mirror) ---------------------------- */
$router->get ('/square',                  [SquareController::class, 'index']);
$router->post('/square/classify-bulk',    [SquareController::class, 'bulk']);
$router->post('/square/{id}/classify',    [SquareController::class, 'classify']);
$router->get ('/square/{id}',             [SquareController::class, 'show']);

/* ---- The books (ledger reports) -------------------------------------- */
$router->get ('/books',                [BooksController::class, 'index']);

/* ---- Core deposits --------------------------------------------------- */
$router->get ('/cores',                [CoreController::class, 'index']);
$router->post('/cores/sweep',          [CoreController::class, 'sweep']);
$router->post('/cores/{id}/move',      [CoreController::class, 'move']);

$router->get ('/expenses',             [ExpenseController::class, 'index']);
$router->post('/expenses',             [ExpenseController::class, 'store']);

$router->get ('/messages',             [MessageController::class, 'index']);
/* No mark-sent route: a text either goes through the connected carrier or it
 * does not go. Personal-phone sends are outside this application's scope.
 * Consent, though, changes through ANY channel — a verbal "stop texting me"
 * binds exactly like a texted STOP, and this is where it is recorded. */
$router->post('/messages/consent',     [MessageController::class, 'recordConsent']);

$router->get ('/reports',              [ReportController::class, 'index']);

/* Point → nearest address, and address → point. Both directions already
 * existed on the Geocoder contract; these give the pin-drop map a way to ask.
 * The definition of "is this an address" stays server-side in Address. */
$router->post('/geo/reverse',          [GeoController::class, 'reverse']);
$router->post('/geo/forward',          [GeoController::class, 'forward']);

/* The user manual, rendered from docs/MANUAL.md at request time. Every role:
 * the manual documents refusals as much as features, and a technician who
 * knows why the system said no is a technician who stops trying to route
 * around it. /manual/print is the same source without the app chrome. */
$router->get ('/manual',               [ManualController::class, 'index']);
$router->get ('/manual/print',         [ManualController::class, 'printable']);

$router->get ('/telnyx-check',         [TelnyxCheckController::class, 'index']);
$router->get ('/api-log',              [ApiLogController::class, 'index']);
$router->get ('/settings',             [SettingsController::class, 'index']);
$router->post('/settings',             [SettingsController::class, 'save']);

/* Applying a schema change where there is no shell to run install.php from.
 * ADMIN only — it executes DDL. See SchemaController. */
$router->get ('/admin/schema',         [SchemaController::class, 'index']);
$router->post('/admin/schema',         [SchemaController::class, 'apply']);

$router->get ('/users',                [UserController::class, 'index']);
$router->post('/users',                [UserController::class, 'store']);
$router->post('/users/{id}/password',  [UserController::class, 'password']);
$router->post('/users/{id}/toggle',    [UserController::class, 'toggle']);

/* ---- Go -------------------------------------------------------------- */
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $ex) {
    if ($cfg['app']['debug']) { throw $ex; }
    error_log((string) $ex);
    error_page(500, '🔧', 'Something went wrong on our side',
        'The error has been recorded with the details, and nothing you entered '
        . 'was half-saved — writes here either complete or roll back whole. '
        . 'Go back and try once more; if it happens again, note the time and '
        . 'what you clicked so the log entry is easy to find.');
}
