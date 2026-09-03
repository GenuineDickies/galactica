<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php $get = function (string $k, string $d = '') use ($s): string {
  foreach ($s as $r) { if ($r['skey'] === $k) { return (string) $r['svalue']; } } return $d; }; ?>
<form method="post" action="<?= url('settings') ?>" style="max-width:860px">
<?= csrf_field() ?>
<div class="panel mb4">
  <div class="panel__head"><div class="panel__title">Business</div></div>
  <div class="panel__body">
    <div class="form-grid">
      <div class="field col-span-2"><label>Legal name</label><input class="input" name="company_name" value="<?= e($get('company_name')) ?>"></div>
      <div class="field"><label>Phone</label><input class="input" name="company_phone" data-mask="phone" value="<?= e($get('company_phone')) ?>"></div>
      <div class="field"><label>Email</label><input class="input" name="company_email" value="<?= e($get('company_email')) ?>"></div>
      <div class="field col-span-2"><label>Address</label><input class="input" name="company_address" value="<?= e($get('company_address')) ?>"></div>
    </div>
  </div>
</div>

<div class="panel mb4">
  <div class="panel__head"><div><div class="panel__title">Rates &amp; tax</div>
    <div class="panel__sub">Tax is applied per line, never as subtotal × rate, and is snapshotted onto each document.</div></div></div>
  <div class="panel__body">
    <div class="form-grid form-grid--3">
      <div class="field"><label>Tax rate (decimal)</label><input class="input" name="tax_rate" value="<?= e($get('tax_rate', '0')) ?>">
        <div class="hint">Oregon: 0. Washington: destination-sourced.</div></div>
      <div class="field"><label>Labour rate / hr</label><input class="input" name="labor_rate" value="<?= e($get('labor_rate')) ?>"></div>
      <div class="field"><label>Mileage / mi</label><input class="input" name="mileage_rate" value="<?= e($get('mileage_rate')) ?>"></div>
      <div class="field"><label>Core return window (days)</label>
        <input class="input" name="core_forfeit_days" type="number" min="1" value="<?= e($get('core_forfeit_days', '30')) ?>">
        <div class="hint">How long a customer has to bring the old unit back before the deposit can be
          forfeited. Keep it INSIDE your supplier's own core window — refund after theirs closes and you
          eat the deposit. Nothing forfeits automatically; this only decides what the sweep proposes.</div></div>
    </div>
  </div>
</div>

<div class="panel mb4">
  <div class="panel__head"><div class="panel__title">Document text</div></div>
  <div class="panel__body">
    <div class="field"><label>Terms printed on invoices</label><textarea class="textarea" name="invoice_terms"><?= e($get('invoice_terms')) ?></textarea></div>
    <?php /* Snapshotted onto every estimate at creation — the contract text
             (ORS-relevant). Until 2026-08-27 it was seeded once and editable
             only by raw SQL. Changing it affects new estimates only; issued
             documents keep the terms they were created with. */ ?>
    <div class="field"><label>Terms printed on estimates (the authorization contract)</label><textarea class="textarea" name="estimate_terms"><?= e($get('estimate_terms')) ?></textarea></div>
    <div class="field"><label>Footer / disclaimer</label><input class="input" name="invoice_footer" value="<?= e($get('invoice_footer')) ?>"></div>
  </div>
</div>

