/*!
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 * Proprietary and confidential. Licensed, not sold. See LICENSE.txt.
 * Unauthorized copying, modification, distribution, or use is strictly
 * prohibited (17 U.S.C. Sections 501-505). Licensing: licensing@wkrllc.com
 */
/* White Knight Roadside — Admin. Vanilla JS. No libraries, no build step. */
(function () {
  'use strict';

  /* ================================================================== *
   *  In-place navigation.
   *
   *  The nav is buttons, not links, and clicking one used to do a full
   *  window.location load — which repaints the whole page, sidebar and
   *  all, so the shell visibly flickers. Instead we fetch the target,
   *  lift out just its <main> region, and swap that in. The sidebar DOM
   *  is never touched, so it — and its scroll position — stay put.
   *
   *  This is a progressive enhancement: anything it can't handle (a
   *  cross-origin URL, a bare print page with no .main, a failed fetch)
   *  falls straight back to a normal browser navigation, so the app
   *  works identically with JS disabled.
   * ================================================================== */

  var pending = null;               // AbortController for an in-flight fetch
  var bar = null;                   // top progress bar element

  function progress(state) {
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'pjax-bar';
      document.body.appendChild(bar);
    }
    if (state === 'start') { bar.style.opacity = '1'; bar.style.width = '35%'; }
    else if (state === 'grow') { bar.style.width = '75%'; }
    else { bar.style.width = '100%'; setTimeout(function () { bar.style.opacity = '0'; bar.style.width = '0'; }, 220); }
  }

  function sameOrigin(url) {
    try { return new URL(url, location.href).origin === location.origin; }
    catch (e) { return false; }
  }

  /* Update the existing sidebar in place: active state and the live
     counts, read from the freshly fetched document — without replacing
     the sidebar node, so there is no flash. */
  function syncSidebar(doc) {
    var incoming = doc.querySelector('.sidebar');
    if (!incoming) return;
    var current = {};
    document.querySelectorAll('.sidebar .nav-btn').forEach(function (b) {
      current[b.getAttribute('data-url')] = b;
    });
    incoming.querySelectorAll('.nav-btn').forEach(function (nb) {
      var b = current[nb.getAttribute('data-url')];
      if (!b) return;
      b.classList.toggle('is-active', nb.classList.contains('is-active'));
      var nCount = nb.querySelector('.nav-btn__count');
      var cCount = b.querySelector('.nav-btn__count');
      if (nCount && cCount) cCount.textContent = nCount.textContent;
      else if (nCount) b.appendChild(nCount.cloneNode(true));
      else if (cCount) cCount.remove();
    });
  }

  function navigate(url, push) {
    if (!sameOrigin(url)) { window.location.href = url; return; }
    if (pending) pending.abort();
    pending = new AbortController();

    document.body.classList.add('is-loading');
    progress('start');

    fetch(url, {
      headers: { 'X-Requested-With': 'pjax' },
      credentials: 'same-origin',
      signal: pending.signal,
      redirect: 'follow'
    })
      .then(function (res) {
        // A login redirect or anything non-HTML: hand off to the browser.
        if (!res.ok || (res.headers.get('content-type') || '').indexOf('text/html') === -1) {
          throw 'fallback';
        }
        progress('grow');
        return res.text().then(function (html) { return { html: html, finalUrl: res.url || url }; });
      })
      .then(function (r) {
        var doc = new DOMParser().parseFromString(r.html, 'text/html');
        var incoming = doc.querySelector('.main');
        var here = document.querySelector('.main');
        // Bare pages (print views, checkout) have no .main — full load.
        if (!incoming || !here) throw 'fallback';

        here.innerHTML = incoming.innerHTML;
        document.title = doc.title || document.title;
        syncSidebar(doc);
        initPage(here);

        if (push) history.pushState({ pjax: true }, '', r.finalUrl);
        window.scrollTo(0, 0);
        progress('done');
        document.body.classList.remove('is-loading');
        pending = null;
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;   // superseded by a newer click
        window.location.href = url;                     // graceful fallback
      });
  }

  /* ---- Button navigation (nav is buttons, not links) ---------------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-url]');
    if (btn) { ev.preventDefault(); navigate(btn.getAttribute('data-url'), true); return; }

    var row = ev.target.closest('tr[data-href]');
    if (row && !ev.target.closest('a,button,input,label')) {
      navigate(row.getAttribute('data-href'), true);
      return;
    }

    var open = ev.target.closest('[data-modal-open]');
    if (open) {
      ev.preventDefault();
      var m = document.getElementById(open.getAttribute('data-modal-open'));
      if (m) {
        m.classList.add('is-open');
        /* A modal the device is handed over for (signatures) hides every
         * .internal element behind it — costs, profit, margins — until it
         * closes. The customer sees prices and totals, never our numbers. */
        if (m.hasAttribute('data-customer-facing')) { document.body.classList.add('is-customer'); }
        var f = m.querySelector('input,select,textarea'); if (f) f.focus();
      }
      return;
    }
    if (ev.target.closest('[data-modal-close]') || ev.target.classList.contains('modal-bg')) {
      var bg = ev.target.closest('.modal-bg');
      if (bg) { bg.classList.remove('is-open'); customerModeSync(); }
    }

    /* Markup matrix: add / remove a tier row. */
    var add = ev.target.closest('[data-add-tier]');
    if (add) {
      var tpl = document.getElementById('tierRowTemplate');
      var tbody = document.querySelector('#tierTable tbody');
      if (tpl && tbody) { tbody.appendChild(tpl.content.cloneNode(true)); }
      return;
    }
    var rm = ev.target.closest('[data-remove-tier]');
    if (rm) {
      var rows = document.querySelectorAll('#tierTable [data-tier-row]');
      var row = rm.closest('[data-tier-row]');
      if (row && rows.length > 1) { row.remove(); }
      return;
    }
  });

  /* Back / forward buttons re-fetch the same way, without pushing. */
  window.addEventListener('popstate', function () { navigate(location.href, false); });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      document.querySelectorAll('.modal-bg.is-open').forEach(function (m) { m.classList.remove('is-open'); });
      customerModeSync();
    }
  });

  /* ================================================================== *
   *  Full-screen signature sheet.
   *
   *  One element, built on first use and reused by every signature field
   *  on every page — so it survives PJAX swaps and never accumulates
   *  duplicates. It deliberately sits above .modal-bg: two of the three
   *  signature points are themselves inside a modal.
   *
   *  The canvas is sized in device pixels against its own box, and resized
   *  on rotation, because a phone handed to a customer in the field will be
   *  turned landscape to sign.
   * ================================================================== */
  var SigSheet = (function () {
    var el, cv, ctx, rule, titleEl, subEl;
    var drawing = false, dirty = false, onApply = null, last = null;

    function build() {
      el = document.createElement('div');
      el.className = 'sigsheet';
      el.innerHTML =
        '<div class="sigsheet__head">' +
          '<div><div class="sigsheet__title"></div><div class="sigsheet__sub"></div></div>' +
          '<div class="topbar__spacer"></div>' +
        '</div>' +
        '<div class="sigsheet__pad">' +
          '<canvas class="sigsheet__canvas"></canvas>' +
          '<div class="sigsheet__rule"></div>' +
        '</div>' +
        '<div class="sigsheet__foot">' +
          '<button type="button" class="btn btn--ghost" data-sheet-cancel>Cancel</button>' +
          '<button type="button" class="btn btn--ghost" data-sheet-clear>Clear</button>' +
          '<button type="button" class="btn btn--primary" data-sheet-apply>Apply signature</button>' +
        '</div>';
      document.body.appendChild(el);

      cv      = el.querySelector('.sigsheet__canvas');
      rule    = el.querySelector('.sigsheet__rule');
      titleEl = el.querySelector('.sigsheet__title');
      subEl   = el.querySelector('.sigsheet__sub');

      el.querySelector('[data-sheet-cancel]').addEventListener('click', close);
      el.querySelector('[data-sheet-clear]').addEventListener('click', wipe);
      el.querySelector('[data-sheet-apply]').addEventListener('click', function () {
        // Applying an untouched sheet clears the signature rather than
        // storing a blank PNG that would look like a real one.
        if (onApply) { onApply(dirty ? cv.toDataURL('image/png') : ''); }
        close();
      });

      ['mousedown', 'touchstart'].forEach(function (e) { cv.addEventListener(e, start, { passive: false }); });
      ['mousemove', 'touchmove'].forEach(function (e) { cv.addEventListener(e, move,  { passive: false }); });
      ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach(function (e) { cv.addEventListener(e, end); });

      window.addEventListener('resize', function () { if (el.classList.contains('is-open')) { resize(); } });
    }

    /* Re-sizing clears the bitmap, so the strokes so far are carried over
       as an image. Without this, rotating the phone wipes the signature. */
    function resize() {
      var snapshot = dirty ? cv.toDataURL('image/png') : null;
      var r   = cv.getBoundingClientRect();
      var dpr = window.devicePixelRatio || 1;
      cv.width  = Math.max(1, Math.round(r.width  * dpr));
      cv.height = Math.max(1, Math.round(r.height * dpr));
      ctx = cv.getContext('2d');
      ctx.scale(dpr, dpr);
      ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      ctx.strokeStyle = '#e8eef8';
      if (snapshot) { paint(snapshot, r.width, r.height); }
    }

    function paint(src, w, h) {
      var im = new Image();
      im.onload = function () { ctx.drawImage(im, 0, 0, w, h); };
      im.src = src;
    }

    function pt(ev) {
      var r = cv.getBoundingClientRect();
      var t = ev.touches ? ev.touches[0] : ev;
      return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function start(ev) {
      ev.preventDefault();
      drawing = true;
      if (!dirty) { dirty = true; el.classList.add('is-dirty'); }
      var p = pt(ev); ctx.beginPath(); ctx.moveTo(p.x, p.y);
      // A tap with no drag should still leave a mark.
      ctx.lineTo(p.x + 0.1, p.y); ctx.stroke();
    }
    function move(ev) { if (!drawing) { return; } ev.preventDefault(); var p = pt(ev); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function end()    { drawing = false; }

    function wipe() {
      if (!ctx) { return; }
      ctx.clearRect(0, 0, cv.width, cv.height);
      dirty = false; el.classList.remove('is-dirty');
    }

    function close() {
      if (!el) { return; }
      el.classList.remove('is-open');
      document.body.style.overflow = last || '';
      onApply = null;
    }

    function open(opts) {
      if (!el) { build(); }
      onApply = opts.onApply || null;
      titleEl.textContent = opts.title || 'Customer signature';
      subEl.textContent   = opts.subtitle || '';
      subEl.style.display = opts.subtitle ? '' : 'none';

      last = document.body.style.overflow;
      document.body.style.overflow = 'hidden';   // no scrolling under the sheet
      el.classList.add('is-open');

      wipe();
      resize();
      // Re-signing starts from the mark already captured, so a customer can
      // touch it up rather than redo it.
      if (opts.existing) {
        var r = cv.getBoundingClientRect();
        dirty = true; el.classList.add('is-dirty');
        paint(opts.existing, r.width, r.height);
      }
    }

    function isOpen() { return !!el && el.classList.contains('is-open'); }

    return { open: open, close: close, isOpen: isOpen };
  })();

  /* Escape closes the signature sheet first — it may be layered over a
     modal, and closing both at once would lose the signature silently. */
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && SigSheet.isOpen()) {
      ev.stopImmediatePropagation();
      SigSheet.close();
    }
  }, true);

  /* ================================================================== *
   *  Per-page setup.
   *
   *  Everything below binds to elements inside the swapped-in region, so
   *  it must run again after each navigation — not just at first load.
   *  initPage(root) is called once on load and once per swap; the
   *  delegated handlers above are bound once and are not repeated here.
   * ================================================================== */
  function initPage(root) {
    root = root || document;
    var $  = function (s) { return root.querySelector(s); };
    var $all = function (s) { return root.querySelectorAll(s); };

  /* ---- Phone mask: (xxx) xxx-xxxx, brackets/dash immovable ---------- */
  function maskPhone(v) {
    var d = (v || '').replace(/\D/g, '').slice(0, 10);
    if (!d.length) return '';
    if (d.length < 4) return '(' + d;
    if (d.length < 7) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
    return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
  }
  $all('input[data-mask="phone"]').forEach(function (el) {
    el.value = maskPhone(el.value);
    el.addEventListener('input', function () { el.value = maskPhone(el.value); });
    el.addEventListener('focus', function () { if (!el.value) el.value = '('; });
    el.addEventListener('blur', function () { if (el.value.replace(/\D/g, '').length < 10) { if (el.value === '(') el.value = ''; } });
  });

  /* ---- VIN: uppercase, strip I/O/Q, live ISO-3779 check ------------- */
  var VIN_TR = { A:1,B:2,C:3,D:4,E:5,F:6,G:7,H:8,J:1,K:2,L:3,M:4,N:5,P:7,R:9,S:2,T:3,U:4,V:5,W:6,X:7,Y:8,Z:9 };
  var VIN_W  = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];
  function vinValid(vin) {
    if (!/^[A-HJ-NPR-Z0-9]{17}$/.test(vin)) return false;
    var sum = 0;
    for (var i = 0; i < 17; i++) {
      var c = vin[i];
      sum += (/\d/.test(c) ? +c : (VIN_TR[c] || 0)) * VIN_W[i];
    }
    var chk = sum % 11;
    return vin[8] === (chk === 10 ? 'X' : String(chk));
  }
  $all('input[data-vin]').forEach(function (el) {
    var hint = $('[data-vin-hint="' + el.id + '"]');
    function check() {
      el.value = el.value.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g, '').slice(0, 17);
      if (!hint) return;
      if (!el.value) { hint.textContent = '17 characters. I, O and Q are not used in VINs.'; hint.className = 'hint'; el.classList.remove('is-bad'); return; }
      if (el.value.length < 17) { hint.textContent = el.value.length + '/17 characters'; hint.className = 'hint'; el.classList.remove('is-bad'); return; }
      if (vinValid(el.value)) { hint.textContent = '✓ Valid VIN (check digit passes)'; hint.className = 'hint'; el.classList.remove('is-bad'); }
      else { hint.textContent = '✗ Check digit fails — re-read the VIN plate'; hint.className = 'hint hint--bad'; el.classList.add('is-bad'); }
    }
    el.addEventListener('input', check); check();
  });

  /* ---- Existing-customer picker -------------------------------------
     Search, never browse: matches come from /customers/search (max 10),
     so no form ever lists the whole customer base. Server-rendered
     candidate buttons (phone matches) live in the same results box. */
  $all('[data-cust-picker]').forEach(function (box) {
    var q    = box.querySelector('[data-cust-q]');
    var hid  = box.querySelector('input[name="customer_id"]');
    var list = box.querySelector('[data-cust-results]');
    if (!q || !hid || !list) return;
    var timer = null, seq = 0;

    function choose(id, label) {
      hid.value = id;
      q.value = label;
      list.classList.add('hide');
    }
    list.querySelectorAll('[data-cust-candidate]').forEach(function (b) {
      b.addEventListener('click', function () {
        choose(b.getAttribute('data-id'), b.getAttribute('data-label'));
      });
    });

    q.addEventListener('input', function () {
      hid.value = '';                                  // typing again unbinds
      clearTimeout(timer);
      var term = q.value.trim();
      if (term.length < 2) { list.classList.add('hide'); return; }
      timer = setTimeout(function () {
        var mine = ++seq;
        fetch(q.getAttribute('data-endpoint') + '?q=' + encodeURIComponent(term), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (mine !== seq || !d.ok) return;
            list.innerHTML = '';
            if (!d.results.length) { list.classList.add('hide'); return; }
            d.results.forEach(function (r) {
              var label = r.label + ' · ' + r.phone;
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'btn btn--ghost btn--sm btn--block';
              b.style.justifyContent = 'flex-start';
              b.textContent = label + (r.kind ? ' · ' + r.kind : '') + (r.city ? ' · ' + r.city : '');
              b.addEventListener('click', function () { choose(r.id, label); });
              list.appendChild(b);
            });
            list.classList.remove('hide');
          }).catch(function () {});
      }, 250);
    });
  });

  /* ---- Person / business branched customer form ---------------------
     A hard distinction: a person is the customer, or a business entity is.
     The type select carries data-cust-type; blocks marked
     data-when-cust="person|business" show for that kind only, and inputs
     marked data-cust-req="person|business" are required for that kind.
     Server-side validation is authoritative — this is presentation. */
  $all('select[data-cust-type]').forEach(function (sel) {
    function syncKind() {
      var biz = sel.value === 'COMMERCIAL' || sel.value === 'FLEET';
      $all('[data-when-cust]').forEach(function (el) {
        el.classList.toggle('hide', (el.getAttribute('data-when-cust') === 'business') !== biz);
      });
      $all('input[data-cust-req]').forEach(function (inp) {
        inp.required = (inp.getAttribute('data-cust-req') === 'business') === biz;
      });
    }
    sel.addEventListener('change', syncKind); syncKind();
  });

  /* ---- Retail / provider branched job source -------------------------
     A provider account and a claim reference only mean anything on a
     provider job. The source select carries data-job-source; blocks marked
     data-when-source="PROVIDER" show only for those values (comma-separated,
     same shape as data-when-service).

     Presentation only, and deliberately so: hiding a field does not clear it,
     so Rules::providerLink drops the provider link server-side whenever the
     source is not PROVIDER. This just stops dispatch being asked a question
     that has no answer on a retail call. */
  $all('select[data-job-source]').forEach(function (sel) {
    function syncSource() {
      $all('[data-when-source]').forEach(function (el) {
        var want = el.getAttribute('data-when-source').split(',');
        el.classList.toggle('hide', want.indexOf(sel.value) === -1);
      });
    }
    sel.addEventListener('change', syncSource); syncSource();
  });

  /* ---- Conditional intake fields ------------------------------------ */
  var natureSel = $('#nature_of_service');
  if (natureSel) {
    /* The category gates the service, not the other way round. Choosing what
       the truck carries narrows the job list to what that truck can do, so a
       mismatched pair cannot be entered rather than being caught afterwards.

       The eligibility map is rendered by the server from ServiceCategory, so
       this file never becomes a second copy of the rule, and the server
       re-coerces the pair on submit regardless of what happened here. */
    var catSel = $('#service_category');
    var typeMap = {};
    if (catSel) {
      try { typeMap = JSON.parse(catSel.getAttribute('data-service-types') || '{}'); } catch (e) {}
    }

    /* Rebuild the job list for the chosen category, keeping the current
       selection when that category can still roll it. A retired type on an
       existing record is not in the map, so it is carried across explicitly —
       editing an old request must not silently rename the job. */
    function syncTypes() {
      if (!catSel || !typeMap[catSel.value]) { return; }
      var types = typeMap[catSel.value];
      var had   = natureSel.value;
      var keep  = Object.prototype.hasOwnProperty.call(types, had);
      var legacy = natureSel.getAttribute('data-legacy-value');
      var legacyLabel = natureSel.getAttribute('data-legacy-label');

      natureSel.innerHTML = '';
      if (legacy && legacy === had && !keep) {
        var lo = document.createElement('option');
        lo.value = legacy; lo.textContent = legacyLabel || legacy;
        natureSel.appendChild(lo);
      }
      Object.keys(types).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k; o.textContent = types[k];
        natureSel.appendChild(o);
      });
      natureSel.value = (keep || legacy === had) ? had : (natureSel.options[0] || {}).value;
      syncNature();
    }

    /* Fields that only make sense for one job — the lockout occupant check. */
    function syncNature() {
      $all('[data-when-service]').forEach(function (el) {
        var want = el.getAttribute('data-when-service').split(',');
        el.classList.toggle('hide', want.indexOf(natureSel.value) === -1);
      });
    }

    if (catSel) { catSel.addEventListener('change', syncTypes); }
    natureSel.addEventListener('change', syncNature);
    syncNature();
  }

  /* Lockout with a child or pet inside escalates to EMERGENCY. */
  var occupant = $('#occupant_inside');
  if (occupant) {
    occupant.addEventListener('change', function () {
      if (!occupant.checked) return;
      var em = $('input[name="priority"][value="EMERGENCY"]');
      if (em) { em.checked = true; }
      var note = $('#occupant_note');
      if (note) note.classList.remove('hide');
    });
  }

  /* ---- Customer location share (public /locate page) -----------------
   *  This runs on the CUSTOMER'S phone, never on a dispatcher's machine —
   *  "capture GPS" means asking the stranded caller's device where it is.
   *  The tap is what triggers the browser permission prompt; a denial gets
   *  instructions and the phone number, not a dead end.                   */
  var locBtn = $('#loc_share_btn');
  if (locBtn) {
    var locForm   = $('#loc_form');
    var locStatus = $('#loc_status');
    var say = function (msg) { locStatus.textContent = msg; locStatus.classList.remove('hide'); };
    locBtn.addEventListener('click', function () {
      if (!navigator.geolocation) {
        say('This phone cannot share its location from the browser — please call us instead.');
        return;
      }
      locBtn.disabled = true; locBtn.textContent = 'Locating…';
      navigator.geolocation.getCurrentPosition(function (p) {
        $('#loc_lat').value = p.coords.latitude.toFixed(7);
        $('#loc_lng').value = p.coords.longitude.toFixed(7);
        $('#loc_acc').value = Math.round(p.coords.accuracy || 0);
        locBtn.textContent = 'Sending…';
        locForm.submit();
      }, function (err) {
        locBtn.disabled = false; locBtn.textContent = 'Share my location';
        say(err && err.code === 1
          ? 'Location permission was denied. Allow location for your browser in your phone settings, then tap the button again — or call us.'
          : 'Could not get a position fix. If you can, move away from buildings or an underpass and try again — or call us.');
      }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
  }

  /* ---- Signature capture --------------------------------------------
   *  The field on the page is a trigger and a preview. Drawing happens on
   *  a full-screen sheet, because a customer signing with a finger needs
   *  room — and a cramped signature is weaker evidence than a clear one.
   *
   *  The sheet is a single shared element created once and reused; only
   *  the field bindings below are per-page.                              */
  $all('[data-sigfield]').forEach(function (field) {
    var target = root.querySelector('#' + field.getAttribute('data-target'));
    var img    = field.querySelector('.sigfield__img');
    var open   = field.querySelector('[data-sig-open]');
    var clear  = field.querySelector('[data-sig-clear]');
    if (!target) { return; }

    function apply(dataUrl) {
      target.value = dataUrl || '';
      if (dataUrl) { img.src = dataUrl; field.classList.add('is-signed'); }
      else         { img.removeAttribute('src'); field.classList.remove('is-signed'); }
      open.textContent = dataUrl ? 'Sign again' : 'Click here to sign';
    }

    if (open)  open.addEventListener('click', function () {
      SigSheet.open({
        title:    field.getAttribute('data-title'),
        subtitle: field.getAttribute('data-subtitle'),
        existing: target.value,
        onApply:  apply
      });
    });
    if (clear) clear.addEventListener('click', function () { apply(''); });

    var form = field.closest('form');
    if (form && !form.hasAttribute('data-sig-bound')) {
      form.setAttribute('data-sig-bound', '1');
      form.addEventListener('submit', function (ev) {
        if (!form.hasAttribute('data-sig-required')) { return; }
        var missing = false;
        form.querySelectorAll('[data-sigfield]').forEach(function (f) {
          var t = document.getElementById(f.getAttribute('data-target'));
          if (t && !t.value) { missing = true; }
        });
        if (missing) {
          ev.preventDefault();
          alert('A customer signature is required to authorize this amount.');
        }
      });
    }
  });

  /* ---- Line-item catalog picker ------------------------------------- */
  var picker = $('#catalog_search');
  if (picker) {
    picker.addEventListener('input', function () {
      var q = picker.value.toLowerCase();
      $all('[data-catalog-row]').forEach(function (r) {
        r.classList.toggle('hide', q !== '' && r.getAttribute('data-catalog-row').indexOf(q) === -1);
      });
    });
  }

  /* ---- Live line total in the picker -------------------------------- */
  function bindLive(qtyId, priceId, outId) {
    var q = root.querySelector('#' + qtyId), p = root.querySelector('#' + priceId), o = root.querySelector('#' + outId);
    if (!q || !p || !o) return;
    function calc() { o.textContent = '$' + ((+q.value || 0) * (+p.value || 0)).toFixed(2); }
    q.addEventListener('input', calc); p.addEventListener('input', calc); calc();
  }
  bindLive('line_qty', 'line_price', 'line_total_preview');

  /* Selecting a catalog item pre-fills cost + price + qty in the add-line form.
     Cost drives the suggested price via the matrix (see wireSuggest below). */
  $all('[data-pick-item]').forEach(function (el) {
    el.addEventListener('click', function () {
      document.getElementById('catalog_item_id').value = el.getAttribute('data-pick-item');
      var costEl = document.getElementById('line_cost');
      if (costEl) costEl.value = el.getAttribute('data-cost') || '';
      document.getElementById('line_price').value = el.getAttribute('data-price');
      var ov = document.getElementById('line_overridden'); if (ov) ov.value = '0';
      document.getElementById('picked_name').textContent = el.getAttribute('data-name');
      $all('[data-pick-item]').forEach(function (x) { x.style.outline = ''; });
      el.style.outline = '1px solid rgba(94,230,255,.6)';

      /* A miscellaneous charge is priced by judgement, not by the matrix. Show
         the description field, leave the price empty for the user to set, and
         put the suggester to sleep so a cost typed afterwards cannot overwrite
         what they entered. The server enforces all of this again in
         Lines::add(); this is only the form keeping up. */
      var misc  = el.getAttribute('data-misc') === '1';
      var field = document.getElementById('misc_name_field');
      var nameI = document.getElementById('line_name');
      var pr    = document.getElementById('line_price');
      if (field) { field.style.display = misc ? '' : 'none'; }
      if (nameI) { nameI.value = ''; nameI.required = misc; }
      // The slot has no price to fall back on, so the box must be filled in.
      if (pr) { pr.required = misc; }
      if (pr && pr._setMiscMode) { pr._setMiscMode(misc); }

      if (misc) {
        if (pr) { pr.value = ''; }
        if (nameI) { nameI.focus(); }
      } else {
        // Re-suggest a price from the picked item's cost, then focus quantity.
        if (pr && pr._suggest) { pr._suggest(); }
        document.getElementById('line_qty').focus();
      }
      var evt = new Event('input'); document.getElementById('line_qty').dispatchEvent(evt);
    });
  });

  /* ---- Suggested price from cost (matrix), live and overridable -------
     The formula lives only on the server; this fetches /pricing/suggest and
     fills the price while it is in its suggested state, tracks manual
     overrides, and offers use/reset. Wired on both the line editor and the
     catalog part form. */
  function fmtMoney(x) { var n = Number(x); return isNaN(n) ? '' : '$' + n.toFixed(2); }
  function wireSuggest(costId, priceId, flagId, noteId) {
    var cost = root.querySelector('#' + costId), price = root.querySelector('#' + priceId);
    if (!cost || !price) return;
    var endpoint = cost.getAttribute('data-price-endpoint') || 'pricing/suggest';
    var flag = root.querySelector('#' + flagId), note = root.querySelector('#' + noteId);
    var last = null, timer = null, miscMode = false;
    function overridden() { return flag && flag.value === '1'; }
    function setOverridden(v) { if (flag) flag.value = v ? '1' : '0'; }
    function render() {
      if (!note) return;
      if (miscMode) {
        note.textContent = 'Markup does not apply to a miscellaneous charge — the price you enter is the price billed.';
      } else if (overridden()) {
        note.innerHTML = (last && last.price != null)
          ? 'Manual price. Suggested ' + fmtMoney(last.price)
            + ' <a href="#" data-use-suggested>use</a> · <a href="#" data-reset-suggested>reset to suggested</a>'
          : 'Manual price.';
      } else if (last && last.needs_pricing) {
        note.textContent = 'No cost recorded — set a price manually.';
      } else if (last && last.price != null) {
        note.innerHTML = '<span class="pill-suggested">suggested</span> from cost · '
          + last.markup_pct + '% markup · editable';
      } else { note.textContent = ''; }
    }
    function fetchSuggest(then) {
      var body = new URLSearchParams(); body.append('unit_cost', cost.value);
      var tok = (price.closest('form') || document).querySelector('[name="_csrf"]');
      if (tok) body.append('_csrf', tok.value);
      fetch(endpoint, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(function (r) { return r.json(); })
        .then(function (d) { last = d; if (then) then(d); render(); })
        .catch(function () {});
    }
    function applySuggested() { if (last && last.price != null) { price.value = last.price; setOverridden(false); render(); } }

    cost.addEventListener('input', function () {
      if (miscMode) { return; }   // a misc charge is never priced from its cost
      clearTimeout(timer);
      timer = setTimeout(function () {
        fetchSuggest(function (d) { if (!overridden() && d.price != null) { price.value = d.price; } });
      }, 250);
    });
    price.addEventListener('input', function () {
      if (miscMode) { return; }   // nothing to override — there is no suggestion
      setOverridden(true); render();
    });
    if (note) note.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-use-suggested]'))   { ev.preventDefault(); applySuggested(); }
      if (ev.target.closest('[data-reset-suggested]')) { ev.preventDefault(); fetchSuggest(function () { applySuggested(); }); }
    });
    // Let the catalog picker trigger a fresh suggestion after it sets the cost.
    price._suggest = function () { fetchSuggest(function (d) { if (!overridden() && d.price != null) { price.value = d.price; } }); };
    /* Picking a misc item suspends the whole suggester: no fetch, no autofill,
       no override flag. Clearing it restores normal matrix behaviour so the
       next item picked in the same modal prices itself as usual. */
    price._setMiscMode = function (on) {
      miscMode = !!on;
      if (miscMode) { clearTimeout(timer); setOverridden(false); }
      render();
    };
    render();
  }
  wireSuggest('line_cost', 'line_price', 'line_overridden', 'line_price_note');
  wireSuggest('cat_cost',  'cat_price',  'cat_overridden',  'cat_price_note');

  /* ---- Vehicle year/make/model cascading dropdowns -------------------
     Progressive enhancement over plain text inputs. The server renders
     ordinary <input> fields (so no-JS, a failed fetch, or a database that
     predates vehicle_catalog all still work); this upgrades each one to a
     <select> fed from /vehicles/options, cascading year → make → model.
     Every select ends in "Other…", which swaps that field back to free
     text — the catalog suggests, it never restricts, because the caller
     may be sitting in a 1987 motorhome. A prefilled value that the catalog
     doesn't know (an edit form holding free text) is kept as a selected
     option rather than dropped. The list itself lives only in the
     database; this only renders what the endpoint returns. */
  function wireVehiclePicker(box) {
    if (box._vehWired) return;
    box._vehWired = true;
    var endpoint = box.getAttribute('data-vehicle-endpoint');
    var flds = {};
    ['year', 'make', 'model'].forEach(function (k) {
      flds[k] = box.querySelector('[data-veh="' + k + '"]');
    });
    if (!endpoint || !flds.year || !flds.make || !flds.model) return;

    function fetchOptions(params, then) {
      var q = new URLSearchParams(params).toString();
      fetch(endpoint + (q ? '?' + q : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) then(d); })
        .catch(function () {});          // fields simply stay as they are
    }

    /* Replace a field with a <select> carrying the same name and value.
       Returns the select; choosing "Other…" swaps the original text input
       back in, focused, and the picker leaves that field alone for good. */
    function toSelect(k, values, onchange) {
      var el = flds[k];
      if (el._vehFree) return null;                 // user asked for free text
      var cur = (el.value || '').trim();
      var sel;
      if (el.tagName === 'SELECT') { sel = el; sel.innerHTML = ''; }
      else {
        sel = document.createElement('select');
        sel.className = 'select';
        sel.name = el.name;
        el.parentNode.replaceChild(sel, el);
        el._vehInput = el;                          // keep the original for "Other…"
        sel._vehOriginal = el;
        flds[k] = sel;
      }
      function opt(v, label) {
        var o = document.createElement('option');
        o.value = v; o.textContent = label === undefined ? v : label;
        return o;
      }
      sel.appendChild(opt('', '—'));
      var seen = false;
      values.forEach(function (v) {
        v = String(v);
        var o = opt(v);
        if (v === cur) { o.selected = true; seen = true; }
        sel.appendChild(o);
      });
      if (cur && !seen) {                           // free text the catalog doesn't know
        var keep = opt(cur, cur + ' (as entered)');
        keep.selected = true;
        sel.insertBefore(keep, sel.children[1] || null);
      }
      sel.appendChild(opt('__other', 'Other…'));
      sel.onchange = function () {
        if (sel.value === '__other') {
          var back = sel._vehOriginal || document.createElement('input');
          if (!sel._vehOriginal) { back.className = 'input'; back.name = sel.name; }
          back.value = '';
          back._vehFree = true;
          sel.parentNode.replaceChild(back, sel);
          flds[k] = back;
          back.focus();
          if (onchange) onchange('');
          return;
        }
        if (onchange) onchange(sel.value);
      };
      return sel;
    }

    function loadModels() {
      var y = flds.year.value, m = flds.make.value;
      if (!y || !m || flds.model._vehFree) return;
      fetchOptions({ year: y, make: m }, function (d) {
        toSelect('model', d.models || []);
      });
    }
    function loadMakes() {
      var y = flds.year.value;
      if (!y || flds.make._vehFree) return;
      fetchOptions({ year: y }, function (d) {
        toSelect('make', d.makes || [], function () { loadModels(); });
        loadModels();                     // the kept make needs its model list refreshed too
      });
    }

    fetchOptions({}, function (d) {
      toSelect('year', d.years || [], function () { loadMakes(); });
      if (flds.year.value) loadMakes();
    });
  }
  $all('[data-vehicle-picker]').forEach(wireVehiclePicker);

  /* ---- Generate a catalog part number via the server (Claude/rules) -- */
  var skuGen = $('#sku_gen');
  if (skuGen) {
    skuGen.addEventListener('click', function () {
      var form = skuGen.closest('form');
      var note = document.getElementById('sku_note');
      var input = document.getElementById('sku_input');
      if (!form || !input) return;
      var body = new URLSearchParams();
      ['name', 'item_type', 'category', 'description'].forEach(function (n) {
        var f = form.querySelector('[name="' + n + '"]');
        if (f) body.append(n, f.value);
      });
      var token = form.querySelector('[name="_csrf"]');
      if (token) body.append('_csrf', token.value);

      var original = skuGen.textContent;
      skuGen.disabled = true; skuGen.textContent = '…';
      fetch(skuGen.getAttribute('data-suggest-sku'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) { input.value = d.sku; if (note) note.textContent = d.note || ('Assigned ' + d.sku); }
          else if (note) { note.textContent = d.error || 'Could not generate a part number.'; }
        })
        .catch(function () { if (note) note.textContent = 'Could not reach the server.'; })
        .then(function () { skuGen.disabled = false; skuGen.textContent = original; });
    });
  }

  /* ---- Chart-of-accounts pickers (catalog form) ---------------------
     Choosing "+ Create new … account" opens the account modal layered over
     the Add-item modal, so the half-filled item form is never lost. The
     save posts to /accounts (validation lives server-side in Accounts);
     on success the new account is slotted into the picker in number order
     and selected. */
  var acctTrigger = null;   // the <select> that opened the modal
  $all('select[data-account-select]').forEach(function (sel) {
    var prev = sel.value;
    sel.addEventListener('change', function () {
      if (sel.value !== '__new__') { prev = sel.value; return; }
      sel.value = prev;                                   // don't leave "+ Create" selected
      var m = document.getElementById('newAccountModal');
      if (!m) return;
      acctTrigger = sel;
      var type = sel.getAttribute('data-account-select');
      document.getElementById('acct_type').value = type;
      document.getElementById('acct_modal_title').textContent =
        type === 'COGS' ? 'New COGS account' : 'New revenue account';
      var err = document.getElementById('acct_error');
      if (err) { err.classList.add('hide'); err.textContent = ''; }
      m.classList.add('is-open');
      document.getElementById('acct_number').focus();
    });
  });

  var acctSave = $('#acct_save');
  if (acctSave) {
    acctSave.addEventListener('click', function () {
      var number = document.getElementById('acct_number');
      var name   = document.getElementById('acct_name');
      var err    = document.getElementById('acct_error');
      var body   = new URLSearchParams();
      body.append('account_number', number.value);
      body.append('name', name.value);
      body.append('account_type', document.getElementById('acct_type').value);
      body.append('json', '1');   // same endpoint as the accounts page form; this asks for JSON back
      var tok = document.querySelector('[name="_csrf"]');
      if (tok) body.append('_csrf', tok.value);

      var original = acctSave.textContent;
      acctSave.disabled = true; acctSave.textContent = '…';
      fetch(acctSave.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) {
            if (err) { err.textContent = d.error || 'Could not create the account.'; err.classList.remove('hide'); }
            return;
          }
          if (acctTrigger) {
            var opt = document.createElement('option');
            opt.value = d.account.number;
            opt.textContent = d.account.number + ' · ' + d.account.name;
            // Keep number order: insert before the first option with a
            // higher number, else before the trailing "+ Create new" row.
            var before = null;
            Array.prototype.slice.call(acctTrigger.options).forEach(function (o) {
              if (before) return;
              if (o.value === '__new__' || (/^\d+$/.test(o.value) && +o.value > +d.account.number)) { before = o; }
            });
            acctTrigger.insertBefore(opt, before);
            acctTrigger.value = d.account.number;
          }
          number.value = ''; name.value = '';
          var m = document.getElementById('newAccountModal');
          if (m) m.classList.remove('is-open');
        })
        .catch(function () {
          if (err) { err.textContent = 'Could not reach the server.'; err.classList.remove('hide'); }
        })
        .then(function () { acctSave.disabled = false; acctSave.textContent = original; });
    });
  }

  /* ---- Resume a form the server re-opened --------------------------- *
   *  A page can render a modal already open and mark the spot to land on:
   *  [data-scroll-into-view] is scrolled to, [data-scroll-focus] takes the
   *  caret. Used when intake is cut short mid-form — the service request's
   *  "Capture GPS" submits from the middle of the page, so the dispatcher is
   *  handed back the rest of the form at the field they were about to reach
   *  rather than at the top of a modal they have to read through.
   *  Both are opt-in attributes; a page without them behaves as before. */
  var jump = $('[data-scroll-into-view]');
  if (jump) {
    requestAnimationFrame(function () {
      jump.scrollIntoView({ block: 'center' });
      var f = document.querySelector('[data-scroll-focus]');
      if (f) { f.focus(); }
    });
  }

  /* ---- Confirm destructive actions ---------------------------------- */
  $all('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); ev.stopPropagation(); }
    });
  });

  /* ---- Auto-dismiss flashes (they live in the shell, outside .main) -- */
  setTimeout(function () {
    document.querySelectorAll('.flash').forEach(function (f) {
      f.style.transition = 'opacity .4s'; f.style.opacity = '0';
      setTimeout(function () { f.remove(); }, 420);
    });
  }, 6000);

    /* Modules that live outside this IIFE (the pin map needs Leaflet, which
       loads on demand) hook here to re-run after a PJAX swap. */
    document.dispatchEvent(new CustomEvent('wkr:page', { detail: root }));
  } /* end initPage */

  /* Wire up the first page render. Every later navigation re-runs this
     against the freshly swapped-in region. */
  initPage(document);
})();

