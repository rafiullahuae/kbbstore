{{--
  "ARE YOU SURE?" BEFORE EVERY RESET, RESTORE AND REVERT IN THE ADMIN.

  THE DEFECT, IN THE OWNER'S WORDS (1 October 2026): "when i press any reset /
  restore option on any page specially under Appearance pages, it should ask me
  Are you sure? Yes / No on small popup with background page blur. bcz few
  times i hit by mistake, and everything messed up and restored, which i didn't
  want."

  Not one of the admin's reset buttons asked first. Appearance -> Header ->
  "Reset to defaults" put every header setting back in a single click, and the
  same was true of Product styles, Product page layout, Site search, Mobile
  menu, Cart panel, Checkout page, Page background, Footer, Site layout,
  Security, the review screens' Revert, the column-picker resets, restoring a
  customer or an order, and Core Updates -> Restore.

  ONE GUARD, NOT THIRTY EDITS. Each of those buttons is wired differently -- an
  id caught by a delegated handler on #content, an onclick property, an inline
  onclick="" -- and they live in a dozen files that other work is changing at
  the same time. Editing every handler would mean thirty chances to break a
  screen and would still miss the next reset button someone adds. So this
  listens for clicks in the CAPTURE phase on the document, which runs before
  any of those handlers, and stops the click when the control's own label
  starts with Reset / Restore / Revert / Back to default(s). On "Yes" it clicks
  the same control again with a one-shot pass, so the screen's own handler
  runs exactly as it always did. On "No" nothing at all has happened.

  What it does NOT catch, on purpose: Modules -> "Back to defaults" already
  asks in its own box (askThen), so it is excluded rather than asked twice.

  tests/Feature/AdminResetGuardTest.php pins that every reset/restore label in
  the admin's views is one this guard matches, and that this file is included
  exactly once.
--}}
<style>
.kbb-sure-bg{position:fixed;inset:0;background:rgba(16,23,41,.45);-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.kbb-sure-bg.on{display:flex}
.kbb-sure{background:var(--surface,#fff);color:var(--ink,#0f172a);border-radius:14px;box-shadow:0 24px 60px rgba(15,23,42,.28);width:100%;max-width:360px;padding:20px 20px 16px}
.kbb-sure h4{margin:0 0 6px;font-size:16px;font-weight:700}
.kbb-sure p{margin:0;font-size:13px;line-height:1.5;color:var(--ink-soft,#475569)}
.kbb-sure .kbb-sure-row{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.kbb-sure button{min-width:76px;padding:8px 14px;border-radius:9px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.kbb-sure .kbb-sure-no{background:var(--surface,#fff);border:1px solid var(--line,#cbd5e1);color:inherit}
.kbb-sure .kbb-sure-yes{background:#b91c1c;border:1px solid #b91c1c;color:#fff}
.kbb-sure button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
</style>
<div class="kbb-sure-bg" id="kbbSureBg" role="presentation">
  <div class="kbb-sure" role="alertdialog" aria-modal="true" aria-labelledby="kbbSureTitle" aria-describedby="kbbSureText">
    <h4 id="kbbSureTitle">Are you sure?</h4>
    <p id="kbbSureText"></p>
    <div class="kbb-sure-row">
      <button type="button" class="kbb-sure-no" id="kbbSureNo">No</button>
      <button type="button" class="kbb-sure-yes" id="kbbSureYes">Yes</button>
    </div>
  </div>
</div>
@verbatim
<script>
(function () {
  /* The labels that put something back. Matched against the START of the
     control's own visible label, so "Reset password", "Restore this customer"
     and "Back to defaults" are caught and "Use this total" is not. */
  var RESET_LABEL = /^\s*(reset|restore|revert|back to defaults?)\b/i;

  /* Controls that already ask in a box of their own. */
  var ALREADY_ASKS = '[data-mddef]';

  var bg = document.getElementById('kbbSureBg');
  var text = document.getElementById('kbbSureText');
  var yes = document.getElementById('kbbSureYes');
  var no = document.getElementById('kbbSureNo');
  var pending = null;
  var lastFocus = null;

  function labelOf(el) {
    var t = (el.innerText || el.textContent || el.value || '').replace(/\s+/g, ' ').trim();
    return t || (el.getAttribute('aria-label') || el.getAttribute('title') || '').trim();
  }

  function close(go) {
    var el = pending;
    pending = null;
    bg.classList.remove('on');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    if (go && el && el.isConnected) {
      el.setAttribute('data-kbb-sure-pass', '1');
      try { el.click(); } finally { el.removeAttribute('data-kbb-sure-pass'); }
    }
  }

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest
      ? e.target.closest('button, a, [role="button"], input[type="button"], input[type="submit"]')
      : null;

    if (!el || el.closest('#kbbSureBg') || el.matches(ALREADY_ASKS)) return;
    if (el.getAttribute('data-kbb-sure-pass') === '1') return;

    var label = labelOf(el);
    if (!RESET_LABEL.test(label)) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    pending = el;
    lastFocus = document.activeElement;
    text.textContent = 'You pressed “' + label + '”. Press Yes to go ahead, or No to leave everything exactly as it is.';
    bg.classList.add('on');
    no.focus();
  }, true);

  yes.addEventListener('click', function () { close(true); });
  no.addEventListener('click', function () { close(false); });
  bg.addEventListener('click', function (e) { if (e.target === bg) close(false); });
  document.addEventListener('keydown', function (e) {
    if (pending && e.key === 'Escape') { e.preventDefault(); close(false); }
  }, true);

  window.kbbResetLabel = RESET_LABEL;
})();
</script>
@endverbatim
