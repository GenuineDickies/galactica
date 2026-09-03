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
$custName = customer_name($customer, true);
$office   = Auth::is('ADMIN', 'DISPATCH');
// Technicians settle this invoice but never edit it: the field bills what
// the work order recorded, so line editing stays office-side.
$locked   = $inv['status'] !== 'DRAFT' || !$office;
$canIssue = $inv['status'] === 'DRAFT' && $vehGate['ok'] && !$needsAuth && $lines;
?>

<div class="panel mb4">
  <div class="panel__body">
    <div class="row row--between wrap">
      <div>
        <div class="row wrap">
          <span class="docno text-lg"><?= e($inv['doc_number']) ?></span>
          <?= badge($inv['status']) ?>
          <span class="muted">for <?= $office ? '<a href="' . url('service-requests/' . $sr['id']) . '">' . e($sr['doc_number']) . '</a>' : e($sr['doc_number']) ?></span>
        </div>
        <div class="mt2 muted">
          <?= e($custName) ?>
          <?php if ($inv['issued_at']): ?> · issued <?= e(fdate($inv['issued_at'])) ?> · <?= Rules::termsDays($inv['terms'] ?? null) === null ? 'due on receipt' : 'due ' . e(fdate($inv['due_at'])) ?><?php endif; ?>
        </div>
      </div>
      <div class="btn-row">
        <a class="btn btn--ghost" href="<?= url('invoices/' . $inv['id'] . '/print') ?>" target="_blank">Print / PDF</a>
        <?php if ($inv['status'] === 'DRAFT'): ?>
          <form method="post" action="<?= url('invoices/' . $inv['id'] . '/issue') ?>">
            <?= csrf_field() ?>
            <button class="btn btn--primary <?= $canIssue ? '' : 'is-disabled' ?>" <?= $canIssue ? '' : 'disabled' ?>
                    title="<?= $canIssue ? '' : e($vehGate['reason'] ?: 'Re-authorization required first') ?>">Issue invoice</button>
          </form>
        <?php elseif (in_array($inv['status'], ['ISSUED','PARTIAL'], true)): ?>
          <form method="post" action="<?= url('payments/link/' . $inv['id']) ?>">
            <?= csrf_field() ?><button class="btn">Send payment link</button>
          </form>
          <button class="btn btn--primary" data-modal-open="payModal">Take payment</button>
        <?php elseif ($inv['status'] === 'PAID'): ?>
          <?php $rc = Db::one('SELECT * FROM receipts WHERE invoice_id = ? ORDER BY id DESC', [(int) $inv['id']]); ?>
          <?php if ($rc): ?><a class="btn btn--primary" href="<?= url('receipts/' . $rc['id']) ?>" target="_blank">View receipt</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$vehGate['ok'] && $inv['status'] === 'DRAFT'): ?>
  <div class="alert alert--danger gate">
    <div>
      <strong>Blocked: <?= e($vehGate['reason']) ?></strong>
      A VIN must be on file before money changes hands. If nothing was actually serviced on a vehicle — a loose wheel mount and balance, for instance —
      record that instead.
      <div class="btn-row mt2">
        <?php if ($office && $est): ?>
          <button class="btn btn--sm" data-url="<?= url('estimates/' . $est['id']) ?>">Capture VIN on the estimate</button>
        <?php elseif (!$office && $inv['work_order_id']): ?>
          <button class="btn btn--sm" data-url="<?= url('work-orders/' . $inv['work_order_id']) ?>">Capture VIN on the work order</button>
        <?php endif; ?>
        <button class="btn btn--sm btn--ghost" data-modal-open="noVehModal">No vehicle was serviced</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($needsAuth): ?>
  <div class="alert alert--warn gate">
    <div>
      <strong>Re-authorization required.</strong>
      The approved estimate was <?= money($estTotal) ?>; this invoice is <?= money($totals['total']) ?> —
      a difference of <?= money(abs($variance)) ?>, past the <?= money($varThresh) ?> tolerance
      (the lesser of $200 or 10%). Capture the customer's signature before issuing.
      <div class="btn-row mt2"><button class="btn btn--sm" data-modal-open="authModal">Capture re-authorization</button></div>
    </div>
  </div>
