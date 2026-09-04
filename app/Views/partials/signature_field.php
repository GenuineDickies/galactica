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
/**
 * Signature capture field.
 *
 * Renders a trigger plus a preview of the captured mark. The drawing surface
 * itself is a full-screen sheet (see .sigsheet in app.css and the signature
 * block in app.js) — an inline pad is too small for a customer to sign
 * properly on a phone, and a cramped signature is weaker evidence.
 *
 * The captured PNG data URL lands in a hidden input, so every consumer
 * submits it exactly as before. Forms carrying data-sig-required are blocked
 * until it holds a value.
 *
 * @var string $id        unique per page — the hidden input takes "<id>_data"
 * @var string $name      form field name (usually signature_data)
 * @var string $label     field label
 * @var bool   $required  marks the label and drives the submit gate
 * @var string $hint      small print under the field
 * @var string $title     heading shown on the full-screen sheet
 * @var string $subtitle  what is being signed for — shown to the signer
 */
$id       = $id       ?? 'sig';
$name     = $name     ?? 'signature_data';
$label    = $label    ?? 'Signature';
$required = $required ?? false;
$hint     = $hint     ?? '';
$title    = $title    ?? 'Customer signature';
$subtitle = $subtitle ?? '';
?>
<div class="field">
  <?php /* The trigger button is the control; the label names it. */ ?>
  <label <?= $required ? 'class="req"' : '' ?> for="<?= e($id) ?>_open"><?= e($label) ?></label>

  <div class="sigfield" id="<?= e($id) ?>"
       data-sigfield
       data-target="<?= e($id) ?>_data"
       data-title="<?= e($title) ?>"
       data-subtitle="<?= e($subtitle) ?>">

    <button type="button" class="btn btn--block btn--lg sigfield__trigger" id="<?= e($id) ?>_open" data-sig-open
            aria-describedby="<?= e($id) ?>_hint">
      Click here to sign
    </button>

    <div class="sigfield__preview">
      <img class="sigfield__img" alt="Captured signature">
      <div class="sigfield__meta">
        <span>Signature captured</span>
        <button type="button" class="btn btn--ghost btn--sm" data-sig-clear>Clear</button>
      </div>
    </div>
  </div>

  <input type="hidden" id="<?= e($id) ?>_data" name="<?= e($name) ?>">

  <div class="hint mt2" id="<?= e($id) ?>_hint"><?= $hint !== '' ? e($hint) . ' ' : '' ?>You can draw with a finger or mouse, or type your name instead.</div>
</div>
