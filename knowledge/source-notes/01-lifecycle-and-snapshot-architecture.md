# Product/Requirements Notes — Bin product-1

Sources: two ChatGPT conversations — *"Life stages of request"* (Dec 2025) and *"Service Request Lifecycle"* (late Dec 2025, the more recent of the two). Both define the core dispatch-to-cash workflow for the WKR admin app: Service Request → Estimate → Work Order → Invoice → Payment → Receipt. Where the two chats differ, the later "Service Request Lifecycle" chat holds the settled model (living SR + snapshot documents with simpler SR statuses); the earlier chat's 15-state unified machine is retained below because its guards, locks, and page inventory were what Jason asked to have written up for a coding agent.

---

## 1. Architectural decision: Living SR + Snapshot Documents *(ChatGPT: "Service Request Lifecycle")*

Jason asked directly: "should the service request be a living document?" **Final resolution:**

- **Service Request (SR) = living operational case file, always mutable.** It holds the *current truth* of the job: status + timestamps, customer link, vehicle link, location, problem description, internal notes, outcome summary, and pointers to the latest documents (`latest_estimate_id`, `latest_work_order_id`, `latest_invoice_id`). SR may display derived totals (current estimate total, current invoice total, balance due) but is **never authoritative** for signed/issued amounts.
- **Documents = official immutable snapshots:**
  - **Estimate** — customer-approved pricing snapshot; **versioned** (v1, v2, ...) per SR; locked after signature; scope changes create a new version, never edit v1.
  - **Work Order** — execution record: what was actually done, attempt/outcome codes, parts installed, time, photos. This is where "paid for the attempt" lives.
  - **Invoice** — billing snapshot; locked once ISSUED; corrections via void + reissue or credit memo (V2), never edits.
  - **Payment / Receipt** — payments are **append-only** records against an invoice; receipt is a generated view of invoice + payments.
- Recommended `sr_events` append-only table for the job timeline/audit (dispatched, arrived, attempted, completed, cancelled).

### Line-item snapshot rule (non-negotiable)
When a catalog item is added to an estimate/invoice, store `catalog_item_id` as a reference but **copy** into the line item: `name`, `description_public`, `unit`, `unit_price`, `taxable`, `qty`, computed `line_total`. Catalog prices can change; historical documents must not. Line items live on document tables (`estimate_line_items`, `invoice_line_items`, optional `work_order_line_items`), never on the SR itself.

### Locking rules
| Object | Locks when | Change path afterward |
|---|---|---|
| SR | Never (editable throughout; read-only after closeout in the earlier model) | — |
| Estimate | Customer signature | Create Estimate v2 |
| Invoice | Issued | Void + reissue, or credit memo/adjustment (V2) |
| Payments | Always append-only | Refund records |
| Work Order | Work-complete / sign-off (earlier model) | Admin override only |

---

## 2. Signature & authorization rules (core business policy, appears in both chats)

- **Every job requires an estimate** — no exceptions.
- **Estimate approval:** customer **signature required if estimate total > $200**. At or below $200, verbal/text approval is acceptable but must be recorded (method + timestamp).
- **Invoice-vs-estimate variance authorization:** before issuing the invoice, compute
  `delta = abs(invoice_total − approved_estimate_total)` and `threshold = min($200, 10% × estimate_total)`.
  If `delta > threshold` → **new customer authorization signature required**. (Phrased as "over $200 OR over 10%, whichever is smaller.")
- **Change Orders** must pass the same variance test: signature required if applying the CO would push the final invoice past the threshold. Applied COs join the "authorized total" against which future variance is checked.
- **Completion sign-off:** customer signature at work completion recommended as a standard rule (even if not legally required); a "customer not present" bypass is allowed only with reason code + photo evidence + admin flag (audit flagged).
- `document_signatures` table supports purposes: `ESTIMATE_APPROVAL`, `INVOICE_AUTHORIZATION`, `WORK_COMPLETION_ACK`.

---

## 3. Invoice issuance gate (hard checks) *(both chats)*

An invoice may exist in DRAFT without these, but **cannot move to ISSUED** unless:

1. **A Vehicle record is attached** (`vehicle_id` present and valid) — Jason's explicit rule: "For an invoice to be issued, a vehicle record must be attached." Blocking UI message: "Attach a vehicle record to issue this invoice," with actions **Attach Existing Vehicle** or **Create Vehicle Record** (requires VIN; optional plate+state VIN lookup).
2. **VIN present** — draft allowed without VIN, issuance is not.
3. **Plate + State recorded** on the invoice — always required for invoice output; a **No-Plate flag** is supported (state + "NO PLATE" value still recorded).
4. **Signature rules satisfied** — estimate signed if > $200; variance authorization if delta exceeds threshold.
5. Sanity: at least one line item; totals compute cleanly and match stored totals.

