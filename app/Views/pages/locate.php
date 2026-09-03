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
 * Customer-facing location page. No navigation, no chrome, one button.
 *
 * Read on a phone at the side of a road, possibly in the rain, possibly at
 * night. One column, large type, one action. The permission prompt only
 * appears after the tap — a browser will not grant location silently, and
 * the page never pretends otherwise.
 */
$co       = App::setting('company_name', App::config('company')['name']);
$phone    = (string) (App::config('company')['phone'] ?? '');
$received = $req['status'] === 'RECEIVED';
$dead     = in_array($req['status'], ['EXPIRED', 'VOID'], true);
/* A WO link went to the technician, not a stranded customer — same page, same
 * button, different words. Nobody tells a tech to stay with their vehicle. */
$isTech   = $req['doc_type'] === 'WO';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Share your location · <?= e($co) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<style>
  body{ background:var(--bg); padding:24px 16px; }
  .locate{ max-width:480px; margin:0 auto; }
</style>
</head><body>
<div class="locate stack">

  <div class="row" style="justify-content:center;margin-bottom:4px">
    <div class="brand__mark" style="width:44px;height:44px;border-radius:13px;font-size:15px">WK</div>
    <div>
      <div style="font-size:17px;font-weight:730"><?= e($co) ?></div>
      <div class="brand__sub"><?= e(App::config('company')['tagline']) ?></div>
    </div>
  </div>

  <?php foreach (flash() as $f): ?>
    <div class="alert alert--<?= $f['type'] === 'err' ? 'danger' : ($f['type'] === 'warn' ? 'warn' : 'ok') ?>">
      <div><?= $f['msg'] ?></div>
    </div>
  <?php endforeach; ?>

  <?php if ($received): ?>
    <div class="alert alert--ok">
      <div>
        <strong><?= $isTech ? 'Dispatch has your position.' : 'We have your location.' ?></strong>
        Received <?= e(fdatetime($req['received_at'])) ?>. <?= $isTech
          ? 'Your route and ETA are calculated from here — nothing further is needed.'
          : 'Nothing further is needed — stay with your vehicle if it is safe to do so.' ?>
      </div>
    </div>
    <?php if ($req['nearest_address'] || $req['nearest_intersection'] || $req['latitude']): ?>
      <div class="panel"><div class="panel__body">
        <div class="tag mb2">Where we think you are</div>
        <?php if ($req['nearest_address']): ?><div class="strong"><?= e($req['nearest_address']) ?></div><?php endif; ?>
        <?php if ($req['nearest_intersection']): ?><div class="muted">Near <?= e($req['nearest_intersection']) ?></div><?php endif; ?>
        <?php if ($req['latitude']): ?>
          <div class="mt2"><?= map_embed((float) $req['latitude'], (float) $req['longitude'], 200) ?></div>
        <?php endif; ?>
        <div class="hint">If this looks wrong, please call us.</div>
      </div></div>
    <?php endif; ?>

  <?php elseif ($dead): ?>
    <div class="alert alert--warn">
      <div>
        <strong>This link has expired.</strong>
        For your privacy, location links only work once and only for a few hours.
        Please call us<?= $phone !== '' ? ' at ' . e($phone) : '' ?> and we will send a fresh one.
      </div>
    </div>

  <?php else: ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title"><?= $isTech ? 'Share your position for routing' : 'Help us find you' ?></div></div>
      <div class="panel__body">
        <p style="margin:0 0 12px">
          <?php if ($isTech): ?>
            Tap the button below, then choose <strong>Allow</strong> when your phone asks.
            Dispatch uses your position once, to plot your route and drive time to the job.
          <?php else: ?>
            Tap the button below, then choose <strong>Allow</strong> when your phone asks.
            We use your position once, to send help to the right place — this page
            cannot see anything else on your phone.
          <?php endif; ?>
        </p>
        <form method="post" action="<?= url('locate/' . $req['token']) ?>" id="loc_form">
          <?= csrf_field() ?>
          <input type="hidden" id="loc_lat" name="latitude">
          <input type="hidden" id="loc_lng" name="longitude">
          <input type="hidden" id="loc_acc" name="accuracy_m">
          <button class="btn btn--primary btn--block" type="button" id="loc_share_btn"
                  style="font-size:17px;padding:14px">Share my location</button>
        </form>
        <div class="hint hide" id="loc_status" style="margin-top:10px"></div>
        <div class="disclaimer">
          If the button does not work, your phone's location may be switched off.
          Turn on Location Services in your phone settings and try again —
          or just call us<?= $phone !== '' ? ' at ' . e($phone) : '' ?> and describe
          what you can see around you.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="muted text-sm" style="text-align:center">
    Questions? Call <?= e($phone) ?>.
  </div>
</div>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body></html>
