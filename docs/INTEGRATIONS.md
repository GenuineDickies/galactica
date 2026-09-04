# Integrations

Every third-party dependency sits behind an interface in `app/Contracts/`. The
application only ever talks to the interface, so swapping a driver is one line
in `config.php` plus credentials in Settings — no business logic moves, and no
controller changes.

**The default drivers need no account anywhere**, and they are not inert. SMS is
composed, consent-gated and queued into an outbox. Payment links open a checkout
page this application serves itself. VIN decoding runs offline. Every flow can be
walked end to end — issue a link, text it, take the payment, print the receipt,
handle a STOP reply — before a merchant account or a 10DLC registration exists.

The **Telnyx** and **Square** drivers are complete, including signed webhooks.

```php
// config.php — the default
'integrations' => [
    'sms'      => 'outbox',      // outbox | telnyx
    'payments' => 'manual',      // manual | square
    'vin'      => 'structural',
    'geocoder' => 'osm',         // osm | google | manual
],
```

A setting overrides the file, so the driver can be switched from Settings →
Integrations without editing anything on the server — changing a line in
`config.php` there would mean a deploy round trip.

The same screen shows which driver is running, its callback URL, and its state.
Every call — live or held — is written to `api_log`, so the audit trail does not
change shape when a provider is switched on.

`app/Services/Http.php` is the only place in the application that opens a
socket. TLS verification, timeouts and error handling are decided once, there.

---

## Credentials

Entered in Settings, stored in the `settings` table, never in a file that gets
committed. The credential fields are **write-only**: rendered as empty password
inputs, and only written when something is actually typed. Saving the Settings
page with a credential field left blank keeps the stored value, so a routine
edit to the company phone number cannot silently disconnect an integration. Type
a single `-` to clear one deliberately.

Credential *values* never appear in the audit log — only the names of the keys
that changed.

| Setting | Where it comes from |
|---|---|
| `app_base_url` | this install's public HTTPS URL |
| `telnyx_api_key` | Telnyx portal → API Keys |
| `telnyx_from` | your 10DLC-registered number, E.164 |
| `telnyx_profile_id` | Telnyx messaging profile |
| `telnyx_public_key` | Telnyx portal → the account's webhook public key |
| `square_access_token` | Square dashboard → Credentials |
| `square_location_id` | Square dashboard → Locations |
| `square_environment` | `sandbox` or `production` |
| `square_signature_key` | Square dashboard → Webhooks → signature key |
| `google_maps_key` | optional, for server-side geocoding |

**`app_base_url` must be exact.** Square signs its callbacks over the
notification URL concatenated with the body. If the URL registered in the Square
dashboard and the one stored here differ by so much as a trailing slash, every
callback fails verification and is rejected.

---

## Webhooks

Two unauthenticated routes, and the only ones in the application reachable
without a session:

```
POST  /webhooks/telnyx      delivery receipts, inbound SMS
POST  /webhooks/square      payment and refund events
```

They are exempt from the CSRF check — that check exists to protect a browser
session, and a provider has none — and carry a cryptographic signature instead.

Three rules hold in `WebhookController`:

1. **The signature is verified before the body is parsed.** Anything that fails
   is logged and dropped with a 403 that explains nothing. Telnyx signs with
   Ed25519 over `timestamp|body`; a callback more than five minutes old is
   refused even with a valid signature, so a captured request cannot be replayed
   later. Square signs with HMAC-SHA256 over `notification_url + body`, compared
   in constant time.
2. **Every handler is idempotent.** Providers retry. A unique index on
   `payments.processor_ref` means a replayed payment callback is a no-op rather
   than a second payment, and that guarantee lives in the database rather than
   in application logic.
3. **A crash answers 5xx, never 200.** A provider that receives a 200 stops
   retrying; a payment we failed to write would then be lost for good.

`tests/e2e.sh` proves all three against real signatures — including a tampered
body, an unsigned request, a replayed callback and a stale timestamp.

---

## SMS — Telnyx

