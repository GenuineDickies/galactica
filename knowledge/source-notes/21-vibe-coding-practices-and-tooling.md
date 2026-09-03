# AI-Assisted Development — Bin ai-dev-2 (Distilled Reference)

Distilled from ~21 exported conversations (ChatGPT, Claude, Gemini) about building the WKR admin app with AI coding agents. Working names for the app varied across chats: "Indie Roadside Admin," "RoadRunner Admin," "White Knight Roadside (WKR) Internal Platform." Stack throughout: PHP 8.2.x OOP, MySQL 8, server-rendered HTML with minimal JS, dark + neon UI, XAMPP local dev, Cursor/agent-driven workflow.

Note on evolution: early chats (Sep–Oct 2025) assume multi-role RBAC (Admin/Dispatch/Driver/Customer). By late 2025 the project pivoted to **single-user (owner only), no RBAC** — later specs and the meta prompt treat that as a hard guardrail. Prefer the later single-user resolutions.

---

## 1. Core vibe-coding method (final workflow)

**From "Vibe coding effectively":** the most complete methodology discussion in this bin.

- **Effective vibe coding = feel first, then lock it down with structure.** Give the vibes rails: one-sentence intention, 2–3 fixed constraints (stack, data source, timebox).
- **Build vertical slices, not systems.** One tiny end-to-end experience that actually works (one screen, one real data path, one action). Hard-code what you must but label it (`// MOCK DATA`, `// TODO: config`). "Ugly but isolated" beats premature clever abstractions.
- **Extract on the second duplication, not the first.** Comment the *why*, not the what. Name things by intent (`JobCard`, not `Card1`).
- **Parking-lot list** for idea sparks so tangents don't derail the current slice.
- **Checkpoint ritual:** every time something feels right — git commit + 3–5 bullets describing what this version does (seed of future spec/README).
- **Two modes:** Vibe mode (discovery) vs Engineer mode (stability). Switch to Engineer mode when you fear touching things, keep copy-pasting, or can't remember where X is handled: clean naming, extract components, replace hard-coded values, basic tests, update ARCHITECTURE.md.
- **Repeatable loop:** define vibe → sketch flow/narrative → vibe slice → checkpoint → prune/refactor → document in 10 lines → pick next slice.

**Jason's decided build order (final resolution of this chat): UI first, then hook in the data.** The locked-in loop is **UI → Shape → Wire**:
1. Build the screen with fake/hard-coded data until it feels right (right fields, clear flow).
2. **Freeze the data shape** — list exactly what data goes in and out of that screen; that becomes the contract for DB columns/JSON.
3. Wire PHP/DB/APIs to match that shape.

The chat also explored the inverse ("no formatting, just data, style later") — verdict: also viable and easy *if* the unstyled HTML keeps consistent structure, sensible `name=""` attributes, and component boundaries; the two real risks are trash HTML structure and giant pages with no reusable components. But Jason explicitly preferred UI-first.

**On "vibe coding is a ton of typing":** if it feels like more typing than coding directly, you're doing it wrong — over-documenting, over-explaining, or doing UI+logic+structure at once. Efficient loop: 1–2 sentence vibe → agent generates scaffold → 1–2 sentence adjustments → one sentence to wire the backend. Manual coding is fast at the start and slow at the end; vibe coding is the reverse and compounds. You're the art director, not the assembly line.

## 2. Preventing agents from breaking the UI (ruleset)

**From "Vibe coding effectively"** — concrete tactics, all worth keeping:

