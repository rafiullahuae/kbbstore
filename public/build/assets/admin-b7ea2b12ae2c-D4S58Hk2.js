
(function () {
  'use strict';

  var SCREEN = 'notfoundpage';
  var data = null, cfg = null, dev = 'd', lang = 'en', tlang = 'en';
  var dirty = false, busy = false, status = '', statusErr = false, fieldErr = {};
  var frameReady = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function api(method, body) {
    var r = await fetch(base() + '/admin-api/not-found-page', {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var json = null;
    try { json = await r.json(); } catch (e) { json = null; }
    if (!r.ok) {
      var err = new Error((json && json.error) || ('404 page ' + r.status));
      err.status = r.status; err.fields = (json && json.fields) || {};
      throw err;
    }
    return json;
  }

  function take(json) { data = json; cfg = clone(json.config); dirty = false; fieldErr = {}; }

  async function load() {
    status = ''; statusErr = false;
    try { take(await api('GET')); } catch (e) {
      data = null; statusErr = true;
      status = e && e.status === 403 ? 'Your role cannot change the 404 page. An owner or manager can.'
        : (e && e.status === 404 ? 'This screen is not in the server\'s route table yet. Clear the route cache and reload.'
        : 'Could not load the 404 page settings. Try again in a moment.');
    }
    paint();
  }

  async function save() {
    if (busy || !data || problems().length) return;
    busy = true; status = 'Saving…'; statusErr = false; paintBar();
    try {
      take(await api('POST', { config: cfg }));
      status = 'Saved. The shop shows it on the next missing page.';
    } catch (e) {
      statusErr = true; fieldErr = e.fields || {};
      status = e && e.status === 403 ? 'Your role cannot change the 404 page.' : (e.message || 'Could not save.');
    }
    busy = false; paint();
  }

  /* ── the same checks the server makes, so Save is never a surprise ── */
  function lum(hex) {
    var c = [1, 3, 5].map(function (i) {
      var v = parseInt(hex.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function contrast(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function validLink(u) {
    if (!u || u.length > data.max.url || /[\s<>"'`\\\u0000-\u001f]/.test(u)) return false;
    if (/^\/(?![\/\\])/.test(u)) return true;
    return /^https?:\/\/[^\/?#]+/i.test(u);
  }
  function accentOk() {
    if (!cfg.accent) return true;
    return /^#[0-9A-F]{6}$/.test(cfg.accent) && contrast(cfg.accent, data.designs[cfg.design].bg) >= data.minContrast;
  }
  function problems() {
    var out = [];
    if (!validLink(cfg.home.url)) out.push('home.url');
    if (!accentOk()) out.push('accent');
    ['en', 'ar'].forEach(function (l) {
      ['h', 'em', 's'].forEach(function (k) { if ((cfg.text[l][k] || '').length > data.max[k]) out.push('text.' + l + '.' + k); });
    });
    return out;
  }

  /* ── reading and writing a field by its path ── */
  function get(path) { return path.split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, cfg); }
  function set(path, v) {
    var ks = path.split('.'), o = cfg;
    for (var i = 0; i < ks.length - 1; i++) o = o[ks[i]];
    o[ks[ks.length - 1]] = v;
  }

  /* ── the page, drawn from data this screen already has ── */
  function words() {
    var c = data.designs[cfg.design].copy[lang], t = cfg.text[lang], out = {};
    ['h', 'em', 's'].forEach(function (k) { out[k] = t[k] !== '' ? t[k] : c[k]; });
    if (t.h !== '' && t.em === '') out.em = '';
    return out;
  }
  function label(k) { var own = cfg.links[k][lang]; return own !== '' ? own : data.labels[lang][k]; }

  function pageHTML() {
    var L = data.labels[lang], w = words(), d = cfg.design, cls = ['nf', 'nf-' + d];
    ['m', 'd'].forEach(function (x) {
      if (!cfg[x].art_on) cls.push('nf-noart-' + x);
      if (cfg[x].align !== 'design') cls.push('nf-al-' + x + '-' + cfg[x].align);
    });
    if (!cfg.motion) cls.push('nf-still');
    var st = [];
    ['m', 'd'].forEach(function (x) {
      var v = cfg[x];
      st.push('--nf-art-' + x + ':' + (v.art / 100).toFixed(2), '--nf-pt-' + x + ':' + v.pt + 'px', '--nf-pb-' + x + ':' + v.pb + 'px',
        '--nf-h1-' + x + ':' + v.h1 + 'px', '--nf-cols-' + x + ':' + v.cols);
    });
    if (cfg.accent && accentOk()) st.push('--nf-accent:' + cfg.accent);

    var links = data.icons ? ['shop', 'sale', 'best', 'wa'].filter(function (k) {
      return cfg.links[k].on && (k !== 'wa' || data.whatsapp);
    }) : [];
    var home = cfg.home.on ? '<a class="nf-cta" href="#"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>'
      + esc(cfg.home[lang] !== '' ? cfg.home[lang] : L.home) + '</a>' : '';
    var search = cfg.search ? '<form class="nf-sf"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg><input type="search" placeholder="' + esc(L.search) + '"><button type="button">' + esc(L.go) + '</button></form>' : '';
    var nav = links.length ? '<nav class="nf-links">' + links.map(function (k) {
      return '<a class="nf-' + k + '" href="#">' + data.icons[k] + esc(label(k)) + '</a>';
    }).join('') + '</nav>' : '';
    var trend = '';
    if (cfg.trend.on) {
      var cards = '';
      for (var i = 0; i < cfg.trend.count; i++) {
        cards += '<div class="pv-card"><div class="pv-ph" style="background:' + ['#EAF6EF', '#FFF1E8', '#F4EEFC', '#FFF0F4'][i % 4] + '"></div><i></i><b></b><u></u></div>';
      }
      trend = '<section class="nf-trend"><div class="nf-trend-h"><h2>' + esc(L['trend_' + cfg.trend.list]) + '</h2><a href="#">' + esc(L.see_all) + '</a></div><div class="kbb-pgrid">' + cards + '</div></section>';
    }
    return '<div class="pv-hd"><b>' + esc(data.shop || 'Your shop') + '</b><span>' + (lang === 'ar' ? 'رأس المتجر' : 'Your header, menu and cart') + '</span></div>'
      + '<div class="' + cls.join(' ') + '" style="' + st.join(';') + '"><section class="nf-hero">' + data.designs[d].art[lang]
      + '<div class="nf-copy"><p class="nf-k">' + esc(L.kicker) + '</p><h1 class="nf-h">' + esc(w.h) + (w.em ? ' <em>' + esc(w.em) + '</em>' : '') + '</h1>'
      + (w.s ? '<p class="nf-s">' + esc(w.s) + '</p>' : '') + home + search + nav + '</div></section>' + trend + '</div>'
      + '<div class="pv-ft">' + (lang === 'ar' ? 'تذييل المتجر' : 'Your footer') + '</div>';
  }

  var PREVIEW_CSS = ':root{--bg:#fff;--cream:#FFF8F5;--pink:#E0567B;--pink-deep:#C13E63;--ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--sale:#E23A4E;--sans:"Outfit",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;--site-gutter:16px}'
    + '@media (min-width:901px){:root{--site-gutter:22px}}'
    + '*{box-sizing:border-box}body{margin:0;font:15px/1.6 var(--sans);color:var(--ink);background:#fff}html[lang=ar] body{font-family:"Cairo",var(--sans)}'
    + '.pv-hd{display:flex;align-items:center;gap:12px;height:62px;padding:0 var(--site-gutter);border-bottom:1px solid rgba(42,34,40,.1);font-size:11px;color:var(--muted)}.pv-hd b{font-size:17px;color:var(--pink-deep);font-weight:800}.pv-hd span{margin-inline-start:auto}'
    + '.pv-ft{min-height:120px;background:#2A2228;color:rgba(255,255,255,.55);display:grid;place-items:center;font-size:12px}'
    + '.kbb-pgrid{display:grid;gap:16px}.pv-card{background:#fff;border:1px solid rgba(42,34,40,.1);border-radius:14px;overflow:hidden;padding-bottom:12px}.pv-ph{aspect-ratio:1/1}'
    + '.pv-card i,.pv-card b,.pv-card u{display:block;height:9px;margin:10px 12px 0;border-radius:5px;background:#eee}.pv-card b{height:12px;width:70%}.pv-card u{width:35%;background:#F6C6D3}';

  function drawPreview() {
    var f = document.querySelector('[data-nfx-frame]');
    if (!f || !data) return;
    var doc = frameReady && f.contentDocument;
    if (doc && doc.body) {
      doc.documentElement.lang = lang;
      doc.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
      doc.body.innerHTML = pageHTML();
      return;
    }
    frameReady = false;
    f.onload = function () { frameReady = true; };
    f.srcdoc = '<!doctype html><html lang="' + lang + '" dir="' + (lang === 'ar' ? 'rtl' : 'ltr') + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
      + data.fontCss + PREVIEW_CSS + data.css + '</style></head><body>' + pageHTML() + '</body></html>';
  }

  /* ── controls ── */
  function sw(path, text, small) {
    return '<label class="nfx-sw' + (small ? ' sm' : '') + '"><input type="checkbox" data-nfx-k="' + path + '" data-nfx-t="bool"' + (get(path) ? ' checked' : '') + '> <span>' + esc(text) + '</span></label>';
  }
  function txt(path, ph, max, area) {
    var v = get(path) || '', bad = v.length > max || fieldErr[path];
    var input = area
      ? '<textarea data-nfx-k="' + path + '" data-nfx-t="text" maxlength="' + max + '" placeholder="' + esc(ph) + '"' + (path.indexOf('.ar.') > -1 ? ' dir="rtl"' : '') + '>' + esc(v) + '</textarea>'
      : '<input type="text" data-nfx-k="' + path + '" data-nfx-t="text" maxlength="' + max + '" placeholder="' + esc(ph) + '" value="' + esc(v) + '"' + (path.indexOf('.ar') > -1 ? ' dir="rtl"' : '') + (bad ? ' class="bad"' : '') + '>';
    return input + '<div class="nfx-n' + (bad ? ' bad' : '') + '" data-nfx-n="' + path + '">' + v.length + ' / ' + max + (v === '' ? ' · empty = the design’s own words' : '') + '</div>';
  }
  function sel(path, opts) {
    var v = get(path);
    return '<select data-nfx-k="' + path + '" data-nfx-t="' + (typeof v === 'number' ? 'int' : 'sel') + '">' + opts.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(v) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }
  function rng(path, unit) {
    var r = data.ranges[dev][path.split('.')[1]], v = get(path);
    return '<div class="nfx-rng"><input type="range" min="' + r[0] + '" max="' + r[1] + '" step="1" value="' + v + '" data-nfx-k="' + path + '" data-nfx-t="int" aria-label="' + esc(path) + '"><output data-nfx-o="' + path + '">' + v + unit + '</output></div>';
  }
  function row(lbl, ctl) { return '<div class="nfx-row"><span class="l">' + esc(lbl) + '</span><div>' + ctl + '</div></div>'; }

  function accentHTML() {
    var pal = data.designs[cfg.design].palette, bg = data.designs[cfg.design].bg;
    var cur = cfg.accent || '', r = cur && /^#[0-9A-F]{6}$/.test(cur) ? contrast(cur, bg) : 0;
    return '<div class="nfx-sws"><button type="button" class="nfx-sw8 def' + (cur === '' ? ' on' : '') + '" data-nfx-accent="" title="The design’s own colour">Design</button>'
      + pal.map(function (c) { return '<button type="button" class="nfx-sw8' + (cur === c ? ' on' : '') + '" style="background:' + c + '" data-nfx-accent="' + c + '" title="' + c + '"></button>'; }).join('')
      + '<input class="nfx-hex' + (accentOk() ? '' : ' bad') + '" data-nfx-hex maxlength="7" placeholder="#E0567B" value="' + esc(cur) + '" aria-label="Custom colour">'
      + '<span class="nfx-cr ' + (cur === '' ? '' : (r >= data.minContrast ? 'ok' : 'no')) + '" data-nfx-cr>'
      + (cur === '' ? 'Design colour' : (r ? r.toFixed(1) + ':1 ' + (r >= data.minContrast ? '✓ reads well' : '— too faint, needs ' + data.minContrast + ':1') : 'Use #RRGGBB')) + '</span></div>';
  }

  function controlsHTML() {
    var D = data.designs, c = D[cfg.design].copy;
    var designs = '<div class="nfx-designs">' + ['a', 'b', 'c', 'd'].map(function (k) {
      return '<button type="button" class="nfx-design' + (cfg.design === k ? ' on' : '') + '" data-nfx-design="' + k + '"><span class="th" style="background:' + (k === 'a' ? '#2E1D29' : D[k].bg) + '">' + D[k].art.en + '</span><b>' + k.toUpperCase() + ' · ' + esc(D[k].name) + '</b>' + (k === 'b' ? '<small>Your pick · default</small>' : '') + '</button>';
    }).join('') + '</div>';

    var tl = tlang, cp = D[cfg.design].copy[tl];
    var text = '<div class="nfx-lang">' + [['en', 'English'], ['ar', 'العربية']].map(function (o) {
      return '<button type="button" data-nfx-tlang="' + o[0] + '" class="' + (tl === o[0] ? 'on' : '') + '">' + o[1] + '</button>';
    }).join('') + '</div>'
      + row('Headline', txt('text.' + tl + '.h', cp.h, data.max.h))
      + row('Accent line', txt('text.' + tl + '.em', cp.em, data.max.em))
      + row('Text', txt('text.' + tl + '.s', cp.s, data.max.s, true));

    var homeBad = !validLink(cfg.home.url);
    var home = '<div class="nfx-sec"><h4>“Take me home” button</h4>' + sw('home.on', 'Show the button')
      + '<div style="margin-top:9px">' + row('Label · English', txt('home.en', data.labels.en.home, data.max.label))
      + row('Label · العربية', txt('home.ar', data.labels.ar.home, data.max.label))
      + row('Link', '<input type="text" data-nfx-k="home.url" data-nfx-t="text" maxlength="' + data.max.url + '" value="' + esc(cfg.home.url) + '"' + (homeBad ? ' class="bad"' : '') + '><div class="nfx-err" data-nfx-urlerr>' + (homeBad ? 'Use a path on this shop (starting with /) or a full https:// address.' : '') + '</div>') + '</div></div>';

    var linkNames = { shop: 'Shop all', sale: 'Super Sale', best: 'Best sellers', wa: 'WhatsApp us' };
    var links = '<div class="nfx-sec"><h4>Search and quick links</h4>' + sw('search', 'Search box') + '<div style="height:10px"></div>'
      + ['shop', 'sale', 'best', 'wa'].map(function (k) {
        return '<div class="nfx-link">' + sw('links.' + k + '.on', linkNames[k], true)
          + '<input type="text" data-nfx-k="links.' + k + '.en" data-nfx-t="text" maxlength="' + data.max.label + '" placeholder="' + esc(data.labels.en[k]) + '" value="' + esc(cfg.links[k].en) + '" aria-label="' + linkNames[k] + ' label, English">'
          + '<input type="text" dir="rtl" data-nfx-k="links.' + k + '.ar" data-nfx-t="text" maxlength="' + data.max.label + '" placeholder="' + esc(data.labels.ar[k]) + '" value="' + esc(cfg.links[k].ar) + '" aria-label="' + linkNames[k] + ' label, Arabic"></div>';
      }).join('')
      + '<p class="nfx-help">WhatsApp uses the shop’s own WhatsApp number' + (data.whatsapp ? '.' : ' — none is set, so the link stays hidden until one is.') + ' An empty label uses the wording shown in grey.</p></div>';

    var trend = '<div class="nfx-sec"><h4>Trending now</h4>' + sw('trend.on', 'Show a product strip under the page')
      + '<div style="margin-top:9px">' + row('Shows', sel('trend.list', [['best', 'Best sellers'], ['new', 'New in'], ['sale', 'Super Sale']]))
      + row('How many', sel('trend.count', [[4, '4 products'], [6, '6 products'], [8, '8 products']])) + '</div>'
      + '<p class="nfx-help">Your real products on the shop (grey cards here). One database query, kept for an hour.</p></div>';

    var look = '<div class="nfx-sec"><h4>Look</h4>' + sw('motion', 'Animation (always off for visitors who ask their phone for less motion)')
      + '<div style="margin-top:10px">' + row('Accent colour', accentHTML()) + '</div></div>';

    var dn = dev === 'd' ? 'Desktop' : 'Mobile';
    var device = '<div class="nfx-card"><h3>' + dn + ' <span class="nfx-tag dev">' + (dev === 'd' ? 'wider than 900px' : '900px and narrower') + '</span></h3>'
      + '<p class="nfx-help">These apply to ' + dn.toLowerCase() + ' only. Switch at the top to set the other.</p>'
      + row('Illustration', sw(dev + '.art_on', 'Show it'))
      + row('Illustration size', rng(dev + '.art', '%'))
      + row('Text alignment', sel(dev + '.align', [['design', 'As the design'], ['start', 'Start'], ['center', 'Centre']]))
      + row('Space above', rng(dev + '.pt', 'px'))
      + row('Space below', rng(dev + '.pb', 'px'))
      + row('Headline size', rng(dev + '.h1', 'px'))
      + row('Trending columns', rng(dev + '.cols', '')) + '</div>';

    return '<div class="nfx-card"><h3>Design</h3><p class="nfx-help">All four ship; the shop shows the one you pick.</p>' + designs + '</div>'
      + device
      + '<div class="nfx-card"><h3>Global <span class="nfx-tag">Desktop and mobile</span></h3>'
      + '<div class="nfx-sec"><h4>Words</h4><p class="nfx-help">Leave a field empty to keep the design’s own words (shown in grey). Plain text.</p>' + text + '</div>'
      + home + links + trend + look + '</div>';
  }

  function topHTML() {
    return '<div class="nfx-top"><div class="nfx-seg" role="tablist" aria-label="Device">'
      + [['d', 'Desktop'], ['m', 'Mobile']].map(function (o) {
        return '<button type="button" role="tab" aria-selected="' + (dev === o[0]) + '" class="' + (dev === o[0] ? 'on' : '') + '" data-nfx-dev="' + o[0] + '">' + o[1] + '</button>';
      }).join('') + '</div><div class="nfx-seg" aria-label="Preview language">'
      + [['en', 'EN'], ['ar', 'AR']].map(function (o) {
        return '<button type="button" class="' + (lang === o[0] ? 'on' : '') + '" data-nfx-lang="' + o[0] + '">' + o[1] + '</button>';
      }).join('') + '</div><span class="nfx-hint">Desktop / Mobile picks the sizes you edit and the preview width. Global settings apply to both.</span></div>';
  }

  function paintBar() {
    var host = document.querySelector('[data-nfx-bar]');
    if (!host) return;
    var bad = data ? problems().length : 0;
    host.classList.toggle('dirty', dirty);
    host.innerHTML = '<button type="button" class="btn" data-nfx-save' + (busy || !data || !dirty || bad ? ' disabled' : '') + '>Save</button>'
      + '<button type="button" class="ghost" data-nfx-reset' + (busy || !data ? ' disabled' : '') + '>Reset to defaults</button>'
      + '<span class="nfx-status' + (statusErr || bad ? ' err' : '') + '" role="status" aria-live="polite">'
      + esc(status || (bad ? 'Fix the field marked in red to save.' : (dirty ? 'Unsaved changes.' : ''))) + '</span>';
  }

  function paintControls() {
    var host = document.querySelector('[data-nfx-side]');
    if (host) host.innerHTML = controlsHTML();
  }

  function paint() {
    var host = document.getElementById('content');
    if (!host) return;
    if (!host.querySelector('[data-nfx]')) {
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>404 page</h2>'
        + '<p>What a shopper sees on an address that does not exist — still a real 404 for search engines. Your stored redirects are tried first.</p></div><div data-nfx></div></div>';
    }
    var root = host.querySelector('[data-nfx]');
    if (!data) {
      root.innerHTML = '<div class="nfx-card"><div class="nfx-help' + (statusErr ? ' nfx-err' : '') + '">' + esc(status || 'Loading…') + '</div></div>';
      return;
    }
    frameReady = false;
    root.innerHTML = topHTML() + '<div class="nfx"><div class="nfx-side" data-nfx-side>' + controlsHTML() + '</div>'
      + '<div class="nfx-prev"><div class="nfx-frame ' + dev + '" data-nfx-box><iframe data-nfx-frame sandbox="allow-same-origin" title="Preview of the 404 page" tabindex="-1"></iframe></div>'
      + '<div class="nfx-cap">' + (dev === 'd' ? 'Desktop, 1280px' : 'Phone, 390px') + ' · ' + (lang === 'ar' ? 'العربية' : 'English') + ' · live preview, nothing is saved until you press Save</div></div></div>'
      + '<div class="nfx-bar" data-nfx-bar></div>';
    drawPreview();
    paintBar();
  }

  function changed(redrawControls) {
    dirty = true; status = ''; statusErr = false;
    if (redrawControls) paintControls();
    drawPreview(); paintBar();
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-nfx]') || !data) return;
    if (t.hasAttribute('data-nfx-hex')) {
      var v = t.value.trim().toUpperCase();
      cfg.accent = v === '#' ? '' : v;
      t.classList.toggle('bad', !accentOk());
      var cr = document.querySelector('[data-nfx-cr]'), r = /^#[0-9A-F]{6}$/.test(v) ? contrast(v, data.designs[cfg.design].bg) : 0;
      if (cr) { cr.className = 'nfx-cr ' + (r >= data.minContrast ? 'ok' : 'no'); cr.textContent = r ? r.toFixed(1) + ':1 ' + (r >= data.minContrast ? '✓ reads well' : '— too faint, needs ' + data.minContrast + ':1') : 'Use #RRGGBB'; }
      changed(false);
      return;
    }
    var k = t.getAttribute('data-nfx-k'), ty = t.getAttribute('data-nfx-t');
    if (!k || (ty !== 'text' && ty !== 'int')) return;
    if (ty === 'int') {
      set(k, parseInt(t.value, 10));
      var o = document.querySelector('[data-nfx-o="' + k + '"]');
      if (o) o.textContent = t.value + (/art$/.test(k) ? '%' : (/cols$/.test(k) ? '' : 'px'));
    } else {
      set(k, t.value);
      var n = document.querySelector('[data-nfx-n="' + k + '"]');
      var max = parseInt(t.getAttribute('maxlength'), 10);
      if (n) n.textContent = t.value.length + ' / ' + max + (t.value === '' ? ' · empty = the design’s own words' : '');
      if (k === 'home.url') {
        var bad = !validLink(t.value);
        t.classList.toggle('bad', bad);
        var er = document.querySelector('[data-nfx-urlerr]');
        if (er) er.textContent = bad ? 'Use a path on this shop (starting with /) or a full https:// address.' : '';
      }
    }
    changed(false);
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-nfx]') || !data) return;
    var k = t.getAttribute('data-nfx-k'), ty = t.getAttribute('data-nfx-t');
    if (!k) return;
    if (ty === 'bool') { set(k, !!t.checked); changed(false); }
    else if (ty === 'sel') { set(k, t.value); changed(false); }
    else if (ty === 'int' && t.tagName === 'SELECT') { set(k, parseInt(t.value, 10)); changed(false); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t || !t.closest('[data-nfx]') || !data) return;
    var b;
    if ((b = t.closest('[data-nfx-design]'))) {
      cfg.design = b.getAttribute('data-nfx-design');
      if (cfg.accent && !accentOk()) cfg.accent = '';
      changed(true);
    } else if ((b = t.closest('[data-nfx-dev]'))) {
      dev = b.getAttribute('data-nfx-dev'); paint();
    } else if ((b = t.closest('[data-nfx-lang]'))) {
      lang = b.getAttribute('data-nfx-lang'); tlang = lang; paint();
    } else if ((b = t.closest('[data-nfx-tlang]'))) {
      tlang = b.getAttribute('data-nfx-tlang'); paintControls();
    } else if ((b = t.closest('[data-nfx-accent]'))) {
      cfg.accent = b.getAttribute('data-nfx-accent'); changed(true);
    } else if ((b = t.closest('[data-nfx-save]')) && !b.disabled) {
      save();
    } else if ((b = t.closest('[data-nfx-reset]')) && !b.disabled) {
      cfg = clone(data.defaults); fieldErr = {};
      changed(true);
      status = 'Defaults restored here — press Save to put them on the shop.'; paintBar();
    }
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: '404 page',
      icon: '<circle cx="12" cy="12" r="9"/><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M8.5 16c1.2-1 2.3-1 3.5 0s2.3 1 3.5 0"/><path d="M13 3l-2 4 2 2-1 3"/>',
      group: 'Safety',
      after: ['democontent', 'sandbox', 'debug']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Safety"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Safety';
    if (title) title.textContent = '404 page';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    data = null;
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
