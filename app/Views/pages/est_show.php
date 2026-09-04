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
/**
 * The Estimate is the contract: verified customer, priced scope, captured
 * authorization. Nothing reaches a technician until the dispatch gate clears.
 */
$locked   = in_array($est['status'], ['APPROVED', 'DECLINED'], true);
$custName = customer_name($customer, true);
$thresh   = (float) Rules::cfg('authorization_threshold');
$wo       = $wos[0] ?? null;

$step  = 1;
if ($lines)                     { $step = 2; }
if ($est['authorized_at'])      { $step = 3; }
if ($wos)                       { $step = 4; }
if ($invoice)                   { $step = 5; }
$chain = ['Drafted', 'Priced', 'Authorized', 'Dispatched', 'Invoiced'];
?>

<div class="panel mb4">
  <div class="chain">
    <?php foreach ($chain as $i => $label): $n = $i + 1; ?>
      <?php if ($i): ?><span class="chain__arrow" aria-hidden="true">▸</span><?php endif; ?>
      <span class="chain__step <?= $n < $step ? 'is-done' : ($n === $step ? 'is-current' : '') ?>">
        <?= $n < $step ? '<span aria-hidden="true">✓</span><span class="sr-only">Done: </span>' : ($n === $step ? '<span class="sr-only">Current step: </span>' : '') ?><?= e($label) ?>
      </span>
    <?php endforeach; ?>
  </div>
  <div class="panel__body">
    <div class="row row--between wrap">
      <div>
        <div class="row wrap">
          <span class="docno text-lg"><?= e($est['doc_number']) ?></span>
          <?= badge($est['status']) ?>
          <span class="muted">from <a href="<?= url('service-requests/' . $sr['id']) ?>"><?= e($sr['doc_number']) ?></a></span>
          <?php if (!empty($optionOf)): ?>
            <span class="badge badge--info"><i></i>Option: <?= e($est['option_label']) ?></span>
            <span class="muted">on <a href="<?= url('work-orders/' . $optionOf['work_order_id'] . '/diagnostic') ?>"><?= e($optionOf['doc_number']) ?></a><?= $est['option_timeframe'] ? ' · ' . e($est['option_timeframe']) : '' ?></span>
          <?php endif; ?>
        </div>
        <div class="mt2 muted">
          <?= e($custName) ?> · <?= e(service_type_label($est['service_type'])) ?>
          <?php $__a = doc_address($est); ?><?= $__a !== '' ? ' · ' . e($__a) : '' ?>
        </div>
      </div>
      <div class="btn-row">
        <a class="btn btn--ghost" href="<?= url('estimates/' . $est['id'] . '/print') ?>" target="_blank">Print / PDF</a>
        <?php if ($est['status'] === 'DRAFT' && $lines): ?>
          <form method="post" action="<?= url('estimates/' . $est['id'] . '/send') ?>">
            <?= csrf_field() ?><button class="btn">Send to customer</button>
          </form>
        <?php endif; ?>
        <?php if (!$locked && $lines): ?>
          <button class="btn btn--primary" data-modal-open="approveModal">Record authorization</button>
        <?php elseif ($est['status'] === 'APPROVED' && !$wos): ?>
          <button class="btn btn--primary" data-modal-open="dispatchModal">Dispatch to a technician</button>
        <?php elseif ($invoice): ?>
          <button class="btn btn--primary" data-url="<?= url('invoices/' . $invoice['id']) ?>">Open invoice</button>
        <?php elseif ($wo): ?>
          <button class="btn btn--primary" data-url="<?= url('work-orders/' . $wo['id']) ?>">Open work order</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($est['status'] === 'APPROVED' && $sigNeeded): ?>
  <div class="alert alert--warn" role="status">
    <div>
      <strong>Authorized by <?= e($est['authorized_by']) ?> on <?= e(fdatetime($est['authorized_at'])) ?>. Signature owed on the work order.</strong>
      Dispatch a technician now — verbal is enough to roll. Because this is over <?= money($thresh) ?>,
      the customer must sign the <em>work order</em> before any work is performed: either on the
      technician's device on arrival, or through a link texted to them. The work order will not
      start or close until they do.
    </div>
  </div>
