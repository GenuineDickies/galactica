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
/** Printable estimate / invoice / receipt. Opens clean and prints clean. */
$co   = ['name' => App::setting('company_name', App::config('company')['name']),
         'phone'=> App::setting('company_phone', App::config('company')['phone']),
         'email'=> App::setting('company_email', App::config('company')['email']),
         'addr' => App::setting('company_address', 'Portland, OR')];
$custName = customer_name($customer);   // customer-facing: a business account shows its company name
$sub = 0.0; $tax = 0.0;
foreach ($lines as $l) { $sub += (float) $l['line_total']; }
$tax   = (float) ($doc['tax_total'] ?? 0);
$total = (float) ($doc['total'] ?? $sub + $tax);
$paid  = (float) ($doc['amount_paid'] ?? 0);
$bal   = (float) ($doc['balance_due'] ?? 0);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docKind) ?> <?= e($receipt['doc_number'] ?? $doc['doc_number']) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<style>
  body{ background:var(--bg); padding:28px; }
  .sheet{ max-width:860px; margin:0 auto; }
  .doc-head{ display:flex; justify-content:space-between; gap:24px; flex-wrap:wrap; }
  @media print{ body{ padding:0; background:#fff; } .no-print{ display:none } }
</style>
</head><body>
<div class="sheet stack">

  <div class="no-print row" style="justify-content:flex-end">
    <button class="btn btn--primary" onclick="window.print()">Print or save as PDF</button>
    <button class="btn btn--ghost" onclick="window.close()">Close</button>
  </div>

  <div class="panel">
    <div class="panel__body">
      <div class="doc-head">
        <div class="row" style="align-items:flex-start">
          <div class="brand__mark" style="width:46px;height:46px;border-radius:13px;font-size:15px">WK</div>
          <div>
            <div style="font-size:17px;font-weight:730"><?= e($co['name']) ?></div>
            <div class="muted text-sm"><?= e($co['addr']) ?><br><?= e($co['phone']) ?> · <?= e($co['email']) ?></div>
          </div>
        </div>
        <div class="right">
          <div class="tag"><?= e($docKind) ?></div>
          <div class="docno text-lg"><?= e($receipt['doc_number'] ?? $doc['doc_number']) ?></div>
          <div class="muted text-sm">
            <?php if ($docKind === 'RECEIPT'): ?>
              Paid <?= e(fdatetime($payment['paid_at'])) ?><br>Against invoice <?= e($doc['doc_number']) ?>
            <?php else: ?>
              <?= e(fdate($doc['issued_at'] ?? $doc['created_at'])) ?>
              <?php if ($docKind === 'INVOICE' && !empty($doc['issued_at'])): ?>
                <?php $termDays = Rules::termsDays($doc['terms'] ?? null); ?>
                <br><?= $termDays === null ? 'Due on receipt' : 'Due ' . e(fdate($doc['due_at'])) . ' (Net ' . $termDays . ')' ?>
              <?php elseif (!empty($doc['due_at'])): ?><br>Due <?= e(fdate($doc['due_at'])) ?><?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="mt2"><?= badge($docKind === 'RECEIPT' ? 'PAID' : ($doc['status'] ?? 'DRAFT')) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid grid--2">
    <div class="panel"><div class="panel__body">
      <div class="tag mb2">Bill to</div>
      <div class="strong text-lg"><?= e($custName) ?></div>
      <?php $attn = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']); ?>
      <?php if (customer_is_business($customer) && $attn !== ''): ?>
        <div class="muted text-sm">Attn: <?= e($attn) ?></div>
      <?php endif; ?>
      <div class="muted text-sm">
        <?= e(phone_display($customer['phone_e164'])) ?><?= $customer['email'] ? '<br>' . e($customer['email']) : '' ?>
        <?= $customer['address_line1'] ? '<br>' . e($customer['address_line1']) : '' ?>
        <?= $customer['city'] ? '<br>' . e($customer['city']) . ', ' . e($customer['state']) . ' ' . e($customer['postal_code']) : '' ?>
      </div>
    </div></div>
    <div class="panel"><div class="panel__body">
      <div class="tag mb2">Service</div>
      <dl class="kv">
        <?php
        $svc  = (string) ($doc['service_type'] ?? $sr['reported_service']);
        /* The nearest physical address, and nothing else. This line prints on
         * a document the customer signs, so it must never fall back to
         * reported_location — that field holds the caller's description of
         * where they are, and "blue sedan on the shoulder" is not an address. */
        $loc  = doc_address($doc);
        ?>
        <dt>Request</dt><dd class="docno"><?= e($sr['doc_number']) ?></dd>
        <?php if (!empty($doc['po_number'])): ?><dt>PO #</dt><dd class="docno"><?= e($doc['po_number']) ?></dd><?php endif; ?>
        <dt>Type</dt><dd><?= e(service_type_label($svc)) ?></dd>
        <dt>Location</dt><dd><?= e(trim($loc . ' ' . $sr['city'])) ?></dd>
        <?php if ($vehicle): ?>
          <dt>Vehicle</dt><dd><?= e(trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model'])) ?></dd>
          <dt>VIN</dt><dd class="docno"><?= e($vehicle['vin']) ?></dd>
          <dt>Plate</dt><dd><?= (int) $vehicle['no_plate'] === 1 ? 'NO PLATE' : e(trim($vehicle['plate'] . ' ' . $vehicle['plate_state'])) ?></dd>
        <?php elseif (!empty($doc['no_vehicle_reason'])): ?>
          <dt>Vehicle</dt><dd class="muted">None — <?= e($doc['no_vehicle_reason']) ?></dd>
        <?php endif; ?>
      </dl>
    </div></div>
  </div>

  <div class="panel">
    <div class="panel__body panel__body--flush">
      <table class="tbl">
        <thead><tr><th>Description</th><th>SKU</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td class="strong"><?= e($l['name']) ?>
              <?php if ($l['notes']): ?><div class="text-sm muted"><?= e($l['notes']) ?></div><?php endif; ?>
              <?php if ((int) $l['warranty_months'] > 0): ?><div class="text-sm muted"><?= (int) $l['warranty_months'] ?>-month warranty</div><?php endif; ?>
              <?php if (!empty($l['mfr_warranty'])): ?><div class="text-sm muted"><?= e($l['mfr_warranty']) ?> warranty</div><?php endif; ?>
            </td>
            <td class="docno"><?= e($l['sku']) ?></td>
            <td class="right num"><?= rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') ?></td>
            <td class="right num"><?= money($l['unit_price']) ?></td>
            <td class="right num strong"><?= money($l['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel__foot" style="display:block">
      <div class="totals" style="max-width:330px;margin-left:auto">
        <div><span class="muted">Subtotal</span><span><?= money($sub) ?></span></div>
        <div><span class="muted">Tax</span><span><?= money($tax) ?></span></div>
        <div class="grand"><span>Total</span><span><?= money($total) ?></span></div>
        <?php if ($docKind !== 'ESTIMATE'): ?>
          <div><span class="muted">Paid</span><span>−<?= money($paid) ?></span></div>
          <div class="grand"><span><?= $bal <= 0 ? 'Paid in full' : 'Balance due' ?></span><span><?= money($bal) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($docKind !== 'ESTIMATE' && !empty($payments)): ?>
  <div class="panel"><div class="panel__body panel__body--flush">
    <table class="tbl">
      <thead><tr><th>Payment</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr><td class="docno"><?= e($p['doc_number']) ?> <span class="muted text-sm"><?= e(fdate($p['paid_at'])) ?></span></td>
          <td><?= e(status_label($p['method'])) ?></td>
          <td class="muted"><?= e($p['reference'] ?: '—') ?></td>
          <td class="right num strong"><?= money($p['amount']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <?php endif; ?>

  <div class="panel"><div class="panel__body">
    <?php if ($docKind === 'ESTIMATE'): ?>
      <div class="text-sm muted"><strong>Authorization.</strong>
        <?php if (!empty($doc['authorized_at'])): ?>
          Authorized by <?= e($doc['authorized_by']) ?> on <?= e(fdatetime($doc['authorized_at'])) ?>
          via <?= e(status_label((string) $doc['authorization_method'])) ?>.
        <?php else: ?>
          By approving this estimate you authorize the work described above and agree to pay for the services and parts listed.
        <?php endif; ?>
      </div>
      <?php if (!empty($doc['signature_data'])): ?>
        <img src="<?= e($doc['signature_data']) ?>" alt="signature" style="height:80px;margin-top:10px">
      <?php endif; ?>
    <?php else: ?>
      <div class="text-sm muted"><?= e(App::setting('invoice_terms', '')) ?></div>
    <?php endif; ?>
    <div class="disclaimer"><?= e(App::setting('invoice_footer', 'Final invoice may vary due to job scope changes.')) ?></div>
  </div></div>
</div>
</body></html>
