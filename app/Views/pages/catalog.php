<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php $groups = []; foreach ($rows as $r) { $groups[$r['item_type']][] = $r; } ?>
<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div><div class="panel__title">Products &amp; Services</div>
      <div class="panel__sub">Every line item on every document comes from here. Codes are never reused once an invoice references them.</div></div>
    <?php if (Auth::is('ADMIN')): ?><button class="btn btn--primary" data-modal-open="newItemModal">Add item</button><?php endif; ?>
  </div>
</div></div>

<?php foreach (['SERVICE' => 'Services', 'PART' => 'Parts', 'FEE' => 'Fees'] as $type => $label):
  if (empty($groups[$type])) continue; ?>
<div class="panel mb4">
  <div class="panel__head"><div class="panel__title"><?= e($label) ?></div>
    <div class="topbar__spacer"></div><span class="tag"><?= count($groups[$type]) ?> items</span></div>
  <div class="panel__body panel__body--flush">
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th scope="col">Item</th><th scope="col">SKU</th><th scope="col">Category</th><th scope="col">Pricing</th><th class="right" scope="col">Price</th><th class="right" scope="col">Cost</th><th class="right" scope="col">Margin</th><th scope="col">Flags</th><?php if (Auth::is('ADMIN')): ?><th scope="col"></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($groups[$type] as $r):
        $margin = Markup::marginPct($r['unit_price'], $r['unit_cost']); ?>
        <tr style="<?= (int) $r['is_active'] === 0 ? 'opacity:.45' : '' ?>">
          <td class="strong"><?= e($r['name']) ?>
            <?php if ((int) ($r['price_overridden'] ?? 0) === 1): ?>
              <span class="badge badge--warn" style="margin-left:6px" title="Price manually set, not from the matrix"><i></i>override</span>
            <?php endif; ?>
          </td>
          <td class="docno"><?= e($r['sku']) ?>
            <?php if (!empty($r['vendor_part_number'])): ?>
              <div class="text-sm faint"><?= e($r['vendor_name'] ?: 'Vendor') ?> <?= e($r['vendor_part_number']) ?></div>
            <?php endif; ?></td>
          <td class="muted"><?= e($r['category']) ?></td>
          <td class="muted text-sm"><?= e(status_label($r['pricing_model'])) ?></td>
          <td class="right num"><?= $r['pricing_model'] === 'ESTIMATE' ? '<span class="faint">quote</span>' : money($r['unit_price']) ?></td>
          <td class="right num muted"><?= (float) $r['unit_cost'] > 0 ? money($r['unit_cost']) : '—' ?></td>
          <td class="right num muted"><?= $margin !== null ? e($margin) . '%' : '—' ?></td>
          <td>
            <?php if ((int) $r['vehicle_not_required'] === 1): ?><span class="badge badge--slate"><i></i>No vehicle</span><?php endif; ?>
            <?php if ((int) ($r['is_misc'] ?? 0) === 1): ?><span class="badge badge--warn"><i></i>Misc slot</span><?php endif; ?>
            <?php if ((int) $r['warranty_months'] > 0): ?><span class="badge badge--info"><i></i><?= (int) $r['warranty_months'] ?>mo</span><?php endif; ?>
            <?php if (!empty($r['mfr_warranty'])): ?><span class="badge badge--info"><i></i><?= e($r['mfr_warranty']) ?></span><?php endif; ?>
            <?php if (Markup::toCents($r['core_charge'] ?? 0) > 0): ?>
              <span class="badge badge--warn" title="Refundable core deposit — posts to 2050, never revenue"><i></i>core <?= e(money($r['core_charge'])) ?></span>
            <?php endif; ?>
            <?php if ((int) $r['is_active'] === 0): ?><span class="badge badge--danger"><i></i>Inactive</span><?php endif; ?>
          </td>
          <?php if (Auth::is('ADMIN')): ?>
          <td class="right nowrap">
            <button class="btn btn--ghost btn--sm" data-modal-open="editItem<?= (int) $r['id'] ?>" type="button">Edit</button>
            <form method="post" action="<?= url('catalog/' . $r['id'] . '/toggle') ?>" style="display:inline"><?= csrf_field() ?>
              <button class="btn btn--ghost btn--sm"><?= (int) $r['is_active'] === 1 ? 'Retire' : 'Restore' ?></button></form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
</div>
<?php endforeach; ?>

