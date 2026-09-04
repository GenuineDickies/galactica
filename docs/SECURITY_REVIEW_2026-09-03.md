# Security review — 2026-09-03

OWASP Top 10:2025 / ASVS 5.0 / LLM Top 10 pass over `app/`, `public/`, `data/`,
`tests/`. Static read of every controller, `Domain.php`, `Services.php`, `Http.php`,
all views and `app.js`; then every fix below verified against a live local server
(`php -S` on :8089, curl) and the 15 pure suites (727 assertions, all pass, lint clean
on all changed files). Follows `CODE_REVIEW_2026-08-27.md`; its fixes were confirmed
present before this pass started.

## 1. Summary

The disciplines the last review praised still hold: parameterized SQL everywhere,
central CSRF with `hash_equals`, verified-before-parse webhooks, CSPRNG tokens with
uniform 404s, no mass assignment, no dangerous sinks, secrets never in logs or the
repo, `data/` unreachable from the web, wipe guard fails closed. A scripted per-echo
pass over all 48 views found no unescaped user data outside the two paths below.

What was wrong clustered in **authorization ordering** — the app's "every controller
method calls Auth" rule is decentralized, and two work-order writes plus two office
routes were reachable by the wrong principal — and **one raw-HTML sink** (the flash)
that keeps collecting new unescaped call sites.

Fixed in this pass: H1, H2, M1–M4, M6–M8, L1–L6, L8. Open: M5 (login throttling),
L7, L9–L12, plus the design items in §3.

## 2. Findings

### High

**H1 — Unauthenticated write on `/work-orders/{id}/category`. FIXED.**
`WorkOrderController::recategorise` (and `captureVin`) called `find()` then
`if (Auth::is('TECHNICIAN') && …) Auth::requireRole(…)`. A guest's role is `GUEST`, so
the guard never fired and the method fell through to `Db::update` + two `Audit::log`
writes with attacker text. CSRF was no obstacle: `/login` mints a token for anyone.
Verified: guest POST with a valid `_csrf` → 200 and a row written before the fix; 302 to
`/login` after. Fix: `authorized()` now calls `Auth::require()` first, and both methods
use it. `WorkOrderController::find` and `InvoiceController::find` also require login
before the lookup (see L4).

**H2 — Stored XSS from `option_label` into an ADMIN session. FIXED.**
Source `DiagnosticController::addOption` (DISPATCH-reachable) stores `option_label`
raw → `Rules::optionAuthorizeGate` (`Domain.php`) interpolates it into `reason` →
`EstimateController::authorize` flashes the reason → `layouts/app.php` emits
`$f['msg']` raw. CSP allows `'unsafe-inline'`, so an `onerror` handler runs in whichever
admin next hits that gate — DISPATCH→ADMIN escalation through the app that holds
`/users` and `/admin/schema`. Fix: `e()` at `Domain.php` (gate reason),
`DiagnosticController` (self-flash) and the SKU flash in `CatalogController`.
The sink itself stays raw because six call sites embed `<strong>`/links; see L7.

### Medium

**M1 — Photo `label` → XSS in `<img src>` and path traversal. FIXED.**
`WorkOrderController::photo` took `label` as free text into the filename;
`wo_show.php` rendered `url($p['stored_path'])` unescaped in `src=`. A technician on
their own job could plant `"` + `ONERROR=…` (entity-encoded, so `strtoupper` was no
defence) for every dispatcher who opens the WO, or `../../public/…` to write into the
served folder (no `.php` — the extension comes from `mime_content_type` — but a
web-served file with no CSP/nosniff). `move_uploaded_file`'s return was also
unchecked, so the row was inserted even when the write failed. Fix: label allowlisted
to `^[A-Z0-9_]{1,24}$` (else `SITE`), `basename()` on the target, return checked,
`e()` on the `src`.

**M2 — Any technician could attach a vehicle to any estimate. FIXED.**
`/estimates/{id}/vehicle` had `Auth::require()` only, while every sibling route is
office-only; the loose gate existed so `captureVin` could reuse the method. A tech
could create a `vehicles` row under another customer and clear
`Rules::invoiceVehicleGate` on an invoice they have no part in. Fix: direct hits are
`requireRole('ADMIN','DISPATCH')`; the `$back !== null` path (already authorized by
`captureVin`) keeps `require()`.