1. **UI Protection Rules block in AGENTS.md**: no layout/color/spacing/typography changes unless explicitly requested; a "Protected files" list (layout components, ui components, tokens.css, theme.css); reference it in every prompt.
2. **Stable design-system atoms**: Button/Card/Input/etc. in one folder marked with loud "CORE UI COMPONENT — do not change; compose elsewhere" comments. Agents compose, never edit.
3. **Narrow file scope per task**: name the exact files the agent may touch; smaller context = less wandering.
4. **Alarms**: snapshot tests on components; later, visual regression (Storybook/Playwright).
5. **Lock style tokens**: single source of truth for colors/spacing; lint against arbitrary values.
6. **Git discipline**: agents never commit to main; branch + tiny PRs, review diffs like a junior dev's.
7. **Explicit negative rules in prompts** ("Do NOT: change CSS classes, move elements, rename components…") — agents respond well to boring, specific prohibitions.
8. **Separate sessions**: "logic only" sessions vs "visual polish" sessions — never both.
9. **Golden reference screens**: 1–2 files marked "GOLDEN UI EXAMPLE — follow this pattern, don't modify."
10. **DO NOT EDIT fences** inside files to protect sections.

## 3. Guidance documents for coding agents (the docs kit)

**From "Guidance for vibe coding agents"** — the refined, research-informed `/docs` set (final version of the list):

| Doc | Purpose |
|---|---|
| `AGENTS.md` | Agent manifest/constitution: entry point, mission, never-break rules, behavior protocol, directory quick-map, pointers to deeper docs |
| `agent-rules/` folder | Modular rule files loaded per context: `language-php.md`, `language-js.md`, `project-domain.md` (VIN/plate/invoice rules), `style-rules.md`, `anti-patterns.md` (explicit "never do this") |
| `project-brief.md` | Vision, audience, MVP vs stretch, brand/UX vibe (dark theme, knight motif, neon glow) |
| `architecture.md` | Structural map: pattern, directory layout, module boundaries, lifecycle flows |
| `style-guide.md` | Palette, typography, component/interaction rules |
| `naming-conventions.md` | PHP PascalCase/PSR-4, DB snake_case, migration filename pattern |
| `requirements.md` | Feature-by-feature business rules — the source of truth |
| `traceability-matrix.md` | Requirement ↔ implementation files ↔ tests ↔ status |
| `runbook.md` | XAMPP setup, migrations/seeders, testing, packaging, troubleshooting |
| `entry-summary.md` | 1–2 page "start here" doc optimized for agent context windows |

Key idea: strong agent entry point + modular enforceable rules + lightweight on-ramp that doesn't flood the context window.

**From "Good AGENTS.md file"** (research-backed findings worth remembering):
- AGENTS.md is an **open standard** (20k+ projects; read automatically by Codex, Copilot agent mode, Cursor, etc.) — a "README for machines," created to unify tool-specific files like CLAUDE.md and .cursor/rules.
- **Keep it under ~150 lines**; concise step-by-step directives, exact commands in backticks.
- Typical sections: project overview, setup/build commands, test/CI commands, code style, git/PR conventions, security notes, gotchas.
- **Don't duplicate other docs** — link/point to them ("For DB changes, see DB_GUIDELINES.md").
- **Nested AGENTS.md files** in subfolders for monorepos; nearest file wins.
- Update it in the same commit as process changes; stale instructions mislead agents.
- Structure is flexible — no mandated headings; goal is self-explanatory for an AI.

**From "Improving vibe coding agents"** — the toolkit to *give* agents:
1. Knowledge docs (ARCHITECTURE, PRD, AGENTS, STYLE_GUIDE, DOMAIN_MODEL) so every agent shares the same mental model.
2. Starter skeletons and code templates (router, base controller, CRUD boilerplate, UI kit) so agents build features, not plumbing.
3. Validation/enforcement: PHPStan/Psalm, PHPCS, ESLint/Stylelint, PHPUnit/Pest scaffolding, pre-commit hooks — lets agents **self-check**.
4. **Domain helper primitives that encode business invariants** as code (e.g., `VinValidator.php`, `PhoneFormatter.js`, `InvoiceCalculator.php`) — agents implement complex features correctly because rules are pre-wired.
5. Task templates (objective, acceptance criteria, affected files, behavioral examples, business rules) + evaluation checklist.
6. Reproducible dev environment (.env.example, seed script) + lightweight CI.
(Remainder of that chat is XAMPP debugging — see §8.)

