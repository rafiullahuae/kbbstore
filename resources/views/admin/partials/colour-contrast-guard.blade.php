{{--
    A readability warning beside every colour control Google's contrast audit
    reads.                                                             (Lane CT)

    The owner approved darker text colours on 5 October and asked that each
    stay a control he can move back. Moving one back is his call; doing it
    without being told it fails is not. So the controls that own those colours
    — Appearance → Product styles → Colour, and Settings → Business details →
    Your brand colour — say, under the swatch, when the chosen colour drops
    below 4.5:1 against what it is drawn on, and say nothing when it does not.

    THE LIST IS App\Support\ContrastPairs, the same one the tests check the
    shipped defaults against, so the warning and the proof cannot disagree.

    LIGHT: no request, no timer. It listens for `input` on the document and, so
    that a colour saved before this existed is flagged when a screen opens,
    watches #content's direct children (not its subtree) — one callback per
    screen paint, which is when those controls are drawn. It measures no layout.

    Pulled in once, after reset-guard, at the end of admin/app.blade.php.
--}}
<script>
(function () {
  'use strict';
  var PAIRS = @json(\App\Support\ContrastPairs::PAIRS);
  var AA = {{ \App\Support\ContrastPairs::AA }};

  function hex(v) {
    v = String(v || '').trim().replace(/^#/, '');
    return /^[0-9a-f]{6}$/i.test(v) ? '#' + v.toUpperCase() : null;
  }
  function lum(h) {
    var c = [1, 3, 5].map(function (i) {
      var s = parseInt(h.substr(i, 2), 16) / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function ratio(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }
  function keyOf(el) {
    if (!el) return null;
    if (el.id === 'set_brand_accent' || el.id === 'set_brand_accent_pick') return 'set_brand_accent';
    var k = el.getAttribute('data-ps');
    return k && PAIRS[k] ? k : null;
  }
  function control(key) {
    return key === 'set_brand_accent'
      ? document.getElementById('set_brand_accent')
      : document.querySelector('input[type=color][data-ps="' + key + '"]');
  }
  function check(key) {
    var el = control(key), p = PAIRS[key];
    if (!el || !p) return;
    var host = el.closest('.mmcol, .bd-colour') || el.parentNode;
    var note = host.nextElementSibling && host.nextElementSibling.classList.contains('kbb-cg') ? host.nextElementSibling : null;
    var v = hex(el.value), worst = null;
    if (v) {
      var fgEl = p.fill_fg ? document.querySelector('[data-ps="' + p.fill_fg + '"]') : null;
      var against = p.kind === 'fill' ? [hex(fgEl && fgEl.value) || p.fg] : p.against;
      against.forEach(function (bg) {
        var r = ratio(v, bg);
        if (!worst || r < worst.r) worst = { r: r, bg: bg };
      });
    }
    if (!worst || worst.r >= AA) { if (note) note.remove(); return; }
    if (!note) {
      note = document.createElement('small');
      note.className = 'kbb-cg';
      note.setAttribute('role', 'status');
      note.style.cssText = 'display:block;margin-top:4px;color:#A3361B;font-size:11.5px;line-height:1.4';
      host.parentNode.insertBefore(note, host.nextSibling);
    }
    note.textContent = 'Hard to read: ' + worst.r.toFixed(2) + ':1 ' +
      (p.kind === 'fill' ? 'with ' + worst.bg + ' text' : 'on ' + worst.bg) +
      '. Google asks for at least ' + AA + ':1.';
  }
  function all() { Object.keys(PAIRS).forEach(check); }

  document.addEventListener('input', function (e) {
    var k = keyOf(e.target);
    if (k) { check(k); return; }
    // The button's label is a colour too: judge the pair when either moves.
    if (e.target && e.target.getAttribute && e.target.getAttribute('data-ps') === 'cart_fg') check('cart_bg');
  });
  var content = document.getElementById('content');
  if (content && window.MutationObserver) new MutationObserver(all).observe(content, { childList: true });
})();
</script>
