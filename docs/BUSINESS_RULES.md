# Business rules

Every rule below is enforced in code, in one place — `Rules` in `app/Domain.php`,
with thresholds in the `rules` block of `config.php`. Screens ask `Rules`
questions; they never re-implement a rule. If a gate ever needs to change, it
changes there and everywhere at once.

---

## 1. A service request is not a fact

A service request records that somebody asked for help. Nothing on it is
verified, and it deliberately cannot:

- create a customer
- create or link a vehicle
- carry prices or line items

It holds `reported_name`, `reported_phone`, `reported_service`,
`reported_problem`, `reported_location`, and a loose description of the vehicle.
All of it may be wrong.

**Why.** Requests will eventually arrive electronically from providers — a payload
someone else's dispatcher typed. If that payload can mint a customer record, the
customer table fills with duplicates and misspellings that then get invoiced. The
request stays inert until a human confirms it.

## 1a. What the caller said and what we rolled for are two different fields

`reported_service` is the caller's word for it. It is testimony: it is never
corrected, even when it is plainly wrong.

`service_category` is what **dispatch** decided to send, which the caller does
not get a vote on. It defaults from `reported_service` and is always
overridable at intake.

The work order carries **its own** `service_category`, defaulted from the
request and changed by whoever is standing in front of the vehicle. Changing it
writes `category:changed` to the audit log and does not touch the request.

**Why three values and not one.** The gap between them is the measurement:

| Question | Answered by |
|---|---|
| How often is intake wrong? | `reported_service` vs the work order's category |
| How often does dispatch send the wrong kit? | request category vs work order category |
| Which customers systematically misreport? | `reported_service`, per customer |

Overwrite any of them and the corresponding number becomes unknowable. A fleet
account that calls every tire job a "flat tire" costs real money in second
dispatches, and this is the only place that shows up.

## 1b. Categories divide on capability, not urgency

The five categories are **Roadside Services**, **Advanced Tire Services**,
**Mobile Repair Services**, **Towing Services** and **Other**
(`ServiceCategory` in `app/Domain.php`).

The dividing question is *what does this job need on the truck*, not *how
stranded is the customer*.

| Category | The question it answers | Services |
|---|---|---|
| Roadside | Hand tools, nothing comes apart | Jump Start · Lockout · Fuel Delivery · Spare Tire Swap (donut) · Tire Repair — plug |
| Advanced Tire | The bead comes off the rim | Tire Repair — internal patch · Tire Delivery, Mount & Balance |
| Mobile Repair | A part comes off, a part goes on | Parts Installation · Battery Replacement · Diagnostic |
| Towing | The *vehicle* moves | Winch Out · Flatbed Tow · Standard Tow |
| Other | None of the above | Other |

For tire work the dividing question reduces to one test:

> **Does the tire have to come off the rim?**

Note what that is *not* asking. It is not "does the wheel come off the vehicle."
Pulling a wheel is a jack and a lug wrench, which every roadside truck carries —
a plug is routinely done with the wheel off the vehicle and the tire still on
it. The question is whether the **bead** has to come off the **rim**, because
that is what needs a bead breaker and a tire machine.

| Job | Wheel off vehicle | Tire off rim | Category |
|---|---|---|---|
| Spare tire swap | Yes | No | Roadside |
| Plug repair | Often | **No** | Roadside |
| Internal patch | Yes | **Yes** | Advanced Tire |
| Tire delivery, mount & balance | Yes (or loose wheel) | **Yes** | Advanced Tire |

Only the third column decides.

**Battery replacement is Mobile Repair, not Roadside.** It was Roadside once, on
the argument that it is hand tools and nothing comes apart. That reasoning is
sound about the *tools* and wrong about the *job*. A battery swap is a part sale
with labour attached — the same shape as an alternator, only lighter — and it is
sold standalone by every other provider in the trade. Filed with jump starts it
looked like a roadside errand and its parts revenue hid inside a category that
is supposed to be pure labour-and-consumables.

**Winch-out is Towing, not Roadside.** A winch-out is vehicle recovery, which is
the tow trade's work and needs a tow truck, not a soft-service van. It sits with
the tows regardless of the fact that the customer describes it as being stuck.

**Towing exists for tenants.** White Knight does not tow. The category ships
because the platform is multi-tenant and other operators run trucks; an operator
that does not offer a category simply never rolls it.

