
(function(){
  'use strict';

  var S = {data: null, loading: false, err: '', root: null, opts: {}, find: ''};
  var E = null;          // the open editor, or null
  var LEGACY_SORT = {bestsellers: 'bestselling', trending: 'trending', newest: 'newest', onsale: 'onsale', featured: 'featured'};
  var SORT_LEGACY = {bestselling: 'bestsellers', trending: 'trending', newest: 'newest', onsale: 'onsale', featured: 'featured'};

  function esc(s){
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function api(p){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/' + p;
  }

  /** fetch + JSON, with the server's own sentence on failure. */
  function req(method, path, body){
    var h = {Accept: 'application/json'};
    if (body !== undefined) { h['Content-Type'] = 'application/json'; h['X-XSRF-TOKEN'] = window.uToken ? window.uToken() : ''; }
    return fetch(api(path), {method: method, credentials: 'same-origin', headers: h, body: body === undefined ? undefined : JSON.stringify(body)})
      .then(function(r){
        return r.json().catch(function(){ return {}; }).then(function(j){
          if (!r.ok || j.ok === false) {
            var msg = j.message || j.error || '';
            if (r.status === 403) msg = 'Your role is not allowed to change this.';
            if (r.status === 404) msg = 'Not found — the cache-clearing migration for this release may not have run (Store → Core Updates).';
            if (r.status === 422 && j.errors) msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' ');
            var e = new Error(msg || ('The server answered ' + r.status + '.')); e.status = r.status; throw e;
          }
          return j;
        });
      });
  }

  function aed(fils){ return 'AED ' + (Math.round(+fils || 0) / 100).toFixed(2).replace(/\.00$/, ''); }

  function ids(v){ return String(v || '').split(',').filter(function(x){ return /^[0-9]+$/.test(x); }).map(Number); }

  /* ================================================================ list */
  function load(){
    S.loading = true; S.err = ''; paint();
    return req('GET', 'homepage-hub').then(function(j){ S.data = j; S.loading = false; paint(); })
      .catch(function(e){ S.loading = false; S.err = e.message; paint(); });
  }

  function section(key){
    var list = (S.data && S.data.sections) || [];
    for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
    return null;
  }

  function paint(){
    var el = S.root;
    if (!el || !document.contains(el)) return;
    if (S.loading && !S.data) { el.innerHTML = '<div class="hph"><p class="hph-note">Loading the homepage&hellip;</p></div>'; return; }
    if (!S.data) { el.innerHTML = '<div class="hph"><div class="hph-note is-bad"><b>Could not load the sections.</b> ' + esc(S.err) + ' <button class="hph-btn sm" data-hph-reload>Try again</button></div></div>'; bindList(); return; }

    var q = S.find.trim().toLowerCase();
    var secs = S.data.sections;
    var html = '<div class="hph"><div class="hph-top"><p>All ' + secs.length + ' sections, in the order the homepage draws them. '
      + '<b>Laptop</b> and <b>Phone</b> switch a section on or off right away; <b>Edit content</b> opens all of its controls. '
      + 'Order: <a href="#" data-hph-go="homepage">Appearance &rarr; Homepage</a>.</p>'
      + '<input class="hph-find" type="search" placeholder="Find a section…" aria-label="Find a section" value="' + esc(S.find) + '" data-hph-find></div>'
      + (S.err ? '<div class="hph-note is-bad">' + esc(S.err) + '</div>' : '')
      + '<ol class="hph-list">';

    secs.forEach(function(s, i){
      if (q && (s.label + ' ' + s.summary).toLowerCase().indexOf(q) < 0) return;
      var off = !s.desktop && !s.mobile;
      html += '<li class="hph-card' + (off ? ' is-off' : '') + '" data-hph-key="' + esc(s.key) + '">'
        + '<span class="hph-n">' + (i + 1) + '</span>'
        + '<div class="hph-main"><b>' + esc(s.label) + (off ? ' · off' : '') + '</b><span>' + esc(s.summary) + '</span></div>'
        + '<div class="hph-dev" role="group" aria-label="Show on">'
        +   '<button type="button" class="hph-pill" aria-pressed="' + (s.desktop ? 'true' : 'false') + '" data-hph-dev="' + esc(s.key) + '|desktop">Laptop</button>'
        +   '<button type="button" class="hph-pill" aria-pressed="' + (s.mobile ? 'true' : 'false') + '" data-hph-dev="' + esc(s.key) + '|mobile">Phone</button>'
        + '</div>'
        + '<button type="button" class="hph-btn" data-hph-edit="' + esc(s.key) + '">Edit content</button>'
        + '</li>';
    });

    el.innerHTML = html + '</ol></div>';
    bindList();
  }

  function bindList(){
    var el = S.root;
    el.querySelectorAll('[data-hph-reload]').forEach(function(b){ b.onclick = load; });
    el.querySelectorAll('[data-hph-edit]').forEach(function(b){ b.onclick = function(){ open(b.dataset.hphEdit); }; });
    el.querySelectorAll('[data-hph-dev]').forEach(function(b){ b.onclick = function(){ toggleDevice(b); }; });
    el.querySelectorAll('[data-hph-go]').forEach(function(a){ a.onclick = function(e){ e.preventDefault(); if (window.go) window.go(a.dataset.hphGo); }; });
    var f = el.querySelector('[data-hph-find]');
    if (f) f.oninput = function(){ S.find = f.value; var at = f.selectionStart; paint(); var g = S.root.querySelector('[data-hph-find]'); if (g) { g.focus(); try { g.setSelectionRange(at, at); } catch (e) {} } };
  }

  /** Every row, as POST /admin-api/homepage takes it, with one row changed. */
  function rowsWith(key, patch){
    return S.data.sections.map(function(s){
      var r = {key: s.key, desktop: !!s.desktop, mobile: !!s.mobile, skin: s.skin, background: s.background, width: s.width};
      return s.key === key ? Object.assign(r, patch) : r;
    });
  }

  function saveRows(rows){
    return req('POST', 'homepage', {sections: rows}).then(function(j){
      var by = {};
      (j.sections || []).forEach(function(r){ by[r.key] = r; });
      S.data.sections.forEach(function(s){ var r = by[s.key]; if (r) { s.desktop = r.desktop; s.mobile = r.mobile; s.skin = r.skin; s.background = r.background; s.width = r.width; } });
      if (S.opts.stale) S.opts.stale();
    });
  }

  function toggleDevice(b){
    var parts = b.dataset.hphDev.split('|'), s = section(parts[0]);
    if (!s) return;
    var patch = {}; patch[parts[1]] = !s[parts[1]];
    b.disabled = true;
    saveRows(rowsWith(s.key, patch)).then(function(){ S.err = ''; paint(); if (window.toast) window.toast(s.label + ' saved'); })
      .catch(function(e){ S.err = s.label + ' not saved: ' + e.message; paint(); });
  }

  /* ============================================================== editor */
  /*
   * E = {
   *   key, sec, kind, tab, tabs: [{key,label,fields?,html?}],
   *   values, orig        flat key => value for the section's own endpoint
   *   row, rowOrig        the Homepage row (desktop, mobile, skin, background, width)
   *   src                 the source picker's model, or null
   *   cards               id => product card, for the manual list
   *   preview, busy, msg
   * }
   */
  function open(key){
    var s = section(key);
    if (!s) return;
    if (s.editor.kind === 'hero') { if (S.opts.openHero) S.opts.openHero(); return; }

    E = {key: key, sec: s, kind: s.editor.kind, tab: null, tabs: [], values: {}, orig: {}, row: {}, rowOrig: {},
         src: null, cards: {}, preview: null, previewSeq: 0, busy: false, msg: null, loading: false, extra: {}};
    ['desktop', 'mobile', 'skin', 'background', 'width'].forEach(function(k){ E.row[k] = s[k]; E.rowOrig[k] = s[k]; });
    (S.data.products || []).forEach(function(p){ E.cards[p.id] = p; });
    initType();
    E.typeOwn = (s.fields || []).filter(function(f){ return f.group === 'type'; });

    if (E.kind === 'content') {
      (s.fields || []).forEach(function(f){ E.values[f.key] = f.value; E.orig[f.key] = f.value; });
      buildContentTabs();
      openPanel();
    } else if (E.kind === 'remote' || E.kind === 'grid') {
      E.loading = true;
      openPanel();
      var path = E.kind === 'grid' ? 'grid-sections/' + s.editor.id : s.editor.get;
      req('GET', path).then(function(j){
        if (!E || E.key !== key) return;
        E.loading = false;
        E.extra = j;
        var want = s.editor.tabs || null, skip = s.editor.skip || [];
        (j.tabs || []).forEach(function(t){
          if ((want && want.indexOf(t.key) < 0) || skip.indexOf(t.key) >= 0) return;
          t.fields.forEach(function(f){ E.values[f.key] = f.value; E.orig[f.key] = f.value; });
          var keep = t.fields;
          if (E.kind === 'remote') {
            keep = t.fields.filter(function(f){ return !OWN_TYPE.test(f.key); });
            E.typeOwn = E.typeOwn.concat(t.fields.filter(function(f){ return OWN_TYPE.test(f.key); }));
          }
          if (!keep.length) return;
          E.tabs.push({key: 'r-' + t.key, label: t.label, help: t.description, fields: keep, products: E.kind === 'grid' && t.key === 'products'});
        });
        if (E.kind === 'grid') initGridSource(j);
        addRowTab();
        E.tab = E.tabs[0] ? E.tabs[0].key : null;
        draw();
      }).catch(function(e){ if (!E) return; E.loading = false; E.msg = {bad: true, text: 'Could not open: ' + e.message}; addRowTab(); E.tab = 'row'; draw(); });
    } else {
      addRowTab();
      E.tab = 'row';
      openPanel();
    }
  }

  function buildContentTabs(){
    var groups = S.data.groups, s = E.sec;
    if (s.source) initRailSource();
    Object.keys(groups).forEach(function(g){
      var fields = (s.fields || []).filter(function(f){ return f.group === g; });
      if (g === 'products' && E.src) { E.tabs.push({key: 'products', label: s.key === 'brands' ? 'Brands' : 'Products', fields: fields, products: true}); return; }
      if (!fields.length) return;
      E.tabs.push({key: g, label: g === 'products' ? (s.key === 'brands' ? 'Brands' : s.key === 'blog' ? 'Articles' : 'Products') : groups[g], fields: fields});
    });
    addRowTab();
    E.tab = E.tabs[0].key;
  }

  function addRowTab(){
    var fields = [];
    (E.sec.section_tabs || []).forEach(function(t){ fields = fields.concat(t.fields); });
    E.tabs.push({key: 'row', label: 'Show & frame', fields: fields, row: true});
    addTypeTab();
  }

  /* ------------------------------------------------ Fonts & size (Lane FS) */
  /*
   * A section's OWN spacing keys — Spotted's pad_top_d, a content section's
   * home_bs_pt_d — leave the tab they came in on and are drawn here, still
   * saved by their own endpoint. SectionType::OWNED is the server's half.
   */
  var OWN_TYPE = /(^|_)(pt|pb|hg|pad|pad_top|pad_bot|head_gap|btn_gap)_[dm]$/;

  function addTypeTab(){
    var own = E.typeOwn || [], ty = E.sec.type_fields || [];
    if (!own.length && !ty.length) return;
    E.tabs.push({key: 'type', label: 'Fonts & size', type: true, own: own, fields: ty});
  }

  function initType(){
    E.ty = {}; E.tyOrig = {};
    (E.sec.type_fields || []).forEach(function(f){ E.ty[f.key] = f.value; E.tyOrig[f.key] = f.value; });
  }

  function tyField(key){
    var out = null;
    (E.sec.type_fields || []).forEach(function(f){ if (f.key === key) out = f; });
    return out;
  }

  /** A px control's current number: the moved value, or what the page draws today. */
  function tyPx(key){
    var f = tyField(key);
    if (!f) return null;
    return E.ty[key] === '' || E.ty[key] === undefined ? +f.options.today : +E.ty[key];
  }

  function typeFieldHTML(f){
    var id = 'hph-t-' + f.key, v = E.ty[f.key];
    if (f.type === 'font') {
      return '<div class="hph-row"><div><span class="hph-l">' + esc(f.label) + '</span><small>' + esc(f.help) + '</small></div><div data-hph-font="' + esc(f.key) + '"></div></div>';
    }
    if (f.type === 'px') {
      var set = v !== '' && v !== undefined, n = set ? +v : +f.options.today;
      return '<div class="hph-row"><div><label for="' + id + '">' + esc(f.label) + '</label></div><div class="hph-px">'
        + '<input type="range" id="' + id + '" min="' + (+f.options.min) + '" max="' + (+f.options.max) + '" step="1" value="' + n + '" data-hph-t="' + esc(f.key) + '">'
        + '<output for="' + id + '" data-hph-out="' + esc(f.key) + '">' + n + 'px' + (set ? '' : ' <i>· as designed</i>') + '</output>'
        + '<button type="button" class="hph-btn sm" data-hph-reset="' + esc(f.key) + '"' + (set ? '' : ' hidden') + '>Reset to ' + (+f.options.today) + 'px</button></div></div>';
    }
    var opts = f.options || {};
    return '<div class="hph-row"><div><label for="' + id + '">' + esc(f.label) + '</label></div><div><select class="hph-in" id="' + id + '" data-hph-t="' + esc(f.key) + '">'
      + Object.keys(opts).map(function(k){ return '<option value="' + esc(k) + '"' + (String(k) === String(v) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>'; }).join('')
      + '</select></div></div>';
  }

  /** The sample: the section's own name, set as the shop would set it. */
  function sampleHTML(){
    var fp = window.kbbFontPicker, font = E.ty.font, heading = !!tyField('h_d');
    var fam = fp ? fp.family(font || '', heading) : 'inherit';
    var tt = {upper: 'uppercase', lower: 'lowercase', capitalize: 'capitalize', none: 'none'}[E.ty['case']] || 'none';
    function one(dev, label){
      var h = tyPx('h_' + dev), sub = tyPx('sub_' + dev), body = tyPx('body_' + dev);
      return '<div class="hph-ty"><small>' + label + '</small>'
        + (heading ? '<b style="font-family:' + esc(fam) + ';font-size:' + h + 'px;text-transform:' + tt + '">' + esc(E.sec.label) + '</b>' : '')
        + (sub ? '<span style="font-size:' + sub + 'px">The line under the heading, at ' + sub + 'px.</span>' : '')
        + (body ? '<span style="font-size:' + body + 'px' + (heading ? '' : ';font-family:' + esc(fam)) + '">Card and body text, at ' + body + 'px.</span>' : '')
        + (!heading && !body ? '<span style="font-family:' + esc(fam) + '">' + esc(E.sec.label) + '</span>' : '')
        + '</div>';
    }
    return '<div class="hph-tys" id="hph-tys">' + one('d', 'Laptop') + one('m', 'Phone') + '</div>';
  }

  function typeTabHTML(t){
    var h = '<p class="hph-note">Fonts, text sizes and spacing for this section only. Each control starts where the page is today; nothing changes on the shop until you move it and press Save section.</p>';
    if (t.fields.length) h += sampleHTML();
    if (t.fields.length) h += '<div>' + t.fields.map(typeFieldHTML).join('') + '</div>';
    if (t.own.length) h += '<p class="hph-sub">This section’s own spacing</p><div>' + t.own.map(function(f){ return fieldHTML(f, E.values[f.key]); }).join('') + '</div>';
    return h;
  }

  function setTy(key, value){
    E.ty[key] = value;
    E.msg = null;
    var msg = document.getElementById('hph-msg');
    if (msg) msg.innerHTML = msgHTML();
    var s = document.getElementById('hph-tys');
    if (s) s.outerHTML = sampleHTML();
  }

  function bindType(bd){
    bd.querySelectorAll('[data-hph-t]').forEach(function(el){
      var k = el.dataset.hphT;
      el.oninput = el.onchange = function(){
        var f = tyField(k);
        if (f && f.type === 'px') {
          setTy(k, +el.value);
          var o = bd.querySelector('[data-hph-out="' + k + '"]'); if (o) o.textContent = el.value + 'px';
          var r = bd.querySelector('[data-hph-reset="' + k + '"]'); if (r) r.hidden = false;
        } else setTy(k, el.value);
      };
    });
    bd.querySelectorAll('[data-hph-reset]').forEach(function(b){
      b.onclick = function(){ setTy(b.dataset.hphReset, ''); redrawBody(); };
    });
    bd.querySelectorAll('[data-hph-font]').forEach(function(host){
      var f = tyField(host.dataset.hphFont);
      if (!f || !window.kbbFontPicker) return;
      window.kbbFontPicker.mount(host, {value: E.ty[f.key], labels: f.options, heading: !!tyField('h_d'),
        onChange: function(v){ setTy(f.key, v); }});
    });
  }

  function typeDirty(){
    for (var k in E.ty) if (String(E.ty[k]) !== String(E.tyOrig[k])) return true;
    return false;
  }

  /* ------------------------------------------------------ source picker */
  function initRailSource(){
    var k = E.sec.source, v = E.values, src = String(v[k.source] || '');
    var m = {keys: k, style: k.style, mode: 'auto', sort: 'bestselling', brands: [], cats: [], stock: false, picks: ids(v[k.picks])};
    if (k.style === 'legacy') {
      m.mode = src === 'manual' ? 'manual' : src === 'query' ? 'auto' : 'shipped';
      m.sort = v[k.sort] || 'bestselling'; m.brands = ids(v[k.brands]); m.cats = ids(v[k.cats]); m.stock = !!v[k.stock];
    } else if (src === 'manual') { m.mode = 'manual'; }
    else if (src === 'query') { m.sort = v[k.sort] || 'bestselling'; m.brands = ids(v[k.brands]); m.cats = ids(v[k.cats]); m.stock = !!v[k.stock]; }
    else if (src === 'brand') { m.brands = ids(v[k.brand]); }
    else if (src === 'category') { m.cats = ids(v[k.cat]); }
    else { m.sort = LEGACY_SORT[src] || 'bestselling'; }
    E.src = m;
  }

  function initGridSource(j){
    var v = E.values, q = j.source_query || {}, src = String(v.source || '');
    var m = {style: 'grid', mode: 'auto', sort: 'bestselling', brands: [], cats: [], stock: false, picks: (j.manual || []).map(function(p){ return +p.id; })};
    (j.manual || []).forEach(function(p){ E.cards[p.id] = {id: +p.id, name: p.name, brand: '', image: p.image, price: p.price}; });
    if (src === 'manual') m.mode = 'manual';
    else if (src === 'query') { m.sort = q.sort || 'bestselling'; m.brands = q.brands || []; m.cats = q.cats || []; m.stock = !!q.stock; }
    else if (src === 'brand') m.brands = v.source_brand_id ? [+v.source_brand_id] : [];
    else if (src === 'category') m.cats = v.source_category_id ? [+v.source_category_id] : [];
    else m.sort = LEGACY_SORT[src] || 'bestselling';
    m.origQuery = JSON.stringify(q); m.origPicks = m.picks.join(',');
    E.src = m;
  }

  /*
   * The owner's choice, written back as the OLD value wherever it is one:
   * "best selling, every brand" stays `bestsellers`, one brand stays `brand`.
   * Only a choice the old sources cannot say becomes `query` — so a section
   * whose Products tab is opened and left alone writes nothing at all.
   */
  function writeSource(){
    var m = E.src, v = E.values;
    var simple = !m.stock && m.mode === 'auto';
    var src;
    if (m.style === 'legacy') {
      var k = m.keys;
      v[k.source] = m.mode === 'manual' ? 'manual' : m.mode === 'shipped' ? 'auto' : 'query';
      if (m.mode === 'auto') { v[k.brands] = m.brands.join(','); v[k.cats] = m.cats.join(','); v[k.sort] = m.sort; v[k.stock] = m.stock; }
      if (m.mode === 'manual') v[k.picks] = m.picks.join(',');
      return;
    }
    if (m.mode === 'manual') src = 'manual';
    else if (simple && !m.brands.length && !m.cats.length && SORT_LEGACY[m.sort]) src = SORT_LEGACY[m.sort];
    else if (simple && m.sort === 'bestselling' && m.brands.length === 1 && !m.cats.length) src = 'brand';
    else if (simple && m.sort === 'bestselling' && m.cats.length === 1 && !m.brands.length) src = 'category';
    else src = 'query';

    if (m.style === 'grid') {
      v.source = src;
      if (src === 'brand') v.source_brand_id = String(m.brands[0]);
      if (src === 'category') v.source_category_id = String(m.cats[0]);
      return;
    }
    var r = m.keys;
    v[r.source] = src;
    if (src === 'brand') v[r.brand] = String(m.brands[0]);
    if (src === 'category') v[r.cat] = String(m.cats[0]);
    if (src === 'query') { v[r.brands] = m.brands.join(','); v[r.cats] = m.cats.join(','); v[r.sort] = m.sort; v[r.stock] = m.stock; }
    if (src === 'manual') v[r.picks] = m.picks.join(',');
  }

  function srcConsumes(key){
    var m = E.src;
    if (!m) return false;
    if (m.style === 'grid') return ['source', 'source_brand_id', 'source_category_id'].indexOf(key) >= 0;
    var k = m.keys;
    return [k.source, k.brand, k.cat, k.brands, k.cats, k.sort, k.stock, k.picks].indexOf(key) >= 0;
  }

  function vocabName(list, id){
    var l = (S.data.vocab[list] || []);
    for (var i = 0; i < l.length; i++) if (l[i].id === id) return l[i].name;
    return '#' + id + ' — no longer listed';
  }

  function chipsHTML(list, chosen, name){
    var opts = (S.data.vocab[list] || []).filter(function(r){ return chosen.indexOf(r.id) < 0; });
    return '<div class="hph-chips">'
      + chosen.map(function(id, i){ return '<span class="hph-chip"><b>' + esc(vocabName(list, id)) + '</b><button type="button" aria-label="Remove" data-hph-chip="' + name + '|' + i + '">×</button></span>'; }).join('')
      + (chosen.length < 20 ? '<select class="hph-in" style="width:auto;max-width:100%" data-hph-chipadd="' + name + '"><option value="">+ Add ' + (list === 'brands' ? 'a brand' : list === 'categories' ? 'a category' : 'one') + '…</option>'
        + opts.map(function(r){ return '<option value="' + r.id + '">' + esc(r.name) + '</option>'; }).join('') + '</select>' : '')
      + '</div>';
  }

  function sourceHTML(){
    var m = E.src, sorts = S.data.sorts;
    var modes = (m.style === 'legacy' ? [['shipped', 'As shipped']] : []).concat([['auto', 'Automatic'], ['manual', 'Manual — I pick them']]);
    var h = '<div class="hph-row"><div><span class="hph-l">Which products</span><small>Automatic follows your brands, categories and order as the catalogue changes. Manual shows exactly the products you pick, in your order.</small></div><div>'
      + '<div class="hph-seg" role="group">' + modes.map(function(x){ return '<button type="button" aria-pressed="' + (m.mode === x[0]) + '" data-hph-mode="' + x[0] + '">' + x[1] + '</button>'; }).join('') + '</div></div></div>';

    if (m.mode === 'shipped') {
      h += '<div class="hph-note">' + esc(E.sec.description) + ' The shop chooses these itself; pick Automatic or Manual to choose them yourself.</div>';
    } else if (m.mode === 'auto') {
      h += '<div class="hph-row"><div><span class="hph-l">Brands</span><small>Any of these. None: every brand.</small></div><div>' + chipsHTML('brands', m.brands, 'brands') + '</div></div>'
        + '<div class="hph-row"><div><span class="hph-l">Categories</span><small>Any of these — mix as many as you like. None: every category.</small></div><div>' + chipsHTML('categories', m.cats, 'cats') + '</div></div>'
        + '<div class="hph-row"><div><label for="hph-sort">Order</label></div><div><select class="hph-in" id="hph-sort" data-hph-sort>'
        + Object.keys(sorts).map(function(k){ return '<option value="' + k + '"' + (m.sort === k ? ' selected' : '') + '>' + esc(sorts[k]) + '</option>'; }).join('') + '</select></div></div>'
        + '<div class="hph-row"><div><span class="hph-l">Stock</span></div><div><label class="hph-sw"><input type="checkbox" data-hph-stock' + (m.stock ? ' checked' : '') + '> In stock only</label></div></div>';
    } else {
      h += '<div class="hph-row"><div><span class="hph-l">Products, in order</span><small>Search by name, brand or SKU. Drag the handle, or use ↑ ↓, to order them. Up to 24.</small></div><div>'
        + '<div class="hph-ta"><input class="hph-in" id="hph-ta-in" type="search" placeholder="Search products — 2 letters or more" aria-label="Search products"><div class="hph-res" id="hph-ta-res" hidden></div></div>'
        + '<ol class="hph-picks" style="margin-top:8px">' + m.picks.map(function(id, i){
            var c = E.cards[id] || {name: '#' + id, brand: '', image: '', price: 0};
            return '<li class="hph-pick" draggable="true" data-hph-pick="' + i + '"><span class="hph-grip" aria-hidden="true">⠿</span>'
              + (c.image ? '<img class="hph-th" src="' + esc(c.image) + '" alt="" loading="lazy">' : '<span class="hph-th"></span>')
              + '<div><b>' + esc(c.name) + '</b><span>' + esc([c.brand, c.price ? aed(c.price) : ''].filter(Boolean).join(' · ')) + '</span></div>'
              + '<div><button type="button" class="hph-btn sm" data-hph-mv="' + i + '|-1"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button>'
              + '<button type="button" class="hph-btn sm" data-hph-mv="' + i + '|1"' + (i === m.picks.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>'
              + '<button type="button" class="hph-btn sm" data-hph-mv="' + i + '|x" aria-label="Remove">×</button></div></li>';
          }).join('') + '</ol>'
        + (m.picks.length ? '' : '<p class="hph-note" style="margin-top:8px">Nothing picked yet — the section draws nothing until you add a product.</p>')
        + '</div></div>';
    }

    if (m.mode !== 'shipped') {
      h += '<div><p class="hph-h">Shows now' + (E.preview ? ' · ' + E.preview.length : '') + '</p>'
        + (E.preview === null ? '<p class="hph-note">Loading…</p>' : E.preview.length ? '<div class="hph-pv" id="hph-pv">' + E.preview.map(function(p){
            return '<figure>' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '<span class="hph-th"></span>') + '<figcaption>' + esc(p.name) + '</figcaption></figure>';
          }).join('') + '</div>' : '<p class="hph-note">Nothing to show — the section draws nothing until something matches.</p>') + '</div>';
    }
    return h;
  }

  var pvTimer = null;
  function refreshPreview(){
    if (!E || !E.src || E.src.mode === 'shipped') return;
    clearTimeout(pvTimer);
    var mine = ++E.previewSeq, key = E.key;
    pvTimer = setTimeout(function(){
      var m = E.src, v = E.values, body = {source: m.mode === 'manual' ? 'manual' : 'query', picks: m.picks,
        query: {brands: m.brands, cats: m.cats, sort: m.sort, stock: m.stock}, limit: limitNow()};
      if (m.style === 'grid') body.children = !!v.include_children;
      if (m.style === 'rail' && +v[m.keys.source.replace('_source', '_max')]) body.max = +v[m.keys.source.replace('_source', '_max')];
      req('POST', 'homepage-hub/preview', body).then(function(j){
        if (!E || E.key !== key || mine !== E.previewSeq) return;
        E.preview = j.products || [];
        (j.products || []).forEach(function(p){ E.cards[p.id] = p; });
        if (E.tab === 'products' || (E.tabs.filter(function(t){ return t.key === E.tab && t.products; }).length)) redrawBody();
      }).catch(function(){ if (E && mine === E.previewSeq) { E.preview = []; redrawBody(); } });
    }, 300);
  }

  function limitNow(){
    var m = E.src, v = E.values;
    if (m.style === 'grid') return Math.max(+v.count || 8, +v.mobile_count || 8);
    if (m.style === 'legacy') return +v[m.keys.limit] || 8;
    return Math.max(+v[m.keys.limit_d] || 8, +v[m.keys.limit_m] || 6);
  }

  /* ------------------------------------------- per-pick images (Lane BS) */
  /** The field that keeps one value per row of the `ids` field `key`, or null. */
  function perPick(key){
    var list = (E && E.sec && E.sec.fields) || [];
    for (var i = 0; i < list.length; i++) if (list[i].options && list[i].options.picker === 'per-pick' && list[i].options.for === key) return list[i];
    return null;
  }

  /** id => path, from the JSON the server keeps. Anything else reads as none. */
  function imgMap(k){
    try { var m = JSON.parse(E.values[k] || '{}'); return m && typeof m === 'object' && !Array.isArray(m) ? m : {}; } catch (e) { return {}; }
  }

  function setImg(k, id, url){
    var m = imgMap(k);
    if (url) m[id] = String(url); else delete m[id];
    set(k, Object.keys(m).length ? JSON.stringify(m) : '');
    redrawBody();
  }

  /* One row per picked brand: its image, Choose / Remove, ↑ ↓ and ×. The
     image travels with the Save section the rest of the tab uses — no request
     of its own, and nothing is fetched to draw it but the thumbnail. */
  function pickRowsHTML(list, chosen, f, per){
    var m = imgMap(per.key), name = 'f:' + f.key, cap = +f.options.cap || 24;
    var opts = (S.data.vocab[list] || []).filter(function(r){ return chosen.indexOf(r.id) < 0; });
    return '<ol class="hph-picks">' + chosen.map(function(id, i){
        var src = m[id] || '';
        return '<li class="hph-pick is-img">'
          + (src ? '<img class="hph-th" src="' + esc(src) + '" alt="" width="44" height="55" loading="lazy">' : '<span class="hph-th" title="No image chosen — the brand’s banner photo shows"></span>')
          + '<div><b>' + esc(vocabName(list, id)) + '</b><span>' + (src ? 'Own image' : 'No image chosen') + '</span></div>'
          + '<span class="hph-pact">'
          +   '<button type="button" class="hph-btn sm" data-hph-pimg="' + esc(per.key) + '|' + id + '">' + (src ? 'Change' : 'Choose image') + '</button>'
          +   (src ? '<button type="button" class="hph-btn sm" data-hph-pimgx="' + esc(per.key) + '|' + id + '">Remove</button>' : '')
          +   '<button type="button" class="hph-btn sm" aria-label="Move up" data-hph-pmv="' + esc(name) + '|' + i + '|-1"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
          +   '<button type="button" class="hph-btn sm" aria-label="Move down" data-hph-pmv="' + esc(name) + '|' + i + '|1"' + (i === chosen.length - 1 ? ' disabled' : '') + '>↓</button>'
          +   '<button type="button" class="hph-btn sm" aria-label="Remove ' + esc(vocabName(list, id)) + '" data-hph-chip="' + esc(name) + '|' + i + '">×</button>'
          + '</span></li>';
      }).join('') + '</ol>'
      + (chosen.length < cap ? '<select class="hph-in" style="width:auto;max-width:100%;margin-top:6px" data-hph-chipadd="' + esc(name) + '"><option value="">+ Add a brand…</option>'
        + opts.map(function(r){ return '<option value="' + r.id + '">' + esc(r.name) + '</option>'; }).join('') + '</select>' : '')
      + '<small>' + esc(per.help) + '</small>';
  }

  /* ---------------------------------------------------------- fields */
  function fieldHTML(f, value){
    var id = 'hph-f-' + String(f.key).replace(/[^a-z0-9]+/gi, '-');
    var v = value === undefined || value === null ? f.default : value;
    var ctl;
    var data = ' data-hph-k="' + esc(f.key) + '"';

    // Lane BS: a per-pick value (one image per picked brand) is drawn by its
    // pick list, beside each row — never as a box of its own.
    if (f.options && f.options.picker === 'per-pick') return '';

    if (f.type === 'bool') {
      return '<div class="hph-row"><div><span class="hph-l">' + esc(f.label) + '</span>' + (f.help ? '<small>' + esc(f.help) + '</small>' : '') + '</div><div>'
        + '<label class="hph-sw"><input type="checkbox" id="' + id + '"' + data + (v ? ' checked' : '') + '> ' + (v ? 'On' : 'Off') + '</label></div></div>';
    }
    if (f.type === 'select' || f.type === 'skin') {
      var opts = f.options || {};
      if (f.type === 'skin' || f.key === 'skin') { opts = {}; (S.data.skins || []).forEach(function(s){ opts[s.key] = s.label; }); }
      if (f.key === 'source_brand_id' && E.extra.brands) opts = E.extra.brands;
      if (f.key === 'source_category_id' && E.extra.categories) opts = E.extra.categories;
      if (f.key === 'set' && E.extra.setOptions) opts = E.extra.setOptions;
      ctl = '<select class="hph-in" id="' + id + '"' + data + '>' + Object.keys(opts).map(function(k){
        return '<option value="' + esc(k) + '"' + (String(k) === String(v) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>';
      }).join('') + '</select>';
    } else if (f.type === 'colour') {
      ctl = '<div class="hph-col"><input type="color" id="' + id + '" value="' + esc(v) + '"' + data + '><code>' + esc(v) + '</code></div>';
    } else if (f.type === 'textarea') {
      ctl = '<textarea class="hph-in" id="' + id + '"' + data + '>' + esc(v) + '</textarea>';
    } else if (f.type === 'ids' && f.options && f.options.of && f.options.of !== 'products') {
      var list = f.options.of === 'posts' ? 'posts' : f.options.of;
      var chosen = ids(v);
      if (+f.options.cap === 1) {
        ctl = '<select class="hph-in" id="' + id + '"' + data + '><option value="">— none —</option>'
          + (S.data.vocab[list] || []).map(function(r){ return '<option value="' + r.id + '"' + (r.id === chosen[0] ? ' selected' : '') + '>' + esc(r.name) + '</option>'; }).join('') + '</select>';
      } else {
        var per = perPick(f.key);
        ctl = per ? pickRowsHTML(list, chosen, f, per) : chipsHTML(list, chosen, 'f:' + f.key);
      }
    } else if (f.type === 'int' || f.type === 'range') {
      var o = f.options || {};
      ctl = '<input class="hph-in" type="number" id="' + id + '" value="' + esc(v) + '"' + data
        + (o.min !== undefined ? ' min="' + esc(o.min) + '"' : '') + (o.max !== undefined ? ' max="' + esc(o.max) + '"' : '') + '>';
    } else {
      ctl = '<input class="hph-in" type="text" id="' + id + '" value="' + esc(v) + '"' + data + '>';
      if (f.options && f.options.picker === 'media') {
        ctl = '<div class="hph-col">' + ctl + '<button type="button" class="hph-btn sm" data-hph-media="' + esc(f.key) + '">Choose</button></div>';
      }
    }
    return '<div class="hph-row"><div><label for="' + id + '">' + esc(f.label) + '</label>' + (f.help ? '<small>' + esc(f.help) + '</small>' : '') + '</div><div>' + ctl + '</div></div>';
  }

  /* ----------------------------------------------------------- panel */
  function openPanel(){
    var ov = document.getElementById('hph-ov');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'hph-ov';
      ov.className = 'hph-ov';
      document.body.appendChild(ov);
      ov.addEventListener('mousedown', function(e){ if (e.target === ov) close(); });
      document.addEventListener('keydown', function(e){ if (E && e.key === 'Escape' && !document.querySelector('.kpp-box:not([hidden])')) close(); });
    }
    ov.hidden = false;
    draw();
    var first = ov.querySelector('.hph-tab[aria-selected="true"]') || ov.querySelector('.hph-x');
    if (first) first.focus();
  }

  function isDirty(){
    if (!E) return false;
    for (var k in E.values) if (String(E.values[k]) !== String(E.orig[k])) return true;
    for (var r in E.row) if (String(E.row[r]) !== String(E.rowOrig[r])) return true;
    if (typeDirty()) return true;
    if (E.src && E.src.style === 'grid' && (E.src.picks.join(',') !== E.src.origPicks || gridQueryChanged())) return true;
    return false;
  }

  function gridQueryChanged(){
    var m = E.src;
    return m.mode === 'auto' && E.values.source === 'query' && JSON.stringify({brands: m.brands, cats: m.cats, sort: m.sort, stock: m.stock}) !== m.origQuery;
  }

  function close(force){
    if (!E) return;
    if (!force && isDirty() && !window.confirm('Discard your unsaved changes to ' + E.sec.label + '?')) return;
    E = null;
    clearTimeout(pvTimer);
    var ov = document.getElementById('hph-ov');
    if (ov) { ov.hidden = true; ov.innerHTML = ''; }
    if (picker) { picker.destroy(); picker = null; }
  }

  function draw(){
    var ov = document.getElementById('hph-ov');
    if (!ov || !E) return;
    var s = E.sec, idx = S.data.sections.indexOf(s) + 1;
    var also = s.editor.also ? '<a href="#" data-hph-also="' + esc(s.editor.also[0]) + '">Also in ' + esc(s.editor.also[1]) + '</a>' : '';
    ov.innerHTML = '<div class="hph-dlg" role="dialog" aria-modal="true" aria-labelledby="hph-title">'
      + '<div class="hph-dh"><div><h3 id="hph-title">' + esc(s.label) + '</h3><small>Section ' + idx + ' of ' + S.data.sections.length + ' · ' + esc(s.summary) + '</small></div>'
      + '<button type="button" class="hph-x" data-hph-close aria-label="Close">×</button></div>'
      + '<div class="hph-tabs" role="tablist">' + E.tabs.map(function(t){
          return '<button type="button" class="hph-tab" role="tab" aria-selected="' + (t.key === E.tab) + '" data-hph-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
        }).join('') + '</div>'
      + '<div class="hph-bd" id="hph-bd"></div>'
      + '<div class="hph-df"><span class="hph-msg" id="hph-msg"></span>' + also
      + '<button type="button" class="hph-btn" data-hph-close>Close</button>'
      + '<button type="button" class="hph-btn is-primary" data-hph-save' + (E.busy ? ' disabled' : '') + '>' + (E.busy ? 'Saving…' : 'Save section') + '</button></div></div>';

    ov.querySelectorAll('[data-hph-close]').forEach(function(b){ b.onclick = function(){ close(); }; });
    ov.querySelectorAll('[data-hph-tab]').forEach(function(b){ b.onclick = function(){ E.tab = b.dataset.hphTab; draw(); }; });
    ov.querySelectorAll('[data-hph-save]').forEach(function(b){ b.onclick = save; });
    ov.querySelectorAll('[data-hph-also]').forEach(function(a){ a.onclick = function(e){ e.preventDefault(); var to = a.dataset.hphAlso; close(); if (!E && window.go) window.go(to); }; });
    redrawBody();
  }

  function msgHTML(){
    if (!E.msg) return isDirty() ? 'Unsaved changes' : '';
    return '<span style="color:' + (E.msg.bad ? '#b4443c' : E.msg.warn ? '#9a6b00' : '#0f8f4b') + '">' + esc(E.msg.text) + '</span>';
  }

  var picker = null;

  function redrawBody(){
    var bd = document.getElementById('hph-bd');
    if (!bd || !E) return;
    var t = null;
    E.tabs.forEach(function(x){ if (x.key === E.tab) t = x; });
    var h = '';

    if (E.loading) h = '<p class="hph-note">Opening…</p>';
    else if (!t) h = '<p class="hph-note">Nothing to edit here.</p>';
    else {
      if (t.help) h += '<p class="hph-note">' + esc(t.help) + '</p>';
      if (t.row && E.sec.editor.note) h += '<p class="hph-note">' + esc(E.sec.editor.note) + '</p>';
      if (t.type) {
        h += typeTabHTML(t);
      } else if (t.products && E.src) {
        h += sourceHTML();
        h += '<div>' + t.fields.filter(function(f){ return !srcConsumes(f.key); }).map(function(f){ return fieldHTML(f, E.values[f.key]); }).join('') + '</div>';
      } else if (t.row) {
        h += '<div>' + t.fields.map(function(f){ return fieldHTML(f, E.row[f.key]); }).join('') + '</div>';
      } else {
        h += '<div>' + t.fields.map(function(f){ return fieldHTML(f, E.values[f.key]); }).join('') + '</div>';
      }
    }
    bd.innerHTML = h;
    var msg = document.getElementById('hph-msg');
    if (msg) msg.innerHTML = msgHTML();
    bindBody(bd, t);
    if (t && t.products && E.src && E.preview === null) refreshPreview();
  }

  function set(key, value, isRow){
    if (isRow) E.row[key] = value; else E.values[key] = value;
    E.msg = null;
    var msg = document.getElementById('hph-msg');
    if (msg) msg.innerHTML = msgHTML();
  }

  function srcChanged(){
    writeSource();
    E.msg = null;
    E.preview = E.preview || [];
    redrawBody();
    refreshPreview();
  }

  function bindBody(bd, t){
    var isRow = !!(t && t.row);
    if (t && t.type) bindType(bd);
    bd.querySelectorAll('[data-hph-k]').forEach(function(el){
      var k = el.dataset.hphK;
      var fire = function(){
        var v = el.type === 'checkbox' ? el.checked : el.value;
        if (el.type === 'checkbox' && el.parentNode.lastChild.nodeType === 3) el.parentNode.lastChild.nodeValue = ' ' + (v ? 'On' : 'Off');
        if (el.type === 'color' && el.nextSibling) el.nextSibling.textContent = v;
        set(k, v, isRow);
        if (E.src && /(_count_[dm]|_limit|^count$|^mobile_count$|_max$|^include_children$)/.test(k)) refreshPreview();
      };
      el.oninput = fire;
      el.onchange = fire;
    });

    bd.querySelectorAll('[data-hph-media]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') return;
        var k = b.dataset.hphMedia;
        window.kbbPickMedia({title: 'Photo', note: 'The photo for this homepage section.', folder: 'appearance',
          onPick: function(urls){ if (urls && urls[0]) { set(k, String(urls[0])); redrawBody(); } }});
      };
    });

    bd.querySelectorAll('[data-hph-pimg]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') return;
        var p = b.dataset.hphPimg.split('|');
        window.kbbPickMedia({title: 'Brand image', note: 'The image on this brand’s homepage card.', folder: 'appearance',
          onPick: function(urls){ if (E && urls && urls[0]) setImg(p[0], p[1], urls[0]); }});
      };
    });
    bd.querySelectorAll('[data-hph-pimgx]').forEach(function(b){
      b.onclick = function(){ var p = b.dataset.hphPimgx.split('|'); setImg(p[0], p[1], ''); };
    });
    bd.querySelectorAll('[data-hph-pmv]').forEach(function(b){
      b.onclick = function(){
        var p = b.dataset.hphPmv.split('|'), i = +p[1], j = i + (+p[2]);
        chipEdit(p[0], function(a){ if (j >= 0 && j < a.length) { var x = a[i]; a[i] = a[j]; a[j] = x; } });
      };
    });

    bd.querySelectorAll('[data-hph-chip]').forEach(function(b){
      b.onclick = function(){ var p = b.dataset.hphChip.split('|'); chipEdit(p[0], function(a){ a.splice(+p[1], 1); }); };
    });
    bd.querySelectorAll('[data-hph-chipadd]').forEach(function(sel){
      sel.onchange = function(){ var id = +sel.value; if (id) chipEdit(sel.dataset.hphChipadd, function(a){ if (a.indexOf(id) < 0) a.push(id); }); };
    });

    if (!E.src) return;
    bd.querySelectorAll('[data-hph-mode]').forEach(function(b){ b.onclick = function(){ E.src.mode = b.dataset.hphMode; E.preview = null; srcChanged(); }; });
    var so = bd.querySelector('[data-hph-sort]'); if (so) so.onchange = function(){ E.src.sort = so.value; srcChanged(); };
    var st = bd.querySelector('[data-hph-stock]'); if (st) st.onchange = function(){ E.src.stock = st.checked; srcChanged(); };
    bd.querySelectorAll('[data-hph-mv]').forEach(function(b){
      b.onclick = function(){
        var p = b.dataset.hphMv.split('|'), i = +p[0], a = E.src.picks;
        if (p[1] === 'x') a.splice(i, 1); else { var j = i + (+p[1]); var x = a[i]; a[i] = a[j]; a[j] = x; }
        srcChanged();
      };
    });

    /* Drag to reorder: native drag and drop, no library. Touch screens use
       the ↑ ↓ buttons beside each row, which do the same thing. */
    var from = null;
    bd.querySelectorAll('[data-hph-pick]').forEach(function(li){
      li.ondragstart = function(e){ from = +li.dataset.hphPick; li.classList.add('is-drag'); try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(from)); } catch (x) {} };
      li.ondragend = function(){ li.classList.remove('is-drag'); };
      li.ondragover = function(e){ e.preventDefault(); };
      li.ondrop = function(e){
        e.preventDefault();
        var to = +li.dataset.hphPick;
        if (from === null || from === to) return;
        var a = E.src.picks, x = a.splice(from, 1)[0];
        a.splice(to, 0, x);
        from = null;
        srcChanged();
      };
    });

    if (document.getElementById('hph-ta-in') && typeof window.kbbProductPicker === 'function') {
      if (!picker) {
        picker = window.kbbProductPicker({
          input: '#hph-ta-in', results: '#hph-ta-res', minChars: 2, debounceMs: 300,
          emptyText: 'No visible product matches that.',
          search: function(term){ return req('GET', 'homepage-hub/products?q=' + encodeURIComponent(term)).then(function(j){ return j.products || []; }); },
          rowMeta: function(p){ return p.brand || ''; },
          rowSide: function(p){ return p.price ? aed(p.price) : ''; },
          onPick: function(p){
            if (!E || !E.src) return;
            E.cards[p.id] = p;
            if (E.src.picks.indexOf(p.id) < 0 && E.src.picks.length < 24) E.src.picks.push(p.id);
            picker.reset();
            srcChanged();
          }
        });
      }
      picker.attach();
    }
  }

  function chipEdit(name, fn){
    if (name.indexOf('f:') === 0) {
      var k = name.slice(2), a = ids(E.values[k]);
      fn(a); set(k, a.join(',')); redrawBody(); return;
    }
    fn(E.src[name]);
    srcChanged();
  }

  /* ------------------------------------------------------------ save */
  function changed(){
    var out = {};
    for (var k in E.values) if (String(E.values[k]) !== String(E.orig[k])) out[k] = E.values[k];
    return out;
  }

  function save(){
    if (!E || E.busy) return;
    var key = E.key, s = E.sec, vals = changed(), jobs = [], warn = [];
    var rowDirty = false;
    for (var r in E.row) if (String(E.row[r]) !== String(E.rowOrig[r])) rowDirty = true;

    if (E.kind === 'content' && Object.keys(vals).length) {
      jobs.push(function(){ return req('POST', 'homepage/content', {copy: vals}).then(function(j){
        Object.keys(j.rejected || {}).forEach(function(k){ warn.push(k); });
      }); });
    }
    if (E.kind === 'remote' && Object.keys(vals).length) {
      jobs.push(function(){ return req('POST', s.editor.save, {settings: vals}).then(function(j){
        Object.keys(j.rejected || {}).forEach(function(k){ warn.push(k); });
      }); });
    }
    if (E.kind === 'grid') {
      var body = {};
      if (Object.keys(vals).length) body.values = vals;
      if (E.src && E.src.picks.join(',') !== E.src.origPicks) body.manual_ids = E.src.picks;
      if (E.src && gridQueryChanged()) body.source_query = {brands: E.src.brands, cats: E.src.cats, sort: E.src.sort, stock: E.src.stock};
      if (Object.keys(body).length) jobs.push(function(){ return req('PUT', 'grid-sections/' + s.editor.id, body); });
    }
    if (rowDirty) jobs.push(function(){ return saveRows(rowsWith(key, E.row)); });
    if (typeDirty()) {
      var ty = {};
      for (var tk in E.ty) if (String(E.ty[tk]) !== String(E.tyOrig[tk])) ty[tk] = E.ty[tk];
      jobs.push(function(){ return req('POST', 'homepage-hub/type', {key: key, values: ty}); });
    }

    if (!jobs.length) { E.msg = {text: 'Nothing has changed.'}; redrawBody(); return; }

    E.busy = true; E.msg = null; draw();
    var chain = Promise.resolve();
    jobs.forEach(function(j){ chain = chain.then(j); });
    chain.then(function(){
      if (S.opts.stale) S.opts.stale();
      return req('GET', 'homepage-hub').then(function(j){ S.data = j; });
    }).then(function(){
      paint();
      if (!E || E.key !== key) return;
      var fresh = section(key);
      E.busy = false;
      E.sec = fresh || E.sec;
      ['desktop', 'mobile', 'skin', 'background', 'width'].forEach(function(k){ E.row[k] = E.sec[k]; E.rowOrig[k] = E.sec[k]; });
      initType();
      E.tabs.forEach(function(t){ if (t.type) t.fields = E.sec.type_fields || []; });
      for (var k in E.values) E.orig[k] = E.values[k];
      if (E.kind === 'content') (E.sec.fields || []).forEach(function(f){ E.values[f.key] = f.value; E.orig[f.key] = f.value; });
      if (E.src && E.src.style === 'grid') { E.src.origPicks = E.src.picks.join(','); E.src.origQuery = JSON.stringify({brands: E.src.brands, cats: E.src.cats, sort: E.src.sort, stock: E.src.stock}); }
      E.msg = warn.length ? {warn: true, text: 'Saved — but not: ' + warn.join('; ') + '. Those kept their previous value.'} : {text: 'Saved. The homepage shows it now.'};
      if (!warn.length && window.toast) window.toast(E.sec.label + ' saved');
      draw();
    }).catch(function(e){
      if (!E || E.key !== key) return;
      E.busy = false;
      E.msg = {bad: true, text: 'Not saved: ' + e.message};
      draw();
    });
  }

  /* ------------------------------------------------------------ export */
  window.kbbHomeHub = {
    /** Draw the list into `el`; opts: {openHero(), stale()}. Refetches every time. */
    mount: function(el, opts){
      S.root = el; S.opts = opts || {};
      paint();
      load();
    },
    open: open,
    close: close
  };
})();