Related vehicle rules threaded through both chats: **VIN is required to create a customer vehicle record** (plate+state is lookup data, not a substitute); a vehicle record only becomes "real" once a VIN exists.

---

## 4. Lifecycle models

### 4.1 Full document lifecycle, 12 stages *(ChatGPT: "Life stages of request")*
Intake Lead → Service Request → Triage/Scope Confirmation → Estimate (required for every job) → Authorization to Proceed (gate) → Work Order (execution) → Change Order/Supplement (only if scope changes) → Completion Acknowledgement → Invoice → Payment → Receipt → Closeout/Accounting Posting.

Status spine: **Intake → Open SR → Scoped → Estimated → Approved → In Progress → Completed → Invoiced → Paid → Closed/Posted**.

### 4.2 Strict unified Job state machine *(ChatGPT: "Life stages of request" — written up for a coding agent)*
Single **Job** owning sub-documents (Estimate, Work Order, Invoice, Payments). States:
`INTAKE → SR_OPEN → EST_DRAFT → EST_PRESENTED → EST_APPROVED → DISPATCHED → ON_SCENE → WORK_IN_PROGRESS → WORK_COMPLETE_PENDING_SIGNOFF → READY_TO_INVOICE → INV_DRAFT → INV_ISSUED → PARTIALLY_PAID → PAID → CLOSED_POSTED`, plus terminal `CANCELLED` and admin-only `VOIDED`.

Key transition guards:
- **SR_OPEN → EST_DRAFT** requires: service location, customer phone, vehicle basics (make/model/year/color), and Plate+State OR No-Plate flag.
- **EST_DRAFT → EST_PRESENTED** requires ≥1 line item and computed totals; snapshots a "presented version"; line items lock (editing requires Revert to Draft with audit reason).
- **EST_PRESENTED → EST_APPROVED**: signature gate (> $200); approved estimate becomes immutable.
- **ON_SCENE → WORK_IN_PROGRESS**: hard gate — estimate must be approved before work starts.
- **WORK_IN_PROGRESS → WORK_COMPLETE_PENDING_SIGNOFF** requires completion notes; if parts installed, warranty fields captured (part, date, serial/lot).
- **READY_TO_INVOICE → INV_DRAFT** requires plate+state (or no-plate path).
- **INV_DRAFT → INV_ISSUED**: VIN + plate/state + variance signature test; invoice number assigned, PDF generated, delivery logged.
- **PAID → CLOSED_POSTED**: receipt generated + tax bucket assigned; creates accounting entries; everything read-only except admin notes.
- Cancellation: allowed from INTAKE through ON_SCENE with required reason (trip/cancellation fee logic optional from DISPATCHED/ON_SCENE — may still require invoicing the fee). After INV_ISSUED never "cancel" — use VOIDED + reissue or credit/refund.
- Change Order sub-machine: `CO_DRAFT → CO_PRESENTED → CO_APPROVED → APPLIED`; only allowed in ON_SCENE / WORK_IN_PROGRESS.

### 4.3 Settled split-status model *(ChatGPT: "Service Request Lifecycle" — later, final)*
Rather than one giant enum, statuses split per object:
- **SR (operational):** `NEW → DISPATCHED → ARRIVED → IN_PROGRESS →` end state `COMPLETED` | `ATTEMPTED_UNSUCCESSFUL` | `CANCELLED`. SR editable at every stage.
- **Estimate:** `DRAFT → SENT → SIGNED (locked)`, plus `VOIDED`. Unique `(service_request_id, version)`.
- **Work Order:** `OPEN → CLOSED`; core fields `attempted` (bool) + `outcome_code` (COMPLETED, ATTEMPTED_UNSUCCESSFUL, CUSTOMER_NO_SHOW, UNSAFE, TOW_REQUIRED).
- **Invoice:** `DRAFT → ISSUED (locked)`, plus `VOIDED`; `PAID` optionally derived from payments.

(An intermediate flat SR enum was also drafted — INTAKE, SR_OPEN, ESTIMATE_DRAFT ... UNABLE_TO_COMPLETE — superseded by the split model above.)

