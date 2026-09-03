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
 * The Service Request is deliberately thin: roughly who, roughly what, roughly
 * where. It has no prices, no line items and no vehicle. Promoting it is the
 * deliberate act that turns hearsay into a contract.
 */
$reportedName = trim((string) $sr['reported_name']);
$closed       = in_array($sr['status'], ['CANCELLED', 'REJECTED'], true);
$openEst      = null;
foreach ($estimates as $x) { if ($x['status'] !== 'DECLINED') { $openEst = $x; break; } }

/* Everything the request already knows, offered to the promote form as a
 * starting point. All of it stays editable — these are suggestions from
 * hearsay, and promotion is where a human confirms them. What they must never
 * be is BLANK when the request holds the answer: retyping a name and address
 * the system already has is how transcription errors get into the customer
 * record, and the record is what gets invoiced. */
$parts = explode(' ', $reportedName, 2);
$guessFirst = $parts[0] ?? '';
$guessLast  = $parts[1] ?? '';

/* A commercial or fleet caller gives a business name, and it arrives in the
 * same reported_name field a person's name does. Offer it to the company box
 * too; the customer-type selector decides which field is actually in play. */
$guessCompany = $reportedName;

/* The nearest physical address the request already holds, split into its
 * pieces for the pin map. reported_location is deliberately NOT a candidate:
 * it is the caller's description of where they are, and a description is not
 * an address. It is shown beside the map to be read, not copied into it. */
$guessAddress = Address::split((string) ($sr['nearest_address'] ?? ''));
if ($guessAddress['line'] === '' && $sr['nearest_address']) {
    $guessAddress['line'] = (string) $sr['nearest_address'];
}

/* Reported as digits, shown the way a dispatcher reads it back. */
$guessPhone = phone_display($sr['reported_phone']) !== '—'
    ? phone_display($sr['reported_phone']) : '';

// When the caller never described a location but their phone shared one, the
// edit form suggests the GPS answer instead of opening blank. The OSM/Google
// drivers compose the line as "street, city, State ZIP", which parses back
// apart cleanly; a save is still a dispatcher's deliberate, audited act.
$gpsGuess = ['loc' => '', 'city' => '', 'state' => '', 'zip' => ''];
if (trim((string) $sr['reported_location']) === '' && $sr['nearest_address']) {
    $gpsGuess['loc'] = (string) $sr['nearest_address'];
    $parts = array_map('trim', explode(',', (string) $sr['nearest_address']));
    if (count($parts) >= 3 && (string) $sr['city'] === '') {
        $gpsGuess['city'] = $parts[count($parts) - 2];
        if (preg_match('/^([A-Za-z. ]+?)\s*(\d{5})?$/', $parts[count($parts) - 1], $m)) {
            $gpsGuess['state'] = us_state_abbrev($m[1]);
            $gpsGuess['zip']   = $m[2] ?? '';
        }
    }
}

/* CONTINUING AN INTAKE THAT WAS CUT SHORT.
 * "Capture GPS" on the intake form is a submit sitting in the middle of the
 * form — deliberately, so the caller's phone is asked for its position while
 * they are still on the line. What that costs is the rest of the form, and the
 * dispatcher is still on the call. store() redirects here with ?continue=vehicle
 * so the edit form opens at the vehicle description instead of being something
 * to go and find. Only meaningful while the request is still editable. */
$continueVehicle = input('continue') === 'vehicle' && $sr['status'] === 'PENDING';

$step  = $estimates ? 2 : 1;
if ($openEst && $openEst['status'] === 'APPROVED') { $step = 3; }
if ($sr['status'] === 'COMPLETED') { $step = 4; }
$chain = ['Requested', 'Estimated', 'Authorized', 'Closed'];
?>

