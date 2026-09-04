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
 * The pin and the nearest physical address.
 *
 * THE PIN IS THE ANSWER. Wherever the marker sits is where the vehicle is and
 * where the truck routes. The address beside it is a label for that point —
 * derived by reverse geocoding, or typed and then geocoded back to a point.
 * Moving the pin re-derives the address; correcting the address moves the pin.
 * Whichever the dispatcher touched last is the one they meant.
 *
 * Leaflet is vendored under public/assets/vendor/leaflet (see VERSION.txt) so
 * nothing loads from a CDN and there is still no build step. It is pulled in
 * here rather than in the layout, because most screens have no map and should
 * not pay 160KB for one.
 *
 * Expects: $pin = ['lat'=>?float,'lng'=>?float,'line'=>string,'city'=>string,
 *                  'state'=>string,'postal'=>string,'name'=>string]
 * $name prefixes the submitted fields so a page can host more than one map.
 */
$p     = ($pin ?? []) + ['lat' => null, 'lng' => null, 'line' => '', 'city' => '',
                         'state' => '', 'postal' => '', 'name' => 'pin'];
$n     = preg_replace('/[^a-z0-9_]/i', '', (string) $p['name']);
$uid   = $n . '_' . substr(md5($n . (string) ($p['lat'] ?? '')), 0, 6);
$hasLL = $p['lat'] !== null && $p['lng'] !== null && (string) $p['lat'] !== '' && (string) $p['lng'] !== '';

/* Portland, when there is nothing else to centre on. The pin is NOT placed
 * here — an unset pin must stay unset, or a dispatcher who never touched the
 * map would silently ship the office's coordinates as the customer's. */
$fallback = [App::config('company')['lat'] ?? 45.5152, App::config('company')['lng'] ?? -122.6784];
?>
<?php /* Leaflet's URLs ride on the element rather than in <script> tags: this
         partial is often inside a region that navigation swaps in via
         innerHTML, and innerHTML never executes scripts. app.js injects them
         on demand instead, so the map works on a full load and in-app alike. */ ?>
<div class="pinmap" data-pinmap
     data-uid="<?= e($uid) ?>"
     data-name="<?= e($n) ?>"
     data-leaflet-css="<?= asset('assets/vendor/leaflet/leaflet.css') ?>"
     data-leaflet-js="<?= asset('assets/vendor/leaflet/leaflet.js') ?>"
     data-reverse="<?= url('geo/reverse') ?>"
     data-forward="<?= url('geo/forward') ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-lat="<?= e((string) ($p['lat'] ?? '')) ?>"
     data-lng="<?= e((string) ($p['lng'] ?? '')) ?>"
     data-flat="<?= e((string) $fallback[0]) ?>"
     data-flng="<?= e((string) $fallback[1]) ?>">

  <div class="pinmap__canvas" id="map_<?= e($uid) ?>" data-pinmap-canvas></div>

  <div class="pinmap__status" data-pinmap-status aria-live="polite">
    <?php if ($hasLL): ?>
      Pin set from the customer's phone. Drag it if the vehicle is elsewhere.
    <?php else: ?>
      No position yet — click the map where the vehicle is, or type the nearest address below.
    <?php endif; ?>
  </div>

  <input type="hidden" name="<?= e($n) ?>_lat" value="<?= e((string) ($p['lat'] ?? '')) ?>" data-pinmap-lat>
  <input type="hidden" name="<?= e($n) ?>_lng" value="<?= e((string) ($p['lng'] ?? '')) ?>" data-pinmap-lng>

  <div class="form-grid mt3">
    <div class="field col-span-2">
      <label for="<?= e($n) ?>_line">Street address, or nearest address</label>
      <div class="row">
        <input class="input" name="<?= e($n) ?>_line" value="<?= e((string) $p['line']) ?>"
               placeholder="842 SE Morrison St" data-pinmap-line autocomplete="off" id="<?= e($n) ?>_line">
        <button type="button" class="btn btn--ghost btn--sm" data-pinmap-find>Find on map</button>
      </div>
      <div class="hint">Optional. A street number, street, city and state — ZIP is not needed.
        Leave it blank if there is nothing addressable near the pin; a shoulder or a rural
        milepost often has none. The pin is what a truck drives to, and the pin is required.</div>
    </div>
    <div class="field"><label for="<?= e($n) ?>_city">City</label>
      <input class="input" name="<?= e($n) ?>_city" value="<?= e((string) $p['city']) ?>" data-pinmap-city id="<?= e($n) ?>_city"></div>
    <div class="field"><label for="<?= e($n) ?>_state">State</label>
      <select class="select" name="<?= e($n) ?>_state" data-pinmap-state id="<?= e($n) ?>_state">
        <option value=""></option>
        <?php foreach (us_states() as $s): ?>
          <option value="<?= e($s) ?>" <?= $s === (string) $p['state'] ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select></div>
    <input type="hidden" name="<?= e($n) ?>_postal" value="<?= e((string) $p['postal']) ?>" data-pinmap-postal>
  </div>
</div>
