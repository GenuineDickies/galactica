# Product / Requirements Notes — Bin product-2

Sources distilled: *(ChatGPT: "Modular app breakdown")*, *(Claude: "Expanding the roadside service document workbook")*, *(ChatGPT: "Company Document Checklist")*.

---

## 1. App Architecture: Modular / DDD Breakdown

*(ChatGPT: "Modular app breakdown")* — App referred to here as **"Indie Roadside Admin"** (in the Claude workbook chat it is called **"RoadRunner Admin"**). Stack: **PHP 8.2 OOP, no heavy framework**. Jason explicitly decided: **"I would like to do the app with DDD adherence."** Modules are treated as bounded contexts with strict ownership — single source of truth per module, no cross-context direct DB access, communication via APIs/domain events, snapshots for pricing/tax history.

### The 17 modules (bounded contexts)
1. **Identity & Access (IAM)** — Users, roles (Admin, Dispatch, Driver, Customer), auth, RBAC.
2. **Customers & Accounts** — individuals + organizations, contacts, agreements, provider accounts, billing prefs, tax exemption flags.
3. **Vehicles** — vehicle registry, VIN decodes, plate history.
4. **Job Intake: Service Requests** — first touch; intake notes; source = Retail vs Provider; read-only subtotal + disclaimer.
5. **Service Orders** — internal "we are under contract" record, distinct from Work Order; pricing/tax snapshots.
6. **Work Orders** — what the technician receives; timestamps (en-route/on-site/complete), photos, signatures.
7. **Dispatch & Scheduling** — assignment, slots, ETA, map.
8. **Catalog: Products & Services** — replaces standalone Inventory; canonical list for all line items; "+" picker modal that closes on select.
9. **Pricing & Tax** — deterministic totals; fee schedules (mileage, after-hours); price snapshots.
10. **Warranty Tracking** — policy, instance per invoice line, claims.
11. **Invoicing & Payments** — invoices, lines, payments, receipts, refunds, PDF generation.
12. **Accounting** — ledger, expenses, categories, simple P&L; "revenue ≠ income" categorization policy.
13. **Media & Documents** — photos, signatures, PDFs, retention.
14. **Integrations** — VIN/plate provider (auto.dev mentioned), maps/ETA, payment gateways; keys entered in Settings before use.
15. **Audit & Compliance** — immutable trails, per-record audit tab.
16. **Settings & Admin Console** — taxes, fees, statuses, templates, legal text/disclaimers.
17. **(V2) Messaging & Notifications** — SMS/email/push, templates, delivery logs.

### Status enums (Jason's specified rules, baked in)
- **Service Request:** Pending, Accepted, Completed, Cancelled, Rejected.
- **Service Order:** Pending, Dispatched, Completed, Cancelled, Invoiced.
- `ServiceRequest.Accepted` event triggers Service Order creation flow.

### Suggested MVP build order
IAM → Customers & Vehicles → Intake (SR) → Catalog → Pricing/Tax → Service Orders → Work Orders + Media → Invoicing/Payments → Accounting → Settings/Integrations.

### DB table map (by module, abbreviated)
users/roles/permissions/sessions/api_keys; customers/locations/contacts/agreements/provider_accounts; vehicles/vehicle_plates/vin_decodes; service_requests/sr_items/intake_notes; service_orders/so_items/so_status_history/so_price_snapshots; work_orders/wo_actions/wo_media/wo_signatures; assignments/tech_slots/etas; catalog_items/price_rules/warranty_templates; tax_configs/fee_schedules; warranties/warranty_claims; invoices/invoice_lines/payments/receipts/refunds; ledger_entries/expenses; media/doc_links; integration_accounts/credentials/sync_jobs; audit_events/access_logs; settings/templates.

Deployment path: start monolith with strict module folders → modular monolith → split hot paths (Dispatch, Driver, Billing) later if needed; message queue (Redis streams / RabbitMQ) for event consumers.

---

## 2. Vehicle / VIN / Plate Rules (FINAL, authoritative)

