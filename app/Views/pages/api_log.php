<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php
/*
 * WHAT EACH OPERATION MEANS, IN WORDS.
 *
 * The operation column stores a terse code because it is written from a dozen
 * call sites and has to stay greppable. Nobody debugging a webhook at the
 * roadside should have to know that "webhook:orphan" means the callback was
 * genuine but named an order this system has never issued.
 *
 * A rejected callback is the case that matters most: the provider is told 403
 * and retries, and the reason exists nowhere but this row.
 */
$meaning = [
    'webhook:rejected'         => 'The signature did not verify. Either the signature key in Settings does not match the one on the subscription in the provider dashboard, or the notification URL registered there is not character-for-character the URL this install answers on — the URL is part of what gets signed. Nothing was read; the body was refused before it was parsed.',
    'webhook:not-ours'         => 'A genuine, correctly signed callback about a location that is not this business. Square delivers events for the whole merchant account, so this is expected and harmless — it is the guard doing its job, not a fault.',
    'webhook:orphan'           => 'Correctly signed and about our location, but it names an order this system has never issued. Usually a payment taken directly in the Square app rather than through an invoice here.',
    'webhook:unreadable'       => 'The body was not JSON this handler recognises. Almost always a provider test ping rather than a real event.',
    'webhook:payment'          => 'A payment was accepted and recorded against its invoice. This is the success case.',
    'webhook:refund'           => 'A refund event arrived. Refunds are noted but do not post on their own — there is no refund flow yet.',
    'webhook:inbound'          => 'An inbound text arrived from a number with no customer on file.',
    'webhook:status-noref'     => 'A delivery receipt arrived carrying no message reference, so it could not be matched to anything.',
    'webhook:status-unmatched' => 'A delivery receipt named a message this system has no record of sending.',
    'refund'                   => 'A refund this system asked the provider to make.',
    'link'                     => 'A payment link or checkout was created for a customer to pay.',
];

/* The kind of thing a row is, for the badge. Deliberately coarse: the detail
 * column carries the specifics and the tooltip carries the explanation. */
$tone = static function (array $r) use ($meaning): array {
    if ((int) $r['ok'] === 1) {
        /* Not-ours is a SUCCESS that reads like a rejection. Marking it plainly
         * "OK" green would be misleading and red would be alarming, so it gets
         * its own neutral word. */
        return $r['operation'] === 'webhook:not-ours'
            ? ['IGNORED', 'slate'] : ['OK', 'success'];
    }
    return ['FAILED', 'danger'];
};

$f = $f ?? ['service' => '', 'outcome' => '', 'q' => ''];
?>

