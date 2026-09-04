# 10DLC / A2P Messaging Compliance Audit

**Scope:** every SMS path in the WKR admin application — `Sms`, `Consent`, `Health`,
`TelnyxSmsGateway`, `WebhookController::inboundSms`, and the consent capture UI.
**Date:** 2026-08-06 · **Status:** P1-A, P1-B, P1-C and P2-H resolved (see each finding); P2-D–G, P3-I–K remain open — tracked in `OPEN_ISSUES.md`.

---

## 0. The rule set being audited against

| Layer | Authority | What it binds |
|---|---|---|
| Statute / regulator | TCPA + FCC consent-revocation rules ([FCC 2024 order, Fed. Reg.](https://www.federalregister.gov/documents/2024/03/05/2024-04587/strengthening-the-ability-of-consumers-to-stop-robocalls)) | Consent, revocation "by any reasonable means", 10-business-day honor window |
| Industry | [CTIA Messaging Principles & Best Practices (May 2023)](https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf) | Opt-in confirmation, opt-out, HELP, privacy policy, sender identification |
| Carrier / registry | [TCR campaign registration](https://support.telnyx.com/en/articles/9940291-10dlc-campaign-compliance-requirements) | Declared keywords, sample messages, privacy policy URL, ToS URL |
| Provider | [Telnyx 10DLC keywords & confirmation messages](https://support.telnyx.com/en/articles/10645338-10dlc-keywords-and-confirmation-messages) | Profile-level auto-response and opt-out list |

Since 1 Feb 2025 carriers **block** (not throttle) 100% of unregistered 10DLC traffic
([Infobip A2P 10DLC guide](https://www.infobip.com/blog/what-is-a2p-10dlc)).

---

## 1. What the application already gets right

Worth stating plainly, because most of the hard parts are done:

- **Every outbound template carries opt-out language**, and `Sms::complianceStop()`
  (`app/Domain.php:920`) refuses to hand a body to the carrier if the string `STOP`
  is absent. Enforcement at send time, not config time.
- **Compliance failure suspends sending outright.** `Health::stopSendBlock()`
  (`app/Domain.php:738`) halts *all* outbound texting when the messaging profile ID
  is missing, when sodium is unavailable, when the Telnyx public key is unset, or
  when the base URL is not HTTPS — i.e. whenever inbound STOP could not be verified.
  This is the correct direction of reasoning and is rare in home-grown systems.
- **Consent is a hard gate, not a warning.** `Sms::gate()` (`app/Domain.php:865`)
  checks `do_not_contact`, `sms_approved`, and a valid E.164 number before anything
  is attempted.
- **Blocked sends are recorded, not dropped silently** (`Sms::record()`), with
  `blocked_reason` persisted to `messages`.
- **Webhook signatures are verified before the body is parsed**
  (`app/Controllers/WebhookController.php:66-73`).
- **A verbal opt-out path exists** for stops heard on a call, and it requires an
  evidence note (`app/Controllers/RecordsController.php:640-684`) — that note is
  exactly what an FCC or carrier complaint response needs.
- **Opt-out is global rather than per-campaign**, which is stricter than CTIA requires.
- **No promotional/marketing template exists.** The whole footprint is transactional
  customer care, which is the lowest-risk 10DLC use case and keeps TCPA quiet-hour
  and one-to-one-consent exposure minimal.

---

## 2. Findings

Severity: **P1** = live carrier/regulatory violation risk · **P2** = compliance gap
that a carrier audit or TCR vetting would flag · **P3** = hygiene / drift risk.

---

### P1-A — An SMS opt-out does not clear intake consent, so a customer who texted STOP can still be texted

**RESOLVED 2026-09-04.** `Consent::optOut()` now calls `Consent::revokeRequests()`, which zeroes `comms_consent` on every open request whose `reported_phone` normalises to the customer's number (the verbal path reuses the same routine). `Sms::queueForRequest()` additionally looks up the customer — by `customer_id`, else by phone — and refuses when `do_not_contact = 1`, so re-ticking the intake box cannot undo a STOP. Covered by `tests/sms_delivery.php` ("inbound STOP from a known customer…" and "after STOP, queueForRequest is blocked…").

**Evidence.** `Consent::optOut()` (`app/Domain.php:820-829`) updates `customers` and
writes an audit row. It never touches `service_requests.comms_consent`. But `Sms::queueForRequest()`
(`app/Domain.php:972-1002`) gates *solely* on `$sr['comms_consent']` — it never looks
at the linked customer's `do_not_contact` / `sms_approved`, even when
`$sr['customer_id']` is populated.

**Live path.** Customer texts STOP → `Consent::optOut()` sets `do_not_contact = 1` →
open service request still has `comms_consent = 1` → dispatcher clicks "text location
link" (`ServiceRequestController::textLocationLink`, line 250-265) →
`queueForRequest()` passes its gate → **message goes to a number that revoked consent.**

Note the verbal path already does this correctly — `RecordsController.php:667-671`
loops the matching service requests and zeroes `comms_consent`. The SMS path is the
one missing that step, which makes this an inconsistency rather than an oversight in principle.

**Why it matters.** Revocation must be honored across the sender's messaging, not per
record ([FCC revocation rules](https://www.manatt.com/insights/newsletters/tcpa-connect/fcc-adopts-revocation-rules)).
Each subsequent message is independently actionable under the TCPA.

---

### P1-B — STOP from a number with no customer record is recorded but not acted on

**RESOLVED 2026-09-04.** `WebhookController::inboundSms()` handles STOP before the no-customer return: with no customer it calls `Consent::revokeRequests($from, …)` and logs how many requests were cleared. `msg_index.php` copy rewritten to state what actually happens. Covered by `tests/sms_delivery.php` ("inbound STOP from an unknown number…").

**Evidence.** `WebhookController::inboundSms()` (`app/Controllers/WebhookController.php:161-164`)
returns early when no `customers` row matches `phone_e164`. The STOP branch at line 166
is never reached.

**Live path.** A stranded caller exists only as a service request (the documented
pre-promotion state). They text STOP. The message is logged; `comms_consent` on their
request stays `1`; the dispatcher can keep texting.

Telnyx's own opt-out list will mask this while `driver_sms = telnyx`
([Telnyx opt-out keywords](https://support.telnyx.com/en/articles/1270091-sms-opt-out-keywords-and-stop-words)),
so outbound will be rejected at the provider. **It is not masked when
`driver_sms = outbox`**, where a human sends from a phone and the app is the only
record of consent — and the app will still show consent as granted.

**Compounding.** `app/Views/pages/msg_index.php:16` tells the operator *"STOP and START
are applied to consent automatically."* For any number without a customer record that
statement is false, and it is the sentence a dispatcher would rely on. The UI copy needs
to change alongside the handler.

---

### P1-C — "Opt out" (two words) is not recognised, though the FCC names it explicitly

**RESOLVED 2026-09-04.** `TelnyxSmsGateway::keyword()` normalises punctuation to spaces and tries the first two words as a phrase before the first word; `OPT OUT`, `STOP ALL`, `CANCEL SUBSCRIPTION` and `OPT IN` added to the lists. All seven FCC words plus casing/punctuation variants are pinned in `tests/sms_delivery.php`.

**Evidence.** `TelnyxSmsGateway::keyword()` (`app/Services/Services.php:246-254`)
strips non-alpha characters, then takes **only the first whitespace-delimited token**.
The STOP list (`Services.php:68`) contains `OPTOUT` but not `OPT`.

- `"STOP ALL"` → `STOP` → honored ✅
- `"opt out"` → `OPT` → **not honored** ❌

The FCC's standardized revocation list is *"stop, quit, end, revoke, **opt out**,
cancel, unsubscribe"* — these must be treated as per-se revocation
([Manatt summary of the FCC order](https://www.manatt.com/insights/newsletters/tcpa-connect/fcc-adopts-revocation-rules),
[Fed. Reg. text](https://www.federalregister.gov/documents/2024/03/05/2024-04587/strengthening-the-ability-of-consumers-to-stop-robocalls)).
One of the seven named keywords falls through.

---

### P2-D — Revocation in plain language is filed as a note, with nothing surfacing it

**Evidence.** Any inbound text that is not an exact keyword falls to
`Audit::log('customer', …, 'sms:reply', …)` (`WebhookController.php:182`). There is no
review queue, no flag, no dispatcher alert. `/messages` renders rows but nothing
distinguishes "quit texting me" from "ok thanks".

**Requirement.** Consumers may revoke by **any reasonable means**, and a sender may not
designate an exclusive method. Non-standard wording creates a **rebuttable presumption**
of revocation that the sender must overcome
([TermsFeed on the 2025 rule](https://www.termsfeed.com/blog/tcpa-2025-any-reasonable-means-opt-out/)).
"It wasn't the magic word" is not a defence; an unread audit row is not a process.

Related: revocation must be honored **within 10 business days**
([BCLP](https://www.bclplaw.com/en-US/events-insights-news/the-tcpas-new-opt-out-rules-take-effect-on-april-11-2025-what-does-this-mean-for-businesses.html)).
Nothing in the app tracks that clock for non-keyword replies.

---

### P2-E — HELP is refused to anyone who has opted out

**Evidence.** The HELP branch (`WebhookController.php:177-180`) calls `Sms::queue()`,
which runs `Sms::gate()` and blocks on `do_not_contact = 1`. After a STOP, a HELP reply
produces a `BLOCKED` row and no response.

**Requirement.** HELP must return program name, customer-care contact, and opt-out
instructions **regardless of subscription state** — it is the consumer's route back to
the sender ([CTIA MPBP](https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf),
[Telnyx short-code keyword standards](https://support.telnyx.com/en/articles/9311492-standards-for-us-short-code-keywords-help-stop-and-opt-in-confirmation)).

Same masking caveat as P1-B: Telnyx's profile-level auto-response covers this today,
but that is a provider default that Advanced Opt-In/Out configuration can override
([Telnyx Advanced Opt-In/Out](https://developers.telnyx.com/docs/messaging/messages/advanced-opt-in-out)).
The application should not depend on it.

---

### P2-F — No opt-out confirmation and no opt-in confirmation on the primary consent path

**Evidence.**
- STOP → `Consent::optOut()` → `return` (`WebhookController.php:166-169`). No
  confirmation message. (And per P2-E's gate, one could not be sent anyway.)
- The `optin` template (`app/Domain.php:861`) is well-drafted — program description,
  HELP, frequency, rates, "consent is not a condition of purchase", STOP — but it is
  sent from **exactly one place**: an inbound `START` (`WebhookController.php:173`).
  The primary consent path — dispatcher ticks the box at intake
  (`RecordsController.php:133-151`, `ServiceRequestController.php:373-386`) — sends **nothing**.

**Requirement.** CTIA requires an opt-in confirmation for recurring programs *regardless
of the opt-in method*, carrying program name, customer care contact, opt-out
instructions, frequency, and fee language
([CTIA MPBP §5.1.2.1](https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf)).
TCR asks for both the opt-in and opt-out confirmation text at registration
([Telnyx 10DLC keywords & confirmation messages](https://support.telnyx.com/en/articles/10645338-10dlc-keywords-and-confirmation-messages)).

Where verbal consent is the mechanism, the confirmation text is also the only durable
artifact proving the consumer's number was correct and the consumer was told the terms.

---

### P2-G — No privacy policy or terms-of-service surface anywhere in the application

**Evidence.** No `privacy` or `terms` route in `public/index.php`; no such view in
`app/Views/pages/`; `RecordsController::KEYS` (`RecordsController.php:739-746`) has no
policy-URL setting. `public/` contains only assets and the front controller.

**Requirement.** TCR campaign registration requires a **privacy policy URL** and a
**terms of service URL**, and the privacy policy must specifically address SMS —
including that opt-in data and phone numbers are not shared or sold
([Telgorithm](https://www.telgorithm.com/news/10dlc-and-your-privacy-policy),
[TCR campaign guidelines](https://help.servicetitan.com/docs/tcr-campaign-guidelines-and-requirements)).
The ToS must carry the SMS disclosure: message types, cadence, rates notice, HELP, and
opt-out. Missing or SMS-silent policies are a common campaign rejection / re-vetting cause.

This is a business deliverable more than a code one, but the app is the natural host
and the URLs must resolve before the campaign is submitted.

---

### P2-H — Templates advertise `APPROVE` and `PAY`, which the app does not handle

**RESOLVED 2026-08-27.** The "Reply APPROVE" / "Reply PAY" phrases were removed
from the `estimate` and `invoice` templates (`Sms::TEMPLATES`). Compliance does
not require those keywords — it requires that whatever a message solicits
actually works, and STOP/START/HELP do. Authorization and payment already
travel as links (`sign_auth`, `pay_link`), which do what the keywords falsely
promised. The `YES`-maps-to-opt-in behaviour is now acceptable: with no
template soliciting a non-consent reply, `YES` as a standard opt-in keyword is
its ordinary carrier meaning. If reply-keyword authorization is ever built, it
must be declared on the TCR campaign before the templates advertise it. The
original finding is kept below for the record.

**Evidence.**
- `estimate` template: *"Reply APPROVE to authorize"* (`app/Domain.php:849`)
- `invoice` template: *"Reply PAY for a payment link"* (`app/Domain.php:851`)
- `inboundSms()` branches only on `stop` / `start` / `help`
  (`WebhookController.php:166-180`). A customer replying `APPROVE` gets silence, and
  `PAY` gets silence.

Worse, `START` includes `YES` (`Services.php:69`) — so a customer replying `YES` to an
estimate is recorded as an **SMS opt-in event**, not an estimate authorization.

**Why it is a compliance item, not just a bug.** TCR requires declared keywords and
sample messages to reflect actual campaign behaviour; messages that solicit a reply the
sender ignores are a documented filtering and vetting trigger
([Telnyx 10DLC campaign compliance](https://support.telnyx.com/en/articles/9940291-10dlc-campaign-compliance-requirements)).
It is also a straightforward customer-trust failure: the estimate flow tells the
customer their reply authorizes work, and it does not.

---

### P3-I — HELP response hard-codes a phone number that also lives in config

**Evidence.** `app/Domain.php:860` embeds `(503) 764-3154` literally, while
`config.php:44` holds `company.phone`. Every other template interpolates `{co}` from
config. A number change updates one and not the other, and the HELP response is the one
message a carrier will actually read during a complaint review.

---

### P3-J — Sender identification depends on `company.short` matching the TCR brand

**Evidence.** Templates prefix `{co}` = `App::config('company')['short']` =
`"White Knight Roadside"` (`config.php:42`), while the legal entity is
`"White Knight Roadside, LLC"`. This is almost certainly fine — but the brand string in
message bodies should match the entity registered with TCR, since mismatches and
unregistered DBAs are an explicit filtering risk
([CTIA guidance summary](https://messageiq.io/blogs/ctia-messaging-principles-and-best-practices/)).
Worth confirming against the TCR brand record rather than assuming.

---

### P3-K — Consent records the *source* but not the *language*

**Evidence.** `customers.sms_consent_source` (`app/Schema.php:119`) stores a short slug
(`verbal_at_intake`, `reply_start_by_sms`). The consent script referenced in the UI
(`app/Views/pages/cust_new.php:40` — "Read the consent script before collecting the
number") is not stored, versioned, or captured.

Under a TCPA challenge the defence is proof of what the consumer was told and when.
The slug plus timestamp is decent; the actual script text, versioned, is materially better.
(The verbal opt-out path at `RecordsController.php:643-651` already demands a free-text
evidence sentence — the opt-**in** path should be held to the same standard.)

---

## 3. Summary table

| # | Finding | Severity | Primary evidence |
|---|---|---|---|
| A | SMS opt-out does not clear `service_requests.comms_consent` | **P1** | `Domain.php:820`, `Domain.php:972` |
| B | STOP from a non-customer number is not acted on | **P1** | `WebhookController.php:161` |
| C | FCC keyword "opt out" (two words) not recognised | **P1** | `Services.php:68,246` |
| D | Plain-language revocation only audited, never surfaced | P2 | `WebhookController.php:182` |
| E | HELP blocked for opted-out numbers | P2 | `WebhookController.php:177` + `Domain.php:865` |
| F | No opt-out confirmation; no opt-in confirmation on the intake path | P2 | `WebhookController.php:166`, `Domain.php:861` |
| G | No privacy policy / ToS page or setting | P2 | `public/`, `RecordsController.php:739` |
| H | `APPROVE` / `PAY` advertised but unhandled; `YES` misrouted to opt-in | P2 | `Domain.php:849,851`, `Services.php:69` |
| I | HELP template hard-codes the company phone | P3 | `Domain.php:860` vs `config.php:44` |
| J | Brand string in messages vs TCR-registered entity | P3 | `config.php:42` |
| K | Consent script text not captured or versioned | P3 | `Schema.php:119` |

---

## 4. Proposed remediation — **not implemented, awaiting approval**

Ordered by risk reduction per unit of change. Everything below is a proposal.

**Round 1 — close the send-anyway paths (P1)**

1. Move the cross-record revocation loop out of `RecordsController` and into
   `Consent::optOut()`, so *every* opt-out route — SMS, verbal, admin — clears
   `service_requests.comms_consent` for the same number. Single source of truth,
   matching the project's `Rules` convention.
2. Add a customer-record check to `Sms::queueForRequest()`: when `$sr['customer_id']`
   is set, run `Sms::gate()` on that customer as well and let the stricter answer win.
3. Handle STOP for numbers with no customer row — clear `comms_consent` on all matching
   service requests and audit it, before the "no customer" early return.
4. Rework `keyword()` to test the normalized phrase against multi-word entries, not just
   the first token; add `OPT OUT`, `STOP ALL`, `CANCEL SUBSCRIPTION`.

**Round 2 — required responses (P2)**

5. Add a `stop_confirm` template and a `Sms::queueCompliance()` path that bypasses the
   consent gate for exactly two message types — STOP confirmation and HELP — since both
   are *required to be deliverable to opted-out numbers*. Narrow, explicit, audited.
6. Send the `optin` confirmation whenever consent is first recorded, not only on `START`.
7. Add `APPROVE` and `PAY` handlers, and remove `YES` from the START list (or scope it
   so it only opts in when no estimate is awaiting a reply).
8. Add a `needs_review` flag on inbound non-keyword messages and surface an unread
   count in the `/messages` nav, with a 10-business-day age indicator.

**Round 3 — registration prerequisites (P2/P3)**

9. Public `/privacy` and `/terms` routes with SMS-specific language, plus settings for
   the URLs so TCR registration can point at them.
10. Replace the hard-coded HELP phone with `{phone}` from config; add a `Health` check
    that the brand string is set.
11. Store the consent script version and text alongside `sms_consent_source`.

**Testing.** Each round should extend `tests/sms_delivery.php` — in particular an
end-to-end case for finding A (inbound STOP → attempt `queueForRequest` on the same
number → assert blocked), which no current test covers.

---

## Sources

- [CTIA Messaging Principles and Best Practices, May 2023 (PDF)](https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf)
- [FCC, Strengthening the Ability of Consumers To Stop Robocalls — Federal Register](https://www.federalregister.gov/documents/2024/03/05/2024-04587/strengthening-the-ability-of-consumers-to-stop-robocalls)
- [Manatt — FCC Adopts Revocation Rules](https://www.manatt.com/insights/newsletters/tcpa-connect/fcc-adopts-revocation-rules)
- [BCLP — The TCPA's New Opt-Out Rules Take Effect on April 11, 2025](https://www.bclplaw.com/en-US/events-insights-news/the-tcpas-new-opt-out-rules-take-effect-on-april-11-2025-what-does-this-mean-for-businesses.html)
- [Nixon Peabody — FCC partially delays new TCPA consent revocation rules](https://www.nixonpeabody.com/insights/alerts/2025/04/11/fcc-partially-delays-new-tcpa-consent-revocation-rules)
- [TermsFeed — The 2025 TCPA "Any Reasonable Means" Opt-Out Rule Explained](https://www.termsfeed.com/blog/tcpa-2025-any-reasonable-means-opt-out/)
- [Telnyx — 10DLC Campaign Compliance Requirements](https://support.telnyx.com/en/articles/9940291-10dlc-campaign-compliance-requirements)
- [Telnyx — 10DLC Keywords and Confirmation Messages](https://support.telnyx.com/en/articles/10645338-10dlc-keywords-and-confirmation-messages)
- [Telnyx — SMS Opt-Out Keywords and Stop Words](https://support.telnyx.com/en/articles/1270091-sms-opt-out-keywords-and-stop-words)
- [Telnyx — Standards for US Keywords: HELP, STOP, and Opt-In Confirmation](https://support.telnyx.com/en/articles/9311492-standards-for-us-short-code-keywords-help-stop-and-opt-in-confirmation)
- [Telnyx — Advanced Opt-In/Out Management](https://developers.telnyx.com/docs/messaging/messages/advanced-opt-in-out)
- [Telgorithm — A Guide to 10DLC and Your Customers' Privacy Policies](https://www.telgorithm.com/news/10dlc-and-your-privacy-policy)
- [TCR campaign guidelines and requirements](https://help.servicetitan.com/docs/tcr-campaign-guidelines-and-requirements)
- [Infobip — A2P 10DLC guide: 2026 US compliance and regulations](https://www.infobip.com/blog/what-is-a2p-10dlc)
- [MessageIQ — CTIA Messaging Principles and Best Practices: 2026 Compliance Guide](https://messageiq.io/blogs/ctia-messaging-principles-and-best-practices/)

*This is an engineering compliance review, not legal advice. Findings A, B, C and G
carry legal exposure and are worth confirming with TCPA counsel before the campaign
goes live.*
