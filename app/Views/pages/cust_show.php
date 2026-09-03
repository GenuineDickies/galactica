<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="split">
  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Service history</div></div>
      <div class="panel__body panel__body--flush">
      <?php if (!$srs): ?><div class="empty"><div class="empty__title">No jobs on file</div>
        <div class="empty__body">Nothing has been logged for this customer yet.</div></div>
      <?php else: ?>
        <table class="tbl"><thead><tr><th>Status</th><th>Request</th><th>Service</th><th>Where</th><th class="right">When</th></tr></thead><tbody>
        <?php foreach ($srs as $r): ?>
          <tr data-href="<?= url('service-requests/' . $r['id']) ?>">
            <td><?= badge($r['status']) ?></td><td class="docno"><?= e($r['doc_number']) ?></td>
            <td><?= e(service_type_label($r['reported_service'])) ?></td>
            <td class="muted"><?= e($r['city'] ?: '—') ?></td>
            <td class="right muted text-sm nowrap"><?= e(fdate($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Invoices</div></div>
      <div class="panel__body panel__body--flush">
      <?php if (!$invoices): ?><div class="panel__body muted text-sm">No invoices.</div>
      <?php else: ?>
        <table class="tbl"><thead><tr><th>Status</th><th>Invoice</th><th>Issued</th><th class="right">Total</th><th class="right">Balance</th></tr></thead><tbody>
        <?php foreach ($invoices as $r): ?>
          <tr data-href="<?= url('invoices/' . $r['id']) ?>">
            <td><?= badge($r['status']) ?></td><td class="docno"><?= e($r['doc_number']) ?></td>
            <td class="muted text-sm"><?= e(fdate($r['issued_at'])) ?></td>
            <td class="right num"><?= money($r['total']) ?></td>
            <td class="right num strong"><?= money($r['balance_due']) ?></td>
          </tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Edit record</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('customers/' . $c['id']) ?>">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field col-span-2"><label>Who is the customer?</label>
              <select class="select" name="customer_type" data-cust-type>
                <?php foreach (customer_types() as $k => $label): ?>
                  <option value="<?= e($k) ?>" <?= $c['customer_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">Fleet operator = the customer's business <em>is</em> vehicles (couriers, trucking, delivery).
                A commercial business that merely owns several vehicles is Commercial.</div></div>
            <div class="field col-span-2" data-when-cust="business"><label class="req">Company (the customer of record)</label>
              <input class="input" name="company" value="<?= e($c['company']) ?>" data-cust-req="business"></div>
            <div class="field" data-when-cust="business"><label>Payment terms</label>
              <select class="select" name="payment_terms">
                <?php foreach (payment_terms_options() as $k => $label): ?>
                  <option value="<?= e($k) ?>" <?= ($c['payment_terms'] ?: 'DUE_ON_RECEIPT') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">Net terms are a deliberate grant of credit. Changing them only affects future invoices — issued ones keep the terms they were created with.</div></div>
            <div class="field col-span-2 hide" data-when-cust="business">
              <div class="hint">The person below is the <strong>billing contact</strong> — optional, never the name on documents.</div></div>
            <div class="field"><label>First name</label><input class="input" name="first_name" value="<?= e($c['first_name']) ?>" data-cust-req="person"></div>
            <div class="field"><label>Last name</label><input class="input" name="last_name" value="<?= e($c['last_name']) ?>" data-cust-req="person"></div>
            <div class="field col-span-2"><label>Email</label><input class="input" name="email" value="<?= e($c['email']) ?>"></div>
            <div class="field col-span-2"><label>Address</label><input class="input" name="address_line1" value="<?= e($c['address_line1']) ?>"></div>
            <div class="field"><label>City</label><input class="input" name="city" value="<?= e($c['city']) ?>"></div>
            <div class="field"><label>State</label><input class="input" name="state" value="<?= e($c['state']) ?>" maxlength="2"></div>
            <div class="field"><label>ZIP</label><input class="input" name="postal_code" value="<?= e($c['postal_code']) ?>"></div>
          </div>
          <label class="checkline"><input type="checkbox" name="sms_approved" value="1" <?= (int) $c['sms_approved'] === 1 ? 'checked' : '' ?>><span>SMS consent on file</span></label>
          <label class="checkline"><input type="checkbox" name="do_not_contact" value="1" <?= (int) $c['do_not_contact'] === 1 ? 'checked' : '' ?>><span>Do not contact — blocks every outbound message</span></label>
          <div class="field mt4"><label>Notes</label><textarea class="textarea" name="notes"><?= e($c['notes']) ?></textarea></div>
          <button class="btn btn--primary">Save customer</button>
        </form>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel kpi"><div class="kpi__label">Outstanding balance</div><div class="kpi__value"><?= money($balance) ?></div></div>
    <div class="panel kpi"><div class="kpi__label">Lifetime revenue</div><div class="kpi__value"><?= money($lifetime) ?></div></div>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Contact</div></div>
      <div class="panel__body">
        <dl class="kv">
          <dt>Phone</dt><dd><?= e(phone_display($c['phone_e164'])) ?></dd>
          <?php if ($c['phone2_e164']): ?><dt>Alt phone</dt><dd><?= e(phone_display($c['phone2_e164'])) ?></dd><?php endif; ?>
          <dt>Type</dt><dd><?= customer_badge($c) ?></dd>
          <?php if (customer_is_business($c)): ?>
            <?php $contact = trim($c['first_name'] . ' ' . $c['last_name']); ?>
            <?php if ($contact !== ''): ?><dt>Contact</dt><dd><?= e($contact) ?></dd><?php endif; ?>
            <dt>Terms</dt><dd><?= e(payment_terms_label($c['payment_terms'])) ?></dd>
          <?php endif; ?>
          <dt>SMS</dt><dd><?= (int) $c['sms_approved'] === 1 ? '<span class="badge badge--success"><i></i>Consent</span>' : '<span class="badge badge--slate"><i></i>None</span>' ?></dd>
        </dl>
        <div class="hint">A phone number is a lookup hint, not an identity. Shared and reassigned numbers are common — always confirm the name.</div>
      </div>
    </div>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Vehicles</div></div>
      <div class="panel__body">
        <?php if (!$vehicles): ?><div class="muted text-sm">None on file. Vehicles are created from a VIN captured in the field.</div>
        <?php else: foreach ($vehicles as $v): ?>
          <div class="row row--between" style="padding:7px 0;border-bottom:1px solid var(--line)">
            <div><div class="strong"><?= e(trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model'])) ?></div>
              <div class="docno text-sm"><?= e($v['vin']) ?></div></div>
            <button class="btn btn--sm" data-url="<?= url('vehicles/' . $v['id']) ?>">Open</button>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
