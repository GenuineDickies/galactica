# Code review — 2026-08-27

> **Addendum (same day):** the following were resolved after the review.
> **M7/tax drift** — decided for per-line (tenant accounts will be US-wide):
> `Lines::taxCents` added, `Lines::totals` now rounds tax per taxable line with
> pro-rata doc-discount allocation; pinned by new `tests/line_tax.php` (16
> assertions). **H6** — "Reply APPROVE"/"Reply PAY" stripped from the SMS
> templates; 10DLC audit P2-H marked resolved. **All §4 doc-drift items** —
> no-SSH claims retired (PI/README/DECISIONS/INTEGRATIONS), INTEGRATIONS
> messages section rewritten to match §11a, JE added to the numbering table,
> AGENTS.md tsc boilerplate removed, ACCOUNTING_PLAN core table corrected to
> the four-leg convention. All 15 pure suites pass after the changes.
> Remaining findings are otherwise open.
>
> **Second addendum (same day):** H1 fixed (atomic `DocNumber::next()` upsert on
> MySQL + unique indexes `uq_pay_number`/`uq_rct_number`/`uq_exp_number`); H2/H3
> fixed (`e()` at the five dynamic flash call sites); H4 fixed
> (`Rules::coreDepositGate` refuses to issue when billed 2050 deposits ≠ part-line
> core charges; seed now pairs the reman parts' core_charge with FEE-CORE-DEPOSIT);
> H5 fixed (`Lines::add` throws on needs-pricing + $0 fallback; explicit 0.00 still
> legal; pricing_integration updated to pin the new rule); H7 fixed (sent/held/
> blocked branch on the locate-link flash); M3 replaced by design decision — no tip
> inference at all: unlabeled overage is held on 2060 (`overpayment_amount`/`_status`
> on payments), flagged on /payments, resolved one-click as tip (reclass to 4300) or
> refund, single-fire via status-claim in the same transaction; M8 fixed (cash-basis
> deducts the unpaid fraction of revenue legs, in cents). `data/schema.mysql.sql`
> regenerated; existing DBs pick up the columns/indexes via /admin/schema. Lint
> clean; all 15 pure suites pass.
>
> **Third addendum (same day):** M1 fixed (generic 503 for visitors, detail
> behind debug + error_log); M2 fixed (XFO/nosniff/Referrer-Policy/baseline CSP
> set centrally — no external assets, so `default-src 'self'` is safe); M4 fixed
> (`Ledger::reverse` claims `reversed_by_id` inside one transaction); M5 fixed
> (`balanceCents` direction from snapshotted line type); M6 fixed (expense
> account validated against the chart; blank still → 6900); M9 comment corrected
> to the four-leg convention; M10 fixed (till form mints an idempotency key +
> `uq_pay_idem`); M11 fixed (`createFromEstimate` deleted); M12 fixed (one VIN
> decoder, helpers copy deleted); M13 fixed (IN_PROGRESS on the field board);
> M14 fixed (`estimate_terms` editable in Settings); M15 fixed (WO line edits
> refused once closed); M16 fixed (8 hot-path indexes added); L4 fixed
> (variance compared in cents); L6 fixed (whole-number qty required on
> core-bearing lines); L7 fixed (WO totals use the estimate's snapshotted
> rate); L8 fixed (capital sweep keys on the exact memo prefix); L9 fixed
> (pricing_integration refuses non-scratch databases without
> `WKR_ALLOW_PRICING_TEST=1`); L13 fixed (explicit `COLLATE=utf8mb4_unicode_ci`);
> L14 fixed (truthful sent/held flash on the on-scene SMS panel). Dump
> regenerated; lint clean; all 15 pure suites pass. **Still open:** L1
> (signature-link expiry), L2 (photo serve route), L3 (login throttling), L5
> (float pipeline in `Lines::totals` accumulation), L10–L12 (small duplicate
> facts, dead pricing config, `doc_address` shim), plus the test-coverage gaps
> in §3.

Comprehensive check per `docs/CODE_REVIEW_PROMPT.md`. Read-only: nothing was changed.
Scope: full sweep of `app/`, `public/`, `data/`, `tests/` against PROJECT_INSTRUCTIONS.md,
README.md, and docs/ (DECISIONS, BUSINESS_RULES, INTEGRATIONS, ACCOUNTING_PLAN).
Lint and pure tests ran on the local XAMPP PHP; DB-mutating tests were skipped (real data
in the local DB — see Test results).

## 1. Summary

The core disciplines hold. Every query goes through the PDO wrapper with bound params;
CSRF is centrally enforced on every POST except signature-verified webhooks; webhook
signatures (Telnyx Ed25519, Square HMAC) are verified constant-time before parse with
replay windows and DB-level idempotency; the Markup engine is exact integer-cents with the
boundary rule pinned by tests; line snapshots survive matrix edits; `Ledger::post` refuses
unbalanced entries inside a transaction and voids reverse rather than delete; `Rules` is
genuinely the single gate for the lifecycle rules; `Schema.php` and `data/schema.mysql.sql`
match exactly. Lint is clean (130 files) and all 15 runnable suites pass (727 assertions).

What's wrong clusters in three places: **trust seams** (flash messages emit raw HTML and
two customer-data call sites don't escape — stored XSS reaching admin sessions), **identity
under concurrency** (`DocNumber::next()` is a read-modify-write race, and payments/receipts/
expenses have no unique doc-number index, so duplicates there are silent), and **the cores
flow** (deposit billing and custody records are two unlinked mechanisms — the current seed
bills deposits with no custody record, and the inverse mis-configuration pays real cash
refunds for deposits never collected).

**Top 5 before production**

1. Escape dynamic data in flash messages (`customer_name`, user email) — stored XSS
   into staff/admin sessions. (H2, H3)
2. Make `DocNumber::next()` atomic and add unique indexes on `payments`/`receipts`/
   `expenses.doc_number`. (H1)
3. Link core-deposit billing to custody: refuse to issue an invoice whose line core
   charges have no matching 2050 deposit amount (and vice versa). (H4)
4. Stop "quote required" items landing at $0.00 on an authorizable estimate — a blank
   price must be an error, as the misc slot already does. (H5)
5. SMS templates advertise "Reply APPROVE" / "Reply PAY" but no handler exists —
   implement or strip. (H6)

## 2. Findings

### Critical

None found.

### High

**H1 — Doc numbering races; three doc types can silently duplicate.**
`app/Domain.php:19-32`. `DocNumber::next()` is SELECT-then-UPDATE with no lock and no
atomic upsert: two concurrent requests mint the same number. SER/EST/WOR/INV/JE have
unique indexes (`app/Schema.php:1150-1153,1171`) so the race surfaces as a 500
mid-transaction (a till payment or webhook fails); `payments`, `receipts`, and `expenses`
(`app/Schema.php:552,586,593`) have **no unique index on doc_number**, so a concurrent
webhook + till payment mints two documents with the same PAY/RCT number silently — against
the assigned-once numbering invariant on documents handed to customers.
Fix: `INSERT … ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq+1)` on `doc_counters`
(the PK already supports it) and unique indexes on the three missing columns.

**H2 — Stored XSS through flash messages carrying customer names.**
Sink: `app/Views/layouts/app.php:60` emits `$f['msg']` raw (by design — callers embed
markup). Sources: `app/Controllers/RecordsController.php:121-124,127-130` interpolate
`customer_name()` (raw first/last/company, `app/helpers.php:292`) into the duplicate-
warning flashes unescaped. A customer saved with a company of `<img onerror=…>` executes
in the next staff member's browser — cross-user stored XSS in the app that holds the
ledger and payment flows. The same raw sink serves the public `/pay`, `/sign`, `/locate`
pages (static strings only today).
Fix: `e()` the dynamic parts at every `flash()` call site, or invert the design so `msg`
is escaped and HTML arrives via an explicit separate field.

**H3 — Same sink, staff email.**
`app/Controllers/RecordsController.php:1462,1490,1510` interpolate `users.email` raw into
flashes (password updated / retire setup admin / (de)activate). Email is not HTML-safe by
construction. Fix: wrap in `e()`.

**H4 — Core deposit billing and core custody are unlinked mechanisms.**
Custody keys on the line's `core_charge` snapshot (`app/Domain.php:4471-4506`); the
customer is billed (and 2050 credited) only via a separate FEE line
(`data/seed.php:235`; comment at `app/Controllers/InvoiceController.php:227-232`). The
seed sets `core_charge => 0` on every item (`data/seed.php:241,275`). Two silent failure
modes: seeded flow bills deposits into 2050 with **no custody record** (forfeiture sweep
and cores report never see them); an operator who sets `core_charge` on a catalog part
(`RecordsController.php:417`) without the fee line creates custody for a deposit never
paid — settling it posts a real cash refund Dr 2050/Cr 1010 (`app/Domain.php:4567-4573`),
money out for money never collected.
Fix: at issue time, verify the invoice's 2050 deposit amount equals the sum of line core
charges (or auto-add the deposit line) and refuse otherwise.

**H5 — "Needs pricing" items land at $0.00 on authorizable estimates.**
`app/Domain.php:128-134`: when `Markup::suggest` says needs-pricing, `Lines::add` falls
back to catalog `unit_price` — 0.00 for every seeded "quote required" item
(`data/seed.php:201-202,220-221`). "Alternator Replacement — quote required · $0.00" can
go out and be authorized: the exact $0 quote BUSINESS_RULES forbids. The misc-slot guard
(`app/Domain.php:103-105`) is the right pattern one screen away, and
`tests/pricing_integration.php:94-100` currently pins the wrong behavior as a pass.
Fix: needs-pricing + zero catalog price + no explicit posted price → throw, as misc does.

**H6 — SMS templates promise reply keywords nothing handles.**
`app/Domain.php:1220,1222` ("Reply APPROVE to authorize", "Reply PAY for a payment
link"). The inbound handler recognizes only STOP/START/HELP
(`app/Services/Services.php:68-70`; `app/Controllers/WebhookController.php:196-213`); an
APPROVE reply becomes a generic `sms:reply` audit line and the estimate sits
unauthorized while everyone believes it's moving. Fix: implement the keywords or strip
the phrases (and align the keyword tables in INTEGRATIONS/BUSINESS_RULES either way).

**H7 — "Location link texted." flashed when nothing was sent.**
`app/Controllers/ServiceRequestController.php:295-298` keys the flash on `$out['ok']`,
which is true for the *held* (outbox) case. This is the regression class DECISIONS'
"Truthful send results" retired — and `store()` (lines 155-159) and
`EstimateController::sendLocateLink` in the same codebase already do it right.
Fix: branch on sent/held like the siblings.

### Medium

**M1 — DB credentials shown to unauthenticated visitors when the DB is down.**
`public/index.php:72-85`: the 503 page prints driver, `username@host:port`, and database
name before any auth, independent of `debug`. Escaped (no XSS), but it's reconnaissance
served during every outage. Fix: generic message for visitors; detail behind `debug`/logs.

**M2 — No security response headers.**
No CSP, `X-Frame-Options`/`frame-ancestors`, `X-Content-Type-Options`, or
`Referrer-Policy` anywhere (`public/index.php` / `app/Core.php`). `/sign/{token}` and the
authorize flow are prime clickjacking targets, and a CSP is the backstop for H2.
Fix: emit centrally in the front controller.

**M3 — Any overpayment silently becomes tip revenue.**
`app/Controllers/InvoiceController.php:369-374` clamps to `balance_due` and routes excess
to `tip` → 4300 (`app/Domain.php:2808-2814`), including fat-fingered till entries and
webhook amounts against a since-reduced balance (`WebhookController.php:151`). 2060
Customer Refunds Payable is seeded for exactly this and never used.
Fix: excess is a tip only when a tip was explicitly entered; otherwise park in 2060.

**M4 — `Ledger::reverse()` is not atomic or race-guarded.**
`app/Domain.php:2438-2468`: unlocked already-reversed check; the two linking updates run
after `post()`'s transaction commits when no outer tx exists. Crash between them, or two
concurrent voids → double reversal. Fix: one `Db::tx` around check+post+links with
`SELECT … FOR UPDATE` (or unique index on `reverses_entry_id`).

**M5 — Deleted GL account flips balance sign in reports.**
`app/Domain.php:2500-2502`: `balanceCents()` reads account type from live `gl_accounts`;
deleted account → `''` → treated debit-positive, sign-flipping the cores note
(`:4305-4326`), receivables recon (`:4250`), Square clearing (`:4061`). The journal lines
snapshot `account_type` for exactly this. (Deletability itself is by design — not
flagged.) Fix: derive direction from the snapshotted `l.account_type`.

**M6 — Expense `account_code` is free text.**
`app/Controllers/RecordsController.php:1055` + `app/Views/pages/exp_index.php:40`. A typo
("50000") posts cleanly with NULL account name/type on the journal line
(`app/Domain.php:2410-2416`) and drops out of every type-filtered report including
cash-basis costs (`:4356-4360`) — in the journal, invisible on the P&L.
Fix: validate against `gl_accounts` or make it a picker.

**M7 — Tax computed subtotal×rate; docs say per line.**
`app/Domain.php:209` vs `docs/BUSINESS_RULES.md:427-428`. Latent at Oregon's 0%, but the
rate is a setting (`settings.php:23`) and the two rounding schemes differ by cents the day
it's nonzero. Fix: round per line and sum, or amend the doc — one must move.

**M8 — Cash-basis report subtracts the wrong quantity.**
`app/Domain.php:4350-4354` vs `docs/ACCOUNTING_PLAN.md:275-276`: code subtracts full
`SUM(balance_due)` (includes tax and 2050 core lines) from accrual revenue (excludes
both), understating cash-basis revenue whenever an unpaid invoice carries tax or a core
deposit. Also the one report line outside integer-cents discipline (`* 100` then `(int)`;
float on SQLite). Fix: deduct the unpaid invoices' revenue-leg amounts from the ledger.

**M9 — Supplier core credit direction: plan, comment, and code disagree.**
`docs/ACCOUNTING_PLAN.md:197` says supplier credit decreases 2050; the code credits
(increases) it (`app/Domain.php:4554-4560`), per a four-leg convention documented only in
`tests/ledger_integration.php:380-396` — which nets to zero only if the part purchase is
expensed to 2050, something nothing enforces or suggests (see M6). The comment at
`:4555` describes the opposite of the code below it.
Fix: align plan + comment to the four-leg convention and enforce/document the leg-1
purchase coding, or 2050 overstates by every supplier credit.

**M10 — Manual payments have no concurrency guard.**
`app/Controllers/InvoiceController.php:367-405`: balance read and insert are separate;
two simultaneous cash/check submissions (no `processor_ref`) both record. `uq_pay_ref`
protects only processor callbacks. Fix: per-invoice idempotency token on the form, or
`SELECT … FOR UPDATE` on the invoice across read-and-insert.

**M11 — Orphaned `createFromEstimate` carries a second, drifted dispatch gate.**
`app/Controllers/WorkOrderController.php:55-95`: no route references it, and it gates on
`status !== 'APPROVED'` (line 60) instead of `Rules::dispatchGate()` (which checks lines
+ `authorized_at`). A hard rule living twice, already diverged. Fix: delete it.

**M12 — VIN decoding implemented twice, both copies live.**
`app/helpers.php:342-361` (`vin_decode`, used by `RecordsController.php:274`) duplicates
`StructuralVinDecoder` (`app/Services/Services.php:724-750`, used by
`EstimateController.php:223`), year and WMI maps included. Vehicles decode differently
depending on where they were born. Fix: delete the helper; use `Integrations::vin()`.

**M13 — Dashboard field board loses jobs once work begins.**
`app/Controllers/AuthController.php:103` filters `IN ('ASSIGNED','EN_ROUTE','ON_SITE')`;
`IN_PROGRESS` was never added, so a tech actively wrenching vanishes from "In the field".
Fix: add it.

**M14 — `estimate_terms` cannot be edited anywhere.**
Snapshotted onto every estimate (`ServiceRequestController.php:570`), seeded once
(`data/seed.php:45`), absent from `SettingsController::KEYS`
(`RecordsController.php:1328-1336`) and the settings form. The ORS-relevant contract
terms are editable only by raw SQL. Fix: add to KEYS and the settings page.

**M15 — Work-order lines editable after COMPLETED.**
`app/Controllers/WorkOrderController.php:204-231`: `addLine`/`delLine` have no
closed-status guard (compare `assign`/`setPo`, and `assertDraft`/`assertOpen` on
EST/INV). Lines can be hard-deleted (`app/Domain.php:193-196`) from a completed,
customer-signed WO — mutating the record the signature covers.
Fix: refuse line edits on COMPLETED/CANCELLED/NO_SHOW.

**M16 — Missing indexes on hot lookups** (none present in `app/Schema.php:1125-1200`):
`customers.phone_e164`/`phone2_e164` (probed on every duplicate check
`app/Domain.php:971-995`, every inbound SMS/STOP `WebhookController.php:177`, verbal
consent `RecordsController.php:1165` — the compliance path is a table scan); `api_log`
(no index beyond PK; `receiptHealth` `RecordsController.php:1110-1113` and /api-log
scan an append-heavy table); `messages.service_request_id`; `invoices.customer_id`;
`receipts.payment_id`; `work_orders.service_request_id`.
Fix: add to `Schema::define()`; `Db::addMissingIndexes()` picks them up.

### Low

**L1** — Signature-request tokens never expire (`app/Domain.php:1412-1451`); location
tokens are single-use + 4h, signature tokens only close on signing/supersession. Add an
expiry enforced at `resolve()` as LocateController does.
**L2** — Photo write path vs serve path mismatch (`WorkOrderController.php:481-487`):
written above webroot (protected), recorded as a `url()` path that resolves under
`public/` (won't serve). Safe today, but the tempting "fix" (move into public/) would
expose customer photos at guessable names. Serve via an authenticated route instead.
**L3** — No login throttling (`app/Core.php:91-99`, `AuthController.php:21-29`);
bcrypt + session regeneration are in place, but /login is credential-stuffable.
**L4** — Statutory variance gate compares floats (`app/Domain.php:789-793`); compare in
cents like everything else.
**L5** — `Lines::totals`/`line_total` are the one money pipeline in float arithmetic
(`app/Domain.php:199-218,168`; override-inference epsilon at `:142`). Safe at these
magnitudes; port to cents when convenient.
**L6** — Core qty truncated by `(int)` cast (`app/Domain.php:4292,4531`): qty 2.5 → 2, so
ledger refund/forfeit can disagree with billing on fractional quantities.
**L7** — WO screen totals use live `App::taxRate()` (`WorkOrderController.php:117`) while
the signature flow correctly uses the estimate snapshot (`:356`).
**L8** — `capitalRepaymentCorrection` sweeps ADJ entries by memo substring "repaid"
(`app/Domain.php:3310-3323`); discriminate on `source_ref` instead.
**L9** — `tests/pricing_integration.php:41` runs `DELETE FROM markup_tiers` against
whatever `config.php` points at and re-seeds defaults; PROJECT_INSTRUCTIONS lists the
command with no warning. Add a DB-name guard like the wipe guard.
**L10** — Facts living twice: `SquareSync::e164` vs `phone_to_e164`
(`Services.php:2092-2099` / `helpers.php:232-240`); `SquareSync::localStamp` vs
`TelnyxSmsGateway::stamp`; `payment_terms_options()` keys vs `Rules::TERMS_DAYS`;
`wo_show.php:13` `$nextMap` vs `Rules::workOrderTransitions`.
**L11** — Dead pricing config: `default_labor_rate`, `drive_time_rate`, `mileage_rate`
(`config.php:73-75`; seeded/editable in settings) are read by nothing.
**L12** — `doc_address()` shim (`app/helpers.php:499-517`) self-marked for deletion after
`data/drop-service-address.php` runs everywhere; nothing tracks that.
**L13** — Tables emit `DEFAULT CHARSET=utf8mb4` with no COLLATE (`app/Schema.php:108`);
on MySQL 8 that's `utf8mb4_0900_ai_ci`, making README's `utf8mb4_unicode_ci` choice a
no-op at table level.
**L14** — `sendSms` flash says "queued in the outbox" even on a live carrier send
(`ServiceRequestController.php:654-656`); harmless direction, off-doctrine.

## 3. Test results

Environment: sandbox has no PHP; lint and tests ran via `C:\xampp\php\php.exe` on this
machine.

**Lint:** `php -l` on all 130 `.php` files — 0 errors.

**Pure suites (all pass, 727 assertions, 0 failures):** markup 42 · ledger 111 ·
markdown 44 · address 42 · asset_version 14 · expense_rules 60 · service_category 120 ·
square_settle 42 · square_sync 102 · terms 40 · wipe_guard 26 · account_delete 23 ·
api_log_view 16 · signature_gate 45.

**Not run (need or mutate the live DB, which holds real data — run only with explicit
approval):** duplicates, ledger_integration, pricing_integration (destructive: wipes
`markup_tiers`, see L9), purge, reset, setup_admin, sms_delivery, location_capture
(failed at `Db.php:36` without DB credentials), geocode_live (live network), e2e.sh.
`exec.php`, `query.php`, `sign.php` are harnesses, not tests.

**Coverage gaps worth closing:** `Lines::totals` (tax rounding, discounts) untested;
`PaymentController::record` clamp/tip/duplicate paths; DocNumber under concurrency; any
taxed invoice posting (every test runs at 0%); the Square settlement engine
(`app/Domain.php:3034-3269`), especially refund sign math; cash-basis report *numbers*
(`ledger_integration` asserts shape only, so M8 is invisible to the suite);
`pricing_integration.php:94-100` pins H5's wrong behavior and should become the
regression test for its fix.

## 4. Doc drift

- **INTEGRATIONS.md:109-118** still describes mark-sent-by-hand and replies running
  through the Telnyx handler; both were deliberately removed (`public/index.php:257-261`,
  `RecordsController.php:1123-1141`, BUSINESS_RULES §11a records the reversal).
- **"No SSH on production"** (PROJECT_INSTRUCTIONS.md:139, DECISIONS.md:186/386,
  INTEGRATIONS.md:28, README.md:185, plus code comments) vs the SSH deploy tooling
  (`data/deploy-ssh.php`) and docs that route work through SSH
  (FINANCIAL_REVIEW_PROMPT.md:32, SQUARE_SETTLEMENT_PLAN.md:83). The plan has SSH;
  the "no shell" statements should be retired.
- **BUSINESS_RULES.md:427** "tax per line, never subtotal × rate" vs `Domain.php:209`
  (see M7).
- **ACCOUNTING_PLAN.md:197** supplier-credit direction vs code (see M9);
  **:275-276** cash-basis exclusion wording vs code (see M8).
- **BUSINESS_RULES numbering table (~399-410)** lacks the `JE` prefix minted at
  `Domain.php:2392`; AGENTS.md's checklist says every prefix gets a row.
- **AGENTS.md:119** "run `npx tsc --noEmit` from the frontend directory" — no frontend
  or TypeScript exists; pasted boilerplate.
- Keyword tables (INTEGRATIONS, BUSINESS_RULES §11) list STOP/START/HELP while shipped
  templates advertise APPROVE/PAY (H6) — must agree whichever way H6 is fixed.

## What held up (once, briefly)

SQL injection: nothing found — all dynamic-looking SQL builds from code-side constants
with user text only in bound placeholders. CSRF: central, `hash_equals`, covers all
JSON/AJAX POSTs. Webhooks: verified-before-parse, replay-windowed, idempotent, Square
location-scoped. Public token routes: CSPRNG tokens, uniform 404s, single-use via guarded
UPDATE. Markdown renderer escapes per line and restricts link schemes. Admin dynamic
surfaces (Schema/Records) never take table names from input; `Db::migrate` is
additive-only. Auth gates on every non-public route with per-request DB re-check and
tech own-job scoping; no unauthenticated route found that should require login. Secrets
excluded from deploy, masked in UI, kept out of audit/api logs; everything above
`public/` is `.htaccess`-denied. Session cookies httponly/samesite/secure, regenerated on
login. Wipe guard fails closed. Markup math exact and pinned; snapshots frozen through
EST→WO→INV; pricing formula server-side only (JS shows a display preview and calls
`/pricing/suggest`). Ledger: balanced-entry enforcement in cents, reversal-not-delete,
period locks, posting matrix matches the plan. `Schema.php` ≡ `data/schema.mysql.sql`.
All outbound calls via `Services\Http` and logged to `api_log`. No TODO/FIXME markers, no
orphaned views, no debug leftovers.
