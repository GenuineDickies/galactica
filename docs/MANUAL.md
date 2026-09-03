# White Knight Roadside — Admin · User Manual

*For dispatchers, technicians, and administrators. Covers the full dispatch-to-cash
workflow: taking the call, locating the caller, pricing and authorizing the work,
dispatching, closing out in the field, invoicing, and getting paid.*

---

## How to read this manual

Quoted text like **"Record authorization"** is the exact label on a button or
screen. Screens are named the way the sidebar names them. Each section notes the
role it applies to:

- **Admin** — everything, including settings, catalog changes, and voids.
- **Dispatch** — intake, estimates, dispatch, invoicing and payments. No settings,
  no catalog writes, no voids.
- **Technician** — their own assigned work orders, including invoicing and
  collecting payment on those jobs. No pricing history, no accounting screens.

One idea underpins everything else in this system, and it explains most of the
warnings you will meet: **a job is a chain of documents, and each link is earned,
never assumed.**

> Service Request → Estimate → Work Order → Invoice → Payment → Receipt

A request commits nobody to anything. An estimate becomes a contract only when
the customer authorizes it. A technician rolls only on an authorized estimate.
An invoice reflects only work actually recorded. Money is taken only against an
issued invoice, and every payment produces a receipt. Nothing in the system is
ever deleted — mistakes are corrected by voids and credits, and every document
keeps an append-only audit trail.

---

## 1 · Signing in

Open the site and enter your **"Email"** and **"Password"**, then **"Sign in"**.
A wrong password shows *"Those credentials did not match an active account."* —
note "active": a deactivated account fails the same way. **"Sign out"** is in the
top-right user chip on every page.

If a form ever responds with *"Session expired — go back and try again."*, your
sign-in timed out while the page was open. Go back, and re-submit.

What you see after signing in depends on your role. Admins and dispatchers get
the full sidebar — **"The chain"** (Dashboard, Service Requests, Estimates, Work
Orders, Invoices, Payments), **"Money"** (Expenses, Reports), **"Records"**
(Customers, Vehicles, Products & Services, Messages), and for admins only,
**"Admin"** (Settings, Markup matrix, Users) — plus the **"New Service Request"**
button above it all. Technicians get a single **"Field"** group: Dashboard, My
Work Orders, Vehicles, Products & Services.

The numbers on sidebar badges are live queues: pending requests, estimates
awaiting authorization, open work orders, unpaid invoices.

## 2 · The dashboard

**Admin / Dispatch.** Four cards up top — **"Revenue today"**, **"Active jobs"**,
**"Accounts receivable"**, **"Unpromoted requests"** — then the working queues:

- **"Intake queue"** — pending requests, highest priority first. This is the
  to-do list for promotion.
- **"In the field"** — work orders currently assigned, en route or on site.
- **"Awaiting authorization"** — estimates that cannot be dispatched yet. As the
  panel says: *"Nothing on this list may be dispatched. That is a gate, not a
  suggestion."*
- **"Ready to invoice"** — completed work orders with no invoice. **"Bill it"**
  starts one.
- **"Unpaid invoices"** — issued invoices with a balance.

**Technician.** One panel: **"Jobs assigned to you"**. Tap a job to update
status, add parts, capture the VIN and close it out.

---

## 3 · Taking the call — logging a Service Request

*Roles: Admin, Dispatch.*

Click **"New Service Request"**. The form's own words set the expectation: *"This
is a record that somebody asked for help — nothing more."* It does not have to be
accurate, it creates no customer and no vehicle, and it carries no prices.
Log what you heard and move on; verification happens at promotion.

Work down the form while you talk:

1. **"Log the request"** — how it came in (phone, website, text, provider
   dispatch, walk-up), whether it is **"Retail — direct customer"** or
   **"Provider / bulk referral"**, and for provider work, the provider account
   and their claim / PO reference.
2. **"Who said they need help"** — the name and callback number exactly as
   given. Tick **"Caller verbally agreed to receive SMS updates."** only if they
   actually did — every text the system ever sends is gated on consent, and this
   checkbox is what carries it until a customer record exists.
3. **"What they say they need"** — your best guess at the service, the
   priority, and **"The problem in their words"**. Priorities carry promised
   ETAs: Emergency 30 min, Urgent 60 min, Standard 2 hr, or Appointment.
   **Lockout calls:** if **"A child or pet is inside the vehicle"** is checked,
   the form escalates the call to Emergency and tells you to advise the caller
   to contact 911 or the fire department immediately if anyone inside is in
   distress. Do not delay that call for a quote.