*(ChatGPT: "Modular app breakdown")* — Jason corrected an early draft that said "invoice requires plate+state." Final rules:

- **Vehicle creation requires a VIN** — either typed directly or obtained via plate→VIN lookup ("we can obtain VIN *sometimes* if a plate is entered"). No vehicle record from plate alone. `vehicles.vin CHAR(17) NOT NULL UNIQUE` (VIN is the Vehicle's natural identity in DDD).
- **Invoice never requires a plate.** If a vehicle was involved, VIN is required and the invoice must link to that vehicle.
- **"No vehicle involved"** radio button allowed only where it makes sense (e.g., mobile mount & balance, tire plug, "Other" — someone brought the tire to us; deliveries). Never for jumpstart/spare tire swap.
- Mechanism: each service type (catalog item) carries a **`vehicle_not_required` flag** (Jason: "I will indicate which type of service will allow for no vehicle involved in the database"). "No vehicle involved" is selectable **only when ALL line items** on the invoice are flagged `vehicle_not_required = true`. If selected, a short **`no_vehicle_reason`** text is required.
- Flag is **snapshotted onto line items** (`vehicle_not_required_snapshot` on sr_items/so_items/invoice_lines) so historical invoices stay valid if the catalog flag later changes.
- Over time a vehicle may have **multiple plates**; plate history stored with effective dates; `(plate, state)` unique pair.

### Vehicle record TIMING (from the workbook chat — supersedes any "vehicle at intake" idea)
*(Claude: "Expanding the roadside service document workbook")* — Jason's dictated rule, verbatim in substance:
> A formal Vehicle record is NOT created at intake. During intake, dispatch, and on-scene work, basic vehicle information (year, make, model, color, plate) lives directly on the Service Request. The technician captures the VIN on scene as part of the Vehicle Condition Report. The Vehicle entity is created at the Invoice stage, populated from the basic info on the Service Request plus the VIN captured on scene.

- **Plate is also NOT collected until the technician is on scene** (Jason repeated this twice for emphasis).
- Rationale (Jason's correction of the AI's draft rationale): prevents **duplicate vehicles** in the system, **eases intake** (keeps customers from getting flustered), and **avoids putting customers in a dangerous position on the side of highways** hunting for documents/VIN.

Note: the earlier ChatGPT "Company Document Checklist" SR form draft had plate required at intake (with a "No Plate" checkbox) and VIN optional at SR — **superseded** by the above.

---

## 3. Locations: Customer Location vs Job Address Snapshot (FINAL)

*(ChatGPT: "Modular app breakdown")* — Long debate; Jason initially rejected "Customer owns Location" ("I can have a customer without a location"; "that will never be the same twice"). Settled design:

- **Customer Location** = reusable saved entity (fleet yard, Store #142, a regular's home). Created **only when explicitly saved**. Customers do NOT automatically accumulate a record of every place they ever got service.
- **Job Address Snapshot** = per-job value object, **always created** — every SO/WO records where the job actually happened ("I-84 EB mile marker 19"). Lives on the order record, never clutters the customer's saved list.
- **Service Request:** location optional. **Service Order:** location REQUIRED before save/dispatch (SO.create fails without job lat/lng or minimum address). **Work Order:** inherits SO snapshot; driver may refine GPS/descriptor only, never mutates the saved Customer Location.
- If a saved Location is picked, its address + critical notes are snapshotted onto the SO at creation.
- **"Save this location" checkbox** on the SO form (default UNCHECKED). Checked → creates a Customer Location with a label. Jason confirmed this design explicitly.
- SO Location panel final fields: search saved locations (customer-scoped) / OR job address (street, city, state, zip) + Pin on Map + job notes; when saving: **Label + Notes** only — Jason dropped "Cost Center" and renamed "Access Notes" to just "Notes."

---

## 4. DDD Domain Model Reference

*(ChatGPT: "Modular app breakdown")*

### Aggregates → roots (each child accessed only via root; other modules reference only the root's identity)
- **User** (user_id): Role, Permission, Session, ApiKey
- **Customer** (customer_id): Location, Contact, Agreement, ProviderAccount
- **Vehicle** (vin — natural identity): VehiclePlate, VinDecode
- **ServiceRequest** (service_request_id): ServiceRequestItem, IntakeNote
- **ServiceOrder** (service_order_id): ServiceOrderItem, ServiceOrderStatusHistory
- **WorkOrder** (work_order_id): WorkOrderAction, WorkOrderMedia, WorkOrderSignature
- **Assignment** (assignment_id): Slot, ETA
- **CatalogItem** (catalog_item_id): PriceRule, WarrantyTemplate
- **FeeSchedule** (fee_schedule_id): TaxConfig, PricingSnapshot
- **WarrantyPolicy** (warranty_policy_id): WarrantyInstance, WarrantyClaim
- **Invoice** (invoice_id): InvoiceLine, Payment, Receipt, Refund
- **LedgerEntry** (ledger_entry_id): Expense, Category, Report
- **MediaObject** (media_id): Signature, DocLink
- **IntegrationAccount**, **AuditEvent**, **SettingGroup** aggregates similarly.

### Key Value Objects (immutable)
VIN (17-char validated w/ checksum), Plate (string + issuing state), Address, GeoPoint (lat/lng), Money (amount+currency), ServiceCode (e.g. `MOUNT_BALANCE`), PersonName/BusinessName, Phone, Email, LocationSnapshot, ProblemDescription, ScheduledWindow.

### DDD guide-doc set for a programming agent (requested at end of chat)
1) Product Brief (goals: fast intake, **VIN-first** vehicle handling, accurate pricing, clean invoices, reusable customer locations; non-goal: DB-driven CRUD "best effort forms"). 2) **Ubiquitous Language glossary** (Customer, Service Request, Work Order, Vehicle = created from VIN only, Plate = may look up VIN, Service Type w/ vehicle_not_required flag, Invoice, Customer Location, Ad-hoc Location). 3) Bounded contexts: Customer & Locations, Catalog, Vehicles, Service Operations, Billing, Integrations (anti-corruption layer for plate→VIN, payments, maps). 4) Domain model with invariants per aggregate (as in sections 2–3 above).

