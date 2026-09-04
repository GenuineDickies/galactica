<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
/**
 * Static accessibility lint. Pure — no database, no server.
 *   php tests/a11y_lint.php
 *
 * Scans app/Views/**\/*.php and public/assets/js/app.js for the patterns the
 * 2026-09-03 accessibility review found (docs/ACCESSIBILITY_REVIEW_2026-09-03.md)
 * and fails on each one, so they cannot come back with the next view. It is
 * deliberately a *pattern* check, not a WCAG engine: it catches what a
 * template author does by habit. Every rule is numbered R1–R17 below and
 * listed in AGENTS.md → Writing views.
 *
 * The scanner is a small tag walker over the PHP template source. PHP blocks
 * are replaced by stable placeholder tokens (same expression → same token),
 * so `for="<?= $id ?>"` and `id="<?= $id ?>"` still match each other. The
 * element stack is best-effort: a close tag that does not match the top of
 * the stack unwinds to the nearest match, so a `<?php if … ?>` that opens two
 * alternative wrappers cannot derail the rest of the file.
 *
 * Add a rule here when a review finds a new pattern; add a pair to
 * CONTRAST_PAIRS when a new text/background token pair is used for text.
 */
declare(strict_types=1);

$ROOT  = dirname(__DIR__);
$PASS  = 0; $FAIL = 0; $LINES = [];

function ok(string $label): void   { global $PASS; $PASS++; }
function bad(string $file, int $line, string $rule, string $msg): void {
    global $FAIL, $LINES;
    $FAIL++;
    $LINES[] = sprintf("  \033[31mFAIL\033[0m %-6s %s:%d  %s", $rule, $file, $line, $msg);
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

/* ------------------------------------------------------------------ *
 *  Template loading
 * ------------------------------------------------------------------ */
final class Tpl
{
    public string $file;
    public string $raw;          // original source
    public string $src;          // PHP blocks replaced by placeholders
    /** @var array<string,string> placeholder => php code */
    public array $php = [];
    /** @var array<int,array{name:string,attrs:array<string,string>,attrRaw:string,start:int,end:int,close:bool,void:bool,line:int}> */
    public array $tags = [];
    public array $lineStarts = [];

    const VOID = ['input','img','br','hr','meta','link','col','source','wbr','area','base','embed','param','track'];

    public function __construct(string $file, string $raw)
    {
        $this->file = $file;
        $this->raw  = $raw;
        $this->src  = preg_replace_callback('/<\?(?:php|=)?[\s\S]*?\?>/', function ($m) {
            $code = $m[0];
            $key  = '{{php:' . substr(md5(trim(preg_replace('/^<\?(?:php|=)?|\?>$/', '', $code))), 0, 8) . '}}';
            $this->php[$key] = $code;
            // keep the line count identical so reported lines are real
            return $key . str_repeat("\n", substr_count($code, "\n"));
        }, $raw);
        $this->index();
    }

    public function lineAt(int $offset): int
    {
        return substr_count($this->src, "\n", 0, $offset) + 1;
    }

    /** True if the attribute string (placeholders included) can render the bare word $word. */
    public function mayContain(string $attrRaw, string $word): bool
    {
        if (preg_match('/(?<![\w-])' . preg_quote($word, '/') . '(?![\w-])/', $attrRaw)) return true;
        if (preg_match_all('/\{\{php:[0-9a-f]{8}\}\}/', $attrRaw, $m)) {
            foreach ($m[0] as $k) {
                if (isset($this->php[$k]) && strpos($this->php[$k], $word) !== false) return true;
            }
        }
        return false;
    }

    private function index(): void
    {
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $this->src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $t) {
            $name = strtolower($t[2][0]);
            $attrRaw = $t[3][0];
            $attrs = [];
            preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attrRaw, $am, PREG_SET_ORDER);
            foreach ($am as $a) {
                $k = strtolower($a[1]);
                if (strpos($k, '{{php') === 0) continue;
                $attrs[$k] = $a[2] ?? ($a[3] ?? ($a[4] ?? ''));
                if ($attrs[$k] === '' && isset($a[2]) && $a[2] === '' && !isset($a[3]) && !isset($a[4])) $attrs[$k] = '';
            }
            $this->tags[] = [
                'name'    => $name,
                'attrs'   => $attrs,
                'attrRaw' => $attrRaw,
                'start'   => $t[0][1],
                'end'     => $t[0][1] + strlen($t[0][0]),
                'close'   => $t[1][0] === '/',
                'void'    => in_array($name, self::VOID, true) || substr(rtrim($attrRaw), -1) === '/',
                'line'    => $this->lineAt($t[0][1]),
            ];
        }
    }

    /** Offset of the close tag matching the open tag at index $i (best effort). */
    public function closeOf(int $i): ?int
    {
        $name = $this->tags[$i]['name']; $depth = 0;
        for ($j = $i, $n = count($this->tags); $j < $n; $j++) {
            $t = $this->tags[$j];
            if ($t['name'] !== $name) continue;
            if ($t['close']) { if (--$depth === 0) return $j; }
            elseif (!$t['void']) { $depth++; }
        }
        return null;
    }

    public function inner(int $i): string
    {
        $j = $this->closeOf($i);
        if ($j === null) return '';
        return substr($this->src, $this->tags[$i]['end'], $this->tags[$j]['start'] - $this->tags[$i]['end']);
    }

    /** Best-effort ancestor list (open tags) for each tag index. */
    public function ancestors(): array
    {
        $stack = []; $out = [];
        foreach ($this->tags as $i => $t) {
            if ($t['close']) {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($this->tags[$stack[$k]]['name'] === $t['name']) { array_splice($stack, $k); break; }
                }
                $out[$i] = $stack;
                continue;
            }
            $out[$i] = $stack;
            if (!$t['void']) $stack[] = $i;
        }
        return $out;
    }

    public static function hasClass(array $attrs, string $cls): bool
    {
        if (!isset($attrs['class'])) return false;
        return (bool) preg_match('/(?:^|\s)' . preg_quote($cls, '/') . '(?:\s|$)/', $attrs['class']);
    }

    public static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

