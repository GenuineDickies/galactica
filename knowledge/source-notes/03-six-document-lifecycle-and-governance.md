# Product-3 Bin Notes — Product & Requirements Domain

Sources (4 conversations, in chronological order of activity):

1. **"Roadside assistance app"** (ChatGPT, Aug 2025) — earliest vision spec; Python/FastAPI attempt, abandoned
2. **"PRD for MVP app"** (ChatGPT, Sep 2025) — no-security MVP PRD; origin of the six-document lifecycle
3. **"Admin platform development breakdown"** (ChatGPT, Oct 2025) — consolidated requirements doc; UI design iteration
4. **"App Development Project Plan"** (ChatGPT, June 2026) — project governance, documentation-first rule, agent steward system

These four chats trace the product's evolution: early Python vision → PHP no-security MVP with six distinct documents → consolidated requirements (VIN-before-invoice, pricing tiers, signature capture) → a formal documentation-governed project with named AI steward agents. Where positions changed over time, both the early and final states are noted.

---

## 1. The Six-Document Lifecycle (core product decision)

### Jason's explicit decision (from "PRD for MVP app," Sep 2025)

When the AI suggested collapsing documents into fewer tables (industry "Pattern A"), Jason explicitly rejected consolidation and declared the six-document model with ownership, in his own words:

- **Service Request** — First Name, Last Name, Nature of Service, Year, Make, Model, Color, Location, Phone Number — **Dispatch owns**
- **Service Order** — Line items and quoted prices — **Dispatch owns**
- **Work Order** — Actionable items provided to Technician; where work performed is recorded — **Technician owns**
- **Invoice** — Final pricing recorded here; sent to customer for payment
- **Payment** — Record of any payment made by the customer toward the invoice
- **Receipt** — Proof of payment provided to customer for their records

Quote: *"I just like having the distinction of each."* — Each document is first-class in both the UI (sidebar maps 1:1 to the six docs) and the database (separate tables: `service_orders`, `service_order_items`, `work_orders`, `work_order_items`, `receipts`, plus existing `invoices`, `invoice_items`, `payments`), even though the AI noted it could be modeled as states of one record.

### Later terminology shift (from "App Development Project Plan," June 2026)

By mid-2026 the lifecycle is consistently stated as:

**New Request → Customer → Vehicle → Estimate → Approval → Work Order → Completion → Invoice → Payment → Receipt**

i.e., **"Service Order" was superseded by "Estimate"** (with an explicit customer Approval step inserted between Estimate and Work Order), and Completion Reports appear as their own artifact. The module list in that plan: Intake, Customers, Vehicles, Service Requests, **Estimates**, Work Orders, Dispatch, Completion Reports, Invoices, Payments, Expenses and Purchases, Catalog, Accounting, Documents and PDFs, Communications, Reports, Settings and Integrations.

The June 2026 documentation baseline records an expanded internal **document lifecycle code chain** (owned by the "Lena" document steward):

`INT → SER → EST → EAP → WOR → DSP → COS → SCR → INV → PAY → RCT → PTW`

(intake, service request, estimate, estimate approval, work order, dispatch, completion of service, scope change request, invoice, payment, receipt, paid-through/warranty — final expansions per steward doc; the codes themselves are the settled artifact). Document numbering/versioning standard: **`DOC-YYYYMMDD-###-V#`**.

### Per-document rules (from "PRD for MVP app," final resolution)

