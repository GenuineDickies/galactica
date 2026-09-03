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
$custName = customer_name($customer, true);
$done     = in_array($wo['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true);
$nextMap  = ['ASSIGNED' => 'EN_ROUTE', 'EN_ROUTE' => 'ON_SITE'];
// Over the threshold the customer approved verbally to get the truck rolling;
// the signature is owed here, on arrival, before anything is touched.
$sigOwed  = !$done && Rules::signaturePending($est, $wo);
$onSite   = $wo['status'] === 'ON_SITE';       // arrived, not yet working
$working  = $wo['status'] === 'IN_PROGRESS';   // hands on the vehicle
$smsOk    = Sms::gate($customer)['ok'];
?>

<div class="panel mb4">
  <div class="chain">
    <?php
    $flow = WorkOrderController::FLOW;
    $cur  = array_search($wo['status'], $flow, true);
    if ($cur === false) { $cur = count($flow) - 1; }
    foreach ($flow as $i => $s): ?>
      <?php if ($i): ?><span class="chain__arrow">▸</span><?php endif; ?>
      <span class="chain__step <?= $i < $cur ? 'is-done' : ($i === $cur ? 'is-current' : '') ?>">
        <?= $i < $cur ? '✓' : '' ?><?= e(status_label($s)) ?>
      </span>
    <?php endforeach; ?>
  </div>
  <div class="panel__body">
    <div class="row row--between wrap">
      <div>
        <div class="row wrap">
          <span class="docno text-lg"><?= e($wo['doc_number']) ?></span>
          <?= badge($wo['status']) ?>
          <span class="muted">for <a href="<?= url('service-requests/' . $sr['id']) ?>"><?= e($sr['doc_number']) ?></a></span>
        </div>
        <?php /* The nearest physical address only. reported_location is the
                 caller's description of where they are ("blue sedan on the
                 shoulder") — useful to read, never an address, and it used to
                 land here and print as one. */ ?>
        <?php $__a = doc_address($est); ?>
        <div class="mt2 muted"><?= e($custName) ?><?= $__a !== '' ? ' · ' . e($__a) : '' ?><?= $est['city'] ? ', ' . e($est['city']) : '' ?></div>
      </div>
      <div class="btn-row">
        <?php if (!$done && isset($nextMap[$wo['status']])): ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>">
            <?= csrf_field() ?><input type="hidden" name="status" value="<?= e($nextMap[$wo['status']]) ?>">
            <button class="btn btn--primary">Mark <?= e(strtolower(status_label($nextMap[$wo['status']]))) ?></button>
          </form>
        <?php elseif (!$done && $onSite): ?>
          <?php if ($sigOwed): ?>
            <button class="btn btn--primary" data-modal-open="signModal">Get signature to begin</button>
          <?php else: ?>
            <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>">
              <?= csrf_field() ?><input type="hidden" name="status" value="IN_PROGRESS">
              <button class="btn btn--primary">Begin work</button>
            </form>
          <?php endif; ?>
        <?php elseif (!$done && $working): ?>
          <button class="btn btn--primary" data-modal-open="completeModal">Complete this job</button>
        <?php elseif ($wo['status'] === 'COMPLETED' && !$invoice): ?>
          <form method="post" action="<?= url('invoices/create/' . $wo['id']) ?>">
            <?= csrf_field() ?><button class="btn btn--primary">Create invoice</button>
          </form>
        <?php elseif ($invoice): ?>
          <button class="btn btn--primary" data-url="<?= url('invoices/' . $invoice['id']) ?>">Open invoice</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($sigOwed): ?>
  <div class="alert alert--danger gate">
    <div>
      <strong>Do not begin work — the customer has not authorized it.</strong>
      <?= e($est['doc_number']) ?> totals <?= money((float) $est['total']) ?>, over the <?= money(Rules::cfg('authorization_threshold')) ?> threshold,
      and was approved only verbally so you could be dispatched.
      Price the real scope on this order first, so the customer signs the number they will actually be billed.
      <?php if ($authReq && $authReq['sent_at']): ?>
        <div class="mt2">
          <strong>Link texted <?= e(fdatetime($authReq['sent_at'])) ?>.</strong>
          <?= $authReq['viewed_at']
              ? 'Opened by the customer ' . e(fdatetime($authReq['viewed_at'])) . ' — not signed yet.'
              : 'Not opened yet.' ?>
        </div>
      <?php endif; ?>
      <div class="btn-row mt2">
        <button class="btn btn--sm" data-modal-open="signModal">Display for customer</button>
        <?php if ($smsOk): ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/sign-link') ?>">
            <?= csrf_field() ?><input type="hidden" name="purpose" value="AUTH">
            <button class="btn btn--sm btn--ghost"
                    data-confirm="Text a signing link to <?= e((string) $customer['phone_e164']) ?>?">
              <?= $authReq && $authReq['sent_at'] ? 'Re-send SMS link' : 'Send to customer via SMS' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$smsOk): ?>
        <div class="hint mt2">
          No SMS consent on file for this customer, so the link cannot be texted —
          take the signature on your device.
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!$done && Rules::workAuthSigned($wo)): ?>
  <div class="alert alert--ok">
    <div>
      <strong>Authorized by <?= e($wo['auth_signer_name']) ?> — <?= e(fdatetime($wo['auth_signed_at'])) ?>.</strong>
      <?= $wo['auth_method'] === 'SMS' ? 'Signed remotely from a texted link' : 'Signed in person on this device' ?><?= $wo['auth_ip'] ? ' · IP ' . e($wo['auth_ip']) : '' ?>.
    </div>
  </div>
