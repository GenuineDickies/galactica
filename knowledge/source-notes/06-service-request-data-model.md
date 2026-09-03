# Product & Requirements Notes — Bin product-6

Distilled from 16 exported conversations (ChatGPT / Claude / Gemini). Focus: product definition, service request data model, catalog structure, service descriptions, workflow, and PRD-level requirements for the WKR dispatch-to-cash admin app.

---

## 1. App Identity & Positioning

**Source: "App Intro Development Help" (Jan 2026 — final resolution)**

Working name in this era: **Indie Roadside Admin**. Final approved intro copy (Option A, revised three times):

> A single-operator back-office app for running a roadside assistance / mobile mechanic business end-to-end. It turns every call into a clean, traceable job record — **Service Request → Estimate → Approval → Work Order → Dispatch → Change Order → Service Report → Invoice → Payment → Receipt** — with catalog-based line items, built-in pricing rules, and customer signature capture when approvals are required.

Key positioning decisions from the revision cycle:
- **Minimize redundant data entry** is a headline feature: customer/vehicle info flows forward through the workflow automatically.
- **Plate-to-VIN lookup** (via a secondary vehicle-data API) auto-populates VIN and vehicle details.
- Every step produces **printable, professional PDF documentation** automatically.
- Chargeback defense is a *byproduct of good records*, not the stated purpose. Final wording: a **one-click response packet** that "consolidates customer acceptance documentation with supporting location and device data." (Jason explicitly rejected framing the app as "built specifically to challenge chargebacks," and pared metadata language down from "device ID, geolocation, and IP address" to "location and device data.")

**Source: "App summary creation" (Sep 2025)** — earlier one-page summary: purpose is a digital platform for a one-person roadside business (scalable later) managing service requests, vehicles, customers, invoices, payments, accounting, and parts/service tracking in one system.

---

## 2. Workflow / Document Lifecycle

Two lifecycle formulations appear; the later one (App Intro, Jan 2026) is the fuller version:

- Full: Service Request → Estimate → Approval → Work Order → Dispatch → Change Order → Service Report → Invoice → Payment → Receipt
- Earlier / condensed ("App summary creation", "Major app features"): Service Request → Service Order → Work Order → Invoice → Payment → Receipt

Document ownership ("App features and improvements"):
- **Service Order** — dispatch-owned; contract between company and customer; statuses Pending, Dispatched, Completed, Cancelled, Invoiced; line items from catalog for quoted pricing.
- **Work Order** — technician-owned; actual work performed, parts used/installed, VCR pre/post image uploads for liability, technician notes.
- Service Request statuses: **Pending, Accepted, Completed, Cancelled, Rejected**; automatic duplicate-customer check on creation.

---

## 3. Service Request Data Model (most detailed spec in this bin)

**Source: "Service Request Data Collection" (Jun 2026 — latest, treat as authoritative)**

Guiding rule: the Service Request answers *"Who needs what, where, for what vehicle, under what price expectation, and have they approved moving forward?"* Everything else belongs in downstream documents.

**Minimum required to create a Service Request:** customer first name, last name, phone, requested service, problem description, service location (address OR description/map pin), capability confirmed, price type, status, source, created timestamp. Fast intake — "not a DMV hostage situation."

Field groups (15 sections):
1. **Request identity** — SR ID, document number (format `SER-20260611-001-V1`), status, created/updated (Portland timezone), source (phone, web form, provider, repeat customer, Google Ads), created by.
2. **Customer** — split first/last name (never one full-name field), phone masked `(xxx) xxx-xxxx` (required), email optional, existing-customer match by phone during intake, **phone match override** ("use existing" vs. "create new anyway") with override reason (e.g., inherited phone number).
3. **Provider / third-party** — provider involved Y/N, provider name/contact/phone, reference (claim/job/case) number, end-customer name if different, **billing party** (customer / provider / other — required).
4. **Service requested** — service type, category (service, parts, fuel, diagnostic, provider job), short problem description in customer's words, urgency (normal, urgent, stranded, unsafe location), **capability confirmed** (can you actually do the job — required), estimate required Y/N, **price type** (Flat Rate / Starting At / Estimate Required), ballpark price given, diagnostic fee disclosed (mobile mechanic), service-allowed-without-vehicle (system-derived, e.g., off-vehicle mount & balance).
5. **Location** — address-first, map pin only when needed; lat/long stored internally, **never shown to user**; location description ("behind Walmart"), access notes (gate code, security desk), safety notes (highway shoulder, low clearance); "customer cannot provide address" flag enables pin/description flow.
6. **Vehicle** — lighter than invoice: year/make/model preferred not required, color, VIN conditional (invoice enforces later), plate + plate state optional, "No Vehicle Serviced" flag only for services that allow it, condition notes. **Decision: do not block SR creation on missing VIN**; flag it before invoicing if the service requires a vehicle.
7. **Tire-specific** (conditional) — position (LF/RF/LR/RR/spare), size, has spare + spare condition, locking lug nut present, damage description (blowout, puncture, sidewall, bead), repair type requested (plug/patch/replacement/change only), disposal needed.
8. **Fuel delivery** (conditional) — fuel type; **included gallons: passenger gas 2 gal, passenger diesel 5 gal, commercial diesel 15 gal**; extra gallons; ran completely out; commercial vehicle flag; fuel price disclosure.
9. **Battery / jump start** (conditional) — symptoms (no crank, click, lights on, totally dead), battery location (hood/trunk/under seat), needs battery test, replacement interest, customer has battery, access issue.
10. **Lockout** (conditional) — keys visible, vehicle running, **child/pet inside → emergency flag + advise 911/fire depending on severity**, proof of ownership/authorization needed (yes), ID verification notes.
11. **Pricing snapshot** — quoted amount or starting-at amount, estimate required, taxable (system-derived from catalog), discount, provider rate, customer approved price Y/N, approval method (SMS, verbal, signature, provider authorization), approval timestamp.
12. **Consent & communication** — SMS consent granted / source / timestamps / auto-end / revoked, do-not-contact, preferred contact method. Matters for SMS compliance and chargeback defense.
13. **Scheduling / dispatch readiness** — requested time (ASAP or scheduled), arrival window given (e.g., 45–60 min), accepted/rejected timestamps, cancellation reason, "Ready for Work Order" system flag once customer/location/service are valid. (Driver assignment removed from scope.)
14. **Internal notes & risk flags** — internal vs. customer-facing notes separated; risk flag (unsafe location, price dispute risk, ID concern), payment risk notes, repeat-customer and prior-issue flags (system-derived).
15. **Attachments** — photos, provider dispatch/authorization documents, customer screenshots, attachment notes.