4. **"Probably this location"** — wherever they say they are, in their words.
5. **"Vehicle as described"** — rough details so the driver can spot it. Never
   ask a stranded caller to hunt for the VIN; that is captured on scene.
6. **"Dispatcher notes"** — internal only, never printed on customer documents.

**"Log service request"** saves it and opens the request's page.

### When the caller can't tell you where they are

Use the **"Capture GPS — text the caller a location link"** button on the
location panel. It logs the request immediately, then texts the caller a link
that asks *their* phone for its position — it never reads a location from your
own machine. Requirements: a valid callback number and the consent checkbox.

What the caller gets is a one-tap page: **"Share my location"**, a phone
permission prompt, done. When they answer, the request fills in with the GPS
position (as a map link), the **nearest address**, and the **nearest
intersection**, and the audit trail records when the link was sent, opened and
answered. The link is one-shot and dies after 4 hours; sending a new one kills
the old one.

If the text cannot be sent you are told exactly why — no consent recorded, or
the number does not parse — and nothing is sent silently. You can send or
re-send from the request page later: the **"Locate the caller"** panel shows
sent/opened/expiry state and a **"Text a location link"** / **"Send a fresh
location link"** button.

## 3a · Choosing the category — what to put on the truck

*Roles: Admin, Dispatch at intake; the technician can correct it in the field.*

Two fields on the intake form look similar and are not:

| Field | What it is | Can it be corrected later? |
|---|---|---|
| **"Roll as"** | What dispatch decided to send. *"What the truck has to carry."* | Yes — at intake, at promotion, and again on the work order. |
| **"Probably this service"** | The caller's word for it, narrowed to what that truck can do. | **No.** It is testimony, and it is kept exactly as heard. |

**Answer them in that order — the form is built for it.** Pick **"Roll as"**
first and **"Probably this service"** narrows to the jobs that kit can actually
do. You cannot file a mount-and-balance against a roadside truck, because the
option is not there to pick.

The five choices are **Roadside Services**, **Advanced Tire Services**,
**Mobile Repair Services**, **Towing Services** and **Other**:

| Roll as | Services offered |
|---|---|
| **Roadside Services** | Jump Start · Lockout · Fuel Delivery · Spare Tire Swap (donut) · Tire Repair — plug |
| **Advanced Tire Services** | Tire Repair — internal patch · Tire Delivery, Mount & Balance |
| **Mobile Repair Services** | Parts Installation · Battery Replacement · Diagnostic |
| **Towing Services** | Winch Out · Flatbed Tow · Standard Tow |
| **Other** | Other |

### The question to ask

The categories divide on **what the job needs on the truck**, not on how
stranded the customer is. For tire work that reduces to one test:

> **Does the tire have to come off the rim?**

Note what that is *not* asking. It is not "does the wheel come off the vehicle."
Pulling a wheel is a jack and a lug wrench and every roadside truck carries
both — a plug is routinely done with the wheel off the car and the tire still on
it. The question is whether the **bead** has to come off the **rim**, because
that is what needs a bead breaker and a tire machine.

| Job | Wheel off vehicle | Tire off rim | Roll as |
|---|---|---|---|
| Spare tire swap | Yes | No | Roadside |
| Plug repair | Often | **No** | Roadside |
| Internal patch | Yes | **Yes** | Advanced Tire |
| Tire delivery, mount & balance | Yes (or loose wheel) | **Yes** | Advanced Tire |

Only the third column decides.

**This is the question to ask the caller**, and it is worth asking properly. "I
have a flat" is not an answer — a sidewall or a mount needs the tire kit, and a
second dispatch costs real money.

**Battery replacement is Mobile Repair, not Roadside.** It is hand tools, but it
is still a part coming off and a part going on — the same shape as an
alternator, only lighter. Its 36-month warranty and core deposit ride on the
*part*, which is billed separately either way.

**Winch-out is Towing, not Roadside.** A winch-out is vehicle recovery. The
customer will describe it as being stuck, which sounds like a soft service; it
needs a tow truck.

**Towing is there for other operators.** White Knight does not tow. If you are
running White Knight's own dispatch you will never pick it.

### Correcting it in the field

The work order carries **its own** category. Its **"Category"** panel shows
**"Dispatched as"** against **"Actually"**, and where the two differ it flags
**"Reclassified"**. To move it, use **"Change to"**, say why in a few words
(*"sidewall, had to demount"*), and **"Save category"**. Available until the job
is closed.