**Why capability.** Urgency does not tell you what to load. A stranded customer
needing an internal patch and a stranded customer needing a spare swap look
identical on the phone and need different equipment. Categorising on urgency
would put them in the same bucket and hide the cost of the second dispatch.

## 1b-i. The category is chosen FIRST, and it gates the service

Intake used to ask "probably this service?" and then "roll as?" — name the job,
then classify it. That is one decision asked in the order nobody makes it in,
and it left the classification riding on whether the rep got it right after the
fact. `TIRE` was the standing casualty: a single "Tire Service" entry that
defaulted to Roadside and had to be corrected by hand on every demount job.

It now runs the other way. Dispatch picks the category — what the truck has to
carry — and the service list narrows to the jobs that kit can actually do. Two
consequences:

- **A service type is a specific job, not a department.** "Tire Service" and
  "Mobile Mechanic" are gone as intake options; the jobs that replaced them each
  carry their own catalog line item.
- **A mismatched pair cannot be entered rather than being caught afterwards.**
  `ServiceCategory::serviceTypes()` is the offer list and
  `coerceServiceType()` re-applies it server-side, so a posted pair that did not
  come from the form as rendered is replaced, not trusted.

Retired types (`ServiceCategory::RETIRED` — `TIRE`, `BATTERY`, `RECOVERY`,
`MECHANIC`) are **accepted but never offered**. A request logged before the
split keeps the broad type it was written with and displays as "(unspecified)".
Nobody went back and asked which of the new jobs it actually was, so the record
does not claim to know.

## 1c. Category is not a revenue account

They are different axes and neither substitutes for the other.

- **Revenue account** — what was sold: labour, parts, fuel, fees. Four accounts,
  and there is deliberately **no revenue account per service**.
- **Category** — how the business is run: what to load, who to staff, where to
  advertise.

A battery job books labour to `4000` and the battery to `4010` no matter which
category it sits in. Fees carry no category at all: a call-out fee on a mechanic
job is mechanic revenue, so a fee inherits the category of the job it is billed
on.

**Fuel delivery is the one revenue account that is not "what was sold" in the
abstract** — `4020` exists because fuel is the only line with a matching COGS
account (`5090`), a commodity cost that moves weekly, and tangible-personal-
property tax treatment. Revenue with a paired COGS gives a real gross margin;
without the split, service margin is understated and fuel margin is invisible.

## 2. Promotion is where hearsay becomes a contract

Promoting a request opens an estimate and is the only path to one. At that moment:

- an existing customer is selected, **or** a new one is created
- a new customer is only created when name *and* phone don't already match an
  existing record — a shared phone number alone is a hint, never an identity
- the confirmed service, scope and address are written onto the estimate
- the request moves to `ACCEPTED`

## 3. Nothing is dispatched without authorization

`Rules::dispatchGate()`. An estimate may only raise a work order when:

1. it has at least one catalog line item, and
2. `authorized_at` is set.

There is no override. A technician being sent to a job that nobody agreed to pay
for is the single most expensive mistake this business can make.

## 4. Above $200, verbal dispatches the truck — a signature releases the wrench

`Rules::signatureRequired()` — `authorization_threshold`, default `$200.00`.

The **estimate** needs verbal approval only, at any amount. The customer's
signature lives on the **work order**, and above the threshold no work may be
performed on the vehicle without it.

Two gates:

- `Rules::dispatchGate()` — priced work plus a customer authorization on the
  estimate. **Verbal counts.** This is all that is needed to send a technician.
- `Rules::workBeginsGate()` — the work order carries a signature, when the
  estimate is over the threshold. **No work begins without it.**

Beginning work is its own recorded step, `IN_PROGRESS`, between `ON_SITE` and
`COMPLETED`. Arriving is not starting: on arrival the technician prices the real
scope on the work order, so the customer signs the number they will actually be
billed. Only then does "Begin work" unlock, stamping `work_started_at`.

`Rules::signatureprecededWork()` compares `auth_signed_at` against
`work_started_at`, and `workOrderCompletionGate()` refuses to close a job whose
authorization was signed *after* work started. Missing timestamps are not
treated as a breach, so jobs predating these columns still close.

### Two ways to capture it

Both write `work_orders.auth_signature`; `auth_method` records which was used.

| Path | How | When |
|---|---|---|
| `IN_PERSON` | The technician turns the device around; the customer reads the work order and signs on the full-screen pad | Default. Strongest evidence — a person signed in front of you |
| `SMS` | A tokenised link is texted; the customer opens the work order on their own phone and signs there | When they are not on scene: keys left, fleet vehicle, driver gone home |

