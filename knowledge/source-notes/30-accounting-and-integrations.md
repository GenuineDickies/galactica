# Accounting & Integrations — Reference Notes (Bin: accounting-integrations-1)

Distilled from ~24 exported conversations (ChatGPT + Claude). Two sections: **Accounting** (chart of accounts, document map, core deposits, expense/COGS design, tips, bulk vs retail, Square reconciliation, invoicing companies) and **Integrations** (SMS/Telnyx, Square API, E.164, email/DNS/domain, SiteGround/php.ini, REST). Source conversation titles are cited in brackets.

---

## SECTION 1 — ACCOUNTING

### 1.1 Chart of Accounts — the settled structure

Two full COA passes exist; they converged on the same skeleton. Numbering follows the standard small-business convention [Core Deposit Accounting]:

| Range | Category |
|---|---|
| 1000–1999 | Assets |
| 2000–2999 | Liabilities |
| 3000–3999 | Equity |
| 4000–4999 | Revenue |
| 5000–5999 | Cost of Goods Sold |
| 6000–7999 | Expenses (7000s reserved for payment processing) |
| 8000–8999 | Other Income / 9000s Other Expense |

**Cleaned/corrected COA (the version ChatGPT approved after reviewing what the coding agent generated — rated "8.5/10, ~85–90% correct")** [Core Deposit Accounting]:

**Assets:** 1000 Cash · 1010 Checking · 1015 Undeposited Funds (add this — QuickBooks-style reconciliation depends on it) · 1050 Square Clearing · 1100 Accounts Receivable · 1120 Business Savings · 1200 Parts Inventory · 1300 Prepaid Expenses · 1500 Service Vehicle · 1510 Tools and Equipment (large tools = asset) · 1590 Accumulated Depreciation

**Liabilities:** 2000 Accounts Payable · 2010 Credit Card Payable · 2020 Sales Tax Payable · **2050 Core Deposits Payable** · 2060 Customer Refunds Payable · 2300 Customer Deposits

**Equity:** 3000 Owner Equity · 3100 Owner Contributions · 3200 Owner Draw · 3300 Retained Earnings

**Liabilities (addition):** 2050 Core Deposits Payable — seeded, because the catalog's core-deposit item points at it.

**Revenue — SUPERSEDED, see §1.4.** An earlier per-service tree (4010 Jump Start, 4020 Tire Change, 4030 Lockout, 4050 Mobile Mechanic Labor, 4060 Roadside General, 4100/4110/4120 Parts, 4200 Fuel, 4400 Platform) was written down here but **never implemented and never seeded**. It is dead. The live set is the five accounts in §1.4 plus 2050, and it is the one in `Accounts::DEFAULTS`. Reporting by service is done with `service_category`, not with revenue accounts.

**COGS:** the live set is `Accounts::DEFAULTS` 5000–5090 (listed in §1.4). The 5100/5200/5300 numbering sketched here was never implemented; fuel *delivered to customers* is **5090**, not 5100.

**Operating Expenses:** 6000 Vehicle Fuel Expense (fuel burned by the service truck — renamed to avoid conflict with 5100) · 6010 Vehicle Maintenance & Repairs (merged from two duplicate accounts) · 6050 Employee Wages · 6060 Payroll Taxes · 6070 Employee Benefits · 6080 Contractor Labor · 6100 Marketing & Advertising · 6110 Google Ads · 6120 Software Subscriptions · 6130 Phone and Communications · 6140 SMS Messaging (Telnyx) · 6250 Vehicle Insurance · 6300 General Insurance · 6400 Supplies · 6500 Licensing & Permits · 6600 Small Tools Expense · 6800 Office Expenses · 6900 Other Expenses

**Payment Processing:** 7000 Merchant Processing Fees · 7010 Square Fees · (7020 Chargebacks)

**Specific corrections made to the agent-generated COA** [Core Deposit Accounting]:
- **Delete "4500 Square Payment Revenue"** — Square is a payment processor, not revenue; revenue posts when the invoice posts.
- **Delete "4100 Roadside Assistance Revenue"** as a duplicate of the 4000 service tree; replaced by child 4060.
- **Delete "6850 Bank & Processing Fees"** — duplicated 7000/7010.
- **Merge 6010/6020** vehicle maintenance duplicates.
- **6600 Tools & Equipment expense renamed "Small Tools Expense"** to stop colliding with asset 1510.
- **5400 Subcontractor Payments removed**, replaced by 6080 Contractor Labor so all labor groups together.
- **6050 Salaries & Wages**: initially removed because Jason is a sole owner (owner pay = 3200 Owner Draw, never a wage expense — a false 6050 expense distorts profit and taxes). Then **reinstated** when Jason pointed out the app will be marketed to other operators who will have W-2 techs/dispatchers. Design principle: the COA is a **generic automotive/roadside template** ("industry standard automotive shop accounting"), supporting both owner-only shops (Owner Draw) and employer shops (6050/6060/6070/6080), with unused accounts sitting inactive.
- Optional refinement offered: split 2050 into **2050 Customer Core Deposits Payable (liability)** + **1350 Supplier Core Deposits Receivable (asset)** for textbook correctness; the single-account 2050 clearing approach was kept for simplicity.