**M3 — Technicians could search the whole customer base. FIXED.**
`/customers/search` (name/phone/city, 10 rows, 2-char query, `ORDER BY id DESC`) was
`Auth::require()` only. No technician screen consumes it. Now ADMIN/DISPATCH.

**M4 — Unbounded `tip_amount` on the public `/pay/{token}`. FIXED.**
`CheckoutController::pay` clamped the invoice amount to the balance but accepted any
float tip, posting Dr cash / Cr 4300 for money that never existed. Fix: only the four
offered amounts (0/5/10/20) are accepted; anything else is no tip.

**M5 — No login throttling. OPEN (needs a table).**
Bcrypt + uniform failure message are in place, but `/login` is unmetered. Fix is a
`login_attempts` (email, ip, failed_at) table via `Schema::define()` with a
per-email+IP backoff in `AuthController::submit`, or a host-level rule. Failed and
successful logins and logouts are **now audited** (`login`, `login:failed`, `logout`
with the address and IP, never the password — verified a `login:failed` row lands),
so a stuffing run is at least visible.

**M6 — Session cookie `Secure` flag came from an admin-editable setting. FIXED.**
`$secureCookie` was `str_starts_with(Http::baseUrl(), 'https://')`; `baseUrl()`
prefers the `app_base_url` settings row, which ships blank and is a free-text field.
A blank or `http://` value silently stripped `Secure` from every staff session. It was
also a DB read before `session_start()`, which made the friendly 503 page unreachable
during an outage (uncaught `PDOException` at that line instead). Fix: decided from
`$_SERVER['HTTPS']`, `X-Forwarded-Proto`, or `config.php` `install.base_url`; HSTS
(`max-age=31536000; includeSubDomains`) sent when secure; `session.use_strict_mode`
on. Verified the 503 page now renders with the DB down.

**M7 — Superseded signature links kept serving the document. FIXED (partly).**
`SignController::resolve` checked only `doc_type`; a `VOID` link (superseded by a
newer request) still rendered the customer's name, address, plate and priced lines
forever. Fix: `VOID` → 404. **Still open:** no `expires_at` on `signature_requests`
(the 2026-08-27 L1) and `SIGNED` links render indefinitely; `LocateController::show`
likewise renders captured coordinates on an expired/used token. Add an expiry column
and a status gate on both `show()`s — schema change, so left for a deliberate pass.

**M8 — Negative prices/costs accepted on document lines. FIXED.**
`Lines::add` clamped `qty` but not price or cost; `unit_price=-500` lowered a total
without tripping the variance gate, reachable by a technician on their own WO. Now
throws. Credits remain their own thing.

### Low

**L1 — `users.role` unvalidated. FIXED.** Allowlisted to ADMIN/DISPATCH/TECHNICIAN;
a typo used to create a login that matched no `Auth::is()` and could reach nothing.
**L2 — `signature_data` never validated. FIXED.** `signature_is_image()` in
`helpers.php` (PNG/JPEG data URI, 64–200k chars) at all three capture sites;
`signer_name` capped at 120.
**L3 — SKU suggestion had no length cap. FIXED.** `PartNumbers::clean()` now
`substr(…, 0, 40)` against `catalog_items.sku VARCHAR(48)`.
**L4 — Record-existence oracle. FIXED.** `InvoiceController::find` and
`WorkOrderController::find` ran the lookup before any auth, so a guest got 404 for a
missing id and 302 for a real one — a free count of invoices. Login now precedes the
lookup (`DiagnosticController::find` already did this).
**L5 — No auth event logging. FIXED.** See M5.
**L6 — No HSTS. FIXED.** See M6.
**L7 — Flash is still a raw-HTML sink.** `layouts/app.php:60` and `sign.php:47` emit
`$f['msg']` raw by design; six call sites embed markup. Every new dynamic flash is one
concatenation from XSS (H2 was the third instance of this class). Invert it: escape at
the sink, add a `flash_html()` for the six.
**L8 — `move_uploaded_file` unchecked. FIXED** (with M1).
**L9 — Inbound SMS insert not idempotent.** A Telnyx callback replayed inside the
5-minute window duplicates the `messages` row and re-runs consent. Needs a unique index
on `messages.provider_ref` + an early return.
**L10 — `/geo/reverse|forward` unmetered.** Office-only and CSRF'd, but one user in a
loop can burn the Google key or get the server IP banned by Nominatim, which degrades
`/locate` for stranded callers. Session token bucket + coordinate-rounded cache.
**L11 — LIKE wildcards unescaped** in customers/vehicles/square/api_log search (`%`
`_` from input). Parameterized, so scan cost only.
**L12 — `Http::baseUrl()` falls back to `HTTP_HOST`.** Only reachable behind an
authenticated send, and the Square signature fails closed, but `app_base_url` should
be required before SMS/Square can be enabled, and validated as a URL on save.