---

## 5. UI Standards

*(ChatGPT: "Modular app breakdown")*
- **Theme: dark + neon purple/blue**, glowing borders, push-button effects. **Buttons, not links.** Jason liked the AI's color choices in the mockup.
- **Vanilla only:** Jason rejected React/Tailwind for his UI kit — "I actually don't want any libraries." Pure HTML/CSS/JS, no build step; colors via CSS variables (`--neon-blue`, `--neon-purple`, `--active-fill`, sizes `--btn-h`/`--btn-w`). He wants his "very own library" of components (toggles, tabs, modals, toasts, form controls, loaders were queued as extensions). Package: "neon-accordion-kit."
- **Button + accordion criteria (Jason's 9-point spec):** (1) regular and accordion buttons look identical at rest; (2) same size; (3) accordion shows small arrow; (4) arrow points UP when closed; (5) DOWN when open; (6) active accordion gets lit neon glow fill; (7) darker text when lit for contrast; (8) submenu items dimmer than active parent; (9) dark + neon theme with borders and push effect. Also: only one accordion active at a time; regular button active state uses the SAME lit effect; submenu selections trigger actions like buttons.
- Lesson learned (Jason's conclusion): image generation cannot follow multi-criteria UI specs — **produce code, not images**, and test on a live server.
- UI slices: Dispatch Board (kanban + map), Driver App (job stepper, media capture, signatures), Customer Admin, Back Office, Settings.

---

## 6. Operations Document Book (White Knight Roadside)

*(Claude: "Expanding the roadside service document workbook")* — A branded Word-doc "Document Book" for **White Knight Roadside, LLC**, grown iteratively from 41 to 67 pages. Jason's verdict: "I like the formatting and most of what you have produced is good, but it sure does need proofreading to get the minute details right." Structure:

1. Strategic Overview — 6-document chain, workflow stages, RACI, internal SLAs
2. Data Schema — 9 entities: Customer, Vehicle, ServiceRequest, Dispatch, Technician, ServiceLog, Photo, Transaction, Incident; status enums New / Dispatched / En_Route / On_Scene / Completed / Cancelled
3. SOPs — Jump Start, Tire Change, Lockout, Fuel Delivery, Mobile Mount & Balance, Mobile Mechanic (equipment, prerequisites, numbered steps, photo requirements, amber safety hold-points)
4. Phone Intake Script
5. Service Request intake form
6. Waiver (see Signatures below; split 6A/6B)
7. Pre-Service Vehicle Condition Report (panel-by-panel damage grid; VIN captured here)
8. Hazard Assessment Checklist
9. Service Log
10. Customer Refusal + Technician Decline-of-Service forms
11. Customer Receipt (split 11A/11B)
12. Tow Referral Form
13. Incident & Damage Report ("do not admit fault on scene")
14. Daily Truck Inspection
15. End-of-Shift Reconciliation
16. Photo Documentation Protocol
17. SMS Location-Capture (Telnyx/10DLC — see below)

**Schema convention decision:** names stored as **FirstName / LastName separate fields**, not "Last, First" single field.

---

## 7. Signature Policy (FINAL — two signatures total)

*(Claude: "Expanding the roadside service document workbook")* — Jason: "too many signatures will make customer uncomfortable."

- **Signature 1 (before work):** ONE signature covering all three things — (1) authorization to perform the service, (2) agreement to charges, (3) liability waiver/risk acknowledgment. Document renamed **"Service Authorization, Charges & Liability Waiver."** Includes an **AGREED CHARGES table** (Service Fee, Surcharges, Parts/Tire/Fuel, Subtotal, Tax, TOTAL AGREED) filled in the customer's presence before signing — the signature is on real numbers, not a blank check.
- **Signature 2 (at completion):** customer signs acknowledging work completed (Jason: "customer will sign at completion, so two signatures").
- **If WKR refuses service or refers to tow:** customer signs NOTHING; the **technician** signs that no work was done. No receipt issued for work not performed = no liability. Customer info never given to a third party. Tow referral = give tow company contact info only (no tow partners at this time).
- Waiver also contains: photo consent, Multnomah County arbitration clause, payment terms ($35 returned-payment fee), AS-IS used tire acknowledgment block.

### Oregon Change Order threshold
Jason: **"Oregon law states that any change that is greater than 10% or $200, whichever is less, signature is required."** Encoded as Principle 4:
- Change ≤ threshold (≤10% AND ≤$200): proceed without signature, but MUST verbally inform customer and log in Service Log.
- Change > threshold: STOP work, written Change Order with new total, customer signature before resuming; amendment becomes the new TOTAL AGREED.
- Worked example: $400 agreed → 10% = $40 controls; $3,000 agreed → $200 cap controls. Smaller threshold always wins. Basis: ORS 646A.480 auto-repair rules (attorney review flagged).

---

## 8. Account Types & Document Routing (FINAL)

*(Claude: "Expanding the roadside service document workbook")* — Account types: **Retail, Fleet, InsurancePartner, MotorClub**. Core principle: the driver of a non-Retail vehicle generally does NOT see pricing; drivers of InsurancePartner/MotorClub vehicles **never receive any documents/pricing from WKR under any circumstance**. Fleet drivers represent the company in some capacity, so exposure isn't catastrophic — but default is no-pricing.

| AccountType | Before work | At completion | Pricing goes to |
|---|---|---|---|
| Retail | Waiver 6A (full, with charges) | Receipt 11A (full pricing) | Driver |
| Fleet — driver authorized (**verbal OK from fleet contact is sufficient**) | 6A | 11A | Driver + Fleet account |
| Fleet — default (not authorized) | 6B Driver Authorization (no charges) | 11B Service Completion Acknowledgement (no pricing) | Fleet account only |
| InsurancePartner | 6B | 11B | Partner only |
| MotorClub | 6B | 11B | Motor club only |

- **6B "Driver Authorization"**: authorization + risk + photo consent + release, NO charges/totals anywhere; driver signature acknowledges pricing was not seen; tech attests no pricing disclosed. Driver IS authorized to sign the waiver (represents the vehicle) and the completion acknowledgment — just no payment involvement.
- **Schema field:** `DriverAuthorizedForBilling` boolean on Customer — Fleet only, default FALSE, always FALSE for InsurancePartner/MotorClub; set TRUE on verbal confirmation from the fleet contact. Determines which form variant prints. Jason rejected "written authorization required" as overbearing.
- System-level enforcement recommended: form template literally cannot include charges block when AccountType ∈ {InsurancePartner, MotorClub} or (Fleet AND flag=false). The priced Partner Invoice is generated separately and transmitted to the account (a possible "11C" not yet drafted).

---

## 9. Service Policies & Pricing (FINAL decisions)

*(Claude: "Expanding the roadside service document workbook")*

### Mobile Mount & Balance = tire REPLACEMENT service
- Equipment reality (Jason's correction): **custom-made, self-powered (leverage) mount machine + level/bubble balancer** — nothing hydraulic or computerized. Balancer is **self-balancing; does not need a level surface** — flat, mostly level is OK.
- **Tire size is required at time of service request** (from sidewall, e.g. "225/65R17"), plus tire count and who supplies the tire.
- WKR generally **purchases a used tire en route** from a third-party supplier. Guarantee = **fitment match, not brand match**; sold **AS-IS, no warranty**.
- **Old-tire disposal:** removed tire rides with the tech until the next job's tire-purchase trip, then dropped at the supplier (normal tire-shop practice).
- **Pricing:** under 18" used tire ≈ $40 purchase → **$50 sale**; **18"+ = custom rate**. Whole package: **$150 service + list price of tire**. **Customer-supplied tire: $100 service, no tire cost.** (Workbook added: +$50 service + $50 tire per additional WKR-supplied tire; +$35 service per additional customer-supplied tire — AI-drafted extensions.)
- En-route inspection: correct size, DOT date preferred under 6 years (Jason had the 6-year language **softened** — "these customers are often stranded with no tire and would drive on a wagon wheel"; tread ≥5/32" and defect checks stay), no sidewall damage/dry rot/shoulder plugs; photos of size markings, DOT code, tread depth; re-quote via dispatch before any purchase exceeding the quote.
- **AS-IS Used Tire Acknowledgement** signed BEFORE installation (records DOT date code, tread depth in 32nds, brand).

### Jump Start
- If vehicle doesn't start after 2 attempts: the need is **towing, a new battery, a starter, or a fuel pump replacement — WKR can determine which and provide a repair quote** (Jason's correction; not just "tow or battery").
- **Failed-attempt billing (three branches, Jason's policy):**
  1. Customer called specifically for a jumpstart and it fails → **customer still owes the fee** ("we performed the service requested; it is not our fault it is not successful").
  2. WKR suggested the jump attempt (customer originally wanted a tow) → **no charge**; we initiated, we eat it. (Also good incentive structure against techs over-suggesting.)
  3. Conversion: customer accepts on-the-spot battery/starter replacement → **jump fee waived as goodwill** ("the failed jumpstart becomes a diagnostic step we wouldn't charge for anyhow"). Choice between 1 and 3 is the customer's.

### Fuel Delivery
- **Internal fuel tank work is NOT out of scope** — Jason has done at least 3.

### Tire Change
- **Floor jacks, not bottle jacks** ("are you insane? We use floor jacks").
- Placing the removed flat under the vehicle: not as a safety stand, but "it isn't gonna hurt and it keeps the tire out of the way."

### Lockout (judgment-based policy)
- **It's a judgment call. NEVER unlock an unattended vehicle. Use best judgment / gut feeling. When in doubt, walk** — and if WKR declines a lockout, **no charge**.
- Verification: look at photo ID before unlocking, or match registration AFTER the door is opened (registration is locked inside — that's the whole point). Jason rejected elaborate verification checklists (interior-description tests, DMV lookups, fleet verification) as unrealistic. No domestic-abuse/stalking framing in the doc.
- **Child or animal locked inside = emergency: unlock immediately.** Jason emphatically rejected "call 911 and wait" — "you are telling me it is better to stand there watching kids locked inside while I have the tools to unlock the door?"
- Technique (Jason's field knowledge, added to SOP): **NEVER pull an unlocked door open by the handle while inflaters (air wedges) are in place — you will break the cable.** Get the door to unlock without fully opening, remove equipment, then open. Test whether a door is unlocked with a light handle pull — a tension appears about halfway through the pull (latch trying to disengage) that wasn't there when locked. Also: "near the latch" placement guidance was wrong — cut.

### Refusal / cancellation fees
- **If WKR refuses service: keep NO funds, not even a dispatch fee.**
- **If the customer cancels after WKR is on scene: the dispatch fee still applies.**

---

## 10. SMS Location-Capture Flow (Telnyx, 10DLC)

*(Claude: "Expanding the roadside service document workbook")* — Section 17 of the workbook. Purpose: text stranded callers a link to a WKR web page that captures their GPS location (callers often can't describe where they are).

- **Consent:** verbal during phone intake — the agent **checks a box stating verbal consent was granted; no recording required** (Jason confirmed and had the doc edited to match). Web-form path: never-pre-checked checkbox. Ambiguous responses must be re-asked.
- **Five locked message templates** (L1 initial location request, L2 reminder, L3 tech en route, L4 STOP confirmation, L5 HELP response); variations require re-approval; variables like {JOBID}, {LINK}, {TECH_NAME}.
- 10DLC campaign scope: Customer Care only, no marketing. Sender ID + opt-out + help language mandatory. STOP/STOPALL/UNSUBSCRIBE/CANCEL/END/QUIT honored automatically; START/UNSTOP/YES re-opt-in; HELP handled. TCPA window 8 AM–9 PM unless customer-initiated within 30 min (the normal roadside case). No SHAFT-C content; **no public URL shorteners** (carrier-filtered).
- Link token: **one-shot, 4-hour max expiry**. Customer taps → HTML5 Geolocation permission → fallback = manual map pin or call dispatch.
- Dispatcher procedure: E.164 validation, consent flag check, send, auto-reminder on 5-minute timer. Failure-mode table (8 scenarios).
- **Retention:** SMS logs 4 years, consent records 4 years, opt-out records indefinitely. TCPA exposure framed: $500/message base, $1,500 willful.
- Registration mechanics live in a separate **10DLC Compliance Workbook** (referenced, not duplicated).

---

## 11. Company Document Checklist & Filing System

*(ChatGPT: "Company Document Checklist")*

### Minimum viable 10-document stack
1) Intake Form 2) Service Request 3) Estimate/Quote with approval signature 4) Authorization to Work 5) Work Order 6) Invoice 7) Receipt 8) Warranty Record (when parts installed) 9) Incident/Damage Report 10) Terms of Service + Pricing Policy.

