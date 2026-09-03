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

/* ------------------------------------------------------------------ */
/* Output + request helpers                                            */
/* ------------------------------------------------------------------ */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(mixed $v): string
{
    return '$' . number_format((float) $v, 2);
}

function base_path(): string
{
    static $bp = null;
    if ($bp !== null) { return $bp; }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $bp = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $bp = ($bp === '/' ? '' : $bp);
}

function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/**
 * A URL for a static asset, stamped with the file's modification time:
 * /assets/css/app.css?v=1753500000.
 *
 * The stamp changes the moment the file does, so a browser fetches the new
 * copy immediately after any edit — no hard refresh, no stale cache — while
 * still caching hard between changes. Falls back to a plain url() if the file
 * can't be found on disk.
 */
/**
 * A URL for a static asset, versioned by the file's own mtime so that shipping
 * a new one invalidates the old.
 *
 * The web root's NAME differs by host — `public/` locally, `public_html/` on
 * most shared hosting, sometimes `htdocs` or `www` — so this does not guess at
 * it. public/index.php records where it actually is at boot; see the note
 * there. This function only falls back for callers that never went through the
 * front controller, which is the CLI scripts.
 */
function asset(string $path): string
{
    static $pub = null;
    if ($pub === null) {
        $pub = App::config('app')['public_dir'] ?? null;
        if (!is_string($pub) || !is_dir($pub)) {
            $root = dirname(__DIR__);
            $pub  = is_dir($root . '/public') ? $root . '/public' : $root . '/public_html';
        }
    }
    $file = $pub . '/' . ltrim($path, '/');
    $ver  = is_file($file) ? filemtime($file) : false;

    /* A missing file must still produce a version. An unversioned URL is
     * indistinguishable from a correct one and caches forever — which is
     * exactly how this went unnoticed for the life of the project. */
    return url($path) . '?v=' . ($ver ?: (App::config('app')['version'] ?? '1'));
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function num(string $key, float $default = 0.0): float
{
    $v = input($key);
    return $v === null || $v === '' ? $default : (float) str_replace([',', '$'], '', (string) $v);
}

function intval_or_null(string $key): ?int
{
    $v = input($key);
    return ($v === null || $v === '') ? null : (int) $v;
}

/** Returns null when the field was absent or blank, so "no override" never means "$0". */
function price_or_null(string $key = 'unit_price'): ?float
{
    $v = input($key);
    return ($v === null || $v === '') ? null : (float) str_replace([',', '$'], '', (string) $v);
}

/**
 * The typed description of a miscellaneous charge, or null when the form did
 * not offer one. Only an is_misc catalog item does anything with it; Lines::add
 * ignores it for every real product, so a stray value can never relabel a SKU.
 */
function misc_name(string $key = 'line_name'): ?string
{
    $v = trim((string) input($key, ''));
    return $v === '' ? null : $v;
}

/** Read a hidden "price was manually overridden" flag: null when unset (infer). */
function overridden_flag(string $key = 'price_overridden'): ?bool
{
    $v = input($key);
    return ($v === null || $v === '') ? null : ($v === '1' || $v === 1 || $v === 'true' || $v === true);
}

function now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function fdate(?string $ts, string $fmt = 'M j, Y'): string
{
    if (!$ts) { return '—'; }
    try { return (new DateTimeImmutable($ts))->format($fmt); } catch (Throwable) { return '—'; }
}

function fdatetime(?string $ts): string
{
    return fdate($ts, 'M j, Y g:i A');
}

function ago(?string $ts): string
{
    if (!$ts) { return '—'; }
    try { $t = new DateTimeImmutable($ts); } catch (Throwable) { return '—'; }
    $d = time() - $t->getTimestamp();
    if ($d < 60)    { return 'just now'; }
    if ($d < 3600)  { return intdiv($d, 60) . 'm ago'; }
    if ($d < 86400) { return intdiv($d, 3600) . 'h ago'; }
    return intdiv($d, 86400) . 'd ago';
}

/* ------------------------------------------------------------------ */
/* Flash + CSRF                                                        */
/* ------------------------------------------------------------------ */

function flash(?string $msg = null, string $type = 'ok'): array
{
    if ($msg !== null) {
        $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* Old input — the flash pattern for form values. A validation failure calls
   keep_input($path) before redirect(); the form it lands back on reads each
   field through old('name', $default). The stash serves exactly one render,
   and only on the page it was kept for — anywhere else it is discarded. */

function _old_bag(): array
{
    static $bag = null;
    if ($bag === null) {
        $stash = $_SESSION['old_input'] ?? null;
        unset($_SESSION['old_input']);
        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $bag = (is_array($stash) && $stash['for'] !== '' && str_ends_with($uri, (string) $stash['for']))
            ? (array) $stash['data'] : [];
    }
    return $bag;
}

function keep_input(string $forPath): void
{
    $post = $_POST;
    unset($post['_csrf']);
    $_SESSION['old_input'] = ['for' => $forPath, 'data' => $post];
}

function old(string $key, mixed $default = ''): mixed
{
    $v = _old_bag()[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/** True when this render follows a kept submission — lets checkboxes tell
 *  "unchecked on resubmit" apart from "never submitted at all". */
function old_filled(): bool
{
    return _old_bag() !== [];
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * A full standalone error page, for failures that happen before or instead of
 * the normal layout — CSRF misses, uncaught errors. Deliberately self-
 * contained: no session, no views, no assets, because it renders exactly when
 * those may be the broken part. Every message on it says what happened AND
 * what to do next; a dead end with no way forward is hostile (2026-08-27).
 */
function error_page(int $status, string $icon, string $title, string $body): void
{
    http_response_code($status);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . '</title></head>'
        . '<body style="margin:0;display:grid;place-items:center;min-height:100vh;'
        . 'background:#0d1220;color:#dbe2f0;font:16px/1.65 system-ui,sans-serif">'
        . '<div style="max-width:30rem;padding:2.5rem;text-align:center">'
        . '<div style="font-size:2.2rem;margin-bottom:.75rem">' . e($icon) . '</div>'
        . '<h1 style="font-size:1.25rem;margin:0 0 .6rem">' . e($title) . '</h1>'
        . '<p style="margin:0 0 1.4rem;color:#9aa7c0">' . e($body) . '</p>'
        . '<button onclick="history.back()" style="cursor:pointer;border:0;border-radius:8px;'
        . 'padding:.6rem 1.2rem;background:#2f6bff;color:#fff;font:inherit">Go back</button>'
        . ' <a href="' . e(url('dashboard')) . '" style="margin-left:.75rem;color:#9aa7c0">Dashboard</a>'
        . '</div></body></html>';
    exit;
}

function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return; }
    if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        error_page(419, '⏳', 'Your session expired',
            'Nothing was submitted — this is a safety stop, not a lost record. '
            . 'The page had been open long enough that your sign-in lapsed, which '
            . 'happens to a tab left open overnight. Press Go back, reload that '
            . 'page so it is fresh, and submit again.');
    }
}

/* ------------------------------------------------------------------ */
/* Domain formatting + validation                                      */
/* ------------------------------------------------------------------ */

/** UI shows (xxx) xxx-xxxx; storage is always E.164. */
function phone_to_e164(?string $raw): ?string
{
    if (!$raw) { return null; }
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 10) { return '+1' . $d; }
    if (strlen($d) === 11 && str_starts_with($d, '1')) { return '+' . $d; }
    if (str_starts_with($raw, '+') && preg_match('/^\+[1-9]\d{1,14}$/', $raw)) { return $raw; }
    return null;
}

function phone_display(?string $e164): string
{
    if (!$e164) { return '—'; }
    if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)) {
        return "($m[1]) $m[2]-$m[3]";
    }
    return $e164;
}