<?php elseif ($est['status'] === 'APPROVED'): ?>
  <div class="alert alert--ok" role="status">
    <div>
      <strong>Authorized by <?= e($est['authorized_by']) ?> on <?= e(fdatetime($est['authorized_at'])) ?>.</strong>
      Method: <?= e(status_label((string) $est['authorization_method'])) ?><?= $est['authorization_ip'] ? ' · IP ' . e($est['authorization_ip']) : '' ?>.
      This estimate is locked. Work performed beyond this scope requires re-authorization on the invoice.
    </div>
  </div>
<?php elseif ($est['status'] === 'DECLINED'): ?>
  <div class="alert alert--danger" role="status">
    <div><strong>Customer declined.</strong> <?= e($est['decline_reason'] ?: 'No reason recorded.') ?></div>
  </div>
<?php elseif ($sigNeeded): ?>
  <div class="alert alert--info" role="status">
    <div>
      <strong>Over <?= money($thresh) ?> — the work order will need a customer signature before work begins.</strong>
      Verbal approval is all that is needed here to dispatch. The signature is taken on the work
      order: on the technician's device, or by texting the customer a link.
    </div>
  </div>
<?php endif; ?>

<?php if (!$gate['ok'] && $est['status'] !== 'DECLINED'): ?>
  <div class="alert alert--warn gate" role="status">
    <div>
      <strong>Cannot dispatch yet.</strong> <?= e($gate['reason']) ?>
      No technician is activated until this estimate is a real contract.
    </div>
  </div>
<?php endif; ?>

