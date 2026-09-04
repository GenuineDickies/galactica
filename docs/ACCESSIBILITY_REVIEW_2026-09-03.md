# Accessibility review — 2026-09-03 (WCAG 2.1 AA, static)

Scope: `app/Views/layouts/app.php`, `partials/*`, `pages/*`, `public/assets/app.css`,
`app.js`. Code review only — no screen-reader or browser pass. Nothing was changed.
Priority weighted to the two phone personas: the stranded caller on `/locate`,
`/sign`, `/pay`, and the technician on `dashboard_tech` / `wo_show`.

## Critical

**C1 — Row navigation is mouse-only.** `app.js:118-122` makes `<tr data-href>`
clickable via a click listener; no `tabindex`, no keydown, no real link. Used on every
index/queue table including the technician's "Jobs assigned to you"
(`dashboard_tech.php:21`). A keyboard or switch user cannot open a record anywhere.
SC 2.1.1, 4.1.2. Fix: wrap the primary cell in a real `<a href>` (row-click stays as
enhancement).

**C2 — Same pattern blocks the catalog picker.** `line_editor.php:160-171`
`<tr data-pick-item>` — no keyboard way to add a line to any document. SC 2.1.1.

**C3 — Labels not associated with inputs (~250 of 263).** Only `login.php` and the
VIN field on `wo_show.php:528` use `for`/`id`; everywhere else `<label>` is a sibling
of the input (`cust_new.php:29`, `sr_new.php:63`, `pinmap.php:72`,
`line_editor.php:196-208`, `diag_edit.php:49-74`, `sign.php:132-135`). Screen readers
announce blank fields; tapping the label does nothing. `.radio-card`/`.checkline`
nest the input and are fine. SC 1.3.1, 3.3.2, 4.1.2. Fix: `for`/`id` on the `.field`
idiom, mechanically.

**C4 — PJAX swap: no focus move, no announcement.** `app.js:67-111` replaces `.main`,
sets `document.title`, scrolls — never moves focus or announces. SC 4.1.3, 2.4.3.
Fix: focus the page `<h1 tabindex="-1">` after swap; one `aria-live="polite"`
region says "Loaded: {title}".

**C5 — Flash and health banner not announced; auto-dismiss at 6 s.** `app.php:36-62`
no `role="status"`; `app.js:978-983` removes flashes regardless. On `/locate`,
`/sign`, `/pay` this is the only feedback a customer gets. SC 4.1.3, 2.2.1. Fix:
`role="status" aria-live="polite"` on `.flashwrap`; pause on hover/focus; close button.

**C6 — `#loc_status` on `/locate` is silent.** `locate.php:105`, `app.js:529-552`
write permission-denied / no-fix text with no live region — the single most
safety-relevant failure state in the app. SC 4.1.3. Fix:
`aria-live="assertive" role="status"`.

## Serious

**S1 — No skip link.** Up to ~25 sidebar buttons before content. SC 2.4.1.
**S2 — Modals have no dialog semantics or focus trap.** `.modal-bg` lacks
`role="dialog" aria-modal aria-labelledby`; `app.js:124-137` focuses the first field
but Tab escapes to the page behind. SC 4.1.2, 2.4.3.
**S3 — Signature is pointer-only.** `signature_field.php` + `app.js:219-221` bind
mouse/touch only; `/sign`'s whole authorize flow gates on it (`data-sig-required`).
SC 2.1.1. Fix: typed-name confirmation fallback; dialog semantics on `.sigsheet`.
**S4 — Primary customer CTAs under 44 px.** `sign.php:158`, `checkout.php:125`
≈34 px; `locate.php:102-103` already overrides to ≈48 px — apply the same. Also
`.btn--sm` (≈27 px) on the tech status buttons `wo_show.php:160-190` and
`.radio-card` (≈30 px) for tip/drivability. SC 2.5.5.
**S5 — `--text-faint: #64748b` fails 4.5:1** at every size used: 4.14 on `--bg`,
3.93 on `--surface-1`, 3.52 on panel, 2.89 on `panel__head`. Backs `.faint`, `.tag`,
`.hint`, `.disclaimer`, table headers — including the legal text on `sign.php:148`
and the fallback instructions on `locate.php:106`. SC 1.4.3. Fix: ≥ `#7c8ba5`
(`--slate` passes at 5.55) and recheck panel-head.
**S6 — Placeholder 2.79:1** (`app.css:442 #4d5b73`) and used as the only hint on
several fields. Move examples into a visible `.hint`.

## Moderate