**Default: `outbox`.** Messages are composed, consent-checked and written to the
`messages` table with status `QUEUED`, or `BLOCKED` with the reason when consent
is missing. The Messages screen is the outbox — a record of what *would* have
been texted. There is deliberately **no "mark sent" action**: a message either
went out through the connected carrier or it did not go out at all; a "sent"
the carrier never saw would be a fabricated delivery record (owner's decision,
2026-08-06 — see BUSINESS_RULES §11a). This is a real operating mode: A2P 10DLC
registration takes weeks, and the business needs the rest of the system before
it clears.

Consent changes the carrier cannot see — a caller says "stop texting me" on the
phone — are entered as a **recorded consent change** (who took the call, what
was said), applied through the same Consent logic the Telnyx webhook uses. An
earlier build fed a pretend inbound SMS through the webhook handler, which
wrote a message row for a text that never existed; that was replaced for the
same fabricated-record reason.

**Live.** `send()` POSTs to `https://api.telnyx.com/v2/messages`. Consent gating
(`Sms::gate()`) runs *before* the driver is called and is identical either way,
so a live gateway cannot message somebody who never opted in. A missing API key
or sending number degrades to the outbox rather than failing the request.

Delivery receipts update the message status; failures are visible rather than
silent.

### Inbound keywords

Handled automatically, and this is the compliance-critical part:

| Reply | Effect |
|---|---|
| STOP, STOP ALL, UNSUBSCRIBE, CANCEL, CANCEL SUBSCRIPTION, END, QUIT, REVOKE, OPT OUT (also OPTOUT, STOPALL) | customer: `sms_approved = 0`, `do_not_contact = 1`; every open service request on that number: `comms_consent = 0`; both audited. Works with no customer record. |
| START, UNSTOP, YES, SUBSCRIBE, OPT IN (also OPTIN) | consent restored with a fresh timestamp, confirmation sent — known customers only |
| HELP, INFO | help message returned |

Matching is on the leading phrase: the first two words are tried, then the
first word, after punctuation is dropped — so "opt out", "OPT-OUT", "Stop." and
"STOP texting me" all revoke, while "please stop by the shop" does not (the
FCC's per-se list is "stop, quit, end, revoke, opt out, cancel, unsubscribe").

STOP is honoured immediately and unconditionally. It is the one instruction that
is never queued, reviewed or second-guessed. Every inbound message is stored,
whether or not it matches a customer. Revocation reaches both consent gates:
the customer row (read by `Sms::queue`) and the request's intake consent (read
by `Sms::queueForRequest`, which also refuses when a customer sharing the
number is do-not-contact — the stricter answer wins).

Outbound templates all carry "Reply STOP to opt out"; the opt-in confirmation
carries the full 10DLC disclosure.

---

## Payments — Square

**Default: `manual`.** Two things, not one.

Cash, cheques and cards run on a separate terminal are recorded by hand, with a
reference field for the terminal's transaction id. This path never goes away
whatever else is configured.

It *also* issues payment links — pointing at `/pay/{token}`, a checkout page
this application serves. The token is 16 random bytes, the page is public
because the customer opening it has a link and nothing else, and paying records
money through exactly the same path a Square callback does. That makes the link
flow real before any merchant account exists rather than a rehearsal of it.

When Square is selected, links point at Square instead and `/pay/…` refuses to
take anything — so it can never become a way to mark a real invoice paid without
a real payment.

**Live.** Two paths:

- **`paymentLink()`** — creates a Square hosted checkout page and texts the URL
  to the customer. This is the normal way a roadside customer pays: the
  technician has no terminal, and the invoice is often settled after the van has
  left. The page is created against the invoice's outstanding balance, tipping
  enabled. When the customer pays, the `payment.updated` callback matches the
  provider's order id back through the `payment_links` table to the invoice,
  records the payment, issues the receipt and recalculates the balance.
- **`charge()`** — takes a tokenised card, for the case where one is on file.

**Idempotency is not the gateway's problem.** The key is minted server-side
before any driver is called and stored on the payment row. A double-click, a
retried request or a flaky connection cannot produce a second charge, and that
guarantee does not depend on which driver is in use.

Money crosses the API boundary as an integer in minor units, rounded once.
Floats are how cents go missing.

The webhook records **what the processor says was taken**, not what was
expected. If the customer pays a different amount or adds a tip, the invoice
balance is recalculated from the payments — the provider is the source of truth
for money that actually moved.

### Setting it up

