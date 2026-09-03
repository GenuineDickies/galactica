# White Knight Roadside — Admin (project brief)

A dispatch-to-cash admin system for a mobile roadside & repair business.

This file is the authoritative orientation for anyone — human or model — about
to change this codebase. It is versioned with the code, so a change in
behaviour and the change to its description land in the same commit. If a copy
of this text exists anywhere else, that copy is stale by definition.

**What belongs here:** stack, how to run it, architecture, conventions, and the
rules that bind every change. **What does not:** anything that restates a value
the code already holds. Passwords, feature status and debug flags are not
documented here, because `config.php` and `README.md` are where they are true.

---

## Stack & how to run

- **PHP 8.0+**, **MySQL 8 / MariaDB**. No framework, no Composer, no build step.
  Hand-rolled front controller + plain PHP views. The files you edit are the
  files that execute. Runs on a stock local PHP + MySQL stack for development,
  any PHP host in production.
- Start locally: `start-wkr.bat` (or `php -S 127.0.0.1:8088 -t public`).
  DB creds live in `config.php` (env-overridable: `WKR_DB_USER` / `WKR_DB_PASS`
  / `WKR_DB_NAME`).
- First-time DB: import `data/schema.mysql.sql`, then `php data/install.php`.
  Interactive setup, including generating a production admin hash, is
  `php data/setup.php`.
- Reset data: `php data/wipe.php` (bare) · `--catalog` · `--demo`. Wipes are
  gated by `WipeGuard` in `app/Guard.php` against `data/wipe-policy.php`.
- **Logins are not documented here.** `README.md` explains how the admin login
  is seeded and why a known password never exists on a public install. Read it
  there; do not copy the values anywhere.

---

## The chain (core domain)

    Service Request → Estimate → Work Order → Invoice → Payment → Receipt

An **Estimate is the "quote"** — there is no separate quote entity. Every line
item on every document lives in one `doc_lines` table (`doc_type` EST/WO/INV)
and is created through `Lines::add()`.

Each link is earned, never assumed: an estimate becomes a contract only when
authorized, a technician rolls only on an authorized estimate, an invoice
reflects only recorded work, money is taken only against an issued invoice.

---

## Architecture

| Path | Holds |
|---|---|
| `app/Core.php` | `App`, `Auth`, `Router`, `View` |
| `app/Db.php` | PDO wrapper, `migrate()`, `pending()` |
| `app/Schema.php` | The whole schema, both engines |
| `app/Domain.php` | Business logic — see below |
| `app/Guard.php` | `WipeGuard` — destructive-action policy |
| `app/Markdown.php` | Minimal Markdown renderer, for the in-app manual |
| `app/helpers.php` | `e()`, `money()`, `num()`, `csrf_field()`, `service_types()`… |
| `app/Contracts/` | Interfaces the drivers implement |
| `app/Services/` | Drivers + `Http` (the only socket in the codebase) |
| `app/Controllers/` | One class per screen; several share a file |
| `app/Views/` | `layouts/app.php`, `pages/`, `partials/` |

`app/Domain.php` carries `DocNumber`, `Audit`, `Lines`, `ServiceCategory`,
`Rules`, `Health`, `Consent`, `Sms`, `SignatureRequest`, `LocationRequest`,
`Markup` and `Accounts`. Controllers are grouped by weight rather than one file
each — `RecordsController.php` holds the eleven thin record screens; the heavy
documents get their own file.

---

## Conventions — follow these

- **Every hard rule lives once.** `Rules` is the single gate; `Markup` is the
  single pricing formula; `ServiceCategory` is the single category map. If you
  find yourself writing a second copy of a fact, stop and say so instead.
- **Money is `DECIMAL(12,2)` and exact.** Pricing math is integer-cents in PHP
  and never in JavaScript — the browser gets suggestions from
  `/pricing/suggest`, never the formula.
- **Nothing is ever deleted.** Corrections are voids and credits. `audit_log` is
  append-only; every outbound call is written to `api_log`.
- **Snapshots don't move.** Lines freeze cost, markup, suggested and final
  price when added. Editing the catalog or the markup matrix never rewrites an
  existing document.
- **Testimony is never edited.** `reported_service` keeps the caller's words;
  corrections go to `service_category`, forward only.
- Assets cache-bust through `asset()` (filemtime query). Navigation is buttons
  with a PJAX-style content swap, so the sidebar stays stable.
- Escape at the boundary with `e()`. CSRF on every POST via `csrf_field()`.

---

## Documentation map

| File | Answers |
|---|---|
| `README.md` | Install, logins, deployment, first run |
| `docs/MANUAL.md` | The user manual — served in-app at `/manual` |
| `docs/BUSINESS_RULES.md` | What the rules are and why |
| `docs/DECISIONS.md` | Roads taken and not taken |
| `docs/INTEGRATIONS.md` | Drivers, keys, webhooks |

`docs/MANUAL.md` is rendered at request time by `app/Markdown.php`. Edit the
markdown; never hand-write HTML into it, and never keep a second copy.

---

## Integrations

Default drivers need no account and are switched in Settings.

- **SMS** — `outbox` | `telnyx` · **Payments** — manual + local checkout |
  `square` · **VIN** — offline structural decode · **Geocoding** — manual | OSM
  | Google
- **Part numbering** — local rules | **Claude** (key in Settings). Generates
  catalog SKUs from house rules plus existing numbers.

---

## Testing

    php tests/markup.php               # pricing math, pure
    php tests/service_category.php     # the category map, pure
    php tests/markdown.php             # the manual renderer, pure
    php tests/wipe_guard.php           # destructive-action policy
    WKR_DB_PASS=… php tests/pricing_integration.php
    tests/e2e.sh                       # HTTP walk

`php -l` every touched file before shipping. Pricing, rules and category
changes need a test run, not an eyeball.

---

## Deploying

Production is live, shared-hosted, **with SSH** — server-side CLI scripts run
over it as a matter of course. (Earlier docs said "no shell"; that predates
confirming the plan. Deploys still go over the wire so a host without SSH
would work, but never route around the shell that exists.)

- Deploys are **targeted** — `php data/deploy.php <files>` — unless a full one
  is asked for.
- **`public/index.php` ships last.** A route referencing a class that has not
  landed is a whole-site outage, not a broken page.
- Schema changes apply through `/admin/schema` (Admin only), because
  `install.php` cannot be run on the host.
- Verify with cache-busted `curl`, not from memory.
