# Open issues

Compiled 2026-09-03 from every review and plan in `docs/`, plus a code check. Items marked fixed in their source doc are omitted. Tick a box when it ships; move the line to the bottom "Closed" section with the date and commit.

Severity is the source doc's where it gave one, otherwise a judgment call. **Bold** = do first.

## Do first

- [ ] **D4 · HIGH · Admin shell doesn't collapse on a phone — the tech's device.** `.shell` grid has no breakpoint; `wo_show` gets ~115px on a 375px screen. ~760px breakpoint, sidebar behind a toggle. — DESIGN_CRITIQUE #1

## A. Security

- [ ] A1 · MED · No login throttling. Needs `login_attempts` table + per-email+IP backoff in `AuthController::submit`. — SECURITY_REVIEW M5, CODE_REVIEW L3
- [ ] A2 · MED · Signature links never expire; SIGNED links render name/address/plate/prices forever. `signature_requests` has no `expires_at`. `LocateController::show` has the same shape. — SECURITY_REVIEW M7, CODE_REVIEW L1
- [ ] A3 · LOW (recurring) · Flash message is a raw-HTML sink (`layouts/app.php:60`, `sign.php:47`). Has produced XSS three times. Escape at the sink, add `flash_html()` for the six markup callers. — SECURITY_REVIEW L7
- [ ] A4 · LOW · Inbound SMS insert not idempotent — replayed Telnyx callback duplicates the row and re-runs consent. Unique index on `messages.provider_ref`. — SECURITY_REVIEW L9
- [ ] A5 · LOW · `/geo/reverse|forward` unmetered; one user can burn the Google key or get the IP banned by Nominatim. — SECURITY_REVIEW L10
- [ ] A6 · LOW · LIKE wildcards unescaped in customer/vehicle/square/api_log search. — SECURITY_REVIEW L11
- [ ] A7 · LOW · `Http::baseUrl()` falls back to `HTTP_HOST`; `app_base_url` should be required before SMS/Square can be enabled. — SECURITY_REVIEW L12
- [ ] A8 · LOW · No idle/absolute session cap; password change doesn't evict other sessions. — SECURITY_REVIEW Info
- [ ] A9 · LOW · `GET /invoices/{id}` and `/print` run `recalc()` → UPDATE. — SECURITY_REVIEW Info
- [ ] A10 · LOW · Claude part-numbering sends catalog names/descriptions to Anthropic; not disclosed in Settings. — SECURITY_REVIEW Info
- [ ] A11 · LOW · Fresh deploy with unedited config seeds `admin@setup.com`/`admin123` (`data/seed.php:95`); `--demo` seeds dispatch123/tech123. Design accepted; the unedited-config path is the risk. — SECURITY_REVIEW Info, AGENTS.md
- [ ] A12 · LOW · `config.example.php:83` ships `'debug' => true`. Live config is false. Flip the example.
- [ ] A14 · LOW · Pre-deploy checklist in AGENTS.md (debug off, passwords, storage perms, docroot) is manual and unenforced.

## B. Accounting / books

- [ ] B1 · HIGH · Ledger Phases 3–5 unbuilt: cores end to end (catalog core-charge field, `/catalog/{id}/edit`, `Lines::add()` carrying core, `core_records`, forfeiture sweep); reports (trial balance, account detail, AR aging, cores outstanding, cash-basis); period locking. — ACCOUNTING_PLAN
- [ ] B2 · HIGH · Refunds and credit memos have no document to post from. `InvoiceController::void` says "record a refund instead" and nothing exists. — ACCOUNTING_PLAN Phase 2 leftovers
- [ ] B6 · HIGH · Nothing from the six-year reconstruction is posted. Importer, posting rule, review screen unbuilt; rules + normaliser + 60 tests exist. — FINANCIAL_DOCUMENT_REVIEW, BANK_IMPORT_FINDINGS
- [ ] B7 · HIGH · Cash App ($13,734 / 156 payments) and Venmo revenue absent from the ledger. Books show revenue $219,569 (Square only) and expenses $12,202 — both wrong. — BANK_IMPORT_FINDINGS
- [ ] B9 · HIGH · $102,895 swept to prepaid cards 2021–2023, largely undocumented. Sits in 1030 as an asset; nothing expensed. Likely unrecoverable. — BANK_IMPORT_FINDINGS
- [ ] B4 · MED · Core netting depends on leg 1 being coded to 2050; nothing enforces or suggests it. — ACCOUNTING_PLAN, CODE_REVIEW M9
- [ ] B8 · MED · ~$4,800 of Cash App withdrawals don't reconcile to bank statements. — BANK_IMPORT_FINDINGS
- [ ] B10 · MED · Document gaps: Venmo pre-Aug-2026, Cash App pre-Jul-2024, Square Checking ≤2020, Business Checking 2807 after Sep 2022 (closed?), receipts 2020–2023. — FINANCIAL_DOCUMENT_REVIEW
- [ ] B11 · MED · Owner draw/contribution 2020–2022 never posted (`FundsTransfer.csv`: $9,410 draw, $7,303 contribution). — FINANCIAL_DOCUMENT_REVIEW
- [ ] B12 · MED · Reclassification of 4050 Historical Card Sales to real job types unbuilt (891 payments now attributable). — FINANCIAL_DOCUMENT_REVIEW, SQUARE_SETTLEMENT_PLAN
- [ ] B13 · MED · Square classification pass outstanding: 2,850 UNREVIEWED charges, then `square_classify.php --business`, `--fix-capital --commit`, `--commit`. 1050 → 0, 2100 must not move. — SQUARE_SETTLEMENT_PLAN "What remains"
- [ ] B18 · MED · Importer must assert row counts and balance continuity (parser once silently dropped 376 whole-dollar rows). — BANK_IMPORT_FINDINGS
- [ ] B20 · MED · Untested money paths: `Lines::totals` discounts, `PaymentController::record` clamp/tip/duplicate, `DocNumber` concurrency, any taxed posting, settlement refund sign math, cash-basis numbers. DB-mutating suites weren't run in the last review. — CODE_REVIEW §3
- [ ] B5 · LOW · `Lines::totals` / `line_total` still float; port to cents. — CODE_REVIEW L5
- [ ] B14 · LOW · 2010 Credit Card Payable ~$1,422 debit until card spending is imported (depends on B6/B9). — SQUARE_SETTLEMENT_PLAN
- [ ] B19 · LOW · No opening balances at ledger cutover; earlier-period reports are empty. One manual entry if wanted. — ACCOUNTING_PLAN
- B15 · accepted · One 2019 $10 charge will never post.

