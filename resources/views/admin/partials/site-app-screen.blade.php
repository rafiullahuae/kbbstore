{{--
    App → Site App.                                                    Lane PW

    The shop as a Home Screen app: one switch for the whole thing, the name
    under the icon, and a read-only look at the icon. The owner, 5 October:
    "just build the app for the site ... give me just one icon to test it".
    How the shop OFFERS the install (a menu row, a sheet after an order, a
    button) is decided later — docs/pw-preview/PLAN.md — so nothing here
    draws or configures one.

    Pulled into resources/views/admin/app.blade.php below the Page header
    screen (tools/pwa-wire.php writes the line), so window.go and toast()
    exist. It wraps window.go for one id, 'siteapp'. Its sidebar row is a
    STATIC entry in NAV's new "App" group, so this file registers no row of
    its own: a group whose only rows were registered by partials would give
    them no NAV anchor to land after (AdminNavAndIdsTest, section 5), and with
    Site App in NAV the Owner App row can register after 'siteapp'.

    LIGHT: one GET when the screen opens, one POST on Save, nothing else. No
    timer, no request per keystroke; the label preview is drawn from the
    field's own value. Everything the server sent is set with textContent or
    as an attribute, never as markup.
--}}
@verbatim
<style>
.sap-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr);max-width:980px}
.sap-wrap > *{min-width:0}
.sap-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.sap-title{font-weight:650;font-size:15px;margin:0}
.sap-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:80ch}
.sap-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:14px}
.sap-sw{display:inline-flex;align-items:center;gap:10px;cursor:pointer;font:inherit;font-size:13.5px;font-weight:600;background:none;border:0;padding:0;color:inherit}
.sap-sw i{position:relative;width:40px;height:22px;border-radius:999px;background:var(--border,#d9d9d9);transition:background .15s;flex:none}
.sap-sw i::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
.sap-sw span{text-align:start}
.sap-sw[aria-checked="true"] i{background:var(--accent,#15a85a)}
.sap-sw[aria-checked="true"] i::after{transform:translateX(18px)}
[dir="rtl"] .sap-sw[aria-checked="true"] i::after{transform:translateX(-18px)}
.sap-field{display:grid;gap:6px;min-width:0;flex:1 1 260px;max-width:360px}
.sap-field label{font-size:12.5px;font-weight:600}
.sap-field input{font:inherit;font-size:14px;padding:8px 10px;border:1px solid var(--border,#d9d9d9);border-radius:9px;background:transparent;color:inherit;min-width:0;width:100%;box-sizing:border-box}
.sap-field small{color:var(--ink-soft,#6b7280);font-size:12px}
.sap-home{display:flex;flex-direction:column;align-items:center;gap:6px;width:92px;padding:12px 6px;border-radius:14px;background:linear-gradient(160deg,#C9B8E8,#F5C6D3 60%,#FBE4D3)}
.sap-home img{width:60px;height:60px;border-radius:14px;display:block}
.sap-home span{font:500 11px/1.2 system-ui,-apple-system,sans-serif;color:#1d1d1f;max-width:76px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center}
.sap-icons{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-top:12px}
.sap-icons figure{margin:0;text-align:center;font-size:11.5px;color:var(--ink-soft,#6b7280);display:grid;gap:6px;justify-items:center}
.sap-icons img{display:block;width:64px;height:64px;border-radius:12px;border:1px solid var(--border,#e6e6e6);background:repeating-conic-gradient(#f1f1f1 0 25%,#fff 0 50%) 0 0/12px 12px}
.sap-icons img.sap-round{border-radius:50%}
.sap-btn{padding:8px 14px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer}
.sap-btn.is-primary{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;font-weight:650}
.sap-btn[disabled]{opacity:.45;cursor:default}
.sap-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px}
.sap-actions span{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.sap-steps{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));margin-top:12px}
.sap-steps div{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:12px}
.sap-steps b{font-size:13px}
.sap-steps ol{margin:6px 0 0;padding-inline-start:18px;font-size:12.5px;line-height:1.6}
.sap-links{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:10px;font-size:12.5px}
.sap-note{border:1px solid #b4443c;color:#b4443c;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5}
.sap-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'siteapp';
  var GROUP = 'App';
  var TITLE = 'Site App';

  var data = null, draft = null, busy = false, banner = null, seq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function say(m) { try { window.toast(m); } catch (e) {} }
  function el(tag, attrs, kids) {
    var x = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'text') x.textContent = v; else if (k === 'class') x.className = v;
      else if (k.indexOf('on') === 0) x.addEventListener(k.slice(2), v); else x.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) { if (c != null && c !== false) x.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return x;
  }

  async function api(body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/site-app', opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('site-app ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Site App endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the Site App. An owner or a manager can.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var d = await api();
      if (mine !== seq) return;
      data = d; draft = { on: !!d.values.on, name: String(d.values.name) };
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Site App settings could not be loaded.');
    }
    busy = false; render();
  }

  function dirty() {
    return !!(data && draft) && (draft.on !== !!data.values.on || draft.name.trim() !== String(data.values.name));
  }

  async function save() {
    if (!dirty() || busy) return;
    busy = true; render();
    try {
      var d = await api({ on: draft.on, name: draft.name.trim() });
      data = d; draft = { on: !!d.values.on, name: String(d.values.name) };
      banner = null;
      say(d.values.on ? 'Saved. The shop can be added to the Home Screen.' : 'Saved. The Site App is off.');
    } catch (e) {
      banner = explain(e, 'Not saved.');
      if (e && e.body && e.body.values) { data = e.body; }
    }
    busy = false; render();
  }

  function card(title, sub, kids) {
    return el('section', { class: 'sap-card' }, [el('h3', { class: 'sap-title', text: title }), sub ? el('p', { class: 'sap-sub', text: sub }) : null].concat(kids || []));
  }

  function render() {
    var c = document.querySelector('#content');
    if (!c) return;
    c.textContent = '';
    var wrap = el('div', { class: 'sap-wrap', 'data-screen': SCREEN });
    c.appendChild(wrap);

    if (banner) wrap.appendChild(el('div', { class: 'sap-note', role: 'alert', text: banner }));
    if (!data || !draft) {
      wrap.appendChild(el('div', { class: 'sap-card sap-empty', text: busy ? 'Loading…' : 'Nothing to show.' }));
      return;
    }

    var apple = data.icons.filter(function (i) { return i.key === 'apple-180'; })[0];
    var label = el('span', { text: draft.name.trim() || ' ' });
    var input = el('input', { id: 'sapName', type: 'text', maxlength: data.name_max, value: draft.name, autocomplete: 'off', spellcheck: 'false' });
    input.addEventListener('input', function () { draft.name = input.value; label.textContent = input.value.trim() || ' '; saveBtn.disabled = busy || !dirty(); });

    var sw = el('button', { type: 'button', class: 'sap-sw', role: 'switch', 'aria-checked': String(draft.on), onclick: function () { draft.on = !draft.on; render(); } },
      [el('i'), el('span', { text: draft.on ? 'On: shoppers can add the shop to their Home Screen' : 'Off: the shop is not an app' })]);

    var saveBtn = el('button', { type: 'button', class: 'sap-btn is-primary', disabled: busy || !dirty(), onclick: save, text: busy ? 'Saving…' : 'Save' });

    wrap.appendChild(card('Site App', 'Shoppers can add K-Beauty Bliss to their Home Screen from their browser\'s own menu, and it then opens full screen, like an app. Off removes it from the shop: no app file on any page, and a phone that already added it drops the app\'s background worker on its next visit.', [
      el('div', { class: 'sap-row' }, [sw]),
      el('div', { class: 'sap-row' }, [
        el('div', { class: 'sap-field' }, [
          el('label', { for: 'sapName', text: 'App name' }),
          input,
          el('small', { text: 'Shown under the icon. Up to ' + data.name_max + ' characters; iPhone shortens a long name with "…", and a shopper can rename it when adding.' })
        ]),
        el('div', { class: 'sap-home', 'aria-label': 'How it looks on a Home Screen' }, [apple ? el('img', { src: apple.url, alt: '' }) : null, label])
      ]),
      el('div', { class: 'sap-actions' }, [saveBtn, el('span', { text: dirty() ? 'Unsaved changes' : 'Saved' })])
    ]));

    wrap.appendChild(card('Icon', 'One icon for testing: KB in white on the shop\'s pink. Other designs, and how the shop offers the install, are decided later.', [
      el('div', { class: 'sap-icons' }, data.icons.map(function (i) {
        return el('figure', {}, [el('img', { src: i.url, alt: '', class: i.purpose === 'maskable' ? 'sap-round' : null, width: 64, height: 64 }), el('figcaption', { text: i.size + ' px · ' + i.purpose })]);
      }))
    ]));

    var L = data.links || {};
    wrap.appendChild(card('Add it to a phone', 'From the shop in the phone\'s browser:', [
      el('div', { class: 'sap-steps' }, [
        el('div', {}, [el('b', { text: 'Android (Chrome)' }), el('ol', {}, [el('li', { text: 'Tap ⋮ (top right).' }), el('li', { text: 'Tap "Add to Home screen" or "Install app".' }), el('li', { text: 'Tap Install.' })])]),
        el('div', {}, [el('b', { text: 'iPhone (Safari)' }), el('ol', {}, [el('li', { text: 'Tap Share (the square with an arrow; on iOS 26, tap ••• first).' }), el('li', { text: 'Scroll down, tap "Add to Home Screen".' }), el('li', { text: 'Tap Add.' })])]),
        el('div', {}, [el('b', { text: 'iPad (Safari)' }), el('ol', {}, [el('li', { text: 'Tap Share, top right.' }), el('li', { text: 'Tap "Add to Home Screen".' }), el('li', { text: 'Tap Add.' })])])
      ]),
      el('div', { class: 'sap-links' }, [
        L.manifest ? el('a', { href: L.manifest, target: '_blank', rel: 'noopener', text: 'App manifest' }) : null,
        L.worker ? el('a', { href: L.worker, target: '_blank', rel: 'noopener', text: 'Service worker' }) : null,
        L.offline ? el('a', { href: L.offline, target: '_blank', rel: 'noopener', text: 'Offline page' }) : null
      ])
    ]));
  }
})();
</script>
@endverbatim