`signature_requests` holds one live link per document per purpose — issuing a
new one voids the old, so a stale text cannot sign something since re-priced.
The token is the entire access control, so it is 24 random bytes and unique by
database index. Links are single-use. `sent_at`, `viewed_at` and `signed_at` are
each recorded, along with the signer's IP and user agent.

**No SMS goes out without consent.** `Sms::gate()` requires `sms_approved`,
honours `do_not_contact`, and records blocked attempts with a reason. When it
refuses, the interface hides the SMS option and says why — the in-person path
remains.

### The completion sign-off is different

It is **not** gated. A customer cannot be compelled to agree the job was done
well. But it cannot be left silently blank either: closing a work order requires
either a signature or an `unsigned_reason`, so "unsigned" is a documented outcome
rather than a gap. Both capture paths are offered, including texting the link
after the van has left.

Closing a job is only reachable through `complete()`. The plain status route will
not accept `COMPLETED`, because that would walk past every gate here.

Either way the stored evidence package is: who authorized it, by what method,
when, from what IP, on what device.

## 5. A vehicle exists only if it has a valid VIN

A plate never creates a vehicle record. VINs are validated against the ISO 3779
check digit (`vin_is_valid()`) on both the client and the server; `I`, `O` and `Q`
are rejected outright.

Capturing the VIN is the **driver's** job, on scene. A stranded customer is not
asked to go hunting for a number on a dash plate.

Where a vehicle genuinely has no plate, that is recorded explicitly with a reason
(`NoPlateIssued`, `PlateMissing`, `PlateObstructed`, `FleetNoPlatePolicy`,
`CustomerDeclined`) rather than left blank.

## 6. A work order cannot be closed without the VIN

`Rules::workOrderCompletionGate()`. Completion is blocked unless a vehicle is
linked — unless every line item on the work order is flagged
`vehicle_not_required` in the catalog (a mount-and-balance on a wheel brought to
the shop, for example).

This catches the VIN while the technician is still standing next to the vehicle,
rather than at invoicing time when they are three jobs away.

## 7. An invoice cannot be issued without a vehicle

`Rules::invoiceVehicleGate()`. Blocked unless:

- a vehicle is linked and its VIN passes the check digit, **or**
- every line is `vehicle_not_required` *and* a written `no_vehicle_reason` is on
  file.

An invoice with no line items is also blocked.

## 8. Scope drift requires re-authorization

`Rules::varianceThreshold()` = the **lesser** of `variance_abs` ($200) and
`variance_pct` (10%) of the authorized estimate.

If `|invoice total − estimate total|` exceeds that, issuing is blocked until a
named person signs off on the new amount. Both a name and a signature are
required; a name alone is rejected.

The threshold is deliberately the lesser of the two, so small jobs get tight
tolerances: a $195 estimate can drift $19.50, not $200.

## 9. Line items come from the catalog, always

There is no free-text line. Every line is snapshotted from a catalog item at the
moment it is added — name, SKU, price, cost, tax flag, warranty months, and the
manufacturer warranty label (stated as-is, e.g. "AutoZone Limited Lifetime"). Editing
the catalog later never rewrites a document that has already been priced.

An absent price field means "use the catalog price". Only an explicitly entered
`0` means zero (`price_or_null()`).

## 10. Documents are locked once they are real

- An **approved or declined estimate** takes no more line items. Field additions
  go on the work order.
- An **issued invoice** takes no more line items. Corrections are a void or a
  credit.
- A **paid invoice** cannot be voided at all — that is a refund, not a deletion.

## 11. No SMS without consent

`Sms::gate()`. A message is blocked when the customer is marked
`do_not_contact`, has no `sms_approved` flag, or has no valid E.164 number. A
blocked message is still written to the outbox with its reason, so there is a
record of what wasn't sent and why.

The gate runs *before* the gateway driver is called, so it applies identically
whether messages are being held in the outbox or sent live through Telnyx.

Consent is stored with a timestamp and a source. Every template carries "Reply
STOP to opt out"; the opt-in confirmation carries the full 10DLC disclosure.

**Inbound STOP is honoured immediately and unconditionally** — it clears
`sms_approved`, sets `do_not_contact`, and is audited. It is never queued for
review. START restores consent with a fresh timestamp. See
`docs/INTEGRATIONS.md` for the full keyword list.

