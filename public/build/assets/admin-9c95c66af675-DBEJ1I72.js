
(function () {
  /* The labels that put something back. Matched against the START of the
     control's own visible label, so "Reset password", "Restore this customer"
     and "Back to defaults" are caught and "Use this total" is not. */
  var RESET_LABEL = /^\s*(reset|restore|revert|back to defaults?)\b/i;

  /* Controls that ask in this same box themselves, through window.kbbConfirm,
     because their question needs the screen's own state (a role's name, a
     count). Skipped here so they are never asked twice. */
  var ALREADY_ASKS = '[data-kbb-asks]';

  /* EVERY RESET-TO-DEFAULT CONTROL IN THE ADMIN, AND WHAT IT ASKS (Lane RC,
     10 October: "any reset to default buttons in our backend, must ask Are you
     sure (yes / no) in a popup to confirm"). The label rule above catches a
     "Reset ..." button on arrival, but it could only say "You pressed Reset",
     and it never saw the resets whose label does not start with the word --
     Modules' per-group "Defaults", Buy together's "Use the default", Product
     tabs' "Use the shop's one", Set's "shipped", the page editor's "Shop's
     own", Coming Soon's "Shop pink". Each of those put a default back in one
     click. Here each control names what goes back. A function answers null
     for the same control in a state that resets nothing ("Customise").
     tests/Feature/AdminResetConfirmTest.php pins this list against the views,
     so a renamed id or a new unlisted reset is red there. */
  var KEEP = ' Your changes on this screen will be lost.';
  var UNSAVED = ' Nothing is stored until you press Save.';
  function screen(name) { return 'Reset the ' + name + ' settings to their defaults?' + KEEP; }
  function startsWith(re, ask) {
    return function (el) { return re.test(labelOf(el)) ? ask(el) : null; };
  }
  var KNOWN = [
    ['#hdReset', screen('Header')],
    ['#mmReset', screen('Mobile menu')],
    ['#ssReset', screen('Site search')],
    ['#apReset', screen('Login / Register panel')],
    ['#psReset', screen('Product styles')],
    ['#ppReset', 'Reset the Product page layout to its defaults?' + KEEP],
    ['#ptsReset', 'Reset the delivery box, “Authenticity Guaranteed” and share settings on these tabs to their defaults?' + KEEP],
    ['#pdsReset', 'Put the product page sections on laptops back in the default order?' + KEEP],
    ['#pmsReset', 'Put the product page sections on phones back in the default order?' + KEEP],
    ['#peo-arrreset', 'Put every panel of the product editor back where it starts? The way you arranged them will be lost.'],
    ['[data-chp-defaults]', screen('Checkout page')],
    ['[data-cpp-defaults]', screen('Cart panel')],
    ['[data-wab-defaults]', screen('WhatsApp button')],
    ['[data-pwb-defaults]', screen('Page background')],
    ['[data-sfs-defaults]', 'Reset the Footer settings on this page to their defaults?' + KEEP],
    ['[data-sls-defaults]', screen('Site layout')],
    ['[data-sls-tunereset]', function (el) {
      var what = labelOf(el).replace(/^\s*back to defaults for\s*/i, '');
      return 'Put the fine-tuning for “' + what + '” back to its defaults? Your changes to it will be lost.';
    }],
    ['[data-sx-defaults]', screen('Security')],
    ['[data-nfx-reset]', screen('404 page')],
    ['[data-pbs-reset]', 'Reset the Page banners strip to its defaults?' + KEEP],
    ['[data-ctx-reset]', screen('Contact page')],
    ['[data-oac="reset"]', 'Reset the Owner App look to its defaults?' + KEEP],
    ['[data-bns-tbreset]', 'Put this set’s text box sizes back to their defaults?' + UNSAVED],
    ['[data-bns-tbspreset]', 'Put this set’s text box spacing back to its defaults?' + UNSAVED],
    ['[data-hph-reset]', function (el) {
      return 'Put this size back to ' + labelOf(el).replace(/^\s*reset to\s*/i, '') + '?' + UNSAVED;
    }],
    ['[data-hpc-reset]', 'Put back the three slides the shop shipped with? The slides in the boxes now will be replaced.' + UNSAVED],
    ['[data-kbi="defaults"]', 'Put the invite email’s subject, wording and link expiry back to the default wording? What you typed here will be lost.'],
    ['[data-an-reset]', 'Put the Analytics layout back to the default? Cards you moved or hid come back where they started.'],
    ['[data-mddef]', 'Put every module back to how the shop ships it, and every device choice back to Everywhere? Nothing is stored until you press Save changes.'],
    ['[data-mdgdef]', function (el) {
      var card = el.closest('.mdcard'), b = card && card.querySelector('.mmhd b');
      return 'Put the modules in “' + (b ? b.textContent.trim() : 'this group') + '” back to how the shop ships them? Nothing is stored until you press Save changes.';
    }],
    ['#olColsReset', 'Put the Orders list columns back to the default set? The columns you picked will be lost.'],
    ['#cuColsReset', 'Put the Customers list columns back to the default set? The columns you picked will be lost.'],
    ['#cplColsReset', 'Put the Products list columns back to the default set? The columns you picked will be lost.'],
    ['#ct-cblclear', 'Put every banner setting for this category back to the shop’s own? What you set here will be lost.' + UNSAVED],
    ['[data-sap-reset]', 'Put this setting back to the value the page shipped with?' + UNSAVED],
    ['[data-cc-reset]', 'Put this card’s wording and link back to the shop’s own? What you typed in it will be lost.'],
    ['[data-csx-bgreset]', 'Put the Coming Soon page background back to the shop pink?' + UNSAVED],
    ['[data-kpt-revert]', startsWith(/^use the shop/i, function () {
      return 'Remove this product’s own version of the tab and show the shop’s one again? Its own wording is deleted straight away.';
    })],
    ['[data-btp-mode]', startsWith(/^use the default/i, function () {
      return 'Go back to the default products for this category? The products you picked here will be lost.' + UNSAVED;
    })],
    ['#rbt-revert', 'Undo your changes to the Rating badge and go back to what is saved?'],
    ['#rvs-revert', 'Undo your changes to the Review settings and go back to what is saved?'],
    ['[data-ugs-draftrestore]', 'Put your unsaved changes to this clip back on screen? What is on screen now will be replaced.'],
    ['[onclick^="uRestore("]', 'Restore this earlier version of the site? The files that update changed are put back the way they were.'],
    ['#olBulkRestore', 'Take the selected orders out of the trash?'],
    ['[data-curestore]', 'Take this customer out of the trash?'],
    ['#cuRestore', 'Take this customer out of the trash?'],
    // (Lane PD) Catalog -> Products -> Trash.
    ['[data-cprestore]', 'Take this product out of the trash? It goes back exactly as it was.'],
    ['#cplBulkRestore', 'Take the selected products out of the trash? They go back exactly as they were.'],
    ['[data-upass]', 'Set a new password for this account? You type it on the next step.']
  ];
  window.kbbResetControls = KNOWN.map(function (k) { return k[0]; });

  var bg = document.getElementById('kbbSureBg');
  var title = document.getElementById('kbbSureTitle');
  var text = document.getElementById('kbbSureText');
  var yes = document.getElementById('kbbSureYes');
  var no = document.getElementById('kbbSureNo');
  var answer = null;
  var lastFocus = null;

  function labelOf(el) {
    var t = (el.innerText || el.textContent || el.value || '').replace(/\s+/g, ' ').trim();
    return t || (el.getAttribute('aria-label') || el.getAttribute('title') || '').trim();
  }

  function knownQuestion(el) {
    for (var i = 0; i < KNOWN.length; i++) {
      if (el.matches(KNOWN[i][0])) {
        var q = KNOWN[i][1];
        return typeof q === 'function' ? q(el) : q;
      }
    }
    return null;
  }

  /* Where the owner is, for a reset nobody has listed yet. */
  function where() {
    var c = document.getElementById('crumb'), p = document.getElementById('ptitle');
    var s = [c && c.textContent.trim(), p && p.textContent.trim()].filter(Boolean).join(' → ');
    return s ? ' on ' + s : '';
  }

  function settle(ok) {
    var done = answer;
    answer = null;
    bg.classList.remove('on');
    if (lastFocus && lastFocus.focus && lastFocus.isConnected) { try { lastFocus.focus(); } catch (e) {} }
    lastFocus = null;
    if (done) done(ok);
  }

  /* THE ADMIN'S ONE "ARE YOU SURE?" BOX, for any screen that needs to ask:
       if (!(await window.kbbConfirm('Reset ...?'))) return;
     Resolves true on Yes, false on No, Escape or a click on the blurred page.
     Focus starts on No, so a stray Enter answers No. */
  window.kbbConfirm = function (message, opts) {
    opts = opts || {};
    if (answer) settle(false);
    lastFocus = document.activeElement;
    title.textContent = opts.title || 'Are you sure?';
    text.textContent = String(message || '');
    yes.textContent = opts.yes || 'Yes';
    no.textContent = opts.no || 'No';
    bg.classList.add('on');
    no.focus();
    return new Promise(function (resolve) { answer = resolve; });
  };

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest
      ? e.target.closest('button, a, [role="button"], input[type="button"], input[type="submit"]')
      : null;

    if (!el || el.closest('#kbbSureBg') || el.matches(ALREADY_ASKS)) return;
    if (el.getAttribute('data-kbb-sure-pass') === '1') return;

    var label = labelOf(el);
    /* data-kbb-sure="<question>" opts any other control in, with its own
       question — Unfinished → Discard throws work away and starts with none of
       the words above (Lane PM). The label rule itself is unchanged. */
    var asks = el.getAttribute('data-kbb-sure');
    if (!asks) asks = knownQuestion(el);
    if (!asks && !RESET_LABEL.test(label)) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    window.kbbConfirm(asks
      ? asks
      : 'You pressed “' + label + '”' + where() + '. Press Yes to go ahead, or No to leave everything exactly as it is.'
    ).then(function (ok) {
      /* On Yes the same control is clicked again with a one-shot pass, so its
         own handler runs exactly as it always did. On No nothing happened. */
      if (ok && el.isConnected) {
        el.setAttribute('data-kbb-sure-pass', '1');
        try { el.click(); } finally { el.removeAttribute('data-kbb-sure-pass'); }
      }
    });
  }, true);

  yes.addEventListener('click', function () { settle(true); });
  no.addEventListener('click', function () { settle(false); });
  bg.addEventListener('click', function (e) { if (e.target === bg) settle(false); });
  document.addEventListener('keydown', function (e) {
    if (!answer) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); settle(false); return; }
    /* Tab stays on the two answers while the box is open. */
    if (e.key === 'Tab') {
      e.preventDefault();
      (document.activeElement === no ? yes : no).focus();
    }
  }, true);

  window.kbbResetLabel = RESET_LABEL;
})();
