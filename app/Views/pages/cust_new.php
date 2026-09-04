<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<form method="post" action="<?= url('customers') ?>" style="max-width:820px">
<?= csrf_field() ?>
<div class="panel">
  <div class="panel__head"><div class="panel__title">New customer</div></div>
  <div class="panel__body">
    <div class="form-grid">
      <div class="field col-span-2"><label for="customer_type">Who is the customer?</label>
        <select class="select" name="customer_type" data-cust-type id="customer_type">
          <?php foreach (customer_types() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= old('customer_type') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">An <strong>individual</strong>, or a <strong>business entity</strong> — the company is then the customer of record.
          Fleet operator means the customer's business <em>is</em> vehicles (couriers, trucking, delivery);
          a commercial business that merely owns several vehicles is Commercial.</div></div>
      <div class="field col-span-2" data-when-cust="business"><label class="req" for="company">Company (the customer of record)</label>
        <input class="input" name="company" data-cust-req="business" value="<?= e(old('company')) ?>" id="company">
        <div class="hint">This name goes on every estimate, invoice and receipt.</div></div>
      <div class="field" data-when-cust="business"><label for="payment_terms">Payment terms</label>
        <select class="select" name="payment_terms" id="payment_terms">
          <?php foreach (payment_terms_options() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= old('payment_terms', 'DUE_ON_RECEIPT') === $k ? 'selected' : '' ?>><?= e($label) ?><?= $k === 'DUE_ON_RECEIPT' ? ' (default)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Every account pays COD by card unless you deliberately grant net terms.</div></div>
      <div class="field col-span-2 hide" data-when-cust="business">
        <div class="hint">The person below is the <strong>billing contact</strong> — optional, never the name on documents.</div></div>
      <div class="field"><label class="req" for="first_name">First name</label><input class="input" name="first_name" data-cust-req="person" required value="<?= e(old('first_name')) ?>" id="first_name"></div>
      <div class="field"><label class="req" for="last_name">Last name</label><input class="input" name="last_name" data-cust-req="person" required value="<?= e(old('last_name')) ?>" id="last_name"></div>
      <div class="field"><label class="req" for="phone">Phone</label><input class="input" name="phone" data-mask="phone" required placeholder="(503) 555-0123" value="<?= e(old('phone')) ?>" id="phone"></div>
      <div class="field"><label for="phone2">Second phone</label><input class="input" name="phone2" data-mask="phone" value="<?= e(old('phone2')) ?>" id="phone2"></div>
      <div class="field col-span-2"><label for="email">Email</label><input class="input" name="email" type="email" value="<?= e(old('email')) ?>" id="email"></div>
      <div class="field col-span-2"><label for="address_line1">Address</label><input class="input" name="address_line1" value="<?= e(old('address_line1')) ?>" id="address_line1"></div>
      <div class="field"><label for="city">City</label><input class="input" name="city" value="<?= e(old('city')) ?>" id="city"></div>
      <div class="field"><label for="state">State</label>
        <select class="select" name="state" id="state"><?php foreach (us_states() as $s): ?><option <?= $s === old('state', 'OR') ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="postal_code">ZIP</label><input class="input" name="postal_code" value="<?= e(old('postal_code')) ?>" id="postal_code"></div>
    </div>
    <label class="checkline"><input type="checkbox" name="sms_approved" value="1" <?= old('sms_approved') ? 'checked' : '' ?>><span>SMS consent captured. <span class="faint">Read the consent script before collecting the number.</span></span></label>
    <label class="checkline"><input type="checkbox" name="dup_override" value="1"><span>Not a duplicate — create even though a similar customer is on file.
      <span class="faint">Only needed after a duplicate warning; the override is audited.</span></span></label>
    <label class="checkline"><input type="checkbox" name="is_provider" value="1" <?= old('is_provider') ? 'checked' : '' ?>><span>This is a provider / broker account that sends us bulk work</span></label>
    <div class="field mt4"><label for="provider_code">Provider code</label><input class="input" name="provider_code" placeholder="CMC-2026" value="<?= e(old('provider_code')) ?>" id="provider_code"></div>
    <div class="field"><label for="notes">Notes</label><textarea class="textarea" name="notes" id="notes"><?= e(old('notes')) ?></textarea></div>
  </div>
  <div class="panel__foot"><button class="btn btn--primary">Create customer</button>
    <a class="btn btn--ghost" href="<?= url('customers') ?>">Cancel</a></div>
</div>
</form>
