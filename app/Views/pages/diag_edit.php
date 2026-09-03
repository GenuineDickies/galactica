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
/** The working screen for a diagnostic report. Internal; the customer copy is diag_print. */
$custName = customer_name($customer, true);
$r        = $report ?? null;
$vehLabel = $vehicle ? trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model']) : null;
?>

<div class="panel mb4">
  <div class="panel__body">
    <div class="row row--between wrap">
      <div>
        <div class="row wrap">
          <span class="docno text-lg"><?= e($r['doc_number'] ?? 'New diagnostic report') ?></span>
          <?= badge($r['status'] ?? 'DRAFT') ?>
          <span class="muted">on <a href="<?= url('work-orders/' . $wo['id']) ?>"><?= e($wo['doc_number']) ?></a></span>
        </div>
        <div class="mt2 muted"><?= e($custName) ?><?= $vehLabel ? ' · ' . e($vehLabel) : '' ?></div>
      </div>
      <div class="btn-row">
        <button class="btn btn--ghost" data-url="<?= url('work-orders/' . $wo['id']) ?>">Back to work order</button>
        <?php if ($r): ?>
          <a class="btn btn--ghost" href="<?= url('diagnostics/' . $r['id'] . '/print') ?>" target="_blank">Preview customer copy</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="split">
  <div class="stack">

    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">What the customer will read</div>
        <div class="panel__sub">Plain language. This is not a quote — the repair options below are, each one an estimate.</div></div>
      </div>
      <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/diagnostic') ?>">
        <?= csrf_field() ?>
        <div class="panel__body stack">
          <div class="field"><label>Customer's concern</label>
            <textarea class="textarea" name="concern" rows="2" placeholder="Won't start in the morning; clicks once, then nothing."><?= e($r['concern'] ?? $sr['reported_problem'] ?? '') ?></textarea>
            <div class="hint">Pre-filled from the request. Their words — what they asked you to look at.</div>
          </div>
          <div class="field"><label>Findings <span class="req">*</span></label>
            <textarea class="textarea" name="findings" rows="6" placeholder="Battery load-tested at 9.6V under load (spec ≥ 9.8V) — failed. Alternator charging at 14.2V — good. Terminals clean, cables tight. No fault codes stored."><?= e($r['findings'] ?? '') ?></textarea>
            <div class="hint">What you tested, what you measured, what you saw. Numbers where you have them.</div>
          </div>
          <div class="field"><label>Recommendation <span class="req">*</span></label>
            <textarea class="textarea" name="recommendations" rows="4" placeholder="Replace the battery. No other work needed at this time. Recheck charging system if the new battery drains within 30 days."><?= e($r['recommendations'] ?? '') ?></textarea>
            <div class="hint">What should happen next, and what can wait.</div>
          </div>

          <div class="field"><label>Can the vehicle be driven?</label>
            <div class="radio-row">
              <?php foreach (DiagnosticController::DRIVABILITY as $k => $v): ?>
                <label class="radio-card"><input type="radio" name="drivability" value="<?= e($k) ?>" <?= ($r['drivability'] ?? '') === $k ? 'checked' : '' ?>><span><?= e($v) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

        </div>

        <div class="panel__body" style="border-top:1px solid var(--line)">
          <div class="field"><label>Internal notes</label>
            <textarea class="textarea" name="internal_notes" rows="2" placeholder="Customer mentioned a second vehicle with the same issue — follow up."><?= e($r['internal_notes'] ?? '') ?></textarea>
            <div class="hint">Never printed on the customer copy.</div>
          </div>
        </div>

        <div class="panel__foot">
          <button class="btn btn--primary">Save draft</button>
        </div>
      </form>
    </div>

    <?php if ($optFor): ?>
    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">Repair options<?= $optFor['status'] === 'ISSUED' ? ' <span class="docno text-sm faint">on ' . e($optFor['doc_number']) . '</span>' : '' ?></div>
        <div class="panel__sub">Each option is its own estimate — price it there. The customer copy prints them side by side; they pick one, and the rest are declined as superseded.</div></div>
      </div>
      <?php if ($options): ?>
      <div class="panel__body panel__body--flush">
        <table class="tbl">
          <thead><tr><th>Option</th><th>Time frame</th><th>Status</th><th class="right">Total</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($options as $o): ?>
            <tr>
              <td><span class="strong"><?= e($o['option_label']) ?></span><div class="docno text-sm faint"><?= e($o['doc_number']) ?> · <?= count($o['lines']) ?> line<?= count($o['lines']) === 1 ? '' : 's' ?></div></td>
              <td class="muted"><?= e($o['option_timeframe'] ?: '—') ?></td>
              <td><?= badge($o['status']) ?><?php if ($o['status'] === 'DECLINED' && $o['decline_reason']): ?><div class="text-sm faint"><?= e($o['decline_reason']) ?></div><?php endif; ?></td>
              <td class="right num strong"><?= money($o['total']) ?></td>
              <td class="right"><?php if ($canQuote): ?><button class="btn btn--ghost btn--sm" data-url="<?= url('estimates/' . $o['id']) ?>">Open</button><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <div class="panel__body" <?= $options ? 'style="border-top:1px solid var(--line)"' : '' ?>>
        <?php if ($canQuote): ?>
          <form method="post" action="<?= url('diagnostics/' . $optFor['id'] . '/options') ?>" class="form-grid">
            <?= csrf_field() ?>
            <div class="field"><label class="req">Option name</label>
              <input class="input" name="option_label" required maxlength="80" placeholder="<?= $options ? 'Replace impeller only' : 'Replace pump' ?>"></div>
            <div class="field"><label>Time frame</label>
              <input class="input" name="option_timeframe" maxlength="120" placeholder="Same day · 2–3 days for the part"></div>
            <div class="field col-full"><button class="btn <?= $options ? 'btn--ghost' : 'btn--primary' ?>">Open option as an estimate</button></div>
          </form>
        <?php elseif (!$options): ?>
          <div class="muted text-sm">No options yet. Dispatch prices the options from the office; you'll see them here once they're opened.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($r): ?>
    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">Issue to customer</div>
        <div class="panel__sub">Issuing freezes the report. A correction afterwards is a new report; this one stays on record.</div></div>
      </div>
      <div class="panel__body">
        <?php if (!$gate['ok']): ?>
          <div class="alert alert--warn"><strong>Not ready.</strong> <?= e($gate['reason']) ?></div>
        <?php else: ?>
          <form method="post" action="<?= url('diagnostics/' . $r['id'] . '/issue') ?>" class="row wrap">
            <?= csrf_field() ?>
            <button class="btn btn--primary">Issue <?= e($r['doc_number']) ?></button>
            <span class="muted text-sm">Opens the customer copy for print or PDF.</span>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div><div class="panel__title">Vehicle</div></div></div>
      <div class="panel__body">
        <?php if ($vehicle): ?>
          <dl class="kv">
            <dt>Vehicle</dt><dd><?= e($vehLabel) ?></dd>
            <dt>VIN</dt><dd class="docno"><?= e($vehicle['vin'] ?: '—') ?></dd>
            <dt>Plate</dt><dd><?= (int) $vehicle['no_plate'] === 1 ? 'NO PLATE' : e(trim($vehicle['plate'] . ' ' . $vehicle['plate_state']) ?: '—') ?></dd>
            <?php if ($wo['odometer']): ?><dt>Odometer</dt><dd><?= number_format((int) $wo['odometer']) ?></dd><?php endif; ?>
          </dl>
        <?php else: ?>
          <div class="muted text-sm">No vehicle on the estimate. Attach one there if the report should name it.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($issued): ?>
    <div class="panel">
      <div class="panel__head"><div><div class="panel__title">Issued reports</div></div></div>
      <div class="panel__body panel__body--flush">
        <table class="tbl">
          <tbody>
          <?php foreach ($issued as $i): ?>
            <tr>
              <td class="docno"><?= e($i['doc_number']) ?></td>
              <td class="muted text-sm"><?= e(fdatetime($i['issued_at'])) ?><br><?= e(trim(($i['tech_first'] ?? '') . ' ' . ($i['tech_last'] ?? ''))) ?></td>
              <td class="right"><a class="btn btn--ghost btn--sm" href="<?= url('diagnostics/' . $i['id'] . '/print') ?>" target="_blank">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($audit): ?>
    <div class="panel">
      <div class="panel__head"><div><div class="panel__title">History</div></div></div>
      <div class="panel__body">
        <ul class="timeline">
          <?php foreach ($audit as $ev): ?>
            <li><div class="t-act"><?= e($ev['action']) ?><?= $ev['detail'] ? ' — <span class="muted" style="font-weight:400">' . e($ev['detail']) . '</span>' : '' ?></div>
                <div class="t-meta"><?= e($ev['actor_name']) ?> · <?= e(fdatetime($ev['created_at'])) ?></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
