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
declare(strict_types=1);

/**
 * A small Markdown renderer — enough for the manual, and nothing more.
 *
 * WHY THIS EXISTS. docs/MANUAL.md is the single source for the manual. The app
 * has no Composer and no build step, so there is no Parsedown to lean on and
 * nothing that could turn the markdown into HTML at deploy time. The choice was
 * to hand-maintain a second HTML copy of a 900-line document, or to render the
 * markdown at request time. A second copy drifts from the first within a month;
 * this is the cheaper mistake.
 *
 * WHAT IT DELIBERATELY IS NOT. This is not CommonMark and does not try to be.
 * It supports exactly the constructs the manual uses: ATX headings, GFM pipe
 * tables, bullet and ordered lists with one level of nesting, blockquotes,
 * horizontal rules, and inline code / bold / italic / links. Anything else
 * passes through as escaped text rather than being silently mangled. If the
 * manual ever needs a construct that is not here, add it here — do not hand-
 * write HTML into the markdown, because then the .md stops being readable on
 * its own and the single source has quietly become two.
 *
 * SAFETY. Every line is escaped BEFORE any formatting is applied, so raw HTML
 * in the source is displayed rather than executed. That is the right default
 * for a file that is edited as prose. It also means the markdown cannot embed
 * markup — see above, that is a feature.
 */
final class Markdown
{
    /** Inline code spans are lifted out before other inline rules run. */
    private array $codes = [];

    /** Collected headings, for building a table of contents. */
    private array $toc = [];

    /** Anchor slugs already issued, so duplicates get a suffix. */
    private array $seen = [];

    /**
     * Render markdown to HTML.
     *
     * @return array{html:string,toc:array<int,array{level:int,text:string,id:string}>}
     */
    public static function render(string $md): array
    {
        $self = new self();
        $html = $self->run($md);
        return ['html' => $html, 'toc' => $self->toc];
    }

    private function run(string $md): string
    {
        $md    = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $out   = [];
        $n     = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $trim = trim($line);

            /* Blank lines separate blocks and carry no meaning of their own. */
            if ($trim === '') { continue; }

            /* Horizontal rule. Checked before tables so a bare --- is not read
             * as a headerless table separator. */
            if (preg_match('/^-{3,}$/', $trim)) { $out[] = '<hr>'; continue; }

            /* ATX heading. */
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $out[] = $this->heading(strlen($m[1]), $m[2]);
                continue;
            }

            /* GFM pipe table: a row followed by a |---|---| separator. */
            if ($trim !== '' && $trim[0] === '|'
                && isset($lines[$i + 1])
                && preg_match('/^\|[\s:\-|]+\|$/', trim($lines[$i + 1]))) {
                $out[] = $this->table($lines, $i, $n);
                continue;
            }