An earlier, novice-friendly 54-account chart (RoadRunner Admin era) exists in [Chart of accounts for roadside assistance] with the same 1000s/4000s conventions, Cost of Services separated from Operating Expenses to enable gross-profit-per-job, and WKR-specific accounts (6420 SMS/Messaging Services, 4040 Fleet Account Revenue, 6700 Bank & Processing Fees). Merchant account fees answer: they belong under Bank & Processing Fees, optionally split 6700 Bank Fees / 6710 Merchant Account Fees / 6720 Credit Card Processing Fees / 6730 Loan Interest. The later Core Deposit Accounting version supersedes this but the same logic applies.

### 1.2 Core deposit accounting (the definitive treatment)

[Core Deposit Accounting] — A core deposit is **never revenue and never expense**; it is temporary money held, tracked in **2050 Core Deposits Payable** (current liability).

Example — battery $260 + core deposit $22:

| Event | Entry |
|---|---|
| Sell part + collect core | Dr Cash/AR 282 · Cr Parts Sales 260 · Cr 2050 Core Deposits Payable 22 |
| Customer returns core, gets refund | Dr 2050 22 · Cr Cash 22 |
| You return core to supplier, supplier refunds | Dr Cash 22 · Cr 2050 22 |
| You pay supplier core charge on a reman part | Dr 2050 (pre-paid core money) |

App implementation: add `part.core_required` (boolean) and `part.core_value` (decimal) to the parts catalog so the invoice engine auto-posts part revenue + core liability — prevents overstated revenue, a common shop mistake. Full example transaction: battery $260 + labor $85 + tax $30 + core $22 = $397 → Dr Checking 397 / Cr Battery Sales 260, Labor Revenue 85, Sales Tax Payable 30, Core Deposits Payable 22.

### 1.3 Revenue vs income; automatic categorization

[Revenue vs income categorization] — Payments on invoices are **revenue, not income**. Income = revenue − expenses, calculated at the reporting layer. Settled system rule: *"Payments on service requests and invoices should be automatically categorized as revenue, not income. The system should auto-classify payments to the appropriate revenue account, while still allowing manual reclassification"* for refunds, write-offs, misapplied payments. Flow: $200 invoice payment → auto-posts to Service Revenue; Cash +200; AR −200; $60 fuel → 5110 Fuel Expense; net income $140.

### 1.4 Posting model: only invoice line items post revenue

[Service Expense Account Setup] — Key architectural decision Jason drove:

- **Service catalog items are non-posting templates** (job type, workflow, default lines, reporting category). **Only invoice line items post revenue.** Otherwise "Service: Jump Start +$85" plus "Line: Jump Start Labor +$85" double-counts income.
- Don't create a revenue account per service ("Jump Start Revenue," "Lockout Revenue"...). Clean revenue set, **and this is the implemented one**: `4000 Service Labor Revenue · 4010 Parts Sales Revenue · 4020 Fuel Delivery Revenue · 4030 Fees & Surcharges Revenue · 4040 Discounts/Adjustments`, plus `2050 Core Deposits Payable`. Use the service *type* for operational reporting (Jump Starts $3,200; Tire Changes $2,100...), so you can report both the accounting way and the operations way.
- **Fuel delivery keeps its own revenue account** and it is the only per-service-looking exception. It earns it: it is the only line with a matching COGS account (5090), the only one whose cost moves with pump prices, and the only one that is tangible personal property rather than a service for sales tax. Revenue paired with COGS gives a real gross margin — merged into 4000, service margin is understated and fuel margin is invisible.
- **Operational reporting is `service_category`, not the chart of accounts** — four values, ROADSIDE / TIRE / MECHANIC / OTHER, on the service request, the work order and every catalog item. See docs/BUSINESS_RULES.md §1a–1c. This is what lets the revenue set stay small.
- **Nothing is 100% margin.** Jump starts, lockouts and spare swaps have no *materials* cost, but every job carries merchant fees (5040), truck fuel to get there (5030) and direct labour (5070). An $85 jump start nets roughly $78 before labour. Treating those as free is how long callouts get underpriced.
- Three separated concepts: (1) Service Catalog (templates — default price, vehicle/VIN required, whether parts allowed, default invoice lines), (2) Invoice Line Item Catalog (posts to accounting; each line carries revenue account, tax behavior, item type labor/part/fee/fuel/discount), (3) Cost Lines (part cost, consumables, sublet, merchant fee, delivered fuel, warranty/rework).

