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
 * Shared line-item editor. Used by estimates, work orders and invoices.
 * Items may ONLY be added from the catalog — there is no free-text row.
 *
 * @var array  $lines
 * @var array  $catalog
 * @var array  $totals
 * @var string $postUrl    add-line endpoint
 * @var string $delUrlBase delete endpoint prefix
 * @var bool   $locked
 * @var string $lockNote
 */
$locked = $locked ?? false;
?>
<div class="panel">
  <div class="panel__head">
    <div>
      <div class="panel__title">Line items</div>
      <div class="panel__sub">Catalog only — prices are snapshotted, so changing the catalog later never rewrites history.</div>
    </div>
    <div class="topbar__spacer"></div>
    <?php if (!$locked): ?>
      <button class="btn btn--sm" data-modal-open="catalogModal">+ Add from catalog</button>
    <?php else: ?>
      <span class="badge badge--slate"><i></i>Locked</span>
    <?php endif; ?>
  </div>

  <div class="panel__body panel__body--flush">
    <?php if (!$lines): ?>
      <div class="empty">
        <div class="empty__icon">＋</div>
        <div class="empty__title">No line items yet</div>
        <div class="empty__body">Nothing can be priced, dispatched or billed until at least one catalog item is on this document.</div>
        <?php if (!$locked): ?><button class="btn btn--primary" data-modal-open="catalogModal">Add the first item</button><?php endif; ?>
      </div>
    <?php else: ?>
<?php
        /* Internal-only rollups. This partial is never PRINTED for a customer,
         * but the device it is on IS handed to one for in-person signatures —
         * so every cost, profit and margin cell carries class="internal" and
         * disappears while a data-customer-facing modal is open (app.js sets
         * body.is-customer). Price and total stay: that is what they are
         * agreeing to. */
        $totCost = 0.0; $totProfit = 0.0;
      ?>
      <div class="table-wrap"><table class="tbl">
        <thead><tr>
          <th style="width:38px">#</th><th>Item</th><th>SKU</th>
          <th class="right">Qty</th><th class="right internal">My cost</th><th class="right">Price</th>
          <th class="right">Total</th><th class="right internal">Profit</th>
          <?php if (!$locked): ?><th style="width:44px"></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
          $cost   = (float) $l['unit_cost'];
          $price  = (float) $l['unit_price'];
          $qty    = (float) $l['qty'];
          $profit = ($price - $cost) * $qty;
          $margin = Markup::marginPct($l['unit_price'], $l['unit_cost']);
          /* Any line billing nothing is worth a second look before it reaches a
           * customer, not only an unpriced part. A miscellaneous charge is a
           * SERVICE, so the old PART-only test never flagged one that slipped
           * through at zero. */
          $needsPricing = $price <= 0 && $qty > 0;
          $totCost   += $cost * $qty;
          $totProfit += $profit;
        ?>
          <tr>
            <td class="faint"><?= (int) $l['line_no'] ?></td>
            <td>
              <span class="strong"><?= e($l['name']) ?></span>
              <?php if ((int) $l['price_overridden'] === 1): ?>
                <span class="badge badge--warn internal" style="margin-left:6px" title="Price manually overridden"><i></i>override</span>
              <?php endif; ?>
              <?php if ($needsPricing): ?>
                <span class="badge badge--danger internal" style="margin-left:6px"><i></i>needs pricing</span>
              <?php endif; ?>
              <?php if ($l['notes']): ?><div class="text-sm faint"><?= e($l['notes']) ?></div><?php endif; ?>
              <?php if ((int) $l['warranty_months'] > 0): ?>
                <span class="badge badge--info" style="margin-top:4px"><i></i><?= (int) $l['warranty_months'] ?> mo warranty</span>
              <?php endif; ?>
              <?php if (!empty($l['mfr_warranty'])): ?>
                <span class="badge badge--info" style="margin-top:4px"><i></i><?= e($l['mfr_warranty']) ?></span>
              <?php endif; ?>
              <?php if ((int) $l['vehicle_not_required'] === 1): ?>
                <span class="badge badge--slate" style="margin-top:4px"><i></i>No vehicle needed</span>
              <?php endif; ?>
            </td>
            <td class="docno"><?= e($l['sku']) ?></td>
            <td class="right num"><?= rtrim(rtrim(number_format($qty, 2), '0'), '.') ?> <span class="faint text-sm"><?= e($l['uom']) ?></span></td>
            <td class="right num faint internal"><?= $cost > 0 ? money($cost) : '—' ?></td>
            <td class="right num"><?= money($price) ?></td>
            <td class="right num strong"><?= money($l['line_total']) ?></td>
            <td class="right num internal <?= $profit < 0 ? 'text-danger' : 'text-ok' ?>">
              <?= money($profit) ?><?php if ($margin !== null): ?><div class="faint text-sm"><?= e($margin) ?>%</div><?php endif; ?>
            </td>
            <?php if (!$locked): ?>
            <td class="right">
              <form method="post" action="<?= e($delUrlBase . '/' . $l['id'] . '/delete') ?>" style="display:inline">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--sm" data-confirm="Remove this line?" title="Remove">✕</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="row row--between wrap internal" style="padding:10px 16px;border-top:1px solid var(--line);font-size:var(--fs-sm)">
        <span class="faint">Internal only — never printed on the customer document.</span>
        <span>My cost <span class="strong"><?= money($totCost) ?></span>
          · Profit <span class="strong <?= $totProfit < 0 ? 'text-danger' : 'text-ok' ?>"><?= money($totProfit) ?></span></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($lines): ?>
  <div class="panel__foot" style="display:block">
    <div class="totals" style="max-width:340px;margin-left:auto">
      <div><span class="muted">Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
      <?php if ($totals['discount'] > 0): ?><div><span class="muted">Discount</span><span>−<?= money($totals['discount']) ?></span></div><?php endif; ?>
      <div><span class="muted">Tax<span class="faint"> (<?= rtrim(rtrim(number_format(App::taxRate() * 100, 3), '0'), '.') ?>%)</span></span><span><?= money($totals['tax']) ?></span></div>
      <div class="grand"><span>Total</span><span><?= money($totals['total']) ?></span></div>
    </div>
    <div class="disclaimer">Final invoice may vary due to job scope changes.</div>
  </div>
  <?php endif; ?>
