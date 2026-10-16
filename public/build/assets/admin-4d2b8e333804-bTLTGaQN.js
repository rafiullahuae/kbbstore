
(function () {
  'use strict';

  var SCREEN = 'concernsync';
  var LABEL = 'Shop by Concern';
  var GROUP = 'Catalog';
  var st = null;          // GET answer
  var res = null;         // last Sync answer
  var picks = {};         // product id -> {slug: true}
  var busy = '', msg = null, away = false, rulesOnly = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function host() { return document.getElementById('content'); }
  function ours() {
    if (away) return false;
    var h = host();
    return !!h && !!h.querySelector('[data-csy]');
  }

  async function api(method, path, body) {
    var r = await fetch(base() + '/admin-api/concern-sync' + path, {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    if (!r.ok) {
      var err = new Error((j && j.message) || ('HTTP ' + r.status));
      err.status = r.status; throw err;
    }
    return j;
  }

  function why(e) {
    if (e && e.status === 403) return 'Your role cannot use this screen. An owner or manager can.';
    if (e && e.status === 404) return 'This screen is not in the server’s route table yet. Clear the route cache and reload.';
    if (e && e.status === 429) return 'Too many presses in a minute. Wait a moment and try again.';
    return (e && e.message) || 'Something went wrong. Try again.';
  }

  async function load() {
    try {
      var j = await api('GET', '');
      if (!ours()) return;
      st = j;
    } catch (e) {
      if (!ours()) return;
      msg = { t: 'err', s: why(e) };
    }
    paint();
  }

  async function sync(before) {
    busy = 'sync'; msg = null; paint();
    try {
      var j = await api('POST', '/scan', { engine: rulesOnly ? 'rules' : 'auto', before: before || null });
      if (!ours()) return;
      if (before && res) { j.products = res.products.concat(j.products); }
      res = j;
      st.pending = j.pending;
      j.products.forEach(function (p) {
        if (picks[p.id]) return;
        picks[p.id] = {};
        p.suggestions.forEach(function (s) { picks[p.id][s.slug] = true; });
      });
      if (j.note) msg = { t: 'info', s: j.note };
    } catch (e) {
      if (!ours()) return;
      msg = { t: 'err', s: why(e) };
    }
    busy = ''; paint();
  }

  function ticked() {
    var links = [];
    (res ? res.products : []).forEach(function (p) {
      var on = Object.keys(picks[p.id] || {}).filter(function (k) { return picks[p.id][k]; });
      if (on.length) links.push({ product_id: p.id, concerns: on });
    });
    return links;
  }

  async function apply() {
    var links = ticked();
    if (!links.length) return;
    busy = 'apply'; msg = null; paint();
    try {
      var j = await api('POST', '/apply', { links: links });
      if (!ours()) return;
      var done = {};
      links.forEach(function (l) { done[l.product_id] = true; });
      res.products = res.products.filter(function (p) { return !done[p.id]; });
      st.pending = j.pending;
      msg = { t: 'ok', s: 'Added ' + num(j.added) + ' membership' + (j.added === 1 ? '' : 's') + ' to ' + num(links.length) + ' product' + (links.length === 1 ? '' : 's') + '. Nothing was removed.' };
    } catch (e) {
      if (!ours()) return;
      msg = { t: 'err', s: why(e) };
    }
    busy = ''; paint();
  }

  async function save(clear) {
    var k = document.getElementById('csyKey'), m = document.getElementById('csyModel');
    busy = 'save'; msg = null;
    var body = { model: m ? m.value : '', api_key: clear ? '' : (k ? k.value.trim() : ''), clear_key: !!clear };
    paint();
    try {
      var j = await api('POST', '/settings', body);
      if (!ours()) return;
      st.has_key = j.has_key; st.model = j.model;
      msg = { t: 'ok', s: clear ? 'Claude API key removed. Sync now uses the built-in rules.' : 'Saved.' };
    } catch (e) {
      if (!ours()) return;
      msg = { t: 'err', s: why(e) };
    }
    busy = ''; paint();
  }

  function product(p, concerns) {
    var on = picks[p.id] || {};
    var sug = {};
    p.suggestions.forEach(function (s) { sug[s.slug] = s.reason; });
    var img = p.image
      ? '<img src="' + esc(p.image) + '"' + (p.srcset ? ' srcset="' + esc(p.srcset) + '" sizes="56px"' : '') + ' alt="" width="56" height="56" loading="lazy" decoding="async">'
      : '<span class="ph" aria-hidden="true"></span>';
    var chips = concerns.map(function (c) {
      return '<label class="csy-chip' + (sug[c.slug] !== undefined ? ' sug' : '') + '"><input type="checkbox" data-csy-pick="' + p.id + '" value="' + esc(c.slug) + '"' + (on[c.slug] ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
    }).join('');
    var names = {};
    concerns.forEach(function (c) { names[c.slug] = c.name; });
    var reasons = p.suggestions.length
      ? '<ul class="csy-why">' + p.suggestions.map(function (s) { return '<li><b>' + esc(names[s.slug] || s.slug) + ':</b> ' + esc(s.reason) + '</li>'; }).join('') + '</ul>'
      : '<div class="csy-none">No suggestion — tick a concern yourself, or leave it out.</div>';
    return '<div class="csy-p">' + img + '<div><h4>' + esc(p.name)
      + '<span class="csy-src ' + (p.source === 'claude' ? 'claude">Claude' : 'rules">rule-based') + '</span></h4>'
      + '<div class="csy-meta">' + (p.brand ? esc(p.brand) + ' · ' : '') + (p.categories.length ? esc(p.categories.join(', ')) : 'No category') + '</div>'
      + '<div class="csy-chips">' + chips + '</div>' + reasons + '</div></div>';
  }

  function paint() {
    if (!ours()) return;
    var root = host().querySelector('[data-csy]');
    var html = '';

    if (!st) {
      html = msg ? '<div class="csy-msg err">' + esc(msg.s) + '</div>' : '<div class="csy-card csy-empty">Loading…</div>';
      root.innerHTML = html; return;
    }

    html += '<div class="csy-card"><div class="csy-top"><p><b>' + num(st.pending) + '</b> published product' + (st.pending === 1 ? ' is' : 's are')
      + ' in none of the ' + num(st.concerns.length) + ' skin-concern collections.<br><span class="csy-meta">Suggestions come from '
      + (st.has_key ? 'the Claude API (' + esc(st.models[st.model] || st.model) + ')' : 'the built-in rules — add a Claude API key below for better ones')
      + '. Apply only adds; it never removes a product from a collection.</span></p>'
      + '<div class="csy-row">' + (st.has_key ? '<label class="csy-opt"><input type="checkbox" id="csyRules"' + (rulesOnly ? ' checked' : '') + '> Rules only</label>' : '')
      + '<button type="button" class="csy-btn" data-csy-sync' + (busy || !st.concerns.length ? ' disabled' : '') + '>' + (busy === 'sync' ? 'Syncing…' : 'Sync') + '</button></div></div></div>';

    if (msg) html += '<div class="csy-msg ' + (msg.t === 'err' ? 'err' : (msg.t === 'info' ? 'info' : '')) + '" role="status">' + esc(msg.s) + '</div>';

    if (res) {
      if (!res.products.length) {
        html += '<div class="csy-card csy-empty">Every product on this page is in a concern collection now.</div>';
      } else {
        html += '<div class="csy-list">' + res.products.map(function (p) { return product(p, res.concerns); }).join('') + '</div>';
      }
      if (res.next_before) html += '<div><button type="button" class="csy-btn ghost" data-csy-more' + (busy ? ' disabled' : '') + '>Show older products</button></div>';
      var n = ticked();
      var count = n.reduce(function (a, l) { return a + l.concerns.length; }, 0);
      html += '<div class="csy-foot"><span><b>' + num(count) + '</b> membership' + (count === 1 ? '' : 's') + ' ticked across <b>' + num(n.length) + '</b> product' + (n.length === 1 ? '' : 's') + '</span>'
        + '<button type="button" class="csy-btn" data-csy-apply' + (busy || !count ? ' disabled' : '') + '>' + (busy === 'apply' ? 'Applying…' : 'Apply') + '</button></div>';
    }

    var models = Object.keys(st.models || {}).map(function (k) {
      return '<option value="' + esc(k) + '"' + (k === st.model ? ' selected' : '') + '>' + esc(st.models[k]) + '</option>';
    }).join('');
    html += '<details class="csy-card csy-set"><summary>Claude API key &amp; model</summary><div class="csy-f">'
      + '<label>Claude API key<input type="password" id="csyKey" autocomplete="new-password" spellcheck="false" placeholder="' + (st.has_key ? 'Saved — type a new one to replace it' : 'sk-ant-…') + '"></label>'
      + '<label>Model<select id="csyModel">' + models + '</select></label></div>'
      + '<div class="csy-row" style="margin-top:12px"><button type="button" class="csy-btn" data-csy-save' + (busy ? ' disabled' : '') + '>Save</button>'
      + (st.has_key ? '<button type="button" class="csy-btn ghost" data-csy-clear' + (busy ? ' disabled' : '') + '>Remove key</button>' : '') + '</div>'
      + '<p class="csy-note">The key is stored encrypted and never shown again. A Sync sends each product’s name, brand and a short plain-text extract of its description and ingredients to the Claude API — nothing about customers, orders or prices.</p></details>';

    var open = root.querySelector('details.csy-set') && root.querySelector('details.csy-set').open;
    root.innerHTML = html;
    if (open) root.querySelector('details.csy-set').open = true;
  }

  document.addEventListener('click', function (e) {
    if (!ours()) return;
    var b = e.target.closest && e.target.closest('[data-csy-sync],[data-csy-more],[data-csy-apply],[data-csy-save],[data-csy-clear]');
    if (!b || b.disabled) return;
    if (b.hasAttribute('data-csy-sync')) { res = null; picks = {}; sync(null); }
    else if (b.hasAttribute('data-csy-more')) sync(res && res.next_before);
    else if (b.hasAttribute('data-csy-apply')) apply();
    else if (b.hasAttribute('data-csy-save')) save(false);
    else if (b.hasAttribute('data-csy-clear')) save(true);
  });

  document.addEventListener('change', function (e) {
    if (!ours() || !e.target) return;
    if (e.target.id === 'csyRules') { rulesOnly = !!e.target.checked; return; }
    var id = e.target.getAttribute && e.target.getAttribute('data-csy-pick');
    if (!id) return;
    picks[id] = picks[id] || {};
    picks[id][e.target.value] = !!e.target.checked;
    var n = ticked(), count = n.reduce(function (a, l) { return a + l.concerns.length; }, 0);
    var foot = host().querySelector('.csy-foot');
    if (foot) {
      foot.querySelector('span').innerHTML = '<b>' + num(count) + '</b> membership' + (count === 1 ? '' : 's') + ' ticked across <b>' + num(n.length) + '</b> product' + (n.length === 1 ? '' : 's');
      foot.querySelector('button').disabled = !!busy || !count;
    }
  });

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: LABEL,
      icon: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
      group: GROUP,
      after: ['imageseo', 'product-editor']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { away = true; return previousGo.apply(this, arguments); }
    away = false;
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = LABEL;
    if (side) side.classList.remove('open');
    var h = host();
    if (!h) return undefined;
    h.innerHTML = '<div class="wrap"><div class="page-head"><h2>' + LABEL + '</h2>'
      + '<p>Find products that are in no skin-concern collection, see where each belongs and why, tick, and Apply.</p></div>'
      + '<div class="csy" data-csy></div></div>';
    st = null; res = null; picks = {}; msg = null; busy = '';
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
