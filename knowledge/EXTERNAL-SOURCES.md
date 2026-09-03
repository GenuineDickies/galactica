# External sources — data, standards and compliance

Researched 2026-08-18. Everything here was checked against primary sources; the
URL is given so it can be re-checked rather than trusted.

**The licence rule for this project.** The application is proprietary and
intended for sale to other operators, so **AGPL and GPL code cannot be used** —
not in part, not "just this file". MIT, BSD, Apache-2.0, CC0 and US-government
public-domain material are fine. Facts and short factual tables are not
copyrightable in the US (*Feist v. Rural Telephone*), which matters more than it
sounds: several things below are cheaper to type in than to license.

Live example: Firefly III is the closest technical match to this application's
ledger and is **AGPL-3.0**. Read it for ideas; copy nothing.

---

## 1. Vehicle data

### NHTSA vPIC — adopt

`https://vpic.nhtsa.dot.gov/api/` · **US Government, public domain.** No key, no
registration, no published hard rate limit (NHTSA say the servers handle
1,000–2,000 transactions/minute and ask that big batch jobs run overnight ET).
The widely-repeated "100–200 requests/minute" figure appears in no NHTSA
document — treat it as folklore, but implement backoff anyway.

Endpoints worth knowing:

- `/vehicles/DecodeVinValues/{VIN}?format=json&modelyear=YYYY` — flat key/value,
  the one to use
- `/vehicles/DecodeVINValuesBatch/` — POST, **max 50 VINs**, `vin,year;vin,year`
- `/vehicles/DecodeWMI/{wmi}` — the 3- or 6-character manufacturer lookup
- `/vehicles/GetVehicleVariableList` — the full 150+ variable dictionary

**Offline database:** `https://vpic.nhtsa.dot.gov/downloads/` — refreshed
monthly. PostgreSQL custom dump ~69 MB, restores into schema `vpic`, then
`select * from vpic.spVinDecode('VIN')`. Restore with `--no-owner
--no-privileges`. The standalone database does **VIN decoding only**; make and
model lookups still need the API.

**THE TRAP, and it matters here.** The schema *has* `BatteryType`, `BatteryV`,
`BatteryA`, `WheelSizeFront`, `WheelSizeRear`, `CurbWeightLB` — and they come
back **empty on ordinary petrol vehicles**. They are populated mainly for EVs
and motorcycles. A live decode of a 2013 F-150 returned 41 populated fields out
of 154, and battery and wheel size were not among them.

So: **do not design the jump-start or tyre-change flow assuming the VIN will
tell you the battery group size or the tyre size.** It will not. That is what
BCI and an in-house tyre table are for.

### us-car-models-data — adopted (2026-08-30)

`https://github.com/abhionlyone/us-car-models-data` · **CC-BY 4.0**, LICENSE
file present. Year/make/model rows for the US market, one CSV per year
1992–2026; a cleaned merge (11,543 rows, 66 makes) ships as
`data/vehicle-models.csv` and seeds the `vehicle_catalog` table behind the
intake form's dropdowns (`data/seed_vehicles.php`).

Attribution (which is all CC-BY asks, and bare year/make/model facts are
likely not copyrightable per *Feist* anyway): data derived from
us-car-models-data by Abhilash Reddy, github.com/abhionlyone/us-car-models-data,
CC-BY 4.0. Rows were deduplicated and the `body_styles` column dropped.

**Frozen upstream.** The maintainer stopped updating the free files in 2026
(paid tier now). Model years after 2026 come from vPIC instead:
`php data/refresh_vehicles.php 2027` asks GetModelsForMakeYear for each make
already in the catalog and adds what is missing, with `source = 'vpic'`.

### NHTSA recalls, complaints and technical service bulletins — adopt

`https://api.nhtsa.gov/` · same public-domain licence, no key.

- Recalls: `/recalls/recallsByVehicle?make=&model=&modelYear=`
- Complaints: `/complaints/complaintsByVehicle?...`
- Safety ratings: `/SafetyRatings/modelyear/{y}/make/{m}/model/{md}`
- Parameters and method names are **case-sensitive**.

