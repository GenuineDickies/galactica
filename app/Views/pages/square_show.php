<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /**
 * One Square transaction, in full.
 *
 * The mapped fields are for scanning; the raw payload underneath is for the
 * question no column anticipated. Six years of charges with no documents
 * behind them are identified by circumstance, so nothing is hidden here.
 *
 * @var array   $r        the transaction
 * @var string  $raw      its payload, pretty-printed
 * @var array   $sameCard other charges on the same physical card
 * @var ?array  $customer the Square directory entry, when it has one
 */
$tone = ['UNREVIEWED' => 'warn', 'BUSINESS' => 'success', 'PERSONAL' => 'danger', 'TRANSFER' => 'info'];
$cls  = (string) $r['classification'];

/** A labelled value, skipped entirely when empty — a page of dashes reads as noise. */
$field = static function (string $label, $value, string $hint = ''): void {
    $v = trim((string) $value);
    if ($v === '' || $v === '0.00') { return; }
    echo '<div class="field" style="margin:0 0 12px">';
    echo '<label style="margin-bottom:2px">' . e($label) . '</label>';
    echo '<div class="strong">' . e($v) . '</div>';
    if ($hint !== '') { echo '<div class="hint">' . e($hint) . '</div>'; }
    echo '</div>';
};
?>
<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div>
      <div class="panel__title"><?= money($r['amount']) ?> · <?= e($r['object_type']) ?></div>
      <div class="panel__sub">
        <?= e(substr((string) $r['occurred_at'], 0, 16)) ?> ·
        <span class="badge badge--<?= $tone[$cls] ?? 'slate' ?>"><i></i><?= e(strtolower($cls)) ?></span>
        <?php if ($r['status'] !== 'COMPLETED'): ?>
          <span class="badge badge--slate"><i></i><?= e(strtolower((string) $r['status'])) ?></span>
        <?php endif; ?>
        <?php if ($r['posted_entry_id'] !== null): ?>
          <span class="badge badge--accent"><i></i>posted to the ledger</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="row" style="gap:6px">
      <?php if (trim((string) $r['receipt_url']) !== ''): ?>
        <?php /* Square's own receipt, exactly as the customer saw it. The most
                 direct answer to "what was this" that exists. */ ?>
        <a class="btn btn--primary" href="<?= e($r['receipt_url']) ?>" target="_blank" rel="noopener">Square receipt</a>
      <?php endif; ?>
      <?php if ($r['posted_entry_id'] === null): ?>
        <?php foreach (['BUSINESS' => 'Business', 'PERSONAL' => 'Personal'] as $as => $label): ?>
          <?php if ($cls !== $as): ?>
            <form method="post" action="<?= url('square/' . (int) $r['id'] . '/classify') ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="as" value="<?= e($as) ?>">
              <button class="btn btn--ghost">Mark <?= e($label) ?></button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <a class="btn btn--ghost" href="<?= url('square') ?>">Back</a>
    </div>
  </div>
</div></div>

<div class="split">
  <div>
    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">What it was</div></div>
      <div class="panel__body">
        <div class="form-grid">
          <?php
            $field('Receipt number', $r['receipt_number'], 'What a customer would quote you.');
            $field('Taken on', $r['device_name'], 'Which device rang it up.');
            $field('Square app', $r['square_product'], 'Point of sale, virtual terminal, online checkout.');
            $field('On their statement', $r['statement_desc'], 'What the customer saw on their bank statement.');
            $field('Note you typed', $r['note']);
            $field('Reference', $r['reference_id']);
            $field('Source', $r['source_type']);
            $field('Entry method', $r['entry_method'], 'Keyed, swiped, tapped, or a card on file.');
            $field('Team member', $r['team_member_id']);
          ?>
        </div>
      </div>
    </div>

    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">Money</div></div>
      <div class="panel__body">
        <div class="form-grid">
          <?php
            $field('Amount', money($r['amount']));
            $field('Tip', money($r['tip_amount']));
            $field('Square fee', money($r['fee_amount']));
            $field('Net to you', money($r['net_amount']), 'Amount plus tip, less the fee.');
            $field('Refunded', money($r['refunded_amount']));
            $field('Currency', $r['currency']);
          ?>
        </div>
      </div>
    </div>

    <?php if (trim((string) $r['decline_detail']) !== ''): ?>
    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">Why it failed</div></div>
      <div class="panel__body">
        <div class="alert alert--warn mb0" role="status"><div>
          <strong><?= e($r['decline_code']) ?></strong> — <?= e($r['decline_detail']) ?>
        </div></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel__head"><div class="panel__title">Everything Square sent</div>
        <div class="topbar__spacer"></div>
        <span class="tag">raw payload</span></div>
      <div class="panel__body">
        <?php /* Kept verbatim on every row since the import. When a mapped
                 column cannot answer a question, this can. */ ?>
        <pre style="margin:0;max-height:520px;overflow:auto;font:12px/1.6 ui-monospace,monospace;white-space:pre-wrap;word-break:break-word"><?= e($raw) ?></pre>
      </div>
    </div>
  </div>

  <div>
    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">Card</div></div>
      <div class="panel__body">
        <?php
          $field('Brand', trim(((string) $r['card_brand']) . ' ' . ((string) $r['card_type'])));
          $field('Last four', $r['card_last4'] ? '•••• ' . $r['card_last4'] : '');
          $field('Expires', $r['card_exp']);
          $field('Issuer prefix', $r['card_bin'], 'The first digits — identifies the issuing bank.');
          $field('Address check', $r['avs_status']);
          $field('CVV check', $r['cvv_status']);
        ?>
        <?php if (trim((string) $r['card_fingerprint']) !== ''): ?>
          <div class="hint mt2">Square fingerprints the physical card, which is what links the
            charges below even where no name was ever captured.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($customer): ?>
    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">Who</div></div>
      <div class="panel__body">
        <?php
          $field('Name', trim(((string) $customer['given_name']) . ' ' . ((string) $customer['family_name'])));
          $field('Company', $customer['company_name']);
          $field('Phone', $customer['phone_number']);
          $field('Email', $customer['email_address']);
          $field('Jobs on record', (string) (int) $customer['payment_count']);
          $field('Total spent', money($customer['payment_total']));
        ?>
      </div>
    </div>
    <?php elseif (trim((string) ($r['customer_name'] ?: $r['buyer_email'])) !== ''): ?>
    <div class="panel mb4">
      <div class="panel__head"><div class="panel__title">Who</div></div>
      <div class="panel__body">
        <?php
          $field('Name on the card', $r['cardholder_name']);
          $field('Email', $r['buyer_email']);
        ?>
        <div class="hint">Not linked to a Square customer record — this is whatever the payment itself carried.</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($sameCard): ?>
    <div class="panel">
      <div class="panel__head"><div class="panel__title">Same card</div>
        <div class="topbar__spacer"></div><span class="tag"><?= count($sameCard) ?></span></div>
      <div class="panel__body panel__body--flush">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th scope="col">Date</th><th class="right" scope="col">Amount</th><th scope="col">Who</th></tr></thead>
          <tbody>
          <?php foreach ($sameCard as $s): ?>
            <tr>
              <td class="text-sm muted nowrap">
                <a href="<?= url('square/' . (int) $s['id']) ?>"><?= e(substr((string) $s['occurred_at'], 0, 10)) ?></a>
              </td>
              <td class="right num"><?= money($s['amount']) ?></td>
              <td class="text-sm"><?= e($s['customer_name'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
