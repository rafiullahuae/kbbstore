{{--
    Appearance → Footer. (Lane: slim-footer)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It registers its own
    sidebar entry and wraps window.go, exactly as the screens beside it do, so
    that one include is the whole of the change to that file.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "i need a seperate footer as attached, something like that stunning with
    same text (can be change from backend), set the checkout footer under
    Appearance > Footer / Checkout Footer / Cart Footer, also give control to
    control and to hide unhide the footer on cart and checkout separately ...
    overall height of the new footer i want very less, like a bar type. give
    some options to choose from."

    So: one bar, three shapes, every word a setting, and two switches — the
    cart's ships OFF, because that page was not to be disturbed.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file -- inside a comment
    included -- with the next closing one, so writing the word in prose
    swallows everything between them and serves the whole docblock to the
    browser as visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto.

    EVERY CLASS IS PREFIXED sfs- OR sfv- AND APPEARS NOWHERE ELSE IN THE
    CONSOLE, and so is every data- attribute anything clicks: app.blade.php
    binds delegated listeners to `document` itself, each claiming a bare
    attribute name, and a click on any element carrying one is handled by that
    listener whichever screen it belongs to.
--}}
@verbatim
<style>
.sfs-wrap{display:grid;gap:14px;min-width:0}
.sfs-wrap > *{min-width:0}
.sfs-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.sfs-title{font-weight:650;font-size:15px}
.sfs-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.sfs-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.sfs-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sfs-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.sfs-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.sfs-f{display:grid;gap:5px;min-width:0}
.sfs-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.sfs-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.sfs-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
         font-variant-numeric:tabular-nums}