Bulk files, which are usually better than the API:

- `https://static.nhtsa.gov/odi/ffdd/rcl/FLAT_RCL_POST_2010.zip` — recalls, 14 MB
- `https://static.nhtsa.gov/odi/ffdd/tsbs/TSBS_RECEIVED_2020-2024.zip` — TSB
  metadata and summaries, 30 MB
- `https://static.nhtsa.gov/odi/ffdd/cmpl/FLAT_CMPL.zip` — complaints, 352 MB

Telling a stranded customer "there's an open recall on that, the dealer fixes it
free" is a credibility win that costs nothing. TSB metadata is the underrated
one for a mobile mechanic — "is there a known bulletin on this symptom".

### OBD-II trouble codes — adopt the generic set

`https://github.com/Wal33D/dtc-database` · **MIT**, LICENSE file present. 3.1 MB
SQLite, 18,805 definitions, 12,128 unique codes, no runtime dependencies.
`https://github.com/lennykean/OBDII.DTC` · **MIT**, generic codes only.

Caveat worth respecting: the generic SAE J2012 P0xxx descriptions are a
published standard and low-risk. The manufacturer-specific half is undocumented
as to origin. Shipping the generic set and treating manufacturer codes as
advisory is the defensible middle.

### Parts fitment — ACES / PIES — defer

The Auto Care Association standards (ACES v5.0 for fitment, PIES v8.0 for
product attributes) are the aftermarket lingua franca, and they are useless
without the reference databases they encode against — VCdb, PCdb, PAdb, Qdb.
All are **subscription-gated and priced by revenue**: VCdb Light Duty Level 1 is
$4,410/yr non-member, PAdb another $1,470, under a signed technology licence.

Any GitHub repository hosting VCdb or PCdb content is redistributing licensed
data in breach of that agreement. **Do not use one, and especially not in
software that gets sold.**

Defer until the business sells parts from stock and needs fitment precision.
Jump starts, lockouts, tyre changes and starter swaps do not.

### Labour time guides — nothing open exists

Mitchell 1, ALLDATA, MOTOR, Chilton, Identifix: all subscription. This is the
biggest unsolved data gap in independent-shop software.

**Open Labor Project (openlaborproject.com) is a trap.** Its own About page says
the data "comes from industry guides" — i.e. derived from the copyrighted works
it claims to replace — and it grants **no licence of any kind**. Its coverage
claim does not survive inspection either: 700,000+ labour times advertised
alongside an invitation to "browse all 127 vehicles with procedures". Do not
ingest it.

**Build the table in-house instead.** A mobile roadside operation has perhaps
30–50 distinct operations. Times measured from actual completed jobs will beat
any commercial guide, because no commercial guide models kerbside work out of a
van rather than a shop with a lift.

### Tyre load index and speed rating — type it in

No open dataset exists; the tables on retailer blogs carry no licence, and the
commercial offerings are per-seat annual. But the load index table is ~130 rows
and the speed rating table ~20 rows — short tables of facts, not copyrightable.
Type them from two independent published sources, cross-check, done in an hour.
Parsing `225/65R17 102H` is a ten-line regex.

The vehicle → OE tyre size mapping is the hard half and has **no free source**,
and as noted vPIC will not fill it.

### Battery group size (BCI) — the best-value purchase on this list

`https://batterycouncil.org/battery-facts-and-applications/vehicle-battery-replacement-data/`

- Print data book: **$20** non-member — gets the ~40-row group size →
  dimensions and terminal position table
- **Source BCI**: $29.99/yr non-member, 160,000+ vehicles, and this is the
  vehicle → group size mapping actually wanted
- A **Source BCI licensing** route exists specifically for integrating into a
  proprietary database — `info@batterycouncil.org`

The group-size charts on retailer sites are derived from the BCI book with no
licence statement. For a business whose bread and butter is batteries, this is
the one data purchase worth making.

---

## 2. Compliance

### 10DLC / A2P SMS