<div class="panel mb4">
  <div class="panel__body">
    <?php if ($total === 0): ?>
      <div class="alert alert--warn mb0" role="status"><div>
        <strong>Nothing has been logged yet.</strong>
        Every call to an outside service, and every callback received from one, is written here —
        whether it succeeded, failed, or was only held because no provider is connected. An empty
        log means nothing has been attempted, not that something is broken.
      </div></div>
    <?php elseif ($failures === 0): ?>
      <div class="alert alert--ok mb0" role="status"><div>
        <strong>No failures on record.</strong>
        <?= number_format($total) ?> call<?= $total === 1 ? '' : 's' ?> logged, every one of them fine.
      </div></div>
    <?php else: ?>
      <div class="alert alert--warn mb0" role="status"><div>
        <strong><?= number_format($failures) ?> failure<?= $failures === 1 ? '' : 's' ?></strong>
        out of <?= number_format($total) ?> logged call<?= $total === 1 ? '' : 's' ?>.
        A failure here is not always a problem — a rejected callback from another business's
        location is recorded and ignored on purpose. Hover any badge for what the row means.
      </div></div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div>
      <div class="panel__title">Integration log</div>
      <div class="panel__sub">Every outside call and every callback, in the order they happened. Newest first.</div>
    </div>
    <div class="topbar__spacer"></div>
    <span class="tag"><?= count($rows) ?></span>
  </div>

  <div class="panel__body">
    <form method="get" action="<?= url('api-log') ?>" class="row wrap">
      <select class="select" name="service" aria-label="Service" style="max-width:170px">
        <option value="">All services</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= e($s['service']) ?>" <?= $f['service'] === $s['service'] ? 'selected' : '' ?>>
            <?= e($s['service']) ?> (<?= (int) $s['n'] ?><?= (int) $s['bad'] ? ', ' . (int) $s['bad'] . ' failed' : '' ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <select class="select" name="outcome" aria-label="Outcome" style="max-width:150px">
        <option value="">Any outcome</option>
        <option value="fail" <?= $f['outcome'] === 'fail' ? 'selected' : '' ?>>Failures only</option>
        <option value="ok"   <?= $f['outcome'] === 'ok'   ? 'selected' : '' ?>>Successes only</option>
      </select>
      <input class="input" name="q" aria-label="Search the log" value="<?= e($f['q']) ?>" placeholder="Reference, operation or reason" style="min-width:240px;flex:1">
      <button class="btn btn--sm">Filter</button>
      <?php if ($f['service'] !== '' || $f['outcome'] !== '' || $f['q'] !== ''): ?>
        <a class="btn btn--sm btn--ghost" href="<?= url('api-log') ?>">Clear</a>
      <?php endif; ?>
    </form>
    <div class="hint">
      Showing at most <?= (int) $per_page ?> rows. Nothing here is ever edited or deleted —
      this is the audit trail for anything that left or entered the system.
    </div>
  </div>

  <div class="panel__body panel__body--flush">
<?php if (!$rows): ?>
  <div class="empty"><div class="empty__icon" aria-hidden="true">◎</div><div class="empty__title">Nothing matches</div>
    <div class="empty__body">No logged call fits those filters. Clear them to see everything.</div></div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr>
      <th scope="col">Outcome</th><th scope="col">Service</th><th scope="col">Driver</th><th scope="col">Operation</th>
      <th scope="col">Reference</th><th scope="col">What happened</th><th class="right" scope="col">When</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        [$word, $class] = $tone($r);
        $why = $meaning[$r['operation']] ?? '';
      ?>
      <tr>
        <?php /* Hand-built rather than badge(), which maps a document STATUS
                 through STATUS_TONE — these are outcomes, not statuses, and
                 adding them there would pollute a map the whole chain reads.
                 The <i> is the dot every other badge carries. */ ?>
        <td><span class="badge badge--<?= e($class) ?>" title="<?= e($why) ?>"><i></i><?= e($word) ?></span></td>
        <td class="strong"><?= e($r['service']) ?></td>
        <td class="muted text-sm"><?= e($r['driver']) ?></td>
        <td class="text-sm">
          <span class="docno" title="<?= e($why) ?>"><?= e($r['operation']) ?></span>
          <?php if ($why !== ''): ?>
            <div class="text-xs faint mt2" style="max-width:340px"><?= e($why) ?></div>
          <?php endif; ?>
        </td>
        <td class="docno faint text-sm" style="word-break:break-all;max-width:200px"><?= e($r['reference'] ?: '—') ?></td>
        <?php /* The detail is the whole point of the row when something failed,
                 so it is never truncated — a signature failure that says only
                 "Signature did not verify" and hides the origin is no better
                 than no log at all. */ ?>
        <td class="<?= (int) $r['ok'] === 1 ? 'muted' : '' ?>"
            style="max-width:420px;word-break:break-word<?= (int) $r['ok'] === 1 ? '' : ';color:var(--danger)' ?>"><?= e($r['detail'] ?: '—') ?></td>
        <td class="right muted text-sm nowrap" title="<?= e(fdatetime($r['created_at'])) ?>"><?= e(ago($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
  </div>
</div>
