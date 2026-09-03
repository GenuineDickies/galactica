# Design Notes — Bin design-3 (UI/Design Domain)

Distilled from 26 exported conversations (ChatGPT / Claude / Gemini). Focus: lasting design decisions for the WKR dispatch-to-cash admin app (dark-themed UI), plus branding/art direction.

---

## 1. Core Visual Identity: Dark "Command Center" Aesthetic

**Source: "Dark UI for admin", "Roadside assistant admin platform design", "Roadside assistance admin application design"**

- The settled direction is a **dark command-center aesthetic with high-visibility signal colors** — chosen explicitly because the operator needs to "scan status at a glance." Described elsewhere as "dark executive dashboard, neon glass, expensive dispatch-console energy — not 'kid gamer RGB keyboard threw up on Bootstrap.'"
- The earliest ChatGPT mockup ("Indie Roadside Admin — Neon Depth") established recurring elements Jason kept returning to:
  - Neon accent color options: purple `#7c3aed`, electric blue `#00d9ff`, neon green `#21fa90`, hot pink `#ff4d9d` (blue/purple became the brand vibe)
  - Depth via glow, glass, and "push-button" effects; sidebar of **buttons, not links**, with off-center dropdown sub-buttons and independent scrolling
  - User-adjustable accent color, neon intensity slider, and density (padding) slider
  - Right-side job-details drawer opened by clicking a table row
- CSS variables control the entire theme so reskinning / adding light mode is a one-layer change. Every nav item carries a `data-page` attribute for future routing.
- Monospace font on every ID, VIN, plate, phone, currency, and ETA value.

