# White Knight Roadside — Admin

A dispatch-to-cash system for a mobile roadside and repair business. One job runs
one chain, and every document in it is a real record with its own number:

```
Service Request → Estimate → Work Order → Invoice → Payment → Receipt
```

PHP 8.0+ and MySQL 8 / MariaDB. No framework, no Composer, no build step —
any shared or managed PHP host in production, a stock local PHP + MySQL stack
for development. Upload the folder, point the document root at `public/`, done.

---

## Install

**1. Create the database.**

```sql
CREATE DATABASE wkr_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wkr'@'localhost' IDENTIFIED BY 'a-real-password';
GRANT ALL PRIVILEGES ON wkr_admin.* TO 'wkr'@'localhost';
```

**2. Import the schema.** On shared hosting, upload `data/schema.mysql.sql`
through phpMyAdmin. From a shell:

```bash
mysql -u wkr -p wkr_admin < data/schema.mysql.sql
```

**3. Set the credentials** in `config.php`, or in the environment
(`WKR_DB_NAME`, `WKR_DB_USER`, `WKR_DB_PASS`) if you'd rather they weren't in a
file that gets committed.

**4. Seed.**

```bash
php data/setup.php              # interactive: pick Clean Install, Clean with
                                # Catalog, Full Demo — or Uninstall
                                # (or double-click setup-wkr.bat on Windows)

# Scriptable equivalents:
php data/install.php            # Clean Install — admin login, settings, markup tiers only
php data/install.php --catalog  # Clean with Catalog — plus the example price book
php data/install.php --demo     # Full Demo — example staff, customers and jobs (dev only)
```

Then serve `public/`:

```bash
php -S 127.0.0.1:8088 -t public
```

| Role        | Email                 | Password      | Notes                          |
|-------------|-----------------------|---------------|--------------------------------|
| Setup admin | admin@setup.com       | `admin123`    | Temporary — see below          |
| Dispatch    | dispatch@wkrllc.com   | `dispatch123` | `--demo` installs only         |
| Technician  | tech@wkrllc.com       | `tech123`     | `--demo` installs only         |

The install seeds exactly one admin login, and `install.admin.password_hash` in
`config.php` decides which one:

- **empty** — a **temporary setup admin** at `admin@setup.com` / `admin123`,
  flagged `is_setup`. Create your real admin under Admin → Users and it
  deactivates itself automatically (kept, inactive, for the audit trail). This
  is the local-dev case. `install.admin.email` is deliberately ignored here, so
  a real address is never seeded with a published password.
- **set** — the **real admin** at `install.admin.email`, not flagged and never
  auto-retired. `php data/setup.php` option 5 generates the bcrypt hash, which
  is how a public server comes up with no known password. Nothing named
  `admin123` exists on such an install.

The dispatch and tech logins exist only in Full Demo installs, for dev and tests.

If the database can't be reached, the app says so plainly — which database, on
which server, and the exact commands to fix it — rather than showing a stack
trace.

`data/schema.mysql.sql` is generated from `app/Schema.php`, which is the single
source of truth. After changing the schema, regenerate it with
`php data/install.php --dump`.

### Tests

```bash
tests/e2e.sh                                    # against MySQL, as configured
WKR_DB_DRIVER=sqlite tests/e2e.sh               # no server needed
```

108 assertions, passing on both engines: every screen renders, every role
boundary holds, every hard gate blocks what it is supposed to block, the
checkout page settles an invoice exactly once, and every provider callback is
verified against a real signature — including a tampered body, an unsigned
request, a replay and a stale timestamp.

`Schema.php` emits dialect-correct DDL for MySQL and SQLite from one definition,
so the suite can run without a database server and the two can't drift apart.
**MySQL is the deployment target; SQLite exists for the test suite.**

The suite drops and rebuilds its tables first, so don't point it at anything you
care about.

---

## The chain

**1. Service Request** — the record that somebody asked for help. It arrives by
phone, from the website, by text, or electronically from a provider. It is *not
required to be accurate*: roughly who, roughly what, roughly where, and that is
all. It carries no prices, no line items, and it creates neither a customer nor a
vehicle. This separation is deliberate — an inbound electronic request is data
someone else typed, and treating it as fact is how bad records get born.