<?php if (Auth::is('ADMIN')): ?>
<div class="modal-bg" id="newItemModal"><div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="newItemModal_title">
  <div class="panel__head"><div class="panel__title" id="newItemModal_title">Add catalog item</div><div class="topbar__spacer"></div>
    <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
  <div class="panel__body">
    <form method="post" action="<?= url('catalog') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label for="sku_input">Part number</label>
          <div class="row" style="gap:6px">
            <input class="input" name="sku" id="sku_input" placeholder="Auto-assigned if blank" style="text-transform:uppercase">
            <button type="button" class="btn btn--sm" id="sku_gen" data-suggest-sku="<?= url('catalog/suggest-sku') ?>">Generate</button>
          </div>
          <div class="hint" id="sku_note">Leave blank to assign automatically, click Generate, or type your own.</div>
        </div>
        <div class="field"><label for="item_type">Type</label><select class="select" name="item_type" id="item_type">
          <option value="SERVICE">Service</option><option value="PART">Part</option><option value="FEE">Fee</option></select></div>
        <div class="field col-span-2"><label class="req" for="name">Name</label><input class="input" name="name" required id="name"></div>
        <div class="field"><label for="category">Category</label><input class="input" name="category" id="category"></div>
        <div class="field"><label for="pricing_model">Pricing model</label><select class="select" name="pricing_model" id="pricing_model">
          <option value="FLAT">Flat rate</option><option value="HOURLY">Hourly</option>
          <option value="PER_UNIT">Per unit</option><option value="ESTIMATE">Estimate required</option></select></div>
        <div class="field"><label for="cat_cost">My cost</label>
          <input class="input" id="cat_cost" name="unit_cost" type="number" step="0.01" min="0" value="0"
                 data-price-endpoint="<?= url('pricing/suggest') ?>"></div>
        <div class="field"><label for="cat_price">Customer price</label>
          <input class="input" id="cat_price" name="unit_price" type="number" step="0.01" min="0" value="0">
          <input type="hidden" name="price_overridden" id="cat_overridden" value="0">
          <div class="hint" id="cat_price_note"></div></div>
        <div class="field"><label for="uom">Unit</label><input class="input" name="uom" value="job" id="uom"></div>
        <div class="field"><label for="core_charge">Core charge</label>
          <input class="input" name="core_charge" type="number" step="0.01" min="0" value="0" id="core_charge">
          <div class="hint">Refundable deposit on a reman part, charged to the customer and given back
            when the old unit comes in. Never revenue — it posts to 2050 Core Deposits Payable until
            the core is settled. Leave at 0 for anything without a core.</div></div>
        <div class="field"><label for="warranty_months">Warranty (months)</label><input class="input" name="warranty_months" type="number" value="0" id="warranty_months"></div>
        <div class="field"><label for="mfr_warranty">Mfr warranty</label><input class="input" name="mfr_warranty" id="mfr_warranty">
          <div class="hint">This part's manufacturer/vendor warranty, if any — e.g. "AutoZone Limited Lifetime", "2-year", "90-day". Stated as-is on customer documents; leave blank if none.</div></div>
        <div class="field"><label for="vendor_name">Vendor</label><input class="input" name="vendor_name" placeholder="AutoZone" id="vendor_name"></div>
        <div class="field"><label for="vendor_part_number">Vendor part #</label><input class="input" name="vendor_part_number" placeholder="DS1414" style="text-transform:uppercase" id="vendor_part_number">
          <div class="hint">Their number, for reorders and warranty claims. Internal only.</div></div>
        <div class="field"><label for="revenue_account">Revenue account</label>
          <select class="select" name="revenue_account" data-account-select="REVENUE" id="revenue_account">
            <option value="">— none —</option>
            <?php foreach ($revenue_accounts as $a): ?>
              <option value="<?= e($a['account_number']) ?>"><?= e($a['account_number']) ?> · <?= e($a['name']) ?></option>
            <?php endforeach; ?>
            <option value="__new__">+ Create new revenue account…</option>
          </select></div>
        <div class="field"><label for="cogs_account">COGS account</label>
          <select class="select" name="cogs_account" data-account-select="COGS" id="cogs_account">
            <option value="">— none —</option>
            <?php foreach ($cogs_accounts as $a): ?>
              <option value="<?= e($a['account_number']) ?>"><?= e($a['account_number']) ?> · <?= e($a['name']) ?></option>
            <?php endforeach; ?>
            <option value="__new__">+ Create new COGS account…</option>
          </select></div>
      </div>
      <label class="checkline"><input type="checkbox" name="taxable" value="1"><span>Taxable</span></label>
      <label class="checkline"><input type="checkbox" name="vehicle_not_required" value="1">
        <span>No vehicle required. <span class="faint">Lets this item be invoiced without a VIN — e.g. a loose-wheel mount and balance.</span></span></label>
      <label class="checkline"><input type="checkbox" name="is_misc" value="1">
        <span>Miscellaneous charge slot. <span class="faint">Not a real product — a placeholder for an ad-hoc charge. Whoever adds it to a document must type what it is for, and the price they enter is billed as entered: the markup matrix is not consulted.</span></span></label>
      <div class="field"><label for="description">Description</label><textarea class="textarea" name="description" id="description"></textarea></div>
      <button class="btn btn--primary btn--block">Add to catalog</button>
    </form>
  </div>
