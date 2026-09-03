# Legal, Tax & Business Reference — White Knight Roadside (WKR LLC)

> **Important caveat:** Everything in this document was distilled from AI chat conversations (ChatGPT/Claude). Statute citations, thresholds, and legal conclusions came from AI answers, not from an attorney or accountant. Verify anything consequential with a licensed Oregon attorney, CPA, or the relevant agency before relying on it. Laws and thresholds change.

---

## 1. Oregon Auto Repair Estimate Law — Applies to Mobile Roadside Work

*Source: "Auto repair estimate laws"*

**Key finding: Oregon's vehicle repair shop statutes (ORS 646A.480–646A.495) apply to WKR even though the business is mobile.** The statute defines a "vehicle repair shop" as *any* business entity that, in exchange for payment, evaluates the condition of, maintains, or repairs a motor vehicle. No brick-and-mortar requirement. Diagnosing a no-start, changing a tire, installing a battery or alternator — all of it counts.

### The core rules (Oregon)

- **Estimates over $200:** The shop cannot change the work or method in a way that increases cost by **more than 10% or more than $200 (whichever is less)** beyond the estimate without **separate owner authorization**.
- An estimate must be **prepared before work starts**, a copy given to the customer by the time of final payment, and a copy kept in records.
- Valid authorization methods for changes:
  - Signature under a printed authorization statement
  - **Phone authorization** (document who, phone number, date, and time on the estimate)
  - **Text/email or other written electronic message** that can be attached/printed
- If disassembly/evaluation is involved, the estimate must spell out the **evaluation/disassembly cost** and a separate **reassembly estimate** if the customer declines further work.
- For **body and frame shops**, ORS 746.292(2) separately requires a written estimate and prohibits charging in excess of the estimate **without customer consent** (no fixed percentage).

### Jobs under $200

The >$200 estimate rule and 10%/$200 cap technically don't kick in, but Oregon's **Unlawful Trade Practices Act** still applies — no quoting "$95" and billing $250 without clear mid-job approval. Quote the full out-the-door price up front; get a "yes" before any add-on.

### General U.S. pattern (for out-of-state jobs)

