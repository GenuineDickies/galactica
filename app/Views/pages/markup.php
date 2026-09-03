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
 * Parts markup matrix editor.
 * @var array $tiers   rows with min_cost, max_cost (null = open), markup_pct
 * @var array $errors  validation messages, empty when the stored matrix is valid
 */
$fmt = fn ($v) => ($v === null || $v === '') ? '' : number_format((float) $v, 2, '.', '');
?>
<div class="panel mb4"><div class="panel__body">
  <div class="alert alert--info mb0"><div>
    Your cost is marked up to the customer price by the tier its cost falls into. The markup drops as parts get
    more expensive. Tiers must be contiguous — no gaps, no overlaps — and the top tier is open-ended
    (leave its <em>max</em> blank). A cost sitting exactly on a boundary uses the <strong>lower</strong> tier.
    <br><br>
    Editing this matrix never changes prices on quotes or invoices already created — each line snapshots its
    markup when it is added.
  </div></div>
</div></div>

<?php if ($errors): ?>
  <div class="alert alert--danger">
    <div><strong>The matrix wasn't saved:</strong>
      <ul style="margin:6px 0 0 18px">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<form method="post" action="<?= url('markup') ?>">
  <?= csrf_field() ?>
  <div class="panel">
    <div class="panel__head">
      <div><div class="panel__title">Cost tiers</div>
        <div class="panel__sub">My cost band → markup applied to reach the customer price.</div></div>
      <div class="topbar__spacer"></div>
      <span class="tag"><?= count($tiers) ?> tiers</span>
    </div>
    <div class="panel__body panel__body--flush">
      <div class="table-wrap"><table class="tbl" id="tierTable">
        <thead><tr>
          <th>Min cost</th><th>Max cost <span class="faint">(blank = open top)</span></th>
          <th class="right">Markup %</th><th style="width:44px"></th>
        </tr></thead>
        <tbody>
        <?php foreach (($tiers ?: [['min_cost'=>'0.00','max_cost'=>null,'markup_pct'=>'0.00']]) as $t): ?>
          <tr data-tier-row>
            <td><div class="row" style="gap:4px;align-items:center"><span class="faint">$</span>
              <input class="input" name="min_cost[]" type="number" step="0.01" min="0" style="max-width:120px"
                     value="<?= e($fmt($t['min_cost'] ?? '')) ?>"></div></td>
            <td><div class="row" style="gap:4px;align-items:center"><span class="faint">$</span>
              <input class="input" name="max_cost[]" type="number" step="0.01" min="0" style="max-width:120px"
                     placeholder="open" value="<?= e($fmt($t['max_cost'] ?? '')) ?>"></div></td>
            <td class="right"><div class="row" style="gap:4px;align-items:center;justify-content:flex-end">
              <input class="input right" name="markup_pct[]" type="number" step="0.01" min="0" style="max-width:100px"
                     value="<?= e($fmt($t['markup_pct'] ?? '')) ?>"><span class="faint">%</span></div></td>
            <td class="right"><button type="button" class="btn btn--ghost btn--sm" data-remove-tier title="Remove tier">✕</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <div class="panel__foot">
      <button type="button" class="btn btn--sm" data-add-tier>+ Add tier</button>
      <div class="topbar__spacer"></div>
      <button class="btn btn--primary">Save matrix</button>
    </div>
  </div>
</form>

<!-- Row template for the "Add tier" button (kept out of the form until cloned). -->
<template id="tierRowTemplate">
  <tr data-tier-row>
    <td><div class="row" style="gap:4px;align-items:center"><span class="faint">$</span>
      <input class="input" name="min_cost[]" type="number" step="0.01" min="0" style="max-width:120px"></div></td>
    <td><div class="row" style="gap:4px;align-items:center"><span class="faint">$</span>
      <input class="input" name="max_cost[]" type="number" step="0.01" min="0" style="max-width:120px" placeholder="open"></div></td>
    <td class="right"><div class="row" style="gap:4px;align-items:center;justify-content:flex-end">
      <input class="input right" name="markup_pct[]" type="number" step="0.01" min="0" style="max-width:100px"><span class="faint">%</span></div></td>
    <td class="right"><button type="button" class="btn btn--ghost btn--sm" data-remove-tier title="Remove tier">✕</button></td>
  </tr>
</template>
