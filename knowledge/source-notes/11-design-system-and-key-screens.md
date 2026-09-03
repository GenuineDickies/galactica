# Design Notes — Bin design-2 (UI/Design Domain)

Distilled from 13 exported conversations. Ten are design-relevant; three are unrelated personal chats (noted at bottom). App names seen across these chats: **Indie Roadside Admin** (with "Indie Omni" branding variants), **RoadRunner Admin** (earlier name), and **White Knight Roadside / WKR** (current). Treat the design decisions as one evolving body of work for the WKR dispatch-to-cash admin app.

---

## 1. Aesthetic Direction (converged across all chats)

The consistent, repeatedly-affirmed visual identity:

- **Dark theme with neon blue/purple accents.** Locked in as early as the v0.9 design document ("Theme: Dark UI with neon blue/purple accents") and carried through every subsequent mockup. *(App design document creation; Frontend design for MVP)*
- **Button-based navigation — no anchor-link nav.** All navigation elements are buttons with **push/active lighting effects**. Accordions expand to reveal **off-center child buttons that push content downward**. This "offset cascading sub-button" pattern appears in at least four separate chats and is clearly a signature interaction Jason wants. *(App design document creation; Button dropdown mockup; UI elements with depth; Sidebar design code)*
- **Sleek, futuristic, yet tactile** — UI elements should have the appearance/illusion of depth. *(UI elements with depth)*
- **Icons: shield/helmet motif, minimal lines, glowing eye slits** — knight branding carried into iconography. *(App design document creation)*
- **Taste guardrails learned from rejections:** Jason rejected outputs that looked "cheap" — emoji icons, loud gradients, "gamer glow." The accepted direction is **enterprise-clean**: monoline SVG icons with consistent stroke, glow **only on interaction** (hover/active), one consistent glow language across rail/icon/border, restrained high-contrast look. "Premium, not noisy." *(Sidebar design code — "not professional enough and the icons look cheap")*

### Dark Command Center palette (most concrete spec)
From the Claude form-design spec — the fullest color/typography definition on record:

- Background `#0a0e1a` (deep navy-black); panels `#151b2d`; borders `#1e2a42`
- Text primary `#e4e8f0`; text secondary/labels `#8b94a8`
- Accents: Emergency Red `#ff4757`, Urgent Orange `#ffa502`, Warning Yellow `#ffd32a`, Success Green `#2ed573`, Info Blue `#1e90ff`
- Typography: **Inter** (headers 600/16–20px, labels 500/13px, input text 400/14px); **JetBrains Mono** 13px for IDs and VINs
- Inputs: bg `#1a2137`; border `#2a3550` default → `#3b4a6b` hover → 2px `#1e90ff` focus; radius 6px; padding 10px 12px; `transition: all 0.2s ease`
*(Roadside assistance form design prompt)*

A separate mockup used `--bg:#0a0e13` / `#0b0f14` — same deep navy-black family. *(Button dropdown mockup)*

---

## 2. Sidebar & Navigation

### Premium sidebar v2 (accepted direction) — *Sidebar design code*
After rejecting v1 (emoji icons, too flashy), the accepted "wk-" prefixed sidebar:

- **Brand block:** "WK" mark tile + "White Knight Roadside / Admin" text
- **Collapsible** (expanded / collapsed at `--sidebar-collapsed-w: 84px`), icon tiles keep the look strong when collapsed; text and section labels hide on collapse
- **Slim active rail** (`::before` accent bar) + subtle `--glow-active` box-shadow; icon glow only on hover/active
- **CSS-variable driven theme** with glow intensity presets (subtle ~0.18 alpha vs louder ~0.30) so intensity can be tuned in one place
- **Section grouping:** *Overview* (Dashboard) → *Operations* (Service Requests, Customers, Vehicles, Catalog, Inventory & Warranty) → *Accounting* (Invoices, Payments, Expenses, Reports) → admin items (Users, Settings)
- Design rationale stated: "Operations first, Accounting second, Admin last — hierarchy that reads instantly"
- Requested as a **single-file** drop-in; future option: PHP partial with `$activeRoute` helper

