# Diagnostics & Job Pricing — Reference Notes (Bin: diagnostics-1)

Distilled from 6 exported ChatGPT conversations. Core recurring facts: **WKR shop labor rate is $125/hr**; drive/travel time is billed at a reduced rate; quotes are built as parts + book-time labor + explicit travel/mileage line items.

---

## 1. Salvage Yard Trip Pricing — The Settled Policy
*Source: "Salvage Yard Trip Pricing"*

This conversation produced Jason's standing policy for billing salvage-yard parts runs. The numbers evolved (80% drive rate was considered first) and the **final settled structure** is:

### Final rate structure
| Component | Rate | Notes |
|---|---|---|
| Shop labor rate | **$125.00/hr** | Hands-on wrench time, including pulling the part at the yard |
| Drive time | **65% of shop rate = $81.25/hr** | Billed door-to-door (portal-to-portal), round trip. Invoice explicitly states "billed at 65% of shop labor rate" |
| Mileage | **$0.72/mi** | Round-trip miles, separate line item from drive time |
| Yard entry / pull / core fees | At cost | Only with customer approval |

Key principles established:
- **Drive time and mileage are separate line items** — both are billed, broken down explicitly (e.g., "60 min drive time" + "30 mi mileage"). Jason insisted on line-item-by-line-item breakdown, not a bundled fee.
- The 65%/reduced rate applies **only to driving**. Wrenching at the junkyard (pulling the part) is skilled labor and stays at the **full $125/hr** — Jason corrected the AI twice on this point.
- **"Payment is for the attempt"** — the trip fee applies whether or not the part is found/usable, same logic as diagnostic fees. Terms wording: *"Labor and travel are billed for time spent; payment is for the attempt/time, not a guarantee of yard inventory/condition."*
- Used/salvage parts sold **as-is** unless otherwise stated.
- Recommended safeguards: pre-authorization / not-to-exceed cap ("NTE $___ without approval"), call/text from the yard before exceeding the cap.
- Industry-benchmark context gathered: portal-to-portal billing is standard field-service practice; IRS business mileage rate was 72.5 cents/mi (2026) as an "official-sounding" baseline; typical mobile-mechanic patterns are hourly + call-out fee + per-mile beyond a radius.

### Worked example (template invoice) — 1994 Ford Ranger 2WD, front driver-side steering knuckle
Typical run: 30 miles round trip, 60 minutes drive time.

| Qty | Description | Rate | Total |
|---|---|---|---|
| 1 | Used part: steering knuckle (front left) | $42.50 | $42.50 |
| 1.0 hr | Drive time (round trip) — 65% of shop rate | $81.25/hr | $81.25 |
| 30 mi | Mileage (round trip) | $0.72/mi | $21.60 |
| 1.0 hr | Junkyard labor: remove knuckle (2WD, one side) | $125.00/hr | $125.00 |
| | **Total** | | **$270.35** |

### 1994 Ford Ranger steering knuckle — labor time reference
- Book R&R (one side, does NOT include alignment): **2WD: 1.9 hr** (bill 2.0), **4WD: 2.5 hr**.
- Alignment add-ons: front toe adjust 0.4 hr; full front alignment 2.3 hr.
- **Removal-only at a junkyard** (what Jason actually needed): 2WD (Twin I-Beam) typical **0.75–1.0 hr**, stuck/rusty 1.5–2.0 hr; 4WD (TTB) typical 1.0–1.5 hr, stuck 1.5–3.0 hr. The wildcard is separating the spindle from the knuckle (can be 10 minutes or 2+ hours of fighting).
- Billing rule adopted: **1.0 hr minimum for a 2WD knuckle pull**, overage at actual in 15-min increments, NTE 2.0 hr without approval.

### Side product from the same conversation
The bulk of the conversation (~90% by length) was a long, frustrating attempt to design a **printed WKR Service Request form** (PDF). Reusable detail: the service checkboxes should be **Tire Change, Tire Replacement, Jump Start, Fuel Delivery, Lockout, Mobile Mechanic, Other ____**; the form has vehicle info card (color/plate/state), a location card with map area, and an authorization/signature card. The PDF work itself never completed satisfactorily in-chat and was handed off as markdown instructions for another agent.

