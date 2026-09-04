<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">All invoices</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon" aria-hidden="true">▤</div>
      <div class="empty__title">No invoices</div>
      <div class="empty__body">Invoices are created from a completed work order, so the bill always reflects work that was actually performed.</div></div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th scope="col">Status</th><th scope="col">Invoice</th><th scope="col">Request</th><th scope="col">Customer</th><th scope="col">Issued</th><th scope="col">Due</th><th class="right" scope="col">Total</th><th class="right" scope="col">Balance</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $overdue = in_array($r['status'], ['ISSUED','PARTIAL'], true) && $r['due_at'] && strtotime($r['due_at']) < time(); ?>
        <tr data-href="<?= url('invoices/' . $r['id']) ?>">
          <td><?= badge($overdue ? 'OVERDUE' : $r['status']) ?></td>
          <td class="docno nowrap"><a class="row-link" href="<?= url('invoices/' . $r['id']) ?>"><?= e($r['doc_number']) ?></a></td>
          <td class="docno faint nowrap"><?= e($r['sr_no']) ?></td>
          <td class="strong"><?= e(customer_name($r, true)) ?></td>
          <td class="muted text-sm nowrap"><?= e(fdate($r['issued_at'])) ?></td>
          <td class="muted text-sm nowrap"><?= e(fdate($r['due_at'])) ?></td>
          <td class="right num"><?= money($r['total']) ?></td>
          <td class="right num strong"><?= money($r['balance_due']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
  </div>
</div>
