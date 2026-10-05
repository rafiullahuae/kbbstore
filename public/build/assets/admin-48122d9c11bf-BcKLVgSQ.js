
(function () {
  'use strict';

  var SCREEN = 'pagebanners';
  var TITLE = 'Page banners';

  var data = null, cur = 0, open = 'banners', dev = 'm', banner = null, busy = false, seq = 0, bad = {}, cssIn = false;

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Pages → Page banners',
    values: function () { return data ? { state: JSON.stringify(state()) } : null; },
    set: function (k, v) { if (k === 'state' && data) { try { var s = JSON.parse(v); data.banners = s.banners; data.assign = s.assign; data.super_sale.source = s.super_sale_source; } catch (e) {} } },
    render: function () { render(); },
    save: function () { save(); }
  });

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  async function api(body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/page-banners', opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('page-banners ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function say(m) { try { window.toast(m); } catch (e) {} }
  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Page banners endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change page banners. An owner, manager or editor can.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  /* Same test PageBanners::linkIsSafe() makes; the server makes it again. */
  function linkOk(v) {
    v = String(v || '').trim();
    if (v === '') return true;
    if (v.length > 500 || /[\x00-\x20\x7f"'<>`\\]/.test(v)) return false;
    if (v.charAt(0) === '/' && v.charAt(1) !== '/') return true;
    return /^https?:\/\/[^\/?#@\s]/i.test(v);
  }
  function picOk(v) {
    v = String(v || '').trim();
    return v === '' || (v.length <= 500 && !/[\x00-\x20"'<>`\\]/.test(v) && (/^https?:\/\//i.test(v) || (v.charAt(0) === '/' && v.charAt(1) !== '/')));
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Pages"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Pages';
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    render();
    load();
    return undefined;
  };

  function state() {
    return { banners: data.banners, assign: data.assign, super_sale_source: data.super_sale.source };
  }

  function take(body) {
    data = body;
    if (Array.isArray(data.assign)) data.assign = {};
    if (cur >= data.banners.length) cur = Math.max(0, data.banners.length - 1);
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api();
      if (mine !== seq) return;
      take(body); bad = {};
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The page banners could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); if (data && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN); }
    }
  }

  async function save() {
    if (busy || !data) return;
    busy = true; bad = {}; banner = null; render();
    try {
      var body = await api(state());
      take(body);
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say('Page banners saved. They are on the shop now.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
      ((e && e.body && e.body.rejected) || []).forEach(function (k) { bad[k] = true; });
    } finally {
      busy = false; render();
    }
  }

  function b() { return data && data.banners[cur]; }

  /* ── THE PREVIEW: partials/page-banner.blade.php, built here ───────────── */

  function stage() {
    var x = b();
    if (!x) return '<div class="pbs-empty">No banner selected.</div>';
    var m = dev === 'm';
    var src = m ? (x.img_m || x.img_d) : (x.img_d || x.img_m);
    var style = '--pb-bg:' + x.bg + ';--pb-ink:' + x.ink + ';--pb-ic:' + x.icon
      + ';--pb-hd:' + (m ? x.sh_m : x.sh_d) + 'px;--pb-hm:' + x.sh_m + 'px'
      + ';--pb-fd:' + (m ? x.fs_m : x.fs_d) + 'px;--pb-fm:' + x.fs_m + 'px'
      + ';--pb-id:' + (m ? x.ic_m : x.ic_d) + 'px;--pb-im:' + x.ic_m + 'px';
    var img = src && picOk(src)
      ? '<div class="kbb-pb-img"><img src="' + esc(src) + '" alt="' + esc(x.alt) + '"></div>'
      : '<div class="pbs-slot">No ' + (m ? 'phone' : 'desktop') + ' picture yet — the shop draws no picture here, only the strip. Choose one under Banners → Picture.</div>';
    /* An item for one device only is left out of the other's preview, as the
       shop's .kbb-pb-d / .kbb-pb-m rules leave it out. (Lane PH) */
    var items = x.strip && x[m ? 'strip_m' : 'strip_d'] !== false ? (x.items || []).filter(function (i) { return String(i.en || '').trim() !== '' && (i.dev || 'both') !== (m ? 'd' : 'm'); }) : [];
    var strip = items.length ? '<ul class="kbb-pb-strip">' + items.map(function (i) {
      return '<li>' + (data.icon || '') + '<span>' + esc(i.en) + '</span></li>';
    }).join('') + '</ul>' : '';
    return '<div class="pbs-stage" data-pbs-dev="' + dev + '"><div class="kbb-pb" style="' + esc(style) + '">' + img + strip + '</div>'
      + '<div class="pbs-fake"><i></i><i></i><i></i><i></i><i></i></div></div>';
  }

  function drawStage() {
    var host = document.querySelector('[data-pbs-stagehost]');
    if (host) host.innerHTML = stage();
  }

  /* ── THE CONTROLS ─────────────────────────────────────────────────────── */

  function badCls(k) { return bad['banners.' + cur + '.' + k] ? ' is-bad' : ''; }

  function textIn(k, label, help, max, ph) {
    var x = b();
    return '<div class="pbs-f"><label for="pbs-' + k + '">' + esc(label) + '</label>'
      + '<input class="pbs-in' + badCls(k) + '" id="pbs-' + k + '" data-pbs-k="' + k + '" maxlength="' + max + '" value="' + esc(x[k]) + '"' + (ph ? ' placeholder="' + esc(ph) + '"' : '') + '>'
      + (help ? '<p class="pbs-help">' + help + '</p>' : '') + '</div>';
  }

  function picBox(k, label, help) {
    var x = b(), v = x[k];
    return '<div class="pbs-pic"><div class="pbs-lbl">' + esc(label) + '</div>'
      + '<div class="pbs-thumb">' + (v && picOk(v) ? '<img src="' + esc(v) + '" alt="">' : 'No picture — nothing is drawn') + '</div>'
      + '<div class="pbs-bar" style="margin-top:0"><button type="button" class="pbs-btn" data-pbs-pick="' + k + '">Choose from Media Library</button>'
      + (v ? '<button type="button" class="pbs-btn" data-pbs-unpick="' + k + '">Remove</button>' : '') + '</div>'
      + '<p class="pbs-help">' + help + '</p></div>';
  }

  function bannersTab() {
    var x = b();
    var list = '<div class="pbs-list">' + data.banners.map(function (y, i) {
      return '<button type="button" class="pbs-btn" data-pbs-pickb="' + i + '" aria-pressed="' + (i === cur) + '">' + esc(y.name) + '</button>';
    }).join('') + '<button type="button" class="pbs-btn" data-pbs-add' + (data.banners.length >= data.limits.banners ? ' disabled' : '') + '>+ New banner</button></div>';
    if (!x) return list + '<p class="pbs-help" style="margin-top:10px">No banners yet. Add one, then choose where it shows.</p>';

    var used = data.pages.filter(function (p) { return data.assign[p.key] === x.id; }).map(function (p) { return p.label; });

    var items = (x.items || []).map(function (it, i) {
      return '<div class="pbs-item">'
        + '<input class="pbs-in" data-pbs-item="' + i + '" data-pbs-lang="en" maxlength="' + data.limits.text + '" value="' + esc(it.en) + '" aria-label="Item ' + (i + 1) + ' text">'
        + '<input class="pbs-in" dir="rtl" lang="ar" data-pbs-item="' + i + '" data-pbs-lang="ar" maxlength="' + data.limits.text + '" value="' + esc(it.ar) + '" placeholder="Arabic (optional)" aria-label="Item ' + (i + 1) + ' Arabic">'
        + '<select class="pbs-in" data-pbs-idev="' + i + '" aria-label="Item ' + (i + 1) + ' shows on">' + (data.devices || []).map(function (d) {
          return '<option value="' + esc(d.value) + '"' + ((it.dev || 'both') === d.value ? ' selected' : '') + '>' + esc(d.label) + '</option>';
        }).join('') + '</select>'
        + '<span class="pbs-mini"><button type="button" data-pbs-move="-1" data-pbs-i="' + i + '" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" data-pbs-move="1" data-pbs-i="' + i + '" aria-label="Move down"' + (i === x.items.length - 1 ? ' disabled' : '') + '>↓</button>'
        + '<button type="button" data-pbs-del="' + i + '" aria-label="Remove item">✕</button></span></div>';
    }).join('');

    var colours = data.colours.map(function (c) {
      return '<div class="pbs-f"><label for="pbs-c-' + c.key + '">' + esc(c.label) + '</label><div class="pbs-colour">'
        + '<input type="color" data-pbs-colour="' + c.key + '" value="' + esc(x[c.key]) + '" aria-label="' + esc(c.label) + ' picker">'
        + '<input class="pbs-in' + badCls(c.key) + '" id="pbs-c-' + c.key + '" data-pbs-k="' + c.key + '" maxlength="7" value="' + esc(x[c.key]) + '"></div></div>';
    }).join('');

    var ranges = data.numbers.map(function (n) {
      return '<div class="pbs-range"><div class="pbs-fh"><label for="pbs-n-' + n.key + '">' + esc(n.label) + '</label><span class="pbs-val" data-pbs-val="' + n.key + '">' + esc(x[n.key]) + 'px</span></div>'
        + '<input type="range" id="pbs-n-' + n.key + '" data-pbs-num="' + n.key + '" min="' + n.min + '" max="' + n.max + '" step="1" value="' + esc(x[n.key]) + '"></div>';
    }).join('');

    return list
      + '<div class="pbs-sec"><div class="pbs-two">' + textIn('name', 'Banner name', 'Only you see this; it names the banner in the lists.', 60)
      + '<div class="pbs-f"><span class="pbs-lbl">Shows on</span><p class="pbs-help" style="padding-top:8px">' + (used.length ? esc(used.join(' · ')) : 'No page yet — choose under <b>Where they show</b>.') + '</p></div></div></div>'
      + '<div class="pbs-sec"><h4>Picture</h4>'
      + '<p class="pbs-help">The whole picture, words and all, exactly as you upload it — it is not cropped. Use a wide picture for desktop and a taller one for phones; with only one, it is used on both.</p>'
      + '<div class="pbs-two">' + picBox('img_d', 'Desktop picture', 'Wider than ' + data.breakpoint + 'px. About 1920 × 600 works well.')
      + picBox('img_m', 'Phone picture', data.breakpoint + 'px and narrower. About 1080 × 720 works well.') + '</div>'
      + textIn('alt', 'Alt text', 'What the picture says, for screen readers and search — for example “Super Sale, coupon code GLOW”.', 160)
      + textIn('link', 'Link (optional)', 'Where a tap on the picture goes: a page on this shop like <code>/shop/</code>, or a full https:// address. Empty = the picture is not a link.', 500, '/shop/')
      + '</div>'
      + '<div class="pbs-sec"><h4>Strip beneath the picture</h4>'
      + '<label class="pbs-f" style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-pbs-strip' + (x.strip ? ' checked' : '') + '> <span class="pbs-lbl">Show the strip</span></label>'
      + '<div class="pbs-f" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-pbs-sdev="strip_d"' + (x.strip_d !== false ? ' checked' : '') + '> <span>On desktop</span></label>'
      + '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-pbs-sdev="strip_m"' + (x.strip_m !== false ? ' checked' : '') + '> <span>On mobile</span></label></div>'
      + items
      + '<div class="pbs-bar" style="margin-top:0"><button type="button" class="pbs-btn" data-pbs-additem' + ((x.items || []).length >= data.limits.items ? ' disabled' : '') + '>+ Add an item</button></div>'
      + '<p class="pbs-help">Each item is a tick and a line of text, spread evenly across the strip. Up to ' + data.limits.items + '. The Arabic line is used on the Arabic shop; empty = the English line. <b>Shows on</b> puts an item on desktop only or phone only — for example a third line on desktop while a phone keeps two.</p>'
      + '<div class="pbs-three">' + colours + '</div>'
      + '<div class="pbs-two">' + ranges + '</div>'
      + '</div>'
      + '<div class="pbs-actions" style="margin-top:12px"><button type="button" class="pbs-btn" data-pbs-reset>Reset the strip to defaults</button>'
      + '<button type="button" class="pbs-btn is-danger" data-pbs-remove>Delete this banner</button></div>';
  }

  function pagesTab() {
    var opts = function (sel) {
      return '<option value="">No banner</option>' + data.banners.map(function (y) {
        return '<option value="' + esc(y.id) + '"' + (sel === y.id ? ' selected' : '') + '>' + esc(y.name) + '</option>';
      }).join('');
    };
    return '<p class="pbs-sub" style="margin-top:0">Custom pages only — product, category and brand pages have their own banners. A page with “No banner” is exactly as it was.</p>'
      + '<table class="pbs-table"><thead><tr><th>Page</th><th>Address</th><th>Banner</th></tr></thead><tbody>'
      + data.pages.map(function (p) {
        return '<tr><td>' + esc(p.label) + '</td><td><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.path) + '</a></td>'
          + '<td><select class="pbs-in' + (bad['assign.' + p.key] ? ' is-bad' : '') + '" data-pbs-assign="' + esc(p.key) + '" aria-label="Banner on ' + esc(p.label) + '">' + opts(data.assign[p.key] || '') + '</select></td></tr>';
      }).join('') + '</tbody></table>';
  }

  // (2.60.388) The old site's exact order, copied by the server in one click.
  function copiedHTML(s) {
    var c = s.copied || { count: 0 };
    var note = c.count
      ? '<div class="pbs-ok"><b>' + esc(c.count) + '</b> products are in the old site\'s exact order' + (c.at ? ' (copied ' + esc(String(c.at).slice(0, 10)) + ')' : '') + '. Products it does not list follow after them, in the Reorder order.'
        + (c.missing_total ? '<br>Not in this shop yet (' + esc(c.missing_total) + '): ' + c.missing.map(esc).join(', ') + (c.missing_total > c.missing.length ? '…' : '') : '') + '</div>'
      : '<p class="pbs-help">Not copied yet: /super-sale/ uses the Reorder order.</p>';
    return '<div class="pbs-f" style="margin-top:14px"><label>Order from the old site</label>' + note
      + '<div class="pbs-actions" style="margin-top:8px"><button type="button" class="pbs-btn is-primary" data-pbs-copyorder' + (copying ? ' disabled' : '') + '>' + (copying ? 'Copying…' : 'Copy the order from kbeautybliss.com/super-sale/') + '</button>'
      + (c.count ? '<button type="button" class="pbs-btn" data-pbs-clearorder' + (copying ? ' disabled' : '') + '>Use the Reorder order instead</button>' : '') + '</div>'
      + '<p class="pbs-help">The server reads the old page and puts the same products in the same places here. Only /super-sale/ changes; every other page keeps its order.</p></div>';
  }

  var copying = false;
  async function copyOrder(action) {
    if (copying) return;
    copying = true; render();
    try {
      var opts = { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin', body: JSON.stringify({ action: action }) };
      var r = await fetch(root() + '/admin-api/page-banners/super-sale-order', opts);
      var body = null; try { body = await r.json(); } catch (e) {}
      if (!r.ok) { say((body && body.message) || 'The order could not be copied.'); return; }
      if (body && body.super_sale && data) data.super_sale = body.super_sale;
      say(action === 'copy' ? 'Copied: ' + (body.copied || 0) + ' products in the old order.' : '/super-sale/ uses the Reorder order again.');
    } catch (e) {
      say('The order could not be copied.');
    } finally {
      copying = false; render();
    }
  }

  function saleTab() {
    var s = data.super_sale;
    var status = s.source === 'on_sale'
      ? '<div class="pbs-ok">/super-sale/ lists every reduced product, biggest discount first.</div>'
      : (s.falls_back
        ? '<div class="pbs-note">' + (s.category_found ? 'The category has no visible product yet' : 'There is no category slugged <b>super-sale</b> yet') + ', so /super-sale/ is listing every reduced product until it has some. Create or fill it, then reload this tab.</div>'
        : '<div class="pbs-ok"><b>' + esc(s.products) + '</b> product' + (s.products === 1 ? '' : 's') + ' on /super-sale/, in the category\'s Reorder order — the order the old site used.</div>');
    return '<div class="pbs-f"><label for="pbs-source">Products on /super-sale/</label>'
      + '<select class="pbs-in' + (bad.super_sale_source ? ' is-bad' : '') + '" id="pbs-source" data-pbs-source>' + s.options.map(function (o) {
        return '<option value="' + esc(o.value) + '"' + (o.value === s.source ? ' selected' : '') + '>' + esc(o.label) + '</option>';
      }).join('') + '</select></div>'
      + '<div style="margin-top:10px">' + status + '</div>'
      + copiedHTML(s)
      + '<p class="pbs-help" style="margin-top:10px"><b>Running the campaign.</b> Add or remove a product: <b>Catalog → Product editor → Categories</b>, tick or untick <b>Super Sale</b>. Change the order: <b>Catalog → Catalog → Reorder</b>, choose <b>Super Sale</b>, drag, Save. That order is shared with every category the product is in, exactly as on the old site.</p>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== TITLE) return;

    if (busy && !data) { host.innerHTML = '<div class="pbs-wrap"><div class="pbs-card"><div class="pbs-empty">Loading…</div></div></div>'; return; }
    if (!data) {
      host.innerHTML = '<div class="pbs-wrap"><div class="pbs-card"><div class="pbs-title">' + TITLE + '</div><p class="pbs-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="pbs-actions"><button type="button" class="pbs-btn" data-pbs-reload>Retry</button></div></div></div>';
      return;
    }
    if (data.css && !cssIn) {
      var st = document.createElement('style'); st.id = 'pbs-shop-css'; st.textContent = data.css; document.head.appendChild(st); cssIn = true;
    }

    var tabs = [['banners', 'Banners'], ['pages', 'Where they show'], ['sale', 'Super Sale products']];
    var body = open === 'pages' ? pagesTab() : (open === 'sale' ? saleTab() : bannersTab());

    host.innerHTML = '<div class="pbs-wrap"><div class="pbs-card">'
      + '<div class="pbs-title">' + TITLE + '</div>'
      + '<p class="pbs-sub">A promo picture with a thin strip of ticks beneath it, for the custom pages — /super-sale/ and the content pages. Nothing reaches the shop until you press Save.</p>'
      + (banner ? '<div class="pbs-note" style="margin-top:10px">' + esc(banner) + '</div>' : '')
      + '<div class="pbs-tabs" role="tablist">' + tabs.map(function (t) {
        return '<button type="button" class="pbs-tab" role="tab" data-pbs-tab="' + t[0] + '" aria-selected="' + (open === t[0]) + '">' + t[1] + '</button>';
      }).join('') + '</div>'
      + '<p class="ectabs-hint pbs-help" style="margin-top:8px"><b>Banners</b> is the pictures and strips themselves; <b>Where they show</b> puts one on a page; <b>Super Sale products</b> chooses what /super-sale/ lists. One Save stores all three.</p>'
      + '</div>'
      + '<div class="pbs-grid"><div class="pbs-card">' + body
      + '<div class="pbs-actions"><button type="button" class="pbs-btn is-primary" data-pbs-save' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button type="button" class="pbs-btn" data-pbs-reload' + (busy ? ' disabled' : '') + '>Reload</button></div></div>'
      + '<div class="pbs-card pbs-sticky"><div class="pbs-title">Preview</div><p class="pbs-sub">The shop\'s own markup and stylesheet' + (b() ? ' for “' + esc(b().name) + '”' : '') + '.</p>'
      + '<div class="pbs-bar"><button type="button" class="pbs-btn" data-pbs-dev="m" aria-pressed="' + (dev === 'm') + '">Phone</button><button type="button" class="pbs-btn" data-pbs-dev="d" aria-pressed="' + (dev === 'd') + '">Desktop</button></div>'
      + '<div data-pbs-stagehost></div></div></div></div>';

    drawStage();
  }


  document.addEventListener('input', function (e) {
    if (!data || !e.target.closest) return;
    var x = b(), el;
    if ((el = e.target.closest('[data-pbs-k]')) && x) {
      var k = el.getAttribute('data-pbs-k');
      x[k] = el.value;
      if (/^(bg|ink|icon)$/.test(k) && /^#[0-9a-f]{6}$/i.test(el.value)) { var p = document.querySelector('[data-pbs-colour="' + k + '"]'); if (p) p.value = el.value; }
      if (k === 'link') el.classList.toggle('is-bad', !linkOk(el.value));
      drawStage(); return;
    }
    if ((el = e.target.closest('[data-pbs-colour]')) && x) {
      var c = el.getAttribute('data-pbs-colour');
      x[c] = el.value.toUpperCase();
      var t = document.querySelector('[data-pbs-k="' + c + '"]'); if (t) t.value = x[c];
      drawStage(); return;
    }
    if ((el = e.target.closest('[data-pbs-num]')) && x) {
      var n = el.getAttribute('data-pbs-num');
      x[n] = Number(el.value);
      var o = document.querySelector('[data-pbs-val="' + n + '"]'); if (o) o.textContent = el.value + 'px';
      drawStage(); return;
    }
    if ((el = e.target.closest('[data-pbs-item]')) && x) {
      x.items[Number(el.getAttribute('data-pbs-item'))][el.getAttribute('data-pbs-lang') === 'ar' ? 'ar' : 'en'] = el.value;
      drawStage();
    }
  });

  document.addEventListener('change', function (e) {
    if (!data || !e.target.closest) return;
    var el;
    if ((el = e.target.closest('[data-pbs-assign]'))) { data.assign[el.getAttribute('data-pbs-assign')] = el.value; return; }
    if ((el = e.target.closest('[data-pbs-source]'))) { data.super_sale.source = el.value; return; }
    if ((el = e.target.closest('[data-pbs-strip]')) && b()) { b().strip = el.checked; drawStage(); return; }
    if ((el = e.target.closest('[data-pbs-sdev]')) && b()) { b()[el.getAttribute('data-pbs-sdev') === 'strip_m' ? 'strip_m' : 'strip_d'] = el.checked; drawStage(); return; }
    if ((el = e.target.closest('[data-pbs-idev]')) && b()) { b().items[Number(el.getAttribute('data-pbs-idev'))].dev = el.value; drawStage(); }
  });

  function newId() {
    var n = 1, ids = data.banners.map(function (y) { return y.id; });
    while (ids.indexOf('banner-' + n) !== -1) n++;
    return 'banner-' + n;
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var el;
    if ((el = e.target.closest('[data-pbs-tab]'))) { open = el.getAttribute('data-pbs-tab'); render(); return; }
    if ((el = e.target.closest('button[data-pbs-dev]'))) { dev = el.getAttribute('data-pbs-dev') === 'd' ? 'd' : 'm'; render(); return; }
    if (e.target.closest('[data-pbs-save]')) { save(); return; }
    if (e.target.closest('[data-pbs-reload]')) { load(); return; }
    if (e.target.closest('[data-pbs-copyorder]')) { copyOrder('copy'); return; }
    if (e.target.closest('[data-pbs-clearorder]')) { copyOrder('clear'); return; }
    if (!data) return;
    var x = b();
    if ((el = e.target.closest('[data-pbs-pickb]'))) { cur = Number(el.getAttribute('data-pbs-pickb')); render(); return; }
    if (e.target.closest('[data-pbs-add]')) {
      if (data.banners.length >= data.limits.banners) return;
      var y = JSON.parse(JSON.stringify(data.blank)); y.id = newId(); y.name = 'Banner ' + (data.banners.length + 1);
      data.banners.push(y); cur = data.banners.length - 1; open = 'banners'; render(); return;
    }
    if (!x) return;
    if ((el = e.target.closest('[data-pbs-pick]'))) {
      var k = el.getAttribute('data-pbs-pick');
      if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available on this page.'); return; }
      window.kbbPickMedia({ title: k === 'img_d' ? 'Desktop banner picture' : 'Phone banner picture', note: 'The whole picture is shown, words and all — it is not cropped.', folder: 'appearance', onPick: function (urls) {
        if (!urls || !urls[0]) return;
        x[k] = String(urls[0]); render();
      } });
      return;
    }
    if ((el = e.target.closest('[data-pbs-unpick]'))) { x[el.getAttribute('data-pbs-unpick')] = ''; render(); return; }
    if (e.target.closest('[data-pbs-additem]')) { if (x.items.length < data.limits.items) { x.items.push({ en: '', ar: '', dev: 'both' }); render(); } return; }
    if ((el = e.target.closest('[data-pbs-del]'))) { x.items.splice(Number(el.getAttribute('data-pbs-del')), 1); render(); return; }
    if ((el = e.target.closest('[data-pbs-move]'))) {
      var i = Number(el.getAttribute('data-pbs-i')), j = i + Number(el.getAttribute('data-pbs-move'));
      if (j < 0 || j >= x.items.length) return;
      var tmp = x.items[i]; x.items[i] = x.items[j]; x.items[j] = tmp; render(); return;
    }
    if (e.target.closest('[data-pbs-reset]')) {
      ['strip', 'strip_d', 'strip_m', 'items'].concat(data.colours.map(function (c) { return c.key; }), data.numbers.map(function (n) { return n.key; })).forEach(function (k2) {
        x[k2] = JSON.parse(JSON.stringify(data.blank[k2]));
      });
      render(); say('The strip is back to its shipped look. Nothing is saved until you press Save.'); return;
    }
    if (e.target.closest('[data-pbs-remove]')) {
      if (!window.confirm('Delete “' + x.name + '”? Pages showing it go back to no banner when you Save.')) return;
      Object.keys(data.assign).forEach(function (p) { if (data.assign[p] === x.id) data.assign[p] = ''; });
      data.banners.splice(cur, 1); cur = Math.max(0, cur - 1); render();
    }
  });
})();
