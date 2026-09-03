<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
$max  = max(1, max($spark));
$cust = fn(array $r): string => customer_name($r, true) ?: 'Unnamed';
?>

<div class="grid grid--kpi mb4">
  <div class="panel kpi">
    <div class="kpi__label">Revenue today</div>
    <div class="kpi__value"><?= money($kpi['revToday']) ?></div>
    <div class="kpi__meta"><?= money($kpi['revMonth']) ?> month to date</div>
    <div class="spark">
      <?php foreach ($spark as $v): ?><i style="height:<?= max(4, (int) round($v / $max * 26)) ?>px"></i><?php endforeach; ?>
    </div>
  </div>
  <div class="panel kpi">
    <div class="kpi__label">Active jobs</div>
    <div class="kpi__value"><?= (int) $kpi['activeJobs'] ?></div>
    <div class="kpi__meta">Work orders not yet closed</div>
  </div>
  <div class="panel kpi">
    <div class="kpi__label">Accounts receivable</div>
    <div class="kpi__value"><?= money($kpi['ar']) ?></div>
    <div class="kpi__meta"><?= count($needsAction['unpaid']) ?> invoice(s) outstanding</div>
  </div>
  <div class="panel kpi">
    <div class="kpi__label">Unpromoted requests</div>
    <div class="kpi__value"><?= count($intake) ?></div>
    <div class="kpi__meta">Logged, not yet turned into an estimate</div>
  </div>
</div>

<div class="split">
  <div class="stack">
    <div class="panel">
      <div class="panel__head">
        <div>
          <div class="panel__title">Intake queue</div>
          <div class="panel__sub">Unverified requests, highest priority first. Promote one to open an estimate.</div>
        </div>
        <div class="topbar__spacer"></div>
        <button class="btn btn--sm" data-url="<?= url('service-requests') ?>">View all</button>
      </div>
      <div class="panel__body panel__body--flush">
        <?php if (!$intake): ?>
          <div class="empty">
            <div class="empty__icon">☎</div>
            <div class="empty__title">Intake is clear</div>
            <div class="empty__body">Every request has been promoted or closed. When the next call comes in, start it here.</div>
            <button class="btn btn--primary" data-url="<?= url('service-requests/new') ?>">New Service Request</button>
          </div>
        <?php else: ?>
        <div class="table-wrap"><table class="tbl">
          <thead><tr>
            <th>Request</th><th>Reported by</th><th>Reported need</th><th>Priority</th><th class="right">Logged</th>
          </tr></thead>
          <tbody>
          <?php foreach ($intake as $r): ?>
            <tr data-href="<?= url('service-requests/' . $r['id']) ?>">
              <td class="docno nowrap"><?= e($r['doc_number']) ?>
                <div class="text-sm faint"><?= e(status_label((string) $r['channel'])) ?></div></td>
              <td class="strong"><?= e($r['reported_name'] ?: '—') ?>
                <div class="text-sm faint"><?= e(phone_display($r['reported_phone'])) ?></div></td>
              <td><?= e(service_type_label($r['reported_service'])) ?>
                <div class="text-sm faint"><?= e($r['city'] ?: ($r['reported_location'] ?: '—')) ?></div></td>
              <td><?php if ($r['priority'] === 'EMERGENCY'): ?><span class="badge badge--danger"><i></i>Emergency</span>
                  <?php elseif ($r['priority'] === 'URGENT'): ?><span class="badge badge--warn"><i></i>Urgent</span>
                  <?php else: ?><span class="muted text-sm"><?= e(ucfirst(strtolower($r['priority']))) ?></span><?php endif; ?></td>
              <td class="right muted text-sm nowrap"><?= e(ago($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head">
        <div>
          <div class="panel__title">In the field</div>
          <div class="panel__sub">Work orders currently assigned, en route or on site.</div>
        </div>
        <div class="topbar__spacer"></div>
        <button class="btn btn--sm" data-url="<?= url('work-orders') ?>">View all</button>
      </div>
      <div class="panel__body panel__body--flush">
        <?php if (!$inField): ?>
          <div class="empty">
            <div class="empty__icon">▲</div>
            <div class="empty__title">Nobody is out on a job</div>
            <div class="empty__body">Authorized estimates dispatch to the field from the estimate screen.</div>
          </div>
        <?php else: ?>
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Status</th><th>Work order</th><th>Customer</th><th>Technician</th></tr></thead>
          <tbody>
          <?php foreach ($inField as $w): ?>
            <tr data-href="<?= url('work-orders/' . $w['id']) ?>">
              <td><?= badge($w['status']) ?></td>
              <td class="docno nowrap"><?= e($w['doc_number']) ?><div class="text-sm faint"><?= e($w['est_no']) ?></div></td>
              <td class="strong"><?= e($cust($w)) ?></td>
              <td class="muted"><?= e(trim(($w['tech_first'] ?? '') . ' ' . ($w['tech_last'] ?? ''))) ?: '<span class="badge badge--warn"><i></i>Unassigned</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Awaiting authorization</div></div>
      <div class="panel__body">
        <?php if (!$needsAction['awaitingAuth']): ?>
          <div class="muted text-sm">Every open estimate has a customer authorization on file.</div>
        <?php else: foreach ($needsAction['awaitingAuth'] as $x): ?>
          <div class="row row--between" style="padding:7px 0;border-bottom:1px solid var(--line)">
            <div>
              <div class="docno"><?= e($x['doc_number']) ?></div>
              <div class="text-sm faint"><?= e($cust($x)) ?></div>
            </div>
            <div class="row">
              <span class="strong"><?= money($x['total']) ?></span>
              <button class="btn btn--sm" data-url="<?= url('estimates/' . $x['id']) ?>">Open</button>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <div class="hint">Nothing on this list may be dispatched. That is a gate, not a suggestion.</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Ready to invoice</div></div>
      <div class="panel__body">
        <?php if (!$needsAction['readyToBill']): ?>
          <div class="muted text-sm">Nothing completed is waiting to be billed.</div>
        <?php else: foreach ($needsAction['readyToBill'] as $x): ?>
          <div class="row row--between" style="padding:7px 0;border-bottom:1px solid var(--line)">
            <div>
              <div class="docno"><?= e($x['doc_number']) ?></div>
              <div class="text-sm faint"><?= e($cust($x)) ?> · authorized <?= money($x['total']) ?></div>
            </div>
            <button class="btn btn--sm" data-url="<?= url('estimates/' . $x['id']) ?>">Bill it</button>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Unpaid invoices</div></div>
      <div class="panel__body">
        <?php if (!$needsAction['unpaid']): ?>
          <div class="muted text-sm">Everything issued has been paid.</div>
        <?php else: foreach ($needsAction['unpaid'] as $x): ?>
          <div class="row row--between" style="padding:7px 0;border-bottom:1px solid var(--line)">
            <div>
              <div class="docno"><?= e($x['doc_number']) ?></div>
              <div class="text-sm faint"><?= e($cust($x)) ?> · due <?= e(fdate($x['due_at'])) ?></div>
            </div>
            <div class="row">
              <span class="strong"><?= money($x['balance_due']) ?></span>
              <button class="btn btn--sm" data-url="<?= url('invoices/' . $x['id']) ?>">Open</button>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
