<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel">
  <div class="panel__head">
    <div>
      <div class="panel__title">Jobs assigned to you</div>
      <div class="panel__sub">Tap a job to update status, add parts, capture the VIN and close it out.</div>
    </div>
  </div>
  <div class="panel__body panel__body--flush">
  <?php if (!$jobs): ?>
    <div class="empty">
      <div class="empty__icon" aria-hidden="true">✓</div>
      <div class="empty__title">You're clear</div>
      <div class="empty__body">Nothing is assigned to you right now. Dispatch will send work orders here as they come in.</div>
    </div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th scope="col">Status</th><th scope="col">Work order</th><th scope="col">Customer</th><th scope="col">Service</th><th scope="col">Where</th><th scope="col">Priority</th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr data-href="<?= url('work-orders/' . $j['id']) ?>">
          <td><?= badge($j['status']) ?></td>
          <td class="docno nowrap"><a class="row-link" href="<?= url('work-orders/' . $j['id']) ?>"><?= e($j['doc_number']) ?><div class="text-sm faint"><?= e($j['est_no']) ?></div></a></td>
          <td class="strong"><?= e(customer_name($j, true)) ?></td>
          <td><?= e(service_type_label($j['service_type'])) ?></td>
          <td class="muted"><?= e($j['city']) ?>, <?= e($j['state']) ?></td>
          <td><?= $j['priority'] === 'EMERGENCY' ? '<span class="badge badge--danger"><i></i>Emergency</span>' : '<span class="muted text-sm">' . e(ucfirst(strtolower($j['priority']))) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  </div>
</div>
