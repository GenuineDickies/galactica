<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">Work orders</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
  <?php if (!$rows): ?>
    <div class="empty"><div class="empty__icon" aria-hidden="true">🔧</div>
      <div class="empty__title">No work orders</div>
      <div class="empty__body">A work order is raised by dispatching an authorized estimate — that is what activates a technician to go and do the work.</div></div>
  <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th scope="col">Status</th><th scope="col">Work order</th><th scope="col">Estimate / Request</th><th scope="col">Customer</th><th scope="col">Service</th><th scope="col">Technician</th><th scope="col">Location</th><th class="right" scope="col">Outcome</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-href="<?= url('work-orders/' . $r['id']) ?>">
          <td><?= badge($r['status']) ?></td>
          <td class="docno nowrap"><a class="row-link" href="<?= url('work-orders/' . $r['id']) ?>"><?= e($r['doc_number']) ?></a></td>
          <td class="docno faint nowrap"><?= e($r['est_no']) ?><div class="text-sm"><?= e($r['sr_no']) ?></div></td>
          <td class="strong"><?= e(customer_name($r, true)) ?></td>
          <td><?= e(service_type_label($r['service_type'])) ?></td>
          <td class="muted"><?= e(trim(($r['tech_first'] ?? '') . ' ' . ($r['tech_last'] ?? ''))) ?: '<span class="badge badge--warn"><i></i>Unassigned</span>' ?></td>
          <td class="muted"><?= e($r['city'] ?: '—') ?></td>
          <td class="right muted text-sm"><?= e($r['outcome_code'] ? status_label($r['outcome_code']) : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
  </div>
</div>
