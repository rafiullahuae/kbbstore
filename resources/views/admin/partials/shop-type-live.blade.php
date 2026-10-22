{{--
    Text sizes · English / Arabic · live preview (Lane CK, 22 October 2026).

    Shared by three screens: Appearance → Checkout page, Appearance → Cart page
    and Appearance → Cart panel. Included once, from checkout-page-screen; the
    other two call window.kbbTypeLive at run time and draw nothing extra when
    it is absent.

    WHAT IT DOES
      - An English / Arabic switch at the top of each size tab. English edits
        the existing settings, unchanged. Arabic shows every size control as
        "Same as English" until it is moved; moved, it is "Custom" with a
        reset back to inheriting. The Arabic values are sent with Save as one
        map; an inheriting control sends nothing.
      - The REAL page beside the controls: the shop's own cart page, checkout
        or cart panel, rendered by the server once per language on a sample
        basket (App\Support\ShopLivePreview) and kept in memory. Every frame is
        that one document in a sandboxed same-origin iframe (srcdoc), so moving
        a slider, ticking a box or switching a tab asks the server for nothing.
      - Moving a control sets the same custom properties, and writes the same
        small-phone block, that the server prints after Save — the sums are
        done in integer thousandths exactly as App\Support\TextSizes does them,
        so the preview and the saved page cannot round apart.
      - Desktop shows one 1280px frame; Mobile shows 390, 360 and 320 side by
        side, so the small-phone steps can be seen working.

    No polling, no timer, no request per change. Nothing here measures layout:
    the frames are scaled by fixed factors.
--}}
@verbatim
<style>
.ktl-lang{display:flex;gap:4px;align-items:center;flex-wrap:wrap;margin:12px 0 2px;padding:3px;background:#f6eff2;border-radius:10px;width:max-content;max-width:100%}
.ktl-lang button{border:0;background:transparent;font:inherit;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:8px;cursor:pointer;color:#5e545a;min-height:34px}
.ktl-lang button[aria-pressed="true"]{background:#c13e63;color:#fff}
.ktl-note{font-size:12px;color:#6f646a;margin:6px 0 0;line-height:1.45}
.ktl-st{display:inline-block;font-size:10.5px;font-weight:700;border-radius:99px;padding:1px 8px;margin-inline-start:6px;vertical-align:middle}
.ktl-st.inh{background:#eef0f2;color:#5c6670}
.ktl-st.cus{background:#fde7ee;color:#a82f53}
.ktl-reset{border:1px solid #e6dde0;background:#fff;border-radius:6px;font:inherit;font-size:11.5px;padding:3px 8px;cursor:pointer;color:#5e545a;margin-inline-start:6px}
.ktl-also{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6f646a;margin:16px 0 4px}
.ktl-live{margin-top:14px}
.ktl-live h3{font-size:13px;margin:0 0 6px}
.ktl-frames{display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding:2px 0 8px;max-width:100%}
.ktl-fr{flex:none;min-width:0}
.ktl-fr h4{font-size:11px;font-weight:700;color:#6f646a;margin:0 0 4px}
.ktl-box{border:1px solid #e6dde0;border-radius:10px;overflow:hidden;background:#fff;position:relative}
.ktl-box iframe{border:0;display:block;transform-origin:0 0;background:#fff}
.ktl-d .ktl-box{width:640px;height:560px}
.ktl-d iframe{width:1280px;height:1120px;transform:scale(.5)}
.ktl-m .ktl-box{height:640px}
.ktl-m iframe{height:800px;transform:scale(.8)}
.ktl-w390 .ktl-box{width:312px}.ktl-w390 iframe{width:390px}
.ktl-w360 .ktl-box{width:288px}.ktl-w360 iframe{width:360px}
.ktl-w320 .ktl-box{width:256px}.ktl-w320 iframe{width:320px}
.ktl-wait{font-size:12px;color:#6f646a;padding:10px 0}
/* In a screen's side column (the mobile tabs) the three phones stack. */
.chp-side .ktl-frames,.cpp-side .ktl-frames{flex-direction:column;overflow:visible}
@media (max-width:700px){.ktl-d .ktl-box{width:320px;height:280px}.ktl-d iframe{transform:scale(.25)}}
</style>
<script>
(function () {
  'use strict';
  if (window.kbbTypeLive) return;

  /* id -> { type, values(), ar, lang, html: {en, ar}, loading: {}, base } */
  var screens = {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------------------------------------------- the same sums as TextSizes */
  function milli(kind, v) {
    v = Math.round(Number(v) || 0);
    if (kind === 'pct') return v * 10;
    if (kind === 'ov') return (100 + v) * 10;
    if (kind === 'px') return v * 1000;
    if (kind === 'px10') return v * 100;
    return v * 1000;
  }
  function fromMilli(kind, m) {
    var sign = m < 0 ? '-' : '';
    var abs = Math.abs(m);
    var frac = String(abs % 1000);
    while (frac.length < 3) frac = '0' + frac;
    frac = frac.replace(/0+$/, '');
    var out = sign + Math.floor(abs / 1000) + (frac === '' ? '' : '.' + frac);
    return (kind === 'px' || kind === 'px10') ? out + 'px' : out;
  }
  function emit(kind, v) { return fromMilli(kind, milli(kind, v)); }
  function reduced(kind, v, today, cut) {
    var m = milli(kind, v);
    return Math.max(milli(kind, today), Math.floor((m * (100 - cut) + 50) / 100));
  }

  /* -------------------------------------------------------------- values */
  /* The values a page would print: English, or English with the Arabic map
     laid over it; and a locked Spacing % follows Text %. */
  function effective(st, lang) {
    var v = {};
    var src = st.values();
    Object.keys(src).forEach(function (k) { v[k] = src[k]; });
    if (lang === 'ar') {
      Object.keys(st.ar).forEach(function (k) { if (k in v) v[k] = st.ar[k]; });
    }
    (st.type.links || []).forEach(function (l) {
      if (v[l.lock]) v[l.space] = v[l.text];
    });
    return v;
  }

  function fitCss(st, v) {
    var fit = st.type.fit;
    if (!fit) return '';
    var out = '';
    [[374, v[fit.s1]], [340, v[fit.s2]]].forEach(function (step) {
      var cut = Math.round(Number(step[1]) || 0);
      if (cut <= 0) return;
      var decls = [], rules = [];
      fit.rows.forEach(function (r) {
        var val = Math.round(Number(v[r.key]) || 0);
        if (val <= r.today) return;
        var s = fromMilli(r.kind, reduced(r.kind, val, r.today, cut));
        if (r.sel) rules.push(r.sel + '{font-size:calc(' + s + '*var(' + st.type.textVar + ',1))!important}');
        else decls.push(r.prop + ':' + s + '!important');
      });
      if (decls.length) out += '@media (max-width:' + step[0] + 'px){' + fit.scope + '{' + decls.join(';') + '}}';
      if (rules.length) out += '@media (max-width:' + step[0] + 'px){' + rules.join('') + '}';
    });
    return out;
  }

  function ruleCss(st, v) {
    var out = '', phone = '';
    (st.type.rules || []).forEach(function (r) {
      var d = r.sel + '{font-size:calc(' + emit(r.kind, v[r.key]) + '*var(' + st.type.textVar + ',1))}';
      if (r.dev === 'm') phone += d; else out += d;
    });
    if (phone) out += '@media (max-width:' + Math.round(Number(v[st.type.rulesBp]) || 600) + 'px){' + phone + '}';
    return out;
  }

  /* ------------------------------------------------------------- frames */
  function paintFrame(st, ifr, lang) {
    var doc = ifr.contentDocument;
    if (!doc || !doc.body) return;
    var v = effective(st, lang);
    doc.querySelectorAll(st.type.scope).forEach(function (root) {
      st.type.vars.forEach(function (d) {
        var val = v[d.key];
        if (d.lock && v[d.lock]) val = v[d.text];
        root.style.setProperty(d.prop, emit(d.kind, val));
      });
    });
    var srv = doc.getElementById(st.type.fitId);
    if (srv) srv.textContent = '';
    var tag = doc.getElementById('kbb-live-css');
    if (!tag) {
      tag = doc.createElement('style');
      tag.id = 'kbb-live-css';
      doc.body.appendChild(tag);
    }
    tag.textContent = ruleCss(st, v) + fitCss(st, v);
  }

  function paint(id) {
    var st = screens[id];
    if (!st) return;
    document.querySelectorAll('iframe[data-ktl="' + id + '"]').forEach(function (ifr) {
      paintFrame(st, ifr, ifr.getAttribute('data-ktl-lang'));
    });
  }

  function fetchHtml(id, lang) {
    var st = screens[id];
    if (st.html[lang] || st.loading[lang]) return;
    st.loading[lang] = true;
    fetch(st.base() + st.type.live + '?lang=' + lang, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) { st.html[lang] = html; st.loading[lang] = false; mount(id); })
      .catch(function (status) { st.loading[lang] = false; st.failed = status === 404 ? 404 : true; mount(id); });
  }

  /* Puts the frames into the placeholder the screen drew. One request per
     language for the whole session; every redraw after that reuses it. */
  function mount(id) {
    var st = screens[id];
    var host = document.querySelector('[data-ktl-frames="' + id + '"]');
    if (!st || !host) return;
    var lang = st.lang;
    var dev = host.getAttribute('data-ktl-dev');
    if (!st.html[lang]) {
      var code = st.failed;
      host.innerHTML = '<div class="ktl-wait">' + (code === 404
        /* A 404 here is the compiled route table without the new routes, not
           a missing page: the package's clear_caches migration has not run. */
        ? 'The live preview is not in this server’s compiled route table yet. Open Platform → Cache, clear the route cache, then come back to this tab.'
        : st.failed ? 'The live page could not be drawn. Reload to try again.' : 'Drawing the real page with a sample basket…') + '</div>';
      if (!st.failed) fetchHtml(id, lang);
      return;
    }
    var widths = dev === 'm' ? [390, 360, 320] : [1280];
    host.innerHTML = '';
    widths.forEach(function (w) {
      var fr = document.createElement('div');
      fr.className = 'ktl-fr ktl-w' + w;
      fr.innerHTML = '<h4>' + w + 'px' + (lang === 'ar' ? ' · العربية' : '') + '</h4><div class="ktl-box"></div>';
      var ifr = document.createElement('iframe');
      ifr.setAttribute('sandbox', 'allow-same-origin');
      ifr.setAttribute('data-ktl', id);
      ifr.setAttribute('data-ktl-lang', lang);
      ifr.setAttribute('title', 'Live preview ' + w + 'px');
      ifr.setAttribute('tabindex', '-1');
      ifr.addEventListener('load', function () { paintFrame(st, ifr, lang); });
      ifr.srcdoc = st.html[lang];
      fr.querySelector('.ktl-box').appendChild(ifr);
      host.appendChild(fr);
    });
  }

  /* --------------------------------------------------------- the screens */
  function attach(id, cfg) {
    var st = screens[id] || { ar: {}, lang: 'en', html: {}, loading: {} };
    st.type = cfg.type;
    st.values = cfg.values;
    st.base = cfg.base;
    st.rerender = cfg.rerender;
    st.ar = {};
    Object.keys(cfg.ar || {}).forEach(function (k) { st.ar[k] = cfg.ar[k]; });
    screens[id] = st;
  }

  function devOf(id, tab) {
    var st = screens[id];
    return st && st.type && st.type.tabs ? (st.type.tabs[tab] || null) : null;
  }

  function isAr(id) { return !!screens[id] && screens[id].lang === 'ar'; }

  function head(id, tab) {
    var st = screens[id];
    if (!devOf(id, tab)) return '';
    return '<div class="ktl-lang" role="group" aria-label="Language">'
      + '<button type="button" data-ktl-lang="en" data-ktl-id="' + id + '" aria-pressed="' + (st.lang === 'en') + '">English</button>'
      + '<button type="button" data-ktl-lang="ar" data-ktl-id="' + id + '" aria-pressed="' + (st.lang === 'ar') + '">Arabic · العربية</button>'
      + '</div>'
      + '<p class="ktl-note">' + (st.lang === 'ar'
        ? 'The Arabic shop (/ar). Every size starts as <b>Same as English</b> and follows it until you move it; <b>Reset</b> puts it back. Saved with the Save button below, like everything else.'
        : 'The English shop. These are the sizes the shop already uses; Arabic follows them unless you set it on the Arabic tab.') + '</p>';
  }

  /* The Arabic "Reset" button: it asks in the admin box itself (kbbConfirm). */
  function resetBtn(key, id) {
    return '<button type="button" class="ktl-reset" data-kbb-asks data-ktl-reset="' + esc(key) + '" data-ktl-id="' + id + '">Reset</button>';
  }

  /* An Arabic control for one English field: same scale, its own value. */
  function arField(id, f) {
    var st = screens[id];
    if (!st.type.arKeys || st.type.arKeys.indexOf(f.key) === -1) return '';
    var o = f.options || {};
    var custom = Object.prototype.hasOwnProperty.call(st.ar, f.key);
    var val = custom ? st.ar[f.key] : st.values()[f.key];
    return '<div class="chp-f cps-f cpp-f ktl-f"><div class="chp-fh cps-fh cpp-fh"><label for="ktl-' + esc(f.key) + '">' + esc(f.label)
      + ' <span class="ktl-st ' + (custom ? 'cus' : 'inh') + '" data-ktl-st="' + esc(f.key) + '">' + (custom ? 'Custom' : 'Same as English') + '</span>'
      + (custom ? resetBtn(f.key, id) : '')
      + '</label><span class="chp-val cps-val cpp-val" data-ktl-val="' + esc(f.key) + '">' + esc(String(val) + (o.unit || '')) + '</span></div>'
      + '<input type="range" id="ktl-' + esc(f.key) + '" data-ktl-ar="' + esc(f.key) + '" data-ktl-id="' + id + '"'
      + ' data-ktl-unit="' + esc(o.unit || '') + '"'
      + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(val) + '">'
      + '</div>';
  }

  function arFields(id, fields) {
    var html = fields.map(function (f) { return arField(id, f); }).join('');
    return '<div class="ktl-arfields">' + html + '</div>';
  }

  function frames(id, tab) {
    var dev = devOf(id, tab);
    if (!dev) return '';
    var st = screens[id];
    return '<div class="ktl-live"><h3>Live preview · ' + (dev === 'm' ? 'mobile 390 / 360 / 320' : 'desktop 1280')
      + (st.lang === 'ar' ? ' · Arabic' : ' · English') + '</h3>'
      + '<div class="ktl-frames ' + (dev === 'm' ? 'ktl-m' : 'ktl-d') + '" data-ktl-frames="' + id + '" data-ktl-dev="' + dev + '"></div>'
      + '<p class="ktl-note">The real page, drawn by the shop with a three-item sample basket. Nothing in it can be clicked or bought, and nothing is saved until you press Save.</p></div>';
  }

  /* The Arabic values to save: only the moved ones. */
  function arPayload(id) {
    var st = screens[id];
    var out = {};
    if (st) Object.keys(st.ar).forEach(function (k) { out[k] = st.ar[k]; });
    return out;
  }

  /* After an English control moves: keep a locked Spacing % on Text %. */
  function afterInput(id, key) {
    var st = screens[id];
    if (!st) return;
    var v = st.values();
    (st.type.links || []).forEach(function (l) {
      if ((key === l.text || key === l.lock) && v[l.lock]) {
        v[l.space] = v[l.text];
        var out = document.querySelector('[data-chp-val="' + l.space + '"],[data-cps-val="' + l.space + '"],[data-cpp-val="' + l.space + '"]');
        var input = document.querySelector('[data-chp-key="' + l.space + '"],[data-cps-key="' + l.space + '"],[data-cpp-key="' + l.space + '"]');
        if (input) input.value = v[l.text];
        if (out) out.textContent = v[l.text] + '%';
      }
    });
    paint(id);
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ktl-lang][data-ktl-id]');
    if (b && b.tagName === 'BUTTON') {
      var st = screens[b.getAttribute('data-ktl-id')];
      if (st) { st.lang = b.getAttribute('data-ktl-lang'); st.rerender(); }
      return;
    }
    var r = e.target.closest('[data-ktl-reset]');
    if (r) {
      /* A reset asks first, like every reset in the console (reset-guard's
         dialog). Nothing is stored until Save either way. */
      var st2 = screens[r.getAttribute('data-ktl-id')];
      var key = r.getAttribute('data-ktl-reset');
      var put = function (ok) { if (ok && st2) { delete st2.ar[key]; st2.rerender(); } };
      if (window.kbbConfirm) window.kbbConfirm('Put this Arabic size back to "Same as English"? Nothing is stored until you press Save.').then(put);
      else put(true);
    }
  });

  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-ktl-ar]');
    if (!el) return;
    var id = el.getAttribute('data-ktl-id');
    var st = screens[id];
    if (!st) return;
    var key = el.getAttribute('data-ktl-ar');
    st.ar[key] = Number(el.value);
    (st.type.links || []).forEach(function (l) {
      var lockOn = st.values()[l.lock];
      if (key === l.text && lockOn) {
        st.ar[l.space] = st.ar[key];
        var sp = document.querySelector('[data-ktl-ar="' + l.space + '"]');
        if (sp) sp.value = st.ar[key];
        var spv = document.querySelector('[data-ktl-val="' + l.space + '"]');
        if (spv) spv.textContent = st.ar[key] + '%';
      }
    });
    var badge = document.querySelector('[data-ktl-st="' + key + '"]');
    if (badge && badge.className.indexOf('cus') === -1) {
      /* The first move makes it Custom. Updated in place: redrawing the
         controls mid-drag would drop the slider out of the pointer. */
      badge.className = 'ktl-st cus';
      badge.textContent = 'Custom';
      badge.insertAdjacentHTML('afterend', resetBtn(key, id));
    }
    var out = document.querySelector('[data-ktl-val="' + key + '"]');
    if (out) out.textContent = el.value + (el.getAttribute('data-ktl-unit') || '');
    paint(id);
  });

  window.kbbTypeLive = {
    attach: attach, head: head, isAr: isAr, devOf: devOf, arFields: arFields,
    frames: frames, mount: mount, paint: paint, arPayload: arPayload, afterInput: afterInput,
    /* exposed for the admin tests' parity check */
    _fitCss: fitCss, _emit: emit
  };
})();
</script>
@endverbatim
