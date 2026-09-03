# Product / Requirements Notes — Bin product-4

Distilled from 7 exported ChatGPT conversations (Aug 2025 – May 2026) covering document numbering, catalog/SKU design, fuel-delivery pricing, multi-location customer modeling, provider dispatch, forms, compliance policies, and regulatory research for White Knight Roadside (WKR).

---

## 1. Document Identifier / Numbering Schemes

### Final scheme (latest, May 2026) — *Document Identifier Parts*
Document numbers are built from **four parts**:

```
TYPE-YYYYMMDD-###-V#      e.g.  SER-20260513-001-V1
```

1. **TYPE** — document type code:

| Code | Meaning | | Code | Meaning |
|---|---|---|---|---|
| INT | Intake | | RCT | Receipt |
| SER | Service Request | | PTW | Parts Warranty / Warranty Certificate |
| EST | Estimate | | REF | Refund Receipt |
| EAP | Estimate Approval | | CRM | Credit Memo |
| WOR | Work Order | | STM | Customer Statement |
| DSP | Dispatch | | POR | Purchase Order |
| COS | Change of Service | | VBI | Vendor Bill |
| SCR | Service Completion Report | | GRN | Goods Received Note |
| INV | Invoice | | RMA | Return Material Authorization |
| PAY | Payment Record | | CAU | Customer Authorization |

2. **YYYYMMDD** — original issue date; **never changes on revision**.
3. **###** — daily sequence number per document type/date (001, 002, …).
4. **V#** — version/revision. Revising an estimate goes `EST-20260513-001-V1` → `-V2`; date and sequence stay fixed.

### Earlier/superseded numbering (for context)
- *Feature mapping explanation* (Aug 2025): `JOB-YYYYMMDD-####` and `INV-YYYY-#####`.
- *Handling multiple locations* spec (Sep 2025): invoice numbering by tenant code + year, e.g. `IRA-2025-000123`; invoice numbers unique **per tenant**, store numbers unique **within an account**.
- The TYPE-date-seq-version scheme above is the latest resolution.

---

## 2. Catalog / Item Identifier System (SKU vs Part Numbers)
*Source: Document Identifier Parts (saved to memory in-conversation as a decision)*

**Main rule: the internal SKU is separated from all external identifiers.** Your SKU identifies what *your business* sells/tracks; manufacturer/vendor part numbers identify what *someone else* calls it.

| Identifier | Used for | Example | Controlled by |
|---|---|---|---|
| SKU / Service Code | Internal sellable catalog item | `BAT-GRP65-STD`, `SVC-JUMP-STD` | WKR |
| Manufacturer Part Number | Brand's identity of the part | `65-DLG`, `BXT-65-650` | Manufacturer |
| Vendor Part Number | Supplier ordering/returns | `AUTOZONE-65DLG`, `NAPA-7565` | Vendor |
| UPC / Barcode | Retail scan code (field exists, no scanning yet) | `012345678905` | GS1/Mfr |
| Serial Number | One specific physical unit | battery serial | Manufacturer |
| Lot / Batch Number | Warranty/recall tracking | `LOT-24A17` | Manufacturer |
| Warranty ID | WKR warranty tracking | `PTW-20260513-001` | WKR |
| Catalog Item ID | DB primary key (system-generated, not shown as business ID) | `item_id = 183` | System |
| Line Item ID | One row on estimate/invoice | `line_item_id = 9021` | System |

**SKU format:** readable `CATEGORY-SUBCATEGORY-SPEC`. Canonical examples:

```
SVC-JUMP-STD          SVC-LOCKOUT-STD       SVC-TIRE-CHANGE
SVC-FUEL-GAS-2GAL     SVC-FUEL-DIESEL-5GAL  SVC-FUEL-DIESEL-COMM-15GAL
SVC-MOBILE-DIAG       LAB-MOBILE-HR         FEE-DISPATCH
BAT-GRP65-STD         TIRE-USED-225-65R17   MAT-FUEL-GAS-REG
MAT-FUEL-DIESEL       VALVE-STEM-STD        SHOP-SUPPLIES
```

