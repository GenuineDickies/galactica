# Design Notes — Bin design-1 (UI/Design Domain)

Distilled from 4 exported conversations: "Dark themed UI design", "Mind blowing interface design", "PRD for app design", "Sitemap for web application". All concern the WKR dispatch-to-cash admin app (earlier working name in these chats: **"Indie Roadside"** / "Indie Roadside Admin / White Knight Roadside").

---

## 1. Core Visual Direction — Dark, Edgy, Tactile

**Source: "Dark themed UI design"**

Jason's founding design brief, stated in his own words:

> "dark themed, but kind of edgy... appearance of depth, using light and shadow to give the appearance of texture and raised buttons or workspaces. I like bright accent colors, backlights and transparency."

This is the definitive aesthetic statement for the app. The concrete design language that came out of the iterations:

- **Dark base with neon accents.** Deep slate/near-black backgrounds with a dynamic accent color driven by a CSS variable (`--accent-rgb`). The accent palette explored: **Electric Cyan (0,219,255), Neon Purple (165,92,255), Hot Magenta (255,0,149), Lime Beam (172,255,47), Amber Arc (255,191,0), Plasma Blue (64,160,255)**. When offered defaults, the direction leaned toward **neon purple/blue** as brand accents.
- **Depth as a first-class feature.** Raised panels built from layered shadow stacks: inset top highlight (bevel edge line), heavy drop shadows (`0 22px 48px rgba(0,0,0,~0.7-0.9)`), plus an accent-colored glow ("backlight aura" — a radial gradient blur behind each card). Bottom inner shadow gradient for weight. A "Depth" slider was even prototyped so surface elevation intensity could be tuned globally — depth is a parameterized system value, not a fixed style.
- **Glassmorphism elements.** Semi-transparent panels (`bg-white/5`, `backdrop-blur-md`), thin `border-white/10` edges, glassy chips with inner white highlight plus accent glow ("NeonChip" pattern).
- **Skeuomorphic-lite buttons.** Buttons should read as physically raised and pressable — pressed states, bevels, glow on hover.
- **Rounded geometry** (rounded-2xl cards) in the primary direction; a squared/hard-edge variant was explored separately (see §2).

### Sidebar navigation — hard requirement
Jason pushed back twice until it was right: the app **must have a left sidebar navigation, always visible (no responsive hiding)**. The settled pattern:
- Left sidebar with **grouped navigation** (e.g., "Operations" and "Accounting" groups), collapsible groups, bevel/glow styling on items.
- A **collapse toggle** in the header; option to lock the panel open on desktop with auto-drawer on mobile was floated.
- An earlier "dual sidebar" (icon rail + expanded panel) was iterated through; the final ask was simply: sidebar always there.

### Comprehensive UI kit
The conversation ended with Jason asking for "a comprehensive set of UI elements," producing a full dark/neon component kit — a useful inventory of what the design system should cover: buttons (primary/secondary/ghost/destructive), badges, icon buttons; inputs (text, select, textarea, prefix/suffix, date/time, file upload); controls (switch, checkbox, radio, slider, tags); navigation (tabs, breadcrumbs, pagination, dropdown menu, avatars); data display (tables with row actions, progress, skeletons); overlays (modal, drawer/sheet, tooltip, toasts) — all in the neon/glass depth style with backlights.

**Tech context of the prototypes:** React + TailwindCSS + Framer Motion + lucide-react icons.

---

## 2. Style Variants and Interaction Ideas Explored

**Source: "Mind blowing interface design"**

This chat generated multiple named style variants and interaction concepts on top of the same app (sidebar rail + cascading sub-buttons + invoice workspace). Useful as a catalog of directions and reusable ideas:

### Named variants produced
1. **Neon Dark ("mind-blow mode")** — glowing push-button sidebar with cascading sub-buttons, silky Framer Motion animation, themed scrollbars, live invoice workspace. Consistent with the core direction in §1.
2. **Stealth Matte (Amber)** — deliberate counterpoint: square geometry, beveled/pressed states (skeuomorphic-lite), amber accent palette (no pink/purple), low-glow emboss, diagonal headers, hard edges. Same flow, different vibe. Not adopted as primary but shows Jason was open to a matte/industrial alternative.
3. **ZenFlow ("addictive delight")** — response to Jason asking for a UI "so enjoyable to use, I won't want to use any other or even turn it off." Features: magnetic buttons (hover pull/tilt toward cursor), **⌘K / Ctrl+K command palette**, "Flow Mode" progress ring, micro-affirmations (confetti pulses, feel-good toasts), plush glass panels, **big touch targets** (glove-friendly sizing was explicitly raised — relevant for a roadside tech in the field), buttery easing.
4. **OrbitDesk ("dream UI", AI's free choice)** — zero-dependency HTML/CSS/JS build. Key patterns: command palette, **right-click radial quick menu at cursor**, bottom **dock** of essentials, tabbed workspaces (Inbox / Builder / Notes), instant toast feedback on every action ("every action feels alive"). Palette tokens from this build: `--bg:#070b12; --panel:#0b1220; --edge:#0f2036; --ink:#e5e7eb; --muted:#9fb0c7; --teal:#22d3ee; --vio:#a78bfa; --good:#34d399; --warn:#f59e0b`. (ZenFlow's near-identical token set: `--bg:#070b12; --panel:#0a0f18; --edge:#122031; --ink:#cbd5e1; --muted:#94a3b8; --cyan:#38bdf8; --vio:#a855f7; --good:#34d399`.) These are the most concrete dark-palette token values in the archive and match the §1 direction (near-black blue base, cyan + violet accents, emerald for success, amber for warning).

