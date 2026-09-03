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
 * The printable manual. Same markdown as /manual, different presentation:
 * cover page, contents, light background, page breaks that fall between
 * sections instead of through them.
 *
 * Light rather than the application's dark theme on purpose — the screen theme
 * would put a full page of ink on every sheet, and this is meant to be printed.
 */
$co = [
    'name'  => App::setting('company_name',  App::config('company')['name']),
    'phone' => App::setting('company_phone', App::config('company')['phone']),
    'email' => App::setting('company_email', App::config('company')['email']),
];
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($co['name']) ?> — User Manual</title>
<style>
  :root{
    --ink:#12161d; --dim:#4a5568; --faint:#78839a;
    --rule:#d9dee7; --rule-soft:#eceff4; --brand:#1b3ca8; --wash:#f6f8fb;
    --serif: Georgia, "Iowan Old Style", "Times New Roman", serif;
    --sans: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    --mono: ui-monospace, SFMono-Regular, Consolas, Menlo, monospace;
  }
  *{ box-sizing:border-box; }
  html,body{ margin:0; padding:0; background:#e9edf3; color:var(--ink); }
  body{ font-family:var(--serif); font-size:11.6pt; line-height:1.62; }

  .sheet{ max-width:7.6in; margin:0 auto; background:#fff; padding:0.85in 0.95in; }

  /* ---- Toolbar (screen only) --------------------------------------- */
  .bar{
    position:sticky; top:0; z-index:5; display:flex; gap:10px; justify-content:flex-end;
    background:#fff; border-bottom:1px solid var(--rule); padding:10px 14px;
    font-family:var(--sans); font-size:13px;
  }
  .bar button{
    font:inherit; padding:7px 14px; border-radius:8px; cursor:pointer;
    border:1px solid var(--rule); background:#fff; color:var(--ink);
  }
  .bar button.primary{ background:var(--brand); border-color:var(--brand); color:#fff; }

  /* ---- Cover -------------------------------------------------------- */
  .cover{ min-height:8.4in; display:flex; flex-direction:column; justify-content:center; }
  .cover__mark{
    width:60px; height:60px; border-radius:16px; background:var(--brand); color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-family:var(--sans); font-weight:800; font-size:21px; letter-spacing:.03em;
    margin-bottom:34px;
  }
  .cover__co{ font-family:var(--sans); font-size:12pt; font-weight:700; letter-spacing:.03em; }
  .cover__title{ font-size:31pt; line-height:1.12; font-weight:400; margin:10px 0 6px; }
  .cover__sub{ font-size:13pt; color:var(--dim); font-style:italic; margin-bottom:40px; }
  .cover__meta{
    font-family:var(--sans); font-size:9.5pt; color:var(--faint);
    border-top:1px solid var(--rule); padding-top:14px; line-height:1.9;
  }

  /* ---- Contents ------------------------------------------------------ */
  .contents{ page-break-before:always; }
  .contents h2{ font-family:var(--sans); font-size:13pt; letter-spacing:.02em; margin:0 0 18px; }
  .contents ol{ list-style:none; margin:0; padding:0; font-family:var(--sans); font-size:10.5pt; }
  .contents li{ padding:5px 0; border-bottom:1px dotted var(--rule-soft); }
  .contents li.l3{ padding-left:22px; color:var(--dim); font-size:10pt; border-bottom:none; }
  .contents a{ color:inherit; text-decoration:none; }

  /* ---- Prose --------------------------------------------------------- */
  .prose{ page-break-before:always; }
  .prose h1{ font-size:22pt; font-weight:400; margin:0 0 4px; }
  .prose h2{
    font-family:var(--sans); font-size:14pt; font-weight:700; letter-spacing:-.01em;
    margin:34px 0 12px; padding-bottom:7px; border-bottom:2px solid var(--brand);
    page-break-after:avoid; page-break-before:auto;
  }
  .prose h3{
    font-family:var(--sans); font-size:11.5pt; font-weight:700; color:var(--brand);
    margin:22px 0 8px; page-break-after:avoid;
  }
  .prose p{ margin:0 0 11px; }
  .prose ul,.prose ol{ margin:0 0 12px; padding-left:22px; }
  .prose li{ margin-bottom:5px; }
  .prose li>ul{ margin:6px 0 4px; }
  .prose strong{ font-weight:700; }
  .prose code{
    font-family:var(--mono); font-size:.86em; background:var(--wash);
    border:1px solid var(--rule-soft); border-radius:4px; padding:1px 4px;
  }
  .prose a{ color:var(--brand); }
  .prose hr{ border:0; border-top:1px solid var(--rule-soft); margin:26px 0; }
  .prose blockquote{
    margin:14px 0; padding:11px 16px; background:var(--wash);
    border-left:3px solid var(--brand); font-style:italic;
  }
  .prose blockquote p{ margin:0; }

  .table-wrap{ margin:0 0 14px; }
  table.tbl{
    width:100%; border-collapse:collapse; font-family:var(--sans);
    font-size:9.5pt; page-break-inside:avoid;
  }
  .tbl th{
    text-align:left; font-size:8.4pt; text-transform:uppercase; letter-spacing:.08em;
    color:var(--faint); border-bottom:1.5px solid var(--rule); padding:7px 9px;
  }
  .tbl td{ border-bottom:1px solid var(--rule-soft); padding:7px 9px; vertical-align:top; }
  .tbl tr:last-child td{ border-bottom:none; }

  /* ---- Print --------------------------------------------------------- */
  @page{ size:letter; margin:0.75in 0.8in 0.8in; }
  @media print{
    html,body{ background:#fff; }
    .bar{ display:none; }
    .sheet{ max-width:none; margin:0; padding:0; }
    .cover{ min-height:8.6in; page-break-after:always; }
    a{ color:inherit; text-decoration:none; }
    /* Keep a heading with the text it introduces, and never split a table
       row across a page — a rule cut in half is a rule misread. */
    h2,h3{ page-break-after:avoid; }
    tr,li,blockquote{ page-break-inside:avoid; }
    p{ orphans:3; widows:3; }
  }
</style>
</head><body>

<div class="bar">
  <button class="primary" onclick="window.print()">Print or save as PDF</button>
  <button onclick="window.close()">Close</button>
</div>

<div class="sheet">

  <section class="cover">
    <div class="cover__mark">WK</div>
    <div class="cover__co"><?= e($co['name']) ?></div>
    <h1 class="cover__title">User Manual</h1>
    <div class="cover__sub">Dispatch to cash — taking the call through getting paid.</div>
    <div class="cover__meta">
      Version <?= e($version) ?> · Revised <?= e($revised) ?><br>
      <?= e($co['phone']) ?> · <?= e($co['email']) ?><br>
      Confidential — for authorized users of this system.
    </div>
  </section>

  <section class="contents">
    <h2>Contents</h2>
    <ol>
      <?php foreach ($toc as $t): ?>
        <li class="l<?= (int) $t['level'] ?>"><a href="#<?= e($t['id']) ?>"><?= e($t['text']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <article class="prose">
    <?= $html /* pre-escaped by Markdown::render */ ?>
  </article>

</div>
</body></html>
