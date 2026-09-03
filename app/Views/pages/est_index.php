<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">All estimates</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon">▤</div>
      <div class="empty__title">No estimates yet</div>
      <div class="empty__body">Estimates are raised by promoting a service request. Open a request and click "Promote to estimate".</div>
      <button class="btn btn--primary" data-url="<?= url('service-requests') ?>">Go to service requests</button></div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Status</th><th>Estimate</th><th>Request</th><th>Customer</th><th>Service</th><th>Authorized by</th><th class="right">Total</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-href="<?= url('estimates/' . $r['id']) ?>">
          <td><?= badge($r['status']) ?></td>
          <td class="docno nowrap"><?= e($r['doc_number']) ?></td>
          <td class="docno faint nowrap"><?= e($r['sr_no']) ?></td>
          <td class="strong"><?= e(customer_name($r, true)) ?></td>
          <td><?= e(service_type_label($r['service_type'])) ?></td>
          <td class="muted"><?= e($r['authorized_by'] ?: '—') ?><?php if ($r['authorization_method']): ?><div class="text-sm faint"><?= e(status_label($r['authorization_method'])) ?></div><?php endif; ?></td>
          <td class="right num strong"><?= money($r['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
  </div>
</div>
