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
 * "Drop a pin on a map" — a modal the dispatcher opens, positions, confirms.
 *
 * WHY A MODAL AND NOT AN INLINE MAP. Intake happens while a stranded caller is
 * on the line. The form is deliberately thin and mostly typed; a permanent map
 * would push the vehicle and consent fields below the fold on the one screen
 * where speed matters. So the map is a thing you reach for when the caller
 * cannot say where they are, and it is closed the rest of the time.
 *
 * NOTHING IS WRITTEN UNTIL CONFIRM. Dragging and zooming change nothing.
 * Confirm copies the pin into the parent form's hidden fields and asks the
 * server for the nearest address; Cancel restores whatever was there before.
 * A pin is a claim about where a customer physically is — it should take a
 * deliberate act, not a stray click on a map.
 *
 * PROVENANCE. This sets latitude/longitude and nearest_address. It does NOT
 * set location_captured_at, which means specifically "the customer's phone
 * answered". A dispatcher pointing at a map is a different kind of fact and
 * must stay distinguishable from the customer's own device saying where it is.
 *
 * Expects: $picker = ['id','lat','lng','line','city','state','postal',
 *                     'prefix' => field-name prefix (default none)]
 */
$k   = ($picker ?? []) + ['id' => 'pinPicker', 'lat' => null, 'lng' => null,
                          'line' => '', 'city' => '', 'state' => '', 'postal' => '', 'prefix' => ''];
$id  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k['id']);
$p   = preg_replace('/[^a-z0-9_]/i', '', (string) $k['prefix']);
$has = (string) $k['lat'] !== '' && $k['lat'] !== null && (string) $k['lng'] !== '' && $k['lng'] !== null;

/* Where the map opens when there is no pin yet. The marker is NOT placed here —
 * an unset pin must stay unset, or a dispatcher who never touched the map would
 * ship the office's coordinates as the customer's position. */
$fb = [App::config('company')['lat'] ?? 45.5152, App::config('company')['lng'] ?? -122.6784];
?>
<div class="pinpick" data-pinpick
     data-id="<?= e($id) ?>"
     data-reverse="<?= url('geo/reverse') ?>"
     data-forward="<?= url('geo/forward') ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-flat="<?= e((string) $fb[0]) ?>"
     data-flng="<?= e((string) $fb[1]) ?>"
     <?php /* The form's own visible address inputs, which Confirm fills in.
              Named here rather than assumed, so a page can call its fields
              whatever its schema calls them. */ ?>
     data-f-line="<?= e((string) ($k['f_line'] ?? 'nearest_address')) ?>"
     data-f-city="<?= e((string) ($k['f_city'] ?? 'city')) ?>"
     data-f-state="<?= e((string) ($k['f_state'] ?? 'state')) ?>"
     data-f-postal="<?= e((string) ($k['f_postal'] ?? 'postal_code')) ?>"
     data-leaflet-css="<?= asset('assets/vendor/leaflet/leaflet.css') ?>"
     data-leaflet-js="<?= asset('assets/vendor/leaflet/leaflet.js') ?>">

  <button type="button" class="btn btn--sm" data-pinpick-open data-modal-open="<?= e($id) ?>">
    Drop a pin on a map
  </button>
  <span class="pinpick__summary <?= $has ? '' : 'faint' ?>" data-pinpick-summary>
    <?= $has ? 'Pin set · ' . e(number_format((float) $k['lat'], 5)) . ', ' . e(number_format((float) $k['lng'], 5))
             : 'No pin dropped.' ?>
  </span>

  <?php /* Only the coordinates are hidden, because no visible field shows a
           latitude. The ADDRESS the pin resolves is written straight into the
           form's own visible address fields, named on the wrapper above — a
           dispatcher must be able to see what the pin decided, and correct it.
           Written only on Confirm. store() already reads latitude/longitude. */ ?>
  <input type="hidden" name="<?= e($p) ?>latitude"             value="<?= e((string) ($k['lat'] ?? '')) ?>" data-pinpick-lat>
  <input type="hidden" name="<?= e($p) ?>longitude"            value="<?= e((string) ($k['lng'] ?? '')) ?>" data-pinpick-lng>
  <input type="hidden" name="<?= e($p) ?>nearest_intersection" value="" data-pinpick-cross>
</div>

<div class="modal-bg" id="<?= e($id) ?>" data-pinpick-modal="<?= e($id) ?>">
  <div class="modal panel modal--wide" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>_title">
    <div class="panel__head">
      <div>
        <div class="panel__title" id="<?= e($id) ?>_title">Where is the vehicle?</div>
        <div class="panel__sub">Drag and zoom the map, then click the spot. The pin is where the truck drives.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" type="button" data-pinpick-cancel>Cancel</button>
    </div>
    <div class="panel__body">
      <div class="pinpick__canvas" data-pinpick-canvas></div>
      <div class="pinpick__status" data-pinpick-status aria-live="polite">Click the map to place the pin.</div>
      <div class="btn-row mt3">
        <button class="btn btn--primary" type="button" data-pinpick-confirm disabled>Confirm this position</button>
        <button class="btn btn--ghost" type="button" data-pinpick-cancel>Cancel</button>
      </div>
    </div>
  </div>
</div>
