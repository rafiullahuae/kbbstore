{{--
    THE CHECKOUT'S DIAGNOSTIC PANEL (Lane PO hotfix). /checkout/?kbbdiag=1 only.

    "the place order button still not working, nothing happening upon click"
    -- in Firefox for Android, on the owner's phone, and nowhere we can reach.
    This turns his phone into the evidence: a small panel at the top of the
    page that lists, live, the browser, which of the checkout's scripts
    started, every tap near Place order (the element the tap really landed
    on, from the event itself), what each Place order handler decided, and
    any script error or unhandled rejection. He opens the address, taps, and
    sends a screenshot.

    NOTHING PERSONAL: ids, class names, methods and the browser's own
    validation sentences -- never a value typed into a box. No request is
    made; nothing is stored. Rendered only when the query says so
    (checkout.blade.php), so every other checkout is byte for byte what it was.
    Placed first in the page so its error listener is up before any other
    script runs.
--}}
<div id="kbbDiag" dir="ltr" style="position:fixed;top:0;inset-inline:0;z-index:2147483646;max-height:46vh;overflow:auto;background:rgba(18,18,20,.9);color:#fff;font:11px/1.4 ui-monospace,Menlo,Consolas,monospace;padding:6px 8px;text-align:start"><b id="kbbDiagT"></b><button type="button" id="kbbDiagFold" style="font:inherit;margin-inline-start:8px"></button><pre id="kbbDiagLog" style="margin:4px 0 0;white-space:pre-wrap;word-break:break-word"></pre></div>
<script>
(function () {
  var out = document.getElementById('kbbDiagLog'), lines = [], t0 = Date.now();
  /* Written here rather than in the markup: an engineer's panel, not shop copy. */
  document.getElementById('kbbDiagT').textContent = 'kbbdiag';
  document.getElementById('kbbDiagFold').textContent = 'fold';
  function w(src, msg) {
    lines.push('+' + (Date.now() - t0) + 'ms ' + src + ': ' + msg);
    if (lines.length > 80) { lines.shift(); }
    out.textContent = lines.join('\n');
  }
  /* A global of its own: the layout assigns window.KBB afresh further down
     the page, which would take a property of it away with it. */
  window.kbbDiag = w;
  w('browser', navigator.userAgent);

  function name(n) {
    if (!n || !n.tagName) { return String(n); }
    var c = typeof n.className === 'string' ? n.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    return n.tagName.toLowerCase() + (n.id ? '#' + n.id : '') + (c ? '.' + c : '') + (n.hasAttribute('data-place') ? '[data-place]' : '');
  }
  function method() {
    var m = document.querySelector('input[name="payment_method"]:checked');
    return m ? m.value : 'none';
  }

  window.addEventListener('error', function (e) {
    if (e.target && e.target !== window && (e.target.src || e.target.href)) {
      w('failed to load', String(e.target.src || e.target.href).split('?')[0]);
      return;
    }
    w('ERROR', (e.message || '?') + ' @ ' + String(e.filename || '').split('/').pop().split('?')[0] + ':' + (e.lineno || 0));
  }, true);
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    w('REJECTION', String(r && r.message ? r.message : r));
  });

  /* The tap: what the browser says it landed on, from the event, and what is
     at that point on screen -- the two differ when something covers the button. */
  ['pointerdown', 'click'].forEach(function (type) {
    window.addEventListener(type, function (e) {
      var t = e.target, place = t && t.closest ? t.closest('[data-place]') : null;
      if (type === 'pointerdown' && !place) { return; }
      var at = document.elementFromPoint(e.clientX, e.clientY);
      w(type, 'target ' + name(t) + ' | at the point ' + name(at)
        + (place ? ' | Place order disabled=' + place.disabled : '') + ' | method ' + method());
    }, true);
  });

  function census(when) {
    var b = document.querySelectorAll('[data-place]'), off = 0;
    for (var i = 0; i < b.length; i++) { if (b[i].disabled) { off++; } }
    var k = window.KBB || {};
    w('page', when + ' | Stripe ' + typeof window.Stripe + ' | card form ' + (k.cardLeg ? 'listening' : 'not started')
      + ' | overlay ' + (k.placing ? 'ready' : 'missing') + ' | buttons ' + b.length + ' (' + off + ' disabled) | method ' + method());
  }
  document.addEventListener('DOMContentLoaded', function () { census('DOMContentLoaded'); });
  window.addEventListener('load', function () { census('load'); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { census('restored from back/forward cache'); } });

  /*
   * THE KEYBOARD (the "white bar" report). Each time the keyboard opens or
   * closes the visual viewport changes size; this logs both viewports, where
   * the document ends against them, and what is painted at three heights
   * just above the keyboard -- element and background. An element of ours
   * names itself; Firefox's own strip above the keyboard (form autofill)
   * lies outside the visual viewport and shows as nothing of ours there.
   */
  var vv = window.visualViewport, vvTimer = 0;
  function bottomOf() {
    var h = vv ? vv.height : innerHeight, top = vv ? vv.offsetTop : 0, out = [];
    [8, 45, 90].forEach(function (up) {
      var y = Math.max(0, Math.round(top + h - up)), n = document.elementFromPoint(innerWidth / 2, y);
      out.push(up + 'px up: ' + name(n) + (n ? ' bg ' + getComputedStyle(n).backgroundColor : ''));
    });
    w('viewport', 'layout ' + innerWidth + 'x' + innerHeight + ' | visual ' + (vv ? Math.round(vv.width) + 'x' + Math.round(vv.height) + ' @' + Math.round(vv.offsetTop) : 'n/a')
      + ' | scrollY ' + Math.round(scrollY) + ' of ' + document.documentElement.scrollHeight
      + ' | document ends ' + Math.round(document.documentElement.getBoundingClientRect().bottom) + ' | focus ' + name(document.activeElement)
      + '\n    ' + out.join('\n    '));
  }
  function soon() { clearTimeout(vvTimer); vvTimer = setTimeout(bottomOf, 250); }
  if (vv) { vv.addEventListener('resize', soon); }
  window.addEventListener('resize', soon);

  document.getElementById('kbbDiagFold').addEventListener('click', function () {
    var p = document.getElementById('kbbDiag');
    p.style.maxHeight = p.style.maxHeight === '24px' ? '46vh' : '24px';
  });
})();
</script>