## 11a. Broken 10DLC compliance suspends all sending

`Sms::complianceStop()`, checked on every live send before the carrier is
contacted. No message goes out while any part of the compliance chain is
broken: no registered campaign (missing messaging profile ID), an unverifiable
STOP path (missing sodium extension, missing Telnyx public key, non-https
callback URL), or a body with no opt-out language. The refused send is recorded
QUEUED with the 10DLC reason in `failure_reason`, and the dispatcher-facing
message says the text did not go.

**Why at send time and not just at configuration time.** Settings refuses to
activate a misconfigured driver (see DECISIONS), but an environment can degrade
after activation — a PHP upgrade that drops sodium suspends sending the moment
it lands, not the next time someone opens Settings. A system that can text but
cannot hear "STOP" is not a working SMS system; it is a compliance violation
with a send button.

**Outbox mode is "texting is not connected", not a manual sending channel.**
Messages queued there are a record of what would have been texted — nobody
hand-sends them from a personal phone (owner's decision, 2026-08-06). Every
staff-facing message about an unsent text points at the working fallback:
call the customer, take the signature in person, get the location verbally.

**Personal-phone messages are outside the system entirely.** If someone texts a
customer from a private phone, they have stepped outside the application; the
application neither facilitates nor records it. There is no "mark sent" action
anywhere — a SENT the carrier never saw would be a fabricated delivery record,
worthless in exactly the dispute where the message log matters.

**A verbal STOP binds like a texted one, and is recorded as what it is.** The
carrier can only see texted keywords; a customer who says "stop texting me" on
a call is our obligation to catch, and `Consent` (app/Domain.php) is the one
door both paths go through. The verbal form on /messages requires the phone
number, the direction, and a note saying how it was learned; it records
`revoked_verbal` with the recorder's name and the note as the audit evidence,
clears intake consent on any open service requests carrying that number, and
enforcement is immediate and identical to a texted STOP. It deliberately writes
NO message row — nothing is ever recorded as a text that was not a text. (An
earlier version fed a pretend inbound SMS through the webhook handler; that was
a fabricated record and was replaced.)

**A texted STOP reaches the request too, and the intake box reaches the
customer.** `Consent::optOut()` clears intake consent on every open request
carrying the number (compared after E.164 normalisation), with or without a
customer record. `Consent::grantAtIntake()` runs on every intake save: if the
box is ticked and a customer exists on that number who is not currently
consenting, they are opted in as `verbal_at_intake` with an audit row naming
the request and the dispatcher. See DECISIONS "The intake consent box is
consent, both ways".

## 12. Money is written once

Idempotency keys are generated on the server before any processor call, never by
the browser. Partial payments are supported; the invoice recalculates to
`PARTIAL` or `PAID` from the sum of completed payments, and only flips to `PAID`
when the total is greater than zero and the balance is within a cent.

There is exactly one path by which money is written down — `PaymentController::record()`
— used by the till and by the payment webhook alike, so audit, receipt
generation and recalculation cannot differ between them.

A unique index on `payments.processor_ref` makes a replayed provider callback a
no-op rather than a second payment. That guarantee lives in the database, not in
application logic, because a race between two concurrent callbacks would defeat
a check written in PHP.

## 12a. Unauthenticated input is verified before it is read

Three routes are reachable without a session: two webhooks and the customer
checkout page.

The **webhooks** verify their provider's signature before parsing the body; a
failure is logged and dropped. Telnyx callbacks older than five minutes are
refused even when correctly signed, so a captured request cannot be replayed
later. A handler that crashes answers 5xx rather than 200, because a provider
that receives a 200 stops retrying and the event is lost.

The checkout page is reached by a 16-byte random token and shows only what a
payer needs: who is charging, for what, how much. It settles an invoice once —
the link closes on payment — and it refuses to take anything at all when a real
processor is configured, so it can never become a way to mark an invoice paid
without a payment.

## 13. Nothing is ever deleted

Every state change writes to `audit_log`: entity, action, actor, detail,
timestamp. Voids and credits replace deletion everywhere. `api_log` records every
call to an outside service, whether it was live or stubbed.

---

## Numbering

`PREFIX-YYYYMMDD-###`, assigned once and never changed.

| Prefix | Document        |
|--------|-----------------|
| `SER`  | Service Request |
| `EST`  | Estimate        |
| `WOR`  | Work Order      |
| `INV`  | Invoice         |
| `PAY`  | Payment         |
| `RCT`  | Receipt         |
| `EXP`  | Expense         |
| `DIA`  | Diagnostic Report |

A repair **option** on a diagnostic report is an ordinary `EST` — same
numbering, same lines, same authorization. It carries `diagnostic_report_id`,
`option_label` and `option_timeframe`, nothing else is different. Options on
one report are mutually exclusive: authorizing one declines its siblings as
superseded (`Rules::optionAuthorizeGate`).

## Customer-facing money

Customer documents — printed estimates, invoices, receipts, diagnostic
reports, the signing and checkout pages — show **price and total only**.
Unit cost, markup, profit and margin never leave the staff screens. On a
staff screen handed to a customer for a signature, every such figure is
tagged `.internal` and hidden while the customer-facing modal is open.
| `JE`   | Journal Entry   |

## Roles

| | Admin | Dispatch | Technician |
|---|---|---|---|
| Requests, estimates, invoices, customers | ✓ | ✓ | — |
| Work orders | ✓ | ✓ | own only |
| Field status, field lines, VIN, completion | ✓ | ✓ | own only |
| Settings, users, reports | ✓ | — | — |
| Void an invoice | ✓ | — | — |

A technician opening a work order that isn't theirs gets a 403.

## Data conventions

- Phones stored E.164 (`+15035550123`), displayed `(503) 555-0123`.
- Money is `DECIMAL(12,2)`; tax is computed **per line** — each taxable line's
  tax rounded to the cent, then summed (`Lines::taxCents`), never
  taxable-subtotal × rate rounded once — and the rate is snapshotted onto each
  document. A document-level discount is allocated across taxable lines pro
  rata before tax. Decided 2026-08-27: tenant accounts can be anywhere in the
  US, and per-line survives mixed jurisdictions and single-line credits.
- Oregon has no sales tax; the default rate is `0`.
- Timestamps are stored in the company timezone (`America/Los_Angeles`).

## Oregon law that the software has to carry

Researched 2026-08-18 against primary sources. Full working and citations in
`knowledge/EXTERNAL-SOURCES.md`; this section is only the part that binds the
application.

**Oregon does not license repair shops.** No registration, no bonding, nothing
like California's Bureau of Automotive Repair. ORS 646A.480–495 regulates
conduct, not entry. Several search-engine-prominent sites claim otherwise and
are wrong.

**A mobile mechanic IS a "vehicle repair shop".** ORS 646A.480 defines it as any
entity "that in exchange for payment evaluates the condition of, maintains or
repairs a motor vehicle" — no premises requirement. All of the below applies to
White Knight. Body-and-frame shops are excluded and sit under ORS 746.292.

**An estimate is required before work begins** — ORS 646A.482, not merely on
request. It must describe the general nature of the work, divide it into
separate tasks so far as it can be divided, and list estimated labour, the parts
to be replaced, incidental charges, and a total. The document chain already
enforces this by construction.

**The 10% / $200 rule is why `variance_abs` and `variance_pct` have the values
they do.** ORS 646A.486 forbids exceeding the estimate "by more than 10 percent
or by more than $200" without fresh authorisation. Either condition triggers, so
`Rules::varianceThreshold` takes the MINIMUM of the two — the smaller binds
first. **These are statutory numbers, not tuning knobs.**

**Documents must be kept one year minimum** — ORS 646A.490. Satisfied already by
never deleting; keep seven years for IRS purposes regardless.

### Three gaps, still open

1. **Phone authorisation needs four fields.** ORS 646A.486 permits oral assent
   by telephone, but only if the shop records the **name and telephone number of
   the person assenting and the date and time of the call**. Today
   `variance_auth_name` and `variance_auth_at` exist; the assenting party's
   phone number does not, and neither does an explicit "authorised by phone"
   channel. A boolean is not a record.

2. **An estimate total may legally be a range.** ORS 646A.482(1)(c)(C): the
   total "may consist of a reasonable range." Estimates carry a single total, so
   the honest "let me look at it first" job cannot be quoted correctly.

3. **Parts condition must be disclosed** — new, used, rebuilt or reconditioned,
   on the customer-facing document. Misrepresenting it is a deceptive practice
   under the UTPA. `catalog_items` has no such field.

### Not found, and worth a lawyer

No Oregon statute sets a refund window for a refundable **core deposit**. The
30-day forfeiture window in Settings is a house rule, not law, and should be
confirmed before it is printed on an invoice as a deadline.