/* ------------------------------------------------------------------ *
 *  Files
 * ------------------------------------------------------------------ */
$views = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/app/Views"));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $views[] = $f->getPathname();
}
sort($views);
$jsPath  = "$ROOT/public/assets/js/app.js";
$cssPath = "$ROOT/public/assets/css/app.css";
$rel = fn(string $p) => str_replace('\\', '/', substr($p, strlen($ROOT) + 1));

$tpls = [];
foreach ($views as $v) $tpls[$rel($v)] = new Tpl($rel($v), file_get_contents($v));
/* app.js builds the signature sheet and a few controls from string literals;
   the same rules apply to that markup. Treat the file as one template. */
$js = file_get_contents($jsPath);
/* Comments mention tags in prose ("upgrades each one to a <select>"); blank
   them, keeping line numbers, so only markup the script actually emits counts. */
$js = preg_replace_callback('~/\*[\s\S]*?\*/|^\s*//[^\n]*~m', fn($m) => str_repeat("\n", substr_count($m[0], "\n")), $js);
$tpls['public/assets/js/app.js'] = new Tpl('public/assets/js/app.js', $js);

/* ------------------------------------------------------------------ *
 *  R1–R12: per-template element rules
 * ------------------------------------------------------------------ */
section('R1–R12 markup patterns');
$SYMBOL_RE = '/^[\s\p{So}\p{Sm}\x{2713}\x{2715}\x{26D4}\x{FF0B}\x{25B8}\x{203A}\x{2039}\x{00D7}\x{2022}\x{2192}\x{2190}\x{2191}\x{2193}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]+$/u';
$HAS_SYMBOL = '/[\p{So}\x{2713}\x{2715}\x{26D4}\x{FF0B}\x{25B8}\x{203A}\x{2039}\x{00D7}\x{2192}\x{2190}\x{2191}\x{2193}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u';