Reading: `SVC-JUMP-STD` = Service – Jump Start – Standard; `BAT-GRP65-STD` = Battery – Group 65 – Standard.

### Simple inventory system spec (final decision: "It must be a simple system")
Jason explicitly rejected the enterprise version. The agreed build:

- **Item types (enum kept simple):** `service | part | material | fee | labor`.
- **catalog_items:** id, sku (required, unique), item_type, display_name, description, default_price, default_cost, manufacturer_name, manufacturer_part_number, vendor_id, vendor_part_number, upc_barcode, warranty_eligible, taxable, track_inventory, active, timestamps, archived_at (soft delete, never hard delete).
- **vendors (basic):** name, phone, email, website, address, notes, active. One default vendor per item for now; multi-vendor later.
- **inventory_stock:** quantity_on_hand, quantity_reserved, reorder_point, storage_location; `available = on_hand − reserved`; only for items with `track_inventory = true` (parts/materials, not services).
- **inventory_movements:** types `purchase | install | adjustment | return | loss`; never overwrite quantity silently — every change gets a movement record that updates on-hand.
- **Line items must snapshot catalog data** (sku, name, description, price, part numbers, warranty eligibility) so old invoices/estimates never change when the catalog is edited.
- **Ad-hoc items** allowed on estimates/invoices without forcing catalog entry; optional "save to catalog later".
- **Warranty:** only a `warranty_eligible` boolean now (snapshotted on line items); no certificates/claims yet.
- **Explicit exclusions:** no serialized-unit tracking, no RMA workflows, no barcode scanning, no purchase-order pages, and **no vehicle-requirement logic in the inventory module** ("do not try to track vehicle requirements" — that belongs to service workflow / VIN policy, not inventory).
- **Pages:** /catalog, /catalog/new, /catalog/{id}/edit, /vendors (+new/edit), /inventory, /inventory/movements. Seed only the starter SKUs listed above — do not invent a large catalog.
- Process rule for the coding agent: maintain `/docs/30-delivery/simple-inventory-system-todo.md` with Current Goal / Decisions / Files Touched / DB Changes / Completed / Next Steps / Tests / Issues.

---

## 3. Catalog Structure & "What Counts as Mechanical"
*Source: What Defines Mechanical*

### Mechanical definition (settled)
- Mechanical = operates through physical parts/forces/motion. **Moving parts are NOT required** — an alternator (moving) and an exhaust manifold (static) are both mechanical.
- **Tires: mechanical. Batteries: not mechanical** (electrochemical), though both interact with mechanical systems.
- Catalog tagging: parts get a Type tag of mechanical / electrical / consumable.

### Two-tree catalog (finalized and saved in-conversation)
1. **Services** (labor + fees — "what you do")
2. **Parts & Materials** ("what you sell/install/consume")

WKR's actual offerings: **tire change, tire replacement, jump start, lockout, fuel delivery, mobile mechanic.** Decisions:
- **No towing and no sublet/tow pass-through categories** — Jason does his own lockouts, does not offer or pass through tow service. The entire "Sublet/Tow" bucket was removed.
- Jump start is billed as **Jump Start (Attempt)** — charge for the attempt.
- Mobile mechanic: don't pre-list every repair. Start with **Diagnostic (Basic, flat)** + **Labor (Hourly)** + parts as needed; add fixed-price bundles (starter swap, etc.) later once real data exists.
- Fees are their own group applying across services: Trip Charge (base), Mileage (per mile after included radius), After-Hours/Emergency Surcharge, Wait Time (per 15 min), Cancellation/No-Show.
- Add a tags layer instead of deep folder nesting (billing unit, after-hours eligible, stocked/special-order/customer-supplied, warranty, core-required).

