# AI-Assisted Development — Bin ai-dev-1

Distilled from two large ChatGPT conversations: **"Agent mode usage tips"** (Aug 2025, ~376k chars — early prototyping of the roadside admin UI in ChatGPT Agent mode) and **"Create agents.md file"** (Oct 2025, ~152k chars — writing the agent rules files for the app and for the Indie Neon UI CSS library). Together they capture how Jason's working practices with coding agents formed, and the settled rules he adopted.

---

## 1. Agent-mode usage tips (from "Agent mode usage tips")

Practical answers Jason got about stretching limited Agent Mode quota (ChatGPT):

- There is no way to buy more Agent Mode runs; limits reset on a rolling (roughly daily/weekly) window — if capped, wait for reset.
- **Batch work into one larger run** instead of many small ones; write prompts that are clear and complete so runs don't need restarting. This is the main lever for making quota go further.
- Watch for quiet upgrade invitations / extended-access forms in account settings.
- Agent-like behavior can be replicated via chained API calls (separate rate limits) if chat quota is exhausted.
- **Sandbox download links expire.** Repeatedly bit him ("session expired", 404s on old zip links). Lesson: download generated artifacts immediately; once the link dies the agent must fully rebuild the package to produce a fresh one.

## 2. Hard-won workflow rules for working with coding agents