            /* Blockquote — consecutive > lines become one quote. */
            if (str_starts_with($trim, '>')) {
                $buf = [];
                while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
                    $buf[] = ltrim(preg_replace('/^\s*>\s?/', '', $lines[$i]));
                    $i++;
                }
                $i--;
                $out[] = '<blockquote><p>' . $this->inline(implode(' ', $buf)) . '</p></blockquote>';
                continue;
            }

            /* Lists — bullet or ordered, one level of nesting. */
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
                $out[] = $this->list($lines, $i, $n);
                continue;
            }

            /* Anything else is a paragraph, running until a blank line or the
             * start of another block. */
            $buf = [];
            while ($i < $n) {
                $l = $lines[$i];
                $t = trim($l);
                if ($t === '' || $t[0] === '#' || $t[0] === '|' || $t[0] === '>'
                    || preg_match('/^-{3,}$/', $t)
                    || preg_match('/^(\s*)([-*]|\d+\.)\s+/', $l)) { break; }
                $buf[] = $t;
                $i++;
            }
            $i--;
            if ($buf) { $out[] = '<p>' . $this->inline(implode(' ', $buf)) . '</p>'; }
        }

        return implode("\n", $out);
    }

    /** A heading, with a stable anchor so the contents list can link to it. */
    private function heading(int $level, string $text): string
    {
        $id = $this->slug($text);
        if ($level >= 2 && $level <= 3) {
            $this->toc[] = ['level' => $level, 'text' => strip_tags($this->inline($text)), 'id' => $id];
        }
        return sprintf('<h%d id="%s">%s</h%d>', $level, e($id), $this->inline($text), $level);
    }

    /** URL-safe, human-readable, and unique within the document. */
    private function slug(string $text): string
    {
        $s = strtolower(strip_tags($this->inline($text)));
        $s = str_replace(['·', '—', '–', '"', '"', "'", "'"], ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') { $s = 'section'; }
        $base = $s; $k = 2;
        while (isset($this->seen[$s])) { $s = $base . '-' . $k; $k++; }
        $this->seen[$s] = true;
        return $s;
    }

    /**
     * A pipe table. The separator row's colons are honoured for alignment,
     * because the manual uses right-aligned money columns in places.
     *
     * @param array<int,string> $lines
     */
    private function table(array $lines, int &$i, int $n): string
    {
        $head  = $this->row($lines[$i]);
        $align = array_map(static function (string $c): string {
            $c = trim($c);
            $l = str_starts_with($c, ':'); $r = str_ends_with($c, ':');
            if ($l && $r) { return ' style="text-align:center"'; }
            if ($r)       { return ' style="text-align:right"'; }
            return '';
        }, $this->row($lines[$i + 1]));

        $i += 2;
        $body = [];
        while ($i < $n && str_starts_with(trim($lines[$i]), '|')) {
            $body[] = $this->row($lines[$i]);
            $i++;
        }
        $i--;

        $h = '';
        foreach ($head as $k => $c) {
            $h .= '<th' . ($align[$k] ?? '') . '>' . $this->inline(trim($c)) . '</th>';
        }

        $b = '';
        foreach ($body as $cells) {
            $b .= '<tr>';
            foreach ($cells as $k => $c) {
                $b .= '<td' . ($align[$k] ?? '') . '>' . $this->inline(trim($c)) . '</td>';
            }
            $b .= '</tr>';
        }

        /* .table-wrap / .tbl are the application's own table classes — the
         * manual uses the same furniture as every other screen rather than
         * introducing a second table style nobody maintains. */
        return '<div class="table-wrap"><table class="tbl"><thead><tr>' . $h
             . '</tr></thead><tbody>' . $b . '</tbody></table></div>';
    }

    /** Split a table row on unescaped pipes. @return array<int,string> */
    private function row(string $line): array
    {
        $line = trim(trim($line), '|');
        return array_map('trim', explode('|', $line));
    }

    /**
     * A list. Supports one level of nesting and lazy continuation lines, both
     * of which the manual uses — an intake step whose explanation wraps onto
     * the following indented line is one item, not two.
     *
     * @param array<int,string> $lines
     */
    private function list(array $lines, int &$i, int $n): string
    {
        preg_match('/^(\s*)([-*]|\d+\.)\s+/', $lines[$i], $m);
        $ordered = !in_array($m[2], ['-', '*'], true);
        $tag     = $ordered ? 'ol' : 'ul';
        $items   = [];      // each: ['text' => string, 'sub' => string[]]
        $cur     = null;

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);
            if ($trim === '') {
                /* A blank line ends the list unless the next line continues it. */
                $next = $lines[$i + 1] ?? '';
                if (!preg_match('/^(\s*)([-*]|\d+\.)\s+/', $next) && trim($next) !== '') { break; }
                if (trim($next) === '') { break; }
                $i++;
                continue;
            }

            /* A nested item: indented, and itself a list marker. */
            if (preg_match('/^\s{2,}([-*]|\d+\.)\s+(.*)$/', $line, $mm)) {
                if ($cur !== null) { $items[$cur]['sub'][] = $mm[2]; $i++; continue; }
            }

            /* A new item at this level. */
            if (preg_match('/^(\s{0,1})([-*]|\d+\.)\s+(.*)$/', $line, $mm)) {
                $items[] = ['text' => $mm[3], 'sub' => []];
                $cur     = count($items) - 1;
                $i++;
                continue;
            }

            /* An indented, non-marker line continues the item above it. */
            if ($cur !== null && preg_match('/^\s+\S/', $line)) {
                $items[$cur]['text'] .= ' ' . $trim;
                $i++;
                continue;
            }

            break;
        }
        $i--;

        $html = '<' . $tag . '>';
        foreach ($items as $it) {
            $html .= '<li>' . $this->inline($it['text']);
            if ($it['sub']) {
                $html .= '<ul>';
                foreach ($it['sub'] as $s) { $html .= '<li>' . $this->inline($s) . '</li>'; }
                $html .= '</ul>';
            }
            $html .= '</li>';
        }
        return $html . '</' . $tag . '>';
    }

    /**
     * Inline formatting. Escaping happens FIRST and code spans are lifted out
     * before anything else runs, so a literal asterisk inside `code` is never
     * read as emphasis.
     */
    private function inline(string $text): string
    {
        $this->codes = [];

        /* Lift code spans out, leaving a placeholder that cannot occur in the
         * source because the source has already been through nothing yet — the
         * marker uses a control character for exactly that reason. */
        $text = preg_replace_callback('/`([^`]+)`/', function (array $m): string {
            $this->codes[] = $m[1];
            return "\x00" . (count($this->codes) - 1) . "\x00";
        }, $text) ?? $text;

        $text = e($text);

        /* Links: [label](target). Only http(s), mailto and in-document anchors
         * are allowed through — a manual has no business emitting javascript:. */
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static function (array $m): string {
                $href = $m[2];
                if (!preg_match('~^(https?://|mailto:|#|/)~i', $href)) { return $m[1]; }
                $ext = str_starts_with($href, 'http');
                return '<a href="' . e($href) . '"'
                     . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                     . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        /* Bold before italic, so **x** is not read as *(*x*)*. */
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;

        /* Put the code spans back, escaped. */
        $text = preg_replace_callback('/\x00(\d+)\x00/', function (array $m): string {
            return '<code>' . e($this->codes[(int) $m[1]] ?? '') . '</code>';
        }, $text) ?? $text;

        return $text;
    }
}