1. Set the payments driver to Square in Settings, and enter the access token,
   location id and environment.
2. Set `app_base_url` to this install's public HTTPS URL.
3. In the Square dashboard, add a webhook subscribing to `payment.updated`
   pointing at `<app_base_url>/webhooks/square`.
4. Copy the signature key from that webhook back into Settings.

Start in `sandbox`. Square's test card numbers exercise the whole path —
including the callback — without moving real money.

---

## VIN decode

**`structural`.** Decoded from the VIN itself, offline: WMI, country of
manufacture, model year from position 10, serial. Free, instant, and enough to
sanity-check what the driver typed against what the customer said.

Check-digit validation (ISO 3779) is separate and always on. It lives in
`vin_is_valid()` in `app/helpers.php`, not in the decoder, because it is a
correctness rule rather than an enrichment — a VIN that fails it cannot create a
vehicle record no matter which decoder is configured.

A paid decoder (NHTSA vPIC, DataOne) drops in behind the same interface when the
extra attributes are worth paying for.

---

## Geocoding

Coordinates never come from the dispatcher's machine — a dispatcher at a desk
is not the stranded caller. "Capture GPS" texts the caller a one-shot `/locate/`
link (4-hour expiry, same token-is-the-access-control model as `/sign/`); the
customer's own phone answers with its position, which is snapshotted onto the
document that asked (service request or estimate) together with what the
geocoder driver resolves from it.

The driver answers one question: coordinates → nearest street address + nearest
named intersection (`Geocoder::reverse()`). The lookup is best-effort on top of
the coordinates, never a condition of storing them.

**`osm`** *(default)*. Nominatim for the address, Overpass for the intersection.
No account, no key — a fresh install resolves both on day one. Fair-use rate
limits apply, which roadside call volume is nowhere near.

**`google`.** Set the driver in Settings and add the Maps key. Better rooftop
accuracy for US addresses; intersections via `result_type=intersection`.

**`manual`.** No lookups at all. Coordinates still capture and store; the
address and intersection fields stay empty.

---

## Adding a driver

1. Implement the interface in `app/Services/Services.php`.
2. Add a branch to the matching factory in `Integrations`.
3. Add the driver name to the allowed values in `config.php`, and a row to
   `Integrations::status()` if it needs its own state wording.
4. Add credentials to `SettingsController::SECRETS` so they are masked and
   write-only, or `::KEYS` if they are not sensitive.
5. Log through `ApiLog::write()` so the new driver appears in `api_log` like
   every other one.
6. If it has callbacks, implement `verifyWebhook()` honestly — returning `true`
   because verification is inconvenient turns a signed endpoint into an open
   one — and add a signature test to `tests/e2e.sh`.

---

## Part numbering — Claude

New catalog SKUs can be assigned by Claude. When a Product or Service is added,
the house numbering rules and every existing part number are sent to the
Anthropic API, which returns a code in the same style that does not collide with
anything on file.

**Default: `rules`.** A local generator encodes the same scheme in code —
prefix by type (`SVC` / `PART` / `FEE`), a keyword abbreviated from the name, a
numeric variant. It needs no account, so the feature works on day one; turning
on Claude is a key in Settings, and the collision check, uniqueness guarantee
and `api_log` entry are identical either way.

**Live.** Set the *Part numbering* driver to Claude and enter an Anthropic API
key. `ClaudePartNumberGenerator` POSTs to `https://api.anthropic.com/v1/messages`
with the rules as the system prompt and the existing numbers in the user
message; the reply is cleaned to the allowed character set and checked for
uniqueness before it is offered. If the call fails for any reason, it falls back
to the local generator so a catalog save is never blocked.

### Setting it up

1. Set the *Part numbering* driver to Claude in Settings.
2. Enter the Anthropic API key (write-only, like every other credential).
3. Set the model to one your key can use. List them with a GET to
   `https://api.anthropic.com/v1/models`; `claude-haiku-4-5-20251001` is a good
   low-cost default. An invalid model id makes the API return a not-found error,
   which is logged and falls back to the local rules.
4. Edit the numbering rules to your house conventions — Claude follows them.

Every call is written to `api_log` with the assigned code, so there is a record
of what was generated and whether it came from Claude or the local rules.
