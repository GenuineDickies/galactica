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
 * The user manual, served from the same file the repository documents.
 *
 * WHY A ROUTE AND NOT A PDF IN A FOLDER. The manual is written for dispatchers
 * and technicians. A markdown file in docs/ is reachable by neither: nobody
 * working a call opens the repository. Serving it here means the manual is one
 * click from the screen it describes, and it is never out of date relative to
 * the deployed code, because it IS the deployed file.
 *
 * WHY EVERY ROLE. A technician needs section 6 more than an admin does. The
 * manual documents refusals as much as features — knowing why the system said
 * no is what stops someone trying to work around it.
 *
 * The print view is deliberately a separate route rather than a stylesheet on
 * this one: it opens without the application chrome, adds a cover page, and is
 * what "Print or save as PDF" acts on. Same source, different presentation.
 */
final class ManualController
{
    /** The manual lives at the repository root, not under public/. */
    private static function path(): string
    {
        return dirname(__DIR__, 2) . '/docs/MANUAL.md';
    }

    /**
     * Read and render. Returns null when the file is missing, which is a real
     * possibility on a partial deploy and should say so plainly rather than
     * rendering an empty page that looks like a manual with no content.
     *
     * @return array{html:string,toc:array,version:string,revised:string}|null
     */
    private static function load(): ?array
    {
        $file = self::path();
        if (!is_file($file) || !is_readable($file)) { return null; }
        $md = (string) file_get_contents($file);
        if (trim($md) === '') { return null; }

        $r = Markdown::render($md);
        return [
            'html'    => $r['html'],
            'toc'     => $r['toc'],
            'version' => (string) (App::config('app')['version'] ?? ''),
            /* The file's own mtime is the revision date. It cannot drift from
             * the content the way a hand-typed date in the header would. */
            'revised' => date('F j, Y', (int) filemtime($file)),
        ];
    }

    public static function index(): void
    {
        Auth::require();

        $m = self::load();
        if ($m === null) {
            View::render('pages/manual', [
                'title' => 'User manual',
                'crumb' => 'Help',
                'nav'   => 'manual',
                'missing' => true,
            ]);
            return;
        }

        View::render('pages/manual', [
            'title'   => 'User manual',
            'crumb'   => 'Help',
            'nav'     => 'manual',
            'missing' => false,
        ] + $m);
    }

    /**
     * Bare, cover page, print stylesheet. The thing you hand someone.
     * Named `printable` rather than `print` — the latter is a language
     * construct, and a method name that only works because of a lexer nicety
     * is a poor thing to build a route on.
     */
    public static function printable(): void
    {
        Auth::require();

        $m = self::load();
        if ($m === null) {
            http_response_code(404);
            echo 'The manual file is not present on this install.';
            return;
        }

        View::render('pages/manual_print', [
            '__bare' => true,
            'title'  => 'User manual',
        ] + $m);
    }
}