---

## 2. Lincoln LS — Confirming a Bad Electronic Throttle Body
*Source: "Confirming Throttle Body Failure"*

Vehicle: Lincoln LS (3.0 V6 or 3.9 V8, drive-by-wire electronic throttle). Code set on the actual car: **P2104, P2111, P2112, P2135** — a classic throttle-body-centered failure pattern, not wiring alone. A bad throttle body **can cause crank/no-start** on this platform: the PCM withholds fuel if throttle angle is implausible, TPS correlation fails, or the plate is stuck.

### Confirmation sequence (mechanical → electrical → PCM logic)
1. **Mechanical (key off):** remove intake duct, rotate plate by hand. Must move smoothly and snap closed cleanly. Sticking, grit, or failure to fully close = throttle body bad, stop here.
2. **Carbon check:** heavy carbon at plate edges/bore causes P2111/P2112 and P2104 failsafe. Clean with throttle body cleaner only (do not force the plate), attempt idle relearn. Codes return → keep testing.
3. **TPS correlation (critical):** the LS throttle body has **two internal TPS sensors that must mirror each other inversely** (TPS A rises smoothly, TPS B falls smoothly). Backprobe, key on/engine off, move plate slowly by hand. Voltage jumps, dead spots, or broken correlation → **P2135, which on this car almost always means internal throttle body failure**.
4. **Actuator motor:** resistance across motor pins, key off — typical **2–25 Ω**, stable. Open/very high/inconsistent = fail. With a scan tool, command throttle and compare commanded vs actual angle; lag, no movement, or snapping shut into failsafe = fail.
5. **Harness sanity check** before condemning: inspect connector for corrosion, spread terminals, oil intrusion (common on LS); wiggle-test while monitoring TPS voltage. Wiring clean + repeating codes → throttle body is guilty.

Replacement notes: **OEM/Motorcraft only** (aftermarket units cause repeat failures on LS). After install: idle relearn + clear KAM (battery disconnect 15–30 min).

### How to charge for this class of diagnostic (adopted policy)
- Name it on the invoice: **"Electronic Throttle Control Diagnostic"** — never "checking it out" or "scan fee." Wording implies process, not opinion.
- Flat tiers: **Base Diagnostic $125** (scan, code interpretation, visual, basic electrical) / **Advanced Diagnostic $175–$225** (live data, correlation testing, actuator testing, mechanical verification). This job = Advanced, **charge $175**.
- Invoice/verbal scope statement: *"This diagnostic fee covers systematic testing of the electronic throttle control system to identify whether the fault is caused by the throttle body, sensors, wiring, or control logic. The fee applies regardless of the final repair decision."*
- Optional credit policy: diagnostic fee credited toward the repair if performed same visit or within X days.
- Time-based fallback for wiring nightmares: *"Advanced diagnostics beyond the initial scope are billed at $125/hr with customer approval."*
- Customer one-liner that works: *"Modern cars don't fail in obvious ways anymore. The diagnostic IS the work — I'm proving what's failed so you don't replace the wrong part."*
- Don't waive because "it was obvious"; don't bury it in labor.

(The remainder of this conversation drifted to personal topics — no business content.)

---

## 3. 2004 Honda CR-V — No/Slipping Reverse After Sitting a Year
*Source: "Honda CR-V reverse issue"*

Case facts: 2004 CR-V automatic, **256,000 miles**, parked ~1 year *because* it was slipping and losing power. Now reverse engages after 3–5 seconds and slips badly once engaged.

