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
 * The customer's copy of a diagnostic report. Opens clean and prints clean.
 * Shows findings, recommendation, drivability and the repair options side
 * by side (name, time frame, the lines at their price, the total) — never
 * internal notes, never cost or markup: $options carries none. A DRAFT
 * prints with a watermark so a preview cannot be handed over as the record.
 */
$co   = ['name' => App::setting('company_name', App::config('company')['name']),
         'phone'=> App::setting('company_phone', App::config('company')['phone']),
         'email'=> App::setting('company_email', App::config('company')['email']),
         'addr' => App::setting('company_address', 'Portland, OR')];
$custName = customer_name($customer);
$r        = $report;
$isDraft  = $r['status'] !== 'ISSUED';
$drive    = $r['drivability'] ? (DiagnosticController::DRIVABILITY[$r['drivability']] ?? null) : null;
$driveCls = ['SAFE' => 'alert--ok', 'CAUTION' => 'alert--warn', 'DO_NOT_DRIVE' => 'alert--danger'][$r['drivability'] ?? ''] ?? '';
$techName = $tech ? trim($tech['first_name'] . ' ' . $tech['last_name']) : '';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>DIAGNOSTIC REPORT <?= e($r['doc_number']) ?></title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<style>
  body{ background:var(--bg); padding:28px; }
  .sheet{ max-width:860px; margin:0 auto; position:relative; }
  .doc-head{ display:flex; justify-content:space-between; gap:24px; flex-wrap:wrap; }
  .section-label{ font-size:11px; letter-spacing:.12em; text-transform:uppercase; font-weight:700; color:var(--text-dim); margin-bottom:8px; }
  .body-text{ white-space:pre-wrap; line-height:1.6; font-size:15px; }
  .watermark{ position:fixed; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; z-index:0; }
  .watermark span{ font-size:120px; font-weight:800; letter-spacing:.2em; opacity:.07; transform:rotate(-24deg); }
  .options{ display:grid; gap:16px; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); }
  .option{ border:1px solid var(--line-strong); border-radius:var(--r-md); padding:16px; display:flex; flex-direction:column; gap:10px; break-inside:avoid; }
  .option--chosen{ border-color:rgba(21,196,126,.6); }
  .option__name{ font-size:16px; font-weight:730; }
  .option__lines{ width:100%; border-collapse:collapse; font-size:13px; }
  .option__lines td{ padding:4px 0; border-top:1px solid var(--line); vertical-align:top; }
  .option__lines td.right{ white-space:nowrap; padding-left:10px; }
  .option__total{ display:flex; justify-content:space-between; align-items:baseline; border-top:2px solid var(--line-strong); padding-top:8px; margin-top:auto; font-weight:730; font-size:17px; }
  .sig-line{ border-top:1px solid var(--line-strong); padding-top:6px; margin-top:44px; font-size:12px; color:var(--text-dim); }
  @media print{ body{ padding:0; background:#fff; } .no-print{ display:none } .alert{ -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
</head><body>
<?php if ($isDraft): ?><div class="watermark"><span>DRAFT</span></div><?php endif; ?>
<div class="sheet stack">

  <div class="no-print row" style="justify-content:flex-end">
    <?php if ($isDraft): ?>
      <span class="muted text-sm" style="margin-right:auto">Preview only — issue the report before giving it to the customer.</span>
    <?php endif; ?>
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
          <div class="tag">Diagnostic report</div>
          <div class="docno text-lg"><?= e($r['doc_number']) ?></div>
          <div class="muted text-sm"><?= e(fdate($r['issued_at'] ?? $r['created_at'])) ?></div>
          <div class="mt2"><?= badge($isDraft ? 'DRAFT' : 'ISSUED') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid grid--2">
    <div class="panel"><div class="panel__body">
      <div class="tag mb2">Prepared for</div>
      <div class="strong text-lg"><?= e($custName) ?></div>
      <?php $attn = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']); ?>
      <?php if (customer_is_business($customer) && $attn !== ''): ?>
        <div class="muted text-sm">Attn: <?= e($attn) ?></div>
      <?php endif; ?>
      <div class="muted text-sm">
        <?= e(phone_display($customer['phone_e164'])) ?><?= $customer['email'] ? '<br>' . e($customer['email']) : '' ?>
      </div>
    </div></div>
    <div class="panel"><div class="panel__body">
      <div class="tag mb2">Vehicle &amp; visit</div>
      <dl class="kv">
        <?php if ($vehicle): ?>
          <dt>Vehicle</dt><dd><?= e(trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model'])) ?></dd>
          <?php if ($vehicle['vin']): ?><dt>VIN</dt><dd class="docno"><?= e($vehicle['vin']) ?></dd><?php endif; ?>
          <dt>Plate</dt><dd><?= (int) $vehicle['no_plate'] === 1 ? 'NO PLATE' : e(trim($vehicle['plate'] . ' ' . $vehicle['plate_state']) ?: '—') ?></dd>
        <?php else: ?>
          <dt>Vehicle</dt><dd class="muted">—</dd>
        <?php endif; ?>
        <?php if ($wo['odometer']): ?><dt>Odometer</dt><dd><?= number_format((int) $wo['odometer']) ?> mi</dd><?php endif; ?>
        <dt>Work order</dt><dd class="docno"><?= e($wo['doc_number']) ?></dd>
        <?php $loc = trim(doc_address($est) . ' ' . (string) $sr['city']); ?>
        <?php if ($loc !== ''): ?><dt>Location</dt><dd><?= e($loc) ?></dd><?php endif; ?>
        <?php if ($techName !== ''): ?><dt>Technician</dt><dd><?= e($techName) ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>

  <?php if ($drive): ?>
    <div class="alert <?= e($driveCls) ?>"><strong>Drivability</strong><?= e($drive) ?></div>
  <?php endif; ?>

  <?php if (trim((string) $r['concern']) !== ''): ?>
  <div class="panel"><div class="panel__body">
    <div class="section-label">Your concern</div>
    <div class="body-text"><?= e($r['concern']) ?></div>
  </div></div>
  <?php endif; ?>

  <div class="panel"><div class="panel__body">
    <div class="section-label">What we found</div>
    <div class="body-text"><?= e($r['findings']) ?></div>
  </div></div>

  <div class="panel"><div class="panel__body">
    <div class="section-label">What we recommend</div>
    <div class="body-text"><?= e($r['recommendations']) ?></div>
  </div></div>

  <?php if ($options): ?>
  <div class="panel"><div class="panel__body">
    <div class="section-label">Your repair options</div>
    <div class="muted text-sm mb4">
      <?= count($options) === 1 ? 'The repair we propose, priced.' : 'Choose one. Each is priced separately; prices include parts and labor as listed.' ?>
    </div>
    <div class="options">
      <?php foreach ($options as $i => $o): $chosen = !empty($o['authorized_at']); ?>
        <div class="option <?= $chosen ? 'option--chosen' : '' ?>">
          <div>
            <div class="tag"><?= count($options) > 1 ? 'Option ' . chr(65 + $i) : 'Proposed repair' ?><?= $chosen ? ' · chosen' : '' ?></div>
            <div class="option__name"><?= e($o['option_label']) ?></div>
            <?php if ($o['option_timeframe']): ?><div class="muted text-sm">Time frame: <?= e($o['option_timeframe']) ?></div><?php endif; ?>
            <div class="docno text-sm faint">Estimate <?= e($o['doc_number']) ?></div>
          </div>
          <?php if ($o['lines']): ?>
          <table class="option__lines">
            <?php foreach ($o['lines'] as $l): ?>
              <tr>
                <td><?= e($l['name']) ?><?php if ((float) $l['qty'] != 1.0): ?> <span class="faint">× <?= rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') ?></span><?php endif; ?>
                  <?php if ($l['notes']): ?><div class="faint"><?= e($l['notes']) ?></div><?php endif; ?>
                  <?php if ((int) $l['warranty_months'] > 0): ?><div class="faint"><?= (int) $l['warranty_months'] ?>-month warranty</div><?php endif; ?>
                </td>
                <td class="right num"><?= money($l['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ((float) $o['tax_total'] > 0): ?><tr><td class="muted">Tax</td><td class="right num"><?= money($o['tax_total']) ?></td></tr><?php endif; ?>
          </table>
          <?php else: ?>
            <div class="muted text-sm">Pricing to follow.</div>
          <?php endif; ?>
          <div class="option__total"><span>Total</span><span class="num"><?= money($o['total']) ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="muted text-sm mt2">Each option is a written estimate. Work begins only on the option you authorize, at the price shown.</div>
  </div></div>
  <?php endif; ?>

  <div class="panel"><div class="panel__body">
    <div class="text-sm muted">
      This report describes the condition of the vehicle as observed at the time and place of the visit. Roadside
      diagnosis is limited by the tools and conditions available on site; further inspection may reveal additional
      issues. This report is not itself an authorization for work or a warranty; the options above are
      separate estimates, and only the one you authorize is binding.
    </div>
    <div class="grid grid--2">
      <div class="sig-line">Technician<?= $techName !== '' ? ' · ' . e($techName) : '' ?></div>
      <div class="sig-line">Customer acknowledgement</div>
    </div>
    <div class="disclaimer">Questions? Call <?= e($co['phone']) ?> and reference <?= e($r['doc_number']) ?>.</div>
  </div></div>
</div>
</body></html>
