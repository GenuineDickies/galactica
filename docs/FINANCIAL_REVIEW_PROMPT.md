# Prompt — review, categorise and sort White Knight's financial documents

Paste the section below into a fresh session. Everything above the line is notes
for the human; everything below it is addressed to Claude.

**Why this exists.** A long session on 2026-08-20/21 worked out where WKR's money
actually lives, and found several traps that silently produce wrong numbers. This
prompt carries that knowledge forward so the next run does not rediscover it the
hard way. Update it whenever a new trap is found.

---

## THE PROMPT

You are reviewing six years of financial records for White Knight Roadside, LLC —
a one-person mobile roadside and repair business in Oregon. Your job is to find
every financial document available, work out what each one is, categorise the
transactions in it, and report what the books are missing. **You are not posting
anything to the ledger unless I explicitly say so.**

### Where the records are

Check all of these. Do not assume any single one is complete.

1. **Google Drive** — the connector is signed in as `genuinedickies99@gmail.com`.
   The business documents live in a folder owned by `admin@wkrllc.com` that has
   been shared across. Search by `parentId` and by title; also look in folders
   named *Job Documents*, *Invoices*, and *WKR*.
2. **Anything I upload in this conversation** — usually Square statement PDFs,
   Cash App CSV exports, Venmo CSV exports.
3. **The production database** at galactica.wkrllc.com, reachable read-only with
   `php data/deploy-ssh.php --run "…"` from `C:\Users\MSI-Thin\Code Projects\wkr`.
   The Square mirror (`square_transactions`, `square_payout_entries`) and the
   ledger (`journal_entries`, `journal_lines`) are already populated.
4. **Ask me before browsing** to Cash App, Venmo, GO2bank or Square. I will open
   them or hand you a URL. Never enter credentials, never touch a verification
   prompt, never move money.

### The three eras — this is the key to not getting confused

WKR's spending moved between accounts over time. Any conclusion drawn from one
account alone will be wrong for at least one era.

| period | where the money was spent |
|---|---|
| Apr 2020 – Sep 2022 | **Business Checking XXXX2807** — a real bank account, 3,707 transactions, $109,312.71 of spending |
| 2022 – 2024 | **Prepaid VISA cards** — 1721, 2173, 4282, 7329, 2279, used sequentially, ~5–7 months each, ~$102k loaded. Mostly undocumented; GO2bank *9720 is NOT one of them and only retains 12 months |
| 2024 – now | **Square debit card 8660**, spending directly from Square Checking |

Revenue similarly arrives through **four** channels, not one: Square card sales,
**Cash App**, **Venmo**, and possibly dispatch providers (Urgent.ly, Honk).
The ledger currently holds Square card sales only.

### Traps that produce silently wrong numbers

Every one of these was hit for real. Check each before trusting a figure.

- **Square statement PDFs write whole dollars without cents** — `-$20`, `$800`.
  A regex demanding `\.\d{2}` drops those rows silently. Use
  `-?\+?\$[\d,]+(?:\.\d{2})?` and assert row counts and balance continuity.
- **Apostrophes survive normalisation.** `Love's`, `Lowe's`, `O'Reilly`. Patterns
  must allow for them.
- **Short substring patterns swallow longer words.** `ADS` matches ROADSIDE — the
  business's own name. `TOW` matches TOWN and TOWER. `TIRE` matches ENTIRE and
  RETIRE. Anything ≤ 4 characters must be an anchored regex; `tests/expense_rules.php`
  enforces this.
- **Transfers are not spending.** Card loads, Cash App and Venmo movements, ATM
  withdrawals and inter-account transfers must be separated out *before* quoting
  any categorisation rate, or the rate is meaningless.
- **Merchant Category Codes are not in the exports.** Statements carry the
  descriptor string and nothing else. Do not design around MCC.
- **Square's "deposits earned" excludes negative payouts.** Reconciling the
  ledger against a Square report will differ by exactly the failed and reversed
  payouts. That is definitional, not an error.
- **Cash App and Venmo inbound is MIXED** — customer payments and Jason moving
  his own money. No rule can separate them. The 1.75% instant-transfer fee is a
  useful *sort* (a round sum less 1.75% is almost certainly a price) but never a
  decision.
- **Check your own measuring scripts.** A hit-rate script that hardcoded
  `is_regex => 0` reported a 20-point drop that did not exist.

### How to categorise

Use the engine that already exists rather than inventing a parallel one:

- `Descriptor::normalize()` in `app/Domain.php` — raw descriptor to match key.
- `ExpenseRules::match()` — ordered patterns, first match wins, specific before
  general. `ExpenseRules::SEED` is the current rule set.
- Accounts are in `Accounts::DEFAULTS`. 5xxx is cost attaching to a job, 6xxx is
  overhead, 1030 is prepaid cards (an asset — a card load has bought nothing
  yet), 4050 is historical card revenue, 3200 is owner draw.

Rules **propose**; they never decide. An unrecognised merchant gets no
suggestion and waits for a person — never a fallback to 6900.

Where a vendor sells both job parts and shop tools (NAPA, O'Reilly, AutoZone),
file uniformly to 5000 and say so. Do not invent a split you cannot evidence;
it is tax-neutral and a consistent disclosed treatment is defensible where a
per-transaction guess is not.

### What to produce

1. **An inventory** of every document found: what it is, what period it covers,
   how many transactions, and what it totals.
2. **A categorised breakdown** by account and by year, with the transfer rows
   separated from real spending.
3. **A gap list** — periods and channels with no records, and what would close
   each gap.
4. **A list of anything that changes the revenue figure**, flagged clearly.
   Understated income matters more than misfiled expenses.
5. **Corrections to your own earlier work**, stated plainly. If a number you gave
   me was wrong, lead with that rather than burying it.

Write findings to `docs/` so they survive the session. Do not post to the ledger.

### How to behave

Verify before asserting — run the query, read the file, check the arithmetic.
When something looks impossible (a year with $82 of spending), suspect your
parser before believing the data. Tell me what you could not do and why, rather
than working around a blocked step quietly. And when the answer is genuinely
mine to give — is this a customer payment or your own money — ask, and give me
the evidence to decide with.