### Info / by design

- `GET /invoices/{id}` and `/print` run `recalc()` → `UPDATE`. Values are recomputed
  from existing rows, nothing forgeable; still a mutating GET.
- `admin@setup.com` / `admin123` seeds only when `install.admin.password_hash` is
  empty, is flagged `is_setup`, and retires when a real admin is created. Production
  config is written by `setup.php` option 5 and excluded from deploy. Correct design;
  the residual risk is a fresh deploy against an empty DB with an unedited config.
- Sessions have no idle/absolute cap and a password change does not evict other
  sessions (deactivation does — `Auth::check()` re-reads `is_active` per request).
- Claude part numbering: catalog text is interpolated into the prompt (LLM01), but
  only ADMIN can reach it and `clean()` reduces output to `[A-Z0-9-]` before it touches
  DB or HTML (LLM05 holds). The key is never logged. Catalog names/descriptions do
  leave the box to Anthropic; not disclosed in Settings.
- `X-Forwarded-Proto` is trusted for the `Secure` decision. Spoofing it only makes the
  cookie *stricter*, so it cannot be used to weaken anything.

## 3. What held up

SQL: every dynamic fragment (SquareController lens, LocateController table,
ApiLog WHERE, `Db::liveColumns`) interpolates from `match` arms/constants/int casts;
user text only in placeholders; `LIMIT`/`OFFSET` int-cast; no sort column from input.
`$_POST` appears in four helpers only; every INSERT/UPDATE names columns; Settings,
customers, SR edit use real allowlists. CSRF central, before routing, `hash_equals`,
128-bit; the `/webhooks/` exemption cannot be steered onto another route (anchored
regexes, no path normalization after the check). No GET route writes except the
recalc above. No open redirect — `url()`/`redirect()` build from `SCRIPT_NAME`,
no `?next=`. No `exec`/`eval`/`unserialize`/variable `include`. Webhooks: Telnyx
Ed25519 over `ts|body` with 300 s window; Square HMAC over `notificationUrl+body`,
location-scoped, amount clamped to balance with excess held on 2060. Tokens
`random_bytes(24)` (sign/locate) and `(16)` (pay/CSRF), one-shot claim in a
transaction, identical 404s. `Http`: constants-only destinations, no
`FOLLOWLOCATION`, TLS verify on, 12 s/5 s timeouts; no SSRF surface.
`api_log` never sees headers or bodies. Secrets: separate `SECRETS` list, blank =
keep, `-` = clear, rendered as `type=password` with no `value`; repo grep for key
patterns is clean; `data/secrets.php` loads from outside the tree. Deploy manifest
excludes `config.php`, `deploy/`, `wipe-policy.php`, `tests/`, `storage/`,
`backups/`; everything non-`public/` lands outside the webroot. `SchemaController`
is ADMIN-only, additive-only DDL from `Schema.php` constants, audited. Cost/markup/
margin never reach `checkout`, `sign`, `locate`, `doc_print`, `diag_print`; the line
editor keeps them behind `.internal` + `data-customer-facing`. `app.js`: eight
`innerHTML` sites, all static templates; server JSON via `textContent`. Deactivation
evicts sessions; self- and last-admin deactivation refused; `session_regenerate_id`
on login and logout; uniform login failure message. `e()` is
`ENT_QUOTES|ENT_SUBSTITUTE`. WipeGuard unchanged and intact.

## 4. Files changed

`public/index.php` · `app/helpers.php` · `app/Domain.php` · `app/Services/Services.php`
· `app/Controllers/{Auth,Checkout,Diagnostic,Estimate,Invoice,Records,Sign,WorkOrder}Controller.php`
· `app/Views/pages/wo_show.php`. No schema change. Not yet deployed to production.