<div class="panel mb4">
  <div class="chain">
    <?php foreach ($chain as $i => $label): $n = $i + 1; ?>
      <?php if ($i): ?><span class="chain__arrow">▸</span><?php endif; ?>
      <span class="chain__step <?= $n < $step ? 'is-done' : ($n === $step ? 'is-current' : '') ?>">
        <?= $n < $step ? '✓' : '' ?><?= e($label) ?>
      </span>
    <?php endforeach; ?>
  </div>
  <div class="panel__body">
    <div class="row row--between wrap">
      <div>
        <div class="row wrap">
          <span class="docno text-lg"><?= e($sr['doc_number']) ?></span>
          <?= badge($sr['status']) ?>
          <?php if ($sr['priority'] === 'EMERGENCY'): ?><span class="badge badge--danger"><i></i>Emergency</span><?php endif; ?>
          <span class="badge badge--<?= $sr['job_source'] === 'PROVIDER' ? 'accent' : 'slate' ?>"><i></i><?= $sr['job_source'] === 'PROVIDER' ? 'Provider job' : 'Retail' ?></span>
          <span class="tag"><?= e(status_label((string) $sr['channel'])) ?></span>
        </div>
        <div class="mt2 muted">
          Reported as <?= e(service_type_label($sr['reported_service'])) ?> ·
          logged <?= e(fdatetime($sr['created_at'])) ?>
          <?php if ($sr['promised_eta']): ?> · promised ETA <?= e(fdate($sr['promised_eta'], 'g:i A')) ?><?php endif; ?>
        </div>
      </div>
      <div class="btn-row">
        <?php if (Auth::is('ADMIN','DISPATCH')): ?>
          <?php if ($sr['status'] === 'PENDING'): ?>
            <button class="btn btn--ghost" data-modal-open="editModal">Edit details</button>
            <button class="btn btn--ghost" data-modal-open="rejectModal">Reject</button>
          <?php endif; ?>
          <?php if ($openEst): ?>
            <button class="btn btn--primary" data-url="<?= url('estimates/' . $openEst['id']) ?>">Open estimate <?= e($openEst['doc_number']) ?></button>
          <?php elseif (!$closed): ?>
            <button class="btn btn--primary" data-modal-open="promoteModal">Promote to estimate</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$customer && !$closed): ?>
  <div class="alert alert--info">
    <div>
      <strong>Nothing here is verified yet.</strong>
      No customer record exists for this request, no vehicle is attached and nothing has been priced.
      That is exactly what a service request is — a note that somebody needs help. Promote it when you
      have confirmed who is actually calling.
    </div>
  </div>
<?php endif; ?>

