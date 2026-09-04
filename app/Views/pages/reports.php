<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php $profit = $revenue - $expense; ?>
<div class="panel mb4"><div class="panel__body">
  <form method="get" class="row wrap">
    <div class="field mb0"><label for="from">From</label><input class="input" type="date" name="from" value="<?= e($from) ?>" id="from"></div>
    <div class="field mb0"><label for="to">To</label><input class="input" type="date" name="to" value="<?= e($to) ?>" id="to"></div>
    <button class="btn" style="margin-top:18px">Run</button>
  </form>
</div></div>

<div class="grid grid--kpi mb4">
  <div class="panel kpi"><div class="kpi__label">Cash collected</div><div class="kpi__value"><?= money($revenue) ?></div>
    <div class="kpi__meta">plus <?= money($tips) ?> in tips</div></div>
  <div class="panel kpi"><div class="kpi__label">Expenses</div><div class="kpi__value"><?= money($expense) ?></div></div>
  <div class="panel kpi"><div class="kpi__label">Net</div>
    <div class="kpi__value" style="color:<?= $profit >= 0 ? 'var(--ok)' : 'var(--danger)' ?>"><?= money($profit) ?></div></div>
  <div class="panel kpi"><div class="kpi__label">Receivable now</div><div class="kpi__value"><?= money($ar) ?></div>
    <div class="kpi__meta"><?= count($unpaid) ?> open invoice(s)</div></div>
</div>

<div class="split">
  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Revenue by item</div>
        <div class="topbar__spacer"></div><span class="tag">Invoiced in range</span></div>
      <div class="panel__body panel__body--flush">
      <?php if (!$byService): ?><div class="panel__body muted text-sm">Nothing invoiced in this range.</div>
      <?php else: ?>
        <table class="tbl"><thead><tr><th scope="col">Item</th><th scope="col">Type</th><th class="right" scope="col">Count</th><th class="right" scope="col">Revenue</th><th class="right" scope="col">Cost</th><th class="right" scope="col">Margin</th></tr></thead><tbody>
        <?php foreach ($byService as $r):
          $m = (float) $r['revenue'] > 0 ? ((float) $r['revenue'] - (float) $r['cost']) / (float) $r['revenue'] * 100 : 0; ?>
          <tr><td class="strong"><?= e($r['name']) ?></td>
            <td><span class="badge badge--<?= $r['item_type'] === 'PART' ? 'accent' : ($r['item_type'] === 'FEE' ? 'warn' : 'info') ?>"><i></i><?= e($r['item_type']) ?></span></td>
            <td class="right num"><?= (int) $r['n'] ?></td>
            <td class="right num strong"><?= money($r['revenue']) ?></td>
            <td class="right num muted"><?= money($r['cost']) ?></td>
            <td class="right num"><?= number_format($m, 0) ?>%</td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Open receivables</div></div>
      <div class="panel__body panel__body--flush">
      <?php if (!$unpaid): ?><div class="panel__body muted text-sm">Everything issued has been paid.</div>
      <?php else: ?>
        <table class="tbl"><thead><tr><th scope="col">Invoice</th><th scope="col">Customer</th><th scope="col">Due</th><th class="right" scope="col">Balance</th></tr></thead><tbody>
        <?php foreach ($unpaid as $r): $late = $r['due_at'] && strtotime($r['due_at']) < time(); ?>
          <tr data-href="<?= url('invoices/' . $r['id']) ?>">
            <td class="docno"><a class="row-link" href="<?= url('invoices/' . $r['id']) ?>"><?= e($r['doc_number']) ?></a></td>
            <td class="strong"><?= e(customer_name($r, true)) ?></td>
            <td class="<?= $late ? '' : 'muted' ?>"><?= e(fdate($r['due_at'])) ?> <?= $late ? '<span class="badge badge--danger"><i></i>Overdue</span>' : '' ?></td>
            <td class="right num strong"><?= money($r['balance_due']) ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">By job source</div></div>
      <div class="panel__body">
      <?php if (!$bySource): ?><div class="muted text-sm">No invoiced jobs in this range.</div>
      <?php else: foreach ($bySource as $r): ?>
        <div class="row row--between" style="padding:8px 0;border-bottom:1px solid var(--line)">
          <div><div class="strong"><?= $r['job_source'] === 'PROVIDER' ? 'Provider / bulk' : 'Retail' ?></div>
            <div class="text-sm faint"><?= (int) $r['jobs'] ?> job(s)</div></div>
          <div class="strong"><?= money($r['revenue']) ?></div>
        </div>
      <?php endforeach; endif; ?>
      <div class="hint">Retail keeps the full ticket. Provider work trades margin for volume — watch this split.</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">By payment method</div></div>
      <div class="panel__body">
      <?php if (!$byMethod): ?><div class="muted text-sm">No payments in this range.</div>
      <?php else: foreach ($byMethod as $r): ?>
        <div class="row row--between" style="padding:8px 0;border-bottom:1px solid var(--line)">
          <div><div class="strong"><?= e(status_label($r['method'])) ?></div>
            <div class="text-sm faint"><?= (int) $r['n'] ?> payment(s)</div></div>
          <div class="strong"><?= money($r['total']) ?></div>
        </div>
      <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
