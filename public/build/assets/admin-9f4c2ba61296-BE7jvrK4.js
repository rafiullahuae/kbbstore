
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