<?php endif; ?>

<?php /* The completion gate exists from the moment the work order does, but
         nagging about the VIN while the technician is still assigned or
         driving is noise — nothing can be done about it from the cab. The
         warning surfaces once they are on site, which is also the first
         moment the VIN is physically capturable. The gate itself is
         unchanged: completion and invoicing still refuse without a VIN. */ ?>
<?php if (!$gate['ok'] && in_array($wo['status'], ['ON_SITE', 'IN_PROGRESS'], true)): ?>
  <div class="alert alert--warn gate">
    <div>
      <strong>VIN required.</strong> <?= e($gate['reason']) ?>
      Capturing the VIN is the driver's job, not the customer's — this job cannot be completed or billed without it.
      <div class="btn-row mt2"><button class="btn btn--sm" data-modal-open="vinModal">Capture VIN</button></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($wo['status'] === 'NO_SHOW'): ?>
  <div class="alert alert--danger">
    <div><strong>Customer no-show.</strong> A trip or no-show fee is still billable — add <code>FEE-NOSHOW</code> and invoice it. Attach a photo showing arrival before closing.</div>
  </div>
<?php endif; ?>

<div class="split">
  <div class="stack">

    <?php if (!$done && Auth::check()): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Field status</div></div>
      <div class="panel__body">
        <?php /* The text to the caller is a per-send decision made here, not a
                 side effect of the status click. The ETA is entered fresh — the
                 intake promise is stale by en-route time and is never texted
                 from this page. Untick the box to change status silently. */ ?>
        <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>" class="btn-row" style="align-items:center;gap:8px;margin-bottom:8px">
          <?= csrf_field() ?><input type="hidden" name="status" value="EN_ROUTE">
          <button class="btn btn--sm" <?= $wo['status'] === 'EN_ROUTE' ? 'disabled' : '' ?>>En route</button>
          <label style="white-space:nowrap;display:flex;align-items:center;gap:4px">
            <input type="checkbox" name="send_sms" value="1" checked <?= $wo['status'] === 'EN_ROUTE' ? 'disabled' : '' ?>> Text customer, arriving in
          </label>
          <input class="input" name="eta_minutes" type="number" min="1" max="720" placeholder="—" style="width:70px" <?= $wo['status'] === 'EN_ROUTE' ? 'disabled' : '' ?>>
          <span class="hint">minutes (blank texts &ldquo;shortly&rdquo;)</span>
          <?php if ($wo['tech_latitude']): ?>
            <button class="btn btn--sm" type="button"
                    data-eta-suggest="<?= url('work-orders/' . $wo['id'] . '/eta-suggest') ?>">Suggest from route</button>
          <?php endif; ?>
        </form>
        <div class="hint" id="eta_suggest_note"></div>
        <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>" class="btn-row" style="align-items:center;gap:8px;margin-bottom:8px">
          <?= csrf_field() ?><input type="hidden" name="status" value="ON_SITE">
          <button class="btn btn--sm" <?= $wo['status'] === 'ON_SITE' ? 'disabled' : '' ?>>On site</button>
          <label style="white-space:nowrap;display:flex;align-items:center;gap:4px">
            <input type="checkbox" name="send_sms" value="1" checked <?= $wo['status'] === 'ON_SITE' ? 'disabled' : '' ?>> Text customer &ldquo;technician has arrived&rdquo;
          </label>
        </form>
        <div class="btn-row">
          <?php // Begin work stays locked until the customer has signed. ?>
          <?php $blocked = $sigOwed; ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>">
            <?= csrf_field() ?><input type="hidden" name="status" value="IN_PROGRESS">
            <button class="btn btn--sm" <?= ($wo['status'] === 'IN_PROGRESS' || $blocked) ? 'disabled' : '' ?>
                    <?= $blocked ? 'title="The customer must authorize this work order first"' : '' ?>>Begin work</button>
          </form>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/status') ?>">
            <?= csrf_field() ?><input type="hidden" name="status" value="NO_SHOW">
            <button class="btn btn--sm btn--danger" data-confirm="Mark this as a customer no-show? A fee is still billable.">Customer no-show</button>
          </form>
        </div>
        <div class="hint">The text only goes out when its box is ticked, and consent on file still gates every send.</div>

        <?php /* The truck's position and the plotted route, as recorded. The
                 locate link goes to the tech at assign; this row re-sends it
                 (one-shot links expire after a few hours). */ ?>
        <div class="btn-row" style="align-items:center;gap:8px;margin-top:8px">
          <?php if ($wo['tech_located_at']): ?>
            <span class="hint">Truck position on file — shared <?= e(fdatetime($wo['tech_located_at'])) ?>.<?php
              if ($wo['drive_miles'] !== null && $wo['drive_miles'] !== ''): ?>
              Route on record: <strong><?= e(number_format((float) $wo['drive_miles'], 1)) ?> mi · <?= (int) $wo['drive_minutes'] ?> min</strong>
              (calculated <?= e(fdatetime($wo['route_calculated_at'])) ?>).<?php endif; ?></span>
          <?php else: ?>
            <span class="hint">The technician has not shared their location yet — route and ETA suggestions need it.</span>
          <?php endif; ?>
          <?php if ($wo['technician_id']): ?>
            <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/locate-link') ?>">
              <?= csrf_field() ?>
              <button class="btn btn--sm">Text location link to tech</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php View::partial('partials/line_editor', [
      'lines' => $lines, 'catalog' => $catalog, 'totals' => $totals, 'locked' => $done,
      'postUrl' => url('work-orders/' . $wo['id'] . '/lines'),
      'delUrlBase' => url('work-orders/' . $wo['id'] . '/lines'),
    ]); ?>

    <?php if ($est): ?>
      <?php $delta = (float) $totals['total'] - (float) $est['total']; $tr = Rules::varianceThreshold((float) $est['total']); ?>
      <div class="alert <?= abs($delta) > $tr ? 'alert--warn' : 'alert--info' ?>">
        <div>
          <strong>Approved scope: <?= money($est['total']) ?> · currently on this work order: <?= money($totals['total']) ?>.</strong>
          <?php if (abs($delta) > $tr): ?>
            That's <?= money(abs($delta)) ?> over the <?= money($tr) ?> tolerance. The invoice will require the customer to re-authorize before it can be issued.
          <?php else: ?>
            Within the <?= money($tr) ?> tolerance — no re-authorization needed.
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">Diagnostic report</div>
        <div class="panel__sub">Findings and a recommendation, written for the customer to keep. Not a quote.</div></div>
        <div class="btn-row">
          <button class="btn <?= $diagDraft ? 'btn--primary' : 'btn--ghost' ?> btn--sm" data-url="<?= url('work-orders/' . $wo['id'] . '/diagnostic') ?>">
            <?= $diagDraft ? 'Continue draft' : 'New report' ?>
          </button>
        </div>
      </div>
      <?php if ($diagIssued): ?>
      <div class="panel__body panel__body--flush">
        <table class="tbl"><tbody>
          <?php foreach ($diagIssued as $d): ?>
            <tr>
              <td class="docno"><?= e($d['doc_number']) ?></td>
              <td class="muted text-sm">Issued <?= e(fdatetime($d['issued_at'])) ?></td>
              <td class="right"><a class="btn btn--ghost btn--sm" href="<?= url('diagnostics/' . $d['id'] . '/print') ?>" target="_blank">Customer copy</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php elseif (!$diagDraft): ?>
      <div class="panel__body"><div class="muted text-sm">No report on this job yet.</div></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">Photo evidence</div>
        <div class="panel__sub">Named <span class="docno">WOR-date-seq-TYPE</span>. This is what wins a dispute.</div></div>
      </div>
      <div class="panel__body">
        <?php if ($photos): ?>
          <div class="grid grid--3 mb4">
            <?php foreach ($photos as $p): ?>
              <div>
                <img src="<?= url($p['stored_path']) ?>" alt="<?= e($p['label']) ?>" style="width:100%;border-radius:var(--r-md);border:1px solid var(--line)">
                <div class="text-sm faint mt2"><?= e($p['label']) ?> · <?= e($p['filename']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted text-sm mb4">No photos attached yet. Capture before-and-after shots on every job.</div>
        <?php endif; ?>
        <?php if (!$done): ?>
        <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/photo') ?>" enctype="multipart/form-data" class="row wrap">
          <?= csrf_field() ?>
          <select class="select" name="label" style="max-width:150px">
            <option value="PRE">Before</option><option value="POST">After</option>
            <option value="PART">Part</option><option value="SITE">Site</option><option value="DAMAGE">Damage</option>
          </select>
          <input class="input" type="file" name="photo" accept="image/*" capture="environment" style="max-width:260px">
          <button class="btn btn--sm">Attach photo</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Audit trail</div></div>
      <div class="panel__body">
        <ul class="timeline">
          <?php foreach ($audit as $ev): ?>
            <li><div class="t-act"><?= e($ev['action']) ?><?= $ev['detail'] ? ' — <span class="muted" style="font-weight:400">' . e($ev['detail']) . '</span>' : '' ?></div>
                <div class="t-meta"><?= e($ev['actor_name']) ?> · <?= e(fdatetime($ev['created_at'])) ?></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Assignment</div></div>
      <div class="panel__body">
        <?php if (Auth::is('ADMIN','DISPATCH') && !$done): ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/assign') ?>">
            <?= csrf_field() ?>
            <div class="field">
              <label class="req">Technician</label>
              <select class="select" name="technician_id" required>
                <option value="">— pick a technician —</option>
                <?php foreach ($techs as $t): ?>
                  <option value="<?= (int) $t['id'] ?>" <?= (int) $wo['technician_id'] === (int) $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['first_name'] . ' ' . $t['last_name']) ?> (<?= e(ucfirst(strtolower($t['role']))) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="hint">A technician is required to dispatch. Only active techs accepting jobs are listed.</div>
            </div>
            <button class="btn btn--block"><?= $wo['technician_id'] ? 'Reassign' : 'Dispatch' ?></button>
          </form>
        <?php else: ?>
          <?php $t = $wo['technician_id'] ? Db::one('SELECT * FROM users WHERE id = ?', [(int) $wo['technician_id']]) : null; ?>
          <div class="strong"><?= $t ? e($t['first_name'] . ' ' . $t['last_name']) : 'Unassigned' ?></div>
        <?php endif; ?>

        <dl class="kv mt4">
          <dt>Assigned</dt><dd><?= e(fdatetime($wo['assigned_at'])) ?></dd>
          <dt>En route</dt><dd><?= e(fdatetime($wo['en_route_at'])) ?></dd>
          <dt>On site</dt><dd><?= e(fdatetime($wo['on_site_at'])) ?></dd>
          <?php /* The pair that proves the customer authorized the work before
                   it started, rather than merely before the job was closed. */ ?>
          <dt>Estimate signed</dt><dd><?= e(fdatetime($est['signature_at'] ?? null)) ?></dd>
          <dt>Work started</dt><dd><?= e(fdatetime($wo['work_started_at'])) ?></dd>
          <dt>Completed</dt><dd><?= e(fdatetime($wo['completed_at'])) ?></dd>
        </dl>
        <?php if (Auth::is('ADMIN','DISPATCH') && !$done): ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/po') ?>" class="row mt4">
            <?= csrf_field() ?>
            <input class="input" name="po_number" value="<?= e($wo['po_number']) ?>" placeholder="PO number" maxlength="64">
            <button class="btn btn--sm">Save</button>
          </form>
        <?php elseif ($wo['po_number']): ?>
          <div class="hint mt4">PO # <span class="docno"><?= e($wo['po_number']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* What the job needs on the truck, and whether that turned out to be
             what dispatch sent. The request is deliberately left alone: the
             pair is the measurement, so overwriting the first half would
             destroy the only record of how often intake gets this wrong. */ ?>
    <?php $woCat  = ServiceCategory::coerce($wo['service_category'] ?? null, (string) $sr['reported_service']);
          $srCat  = ServiceCategory::coerce($sr['service_category'] ?? null, (string) $sr['reported_service']);
          $moved  = $srCat !== $woCat; ?>
    <div class="panel">
      <div class="panel__head">
        <div><div class="panel__title">Category</div>
        <div class="panel__sub">What the job needs on the truck.</div></div>
      </div>
      <div class="panel__body">
        <dl class="kv">
          <dt>Dispatched as</dt><dd><?= e(ServiceCategory::label($srCat)) ?></dd>
          <dt>Actually</dt>
          <dd><?= e(ServiceCategory::label($woCat)) ?>
            <?php if ($moved): ?><span class="badge badge--warn ml2"><i></i>Reclassified</span><?php endif; ?></dd>
        </dl>
        <?php if (!$done && Auth::check()): ?>
          <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/category') ?>" class="mt4">
            <?= csrf_field() ?>
            <div class="field">
              <label>Change to</label>
              <select class="select" name="service_category">
                <?php foreach (service_categories() as $k => $v): ?>
                  <option value="<?= e($k) ?>" <?= $k === $woCat ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <input class="input" name="why" maxlength="120" placeholder="Why, in a few words — e.g. sidewall, had to demount">
            </div>
            <button class="btn btn--sm">Save category</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Vehicle</div></div>
      <div class="panel__body">
        <?php if ($vehicle): ?>
          <div class="strong mb2"><?= e(trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model'])) ?></div>
          <dl class="kv">
            <dt>VIN</dt><dd class="docno"><?= e($vehicle['vin']) ?></dd>
            <dt>Plate</dt><dd><?= (int) $vehicle['no_plate'] === 1 ? 'NO PLATE' : e(trim($vehicle['plate'] . ' ' . $vehicle['plate_state'])) ?></dd>
          </dl>
        <?php else: ?>
          <?php /* No vehicle record yet, but intake may have typed what the
                   caller said. Show it — the tech needs to spot the right
                   vehicle in a parking lot before any VIN exists. */ ?>
          <?php $vManual = trim(($sr['v_year'] ?: '') . ' ' . $sr['v_make'] . ' ' . $sr['v_model']); ?>
          <?php if ($vManual !== ''): ?>
            <div class="strong mb2"><?= e($vManual) ?><?= $sr['v_color'] ? ' · ' . e($sr['v_color']) : '' ?></div>
            <dl class="kv">
              <?php if ($sr['v_plate']): ?>
                <dt>Plate</dt><dd><?= e(trim($sr['v_plate'] . ' ' . $sr['v_plate_state'])) ?></dd>
              <?php endif; ?>
              <dt>VIN</dt><dd class="muted">Not captured yet — as described at intake.</dd>
            </dl>
            <div class="mt2"></div>
          <?php else: ?>
            <div class="muted text-sm mb4">No VIN captured.</div>
          <?php endif; ?>
          <button class="btn btn--sm btn--block" data-modal-open="vinModal">Capture VIN</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Customer</div></div>
      <div class="panel__body">
        <div class="strong mb2"><?= e($custName) ?></div>
        <dl class="kv">
          <dt>Phone</dt><dd><a href="tel:<?= e($customer['phone_e164']) ?>"><?= e(phone_display($customer['phone_e164'])) ?></a></dd>
          <dt>Service</dt><dd><?= e(service_type_label($est['service_type'])) ?></dd>
        </dl>
        <?php if ($sr['latitude']): ?>
          <a class="btn btn--sm btn--block mt4" target="_blank" rel="noopener"
             href="https://maps.google.com/?q=<?= e($sr['latitude']) ?>,<?= e($sr['longitude']) ?>">Navigate to the vehicle</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($wo['signature_data']): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Completion signature</div></div>
      <div class="panel__body">
        <img src="<?= e($wo['signature_data']) ?>" style="width:100%;background:#0a1120;border-radius:var(--r-md);border:1px solid var(--line)" alt="signature">
        <div class="hint"><?= e($wo['signer_name']) ?> · <?= e(fdatetime($wo['signed_at'])) ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="completeModal" data-customer-facing>
  <div class="modal panel">
    <div class="panel__head">
      <div><div class="panel__title">Close out this work order</div>
      <div class="panel__sub">Record what actually happened — this is the field record, not the estimate.</div></div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/complete') ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label class="req">Outcome</label>
          <select class="select" name="outcome_code" required>
            <?php foreach (WorkOrderController::OUTCOMES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
          </select>
          <div class="hint">An unsuccessful attempt is still billable — the service fee applies regardless of outcome.</div>
        </div>
        <div class="form-grid">
          <div class="field"><label>Odometer</label><input class="input" name="odometer" type="number"></div>
          <div class="field"><label>Signed by</label><input class="input" name="signer_name" placeholder="<?= e($custName) ?>"></div>
        </div>
        <div class="field"><label>What was done</label>
          <textarea class="textarea" name="field_notes" placeholder="Battery tested at 9.8V under load — failed. Jump started, advised replacement."><?= e($wo['field_notes']) ?></textarea>
        </div>
        <?php View::partial('partials/signature_field', [
          'id'       => 'woSig',
          'label'    => 'Customer sign-off',
          'title'    => 'Confirm the work is complete',
          'subtitle' => $wo['doc_number'] . ' — the customer is confirming the work recorded here was performed.',
          'hint'     => 'Ask every time. If they will not or cannot sign, say why below instead.',
        ]); ?>

        <?php /* Deliberately not a hard gate: a customer cannot be compelled to
                 agree the job was done well. But it cannot be left silently
                 blank either — either a signature or a reason. */ ?>
        <div class="field">
          <label>If unsigned, why</label>
          <input class="input" name="unsigned_reason"
                 placeholder="Customer left before completion / declined to sign / vehicle unattended">
          <div class="hint">Required only when there is no signature above.</div>
        </div>

        <button class="btn btn--primary btn--block">Complete work order</button>
      </form>

      <?php if ($smsOk): ?>
      <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/sign-link') ?>" class="mt4">
        <?= csrf_field() ?><input type="hidden" name="purpose" value="COMPLETION">
        <button class="btn btn--ghost btn--block"
                data-confirm="Text a sign-off link to <?= e((string) $customer['phone_e164']) ?>?">
          Send sign-off to customer via SMS instead
        </button>
        <div class="hint">
          For when they are not here to sign. Close the job with a reason now; their signature
          attaches to this work order whenever they open the link.
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-bg" id="vinModal">
  <div class="modal panel">
    <div class="panel__head">
      <div><div class="panel__title">Capture VIN</div><div class="panel__sub">Driver responsibility. Read it off the dash plate or door jamb.</div></div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/vin') ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label class="req" for="wo_vin">VIN</label>
             <input class="input" id="wo_vin" name="vin" data-vin maxlength="17"
                 style="font-family:var(--mono);letter-spacing:.08em;font-size:16px" placeholder="1HGCM82633A004352">
             <div class="hint" data-vin-hint="wo_vin">Enter the VIN, or enter a plate below to find an existing VIN record. New records require a valid VIN.</div>
        </div>
        <div class="form-grid form-grid--3" data-vehicle-picker data-vehicle-endpoint="<?= url('vehicles/options') ?>">
          <div class="field"><label>Year</label><input class="input" name="year" type="number" value="<?= e($sr['v_year']) ?>" data-veh="year"></div>
          <div class="field"><label>Make</label><input class="input" name="make" value="<?= e($sr['v_make']) ?>" data-veh="make"></div>
          <div class="field"><label>Model</label><input class="input" name="model" value="<?= e($sr['v_model']) ?>" data-veh="model"></div>
          <div class="field"><label>Plate (lookup only)</label><input class="input" name="plate" style="text-transform:uppercase" value="<?= e($sr['v_plate']) ?>"></div>
          <div class="field"><label>Plate state</label><input class="input" name="plate_state" maxlength="2" style="text-transform:uppercase" value="<?= e($sr['v_plate_state']) ?>"></div>
        </div>
        <label class="checkline"><input type="checkbox" name="no_plate" value="1"><span>No plate on this vehicle</span></label>
        <button class="btn btn--primary btn--block">Save VIN</button>
      </form>
    </div>
  </div>
</div>

<?php if ($sigOwed): ?>
<div class="modal-bg" id="signModal" data-customer-facing>
  <div class="modal panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Display for customer</div>
        <div class="panel__sub">Hand them the device. Work may not begin until this is signed.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <div class="alert alert--warn">
        <div>
          Work order <?= e($wo['doc_number']) ?> · <?= money((float) $totals['total']) ?>.
          Let the customer read the line items below before they sign. If the job has grown
          since it was quoted, add the work to this order first so they are authorizing the
          real number.
        </div>
      </div>
      <?php /* The customer's read of the scope, price only. Cost and profit
               columns on the page behind are hidden while this modal is open. */ ?>
      <div class="table-wrap mb4"><table class="tbl">
        <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Total</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td class="strong"><?= e($l['name']) ?><?php if ($l['notes']): ?><div class="text-sm faint"><?= e($l['notes']) ?></div><?php endif; ?></td>
            <td class="right num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') ?></td>
            <td class="right num"><?= money($l['unit_price']) ?></td>
            <td class="right num strong"><?= money($l['line_total']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="3" class="right muted">Tax</td><td class="right num"><?= money($totals['tax']) ?></td></tr>
        <tr><td colspan="3" class="right strong">Total</td><td class="right num strong"><?= money($totals['total']) ?></td></tr>
        </tbody>
      </table></div>
      <form method="post" action="<?= url('work-orders/' . $wo['id'] . '/sign') ?>" data-sig-required>
        <?= csrf_field() ?>
        <div class="field">
          <label class="req">Name of the person signing</label>
          <input class="input" name="signer_name" required placeholder="<?= e($custName) ?>">
        </div>
        <?php View::partial('partials/signature_field', [
          'id'       => 'woAuthSig',
          'label'    => 'Customer signature',
          'required' => true,
          'title'    => 'Authorize this work',
          'subtitle' => $wo['doc_number'] . ' \u00b7 ' . money((float) $totals['total'])
                        . ' \u2014 by signing, the customer authorizes this work at this price.',
          'hint'     => 'Captured in person, on this device. This is what allows work to begin.',
        ]); ?>
        <button class="btn btn--primary btn--block">Capture signature and begin</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
