# Decisions

This application was reconciled from roughly a year of planning conversations
across three assistants — 247 transcripts in total. Those conversations
contradict each other in places, sometimes sharply. Where they did, a call had to
be made. Each one is recorded here with what was chosen, what was rejected, and
why, so it can be argued with later rather than rediscovered.

---

## The service request is separate from the estimate

**Chosen.** Six documents, not five. The request is thin, unverified and inert.

Several rounds of planning folded intake and pricing into one "job" record, which
is simpler and would have worked for phone calls. It breaks the moment a request
arrives electronically from a provider: that payload is data someone else's
dispatcher typed, and if it can create a customer, the customer table fills with
duplicates that later get invoiced.

Keeping them separate means the boundary between "somebody said this" and "we
have confirmed this" is a document boundary, not a discipline problem.

## Service Order was folded into Estimate

**Chosen.** One priced, authorized document rather than two.

An earlier design had a Service Order sitting between the estimate and the work
order, holding the authorization. In practice it held nothing the estimate
didn't already have, and it meant three documents existed before a technician
moved. The authorization now lives on the priced document, which is also the
document the customer actually saw — which is what makes it defensible.

If a provider workflow later needs a purchase-order document distinct from a
customer-facing estimate, that is a new document type, not a resurrection of this
one.

## Vehicle identity is the VIN, and only the VIN

**Chosen.** A plate never creates a vehicle record.

Plates transfer between vehicles, get obscured, and are frequently misread over
the phone. Plate-keyed records merge two customers' vehicles sooner or later.
The VIN is validated against the ISO 3779 check digit before a record can exist,
and the driver captures it on scene.

The cost is real: a job can run its whole course before a vehicle record exists.
That is why the VIN gate sits on work-order completion, where the technician is
still next to the vehicle, rather than only at invoicing.

## The signature is on the work order, and there are two ways to get it

**Chosen.** The estimate needs verbal approval only. The customer's signature
lives on the **work order**, and above the threshold it gates the start of work.

Two things fell out of putting it there.

The first is that the estimate stopped carrying a signature at all. Requiring
one to authorize would have made any job over the threshold undispatchable,
since nobody is in front of the customer at intake. Verbal approval releases the
truck at any amount; the signature releases the wrench. Two gates —
`dispatchGate()` and `workBeginsGate()` — instead of one doing both jobs badly.

The second is that the work order is the right document to sign anyway. By the
time the technician is on scene they know the real scope, so the number the
customer signs is the number they will be billed. Signing the estimate would
have meant signing a guess.

**Two capture paths, one signature.** *Display for customer* turns the
technician's device around: the customer reads the line items and signs on the
full-screen pad. *Send to customer via SMS* texts a tokenised link to the same
document. Both write the same column; `auth_method` records which was used,
alongside the IP, the user agent, and the moments the link was sent, first
opened, and signed.

An earlier version of this decision rejected remote signing outright, on the
grounds that a token proves a device opened a link rather than proving who held
the phone. That reasoning still holds for the *evidence*, which is why in-person
is the default path and the one the interface leads with. But it was answering
the wrong question: often the customer simply is not there — keys left, fleet
vehicle, driver gone home — and the choice is not between strong and weak
evidence but between a signature and none at all.

Consent is checked before anything is sent. `Sms::gate()` refuses without
`sms_approved`, honours `do_not_contact`, and records the block with its reason,
so a customer who never opted in cannot be texted even by accident. When the
gate refuses, the interface hides the SMS button and says why, leaving the
in-person path.

## The completion sign-off is asked for, not gated

**Chosen.** A signature or a recorded reason — never silence.

A customer cannot be compelled to agree the job was done well, so gating
completion on their signature would only teach technicians to fake it or leave
jobs open. But leaving the field blank makes "unsigned" indistinguishable from
"nobody asked".

So the completion sign-off is pushed hard — both capture paths are offered on
the close-out screen, including texting the link after the van has left — and if
it does not come, `unsigned_reason` has to say why. The reason lands in the
audit trail next to the outcome code.