/* ------------------------------------------------------------------ */
/* Customer identity                                                   */
/* ------------------------------------------------------------------ */

/** COMMERCIAL and FLEET accounts are business accounts; INDIVIDUAL is a person. */
function customer_is_business(array $c): bool
{
    return Rules::isBusinessType((string) ($c['customer_type'] ?? ''));
}

/**
 * Type labels. FLEET means the customer's business IS vehicles (couriers,
 * trucking, delivery); a commercial customer that merely owns several
 * vehicles is COMMERCIAL.
 */
function customer_types(): array
{
    return ['INDIVIDUAL' => 'Individual', 'COMMERCIAL' => 'Commercial business', 'FLEET' => 'Fleet operator'];
}

function customer_type_label(?string $t): string
{
    return customer_types()[strtoupper((string) $t)] ?? status_label((string) $t);
}

/** A distinct badge per kind, so a human and a business never read alike. */
function customer_badge(array $c): string
{
    $t    = strtoupper((string) ($c['customer_type'] ?? ''));
    $tone = ['INDIVIDUAL' => 'slate', 'COMMERCIAL' => 'info', 'FLEET' => 'accent'][$t] ?? 'slate';
    return '<span class="badge badge--' . $tone . '"><i></i>' . e(customer_type_label($t)) . '</span>';
}

