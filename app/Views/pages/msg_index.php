<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php
$live = Integrations::sms()->isLive();

/* One description per status the table can show — all of them, so nobody has
 * to infer what a badge means from its colour. Used for the legend and as the
 * tooltip on every row's badge. */
$statusHelp = [
    'QUEUED'      => $live
        ? 'Composed and waiting to be handed to the carrier.'
        : 'Recorded only. Texting is not connected — the customer has NOT received anything. Reach them by phone.',
    'SENT'        => 'Handed to the carrier network. A delivery receipt normally follows within seconds; give it a few minutes on slow carriers.',
    'DELIVERED'   => 'The carrier confirmed it reached the customer\'s handset. The time shown is theirs, not ours.',
    'UNCONFIRMED' => 'The carrier never returned a receipt. Not a failure — some networks simply don\'t report, and the message almost certainly arrived.',
    'FAILED'      => 'The carrier rejected it. The reason on the row says why — a dead number and a spam block need different responses.',
    'RECEIVED'    => 'Inbound — the customer texted us. STOP and START are applied to consent automatically.',
    'BLOCKED'     => 'Never sent, on purpose. No SMS consent on file, or the customer texted STOP. The reason is on the row.',
];
?>

<div class="split mb4">
  <div class="panel">
    <div class="panel__body">
      <?php if ($live): ?>
        <div class="alert alert--ok mb0"><div>
          <strong>Sending live through <?= e(Integrations::sms()->driverName()) ?>.</strong>
          Delivery receipts update these rows as they arrive, and inbound replies are handled automatically —
          STOP revokes consent the moment it lands.
        </div></div>
      <?php else: ?>
        <div class="alert alert--warn mb0"><div>
          <strong>Texting is not connected.</strong> The rows below are a record of what the system
          <em>would</em> have texted — none of it has reached a customer, and none of it will until
          Telnyx is connected in Settings. Until then, work by phone: nobody hand-sends these from a
          personal cell. Consent is enforced either way.
        </div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel__head">
      <div>
        <div class="panel__title">Record a verbal consent change</div>
        <div class="panel__sub">A customer told you to stop (or start) in a way the carrier cannot see — on a call, at the counter.</div>
      </div>
    </div>
    <div class="panel__body">
      <form method="post" action="<?= url('messages/consent') ?>" class="row wrap">
        <?= csrf_field() ?>
        <input class="input" name="phone" data-mask="phone" placeholder="(503) 555-0123" style="max-width:170px" required>
        <select class="select" name="consent_action" style="max-width:150px">
          <option value="stop">Opt OUT — stop</option>
          <option value="start">Opt in — start</option>
        </select>
        <input class="input" name="note" placeholder='How you learned it — "said stop texting on today&#039;s call"' style="min-width:260px;flex:1" required>
        <button class="btn btn--sm">Record</button>
      </form>
      <div class="hint">
        Recorded as a VERBAL request — as it happened, never as a pretend text message — with your name
        and your note as the evidence, then enforced identically to a texted
        <span class="docno">STOP</span>: consent cleared, do-not-contact set, audited on the customer.
        Texted keywords need no help here; the carrier callback handles those itself.
      </div>
    </div>
  </div>
</div>

<?php if (!empty($receiptHealth)): ?>
<?php /* Anything that can silently stop receipts is said HERE, where the
         person waiting on a "Sent" badge is actually looking — not in a log
         they would have to know to go and read. */ ?>
<div class="panel mb4"><div class="panel__body">
  <div class="alert alert--warn mb0"><div>
    <strong>Delivery receipts are not fully working.</strong>
    <?php foreach ($receiptHealth as $h): ?>
      <div class="mt2"><?= e($h['what']) ?>
        <div class="hint"><?= e($h['fix']) ?></div></div>
    <?php endforeach; ?>
  </div></div>
</div></div>
<?php endif; ?>

<div class="panel">
  <div class="panel__head"><div class="panel__title">Message log</div><div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?></span></div>
  <div class="panel__body panel__body--flush">
    <?php /* Every status the table can show, spelled out. */ ?>
    <div class="row wrap" style="gap:10px 18px; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,.06)">
      <?php foreach ($statusHelp as $s => $help): ?>
        <span class="text-sm nowrap" title="<?= e($help) ?>"><?= badge($s) ?></span>
      <?php endforeach; ?>
      <span class="hint" style="margin:0">hover a badge for what it means</span>
    </div>
