<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">All estimates</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon" aria-hidden="true">▤</div>
      <div class="empty__title">No estimates yet</div>
      <div class="empty__body">Estimates are raised by promoting a service request. Open a request and click "Promote to estimate".</div>
      <button class="btn btn--primary" data-url="<?= url('service-requests') ?>">Go to service requests</button></div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th scope="col">Status</th><th scope="col">Estimate</th><th scope="col">Request</th><th scope="col">Customer</th><th scope="col">Service</th><th scope="col">Authorized by</th><th class="right" scope="col">Total</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-href="<?= url('estimates/' . $r['id']) ?>">
          <td><?= badge($r['status']) ?></td>
          <td class="docno nowrap"><a class="row-link" href="<?= url('estimates/' . $r['id']) ?>"><?= e($r['doc_number']) ?></a></td>
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
