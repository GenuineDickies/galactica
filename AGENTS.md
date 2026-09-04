# Conventions

Notes for anyone — human or otherwise — extending this codebase. Read
`docs/BUSINESS_RULES.md` first; most "bugs" in a system like this are rules
implemented twice and drifting apart.

## Shape

Hand-rolled MVC. No framework, no Composer, no build step. `public/index.php` is
the front controller and holds every route in one readable list. Classes are
loaded by explicit `require`, one concern per file — there is no autoloader to
reason about.

## Hard rules

**Every gate goes through `Rules` in `app/Domain.php`.** If a screen needs to
know whether something is allowed, it asks. Never re-check a threshold inline;
never read `config('rules')` from a view. A rule that exists in two places is a
rule that will be wrong in one of them.

**Never trust the service request.** It is unverified by design. Nothing reads
`reported_*` as fact, and nothing downstream joins to it for customer or vehicle
data — that lives on the estimate.

**Line items only ever come from the catalog**, through `Lines::add()`, which
snapshots the item. Never insert into `doc_lines` directly.

**Nothing is deleted.** Void, credit, or mark inactive. `Audit::log()` on every
state change, with enough detail in the message to reconstruct what happened.

## Writing PHP here

- `declare(strict_types=1)` at the top of every file.
- `e()` every single value that reaches HTML. There is no template autoescaping.
- Read input through `input()`, `num()`, `intval_or_null()`, `price_or_null()`.
  `price_or_null()` exists because an absent price must mean "use the catalog
  price", not "$0" — that distinction has already caused one bug.
- All SQL is parameterised through `Db::` helpers. No string interpolation, ever.
- Multi-write operations go in `Db::tx()`.
- Phones through `phone_to_e164()` on the way in, `phone_display()` on the way
  out. Storage is always E.164.
- Money is `DECIMAL(12,2)`, never a float column. Round at the boundary.

## Writing views

- Views are plain PHP with alternate syntax (`<?php if (…): ?>`). No logic beyond
  presentation — compute in the controller.
- `View::partial()` for shared fragments. The line-item editor
  (`partials/line_editor.php`) is shared by estimates, work orders and invoices;
  change it once.
- `csrf_field()` inside every form. `csrf_check()` runs on every POST.
- One glowing primary action per screen. If a screen has two obvious next steps,
  the screen is doing two jobs.
- Navy is chrome. Amber, red and green are reserved for state — never decoration.
- **Accessibility is a hard rule (WCAG 2.1 AA).** Fix-and-enforce prompt:
  `docs/ACCESSIBILITY_PROMPT.md`; findings: `docs/ACCESSIBILITY_REVIEW_2026-09-03.md`.
  1. Every control has a name a screen reader can say: `<label for>` + `id`, or the
     input nested in the `<label>`. `placeholder` is never the only hint.
  2. Everything a mouse can do, a keyboard can do: a clickable row contains a real
     `<a>`; a pickable row contains a real `<button>`; modals trap focus and return it.
  3. Anything that changes without a page load announces itself: status text and
     flashes live in `aria-live` regions; PJAX moves focus to the new `<h1>`.
  4. Phone pages (customer and technician) use `btn--lg` for the primary action —
     44 px minimum; never `.btn--sm` for a state change.
  5. Colour never carries meaning alone; every text/background token pair used for
     normal text is ≥ 4.5:1 — new tokens go through the lint's pair list.
  6. Decorative glyphs are `aria-hidden="true"`; `disabled` buttons say why in
     visible text, not in `title`.
  `php tests/a11y_lint.php` enforces the mechanical part (rules R1–R17, listed
  at the top of the file) and is part of Definition of Done: 0 failed before any
  change under `app/Views/` or `public/assets/` ships. New form fields go through
  `field()` in `app/helpers.php`; a new text/background token pair goes into the
  lint's `CONTRAST_PAIRS` list with the selector that uses it.

## Touching integrations or webhooks

**Never call an outside service directly.** Go through the interface —
`Integrations::sms()`, `::payments()`, `::vin()`, `::geocoder()`. A controller
that knows the name Telnyx is a controller that has to change when the provider
does. `app/Services/Http.php` is the only place that opens a socket.

**Webhook handlers are the only unauthenticated code in the application.** Three
things must stay true of every one of them:

1. Verify the signature *before* parsing the body. Returning `true` from
   `verifyWebhook()` because verification is inconvenient turns a signed
   endpoint into an open one, and nothing downstream will notice.
2. Be idempotent. Providers retry. Lean on a database constraint — the unique
   index on `payments.processor_ref` — rather than a check in PHP, which two
   concurrent callbacks will race straight past.
3. Answer 5xx on failure, never 200. A 200 tells the provider to stop retrying,
   and whatever you failed to write is gone.

Every driver call goes through `ApiLog::write()`, whether it hit the network or
not. Credentials belong in `SettingsController::SECRETS` so they render masked
and write-only; never write a credential value into an audit detail or a log
line.

## Adding a document type

1. Table in `app/Schema.php` (both dialects come out of the one definition).
2. A prefix in the `DocNumber` conventions and a row in the numbering table in
   `docs/BUSINESS_RULES.md`.
3. Controller in `app/Controllers/`, routes in `public/index.php`.
4. Any gate it introduces goes in `Rules`, not in the controller.
5. Index and detail views; reuse `line_editor` if it carries line items.
6. Extend `tests/e2e.sh` — a gate without a test is a gate that will be removed
   by accident.

## Schema changes

`app/Schema.php` is the single source of truth and emits both SQLite and MySQL.
`Db::migrate()` is `CREATE TABLE IF NOT EXISTS` only — it does not alter existing
tables. For a real migration, add a numbered script under `data/` and run it
deliberately.

## Testing

`tests/e2e.sh` drives a live server with curl and asserts against the database.
It resets the database first. Add to it whenever you add a gate; the point of the
suite is that every rule in `docs/BUSINESS_RULES.md` has a test that proves it
still blocks.

```bash
php -S 127.0.0.1:8088 -t public &
tests/e2e.sh
```

## Before deploying

- `'debug' => false` in `config.php`
- change the seeded passwords
- confirm `storage/` is writable and not web-servable
- point the document root at `public/`, never at the project root

## Definition of Done

For PHP files you changed, run `php -l <file>` on each one and fix any syntax errors. Run the pure test suites that cover what you touched (`php tests/markup.php`, `php tests/ledger.php`, `php tests/line_tax.php`, …) before reporting done. Any change under `app/Views/` or `public/assets/` also runs `php tests/a11y_lint.php` (see Writing views).

If you discover a problem while working, FIX IT — do not report it and stop.

### Fix without asking

- Anything preventing the requested behavior from working
- Type errors, lint failures, broken imports, missing null checks
- Bugs in code you just wrote or touched

### Stop and ask first

- Schema migrations or changes to existing tables
- Changing a REST endpoint's request/response shape
- Adding a dependency
- Deleting or rewriting a file you weren't asked to touch
- Anything that contradicts an existing spec document
- Invoice tax calculation, document chain state transitions, or CRM confidence badge logic — these can pass typecheck while still being wrong

### Reporting

End every task with DONE or BLOCKED. If BLOCKED, name the one blocking issue. Do not append lists of observations, improvements, or considerations.