### Three item pricing classes
1. **Services** — stable, flat/hourly prices (the "ready to go" items).
2. **Standard Materials** — stable-ish low-cost consumables (valve stems, terminal protectant, shop supplies, disposal fee).
3. **Quoted Parts / Pass-through** — tires, batteries, auto parts. **Do not catalog every tire/part price.** Catalog placeholders (`QUOTE – Tire`, `QUOTE – Battery`, `QUOTE – Auto Part`, `PASS – Fuel per gallon`) with **no fixed sell price, only pricing rules**. At quote time capture: supplier, cost, core, markup rule → auto-calculated sell price; tire specifics (size, load/speed rating, brand, availability, approved substitute Y/N) go in line-item notes. Track **vendor quotes per job** (vendor_id, vendor_sku, cost, core_cost, shipping, quote_timestamp, markup rule, sell_price) rather than bloating the catalog. Supplier names need not be shown to customers.

**Recommended markup rule (suggested, leaning tiered):**
- Cost <$100 → ×1.60; $100–300 → ×1.40; $300–700 → ×1.30; >$700 → ×1.20; plus a minimum gross-profit floor (~$25 markup).

### Fuel delivery (firm decision, saved in-conversation)
Included-gallons allowances:
- **Passenger gas: 2 gal** — `SVC-FUEL-GAS-2GAL`
- **Passenger diesel: 5 gal** — `SVC-FUEL-DIESEL-5GAL`
- **Commercial diesel: 15 gal** — `SVC-FUEL-DIESEL-COMM-15GAL`

