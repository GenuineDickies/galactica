<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /**
 * Core deposits — money held, and parts owed.
 *
 * The column that matters is WHERE IT IS, not what it is worth. A core is lost
 * by riding around in a van, not by being mispriced.
 *
 * @var array $rows    core_records for the chosen filter
 * @var array $summary ['count' => int, 'total' => string] of everything open
 * @var array $overdue rows past their return window
 * @var int   $window  days a customer has to bring the old unit back
 * @var array $techs   active users, for "who collected it"
 * @var string $status the active filter
 * @var bool  $needs_schema true when core_records has not been created yet
 */
$needs_schema = $needs_schema ?? false;
$next = [
    'CHARGED'   => [['COLLECTED', 'Old unit collected'], ['FORFEITED', 'Forfeit — never returned']],
    'COLLECTED' => [['RETURNED', 'Returned to supplier'], ['SETTLED', 'Refund customer & close'], ['FORFEITED', 'Forfeit']],
    'RETURNED'  => [['CREDITED', 'Supplier credited us'], ['SETTLED', 'Refund customer & close']],
    'CREDITED'  => [['SETTLED', 'Refund customer & close']],
];
$tone = ['CHARGED' => 'warn', 'COLLECTED' => 'info', 'RETURNED' => 'info',
         'CREDITED' => 'accent', 'SETTLED' => 'success', 'FORFEITED' => 'slate'];
?>
<?php if ($needs_schema): ?>
<div class="panel"><div class="panel__body">
  <div class="alert alert--warn mb0"><div>
    <strong>The core deposits table has not been created yet.</strong>
    The code for this page is deployed but the database has not caught up —
    schema changes are applied by hand here, deliberately.
    Open <a href="<?= url('admin/schema') ?>">Database schema</a> and apply the pending change.
    Nothing is broken and nothing is lost.
  </div></div>
</div></div>
<?php return; endif; ?>

<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div><div class="panel__title">Core Deposits</div>
      <div class="panel__sub">Refundable deposits on remanufactured parts. Never revenue — held in
        2050 Core Deposits Payable until the old unit stops moving.</div></div>
    <div class="row" style="gap:8px">
      <span class="tag"><?= (int) $summary['count'] ?> open · <?= money($summary['total']) ?> held</span>
      <?php if ($overdue && Auth::is('ADMIN')): ?>
        <form method="post" action="<?= url('cores/sweep') ?>" style="display:inline"><?= csrf_field() ?>
          <button class="btn btn--primary"
            data-confirm="Forfeit <?= count($overdue) ?> core(s) past the <?= (int) $window ?>-day window? Each one moves out of the liability and becomes revenue. This is a real accounting event.">
            Forfeit <?= count($overdue) ?> overdue
          </button></form>
      <?php endif; ?>
    </div>
  </div>
</div></div>

<?php if ($overdue): ?>
<div class="panel mb4"><div class="panel__body">
  <div class="alert alert--warn mb0"><div>
    <strong><?= count($overdue) ?> core<?= count($overdue) === 1 ? '' : 's' ?> past the <?= (int) $window ?>-day window.</strong>
    Forfeiting moves the deposit from money you owe into money you earned. It is the only
    point at which a core becomes revenue, so nothing does it automatically — you press the button.
    Refunding a good customer on day 40 anyway is a fine reason not to.
  </div></div>