**M1** No `<h1>`/headings on any customer page (`locate`, `sign`, `checkout`,
`doc_print`, `diag_print`) — SC 1.3.1, 2.4.6.
**M2** Sidebar is `<button>`s in `<aside>` with no `<nav>` landmark
(`sidebar.php:108`); consider `<a href>` intercepted by the same handler so
open-in-new-tab works — SC 1.3.1, 4.1.2.
**M3** Status chain current step is colour-only (`wo_show.php:22-33`,
`.chain__step.is-current`) — SC 1.4.1.
**M4** No `scope="col"` on any `<th>` — SC 1.3.1.
**M5** Radio groups without `<fieldset>/<legend>` (`checkout.php:114-122` tip,
`diag_edit.php:62-68` drivability) — SC 1.3.1.
**M6** Decorative emoji spoken (`403.php:3`, `dashboard_tech.php:12`,
`wo_index.php`, `line_editor.php:42`); sidebar SVGs already `aria-hidden` — copy that.
**M7** `[data-pinmap-status]` / `[data-pinpick-status]` updates have no live region
(`app.js:1015`, `:1280`) — SC 4.1.3.
**M8** Photo `<select name="label">` and `<input type="file">` on
`wo_show.php:285-289` have no label at all — SC 3.3.2.

## Minor

`prefers-reduced-motion` covers `.content`/`.pjax-bar` only (`app.css:649`), not
`.flash` slide, `.modal` pop, `.spark`. Signature `alt` text generic. Disabled-button
reasons live in `title` only (`wo_show.php:184-185`) — invisible on touch and
unreliable for AT; use visible text + `aria-describedby`.

## Passes

Core text/background pairs 6.5–16.9:1; link 13.3:1; badges and alerts 4.68–15.5:1.
`lang="en"` everywhere. Viewport never blocks zoom. `:focus-visible` glow 11.4:1, no
`outline:none` without replacement. Escape closes modals and the sheet, in the right
order. Print stylesheet forces light/high-contrast. PJAX, vehicle picker and pinmap
all degrade to plain navigation/inputs without JS. `body.is-customer .internal`
hides cost/margin during hand-over.

## Order of work

C3 (labels) and C1/C2 (row keyboard access) — mechanical, highest volume. Then
C4–C6 (announcements) — every screen. Then S1–S5 on the three customer pages, which
are used by someone with no one to ask when something silently fails.

---

## Addendum — resolution, 2026-09-03

Every finding above was worked through the same day. `tests/a11y_lint.php`
(17 rules, static, no DB) went from **865 failed** on the tree as reviewed to
**0 failed**; it is now part of Definition of Done (`AGENTS.md`) and the code
review checklist. Nothing here changed behaviour or copy; the
`.internal` / `data-customer-facing` hand-over hiding was re-tested and still
holds (below).