- Most states require written estimates above a threshold (often ~$100).
- Two models: a hard **"10% rule"** (e.g., Illinois, Maryland — final bill can't exceed the written estimate by more than ~10% without authorization) or a **consent rule** with no fixed percentage.
- Itemized invoices are commonly required (each part, labor, new/used/aftermarket status). Charging for repairs not performed or parts not supplied is illegal.

### Workflow rules adopted

- Treat every estimate as a **hard cap** until the customer approves more.
- Every job: an "Estimate total" field and a "Customer approved" checkbox with timestamp.
- When actual charges approach the 10%/$200 overage trigger, the system forces a "Get new approval" step (update estimate → log approval method → continue).
- Final invoice shows original estimate, approved supplements (with date/time and approval method), and final total.

---

## 2. Stranded Drivers, Call "Ownership," and Interference

*Source: "Stranded Driver Assistance Laws"*

- **No law gives a tow company exclusive rights to a motorist just because they were dispatched first.** The customer can choose any provider until they've actually entered a binding agreement or accepted service. "On the way" does not equal ownership of the call.
- **Tortious interference** requires: a valid contract existed, the interferer knew about it, intentionally caused its breach, and damages resulted. Simply helping a motorist who independently chooses you is not interference.
- **Police tow rotations** are the exception — jumping a police-dispatched rotation call can violate local regulations and get a tower removed from rotation. At accident scenes, the controlling officer designates who tows; Oregon also **prohibits tow solicitation at accident scenes** except through a road service company.
- Oregon: a vehicle owner can stop a tow before completion. If the owner is present and **hookup is not complete, the vehicle must be released**; if hookup is complete, the tower may charge a **hookup fee but not a full tow fee**.
- **Practical rule for WKR: "Don't poach. Don't interfere. Let the customer choose."** If a driver already has AAA dispatched: have *the customer* cancel the tow themselves, then serve them. Document that they requested WKR and canceled on their own. Don't contact the competing company.

### The ODOT fuel-delivery incident (case study)

Customer requested fuel delivery, accepted a quoted price ("OK, come on down"), Jason bought fuel and was 3/4 of the way there when ODOT stopped and helped the driver for free. Conclusions:

- A **verbal contract existed** (offer, acceptance, reliance), but ODOT — a non-commercial public-safety actor with no knowledge of the contract — did **not** commit tortious interference. This is a **customer cancellation problem**, not an ODOT problem.
- The recoverable amount (fuel + drive time) is too small to litigate. The fix is contractual:
  - **Non-refundable dispatch fee** earned when the technician begins travel (not when the customer calls) — even $25–$50 removes most of the sting.
  - **"Fuel purchased specifically for a customer is non-refundable and will be charged if service is canceled after dispatch."**
  - Best setup: **dispatch fee + card preauthorization** for the full quoted amount at booking; capture only what's earned (void if canceled pre-departure; capture dispatch fee if canceled post-departure; capture dispatch fee + fuel cost if fuel already purchased).
- App fields added for this: `dispatch_fee_amount`, `dispatch_fee_earned_at`, `preauth_amount`, `preauth_transaction_id`, `fuel_purchased_amount`, `cancellation_reason/timestamp/charge`, plus statuses (Pending Payment → Authorized → Dispatched → En Route → Cancelled Before/After Dispatch → Completed).

---

## 3. Contract Law Essentials

### Verbal contracts

*Source: "Verbal Contract Essentials"*

A verbal contract is enforceable if all elements exist: **offer** (specific terms), **acceptance** (clear agreement), **mutual intent to be bound**, **consideration** (value both ways), and **definite terms** (what work, for how much, when). A verbal contract exists the moment: *quote a price → they clearly say yes → work begins*.

- Not contracts: casual statements, negotiations without agreement, vague promises, promises without consideration.
- **Statute of Frauds** exceptions requiring writing: contracts that can't be completed within one year, real estate, guaranteeing another's debt, large goods sales (often >$500, varies by state). Everyday service work is fine verbally.
- The real problem is **proof**, not validity. Courts look for: texts/follow-up confirmations, call logs, witnesses, partial performance, invoices issued after, recorded calls (where legal).
- Best practice: **immediately confirm verbal terms in writing** ("Per our call, I'll replace the battery today for $320").
- Technical note from same chat: for legal-grade records store timestamps in **local time using `DATETIME` columns** (not `TIMESTAMP`, which converts timezones), with PHP `date.timezone` and MySQL `default-time-zone` both set to `America/Los_Angeles` — matters for invoices, signatures, and SMS-consent audit trails.

### Text-message contracts

*Source: "Text message discussions as legal contracts"*

- **Silence/non-denial is NOT acceptance.** A text thread doesn't become a contract just because the other party didn't push back. (Unanswered accusations can carry evidentiary weight in disputes, but that's a different concept.)
- Texts **can** form a valid contract when the thread clearly shows offer, acceptance, and consideration — courts have upheld text-based agreements. Signals: "deal," "agreed," "yes," specific terms (amount, deadline, exchange), and conduct consistent with acceptance.
- **Contracts don't require signatures** — "I never signed anything" is a weak defense when offer, acceptance, and consideration are documented in texts.

**Collateral case study (gas delivery, $65, phone held as collateral):** Customer accepted gas delivery for $65 with his phone held as collateral for up to two weeks, then missed 14+ documented deadlines. Key takeaways validated in the chat:

- The text thread *was* the contract and the "permission" to sell the collateral — offer, acceptance, consideration, performance were all documented.
- Selling collateral after **repeated non-payment and a documented final written warning** ("pay by 4pm or the phone is sold") is textbook secured-collateral enforcement. Explicitly telling the debtor "the debt is cleared, that is what collateral is for" settled the matter.
- The debtor's counter-claims (police threats, "you accessed my data," "I didn't understand — language barrier") were all defeated by the record: phone kept off/locked, an unlock attempt only to assess resale value, sale to a dealer that openly buys locked phones, and the debtor's own use of a translator throughout (undermining "I didn't understand").
- Oregon small claims court handles disputes up to **$10,000** without a lawyer.
- Practical lessons: screenshot and back up entire threads; follow through on every stated deadline; keep evidence of good-faith effort (e.g., dealers who *refused* to buy the locked phone proved diminished value); once settled, **stop responding**.

---

## 4. Payment Protection for a Mobile Mechanic (No Possession = No Leverage)

*Source: "Mobile Mechanic Payment Protection", "Fault and payment question"*

**Myth corrected: it is NOT illegal to collect payment before work is performed.** Prepayment, deposits, and retainers are legal in the U.S. including Oregon, as long as the customer isn't misled and consumer-protection rules are followed.

The ten protections (since mobile mechanics never take possession of the vehicle):

1. **Call-out / service fee up front** — paid before dispatch; filters bad customers.
2. **Deposits (30–70%) on higher-risk jobs** — non-refundable once parts are purchased.
3. **Written authorization, never just verbal** — digital signature, SMS "Reply YES to approve $XXX," or signed estimate; include the $200/10% threshold rule.
4. **Phase the job**: diagnostic (paid) → repair authorization + deposit → completion + final payment.
5. **Practical leverage without possession** — keep keys until paid; don't complete reassembly/hand over an operable vehicle before payment.
6. **Immediate payment methods** (Square tap-to-pay, pay-now links) — no IOUs.
7. **Chargeback packet**: estimate, approval, before/after photos, work notes, timestamp/location.
8. **Stop-work terms**: work stops if payment/approval isn't received; customer owes for work completed; installed parts must be paid before release.
9. **Price for risk** quietly (slightly higher labor rate, call-out structure).
10. **Mechanic's liens are weak without possession** — last resort only, not a frontline defense.

### Call-out fee benchmarks (Portland market)

- $50–$75 budget/high-volume; **$65–$95 the sweet spot**; $100–$150+ premium/after-hours.
- Rule of thumb: call-out ≈ 0.5 hr of labor. At WKR's ~$125/hr rate → **$65–$85 optimal; $65 is the recommended baseline**, waived/reduced for fleet or large jobs, increased after-hours or out-of-area.
- Frame it as "on-site service fee / mobile dispatch fee — covers travel, setup, and bringing tools to you," not a bare "fee."

### Failed jumpstart = still billable

2025 Ram with a battery too damaged to accept a jump: the no-start cause is the **customer's vehicle condition**, not the service. The call is billable if the service is defined as "respond, test/attempt, and report," not "guaranteed start." Work-order language adopted: *"Jumpstart/No-Start Attempts: fee applies to response and diagnostic/attempted start. Service does not guarantee vehicle will start if battery, cables, starter, immobilizer, or other vehicle faults are present."* Only waive if the service was misdescribed ("guaranteed start") or WKR's own equipment failed.

### Preauthorization capability by processor

- **Square POS app: built-in "Pre-authorize" flow** (bar-tab style) — best no-code option; also supports card-on-file.
- **Clover**: real preauth/capture, but full-POS oriented.
- **Stripe**: excellent auth-then-capture (`capture_method: manual` PaymentIntents, capture ≤ authorized amount later) but requires building an integration; never embed the secret key in a client app — use a small backend. Stripe Terminal recommended for in-person tap/chip (lower fees, less fraud than keyed entry).
- PayPal Zettle, SumUp: no clean preauth — not suitable. Shopify POS: possible but awkward.

### Service document lifecycle (dispute protection by design)

Full chain: **INT → SER → EST → EAP → WOR → (DSP) → (COS) → SCR → INV → PAY → RCT → PTW** (Intake, Service Request, Estimate, Estimate Approval, Work Order, Dispatch, Change of Service, Service Completion Report, Invoice, Payment, Receipt, Parts Warranty). Each layer protects differently: SER = operational clarity; EST+EAP = legal authorization; WOR+SCR = proof of work; INV+PAY+RCT = financial enforcement; COS = scope-creep protection. Non-negotiable rules: no work without EAP; changes >$200 or >10% require a COS with fresh approval; invoice requires VIN (unless "no vehicle serviced" flag) and a completed SCR; documents are versioned (V1, V2) and immutable once finalized; receipt auto-generates on payment. Document numbering: `DOC-YYYYMMDD-###-V#`.

---

## 5. Terms of Service / Privacy Policy Decisions

*Source: "Privacy Policy Terms Template"*

Final published documents (dated January 26, 2026) for wkrllc.com. Business identity used: **White Knight Roadside, 2455 NW Nicolai St., B-E1, Portland, OR 97210, Admin@wkrllc.com**. Both were produced as PDFs for the site.

### Privacy policy — core decisions

- **"We do not sell personal information"** and **no sharing unless immediately necessary to complete the service request**, minimum-necessary principle, no advertising/data-broker disclosure. One unavoidable carve-out kept: disclosure required by law/court order/safety.
- Named vendors (shared only as needed): **Square** (payments; no full card numbers stored on WKR systems), **SiteGround** (hosting), **AT&T** (communications), **AutoZone / O'Reilly** (parts the customer authorizes), **Google/Alphabet** (maps/security tools only when a used feature requires it).
- **Oregon Consumer Privacy Act (OCPA)** (effective July 1, 2024): Jason determined **OCPA does not apply** to WKR at current scale. Final published wording (no fourth-wall drafting): *"Based on our current operations, we do not believe the Oregon Consumer Privacy Act (OCPA) applies to White Knight Roadside at this time. If legal obligations change, we will update this Privacy Policy accordingly."*
- SMS: **service updates only** (opted-in), STOP to opt out; marketing texts would require prior express written consent (TCPA).

### Terms of Service — the possession problem and its fix

This was the pivotal legal analysis in the file:

- **Possession = actual physical control, not ownership.** Roadside at the customer's location or on a **public street**, the *customer* stays in possession; WKR has only temporary custody while actively working. Only when a vehicle is left in WKR's controlled custody (behind a gate, towed to storage) does WKR possess it.
- Oregon's possessory lien (ORS 87.152 area) attaches only to a chattel **"in the possession of the person"** — the lienholder "may retain possession until the charges are paid." **On a public street, "we will hold your vehicle until paid" is unenforceable in practice** and risky (blocking/immobilizing could be unlawful). Jason's directive: *"I do not want any unenforceable verbiage anywhere ever related to anything I do. Period."*
- **Resulting policy: since WKR does not take possession, all authorized work including labor is paid in advance.** Confirmed generally legal in Oregon, provided: estimate + authorization before work begins; the >10%/$200 separate-authorization rule is followed (each additional authorized amount also prepaid); and **undelivered services are refunded** on request (minus clearly disclosed good-faith retentions like a dispatch/cancellation fee or special-order parts already purchased). Taking money and not delivering is an unlawful trade practice.

### Final Terms §3 structure (roadside-safe)

1. Pre-inspection pricing is an **estimate**; conditions can change.
2. **Separate authorization** for changes >10% or >$200 (whichever is less) — statutory.
3. **Written approval policy** (stricter than statute, which also allows documented phone assent): signature, email, or text showing name and date/time.
4. **STOP-WORK if not approved** — all work stops immediately; nothing beyond what was authorized.
5. Customer pays for **authorized work already performed** and parts already installed/used.
6. **No vehicle-retention claims** for roadside/public locations — instead, lawful payment remedies (invoice, documented debt, collections).
7. **Installed and special-order parts are non-returnable** (except as required by law/manufacturer warranty); installed parts become part of the vehicle.
8. Possessory-lien rights reserved **only** for vehicles actually in WKR's lawful custody.
9. Governing law: **Oregon**; venue: Oregon state/federal courts.

### Authorization sentence interpretation

*Source: "Service Authorization and Liability"* — The form line *"I authorize White Knight Roadside to perform the services above and assume liability for site access"* means: (1) customer okays the listed work; (2) customer is responsible for lawful access to the location (property-owner/HOA permission, gate codes, escorts, tow-yard authorization) and bears problems arising from lacking permission. It does **not** waive WKR's own negligence or damage it causes. Clearer suggested wording: *"I authorize White Knight Roadside to perform the services listed above. I confirm I have permission to request service at the service location and I am responsible for providing/obtaining access (e.g., gate codes, parking permission, security approval)."*

### Damages vocabulary

*Source: "Consequential vs incidental damage"* — **Direct damages** = fix the actual thing that went wrong. **Incidental damages** = reasonable cleanup costs of dealing with the breach (return shipping, replacement sourcing). **Consequential damages** = ripple-effect losses (lost jobs, lost profit because a part arrived late). Standard contract clause "not liable for consequential or incidental damages" limits claims to the direct thing (refund/replacement). Reviewing a Walmart vision warranty, the takeaway for WKR's own customer-facing docs: exclusions like this are standard-fair, but to look customer-first, (1) allow receipt lookup, and (2) make repair-vs-replace decisions transparent.

### Tow bill after a failed repair

*Source: "Tow Bill Coverage After Repair"* — Customer usually pays the tow up front, then recovers from whoever is responsible. **The shop owes the tow** when the breakdown was caused by its bad work/diagnosis/defective part — the tow becomes part of the customer's damages. **The customer eats it** when the failure is unrelated, a different system, or the warranty excludes towing (many do). Insurance/AAA/credit-card roadside may cover it, with reimbursement pursued afterward. Document: tow receipt, invoice, photos, codes, the failed part. Oregon requires shops to provide written estimates/invoices documenting work and parts — this documentation matters in disputes. (California's Bureau of Automotive Repair mediates such complaints — relevant reference if working CA jobs.)

---

## 6. Out-of-State Service and Taxes

*Source: "Out-of-State Service Taxes"*

**Core rule: sales tax is NOT based on the Portland business address.** Oregon has no general sales tax, so there is no Oregon tax to "carry" into another state. For interstate jobs, the destination state's rules control:

1. Look at the **state where the job happens**.
2. Check whether that state **taxes that kind of work** (labor-only repair vs. installation vs. parts vs. towing vs. fuel vs. lockout).
3. Check whether **registration** there is required before collecting (physical presence can trigger it immediately; economic-nexus thresholds also exist).

State specifics captured:

- **Washington:** tax sourced to **where the customer receives the service**; labor/services coded to where performed; installation charges coded to where installed. **Special rule: automobile towing is sourced to the business location, not the customer location.** WA can require registration based on physical presence or WA-sourced receipts. WA publishes a downloadable sales-tax rate database (updated quarterly) for custom applications.
- **California:** out-of-state businesses "engaged in business" in CA may need to register; CA generally treats **parts as taxable** while **separately stated repair/installation labor can be nontaxable**. Doing business there for financial gain can subject you to CA tax laws.
- **Invoice practice:** always line-item **labor / installed parts / fuel product / towing / fees separately** so only the taxable portion gets taxed.
- Out-of-state work can also create **non-sales-tax obligations** (registration, income/B&O tax).

### Tax automation decisions

- Don't hardcode rates. The app decides *what was sold and where*; a tax engine decides *how much tax*.
- Paid engines evaluated: **Avalara AvaTax** (best for mobile multi-jurisdiction; address/geolocation-based), **Stripe Tax** (works even with non-Stripe processors), **TaxJar** (lighter API). None have a permanent free tier.
- **Free path:** **TaxCloud via Streamlined Sales Tax (SST)** — CSP services are free in the 24 SST member states for sellers qualifying as "CSP-compensated" (registered through SST, no fixed place of business in the state >30 days, under $50,000 property/payroll there, remote-seller economic nexus only). Washington is an SST member state. Not free outside SST states or where the seller doesn't qualify.
- **Product decision for the app (must be sellable without a forced paid service):** provider-agnostic tax module with three modes — (1) free built-in engine using official SST rate/boundary files and taxability matrices plus WA's published database; (2) SST/TaxCloud mode where eligible; (3) optional premium adapters (Avalara/Stripe Tax/TaxJar). Per-state flags: `tax_registration_status` and `tax_collection_enabled` — **never collect tax in a state where not registered**, even if the API can compute a number. Calculate at quote and again at final invoice; commit only when finalized; store jurisdiction snapshot and raw API responses for audit; push final totals into Square. Design directive: make it universal (not Oregon-centric) and aimed at the towing/roadside industry.

---

## 7. LLC Compliance and Local Registration (Oregon / Portland)

*Source: "LLC Registration Assistance"*

Situation: WKR LLC's Oregon registration was out of date and it had never registered with Portland. Catch-up plan:

1. **Oregon LLC Annual Report** (most urgent) — due each year on the LLC's **anniversary date** via the Oregon Secretary of State Corporation Division; prolonged lapse leads to **administrative dissolution** requiring reinstatement. Check status via the Oregon Business Search (Status: Active / Inactive / Administratively dissolved / Inactive due to failure to renew).
2. **City of Portland / Multnomah County** — businesses operating in Portland must **register with the Revenue Division and file annual returns even when exempt**. Registration is free, but filing is still required to claim the exemption; not filing risks penalties or estimated assessments.
3. **Exemption thresholds (tax year 2026):** Portland Business License Tax — total gross receipts **under $75,000** (all business activity everywhere); Multnomah County Business Income Tax — **under $100,000**. WKR is well under both, so catch-up is mostly late filings, not tax bills.
4. **Record reconstruction:** Square transaction history is the primary income record (WKR's only active account is the Square merchant account); supplement with bank statements, cash-job estimates, and expense receipts (fuel, parts, tools, insurance, phone). Agencies accept honest, reasonable reconstruction from small businesses coming back into compliance; contacting the Revenue Division proactively reduces pain.

---

## 8. Trademark — Scam Alert and Real Costs

*Source: "Scam Trademark Alert"*

- **The "Prime Mark Registry" text ("a third party has contacted us to trademark White Knight Roadside LLC…") is a known solicitation/scam script.** The USPTO specifically warns about companies using alarming "someone is registering your name" claims to sell services. Never respond to the solicitor; verify any claimed application directly at the official USPTO trademark search (tmsearch.uspto.gov).
- WKR likely already has **common-law trademark rights** from use in commerce even without federal registration.
- **Real costs:** USPTO filing fee ~**$350 per class** (using standard descriptions); attorney optional at $500–$2,000+; search $0–$500 (DIY free); maintenance filings between years 5–6, ~year 10, then every 10 years. DIY total ≈ $350; with attorney ≈ $1,000–$2,500.
- **Correct class: International Class 37** (vehicle repair, maintenance, roadside assistance — jump starts, lockouts, tire changes, fuel delivery, battery replacement, mobile mechanic services). One class suffices since no products are sold under the brand.
- Preliminary search found **no federal registration of "White Knight Roadside"**; existing "White Knight" marks (bicycles, health) are in unrelated industries — confusion unlikely. Estimated ~8–9/10 registration odds. Before filing: full clearance search (federal, Oregon registrations, Google/Maps listings, domains, common-law use) and documentation of first-use date (website, invoices, Google Business Profile, receipts).

---

## 9. Software Copyright & Licensing (Maximum Enforcement)

*Source: "Copyright license for suing"*

To retain the ability to sue over unauthorized use/modification of WKR's software:

- **Do NOT use open-source licenses.** MIT/BSD/Apache permit reuse; GPL/AGPL only force share-back. Use a **custom proprietary license, "All Rights Reserved."**
- License must explicitly forbid copying, modification, reverse-engineering, decompiling, sublicensing, distribution, hosting, and derivative works without prior written consent; state the license terminates on breach; reference **17 U.S.C. §§ 501–505**.
- **Register the copyright with the U.S. Copyright Office** (~$45–$65). Registration is what unlocks **statutory damages up to $150,000 per willful infringement plus attorney's fees** — ownership alone doesn't.
- Enforcement ladder: collect evidence → cease & desist → **DMCA takedown** (GitHub, Google, hosts — forces removal before court) → federal suit.
- Practices adopted: copyright header in **every source file** ("Copyright © White Knight Roadside, LLC. All Rights Reserved… prosecuted to the fullest extent of the law"), a repo `LICENSE.txt` ("This software is LICENSED, not SOLD"), private repositories, license keys/API auth, monitoring code-sharing platforms. Licensing contact placeholder used: licensing@wkrllc.com.

---

## 10. Miscellaneous

- **Verify AI legal/tax claims** (*"Verifying AI accuracy and reliability"*): AI is a starting point, not a source — cross-reference statutes, agency sites, and professionals, especially for statistics, jurisdiction-specific legal/tax specifics, and anything post-knowledge-cutoff. Confident-sounding answers can be wrong. This applies to every item in this document.
- **wkrllc.com availability issue** (*"Website loading issue for wkrllc.com"*): the site is hosted on **SiteGround**, whose platform-level Anti-Bot AI (sgcaptcha challenge, HTTP 202 + `x-robots-tag: noindex`) was blocking legitimate visitors and potentially Googlebot. Not fixable via .htaccess/files — requires a SiteGround support ticket to relax anti-bot sensitivity and confirm crawler whitelisting; check Google Search Console live URL test. Kept here because de-indexing and blocked customers have business-liability implications for a dispatch business.

---

*Compiled 2026-07-29 from exported AI chat conversations. All statutes (ORS 646A.480–495, ORS 746.292, ORS 87.152, 17 U.S.C. §§ 501–505), thresholds, and legal conclusions should be independently verified with a qualified professional before acting on them.*
