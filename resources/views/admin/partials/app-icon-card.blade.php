{{--
    The "App icon" card (Lane IC): App → Site App → App icon, and
    App → Owner App → App icon. One component, two doors.

    The owner, 5 October: "for the app, allow me to upload our own icon and
    advise the icon size etc and guide. also site favicon option i need in main
    site and also in apps sites too."

    Included ONCE, from site-app-screen.blade.php (which app.blade.php already
    includes), so nothing new needs wiring. It defines window.kbbAppIconCard();
    each screen calls it with its own address:

        kbbAppIconCard(el, {path: '/admin-api/site-app/icon', state: data.icon, onSaved: fn})
        kbbAppIconCard(el, {path: '/admin-api/owner-app/icon', relativePreviews: true})   // GETs its own state

    The capability is the server's (siteapp.manage / ownerapp.manage, by path).

    LIGHT: the preview of a chosen file is drawn by the browser from the file
    itself (a data: URL, which the admin CSP's img-src allows), no request; one
    POST on Upload, one on a reset. No timer, nothing measured. Every string
    the server sent is set with textContent or as an attribute, never markup.
--}}
@verbatim
<style>
.aic{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0;display:grid;gap:14px}
.aic h3{font-weight:650;font-size:15px;margin:0}
.aic h4{font-weight:650;font-size:13.5px;margin:0}
.aic p{margin:0}
.aic-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;max-width:80ch}
.aic-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));min-width:0}
.aic-grid > *{min-width:0}
.aic-prev{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
.aic-prev figure{margin:0;display:grid;gap:6px;justify-items:center;font-size:11.5px;color:var(--ink-soft,#6b7280);text-align:center}
.aic-home{display:flex;flex-direction:column;align-items:center;gap:5px;width:84px;padding:10px 4px;border-radius:14px;background:linear-gradient(160deg,#C9B8E8,#F5C6D3 60%,#FBE4D3)}
.aic-home span{font:500 11px/1.2 system-ui,-apple-system,sans-serif;color:#1d1d1f;max-width:76px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aic-tile{width:60px;height:60px;border-radius:13.5px;overflow:hidden;background:#fff;display:grid;place-items:center}
.aic-tile img{width:100%;height:100%;object-fit:cover;display:block}
.aic-tile.is-round{border-radius:50%}
.aic-tile.is-pad img{width:80%;height:80%}
.aic-safe{position:relative;width:120px;height:120px;background:#fff;border:1px solid var(--border,#e6e6e6);border-radius:6px;overflow:hidden}
.aic-safe img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.aic-safe.is-pad img{inset:10%;width:80%;height:80%}
.aic-safe i{position:absolute;inset:10%;border-radius:50%;border:2px dashed #e11d74;box-sizing:border-box}
.aic-safe b{position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 60px rgba(17,24,39,.32)}
.aic-tab{display:flex;align-items:center;gap:7px;height:30px;padding:0 12px;background:#fff;border:1px solid var(--border,#e6e6e6);border-bottom:0;border-radius:9px 9px 0 0;max-width:180px;font:12px/1 system-ui,-apple-system,sans-serif;color:#1f2937}
.aic-tab img{width:16px;height:16px;flex:none}
.aic-tab span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aic-tabbar{background:#dfe3ea;padding:6px 8px 0;border-radius:9px 9px 0 0}
.aic-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.aic-btn{padding:8px 14px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer}
.aic-btn.is-primary{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;font-weight:650}
.aic-btn[disabled]{opacity:.45;cursor:default}
.aic-file{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.aic-note{font-size:12.5px;line-height:1.5;color:var(--ink-soft,#6b7280)}
.aic-note.is-bad{color:#b4443c}
.aic-note.is-ok{color:#13794a}
.aic-guide{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:12px 14px;background:var(--surface-2,#fafafa)}
.aic-guide ul{margin:8px 0 0;padding-inline-start:18px;font-size:12.5px;line-height:1.6;display:grid;gap:4px}
.aic-split{border-top:1px solid var(--border,#e6e6e6);padding-top:14px;display:grid;gap:10px}
.aic-col{display:grid;gap:12px;min-width:0;align-content:start}
.aic-pending{justify-self:start;font-size:11.5px;font-weight:600;color:#9a3412;background:#ffedd5;border-radius:999px;padding:2px 8px}
</style>
<script>
(function () {
  'use strict';
  if (window.kbbAppIconCard) return;

  var ACCEPT = 'image/png,image/jpeg,image/webp';
  var pending = {};   // path -> {app|favicon: {name, size, w, h, url}} : survives a screen re-render
  var lastNote = {};  // path -> the answer to the last upload, shown once after the screen reloads

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
  function kb(n) { return n >= 1048576 ? String(+(n / 1048576).toFixed(1)) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  async function call(path, method, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body instanceof FormData) opts.body = body;
    else if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + path, opts);
    var p = null;
    try { p = await r.json(); } catch (e) { p = null; }
    if (!r.ok) {
      var msg = (p && p.message) ? p.message
        : r.status === 403 ? 'Your role cannot change this icon.'
        : r.status === 404 ? 'The icon endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
        : r.status === 413 ? 'That file is larger than this server accepts. Save it smaller (1024 × 1024 PNG under 1 MB) and try again.'
        : r.status === 419 ? 'Your session expired. Reload the page and try again.'
        : 'Not saved (' + r.status + ').';
      var err = new Error(msg); err.body = p; throw err;
    }
    return p;
  }

  /*
   * Read a chosen file in the browser: its pixel size and a data: URL for the
   * preview. The checks mirror the server's (which decides): a warning here
   * saves an upload that would be refused.
   */
  function inspect(file, limits) {
    return new Promise(function (resolve) {
      var out = { name: file.name, size: file.size, w: 0, h: 0, url: '', problem: '' };
      if (ACCEPT.split(',').indexOf(file.type) < 0) {
        out.problem = file.type === 'image/svg+xml' ? 'SVG is not accepted (it can carry scripts). Export a PNG, 1024 × 1024.' : 'Choose a PNG, JPEG or WebP image.';
        return resolve(out);
      }
      if (file.size > limits.bytes) { out.problem = 'That file is ' + kb(file.size) + '; the limit is ' + kb(limits.bytes) + '.'; return resolve(out); }
      var fr = new FileReader();
      fr.onload = function () {
        out.url = String(fr.result);
        var im = new Image();
        im.onload = function () {
          out.w = im.naturalWidth; out.h = im.naturalHeight;
          var s = Math.min(out.w, out.h), l = Math.max(out.w, out.h);
          if (s < limits.min) out.problem = out.w + ' × ' + out.h + ' is too small: at least ' + limits.min + ' × ' + limits.min + ', and ' + limits.best + ' × ' + limits.best + ' is best.';
          else if (l > limits.max) out.problem = out.w + ' × ' + out.h + ' is larger than ' + limits.max + ' on a side. Save it at ' + limits.best + ' × ' + limits.best + '.';
          else if (l / s > limits.ratio) out.problem = out.w + ' × ' + out.h + ' is not square. Crop it to a square first.';
          resolve(out);
        };
        im.onerror = function () { out.problem = 'This file could not be read as an image.'; resolve(out); };
        im.src = out.url;
      };
      fr.onerror = function () { out.problem = 'This file could not be read.'; resolve(out); };
      fr.readAsDataURL(file);
    });
  }

  window.kbbAppIconCard = function (host, opts) {
    var path = opts.path;
    var st = opts.state || null, busy = false, note = lastNote[path] || null, files = {};
    pending[path] = pending[path] || {};
    var mine = pending[path];

    function guide(L) {
      return el('div', { class: 'aic-guide' }, [
        el('h4', { text: 'How to make an icon that looks right everywhere' }),
        el('ul', {}, [
          el('li', { text: 'A square PNG. ' + L.best + ' × ' + L.best + ' pixels is best; ' + L.min + ' × ' + L.min + ' is the smallest accepted. JPEG and WebP work too; SVG is not accepted.' }),
          el('li', { text: 'Keep the artwork inside the middle 80%. Android cuts app icons to a circle, and anything near the edge is lost. The dashed circle in the preview is the safe area; we also shrink the Android icon to fit it for you.' }),
          el('li', { text: 'A solid background, white is fine. iPhone turns see-through areas black, so we put your image\'s corner colour behind the iPhone and Android icons.' }),
          el('li', { text: 'No small text. Anything smaller than about 1/6 of the icon\'s height cannot be read at Home Screen size (60 pixels) and becomes a smudge in a browser tab (16 pixels). A simple mark works best.' }),
          el('li', { text: 'Under 1 MB is plenty (up to ' + kb(L.bytes) + ' is accepted). Upload once: every size is made from it (512, 192, iPhone 180, Android 512 with the safe area, and 48, 96, 192 for the browser tab and Google).' })
        ])
      ]);
    }

    function figure(caption, node) { return el('figure', {}, [node, el('figcaption', { text: caption })]); }

    function previews(name) {
      var p = {}, local = mine.app, favLocal = mine.favicon || mine.app;
      // The owner app's previews are admin images named relative to this card's
      // own address; the shop's are its public icon addresses.
      Object.keys(st.previews || {}).forEach(function (k) {
        var v = st.previews[k];
        p[k] = v && opts.relativePreviews ? root() + path + '/' + v : v;
      });
      var apple = local ? local.url : p.apple, icon = local ? local.url : p.maskable, fav = favLocal ? favLocal.url : p.favicon;
      var pad = local ? ' is-pad' : '';   // a local file is not padded yet; the server's maskable icon already is
      return el('div', { class: 'aic-prev' }, [
        figure('iPhone', el('div', { class: 'aic-home' }, [el('div', { class: 'aic-tile' }, [apple ? el('img', { src: apple, alt: '' }) : null]), el('span', { text: name })])),
        figure('Android', el('div', { class: 'aic-home' }, [el('div', { class: 'aic-tile is-round' + pad }, [icon ? el('img', { src: icon, alt: '' }) : null]), el('span', { text: name })])),
        figure('Safe area (inside the dashed circle)', el('div', { class: 'aic-safe' + pad }, [icon ? el('img', { src: icon, alt: '' }) : null, el('b'), el('i')])),
        figure('Browser tab', el('div', { class: 'aic-tabbar' }, [el('div', { class: 'aic-tab' }, [fav ? el('img', { src: fav, alt: '' }) : el('span', { text: '·' }), el('span', { text: name })])]))
      ]);
    }

    async function choose(kind, file) {
      if (!file) return;
      var info = await inspect(file, st.limits);
      if (info.problem) { delete mine[kind]; delete files[kind]; note = { bad: true, text: info.problem }; render(); return; }
      mine[kind] = info; files[kind] = file;
      note = { text: 'Preview of ' + info.name + ' (' + info.w + ' × ' + info.h + ', ' + kb(info.size) + '). Not live yet: press Upload.' };
      render();
    }

    async function upload(kind) {
      var file = files[kind];
      if (!file || busy) return;
      busy = true; render();
      var fd = new FormData();
      fd.append('kind', kind); fd.append('file', file);
      try {
        var d = await call(path, 'POST', fd);
        st = d.icon; delete mine[kind]; delete files[kind];
        note = { ok: true, text: d.message }; say(d.message);
        lastNote[path] = note;
        if (opts.onSaved) opts.onSaved(st);
      } catch (e) {
        note = { bad: true, text: e.message };
        if (e.body && e.body.icon) st = e.body.icon;
      }
      busy = false; render();
    }

    async function reset(kind) {
      if (busy) return;
      busy = true; render();
      try {
        var d = await call(path + '/reset', 'POST', { kind: kind });
        st = d.icon; note = { ok: true, text: d.message }; say(d.message);
        lastNote[path] = note;
        if (opts.onSaved) opts.onSaved(st);
      } catch (e) { note = { bad: true, text: e.message }; }
      busy = false; render();
    }

    function cancel(kind) { delete mine[kind]; delete files[kind]; note = null; render(); }

    function picker(kind, label) {
      var input = el('input', { type: 'file', accept: ACCEPT, class: 'aic-file', 'aria-label': label });
      input.addEventListener('change', function () { choose(kind, input.files && input.files[0]); });
      return [input, el('button', { type: 'button', class: 'aic-btn', disabled: busy, text: label, onclick: function () { input.click(); } })];
    }

    function render() {
      lastNote[path] = note;
      host.textContent = '';
      if (!st) { host.appendChild(el('section', { class: 'aic' }, [el('p', { class: 'aic-sub', text: busy ? 'Loading…' : (note ? note.text : 'Nothing to show.') })])); return; }
      var name = st.name || '';
      var appRow = el('div', { class: 'aic-row' }, picker('app', st.app ? 'Choose a new icon…' : 'Choose your icon…'));
      if (mine.app && files.app) {
        appRow.appendChild(el('button', { type: 'button', class: 'aic-btn is-primary', disabled: busy, text: busy ? 'Uploading…' : 'Upload and use', onclick: function () { upload('app'); } }));
        appRow.appendChild(el('button', { type: 'button', class: 'aic-btn', disabled: busy, text: 'Cancel', onclick: function () { cancel('app'); } }));
      } else if (st.app) {
        appRow.appendChild(el('button', { type: 'button', class: 'aic-btn', disabled: busy, text: 'Back to the shipped icon', onclick: function () { reset('app'); } }));
      }

      var favState = st.favicon_from === 'own' ? 'Its own image.' : st.favicon_from === 'app' ? 'Your app icon, at 48, 96 and 192 px.' : (opts.shipFavicon || 'None yet: upload an app icon above, or a favicon here.');
      var favRow = el('div', { class: 'aic-row' }, picker('favicon', 'Choose a separate favicon…'));
      if (mine.favicon && files.favicon) {
        favRow.appendChild(el('button', { type: 'button', class: 'aic-btn is-primary', disabled: busy, text: busy ? 'Uploading…' : 'Upload favicon', onclick: function () { upload('favicon'); } }));
        favRow.appendChild(el('button', { type: 'button', class: 'aic-btn', disabled: busy, text: 'Cancel', onclick: function () { cancel('favicon'); } }));
      } else if (st.favicon) {
        favRow.appendChild(el('button', { type: 'button', class: 'aic-btn', disabled: busy, text: 'Use the app icon again', onclick: function () { reset('favicon'); } }));
      }

      host.appendChild(el('section', { class: 'aic', 'data-aic': path }, [
        el('div', {}, [
          el('h3', { text: 'App icon' }),
          el('p', { class: 'aic-sub', text: (opts.intro || 'The picture on the Home Screen, and the icon in the browser tab.') + (st.app ? ' Your own icon is live.' : ' The shipped icon is in use.') })
        ]),
        el('div', { class: 'aic-grid' }, [
          el('div', { class: 'aic-col' }, [
            mine.app ? el('span', { class: 'aic-pending', text: 'Preview, not saved' }) : null,
            previews(name),
            appRow,
            note ? el('p', { class: 'aic-note' + (note.bad ? ' is-bad' : note.ok ? ' is-ok' : ''), role: note.bad ? 'alert' : 'status', text: note.text }) : null
          ]),
          guide(st.limits)
        ]),
        el('div', { class: 'aic-split' }, [
          el('h4', { text: 'Browser tab icon (favicon)' }),
          el('p', { class: 'aic-sub', text: 'Now: ' + favState + ' ' + (opts.favIntro || 'It also shows next to the shop in Google results.') + ' A separate favicon is optional; a simple mark (just the lotus, no words) reads best at 16 pixels.' }),
          favRow
        ])
      ]));
    }

    if (st) render();
    else {
      busy = true; render();
      call(path, 'GET').then(function (d) { st = d.icon; busy = false; render(); }, function (e) { busy = false; note = { bad: true, text: e.message }; render(); });
    }
  };
})();
</script>
@endverbatim
