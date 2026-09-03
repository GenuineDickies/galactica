# Income and expense summary by tax year, 2020–2026

Built 2026-08-21 from every record that exists. **This is working paper for a tax
professional, not a return and not advice.** Every figure traces to a source
named below; every assumption is labelled.

Prepared because no returns have been filed since the company started, so all
years remain open — the assessment clock starts on filing, not on the tax year.

---

## The headline

| year | revenue | documented expenses | apparent net |
|---|---:|---:|---:|
| 2020 (from Apr) | 26,047 | 5,902 | **20,145** |
| 2021 | 44,522 | 23,834 | **20,688** |
| 2022 | 59,569 | 28,345 | **31,224** |
| 2023 | 31,706 | 751 | **30,955** |
| 2024 | 39,620 | 15,378 | **24,242** |
| 2025 | 36,390 | 22,460 | **13,930** |
| 2026 (to Jul) | 48,090 | 26,505 | 21,585 |
| **2020–2025** | **237,854** | **96,670** | **141,184** |

**Read "apparent" literally.** These are not the numbers to file. Three
adjustments push the real figure down, and they are set out below.

## Revenue, by channel and year

| | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 | 2026¹ |
|---|---:|---:|---:|---:|---:|---:|---:|
| Square card sales | 26,047 | 27,161 | 33,563 | 31,706 | 36,676 | 29,809 | 34,230 |
| Honk — ACH direct | — | 11,286 | 12,871 | — | — | — | — |
| 1-800-Battery — via Bill.com | — | 4,567 | 13,135 | — | — | — | — |
| Urgent.ly — ACH direct | — | 1,508 | — | — | — | — | — |
| Cash App | — | — | — | — | 2,944 | 6,581 | 4,210 |
| Venmo | — | — | — | — | — | ? | ~9,650² |
| **total** | **26,047** | **44,522** | **59,569** | **31,706** | **39,620** | **36,390** | **48,090** |

¹ Seven months only. ² Estimated from $129.21 of year-to-date Venmo fees at the
1.75% instant-transfer rate, plus August's actual $2,252.

**Provider work paid two ways.** Honk, Urgent.ly and 1-800-Battery each issued
virtual cards *and* paid some jobs by ACH. The card-paid jobs went through Square
and are inside the Square line — proved by matching card last-four, exact amount
and date: Urgent.ly 891 of 965 jobs, Honk 272 payments at 97%. The ACH payments
never touched Square and are the separate lines above.

Provider work ends in 2022. No Honk, Urgent.ly or 1-800-Battery deposit appears
anywhere after Business Checking closes in September 2022, and the job records
stop at the same point.

## Expenses categorised by the rules engine

Excludes owner draw, transfers, and the unmatched tail. Produced by
`data/categorise_spend.php`, which runs the same `Descriptor` and `ExpenseRules`
code the application uses.

| acct | | 2020 | 2021 | 2022 | 2023 | 2024 | 2025 | 2026 |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| 5030 | Vehicle fuel | 3,028 | 10,139 | 13,521 | 112 | 3,315 | 6,782 | 6,167 |
| 5000 | Parts & materials | 695 | 4,380 | 4,272 | 228 | 4,993 | 10,225 | 11,467 |
| 6600 | Small tools | 796 | 4,194 | 1,350 | — | 892 | 1,945 | 1,808 |
| 6200 | Rent & storage | 672 | 2,451 | 2,445 | — | 765 | 726 | 828 |
| 6250 | Vehicle insurance | 351 | 128 | 2,820 | — | 1,216 | 986 | 1,274 |
| 6120 | Software | — | 339 | 1,641 | 108 | 972 | 1,149 | 3,391 |
| 6110 | Google Ads | — | 350 | 1,241 | 254 | 2,168 | — | 118 |
| 6130 | Phone | — | 356 | 752 | 50 | 950 | 648 | 598 |
| 6400 | Supplies | 360 | 1,318 | 121 | — | — | — | — |
| others | | 1 | 178 | 182 | — | 106 | — | 854 |
| **total** | | **5,902** | **23,834** | **28,345** | **751** | **15,378** | **22,460** | **26,505** |

Square's own processing fees — **$7,447.94** across the period — are deducted at
source and never appear as a bank debit, so they are not in the table above. They
are a deductible expense and are already in the ledger at account 7010.

## The three adjustments that reduce the apparent net

**1. Unmatched spending — $40,270.** Real money that left the account and has not
yet been categorised, so it is currently counted as profit. Much of it will be
deductible once reviewed. Concentrated in 2021 ($13,064) and 2022 ($15,045),
mostly truncated bank descriptors — "AUTHORITY AUTH PURCH", "AUTH PURCH", "T MEN
AUTH". Reviewing these is the single highest-value hour available.

**2. Undocumented prepaid card spending — $102,895.** This is why 2023 shows
$30,955 of apparent profit against $751 of expenses: that year, $30,278 went onto
prepaid cards and what it bought is not recorded anywhere. Almost certainly it
bought fuel, parts and tools like every other year — but without records it
cannot be substantiated, and unsubstantiated it is taxed as profit.

**3. Owner draw is not an expense — $25,919.** Correctly excluded above. Listed
so nobody adds it back by mistake. Business-to-personal transfers in
`FundsTransfer.csv` run $9,410 with $7,303 returning as owner contribution.

## What this means, arithmetically

Apparent net for 2020–2025 is $141,184. If the unmatched tail is largely business
and the card spending could be substantiated, the real figure would be
dramatically lower — potentially under $40,000 across six years. It cannot be
substantiated on current records.

At these profit levels the tax is dominated by **self-employment tax at 15.3%**
of net earnings; federal income tax is often absorbed by the standard deduction;
Oregon adds roughly 4.75–8.75%. Penalties and interest sit on top and are not
estimated here — failure-to-file runs 5% per month capped at 25% of unpaid tax,
failure-to-pay 0.5% per month, and interest compounds. All of it is a percentage
of tax owed, so reducing the net reduces everything.

Local gross-receipts taxes almost certainly do not apply: Portland's Business
License Tax exempted under $50,000 through 2025, Multnomah County's threshold is
$100,000, and Oregon's Corporate Activity Tax starts at $1,000,000. Portland's
exemption may still require a filing to claim.

## Known gaps and soft figures

- **Venmo before August 2026** — one month held; the rest needs pulling by hand.
  2025 Venmo revenue is unknown and missing from the table entirely.
- **Cash App before July 2024** — the export reaches no further.
- **"Card push / misc auth", $41,298** into Business Checking 2020–22 is treated
  as Square instant transfers on the strength of the descriptors ("Visa Direct",
  "San Francisco CA"). If that is wrong, it is unexplained income.
- **"Intuit deposits", $2,739** — unidentified. Could be QuickBooks Payments
  revenue or a refund.
- **Unemployment benefit, $7,632** received in 2021 — excluded as not business
  revenue, but it is personally taxable and belongs on the personal return.
- **Business Checking after Sept 2022** — the export ends there.
- **Receipts** — largely absent before 2024.

## Sources

Square mirror and ledger (production database) · 67 Square Checking statements
2021-01 to 2026-07 · Business Checking XXXX2807 export 2020-04 to 2022-09 ·
Cash App export 2024-07 to 2026-08 · Venmo statement 2026-08 · Urgently
Transactions, Honk CC, Honk PO, FundsTransfer, Square Payments, Combined Results
Transfers (Google Drive) · Square reconciliation report 2025.
