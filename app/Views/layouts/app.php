<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /** @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Admin') ?> · White Knight Roadside</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%232c5cff'/><text x='16' y='22' font-size='15' font-family='sans-serif' font-weight='bold' fill='white' text-anchor='middle'>WK</text></svg>">
</head>
<body>
<div class="shell">
  <?php View::partial('partials/sidebar', ['nav' => $nav ?? '']); ?>
  <div class="main">
    <header class="topbar">
      <div>
        <?php if (!empty($crumb)): ?><div class="topbar__crumb"><?= e($crumb) ?></div><?php endif; ?>
        <div class="topbar__title"><?= e($title ?? '') ?></div>
      </div>
      <div class="topbar__spacer"></div>
      <?php if (!empty($headActions)) echo $headActions; ?>
      <?php if ($u = Auth::user()): ?>
        <div class="whoami">
          <span class="whoami__dot"><?= e(strtoupper(substr($u['first_name'],0,1) . substr($u['last_name'],0,1))) ?></span>
          <span><?= e($u['first_name']) ?> · <span class="faint"><?= e(ucfirst(strtolower($u['role']))) ?></span></span>
          <form method="post" action="<?= url('logout') ?>" style="display:inline">
            <?= csrf_field() ?><button class="btn btn--ghost btn--sm">Sign out</button>
          </form>
        </div>
      <?php endif; ?>
    </header>
    <?php /* Misconfiguration is announced on EVERY page, before a promise is
             made to a customer — not discovered afterwards on a log page. Only
             staff who can act on it (or must route around it) see the banner. */ ?>
    <?php if (Auth::is('ADMIN', 'DISPATCH') && ($health = Health::all())): ?>
      <div style="padding:16px 24px 0">
        <div class="alert alert--danger mb0"><div>
          <strong>Stop — customers cannot be reached until this is fixed.</strong>
          <?php foreach ($health as $service => $issues): ?>
            <?php foreach ($issues as $h): ?>
              <div class="mt2"><span class="tag"><?= e($service) ?></span> <?= e($h['what']) ?>
                <div class="hint"><?= e($h['fix']) ?></div></div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (Auth::is('ADMIN')): ?>
            <div class="mt2"><a class="btn btn--sm" href="<?= url('settings') ?>">Open Settings</a></div>
          <?php endif; ?>
        </div></div>
      </div>
    <?php endif; ?>
    <main class="content"><?= $content ?></main>
    <footer class="text-xs faint" style="text-align:center;padding:10px 16px 16px">&copy; <?= date('Y') ?> White Knight Roadside, LLC. All Rights Reserved.</footer>
  </div>
</div>

<div class="flashwrap">
<?php foreach (flash() as $f):
  $cls = ['ok'=>'alert--ok','warn'=>'alert--warn','err'=>'alert--danger','info'=>'alert--info'][$f['type']] ?? 'alert--info'; ?>
  <div class="alert flash <?= $cls ?>"><div><?= $f['msg'] ?></div></div>
<?php endforeach; ?>
</div>

<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
