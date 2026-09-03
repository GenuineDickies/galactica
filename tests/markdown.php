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
 * Unit tests for Markdown. Pure — no database, no server.
 *   php tests/markdown.php
 *
 * The renderer exists so docs/MANUAL.md can stay the single source for the
 * manual. Two things therefore have to hold, and they are what this file is
 * mostly about: markup in the source must be shown rather than executed, and
 * the constructs the manual actually uses must survive the trip. The last
 * section renders the real manual and asserts on the result, so a construct
 * added to the manual that this renderer cannot handle fails here rather than
 * silently degrading on the page.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';
require dirname(__DIR__) . '/app/Markdown.php';

$PASS = 0; $FAIL = 0;
function check(string $label, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s\n    want %s\n    got  %s\n",
        $label, var_export($want, true), var_export($got, true)); }
}
function contains(string $label, string $hay, string $needle): void {
    global $PASS, $FAIL;
    if (str_contains($hay, $needle)) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s\n    %s\n    not in: %s\n",
        $label, $needle, substr($hay, 0, 300)); }
}
function lacks(string $label, string $hay, string $needle): void {
    global $PASS, $FAIL;
    if (!str_contains($hay, $needle)) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $label); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s — found %s\n", $label, $needle); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }
function md(string $s): string { return Markdown::render($s)['html']; }

section('markup in the source is shown, never executed');
lacks('script tag is not emitted', md('<script>alert(1)</script>'), '<script>');
contains('script tag is displayed', md('<script>alert(1)</script>'), '&lt;script&gt;');
lacks('img onerror is not emitted', md('<img src=x onerror=alert(1)>'), '<img');
lacks('javascript: link is not linked', md('[click](javascript:alert(1))'), '<a ');
contains('javascript: link keeps its label', md('[click](javascript:alert(1))'), 'click');
lacks('code span cannot smuggle markup', md('`<b>x</b>`'), '<b>');

section('links');
contains('http link', md('[a](https://x.test)'), '<a href="https://x.test"');
contains('external link is safe-rel', md('[a](https://x.test)'), 'rel="noopener noreferrer"');
contains('anchor link', md('[a](#s)'), '<a href="#s"');
lacks('anchor link is not treated as external', md('[a](#s)'), 'target');

section('inline');
check('bold', md('**x**'), '<p><strong>x</strong></p>');
check('italic', md('*x*'), '<p><em>x</em></p>');
contains('bold wins over italic', md('**x**'), '<strong>x</strong>');
lacks('bold is not double-wrapped', md('**x**'), '<em>');
contains('code span', md('`a:b`'), '<code>a:b</code>');
contains('asterisk inside code is literal', md('`a*b*c`'), '<code>a*b*c</code>');
contains('bold containing quotes', md('**"Roll as"**'), '<strong>&quot;Roll as&quot;</strong>');

section('blocks');
contains('h2 renders', md('## Title'), '<h2');
contains('h2 gets an anchor', md('## Title'), 'id="title"');
contains('hr renders', md('---'), '<hr>');
contains('blockquote renders', md('> quoted'), '<blockquote><p>quoted</p></blockquote>');
contains('bullet list', md("- one\n- two"), '<ul><li>one</li><li>two</li></ul>');
contains('ordered list', md("1. one\n2. two"), '<ol><li>one</li><li>two</li></ol>');
contains('nested bullet', md("- parent\n  - child"), '<ul><li>child</li></ul>');
contains('lazy continuation joins the item',
    md("1. first line\n   second line"), '<li>first line second line</li>');

section('duplicate headings get distinct anchors');
$dupe = md("## Same\n\n## Same");
contains('first anchor',  $dupe, 'id="same"');
contains('second anchor', $dupe, 'id="same-2"');

section('tables');
$t = md("| A | B |\n|---|---|\n| 1 | 2 |");
contains('table uses the app table classes', $t, '<table class="tbl">');
contains('header cell', $t, '<th>A</th>');
contains('body cell',   $t, '<td>1</td>');
contains('right alignment honoured',
    md("| A | B |\n|---|--:|\n| 1 | 2 |"), 'style="text-align:right"');
contains('inline formatting inside a cell',
    md("| A |\n|---|\n| **b** |"), '<td><strong>b</strong></td>');
$hr = md("Text\n\n---\n\nMore");
contains('bare --- after a paragraph is a rule, not a table', $hr, '<hr>');

section('the real manual survives the renderer');
$file = dirname(__DIR__) . '/docs/MANUAL.md';
if (!is_file($file)) {
    printf("  \033[33mSKIP\033[0m docs/MANUAL.md not present\n");
} else {
    $r    = Markdown::render((string) file_get_contents($file));
    $html = $r['html'];

    check('contents list is not empty', count($r['toc']) > 0, true);
    contains('section 3a is present', $html, 'Choosing the category');
    contains('the demount table rendered', $html, '<table class="tbl">');
    contains('the chain blockquote rendered', $html, '<blockquote>');
    lacks('no unconverted table pipes leaked into a paragraph', $html, '<p>|');
    lacks('no unconverted heading leaked', $html, '<p>## ');
    lacks('no unconverted bullet leaked', $html, '<p>- ');
    lacks('no stray code placeholder survived', $html, "\x00");

    /* Every contents entry must point at a heading that exists, or the
     * contents list is a set of dead links. */
    $bad = [];
    foreach ($r['toc'] as $t) {
        if (!str_contains($html, 'id="' . $t['id'] . '"')) { $bad[] = $t['id']; }
    }
    check('every contents entry resolves to a heading', $bad, []);

    /* Escaping is the one thing that must hold across the whole document. */
    lacks('no script tag anywhere in the rendered manual', $html, '<script');
    lacks('no inline event handler anywhere', $html, 'onerror=');
}

printf("\n%d passed, %d failed\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
