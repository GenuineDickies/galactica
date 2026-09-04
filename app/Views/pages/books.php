<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /**
 * The books — a generic renderer for any ledger report.
 *
 * This file knows NOTHING about trial balances, aging or cores. It renders the
 * uniform shape LedgerReports returns, so adding a report is a method and a
 * registry line, and never a new page. That is deliberate: reporting is the
 * part of an accounting system that changes most, and a page per report is how
 * five reports become five inconsistent pages.
 *
 * @var array  $report   the report to draw
 * @var string $key      which one is selected
 * @var array  $reports  key => label, for the switcher
 * @var array  $accounts chart of accounts, for the account picker
 */
$cols = $report['columns'] ?? [];
?>
<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div><div class="panel__title">The Books</div>
      <div class="panel__sub">Read from the ledger itself. The Reports page answers how the business is
        doing; this answers what the books say. Where the two disagree, that is worth knowing.</div></div>
  </div>
  <div class="row wrap mt4" style="gap:6px">
    <?php foreach ($reports as $k => $label): ?>
      <a class="btn btn--sm <?= $key === $k ? '' : 'btn--ghost' ?>"
         href="<?= url('books?r=' . $k . '&from=' . e($from) . '&to=' . e($to)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div></div>

<?php /* Only the controls a given report actually uses. A date range on a
         trial balance means "as at"; on the cash-basis comparison it means a
         period; on account detail it means nothing at all. */ ?>
<div class="panel mb4"><div class="panel__body">
  <form method="get" action="<?= url('books') ?>" class="row wrap" style="gap:12px;align-items:flex-end">
    <input type="hidden" name="r" value="<?= e($key) ?>">
    <?php if ($key === 'account'): ?>
      <div class="field" style="margin:0"><label for="account">Account</label>
        <select class="select" name="account" style="min-width:280px" id="account">
          <option value="">— pick one —</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= e($a['account_number']) ?>" <?= $account === $a['account_number'] ? 'selected' : '' ?>>
              <?= e($a['account_number']) ?> · <?= e($a['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
    <?php elseif ($key === 'cash-basis'): ?>
      <div class="field" style="margin:0"><label for="from">From</label>
        <input class="input" type="date" name="from" value="<?= e($from) ?>" id="from"></div>
      <div class="field" style="margin:0"><label for="to">To</label>
        <input class="input" type="date" name="to" value="<?= e($to ?: date('Y-m-d')) ?>" id="to"></div>
    <?php elseif ($key === 'trial-balance'): ?>
      <div class="field" style="margin:0"><label for="to_2">As at</label>
        <input class="input" type="date" name="to" value="<?= e($to) ?>" id="to_2">
        <div class="hint">Leave blank for everything posted to date.</div></div>
    <?php else: ?>
      <div class="muted text-sm">This report shows the position right now.</div>
    <?php endif; ?>
    <button class="btn btn--primary">Show</button>
  </form>
</div></div>

<div class="panel">
  <div class="panel__head">
    <div>
      <div class="panel__title"><?= e($report['title']) ?></div>
      <?php if (($report['subtitle'] ?? '') !== ''): ?>
        <div class="panel__sub"><?= e($report['subtitle']) ?></div>
      <?php endif; ?>
    </div>
    <div class="topbar__spacer"></div>
    <?php if (empty($report['ok'])): ?>
      <span class="badge badge--danger"><i></i>does not balance</span>
    <?php endif; ?>
  </div>

  <div class="panel__body<?= $report['rows'] ? ' panel__body--flush' : '' ?>">
    <?php if (!$report['rows']): ?>
      <div class="empty">
        <div class="empty__icon" aria-hidden="true">○</div>
        <div class="empty__title">Nothing to show</div>
        <div class="empty__body"><?= e($report['note'] ?: 'No entries match.') ?></div>
      </div>
    <?php else: ?>
      <div class="table-wrap"><table class="tbl">
        <thead><tr>
          <?php foreach ($cols as $c): ?>
            <th class="<?= ($c['align'] ?? 'left') === 'right' ? 'right' : '' ?>" scope="col"><?= e($c['label']) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $row): ?>
          <tr>
            <?php foreach ($row as $i => $cell): ?>
              <?php $c = $cols[$i] ?? []; ?>
              <td class="<?= ($c['align'] ?? 'left') === 'right' ? 'right num' : '' ?><?= !empty($c['strong']) ? ' strong' : '' ?>"><?= e((string) $cell) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!empty($report['totals'])): ?>
        <tfoot><tr>
          <?php foreach ($report['totals'] as $i => $cell): ?>
            <?php $c = $cols[$i] ?? []; ?>
            <td class="strong <?= ($c['align'] ?? 'left') === 'right' ? 'right num' : '' ?>"><?= e((string) $cell) ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <?php if (($report['note'] ?? '') !== '' && $report['rows']): ?>
    <div class="panel__foot" style="display:block">
      <?php /* An out-of-balance ledger is not a footnote. */ ?>
      <div class="alert <?= empty($report['ok']) ? 'alert--danger' : 'alert--info' ?> mb0" role="status">
        <div><?= e($report['note']) ?></div>
      </div>
    </div>
  <?php endif; ?>
</div>