## Beginning work is a recorded step, not an assumption

**Chosen.** `IN_PROGRESS` sits between `ON_SITE` and `COMPLETED`.

The rule is that work does not begin without a signed estimate. Enforcing that
needs a moment to attach to, and arrival is the wrong one: the technician has to
be on scene, and able to add lines, *before* the signature — otherwise the
customer is signing a number that is not what they will be billed. So the gate
cannot sit on `ON_SITE`.

The alternative was to check the signature only at completion. That is weaker
than the rule it claims to enforce: it proves a signature exists before the job
closed, not before the work happened. A technician could wrench first and sign
after, and nothing would notice.

So starting work became explicit. It costs one tap per job and buys a timestamp
that, set against `estimates.signature_at`, makes the ordering provable instead
of assumed — and `workOrderCompletionGate()` now refuses a job that was signed
after work began.

## The clock is pinned to the business's timezone

**Chosen.** `config.timezone`, applied in `App::boot()`, defaulting to
`America/Los_Angeles`.

Nothing had set a timezone, so PHP fell through to php.ini — and the local PHP
bundle shipped a European `date.timezone`. Every timestamp the application wrote was around
ten hours ahead of Oregon. Because `DocNumber::next()` keys on `date('Ymd')`,
any job taken after early afternoon was numbered with **tomorrow's** date, and
`doc_counters` incremented under the wrong day.

Document numbers are date-keyed and authorization timestamps are legal evidence.
Neither is something to inherit from whatever the host happens to ship, so the
application sets it and a test asserts it is neither UTC nor Europe/Berlin.

## Variance tolerance is the *lesser* of $200 and 10%

**Chosen**, over "$200 or 10%, whichever is greater".

Both readings appear in the notes. The greater-of reading means a $150 job can
double without anyone re-authorizing. The lesser-of reading gives small jobs
tight tolerances and large jobs a $200 ceiling — which is the direction that
protects both sides.

## Real authentication and roles from day one

**Chosen**, overriding an explicit note in the planning corpus to "build with no
security in mind" and add it later.

Three roles, enforced server-side on every route, from the first commit. Bolting
authorization onto a system that was written without it means auditing every
controller and every view. The roles are simple enough (Admin, Dispatch,
Technician) that they cost almost nothing to build in now.

What is *not* here, deliberately: rate limiting, password policy, 2FA, session
rotation, CSP. Those are hardening, and this is an MVP.

## The default drivers need no account, and are not inert

**Chosen.** Four interfaces — SMS, payments, VIN decode, geocoding — each
defaulting to a driver that works with no provider account at all, alongside
complete Telnyx and Square drivers that are one dropdown away.

The distinction that matters is between a *stub* and an *offline mode*. A stub
returns a canned failure and leaves a flow untestable. These drivers do the work
locally: messages are composed, consent-gated and queued to an outbox; payment
links open a checkout page this application serves; VINs decode from their own
structure. The entire chain — including taking a payment and handling a STOP
reply — can be walked before any account exists.

That matters practically. A2P 10DLC registration takes weeks, and a merchant
account is not instant. Neither should block building or evaluating the rest of
the system, and cash has to keep working whatever happens to a processor account.

Each writes to `api_log` identically, so the audit trail does not change shape
when a real driver is switched on.

## The driver is a setting, not just a config line

**Chosen.** `config.php` sets the default; a value in the `settings` table
overrides it.

Changing a driver by editing `config.php` on the production host means a
deploy round trip, which is a poor reason to be unable to switch a provider
on. The file still holds the default, so a fresh install is self-describing;
the override exists for the operator.

## Card payments are a link, not a terminal

**Chosen.** A checkout page, texted to the customer, recorded on payment —
served by Square when it is configured, and by this application when it is not.

The alternative is card-present capture in the field, which needs a terminal per
technician, a card-entry UI, and PCI scope this business does not want. A
roadside customer is frequently not standing next to the technician when the
invoice is settled anyway.

The consequence is that a payment arrives asynchronously, from an unauthenticated
endpoint — which is why the webhook path is the most carefully guarded code in
the application: signature verified before parse, database-level idempotency, and
5xx on failure so the provider retries.