### Sidebar navigation structure (design doc v0.9) — *App design document creation*
Dashboard / Service Requests / Work Orders / Invoices / Payments / Products & Services / Customers / Vehicles / Reports / Users (Admin only) / Settings (tax, disclaimers, provider configs).

### Buttons-only full nav mockup — *Button dropdown mockup*
Tailored "Indie Roadside Admin" navigation, buttons-only with offset cascading sub-buttons, smooth animation, arrow rotation, keyboard support (↑/↓ move, → open, ← close), one-menu-open-at-a-time, themed scrollbar:

- **Top bar quick actions:** New Service Request, Clock In, Go Available
- **Sidebar modules:** Jobs/Dispatch; Customers & Vehicles (VIN/Plate flows); Catalog; Accounting; Providers (with Volume Pricing Matrix); Admin
- Noted future options: role-based visibility (Admin/Dispatch/Driver/Customer)

### Depth UI kit — *UI elements with depth*
"Neon Depth UI Kit v2" (v1 rejected as not deep/edgy enough): sidebar-first with off-center dropdown buttons pushing content down; bevel/extrusion surface variants (**Hard, Soft, Glass, Carbon**); tactile controls with pressed states, **rim-light and underglow**; true 3-panel layout with independently scrolling sidebar/content. Conversation ended without explicit approval of v2 — treat as a direction library, not a final decision.

---

## 3. Splashscreen & Branding — *Create splashscreen image*

A **7-second splash screen** built around the knight logo image:

- Auto-fades after 7s with a **neon progress bar**, then reveals the app shell
- **Skip:** "Skip" button or `Esc`; `?skipSplash=1` URL param bypasses entirely
- **Shows once per session** (`sessionStorage` flag)
- **Restart controls** (added later): "Restart" pill, `Shift+R`, `window.restartSplash()`, or `?forceSplash=1` (force clears the session flag); suggestion to gate the restart control behind `?dev=1` or localhost in production
- Practical gotcha encountered: logo not visible due to **Windows backslash paths in `img src`** — needed `logo.src.replace(/\\/g, '/')`; a test panel (`?test=1`) was added for debugging

---

## 4. Design System CSS — *Improve Design System CSS (Gemini)*

Historical/early artifact: the original "WKR MVP Design System" stylesheet was a **light-theme, Bootstrap-flavored token set** (`--primary: #4a90e2`, `--light: #f8f9fa`, Segoe UI, standard success/danger/warning/info colors, spacing scale xs–xxl, radius sm/base/lg, three shadow levels). Gemini's "improvement" mostly swapped in Montserrat and minor tweaks; **Jason's verdict: "this is horrible."** The token *structure* (colors / typography / spacing / borders / shadows in `:root`) is sound and worth keeping, but the light palette predates and conflicts with the dark-neon direction — superseded.

Useful side-product: a dashboard critique of the then-current admin screen — cramped layout, harsh blue gradient, basic font; recommendations to add KPI widgets (jobs today, revenue), search/filtering on job lists, richer recent-jobs details, ARIA/contrast accessibility.

---

## 5. Service Request Form — Full Design Spec — *Roadside assistance form design prompt (Claude)*

Two reusable artifacts here: (a) a **meta-prompt** for generating form design instructions (reusable pattern for other forms), and (b) the resulting full spec for the dispatcher intake form ("critical entry point for all service calls"). Highlights:

### Layout
**Three-column responsive layout:** Left 30% (Customer Info, Vehicle Info, Billing) | Middle 45% (Location & Service, Issue Description, Priority/Timing, Special Requirements) | Right 25% (**Live Summary Card**: customer, vehicle, location map, service type, est. cost, ETA promise + Quick Actions: Assign Tech, Send SMS, Print Ticket). Header: ticket # (auto) + Save Draft. Footer: Cancel / Save Draft / **Create Ticket & Notify** (primary). Mobile <768px: single column with sticky summary bar; sections default collapsed.

