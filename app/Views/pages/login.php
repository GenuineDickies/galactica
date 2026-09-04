<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · White Knight Roadside</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head><body>
<div class="auth">
  <div class="auth__card">
    <div class="auth__logo">
      <div class="brand__mark" style="width:44px;height:44px;border-radius:13px;font-size:15px">WK</div>
      <div>
        <h1 style="font-size:17px;font-weight:730">White Knight Roadside</h1>
        <div class="brand__sub">Admin · We Answer the Call</div>
      </div>
    </div>

    <?php foreach (flash() as $f): ?>
      <div class="alert <?= $f['type'] === 'err' ? 'alert--danger' : 'alert--ok' ?>" role="status"><div><?= $f['msg'] ?></div></div>
    <?php endforeach; ?>

    <div class="panel">
      <div class="panel__body">
        <form method="post" action="<?= url('login') ?>" class="stack">
          <?= csrf_field() ?>
          <div class="field mb0">
            <label class="req" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required autofocus autocomplete="username" placeholder="you@wkrllc.com">
          </div>
          <div class="field mb0">
            <label class="req" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
          </div>
          <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
      </div>
    </div>
    <div class="text-xs faint" style="text-align:center;margin-top:14px">&copy; <?= date('Y') ?> White Knight Roadside, LLC. All Rights Reserved.</div>
  </div>
</div>
</body></html>