<?php if (!$rows): ?>
  <div class="empty"><div class="empty__icon">✉</div><div class="empty__title">No messages</div>
    <div class="empty__body">Messages are queued automatically at dispatch, arrival, estimate, invoice and receipt.</div></div>
<?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Status</th><th></th><th>Customer</th><th>Job</th><th>Template</th><th>Message</th><th class="right">When</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php /* Message statuses now carry their own tones in STATUS_TONE, so
                 this no longer has to borrow a document status to get a colour
                 — DELIVERED used to render as "Paid", which read very oddly.
                 Every badge carries its meaning as a tooltip; the second line
                 carries this row's specifics. A SENT that has gone over an
                 hour without a receipt is flagged inline, because the person
                 wondering about it is looking at this row, not at a log. */ ?>
        <?php $stuck = $live && $r['status'] === 'SENT'
                  && (time() - strtotime((string) ($r['sent_at'] ?: $r['created_at']))) > 3900; ?>
        <td><span title="<?= e($statusHelp[$r['status']] ?? '') ?>"><?= badge((string) $r['status']) ?></span>
          <?php if ($r['blocked_reason']): ?>
            <div class="text-xs faint mt2"><?= e($r['blocked_reason']) ?></div>
          <?php elseif ($r['status'] === 'DELIVERED' && !empty($r['delivered_at'])): ?>
            <div class="text-xs faint mt2" title="Confirmed by the carrier">
              handset <?= e(fdate($r['delivered_at'], 'g:i A')) ?></div>
          <?php /*
             * Any row carrying a reason shows it — not only FAILED ones.
             * Sms::record() writes a send the carrier REJECTED as QUEUED with
             * failure_reason set, because the row is held rather than lost.
             * Matching only on FAILED meant that reason was recorded and then
             * never displayed: the row sat there looking merely pending, while
             * the database knew exactly why it had not gone. A reason that is
             * stored and not shown is worse than one never captured, because
             * it stops anybody going to look for it.
             */ ?>
          <?php elseif (!empty($r['failure_reason'])): ?>
            <div class="text-xs mt2" style="color:var(--danger)"><?= e($r['failure_reason']) ?></div>
          <?php elseif ($r['status'] === 'UNCONFIRMED'): ?>
            <div class="text-xs faint mt2" title="<?= e($statusHelp['UNCONFIRMED']) ?>">
              no carrier receipt</div>
          <?php elseif ($stuck): ?>
            <div class="text-xs mt2" style="color:var(--warn)"
                 title="A receipt normally arrives within seconds. Over an hour with none usually means callbacks are not reaching this install — if so, the banner above names the cause.">
              no receipt in over an hour</div>
          <?php endif; ?>
          <?php /*
             * The provider's own id for this message, shown only where a
             * receipt is outstanding. It is the one value that makes the
             * problem answerable: with it you can ask Telnyx directly what
             * became of that message, rather than inferring from silence.
             * Not shown on settled rows, where it would be clutter.
             */ ?>
          <?php if (!empty($r['provider_ref']) && ($stuck || $r['status'] === 'SENT')): ?>
            <div class="text-xs faint mt2 docno" title="Telnyx's id for this message — use it to look the message up at the provider">
              <?= e($r['provider_ref']) ?></div>
          <?php endif; ?></td>
        <td class="faint"><?= $r['direction'] === 'IN' ? '←' : '→' ?></td>
        <td class="strong"><?= e(customer_name($r, true)) ?: '<span class="muted">Unknown</span>' ?>
          <div class="text-sm faint"><?= e(phone_display($r['phone_e164'])) ?></div></td>
        <td class="docno faint"><?= e($r['sr_no'] ?? '—') ?></td>
        <td class="muted text-sm"><?= e($r['template']) ?></td>
        <td class="muted" style="max-width:400px;word-break:break-word"><?= e($r['body']) ?></td>
        <td class="right muted text-sm nowrap"><?= e(ago($r['created_at'])) ?></td>
        <?php /* No "Mark sent" button, and no route behind one either. A text
                 either goes through the connected carrier or it does not go.
                 A message sent from somebody's private phone is outside this
                 application's scope — the system neither facilitates nor
                 records it, because a "sent" the carrier never saw would be a
                 fabricated delivery record. The working fallback is the phone
                 call, and a phone call is not a text to be marked sent. */ ?>
        <td class="right"></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</div></div>
