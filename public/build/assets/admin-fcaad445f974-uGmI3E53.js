
(function () {
  'use strict';

  var SCREEN = 'freegifts';
  var GROUP = 'Growth & Marketing';
  var TITLE = 'Free gifts';
  var TABS = [['overview', 'Overview'], ['ranges', 'Ranges & gifts'], ['products', 'Gift products'], ['display', 'Display'], ['home', 'Homepage section']];
  var TEXTS = [['title', 'Box title'], ['free', '"FREE" on each gift'], ['view_all', 'Open button'], ['hide', 'Close button'], ['count_one', 'Count: 1 gift'], ['count_two', 'Count: 2 gifts (keep :count in English)'], ['count_few', 'Count: 3 to 10 gifts (keep :count)'], ['count_many', 'Count: 11 gifts and more (keep :count)'], ['line', 'Checkout summary line (keep :count)'], ['tag', 'Tag on emails, invoice and packing slip']];

  var st = { tab: 'overview', data: null, banner: null, busy: false, seq: 0, edit: {}, search: {}, errors: {} };

  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
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
  function ours() { var c = document.querySelector('#content'); return !!c && (!c.firstChild || !!c.querySelector('[data-screen="' + SCREEN + '"]')); }

  async function api(method, path, body) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/free-gifts' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('free-gifts ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && e.body.error)) return 'The Free gifts endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot do this. An owner or a manager can.';
    if (e && e.body && e.body.errors) { var k = Object.keys(e.body.errors)[0]; return e.body.errors[k][0]; }
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
    var c = document.querySelector('#content');
    if (c) c.textContent = '';
    render();
    run(function () { return api('GET', ''); }, 'Free gifts could not be loaded.');
    return undefined;
  };

  async function run(fn, fallback, done) {
    var mine = ++st.seq;
    st.busy = true; st.banner = null; render();
    try {
      var d = await fn();
      if (mine !== st.seq) return;
      if (d && d.config) { st.data = d; st.edit = {}; }
      if (done) done(d);
    } catch (e) { if (mine === st.seq) st.banner = explain(e, fallback); }
    if (mine === st.seq) { st.busy = false; render(); }
  }

  function card(title, sub, kids) {
    return el('section', { class: 'fg-card' }, [title ? el('h3', { class: 'fg-h', text: title }) : null, sub ? el('p', { class: 'fg-sub', text: sub }) : null].concat(kids || []));
  }
  function sw(on, label, onclick, disabled) {
    return el('button', { type: 'button', class: 'fg-sw', role: 'switch', 'aria-checked': String(!!on), onclick: onclick, disabled: disabled }, [el('i'), el('span', { text: label })]);
  }
  function field(label, input, help) { return el('label', { class: 'fg-field' }, [el('span', { text: label }), input, help ? el('small', { class: 'fg-sub', text: help }) : null]); }
  function thumb(p) { return p && p.thumb ? el('img', { class: 'fg-th', src: p.thumb, alt: '', width: 38, height: 38, loading: 'lazy' }) : el('span', { class: 'fg-th ph', 'aria-hidden': 'true' }); }
  function stockPill(p) {
    if (!p) return el('span', { class: 'fg-pill block', text: 'Missing' });
    if (p.status !== 'publish') return el('span', { class: 'fg-pill block', text: 'Not published' });
    if (!p.in_stock) return el('span', { class: 'fg-pill warn', text: 'Out of stock' });
    return el('span', { class: 'fg-pill ok', text: p.stock === null ? 'In stock' : p.stock + ' in stock' });
  }
  function can() { return !!(st.data && st.data.can_manage); }

  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    c.textContent = '';
    var wrap = el('div', { class: 'fg-wrap', 'data-screen': SCREEN });
    c.appendChild(wrap);
    wrap.appendChild(el('div', { class: 'fg-tabs', role: 'tablist' }, TABS.map(function (t) {
      return el('button', { type: 'button', class: 'fg-tab', role: 'tab', 'aria-selected': String(st.tab === t[0]), text: t[1], onclick: function () { st.tab = t[0]; render(); } });
    })));
    if (st.banner) wrap.appendChild(el('div', { class: 'fg-note', role: 'alert', text: st.banner }));
    if (!st.data) { wrap.appendChild(el('div', { class: 'fg-card', text: st.busy ? 'Loading…' : 'Nothing to show.' })); return; }
    ({ overview: renderOverview, ranges: renderRanges, products: renderProducts, display: renderDisplay, home: renderHome })[st.tab](wrap);
  }

  /* ---------------------------------------------------------- overview */
  function renderOverview(wrap) {
    var d = st.data, on = d.config.on, blocked = d.checklist.some(function (i) { return i.level === 'block'; });
    wrap.appendChild(card('Free gifts', 'Spend a set amount and the order gets free gifts: the cart panel, the cart page and the checkout summary show them, and the order stores them at AED 0. One range at a time, never added together. The amount is the products\' subtotal ' + (d.config.basis === 'after_discounts' ? 'after the coupon' : 'before the coupon') + ', without delivery, gift wrapping or the gifts.', [
      el('div', { class: 'fg-row', style: 'margin-top:12px' }, [
        sw(on, on ? 'On: shoppers see their gifts' : 'Off: the shop shows nothing', function () {
          run(function () { return api('POST', '/switch', { on: !on }); }, on ? 'Not switched off.' : 'Not switched on.', function () { say(!on ? 'Free gifts are on.' : 'Free gifts are off.'); });
        }, !can() || st.busy || (!on && blocked))
      ]),
      (!on && blocked) ? el('p', { class: 'fg-sub', text: 'The switch opens once every "Needed" item below is done.' }) : null
    ]));
    var items = d.checklist.length ? d.checklist : [{ level: 'ok', text: 'Everything is set: every range has its gifts and they are in stock.' }];
    wrap.appendChild(card('Before switching on', null, [el('ul', { class: 'fg-list' }, items.map(function (i) {
      return el('li', {}, [el('span', { class: 'fg-pill ' + i.level, text: i.level === 'block' ? 'Needed' : i.level === 'warn' ? 'Check' : 'Ready' }), el('span', { text: i.text })]);
    }))]));
    wrap.appendChild(card('Ranges', null, [el('div', { class: 'fg-scroll' }, [el('table', { class: 'fg-table' }, [
      el('thead', {}, [el('tr', {}, ['Spend', 'Gifts', 'Picked', 'Status'].map(function (h) { return el('th', { text: h }); }))]),
      el('tbody', {}, d.ranges.map(function (r) {
        var units = r.gifts.reduce(function (a, g) { return a + g.quantity; }, 0);
        return el('tr', {}, [el('td', { text: r.label }), el('td', { text: r.label_en }), el('td', { text: r.gifts.length ? r.gifts.length + (r.gifts.length === 1 ? ' product, ' : ' products, ') + units + (units === 1 ? ' unit' : ' units') : 'None yet' }), el('td', {}, [el('span', { class: 'fg-pill ' + (r.enabled ? 'ok' : ''), text: r.enabled ? 'On' : 'Off' })])]);
      }))
    ])])]));
  }

  /* ------------------------------------------------------------ ranges */
  function draftOf(r) {
    if (!st.edit[r.id]) st.edit[r.id] = JSON.parse(JSON.stringify({ min: r.min, max: r.max, label_en: r.label_en, label_ar: r.label_ar, enabled: r.enabled, starts_on: r.starts_on, ends_on: r.ends_on, gifts: r.gifts }));
    return st.edit[r.id];
  }
  function renderRanges(wrap) {
    var d = st.data;
    wrap.appendChild(card('Ranges & gifts', 'Amounts are whole dirhams. A range "100 – 299" covers AED 100.00 to AED 299.99; leave the maximum empty for "and above". Ranges may not overlap. A gift is 1 to 50 of a product; a sold-out gift is skipped on the shop until it is back.', [
      can() ? el('div', { class: 'fg-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'fg-btn is-primary', text: 'Add range', onclick: function () {
        var top = d.ranges.reduce(function (a, r) { return Math.max(a, r.max == null ? r.min : r.max + 1); }, 0);
        st.edit['new'] = { min: top, max: null, label_en: '', label_ar: '', enabled: false, starts_on: null, ends_on: null, gifts: [] };
        render();
      } })]) : null
    ]));
    var list = d.ranges.slice();
    if (st.edit['new']) list.push({ id: 'new', label: 'New range' });
    list.forEach(function (r, ix) { wrap.appendChild(rangeCard(r, ix, list.length)); });
  }
  function rangeCard(r, ix, count) {
    var x = r.id === 'new' ? st.edit['new'] : draftOf(r), key = String(r.id), ro = !can();
    function inp(prop, type, extra) {
      var i = el('input', Object.assign({ class: 'fg-in', type: type || 'text', value: x[prop] == null ? '' : x[prop], disabled: ro }, extra || {}));
      i.addEventListener('change', function () { x[prop] = type === 'number' ? (i.value === '' ? null : Number(i.value)) : (i.value === '' ? null : i.value); });
      return i;
    }
    var gifts = el('div', {}, x.gifts.map(function (g, gi) {
      var q = el('input', { class: 'fg-in', type: 'number', min: 1, max: 50, value: g.quantity, disabled: ro, 'aria-label': 'Quantity' });
      q.addEventListener('change', function () { g.quantity = Math.max(1, Math.min(50, Number(q.value) || 1)); q.value = g.quantity; });
      return el('div', { class: 'fg-gift' }, [thumb(g.product), el('div', {}, [el('b', { text: g.product ? g.product.name : 'Product #' + g.product_id }), el('small', { text: (g.product && g.product.brand ? g.product.brand + ' · ' : '') }), stockPill(g.product)]), q,
        ro ? null : el('button', { type: 'button', class: 'fg-btn sm is-danger', text: 'Remove', onclick: function () { x.gifts.splice(gi, 1); render(); } })]);
    }));
    var s = st.search[key] || (st.search[key] = { q: '', rows: null });
    var find = el('input', { class: 'fg-in', type: 'search', value: s.q, placeholder: 'Search the catalogue, gift-only products included', disabled: ro, style: 'flex:1 1 240px;width:auto' });
    function search() {
      s.q = find.value.trim();
      api('GET', '/products?q=' + encodeURIComponent(s.q)).then(function (d) { s.rows = d.products; if (ours() && st.tab === 'ranges') render(); }).catch(function (e) { say(explain(e, 'Search failed.')); });
    }
    find.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
    var results = s.rows ? el('div', { class: 'fg-results' }, s.rows.length ? s.rows.map(function (p) {
      var taken = x.gifts.some(function (g) { return g.product_id === p.id; }), bad = p.type === 'variable';
      return el('button', { type: 'button', disabled: taken || bad, title: bad ? 'Has shades or sizes: pick a simple product' : null, onclick: function () { x.gifts.push({ product_id: p.id, quantity: 1, product: p }); render(); } }, [
        thumb(p), el('span', {}, [el('b', { text: p.name }), el('br'), el('small', { text: (p.brand ? p.brand + ' · ' : '') + (p.gift_only ? 'Gift only · ' : 'AED ' + p.price + ' · ') + (p.in_stock ? (p.stock === null ? 'in stock' : p.stock + ' in stock') : 'out of stock') })]),
        el('span', { class: 'fg-pill', text: taken ? 'Added' : bad ? 'Options' : 'Add' })]);
    }) : [el('p', { class: 'fg-sub', text: 'No product matches.' })]) : null;
    var err = st.errors[key];
    return el('section', { class: 'fg-range' + (x.enabled ? '' : ' off') }, [
      el('div', { class: 'fg-range-h' }, [el('b', { text: r.id === 'new' ? 'New range' : r.label }),
        el('div', { class: 'fg-row' }, [
          r.id !== 'new' && !ro ? el('button', { type: 'button', class: 'fg-btn sm', text: '↑', 'aria-label': 'Move up', disabled: ix === 0, onclick: function () { move(ix, -1); } }) : null,
          r.id !== 'new' && !ro ? el('button', { type: 'button', class: 'fg-btn sm', text: '↓', 'aria-label': 'Move down', disabled: ix >= count - 1 || (ix === count - 2 && st.edit['new']), onclick: function () { move(ix, 1); } }) : null,
          sw(x.enabled, x.enabled ? 'On' : 'Off', function () { x.enabled = !x.enabled; render(); }, ro)])]),
      el('div', { class: 'fg-grid' }, [field('From (AED)', inp('min', 'number', { min: 0, step: 1 })), field('To (AED)', inp('max', 'number', { min: 0, step: 1, placeholder: 'and above' })), field('Starts (optional)', inp('starts_on', 'date')), field('Ends (optional)', inp('ends_on', 'date'))]),
      el('div', { class: 'fg-grid' }, [field('Wording (English)', inp('label_en')), field('Wording (Arabic)', inp('label_ar', 'text', { dir: 'rtl' }))]),
      el('div', {}, [el('span', { class: 'fg-sub', text: 'Gifts' }), x.gifts.length ? gifts : el('p', { class: 'fg-sub', text: 'No gifts yet. Search below and add them.' })]),
      ro ? null : el('div', { class: 'fg-row' }, [find, el('button', { type: 'button', class: 'fg-btn', text: 'Search', onclick: search })]),
      results,
      err ? el('p', { class: 'fg-err', role: 'alert', text: err }) : null,
      ro ? null : el('div', { class: 'fg-row' }, [
        el('button', { type: 'button', class: 'fg-btn is-primary', text: 'Save range', disabled: st.busy, onclick: function () { save(r.id, x); } }),
        r.id === 'new' ? el('button', { type: 'button', class: 'fg-btn', text: 'Cancel', onclick: function () { delete st.edit['new']; render(); } })
          : el('button', { type: 'button', class: 'fg-btn is-danger', text: 'Delete range', onclick: function () { if (window.confirm('Delete ' + r.label + ' and its gifts?')) run(function () { return api('DELETE', '/ranges/' + r.id); }, 'Not deleted.', function () { say('Range deleted.'); }); } })])
    ]);
  }
  function save(id, x) {
    var body = { min: x.min, max: x.max, label_en: x.label_en || '', label_ar: x.label_ar || '', enabled: !!x.enabled, starts_on: x.starts_on || null, ends_on: x.ends_on || null, gifts: x.gifts.map(function (g) { return { product_id: g.product_id, quantity: g.quantity }; }) };
    delete st.errors[String(id)];
    var mine = ++st.seq;
    st.busy = true; render();
    (id === 'new' ? api('POST', '/ranges', body) : api('PUT', '/ranges/' + id, body)).then(function (d) {
      if (mine !== st.seq) return;
      st.data = d; st.edit = {}; st.busy = false; say('Range saved.'); render();
    }).catch(function (e) {
      if (mine !== st.seq) return;
      st.errors[String(id)] = explain(e, 'Not saved.'); st.busy = false; render();
    });
  }
  function move(ix, by) {
    var ids = st.data.ranges.map(function (r) { return r.id; }), j = ix + by;
    if (j < 0 || j >= ids.length) return;
    var t = ids[ix]; ids[ix] = ids[j]; ids[j] = t;
    run(function () { return api('POST', '/ranges/order', { ids: ids }); }, 'Not reordered.');
  }

  /* ---------------------------------------------------------- products */
  function renderProducts(wrap) {
    var d = st.data, byId = {};
    d.ranges.forEach(function (r) { byId[r.id] = r.label; });
    wrap.appendChild(card('Gift products', '"Gift only (not for sale)" hides a product from the shop, listings, search, the sitemap, feeds and /api, and it cannot be added to the bag; it can still be a gift. Switching it off puts the product back on sale.', [el('div', { class: 'fg-scroll', style: 'margin-top:10px' }, [el('table', { class: 'fg-table' }, [
      el('thead', {}, [el('tr', {}, ['', 'Product', 'Stock', 'Used in', 'Gift only', ''].map(function (h) { return el('th', { text: h }); }))]),
      el('tbody', {}, d.gift_products.length ? d.gift_products.map(function (p) {
        return el('tr', {}, [el('td', {}, [thumb(p)]), el('td', {}, [el('b', { text: p.name }), el('br'), el('small', { class: 'fg-sub', text: p.brand })]), el('td', {}, [stockPill(p)]),
          el('td', { text: p.used_in.map(function (id) { return byId[id]; }).filter(Boolean).join(', ') || '—' }),
          el('td', {}, [sw(p.gift_only, p.gift_only ? 'Gift only' : 'On sale', function () { run(function () { return api('POST', '/products/' + p.id + '/gift-only', { on: !p.gift_only }); }, 'Not changed.'); }, !can())]),
          el('td', {}, [typeof window.peoEdit === 'function' ? el('button', { type: 'button', class: 'fg-btn sm', text: 'Edit product', onclick: function () { window.go('product-editor'); window.peoEdit(p.id); } }) : null])]);
      }) : [el('tr', {}, [el('td', { colspan: 6, class: 'fg-sub', text: 'No gift products yet.' })])])
    ])])]));
    if (!can()) return;
    var name = el('input', { class: 'fg-in', type: 'text', placeholder: 'e.g. Isntree Mugwort Calming Clay Mask Mini' });
    var stock = el('input', { class: 'fg-in', type: 'number', min: 0, step: 1, placeholder: 'Leave empty to not count stock' });
    wrap.appendChild(card('New gift-only product', 'Creates a published product that is never for sale, ready to pick as a gift. Add its photo and brand in the product editor afterwards.', [
      el('div', { class: 'fg-grid' }, [field('Name', name), field('Stock', stock)]),
      el('div', { class: 'fg-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'fg-btn is-primary', text: 'Create gift-only product', onclick: function () {
        run(function () { return api('POST', '/products', { name: name.value.trim(), stock: stock.value === '' ? null : Number(stock.value) }); }, 'Not created.', function () { say('Gift-only product created.'); });
      } })])
    ]));
  }

  /* ----------------------------------------------------------- display */
  function textsBody(cfg) { var t = {}; TEXTS.forEach(function (k) { t[k[0] + '_en'] = cfg.texts[k[0] + '_en']; t[k[0] + '_ar'] = cfg.texts[k[0] + '_ar']; }); return t; }
  function saveSettings(cfg, msg) {
    run(function () { return api('POST', '/settings', { show_value: !!cfg.show_value, countries: cfg.countries, basis: cfg.basis, texts: textsBody(cfg), home: cfg.home }); }, 'Not saved.', function () { say(msg); });
  }
  function renderDisplay(wrap) {
    var cfg = st.edit.cfg || (st.edit.cfg = JSON.parse(JSON.stringify(st.data.config))), ro = !can();
    function sel(prop, opts) {
      var s = el('select', { class: 'fg-in', disabled: ro }, opts.map(function (o) { return el('option', { value: o[0], text: o[1], selected: cfg[prop] === o[0] }); }));
      s.addEventListener('change', function () { cfg[prop] = s.value; });
      return s;
    }
    var rows = TEXTS.map(function (k) {
      function t(lang) { var i = el('input', { class: 'fg-in', type: 'text', value: cfg.texts[k[0] + lang], dir: lang === '_ar' ? 'rtl' : null, disabled: ro, maxlength: 160 }); i.addEventListener('change', function () { cfg.texts[k[0] + lang] = i.value; }); return i; }
      return el('tr', {}, [el('td', { text: k[1] }), el('td', {}, [t('_en')]), el('td', {}, [t('_ar')])]);
    });
    wrap.appendChild(card('Display', 'How the box reads on the cart panel, the cart page and the checkout summary (and in the order\'s emails and documents).', [
      el('div', { class: 'fg-row', style: 'margin-top:12px' }, [sw(cfg.show_value, 'Show gift prices', function () { cfg.show_value = !cfg.show_value; render(); }, ro)]),
      el('p', { class: 'fg-sub', text: 'Off, as asked. On, each gift in the opened list shows its price struck through before FREE.' }),
      el('div', { class: 'fg-grid' }, [
        field('Countries', sel('countries', [['all', 'Every country the shop delivers to'], ['AE', 'United Arab Emirates only']])),
        field('What counts', sel('basis', [['after_discounts', 'Products after the coupon (as asked)'], ['before_discounts', 'Products before the coupon']]), 'Delivery, gift wrapping and the gifts never count.')
      ]),
      el('div', { class: 'fg-scroll', style: 'margin-top:14px' }, [el('table', { class: 'fg-table' }, [el('thead', {}, [el('tr', {}, [el('th', { text: 'Text' }), el('th', { text: 'English' }), el('th', { text: 'Arabic' })])]), el('tbody', {}, rows)])]),
      ro ? null : el('div', { class: 'fg-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'fg-btn is-primary', text: 'Save display', disabled: st.busy, onclick: function () { saveSettings(cfg, 'Display saved.'); } })])
    ]));
  }

  /* The sidebar row is AdminNav's static `late` entry; this only returns it
     when the console's own nav is drawn without it. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Free gifts',
      icon: '<path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/>',
      group: GROUP,
      after: ['push', 'searchterms', 'pixels']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();

  /* -------------------------------------------------------------- home */
  function renderHome(wrap) {
    var cfg = st.edit.cfg || (st.edit.cfg = JSON.parse(JSON.stringify(st.data.config))), h = cfg.home, ro = !can();
    function t(prop, rtl) { var i = el('input', { class: 'fg-in', type: 'text', value: h[prop], dir: rtl ? 'rtl' : null, disabled: ro, maxlength: 160 }); i.addEventListener('change', function () { h[prop] = i.value; }); return i; }
    wrap.appendChild(card('Homepage section', 'The section\'s design is still being chosen, so nothing shows on the homepage yet. Its words are kept here, ready for it.', [
      el('div', { class: 'fg-row', style: 'margin-top:12px' }, [sw(h.on, 'Show on the homepage (once its design is chosen)', function () { h.on = !h.on; render(); }, ro)]),
      el('div', { class: 'fg-grid' }, [field('Title (English)', t('title_en')), field('Title (Arabic)', t('title_ar', true)), field('Subtitle (English)', t('sub_en')), field('Subtitle (Arabic)', t('sub_ar', true)), field('Button (English)', t('btn_en')), field('Button (Arabic)', t('btn_ar', true))]),
      ro ? null : el('div', { class: 'fg-row', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'fg-btn is-primary', text: 'Save homepage section', disabled: st.busy, onclick: function () { saveSettings(cfg, 'Homepage section saved.'); } })])
    ]));
  }
})();
