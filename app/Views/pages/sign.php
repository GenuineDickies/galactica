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
 * Customer-facing signing page. No navigation, no chrome, nothing to explore.
 *
 * Reached from a texted link, so it is read on a phone, often in a parking lot
 * — one column, large type, the charges visible above the signature, and the
 * same full-screen pad the technician's device uses.
 */
$co      = App::setting('company_name', App::config('company')['name']);
$name    = customer_name($customer);
$isAuth  = $req['purpose'] === 'AUTH';
$vLabel  = trim(($vehicle['year'] ?? $sr['v_year'] ?? '') . ' '
             . ($vehicle['make'] ?? $sr['v_make'] ?? '') . ' '
             . ($vehicle['model'] ?? $sr['v_model'] ?? ''));
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isAuth ? 'Authorize' : 'Sign off on' ?> <?= e($wo['doc_number']) ?> · <?= e($co) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<style>
  body{ background:var(--bg); padding:24px 16px; }
  .sign{ max-width:560px; margin:0 auto; }
</style>
</head><body>
<div class="sign stack">

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

  <?php if ($signed): ?>
    <div class="alert alert--ok">
      <div>
        <strong>Signed<?= $req['signer_name'] ? ' by ' . e($req['signer_name']) : '' ?>.</strong>
        Recorded <?= e(fdatetime($req['signed_at'])) ?>. Nothing further is needed —
        you can keep this page for your records.
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert--<?= $isAuth ? 'warn' : 'info' ?>">
      <div>
        <?php if ($isAuth): ?>
          <strong>Please review and authorize this work.</strong>
          Your technician cannot begin until you approve the charges below.
        <?php else: ?>
          <strong>Your service is complete.</strong>
          Please review what was done and sign to confirm.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel__body">
      <div class="row row--between wrap">
        <div>
          <div class="tag">Work order</div>
          <div class="docno text-lg"><?= e($wo['doc_number']) ?></div>
        </div>
        <div class="right">
          <div class="tag"><?= $isAuth ? 'Total to authorize' : 'Total' ?></div>
          <div class="text-lg strong"><?= money((float) $totals['total']) ?></div>
        </div>
      </div>
      <div class="muted text-sm mt2">
        For <?= e($name) ?><?= $vLabel !== '' ? ' · ' . e($vLabel) : '' ?>
        <?= $vehicle && $vehicle['plate'] ? ' · plate ' . e($vehicle['plate']) : '' ?>
      </div>
      <?php $__a = doc_address($est); if ($__a !== ''): ?>
        <div class="muted text-sm"><?= e($__a) ?><?= $est['city'] ? ', ' . e($est['city']) : '' ?></div>
      <?php endif; ?>
    </div>

    <div class="panel__body panel__body--flush">
      <table class="tbl">
        <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td>
              <?= e($l['name']) ?>
              <?php if ($l['description']): ?><div class="text-sm faint"><?= e($l['description']) ?></div><?php endif; ?>
            </td>
            <td class="right"><?= rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') ?> <span class="faint text-sm"><?= e($l['uom']) ?></span></td>
            <td class="right"><?= money((float) $l['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel__body">
      <div class="totals">
        <div><span>Subtotal</span><span><?= money((float) $totals['subtotal']) ?></span></div>
        <?php if ((float) $totals['discount'] > 0): ?>
          <div><span>Discount</span><span>−<?= money((float) $totals['discount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float) $totals['tax'] > 0): ?>
          <div><span>Tax</span><span><?= money((float) $totals['tax']) ?></span></div>
        <?php endif; ?>
        <div class="grand"><span>Total</span><span><?= money((float) $totals['total']) ?></span></div>
      </div>
    </div>
  </div>

  <?php if (!$signed): ?>
  <div class="panel">
    <div class="panel__head"><div class="panel__title"><?= $isAuth ? 'Authorize this work' : 'Confirm the work is complete' ?></div></div>
    <div class="panel__body">
      <form method="post" action="<?= url('sign/' . $req['token']) ?>" data-sig-required>
        <?= csrf_field() ?>
        <div class="field">
          <label class="req">Your full name</label>
          <input class="input" name="signer_name" required autocomplete="name"
                 value="<?= e($req['signer_name'] ?: $name) ?>">
        </div>

        <?php View::partial('partials/signature_field', [
          'id'       => 'custSig',
          'label'    => 'Your signature',
          'required' => true,
          'title'    => $isAuth ? 'Authorize this work' : 'Confirm the work is complete',
          'subtitle' => $wo['doc_number'] . ' · ' . money((float) $totals['total'])
                        . ($isAuth ? ' — you are authorizing this work at this price.'
                                   : ' — you are confirming this work was completed.'),
        ]); ?>

        <div class="disclaimer">
          <?php if ($isAuth): ?>
            By signing you authorize <?= e($co) ?> to perform the work itemised above at the price shown.
            If the work turns out to need more than this, we will contact you for approval before going further.
          <?php else: ?>
            By signing you confirm the work itemised above was performed. This is not a payment —
            your invoice will follow separately.
          <?php endif; ?>
        </div>

        <button class="btn btn--primary btn--block mt4">
          <?= $isAuth ? 'Authorize the work' : 'Confirm and sign off' ?>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="muted text-sm" style="text-align:center">
    Questions? Call <?= e(App::config('company')['phone'] ?? '') ?>.
  </div>
</div>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body></html>