### Interaction patterns worth keeping
- Command palette (⌘K) for "instant anything"
- Radial context menu on right-click
- Toast confirmations on every meaningful action
- Cascading sub-button navigation from sidebar items
- Progress/"flow" indicators as gentle motivation

### Practical lesson learned
React canvas previews with shadcn imports repeatedly failed to render for Jason ("I can't see anything", "no dice"). The fix that worked: **zero-dependency plain HTML/CSS/JS mockups**. When producing UI mockups for Jason, self-contained no-deps HTML is the reliable delivery format.

### Tangent (skip-worthy)
The second half of the chat was a recreational "interface of the year 2045 / 2075 / 2300 / 3000..." series (HaloUI, SynapseUI, AeonUI, ChronoForge, ArchetypeUI). Entertaining speculative builds; no lasting product decisions. Only marginal takeaway: Jason enjoys ambient depth/parallax effects and anticipatory/copilot concepts, but none were adopted.

---

## 3. PRD Requirements Affecting Design

**Source: "PRD for app design"** — Jason iterated a PRD for the "White Knight Roadside Assistance App" with a junior-developer audience, then pasted back his consolidated final version. Key design-relevant requirements:

### Data-entry rules (settled decisions, stated explicitly by Jason)
- **VIN is NOT required for invoice creation. License Plate + State of issuance IS required.** Plate+State must be captured before a job can be completed or invoiced.
- **Phone numbers: `(xxx) xxx-xxxx` format, with the parentheses and dash rendered as fixed, non-removable placeholders in the input field** (input mask, not just validation).
- **Vehicle make, model, year, and color are recorded at time of service request.**
- **Customer dedup on SR creation:** creating a service request checks entered customer data against stored records; a new customer record is created **only if no match is found**.
- **Vehicle record creation is deferred** until License Plate + State are entered; the **Plate+State combination must be unique** to create a new Vehicle record.
- **Service records attach to both the customer and the vehicle** (single record, dual linkage).

### Structure from the final PRD
- **Roles:** Admin (full control), Dispatch (SRs, invoices, payments), Driver/Technician (assigned jobs, vehicle capture, completion; cannot generate invoices), Customer (request service, pay invoices). Each role gets its own dashboard/portal view. *(Note: the later sitemap chat pivots to a single-user owner design — see §5.)*
- **Catalog system:** all services/products selected from a predefined catalog; line items on SRs/invoices are edited by selecting catalog items (catalog is the only source for line items).
- **Invoicing:** multi-line-item invoices, payment tracking, accounting module (revenue, expenses, reports).
- **Warranty tracking** for installed parts (expiration dates, service history).
- **Tech stack in PRD:** HTML5/CSS3 (Tailwind or Bootstrap), JS (React or Vue), **PHP 8.2 + MySQL 8 (PDO, MVC)**, session or JWT auth, Stripe/PayPal payments.
- **Non-functional:** responsive, <2s load, encryption in transit and at rest, role-based access control, scalable without redesign.
- **MVP scope:** auth/roles, service requests + catalog, invoicing + payments, vehicle info (plate+state mandatory), customer/vehicle validation. **Phase 2:** warranty tracking, advanced reporting, mobile support.
- **Acceptance criteria** restate the data rules above verbatim — they are the load-bearing rules of the product.

---

## 4. Sitemap and Navigation Structure

**Source: "Sitemap for web application"** — the most complete information-architecture artifact. Built as an **admin app for a single-user owner** ("Owner sign-in (single-user)"; the Users module is replaced by `/settings/user` owner profile).

### Workflow spine
**Intake → Service Request → Estimate → Work Order → Invoice → Payment → Receipt**, all nested under the SR as a "job workspace." Intake is a distinct pre-SR lead stage with a conversion wizard (match/create customer → capture vehicle basics → create official SR).