## 4. Refined prompts and rulesets (final versions)

**"Programming Agent Prompt Design"** — Jason iterated from a long prompt down to progressively simpler versions; his complaint each round was "too complex." **The final 8-line ultra-compressed prompt he settled on:**

> You are building production code.
> Always choose the simplest working solution.
> Avoid cleverness, abstractions, and over-engineering.
> Keep file structure shallow and obvious.
> Make small, safe changes only.
> Use clear names and short functions.
> For every task: state goal → short plan → files changed → code → how to test.
> If unsure, pick the simplest option.

Lesson: elevate "simplicity first" to the very top so agents don't drift into architect mode; the leanest prompt that still enforces discipline wins.

**"Programming agent ruleset"** — Jason's six core rules plus one addition, made "one document":
- Core: (1) keep it simple, (2) easy to understand, (3) polished UI ("if it's visible, it's finished" — no temporary UI ships), (4) small steps, (5) test every change, (6) test it again.
- **Mandatory Living TODO discipline** (his addition, saved to ChatGPT memory): a single structured TODO list is the source of truth; work only exists if it's a TODO item; columns Backlog / Next (max 1) / In Progress (max 1) / Testing / Done; each item has ID, one-sentence goal, 3–7 step checklist, and test plan; updated before starting, after each change, after each test run, on scope change, and at completion. Done requires tests passed + manual UI sanity + no debug leftovers + docs updated.
- Every agent output must include: updated TODO list, what changed (1–3 bullets), how tested (exact commands), what's next.

**"Meta prompt for admin platform"** (Feb 2026 — the most complete build prompt, single-owner era). Highlights beyond the ruleset above:
- Operating rules: simple/readable/boring; small steps with test-verify-update-TODO after each; no stubs in core flows; "when uncertain, choose the option that reduces future complexity."
- Constraints: PHP 8.2, MySQL 8, single user, no RBAC, no containers; PDFs with stable numbering (SR-, EST-, WOR-, INV-, PAY-/RCT-); attachments; event log.
- Module build order: Dashboard → Customers → Vehicles → Service Requests → Catalog → Estimates → Work Orders → Invoices → Payments/Receipts → Expenses/Reports.
- Minimum schema list (~18 tables incl. polymorphic attachments/signatures/event_log; customer_vehicle many-to-many).
- Required first output before coding: architecture overview, schema, milestone plan, initial living TODO.
- Guardrails: no multi-user auth, no external services unless required, **do not invent business rules**, no premature refactoring.
- Tests required for: numbering, VIN/vehicle invoice gate, signature thresholds, estimate-vs-invoice change authorization, catalog add-item validation.

**"LLM application proofreading prompt"** (Claude) — the concept: a prompt that reads an LLM-built app for *intent*, not correctness — a proofreader, not an error checker. Catches what linters miss, especially "real-world reasonableness": LLM code tends to be idealistic happy-path logic that breaks when a real user touches it. (Same chat: a deliberately minimal single-table `parts_warranties` schema, keyed to `service_request_id`; final decision was "go minimal" and add claim-recovery/replacement-chaining only if actually needed.)

**"Coding Agent Prompt"** — trivial; two initiation prompt templates (content omitted in export).

## 5. Framework, boilerplate, and tooling decisions

**"Laravel for coding agents":** why an agent benefits from Laravel — enforced conventions and predictable structure ("less likely to produce spaghetti over time"), artisan scaffolding agents can run deterministically (`php artisan make:model Vehicle -mcr`), ecosystem packages instead of hand-rolled auth/queues. Without Laravel the agent must build a mini-framework itself. Skip it for microservices/minimal APIs or front-end-only work. (No adoption decision recorded in this chat — WKR chats elsewhere continued with custom PHP MVC.)