foreach ($tpls as $file => $T) {
    $anc = $T->ancestors();
    $isJs = substr($file, -3) === '.js';

    /* Collect every for= target and every id in the file. */
    $forIds = []; $ids = [];
    foreach ($T->tags as $t) {
        if ($t['close']) continue;
        if (isset($t['attrs']['for'])) $forIds[$t['attrs']['for']] = true;
        if (isset($t['attrs']['id']))  $ids[$t['attrs']['id']] = $t['name'];
    }

    foreach ($T->tags as $i => $t) {
        if ($t['close']) continue;
        $a = $t['attrs']; $n = $t['name'];
        $insideLabel = false;
        foreach ($anc[$i] as $p) if ($T->tags[$p]['name'] === 'label') { $insideLabel = true; break; }

        /* R1 — <label> without for= that does not wrap a control; or a for=
           that names no control in the same file (a dangling for is as
           silent as no for at all). */
        if ($n === 'label') {
            if (isset($a['for']) && !$isJs && (!isset($ids[$a['for']]) || !in_array($ids[$a['for']], ['input', 'select', 'textarea', 'button', 'canvas'], true))) {
                bad($file, $t['line'], 'R1', 'label for="' . $a['for'] . '" names no control in this file');
            }
            if (!isset($a['for'])) {
                $inner = $T->inner($i);
                if (!preg_match('/<(input|select|textarea)\b/i', $inner)) {
                    bad($file, $t['line'], 'R1', 'label has no for= and wraps no control');
                } else ok('R1');
            } else ok('R1');
        }

        /* R2 — control with no accessible name. */
        if (in_array($n, ['input', 'select', 'textarea'], true)) {
            $type = strtolower($a['type'] ?? 'text');
            if (!in_array($type, ['hidden', 'submit', 'button', 'reset'], true) && !$insideLabel) {
                $named = isset($a['aria-label']) || isset($a['aria-labelledby'])
                      || (isset($a['id']) && isset($forIds[$a['id']]));
                if (!$named) bad($file, $t['line'], 'R2', "<$n" . (isset($a['name']) ? " name=\"{$a['name']}\"" : '') . '> has no label (for/id, aria-label or aria-labelledby)');
                else ok('R2');
            }
        }

        /* R3 — <th> without scope. */
        if ($n === 'th') {
            if (!isset($a['scope'])) bad($file, $t['line'], 'R3', '<th> without scope=');
            else ok('R3');
        }

        /* R4 — clickable row with no real link. */
        if ($n === 'tr' && isset($a['data-href'])) {
            if (!preg_match('/<a\b[^>]*\bhref=/i', $T->inner($i))) bad($file, $t['line'], 'R4', 'tr[data-href] contains no <a href>');
            else ok('R4');
        }

        /* R5 — pickable row with no button/link. */
        if ($n === 'tr') {
            foreach ($a as $k => $v) {
                if (strpos($k, 'data-pick-') === 0) {
                    if (!preg_match('/<(button|a)\b/i', $T->inner($i))) bad($file, $t['line'], 'R5', "tr[$k] contains no <button> or <a>");
                    else ok('R5');
                    break;
                }
            }
        }

        /* R6 — modal without dialog semantics. The .modal-bg is the backdrop;
           the semantics may sit on it or on the .modal panel directly inside. */
        if (Tpl::hasClass($a, 'modal-bg') || Tpl::hasClass($a, 'modal')) {
            $isBg = Tpl::hasClass($a, 'modal-bg');
            $insideBg = false;
            foreach ($anc[$i] as $p) if (Tpl::hasClass($T->tags[$p]['attrs'], 'modal-bg')) { $insideBg = true; break; }
            if ($isBg || !$insideBg) {
                $attrs = $a;
                if ($isBg) {
                    // merge the first .modal child's attributes
                    for ($j = $i + 1; $j < min($i + 6, count($T->tags)); $j++) {
                        if (Tpl::hasClass($T->tags[$j]['attrs'], 'modal')) { $attrs += $T->tags[$j]['attrs']; break; }
                    }
                }
                $missing = [];
                if (($attrs['role'] ?? '') !== 'dialog') $missing[] = 'role="dialog"';
                if (($attrs['aria-modal'] ?? '') !== 'true') $missing[] = 'aria-modal="true"';
                if (!isset($attrs['aria-labelledby'])) $missing[] = 'aria-labelledby';
                if ($missing) bad($file, $t['line'], 'R6', 'modal missing ' . implode(', ', $missing));
                else ok('R6');
            }
        }

        /* R7 — flash / alert outside a live region. */
        if (Tpl::hasClass($a, 'flashwrap') || Tpl::hasClass($a, 'alert')) {
            $live = function (array $x): bool {
                return in_array($x['role'] ?? '', ['status', 'alert'], true) || isset($x['aria-live']);
            };
            $inLive = $live($a);
            if (!$inLive) foreach ($anc[$i] as $p) if ($live($T->tags[$p]['attrs'])) { $inLive = true; break; }
            if (!$inLive) bad($file, $t['line'], 'R7', '.' . (Tpl::hasClass($a, 'flashwrap') ? 'flashwrap' : 'alert') . ' outside a role="status"/"alert" or aria-live region');
            else ok('R7');
        }

        /* R8 — status targets without aria-live. */
        $isStatus = ($a['id'] ?? '') === 'loc_status' || isset($a['data-vin-hint']);
        foreach ($a as $k => $v) if (preg_match('/^data-[a-z-]*status$/', $k)) $isStatus = true;
        if ($isStatus) {
            if (!isset($a['aria-live'])) bad($file, $t['line'], 'R8', 'status element without aria-live');
            else ok('R8');
        }

        /* R9 — radio groups outside fieldset/legend (checked below, per file). */

        /* R11 — <img> without alt. */
        if ($n === 'img') {
            if (!isset($a['alt'])) bad($file, $t['line'], 'R11', '<img> without alt=');
            else ok('R11');
        }

        /* R12 — disabled button explained only by title. */
        if ($n === 'button' && $T->mayContain($t['attrRaw'], 'disabled') && $T->mayContain($t['attrRaw'], 'title=')) {
            if (!$T->mayContain($t['attrRaw'], 'aria-describedby')) bad($file, $t['line'], 'R12', 'disabled button explains itself in title= only — use visible text + aria-describedby');
            else ok('R12');
        }
    }

    /* R9 — radio groups. */
    $groups = [];
    foreach ($T->tags as $i => $t) {
        if ($t['close'] || $t['name'] !== 'input' || strtolower($t['attrs']['type'] ?? '') !== 'radio') continue;
        $groups[$t['attrs']['name'] ?? ''][] = $i;
    }
    foreach ($groups as $name => $idx) {
        /* A radio rendered inside a foreach is one tag in the source but ≥2 in
           the page; treat any radio inside a php loop placeholder as a group. */
        $isGroup = count($idx) >= 2;
        if (!$isGroup) {
            $line = $T->tags[$idx[0]]['start'];
            $before = substr($T->src, max(0, $line - 600), 600);
            if (preg_match_all('/\{\{php:[0-9a-f]{8}\}\}/', $before, $mm)) {
                foreach ($mm[0] as $k) if (isset($T->php[$k]) && preg_match('/\b(foreach|for|while)\b/', $T->php[$k])) { $isGroup = true; }
            }
        }
        if (!$isGroup) continue;
        foreach ($idx as $i) {
            $fs = null;
            foreach ($anc[$i] as $p) if ($T->tags[$p]['name'] === 'fieldset') { $fs = $p; }
            if ($fs === null) { bad($file, $T->tags[$i]['line'], 'R9', "radio group \"$name\" not inside <fieldset>"); continue; }
            if (!preg_match('/<legend\b/i', $T->inner($fs))) bad($file, $T->tags[$i]['line'], 'R9', "radio group \"$name\" fieldset has no <legend>");
            else ok('R9');
        }
    }

    /* R10 — symbol-only text not hidden from AT. */
    $n = count($T->tags);
    for ($i = 0; $i < $n - 1; $i++) {
        $t = $T->tags[$i];
        if ($t['close'] || $t['void']) continue;
        if (in_array($t['name'], ['script', 'style', 'svg', 'path', 'title'], true)) continue;
        $next = $T->tags[$i + 1];
        $textRaw = substr($T->src, $t['end'], $next['start'] - $t['end']);
        if ($isJs) { $textRaw = trim($textRaw, " '+\n\r\t"); }
        $text = html_entity_decode($textRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($text) === '' || !preg_match($HAS_SYMBOL, $text) || !preg_match($SYMBOL_RE, $text)) continue;
        $a = $t['attrs'];
        if (($a['aria-hidden'] ?? '') === 'true' || isset($a['aria-label'])) { ok('R10'); continue; }
        // an ancestor hidden from AT covers it
        $hidden = false;
        foreach ($anc[$i] as $p) if (($T->tags[$p]['attrs']['aria-hidden'] ?? '') === 'true') { $hidden = true; break; }
        if ($hidden) { ok('R10'); continue; }
        $whole = Tpl::text(preg_replace('/\{\{php:[0-9a-f]{8}\}\}/', 'x', $T->inner($i)));
        if (preg_match('/\p{L}|\p{N}/u', $whole)) { ok('R10'); continue; }
        bad($file, $t['line'], 'R10', 'symbol-only content "' . trim($text) . '" without aria-hidden="true" or a text label');
    }
}

