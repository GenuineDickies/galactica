# Product & Requirements Notes — Bin product-5

Distilled from 21 exported conversations (ChatGPT / Claude / Gemini), Aug 2025 – May 2026. Covers app identity, lifecycle, business rules, modules, service catalog, forms/documents, document intake (AI/OCR), user roles, schemas, pricing, and SMS compliance for the White Knight Roadside (WKR) dispatch-to-cash admin app.

---

## 1. App identity, purpose, and tech stack

**Sources:** "Jumpstart Service Catalog", "Project plan request", "App summary creation", "Major app features list", "Roadside assistance app prompt"

- App name: **Indie Roadside Admin / White Knight Roadside Admin**. Recommended GitHub description: "A PHP/MySQL roadside assistance admin platform for managing intake, service requests, estimates, work orders, invoices, payments, receipts, catalog pricing, expenses, and job documentation." Suggested topics: php, mysql, roadside-assistance, business-management, invoicing, estimates, service-requests, work-orders, accounting, field-service, admin-dashboard, mobile-mechanic.
- Positioning: **solo-operator first** ("designed for a solo operator first, with clean workflows, simple accounting records, catalog-based services/items, estimate-required repair jobs, VIN-aware invoicing rules, and defensible job documentation") but role-ready for growth.
- The "Why" (Jason's first-person mission, Sept 2025): one place for customer details, vehicle history, job tracking, invoices, payments, expenses; look professional (PDF invoices, taxes, warranty tracking, disclaimers); real-time profit visibility; catalog-enforced consistent pricing; lifecycle enforcement so nothing falls through cracks; scale-ready roles; brand-consistent dark/neon design; "spend less time on paperwork and more time helping people on the road."
- **Tech stack evolution:** early explorations (Aug 2025, "Major app features list" / "Roadside assistance app prompt" / Gemini workbook) considered Python + Flask/FastAPI or Tkinter with SQLite. **Final decision: pure PHP 8.2.12 OOP, MVC, front controller pattern, MySQL 8.0+, XAMPP/Windows dev environment.** No framework (no Laravel). JSON-first REST; server-side computed totals; PDF generation server-side (wkhtmltopdf or TCPDF/FPDF discussed).
- Business does **no towing** (explicit in early prompt); towing/winch items are optional/scale-up only.

---

## 2. Core lifecycle & document codes (FINAL model)

**Source:** "App Module Recommendations" (Apr 2026 — most current), superseding earlier models.

Canonical chain:

```
INT → SER → EST → EAP/CAU → WOR → DSP → COS → SCR → INV → PAY → RCT → PTW
Intake → Service Request → Estimate → Estimate Approval / Customer Authorization → Work Order
→ Dispatch → Change of Service (if needed) → Service Completion Report → Invoice → Payment
→ Receipt → Parts Warranty (if applicable)
```

**Document numbering format:** `PREFIX-YYYYMMDD-###-V#`. Prefixes:

| Doc | Code | Doc | Code |
|---|---|---|---|
| Service Request | SER | Payment | PAY |
| Estimate | EST | Receipt | RCT |
| Estimate Approval | EAP (or CAU for Customer Authorization) | Refund Receipt | REF |
| Work Order | **WOR (not WO)** | Credit Memo | CRM |
| Dispatch | DSP | Customer Statement | STM |
| Change of Service | COS | Purchase Order | POR |
| Service Completion Report | SCR | Vendor Bill | VBI |
| Invoice | INV | Goods Received Note | GRN |
| Parts Warranty | PTW | Return Material Auth | RMA |

**Earlier lifecycle model (Sept 2025, "Router controller documentation" / "Project plan request")** distinguished Service Request vs Service Order vs Work Order:
- **Service Request (SR)** = customer asks for help; no dispatch mechanics. Jason's exact correction: "A service request is just that, a request for service… It can only be Pending (not accepted yet), Accepted, Completed, Cancelled (customer revokes request) or Rejected (we rejected request)." Never "In Progress" or "No Show".
- **Service Order (SO)** = in-house contract/authorization to perform work (scope + quoted items). States: Pending → Dispatched → Completed → Invoiced, with Cancelled off-ramp.
- **Work Order (WO)** = technician-facing ticket that "activates the tech": Pending → Assigned → Acknowledged → En_Route → On_Site → Completed; off-ramps Cancelled, No_Show. SR can spawn multiple SOs; SO can spawn multiple WOs. SO becomes Dispatched when any WO assigned; Completed when all WOs terminal; Invoiced after invoice created.
- The later (2026) module model merged/renamed this into Intake → Service Request → Estimate → Work Order → Dispatch, but SR status list stayed identical.

**Service Request statuses (constant across all versions):** Pending, Accepted, Completed, Cancelled, Rejected. Guards: Cancelled requires cancelReason; Rejected requires rejectReason; Completed requires linked completed work.

---

## 3. Hard business rules

**Sources:** "Write application instructions" (Dec 2025), "App Module Recommendations", "Bullet List Formatting", "Router controller documentation", "Project plan request"

### Vehicle / VIN rules (evolution → final)
- **Sept 2025 rule (superseded):** vehicle identified by **plate + state** (unique pair required for creation; required before invoicing; VIN optional).
- **Dec 2025 correction (FINAL), Jason verbatim:** "You have the vehicle data rules backwards. A VIN number is required. You can look up the vin using plate and state."
- Final rules: **VIN is required for vehicle records and for invoice creation** unless the "**No vehicle was serviced**" override flag is checked. Plate + state are captured when available and used to look up the VIN (canonical identifier is VIN, not plate). Intake/service request may hold basic vehicle info (year/make/model/color) without creating a full vehicle record. If plate unavailable: store No-Plate flag + reason; proceed only if VIN known. Plate+state is NOT required for invoice. A vehicle can link to multiple customers (fleet/shared) via association table.

### Estimate & authorization rules
- **Every job gets an estimate.**
- **Customer approval (signature) REQUIRED for any estimate over $200.**
- **Customer authorization (CAU) REQUIRED when final invoice differs from approved estimate by more than $200 or 10% — whichever is smaller.** Pseudocode (from "Bullet List Formatting" spec): `threshold = MIN(200, approved_estimate_total * 0.10); requires_cau = (overage > threshold)`; `requires_eap = (estimate_total > 200)`.
- Mobile **signature capture** built for these approvals. Approval evidence stored: SMS approval, signature, timestamp, customer name, IP, device/browser metadata, geolocation, approval text shown, link to estimate version approved, optional photo-ID attachment.
- Estimate disclaimer everywhere: "Final invoice may vary if scope changes."
- These thresholds trace to **Oregon ORS 646A.480–646A.495** (see §12).

### Other non-negotiables
- Phone number format enforced: `(xxx) xxx-xxxx`.
- **Catalog-only line items** on orders/estimates/invoices — no free-typed items (v1); later softened to allow **ad-hoc purchased parts/materials at job time** with full fields (name, part number, unit cost, vendor, qty, warranty flag/date/details, proof-of-purchase attachment).
- Totals (subtotal/tax/total) computed server-side.
- Prevent accidental customer merges on reused phone numbers.
- Image/photo naming protocol: `JOB-YYYYMMDD-SEQ-TYPE.ext`.
- Contact design point: a contact can be a **person OR a company** — schema must not force both field sets on every contact.
- Customer record created only when no confident match found (dedupe on phone/name/email with confirm/merge flow).

---

## 4. Modules (final consolidated list)

**Source:** "App Module Recommendations" (Apr 2026) — 37 business modules:

1. Dashboard (today's jobs, pending intakes, estimates awaiting approval, jobs ready to invoice, unpaid invoices, revenue/expense snapshot; Quick Actions: New Intake, New Service Request, Create Estimate, Take Payment, Record Expense, Create PO, Add Vendor Receipt)
2. Intake / Lead Capture — pre-service-request holding area; statuses Pending/Converted/Cancelled/Rejected; captures name, masked phone, service requested, basic vehicle (Y/M/M/color), location (address mode or pin/drop-map with stored lat/long), job source (retail direct / broker-referral / fleet-company), soft quote; convert-to-SR workflow
3. Customers / Contacts (individuals + company/fleet accounts + contact people under companies + broker/provider relationships)
4. Vehicles (VIN, plate, state, YMM, color, customer links, service/warranty history, VIN decode, plate+state lookup)
5. Service Requests 6. Estimates 7. Estimate Approval / Customer Authorization 8. Work Orders 9. Dispatch (work order after driver assignment; on-scene capture: VIN, plate/state, photos, signature, notes) 10. Change of Service 11. Service Completion Report (work performed, actual labor/parts, actual gallons + pump price for fuel, before/after photos, VIN confirmation, GPS evidence) 12. Invoices 13. Payments (Square API + manual cash/check/card/other; Square clearing tracking) 14. Receipts 15. Refunds / Credit Memos / Statements 16. Service Catalog 17. Parts & Materials Catalog 18. Line Item Builder (shared component across SR/EST/COS/SCR/INV; "+ Add Service" / "+ Add Item" catalog picker) 19. Warranty / Parts Warranty 20. Vendors 21. Purchasing (POR/VBI/GRN/RMA) 22. Expenses 23. Accounting (chart of accounts, AR/AP, Square clearing, core deposits payable, sales tax payable, journal entries) 24. Tax Engine (built-in, universal, NOT dependent on a paid tax service; jurisdictions, rates, categories, effective dates, snapshot stored on invoices) 25. Reports 26. Documents / PDF Center 27. Attachments / Evidence Vault 28. Chargeback / Dispute Package (one-click PDF/ZIP export gathering the full document chain + evidence) 29. Messaging / SMS (Telnyx, 10DLC templates, webhook inbox, job-linked conversations) 30. Payments Integration 31. Maps / Location (Google Maps, address search, draggable pin, lat/long) 32. Users / Owner Profile (single-user now; future roles Admin / Dispatch / Technician / Customer portal) 33. Settings 34. Terms / Policies 35. Customer Portal (planned, not phase one) 36. Fleet / Broker Accounts (bulk pricing, net terms, monthly statements) 37. Developer / Director Documentation module (`/docs/00-director/roadmap.md` … `/docs/70-testing/test-plan.md`, living TODO, agent rules, traceability matrix — "AI-proofing")

**Suggested chart of accounts:** 1000 Cash, 1010 Checking, 1050 Square Clearing, 1100 Accounts Receivable, 1200 Parts Inventory, 2000 Accounts Payable, 2010 Credit Card Payable, 2020 Sales Tax Payable, 2050 Core Deposits Payable.

**Phased build order:** Phase 1 core job flow (Dashboard, Intake, Customers, SR, both catalogs, Estimates, Invoices, Payments, Receipts); Phase 2 field execution (WOR, DSP, SCR, COS, signatures, attachments, VIN capture, maps); Phase 3 accounting & protection (Expenses, Vendors, Purchasing, Tax Engine, CoA, Reports, Chargeback, Warranty); Phase 4 scale (SMS/Telnyx, Square automation, portal, fleet accounts, broker tracking, dev docs). Strong advice: start with "Customer → Service Request → Estimate → Invoice → Payment → Receipt".

**Programming (code) modules** — same conversation, 44 technical modules: Core/Bootstrap, Auth, Routing, Layout/UI Shell, Dashboard, Contacts, Vehicles, …, Line Items, Estimate, Approval/Authorization, Work Order, Dispatch, Change of Service, Service Report, Invoice, Payment, Receipt, Refund/Credit, Vendor, Purchasing, Expense, Accounting, Tax, Warranty, **Document Numbering**, PDF/Document Rendering, Attachments/Evidence, Audit Log, Chargeback Package, Messaging/SMS, Maps/Location, Integration, Settings, Reports, Search, Validation, Status/Workflow, Security, Customer Portal, API/Webhook, Developer Docs/Director.

**Sidebar navigation (final):** Dashboard | Operations (Intake, Service Requests, Estimates, Work Orders, Dispatch, Service Reports, Invoices, Payments, Receipts) | Customers (Customers/Contacts, Companies/Fleets, Vehicles) | Catalog (Services, Parts & Materials, Warranties) | Accounting (Expenses, Vendors, Purchasing, Chart of Accounts, Reports, Taxes) | Evidence (Attachments, Approval Records, Chargeback Packages) | Communication (SMS Inbox, Message Log) | System (Documents, Settings, Owner Profile, Developer Docs).

---

## 5. Service catalog (full detail)

**Source:** "Jumpstart Service Catalog" (May 2026 — most current catalog spec)

Standard catalog-entry fields per service: Service Name, Service Code/SKU, Category, Description (with explicit "not included" scope), Vehicle Required, VIN Required for Invoice ("Yes, unless 'No vehicle was serviced' override applies"), Pricing Type, Taxable, Warranty Eligible, Default Expense/COGS Treatment.

| Service | SKU | Pricing | Notes |
|---|---|---|---|
| Jump Start | SVC-JUMP-STD | Flat rate | No *materials* COGS; still carries merchant fees, truck fuel and labour. Excludes battery replacement, diagnostics |
| Spare Tire Swap | SVC-TIRE-CHANGE | Flat rate | Renamed from "Tire Change". Install customer's spare, tire stays on its wheel → ROADSIDE category; excludes repair/mount/balance/disposal |
| Tire Replacement | SVC-TIRE-REPLACE | Flat service fee + parts | Warranty eligible if tire sold; tire/disposal/valve/mounting to COGS |
| Vehicle Lockout | SVC-LOCKOUT-STD | Flat rate | Non-destructive entry; excludes key cutting/programming/rekey |
| Fuel Delivery — Gasoline | SVC-FUEL-GAS-2GAL | Flat rate w/ **2 gal included**; overage billed separately | Fuel recorded ONCE as vendor expense/COGS then allocated to job — never double-post |
| Fuel Delivery — Diesel Passenger | SVC-FUEL-DIESEL-5GAL | Flat rate w/ **5 gal included** | Same COGS rule |
| Fuel Delivery — Diesel Commercial | SVC-FUEL-DIESEL-15GAL | Flat rate w/ **15 gal included** | Same COGS rule |
| Mobile Mechanic | SVC-MOBILE-MECH | Hourly / flat / estimate-based | Light repairs, no lift work |
| Battery Replacement | SVC-BATT-REPLACE | Labor fee + battery/materials | Warranty eligible if battery sold |
| Starter Replacement | SVC-STARTER-REPLACE | Estimate-based; labor + parts | |
| Alternator Replacement | SVC-ALT-REPLACE | **Estimate Required** (see below) | |
| Mobile Tire Mount / Balance | SVC-TIRE-MOUNT-BAL | Flat or per tire | VIN bypass allowed if servicing a loose wheel/tire assembly only |
| Dispatch / Service Call Fee | FEE-DISPATCH | Flat rate | Call-out/minimum charge; no COGS |
| Diagnostic / Inspection | SVC-DIAG-STD | Flat or hourly | Does not guarantee full diagnosis |
| Other / Custom Service | SVC-OTHER-CUSTOM | Estimate-based | Convert to specific item if it becomes common |

**Key catalog design decision (Jason-driven):** "things like alternator replacements cannot be a flat rate as there is too large a difference in labor times." Resolution: **catalog item = service template; estimate line item = actual priced work.** Variable-labor jobs (alternator, starter, brakes, water pump) listed as **Estimate-Required service templates** with fields like:

```
pricing_type: estimate_required     billing_method: labor_plus_parts
default_labor_rate: 125.00          default_labor_hours: NULL
minimum_labor_hours: 1.00           requires_vehicle: true
requires_vin_for_invoice: true      requires_parts: true
requires_estimate: true             requires_customer_approval: true
allow_manual_labor_hours: true      allow_manual_parts: true
```

Customer-facing price display: "Estimate Required". **Default labor rate: $125/hr.** Example estimate build: Labor 2.4 hrs × $125 = $300 + alternator $189.99 + belt $42.99 + shop supplies $10 = $542.98 + tax.

**Pricing types the catalog must support:** Flat Rate | Hourly | Estimate Required | Per Unit (per tire, per overage gallon) | Labor + Parts | No Charge / Included | Variable / Custom.

Catalog module fields (from module list): service code/SKU, name, description, base price, labor rule, tax category, vehicle-required toggle, approval behavior, active/inactive. Parts catalog: SKU, name, description, default cost, default price, vendor, warranty flag/term, tax category, active flag.

---

## 6. Forms inventory

**Source:** "Forms list creation" (Aug 2025). Comprehensive industry forms list; also HTML dark-theme templates were generated (wkr_forms_v1 bundle).

- **Customer-facing:** Service Request Intake (retail); Authorization to Perform Roadside Service & Liability Waiver (master consent + digital signature, attaches addenda); Estimate/Quote; Change Authorization (Scope Change); Payment Authorization; Invoice & Receipt (VIN enforced); Refund/Adjustment Request; Feedback/NPS; Photo/Testimonial Release; Data & Communications Consent (TCPA).
- **Service-specific addenda:** Tire (plug/patch ≤¼" tread puncture limit, TPMS damage waiver, **Lug-Nut Torque Acknowledgment — re-torque after ~50 miles**); Battery/Jump-Start (rated vs measured CCA pre-test, memory-loss risk, Core Return Receipt, warranty exclusions); Lockout (entry method, weatherstrip/airbag sensor caution, hidden damage waiver); Fuel Delivery (fuel type confirmation, misfuel liability, "DEF is not diesel"); optional Light Recovery/Winch.
- **Dispatch & ops:** Dispatch Log/Job Ticket, Bulk-Referral/Network Job Intake (partner, SLA, rate sheet, portal job ID, proof-of-service rules), Price Override/Discount Approval, Job Source Record (retail vs partner, campaign/keyword for marketing ROI).
- **Field/on-scene:** VIN Capture & Validation (barcode scan + manual fallback + photo proof), Pre-Service Condition Photos Checklist, Work Performed Report, Lug-Nut Torque Log, Tool & Equipment Use Log, Field Safety Checklist, Customer Signature: Work Completed & Vehicle Released.
- **Inventory/parts/warranty:** Catalog Item Request, PO, Receiving Log, Part Installation Record (links item→job→VIN, serial/lot, warranty start), Warranty Registration & Claim, Battery Core Return Manifest, Consumables Usage Log.
- **Accounting/tax:** Estimate→Invoice handoff (lock line items, freeze rates), Payment Receipt, Refund/Credit Memo, Expense Report, Mileage Log, Sales Tax Exemption capture, **Chargeback Response Packet Checklist** (signed auth, comms logs, GPS proof, before/after photos), Vendor W-9.
- **Safety/compliance/incident, insurance/claims, HR/contractor, IT/privacy, quality/CX** sections as listed (incident reports, JSA, torque wrench calibration log, COI request, subrogation packet, contractor agreements, device sign-out, data request workflow, re-torque appointment reminder, post-service safety advisory).
- **Smart Form Packs (bundles):** Tire Service Pack, Jump-Start/Battery Pack, Lockout Pack, Fuel Delivery Pack — each = Authorization + addendum + photos/logs + invoice (+ reminders/warranty as applicable).
- **Standard metadata on every form:** Job ID, timestamps, approximate GPS, technician ID, VIN (required before invoice), vehicle MMYC, job source, partner job ID, photo set refs, signatures, and a **"Legal Text vX.Y" version field** so disclaimers can update without corrupting history.

**Document master list & versioning** ("Document List Request", Dec 2025): ~50-item company document list. Versioning split: (a) **version the template/policy** for catalog, price sheet/rate card, T&C, warranty policy, handbooks, NDAs, privacy policy, data retention policy; (b) **revision history per instance** for all transactional docs (SR, work order, estimate+approval, invoice, receipts, refunds, incident reports, POs, inventory forms, closeout reports, etc.); (c) marketing assets don't need versioning.

---

## 7. Document Intake / AI document processing feature

**Sources:** "Document Processing Feature" (May 2026), "Document Intake System" (May 2026)

Core rule: **"capture → classify → extract → review → approve → post."** AI/OCR only creates a draft extraction; official accounting/inventory/job-cost/invoice records are created **only after human review and approval**. Never blindly assign receipt lines to customer jobs/COGS.

- **Flow:** upload image/PDF → create document_upload record → OCR/AI extraction (OpenAI vision-capable model via Responses API `input_image`, Structured Outputs with strict JSON schema; `input_file` for PDFs) → classify document type → extracted draft → 3-panel review screen (left: document image w/ zoom/rotate; middle: editable extracted fields with per-field confidence scores; right: posting actions) → user approves → records created → Posted.
- **Document statuses:** Uploaded, Processing, Classified, Extracted, Needs Review, Review In Progress, Approved, Posted, Rejected, Archived, Duplicate, Failed Extraction.
- **Supported document types (min):** customer_invoice, vendor_receipt, vendor_bill, purchase_order, payment_receipt, estimate, work_order, service_report, warranty_document, refund_receipt, credit_memo, core_return_document, customer_authorization, unknown (unknown → review, never posted).
- **Line-item-level classification (Jason requirement):** each receipt line classified independently by multiple dimensions — What is it? / Why bought? / Where does it post? / Billable? / Attached to job? Categories: Customer Job Part, Inventory Part, Shop Consumable, Tool/Equipment, PPE/Safety Supply, Vehicle/Fleet Expense, Fuel Expense, Food/Meal, Office/Admin, Software/Subscription, Vendor Fee, Sales Tax, Core Charge/Core Deposit, Return/Refund/Credit, Personal/Non-Business, Uncategorized. Examples: Dorman radiator fan → job part/inventory/COGS by intent; PB Blaster/brake clean → Shop Consumable (no job attachment required, not billable by default); Torx sockets/C-clamp → Tool/Equipment; gloves → PPE; NOS energy drink → Food/Personal (never auto job-cost); core charges get their own category posting to Core Deposits Receivable/Payable with resolution states Returned/Forfeited/Refunded.
- Tool capitalization setting idea: capitalize tools over $500, expense under $500.
- **Duplicate detection:** Vendor + Invoice Number + Total + Date; duplicate images attach as additional source images without creating second vendor bill.
- **Matching logic:** customers by phone/email/name/address/related doc numbers; vehicles by **VIN first, plate+state second**, customer+YMM weak-match only (no finalized vehicle without VIN unless app rules allow); vendors by name/phone/email/aliases; jobs/invoices by document numbers, VIN, date proximity, amount proximity; parts by SKU / manufacturer part number / vendor part number / UPC / description similarity.
- **Tables:** document_uploads (or document_intakes), document_extractions (raw + normalized JSON, model, confidence, warnings), document_extraction_line_items, vendor_documents + vendor_document_lines, document_matches, document_review_decisions, document_posting_logs.
- **Strict JSON extraction shape** includes: document_type + confidence, document/order numbers, dates, source_party/target_party, vehicle block (VIN/plate/state/YMM/color), financial_summary (subtotal/tax/fees/discounts/total/paid/balance), payment (method/last4/auth), line_items (with category_guess, expense_type_guess, inventory/resale/warranty candidates), warranty block, core_deposit block, matching_hints, warnings.
- Config via `.env`: OPENAI_API_KEY, OPENAI_MODEL (e.g., gpt-4.1-mini), OPENAI_DOCUMENT_EXTRACTION_ENABLED. Never hard-code keys or model names; never put keys client-side.
- Routes: /documents/intake, /upload, /{id}/review, /{id}/approve, /{id}/reject, /{id}/reprocess.
- Accounting posting: paid-by-debit → Debit Tools/Supplies/COGS/Inventory, Credit Checking/Card; unpaid commercial account → Debit expense/inventory/COGS, Credit AP.

---

## 8. User roles, permissions, and personnel data

**Sources:** "Define user roles" (Aug 2025), "Project plan request" (Sept 2025 spec), "Technician Driver Info Checklist" (Dec 2025)

**Four roles:** Admin (full control), Dispatch/Customer Service (SR/SO/WO management, invoices, payments, limited read-only accounting), Driver/Technician (assigned jobs, update job status, edit vehicle records in field, catalog adds on own jobs, capture notes/photos/signatures), Customer (submit requests, view/pay own invoices, own vehicles/history). Permission matrix documented in Specifications v1.0 (CRUD by module per role). Note: current app is single-user; Users module = Owner Profile now, role structure future-ready.

**User registration:** public registration = Customer role only (hardcoded); Admin panel creates all roles. Common fields: first/last name, email (unique, login), phone, password (min 8 chars, 1 upper, 1 digit; bcrypt/Argon2), role (admin-only field). Role-specific: Driver → license number, vehicle type, notes; Dispatch → internal ID, work hours; Customer → address, vehicle info prompt. **All roles should have a notes field** (explicit Jason requirement). Public route forces role=Customer; status active or pending w/ optional email verification.

**Technician/driver record keeping** (8 sections): identity/contact (+ emergency contact); status/availability (active/inactive/suspended, worker type owner-operator/employee/contractor, can-accept-jobs toggle, blackout dates); operational dispatch profile (service radius, preferred zones, capabilities checklist, equipment checklist, constraints); work vehicle + insurance (carrier, policy #, effective/expiration, proof upload) + license (option to store only "verified on date" + expiration for privacy); pay setup (hourly / per-job / percentage / hybrid, mileage rate, after-hours rules, payout method, W-2 vs 1099); compliance/agreements (signed dates, background check status, do-not-dispatch flag + reason); performance (system-calculated job counts, ETA averages, complaints); notes + attachments. Full MySQL DDL produced: users, user_dispatch_profiles, user_payment_profiles, user_work_vehicles, user_compliance, files, user_files (doc_type enum: insurance_proof, license_proof, certification, profile_photo, other). Unique key on phone.

---

## 9. Database schema ideas

**Sources:** "Database Schema for Service Business" (Gemini, early), "Project plan request" Specifications v1.0, "Router controller documentation"

- Early Gemini schema (14 tables): Users, Customers, Vehicles, Vendors, Products (with QuantityOnHand, CostPrice/UnitPrice), Services (DefaultRate, IsFlatRate), ServiceRequests, ServiceOrders, WorkOrders, Invoices, InvoiceLineItems, Receipts, WorkOrderProducts, WorkOrderServices. **ServiceBrokers table added** on request — Jason asked for the proper title for "someone that contracts out their requests"; answer: **Service Broker** (a WorkOrder can be assigned to an internal User OR contracted to an external ServiceBroker).
- Specifications v1.0 (Sept 2025) MVP tables: users, customers, vehicles (unique plate+state at that time), customer_vehicles (many-to-many w/ is_primary), service_requests, service_orders, work_orders, catalog_items (type service|part, unit_price, tax_category, warranty_template_id), service_order_lines & invoice_lines (with **unit_price_snapshot / tax_category_snapshot**), invoices (status draft|issued|paid|void, pdf_path), payments, receipts, expenses, warranty_records, **status_history** (entity_type, entity_id, from/to status, changed_by, note), settings (key/value_json).
- MySQL status enums (Router doc): service_requests ENUM('PENDING','ACCEPTED','COMPLETED','CANCELLED','REJECTED'); service_orders ENUM('PENDING','DISPATCHED','COMPLETED','CANCELLED','INVOICED'); work_orders ENUM('PENDING','ASSIGNED','ACKNOWLEDGED','EN_ROUTE','ON_SITE','COMPLETED','CANCELLED','NO_SHOW').
- Router/middleware pattern: front controller → router → middleware order (correlation ID, auth, roles, JSON parsing, idempotency, rate limit) → controller; problem+json errors 400/401/403/404/409 (state guard)/422 (domain validation). Full OpenAPI 3.1 spec + controller/service stubs generated ("WKR API v1").

---

## 10. Partner pricing, volume matrix, and the tip incident

**Sources:** "Dispatcher tipping frequency" (Oct 2025), "Formal letter writing" (Oct 2025)

**Tip incident:** A partner company (dispatcher) kept a customer tip meant for Jason (the driver), excusing it as "we assumed the tip was for dispatch." Jason's stance (from his drafted letter): he claims no ownership of tips in general — only tips explicitly requested/acknowledged as intended for him and his employ; he expects those to be handled properly; handshake/good-faith deal considered broken. Consequences he set: demand receipts for any customer payments including tips going forward; **end the above-market discount** he had been giving; discount eligibility examined the **first working day of every month**; pricing unchanged within a calendar month; pricing announced before jobs are dispatched; matrix published on the website with strict adherence.
- His old sweetheart rates to that partner: $60 tire change, $70 repair, $100 mobile mount & balance. Guidance: such partner-tier rates should require ~50+ jobs/month; 15–40 jobs/month deserves only a modest discount.

**FINAL Volume Pricing Matrix (fixed price per tier; based on jobs OFFERED, not completed — "We will not penalize for unavailability"):**

| Service | Retail 0–10 | Standard 11–24 | Bulk 25–49 | Premium Bulk 50+ |
|---|---|---|---|---|
| Tire Change | $85 | $75 | $68 | $60 |
| Tire Plug | $95 | $85 | $78 | $70 |
| Tire Patch | $125 | $110 | $100 | $90 |
| Mobile Mount and Balance (tire cost additional) | $150 | $125 | $113 | $100 |
| Jumpstart | $85 | $75 | $68 | $60 |
| Lockout | $95 | $85 | $78 | $70 |
| Fuel Delivery (plus fuel) | $85 | $75 | $68 | $60 |
| Mobile Mechanic (per hour) | $125 | $125 | $125 | $125 |
| Diagnostic Fee | $85 | $85 | $85 | $85 |

Tier discounts: Retail 0%, Standard ~10–15%, Bulk ~20%, Premium Bulk ~25–30% (contract-level, top dispatch priority). Corrections Jason made along the way: "Mobile Mount and Balance" is the job name (not "Mount & Balance (+ tire)"); the tire is additional to the price; each tier gets a set round-number price, not a range. Deliverables: branded PDF, Excel, HTML for website.

---

## 11. SMS / 10DLC opt-in & keyword messaging (Telnyx)

**Source:** "Updating opt-in workflow and keyword messaging documentation" (Claude, Jan 2026). Final, Telnyx-template-exact wording:

- **Opt-in confirmation (START):** `{business_name}: Thanks for subscribing to roadside assistance and service updates! Reply HELP for help. Message frequency may vary. Msg&data rates may apply. Consent is not a condition of purchase. Reply STOP to opt out.`
- **Opt-out confirmation (STOP):** `{business_name}: You are unsubscribed and will receive no further messages.` (Must NOT add "Reply START to resubscribe"; must NOT include extra rate/frequency text.)
- **Help confirmation (HELP):** `{business_name}: Please reach out to us at {support_phone} or {support_email} for help.` (Must NOT include STOP instruction or rate notices.)
- **Verbal opt-in agent script (before collecting the number):** "By providing your phone number, you agree to receive SMS service updates and roadside assistance notifications from {business_name}. Message frequency may vary. Standard Message and Data Rates may apply. Reply STOP to opt out. Reply HELP for help. We will not share mobile information with third parties for promotional or marketing purposes." Then confirmation SMS (no brand prefix): `You have agreed to receive SMS updates from {business_name}. Msg freq may vary. Std msg & data rates apply. Reply STOP to opt out, HELP for help.`
- **Inbound-message auto-response:** `Thank you for your message to {business_name}! We will be with you shortly. Msg freq may vary. Std msg & data rates apply. Reply STOP to opt out, HELP for help. We will not share or sell your mobile information for marketing/promotional purposes.`
- **Operational message rules:** every message starts with brand name; include "Reply STOP to opt out. Msg & data rates may apply."; do NOT include "Reply HELP" in operational messages (opt-in confirmations only). Four sample messages: estimate approval link, dispatch notification, service completion, technician en-route w/ ETA.
- **Web-form opt-in disclosure must include:** "Consent is not a condition of purchase" and "Your mobile information will not be sold or shared with third parties for promotional or marketing purposes."
- Five opt-in methods documented: digital (web form), verbal (phone/in-person), keyword, inbound message, physical (paper). STOP variants accepted: CANCEL, END, QUIT, UNSUBSCRIBE, etc.
- Campaign facts: privacy policy https://wkrllc.com/privacy-policy.html; terms https://wkrllc.com/terms-of-service.html; webhook proxy https://www.wkrllc.com/siteground-webhook-proxy/webhook.php; subscriber opt-in/out/help = Yes; embedded links & phone numbers = Yes; no number pooling, no lending, no age-gating.

---

## 12. Oregon regulatory compliance (ORS 646A.480–646A.495)

**Source:** "Bullet List Formatting" (Jan 2026) — verified compliance checklist Jason approved ("That is exactly what I wanted").

- These vehicle-repair-shop statutes impose estimate/authorization/disclosure/recordkeeping duties; they do NOT create a DOJ licensing program (other city/state licenses may still apply).
- **Written estimate before work starts** (ORS 646A.482): nature of work, cost breakdown incl. labor and parts, incidental charges, total (may be a range).
- **Authorization** (ORS 646A.486): documented owner assent — signature, documented phone assent, or electronic/fax — required when estimate exceeds **$200** and for certain revisions/overages. (This is the statutory root of the app's $200 rule.)
- Disclose all potential fees (diagnostic, storage, shop fees) as line items/incidentals.
- Parts: don't install used parts when estimate says new (646A.490(1)(b)); disclose used/reconditioned parts (646A.490(1)(c)); record part type per line (OEM/new/aftermarket/used).
- Don't charge for work not performed (646A.490(1)(a)).
- **Recordkeeping: retain legible copies of all required documents ≥1 year** (646A.490(2)(b)); app should keep longer for tax purposes.
- No statutory minimum warranty, mandatory mediation, or payment-methods mandate — but publish accepted payment types, keep dispute intake workflow, keep ads truthful (UTPA / ORS 646).
- Roadside applicability: if performing roadside diagnosis/repair for compensation, treat as subject to the same estimate/authorization/recordkeeping controls; use a work order on every roadside job.
- Follow-up work: a coding-agent spec was generated mapping this to document codes (SR/EST/EAP/WOR/COS/INV/PAY/REC), a state machine (work blocked until estimate + authorization), and the EAP/CAU gate formulas (§3).

---

## 13. VIN lookup integration (plate+state → VIN)

**Source:** "App summary creation" (Sept 2025)

- Scraping AutoZone's site lookup is off-limits (TOS, DPPA, technical blocks). Legit providers with plate→VIN: Auto Data Direct (ADD/DMV123), ClearVin (~$2.50/lookup dealer program), VinAudit (~$1/request API; $9.99 full report), VinCheckPro, CARFAX via integrators. NHTSA vPIC is free but VIN-decode only.
- **Chosen: Auto.dev Plate-to-VIN API** — free tier 1,000 calls/month ("1000 is WAY MORE than I could possibly use"). Hybrid flow: if VIN known → free vPIC decode; if plate+state → paid plate→VIN once → vPIC decode → cache result so lookups are never re-paid.
- Requirement: setup directions built into the application + Settings section for account variables. Settings page (Admin → Settings → Vehicle Data Providers): provider enum (`auto_dev` default, `manual_only`, `clearvin`, `add`), API key (secret), base URL, timeout ms (5000), **permissible use reason (required, logged per DPPA)**, enable toggle, cache TTL (default 43200 min = 30 days), audit logging toggle, optional daily lookup limit.
- Tables: `app_settings` (scope/key/value/is_secret), `plate_vin_cache` (unique plate+state, VIN, raw payload JSON), `plate_vin_audit` (user, plate, state, vin, provider, permissible_use, status HIT/MISS/ERROR/CACHED).

---

## 14. UI / branding

**Sources:** "Major app features list", "Roadside assistance app prompt", "Roadside Service Request Form Creation" (Gemini), "Project plan request", "App summary creation"

- **Dark theme, neon blue/purple accents.** Early palette: base #121212/#1A1A1A, neon blue #00FFFF, neon purple #C500FF; spec v1.0 uses background #0B0E14–#111827 range. Forms bundle CSS variables: bg #0b0f1a, panel #111827, text #e5e7eb, primary #60a5fa, secondary #a78bfa.
- Sidebar navigation with **push-effect buttons (not links)**, glow on hover, active-tab glowing accent border, icons+labels collapsing to icons; inspiration: Behance "Dashboard Sidebar Menu — Lead Management CRM"; SaaS references: Stripe Dashboard, Notion dark UI, Linear.app.
- 3-panel layout (header, sidebar, main content); responsive/mobile-first; sticky Save/Cancel action bars.
- Form styling decisions (Gemini SR form iterations): text boxes must be clearly visible with **illuminated/glowing borders**, low depth (flat) fields, **red asterisk on every mandatory field**.
- Catalog picker modal: search, categories, single-click add, auto-close after select. Status changes require confirmation + optional note (stored in status_history).
- Brand identity: "dark metallic/neon, forged-from-fire" White Knight Roadside identity.

---

## 15. Misc / early artifacts

- **Gemini "Solo Developer App Design Workbook"** ("Document Review and Improvement Offer"): earliest 10-step planning doc (Tkinter/SQLite era — superseded) but contains a still-useful **AI integration opportunity list**: [High] address autocomplete, ETA prediction; [Medium] auto job classification from request text, internal chat assistant, document OCR auto-categorization; [Future] smart job assignment, financial insights, service usage analysis, inventory alerts, vendor reliability analysis, driver behavior trends.
- **AGENTS.md practice** ("Write application instructions"): repo carries an AGENTS.md with hard business rules ("do not simplify"), canonical workflow definitions, and a PR checklist for coding agents (VIN-required invoice rules, catalog-only items, server-side totals, docs updated, tests added). CONSTITUTION.md concept ("Project plan request") holds mission, core principles (Accuracy First, Lifecycle Integrity, Professionalism, Scalability, Transparency), in-scope/out-of-scope, non-negotiables.
- **V1 explicit exclusions** (Sept 2025): messaging/notifications, document uploads/processing, advanced analytics — all later planned as modules (SMS module, Document Intake) in the 2026 model.
- Specifications v1.0 (Sept 2025) also defines: acceptance criteria examples, test plan, migrations/seeding, error envelope `{errors:[{code,msg,field?}]}`, performance target sub-200ms p95 local CRUD, Argon2id password hashing, daily DB backups.
