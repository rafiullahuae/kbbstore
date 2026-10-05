{{--
    Pages → Page header.                                               Lane PH

    Pulled into resources/views/admin/app.blade.php below the Page banners
    screen (tools/ph-wire.php writes the line), so window.go, toast() and the
    Media Library picker (window.kbbPickMedia) exist. It wraps window.go for one
    id, 'pageheader'; the sidebar row is a static entry in the Pages group of
    NAV, so this file registers no row of its own.

    The owner, of /super-sale/: "i want full control of super sale page header,
    to hide title, and i needed an image too, also control of image height etc.
    and hide the all products button, count etc. make this global option for all
    pages. desktop and mobile seeperate." — "also to change the position of the
    elements on the pages title header."

    THE CONTROLS ARE THE STOREFRONT PANEL'S OWN. This screen imports
    resources/js/kbb/admin/page-header-editor.js by its manifest address and
    calls its controls() and stage(), so the console and the "Edit header"
    panel on the shop cannot drift apart, and the preview is drawn by the same
    compile() the shop's header is (PageHeaderTest holds that to the PHP).
    No request until Save.
--}}
@verbatim
<style>
.phs-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr)}
.phs-wrap > *{min-width:0}
.phs-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.phs-title{font-weight:650;font-size:15px}
.phs-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:80ch}
.phs-grid{display:grid;gap:14px;grid-template-columns:minmax(0,1fr)}
@media (min-width:1100px){.phs-grid{grid-template-columns:minmax(0,380px) minmax(0,1fr);align-items:start}.phs-sticky{position:sticky;top:12px}}
.phs-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
.phs-btn{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:12.5px;cursor:pointer;max-width:100%}
.phs-btn[aria-pressed="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.phs-btn.is-primary{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;font-weight:650}
.phs-btn[disabled]{opacity:.45;cursor:default}
.phs-btn small{opacity:.7;margin-inline-start:4px}
.phs-note{border:1px solid #b4443c;color:#b4443c;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5;margin-top:10px}
.phs-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;align-items:center}
.phs-actions a{font-size:12.5px}
.phs-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.phs-stage{margin:12px auto 0;border:1px solid var(--border,#e6e6e6);border-radius:12px;overflow:hidden;background:#fff;color:#2A2228;max-width:100%;padding:18px 16px 4px;font-family:Outfit,system-ui,sans-serif}
.phs-stage[data-dev="m"]{width:390px}
.phs-stage .kbb-home{--muted:#7d6b73;--pink:#e0567b;--pink-d:#c8336a;--pink-s:#fde8ef}
.phs-stage .kbb-home .crumb{font-size:12.5px;color:var(--muted)}
.phs-stage .kbb-home .crumb a{color:var(--muted);text-decoration:none}
.phs-stage .kbb-home .sh :is(h1){font-size:30px;font-weight:700;display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;line-height:1.2}
.phs-stage[data-dev="m"] .kbb-home .sh h1{font-size:18px}
.phs-stage .kbb-home .sh:not(.kbb-ph-nod) h1::before{content:'';display:inline-block;width:18px;height:18px;border-radius:50%;background:radial-gradient(circle at 35% 35%,#fbd3df,#f3a9c0);opacity:.85}
.phs-stage .kbb-home .sh h1 .cnt{font-size:12px;font-weight:600;color:var(--pink-d);background:var(--pink-s);border-radius:99px;padding:4px 11px}
.phs-stage .kbb-home .sh p{color:var(--muted);font-size:13.5px;max-width:64ch}
.phs-stage .kbb-home .lnk{font-size:12.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--pink-d);border:1.5px solid var(--pink);border-radius:99px;padding:9px 18px;white-space:nowrap;text-decoration:none}
.phs-stage .kbb-home .kbb-ph-pg h1{font-size:34px;font-weight:800;margin:0}
.phs-fake{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:4px 0 14px}
.phs-stage[data-dev="d"] .phs-fake{grid-template-columns:repeat(4,1fr)}
.phs-fake i{display:block;height:80px;border-radius:10px;background:#F7EEF1}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'pageheader';
  var TITLE = 'Page header';

  var data = null, mod = null, sel = '', dev = 'd', busy = false, banner = null, seq = 0, ui = null;

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
    var r = await fetch(root() + '/admin-api/page-header', opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('page-header ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Page header endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the page header. An owner, manager or editor can.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
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

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api();
      if (mine !== seq) return;
      data = body;
      if (Array.isArray(data.pages)) data.pages = {};
      if (!mod) {
        if (!data.editor || data.editor.charAt(0) !== '/' || data.editor.charAt(1) === '/') throw { body: { error: 'The page header controls are not built on this server yet (run the asset build).' } };
        mod = await import(data.editor);
      }
      var css = document.getElementById('kbb-ph-css');
      if (!css) { css = document.createElement('style'); css.id = 'kbb-ph-css'; css.textContent = data.spec.css; document.head.appendChild(css); }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The page header settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy || !data) return;
    busy = true; banner = null; render();
    try {
      var body = await api({ global: data.global, pages: data.pages });
      data.global = body.global; data.pages = Array.isArray(body.pages) ? {} : body.pages;
      say('Page header saved. It is on the shop now.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  function page() { return data.list.filter(function (p) { return p.key === sel; })[0] || null; }
  function bag() { return sel === '' ? data.global : (data.pages[sel] || null); }
  function kind() { var p = page(); return p ? p.kind : 'collection'; }

  function drawStage() {
    var host = document.querySelector('[data-phs-stage]');
    if (!host || !mod) return;
    var b = bag() || data.global, p = page();
    var sample = { title: p ? p.label : 'Super Sale', count: '117 products', intro: 'Every reduced product, biggest discount first.', button: 'All products', home: 'Home' };
    var crumb = { d: data.spec.crumb[dev], m: data.spec.crumb[dev] };
    var head = mod.stage(sample, mod.asDevice(b, dev), kind(), data.spec.breakpoint, crumb);
    head.setAttribute('data-kbb-ph', '');
    var fake = el('div', { class: 'phs-fake' }, [el('i'), el('i'), el('i'), el('i')]);
    var box = el('div', { class: 'phs-stage', 'data-dev': dev }, [el('div', { class: 'kbb-home' }, [head, fake])]);
    host.replaceChildren(box);
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== TITLE) return;

    if (busy && !data) { host.replaceChildren(el('div', { class: 'phs-wrap' }, [el('div', { class: 'phs-card' }, [el('div', { class: 'phs-empty', text: 'Loading…' })])])); return; }
    if (!data || !mod) {
      host.replaceChildren(el('div', { class: 'phs-wrap' }, [el('div', { class: 'phs-card' }, [
        el('div', { class: 'phs-title', text: TITLE }), el('p', { class: 'phs-sub', text: banner || 'Nothing to show yet.' }),
        el('div', { class: 'phs-actions' }, [el('button', { type: 'button', class: 'phs-btn', onclick: load, text: 'Retry' })])])]));
      return;
    }

    var scopes = [el('button', { type: 'button', class: 'phs-btn', 'aria-pressed': String(sel === ''), onclick: function () { sel = ''; render(); } }, ['All custom pages'])]
      .concat(data.list.map(function (p) {
        return el('button', { type: 'button', class: 'phs-btn', 'aria-pressed': String(sel === p.key), onclick: function () { sel = p.key; render(); } },
          [p.label, el('small', { text: data.pages[p.key] ? '· own look' : '· global' })]);
      }));

    var p = page(), b = bag(), controlsHost = el('div', { class: 'kbb-phe-in' });
    var lead;
    if (sel === '') {
      lead = el('p', { class: 'phs-sub', text: 'The look of every custom page that has no look of its own. A page that differs from the shop as it was before this screen existed draws the new header; one that does not keeps its original markup.' });
    } else if (!b) {
      lead = el('div', {}, [
        el('p', { class: 'phs-sub', text: p.label + ' follows the global look.' }),
        el('div', { class: 'phs-actions' }, [el('button', { type: 'button', class: 'phs-btn', onclick: function () { data.pages[sel] = JSON.parse(JSON.stringify(data.global)); render(); }, text: 'Give this page its own look' })])]);
    } else {
      lead = el('div', {}, [
        el('p', { class: 'phs-sub', text: p.label + ' has its own look. Every other page keeps the global one.' }),
        el('div', { class: 'phs-actions' }, [el('button', { type: 'button', class: 'phs-btn', onclick: function () { delete data.pages[sel]; render(); }, text: 'Use the global look on this page' })])]);
    }

    host.replaceChildren(el('div', { class: 'phs-wrap' }, [
      el('div', { class: 'phs-card' }, [
        el('div', { class: 'phs-title', text: TITLE }),
        el('p', { class: 'phs-sub', text: 'The title block at the top of the custom pages — /super-sale/, /new-in/, /best-sellers/, /everything-under-54-aed/ and the content pages: which parts show, in what order, a picture and its height, separately for desktop and phone. You can also change it on the page itself: open the page while signed in and press “Edit header”.' }),
        banner ? el('div', { class: 'phs-note', text: banner }) : null,
        el('div', { class: 'phs-list' }, scopes)]),
      el('div', { class: 'phs-grid' }, [
        el('div', { class: 'phs-card' }, [lead, b ? el('div', { class: 'kbb-phe kbb-phe--inline', style: 'margin-top:12px' }, [controlsHost]) : null,
          el('div', { class: 'phs-actions' }, [
            el('button', { type: 'button', class: 'phs-btn is-primary', disabled: busy || null, onclick: save, text: busy ? 'Saving…' : 'Save' }),
            el('button', { type: 'button', class: 'phs-btn', disabled: busy || null, onclick: load, text: 'Reload' }),
            p ? el('a', { href: p.url, target: '_blank', rel: 'noopener', text: 'Open ' + p.path + ' to edit it on the shop' }) : null])]),
        el('div', { class: 'phs-card phs-sticky' }, [el('div', { class: 'phs-title', text: 'Preview' }),
          el('p', { class: 'phs-sub', text: (dev === 'd' ? 'Desktop' : 'Phone') + ' — the shop\'s own header markup and stylesheet, with sample words. Products below are placeholders.' }),
          el('div', { 'data-phs-stage': '' })])])]));

    if (b) {
      ui = mod.controls(controlsHost, {
        get bag() { return bag(); },
        spec: data.spec,
        kind: kind(),
        get dev() { return dev; },
        set dev(v) { dev = v; },
        onDevice: function () { render(); },
        onChange: drawStage,
        pick: function (done) {
          if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available on this page.'); return; }
          window.kbbPickMedia({ title: 'Header picture', note: 'Shown at the height you set for each device.', folder: 'appearance', onPick: function (urls) { if (urls && urls[0]) done(String(urls[0])); } });
        }
      });
    }
    drawStage();
  }
})();
</script>
@endverbatim
