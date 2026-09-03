<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="split">
  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Service history</div></div>
      <div class="panel__body panel__body--flush">
      <?php if (!$history): ?><div class="empty"><div class="empty__title">No service history</div>
        <div class="empty__body">This vehicle has not been worked on yet.</div></div>
      <?php else: ?>
        <table class="tbl"><thead><tr><th>Status</th><th>Estimate</th><th>Service</th><th class="right">Total</th><th class="right">When</th></tr></thead><tbody>
        <?php foreach ($history as $r): ?>
          <tr data-href="<?= url('estimates/' . $r['id']) ?>">
            <td><?= badge($r['status']) ?></td>
            <td class="docno"><?= e($r['doc_number']) ?><div class="text-sm faint"><?= e($r['sr_no']) ?></div></td>
            <td><?= e(service_type_label($r['service_type'])) ?></td>
            <td class="right num"><?= money($r['total']) ?></td>
            <td class="right muted text-sm nowrap"><?= e(fdate($r['created_at'])) ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Identity</div></div>
      <div class="panel__body">
        <dl class="kv">
          <dt>VIN</dt><dd class="docno"><?= e($v['vin']) ?></dd>
          <dt>Check digit</dt><dd><?= vin_is_valid($v['vin']) ? '<span class="badge badge--success"><i></i>Valid</span>' : '<span class="badge badge--danger"><i></i>Fails</span>' ?></dd>
          <dt>Plate</dt><dd><?= (int) $v['no_plate'] === 1 ? 'NO PLATE — ' . e($v['no_plate_reason']) : e(trim($v['plate'] . ' ' . $v['plate_state'])) ?></dd>
          <dt>Colour</dt><dd><?= e($v['color'] ?: '—') ?></dd>
          <dt>Odometer</dt><dd><?= $v['odometer'] ? number_format((float) $v['odometer']) : '—' ?></dd>
          <dt>Associated account</dt><dd><?= $customer ? e(customer_name($customer, true)) : '—' ?></dd>
        </dl>
      </div>
    </div>
    <?php if ($decoded): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">VIN decode</div><div class="topbar__spacer"></div><span class="tag">Offline</span></div>
      <div class="panel__body">
        <dl class="kv">
          <dt>WMI</dt><dd class="docno"><?= e($decoded['wmi'] ?? '') ?></dd>
          <dt>Built in</dt><dd><?= e($decoded['country_hint'] ?? '—') ?></dd>
          <dt>Model year</dt><dd><?= e($decoded['model_year'] ?? '—') ?></dd>
          <dt>Serial</dt><dd class="docno"><?= e($decoded['serial'] ?? '') ?></dd>
        </dl>
        <div class="hint">Decoded locally from the VIN structure — no paid service is required. A full decode provider can be dropped in behind the same interface.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