### Full route map (final, converted to markdown at Jason's request)
- **System:** `/login`, `/logout`, `/lock` (quick re-auth lock screen)
- **Dashboard:** `/dashboard` (daily command center: active jobs, unpaid invoices, quick actions, recent activity), `/dashboard/quick-actions` ("do-it-now" launcher: new intake, new SR, take payment, record expense, create PO)
- **Intake:** `/intake`, `/intake/new`, `/intake/{intakeId}`, `/intake/{intakeId}/convert-to-service-request`
- **Service Requests:** `/service-requests` (filters: Active / On-Hold / Completed / Invoiced / Paid), `/service-requests/new` (wizard: customer match/create + vehicle basics + plate/state + **no-plate flag** + optional VIN lookup via plate+state), then per-SR sub-pages: `/{srId}`, `/{srId}/estimate`, `/{srId}/work-order`, `/{srId}/invoice`, `/{srId}/payment`, `/{srId}/receipt`
- **Customers:** `/customers` (search by name/phone), `/customers/new` (first/last separated, phone format enforced), `/customers/{customerId}`, `/customers/{customerId}/service-history`
- **Vehicles:** `/vehicles` (search by VIN or plate+state), `/vehicles/new`, `/vehicles/{vehicleId}`, `/vehicles/{vehicleId}/service-history`
- **Catalog:** `/catalog`, `/catalog/services`, `/catalog/products`, `/catalog/{itemId}` (pricing, taxable flag, SKU, defaults)
- **Inventory:** `/inventory`, `/inventory/transactions` (receiving, usage on jobs, adjustments, audit trail)
- **Warranties:** `/warranties`, `/warranties/{warrantyId}`
- **Purchase Orders:** `/purchase-orders`, `/purchase-orders/new`, `/purchase-orders/{poId}` (line items, receiving status)
- **Expenses:** `/expenses`, `/expenses/new` (receipt upload), `/expenses/{expenseId}`
- **Reports:** `/reports` hub, `/reports/revenue`, `/reports/expenses`, `/reports/profit-loss`, `/reports/unpaid` (aging-style), `/reports/tax-summary`
- **Documents:** `/documents` (generated estimates/invoices/receipts library)
- **Templates:** `/templates`, `/templates/estimate`, `/templates/invoice`, `/templates/receipt`, `/templates/terms-disclaimers` (central legal/terms text blocks reused across documents)
- **Settings:** `/settings`, `/settings/user` (owner profile), `/settings/business` (identity/logo/branding), `/settings/rates` (labor rate, trip/dispatch fees, mileage, minimum charges), `/settings/taxes`, `/settings/payments`, `/settings/signatures`, `/settings/notifications`
- **Utilities:** `/search` (global search across customers/vehicles/jobs/invoices), `/activity` (audit feed — "still useful even single-user"), `/import-export` (CSV export, backups)

### Signature rules (design requirement embedded in sitemap)
- **Estimate:** signature capture gate **if estimate > $200**.
- **Estimate → Invoice:** authorization signature required **if the delta exceeds > $200 or > 10%, whichever is smaller**. Thresholds are configurable at `/settings/signatures`.

### Global navigation
Every authenticated page carries a global sidebar/header nav: Dashboard, Intake, Service Requests, Customers, Vehicles, Catalog, Purchase Orders, Expenses, Reports, Documents, Templates, Settings, Search, Activity, Import/Export, Lock, Logout. A full **per-page "navigation away" map** (each page's buttons/links and their target routes, e.g., dashboard rows deep-link into `/service-requests/{srId}` and `/{srId}/invoice`) was produced — a ready spec for wiring links.

### Architecture notes
- Routes with IDs (`/customers/{customerId}`) are **virtual** — one `view.php` per entity, no per-record folders. Jason confirmed understanding and this file-per-page + virtual-route model is the settled structure (`app/pages/<module>/<page>.php`).
- Stack for scaffold: HTML, CSS, PHP, JavaScript (matches PRD's PHP 8.2/MySQL choice).

---

## 5. Cross-Conversation Conflicts To Resolve

1. **VIN rule conflict.** Sitemap chat: `/vehicles/new` says "VIN required to create the vehicle record, per your rule set," while SR creation treats VIN as an optional lookup. PRD chat (explicit correction from Jason): "A VIN is not required for invoice creation, a License Plate with state issuance is," and vehicle records are created from unique Plate+State. **The PRD's plate+state rule is Jason's explicit, corrective statement and should be treated as authoritative**; the VIN-required note in the vehicle page description appears to be a leftover from an earlier ruleset.
2. **Single-user vs. multi-role.** The PRD defines four roles (Admin/Dispatch/Driver/Customer) plus a public customer portal; the sitemap is explicitly single-user owner (login is "Owner sign-in (single-user)", Users module removed). These are different scoping snapshots — the sitemap represents the leaner admin-app scope, while the PRD's role model represents the fuller ambition (its own text describes "scalability as the business grows").

---

## 6. Working Preferences (How Jason Wants Design Work Delivered)

- **Deliverables must match the agreed spec exactly.** When a scaffold only partially matched the sitemap he reacted strongly ("why would I want a partial? ... can you make one that matches"). Never deliver partial structures against an agreed sitemap.
- Prefers **plain mock file trees** ("just the file names in a mock file structure") with **per-page function descriptions** and **routes** alongside — then converted to **markdown files** for keeping.
- Prototype mockups should be **zero-dependency HTML/CSS/JS** (React/shadcn canvas previews failed repeatedly for him).
- Iterates by short corrective instructions; explicit statements like the plate/phone-format rules are firm decisions, not suggestions.
