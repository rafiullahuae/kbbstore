{{--
    Growth & Marketing → Push Notifications.                          Lane PN

    The owner, 5 October: "i need a dedicated page for push notifications under
    Growth & Marketing > Push Notifications > Campaigns ... create campaigns,
    schedule, send to all or specific groups of customers etc, city, region
    wise ... we will have all analytics ... also all controls to controls all
    the notifications".

    Four tabs: Campaigns (write, aim, test, send or schedule, report),
    Automations (order updates, back in stock, basket, price drop — each with
    its switch and its EN/AR wording), Subscribers & analytics, Settings (the
    frequency cap, quiet hours, IP location).

    Pulled into resources/views/admin/app.blade.php below Cart Tracking
    (tools/pn-wire.php writes the line), so window.go and toast() exist. It
    wraps window.go for one id, 'push'. Its sidebar row is AdminNav's static
    `late` entry in Growth & Marketing; kbbAddNavEntry() here only returns it.

    LIGHT: one GET per tab when it opens; the live audience count is ONE POST
    per change of the audience — a chip pressed (debounced 350 ms) or a typed
    box left (change, not input) — never per keystroke; no timer, no polling.
    The notification preview is drawn from the fields' own values. Everything
    the server sent is set with textContent or as an attribute, never markup.
--}}
@verbatim
<style>
.pn-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr);max-width:1180px}
.pn-wrap > *{min-width:0}
.pn-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--border,#e6e6e6)}
.pn-tab{font:inherit;font-size:13.5px;padding:9px 12px;border:0;background:none;color:var(--ink-soft,#6b7280);border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-1px}
.pn-tab[aria-selected="true"]{color:inherit;font-weight:650;border-bottom-color:var(--accent,#15a85a)}
.pn-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.pn-h{font-weight:650;font-size:15px;margin:0}
.pn-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:86ch}
.pn-grid{display:grid;gap:14px;grid-template-columns:minmax(0,1fr)}
@media (min-width:980px){.pn-grid.is-editor{grid-template-columns:minmax(0,1fr) 340px}}
.pn-field{display:grid;gap:6px;min-width:0;margin-top:12px}
.pn-field label,.pn-lbl{font-size:12.5px;font-weight:600}
.pn-field input,.pn-field textarea,.pn-field select,.pn-in{font:inherit;font-size:14px;padding:8px 10px;border:1px solid var(--border,#d9d9d9);border-radius:9px;background:transparent;color:inherit;min-width:0;width:100%;box-sizing:border-box}
.pn-field textarea{resize:vertical;min-height:64px}
.pn-field small{color:var(--ink-soft,#6b7280);font-size:12px}
.pn-count-in{display:flex;justify-content:space-between;gap:8px}
.pn-over{color:#b4443c !important;font-weight:650}
.pn-chips{display:flex;flex-wrap:wrap;gap:6px}
.pn-chip{font:inherit;font-size:12.5px;padding:5px 10px;border:1px solid var(--border,#d9d9d9);border-radius:999px;background:transparent;color:inherit;cursor:pointer}
.pn-chip[aria-pressed="true"]{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.pn-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.pn-btn{padding:8px 14px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;white-space:nowrap}
.pn-btn.is-primary{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;font-weight:650}
.pn-btn.is-danger{border-color:#b4443c;color:#b4443c}
.pn-btn[disabled]{opacity:.45;cursor:default}
.pn-live{display:flex;align-items:baseline;gap:8px;padding:12px;border-radius:10px;background:rgba(21,168,90,.08);margin-top:12px}
.pn-live b{font-size:22px;font-variant-numeric:tabular-nums}
.pn-live span{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.pn-table{width:100%;border-collapse:collapse;font-size:13px}
.pn-table th,.pn-table td{text-align:start;padding:8px 6px;border-bottom:1px solid var(--border,#eee);vertical-align:top}
.pn-table th{font-size:11.5px;font-weight:650;color:var(--ink-soft,#6b7280);text-transform:uppercase;letter-spacing:.03em}
.pn-table td.n,.pn-table th.n{text-align:end;font-variant-numeric:tabular-nums}
.pn-table tr.is-link{cursor:pointer}
.pn-table tr.is-link:hover td{background:rgba(0,0,0,.025)}
.pn-scroll{overflow-x:auto;min-width:0}
.pn-pill{display:inline-block;font-size:11.5px;font-weight:650;padding:2px 8px;border-radius:999px;background:#eef0f3;color:#3d4451;white-space:nowrap}
.pn-pill.sent{background:#e3f5ea;color:#0f7a41}.pn-pill.sending{background:#e6effc;color:#1f5fb8}
.pn-pill.scheduled{background:#fff3dc;color:#8a5a00}.pn-pill.cancelled,.pn-pill.failed{background:#fbe9e7;color:#b4443c}
.pn-tiles{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(min(100%,130px),1fr))}
.pn-tile{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px}
.pn-tile b{display:block;font-size:20px;font-variant-numeric:tabular-nums}
.pn-tile span{font-size:12px;color:var(--ink-soft,#6b7280)}
.pn-bars{display:grid;gap:6px;margin-top:10px}
.pn-bar{display:grid;grid-template-columns:minmax(90px,30%) minmax(0,1fr) 48px;gap:8px;align-items:center;font-size:12.5px}
.pn-bar i{display:block;height:10px;border-radius:5px;background:var(--accent,#15a85a);min-width:2px}
.pn-bar em{font-style:normal;text-align:end;font-variant-numeric:tabular-nums}
.pn-spark{display:flex;align-items:flex-end;gap:2px;height:70px;margin-top:10px}
.pn-spark i{flex:1 1 0;background:var(--accent,#15a85a);border-radius:2px 2px 0 0;min-height:1px}
.pn-two{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))}
.pn-sw{display:inline-flex;align-items:center;gap:10px;cursor:pointer;font:inherit;font-size:13.5px;font-weight:600;background:none;border:0;padding:0;color:inherit;text-align:start}
.pn-sw i{position:relative;width:40px;height:22px;border-radius:999px;background:var(--border,#d9d9d9);transition:background .15s;flex:none}
.pn-sw i::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
.pn-sw[aria-checked="true"] i{background:var(--accent,#15a85a)}
.pn-sw[aria-checked="true"] i::after{transform:translateX(18px)}
[dir="rtl"] .pn-sw[aria-checked="true"] i::after{transform:translateX(-18px)}
.pn-note{border:1px solid #b4443c;color:#b4443c;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5}
.pn-info{border:1px solid var(--border,#e6e6e6);background:rgba(0,0,0,.02);border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.55}
.pn-info code{font-size:11.5px;word-break:break-all}
.pn-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.pn-phone{border-radius:22px;padding:14px;background:linear-gradient(160deg,#C9B8E8,#F5C6D3 60%,#FBE4D3)}
.pn-notif{display:grid;grid-template-columns:38px minmax(0,1fr);gap:10px;background:rgba(255,255,255,.88);border-radius:16px;padding:10px 12px;color:#1d1d1f;font-family:system-ui,-apple-system,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.08)}
.pn-notif img{width:38px;height:38px;border-radius:9px;display:block}
.pn-notif .a{display:flex;justify-content:space-between;gap:6px;font-size:11.5px;color:#6b6b70}
.pn-notif b{display:block;font-size:14px;margin-top:2px;overflow-wrap:anywhere}
.pn-notif p{margin:2px 0 0;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere}
.pn-results{display:grid;gap:4px;margin-top:6px}
.pn-results button{font:inherit;font-size:12.5px;text-align:start;padding:6px 8px;border:1px solid var(--border,#eee);border-radius:8px;background:transparent;color:inherit;cursor:pointer}
.pn-rule{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,130px),1fr)) auto;gap:6px;align-items:center;margin-top:6px}
.pn-tpl{display:grid;gap:8px;grid-template-columns:minmax(0,1fr);margin-top:10px}
@media (min-width:760px){.pn-tpl{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.pn-tpl input{direction:auto}
.pn-badge{font-size:11px;font-weight:650;padding:1px 6px;border-radius:6px;background:#fff3dc;color:#8a5a00;margin-inline-start:6px}
.pn-badge.ok{background:#e3f5ea;color:#0f7a41}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'push';
  var GROUP = 'Growth & Marketing';
  var TITLE = 'Push Notifications';
  var TABS = [['campaigns', 'Campaigns'], ['automations', 'Automations'], ['analytics', 'Subscribers & analytics'], ['settings', 'Settings']];

  var st = { tab: 'campaigns', view: 'list', ov: null, list: null, camp: null, report: null, an: null, banner: null, busy: false, seq: 0 };
  var draft = null, count = { n: null, label: '', seq: 0, timer: null }, links = [];

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
      else if (k === 'value') x.value = v;
      else if (k.indexOf('on') === 0) x.addEventListener(k.slice(2), v); else x.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) { if (c != null && c !== false) x.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return x;
  }
  function num(n) { return Number(n || 0).toLocaleString('en'); }
  function when(iso) { if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }

  async function api(method, path, body) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/push' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('push ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && e.body.error)) return 'The Push Notifications endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot do this. An owner or a manager can.';
    if (e && e.status === 429) return 'Too many tries in a minute. Wait a moment.';
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
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
    st.view = 'list';
    render();
    open(st.tab);
    return undefined;
  };

  async function run(fn, fallback) {
    var mine = ++st.seq;
    st.busy = true; st.banner = null; render();
    try { await fn(mine); } catch (e) { if (mine === st.seq) st.banner = explain(e, fallback); }
    if (mine === st.seq) { st.busy = false; render(); }
  }

  function open(tab) {
    st.tab = tab;
    run(async function (mine) {
      var ov = await api('GET', '');
      if (mine !== st.seq) return;
      st.ov = ov;
      if (tab === 'campaigns') { var l = await api('GET', '/campaigns'); if (mine === st.seq) st.list = l.campaigns; }
      if (tab === 'analytics') { var a = await api('GET', '/analytics'); if (mine === st.seq) st.an = a; }
    }, 'Push Notifications could not be loaded.');
  }

  /* ----------------------------------------------------------- render */

  function card(title, sub, kids) {
    return el('section', { class: 'pn-card' }, [title ? el('h3', { class: 'pn-h', text: title }) : null, sub ? el('p', { class: 'pn-sub', text: sub }) : null].concat(kids || []));
  }
  function sw(on, label, onclick, help) {
    return el('button', { type: 'button', class: 'pn-sw', role: 'switch', 'aria-checked': String(!!on), onclick: onclick, title: help || null }, [el('i'), el('span', { text: label })]);
  }
  function chip(label, on, onclick) {
    return el('button', { type: 'button', class: 'pn-chip', 'aria-pressed': String(!!on), onclick: onclick, text: label });
  }

  function render() {
    var c = document.querySelector('#content');
    if (!c) return;
    c.textContent = '';
    var wrap = el('div', { class: 'pn-wrap', 'data-screen': SCREEN });
    c.appendChild(wrap);
    wrap.appendChild(el('div', { class: 'pn-tabs', role: 'tablist' }, TABS.map(function (t) {
      return el('button', { type: 'button', class: 'pn-tab', role: 'tab', 'aria-selected': String(st.tab === t[0]), 'data-tab': t[0], text: t[1], onclick: function () { st.view = 'list'; open(t[0]); } });
    })));
    if (st.banner) wrap.appendChild(el('div', { class: 'pn-note', role: 'alert', text: st.banner }));
    if (!st.ov) { wrap.appendChild(el('div', { class: 'pn-card pn-empty', text: st.busy ? 'Loading…' : 'Nothing to show.' })); return; }
    if (!st.ov.cron.alive && st.tab !== 'analytics') wrap.appendChild(cronNote());
    if (st.tab === 'campaigns') renderCampaigns(wrap);
    else if (st.tab === 'automations') renderAutomations(wrap);
    else if (st.tab === 'analytics') renderAnalytics(wrap);
    else renderSettings(wrap);
  }

  function cronNote() {
    var cr = st.ov.cron;
    return el('div', { class: 'pn-info' }, [
      el('b', { text: 'Scheduled sends and automations need the server\'s cron line. ' }),
      'No tick seen ' + (cr.last_tick ? 'since ' + when(cr.last_tick) : 'yet') + '. "Send now" still sends from this screen. Add it once under ' + cr.where + ':',
      el('br'), el('code', { text: cr.line })
    ]);
  }

  /* -------------------------------------------------------- campaigns */

  function renderCampaigns(wrap) {
    if (st.view === 'edit' && draft) return renderEditor(wrap);
    if (st.view === 'report' && st.camp) return renderReport(wrap);
    var can = st.ov.can_send;
    var head = el('div', { class: 'pn-row', style: 'justify-content:space-between' }, [
      el('div', {}, [el('h3', { class: 'pn-h', text: 'Campaigns' }), el('p', { class: 'pn-sub', text: num(st.ov.active) + ' phones subscribed. A campaign goes only to the phones you aim it at, at most ' + st.ov.rules.cap_day + ' a day and ' + st.ov.rules.cap_week + ' a week per phone, never in quiet hours (' + st.ov.rules.quiet_from + '–' + st.ov.rules.quiet_to + ').' })]),
      can ? el('button', { type: 'button', class: 'pn-btn is-primary', text: 'New campaign', onclick: function () { newDraft(); } }) : null
    ]);
    var rows = (st.list || []);
    var body = rows.length ? el('div', { class: 'pn-scroll' }, [el('table', { class: 'pn-table' }, [
      el('thead', {}, [el('tr', {}, [el('th', { text: 'Campaign' }), el('th', { text: 'Status' }), el('th', { text: 'When' }), el('th', { class: 'n', text: 'Delivered' }), el('th', { class: 'n', text: 'Clicks' }), el('th', { class: 'n', text: 'CTR' })])]),
      el('tbody', {}, rows.map(function (r) {
        return el('tr', { class: 'is-link', tabindex: '0', onclick: function () { openCampaign(r.id); }, onkeydown: function (e) { if (e.key === 'Enter') openCampaign(r.id); } }, [
          el('td', {}, [el('b', { text: r.title }), el('div', { class: 'pn-sub', text: r.audience_label })]),
          el('td', {}, [el('span', { class: 'pn-pill ' + r.status, text: r.status })]),
          el('td', { text: r.status === 'scheduled' ? when(r.scheduled_at) : when(r.started_at) }),
          el('td', { class: 'n', text: num(r.delivered) }), el('td', { class: 'n', text: num(r.clicks) }), el('td', { class: 'n', text: r.ctr + '%' })
        ]);
      }))
    ])]) : el('div', { class: 'pn-empty', text: st.busy ? 'Loading…' : 'No campaigns yet.' });
    wrap.appendChild(el('section', { class: 'pn-card' }, [head, body]));
  }

  function newDraft(c) {
    draft = c ? { id: c.id, title: c.title, body: c.body, url: c.url || '', link_label: c.link_label || '', audience: JSON.parse(JSON.stringify(c.audience)), at: c.scheduled_local || '', status: c.status }
      : { id: null, title: '', body: '', url: '', link_label: '', audience: { emirates: [], cities: [], locales: [], platforms: [], who: 'all', rules: [], match: 'all' }, at: '', status: 'draft' };
    links = []; count.n = null;
    st.view = 'edit'; render(); recount(0);
  }

  function openCampaign(id) {
    run(async function (mine) {
      var d = await api('GET', '/campaigns/' + id);
      if (mine !== st.seq) return;
      st.camp = d.campaign; st.report = d.by_emirate;
      if ((d.campaign.status === 'draft' || d.campaign.status === 'scheduled') && st.ov.can_send) { newDraft(d.campaign); return; }
      st.view = 'report';
    }, 'That campaign could not be opened.');
  }

  function recount(delay) {
    clearTimeout(count.timer);
    count.timer = setTimeout(async function () {
      var mine = ++count.seq;
      try {
        var d = await api('POST', '/count', { audience: draft.audience });
        if (mine !== count.seq) return;
        count.n = d.n; count.label = d.label; count.err = (d.errors || []).join(' ');
      } catch (e) { if (mine === count.seq) { count.n = null; count.err = explain(e, 'Could not count.'); } }
      var live = document.querySelector('#pnLive');
      if (live) live.replaceWith(liveBox());
    }, delay);
  }

  function liveBox() {
    return el('div', { class: 'pn-live', id: 'pnLive', 'aria-live': 'polite' }, [
      el('b', { text: count.n == null ? '…' : num(count.n) }),
      el('span', { text: count.err ? count.err : (count.n === 1 ? 'phone matches · ' : 'phones match · ') + (count.label || 'All subscribers') })
    ]);
  }

  function toggle(list, key) { var i = list.indexOf(key); if (i >= 0) list.splice(i, 1); else list.push(key); }

  function renderEditor(wrap) {
    var o = st.ov.options, a = draft.audience;
    var preview = { t: el('b'), b: el('p') };
    function paint() { preview.t.textContent = draft.title || 'Your title'; preview.b.textContent = draft.body || 'Your message'; }
    paint();
    function counter(input, max, out) { var n = input.value.length; out.textContent = n + ' / ' + max; out.classList.toggle('pn-over', n > max); }

    var title = el('input', { id: 'pnTitle', type: 'text', maxlength: 80, value: draft.title, autocomplete: 'off' });
    var tCount = el('small'); counter(title, o.title_max, tCount);
    title.addEventListener('input', function () { draft.title = title.value; counter(title, o.title_max, tCount); paint(); });
    var body = el('textarea', { id: 'pnBody', maxlength: 200, rows: 3 }); body.value = draft.body;
    var bCount = el('small'); counter(body, o.body_max, bCount);
    body.addEventListener('input', function () { draft.body = body.value; counter(body, o.body_max, bCount); paint(); });

    var url = el('input', { id: 'pnUrl', type: 'text', value: draft.url, placeholder: '/product/… or /sale/', autocomplete: 'off', spellcheck: 'false' });
    url.addEventListener('input', function () { draft.url = url.value; draft.link_label = ''; });
    var find = el('input', { type: 'search', placeholder: 'Find a product, category or brand', class: 'pn-in', autocomplete: 'off' });
    var results = el('div', { class: 'pn-results' });
    function showLinks() {
      results.textContent = '';
      links.forEach(function (l) { results.appendChild(el('button', { type: 'button', text: l.type + ' · ' + l.name, onclick: function () { draft.url = l.path; draft.link_label = l.type + ' · ' + l.name; url.value = l.path; links = []; showLinks(); } })); });
    }
    find.addEventListener('change', async function () {
      if (find.value.trim().length < 2) { links = []; showLinks(); return; }
      try { links = (await api('GET', '/links?q=' + encodeURIComponent(find.value.trim()))).links; } catch (e) { links = []; }
      showLinks();
    });
    find.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); find.blur(); } });

    function chips(opts, list) {
      return el('div', { class: 'pn-chips' }, opts.map(function (x) {
        var b = chip(x.label, list.indexOf(x.key) >= 0, function () { toggle(list, x.key); b.setAttribute('aria-pressed', String(list.indexOf(x.key) >= 0)); recount(350); });
        return b;
      }));
    }
    var cityOpts = o.cities.map(function (c) { return { key: c, label: c }; });
    a.cities.forEach(function (c) { if (o.cities.indexOf(c) < 0) cityOpts.push({ key: c, label: c }); });
    var addCity = el('input', { type: 'text', class: 'pn-in', placeholder: 'Add a city and press Enter', maxlength: 80, style: 'max-width:260px' });
    addCity.addEventListener('change', function () { var v = addCity.value.trim(); if (v && a.cities.indexOf(v) < 0) { a.cities.push(v); render(); recount(0); } });

    var who = el('div', { class: 'pn-chips' }, o.who.map(function (x) {
      return chip(x.label, a.who === x.key, function () { a.who = x.key; render(); recount(350); });
    }));

    var rules = el('div', {}, a.rules.map(function (r, i) { return ruleRow(r, i); }));
    function ruleRow(r, i) {
      var f = o.rule_fields.filter(function (x) { return x.key === r.field; })[0] || o.rule_fields[0];
      var fieldSel = el('select', { class: 'pn-in', onchange: function () { r.field = fieldSel.value; var nf = o.rule_fields.filter(function (x) { return x.key === r.field; })[0]; r.op = nf.ops[0].key; r.value = ''; render(); recount(0); } },
        o.rule_fields.map(function (x) { return el('option', { value: x.key, text: x.label, selected: x.key === r.field }); }));
      var opSel = el('select', { class: 'pn-in', onchange: function () { r.op = opSel.value; recount(0); } }, f.ops.map(function (x) { return el('option', { value: x.key, text: x.label, selected: x.key === r.op }); }));
      var op = f.ops.filter(function (x) { return x.key === r.op; })[0] || f.ops[0];
      var val = op.kind === 'none' ? null : el('input', { class: 'pn-in', type: op.kind === 'date' ? 'date' : 'text', value: Array.isArray(r.value) ? r.value.join(',') : (r.value == null ? '' : r.value), placeholder: op.kind.indexOf('range') >= 0 ? 'from,to' : '' });
      if (val) val.addEventListener('change', function () { r.value = op.kind.indexOf('range') >= 0 ? val.value.split(',').map(function (x) { return x.trim(); }) : val.value.trim(); recount(0); });
      return el('div', { class: 'pn-rule' }, [fieldSel, opSel, val || el('span'), el('button', { type: 'button', class: 'pn-btn', text: 'Remove', onclick: function () { a.rules.splice(i, 1); render(); recount(0); } })]);
    }

    var at = el('input', { type: 'datetime-local', class: 'pn-in', value: draft.at, min: st.ov.now_local, style: 'max-width:230px' });
    at.addEventListener('change', function () { draft.at = at.value; });

    var form = card(draft.id ? 'Edit campaign' : 'New campaign', 'Short and specific works best: a title of up to ' + o.title_max + ' characters and a message of up to ' + o.body_max + '. Tapping it opens the page you link.', [
      el('div', { class: 'pn-field' }, [el('div', { class: 'pn-count-in' }, [el('label', { for: 'pnTitle', text: 'Title' }), tCount]), title]),
      el('div', { class: 'pn-field' }, [el('div', { class: 'pn-count-in' }, [el('label', { for: 'pnBody', text: 'Message' }), bCount]), body]),
      el('div', { class: 'pn-field' }, [el('label', { for: 'pnUrl', text: 'Opens' }), url, find, results,
        el('small', { text: draft.link_label ? 'Linked to ' + draft.link_label + '.' : 'A page on this shop only. Empty opens the home page.' })]),
      el('h4', { class: 'pn-lbl', style: 'margin:18px 0 6px', text: 'Who receives it' }),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Emirate (where the phone is)' }), chips(o.emirates, a.emirates),
        el('small', { text: 'Pick Sharjah and only phones located in Sharjah receive it: a phone in Dubai is left alone.' })]),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'City' }), chips(cityOpts, a.cities), addCity]),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Language' }), chips(o.locales, a.locales)]),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Phone' }), chips(o.platforms, a.platforms)]),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Customers or guests' }), who]),
      el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'What they bought (signed-in customers)' }), rules,
        el('div', { class: 'pn-row', style: 'margin-top:6px' }, [
          el('button', { type: 'button', class: 'pn-btn', text: 'Add a purchase rule', onclick: function () { a.rules.push({ field: o.rule_fields[0].key, op: o.rule_fields[0].ops[0].key, value: '' }); render(); } }),
          a.rules.length > 1 ? el('select', { class: 'pn-in', style: 'max-width:200px', onchange: function (e) { a.match = e.target.value; recount(0); } }, [el('option', { value: 'all', text: 'Match all rules', selected: a.match !== 'any' }), el('option', { value: 'any', text: 'Match any rule', selected: a.match === 'any' })]) : null
        ])]),
      liveBox(),
      el('div', { class: 'pn-row', style: 'margin-top:16px' }, [
        el('button', { type: 'button', class: 'pn-btn', text: 'Back', onclick: function () { st.view = 'list'; open('campaigns'); } }),
        el('button', { type: 'button', class: 'pn-btn', text: 'Save draft', disabled: st.busy, onclick: function () { saveDraft(); } }),
        el('button', { type: 'button', class: 'pn-btn', text: 'Send a test to my phone', disabled: st.busy, title: st.ov.test_devices ? null : 'Sign in to the shop app on your phone with your admin email and allow notifications', onclick: testSend }),
      ]),
      el('div', { class: 'pn-row', style: 'margin-top:10px' }, [
        at,
        el('button', { type: 'button', class: 'pn-btn', text: draft.status === 'scheduled' ? 'Reschedule' : 'Schedule', disabled: st.busy, onclick: function () { schedule(); } }),
        draft.status === 'scheduled' ? el('button', { type: 'button', class: 'pn-btn is-danger', text: 'Cancel schedule', onclick: function () { cancel(draft.id); } }) : null,
        el('button', { type: 'button', class: 'pn-btn is-primary', text: 'Send now', disabled: st.busy, onclick: function () { sendNow(); } }),
      ]),
      el('p', { class: 'pn-sub', text: 'Times are the shop\'s clock (' + st.ov.zone + '). ' + (st.ov.quiet_now ? 'It is quiet hours now: a campaign sent now waits until ' + st.ov.rules.quiet_to + '.' : 'Quiet hours ' + st.ov.rules.quiet_from + '–' + st.ov.rules.quiet_to + ': nothing is sent then.') })
    ]);

    var side = el('aside', { class: 'pn-card' }, [
      el('h3', { class: 'pn-h', text: 'On the phone' }),
      el('p', { class: 'pn-sub', text: 'How it shows on a lock screen. Phones shorten long text differently.' }),
      el('div', { class: 'pn-phone', style: 'margin-top:12px' }, [el('div', { class: 'pn-notif' }, [
        st.ov.icon ? el('img', { src: st.ov.icon, alt: '' }) : el('span'),
        el('div', {}, [el('div', { class: 'a' }, [el('span', { text: 'K-Beauty Bliss' }), el('span', { text: 'now' })]), preview.t, preview.b])
      ])])
    ]);
    wrap.appendChild(el('div', { class: 'pn-grid is-editor' }, [form, side]));
  }

  function payload() { return { title: draft.title, body: draft.body, url: draft.url, link_label: draft.link_label, audience: draft.audience }; }

  async function saveDraft(after) {
    var ok = false;
    await run(async function (mine) {
      var d = draft.id ? await api('PUT', '/campaigns/' + draft.id, payload()) : await api('POST', '/campaigns', payload());
      if (mine !== st.seq) return;
      draft.id = d.campaign.id; draft.status = d.campaign.status; ok = true;
      if (!after) say('Saved.');
    }, 'Not saved.');
    return ok;
  }
  async function testSend() {
    await run(async function () {
      var d = await api('POST', '/test', { title: draft.title, body: draft.body, url: draft.url });
      say(d.ok ? 'Sent to ' + d.sent + ' of your phones.' : (d.error || 'Not sent.'));
    }, 'The test was not sent.');
  }
  async function schedule() {
    if (!draft.at) { st.banner = 'Pick a date and time first.'; render(); return; }
    if (!(await saveDraft(true))) return;
    await run(async function () {
      var d = await api('POST', '/campaigns/' + draft.id + '/schedule', { at: draft.at });
      draft.status = d.campaign.status; say('Scheduled for ' + when(d.campaign.scheduled_at) + '.');
      st.view = 'list'; open('campaigns');
    }, 'Not scheduled.');
  }
  async function sendNow() {
    if (!window.confirm('Send "' + (draft.title || '') + '" to ' + (count.n == null ? 'the matching' : num(count.n)) + ' phones now?')) return;
    if (!(await saveDraft(true))) return;
    await run(async function () {
      var d = await api('POST', '/campaigns/' + draft.id + '/send', {});
      st.camp = d.campaign; st.report = d.by_emirate; st.view = 'report';
      say(d.campaign.waiting_quiet ? 'Started. It waits for quiet hours to end.' : 'Sent to ' + num(d.campaign.delivered) + ' phones.');
    }, 'Not sent.');
  }
  async function cancel(id) {
    if (!window.confirm('Cancel this campaign? Phones that have not received it yet will not.')) return;
    await run(async function () { var d = await api('POST', '/campaigns/' + id + '/cancel', {}); st.camp = d.campaign; st.report = d.by_emirate; st.view = 'report'; }, 'Not cancelled.');
  }
  async function step(id) {
    await run(async function () { var d = await api('POST', '/campaigns/' + id + '/step', {}); st.camp = d.campaign; st.report = d.by_emirate; st.view = 'report'; }, 'Could not continue.');
  }

  function renderReport(wrap) {
    var c = st.camp;
    var tiles = [['Aimed at', c.targeted], ['Delivered', c.delivered], ['Failed', c.failed], ['No longer subscribed', c.gone], ['Held by the daily cap', c.held], ['Clicks', c.clicks], ['Click rate', c.ctr + '%']];
    var max = 1; (st.report || []).forEach(function (r) { max = Math.max(max, r.sent); });
    wrap.appendChild(card(c.title, c.body, [
      el('div', { class: 'pn-row', style: 'margin:8px 0 12px' }, [el('span', { class: 'pn-pill ' + c.status, text: c.status }), el('span', { class: 'pn-sub', text: c.audience_label + (c.url ? ' · opens ' + c.url : '') }),
        el('span', { class: 'pn-sub', text: c.status === 'scheduled' ? 'Sends ' + when(c.scheduled_at) : (c.started_at ? 'Started ' + when(c.started_at) : '') })]),
      c.waiting_quiet ? el('div', { class: 'pn-info', text: 'Waiting for quiet hours to end (' + st.ov.rules.quiet_to + '). It carries on by itself.' }) : null,
      el('div', { class: 'pn-tiles' }, tiles.map(function (t) { return el('div', { class: 'pn-tile' }, [el('b', { text: typeof t[1] === 'number' ? num(t[1]) : t[1] }), el('span', { text: t[0] })]); })),
      el('h4', { class: 'pn-lbl', style: 'margin:16px 0 0', text: 'By emirate' }),
      (st.report || []).length ? el('div', { class: 'pn-scroll' }, [el('table', { class: 'pn-table' }, [
        el('thead', {}, [el('tr', {}, [el('th', { text: 'Emirate' }), el('th', { class: 'n', text: 'Sent' }), el('th', { class: 'n', text: 'Delivered' }), el('th', { class: 'n', text: 'Clicks' }), el('th', { class: 'n', text: 'CTR' })])]),
        el('tbody', {}, st.report.map(function (r) { return el('tr', {}, [el('td', { text: r.label }), el('td', { class: 'n', text: num(r.sent) }), el('td', { class: 'n', text: num(r.delivered) }), el('td', { class: 'n', text: num(r.clicks) }), el('td', { class: 'n', text: r.delivered ? (Math.round(r.clicks * 1000 / r.delivered) / 10) + '%' : '0%' })]); }))
      ])]) : el('p', { class: 'pn-sub', text: 'Nothing sent yet.' }),
      el('div', { class: 'pn-row', style: 'margin-top:14px' }, [
        el('button', { type: 'button', class: 'pn-btn', text: 'Back to campaigns', onclick: function () { st.view = 'list'; open('campaigns'); } }),
        el('button', { type: 'button', class: 'pn-btn', text: 'Refresh', onclick: function () { openCampaign(c.id); } }),
        st.ov.can_send && c.status === 'sending' && !st.ov.cron.alive ? el('button', { type: 'button', class: 'pn-btn is-primary', text: 'Continue sending', onclick: function () { step(c.id); } }) : null,
        st.ov.can_send && (c.status === 'sending' || c.status === 'scheduled') ? el('button', { type: 'button', class: 'pn-btn is-danger', text: 'Cancel', onclick: function () { cancel(c.id); } }) : null
      ])
    ]));
  }

  /* ------------------------------------------------------ automations */

  var AUTO = [
    ['order', 'Order updates', 'Automatic. The shopper\'s own phones hear when their order moves — the statuses that send an order email, behind the same "notify the customer" choice. Never capped, never held by quiet hours.'],
    ['stock', 'Back in stock', 'Only to phones that opened the product while it was sold out, and only once ever per phone and product.'],
    ['cart', 'Basket reminder', 'One reminder per basket, after the delay below, only while the basket is still there and nothing has been ordered since.'],
    ['price', 'Price drop', 'Only for products the phone opened while sold out or hearted, when the price falls by at least the threshold; at most once per product per phone in 30 days.']
  ];
  var rulesDraft = null, tplDraft = null;

  function renderAutomations(wrap) {
    var ov = st.ov, can = ov.can_send;
    if (!rulesDraft) rulesDraft = JSON.parse(JSON.stringify(ov.rules));
    if (!tplDraft) tplDraft = JSON.parse(JSON.stringify(ov.templates));
    var tplNames = { order: [], stock: ['stock_title', 'stock_body'], cart: ['cart_title', 'cart_body'], price: ['price_title', 'price_body'] };
    Object.keys(ov.status_labels).forEach(function (s) { tplNames.order.push('order_' + s + '_title', 'order_' + s + '_body'); });

    AUTO.forEach(function (a) {
      var key = a[0], on = rulesDraft[key + '_on'];
      var extra = [];
      if (key === 'order') {
        extra.push(el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Statuses' }), el('div', { class: 'pn-chips' }, Object.keys(ov.status_labels).map(function (s) {
          return chip(ov.status_labels[s], rulesDraft.order_statuses.indexOf(s) >= 0, function () { toggle(rulesDraft.order_statuses, s); render(); });
        }))]));
      }
      if (key === 'cart') {
        var h = el('input', { type: 'number', class: 'pn-in', min: 0.25, max: 1440, step: 0.25, value: rulesDraft.cart_hours, style: 'max-width:120px' });
        h.addEventListener('change', function () { rulesDraft.cart_hours = Number(h.value); });
        extra.push(el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Hours after the basket was last touched' }), h, el('small', { text: 'From 0.25 to 1440, the email reminder\'s range.' })]));
      }
      if (key === 'price') {
        var p = el('input', { type: 'number', class: 'pn-in', min: 1, max: 90, step: 1, value: rulesDraft.price_pct, style: 'max-width:120px' });
        p.addEventListener('change', function () { rulesDraft.price_pct = Number(p.value); });
        extra.push(el('div', { class: 'pn-field' }, [el('span', { class: 'pn-lbl', text: 'Smallest drop that counts (%)' }), p]));
      }
      var tpls = tplNames[key].map(function (n) {
        var t = tplDraft[n]; if (!t) return null;
        var en = el('input', { type: 'text', class: 'pn-in', value: t.en, 'aria-label': n + ' English', maxlength: 150 });
        en.addEventListener('input', function () { t.en = en.value; t.dirty = true; });
        var ar = el('input', { type: 'text', class: 'pn-in', value: t.ar, dir: 'rtl', lang: 'ar', 'aria-label': n + ' Arabic', maxlength: 150 });
        ar.addEventListener('input', function () { t.ar = ar.value; t.dirty = true; });
        var label = n.replace(/^order_/, '').replace(/_/g, ' ');
        return el('div', {}, [el('div', { class: 'pn-lbl' }, [label, el('span', { class: 'pn-badge' + (t.ar_status === 'published' ? ' ok' : ''), text: t.ar_status === 'published' ? 'Arabic in use' : (t.ar_status === 'draft' ? 'Arabic draft' : 'No Arabic') })]),
          el('div', { class: 'pn-tpl' }, [en, ar])]);
      });
      wrap.appendChild(card(null, null, [
        el('div', { class: 'pn-row', style: 'justify-content:space-between' }, [sw(on, a[1] + (on ? ': on' : ': off'), can ? function () { rulesDraft[key + '_on'] = !rulesDraft[key + '_on']; render(); } : null)]),
        el('p', { class: 'pn-sub', text: a[2] })
      ].concat(extra).concat([el('details', { style: 'margin-top:10px' }, [el('summary', { class: 'pn-lbl', text: 'Wording (English · Arabic)' }),
        el('p', { class: 'pn-sub', text: ':order is the order number, :product the product, :price its new price. An Arabic phone receives the Arabic once it is saved here or approved under Translation → Strings.' })].concat(tpls))])));
    });
    if (can) wrap.appendChild(el('div', { class: 'pn-row' }, [el('button', { type: 'button', class: 'pn-btn is-primary', text: 'Save automations', disabled: st.busy, onclick: saveAutomations })]));
  }

  async function saveAutomations() {
    await run(async function () {
      var r = await api('POST', '/settings', { rules: { order_on: rulesDraft.order_on, order_statuses: rulesDraft.order_statuses, stock_on: rulesDraft.stock_on, cart_on: rulesDraft.cart_on, cart_hours: rulesDraft.cart_hours, price_on: rulesDraft.price_on, price_pct: rulesDraft.price_pct } });
      st.ov.rules = r.rules; rulesDraft = null;
      var send = {};
      Object.keys(tplDraft).forEach(function (n) { if (tplDraft[n].dirty) send[n] = { en: tplDraft[n].en, ar: tplDraft[n].ar }; });
      if (Object.keys(send).length) {
        var t = await api('POST', '/templates', { templates: send });
        st.ov.templates = t.templates;
        if (!t.ok && t.error) st.banner = t.error;
      }
      tplDraft = null; say('Automations saved.');
    }, 'Not saved.');
  }

  /* -------------------------------------------------------- analytics */

  function bars(rows, label) {
    var max = 1; rows.forEach(function (r) { max = Math.max(max, r.n); });
    return el('div', { class: 'pn-bars' }, rows.length ? rows.map(function (r) {
      return el('div', { class: 'pn-bar' }, [el('span', { text: r.label || r.k || label }), el('span', {}, [el('i', { style: 'width:' + Math.round(r.n * 100 / max) + '%' })]), el('em', { text: num(r.n) })]);
    }) : [el('p', { class: 'pn-sub', text: 'No subscribers yet.' })]);
  }

  function renderAnalytics(wrap) {
    var a = st.an;
    if (!a) { wrap.appendChild(el('div', { class: 'pn-card pn-empty', text: st.busy ? 'Loading…' : 'Nothing to show.' })); return; }
    wrap.appendChild(el('div', { class: 'pn-tiles' }, [['Subscribed phones', a.active], ['Signed-in customers', a.customers], ['Guests', a.guests], ['Turned off (all time)', a.optouts], ['No longer reachable', a.gone]].map(function (t) {
      return el('div', { class: 'pn-tile' }, [el('b', { text: num(t[1]) }), el('span', { text: t[0] })]);
    })));
    var g = a.growth || [], gmax = 1; g.forEach(function (d) { gmax = Math.max(gmax, d.new); });
    var sum = g.reduce(function (s, d) { return s + d.new; }, 0), off = g.reduce(function (s, d) { return s + d.optout + d.gone; }, 0);
    wrap.appendChild(card('Growth, last 30 days', num(sum) + ' new · ' + num(off) + ' turned off or unreachable', [
      el('div', { class: 'pn-spark', role: 'img', 'aria-label': 'New subscriptions per day' }, g.map(function (d) { return el('i', { title: d.day + ': ' + d.new + ' new', style: 'height:' + Math.max(1, Math.round(d.new * 100 / gmax)) + '%' }); }))
    ]));
    wrap.appendChild(el('div', { class: 'pn-two' }, [
      card('By emirate', 'Where each phone is: its shopper\'s delivery address first, then the network.', [bars(a.emirates.filter(function (r) { return r.n > 0 || r.key !== 'outside'; }))]),
      card('By city', 'The 20 most common.', [bars(a.cities.map(function (r) { return { label: r.k, n: r.n }; }))]),
      card('By language', null, [bars(a.languages)]),
      card('By phone', null, [bars(a.platforms)]),
      card('How the place was found', 'Approximate places come from the IP address alone.', [bars(a.sources),
        el('p', { class: 'pn-sub' }, ['IP places: ', el('a', { href: a.attribution_url, target: '_blank', rel: 'noopener', text: a.attribution })])])
    ]));
    wrap.appendChild(card('Top campaigns', 'By click rate.', [(a.top || []).length ? el('div', { class: 'pn-scroll' }, [el('table', { class: 'pn-table' }, [
      el('thead', {}, [el('tr', {}, [el('th', { text: 'Campaign' }), el('th', { text: 'Sent' }), el('th', { class: 'n', text: 'Delivered' }), el('th', { class: 'n', text: 'Clicks' }), el('th', { class: 'n', text: 'CTR' })])]),
      el('tbody', {}, a.top.map(function (c) { return el('tr', { class: 'is-link', onclick: function () { st.tab = 'campaigns'; openCampaign(c.id); } }, [el('td', { text: c.title }), el('td', { text: when(c.at) }), el('td', { class: 'n', text: num(c.delivered) }), el('td', { class: 'n', text: num(c.clicks) }), el('td', { class: 'n', text: c.ctr + '%' })]); }))
    ])]) : el('p', { class: 'pn-sub', text: 'No campaign sent yet.' })]));
  }

  /* --------------------------------------------------------- settings */

  function renderSettings(wrap) {
    var ov = st.ov, can = ov.can_send;
    if (!rulesDraft) rulesDraft = JSON.parse(JSON.stringify(ov.rules));
    var r = rulesDraft;
    function numIn(key, min, max) { var i = el('input', { type: 'number', class: 'pn-in', min: min, max: max, value: r[key], style: 'max-width:110px', disabled: !can }); i.addEventListener('change', function () { r[key] = Number(i.value); }); return i; }
    function timeIn(key) { var i = el('input', { type: 'time', class: 'pn-in', value: r[key], style: 'max-width:130px', disabled: !can }); i.addEventListener('change', function () { r[key] = i.value; }); return i; }
    var g = ov.geo || {};
    wrap.appendChild(card('Frequency cap', 'The most marketing pushes one phone receives — campaigns, back in stock, basket and price drop together. Order updates never count. 0 means no limit.', [
      el('div', { class: 'pn-row', style: 'margin-top:12px' }, [el('span', { class: 'pn-lbl', text: 'Per day' }), numIn('cap_day', 0, 10), el('span', { class: 'pn-lbl', text: 'Per week' }), numIn('cap_week', 0, 30)])
    ]));
    wrap.appendChild(card('Quiet hours', 'Shop time (' + ov.zone + '). Nothing marketing is sent in these hours; scheduled campaigns and automations wait until they end. Order updates are not held.', [
      el('div', { class: 'pn-row', style: 'margin-top:12px' }, [sw(r.quiet_on, r.quiet_on ? 'On' : 'Off', can ? function () { r.quiet_on = !r.quiet_on; render(); } : null), el('span', { class: 'pn-lbl', text: 'From' }), timeIn('quiet_from'), el('span', { class: 'pn-lbl', text: 'to' }), timeIn('quiet_to')])
    ]));
    wrap.appendChild(card('Where phones are', 'Automatic, never asked: a phone\'s place comes from its shopper\'s delivery address (accurate), else the network\'s headers, else — with this on — an offline IP-to-emirate table for the UAE (approximate). The phone\'s own location would show the shopper a permission question, so it is never used.', [
      el('div', { class: 'pn-row', style: 'margin-top:12px' }, [sw(r.geo_ip, 'Locate by IP address when nothing better is known', can ? function () { r.geo_ip = !r.geo_ip; render(); } : null)]),
      el('p', { class: 'pn-sub', text: (g.ranges ? num(g.ranges) + ' UAE address ranges loaded' + (g.month ? ' (' + g.month + ')' : '') + '.' : 'No IP table loaded yet: it downloads itself within the hour once the cron line runs, then monthly.') + (g.error ? ' Last download failed: ' + g.error : '') }),
      el('p', { class: 'pn-sub' }, ['Data: ', el('a', { href: g.attribution_url, target: '_blank', rel: 'noopener', text: g.attribution }), ' (CC BY 4.0).'])
    ]));
    if (can) wrap.appendChild(el('div', { class: 'pn-row' }, [el('button', { type: 'button', class: 'pn-btn is-primary', text: 'Save settings', disabled: st.busy, onclick: saveSettings })]));
  }

  /* The sidebar row is AdminNav's (server-drawn); this returns it, adding nothing. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Push Notifications',
      icon: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
      group: 'Growth & Marketing',
      after: ['carttracking', 'searchterms', 'pixels']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();

  async function saveSettings() {
    await run(async function () {
      var r = await api('POST', '/settings', { rules: { cap_day: rulesDraft.cap_day, cap_week: rulesDraft.cap_week, quiet_on: rulesDraft.quiet_on, quiet_from: rulesDraft.quiet_from, quiet_to: rulesDraft.quiet_to, geo_ip: rulesDraft.geo_ip } });
      st.ov.rules = r.rules; rulesDraft = null; say('Settings saved.');
    }, 'Not saved.');
  }
})();
</script>
@endverbatim