**Decision: flat-rate pricing with accurate internal recording** ("Save what we have here, i will use flat rate pricing with accurate recording"). Mechanics:
- Customer hears/pays a flat price per service tier; overage gallons beyond included amount billed as a separate `PASS – Fuel (Gas/Diesel)` line: `OverageGallons = max(0, Actual − Included)`; only added when > 0.
- Internally record per job: actual gallons delivered, actual pump price per gallon, fuel COGS (gallons × pump price), station/receipt photo optional → margin tracking.
- Key modeling concept: every line item carries **billable quantity** (what's charged, e.g. overage only) and **fulfilled quantity** (what was delivered) — "the secret sauce for easy + accurate."
- Suggested overage policy: bill at a posted per-gallon rate (updated monthly), record actual pump price internally.
- Fuel screen: select tier → enter gallons delivered → enter pump price (internal) → auto-shows included, overage, cost, margin.

### Extensibility vs. the "hook" (towing dilemma resolution)
Jason's concern: excluding towing might handicap the app for future users, but he isn't a towing authority and building a generic platform "kills the hook" (Excel-with-a-logo). Resolution: **opinionated for the roadside niche + extensible at the seams; design for extensibility, implement only what you use.**
- Don't hardcode "no towing": no fixed service-type enums in code, no required `is_tow_job` columns; **offerings are data, not code**.
- Ship: service-domain tags (roadside / mechanic / tow(future) / admin), feature flags (`feature_towing_enabled = false`), and a generic, neutrally named **External Service / Third-Party Service** line-item type hidden by default (not "Tow Sublet").
- Do NOT build: tow dispatch flows, storage/lien/impound logic, tow rate tables, heavy-recovery add-ons, tow vendor billing.
- Design rule protecting the hook: **"Core flows and screens are fixed. Only the catalog and pricing rules are configurable."** The hook is roadside-native workflow, fast quoting, clean paperwork, tight rules (signatures, estimate thresholds, VIN/plate logic), and per-job profit clarity.

---

## 4. Customer / Account / Location Model & Provider Dispatch
*Source: Handling multiple locations*

### Core principles for same-name locations (franchises/chains)
- **Never key off names.** Opaque surrogate IDs (UUID/bigint) everywhere; locations get a human `store_number` unique **within** the brand/account.
- Display label convention: `"{org_legal_name} — {city}, {state} (#{store_number})"`. UI searches by name but must store the ID; APIs reject payloads referencing objects by name.
- Locations survive renames/moves/closures: never change `location.id`, update mutable fields.

### Party → Account → Location pattern (unifies chains, single-location businesses, individuals)
- **Party** = person or organization (the real-world "who").
- **Account** = the relationship a party has with WKR (billing, terms, status); `UNIQUE(tenant_id, party_id)`.
- **Location** = physical branch under an org account; `UNIQUE(account_id, store_number)`.
- Individuals: person party + account, no locations. Single-location org: one location. Chain: many locations.
- Account roles: `provider | retail | vendor` (a partner like Agero is an account with the provider role).
- Contact points normalized (email, phone in E.164, addresses standardized/geocoded).

### Provider dispatch layer (bulk referral jobs)
Internal documents stay identical for retail and provider jobs; a thin inbound layer maps external jobs in:
- `provider_contract` (terms_json fee schedule, SLAs; e.g. base service prices, included_miles, mileage_rate, after-hours window + surcharge, caps).
- `inbound_dispatch` — immutable snapshot of the provider payload (external_job_id, end-customer name/phone, vehicle_json, location_json, requested services, ETA); `UNIQUE(provider_account_id, external_job_id)` as duplicate guard; statuses `received → acknowledged → accepted → (declined | cancelled_by_provider | expired)`.
- `dispatch_link` ties an inbound dispatch to the SR/SO/WO/Invoice it spawned.
- Accepting an inbound creates SR → SO (bill_to = provider account) → WO in one transaction.
- Retail pricing from the active **price book**; provider pricing computed from contract terms with computation parameters persisted in `invoice_line.meta_json` for audit.
- PII minimization: provider jobs may keep end-customer PII only in the inbound snapshot.

### Full-spec highlights ("Indie Roadside Admin – Full System Spec v1.0")
- Stack: PHP 8.2.12 OOP + MySQL 8.x; dark UI with neon accents, **button-first navigation**; roles **Admin, Dispatch, Driver, Customer** (customer portal optional v1.1).
- Lifecycle: **SR → SO → WO → INV → PAY → RCT**.
- State machines (kept independent of each other):
  - SO: `pending → dispatched → completed → invoiced`
  - WO: `pending → en_route → on_site → completed | cancelled`
  - Invoice: `draft → sent → paid | void`
- Financial documents are **voided, never hard-deleted**; audit_log rows on every create/update; idempotency keys on all POSTs (24 h retention).
- Single tenant now, multi-tenant-ready schema.
- Edge cases: provider duplicate retries return existing record (409); provider cancel after accept → close WO if unstarted, SO cancelled, optional cancellation fee per contract; VIN optional with background plate/VIN lookup enrichment.
- SR view disclaimer: "Final invoice may vary as scope changes."

### MVP cut
End-to-end: intake (retail or provider) → dispatch → work → invoice → payment → receipt. Minimal tables (tenant seeded 1 row, party/account/location minimal, catalog + one retail price book, provider contract as hardcoded JSON, SR/SO/WO/INV/line/payment/receipt, inbound_dispatch + dispatch_link). UI: create-SR form, accept-inbound, status board, driver WO page, invoice/payment screens, CSV export. **Out of MVP:** messaging/notifications, automated tax, VIN lookup integration, multi-tenant, marketing prefs, complex contract logic, customer portal.

---

## 5. Forms Catalog & Data Policies
*Source: Roadside assistance forms list (Sept 2025)*

### Core lifecycle forms (the six)
**Service Request (SR)** → **Service Order (SO)** (internal acceptance; holds quote/terms; distinct from WO — represents the contractual obligation) → **Work Order (WO)** (technician field record) → **Invoice** → **Payment Authorization/Payment** → **Receipt** (auto email/SMS with consent).

### Status models (as fixed in this catalog)
- **Service Request:** Pending → Accepted → Completed → Cancelled (by customer) → Rejected (by WKR). Explicitly **no** In-Progress or No-Show status on SR.
- **Service Order:** Pending → Dispatched → Completed → Cancelled → Invoiced.
- **Work Order:** Pending → Dispatched → Completed.

### Data policy (Appendix B — WKR rules)
- **Customer matching:** create a new customer only if not matched by name+phone.
- **Vehicle creation requires License Plate + State** (unique together); plate+state optional at SR, **required to create the Vehicle record and required on the Invoice**.
- VIN: driver may capture; optional at intake.
- Every job links to Customer and Vehicle.
- **Phone mask:** `(xxx) xxx-xxxx` with non-removable brackets/dash.
- Location: GPS + text address; map pin required if GPS unavailable.
- Auto timestamps for dispatch/arrival/completion; manual changes require an editable comment.

### Full forms inventory (by group, with form codes)
- **Intake & Dispatch:** Retail Intake (SR-INTAKE-RET), Bulk Referral Intake (SR-INTAKE-BULK, enforces partner rules/SLA/authorized amount), Triage Checklist (safety: off roadway? children/pets inside? locked-while-running? fuel type, tire size, wheel locks, EV/hybrid flags), Quote/Estimate (EST, with e-signature + expiry), SMS/Email Consent (COMMS-CONSENT, TCPA/CTIA).
- **Field & Safety:** Job Hazard Analysis (JHA — blocks WO completion if high-risk steps unchecked), VCR-PRE / VCR-POST (pre/post condition reports; PRE required for lockouts, wheel/tire work, recovery; POST compares and flags deltas), Incident/Near-Miss (SAF-INC), Spill/Environmental (ENV-SPILL).
- **Service-specific waivers:** TIRE-AUTH (plug ≤1/4", temporary-repair + re-torque disclaimer), TIRE-WO (lug count/pattern, torque spec, torque wrench ID + calibration date), BATTERY-WO (OCV, CCA measured vs rated, charging tests, parasitic draw, core return), JUMP-AUTH, LOCKOUT-AUTH, FUEL-WO, REC-AUTH (winch/recovery), MECH-RO (mobile mechanic repair order with Complaint/Cause/Correction "3C").
- **Parts & Warranty:** PARTS-AUTH, WARR-REG, WARR-CLAIM, CORE-RTN, CAT-REQ, PRICE-OVR, SUP-REX.
- **Customer comms/QA:** MEDIA-CONSENT, REVIEW-OPT, CSAT/NPS, CUST-CMP, GOODWILL, CUST-CLAIM.
- **Accounting & Legal:** AR-ADJ, CBK-PKT (chargeback rebuttal), TAX-SETUP, AP-EXP, AP-MILE, RATE-CARD, PARTNER-STMT, INS-COI, T&C, PRIV-CONSENT.
- **Ops/Equipment/Quality:** EQP-CAL (torque wrench calibration log), EQP-MX, EQP-CUST, INV-COUNT, UNIT-MX, QA-AUDIT (verifies SR/SO/WO/Invoice/Payment/Receipt + photos + signatures present per job file), DOC-CHGLOG.
- **HR/Contractor (future):** HR-ICA, HR-W9, HR-APP-MVR, HR-ONB, HR-ACK, HR-TIME.
- **Partner/Fleet/Insurance:** PRT-PROF, PRT-SLA, PRT-ACPT, PRT-RATE, PRT-EXC, PRT-INV.
- **IT/Data:** IT-RET, IT-DSAR, IT-GEO-CONSENT, IT-IMG-RULES, IT-SECINC.
- **Emergency/Continuity:** ERP, WX-PLAN, BCP.

### Image naming (this catalog's Appendix A — later revision)
Pattern `JOB-YYYYMMDD-SEQ-TYPE.EXT` keyed to the WO number, e.g. `WO12345-20250905-001-PRE.jpg`, `WO12345-20250905-004-SIGN.png`. TYPE ∈ `PRE, POST, PART, SIGN, DOC, SITE, DAMAGE`; SEQ is 3-digit.
Required photo sets by service: **Lockout** PRE ×2 (door/trim) + POST ×2; **Tire** PRE wheel ×2 + part/patch ×1 + POST torque ×1; **Battery** PRE bay ×1 + tester screen ×1 + PART battery label ×1 + POST voltage ×1.

### Production schema decisions from this conversation
- **Timezone: America/Los_Angeles** (Portland, OR) set at session/schema level.
- MySQL 8.0.16+, utf8mb4, InnoDB; plate+state unique on vehicles; vehicles ↔ customers many-to-many (`customer_vehicle_links`); generic `consents` table with JSON payload instead of one table per waiver type; catalog text frozen at time of use on all line items; triggers auto-recalc invoice totals and derive PAID/PARTIALLY_PAID; receipts rendered from payment+invoice data (no separate table in that version).

---

## 6. Records, Compliance & Operating Policies (earliest master doc)
*Source: Feature mapping explanation (Aug 2025 — Solo Operator Master Document Pack v0.3)*

Historic first pass at policies; several were refined later, but these decisions persist:

- **Electronic-only records policy:** No physical records retained ever. Incoming paper is scanned immediately (≥300 DPI, deskewed), attached to the related Job/Invoice, SHA-256 hashed, then the paper is shredded once scan quality is verified. PDF is the official record (embedded fonts, signatures, disclaimer version, doc hash). Formats: PDF for forms, JPEG/PNG for photos/signatures.
- **VIN policy:** VIN optional at intake, **mandatory before completion/invoicing** unless a documented exception is logged (VIN inaccessible, unsafe conditions). (Later refined by the plate+state policy in the Forms Catalog: plate+state is the invoice/vehicle-creation requirement; VIN captured by driver when possible.)
- **Signature/consent protocol:** show exact disclaimer text with visible version number; capture typed name + drawn signature (PNG) + timestamp + IP + GPS (if permitted) + hash of the disclaimer version signed; ESIGN/UETA consent notice before first signature per customer; e-delivery consent with paper-on-request.
- **Retention:** 7 years for job/invoice/payment records (tax/audit). Nightly encrypted local backup + monthly off-device export; quarterly restore tests; immutable event log for signatures and edits.
- **Image labeling workflow:** every image ties to a Job ID; app suggests the `<type>` code from service type + workflow stage (e.g., in Vehicle Condition pre-capture → `VCR-pre`; job marked completed → `VCR-post`; fuel job → `FUEL`), auto-increments sequence, sets extension by source, and shows a confirm screen (accept or edit). Original type-code set: `VCR-pre, VCR-post, WAIVER, SIGN, FUEL, LOCK, BAT, TIRE, RIM, PART, RECEIPT, EVIDENCE, SCAN`. Photo record fields: job_id, file_path, type, seq, format, uploaded_at, SHA-256 hash, source (camera/upload/scan), user. (Note: the Forms Catalog later simplified the type set to PRE/POST/PART/SIGN/DOC/SITE/DAMAGE.)
- **Job source tracking:** every job records `job_source` ∈ `direct` (retail — customer books directly) vs `referral` (bulk referral company using WKR to complete jobs they booked), with `referral_id` FK to a **Referral Directory** (company, dispatch contacts, payment terms e.g. Net 30, special restrictions). Job source printed on all job documents ("Job Source: DIRECT" / "Job Source: REFERRAL — AAA Roadside"); reporting splits revenue by source; separate invoice pipelines for referral partners. (This evolved into the full provider/partner account + inbound dispatch model in *Handling multiple locations*.)
- **Required documentation baseline** for a roadside provider (mostly best practice, not federal mandate): customer info, service date/time/location/type, vehicle details (VIN/plate/make/model/year/color), authorizations/disclaimers, invoices & payments, parts + warranty info, incident reports, expenses & receipts.
- Master pack contains 14 customer-facing templates (Job Ticket, Authorization & Liability Waiver, VCR pre/post, Fuel Delivery Waiver, Lockout Waiver, Jumpstart/Electrical Waiver with test log, Tire Service Waiver + torque re-check notice at 25–50 miles, Wheel/Rim/Lug Damage Acknowledgment, Parts Install & Warranty, Invoice, Payment Auth & Receipt, Incident Report, Refund/Adjustment, Privacy Notice) plus boilerplate liability clauses (all flagged attorney-review-required). No card data stored — tokenized PCI-compliant processor only.

Also from this conversation: the app's mapped feature inventory (customer mgmt, vehicle mgmt with VIN/plate tied to multiple customers, service requests, catalog-first line items — "picked from a predefined catalog, not typed in", invoicing/payments, expenses & reports, warranty tracking) and the early scope decision to build **me-only first** (no auth, no multi-user) with structure ready for later expansion.

---

## 7. Schema Field Reference (canvas artifact)
*Source: Roadside Schema Field Chooser (Jan 2026)*

An interactive React "field chooser" canvas seeded with a comprehensive pricing/catalog field set for pruning. Lasting value is the table/field inventory (localStorage key `wkr_schema_field_chooser_v1`):

- **catalog_services** (17): id, sku, name, category, description, base_price, pricing_model, default_qty, uom, requires_vehicle, requires_vin, requires_location, default_duration_min, taxable, active, sort_order, meta.
- **catalog_parts_materials** (18): id, sku, name, description, part_number, brand, manufacturer, condition, uom, default_unit_cost, default_sell_price, taxable, warranty_months, warranty_miles, warranty_terms, core_charge, active, meta.
- **catalog_fees** (14): id, code, name, description, basis, amount, percent, applies_to, taxable, min_amount, max_amount, priority, active, meta.
- **doc_line_items** (59) — a single shared line-item table across document types: document_type/document_id, line_no, line_kind, catalog_ref_table/id, sku, name, qty, uom, unit_price/unit_cost, extended price/cost, tax fields, discount fields, pass_through, is_flat_rate, part fields (part_number, brand, condition, serial_number, core_charge, core_returned), labor fields (labor_minutes/rate/amount), travel & mileage fields, **fuel fields (fuel_type, included_gallons, gallons_delivered_actual, pump_price_per_gallon, overage_gallons, overage_unit_price)**, warranty fields, vendor fields (vendor_name, vendor_sku, vendor_receipt_no, purchased_at, proof_attachment_id), status/void_reason, notes.
- **vendor_purchases** (16): service_request_id, vendor_name, vendor_invoice_no, item_name, part_number, qty, unit_cost, tax_amount, shipping_amount, total_cost, purchased_at, proof_attachment_id, warranty fields, notes.
- **tax_rules** (10): jurisdiction, state, county, city, tax_code, rate, effective_from/to, meta.

Note how the flat-rate fuel model and quoted-parts vendor capture from *What Defines Mechanical* are reflected directly in doc_line_items — this artifact operationalizes those decisions.

---

## 8. Regulatory Research & Fee Benchmarks
*Source: Research Request Details (Jan 2026)*

### PNW Regulatory Workbook (OR • WA • ID) — code-friendly compliance parameters
Scope: roadside assistance, mobile mechanic, mobile tire, optional lockouts/fuel delivery. **Towing/impound/collision explicitly out of scope.** Not legal advice.

- **Washington:** written estimate required for repairs over **$100** (`WA_WRITTEN_ESTIMATE_THRESHOLD_USD = 100`); authorization required to exceed estimate by **>10%** pre-tax; estimate/invoice retention **1 year**; "Your Customer Rights" sign required (mobile: carry in vehicle / show digitally); waste-tire transport license required if in the business of transporting/storing waste tires.
- **Oregon:** estimate required **before beginning work** (incl. mobile jobs); evaluation/disassembly authorization threshold **$200**; overage limit **min(10%, $200)** before separate authorization needed; lockouts may trigger **Oregon CCB locksmith certification** (verify exemptions); DEQ waste tire carrier permit likely if transporting as a business.
- **Idaho:** repair **labor not taxable if separately stated** from parts (structure invoices accordingly); used-oil self-transport trigger at **>55 gallons**; waste tire act + county-level storage approvals.
- Evidence fields the app should capture per job: estimate issued (Y/N) + versions + timestamps, customer authorization (signature/SMS/call log), overage authorization ("Change of Service"), parts disposition, invoice delivered timestamp, required-notice acknowledgments; ops-level: registration numbers, carrier/license IDs, disposal receipts/manifests, SOP signoffs.
- Local overlay per city/county served: business license, mobile vendor permit, roadside work restrictions, fire code for fuel storage, signage rules — tracked with permit ID, renewal date, fee, contact.
- For scaling beyond PNW: build a compliance knowledge base, not 50 hand-written docs — tables `jurisdiction`, `activity`, `obligation` (threshold_json, evidence_json, effective/last-verified dates, confidence score), `source` (url, citation, retrieved_at, hash).

### Waste tire hauling ("in the business of") — settled operating rule
Jason's practice: rotate stock — carry the last job's old tire (roof rack), pay disposal when buying the next tire; never more than 4 aboard.
- **Oregon:** carrier permit exemption for transporting **fewer than five** tires for disposal → 1–4 per run is exempt; BUT disposal **receipts must be kept 2 years** regardless, and a log is required if handling **>100 tires/year**. Storage of >100 tires triggers a storage permit.
- **Washington:** license not required for **5 or fewer** tires per load; 6+ requires license; contracting with unlicensed haulers is prohibited.
- **Idaho:** transport only to **approved** sites; <200 stored tires without county approval.
- **Nationwide caveat:** the ≤4 rule is a strong risk-reduction baseline but **not a guarantee** — some states have no simple count exemption. Default nationwide envelope: cap onboard waste tires (≤4) unless registered in that state; only authorized destinations; receipt per disposal event tied to the customer job record; no stockpiling; check both states when crossing lines.
- SOP add-on: hard cap 4; no batching runs; contingency — if about to exceed 4, make an immediate disposal stop before taking custody of another tire. Candidate app features: live tire counter + "upload receipt required" closeout rule for Oregon jobs.

### Shop supplies fee benchmarks
- Industry norm: **5–10% of labor**, 8% a very common default, usually with a **$20–$50 cap per repair order**. Alternatives: flat fee (~$9.95/ticket) or $3–6 per billed hour.
- Recommended for roadside/mobile: **6–8% of labor, cap $25–$35**; keep disposal/environmental fees (tires, oil, batteries) as separate line items; disclose on the estimate with a plain-language definition; audit actual consumable spend for 30–60 days and adjust.

---

## 9. Cross-Conversation Evolution & Conflicts (flag for reconciliation)

1. **Document numbering** evolved: `JOB-YYYYMMDD-####`/`INV-YYYY-#####` (Aug 2025) → tenant-prefixed `IRA-2025-000123` (Sep 2025) → **`TYPE-YYYYMMDD-###-V#` with the 20-code TYPE table (May 2026, latest)**.
2. **Image naming** evolved: `JOB-<id>-<YYYYMMDD>-<type>-<seq>.<ext>` with 13 type codes (Aug 2025) → `WO#-YYYYMMDD-SEQ-TYPE.ext` with 7 type codes PRE/POST/PART/SIGN/DOC/SITE/DAMAGE (Sep 2025, Forms Catalog Appendix A).
3. **Lifecycle naming** varies: SR → SO → WO → INV → PAY → RCT (Forms Catalog & Multi-location spec) vs. SR → EST → WOR → INV (What Defines Mechanical) vs. the full 20-document-type set (Document Identifier Parts, which includes both EST/EAP and DSP/COS/SCR). The May 2026 document-type table is the most complete and most recent vocabulary.
4. **"Estimate" vs "Service Order":** the Forms Catalog treats SO as the internal quote/acceptance record with EST as a pre-dispatch price confirmation; the later doc-type scheme has explicit EST + EAP (Estimate Approval) + COS (Change of Service). Treat EST/EAP/COS as the current document vocabulary.
5. **Job source:** "direct vs referral + Referral Directory" (Aug 2025) was superseded by the fuller **retail vs provider account roles + provider contracts + inbound dispatch layer** (Sep 2025).
6. **Vehicle gating:** VIN-mandatory-before-invoice (Aug 2025) refined to **plate+state required for vehicle creation and invoicing; VIN optional/driver-captured** (Sep 2025 Forms Catalog Appendix B).
7. Constants that never changed: catalog-first line items with snapshotting, electronic-only records, phone mask `(xxx) xxx-xxxx`, no towing, flat-rate fuel tiers 2/5/15 gal, Pacific timezone, soft-delete/void for financial docs, 7-year retention.
