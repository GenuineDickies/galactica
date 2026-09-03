<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel mb4"><div class="panel__body">
  <form method="get" class="row wrap">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="VIN, plate, make or model…" style="max-width:340px">
    <button class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn--ghost" href="<?= url('vehicles') ?>">Clear</a><?php endif; ?>
    <div class="topbar__spacer"></div>
    <button type="button" class="btn btn--primary" data-url="<?= url('vehicles/new') ?>">New vehicle</button>
  </form>
  <div class="hint">A vehicle record can only be created from a valid 17-character VIN. Use a plate to find an existing VIN record.</div>
</div></div>
<div class="panel">
  <div class="panel__head"><div class="panel__title">Vehicles</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
<?php if (!$rows): ?>
  <div class="empty"><div class="empty__icon">⛟</div><div class="empty__title">No vehicles on file</div>
    <div class="empty__body">Vehicles appear here once a driver captures a VIN in the field.</div></div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Vehicle</th><th>VIN</th><th>Plate</th><th>Associated account</th><th class="right">Added</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= url('vehicles/' . $r['id']) ?>">
        <td class="strong"><?= e(trim(($r['year'] ?: '') . ' ' . $r['make'] . ' ' . $r['model'])) ?: 'Unidentified' ?>
          <?php if ($r['color']): ?><div class="text-sm faint"><?= e($r['color']) ?></div><?php endif; ?></td>
        <td class="docno"><?= e($r['vin']) ?></td>
        <td><?= (int) $r['no_plate'] === 1 ? '<span class="badge badge--slate"><i></i>No plate</span>' : e(trim($r['plate'] . ' ' . $r['plate_state'])) ?></td>
        <td class="muted"><?= e(trim(($r['company'] ?? '') ?: (($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')))) ?: '—' ?></td>
        <td class="right muted text-sm nowrap"><?= e(fdate($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</div></div>