---

## 4. Catalog Structure

**Source: "Automotive catalog field structure" (Jan 2026)** — recommended parts/service/fees catalog fields:
- **Core:** item ID/SKU, name/description, category/subcategory (parts, labor, fees, fluids), manufacturer/brand (OEM vs aftermarket), part number, unit of measure.
- **Pricing:** cost price, retail price, core charge (refundable deposit), labor rate, tax category (taxable / non-taxable / labor-only).
- **Fitment:** year range, make, model, engine/trim, position (front/rear, left/right).
- **Inventory:** stock status, qty on hand, reorder point, supplier, lead time.
- **Service-specific:** labor time (standard hours), service interval, required-parts list.
- **Other:** warranty info, superseded-by, interchangeable-with cross-reference, notes/specs, image reference.
- A fuller mobile-mechanic schema (code omitted in export) additionally covered: labor rates with emergency/after-hours/mobile surcharges; fees (dispatch, mileage, environmental); geographic service zones for mobile pricing; fluids/consumables; per-truck mobile-unit inventory; warranties; equipment requirements; price modifiers.

**Source: "Jumpstart Service Description" (Mar 2026)** — Unit-of-measure convention decided:
- **Flat-rate services → EA (Each)**; timed labor → HR (Hour); materials/fluids → gallon, quart, oz, each.
- A service order line looks like: Qty 1, UOM EA, Description "Jump Start Service", Rate, Total.

**Source: "Parts & Service Catalog Integration" (May 2026)** — plan: catalog integrates with accounting so income and expenses post to proper accounts, **posting only from approved business documents**; built as a coding-agent prompt followed by a codebase-inspection/file-by-file plan step before writing code. (Full prompt text omitted in export.)

---

## 5. Service Descriptions & Billing Policy

**Source: "Jump start service descriptions" (Dec 2025 — final resolutions)**

Core policy decision: **payment is for the attempt, not the success.** Final short attempt-based catalog descriptions:
- **Jump Start (Attempt-Based):** "Dispatch and attempt to start vehicle with jump equipment; success not guaranteed."
- **Lockout (Attempt-Based):** "Dispatch and attempt to unlock vehicle using entry tools; success not guaranteed."
- **Tire Change (Attempt-Based):** "Dispatch and attempt to replace flat with spare; success not guaranteed."

Policy language options generated (customer-friendly → firm) with placement guidance: one-line version under invoice totals; firmer "No-Guarantee of Start" version above the customer signature line on the work order. Also: if no attempt is possible due to blocked access/unsafe conditions, a dispatch fee may apply.

**Source: "Jumpstart Service Description" (Mar 2026)** — chosen customer-facing catalog description: "**Jump Start Service** — Mobile roadside service to start a vehicle with a weak or discharged battery. Includes connection of jump equipment and a basic starting/battery check when possible."

---

## 6. PRD & Feature Set (historical baselines)