<div class="split">
  <div class="stack">

    <div class="panel">
      <div class="panel__head">
        <div>
          <div class="panel__title">What was reported</div>
          <div class="panel__sub">Recorded as given. Nothing here has been checked.</div>
        </div>
      </div>
      <div class="panel__body">
        <?php if ($sr['reported_problem']): ?>
          <p style="margin:0 0 12px"><?= nl2br(e($sr['reported_problem'])) ?></p>
        <?php else: ?>
          <p class="muted" style="margin:0 0 12px">No description was given.</p>
        <?php endif; ?>
        <dl class="kv">
          <dt>Name as given</dt><dd><?= e($reportedName ?: '—') ?></dd>
          <dt>Callback</dt><dd><?= e(phone_display($sr['reported_phone'])) ?></dd>
          <dt>Service</dt><dd><?= e(service_type_label($sr['reported_service'])) ?></dd>
          <dt>Rolled as</dt><dd><?= e(ServiceCategory::label($sr['service_category'] ?? null)) ?></dd>
          <dt>Location</dt>
          <dd><?= e($sr['reported_location'] ?: '—') ?><?= $sr['city'] ? '<br>' . e($sr['city']) . ', ' . e($sr['state']) . ' ' . e($sr['postal_code']) : '' ?></dd>
          <?php if ($sr['latitude']): ?>
            <dt>GPS position</dt>
            <dd>
              <a href="https://maps.google.com/?q=<?= e($sr['latitude']) ?>,<?= e($sr['longitude']) ?>" target="_blank" rel="noopener"><?= e($sr['latitude']) ?>, <?= e($sr['longitude']) ?></a>
              <?php if ($sr['location_captured_at']): ?><span class="faint text-sm"> · shared by the customer <?= e(ago($sr['location_captured_at'])) ?></span><?php endif; ?>
            </dd>
          <?php endif; ?>
          <?php if ($sr['nearest_address']): ?>
            <dt>Nearest address</dt><dd><?= e($sr['nearest_address']) ?></dd>
          <?php endif; ?>
          <?php if ($sr['nearest_intersection']): ?>
            <dt>Nearest intersection</dt><dd><?= e($sr['nearest_intersection']) ?></dd>
          <?php endif; ?>
          <dt>Vehicle as described</dt>
          <dd><?= e(trim(($sr['v_year'] ?: '') . ' ' . $sr['v_make'] . ' ' . $sr['v_model'])) ?: '—' ?>
              <?= $sr['v_color'] ? ' · ' . e($sr['v_color']) : '' ?>
              <?= $sr['v_plate'] ? ' · plate ' . e($sr['v_plate']) . ' (' . e($sr['v_plate_state']) . ')' : '' ?></dd>
          <?php if ($provider): ?><dt>Provider</dt><dd><?= e($provider['company']) ?></dd><?php endif; ?>
          <?php if ($sr['provider_ref']): ?><dt>Provider ref</dt><dd class="docno"><?= e($sr['provider_ref']) ?></dd><?php endif; ?>
          <?php if ($sr['close_reason']): ?><dt>Closed because</dt><dd><?= e($sr['close_reason']) ?></dd><?php endif; ?>
        </dl>
        <div class="hint">A plate is a description, not an identity. Only a valid VIN creates a vehicle record, and that happens on scene.</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Estimates raised from this request</div></div>
      <div class="panel__body panel__body--flush">
        <?php if (!$estimates): ?>
          <div class="empty">
            <div class="empty__icon">▤</div>
            <div class="empty__title">Nothing priced yet</div>
            <div class="empty__body">Promote this request to open an estimate. That is where the customer is confirmed, the work is priced and the authorization is captured.</div>
            <?php if (!$closed && Auth::is('ADMIN','DISPATCH')): ?>
              <button class="btn btn--primary" data-modal-open="promoteModal">Promote to estimate</button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Status</th><th>Estimate</th><th>Authorized by</th><th class="right">Total</th><th class="right">Opened</th></tr></thead>
            <tbody>
            <?php foreach ($estimates as $x): ?>
              <tr data-href="<?= url('estimates/' . $x['id']) ?>">
                <td><?= badge($x['status']) ?></td>
                <td class="docno"><?= e($x['doc_number']) ?></td>
                <td class="muted"><?= e($x['authorized_by'] ?: '—') ?></td>
                <td class="right num strong"><?= money($x['total']) ?></td>
                <td class="right muted text-sm nowrap"><?= e(ago($x['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Dispatcher notes</div><div class="topbar__spacer"></div><span class="tag">Internal only</span></div>
      <div class="panel__body">
        <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/notes') ?>">
          <?= csrf_field() ?>
          <textarea class="textarea" name="intake_notes"><?= e($sr['intake_notes']) ?></textarea>
          <div class="mt2"><button class="btn btn--sm">Save notes</button></div>
        </form>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Audit trail</div><div class="topbar__spacer"></div><span class="tag">Append-only</span></div>
      <div class="panel__body">
        <ul class="timeline">
          <?php foreach ($audit as $ev): ?>
            <li>
              <div class="t-act"><?= e($ev['action']) ?><?= $ev['detail'] ? ' — <span class="muted" style="font-weight:400">' . e($ev['detail']) . '</span>' : '' ?></div>
              <div class="t-meta"><?= e($ev['actor_name']) ?> · <?= e(fdatetime($ev['created_at'])) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$audit): ?><li><div class="t-meta">No events recorded yet.</div></li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Customer</div></div>
      <div class="panel__body">
        <?php if ($customer): ?>
          <div class="strong text-lg mb2"><?= e(customer_name($customer, true)) ?></div>
          <dl class="kv">
            <dt>Phone</dt><dd><?= e(phone_display($customer['phone_e164'])) ?></dd>
            <?php if ($customer['email']): ?><dt>Email</dt><dd><?= e($customer['email']) ?></dd><?php endif; ?>
            <dt>SMS consent</dt>
            <dd><?= (int) $customer['sms_approved'] === 1
                  ? '<span class="badge badge--success"><i></i>On file</span>'
                  : '<span class="badge badge--warn"><i></i>None</span>' ?></dd>
          </dl>
          <div class="btn-row mt4">
            <button class="btn btn--sm" data-url="<?= url('customers/' . $customer['id']) ?>">Open record</button>
          </div>
        <?php else: ?>
          <div class="muted text-sm mb4">
            None bound yet. A request never creates a customer — that happens at promotion, once you have
            confirmed the caller is who they say they are.
          </div>
          <?php if ($candidates): ?>
            <div class="tag mb2">Possible matches on that number</div>
            <?php foreach ($candidates as $c): ?>
              <div class="row row--between" style="padding:6px 0;border-top:1px solid var(--line)">
                <div class="text-sm"><?= e(customer_name($c, true)) ?>
                  <div class="faint"><?= e(phone_display($c['phone_e164'])) ?></div></div>
                <a class="btn btn--ghost btn--sm" href="<?= url('customers/' . $c['id']) ?>">View</a>
              </div>
            <?php endforeach; ?>
            <div class="hint">A shared number is a hint, never an identity. Two people can use one phone.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::is('ADMIN','DISPATCH') && !$closed): ?>
    <div class="panel">
      <div class="panel__head">
        <div>
          <div class="panel__title">Locate the caller</div>
          <div class="panel__sub">Texts a link that asks <em>their</em> phone where it is.</div>
        </div>
      </div>
      <div class="panel__body">
        <?php /* A position can arrive three ways, and all three end in the same
                 place: an address, a pin, and coordinates. The customer's phone
                 answers a texted link; a dispatcher types an address and it is
                 geocoded; a dispatcher drops the pin by hand and the nearest
                 address is worked back out of it.
                 The map is therefore drawn whenever coordinates exist — it used
                 to require location_captured_at, which only the first of the
                 three ever sets, so the other two showed no map and the job
                 looked unlocated when it was not. What that timestamp still
                 tells us is PROVENANCE, and that is worth saying out loud: a
                 position the customer's own device reported is different
                 evidence from one somebody in the office inferred. */ ?>
        <?php if ($sr['latitude']): ?>
          <div class="mb2"><?= map_embed((float) $sr['latitude'], (float) $sr['longitude']) ?></div>
          <div class="btn-row mb2">
            <a class="btn btn--sm btn--ghost" href="https://maps.google.com/?q=<?= e($sr['latitude']) ?>,<?= e($sr['longitude']) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
          </div>
          <dl class="kv mb2">
            <?php if ($sr['nearest_address']): ?>
              <dt>Address</dt>
              <dd><?= e(Address::oneLine($sr['nearest_address'], $sr['city'], $sr['state'], $sr['postal_code'])) ?></dd>
            <?php endif; ?>
            <?php if ($sr['nearest_intersection']): ?>
              <dt>Cross streets</dt><dd><?= e($sr['nearest_intersection']) ?></dd>
            <?php endif; ?>
            <dt>Coordinates</dt><dd><?= e($sr['latitude']) ?>, <?= e($sr['longitude']) ?></dd>
          </dl>
        <?php endif; ?>

        <?php if ($sr['location_captured_at']): ?>
          <div class="alert alert--ok mb2"><div>
            <strong>Position received <?= e(ago($sr['location_captured_at'])) ?> from the customer's phone.</strong>
            Their device reported it — the strongest answer available.
          </div></div>
        <?php elseif ($sr['latitude']): ?>
          <div class="alert alert--info mb2"><div>
            <strong>Position set by dispatch.</strong>
            Worked out from the address or placed on the map by hand, not reported by the
            customer's phone. Text them a link below if you want their device to confirm it.
          </div></div>
        <?php elseif ($locOpen): ?>
          <div class="alert alert--info mb2"><div>
            Link sent <?= $locOpen['sent_at'] ? e(ago($locOpen['sent_at'])) : 'just now' ?>,
            <?= $locOpen['viewed_at'] ? 'opened ' . e(ago($locOpen['viewed_at'])) : 'not opened yet' ?>.
            It expires <?= e(fdate($locOpen['expires_at'], 'g:i A')) ?>.
          </div></div>
        <?php endif; ?>
        <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/locate-link') ?>">
          <?= csrf_field() ?>
          <button class="btn btn--sm btn--block"><?= $locOpen || $sr['location_captured_at'] ? 'Send a fresh location link' : 'Text a location link' ?></button>
        </form>
        <div class="hint">One-shot link, dead after <?= LocationRequest::EXPIRY_HOURS ?> hours. Blocked unless the
          intake consent box was ticked and the callback number is valid. Nothing sends silently.</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (Auth::is('ADMIN','DISPATCH') && $customer): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Send an update</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/sms') ?>">
          <?= csrf_field() ?>
          <select class="select mb2" name="template">
            <option value="dispatch">Technician en route + ETA</option>
            <option value="on_site">Technician has arrived</option>
            <option value="optin">SMS opt-in confirmation</option>
          </select>
          <button class="btn btn--sm btn--block">Queue message</button>
        </form>
        <div class="hint">Blocked automatically when there is no consent on file. Nothing sends silently.</div>
        <?php if ($messages): ?>
          <div class="mt4 stack-sm">
            <?php foreach (array_slice($messages, 0, 4) as $m): ?>
              <div class="text-sm" style="border-top:1px solid var(--line);padding-top:8px">
                <?= badge($m['status']) ?>
                <div class="muted mt2"><?= e($m['body']) ?></div>
                <?php if ($m['blocked_reason']): ?><div class="faint text-xs mt2"><?= e($m['blocked_reason']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (Auth::is('ADMIN','DISPATCH') && !$closed && $sr['status'] !== 'COMPLETED'): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Close this request</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/status') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="status" value="CANCELLED">
          <div class="field"><label class="req">Cancellation reason</label>
            <input class="input" name="close_reason" required placeholder="Customer got a jump from a passer-by">
          </div>
          <button class="btn btn--danger btn--sm btn--block" data-confirm="Cancel this request?">Cancel request</button>
        </form>
        <div class="hint">If a trip fee applies, bill it through an estimate and invoice rather than cancelling silently.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::is('ADMIN','DISPATCH') && !$closed && !$openEst): ?>
<?php /* A validation bounce (bad phone, gate, duplicate warning) re-opens the
         modal with everything the dispatcher typed still in place. */ ?>
<div class="modal-bg <?= old_filled() ? 'is-open' : '' ?>" id="promoteModal">
  <div class="modal panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Promote to an estimate</div>
        <div class="panel__sub">This is where the record stops being hearsay: confirm the customer, then price the work.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/promote') ?>">
        <?= csrf_field() ?>

        <?php /* On a provider job there is nothing to choose: the provider IS
                 the customer of record, bound from the request. Showing the
                 search and the create-a-customer form here would invite a
                 dispatcher to bind the motorist and quietly bill the wrong
                 party. Rules::providerJobGate refuses the promotion outright
                 when no account has been picked yet. */
              $isProviderJob = strtoupper((string) $sr['job_source']) === 'PROVIDER'; ?>
        <?php if ($isProviderJob): ?>
          <?php if ($provider): ?>
            <div class="alert alert--info mb3">
              <div><strong>Provider job — the invoice goes to <?= e($provider['company']) ?>.</strong>
                They are the customer of record on this estimate; the caller is not made into a customer.
                <?= e($reportedName ?: 'The caller') ?> stays on the request as reported, and the
                en-route and arrival texts go to their number, not the provider's.
                <?php if ($sr['provider_ref']): ?><br>Claim ref <span class="docno"><?= e($sr['provider_ref']) ?></span>
                  carries onto the estimate as the PO number.<?php endif; ?></div>
            </div>
          <?php else: ?>
            <div class="alert alert--danger mb3">
              <div><strong>No provider account chosen.</strong> This is a provider job, so the provider is
                who the invoice goes to — pick the account under Edit details → Source before promoting,
                or change the source to Retail if the motorist is paying.</div>
            </div>
          <?php endif; ?>
        <?php else: ?>
        <div class="field" data-cust-picker>
          <label>Existing customer</label>
          <input type="hidden" name="customer_id" value="">
          <input class="input" type="text" data-cust-q data-endpoint="<?= url('customers/search') ?>"
                 placeholder="Search by name, company or phone…" autocomplete="off">
          <div class="stack mt2 <?= $candidates ? '' : 'hide' ?>" data-cust-results>
            <?php foreach ($candidates as $c): ?>
              <button type="button" class="btn btn--ghost btn--sm btn--block" style="justify-content:flex-start"
                      data-cust-candidate data-id="<?= (int) $c['id'] ?>"
                      data-label="<?= e(customer_name($c, true) . ' · ' . phone_display($c['phone_e164'])) ?>">
                ★ <?= e(customer_name($c, true)) ?> · <?= e(phone_display($c['phone_e164'])) ?> · <?= e(customer_type_label($c['customer_type'])) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="hint">Starred entries match the reported number or name — a hint, never an identity; verify before binding.
            Search binds an existing record; leave this empty to create a new customer below.</div>
        </div>

        <div class="tag mb2">— or create the customer —</div>
        <div class="form-grid">
          <div class="field col-span-full"><label>Who is the customer?</label>
            <select class="select" name="customer_type" data-cust-type>
              <?php foreach (customer_types() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= old('customer_type') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Fleet operator = their business <em>is</em> vehicles (couriers, trucking, delivery);
              a business that merely owns several vehicles is Commercial.</div></div>
          <div class="field col-span-full" data-when-cust="business"><label class="req">Company (the customer of record)</label>
            <input class="input" name="company" value="<?= e(old('company', $guessCompany)) ?>">
            <div class="hint">Carried from the reported name — correct it to the legal business name.</div></div>
          <div class="field"><label>First name</label><input class="input" name="first_name" value="<?= e(old('first_name', $guessFirst)) ?>"></div>
          <div class="field"><label>Last name</label><input class="input" name="last_name" value="<?= e(old('last_name', $guessLast)) ?>"></div>
          <div class="field"><label>Phone</label>
            <input class="input" name="phone" data-mask="phone" value="<?= e(old('phone', $guessPhone)) ?>">
            <div class="hint">Carried from the callback number. Required when creating a new record.</div>
          </div>
          <div class="field"><label>Email</label><input class="input" name="email" type="email" value="<?= e(old('email')) ?>"></div>
        </div>
        <label class="checkline">
          <input type="checkbox" name="sms_approved" value="1" <?= (old_filled() ? old('sms_approved') : (int) $sr['comms_consent'] === 1) ? 'checked' : '' ?>>
          <span>Customer consented to SMS updates. <span class="faint">Timestamped and sourced on the customer record.</span></span>
        </label>
        <label class="checkline">
          <input type="checkbox" name="dup_override" value="1">
          <span>Not a duplicate — create a new record even though a similar customer is on file.
            <span class="faint">Only needed after a duplicate warning; the override is audited.</span></span>
        </label>
        <?php endif; /* not a provider job */ ?>

        <div class="tag mb2 mt4">— confirm the work —</div>
        <div class="form-grid">
          <div class="field">
            <label>Service</label>
            <select class="select" name="service_type">
              <?php foreach (service_types() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $k === old('service_type', (string) $sr['reported_service']) ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field col-span-2"><label>Scope</label>
            <textarea class="textarea" name="scope_summary" placeholder="Expected work to be performed"><?= e(old('scope_summary', (string) $sr['reported_problem'])) ?></textarea>
          </div>
        </div>

        <div class="tag mb2 mt4">— where the vehicle is —</div>
        <?php if (trim((string) $sr['reported_location']) !== ''): ?>
          <div class="alert alert--info mb3">
            <div><span class="strong">They said:</span> <?= e($sr['reported_location']) ?>
              <span class="faint">— their words, kept on the request. Turn it into an address below.</span></div>
          </div>
        <?php endif; ?>
        <?php View::partial('partials/pinmap', ['pin' => [
            'name'   => 'pin',
            'lat'    => old('pin_lat', $sr['latitude']),
            'lng'    => old('pin_lng', $sr['longitude']),
            'line'   => old('pin_line', $guessAddress['line']),
            'city'   => old('pin_city', $guessAddress['city'] !== '' ? $guessAddress['city'] : (string) $sr['city']),
            'state'  => old('pin_state', $guessAddress['state'] !== '' ? $guessAddress['state'] : (string) $sr['state']),
            'postal' => old('pin_postal', $guessAddress['postal'] !== '' ? $guessAddress['postal'] : (string) $sr['postal_code']),
        ]]); ?>

        <div class="alert alert--info">
          <div>The estimate opens as a draft. Pricing, VIN capture and the customer authorization all happen there — and nothing dispatches until that authorization exists.</div>
        </div>
        <button class="btn btn--primary btn--block">Promote and open the estimate</button>
      </form>
    </div>
  </div>
</div>

<?php if (Auth::is('ADMIN','DISPATCH') && $sr['status'] === 'PENDING'): ?>
<div class="modal-bg <?= $continueVehicle ? 'is-open' : '' ?>" id="editModal">
  <div class="modal panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Edit the reported details</div>
        <div class="panel__sub">Still hearsay — correct it freely. Every change lands on the audit trail.
          Editing locks once the request is promoted; after that, corrections belong on the estimate.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/edit') ?>">
        <?= csrf_field() ?>

        <div class="tag mb2">— who and what —</div>
        <div class="form-grid">
          <div class="field"><label>Name as given</label>
            <input class="input" name="reported_name" value="<?= e($sr['reported_name']) ?>"></div>
          <div class="field"><label>Callback number</label>
            <input class="input" name="reported_phone" data-mask="phone"
                   value="<?= e(phone_display($sr['reported_phone']) !== '—' ? phone_display($sr['reported_phone']) : $sr['reported_phone']) ?>"></div>
          <?php /* Same order as intake: the kit first, then what that kit is
                   being sent to do. See sr_new.php. */
                $srCat  = ServiceCategory::coerce(
                            $sr['service_category'] ?? null, (string) $sr['reported_service']);
                $srType = (string) $sr['reported_service'];
                /* A request logged before the split carries a type that is no
                   longer offered. It stays selectable on its own record —
                   editing the callback number must not quietly rename the job
                   to one nobody verified. */
                $srLegacy = !isset(service_types()[$srType]) ? $srType : ''; ?>
          <div class="field">
            <label>Roll as</label>
            <select class="select" name="service_category" id="service_category"
                    data-service-types="<?= e(json_encode(service_types_by_category())) ?>">
              <?php foreach (service_categories() as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $k === $srCat ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Probably this service</label>
            <select class="select" name="reported_service" id="nature_of_service"
                    data-legacy-value="<?= e($srLegacy) ?>"
                    data-legacy-label="<?= e($srLegacy !== '' ? service_type_label($srLegacy) : '') ?>">
              <?php if ($srLegacy !== ''): ?>
                <option value="<?= e($srLegacy) ?>" selected><?= e(service_type_label($srLegacy)) ?></option>
              <?php endif; ?>
              <?php foreach (service_types_for($srCat) as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $k === $srType ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Priority</label>
            <select class="select" name="priority">
              <?php foreach (['EMERGENCY' => 'Emergency', 'URGENT' => 'Urgent', 'STANDARD' => 'Standard', 'APPOINTMENT' => 'Appointment'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $k === $sr['priority'] ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Promised ETA <span class="faint">(optional)</span></label>
            <input class="input" name="promised_eta_time" type="time" step="900">
            <div class="hint">Blank keeps <?= $sr['promised_eta'] ? 'the current promise, ' . e(fdate($sr['promised_eta'], 'g:i A')) : 'no promise on record' ?>. Set a time only if you quoted the caller one — quarter-hour times.</div>
          </div>
          <div class="field col-span-2"><label>The problem in their words</label>
            <textarea class="textarea" name="reported_problem"><?= e($sr['reported_problem']) ?></textarea></div>
        </div>
        <label class="checkline">
          <input type="checkbox" name="comms_consent" value="1" <?= (int) $sr['comms_consent'] === 1 ? 'checked' : '' ?>>
          <span>Caller verbally agreed to receive SMS updates.</span>
        </label>

        <div class="tag mb2 mt4">— where —</div>
        <?php $selState = $gpsGuess['state'] !== '' ? $gpsGuess['state'] : (string) $sr['state']; ?>
        <div class="form-grid">
          <div class="field col-span-2"><label>Where they say they are</label>
            <input class="input" name="reported_location" value="<?= e($sr['reported_location'] ?: $gpsGuess['loc']) ?>">
            <?php if ($gpsGuess['loc']): ?><div class="hint">Suggested from the GPS position the caller shared — edit freely, saving is what makes it the record.</div><?php endif; ?>
          </div>
          <div class="field"><label>City</label><input class="input" name="city" value="<?= e($sr['city'] ?: $gpsGuess['city']) ?>"></div>
          <div class="field"><label>State</label>
            <select class="select" name="state">
              <?php foreach (us_states() as $s): ?><option <?= $s === $selState ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>ZIP</label><input class="input" name="postal_code" maxlength="10" value="<?= e($sr['postal_code'] ?: $gpsGuess['zip']) ?>"></div>
        </div>
        <div class="hint">The GPS position isn't edited here — that only comes from the caller's own phone via a location link.</div>

        <div class="tag mb2 mt4" <?= $continueVehicle ? 'data-scroll-into-view' : '' ?>>— vehicle as described —</div>
        <?php if ($continueVehicle): ?>
          <div class="hint mb2">The location link is on its way to the caller. Finish describing the
            vehicle while you still have them — the driver spots the car by this, and the estimate's
            vehicle form opens pre-filled from it.</div>
        <?php endif; ?>
        <div class="form-grid form-grid--3" data-vehicle-picker data-vehicle-endpoint="<?= url('vehicles/options') ?>">
          <div class="field"><label>Year</label>
            <input class="input" name="v_year" type="number" min="1900" max="<?= date('Y') + 1 ?>" value="<?= e((string) ($sr['v_year'] ?? '')) ?>" data-veh="year"></div>
          <div class="field"><label>Make</label>
            <input class="input" name="v_make" value="<?= e($sr['v_make']) ?>" <?= $continueVehicle ? 'data-scroll-focus' : '' ?> data-veh="make"></div>
          <div class="field"><label>Model</label><input class="input" name="v_model" value="<?= e($sr['v_model']) ?>" data-veh="model"></div>
          <div class="field"><label>Colour</label><input class="input" name="v_color" value="<?= e($sr['v_color']) ?>"></div>
          <div class="field"><label>Plate</label><input class="input" name="v_plate" style="text-transform:uppercase" value="<?= e($sr['v_plate']) ?>"></div>
          <div class="field"><label>Plate state</label>
            <select class="select" name="v_plate_state">
              <option value="">—</option>
              <?php foreach (us_states() as $s): ?><option <?= $s === $sr['v_plate_state'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="tag mb2 mt4">— source —</div>
        <div class="form-grid">
          <div class="field">
            <label>How did it come in</label>
            <select class="select" name="channel">
              <?php foreach (['PHONE' => 'Phone call', 'WEB' => 'Website form', 'SMS' => 'Text message', 'PROVIDER_API' => 'Provider — electronic dispatch', 'WALK_IN' => 'Walk-up'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $k === $sr['channel'] ? 'selected' : '' ?>><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Job source</label>
            <select class="select" name="job_source" data-job-source>
              <option value="RETAIL" <?= $sr['job_source'] === 'RETAIL' ? 'selected' : '' ?>>Retail — direct customer</option>
              <option value="PROVIDER" <?= $sr['job_source'] === 'PROVIDER' ? 'selected' : '' ?>>Provider / bulk referral</option>
            </select>
          </div>
          <?php /* Shown or hidden from the SAVED source, not from a default, so
                   an existing provider job opens with its account visible and a
                   retail one does not flash it. Correcting the source to Retail
                   hides these AND drops the link on save — Rules::providerLink,
                   with both changes named on the audit trail. */
                $provHide = $sr['job_source'] === 'PROVIDER' ? '' : ' hide'; ?>
          <div class="field<?= $provHide ?>" data-when-source="PROVIDER">
            <label>Provider account</label>
            <select class="select" name="provider_id">
              <option value="">—</option>
              <?php foreach ($providers as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $sr['provider_id'] ? 'selected' : '' ?>><?= e($p['company']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field<?= $provHide ?>" data-when-source="PROVIDER"><label>Provider claim / PO ref</label>
            <input class="input" name="provider_ref" value="<?= e($sr['provider_ref']) ?>"></div>
        </div>

        <button class="btn btn--primary btn--block">Save changes</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal-bg" id="rejectModal">
  <div class="modal panel">
    <div class="panel__head"><div class="panel__title">Reject this request</div><div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
    <div class="panel__body">
      <form method="post" action="<?= url('service-requests/' . $sr['id'] . '/status') ?>">
        <?= csrf_field() ?><input type="hidden" name="status" value="REJECTED">
        <div class="field"><label class="req">Why are we declining?</label>
          <input class="input" name="close_reason" required placeholder="Outside service area / requires a tow">
        </div>
        <button class="btn btn--danger btn--block">Reject request</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