Doing that writes `category:changed` to the audit trail and **does not touch the
service request.** That is deliberate, and it is the whole point of keeping
three values instead of one:

| Question | Answered by |
|---|---|
| How often is intake wrong? | **"Probably this service"** vs the work order's category |
| How often does dispatch send the wrong kit? | **"Rolled as"** vs the work order's category |
| Which customers systematically misreport? | **"Probably this service"**, per customer |

Overwrite either of the earlier values to "tidy up" the record and the matching
number becomes unknowable. A fleet account that calls every tire job a "flat
tire" costs real money in second dispatches, and this is the only place in the
system that shows it.

### Category is not a revenue account

They are different axes and neither substitutes for the other. **Category** is
how the business is run — what to load, who to staff, where to advertise.
**Revenue account** is what was sold. A battery job books its labour and its
part to the same two accounts no matter which category it sits in.

---

## 4 · Working a Service Request

*Roles: Admin, Dispatch.*

The request page shows everything as reported, deliberately unverified: *"Nothing
here is verified yet"* until a customer is bound. From here you can:

- **Save dispatcher notes** — internal only.
- **Send an update** — once a customer is bound: technician en route, arrived,
  or an SMS opt-in confirmation. Every send is consent-gated; blocked messages
  say why.
- **Locate the caller** — see above.
- **Reject** (pending requests) or **"Cancel request"** — both demand a reason.
  The page's own advice: if a trip fee applies, bill it through an estimate and
  invoice rather than cancelling silently.

The **"Customer"** panel offers **"Possible matches on that number"** — treat
them as hints. As the screen says: a shared number is a hint, never an identity;
two people can use one phone.

### Promoting — where hearsay becomes a contract

Click **"Promote to estimate"**. This is the moment you confirm who the customer
actually is:

- **Bind an existing customer** with the type-ahead search (name, company or
  phone). Starred entries share the reported number — verify the name before
  binding. The full customer base is never listed; search is the only way in.
- **Or create the customer.** Pick who the customer is:
  - **Individual** — a person's first and last name are required.
  - **Commercial business** — the company is the customer of record; a company
    name is required, and the person is just the billing contact.
  - **Fleet operator** — a business whose *business is vehicles* (couriers,
    trucking, delivery). A business that merely owns several vehicles is
    Commercial.
  A valid 10-digit phone is required to create a record. Tick **"Customer
  consented to SMS updates."** if consent was given — it is timestamped and
  sourced on the customer record, and nothing can send without it.
- **Confirm the work** — service, service address, scope.

**"Promote and open the estimate"** creates the estimate as a draft and takes
you there. If an identical name + phone already exists, the existing record is
reused rather than duplicated.

---

## 5 · The Estimate — pricing and authorization

*Roles: Admin, Dispatch. The estimate is the quote; there is no separate quote
document.*

The estimate page tracks its own progress across the top: **Drafted ▸ Priced ▸
Authorized ▸ Dispatched ▸ Invoiced**.

### 5.1 Price the work

Everything billable lives in the **"Line items"** panel, and *only* catalog items
can go on it — free-typed lines are refused. Click **"+ Add from catalog"**,
search, pick the item, set the quantity.

Two money fields matter on every line:

- **"My cost (never shown to the customer)"** — what the part costs you. Typing
  a cost live-fills the customer price from the markup matrix.
- **"Customer price"** — the suggested price, editable. Editing it marks the
  line **"override"** so everyone can see the matrix was deliberately departed
  from.

Prices, costs and markup are **snapshotted onto the line when it is added**.
Editing the catalog or the markup matrix later never rewrites a document that
already exists. Internal views show cost, profit and margin on every line — the
rollup row is marked *"Internal only — never printed on the customer document"* —
while everything the customer ever sees shows price only.

A $0-cost part shows a **"needs pricing"** badge rather than pretending zero is
a price. Fix it before the document goes anywhere.

### 5.2 Get authorization

Nothing dispatches without authorization — the dispatch gate refuses with the
reason spelled out (no priced lines, or no authorization).

Optionally send the quote first: **"Send to customer"** marks it sent and texts
the estimate total (consent-gated, like every message).

Then **"Record authorization"**: who authorized (first and last name), and how —
**Verbal** (the normal roadside case), **Text message**, **In person**, or
**Provider PO**. Name, time, IP address and device are recorded.

