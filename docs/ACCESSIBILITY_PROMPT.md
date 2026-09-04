# Prompt — resolve every accessibility finding and keep them resolved

Paste the section below the line into a fresh session. Everything above the
line is notes for the human; everything below it is addressed to Claude.

**Why this exists.** `docs/ACCESSIBILITY_REVIEW_2026-09-03.md` found the app
unusable from a keyboard, unlabeled to a screen reader, and silent on the one
customer page where silence is dangerous (`/locate`). The fixes are mechanical
but wide (~250 labels, 13 tables, 5 modals), and the same patterns will be
re-introduced by the next view someone writes unless there is a rule and a check.
This prompt does both: it fixes the current tree and installs a static lint
(`tests/a11y_lint.php`) that fails on the patterns, wired into the project's
Definition of Done. Update the lint when a new pattern is found.

---

## THE PROMPT

You are resolving every finding in `docs/ACCESSIBILITY_REVIEW_2026-09-03.md` in
the White Knight Roadside admin system at `C:\Users\MSI-Thin\Code Projects\wkr`
(bash path `/sessions/*/mnt/wkr`). Hand-rolled PHP 8, no framework, no build
step, **no git** — the working copy is the only copy; never suggest commit/diff/
revert, copy a file before a risky rewrite. PHP is not in the sandbox: lint and
tests run on the Windows side with `C:\xampp\php\php.exe` (set
`$env:WKR_DB_PASS` from `[Environment]::GetEnvironmentVariable('WKR_DB_PASS','User')`
first, though nothing here needs the DB).

Target is **WCAG 2.1 AA**. The two users who matter most are a stranded caller on
a phone (`/locate`, `/sign`, `/pay`) and a technician on a phone (`dashboard_tech`,
`wo_show`, `diag_edit`). Nothing you do may change behaviour, copy, or the
`.internal` / `data-customer-facing` hand-over hiding — that is a security control
([docs/SECURITY_REVIEW_2026-09-03.md]); test it still works after every phase.

### Read first

1. `PROJECT_INSTRUCTIONS.md`, `README.md`, `AGENTS.md` (authoritative).
2. `docs/ACCESSIBILITY_REVIEW_2026-09-03.md` — the findings, with file:line.
3. `app/Views/layouts/app.php`, `app/Views/partials/*.php`, `public/assets/app.css`,
   `public/assets/app.js` — the shared pieces most fixes land in.

### Phase 0 — build the lint before fixing anything

Create `tests/a11y_lint.php` in the style of the other pure suites (no DB, prints
`N passed, M failed`, exit 1 on failure). It scans `app/Views/**/*.php` and
`public/assets/app.js` statically and **fails** on each of these; run it now to
get the baseline count, and again after each phase until it is zero:

1. `<label` without `for=` whose element does not contain an `<input|select|textarea` — unassociated label.
2. `<input|select|textarea` (not `type=hidden|submit|button`, not inside a `<label>`) with no `id` matched by a `for=` in the same file and no `aria-label`/`aria-labelledby`.
3. `<th` without `scope=`.
4. `<tr` with `data-href` whose cells contain no `<a href`.
5. `<tr` with `data-pick-item` (or any `data-pick-*`) containing no `<button` or `<a`.
6. `class="modal-bg"` / `class="modal` elements without `role="dialog"`, `aria-modal="true"`, and `aria-labelledby`.
7. `class="flashwrap"` or `class="alert` outside a `role="status"|"alert"` / `aria-live` ancestor (allow `alert--*` inside `role="status"`).
8. Elements with `id="loc_status"`, `data-pinmap-status`, `data-pinpick-status`, `data-vin-hint` (and any `data-*-status`) without `aria-live`.
9. `<input type="radio"` groups (same `name`, ≥2) not inside `<fieldset>` with a `<legend>`.
10. Emoji or symbol-only content (`✓ ✕ ⛔ 🔧 ＋ ▸ ›`) in an element without `aria-hidden="true"` and without an adjacent text label in the same element.
11. `<img` without `alt=`.
12. `disabled` buttons with `title=` and no `aria-describedby`.
13. `<main` missing from `layouts/app.php`, or no `<a class="skip-link" href="#main"` as the first focusable child of `<body>`.
14. `<h1` missing from any page in `app/Views/pages/` (layout-rendered pages may put it in the layout via `$title` — allow that exactly once).
15. `outline:none` / `outline:0` in `app.css` without a `:focus-visible` replacement in the same rule block.
16. CSS custom-property contrast: parse `:root` tokens in `app.css`, compute WCAG contrast for every `--text*` token against every `--bg`/`--surface*`/`--panel*` token, and fail any pair used for normal text below 4.5:1. Hard-code the pairs that are actually used (list them in the test with the selector that uses them) so the check is honest, not exhaustive.
17. `user-scalable=no` or `maximum-scale` in any viewport meta.