/**
 * The one place a customer's display name is decided.
 *
 * Customer-facing (default): business → the company name; retail → the person.
 * Internal ($internal = true): business → "Company (Contact)" so dispatch can
 * see who to ask for. Rows that arrive from JOINs without customer_type fall
 * back to the historical company-first behaviour.
 */
function customer_name(array $c, bool $internal = false): string
{
    $person  = trim((string) ($c['first_name'] ?? '') . ' ' . (string) ($c['last_name'] ?? ''));
    $company = trim((string) ($c['company'] ?? ''));

    if (!array_key_exists('customer_type', $c)) {
        return $company !== '' ? $company : $person;
    }
    if (customer_is_business($c)) {
        if ($company === '') { return $person; }                       // shouldn't happen: validation requires it
        return ($internal && $person !== '') ? $company . ' (' . $person . ')' : $company;
    }
    return $person !== '' ? $person : $company;
}

/** Payment-terms options for selects. COD is the default for every account. */
function payment_terms_options(): array
{
    return [
        'DUE_ON_RECEIPT' => 'COD — due on receipt',
        'NET_15'         => 'Net 15',
        'NET_30'         => 'Net 30',
    ];
}

function payment_terms_label(?string $terms): string
{
    return payment_terms_options()[strtoupper(trim((string) $terms))] ?? 'COD — due on receipt';
}

/** ISO 3779 check-digit validation. Rejects I, O, Q. */
function vin_is_valid(?string $vin): bool
{
    $vin = strtoupper(trim((string) $vin));
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) { return false; }
    $tr = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,'J'=>1,'K'=>2,'L'=>3,'M'=>4,
           'N'=>5,'P'=>7,'R'=>9,'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9];
    $w  = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $c = $vin[$i];
        $v = ctype_digit($c) ? (int) $c : ($tr[$c] ?? 0);
        $sum += $v * $w[$i];
    }
    $chk = $sum % 11;
    $exp = $chk === 10 ? 'X' : (string) $chk;
    return $vin[8] === $exp;
}

/** Offline VIN decode: year + region/WMI hints. No paid service required. */
/* vin_decode() was deleted 2026-08-27: it was a byte-for-byte shadow of
 * Services\StructuralVinDecoder, year map and WMI map included, and the two
 * were used from different controllers — the same VIN decoding differently
 * depending on which screen created the vehicle. Decode through the driver:
 * Integrations::vin()->decode($vin). Do not re-add a local copy. */

/* ------------------------------------------------------------------ */
/* Labels                                                              */
/* ------------------------------------------------------------------ */

const STATUS_TONE = [
    'PENDING'      => 'slate',  'ACCEPTED'  => 'info',   'REJECTED'  => 'danger',
    'CANCELLED'    => 'slate',  'COMPLETED' => 'success','DRAFT'     => 'slate',
    'SENT'         => 'info',   'APPROVED'  => 'success','DECLINED'  => 'danger',
    'ASSIGNED'     => 'info',   'EN_ROUTE'  => 'warn',   'ON_SITE'   => 'accent',
    'IN_PROGRESS'  => 'accent',
    'NO_SHOW'      => 'danger', 'DISPATCHED'=> 'warn',   'INVOICED'  => 'success',
    'ISSUED'       => 'info',   'PAID'      => 'success','PARTIAL'   => 'warn',
    'VOID'         => 'slate',  'OVERDUE'   => 'danger',
    /* Message delivery. UNCONFIRMED is amber rather than red on purpose: the
     * carrier returned no receipt, which is not the same as a failure and
     * usually means the message arrived. Colouring it like a failure is what
     * makes somebody re-text a customer who already replied. */
    'QUEUED'       => 'slate',  'DELIVERED' => 'success', 'UNCONFIRMED' => 'warn',
    'FAILED'       => 'danger', 'RECEIVED'  => 'accent',  'BLOCKED'     => 'danger',
];

