# The general ledger — build plan

Status: **Phases 0, 1 and 2 built and tested. Phases 3–5 outstanding.**
Written 2026-08-16.

Invoices, payments, voids and expenses now post. Refunds, credit memos and
Square settlement do not — see the Phase 2 leftovers below. The core lifecycle
(Phase 3) is the next build.

**Cutover:** the ledger starts from the day Phase 2 shipped. Documents issued
before that were never posted and are not backfilled, so any report covering an
earlier period will be empty. That was a deliberate choice over backfilling —
no invented dates, no risk of double-posting a voided or partly-paid document.
If opening balances are wanted later, one manual entry on a cutover date is the
way to add them, not a retrospective sweep.

This is the accounting build-out that `knowledge/WKR-KNOWLEDGE.md` recorded as
deliberately deferred. The stated reason for deferring it — "two incompatible
numbering schemes came out of the planning work, and rather than pick one in code
the data was tagged and the ledger left for later" — no longer applies:
`Accounts::DEFAULTS` settled on one scheme and the running application uses it.

The trigger was core deposits. Cores cannot be tracked correctly without a place
to hold a liability, and 2050 is currently a tag on a catalog item pointing at
nothing. Rather than build a core-specific subledger, the decision was to build
the ledger the cores were always going to need.

---

## Decisions taken up front

**Accrual basis.** Revenue posts when the invoice is issued, not when payment
arrives. Accounts Receivable is a real balance. This matches the posting matrix
already written in `knowledge/`, and it is what lets a core liability sit open
between the invoice and its settlement weeks later.

*Rejected:* cash-basis books. WKR runs fleet accounts on net terms, which is
precisely where cash basis goes blind — there is no "who owes me" view, because
until money moves nothing has happened.

**Cash-basis is a report, not a second set of books.** Taxes are commonly filed
cash basis. Rather than keep two ledgers, one accrual ledger is kept and a
cash-basis view is derived by excluding unpaid invoices. This is what QuickBooks
does.

**Every document posts, or the ledger is a lie.** A ledger holding only some
transaction types does not balance, and an unbalanced ledger is worse than none
because it looks authoritative. Invoices, payments, refunds, voids, expenses and
cores all post in this build.

**Entries are immutable.** Consistent with the existing never-delete convention:
a posted entry is never edited and never deleted. A correction is a reversing
entry that points back at what it reverses. This is the same rule `audit_log` and
document voids already follow.

**Integer-cents exact math.** Same rule as `Markup`. Debits and credits are
compared as integer cents, never as floats. An entry whose sides differ by one
cent must be refused, not rounded.

---

## What exists today

| Piece | State |
|---|---|
| `gl_accounts` table | Exists. Holds account number, name, type, active flag. |
| `Accounts::DEFAULTS` | Seeds 2050, five revenue accounts, ten COGS accounts. |
| `catalog_items.revenue_account` / `.cogs_account` | Exist, populated, used as tags only. |
| `expenses.account_code` | Exists, tags only. |
| `catalog_items.core_charge` | Column exists. **No form field posts it — always saves 0.** |
| Catalog edit | **Does not exist.** Routes are create, suggest-sku, toggle. |
| `doc_lines` account snapshot | **Missing.** Lines snapshot price, cost, markup, warranty — not accounts, not core. |
| Journal / ledger / trial balance | **Does not exist.** No table, no engine. |
| Asset and equity accounts | **Not seeded.** There is nowhere for cash or receivables to post. |

---

## Phase 0 — expand the chart of accounts · **done**

Nothing can post until there are accounts to post to. The seeded set was revenue,
COGS and 2050; a double-entry system needs the other side of every entry.

Added to `Accounts::DEFAULTS`, taken from the reconciled chart in
`knowledge/WKR-KNOWLEDGE.md`, which is the authority for these ranges:

**Assets:** 1000 Cash · 1010 Checking · 1015 Undeposited Funds ·
1050 Square Clearing · 1100 Accounts Receivable · 1120 Business Savings ·
1200 Parts Inventory · 1300 Prepaid Expenses · 1500 Service Vehicle ·
1510 Tools and Equipment · 1590 Accumulated Depreciation