</div>

<?php if (!$locked): ?>
<div class="modal-bg" id="catalogModal">
  <div class="modal panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Add from Products &amp; Services</div>
        <div class="panel__sub">Pick an item, set the quantity, adjust the price if the job warrants it.</div>
      </div>
      <div class="topbar__spacer"></div>
      <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button>
    </div>
    <div class="panel__body">
      <input class="input mb4" id="catalog_search" placeholder="Search by name, SKU or category…" autocomplete="off">
      <div style="max-height:280px;overflow-y:auto;border:1px solid var(--line);border-radius:var(--r-md)">
        <table class="tbl">
          <thead><tr><th>Item</th><th>SKU</th><th>Type</th><th class="right">Price</th></tr></thead>
          <tbody>
          <?php foreach ($catalog as $it): ?>
            <?php $isMisc = (int) ($it['is_misc'] ?? 0) === 1; ?>
            <tr data-catalog-row="<?= e(strtolower($it['name'] . ' ' . $it['sku'] . ' ' . $it['category'] . ' ' . $it['item_type'] . ($isMisc ? ' misc miscellaneous' : ''))) ?>"
                data-pick-item="<?= (int) $it['id'] ?>" data-price="<?= e((string) $it['unit_price']) ?>"
                data-cost="<?= e((string) $it['unit_cost']) ?>" data-name="<?= e($it['name']) ?>"
                data-misc="<?= $isMisc ? '1' : '0' ?>">
              <td class="strong"><?= e($it['name']) ?>
                <?php if ($isMisc): ?><span class="badge badge--warn" style="margin-left:6px"><i></i>describe it</span><?php endif; ?>
                <div class="text-sm faint"><?= e($it['category']) ?></div></td>
              <td class="docno"><?= e($it['sku']) ?></td>
              <td><span class="badge badge--<?= $it['item_type'] === 'PART' ? 'accent' : ($it['item_type'] === 'FEE' ? 'warn' : 'info') ?>"><i></i><?= e($it['item_type']) ?></span></td>
              <td class="right num"><?= $isMisc ? '<span class="faint">you set it</span>'
                                        : ($it['pricing_model'] === 'ESTIMATE' ? '<span class="faint">quote</span>' : money($it['unit_price'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="<?= e($postUrl) ?>" class="mt4">
        <?= csrf_field() ?>
        <input type="hidden" name="catalog_item_id" id="catalog_item_id">
        <input type="hidden" name="price_overridden" id="line_overridden" value="0">
        <div class="alert alert--info"><div>Selected: <strong id="picked_name">nothing yet</strong></div></div>

        <?php /* Shown only for an is_misc catalog item. What is typed here
                 becomes the LINE's name — it is what the customer reads on the
                 estimate and the invoice, so it is required and it is worth
                 spelling out. The markup matrix is not consulted for these:
                 the price entered below is the price billed. */ ?>
        <div class="field" id="misc_name_field" style="display:none">
          <label>What is this charge for? <span class="faint">(printed on the customer document)</span></label>
          <input class="input" id="line_name" name="line_name" maxlength="160"
                 placeholder="e.g. Additional labor — extra 40 min on seized lug nuts">
          <div class="hint">Be specific. This sentence is what defends the charge if the customer disputes it.</div>
        </div>

        <div class="form-grid form-grid--3">
          <div class="field"><label>Quantity</label><input class="input" id="line_qty" name="qty" type="number" step="0.01" min="0" value="1"></div>
          <div class="field"><label>My cost <span class="faint">(never shown to the customer)</span></label>
            <input class="input" id="line_cost" name="unit_cost" type="number" step="0.01" min="0" placeholder="0.00"
                   data-price-endpoint="<?= url('pricing/suggest') ?>"></div>
          <div class="field"><label>Customer price</label>
            <input class="input" id="line_price" name="unit_price" type="number" step="0.01" min="0">
            <div class="hint" id="line_price_note"></div></div>
        </div>
        <div class="row row--between wrap">
          <div class="field" style="flex:1;min-width:220px"><label>Note on this line</label>
            <input class="input" name="line_notes" placeholder="Optional — appears on the customer document"></div>
          <div class="field"><label>Line total</label>
            <div class="input" style="display:flex;align-items:center;min-width:120px" id="line_total_preview">$0.00</div></div>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Add line item</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
