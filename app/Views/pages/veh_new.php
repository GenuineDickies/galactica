<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<form method="post" action="<?= url('vehicles') ?>" style="max-width:760px">
<?= csrf_field() ?>
<div class="panel">
  <div class="panel__head"><div><div class="panel__title">New vehicle</div>
    <div class="panel__sub">The VIN is the identity. Everything else is descriptive.</div></div></div>
  <div class="panel__body">
    <div class="field"><label class="req" for="new_vin">VIN</label>
      <input class="input" id="new_vin" name="vin" data-vin maxlength="17" required style="font-family:var(--mono);letter-spacing:.08em;font-size:16px">
      <div class="hint" data-vin-hint="new_vin" aria-live="polite">17 characters. I, O and Q are not used in VINs.</div></div>
    <div class="form-grid form-grid--3" data-vehicle-picker data-vehicle-endpoint="<?= url('vehicles/options') ?>">
      <div class="field"><label for="year">Year</label><input class="input" name="year" type="number" data-veh="year" id="year"></div>
      <div class="field"><label for="make">Make</label><input class="input" name="make" data-veh="make" id="make"></div>
      <div class="field"><label for="model">Model</label><input class="input" name="model" data-veh="model" id="model"></div>
      <div class="field"><label for="color">Colour</label><input class="input" name="color" id="color"></div>
      <div class="field"><label for="plate">Plate</label><input class="input" name="plate" style="text-transform:uppercase" id="plate">
        <div class="hint">Optional descriptive data. A plate can find an existing VIN record, but cannot create one.</div></div>
      <div class="field"><label for="plate_state">Plate state</label>
        <select class="select" name="plate_state" id="plate_state"><option value="">—</option>
        <?php foreach (us_states() as $s): ?><option <?= $s === 'OR' ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="odometer">Odometer</label><input class="input" name="odometer" type="number" id="odometer"></div>
      <div class="field"><label for="unit_number">Unit number</label><input class="input" name="unit_number" id="unit_number"></div>
    </div>
    <label class="checkline"><input type="checkbox" name="no_plate" value="1"><span>No plate on this vehicle</span></label>
    <div class="field"><label for="no_plate_reason">Reason there is no plate</label>
      <select class="select" name="no_plate_reason" id="no_plate_reason"><option value="">—</option>
        <option>NoPlateIssued</option><option>PlateMissing</option><option>PlateObstructed</option>
        <option>FleetNoPlatePolicy</option><option>CustomerDeclined</option></select></div>
    <div class="field"><label for="notes">Notes</label><textarea class="textarea" name="notes" id="notes"></textarea></div>
  </div>
  <div class="panel__foot"><button class="btn btn--primary">Create vehicle</button>
    <a class="btn btn--ghost" href="<?= url('vehicles') ?>">Cancel</a></div>
</div>
</form>
