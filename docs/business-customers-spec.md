# Business Customers — Implementation Spec (v2, codebase-verified)

**Project:** White Knight Roadside — Admin (`C:\Users\MSI-Thin\Code Projects\wkr`)
**Feature:** Commercial/business accounts alongside retail customers, with payment terms
and PO numbers flowing through the document chain.
**Supersedes:** the v1 spec written without code access. v1 proposed columns that already
exist under other names; this version is grounded in the actual schema and controllers.

---

## What the codebase already has (verified 2026-07-28)

| v1 proposed | Reality in `app/Schema.php` | Status |
|---|---|---|
| `customer_type` retail\|business | `customers.customer_type` VARCHAR(16) — `INDIVIDUAL` \| `COMMERCIAL` \| `FLEET`, default `INDIVIDUAL` | exists, finer-grained |
| `company_name` | `customers.company` VARCHAR(160) | exists |
| `payment_terms_days` INT | `customers.payment_terms` VARCHAR(32) — `DUE_ON_RECEIPT` (default) \| `NET_15` \| `NET_30` | exists; **read by nothing** |
| PO number on EST/WO/INV | — | missing |
| Terms snapshot on invoice | — | missing |

Other relevant reality:

- `InvoiceController::issue()` **hard-codes `due_at = +15 days`** for every invoice
  (line ~159). `payment_terms` is ignored everywhere. This is the central bug/change.
- Display name is ad hoc — `trim($c['company'] ?: $c['first_name'].' '.$c['last_name'])`
  appears in `doc_print.php`, `est_show.php`, `cust_index.php`, `CustomerController::show()`,
  and JOIN-based variants in lists. No single helper.
- `CustomerController::update()` **cannot change** `customer_type`, `payment_terms`,
  `is_provider`, `provider_code`, or `phone` — the edit form (in `cust_show.php`) omits them.
- `Db::migrate()` only runs `CREATE TABLE IF NOT EXISTS` — it cannot add columns to
  existing tables. (Resolution decided below.)
- `customers.tax_exempt`, `is_provider`, `provider_code` already exist. Out of scope —
  do not touch, do not remove.
- Demo seed (`data/seed.php`) already creates one business account: Cascade Motor Club,
  `COMMERCIAL`, `NET_30`, `is_provider=1`.
- The snapshot precedent is `Lines::add()` in `app/Domain.php` — cost/markup/price copied
  onto the line at creation; later matrix edits never touch existing documents.

## Decisions

1. **Reuse existing columns; add none to `customers`.**
   `INDIVIDUAL` = retail. `COMMERCIAL` and `FLEET` = business. `company` is the company
   name. `payment_terms` stays a VARCHAR label. If arbitrary terms (Net 45, Net 60) are
   ever needed, the future path is adding a `payment_terms_days` INT via the same migrate
   mechanism and treating the varchar as legacy — not now.
2. **COD is the default for everyone.** `DUE_ON_RECEIPT` means pay at time of service
   (typically card). Creating a business account changes nothing about payment; Net 15/30
   is a deliberate per-account grant. An invoice with no terms behaves exactly like today.
3. **Schema evolution: extend `Db::migrate()`** (approved) — after the CREATE TABLE pass,
   diff `Schema.php` column lists against the live tables (`information_schema.columns`
   on MySQL, `PRAGMA table_info` on SQLite) and `ALTER TABLE … ADD COLUMN` anything
   missing. Additive only, idempotent, never drops or renames. Both `data/install.php`
   and `data/wipe.php` already call `Db::migrate()`, so existing entry points pick it up.