**The $200 signature rule.** Verbal authorization is *always* enough to
dispatch — that is deliberate, so a truck can roll on a phone call. But when the
estimate total is over $200.00, the customer must additionally **sign the work
order before any work is performed** — on the technician's device on arrival, or
through a texted link. The screens repeat this at every step so nobody is
surprised on scene. Under the threshold, no signature is owed at all.

The **"Authorization rules"** panel on the right shows exactly where this
estimate stands: the threshold, whether a signature is needed, and the
**re-authorization trigger** — if the final invoice ends up differing from the
estimate by more than the lesser of $200 or 10%, the customer must re-authorize
before the invoice can be issued.

An estimate the customer refuses gets **"Mark declined"** with the reason.
Approved and declined estimates are locked; field additions go on the work
order instead.

### 5.3 Other things on the estimate page

- **"PO number"** — the customer's purchase-order number, if they issued one.
  It carries to the work order and invoice.
- **"Locate the customer"** — same location-link widget as intake. The position
  lands on this estimate; the service address you typed is never overwritten.
- **"Vehicle" / "Capture VIN"** — link a vehicle the customer already has, or
  capture a new VIN. A VIN is not required to estimate or dispatch — but it
  blocks work-order completion and invoicing, so the earlier it is on file the
  better. Only a valid 17-character VIN creates a vehicle record; the check
  digit is verified. If the vehicle has no plate, record why.
- **"Print / PDF"** — the customer-facing printable estimate.

### 5.4 Dispatch

**"Dispatch to a technician"** raises the work order — the document that
actually activates a technician. Pick a technician (only active technicians
accepting jobs are listed) or leave it unassigned and assign later. The
authorized scope is copied onto the work order; anything the technician adds in
the field is measured against it.

---

## 6 · In the field — the Work Order

*Roles: everyone. Technicians see only their own; the walkthrough below is
written for the technician holding the phone.*

Your dashboard and **"My Work Orders"** list what is assigned to you. A job
moves through **Pending ▸ Assigned ▸ En route ▸ On site ▸ In progress ▸
Completed**, and the header button always offers the next step.

### 6.1 Rolling

Tap **"En route"** when you leave, **"On site"** when you arrive. Both are
timestamped, and both automatically queue a customer text (en-route ETA,
arrival) when consent is on file — you do nothing.

### 6.2 Before touching the vehicle: the signature

If the estimate was over $200, the work order carries a red banner: **do not
begin work — the customer has not authorized it.** The estimate was approved
verbally so you could roll; the signature happens now, against the *real* number.
If the job has grown since it was quoted, add the work to the order **first**,
so the customer signs what they will actually be billed.

Two ways to take it:

- **"Display for customer"** — hand them your device. They read the line items,
  enter their name, sign on the full-screen pad. *"Capture signature and begin."*
- **"Send to customer via SMS"** — texts them a signing link for when they are
  not on scene (keys left, fleet vehicle, customer went home). The banner shows
  when the link was sent and whether they have opened it. No SMS consent on
  file? The system says so and you take the signature on your device instead.

Until it is signed, **"Begin work"** is disabled and the system will not let
work start. A signature taken *after* work started is caught at close-out and
cannot be recorded as authorized work — record what happened in the field notes
and escalate it. Under $200, none of this appears; **"Begin work"** is
immediately available.

### 6.3 On the job

- **"Begin work"** stamps the moment a hand went on the vehicle.
- **Add parts and labour** through **"+ Add from catalog"** — same picker, same
  cost→price suggestion, same snapshot rules as the estimate. The page warns as
  you add: if the total pushes past the authorized amount, a variance banner
  compares *"Approved scope"* against what is now on the order and tells you
  plainly whether the invoice will require the customer to re-authorize
  (more than the lesser of $200 or 10% over) or is still within tolerance.
- **"Capture VIN"** — your job, not the customer's. Read it off the dash plate
  or door jamb. An invalid VIN is refused (*"That VIN fails the check digit."*);
  a valid one creates or links the vehicle record and clears the invoice gate.
  No plate? Tick **"No plate on this vehicle"** and pick the reason.
- **"Photo evidence"** — before-and-after shots on every job, plus part, site
  and damage photos. As the panel says: this is what wins a dispute.
- **"Diagnostic report"** — when the job is a diagnosis, or the customer
  wants it in writing, open **"New report"** and write what you tested, what
  you found and what you recommend, in plain language. Say whether the vehicle
  is safe to drive. **Save draft** as often as you like; **Issue** freezes the
  words and opens the customer copy for print or PDF. An issued report never
  changes — a correction is a new report, and the old one stays on the work
  order. Internal notes on the form never print.