Add it to the pure-suite list in `AGENTS.md` → Definition of Done, and to
`docs/CODE_REVIEW_PROMPT.md`'s test list. It must pass before the work is DONE.

### Phase 1 — shared scaffolding (one change, every page)

- `layouts/app.php`: `<a class="skip-link" href="#main">Skip to content</a>` first
  in `<body>`; content region becomes `<main id="main" class="content" tabindex="-1">`;
  `.flashwrap` gets `role="status" aria-live="polite" aria-atomic="true"`; health
  banner gets `role="status"`; wrap the sidebar in `<nav aria-label="Primary">`.
  Add one visually-hidden `<div id="live" aria-live="polite">` for PJAX.
- `app.css`: `.skip-link` (off-screen until `:focus`), `.sr-only` utility, `.btn--lg`
  (min-height 44px, matching the override already inline on `locate.php:102`),
  `--text-faint` raised to ≥ `#7c8ba5` and re-checked against `panel__head`
  (the lint will tell you), `.input::placeholder` ≥ `#8291a8`, extend the
  `prefers-reduced-motion` block to `.flash`, `.modal`, `.spark`.
- `app.js`:
  - PJAX `navigate()`: after swap, `document.querySelector('#main h1, #main [tabindex="-1"]')?.focus()` and set `#live` text to `Loaded: ` + title.
  - Row-click: keep the delegated handler but make it *skip* when the click target is inside an `<a>` or `<button>` (so the real link wins), and add `keydown` Enter on `tr[data-href]` as belt-and-braces.
  - Flash: pause the 6 s timer on `mouseenter`/`focusin`, resume on leave; add a close button per flash (`aria-label="Dismiss"`).
  - Modals: on open set `role="dialog" aria-modal="true"` if missing, remember the opener, trap Tab/Shift+Tab inside `.modal`, restore focus to the opener on close. Same for `.sigsheet`.
  - Signature sheet: add `keydown` support so Enter/Space on the canvas opens the typed-name fallback (Phase 3).
- `helpers.php`: add `field(string $label, string $name, string $inputHtml, bool $req=false, string $hint='')` that emits the `.field` wrapper with `<label for>` + `id` wired — use it for every *new* field; for existing views it is acceptable to add `for`/`id` inline (Phase 2) rather than rewrite them through the helper. `badge()` output with a symbol prefix must wrap the symbol in `<span aria-hidden="true">`.

### Phase 2 — mechanical sweep (the lint drives this)

Work file by file through `app/Views/pages/*.php` and `partials/*.php` until the
lint is zero. Rules:

- Every `<label>` gets `for="<name>"`, every control gets `id="<name>"`. When the same `name` appears twice on one page (edit vs. promote modal) suffix the id (`first_name_promote`). Never change `name=`.
- Every `<th>` gets `scope="col"` (or `row` where the first column is a header).
- Every `tr[data-href]`: the primary cell's content becomes `<a class="row-link" href="…">…</a>`; keep `data-href` so the row stays clickable for mouse users. `.row-link` in CSS: `color:inherit;text-decoration:none;display:block`.
- Every `tr[data-pick-item]` in `line_editor.php`: the name cell becomes `<button type="button" class="row-pick" data-pick-item="…">`. Move the `data-pick-item` handler target accordingly (`app.js` ~627).
- Radio groups (`checkout.php` tip, `diag_edit.php` drivability, any `.radio-row`): `<fieldset class="radio-row"><legend>…</legend>…</fieldset>`; style `fieldset{border:0;padding:0;margin:0}` so nothing moves visually.
- Decorative glyphs get `aria-hidden="true"`; the sidebar SVGs already do it — copy that.
- `disabled` buttons: move the `title` text into a visible `<div class="hint" id="why-…">` under the button and add `aria-describedby="why-…"`. This also fixes the design critique's "reason lives in a title" items — do not keep both.
- Status chain: add `<span class="sr-only">(current)</span>` to `.chain__step.is-current`.
- Live regions: `#loc_status` → `role="status" aria-live="assertive"`; pinmap/pinpick status and the VIN hint → `aria-live="polite"`.
- Customer pages (`locate`, `sign`, `checkout`, `doc_print`, `diag_print`): company name becomes `<h1>`, panel titles `<h2>`; primary submit buttons get `btn--lg`; remove the now-redundant inline override on `locate.php:102`. Tech status buttons on `wo_show.php:160-190` and `.radio-card` go to ≥44 px on `body.is-tech`/customer pages (a media query on `(pointer:coarse)` is acceptable).
- `wo_show.php:285-289`: real labels on the photo `<select>` and `<input type=file>`.
- `img` alts: signature images say who signed (`alt="Signature of <?= e($signer) ?>"`).

### Phase 3 — signature without a pointer

`partials/signature_field.php` + `app.js` sigsheet: add a "I can't draw — confirm
by typing my full name" control inside the sheet. When used, it writes a
generated PNG of the typed name (canvas `fillText`, then `toDataURL('image/png')`)
into the same `signature_data` field, so the server path, `signature_is_image()`,
and the evidence record are unchanged; record `signed_method` unchanged (the
audit detail may append `· typed`). The sheet gets `role="dialog" aria-modal`
and the Phase 1 focus trap. Verify `/sign` end-to-end on a local server with the
keyboard only.

### Phase 4 — verify, then make it stick

1. `tests/a11y_lint.php` → 0 failures. `php -l` on every changed file. All pure suites still pass.
2. Start `php -S 127.0.0.1:8089 -t public` and walk, keyboard only (Tab/Enter/Escape): login → dashboard → open a job from the table → open a work order → add a catalog line → open and close a modal → `/sign/{token}` typed-name path. Record what you did in the report.
3. Confirm the hand-over hiding still works: open a `data-customer-facing` modal and assert `.internal` is hidden (`getComputedStyle`), with JS on. Then confirm `doc_print` still contains no cost/markup strings.
4. Add to `AGENTS.md` → "Writing views": the six rules below, verbatim. Add `php tests/a11y_lint.php` to the Definition of Done. Add a line to `docs/DECISIONS.md` dated today: "Accessibility: WCAG 2.1 AA is a hard rule for every view; `tests/a11y_lint.php` enforces the mechanical part."
5. Update `docs/ACCESSIBILITY_REVIEW_2026-09-03.md` with an addendum listing each finding as FIXED or, if deliberately left, why.

### The six standing rules (for AGENTS.md)

1. Every control has a name a screen reader can say: `<label for>` + `id`, or the input nested in the `<label>`. `placeholder` is never the only hint.
2. Everything a mouse can do, a keyboard can do: a clickable row contains a real `<a>`; a pickable row contains a real `<button>`; modals trap focus and return it.
3. Anything that changes without a page load announces itself: status text and flashes live in `aria-live` regions; PJAX moves focus to the new `<h1>`.
4. Phone pages (customer and technician) use `btn--lg` for the primary action — 44 px minimum; never `.btn--sm` for a state change.
5. Colour never carries meaning alone, and every text/background token pair used for normal text is ≥ 4.5:1 — new tokens go through the lint's pair list.
6. Decorative glyphs are `aria-hidden="true"`; `disabled` buttons say why in visible text, not in `title`.

### Reporting

End with DONE or BLOCKED and the lint count before/after. Do not append a list of
further considerations; anything you noticed goes into the review addendum.