Side exits acknowledged in the lifecycle: Declined, Canceled, No Show / Unable to Access Vehicle, Unable to Complete (document why; **trip/diagnostic fees may still be invoiced**), Voided Invoice / Refund / Chargeback.

---

## 5. Intake / Lead concept *(ChatGPT: "Life stages of request")*

Jason's confirmed understanding: **"holding a SR in intake is a service lead but not yet an official request."**

- **Save as Intake** creates a Job in `INTAKE` status: partial info allowed (first/last name, phone as digits with masked input, partial location, vehicle basics, plate+state or No-Plate flag, notes/photos). No estimate started; dispatch/work/invoice/payment actions are blocked.
- Use cases: "I'll call you back" price shoppers, missing key info, follow-up paper trail, lead funnel separate from active work.
- The only forward action from INTAKE is **Convert to Service Request** (`INTAKE → SR_OPEN`), after which the job enters the mandatory estimate workflow.
- In the later schema this became an `intakes` table with statuses `NEW, CONTACTED, CONVERTED, CLOSED`.

---

## 6. Pages / screens inventory *(ChatGPT: "Life stages of request")*

Global nav on nearly every page: Dashboard, Jobs, Customers, Vehicles, Catalog, Audit Log, Settings. The 21 pages (collapsible — several can become tabs inside the Job Details hub, leaving Dashboard, Job List, New Job, Customers, Vehicles, Catalog, Settings truly separate):

1. **Dashboard** — quick actions (New Job, Active Jobs, Take Payment) + status counters (Open / Dispatched / In Progress / Ready to Invoice / Unpaid).
2. **New Job Intake** — first/last name, masked phone `(xxx) xxx-xxxx`, location, issue type, vehicle basics, plate+state/No-Plate; buttons: Cancel, **Save as Intake**, **Create Service Request**, Find Customer (prefill from existing).
3. **Job List** — filter by lifecycle state; search by phone digits, plate, invoice #.
4. **Job Details (SR hub)** — the main workspace: status, timeline, audit breadcrumbs, customer/vehicle snapshots, linked docs; action buttons enable/disable by state (Start Estimate, Dispatch, Arrived, Start Work, Create Invoice, Take Payment, Post to Ledger, Cancel Job pre-invoice only).
5. **Estimate Builder** — catalog picker, line items, totals/disclaimers; Save Draft / Present; Discard Draft reverts job to SR_OPEN with audit.
6. **Estimate Presentation & Approval** — read-only presented snapshot; approval method capture; signature screen when > $200; stores signer first/last + signature. Back = "Revert to Draft" (reason + audit).
7. **Dispatch / En Route** — dispatch timestamp, ETA notes.
8. **On Scene** — arrival, quick notes/photos; Start Work gated on approved estimate.
9. **Work Order Editor** — labor entries/timers, parts used with **inventory decrement**, warranty fields for installed parts, completion notes/media.
10. **Completion Sign-off** — signature capture (first/last + signature) or controlled "Customer Not Present" bypass (reason + evidence, audit flagged).
11. **Change Order Builder** — only ON_SCENE / WORK_IN_PROGRESS; draft → present → approve → apply, then returns to Work Order Editor.
12. **Invoice Draft** — generate from WO + applied COs; validate plate/state/no-plate; shows variance delta/threshold and whether variance signature required; VIN entry/lookup area.
13. **Invoice Issue & Delivery** — issues (locks) invoice, generates PDF, logs delivery method/time.
14. **Take Payment** — partial or full against issued invoice; method/amount/processor refs; auto-generates receipt.
15. **Receipt Viewer** — view/print/download; delivery log.
16. **Closeout / Post to Ledger** — posts paid job to accounting buckets, marks CLOSED_POSTED, reconciliation metadata.
17. **Customers** — split first/last names, masked phone input, dedupe/search by phone digits.
18. **Vehicles** — VIN management (vehicle creation gated by VIN rules); plate+state lookup helper.
19. **Catalog Management** — services/parts/fees, taxability flags, default rates.
20. **Audit Log Viewer** (admin) — per-job append-only trail (transitions, signatures, overrides, voids/refunds) + global search.
21. **Settings** — tax rates, invoice numbering, signature requirement toggles, phone mask behavior.

A static HTML/CSS/JS UI prototype ("Roadside Admin") was generated as a zip at the end of that chat (sidebar nav: Dashboard, Jobs, Customers, Vehicles, Catalog, Audit Log, Settings).

---

## 7. Parts & Service Catalog *(ChatGPT: "Service Request Lifecycle")*