**Liabilities:** 2000 Accounts Payable · 2010 Credit Card Payable ·
2020 Sales Tax Payable · 2050 Core Deposits Payable *(already seeded)* ·
2060 Customer Refunds Payable · 2300 Customer Deposits

**Equity:** 3000 Owner Equity · 3100 Owner Contributions · 3200 Owner Draw ·
3300 Retained Earnings

**Operating expenses:** 6010 · 6050–6080 · 6100 · 6110 · 6120 · 6130 · 6150 ·
6250 · 6300 · 6400 · 6500 · 6600 · 6800 · 6900 · 7000 · 7010 · 7020

*A drafting error caught in review, recorded so it is not repeated.* An earlier
pass of this plan numbered equity 3000 / 3100 Owner's Draw / 3200 Retained
Earnings, taken from the shorter list in `knowledge/source-notes/30-…`. The
reconciled chart in `WKR-KNOWLEDGE.md` disagrees and wins: Owner Draw is 3200 and
Retained Earnings is 3300. Where the source notes and the reconciled chart
differ, the reconciled chart is the one to follow.

*The 4xxx block was deliberately left alone.* The knowledge chart's per-service
revenue tree (4110 Battery Sales, 4200 Fuel Delivery, 4400 Platform…) is recorded
there as superseded and was never seeded. The five accounts already live —
4000, 4010, 4020, 4030, 4040 — are the settled set, and `data/seed.php` already
tags catalog items against exactly those. Reporting by service is done with
`service_category`, not with revenue accounts.

*6150 was already dangling.* `data/seed.php` has been writing an expense against
SMS Messaging since the demo data was written, with no such account existing —
the same silent-dangling-tag failure the `Accounts` comment warns about. Seeding
the expense block fixes it, and `tests/ledger.php` now asserts that every code
the seed references is a code the chart seeds.

Existing installs pick these up through the lazy seed already in `Accounts`,
which is additive — nothing already present is renamed, retired or renumbered.

*Deliberately not added:* 1350 Supplier Core Deposits Receivable. The single
2050 clearing approach was chosen for simplicity and is recorded as such in
`WKR-KNOWLEDGE.md`. Revisit only if supplier core balances grow enough to matter.

*Seeded active even where inapplicable:* 2020 Sales Tax Payable (Oregon has
none), 1200 Parts Inventory (parts are catalog items with a cost, not tracked
stock), and the 6050–6070 payroll block (WKR is owner-only; owner pay is 3200
Owner Draw, never a wage expense). The chart is a generic template for operators
this application may later serve. Retiring them is an operator decision made at
/accounts, not one this list makes for them.

## Phase 1 — the journal · **done**

Two tables and one service. This is the load-bearing phase.

`journal_entries` — one row per transaction: entry number (through the existing
`DocNumber`), entry date, source type (INV / PAY / REF / VOID / EXP / CORE /
ADJ / REV), source id, memo, posted-at, posted-by, period key, and for reversals
a pointer to the entry being reversed.

`journal_lines` — one row per side: entry id, line number, account number, debit,
credit, memo. Account number is snapshotted as text, the same way documents
snapshot prices and for the same reason.

`Ledger` in `app/Domain.php`, sitting alongside `Markup` and `Rules` as the
single source of truth for posting. Its contract:

- `post()` accepts an entry and its lines, refuses anything whose debits and
  credits differ by even one cent, writes both tables in one transaction, and
  audit-logs.
- `reverse()` writes the mirror entry and links the two. Never touches the
  original.
- Balance and trial-balance queries read only; nothing else in the application
  writes to these tables.

Also in this phase: `doc_lines` gains `revenue_account`, `cogs_account` and
`core_charge` snapshot columns, so a line carries the accounts it posted to even
after the catalog item behind it changes. Same principle as the markup snapshot —
editing the catalog must never rewrite history on an issued document.

## Phase 2 — posting rules · **done, with two gaps**

One rule per event, each living once, in `Posting` (`app/Domain.php`). `Ledger`
owns *how* an entry is written; `Posting` owns *what* to write.

Three properties every rule has:

**Idempotent.** Each checks `Ledger::forSource()` first and returns the existing
entry id rather than posting a second. A replayed webhook, a double-clicked
button or a retried request cannot double the books.