**"Boilerplate for app development"** — decision framework: (A) no boilerplate = full control, more upfront work; (B) lightweight MVC boilerplate = recommended middle ground; (C) full framework starter (Laravel Breeze/Jetstream) = overkill for this app. **Recommendation accepted: avoid heavy boilerplates; use a lightweight MVC skeleton or build a reusable mini-boilerplate once** (routing, auth, DB class, sidebar layout). Same chat has a complete Cursor setup for the custom-MVC path: project layout Cursor understands (`/app/Controllers|Models|Views|Core`, `/public/index.php` single entry point, tiny Router class) and a `.cursorrules` file encoding: PSR-12 + strict_types; MVC rules ("Controllers: no SQL; Models: DB + business rules only; Views: no DB calls, escape output"); business rules (VIN optional at intake, REQUIRED to complete/invoice/collect payment; catalog-only line items; free-text only via explicit 'customer-supplied item' flag); scope controls ("change only files under that module's folder; don't rename/move files unless asked; propose diff if a change crosses layers"); PDO prepared statements + CSRF; dark/neon UI rules.

**"Initialize project with specify" (spec-kit):** the correct facts, hard-won:
- `/specify` is **not** a Cursor built-in and spec-kit is **not** an npm package — it's GitHub's spec-driven development toolkit installed as a Python CLI.
- Canonical usage: `uvx --from git+https://github.com/github/spec-kit.git specify init <project> --ai cursor`, then the workflow **`/specify` (what) → `/plan` (how: stack, architecture) → `/tasks` (breakdown) → implement**, with spec/plan/tasks files acting as the contract. Cursor picks up the slash commands via `.cursor/commands/`.
- Behind his proxy (192.168.49.1:8000), uv couldn't reach PyPI; working fallback: Python venv + `pip install --proxy=... git+https://github.com/github/spec-kit.git` (add `--trusted-host pypi.org --trusted-host files.pythonhosted.org` if TLS interception), then `specify init ... --ai cursor`. pipx variant also documented.

**"Using roadmap.sh for App Design":** use roadmap.sh not as curriculum but as (1) **design gates** — pull only relevant Backend/SQL/Frontend/System-Design sections when about to build that layer; (2) convert roadmap nodes into concrete **design deliverables** (schema, state machine for SER→…→RCT pipeline, component library plan, service-layer boundaries); (3) **preflight checklist** before each module (e.g., before Invoices+Payments: transaction atomicity, webhook idempotency, VIN-null cases, CSRF even single-user); (4) keep a **roadmap traceability matrix** in the repo. Suggested order: lightweight system design → database → backend services → UI flows (fastest operational screens first) → defense-packet automation.

## 6. Coding Agent Spec — merged source-of-truth business rules

**From "Coding Agent Spec"** — this chat produced the merged spec ("Indie Roadside Admin — Application Spec") after several rule refinements. Final resolutions that superseded earlier rules:

- **Pipeline artifacts:** SER → EST → EAP (approval) → WOR → COS (change of service) → SCR (completion report) → INV → PAY → RCT → Evidence Packet Export (one-click chargeback-defense packet: all docs + signatures + timestamps/device/geo/IP metadata + API provenance snapshots).
- **VIN rule evolution (important final state):** VIN is *not* an invoice-level rule — it's a **vehicle-context rule derived from service line items**. Each service SKU carries `vehicle_requirement`: REQUIRED (Jump Start, Lockout), OPTIONAL (Tire Replacement — rim may be serviced with no vehicle present), NOT_APPLICABLE (fees/admin). OPTIONAL **defaults to VIN required** unless overridden. Invoice-level `no_vehicle_serviced` flag: blocks REQUIRED services, sets OPTIONAL lines to NO_VEHICLE_PRESENT, allows VIN NULL. Single computed source of truth: `requires_vin = !no_vehicle_serviced AND any(line.vehicle_context == VEHICLE_SERVICED)`.
- Vehicle records can only be created with a VIN (hard rule); vehicles may belong to multiple customers.
- **Plate→VIN and VIN-decoder APIs are enrichment only**: never block workflow on failure, not required to invoice; behind swappable adapter interfaces with config + mock mode; store full provenance (provider, timestamp, sanitized request/response, which fields came from API vs user) and flag mismatches with user-entered attributes.
- Signature thresholds: estimate approval signature if total > $200; change authorization if invoice-vs-estimate delta exceeds $200 OR 10%, whichever is smaller.
- Catalog-only line items (two trees: Services with stable SKUs; Parts & Materials); "+" picker flow; disclaimer "final invoice may vary if scope changes."
- Fuel delivery: flat-rate with included gallons (gas passenger 2, diesel passenger 5, diesel commercial 15); record actual gallons/pump price internally for margins; overage as separate catalog line.
- Tax-rate APIs (tail of chat): explored free options; because he wants to market the app someday, requirement noted that **tax rates must auto-update** (pointing toward a paid/first-party feed rather than static tables). No final provider chosen.

