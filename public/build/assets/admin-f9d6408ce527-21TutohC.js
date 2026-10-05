
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
      data = d; draft = { on: !!d.values.on, name: String(d.values.name), ask: d.ask_push !== false };
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Site App settings could not be loaded.');
    }
    busy = false; render();
  }

  function dirty() {
    return !!(data && draft) && (draft.on !== !!data.values.on || draft.name.trim() !== String(data.values.name) || draft.ask !== (data.ask_push !== false));
  }

  async function save() {
    if (!dirty() || busy) return;
    busy = true; render();
    try {
      var d = await api({ on: draft.on, name: draft.name.trim(), ask_push: draft.ask });
      data = d; draft = { on: !!d.values.on, name: String(d.values.name), ask: d.ask_push !== false };
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

    // Lane NT: the installed app's own "Allow notifications" sheet, on open.
    var ask = el('button', { type: 'button', class: 'sap-sw', role: 'switch', 'aria-checked': String(draft.ask), 'aria-describedby': 'sapAskHelp', onclick: function () { draft.ask = !draft.ask; render(); } },
      [el('i'), el('span', { text: 'Ask shoppers for notifications when the app opens' })]);

    var saveBtn = el('button', { type: 'button', class: 'sap-btn is-primary', disabled: busy || !dirty(), onclick: save, text: busy ? 'Saving…' : 'Save' });

    wrap.appendChild(card('Site App', 'Shoppers can add K-Beauty Bliss to their Home Screen from their browser\'s own menu, and it then opens full screen, like an app. Off removes it from the shop: no app file on any page, and a phone that already added it drops the app\'s background worker on its next visit.', [
      el('div', { class: 'sap-row' }, [sw]),
      el('div', { class: 'sap-row' }, [ask]),
      el('p', { class: 'sap-sub', id: 'sapAskHelp', text: 'Only in the installed app, and only while the phone has neither allowed nor blocked notifications: it offers "Allow notifications" or "Not now". "Not now" asks again some days later; a phone that blocked them is never asked. Shoppers who allow are saved for later. Nothing is sent to them yet.' }),
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
