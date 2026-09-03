<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="grid grid--kpi mb4">
  <div class="panel kpi"><div class="kpi__label">Collected today</div><div class="kpi__value"><?= money($totals['today']) ?></div></div>
  <div class="panel kpi"><div class="kpi__label">Collected this month</div><div class="kpi__value"><?= money($totals['month']) ?></div></div>
  <div class="panel kpi"><div class="kpi__label">Tips this month</div><div class="kpi__value"><?= money($totals['tips']) ?></div>
    <div class="kpi__meta">Tracked separately, not taxable</div></div>
</div>
<?php /* Extra money that arrived without a tip label sits on 2060 until a
        person says what it was. One click either way; the ledger entry and
        the status flip happen together server-side. */
$held = array_filter($rows, fn ($r) => ($r['overpayment_status'] ?? null) === 'HELD'); ?>
<?php if ($held): ?>
<div class="panel mb4">
  <div class="panel__head"><div class="panel__title">Overpayments to verify</div><div class="topbar__spacer"></div><span class="tag"><?= count($held) ?></span></div>
  <div class="panel__body panel__body--flush">
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Payment</th><th>Invoice</th><th>Customer</th><th class="right">Extra received</th><th class="right">What was it?</th></tr></thead>
      <tbody>
      <?php foreach ($held as $r): ?>
        <tr>
          <td class="docno"><?= e($r['doc_number']) ?></td>
          <td class="docno faint"><?= e($r['inv_no']) ?></td>
          <td class="strong"><?= e(customer_name($r, true)) ?></td>
          <td class="right num strong"><?= money($r['overpayment_amount']) ?></td>
          <td class="right nowrap">
            <form method="post" action="<?= url('payments/' . (int) $r['id'] . '/overpayment') ?>" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="resolve" value="tip">
              <button class="btn btn--sm">It was a tip</button>
            </form>
            <form method="post" action="<?= url('payments/' . (int) $r['id'] . '/overpayment') ?>" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="resolve" value="refund">
              <button class="btn btn--sm btn--ghost">Refunded to customer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
</div>
<?php endif; ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">Payments</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon">▦</div><div class="empty__title">No payments recorded</div>
      <div class="empty__body">Payments can only be taken against an issued invoice.</div></div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Payment</th><th>Invoice</th><th>Customer</th><th>Method</th><th>When</th><th class="right">Tip</th><th class="right">Amount</th><th class="right">Receipt</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="docno"><?= e($r['doc_number']) ?></td>
          <td class="docno faint"><?= e($r['inv_no']) ?></td>
          <td class="strong"><?= e(customer_name($r, true)) ?></td>
          <td><?= e(status_label($r['method'])) ?></td>
          <td class="muted text-sm nowrap"><?= e(fdatetime($r['paid_at'])) ?></td>
          <td class="right num muted"><?= (float) $r['tip_amount'] > 0 ? money($r['tip_amount']) : '—' ?></td>
          <td class="right num strong"><?= money($r['amount']) ?></td>
          <td class="right"><?php if ($r['rcpt_id']): ?><a class="btn btn--sm btn--ghost" href="<?= url('receipts/' . $r['rcpt_id']) ?>" target="_blank"><?= e($r['rcpt_no']) ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
  </div>
</div>