/* ------------------------------------------------------------------ *
 *  R13 — layout landmarks
 * ------------------------------------------------------------------ */
section('R13 layout: <main> and skip link');
$layout = $tpls['app/Views/layouts/app.php'];
if (preg_match('/<main\b/', $layout->src)) ok('R13'); else bad($layout->file, 1, 'R13', 'no <main> in layout');
if (preg_match('/<body\b[^>]*>([\s\S]*)/', $layout->src, $bm)
    && preg_match('/<(a|button|input|select|textarea)\b[^>]*>/i', $bm[1], $fm)
    && preg_match('/class="skip-link"/', $fm[0]) && preg_match('/href="#main"/', $fm[0])) ok('R13');
else bad($layout->file, 1, 'R13', 'first focusable child of <body> is not <a class="skip-link" href="#main">');

/* ------------------------------------------------------------------ *
 *  R14 — every page has an h1: bare pages (own <html>) carry their own;
 *  layout-rendered pages get it from the layout, which must have exactly one.
 * ------------------------------------------------------------------ */
section('R14 headings');
$layoutH1 = preg_match_all('/<h1\b/', $layout->src);
if ($layoutH1 === 1) ok('R14'); else bad($layout->file, 1, 'R14', "layout must contain exactly one <h1> (found $layoutH1)");
foreach ($tpls as $file => $T) {
    if (strpos($file, 'app/Views/pages/') !== 0) continue;
    $bare = (bool) preg_match('/<html\b/i', $T->src);
    if ($bare) {
        if (preg_match('/<h1\b/', $T->src)) ok('R14');
        else bad($file, 1, 'R14', 'bare page (renders its own <html>) has no <h1>');
    } else ok('R14');
}

