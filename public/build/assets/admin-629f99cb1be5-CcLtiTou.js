
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

  /* ------------------------------------------- App\Support\TextSizes, in JS
     The same block the shop prints after Save, built the same way: the same
     integer sums (thousandths), the same order, from the same spec and twin
     table the server sent. LaneCk2TextSizesTest runs this function in node
     against TextSizes::css() and holds the two strings equal. */
  function milli(kind, v) {
    v = Math.round(Number(v) || 0);
    if (kind === 'pct') return v * 10;
    if (kind === 'ov') return (100 + v) * 10;
    if (kind === 'px') return v * 1000;
    if (kind === 'px10') return v * 100;
    throw new Error('unknown kind ' + kind);
  }
  function decimal(n, places) {
    var sign = n < 0 ? '-' : '';
    var abs = Math.abs(n);
    var unit = Math.pow(10, places);
    var frac = String(abs % unit);
    while (frac.length < places) frac = '0' + frac;
    frac = frac.replace(/0+$/, '');
    return sign + Math.floor(abs / unit) + (frac === '' ? '' : '.' + frac);
  }
  function fromMilli(kind, m) {
    var out = decimal(m, 3);
    return (kind === 'px' || kind === 'px10') ? out + 'px' : out;
  }
  function emit(kind, v) { return fromMilli(kind, milli(kind, v)); }
  function reduced(kind, v, today, cut) {
    return Math.max(milli(kind, today), Math.floor((milli(kind, v) * (100 - cut) + 50) / 100));
  }
  function int(x) { return Math.trunc(Number(x) || 0); }

  function factors(spec, v, dev, cut) {
    var d = spec.dev[dev];
    var text = int(v[d[0]]);
    var space = v[d[2]] ? text : int(v[d[1]]);
    var ov = function (x) { return cut > 0 ? reduced('ov', x, 0, cut) : milli('ov', x); };
    var roles = {};
    Object.keys(spec.roles).forEach(function (role) {
      var r = spec.roles[role];
      var x = int(v[dev === 'd' ? r[0] : r[1]]);
      roles[role] = cut > 0 ? reduced('pct', x, 100, cut) : milli('pct', x);
    });
    return { t: ov(text), s: ov(space), roles: roles };
  }
  function sameFactors(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

  function stateCss(spec, f, base) {
    var root = [], containers = '', anyRole = false;
    var moved = function (now, was) { return now !== 1000 || (was !== undefined && was !== 1000); };
    if (moved(f.t, base && base.t)) root.push(spec.t + ':' + decimal(f.t, 3));
    if (moved(f.s, base && base.s)) root.push(spec.s + ':' + decimal(f.s, 3));
    Object.keys(spec.roles).forEach(function (role) {
      var r = spec.roles[role], was = base ? base.roles[role] : undefined;
      if (r[3] !== null) { if (moved(f.roles[role], was)) root.push(r[3] + ':' + decimal(f.roles[role], 3)); }
      else if (moved(f.roles[role], was)) anyRole = true;
    });
    if (anyRole) {
      Object.keys(spec.roles).forEach(function (role) {
        var r = spec.roles[role];
        if (r[2] !== null) containers += r[2] + '{' + spec.t + ':' + decimal(f.t * f.roles[role], 6) + '}';
      });
    }
    return (root.length ? spec.scope + '{' + root.join(';') + '}' : '') + containers;
  }

  function wrap(ctx, body, en) {
    if (body === '') return '';
    if (!ctx) return body;
    ctx = ctx.replace(/kbbint\((\w+)\)/g, function (m, k) { return String(int(en[k])); });
    var parts = ctx.match(/@[^@]+/g).map(function (p) { return p.trim(); });
    return parts.join('{') + '{' + body + new Array(parts.length + 1).join('}');
  }

  function typeCss(spec, twins, en, ar) {
    var v = {};
    Object.keys(en).forEach(function (k) { v[k] = en[k]; });
    Object.keys(ar || {}).forEach(function (k) { if (k in v) v[k] = ar[k]; });
    var state = {}, textAll = false, spaceAll = false, moved = {};
    ['d', 'm'].forEach(function (dev) {
      var f = factors(spec, v, dev, 0);
      state[dev] = f;
      textAll = textAll || f.t !== 1000;
      spaceAll = spaceAll || f.s !== 1000;
      Object.keys(f.roles).forEach(function (r) { if (f.roles[r] !== 1000) moved[r] = true; });
    });
    var bp = int(spec.bp), out = '';
    [['d', '@media(min-width:' + (bp + 1) + 'px){'], ['m', '@media(max-width:' + bp + 'px){']].forEach(function (p) {
      var rules = stateCss(spec, state[p[0]]);
      if (rules !== '') out += p[1] + rules + '}';
    });
    var ctx = null, body = '';
    twins.forEach(function (tw) {
      var decls = [];
      if (tw[2] !== '' && (textAll || tw[4].some(function (r) { return moved[r]; }))) decls.push(tw[2]);
      if (tw[3] !== '' && spaceAll) decls.push(tw[3]);
      if (!decls.length) return;
      if (tw[0] !== ctx) { out += wrap(ctx, body, en); ctx = tw[0]; body = ''; }
      body += tw[1] + '{' + decls.join(';') + '}';
    });
    out += wrap(ctx, body, en);
    var old = spec.old || {}, arOld = [];
    Object.keys(old).forEach(function (k) {
      if (int(v[k]) !== int(en[k])) arOld.push(old[k][0] + ':' + emit(old[k][1], int(v[k])) + '!important');
    });
    if (arOld.length) out += spec.scope + '{' + arOld.join(';') + '}';
    var rules = spec.rules || {};
    if (Object.keys(rules).length) {
      var differs = Object.keys(rules).some(function (k) { return int(v[k]) !== int(en[k]); });
      var printed = Object.keys(spec.rowKeys).some(function (k) { return int(en[k]) !== int(spec.rowKeys[k]); });
      if (differs || (textAll && printed)) {
        var desk = '', phone = '';
        Object.keys(rules).forEach(function (k) {
          var r = rules[k];
          var rule = r[0] + '{font-size:calc(' + emit(r[1], int(v[k])) + '*var(' + spec.t + ',1))}';
          if (r[2] === 'm') phone += rule; else desk += rule;
        });
        out += desk + (phone === '' ? '' : '@media (max-width:' + int(en[spec.rulesBp]) + 'px){' + phone + '}');
      }
    }
    [[374, int(v[spec.fit[0]])], [340, int(v[spec.fit[1]])]].forEach(function (step) {
      var cut = step[1];
      if (cut <= 0) return;
      var inner = '';
      var cs = factors(spec, v, 'm', cut);
      if (!sameFactors(cs, state.m)) inner += stateCss(spec, cs, state.m);
      var o = [];
      Object.keys(old).forEach(function (k) {
        var today = int(spec.today[k]);
        if ((spec.phoneOld || []).indexOf(k) !== -1 && int(v[k]) > today) {
          o.push(old[k][0] + ':' + fromMilli(old[k][1], reduced(old[k][1], int(v[k]), today, cut)) + '!important');
        }
      });
      if (o.length) inner += spec.scope + '{' + o.join(';') + '}';
      Object.keys(rules).forEach(function (k) {
        var r = rules[k], today = int(spec.today[k]);
        if (r[2] === 'm' && int(v[k]) > today) {
          inner += r[0] + '{font-size:calc(' + fromMilli(r[1], reduced(r[1], int(v[k]), today, cut)) + '*var(' + spec.t + ',1))!important}';
        }
      });
      if (inner !== '') out += '@media (max-width:' + step[0] + 'px){' + inner + '}';
    });
    (spec.extra || []).forEach(function (x) { if (v[x[0]] === x[1]) out += x[2]; });
    return out;
  }

  /* The Lock links of a spec: [{text, space, lock}] per device. */
  function links(type) {
    var dev = (type.spec && type.spec.dev) || {};
    return Object.keys(dev).map(function (d) { return { text: dev[d][0], space: dev[d][1], lock: dev[d][2] }; });
  }

  /* ------------------------------------------------------------- frames */
  function paintFrame(st, ifr, lang) {
    var doc = ifr.contentDocument;
    if (!doc || !doc.body) return;
    var spec = st.type.spec;
    var en = st.values();
    /* The sizes the style attribute prints, at the values on screen. */
    doc.querySelectorAll(spec.scope).forEach(function (root) {
      Object.keys(spec.old || {}).forEach(function (k) {
        root.style.setProperty(spec.old[k][0], emit(spec.old[k][1], en[k]));
      });
    });
    /* The row rules the cart page prints (kbb-cartrows), font sizes only. */
    var rows = '';
    var rules = spec.rules || {};
    if (Object.keys(rules).length && Object.keys(spec.rowKeys).some(function (k) { return int(en[k]) !== int(spec.rowKeys[k]); })) {
      var phone = '';
      Object.keys(rules).forEach(function (k) {
        var r = rules[k], d = r[0] + '{font-size:' + emit(r[1], en[k]) + '}';
        if (r[2] === 'm') phone += d; else rows += d;
      });
      rows += '@media (max-width:' + int(en[spec.rulesBp]) + 'px){' + phone + '}';
    }
    var srv = doc.getElementById(st.type.styleId);
    if (srv) srv.textContent = '';
    var tag = doc.getElementById('kbb-live-css');
    if (!tag) {
      tag = doc.createElement('style');
      tag.id = 'kbb-live-css';
      doc.body.appendChild(tag);
    }
    tag.textContent = rows + typeCss(spec, st.type.twins, en, lang === 'ar' ? st.ar : {});
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
    links(st.type).forEach(function (l) {
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
    links(st.type).forEach(function (l) {
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
    _typeCss: typeCss, _emit: emit
  };
})();