## 7. Domain design conclusions reached in dev chats

**From "Tenancy in coding"** (started as a tenancy question — single-tenant fits his app; the chat became lifecycle design):

- **Final Service Request lifecycle:** 1) Intake/Create (Pending) → 2) Triage/Decision (Accepted/Rejected/Scheduled) → 3) Assignment & Dispatch (Assigned→Dispatched→EnRoute) → 4) **On-Scene: VIN capture (required, optional No-Plate flag) → non-intrusive diagnosis → Estimate & Authorization (AwaitingAuthorization/Authorized/Declined/ChangeOrderRequired) → Execution (InProgress)** → 5) Completion Review → 6) Invoicing & Payment (Invoiced→PartiallyPaid/Paid) → 7) Closeout (Closed). Terminal paths: Cancelled(reason), NoShow, AuthorizationExpired.
- Jason's decisions during refinement: customer authorization/acknowledgment happens **on-scene in stage 4 before any work begins** (not a separate earlier stage); technician enters plate+state to get VIN via API before diagnosis, but **plate is optional — only VIN is 100% required** (No-Plate flag acceptable).
- **ETA model:** compute two ETAs (Arrival and Completion). Arrival = now + dispatch_delay + prep_time + route_time × calibration factors (traffic/weather/road type) + access buffer (curb 2 / apartment 8 / gated 12 min). Completion = Arrival + service duration baselines per job type + checkout time. Show P50–P80 windows, not single promises; recalc on status change, GPS drift >300m, traffic incident, auth delay, change order; freeze ETA-Completion during on-scene authorization. Insertion heuristic for stacked jobs.
- **Pricing ruleset (16 rules):** catalog only; service + site minimums; one travel policy (zones OR per-mile, never both); after-hours/holiday multipliers; vehicle-class and complexity adders; authorization cap hard stop; discount order = line-level → job-level → taxes; parts taxable / labor configurable; tips tracked separately (default recipient technician); stage-based cancellation fees ($0 before dispatch → trip fee dispatched/en-route → service minimum on-scene); change orders re-authorize the delta; provider volume-tier rate cards versioned per job (`rate_card_version`), recalculated monthly. Defined calculation-order engine (base lines → modifiers → multipliers → rate card → line discounts → subtotal → job discounts → taxes → total → fees → tips).
- Auto-escalation ruleset (timers + guards + actions) was also drafted for jobs slipping through stages.

**From "Admin access agents"** (post-pivot): only the Owner has admin access — single-user app, no RBAC, so no app-level "agents" hold privileges. For automation/AI/deploy agents: least privilege, separate credentials per agent, no god mode; deploy agent gets repo/build/push but not DB admin; migration agent gets ALTER/CREATE on the app schema only; backup agent reads DB + writes to backup destination but cannot write back to production; admin actions require the human to confirm in-app.

## 8. Environment and ops lessons (Windows/XAMPP/proxy)

