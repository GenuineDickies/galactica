<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<div class="panel"><div class="empty">
  <div class="empty__icon">⛔</div>
  <div class="empty__title">Not permitted for your role</div>
  <div class="empty__body">You're signed in as <strong><?= e(ucfirst(strtolower(Auth::role()))) ?></strong>. This area is restricted. Ask an admin if you need access.</div>
  <button class="btn btn--primary" data-url="<?= url('dashboard') ?>">Back to dashboard</button>
</div></div>