### The brand-color ruling (important, explicitly resolved)
- **Navy blue is the brand/chrome color** (Jason's explicit request: "I want a navy blue to be the brand color"). Navy is used for: buttons, active nav + left indicator bar, brand icon, focus rings, job-ID text, panel action links, toggles, tab highlights, active filter chips.
- **Signal colors are reserved for status meaning only**: amber = en-route / low-stock warnings, green = completed/available, red = errors/overdue, blue/cyan = dispatched, purple = SMS/AI features.
- Codified rule for coding agents: **"Never use navy for status indicators. Never use signal colors for buttons, nav, or chrome."** A later session caught a contradiction (a build plan had reverted to amber-as-brand) and re-affirmed navy as the disciplined, most-recent explicit ruling — if amber were both the primary button and the "en-route" status, color would stop carrying meaning.
- Navy token scale exists: `--navy-700` through `--navy-300` plus `--navy-glow`.

---

## 2. Dopamine-Inducing UI (ideas Jason liked)

**Source: "Dopamine inducing UI design", "Dopamine inducing UI" (prototype)**

Principles adopted (anticipation + reward, not constant overstimulation):
- **Micro-rewards**: subtle success animations on creating a service request; checkmark morphs; confetti bursts on completion.
- **Variable rewards** (slot-machine effect): rotate different success banners/tips after task completion.
- **Progress feedback**: animated status timeline "Request Received → Driver Assigned → On-Site → Completed" with neon pulses at each milestone; the current stage pulses.
- **Anticipation-driven loading**: hinted-reward loading copy ("Almost there! Prepping your invoice…") beats plain spinners.
- **Choice architecture / progressive disclosure**: 3–4 obvious action buttons instead of long forms; hide tax fields behind "Advanced Options."
- **Gamification**: streak badges ("5-Star Service Streak"), daily job counters, animated earnings tracker with a subtle green glow when money goes up, milestone confetti.
- **Instant feedback**: "Driver Assigned" pops immediately with a neon glow.
- Visual triggers: high-contrast CTAs as recognizable "hotspots," pulsing gradients on primary buttons, glow-on-hover rings around primary CTAs, optional success tones (C-major chords, à la Uber/CashApp).
- Prototype stack that demonstrated it: React + Tailwind + Framer Motion + Lucide icons; shimmer sweeps, particle "burst" on button press, "Tiny Wins" checklist cards.

---

## 3. UI/UX Guidelines for Data-Heavy Apps (the master ruleset)

**Source: "UI/UX Guidelines for Data-Heavy Apps"** — later reformatted as a directional prompt for coding agents. The most complete design doctrine in the bin. Key rules:

1. **Start with the user's job, not the data** — every screen answers "what needs attention, what's next?" not "here are all the fields."
2. **Strong hierarchy**: object identity → status → primary actions → key summary → details. Everything the same weight forces reading instead of scanning.
3. **Build screens in zones**: header (identity/status/actions), summary cards, main work zone, side/support zone (timeline, notes, attachments), footer/action zone.
4. **One primary next action per screen** (Intake → "Convert to Service Request"; Invoice → "Take Payment"). One primary button per decision area.
5. **Group related data aggressively** (Customer / Vehicle / Money blocks) — users should never mentally reorganize the screen.
6. **Progressive disclosure**: tabs, accordions, expandable rows, drill-downs. Timeline shows major events; expand for device ID/IP/GPS-level chargeback evidence.
7. **Tables are work queues, not database dumps.** Include a **"Next Action" column**. Sticky headers, filters, sort, status badges, bulk actions, no mystery icons.
8. **Status badges everywhere** — short, consistent colors, near the object title, same labels everywhere, no vague "Active."
9. **Forms follow the real-world call sequence** (name/phone → service → location → vehicle → pricing → notes), not database-table order.
10. **Reduce cognitive load**: repeat object identity at top; show linked document chain (SR → EST → WOR → INV numbers) explicitly.
11. **Contrast and spacing over decoration.** Glow is for hierarchy only — active nav, primary action, critical status, focused input, important alert. Glow on everything = noise.
12. **Grid discipline**: left-align data, right-align money, consistent padding/gaps, no random centering.
13. **Blockers impossible to miss**, placed near the affected section and the action button; each explains what's wrong, why it matters, how to fix.
14. **Summary before details** (invoice totals block above line items).
15. **Empty states teach the workflow** ("No invoices yet. Create one after the service report is complete. [Create Invoice]").
16. **Navigation ≠ actions** — sidebar is stable navigation; actions live in the page header/action area.
17. **Reuse component patterns** relentlessly (same badge, card, modal, money/date formats).
18. Dashboard / list / detail pages each have one job; don't make every page do everything.
19. **Design for speed under pressure**: big targets, input masks, dropdowns, defaults, minimal typing — used on the phone, in a truck, with a customer waiting.
20. **Five-second test**: user can tell what record, what status, key facts, what needs attention, what's next — or the layout is too noisy or too flat.

---

## 4. Platform Shell & Navigation Architecture

**Source: "Roadside assistant admin platform design", "Roadside assistance admin application design"**

- Shell: collapsible sidebar + 56px header (global search with **⌘K**, breadcrumb, notifications, quick-action button) + routed content area. Responsive: sidebar collapses; grids drop from 4-col to 1-col.
- Nav sections evolved to: **Operations, Fleet, Business, Catalog, Financial, System** (Catalog split into Service Catalog vs Parts Inventory at Jason's request; Financial = Accounting, Payments, Payroll, Reports; System = Users & Roles, Settings, AI Customizer).
- **⌘K command palette as the signature element** — navigation AND actions ("New Service Request," "dispatch nearest tech"), plus jump-to job/customer/VIN/plate.
- Dispatch board: **status drives each row's left-border accent color**; unassigned/overdue jobs **pulse red** (with `prefers-reduced-motion` respected).
- Six-document chain rendered as a live breadcrumb on job detail: SR → Estimate → Work Order → Change Order → Invoice → Receipt, with done/active/pending states.
- Component library built into the shell: form elements, modals with overlay animation, button variants (danger/small/ghost), table toolbars with filter chips, tab bars, empty states, ~14+ status chip variants (completed, cancelled, overdue, paid, draft, sent, approved, declined, low-stock, out-of-stock, etc.).
- **A DESIGN-GUIDE.md is the design source of truth for coding agents**: philosophy + "why," full token reference, layout diagram, component HTML patterns, module specs, add-a-module steps, explicit "do not" rules. The single-file vanilla prototype is the design source the React + Vite + TS production build ports from.
- Novel idea Jason initiated: an **AI Customizer module** — an in-app chat panel (purple accent to separate it from operational UI) that embeds the entire design guide in its system prompt and outputs structured, copy-pasteable change instructions (Summary → Scope → Steps → CSS → HTML → JS) for coding agents.

---

## 5. Form Design Conclusions

**Source: "Roadside assistance service request form design" (Claude), "Roadside Assistance Form Design" (Gemini x2), "Dark-Themed Web Form Template" (Gemini)**

- Dispatcher intake form spec: **47 fields across 7 sections**; conditional field logic (9 trigger conditions); validate **on blur, not per keystroke**; responsive breakpoints 1200px (3-col) → 768px (2-col) → mobile single stack with tab navigation.
- Dispatcher efficiency features: auto-complete, returning-customer typeahead, **shorthand parser ("js" → Jump Start)**, saved templates, and a "Rapid Dispatch" bypass mode for emergencies.
- **Location permission UX (3-state widget)**: (1) Default — big "Use My Current Location" button OR manual address field; (2) Permission Denied — inline help state with "Show Me How" browser-settings walkthrough and the manual field highlighted red as the required path; (3) Confirmed — map with draggable pin, auto-filled reverse-geocoded address, plus an "additional details" field ("in front of the red diner, mile marker 42"). Never dead-end on denied permission.
- Service-type selection as **large, easy-to-tap icon buttons** (towing, flat, jump, lockout, fuel) — mobile-first for stranded users.
- Baseline dark form recipe (Gemini template): very dark grey page (`#1a1a1a`), slightly lighter form container (`#2c2c2c`), light text (`#f0f0f0`), rounded container with subtle depth shadow, max-width ~600px, generous field spacing.
- Earlier style-guide notes captured the liked interaction language: **push-down button animation, sidebar lighting, themed scrollbars, phone-number masks, glowing focus states**.

---

## 6. Build/Design Process & Agent-Facing Documentation

**Source: "Document creation guide", "Roadside assistant admin application frontend workflow", "Mockups as PDF Inspiration", "UI/UX Polish Prompt", "App Build Plan Guide"**

- Standard doc set for the project: AGENTS.md, agent-rules/ (modular short rule files with scope/rules/examples/rationale), project-brief.md (branding: dark theme, neon blue accents, shield motif), architecture.md, **style-guide.md** (global theme, 3-panel layout, typography with glow, button/nav behavior, scrollbars, input masks, responsive rules), naming-conventions.md, requirements.md, traceability-matrix.md, runbook.md, entry-summary.md.
- Three-document build stack: **Master Prompt** (what to build — ~27 sections with ASCII wireframes per module), **Build Roadmap** (20 ordered sessions with dependency graph and acceptance criteria), **Build Addendum** (auth/RBAC, error playbook for Square/Telnyx failures, validation rules, concurrency, offline banner, keyboard shortcuts incl. two-key "G then D" sequences, notification system, print styles for work orders/receipts, WCAG 2.1 AA specifics, performance budget, agent prompting guide + 8 agent anti-patterns + recovery procedures).
- **Polish rule**: "Do not make one pretty dashboard while leaving the rest of the app looking abandoned. Build the style system first, then apply it consistently."
- **Mockups-as-inspiration rule** (PDF documents): the /mockups folder is inspiration only, never source of truth. Every PDF field must map to real app data; unmappable visual elements are omitted or become a named TODO. "Do not fake it just to make the PDF look complete." No hardcoded fake data, no guessed financial values, no implied approval without approval data. Templates hold no business logic; view-models + data maps per document.
- Easter eggs deliberately spec'd: Konami code "MEEP MEEP" mode (roadrunner puns in status chips), secret "stats" command in global search, milestone confetti at 100/500/1000 jobs, rotating dispatch-board empty-state flavor text, roadrunner-personality 404 page, first-time welcome tour. (Note: this era used a "RoadRunner Admin" working name.)

---

## 7. Data-Model Decisions that Shape the UI

**Source: "Logic design document integration", "Database design challenge"** (logic-heavy, but with direct UI consequences)

- Philosophy shift: the CRM is built to **survive bad data, not prevent it**. UI consequences:
  - **Data-confidence badges** (Verified / Unconfirmed / Flagged) shown on records; auto-communications never fire silently to unconfirmed numbers.
  - **Lookup-first must be automatic** (inbound phone match surfaced before the dispatcher can create) or it will be skipped under call pressure.
  - **Duplicate detection runs in background and surfaces proactively** ("2 possible duplicates") — never a user-initiated merge tool alone.
  - Progressive enrichment writes require a **one-tap confirm**, not auto-save.
  - **Nothing blocks dispatch except a missing requesting contact**; brokerage "contact pending" state gets a visible timer escalation, not a quiet flag.
  - Jobs store **references, not copied data** (enter once, use everywhere); jobs carry three independent parties: requesting contact / onsite contact / billing party. Brokerage onsite contacts never see pricing or terms.
  - Anchor on a **party record** (person/company/brokerage) with contextual roles per job, not fixed "customer types."
- Schema-to-UI decisions baked into forms: VIN and the Vehicle record are NOT created at intake; vehicle fields live inline on the Service Request; VIN captured on scene; Vehicle row created at Invoice. Ticket ID format RR-YYYYMMDD-XXXX; SR IDs like SER-20260512-001-V1.

---

## 8. Branding, Imagery & Art Style

- **"Image creation for website"**: settled website/brand imagery direction — dark, futuristic, premium/heroic: glowing **white knight chess piece** (later variant: knight helmet) on dark brick background, blue + white neon lighting, "WHITE KNIGHT" in bright blue neon with "ROADSIDE" in clean white neon below; **wrench crossed behind the knight** (or roadside shield outline) so it reads mechanic/roadside at a glance. Hero-banner variant: charcoal black with faint gradient, subtle circuit lines, faint neon-blue tow-truck silhouette. Candidate add-ons discussed: tow truck silhouette, tire, jumper clamps, chrome/armor 3D treatment.
- **"Create knight image"**: personal art exploration — an ultra-defensive knight braced behind a massive shield, eyes glowing demonically over the rim, barbed spikes on armor. Gore/skull additions were refused by the generator; acceptable menace substitutes: broken weapons, cracked helmets, chains/hooks, torn banners, glowing runes. (The "shield-knight" identity recurs as a brand metaphor.)
- **"Unique Art Style Request"**: one-off experiment asking for a never-before-seen art style; honest refusal to guarantee originality. Side value: a list of high-leverage meta-questions ("What am I overbuilding?", "What is the next concrete move?") — not design content.

---

## 9. Minor / Non-Design (one-liners)

- **"Tailwind CSS Error in Vite"** — debugging a Tailwind v4 `Missing "./base" specifier` error in the business_manager Vite project; no lasting design value.
- **"CSS rewrite neon style"** — existing CSS/JS rewritten to the preferred neon blue/purple dark theme (glow, button-first nav, themed scrollbars, subtle push active states) while keeping class names/endpoints drop-in compatible; confirms the persistent style vocabulary.
- **"App Build Plan Guide"** — build-order copilot chat; design-relevant bits: stack is plain PHP 8.2 + MySQL + custom CSS ("expensive glass/metal UI," no framework circus), build the business spine (Intake → SR → Estimate → WO → Invoice → Payment → Receipt) first.
- **"Auto Parts Markup Guide"** — parts pricing matrix discussion (business, not design).
- **"Model Inquiry"** — personal chat; the AI's read: Jason is building a defensible business operating system, thinks like a field operator not a desk developer, wants UIs that survive real-world chaos.
- **"Personality type inquiry"** — personal chat (INFJ self-identification); no design content.