.sfs-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.sfs-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.sfs-f input[type=text],.sfs-f select{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.sfs-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.sfs-check input{margin-top:3px;flex:none;width:16px;height:16px}
.sfs-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.sfs-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sfs-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.sfs-btn[disabled]{opacity:.45;cursor:default}
.sfs-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.sfs-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
@media (max-width:640px){ .sfs-card{padding:13px} }

/* ── the preview ──────────────────────────────────────────────────────────
   The bar is the whole product, so the mock is the bar at its real size on a
   mock page rather than a miniature of one. What it stands for is the foot of
   the checkout, so it is drawn as the bottom of a window. */
.sfv-h{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:4px 0 10px}
.sfv-h b{font-size:15px;font-weight:700}
.sfv-h span{font-size:11px;color:var(--ink-soft,#6b7280)}
.sfv-win{border:1px solid var(--border,#e6e6e6);border-radius:12px;overflow:hidden;
  background:#fbf5f4;box-shadow:0 8px 26px -18px rgba(0,0,0,.4)}
.sfv-win.is-phone{width:340px;max-width:100%;margin:0 auto;border-radius:18px}
.sfv-bar{display:flex;align-items:center;gap:6px;padding:7px 11px;background:#f6f7f9;
  border-bottom:1px solid var(--border,#e6e6e6);font-size:10px;color:#6b7280}
.sfv-bar i{width:6px;height:6px;border-radius:50%;background:#d8dbe0;display:block}
.sfv-page{padding:18px 16px 10px;display:grid;gap:7px}
.sfv-ghost{height:9px;border-radius:5px;background:#eee7ea}
.sfv-ghost.w70{width:70%}.sfv-ghost.w45{width:45%}.sfv-ghost.w88{width:88%}
.sfv-rulers{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 8px;
  padding:7px 11px;margin-top:8px;background:#f6f7f9;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font-size:11px;color:#6b7280;text-align:center}
.sfv-rulers b{font-weight:700;color:var(--ink,#16181d);font-variant-numeric:tabular-nums}

/* The bar itself, drawn from the same numbers the shop reads. Its rules are a
   deliberate copy of the storefront's rather than a reuse: the storefront
   stylesheet is not loaded in the console, and a preview that guessed at the
   shape would be the one thing a preview must never be -- different from the
   page. */
.sfv-foot{--f:1;--bf:1;--pady:12px;--padx:20px;--gap:18px;
  --ink:#17181C;--ink2:#5E545A;--line:#EBE3E6;--bg:#FBF5F4;
  background:var(--bg);color:var(--ink2);border-top:1px solid var(--line);
  font-size:calc(12px * var(--f));line-height:1.45}
.sfv-foot.noline{border-top:0}
.sfv-foot.t-white{--bg:#FFFFFF}
.sfv-foot.t-ink{--bg:#17181C;--ink:#FFFFFF;--ink2:#C9C1C5;--line:#2C2A2E}
/* The same 1040px cap the storefront bar has. Without it the mock keeps
   everything on one line at a width the real page never gives it, and the
   preview says "one line" where the shop wraps to two. */
.sfv-in{max-width:1040px;margin:0 auto;padding:var(--pady) var(--padx);display:flex;align-items:center;flex-wrap:wrap;
  gap:calc(var(--gap) * .55) var(--gap);min-width:0}
.sfv-in > *{min-width:0}
.sfv-foot b{color:var(--ink);font-weight:700}
.sfv-brand b{display:block;font-size:calc(14px * var(--bf));font-weight:800;letter-spacing:.02em;
  text-transform:uppercase;line-height:1.15}
.sfv-brand i{display:block;font-style:normal;font-size:calc(10.5px * var(--f));opacity:.85}
.sfv-help{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.sfv-con{display:flex;align-items:center;flex-wrap:wrap;gap:calc(var(--gap) * .5) var(--gap)}
.sfv-c{display:inline-flex;align-items:center;gap:6px;min-width:0}
.sfv-c svg{flex:none;width:calc(15px * var(--f));height:calc(15px * var(--f));color:var(--ink)}
.sfv-c span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sfv-links{display:flex;flex-wrap:wrap;gap:calc(var(--gap) * .5) var(--gap)}
.sfv-links span{text-decoration:underline;text-underline-offset:2px}
.sfv-top{margin-inline-start:auto;flex:none;width:calc(30px * var(--f));height:calc(30px * var(--f));
  display:grid;place-items:center;border-radius:50%;border:1px solid var(--line);color:var(--ink)}
.sfv-top svg{width:calc(15px * var(--f));height:calc(15px * var(--f))}
.sfv-foot.v-bar .sfv-brand b{display:inline}
.sfv-foot.v-bar .sfv-brand i{display:inline;margin-inline-start:6px}
.sfv-foot.v-split .sfv-in{align-items:flex-start}
.sfv-foot.v-split .sfv-brand{margin-inline-end:auto}
.sfv-foot.v-split .sfv-help,.sfv-foot.v-split .sfv-con,.sfv-foot.v-split .sfv-links{align-self:center}
.sfv-foot.v-stack .sfv-in{flex-direction:column;align-items:flex-start}
.sfv-foot.v-stack .sfv-top{margin-inline-start:0;align-self:flex-end}
.sfv-foot.v-stack .sfv-help{flex-direction:column;align-items:flex-start;gap:1px}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'slimfooter';

  var tabs = null, values = {}, open = null, banner = null, busy = false, seq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Footer endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Footer',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 15h18"/><path d="M7 18h5"/>',
      group: 'Appearance',
      after: ['checkoutpage', 'cartpage', 'dividers']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Footer';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/slim-footer');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Footer settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      await api('/slim-footer', { settings: payload });
      say('Footer saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  function shown(f) {
    return String(values[f.key]) + ((f.options || {}).unit || '');
  }

  function fieldHTML(f) {
    var id = 'sfs-' + f.key;
    var help = f.help ? '<p class="sfs-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="sfs-f"><div class="sfs-check">'
        + '<input type="checkbox" id="' + id + '" data-sfs-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="sfs-f"><div class="sfs-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-sfs-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="sfs-f"><div class="sfs-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="sfs-val" data-sfs-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-sfs-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    return '<div class="sfs-f"><div class="sfs-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-sfs-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Footer') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sfs-wrap"><div class="sfs-card"><div class="sfs-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="sfs-wrap"><div class="sfs-card">'
        + '<div class="sfs-title">Footer</div>'
        + '<p class="sfs-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="sfs-actions"><button class="sfs-btn" data-sfs-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="sfs-tab" data-sfs-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    host.innerHTML = '<div class="sfs-wrap">'
      + (banner ? '<div class="sfs-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="sfs-note">This is <b>not</b> the site footer. The checkout and the cart page have '
      + 'never drawn that one, and still do not — this is a separate bar with its own words, and the '
      + 'switches on the first tab decide which of the two pages carries it.</div>'
      + '<div class="sfs-card">'
      + '<div class="sfs-tabs">' + strip + '</div>'
      + '<p class="sfs-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="sfs-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="sfs-actions">'
      + '<button class="sfs-btn is-primary" data-sfs-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="sfs-btn" data-sfs-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '</div></div>'
      + previewHTML()
      + '</div>';
  }

  /* -------------------------------------------------------------- preview */
  function v(key, fallback) {
    var x = values[key];
    return (x === undefined || x === null) ? fallback : x;
  }
  function num(key, fallback) {
    var n = Number(values[key]);
    return isFinite(n) ? n : fallback;
  }
  function on(key) { return values[key] === true || values[key] === 1 || values[key] === '1'; }

  var WA = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.7 15l-1.2 4.3a.6.6 0'
    + ' 0 0 .7.7L7.2 20.8A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2.9.9 0 0 0-.7-.1l-2.7.8.8-2.6a.9.9 0'
    + ' 0 0-.1-.8A8.2 8.2 0 0 1 12 3.8Z"/></svg>';
  var MAIL = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H3a1'
    + ' 1 0 0 1-1-1v-11a1 1 0 0 1 1-1Zm1.6 1.8L12 12.4l7.4-5.1H4.6Z"/></svg>';
  var UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
    + ' stroke-linejoin="round"><path d="M12 19V5"/><path d="m5.5 11.5 6.5-6.5 6.5 6.5"/></svg>';

  /* The bar, from the same values the page reads. Anything empty draws no
     element at all, exactly as the storefront partial does — which is the part
     of this design a preview most needs to show, because it is how the bar is
     made short. */
  function barHTML() {
    var cls = 'sfv-foot v-' + esc(v('variant', 'bar')) + ' t-' + esc(v('tone', 'cream'))
      + (on('divider') ? '' : ' noline');

    var style = '--pady:' + num('pad_y', 12) + 'px;--padx:' + num('pad_x', 20) + 'px'
      + ';--gap:' + num('gap', 18) + 'px'
      + ';--f:' + (num('font', 100) / 100) + ';--bf:' + (num('brand_size', 100) / 100);

    var brand = String(v('brand', '')), byline = String(v('byline', ''));
    var ht = String(v('help_title', '')), hs = String(v('help_sub', ''));
    var ph = String(v('phone', '')), em = String(v('email', ''));
    var l1 = String(v('l1_text', '')), l2 = String(v('l2_text', ''));

    var html = '<footer class="' + cls + '" style="' + style + '"><div class="sfv-in">';

    if (brand || byline) {
      html += '<div class="sfv-brand">' + (brand ? '<b>' + esc(brand) + '</b>' : '')
        + (byline ? '<i>' + esc(byline) + '</i>' : '') + '</div>';
    }
    if (ht || hs) {
      html += '<div class="sfv-help">' + (ht ? '<b>' + esc(ht) + '</b>' : '')
        + (hs ? '<span>' + esc(hs) + '</span>' : '') + '</div>';
    }
    if (ph || em) {
      html += '<div class="sfv-con">'
        + (ph ? '<span class="sfv-c">' + WA + '<span>' + esc(ph) + '</span></span>' : '')
        + (em ? '<span class="sfv-c">' + MAIL + '<span>' + esc(em) + '</span></span>' : '')
        + '</div>';
    }
    if (l1 || l2) {
      html += '<div class="sfv-links">' + (l1 ? '<span>' + esc(l1) + '</span>' : '')
        + (l2 ? '<span>' + esc(l2) + '</span>' : '') + '</div>';
    }
    if (on('top_on')) html += '<span class="sfv-top">' + UP + '</span>';

    return html + '</div></footer>';
  }

  function previewHTML() {
    var ghosts = '<div class="sfv-page"><div class="sfv-ghost w70"></div>'
      + '<div class="sfv-ghost w88"></div><div class="sfv-ghost w45"></div></div>';

    /* Both widths, always. The bar's whole job is to be short, and the one
       place it stops being short is a phone, where five blocks wrap. Showing
       only a desktop would hide the case worth looking at. */
    return '<div class="sfs-card" data-sfs-preview>'
      + '<div class="sfv-h"><b>Preview</b><span>Redraws as you type. A drawing, not the live page.</span></div>'
      + '<div class="sfv-win"><div class="sfv-bar"><i></i><i></i><i></i><span>Desktop</span></div>'
      + ghosts + barHTML() + '</div>'
      + '<div class="sfv-win is-phone" style="margin-top:16px"><div class="sfv-bar"><i></i><i></i><i></i>'
      + '<span>Phone · 340px</span></div>' + ghosts + barHTML() + '</div>'
      + '<div class="sfv-rulers">Height is mostly <b>' + num('pad_y', 12) + 'px</b> top and bottom'
      + '<span>·</span>shows on the checkout <b>' + (on('co_on') ? 'yes' : 'no') + '</b>'
      + '<span>·</span>on the cart page <b>' + (on('cart_on') ? 'yes' : 'no') + '</b></div>'
      + '</div>';
  }

  function paintPreview() {
    var node = document.querySelector('[data-sfs-preview]');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-sfs-key]');
    if (!el) return;

    var key = el.getAttribute('data-sfs-key');
    if (el.type === 'checkbox') values[key] = el.checked;
    else if (el.type === 'range') values[key] = Number(el.value);
    else values[key] = el.value;

    /* A checkbox or a select can change what belongs on the screen, and both
       are single clicks nobody is dragging, so they redraw. A range and a text
       box repaint only the preview: rebuilding the controls mid-drag drops the
       pointer capture, and mid-word it moves the caret. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); return; }

    var out = document.querySelector('[data-sfs-val="' + key + '"]');
    if (out) {
      var f = null;
      tabs.forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) f = x; }); });
      if (f) out.textContent = shown(f);
    }
    paintPreview();
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-sfs-tab]');
    if (tab) { open = tab.getAttribute('data-sfs-tab'); render(); return; }
    if (e.target.closest('[data-sfs-save]')) { save(); return; }
    if (e.target.closest('[data-sfs-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
