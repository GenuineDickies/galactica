<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel mb4">
  <div class="panel__body">
    <form method="get" class="row wrap">
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Search by number, reported name, phone or location…" style="max-width:340px">
      <select class="select" name="status" style="max-width:190px">
        <option value="">All statuses</option>
        <?php foreach (ServiceRequestController::STATUSES as $s): ?>
          <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn">Filter</button>
      <?php if ($q !== '' || $status !== ''): ?><a class="btn btn--ghost" href="<?= url('service-requests') ?>">Clear</a><?php endif; ?>
      <div class="topbar__spacer"></div>
      <button type="button" class="btn btn--primary" data-url="<?= url('service-requests/new') ?>">New Service Request</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel__head"><div class="panel__title">Service requests</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
    <?php if (!$rows): ?>
      <div class="empty">
        <div class="empty__icon">☎</div>
        <div class="empty__title">No requests match</div>
        <div class="empty__body">Either nothing has been logged yet, or the filter is too narrow. Clear the filter to see everything.</div>
        <button class="btn btn--primary" data-url="<?= url('service-requests/new') ?>">New Service Request</button>
      </div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Status</th><th>Request</th><th>Reported by</th><th>Reported need</th><th>Source</th><th>Location</th><th>Priority</th><th class="right">Logged</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-href="<?= url('service-requests/' . $r['id']) ?>">
          <td><?= badge($r['status']) ?></td>
          <td class="docno nowrap"><?= e($r['doc_number']) ?></td>
          <td class="strong"><?= e($r['reported_name'] ?: '—') ?>
            <div class="text-sm faint"><?= e(phone_display($r['reported_phone'])) ?></div></td>
          <td><?= e(service_type_label($r['reported_service'])) ?></td>
          <td><span class="badge badge--<?= $r['job_source'] === 'PROVIDER' ? 'accent' : 'slate' ?>"><i></i><?= $r['job_source'] === 'PROVIDER' ? 'Provider' : 'Retail' ?></span></td>
          <td class="muted">
            <?php $loc = trim((string) $r['reported_location']); ?>
            <?php if ($loc): ?>
              <?= e(mb_strimwidth($loc, 0, 48, '…')) ?>
              <?php if ($r['city']): ?><div class="text-sm faint"><?= e($r['city']) ?>, <?= e($r['state']) ?></div><?php endif; ?>
            <?php elseif ($r['nearest_address']): ?>
              <?= e(mb_strimwidth((string) $r['nearest_address'], 0, 48, '…')) ?>
              <div class="text-sm faint">GPS · shared by the customer</div>
            <?php elseif ($r['city']): ?>
              <?= e($r['city']) ?>, <?= e($r['state']) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?= $r['priority'] === 'EMERGENCY'
                ? '<span class="badge badge--danger"><i></i>Emergency</span>'
                : '<span class="muted text-sm">' . e(ucfirst(strtolower($r['priority']))) . '</span>' ?></td>
          <td class="right muted text-sm nowrap"><?= e(fdate($r['created_at'], 'M j · g:i A')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