## STOP is not reviewable

**Chosen.** An inbound STOP revokes consent the instant the callback lands —
no queue, no dispatcher confirmation, no undo prompt.

It is tempting to route opt-outs through a human, since customers sometimes text
"stop" meaning "stop calling me about *this* job". The regulatory exposure is
not worth it, and a customer who wants back in can text START. Consent is
restored with a fresh timestamp and a new source, so the record shows exactly
what happened and when.

## Catalog-only line items

**Chosen.** No free-text rows anywhere.

Free text is faster at the counter and destroys reporting: the same service ends
up spelled four ways and priced five, and nothing can be totalled by category.
Lines are snapshotted from the catalog at add time, so changing a price later
never rewrites a document that has already been quoted.

## A driver is either fully configured or not on

**Chosen.** Three stops, one principle: a stressed customer must never be left
waiting on a text that the system could have known up front was never going to
arrive.

1. **Activation gate.** Settings refuses to switch a driver to its live mode
   (telnyx, square) while required configuration is missing, forcing it back to
   the offline mode and naming the missing pieces. There is no half-connected
   state to be discovered in the field.
2. **Every-page banner.** `Health` (app/Domain.php) checks the active drivers'
   configuration on every admin page render; problems appear as a red banner to
   ADMIN and DISPATCH before a promise is made, not on a log page afterwards.
3. **Truthful send results.** `Sms::queue()` returns `sent` / `held` / failure
   distinctly, and every flash that used to say "texted to the customer" now
   checks `sent`, not consent. A held (outbox) send says "send it by hand"; a
   failed send says "call them — do not leave them waiting on a text."

The old behaviour combined the worst of all three: 'ok' meant only "consent
allowed the attempt", so `sendSignLink` flashed "Link texted" even when the
carrier had refused the message — and the customer it mattered most to was one
who had gone home and was waiting on that link to get their vehicle back.

**And a fourth stop, absolute: broken 10DLC compliance suspends ALL sending.**
`Sms::complianceStop()` runs before the carrier is contacted, on every send. If
any link in the compliance chain is broken — no registered campaign (messaging
profile ID), an unverifiable STOP path (missing sodium, missing public key,
non-https callback), or a message body with no opt-out language — the send is
refused, the row records the 10DLC reason, and the dispatcher is told the text
did not go. This is checked at send time rather than only at configuration
time, because an environment can degrade after settings were saved. There is no
carve-out for "this text probably won't get a STOP reply"; the carrier rules
have no such carve-out either. Honouring STOP is a precondition of sending, not
a feature beside it.

## "Unconfirmed" is a delivery outcome, not a failure

**Chosen.** A message carries one of QUEUED · SENT · UNCONFIRMED · DELIVERED ·
FAILED, and `delivery_unconfirmed` from the carrier maps to its own state
rather than being folded into FAILED.

Telnyx defines `delivery_unconfirmed` as *no delivery confirmation was received
from the carrier*. Plenty of carriers never return a receipt at all. Recording
that as a failure means a dispatcher re-texts a customer who already has the
message, and — worse — stops trusting the delivery column entirely. It is amber
in the UI, not red.

**Delivery status only moves forward.** Telnyx does not guarantee webhook
ordering and says `message.finalized` may arrive before `message.sent`. Statuses
are ranked and a callback may only advance one; DELIVERED and FAILED rank equal
and are terminal, so whichever the carrier reports first stands. This also makes
the handler idempotent under the provider's retries, which is required anyway.

**A refusal is logged with its cause.** `verifyWebhook()` returns a bare boolean
because that is all the HTTP response may reveal, but the log records which
problem it was — missing public key, missing sodium extension, stale timestamp,
or a genuinely bad signature. Those had all previously surfaced as the same
"signature did not verify", and "no receipts are arriving" is undiagnosable when
a missing PHP extension looks identical to an attack.

## Destroying data is gated on a file the owner edits, and it fails closed

