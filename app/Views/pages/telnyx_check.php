<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php
/**
 * Read-only. Every value on this page came either from this install's settings
 * or from Telnyx a moment ago; nothing here writes anywhere.
 */
$mark = static function (string $level): string {
    return match ($level) {
        'ok'   => '<span class="badge badge--success">OK</span>',
        'warn' => '<span class="badge badge--warn">Check</span>',
        default => '<span class="badge badge--danger">Problem</span>',
    };
};
$i = $audit['install'];
?>
<div style="max-width:860px">

<?php if ($audit['problems'] > 0): ?>
  <div class="panel mb4">
    <div class="panel__body">
      <span class="badge badge--danger"><?= (int) $audit['problems'] ?></span>
      problem<?= $audit['problems'] === 1 ? '' : 's' ?> below would stop a delivery receipt being recorded.
    </div>
  </div>
<?php elseif ($audit['error'] === ''): ?>
  <div class="panel mb4">
    <div class="panel__body">
      <span class="badge badge--success">OK</span>
      Nothing found that would stop a delivery receipt.
    </div>
  </div>
<?php endif; ?>

<div class="panel mb4">
  <div class="panel__head"><div>
    <div class="panel__title">This install</div>
    <div class="panel__sub">Where this application answers, and what it is holding. These come from
      the settings of the server serving this page.</div>
  </div></div>
  <div class="panel__body">
    <table class="table">
      <tr><td>SMS driver</td><td><span class="docno"><?= e($i['driver']) ?></span></td></tr>
      <tr><td>Base URL</td><td><span class="docno"><?= e($i['base_url'] ?: '(none)') ?></span></td></tr>
      <tr><td>Callbacks answered at</td><td><span class="docno"><?= e($i['expected_hook']) ?></span></td></tr>
      <tr><td>Messaging profile id</td><td>
        <?= $i['profile_id'] !== '' ? '<span class="docno">' . e($i['profile_id']) . '</span>' : '<em>none configured</em>' ?>
      </td></tr>
      <tr><td>Signing public key</td><td>
        <?= $i['has_public_key'] ? $mark('ok') . ' configured'
            : $mark('bad') . ' missing — every callback is refused before it is read' ?>
      </td></tr>
      <tr><td>sodium extension</td><td>
        <?= $i['has_sodium'] ? $mark('ok') . ' present'
            : $mark('bad') . ' absent — no callback can ever be verified' ?>
      </td></tr>
    </table>
  </div>
</div>

<div class="panel mb4">
  <div class="panel__head"><div>
    <div class="panel__title">Telnyx says</div>
    <div class="panel__sub">Asked live, just now. Only webhook API version 2 is signed — versions 1 and
      2010-04-01 send nothing this application can verify, so their callbacks are refused.</div>
  </div></div>
  <div class="panel__body">

  <?php if ($audit['error'] !== ''): ?>
    <p><?= $mark('bad') ?> <?= e($audit['error']) ?></p>
  <?php endif; ?>

  <?php foreach ($audit['profiles'] as $p): ?>
    <div class="mb4">
      <div>
        <strong><?= e($p['name']) ?></strong>
        <span class="docno"><?= e($p['id']) ?></span>
        <?php if ($p['is_ours']): ?><em>— the profile this install sends through</em><?php endif; ?>
      </div>
      <table class="table">
        <?php foreach ($p['findings'] as $f): ?>
          <tr>
            <td style="width:6rem"><?= $mark($f['level']) ?></td>
            <td>
              <?= e($f['text']) ?>
              <?php if (($f['fix'] ?? '') !== ''): ?>
                <div class="hint"><?= e($f['fix']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>

  </div>
</div>

</div>
