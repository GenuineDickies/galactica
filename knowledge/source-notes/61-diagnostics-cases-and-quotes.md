# Diagnostics & Quotes — Reference Notes (Bin: diagnostics-2)

Distilled from 16 exported chats covering per-case vehicle diagnostics, quote structures, checklists, the mobile diagnostic LLM prompt, and equipment setups. Each item is attributed to its source conversation title.

---

## 1. Case Diagnostics

### Misfire on 2018 Thor Majestic 28A (Ford E-450, 6.8L Triton V10)
*Source: "Misfire Diagnostics Analysis"*

**Codes:** P0302 (cyl 2), P0306 (cyl 6), P0300 (random) — all pending.

**Freeze frame:** 196°F coolant (fully warm), 1,621 RPM, 7 mph, 88.2% calculated load, closed loop, 13.9V charging. **STFT B1 +23.4%, LTFT B1 +7%; STFT B2 -2.3%, LTFT B2 +2.3%.**

**Key interpretation:** Bank 1 trims strongly positive, Bank 2 near normal — but cyl 2 is Bank 1 and cyl 6 is Bank 2, so not a simple bank-wide vacuum leak. A misfire dumps unburned oxygen into the exhaust, making the O2 sensor read lean, so the +23.4% STFT alone does NOT prove a vacuum leak.

**Ranked causes:** ignition coils 45% (COP failure is very common on the 6.8 V10, heat-related intermittents), plugs/plug wells 25% (oil in wells, carbon tracking, cracked boots), Bank 1 vacuum leak + weak ignition 15%, injector 10%, mechanical 5%.

**V10 layout:** passenger side front-to-rear 1-2-3-4-5; driver side 6-7-8-9-10. Firing order 1-6-5-10-2-7-3-8-4-9.