/* ------------------------------------------------------------------ *
 *  R15 — outline:none only with a visible replacement on a focus rule
 * ------------------------------------------------------------------ */
section('R15 focus outlines (app.css)');
$css = file_get_contents($cssPath);
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
foreach ($blocks as $b) {
    $sel = $b[1][0]; $decl = $b[2][0];
    if (!preg_match('/outline\s*:\s*(none|0)\b/', $decl)) continue;
    $line = substr_count($css, "\n", 0, $b[0][1]) + 1;
    $focusRule = (bool) preg_match('/:focus(-visible)?\b/', $sel);
    $replacement = (bool) preg_match('/box-shadow\s*:|border(-color)?\s*:/', $decl);
    if ($focusRule && $replacement) ok('R15');
    else bad('public/assets/css/app.css', $line, 'R15', 'outline:none without a visible :focus-visible replacement in the same rule');
}

/* ------------------------------------------------------------------ *
 *  R16 — contrast of the token pairs actually used for normal text.
 *  Hard-coded and honest: each entry names the selector that uses it.
 *  Literal colours (panel gradients etc.) are asserted to still exist in
 *  app.css so the list cannot silently go stale.
 * ------------------------------------------------------------------ */
section('R16 contrast (WCAG 2.1 AA, 4.5:1)');
$tokens = [];
if (preg_match('/:root\s*\{([^}]*)\}/', $css, $rm)) {
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $rm[1], $tm, PREG_SET_ORDER);
    foreach ($tm as $t) $tokens[$t[1]] = trim($t[2]);
}
function rgb(string $c): array {
    $c = trim($c);
    if (preg_match('/^#([0-9a-f]{6})$/i', $c, $m)) return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), 1.0];
    if (preg_match('/^#([0-9a-f]{3})$/i', $c, $m)) return [hexdec($m[1][0] . $m[1][0]), hexdec($m[1][1] . $m[1][1]), hexdec($m[1][2] . $m[1][2]), 1.0];
    if (preg_match('/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+))?\s*\)$/', $c, $m)) return [(float) $m[1], (float) $m[2], (float) $m[3], isset($m[4]) ? (float) $m[4] : 1.0];
    throw new RuntimeException("cannot parse colour '$c'");
}
function col(string $c): array {
    global $tokens;
    if (strpos($c, 'var(') === 0) { $c = $tokens[trim(substr($c, 4, -1))] ?? ''; }
    return rgb($c);
}
/** Composite $top (may have alpha) over opaque $base. */
function over(array $top, array $base): array {
    $a = $top[3];
    return [$top[0] * $a + $base[0] * (1 - $a), $top[1] * $a + $base[1] * (1 - $a), $top[2] * $a + $base[2] * (1 - $a), 1.0];
}
function lum(array $c): float {
    $f = function (float $v): float { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
}
function contrast(array $fg, array $bg): float {
    $bg = over($bg, [0, 0, 0, 1]);   // bg pairs below are pre-composited; guard anyway
    $fg = over($fg, $bg);
    $l1 = lum($fg); $l2 = lum($bg);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
/* Literal surfaces used under text, with the selector that paints them.
   Each literal must still appear in app.css. */
$LIT = [
    'panel-top'   => ['#17223a', '.panel gradient top'],
    'panel-head'  => ['#26355a', '.panel__head gradient top'],
    'nav-btn'     => ['#2c3f66', '.nav-btn gradient top'],
    'sidebar'     => ['#0a1120', '.sidebar gradient top / .sigfield'],
    'btn-danger'  => ['#3a1523', '.btn--danger gradient top'],
];
foreach ($LIT as $k => [$hex, $where]) {
    if (stripos($css, $hex) === false) bad('public/assets/css/app.css', 1, 'R16', "literal $hex ($where) no longer in app.css — update the pair list");
}
$panel   = col($LIT['panel-top'][0]);
$phead   = col($LIT['panel-head'][0]);
$thead   = over(rgb('rgba(255,255,255,.015)'), $panel);
$topbar  = over(rgb('rgba(9,14,25,.78)'), col('var(--bg)'));
$tint    = fn(string $tok, float $alpha) => over(array_replace(col("var($tok)"), [3 => $alpha]), $panel);
/* Placeholder colour is a literal in .input::placeholder. */
preg_match('/\.input::placeholder[^{]*\{\s*color\s*:\s*([^;}]+)/', $css, $pm) or $pm = [1 => '#000'];
preg_match('/\.nav-btn__count\s*\{[^}]*color\s*:\s*([^;}]+)/', $css, $cm) or $cm = [1 => 'var(--text-dim)'];
$CONTRAST_PAIRS = [
    // label                                     foreground               background
    ['body text (--text on --bg)',              'var(--text)',            col('var(--bg)')],
    ['.field label / .kpi__label (--text-dim on panel)', 'var(--text-dim)', $panel],
    ['.panel__sub / .panel__head .tag (--text-dim on panel__head)', 'var(--text-dim)', $phead],
    ['.hint / .faint / .kv dt / .chain__step (--text-faint on panel)', 'var(--text-faint)', $panel],
    ['.tbl thead th (--text-faint on thead tint)', 'var(--text-faint)',    $thead],
    ['.topbar__crumb (--text-faint on topbar)', 'var(--text-faint)',      $topbar],
    ['.brand__sub / .sigsheet__rule::after (--text-faint on sidebar)', 'var(--text-faint)', col($LIT['sidebar'][0])],
    ['.input::placeholder on --surface-2',      trim($pm[1]),             col('var(--surface-2)')],
    ['a (--glow-a on panel)',                   'var(--glow-a)',          $panel],
    ['.nav-btn (--text on nav-btn)',            'var(--text)',            col($LIT['nav-btn'][0])],
    ['.nav-btn__count on its pill',             trim($cm[1]),             over(rgb('rgba(255,255,255,.08)'), col($LIT['nav-btn'][0]))],
    ['.badge--slate',                           'var(--slate)',           $tint('--slate', .12)],
    ['.badge--info',                            'var(--info)',            $tint('--info', .12)],
    ['.badge--success',                         'var(--ok)',              $tint('--ok', .12)],
    ['.badge--warn',                            'var(--warn)',            $tint('--warn', .12)],
    ['.badge--danger',                          'var(--danger)',          $tint('--danger', .12)],
    ['.badge--accent',                          'var(--accentpill)',      $tint('--accentpill', .12)],
    ['.hint--bad / .text-danger (--danger on panel)', 'var(--danger)',    $panel],
    ['.hint--warn (--warn on panel)',           'var(--warn)',            $panel],
    ['.text-ok / .chain__step.is-done (--ok on panel)', 'var(--ok)',      $panel],
    ['.alert--ok text',                         '#a8f0d0',                $tint('--ok', .08)],
    ['.alert--warn text',                       '#ffe0ab',                $tint('--warn', .08)],
    ['.alert--danger text',                     '#ffc3d1',                $tint('--danger', .08)],
    ['.alert--info text',                       '#bce4ff',                $tint('--info', .08)],
    ['.btn--ghost (--text-dim on panel)',       'var(--text-dim)',        $panel],
    ['.btn--danger text',                       '#ffdbe4',                col($LIT['btn-danger'][0])],
    ['.radio-card span (--text-dim on --surface-2)', 'var(--text-dim)',   col('var(--surface-2)')],
    ['.whoami (--text-dim on topbar)',          'var(--text-dim)',        $topbar],
    ['.pinmap__status (--text-dim on panel)',   'var(--text-dim)',        $panel],
    ['.pill-suggested (--glow-a on tint)',      'var(--glow-a)',          $tint('--glow-a', .12)],
    ['.fieldset > legend (--glow-a on panel)',  'var(--glow-a)',          $panel],
];
foreach ($CONTRAST_PAIRS as [$label, $fg, $bg]) {
    $r = contrast(col($fg), $bg);
    if ($r >= 4.5) ok('R16');
    else bad('public/assets/css/app.css', 1, 'R16', sprintf('%s = %.2f:1 (need 4.5)', $label, $r));
}

/* ------------------------------------------------------------------ *
 *  R17 — zoom never blocked
 * ------------------------------------------------------------------ */
section('R17 viewport');
foreach ($tpls as $file => $T) {
    if (preg_match('/<meta[^>]*name="viewport"[^>]*>/i', $T->src, $vm)) {
        if (preg_match('/user-scalable\s*=\s*no|maximum-scale/i', $vm[0])) bad($file, $T->lineAt(strpos($T->src, $vm[0])), 'R17', 'viewport blocks zoom');
        else ok('R17');
    }
}

/* ------------------------------------------------------------------ *
 *  Report
 * ------------------------------------------------------------------ */
echo "\n";
foreach ($LINES as $l) echo $l, "\n";
printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL ? 1 : 0);