4. **One contact per account** (the record's person-name fields). Multiple contacts,
   fleet-vehicle linkage, statements/aging, credit limits: deferred; design must not
   block them.

## Schema changes (`app/Schema.php`, emitted for both engines)

- `estimates.po_number` VARCHAR(64) NULL
- `work_orders.po_number` VARCHAR(64) NULL
- `invoices.po_number` VARCHAR(64) NULL
- `invoices.terms` VARCHAR(32) NULL — snapshot of the customer's `payment_terms` at
  invoice creation. NULL (legacy rows) is treated as COD.

Nothing dropped, nothing renamed. After editing `Schema.php`, regenerate the dump:
`php data/install.php --dump`.

## Business rules — each lives once

All in `app/Domain.php` `Rules` (or `helpers.php` for pure formatting), never in views/JS.

1. **`customer_is_business(array $c): bool`** (helpers) — true for `COMMERCIAL`/`FLEET`.
2. **Display name — `customer_name(array $c): string`** (helpers) — replaces every
   inline `company ?: first last` expression. Business → company name on customer-facing
   documents; `Company (First Last)` on internal screens when a contact name exists
   (second param or twin helper for the internal variant). Retail → person name.
   Sweep: `doc_print.php`, `est_show.php`, `inv_show.php`, `wo_show.php`, `sr_show.php`,
   `cust_index.php`, `cust_show.php` title, `CustomerController::show()`, list JOINs in
   `dashboard.php`, `reports.php`, `msg_index.php`, `pay_index.php`, `inv_index.php`,
   `est_index.php`, `wo_index.php`.
3. **`Rules::termsDays(?string $terms): ?int`** — `NET_15`→15, `NET_30`→30,
   `DUE_ON_RECEIPT`/NULL/unknown→null (COD).
4. **`Rules::invoiceDueAt(string $issuedAt, ?string $terms): ?string`** — null terms →
   due on receipt (`due_at = issued_at`); otherwise issue date + N days. Computed from
   the invoice's **snapshotted** `terms`, never the live customer row. Replaces the
   hard-coded `+15 days` in `InvoiceController::issue()`.
5. **Validation:** business type requires non-empty `company`; retail ignores/clears
   nothing (company stays optional for retail, as today — an individual with a company
   noted is fine). Server-side authoritative, in the controller like existing checks.
6. **Snapshot semantics (Markup precedent):** invoice creation copies the customer's
   current `payment_terms` into `invoices.terms`. Editing the account later never
   changes existing documents. PO number is per-document data, copied forward, never
   re-read from anywhere.
7. **PO carry-forward:** PO entered on the estimate is copied EST → WO → INV at each
   promotion (`EstimateController` dispatch creates the WO; the WO/invoice creation
   copies from its parent). Editable per document while that document is editable.
8. **No new money math.** Date addition only.

## UI

- **Customer form (`cust_new.php`) and the edit form in `cust_show.php`:**
  type select already exists; label the options "Individual (retail)", "Commercial",
  "Fleet". Company field required-when-business (progressive disclosure in JS is fine;
  server validates). Payment-terms select already exists on `cust_new` — label it
  "COD — due on receipt (default)" / "Net 15" / "Net 30"; **add it and the type select
  to the edit form**, since terms can't currently be changed after creation (that gap
  makes the whole opt-in flow impossible today). `CustomerController::update()` gains
  the corresponding fields + validation + audit detail.
- **Customer list (`cust_index.php`):** already shows type and searches company. Add a
  small "Net 15/30" tag when terms granted; no other change.
- **Doc editors:** PO number input on `est_show.php`, `wo_show.php`, `inv_show.php`
  (while DRAFT/editable, following each page's existing edit conventions). Invoice page
  shows snapshotted terms + computed due date.
- **Customer-facing print (`doc_print.php`):** company name via the helper; "PO #" line
  when set; "Due on receipt" or the real due date. Cost/margin visibility rules unchanged.
- Existing PJAX nav + `asset()` conventions; no new front-end machinery.

## Audit & integrity

- Type/terms changes and PO edits log through `Audit::log()` with old→new detail
  (matching existing update actions). No deletions anywhere.

## Seeds

- Demo seed already has Cascade Motor Club (business, NET_30). Add one business account
  **without** terms (COD) so both cases are visible after `php data/wipe.php --demo`,
  and put a PO number on one seeded estimate chain.

## Tests & verification

- `php -l` every touched file.
- `tests/terms.php` in the style of `tests/markup.php`: `termsDays` mapping,
  `invoiceDueAt` for COD/15/30, boundary (invoice issued at 23:59), NULL-terms legacy row.
- Extend `tests/e2e.sh`: (a) business account left on COD → full chain → invoice due on
  receipt, byte-identical behavior to retail; (b) business account granted Net 30 →
  estimate with PO → WO → invoice: PO carried forward, due = issue + 30 days, then edit
  the customer's terms and assert the issued invoice is unchanged; (c) retail chain
  asserting nothing regressed. The suite runs against whichever engine `config.php`
  targets — verify on MySQL (the production engine) at minimum.
- Migration check: run `Db::migrate()` against a copy of the existing database
  (`backups/wkr_admin_before_wipe_20260727_191952.sql` is available) and confirm the new
  columns appear and no data is lost; run twice to prove idempotence.
- `php data/wipe.php --demo`; confirm the seeded business accounts render in list, form,
  and a full doc chain.
- Verify on the running local install at `127.0.0.1:8088`.

## v2.1 — hard person/business distinction (implemented)

A customer is either a **person** or a **business entity** — never a blur:

- **Individual (INDIVIDUAL):** the human is the customer. A name is required;
  the record can never carry a company name (`Rules::accountCompany()` clears
  it). The UI label is "Individual" — never "Person".
- **Business (COMMERCIAL / FLEET):** the company is the customer of record —
  its name is required and goes on every document. The person fields are an
  optional **billing contact** ("Attn:" line on printed documents, shown as
  `Company (Contact)` internally).
- **Fleet ≠ commercial-with-vehicles.** FLEET means the customer's business
  *is* vehicles (couriers, trucking, delivery). A commercial business that
  merely owns several vehicles is COMMERCIAL. This definition appears as a
  hint wherever the type is chosen.
- Both rules live once, in `Rules::customerGate()` / `Rules::accountCompany()`,
  enforced at create, edit, and SR promotion. Forms branch on the type
  (`data-cust-type` / `data-when-cust` in app.js); the customer list has
  All / Individuals / Businesses tabs and a distinct badge per kind.

## Out of scope (unchanged from v1)

Multiple contacts per account, fleet vehicles tied to an account, tax exemption
(column exists; leave untouched), statements/aging, credit limits, provider/broker
mechanics (`is_provider`, `provider_code`, `job_source` — already exist; untouched).