| Finding | Status | What was done |
|---|---|---|
| C1 row navigation mouse-only | FIXED | Every `tr[data-href]` (15 tables) carries a real `<a class="row-link">` in its primary cell; the row stays clickable for the mouse, the link navigates in place (modifier-click opens a tab), Enter on a focused row also works. |
| C2 catalog picker | FIXED | The item name in `line_editor.php` is a `<button class="row-pick" data-pick-item>` with `aria-pressed`; the rest of the row still picks for a mouse. |
| C3 labels (~250) | FIXED | 223 `<label for>`/`id` pairs wired mechanically (ids suffixed where a name repeats — `_promote`, `_<row id>`); the remaining bare search/filter/inline inputs got `aria-label`. `field()` helper added for new views. Lint R1/R2 also rejects a `for` that names no control. |
| C4 PJAX no focus/announce | FIXED | The topbar title is the page `<h1 tabindex="-1">`; after a swap it is focused and `#live` says "Loaded: {title}". |
| C5 flashes silent, auto-dismiss | FIXED | `.flashwrap` is `role="status" aria-live="polite"`; each flash gets a Dismiss button and the 6 s timer pauses on hover/focus. Health banner wrapper is `role="status"`. |
| C6 `#loc_status` silent | FIXED | `role="status" aria-live="assertive"`, rendered (empty) from page load so the region exists before the first message. |
| S1 no skip link | FIXED | `<a class="skip-link" href="#main">` first in `<body>`; `<main id="main" tabindex="-1">`; sidebar wrapped in `<nav aria-label="Primary">`. |
| S2 modals | FIXED | All 19 `.modal` panels: `role="dialog" aria-modal="true" aria-labelledby` (title ids added). app.js traps Tab/Shift+Tab inside the open layer, remembers the opener and returns focus on close; Escape unchanged. |
| S3 signature pointer-only | FIXED | Sheet is a dialog (trapped, focus returned). Canvas is focusable; Enter/Space or the "I can't draw — type my name" button opens a typed-name field that renders the name to the same canvas, so `signature_data`, `signature_is_image()` and the server path are unchanged. Trigger button is labelled by the field label. |
| S4 CTAs under 44 px | FIXED | `.btn--lg` (min-height 44 px) on the `/locate`, `/sign`, `/pay` primaries and the signature trigger; inline override on `locate.php` removed. `body.is-tech` / `body.is-customer-page` lift `.btn--sm` and `.radio-card` to 44 px on coarse pointers. |
| S5 `--text-faint` contrast | FIXED | `#64748b` → `#8593ab` (4.99:1 on the table header tint, the hardest surface); `.panel__head .tag/.faint/.hint` use `--text-dim` (4.71:1 on the head band). Lint R16 checks 31 real token/surface pairs; that check also caught `--slate` (3.90 → lifted to `#8a99b3`), `--danger` as text (4.42 → `#ff5f85`) and the nav count pill (3.23 → white text). |
| S6 placeholder contrast | FIXED | `#8291a8` on `--surface-2` (5.0:1). Placeholder-only hints now have an `aria-label` or a visible label. |
| M1 headings on customer pages | FIXED | Company name is `<h1>`, panel titles `<h2>` on `locate`, `sign`, `checkout`, `doc_print`, `diag_print`, `login`. |
| M2 nav landmark | FIXED | `<nav aria-label="Primary">`. Buttons were left as buttons (open-in-new-tab was not a requirement; the row links now cover it where it matters). |
| M3 chain current step colour-only | FIXED | `<span class="sr-only">Current step: </span>` / "Done: " prefixes; ✓ and ▸ are `aria-hidden`. |
| M4 `scope` | FIXED | 224 `<th scope="col">`. |
| M5 radio groups | FIXED | `<fieldset class="radio-group"><legend>` on tip, drivability, authorization method, payment method; reset CSS so nothing moves. |
| M6 emoji spoken | FIXED | Every `.empty__icon`, `.chain__arrow` and ✕ glyph is `aria-hidden` (remove buttons got `aria-label`). |
| M7 pinmap/pinpick status | FIXED | `aria-live="polite"`; VIN hints too. |
| M8 photo select/file unlabeled | FIXED | `sr-only` labels wired by `for`. |
| Minor: reduced motion | FIXED | `.flash`, `.modal`, `.spark`, button/row transitions covered. |
| Minor: signature `alt` | FIXED | `alt="Signature of {name}"` on all three. |
| Minor: `disabled` reasons in `title` | FIXED | Visible `.why` text + `aria-describedby` on Issue invoice and Begin work. |

**Deliberately left.** Sidebar items stay `<button data-url>` (M2's
"consider"): they are keyboard-operable and inside a landmark; converting to
links would touch the PJAX contract for no accessibility gain. Static `.alert`
gate boxes carry `role="status"` per the lint rule; this is harmless (they are
present at load) but a future pass could distinguish them from live feedback.

**Found while verifying, fixed.** `signature_is_image()` used
`{64,200000}`; PCRE caps a quantifier at 65535, so the pattern never compiled
and every signature submit — drawn or typed, `/sign` or in-person — was
rejected as "could not be read". Length is now checked outside the pattern;
`tests/signature_gate.php` covers it. The new hint sentence under the
signature trigger ("You can draw with a finger or mouse, or type your name
instead.") is the one piece of new copy, added so the fallback is discoverable.

**Keyboard walk (local `php -S 127.0.0.1:8089 -t public`, Chrome).** Native Tab
traversal cannot be driven from the automation bridge, so focus was moved with
`.focus()` and the same key events dispatched; each step was asserted from the
DOM: login → dashboard; `/service-requests`: first focusable is the skip link,
Enter lands on `#main`; row link → PJAX to `SER-20260831-011`, focus on the
`<h1>`, `#live` = "Loaded: SER-20260831-011"; `/work-orders/4`: open "Add from
catalog" (focus → search box), Tab from the last control wraps to "Close",
Shift+Tab from the first wraps to "Add line item", pick "After-Hours /
Emergency Surcharge" with the row button (`aria-pressed`, row highlighted),
Escape closes and returns focus to the opener; `completeModal`
(`data-customer-facing`): all 5 `.internal` elements `display:none` by computed
style while open, visible again after close; signature sheet: `role="dialog"`,
Enter on the canvas opens the typed field, name → Apply → hidden input holds a
57 KB `data:image/png;base64` value, sheet trap wraps, focus returns to the
trigger; `/sign/{token}` (COMPLETION): `<h1>`, no unlabeled controls, primary
and trigger 47 px, typed-name path submitted → "Thank you — your signature has
been recorded", signer recorded. `/invoices/1/print` visible text contains no
cost/markup/margin/profit. `/locate` renders `<h1>`; a fresh location token
could not be minted (consent gate), so its live region was verified statically.