/* ---- Pin map: the point is the answer -------------------------------- *
 * Wherever the marker sits is where the vehicle is and where the truck
 * routes. The address beside it is a LABEL for that point. Moving the pin
 * re-derives the address; correcting the address moves the pin. Neither
 * decides anything alone: every lookup goes to the server, because "is this
 * an address" is a business rule and lives in Address, not here.
 *
 * This block sits OUTSIDE the main IIFE on purpose. It depends on Leaflet,
 * which is fetched only on screens that actually have a map — most do not,
 * and none should pay 160KB for one. It cannot be a <script> tag in the
 * partial either: navigation swaps innerHTML, and innerHTML never executes
 * scripts, so the tag would work on a full load and silently do nothing
 * in-app. Injecting it here is the one path that works for both.           */
(function () {
  'use strict';

  var maps = {}, loading = null;

  function each(sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

  function setStatus(root, msg, kind) {
    var el = root.querySelector('[data-pinmap-status]');
    if (!el) { return; }
    el.textContent = msg;
    el.className = 'pinmap__status' + (kind ? ' pinmap__status--' + kind : '');
  }

  function post(url, body, csrf) {
    body._csrf = csrf;
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: Object.keys(body).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(body[k] == null ? '' : body[k]);
      }).join('&')
    }).then(function (r) { return r.json(); });
  }

  /** Fetch Leaflet once, then run everything waiting on it. */
  function ensureLeaflet(root, cb) {
    if (window.L) { cb(); return; }
    if (loading) { loading.then(cb); return; }

    loading = new Promise(function (resolve, reject) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = root.getAttribute('data-leaflet-css');
      document.head.appendChild(css);

      var js = document.createElement('script');
      js.src = root.getAttribute('data-leaflet-js');
      js.onload = resolve;
      js.onerror = reject;
      document.head.appendChild(js);
    });
    loading.then(cb).catch(function () {
      setStatus(root, 'The map could not be loaded. Type the nearest address instead — '
        + 'it is the address that gates promotion, not the pin.', 'warn');
    });
  }

  function build(root) {
    var uid = root.getAttribute('data-uid');
    if (maps[uid]) { maps[uid].map.invalidateSize(); return; }

    var canvas = root.querySelector('[data-pinmap-canvas]');
    if (!canvas || !canvas.offsetParent) { return; }   /* still hidden — no size to measure */

    var lat = parseFloat(root.getAttribute('data-lat'));
    var lng = parseFloat(root.getAttribute('data-lng'));
    var has = !isNaN(lat) && !isNaN(lng);
    var ctr = has ? [lat, lng]
                  : [parseFloat(root.getAttribute('data-flat')), parseFloat(root.getAttribute('data-flng'))];

    var map = L.map(canvas, { scrollWheelZoom: false }).setView(ctr, has ? 16 : 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var st = { map: map, marker: null, busy: false };
    maps[uid] = st;

    function place(ll, ask) {
      if (!st.marker) {
        st.marker = L.marker(ll, { draggable: true }).addTo(map);
        st.marker.on('dragend', function () { place(st.marker.getLatLng(), true); });
      } else {
        st.marker.setLatLng(ll);
      }
      root.querySelector('[data-pinmap-lat]').value = ll.lat.toFixed(7);
      root.querySelector('[data-pinmap-lng]').value = ll.lng.toFixed(7);
      if (ask) { reverse(ll); }
    }

    /* preserve=true fills only EMPTY fields — used when the dispatcher typed
     * the address themselves (their words stay) and when a page loads with a
     * pin whose labels were never derived. A deliberate pin move passes
     * preserve=false: the new point's nearest address replaces the old label,
     * because the pin is the answer and the address is only its name. */
    function reverse(ll, preserve) {
      if (st.busy) { return; }
      st.busy = true;
      setStatus(root, 'Looking up the nearest address…');
      post(root.getAttribute('data-reverse'), { lat: ll.lat, lng: ll.lng }, root.getAttribute('data-csrf'))
        .then(function (d) {
          st.busy = false;
          if (!d.ok) { setStatus(root, d.error || 'That point could not be looked up.', 'warn'); return; }
          var lineI   = root.querySelector('[data-pinmap-line]');
          var cityI   = root.querySelector('[data-pinmap-city]');
          var postalI = root.querySelector('[data-pinmap-postal]');
          var sel     = root.querySelector('[data-pinmap-state]');
          if (!preserve || lineI.value.trim() === '')   { lineI.value   = d.line || lineI.value; }
          if (!preserve || cityI.value.trim() === '')   { cityI.value   = d.city || cityI.value; }
          if (!preserve || postalI.value.trim() === '') { postalI.value = d.postal || postalI.value; }
          if (sel && d.state && (!preserve || sel.value === '')) { sel.value = d.state; }
          setStatus(root,
            d.usable ? 'Nearest address: ' + d.one_line
                     : (d.reason || 'No street address near that point. Type one, or move the pin.'),
            d.usable ? 'ok' : 'warn');
        })
        .catch(function () { st.busy = false; setStatus(root, 'The lookup failed. The pin is still set.', 'warn'); });
    }

    if (has) {
      place(L.latLng(lat, lng), false);
      /* A pin with no address label (a locate-link answer the geocoder never
       * enriched, or an enrichment that failed at capture time) fills its own
       * labels the moment the map is looked at. Empty fields only, so a
       * half-typed form is never clobbered by a page rebuild. */
      if (root.querySelector('[data-pinmap-line]').value.trim() === '') {
        reverse(L.latLng(lat, lng), true);
      }
    }
    map.on('click', function (e) { place(e.latlng, true); });

    /* One lookup, two triggers. The address fields and the Find button share
     * this: geocode what was typed, pan there, drop (or move) the pin. The
     * lastQ guard stops a blur from re-running a lookup nothing changed. */
    var lastQ = null;
    function lookup() {
      if (st.busy) { return; }
      var sel = root.querySelector('[data-pinmap-state]');
      var q = {
        line:   root.querySelector('[data-pinmap-line]').value,
        city:   root.querySelector('[data-pinmap-city]').value,
        state:  sel ? sel.value : '',
        postal: root.querySelector('[data-pinmap-postal]').value
      };
      lastQ = q.line.trim() + '|' + q.city.trim() + '|' + q.state;
      st.busy = true;
      setStatus(root, 'Finding that address…');
      post(root.getAttribute('data-forward'), q, root.getAttribute('data-csrf')).then(function (d) {
        st.busy = false;
        if (!d.ok || !d.usable) { setStatus(root, d.error || 'That is not an address.', 'warn'); return; }
        if (!d.located) { setStatus(root, d.reason || 'Could not place that address.', 'warn'); return; }
        var ll = L.latLng(d.lat, d.lng);
        map.setView(ll, 17);
        place(ll, false);
        setStatus(root, 'Pin moved to ' + d.one_line + '. Drag it if the vehicle is elsewhere.', 'ok');
        /* The typed words stay — they ARE the nearest address now — but a
         * street line alone leaves city/state/ZIP blank, and blanks fail the
         * promotion gate. The server derives the missing pieces from the
         * geocoder's answer; anything still blank falls back to a reverse
         * lookup at the point itself. */
        var cityI2   = root.querySelector('[data-pinmap-city]');
        var sel2     = root.querySelector('[data-pinmap-state]');
        var postalI2 = root.querySelector('[data-pinmap-postal]');
        if (cityI2.value.trim() === '' && d.city)     { cityI2.value = d.city; }
        if (sel2 && sel2.value === '' && d.state)     { sel2.value = d.state; }
        if (postalI2.value.trim() === '' && d.postal) { postalI2.value = d.postal; }
        if (cityI2.value.trim() === '' || (sel2 && sel2.value === '')) {
          reverse(ll, true);
        }
      }).catch(function () { st.busy = false; setStatus(root, 'The lookup failed.', 'warn'); });
    }

    var find = root.querySelector('[data-pinmap-find]');
    if (find) { find.addEventListener('click', lookup); }

    /* Typing the address is enough. Leaving the street field — or Enter in
     * it, which must not submit the form — runs the lookup on its own, so a
     * dispatcher who never notices the button still gets a pin. City/state
     * edits re-run it too, but only once a street line exists: a lookup on
     * city alone would drop a pin on the town centre as if it meant
     * something. The button stays as the explicit retry. */
    var lineEl = root.querySelector('[data-pinmap-line]');
    function autoLookup() {
      var sel = root.querySelector('[data-pinmap-state]');
      var key = lineEl.value.trim() + '|' + root.querySelector('[data-pinmap-city]').value.trim()
              + '|' + (sel ? sel.value : '');
      if (lineEl.value.trim() === '' || key === lastQ) { return; }
      lookup();
    }
    lineEl.addEventListener('change', autoLookup);
    lineEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); autoLookup(); }
    });
    root.querySelector('[data-pinmap-city]').addEventListener('change', autoLookup);
    var stateEl = root.querySelector('[data-pinmap-state]');
    if (stateEl) { stateEl.addEventListener('change', autoLookup); }

    setTimeout(function () { map.invalidateSize(); }, 60);
  }

  function scan() {
    var first = document.querySelector('[data-pinmap]');
    if (!first) { return; }
    ensureLeaflet(first, function () { each('[data-pinmap]', build); });
  }

  document.addEventListener('wkr:page', scan);
  /* A map inside a modal has no size until the modal opens. */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest && ev.target.closest('[data-modal-open]')) { setTimeout(scan, 80); }
  });
  if (document.readyState !== 'loading') { scan(); }
  else { document.addEventListener('DOMContentLoaded', scan); }
})();