**2. Estimate** — the contract. Promoting a request is the moment the record
stops being hearsay: the customer is confirmed or created, the work is priced
from the catalog, and the customer's authorization is captured with a timestamp,
an IP address and a device string. Above $200 that authorization must include a
signature. This document is what defends a chargeback.

**3. Work Order** — what actually activates a technician. Raised by dispatching
an authorized estimate; the authorized scope copies onto it, and anything the
technician adds in the field is measured against that scope. It cannot be closed
without a VIN unless every line on it is flagged as not needing a vehicle.

**4. Invoice** — built from what the technician recorded, falling back to the
estimate when there was no field visit. It cannot be issued without a valid VIN,
and it cannot be issued if the total has drifted from the authorized estimate by
more than the lesser of $200 or 10% — not without re-authorization, signed.

**5. Payment / 6. Receipt** — cash and cheques are recorded at the till. Card
payments go out as a checkout link, texted to the customer and settled from
wherever they are. Partial payments are supported and a receipt is generated for
each. Idempotency keys are minted server-side before any processor is called, so
a double-click — or a retried webhook — can never become a second charge.

Nothing is ever deleted. Corrections are voids and credits, and every state
change writes to an append-only audit log.

---

## Layout

```
config.php              db, company, business rules, integration drivers
public/index.php        front controller — all routes in one readable list
public/assets/          css + js (phone mask, VIN validator, signature pad)
data/schema.mysql.sql   generated schema, for import on shared hosting
data/setup.php          interactive installer/uninstaller — asks which install type
data/install.php        first-run installer and schema dumper
app/Core.php            App, Auth, Router, View
app/Db.php              PDO wrapper, transactions, migrate
app/Schema.php          the whole schema, both dialects
app/Domain.php          DocNumber, Audit, Lines, Rules, Sms
app/helpers.php         escaping, money, phone E.164, VIN check digit
app/Contracts/          SmsGateway, PaymentGateway, VinDecoder, Geocoder
app/Services/           Http.php (the only socket) + the drivers
app/Controllers/        one per document in the chain, plus records and webhooks
app/Views/              layouts, partials, pages
data/seed.php           layered seeding: core / example catalog / demo jobs
docs/                   business rules, decisions, integrations
tests/e2e.sh            end-to-end gate test
```

`app/Domain.php` holds every hard rule in one class, `Rules`. To know what the
system will and won't allow, read that file — the screens only ever ask it
questions.

## Integrations

Every outside service sits behind an interface, and **the default drivers need
no account anywhere**. Messages are composed and consent-gated into an outbox.
Payment links open a checkout page this application serves itself. VIN decoding
runs offline. Every flow — issue a link, text it, take the payment, print the
receipt, handle a STOP reply — can be walked end to end on a laptop.

The **Telnyx** and **Square** drivers are complete, including signed webhooks at
`/webhooks/telnyx` and `/webhooks/square`. Switching to one is a dropdown in
Settings plus credentials; no code changes and no redeploy. See
[docs/INTEGRATIONS.md](docs/INTEGRATIONS.md).

## Deploying

No build step, no Composer, no SSH required. Create the database in your host's
control panel, then run `php data/setup.php` locally and choose
**[5] Public server**: it collects the database details, the public
URL, and a real admin login, and writes `deploy/config.php` — a production
config with debug off, the admin password hashed in, and first-boot seeding set
to *clean* so no demo data can ever reach a public server.

Setup then offers to do the deployment itself: give it the FTP account from the
hosting control panel and it uploads the application over FTPS or SFTP, places
the production config as `config.php`, and opens the site — whose first request
against the empty database installs the schema and seeds it. No phpMyAdmin
import, no seeded passwords. All that remains in the hosting panel is pointing
the domain's document root at the project's `public/` directory. The
`.htaccess` files handle the front-controller rewrite and deny web access to
everything above `public/`; on nginx, route all requests to `public/index.php`.

Providers are switched on afterwards from Settings, not by editing a file —
changing a driver shouldn't need a deploy round trip.

## Further reading

- [docs/MANUAL.md](docs/MANUAL.md) — the user manual: how to run a job from call to receipt
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — every gate, and why it exists
- [docs/DECISIONS.md](docs/DECISIONS.md) — the judgement calls, and what was rejected
- [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) — swapping a stub for the real thing
- [AGENTS.md](AGENTS.md) — conventions for anyone (or anything) extending this