Not federal law — a carrier and industry regime. The mobile operators enforce
it, The Campaign Registry is the registry, CTIA publishes the underlying
principles. TCPA applies **in addition**. Unregistered traffic is not fined so
much as silently dropped.

CTIA *Messaging Principles and Best Practices* (May 2023) sets three tiers, and
the tier decides what consent is needed:

| | Conversational | Informational | Promotional |
|---|---|---|---|
| Who texts first | Consumer | Either | Business |
| Consent | **Implied** | Express | Express **written** |

A customer who phones for a tow and gives their mobile is *conversational*.
Dispatch, ETA, arrival, invoice and signature links need no separate opt-in. A
review request or a seasonal promotion is *promotional* and does.

**Register two campaigns, not one.** Mixing marketing into a customer-care
campaign is a declared-use-case violation that carriers fine for.

**Mandatory keywords.** STOP, END, CANCEL, UNSUBSCRIBE, QUIT — and punctuation
or capitalisation must not defeat them. Opt-out inside a sentence ("please stop
texting me") must also be honoured. HELP must return business identity and
contact. Opt-out must be accepted by phone and email too, not only by text, and
exactly **one** confirmation message may follow.

**The seven consent fields carriers demand on audit** — timestamp, acquisition
medium, **a capture of the exact wording and action used to obtain consent**,
the campaign, IP address, phone number, and the identity of the person who
consented. That third one is the one everybody misses: store a versioned
snapshot of the consent screen as it read at that moment.

**Thresholds worth watching before the carrier does:** opt-out rate above 0.5%
on a send triggers monitoring; above 4% in 24 hours triggers suspension and a
consent audit.

### TCPA

The 1992 Order is the anchor: "Persons who knowingly release their phone numbers
to a caller have in effect given their invitation or permission to be called at
the number which they have given, absent instructions to the contrary." Texts
count as calls.

Two limits that matter:

1. **Consent is scoped to the transaction.** Not a blanket. Job-related yes,
   marketing no.
2. **Revocation is by any reasonable means** — 47 CFR 64.1200(a)(9)–(10). Stop,
   quit, end, revoke, opt out, cancel, unsubscribe are *per se* reasonable. You
   **may not designate an exclusive means** of revoking, and all requests must be
   honoured within **ten business days**. Honour them immediately anyway.

Damages are $500 per message, trebled to $1,500 for wilful violations.

Retention: no TCPA-specific period exists. The do-not-call honour period is 5
years and the statute of limitations 4, so **keep consent and opt-out records at
least 5 years**. That is an inference, not a stated rule.

### Electronic signatures — ESIGN, UETA, and Oregon ORS chapter 84

**ORS 84.061 supersedes ESIGN §101 for Oregon transactions**, so Oregon's UETA
governs, not the federal act. Full text:
`https://www.oregonlegislature.gov/bills_laws/ors/ors084.html`

The six things that make a signature defensible, and where each comes from:

| Element | Source | What to store |
|---|---|---|
| **Intent to sign** | 15 USC 7006(5); ORS 84.004(8) | The exact on-screen wording agreed to, the button label, the template version. A signature image alone proves nothing. |
| **Agreement to transact electronically** | ORS 84.013(2) | Explicit affirmation plus a paper-copy offer, with wording and timestamp. |
| **Attribution** | ORS 84.025 | Typed name, the phone the link was texted to, the consumed one-time token, IP, user agent, geo, and who initiated. |
| **Association with the record** | 15 USC 7006(5) | **A SHA-256 hash of the exact rendered document bytes**, stored with the signature. |
| **Audit trail** | ORS 84.025(2) | Append-only: link generated → SMS sent → opened → viewed → consented → signed → submitted. Never mutable. |
| **Retention and reproduction** | 15 USC 7001(d),(e); ORS 84.034 | The flattened immutable signed PDF, renderable years later without this application. |

**ORS 84.025 explicitly lets attribution be proved by "showing the efficacy of
any security procedure"**, and ORS 84.004(14) names "callback or other
acknowledgment procedures" as one. Texting a signing link to the caller's own
phone *is* such a procedure — so document how it works, because that
documentation is the evidence.

**ORS 84.022(3): a record is NOT enforceable against the recipient if the sender
inhibits their ability to store or print it.** Always give the signer a copy.

**The anti-pattern to avoid:** storing `signature.png` plus an invoice id and
re-rendering the invoice from live data at dispute time. If a price changed
after signing there is no way to prove what was signed. Freeze and hash the
document at the moment of signature.

### Oregon vehicle repair — ORS 646A.480 to 646A.495

**There is no Oregon licence or registration for a repair shop.** Oregon has
nothing equivalent to California's Bureau of Automotive Repair. ORS
646A.480–495 regulates *conduct*, not entry, and the Oregon DOJ's Motor Vehicles
section covers buying, leasing, service contracts, lemon law and towing — with
no repair-licensing page at all.

Flagging this because the top search results say the opposite. LegalClarity and
similar AI-generated sites assert that Oregon repair shops "must be registered
and licensed". No statute supports it. Those sites were wrong on this the same
way they were vague on core deposits.

**ORS 646A.480 — the definition catches a mobile mechanic squarely.** "Vehicle
repair shop" means any entity "that in exchange for payment evaluates the
condition of, maintains or repairs a motor vehicle." No premises requirement, no
size threshold. Body-and-frame shops are excluded — they fall under ORS 746.275
and 746.292 instead.

**ORS 646A.482 — an estimate is required BEFORE work begins.** Not on request:
*"A vehicle repair shop shall prepare an estimate of the cost of work the
vehicle repair shop proposes to perform on a motor vehicle before beginning the
work."* A copy goes to the owner not later than before final payment, either
standalone or as part of the invoice, and the shop keeps a copy. Minimum
contents:

- the general nature of the proposed work
- the work **divided into separate tasks**, so far as it can be
- estimated labour cost, and the parts or component systems to be replaced
- the amount of any incidental charges
- the total estimated cost, **which may be a reasonable range**

**ORS 646A.486 — the authorisation threshold, and this application already
implements it.** Work may not exceed the estimate *"by more than 10 percent or
by more than $200"* without fresh authorisation.

`config.php` carries `variance_abs => 200.00` and `variance_pct => 0.10`, and
`Rules::varianceThreshold` takes the **minimum** of the two — which is right,
because either condition triggers, so the smaller one binds first.

The statute also names exactly two acceptable ways to get that authorisation,
and the second one is what a roadside operator will actually use:

1. the owner's **signature** under a statement printed on the estimate; or
2. **oral assent by telephone**, having given all material information shown on
   the estimate, recording the **name and telephone number of the person
   assenting, and the date and time of the call**.

That second route is a concrete schema requirement: an authorisation captured by
phone needs those four fields, not just a boolean.

**ORS 646A.490 — reassembly, and a one-year retention floor.** A vehicle taken
apart for evaluation must be reassembled roughly within the time the estimate
stated, if the parts are available. And legible copies of every document
required under 646A.480–495 must be kept **at least one year**, electronic or
printed. (Keep seven for tax purposes anyway — see above.)

**Parts condition must be disclosed.** Each part identified as new, used,
rebuilt or reconditioned. Misrepresenting it is a deceptive practice under the
UTPA. `catalog_items` has no such field yet.

**Core deposits: no Oregon statute found.** Searched the UTPA, ORS 646A and DOJ
guidance. Nothing sets a mandatory refund window for a refundable core charge.
That is an absence of evidence, not proof of absence — the 30-day forfeiture
window in Settings should be confirmed with a lawyer before it is printed on an
invoice as a deadline.

**Software consequences:**

| Requirement | What the app must do |
|---|---|
| 646A.482 estimate before work | Already the document chain. Do not allow a work order without an authorised estimate. |
| Estimate divided into tasks | Line items already do this. |
| Total may be a range | **Not supported** — estimates carry a single total. Worth adding for "evaluate then advise" jobs. |
| 646A.486 10%/$200 | Already enforced via `Rules::varianceNeedsAuth`. |
| Phone authorisation fields | **Capture name, phone number, date and time of the call** — a boolean is not enough. |
| 646A.490 one-year retention | Covered by never deleting. |
| Parts condition | **Add new/used/rebuilt/reconditioned to `catalog_items` and print it on customer documents.** |

### Record retention

IRS: **3 years** general, **6 years** if income was understated by more than
25%, **4 years** for employment tax, indefinitely if no return was filed.
Property records until the limitations period expires for the year of disposal.
`https://www.irs.gov/businesses/small-businesses-self-employed/how-long-should-i-keep-records`

Electronic records are acceptable under **Rev. Proc. 97-22**, which requires an
indexing system permitting retrieval "by any designation used on the original",
legible hardcopy on demand, and controls against unauthorised alteration.

**Oregon adds a real requirement.** OAR 150-314-0265(2)(b): if records are kept
in machine-sensible form, they must be **made available to the Department of
Revenue in machine-sensible format on request** — PDFs alone will not satisfy an
Oregon audit. (3)(a) requires "sufficient transaction-level detail" and the
ability to convert to a standard record format.

**Practical default: keep everything seven years.** That covers the 3-year
normal case, the 6-year omission case, the 4-year employment case and Oregon's
window with room for extensions.

Software consequences, all of which this application already does or should:

- Never hard-delete a financial record. Voids and reversing entries only.
- Build a **structured export** — CSV or JSON at transaction level, not just
  PDFs. That is what Oregon will ask for.
- Index documents by every designation on the original: invoice number, job
  number, date, customer, amount, VIN, plate.
- Attach immutable hashed PDF renderings so "legible hardcopy on demand" works
  even if the application is gone.
- Log every access and modification with actor and timestamp.

---

## 3. Prior art worth reading

**Resgrid/Core** — `https://github.com/Resgrid/Core` · **Apache-2.0**. A real
open-source Computer Aided Dispatch platform: dispatch, personnel, shift
management, automatic vehicle location. Permissive licence, so it can be learned
from *and* borrowed from. The closest serious prior art to the dispatch side of
this application.

**Fleetbase** — AGPL-3.0. Ideas only.
**LedgerSMB** — GPL. Ideas only.
**Firefly III** — AGPL-3.0. Ideas only. Its rule engine is the right shape for
expense categorisation; the implementation is off limits. See section 4.

**Square's own PHP SDK** — MIT, and its `WebhooksHelper::verifySignature` was
compared against this application's implementation. Ours is algorithmically
identical and **stronger in one respect**: it uses `hash_equals` where Square
uses `===`, so theirs leaks timing information on a forged signature. Kept ours.

---

## 4. Expense categorisation — bank and card import

Researched 2026-08-20 for task #26. The operating spend — parts, fuel, insurance,
tools — is on bank and card statements, not in Square, so it has to be imported
and assigned to accounts. The question was whether that assignment can be
automated, and by what.

### Merchant Category Codes — adopt the data, do NOT design around it

`https://github.com/greggles/mcc-codes` · **Unlicense** (public domain
dedication, LICENSE.txt present). CSV, JSON, JSONL, ODS, XLS of every MCC, with
USDA and IRS descriptions and an `irs_reportable` flag for 6041/6041A. 528
stars, actively corrected, Expensify among the contributors. Free of any
licence problem for a proprietary product.

**But the MCC will almost never be in the data being imported, and that is the
finding that decides the design.** MCC is assigned by the acquiring bank and
travels on the card network — it is not part of what a consumer or small
business account hands back in an export. American Express does not display it
anywhere on its US or Canadian sites; most issuers omit it from CSV downloads
entirely, and where a "category" column does appear it is the issuer's own
marketing bucket, not the MCC.

The OFX specification does carry an `SIC` element inside `STMTTRN`
(`https://schemas.liquid-technologies.com/ofx/2.1.1/stmttrn.html`), which is the
right place for it. Population is inconsistent between institutions and cannot
be relied on. Read it opportunistically; never require it.

*So:* keep the MCC table for the case where a code IS present, and for mapping
one to an account when it is. Do not build the categorisation engine on it.

### Merchant NAME matching — the actual design

This is the route, confirmed by the operator: the only field that is reliably
present on every bank and card line is the descriptor string.

**What the descriptor actually looks like.** Legacy banking systems cap the
field at roughly 20–25 characters, so merchant name, location and reference get
compressed together and truncated. Payment intermediaries prepend their own
identifiers — `SQ *`, `TST*`, `PAYPAL *`, `SP ` — so the merchant a payment ran
*through* obscures the merchant it was *with*. Store numbers (`#34`), city and
state, and date artifacts trail the name.

**The pipeline that follows from that**, and it is ordinary string work rather
than anything clever:

1. Upper-case, collapse whitespace, strip punctuation noise.
2. Strip known processor prefixes — a short, hand-maintained list.
3. Strip trailing store numbers, city/state pairs, and reference digits.
4. What is left is a **match key**. Store it beside the raw descriptor; never
   overwrite the raw, because the cleaning rules will be wrong sometimes and the
   original is the evidence.
5. Ordered pattern → account rules, first match wins — the same shape as `Rules`
   and `Markup`, and for the same reason: one place, testable, no formula in JS.

**The asset is the operator's own corrections, not a downloadable list.** No
permissively-licensed merchant→category dataset of any quality was found. The
open-source projects in this space are small, mostly unmaintained, and trained
on data whose provenance is not stated — `Foxel05/Finance-TransactionCategorizer`
(MIT, Naive Bayes), `eli-goodfriend/banking-class`, `j-convey/BankTextCategorizer`
(BERT). None is worth a dependency. What *is* worth building is the loop: when
the operator recategorises a line, offer to write the rule, so the second Napa
invoice files itself. After a few months of that the rule set is worth more than
anything that could have been imported, and it is the operator's own.

### OFX parsing — MIT, but check the state of the fork

`https://github.com/asgrim/ofxparser` · **MIT**. Clean, framework-independent,
handles multiple accounts and OFX timestamps. **Archived by its owner on
2020-09-08 and read-only** — so it is a snapshot to vendor and own, not a
dependency to track. Given this project takes no Composer dependencies anyway,
vendoring a trimmed copy or writing the parser against the spec are both
reasonable; OFX is SGML-ish and the statement subset is small.
`endeken-com/ofx-php-parser` targets PHP 8.2 and is maintained — licence not yet
verified, check before use.

### IRS Schedule C — the taxonomy the books should land on

`https://www.irs.gov/pub/irs-pdf/f1040sc.pdf` and the instructions at
`https://www.irs.gov/instructions/i1040sc` · **US Government, public domain.**
Part II, lines 8–27b, is the categorised expense list a sole proprietor actually
files: advertising, car and truck, commissions and fees, contract labor,
insurance, interest, legal and professional, office, rent, repairs, supplies,
taxes and licenses, travel, meals, utilities, wages, other.

Worth mapping the 6xxx block onto those line numbers explicitly, so the ledger
can produce the form rather than something that has to be re-bucketed at tax
time. Note the two-part §162 test — ordinary *and* necessary — is a judgement,
not something a rule engine settles; the engine proposes, the operator decides.

---

## Where a professional is needed

Reporting what sources say is not advice, and several of these are genuinely
unsettled:

- Whether Oregon's towing and vehicle-service statutes impose written
  authorisation or paper-disclosure rules that an electronic signature does not
  satisfy. **Not yet researched** — the agent covering Oregon repair law failed
  mid-run and this remains open.
- Whether a core-deposit forfeiture window is enforceable as written in Oregon.
  No statute setting a mandatory refund window was found, which is not the same
  as there being none.
- The principal-versus-fee split on Square Capital advances, and whether the fee
  is deductible when incurred or over the term. A CPA question.
- Six years of commingled business and personal spending. Also a CPA question,
  and the most valuable hour the owner could spend.