/* ---- "Drop a pin on a map" picker ------------------------------------ *
 * A modal you open, position, and confirm. Dragging and zooming change
 * nothing: only Confirm writes the pin into the form and asks the server for
 * the nearest address, and Cancel puts back whatever was there before. A pin
 * is a claim about where a customer physically is, so it takes a deliberate
 * act rather than a stray click.                                          */
(function () {
  'use strict';

  var built = {}, loading = null;

  function ensureLeaflet(root) {
    if (window.L) { return Promise.resolve(); }
    if (loading) { return loading; }
    loading = new Promise(function (resolve, reject) {
      var css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = root.getAttribute('data-leaflet-css');
      document.head.appendChild(css);
      var js = document.createElement('script');
      js.src = root.getAttribute('data-leaflet-js');
      js.onload = resolve; js.onerror = reject;
      document.head.appendChild(js);
    });
    return loading;
  }

  function post(url, body, csrf) {
    body._csrf = csrf;
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: Object.keys(body).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(body[k] == null ? '' : body[k]);
      }).join('&')
    }).then(function (r) { return r.json(); });
  }

  function fld(root, name) { return root.querySelector('[data-pinpick-' + name + ']'); }

  function wire(root) {
    var id     = root.getAttribute('data-id');
    var modal  = document.querySelector('[data-pinpick-modal="' + id + '"]');
    if (!modal || built[id]) { return; }
    built[id] = true;

    /* MOVE THE MODAL TO THE END OF THE BODY BEFORE ANYTHING ELSE.
     *
     * `.panel > *` carries `position:relative; z-index:1`, so every panel body
     * is its own stacking context. A modal rendered inside one can only stack
     * against its siblings within that panel — no z-index will lift it above a
     * LATER panel, which then paints straight over the map. The other modals in
     * this app avoid it by being written at the end of the page, which a
     * partial placed next to its own button cannot do.
     *
     * Safe to move: the modal holds only buttons. The hidden inputs stay behind
     * in the form, which is where they have to be to submit. */
    if (modal.parentNode !== document.body) { document.body.appendChild(modal); }

    var canvas  = modal.querySelector('[data-pinpick-canvas]');
    var status  = modal.querySelector('[data-pinpick-status]');
    var okBtn   = modal.querySelector('[data-pinpick-confirm]');
    var summary = fld(root, 'summary');
    /* `manual` marks a pin the dispatcher placed themselves. Typing an
       address never overrides one of those — see the note further down. */
    var st      = { map: null, marker: null, ll: null, busy: false, manual: false };

    function say(msg, kind) {
      status.textContent = msg;
      status.className = 'pinpick__status' + (kind ? ' pinpick__status--' + kind : '');
    }

    function close() { modal.classList.remove('is-open'); }

    function place(ll, byHand) {
      if (byHand !== undefined) { st.manual = !!byHand; }
      st.ll = ll;
      if (!st.marker) {
        st.marker = L.marker(ll, { draggable: true }).addTo(st.map);
        st.marker.on('dragend', function () { place(st.marker.getLatLng(), true); });
      } else {
        st.marker.setLatLng(ll);
      }
      okBtn.disabled = false;
      say('Pin at ' + ll.lat.toFixed(5) + ', ' + ll.lng.toFixed(5)
        + '. Drag to adjust, then confirm.');
    }

    function open() {
      ensureLeaflet(root).then(function () {
        if (!st.map) {
          var lat = parseFloat(fld(root, 'lat').value);
          var lng = parseFloat(fld(root, 'lng').value);
          var has = !isNaN(lat) && !isNaN(lng);
          st.map = L.map(canvas, { scrollWheelZoom: true }).setView(
            has ? [lat, lng] : [parseFloat(root.getAttribute('data-flat')), parseFloat(root.getAttribute('data-flng'))],
            has ? 17 : 11);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
          }).addTo(st.map);
          st.map.on('click', function (e) { place(e.latlng, true); });
          if (has) { place(L.latLng(lat, lng)); }
        }
        /* Leaflet measures its container on creation; inside a modal that has
           only just opened, that measurement is zero until the next frame. */
        setTimeout(function () { st.map.invalidateSize(); }, 80);
      }).catch(function () {
        say('The map could not be loaded. Use "Capture GPS" instead, or type the location in words.', 'warn');
      });
    }

    root.querySelector('[data-pinpick-open]').addEventListener('click', open);

    /* ---- Typing an address drops the pin ---------------------------- *
     * The reverse of Confirm, and it has to be, or the two halves of the
     * panel disagree about where the job is.
     *
     * WHEN IT DOES NOT FIRE. A pin the dispatcher placed by hand is a
     * deliberate statement — often more precise than any address, which is
     * the whole reason the map exists. Fixing a typo in the city should not
     * silently drag that pin to the nearest street number. So this fills an
     * EMPTY pin, or replaces one that itself came from an address, and
     * otherwise says the two now disagree and leaves the choice alone.     */
    var form = root.closest('form');
    if (form) {
      var names  = ['line', 'city', 'state', 'postal'].map(function (k) {
        return root.getAttribute('data-f-' + k);
      });
      var inputs = names.map(function (n) { return n ? form.querySelector('[name="' + n + '"]') : null; });
      var timer = null, lastAsked = '';

      function currentAddress() {
        return inputs.map(function (el) { return el ? el.value.trim() : ''; });
      }

      function askForPin() {
        var a = currentAddress();
        /* Not the address rule — that lives on the server. Just a guard
           against calling the geocoder with obviously nothing. */
        if (!a[0] || !a[1] || !a[2]) { return; }
        var key = a.join('|');
        if (key === lastAsked) { return; }
        lastAsked = key;

        if (st.manual) {
          setStatus(root, 'Address changed. The pin is where you put it — reopen the map to move it.', 'warn');
          summary.className = 'pinpick__summary';
          summary.textContent = 'Pin set by hand · address edited separately';
          return;
        }

        post(root.getAttribute('data-forward'),
             { line: a[0], city: a[1], state: a[2], postal: a[3] }, root.getAttribute('data-csrf'))
          .then(function (d) {
            if (!d || !d.ok || !d.usable || !d.located) {
              /* A refusal here is not worth interrupting intake over — the
                 dispatcher can still drop the pin by hand, and the promote
                 gate is where an unusable address actually gets stopped. */
              return;
            }
            fld(root, 'lat').value = Number(d.lat).toFixed(7);
            fld(root, 'lng').value = Number(d.lng).toFixed(7);
            st.fromAddress = true;
            summary.className = 'pinpick__summary';
            summary.textContent = 'Pin placed from the address · '
              + Number(d.lat).toFixed(5) + ', ' + Number(d.lng).toFixed(5)
              + ' — open the map to check it';
            /* Keep an already-built map in step with the fields. */
            if (st.map) { var ll = L.latLng(d.lat, d.lng); place(ll, false); st.map.setView(ll, 17); }
          })
          .catch(function () { /* offline or blocked; the pin can still be dropped by hand */ });
      }

      inputs.forEach(function (el) {
        if (!el) { return; }
        el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(askForPin, 250); });
        el.addEventListener('blur',   function () { clearTimeout(timer); timer = setTimeout(askForPin, 250); });
      });
    }

    Array.prototype.forEach.call(modal.querySelectorAll('[data-pinpick-cancel]'), function (b) {
      b.addEventListener('click', function () {
        /* Cancel discards the working pin and leaves the form untouched. */
        var lat = parseFloat(fld(root, 'lat').value);
        var lng = parseFloat(fld(root, 'lng').value);
        if (st.marker && !isNaN(lat) && !isNaN(lng)) { st.marker.setLatLng(L.latLng(lat, lng)); st.ll = L.latLng(lat, lng); }
        else if (st.marker) { st.map.removeLayer(st.marker); st.marker = null; st.ll = null; okBtn.disabled = true; }
        close();
      });
    });

    okBtn.addEventListener('click', function () {
      if (!st.ll || st.busy) { return; }
      st.busy = true;
      okBtn.disabled = true;
      say('Confirming and looking up the nearest address…');

      post(root.getAttribute('data-reverse'),
           { lat: st.ll.lat, lng: st.ll.lng }, root.getAttribute('data-csrf'))
        .then(function (d) {
          st.busy = false; okBtn.disabled = false;

          /* The coordinates are the answer and are kept whether or not the
             geocoder had anything to say about them. */
          fld(root, 'lat').value = st.ll.lat.toFixed(7);
          fld(root, 'lng').value = st.ll.lng.toFixed(7);

          if (d && d.ok) {
            if (fld(root, 'cross')) { fld(root, 'cross').value = d.intersection || ''; }

            /* Write the resolved address into the form's OWN visible fields.
               Confirm is a deliberate act — the dispatcher asked for the pin's
               answer — so it overwrites rather than only filling blanks. It is
               all still editable afterwards, which is the point of the fields
               being visible at all. */
            var form = root.closest('form');
            if (form) {
              [[root.getAttribute('data-f-line'),   d.line],
               [root.getAttribute('data-f-city'),   d.city],
               [root.getAttribute('data-f-state'),  d.state],
               [root.getAttribute('data-f-postal'), d.postal]].forEach(function (pair) {
                if (!pair[0]) { return; }
                var el = form.querySelector('[name="' + pair[0] + '"]');
                if (el && pair[1]) { el.value = pair[1]; }
              });
            }
            summary.className = 'pinpick__summary';
            summary.textContent = d.usable && d.one_line
              ? 'Pin set · nearest address ' + d.one_line
              : 'Pin set · ' + st.ll.lat.toFixed(5) + ', ' + st.ll.lng.toFixed(5)
                + ' (no street address nearby)';
          } else {
            summary.className = 'pinpick__summary';
            summary.textContent = 'Pin set · ' + st.ll.lat.toFixed(5) + ', ' + st.ll.lng.toFixed(5)
              + ' (address lookup failed)';
          }
          close();
        })
        .catch(function () {
          /* A failed lookup must not lose the pin — the position is the part
             that matters, and the address can be filled in later. */
          st.busy = false; okBtn.disabled = false;
          fld(root, 'lat').value = st.ll.lat.toFixed(7);
          fld(root, 'lng').value = st.ll.lng.toFixed(7);
          summary.className = 'pinpick__summary';
          summary.textContent = 'Pin set · ' + st.ll.lat.toFixed(5) + ', ' + st.ll.lng.toFixed(5)
            + ' (address lookup failed)';
          close();
        });
    });
  }

  function scan() { Array.prototype.forEach.call(document.querySelectorAll('[data-pinpick]'), wire); }

  document.addEventListener('wkr:page', scan);
  if (document.readyState !== 'loading') { scan(); }
  else { document.addEventListener('DOMContentLoaded', scan); }
})();