### Full checklist categories
Customer + job docs (incl. Refund/Adjustment Form, Photo/Evidence Log, Signature Capture Record); customer-facing policies (Terms of Service, Pricing Policy Sheet, **"Attempt Fee"/non-success disclaimer** — foreshadows the jumpstart policy, Cancellation/No-show, Privacy Notice); accounting (chart of accounts, expense capture, reconciliation, sales tax, P&L, asset register, mileage log); vendor/parts (vendor list, POs, inventory log, core/return tracking); legal/compliance (LLC docs, EIN, licenses, insurance certs incl. garagekeepers/on-hook, B2B/fleet contract templates, record retention policy); safety/ops (roadside safety SOPs, equipment inspection checklists, emergency plan, training checklist); hiring docs; sales/marketing (service menu, review request script, brand kit).

### Job folder filing system
- Folder name: `YYYY-MM-DD__SR-######__Last-First__Year-Make-Model__Service`
- Standard numbered subfolders per job: 00_Intake, 01_Service_Request, 02_Estimates, 03_Authorizations, 04_Work_Order, 05_Invoices, 06_Payments_Receipts, 07_Warranty, 08_Incident_Damage, 09_Photos_Evidence, 10_Communications, **99_Export_PDF_Packet** (only customer/court-ready PDFs).
- File naming: `SR-001234__Estimate__v1.pdf`, `SR-001234__Authorization-to-Work__Signed.pdf`, etc. — makes Windows search the retrieval tool.
- Three rules: every job gets a folder; every customer-facing doc exports to the packet folder; nothing job-related lives outside the job folder. Best long-term: the app auto-creates SR ID + folders + saves PDFs into place.

