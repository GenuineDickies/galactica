# Design critique — 2026-09-03 (static)

Usability, hierarchy and consistency of the dispatch-to-cash UI, read against
`docs/MANUAL.md`. Code review only; accessibility and security are in their own
reports. Nothing was changed.

## Top 5 by user impact

**1. The admin shell does not collapse on a phone — the technician's device.**
`app.css:188` `.shell{grid-template-columns:var(--sidebar-w) 1fr}` with `--sidebar-w`
258 px; no `@media` rule in the file touches `.shell` or `.sidebar`; `app.js` has no
toggle. On a 375 px phone the rail eats two-thirds of the width and `wo_show`
(six-column line table, `form-grid--3` VIN form, close-out) and `dashboard_tech`
(six-column job table) are left ~115 px. This is the primary screen for one of the
three named users. Fix first: a ~760 px breakpoint that stacks `.shell`, hides the
sidebar behind a toggle, and stacks the topbar.

**2. The work-blocking gate is one `.alert` among four look-alikes.**
`wo_show.php:79-139` stacks signature-owed (danger), authorized-by (ok),
VIN-required (warn) and no-show (danger) in the same component. The manual calls
"do not begin work" the one warning a tech must never miss, but the reason "Begin
work" is disabled lives in a `title` (`wo_show.php:185`) — never shown on touch. Give
the blocking gate a distinct, sticky treatment and put the disabled reason as visible
text under the button.

**3. Customer hand-over hiding of cost/margin is JS-only in the interactive
modals.** `body.is-customer .internal{display:none}` (`app.css:527`) is applied by
`app.js:133` when a `data-customer-facing` modal opens. If `app.js` fails to load or
errors before that handler, the modal still opens with `.internal` cells visible, in
the customer's hands. `doc_print`/`diag_print` omit the fields structurally — the
right model. Render the sign/close-out modals from a fragment that never contains
`.internal` markup.

**4. Estimate/invoice headers omit the primary button instead of explaining it.**
`est_show.php:56-69` renders no action at all when none of four branches match
(e.g. a fresh DRAFT with no lines) — the most common first-touch state.
`inv_show.php:38-39` does better (`disabled` + `title`) but `title` again. Always
render the primary action, disabled, with a visible reason — the pattern the manual
itself promises.

**5. Dense tables have no mobile treatment, and `dashboard_tech.php:17-31` is one.**
`.table-wrap{overflow-x:auto}` means the tech horizontally scrolls their own job list
as the first thing after login. Switch technician-facing tables to stacked cards
below ~700 px (badge, doc number, customer, service, where, priority).

## Further issues

**Hierarchy / gate explanation.** Disabled-reason-in-`title` recurs three times in
`wo_show.php` and on `inv_show.php:38`. The five-step chain (`est_show.php:20-25`,
`sr_show.php:75-78`, `wo_show.php:24-33`) orients well but is not navigable; a
persistent SR → Est → WO → Inv trail is the missing piece — and `$crumb` in
`layouts/app.php:18` exists but no page sets it. `inv_show.php:16` locks line edits
for a tech on a DRAFT invoice with no copy saying why ("the field bills what the WO
recorded" is in the manual, not the screen).

**Consistency.** Confirmation weight is not graduated: line removal `confirm()`
(`line_editor.php:112`), markup-tier removal none (`app.js:151-157`), invoice void a
typed reason. `badge--warn` carries three meanings on one page (override,
reclassified, etc. — `wo_show.php:374`), so colour can't be pattern-matched. `.btn--sm`
"✕" remove buttons sit inside clickable rows with `data-stop-row-click` applied only
on `est_show.php:137`, not the line editor.

**Forms.** Field ordering and conditional disclosure in `sr_new.php` (category before
service, vehicle before location, reasons in comments at `:146-152`;
`data-when-*` in `app.js:423-454`) is unusually deliberate. Inline validation exists
only for VIN (`app.js:340-364`, excellent) and phone; every other required field
bounces to a full reload + flash mid-modal. Misc-line required-ness is JS-set
(`app.js:647-654`); confirm the server refuses a nameless misc line without JS.

**Navigation.** `data-href` rows have no affordance beyond hover cursor; touch users
discover them by accident. PJAX itself (`app.js:67-111`: abort in-flight, in-place
sidebar sync, non-HTML fallback) is well built.

**Print.** `doc_print.php` is clean; no `page-break-inside: avoid` on the line table
or totals, so a long invoice can split a row or orphan the totals.

## What works

Gate reasons rendered as prose beside the blocked action nearly everywhere
(`inv_show.php:56-70`, `wo_show.php:131-139`, `est_show.php:107-114`); the
document-chain widget used identically on all three doc types; the shared `.empty`
component with contextual copy; customer pages that drop the admin chrome entirely
for one column, big buttons and short copy; print views that omit internal fields
structurally rather than hiding them.