/* ---- Suggested ETA from the truck's position ------------------------- *
 * The route math lives on the server (POST /work-orders/{id}/eta-suggest,
 * which also snapshots miles and minutes onto the work order); this fills
 * the minutes box and says what was calculated. Filling a box sends
 * nothing — the En route button with its checkbox is the approval.        */
(function () {
  'use strict';

  function wire(btn) {
    if (btn._etaWired) { return; }
    btn._etaWired = true;
    btn.addEventListener('click', function () {
      var form  = btn.closest('form');
      var input = form ? form.querySelector('[name="eta_minutes"]') : null;
      var note  = document.getElementById('eta_suggest_note');
      var say   = function (t) { if (note) { note.textContent = t; } };
      say('Calculating the route…');

      var body = new URLSearchParams();
      var tok  = (form || document).querySelector('[name="_csrf"]');
      if (tok) { body.append('_csrf', tok.value); }

      fetch(btn.getAttribute('data-eta-suggest'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { say(d.error || 'No route available.'); return; }
          if (input) { input.value = d.eta_minutes; }
          say(d.miles + ' mi · ' + d.drive_minutes + ' min drive from the truck’s position ('
            + d.located_at + ') → suggested ETA ' + d.eta_clock
            + ' (includes a 5 min buffer). Adjust the minutes if you know better — nothing is sent yet.');
        })
        .catch(function () { say('The route service did not answer — enter the minutes by hand.'); });
    });
  }

  function scan() { Array.prototype.forEach.call(document.querySelectorAll('[data-eta-suggest]'), wire); }

  document.addEventListener('wkr:page', scan);
  if (document.readyState !== 'loading') { scan(); }
  else { document.addEventListener('DOMContentLoaded', scan); }
})();

/* body.is-customer follows whether any data-customer-facing modal is open. */
function customerModeSync() {
  var any = document.querySelector('.modal-bg.is-open[data-customer-facing]');
  document.body.classList.toggle('is-customer', !!any);
}