</div></div>

<?php /* Layered above the Add-item modal (later in the DOM at the same
         z-index), so creating an account never loses the half-filled item
         form behind it. Submits via fetch to /accounts; app.js inserts the
         new option into the picker that opened it. */ ?>
<div class="modal-bg" id="newAccountModal"><div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="newAccountModal_title">
  <div class="panel__head"><div class="panel__title" id="acct_modal_title">New account</div><div class="topbar__spacer"></div>
    <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
  <div class="panel__body">
    <div class="form-grid">
      <div class="field"><label class="req" for="acct_number">Account number</label>
        <input class="input" id="acct_number" placeholder="4050">
        <div class="hint">4xxx revenue · 5xxx cost of goods sold. Numbers are permanent.</div></div>
      <div class="field"><label class="req" for="acct_name">Name</label>
        <input class="input" id="acct_name" placeholder="Towing Revenue"></div>
    </div>
    <input type="hidden" id="acct_type" value="REVENUE">
    <div class="hint hint--bad hide" id="acct_error"></div>
    <button type="button" class="btn btn--primary btn--block" id="acct_save"
            data-endpoint="<?= url('accounts') ?>">Create account</button>
  </div>
</div></div>
<?php /* One edit modal per item, prefilled from the row.
         The SKU is shown but NOT editable: a part number is identity, every
         document snapshots it, and the codes-are-never-reused rule rests on it
         holding still. A wrong number is retired and re-created, as before.
         Editing an item never touches a document already issued — lines
         snapshot price, cost, markup, accounts and core value when they are
         added, so this changes only what the NEXT line will carry. */ ?>