These rules emerged directly from breakage during the "Agent mode usage tips" session (each full-project regeneration lost previous work — the sidebar disappeared, the purple backlight effect vanished, index.css shipped with literal `\n` characters, deploys 404'd):

1. **Only change what was asked.** Jason's exact settled instruction, repeated verbatim to agents afterward: *"please please please only change what I ask to be changed, you need to triple check the integrity of the code before and after that you have not changed things you were not asked to."* This later hardened into AGENTS.md's "Change the minimum surface area" non-negotiable.
2. **Scope work to one module at a time and require permission for anything outside it.** His settled phrasing: *"Flesh out the service request pages and only the service request pages. Do not touch or alter any other part of the project without asking first."*
3. **Prefer patch packages over full rebuilds.** After full-repackage regressions, the workflow shifted to PATCH zips containing only the added/updated files (e.g., a Service-Requests-only patch), leaving the rest of the project untouched.
4. **Verify before trusting "complete."** He learned to challenge the agent ("are you sure?") after receiving zips missing components; the agent's confident checklists were not reliable. This drove the later adoption of diagnostics/self-test pages (see §5).
5. **Ask for exact file + exact string when hand-editing.** The productive pattern for small tweaks (e.g., restoring the backlight): agent names the file (`src/App.jsx`), shows the current code block, and gives the single replacement `className` string — "that's the only change."
6. **Copy/paste hygiene:** a pasted CSS file with literal `\n` escape characters broke the Vite/PostCSS build. Paste real newlines; when an agent supplies a file, replace the whole file contents rather than inline-pasting escaped text.

## 3. Early UI prototype conventions (from "Agent mode usage tips")

The first working prototype of the roadside admin UI, and conventions that persisted:

- **Stack:** Vite + React + Tailwind (initial canvas version also used shadcn/ui, lucide-react, Framer Motion). Run locally via `npm install && npm run dev`; deployed under XAMPP.
- **Theme:** dark background (black/zinc) with **neon indigo/purple accents and glow ("backlight") effects** — this became the signature look. Active nav buttons and panels get a purple ring + soft glow, e.g. `ring-1 ring-purple-500/50 shadow-[0_0_20px_rgba(168,85,247,0.35)] bg-purple-600/10`; row hover uses `hover:bg-purple-600/20 hover:ring-1 hover:ring-purple-500/40`.
- **Layout:** header + sidebar of **button-style nav (buttons, not links)** + main content; modules were Dashboard, Service Requests, Dispatch, Customers, Vehicles, Catalog, Accounting, Users, Settings.
- **Service Request workflow conventions** (already present here, later encoded in AGENTS.md): quick-create modal; line items added only via catalog pickers (separate Service and Item catalogs with search; selecting appends the line and closes the picker); Subtotal shown with the disclaimer *"final invoice may vary as the scope of work changes on-site."*
- **XAMPP deployment convention:** set `base: "./"` in `vite.config.js` so all asset paths are relative and the production `dist/` build works from any htdocs subfolder (this fixed the recurring 404s). One-click build scripts (`build-win.bat` / `build-mac.sh`) generate `dist/` locally since the agent sandbox can't run `npm run build`; drop `dist/` into `C:\xampp\htdocs\...` — no dev server needed.

## 4. AGENTS.md — the settled rules file for the roadside app (from "Create agents.md file")

Jason asked for "a set of rules and procedures that guide coding agents behavior to improve code quality, consistency and accuracy." The resulting **AGENTS.md for Indie Roadside Admin** (PHP 8.2.12, OOP, MVC/DDD) is the single source of truth for agent behavior. Its lasting content:

### Non-negotiables
1. **Do not invent requirements.** Search repo docs first; if still ambiguous, stop and open an issue titled `CLARIFICATION: <topic>` with a proposed assumption and questions.
2. **Change the minimum surface area** — smallest well-tested change that satisfies the requirement.
3. **Keep the system deployable** — `main` always passes tests/static analysis; no TODOs or commented-out code in commits.
4. **Reproducible builds only** — exact versions, lockfiles, no machine-specific paths.
5. **Security and data integrity override speed** — choose the safer option and document why.

### Domain invariants (enforced in code AND database — "belt and suspenders")
- Invoice requires **License Plate + State** recorded before creation.
- **Vehicle record requires VIN** (plate+state may look up a VIN but can't create a vehicle alone). `vehicle.vin` NOT NULL + unique; unique `(plate, state)` for active vehicles.
- Service Request captures vehicle make/model/year/color at intake; service records attach to both customer and vehicle.
- Service Request statuses: **Pending, Accepted, Completed, Cancelled, Rejected** (Service Order has its own separate statuses).
- Line-item invoices; items only from the **catalog** (no free-text items); `+` opens the picker, selection appends and closes.
- Track payments, expenses, warranties on installed parts; generate tax, PDF invoices, simple reports.

### Engineering standards
- PSR-12, strict types, final classes by default; PHPStan level 9; PHP CS Fixer; never silence errors with `@`.
- Thin controllers; business logic in UseCases; persistence in Repositories; no circular deps across modules (cross-module via UseCases or Domain Events).
- Immutable Value Objects validated on construction (Plate, State, VIN, Money, PhoneNumber, Email); **never floats for money** — Money VO with configured rounding; UTC everywhere in back end with a `Clock` abstraction; UUIDv7/ULID IDs, no auto-increment exposure.
- Migrations idempotent and reversible; transactions for multi-table changes; domain events with outbox pattern for reliability; PDF/email retryable with idempotency keys; prevent N+1 queries.

### Process
- **Testing pyramid** (unit > integration > e2e), >85% critical-path coverage, golden-PDF snapshot tests for invoices.
- **Git:** protected `main`; `feat/`, `fix/`, `chore/` branches; Conventional Commits; PR template with Why/What/How-validated/Risk-rollback + checklist (docs updated, PHPStan clean, tests pass, migrations reversible, UI matches design system).
- **CI quality gates:** build fails if diff coverage <80%, any PHPStan error, or formatting drift.
- **Definition of Done:** acceptance criteria + invariants enforced; tests green locally and in CI; static analysis clean; docs updated; reviewer can reproduce.
- **When stuck:** re-read spec + AGENTS.md → write a minimal failing test → 30–60 min spike in a throwaway branch → open an issue with context, attempts, blockers, and a concrete question.
- **Dependencies:** avoid new libraries unless they materially reduce risk/code; must be actively maintained, permissively licensed, pinnable.
- **Ubiquitous language:** Service Request, Service Order, Work Order, Invoice, Payment, Warranty — no ambiguous synonyms; glossary changes go to `docs/glossary.md`.

### Agent Prompt Contract (task scaffold)
Every AI-agent task starts with this scaffold:

```
ROLE: Senior PHP 8.2.12 engineer using DDD/MVC for Indie Roadside Admin.
GOAL: <one sentence>.
INPUTS: <files/specs explicitly listed>.
CONSTRAINTS: enforce domain invariants (VIN for Vehicle, plate+state for
  Invoice); PSR-12, PHPStan 9, strict types; no floating-point money;
  minimal change; add tests; keep main deployable; UI dark + neon,
  buttons for nav.
OUTPUTS: changed files with full contents (no placeholders); tests;
  short doc notes.
CHECKS BEFORE DONE: static analysis clean; tests green; migrations
  reversible; business rules enforced in code + DB.
```

### UI/UX rules (high level)
Dark theme, neon blue/purple accents; buttons (not links) for primary nav with tactile push effect; 3-panel layout (header, button sidebar, main); catalog picker via `+`; Subtotal with scope-change disclaimer; phone mask `(xxx) xxx-xxxx`; plate/state format validation; WCAG AA contrast and full keyboard navigation.

## 5. Indie Neon UI — the CSS library and its RULES.md (from "Create agents.md file")

The second half of the conversation packaged Jason's hand-built modular CSS kit (base.css design tokens + per-component files: buttons, button-group, accordion, dropdown, badges, cards, circular-progress) into a public library.

### Decisions Jason made
- **Name stays "Indie Neon UI" / `indie-neon-ui`** — he explicitly rejected the proposed rename to `indie-roadside-ui` ("No... I like the current name"). Only the CDN file was renamed `*.cdn.js` → `*.bundle.js`, and docs filenames were standardized (docs.html, component-demo.html, bundle-demo.html, quickstart.html, kitchen-sink.html, diagnostics.html).
- **Bar for release:** *"I want all the features working out of the box, no custom code required to get it to function. Package is in a manner fit to represent to the public."* This became the library's core principle.
- Two consumption modes: `dist/` CSS+JS pair (+optional autoinit script) or a single-file `indie-neon-ui.bundle.js` that injects CSS and auto-inits. Also a `indie-neon-ui.scoped.css` build with everything nested under a `.ir` wrapper class so styles can't leak into a host page (plain-English: wrap the app in `<div class="ir">` and nothing spills out).

### RULES.md non-negotiables (the settled agent-rules file for the library)
1. **Zero custom code required** — every component works via semantic HTML + documented `data-*` attributes; progressive enhancement.
2. **No breaking the public API** — changing classes, `data-*` hooks, event names, or file names is breaking → major version + migration note + deprecation cycle (old attribute kept working one minor cycle).
3. **Idempotent init** — `IndieNeon.init()` safe to call repeatedly; double-init guards mandatory (a real bug he hit: components binding twice made everything "feel broken").
4. **Security & accessibility first** — keyboard support, ARIA, focus management, WCAG AA contrast, `prefers-reduced-motion`, no inline event handlers.
5. **Tokens are the single source of truth** — all colors/spacing/radii/shadows via CSS variables; **no hard-coded colors in components** (enforced by stylelint "no hex" rule); stable public token names.

Other settled rules: SemVer with defined MAJOR/MINOR/PATCH meanings; stable repo layout (`/dist`, `/docs`, `/examples`, README, RULES.md); single global `window.IndieNeon` namespace, vanilla JS, zero dependencies; components discover markup via classes + `data-*` (no hard-coded IDs); custom events documented (`tabs:change`, `dropdown:change`, `pagination:change`); soft performance budgets (core CSS ≤50KB, JS ≤35KB min+gzip); SRI hashes published for all public snippets and recalculated on every dist change.

### The diagnostics pattern (settled QA practice)
After "so many things missing and broken...", the fix that stuck was **self-testing HTML pages** shipped with the library:
- `docs/diagnostics.html` — programmatically exercises tabs, dropdown, modal, pagination, and sidebar with hidden fixture markup and prints green OK / red FAIL per component. **Must pass before any release.**
- `docs/kitchen-sink.html` — every widget on one page for visual sanity, must render with no console errors.

This is Jason's practical answer to unverifiable agent claims of completeness: make the package prove itself in the browser, then report exactly which line is red so the agent can patch that one component.

### Definition of Done
- **Per component:** markup contract documented with example; works with no custom code; keyboard + ARIA validated; re-init doesn't duplicate handlers; tokens only; added to kitchen-sink/diagnostics; CHANGELOG entry.
- **Per release:** diagnostics pass; SRI hashes updated; version bumped; zip built containing `/dist`, `/docs`, `/examples`.

### Enforcement kit
The rules were made mechanical with a repo kit: PR template whose checklist enforces RULES.md; bug/feature issue templates; GitHub Actions CI (stylelint with no-hex/variables-only rules, ESLint browser config forbidding `eval` and stray globals, docs smoke tests, SRI check); `.editorconfig` + Prettier; helper tools `recalc-sri.js` (`--check`/`--write`) and `smoke-docs.js`. RULES.md itself changes only via PR with approval and a CHANGELOG note. Library also carries an Agent Prompt Contract scaffold mirroring the app's (role, goal, constraints incl. zero-config + stable selectors + tokens-only + a11y, outputs with full code and no placeholders, checks incl. diagnostics passing and SRI recalculated).

## 6. Cross-cutting takeaways

- **Rules files are Jason's chosen mechanism** for taming agent inconsistency: one authoritative markdown file per project (AGENTS.md for the app, RULES.md for the library), plus a per-task prompt scaffold, plus CI that mechanically enforces the rules.
- **Minimal-change discipline and explicit scoping** ("only the service request pages, ask before touching anything else") were learned the hard way in Agent mode and then codified as non-negotiables in both rules files.
- **Verification is externalized**: diagnostics pages, kitchen-sink pages, checklists, and quality gates — never trust an agent's own "it's complete."
- **Full outputs, no placeholders** is a standing requirement in both prompt contracts (agents returning partial files caused most of the Aug 2025 breakage).
- **Naming/branding calls are Jason's alone** — agents proposing renames should ask, not act (the indie-roadside-ui rename attempt was overruled).
- Note: the AGENTS.md targets the PHP 8.2.12 DDD/MVC app ("Indie Roadside Admin"), while the Aug 2025 prototype was React/Vite — the PHP stack is the later, authoritative direction in these conversations.
