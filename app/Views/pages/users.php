<?php /* Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved. Proprietary; licensed, not sold. See LICENSE.txt */ ?>
<?php /**
 * User administration.
 * Full-width panel + modal, matching catalog.php. The old two-column .split
 * squeezed a seven-column table into 1.65fr and it overflowed onto the
 * neighbouring card.
 * @var array $rows  every user, inactive included, ordered by role then name
 */ ?>
<div class="panel mb4"><div class="panel__body">
  <div class="row row--between wrap">
    <div><div class="panel__title">Users</div>
      <div class="panel__sub">Who can sign in, what they can reach, and who can be handed a work order. Accounts are never deleted — deactivating keeps the person's history intact.</div></div>
    <button class="btn btn--primary" data-modal-open="newUserModal">Add user</button>
  </div>
</div></div>

<div class="panel mb4">
  <div class="panel__head"><div class="panel__title">Accounts</div>
    <div class="topbar__spacer"></div><span class="tag"><?= count($rows) ?> users</span></div>
  <div class="panel__body panel__body--flush">
    <div class="table-wrap"><table class="tbl">
      <thead><tr>
        <th>Name</th><th>Role</th><th>Email</th><th>Jobs</th><th>Status</th>
        <th style="width:230px">Password</th><th style="width:120px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $inactive = (int) $r['is_active'] === 0; ?>
        <tr style="<?= $inactive ? 'opacity:.5' : '' ?>">
          <td class="strong" style="white-space:nowrap"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td><span class="badge badge--<?= $r['role'] === 'ADMIN' ? 'accent' : ($r['role'] === 'DISPATCH' ? 'info' : 'slate') ?>"><i></i><?= e(status_label($r['role'])) ?></span>
            <?php if ((int) ($r['is_setup'] ?? 0) === 1): ?> <span class="badge badge--warn"><i></i>Setup</span><?php endif; ?></td>
          <td class="muted"><?= e($r['email']) ?></td>
          <td><?= (int) $r['can_accept_jobs'] === 1 ? '<span class="muted text-sm">Accepting</span>' : '<span class="badge badge--warn"><i></i>Not dispatchable</span>' ?></td>
          <td><?= !$inactive ? '<span class="badge badge--success"><i></i>Active</span>' : '<span class="badge badge--slate"><i></i>Inactive</span>' ?></td>
          <td><form method="post" action="<?= url('users/' . (int) $r['id'] . '/password') ?>" style="display:flex;gap:6px;align-items:center">
            <?= csrf_field() ?>
            <input class="input" name="password" type="password" minlength="8" required placeholder="New password" autocomplete="new-password" style="width:130px;padding:4px 8px;font-size:12px">
            <button class="btn btn--ghost btn--sm" type="submit">Reset</button>
          </form></td>
          <td class="right"><form method="post" action="<?= url('users/' . (int) $r['id'] . '/toggle') ?>" style="display:inline"><?= csrf_field() ?>
            <button class="btn btn--ghost btn--sm"
              <?php if (!$inactive): ?>data-confirm="Deactivate <?= e($r['email']) ?>? They can no longer sign in. The account and its history are kept."<?php endif; ?>
            ><?= $inactive ? 'Reactivate' : 'Deactivate' ?></button>
          </form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php /* Left in the panel foot rather than an .alert: `.alert strong` is
           display:block, which would break each role onto its own line. */ ?>
  <div class="panel__foot" style="display:block">
    <div class="text-sm muted" style="line-height:1.8">
      <strong>Admin</strong> — everything, including settings, catalog and voids.<br>
      <strong>Dispatch</strong> — intake, estimates, dispatch, invoicing and payments. No settings, no catalog writes, no voids.<br>
      <strong>Technician</strong> — their own assigned work orders, including invoicing and collecting payment on those jobs. No pricing history, no accounting screens.<br>
      <strong>Setup</strong> — the temporary login the installer seeds. It is deactivated automatically the moment a real admin account is created here.
    </div>
  </div>
</div>

<div class="modal-bg" id="newUserModal"><div class="modal panel">
  <div class="panel__head"><div class="panel__title">Add a user</div><div class="topbar__spacer"></div>
    <button class="btn btn--ghost btn--sm" data-modal-close type="button">Close</button></div>
  <div class="panel__body">
    <?php /* autocomplete off throughout: without it Chrome fills the signed-in
             admin's own email and password into a form that creates someone else. */ ?>
    <form method="post" action="<?= url('users') ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field"><label class="req">First name</label><input class="input" name="first_name" required autocomplete="off"></div>
        <div class="field"><label class="req">Last name</label><input class="input" name="last_name" required autocomplete="off"></div>
        <div class="field"><label class="req">Email</label><input class="input" name="email" type="email" required autocomplete="off"></div>
        <div class="field"><label>Phone</label><input class="input" name="phone" data-mask="phone" autocomplete="off"></div>
        <div class="field"><label class="req">Password</label><input class="input" name="password" type="password" minlength="8" required autocomplete="new-password">
          <div class="hint">Minimum 8 characters. Stored as a bcrypt hash.</div></div>
        <div class="field"><label>Role</label><select class="select" name="role">
          <option value="TECHNICIAN">Technician</option><option value="DISPATCH">Dispatch</option><option value="ADMIN">Admin</option></select></div>
      </div>
      <label class="checkline"><input type="checkbox" name="can_accept_jobs" value="1" checked><span>Can be dispatched work orders</span></label>
      <div class="field mt4"><label>Notes</label><textarea class="textarea" name="notes"></textarea></div>
      <button class="btn btn--primary btn--block">Create user</button>
    </form>
  </div>
</div></div>