### Diagnostic logic (reusable for Honda automatics)
- **Delayed reverse (3–5 s)** = low line pressure or sticky valve (varnish from sitting).
- **Reverse that engages but SLIPS** = worn reverse clutch pack (reverse has its own clutch pack, separate from forward gears). This is internal wear, not just fluid.
- With prior forward slipping + 256k + a year of sitting: transmission is internally worn out. Fluid/solenoid service is a **band-aid**, realistic improvement 10–30% at best, possibly 0%. Verdict path: try drain & fill + linear solenoid clean once; if slipping persists, it's a **used transmission swap** (junkyard trans $300–$600, labor $500–$900 shop-typical — cheaper than a rebuild on these).
- Fluid condition read: pink/red = OK; dark brown = worn; black/burnt smell = near end of life; metallic glitter = internal damage; milky = water.

### Service facts (2004 CR-V automatic)
- Fluid: **Honda ATF DW-1 (or older Z1) ONLY** — anything else causes clutch problems in Hondas. **Drain & fill, never flush.** Refill ≈ **3.3–3.5 qt**; repeat 2–3 times over a week for a full exchange. Check level engine idling, in Park, level ground.
- Drain plug takes a **3/8" square ratchet drive directly**; torque **~36 lb-ft (49 N·m)**; new crush washer.
- **No serviceable pan filter.** Internal screen filter requires transmission teardown — leave it alone. External inline ATF filter (Honda **25430-PLR-003**, ~$20, 0.3 hr) is a maintenance item only, won't fix slipping; usually skipped.
- **Linear pressure control solenoid** (clutch pressure control solenoid): bolted to the front (radiator side) of the transmission below the intake tube. Controls line pressure → directly causes delayed/weak reverse when varnished. Remove (**4 × 10mm bolts** — note: the 3-bolt block is the *shift solenoid A/B assembly*, a different part; the AI initially said 3 and was corrected), unplug one connector, clean the fine mesh screens with brake cleaner (never scrape), reinstall. Bolt torque ~8–12 lb-ft, snug and even.
- **Gasket is reusable**: rubberized molded-metal multi-port gasket, designed for service. Replace only if torn/cracked/swollen/RTV'd. Part: **28262-P7W-003**, $12–$18 dealer.

### Final quote as issued (WKR format)
2004 Honda CR-V, drain & fill + linear solenoid clean, no filter:
| Item | Qty/Time | Rate | Total |
|---|---|---|---|
| Honda ATF DW-1 | 4 qt | $12.50/qt | $50.00 |
| Brake cleaner / shop supplies | — | — | $8.00 |
| Labor: ATF drain & fill | **0.7 hr** | $125/hr | $87.50 |
| Labor: remove & clean linear pressure solenoid | **0.9 hr** | $125/hr | $112.50 |
| | | **Total** | **$258.00** |

Customer-facing disclaimer used: service aims to restore hydraulic pressure/shift quality; internal clutch wear is suspected at this mileage; may improve drivability but **cannot reverse internal wear**; transmission replacement may still be required.

### Quick references captured in the same chat
- **Automatic transmission lifespan** (general): 150k–250k miles typical.
- **2008 Honda Civic tire mismatch on rear axle:** FWD/open diff, so front-vs-rear size differences are tolerated if each axle matches side-to-side. Left/right on the same axle must match diameter (even 3–4% difference triggers ABS/VSA issues). Overall diameter difference guide: 0–2% fine, 3–5% okay-not-ideal, 6%+ not recommended.
- **Bolt patterns:** 2008 Civic (all trims): **5×114.3**, center bore 64.1 mm, lugs 12×1.5. Subaru Outback: **5×100 (1995–2014)**, **5×114.3 (2015+)**. Subaru Forester: **5×100 (1998–2018)**, **5×114.3 (2019+)**; Subaru center bore 56.1 mm, lugs 12×1.25.

---

## 4. 2015 Kia Soul + — Ignition Lock Cylinder Replacement Quote
*Source: "Ignition lock replacement quote"*

Vehicle: 2015 Kia Soul + 2.0L GDI, VIN KNDJP3A59F7794086, OR plate 521NQR. Non-push-button start; vehicle **confirmed to have the Kia anti-theft ignition cylinder protector** installed.

