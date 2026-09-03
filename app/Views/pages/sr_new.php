<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<form method="post" action="<?= url('service-requests') ?>" class="split" autocomplete="off">
<?= csrf_field() ?>
<div class="stack">

  <div class="panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Log the request</div>
        <div class="panel__sub">
          This is a record that somebody asked for help — nothing more. It does not have to be accurate,
          it creates no customer and no vehicle, and it carries no prices. Confirming who they really are
          happens later, when this is promoted to an estimate.
        </div>
      </div>
    </div>
    <div class="panel__body">
      <div class="form-grid">
        <div class="field">
          <label>How did it come in</label>
          <select class="select" name="channel">
            <option value="PHONE">Phone call</option>
            <option value="WEB">Website form</option>
            <option value="SMS">Text message</option>
            <option value="PROVIDER_API">Provider — electronic dispatch</option>
            <option value="WALK_IN">Walk-up</option>
          </select>
          <div class="hint">Electronic requests will land here automatically once a provider feed is wired up.</div>
        </div>
        <div class="field">
          <label>Job source</label>
          <select class="select" name="job_source" data-job-source>
            <option value="RETAIL">Retail — direct customer</option>
            <option value="PROVIDER">Provider / bulk referral</option>
          </select>
        </div>
        <?php /* Only a provider job has a provider. Hidden by default because
                 the source defaults to RETAIL — rendered with .hide so the
                 fields never flash in and back out before the script runs.
                 Hiding is presentation; Rules::providerLink is what actually
                 keeps a retail request from carrying a claim number. */ ?>
        <div class="field hide" data-when-source="PROVIDER">
          <label>Provider account</label>
          <select class="select" name="provider_id">
            <option value="">—</option>
            <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['company']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field hide" data-when-source="PROVIDER"><label>Provider claim / PO ref</label><input class="input" name="provider_ref" placeholder="CMC-884120"></div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Who said they need help</div>
        <div class="panel__sub">As reported. A name and a number, exactly as given — unverified.</div>
      </div>
    </div>
    <div class="panel__body">
      <div class="form-grid">
        <div class="field"><label>Name as given</label><input class="input" name="reported_name" placeholder="Marcus Webb"></div>
        <div class="field"><label>Callback number</label>
          <input class="input" name="reported_phone" data-mask="phone" placeholder="(503) 555-0123">
          <div class="hint">Displayed as (xxx) xxx-xxxx. Stored as E.164 when it parses.</div>
        </div>
      </div>
      <label class="checkline">
        <input type="checkbox" name="comms_consent" value="1">
        <span>Caller verbally agreed to receive SMS updates.
          <span class="faint">Noted here only — consent is written to the customer record at promotion, and nothing can send without it.</span></span>
      </label>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head"><div class="panel__title">What they say they need</div></div>
    <div class="panel__body">
      <div class="form-grid">
        <?php /* CATEGORY FIRST, THEN THE JOB — and the order is the point.
                 Asking "what service?" and then "what should this roll as?"
                 makes a dispatcher name a job and classify it afterwards,
                 which is one decision asked in the order nobody makes it in
                 and leaves the classification to whether the rep got it right.
                 Deciding the kit first turns the second question into a short
                 list of jobs that kit can actually do. */ ?>
        <div class="field">
          <label>Roll as</label>
          <select class="select" name="service_category" id="service_category"
                  data-service-types="<?= e(json_encode(service_types_by_category())) ?>">
            <?php foreach (service_categories() as $k => $v): ?>
              <option value="<?= e($k) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">What the truck has to carry. For tire work the test is whether the
            tire comes off the RIM, not off the vehicle: a spare swap or a plug is roadside;
            an internal patch or a delivery needs the bead broken and rides with the tire kit.</div>
        </div>
        <div class="field">
          <label>Probably this service</label>
          <?php /* Rendered for the default category and rebuilt client-side as
                   the category changes. The eligibility comes from
                   ServiceCategory either way, and the server re-coerces the
                   pair on submit regardless of what the browser did. */ ?>
          <select class="select" name="reported_service" id="nature_of_service">
            <?php foreach (service_types_for(array_key_first(service_categories())) as $k => $v): ?>
              <option value="<?= e($k) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">Narrowed to what that kit does. Still a guess from the call —
            the estimate decides what the work actually is.</div>
        </div>
        <div class="field">
          <label>Priority</label>
          <select class="select" name="priority">
            <option value="EMERGENCY">Emergency</option>
            <option value="URGENT">Urgent</option>
            <option value="STANDARD" selected>Standard</option>
            <option value="APPOINTMENT">Appointment</option>
          </select>
        </div>
        <div class="field">
          <label>ETA quoted to caller <span class="faint">(optional)</span></label>
          <input class="input" name="promised_eta_time" type="time" step="900">
          <div class="hint">Only if you actually told them a time — quarter-hour times.
            Blank records no promise. A time already past means tomorrow.</div>
        </div>
      </div>
      <div class="field mb0"><label>The problem in their words</label>
        <textarea class="textarea" name="reported_problem" placeholder="Car won't start, clicks when I turn the key. Battery light was on yesterday."></textarea>
      </div>

      <div class="mt4" data-when-service="LOCKOUT">
        <label class="checkline">
          <input type="checkbox" id="occupant_inside" name="occupant_inside" value="1">
          <span>A child or pet is inside the vehicle</span>
        </label>
        <div id="occupant_note" class="alert alert--danger hide">
          <div><strong>Escalate to emergency.</strong> Advise the caller to contact 911 or the fire department immediately if anyone inside is in distress. Do not delay the call for a quote.</div>
        </div>
      </div>
    </div>
  </div>

  <?php /* VEHICLE BEFORE LOCATION, AND THE ORDER IS LOAD-BEARING.
           The location panel ends with "Capture GPS", which is a SUBMIT: it
           logs the request there and then so the caller's phone starts
           buzzing while they are still on the line. Anything rendered below
           that button is a field the dispatcher never reaches on the one flow
           that most wants the text sent early. The car the driver has to spot
           on a dark shoulder is not an afterthought, so it goes above. */ ?>
  <div class="panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Vehicle as described</div>
        <div class="panel__sub">Rough details, so the driver can spot it. The VIN is captured on scene — never ask a stranded customer to hunt for it.</div>
      </div>
    </div>
    <div class="panel__body">
      <div class="form-grid form-grid--3" data-vehicle-picker data-vehicle-endpoint="<?= url('vehicles/options') ?>">
        <div class="field"><label>Year</label><input class="input" name="v_year" type="number" min="1900" max="<?= date('Y') + 1 ?>" data-veh="year"></div>
        <div class="field"><label>Make</label><input class="input" name="v_make" data-veh="make"></div>
        <div class="field"><label>Model</label><input class="input" name="v_model" data-veh="model"></div>
        <div class="field"><label>Colour</label><input class="input" name="v_color"></div>
        <div class="field"><label>Plate</label><input class="input" name="v_plate" style="text-transform:uppercase"></div>
        <div class="field"><label>Plate state</label>
          <select class="select" name="v_plate_state">
            <option value="">—</option>
            <?php foreach (us_states() as $s): ?><option <?= $s === 'OR' ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="hint">A plate on its own never creates a vehicle record. Only a valid VIN does.
        These carry forward: the estimate's vehicle form opens pre-filled from them.</div>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Probably this location</div>
        <div class="panel__sub">However they described it. Precision comes later.</div>
      </div>
    </div>
    <div class="panel__body">
      <?php /* TWO DIFFERENT THINGS, KEPT APART.
               The description is what the caller said — testimony, in their
               words, never corrected. The address is a place you could post a
               letter to. "I-84 EB near Exit 9, blue sedan on the shoulder" is
               the first and can never become the second, which is why they are
               separate fields rather than one box that means whichever. */ ?>
      <div class="form-grid">
        <div class="field col-span-full"><label>Where they say they are</label>
          <input class="input" name="reported_location" placeholder="I-84 EB near Exit 9, blue sedan on the shoulder">
          <div class="hint">Their words. Kept exactly as heard — a description of a place, not an address.</div>
        </div>
      </div>

      <div class="tag mb2 mt4">— the address —</div>
      <div class="form-grid">
        <div class="field col-span-full"><label>Street address, or nearest address</label>
          <input class="input" name="nearest_address" placeholder="1220 NW Everett St" autocomplete="off">
          <div class="hint">Works for both: the address the job is <em>at</em> when there is one, or the
            nearest address to a vehicle on a shoulder. Number, street, city and state — no ZIP needed.
            Drop a pin below and this fills itself in.</div>
        </div>
        <div class="field"><label>City</label><input class="input" name="city"></div>
        <div class="field"><label>State</label>
          <select class="select" name="state">
            <?php foreach (us_states() as $s): ?><option <?= $s === 'OR' ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>ZIP <span class="faint">(optional)</span></label>
          <input class="input" name="postal_code" maxlength="10"></div>
      </div>
      <?php /* Two ways to get a position, and they are not interchangeable.
               The location link asks the CUSTOMER'S phone, which is the better
               answer when it is available — it is their device, not a guess.
               The pin is for when it is not: no consent on file, a dead phone,
               a landline, or a caller who can describe the place well enough
               for a dispatcher to point at it. */ ?>
      <div class="row wrap">
        <?php /* This SUBMITS. Named plainly in the confirm, because a button
                 that ends intake mid-form is a surprise the first time and a
                 lost vehicle description every time after. */ ?>
        <button type="submit" class="btn btn--sm" name="send_location_link" value="1"
                data-confirm="This logs the request now and texts the caller a location link. Intake continues on the next screen. Send it?">Capture GPS — text the caller a location link</button>
        <?php View::partial('partials/pinpicker', ['picker' => [
            'id' => 'intakePin', 'lat' => null, 'lng' => null,
            'line' => '', 'city' => '', 'state' => '', 'postal' => '',
        ]]); ?>
      </div>
      <div class="hint">
        <strong>Capture GPS</strong> texts the caller a link that asks <em>their</em> phone for its
        position — the best answer when you can get it. Needs the callback number and the consent box above.
        It logs the request as it sends, then re-opens the details on the next screen so you can keep
        taking the call while their phone answers.
        <br><strong>Drop a pin</strong> when you cannot: you place the position on a map yourself, and the
        nearest street address is worked out from it.
      </div>
    </div>
  </div>

</div>

<div class="stack">
  <div class="panel">
    <div class="panel__head"><div class="panel__title">What happens next</div></div>
    <div class="panel__body">
      <dl class="kv">
        <dt>1 · Logged</dt><dd>Unverified, unpriced. Commits neither side to anything.</dd>
        <dt>2 · Promoted</dt><dd>The customer is confirmed and an estimate is opened.</dd>
        <dt>3 · Authorized</dt><dd>Priced and signed. Only then can it be dispatched.</dd>
      </dl>
      <div class="hint">Everything downstream — work order, invoice, payment, receipt — traces back to this number.</div>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head"><div class="panel__title">Dispatcher notes</div></div>
    <div class="panel__body">
      <textarea class="textarea" name="intake_notes" placeholder="Internal only — never printed on customer documents."></textarea>
    </div>
    <div class="panel__foot" style="display:block">
      <button class="btn btn--primary btn--block" type="submit">Log service request</button>
      <div class="disclaimer" style="margin-top:12px">Logging a request commits us to nothing. Nothing is billable until an estimate is authorized.</div>
    </div>
  </div>
</div>
</form>
