# Bank and card import — what the statements actually say

Findings from 67 months of Square Checking statements (Jan 2021 – Jul 2026,
4,756 transactions) and the Cash App export. Written 2026-08-21, task #26.

**Nothing here is posted.** These are findings, not entries.

---

## Correction first: earlier figures in this session were wrong

The statement parser required amounts to carry two decimal places. Square renders
whole-dollar amounts without them — `-$20`, `$800`, `+$0` — so **every
whole-dollar transaction was silently dropped**. It looked like 2022 had $82 of
spending for the entire year.

Fixing the pattern to `-?\+?\$[\d,]+(?:\.\d{2})?` recovered **376 rows**. Figures
quoted before that fix understated the transfer problem by roughly two thirds.
This is the failure mode PDF parsing is prone to: not a crash, a quiet omission
that reads as a fact. The importer must assert row counts and balance
continuity, not just parse what it recognises.

## The six-year picture

| year | rows | money in | merchant spend | moved to cards |
|---|---:|---:|---:|---:|
| 2021 | 700 | 22,745.62 | 3,784.41 | 18,961.21 |
| 2022 | 296 | 32,725.31 | 1,532.72 | 30,396.43 |
| 2023 | 269 | 31,542.88 | 1,859.26 | 30,277.70 |
| 2024 | 930 | 36,504.33 | 22,212.16 | 14,365.38 |
| 2025 | 1,468 | 34,249.07 | 30,934.77 | 3,443.16 |
| 2026 (7mo) | 1,093 | 38,027.99 | 32,151.92 | 5,451.45 |
| **all** | **4,756** | **195,795.20** | **92,475.24** | **102,895.33** |

**$102,895 left the bank onto prepaid cards, Cash App and Venmo** — not the
$27,805 estimated earlier. For 2021–2023 that was almost the entire outflow:
money arrived from Square, was swept straight out, and what it bought is not on
any statement held.

The behaviour reversed over time. By 2025–26 nearly everything is spent directly
on the Square debit card, which is why those years categorise cleanly and the
early ones do not.

## The prepaid cards

Sequential churn — each card live five to seven months, then replaced:

| card | period | loaded |
|---|---|---:|
| VISA 1721 | Jan–Aug 2023 | 7,273 |
| VISA 2173 | Sep 2023–Jan 2024 | 7,592 |
| VISA 4282 | Feb–Aug 2024 | 11,858 |
| VISA 7329 | Oct–Dec 2024 | 581 |
| VISA 2279 | Jan–Jun 2025 | 1,008 |

GO2bank account *9720 was checked directly: opened around August 2025, statements
retained 12 months, transaction view about three. **It is none of the above** and
cannot supply their history. Those issuers would have to be identified from
memory and asked for records.

Card loads post to **1030 Prepaid Cards**, an asset — the money moved, nothing
was bought yet. See the account comment in `Accounts::DEFAULTS`.

## Cash App carries real revenue, and the notes prove it

156 inbound person-to-person payments, **$13,734.46**, Jul 2024 – Aug 2026 (the
export reaches no further back). The notes are job descriptions, not transfers:

```
tire ×12    gas ×8    work ×4    car ×3    tire repair ×3
radiator ×2    "chris jeep" ×2    "red tesla tire"
"break pads"    "volvo battery"    "batter instal"
```

| year | payments | amount |
|---|---:|---:|
| 2024 (from Jul) | 32 | 2,944.00 |
| 2025 | 77 | 6,580.71 |
| 2026 (to Aug) | 47 | 4,209.75 |

**None of this is in the ledger.** `square_settle.php` posted Square card sales
only — these payments never touched Square, so nothing could have seen them.

Where it went out: $12,009 to card 8660 (the Square debit card) and $5,363 to
Visa debit 0687. Square Checking only shows $3,030 of Cash App inflow across the
whole period, so **roughly $4,800 of Cash App withdrawals do not reconcile to the
bank statements yet** — an open item, not a conclusion.

Venmo is a separate channel of the same kind: $4,208.66 seen arriving at the
bank, and its own export has not been pulled.

## What the books currently say, and why both sides are wrong

- Revenue $219,569 — Square card sales only. Missing Cash App and Venmo.
- Expenses $12,202 — Square fees, chargebacks, financing. Missing all $92,475 of
  merchant spend and whatever the $102,895 of card money bought.

## Engine state

90% of merchant spend matches automatically — $82,481 of $91,934, 144 merchants.
161 unmatched merchants hold the remaining $9,452. Rules, normaliser and 60 unit
tests are built; the importer, posting rule and review screen are not.

## For the accountant

This is now a concrete conversation rather than a general one:

1. Six years of customer payments through Cash App and Venmo that are not in the
   Square figures, roughly $13.7k evidenced so far and only from Jul 2024.
2. $102,895 moved to prepaid cards, largely undocumented for 2021–2023.
3. Whether the 2021–2022 records can be reconstructed at all, and what treatment
   applies if they cannot.
