# Prompt — comprehensive code check of the WKR admin application

Paste the section below into a fresh session. Everything above the line is notes
for the human; everything below it is addressed to Claude.

**Why this exists.** The app is hand-rolled PHP with no framework and no version
control — there is no diff to review, so a check has to sweep the whole codebase
against the project's own rules. This prompt encodes those rules and the traps a
reviewer keeps rediscovering. Update it when a new trap is found.

---

## THE PROMPT

You are doing a comprehensive code check of the White Knight Roadside admin
system at `C:\Users\MSI-Thin\Code Projects\wkr` (bash path
`/sessions/*/mnt/wkr`). It is a dispatch-to-cash system for a one-person mobile
roadside business: Service Request → Estimate → Work Order → Invoice → Payment →
Receipt, plus a full double-entry ledger. Hand-rolled PHP 8 + MySQL, no
framework, no Composer, no build step, **no git** — the working copy is the only
copy, so never suggest commit/diff/revert, and never rewrite files as part of
this review. **Read-only: report findings, change nothing unless I explicitly
approve a fix.**

### Read first, in this order

1. `PROJECT_INSTRUCTIONS.md` and `README.md` — these are authoritative and
   override anything you think you know about the project.
2. `docs/DECISIONS.md`, `docs/BUSINESS_RULES.md`, `docs/INTEGRATIONS.md`,
   `docs/ACCOUNTING_PLAN.md` — the intended behavior you are checking against.
3. `AGENTS.md` and the `knowledge/` index if present.

Then map the code: `public/index.php` (front controller), `app/Core.php`
(App/Auth/Router/View), `app/Db.php`, `app/Schema.php`, `app/Domain.php`
(DocNumber, Audit, Lines, Rules, Markup, Ledger), `app/Guard.php`,
`app/helpers.php`, `app/Contracts/`, `app/Services/` (Http is the only socket),
`app/Controllers/` (~13 controllers), `app/Views/`, `data/` scripts, `tests/`.

### What to check

**1. Correctness of money math.** Everything financial must be decimal-exact:
integer cents or DECIMAL(12,2), never floats in arithmetic. Verify `Markup`
boundary behavior (cost on a tier max belongs to the LOWER tier; zero/null cost
→ "needs pricing", never $0), that line snapshots (cost, markup %, suggested,
final, override flag) are actually snapshotted, that the pricing formula lives
only server-side (`/pricing/suggest`), and that journal entries always balance.
Books are accrual; cash-basis is only a report.

**2. Invariants.** Every hard rule lives once in `Rules` — flag duplicated rule
logic in controllers or views. Nothing is ever deleted: voids/credits plus
append-only `audit_log` (exception: GL accounts are deletable by design as of
2026-08-25 — do not flag that, and do not reintroduce a retire-only rule).
Outside calls go through `Services\Http` and land in `api_log` — flag any other
socket/curl usage. Doc numbering must be gap-safe under concurrency.

**3. Security.** This is pre-production with known issues (seeded passwords,
`debug` on locally) — note them, but focus on what's *not* already known:

- SQL injection: every query through the PDO wrapper with bound params; grep
  for string-interpolated SQL.
- XSS: every view output through `e()`; grep views for raw `<?=` without it.
  Check the Markdown renderer for injection.
- CSRF on every state-changing POST; auth checks on every route (especially
  JSON/AJAX endpoints, `/pricing/suggest`, schema and records admin routes).
- Public entry points: `/locate`, `/sign`, `/checkout`, webhooks. Signed-link
  tokens must be unguessable and expiring; Square/Telnyx webhook signatures
  must be verified, not just parsed.
- Role enforcement: admin vs dispatch vs tech — check each route's gate, not
  just the nav that hides buttons.
- Secrets: `config.php` and `data/secrets.php` must not leak into logs,
  errors, or responses; `public/` must not expose anything but `index.php` and
  assets; check `storage/` and `backups/` are not web-reachable in the
  SiteGround `public_html` layout.
- File/path handling in uploads, PDF/receipt generation, and `asset()`.

**4. Schema and data integrity.** `app/Schema.php` vs `data/schema.mysql.sql`
drift; missing indexes on hot lookups (doc numbers, customer search, ledger by
account/date); transactions around multi-row writes (doc + lines + audit +
journal); charset/collation consistency; DECIMAL widths.

**5. Consistency and dead weight.** Patterns that deviate from the house style
(front controller + plain views, PJAX nav, cache-busted assets), copy-pasted
blocks that should share a helper, unused routes/views/helpers, TODOs, and any
place the code contradicts `docs/DECISIONS.md` — flag the conflict, don't pick
a side.

**6. Run everything.**

- `php -l` every `.php` file (recursive).
- `php tests/markup.php`, `php tests/ledger.php`, `php tests/a11y_lint.php`
  (static WCAG pattern check over every view and app.js — must be 0 failed),
  and the rest of `tests/*.php` that run without a live DB; with local DB up,
  the `*_integration.php` tests and `tests/e2e.sh`.
- Note which tests are stale or don't cover the code they name.

### Hard constraints

- Local working copy only. Do not touch production (galactica.wkrllc.com), do
  not run `data/wipe.php` or edit `data/wipe-policy.php`, do not run deploys.
- Do not "fix while you're in there." Findings only.
- Real customer/financial data may be in the local DB — don't paste rows into
  the report; row counts and column names are fine.

### Deliverable

A single report at `docs/CODE_REVIEW_<date>.md`:

1. **Summary** — overall state in a paragraph, and the top 5 things to fix
   before production.
2. **Findings** — grouped Critical / High / Medium / Low. Each: `file:line`,
   what's wrong, why it matters here (tie to a business rule where relevant),
   and the suggested fix in a sentence or two. No patches.
3. **Test results** — what ran, what passed, what couldn't run and why.
4. **Doc drift** — where README/DECISIONS/BUSINESS_RULES no longer match code.

Rank honestly. If something is fine, say so once and move on — the report's
value is the ordered list of what's actually wrong.