<?php elseif ((int) $inv['variance_authorized'] === 1): ?>
  <div class="alert alert--ok">
    <div><strong>Change in scope authorized</strong> by <?= e($inv['variance_auth_name']) ?> on <?= e(fdatetime($inv['variance_auth_at'])) ?>.</div>
  </div>
<?php endif; ?>

<div class="split">
  <div class="stack">
    <?php View::partial('partials/line_editor', [
      'lines' => $lines, 'catalog' => $catalog, 'totals' => $totals, 'locked' => $locked,
      'postUrl' => url('invoices/' . $inv['id'] . '/lines'),
      'delUrlBase' => url('invoices/' . $inv['id'] . '/lines'),
    ]); ?>

    <?php if ($payments): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Payments</div></div>
      <div class="panel__body panel__body--flush">
        <table class="tbl">
          <thead><tr><th>Payment</th><th>Method</th><th>Reference</th><th class="right">Tip</th><th class="right">Amount</th><th class="right">Receipt</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): $rc = Db::one('SELECT * FROM receipts WHERE payment_id = ?', [(int) $p['id']]); ?>
            <tr>
              <td class="docno"><?= e($p['doc_number']) ?><div class="text-sm faint"><?= e(fdatetime($p['paid_at'])) ?></div></td>
              <td><?= e(status_label($p['method'])) ?></td>
              <td class="muted text-sm"><?= e($p['reference'] ?: $p['processor_ref'] ?: '—') ?></td>
              <td class="right num muted"><?= (float) $p['tip_amount'] > 0 ? money($p['tip_amount']) : '—' ?></td>
              <td class="right num strong"><?= money($p['amount']) ?></td>
              <td class="right"><?php if ($rc): ?><a class="btn btn--sm btn--ghost" href="<?= url('receipts/' . $rc['id']) ?>" target="_blank">Receipt</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Audit trail</div></div>
      <div class="panel__body">
        <ul class="timeline">
          <?php foreach ($audit as $ev): ?>
            <li><div class="t-act"><?= e($ev['action']) ?><?= $ev['detail'] ? ' — <span class="muted" style="font-weight:400">' . e($ev['detail']) . '</span>' : '' ?></div>
                <div class="t-meta"><?= e($ev['actor_name']) ?> · <?= e(fdatetime($ev['created_at'])) ?></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Balance</div></div>
      <div class="panel__body">
        <div class="totals">
          <div><span class="muted">Invoice total</span><span><?= money($inv['total']) ?></span></div>
          <div><span class="muted">Paid</span><span><?= money($inv['amount_paid']) ?></span></div>
          <div class="grand"><span>Due</span><span><?= money($inv['balance_due']) ?></span></div>
        </div>
        <?php if (in_array($inv['status'], ['ISSUED','PARTIAL'], true)): ?>
          <button class="btn btn--primary btn--block mt4" data-modal-open="payModal">Take payment</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($links): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Payment links</div>
        <div class="topbar__spacer"></div><span class="tag">Hosted checkout</span></div>
      <div class="panel__body">
        <?php foreach ($links as $l): ?>
          <div style="border-top:1px solid var(--line);padding-top:8px;margin-top:8px">
            <div class="row row--between">
              <span class="strong"><?= money($l['amount']) ?></span>
              <?= badge($l['status'] === 'PAID' ? 'PAID' : 'ISSUED') ?>
            </div>
            <div class="text-sm faint mt2"><?= e($l['provider']) ?> · <?= e(ago($l['created_at'])) ?></div>
            <?php if ($l['url'] && $l['status'] !== 'PAID'): ?>
              <a class="btn btn--ghost btn--sm btn--block mt2" href="<?= e($l['url']) ?>" target="_blank" rel="noopener">Open checkout page</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="hint">The customer pays from wherever they are. The provider's callback records it against this invoice, signature-verified, exactly once.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Against the estimate</div></div>
      <div class="panel__body">
        <?php if ($est): ?>
          <dl class="kv">
            <dt>Approved</dt><dd><?= money($estTotal) ?></dd>
            <dt>This invoice</dt><dd class="strong"><?= money($totals['total']) ?></dd>
            <dt>Variance</dt><dd class="<?= abs($variance) > $varThresh ? '' : 'muted' ?>"><?= ($variance >= 0 ? '+' : '−') . money(abs($variance)) ?></dd>
            <dt>Tolerance</dt><dd class="muted"><?= money($varThresh) ?></dd>
          </dl>
          <div class="hint">Authorized by <?= e($est['authorized_by'] ?: '—') ?> · <?= e(status_label((string) ($est['authorization_method'] ?: 'NONE'))) ?>
            <?php if ($est['authorized_at']): ?> · <?= e(fdatetime($est['authorized_at'])) ?><?php endif; ?></div>
          <?php if ($office): ?><div class="btn-row mt2"><button class="btn btn--ghost btn--sm" data-url="<?= url('estimates/' . $est['id']) ?>">Open the estimate</button></div><?php endif; ?>
        <?php else: ?>
          <div class="muted text-sm">No estimate is linked to this invoice.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Bill to</div></div>
      <div class="panel__body">
        <div class="strong mb2"><?= e($custName) ?></div>
        <dl class="kv">
          <dt>Phone</dt><dd><?= e(phone_display($customer['phone_e164'])) ?></dd>
          <?php if ($customer['email']): ?><dt>Email</dt><dd><?= e($customer['email']) ?></dd><?php endif; ?>
          <dt>Vehicle</dt>
          <dd><?= $vehicle ? e(trim(($vehicle['year'] ?: '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model'])) : '<span class="muted">none</span>' ?></dd>
          <?php if ($vehicle): ?><dt>VIN</dt><dd class="docno"><?= e($vehicle['vin']) ?></dd><?php endif; ?>
          <?php if ($inv['no_vehicle_reason']): ?><dt>No vehicle</dt><dd class="muted"><?= e($inv['no_vehicle_reason']) ?></dd><?php endif; ?>
          <dt>Terms</dt><dd><?= e(payment_terms_label($inv['terms'] ?? null)) ?></dd>
          <?php if (($d = Rules::termsDays($inv['terms'] ?? null)) !== null && !$inv['issued_at']): ?>
            <dt>Will be due</dt><dd>issue date + <?= $d ?> days</dd>
          <?php endif; ?>
        </dl>
        <?php if ($inv['status'] === 'DRAFT' && $office): ?>
          <form method="post" action="<?= url('invoices/' . $inv['id'] . '/po') ?>" class="row mt4">
            <?= csrf_field() ?>
            <input class="input" name="po_number" value="<?= e($inv['po_number']) ?>" placeholder="PO number" maxlength="64">
            <button class="btn btn--sm">Save</button>
          </form>
        <?php elseif ($inv['po_number']): ?>
          <div class="hint mt4">PO # <span class="docno"><?= e($inv['po_number']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::is('ADMIN') && $inv['status'] !== 'VOID' && (float) $inv['amount_paid'] == 0.0): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Void</div></div>
      <div class="panel__body">
        <form method="post" action="<?= url('invoices/' . $inv['id'] . '/void') ?>">
          <?= csrf_field() ?>
          <div class="field"><label class="req">Reason</label><input class="input" name="void_reason" required></div>
          <button class="btn btn--danger btn--sm btn--block" data-confirm="Void this invoice? The record is kept, never deleted.">Void invoice</button>
        </form>
        <div class="hint">Records are never deleted. Voiding leaves the document and its history intact.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="payModal">
  <div class="modal panel">
    <div class="panel__head">
      <div><div class="panel__title">Take payment</div>
      <div class="panel__sub">Balance due: <strong><?= money($inv['balance_due']) ?></strong></div></div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('payments/take/' . $inv['id']) ?>">
        <?= csrf_field() ?>
        <?php /* Minted at render: a double-click or back-button resubmit
                 carries the same key and records exactly once. */ ?>
        <input type="hidden" name="idempotency_key" value="till-<?= (int) $inv['id'] ?>-<?= bin2hex(random_bytes(8)) ?>">
        <div class="form-grid">
          <div class="field"><label class="req">Amount</label>
            <input class="input" name="amount" type="number" step="0.01" min="0.01" value="<?= e(number_format((float) $inv['balance_due'], 2, '.', '')) ?>" required>
          </div>
          <div class="field"><label>Tip</label><input class="input" name="tip_amount" type="number" step="0.01" min="0" value="0"></div>
        </div>
        <div class="field">
          <label>Method</label>
          <div class="radio-row">
            <?php foreach (['CARD' => 'Card (Square)', 'CASH' => 'Cash', 'CHECK' => 'Check', 'ACH' => 'ACH', 'PROVIDER' => 'Provider remit'] as $k => $v): ?>
              <label class="radio-card"><input type="radio" name="method" value="<?= e($k) ?>" <?= $k === 'CARD' ? 'checked' : '' ?>><span><?= e($v) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field"><label>Reference</label><input class="input" name="reference" placeholder="Square payment id, check number, remittance ref"></div>
        <div class="alert alert--info"><div>The idempotency key is generated on the server before any processor call, so a double-click can never double-charge. Pay the invoice balance first; any amount above it is recorded as a tip for the assigned driver. Tips are tracked separately and are not taxable.</div></div>
        <button class="btn btn--primary btn--block">Record payment and issue receipt</button>
      </form>
    </div>
  </div>
