
(function () {
  'use strict';

  var SCREEN = 'carttracking';
  var TABS = [['carts', 'Carts'], ['added', 'Added products'], ['removed', 'Removed products'], ['blocked', 'Blocked'], ['settings', 'Settings']];
  var PERIODS = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days'], ['all', 'All time'], ['custom', 'Custom']];
  var PPERIODS = [['today', 'Today'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days'], ['all', 'All time']];

  var st = {
    tab: 'carts',
    q: { period: '7d', from: '', to: '', q: '', bot: '', bought: '', country: '', sort: 'last', dir: 'desc', page: 1 },
    data: null, err: '', busy: false, seq: 0,
    sel: {}, allMatching: false,
    prod: { added: { period: '7d', data: null }, removed: { period: '7d', data: null } },
    blocks: null, settings: null, blockedCount: null
  };

  /* ───────────────────────────────────────────── helpers ── */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* Only http(s) and site-relative URLs become an href; anything else is dropped. */
  function safeUrl(u) {
    u = String(u || '');
    return /^(https?:\/\/|\/)/i.test(u) && !/^\/\//.test(u) ? u : '';
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function flag(cc) {
    if (!cc || !/^[A-Z]{2}$/.test(cc)) return '';
    return String.fromCodePoint(127397 + cc.charCodeAt(0), 127397 + cc.charCodeAt(1));
  }
  /* "2026-10-04T14:32:10+04:00" → "4 Oct, 14:32" in the store's own clock (the offset the server sent). */
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function when(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso || '');
    if (!m) return '—';
    return (+m[3]) + ' ' + MONTHS[+m[2] - 1] + (String(new Date().getFullYear()) === m[1] ? '' : ' ' + m[1]) + ', ' + m[4] + ':' + m[5];
  }
  function ago(iso) {
    var t = Date.parse(iso || '');
    if (!t) return '';
    var s = Math.max(0, (Date.now() - t) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    if (s < 86400 * 30) return Math.floor(s / 86400) + ' d ago';
    return '';
  }
  function icon(path, size) {
    return '<svg width="' + (size || 16) + '" height="' + (size || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }
  var I = {
    ban: '<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>',
    lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    dl: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
    trash: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
    warn: '<path d="M12 3 2 21h20z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>'
  };

  async function api(path, opts) {
    opts = opts || {};
    var r = await fetch(base() + '/admin-api/cart-tracking' + path, {
      method: opts.method || 'GET',
      headers: Object.assign({ Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
        opts.body ? { 'Content-Type': 'application/json' } : {}),
      credentials: 'same-origin',
      body: opts.body ? JSON.stringify(opts.body) : undefined
    });
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) {
      var err = new Error((body && body.message) || ('cart-tracking ' + r.status));
      err.status = r.status; err.body = body;
      throw err;
    }
    return body;
  }
  /* Before the CSV navigation: is the session alive and the role allowed?
     Never throws; says why on this screen when the answer is no. */
  async function downloadOk(query) {
    try {
      await api('/export?' + query + '&probe=1');
      return true;
    } catch (e) {
      toast(e && (e.status === 401 || e.status === 419)
        ? 'Your session has ended, so the download was not started. Sign in again; this screen keeps its filters.'
        : failText(e) + ' Nothing was downloaded.', true);
      return false;
    }
  }
  function failText(e) {
    if (e && e.status === 403) return 'Your role cannot use Cart Tracking. An owner or manager can.';
    if (e && e.status === 404 && !(e.body && e.body.message)) return 'This page is not in the server\'s route table yet. Clear the route cache and reload.';
    return (e && e.body && e.body.message) || 'Could not load Cart Tracking. Try again in a moment.';
  }
  function toast(msg, bad) {
    if (typeof window.toast === 'function') { try { window.toast(msg, bad ? 'bad' : undefined); return; } catch (e) {} }
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:' + (bad ? '#b4231a' : '#101729') + ';color:#fff;padding:10px 16px;border-radius:12px;font-size:13px;z-index:999;max-width:90vw';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }
  function qs(obj) {
    var p = new URLSearchParams();
    Object.keys(obj).forEach(function (k) { if (obj[k] !== '' && obj[k] != null) p.set(k, obj[k]); });
    return p.toString();
  }

  /* ───────────────────────────────────────────── shell ── */

  function root() { return document.querySelector('[data-ctk]'); }

  function shell() {
    var host = document.getElementById('content');
    if (!host) return null;
    if (!host.querySelector('[data-ctk]')) {
      var tabs = window.kbbTabs;
      var current = tabs ? tabs.pick('carttracking', TABS.map(function (t) { return t[0]; }), 'carts') : 'carts';
      st.tab = current;
      var bar = tabs
        ? tabs.bar('carttracking', TABS.map(function (t) { return [t[0], t[1], '', '']; }), current, 'Cart Tracking sections')
        : '<div class="ectabs kbb-tabs" role="tablist" data-kbt="carttracking">' + TABS.map(function (t) {
            return '<button type="button" class="ectab kbb-tab' + (t[0] === current ? ' on' : '') + '" role="tab" data-kbt-tab="' + t[0] + '">' + t[1] + '</button>';
          }).join('') + '</div>';
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>Cart Tracking</h2>'
        + '<p>Every cart on the shop: what went in and came out, from where, whether it was a person or a bot, and whether it became an order. '
        + 'Block an address — or its whole range — with the shield beside it.</p></div>'
        + bar
        + '<p class="ectabs-hint"><b>Carts</b> is every basket, newest first, with its visitor and its order. <b>Added</b> and <b>Removed products</b> rank what goes in and comes out. '
        + '<b>Blocked</b> is the shop\'s one block list — what a blocked address cannot do is set in <b>Settings</b>, with the bot rules and how long history is kept.</p>'
        + '<div class="ctk" data-ctk>'
        + TABS.map(function (t) {
            return '<div' + (tabs ? tabs.panel('carttracking', t[0], current) : ' data-kbt-panel="carttracking" data-kbt-id="' + t[0] + '"' + (t[0] === current ? '' : ' hidden')) + ' data-ctk-panel="' + t[0] + '"></div>';
          }).join('')
        + '</div></div>';
      ensureOverlays();
    }
    return root();
  }

  function panel(id) { return document.querySelector('[data-ctk-panel="' + id + '"]'); }

  function ensureOverlays() {
    if (document.getElementById('ctkPanel')) return;
    var d = document.createElement('div');
    d.innerHTML = '<div class="ctk-scrim" id="ctkScrim"></div>'
      + '<aside class="ctk-panel" id="ctkPanel" role="dialog" aria-modal="true" aria-labelledby="ctkPanelTitle">'
      + '<div class="ctk-ph"><h3 id="ctkPanelTitle">Cart</h3><button type="button" class="ctk-x" data-ctk-close aria-label="Close">' + icon(I.x, 18) + '</button></div>'
      + '<div class="ctk-pb" id="ctkPanelBody"></div></aside>'
      + '<div class="ctk-pop" id="ctkPop" role="dialog" aria-label="Block"></div>';
    while (d.firstChild) document.body.appendChild(d.firstChild);
  }

  function show(tab) {
    st.tab = tab;
    if (tab === 'carts') loadCarts();
    else if (tab === 'added' || tab === 'removed') loadProducts(tab);
    else if (tab === 'blocked') loadBlocks();
    else if (tab === 'settings') loadSettings();
  }

  /* ───────────────────────────────────────────── Carts ── */

  async function loadCarts() {
    var mine = ++st.seq;
    st.busy = true; st.err = '';
    paintCarts();
    try {
      var body = await api('?' + qs(st.q));
      if (mine !== st.seq) return;
      st.data = body;
    } catch (e) {
      if (mine !== st.seq) return;
      st.err = failText(e);
    }
    st.busy = false;
    paintCarts();
  }

  function chips(list, on, attr) {
    return '<div class="ctk-chips" role="group">' + list.map(function (p) {
      return '<button type="button" class="ctk-chip' + (on === p[0] ? ' on' : '') + '" ' + attr + '="' + p[0] + '">' + esc(p[1]) + '</button>';
    }).join('') + '</div>';
  }

  function sel(name, value, options, label) {
    return '<select class="ctk-sel" data-ctk-f="' + name + '" aria-label="' + esc(label) + '">' + options.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(value) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }

  function paintCarts() {
    var p = panel('carts');
    if (!p) return;
    var d = st.data, s = (d && d.summary) || { carts: 0, bought: 0, conversion: 0, bots: 0, bot_share: 0, countries: [], open_value: '', bought_value: '' };
    var q = st.q;

    var countryOpts = [['', 'All countries'], ['--', 'Unknown']].concat((s.countries || []).filter(function (c) { return c.code; }).map(function (c) {
      return [c.code, flag(c.code) + ' ' + c.name];
    }));
    if (q.country && q.country !== '--' && !countryOpts.some(function (o) { return o[0] === q.country; })) countryOpts.push([q.country, q.country]);

    var html = '';

    if (d && d.health) {
      html += '<div class="ctk-warn">' + icon(I.warn, 18) + '<div>' + esc(d.health) + '</div></div>';
    }

    html += '<div class="ctk-card"><div class="ctk-row" style="justify-content:space-between;gap:10px">'
      + chips(PERIODS, q.period, 'data-ctk-period')
      + '</div>'
      + '<div class="ctk-dates' + (q.period === 'custom' ? ' on' : '') + '" style="margin-top:10px">From <input type="date" class="ctk-in" data-ctk-f="from" value="' + esc(q.from) + '"> to <input type="date" class="ctk-in" data-ctk-f="to" value="' + esc(q.to) + '"></div>'
      + '</div>';

    html += '<div class="ctk-tiles">'
      + tile('Carts', num(s.carts), s.open_value ? 'Open baskets ' + s.open_value : '', '#3f6fe0')
      + tile('Became orders', num(s.bought), s.conversion + '% conversion' + (s.bought_value ? ' · ' + s.bought_value : ''), '#15a85a')
      + tile('Bots', num(s.bots), s.bot_share + '% of carts', '#e3493f')
      + '<div class="ctk-tile" style="--tile:#7b6cf0"><span class="k">Top countries</span>'
      + '<div class="ctk-flags">' + ((s.countries || []).slice(0, 6).map(function (c) {
          return '<button type="button" class="ctk-flagchip" data-ctk-country="' + esc(c.code || '--') + '" title="Show only ' + esc(c.name) + '">' + (c.code ? flag(c.code) + ' ' + esc(c.code) : 'Unknown') + ' <span style="color:var(--ink-soft)">' + num(c.n) + '</span></button>';
        }).join('') || '<span class="s">No carts yet</span>') + '</div></div>'
      + '</div>';

    html += '<div class="ctk-card">'
      + '<div class="ctk-row">'
      + '<div class="ctk-search">' + icon(I.search, 15) + '<input type="search" class="ctk-in" id="ctkFind" enterkeyhint="search" placeholder="Product, IP, country, email or order #" value="' + esc(q.q) + '" aria-label="Search carts"></div>'
      + sel('bot', q.bot, [['', 'Bot: any'], ['yes', 'Bot: yes'], ['no', 'Bot: no']], 'Bot')
      + sel('bought', q.bought, [['', 'Orders: any'], ['yes', 'Purchased'], ['no', 'Not purchased']], 'Purchased')
      + sel('country', q.country, countryOpts, 'Country')
      + '<button type="button" class="ctk-btn" data-ctk-csv>' + icon(I.dl, 15) + ' CSV</button>'
      + '</div>';

    var n = selectedIds().length;
    var total = d ? d.total : 0;
    html += '<div class="ctk-bulk' + (n || st.allMatching ? ' on' : '') + '" style="margin-top:12px">'
      + '<b>' + (st.allMatching ? 'All ' + num(total) + ' matching' : num(n) + ' selected') + '</b>'
      + (!st.allMatching && d && n === d.rows.length && total > n ? '<button type="button" class="ctk-linkbtn" data-ctk-allmatch>Select all ' + num(total) + ' matching</button>' : '')
      + '<span class="sp"></span>'
      + '<button type="button" class="ctk-btn" data-ctk-bulk="block_ip">' + icon(I.ban, 14) + ' Block addresses</button>'
      + '<button type="button" class="ctk-btn" data-ctk-bulk="block_range">' + icon(I.ban, 14) + ' Block ranges</button>'
      + '<button type="button" class="ctk-btn" data-ctk-bulk="export">' + icon(I.dl, 14) + ' Export</button>'
      + '<button type="button" class="ctk-btn bad" data-ctk-bulk="delete">' + icon(I.trash, 14) + ' Delete</button>'
      + '<button type="button" class="ctk-btn" data-ctk-bulk="clear" aria-label="Clear selection">' + icon(I.x, 14) + '</button>'
      + '</div>';

    if (st.err) {
      html += '<div class="ctk-empty"><b>Something went wrong</b>' + esc(st.err) + '</div>';
    } else if (!d) {
      html += '<div class="ctk-empty">Loading carts…</div>';
    } else if (!d.rows.length) {
      html += '<div class="ctk-empty"><b>No carts here</b>' + (st.busy ? 'Loading…' : 'Nothing matches this period and these filters yet.') + '</div>';
    } else {
      var allOn = d.rows.every(function (r) { return st.sel[r.id]; });
      html += '<div class="ctk-tablewrap" style="margin-top:8px"><table class="ctk-t"><thead><tr>'
        + '<th style="width:30px"><input type="checkbox" class="ctk-chk" data-ctk-selall' + (allOn ? ' checked' : '') + ' aria-label="Select all on this page"></th>'
        + th('id', 'Cart #') + th('last', 'Last update')
        + '<th>Products</th><th>Customer / IP</th><th>Bot</th><th>Removed</th>'
        + th('value', 'Value', true) + '<th>Order</th>'
        + '</tr></thead><tbody>'
        + d.rows.map(rowHtml).join('')
        + '</tbody></table></div>'
        + pager(d);
    }

    html += '</div>';

    var hadFocus = document.activeElement && document.activeElement.id === 'ctkFind';
    var caret = hadFocus ? document.activeElement.selectionStart : null;
    p.innerHTML = html;
    if (hadFocus) {
      var f = document.getElementById('ctkFind');
      if (f) { f.focus(); try { f.setSelectionRange(caret, caret); } catch (e) {} }
    }
  }

  function tile(k, v, s, color) {
    return '<div class="ctk-tile" style="--tile:' + color + '"><span class="k">' + esc(k) + '</span><span class="v">' + esc(v) + '</span>'
      + (s ? '<span class="s">' + esc(s) + '</span>' : '') + '</div>';
  }

  function th(key, label, right) {
    var on = st.q.sort === key;
    var arrow = on ? (st.q.dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th class="sortable' + (on ? ' on' : '') + (right ? ' ctk-num' : '') + '" data-ctk-sort="' + key + '" aria-sort="' + (on ? (st.q.dir === 'asc' ? 'ascending' : 'descending') : 'none') + '">' + esc(label) + arrow + '</th>';
  }

  function prodList(list, cls) {
    if (!list || !list.length) return '';
    var shown = list.slice(0, 4).map(function (p) {
      var u = safeUrl(p.url);
      var name = esc(p.name);
      return '<div>' + (u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener" data-ctk-stop>' + name + '</a>' : '<span>' + name + '</span>')
        + ' <span class="ctk-qty">×' + num(p.qty) + '</span></div>';
    }).join('');
    return '<div class="ctk-prods ' + (cls || '') + '">' + shown + (list.length > 4 ? '<span class="ctk-more">+' + (list.length - 4) + ' more</span>' : '') + '</div>';
  }

  function botHtml(r) {
    var tip = '<b>' + (r.bot ? 'Looks like a bot' : 'Looks like a person') + ' · score ' + r.score + '</b>'
      + (r.reasons.length ? r.reasons.map(function (x) { return '<div><i>+' + x.points + '</i> ' + esc(x.text) + '</div>'; }).join('') : '<div>No bot signals on this cart.</div>');
    return '<span class="ctk-bot" tabindex="0"><span class="ctk-pill ' + (r.bot ? 'yes' : 'no') + '">' + (r.bot ? 'Yes' : 'No') + '</span>'
      + '<span class="ctk-tip" role="tooltip">' + tip + '</span></span>';
  }

  function whoHtml(r) {
    var name = r.customer ? (r.customer.name || r.customer.email) : (r.email || '');
    var sub = r.customer && r.customer.name ? r.customer.email : (r.customer ? '' : (r.email ? 'Guest' : 'Guest, no email'));
    var shield = r.ip
      ? '<button type="button" class="ctk-shield' + (r.blocked ? ' on' : '') + '" data-ctk-shield="' + r.id + '" data-stop title="' + (r.blocked ? 'Blocked (' + esc(r.blocked.cidr) + ') — click to unblock' : 'Block this address or its range') + '" aria-label="' + (r.blocked ? 'Unblock' : 'Block') + '">' + icon(r.blocked ? I.lock : I.ban, 15) + '</button>'
      : '';
    return '<div class="ctk-who">'
      + (name ? '<div class="nm">' + esc(name) + '</div>' : '')
      + (sub ? '<span class="ctk-sub">' + esc(sub) + '</span>' : '')
      + '<div class="ctk-ipline">' + (r.country ? '<span class="ctk-cc" title="' + esc(r.country_name) + '"><span class="fl">' + flag(r.country) + '</span>' + esc(r.country) + '</span>' : '<span class="ctk-cc">??</span>')
      + '<span class="ctk-ip">' + esc(r.ip || 'no address') + '</span>' + shield + '</div></div>';
  }

  function orderHtml(o) {
    if (!o) return '<div class="ctk-order"><span class="no">Not purchased</span></div>';
    var tone = /cancel|fail|refund/.test(o.status) ? 'yes' : (/complet|deliver/.test(o.status) ? 'no' : (/process|ship/.test(o.status) ? 'blue' : 'amber'));
    return '<div class="ctk-order"><span class="num">#' + esc(o.number) + '</span>'
      + '<span class="ctk-pill ' + tone + '">' + esc(o.status) + '</span>'
      + (o.cod ? '<span class="ctk-pill mute">COD</span>' : '') + '</div>';
  }

  function rowHtml(r) {
    return '<tr data-ctk-open="' + r.id + '" class="' + (st.sel[r.id] ? 'sel' : '') + '">'
      + '<td class="c-chk" data-stop><input type="checkbox" class="ctk-chk" data-ctk-sel="' + r.id + '"' + (st.sel[r.id] ? ' checked' : '') + ' aria-label="Select cart ' + r.id + '"></td>'
      + '<td class="c-id"><span class="ctk-id">#' + r.id + '</span>' + (r.status === 'merged' ? ' <span class="ctk-pill mute">merged</span>' : '') + '</td>'
      + '<td class="c-when ctk-when">' + esc(when(r.last)) + '<small>' + esc(ago(r.last)) + '</small></td>'
      + '<td class="c-prod" data-l="Products">' + (prodList(r.products) || '<span class="ctk-more">Empty now</span>') + '</td>'
      + '<td class="c-who" data-l="Customer / IP">' + whoHtml(r) + '</td>'
      + '<td class="c-bot">' + botHtml(r) + '</td>'
      + '<td class="c-rem"' + (r.removed.length ? ' data-l="Removed"' : '') + '>' + prodList(r.removed, 'ctk-rem') + '</td>'
      + '<td class="c-val ctk-num"><span class="ctk-val">' + esc(r.value_label) + '</span></td>'
      + '<td class="c-ord">' + orderHtml(r.order) + '</td>'
      + '</tr>';
  }

  function pager(d) {
    var from = (d.page - 1) * d.per_page + 1, to = Math.min(d.total, d.page * d.per_page);
    var pages = [], i;
    var lo = Math.max(1, d.page - 2), hi = Math.min(d.pages, d.page + 2);
    if (lo > 1) pages.push(1);
    if (lo > 2) pages.push('…');
    for (i = lo; i <= hi; i++) pages.push(i);
    if (hi < d.pages - 1) pages.push('…');
    if (hi < d.pages) pages.push(d.pages);
    return '<div class="ctk-pager"><span>Showing ' + num(from) + '–' + num(to) + ' of ' + num(d.total) + ' carts</span><div class="pg">'
      + '<button type="button" data-ctk-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page">‹</button>'
      + pages.map(function (n) {
          return n === '…' ? '<button type="button" disabled>…</button>' : '<button type="button" data-ctk-page="' + n + '" class="' + (n === d.page ? 'on' : '') + '">' + n + '</button>';
        }).join('')
      + '<button type="button" data-ctk-page="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + ' aria-label="Next page">›</button>'
      + '</div></div>';
  }

  function selectedIds() { return Object.keys(st.sel).filter(function (k) { return st.sel[k]; }).map(Number); }

  /* The one CSV navigation (the file streams with the session cookie), and it
     is gated: downloadOk() asks the export first (?probe=1, answered before any
     query), so a dead session is said on this screen instead of the console
     being replaced by a login page. */
  async function exportCsv(query) {
    if (!(await downloadOk(query))) return;
    var u = base() + '/admin-api/cart-tracking/export?' + query;
    window.location.href = u;
  }

  async function bulk(action) {
    var ids = selectedIds();
    if (action === 'clear') { st.sel = {}; st.allMatching = false; paintCarts(); return; }
    if (action === 'export') {
      await exportCsv(st.allMatching ? qs(Object.assign({}, st.q, { page: '' })) : 'ids=' + ids.join(','));
      return;
    }
    var count = st.allMatching ? (st.data ? st.data.total : 0) : ids.length;
    var verb = { block_ip: 'Block the address of', block_range: 'Block the whole range (/24) of', delete: 'Delete' }[action];
    var warn = action === 'delete' ? '\n\nTheir history goes with them. An active basket a shopper is still using is emptied.' : '\n\nBlocked addresses cannot add to cart, check out, place any order or submit forms.';
    if (!window.confirm(verb + ' ' + num(count) + ' cart' + (count === 1 ? '' : 's') + '?' + warn)) return;
    var body = { action: action };
    if (st.allMatching) { body.all = 1; Object.assign(body, st.q); } else { body.ids = ids; }
    try {
      var r = await api('/bulk', { method: 'POST', body: body });
      toast(r.message + (r.refused && r.refused.length ? ' ' + r.refused[0] : ''));
      st.sel = {}; st.allMatching = false; st.blocks = null;
      loadCarts();
    } catch (e) { toast(failText(e), true); }
  }

  /* ───────────────────────────────────────────── block popover ── */

  function rowById(id) {
    var rows = (st.data && st.data.rows) || [];
    for (var i = 0; i < rows.length; i++) if (rows[i].id === id) return rows[i];
    return st.detail && st.detail.id === id ? st.detail : null;
  }

  function rangeOf(ip) {
    if (!ip) return '';
    if (ip.indexOf(':') === -1) return ip.split('.').slice(0, 3).join('.') + '.0/24';
    return '';
  }

  function openPop(at, r) {
    var pop = document.getElementById('ctkPop');
    if (!pop || !r) return;
    if (r.blocked) {
      pop.innerHTML = '<h5>Unblock ' + esc(r.blocked.cidr) + '?</h5>'
        + '<p class="ctk-note" style="margin-bottom:10px">Every address in it can add to cart, check out and send forms again straight away.</p>'
        + '<div class="acts"><button type="button" class="ctk-btn" data-ctk-popclose>Cancel</button><button type="button" class="ctk-btn pri" data-ctk-unblock="' + r.blocked.id + '">Unblock</button></div>';
    } else {
      var range = r.net || rangeOf(r.ip);
      pop.innerHTML = '<h5>Block this visitor</h5><div class="opt">'
        + '<label class="r"><input type="radio" name="ctkMode" value="single" checked><span>Just this address<code>' + esc(r.ip) + '</code></span></label>'
        + '<label class="r"><input type="radio" name="ctkMode" value="range"><span>The whole range<code>' + esc(range) + '</code></span></label></div>'
        + '<input class="ctk-in" id="ctkReason" maxlength="190" placeholder="Reason (e.g. Fake COD order)" value="' + (r.bot ? 'Bot' : '') + '">'
        + '<select class="ctk-sel" id="ctkDays"><option value="0">Until I unblock it</option><option value="1">For 1 day</option><option value="7">For 7 days</option><option value="30">For 30 days</option><option value="90">For 90 days</option><option value="365">For a year</option></select>'
        + '<div class="acts"><button type="button" class="ctk-btn" data-ctk-popclose>Cancel</button><button type="button" class="ctk-btn bad" data-ctk-doblock="' + r.id + '" data-ip="' + esc(r.ip) + '">' + icon(I.ban, 14) + ' Block</button></div>';
    }
    pop.classList.add('on');
    // Placed at the click, by CSS: the pointer's own coordinates go in as two
    // custom properties and calc() keeps the box on screen. Nothing measures
    // an element.
    pop.style.setProperty('--x', (at && at.clientX ? at.clientX : 0) + 'px');
    pop.style.setProperty('--y', (at && at.clientY ? at.clientY : 0) + 'px');
    var first = pop.querySelector('input,button');
    if (first) first.focus();
  }

  function closePop() { var p = document.getElementById('ctkPop'); if (p) p.classList.remove('on'); }

  async function doBlock(btn) {
    var pop = document.getElementById('ctkPop');
    var mode = (pop.querySelector('input[name=ctkMode]:checked') || {}).value || 'single';
    btn.disabled = true;
    try {
      var r = await api('/blocks', { method: 'POST', body: {
        target: btn.getAttribute('data-ip'), mode: mode,
        reason: (document.getElementById('ctkReason') || {}).value || '',
        days: Number((document.getElementById('ctkDays') || {}).value || 0),
        cart_id: Number(btn.getAttribute('data-ctk-doblock'))
      } });
      toast(r.message);
      closePop();
      st.blocks = null;
      refreshAfterBlock();
    } catch (e) {
      toast(failText(e), true);
      btn.disabled = false;
    }
  }

  async function doUnblock(id, btn) {
    if (btn) btn.disabled = true;
    try {
      var r = await api('/blocks/' + id + '/unblock', { method: 'POST', body: {} });
      toast(r.message);
      closePop();
      st.blocks = null;
      refreshAfterBlock();
    } catch (e) { toast(failText(e), true); if (btn) btn.disabled = false; }
  }

  function refreshAfterBlock() {
    if (st.tab === 'blocked') loadBlocks();
    if (st.tab === 'carts') loadCarts();
    if (st.detail) openCart(st.detail.id);
  }

  /* ───────────────────────────────────────────── one cart ── */

  async function openCart(id) {
    ensureOverlays();
    var pnl = document.getElementById('ctkPanel'), scrim = document.getElementById('ctkScrim');
    var body = document.getElementById('ctkPanelBody');
    document.getElementById('ctkPanelTitle').textContent = 'Cart #' + id;
    if (!st.detail || st.detail.id !== id) body.innerHTML = '<div class="ctk-empty">Loading cart #' + esc(id) + '…</div>';
    pnl.classList.add('on'); scrim.classList.add('on');
    try {
      var c = await api('/carts/' + id);
      st.detail = c;
      paintDetail(c);
    } catch (e) {
      body.innerHTML = '<div class="ctk-empty"><b>Could not open this cart</b>' + esc(failText(e)) + '</div>';
    }
  }

  function closeCart() {
    var pnl = document.getElementById('ctkPanel'), scrim = document.getElementById('ctkScrim');
    if (pnl) pnl.classList.remove('on');
    if (scrim) scrim.classList.remove('on');
    st.detail = null;
    closePop();
  }

  function paintDetail(c) {
    var body = document.getElementById('ctkPanelBody');
    var scoreColor = c.bot ? '#e3493f' : (c.score >= 25 ? '#e0922f' : '#15a85a');
    var tl = c.timeline.map(function (e) {
      var sign = e.type === 'add' ? '+' : (e.type === 'remove' ? '−' : '↕');
      var verb = e.type === 'add' ? 'Added' : (e.type === 'remove' ? 'Removed' : 'Changed quantity of');
      var u = safeUrl(e.url);
      var name = u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(e.product) + '</a>' : esc(e.product);
      var qty = e.type === 'qty' ? ' to ' + e.qty_after + ' (' + (e.qty > 0 ? '+' : '') + e.qty + ')' : ' ×' + Math.abs(e.qty);
      return '<li><span class="ctk-dot ' + e.type + '">' + sign + '</span><div class="t">' + verb + ' ' + name + (e.variant ? ' <span class="ctk-more">' + esc(e.variant) + '</span>' : '') + esc(qty)
        + '<small>' + esc(when(e.at)) + ' · ' + esc(e.price) + ' each' + (e.ip ? ' · from ' + esc(e.ip) : '') + (e.country ? ' ' + flag(e.country) : '') + '</small></div></li>';
    });
    if (c.order) {
      tl.push('<li><span class="ctk-dot order">✓</span><div class="t">Became order <b>#' + esc(c.order.number) + '</b> — ' + esc(c.order.status) + ', ' + esc(c.order.total) + (c.order.cod ? ' · cash on delivery' : '') + '<small>' + esc(when(c.order.at)) + '</small></div></li>');
    }

    var you = c.you === c.ip;
    var blockBtns = !c.ip ? '' : (c.blocked
      ? '<button type="button" class="ctk-btn pri" data-ctk-unblock="' + c.blocked.id + '">' + icon(I.lock, 14) + ' Unblock ' + esc(c.blocked.cidr) + '</button>'
      : (you ? '<span class="ctk-pill mute">This is your own address</span>'
        : '<button type="button" class="ctk-btn bad" data-ctk-shield="' + c.id + '">' + icon(I.ban, 14) + ' Block…</button>'));

    var rel = c.related || { carts: [], orders: [] };

    body.innerHTML = '<div class="ctk-kv">'
      + '<div><span class="k">Value</span><span class="v">' + esc(c.value_label) + '</span></div>'
      + '<div><span class="k">Order</span><span class="v">' + (c.order ? '#' + esc(c.order.number) : '<span style="color:var(--ink-faint)">Not purchased</span>') + '</span></div>'
      + '<div><span class="k">First seen</span><span class="v">' + esc(when(c.first)) + '</span></div>'
      + '<div><span class="k">Last update</span><span class="v">' + esc(when(c.last)) + '</span></div>'
      + '</div>'

      + '<div class="ctk-card ctk-sec"><h4>Visitor</h4>'
      + whoHtml(Object.assign({}, c, { blocked: null, ip: c.ip })).replace(/<button[^>]*data-ctk-shield[^>]*>[\s\S]*?<\/button>/, '')
      + '<div class="ctk-ipline" style="margin-top:8px">' + (c.net ? '<span class="ctk-pill mute">Range ' + esc(c.net) + '</span>' : '')
      + (c.hosting ? '<span class="ctk-pill amber">Datacenter / VPN</span>' : '') + (c.country_name ? '<span class="ctk-pill mute">' + flag(c.country) + ' ' + esc(c.country_name) + '</span>' : '') + '</div>'
      + (c.ua ? '<div class="ctk-ua">' + esc(c.ua) + '</div>' : '<div class="ctk-ua">No user agent was sent.</div>')
      + '<div class="ctk-row" style="margin-top:10px">' + blockBtns + (c.range_blocked && (!c.blocked || c.blocked.id !== c.range_blocked.id) ? '<span class="ctk-pill yes">Range blocked</span>' : '') + '</div>'
      + '</div>'

      + '<div class="ctk-card ctk-sec"><h4>Bot or person?</h4>'
      + '<div class="ctk-row" style="justify-content:space-between"><span class="ctk-pill ' + (c.bot ? 'yes' : 'no') + '">Bot: ' + (c.bot ? 'Yes' : 'No') + '</span><b style="font-variant-numeric:tabular-nums">' + c.score + ' / 100</b></div>'
      + '<div class="ctk-score"><i style="width:' + Math.min(100, c.score) + '%;background:' + scoreColor + '"></i></div>'
      + (c.reasons.length ? '<ul class="ctk-reasons">' + c.reasons.map(function (x) { return '<li><span>' + esc(x.text) + '</span><b>+' + x.points + '</b></li>'; }).join('') + '</ul>'
        : '<p class="ctk-note">No bot signals: a real browser that ran the shop\'s script' + (c.speed_ms ? ', ' + (c.speed_ms / 1000).toFixed(1) + ' s after the page opened' : '') + '.</p>')
      + '</div>'

      + '<div class="ctk-card ctk-sec"><h4>In the cart now</h4>' + (prodList(c.products) || '<p class="ctk-note">Empty.</p>')
      + (c.removed.length ? '<h4 style="margin-top:14px">Taken out</h4>' + prodList(c.removed, 'ctk-rem') : '') + '</div>'

      + '<div class="ctk-card ctk-sec"><h4>History</h4>' + (tl.length ? '<ul class="ctk-tl">' + tl.join('') + '</ul>' : '<p class="ctk-note">No tracked changes.</p>') + '</div>'

      + '<div class="ctk-card ctk-sec"><h4>This shopper\'s orders</h4>'
      + (rel.orders.length ? '<div class="ctk-list">' + rel.orders.map(function (o) {
          return '<div class="ctk-li"><span><b>#' + esc(o.number) + '</b> · ' + esc(when(o.at)) + '<span class="ctk-sub">matched by ' + esc(o.why) + (o.cod ? ' · COD' : '') + '</span></span>'
            + '<span style="text-align:right"><b>' + esc(o.total) + '</b><span class="ctk-sub">' + esc(o.status) + '</span></span></div>';
        }).join('') + '</div>' : '<p class="ctk-note">No other orders from this account, email or address.</p>')
      + '<h4 style="margin-top:14px">Other carts</h4>'
      + (rel.carts.length ? '<div class="ctk-list">' + rel.carts.map(function (o) {
          return '<div class="ctk-li"><span><button type="button" class="ctk-linkish" data-ctk-open-cart="' + o.id + '">Cart #' + o.id + '</button> · ' + esc(when(o.last)) + '<span class="ctk-sub">same ' + esc(o.why) + (o.bot ? ' · bot' : '') + '</span></span>'
            + '<span style="text-align:right"><b>' + esc(o.value) + '</b><span class="ctk-sub">' + (o.bought ? 'purchased' : 'not purchased') + '</span></span></div>';
        }).join('') + '</div>' : '<p class="ctk-note">No other carts from this account or address.</p>')
      + '</div>';
  }

  /* ───────────────────────────────────────────── products ── */

  async function loadProducts(kind) {
    var p = panel(kind);
    var s = st.prod[kind];
    paintProducts(kind, true);
    try {
      s.data = await api('/products?kind=' + kind + '&period=' + s.period);
    } catch (e) {
      s.data = { err: failText(e) };
    }
    paintProducts(kind, false);
  }

  function paintProducts(kind, loading) {
    var p = panel(kind);
    if (!p) return;
    var s = st.prod[kind], d = s.data;
    var added = kind === 'added';
    var html = '<div class="ctk-card"><div class="ctk-row" style="justify-content:space-between">'
      + chips(PPERIODS, s.period, 'data-ctk-pperiod="' + kind + '" data-ctk-pp')
      + '<span class="ctk-note">Top 40 · ranked by how many carts</span></div></div><div class="ctk-card">';
    if (d && d.err) {
      html += '<div class="ctk-empty"><b>Something went wrong</b>' + esc(d.err) + '</div>';
    } else if (!d || (loading && !d.rows)) {
      html += '<div class="ctk-empty">Loading…</div>';
    } else if (!d.rows.length) {
      html += '<div class="ctk-empty"><b>Nothing yet</b>No product was ' + (added ? 'added to' : 'removed from') + ' a cart in this period.</div>';
    } else {
      var max = Math.max(1, d.max || 1);
      html += '<div class="ctk-rank"><div class="ctk-rk h' + (added ? '' : ' rm') + '"><span class="n">#</span><span>Product</span><span class="c">' + (added ? 'Times added' : 'Times removed') + '</span><span class="c">Carts</span>' + (added ? '<span class="c cv">Became orders</span>' : '') + '</div>'
        + d.rows.map(function (r) {
            var u = safeUrl(r.url);
            return '<div class="ctk-rk' + (added ? '' : ' rm rmrow') + '"><span class="n">' + r.rank + '</span>'
              + '<span class="nm">' + (u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(r.name) + '</a>' : '<span>' + esc(r.name) + '</span>')
              + '<span class="ctk-sub">Product ID ' + esc(r.id) + '</span><div class="ctk-bar"><i style="width:' + Math.round(100 * r.carts / max) + '%"></i></div></span>'
              + '<span class="c">' + num(r.times) + '</span><span class="c">' + num(r.carts) + '</span>'
              + (added ? '<span class="c cv"><span class="ctk-conv">' + num(r.ordered) + ' <span class="ctk-more">(' + r.conversion + '%)</span><span class="ring"><i style="width:' + Math.min(100, r.conversion) + '%"></i></span></span></span>' : '')
              + '</div>';
          }).join('') + '</div>';
    }
    html += '<p class="ctk-note" style="margin-top:12px">' + (added
      ? '<b>Times added</b> counts every press of Add to cart; <b>carts</b> counts different baskets; <b>became orders</b> is how many of those baskets were then bought.'
      : '<b>Times removed</b> counts every removal, including setting the quantity to zero; <b>carts</b> counts different baskets.')
      + ' "All time" keeps counting events after they pass the retention period.</p></div>';
    p.innerHTML = html;
  }

  /* ───────────────────────────────────────────── blocked ── */

  async function loadBlocks() {
    var p = panel('blocked');
    if (p && !st.blocks) p.innerHTML = '<div class="ctk-card"><div class="ctk-empty">Loading…</div></div>';
    try {
      st.blocks = await api('/blocks');
    } catch (e) {
      st.blocks = { err: failText(e), rows: [] };
    }
    paintBlocks();
  }

  function paintBlocks() {
    var p = panel('blocked');
    if (!p) return;
    var b = st.blocks || { rows: [] };
    var scope = b.scope === 'site' ? 'cannot open any page of the shop' : 'can still read the shop, but cannot add to cart, check out, place any order (cash on delivery included) or send any form';
    var html = '';
    if (b.health) html += '<div class="ctk-warn">' + icon(I.warn, 18) + '<div>' + esc(b.health) + '</div></div>';
    html += '<div class="ctk-card"><div class="ctk-form">'
      + '<label>IP address or range<input class="ctk-in" id="ctkNewIp" placeholder="203.0.113.7 or 203.0.113.0/24" maxlength="49" autocomplete="off"></label>'
      + '<label>Reason<input class="ctk-in" id="ctkNewReason" placeholder="e.g. Fake COD orders" maxlength="190"></label>'
      + '<label>For<select class="ctk-sel" id="ctkNewDays"><option value="0">Until unblocked</option><option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">1 year</option></select></label>'
      + '<button type="button" class="ctk-btn bad" data-ctk-add>' + icon(I.ban, 14) + ' Block</button>'
      + '</div><div class="ctk-msg" id="ctkAddMsg"></div>'
      + '<p class="ctk-note" style="margin-top:10px">A blocked address <b>' + scope + '</b>. The admin is never blocked. '
      + 'Your own address' + (b.you ? ' (<b>' + esc(b.you) + '</b>)' : '') + ', private networks and Cloudflare cannot be blocked, and nothing wider than /16 (IPv6 /32).</p></div>';

    html += '<div class="ctk-card">';
    if (b.err) {
      html += '<div class="ctk-empty"><b>Something went wrong</b>' + esc(b.err) + '</div>';
    } else if (!b.rows.length) {
      html += '<div class="ctk-empty"><b>Nothing is blocked</b>Block an address from the shield on any cart, or add one above.</div>';
    } else {
      html += '<div class="ctk-bt h"><span>Address / range</span><span>Reason</span><span>Blocked by</span><span>Refused since</span><span></span></div>'
        + b.rows.map(function (r) {
            return '<div class="ctk-bt' + (r.expired ? ' exp' : '') + '">'
              + '<div><span class="cidr">' + esc(r.cidr) + '</span><span class="ctk-sub">' + (r.single ? 'Single address' : 'Whole range') + (r.cart_id ? ' · from cart #' + r.cart_id : '') + '</span></div>'
              + '<div>' + (r.reason ? esc(r.reason) : '<span class="ctk-more">No reason given</span>') + (r.expires_at ? '<span class="ctk-sub">' + (r.expired ? 'Expired ' : 'Until ') + esc(when(r.expires_at)) + '</span>' : '') + '</div>'
              + '<div>' + esc(r.by || '—') + '<span class="ctk-sub">' + esc(when(r.at)) + '</span></div>'
              + '<div><b style="font-variant-numeric:tabular-nums">' + num(r.hits) + '</b> <span class="ctk-more">request' + (r.hits === 1 ? '' : 's') + '</span>' + (r.last_hit_at ? '<span class="ctk-sub">last ' + esc(when(r.last_hit_at)) + '</span>' : '') + '</div>'
              + '<div style="text-align:right"><button type="button" class="ctk-btn" data-ctk-unblock="' + r.id + '">Unblock</button></div>'
              + '</div>';
          }).join('');
    }
    html += '</div>';
    p.innerHTML = html;
  }

  async function addBlock(btn) {
    var msg = document.getElementById('ctkAddMsg');
    var target = (document.getElementById('ctkNewIp') || {}).value || '';
    if (!target.trim()) { msg.className = 'ctk-msg on bad'; msg.textContent = 'Type an address or a range first.'; return; }
    btn.disabled = true;
    try {
      var r = await api('/blocks', { method: 'POST', body: {
        target: target.trim(),
        reason: (document.getElementById('ctkNewReason') || {}).value || '',
        days: Number((document.getElementById('ctkNewDays') || {}).value || 0)
      } });
      toast(r.message);
      await loadBlocks();
      var m2 = document.getElementById('ctkAddMsg');
      if (m2) { m2.className = 'ctk-msg on ok'; m2.textContent = r.message; }
    } catch (e) {
      msg.className = 'ctk-msg on bad';
      msg.textContent = failText(e);
      btn.disabled = false;
    }
  }

  /* ───────────────────────────────────────────── settings ── */

  async function loadSettings() {
    var p = panel('settings');
    if (p && !st.settings) p.innerHTML = '<div class="ctk-card"><div class="ctk-empty">Loading…</div></div>';
    try { st.settings = await api('/settings'); } catch (e) { st.settings = { err: failText(e) }; }
    paintSettings();
  }

  function fieldHtml(f, v) {
    var id = 'ctkS_' + f.key;
    if (f.type === 'bool') {
      return '<div class="ctk-field"><div class="lb"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="ctk-sw"><input type="checkbox" id="' + id + '" data-ctk-set="' + f.key + '"' + (v ? ' checked' : '') + '><span></span></span></div>'
        + '<div class="hp">' + esc(f.help) + '</div></div>';
    }
    if (f.type === 'select') {
      return '<div class="ctk-field"><div class="lb"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select class="ctk-sel" id="' + id + '" data-ctk-set="' + f.key + '">' + Object.keys(f.options).map(function (k) {
            return '<option value="' + esc(k) + '"' + (k === v ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
          }).join('') + '</select><div class="hp">' + esc(f.help) + '</div></div>';
    }
    return '<div class="ctk-field"><div class="lb"><label for="' + id + '">' + esc(f.label) + '</label><output id="' + id + '_o">' + esc(v) + esc(f.unit || '') + '</output></div>'
      + '<input type="range" id="' + id + '" data-ctk-set="' + f.key + '" data-unit="' + esc(f.unit || '') + '" min="' + f.min + '" max="' + f.max + '" step="' + f.step + '" value="' + esc(v) + '">'
      + '<div class="hp">' + esc(f.help) + '</div></div>';
  }

  function paintSettings() {
    var p = panel('settings');
    if (!p) return;
    var s = st.settings;
    if (!s) return;
    if (s.err) { p.innerHTML = '<div class="ctk-card"><div class="ctk-empty"><b>Something went wrong</b>' + esc(s.err) + '</div></div>'; return; }
    var groups = [
      ['Tracking & bots', ['track', 'bots_leave', 'block_scope', 'keep_days']],
      ['When a cart is called a bot', ['bot_threshold', 'speed_ms', 'burst_minutes', 'burst_ip', 'burst_net']]
    ];
    var byKey = {};
    s.fields.forEach(function (f) { byKey[f.key] = f; });
    var html = groups.map(function (g) {
      return '<div class="ctk-card"><h4 style="margin:0 0 12px;font-size:14px">' + esc(g[0]) + '</h4><div class="ctk-set">'
        + g[1].filter(function (k) { return byKey[k]; }).map(function (k) { return fieldHtml(byKey[k], s.values[k]); }).join('')
        + '</div></div>';
    }).join('');
    html += '<div class="ctk-card"><div class="ctk-row" style="justify-content:space-between;gap:12px">'
      + '<p class="ctk-note" style="flex:1 1 300px">Signals and their points: no user agent 60 · scripted client 70 · headless browser 60 · crawler 80 · cart script never ran 30 · too fast 35 · datacenter/VPN 25 · many carts from one address 40 · from one range 25 · outdated browser 20. '
      + (s.hosting && s.hosting.v4 ? 'Datacenter list: ' + num(s.hosting.v4) + ' IPv4 and ' + num(s.hosting.v6) + ' IPv6 ranges (' + esc(s.hosting.source) + ', ' + esc(s.hosting.fetched) + '), checked locally — nothing is sent anywhere.' : '')
      + '</p><button type="button" class="ctk-btn pri" data-ctk-save>Save settings</button></div><div class="ctk-msg" id="ctkSetMsg"></div></div>';
    p.innerHTML = html;
  }

  async function saveSettings(btn) {
    var values = {};
    document.querySelectorAll('[data-ctk-set]').forEach(function (el) {
      values[el.getAttribute('data-ctk-set')] = el.type === 'checkbox' ? el.checked : el.value;
    });
    btn.disabled = true;
    var msg = document.getElementById('ctkSetMsg');
    try {
      var r = await api('/settings', { method: 'POST', body: { values: values } });
      st.settings.values = r.values;
      st.data = null;
      paintSettings();
      var m2 = document.getElementById('ctkSetMsg');
      if (m2) { m2.className = 'ctk-msg on ok'; m2.textContent = r.message; }
    } catch (e) {
      if (msg) { msg.className = 'ctk-msg on bad'; msg.textContent = failText(e); }
      btn.disabled = false;
    }
  }

  /* ───────────────────────────────────────────── events ── */

  function inScreen(e) { return !!(e.target && e.target.closest && (e.target.closest('[data-ctk]') || e.target.closest('#ctkPanel') || e.target.closest('#ctkPop') || e.target.closest('#ctkScrim'))); }

  document.addEventListener('click', function (e) {
    if (!inScreen(e)) {
      if (document.getElementById('ctkPop') && document.getElementById('ctkPop').classList.contains('on') && !(e.target.closest && e.target.closest('[data-ctk-shield]'))) closePop();
      return;
    }
    var t = e.target.closest('[data-ctk-period],[data-ctk-pp],[data-ctk-sort],[data-ctk-page],[data-ctk-country],[data-ctk-selall],[data-ctk-sel],[data-ctk-allmatch],[data-ctk-bulk],[data-ctk-shield],[data-ctk-unblock],[data-ctk-doblock],[data-ctk-popclose],[data-ctk-close],[data-ctk-add],[data-ctk-save],[data-ctk-csv],[data-ctk-open-cart],#ctkScrim,[data-ctk-open]');
    if (!t) return;
    if (t.disabled) return;

    if (t.hasAttribute('data-ctk-period')) { st.q.period = t.getAttribute('data-ctk-period'); st.q.page = 1; st.sel = {}; st.allMatching = false; if (st.q.period !== 'custom' || (st.q.from && st.q.to)) loadCarts(); else paintCarts(); return; }
    if (t.hasAttribute('data-ctk-pp')) { var kind = t.getAttribute('data-ctk-pperiod'); st.prod[kind].period = t.getAttribute('data-ctk-pp'); loadProducts(kind); return; }
    if (t.hasAttribute('data-ctk-sort')) { var k = t.getAttribute('data-ctk-sort'); st.q.dir = st.q.sort === k && st.q.dir === 'desc' ? 'asc' : 'desc'; st.q.sort = k; st.q.page = 1; loadCarts(); return; }
    if (t.hasAttribute('data-ctk-page')) { st.q.page = Math.max(1, parseInt(t.getAttribute('data-ctk-page'), 10) || 1); loadCarts(); return; }
    if (t.hasAttribute('data-ctk-country')) { st.q.country = t.getAttribute('data-ctk-country'); st.q.page = 1; loadCarts(); return; }
    if (t.hasAttribute('data-ctk-selall')) { var on = t.checked; (st.data ? st.data.rows : []).forEach(function (r) { st.sel[r.id] = on; }); st.allMatching = false; paintCarts(); return; }
    if (t.hasAttribute('data-ctk-sel')) { st.sel[Number(t.getAttribute('data-ctk-sel'))] = t.checked; st.allMatching = false; paintCarts(); return; }
    if (t.hasAttribute('data-ctk-allmatch')) { st.allMatching = true; paintCarts(); return; }
    if (t.hasAttribute('data-ctk-bulk')) { bulk(t.getAttribute('data-ctk-bulk')); return; }
    if (t.hasAttribute('data-ctk-shield')) { e.preventDefault(); openPop(e, rowById(Number(t.getAttribute('data-ctk-shield')))); return; }
    if (t.hasAttribute('data-ctk-unblock')) { doUnblock(Number(t.getAttribute('data-ctk-unblock')), t); return; }
    if (t.hasAttribute('data-ctk-doblock')) { doBlock(t); return; }
    if (t.hasAttribute('data-ctk-popclose')) { closePop(); return; }
    if (t.hasAttribute('data-ctk-close') || t.id === 'ctkScrim') { closeCart(); return; }
    if (t.hasAttribute('data-ctk-add')) { addBlock(t); return; }
    if (t.hasAttribute('data-ctk-save')) { saveSettings(t); return; }
    if (t.hasAttribute('data-ctk-csv')) { exportCsv(qs(Object.assign({}, st.q, { page: '' }))); return; }
    if (t.hasAttribute('data-ctk-open-cart')) { openCart(Number(t.getAttribute('data-ctk-open-cart'))); return; }
    if (t.hasAttribute('data-ctk-open')) {
      if (e.target.closest('a,button,input,[data-stop],[data-ctk-stop],.ctk-bot')) return;
      openCart(Number(t.getAttribute('data-ctk-open')));
    }
  });

  document.addEventListener('input', function (e) {
    if (!e.target) return;
    // Clearing the box (its x, or deleting the last letter) brings the full
    // list back at once; every other search waits for Enter — one request per
    // search, never one per keystroke.
    if (e.target.id === 'ctkFind' && e.target.value === '') find('');
    if (e.target.matches && e.target.matches('input[type=range][data-ctk-set]')) {
      var o = document.getElementById(e.target.id + '_o');
      if (o) o.textContent = e.target.value + (e.target.getAttribute('data-unit') || '');
    }
  });

  function find(v) {
    v = v.trim();
    if (v === st.q.q) return;
    st.q.q = v; st.q.page = 1; st.sel = {}; st.allMatching = false; loadCarts();
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'ctkFind') { find(e.target.value); return; }
    if (!e.target || !e.target.matches || !e.target.matches('[data-ctk-f]')) return;
    var f = e.target.getAttribute('data-ctk-f');
    st.q[f] = e.target.value;
    st.q.page = 1; st.sel = {}; st.allMatching = false;
    if ((f === 'from' || f === 'to') && !(st.q.from && st.q.to)) return;
    loadCarts();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var pop = document.getElementById('ctkPop');
      if (pop && pop.classList.contains('on')) { closePop(); return; }
      if (st.detail) closeCart();
    }
    if (e.key === 'Enter' && e.target && e.target.id === 'ctkNewIp') { var b = document.querySelector('[data-ctk-add]'); if (b) addBlock(b); }
  });

  document.addEventListener('kbb:tab', function (e) {
    if (!e.detail || e.detail.group !== 'carttracking') return;
    show(e.detail.id);
  });

  /* ───────────────────────────────────────────── the console ── */

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart Tracking',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M6 6 5 3H2"/><path d="m11 10 2 2 3-3"/>',
      // A top-level row under Analytics since Lane QK10 (the owner asked for
      // it "at the top"); AdminNav draws it there, this only finds it.
      after: ['dash']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) {
      closeCart();
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    // A top-level row since Lane QK10, like Analytics: no group to open.
    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Overview';
    if (title) title.textContent = 'Cart Tracking';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    st.data = null; st.blocks = null; st.settings = null;
    st.prod.added.data = null; st.prod.removed.data = null;
    shell();
    show(st.tab);
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