### Field structure (the tree design is reusable)
- **Customer:** search/auto-complete by phone, name, or ID → quick-create if not found (first/last, phone masked `(XXX) XXX-XXXX`, secondary phone, email, **Customer Type: Individual / Fleet / Commercial**) → read-only selected-customer summary with edit button
- **Vehicle:** VIN lookup (17 chars, auto-populate if in system), make/model/year (dropdown-hybrid), color, plate + state, odometer, fuel type
- **Location:** GPS auto-capture, address autocomplete, intersection/landmarks, location notes ("red building on corner"); conditional destination block when service = towing
- **Special requirements:** vehicle accessibility checkboxes (keys locked inside, parking garage, off-road, needs winch); customer needs (ride/shuttle, pet in vehicle, child seat, medical equipment); dispatcher-only internal notes; communication preferences (SMS default ON, 10DLC compliant)
- **Billing:** est. cost auto-calculated from catalog; quoted price; conditional Invoice-To (company + PO) for fleet/commercial

### UX rules worth reusing everywhere
- **Validate on blur, not per keystroke**; invalid = red border + icon + inline message; valid = subtle green check
- VIN validation: 17 chars, no I/O/Q
- Section cards with **left border accent color-coded by urgency**; collapsible low-priority sections to reduce cognitive load
- **Smart defaults:** priority auto-set from description keywords ("accident" → EMERGENCY); ETA auto-calculated by priority (Emergency 30min / Urgent 60min / Standard 2hrs)
- Progressive disclosure via conditional rendering; autosave drafts; dispatcher efficiency features (recent customers, saved templates)

Note: spec targets "React-based RoadRunner Admin" — the React assumption conflicts with the PHP server-rendered stack chosen elsewhere; the design content transfers regardless.

---

## 6. Sitemap & Document Lifecycle — *App sitemap design*

Rebuilt around Jason's **exact six document types**: **Service Request → Service Order → Work Order → Invoice → Payment → Receipt** (his explicit correction: "remember, Service Request, Service Order, Work Order, Payment, Invoice and Receipt").