- **XAMPP's PHP is not on PATH by default** — `where php` returning nothing means Windows can't find php.exe; add `C:\xampp\php` to PATH or call `& "C:\xampp\php\php.exe"` (PowerShell needs the `&` call operator for quoted paths). "Module already loaded" warnings = duplicate extension lines (e.g., php_openssl) in php.ini. "PDO could not find driver" = pdo_sqlite/pdo_mysql extension not enabled in the loaded php.ini (and check that a php.ini is loaded at all — `php --ini`).
- **Silencing progress bars for agents** (`--no-progress`, `--progress=false`, `--progress-bar off`): good for clean agent/CI logs, but then you need other liveness signals. The hung-vs-slow protocol: timestamps + verbosity (`composer -vvv`, `npm --timing`), global timeouts (`COMPOSER_PROCESS_TIMEOUT`, `timeout 30m`), 30-second heartbeat echoes, phase logging, retries for flaky registries. Decision rule for "hung": no new log lines for 5–10 min AND no I/O/network activity AND time budget exceeded → kill and retry. A reusable `run-step` wrapper (timeout + retries + heartbeat + log file) was produced for both Bash and PowerShell.
- Proxy environment (192.168.49.1:8000) repeatedly broke npm/uv/git/pip; fixes were per-tool proxy config and, when TLS-intercepted, temporary trusted-host/verification relaxation (see spec-kit entry, §5).

## 9. Project management for AI development

**From Gemini "Application Development Project Management Guide"** (Jason: "I'm not a programmer; I'm using GitHub Copilot/Projects as project manager"): standard five-phase map he uses as his checklist — 1) Conception & Planning (problem/vision, requirements functional + non-functional, user stories, MVP scope, roadmap) → 2) Design (UX flows/wireframes, UI, technical architecture, DB design, stack selection) → 3) Development (env + version control first, then frontend/backend/DB/API, CI/CD) → 4) Testing/QA (unit → integration → system → UAT, performance, security) → 5) Deployment & Maintenance. Also covered: tasks *can* be imported into GitHub Projects (CSV / bulk-add); a Project is the planning board, a Repository is the code — they link but are different things. Core feature list he stated for the app: auth, service-request tracking from entry to final payment logging, all forms/data, income & expenses, inventory & vendors, and **document scanning → online LLM classifies doc type, extracts key details, links to associated records**.

**From "AI-assisted development guide"** — his prioritized AI-dev step order: Phase 1 before any code (write vision/README with the AI, simplify the stack, configure AI rules in the IDE); Phase 2 prompt strategy (set the AI's persona, state the problem and what "done" looks like, provide context — docs/screenshots/snippets, and **always ask the AI for a plan first** before letting it code). The rest of the chat is app-domain work (forms inventory, relationship explanations, form-field reports exported to CSV for Google Sheets) — the useful takeaway is the practice of asking the AI "what forms/fields are missing?" against the existing data model and integrating the answers into the schema.

## 10. Early AGENTS.md drafts (superseded but historically useful)

"Create AGENTS.md file" (Sep 2025) and the generated file in "Good AGENTS.md file" date from the multi-role era: agent categories (Architect/Coding/Dispatcher/Accounting/Customer Support/Data Integrity), role scopes, agent-to-agent communication tables. Still-valid elements that survived the pivot: vibe-coding protocols (**complete non-truncated files, never `// existing code here` placeholders**, naming conventions, OOP compliance, dark neon UI default), traceability (feature → PRD ref → file path → test coverage), coding-agent workflow (prompt → implementation plan reviewed before execution → complete deliverables → schema-aware validation → manual testing), and VIN + plate+state uniqueness rules. The role/RBAC content is obsolete.

## Skipped (no lasting dev value)

- "Vedal Avatar Explanation" — non-dev (VTuber avatar topic).
- "ChatGPT Plus Features" — product Q&A, nothing project-related.
- "Coding Agent Prompt" — 438 bytes; prompt bodies omitted from export.
