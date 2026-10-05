{{--
    Safety -> 404 page.                                                (Lane NF)

    The owner: "for 404, i like the option B's, but keep others too to choose
    on the backend, also give full controls. Keep this under Safety > 404 page >
    desktop / mobile with global elements or functions."

    Global (both devices): the design A/B/C/D, the headline, accent line and
    text in English and Arabic, the "Take me home" button, the search box, the
    four quick links, Trending now, animation and the accent colour. Per device
    (the Desktop | Mobile switch): the illustration on/off and its size, text
    alignment, space above and below, headline size and trending columns.

    LIGHT: one GET when the screen opens brings the settings, the four designs
    (copy, palette and both illustrations) and the page's own stylesheet, so the
    preview is drawn in this browser from data it already has — no request per
    change, no timer, nothing measured. The preview frame is sized by CSS for
    each admin width. One POST on Save.

    Every word the owner types reaches the preview through esc(); the
    illustrations and the sheet come from the server as constants. The preview
    is an <iframe sandbox="allow-same-origin"> — no scripts can run in it.

    Pulled into app.blade.php after pagination-screen by tools/nf-wire.php:
    registers its own sidebar entry (LATE_NAV carries the build-time copy) and
    wraps window.go.
--}}
@verbatim
<style>
.nfx-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 14px}
.nfx-seg{display:inline-flex;background:var(--surface-2,#f1f5f9);border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:3px}
.nfx-seg button{font:inherit;font-size:13px;font-weight:650;border:0;background:none;color:var(--ink-soft,#6b7280);padding:7px 16px;border-radius:999px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.nfx-seg button.on{background:var(--surface,#fff);color:var(--ink,#111827);box-shadow:0 1px 3px rgba(0,0,0,.12)}
.nfx-hint{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.nfx{display:grid;grid-template-columns:minmax(0,1fr) 540px;gap:18px;align-items:start}
.nfx-side{display:grid;gap:14px;min-width:0}
.nfx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;min-width:0}
.nfx-card h3{font-size:15px;font-weight:700;margin:0 0 4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.nfx-tag{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;border-radius:999px;padding:2px 8px;background:#eef2ff;color:#3730a3}
.nfx-tag.dev{background:#fdf2f8;color:#9d174d}
.nfx-help{font-size:12.5px;color:var(--ink-soft,#6b7280);margin:0 0 10px;line-height:1.5}
.nfx-designs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.nfx-design{font:inherit;text-align:start;border:2px solid var(--border,#e6e6e6);border-radius:12px;background:var(--surface,#fff);padding:8px;cursor:pointer;display:grid;gap:6px;min-width:0;color:inherit}
.nfx-design.on{border-color:#E0567B;box-shadow:0 0 0 3px rgba(224,86,123,.15)}
.nfx-design .th{aspect-ratio:4/3;border-radius:8px;display:grid;place-items:center;overflow:hidden;padding:6px}
.nfx-design .th svg{width:100%;height:100%}
.nfx-design .th svg *{animation:none!important}
.nfx-design b{font-size:12.5px;line-height:1.3}
.nfx-design small{font-size:11px;color:#9d174d;font-weight:700}
.nfx-sec{border-top:1px solid var(--border,#eee);padding-top:12px;margin-top:12px}
.nfx-sec:first-of-type{border-top:0;margin-top:0;padding-top:0}
.nfx-sec h4{font-size:13px;font-weight:700;margin:0 0 8px}
.nfx-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:8px 12px;align-items:center;margin:0 0 9px}
.nfx-row>label,.nfx-row>span.l{font-size:12.5px;font-weight:600;color:var(--ink,#111827)}
.nfx-row input[type=text],.nfx-row textarea,.nfx-row select,.nfx-hex{font:inherit;font-size:13px;width:100%;box-sizing:border-box;border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:7px 9px;background:var(--surface,#fff);color:inherit}
.nfx-row textarea{min-height:64px;resize:vertical}
.nfx-row input.bad,.nfx-hex.bad{border-color:#dc2626;background:#fef2f2}
.nfx-n{font-size:11px;color:var(--ink-soft,#6b7280);text-align:end;margin-top:2px}
.nfx-n.bad,.nfx-err{color:#b91c1c}
.nfx-err{font-size:11.5px;margin-top:3px}
.nfx-sw{display:inline-flex;gap:10px;align-items:center;font-size:13px;font-weight:600;cursor:pointer}
.nfx-sw input{width:40px;height:22px;appearance:none;-webkit-appearance:none;border-radius:999px;background:#cbd5e1;position:relative;cursor:pointer;flex:none;margin:0;transition:background .15s}
.nfx-sw input::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s}
.nfx-sw input:checked{background:var(--accent,#15a85a)}
.nfx-sw input:checked::after{transform:translateX(18px)}
.nfx-rng{display:grid;grid-template-columns:minmax(0,1fr) 64px;gap:10px;align-items:center}
.nfx-rng input[type=range]{width:100%;accent-color:#E0567B}
.nfx-rng output{font-size:12.5px;font-weight:650;text-align:end;font-variant-numeric:tabular-nums}
.nfx-link{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(0,1fr);gap:8px;align-items:center;margin:0 0 8px}
.nfx-link input[type=text]{font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:7px 9px;min-width:0;background:var(--surface,#fff);color:inherit}
.nfx-sw.sm{font-size:12.5px;min-width:130px}
.nfx-lang{display:inline-flex;gap:4px;margin:0 0 8px}
.nfx-lang button{font:inherit;font-size:12px;font-weight:650;border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:999px;padding:4px 12px;cursor:pointer;color:inherit}
.nfx-lang button.on{background:#111827;color:#fff;border-color:#111827}
.nfx-sws{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.nfx-sw8{width:30px;height:30px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px var(--border,#ddd);cursor:pointer;padding:0}
.nfx-sw8.on{box-shadow:0 0 0 2px #111827}
.nfx-sw8.def{background:conic-gradient(#E0567B 0 25%,#F2C9A6 0 50%,#D93F69 0 75%,#2E8A5E 0);font-size:0}
.nfx-hex{width:110px!important}
.nfx-cr{font-size:12px;font-weight:650}
.nfx-cr.ok{color:#047857}.nfx-cr.no{color:#b91c1c}
.nfx-prev{position:sticky;top:12px;display:grid;gap:8px;min-width:0}
.nfx-frame{background:#f1f5f9;border:1px solid var(--border,#e6e6e6);border-radius:14px;overflow:hidden;position:relative;margin:0 auto}
.nfx-frame iframe{border:0;display:block;transform-origin:0 0;background:#fff}
.nfx-frame.d{--w:1280;--h:800;--s:.418}
.nfx-frame.m{--w:390;--h:844;--s:.74}
.nfx-frame{width:calc(var(--w) * var(--s) * 1px);height:calc(var(--h) * var(--s) * 1px)}
.nfx-frame iframe{width:calc(var(--w) * 1px);height:calc(var(--h) * 1px);transform:scale(var(--s))}
.nfx-cap{font-size:12px;color:var(--ink-soft,#6b7280);text-align:center}
.nfx-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:12px 16px;margin-top:14px}
.nfx-bar.dirty{position:sticky;bottom:0;box-shadow:0 -8px 24px -12px rgba(0,0,0,.25);z-index:2}
.nfx-bar .ghost{font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:9px;padding:8px 12px;cursor:pointer;color:inherit}
.nfx-status{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.nfx-status.err{color:#b91c1c}
@media (max-width:1280px){.nfx{grid-template-columns:minmax(0,1fr) 400px}.nfx-frame.d{--s:.31}.nfx-frame.m{--s:.66}}
@media (max-width:1060px){.nfx{grid-template-columns:minmax(0,1fr)}.nfx-prev{position:static;order:-1}.nfx-frame.d{--s:.5}.nfx-frame.m{--s:.7}}
@media (max-width:720px){.nfx-frame.d{--s:.268}.nfx-frame.m{--s:.6}.nfx-designs{grid-template-columns:repeat(2,minmax(0,1fr))}.nfx-row{grid-template-columns:1fr}.nfx-link{grid-template-columns:1fr 1fr}.nfx-link .nfx-sw{grid-column:1/-1}.nfx-card{padding:13px}}
</style>

<script>
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
</script>
@endverbatim