### Part selection (production-date sensitive — the key lesson)
Kia PS-generation Soul cylinder sets are split by build window; a 2015 needs the **08/2013–08/2016** production parts:
- **81905-B2000** — key & cylinder set, no push-button start, **straight (non-folding) key** (~$115–$153)
- **81905-B2110** — key & cylinder set, no push-button start, **folding/flip key** (~$275–$285) ← the part chosen for this job
- **81905-B2111** — same description but **late-production only (12/2017–12/2018)** — wrong for a 2015; the AI first offered it and had to be corrected against production date
- 81905-B2300 shows in some listings (certain trims/EVs) — have sellers confirm by VIN
- Lesson: **always verify Kia part numbers against the vehicle's production-date window, not just model year.**

### Anti-theft protector complication (CS2311 / 9A5 campaign)
Kia's theft-deterrent campaign bonds a metal protector sleeve over the lock cylinder with **epoxy and break-off screws** — intended to be permanent. Removing the cylinder therefore normally requires **replacing the whole lock housing**:
- **81910-B2100** — lock housing, w/o push-button start (~$50–$53)
- **81919-31000** — one-time-use shear bolts, **Qty 2** (~$3.60–$5.20 each)
- Adds **~1.0 hr labor** on top of the base job.

### Labor & quote structure
- Base ignition cylinder R&R: **0.8 hr** (book ~1 hr; WKR quotes 0.8 when the cylinder releases normally) → $100 at $125/hr.
- With protector/housing swap: **1.8 hr × $125 = $225.00**.
- Key cutting (2 blades): $30. Optional bench rekey to match existing door key: +$85 (instead of cutting). Most 2014–2015 US Souls without push-button start have **no immobilizer chip** — cutting blades is sufficient, no programming; the CS2311 bulletin itself notes affected vehicles lacked immobilizers.

**Final arrangement (as invoiced):** customer supplies all parts; no door rekey.
- **Labor only: $225.00** (remove protector, replace housing, install 81905-B2110)
- Customer orders: 81905-B2110 ($275–285) + 81910-B2100 ($50–53) + 2× 81919-31000 ($7.20–10.40) = **Estimated parts total $332–$348** before shipping.

(Process note: several rounds were lost getting the AI to print "$" on every money amount in the PDF — worth specifying up front in any future estimate-generation prompt.)

---

## 5. 2011 Kia Soul 1.6L — Timing Chain Replacement Quote
*Source: "Timing chain replacement quote"*

Vehicle: 2011 Kia Soul, **1.6L Gamma (G4FC)** — explicitly not the 2.0L. This engine uses a **timing chain, not a belt**, and is **interference-type**: if the chain has already jumped/failed, valve-piston contact inspection is required and head/valve work is extra (disclose this on the quote).

### Quote as finalized
- **Labor: 8.0 hr × $125 = $1,000.00.** Book guides show ~5.4–5.5 hr; Jason deliberately quoted **8.0 hours** to cover real-world conditions (rusted fasteners, engine-mount removal, mobile setting). Useful precedent: he pads book time ~45% on major engine jobs.
- **Parts ($328.00):**
  - Timing chain kit $225 — OEM PNs: chain **24321-2B000**, tensioner **24420-2B000**, guides **24410-25001** & **24431-2B000** (aftermarket kits run ~$110–$150 if cost-cutting)
  - Valve cover gasket $25
  - Front crankshaft seal $15
  - RTV for timing cover $18 — front cover is **RTV-sealed (Loctite 5900 or equivalent)** per factory procedure, no gasket
  - Engine oil & filter $45 (oil change included in scope)
