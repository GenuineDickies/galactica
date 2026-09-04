<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel mb4"><div class="panel__body">
  <form method="get" class="row wrap">
    <?php $kq = $q !== '' ? '&q=' . rawurlencode($q) : ''; ?>
    <a class="btn btn--sm <?= $kind === '' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('customers') . ($kq ? '?' . ltrim($kq, '&') : '') ?>">All</a>
    <a class="btn btn--sm <?= $kind === 'individuals' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('customers?kind=individuals') . $kq ?>">Individuals</a>
    <a class="btn btn--sm <?= $kind === 'business' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('customers?kind=business') . $kq ?>">Businesses</a>
    <?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
    <input class="input" name="q" aria-label="Search customers" value="<?= e($q) ?>" placeholder="Name, company, phone or email…" style="max-width:340px">
    <button class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn--ghost" href="<?= url('customers') . ($kind !== '' ? '?kind=' . e($kind) : '') ?>">Clear</a><?php endif; ?>
    <div class="topbar__spacer"></div>
    <button type="button" class="btn btn--primary" data-url="<?= url('customers/new') ?>">New customer</button>
  </form>
</div></div>
<div class="panel">
  <div class="panel__head"><div class="panel__title">Customers</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
<?php if (!$rows): ?>
  <div class="empty"><div class="empty__icon" aria-hidden="true">☺</div><div class="empty__title">No customers match</div>
    <div class="empty__body">Customers are usually created automatically during intake — you rarely need to add one by hand.</div></div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Phone</th><th scope="col">Email</th><th scope="col">SMS</th><th scope="col">City</th><th class="right" scope="col">Added</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= url('customers/' . $r['id']) ?>">
        <td class="strong"><a class="row-link" href="<?= url('customers/' . $r['id']) ?>"><?= e(customer_name($r, true)) ?>
          <?php if ((int) $r['is_provider'] === 1): ?><span class="badge badge--accent" style="margin-left:6px"><i></i>Provider</span><?php endif; ?>
          <?php if (($d = Rules::termsDays($r['payment_terms'])) !== null): ?><span class="badge badge--info" style="margin-left:6px"><i></i>Net <?= $d ?></span><?php endif; ?></a></td>
        <td><?= customer_badge($r) ?></td>
        <td class="nowrap"><?= e(phone_display($r['phone_e164'])) ?></td>
        <td class="muted"><?= e($r['email'] ?: '—') ?></td>
        <td><?= (int) $r['sms_approved'] === 1 ? '<span class="badge badge--success"><i></i>Consent</span>' : '<span class="badge badge--slate"><i></i>None</span>' ?></td>
        <td class="muted"><?= e($r['city'] ?: '—') ?></td>
        <td class="right muted text-sm nowrap"><?= e(fdate($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div></div>