**Atomic with its document.** `Db::tx()` was made re-entrant so posting runs
inside the caller's transaction. If the entry cannot be written, the invoice
does not issue. An issued invoice with nothing behind it in the ledger is the
exact silent hole this build exists to close, so it fails loudly at the button
instead.

**Signed arithmetic, single sign convention.** Callers net amounts with ordinary
addition and one private helper turns a signed figure into a debit or a credit.
Nothing else can emit the negative-credit line that `Ledger::validate` rejects.

Following the matrix already written in `knowledge/`:

| Event | Entry |
|---|---|
| Invoice issued | Increase Accounts Receivable by the total · Increase the revenue accounts by their line amounts · Increase Sales Tax Payable by the tax · Increase Core Deposits Payable by any core lines |
| Payment received | Increase Cash, Checking or Square Clearing · Decrease Accounts Receivable |
| Overpayment received (not labelled a tip) | Increase Cash · Increase Customer Refunds Payable (2060) — held until a person resolves it; extra money is never guessed into revenue (2026-08-27) |
| Held overpayment confirmed as tip | Decrease Customer Refunds Payable · Increase Other Revenue (tips) |
| Held overpayment refunded | Decrease Customer Refunds Payable · Decrease the cash account it arrived on |
| Square settlement | Increase Checking by the net · Increase Merchant Processing Fees by the fee · Decrease Square Clearing by the gross |
| Refund issued | Decrease revenue or increase a contra account · Decrease Cash |
| Invoice voided | Reversing entry against the original, never a deletion |
| Expense recorded | Increase the expense or COGS account · Decrease Cash or increase Accounts Payable |
| Core charged | *(part of the invoice entry above)* |
| Core refunded to customer | Decrease Core Deposits Payable · Decrease Cash |
| Core credited by supplier | Increase Cash · **Increase** Core Deposits Payable |
| Core forfeited | Decrease Core Deposits Payable · Increase revenue |

A core has **four legs**, two per counterparty, and all four net 2050 to zero:
(1) you pay the supplier's core charge when buying the part — a purchase coded
to 2050 (debit); (2) you collect the deposit from the customer — the invoice
entry credits 2050; (3) you refund the customer when the core comes back —
debit 2050; (4) the supplier refunds you when you hand the core over — credit
2050. Leg 4 *raises* the account rather than lowering it: the supplier
repaying you undoes what you paid them in leg 1, not what the customer paid
you in leg 2. Reading it as "the liability should fall" is the intuitive
mistake. **The netting depends on leg 1**: the purchase of a core-bearing part
must be expensed with `account_code = 2050`, or the account overstates by
every supplier credit.

Oregon collects no sales tax, so the tax leg is normally zero. It is built
because the chart is a template and the tax engine is a planned module.

### Decisions inside the matrix worth knowing

**A card payment debits 1050 Square Clearing, never 1010 Checking.** The money
is not in the bank on the day of the swipe — it sits with the processor until
the transfer lands, days later, minus fees. Debiting Checking immediately would
show cash that does not exist and make the account impossible to reconcile
against a statement. This is the hinge of the whole payment model.

**A tip credits 4300 Other Revenue, not Accounts Receivable.** A tip is not part
of the invoice total. Crediting it to receivables would mark an invoice paid
that is not.

**Purchase tax is folded into the expense, not into 2020.** Tax *paid* on a
purchase is part of the cost of the thing bought. 2020 Sales Tax Payable is tax
*collected* and owed onward. Confusing the two is common and expensive.

**A line with no revenue account falls back by item type** — part to 4010,
service to 4000, fee to 4030 — rather than being dropped. Every line written
before the ledger existed has NULL here, and the catalog form legitimately
offers "— none —". Dropping the amount would silently understate revenue.

**An expense with no account code posts to 6900 Other Expenses.** An unposted
expense understates cost and overstates profit, which is the worse error.

**A zero-total invoice posts nothing.** A goodwill call-out written off to
nothing is legal; an entry with no amounts is not.

### Phase 2 leftovers — not built, deliberately

**Refunds and credit memos.** There is nothing to hook.
`InvoiceController::void` tells the operator to "record a refund instead" and no
such flow exists anywhere in the application. Building the posting rule before
the document it posts from would be writing against an imaginary shape.