- **Estimated total: $1,328.00** before tax. Optional add-ons (removed from the final version at Jason's request): serpentine belt +$35, crank pulley bolt (TTY/replace-if-specified) +$12.
- Scope included: chain/guides/tensioner R&R, front cover reseal, oil & filter, timing verification, road test. Excluded: VVT actuator/sprockets, tax/fees, towing.

Branding note: WKR quote PDFs use the wkrllc.com identity — White Knight Roadside, LLC, phone (503) 764-3154, dark/neon "Indie Neon" palette (bg #0B0F14, panel #121826, cyan #6AE2FF, purple #B388FF) with a **white header background** behind the logo (per Jason's correction).

---

## 6. Towing With a Tow Dolly — Oregon Legal/Cost Requirements
*Source: "Towing with tow dolly requirements" (based on Jason's uploaded ORS 822.200 packet + Oregon DMV Title & Registration Handbook, Ch. O, Jan 2024)*

Situation: Jason bought a used tow dolly with **no VIN and no bill of sale** and wants to tow customers' vehicles (for compensation) as part of WKR.

### Core legal requirements (Oregon)
- Towing **for any compensation** (even with a dolly — the law targets the activity, not the equipment) requires a **DMV Tow Business Certificate**. Operating without one = **operating an illegal towing business, Class A misdemeanor** (up to 364 days / $6,250 fine) plus **civil penalties up to $25,000 per violation** from the State Board of Towing (ORS 822.200/822.995).
- **Exempt** if only towing your own vehicles or uncompensated Good Samaritan help (also: vehicle transporter cert holders, dismantlers under ORS 819.280).
- **TW plates** required on each tow vehicle; non-transferable; must be removed when a vehicle is sold/retired. **Title, registration, and tow certificate must all be in the same name.** Certificate renews annually.
- **Insurance:** motor-carrier liability minimum **$750,000** single limit (OAR 740-040-0020) **plus $50,000 cargo coverage** (ORS 822.205). Insurer must be Oregon-licensed; DMV notified of cancellation. Certificate of insurance must list the VIN.
- Equipment must meet DMV/ODOT "minimum safety standards." **Important verification note:** the detailed equipment lists the AI initially produced (fire extinguisher specs, tread depths, etc.) were *not* in the source document — the statute only requires compliance with DMV/ODOT minimum standards generally. When pressed for sources, the AI retracted the granular specs. Treat any specific equipment checklist as unverified.
- False statements on the certification = false swearing, Class C felony (ORS 822.605).

### Path for a dolly with no VIN and no bill of sale
1. Establish ownership: **Affidavit of Ownership (Form 735-550)** or **Homemade Trailer Certification (Form 735-230)**; possibly bonded title if ownership is doubtful.
2. **Apply for Assigned VIN — Form 735-226, fee $98 (one-time)**; DMV/State Police inspection, then a state VIN plate is affixed. Bring photos, any serial marks, possibly a weight slip.
3. Title & register the dolly; then apply for the **Tow Business Certificate (Form 387)** and TW plates.
4. DMV cannot issue TW plates or the certificate until the dolly has a VIN and is titled in Jason's name.

### Fees (per DMV Handbook Ch. O, 2024)
- Tow Business Certificate: **$117/vehicle/year** (includes $100 State Board of Towing fee, effective 1/1/2024)
- TW plate issuance: ~$24–$30
- Registration: based on tow vehicle weight (light vehicles ~$126–$152 biennial); combined weight over 26,000 lbs triggers weight-mile tax / ODOT CCD registration
- Assigned VIN: $98 one-time
- **Year-1 total ≈ $310–$390; ongoing ≈ $180–$195/yr.** Cities/counties may add local fees under ORS 822.230.

---

## Cross-cutting takeaways
- **Standing rates:** shop labor $125/hr; drive time 65% of shop rate ($81.25/hr); mileage $0.72/mi; diagnostic tiers $125 base / $175–225 advanced.
- **Quote anatomy WKR uses:** separate line items for parts (with PNs and price ranges), each labor operation with hours × rate, travel time and mileage broken out, contingencies priced ("if protector present +X"), and an attempt-not-outcome / final-may-vary disclaimer.
- **Labor-hour precedents:** cylinder R&R 0.8 hr; +1.0 hr for Kia anti-theft protector; ATF drain & fill 0.7 hr; linear solenoid clean 0.9 hr; timing chain quoted at 8.0 hr vs 5.5 book; 2WD Ranger knuckle yard pull 1.0 hr minimum.
- **Part-number verification habit:** confirm Kia parts by production-date window and VIN, not model year alone.
