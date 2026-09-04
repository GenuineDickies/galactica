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
 * Pending schema changes.
 * @var array  $pending table => [column => declaration]; empty array = table absent
 * @var string $driver
 * @var int    $columns count of columns that would be added
 * @var array  $tables  names of tables that do not exist at all
 * @var array  $indexes [name, table, columns, unique] declared but not present
 */
$anything = $pending || $indexes;
?>
<div class="panel mb4"><div class="panel__body">
  <div class="alert alert--info mb0" role="status"><div>
    What the deployed code expects, compared with what this database actually has.
    Applying only ever <strong>adds</strong> — missing tables are created and missing columns
    appended. Nothing is dropped, renamed or retyped, and existing rows are untouched.
    One click here applies them — no server access needed after a deploy.
  </div></div>
</div></div>

<div class="panel">
  <div class="panel__head">
    <div>
      <div class="panel__title">Pending changes</div>
      <div class="panel__sub"><?= e($driver) ?> · <?= count($pending) ?> table<?= count($pending) === 1 ? '' : 's' ?> affected<?= $indexes ? ' · ' . count($indexes) . ' index' . (count($indexes) === 1 ? '' : 'es') : '' ?></div>
    </div>
    <div class="topbar__spacer"></div>
    <?php if ($pending): ?><span class="badge badge--warn"><i></i><?= (int) $columns ?> column<?= (int) $columns === 1 ? '' : 's' ?></span><?php endif; ?>
  </div>
  <div class="panel__body<?= $pending ? ' panel__body--flush' : '' ?>">

<?php if (!$anything): ?>
  <div class="empty">
    <div class="empty__icon" aria-hidden="true">✓</div>
    <div class="empty__title">Schema is up to date</div>
    <div class="empty__body">Every table and column the running code expects is present in this database.</div>
  </div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th scope="col">Table</th><th scope="col">Column</th><th scope="col">Definition</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $table => $cols): ?>
      <?php if ($cols === []): ?>
        <tr>
          <td class="strong"><?= e($table) ?></td>
          <td colspan="2"><span class="badge badge--warn"><i></i>Table does not exist</span>
            <span class="muted text-sm ml2">it will be created</span></td>
        </tr>
      <?php else: ?>
        <?php $first = true; foreach ($cols as $name => $decl): ?>
          <tr>
            <td class="strong"><?= $first ? e($table) : '' ?></td>
            <td class="docno"><?= e($name) ?></td>
            <td class="muted text-sm"><?= e($decl) ?></td>
          </tr>
        <?php $first = false; endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

<?php if ($indexes): ?>
  <?php /* Shown separately because a missing index is a different kind of
           problem from a missing column: nothing errors, a query just goes
           full-scan. Indexes declared for a table that already existed were
           never created at all until Db::addMissingIndexes was written. */ ?>
  <div class="table-wrap mt4"><table class="tbl">
    <thead><tr><th scope="col">Index</th><th scope="col">Table</th><th scope="col">Columns</th><th scope="col">Kind</th></tr></thead>
    <tbody>
    <?php foreach ($indexes as [$iname, $itable, $icols, $iuniq]): ?>
      <tr>
        <td class="docno"><?= e($iname) ?></td>
        <td class="strong"><?= e($itable) ?></td>
        <td class="muted text-sm"><?= e($icols) ?></td>
        <td><?= $iuniq ? '<span class="badge badge--accent"><i></i>unique</span>' : '<span class="muted text-sm">index</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

  </div>
<?php if ($anything): ?>
  <div class="panel__body">
    <form method="post" action="<?= url('admin/schema') ?>">
      <?= csrf_field() ?>
      <button class="btn btn--primary"
              data-confirm="Add <?= (int) $columns ?> column(s)<?= $tables ? ' and create ' . count($tables) . ' table(s)' : '' ?><?= $indexes ? ' and ' . count($indexes) . ' index(es)' : '' ?> to this database? Nothing will be dropped or changed.">
        Apply these changes
      </button>
      <div class="hint mt2">
        Safe to run more than once — anything already present is skipped. The change is written to the audit trail.
      </div>
    </form>
  </div>
<?php endif; ?>
</div>