Decisions needing a human (CPA / attorney / Jason):
- [ ] B3 · Is a 30-day core forfeiture window enforceable under Oregon UTPA? Confirm before printing a deadline on invoices.
- [ ] B16 · Personal/transfer treatment crediting 3100 — CPA question (task #28).
- [ ] B17 · The accountant conversation: six years of off-Square revenue, $102,895 prepaid, whether 2021–2022 can be reconstructed.

## C. Integrations — SMS / 10DLC (all "awaiting approval" in the audit)

- [ ] C4 · P2 · Plain-language revocation only audited; no review queue or 10-business-day clock. `needs_review` flag + unread count in `/messages`. — 10DLC §2D
- [ ] C5 · P2 · HELP refused to anyone opted out (gate blocks it). Narrow `Sms::queueCompliance()` bypass for STOP-confirm and HELP only. — 10DLC §2E
- [ ] C6 · P2 · No opt-out confirmation; no opt-in confirmation on the dispatcher-ticks-the-box path. — 10DLC §2F
- [ ] C7 · P2 · No privacy policy / ToS surface — blocks TCR registration. No route, view, or setting exists. — 10DLC §2G
- [ ] C8 · P3 · HELP response hard-codes `(503) 764-3154` (`Domain.php:860`) instead of `{co}`. — 10DLC §2I
- [ ] C9 · P3 · Brand string "White Knight Roadside" vs registered "White Knight Roadside, LLC". Confirm against TCR; Health check. — 10DLC §2J
- [ ] C10 · P3 · Consent stores source slug but not the script language/version. — 10DLC §2K
- [ ] C14 · LOW · Photo write path is above webroot but recorded URL resolves under `public/` — won't serve. Serve via authenticated route, don't move into public. — CODE_REVIEW L2
- [ ] C11 · decision · Findings A, B, C, G past TCPA counsel before the campaign goes live.
- C13 · constraint · Reply-keyword authorization, if ever built, must be declared on TCR first.

## D. UX / accessibility

- [ ] D5 · HIGH · Work-blocking gate is one `.alert` among four look-alikes in `wo_show.php:79-139`. Distinct, sticky treatment. — DESIGN_CRITIQUE #2
- [ ] D7 · MED/HIGH · Customer hand-over hiding of cost/margin is JS-only in modals (`body.is-customer .internal`). If `app.js` fails, margin is in the customer's hands. Render sign/close-out modals from a fragment with no `.internal` markup. — DESIGN_CRITIQUE #3
- [ ] D6 · MED · Disabled-reason-in-`title` invisible on touch — remaining sites `wo_show.php:185` ×3, `inv_show.php:38` (Issue invoice / Begin work already fixed). — DESIGN_CRITIQUE
- [ ] D8 · MED · Estimate/invoice headers render no action when no branch matches (fresh DRAFT with no lines). Always show the primary action, disabled, with a reason. — DESIGN_CRITIQUE #4
- [ ] D9 · MED · Dense tables have no mobile treatment; tech horizontally scrolls their own job list. Stacked cards <700px for tech-facing tables. — DESIGN_CRITIQUE #5
- [ ] D10 · MED · No SR → Est → WO → Inv breadcrumb; `$crumb` exists, nothing sets it. — DESIGN_CRITIQUE
- [ ] D15 · MED · Inline validation only for VIN and phone; everything else bounces to full reload mid-modal. Also verify: does the server refuse a nameless misc line without JS? — DESIGN_CRITIQUE
- [ ] D11 · LOW · `inv_show.php:16` locks tech line edits on DRAFT invoice with no on-screen reason.
- [ ] D12 · LOW · Confirmation weight not graduated (line remove = confirm(), tier remove = nothing, void = typed reason).
- [ ] D13 · LOW · `badge--warn` carries three meanings on `wo_show.php:374`.
- [ ] D14 · LOW · `data-stop-row-click` missing on line-editor ✕ buttons inside clickable rows.
- [ ] D16 · LOW · `data-href` rows have no visual affordance beyond hover cursor.
- [ ] D17 · LOW · `doc_print.php` has no `page-break-inside: avoid`.
- [ ] D1 · LOW · Static `.alert` gate boxes indistinguishable from live feedback (`role="status"` on both). — ACCESSIBILITY_REVIEW "future pass"
- [ ] D3 · LOW · Native Tab traversal and screen reader never verified in a real browser; `/locate` live region verified statically only.
- D2 · accepted · Sidebar items stay `<button data-url>`, not links.

## E. Data / customers

- [ ] E3 · LOW · AGENTS.md says `Db::migrate()` is CREATE-IF-NOT-EXISTS only; contradicts shipped `Db::addMissingColumns()`. Fix the doc.
- E2 · deferred · Multiple contacts per account, fleet vehicles on an account, statements/aging, credit limits, tax exemption (`tax_exempt` untouched), provider/broker mechanics (`is_provider`, `provider_code`, `job_source` untouched), Net 45/60. — business-customers-spec, DECISIONS
- E4 · accepted · Retired service types read "(unspecified)" on old rows.

## F. Deploy / ops

- [ ] F2 · LOW · PROJECT_INSTRUCTIONS.md:130 lists `pricing_integration.php` without the `WKR_ALLOW_PRICING_TEST=1` warning; it wipes `markup_tiers`.
- [ ] F3 · LOW · Dead config: `default_labor_rate` / `drive_time_rate` / `mileage_rate` editable in Settings, read by nothing. — CODE_REVIEW L11
- [ ] F4 · LOW · `doc_address()` shim (`helpers.php:571`) deletable once `drop-service-address.php` has run everywhere; nothing records that. — CODE_REVIEW L12
- [ ] F5 · LOW · Facts living twice: `SquareSync::e164` vs `phone_to_e164`; `SquareSync::localStamp` vs `TelnyxSmsGateway::stamp`; `payment_terms_options()` vs `Rules::TERMS_DAYS`; `wo_show.php` `$nextMap` vs `Rules::workOrderTransitions`. — CODE_REVIEW L10
- F6 · standing rule · Windows truncates command lines at 8191 chars; server-side scripts go through `--put`, never `--run`.
- F7 · standing rule · Targeted deploys; `public/index.php` ships last; schema via `/admin/schema`; verify with cache-busted curl.

## G. Decisions pending / doc drift

- [ ] G2 · Password policy and 2FA — deferred in DECISIONS "MVP hardening", unaddressed. (CSP line there is stale — it shipped.)
- [ ] G3 · WipeGuard can be defeated by editing `Guard.php`. Revoke DROP/TRUNCATE from the app's MySQL user for enforcement that survives a compromised repo.
- [ ] G5 · Chart entries seeded active but inapplicable (2020 Sales Tax Payable, 1200 Parts Inventory, 6050–6070 payroll). Operator retires at `/accounts`.
- [ ] G6 · Tax engine never exercised end to end — every test runs at 0%.
- [ ] G7 · Doc drift: DECISIONS "Chart of accounts" deferral superseded by ACCOUNTING_PLAN; keyword tables in INTEGRATIONS.md / BUSINESS_RULES §11 must track C1–C6 changes; E3; F2.
- G1 · deferred by decision · Provider API intake, scheduling/routing, fleet contracts, inventory, customer portal.
- G4 · revisit trigger · 1350 Supplier Core Deposits Receivable if supplier core balances grow.

## Closed

- [x] F1 · 2026-09-04 · 2026-09-03 security/a11y/design pass (commit 6283a6f, 63 files) plus the C1–C3 STOP fixes deployed to galactica.wkrllc.com via `data/deploy.php` (index.php last). Verified: md5 of all 63 files identical on the server over SSH, `php -l` clean on the server, /login renders in a browser (scripted clients get a WAF 403 — host behaviour, not the app).

- [x] C1, C2, C3, C12 · 2026-09-04 · STOP handling: `Consent::revokeRequests()` clears intake consent on open requests (SMS and verbal paths, with or without a customer record); `Sms::queueForRequest()` honours customer `do_not_contact`; `keyword()` matches two-word FCC phrases (OPT OUT, STOP ALL, CANCEL SUBSCRIPTION, OPT IN); `msg_index` copy corrected; 40 new assertions in `tests/sms_delivery.php` (148/148). Docs: INTEGRATIONS.md keyword table, 10DLC audit resolution notes. Commit: pending.

- [x] A13 · 2026-09-03 · Card-data spreadsheets scrubbed: local `Downloads` copies reduced to last-4 (Honk: number/cvc2/exp_date columns deleted; Urgently: 965 PANs → last4) by Claude via Excel; Drive copies edited by Jason. Drive version history may still hold the pre-edit versions — Manage versions → delete old version, then empty Trash. No commit (not code).
