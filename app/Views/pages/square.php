<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /**
 * The imported Square history.
 *
 * Six years of it. The lenses down the side are the point — nobody reviews
 * 5,857 rows in a list, but "the 23 charges under $5" is a five-second
 * decision, and that is what turns an unreviewable pile into a morning's work.
 *
 * @var array  $rows    the current page
 * @var string $lens    which view is active
 * @var array  $lenses  key => label
 * @var array  $counts  key => how many rows that lens matches
 * @var array  $summary ['n' => count, 't' => total] for the whole filter
 * @var bool   $needs_schema
 */
$needs_schema = $needs_schema ?? false;
$tone = ['UNREVIEWED' => 'warn', 'BUSINESS' => 'success', 'PERSONAL' => 'danger', 'TRANSFER' => 'info'];
$qs = static fn(array $over = []): string => http_build_query(array_merge(
    ['lens' => $lens, 'q' => $q, 'page' => $page], $over));
?>
<?php if ($needs_schema): ?>
<div class="panel"><div class="panel__body">
  <div class="alert alert--warn mb0" role="status"><div>
    <strong>The Square mirror has not been created yet.</strong>
    Apply the pending change on <a href="<?= url('admin/schema') ?>">Database schema</a>,
    then import with <span class="docno">php data/square_sync.php --all</span>.
  </div></div>
</div></div>
<?php return; endif; ?>

<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div><div class="panel__title">Square History</div>
      <div class="panel__sub">Everything Square says happened, mirrored. <strong>Nothing here has touched
        your books.</strong> This account mixes White Knight work with personal spending, so each row
        needs a judgement before it can post — and only you can make it.</div></div>
  </div>

  <div class="row wrap mt4" style="gap:6px">
    <?php foreach ($lenses as $k => $label): ?>
      <a class="btn btn--sm <?= $lens === $k ? '' : 'btn--ghost' ?>" href="<?= url('square?lens=' . $k) ?>">
        <?= e($label) ?><?php if (isset($counts[$k])): ?> <span class="faint"><?= (int) $counts[$k] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= url('square') ?>" class="row wrap mt4" style="gap:8px;align-items:flex-end">
    <input type="hidden" name="lens" value="<?= e($lens) ?>">
    <div class="field" style="margin:0"><label for="q">Search</label>
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="Name, note, email, reference…" style="min-width:280px" id="q"></div>
    <button class="btn">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn--ghost" href="<?= url('square?lens=' . $lens) ?>">Clear</a><?php endif; ?>
  </form>
</div></div>

<?php /* Bulk action, and the reason it is safe: the filter is recomputed on
         the server from the lens name, so it can only ever touch the rows this
         list is actually showing. */ ?>
