
(function () {
  'use strict';

  var SCREEN = 'siteapp';
  var GROUP = 'App';
  var TITLE = 'Site App';

  var data = null, draft = null, busy = false, banner = null, seq = 0, up = null;

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

  async function api(body, path) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/site-app' + (path || ''), opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('site-app ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Site App endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the Site App. An owner or a manager can.';
    if (e && e.status === 429) return 'Too many changes in a minute. Wait a moment and try again.';
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
      upFrom(d);
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
      // A new name changes what an iPhone shows: App update's box follows it.
      if (up && d.update) up.icon = !!d.update.look_changed;
      banner = null;
      say(d.values.on ? 'Saved. The shop can be added to the Home Screen.' : 'Saved. The Site App is off.');
    } catch (e) {
      banner = explain(e, 'Not saved.');
      if (e && e.body && e.body.values) { data = e.body; }
    }
    busy = false; render();
  }

  /* ── App update (Lane UA) ── */
  function upFrom(d) {
    var u = d && d.update;
    up = u ? { en: u.v > 0 ? String(u.en) : '', ar: u.v > 0 ? String(u.ar) : '', icon: !!u.look_changed } : null;
  }
  async function upPost(path, body, ok) {
    if (busy) return;
    busy = true; render();
    try {
      var d = await api(body, path);
      data = d; upFrom(d); banner = null; say(ok(d));
    } catch (e) {
      banner = explain(e, 'Not saved.');
      if (e && e.body && e.body.update) { data = e.body; }
    }
    busy = false; render();
  }
  function publish() {
    if (!up || !window.confirm('Publish an update? Every installed app shows the Update App row once, until the shopper taps it or closes it.')) return;
    upPost('/update', { en: up.en.trim(), ar: up.ar.trim(), icon: up.icon }, function (d) { return 'Published update ' + d.update.v + '. Installed apps show the Update App row.'; });
  }
  function when(iso) {
    var t = new Date(iso);
    return isNaN(t.getTime()) ? String(iso) : t.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  }
  function updateCard() {
    var u = data.update;
    if (!u || !up) return null;
    var en = el('input', { id: 'sapUpEn', type: 'text', maxlength: u.msg_max, value: up.en, placeholder: u.defaults.en, autocomplete: 'off' });
    var ar = el('input', { id: 'sapUpAr', type: 'text', maxlength: u.msg_max, value: up.ar, placeholder: u.defaults.ar, dir: 'rtl', lang: 'ar', autocomplete: 'off' });
    en.addEventListener('input', function () { up.en = en.value; });
    ar.addEventListener('input', function () { up.ar = ar.value; });
    var icon = el('input', { type: 'checkbox', id: 'sapUpIcon', checked: up.icon });
    icon.addEventListener('change', function () { up.icon = icon.checked; });
    var show = el('button', { type: 'button', class: 'sap-sw', role: 'switch', 'aria-checked': String(!!u.show), disabled: busy || u.v === 0,
      onclick: function () { upPost('/update/show', { show: !u.show }, function (d) { return d.update.show ? 'The Update App row shows in installed apps that have not updated.' : 'The Update App row is off.'; }); } },
      [el('i'), el('span', { text: 'Show the Update App row in installed apps' })]);
    var stamp = u.v > 0
      ? 'Update ' + u.v + ' published ' + when(u.at) + (u.by ? ' by ' + u.by : '') + '.'
      : 'Nothing published yet. Installed apps show no update row.';

    return card('App update', 'Tell phones that already installed the app that there is something new. Inside the installed app only, the footer\'s app row turns into an "Update App" row (Arabic: \u2068«تحديث التطبيق»\u2069); one tap loads the new version straight away, and the row never shows again on that phone for that update. The shop in a browser does not change.', [
      el('p', { class: 'sap-stamp', text: stamp }),
      el('div', { class: 'sap-msgs' }, [
        el('div', { class: 'sap-field' }, [el('label', { for: 'sapUpEn', text: 'Message (English)' }), en, el('small', { text: 'Empty: "' + u.defaults.en + '". Up to ' + u.msg_max + ' characters.' })]),
        el('div', { class: 'sap-field' }, [el('label', { for: 'sapUpAr', text: 'Message (Arabic)' }), ar, el('small', { text: 'Empty: \u2068«' + u.defaults.ar + '»\u2069.' })])
      ]),
      el('label', { class: 'sap-chk', for: 'sapUpIcon' }, [icon, el('span', { text: 'This update has a new app icon or app name — also tell iPhone users how to see it' + (u.look_changed ? ' (ticked for you: the icon or name changed since the last update)' : '') })]),
      el('div', { class: 'sap-actions' }, [
        el('button', { type: 'button', class: 'sap-btn is-primary', disabled: busy, onclick: publish, text: busy ? 'Publishing…' : 'Publish an update to installed apps' })
      ]),
      el('div', { class: 'sap-row' }, [show]),
      el('p', { class: 'sap-sub', text: 'What an app update really changes:' }),
      el('ul', { class: 'sap-truth' }, [
        el('li', { text: 'Pages, products, prices and stock are always live in the installed app. They never need an update.' }),
        el('li', { text: 'What an installed app can be behind on is its own engine — the background worker and the files it keeps. "Update App" swaps in the new one and reloads at once. (The app also checks by itself each time it opens; the button makes sure, now.)' }),
        el('li', { text: 'The Home Screen icon, name and top colour: Android refreshes them by itself, usually within a day. An iPhone only shows a new icon or name after the app is removed and added again — with the box above ticked, iPhone users see that as a one-line tip.' }),
        el('li', { text: 'The row is the footer\'s app row, so it needs Appearance → Footer → App row → "Show the app row" on (it is, as shipped).' })
      ])
    ]);
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

    var upCard = updateCard();
    if (upCard) wrap.appendChild(upCard);

    // App icon and favicon (Lane IC): upload, preview, guide. A save reloads
    // this screen once, so the Home Screen preview above shows the new icon.
    var iconHost = el('div', { 'data-sap-icon': '' });
    wrap.appendChild(iconHost);
    if (data.icon) {
      data.icon.name = draft.name.trim() || data.values.name;
      window.kbbAppIconCard(iconHost, { path: '/admin-api/site-app/icon', state: data.icon, intro: 'The picture on a shopper\'s Home Screen when they add the shop, and the shop\'s icon in the browser tab.', onSaved: function () { load(); } });
    }

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