</div></div>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__title">
      <?= $status === 'ALL' ? 'Every core' : ($status === 'SETTLED' ? 'Settled' : ($status === 'FORFEITED' ? 'Forfeited' : 'Open')) ?>
    </div>
    <div class="topbar__spacer"></div>
    <div class="row" style="gap:6px">
      <?php foreach (['OPEN' => 'Open', 'SETTLED' => 'Settled', 'FORFEITED' => 'Forfeited', 'ALL' => 'All'] as $k => $v): ?>
        <a class="btn btn--sm <?= $status === $k ? '' : 'btn--ghost' ?>" href="<?= url('cores?status=' . $k) ?>"><?= e($v) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel__body panel__body--flush">
    <?php if (!$rows): ?>
      <div class="empty">
        <div class="empty__icon">○</div>
        <div class="empty__title">Nothing here</div>
        <div class="empty__body">A core record is created automatically when an invoice is issued
          carrying a part with a core charge. Set that on the item in Products &amp; Services.</div>
      </div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Part</th><th>Where it is</th><th class="right">Value</th>
        <th>Charged</th><th>Due back</th><th>Supplier</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $st   = (string) $r['status'];
        $late = in_array($st, Cores::OPEN, true) && $r['due_back_by'] && $r['due_back_by'] < date('Y-m-d'); ?>
        <tr>
          <td class="strong"><?= e($r['part_name']) ?>
            <div class="docno text-sm faint"><?= e($r['sku']) ?></div></td>
          <td>
            <span class="badge badge--<?= $tone[$st] ?? 'slate' ?>"><i></i><?= e(Cores::label($st)) ?></span>
            <?php if ($r['collected_at']): ?>
              <div class="text-sm faint">picked up <?= e(fdate($r['collected_at'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="right num"><?= money((float) $r['core_value'] * max(1, (float) $r['qty'])) ?></td>
          <td class="muted text-sm"><?= e(fdate($r['charged_at'])) ?></td>
          <td class="text-sm <?= $late ? 'strong' : 'muted' ?>">
            <?= e($r['due_back_by'] ?: '—') ?>
            <?php if ($late): ?><span class="badge badge--danger" style="margin-left:6px"><i></i>overdue</span><?php endif; ?>
          </td>
          <td class="muted text-sm"><?= e($r['supplier_name'] ?: '—') ?></td>
          <td class="right nowrap">
            <?php if (!empty($next[$st])): ?>
              <button class="btn btn--ghost btn--sm" type="button" data-modal-open="core<?= (int) $r['id'] ?>">Move</button>
            <?php else: ?>
              <span class="faint text-sm">closed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php /* One modal per movable core. Each button names the physical event, not
         the status — "Old unit collected" is a thing that happened; "COLLECTED"
         is a database value, and the person holding the part should not have to
         translate. */ ?>
<?php foreach ($rows as $r): $st = (string) $r['status']; if (empty($next[$st])) continue; ?>
<div class="modal-bg" id="core<?= (int) $r['id'] ?>"><div class="modal panel">
  <div class="panel__head"><div class="panel__title"><?= e($r['part_name']) ?></div><div class="topbar__spacer"></div>
    <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
  <div class="panel__body">
    <div class="text-sm muted mb4">
      <?= e($r['sku']) ?> · <?= money((float) $r['core_value'] * max(1, (float) $r['qty'])) ?> ·
      currently <?= e(Cores::label($st)) ?>
      <?php if (!empty($r['notes'])): ?><div class="mt2 faint"><?= nl2br(e($r['notes'])) ?></div><?php endif; ?>
    </div>

    <?php foreach ($next[$st] as [$to, $label]): ?>
      <form method="post" action="<?= url('cores/' . (int) $r['id'] . '/move') ?>" class="mb4">
        <?= csrf_field() ?>
        <input type="hidden" name="to" value="<?= e($to) ?>">

        <?php if ($to === 'COLLECTED'): ?>
          <div class="field"><label>Who has it</label>
            <select class="select" name="collected_by_id">
              <option value="">— me —</option>
              <?php foreach ($techs as $t): ?>
                <option value="<?= (int) $t['id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Who is carrying the old unit. This is the field that makes it findable later.</div></div>
        <?php elseif ($to === 'RETURNED'): ?>
          <div class="field"><label>Supplier</label>
            <input class="input" name="supplier_name" value="<?= e($r['supplier_name'] ?? '') ?>" placeholder="O'Reilly, NAPA…"></div>
        <?php endif; ?>

        <?php if ($to === 'FORFEITED'): ?>
          <div class="field"><label>Why</label><input class="input" name="notes" placeholder="Customer kept the old unit"></div>
        <?php else: ?>
          <div class="field"><label>Note</label><input class="input" name="notes"></div>
        <?php endif; ?>

        <button class="btn <?= $to === 'FORFEITED' ? 'btn--ghost' : 'btn--primary' ?> btn--block"
          <?php if ($to === 'FORFEITED'): ?>
            data-confirm="Forfeit this core? The deposit stops being money you owe and becomes revenue. That is the correct treatment when the customer keeps the old unit — and it cannot be undone except by a reversing entry."
          <?php elseif ($to === 'SETTLED'): ?>
            data-confirm="Refund the customer and close this core?"
          <?php endif; ?>
        ><?= e($label) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</div></div>
<?php endforeach; ?>