<?php foreach ($rows as $r): ?>
<div class="modal-bg" id="editItem<?= (int) $r['id'] ?>"><div class="modal panel" role="dialog" aria-modal="true" aria-labelledby="editItem<?= (int) $r['id'] ?>_title">
  <div class="panel__head"><div class="panel__title" id="editItem<?= (int) $r['id'] ?>_title">Edit <?= e($r['name']) ?></div><div class="topbar__spacer"></div>
    <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
  <div class="panel__body">
    <form method="post" action="<?= url('catalog/' . (int) $r['id'] . '/edit') ?>">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label for="sku_ro_<?= (int) $r['id'] ?>">Part number</label>
          <input class="input" id="sku_ro_<?= (int) $r['id'] ?>" value="<?= e($r['sku']) ?>" disabled>
          <div class="hint">Permanent. A wrong number is retired and re-created, never edited.</div></div>
        <div class="field"><label for="item_type_<?= (int) $r['id'] ?>">Type</label><select class="select" name="item_type" id="item_type_<?= (int) $r['id'] ?>">
          <?php foreach (['SERVICE' => 'Service', 'PART' => 'Part', 'FEE' => 'Fee'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $r['item_type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?></select></div>
        <div class="field col-span-2"><label class="req" for="name_<?= (int) $r['id'] ?>">Name</label>
          <input class="input" name="name" value="<?= e($r['name']) ?>" required id="name_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="category_<?= (int) $r['id'] ?>">Category</label>
          <input class="input" name="category" value="<?= e($r['category']) ?>" id="category_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="pricing_model_<?= (int) $r['id'] ?>">Pricing model</label><select class="select" name="pricing_model" id="pricing_model_<?= (int) $r['id'] ?>">
          <?php foreach (['FLAT' => 'Flat rate', 'HOURLY' => 'Hourly', 'PER_UNIT' => 'Per unit', 'ESTIMATE' => 'Estimate required'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $r['pricing_model'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?></select></div>
        <div class="field"><label for="unit_cost_<?= (int) $r['id'] ?>">My cost</label>
          <input class="input" name="unit_cost" type="number" step="0.01" min="0" value="<?= e(number_format((float) $r["unit_cost"], 2, ".", "")) ?>" id="unit_cost_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="unit_price_<?= (int) $r['id'] ?>">Customer price</label>
          <input class="input" name="unit_price" type="number" step="0.01" min="0" value="<?= e(number_format((float) $r["unit_price"], 2, ".", "")) ?>" id="unit_price_<?= (int) $r['id'] ?>">
          <input type="hidden" name="price_overridden" value="1">
          <div class="hint">Editing a price here counts as an override — the matrix will not restate it.</div></div>
        <div class="field"><label for="uom_<?= (int) $r['id'] ?>">Unit</label>
          <input class="input" name="uom" value="<?= e($r['uom']) ?>" id="uom_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="core_charge_<?= (int) $r['id'] ?>">Core charge</label>
          <input class="input" name="core_charge" type="number" step="0.01" min="0" value="<?= e(number_format((float) ($r["core_charge"] ?? 0), 2, ".", "")) ?>" id="core_charge_<?= (int) $r['id'] ?>">
          <div class="hint">Refundable. Posts to 2050 Core Deposits Payable, never to revenue.</div></div>
        <div class="field"><label for="warranty_months_<?= (int) $r['id'] ?>">Warranty (months)</label>
          <input class="input" name="warranty_months" type="number" value="<?= (int) $r['warranty_months'] ?>" id="warranty_months_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="mfr_warranty_<?= (int) $r['id'] ?>">Mfr warranty</label>
          <input class="input" name="mfr_warranty" value="<?= e($r['mfr_warranty'] ?? '') ?>" id="mfr_warranty_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="vendor_name_<?= (int) $r['id'] ?>">Vendor</label>
          <input class="input" name="vendor_name" value="<?= e($r['vendor_name'] ?? '') ?>" id="vendor_name_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="vendor_part_number_<?= (int) $r['id'] ?>">Vendor part #</label>
          <input class="input" name="vendor_part_number" value="<?= e($r['vendor_part_number'] ?? '') ?>" style="text-transform:uppercase" id="vendor_part_number_<?= (int) $r['id'] ?>"></div>
        <div class="field"><label for="revenue_account_<?= (int) $r['id'] ?>">Revenue account</label>
          <select class="select" name="revenue_account" id="revenue_account_<?= (int) $r['id'] ?>">
            <option value="">— none —</option>
            <?php foreach ($revenue_accounts as $a): ?>
              <option value="<?= e($a['account_number']) ?>" <?= (string) $r['revenue_account'] === (string) $a['account_number'] ? 'selected' : '' ?>>
                <?= e($a['account_number']) ?> · <?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="cogs_account_<?= (int) $r['id'] ?>">COGS account</label>
          <select class="select" name="cogs_account" id="cogs_account_<?= (int) $r['id'] ?>">
            <option value="">— none —</option>
            <?php foreach ($cogs_accounts as $a): ?>
              <option value="<?= e($a['account_number']) ?>" <?= (string) $r['cogs_account'] === (string) $a['account_number'] ? 'selected' : '' ?>>
                <?= e($a['account_number']) ?> · <?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <label class="checkline"><input type="checkbox" name="taxable" value="1" <?= (int) $r['taxable'] === 1 ? 'checked' : '' ?>><span>Taxable</span></label>
      <label class="checkline"><input type="checkbox" name="vehicle_not_required" value="1" <?= (int) $r['vehicle_not_required'] === 1 ? 'checked' : '' ?>>
        <span>No vehicle required</span></label>
      <label class="checkline"><input type="checkbox" name="is_misc" value="1" <?= (int) ($r['is_misc'] ?? 0) === 1 ? 'checked' : '' ?>>
        <span>Miscellaneous charge slot. <span class="faint">Typed description per line, priced as entered, no markup.</span></span></label>
      <div class="field"><label for="description_<?= (int) $r['id'] ?>">Description</label><textarea class="textarea" name="description" id="description_<?= (int) $r['id'] ?>"><?= e($r['description'] ?? '') ?></textarea></div>
      <div class="alert alert--info" role="status"><div>Saving changes what the NEXT line will carry. Estimates, work orders
        and invoices already issued keep the values they snapshotted.</div></div>
      <button class="btn btn--primary btn--block">Save changes</button>
    </form>
  </div>
</div></div>
<?php endforeach; ?>
<?php endif; ?>