**Chosen.** `data/wipe-policy.php` is the only thing that can authorise
`data/wipe.php`. Missing, malformed, not naming the target database, or
`allow_wipe` anything other than the boolean `true` all mean no.

The guard deliberately ignores command-line flags, environment variables and
interactive confirmation. Those are the levers reached for by a script, or by an
assistant working from a conversation where wiping *was* fine an hour ago. A
`--force` flag would make the whole thing decorative, so there isn't one.

Two independent gates beyond the switch: the policy must name the database, so a
permissive development policy cannot travel with the code and authorise
production; and no non-local MySQL host is wipeable regardless of what the
policy says.

**What it does not claim.** Anything that can edit `app/Guard.php` or the policy
file can defeat it. Nothing living in the repository can prevent that. This
stops accidents, stale instructions and confident misremembering — which is the
actual failure mode. For enforcement that survives a compromised repo, revoke
`DROP` and `TRUNCATE` from the application's MySQL user.

## Operational category is a separate axis from the revenue account

**Chosen.** Five categories — Roadside Services, Advanced Tire Services, Mobile
Repair Services, Towing Services, Other — stored on the request, the work order
and every catalog item, alongside a revenue set of five accounts that splits by
*what was sold* rather than by which service produced it.

The alternative was a revenue account per service: Jump Start Revenue, Lockout
Revenue, Tire Change Revenue. That was written down early and never built. It
fails because the chart of accounts then has to grow every time a service is
added, and it still answers the wrong question — an accountant wants labour
versus parts versus fuel, and an operator wants to know whether to buy tire ads
or a second bead breaker. One column cannot be both.

**Fuel delivery is the single exception that keeps its own revenue account**, on
the strength of having a matching COGS account (5090), a cost that moves with
pump prices, and tangible-personal-property tax treatment. It is not a
per-service account; it is a product line that happens to be delivered.

**The dividing line for categories is capability, not urgency.** A stranded
customer needing a spare swap and one needing an internal patch sound identical
on the phone and need different equipment on the truck. The test is whether the
tire has to come off the rim — specifically the bead off the rim, not the
wheel off the vehicle. Pulling a wheel is a jack and a lug wrench and stays
roadside; breaking a bead needs a tire machine and does not. The same question
sorts the rest: Mobile Repair is where a part comes off and a part goes on,
Towing is where the vehicle moves rather than the technician's hands.

**Towing ships even though White Knight does not tow**, because the platform is
multi-tenant and other operators run trucks. Its SKUs post to 4000 like every
other service — a towing revenue account was considered and rejected as exactly
the per-service tree this section already ruled out. An operator who wants tow
revenue on its own GL line adds that account themselves.

## The category is chosen first and it gates the service type

**Chosen.** Intake asks "Roll as" first and narrows "Probably this service" to
the jobs that category can roll. The pair is re-coerced server-side by
`ServiceCategory::coerceServiceType()`.

The original order was the reverse: name the service, then classify it. That
made a rep name a job and then answer a question about equipment they may never
have loaded, and it put the classification — the thing dispatch actually acts
on — downstream of a guess. `TIRE` was the standing casualty. One broad "Tire
Service" option covered four different jobs across two categories, defaulted to
Roadside because that was commoner, and had to be corrected by hand every time
it was wrong. The form flagged the choice as live, which is a warning label
standing in for a fix.

Inverting it costs two things and buys one. It costs the broad types: "Tire
Service" and "Mobile Mechanic" are gone as intake options, replaced by specific
jobs that each carry their own catalog line item. It costs a migration story:
old rows hold retired types, which are accepted and displayed but never offered
again — they read "(unspecified)" rather than being reassigned to a specific job
nobody verified. What it buys is that the mismatched pair is now unrepresentable
at intake rather than detectable afterwards.

## The caller's report and dispatch's decision are separate columns

**Chosen.** `reported_service` is never corrected; `service_category` on the
request holds what dispatch rolled for; the work order carries its own copy that
the technician changes when the job turns out to be something else.