**Square settlement** — gross in, fees out, net to Checking. Needs the transfers
report, which is not built. Until it lands, card money accumulates in 1050
Square Clearing and never moves to 1010, so Checking will understate the bank
balance and 1050 will grow without bound. This is the most visible incomplete
edge of the ledger and should be next after cores.

## Phase 3 — cores end to end

**Catalog.** A core-charge field on the create form — the controller already
reads `num('core_charge')`, so the column has been silently saving zero. And a
real edit path at `/catalog/{id}/edit`, which does not exist today for any field.
Editing a catalog item never touches issued documents.

**Lines.** `Lines::add()` carries the core charge from the catalog item onto the
document line, snapshotted.

**Custody.** A `core_records` table, one row per core charged, moving through:
charged → old part collected by technician (who, when) → returned to supplier →
credit received → settled. Or → forfeited. Every transition timestamped and
audit-logged, nothing deleted.

The physical chain is tracked in full because WKR is mobile. A shop has the old
alternator in a bin near the parts counter; here it rides in a van for days
between the job and the jobber, and that gap is where cores are lost. Recording
who picked the part up is what makes it findable.

**Forfeiture sweep.** A 30-day default window, settable, that lists cores old
enough to forfeit. It proposes; a human confirms. Nothing auto-posts to revenue.

*Open question, not settled here:* whether a 30-day forfeiture window is
enforceable as written under Oregon's Unlawful Trade Practices Act. No statute
setting a mandatory refund window was found, but absence of a search result is
not legal advice. Confirm with Oregon DOJ Consumer Protection or a business
attorney before printing a deadline on invoices.

## Phase 4 — reports

Trial balance. Account ledger detail. Accounts Receivable aging. Core deposits
outstanding with age. And the cash-basis view — the accrual ledger with unpaid
invoices and their tax and core legs excluded.

## Phase 5 — period locking

A closed period refuses new entries dated inside it. Corrections to a closed
period post as current-dated reversing entries. Without this, last year's numbers
change after you have filed on them.

---

## Testing — **built and passing**

`tests/ledger.php` — pure, no database, in the style of `tests/markup.php`.
59 checks. `php tests/ledger.php`

`tests/ledger_integration.php` — the write path against a real database, in the
style of `tests/pricing_integration.php`. 47 checks.
`WKR_DB_PASS=… php tests/ledger_integration.php`

Between them:

- No entry can post with unbalanced sides, including one-cent drift — refused at
  both the pure and the write path, and nothing is written when refused.
- A line carries one side or the other, never both, never neither, never negative.
- Every account type moves in its natural direction.
- A reversal exactly cancels its original and leaves both rows intact; the same
  entry cannot be reversed twice.
- A full core lifecycle — charged, refunded, credited, paid — nets 2050 to zero.
- A forfeited core moves exactly the core value into revenue, and only at
  forfeiture, not while the money is held.
- Trial balance squares.
- A closed period refuses an entry dated inside it while other periods still post.
- Every account code `data/seed.php` references is a code the chart seeds.

Phase 2 adds, in the same two files: where each payment method lands, revenue
fallback by item type, revenue netting per account, idempotency on every posting
rule, a tip that does not touch receivables, an expense with no account code, a
zero-total invoice, and a nested transaction rolling back its inner entry.

**Three real bugs the tests caught**, all of which would have shipped:

*Reversals were silently refused.* `reverse()` feeds stored `journal_lines` rows
back through `normalizeLine()`, and those rows key the account as
`account_number` while the normaliser only looked for `account`. Every reversal
normalised to a blank account and was rejected as unbalanced. Fixed by accepting
both keys, with a unit test on the stored-row shape.

*Account numbers came back as integers.* PHP coerces a numeric-string array key
to an int, so `revenueSplit()` returned `4010` rather than `'4010'` and threw a
TypeError out of the line builder. Fixed with an explicit cast and a test that
pins the coercion, since the language will keep doing this.

*A test that could only pass once.* The integration suite used a fixed literal
as a source-document id, so entries accumulated across runs and the "entries for
this document" count grew every execution. Now randomised per run.

## Sequencing

Phases 0 and 1 are prerequisites for everything. Phase 2 is repetitive once the
engine exists. Phase 3 is what was actually asked for and arrives third, because
it cannot be correct before the two beneath it. Phases 4 and 5 can ship after.

This is several sessions of work and it touches invoices, payments and expenses,
not only cores.
