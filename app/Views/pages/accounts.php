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
 * Chart of accounts.
 * @var array $rows   every gl_account, retired included, ordered by number
 * @var array $usage  account_number => count of catalog items referencing it
 */
$labels = [
    'ASSET' => 'Assets', 'LIABILITY' => 'Liabilities', 'EQUITY' => 'Equity',
    'REVENUE' => 'Revenue', 'COGS' => 'Cost of Goods Sold', 'EXPENSE' => 'Expenses',
];
$groups = [];
foreach ($rows as $r) { $groups[$r['account_type']][] = $r; }
?>
<div class="panel mb4"><div class="panel__body">
  <div class="alert alert--info mb0"><div>
    The account numbers your catalog items post to. Fix a typo'd <em>name</em> with Rename — the number is
    the identity and does not change. <strong>Retire</strong> takes an account out of the pickers but keeps
    it on screen and on anything already using it; that is what you want once an account has real history
    behind it. <strong>Delete</strong> removes it outright, for an account that was simply a mistake.
    Posted journal lines snapshot the number, name and type, so deleting never makes past entries
    unreadable.
  </div></div>
</div></div>

<?php foreach ($labels as $type => $label):
  if (empty($groups[$type])) continue; ?>
<div class="panel mb4">
  <div class="panel__head"><div class="panel__title"><?= e($label) ?></div>
    <div class="topbar__spacer"></div><span class="tag"><?= count($groups[$type]) ?> accounts</span></div>
  <div class="panel__body panel__body--flush">
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th style="width:90px">Number</th><th>Name</th><th class="right">Used by</th><th style="width:270px"></th></tr></thead>
      <tbody>
      <?php foreach ($groups[$type] as $r):
        $n = (int) ($usage[$r['account_number']] ?? 0); ?>
        <tr style="<?= (int) $r['is_active'] === 0 ? 'opacity:.45' : '' ?>">
          <td class="docno strong"><?= e($r['account_number']) ?></td>
          <td>
            <form method="post" action="<?= url('accounts/' . $r['id'] . '/rename') ?>" class="row" style="gap:6px">
              <?= csrf_field() ?>
              <input class="input" name="name" value="<?= e($r['name']) ?>" required>
              <button class="btn btn--ghost btn--sm">Rename</button>
            </form>
          </td>
          <td class="right muted"><?= $n > 0 ? $n . ' item' . ($n === 1 ? '' : 's') : '—' ?></td>
          <td class="right">
            <?php if ((int) $r['is_active'] === 0): ?><span class="badge badge--danger" style="margin-right:6px"><i></i>Retired</span><?php endif; ?>
            <form method="post" action="<?= url('accounts/' . $r['id'] . '/toggle') ?>" style="display:inline"><?= csrf_field() ?>
              <button class="btn btn--ghost btn--sm"
                <?php if ((int) $r['is_active'] === 1 && $n > 0): ?>
                  data-confirm="Retire account <?= e($r['account_number']) ?>? It is used by <?= $n ?> catalog item<?= $n === 1 ? '' : 's' ?> — they keep the number, but it leaves the pickers."
                <?php endif; ?>
              ><?= (int) $r['is_active'] === 1 ? 'Retire' : 'Restore' ?></button>
            </form>
            <form method="post" action="<?= url('accounts/' . $r['id'] . '/delete') ?>" style="display:inline"><?= csrf_field() ?>
              <button class="btn btn--ghost btn--sm text-danger"
                data-confirm="Delete account <?= e($r['account_number']) ?> <?= e($r['name']) ?>?<?php
                  if ($n > 0): ?> It is used by <?= $n ?> catalog item<?= $n === 1 ? '' : 's' ?>, which keep the number as text.<?php endif; ?> Posted journal lines are unaffected — they carry their own copy. This removes the row."
              >Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="panel__head"><div class="panel__title">Add account</div></div>
  <div class="panel__body">
    <form method="post" action="<?= url('accounts') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label class="req">Number</label>
          <input class="input" name="account_number" placeholder="4050" required>
          <div class="hint">1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx revenue · 5xxx COGS · 6xxx+ expenses.</div></div>
        <div class="field"><label class="req">Name</label>
          <input class="input" name="name" placeholder="Towing Revenue" required></div>
        <div class="field"><label>Type</label>
          <select class="select" name="account_type">
            <?php foreach ($labels as $type => $label): ?>
              <option value="<?= e($type) ?>" <?= $type === 'REVENUE' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <button class="btn btn--primary">Add account</button>
    </form>
  </div>
</div>