<div class="panel mb4">
  <div class="panel__head">
    <div>
      <div class="panel__title">Integrations</div>
      <div class="panel__sub">Every outside service sits behind an interface. Switching one is a line in <span class="docno">config.php</span> plus credentials here — no business logic moves.</div>
    </div>
  </div>
  <div class="panel__body panel__body--flush">
    <table class="tbl">
      <thead><tr><th>Service</th><th>Driver</th><th>Callback URL</th><th class="right">State</th></tr></thead>
      <tbody>
      <?php foreach (Integrations::status() as $row): ?>
        <tr>
          <td class="strong"><?= e($row['service']) ?></td>
          <td class="docno"><?= e($row['driver']) ?></td>
          <td class="docno text-sm faint"><?= $row['hook'] ? e($row['hook']) : '—' ?></td>
          <td class="right"><span class="badge badge--<?= e($row['state'][1]) ?>"><i></i><?= e($row['state'][0]) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel__body">
    <div class="field">
      <label>Public base URL</label>
      <input class="input" name="app_base_url" value="<?= e($get('app_base_url')) ?>" placeholder="https://admin.wkrllc.com">
      <div class="hint">
        What the providers call back to, and what Square signs its callbacks against — it must match the URL
        registered in the Square dashboard exactly, character for character. Leave blank to guess from the request.
      </div>
    </div>

    <div class="form-grid form-grid--3">
      <div class="field"><label>Messaging driver</label>
        <select class="select" name="driver_sms">
          <?php foreach (['outbox' => 'Off — record only, nothing is texted', 'telnyx' => 'Telnyx — live'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= Integrations::driver('sms', 'outbox') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Payments driver</label>
        <select class="select" name="driver_payments">
          <?php foreach (['manual' => 'Manual — till + local checkout', 'square' => 'Square — live'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= Integrations::driver('payments', 'manual') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Geocoding driver</label>
        <select class="select" name="driver_geocoder">
          <?php foreach (['osm' => 'OpenStreetMap — free, no key', 'google' => 'Google Maps', 'manual' => 'By hand'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= Integrations::driver('geocoder', 'osm') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Routing driver</label>
        <select class="select" name="driver_routes">
          <?php foreach (['offline' => 'Off — ETA entered by hand', 'google' => 'Google Maps — live drive times'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= Integrations::driver('routes', 'offline') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Part numbering</label>
        <select class="select" name="driver_partnum">
          <?php foreach (['rules' => 'Local rules', 'claude' => 'Claude — assign SKUs'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= Integrations::driver('partnum', 'rules') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="hint" style="margin-top:-6px">
      Switching a driver here beats editing <span class="docno">config.php</span> on the server.
      Cash and cheques are recorded by hand whichever payment driver is selected — that path never goes away.
    </div>

    <div class="tag mb2 mt4">Telnyx — messaging</div>
    <div class="form-grid">
      <div class="field"><label>Sending number (E.164)</label>
        <input class="input" name="telnyx_from" value="<?= e($get('telnyx_from')) ?>" placeholder="+15035550100"></div>
      <div class="field"><label>Messaging profile id</label>
        <input class="input" name="telnyx_profile_id" value="<?= e($get('telnyx_profile_id')) ?>"></div>
      <div class="field"><label>API key</label>
        <input class="input" type="password" name="telnyx_api_key" autocomplete="new-password"
               placeholder="<?= $get('telnyx_api_key') !== '' ? '•••••••• stored' : 'KEY…' ?>"></div>
      <div class="field"><label>Webhook public key</label>
        <input class="input" type="password" name="telnyx_public_key" autocomplete="new-password"
               placeholder="<?= $get('telnyx_public_key') !== '' ? '•••••••• stored' : 'base64 Ed25519 key' ?>"></div>
    </div>

    <div class="tag mb2 mt4">Square — card payments</div>
    <div class="form-grid">
      <div class="field"><label>Location id</label>
        <input class="input" name="square_location_id" value="<?= e($get('square_location_id')) ?>"></div>
      <div class="field"><label>Environment</label>
        <select class="select" name="square_environment">
          <?php foreach (['sandbox' => 'Sandbox', 'production' => 'Production'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $get('square_environment', 'sandbox') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Access token</label>
        <input class="input" type="password" name="square_access_token" autocomplete="new-password"
               placeholder="<?= $get('square_access_token') !== '' ? '•••••••• stored' : 'EAAA…' ?>"></div>
      <div class="field"><label>Webhook signature key</label>
        <input class="input" type="password" name="square_signature_key" autocomplete="new-password"
               placeholder="<?= $get('square_signature_key') !== '' ? '•••••••• stored' : 'from the Square dashboard' ?>"></div>
    </div>

    <div class="tag mb2 mt4">Google — geocoding &amp; routing</div>
    <div class="field">
      <label>Maps API key</label>
      <input class="input" type="password" name="google_maps_key" autocomplete="new-password"
             placeholder="<?= $get('google_maps_key') !== '' ? '•••••••• stored' : 'optional' ?>">
      <div class="hint">One key serves both. Geocoding uses the Geocoding API; live drive times need the
        <strong>Routes API</strong> also enabled on this key in the Google Cloud console.</div>
    </div>

    <div class="tag mb2 mt4">Claude — part numbering</div>
    <div class="form-grid">
      <div class="field"><label>Anthropic API key</label>
        <input class="input" type="password" name="anthropic_api_key" autocomplete="new-password"
               placeholder="<?= $get('anthropic_api_key') !== '' ? '•••••••• stored' : 'sk-ant-…' ?>"></div>
      <div class="field"><label>Model</label>
        <input class="input" name="anthropic_model" value="<?= e($get('anthropic_model')) ?>"
               placeholder="claude-haiku-4-5-20251001"></div>
    </div>
    <div class="field">
      <label>Numbering rules</label>
      <textarea class="textarea" name="partnum_rules" rows="7"
        placeholder="<?= e(PartNumbers::DEFAULT_RULES) ?>"><?= e($get('partnum_rules')) ?></textarea>
      <div class="hint">
        Sent to Claude with the list of existing part numbers whenever a new catalog item is added, so it
        assigns a code in your house style. Leave the driver on <span class="docno">Local rules</span> and the
        same scheme is applied without an API call. Either way you can always type a SKU by hand instead.
      </div>
    </div>

    <div class="alert alert--info mb0">
      <div>
        Credential fields are write-only: leave one blank and the stored value is kept, so saving this page
        cannot disconnect an integration by accident. Type a single <span class="docno">-</span> to clear one
        deliberately.
        <br><br>
        Until credentials are entered, nothing breaks — messages hold in the outbox and card payments are taken
        at the till. Every call, live or held, is written to <span class="docno">api_log</span>, so the audit
        trail does not change shape when a provider is switched on. Callbacks that fail signature verification
        are rejected before they are read.
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel__head"><div class="panel__title">Hard business rules</div>
    <div class="topbar__spacer"></div><span class="tag">Code-level, not editable here</span></div>
  <div class="panel__body">
    <dl class="kv">
      <dt>Signature required above</dt><dd><?= money(Rules::cfg('authorization_threshold')) ?></dd>
      <dt>Re-authorization trigger</dt><dd>the lesser of <?= money(Rules::cfg('variance_abs')) ?> or <?= (int) (Rules::cfg('variance_pct') * 100) ?>%</dd>
      <dt>Service request</dt><dd>unverified intake — no customer, no vehicle, no prices</dd>
      <dt>Dispatch gate</dt><dd>priced lines plus a customer authorization on the estimate</dd>
      <dt>Vehicle identity</dt><dd>VIN only — a plate never creates a record</dd>
      <dt>Invoice gate</dt><dd>VIN required unless every line is flagged "no vehicle needed"</dd>
      <dt>Line items</dt><dd>catalog only, snapshotted at add-time</dd>
      <dt>Corrections</dt><dd>void or credit — records are never deleted</dd>
    </dl>
    <div class="hint">These live in <span class="docno">config.php</span> and <span class="docno">app/Domain.php</span> so they are enforced in one place rather than scattered through screens.</div>
  </div>
  <div class="panel__foot"><button class="btn btn--primary">Save settings</button></div>
</div>
</form>