- **Public site:** `/`, `/services` (SEO pages per service), `/request-service`, `/invoice-lookup` (view/pay by invoice # + email/phone), `/contact`, `/terms`
- **Service Requests** (dispatch-owned intake): create/list/view/edit(Pending|Accepted only)/cancel/**convert → Service Order**. Statuses: PENDING, ACCEPTED, REJECTED, COMPLETED, CANCELLED
- **Service Orders** (internal contract): assign technicians, add catalog items, generate invoice. Statuses: PENDING, DISPATCHED, COMPLETED, CANCELLED, INVOICED
- **Work Orders** (technician-owned): perform, **VCR before/after images + waiver signatures**, parts scan → **auto-register warranties**, complete → pushes status back to Service Order
- **Invoices:** PDF-style view, send via email/SMS, editable until paid (line items lock after payment), refunds
- **Payments:** record (supports partials, overpayments), refund auto-linked to invoice, auto-updates A/R
- **Receipts:** auto-generated on payment; view/download/resend
- **Catalog** (single source of truth): unified services+parts list, pricing matrices (retail vs bulk, after-hours multipliers), warranty templates, tax flags. Line items on SO/Invoice **only** from catalog
- **Warranties:** auto-registration from work-order part installs, claims (photos, defect notes), vendor returns/RMA; reports for expiring warranties, claim cost impact, vendor failure rates
- **Accounting:** revenue by period broken down **bulk vs retail**, A/R aging, expenses with receipt attachments, reconcile vendor credits to expenses; reports: simple P&L, sales tax summaries, profitability per job/service type, bulk vs retail margin

### Complete forms inventory (21 forms, mapped to routes)
Six lifecycle forms (Request, Service Order, Work Order, Invoice, Payment, Receipt) + catalog forms (service item, part item, price matrix, warranty template) + warranty forms (auto-registration, claim, vendor RMA) + customer/vehicle forms + accounting forms (expense entry, refund, reconciliation) + compliance/media forms:
- **Waiver & Signature** embedded in Work Order (authorization, liability disclaimer, digital signature, auto-attached to job media)
- **VCR image intake** with auto-naming `JOB-YYYYMMDD-SEQ-TYPE.ext`, TYPE tags `VCR-PRE`, `VCR-POST`, `WAIVER`, `SIGN`, plus checksum
- **Bulk referral intake** (`/service-requests/create?source=bulk`): company, dispatch contact, external job/ref #, SLA windows, billing rules

**Conflict to resolve:** this sitemap makes vehicle uniqueness `(license_plate + state)` with VIN optional — contradicting the design doc's "vehicle creation requires VIN, VIN unique." The design doc + frontend MVP chats (VIN-gating) appear to be the later/controlling position.

---

## 7. Catalog Structure & UI — *Online Catalog Setup Guide*

Research-backed catalog design (Square catalog model, Google product data, ACES/PIES standards, NHTSA vPIC VIN decoding). Core principle: the catalog is a **service + parts + pricing + inventory + accounting control system**, not a menu.

- **Four sellable item types:** Service (revenue, no inventory), Part (inventory + parts revenue + COGS), Fee (callout/mileage/disposal/after-hours/cancellation), Supply/Consumable (usually not itemized to customer; COGS/expense). Plus **Kits as shortcuts, not fake inventory** (kit adds labor + battery + protectant + core deposit lines; the real battery still decrements stock)
- **Separate parent item from sellable variation:** "Battery" is the item; "Group 35 Battery" is the variation with its own code, cost, stock, GTIN/MPN, warranty months
- **Modifiers for job-time add-ons** (after-hours +$35, mileage $0.72/mi, fuel overage, extra labor, tire disposal $7, core deposit) — "don't make a separate service item for every tiny scenario"
- **Table design:** `catalog_categories` (nested, with default revenue/COGS accounts + taxable defaults), `catalog_items` (item_code, item_type, price_type flat/hourly/starting-at/estimate-required, `requires_vehicle`, `requires_vin_for_invoice`, `allow_ad_hoc_price`), `catalog_variations`, `catalog_modifiers`
- **Starter catalog with real WKR pricing:** Jump Start $85, Lockout $95, Tire Change $85, Plug $95, Patch $125, Mount & Balance from $150, Fuel Delivery $85 (2 gal gas / 5 gal diesel included), Commercial Diesel (estimate, up to 15 gal), Battery Test $85 (creditable), Diagnostic $85 (creditable), Mobile Mechanic $125/hr (1-hr min), Loose Wheel Service (no VIN required). Fees: Callout $65, Mileage $0.72/mi, Drive Time 65% of labor rate. Category tree: Roadside Services / Labor / Parts / Fuel / Consumables / Fees / Warranty & Documentation
- **Accounting behaviors:** core deposits are a **liability** (2050 Core Deposits Payable) until returned/forfeited, not revenue
- **Scope decision (Jason's call, confirmed):** **vehicle fitment is out of scope** — supplier responsibility. No ACES/PIES imports, no year/make/model compatibility tables, no "parts that fit this vehicle." Instead use a **"Part Application Record"**: capture only the part actually used/quoted per job (name, brand, MPN, vendor, vendor part #, cost, sale price, warranty, receipt attachment, vehicle used on, notes). "This part was used on this vehicle/job — not this part fits every matching vehicle forever."
- Output was consolidated into an MD file at Jason's request

---

## 8. Frontend MVP Package — *Frontend design for MVP*

Built against the "Full Development Guide (MVP + VIN Enforcement)" backend spec (PHP 8.2 / MySQL 8+ / MVC, front controller + .htaccess routing). The delivered dark-neon frontend package:

- **Layout convention:** `header.php` / `sidebar.php` / `footer.php`; controller sets `$page_title`; active nav via `$active` ∈ `dashboard|requests|catalog|customers|vehicles|accounting|users` and `<body data-current="...">`; detail pages add `data-has-vin="1"`
- **Button-based sidebar** (no links) with brand dot + "Roadside Admin", Operations/other nav groups; Bootstrap-assisted layout + custom neon CSS
- **VIN gating in the UI:** VIN optional at intake but mandatory to complete a request, create an invoice, or take payment. UI shows a **VIN-required banner + capture form** on request detail; **Complete / Create Invoice / Record Payment buttons disabled until VIN present**
- Requests list + detail with **always-visible Add Service / Add Item buttons** (catalog-first; tunable), line-item rows with simple JS math
- Views delivered: customers, vehicles (driver-editable), requests list/detail, catalog selection (services & items + custom item form), invoices + invoice detail, expenses (minimal)

---

## 9. Canonical App Design Document (v1.0) — *App design document creation*

The most complete single artifact: "Indie Roadside Admin — App Design Document," iterated live with Jason's corrections. UI-relevant content:

### Design language & component inventory (Section 8 + Appendix)
Dark + neon, button nav with push/active glow, accordion off-center child buttons. Component inventory: primary/accent/danger buttons with push glow; sidebar accordion; **Catalog Picker modal ("+") with search/filter and SKU view**; phone input fixed mask `(xxx) xxx-xxxx`; **status timeline component** (SR/WO/Invoice); **authorization widget** (signature pad + printed-name field + terms version + timestamp); totals card (Subtotal/Tax/Total); PDF badge/button; striped dark tables with selectable rows; alert/toast (V2).

### Business rules that drive UI (Jason's explicit corrections in this chat)
- Plate + State required to be recorded **by the technician** unless a **No Plate flag** is indicated
- **Invoice requires VIN, not plate** (his correction of the earlier plate rule)
- Invoice items that have costs **are expenses** (COGS linkage from invoice_items to expenses)
- **Vehicle record is not created until invoicing stage** — accurate ID needs the VIN; all vehicle info stays on the document as `temp_*` fields until then (`vehicle_id` NULL until invoice)
- On-scene authorization required **before work commences** (changed from "before marking complete") — reflected in UI copy, buttons, tooltips, validation messages
- **Signature capture required** for authorizations (canvas pad, PNG + hash stored, terms version, timestamp)

### UX guardrails
No free-text line items (catalog "+" picker only); phone mask enforced at UI and validated server-side; prevent invoice issue without required vehicle ID; live subtotal with "final invoice may vary" disclaimer; prominent on-scene authorization step. Page blueprints for SR create/detail, invoice create/issue, products & services, customers/vehicles (dedup helpers), reports (date filters, CSV/PDF export).

### Recommended tech stack (saved as tech-stack.md)
**Laravel 11 (PHP 8.2+) + Blade + Alpine.js + Tailwind CSS + MySQL**; Breeze auth + spatie/laravel-permission (roles: admin, dispatch, driver, customer); barryvdh/laravel-dompdf for invoice/authorization PDFs; **signature_pad** (vanilla JS canvas) for signatures; dev on Windows/XAMPP, deploy Ubuntu 22.04 + Nginx + PHP-FPM; Redis optional later; Pest for tests. Rationale: server-rendered Blade keeps complexity low; Alpine covers the sidebar/accordion interactivity; Tailwind config carries the dark+neon palette. (Note: several other chats assume plain PHP MVC without Laravel — stack was still in flux.)

---

## 10. Cross-Cutting Observations

1. **The signature interaction** = button-only nav + accordion sub-buttons that are offset/off-center and push content down, with push/active glow. Any new UI should implement this.
2. **Glow discipline:** interaction-only glow, one glow language, monoline icons. Violating this is what got two mockups rejected.
3. **VIN policy evolved:** early sitemap said plate+state unique / VIN optional → final position: VIN mandatory for completion/invoice/payment, vehicle record deferred until invoicing, No-Plate flag exists. Use the design doc + MVP frontend versions.
4. **Catalog-first is universal:** every chat enforces "no free-text line items; everything from the catalog via a + picker."
5. Customer type dropdown in the form spec (Individual / Fleet / Commercial) matches current WKR terminology ("Individual," never "Person"; fleet = business).

---

## Non-Design Chats (skipped per scope)

- **Neuralink Experience Inquiry** — personal curiosity chat about Neuralink; no app/design content.
- **Audio interface absence reasons** — personal/hardware chat; no design content.
- **Device Access Inquiry** — personal/device chat; no design content.