</div>

<div class="modal-bg" id="authModal" data-customer-facing>
  <div class="modal panel">
    <div class="panel__head"><div class="panel__title">Capture re-authorization</div>
      <div class="topbar__spacer"></div><button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
    <div class="panel__body">
      <div class="alert alert--warn">
        <div>Estimate <?= money($estTotal) ?> → invoice <?= money($totals['total']) ?>. The customer is agreeing to the new amount.</div>
      </div>
      <form method="post" action="<?= url('invoices/' . $inv['id'] . '/authorize') ?>" data-sig-required>
        <?= csrf_field() ?>
        <div class="field"><label class="req">Authorizing name</label><input class="input" name="variance_auth_name" required placeholder="<?= e($custName) ?>"></div>
        <?php View::partial('partials/signature_field', [
          'id'       => 'varSig',
          'label'    => 'Signature',
          'required' => true,
          'title'    => 'Authorize the new amount',
          'subtitle' => 'Estimate ' . money($estTotal) . ' → invoice ' . money($totals['total'])
                        . ' — the customer is agreeing to the revised total.',
        ]); ?>
        <button class="btn btn--primary btn--block">Record re-authorization</button>
      </form>
    </div>
  </div>
</div>

<div class="modal-bg" id="noVehModal">
  <div class="modal panel">
    <div class="panel__head"><div class="panel__title">No vehicle was serviced</div>
      <div class="topbar__spacer"></div><button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
    <div class="panel__body">
      <form method="post" action="<?= url('invoices/' . $inv['id'] . '/no-vehicle') ?>">
        <?= csrf_field() ?>
        <div class="field"><label class="req">Reason</label>
          <input class="input" name="no_vehicle_reason" required placeholder="Loose wheel mount and balance — customer brought the wheel only">
        </div>
        <div class="hint">Only allowed when every line item on this invoice is flagged "no vehicle needed" in the catalog.</div>
        <button class="btn btn--primary btn--block mt4">Record and clear the gate</button>
      </form>
    </div>
  </div>
</div>
