# Square Clearing settlement — task #15

Status: **DONE. Posted to production 2026-08-20.**
Written 2026-08-19, completed 2026-08-20.

---

## What the books say now

6,982 journal entries. Trial balance squares at $542,023.70 on both sides.

| Account | Balance |
|---|---|
| 1000 Cash | $3,800.83 |
| 1010 Checking | $205,678.65 |
| **1050 Square Clearing** | **$0.00** |
| 2010 Credit Card Payable | $1,422.48 *debit* — expected, see below |
| 2100 Square Capital Loan | $3,534.79 |
| 4050 Historical Card Sales | $219,569.11 |
| 7010 Square Fees | $7,447.94 |
| 7020 Chargebacks | $85.00 |
| 7030 Financing Interest & Fees | $4,669.00 |

**1050 reads zero.** A clearing account exists to be emptied, and that figure is
the only real test of whether the engine is right — six years, 2,850 charges,
2,280 payouts, every sale followed from the card reader to the bank.

Posted: 2,850 charges, 1,783 deductions, 2,280 payouts, 35 cash/external. Fifteen
rows did not post because every one of them is $0.00 — an entry with no amount is
not an entry. No errors.

## The classification question, answered

The blocker was 2,850 UNREVIEWED charges. Jason, 2026-08-20: *"all credit card
transactions were business. I've never swiped a card to take personal income."*

Obvious in hindsight — a merchant account only takes customer payments. The
business/personal mixing in this Square account is on the **spending** side, and
gating six years of revenue behind an expense-side safeguard was over-applying
it. The gate itself stays: it is right that an unclassified charge cannot post,
and the runner still refuses to commit while one exists.

## Decisions taken

**1. Pre-payout-history card sales — solved outright, not fudged.**
Square's Payouts API begins 2020-12-30, and this plan originally proposed a
single invented aggregate for the 903 earlier charges. That is no longer
necessary: the dashboard CSV export carries `Deposit ID` and `Deposit Date` on
every row — the same fact the API withholds, through a different door.
`data/square_import_deposits.php` rebuilds those payouts into the mirror, and
from there they are indistinguishable from fetched ones. **325 deposits and 902
charges imported**, $25,919.00 gross, $1,043.41 fees, $24,875.59 net, and
`gross − fees = net` proved before writing. There is deliberately no special
case for them anywhere in the posting rules.

The two deposits that straddle the boundary are skipped whole — they contain the
six charges the API already knows, and importing half a deposit would put money
into the clearing account twice.

**2. Revenue account: new 4050 Historical Card Sales (unattributed).**
Crediting 4000 would assert every historical charge was labour, which nothing
knows. Kept apart so that when task #16 learns what each job actually was, the
reclassification is a clean reversal out of one account. A shrinking balance in
4050 is the measure of that work.

**3. Task #14's repayment entries get reversed and re-posted split.**
$26,751.34 against 1050, $6,052.87 against 1010. Account 2100 ends on exactly the
balance it holds now — the runner asserts that and exits non-zero if it moved.

**4. Scope: built and dry-run; posting gated on classification.**

## What was built

| Piece | Where |
|---|---|
| 4050 in the chart, `SQCHG`/`SQDED`/`SQPAY` journal sources | `app/Domain.php` |
| `squareCharge`, `squareRefund`, `squareDeduction`, `squarePayout`, `squareUnsettled` | `Posting` |
| `capitalRepaymentCorrection` | `Posting` |
| `LedgerReports::squareClearing` — sixth report | `app/Domain.php` |
| Settlement runner, dry-run by default | `data/square_settle.php` |
| CSV deposit reconstruction | `data/square_import_deposits.php` |
| 42 unit tests | `tests/square_settle.php` |
| `--put` for non-code files | `data/deploy-ssh.php` |

## Findings that changed the design

**`RETURNED_PAYOUT` is not a bank movement.** The name says it is. The four rows
sit inside *PAID* payouts and carry the components of the two FAILED payouts — a
payout a dispute pulled negative, re-issued piece by piece into later payouts.
Facing them to Checking makes them cancel the negative payout headers exactly:
both total −$171.45, both accounts net to zero. Facing them the obvious way
would have left $171.45 of phantom bank movement.

**38 payments must never touch 1050.** 31 CASH and 7 EXTERNAL, zero-fee, never
settled. Cash debits 1000, external debits 1010.

**`ADJUSTMENT` rows are not zero individually** — 14 of them, $395.26 of absolute
movement — but they sum to exactly $0.00 and are internal Square balance
mechanics, so they face Checking and leave no residue.

**Windows truncates a command line at 8191 characters, silently.** Pushing the
CSV through `--run` as base64 produced "the append did nothing" eight times with
no error. `--put` uses the scp helper that was already in `deploy-ssh-lib.php`,
verified by SHA-256; `--run` now refuses anything over 6,000 characters rather
than letting the far end execute a fragment.

## What remains

**The classification pass at /square.** 2,850 charges are UNREVIEWED and the
runner will not commit while any of them are — it exits non-zero rather than
skipping them quietly. The Square account carries personal spending mixed with
business, and that flag is the only thing that tells them apart. Four small
lenses at /square, then `data/square_classify.php --business` for the remainder.

Then, in order:

```
php data/square_settle.php --fix-capital --commit    # 2100 must not move
php data/square_settle.php                           # read it once more
php data/square_settle.php --commit                  # 1050 must reach zero
```

## Known and expected afterwards

- **2010 Credit Card Payable will sit in debit**, about $1,422.48 — Square card
  repayments on the books with the card spending behind them not yet imported.
  That is task #26, not an error. The report says so in its note.
- **One 2019 charge, $10.00**, predates the CSV and has no deposit record. It
  will not post. Not worth a mechanism.
- **Personal and transfer treatment** credits 3100 Owner Contributions. Still a
  CPA question, already on task #28.