- **SR statuses:** `NEW → REVIEWED → APPROVED → CANCELLED` (earlier draft used PENDING/IN_PROGRESS/COMPLETED/CANCELLED)
- **SO statuses:** `DRAFT → AUTHORIZED → READY_FOR_DISPATCH`; line items only from Catalog (no free-typed items); quoted prices editable per item; technician optional at SO creation but required before dispatch
- **WO statuses:** `DISPATCHED → IN_PROGRESS → TECH_COMPLETED → REVIEWED`; pre-populated from SO items; technician may add/remove/change quantities but changes tracked separately for audit; captures arrival/departure timestamps, actual services, parts used, notes/diagnostics, optional image filenames; Dispatch reviews/approves before invoicing
- **Invoice statuses:** `DRAFT → SENT → PAID / PARTIALLY_PAID → VOID`; sequential invoice number; links to SR, SO, and WO for traceability; items copied from WO, editable until posted; locked/immutable after posting (industry norm adopted: corrections via credit/rebill, not edits)
- **Payment:** methods `CASH | CARD | REFERRAL | OTHER`; multiple payments per invoice; invoice marked PAID when sum(payments) ≥ total; partial payments supported
- **Receipt:** auto-generated per payment with sequential receipt number; links to both invoice and payment; shows remaining balance; two variants — per-payment receipt and paid-in-full receipt when balance hits $0
- **Numbering:** separate sequence counters for SR, SO, WO, Invoice, and Receipt numbers (a `counters` table; industry norm of separate RO# vs INV# sequences)
- **Build dependency order** (for the task-management-AI PRD): Database + seed → SR → SO → WO → Invoices → Payments → Receipts → Reports

---

## 2. Key Field & Schema Decisions

### Customers ("PRD for MVP app")
- Required: first_name, last_name, phone in format `(xxx) xxx-xxxx` (masked input)
- Optional: email, address (street, city, state, zip)
- **De-duplication by phone**: on create, same phone → suggest linking to existing customer. (Reaffirmed in June 2026 plan: "Search for an existing customer by phone… handle inherited or reused phone numbers.") Customer lookup by phone/email happens at Service Request; only create new if no match.

### Vehicles — evolution of the VIN rule
- **Aug 2025 ("Roadside assistance app")**: VIN required; plate/state also captured; year >= 1886; registration expiry and insurance policy fields; vehicle reassociation across multiple customers and jobs.
- **Sep 2025 ("PRD for MVP app")**: **VIN explicitly not required for MVP.** Vehicle created **only** when license_plate + state present; **uniqueness = (license_plate, state)**; fields: make, model, year, color, plate, state, notes; vehicle belongs to one primary customer (N:1). Rule: **vehicle must exist (plate+state) before invoice creation.**
- **Oct 2025 ("Admin platform development breakdown") — final position**: **VIN required *before* an invoice can be created** (enforced by DB trigger in the sketched schema); plate+state captured at request time; **`no_plate` flag supported**. VIN decode integration planned, with manual entry fallback if decode fails.

### Users / Roles — evolution
- Aug 2025: Admin (full), Dispatch (service requests, customers, payments), Driver/Technician (vehicle details, job statuses, assigned jobs), optional future Customer portal.
- Sep 2025 decision (Jason): *"We do need some users… just not for security. We need Admin, Dispatch, Technician"* — roles exist **only for assignment and attribution** (labels, not enforced permissions). Technician must exist and be ACTIVE before an SR can be assigned/move out of PENDING. User fields: first/last name, phone, email (optional), role enum (ADMIN/DISPATCH/TECHNICIAN), status (ACTIVE/INACTIVE), notes.
- Oct 2025 onward: platform framed as **single-user (owner only)** — "no RBAC for v1; secure login only." June 2026 plan retains solo-operator framing throughout ("solo-operator roadside assistance business platform," one-person operating from a phone).

### Catalog ("PRD for MVP app" + "Admin platform development breakdown")
- **Catalog-first operations**: all line items on every document selected from the Products & Services catalog — no ad-hoc/free-typed item names. "+" button opens a catalog overlay/modal with search + type filter; selecting fills unit_price = default_price.
- Catalog item fields: sku, name, type (SERVICE/PART), default_price, tax_code, warranty_days (optional), description, active flag.
- Seed catalog with example prices (MVP seed data, illustrative): Jumpstart $75; Tire Plug $45 (punctures ≤1/4"); Tire Patch $65 (internal, demount required); Battery Install $80 (90-day labor warranty); Fuel Delivery $85 (up to 2 gallons); Mechanic Labor $120/hr; Replacement Tire $150 (365-day warranty). (An Oct 2025 mockup used different figures — Jump-Start $85, Lockout $95, Fuel $75, Tire Change $105 — treat all as placeholder pricing, not authoritative rates.)

### Money, tax, totals
- Live subtotal always shown on line-item tables; standing disclaimer everywhere quotes/invoices appear: **"Final invoice may vary based on changes in job scope."**
- Tax: global percent in `settings` (`TAX_PERCENT`, seeded 0.00); taxable items flagged; jurisdiction rate in settings (Oct 2025).
- **Tips**: stored separately as `tip_cents`; **not taxable** (Oct 2025).
- Discounts: percentage or fixed; applied before tip; displayed as separate row.
- Money stored in cents (`total_cents`, `amount_cents`) in the Oct 2025 schema sketch; PDFs and UI must always show **"$" with two decimals**.

### Job source / bulk referrals
- Every job classified `RETAIL` or `BULK`; BULK requires referral_source (and later partner PO/job ID); invoices for BULK bill-to the partner; payment method REFERRAL indicates settlement via the partner (net terms/remittance cycles). Reporting: jobs by source.
- **Pricing tiers** (Oct 2025): Retail = dispatch fee + mileage + time + catalog services + parts markup; Provider ("bulk") = negotiated base (e.g., flat), capped mileage, volume discount matrices by monthly job count.

### Warranty tracking
- For installed parts: warranty start/end dates, part reference from catalog, `warranty_days` on catalog items. Warranty claims validated against original invoice line warranty dates on new SR/WO. Nightly warranty checks listed as a cron job (Oct 2025).

---

## 3. MVP Strategy Decisions ("PRD for MVP app," Sep 2025)

- **Deliberate decision: build the MVP with NO security** — no auth, no roles enforcement, no CSRF, no hashing, no input validation beyond basic types. Jason: *"I need this built with no security in mind… then we will secure the app."* Security postponed to v1.2; notifications/API integrations to v2.0.
- Local-only deployment: XAMPP, PHP 8.2 (OOP), MySQL 8+ (InnoDB, utf8mb4), Tailwind, vanilla JS, minimal custom MVC, no middleware. Manual testing only for v1.1.
- MVP reporting (minimal): revenue by date, expenses vs revenue, top services sold, jobs by source (RETAIL vs BULK), technician workload.
- Sidebar navigation (v1.0 PRD): Dashboard, Service Requests, Customers, Vehicles, Catalog, Invoices, Payments, Expenses, Reports, Settings, Users — later revised so the sidebar shows the six lifecycle documents distinctly.
- SR completion guard: requires ≥1 line item **or** a zero-dollar reason.
- A final "PRD for a task-management AI" was produced: module-by-module tasks, dependencies, and acceptance criteria for SR/SO/WO/Invoice/Payment/Receipt (acceptance test checklist: SR creation → SO with items → WO with performed items → Invoice with totals → Payment updates balance → Receipt shows correct data).

---

## 4. Tech Stack History ("Roadside assistance app," Aug 2025)

- Original vision prompt specified **Python backend** (SQLite, modular PostgreSQL later), FastAPI was delivered; the entire conversation then became Windows install troubleshooting (Rust/pydantic-core build failures → pinned pydantic 1.10.12; Python 3.13 incompatibility with SQLAlchemy → advised Python 3.10/3.11; flat-directory relative-import fixes).
- **Final decision in that chat: "stop, switch to a mysql database."** The Python direction was subsequently abandoned entirely; by Sep 2025 the stack is PHP 8.2.12 + MySQL 8 on XAMPP, which remains the stack in all later conversations (June 2026 plan: "structured modular architecture without forcing a heavyweight framework onto shared hosting"; deployment targets = local XAMPP **and** remote shared hosting).
- Lasting content from the Aug 2025 vision prompt (much survived into later specs): dark theme with neon blue/purple accents, soft shadows, rounded corners, **"push" effect buttons (no links for nav)**; sidebar navigation; mobile/tablet responsive; audit logs viewable by Admin; dashboard quick stats (jobs today, open requests, overdue invoices, top customers) and charts (service types used, job time distribution, monthly revenue); settings for company profile, service type customization, tax/discount rules; UX influences: Stripe Dashboard, Notion dark UI, Linear.app.

---

## 5. Consolidated Requirements & Non-Negotiables ("Admin platform development breakdown," Oct 2025)

The opening breakdown document captured the then-settled requirement set ("Product Goals & Non-Negotiables"):

- Single user (owner only); secure login only, no RBAC v1
- Service flow: Service Request → Service Order → Work Order → Invoice → Payment → Receipt
- VIN required before invoice; plate+state at request; `no_plate` flag
- Customer lookup by phone/email at SR; create only if no match
- Catalog-driven line items; "+" opens catalog overlay
- Live subtotal + scope-change disclaimer
- Retail vs Provider pricing tiers; discounts and volume matrices
- Accounting: invoices, payments, expenses, basic reports; reporting SQL views (v_monthly_revenue, v_outstanding_invoices)
- Warranty tracking with expiration/terms
- **PDF standards**: Dompdf; templates per doc type (Work Approval, Work Completed, Invoice, Receipt); currency always "$" + 2 decimals; metadata block (Invoice No., Date, Customer, Vehicle VIN/Make/Model/Year, Location); white header band with logo left / metadata right; warranty statement + "final total may vary" disclaimer on quote/estimate PDFs; signature images embedded as timestamped PNGs
- **Android Signature micro-app**: ultra-simple — Select Job → Capture Signature → Confirm & Upload; pulls assigned Work Orders via tokenized endpoint; canvas→PNG; used for **Work Approval** and **Work Completed** signatures attached to records
- Layout: 3-panel (header, sidebar, content) each scrolling independently; accordion sidebar nav
- Background cron jobs: nightly backups, warranty checks, overdue invoice reminders
- Architecture: PHP monolith, DDD-inspired layers (Presentation/HTMX + Tailwind; Application; Domain with value objects Money, VIN, PlateState, Phone and services PricingService/WarrantyService; Infrastructure with PDO repositories, migrations, Dompdf, local /storage)

### UI design preferences (settled through live mockup iteration, same chat)

Jason iterated a React dashboard mockup and landed on concrete visual preferences:

- Dark theme, but **must have enough contrast/color to distinguish layers** — flat dark-on-dark rejected
- Per-section **accent-tinted glass cards** (cyan/violet/amber/emerald/rose/sky tints, backdrop blur, accent top bar, soft glow) — "Ok... this I like"
- Subtle **texture** (dot-grid + diagonal hatch overlays, low opacity)
- Text inputs must be **highly visible**: thick borders, bright placeholders, neon focus glow, inline icons
- **Depth = sunken/concave input fields, NOT moving cards.** Jason explicitly stopped hover tilt/lift animation: *"Please stop the card movement… by depth I meant maybe you could have a lower depth for input fields."* Then asked for "more concave" — inset inner shadows, top-lip highlight. Hierarchy reads: page → glass cards → sunken fields.
- Final state of that mockup: **Dashboard only** (Service Request view removed on request). Dashboard contents: KPI tiles, 7-day revenue line chart, job board cards (statuses New/En-Route/On-Site/Done), live map preview, outstanding invoices table, recent activity feed; job IDs formatted like "WKR-1042."
- Go-live plan for the dashboard (delivered as .md): Next.js App Router + TypeScript on Vercel, pnpm, Tailwind + lucide + recharts + framer-motion, mock data first then swap to API, NextAuth later, Mapbox later. (Note: this was a plan for the standalone dashboard mockup, not the PHP app.)

---

## 6. Project Governance & Process ("App Development Project Plan," June 2026)

This is the most recent conversation and establishes how the project is run.

### Governing rule (Jason's explicit directive)
*"It is more important that documentation be kept accurate and current than code be written."* Adopted as: **"Documentation is the system of record. Code is only an implementation of the current documentation."** No feature coded until requirements/rules/data effects/acceptance criteria are documented; changed decisions update docs before code; contradictions resolved in docs, not improvised; every code change cites the doc sections it implements; agents must never treat existing code as authority over approved documentation.

### Authority model
- **Jason White — Product Authority**: final authority over business policy, priorities, exceptions, and approval of material changes. No agent may invent or silently approve business policy.
- **Mara Vale — Project Manager and Documentation Governor** (portable AI PM personality, recorded as decision **DEC-021**): coordinates work, selects stewards, reconciles reviews, ensures docs updated before implementation; explicitly may not simplify documented requirements without approval or "treat the user like an approval vending machine."

### The ten document stewards (named AI review personas, stored as reusable profiles in an `agents/` directory)
| Steward | Domain |
|---|---|
| Clara — Curator | Documentation governance, terminology, decision log, versioning |
| Sophie | Product requirements, scope, roadmap, backlog, MVP boundary |
| Nora — Operator | Field operations, intake, mobile usability, interruption recovery |
| Marcus — Architect | Architecture, data model, module contracts, transactions, migrations |
| Evelyn — Comptroller | Accounting, posting matrix, payments, refunds, reconciliation, tax, periods |
| Rook | Security, privacy, audit, uploads, retention, threat model |
| Iris — Integrator | Square, Telnyx, OpenAI, VIN decode, maps, webhooks, fallbacks |
| Quinn — Examiner | QA, acceptance criteria, regression, release gates, defect severity |
| Damon | Deployment, environments, rollback, backups, monitoring, recovery |
| Lena | Business documents, PDFs, numbering, templates, signatures |

Invocation rules: single steward + Clara for narrow changes; multi-steward panels for cross-boundary changes (e.g., invoice/payment/tax behavior: lead Evelyn, mandatory Clara/Marcus/Quinn/Rook). Mandatory review order ends with Product Authority approval for material business-policy changes. Documentation baseline v0.1 dated June 8, 2026; v0.3 by end of conversation.

### Integrations (planned, settled list)
**Square** (payments, clearing/settlement reconciliation), **Telnyx** (SMS, customer approvals by SMS, status updates), **OpenAI** (document classification/intake, OCR review), **VIN decoding**, **maps/address assistance**. Core principle: workflow must remain usable when integrations fail — VIN decode failure → manual vehicle entry; SMS failure → signature approval; OpenAI failure → manual classification; Square outage → record payment as pending/manual. Each integration gets an adapter layer, idempotency keys, webhook signature verification, retry/failure queues, sandbox+production configs.

### Release plan (four releases)
1. **Operational core**: dashboard, new request, customer matching, location, vehicle/VIN, catalog, estimates, approval capture, work orders, completion, invoices, manual payments, printable PDFs, customer/vehicle records, basic reporting — goal: run a real job end-to-end.
2. **Financial operations**: chart of accounts, journal entries, revenue/COGS posting, expenses, purchases, vendors, Square reconciliation, sales tax, payment clearing, financial reports, document intake.
3. **Communications & automation**: Telnyx SMS, SMS approvals, status updates, message history, OpenAI classification, VIN decoding, maps, notification rules.
4. **Optimization & polish**: UI consistency, performance, advanced reports, search, mobile speed, backup/restore, export, diagnostics, accessibility.

### Peer-review revisions adopted (the plan was pressure-tested and restructured)
- Added **Phase 0 (Discovery)**: codebase audit, business-process map, accounting posting matrix, threat model, migration assessment, mobile prototypes, risk register, MVP boundary — before any coding.
- **Vertical slice first**: build one complete workflow (New Request → Customer → Service Selection → Estimate → Approval → Work Order → Completion → Invoice → Manual Payment → Receipt → Journal Entries) through every layer, rather than broad modules.
- **Accounting designed up front** even if UI comes later; posting matrix defined (e.g., Invoice issued: DR Accounts Receivable / CR Revenue + Sales Tax Payable; Square payment: DR Square Clearing / CR A/R; deposit: DR Checking / CR Square Clearing; fees: DR Processing Fees / CR Square Clearing; part used: DR Parts COGS / CR Parts Inventory). Posted journal entries immutable; corrections via reversing entries. Operational status kept separate from financial status. Explicit treatment required for refunds vs voids, credit memos, chargebacks, core deposits, partial/over/unapplied payments, failed payments, locked periods.
- **Field resilience is not optional**: autosave drafts, resume-work, duplicate-submission prevention (estimate/invoice/payment/completion), mobile-first core pages, one-handed primary actions, delayed photo/signature upload, sync status, printable emergency job form. (Full offline mode deferred.)
- Measurable UX targets: find existing customer ≤10s; capture basic request ≤60s; estimate without re-entering data; record completion ≤2 min; record payment in ≤3 taps from invoice.
- Permanent regression scenario set based on actual services: jump start, tire change, lockout, fuel delivery, mobile mechanic diagnosis, battery replacement, estimate requiring approval, scope change requiring reauthorization, no-vehicle service, provider-dispatched service, failed/refunded payment.
- Security built per-feature, not a final phase; measurable success criteria include: complete a job intake→receipt with no external paperwork, no duplicate data entry between documents, all invoice totals trace to line items, balanced postings, customer history by phone number, standard job completable from a phone, integration failures don't block core ops, restorable backups.
- Living records maintained: decision log, risk register, change log, issue log. Launch is gradual/parallel with the current process before full transition.

---

## 7. Cross-Conversation Evolution Summary (final resolutions)

| Topic | Early position | Final position |
|---|---|---|
| Stack | Python/FastAPI/SQLite (Aug 2025) | PHP 8.2 + MySQL 8, XAMPP + shared hosting (Sep 2025 onward) |
| VIN | Required (Aug 2025) → not required for MVP (Sep 2025) | **Required before invoice**; plate+state at intake; `no_plate` flag (Oct 2025+) |
| Second lifecycle doc | "Service Order" (Sep 2025) | **"Estimate"** with explicit customer Approval step (2026) |
| Users | Multi-role with permissions (Aug 2025) → role labels, no enforcement (Sep 2025) | Single-user/owner-only platform; secure login only (Oct 2025+) |
| Security | Explicitly none in MVP (Sep 2025) | Built into every feature per governance plan (June 2026) |
| Document model | Could be states of one record (AI suggestion) | **Six distinct first-class documents** (Jason's standing decision) |
| Process | "Just build it" prompts | Documentation-first governance, Product Authority = Jason, steward review panels (June 2026) |

Constants throughout: dark neon UI with push-effect sidebar buttons; catalog-only line items; phone-based customer dedup; plate+state vehicle uniqueness; the scope-change disclaimer; RETAIL vs BULK job sourcing with referral/partner billing; "$"-formatted currency; partial payments with per-payment receipts; separate number sequences per document type.