function status_label(string $s): string
{
    return ucwords(strtolower(str_replace('_', ' ', $s)));
}

function badge(string $status, string $extraClass = ''): string
{
    $tone = STATUS_TONE[$status] ?? 'slate';
    return '<span class="badge badge--' . $tone . ' ' . e($extraClass) . '"><i></i>' . e(status_label($status)) . '</span>';
}

/**
 * The service types on offer, in the order ServiceCategory rolls them.
 *
 * A TYPE IS NOW A SPECIFIC JOB, NOT A DEPARTMENT. These used to be broad —
 * "Tire Service", "Mobile Mechanic" — picked first and classified afterwards,
 * which put the dividing question at the wrong end of the conversation. The
 * category is chosen first and these are what it can roll, so each one has to
 * name a job precisely enough to price and dispatch on its own. Each carries a
 * dedicated catalog line item; see data/seed.php.
 *
 * Retired keys are NOT listed here — see retired_service_types() — because
 * this function is the offer list, and nothing may pick them again.
 */
function service_types(): array
{
    return [
        /* Roadside Services */
        'JUMPSTART'     => 'Jump Start',
        'LOCKOUT'       => 'Lockout',
        'FUEL'          => 'Fuel Delivery',
        'TIRE_SWAP'     => 'Spare Tire Swap — donut',
        'TIRE_PLUG'     => 'Tire Repair — plug (tire stays on the rim)',

        /* Advanced Tire Services */
        'TIRE_PATCH'    => 'Tire Repair — internal patch (demounts tire from rim)',
        'TIRE_DELIVERY' => 'Tire Delivery, Mount & Balance',

        /* Mobile Repair Services */
        'PARTS_INSTALL' => 'Parts Installation',
        'BATTERY_SWAP'  => 'Battery Replacement',
        'DIAGNOSTIC'    => 'Diagnostic',

        /* Towing Services */
        'WINCH_OUT'     => 'Winch Out',
        'FLATBED_TOW'   => 'Flatbed Tow',
        'STANDARD_TOW'  => 'Standard Tow',

        /* Other */
        'OTHER'         => 'Other',
    ];
}

/**
 * Service types that were once offered and no longer are. Kept so that rows
 * written before the split still render as words rather than as a raw key.
 * Nothing may select these; they exist for reading old records only.
 *
 * Each says "unspecified" rather than quietly adopting one of the specific
 * jobs that replaced it. Nobody went back and asked which one it was, so the
 * record should not claim to know.
 */
function retired_service_types(): array
{
    return [
        'TIRE'     => 'Tire Service (unspecified)',
        'BATTERY'  => 'Battery Service (unspecified)',
        'RECOVERY' => 'Winch / Recovery (unspecified)',
        'MECHANIC' => 'Mobile Mechanic (unspecified)',
    ];
}

/** The label for a service type, including the retired ones. */
function service_type_label(?string $k): string
{
    $k = (string) $k;
    if ($k === '') { return '—'; }
    return service_types()[$k] ?? retired_service_types()[$k] ?? $k;
}

/**
 * The service types a category can roll, as key => label, ready for a select.
 * The eligibility itself lives in ServiceCategory; this is only the view's
 * door onto it, the same way service_categories() is.
 */
function service_types_for(?string $category): array
{
    $out = [];
    foreach (ServiceCategory::serviceTypes($category) as $k) {
        $out[$k] = service_types()[$k] ?? $k;
    }
    return $out;
}

/** Every category's offer list, for the intake form's client-side filter. */
function service_types_by_category(): array
{
    $out = [];
    foreach (array_keys(service_categories()) as $c) {
        $out[$c] = service_types_for($c);
    }
    return $out;
}

