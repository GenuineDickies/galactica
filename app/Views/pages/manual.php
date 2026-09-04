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
 * The manual, in the application. Contents on the left, prose on the right.
 * The HTML comes from Markdown::render — already escaped there, which is why
 * it is echoed raw here and nowhere else in this file is.
 */
?>
<?php if (!empty($missing)): ?>
  <div class="panel">
    <div class="panel__body">
      <div class="empty">
        <div class="empty__title">The manual is not on this install.</div>
        <p class="muted">
          <code>docs/MANUAL.md</code> is missing or unreadable. It ships with the
          application — if this is a deployed site, the docs folder was left out
          of the upload. Nothing else is affected.
        </p>
      </div>
    </div>
  </div>
<?php return; endif; ?>

<div class="row mb3" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <div class="text-lg strong">White Knight Roadside — Admin</div>
    <div class="muted text-sm">
      User manual · v<?= e($version) ?> · revised <?= e($revised) ?>
    </div>
  </div>
  <div class="row">
    <input class="input" id="manual_find" type="search" aria-label="Find in the manual" placeholder="Find in the manual…"
           style="min-width:220px" autocomplete="off">
    <a class="btn btn--ghost" href="<?= url('manual/print') ?>" target="_blank" rel="noopener">
      Print or save as PDF
    </a>
  </div>
</div>

<div class="manual-layout">
  <nav class="manual-toc panel" aria-label="Contents">
    <div class="panel__head"><div class="panel__title">Contents</div></div>
    <div class="panel__body">
      <ol class="manual-toc__list">
        <?php foreach ($toc as $t): ?>
          <li class="manual-toc__l<?= (int) $t['level'] ?>">
            <a href="#<?= e($t['id']) ?>"><?= e($t['text']) ?></a>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </nav>

  <article class="manual-body panel">
    <div class="panel__body prose" id="manual_prose">
      <?= $html /* pre-escaped by Markdown::render */ ?>
    </div>
  </article>
</div>

<script>
/* Filter the contents list as you type. Deliberately only the contents — a
   find that hid body text would let someone read half a rule and act on it. */
(function () {
  var box = document.getElementById('manual_find');
  if (!box) { return; }
  var items = Array.prototype.slice.call(
    document.querySelectorAll('.manual-toc__list li'));
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    items.forEach(function (li) {
      li.style.display = (q === '' || li.textContent.toLowerCase().indexOf(q) !== -1)
        ? '' : 'none';
    });
  });
})();
</script>
