# White Knight Roadside — Consolidated Knowledge

Everything worth keeping from 247 AI conversations (ChatGPT, Claude, Gemini; roughly August 2025 through June 2026, exported 14 July 2026), boiled into one document and reconciled against the running application.

---

## How to use this

**The application is the only thing that is decided.** What runs in `C:\Users\MSI-Thin\Code Projects\wkr` is the system of record for how WKR works. `docs/DECISIONS.md` records why each choice was made, `docs/BUSINESS_RULES.md` states the rules the code enforces, and `app/Domain.php` is where those rules actually live.

**This document is everything else** — the reasoning, research, policies, prices, statutes, scripts and worked cases that came out of the conversations but were never encoded. It is reference material: solutions found along the way. It is not law, and it does not override the app.

Where the conversations contradicted each other, the conflict has been resolved in favour of the application and the losing variant dropped. The [conflict ledger](#conflict-ledger) below lists what was dropped, so a decision that looks missing can be recognised as retired rather than forgotten.

Where the conversations covered ground the app does not touch — the service catalog contents, accounting treatment, Oregon statute, ad structure, diagnostic procedure — there was nothing to reconcile, and that material is the real payload here.

### Contents

- [What the application already settles](#what-the-application-already-settles)
- [Conflict ledger](#conflict-ledger)
- [Service operations, catalog and pricing](#service-operations-catalog-and-pricing)
- [Money, accounting and integrations](#money-accounting-and-integrations)
- [Legal, tax and compliance](#legal-tax-and-compliance)
- [Marketing and customer acquisition](#marketing-and-customer-acquisition)
- [Trade reference: diagnostics and job pricing](#trade-reference-diagnostics-and-job-pricing)
- [Interface design](#interface-design)
- [Working with coding agents](#working-with-coding-agents)
- [Open questions](#open-questions)

---

## What the application already settles

Stated once here so the rest of the document can reference rather than repeat it. Authority is the code; this is a reading of it, not a substitute.

**The chain.** Service Request → Estimate → Work Order → Invoice → Payment → Receipt. The Estimate *is* the quote — there is no separate quote entity, and Service Order was folded into Estimate.

### The signature is on the work order

**The estimate needs verbal approval only** — at any amount. That is what releases the technician. The customer's signature lives on the **work order**, and above $200 it is what releases the work.

| Gate | Requires | Releases |
|---|---|---|
| `Rules::dispatchGate()` | Priced work + customer authorization on the estimate — **verbal counts, at any amount** | The truck |
| `Rules::workBeginsGate()` | A signature on the work order, when the estimate is over $200 | The wrench |

The work order is the right document to sign: by the time the technician is on scene they know the real scope, so the number the customer signs is the number they will be billed. Signing the estimate would have meant signing a guess.

**Beginning work is its own step.** `IN_PROGRESS` sits between `ON_SITE` and `COMPLETED` — arriving is not starting. On arrival the technician prices the real scope, gets the signature, and only then does "Begin work" unlock, stamping `work_started_at`. Compared against `auth_signed_at`, that makes "signed before work began" provable rather than assumed; completion is refused if the order is wrong.

**Two capture paths, one signature.** Both write `work_orders.auth_signature`, and `auth_method` records which was used.

- **Display for customer** — the technician turns the device around; the customer reads the line items and signs on the full-screen pad. The default, and the stronger evidence: a person signed in front of you.
- **Send to customer via SMS** — a tokenised link to the same document, for when they are not on scene: keys left, fleet vehicle, driver gone home.

The link mirrors the `/pay/{token}` pattern — `signature_requests`, 24 random bytes, unique by database index, single-use, one live link per document per purpose so a stale text cannot sign something since re-priced. `sent_at`, `viewed_at` and `signed_at` are each recorded with the signer's IP and user agent, and the technician's screen shows whether the customer has opened it yet.

**No SMS without consent.** `Sms::gate()` requires `sms_approved`, honours `do_not_contact`, and records blocked attempts with a reason. When it refuses, the SMS option disappears and the interface says why, leaving the in-person path. Templates carry the brand name, one clear purpose, the link and `Reply STOP to opt out`, with no shorteners — public shorteners are a common carrier-filtering trigger.

**The completion sign-off is asked for, not gated.** A customer cannot be compelled to agree the job was done well, so gating on it would only teach technicians to fake it. But it cannot be silently blank either: closing a work order needs either a signature or an `unsigned_reason`, which lands in the audit trail beside the outcome code.

Two notes on the record. Closing a job is reachable only through `complete()` — the plain status route refuses `COMPLETED`, which would skip every gate above. And the app pins its own timezone (`company.tz`, Oregon) rather than inheriting php.ini, because document numbers are date-keyed and XAMPP's Europe/Berlin default was dating them a day ahead.

This has moved twice. Originally a signature was demanded before an estimate could be authorized, which made any job over $200 undispatchable. Then it became an on-arrival signature on the estimate, with remote signing rejected outright. It now sits on the work order with both capture paths — the in-person-is-better reasoning survives as *which path leads*, not as a prohibition, because the real alternative to a remote signature is often no signature at all. `docs/DECISIONS.md` carries the reasoning; `tests/signature_gate.php` covers it.

### Stages within the chain

Six documents is a statement about how many things get a **document number**, not about how many **stages** a job passes through. The twelve-stage model worked out in conversation — INT, SER, EST, EAP, WOR, DSP, COS, SCR, INV, PAY, RCT, PTW — was never wrong about the stages. It was wrong about giving each one its own table and its own number. Every stage still exists; eleven of the twelve are already implemented, as statuses, timestamps and signature blocks inside the six documents.

| Stage | Where it lives now | Evidence captured |
|---|---|---|
| **INT** intake | `service_requests` at `PENDING`, with `channel` (phone / web / provider) | Reported name, phone, problem, location — explicitly not required to be accurate |
| **SER** service request | `service_requests`, numbered `SER-…` | The request becomes a tracked job |
| **EST** estimate | `estimates` at `DRAFT` → `SENT`, numbered `EST-…` | Priced scope, catalog lines, terms text |
| **EAP** estimate approval | `estimates` at `APPROVED` | `authorized_by`, `authorization_method`, `authorized_at`, `signature_data`, `authorization_ip`, `authorization_agent` — a full authorization block, not a flag |
| **WOR** work order | `work_orders` at `PENDING`, numbered `WOR-…` | Exists only once the estimate is a contract |
| **DSP** dispatch | `work_orders` at `ASSIGNED` → `EN_ROUTE` → `ON_SITE` | `technician_id`, `assigned_at`, `en_route_at`, `on_site_at`; en-route and on-site each fire a customer SMS |
| **COS** completion of service | `work_orders` at `COMPLETED` | `completed_at`, `outcome_code`, `signer_name`, `signature_data`, `signed_at` — the customer signs for the work |
| **SCR** scope change | `invoices` variance block | `variance_amount`, `variance_authorized`, `variance_auth_name`, `variance_auth_at`, `variance_signature`; the issue gate blocks until it is captured |
| **INV** invoice | `invoices` at `DRAFT` → `ISSUED`, numbered `INV-…` | |
| **PAY** payment | `payments`, numbered `PAY-…`; invoice moves `PARTIAL` → `PAID` | `processor_ref` uniquely indexed |
| **RCT** receipt | `receipts`, numbered `RCT-…` | |
| **PTW** warranty | **Not implemented.** `warranty_months` and `mfr_warranty` are snapshotted onto every line, so the data is there, but nothing reads it back | — |

So the practical difference between the two models is smaller than it looks. What the six-document structure actually buys is that a stage cannot drift away from the thing it describes: the completion signature is *on* the work order it completes, and the scope-change authorization is *on* the invoice whose total it authorizes. `docs/DECISIONS.md` gives the reasoning for the one merge that did remove a document — Service Order into Estimate — and it was that the extra document held nothing the estimate didn't, while forcing three documents to exist before a technician could move.

**Where this is still genuinely open:** two stages capture a customer signature but have no separately numbered, separately printable artifact. Only `/estimates/{id}/print` and `/invoices/{id}/print` exist — there is no work-order print route, so **COS** (the completion the customer signed for) and **SCR** (the scope change they authorized) cannot currently be handed over or attached to a partner claim as standalone documents. Whether they need to be is a real question, not a settled one, and it is most likely to be forced by a motor club or fleet account that requires a signed completion certificate. `docs/DECISIONS.md` already leaves the door open in the right terms: a provider workflow needing its own document is *a new document type, not a resurrection of the old one*. Adding a printable, numbered COS would not reopen the twelve-document model — it would be one new artifact over data the app already captures.

**Numbering.** `PREFIX-YYYYMMDD-###`, assigned once from the `doc_counters` table and never changed.

**Vehicle identity is the VIN and only the VIN.** `vehicles.vin` is NOT NULL and uniquely indexed. Plate and state are optional lookup data; `no_plate` with a reason exists for the cases that have none. A service request carries only *reported* vehicle details and is never blocked on accuracy. A work order cannot be completed, and an invoice cannot be issued, without a captured VIN — unless every line on the document is flagged `vehicle_not_required`, and for an invoice a `no_vehicle_reason` is recorded.

**Thresholds**, from `config.php`:

| Setting | Value | Meaning |
|---|---|---|
| `authorization_threshold` | $200.00 | Above this, a signature is required before work begins — captured on arrival, not at authorization |
| `variance_abs` / `variance_pct` | $200.00 / 10% | Re-authorization when the invoice-to-estimate delta exceeds the **lesser** of the two |
| `default_labor_rate` | $125.00/hr | |
| `drive_time_rate` | $81.25/hr | 65% of labor |
| `mileage_rate` | $0.72/mi | |
| `default_tax_rate` | 0.0 | Oregon has no sales tax |
| `eta_minutes` | 30 / 60 / 120 / 0 | Emergency / Urgent / Standard / Appointment |

**Gates.** Nothing dispatches without at least one catalog line and a customer authorization on the estimate — verbal is enough to dispatch. No work begins on the vehicle without a signed estimate above $200, captured on arrival. Documents lock once they are real. Nothing is ever deleted — voids and credits, plus an append-only `audit_log`; every outside call lands in `api_log`.

**Line items are catalog-only.** `Lines::add()` refuses anything not in the catalog. Every line snapshots unit cost, the markup percentage applied, the suggested price, the final price and whether that price was an override — which is what makes editing the catalog or the markup matrix safe for documents already written. Markup lives in `markup_tiers`, edited at `/markup`: `price = cost + cost × tier%`, integer-cents exact, a cost sitting on a tier's maximum belongs to the lower tier, and a zero or null cost means "needs pricing", never $0. Money is `DECIMAL(12,2)`, never a float, and totals are computed server-side.

**Customers** are `INDIVIDUAL`, `COMMERCIAL` or `FLEET`. For an individual, a person is the customer and never carries a company name. For a business, the company *is* the customer and its name is the legal name on every document. FLEET means the customer's business is vehicles — couriers, trucking, delivery; a business that merely owns several vehicles is COMMERCIAL. The word is "Individual", never "Person". Every account defaults to due-on-receipt; net terms are a deliberate per-account setting and are snapshotted onto the invoice at creation.

**Authentication and roles from day one** — ADMIN, DISPATCH, TECHNICIAN.

**SMS is Telnyx.** The account is approved and 10DLC is approved. This is closed. The `outbox` driver exists only so a fresh install works without an account. No message goes out without consent, and STOP is honoured immediately and is not reviewable.

**Card payments are a link**, not a terminal. `payment_links` maps the processor's order id back to the invoice, and `payments.processor_ref` is uniquely indexed — which is what makes a replayed webhook a no-op instead of a second payment.

**The stack** is PHP 8 and MySQL with no framework, no Composer and no build step: a hand-rolled front controller and plain PHP views, XAMPP locally and SiteGround in production. SQLite exists only for the tests.

**Navy is the brand colour.** Amber, green, red, cyan and purple carry status meaning and are never decorative.

---

## Conflict ledger

Every case where the conversations disagreed with each other or with the app. The right-hand column is retired — if you find it in an old chat or an old document, it is not a live option.

| Question | Settled | Retired |
|---|---|---|
| Quote entity | The Estimate is the quote | A separate Quote or Service Order entity |
| Document chain | **Six numbered documents carrying twelve stages** — see [Stages within the chain](#stages-within-the-chain) | Twelve *separately numbered* documents, with EAP/DSP/COS/SCR/PTW as their own tables and doc numbers |
| Numbering | `PREFIX-YYYYMMDD-###` | `TYPE-YYYYMMDD-###-V#`, `JOB-YYYYMMDD-####`, `INV-YYYY-#####`, `IRA-2025-000123`, and the 20-code type table |
| Vehicle identity | VIN, uniquely | Plate + state as the unique pair; plate required at intake |
| VIN timing | Not needed to open a request; required to complete a work order and issue an invoice | "VIN required at intake"; "VIN not required for MVP" |
| Variance rule | The **lesser** of $200 and 10% | Phrasings that read as "greater than 10% or $200" |
| Auth and roles | Real auth, three roles, from day one | The deliberate no-security MVP; the single-user no-RBAC pivot |
| Stack | Plain PHP 8 + MySQL, no framework, no build step | Python/FastAPI; Laravel; React + Vite + Tailwind; Slim 4 + Eloquent + Twig; the DDD bounded-context architecture |
| Line items | Catalog-only, snapshotted | Free-typed ad-hoc parts at job time |
| Markup | Per-tier percentage in `markup_tiers`, lower-tier boundary | Compounding multiplier tiers; the shallow ×1.60–×1.20 matrix; a fixed gross-profit floor |
| SMS provider | Telnyx, approved | Twilio; the CPaaS comparison; toll-free vs 10DLC as an open lane |
| Payments | Hosted checkout link | Card terminal; the Square PHP SDK (no Composer) |
| Job source | `RETAIL` or provider | A `HYBRID` source; separate contract entities |
| Brand colour | Navy chrome, signals for status | Neon purple or cyan as the brand colour |
| Accounting | Account codes ride on catalog items and expenses; no ledger yet | A live posting ledger as though it already existed |
| Customer terms | Due on receipt for everyone unless granted | Net terms implied by business customer type |

Two things were reversed by Jason directly and are worth remembering as reversals, because the earlier version appears in a lot of documents: **"You have the vehicle data rules backwards. A VIN number is required. You can look up the VIN using plate and state."** — and the salvage-yard rule that **driving** is billed at the reduced rate while **wrenching at the yard** stays at full rate, which had to be corrected twice.

---

## Service operations, catalog and pricing

The app already enforces the mechanics: catalog-only line items, snapshotted cost/markup/price on every line, the $200 signature threshold, the min($200, 10%) variance re-authorization, VIN gating on work-order completion and invoice issuance, and `PREFIX-YYYYMMDD-###` numbering. None of that is restated here. What follows is the content that has to go *into* those mechanics — what the catalog actually contains, what each price means, what gets said on the phone, and what a job file has to hold to survive a dispute.

### Catalog shape: two trees plus a fee group

The catalog is organized as **Services** (what WKR does — labor and fees) and **Parts & Materials** (what WKR sells, installs, or consumes). Fees are their own group because they cut across services: dispatch/trip charge, mileage beyond the included radius, after-hours/emergency surcharge, wait time per 15 minutes, cancellation/no-show. Rather than deep folder nesting, use a tag layer — billing unit, after-hours eligible, stocked vs special-order vs customer-supplied, warranty, core-required.

SKU format is readable `CATEGORY-SUBCATEGORY-SPEC`, and the internal SKU is deliberately separate from every external identifier. The SKU says what WKR sells; manufacturer part number, vendor part number, UPC, serial, and lot number all say what somebody else calls the same object, and each gets its own field. Canonical seed SKUs:

```
SVC-JUMP-STD        SVC-LOCKOUT-STD       SVC-TIRE-CHANGE      SVC-TIRE-REPLACE
SVC-FUEL-GAS-2GAL   SVC-FUEL-DIESEL-5GAL  SVC-FUEL-DIESEL-COMM-15GAL
SVC-MOBILE-MECH     SVC-MOBILE-DIAG       SVC-TIRE-MOUNT-BAL   SVC-OTHER-CUSTOM
LAB-MOBILE-HR       FEE-DISPATCH          BAT-GRP65-STD        TIRE-USED-225-65R17
MAT-FUEL-GAS-REG    MAT-FUEL-DIESEL       VALVE-STEM-STD       SHOP-SUPPLIES
```

Seed only these. Do not invent a large catalog — the discipline is that variable-cost goods get *placeholder* items with pricing rules, not a price per SKU (see quoted parts below).

Unit of measure convention: **flat-rate services are EA (Each)**, timed labor is HR (Hour), materials and fluids use gallon/quart/oz/each. A line reads: Qty 1, UOM EA, "Jump Start Service", rate, total.

Pricing models the catalog must support: **Flat Rate | Hourly | Estimate Required | Per Unit (per tire, per overage gallon) | Labor + Parts | No Charge / Included | Variable / Custom**. Default labor rate is $125/hr, drive time $81.25/hr, mileage $0.72/mi — set in config, not in catalog rows.

Every catalog item also carries `taxable` and `warranty_eligible` booleans that snapshot onto the line. Both are real fields with a caveat: Oregon has no sales tax, so `default_tax_rate` is 0.0 and the `taxable` flag currently changes nothing on an Oregon invoice — it exists so the data is correct if WKR ever works a taxing jurisdiction. Warranty in V1 is **parts only**; services never carry a warranty flag. Warranty is meaningful at the installed-part level (part, install date, serial/lot, start/end), with the catalog `warranty_days` value acting only as a default.

### The service catalog

**Every item carries an operational category** — ROADSIDE, TIRE (Mobile Tire
Service), MECHANIC, or OTHER — separate from its revenue account. The dividing
question is what the job needs on the truck, not how stranded the customer is,
and for tire work it reduces to one test: **does the tire have to come off the
wheel?** Not off the *vehicle* — pulling a wheel is a jack and a lug wrench, and
a plug is routinely done with the wheel off the car and the tire still on it.
The question is whether the bead comes off the rim, which needs a bead breaker
and a tire machine. Spare swap and plug are Roadside; internal patch and mount &
balance are Mobile Tire. Battery installation is Roadside (hand tools, nothing
comes apart). Fees carry no category — they inherit the job they are billed on.
Full rule in docs/BUSINESS_RULES.md §1b; implementation in `ServiceCategory`.

| Service | SKU | Category | Pricing model | Includes / explicitly excludes | Warranty | COGS |
|---|---|---|---|---|---|---|
| Jump Start | `SVC-JUMP-STD` | ROADSIDE | Flat, attempt-based | Connect jump equipment, start attempt, basic starting/battery check when possible. Excludes battery replacement, diagnostics beyond the basic check. | No | None |
| Spare Tire Swap | `SVC-TIRE-CHANGE` | ROADSIDE | Flat, attempt-based | Install the customer's own spare. Excludes repair, mount, balance, disposal. Renamed from "Tire Change" so it is not confused with Mobile Tire work. | No | None |
| Tire Plug | (tire repair family) | ROADSIDE | Flat | Puncture repair, tread area only, ≤ ¼". The wheel usually comes off the vehicle, but the tire stays on the wheel — no bead break, so roadside kit. Excludes sidewall/shoulder, TPMS damage. | No | Materials only |
| Tire Patch | (tire repair family) | TIRE | Flat | **Bead broken, tire off the rim**, inside patch, remount. Same ≤ ¼" tread limit. The demount is what moves it out of roadside. | No | Materials only |
| Tire Replacement | `SVC-TIRE-REPLACE` | TIRE | Flat service fee + parts | Service fee plus tire; tire, disposal, valve stem, mounting all post to COGS. | Yes if tire sold (used tires AS-IS, see below) | Tire + disposal + valve |
| Mobile Mount & Balance | `SVC-TIRE-MOUNT-BAL` | TIRE | Flat or per tire | Tire *replacement* service on WKR's own leverage mount machine + bubble balancer. Tire cost is additional. | Fitment guarantee only | Tire |
| Vehicle Lockout | `SVC-LOCKOUT-STD` | ROADSIDE | Flat, attempt-based | Non-destructive entry with wedges/tools. Excludes key cutting, key programming, rekey, ignition work. | No | None |
| Fuel Delivery — Gasoline | `SVC-FUEL-GAS-2GAL` | ROADSIDE | Flat with **2 gal included**; overage per gallon | Delivery + 2 gal. Excludes fuel-system diagnosis; misfuel is the customer's declaration. | No | Fuel, once, as vendor expense |
| Fuel Delivery — Diesel Passenger | `SVC-FUEL-DIESEL-5GAL` | ROADSIDE | Flat with **5 gal included** | Same | No | Same |
| Fuel Delivery — Diesel Commercial | `SVC-FUEL-DIESEL-COMM-15GAL` | ROADSIDE | Flat with **15 gal included** | Same | No | Same |
| Battery Replacement | `BAT-*` + labor | ROADSIDE | Labor fee + battery/materials | Test, remove, install, terminal clean. Core charge tracked separately. Roadside by the demount test — hand tools, nothing comes apart; the warranty and core ride on the part. | Yes | Battery + core |
| Starter Replacement | `SVC-STARTER-REPLACE` | MECHANIC | Estimate Required (labor + parts) | Variable labor. | Yes | Part |
| Alternator Replacement | `SVC-ALT-REPLACE` | MECHANIC | Estimate Required (labor + parts) | Variable labor. | Yes | Part + belt |
| Mobile Mechanic | `SVC-MOBILE-MECH` / `LAB-MOBILE-HR` | MECHANIC | Hourly, 1-hr minimum | Light repairs on scene. Excludes anything needing a lift. | Parts only | Parts |
| Diagnostic / Inspection | `SVC-MOBILE-DIAG` | MECHANIC | Flat (or hourly) | Inspection and best-effort diagnosis. **Does not guarantee a full diagnosis.** Credited against the repair bill if WKR does the repair. | No | None |
| Dispatch / Service Call Fee | `FEE-DISPATCH` | *(inherits the job)* | Flat | Call-out / minimum charge. A fee takes the category of whatever it is billed on. | No | None |
| Other / Custom | `SVC-OTHER-CUSTOM` | OTHER | Estimate Required | Convert to a specific SKU once it recurs. | — | — |

Two catalog-wide notes. First, **no towing and no tow pass-through**. WKR does its own lockouts and does not sublet tows; the entire Sublet/Tow bucket was cut. A tow referral means handing over the tow company's contact information, nothing more. Keep towing out of code as well as out of the catalog — service offerings are data, not enums, and a `feature_towing_enabled = false` flag plus a neutrally named "External Service" line type is the seam if it ever matters.

Second, **variable-labor jobs are templates, not flat rates**. "Alternator replacements cannot be a flat rate — there is too large a difference in labor times." The resolution: the catalog item is a service *template* carrying `pricing_type = estimate_required`, `billing_method = labor_plus_parts`, `default_labor_rate = 125.00`, `default_labor_hours = NULL`, `minimum_labor_hours = 1.00`, and flags for requires_parts / requires_estimate / allow_manual_labor_hours. Customer-facing price displays as "Estimate Required". The estimate line is where actual priced work lives. A realistic build: labor 2.4 hrs × $125 = $300, alternator $189.99, belt $42.99, shop supplies $10 → $542.98.

Which items carry `vehicle_not_required` is catalog content even though the gate itself is enforced in the app: mount & balance on a loose wheel/tire assembly, tire plug on a tire brought to WKR, deliveries, and "Other". **Never** jumpstart, lockout, fuel delivery, or spare-tire swap.

### Quoted parts and the parts catalog

Do not catalog every tire, battery, or auto part price. Use placeholder items — `QUOTE – Tire`, `QUOTE – Battery`, `QUOTE – Auto Part`, `PASS – Fuel per gallon` — that carry pricing *rules* rather than a fixed sell price. At quote time capture supplier, cost, core charge, and markup rule; the sell price is computed from the markup matrix. Tire specifics (size, load/speed rating, brand, availability, approved substitute Y/N) go in line-item notes. Track vendor quotes per job (vendor, vendor SKU, cost, core, shipping, quote timestamp, markup rule, resulting sell price) instead of bloating the catalog. Supplier names never need to appear on a customer document.

One chat position is superseded and worth flagging because people keep reaching for it: the notes repeatedly proposed **ad-hoc, free-typed parts at job time** with a "save to catalog later" option. The app does not allow it — `Lines::add()` throws when the catalog item is missing. The workflow is: create the catalog item (thirty seconds, SKU + name + cost), then add the line. The reasoning behind the ad-hoc idea still holds — a tech buying a part on scene should not be blocked — so the answer is a fast "new catalog item" path from inside the line builder, not a free-text escape hatch.

Vendors are a table with an FK, never free text. Per-part sourcing carries vendor part number, vendor URL, and internal vendor unit cost for margin math.

### Pricing policies

**Fuel delivery — flat rate with accurate internal recording.** The customer hears and pays one flat price per tier. Overage gallons beyond the included allowance bill as a separate `PASS – Fuel` line, `overage = max(0, actual − included)`, added only when greater than zero, at a posted per-gallon rate refreshed monthly. Internally the job records actual gallons delivered, actual pump price per gallon, and fuel COGS (gallons × pump price), with an optional receipt photo. The modeling concept that makes this work is that every line carries both a **billable quantity** (what the customer is charged for — overage only) and a **fulfilled quantity** (what was actually delivered). Fuel is recorded **once** as a vendor expense/COGS and then allocated to the job — never posted twice. The fuel screen should be: pick tier → enter gallons delivered → enter pump price (internal) → it shows included, overage, cost, and margin. Internal fuel-tank work is in scope; it has been done more than once.

**Paid for the attempt.** Payment is for the attempt, not the outcome. The work order records `attempted = true` plus an outcome code (`COMPLETED`, `ATTEMPTED_UNSUCCESSFUL`, `CUSTOMER_NO_SHOW`, `UNSAFE`, `TOW_REQUIRED`), and the invoice keeps the service line regardless. Customer-facing wording is deliberately plain, not legalistic:

> "Jump start attempt (12V). Includes connection and start attempt; service fee applies regardless of outcome."
> "Dispatch and attempt to unlock vehicle using entry tools; success not guaranteed."
> "Dispatch and attempt to replace flat with spare; success not guaranteed."

A one-line version belongs under the invoice totals; the firmer "No Guarantee of Start" phrasing belongs directly above the signature line on the work order — that is where it does legal work. If no attempt is even possible because access is blocked or the site is unsafe, a dispatch fee may still apply.

**Failed jumpstart — three branches, and the customer picks between two of them.** After two failed attempts the vehicle needs a tow, a battery, a starter, or a fuel pump, and WKR can determine which and quote the repair.

| Situation | Billing |
|---|---|
| Customer specifically called for a jumpstart, it fails | Customer owes the fee. The requested service was performed. |
| WKR suggested the jump (customer originally wanted a tow) and it fails | **No charge.** We initiated it, we eat it — which also keeps techs from over-suggesting jumps. |
| Customer accepts on-the-spot battery or starter replacement | Jump fee **waived as goodwill** — the failed jump becomes a diagnostic step we would not have charged for anyway. |

**Lockout is a judgment call, and it is billable only if we actually take the job.** Never unlock an unattended vehicle. Use gut feeling; when in doubt, walk — and if WKR declines a lockout, **there is no charge**. Verification is realistic, not theatrical: look at a photo ID before unlocking, or match the registration *after* the door is open (the registration is locked inside — that is the whole point). Elaborate verification checklists, interior-description quizzes, and DMV lookups were rejected as unworkable roadside. **Child or animal locked inside is an emergency — unlock immediately**; "call 911 and wait" is not the policy. Field technique that belongs in the SOP: never pull an unlocked door open by the handle while air wedges are still in place — it breaks the cable. Get it unlocked, remove the equipment, then open. Test for an unlocked door with a light handle pull; there is a tension about halfway through the pull that is absent when locked.

**Refusal, cancellation, and the dispatch fee.**

| Event | Money | Paperwork |
|---|---|---|
| WKR refuses service | Keep **nothing**, not even the dispatch fee | Customer signs nothing; the *technician* signs that no work was done. No receipt for work not performed = no liability. |
| WKR declines a lockout on judgment | No charge | Same |
| Customer cancels after WKR is on scene | Dispatch fee applies | Cancellation reason logged |
| Access blocked / unsafe, no attempt possible | Dispatch fee may apply | Photo evidence + reason code |

**Mobile mount & balance pricing.** This is a tire *replacement* service. Tire size is required at the service request (read off the sidewall, e.g. "225/65R17"), plus tire count and who supplies the tire. WKR generally buys a used tire en route from a third-party supplier; the guarantee is **fitment match, not brand match**, and the tire is sold **AS-IS with no warranty** under a signed acknowledgment that records the DOT date code, tread depth in 32nds, and brand — signed *before* installation.

| Configuration | Price |
|---|---|
| Package, WKR-supplied tire | **$150 service + tire list price** |
| Used tire under 18" | ≈$40 cost → **$50 sale** |
| Used tire 18"+ | Custom rate |
| Customer-supplied tire | **$100 service**, no tire cost |
| Each additional WKR-supplied tire | +$50 service, +$50 tire *(drafted extension, not field-confirmed)* |
| Each additional customer-supplied tire | +$35 service *(drafted extension, not field-confirmed)* |

En-route inspection: correct size, no sidewall damage, dry rot, or shoulder plugs; tread ≥ 5/32"; DOT date under six years is *preferred*, not required — that language was deliberately softened, because "these customers are often stranded with no tire and would drive on a wagon wheel." Photograph the size markings, the DOT code, and the tread depth. Re-quote through dispatch before any purchase that exceeds the quote. The removed tire rides with the truck until the next tire-purchase trip and is dropped at the supplier; hard cap of **4 waste tires on board** (the Oregon carrier-permit exemption is fewer than five per run) and keep the disposal receipt — receipts must be retained two years regardless.

**Shop supplies fee.** Industry norm is 5–10% of labor, with 8% the common default and a $20–$50 per-ticket cap. WKR benchmark: **6–8% of labor, capped $25–$35**. Keep disposal and environmental fees (tires, oil, batteries) as separate line items, disclose the supplies fee on the estimate with a plain-language definition, and audit actual consumable spend for 30–60 days before adjusting the percentage.

### Parts markup — the intended content of `markup_tiers`

Do not use a flat markup. The target is a **blended gross profit on parts of 55–60%, with 58% as the working number** — the benchmark for a healthy repair business. Note the trap first, because it costs money: **markup is not margin.** A 50% markup is only a 33.3% margin. Conversions: 30% margin needs 43% markup; 40% → 67%; 50% → 100%; 55% → 122%; 60% → 150%.

These are the rows that belong in `markup_tiers`. The app stores `markup_pct` and computes `price = cost + cost × pct`, so the industry multipliers convert directly:

| Cost band | Multiplier | `markup_pct` | Resulting GP |
|---|---|---|---|
| $0.01 – $2.50 | ×4.00 | 300% | 75.0% |
| $2.51 – $5.00 | ×3.75 | 275% | 73.3% |
| $5.01 – $10.00 | ×3.00 | 200% | 66.7% |
| $10.01 – $50.00 | ×2.75 | 175% | 63.6% |
| $50.01 – $100.00 | ×2.50 | 150% | 60.0% |
| $100.01 – $150.00 | ×2.20 | 120% | 54.5% |
| $150.01 – $200.00 | ×2.00 | 100% | 50.0% |
| $200.01 – $500.00 | ×1.85 | 85% | 46.0% |
| $500.01 and up | ×1.70 | 70% | 41.1% |

Enter them as contiguous bands with an open-ended top tier (max null). The app's boundary rule — a cost sitting exactly on a tier's max belongs to the *lower* tier — matches how these bands are written, so a $5.00 part prices in the ×3.00 band, not ×2.75.

Two refinements from the research that the app does not implement, kept because the reasoning is useful. A **compound** calculation (splitting a part's cost across tiers, so an $8 part prices as first $5 × 3 plus $3 × 2 = $21 rather than $16 simple) recovers the profit lost at tier boundaries and adds roughly 8–10% to parts margin; the app computes simple per-tier and that is the decided behavior. And a **minimum gross-profit floor of about $25 markup** on any part has no field in the app, so it is a manual watch item on cheap-but-fiddly parts. An earlier, much shallower matrix (×1.60 / ×1.40 / ×1.30 / ×1.20) also appears in the notes — it is superseded by the nine-tier table above and would land the blended GP well under target.

Operating discipline around the matrix: OEM parts sit at the lower end of the range to stay competitive, quality aftermarket takes the standard matrix. Review blended parts GP monthly; if it comes in under 58%, nudge the multipliers on the high-volume tiers rather than everything. Roughly 67% of shops leave $40k–$70k a year on the table purely through inconsistent markup, which is the whole argument for automating this instead of eyeballing it. If a customer asks why the markup exists, the honest answer is warranty administration and labor risk, sourcing expertise, overhead, and DOA/wrong-part risk. Portland supports a trust-and-expertise premium — mobile convenience *is* a premium feature — so do not compete on being cheapest. Mobile mechanic labor runs $60–$150/hr nationally; $125 sits deliberately in the upper half.

### Partner and motor-club volume pricing

Retail is the top of the sheet, and everything below it is earned. The authoritative retail rates are: tire change $85, plug $95, patch $125, mobile mount & balance $150 + tire, jumpstart $85, lockout $95, fuel delivery $85 + fuel, mobile mechanic $125/hr with a 1-hour minimum, diagnostic $85 credited against the repair. (Older $60 / $70 / $100 figures appear in early notes — those were the sweetheart rates given to one partner, not a price list.)

| Service | Retail 0–10 | Standard 11–24 | Bulk 25–49 | Premium Bulk 50+ |
|---|---|---|---|---|
| Tire Change | $85 | $75 | $68 | $60 |
| Tire Plug | $95 | $85 | $78 | $70 |
| Tire Patch | $125 | $110 | $100 | $90 |
| Mobile Mount and Balance *(tire cost additional)* | $150 | $125 | $113 | $100 |
| Jumpstart | $85 | $75 | $68 | $60 |
| Lockout | $95 | $85 | $78 | $70 |
| Fuel Delivery *(plus fuel)* | $85 | $75 | $68 | $60 |
| Mobile Mechanic (per hour) | $125 | $125 | $125 | $125 |
| Diagnostic Fee | $85 | $85 | $85 | $85 |

Effective discounts run 0% / 10–15% / 20% / 25–30%, and Premium Bulk carries top dispatch priority. Each tier gets one set round-number price, never a range. "Mobile Mount and Balance" is the job name, and the tire is always additional.

Rules that make the matrix hold:

- **Volume counts jobs *offered*, not jobs completed** — "we will not penalize for unavailability." A partner that sends 30 jobs and WKR can only take 22 is still a 30-job partner.
- Partners start each calendar month on a **projected tier** (prior-month history, or an agreed estimate for a new partner), so they get the rate they want immediately.
- Rates **recalculate on the first weekday of every month** from actual prior-month offered volume. Short of the minimum → drop to the earned tier. Over → move up. **Pricing never changes inside a calendar month**, and the tier is announced before jobs are dispatched.
- The matrix is published on the website and adhered to strictly. Contract wording: "Bulk discounts are earned through sustained volume, not promised volume. If minimums are not met, standard retail rates apply." Optional guards: a minimum monthly invoice threshold (e.g. $3,000) for Premium Bulk, and an MOU or proven consistency before granting it.
- Sanity math: a 20%-off tire change is $68 against $85. At eight jobs a month that is pure margin giveaway. The tier only pays at genuine 25+ volume. Big providers (Agero, Urgently, Honk) routinely overpromise volume — this is exactly what the earned-tier structure defends against.

**Why the policy exists.** A partner dispatcher kept a customer tip that was intended for the driver, excusing it as "we assumed the tip was for dispatch." The stated position: no general claim on tips, but tips explicitly requested or acknowledged as intended for WKR and its people are expected to be handled properly, and the handshake deal was considered broken. The consequences became policy — receipts demanded for any customer payment including tips, the above-market discount ended, eligibility reviewed the first working day of each month, pricing announced before dispatch, matrix published publicly. The lesson generalizes: informal partner pricing survives only as long as goodwill does, so the discount structure has to be mechanical, dated, and public.

Non-retail accounts also change what the customer sees. Drivers of insurance-partner and motor-club vehicles **never receive documents or pricing from WKR under any circumstance**; the priced invoice goes to the account. Fleet drivers default to no-pricing, flipped per account by a `driver_authorized_for_billing` flag set on verbal confirmation from the fleet contact — written authorization was rejected as overbearing. The no-pricing form variant carries authorization, risk, photo consent, and release, with no charges block anywhere, plus a technician attestation that no pricing was disclosed.

### Intake practice

The call always opens the same way: *"Do you do this and how much?"* Hook them fast, then get into the nitty-gritty if they are still on the line. Price is therefore hit **twice** per call — a ballpark first, an accurate soft quote later. Intake is one screen with three sections (Service & Ballpark / Location / Vehicle + Soft Quote), not a wizard; the operator jumps around following the actual conversation. A single **Save & Dispatch** button enables only when the hard requirements are met, backed by a visible checklist. No interrupting popups. The nav philosophy is job-first, not module-first: one big **+ New Request** button, and the customer/vehicle/location tables get written invisibly behind it.

Order of collection:

1. **Quick service match + ballpark.** Service quick-buttons and an instant panel: "Starting at $XX / typical $XX–$XX / after-hours + / mileage add +". Quote it verbally, then move to location.
2. **Customer core.** First name, last name (always separate fields, never one full-name box), phone `(xxx) xxx-xxxx`, optional company. The system auto-searches by phone: match → confirm and reuse; no match → silently create. Never a separate "create customer" trip. A phone match can be overridden ("create new anyway") with a reason — inherited numbers are real.
3. **Location — no lat/long, no dispatch.** Saved locations are useless roughly 95% of the time; assume a random location. Path A: exact address → geocode → "Confirm on Map" with a draggable pin. Path B: no address → city and state required, pin drop, plus a **required human description** ("I-5 NB shoulder past exit 297, right lane") and access notes (gate code, security desk) and safety notes. Coordinates are stored internally and **never shown** — record the numbers, present in human. `location_type` is `EXACT_ADDRESS | PINNED_LOCATION`, and dispatch is blocked without confirmed coordinates. If a job address is worth keeping, an explicitly checked "save this location" box (default unchecked) creates a reusable customer location with a label and notes; otherwise the address is a per-job snapshot that never clutters the customer's list.
4. **Vehicle basics.** Year, make, model, color; plate and state only if volunteered. No VIN at intake, and no plate hunting — the tech captures both on scene during the condition report. The reasoning is worth keeping: it prevents duplicate vehicle records, keeps flustered customers moving, and does not send someone digging for documents on a highway shoulder.
5. **Service + soft quote.** Catalog-driven auto-calc: base service, mileage/zone adder, after-hours, special conditions, with a visible breakdown.
6. **Dispatch fee (optional).** Collect-now toggle, default amount from settings, summary reading "soft estimate / dispatch fee now / balance due after service."
7. **Dispatch.**

Conditional field sets by service — these are the questions that stop a wasted roll:

| Service | Ask |
|---|---|
| Tire | Position (LF/RF/LR/RR/spare), tire size off the sidewall, has spare + spare condition, locking lug nut present, damage type (blowout, puncture, sidewall, bead), repair type requested, disposal needed |
| Fuel | Fuel type (confirm — DEF is not diesel), ran completely out, commercial vehicle flag, extra gallons wanted, fuel price disclosed |
| Battery / jump | Symptoms (no crank, click, lights on, totally dead), battery location (hood/trunk/under seat), needs test, replacement interest, customer has a battery, access issues |
| Lockout | Keys visible, vehicle running, **child or pet inside → emergency flag**, proof of ownership/authorization, ID verification notes |
| Mobile mechanic | Complaint in the customer's words, diagnostic fee disclosed |

Also captured at intake: urgency (normal / urgent / stranded / unsafe location), **capability confirmed** (can WKR actually do this job — a required field), price type (Flat Rate / Starting At / Estimate Required), the ballpark actually quoted, billing party when a provider is involved (customer / provider / other) plus the provider's claim or job reference number, requested time and the arrival window given, and SMS consent with source and timestamp. Internal notes and risk flags (unsafe location, price-dispute risk, ID concern) stay strictly off customer-facing documents.

The disclaimer is non-negotiable and appears on the service request, the estimate, and anywhere a subtotal is displayed: **"Estimate only. Final invoice may vary if scope changes."**

### Job documentation

Photos are named `JOB-YYYYMMDD-SEQ-TYPE.ext`, keyed to the work order number, with a 3-digit sequence — e.g. `WOR-20260729-001-004-SIGN.png`. The type set was deliberately reduced to seven: **PRE, POST, PART, SIGN, DOC, SITE, DAMAGE**. (An earlier 13-code set — VCR-pre, VCR-post, WAIVER, FUEL, LOCK, BAT, TIRE, RIM, RECEIPT, EVIDENCE, SCAN — was collapsed into these; the useful idea it carried is that the app should *suggest* the type from service type plus workflow stage, auto-increment the sequence, and show a one-tap confirm screen rather than making the tech type a filename.) Each image record stores job id, path, type, sequence, format, uploaded_at, SHA-256 hash, source (camera / upload / scan), and user.

Minimum photo sets by service:

| Service | Required |
|---|---|
| Lockout | PRE ×2 (door and trim) + POST ×2 |
| Tire | PRE wheel ×2 + PART (patch/plug) ×1 + POST torque ×1 |
| Battery | PRE bay ×1 + tester screen ×1 + PART battery label ×1 + POST voltage ×1 |
| Mount & balance | Tire size markings, DOT code, tread depth — before install |

Attachments are polymorphic across service request, work order, estimate, invoice, and payment, and the kinds that matter are: condition photos, signature images, provider dispatch/authorization documents, vendor receipts and proof-of-purchase for parts, disposal receipts, customer screenshots, and generated PDFs.

**A defensible job packet** contains, at minimum: the signed authorization with the agreed-charges table filled in *in the customer's presence* before signing (the signature is on real numbers, not a blank check); the estimate that was actually approved plus its approval evidence — method, timestamp, signer's first and last name, IP, device/browser metadata, geolocation, the exact approval text shown, and the legal-text version signed; any change authorization; the pre- and post-service photo sets; the work order with attempt flag, outcome code, and completion notes; the completion signature (or a reason-coded, photo-backed "customer not present" bypass, which is audit-flagged); the invoice; the payment record and receipt; and the append-only event timeline. Two signatures total is the standard — one before work covering authorization, charges, and liability waiver together, one at completion — because more than that makes customers uncomfortable.

Records are electronic only: incoming paper is scanned at ≥300 DPI, attached, hashed, then shredded once scan quality is verified. PDF is the official record, carrying embedded fonts, signatures, the disclaimer version, and a document hash. Retention is 7 years for job, invoice, and payment records — Oregon's statutory floor for repair documents is 1 year, tire disposal receipts 2 years, SMS consent records 4 years, so 7 covers everything. Chargeback defense is a *byproduct* of this discipline, not its purpose: the packet export exists so a dispute takes thirty seconds, and the customer-facing description of it is "consolidates customer acceptance documentation with supporting location and device data."

---

## Money, accounting and integrations

### The chart of accounts

The chart of accounts is a bookkeeping reference, not a module. The application carries account codes as data — `catalog_items.revenue_account`, `catalog_items.cogs_account` and `expenses.account_code`, all `VARCHAR(8)` — so every line and every receipt is already tagged, but no general ledger, no journal table and no trial balance is built. That is deliberate and recorded as deferred: two incompatible numbering schemes came out of the planning work, and rather than pick one in code the data was tagged and the ledger left for later. The chart below is the reconciliation of those two schemes against the codes the running application actually uses, and it is the one to carry into QuickBooks or into a future ledger module.

Ranges follow the standard small-business convention: 1000–1999 assets, 2000–2999 liabilities, 3000–3999 equity, 4000–4999 revenue, 5000–5999 cost of goods sold, 6000–6999 operating expenses, 7000–7999 payment processing, 8000s other income, 9000s other expense.

| # | Account | Use |
|---|---|---|
| 1000 | Cash | Cash on hand. Negligible in practice — $945 across 9 transactions in twelve months. |
| 1010 | Checking | Operating account. Square transfers land here. |
| 1015 | Undeposited Funds | Added to the AI draft; QuickBooks-style reconciliation depends on it. |
| 1050 | Square Clearing | The hinge of the whole payment model. See below. |
| 1100 | Accounts Receivable | |
| 1120 | Business Savings | |
| 1200 | Parts Inventory | Inactive — parts are catalog items with a cost, not tracked stock. |
| 1300 | Prepaid Expenses | |
| 1500 | Service Vehicle | |
| 1510 | Tools and Equipment | Capitalised tools. Distinct from 6600. |
| 1590 | Accumulated Depreciation | |
| 2000 | Accounts Payable | |
| 2010 | Credit Card Payable | |
| 2020 | Sales Tax Payable | Inactive — Oregon has no sales tax and `default_tax_rate` is 0.0. Present because the chart is a template. |
| 2050 | Core Deposits Payable | Refundable core money. Never revenue, never expense. |
| 2060 | Customer Refunds Payable | |
| 2300 | Customer Deposits | Refundable customer money — also never P&L. |
| 3000 | Owner Equity | |
| 3100 | Owner Contributions | |
| 3200 | Owner Draw | Where owner pay belongs in a sole-owner shop. |
| 3300 | Retained Earnings | |
| 4000 | Service Revenue | Parent / rollup. |
| 4010 | Roadside Service Labour | Jump start, battery and charging test, battery installation labour. |
| 4020 | Tire Service Revenue | Spare install, plug, internal patch, mount & balance. |
| 4030 | Lockout Revenue | |
| 4050 | Mobile Mechanic Labour | Hourly mechanic work, diagnostics, quote-required jobs. |
| 4060 | Recovery / Winching Revenue | |
| 4070 | Remote / Video Consultation Revenue | |
| 4100 | Parts Sales | Parent / rollup. |
| 4110 | Battery Sales | |
| 4120 | Starter Sales | |
| 4130 | Alternator Sales | |
| 4140 | Tire Parts & Sundries | Valve stems, patch kits. |
| 4200 | Fuel Delivery Revenue | The delivery service, the gallons, and per-gallon overage. |
| 4250 | Towing Revenue | Inactive — template account. |
| 4300 | Other Revenue | |
| 4400 | Fees & Surcharges | Call-out, mileage, drive time, shop supplies, cancellation, no-show, tire disposal. |
| 4500 | After-Hours / Emergency Surcharge | |
| 4900 | Discounts & Adjustments | Contra-revenue. |
| 5000 | COGS — Parts & Materials Sold | |
| 5010 | COGS — Sublet / Outside Services | Third-party locksmith on a lockout, for example. |
| 5020 | COGS — Consumables Used on Jobs | |
| 5030 | COGS — Vehicle Fuel Used for Jobs | Fuel the service van burns. |
| 5040 | COGS — Merchant Processing Fees | Optional; use 7000/7010 instead unless per-job margin needs it. |
| 5050 | COGS — Warranty / Rework | Comebacks. |
| 5060 | COGS — Disposal / Environmental Fees | |
| 5070 | COGS — Direct Labour | |
| 5080 | COGS — Roadside Equipment Usage | |
| 5090 | COGS — Fuel Sold / Delivered Fuel | Fuel sold to the customer. Distinct from 5030. |
| 6010 | Vehicle Maintenance & Repairs | |
| 6050 | Employee Wages | |
| 6060 | Payroll Taxes | |
| 6070 | Employee Benefits | |
| 6080 | Contractor Labour | |
| 6100 | Marketing & Advertising | |
| 6110 | Google Ads | |
| 6120 | Software Subscriptions | |
| 6130 | Phone & Communications | |
| 6150 | SMS Messaging (Telnyx) | |
| 6250 | Vehicle Insurance | |
| 6300 | General Insurance | |
| 6400 | Supplies | |
| 6500 | Licensing & Permits | |
| 6600 | Small Tools Expense | Expensed tools. Named so it cannot collide with asset 1510. |
| 6800 | Office Expenses | |
| 6900 | Other Expenses | |
| 7000 | Merchant Processing Fees | |
| 7010 | Square Fees | |
| 7020 | Chargebacks | |

**Corrections made to the AI-generated draft, and why.** The coding agent's first chart was rated roughly 85–90% correct; the following were fixed.

*Deleted "4500 Square Payment Revenue."* Square is a payment processor. A processor never generates revenue — revenue arises when the invoice posts, and the card is merely how the receivable is settled. Leaving this account in would have double-counted every card job. (4500 is now the after-hours surcharge.)

*Deleted "4100 Roadside Assistance Revenue"* as a straight duplicate of the 4000 service tree; the general roadside case is a child of that tree, and 4100 became the Parts Sales parent.

*Deleted "6850 Bank & Processing Fees"* — it duplicated 7000/7010, which is where processing costs belong.

*Merged the two vehicle-maintenance accounts* (6010/6020) into 6010.

*Renamed "6600 Tools & Equipment" to "Small Tools Expense"* so an expensed tool cannot be confused with capitalised asset 1510.

*Removed "5400 Subcontractor Payments"* and replaced it with 6080 Contractor Labour, so every form of labour — wages, taxes, benefits, contractors — groups together in the 6050–6080 block.

*Payroll accounts removed, then reinstated.* 6050 was first struck out because Jason is a sole owner: owner pay is 3200 Owner Draw, never a wage expense, and a false wage expense distorts both profit and tax. It went back in once the application became something to be sold to other operators, who will have W-2 technicians and dispatchers. The governing principle is that the chart is a **generic automotive/roadside template** serving both an owner-only shop (3200) and an employer shop (6050–6080), with unused accounts present but inactive. 2020 Sales Tax Payable and 4250 Towing sit there for the same reason.

*Fuel split into three accounts, not one.* This is the correction that matters most in practice. Fuel the van burns is 5030; fuel sold or delivered to a customer is 5090 and is real cost of goods; the customer-facing fuel delivery price is 4200. The draft's single "6000 Vehicle Fuel Expense" is retired — the running catalog codes van fuel at 5030 and delivered fuel at 5090, and that is the assignment to keep.

*Two renumberings adopted from the running application.* 4400 is Fees & Surcharges (the draft's "Platform Revenue — Honk/Urgently" is dropped entirely; provider mix is a report dimension, not an account — see job sourcing below), and SMS messaging is 6150 rather than 6140.

*A refinement considered and declined.* 2050 could be split into 2050 Customer Core Deposits Payable (liability) and 1350 Supplier Core Deposits Receivable (asset) for textbook correctness. The single-account clearing approach was kept for simplicity; revisit only if supplier core balances get large enough to matter.

An earlier 54-account novice-friendly chart exists from the RoadRunner era with the same conventions and a Cost of Services block separated to enable gross-profit-per-job. It is superseded by the chart above, but the reasoning survives: keep direct job costs out of operating expenses so per-job margin is readable.

### The posting model

Four rules, in order of how often they get violated.

**Only invoice line items post revenue.** Service catalog entries are non-posting templates — they carry the job type, the workflow, the default lines and the reporting category. If a catalog service posted "Jump Start +$85" and its own invoice line posted "Jump Start Labour +$85," income doubles. The catalog describes; the invoice posts. This also settles a question that keeps coming back: do not create a revenue account per service. Six labour accounts is plenty; the *service type* carries operational reporting (Jump Starts $3,200, Tire Changes $2,100), so the business can be read the accounting way and the operations way from the same data without inflating the chart.

Three concepts stay separate: the service catalog (templates — default price, whether a vehicle/VIN is required, whether parts are allowed, default lines), the invoice line item (the thing that posts — revenue account, tax behaviour, item type of labour/part/fee/fuel/discount), and cost lines (part cost, consumables, sublet, merchant fee, delivered fuel, warranty/rework).

**Post once, allocate freely.** Posting means recorded in the ledger, once. Allocating means assigning an already-recorded cost to a job for costing purposes, and can happen as many ways as you like. An $80 tank of fuel posts once — Dr 5030 / Cr Cash — and attributing $6.50 of that trip's fuel to a jump start is a *non-posting allocation* that exists only to display margin. Every job cost line therefore carries a `Posts to Accounting: Yes/No` flag. The exception is fuel sold to the customer, a genuine purchase-and-resale, which posts to 5090.

Per-service cost profiles fall out of this: a jump start usually posts no COGS at all (fuel and equipment are allocations); a tire change posts plugs, stems and lug nuts to 5000 and disposal to 5060; a lockout sent to a third-party locksmith posts 5010; a battery, alternator or starter posts the part to 5000, the core to 2050 (not an expense), and any comeback to 5050. Each catalog service wants: revenue account, default COGS account, allows parts?, track fuel?, default cost method (actual / flat allocation / none).

**Payments are revenue, not income.** Income is revenue minus expenses, computed at the reporting layer and never stored. A $200 invoice payment auto-classifies to service revenue, cash +$200, AR −$200; a $60 fuel purchase hits its expense account; net income of $140 is a report, not a posting. Manual reclassification stays available for refunds, write-offs and misapplied payments.

**Square is never revenue.** This is doctrine and it appeared independently in four separate conversations. The invoice posts revenue and AR. The Square payment clears AR into 1050. The transfer moves 1050 into checking with the fee broken out. Square is the card machine, not the accountant.

The document-to-entry mapping is the ordinary one: `Source document → transaction → journal entry → ledger`. Invoice: Dr AR / Cr Revenue. Payment: Dr Cash or 1050 / Cr AR. Vendor bill: Dr Expense or Inventory / Cr AP. Vendor payment: Dr AP / Cr Cash.

### 1050 Square Clearing and the three-report reconciliation

The clearing account exists because Square pays in batches, net of fees, a day or more after the customer pays. Without it the checking deposit never matches any invoice.

| Step | Entry |
|---|---|
| Invoice issued | Dr 1100 AR / Cr revenue account(s) |
| Customer pays by card | Dr 1050 Square Clearing / Cr 1100 AR |
| Square transfers the batch | Dr 1010 Checking 968 · Dr 7010 Square Fees 32 / Cr 1050 Square Clearing 1,000 |

Three Square Dashboard reports do the reconciliation, and each answers a different question.

**Sales Summary** (Reports → Sales Summary → export CSV): gross sales, refunds, taxes, tips, net and payment-method breakdown. Confirms that invoices and payments recorded in the application match what Square thinks happened, and it is where tip totals come from.

**Transfers** (Balance → Transfers → CSV): transfer date, gross, fees, adjustments, net deposit. This is the one you reconcile against the bank statement, and it produces the third journal entry above.

**Transaction Detail** (Transactions → CSV): transaction id, customer, amount, card type, refunds, timestamp. For disputes and for tracing a payment back to an invoice.

The long-term version is to poll the Payments, Payouts and Customers APIs and auto-reconcile against 1050, surfacing a single line per day: *"June 6: payments $623.00, deposits $600.84, difference $22.16 = fees."* Any residual balance in 1050 that is not explained by in-flight payments is an error worth chasing.

Twelve months of live Square data (Jun 2025 – Jun 2026) sets the baseline: 354 completed payments, **$45,940 gross, $1,445 in fees — a 3.15% blended rate — roughly $44,495 net**, against a true run rate of $48–50K/yr. Median ticket $96.65; the average climbed from $110–130 in autumn 2025 to $140–168 in 2026. Weekends and the noon-to-midnight window are 73% of volume, overnight only ~10%. Cash is negligible: $945 over 9 transactions. The top 10% of customers produce 39% of revenue. Of 342 card payments, 189 were hand-keyed at 3.57% versus 2.74% contactless — switching would save only ~$230/yr and keyed entry fails less often at a roadside, so **keep keying**. 31 payment attempts failed, about 8%.

### Core deposits: 2050

A core deposit is temporary money held on someone else's behalf. It is never revenue and never expense; it lives in 2050 Core Deposits Payable, a current liability, and is released when the core moves.

| Event | Entry |
|---|---|
| Sell battery $260 with a $22 core | Dr Cash/AR 282 · Cr 4110 Battery Sales 260 · Cr 2050 Core Deposits Payable 22 |
| Customer returns the core, gets refunded | Dr 2050 22 · Cr Cash 22 |
| You return the core to the supplier and are refunded | Dr Cash 22 · Cr 2050 22 |
| You pay a supplier core charge on a reman part | Dr 2050 (against pre-paid core money) |

A full ticket: battery $260 + labour $85 + tax $30 + core $22 = $397 → Dr Checking 397 / Cr Battery Sales 260, Labour Revenue 85, Sales Tax Payable 30, Core Deposits Payable 22. (Oregon collects no sales tax, so the tax leg is normally zero; it is shown because the chart is a template.)

The mechanism in the application is a catalog item: `FEE-CORE-DEPOSIT`, a per-unit fee priced at $22.00, whose `revenue_account` is **`2050`** — a liability code deliberately placed on a catalog line so the core is billed on the invoice like anything else but is tagged to the liability from the moment it is charged. `catalog_items` also carries a `core_charge` column for per-part core values. Overstating revenue by treating cores as sales is one of the most common independent-shop mistakes, and this is the guard against it. The general principle: **nothing refundable touches the P&L** — cores in 2050, customer deposits in 2300.

### Tips

Tips belong to the person on scene, and the application tracks them separately from the invoice. `payments.tip_amount` is its own column, tips are excluded from the taxable base, the checkout page offers preset amounts with a note that they go to the technician in full, and both the payments and reports screens total them independently. Square hosted checkout is created with `allow_tipping` enabled, and because the webhook records what the processor says was actually taken, a tip added at checkout flows back and the invoice balance is recalculated from the payments.

The policy came out of an incident. A dispatch partner — Mobile Tire Guys LLC, then the largest account, 22 payments totalling $1,822 at an $83 average job — kept a customer tip the customer had explicitly directed to Jason, claiming it was "assumed to be for dispatch." The Square data corroborated the pattern exactly: **all 22 of that partner's payments carried $0.00 in tips**, against $931 in tips across 47 direct-customer jobs — roughly $20 a job, about a 13% tip rate. Jason ended the relationship on principle: *"It could have been five cents. They are thieves."*

The positions established in the formal letter that followed, on White Knight Roadside LLC letterhead, are the standing policy:

- In this business model tips overwhelmingly belong to the person on scene; no part of the industry assumes tips flow to in-house or dispatch roles.
- The arrangement was a handshake — no contract, no employer-employee relationship — so tip law does not apply, and no default claim is made on *unspecified* tips. A provider may do as it sees fit with those.
- **But a customer's specific direction of a tip is a verbal contract and must be honoured.** That is the whole of the complaint.
- No receipts or accounting are demanded. The expectation is honourable dealing.
- The consequence lever is pricing, not argument: a **performance-based pricing matrix** of deeply discounted rates tied to monthly job volume, recalculated on the first weekday of each month from the prior month's delivered volume, with current rates in force until recalculated. Rates are posted publicly on the website and applied uniformly and non-discriminatorily. Continued problems revert the account to standard retail pricing.

Operationally, expect zero-tip patterns on partner and platform work, and treat a partner account whose tip total is exactly zero over dozens of jobs as a signal worth looking at.

### Retail versus provider work

`service_requests.job_source` is `RETAIL` or `PROVIDER`, defaulting to RETAIL, and customers carry `is_provider` and `provider_code`. When servicing a bulk provider's customer — Agero, AAA, an insurer, a fleet, Honk, Urgently — the sale is not to the vehicle owner. It is a wholesale service sold to the provider, and that changes three things: the invoice goes to the provider at contracted rates rather than retail; the vehicle owner receives no invoice at all unless there is a copay; and the transaction is B2B, so no sales tax is collected from the owner and provider contracts are frequently exempt regardless.

| Job source | Customer of record | Invoice to | Sales tax |
|---|---|---|---|
| Retail | Vehicle owner | Vehicle owner | Usually taxable |
| Provider / bulk | The provider | The provider | Usually non-taxable |

Posting does not change. Provider work posts to the same revenue accounts as retail work through the same catalog lines — there is no separate platform-revenue account, and the draft chart's "4400 Platform Revenue" was dropped for exactly this reason. Provider versus retail is a **reporting dimension**, and the reports screen already groups jobs and revenue by `job_source`. What differs is the rate on the line, the payer on the invoice, and the terms — noting that terms default to COD/due-on-receipt for *every* account including business ones, and net terms are a deliberate per-account setting, never implied by a customer being a provider.

The larger PRD-era design — versioned contract entities with rate cards, caps, mileage logic, after-hours differentials and SLA evidence requirements, a `HYBRID` job source producing two invoices, and line-item payer toggles — is not built. Contract pricing does not exist; fleet is a customer type only. The shipped model is two job sources and manual rate entry.

### Expenses, COGS and billing companies

Expenses are recorded as documents with a vendor, category, `account_code`, amount, tax, date, payment method and an optional link to the service request they belong to, so a cost can be attached to a job without a job-costing engine existing. The live coding pattern is: parts bought for stock or for a job → `5000`; service van fuel → `5030`; Telnyx monthly messaging → `6150`.

**Invoicing a company you have no agreement with.** You *can* send a bill, but without a purchase order or an agreement most AP departments classify it as a "ghost invoice" and reject it — and if it is deceptive it stops being a billing dispute: wire fraud (18 U.S.C. §1343), mail fraud (§1341), Oregon theft by deception (ORS 164.085), the Oregon UTPA, unlawful-collection statutes, and the False Claims Act with treble damages if the payer is a government body. The playbook:

1. **Invoice now** if the work was requested or approved with evidence — email, text, PO, dispatch id — or delivered and accepted, with a signed work order, photos, or GPS and job logs.
2. **Otherwise, do not send an invoice.** Email AP first, confirm the payer, complete vendor onboarding (W-9, PO), and send a **"statement of charges" requesting approval** — explicitly not labelled "Invoice."
3. Whatever is sent carries: dispatch/job id, plate and state, service date, time and location, signed work authorisation, before-and-after photos, legal name and EIN, remit-to details, PO reference, Net-15 terms and lawful late-fee language.
4. Escalation runs past-due reminder → demand letter → small claims. A mechanic's lien generally requires possession of the vehicle, so roadside-only work rarely qualifies.

Between a third and a half of businesses are hit by invoice fraud in a given year and AP teams catch only about 39% of invoice errors — which is why an unexpected invoice from an unknown vendor is treated as hostile by default.

### Telnyx — implementation reference

SMS is Telnyx. The account is approved and 10DLC is approved. (Twilio denied service early on, which is why the search happened at all — that is the entire relevance of Twilio.) The `outbox` driver is an accountless default for tests and fresh installs, not a fallback provider; production is `telnyx`. Consent gating runs before the driver is ever called, so this is implementation detail rather than policy — the policy is in the application.

**Outbound.** `POST https://api.telnyx.com/v2/messages`, header `Authorization: Bearer <api key>`, JSON body `{from, to, text}` plus `messaging_profile_id` and a `webhook_url` when configured. The provider message id comes back at `data.id` and is stored on the message row. A missing API key or sending number degrades to the outbox rather than failing the request.

**Inbound and delivery receipts.** One webhook route, `POST /webhooks/telnyx`. The event is at `data.event_type`; `message.received` carries `data.payload.from.phone_number`, `data.payload.to[0].phone_number`, `data.payload.text` and `data.payload.id`. Any other `message.*` event is a status update, mapped from `data.payload.to[0].status`: `DELIVERED` → DELIVERED; `SENDING_FAILED`, `DELIVERY_FAILED`, `DELIVERY_UNCONFIRMED` → FAILED; anything else → SENT. Failures are shown, not swallowed.

**Signature verification.** Telnyx signs with **Ed25519** over the string `timestamp . "|" . rawBody`, sent as headers `telnyx-signature-ed25519` (base64 signature) and `telnyx-timestamp`. Both signature and the account public key are base64-decoded, the key length is checked against `SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES`, and `sodium_crypto_sign_verify_detached()` does the check. **A callback more than 300 seconds old is refused even with a valid signature**, so a captured request cannot be replayed later. Verification happens before the body is parsed; a failure logs and drops with a 403 that explains nothing. Without a configured public key, callbacks are refused outright rather than trusted.

**Portal setup.** (1) Mission Control → Messaging → add a messaging profile, e.g. `Roadside-Dispatch`. (2) Numbers → My Numbers → edit the number and assign it to that profile. (3) Set the inbound and delivery-status webhook URLs on the profile — webhook.site is fine for the first test. (4) Create an API key. (5) Complete compliance: the 10DLC path is brand → campaign → number assignment. (6) Test by texting the Telnyx number from a phone, confirming the webhook fires, and replying. (7) **Enable spend limits on the messaging profile early.** MMS is supported; inbound MMS includes an attachment link in the payload.

**Credentials.** Note that despite the historical env-var plan (`TELNYX_API_KEY`, `TELNYX_FROM_NUMBER`, `APP_BASE_URL`), the application stores these in the `settings` table, entered on Settings → Integrations, write-only, never in a committed file: `telnyx_api_key`, `telnyx_from` (the 10DLC-registered number in E.164), `telnyx_profile_id`, `telnyx_public_key`, and `app_base_url`. This matters on a host with no shell, where editing a config file means an FTP round trip. Credential values never appear in the audit log — only the names of keys that changed.

**Consent fields.** The specification asked for granted / auto-end / revoked / source timestamps plus a hard approval flag and a do-not-contact flag. The shipped set on `customers` is `sms_approved` (the hard gate — no message is sent unless this is 1), `sms_consent_at`, `sms_consent_source`, `do_not_contact`, and `phone_e164`. There is no auto-expiry column; consent is revoked by STOP or by the do-not-contact flag rather than by lapsing. Consent is granted verbally at intake and logged with a timestamp and a source. Note that this is consent **to be messaged** — it is not authorization for work, and the two must not be conflated. An earlier version of this workflow treated a texted "YES" as approval of the work; that is [not how it works](#the-signature-is-on-the-work-order). Consent is what permits the signing link to be texted at all; the authorization is the signature the customer then puts on the work order. Blocked messages are still written, with status `BLOCKED` and the reason recorded ("No SMS consent on file for this customer," "Customer is marked do-not-contact," "No valid E.164 phone number on file"), so nothing disappears silently.

**Keywords.** The first word of an inbound message, stripped to letters, is matched case-insensitively.

| Reply | Effect |
|---|---|
| STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, REVOKE, OPTOUT | `sms_approved = 0`, `do_not_contact = 1`, audited |
| START, UNSTOP, YES, SUBSCRIBE, OPTIN | Consent restored with a fresh timestamp, confirmation sent |
| HELP, INFO | Help message returned |

STOP is honoured immediately and unconditionally — it is the one instruction never queued, reviewed or second-guessed. Every inbound message is stored whether or not it matches a known customer.

**Templates.** `{co}` is the company short name; every outbound message ends in "Reply STOP to opt out."

| Key | Body |
|---|---|
| dispatch | `{co}: Your technician is en route. ETA {eta}. Reply STOP to opt out.` |
| estimate | `{co}: Your estimate is ready: {total}. Reply APPROVE to authorize. Reply STOP to opt out.` |
| on_site | `{co}: Your technician has arrived on scene. Reply STOP to opt out.` |
| invoice | `{co}: Service complete. Your invoice total is {total}. Reply PAY for a payment link. Reply STOP to opt out.` |
| receipt | `{co}: Payment received — thank you. Receipt {doc}. Reply STOP to opt out.` |
| pay_link | `{co}: Invoice {doc} — {total} due. Pay securely here: {link} Reply STOP to opt out.` |
| help | `{co}: Roadside assistance and service updates. Call (503) 764-3154 for help. Msg&data rates may apply. Reply STOP to opt out.` |
| optin | `{co}: Thanks for subscribing to roadside assistance and service updates! Reply HELP for help. Msg frequency may vary. Msg&data rates may apply. Consent is not a condition of purchase. Reply STOP to opt out.` |

The opt-in confirmation carries the full 10DLC disclosure, which is what campaign review looks for. For any future campaign or re-registration: sample messages are templates, not live traffic; campaign type is Customer Care / Account Notifications, not Marketing; the message-flow paragraph describes opt-in as verbal at intake logged with a timestamp, a web form checkbox, or a signed estimate; the public privacy policy and SMS terms page must actually be live. Rejections come from vague descriptions, samples without the sender's name, missing STOP/HELP, or a claimed opt-in form that does not exist.

A texted link to a signable document **is** how remote authorization works now — see [The signature is on the work order](#the-signature-is-on-the-work-order). What did not survive from the original design is the rest of it: a texted "YES" treated as a signature, and device fingerprinting or browser geolocation offered as proof of who was holding the phone. The link carries the customer to the actual work order, where they sign; the evidence is the signature plus its IP, user agent and the recorded moments the link was sent, opened and signed, alongside the work-order photos and the audit trail. SMS carries the link — it is not itself the authorization.

### Square — implementation reference

The account is one location, "White Knight Roadside," on Square since 2014.

**Hosted checkout links are the primary path**, not the Web Payments SDK. `paymentLink()` posts to `/v2/online-checkout/payment-links` with an `idempotency_key`, a `quick_pay` block (`name`, `price_money` in minor units, `location_id`), `checkout_options` with `allow_tipping: true` and a redirect URL, and the invoice reference as `payment_note`. The response yields `payment_link.url`, `payment_link.id` and `payment_link.order_id`; the URL is texted to the customer. This is the right shape for the business — the technician has no terminal and the invoice is frequently settled after the van has left. `charge()`, posting to `/v2/payments` with a tokenised `source_id`, exists for the case where a card is on file. The Web Payments SDK (tokenise client-side, send the token to the backend, call CreatePayment) remains the future path for in-app card entry and requires a secure context and CSP-friendly hosting. The official `square/square-php-sdk` recommended in the early prompts is *not* used: the stack has no Composer, so the integration is hand-rolled HTTP against `https://connect.squareup.com` (production) or `https://connect.squareupsandbox.com` (sandbox) with `Square-Version: 2025-01-23`.

**Idempotency is minted server-side** before any driver is called and stored on the payment row, so a double-click, a retried request or a flaky connection cannot produce a second charge — and that guarantee holds regardless of which driver is configured. Money crosses the API boundary as an integer in minor units, rounded once; floats are how cents go missing.

**Webhooks.** One route, `POST /webhooks/square`, subscribing to `payment.updated` (and `payment.created` / refund events). Square signs with HMAC-SHA256 over `notification_url + body`, compared in constant time — which is why `app_base_url` must match the URL registered in the Square dashboard *exactly*, down to a trailing slash, or every callback fails verification. Setup order: choose the Square driver in Settings and enter `square_access_token`, `square_location_id` and `square_environment`; set `app_base_url`; add the dashboard webhook pointing at `<app_base_url>/webhooks/square`; copy the signature key back into `square_signature_key`. Start in sandbox — Square's test cards exercise the whole path including the callback.

**order_id is the join.** A callback arrives carrying the processor's order id and nothing about the invoice. `payment_links` maps that order id back to the invoice, which is how the payment is recorded, the receipt issued and the balance recalculated. (Uniqueness on `payments.processor_ref`, the payment-link architecture and consent gating are enforced by the application — see the app rules; they are not re-litigated here.) The webhook records **what the processor says was taken**, not what was expected: if the customer pays a different amount or adds a tip, the invoice balance is recalculated from the payments, because the provider is the source of truth for money that actually moved.

**A manual recording path is required and permanent** — cash, cheques, and cards run on a separate terminal, with a reference field for the terminal's transaction id. It does not go away whatever else is configured.

### Phone numbers

Storage is E.164 and display is not. The `phone_e164` column is `VARCHAR(16)`; normalisation happens at every boundary — intake form, customer create and edit, CSV import — by stripping non-digits, prepending `+1` to a 10-digit number, prepending `+` to an 11-digit number starting with 1, accepting an already-well-formed international number matching `^\+[1-9]\d{1,14}$`, and returning null otherwise. Display renders `+1XXXXXXXXXX` as `(503) 555-0123` and leaves anything else as-is. The UI mask (`type="tel"`, numeric input mode, 14 characters) is UX, not a security boundary — the backend validates and rejects independently. Note the general `+[1-9]\d{1,14}` pattern rather than the US-only `^\+1\d{10}$` from the early notes: the stricter version would lock out any non-US number if the business ever expands. This matters because Telnyx rejects malformed numbers with a 4xx, and failed calls pollute the logs and count against rate limits.

### Hosting, DNS and the local PHP environment

**Domain and mail.** `wkrllc.com` is a new domain; a previous one was lost, which is why Google Cloud Identity initially did not recognise it. The fix was a fresh Google Workspace / Cloud Identity signup, domain verification by TXT record (`google-site-verification=...` on host `@`), MX records for mail, then completing 2-Step Verification at `myaccount.google.com/security` because the org policy required it (enforcement adjustable per OU under Admin Console → Security → 2-Step Verification). Real mailboxes for `admin@` (registrar and hosting logins), `billing@`, `support@` and `noreply@`; aliases for `info@`, `dispatch@`, `sales@`. SPF, DKIM and DMARC all set, or automated mail lands in spam. A dedicated `ads@wkrllc.com` for advertising, 2FA on, `admin@` as recovery, and marketers added as Google Ads *users* rather than handed the login.

**Canonical domain.** Two incidents, one root cause. First, `https://wkrllc.com/` returned blank while `/index.html` and other pages loaded — the server was not serving `index.html` as the default document. Then www and the bare domain served *different deployments*: `www.wkrllc.com` was an older build still showing a placeholder `service@example.com` while the bare domain's contact page correctly showed `Admin@wkrllc.com`. The resolution is to treat **`https://wkrllc.com/` as canonical**, 301 everything else to it, and delete the stale www document root:

```apache
DirectoryIndex index.html index.php
RewriteEngine On
RewriteCond %{HTTPS} !=on [OR]
RewriteCond %{HTTP_HOST} ^www\.wkrllc\.com$ [NC]
RewriteRule ^(.*)$ https://wkrllc.com/$1 [R=301,L]
```

If the root is still blank after that, check `public_html` for an empty `index.php` shadowing `index.html`, and if necessary add `RewriteRule ^$ /index.html [L]`. Note that SiteGround's anti-bot challenge sits in front of the site and will intercept automated fetches — a blank or challenge response from a script is not evidence that the site is down.

**Local PHP.** Windows dev box running PHP 8.2.12 from `C:\php8412\`, Apache at `C:\xampp2\apache`. Working config: `extension_dir = "C:\php8412\ext"`; extensions enabled without the `.dll` suffix — `bz2, curl, fileinfo, mbstring, exif, mysqli, pdo_mysql, pdo_sqlite, sqlite3, openssl`; opcache on with `opcache.jit=1255`, `opcache.jit_buffer_size=64M`. TLS needs both `curl.cainfo` and `openssl.cafile` pointing at `C:\php8412\cacert.pem` — and watch for a **duplicate `[openssl]` section later in the file silently overriding cafile**, which was the actual bug. Dev: `memory_limit=512M`, `max_execution_time=120`, `display_errors=On`, `error_log="C:\php8412\logs\php_error.log"`, 40M uploads, sessions in `C:\php8412\sessions` with `use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax`. Production deltas: `display_errors=Off`, tightened `error_reporting`, `opcache.validate_timestamps=0` after deploy. Timezone in the file was `Europe/Berlin` and should be the business timezone.

When extensions appear "missing," work this order: confirm Apache is loading the PHP you think it is (`Loaded Configuration File` in phpinfo; fix with `LoadModule php_module "C:/php8412/php8apache2_4.dll"` and `PHPIniDir "C:/php8412"`); confirm `C:\php8412` is on PATH so companion DLLs resolve (`libssl-3-x64`, `libcrypto-3-x64`, `libsqlite3`); confirm a Thread Safe x64 build, which the Apache module requires; install the VC++ 2019–2022 x64 redistributable if the error log shows runtime errors. Verify with `C:\php8412\php.exe -m`. Never re-add deprecated directives (`safe_mode*`, `register_globals`, `magic_quotes_*`).

---

## Legal, tax and compliance

**Caution: this section was distilled from AI chat research, not from counsel. Statute cites, thresholds, tax rules and legal conclusions below are a starting point for a conversation with a licensed Oregon attorney, a CPA, and the relevant agencies — not a substitute for one. Confirm anything consequential before relying on it, and re-check thresholds annually.**

### Oregon's repair-estimate statutes apply to mobile roadside work

The central finding, and the one that shapes the whole document chain, is that **ORS 646A.480–646A.495 applies to WKR even though there is no shop building**. The statute defines a "vehicle repair shop" as any business entity that, for payment, evaluates the condition of, maintains, or repairs a motor vehicle — no brick-and-mortar requirement anywhere. Diagnosing a no-start on the shoulder, changing a tire in a parking lot, installing a battery in a driveway: all regulated repair work. Going mobile removes possession; it does not remove the statute.

| Rule | Requirement |
|---|---|
| Estimate before work | Prepared **before work starts**, copy to the customer no later than final payment, copy retained in records |
| Change cap | Work or method may not be changed in a way that increases cost by **more than 10% or more than $200, whichever is less**, without **separate owner authorization** |
| Disassembly | Estimate must state the **evaluation/disassembly cost** plus a separate **reassembly estimate** if the customer declines further work |
| Body/frame work | ORS 746.292(2) separately requires a written estimate and bars charging in excess of it without consent — no fixed percentage |
| Under $200 | The estimate rule and cap don't technically attach, but the **Unlawful Trade Practices Act** always does: no quoting $95 and billing $250 |

The whichever-is-less construction is what people get wrong. On a $500 estimate, 10% is $50, so the trigger is **$50**; on a $4,000 estimate the trigger is the **$200** flat. The cap always bites at the smaller number.

**Both rules are already enforced in the application.** `config.php` carries `variance_abs 200.00` and `variance_pct 0.10`, and the invoice-versus-estimate check uses `min($200, 10% × estimate)` exactly as the statute reads. Separately, `authorization_threshold 200.00` requires a captured signature on any estimate totalling above $200. Don't re-derive either rule by hand; customer-facing copy should track the code word for word.

**Valid authorization methods.** The statute accepts three: signature under a printed authorization statement; **documented phone assent** (record who authorized, their phone number, date and time, on the estimate itself); or a **written electronic message** — text or email — attachable to the record. WKR's published Terms adopt a *stricter* standard: written approval only, showing the authorizing party's name and a date/time stamp. Phone assent is legal but weakest in a dispute; if it is taken in the field, confirm by text immediately so a written artifact exists.

**Stop-work.** If the customer does not approve an overage, all work stops immediately and nothing beyond the authorized scope is performed; the customer still owes for authorized work already performed and parts already installed or consumed. Performing unauthorized work and then invoicing for it is the exact conduct the statute exists to prohibit, and it converts a collectible invoice into an exposure.

In the app this is structural, not advisory. The **dispatch gate** requires at least one catalog line on the estimate *and* customer authorization on file before anything is dispatched — nothing goes out on a verbal "sounds good." Lines are catalog-only, so an estimate cannot grow through free-typed additions, and every line snapshots its cost, markup and final price when written, so later catalog or markup edits never retroactively change what the customer authorized. That snapshotting is what makes the estimate a legally meaningful cap rather than a moving number.

**Recordkeeping.** Keep the estimate, the authorization artifact (signature image, text thread, email), the work order, completion notes, before/after photos, the invoice showing the original estimate plus each approved supplement with its date/time and approval method, and the payment and receipt records. The app enforces durability: documents lock once real, nothing is ever deleted (voids and credits instead), every mutation lands in an append-only `audit_log`, and document numbers (`PREFIX-YYYYMMDD-###`) are assigned once and never change. One technical note: legal-grade timestamps belong in local time in `DATETIME` columns, not `TIMESTAMP` (which silently converts timezones), with PHP and MySQL both pinned to `America/Los_Angeles`.

**Out-of-state jobs.** Most states require a written estimate above a threshold, often around $100, under one of two models: a hard percentage rule (Illinois and Maryland run roughly the 10% pattern) or a plain consent rule. Itemized invoices — each part, each labor line, new/used/aftermarket status — are commonly mandatory. Running Oregon's stricter discipline everywhere keeps WKR compliant without per-state logic.

### Possession: why "we'll hold the vehicle until you pay" is unenforceable

Possession means **actual physical control, not ownership**. On a roadside call at the customer's location or on a public street, the *customer* never gives up possession; WKR has at most temporary custody while actively working. Possession transfers only when a vehicle ends up in WKR's controlled custody — behind a gate, or towed to storage.

Oregon's possessory lien statute (the ORS 87.152 area) attaches only to a chattel "in the possession of the person" asserting the lien, and the remedy it grants is to *retain possession* until charges are paid. On a public street there is no possession to retain. "We will hold your vehicle until paid" is therefore unenforceable, and acting on it — blocking, immobilizing, refusing to return keys — risks unlawful conduct on a public roadway. The field tactic of keeping the keys or leaving the vehicle disassembled until paid is **rejected for the same reason**. Jason's standing directive governs: no unenforceable verbiage anywhere, ever.

**The adopted remedy is prepayment, not retention.** Because WKR does not take possession, **all authorized work, including labor, is paid in advance**. This is generally lawful in Oregon — collecting before performing is not illegal; the "you can't take money before the work is done" belief is simply wrong — provided three conditions hold:

1. An estimate and captured authorization exist before work begins.
2. The >10%/$200 separate-authorization rule is followed, and each additional authorized amount is likewise prepaid.
3. **Undelivered services are refunded on request**, minus clearly disclosed good-faith retentions (dispatch or cancellation fee, special-order parts already purchased). Taking money and not delivering is itself an unlawful trade practice.

Three instruments support prepayment. A **dispatch fee** — customer-facing as an on-site service fee, "covers travel, setup, and bringing the tools to you," never a bare "fee" — is earned when the technician begins travel, not when the phone rings. Portland benchmarks: $50–$75 budget, $65–$95 the sweet spot, $100–$150+ premium or after-hours; the rule of thumb is half an hour of labor, which at the app's `default_labor_rate` of $125/hr makes **$65 the baseline**, waived for fleet and large jobs, raised after-hours or out-of-area. A **non-refundable materials clause** covers consumables bought for a specific customer. **Card preauthorization** at booking for the full quoted amount, capturing only what is earned, is the belt-and-suspenders version.

Alignment note: in the running app, card payment is a **hosted checkout link**, not a terminal and not a preauth flow — `payment_links` maps the processor order back to the invoice, and `payments.processor_ref` is UNIQUE, which makes a replayed webhook a harmless no-op. Preauth-and-capture exists in Square's POS app and remains a sensible future addition, but today the enforceable mechanism is *prepayment through a payment link before dispatch*, backed by the dispatch gate. Mechanic's liens without possession are a weak last resort, not a collection tool.

Related: no law gives a tow company exclusive rights to a motorist because it was dispatched first, and tortious interference requires a valid contract, knowledge of it, intentional causation of breach, and damages. Police rotations are the exception, and Oregon bars tow solicitation at accident scenes. Operating rule: **don't poach, don't interfere, let the customer choose** — if a driver already has AAA en route, have *the customer* cancel it, then serve them.

### Contract law: verbal, text, and two case studies

A verbal contract is fully enforceable when the elements are present — **offer** with specific terms, **acceptance**, mutual intent to be bound, **consideration** both ways, and definite terms (what work, how much, when). Quote a price, hear a clear yes, begin work: that is a contract. Not contracts: casual conversation, negotiation without agreement, vague promises, promises without consideration. Statute-of-Frauds writing requirements (performance impossible within a year, real estate, guaranteeing another's debt, large goods sales) don't touch same-day service work.

The real exposure is never validity, it is **proof**: courts look for confirming texts, call logs, witnesses, partial performance, invoices issued afterward. Hence the standing practice, **confirm every verbal term in writing immediately** — "Per our call, I'll replace the battery today for $320" — which in the app is a byproduct of issuing the estimate and capturing authorization.

Text threads follow the same logic with one critical asymmetry: **silence is not acceptance**. A thread does not become a contract because the other party failed to object. (Unanswered accusations can carry evidentiary weight, but that is a different doctrine and must never be relied on as consent.) Texts *do* bind when the thread shows offer, acceptance and consideration — "deal," "agreed," "yes," specific amounts and deadlines. And **contracts do not require signatures**: "I never signed anything" is a weak defense against a documented exchange.

**Case study — the ODOT fuel-delivery cancellation.** A customer requested fuel delivery, accepted the quoted price ("OK, come on down"), and Jason bought fuel and drove three-quarters of the way before ODOT stopped and fueled the driver for free. A verbal contract existed — offer, acceptance, reliance — but ODOT, a public-safety actor with no knowledge of the agreement, committed no tortious interference. **Lesson: this is a customer-cancellation problem, not a competitor problem, and the fix is contractual, not legal.** The loss is too small to litigate; what removes the sting is the dispatch fee earned at departure, the non-refundable-fuel clause, and preauthorization at booking — void if cancelled before departure, capture the fee if cancelled after, fee plus fuel if fuel was already bought.

**Case study — the $65 phone-collateral incident.** A customer accepted a $65 gas delivery with his phone held as collateral for up to two weeks, then missed fourteen-plus documented deadlines. The text thread *was* the contract and *was* the permission to sell the collateral: offer, acceptance, consideration and performance were all in writing. Selling it after repeated non-payment and a documented final written warning ("pay by 4pm or the phone is sold") is textbook secured-collateral enforcement, and stating plainly that the debt was cleared closed the matter. Every counter-claim — police threats, "you accessed my data," "language barrier" — was defeated by the record: phone kept off and locked, one unlock attempt only to assess resale value, sale to a dealer that openly buys locked phones, the debtor's own use of a translator throughout. **Lesson: the record wins.** Back up entire threads; follow through on every deadline you state, because a deadline you let slide is waived; once settled, stop responding. Oregon small claims handles disputes up to **$10,000** without a lawyer.

### Liability, damages and the tow-bill question

Three terms of art: **direct damages** fix the actual thing that went wrong; **incidental damages** are the reasonable cleanup costs of a breach (return shipping, re-sourcing a part); **consequential damages** are ripple-effect losses (a lost delivery contract because a part arrived late). The standard clause "not liable for consequential or incidental damages" confines a claim to the direct remedy — refund or replacement — and is standard-fair, not sharp practice. To stay customer-first alongside it: allow receipt lookup, and make repair-versus-replace decisions transparent.

The service authorization line — *"I authorize White Knight Roadside to perform the services above and assume liability for site access"* — means two things only: the customer approves the listed work, and the customer is responsible for lawful access to the location (property-owner or HOA permission, gate codes, escorts, tow-yard authorization). It does **not** waive WKR's own negligence. Clearer wording: *"I authorize White Knight Roadside to perform the services listed above. I confirm I have permission to request service at the service location and I am responsible for providing or obtaining access (e.g., gate codes, parking permission, security approval)."*

**Tow bills after a repair.** The customer normally pays the tow up front and recovers from whoever is responsible. **The shop owes the tow** when the breakdown was caused by its own bad work, bad diagnosis, or a defective part it supplied — the tow becomes part of the customer's damages. **The customer eats it** when the failure is unrelated, involves a different system, or the warranty excludes towing (many do); insurance, AAA or credit-card benefits may cover it. Either way, document: tow receipt, original invoice, photos, fault codes, the failed part. For California jobs, the Bureau of Automotive Repair mediates this class of complaint.

Two field points. A **failed jumpstart is still billable** when the no-start cause is the vehicle's condition rather than the service. Adopted work-order language: *"Jumpstart/No-Start Attempts: fee applies to response and diagnostic/attempted start. Service does not guarantee vehicle will start if battery, cables, starter, immobilizer, or other vehicle faults are present."* Waive only if the service was misdescribed as a guaranteed start or WKR's own equipment failed. And **stranded-driver / good-Samaritan work should not be improvised**: the moment WKR responds for payment it is a commercial service call governed by everything above, so run the same estimate-and-authorization path even on a small favor.

### Sales tax: Oregon has none, out-of-state jobs are the exception

**Sales tax is not based on the Portland business address.** Oregon has no general sales tax, so there is no Oregon tax to carry anywhere. The app reflects this: `default_tax_rate` is **0.0**. That default is correct for the overwhelming majority of jobs and wrong for exactly one category — work performed across a state line — which is where deliberate handling is required.

For interstate jobs the analysis is three questions: which state did the job happen in; does that state tax *that kind* of work (labor-only repair, installation, parts, towing, fuel and lockout are treated differently); and is registration required there before collecting anything — physical presence can trigger nexus immediately, while economic-nexus thresholds apply to remote sellers.

| State | Sourcing and taxability |
|---|---|
| Washington | Sourced to **where the customer receives the service**; labor coded where performed, installation where installed. **Special rule: automobile towing sources to the business location**, not the customer's. Registration triggered by physical presence or WA-sourced receipts. Publishes a downloadable rate database, updated quarterly. |
| California | Out-of-state businesses "engaged in business" in CA may need to register. **Parts are generally taxable; separately stated repair/installation labor can be nontaxable** — line-item separation is financially material, not cosmetic. |
| Oregon | No general sales tax. |

Universal invoice practice: **line-item labor, installed parts, fuel product, towing and fees separately**, always, so only the taxable portion is taxed. The app's catalog-only per-line pricing already produces that shape. Out-of-state work can also create non-sales-tax obligations — state registration, income tax, Washington's B&O.

Never hardcode rates: the application records *what was sold and where*, a tax engine decides *how much tax*. Paid engines evaluated — Avalara AvaTax (strongest for mobile multi-jurisdiction), Stripe Tax, TaxJar — have no permanent free tier. **The free path is TaxCloud via Streamlined Sales Tax (SST)**: CSP services are free in the 24 SST member states for sellers qualifying as CSP-compensated (registered through SST, no fixed place of business in the state more than 30 days, under $50,000 property/payroll there, remote-seller economic nexus only). Washington is an SST member state, covering the most likely exposure.

Because the software must be sellable without forcing anyone onto a paid service, the design is a provider-agnostic tax module with three modes: a free built-in engine driven by official SST rate/boundary files and WA's published database; an SST/TaxCloud mode where eligible; optional premium adapters. Two per-state flags govern behavior — `tax_registration_status` and `tax_collection_enabled` — with one inviolable rule: **never collect tax in a state where WKR is not registered**, even when an API returns a number. Calculate at estimate and again at final invoice, and store the jurisdiction snapshot for audit.

### Entity, local registration, brand and code

**Oregon LLC annual report** is due each year on the LLC's anniversary date through the Secretary of State Corporation Division. A prolonged lapse leads to administrative dissolution and a reinstatement process; status is checkable through Oregon Business Search. This is the most urgent item whenever it slips, because dissolution undermines the liability shield the LLC exists to provide.

**City of Portland and Multnomah County.** Businesses operating in Portland must register with the Revenue Division and **file annual returns even when exempt**. Registration is free; the filing is what claims the exemption, and not filing risks penalties and estimated assessments. For tax year 2026: Portland Business License Tax exemption under **$75,000** total gross receipts (all business activity everywhere, not just Portland), Multnomah County Business Income Tax exemption under **$100,000**. WKR is comfortably under both, so catching up is late filings rather than tax bills. For income reconstruction, Square transaction history is the primary record, supplemented with bank statements and expense receipts; agencies routinely accept honest reconstruction from businesses returning to compliance.

**Trademark.** The "Prime Mark Registry" text — *"a third party has contacted us to trademark White Knight Roadside LLC…"* — is a known solicitation scam script; the USPTO specifically warns about firms using alarming "someone is registering your name" claims to sell services. Never respond to the solicitor; verify any claimed application at tmsearch.uspto.gov. WKR very likely already holds **common-law rights** from use in commerce regardless. Real costs: **~$350 per class** in USPTO filing fees using standard descriptions, attorney optional at $500–$2,000+, clearance search $0–$500 (DIY free), maintenance filings between years 5–6, around year 10, then every ten years — **DIY total roughly $350**, with an attorney $1,000–$2,500. Correct class is **International Class 37** (vehicle repair, maintenance, roadside assistance); one class suffices because no products are sold under the brand. No federal registration of "White Knight Roadside" was found and existing "White Knight" marks sit in unrelated industries — roughly 8–9 out of 10 odds.

**Software.** To preserve the ability to sue over unauthorized use, **do not use an open-source license** — MIT/BSD/Apache permit reuse outright; GPL/AGPL only force share-back. Use a custom proprietary license, All Rights Reserved, forbidding copying, modification, reverse-engineering, sublicensing, distribution, hosting and derivative works without prior written consent, terminating on breach, referencing **17 U.S.C. §§ 501–505**. Then **register the copyright** (~$45–$65): registration, not mere ownership, unlocks statutory damages up to $150,000 per willful infringement plus attorney's fees. Enforcement ladder: evidence → cease and desist → DMCA takedown → federal suit. In place: a copyright header in every source file, a repo `LICENSE.txt` stating the software is licensed and not sold, private repositories, license keys and API authentication.

### Terms of Service and Privacy Policy

Both documents were published for wkrllc.com dated January 26, 2026, under the business identity **White Knight Roadside, 2455 NW Nicolai St., B-E1, Portland, OR 97210, Admin@wkrllc.com**.

**Privacy Policy.** WKR **does not sell personal information** and does not share it except where immediately necessary to complete the service request — minimum-necessary, no advertising or data-broker disclosure. The one carve-out is disclosure required by law, court order, or safety. Vendors named as recipients-as-needed: **Square** (payments; no full card numbers stored on WKR systems), **SiteGround** (hosting), **AT&T** (communications), **AutoZone / O'Reilly** (authorized parts), **Google/Alphabet** (maps and security tools where a used feature requires it). On the **Oregon Consumer Privacy Act** (effective July 1, 2024), the determination is that OCPA does not apply at current scale: *"Based on our current operations, we do not believe the Oregon Consumer Privacy Act (OCPA) applies to White Knight Roadside at this time. If legal obligations change, we will update this Privacy Policy accordingly."*

**SMS terms.** Messaging is **service updates only** to opted-in numbers, STOP to opt out; marketing texts would require prior express written consent under the TCPA and are out of scope. The published terms must carry the 10DLC-required language: program name and purpose, message frequency ("message frequency varies"), "message and data rates may apply," HELP for help and STOP to cancel, a support contact, and links to the Privacy Policy and SMS terms — plus the explicit statement that mobile numbers collected for service messaging are **not sold or shared with third parties for marketing**. That last sentence is what carriers look for during 10DLC vetting. The app enforces consent in code: no SMS without `sms_approved`, `sms_consent_at` and `sms_consent_source`, and **STOP is never reviewable — it is honoured immediately**. Delivery is Telnyx, account and campaign approved.

**Terms of Service §3, the roadside-safe structure.** Nine parts: (1) pre-inspection pricing is an **estimate** and conditions can change; (2) **separate authorization** for changes exceeding 10% or $200, whichever is less — statutory; (3) **written approval only** — signature, email or text showing name and date/time — stricter than the statute, which would also allow documented phone assent; (4) **stop-work if not approved**, immediately and completely; (5) the customer pays for **authorized work already performed** and parts already installed or used; (6) **no vehicle-retention claims** for roadside or public-location work, with lawful payment remedies instead (invoice, documented debt, collections); (7) **installed and special-order parts are non-returnable** except as required by law or manufacturer warranty, since installed parts become part of the vehicle; (8) possessory-lien rights reserved **only** for vehicles actually in WKR's lawful custody; (9) governing law **Oregon**, venue Oregon state and federal courts.

A note on the older document-chain proposals, since the compliance argument for them was the strongest one made: what is superseded is giving each stage its **own numbered document** — separate Estimate Approval and Change of Service records, and `DOC-YYYYMMDD-###-V#` versioned numbering. The **stages themselves are all still there**, carried as authorization blocks and signature captures inside the six documents; see [Stages within the chain](#stages-within-the-chain) for where each one lives. Every legal protection that chain was meant to deliver survives the merge — the estimate carries a full authorization block with method, timestamp, IP and signature; nothing dispatches without it; the variance rule forces re-authorization before an invoice can issue; documents lock once real; and the audit log is append-only.

The one place the merge costs something legally is **evidentiary hand-over**. A signed completion and a signed scope change exist in the data but cannot be printed as standalone documents, because only the estimate and invoice have print routes. For a customer dispute the audit log and the signature blocks are the evidence and they are sufficient. For a partner claim that demands a signed completion certificate as a deliverable, they are not — that would need a printable artifact, which is a reporting gap rather than a modelling one.

---

## Marketing and customer acquisition

### Brand and positioning

The tagline is **"We Answer the Call."** It replaced "Always Ready," dropped because that is the Coast Guard motto and there is no interest in stealing valor. The double meaning is the point: answering the phone when a stranded driver dials, and answering the call of duty — the knight theme without overdoing it. Runner-ups: "Ready When the Road Isn't," "When the Road Lets You Down, We Show Up," "Your Knight on the Road."

WKR is a solo owner-operator mobile roadside service in the Portland, Oregon metro, based in Beaverton: jump starts, vehicle lockouts, flat tire change, tire repair and replacement, fuel delivery, battery replacement and installation, and mobile mechanic work at the $125/hour labor rate the app also carries as its default. What it explicitly is **not** matters just as much, because every wrong call costs a click and a minute. **No towing** — no tow truck, flatbed, or rollback. **No winching or recovery** — no stuck-in-mud, ditch pulls, vehicle recovery. **Not a locksmith** — car unlock only, no key replacement, fob programming, ignition work, or house/apartment lockouts.

Positioning follows from that: "we fix it where you are, no towing" — fast, local, licensed and insured, mobile. Speed beats price here, so copy avoids "cheap" and "free" entirely and leans on "Professional, Licensed, Insured." "No Towing" appears in the ad copy on purpose — cheaper to pre-filter a tow shopper in the ad than to pay for the click. Service area is a 30-mile radius from Beaverton — Portland, Hillsboro, Tigard, Lake Oswego, Gresham, West Linn, Tualatin — with Vancouver WA excluded from paid targeting initially even though it appears in the SEO city-page list. Two numbers appear in materials and must not be mixed up: **(503) 764-3154** is the website/SEO number, **(503) 974-2741** is the SMS number in the 10DLC and compliance messages.

### Google Ads: the canonical campaign structure

One Search campaign, goal = phone calls: **"Roadside Assistance — Non-Towing,"** organized by buying intent rather than by four broad service names. That is the most important structural decision in the account — "flat tire" is three different customers at three different price points, and mashing them together destroys both the ad copy and the negative lists.

| # | Ad group | Representative keywords | Group negatives |
|---|---|---|---|
| 1 | Car Lockouts | car lockout service, locked keys in car, car unlock service | key replacement, key fob, program key, lost key, ignition repair, house lockout |
| 2 | Jump Starts | jump start service, dead battery service, mobile jump start | battery replacement, battery installation, battery store terms |
| 3 | Tire Change / Spare Install | flat tire change, roadside tire change, spare tire installation | new/used tire, tire shop, patch, plug, repair, mount and balance, alignment |
| 4 | Tire Repair (plug/patch) | mobile tire repair, tire plug service, nail in tire repair | spare tire, install spare, new/used tire, tire shop, wheels, rims |
| 5 | Tire Replacement (no spare) | mobile tire replacement, flat tire no spare, bring me a tire | rotation, alignment, wheels, rims, tire rack, discount tire, les schwab |
| 6 | Mobile Mechanic | mobile mechanic near me, mechanic comes to you, car won't start mechanic | engine rebuild, transmission, head gasket, body shop, collision, dealership, jobs, diy |
| 7 | Battery Replacement | mobile battery replacement, car battery replacement near me | jump start, battery boost, charger, tester, free battery test |

Groups 2 and 7 split because a jump start is an emergency and a battery is a purchase; groups 3, 4 and 5 split because spare-on-board, plug/patch and bring-a-tire are different jobs at different prices. Group 6 gets the tightest keyword control — mobile-mechanic terms are broad and expensive.

**Match types at launch: Phrase and Exact only.** No Broad, no Performance Max, no Display, no Search Partners until conversion data is clean. Roughly 10–15 active keywords per ad group. **Location targeting must be "Presence: people in or regularly in your targeted locations," never "Interest"** — someone in Phoenix reading about Portland is not a customer. Lockout advertising can trip Google's locksmith verification policy, so the wording is always "Car Lockout Service," never "locksmith," and the site stays framed as automotive roadside.

The **non-towing negative keyword shield** applies account-wide and is the highest-leverage list in the account:

| Block | Terms |
|---|---|
| Non-towing | tow, towing, tow truck, wrecker, flatbed, rollback, winch, winching, stuck in mud, stuck in ditch, ditch recovery, vehicle recovery, impound, repo, repossession, junk car removal, scrap car, sell my car, auction |
| Information / DIY | how to, diy, do it yourself, instructions, tools needed, youtube, video, guide, manual, training, class, course, school, jobs, career, salary, apprentice, certification |
| Bad-fit / low intent | free, cheap, coupon, amazon, ebay, harbor freight, parts only, tool kit, business for sale, franchise |

Start conservative on the third block — "cheap," "used," and retailer names can catch real buyers — and expand from the Search Terms report. Account negatives grow past 200 over time; the weekly Search Terms check is the job.

**Ad format: Responsive Search Ads plus call assets.** Call-Only ads are deprecated — new call ad creation was removed February 2026, existing call ads stop serving February 2027 — so the build is RSAs + Call Assets + Location Assets + Call Reporting + phone-call conversion tracking. Pin Headline 1 to a call CTA ("Emergency Jump Start — 15 Min Arrival"); set the Calls bid adjustment ~+20%. Link the Google Business Profile so Location Assets serve into "near me" searches and show distance — people call whoever looks closest. Keep sitelinks minimal and service-focused; About Us is a distraction to someone standing next to a dead car. **The call asset phone number must appear in plain text on the website homepage or the asset gets disapproved.** Set a 45–90 second minimum call length on the call conversion so misdials do not train Smart Bidding.

RSA copy bank — headlines "Roadside Assistance in Portland," "Fast Help—Call Now," "Jump Starts • Lockouts • Tires," "Locked Out? We're 30 Mins Away," "Upfront Pricing"; descriptions "Stuck right now? Call for rapid dispatch in Portland. Upfront pricing, mobile service, pay by card," "Jump starts, lockouts, tire changes, fuel delivery. Real-time ETA. Call now," "No towing — we fix it where you are. Licensed, insured, local, 24/7"; callouts 30-Min Response, Licensed & Insured, 24/7 Availability, Flat-Rate Pricing, Card Accepted; structured snippet Services: Jump Start, Lockout, Tire Change, Fuel Delivery. A small side bet exists on competitor intercept — bid on [AAA roadside assistance Portland], Urgently, Honk, Allstate with "Skip the Wait — Faster than AAA, no memberships, no call centers."

**Bidding.** Manual CPC with Enhanced CPC at launch rather than Smart Bidding, to avoid buying a learning phase on a tiny account; move to Maximize Conversions or tCPA only after ~20 tracked conversions. Bids tier by intent: Tier 1 emergency-now (lockout, jump, tire change) ≈110% of the high top-of-page benchmark; Tier 2 scheduled/commercial (mobile mechanic, battery) ≈80%; Tier 3 generic "roadside assistance" capped low. Modifiers: Mobile +25–30%, Desktop −50%, Portland core +15%, after-hours +20%. Rules of thumb: CTR under 3% is a copy problem, not a bid problem; Lost IS (rank) above 30–50% means raise the bid; CPC above $18 means pause the keyword. The dollar benchmarks that circulated were AI estimates, not live API pulls — verify in Keyword Planner scoped to Portland OR + 30 miles first. An early budget sketch put ~$25–30/day (~$750–900/month) across five campaigns: Emergency Roadside $15, Battery Installs $5, Mobile Mechanic $5, Competitor Intercept $2, Display Retargeting $1 on a 30-day non-converter audience.

**Dayparting** is where a solo operator gets an edge: +25% for the 6–9 AM rush (dead batteries in driveways), +20% for the 4–8 PM rush, +35% late night *only if the phone is actually being answered*, −10 to −20% midday. "Weather Warrior" is manual — raise jump-start and battery bids ~50% for the 48 hours before a cold snap. The rule underneath all of it: **ads go OFF when the phone cannot be answered.** Miss three rings and they call the next guy, and you paid for that click anyway.

### The bidding failure lesson

The account once ran at **$150/day and produced zero impressions.** Diagnosis: Max CPC bids sat far below the top-of-page ranges — $0.86–$2.11 against a market clearing at $5–$15+, and $9.31 on "car unlock service" where the high range was roughly $15–20. Budget was never the constraint; the bid was. An ad that never enters the auction spends nothing and shows nothing, and no amount of daily budget fixes it.

The check that prevents a repeat: read the **"Top of page bid (low range)" and "(high range)" columns** in Keyword Planner for every keyword before setting a Max CPC, and never bid below the low range. Low bids have a second failure mode beyond invisibility — they can suppress the call button, so the ad serves in a form that cannot produce the one conversion that matters.

The parallel diagnostic for clicks that never become calls: slow site or click friction; no local trust signal in the ad; sticker shock (put "Starting at $X" in assets); accidental or bot clicks (check Invalid Clicks); or Call Reporting simply off, so calls happen but report as zero conversions. Fixes: sticky click-to-call header on mobile, headline pinning, active call asset, Call Details report to expose 0-second hang-ups. The weekly loop is short on purpose — Search Terms report → negatives; shift budget to converting services; bid up peak hours; improve landing pages for the top two ad groups only; mark each call qualified or unqualified.

### Keyword research output and automation

A dump of **1,923 cleaned keywords** was classified into launch groups and exclusion buckets, producing the workbook `google_ads_adgroup_organization.xlsx`.

| Campaign | Ad group | Keywords |
|---|---|---:|
| Jump Start | Jump Start / Dead Battery Service | 295 |
| Mobile Tire | Flat Tire Change / Spare Install | 221 |
| Mobile Tire | Mobile Tire Repair / Patch / Plug | 196 |
| Mobile Tire | Mobile Tire Replacement / Install | 115 |
| Mobile Tire | Flat Tire Emergency / No Spare / Blowout | 106 |
| Vehicle Lockout | Vehicle Lockout / Car Unlock | 119 |
| Fuel Delivery | Gas / Fuel Delivery | 81 |
| Roadside General | Roadside Assistance — General | 78 |

Excluded buckets: jump-starter products and DIY gear (327, all negatives), battery replacement (128, run only against the service actually offered), broad auto repair (33, review only), how-to/informational (37), free/cheap shoppers (17), towing/flatbed (14), locksmith/key replacement (9), propane (7). Launch order: Jump Start first, then tire groups, fuel, lockout, roadside general — heavy-truck/commercial and mobile-mechanic held back.

On automation the two paths are not equivalent. The official **Google Ads API** (official PHP client library; Explorer → Basic at 15k ops/day → Standard) can actually create campaigns. The official **Google Ads MCP server is read-only**, analysis only; the Google Marketing Solutions GitHub MCP has mutations but is experimental, disabled behind `ADS_MCP_ENABLE_MUTATIONS`. The design adopted for WKR is an **AI Campaign Drafting System**: AI reads the business rules (no towing, Portland area, service list, pricing, hours) → generates draft campaign JSON → a business-rule validator enforces constraints (no towing keywords except as negatives, no false claims, budget cap, geo cap) → the API creates everything **paused** → human review → enable. Reporting runs the other way: MCP or API pulls performance, an AI analyst proposes pauses, negatives and budget shifts for approval.

The research workflow is baked in: resolve geo target constants (Portland plus realistic nearby cities) → `KeywordPlanIdeaService.GenerateKeywordIdeas` with keyword and URL seeds (wkrllc.com) → `GenerateKeywordHistoricalMetrics` for volume, competition, top-of-page bid micros → score by commercial + emergency intent → cluster into lean ad groups. Rate limit 1 request/second per customer ID; cache, refresh monthly. A **reusable handoff prompt** regenerates the package in 12 sections: exec summary → research method → findings table → structure → match types → negatives → RSA copy → assets → landing pages → bidding → 30-day plan → final priorities. Its decision rules are the keeper: optimize for profitable conversions first and CPC second; never pick a keyword because it is cheap or high-volume; a smaller list of better keywords beats a large one.

### SMS marketing and staying compliant

SMS runs on **Telnyx** and the 10DLC campaign is **registered and approved** — that work is done. The campaign is "White Knight Roadside Customer Updates," a Mixed use case covering Customer Care, Account Notifications and Marketing. What follows is about staying inside the terms approval was granted under.

Three registered opt-in methods and no others: a web form checkbox **unchecked by default**, verbal consent on the intake call, and inbound text START. The app enforces this at the data layer — nothing sends without `sms_approved`, and consent is recorded with `sms_consent_at` and `sms_consent_source`. **STOP is never reviewable; it is honoured immediately.**

The exact wording, not to be paraphrased:

- **Opt-in disclosure:** "By providing your mobile number, you agree to receive text messages from White Knight Roadside about service updates, dispatch status, estimates, invoices, and occasional promotions. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help."
- **HELP response:** "White Knight Roadside: For help, call (503) 974-2741 or reply with your question. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out."
- **STOP response:** "White Knight Roadside: You have been unsubscribed and will no longer receive messages. Reply START to re-subscribe."

The nine registered sample message types define the envelope of what may be sent: opt-in confirmation; dispatch update with ETA; ETA change; estimate ready ("$185. Reply APPROVE to authorize or call…"); work-in-progress; invoice notice ("Reply PAY for payment link"); payment receipt; appointment reminder; promotion (winter battery check, "Reply INFO… Reply STOP to opt out"). Outbound traffic should stay recognizably within these shapes.

**Verbal consent read-aloud script**, delivered immediately after the customer says yes: *"By giving me your mobile number and saying yes, you agree to receive SMS updates from White Knight Roadside about service updates: dispatch, ETAs, job status, estimates/invoices. Message frequency may vary. Standard message and data rates may apply. Reply STOP to opt out and HELP for help."* For marketing texts, the same script plus **"Consent is not a condition of purchase."**

The distinction that governs everything: **transactional messages** — dispatch, ETA, estimate, invoice, receipt — ride on the verbal or checkbox consent captured at intake, while **promotional or autodialed marketing texts generally require prior express written consent under the TCPA.** Verbal-only consent is not a safe basis for marketing sends, so the promotional lane needs its own recorded written opt-in and will always be the smaller list. After any verbal opt-in send the confirmation SMS immediately: "You have agreed to receive SMS updates from White Knight Roadside. Msg freq may vary. Std msg & data rates apply. Reply STOP to opt out, HELP for help." One note for the record: a customer-care-only campaign with no marketing language clears first-time 10DLC review more easily — relevant only if a second campaign or a carrier re-review comes up.

### SEO plan for wkrllc.com

The site already does the basics well — clean URLs, keyword-targeted titles with matching H1s, breadcrumbs, FAQ blocks, some city pages, internal links, consistent NAP, mobile layout, GBP link. The plan is tiered across a 90-day rollout.

| Tier | Work |
|---|---|
| 1 — Technical (week one) | Verify robots.txt and sitemap.xml, submit to GSC + Bing. JSON-LD: LocalBusiness (`AutoRepair`/`EmergencyService`) on the homepage, `Service` + `offers` with price per service page, `FAQPage`, `AggregateRating`, `BreadcrumbList`. Canonicals plus OG/Twitter tags. Pick wkrllc.com or www, 301 the other. Fix the blank copyright year. |
| 2 — Content | A unique 600+ word page for every city served — Beaverton, Gresham, Lake Oswego, Tigard, Hillsboro, Milwaukie, Tualatin, West Linn, Oregon City, Clackamas, Vancouver — no near-duplicates. Service pages for what is actually offered (battery replacement distinct from jump start, "car won't start diagnostic," motorcycle/RV if applicable) plus a "why we don't tow + who we recommend" page. Blog two posts a month ("signs your battery is dying," "locked out of your car in Oregon"). A /reviews page, 15–20 reviews with schema. |
| 3 — On-page | Unique 140–160 character meta descriptions with phone and hook: "Need [service] in [city]? White Knight Roadside offers $[price] flat rate, 30-min response, 24/7. Call (503) 764-3154." Real photos with descriptive filenames and alt text, a "Nearby service areas" cross-link block per city page, one H1 per page, no orphan H4s. |
| 4 — GBP and citations | GBP weekly posts, 10+ photos a month, respond to every review within 24 hours, seed the Q&A. Exact-NAP citations on Yelp, Angi, Nextdoor, BBB, Apple Maps, Bing Places, Waze, YP. Backlinks via local news, sponsorships, referral partnerships with towing companies, body shops, dealerships. |
| 5 — Measurement | GSC, Bing Webmaster Tools, GA4, call tracking (CallRail-style) to attribute SEO vs GBP vs direct. |

Referring work to tow operators is not a contradiction — it is the consequence of not towing. Every tow company gets non-tow calls it does not want, and the "why we don't tow" page makes that trade legible in both directions.

### Offers and outreach

**The $35 Virtual Mechanic Assessment** — a 15-minute live video consultation for $35, with **the $35 credited toward any WKR service booked within 24 hours.** That credit turns "pay to talk to me" into "expert assessment applied to the repair." Lead generation and qualification first, revenue second, upselling into the $125/hour mobile mechanic service. It works remotely for no-start diagnosis, battery vs alternator, warning lights, tire damage and "can I drive on this?", fluid leaks, second opinions on shop estimates, pre-purchase walkthroughs and DIY guidance; poor fit for lockouts, fuel delivery, freeway-stranded emergencies, and anything scan-tool-dependent or intermittently electrical. Because it sells expertise rather than dispatch it is not bounded by the Portland radius — it can run nationwide and fill idle time between the one or two local jobs a day. Consults are scheduled, not on-demand. Required disclaimer, verbatim: "This service provides professional guidance and diagnostic assistance based on information available during the consultation. It is not a guarantee of repair outcome and does not replace an in-person inspection."

The rollout caution is the decided part: **validate before building.** Stage 1 is no platform at all — Square payment plus a Google Meet link by SMS, run manually at roughly $0 cost. Stage 2, after 10–20 paid consults, adds a booking/payment page and tracking in the app. Stage 3, only after demand is consistent, integrates WebRTC rooms on Telnyx STUN/TURN (stun.telnyx.com:3478 / turn.telnyx.com:3478) — natural since Telnyx already carries the SMS; self-hosted coturn on a $5–10/month VPS is the alternative. STUN alone connects ~70–90% of calls, TURN relay ~99%, since carrier-grade NAT affects cellular too. Do not skip to Stage 3.

**Fleet tire proposal**, aimed at true fleet accounts — couriers, trucking, delivery, businesses whose business *is* vehicles — as distinct from a company that merely owns a few trucks, which is a commercial account.

| Item | Price |
|---|---|
| Call-out / dispatch fee | $65 flat per visit |
| Tire plug repair | $30 |
| Tire patch repair | $50 |
| Tire replacement (labor, incl. mount and balance) | $80 |
| Replacement tire cost | $50–$200 by size/type/availability |
| Tire disposal | $7/tire if required |

The selling point is structural, not the unit prices: **the $65 call-out is flat per visit, and multiple vehicles or multiple tires serviced on the same dispatch do not compound the charge.** For a yard with three vehicles down that is a materially different bill than per-unit dispatch. The email frames mobile, on-site, fast-response service that minimizes fleet downtime and eliminates towing, with transparent pricing that scales, emphasizing consistency, communication and accountability, and closes by asking to discuss fleet size and service expectations rather than pushing for a signature.

**LinkedIn: skip it.** Nice-to-have credibility, not a growth lever for roadside — inbound calls come from GBP, the website, Apple Maps and Bing Places, Yelp/Nextdoor/Facebook. If a page is ever created it needs a personal profile as admin, the basics (name, URL, logo, tagline, location, services), a short About covering what/where plus two or three services and licensed/insured trust signals, and three starter posts (service-area map, services list, "how it works") so it does not sit empty. That is the entire scope.

### Organic growth routine and reviews

Highest-leverage organic levers, in order: **Google Business Profile** — correct service area, 20+ photos, one or two posts a week, messaging on, reviews driven toward 50+. **Answer speed** — the opening line is "Roadside assistance, this is Jason — what kind of problem do you have today?", callback within two minutes on anything missed. **B2B relationships** — repair shops, used car lots, rentals, dealer service centers, HVAC and plumbing fleets, delivery couriers; three to five business accounts produce steady weekly work, and the hooks are discounted fleet jump starts, priority response, monthly billing. **Differentiators against AAA** — mobile battery testing and replacement (20–30% battery markup), battery delivery, alternator testing, OBD2 scans, spare-tire transport. **Google Local Services Ads (Google Guaranteed)** is rated the highest-ROI paid channel available and should run alongside Search; Facebook Marketplace listings and local groups round it out. An optional WKR Loyalty Plan at $20/year — $10 off every call, free battery test, priority response — sits on the shelf.

Review generation is a per-job habit, not a campaign. The ask goes out by text after the work is done: **"If I took care of you today, could you drop a quick review on Google? It helps me keep my prices low."** The referral ask is equally scripted: "tell them to ask for Jason at WKR."

| When | Action |
|---|---|
| Daily, 10–20 min | GBP check plus one micro-post ("Another jumpstart in NE Portland"); review ask after every job; scan Facebook groups for stranded drivers |
| Monday | One site or blog update; review last week's lead sources |
| Tuesday | Renew Facebook Marketplace listings |
| Wednesday | Contact one or two local businesses |
| Thursday | Proof-of-work photo post |
| Friday | $10–20 weekend boost, 10-mile radius |
| Saturday | Pricing review |
| Sunday | Restock and clean the vehicle, recharge jump packs |

### Competitive landscape and what it means for positioning

The competitive research looked at the software market rather than at rival roadside operators, and its conclusion shapes marketing as much as product. The strategic target is **not** a Towbook or Jobber clone; it is a professional roadside and mobile-mechanic operating system for independent operators, moving a job from call → estimate → approval → field work → proof → invoice → payment → accounting with almost no duplicate entry.

Direct competitors: **Towbook** (roadside-native depth, transparent $109/$209/$319/$429 monthly tiers, geocoded photos, customer-location links, job-progress texts, proof of service, Square and QuickBooks); **Dispatch Anywhere / Autura** (dispatcher productivity, 250+ reports, embedded TowPay); the motor clubs **Agero / Swoop / Urgently**, which show the industry direction — public mobile-web intake links, duplicate-job prevention, customer status pages, ETA intelligence. Adjacent tools worth borrowing from: **Jobber** (polished quote → approve → pay flow), **Housecall Pro** (customer portal), **Workiz** (lead-source tracking with built-in calls and SMS — directly relevant to attributing Google Ads calls), **FieldPulse** (job-linked parts), **Shopmonkey / Tekmetric / AutoLeap / Shop-Ware** (digital inspections, profitability reporting). No mature open-source roadside competitor exists; Dolibarr is the best PHP/MySQL architecture reference, Invoice Ninja the best reference for client-facing invoicing polish.

Four patterns recur among the winners, each translating into a marketing practice:

1. **Put the customer on a link.** Intake form, estimate approval, status page, payment link — every leader does this. The estimate the customer approves and the hosted payment link are customer-facing brand surfaces, not internal documents, and they land at the moment of highest attention.
2. **Field proof doubles as chargeback defense.** Geocoded photos and captured signatures are simultaneously the proof packet that wins a disputed card payment and the raw material for the Thursday proof-of-work post and the GBP photo quota.
3. **Ask for the review at payment.** Towbook, Tekmetric and AutoLeap all fire the review request off the payment event — that is where the WKR review text belongs, attached to the receipt rather than left to memory.
4. **Track the lead source as a first-class field.** The app already carries `job_source` (retail vs provider) on every job. Which ad group gets more budget, whether Local Services Ads beats Search, whether the Wednesday B2B hour pays — none of it is answerable unless source is captured on every job and reported alongside margin by service. Report customer source and margin by service together; either alone is misleading.

---

## Trade reference: diagnostics and job pricing

### The rate card

| Rate | Value | Applies to |
|---|---|---|
| Shop labor | **$125.00/hr** | All hands-on wrench time, anywhere — driveway, roadside, or salvage yard |
| Drive time | **$81.25/hr** (65% of shop labor) | Driving only, portal-to-portal |
| Mileage | **$0.72/mi** | Round-trip miles, separate line from drive time |

Oregon has no sales tax; default tax rate 0%. An 80% drive-time rate was floated early and lost — 65% is the rate.

### How a job gets priced in practice

1. **Parts**, itemized, with part numbers where they exist. Parts come from the catalog — the app refuses free-typed lines — priced as cost plus the markup-tier percentage for that cost band, then snapshotted onto the line. Editing the catalog later never changes a document already written.
2. **Labor**, one line per operation, hours × $125 shown explicitly. Not "labor, $412.50" but "transfer/align park sensors, 0.7 hr, $87.50."
3. **Travel**, always two lines: drive time at $81.25/hr and mileage at $0.72/mi, never bundled into a "trip fee."
4. **Contingencies priced in advance** — "if the anti-theft protector is present, +1.0 hr," "rusty hardware billed at standard rate," "sensor calibration +0.5 hr."
5. **A disclaimer** tying payment to time and attempt rather than outcome.

Book time is a floor, not a ceiling: on major engine work the pad runs roughly 45% over book (the 2011 Soul timing chain, 8.0 hr against 5.4–5.5 hr book), because mobile work has no lift, no bench, no second set of hands.

The Estimate **is** the quote, and any estimate above **$200** needs a captured signature before dispatch — which makes essentially every job below a signature job. If the invoice drifts from the signed estimate by more than the lesser of $200 or 10%, it needs re-authorization: one more reason to price contingencies up front.

### Diagnostic tiers

Billed as a named service, never "checking it out" or a "scan fee." The wording implies process, which is what's being sold.

| Tier | Price | Scope |
|---|---|---|
| Base Diagnostic | **$125** | Scan, code interpretation, visual, basic electrical |
| Advanced Diagnostic | **$175** (to $225 for the worst) | Live data, correlation testing, actuator testing, mechanical verification |
| Extended, beyond scope | **$125/hr** | Wiring rabbit holes, with customer approval before starting |

The Lincoln LS throttle-body job is the reference Advanced Diagnostic, billed **$175**. Scope statement, verbal and on the invoice: *"This diagnostic fee covers systematic testing of the electronic throttle control system to identify whether the fault is caused by the throttle body, sensors, wiring, or control logic. The fee applies regardless of the final repair decision."* Swap in whichever system is under test.

Optional: credit the fee toward the repair when done same visit or within a set window. Not optional: never waive it because the answer turned out obvious, never bury it in the labor line. The line that ends the argument — *"Modern cars don't fail in obvious ways anymore. The diagnostic IS the work — I'm proving what's failed so you don't replace the wrong part."*

### Salvage-yard trip policy

> **Driving is billed at the reduced drive-time rate of $81.25/hr. Wrenching at the yard is billed at the FULL shop rate of $125.00/hr.**

Pulling a part in a junkyard is skilled labor in worse conditions than a driveway. It is not travel. The reduced rate acknowledges that sitting behind a wheel is not turning a wrench; the moment the wrench comes out the meter goes back to $125. There is no third, in-between rate for "labor away from the shop."

- **Drive time is portal-to-portal, round trip.** The invoice states the basis: "billed at 65% of shop labor rate."
- **Mileage is its own line.** Both are billed; round-trip miles at $0.72/mi.
- **Yard entry, pull, and core fees pass through at cost**, only with customer approval.
- **Payment is for the attempt** — billed whether or not the part is there, fits, or is usable, same logic as a diagnostic fee. Terms: *"Labor and travel are billed for time spent; payment is for the attempt/time, not a guarantee of yard inventory/condition."*
- **Used parts sold as-is** unless the document says otherwise.
- **Cap it before leaving** — "NTE $___ without approval" on the authorization, plus a call or text from the yard before exceeding it. Standing cap on a knuckle pull is NTE 2.0 hr.

### Worked salvage-yard invoice — the template

1994 Ford Ranger 2WD, front driver-side steering knuckle. 30 miles round trip, 60 minutes driving, one hour at the yard.

| Qty | Description | Rate | Total |
|---|---|---|---|
| 1 | Used part: steering knuckle, front left | $42.50 | $42.50 |
| 1.0 hr | Drive time, round trip — 65% of shop labor rate | $81.25/hr | $81.25 |
| 30 mi | Mileage, round trip | $0.72/mi | $21.60 |
| 1.0 hr | Junkyard labor: remove knuckle (2WD, one side) | $125.00/hr | $125.00 |
| | **Total** | | **$270.35** |

Two labor lines at two different rates on the same document. That is the shape every salvage run takes.

**Knuckle labor reference.** Book R&R one side, no alignment: **2WD 1.9 hr** (bill 2.0), **4WD 2.5 hr**; front toe adjust 0.4 hr, full front alignment 2.3 hr. Removal-only at a yard: 2WD Twin I-Beam **0.75–1.0 hr**, 1.5–2.0 hr rusty; 4WD TTB 1.0–1.5 hr, 1.5–3.0 hr stuck. The wildcard is separating the spindle from the knuckle — ten minutes or two hours, nothing between. Rule: **1.0 hr minimum** on a 2WD pull, overage at actual in 15-minute increments, NTE 2.0 hr.

### Worked quotes

| Vehicle | Job | Labor | Parts | Total |
|---|---|---|---|---|
| 2015 Kia Soul + 2.0L GDI | Ignition lock cylinder w/ anti-theft protector + housing | 1.8 hr = $225.00 | Customer-supplied, est. $332–$348 | **$225.00 labor only** |
| 2011 Kia Soul 1.6L Gamma (G4FC) | Timing chain, guides, tensioner + front cover reseal | 8.0 hr = $1,000.00 | $328.00 | **$1,328.00** |
| 1987 Nissan Sentra | External inline fuel pump replacement | 1.0–1.5 hr = $125–$187.50 | ~$175 | **$362.50**, presented "$350–$380" |
| 2013 Toyota Corolla 1.8L | Fuel tank R&R, tank dropped | 3.5–4.0 hr | Not included | **$450–$500 labor** |
| 2015 Chrysler 300 RWD | Front bumper cover, fog lamps + park sensors, no paint | 3.3 hr | Not included | **$412.50 labor** |
| 1994 Ford Ranger 2WD | Salvage steering knuckle: trip + pull + part | 1.0 hr drive + 1.0 hr yard | $42.50 | **$270.35** |
| Any | Tire mount & balance | Per service definition | Tire not included | Priced per unit |

**Kia Soul ignition cylinder** (VIN KNDJP3A59F7794086, OR plate 521NQR, non-push-button start). Base R&R **0.8 hr** when the cylinder releases normally — book says about an hour, WKR quotes 0.8. This car carried the anti-theft cylinder protector from the CS2311/9A5 campaign: a metal sleeve bonded over the cylinder with epoxy and break-off screws, meant to be permanent. Removing it means replacing the whole lock housing, **+1.0 hr**, hence 1.8 hr. Parts, customer-supplied: **81905-B2110** key & cylinder set, no push-button start, folding key ($275–$285); **81910-B2100** lock housing ($50–$53); **2× 81919-31000** one-time-use shear bolts ($3.60–$5.20 ea). Key cutting, two blades, $30; bench rekey to match the door key +$85 instead. Most 2014–2015 US Souls without push-button start have no immobilizer chip, so cutting blades is sufficient — the CS2311 bulletin itself notes the affected vehicles lacked immobilizers. Generalizable lesson: **verify Kia part numbers against the production-date window, not the model year.** A 2015 needs 08/2013–08/2016 parts. **81905-B2000** is the same set with a straight key ($115–$153); **81905-B2111** reads almost identically but is late-production only (12/2017–12/2018) and is wrong here; 81905-B2300 appears on some trims and EVs — confirm by VIN.

**Kia Soul timing chain.** 1.6L Gamma G4FC, not the 2.0L. Chain rather than belt, and **interference** — if the chain has already jumped, valve-to-piston inspection is required and head or valve work is extra; put that on the quote. Parts, $328: chain kit $225 (OEM chain **24321-2B000**, tensioner **24420-2B000**, guides **24410-25001** and **24431-2B000**; aftermarket $110–$150), valve cover gasket $25, front crank seal $15, RTV $18 (front cover is RTV-sealed per factory procedure, Loctite 5900 or equivalent, no gasket), oil and filter $45. Scope: chain/guides/tensioner R&R, front cover reseal, oil and filter, timing verification, road test. Excluded: VVT actuator and sprockets, fees, towing.

**Sentra fuel pump**, external inline, not in-tank. Labor covers raise, relieve pressure, disconnect, swap pump and short hoses/clamps, prime, leak-check; write 1.5 hr if rust or prior hack repairs are likely. Parts: pump $90–140, filter $25, EFI hose/clamps $20, supplies $10. Customer-supplied pump drops it to $210–$225. Fuel pressure test is a 0.5 hr add ($62.50). Include the "rusty hardware billed at standard rate" line.

**Corolla fuel tank.** Tank comes down — filler neck, EVAP lines, fuel lines, straps. Adders: a full tank needing drain, rusty strap bolts or partial exhaust removal, brittle EVAP lines, and doing the pump/sender while the tank is out, which is the right time to do it.

**Chrysler 300 front bumper**, fog lights and park sensors, cover already painted:

| Task | Hr | $ |
|---|---|---|
| Remove damaged cover | 0.6 | 75.00 |
| Transfer fog lamps and harnesses | 0.5 | 62.50 |
| Transfer grille, trim, badges | 0.6 | 75.00 |
| Transfer and align park sensors | 0.7 | 87.50 |
| Install and align new cover | 0.7 | 87.50 |
| System check | 0.2 | 25.00 |
| | **3.3** | **412.50** |

Sensor calibration +0.5 hr takes it to $475; headlamp aim +0.3 hr adds $37.50. A pre-assembled bumper drops the job to 2.0–2.5 hr. Intake questions: year and generation, fog lights, sensors or radar, paint already done.

**Tire mount & balance**, customer-facing definition: *"removal of the old tire from the wheel, installation of the replacement tire onto the wheel, inflation to proper pressure, and balancing of the tire/wheel assembly with wheel weights."* It does **not** include the tire, valve stem or TPMS kit, disposal, repair, lug nuts, wheel repair, or taking the wheel off the vehicle. Removing the wheel is a separate operation — **Wheel Remove & Reinstall (R&I)**, including torquing lugs to spec. Sold together: "Tire Mount & Balance with Wheel Removal/Reinstallation."

### Case diagnostics

#### Engine and ignition

**Misfire — 2018 Thor Majestic 28A, Ford E-450, 6.8L Triton V10.** *Symptom:* P0302 (cyl 2), P0306 (cyl 6), P0300, all pending. Freeze frame: 196°F coolant, 1,621 RPM, 7 mph, 88.2% load, closed loop, 13.9V, **STFT B1 +23.4% / LTFT B1 +7%** against **STFT B2 −2.3% / LTFT B2 +2.3%**.

*Reading it:* Bank 1 strongly positive with Bank 2 normal looks like a Bank 1 vacuum leak until you check the layout — cyl 2 is Bank 1, cyl 6 is Bank 2, so a bank-wide leak explains neither pair. A misfire also dumps unburned oxygen into the exhaust, making the O2 sensor read lean, so +23.4% is as likely an *effect* as a cause. V10 layout: passenger side 1-2-3-4-5 front to rear, driver side 6-7-8-9-10; firing order 1-6-5-10-2-7-3-8-4-9. Ranked: coils 45% (COP failure is very common on the 6.8, heat-related, and a motorhome doghouse is an oven), plugs and plug wells 25%, Bank 1 leak plus weak ignition 15%, injector 10%, mechanical 5%.

*Tests:* flashing MIL, violent shake, or raw fuel smell means shut down except brief testing — hard misfires kill cats fast. Read misfire counters and Mode $06 first; the freeze frame rode on P0302, so cyl 2 leads. Inspect #2/#6 connectors, boots, springs, wells. Then the **coil swap test** — move #2 to #3 and #6 to #7, marked, complete coil-and-boot assemblies, clear codes, reproduce conditions: P0303/P0307 condemns the coils, codes staying put clears them. Read the plugs (wet = no spark or low compression, chalky white = lean, sooty = weak spark or rich, oily = oil control, steam-cleaned = coolant). Stethoscope injectors #2/#6 against neighbors. Trims at warm idle and ~2,500 RPM no load: B1 positive at idle improving at 2,500 = vacuum leak; both banks positive under load = fuel delivery; trims jumping only during the misfire = the misfire faking lean. Leak hunt on the passenger side (PCV, booster hose, manifold seams, MAF-to-throttle duct) with smoke, never flammable spray near a hot engine.

*Outcome:* don't throw ten coils at it. Kit: two known-good coils with boots and springs, correct plugs, dielectric grease, plug socket and torque wrench, DMM, Mode $06 scan tool. If swaps don't move the misfire, stop guessing — injector balance, relative compression, leak-down, smoke, or current-ramp testing.

**Throttle body confirmation — Lincoln LS** (3.0 V6 or 3.9 V8, drive-by-wire). *Symptom:* **P2104, P2111, P2112, P2135** with a crank/no-start. A bad throttle body genuinely causes crank/no-start here: the PCM withholds fuel when throttle angle is implausible, correlation fails, or the plate is stuck.

*Tests, mechanical → electrical → PCM logic:* (1) Key off, pull the intake duct, rotate the plate by hand — must move smoothly and snap fully closed; sticking, grit, or incomplete closing condemns it, stop there. (2) Carbon at plate edges and bore causes P2111/P2112 and the P2104 failsafe; clean with throttle body cleaner only, never forcing the plate, attempt idle relearn, keep testing if codes return. (3) **TPS correlation, the critical test** — two internal TPS sensors must mirror inversely, A rising smoothly as B falls. Backprobe, key on engine off, move the plate slowly. Voltage jumps, dead spots, or broken correlation give **P2135, which on this car almost always means internal throttle body failure**. (4) Actuator motor resistance across the motor pins, key off: **2–25 Ω**, stable; open, very high, or inconsistent fails. With a scan tool, command throttle and compare commanded vs actual — lag, no movement, or snapping into failsafe fails. (5) Harness sanity check before condemning: corrosion, spread terminals, oil intrusion (common on the LS), wiggle test while watching TPS voltage.

*Outcome:* clean wiring plus repeating correlation codes means the throttle body is guilty. **OEM/Motorcraft only** — aftermarket units cause repeat failures. After install, idle relearn and clear KAM with a 15–30 minute battery disconnect.

#### Transmission

**No/slipping reverse — 2004 Honda CR-V automatic, 256,000 miles.** *Symptom:* parked about a year *because* it was slipping and losing power; reverse now engages after 3–5 seconds and slips badly once engaged.

*Reading it:* a 3–5 second delay is low line pressure or a sticky valve, typically varnish from sitting. Reverse that engages then **slips** is a worn reverse clutch pack — it has its own pack, separate from the forward gears — internal wear, not fluid. With prior forward slipping, 256k, and a year parked, the transmission is worn out internally; fluid and solenoid service is a band-aid worth 10–30% at best, possibly zero. Fluid read: pink/red fine, dark brown worn, black with burnt smell near end of life, metallic glitter internal damage, milky water.

*Service facts:* Honda **ATF DW-1** (or older Z1) only — anything else causes clutch problems in Hondas. **Drain and fill, never flush.** Refill 3.3–3.5 qt, repeated two or three times over a week; check level engine idling, in Park, level ground. Drain plug takes a **3/8" square ratchet drive directly**, ~36 lb-ft (49 N·m), new crush washer. **No serviceable pan filter** — the internal screen needs teardown; the external inline filter (Honda **25430-PLR-003**, ~$20, 0.3 hr) is maintenance only. The **linear pressure control solenoid** bolts to the radiator side of the transmission below the intake tube and controls line pressure, exactly what makes reverse delayed and weak when varnished: **four 10mm bolts** (the nearby three-bolt block is the shift solenoid A/B assembly, a different part), one connector, clean the fine mesh screens with brake cleaner and never scrape, reinstall ~8–12 lb-ft. Gasket is **reusable** — rubberized molded-metal multi-port, replaced only if torn, cracked, swollen, or RTV'd (**28262-P7W-003**, $12–$18 dealer).

| Item | Qty/Time | Rate | Total |
|---|---|---|---|
| Honda ATF DW-1 | 4 qt | $12.50/qt | $50.00 |
| Brake cleaner / shop supplies | — | — | $8.00 |
| Labor: ATF drain & fill | 0.7 hr | $125/hr | $87.50 |
| Labor: remove & clean linear pressure solenoid | 0.9 hr | $125/hr | $112.50 |
| | | **Total** | **$258.00** |

*Outcome:* disclaimer states the service aims to restore hydraulic pressure and shift quality, that internal clutch wear is suspected at this mileage, and that it may improve drivability but **cannot reverse internal wear**. If slipping persists, the path is a used transmission (junkyard unit $300–$600, install $500–$900), cheaper than a rebuild on these. Automatics generally: 150k–250k miles.

#### Brakes and hydraulics

**Master cylinder failure during a flush.** *Symptom:* seized caliper freed, flush/bleed attempted. System never ran low, worked briefly, then stopped pushing fluid. Pedal to the floor with all bleeders closed, slight resistance that doesn't build with pumping, no visible leaks, no drop in reservoir level. Fluid extremely dark, and the customer saw it.

*Logic:* a pedal sinking with bleeders closed means the system isn't building or holding pressure — a blockage gives a **hard** pedal, not a sinking one. No firming with pumping plus zero fluid loss points at **master cylinder internal bypass**: manual bleeding strokes the pedal far past normal travel, dragging seals across a corroded, never-used section of bore.

*Field tests:* **static pressure hold** — bleeders closed, press and hold 20–30 s, watch the reservoir and inspect for leaks including inside the firewall at the pedal; sinking with fluid loss = external leak, sinking with no loss = master, pumping up then fading = air. **Master isolation test, definitive** — plug the master outlet ports with proper brake line plugs and press slowly; rock-hard pedal means the master is good and the fault is downstream (air, ABS, hose, caliper), a pedal that still sinks condemns it. **No plugs?** Crack one line at the master, helper presses slowly once, watch for a strong clean pulse, tighten *before* the pedal is released; weak dribble or bubbles means a failed or air-locked master. Short controlled strokes throughout.

*Outcome:* internal bypass in an already-fragile master. Replace matching what's on the vehicle, never converting — a big round black canister with a thick vacuum hose to the intake is a vacuum booster, a smaller unit with power steering pressure hoses is Hydro-Boost. Match ABS/non-ABS, bore size, reservoir shape and sensor plug, port count and locations. Bench bleed before installing. *Prevention:* never push the pedal to the floor when bleeding — short strokes, or a block of wood under the pedal. Prefer a pressure bleeder on old systems; a vacuum bleeder works but pulls air past the bleeder threads. Very dark fluid means a neglected system and a fragile master — warn **before** flushing.

#### Electrical

**CKP relearn.** A crankshaft position variation relearn teaches the PCM the crank's manufacturing tolerances for accurate misfire detection and timing. Required after CKP replacement on *some* vehicles, GM notably; symptoms are a CEL after replacement, misfire codes that won't clear, poor idle or hesitation. **2000 Jeep Grand Cherokee:** no GM-style scan-tool CASE relearn — the PCM learns automatically, so chase wiring, sensor quality, grounds, PCM power, or a damaged flexplate tone ring. **Toyota Camry:** most need no manual relearn either; "recalibration" talk usually means clearing learned values, an idle relearn after battery disconnect, or fixing an install issue (air gap, damaged reluctor, wiring).

**5-pin (SPDT) relay testing.** Pins: 85/86 coil, 30 common, 87a NC, 87 NO — schematic on the case. (1) Coil across 85–86: ~50–150 Ω normal; OL = open, near 0 Ω = shorted. (2) Unpowered: 30↔87a continuity yes, 30↔87 no; reversed = faulty. (3) Energized, 85 to ground and 86 to +12V: should click, then 30↔87 continuity yes and 30↔87a no. Clicks but doesn't switch = burned contacts; no click = dead coil. (4) On diode-protected relays polarity matters — 85 ground, 86 +12V; reversing blows the diode. **Fast field test:** swap with an identical relay elsewhere (horn, A/C) — if the problem moves, the relay is bad. Fuel pump logic: if jumping 30→87 runs the pump but the relay won't, it's the relay, a missing trigger, or a bad ground at 85.

**Key stuck in the ignition, engine won't shut off — 2015 Kia Soul.** Causes in order: steering-wheel lock engaged (most common — rock the wheel while turning the key, don't yank); shifter/park interlock not sensing Park (cycle to Neutral and back); worn or dirty cylinder or worn key (try the spare); ignition switch or shift-lock solenoid failure; anti-theft related, since Kia issued bulletins and the cylinder-protector campaign for some 2014–15 Souls — check the VIN with NHTSA or the dealer. Field sequence: Park and parking brake, rock the wheel while turning the key, spare key, cycle the shifter, **graphite lock lube only — never WD-40 or oil**. If the engine won't shut off, secure it and tow; do not disconnect the battery with the engine running.

#### Chassis and leaks

**Oil "everywhere" but no drip.** An **active leak** is fresh and forms drops; **seepage** is light wetness that never drips. Widespread oil with no drip point usually means old residue spread by airflow, several minor seep points combining (valve covers, oil pressure sensor, filter adapter gasket, pan corners, rear main), crankcase pressure from a restricted PCV, a past overfill, or misidentified transmission or power steering fluid. *Test:* oil travels down and back, so **the highest wet point is the source** — degrease, drive 20–50 miles, reinspect; dye test for a definitive answer. *Outcome:* clean-and-monitor rather than a speculative gasket job.

**Clicking from a front wheel while turning.** Clicking or popping only while turning, louder one direction, worse accelerating through the turn, often with grease flung inside the wheel from a torn boot. *Test:* slow full-lock circles each way — **louder turning left means the right axle is bad**, and vice versa. *Root cause:* almost always the outer CV joint. *Differentials:* wheel bearing growls or hums with speed; tie rod end clicks or clunks with steering play; ball joint clunks over bumps; strut mount gives a notchy low-speed click.

### Customer-facing scripts and policies

**Master cylinder — the conversation.** The flush *triggered* the failure; it did not damage a healthy system. The line is *"the flush exposed the weakness; it did not create the failure from nothing."* Own the outcome without confessing to a certainty that isn't there. Do **not** say "good thing it failed now" — say *"it failed under controlled service conditions instead of during normal driving."* Fair position: the customer pays for the part, since the component was already weak, and some labor and diagnostic time is discounted or waived because it happened during service. Document everything — dark fluid observed by the customer, no leaks found, no level drop, pedal won't build with bleeders closed, failure occurred during the flush, vehicle unsafe to drive. **Never release it drivable.**

**Vendor receipts — never handed over.** Supplier receipts expose wholesale pricing and account information. The customer gets a fully itemized invoice instead: parts, warranty, price paid, labor, fees. Policy line: *"White Knight Roadside LLC does not provide internal procurement records or supplier receipts. All customer-facing documentation will be provided in the form of an itemized invoice detailing billable parts and services."* If a customer insists, the legitimate options are supplying their own parts (labor-only, no parts warranty) or shopping around **before** approving the work.

**Oil seepage — liability framing.** Write it up as *"no active leak observed; multiple minor seepage points consistent with age,"* recommend clean-and-monitor with reinspection after a stated mileage, note the dye test as optional. That write-up prevents the "you missed my leak" callback three months later.

### Checklists

**Roadside battery install.** *Before leaving:* correct group size, CCA meeting or exceeding OEM, **terminal orientation** (the common mistake), radio anti-theft codes, memory saver if needed. *On arrival, 2–3 min:* verify the complaint (slow crank, no crank, click), check corrosion, cables — especially the ground — and the belt; if fully dead, jump it first to confirm it's the battery and not the starter or a connection. *Removal:* key off, negative first, then positive, hold-down, lift straight out; heavy corrosion gets baking soda and water, wire brush, clean tray. *Install:* seat fully, hold-down (don't skip), positive first, then negative, terminal protectant; clamps tight enough you can't twist them by hand. *Post-install:* strong crank, charging 13.5–14.7V running, reset clock and windows, clear low-voltage codes. *Gotchas:* Ford side-post stripping, GM battery current sensor damage, BMW and Euro IBS sensors, battery registration required on BMW/Audi/Mercedes, parasitic drain masquerading as a bad battery.

**Commercial tire service — 245/70R19.5.** The 19.5" wheel is the tell: Class 4–6 medium-duty — Isuzu NPR/NQR/NRR, Chevy/GMC 4500-6500HD, Ford F-450/F-550 cab and chassis, Ram 4500/5500, Hino 155/195, Freightliner M2 light spec. Box trucks, flatbeds, tow trucks, RV chassis; never a half-, three-quarter-, or one-ton pickup. Price and treat it as **commercial service**, not light-duty roadside: heavy stiff Load Range G/H tires, harder mounts, higher liability, and some roadside programs exclude 19.5" entirely. On duals, confirm inner vs outer before quoting.

### Equipment: inverter-powered air compressor

The compressor is 120V, 7.1A running (≈852W), 1.2 HP, 135 PSI, 2-gallon, oil-free. Motor surge runs 2–4× running current, so the design target is **850–900W continuous and 2,000–3,000W surge** — after inverter losses, **80–90A off the 12V system**. A 130–180A alternator handles it; the compressor eats roughly half a 160A alternator's output while running.

*Specification:* a **2,000W pure-sine inverter with ≥4,000W surge**, engine running before the compressor is switched on, compressor plugged directly into the inverter — any long cord goes on the 120V side, 12-gauge minimum.

*Rear-mount install, 2000 Grand Cherokee*, ~10 ft run / 20 ft round trip: **2/0 AWG stranded copper welding cable on both legs** (1/0 absolute minimum); a **250A Class-T or ANL fuse within ~12" of the battery positive**; a disconnect or breaker near the inverter; a **dedicated 2/0 negative run back to the battery** rather than trusting the unibody, plus a case-bond strap. Route away from exhaust, sharp edges, driveline, fuel and brake lines; grommets at pass-throughs, adhesive-lined heat-shrink lugs. Failure mode: undersized cable causes voltage drop and the inverter trips on compressor startup even when the wattage rating looks adequate.

*Usage:* engine on, other big loads off, hold **1,200–1,500 RPM** for big or repeated fills, since alternators make little current at idle. Upgrade battery or alternator only if lights dim, running voltage sags below ~12.5V, or the inverter trips. *High-idle solution (2000 WJ 4.0L):* a locking universal choke/throttle cable on the throttle-body linkage as a manual high idle, ~1,200–1,500 RPM max — strong return spring, must not bind the factory throttle or cruise cables, released before driving.

### Oregon tow-dolly requirements

Towing **for any compensation** — dolly included, since the law targets the activity rather than the equipment — requires a **DMV Tow Business Certificate**. Operating without one is an illegal towing business: a **Class A misdemeanor** (up to 364 days, $6,250 fine) plus **civil penalties up to $25,000 per violation** from the State Board of Towing, under ORS 822.200/822.995. Exempt: towing only your own vehicles, uncompensated Good Samaritan help, vehicle transporter certificate holders, dismantlers under ORS 819.280. False statements on the certification are false swearing, a Class C felony (ORS 822.605).

**TW plates** on each tow vehicle, non-transferable, removed when a vehicle is sold or retired. **Title, registration, and tow certificate must all be in the same name**; the certificate renews annually. **Insurance:** motor-carrier liability at a **$750,000 single limit** (OAR 740-040-0020) **plus $50,000 cargo** (ORS 822.205), Oregon-licensed insurer, DMV notified on cancellation, certificate of insurance listing the VIN.

**Equipment standards — caveat.** The statute requires compliance with DMV/ODOT "minimum safety standards" generally. A granular equipment checklist (fire extinguisher specs, tread depths, and the like) was produced during research but **was never found in the source document and was retracted when sources were requested**. Treat any specific equipment checklist as **unverified** until confirmed against the actual DMV/ODOT standard.

**Path for a dolly with no VIN and no bill of sale:** (1) Establish ownership via **Affidavit of Ownership, Form 735-550**, or **Homemade Trailer Certification, Form 735-230**; a bonded title if ownership is doubtful. (2) Apply for an **Assigned VIN, Form 735-226, $98 one-time** — DMV or State Police inspection, then a state VIN plate is affixed; bring photos, serial markings, possibly a weight slip. (3) Title and register the dolly. (4) Apply for the **Tow Business Certificate, Form 387**, and TW plates. DMV cannot issue plates or the certificate until the dolly has a VIN and is titled in Jason's name.

**Fees** (DMV Handbook Ch. O, 2024): certificate **$117 per vehicle per year** including the $100 State Board of Towing fee effective 1/1/2024; TW plate issuance ~$24–$30; registration by tow vehicle weight, light vehicles ~$126–$152 biennial; Assigned VIN $98 one-time. **Year one ≈ $310–$390, ongoing ≈ $180–$195/yr.** Combined weight over 26,000 lbs triggers weight-mile tax and ODOT CCD registration; local fees possible under ORS 822.230.

### The LLM diagnostic-agent prompt

A copy-paste system prompt for a mobile-roadside diagnostic assistant, proven in practice on the master cylinder case above.

- **Identity.** A diagnostic assistant for a mobile mechanic away from a full shop — no lift, limited scan tools. Framing sentence: *"You are NOT here to guess parts... narrow the fault using symptoms, simple tests, and evidence."*
- **Primary goals.** Identify the failing system; is it safe to drive; is it field-diagnosable and field-repairable; next best test; what must be ruled out before replacing parts; when to stop, tow, or refer.
- **Field-service mindset.** Safest, simplest, lowest-risk test first. No unnecessary disassembly. Never make the vehicle less movable than it already is. Say clearly when a job stops being field-friendly.
- **Field Constraints First.** The section added for roadside reality, and what separates this from a generic diagnostic bot. Before recommending lifting, crawling under, bleeding, disassembly, or a road test, it must ask: roadside or in traffic? Flat, stable ground? Light and room to work? Slope, gravel, soft ground? Weather? Can it be chocked and secured? Will a failed test strand it? If any of that reads unsafe, the answer is tow or reschedule — not a test procedure.
- **Response template.** What we know → Safety call (safe to drive / safe to continue / main risk) → What this points toward → Three most likely causes, each with why it fits, why it might not, how to test → First field test (test, tools, steps, good result, bad result, what each means) → Next branch as if/then, including stop-and-tow → **Do not replace yet** → Field-service decision (drive? continue? field repair? tow?).
- **Running state between messages.** Confirmed facts, ruled out, still possible, most likely now, next best test — updated each turn, not restarted.
- **Tone.** Direct, mechanic-friendly, no fake certainty, minimum necessary questions.

---

## Interface design

### The settled aesthetic

The app is a dark command centre — not a dark theme applied to a light layout, but a dark room with lit instruments in it, built for an operator reading status at a glance while a customer is on the phone and a technician is on the shoulder of a highway. Surfaces are navy-cast near-black, never pure `#000`; the chrome is navy; the only saturated colour on screen carries meaning. That last rule took the longest to settle and is worth defending hardest: **navy is the brand, and amber, green, red, cyan and purple are status signals only.** Amber cannot be both the primary button and "en route" — the moment it is both, colour stops carrying information and the interface reverts to being read word by word. Earlier explorations using amber, purple or electric cyan as the brand accent are superseded.

Depth is the second pillar and it is structural, not decorative. Every panel is *lighter* than the page behind it and lifts off with three things stacked: a one-pixel inset top highlight (the bevel), a wide soft drop shadow, and a hairline border. Buttons are physically raised objects — they push down on `:active` with a downward translate and an inner shadow. The background carries a fixed, viewport-sized texture layer (two brand glows in the top corners, a fine blueprint grid, an edge vignette, a low-opacity film grain) so large dark areas have tooth instead of reading as flat black; because that layer is `position:fixed` it neither scrolls nor repaints when a view is swapped in. Panels use a *different* material from the backdrop: a fine diagonal hatch, like brushed metal, with a soft sheen on the top-left corner. Two clearly distinguished materials is what makes the layering read.

Glow is rationed: exactly one glowing element per screen — the dominant primary action — plus the focus ring on the focused input and the small cyan ticks marking section headers. Nothing else. Glow on everything is noise, and it is what got two earlier mockups rejected outright. Motion is equally rationed: 120ms and 190ms on a single easing curve, a two-pixel progress line during navigation, a slide-in for toasts, a small pop for modals, `prefers-reduced-motion` honoured. No parallax, no shimmer sweeps, no cards that drift when the cursor passes over them.

### Colour tokens

Everything is CSS custom properties on `:root` in a single hand-written stylesheet. There is no build step and no preprocessor, so the variables *are* the design system — there is no other layer where a token could hide. Assets are cache-busted by an `asset()` helper that appends `filemtime`, so a stylesheet edit is live on the next request with no pipeline to run.

| Token | Value | Role |
|---|---|---|
| `--bg` | `#070b14` | Page. Navy-cast near-black. |
| `--surface-1` | `#0c1220` | Panels. |
| `--surface-2` | `#0a0f1b` | Nested cards, input wells. |
| `--surface-3` | `#111a2c` | Hover / raised. |
| `--sidebar` | `#080d18` | Sidebar base (gradient `#0a1120 → #070c16`). |
| `--brand` | `#2c5cff` | Navy blue. Chrome, nav, primary buttons, focus, brand mark. |
| `--brand-deep` | `#1b3ca8` | Bottom of every brand gradient. |
| `--brand-soft` | `rgba(44,92,255,.14)` | Brand wash behind KPI tiles. |
| `--glow-a` | `#5ee6ff` | Interaction glow / focus ring / section ticks. |
| `--glow-b` | `#8d7bff` | Second glow stop on the primary action only. |
| `--text` | `#e8eef8` | Body text. |
| `--text-dim` | `#93a3bd` | Labels, secondary. |
| `--text-faint` | `#64748b` | Meta, timestamps, hints. |
| `--ok` | `#15c47e` | Status: complete, paid, available. |
| `--info` | `#38b6ff` | Status: dispatched, sent. |
| `--warn` | `#f5a524` | Status: en route, needs attention, low stock. |
| `--danger` | `#f4436c` | Status: overdue, declined, error, blocked. |
| `--accentpill` | `#a78bfa` | Status: SMS / messaging / AI-assisted. |
| `--slate` | `#7c8ba5` | Status: draft, inactive, neutral. |
| `--line` / `--line-strong` | `rgba(255,255,255,.07)` / `.13` | Hairlines and borders. |

Signal colours are only ever used at low alpha as a pill background, as a border, or as text on a pill — never as a filled control. Status pills are uppercase, tracked, pill-radius, with a 6px dot that carries `box-shadow: 0 0 8px currentColor` so the dot glows in its own colour without the pill shouting.

### Typography, space, depth

| Group | Tokens |
|---|---|
| Fonts | `--font: system-ui, -apple-system, "Segoe UI", Roboto, Inter, Arial, sans-serif` · `--mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` |
| Sizes | `--fs-xs 10.5px` · `--fs-sm 12px` · `--fs-md 13.5px` (body) · `--fs-lg 16px` · `--fs-xl 20px` · `--fs-2xl 27px` |
| Space | `--s1 4` · `--s2 8` · `--s3 12` · `--s4 16` · `--s5 20` · `--s6 24` · `--s7 32` · `--s8 44` |
| Radii | `--r-xs 6` · `--r-sm 9` · `--r-md 12` · `--r-lg 14` · `--r-xl 20` |
| Depth | `--shadow: 0 12px 30px rgba(0,0,0,.45)` · `--shadow-sm: 0 4px 14px rgba(0,0,0,.35)` · `--inset-top: inset 0 1px 0 rgba(255,255,255,.055)` |
| Primary glow | `--glow-primary: 0 0 0 1px rgba(94,230,255,.22), 0 6px 26px rgba(44,92,255,.38), 0 0 42px rgba(141,123,255,.18)` |
| Focus | `--focus: 0 0 0 2px rgba(94,230,255,.55), 0 0 18px rgba(94,230,255,.28)` |
| Motion | `--ease: cubic-bezier(.2,.8,.2,1)` · `--fast 120ms` · `--med 190ms` |
| Shell | `--sidebar-w: 258px` |

No web fonts are loaded. The system stack renders instantly and looks correct on the Windows machine the app is actually used on; a second network request for a font is a cost with no return here. **Monospace is mandatory on every identifier**: document numbers, VINs, plates, phone numbers, money in tables, ETAs. A document number in a proportional font is a number that gets misread.

Headers are marked, not just sized. Every panel head, sidebar group label and KPI label carries a small vertical tick — a 3–4px bar with a `--glow-a → --brand` gradient and a soft cyan shadow. Panel titles are uppercase with `.1em` tracking on a solid, distinctly lighter band with a 2px brand bottom border. Section labels are `--fs-xs`, uppercase, `.14–.16em` tracking, `--text-dim`. The result is that a screen's structure is legible from across the room before a single word is read.

### Inputs

| Property | Value |
|---|---|
| Background | `--surface-2` (`#0a0f1b`) — recessed, darker than the panel it sits in |
| Border | `1px solid var(--line-strong)` → `rgba(94,230,255,.5)` on focus |
| Inner shadow | `inset 0 1px 3px rgba(0,0,0,.45)` — the well |
| Focus | `outline:none` + `--focus` (2px cyan ring plus bloom) |
| Radius / padding | `--r-sm` (9px) / `9px 12px` |
| Text | `--fs-md`, `--text`; placeholder `#4d5b73` |
| Label | `--fs-xs`, 700, uppercase, `.13em` tracking, `--text-dim`; required adds a `--danger` asterisk |
| Invalid | `.is-bad` — `--danger` border plus a `.hint--bad` line underneath |

Inputs are wells and buttons are raised. That inversion is the whole trick: it tells you where to type and where to press without a single instruction. Radio groups render as `radio-card` chips that fill with a navy gradient when checked rather than as native radios. Fieldset legends are `--glow-a`, uppercase, tracked. Phone inputs carry a live mask that renders `(xxx) xxx-xxxx` as you type and stores E.164; VIN inputs validate 17 characters with no I/O/Q and colour the field as you go. Validation is on blur or on completion, never per keystroke — a field that turns red on the second character is a field that punishes typing.

### Navigation

The sidebar is a hard requirement and it is always visible at 258px. It is not a list of links; it is a column of **buttons** that read as physical objects: a `#2c3f66 → #1c2c49` gradient distinctly lighter than the sidebar behind them, a light border, an inset top highlight, a drop shadow. On hover they shift 3px right and pick up a cyan border; on `:active` they push down 1.5px with an inner shadow; when active they take a navy-tinted fill. Each carries a 16px monoline SVG icon (inline, `currentColor`, consistent 1.7 stroke) and an optional right-aligned count pill.

Grouping is by what the operator is doing, not by table name. The live grouping is **The chain** (Dashboard, Service Requests, Estimates, Work Orders, Invoices, Payments) → **Money** (Expenses, Reports) → **Records** (Customers, Vehicles, Products & Services, Messages) → **Admin** (Settings, Markup matrix, Users — admin only). A technician sees a single **Field** group with four items. The counts on the chain items are live queue depths — pending requests, unauthorized estimates, open work orders, unpaid invoices — so the sidebar doubles as a workload readout. Above the groups sit the brand block ("WK" tile, White Knight, ROADSIDE · ADMIN) and one full-width primary button: **New Service Request**.

Navigation is a PJAX-style content swap. Clicking a nav button or a table row fetches the target, replaces the content region and the sidebar's active/count state, and pushes history; back and forward re-fetch the same way. The sidebar element itself is never re-rendered, so it does not flicker, lose scroll position, or re-animate. The only motion is a 2px progress line across the top and a 180ms opacity fade on the incoming content. Per-page JS is re-initialised on every swap through a single `initPage(root)`; delegated handlers are bound once at the document level and never rebound. This is the point where a double-bind bug is easiest to introduce and hardest to notice — the discipline of "delegated handlers bound once, per-page handlers re-run per swap" is what keeps it out.

**Navigation is not actions.** The sidebar goes places; the page header holds the actions for the record you are looking at. The top bar carries an uppercase breadcrumb line, the page title, the page's action buttons, and the signed-in user.

### The document chain

The six-document chain — Service Request → Estimate → Work Order → Invoice → Payment → Receipt — is rendered as a live breadcrumb at the top of every document detail screen, with each step in one of three states: done (green, with a check), current (white on a navy fill with a soft glow), pending (faint). On the estimate the steps are Drafted → Priced → Authorized → Dispatched → Invoiced; the work order and service request each render their own stage list the same way. This is the single most valuable piece of chrome in the app. It answers "where am I in this job and what has already happened" before the user reads anything else, and it makes the linked document numbers explicit rather than something the operator has to remember. Document numbers are `PREFIX-YYYYMMDD-###`, monospace, and clickable through to the neighbouring document.

### Rules for data-heavy screens

These are the working doctrine, stated so they survive any restyling.

1. **Start with the job, not the data.** Every screen answers "what needs attention and what is next", not "here are all the columns".
2. **Hierarchy in one order:** object identity → status → primary action → key summary → details. Everything at the same visual weight forces reading instead of scanning.
3. **Zones, not soup.** Header (identity, status, actions) · summary · main work zone · support column (timeline, notes, attachments) · footer actions. The two-column `split` (1.65fr / 1fr, collapsing under 1050px) is the default detail layout.
4. **One primary action per screen.** It is the only glowing element. On an estimate it is "Record authorization"; once authorized it becomes "Dispatch to a technician"; once dispatched, "Open work order". If a screen has two obvious next steps, the screen is doing two jobs.
5. **Tables are work queues, not database dumps.** Status is the *first* column, as a pill. Rows are clickable through to the record. Money is right-aligned with tabular numerals; text is left-aligned; nothing is centred. Headers are uppercase, tracked, faint.
6. **The next action must be on the row or in the queue.** A dedicated "Next action" column is the general form of this rule; the app's realisation of it is queue panels — Intake queue, In the field, Awaiting authorization, Ready to invoice, Unpaid invoices — where membership of the list *is* the next action. Either is acceptable; a list that does not tell you what to do next is not.
7. **Progressive disclosure.** Show the major events; expand for detail. Conditional sections appear only when the choice that needs them is made — provider fields when the source is a provider, destination when the service is a tow, company fields when the customer is commercial or fleet.
8. **Summary before details.** Totals above line items. The variance figure next to the gate that depends on it.
9. **Blockers are impossible to miss and sit next to the thing they block.** The dashed-border `gate` alert states what is wrong, why it matters and how to clear it; the button it blocks is disabled with the reason in its tooltip. VIN missing, authorization missing, variance over tolerance — all render the same way.
10. **Empty states teach the workflow.** "No invoices yet. Create one after the work order is complete." plus the button, not a shrug.
11. **Same badge, same money format, same date format, everywhere.** Formatting lives in helpers, not in views.
12. **Five-second scan test.** In five seconds a user must be able to say what record this is, what state it is in, the two or three key facts, what is blocked, and what to do next. If they cannot, the screen is either too noisy or too flat.
13. **Glove-friendly targets.** This is used one-handed, in a truck, in the cold. Nav buttons are 11–12px vertical padding at full width; primary buttons are 9×15px minimum and usually full-width in modals; touch targets never shrink below the button baseline. Masks, dropdowns and sensible defaults instead of free typing wherever the data allows it.
14. **Print is a first-class view.** A print stylesheet strips the sidebar, top bar, background layers and toasts, flips to white, and renders panels and badges as flat bordered blocks. Estimates, invoices and receipts print correctly with no separate template.

### Interaction patterns

**Toasts** confirm every meaningful action: a fixed stack at top right, slide-in over 280ms, coloured by kind (ok / warn / error / info) from the status palette. Nothing succeeds silently.

**Modals** carry the consequential: recording an authorization, dispatching, capturing a re-authorization, adding a markup tier. Backdrop blur, small pop-in, `Escape` and backdrop click both close, first field auto-focused. **Confirmation** for anything irreversible is a `data-confirm` attribute on the control carrying the exact sentence the user must agree with — the wording lives next to the action, not in a shared "Are you sure?" dialog.

**A command palette** is a keeper idea, not yet built: one keystroke to jump to a job, customer, VIN or plate, and to fire actions like "new service request" or "take payment". It fits the stack without ceremony — a fixed overlay, an input, and a server endpoint returning JSON matches, in the same vanilla JS as the customer typeahead already in the app. It matters precisely because the sidebar deliberately does *not* grow to hold everything.

**Anticipation and micro-reward** survive as principles at low dosage. The stage timeline that advances and pulses at each milestone is the honest version: progress the user can feel. Loading copy that names what is happening ("preparing the invoice") beats a bare spinner. A brief, quiet acknowledgement at genuine completion — a job closed, an invoice paid — is fine. Constant stimulation, confetti on routine saves and gamification that competes with the data are not. The interface should feel good because it is fast and legible; the reward layer must never be why a screen reads slower.

### Key screens

**Intake (new service request).** Two columns. The left is a stack of panels in call order, each with a subtitle stating that panel's epistemic status in plain language: how it came in (channel, job source, provider account, claim ref) → who said they need help, as reported and unverified → what they say is wrong → where they are → priority and promised ETA. The right is a live summary. The framing matters as much as the layout: a service request records that somebody asked for help. It creates no customer and no vehicle, carries no prices, and nothing downstream reads it as fact. The form is therefore forgiving — almost nothing is required — and the copy says so, which stops dispatchers stalling a live call to get fields perfect. Reported vehicle details, location capture (browser geolocation with a manual address fallback that never dead-ends on denied permission) and free-text notes live here.

**Estimate and approval.** Chain breadcrumb, then an identity row: document number, status pill, source request, customer, service type, address; on the right, Print/PDF, Send to customer, and the one primary action for the current state. Below, the shared line editor — catalog picker only, no free typing, each line snapshotting cost, markup, suggested price and final price — with totals above the lines and the scope disclaimer under them. Above $200 the authorization gate renders: a dashed alert stating the threshold, and a modal with printed name, method, terms version and the signature pad. Once authorized the estimate locks and the alert flips to a green record of who signed, when, by what method, from what IP.

**Dispatch.** There is no separate board; the dashboard is the board. Queue panels — Intake queue, In the field, Awaiting authorization, Ready to invoice, Unpaid invoices — each a table with status first, then record, customer, technician. The work order index is the same shape with an outcome column. Anything overdue or unassigned reads through the pill, not through row decoration. The durable idea from earlier explorations — status drives a left-border accent on the row, overdue jobs pulse — is compatible and can be added as a row class without touching anything else, provided the pulse respects reduced motion.

**Invoice.** Totals and balance at the top, lines below, payment and variance panels to the side. Two gates are visible: the vehicle gate (no issue without a linked vehicle carrying a valid VIN, unless every line is vehicle-not-required and a reason is recorded) and the variance gate (re-authorization when invoice-versus-estimate exceeds the *lesser* of $200 and 10%). Both render as gate alerts with the figure spelled out and the blocked button disabled, reason in its tooltip. Card payment is a hosted checkout link — "the customer pays from wherever they are" — never a terminal.

**Signature capture.** A 150px canvas with a dashed border on a dark well, `touch-action:none`, drawn with mouse or finger, serialised to a PNG data URL into a hidden input on every stroke end, cleared by a button, refused on submit if untouched. The same component appears in three places — estimate authorization, work order, invoice re-authorization — alongside printed name, terms version, timestamp and IP.

### The mark

The brand is a white knight — chess knight or knight's helm — rendered in blue and white neon against a dark ground, with a **wrench crossed behind it** so that it reads "mechanic" and not "chess club" at a glance. "WHITE KNIGHT" in bright blue neon, "ROADSIDE" in clean white below. In the app the mark is reduced to its working form: a 34px rounded tile with a `--brand → --brand-deep` gradient, an inset top highlight and a navy drop shadow, carrying the letters "WK"; the same tile is the favicon. Usage rules: navy or white only; no signal colours in the mark, ever; no glow on the mark inside the application (the neon treatment belongs to marketing artwork, not to chrome); monoline SVG icons everywhere else at a consistent stroke weight, never emoji, never a second icon language.

### Rejected, and why

**Emoji icons** — cheap-looking, inconsistent across platform renderings, no stroke discipline. Replaced by inline monoline SVG inheriting `currentColor`.

**Cards that move on hover** — lift, tilt, magnetic pull toward the cursor. Entertaining in a demo; in a work queue it makes rows feel unstable and slows scanning. Hover is a background tint and nothing else. Buttons still push on press, because that is feedback for an action the user took, not decoration reacting to a passing cursor.

**Decorative glow** — glow on cards, borders, every panel, hover rings around everything. Glow is a hierarchy tool: the one primary action, the focused input, the section ticks, the status dots. Once everything glows, nothing does.

**Amber or purple as the brand colour** — resolved in favour of navy. A signal colour used as chrome destroys the signal.

**Noisy premium** — loud gradients, "gamer glow", heavy glassmorphism, animated everything. The target is an expensive dispatch console: high contrast, restrained palette, real depth, quiet motion. If a screenshot looks impressive but the operator has to hunt for the next action, it failed.

**Light-theme, Bootstrap-flavoured token sets** — abandoned. The token *structure* (colour / type / space / radius / shadow at `:root`) survived and is what the current stylesheet uses; the palette did not.

**Splash screens, easter eggs, ambient parallax, gamification badges** — fun, none adopted. A tool opened forty times a day should open instantly.

---

## Working with coding agents

### The durable rules

**Change only what was asked.** This is the first rule because every expensive incident traced back to violating it: a regenerated project that silently dropped the sidebar, a rewrite that lost an effect which had taken an afternoon to get right, a "cleanup" that reformatted files nobody asked about. The instruction that works is blunt and repeated verbatim: *only change what I ask to be changed; verify before and after that you have not changed anything you were not asked to.*

**Minimal change surface.** The smallest correct change that satisfies the requirement, with no drive-by refactors, no renames, no reformatting, no "while I was in there". If a change genuinely needs to cross a layer, the agent proposes the diff first and waits.

**Scope to one module.** "Work on the estimate pages and only the estimate pages. Do not touch anything else without asking." Name the exact files that may be edited. A smaller context wanders less.

**Verify claims; self-reports are unreliable.** An agent's confident checklist is not evidence. Deliverables that shipped "complete" while missing components are the reason verification is externalised into things that can fail loudly: the test suite, `php -l`, and opening the actual page in the actual browser on the actual machine.

**Patch, never rebuild.** When something is broken, ask for the specific file and the specific replacement block — "here is the current code, here is the one line that changes, that is the only change." Full regeneration of a working area is how working areas stop working. This is doubly true in a codebase with no build step, where a hand-written stylesheet and a hand-rolled front controller carry a lot of accumulated judgement per line.

**Full files, no placeholders.** When an agent does return a file, it returns the whole file. `// ...rest of file unchanged...` has cost more time than it has ever saved.

### Maintaining the rules files

The repo already carries its rules; the job now is upkeep, not authoring.

- **`AGENTS.md`** is the conventions file: the shape of the codebase (hand-rolled MVC, no framework, no Composer, no build step, explicit `require`, every route in one readable list in `public/index.php`), the hard rules, how to write PHP and views here, how to touch integrations and webhooks, how to add a document type, how schema changes work, how to test, and what to check before deploying. It is prose, it is short, and it is deliberately opinionated — it explains *why* a rule exists, which is what makes an agent comply rather than route around it.
- **`PROJECT_INSTRUCTIONS.md`** is the one-page brief: stack, how to run it, the chain, the architecture map, the integration defaults, the feature currently in flight, and the working style. This is the file to hand an agent first; it is sized for a context window.
- **`docs/`** holds the reasoning: `BUSINESS_RULES.md` (every hard rule, in one place, with its numbers), `DECISIONS.md` (what was chosen, what it was chosen over, and why — including the deferred list, recorded so gaps are not mistaken for oversights), `INTEGRATIONS.md` (drivers, webhooks, credentials, logging).

Upkeep rules that keep them from rotting: update them **in the same change** as the behaviour they describe — a stale instruction is worse than no instruction, because an agent will follow it. Never duplicate a rule across two files; state it once and point at it ("for the numbering scheme, see `docs/BUSINESS_RULES.md`"). Keep `AGENTS.md` scannable — when a topic grows past a paragraph, it moves to `docs/` and gets a pointer. When a decision reverses, edit `DECISIONS.md` to say so rather than leaving both versions standing; the whole value of that file is that it is the tiebreaker.

The one non-negotiable that belongs at the top of any agent brief: **the hard rules live in `Rules` in `app/Domain.php`, and nowhere else.** Thresholds are never re-checked inline, views never read config, gates are never reimplemented in a controller. A rule that exists in two places is a rule that will be wrong in one of them. A PR checklist enforces the rest — rule enforced in one place, `php -l` clean, tests pass, every value escaped with `e()`, all SQL parameterised, CSRF field present, docs updated, one primary action per screen, navy for chrome and signals for status.

### Protecting the interface

The stylesheet and the shell are the easiest things for an agent to damage and the hardest damage to notice.

- **Protected files.** `public/assets/css/app.css`, `public/assets/js/app.js`, `app/Views/layouts/app.php` and `app/Views/partials/sidebar.php` are not edited as a side effect of anything. A task that needs a new component adds it at the end of the stylesheet in the correct section; it does not rewrite the token block.
- **Tokens are the single source of truth.** No hard-coded hex values in new rules. If a colour is needed that does not exist, that is a design decision to make deliberately, not a literal to paste inline.
- **Golden screens.** `est_show.php` and `inv_show.php` are the reference implementations — chain breadcrumb, identity row, one primary action, gate alerts, shared line editor, side summary. New screens follow them. "Follow this pattern, do not modify it" is a useful thing to say out loud in a prompt.
- **DO-NOT-EDIT fences.** A comment block above a section that took real effort — the background texture layers, the scrollbar treatment, the PJAX swap — stating what it does, why it is fragile, and that it changes only on explicit request. The existing comments in the stylesheet and in `app.js` already do this; keep writing them that way.
- **Negative prompts.** Agents respond well to boring, specific prohibitions. *Do not change CSS variables. Do not move or rename elements. Do not touch the sidebar partial. Do not add a library. Do not add a build step. Do not reformat.*
- **Separate the sessions.** A "logic only" pass and a "visual polish" pass, never the same pass. Mixed sessions produce diffs that cannot be reviewed, because a genuine fix and an unasked-for restyle look identical inside a large patch.
- **Review the diff like a junior developer's.** Small changes, reviewed. If the diff is too big to read, that is the finding.

### Prompt scaffolds that work

**The simplicity-first prompt.** Every attempt at a longer version was worse. This is the one that stuck, and simplicity sits at the very top so the agent never drifts into architect mode:

> You are building production code. Always choose the simplest working solution. Avoid cleverness, abstractions, and over-engineering. Keep file structure shallow and obvious. Make small, safe changes only. Use clear names and short functions. For every task: state goal → short plan → files changed → code → how to test. If unsure, pick the simplest option.

**The guardrail meta-prompt.** Bolted onto any build task: *do not invent business rules.* If the rule is not in `docs/BUSINESS_RULES.md`, not in `Rules`, and not in the task, stop and ask — do not infer a threshold, a status name, a numbering format or a tax behaviour from context. Along with it: no stubs in core flows, no premature refactoring, no new dependencies, and when uncertain choose the option that reduces future complexity. Inventing plausible rules is the single most damaging failure mode in a domain where the rules are money.

**The living TODO.** One structured list is the source of truth for work in flight. Columns: Backlog / Next (max one) / In Progress (max one) / Testing / Done. Each item carries an ID, a one-sentence goal, a three-to-seven step checklist and a test plan. It is updated before starting, after each change, after each test run, on any scope change, and at completion. Work that is not on the list does not exist. Every agent turn ends with: the updated TODO, one to three bullets on what changed, the exact commands used to test, and what is next. This discipline is what makes a long session recoverable — it is the only artefact that survives a context reset intact.

**Task framing.** Objective, acceptance criteria, the exact files that may be touched, the business rules that apply, and a behavioural example or two. Ask for the plan before the code. A plan is cheap to reject; a patch is not.

**Vertical slices.** Build one screen with one real data path and one working action, end to end, before generalising. Extract on the second duplication, not the first. Comment the *why*, not the what. Commit at every point where something feels right, with three to five bullets describing what that version does — those bullets are the seed of the next doc.

### Testing and enforcement

The app carries its own proof, which is the practical answer to unverifiable agent claims:

- **`tests/markup.php`** — pure unit tests for the pricing maths. No database, no server. Tiers are passed in explicitly so the arithmetic is tested in isolation from whatever matrix happens to be stored. Integer-cents exact; the boundary rule (a cost sitting on a tier's max belongs to the lower tier) is asserted directly.
- **`tests/pricing_integration.php`** — the same pricing against a real database.
- **`tests/e2e.sh`** — a curl-driven walk of the entire chain against a live server, asserting against the database, resetting first, and running through the application's own config so it proves behaviour on whichever engine is configured. Every hard gate has an assertion. **A gate without a test is a gate that will be removed by accident**, so the rule is: extend `e2e.sh` in the same change that adds the gate.
- **`php -l` before shipping.** Cheap, and it catches the class of error an agent makes most often in a codebase with no compile step.

The self-testing-page pattern is the extension of this that is still worth building: a diagnostics page that programmatically exercises the interactive pieces — phone mask, VIN validation, customer typeahead, signature pad, modal open/close, PJAX swap and re-init — against hidden fixture markup and prints green OK or red FAIL per component; and a kitchen-sink page rendering every component in every state on one screen, which must render with no console errors. Both make the app prove itself in the browser and, when something breaks, tell the agent exactly which line is red so it can patch that one thing instead of rebuilding. The double-init bug — handlers binding twice after a content swap, everything feeling subtly broken — is precisely the class of failure only a diagnostics page catches.

### Ops lessons that still apply

**XAMPP's PHP is not on `PATH`.** If `where php` returns nothing, call it explicitly — `& "C:\xampp\php\php.exe"` — and note that PowerShell needs the `&` call operator for a quoted path. "Module already loaded" means a duplicated extension line in `php.ini`; "could not find driver" means `pdo_mysql` is not enabled in the *loaded* `php.ini`. Check `php --ini` first; the loaded file is often not the one being edited.

**The built-in server is for tests, not for judging the app.** `php -S 127.0.0.1:8088 -t public` is single-threaded and appears to hang when a page triggers a second request to itself, and serves nothing outside the document root. Right tool for `e2e.sh`, wrong tool for deciding whether something is slow.

**Relative asset paths.** Everything goes through the `asset()` helper — a path relative to the application root plus a `filemtime` cache-buster. Never a hardcoded absolute or machine-specific path; the install must work from a subfolder under `htdocs` and from a production document root without edits. Point the document root at `public/`, never at the project root.

**Deploy to the running install and verify on-machine.** The change is done when the page has been loaded in the browser on the machine that runs the app and the behaviour has been seen — not when the agent says so. Hard-refresh if anything looks stale, and check the browser console: a JavaScript error after a PJAX swap is otherwise silent.

**Copy/paste hygiene.** A file pasted with literal `\n` escape sequences instead of real newlines has broken a build before. When an agent supplies a file, replace the whole file; never paste escaped text into the middle of one.

**Before production:** `'debug' => false` in `config.php`, seeded passwords changed, `storage/` writable and not web-servable, document root on `public/`. All four are on the pre-deploy list in `AGENTS.md` and all four are still outstanding on the running install.

---
---

## Open questions

Things this archive raised and never closed. None of them are blocked on information — they are decisions waiting to be made.

**The ledger is deferred.** Catalog items and expenses carry `revenue_account` and `cogs_account` codes, so the data is being tagged, but nothing posts anywhere. The chart of accounts in the money section is ready to use. The open choice is whether to build a real posting ledger inside the app or to keep tagging and export to accounting software — and that choice should be made before the account codes accumulate a year of history under an assumption that turns out wrong.

**Out-of-state tax.** `default_tax_rate` is 0.0, which is right for Oregon and wrong the moment a job is worked in Washington or California. The sourcing research is in the legal section; the app has no mechanism for a per-job rate. Worth deciding whether out-of-state work is common enough to build for, or rare enough to handle by hand.

**Prepayment versus the hosted link.** The legal finding is that roadside work on a public street leaves possession with the customer, so there is no lien and no leverage — and the remedy is to take payment for authorized work up front. The app issues a hosted checkout link against an invoice, which is inherently after the fact. Closing this means either card preauthorization at authorization time, or a deposit flow, or accepting the exposure deliberately.

**The production SMS driver.** `config.php` ships with `'sms' => 'outbox'`. Telnyx is approved and the driver is complete; production needs the switch and the credentials in Settings. Until then nothing actually sends.

**Pre-production hygiene.** Seeded passwords are still in place and `'debug'` is still on. Both are known and both need to go before the app faces the internet.

**Partner volume pricing** is fully specified as policy — the tier matrix, jobs offered rather than completed, monthly review, the $60–$150 band — and exists nowhere in the app. Provider jobs currently price like retail jobs.

**The $35 virtual assessment** was designed and never validated. The rollout plan was to run it manually first and see whether anyone buys it; that test has not happened.

**Warranty tracking** appears repeatedly in the conversations as something wanted, and it is the one stage of the twelve-stage model with nothing behind it. `catalog_items.warranty_months` and `mfr_warranty` are snapshotted onto every line, so the data is being captured, but nothing reads it back — there is no way to ask "what is still under warranty for this customer". This is the PTW stage, and it is the only genuine hole in the chain rather than a difference of modelling.

**Printable completion and scope-change artifacts.** COS and SCR both capture a customer signature but neither can be printed or handed over on its own — there is no work-order print route, and the variance authorization lives inside the invoice. For retail work that is fine. A motor club or fleet account that requires a signed completion certificate would force the issue, and the cheapest answer is a numbered printable over data the app already holds, not a new stage in the chain. See [Stages within the chain](#stages-within-the-chain).

---

## Provenance

Distilled 29 July 2026 from `secondbrain_chatgpt.json` (203 conversations), `secondbrain_claude.json` (25) and `secondbrain_gemini.json` (19) — 247 conversations, 4,489 messages, about 9.1 million characters, exported 14 July 2026.

Personal, medical and off-topic conversations in the exports were skipped rather than summarised. Legal and tax material is AI-derived and carries its own caution where it appears.

**One security note:** some source conversations contain API keys in plain text. Treat every credential visible in those exports as burned.