**Design:** a single unified `catalog_items` table. Full model supports types `PART / SERVICE / FEE / BUNDLE`; **V1 (chosen starting point)** simplifies to `SERVICE | PART` only.

### V1 catalog item fields (settled)
- Shared/required: `item_type`, `active`, `name`, `description_public` (prints on estimate/invoice), `category`, `unit` (FLAT | HOUR | EACH | MILE), `default_qty` (> 0), `unit_price` (≥ 0), `taxable`; optional `sku` (auto-generate supported).
- SERVICE-only (optional): `default_labor_minutes`. Services cannot toggle warranty in V1.
- PART-only (optional): `manufacturer`, `part_number`; warranty: `warranty_applies` + `warranty_days` (> 0 when applies) + `warranty_notes` — **parts only** in V1.
- Catalog is a data-entry accelerator; documents always snapshot (see §1).

### Advanced/V2 catalog fields (deferred, for later)
Price modes (FIXED/VARIABLE/QUOTE_REQUIRED), discountable, labor min/max & billing units, labor rate modes (catalog fixed vs global hourly vs custom), requires_vehicle/location/photos flags, mileage pricing behavior (NONE / ADD_MILEAGE_ITEM / INCLUDED_UP_TO_X), after-hours multiplier, minimum charge, checklist templates, inventory tracking (qty on hand, reorder points, bins), fitment mapping, bundles (FIXED or SUM_OF_CHILDREN pricing, auto-expand).

### Catalog entry UI (settled)
One "Catalog Item" screen with a SERVICE/PART type toggle; live preview of how the item renders on an estimate/invoice; saved-items list for edit. Planned data-entry upgrades: Save & Add Another, category quick-add, duplicate last item, vendor picker, multiple vendors per part (V2).