/**
 * The operational categories, for form selects. The definitions and the
 * service-type defaults live in ServiceCategory; this is only the view's door
 * onto them, mirroring how service_types() is used.
 */
function service_categories(): array
{
    return ServiceCategory::ALL;
}

/**
 * The nearest physical address of a document, whichever column holds it.
 *
 * TRANSITIONAL, AND DELIBERATELY SO. estimates.service_address was retired in
 * favour of nearest_address, but rows written before that still carry the old
 * column, and production has no shell to run data/drop-service-address.php
 * from — the same reason schema changes go through /admin/schema. Reading both
 * means the switch can ship without blanking the address on every existing
 * estimate, and the backfill and drop stay a deliberate, separate act.
 *
 * The ?? is load-bearing: after the drop the key is gone, not empty.
 *
 * Delete this once data/drop-service-address.php has been run everywhere.
 */
function doc_address(array $doc): string
{
    $near = trim((string) ($doc['nearest_address'] ?? ''));
    if ($near !== '') { return $near; }
    return trim((string) ($doc['service_address'] ?? ''));
}

/**
 * A latitude or longitude from the request, or null when there isn't one.
 *
 * ZERO IS NOT "MISSING", AND THAT IS THE POINT. 0.0, 0.0 is a real location in
 * the Gulf of Guinea, so a bug that turns "no pin" into zero does not fail
 * loudly — it silently claims a position off the coast of Africa and passes
 * every null check downstream. This treats absent, empty and exactly-zero
 * alike, because for a roadside business in Oregon the third can only be the
 * first wearing a disguise.
 */
function coord_or_null(string $key): ?float
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? null;
    if ($raw === null) { return null; }
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) { return null; }
    $f = (float) $raw;
    return abs($f) < 0.000001 ? null : $f;
}

function us_states(): array
{
    return ['OR','WA','CA','ID','NV','AZ','MT','UT','AK','AL','AR','CO','CT','DC','DE','FL','GA','HI','IA','IL',
            'IN','KS','KY','LA','MA','MD','ME','MI','MN','MO','MS','NC','ND','NE','NH','NJ','NM','NY','OH','OK',
            'PA','RI','SC','SD','TN','TX','VA','VT','WI','WV','WY'];
}

/**
 * A state, however written, to its 2-letter code — geocoders return "Oregon",
 * the schema stores "OR". Unknown input returns '' rather than a guess.
 */
function us_state_abbrev(string $s): string
{
    $s = trim($s);
    if ($s === '') { return ''; }
    $up = strtoupper($s);
    if (strlen($up) === 2 && in_array($up, us_states(), true)) { return $up; }
    static $names = [
        'ALABAMA'=>'AL','ALASKA'=>'AK','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA','COLORADO'=>'CO',
        'CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC','FLORIDA'=>'FL','GEORGIA'=>'GA',
        'HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL','INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY',
        'LOUISIANA'=>'LA','MAINE'=>'ME','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN',
        'MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV','NEW HAMPSHIRE'=>'NH',
        'NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY','NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND',
        'OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR','PENNSYLVANIA'=>'PA','RHODE ISLAND'=>'RI',
        'SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT',
        'VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY',
    ];
    return $names[$up] ?? '';
}

/**
 * An embedded map centred on a point, with a marker — OpenStreetMap's own
 * embed page in an iframe. No API key, no JavaScript, no build step, and it
 * matches the geocoding default: the same map the address came from.
 */
function map_embed(float $lat, float $lng, int $height = 230): string
{
    $d    = 0.004;   // ≈ a 400 m box around the pin
    $bbox = sprintf('%.6F,%.6F,%.6F,%.6F', $lng - $d, $lat - $d, $lng + $d, $lat + $d);
    $src  = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox)
          . '&layer=mapnik&marker=' . rawurlencode(sprintf('%.6F,%.6F', $lat, $lng));
    return '<iframe src="' . e($src) . '" title="Customer location map"'
         . ' style="width:100%;height:' . (int) $height . 'px;border:1px solid var(--line);border-radius:8px;display:block"'
         . ' loading="lazy" referrerpolicy="no-referrer"></iframe>';
}