**Source: "Write PRD for app" (Aug 2025)** — early PRD for "White Knight Roadside Assistance Application":
- MVP scope: service request management, driver management, invoicing/payments, accounting/reporting, warranty tracking, product & service catalog. Timeline: MVP 3 mo; v2 (customer portal, messaging, notifications) 6 mo; v3 (advanced reporting/analytics) 9 mo.
- **VIN enforcement**: VIN must be captured before an SR can be marked completed or invoiced (driver's responsibility). *(Later softened at the SR stage — see §3 item 6.)*
- Roles: Admin (full), Dispatch (SRs, invoices, payments, reports, job assignment), Driver/Technician (assigned SRs, VIN entry, job status; no invoice edits or financials), Customer portal (future).
- Tech: PHP 8.2 + MySQL 8 (PDO), HTML/CSS/JS frontend, CSRF/input validation, GitHub. Non-functional: up to 100 concurrent users, encrypted sensitive data, backups/recovery.
- UI: dark theme, neon blue/purple accents, sidebar with buttons (not links), responsive, role-based views.

**Source: "Major app features" (Oct 2025)** — consolidated feature list for Indie Roadside Admin. Notable rules beyond the above:
- **Vehicle record requires VIN** (cannot be created from plate+state alone); **invoice requires Plate+State**; plate+state may be used to *look up* VIN (lookup only, not record creation).
- Catalog-driven line items only — **no free-text**; "+" button opens catalog picker; selecting auto-adds the item.
- Pricing: retail price list (tire change, plug, patch, mount & balance, jumpstart, lockout, fuel, mechanic hourly, diagnostic); **provider/bulk pricing with volume-based matrices** (0–10 jobs = retail; monthly projected volume; recalculated first weekday of each month); tips policy and partner-rate governance.
- Accounting: taxes/subtotals/totals, PDF invoices, payments incl. tips, expenses, simple reports; items with costs post to expenses; revenue vs. income categorization.
- Warranty tracking for any installed physical part, tied to service records.
- V1 explicitly excludes messaging, notifications, document processing (v2).
- Dev governance doc set: AGENTS.md, agent-rules/, project-brief, architecture, style-guide, naming-conventions, requirements, traceability-matrix (tasks → features → files), runbook, entry-summary.

**Source: "App features and improvements" (Sep 2025)** — additions not repeated elsewhere:
- Customer↔vehicle is many-to-many (customer owns multiple vehicles; a vehicle can belong to multiple customers). Unique-vehicle validation at that time used License Plate + State. *(Superseded by VIN-required rule above.)*
- ETA logic and auto-escalation rules planned; VCR pre/post images on work orders; disclaimers on invoices ("pricing may vary"); audit trails.
- Suggested integrations: Stripe/Square/PayPal, Google Maps (routing, reverse geocoding), Twilio/SendGrid (v2), QuickBooks/Wave (optional), vendor warranty-lookup APIs (future).
- UI: 3-pane layout (sidebar nav / header quick actions + counters / main content), collapsible button-based sidebar, push-effect neon buttons, inline fuzzy search/filtering, dark+neon default with optional light mode.

**Source: "App summary creation" (Sep 2025)** — business rules snapshot: phone format `(xxx) xxx-xxxx`; vehicle info required at SR creation; SR disclaimer "Final invoice may vary due to job scope changes"; job images use structured filenames + classification tags (pre/post job, waiver, signature); settings section stores API credentials for plate→VIN lookup (low-cost/free preferred).

---

## 7. Miscellaneous

- **"Location Permission Service Request Form" (Gemini, Oct)** — SR web form uses browser geolocation: a "Use My Current Location" button triggers the permission prompt and fills lat/long. Constraint learned: a site cannot force permission; if previously denied, the user must change it in browser settings (lock icon) — error messaging should say so explicitly.
- **"Typescript roadside assistance form" (Feb 2026)** — Claude built a TypeScript+React modular SR form as an *educational tool* for Jason as a novice programmer ("RoadRunner Admin" naming, dark command-center theme). Design targets worth keeping: sub-2-minute completion, 8 service types (towing, tire change, jump start, lockout, fuel, winch, mobile mechanic, other), 4 urgency levels (emergency/urgent/standard/scheduled), auto-save every 30 s to localStorage, modular per-section components, real-time validation. Mostly a learning exercise, not the production stack (production is PHP/MySQL).
- **"Roadside Assistance Workflow Plan" (Gemini, Jul)** — research-plan outline covering order lifecycle, dispatch models, CRM, accounting, inventory/warranty, payment APIs; report content not in export. No decisions captured.
- **"API assistance request" (Aug 2025)** — generated `wkr_live_`/`wkr_test_` prefixed API keys plus a PHP Bearer-token middleware pattern; rotation practice: overlap old+new keys, log key prefix only. (Keys in the export should be considered burned/rotated.)
- **"Clarifying JSON to HTML Formatting"** and **"Memory Export Request"** — no lasting product content (meta-conversations; export code block omitted).

---

## Cross-cutting terminology & conventions (as they appear in this bin)

- Names always split first/last; phone `(xxx) xxx-xxxx`; Portland timezone on timestamps.
- Document numbers: `SER-YYYYMMDD-###-V#` pattern for service requests.
- "Attempt-Based" is the standard suffix/framing for roadside service line items.
- Internal-only data (lat/long, internal notes, risk flags) kept strictly off customer-facing documents.