### "Job Vault" module (online platform version)
- **Storage decision: Option A — Database + object storage** (files in /storage or S3-compatible; DB holds metadata), with the customer-visible folder layout produced at ZIP-export time. Internal storage mirror: `/storage/jobs/YYYY/MM/SR-xxxxxx/<numbered subfolders>`.
- Tables: jobs (sr_number unique), document_categories, documents (storage_key, sha256, mime, versioned), document_versions, templates (estimate/invoice/auth/policy; html/docx/markdown engines), export_packets.
- Features: jobs index searchable by name/plate+state/SR#/phone; per-job Documents tab with auto-categorization; template library ("create from template" produces a job-tied versioned doc); PDF generation (DomPDF/wkhtmltopdf), regeneration creates versions; **Packet export** — Estimate Packet (estimate + auth + key photos), Invoice Packet (invoice + receipt + completion signature + key photos), Full Job Packet — as a ZIP mirroring the folder layout; "mark as key evidence" checkbox feeds packets.
- Use cases justifying it: charge disputes (open packet folder, done in 30 seconds), warranty claims, taxes. Hosting: VPS + nightly DB and file backups.

### Service Request form draft (early wireframe — partially superseded)
Sections: header (SR# auto, status New→Dispatched→En Route→On Scene→Completed→Invoiced→Paid, priority Normal/Urgent, action buttons incl. Create Estimate/Work Order/Invoice — invoice disabled until rules met); Customer (First/Last, phone masked (xxx) xxx-xxxx, email optional, notes); Service Location (address, cross streets/landmark, GPS, "safe spot to work?" + hazards); Vehicle as-reported (Year/Make/Model/Color required); Service Requested (type dropdown: Jump Start, Lockout, Tire Change, Fuel Delivery, Battery Install, Diagnostics/No Start, Tow Coordination, Other + "Customer states…" description + per-service condition fields: keys inside, flat position LF/RF/LR/RR, fuel type, battery access, no-crank vs crank-no-start); Dispatch & timing timestamps + append-only internal timeline log; Pricing Snapshot (catalog-only line items via + Add Item, trip fee, mileage surcharge, discounts, tax, estimated total, disclaimer "Estimate only. Final invoice may vary if scope changes."); attachments. Banner pattern: "Vehicle record attached" vs "No Vehicle record yet — add VIN to create one (required before invoicing)."
**Superseded elements:** plate-at-intake requirement and "No Plate" checkbox (plate is now collected on scene only — see §2); estimate-signature ">$200" note (now the Oregon 10%/$200 change-order rule, §7).

---

## 12. Cross-Chat Terminology / Naming Notes

- App name drift: **"Indie Roadside Admin"** (ChatGPT chats) vs **"RoadRunner Admin"** (Claude workbook chat) — same dispatch-to-cash admin app.
- Company: **White Knight Roadside, LLC** (Multnomah County, Oregon).
- Document chain: Service Request → Estimate → Work Order → Invoice → Payment → Receipt; workbook layer adds Waiver (6A/6B), Vehicle Condition Report, Hazard Checklist, Service Log, Receipt/Completion Acknowledgement (11A/11B), Tow Referral, Incident Report.
- "Service Order" (contract record between SR and WO) appears only in the modular/DDD chat; the workbook chain goes SR → Dispatch → WO directly. The DDD chat's SR statuses (Pending/Accepted/Completed/Cancelled/Rejected) and the workbook's operational enums (New/Dispatched/En_Route/On_Scene/Completed/Cancelled) are different layers — SR lifecycle vs job operational status.