**Posting vs allocating** (Jason's phrasing, confirmed correct): *posting = recorded in the ledger once; allocating = internal assignment of an already-recorded cost for job costing, can happen many ways, but hits the ledger only once.*

**Fuel double-count rule**: an $80 fuel purchase posts once (Dr Vehicle Fuel Expense / Cr Cash). Attributing ~$6.50 of trip fuel to a jumpstart job is a **non-posting allocation** for margin display only. Give every job cost line a `Posts to Accounting: Yes/No` flag. Exception: **fuel delivered/sold to a customer is real COGS** — `5090 COGS — Fuel Sold / Delivered Fuel` — distinct from fuel your truck burns.

**Service COGS account set (recommended minimum)** for direct costs of services (services have direct costs even without parts):

```
5000 COGS — Parts & Materials Sold
5010 COGS — Sublet / Outside Services
5020 COGS — Consumables Used on Jobs
5030 COGS — Vehicle Fuel Used for Jobs
5040 COGS — Merchant Processing Fees
5050 COGS — Warranty / Rework Costs
5060 COGS — Disposal / Environmental Fees
5070 COGS — Direct Labor
5080 COGS — Roadside Equipment Usage
5090 COGS — Fuel Sold / Delivered Fuel
```

Per-service default cost profiles: Jump Start → usually no posted COGS unless parts/consumables added (fuel/equipment as non-posting allocation); Tire Change → plugs/stems/lug nuts = Parts COGS, disposal = 5060; Lockout → third-party locksmith = 5010 Sublet; Battery/Alternator/Starter → part = 5000, core = 2050 clearing (not expense), comebacks = 5050 Warranty/Rework. Each catalog service gets fields: Revenue Account, Default COGS Account, Allows Parts?, Track Fuel?, Default Cost Method (Actual / Flat allocation / None).

### 1.5 Accounting document map

[Accounting System Documents] + [Accounting System Document Map] — Full modern-system map: documents split into **source documents** (proof) and **accounting records** (entries). 14 groups with 3-letter codes: Customer/Sales (INT, SER, EST, EAP, WOR, DSP, COS, SCR, INV, PAY, RCT, CRM, REF, STM, CAU, PTW), Vendor/Purchasing (REQ, RFQ, VQT, POR, POA, GRN, VBI, VCR, VPM, RMA, RET), Inventory (ITM, BOM, STK, TRN, ISS, RCV, CNT, VAR, COG, WAR), Banking (DEP, BTR, WDL, BFS, REC, MSC merchant settlement, FEE, CHB chargeback, CBD chargeback defense packet, NSF), GL core (COA, JRN, LED, SUB, ADJ, REV, ACC, DEF, CLS, AUD), AR (ARL, AGE, DUN, WOFF, PMT), AP (APL, APA, APP, SCH, 1099), Tax (TAX, STX, UTX, EXT, W9...), Payroll, Fixed Assets (AST, CAP, DEPX, DISP), Loans (incl. COR Core Deposit Record), Compliance/Evidence (SIG, GEO, DEV, PHO, MSG, CAL, AUD, POL), Reports, Master records (CUS, VEN, VEH, ITM, LOC, BNK...).

Posting concept: `Source Document → Transaction → Journal Entry → Ledger`. E.g. INV → Dr AR / Cr Revenue; PAY → Dr Cash or Square Clearing / Cr AR; VBI → Dr Expense/Inventory / Cr AP; VPM → Dr AP / Cr Cash.

**Chargeback defense packet** = assembled from SER + EST + EAP + COS + SCR + INV + PAY + RCT + SIG + GEO + DEV + PHO + MSG.

**Which documents to actually build** [Accounting System Document Map] — corrected v1 set (Jason overrode the AI: *"law dictates there be a change of service document"* → COS moved into v1):

- **v1 (required):** SER, EST, EAP, WOR, DSP, **COS**, SCR, INV, PAY, RCT, EXP (expense receipt), PTW (parts warranty)
- **v2:** INT, CRM, REF, DEP, REC
- **v3:** POR, VBI, GRN, STM, JRN

Governing rules: every job produces an estimate; EAP required when estimate > $200, or final invoice may differ from estimate by > $200 or > 10%; **COS must be created and approved before changed work is performed or billed** whenever work/price/parts/labor/scope changes after estimate approval ("Estimate approval protects the original job; Change of Service approval protects anything that changes after"). COS fields: original estimate ref, reason, added/removed services & parts, price difference, new total, customer approval method + timestamp, signature/authorization evidence, photos, created by / approved by. The conversation also produced complete field lists and a MySQL schema for every v1 document (exported as a text file).

Skip for now: payroll runs, timesheets, commissions, retainage, progress billing, BOM/assemblies, intercompany, lease accounting, VAT, W-2/1099 filing.

### 1.6 Square reconciliation

[Square reconciliation reports] — Three Square Dashboard reports for reconciliation:
1. **Sales Summary** (Reports → Sales Summary → export CSV): gross sales, refunds, taxes, tips, net, payment method breakdown — verify app invoices/payments match Square.
2. **Transfers** (Balance → Transfers → CSV): transfer date, gross, fees, adjustments, net deposit — the one you reconcile against the checking account. E.g. payments $1,000 − fees $32 = $968 transfer → Dr Checking 968, Dr Processing Fees Expense 32, Cr Square Clearing 1,000.
3. **Transaction Detail** (Transactions → CSV): transaction ID, customer, amount, card type, refunds, timestamp — for disputes and invoice tracing.

Ledger workflow with **1050 Square Clearing**: (1) invoice: Dr AR / Cr Revenue; (2) Square payment: Dr Square Clearing / Cr AR; (3) deposit: Dr Checking + Dr Merchant Processing Fees / Cr Square Clearing. Better long-term: pull Payments, Payouts, and Customers APIs every few minutes and auto-reconcile deposits against 1050, showing a screen like "June 6: Payments $623.00, Deposits $600.84, Difference $22.16 = Fees."

Real-account context [Square app connection]: Claude analyzed 12 months of live Square data (Jun 2025–Jun 2026): 354 completed payments, **$45,940 gross, $1,445 fees (3.15% blended), ~$44,495 net**; true run rate ~$48–50K/yr; median ticket $96.65, average climbing from $110–130 (fall 2025) to $140–168 (2026); weekends and 12pm–midnight dominate (73%); overnight only ~10%; 189/342 card payments hand-keyed at 3.57% vs 2.74% contactless (switching would save only ~$230/yr, and keyed fails less often roadside — keep keying); 31 failed payment attempts (8%); cash negligible ($945/9 txns); top 10% of customers = 39% of revenue.

### 1.7 Tips handling

[Tip misallocation and pricing policy] + [Square app connection] — Incident: a dispatch partner (Mobile Tire Guys LLC — Jason's largest account: 22 payments, $1,822, avg $83/job) kept a customer tip that the customer explicitly directed to Jason; they claimed it was "assumed to be for dispatch." Square data corroborated: **all 22 MTG payments carried $0.00 tips**, vs $931 in tips across 47 direct-customer jobs (~$20 avg, ~13% tip rate). Jason ended the relationship on principle ("It could have been five cents. They are thieves.").

Policy positions established in the formal letter (final version drafted, White Knight Roadside LLC letterhead):
- In this business model tips overwhelmingly belong to the person on scene; no industry assumes tips go to in-house/dispatch positions.
- Handshake arrangement: no contract, no employer-employee relationship, so tip laws don't apply and Jason claims no default right to blind tips — providers may do as they see fit with unspecified tips.
- **But a customer's specific direction of a tip is a verbal contract and must be honored.**
- No receipts demanded; expectation is simply honorable dealing.
- Consequence lever: **performance-based pricing matrix** — deep discounted rates tied to monthly job volume, recalculated the first weekday of each month based on prior-month delivered volume; current rates stay in effect until recalculated; rates posted publicly on the website, applied uniformly/non-discriminatorily; continued problems revert the account to standard retail pricing.

(App implication: Square Sales Summary carries tip totals — the accounting system should track tips distinctly, and platform/bulk jobs will show zero-tip patterns.)

### 1.8 Bulk service vs retail sale

[Bulk service vs retail sale] — When servicing a bulk provider's customer (Agero, AAA, fleets, insurers, Honk/Urgently), Jason is **not selling to the vehicle owner** — he is selling a wholesale/bulk service to the provider. Consequences:
- Invoice goes to the provider at **contracted rates**, not retail; vehicle owner gets no invoice (unless copay).
- B2B tax treatment: generally no sales tax collected from the vehicle owner; provider contracts often tax-exempt; nexus rules per configuration.
- App design (PRD v0.9 written): `job_source` ENUM {RETAIL, BULK, HYBRID} on every service request; Provider + versioned Contract entities with rate cards, caps, mileage logic, after-hours differentials, tax treatment, SLA/evidence requirements, Net-30/45 terms; **Hybrid = two invoices** (provider items at contract rates + customer copay items at retail); line-item payer toggle; pricing falls back to manual approval if no contract rate; reports by source/provider/contract + provider AR aging.
- Standing invariants carried through: vehicle requires **plate + state** (unique pair); no invoice without a vehicle; VIN optional at intake; existing SRs backfill to RETAIL.

Routing table:

| Job Source | Customer in DB | Invoice To | Sales Tax |
|---|---|---|---|
| Retail | Vehicle owner | Vehicle owner | Usually taxable |
| Bulk | Bulk provider | Bulk provider | Usually non-taxable |
| Hybrid/Copay | Both | Both (split) | Mixed |

### 1.9 Invoicing a company cold ("ghost invoices")

[Invoicing a company] — Can you just send a company a bill? You *can*, but without a PO/agreement most AP departments reject it as a "ghost invoice," and if it's deceptive it can be criminal: wire/mail fraud (18 U.S.C. §1343/§1341), Oregon theft by deception (ORS 164.085), Oregon UTPA, unlawful-collection statutes, False Claims Act for government payers (treble damages). Practical playbook: OK to invoice now if work was requested/approved (email/text/PO/dispatch ID) or delivered and accepted (signed work order, photos, GPS/job logs). Otherwise email AP first, confirm the payer, complete vendor onboarding (W-9, PO), and send a **"statement of charges" requesting approval — not labeled "Invoice."** Roadside invoices should carry: dispatch/job ID, plate + state, service date/time/location, signed work authorization, before/after photos, legal name/EIN, remit details, PO ref, Net-15 terms, lawful late-fee language. Escalation: past-due reminder → demand letter → small claims; mechanic's lien usually requires possession of the vehicle, so roadside-only work rarely qualifies. Survey data: roughly one-third to one-half of businesses hit by invoice fraud yearly; AP teams catch only ~39% of invoice errors.

### 1.10 Modern accounting UI directive

[Modern Accounting System Request] — Jason ordered the old accounting UI "revoked and replaced" with a useful, intuitive system — a "financial command center," not a bookkeeper-only UI. A full design prompt (~16k chars) was generated for a coding agent. (Content lives in that chat's code block; the design intent is the takeaway.)

---

## SECTION 2 — INTEGRATIONS

### 2.1 SMS: Telnyx (settled — account approved, 10DLC approved)

> **Current state, not a decision to revisit.** SMS is Telnyx. The account is approved and set up, and 10DLC is approved. Everything below is history and implementation detail — the provider question is closed. Do not propose alternatives.

Chronology (how it got here): [Twilio account setup] (walked through Twilio signup, buy local 10DLC number, Messaging Service, A2P registration) → **Twilio denied service** [SMS Provider Alternatives] → provider evaluation [SMS alternatives to Twilio] → **Telnyx chosen** → [Telnyx Messaging Setup Guide] → account and 10DLC campaign approved.

**Requirements that shaped the decision:** ~10 texts/day, US-only, dispatch/updates + estimate approvals, need inbound replies (YES/NO/STOP), need authorization evidence (device/location), stack = PHP/MySQL/XAMPP.

**Option buckets considered** *(historical — closed; Telnyx is the provider)*: (1) CPaaS APIs — Telnyx, Bandwidth, Vonage/Nexmo, Plivo, Sinch, MessageBird, Infobip, SignalWire, ClickSend, Telesign (best fit); (2) SMPP/direct-to-carrier (only worth it at scale); (3) email-to-SMS gateways (unreliable, filtered); (4) own SIM/Android/GSM modem (fragile, TOS risk); (5) non-SMS channels. Shortlist: **Telnyx or Bandwidth**; Telnyx chosen ("Use Track A with Telnyx").

**Two US compliance tracks (unavoidable with any provider):**
- **Track A — Toll-free + toll-free verification** (chosen): required for deliverability; unverified/pending toll-free traffic has been industry-blocked since **Jan 31, 2024**; **BRN/EIN fields become mandatory for new toll-free verifications Feb 17, 2026** (Telnyx and Bandwidth both) — gather EIN info up front. Simplest lane at low volume.
- **Track B — Local 10DLC**: requires A2P Brand + Campaign registration via TCR; more paperwork, "local" look; unregistered traffic gets carrier fees and filtering; Twilio has a Sole Proprietor option if no EIN.

**Campaign registration without a live product** [SMS Provider Alternatives]: sample messages are just *templates* — no live sending required. Minimum viable packet: campaign type "Customer Care / Account Notifications" (easier than Marketing); message-flow paragraph describing opt-in (verbal at intake logged with timestamp, web form checkbox, or signed estimate); 3–6 sample messages containing the business name + "Reply STOP to opt out"; STOP/END/CANCEL/UNSUBSCRIBE, START, HELP keywords with confirmations; public **Privacy Policy + SMS Terms page** ("Msg frequency varies. Msg & data rates may apply."). Sample template style: *"White Knight Roadside: On the way to [ServiceAddress]. ETA ~[ETA]. Reply STOP to opt out, HELP for help."* Common rejection causes: vague description, samples without sender name, missing STOP/HELP, claimed web opt-in form that isn't live. **Develop while pending** via an SMS abstraction: `SmsGateway` interface with a `DbSmsGateway`/NullSmsProvider that writes to an outbox table; swap in `TelnyxSmsProvider` at approval.

**Telnyx implementation (settled architecture)** [SMS alternatives to Twilio]:
- Outbound: `POST https://api.telnyx.com/v2/messages` with `Authorization: Bearer $TELNYX_API_KEY`, JSON `{from, to, text}` (PHP cURL).
- Inbound: webhook POST, event `message.received` at `data.event_type`; payload fields `data.payload.from.phone_number`, `data.payload.text`; handle STOP/HELP/YES; respond 200.
- Webhook security: Telnyx signs with **Ed25519** over `timestamp|payload`, headers `telnyx-timestamp` + `telnyx-signature-ed25519` — verify in production.
- Delivery status callbacks on a second webhook endpoint.
- Local dev: XAMPP + ngrok tunnel for the public HTTPS webhook URL.
- Env vars (no secrets in code): `TELNYX_API_KEY`, `TELNYX_FROM_NUMBER` (+1 toll-free), `APP_BASE_URL`, DB creds.
- DB: `sms_messages` (provider, provider_message_id, direction, from_e164, to_e164, body, status queued/sent/delivered/failed/received, error_code...), plus consent + approval tables.
- **Two-channel authorization pattern** (SMS alone can't prove device/location): text "Estimate $245. Reply YES to approve, or approve here: https://…/a/{token} (Reply STOP to opt out)". YES reply = baseline record; the tokenized approval link captures IP, user-agent, timestamp, optional browser geolocation → the strong evidence for chargebacks.

**Telnyx portal setup steps** [Telnyx Messaging Setup Guide]: (1) Mission Control → Messaging → Add new profile (e.g. `Roadside-Dispatch`); (2) Numbers → My Numbers → edit → assign number to the messaging profile; (3) set inbound + delivery-status webhook URLs on the profile (webhook.site for first test); (4) create API key; (5) compliance — toll-free verification submission (webhook status updates available) or 10DLC brand→campaign→number assignment; (6) test: text the Telnyx number from a phone, confirm webhook fires, reply back; (7) enable **spend limits** on the messaging profile early; MMS supported (inbound MMS includes attachment link in payload).

**SMS consent model (Jason's mandated spec, attached to every phone number)** [SMS alternatives to Twilio]:
- Fields: `sms_consent_granted` (timestamp opted in), `sms_consent_auto_end` (when it auto-expires), `sms_consent_revoked` (opt-out timestamp), `sms_consent_source` (how consent was given), a hard **`sms_approved` true/false flag — no SMS may be sent unless true**, plus a **`do_not_contact`** flag for do-not-call requests.
- Consent is normally granted **verbally at estimate approval**: customer is asked to approve the estimate via text; agreeing = consent. If the customer can't confirm by text, the technician captures a **signature + photo of ID at arrival** before work begins. Signature is *only* required for that fallback case.
- Re-granting consent after revocation/auto-end updates the timestamps and flags.

**Document numbering** (settled in the same conversation): `TYPE-YYYYMMDD-####-V#` (e.g. `EST-20260115-0042-V1`); daily sequence is 3–4 digits, unique per TYPE per day; date + seq frozen at V1; V# bumps only when customer-visible content changes; store `doc_type/doc_date/doc_seq/doc_version` separately with `UNIQUE(doc_type, doc_date, doc_seq, doc_version)`; job anchor `job_no = SER-YYYYMMDD-####` ties all documents to the service request.

Also settled there: catalog-first line items **relaxed** — service requests must accept ad-hoc purchased parts at time of purchase (name, part number, description, purchased price, vendor ID, qty, warranty yes/no + end date + details, proof of purchase attachments) because mid-repair purchases are "borderline infinite"; app must run **locally (XAMPP) or on remote hosting** from config; Plate→VIN lookup API, VIN decoder API, and Google Maps API are required integrations.

### 2.2 Square connection & API decisions

[Square API Integration Prompt ×2] + [SMS alternatives to Twilio] + [Square app connection]:
- Account: one location, "White Knight Roadside," on Square **since 2014**.
- Browser flow: **Web Payments SDK** tokenizes the card client-side → token to backend → server calls **Payments API `CreatePayment`** with a unique **idempotency key** (retry never double-charges). Web Payments SDK requires secure context/CSP-friendly hosting.
- Alternative starting point recommended in the second prompt: **Square-hosted Checkout payment links** first (keeps card handling out of the app entirely), deeper card-entry/terminal later.
- **Webhooks** `payment.created` / `payment.updated` reconcile status; validate webhook notification signatures.
- Official **Square PHP SDK** (square/square-php-sdk) with sandbox vs production environment config.
- **Manual payment recording pathway is required** alongside API payments (cash/check/manual card).
- Accounting boundary rule (stated in both prompts): *do not let Square "paid" events post revenue.* The invoice posts revenue/AR; the Square payment clears AR into **1050 Square Clearing**; deposits move Clearing → Checking with fees to 7000/7010. "Square is the card machine, not the accountant."

### 2.3 E.164 phone validation (for Telnyx)

[E.164 phone number validation for Telnyx]:
- Storage standard: E.164 `+1XXXXXXXXXX`; validation regex `/^\+1\d{10}$/`. Telnyx rejects malformed numbers with 4xx — failed calls pollute logs and count against rate limits.
- **Decision: display format ≠ storage format.** UI enforces `(xxx) xxx-xxxx` (14 chars max, `type="tel"`, `inputMode="numeric"`); DB stores E.164.
- Normalize at the boundary (intake form, customer create/edit, CSV import): strip non-digits; 10 digits → prepend `+1`; 11 digits starting with 1 → prepend `+`; else reject. Helper set: `formatPhoneDisplay`, `toE164`, `fromE164`, `isValidUSPhone`.
- Backend still validates `/^\+1\d{10}$/` and 422s bad input — UI masking is UX, not a security boundary.
- Column: `VARCHAR(15)`; optional check `CHECK (phone REGEXP '^\\+[1-9][0-9]{1,14}$')`. Migration: run `toE164()` over existing rows; failures go to the "unconfirmed" bucket. Caveat: the `+1`-only regex locks out non-US/Canada numbers if the business ever expands.

### 2.4 Email, domain, DNS

[Email account setup] — Domain **wkrllc.com** (a *new* domain; a previous domain was lost, which caused Google Cloud Identity to not recognize wkrllc.com). Fixes: sign up fresh for Google Workspace (Business Starter) or free Cloud Identity; verify domain via DNS **TXT record** (`google-site-verification=...` on host `@`); MX records for mail; then the org's **2-Step Verification policy** error required completing 2SV at myaccount.google.com/security (admin can adjust enforcement under Admin Console → Security → 2-Step Verification per OU). User's email is admin@wkrllc.com.
- Recommended mailbox set: `admin@` (registrar/hosting logins), `info@` (public, forward), `support@`, `billing@`, `noreply@` (automated sends), plus `dispatch@`, `sales@`, personal `jason@...`-style. Use aliases/forwards for the light ones, real mailboxes only where you send from. Set **SPF, DKIM, DMARC** to stay out of spam. For Google Ads specifically: dedicated `ads@wkrllc.com` (or `marketing@`), 2FA on, `admin@` as recovery, add hired marketers as Google Ads users rather than sharing logins.

### 2.5 Website/hosting fixes (SiteGround/Apache)

[Website not loading fix] + [Domain Configuration Issue] — Two related incidents on wkrllc.com:
1. Bare root `https://wkrllc.com/` returned blank while `/index.html`, `/contact.html`, `/pricing.html` loaded → server wasn't serving index.html as the default document. Fixes: check `public_html` for a blank `index.php`/`index` shadowing it; add `DirectoryIndex index.html index.php index.htm` near the top of `.htaccess`; if still blank, `RewriteEngine On` + `RewriteRule ^$ /index.html [L]`.
2. Later: **www and bare domain served different site versions** — `www.wkrllc.com` had an older deployment exposing placeholder `service@example.com`, while the bare domain's contact page correctly showed `Admin@wkrllc.com`. Diagnosis: two document roots/deployments + wrong default index. Fix: pick **canonical `https://wkrllc.com/`**, 301-redirect http/www variants to it via `.htaccess`:

```apache
DirectoryIndex index.html index.php
RewriteEngine On
RewriteCond %{HTTPS} !=on [OR]
RewriteCond %{HTTP_HOST} ^www\.wkrllc\.com$ [NC]
RewriteRule ^(.*)$ https://wkrllc.com/$1 [R=301,L]
```

then delete/overwrite the stale www files. A Claude Code prompt was written to apply the minimum safe fix with diagnosis-first behavior.

### 2.6 Local PHP environment (php.ini)

[Correct php.ini syntax] — Windows dev box, **PHP 8.2.12 at `C:\php8412\`** (XAMPP-adjacent; Apache at `C:\xampp2\apache`). Key working config:
- `extension_dir = "C:\php8412\ext"`; enable (no `.dll` suffix, uncommented): `bz2, curl, fileinfo, mbstring, exif, mysqli, pdo_mysql, pdo_sqlite, sqlite3, openssl`; opcache with `opcache.jit=1255`, `opcache.jit_buffer_size=64M`.
- SSL roots must exist: `curl.cainfo` and `openssl.cafile` both → `C:\php8412\cacert.pem`; watch for a **duplicate `[openssl]` section** overriding cafile (was present in the original file).
- Dev settings: `memory_limit=512M`, `max_execution_time=120`, `display_errors=On`, `error_log="C:\php8412\logs\php_error.log"`, uploads 40M, sessions in `C:\php8412\sessions` with `use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax`. Production deltas noted: display_errors Off, tightened error_reporting, `opcache.validate_timestamps=0` post-deploy.
- "Missing extensions" root causes checklist: Apache loading a *different* PHP (verify `Loaded Configuration File` in phpinfo; fix with `LoadModule php_module "C:/php8412/php8apache2_4.dll"` + `PHPIniDir "C:/php8412"`); companion DLLs (libssl-3-x64, libcrypto-3-x64, libsqlite3) need `C:\php8412` on PATH; must be Thread Safe x64 build for the Apache module; VC++ redistributable (VS 2019–2022 x64) if the error log shows runtime errors. Verify via `C:\php8412\php.exe -m` and phpinfo. Deprecated directives never to re-add: safe_mode*, register_globals, magic_quotes_*, etc. Timezone in the file was `Europe/Berlin` — should be corrected to the business timezone.

### 2.7 REST API basics settled on

[Rest API overview] — The app's architecture is resource-oriented REST: nouns as URLs (`GET /service-requests`, `GET /service-requests/42`, `POST`, `PUT/PATCH`, `DELETE`); HTTP verb carries intent (no `/getServiceRequest` endpoints); stateless requests with `Authorization: Bearer <token>`; standard status codes (200/201/400/401/403/404/500) so the frontend reacts to codes; JSON representations of MySQL rows. Why it matters to Jason (layman framing he accepted): build the backend once and every client — React frontend, phone in the field, future customer portal — hits the same endpoints; and every vendor integration (Telnyx, Square, QuickBooks) is the same REST pattern in reverse. Example round-trip: `POST /api/dispatch` + Bearer token → PHP validates, inserts, returns `201` with the new record's id. Known REST trade-offs acknowledged (over/under-fetching, chatty screens) but plain REST judged right for a field-service app: simple, cacheable, curl-debuggable.

---

## Cross-cutting decisions worth remembering

1. **Square is never revenue.** Invoice posts revenue/AR; payments clear AR through 1050 Square Clearing; fees land in 7000/7010. (Repeated across four conversations — this is doctrine.)
2. **Nothing refundable touches P&L**: core deposits (2050) and customer deposits (2300) are liabilities.
3. **Post once, allocate freely**: ledger entries are singular; job costing is a non-posting overlay with a Posts-to-Accounting flag per cost line.
4. **Only invoice line items post revenue** — service catalog entries are templates.
5. **COA is a marketable template**, not Jason-only: keep payroll accounts (6050–6080) inactive-but-present.
6. **COS is a legally required v1 document**; approvals thresholds: >$200 estimate, or invoice deviating >$200 or >10%.
7. **Consent gates all SMS**: `sms_approved` must be true; consent granted/auto-end/revoked/source timestamps on every phone number; do_not_contact flag.
8. **Normalize phone numbers at the boundary**, store E.164, display (xxx) xxx-xxxx.
9. **SMS is Telnyx — account approved, 10DLC approved.** Settled; not an open provider question.
10. **Canonical domain is non-www https://wkrllc.com** with 301s from all variants.
