<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="grid grid--kpi mb4">
  <div class="panel kpi"><div class="kpi__label">Expenses this month</div><div class="kpi__value"><?= money($mtd) ?></div></div>
</div>
<div class="split">
  <div class="panel">
    <div class="panel__head"><div class="panel__title">Recorded expenses</div></div>
    <div class="panel__body panel__body--flush">
    <?php if (!$rows): ?><div class="empty"><div class="empty__title">Nothing recorded</div>
      <div class="empty__body">Log parts, fuel and overheads here so job profitability is real rather than a guess.</div></div>
    <?php else: ?>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th scope="col">Date</th><th scope="col">Vendor</th><th scope="col">Category</th><th scope="col">Acct</th><th scope="col">Description</th><th class="right" scope="col">Amount</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="muted text-sm nowrap"><?= e(fdate($r['expense_date'])) ?></td>
            <td class="strong"><?= e($r['vendor_name']) ?></td>
            <td class="muted"><?= e($r['category']) ?></td>
            <td class="docno"><?= e($r['account_code'] ?: '—') ?></td>
            <td class="muted"><?= e($r['description']) ?></td>
            <td class="right num strong"><?= money($r['amount']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head"><div class="panel__title">Record an expense</div></div>
    <div class="panel__body">
      <form method="post" action="<?= url('expenses') ?>">
        <?= csrf_field() ?>
        <div class="field"><label class="req" for="vendor_name">Vendor</label><input class="input" name="vendor_name" required id="vendor_name"></div>
        <div class="field"><label for="category">Category</label>
          <select class="select" name="category" id="category">
            <option>Parts &amp; Materials</option><option>Sublet / Outside Services</option>
            <option>Consumables</option><option>Vehicle Fuel</option><option>Merchant Fees</option>
            <option>Tools &amp; Equipment</option><option>Insurance</option><option>Advertising</option>
            <option>SMS Messaging</option><option>Other</option>
          </select></div>
        <div class="form-grid">
          <div class="field"><label for="account_code">Account code</label><input class="input" name="account_code" placeholder="5000" id="account_code"></div>
          <div class="field"><label class="req" for="amount">Amount</label><input class="input" name="amount" type="number" step="0.01" required id="amount"></div>
          <div class="field"><label for="expense_date">Date</label><input class="input" name="expense_date" type="date" value="<?= e(date('Y-m-d')) ?>" id="expense_date"></div>
          <div class="field"><label for="payment_method">Method</label><select class="select" name="payment_method" id="payment_method">
            <option>CARD</option><option>CASH</option><option>CHECK</option><option>ACH</option></select></div>
        </div>
        <div class="field"><label for="description">Description</label><input class="input" name="description" id="description"></div>
        <div class="field"><label for="service_request_id">Attach to a job (optional)</label>
          <select class="select" name="service_request_id" id="service_request_id"><option value="">— overhead, not job-specific —</option>
          <?php foreach ($srs as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['doc_number']) ?></option><?php endforeach; ?></select>
          <div class="hint">A cost hits the ledger once. Attaching it to a job is for margin analysis, not a second posting.</div></div>
        <button class="btn btn--primary btn--block">Record expense</button>
      </form>
    </div>
  </div>
</div>
