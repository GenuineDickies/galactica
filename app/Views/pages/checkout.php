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
/** Customer-facing checkout. No navigation, no chrome, nothing to explore. */
$co   = App::setting('company_name', App::config('company')['name']);
$name = customer_name($customer);   // customer-facing page
$paid = $inv['status'] === 'PAID' || $link['status'] === 'PAID';
$due  = (float) $inv['balance_due'];
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay <?= e($inv['doc_number']) ?> · <?= e($co) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<style>
  body{ background:var(--bg); padding:24px 16px; }
  .pay{ max-width:520px; margin:0 auto; }
</style>
</head><body class="is-customer-page">
<div class="pay stack">

  <div class="row" style="justify-content:center;margin-bottom:4px">
    <div class="brand__mark" style="width:44px;height:44px;border-radius:13px;font-size:15px">WK</div>
    <div>
      <h1 style="font-size:17px;font-weight:730"><?= e($co) ?></h1>
      <div class="brand__sub"><?= e(App::config('company')['tagline']) ?></div>
    </div>
  </div>

  <?php foreach (flash() as $f): ?>
    <div class="alert alert--<?= $f['type'] === 'err' ? 'danger' : ($f['type'] === 'warn' ? 'warn' : 'ok') ?>" role="status">
      <div><?= $f['msg'] ?></div>
    </div>
  <?php endforeach; ?>

  <?php if ($simulated): ?>
    <div class="alert alert--info" role="status">
      <div>
        <strong>Demonstration checkout.</strong> No card processor is connected to this install, so nothing is
        charged and no card details are asked for or stored. Connect Square in Settings and links point at
        Square's hosted page instead.
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel__body">
      <div class="row row--between wrap">
        <div>
          <div class="tag">Invoice</div>
          <div class="docno text-lg"><?= e($inv['doc_number']) ?></div>
        </div>
        <div class="right">
          <div class="tag">Amount due</div>
          <div class="text-lg strong"><?= money($paid ? 0 : $due) ?></div>
        </div>
      </div>
      <div class="muted text-sm mt2">Billed to <?= e($name) ?></div>
    </div>

    <div class="panel__body panel__body--flush">
      <table class="tbl">
        <thead><tr><th scope="col">Item</th><th class="right" scope="col">Qty</th><th class="right" scope="col">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td class="strong"><?= e($l['name']) ?>
              <?php if ((int) $l['warranty_months'] > 0): ?>
                <div class="text-sm muted"><?= (int) $l['warranty_months'] ?>-month warranty</div>
              <?php endif; ?>
              <?php if (!empty($l['mfr_warranty'])): ?>
                <div class="text-sm muted"><?= e($l['mfr_warranty']) ?> warranty</div>
              <?php endif; ?>
            </td>
            <td class="right num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') ?></td>
            <td class="right num"><?= money($l['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel__foot" style="display:block">
      <div class="totals">
        <div><span class="muted">Subtotal</span><span><?= money($inv['subtotal']) ?></span></div>
        <div><span class="muted">Tax</span><span><?= money($inv['tax_total']) ?></span></div>
        <div><span class="muted">Already paid</span><span>−<?= money($inv['amount_paid']) ?></span></div>
        <div class="grand"><span><?= $paid ? 'Paid in full' : 'Due now' ?></span><span><?= money($paid ? 0 : $due) ?></span></div>
      </div>
    </div>
  </div>

  <?php if ($paid): ?>
    <div class="panel">
      <div class="panel__body" style="text-align:center">
        <div class="badge badge--success" style="margin-bottom:10px"><i></i>Paid</div>
        <div class="muted">Thank you. A receipt has been issued and this link is now closed.</div>
      </div>
    </div>
  <?php else: ?>
    <div class="panel">
      <div class="panel__head"><h2 class="panel__title">Pay now</h2></div>
      <div class="panel__body">
        <form method="post" action="<?= url('pay/' . $link['order_id']) ?>">
          <?= csrf_field() ?>
          <div class="field">
            <fieldset class="radio-group"><legend>Add a tip for your technician</legend>
            <div class="radio-row">
              <?php foreach ([0, 5, 10, 20] as $tip): ?>
                <label class="radio-card">
                  <input type="radio" name="tip_amount" value="<?= $tip ?>" <?= $tip === 0 ? 'checked' : '' ?>>
                  <span><?= $tip === 0 ? 'No tip' : money($tip) ?></span>
                </label>
              <?php endforeach; ?>
            </div></fieldset>
            <div class="hint">Tips go to the technician in full and are not taxed.</div>
          </div>
          <button class="btn btn--primary btn--block btn--lg">Pay <?= money($due) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="disclaimer" style="text-align:center">
    Questions about this invoice? Call <?= e(App::setting('company_phone', App::config('company')['phone'])) ?>.
  </div>
</div>
</body></html>
