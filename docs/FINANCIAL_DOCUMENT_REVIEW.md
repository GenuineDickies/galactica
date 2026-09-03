# Financial document review — full inventory, 2019–2026

Run 2026-08-21 against every source available. **Nothing was posted to the
ledger.** Read `docs/BANK_IMPORT_FINDINGS.md` alongside this.

---

## Read this first — a security problem, not an accounting one

**`Honk CC.xlsx` and `Urgently Transactions.xlsx` in the shared Drive folder
contain full payment card numbers.** Honk's file holds 325 complete card numbers
*with CVC2 codes and expiry dates*; the Urgent.ly file holds 965 more card
numbers.

Storing a full card number alongside its CVC is prohibited under PCI-DSS
regardless of who issued the card, and this folder was shared to a second Google
account earlier today — which is exactly how such a file spreads. Most of the
cards appear to be expired single-use virtual cards, which lowers the risk but
does not remove it.

This is worth fixing before anything else in this document: delete the CVC
column, or keep only the last four digits, which is all the reconciliation below
ever needed.

---

## Inventory

| document | source | period | rows | value |
|---|---|---|---|---|
| Square Checking statements | uploaded, 67 PDFs | 2021-01 → 2026-07 | 4,756 | $195,795 in / $195,371 out |
| **Business Checking XXXX2807** | Drive | 2020-04 → 2022-09 | 3,707 | $109,313 spent / $109,666 received |
| Cash App export | downloaded | 2024-07 → 2026-08 | 526 | $13,734 customer payments in |
| Venmo statement | downloaded | 2026-08 only | 19 | $2,252 in |
| Square transactions CSV | uploaded | 2020 full year | 915 | $26,047 |
| Square Payments.csv | Drive | 2019-12 → 2022-09 | 1,785 | $71,134 collected |
| Combined Results Transfers.xlsx | Drive | 2019-12 → 2022-09 | 4,273 | payments, payouts, capital, adjustments |
| Square Deposits.xlsx | Drive | from 2020-03 | — | deposits with fees |
| Reconciliation report | uploaded | 2025 full year | — | summary only |
| **Urgently Transactions.xlsx** | Drive | 2020-03 → 2022 | 1,053 jobs | $28,305 of work |
| **Honk CC.xlsx** | Drive | 2021 → 2022 | 325 cards | $13,532 |
| **Honk PO.xlsx** | Drive | 2020 → 2022 | 1,252 POs | 1,188 paid |
| Providers.xlsx | Drive | — | — | dispatch providers applied to |
| FundsTransfer.csv | Drive | 2020 → 2022 | 181 | inter-account transfers |
| Square mirror (production) | database | 2019-12 → 2026-08 | 6,182 | already imported |
| Ledger (production) | database | posted 2026-08-20 | 6,982 entries | balanced |

## The revenue question, answered

**The dispatch providers are NOT a new revenue channel.** Both paid by issuing
virtual credit cards, which were then charged through Square — so that money is
already in the figures. Tested rather than assumed:

- **Honk** — 272 Square payments match a Honk card's last four digits, totalling
  $13,167.97 against $13,532.00 of cards issued. **97%.**
- **Urgent.ly** — matched on card last four **plus exact amount plus date within
  ten days**, which coincidence cannot survive: **891 of 965 card-paid jobs,
  $23,801.65 of $26,264.15. 91%.**

Residual worth a look, but small: 74 Urgent.ly jobs that did not match ($2,462.50)
and 88 with no card number recorded ($2,040.40).

**What does still move revenue** is unchanged from the earlier finding: Cash App
($13,734 since Jul 2024) and Venmo (August 2026 alone was $2,252). Those never
touched Square and are not in the ledger.

## The unexpectedly valuable finding

The provider files are the job history that task #16 was going to chase through
the Square Orders API — and they are better, because they are already matched to
payments.

**891 historical Square payments can now be attributed to actual jobs**, with
service type and location:

| job type | jobs | value |
|---|---:|---:|
| Jump Start | 424 | 11,133.00 |
| Flat Tire | 296 | 7,962.25 |
| Auto Lockout | 292 | 7,982.30 |
| Fuel | 41 | 1,227.00 |

That is enough to reclassify a large slice of **4050 Historical Card Sales** into
real service revenue, which is exactly what that account was created to allow.

## Owner draw and contribution, 2020–2022

`FundsTransfer.csv` records movements the ledger has never seen:

| direction | count | amount |
|---|---:|---:|
| Business Checking → Personal Checking | 102 | 9,410.30 |
| Personal Checking → Business Checking | 57 | 7,303.13 |
| Business Savings → Personal Checking | 4 | 1,152.00 |
| Business Checking → Personal Loan | 8 | 510.04 |
| Business Checking → Business Savings | 1 | 1,104.00 |

Business-to-personal is owner draw (3200); personal-to-business is owner
contribution (3100). Net draw over the period is roughly $3,769.

## Gap list

| gap | period | what would close it |
|---|---|---|
| Prepaid card spending | 2022–2024, ~$102k | Issuer records for VISA 1721, 2173, 4282, 2279. GO2bank is not one of them and retains only 12 months. Likely unrecoverable |
| Venmo history | before Aug 2026 | 30 monthly CSVs; Chrome blocks bulk download, so these need pulling by hand |
| Cash App history | before Jul 2024 | Export reaches no further; may need a records request |
| Square Checking | 2020 and earlier | Account predates the statements held; the 2020 transactions CSV covers the payment side |
| Business Checking | after Sep 2022 | Export ends there. Was the account closed, or is there more? |
| Receipts | 2020–2023 | Mostly gone. Affects substantiation, not categorisation |

## Corrections to earlier statements in this session

1. **"Square Checking is the whole business"** — true today, wrong for history.
   Business Checking XXXX2807 held the 2020–2022 operating spend.
2. **"$27,805 moved to prepaid cards"** — wrong, the parser was dropping
   whole-dollar rows. The real figure is ~$102,895 over six years.
3. **"2022 had $82 of merchant spending"** — a parsing artefact, not a fact.
4. **Urgent.ly and Honk as possible new revenue** — I raised them as a likely
   fourth channel. Tested, and they are not. Revenue does not move.

## What has not been done

Nothing is posted. The importer, the posting rule and the review screen are still
unbuilt, so none of this reaches the ledger yet. The engine categorises 90% of
Square-era merchant spend and 61% of the older bank account, where descriptors
are truncated differently.