**Roadside game plan (don't throw ten coils at it):**
1. Safety first — flashing MIL, violent shake, or raw fuel smell = shut down except brief testing (hard misfires kill cats fast).
2. Check misfire counters / Mode $06 before touching anything; freeze frame was attached to P0302, so cyl 2 is lead suspect.
3. Inspect #2 and #6 coil connectors, boots, springs, wells (water/oil/carbon tracking). Motorhome doghouse heat makes wiring/boot trouble believable.
4. **Coil swap test:** move #2 coil to #3 and #6 coil to #7 (mark them, swap complete coil+boot). Clear codes, reproduce conditions. P0303/P0307 appears = original coil condemned; codes stay put = coils not guilty.
5. Read the plugs: wet = no spark/low compression; chalky white = lean; sooty = weak spark/rich; oily = oil control; steam-cleaned = possible coolant.
6. Listen to injectors #2/#6 with stethoscope vs neighbors; wiggle-test connectors.
7. Check trims live at warm idle and ~2,500 RPM no load: B1 positive at idle improving at 2,500 = vacuum leak; both banks positive under load = fuel delivery; trims jump only during misfire = misfire causing false lean.
8. Vacuum leak hunt on passenger side (PCV, booster hose, manifold seams, MAF-to-throttle duct). Smoke if available — never spray flammable cleaner around a hot motorhome engine.

**Roadside kit for this job:** 2 known-good coils + boots/springs, correct plugs, dielectric grease, plug socket + torque wrench, DMM, scan tool with Mode $06. If swaps don't move the misfire, stop guessing — recommend injector balance, relative compression, leak-down, smoke, or current-ramp testing. Loaded road test before release.

### Brake master cylinder failure during flush (customer job)
*Source: "Automotive Mobile Diagnostic Agent" — the diagnostic-agent prompt applied to a live case*

**Situation:** Unstuck a seized caliper, attempted flush/bleed. System never ran low, worked briefly during the flush, then just stopped pushing fluid. Pedal goes to floor with all bleeders closed; slight resistance that does NOT build with pumping; no visible leaks; no fluid level drop. Fluid was extremely dark (customer saw it).

**Diagnosis logic:**
- Pedal to floor with bleeders closed = not building/holding hydraulic pressure. A blockage makes a HARD pedal, not a sinking one.
- Pumping doesn't firm it + no fluid loss = **master cylinder internal bypass** is prime suspect. Manual bleeding strokes the pedal far past normal travel, dragging seals across corroded/unused bore — classic failure mode on old masters.

**Field tests:**
- *Static pressure hold:* bleeders closed, press and hold 20-30s, watch reservoir and inspect for leaks (including inside firewall at the pedal). Sinks with fluid loss = external leak; sinks with no loss = master suspect; pumps up then fades = air.
- *Master isolation test (definitive):* plug the master outlet ports with proper brake line plugs, press slowly. Rock-hard pedal = master is good, problem is downstream (air, ABS, hose, caliper). Pedal still sinks = master is bad.
- *No plugs? Master output check:* crack one line at the master, helper presses slowly once, watch for a strong clean pulse; tighten BEFORE pedal release. Weak dribble/bubbles = failed or air-locked master.
- Don't keep full-stroking the pedal — it makes a weak master worse. Short controlled strokes only.

**Prevention lessons (foreseeable risk, not certain negligence):**
- Never push the pedal to the floor when bleeding — short strokes, or block of wood under the pedal to limit travel.
- Prefer a pressure bleeder on old systems (no piston over-travel); vacuum bleeder is OK but pulls air past bleeder threads.
- Very dark fluid = neglected system, fragile master — warn the customer BEFORE flushing: "if components are already weak, flushing can expose the failure."

**Customer handling:** The flush *triggered* the failure but didn't damage a healthy system — "the flush exposed the weakness; it did not create the failure from nothing." Own the outcome without falsely confessing certainty. Fair position: customer pays the part (pre-existing weak component), discount/waive some labor and diagnostic time since it happened during service. Don't tell the customer "good thing it failed now" — instead: "it failed under controlled service conditions instead of during normal driving." Document everything: dark fluid observed by customer, no leaks, no level drop, pedal won't build with bleeders closed, failure occurred during flush, vehicle unsafe to drive. Never release it drivable.

**Replacement — matching vacuum vs Hydro-Boost:** you match what's on the vehicle, never convert. Big round black canister behind the master with a thick vacuum hose to the intake = vacuum booster. Smaller unit with power steering pressure hoses running to it = Hydro-Boost. Also match ABS/non-ABS, bore size, reservoir shape/sensor plug, port count/locations. Bench bleed the new master before installing.

### CKP sensor relearn
*Source: "CKP Sensor Relearn Process"*

- A crankshaft position variation relearn (CKP/crank/CASE relearn) teaches the PCM the crank's manufacturing tolerances for accurate misfire detection and timing. Needed after CKP replacement on *some* vehicles (GM notably). Symptoms: CEL after CKP replacement, misfire codes that won't clear, poor idle/hesitation.
- **2000 Jeep Grand Cherokee:** no GM-style scan-tool CASE relearn — PCM learns automatically. Chase wiring, sensor quality, grounds, PCM power, or flexplate tone ring instead.
- **Toyota Camry:** most do not require a manual CKP relearn; ECM learns automatically. "Recalibration" talk usually means clearing learned values, idle relearn after battery disconnect, or fixing an install issue (air gap, damaged reluctor, wiring).

### 2015 Kia Soul — key stuck in ignition, won't turn off
*Source: "Kia Soul ignition issue"*

Common causes in order: steering-wheel lock engaged (most common — rock the wheel left/right while turning the key); shifter/park interlock not sensing Park (cycle Neutral-and-back-to-Park); worn/dirty lock cylinder or worn key (try the spare); ignition switch or shift-lock solenoid electrical failure; anti-theft/recall-related — Kia issued bulletins and a "cylinder protector" campaign for some 2014-15 Souls, so check the VIN with NHTSA/dealer.

Field steps: Park + parking brake → rock wheel while turning key (don't yank) → try spare key → cycle shifter → graphite lock lube only (never WD-40/oil). If the engine won't shut off: secure it and tow — don't disconnect the battery with the engine running (charging system damage risk).

### Testing a 5-pin (SPDT) automotive relay
*Source: "Testing 5 Pole Relay"*

Pins: 85/86 coil, 30 common, 87a NC, 87 NO (schematic on the case).
1. **Coil (85-86) with ohmmeter:** normal ~50-150Ω. OL = open coil; ~0Ω = shorted. Both = bad.
2. **Unpowered contacts:** 30↔87a continuity yes; 30↔87 no. Reversed = faulty.
3. **Energized (85 to ground, 86 to +12V):** should click; then 30↔87 continuity yes, 30↔87a no. Clicks but doesn't switch = burned contacts; no click = dead coil.
4. **Diode-protected relays:** polarity matters (85 = ground, 86 = +12V); reversing blows the diode. Look for the diode symbol.
- **Fast field test:** swap with an identical relay elsewhere on the vehicle (horn, AC). Problem moves = relay bad.
- Fuel-pump circuit logic: if jumping 30→87 runs the pump but the relay won't, it's the relay, missing trigger signal, or bad ground at 85.

### Oil "everywhere" but no drip — seepage diagnosis
*Source: "Oil Seepage Diagnosis"*

Distinguish **active leak** (fresh, forming drops) from **seepage** (light wetness, never drips). Widespread oil with no defined drip point usually means: old residue spread by airflow; multiple minor seep points combining (valve covers, oil pressure sensor, filter adapter gasket, pan corners, rear main); crankcase pressure/PCV restriction pushing mist out; a past overfill/spill; or misidentified trans/PS fluid. Oil travels down and back — **the highest wet point is the source**. Degrease, drive 20-50 miles, reinspect (dye test for a definitive answer).

**Liability protection (customer-safe write-up):** document "no active leak observed; multiple minor seepage points consistent with age"; recommend clean-and-monitor with reinspection after X miles, dye test optional. Prevents the "you missed my leak" callback.

### Clicking from front wheel while turning
*Source: "Clicking Front Wheel Issue"*

Almost always the **outer CV joint**: clicking/popping only while turning, louder one direction, worse accelerating in a turn, grease at the wheel from a torn boot. **Parking-lot test:** slow full-lock circles each way — louder turning left = right axle bad, and vice versa. Differentials: wheel bearing = growl/hum with speed; tie rod end = click/clunk with steering play; ball joint = clunk over bumps; strut mount/bearing = notchy low-speed clicking. Narrowing questions: FWD or 4x4? Clicks parked-and-steering too? Louder under acceleration? Grease inside the wheel?

---

## 2. Quotes & Pricing (labor rate $125/hr)

### Fuel pump — 1987 Nissan Sentra, external inline pump (not in-tank)
*Source: "Fuel pump replacement quote"*

Labor 1.0-1.5 hr (raise vehicle, relieve pressure, disconnect, swap pump + short hoses/clamps, prime, leak-check) = $125-$187.50; write 1.5 hr if rust/hack repairs expected. Parts: inline pump $90-140, filter $25, EFI hose/clamps $20, shop supplies $10. Example total **$362.50 — present as "$350-$380 plus tax."** Customer-supplied pump: labor + small parts ≈ $210-225. Add-ons: fuel pressure test/diag 0.5 hr ($62.50); "rusty hardware billed at standard rate" line.

### Front bumper — 2015 Chrysler 300 RWD (fog lights + park sensors, no paint)
*Source: "Quote for bumper labor"*

| Task | Hr | $ |
|---|---|---|
| Remove damaged cover | 0.6 | 75.00 |
| Transfer fog lamps/harnesses | 0.5 | 62.50 |
| Transfer grille/trim/badges | 0.6 | 75.00 |
| Transfer/align park sensors | 0.7 | 87.50 |
| Install & align new cover | 0.7 | 87.50 |
| System check | 0.2 | 25.00 |

**3.3 hr = $412.50**; +0.5 hr sensor calibration → $475; +0.3 hr headlamp aim → +$37.50. Pre-assembled bumper drops the job to ~2.0-2.5 hr. Quote-intake questions worth reusing: year/generation, fog lights Y/N, sensors/radar Y/N, paint already done Y/N.

**Receipts policy (same conversation):** never hand over vendor receipts — they expose wholesale pricing and account info. Provide a fully itemized invoice (parts, warranty, price paid, labor, fees) instead. Policy line: *"White Knight Roadside LLC does not provide internal procurement records or supplier receipts. All customer-facing documentation will be provided in the form of an itemized invoice detailing billable parts and services."* If a customer insists: they can supply their own parts (labor-only, no parts warranty) or shop around before approving — no after-the-fact margin fishing.

### Fuel tank — 2013 Toyota Corolla 1.8L
*Source: "Fuel Tank Installation Quote"*

Tank must be dropped (filler neck, EVAP lines, fuel lines, straps). Book 3.5-4.0 hr → **$437.50-$500; quote $450-$500 labor** for mobile work. Time adders: full tank needing drain, rusty strap bolts / partial exhaust removal, brittle EVAP lines, doing pump/sender while the tank is out.

### Tire mount & balance — service definitions
*Source: "Tire Mount and Balance"*

Customer-facing wording: *"Tire Mount & Balance: removal of the old tire from the wheel, installation of the replacement tire onto the wheel, inflation to proper pressure, and balancing of the tire/wheel assembly with wheel weights."* Does NOT include the tire itself, valve stem/TPMS kit, disposal, repair, lug nuts, wheel repair, or on-vehicle R&I unless stated. Taking the wheel off the vehicle = **Wheel Remove & Reinstall (R&I)** incl. torquing lugs to spec; bundled = "Tire Mount & Balance with Wheel Removal/Reinstallation."

**Brokered-job terminology:** call the broker's customer the **End Customer** (never "our customer's customer"). Roles: Customer (billable), Broker/Provider (sends jobs, may pay), End Customer (receives service), Service Contact (person on scene), Vehicle Owner (only when confirmed). Field names: `account_customer_id, broker_id, end_customer_id, service_contact_id, payment_responsibility_party`.

### 245/70R19.5 tires = medium-duty commercial
*Source: "Truck Tire Size 245/70R19"*

19.5" wheels are the tell: Class 4-6 trucks — Isuzu NPR/NQR/NRR, Chevy/GMC 4500-6500HD, Ford F-450/F-550 cab & chassis, Ram 4500/5500, Hino 155/195, Freightliner M2 light spec (box trucks, flatbeds, tow trucks, RV chassis; never on half/three-quarter/one-ton pickups). Price and treat as **commercial service** — heavy stiff Load Range G/H tires, harder mounts, higher liability; some roadside programs exclude 19.5"; on duals, confirm inner vs outer before quoting.

---

## 3. Battery Install Checklist (Roadside)
*Source: "Battery Install Checklist"*

**Before leaving:** correct group size, CCA meets/exceeds OEM, terminal orientation (common mistake), radio anti-theft codes, memory saver needed?
**On arrival (2-3 min):** verify complaint (slow crank / no crank / click), corrosion, cables (especially ground), belt. If fully dead, jump first to confirm it's the battery, not starter/connections.
**Removal:** key off → negative first → positive → hold-down → lift straight. Heavy corrosion: baking soda + water, wire brush, clean tray.
**Install:** seat fully → hold-down (don't skip) → positive first → negative → terminal protectant. Clamps tight enough you can't twist by hand.
**Post-install:** strong crank, charging 13.5-14.7V running, reset clock/windows, clear low-voltage codes.
**Gotchas:** Ford side-post stripping, GM battery current sensor damage, BMW/Euro IBS sensors, battery registration required (BMW/Audi/Mercedes), parasitic drain masquerading as a bad battery.

---

## 4. LLM Automotive Diagnostic Prompt (his tool)
*Sources: "LLM Automotive Diagnostic Prompt" + "Automotive Mobile Diagnostic Agent"*

He built a copy/paste system prompt for a mobile-roadside diagnostic agent. Core design (full 16k-char text lives in the original chat, URL chatgpt.com/c/69ee33b8-...):

- **Identity:** diagnostic assistant for a mobile mechanic away from a full shop (no lift, limited scan tools). "You are NOT here to guess parts... narrow the fault using symptoms, simple tests, and evidence."
- **Primary goals:** identify failing system; safe to drive?; field-diagnosable/repairable?; next best test; what to rule out before replacing parts; when to stop/tow/refer.
- **Field-Service Mindset:** prefer the safest, simplest, lowest-risk test first; don't disassemble unnecessarily; never make the vehicle less movable; say clearly when a job stops being field-friendly.
- **Field Constraints First (his added upgrade):** before recommending lifting, crawling under, bleeding, disassembly, or road testing — is the vehicle roadside/in traffic? flat stable ground? light and room? slope/gravel/soft ground? weather? can it be chocked/secured? will a failed test strand it? If unsafe → tow or reschedule.
- **Response template:** What we know → Safety call (safe to drive / safe to continue / main risk) → What this points toward → 3 most likely causes (why it fits / why not / how to test) → First field test (test, tools, steps, good result, bad result, meaning) → Next branch (if/then, incl. stop-and-tow) → **Do not replace yet** → Field-service decision (drive? continue? field repair? tow?).
- **Running state between messages:** confirmed facts / ruled out / still possible / most likely now / next best test — update, don't restart.
- **Tone:** direct, mechanic-friendly, no fake certainty, minimum necessary questions.

Proven in practice on the master-cylinder case above (Section 1).

---

## 5. Equipment Setups

### Running a 120V air compressor off vehicle power
*Source: "Truck Air Compressor Setup"*

Compressor: 120V, 7.1A running (≈852W), 1.2 HP, 135 PSI, 2-gal, oil-free. Motor surge = 2-4x running current, so plan **850-900W continuous / 2,000-3,000W surge** → **80-90A from the 12V system** after inverter losses.

**Recommended:** 2,000W pure-sine inverter (≥4,000W surge), engine running before switching on, compressor plugged directly in (long cord goes on the 120V side, 12-ga minimum). A 130-180A alternator handles it; the compressor eats roughly half a 160A alternator's output while running.

**Rear-mount install in the 2000 Grand Cherokee (≈10 ft run / 20 ft round trip):** 2/0 AWG stranded copper welding cable both legs (1/0 absolute minimum); 250A Class-T/ANL fuse within ~12" of battery positive; disconnect/breaker near the inverter; run a dedicated 2/0 negative back to the battery (don't trust the unibody) plus a case-bond strap. Route away from exhaust, sharp edges, driveline, fuel/brake lines; grommets and adhesive-lined heat-shrink lugs. Undersized cable = voltage drop = inverter trips on compressor startup even when "big enough."

**Usage rules:** engine on, big loads off, raise to ~1,200-1,500 RPM for big/repeated fills (alternators make little current at idle). Upgrade battery/alternator only if lights dim, voltage sags below ~12.5V running, or the inverter trips.

**Holding RPM without the pedal (2000 WJ 4.0L):** locking universal choke/throttle cable to the throttle-body linkage as a manual high-idle (~1,200-1,500 RPM max). Strong return spring, must not bind factory throttle/cruise cables, release before driving.

### Wiring a dual voice coil sub (Dual DA1000-4D amp)
*Source: "Wiring dual voice coil sub"*

- Amp is **4Ω minimum bridged** (2Ω min stereo); rated 80W x4 @ 4Ω, 120W x4 @ 2Ω, 240W x2 bridged @ 4Ω.
- DVC 2Ω sub must be wired **series to 4Ω** for bridging: jumper VC1(-) to VC2(+); amp+ ← VC1(+), amp- ← VC2(-). **Never parallel to 1Ω** on this amp.
- Layout: CH1/CH2 front speakers, CH3/CH4 bridged to sub. Bridged pair on LPF ~80 Hz; fronts HPF 80-100 Hz; bass boost off.
- **DMM gain setting:** target VAC = √(W × Ω): bridged 240W@4Ω ≈ 31.0 VAC (50 Hz tone); 80W@4Ω ≈ 17.9 VAC (1 kHz); head unit at 75-80% volume, EQ flat.
- Power: BAT+ fused near battery, short solid chassis ground, REM from head unit/LOC.

**Budget "shoestring but impressive" 2000 WJ build (~$790-865 keeping factory radio):** NVX NDA11005 5-ch amp (~$244, 2Ω stable, 150x4 + 500x1 @ 2Ω), Infinity REF607CF 6.5" front components on Scosche SA69 adapters (fit the WJ's 6x9 door cutouts better than most 6x9s), Rockford P3D4-10 sub wired parallel to 2Ω (~$240), 4-ga OFC kit ($68), front-door butyl deadening ($105), KISLOC2 LOC (~$30). Head unit ~+$120; Dayton DSP-408 ~+$200; factory Infinity amp needs Metra 70-6507 bypass harness. Door treatment (deadener on outer skin, seal inner openings, foam gaskets/rings) is the biggest audible upgrade. Crossovers: doors HPF 80-100 Hz @ 12 dB/oct, sub LPF 70-90 Hz @ 24 dB/oct, gains by DMM not by ear.