<?php if ($rows && $lens !== 'declined'): ?>
<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap" style="gap:12px">
    <div class="text-sm muted">
      <strong><?= (int) ($summary['n'] ?? 0) ?></strong> transactions in this view,
      totalling <strong><?= money($summary['t'] ?? 0) ?></strong>.
    </div>
    <div class="row" style="gap:6px">
      <?php foreach (['BUSINESS' => 'Mark all business', 'PERSONAL' => 'Mark all personal'] as $as => $label): ?>
        <form method="post" action="<?= url('square/classify-bulk') ?>" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="lens" value="<?= e($lens) ?>">
          <input type="hidden" name="as" value="<?= e($as) ?>">
          <button class="btn btn--sm <?= $as === 'BUSINESS' ? 'btn--primary' : 'btn--ghost' ?>"
            data-confirm="Mark all <?= (int) ($summary['n'] ?? 0) ?> transaction(s) in this view as <?= e($as) ?>? This records a judgement — it does not post anything to your books.">
            <?= e($label) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</div></div>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__title"><?= e($lenses[$lens]) ?></div>
    <div class="topbar__spacer"></div>
    <span class="tag"><?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="panel__body<?= $rows ? ' panel__body--flush' : '' ?>">
    <?php if (!$rows): ?>
      <div class="empty">
        <div class="empty__icon" aria-hidden="true">○</div>
        <div class="empty__title">Nothing in this view</div>
        <div class="empty__body">Try another lens, or clear the search.</div>
      </div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th scope="col">Date</th><th scope="col">Type</th><th class="right" scope="col">Amount</th><th scope="col">Status</th>
        <th scope="col">Who</th><th scope="col">Note</th><th scope="col">Class</th><th scope="col"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $cls = (string) $r['classification'];
        $who = trim((string) ($r['customer_name'] ?: $r['cardholder_name'] ?: $r['buyer_email'])); ?>
        <tr>
          <td class="muted text-sm nowrap"><?= e(substr((string) $r['occurred_at'], 0, 10)) ?></td>
          <td class="text-sm">
            <?= e($r['object_type']) ?>
            <?php if (($r['source_type'] ?? '') !== '' && $r['source_type'] !== 'CARD'): ?>
              <span class="badge badge--info" style="margin-left:4px"><i></i><?= e($r['source_type']) ?></span>
            <?php endif; ?>
          </td>
          <td class="right num"><?= money($r['amount']) ?>
            <?php if (Markup::toCents($r['fee_amount']) > 0): ?>
              <div class="text-sm faint">fee <?= e(money($r['fee_amount'])) ?></div>
            <?php endif; ?></td>
          <td class="text-sm">
            <?php if ($r['status'] === 'COMPLETED'): ?>
              <span class="muted">completed</span>
            <?php else: ?>
              <span class="badge badge--slate" title="A declined card is not money and will never post"><i></i><?= e(strtolower((string) $r['status'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-sm"><?= e($who !== '' ? $who : '—') ?>
            <?php if (($r['card_last4'] ?? '') !== ''): ?>
              <div class="faint text-sm"><?= e(trim(((string) $r['card_brand']) . ' ••••' . $r['card_last4'])) ?></div>
            <?php endif; ?></td>
          <td class="text-sm muted">
            <?= e(substr((string) ($r['note'] ?: $r['statement_desc']), 0, 40)) ?>
            <?php /* Which device and which Square app is often the only thing
                     that distinguishes two identical amounts years apart. */ ?>
            <?php if (($r['device_name'] ?? '') !== '' || ($r['square_product'] ?? '') !== ''): ?>
              <div class="faint text-sm"><?= e(trim(((string) $r['device_name']) . ' · ' . strtolower(str_replace('_', ' ', (string) $r['square_product'])), ' ·')) ?></div>
            <?php endif; ?>
            <?php if (($r['decline_detail'] ?? '') !== ''): ?>
              <div class="faint text-sm"><?= e(substr((string) $r['decline_detail'], 0, 40)) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge--<?= $tone[$cls] ?? 'slate' ?>"><i></i><?= e(strtolower($cls)) ?></span>
            <?php if ($r['posted_entry_id'] !== null): ?>
              <span class="badge badge--accent" title="Already posted — reverse the entry before reclassifying"><i></i>posted</span>
            <?php endif; ?>
          </td>
          <td class="right nowrap">
            <a class="btn btn--ghost btn--sm" href="<?= url('square/' . (int) $r['id']) ?>">Details</a>
            <?php if ($r['posted_entry_id'] === null): ?>
              <?php foreach (['BUSINESS' => 'Biz', 'PERSONAL' => 'Personal'] as $as => $short): ?>
                <?php if ($cls !== $as): ?>
                  <form method="post" action="<?= url('square/' . (int) $r['id'] . '/classify') ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="as" value="<?= e($as) ?>">
                    <input type="hidden" name="lens" value="<?= e($lens) ?>">
                    <input type="hidden" name="q" value="<?= e($q) ?>">
                    <input type="hidden" name="page" value="<?= (int) $page ?>">
                    <button class="btn btn--ghost btn--sm"><?= e($short) ?></button>
                  </form>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="faint text-sm">locked</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="panel__foot">
    <div class="row row--between" style="width:100%">
      <div class="text-sm muted">Page <?= (int) $page ?> of <?= (int) $pages ?></div>
      <div class="row" style="gap:6px">
        <?php if ($page > 1): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('square?' . $qs(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('square?' . $qs(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