Three fields for what looks like one fact, because the gaps between them are the
only measurements of intake accuracy and dispatch accuracy that exist. Collapse
them into one mutable field — the obvious design — and the first correction
destroys the evidence that a correction happened. It also silently loses which
customers systematically misreport, which is a real cost in second dispatches.

Reclassification writes `category:changed` to the audit log and does not touch
the request, consistent with **nothing is ever deleted**.

## No framework

**Chosen**, and reversed once during planning.

An intermediate build used Slim 4 + Twig + Eloquent and worked well. The final
call was a hand-rolled front controller and plain PHP views, for one reason:
shared hosting with no Composer workflow. A vendored Slim install is 20 MB of
code nobody on this project will read, uploaded over the wire. The router is 40
lines; the view layer is `require`. (The host does have SSH — the deciding
constraint was Composer-less dependency management, not shell access.)

The trade is real — no PSR middleware, no Twig autoescaping (hence `e()` on
every output), no Eloquent relations. For an application this size that is a
reasonable price for a folder that can be dragged onto any host.

## MySQL is the deployment target; SQLite exists for the tests

**Chosen**, and reversed once during planning.

An earlier build defaulted to SQLite so the app would run the moment it was
unzipped, with MySQL a config flag away. The final call is the other way round:
MySQL is the default, the install is a documented four steps, and
`data/schema.mysql.sql` ships ready to import through phpMyAdmin.

The reason is that a system which is *developed* on one engine and *deployed* on
another accumulates differences nobody notices until production — and this one
already found two: MySQL has no `CREATE INDEX IF NOT EXISTS`, and it returns
`DECIMAL` as `"0.00"` where SQLite returns `"0"`.

One `Schema` class still emits both dialects, and the full test suite passes on
both, so those differences stay found rather than latent. SQLite's remaining job
is letting the suite run without a database server.

A database that cannot be reached produces a plain explanation — which database,
on which server, and the commands to fix it — rather than a stack trace.

## Navy as the brand colour

**Chosen**, over the amber/black scheme that appears in some of the earlier
design notes.

Amber is a *status*. Using it as chrome means the interface cannot signal
"caution" without competing with its own navigation. Navy carries the brand;
amber, red and green are reserved for state, and there is exactly one glowing
primary action per screen so the next step is never ambiguous.

## Accessibility is a hard rule, and the lint enforces the mechanical part

**Decided 2026-09-03.** Accessibility: WCAG 2.1 AA is a hard rule for every
view; `tests/a11y_lint.php` enforces the mechanical part.

The two users who matter most cannot ask anyone for help: the stranded caller
on `/locate`, `/sign` and `/pay`, and the technician on a phone at the
roadside. A control with no name, a row that only a mouse can open, or a
status message that a screen reader never hears is a failure for exactly
those people. The six standing rules live in `AGENTS.md` → Writing views;
the lint (labels, `scope`, real links in clickable rows, dialog semantics,
live regions, glyphs, `disabled` reasons, headings, focus outlines, and the
contrast of every text/background token pair actually used) runs as part of
Definition of Done and must be at 0 failed. Two token changes came out of the
contrast check: `--text-faint` and `--slate` were lifted and `--danger` was
brightened slightly so each passes 4.5:1 on the panel surfaces they sit on.
A signature can be given by typing a name (rendered to the same PNG the
drawn path produces), so the authorization flow no longer depends on a
pointer.

## Deferred

Recorded here so they aren't mistaken for oversights.

- **Chart of accounts.** Two incompatible numbering schemes exist in the
  planning notes. Account codes are carried on catalog items and expenses so the
  data is there, but no ledger is built until the scheme is settled.
- **Provider API intake.** The `PROVIDER_API` channel and the thin request shape
  exist precisely so this can be added without a migration. No endpoint yet.
- **Scheduling and routing.** Priority and promised ETA are recorded; there is
  no calendar, no route optimisation, no technician availability model.
- **Recurring and fleet contracts.** Fleet customers exist as a customer type;
  contract pricing does not.
- **Inventory.** Parts are catalog items with a cost, not tracked stock.
- **Customer portal.** Documents print to PDF for emailing; there is no
  self-service login.