- **Repair options** — when there is more than one way to fix it ("replace
  the pump" or "replace the impeller only"), dispatch opens each one from the
  report as its own **estimate**, with a name and a time frame, and prices it
  there like any other quote. The customer copy prints the options side by
  side, each with its lines and total, price only. The customer picks one:
  authorizing that estimate declines the others as *superseded* — they stay
  on record, they just stop counting as open. One option per report; the
  system refuses a second authorization until the first is declined. The
  report itself is never a quote — the option estimate is.
- **The device in the customer's hands.** When you tap *Display for customer*
  or close out a job, every cost, profit and margin figure on the page is
  hidden until the modal closes. They see prices and totals, never our numbers.
  That said: a printed or texted document is always the cleaner handover.
- **"Category"** — if the job needed a different kit than the one you were sent
  with, correct it here and say why (section 3a). It records what the job
  actually was; the request keeps what dispatch rolled, on purpose.

### 6.4 Closing out

**"Complete this job"** opens the close-out. Record what actually happened:

- **"Outcome"** — Completed; **Attempted — unsuccessful (billable)**; Customer
  no-show; Unsafe to proceed; Tow required — out of scope. An unsuccessful
  attempt is still billable — the service fee applies regardless of outcome.
- **"What was done"** — the field record, in your words.
- **"Customer sign-off"** — ask every time. If they will not or cannot sign,
  say why in **"If unsigned, why"** — the job cannot close with both blank. Or
  use **"Send sign-off to customer via SMS instead"**: close the job with a
  reason now, and their signature attaches whenever they open the link.

Completion is gated. The system refuses to close a job where the customer never
authorized the work, where the signature came after work started, or where the
VIN is missing and any line requires a vehicle. Each refusal states its reason.

**No-shows:** **"Customer no-show"** closes the order with that outcome. A trip
or no-show fee is still billable — add FEE-NOSHOW and invoice it, and attach a
photo showing arrival before closing.

### 6.5 Collecting on the spot

Once the job is completed, the header offers **"Create invoice"** — and as the
technician on the job, you can run the whole collection yourself, right at the
vehicle:

1. **"Create invoice"** opens the draft built from your work order. You cannot
   edit its lines — the field bills what the work order recorded; if something
   is missing, it belonged on the work order.
2. If a gate blocks issuing, the banner tells you which one: capture the VIN
   (**"Capture VIN on the work order"** takes you back), record **"No vehicle
   was serviced"** when that is the truth, or — if the total drifted past the
   tolerance — **"Capture re-authorization"**: the customer signs the new
   number on your device, same as any other signature.
3. **"Issue invoice"**, then **"Take payment"** — cash, card, check, ACH — and
   the receipt is generated on the spot. Or **"Send payment link"** to text
   them a checkout page and let them pay from their phone.

Dispatch and admin can do all of this from the office instead; nothing requires
the technician to bill if the business prefers office billing. A technician can
only ever open the invoice for a job assigned to them.

---

## 7 · Billing — the Invoice

*Roles: Admin, Dispatch — plus the assigned technician, for their own job only
(see 6.5). Technicians settle invoices but never edit lines, PO numbers, or
anything on the invoice lists.*

An invoice is created from a completed work order (**"Create invoice"** /
**"Bill it"**), so the bill always reflects work that was actually performed —
raising one while the work order is still open is refused. With no work order at
all (rare), it builds from the authorized estimate scope.

A draft invoice is editable: line items, PO number, and the gates below. Then
**"Issue invoice"** locks it forever — corrections from that point are voids or
credits, never edits.

### 7.1 The two gates on issuing

**The vehicle gate.** A VIN must be on file before money changes hands. If the
work order was closed properly this is already satisfied. The exception is real
work with no vehicle serviced — a loose wheel mount and balance, for instance:
if *every* line on the invoice is a catalog item flagged "no vehicle needed",
record the reason under **"No vehicle was serviced"** and the gate clears.

**The variance gate.** If the invoice total differs from the approved estimate
by more than the lesser of $200 or 10%, an amber banner demands
**re-authorization**: the customer's name and signature agreeing to the new
amount, captured through **"Capture re-authorization"**. The **"Against the
estimate"** panel shows the approved figure, this invoice, the signed variance
and the tolerance at all times.

### 7.2 Issuing and terms

**"Issue invoice"** stamps the issue date, computes the due date from the
customer's payment terms — COD accounts are due on receipt; Net 15/30 accounts
add days — locks the document, marks the originating service request
**Completed**, and texts the customer their total (consent-gated). Terms are
snapshotted per invoice: changing an account's terms later never changes an
invoice that already exists. Issued invoices past their due date show an
**"Overdue"** badge.

### 7.3 Voiding

Admin only, reason required, and only while nothing has been paid — an invoice
with payments needs a refund instead. Voiding keeps the document and its whole
history; nothing is ever deleted.

---

## 8 · Getting paid

*Roles: Admin, Dispatch — plus the assigned technician, for their own job only.*

Payments can only be taken against an **issued** invoice.

### 8.1 Take a payment

**"Take payment"** on the invoice: amount (prefilled with the balance), optional
tip, method — **Card**, **Cash**, **Check**, **ACH**, or **Provider remit** —
and a reference (processor id, check number, remittance ref). A double-click can
never double-charge: the idempotency key is generated before any processor call,
and a duplicate reference is recognised and written exactly once. Tips are
tracked separately and are not taxable.

Every payment generates a **receipt** automatically. Full payment marks the
invoice **Paid**, closes any open payment links, and texts the customer their
receipt.

### 8.2 Payment links

**"Send payment link"** creates a hosted checkout the customer can pay from
wherever they are, and texts it to them. What the customer sees: their invoice,
line items, an optional tip picker (*"Tips go to the technician in full and are
not taxed."*), and a pay button. With no card processor connected, the built-in
demonstration checkout makes that plain on screen and charges nothing; with a
processor connected in Settings, links point at the processor's own hosted page
and its verified callback records the payment against the invoice exactly once.

### 8.3 The Payments screen

**"Payments"** lists everything collected — today's and this month's totals,
tips separated — with each payment's method, reference and receipt. Receipts
open as printable documents.

---

## 9 · Money — Expenses and Reports

*Roles: Admin, Dispatch.*

**Expenses.** Log parts, fuel and overheads so job profitability is real rather
than a guess: vendor, category, account code, amount, date, method. Optionally
attach an expense to a job — that is for margin analysis, not a second posting;
a cost hits the ledger once.

**Reports.** Pick a date range and **"Run"**:

- **Cash collected**, **Expenses**, **Net**, and **Receivable now**.
- **"Revenue by item"** — count, revenue, cost and margin per catalog item.
- **"Open receivables"** — who owes what, overdue flagged.
- **"By job source"** — retail vs provider. Retail keeps the full ticket;
  provider work trades margin for volume — watch this split.
- **"By payment method"**.

---

## 10 · Records

### 10.1 Customers *(Admin, Dispatch)*

Customers are usually created automatically during promotion — you rarely add
one by hand. The index is searched, filtered by **"All" / "Individuals" /
"Businesses"**, and deliberately capped: the full base is reached through
search, never listed wholesale.

Three customer types, and the distinction matters on every document:

- **Individual** — a person is the customer.
- **Commercial business** — the company is the customer of record; its name
  goes on every estimate, invoice and receipt, and the person is only the
  billing contact.
- **Fleet operator** — the customer's business *is* vehicles (couriers,
  trucking, delivery). A business that merely owns several vehicles is
  Commercial.

**Payment terms** live on business accounts: every account pays COD by card
unless you deliberately grant **Net 15** or **Net 30**. Changing terms only
affects future invoices — issued ones keep the terms they were created with.
Provider/broker accounts that send bulk work are flagged as such, with a
provider code.

Each customer record shows service history, invoices, outstanding balance and
lifetime revenue, their vehicles, and the two consent switches: **"SMS consent
on file"** and **"Do not contact — blocks every outbound message"**.

### 10.2 Vehicles *(all roles)*

One rule: **a vehicle record is created from a valid 17-character VIN and
nothing else.** A plate is a description, not an identity — it never creates a
record. The check digit is verified on entry; I, O and Q never appear in a VIN.
Duplicates are refused and you are pointed at the existing record. Vehicles
with no plate carry a recorded reason. Each record shows its service history
and an offline VIN decode (region, model year, serial) that needs no paid
service.

### 10.3 Products & Services — the catalog *(view: all roles; changes: Admin)*

Every line item on every document comes from here, and codes are never reused
once an invoice references them. Items are grouped as **Services**, **Parts**
and **Fees**, each with a pricing model — flat rate, hourly, per unit, or
**"Estimate required"** (shows as "quote" until priced on a real estimate).

Adding an item (**"Add item"**): leave the part number blank and it is
auto-assigned in the house numbering style, click **"Generate"** for a
suggestion, or type your own — duplicates are refused. Enter **"My cost"** and
the customer price fills in live from the markup matrix; edit the price and the
item is flagged **"override"**. Record the vendor and their part number for
reorders and warranty claims (internal only), the warranty months, and any
manufacturer warranty exactly as you want it stated on customer documents.
Tick **"No vehicle required."** for items that may be invoiced without a VIN —
that flag is what feeds the invoice's no-vehicle gate.

Items are never deleted: **"Retire"** removes one from the pickers while every
document that ever referenced it stays intact. **"Restore"** brings it back.

### 10.4 Messages *(Admin, Dispatch)*

The message log shows every outbound and inbound text with its status —
queued, sent, delivered, failed, blocked (with the reason), or received.

Two modes, stated in the banner at the top:

- **Outbox mode** (no messaging account connected): messages are composed and
  consent-gated exactly as if live, but not transmitted. Send them by hand and
  click **"Mark sent"**. This is also the practical way to test texted links —
  open the outbox row and use the link yourself.
- **Live mode**: sends go out through the connected provider, delivery receipts
  update the rows, and inbound replies are handled automatically.

**Consent is absolute.** Nothing sends without it, in either mode, and nothing
is blocked silently — a blocked message is logged with its reason. **STOP**
revokes consent and sets do-not-contact the moment it arrives; **START**
restores it; **HELP** gets a reply. If a customer texts you a reply on some
other channel, **"Record a reply"** runs it through the same handler so the
consent state is always honest.

---

## 11 · What the customer sees

Customers never sign in. They reach three kinds of one-time pages from texted
links, each guarded by a long unguessable token, and they see prices only —
never costs, never margins.

- **Signing page** — the work order with its line items and total, a name
  field and a full-screen signature pad. Used both to *authorize* work and to
  *sign off* on completed work. Links are single-use; a re-issued link kills
  the old one; when they sign, the signature lands on the work order instantly
  and the technician's screen unblocks.
- **Location page** — one button: **"Share my location"**. One-shot, expires
  in 4 hours, and shows the customer the address the system resolved so they
  can flag it if it looks wrong.
- **Checkout page** — the invoice, a tip picker, and a pay button.

Printable documents (estimate, invoice, receipt) carry the business block, the
bill-to (business accounts show the company with an *Attn:* contact), the
service and vehicle details, customer-facing line items, totals, payments, and
the stored terms and disclaimer text.

---

## 12 · Administration

*Role: Admin.*

### 12.1 Settings

- **Business** — legal name, phone, email, address as printed on documents.
- **Rates & tax** — tax rate (Oregon: 0), labour rate, mileage rate. Tax is
  applied per line and snapshotted onto each document.
- **Document text** — the terms printed on invoices and the footer disclaimer.
- **Integrations** — a status table shows each service, its driver and its
  state. The defaults work with no account anywhere: messages hold in the
  outbox, card payments are taken at the till, geocoding uses a free service,
  part numbers come from local rules. Switching a driver is a dropdown plus
  credentials. The **"Public base URL"** matters once texted links are in play:
  it is the address links are built from and what payment callbacks are
  verified against — set it to the site's public address.
  Credential fields are write-only: leaving one blank keeps the stored value,
  so saving the page can never disconnect an integration by accident; type a
  single `-` to clear one deliberately.
- **Hard business rules** — displayed read-only, because they are enforced in
  code, not adjustable per screen.

### 12.2 Markup matrix

The pricing policy for parts, in one editable table: cost bands and the markup
applied to reach the customer price, dropping as parts get more expensive. The
seeded defaults: $0–10 → 200%, $10.01–50 → 100%, $50.01–200 → 75%,
$200.01–500 → 50%, $500.01–1,500 → 35%, above that → 25%.

Tiers must be contiguous — no gaps, no overlaps — the first must start at
$0.00, and the top tier is open-ended (leave its max blank). A cost sitting
exactly on a boundary uses the **lower** tier. Every violation is named
on-screen and nothing partial is ever saved.

Editing the matrix never changes a price on any existing quote or invoice —
each line snapshotted its markup when it was added. The matrix is policy for
the *future*, never a rewrite of the past.

### 12.3 Users

Create accounts with name, email, password (minimum 8 characters, stored
hashed), role, and whether they **"Can be dispatched work orders"** — only
active, dispatchable technicians appear in dispatch pickers. Deactivating a
user disables sign-in without touching their history.

---

## 13 · The rules, in one place

Everything the system will stop you from doing, and why:

| Rule | What it means in practice |
|---|---|
| The chain is earned | Request → estimate → work order → invoice → payment → receipt. Each document is created from the one before it, never invented. |
| Catalog-only lines | Every billable line comes from Products & Services. Free-typed lines are refused. |
| Snapshot pricing | Cost, markup and price freeze onto a line when added. Catalog and matrix edits never rewrite documents. |
| Dispatch gate | Priced lines + recorded customer authorization, or no technician rolls. |
| $200 signature rule | Verbal always dispatches. Over $200, the customer signs the work order before work begins. |
| Signature precedes work | An authorization signed after work started cannot close as authorized work. |
| VIN or nothing | Only a valid 17-character VIN creates a vehicle. A plate never does. |
| Invoice vehicle gate | No invoice without a VIN — unless every line is flagged "no vehicle needed" and a reason is recorded. |
| Variance re-auth | Invoice differs from estimate by more than the lesser of $200 / 10% → name + signature before issue. |
| Terms are snapshotted | COD unless net terms were deliberately granted; issued invoices keep their terms forever. |
| Consent gates every text | No SMS without consent on file. STOP is honored instantly. Blocked sends are logged, never silent. |
| Location links ask *their* phone | "Capture GPS" texts the customer a link. It never reads a location from an office machine. |
| Category is corrected forward, never backward | The work order records what the job actually needed; the request keeps what dispatch rolled. Overwriting the earlier value destroys the only measure of wrong-kit dispatches. |
| The caller's words are never edited | "Probably this service" is testimony, kept as heard even when plainly wrong. Category is where the correction goes. |
| Nothing is deleted | Corrections are voids and credits. Audit trails are append-only. |

---

## 14 · When the system says no

The most common messages, and what to actually do:

| You see | Do this |
|---|---|
| *"Pick an item from the catalog. Free-typed line items are not allowed."* | Find or create the item in Products & Services first (Admin adds items). |
| *"No technician is dispatched without customer authorization on the estimate."* | **"Record authorization"** — a verbal is enough. |
| *"No work may begin on the vehicle until the customer signs this work order."* | Take the signature on your device (**"Display for customer"**) or text the link. |
| *"That VIN fails the check digit."* | Re-read the VIN from the dash plate or door jamb — a typo is far more likely than a bad plate. I, O, Q never appear. |
| *"VIN required: capture the VIN before completing this work order."* | Capture it on scene. If genuinely no vehicle was serviced, the *invoice* offers "No vehicle was serviced" — but only when every line allows it. |
| *"The final total differs from the approved estimate by more than…"* | **"Capture re-authorization"** — the customer signs the new number. |
| *"Message blocked: No SMS consent on file for this customer."* | Get consent and record it on the customer record — or use the phone. Never work around a consent block. |
| *"Location link blocked: No SMS consent was recorded at intake."* | If the caller verbally agreed, tick the intake consent box; otherwise ask them, then send. |
| *"This invoice has been issued and is locked."* | Void it (Admin, nothing paid) or record a refund — then raise a corrected invoice. |
| *"That link has already been used."* (customer reports) | Links are single-use by design. Send a fresh one. |
| *"Session expired — go back and try again."* | Sign back in and re-submit; nothing was written. |

---

## 15 · Reference

**Document numbers** are `PREFIX-YYYYMMDD-###` and never change: SER (service
request), EST (estimate), WOR (work order), INV (invoice), PAY (payment), RCT
(receipt), EXP (expense).

**Lifecycles:**

- Service Request: Pending → Accepted (promoted) → Completed (its invoice
  issued) · or Cancelled / Rejected (reason required).
- Estimate: Draft → Sent → Approved (locked) · or Declined.
- Work Order: Pending → Assigned → En route → On site → In progress →
  Completed · or Cancelled / No-show.
- Invoice: Draft → Issued (locked) → Partial → Paid · or Void. "Overdue" is a
  display state for issued invoices past their due date.
- Texted links: signature links are open until signed or superseded; location
  links expire after 4 hours; all are single-use.

---

*This manual describes the application as built. When a screen and this manual
disagree, the screen is newer — and the manual should be updated to match.*