### Vendor sourcing — decision
Jason chose: **vendor is selected from a `vendors` table (FK `vendor_id`), not free text.** Per-part vendor source info: `vendor_part_number`, `vendor_url`, `vendor_unit_cost` (internal, for margins). V1 may inline these on `catalog_items` for speed, but the recommended structure is a `vendor_sources` table (`catalog_item_id`, `vendor_id`, part #, URL, unit cost, `is_preferred`) which cleanly extends to multiple vendors per part in V2. If a vendor is selected, UI advises (non-blocking) adding a vendor part # or URL. A small "Manage Vendors" panel reachable from the catalog flow was planned.

---

## 8. "Paid for the attempt" policy & customer-facing copy *(ChatGPT: "Service Request Lifecycle")*

**Policy: Jason gets paid for the attempt** (e.g., jump start that doesn't start the vehicle is still billable).

Implementation: work order records `attempted = true` with an outcome code (e.g., `ATTEMPTED_UNSUCCESSFUL`); the invoice still carries the service/attempt-fee line item regardless of outcome.

Chosen customer-facing description style for Jump Start (best-default option): **"Jump start attempt (12V). Includes connection and start attempt; service fee applies regardless of outcome."** (Alternates kept the same "fee applies regardless of outcome" language; goal was clarity without legal-disclaimer tone.)

---

## 9. Database schema — tables + required fields (V1 full list) *(ChatGPT: "Service Request Lifecycle" — final deliverable of the chat)*

Jason asked for "all the tables listed and the required fields for each," delivered as an MD file. Conventions: every table has `id`, `created_at`, `updated_at`.

| Area | Tables | Notes / key required fields |
|---|---|---|
| Auth | `roles`, `users` | Roles enum: **ADMIN, DISPATCH, TECHNICIAN**. Users: role_id, unique username + email, password_hash, is_active. |
| Customers | `customers`, `customer_addresses` (optional) | first_name, last_name, phone (UI enforces `(xxx) xxx-xxxx`); email recommended. |
| Vendors | `vendors` | name required; phone/email/website recommended. |
| Vehicles | `fleet_vehicles`, `vehicles`, `vehicle_customers` | Two distinct concepts: **fleet vehicles** (WKR's own trucks — VIN, plate, plate_state required) vs **customer vehicles** (VIN unique + **required to create record**; plate recommended). `vehicle_customers` join table: vehicles may belong to multiple customers, `relationship_type` OWNER/DRIVER/FLEET_MANAGER. |
| Leads | `intakes` | status NEW/CONTACTED/CONVERTED/CLOSED; caller name, phone, problem summary. |
| SR | `service_requests` | status, customer_id, problem_description, **vehicle snapshot at SR time** (year/make/model/color — required even without VIN), `service_location_text`; nullable `vehicle_id` (must exist before invoice issue), dispatch/arrive/complete/cancel timestamps, latest-doc pointers. |
| Catalog | `catalog_items` | See §7. |
| Sourcing | `vendor_sources` | catalog_item_id (PART only) + vendor_id required. |
| Warranty | `catalog_warranties`, `installed_part_warranties` | Catalog default (`warranty_days` per PART) vs per-job instance (SR + catalog item + warranty start/end dates; serial number, WO/invoice links recommended). |
| Estimates | `estimates`, `estimate_line_items` | version unique per SR; status DRAFT/SENT/SIGNED/VOIDED; subtotal/tax_total/total; signed_at/signed_by_name when signed. Line items snapshot fields + `line_type` (SERVICE/PART/FEE/DISCOUNT). |
| Work Orders | `work_orders`, `work_order_line_items` (optional) | attempted bool + outcome_code required. |
| Invoices | `invoices`, `invoice_line_items` | status DRAFT/ISSUED/VOIDED; `invoice_number` unique required at issuance; issuance-time requirements: issued_at, vehicle_id, plate_number, plate_state, no_plate_flag; authorization signature fields nullable. |
| Money | `payments`, `payment_receipts` | payments: invoice_id, amount, payment_method (CASH, CARD, ACH, ZELLE, ...), payment_date; receipts: payment_id, unique receipt_number, receipt_date. |
| Audit | `sr_events` | append-only: event_type (DISPATCHED, ARRIVED, NOTE_ADDED, ATTEMPTED, COMPLETED, CANCELLED...), timestamp, optional payload_json. |
| Signatures | `document_signatures` | polymorphic (document_type ESTIMATE/INVOICE + document_id), purpose enum (§2), signed_by_name, signed_at, signature_data. |
| Files | `attachments` | polymorphic entity_type (SERVICE_REQUEST/WORK_ORDER/ESTIMATE/INVOICE/PAYMENT), file_name, storage_key, mime_type. |
| Expenses | `expenses` | expense_date, amount, category (FUEL, TOOLS, PARTS, SUBSCRIPTIONS...); optional vendor/SR/receipt-attachment links. |

Flagged for V2/V3: `tax_rates` with per-line breakdowns, credit memos/adjustments, inventory/stock counts, provider networks/dispatch sources, SLA/pricing matrices by partner.

### Suggested routes (PHP page-routing style)
SR-scoped document routes, e.g. `GET/POST /service-requests/{srId}/estimate` (POST creates next version), `POST /estimates/{id}/sign`, `POST /service-requests/{srId}/invoice`, `POST /invoices/{id}/issue` (runs issuance gate), `POST /invoices/{id}/payments`, `GET /service-requests/{srId}/receipt`, plus `/catalog`, `/catalog/new`, `/vendors`.

---

## 10. Conventions, standards, and misc decisions

- **Names are always separate first and last fields** — Jason explicitly corrected the spec: "All names are separate first and last names." Applies to customers and signature signers. *(ChatGPT: "Life stages of request")*
- **Phone numbers:** masked input `(xxx) xxx-xxxx`, stored as digits; dedupe/search customers by phone digits; follows the app's existing phone-format guidance. *(both chats)*
- **Tax:** V1 keeps a single configured tax rate; `taxable` boolean per line item.
- **V1 simplicity notes:** locking logic in the application layer first (DB constraints later); inline vendor fields acceptable for speed but `vendor_sources` preferred.
- **TypeScript in the PHP app:** confirmed workable — TS compiles to JS served as static assets; simple `tsc` output or Vite bundling into `/public/build/`; PHP renders pages, JS enhances (relevant to forms, catalog UI, signature capture). *(ChatGPT: "Service Request Lifecycle")*
- **Mapping template for agents:** every screen→DB implementation uses a fill-in record per field (UI Field / DB Column / Type / Required / Validation / Default / Notes-Snapshots), so generated forms can adapt to the existing system rather than assume table names. *(ChatGPT: "Service Request Lifecycle")*
- **Acceptance criteria** for the lifecycle model: SR editable throughout without changing signed/issued totals; estimate v1 unchanged after signature (v2 for changes); invoice unissuable without a vehicle record; attempted-but-unsuccessful outcomes still invoiceable; post-issue invoice edits prevented with a correction path.
- Warranty tracking is most meaningful at the **installed-part** level (per job/work order), with catalog-level defaults as convenience.
- Project name used in generated docs: "Indie Roadside Admin" / "Roadside Admin" (working titles in the ChatGPT artifacts, not a product-name decision).