<div class="split">
  <div class="stack">
    <?php View::partial('partials/line_editor', [
      'lines' => $lines, 'catalog' => $catalog, 'totals' => $totals, 'locked' => $locked,
      'postUrl' => url('estimates/' . $est['id'] . '/lines'),
      'delUrlBase' => url('estimates/' . $est['id'] . '/lines'),
    ]); ?>

    <?php if ($wos): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Work orders</div></div>
      <div class="panel__body panel__body--flush">
        <table class="tbl">
          <thead><tr><th scope="col">Status</th><th scope="col">Work order</th><th scope="col">Technician</th><th scope="col">Outcome</th><th class="right" scope="col">Raised</th></tr></thead>
          <tbody>
          <?php foreach ($wos as $w): ?>
            <tr data-href="<?= url('work-orders/' . $w['id']) ?>">
              <td><?= badge($w['status']) ?></td>
              <td class="docno"><a class="row-link" href="<?= url('work-orders/' . $w['id']) ?>"><?= e($w['doc_number']) ?></a></td>
              <td>
                <?php if (!$w['technician_id'] && !in_array($w['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)): ?>
                  <form method="post" action="<?= url('work-orders/' . $w['id'] . '/assign') ?>" class="row" data-stop-row-click>
                    <?= csrf_field() ?>
                    <select class="select" name="technician_id" required aria-label="Technician">
                      <option value="">— select technician —</option>
                      <?php foreach ($techs as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn--sm" type="submit">Assign</button>
                  </form>
                <?php else: ?>
                  <?= $w['technician_id'] ? e(trim(($w['tech_first'] ?? '') . ' ' . ($w['tech_last'] ?? ''))) : '<span class="muted">Unassigned</span>' ?>
                <?php endif; ?>
              </td>
              <td class="muted"><?= e($w['outcome_code'] ? status_label($w['outcome_code']) : '—') ?></td>
              <td class="right muted text-sm nowrap"><?= e(ago($w['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Audit trail</div><div class="topbar__spacer"></div><span class="tag">Append-only</span></div>
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
      <div class="panel__head"><div class="panel__title">Customer</div></div>
      <div class="panel__body">
        <div class="strong text-lg mb2"><?= e($custName) ?></div>
        <dl class="kv">
          <dt>Phone</dt><dd><a href="tel:<?= e($customer['phone_e164']) ?>"><?= e(phone_display($customer['phone_e164'])) ?></a></dd>
          <?php if ($customer['email']): ?><dt>Email</dt><dd><?= e($customer['email']) ?></dd><?php endif; ?>
          <dt>SMS consent</dt>
          <dd><?= (int) $customer['sms_approved'] === 1
                ? '<span class="badge badge--success"><i></i>On file</span>'
                : '<span class="badge badge--warn"><i></i>None</span>' ?></dd>
          <?php if (customer_is_business($customer)): ?>
            <dt>Account</dt><dd><?= customer_badge($customer) ?></dd>
            <dt>Terms</dt><dd><?= e(payment_terms_label($customer['payment_terms'])) ?></dd>
          <?php endif; ?>
        </dl>
        <div class="btn-row mt4"><button class="btn btn--sm" data-url="<?= url('customers/' . $customer['id']) ?>">Open record</button></div>
        <?php if ($locked): ?>
          <?php if ($est['po_number']): ?><div class="hint mt4">PO # <span class="docno"><?= e($est['po_number']) ?></span></div><?php endif; ?>
        <?php else: ?>
          <form method="post" action="<?= url('estimates/' . $est['id'] . '/po') ?>" class="row mt4">
            <?= csrf_field() ?>
            <input class="input" name="po_number" aria-label="PO number" value="<?= e($est['po_number']) ?>" placeholder="PO number" maxlength="64">
            <button class="btn btn--sm">Save</button>
          </form>
          <div class="hint">The customer's purchase-order number, if they issued one. Carries to the work order and invoice.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::is('ADMIN','DISPATCH')): ?>
    <div class="panel">
      <div class="panel__head">
        <div>
          <div class="panel__title">Locate the customer</div>
          <div class="panel__sub">Texts a link that asks <em>their</em> phone where it is.</div>
        </div>
      </div>
      <div class="panel__body">
        <?php /* Every promoted estimate carries a position — from the customer's
                 phone, or from a pin a dispatcher dropped. The map is drawn
                 whenever coordinates exist, not only when a location link was
                 answered: a dropped pin sets no location_captured_at, and a job
                 that showed no map because of that would look unlocated when it
                 is not. */ ?>
        <?php if ($est['latitude']): ?>
          <div class="mb2"><?= map_embed((float) $est['latitude'], (float) $est['longitude']) ?></div>
          <div class="btn-row mb2">
            <a class="btn btn--sm btn--ghost" href="https://maps.google.com/?q=<?= e($est['latitude']) ?>,<?= e($est['longitude']) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
          </div>
          <?php if ($est['nearest_address']): ?>
            <div class="kv mb2">
              <dt>Nearest address</dt><dd><?= e(Address::oneLine($est['nearest_address'], $est['city'], $est['state'], $est['postal_code'])) ?></dd>
              <?php if ($est['nearest_intersection']): ?>
                <dt>Cross streets</dt><dd><?= e($est['nearest_intersection']) ?></dd>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($est['location_captured_at']): ?>
          <div class="alert alert--ok mb2" role="status"><div>
            <strong>Position received <?= e(ago($est['location_captured_at'])) ?></strong> from the customer's phone.
          </div></div>
        <?php elseif ($locOpen): ?>
          <div class="alert alert--info mb2" role="status"><div>
            Link sent <?= $locOpen['sent_at'] ? e(ago($locOpen['sent_at'])) : 'just now' ?>,
            <?= $locOpen['viewed_at'] ? 'opened ' . e(ago($locOpen['viewed_at'])) : 'not opened yet' ?>.
            It expires <?= e(fdate($locOpen['expires_at'], 'g:i A')) ?>.
          </div></div>
        <?php endif; ?>
        <form method="post" action="<?= url('estimates/' . $est['id'] . '/locate-link') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--sm btn--block"><?= $locOpen || $est['location_captured_at'] ? 'Send a fresh location link' : 'Text a location link' ?></button>
        </form>
        <div class="hint">The position lands on this estimate. The service address you typed is never overwritten —
          confirm against what comes back.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Vehicle</div></div>
      <div class="panel__body">
        <?php if ($vehicle): ?>
          <div class="strong mb2"><?= e(trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model'])) ?></div>
          <dl class="kv">
            <dt>VIN</dt><dd class="docno"><?= e($vehicle['vin']) ?></dd>
            <dt>Plate</dt><dd><?= (int) $vehicle['no_plate'] === 1 ? 'NO PLATE' : e(trim($vehicle['plate'] . ' ' . $vehicle['plate_state'])) ?></dd>
            <dt>Colour</dt><dd><?= e($vehicle['color'] ?: '—') ?></dd>
          </dl>
          <div class="btn-row mt4"><button class="btn btn--sm" data-url="<?= url('vehicles/' . $vehicle['id']) ?>">Open vehicle</button></div>
        <?php else: ?>
          <?php /* No vehicle record yet, but intake may have typed what the
                   caller said — show it rather than pretending the vehicle
                   is unknown. */ ?>
          <?php $vManual = trim(($sr['v_year'] ?: '') . ' ' . $sr['v_make'] . ' ' . $sr['v_model']); ?>
          <?php if ($vManual !== ''): ?>
            <div class="strong mb2"><?= e($vManual) ?><?= $sr['v_color'] ? ' · ' . e($sr['v_color']) : '' ?></div>
            <?php if ($sr['v_plate']): ?>
              <dl class="kv"><dt>Plate</dt><dd><?= e(trim($sr['v_plate'] . ' ' . $sr['v_plate_state'])) ?></dd></dl>
            <?php endif; ?>
          <?php endif; ?>
          <div class="muted text-sm mb4">
            <?= $vManual !== '' ? 'As described at intake — no VIN on file.' : 'No VIN on file.' ?>
            The driver captures it on scene — this blocks work-order completion and invoicing,
            not the estimate itself.
          </div>
          <button class="btn btn--sm btn--block" data-modal-open="vinModal">Capture VIN</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Authorization rules</div></div>
      <div class="panel__body">
        <dl class="kv">
          <dt>Signature threshold</dt><dd><?= money($thresh) ?></dd>
          <dt>This estimate</dt><dd class="strong"><?= money($totals['total']) ?></dd>
          <dt>Signature needed</dt><dd><?= $sigNeeded ? '<span class="badge badge--warn"><i></i>Yes — on the work order</span>' : '<span class="badge badge--slate"><i></i>No — verbal is enough</span>' ?></dd>
          <dt>Re-auth trigger</dt><dd><?= money(Rules::varianceThreshold($totals['total'])) ?></dd>
        </dl>
        <div class="hint">If the final invoice differs from this by more than the lesser of $200 or 10%, the customer must re-authorize before it can be issued.</div>
      </div>
    </div>

    <?php if ($est['signature_data']): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Captured signature</div></div>
      <div class="panel__body">
        <img src="<?= e($est['signature_data']) ?>" alt="Signature of <?= e($est['authorized_by'] ?: 'the customer') ?>" style="width:100%;background:#0a1120;border-radius:var(--r-md);border:1px solid var(--line)">
        <div class="hint"><?= e($est['authorized_by']) ?> · <?= e(fdatetime($est['authorized_at'])) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($est['status'] === 'APPROVED' && !$invoice): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Billing</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('estimates/' . $est['id'] . '/invoice') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--block">Raise the invoice</button>
        </form>
        <div class="hint">
          <?= $wo ? 'The invoice is built from what the technician actually recorded on the work order.'
                  : 'With no work order, the invoice is built from this authorized scope.' ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$locked): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Customer declined</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('estimates/' . $est['id'] . '/decline') ?>">
          <?= csrf_field() ?>
          <div class="field"><label for="decline_reason">Reason</label><input class="input" name="decline_reason" placeholder="Too expensive / going to a shop" id="decline_reason"></div>
          <button class="btn btn--sm btn--block">Mark declined</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="approveModal">
  <div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="approveModal_title">
    <div class="panel__head">
      <div>
        <div class="panel__title" id="approveModal_title">Record customer authorization</div>
        <div class="panel__sub">Total: <strong><?= money($totals['total']) ?></strong></div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <?php /* No data-sig-required: verbal authorization is valid here at any
               amount, because the signature is captured on arrival instead. */ ?>
      <form method="post" action="<?= url('estimates/' . $est['id'] . '/authorize') ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label class="req" for="authorized_by">Who is authorizing (first and last name)</label>
          <input class="input" name="authorized_by" required placeholder="<?= e($custName) ?>" id="authorized_by">
        </div>
        <div class="field">
          <fieldset class="radio-group"><legend>How was it authorized</legend>
          <div class="radio-row">
            <?php foreach (['VERBAL' => 'Verbal', 'SMS' => 'Text message', 'IN_PERSON' => 'In person', 'PROVIDER_PO' => 'Provider PO'] as $k => $v): ?>
              <label class="radio-card"><input type="radio" name="authorization_method" value="<?= e($k) ?>" <?= $k === 'VERBAL' ? 'checked' : '' ?>><span><?= e($v) ?></span></label>
            <?php endforeach; ?>
          </div></fieldset>
        </div>

        <?php /* No signature pad here. The estimate only ever needs verbal
                 approval — that is what releases the technician. The customer's
                 signature is taken on the WORK ORDER, before work begins, on
                 the technician's device or through a texted link. */ ?>
        <div class="alert alert--info" role="status">
          <div>
            Name, time, IP address and device are recorded against this authorization.
            <?php if ($sigNeeded): ?>
              Because this estimate is over <?= money($thresh) ?>, the customer will also have to
              sign the work order before any work is performed.
            <?php endif; ?>
          </div>
        </div>
        <button class="btn btn--primary btn--block">Record authorization</button>
      </form>
    </div>
  </div>
</div>

<div class="modal-bg" id="dispatchModal">
  <div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="dispatchModal_title">
    <div class="panel__head">
      <div>
        <div class="panel__title" id="dispatchModal_title">Dispatch to the field</div>
        <div class="panel__sub">This raises the work order — the document that actually activates a technician.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('estimates/' . $est['id'] . '/dispatch') ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label for="technician_id">Technician</label>
          <select class="select" name="technician_id" id="technician_id">
            <option value="">— leave unassigned, dispatch later —</option>
            <?php foreach ($techs as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?> (<?= e(ucfirst(strtolower($t['role']))) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Only active technicians accepting jobs are listed.</div>
        </div>
        <div class="alert alert--info" role="status"><div>The authorized scope is copied onto the work order. Anything the technician adds in the field is measured against it.</div></div>
        <button class="btn btn--primary btn--block">Raise the work order</button>
      </form>
    </div>
  </div>
</div>

<div class="modal-bg" id="vinModal">
  <div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="vinModal_title">
    <div class="panel__head">
      <div>
        <div class="panel__title" id="vinModal_title">Capture VIN</div>
        <div class="panel__sub">The driver is responsible for this, not the customer.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('estimates/' . $est['id'] . '/vehicle') ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label class="req" for="vin_input">VIN</label>
          <input class="input" id="vin_input" name="vin" data-vin maxlength="17" style="font-family:var(--mono);letter-spacing:.08em" placeholder="1HGCM82633A004352">
          <div class="hint" data-vin-hint="vin_input" aria-live="polite">Enter a VIN to create or find a vehicle. If the VIN is unavailable, enter a plate below to find an existing VIN record.</div>
        </div>
        <div class="form-grid form-grid--3" data-vehicle-picker data-vehicle-endpoint="<?= url('vehicles/options') ?>">
          <div class="field"><label for="year">Year</label><input class="input" name="year" type="number" value="<?= e($sr['v_year']) ?>" data-veh="year" id="year"></div>
          <div class="field"><label for="make">Make</label><input class="input" name="make" value="<?= e($sr['v_make']) ?>" data-veh="make" id="make"></div>
          <div class="field"><label for="model">Model</label><input class="input" name="model" value="<?= e($sr['v_model']) ?>" data-veh="model" id="model"></div>
          <div class="field"><label for="color">Colour</label><input class="input" name="color" value="<?= e($sr['v_color']) ?>" id="color"></div>
          <div class="field"><label for="plate">Plate</label><input class="input" name="plate" value="<?= e($sr['v_plate']) ?>" style="text-transform:uppercase" id="plate"></div>
          <div class="field"><label for="plate_state">Plate state</label>
            <select class="select" name="plate_state" id="plate_state">
              <option value="">—</option>
              <?php foreach (us_states() as $s): ?><option <?= $s === $sr['v_plate_state'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="checkline"><input type="checkbox" name="no_plate" value="1"><span>No plate on this vehicle</span></label>
        <div class="field"><label for="no_plate_reason">Reason there is no plate</label>
          <select class="select" name="no_plate_reason" id="no_plate_reason">
            <option value="">—</option>
            <option>NoPlateIssued</option><option>PlateMissing</option><option>PlateObstructed</option>
            <option>FleetNoPlatePolicy</option><option>CustomerDeclined</option>
          </select>
        </div>
        <button class="btn btn--primary btn--block">Save VIN and link the vehicle</button>
      </form>
    </div>
  </div>
</div>
