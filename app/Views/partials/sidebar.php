<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
$role = Auth::role();
$icon = function (string $n): string {
    $p = [
      'dash'     => '<path d="M3 3h7v8H3zM14 3h7v5h-7zM14 11h7v10h-7zM3 14h7v7H3z"/>',
      'plus'     => '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>',
      'ticket'   => '<path d="M4 6h16v4a2 2 0 000 4v4H4v-4a2 2 0 000-4z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'doc'      => '<path d="M6 3h8l4 4v14H6z" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M14 3v4h4" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'wrench'   => '<path d="M20 5a5 5 0 01-6.6 6.6L6 19l-2-2 7.4-7.4A5 5 0 0118 3l-2.6 2.6 2 2z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'invoice'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M9 8h6M9 12h6" stroke="currentColor" stroke-width="1.7"/>',
      'card'     => '<rect x="3" y="6" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18" stroke="currentColor" stroke-width="1.7"/>',
      'users'    => '<circle cx="9" cy="8" r="3.2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 20a6 6 0 0112 0M16 11a3 3 0 100-6M17.5 20a5.5 5.5 0 00-2-4" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'car'      => '<path d="M5 16h14M6.5 16l1.2-5.2A2 2 0 019.6 9h4.8a2 2 0 011.9 1.4L17.5 16M4 16h16v3H4z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'catalog'  => '<path d="M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8 5v14M4 10h4M4 14h4" stroke="currentColor" stroke-width="1.7"/>',
      'receipt'  => '<path d="M5 3h14v18l-2.3-1.6L14.4 21l-2.4-1.6L9.6 21l-2.3-1.6L5 21z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
      'chat'     => '<path d="M4 5h16v11H9l-5 4z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
      'gear'     => '<circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2" stroke="currentColor" stroke-width="1.7"/>',
      'book'     => '<path d="M4 4.5A2.5 2.5 0 016.5 2H20v16H6.5A2.5 2.5 0 004 20.5z" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M4 20.5A2.5 2.5 0 016.5 18H20v4H6.5A2.5 2.5 0 014 20.5z" fill="none" stroke="currentColor" stroke-width="1.7"/>',
    ][$n] ?? '';
    return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $p . '</svg>';
};

$counts = [
  'sr'  => (int) Db::val("SELECT COUNT(*) FROM service_requests WHERE status = 'PENDING'"),
  'est' => (int) Db::val("SELECT COUNT(*) FROM estimates WHERE authorized_at IS NULL AND status != 'DECLINED'"),
  'wo'  => (int) Db::val("SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('COMPLETED','CANCELLED','NO_SHOW')"),
  'inv' => (int) Db::val("SELECT COUNT(*) FROM invoices WHERE status IN ('ISSUED','PARTIAL')"),
  /* Wrapped: core_records postdates most installs, and a sidebar that throws
   * on a missing table takes every page down with it. */
  'square' => (static function (): int {
      try {
          return (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE classification = 'UNREVIEWED'", [], 0);
      } catch (Throwable) { return 0; }
  })(),
  'cores' => (static function (): int {
      try {
          return (int) Db::val("SELECT COUNT(*) FROM core_records WHERE status IN ('CHARGED','COLLECTED','RETURNED','CREDITED')", [], 0);
      } catch (Throwable) { return 0; }
  })(),
];

$groups = [
  'The chain' => [
    ['dashboard',        'Dashboard',        'dash',    null],
    ['service-requests', 'Service Requests', 'ticket',  $counts['sr']],
    ['estimates',        'Estimates',        'doc',     $counts['est']],
    ['work-orders',      'Work Orders',      'wrench',  $counts['wo']],
    ['invoices',         'Invoices',         'invoice', $counts['inv']],
    ['payments',         'Payments',         'card',    null],
  ],
  'Money' => [
    ['expenses',         'Expenses',         'receipt', null],
    /* Open cores are money held on somebody else's behalf, and they are
     * invisible unless counted — the badge is the whole point of putting this
     * in the sidebar rather than burying it in a report. */
    ['cores',            'Core Deposits',    'receipt', $counts['cores'] ?? null],
    ['reports',          'Reports',          'chart',   null],
    /* Separate from Reports on purpose: that page reports on OPERATIONS from
     * the documents, this one reports what the LEDGER says. Where the two
     * disagree, the disagreement is the useful part. */
    ['books',            'The Books',        'book',    null],
    /* Badged with what still needs a judgement, because an unreviewed mirror
     * silently never reaches the ledger and nothing else would say so. */
    ['square',           'Square History',   'card',    $counts['square'] ?? null],
  ],
  'Records' => [
    ['customers',        'Customers',        'users',   null],
    ['vehicles',         'Vehicles',         'car',     null],
    ['catalog',          'Products & Services', 'catalog', null],
    ['messages',         'Messages',         'chat',    null],
  ],
];
if ($role === 'ADMIN') {
  $groups['Admin'] = [
    ['settings', 'Settings', 'gear', null],
    ['telnyx-check', 'Telnyx check', 'chat', null],
    ['api-log',  'Integration log', 'chart', null],
    ['markup',   'Markup matrix', 'card', null],
    ['accounts', 'Accounts', 'chart', null],
    ['users',    'Users', 'users', null],
  ];
}
if ($role === 'TECHNICIAN') {
  $groups = ['Field' => [
      ['dashboard',   'Dashboard',       'dash',    null],
      ['work-orders', 'My Work Orders',  'wrench',  $counts['wo']],
      ['vehicles',    'Vehicles',        'car',     null],
      ['catalog',     'Products & Services', 'catalog', null],
  ]];
}
/* Last group for every role, technicians included — the section on signatures
 * and close-out gates is written for the person holding the phone. */
$groups['Help'] = [
    ['manual', 'User manual', 'book', null],
];
$co = App::config('company');
?>
<aside class="sidebar">
<nav aria-label="Primary">
  <div class="brand">
    <div class="brand__mark">WK</div>
    <div>
      <div class="brand__name">White Knight</div>
      <div class="brand__sub">Roadside · Admin</div>
    </div>
  </div>

  <?php if ($role !== 'TECHNICIAN'): ?>
    <button class="btn btn--primary btn--block" data-url="<?= url('service-requests/new') ?>">
      <?= $icon('plus') ?> New Service Request
    </button>
  <?php endif; ?>

  <?php foreach ($groups as $label => $items): ?>
    <div class="navgroup">
      <div class="navgroup__label"><?= e($label) ?></div>
      <?php foreach ($items as [$slug, $text, $ic, $count]): ?>
        <button class="nav-btn <?= ($nav ?? '') === $slug ? 'is-active' : '' ?>" data-url="<?= url($slug) ?>">
          <?= $icon($ic) ?><span><?= e($text) ?></span>
          <?php if ($count): ?><span class="nav-btn__count"><?= (int) $count ?></span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

</nav>
  <div class="navgroup" style="margin-top:auto">
    <div class="text-xs faint" style="padding:12px 10px 0;line-height:1.6">
      <?= e($co['tagline']) ?><br>
      <?= e($co['phone']) ?><br>
      v<?= e(App::config('app')['version']) ?>
    </div>
  </div>
</aside>
