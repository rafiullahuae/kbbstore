
/* ============================================================================
   KBB admin — live wiring (mechanical): connects the adopted kbb-admin.html to
   the real /admin-api. The UI above is untouched; this only feeds it real data
   and persists writes. All functions it overrides are window-level declarations.
   ============================================================================ */
(function(){
  function cookie(name){
    return document.cookie.split('; ').reduce(function(r,c){
      var i=c.indexOf('='); var k=c.slice(0,i);
      return k===name ? decodeURIComponent(c.slice(i+1)) : r;
    },'');
  }
  /**
   * A URL written as '/admin-api/...' assumes the app lives at the domain
   * root. On a subdirectory deployment (the live site runs at
   * easywebsol.com/kbb-upgrade/) that silently resolves to a URL with no
   * matching route at all. Shared by api() and every raw fetch() call in
   * this file that still builds its own URL by hand, so the fix lives in
   * one place rather than being repeated at each call site.
   */
  function fixAdminApiUrl(url){
    if(url.indexOf('/admin-api/')===0){
      return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + url;
    }
    return url;
  }
  /* ══════════════════════════════════════════════════════════════════════════
     THE DOWNLOAD GATE                                             (Lane SEC)
     ══════════════════════════════════════════════════════════════════════════

     Eleven addresses in this console are reached by NAVIGATING the browser at
     them rather than by fetch: the four CSV exports, the bulk-documents page,
     the four order documents, and the two OAuth /start legs. An admin-api
     address that does not carry the secret admin path answers a signed-out
     browser with a plain 404, because the 302 it used to answer with named
     `admin_path` in a Location header to anyone who typed the prefix.

     So a navigation on a dead session used to land on the admin login, where
     the owner signed back in, and now lands on a blank 404. For the four
     `window.location.href` sites that takes THE WHOLE CONSOLE away with it and
     loses the list he was standing on -- strictly worse than what it replaced,
     and the reason this exists.

     ── SO THE BUTTON ASKS FIRST ──────────────────────────────────────────────

     `?probe=1` on the download's OWN address. App\Support\ExportProbe answers
     `{"ok":true}` and nothing else, as the first statement of the action, so no
     query is run and no OAuth state is minted -- and it passes through that
     action's own capability, because AdminCapabilities matches on the route's
     URI and a query string is not part of it. This therefore answers "may THIS
     operator run THIS download", which a shared liveness endpoint could not.
     `/admin-api/health` was considered and rejected: `throttle:6,1` would
     refuse the fourth export in a minute.

     ── AND NOT A BLOB, WHICH IS MEASURED AND NOT ASSERTED ────────────────────

     The obvious repair is to fetch the file through api() and hand over a blob
     URL. The call sites' own comment refuses it -- "the file lands in Downloads
     instead of in memory" -- and all four CSV exports return a StreamedResponse.
     Measured on this tree: at the live shop's 3,025 products the catalogue
     export is 619,968 bytes and 645 ms, and the probe that guards it is 11
     BYTES and 35 ms. The orders export measures 153 bytes per order, which is
     the one that grows without bound as the shop takes orders.

     SO THE HONEST ANSWER IS THAT 0.59 MB IS NOT RUINOUS, and the case for the
     probe does not rest on memory. It rests on CLAUDE.md rule 1: a blob is a
     different behaviour from a streamed download, the comment at every call
     site promises the streamed one, and 35 ms buys leaving a working thing
     exactly as it is.
     ══════════════════════════════════════════════════════════════════════════ */

  /* Where the sign-in page is. Derived off window.location.pathname, the way
     fixAdminApiUrl() already derives the api base -- the console is served AT
     the secret admin path, so the browser is standing on it already and reading
     it back discloses nothing. NEVER from a setting, and never interpolated
     into markup: it is assigned to location.href and nowhere else. */
  function kbbAdminLoginUrl(){
    return window.location.pathname.replace(/\/+$/,'') + '/login';
  }

  /* Ask, and classify. NEVER THROWS, so no caller needs a try. */
  async function kbbProbeDownload(url){
    var r;
    try{
      r = await fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'probe=1',
        {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
    }catch(e){
      /* The request never reached a server. Reported as ITSELF and not as a
         dead session: "sign in again" is the wrong remedy for a dropped
         connection, and it is the one sentence that would send the owner to
         re-type his password over his own wifi. */
      return {verdict:'unreachable', status:0};
    }
    if(r.ok)                                 return {verdict:'ok',        status:r.status};
    if(r.status === 401 || r.status === 419)  return {verdict:'signedout', status:r.status};
    if(r.status === 403)                     return {verdict:'forbidden', status:r.status};
    return {verdict:'other', status:r.status};
  }

  /* What the console says, WITH THE CONSOLE STILL ON SCREEN. Every string here
     is a constant. `opened` is true for the window.open sites, where the tab is
     already up and the sentence has to be about the tab rather than about a
     download that never started. */
  function kbbSayDownloadRefused(answer, opened){
    if(answer.verdict === 'signedout'){
      openModal('<div class="modal-h"><b>Your session has ended</b>' +
        '<button class="x" onclick="closeModal()">✕</button></div>' +
        '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">' +
        (opened
          ? 'You are signed out, so that document could not be opened.'
          : 'You are signed out, so the download was not started — nothing was sent.') +
        '</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">' +
        'Sign in again and this screen comes back exactly as it is, with your ' +
        'filters and ticks where you left them.</p>' +
        '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
        '<button class="btn ghost" onclick="closeModal()">Stay here</button>' +
        '<button class="btn" id="kbbSessionSignIn">Sign in again</button></div></div>');
      var go = document.getElementById('kbbSessionSignIn');
      if(go) go.onclick = function(){ window.location.href = kbbAdminLoginUrl(); };
      return;
    }
    if(answer.verdict === 'forbidden'){
      toast('Your account is not allowed to download this. Nothing was sent.', 'bad');
      return;
    }
    if(answer.verdict === 'unreachable'){
      toast('Could not reach the server, so the download was not started.', 'bad');
      return;
    }
    toast('The server refused that download (' + answer.status + '). Nothing was sent.', 'bad');
  }

  /* AWAIT THIS BEFORE a window.location.href. Nothing is navigated unless the
     answer is yes, which is what keeps the console on the screen. */
  async function kbbDownloadOk(url){
    var answer = await kbbProbeDownload(url);
    if(answer.verdict === 'ok') return true;
    kbbSayDownloadRefused(answer, false);
    return false;
  }

  /* AND THIS AFTER a window.open, which is the shape that cannot wait: a popup
     opened from an async continuation is blocked by the browser, so the tab has
     to be opened inside the click and the question asked behind it. `win` is
     the handle when there is one, and then the dead window is closed rather
     than left for the owner to find; window.open with 'noopener' returns none,
     so the four order documents are told after the fact and no more. */
  function kbbTellIfDownloadRefused(url, win){
    kbbProbeDownload(url).then(function(answer){
      if(answer.verdict === 'ok') return;
      if(win){ try{ win.close(); }catch(e){} }
      kbbSayDownloadRefused(answer, true);
    });
  }

  async function api(url, opts){
    opts = opts || {};
    url = fixAdminApiUrl(url);
    opts.headers = Object.assign({'Accept':'application/json'}, opts.headers||{});
    if(opts.method && opts.method!=='GET'){
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      // FormData bodies (file uploads) must NOT have Content-Type set here —
      // the browser has to generate its own multipart boundary, which it
      // only does when the header is left unset entirely.
      if(!(opts.body instanceof FormData)){
        opts.headers['Content-Type'] = opts.headers['Content-Type'] || 'application/json';
      }
    }
    opts.credentials = 'same-origin';
    var r = await fetch(url, opts);
    if(!r.ok){
      /* The thrown Error keeps exactly the message it always had, so every
         existing catch behaves as before. The status and the parsed body are
         ATTACHED to it, for the callers that can say something better than
         "save failed" — PUT /admin-api/settings refuses a bad value with a
         sentence naming the field, and that sentence used to be thrown away
         here and replaced with "check connection", which sends the owner to
         look at his wifi over a typo in a VAT rate. */
      var err = new Error('api '+url+' -> '+r.status);
      err.status = r.status;
      try{ err.body = await r.json(); }catch(parseFailed){ err.body = null; }
      throw err;
    }
    return r.json();
  }

  /* ---------- Catalog: populate the seed arrays with real data ---------- */
  async function loadCatalog(){
    try{
      var d = await api('/admin-api/products');
      d = d || {}; d.products=d.products||[]; d.categories=d.categories||[]; d.brands=d.brands||[];
      CAT_PRODUCTS.length = 0; CAT_DRAFT.clear();
      d.products.forEach(function(p){
        var sku = p.sku || ('KBB-'+p.id);
        CAT_PRODUCTS.push([p.name, p.brand||'', sku, p.category||'\u2014',
          (p.price_aed==null?0:p.price_aed), (p.sale_aed==null?null:p.sale_aed),
          (p.stock==null?0:p.stock), p.id, p.status, p.slug]);
        // 'publish', not 'active'. products.status is publish|draft|private —
        // there is no 'active' in this schema, so this marked every LIVE
        // product as a draft and left real drafts indistinguishable from them.
        if(p.status!=='publish') CAT_DRAFT.add(sku);
      });
      CAT_CATEGORIES.length = 0; d.categories.forEach(function(c){ CAT_CATEGORIES.push([c.name, c.count]); });
      CAT_BRANDS.length = 0; d.brands.forEach(function(b){ CAT_BRANDS.push([b.name, b.count]); });
      if(cur==='catalog') renderCatalog();
    }catch(e){ console.warn('catalog load failed', e); }
  }

  /* ---------- Dashboard: real KPIs + recent orders ---------- */
  async function hydrateDash(){
    var s = null;
    /* The first paint reads the request the head of this document already
       started; every later hydrate issues its own, exactly as before. */
    if(window.__kbbStatsFirst){
      var first = window.__kbbStatsFirst; window.__kbbStatsFirst = null;
      try{ s = await first; }catch(e){ s = null; }
    }
    if(!s){ try{ s = await api('/admin-api/stats'); }catch(e){ return; } }
    if(!s) return;
    function setKpi(label, val, sub, opts){
      opts = opts || {};
      document.querySelectorAll('#content .kpi').forEach(function(k){
        var l=k.querySelector('.lbl');
        if(l && l.textContent.trim()===label){
          var v=k.querySelector('.val'); if(v) v.textContent=val;
          if(sub!=null){ var su=k.querySelector('.sub'); if(su) su.textContent=sub; }
          /* LANE DU: a tile may need to say what it is measuring, not only what
             the number is. `note` is help text on hover; `rename` changes the
             label AFTER the match, so the match above still keys off the label
             the markup shipped with and a rename cannot make the tile
             unfindable on the next render. */
          if(opts.note){ k.setAttribute('title', opts.note); }
          if(opts.rename){ l.textContent = opts.rename; }
        }
      });
    }
    /* Net of refunds, and the tile says so when any money has gone back. A
       partial refund leaves its order 'completed' (PaymentRefunder only moves
       the status on a FULL refund), so before this the whole of a partly
       refunded order stayed in the revenue figure for ever. */
    /* LANE DU: the figure is what customers were BILLED, less refunds — so if
       the shop ever charges VAT on top of its prices, this tile silently
       includes money collected for the tax authority and handed straight on.
       Today that amount is zero (the tax engine writes tax_total 0 while it is
       in display mode), and the tile therefore says nothing extra; the day it
       stops being zero the tile says so rather than quietly growing. The note
       is rendered from the endpoint's own revenue_basis rather than written
       here, so the screen cannot drift from what the figure actually sums. */
    var rb = s.revenue_basis || {};
    var vat30 = Number(s.tax_collected_30d_aed || 0);
    setKpi('Revenue (30d)', 'AED '+s.revenue_30d_aed.toLocaleString(),
      vat30 > 0
        ? ('includes AED '+vat30.toLocaleString()+' VAT collected for the tax authority')
        : (s.refunds_30d_aed ? ('net of AED '+s.refunds_30d_aed.toLocaleString()+' refunded') : 'last 30 days, net of refunds'),
      { note: rb.note || null, rename: vat30 > 0 ? 'Revenue (30d, incl. VAT)' : null });
    /* "Today" is the shop's own calendar day, not the server's. Storage is
       UTC; App\Support\StoreTime decides which local day a stored instant falls
       in, so an order placed at 01:30 in Dubai counts towards today rather than
       towards yesterday, which is where a UTC day would have filed it. */
    setKpi('Orders', s.orders.toLocaleString(), (s.today? s.today.orders+' today \u00b7 ' : '')+s.paid_orders+' paid');
    setKpi('Customers', s.customers.toLocaleString(), 'total accounts');
    /* Named for what it measures. See the comment on the tile in renderDash():
       this is not a conversion rate and nothing here tracks sessions. */
    var conv = s.orders ? Math.round((s.paid_orders/s.orders)*100) : 0;
    setKpi('Orders completed', conv+'%', s.paid_orders+' of '+s.orders+' reached a real status');

    var banner = document.querySelector('#content .banner div');
    /* Demo rows are excluded from every figure on this screen, so the screen has
       to say so — otherwise switching Demo Content on and off moves the numbers
       and nothing explains why.

       WORDED FROM THE COUNTS, NEVER FROM s.demo.excluded. `excluded` is
       orders + customers + products > 0, and Store -> Demo Content imports one
       type at a time, so an owner who imported demo PRODUCTS alone has
       excluded=true with orders=0 — and this line used to print "0 demo orders
       are excluded from these figures" at him. Measured, and pinned in
       tests/Feature/DemoDisclosureSentenceTest.php.

       Demo REVIEWS get their own sentence because they are a different claim:
       no figure on this screen counts reviews at all. They are kept off the
       STOREFRONT by App\Support\DemoReviews, and that is the thing the owner
       needs told. */
    var dm = (s.demo||{}), demoBits = [], demoTotal = 0, demoNote = '';
    [['orders','order'],['customers','customer'],['products','product']].forEach(function(k){
      var n = Number(dm[k[0]]||0);
      if(!n) return;
      demoTotal += n;
      demoBits.push('<b>'+n.toLocaleString()+'</b> demo '+k[1]+(n===1?'':'s'));
    });
    if(demoBits.length){
      var demoLast = demoBits.pop();
      demoNote += ' '+(demoBits.length? demoBits.join(', ')+' and '+demoLast : demoLast)+
                  (demoTotal===1? ' is' : ' are')+' excluded from these figures.';
    }
    if(Number(dm.reviews||0)){
      demoNote += ' <b>'+Number(dm.reviews).toLocaleString()+'</b> demo review'+
                  (Number(dm.reviews)===1? ' is' : 's are')+' hidden from the storefront.';
    }
    if(banner) banner.innerHTML = 'Live data. <b>'+s.products+'</b> products, <b>'+s.orders+'</b> orders, <b>'+s.customers+'</b> customers. '+(s.low_stock? ('<b>'+s.low_stock+'</b> low on stock.') : 'Stock levels healthy.')+demoNote;

    var cards = Array.prototype.slice.call(document.querySelectorAll('#content .card.pad'));
    var feedCard = cards.filter(function(c){ return /Recent activity/.test(c.textContent); })[0];
    /* LANE DD — the empty case is written out, not skipped. This used to bail
       when the store had no recent orders, and whatever renderDash() had drawn
       stayed on the page under a "live feed" label: three invented events, each
       dated "just now" for ever. A shop with no orders now reads that it has no
       orders. */
    if(feedCard && !(s.recent && s.recent.length)){
      var empty = feedCard.querySelector('div:last-child');
      if(empty) empty.innerHTML = '<p style="font-size:12.5px;color:var(--ink-soft);padding:8px 0">No orders yet.</p>';
    }
    if(feedCard && s.recent && s.recent.length){
      var feed = feedCard.querySelector('div:last-child');
      if(feed) feed.innerHTML = s.recent.map(function(o){
        var dot = o.status==='completed'?'green':(o.status==='cancelled'||o.status==='failed'?'red':'amber');
        return '<div class="row" style="padding:11px 0;border-bottom:1px solid var(--border-2)"><span class="hd '+dot+'" style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span><div><div style="font-size:13px;font-weight:600">Order #'+o.id+' \u00b7 '+sesc(o.customer)+'</div><div style="font-size:11.5px;color:var(--ink-soft)">AED '+o.total_aed.toLocaleString()+' \u00b7 '+sesc(o.status)+'</div></div><small style="margin-left:auto;font-size:11px;color:var(--ink-faint)">'+(o.created_at||'').slice(0,10)+'</small></div>';
      }).join('');
    }
  }

  /* ---------- LANE DH · storefront health check ----------------------------
     The one implementation both screens use. The Dashboard's "Storefront
     health" card and Safety -> Debug & Monitor render the same two ids -
     #shPill and #shRows - and only one of them is ever on screen, so the
     painter needs no notion of which screen it is on. Whichever one you pressed
     the button on, the answer is the same answer.

     IT LIVES HERE, in the live-wiring block, because this is where api() is:
     the one helper that fixes up '/admin-api/...' for the subdirectory the live
     site is deployed into, attaches the CSRF token and hands back the parsed
     error body of a refusal.

     NOTHING POLLS IT. There is no interval and no call on load. One call
     renders eight complete pages inside the worker serving it (276ms cold on
     the SQLite demo catalogue, more on the live shop) and the route carries
     throttle:6,1. It runs when the owner presses the button, and not otherwise.

     A REFUSAL IS PRINTED, NOT GUESSED AT. The route is system.diagnostics -
     owner-only - and EnforceAdminCapability answers a role that does not hold
     it with a JSON body whose `message` names the capability in a sentence. A
     manager pressing Check now reads that sentence. He does not read a green
     tick, which is the whole defect this card was built to remove: a tick that
     means "we did not check" is not a milder version of a lie about health, it
     is the same one. */
  function shEl(id){ return document.getElementById(id); }

  function shPaint(){
    var pill = shEl('shPill'), rows = shEl('shRows');
    if(!pill || !rows) return;                 // the owner moved to another screen
    var h = window.KBB_HEALTH || {};

    function setPill(cls, text){
      pill.className = 'pill ' + cls;
      pill.innerHTML = '<span class="d"></span>' + sesc(text);
    }

    if(h.running){
      setPill('grey', 'Checking…');
      rows.innerHTML = '<p style="font-size:12.5px;color:var(--ink-soft)">Opening every public page…</p>';
      return;
    }

    if(h.error){
      setPill('red', 'Could not check');
      rows.innerHTML = '<p style="font-size:12.5px;color:var(--sale,#c0392b);line-height:1.55">' + sesc(h.error) + '</p>';
      return;
    }

    if(!h.data){ return; }                     // never run: leave the screen's own copy

    var d = h.data;
    if(d.failed) setPill('red', d.failed + ' of ' + d.checked + ' failing');
    else setPill('green', 'All ' + d.checked + ' pages OK');

    var html = '<div class="health">' + (d.results||[]).map(function(r){
      var dot = r.ok ? 'green' : 'red';
      if(r.ok){
        return '<div class="hrow"><span class="hd ' + dot + '"></span><b>' + sesc(r.label) +
               '</b><small>HTTP ' + sesc(r.status) + '</small></div>';
      }
      /* A failure spans both columns and carries what the check actually
         learned: the exception message, and the file and line in this
         application that raised it. That pair is the whole reason the endpoint
         exists, and it is what gets pasted into the report. */
      var detail = '<div style="flex-basis:100%;margin-left:19px;font-size:11.5px;color:var(--ink-soft);line-height:1.5;word-break:break-word">' +
        sesc(r.path) +
        (r.error ? '<br>' + sesc(r.error) : '') +
        (r.where ? '<br><code style="font-size:11px">' + sesc(r.where) + '</code>' : '') +
        '</div>';
      return '<div class="hrow" style="grid-column:1/-1;flex-wrap:wrap;align-items:flex-start">' +
             '<span class="hd ' + dot + '" style="margin-top:3px"></span><b>' + sesc(r.label) +
             '</b><small>HTTP ' + sesc(r.status) + '</small>' + detail + '</div>';
    }).join('') + '</div>';

    html += '<p style="font-size:11.5px;color:var(--ink-faint);margin-top:11px;line-height:1.5">Checked ' +
      sesc(h.at ? h.at.toLocaleTimeString() : 'just now') +
      '. This is what those pages returned at that moment — it is a check you ran, not a monitor that keeps watching.</p>';

    rows.innerHTML = html;
  }

  async function kbbHealthRun(){
    var btn = shEl('shRun');
    if(btn && btn.disabled) return;            // already in flight
    if(btn){ btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Checking…'; }

    window.KBB_HEALTH = {running:true, at:null, data:null, error:null};
    shPaint();

    var next = {running:false, at:new Date(), data:null, error:null};
    try{
      next.data = await api('/admin-api/health');
    }catch(e){
      /* Each of these is a different thing to tell the owner, and "could not
         check" on its own sends him to look at his wifi over a permission. */
      if(e.body && e.body.message)      next.error = e.body.message;
      else if(e.status === 429)         next.error = 'That is a lot of checks in one minute. Each one opens eight pages of your shop, so it is rate-limited — wait a moment and press it again.';
      else if(e.status === 404)         next.error = 'The health check is not installed on this server yet. It arrives with the next update package.';
      else if(e.status === 419)         next.error = 'Your session has expired. Reload this page and sign in again.';
      else                              next.error = 'The check could not be run: ' + (e.message || 'no answer from the server') + '.';
    }
    window.KBB_HEALTH = next;

    if(btn){ btn.disabled = false; btn.textContent = btn.dataset.label || 'Check again'; }
    shPaint();
  }

  window.kbbHealthRun = kbbHealthRun;

  /* ---------- Orders screen (new; built from the admin's own tokens) ---------- */
  var ORDER_STATUSES=['draft','pending','processing','onhold','shipped','completed','cancelled','refunded','failed'];
  function statusPill(s){
    var m={completed:'green',processing:'amber',onhold:'amber',shipped:'blue',pending:'grey',draft:'grey',cancelled:'red',refunded:'red',failed:'red'}[s]||'grey';
    return '<span class="pill '+m+'"><span class="d"></span>'+s+'</span>';
  }
  /**
   * Content -> Blog Posts.
   *
   * WHAT WAS HERE. This function drew the whole screen: a read-only table whose
   * own subtitle said "Editing happens on the real page for now", over a "real
   * page" that did not exist. POST /admin-api/posts answered 405 and no
   * endpoint in this application wrote posts.body at all, so an owner who had
   * written an article had nowhere to put it and the Journal served only what
   * the WordPress import or the demo seeder had left behind.
   *
   * The list and the article editor behind it now live together in
   * admin/partials/post-editor-screen.blade.php (Lane J), included at the foot
   * of this file. This stays as the entry point, because the interception above
   * calls it by name for both 'blog' and 'posts', and it delegates rather than
   * drawing a second table that would have to be kept in step with the first.
   */
  function renderPosts(){
    if (window.KBBPostEditor) return KBBPostEditor.list();

    /* The partial is included at the very end of this file, so it is defined
       long before any click can reach here. If it somehow is not, say so
       rather than drawing a silent blank screen. */
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Blog Posts</h2></div>'
      + '<p style="padding:24px;color:var(--sale,#c0392b)">The Blog Posts screen did not load.</p></div>';
  }

  /* ===== LANE V · Store · Orders — BEGIN =====================================

     WHAT WAS HERE BEFORE. renderOrders() fetched /admin-api/orders, which
     returns EVERY order the store has ever taken in one array, kept it in a
     module-level ORD variable, counted the filter chips by running
     Array.filter over it once per status, and paginated not at all. Against
     the 2,419 orders the WooCommerce import brings across that is one very
     large response per visit and a chip count that is only ever as right as
     whatever the browser happens to be holding.

     Everything is now asked of the server: one page of rows, the chip counts,
     the summary and the sort all come back from /admin-api/orders-list for the
     filters currently on screen. The money in the summary is computed in SQL
     over the filtered set, never by adding up the rows on this page.

     WHAT THIS DOES NOT TOUCH. renderOrderDetail() below, its capture panel and
     its refund form belong to other lanes and already work. The View button
     here opens that screen. Nothing in this region defines a second way to look
     at an order, and nothing in it moves money.

     THE 390px DEFECT. The old table was 607px wide inside a 342px card on a
     390px phone, clipped mid-column with no indication there was more. It now
     lives in .odlscroll, which is overflow-x:auto and max-width:100% — the
     table scrolls inside the card and contributes nothing to the width of the
     page. The KPI grid uses minmax(0,1fr) rather than 1fr for the same reason:
     a grid track defaults to min-width:auto, so a long money figure like
     "AED 1,245,300" widens the track past its share and pushes the page out.
     Measured in real Chromium at 390 and 1280; #content reports
     scrollWidth === clientWidth at both.

     BLANKS ARE EXPECTED, NOT EXCEPTIONAL. An imported order can have no
     customer row, no phone, no city, no payment method and a date from 2019.
     Every cell falls back to an em dash rather than printing "undefined".

     Everything the public typed — billing names, emails, phones, cities — goes
     through sesc() before it reaches innerHTML.
  */

  (function odlStyles(){
    if(document.getElementById('odlcss')) return;

    /* Injected rather than added to the stylesheet at the top of this file:
       that block is shared by every screen and several lanes are editing this
       view at once. A style element this region owns outright cannot collide
       with somebody else's rule. */
    var s = document.createElement('style');
    s.id = 'odlcss';
    s.textContent =
      '.odlkpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}' +
      '@media(max-width:900px){.odlkpis{grid-template-columns:repeat(2,minmax(0,1fr))}}' +
      '@media(max-width:430px){.odlkpis{grid-template-columns:minmax(0,1fr)}}' +
      '.odlkpi{min-width:0;overflow-wrap:anywhere}' +
      '.odlkpi .v{font-size:21px;font-weight:700;margin-top:6px;line-height:1.15}' +
      '.odlkpi .k{font-size:11px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em}' +
      '.odlkpi .s{font-size:11.5px;color:var(--ink-soft);margin-top:2px}' +
      /* The whole point of the fix: a wide table scrolls in here, never on the
         page. max-width:100% stops a min-width table stretching the card. */
      '.odlscroll{max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}' +
      '.odlscroll table{min-width:880px}' +
      '.odlhint{display:none;font-size:11.5px;color:var(--ink-soft);padding:10px 14px 0}' +
      '@media(max-width:900px){.odlhint{display:block}}' +
      '.odltools{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}' +
      '.odltools .search{flex:1 1 200px;min-width:0}' +
      '.odltools .inp{max-width:100%}' +
      '.odlgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:12px}' +
      '.odlbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}' +
      '.odlpager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;' +
        'padding:13px 4px 2px;font-size:12.5px;color:var(--ink-soft)}' +
      '.odlnum{font-variant-numeric:tabular-nums;white-space:nowrap}' +
      '.odlwarn{border-color:#f0dcae;background:var(--amber-soft)}' +
      /* Lane ORD. The whole row opens the order: a pointer and a tint, both
         paint-only, so nothing moves by a pixel. The order number is a real
         link (Ctrl/Cmd-click, middle-click) that reads exactly as the text it
         replaced. */
      '.olrow{cursor:pointer}' +
      '.olrow:hover td,.olrow:focus-visible td{background:rgba(127,127,127,.06)}' +
      '.olrow:focus-visible{outline:2px solid var(--accent-ink);outline-offset:-2px}' +
      '.ollink{color:inherit;text-decoration:none}' +
      '.ollink:hover{text-decoration:underline}' +
      '.olgo{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap}' +
      '.olgo-t{font-size:12.5px;color:var(--ink-2)}' +
      '.olres{font-size:12.5px;color:var(--ink-2)}';

    document.head.appendChild(s);
  })();

  var OL = {
    page: 1,
    perPage: +(localStorage.getItem('kbb_ord_pp') || 50),
    search: '', filter: 'all', sort: 'newest',
    from: '', to: '', totalMin: '', totalMax: '', payment: '', source: '',
    adv: false, colsOpen: false, cols: null, data: null, err: null, sel: {}, busy: false,
    /* Lane ORD: the status picked in "Set status to…" and waiting for Proceed,
       the request in flight, and the one-line answer drawn where the bar was. */
    pend: '', applying: false, result: null
  };

  var OL_COLDEF = [
    ['status', 'Status'], ['items', 'Items'], ['total', 'Total'], ['refunded', 'Refunded'],
    ['payment', 'Payment'], ['source', 'Source'], ['placed', 'Placed'], ['location', 'City'],
    ['contact', 'Phone'], ['wc', 'Woo ID']
  ];

  /* Refunded, City, Phone and the Woo ID are off by default and one click away
     in Columns. Most orders have no refund, and the six that are on already
     fill a 1032px content area; Woo's own screen shows more and pays for it on
     every page load. */
  var OL_COLS_DEFAULT = {
    status: true, items: true, total: true, refunded: false,
    payment: true, source: true, placed: true, location: false, contact: false, wc: false
  };

  /* Which sort each sortable header maps to, so the header caret and the Sort
     menu can never disagree about what the list is ordered by. */
  var OL_COLSORT = { items: 'units_desc', total: 'total_desc', placed: 'newest', status: 'status' };

  var OL_SORTS = [
    ['newest', 'Newest first'], ['oldest', 'Oldest first'], ['number', 'Order number'],
    ['total_desc', 'Value, high to low'], ['total_asc', 'Value, low to high'],
    ['units_desc', 'Most items'], ['status', 'Status'], ['customer', 'Customer A–Z']
  ];

  /* Bands in whole dirhams; the server converts with Money::fromMajor, so the
     comparison happens in fils and nothing here ever holds a money float. */
  var OL_BANDS = [
    ['', '', 'Any value'], ['', '99', 'Under AED 100'], ['100', '499', 'AED 100 – 499'],
    ['500', '1999', 'AED 500 – 1,999'], ['2000', '', 'AED 2,000 and over']
  ];

  /* Statuses a bulk action may set. Mirrors OrdersApiController::BULK_SETTABLE
     — and 'refunded' is absent from both, because that status is written when
     money actually goes back and a dropdown must not be able to claim it did. */
  var OL_SETTABLE = [
    ['processing', 'Processing'], ['onhold', 'On hold'], ['shipped', 'Shipped'],
    ['completed', 'Completed'], ['pending', 'Pending'], ['cancelled', 'Cancelled']
  ];

  function olCols(){
    if(OL.cols) return OL.cols;
    var saved = null;
    try{ saved = JSON.parse(localStorage.getItem('kbb_ord_cols') || 'null'); }catch(e){ saved = null; }
    OL.cols = Object.assign({}, OL_COLS_DEFAULT, saved || {});
    return OL.cols;
  }
  function olSaveCols(){ try{ localStorage.setItem('kbb_ord_cols', JSON.stringify(OL.cols)); }catch(e){} }

  function olParams(forExport){
    var p = new URLSearchParams();
    if(!forExport){ p.set('page', OL.page); p.set('per_page', OL.perPage); }
    if(OL.search) p.set('search', OL.search);
    if(OL.filter && OL.filter !== 'all') p.set('filter', OL.filter);
    if(OL.sort && OL.sort !== 'newest') p.set('sort', OL.sort);
    if(OL.from) p.set('from', OL.from);
    if(OL.to) p.set('to', OL.to);
    if(OL.totalMin !== '') p.set('total_min', OL.totalMin);
    if(OL.totalMax !== '') p.set('total_max', OL.totalMax);
    if(OL.payment) p.set('payment', OL.payment);
    if(OL.source) p.set('source', OL.source);
    return p.toString();
  }

  function olDash(v){ return (v === null || v === undefined || v === '') ? '<span style="color:var(--ink-faint)">—</span>' : sesc(v); }

  function olDate(iso){
    if(!iso) return '<span style="color:var(--ink-faint)">—</span>';
    var d = new Date(iso);
    if(isNaN(d)) return '<span style="color:var(--ink-faint)">—</span>';
    return sesc(d.toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'}));
  }

  /* "3 days ago" under the date. A shop owner reads recency faster than a date,
     and an order imported from 2019 should look like it. */
  function olAgo(iso){
    if(!iso) return '';
    var d = new Date(iso); if(isNaN(d)) return '';
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if(days < 0) return '';
    if(days === 0) return 'today';
    if(days === 1) return 'yesterday';
    if(days < 31) return days + ' days ago';
    if(days < 365){ var m = Math.max(1, Math.round(days / 30)); return m + (m === 1 ? ' month ago' : ' months ago'); }
    var years = Math.floor(days / 365);
    return (years < 2 ? 'over a year ago' : years + ' years ago');
  }

  /* How this application's own statuses read on a chip. A status NOT in here
     came out of the import, and it is shown verbatim: "wc-tamara-p-failed" is
     the key the operator will search WooCommerce for, and prettifying it into
     "Wc tamara p failed" throws that away for nothing. */
  var OL_LABELS = {
    draft: 'Draft', pending: 'Pending', processing: 'Processing', onhold: 'On hold',
    shipped: 'Shipped', completed: 'Completed', cancelled: 'Cancelled',
    refunded: 'Refunded', failed: 'Failed'
  };
  function olTitle(s){ return OL_LABELS[s] || s; }
  function olLabel(o){ return o.customer_name || o.email || ('Order ' + o.order_number); }

  async function olLoad(){
    if(OL.busy) return;
    OL.busy = true;
    OL.result = null; OL.pend = '';
    try{
      OL.data = await api('/admin-api/orders-list?' + olParams(false));
      OL.perPage = OL.data.per_page;
      OL.err = null;
    }catch(e){
      OL.data = null;
      /* Say WHAT failed, not what might have. Fetch the same URL again plainly
         so the status and the server's own message can be shown, rather than
         guessing at a cause and sending whoever reads it to the wrong place. */
      OL.err = {status:0, body:''};
      try{
        var probe = await fetch(fixAdminApiUrl('/admin-api/orders-list?' + olParams(false)),
          {credentials:'same-origin', headers:{'Accept':'application/json'}});
        OL.err.status = probe.status;
        OL.err.body = (await probe.text() || '').slice(0, 400);
      }catch(e2){
        OL.err.body = String(e2 && e2.message || e);
      }
    }
    OL.busy = false;
    olPaint();
  }

  async function renderOrders(){
    OL.page = 1; OL.sel = {};
    /* Back on the list, the address is the list's again (Lane ORD): an order
       opened from `#orders/<id>` must not reopen on the next refresh. */
    try{ if(/^#orders\//.test(location.hash)) history.replaceState(null, '', location.pathname + location.search + '#orders'); }catch(e){}
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Orders</h2>' +
      '<p>Every order the store has taken, including guest and imported ones.</p></div>' +
      '<p style="padding:24px;color:var(--ink-soft)">Loading orders…</p></div>';
    await olLoad();
  }

  /* Turn the HTTP status into the thing to actually go and check. */
  function olWhy(){
    var st = OL.err ? OL.err.status : 0;
    if(st === 404) return 'The server returned 404 — this build’s routes are not live yet. The compiled route cache needs clearing (Store → Core Updates does this on every apply).';
    if(st === 401 || st === 403) return 'The server returned ' + st + ' — the admin session was refused. Sign out and back in.';
    if(st === 419) return 'The server returned 419 — the admin session expired. Reload the page.';
    if(st === 500) return 'The server returned 500 — the request reached the code and the code threw. The exception is in storage/logs/laravel.log; the text below is what the server sent back.';
    if(st === 0)   return 'The request never completed — the browser could not reach the server at all.';
    return 'The server returned ' + st + '. The text below is what it sent back.';
  }

  function olKpi(label, value, sub){
    return '<div class="card pad odlkpi"><div class="k">' + sesc(label) + '</div>' +
      '<div class="v odlnum">' + value + '</div><div class="s">' + sesc(sub || '') + '</div></div>';
  }

  function olAdvCount(){
    var n = 0;
    if(OL.from || OL.to) n++;
    if(OL.totalMin !== '' || OL.totalMax !== '') n++;
    if(OL.payment) n++;
    if(OL.source) n++;
    return n;
  }

  /* All, Paid, every status actually present, Trash. A status nobody has is not
     a chip — but an imported one nobody planned for (wc-tamara-p-failed) is,
     because the server builds the list from the column rather than a constant. */
  function olChips(d){
    var counts = d.counts || {};
    var chips = [['all', 'All'], ['paid', 'Counts as revenue']];

    (d.statuses || []).forEach(function(s){
      if(counts[s]) chips.push([s, olTitle(s)]);
    });

    chips.push(['trashed', 'Trash']);

    return chips.map(function(c){
      var n = counts[c[0]] || 0;
      return '<button class="chip' + (OL.filter === c[0] ? ' on' : '') + '" data-olf="' + sesc(c[0]) + '">' +
        sesc(c[1]) + ' <span style="opacity:.6">' + n + '</span></button>';
    }).join('');
  }

  function olPaint(){
    var el = document.querySelector('#content');
    var d = OL.data;

    if(!d){
      el.innerHTML = '<div class="wrap"><div class="page-head"><h2>Orders</h2></div>' +
        '<div class="card pad"><p style="font-size:13px;color:var(--red)">Orders could not be loaded.</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:6px">' + olWhy() + '</p>' +
        (OL.err && OL.err.body ? '<pre style="margin-top:10px;padding:10px;background:var(--bg-soft,#f6f6f7);border-radius:8px;font-size:11.5px;white-space:pre-wrap;word-break:break-word;max-height:220px;overflow:auto">' + sesc(OL.err.body) + '</pre>' : '') +
        '<div style="margin-top:12px"><button class="btn ghost sm" id="olRetry">Try again</button></div></div></div>';
      var retry = document.getElementById('olRetry');
      if(retry) retry.onclick = function(){ olLoad(); };
      return;
    }

    var cols = OL_COLDEF.filter(function(c){ return olCols()[c[0]]; });
    var selected = Object.keys(OL.sel).filter(function(k){ return OL.sel[k]; });
    var s = d.summary || {};
    if(!selected.length) OL.pend = '';

    el.innerHTML =
      '<div class="wrap">' +
      '<div class="between" style="margin-bottom:8px;flex-wrap:wrap;gap:12px">' +
        '<div class="page-head" style="margin:0"><h2>Orders</h2>' +
        '<p>Every order the store has taken, including guest and imported ones. Revenue counts processing, on-hold, shipped and completed orders, with refunds taken off.</p></div>' +
        '<div class="row" style="gap:8px;flex-wrap:wrap">' +
          '<button class="btn ghost" id="olColsBtn">' + ic('<path d="M4 6h16M7 12h10M10 18h4"/>') + ' Columns</button>' +
          '<button class="btn" id="olExport">' + ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>') + ' Export CSV</button>' +
        '</div>' +
      '</div>' +

      '<div class="odlkpis">' +
        olKpi('Orders in this view', (s.orders || 0).toLocaleString(),
          s.paid_orders === s.orders ? 'all count as revenue' : (s.paid_orders || 0) + ' count as revenue') +
        olKpi('Revenue', sesc(s.revenue_display || ''), 'after refunds') +
        olKpi('Refunded', sesc(s.refunded_display || ''), 'across this view') +
        olKpi('Average order', sesc(s.aov_display || ''), 'across the revenue orders') +
      '</div>' +

      (OL.colsOpen ? olColsPanel() : '') +

      '<div class="odltools">' +
        '<div class="search">' + ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>') +
        '<input id="olSearch" placeholder="Search order number, name, email, phone or Woo ID…" value="' + sesc(OL.search) + '"></div>' +
        '<select class="inp" id="olSort" style="max-width:220px">' +
          OL_SORTS.map(function(o){ return '<option value="' + o[0] + '"' + (OL.sort === o[0] ? ' selected' : '') + '>Sort: ' + o[1] + '</option>'; }).join('') +
        '</select>' +
        '<button class="btn ghost" id="olAdv">' + ic('<path d="M4 6h16M7 12h10M10 18h4"/>') + ' Filters' + (olAdvCount() ? ' · ' + olAdvCount() : '') + (OL.adv ? ' ▴' : ' ▾') + '</button>' +
      '</div>' +

      (OL.adv ? olAdvPanel(d) : '') +

      '<div class="chips" style="margin-bottom:12px">' + olChips(d) + '</div>' +

      (selected.length ? olSelectionBar(selected) : olResultLine()) +

      '<div class="card">' +
        '<p class="odlhint">This table is wider than the screen — swipe it sideways to see every column, or hide the ones you do not need with Columns.</p>' +
        '<div class="odlscroll">' + olTable(d, cols) + '</div>' +
      '</div>' +

      '<div class="odlpager">' +
        '<span>' + (d.orders.length ? ((d.page - 1) * d.per_page + 1) : 0) + '–' +
        ((d.page - 1) * d.per_page + d.orders.length) + ' of ' + d.total + '</span>' +
        '<div class="row" style="gap:8px;flex-wrap:wrap">' +
          '<select class="inp" id="olPerPage" style="width:126px">' +
            [25, 50, 100, 200].map(function(n){ return '<option value="' + n + '"' + (n === OL.perPage ? ' selected' : '') + '>' + n + ' per page</option>'; }).join('') +
          '</select>' +
          '<button class="btn ghost sm" ' + (d.page <= 1 ? 'disabled' : '') + ' id="olPrev">‹ Prev</button>' +
          '<span style="font-size:12px">Page ' + d.page + ' of ' + d.last_page + '</span>' +
          '<button class="btn ghost sm" ' + (d.page >= d.last_page ? 'disabled' : '') + ' id="olNext">Next ›</button>' +
        '</div>' +
      '</div></div>';

    olBind();
  }

  function olSelectionBar(selected){
    var trashView = OL.filter === 'trashed';

    return '<div class="card pad odlbar" style="margin-bottom:12px">' +
      '<b style="font-size:12.5px">' + selected.length + ' selected</b>' +
      '<button class="btn ghost sm" id="olClearSel">Clear</button>' +
      '<div style="flex:1"></div>' +
      /* BULK DOCUMENTS (Lane GC). Before the trashView ternary on purpose, so
         it is offered in the trash view too: a soft-deleted order is still an
         order that was placed and may have been paid for, and the server prints
         one withTrashed() exactly as the single documents do. */
      '<select class="inp" id="olBulkPrint" style="width:auto;min-width:150px">' +
        '<option value="">Print&hellip;</option>' +
        OL_DOCS.map(function(d){ return '<option value="' + d[0] + '">' + d[1] + '</option>'; }).join('') +
      '</select>' +
      (trashView
        ? '<button class="btn sm" id="olBulkRestore">Restore</button>'
        : '<select class="inp" id="olBulkStatus" style="width:auto;min-width:150px">' +
            '<option value="">Set status to…</option>' +
            OL_SETTABLE.map(function(o){ return '<option value="' + o[0] + '"' + (OL.pend === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
          '</select>' +
          (OL.pend ? olProceedGroup(selected.length) : '') +
          '<button class="btn sm" style="background:var(--red)" id="olBulkDelete">Move to trash…</button>') +
      '</div>';
  }

  /* Lane ORD. "Set status to…" no longer applies anything on its own: the
     choice waits here, beside the select, until Proceed. The line says what
     will happen in full -- how many, to what, and whether the customers get
     an email -- because that sentence is the whole of the confirmation. */
  function olStatusLabel(status){
    return (OL_SETTABLE.filter(function(o){ return o[0] === status; })[0] || [status, olTitle(status)])[1];
  }

  function olEmails(status){
    var m = OL.data && OL.data.status_emails;
    return !!(m && m[status]);
  }

  function olProceedGroup(n){
    return '<span class="olgo" role="group" aria-label="Confirm the status change">' +
      '<span class="olgo-t" id="olProceedText">Set <b>' + n + '</b> order' + (n === 1 ? '' : 's') + ' to <b>' + sesc(olStatusLabel(OL.pend)) + '</b>' +
        (olEmails(OL.pend) ? ' · customers will be emailed' : '') + '</span>' +
      '<button class="btn sm" id="olProceed"' + (OL.applying ? ' disabled' : '') + '>' + (OL.applying ? 'Applying…' : 'Proceed') + '</button>' +
      '<button class="btn ghost sm" id="olProceedNo">Cancel</button>' +
    '</span>';
  }

  /* What the last Proceed did, where the bar was. Gone on the next filter,
     page or selection, or with its own ✕. */
  function olResultLine(){
    if(!OL.result) return '';
    return '<div class="card pad odlbar olres" role="status" id="olResult">' +
      '<span>' + ic(I.check) + '</span><span style="flex:1">' + sesc(OL.result) + '</span>' +
      '<button class="btn ghost sm" id="olResultX" aria-label="Dismiss">✕</button></div>';
  }

  function olColsPanel(){
    return '<div class="card pad" style="margin-bottom:14px">' +
      '<b style="font-size:12.5px">Columns</b>' +
      '<div style="display:flex;flex-wrap:wrap;gap:12px 20px;margin-top:11px">' +
      OL_COLDEF.map(function(c){
        return '<label class="row" style="gap:8px;font-size:12.5px;cursor:pointer">' +
          '<span class="cbx' + (olCols()[c[0]] ? ' on' : '') + '" data-olcol="' + c[0] + '">' + ic(I.check) + '</span> ' + sesc(c[1]) + '</label>';
      }).join('') +
      '</div><div style="margin-top:14px"><button class="btn ghost sm" id="olColsReset">Reset to default</button></div></div>';
  }

  function olAdvPanel(d){
    var band = OL_BANDS.filter(function(b){ return b[0] === OL.totalMin && b[1] === OL.totalMax; })[0];

    /* The payment methods that actually appear in this view, so the filter
       cannot offer a gateway the store has never taken money through. */
    var methods = {};
    (d.orders || []).forEach(function(o){ if(o.payment_method) methods[o.payment_method] = o.payment; });
    if(OL.payment && !methods[OL.payment]) methods[OL.payment] = OL.payment;

    return '<div class="card pad" style="margin-bottom:12px">' +
      '<div class="odlgrid">' +
        '<div class="fld" style="margin:0"><label>Order value</label><select id="olBand">' +
          OL_BANDS.map(function(b){ return '<option value="' + b[0] + '|' + b[1] + '"' + (band && band[2] === b[2] ? ' selected' : '') + '>' + sesc(b[2]) + '</option>'; }).join('') +
          (band ? '' : '<option value="custom" selected>Custom range</option>') +
        '</select></div>' +
        '<div class="fld" style="margin:0"><label>Value from (AED)</label><input id="olMin" type="number" min="0" step="1" value="' + sesc(OL.totalMin) + '" placeholder="any"></div>' +
        '<div class="fld" style="margin:0"><label>Value to (AED)</label><input id="olMax" type="number" min="0" step="1" value="' + sesc(OL.totalMax) + '" placeholder="any"></div>' +
        '<div class="fld" style="margin:0"><label>Placed from</label><input id="olFrom" type="date" value="' + sesc(OL.from) + '"></div>' +
        '<div class="fld" style="margin:0"><label>Placed to</label><input id="olTo" type="date" value="' + sesc(OL.to) + '"></div>' +
        '<div class="fld" style="margin:0"><label>Payment</label><select id="olPayment"><option value="">Any payment method</option>' +
          Object.keys(methods).sort().map(function(k){ return '<option value="' + sesc(k) + '"' + (OL.payment === k ? ' selected' : '') + '>' + sesc(methods[k]) + '</option>'; }).join('') +
        '</select></div>' +
        /* Source (Lane AN): where the order came from, from analytics. */
        '<div class="fld" style="margin:0"><label>Source</label><select id="olSource"><option value="">Any source</option>' +
          Object.keys(d.sources || {}).map(function(k){ return '<option value="' + sesc(k) + '"' + (OL.source === k ? ' selected' : '') + '>' + sesc(d.sources[k]) + '</option>'; }).join('') +
        '</select></div>' +
      '</div>' +
      '<div class="row" style="margin-top:14px;gap:8px;flex-wrap:wrap"><button class="btn sm" id="olApply">Apply filters</button>' +
      '<button class="btn ghost sm" id="olClearFilters">Clear all</button>' +
      '<span style="font-size:11.5px;color:var(--ink-soft)">Dates are when the order was placed, so imported orders sort by their original date.</span></div></div>';
  }

  function olTable(d, cols){
    if(!d.orders.length){
      return '<p style="padding:34px;text-align:center;color:var(--ink-soft);font-size:13px">No orders match this view.' +
        (olAdvCount() || OL.search || OL.filter !== 'all' ? ' <button class="btn ghost sm" id="olEmptyClear" style="margin-left:8px">Clear filters</button>' : '') + '</p>';
    }

    var allOnPage = d.orders.every(function(o){ return OL.sel[o.id]; });

    var head = '<thead><tr>' +
      '<th style="width:36px"><span class="cbx' + (allOnPage ? ' on' : '') + '" id="olAll">' + ic(I.check) + '</span></th>' +
      '<th>' + olHeadSort('number', 'Order') + '</th>' +
      '<th>' + olHeadSort('customer', 'Customer') + '</th>' +
      cols.map(function(c){
        var right = ['items', 'total', 'refunded'].indexOf(c[0]) >= 0;
        var inner = OL_COLSORT[c[0]] ? olHeadSort(OL_COLSORT[c[0]], c[1]) : sesc(c[1]);
        return '<th style="white-space:nowrap' + (right ? ';text-align:right' : '') + '">' + inner + '</th>';
      }).join('') +
      '<th></th></tr></thead>';

    var body = '<tbody>' + d.orders.map(function(o){
      /* Lane ORD: the row is the target. data-olrow carries the id for the one
         delegated handler on the table, and tabindex makes the row the single
         keyboard stop (Enter opens it); the link inside is the real address
         for a new tab, and is skipped by Tab so a row is not two stops. */
      return '<tr class="olrow" data-olrow="' + o.id + '" tabindex="0"' + (o.trashed ? ' style="opacity:.62"' : '') + '>' +
        '<td><span class="cbx' + (OL.sel[o.id] ? ' on' : '') + '" data-olsel="' + o.id + '">' + ic(I.check) + '</span></td>' +
        '<td style="white-space:nowrap"><div class="pname"><a class="ollink" href="' + sesc(olHref(o.id)) + '" data-olopen="' + o.id + '" tabindex="-1">' + sesc(o.order_number) + '</a></div>' +
          '<div class="pbrand">' +
            (o.wc_order_id ? 'Woo #' + o.wc_order_id : '#' + o.id) +
            (o.trashed ? ' · <span style="color:var(--red)">in the trash</span>' : '') +
            /* Demo orders stay on this list — showing them is what the Demo
               Content feature is for — but they are excluded from every money
               figure on the Dashboard and in Analytics, so the row has to admit
               which it is. */
            (o.is_demo ? ' · <span style="color:var(--ink-faint)">demo</span>' : '') +
          '</div></td>' +
        '<td><div class="row" style="min-width:0">' +
          '<span class="pthumb" style="background:' + sesc(tcol(olLabel(o))) + ';width:32px;height:32px;font-size:10px">' + sesc(initials(olLabel(o))) + '</span>' +
          '<div style="min-width:0"><div class="pname" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px">' +
            (o.customer_name ? sesc(o.customer_name) : '<span style="color:var(--ink-faint)">No name on record</span>') +
            (o.guest ? ' <span class="pill grey">Guest</span>' : '') + '</div>' +
          '<div class="pbrand" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px">' + olDash(o.email) + '</div></div></div></td>' +
        cols.map(function(col){ return olCell(col[0], o); }).join('') +
        '<td style="white-space:nowrap">' +
          '<button class="btn ghost sm" data-olview="' + o.id + '">View</button></td>' +
      '</tr>';
    }).join('') + '</tbody>';

    return '<table>' + head + body + '</table>';
  }

  function olHeadSort(sort, label){
    var on = OL.sort === sort;
    return '<button data-olsort="' + sesc(sort) + '" style="font:inherit;color:inherit;text-transform:inherit;letter-spacing:inherit;' +
      (on ? 'color:var(--accent-ink)' : '') + '">' + sesc(label) + (on ? ' ▾' : '') + '</button>';
  }

  function olCell(key, o){
    switch(key){
      case 'status':
        return '<td style="white-space:nowrap">' + statusPill(o.status) +
          (o.counts_as_revenue ? '' : '<div class="pbrand">not revenue</div>') + '</td>';
      case 'items':
        return '<td class="odlnum" style="text-align:right">' + o.units +
          (o.lines !== o.units ? '<div class="pbrand">' + o.lines + ' line' + (o.lines === 1 ? '' : 's') + '</div>' : '') + '</td>';
      case 'total':
        return '<td class="price odlnum" style="text-align:right"><b>' + sesc(o.total_display) + '</b>' +
          (o.refunded_fils ? '<div class="pbrand">' + sesc(o.net_display) + ' net</div>' : '') + '</td>';
      case 'refunded':
        return '<td class="odlnum" style="text-align:right;color:' + (o.refunded_fils ? 'var(--red)' : 'var(--ink-faint)') + '">' +
          (o.refunded_fils ? sesc(o.refunded_display) : '—') + '</td>';
      case 'source':
        /* Lane AN: "Instagram Ads · eid_sale"; Unknown for orders before analytics, imports and manual ones. */
        return '<td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + sesc(o.source || 'Unknown') + '">' +
          '<span class="chip" style="cursor:default;font-size:11.5px;padding:2px 8px;display:inline-block;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle' + (o.source_key ? '' : ';opacity:.6') + '">' + sesc(o.source || 'Unknown') + '</span></td>';
      case 'payment':
        return '<td style="font-size:12px">' + olDash(o.payment) +
          (o.captured ? '<div class="pbrand">captured</div>' : '') + '</td>';
      case 'placed':
        return '<td style="white-space:nowrap;font-size:12px">' + olDate(o.placed_at) +
          (o.placed_at ? '<div class="pbrand">' + sesc(olAgo(o.placed_at)) + '</div>' : '') + '</td>';
      case 'location':
        return '<td style="white-space:nowrap;font-size:12px">' + olDash(o.city) +
          (o.country ? '<div class="pbrand">' + sesc(o.country) + '</div>' : '') + '</td>';
      case 'contact':
        return '<td style="white-space:nowrap;font-size:12px">' + olDash(o.phone) + '</td>';
      case 'wc':
        return '<td style="white-space:nowrap;font-family:var(--mono);font-size:11px;color:var(--ink-soft)">' + olDash(o.wc_order_id) + '</td>';
      default:
        return '<td></td>';
    }
  }


  /* -------- bulk documents: many orders, one printable page (Lane GC) -------- */

  /* The four documents, in the order a packing bench wants them: the two that
     go on and in the parcel first, the invoice last because it is the one that
     issues a number. */
  var OL_DOCS = [
    ['packing-slip', 'Packing slips'],
    ['dispatch-label', 'Dispatch labels'],
    ['delivery-note', 'Delivery notes'],
    ['invoice', 'Invoices']
  ];

  /* Services\Invoices\BulkDocumentSelection::MAX. Checked here as well as on
     the server so the operator is told in a dialog rather than in a new tab
     that turns out to hold a refusal. The server is still the one that decides:
     it refuses over the cap before it loads an order or issues a number. */
  var OL_DOC_MAX = 100;

  /* A NEW TAB, NOT A FETCH. The page is a document for the browser to print,
     the browser carries the same admin session cookie, and leaving the Orders
     screen behind means the operator's ticks are still there when they come
     back for the next document. Same reasoning as the Export button below.

     window.open MUST HAPPEN IN THE CLICK. A browser blocks a popup opened from
     an async continuation, so this is called straight from the change handler,
     or straight from the confirm button's own click - never after an await. */
  function olPrintDocs(ids, type){
    if(!ids.length) return;

    /* Gated AFTER THE FACT, and that is the honest shape here. (Lane SEC)
       The comment above is the constraint: window.open has to happen inside
       the click, so nothing can be awaited in front of it. The tab opens,
       and the console -- which is still on the screen behind it -- is told
       whether it was ever going to work. 'noopener' returns no handle, so
       the dead tab cannot be closed from here and the modal says so. */
    var url = fixAdminApiUrl('/admin-api/orders-bulk-documents') +
      '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(',');

    window.open(url, '_blank', 'noopener');
    kbbTellIfDownloadRefused(url);
  }

  function olDocLabel(type){
    return (OL_DOCS.filter(function(d){ return d[0] === type; })[0] || [type, type])[1];
  }

  /**
   * Three of the four just print. The invoice asks first.
   *
   * Not because printing is dangerous, but because an invoice number is: the
   * server issues one to every selected order that has none, out of a sequence
   * an accountant reconciles, and nothing in this admin can take one back. The
   * other three documents allocate nothing at all and open straight away -
   * asking about a picking list would only train the operator to click through
   * the dialog that matters.
   */
  function olConfirmDocs(ids, type){
    if(!ids.length) return;

    if(ids.length > OL_DOC_MAX){
      openModal('<div class="modal-h"><b>Too many at once</b><button class="x" onclick="closeModal()">&#10005;</button></div>' +
        '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">You picked <b>' + ids.length +
        '</b> orders, and one document holds at most <b>' + OL_DOC_MAX + '</b>.</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Nothing has been printed and no invoice numbers have been issued. ' +
        'Print them in batches of ' + OL_DOC_MAX + ' or fewer - your ticks are still here.</p>' +
        '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
        '<button class="btn" onclick="closeModal()">Close</button></div></div>');
      return;
    }

    if(type !== 'invoice'){ olPrintDocs(ids, type); return; }

    openModal('<div class="modal-h"><b>Print invoices</b><button class="x" onclick="closeModal()">&#10005;</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Print invoices for <b>' + ids.length +
      '</b> order' + (ids.length === 1 ? '' : 's') + '?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Any of them that has never been invoiced is given its invoice number now, ' +
      'exactly as opening one invoice from the order screen does. That cannot be undone from here, so pick the orders you are really invoicing. ' +
      'Orders that already have a number keep it.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" id="olDocsYes">Print invoices</button></div></div>');

    var yes = document.getElementById('olDocsYes');
    if(yes) yes.onclick = function(){ closeModal(); olPrintDocs(ids, 'invoice'); };
  }

  /* ▲ THIS FUNCTION WAS MISSING (found by Lane ORD in Chromium). Every
     control in the bulk bar -- Set status, Print, Move to trash, Restore --
     calls it, and nothing defined it: the first pick threw "olSelectedIds is
     not defined" and the bar did nothing at all. Lane V's original, restored. */
  function olSelectedIds(){
    return Object.keys(OL.sel).filter(function(k){ return OL.sel[k]; }).map(Number);
  }

  function olBind(){
    var $$$ = function(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); };
    var byId = function(id){ return document.getElementById(id); };

    var searchT;
    var searchEl = byId('olSearch');
    if(searchEl) searchEl.oninput = function(e){
      clearTimeout(searchT);
      var v = e.target.value;
      searchT = setTimeout(function(){ OL.search = v; OL.page = 1; olLoad(); }, 300);
    };

    var sortEl = byId('olSort');
    if(sortEl) sortEl.onchange = function(e){ OL.sort = e.target.value; OL.page = 1; olLoad(); };

    $$$('#content [data-olsort]').forEach(function(b){
      b.onclick = function(){ OL.sort = b.dataset.olsort; OL.page = 1; olLoad(); };
    });

    $$$('#content .chip[data-olf]').forEach(function(b){
      b.onclick = function(){ OL.filter = b.dataset.olf; OL.page = 1; OL.sel = {}; olLoad(); };
    });

    var adv = byId('olAdv');
    if(adv) adv.onclick = function(){ OL.adv = !OL.adv; olPaint(); };

    var colsBtn = byId('olColsBtn');
    if(colsBtn) colsBtn.onclick = function(){ OL.colsOpen = !OL.colsOpen; olPaint(); };

    $$$('#content .cbx[data-olcol]').forEach(function(b){
      b.onclick = function(){ var k = b.dataset.olcol; OL.cols[k] = !OL.cols[k]; olSaveCols(); olPaint(); };
    });
    var colsReset = byId('olColsReset');
    if(colsReset) colsReset.onclick = function(){ OL.cols = Object.assign({}, OL_COLS_DEFAULT); olSaveCols(); olPaint(); };

    var band = byId('olBand');
    if(band) band.onchange = function(e){
      if(e.target.value === 'custom') return;
      var parts = e.target.value.split('|');
      OL.totalMin = parts[0]; OL.totalMax = parts[1]; OL.page = 1; olLoad();
    };

    var apply = byId('olApply');
    if(apply) apply.onclick = function(){
      OL.totalMin = (byId('olMin') || {}).value || '';
      OL.totalMax = (byId('olMax') || {}).value || '';
      OL.from = (byId('olFrom') || {}).value || '';
      OL.to = (byId('olTo') || {}).value || '';
      OL.payment = (byId('olPayment') || {}).value || '';
      OL.source = (byId('olSource') || {}).value || '';
      OL.page = 1; olLoad();
    };

    var clearAll = function(){
      OL.totalMin = ''; OL.totalMax = ''; OL.from = ''; OL.to = '';
      OL.payment = ''; OL.source = ''; OL.search = ''; OL.filter = 'all';
      OL.page = 1; olLoad();
    };
    var clearBtn = byId('olClearFilters'); if(clearBtn) clearBtn.onclick = clearAll;
    var emptyClear = byId('olEmptyClear'); if(emptyClear) emptyClear.onclick = clearAll;

    var perPage = byId('olPerPage');
    if(perPage) perPage.onchange = function(e){
      OL.perPage = +e.target.value;
      try{ localStorage.setItem('kbb_ord_pp', OL.perPage); }catch(err){}
      OL.page = 1; olLoad();
    };

    var prev = byId('olPrev'); if(prev) prev.onclick = function(){ if(OL.data.page > 1){ OL.page = OL.data.page - 1; olLoad(); } };
    var next = byId('olNext'); if(next) next.onclick = function(){ if(OL.data.page < OL.data.last_page){ OL.page = OL.data.page + 1; olLoad(); } };

    $$$('#content [data-olsel]').forEach(function(b){
      b.onclick = function(){ var id = b.dataset.olsel; OL.sel[id] = !OL.sel[id]; olPaint(); };
    });
    var all = byId('olAll');
    if(all) all.onclick = function(){
      var on = !OL.data.orders.every(function(o){ return OL.sel[o.id]; });
      OL.data.orders.forEach(function(o){ OL.sel[o.id] = on; });
      olPaint();
    };
    var clearSel = byId('olClearSel'); if(clearSel) clearSel.onclick = function(){ OL.sel = {}; olPaint(); };

    /* Lane ORD: picking a status ARMS it; only Proceed sends anything. */
    var bulkStatus = byId('olBulkStatus');
    if(bulkStatus) bulkStatus.onchange = function(e){
      olConfirmStatus(olSelectedIds(), e.target.value);
    };
    var proceed = byId('olProceed');
    if(proceed) proceed.onclick = function(){
      if(OL.pend) olRunStatus(olSelectedIds(), OL.pend, false);
    };
    var proceedNo = byId('olProceedNo');
    if(proceedNo) proceedNo.onclick = function(){ OL.pend = ''; olPaint(); };
    var resultX = byId('olResultX');
    if(resultX) resultX.onclick = function(){ OL.result = null; olPaint(); };

    /* THE WHOLE ROW OPENS THE ORDER (Lane ORD). One delegated listener per
       paint, on the table, rather than one per row. */
    var tbl = document.querySelector('#content .odlscroll table');
    if(tbl){
      tbl.onclick = olRowClick;
      tbl.onauxclick = olRowClick;
      tbl.onkeydown = olRowKey;
    }

    var bulkPrint = byId('olBulkPrint');
    if(bulkPrint) bulkPrint.onchange = function(e){
      var type = e.target.value;
      /* Reset first: the select is a menu, not a setting, and leaving it on
         "Invoices" would make the next Print look like it had already been
         chosen. Also lets the same document be picked twice in a row. */
      e.target.value = '';
      if(type) olConfirmDocs(olSelectedIds(), type);
    };

    /* ▲ "Move to trash…" WAS DRAWN AND BOUND TO NOTHING. Its three siblings in
       the same bulk bar are bound just above and just below this line; this one
       never was, so the button rendered, took a click and did nothing at all.
       olConfirmDelete() and olRunDelete() both already existed and were
       reachable only from the second "some were skipped" dialog, which a
       shopper's orders can only reach by going through the first one -- so the
       whole delete path was live and had no way in. Found by Lane QA sweeping
       every id-bearing button for something that references its id. */
    var bulkDelete = byId('olBulkDelete');
    if(bulkDelete) bulkDelete.onclick = function(){ olConfirmDelete(olSelectedIds()); };

    var bulkRestore = byId('olBulkRestore');
    if(bulkRestore) bulkRestore.onclick = async function(){
      var ids = olSelectedIds();
      if(!ids.length) return;
      try{
        var out = await api('/admin-api/orders-bulk-restore', {method:'POST', body: JSON.stringify({ids: ids})});
        toast(out.restored + ' order' + (out.restored === 1 ? '' : 's') + ' restored');
        OL.sel = {}; olLoad();
      }catch(e){ toast('Could not restore those orders','bad'); }
    };

    /* The detail screen that already exists. This list does not define a second
       one, and the capture and refund panels on it are another lane's work. */
    $$$('#content [data-olview]').forEach(function(b){
      b.onclick = function(){ renderOrderDetail(+b.dataset.olview); };
    });

    var exportBtn = byId('olExport');
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = olParams(true);
      var url = fixAdminApiUrl('/admin-api/orders-export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
  }

  /* -------- the row is the link (Lane ORD) -------- */

  /* The address an order opens at: this console, deep-linked. A new tab boots
     the console and LANE DA's replay hands `orders/<id>` to window.go, which
     opens the order rather than the list. location.pathname, never the whole
     href, so a stale ?go= cannot ride along into the new tab. */
  function olHref(id){ return location.pathname + '#orders/' + (+id); }

  /* Anything in a row that is a control of its own keeps its own click: the
     tick box, a button (View, the sort headers), a select, an input. The
     order-number link is the one exception, handled below. */
  var OL_OWN_CLICK = 'button,select,input,textarea,label,.cbx,[data-olsel],a:not([data-olopen])';

  function olRowClick(e){
    var tr = e.target.closest && e.target.closest('tr[data-olrow]');
    if(!tr) return;
    if(e.target.closest(OL_OWN_CLICK)) return;

    var id = +tr.dataset.olrow;
    var link = e.target.closest('a[data-olopen]');
    var newTab = e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1;

    /* Only the primary and middle buttons. A right click is the context menu. */
    if(e.button !== 0 && e.button !== 1) return;

    /* On the link itself the browser already knows how to open a new tab --
       Ctrl/Cmd-click, Shift-click, middle-click, "Open in new tab". */
    if(link && newTab) return;

    /* A drag that selected text is somebody copying a name or an email, not
       asking to leave. The click that ends a drag-select arrives with the
       selection still in place; a plain click has already collapsed it. */
    var picked = window.getSelection ? String(window.getSelection()) : '';
    if(picked && tr.contains(window.getSelection().anchorNode)) return;

    e.preventDefault();
    if(newTab){ window.open(olHref(id), '_blank', 'noopener'); return; }
    olOpen(id);
  }

  function olRowKey(e){
    if(e.key !== 'Enter' || !e.target.matches || !e.target.matches('tr[data-olrow]')) return;
    e.preventDefault();
    var id = +e.target.dataset.olrow;
    if(e.ctrlKey || e.metaKey){ window.open(olHref(id), '_blank', 'noopener'); return; }
    olOpen(id);
  }

  function olOpen(id){ renderOrderDetail(id); }

  /* -------- destructive actions: always a dialog, sometimes two -------- */

  /**
   * Nothing changes on a click. Choosing a status arms it beside the select
   * with a Proceed button and a sentence saying what Proceed will do (Lane
   * ORD -- the owner: "the statuses should ask me confirmation beside the
   * statuses selection with a button 'Proceed'"). The server then refuses any
   * order that counts as revenue and reports which ones and what they are
   * worth, and only a second, explicit confirmation carrying force goes
   * through.
   */
  function olConfirmStatus(ids, status){
    OL.pend = ids.length && OL_SETTABLE.some(function(o){ return o[0] === status; }) ? status : '';
    olPaint();
    var go = document.getElementById('olProceed');
    if(go) go.focus();
  }

  /**
   * One request for the whole selection; the server walks it in chunks
   * through the per-order funnel. The rows are then redrawn IN PLACE from the
   * answer -- no reload, no "Loading orders…", the scroll stays where it was
   * -- and the chip counts and money tiles are refreshed behind it.
   */
  async function olRunStatus(ids, status, force){
    closeModal();
    if(!ids.length || OL.applying) return;
    OL.applying = true;
    var go = document.getElementById('olProceed');
    if(go){ go.disabled = true; go.textContent = 'Applying…'; }

    var out;
    try{
      out = await api('/admin-api/orders-bulk-status', {
        method: 'POST', body: JSON.stringify({ids: ids, status: status, force: !!force})
      });
    }catch(e){
      OL.applying = false;
      if(go){ go.disabled = false; go.textContent = 'Proceed'; }
      toast(e && e.status === 403 ? 'Your role cannot change order statuses' : 'Could not complete that — nothing was changed', 'bad');
      return;
    }
    OL.applying = false;

    /* An older server answers without changed_ids: fall back to the reload
       this screen always did, rather than guess which rows moved. */
    if(!Array.isArray(out.changed_ids)){
      OL.sel = {}; OL.pend = '';
      await olLoad();
      OL.result = olResultText(out, status, force);
      olPaint();
    } else {
      var moved = {};
      out.changed_ids.forEach(function(id){ moved[id] = true; });
      ((OL.data && OL.data.orders) || []).forEach(function(o){
        if(moved[o.id]){ o.status = status; o.counts_as_revenue = !!out.revenue; }
      });
      OL.sel = {}; OL.pend = ''; OL.result = olResultText(out, status, force);
      olPaint();
      olRefreshCounts();
    }

    if(out.skipped && out.skipped.length) olConfirmSkipped(out, status, 'status');
  }

  /* "12 updated, 1 skipped: already Completed". Every order the owner ticked
     is accounted for in the one line. */
  function olResultText(out, status, force){
    var label = olStatusLabel(status);
    var parts = [(out.changed || 0) + (force ? ' more' : '') + ' updated'];
    var same = (out.unchanged_ids || []).length;
    var skipped = out.skipped || [];
    var revenue = skipped.filter(function(s){ return s.forceable !== false; }).length;
    var refused = skipped.length - revenue;
    if(same) parts.push(same + ' skipped: already ' + label);
    if(revenue) parts.push(revenue + ' left alone: count' + (revenue === 1 ? 's' : '') + ' as revenue');
    if(refused) parts.push(refused + ' refused: stock or coupon no longer available');
    return parts.join(', ') + (out.changed && olEmails(status) ? '. Customers are being emailed.' : '');
  }

  /* The chips and the money tiles, after a change: the same request the
     screen loads with, but only its counts and summary are taken, so the rows
     just redrawn in place stay as the owner is looking at them. */
  async function olRefreshCounts(){
    var qs = olParams(false);
    try{
      var d = await api('/admin-api/orders-list?' + qs);
      if(!OL.data || qs !== olParams(false) || !document.getElementById('olSearch')) return;
      OL.data.counts = d.counts; OL.data.summary = d.summary; OL.data.statuses = d.statuses;
      var a = document.activeElement;
      if(a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName) && document.querySelector('#content').contains(a)) return;
      olPaint();
    }catch(e){ /* the rows are right; the counts catch up on the next load */ }
  }

  function olConfirmDelete(ids){
    if(!ids.length) return;

    openModal('<div class="modal-h"><b>Move to trash</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Move <b>' + ids.length + '</b> order' + (ids.length === 1 ? '' : 's') + ' to the trash?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Nothing is destroyed. The orders and their line items stay in the database, they leave this list, and the Trash filter restores them at any time. Orders that count as revenue are left alone unless you confirm them separately.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" style="background:var(--red)" id="olDelYes">Move to trash</button></div></div>');

    var yes = document.getElementById('olDelYes');
    if(yes) yes.onclick = function(){ olRunDelete(ids, false); };
  }

  async function olRunDelete(ids, force){
    closeModal();
    try{
      var out = await api('/admin-api/orders-bulk-delete', {
        method: 'POST', body: JSON.stringify({ids: ids, force: !!force})
      });

      if(out.skipped && out.skipped.length){ olConfirmSkipped(out, null, 'delete'); return; }

      toast(out.deleted + ' order' + (out.deleted === 1 ? '' : 's') + ' moved to trash');
      OL.sel = {}; olLoad();
    }catch(e){
      toast('Could not complete that — nothing was changed', 'bad');
    }
  }

  /**
   * The second dialog. The server has already done the safe half and is telling
   * the operator exactly which orders it refused and what they are worth, by
   * order number rather than by id.
   */
  function olConfirmSkipped(out, status, kind){
    var done = kind === 'delete' ? out.deleted : out.changed;

    /* TWO KINDS OF SKIP NOW, AND THEY MUST NOT BE RUN TOGETHER.
       A revenue skip is a question only the owner can settle, and `force` is his
       answer to it. A revive refusal is the shop saying the arithmetic does not
       work — the jar has been sold, the code is spent — and `force` is not an
       answer to that: the server refuses it either way, so offering the button
       would be offering one whose only possible outcome is a second refusal.
       An entry written before `forceable` existed carries no flag, and a missing
       flag keeps the old meaning: forceable. */
    var forceable = out.skipped.filter(function(s){ return s.forceable !== false; });
    var refused   = out.skipped.filter(function(s){ return s.forceable === false; });

    function olSkipLine(s){
      return '<li>' + sesc(s.label) + ' — ' +
        (s.reason ? sesc(s.reason) : sesc(s.status) + ', ' + sesc(s.total_display)) + '</li>';
    }

    openModal('<div class="modal-h"><b>' + (refused.length ? 'Some of these could not be changed' : 'Some of these count as revenue') + '</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)"><b>' + done + '</b> ' +
      (kind === 'delete' ? 'moved to trash' : 'updated') + '. <b>' + out.skipped.length + '</b> left alone.</p>' +
      (refused.length
        ? '<p style="font-size:12.5px;color:var(--ink-2);margin-top:10px"><b>' + refused.length + '</b> ' +
          (refused.length === 1 ? 'was' : 'were') + ' refused — bringing ' + (refused.length === 1 ? 'it' : 'them') +
          ' back would need stock or a coupon use the shop no longer has:</p>' +
          '<ul style="font-size:12.5px;color:var(--ink-2);margin:8px 0 0 18px">' +
          refused.slice(0, 12).map(olSkipLine).join('') +
          (refused.length > 12 ? '<li>and ' + (refused.length - 12) + ' more</li>' : '') + '</ul>'
        : '') +
      (forceable.length
        ? '<p style="font-size:12.5px;color:var(--ink-2);margin-top:10px"><b>' + forceable.length + '</b> left alone because ' +
          (forceable.length === 1 ? 'it counts' : 'they count') + ' as revenue:</p>' +
          '<ul style="font-size:12.5px;color:var(--ink-2);margin:8px 0 0 18px">' +
          forceable.slice(0, 12).map(olSkipLine).join('') +
          (forceable.length > 12 ? '<li>and ' + (forceable.length - 12) + ' more</li>' : '') + '</ul>' +
          '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:10px">Going ahead takes their value out of the store’s revenue figures. Refunds are not affected either way — money only moves from the order screen.</p>'
        : '') +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">' + (forceable.length ? 'Leave them' : 'Close') + '</button>' +
      (forceable.length
        ? '<button class="btn" style="background:var(--red)" id="olForce">' + (kind === 'delete' ? 'Trash those too' : 'Change those too') + '</button>'
        : '') + '</div></div>');

    var force = document.getElementById('olForce');
    if(force) force.onclick = function(){
      /* The forceable ones only. Sending the refused ids back would ask the
         server to refuse them a second time. */
      var ids = forceable.map(function(s){ return s.id; });
      if(kind === 'delete') olRunDelete(ids, true); else olRunStatus(ids, status, true);
    };
  }

  /* ===== LANE V · Store · Orders — END ===== */

  /* The "add a product" type-ahead on the order detail screen, built once and
     re-attached whenever the screen is redrawn. See wireOrderDetail below. */
  var odPicker = null, odPickerOrder = null;

  /** Put one of the picked products on the order that is open. */
  async function odAddItem(productId){
    try{
      var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+odPickerOrder+'/items'),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
        body:JSON.stringify({product_id:parseInt(productId,10), quantity:1})});
      var j = await r.json();
      if(!r.ok || j.ok===false){ toast(j.message||'Could not add that product.', 'bad'); return; }
      toast('Product added'); renderOrderDetail(odPickerOrder);
    }catch(e){ toast('Could not add that product.','bad'); }
  }

  /**
   * The detailed order page, built from Rafi's own WooCommerce reference
   * screenshot. Card-stack layout, every section open by default (his
   * choice) rather than collapsed — the ^v▲ controls just toggle a
   * section shut for anyone who wants to tidy the page, they don't start
   * that way. Talks to AdminOrderController, which already existed fully
   * built and tested by the time this page was written — this is the
   * missing other half, not a rebuild of that work.
   */
  async function renderOrderDetail(id){
    document.querySelector('#content').innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading order…</p></div>';
    var o;
    try{ o = await api('/admin-api/orders/'+id+'/detail'); }
    catch(e){ document.querySelector('#content').innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--sale)">Could not load this order.</p></div>'; return; }

    /* (Lane PU) "Paid on" only when the payment panel below agrees the order
       IS paid. WooCommerce stamps date_paid on a cash-on-delivery order the
       moment it reaches Processing, so an imported COD order read "Paid on
       29 Sep" here directly above an amber "AED 337 to collect". */
    var payState = (o.payment && o.payment.state) || '';
    var paidSaid = o.paid_at && (!o.payment || payState === 'paid' || payState === 'refunded');
    var paidLine = o.payment_method_title
      ? 'Payment via '+sesc(o.payment_method_title)+'.'+(o.transaction_id?' ('+sesc(o.transaction_id)+').':'')+(paidSaid?' Paid on '+fmtDT(o.paid_at)+'.':'')+(o.ip_address?' Customer IP: '+sesc(o.ip_address)+'.':'')
      : 'No payment recorded yet.';

    document.querySelector('#content').innerHTML =
      '<div class="wrap">'+
      '<p style="margin-bottom:10px"><a href="#" id="ordBack" style="font-size:12.5px;color:var(--pink-deep,#c0392b);text-decoration:none">\u2190 Back to Orders</a></p>'+
      '<div class="page-head" style="margin-bottom:4px"><h2>Order #'+sesc(o.order_number)+'</h2></div>'+
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px">'+paidLine+'</p>'+
      '<div class="odgrid">'+
      '<div class="odmain">'+odOverviewAddressesCard(o)+odCustomerNoteCard(o)+odItemsCard(o)+odJourneyCard(o)+odEmailsCard(o)+odNotesCard(o)+'</div>'+
      '<div class="odside">'+odAttributionCard(o)+odActionsCard(o)+odHistoryCard(o)+odInvoiceCard(o)+'</div>'+
      '</div></div>';

    document.getElementById('ordBack').onclick = function(e){ e.preventDefault(); renderOrders(); };
    wireOrderDetail(o);
  }

  function odCardHead(title){
    return '<div class="odcardhead"><b>'+title+'</b><div class="odchev">'+
      '<span class="odtoggle" data-odsec="1">'+ic('<path d="M18 15l-6-6-6 6"/>')+'</span>'+
      '<span class="odtoggle">'+ic('<path d="M6 9l6 6 6-6"/>')+'</span></div></div>';
  }

  /*
   * What the shopper wrote, as opposed to odNotesCard which is the internal
   * thread staff add to. Two different audiences, so two different cards --
   * a gift message read as an internal note is how the wrong words end up
   * on a card in the box.
   *
   * customer_note has been on the orders table and in this endpoint's payload
   * since the beginning, but nothing captured it at checkout and nothing
   * rendered it here. Both halves land together.
   */
  function odCustomerNoteCard(o){
    var note = (o.customer_note||'').trim();
    var gift = (o.gift_note||'').trim();
    if(!note && !gift && !o.is_gift) return '';
    var body = '';
    if(o.is_gift){
      body += '<div class="odgiftrow"><span class="odgiftflag">Gift order</span>'+
        (o.gift_fee_aed>0?'<span class="odgiftfee">AED '+o.gift_fee_aed+' charged</span>':'<span class="odgiftfee">No gift-wrap charge</span>')+
        '</div>';
    }
    if(gift){
      body += '<div class="odgiftmsg"><b>Message for the gift card</b><p>'+sesc(gift).replace(/\n/g,'<br>')+'</p></div>';
    }
    if(note){
      body += '<div class="odcustnote"><b>Delivery notes from the customer</b><p>'+sesc(note).replace(/\n/g,'<br>')+'</p></div>';
    }
    return '<div class="odcard">'+odCardHead('Customer note')+'<div class="odcardbody">'+body+'</div></div>';
  }

  /*
    "Email the customer about this change", beside the status dropdown.

    WHAT IT IS FOR. The store's standing rule says which statuses email a
    customer at all (Store → Mail → order status emails). This is the exception
    to that rule, for the order in front of you: untick it and this one save
    stays quiet; tick it and this one save sends even though the rule is off.
    It is never stored — it travels with the status change as `notify` and is
    gone with the response.

    IT RE-TICKS ITSELF WHEN THE STATUS CHANGES, which is the whole reason this
    is a function and not a static string. Pre-ticking it once at render time
    would leave "email the customer" ticked while the operator moved the
    dropdown from Shipped (which emails) to Processing (which never does), and
    the tick would be a promise the store cannot keep. odNotifySync() below is
    wired to the dropdown's own change event.

    A STATUS WITH NO MESSAGE DISABLES IT AND SAYS WHY. There is no customer
    email for Processing, Completed, Refunded and the rest — OrderStatusChanged
    carries wording for Shipped and Cancelled only, and the reasons are the
    server's, printed here rather than invented in the browser. A tick box that
    silently does nothing is the fault this project has already shipped three
    times; a disabled one that explains itself is not that.

    Wording and markup follow the New Order screen's "Email the customer a
    confirmation" box — checkbox, label, small print underneath — so the two
    places an operator decides about email look like each other.
  */
  function odStatusEmailRow(o, status){
    var rows = o.status_emails || [];
    for(var i=0;i<rows.length;i++){ if(rows[i].status===status) return rows[i]; }
    return {status:status, supported:false, enabled:false, reason:''};
  }

  function odNotifyFieldHTML(o){
    var row = odStatusEmailRow(o, o.status);
    return '<div class="odfld" id="odNotifyFld">'+
      '<label style="display:flex;align-items:flex-start;gap:7px;cursor:pointer;font-weight:500">'+
        '<input type="checkbox" id="odNotify" style="margin-top:2px"'+
          (row.supported && row.enabled ? ' checked' : '')+
          (row.supported ? '' : ' disabled')+'>'+
        '<span>Email the customer about this change'+
          '<small id="odNotifyWhy" style="display:block;color:var(--ink-faint);font-weight:400;line-height:1.45">'+
            sesc(odNotifyReason(row))+
          '</small>'+
        '</span>'+
      '</label>'+
    '</div>';
  }

  function odNotifyReason(row){
    if(!row.supported) return row.reason || 'There is no customer email for this status.';
    return row.enabled
      ? 'On by default for this status. Untick to change the status quietly, just this once.'
      : 'Switched off for this status in Store → Mail. Tick to send it anyway, just this once.';
  }

  /* Keep the box honest as the dropdown moves. See odNotifyFieldHTML. */
  function odNotifySync(o){
    var sel = document.getElementById('odStatusSel');
    var box = document.getElementById('odNotify');
    var why = document.getElementById('odNotifyWhy');
    if(!sel || !box || !why) return;
    var apply = function(){
      var row = odStatusEmailRow(o, sel.value);
      box.disabled = !row.supported;
      box.checked = row.supported && row.enabled;
      why.textContent = odNotifyReason(row);
    };
    sel.addEventListener('change', apply);
  }

  /*
   * WHEN THIS WAS THREE INPUT BOXES.
   *
   * "Date created" rendered a date box and two time boxes, pre-filled from the
   * order, and NOTHING read them back: there was no handler, no request and no
   * endpoint -- AdminOrderController has no method that accepts a created date,
   * and no route posts one. Typing in them and navigating away lost the edit
   * silently, and the screen gave the operator every reason to believe it had
   * been saved. That is the same defect as a figure that states something the
   * code does not do, which is the class of bug this panel keeps turning up.
   *
   * It is printed rather than wired, deliberately. An order's created_at is not
   * a cosmetic label: it is the bucket key for the 14-day chart, the dashboard
   * and Analytics windows, and the "placed between" filter on the Orders list.
   * Letting it be retyped would let an operator move money between reporting
   * periods and quietly restate figures the owner has already read as settled,
   * so it is a write path that needs validation, an audit note and a permission
   * before it needs an input box -- and nobody has asked for the ability. The
   * honest small change is to stop claiming to offer it.
   *
   * Formatted from the string's own characters and NOT via `new Date(...)`.
   * StoreTime::iso() already emits this instant on the SHOP's clock with its
   * offset attached, so slicing reads the shop's wall clock exactly; handing it
   * to the browser's Date would re-render it in the VIEWER's timezone, which
   * puts the display back on a different clock from the rest of the screen --
   * the very bug App\Support\StoreTime exists to close.
   */
  var OD_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  function odShopDateTime(iso){
    var s = String(iso||'');
    if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) return '\u2014';
    var mo = OD_MONTHS[parseInt(s.slice(5,7),10)-1];
    if(!mo) return '\u2014';
    return sesc(String(parseInt(s.slice(8,10),10))+' '+mo+' '+s.slice(0,4)+' at '+s.slice(11,16));
  }

  /*
   * (Lane PU) THE ADDRESSES, IN THE SHAPE EVERY WRITER STORES.
   *
   * This printed `a.name` and `a.emirate`. Nothing in the shop writes either:
   * the checkout, New Order and the WooCommerce import all store first_name /
   * last_name / company / line1 / line2 / city / state / postcode / country /
   * phone (App\Support\OrderAddress). So the shipping name was blank and the
   * emirate missing on every order. odAddrName() still reads a legacy `name`
   * for the demo rows that carry one.
   */
  function odAddrName(a){
    a = a || {};
    return [a.first_name, a.last_name].filter(Boolean).join(' ') || a.name || '';
  }

  function odAddrLines(a, countries){
    a = a || {};
    var city = a.city || '', state = a.state || a.emirate || '';
    // "Dubai, Dubai" is what city + emirate gives in most of the UAE.
    var place = (state && String(state).toLowerCase() !== String(city).toLowerCase()) ? [city, state].filter(Boolean).join(', ') : city;
    var country = a.country ? ((countries && countries[a.country]) || a.country) : '';
    return [a.company, a.line1, a.line2, place, a.postcode, country].filter(Boolean).map(sesc).join('<br>');
  }

  function odOverviewAddressesCard(o){
    var c = o.customer||{};
    var b = o.billing_address||{}, s = o.shipping_address||{};
    var can = o.can||{};
    var countries = (o.address_form||{}).countries || {};
    /* "Edit" is drawn only for an admin the server will let save, and it is a
       BUTTON: the link it replaces was <a href="#"> with no handler, so a click
       did nothing but clear the URL's hash. */
    var editBtn = function(type){
      return can.edit ? '<button type="button" class="odlinkbtn" data-odedit="'+type+'" aria-label="Edit '+type+' details">Edit</button>' : '';
    };
    /* Order history works for a guest too -- matched by the order's email, the
       way the Customer history card on the right already counts one. */
    var histLink = (c.id || o.email) ? ' &middot; <button type="button" class="odlinkbtn" id="odCustHist">Order history</button>' : '';
    var changeLink = can.customer ? '<button type="button" class="odlinkbtn" id="odCustChange">Change</button>' : '';
    return '<div class="odcard" style="margin-bottom:16px" id="odGeneral">'+
      '<div class="odcols3">'+
      '<div class="odcolcell">'+
        '<div class="odcollabel">GENERAL</div>'+
        '<div class="odfld"><label>Date created</label>'+
        '<div class="odreadonly">'+odShopDateTime(o.created_at)+'</div></div>'+
        '<div class="odfld"><label>Status</label>'+seoSel2('odStatusSel', o.status, ORDER_STATUSES.map(function(s){return [s, s.charAt(0).toUpperCase()+s.slice(1)];}))+'</div>'+
        odNotifyFieldHTML(o)+
        '<div class="odfld" style="margin-bottom:0"><label>Customer'+histLink+'</label>'+
        (c.id ? '<div class="odcustchip"><span>'+sesc(c.name||c.email)+'</span>'+changeLink+'</div>' : '<div class="odcustchip"><span style="color:var(--ink-faint)">Guest checkout</span>'+changeLink+'</div>')+
        '</div>'+
      '</div>'+
      '<div class="odcolcell odcolmid">'+
        '<div class="odcollabel">BILLING '+editBtn('billing')+'</div>'+
        '<div class="odaddr"><span class="odname">'+sesc(odAddrName(b)||c.name||'')+'</span><br>'+(odAddrLines(b, countries)||'—')+'</div>'+
        '<div class="odfld" style="margin-top:14px;margin-bottom:0"><label>Email address</label>'+(o.email?'<a href="mailto:'+sesc(o.email)+'" style="font-size:11.5px">'+sesc(o.email)+'</a>':'—')+'</div>'+
        '<div class="odfld" style="margin-top:10px;margin-bottom:0"><label>Phone</label>'+(o.phone?'<a href="tel:'+sesc(o.phone)+'" style="font-size:11.5px">'+sesc(o.phone)+'</a>':'—')+'</div>'+
      '</div>'+
      '<div class="odcolcell">'+
        '<div class="odcollabel">SHIPPING '+editBtn('shipping')+'</div>'+
        '<div class="odaddr" id="odShipView"><span class="odname">'+sesc(odAddrName(s))+'</span><br>'+(odAddrLines(s, countries)||'—')+'</div>'+
        '<div class="odfld" style="margin-top:14px;margin-bottom:0"><label>Phone</label>'+((s.phone||o.phone)?'<a href="tel:'+sesc(s.phone||o.phone)+'" style="font-size:11.5px">'+sesc(s.phone||o.phone)+'</a>':'—')+'</div>'+
      '</div>'+
      '</div></div>';
  }

  /*
   * The line item's photograph, and a real placeholder when there is none.
   *
   * `image` on a line item is the LIVE product's image (AdminOrderController::
   * show reads $item->product?->image) -- order_items snapshots the name, the
   * brand, the SKU and the price but not the picture. So it is legitimately
   * empty for a line whose product has no photograph or has since been deleted,
   * and that is precisely the case that drew an empty grey square. The tinted
   * initials are the same square the product picker draws, from the same
   * tint(), so the row and the suggestion that created it match.
   */
  function odItemThumb(it){
    var label = it.brand || it.name || '?';
    var mark = String(it.name||'?').trim().split(/\s+/).map(function(w){return w[0]||'';}).join('').slice(0,2).toUpperCase() || '?';
    var tint = window.kbbProductPickerTint ? window.kbbProductPickerTint(label) : 'var(--surface-2)';

    return '<div class="odthumb" style="background:'+sesc(tint)+'" data-mark="'+sesc(mark)+'">'+
      (it.image?'<img src="'+sesc(it.image)+'" alt="" loading="lazy">':sesc(mark))+'</div>';
  }

  /* What is in a Set, on an order line, for the TWO admin surfaces that list
     order items -- the order-detail screen and the quick-view modal.

     ONE FUNCTION AND NOT TWO COPIES. Lane SE named the order-detail line and
     wired six order documents; the modal has the same gap and a different
     payload shape (qty/unit_aed/line_aed rather than quantity/unit_price_aed),
     which is exactly why it was missed -- a search for the first cell's text
     does not find the second. Two copies would drift, and the one that drifts
     is the one nobody photographs.

     sesc() on every line: `set_contents` is a list of strings built from an
     order's own snapshot, and a member's NAME came from the catalogue, which
     came from the WordPress import. It is printed into innerHTML.

     The snapshot is the source and no relation is consulted, so an order whose
     Set has since been rewritten -- or deleted -- still prints the box the
     customer actually bought. */
  function odSetContents(it){
    var lines = (it && it.set_contents) || [];

    if (!lines.length) return '';

    return '<div class="pbrand" style="margin-top:3px;padding-inline-start:7px;'
      + 'border-inline-start:2px solid var(--line-2,#eadfe4)">'
      + lines.map(function(l){ return sesc(l); }).join('<br>')
      + '</div>';
  }

  function odItemsCard(o){
    var editable = !!o.editable;
    var rows = o.items.map(function(it){
      var qtyCell = editable
        ? '<input class="odinp odqty" data-itemid="'+it.id+'" type="number" min="1" value="'+it.quantity+'" style="width:60px;padding:6px 7px">'
        : '\u00d7 '+it.quantity;
      var priceCell = editable
        /* WHOLE DIRHAMS. AdminOrderController refuses a NEW line price
           carrying fils. The stored value is still printed as it is, so a line
           revived from a WooCommerce order shows its real 99.80 rather than a
           rounded lie — only a figure he types has to be whole. */
        ? '<input class="odinp odprice" data-itemid="'+it.id+'" type="number" step="1" min="0" value="'+it.unit_price_aed+'" title="Whole dirhams" style="width:84px;padding:6px 7px">'
        : 'AED '+it.unit_price_aed;
      var removeCell = editable
        ? '<button class="btn ghost sm oditemdel" data-itemid="'+it.id+'" style="color:var(--sale,#c0392b);padding:4px 9px">Remove</button>'
        : '';
      return '<tr><td style="width:44px">'+odItemThumb(it)+'</td>'+
        '<td><b style="font-size:12.5px">'+sesc(it.name)+'</b><div class="pbrand">'+sesc(it.brand||'')+'</div>'+
          odSetContents(it)+'</td>'+
        '<td>'+priceCell+'</td><td>'+qtyCell+'</td><td><b>AED '+it.total_aed+'</b></td>'+(editable?'<td>'+removeCell+'</td>':'')+'</tr>';
    }).join('');

    var refundedLine = o.refunded_total_aed>0 ? '<div class="between" style="color:var(--sale,#c0392b)"><span>Refunded</span><span>-AED '+o.refunded_total_aed+'</span></div>' : '';
    var capturedLine = (o.settlement && o.settlement.captured) ? '<div class="between" style="color:var(--ink-soft)"><span>Captured</span><span>AED '+o.settlement.captured_total_aed+'</span></div>' : '';
    var vatLine = o.vat ? '<div class="between" style="color:var(--ink-faint);font-size:11.5px;padding-top:4px"><span>'+sesc(o.vat.label)+'</span><span>AED '+o.vat.amount_aed+'</span></div>' : '';

    var addProductBlock = editable
      ? '<div style="margin-top:14px;position:relative"><input class="odinp" id="odAddProductSearch" placeholder="Search products to add\u2026" style="width:280px">'+
        '<div id="odAddProductResults" style="display:none;position:absolute;z-index:20;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.08);width:340px;max-height:280px;overflow:auto;margin-top:4px"></div></div>'
      : '<p style="font-size:12px;color:var(--ink-faint);margin-top:12px;display:flex;align-items:center;gap:6px">'+ic('<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1" stroke-linecap="round"/>')+'This order is no longer editable \u2014 it has already shipped or been closed out.</p>';

    return '<div class="odcard" id="odItems">'+odCardHead('Items')+
      '<div class="pad">'+
      '<div style="overflow:auto"><table><thead><tr><th></th><th>Item</th><th>Price</th><th>Qty</th><th>Total</th>'+(editable?'<th></th>':'')+'</tr></thead><tbody>'+rows+'</tbody></table></div>'+
      addProductBlock+
      (o.shipping_method?'<p style="font-size:12px;color:var(--ink-soft);margin-top:10px">Shipping: '+sesc(o.shipping_method)+'</p>':'')+
      /* (Lane PU) The payment panel sits in the empty space LEFT of the
         totals -- "a big green box" -- and the two wrap onto separate rows on a
         phone. The totals block itself is unchanged. */
      '<div class="odpayrow">'+odPaymentPanel(o)+
      '<div style="margin-top:14px;max-width:280px;margin-left:auto;'+(editable?'margin-right:140px;':'')+'display:flex;flex-direction:column;gap:5px;font-size:13px">'+
      '<div class="between"><span style="color:var(--ink-soft)">Subtotal</span><span>AED '+o.subtotal_aed+'</span></div>'+
      (o.bundle_discount_aed>0?'<div class="between"><span style="color:var(--ink-soft)">Buy-together discount</span><span>-AED '+o.bundle_discount_aed+'</span></div>':'')+
      (Math.round((o.discount_total_aed-(o.bundle_discount_aed||0))*100)>0?'<div class="between"><span style="color:var(--ink-soft)">Discount'+(o.coupon_code?' ('+sesc(o.coupon_code)+')':'')+'</span><span>-AED '+(Math.round((o.discount_total_aed-(o.bundle_discount_aed||0))*100)/100)+'</span></div>':'')+
      '<div class="between"><span style="color:var(--ink-soft)">Shipping</span><span>AED '+o.shipping_total_aed+'</span></div>'+
      (o.fee_total_aed>0?'<div class="between"><span style="color:var(--ink-soft)">Fees</span><span>AED '+o.fee_total_aed+'</span></div>':'')+
      '<div class="between" style="font-weight:800;font-size:14px;border-top:1px solid var(--border);padding-top:8px"><span>Order total</span><span>AED '+o.total_aed+'</span></div>'+
      vatLine+
      capturedLine+
      refundedLine+
      '</div>'+
      '</div>'+
      '<div class="row" style="margin-top:16px;gap:10px;align-items:center">'+
      '<button class="btn ghost sm" id="odRefundToggle">Refund</button>'+
      '<div id="odRefundForm" style="display:none;gap:8px;align-items:center" class="row">'+
        '<input class="odinp" id="odRefundAmt" type="number" step="0.01" placeholder="Amount AED" style="width:120px">'+
        '<input class="odinp" id="odRefundReason" placeholder="Reason (optional)" style="width:200px">'+
        /*
         * The double-click guard, and it lives here rather than on the button
         * because the server's own guard is a UNIQUE index on this value. One
         * key per form render means two clicks send the same key and collide
         * in the database; a successful refund re-renders the page and gets a
         * fresh one, so a second, deliberate refund of the same amount is
         * still allowed. A disabled button would only stop the clicks this
         * browser makes.
         */
        '<input type="hidden" id="odRefundKey" value="'+sesc(odNewKey())+'">'+
        '<button class="btn sm" id="odRefundGo">Confirm refund</button></div>'+
      (o.refundable_aed!=null ? '<span style="font-size:11.5px;color:var(--ink-faint)">AED '+o.refundable_aed+' still refundable</span>' : '')+
      '</div>'+
      '</div></div>';
  }

  /* A fresh idempotency key. randomUUID is not available on http:// origins in
   * older browsers, so there is a fallback rather than an undefined key. */
  function odNewKey(){
    try{ if(window.crypto && crypto.randomUUID) return 'ui:'+crypto.randomUUID(); }catch(e){}
    return 'ui:'+Date.now()+'-'+Math.random().toString(36).slice(2,12);
  }

  /*
   * (Lane PU) THE PAYMENT PANEL -- "a big green box where clearly written
   * 'Paid with <payment method name>'". Every word of it is decided by the
   * server (App\Support\OrderPaymentPanel); this only draws it, and escapes
   * all of it: the method title and the reference came from WooCommerce.
   *
   * IT REPLACES THE CAPTURE BOX. "Not captured ... Capture AED X" was drawn on
   * every cash-on-delivery order, including delivered ones. Capture now
   * appears in exactly one place: inside this panel, on a Tabby or Tamara
   * order this shop authorised and has not yet taken the money for -- the
   * only orders where pressing it moves money. The button keeps its id, so the
   * existing wiring in wireOrderDetail() still serves it.
   *
   * Green paid, amber cash-on-delivery-to-collect, grey/red not paid.
   */
  function odPaymentPanel(o){
    var p = o.payment;
    if(!p) return '';
    var can = o.can || {};
    var rows = [];
    var row = function(label, value, extra){
      rows.push('<div class="odpay-r"><dt>'+label+'</dt><dd>'+value+(extra||'')+'</dd></div>');
    };
    if(p.method_title) row('Method', sesc(p.method_title));
    if(p.gateway && p.gateway !== p.method_title) row('Gateway', sesc(p.gateway));
    if(p.transaction_id){
      row('Transaction ID', '<code class="odpay-id">'+sesc(p.transaction_id)+'</code>',
        '<button type="button" class="odpay-copy" data-odcopy="'+sesc(p.transaction_id)+'" aria-label="Copy the transaction ID">Copy</button>');
    }
    if(p.capture_ref){
      row('Capture ID', '<code class="odpay-id">'+sesc(p.capture_ref)+'</code>',
        '<button type="button" class="odpay-copy" data-odcopy="'+sesc(p.capture_ref)+'" aria-label="Copy the capture ID">Copy</button>');
    }
    if(p.date_label) row(p.state === 'cod_collected' ? 'Collected' : 'Date paid', sesc(p.date_label));
    if(p.state !== 'unpaid') row('Amount', 'AED '+sesc(String(p.amount_aed)));
    if(p.refunds && p.refunds.any){
      row('Refunded', '-AED '+sesc(String(p.refunds.total_aed))+' <span class="odpay-soft">· net AED '+sesc(String(p.refunds.net_aed))+'</span>');
    }

    var actions = '';
    if(p.can_record_cash && can.payment){
      actions += '<button type="button" class="btn sm odpay-act" id="odRecordCash">Record cash received</button>';
    }
    /* (Lane OL) "Send order link": a failed order, or a pending one nobody
       has paid, handed back to the customer as one signed link. */
    var pl = o.pay_link;
    var plLast = '';
    if(pl && pl.offered && pl.can){
      actions += '<button type="button" class="btn sm odpay-act" id="odPayLinkGo">Send order link</button>';
      if(pl.last) plLast = '<p class="odpay-soft odpl-last" id="odPayLinkLast">Link last sent: '+sesc(pl.last.label)+' · '+sesc(pl.last.at_label)+(pl.last.by?' · by '+sesc(pl.last.by):'')+'</p>';
    }
    if(p.capture && p.capture.offered && can.money){
      actions += '<button type="button" class="btn sm odpay-act" id="odCaptureGo" title="'+sesc(p.capture.window||'')+'">Capture AED '+sesc(String(o.total_aed))+'</button>'+
        (p.capture.days_left!=null ? '<span class="odpay-soft'+(p.capture.expiring?' odpay-urgent':'')+'">'+
          (p.capture.days_left>0 ? p.capture.days_left+' day'+(p.capture.days_left===1?'':'s')+' left to capture' : 'Capture window has run out')+'</span>' : '');
    }

    var icon = p.tone==='green' ? '<path d="M5 12.5l4.2 4.2L19 7"/>'
             : p.tone==='amber' ? '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'
             : '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 8.5l7 7M15.5 8.5l-7 7"/>';

    return '<section class="odpay odpay-'+sesc(p.tone)+'" id="odPayPanel" data-state="'+sesc(p.state)+'" aria-label="Payment">'+
      '<div class="odpay-head"><span class="odpay-ic" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'+icon+'</svg></span>'+
        '<div><div class="odpay-h">'+sesc(p.headline)+'</div>'+(p.detail?'<div class="odpay-d">'+sesc(p.detail)+'</div>':'')+'</div></div>'+
      (rows.length?'<dl class="odpay-dl">'+rows.join('')+'</dl>':'')+
      (actions?'<div class="odpay-acts">'+actions+'</div>':'')+plLast+
      odJourney(o.payment_journey)+
    '</section>';
  }

  /*
   * (Lane TM) PAYMENT JOURNEY, inside the payment panel: the server's verdict
   * ("Never reached Tamara…", "came back without finishing", "Tamara reported
   * the payment as declined") and each step it was read from. Built by
   * App\Support\PaymentJourney; every word escaped here, nothing measured.
   */
  function odJourney(j){
    if(!j || !j.steps) return '';
    return '<details class="odpj" open><summary>Payment journey</summary>'+
      '<p class="odpj-v odpj-'+sesc(j.verdict.tone||'n')+'">'+sesc(j.verdict.text)+'</p><ol class="odpj-l">'+
      j.steps.map(function(s){
        return '<li class="odpj-'+sesc(s.tone||'n')+'"><b>'+sesc(s.title)+'</b>'+(s.detail?'<span>'+sesc(s.detail)+'</span>':'')+'<small>'+sesc(s.at_label)+'</small></li>';
      }).join('')+'</ol>'+
      (j.cart?'<p class="odpay-soft">Basket #'+sesc(String(j.cart.id))+' is in Store → Cart Tracking (search this order number).</p>':'')+
    '</details>';
  }

  /* (Lane TM) Customer journey -- landing source, basket, placed -- from
     App\Support\CustomerJourney, and every email sent for the order from
     App\Support\OrderEmails. Server-built, escaped here, nothing measured. */
  function odJourneyCard(o){
    var c = o.customer_journey;
    if(!c) return '';
    return '<div class="odcard" id="odJourney">'+odCardHead('Customer journey')+'<div class="pad"><ol class="odpj-l">'+
      c.steps.map(function(s){
        return '<li class="odpj-'+sesc(s.tone||'n')+'"><b>'+sesc(s.title)+'</b>'+(s.detail?'<span>'+sesc(s.detail)+'</span>':'')+'<small>'+sesc(s.at_label)+'</small></li>';
      }).join('')+'</ol>'+
      (c.cart_id?'<p class="odpay-soft" style="margin-top:10px">Basket #'+sesc(String(c.cart_id))+' is in Store → Cart Tracking (search this order number).</p>':'')+
      '<p class="odpay-soft" style="margin-top:8px;color:var(--ink-soft)">'+sesc(c.not_tracked)+'</p></div></div>';
  }

  function odEmailsCard(o){
    var m = o.emails;
    if(!m) return '';
    var rows = m.rows.map(function(r){
      return '<div class="odem-r"><div><b>'+sesc(r.type)+'</b><span>'+sesc(r.to)+' · '+sesc(r.at_label)+'</span>'+
        (r.subject?'<span>'+sesc(r.subject)+'</span>':'')+(r.error?'<span class="odem-err">'+sesc(r.error)+'</span>':'')+'</div>'+
        '<span class="odem-s odem-'+sesc(r.status)+'">'+sesc(r.status)+'</span></div>';
    }).join('');
    return '<div class="odcard" id="odEmails">'+odCardHead('Emails ('+m.rows.length+')')+'<div class="pad">'+
      (rows || '<p style="font-size:12.5px;color:var(--ink-soft)">No email has been sent for this order.</p>')+
      '<p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px">'+sesc(m.note)+'</p></div></div>';
  }

  function odNotesCard(o){
    var rows = o.notes.map(function(n){
      return '<div style="padding:10px 0;border-bottom:1px solid var(--border)"><div style="font-size:12.5px">'+sesc(n.content)+'</div>'+
        '<div style="font-size:11px;color:var(--ink-soft);margin-top:3px">'+sesc(n.author||'Admin')+' \u00b7 '+fmtDT(n.created_at)+'</div></div>';
    }).join('');

    return '<div class="odcard" id="odNotes">'+odCardHead('Order notes')+'<div class="pad">'+
      (rows || '<p style="font-size:12.5px;color:var(--ink-soft)">No notes yet.</p>')+
      '<div class="row" style="margin-top:12px;gap:8px"><textarea class="odinp" id="odNoteText" rows="2" placeholder="Add a note for other admins…" style="flex:1"></textarea>'+
      '<button class="btn sm" id="odNoteGo" style="align-self:flex-end">Add</button></div></div></div>';
  }

  function odAttributionCard(o){
    var a = o.attribution||{};
    var na = '<span style="color:var(--ink-faint)">Not tracked yet</span>';
    /* Lane AN: where the order came from, recorded by analytics at checkout. */
    var sv = o.source||{};
    if(sv.known){
      var fld = function(l, v){ return '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">'+l+'</label><div style="font-size:13px">'+(v?sesc(v):'<span style="color:var(--ink-faint)">—</span>')+'</div></div>'; };
      var tch = function(t){ return t ? [t.channel, t.source && t.source !== t.channel.toLowerCase() ? t.source : '', t.medium, t.campaign, t.click].filter(Boolean).join(' · ') : ''; };
      return '<div class="odcard" style="margin-bottom:14px" id="odAttr">'+odCardHead('Source')+'<div class="pad" style="padding:18px 20px">'+
        fld('SOURCE', sv.chip)+fld('LAST TOUCH', tch(sv.last))+fld('FIRST TOUCH', tch(sv.first))+
        fld('LANDING PAGE', sv.first && sv.first.landing ? (function(p){ try{ return decodeURIComponent(p); }catch(x){ return p; } })(sv.first.landing) : '')+
        '<div class="odfld" style="margin-bottom:0"><label style="font-weight:700;color:var(--ink-faint)">TIME TO ORDER</label><div style="font-size:13px">'+(sv.days==null?'—':sv.days===0?'Same day':sv.days+' day'+(sv.days===1?'':'s')+' after the first visit')+'</div></div>'+
        '</div></div>';
    }
    return '<div class="odcard" style="margin-bottom:14px" id="odAttr">'+odCardHead('Order attribution')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">SOURCE</label><div style="font-size:13px">'+(a.origin?sesc(a.origin):na)+'</div></div>'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">DEVICE TYPE</label><div style="font-size:13px">'+(a.device_type?sesc(a.device_type):na)+'</div></div>'+
      '<div class="odfld" style="margin-bottom:0"><label style="font-weight:700;color:var(--ink-faint)">SESSION PAGE VIEWS</label><div style="font-size:13px">'+(a.session_page_views!=null?a.session_page_views:na)+'</div></div>'+
      '</div></div>';
  }

  function odActionsCard(o){
    var real = (o.actions&&o.actions.real)||[], ph = (o.actions&&o.actions.placeholder)||[];
    var labels = {cancel:'Cancel order', duplicate:'Duplicate order', resend_confirmation:'Resend confirmation email', email_invoice:'Email invoice'};
    var opts = real.concat(ph).map(function(a){ return '<option value="'+a+'">'+(labels[a]||a)+(ph.indexOf(a)>-1?' (not available yet)':'')+'</option>'; }).join('');
    return '<div class="odcard" style="margin-bottom:14px" id="odActions">'+odCardHead('Order actions')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="row" style="gap:8px;margin-bottom:14px"><select class="odinp" id="odActionSel" style="flex:1"><option value="">Choose an action\u2026</option>'+opts+'</select>'+
      '<button class="btn sm" id="odActionGo" style="background:#FFF3E0;color:#B36A0E;border:1.5px solid transparent">'+ic('<path d="M9 6l6 6-6 6"/>')+'</button></div>'+
      '<div class="between"><a href="#" id="odTrash" style="color:var(--sale,#c0392b);font-size:12.5px;font-weight:600;text-decoration:none">Move to trash</a><button class="btn" id="odUpdate" style="background:#E08A1A;color:#fff">Update</button></div></div></div>';
  }

  function odHistoryCard(o){
    var h = o.customer_history||{};
    return '<div class="odcard" style="margin-bottom:14px" id="odHist">'+odCardHead('Customer history')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">TOTAL ORDERS</label><div style="font-size:17px;font-weight:800">'+(h.total_orders||0)+'</div></div>'+
      /* LANE DU: this is what the customer was BILLED across their orders, so
         where the shop charged VAT it is money inside a figure that is not the
         shop's to keep. Named and explained only when there is something to
         disclose — a suffix on a figure that contains no tax is noise. */
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)" title="'+
        sesc(((h.revenue_basis||{}).note)||'')+'">LIFETIME SPEND'+
        (Number(h.tax_collected_aed||0) ? ' (INCL. VAT)' : '')+'</label>'+
        '<div style="font-size:17px;font-weight:800">AED '+(h.total_revenue_aed||0)+'</div>'+
        (Number(h.tax_collected_aed||0)
          ? '<div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px">includes AED '+
            sesc(String(h.tax_collected_aed))+' VAT collected for the tax authority</div>'
          : '')+'</div>'+
      '<div class="odfld" style="margin-bottom:0"><label style="font-weight:700;color:var(--ink-faint)">AVERAGE ORDER VALUE</label><div style="font-size:17px;font-weight:800">AED '+(h.average_order_value_aed||0)+'</div></div>'+
      '</div></div>';
  }

  function odInvoiceCard(o){
    /* FOUR BUTTONS, NOT FIVE. 'Shipping Label' and 'Dispatch Label' were two
       names for one sheet, and neither was built; now that the sheet exists,
       two buttons that print the identical A6 label would leave the operator a
       standing question about which one to press and, worse, the impression
       that the shop issues two different things. One button, named for what
       the printed sheet calls itself. */
    var docs = ['Invoice','Packing slip','Delivery note','Dispatch label'];
    return '<div class="odcard" id="odInvoice">'+odCardHead('Invoice / Packing')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">INVOICE NUMBER</label><div style="font-size:13px">'+(o.invoice_number?sesc(String(o.invoice_number)):'<span style="color:var(--ink-faint)">Not yet invoiced</span>')+'</div></div>'+
      '<label style="font-size:10.5px;font-weight:700;color:var(--ink-faint)">PRINT / DOWNLOAD</label>'+
      '<div style="margin-top:8px">'+docs.map(function(d){
        return '<div class="between" style="padding:8px 0;border-bottom:1px solid var(--line-2,var(--border))"><span style="font-size:12.5px;font-weight:500">'+d+'</span><button class="btn ghost sm" data-oddoc="'+d+'" style="padding:5px 9px">'+ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>')+'</button></div>';
      }).join('')+'</div></div></div>';
  }

  function seoSel2(id, cur, opts){
    return '<select class="inp" id="'+id+'" style="width:100%">'+opts.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===cur?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>';
  }

  function fmtDT(iso){
    if(!iso) return '\u2014';
    var d = new Date(iso);
    if(isNaN(d)) return sesc(iso);
    return d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})+' at '+d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
  }

  function wireOrderDetail(o){
    var id = o.id;

    // A product photograph whose URL no longer resolves falls back to the
    // initials rather than the browser's broken-image icon.
    document.querySelectorAll('#content .odthumb img').forEach(function(img){
      img.onerror = function(){
        var holder = img.parentNode;
        if(holder) holder.textContent = holder.dataset.mark||'';
      };
    });

    // Collapse/expand — sections start open (Rafi's choice); this just toggles.
    document.querySelectorAll('#content .odtoggle').forEach(function(t){
      t.onclick = function(){
        var card = t.closest('.card');
        var body = card.querySelectorAll(':scope > *:not(.between)');
        var hidden = body[0] && body[0].style.display==='none';
        body.forEach(function(el){ el.style.display = hidden ? '' : 'none'; });
        t.textContent = hidden ? 'Hide' : 'Show';
      };
    });

    odNotifySync(o);

    document.getElementById('odUpdate').onclick = async function(){
      var status = document.getElementById('odStatusSel').value;
      var box = document.getElementById('odNotify');
      var body = {status:status};
      /*
        `notify` is sent only when the box is live. A disabled box carries no
        decision — the status it belongs to has no customer email at all — and
        posting `notify:false` for it would look like the operator chose
        silence when the screen never offered them a choice. Omitted means
        "no view expressed", which is what the server reads every caller
        written before this field as meaning.
      */
      if(box && !box.disabled) body.notify = box.checked;
      /* (Lane PU) Unpaid -> paid asks for the payment first: "Mark as paid".
         The status and the payment then travel in ONE request to
         /mark-paid, so a refused revive records no payment. Between two paid
         statuses it never asks. */
      if(odNeedsPayment(o, status)){ odMarkPaidModal(o, {status:status, notify:body.notify}); return; }
      try{ await api('/admin-api/orders/'+id+'/status',{method:'PUT',body:JSON.stringify(body)});
        toast(body.notify === false ? 'Order updated · no email sent' : 'Order updated');
        renderOrderDetail(id);
      }catch(e){
        /* A REVIVE THE SHOP CANNOT PAY FOR IS REFUSED, AND THE REFUSAL NAMES
           WHAT IS SHORT. Bringing a cancelled order back to life has to re-take
           the units it put back on the shelf and the coupon use it handed back;
           where it cannot, the server says which product is short and by how
           many, or which coupon is spent. That sentence IS the feature —
           'Update failed' throws it away and sends the owner to look at his
           wifi. api() attaches the status and the parsed body for exactly this.
           The screen is redrawn either way, because the order did not move. */
        if(e && e.status === 422 && e.body && e.body.error === 'revive_refused'){
          alert(e.body.message);
          renderOrderDetail(id);
          return;
        }
        toast('Update failed', 'bad');
      }
    };

    document.getElementById('odTrash').onclick = async function(){
      if(!confirm('Move this order to trash?')) return;
      try{ await api('/admin-api/orders/'+id,{method:'DELETE'}); toast('Order moved to trash'); renderOrders(); }
      catch(e){ toast('Could not trash this order.','bad'); }
    };

    /* (Lane PU) "Order history" opens THIS customer's orders in a popup. It
       used to open the Customers screen -- the whole list, nobody selected. */
    var custHist = document.getElementById('odCustHist');
    if(custHist) custHist.onclick = function(e){ e.preventDefault(); odHistoryModal(o, 1); };

    var custChange = document.getElementById('odCustChange');
    if(custChange) custChange.onclick = function(e){ e.preventDefault(); odCustomerModal(o); };

    /* (Lane PU) Billing / Shipping -> Edit: a real form in a modal. Billing's
       "Edit" had no handler at all; Shipping's opened a raw JSON textarea. */
    document.querySelectorAll('#content [data-odedit]').forEach(function(b){
      b.onclick = function(e){ e.preventDefault(); odAddressModal(o, b.dataset.odedit); };
    });

    // (Lane PU) The transaction / capture reference's Copy button.
    document.querySelectorAll('#content [data-odcopy]').forEach(function(b){
      b.onclick = function(){ odCopy(b.dataset.odcopy, b); };
    });

    // (Lane OL) "Send order link".
    var payLinkGo = document.getElementById('odPayLinkGo');
    if(payLinkGo) payLinkGo.onclick = function(){ odPayLinkModal(o); };

    // (Lane PU) Cash on delivery, amber: "Record cash received".
    var recordCash = document.getElementById('odRecordCash');
    if(recordCash) recordCash.onclick = function(){ odMarkPaidModal(o, {cash:true}); };

    // Item editing: quantity, price, remove, add product — only rendered when o.editable.
    if(o.editable){
      document.querySelectorAll('#content .odqty').forEach(function(inp){
        inp.onchange = async function(){
          var qty = parseInt(inp.value, 10);
          if(!qty || qty<1){ toast('Quantity must be at least 1.', 'bad'); renderOrderDetail(id); return; }
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+inp.dataset.itemid),{method:'PUT',credentials:'same-origin',
              headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
              body:JSON.stringify({quantity:qty})});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not update that item.', 'bad'); return; }
            toast('Quantity updated'); renderOrderDetail(id);
          }catch(e){ toast('Could not update that item.','bad'); }
        };
      });
      document.querySelectorAll('#content .odprice').forEach(function(inp){
        inp.onchange = async function(){
          var price = parseFloat(inp.value);
          if(price==null || isNaN(price) || price<0){ toast('Enter a valid price.', 'bad'); renderOrderDetail(id); return; }
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+inp.dataset.itemid),{method:'PUT',credentials:'same-origin',
              headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
              body:JSON.stringify({unit_price_aed:price})});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not update that item.', 'bad'); return; }
            toast('Price updated'); renderOrderDetail(id);
          }catch(e){ toast('Could not update that item.','bad'); }
        };
      });
      document.querySelectorAll('#content .oditemdel').forEach(function(btn){
        btn.onclick = async function(){
          if(!confirm('Remove this item from the order?')) return;
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+btn.dataset.itemid),{method:'DELETE',credentials:'same-origin',
              headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'}});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not remove that item.', 'bad'); return; }
            toast('Item removed'); renderOrderDetail(id);
          }catch(e){ toast('Could not remove that item.','bad'); }
        };
      });

      /*
       * "Add a product to this order" is the SAME picker New Order uses --
       * admin/partials/product-picker.blade.php -- so the two screens search,
       * highlight, key and draw their thumbnails identically. What was here
       * before had none of that: no keyboard selection, no images, and a fresh
       * document-level click listener added on EVERY render of the order, each
       * one holding that render's detached nodes for as long as the console
       * stayed open. The picker owns exactly one such listener for its life.
       */
      if(document.getElementById('odAddProductSearch') && typeof window.kbbProductPicker==='function'){
        if(!odPicker){
          odPicker = window.kbbProductPicker({
            input:'#odAddProductSearch',
            results:'#odAddProductResults',
            listId:'odAddProductList',
            minChars:2,
            search: async function(term){
              var data = await api('/admin-api/catalog/products?search='+encodeURIComponent(term)+'&per_page=8');
              return data.products||[];
            },
            rowMeta: function(p){ return [p.brand||'', p.sku||''].filter(Boolean).join(' \u00b7 '); },
            rowSide: function(p){ return 'AED '+(p.sale_price!=null?p.sale_price:p.price); },
            onPick: function(p){ odAddItem(p.id); }
          });
        }
        // A different order means a different basket: nothing typed against the
        // last one should be waiting in the box for this one.
        if(odPickerOrder!==id){ odPicker.reset(); odPickerOrder = id; }
        odPicker.attach();
      }
    }

    // Refund.
    document.getElementById('odRefundToggle').onclick = function(){
      var f = document.getElementById('odRefundForm');
      f.style.display = f.style.display==='none' ? 'flex' : 'none';
    };
    document.getElementById('odRefundGo').onclick = async function(){
      var amt = parseFloat(document.getElementById('odRefundAmt').value);
      if(!amt || amt<=0){ toast('Enter a refund amount.', 'bad'); return; }
      var reason = document.getElementById('odRefundReason').value;
      var btn = this;
      // Cosmetic only. The guard that counts is the idempotency key below,
      // which the server has a unique index on -- a disabled button does
      // nothing about a retried request or a second tab.
      btn.disabled = true;
      try{
        var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/refund'),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
          body:JSON.stringify({amount_aed:amt, reason:reason, idempotency_key:document.getElementById('odRefundKey').value})});
        var j = await r.json();
        if(!r.ok || j.ok===false){ btn.disabled = false; toast(j.message||'Could not process that refund.', 'bad'); return; }
        // The server's own words: "refunded through Tabby" and "recorded --
        // return the money by hand" are different facts and the admin needs
        // to be told which one happened.
        toast(j.message||'Refund recorded'); renderOrderDetail(id);
      }catch(e){ btn.disabled = false; toast('Could not process that refund.','bad'); }
    };

    // Capture. Present only on an authorised, uncaptured Tabby or Tamara
    // order -- see odPaymentPanel and App\Support\OrderPaymentPanel.
    var captureBtn = document.getElementById('odCaptureGo');
    if(captureBtn){
      captureBtn.onclick = async function(){
        captureBtn.disabled = true;
        try{
          var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/capture'),{method:'POST',credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
            body:'{}'});
          var j = await r.json();
          if(!r.ok || j.ok===false){ captureBtn.disabled = false; toast(j.message||'Could not capture that payment.', 'bad'); return; }
          toast(j.message||'Captured'); renderOrderDetail(id);
        }catch(e){ captureBtn.disabled = false; toast('Could not capture that payment.','bad'); }
      };
    }

    // Add note.
    document.getElementById('odNoteGo').onclick = async function(){
      var content = document.getElementById('odNoteText').value.trim();
      if(!content){ toast('Write a note first.', 'bad'); return; }
      try{ await api('/admin-api/orders/'+id+'/notes',{method:'POST',body:JSON.stringify({content:content})});
        toast('Note added'); renderOrderDetail(id);
      }catch(e){ toast('Could not save that note.','bad'); }
    };

    // Actions dropdown.
    document.getElementById('odActionGo').onclick = async function(){
      var action = document.getElementById('odActionSel').value;
      if(!action){ toast('Choose an action first.', 'bad'); return; }
      try{
        var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/action'),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
          body:JSON.stringify({action:action})});
        var j = await r.json();
        if(!r.ok || j.ok===false){ toast(j.message||'That action could not be completed.', 'bad'); return; }
        toast('Done'); renderOrderDetail(id);
      }catch(e){ toast('That action could not be completed.','bad'); }
    };

    /* All four are real documents now — the placeholder toast that used to
       answer three of these buttons is gone, and with it the last of it from
       this card. The URLs come from the order-detail payload rather than being
       built here, so this cannot drift from the route or lose the deployment's
       base path. Opened in a new tab because the admin console is a single
       page — navigating it away would lose the order the operator is working
       on.

       The toast is KEPT as the fallback for an empty URL. It is reachable
       again the moment a console is served against an older endpoint that does
       not send one of these keys, and a button that silently does nothing is
       the harder fault to report. Its wording is now about this order rather
       than about the feature. */
    document.querySelectorAll('#content [data-oddoc]').forEach(function(b){
      b.onclick = function(){
        var kind = b.dataset.oddoc;
        var url  = kind === 'Invoice'        ? (o.invoice_url || '')
                 : kind === 'Packing slip'   ? (o.packing_slip_url || '')
                 : kind === 'Delivery note'  ? (o.delivery_note_url || '')
                 : kind === 'Dispatch label' ? (o.shipping_label_url || '')
                 : '';
        /* Gated after the fact, same constraint as the bulk documents above:
           the popup has to be opened inside the click. (Lane SEC) THE ADDRESS
           IS NOT WRITTEN IN THIS FILE -- it arrives on the payload from
           Admin\InvoiceController::invoiceUrl() and its three siblings, which
           is why no scan of the console found these four and why
           ServerBuiltAdminUrlsTest reads the SERVER for them instead. */
        if (url) {
          window.open(url, '_blank', 'noopener');
          kbbTellIfDownloadRefused(url);
          return;
        }
        toast('That document is not available for this order.', 'bad');
      };
    });
  }

  /* ===== Lane PU · Store → Orders → (an order): the four modals =====

     Billing / Shipping → Edit, Customer → Order history, Customer → Change,
     and Status → "Mark as paid". All four use the console's own modal
     (openModal / closeModal, the same box New Order and the order quick view
     use), all four cancel on Escape, on ✕, on Cancel and on a click outside,
     and every value printed into them goes through sesc(). The server is the
     gate for every write -- each endpoint has its own row in
     App\Support\AdminCapabilities -- and o.can only spares an admin a modal
     that would end in a 403. */

  function odModal(html, onCancel){
    var bg = document.getElementById('modalBg');
    var closed = false;
    var cleanup = function(){
      if(closed) return; closed = true;
      document.removeEventListener('keydown', onKey, true);
      if(bg) bg.removeEventListener('click', onBg);
    };
    var cancel = function(){ if(closed) return; cleanup(); closeModal(); if(onCancel) onCancel(); };
    var onKey = function(e){ if(e.key === 'Escape'){ e.preventDefault(); cancel(); } };
    var onBg = function(e){ if(e.target === bg) cancel(); };
    openModal(html);
    document.addEventListener('keydown', onKey, true);
    if(bg) bg.addEventListener('click', onBg);
    document.querySelectorAll('#modal [data-odx]').forEach(function(b){ b.onclick = function(e){ e.preventDefault(); cancel(); }; });
    return { close: function(){ cleanup(); closeModal(); }, cancel: cancel };
  }

  /* The first validation message the server sent, or a fallback. */
  function odErrText(e, fallback){
    var b = e && e.body;
    if(b && b.errors){ for(var k in b.errors){ if(b.errors[k] && b.errors[k][0]) return b.errors[k][0]; } }
    if(b && b.message) return b.message;
    if(e && e.status === 403) return 'Your account is not allowed to do that.';
    return fallback;
  }

  /* "Now" on the SHOP's clock, for a datetime-local box. The order's own
     created_at carries the shop's offset (StoreTime::iso), so the box shows the
     time the owner sees on his gateway's dashboard, not the laptop's zone. */
  function odShopNowLocal(o){
    var m = String(o.created_at||'').match(/([+-])(\d{2}):?(\d{2})$/);
    var off = m ? (m[1]==='-'?-1:1)*(parseInt(m[2],10)*60+parseInt(m[3],10)) : 0;
    var d = new Date(Date.now() + off*60000);
    var p = function(n){ return (n<10?'0':'')+n; };
    return d.getUTCFullYear()+'-'+p(d.getUTCMonth()+1)+'-'+p(d.getUTCDate())+'T'+p(d.getUTCHours())+':'+p(d.getUTCMinutes());
  }

  function odStatusLabel(s){
    var m = {onhold:'On hold', pending:'Pending payment'};
    s = String(s||'');
    return m[s] || (s.charAt(0).toUpperCase()+s.slice(1));
  }

  function odNeedsPayment(o, to){
    var can = o.can || {};
    if(!can.payment || to === o.status) return false;
    if((o.unpaid_statuses||[]).indexOf(o.status) < 0) return false;
    if((o.paid_statuses||[]).indexOf(to) < 0) return false;
    /* Cash on delivery to Processing or Shipped is not money received -- the
       panel goes amber "to collect" and there is nothing to write down yet.
       Straight to Completed is the cash arriving, and that does ask. */
    if(o.payment_method === 'cod' && to !== 'completed') return false;
    return true;
  }

  function odCopy(text, btn){
    var done = function(ok){
      if(!btn) return;
      var was = btn.dataset.label || btn.textContent;
      btn.dataset.label = was;
      btn.textContent = ok ? 'Copied' : 'Copy failed';
      setTimeout(function(){ btn.textContent = was; }, 1500);
    };
    var fallback = function(){
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly',''); t.style.position='fixed'; t.style.opacity='0';
      document.body.appendChild(t); t.select();
      var ok = false; try{ ok = document.execCommand('copy'); }catch(e){}
      t.remove(); done(ok);
    };
    try{
      if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(text).then(function(){ done(true); }, fallback); return; }
    }catch(e){}
    fallback();
  }

  /* ---------------------------------------------------- Mark as paid */
  function odMarkPaidModal(o, opts){
    opts = opts || {};
    var cash = !!opts.cash;
    var sel = document.getElementById('odStatusSel');
    var revert = function(){
      if(sel && sel.value !== o.status){ sel.value = o.status; sel.dispatchEvent(new Event('change')); }
    };
    var methods = o.payment_methods || [];
    var current = cash ? 'cod' : (o.payment_method || (methods[0] && methods[0].id) || '');
    var options = methods.map(function(m){
      return '<option value="'+sesc(m.id)+'"'+(m.id===current?' selected':'')+'>'+sesc(m.title)+'</option>';
    }).join('');
    var lead = cash
      ? 'The courier has handed over the cash for order #'+sesc(o.order_number)+'. The payment panel turns green.'
      : 'Order #'+sesc(o.order_number)+' moves from <b>'+sesc(odStatusLabel(o.status))+'</b> to <b>'+sesc(odStatusLabel(opts.status))+'</b>. Record how it was paid.';

    var m = odModal(
      '<div class="modal-h"><b>'+(cash?'Record cash received':'Mark as paid')+'</b><button class="x" data-odx aria-label="Cancel">✕</button></div>'+
      '<div class="modal-b odmodal">'+
        '<p class="odm-lead">'+lead+'</p>'+
        '<div class="odfld"><label for="odMpMethod">Payment method</label>'+
          '<select class="odinp" id="odMpMethod"'+(cash?' disabled':'')+'>'+options+'</select></div>'+
        '<div class="odfld"><label for="odMpRef">Payment reference / transaction ID <span class="odm-opt">optional</span></label>'+
          '<input class="odinp" id="odMpRef" maxlength="191" autocomplete="off" placeholder="The ID from your gateway’s dashboard" value="'+sesc(cash?'':(o.transaction_id||''))+'"></div>'+
        '<div class="odfld"><label for="odMpDate">Date paid</label>'+
          '<input class="odinp" id="odMpDate" type="datetime-local" value="'+odShopNowLocal(o)+'" max="'+odShopNowLocal(o)+'"></div>'+
        '<p class="odm-err" id="odMpErr" role="alert"></p>'+
        '<div class="odm-acts"><button type="button" class="btn ghost" data-odx>Cancel</button>'+
          '<button type="button" class="btn primary" id="odMpGo">'+(cash?'Record cash':'Confirm')+'</button></div>'+
      '</div>', revert);

    var go = document.getElementById('odMpGo');
    var err = document.getElementById('odMpErr');
    setTimeout(function(){ var f = document.getElementById(cash?'odMpRef':'odMpMethod'); if(f) f.focus(); }, 30);

    go.onclick = async function(){
      var body = {
        payment_method: document.getElementById('odMpMethod').value,
        reference: document.getElementById('odMpRef').value.trim(),
        paid_at: document.getElementById('odMpDate').value
      };
      if(!body.paid_at){ err.textContent = 'Enter the date the payment was made.'; return; }
      if(!cash && opts.status){ body.status = opts.status; if(opts.notify !== undefined) body.notify = opts.notify; }
      go.disabled = true; err.textContent = '';
      try{
        var j = await api('/admin-api/orders/'+o.id+'/mark-paid', {method:'POST', body:JSON.stringify(body)});
        m.close();
        toast(cash ? 'Cash recorded' : (j.message || 'Payment recorded'));
        renderOrderDetail(o.id);
      }catch(e){
        go.disabled = false;
        err.textContent = odErrText(e, 'Could not record that payment.');
      }
    };
  }

  /* ------------------------------------------------ (Lane OL) Send order link
     Store → Orders → (a failed or unpaid order) → Payment → Send order link.
     One POST mints the signed link (the same one all day); Copy, WhatsApp and
     Email each tell the server how it went, which writes the order note the
     "Link last sent" line and the Payment journey read. Nothing is measured,
     nothing polls; every server word is escaped. */
  function odPayLinkModal(o){
    var can = o.pay_link || {};
    var sent = false;
    var m = odModal(
      '<div class="modal-h"><b>Send order link · #'+sesc(o.order_number)+'</b><button class="x" data-odx aria-label="Close">✕</button></div>'+
      '<div class="modal-b odmodal" id="odPlBody"><p class="odm-lead">Making the link…</p></div>',
      function(){ if(sent) renderOrderDetail(o.id); });
    var body = document.getElementById('odPlBody');
    var post = function(via){ return api('/admin-api/orders/'+o.id+'/pay-link', {method:'POST', body:JSON.stringify({via:via||''})}); };
    var lastLine = function(l){ return l ? 'Last sent: '+sesc(l.label)+' · '+sesc(l.at_label)+(l.by?' · by '+sesc(l.by):'') : 'Not sent yet.'; };

    post('').then(function(j){
      body.innerHTML =
        '<p class="odm-lead">The customer opens order #'+sesc(o.order_number)+' with its items, delivery and total exactly as placed, chooses how to pay, and completes <b>this same order</b>. The link works until <b>'+sesc(j.expires_label)+'</b>.</p>'+
        (j.problems && j.problems.length ? '<p class="odm-err" style="display:block">Payment is blocked until this is sorted: '+j.problems.map(sesc).join('; ')+'.</p>' : '')+
        '<div class="odfld"><label for="odPlUrl">Order link</label><input class="odinp odpl-url" id="odPlUrl" readonly value="'+sesc(j.url)+'"></div>'+
        '<div class="odpl-acts">'+
          '<button type="button" class="btn" id="odPlCopy">Copy link</button>'+
          '<a class="btn odpl-wa" id="odPlWa" href="'+sesc(j.whatsapp_url)+'" target="_blank" rel="noopener noreferrer">WhatsApp</a>'+
          '<button type="button" class="btn" id="odPlMail"'+(j.email?'':' disabled')+'>Email</button>'+
        '</div>'+
        (j.has_phone ? '' : '<p class="odpay-soft">No phone number on this order that WhatsApp can dial — WhatsApp opens so you can pick the chat.</p>')+
        (j.email ? '<p class="odpay-soft">Email goes to '+sesc(j.email)+' — the order email, not a marketing one.</p>' : '<p class="odpay-soft">This order has no email address.</p>')+
        '<p class="odpay-soft" id="odPlLast">'+lastLine(j.last)+'</p>'+
        '<p class="odm-err" id="odPlErr" role="alert"></p>'+
        (can.can_settings ? '<details class="odpl-set" id="odPlSet"><summary>Link settings</summary><div id="odPlSetBody"><p class="odpay-soft">Loading…</p></div></details>' : '');

      var err = document.getElementById('odPlErr');
      var note = function(r){ sent = true; document.getElementById('odPlLast').innerHTML = lastLine(r.last); if(r.message) toast(r.message); };
      var fail = function(e){ err.textContent = odErrText(e, 'That did not go through.'); };

      document.getElementById('odPlCopy').onclick = function(){
        odCopy(j.url, this);
        post('copy').then(note, fail);
      };
      // The link opens WhatsApp itself (no popup blocker in the way); the
      // record goes alongside it.
      document.getElementById('odPlWa').onclick = function(){ post('whatsapp').then(note, fail); };
      var mail = document.getElementById('odPlMail');
      mail.onclick = function(){
        mail.disabled = true; err.textContent = '';
        post('email').then(function(r){ note(r); mail.disabled = false; }, function(e){ fail(e); mail.disabled = false; });
      };

      var set = document.getElementById('odPlSet');
      if(set) set.addEventListener('toggle', function once(){
        if(!set.open) return;
        set.removeEventListener('toggle', once);
        odPayLinkSettings(document.getElementById('odPlSetBody'));
      });
    }, function(e){
      body.innerHTML = '<p class="odm-err" style="display:block">'+sesc(odErrText(e, 'Could not make the link.'))+'</p>';
    });
  }

  function odPayLinkSettings(box){
    api('/admin-api/order-pay-link-settings').then(function(j){
      var s = j.settings || {};
      box.innerHTML =
        '<div class="odfld"><label for="odPlDays">Link works for (days)</label><input class="odinp" id="odPlDays" type="number" min="1" max="'+sesc(String(j.max_days||30))+'" value="'+sesc(String(s.days))+'"></div>'+
        '<div class="odfld"><label for="odPlEn">WhatsApp message (English)</label><textarea class="odinp" id="odPlEn" rows="3" maxlength="600">'+sesc(s.whatsapp_en)+'</textarea></div>'+
        '<div class="odfld"><label for="odPlAr">WhatsApp message (Arabic, added under the English for an order placed in Arabic)</label><textarea class="odinp" id="odPlAr" rows="3" maxlength="600" dir="rtl">'+sesc(s.whatsapp_ar)+'</textarea></div>'+
        '<p class="odpay-soft">Placeholders: {name} {order} {link} {shop}. {link} must stay in.</p>'+
        '<div class="odfld"><label for="odPlSubj">Email subject <span class="odm-opt">empty = “Your order #… is saved — complete it here”</span></label><input class="odinp" id="odPlSubj" maxlength="150" value="'+sesc(s.email_subject)+'" placeholder="{order} {shop} work here too"></div>'+
        '<p class="odm-err" id="odPlSetErr" role="alert"></p>'+
        '<div class="odm-acts"><button type="button" class="btn primary" id="odPlSave">Save link settings</button></div>';
      var save = document.getElementById('odPlSave');
      save.onclick = function(){
        save.disabled = true;
        api('/admin-api/order-pay-link-settings', {method:'PUT', body:JSON.stringify({
          days: document.getElementById('odPlDays').value,
          whatsapp_en: document.getElementById('odPlEn').value,
          whatsapp_ar: document.getElementById('odPlAr').value,
          email_subject: document.getElementById('odPlSubj').value
        })}).then(function(){ save.disabled = false; document.getElementById('odPlSetErr').textContent = ''; toast('Link settings saved. New links use them.'); },
          function(e){ save.disabled = false; document.getElementById('odPlSetErr').textContent = odErrText(e, 'Not saved.'); });
      };
    }, function(e){ box.innerHTML = '<p class="odm-err" style="display:block">'+sesc(odErrText(e, 'Could not load the settings.'))+'</p>'; });
  }

  /* ---------------------------------------------- Billing / Shipping edit */
  function odAddressModal(o, type){
    var a = Object.assign({}, (type==='billing' ? o.billing_address : o.shipping_address) || {});
    if(!a.first_name && !a.last_name && a.name){
      var parts = String(a.name).trim().split(/\s+/); a.first_name = parts.shift(); a.last_name = parts.join(' ');
    }
    var form = o.address_form || {};
    var countries = form.countries || {};
    var codes = Object.keys(countries).sort(function(x,y){ return String(countries[x]).localeCompare(String(countries[y])); });
    var cur = String(a.country||'').toUpperCase();
    if(cur && !countries[cur]) codes.unshift(cur);
    var countryOpts = '<option value="">—</option>'+codes.map(function(c){
      return '<option value="'+sesc(c)+'"'+(c===cur?' selected':'')+'>'+sesc(countries[c] || c)+'</option>';
    }).join('');
    var inp = function(key, label, extra){
      return '<div class="odfld"><label for="odAd_'+key+'">'+label+'</label>'+
        '<input class="odinp" id="odAd_'+key+'" data-odad="'+key+'" value="'+sesc(a[key]||'')+'"'+(extra||'')+'>'+
        '<small class="odm-ferr" data-oderr="address.'+key+'"></small></div>';
    };
    var title = type==='billing' ? 'Edit billing details' : 'Edit shipping address';

    var m = odModal(
      '<div class="modal-h"><b>'+title+'</b><button class="x" data-odx aria-label="Cancel">✕</button></div>'+
      '<div class="modal-b odmodal">'+
        '<div class="odm-2">'+inp('first_name','First name',' maxlength="100" autocomplete="off"')+inp('last_name','Last name',' maxlength="100" autocomplete="off"')+'</div>'+
        inp('company','Company',' maxlength="150"')+
        inp('line1','Address line 1',' maxlength="200"')+
        inp('line2','Address line 2',' maxlength="200"')+
        '<div class="odm-2">'+inp('city','City',' maxlength="100"')+inp('state','State / Emirate',' maxlength="100" list="odEmirates"')+'</div>'+
        '<datalist id="odEmirates">'+(form.emirates||[]).map(function(e){ return '<option value="'+sesc(e)+'">'; }).join('')+'</datalist>'+
        '<div class="odm-2">'+inp('postcode','Postcode',' maxlength="20"')+
          '<div class="odfld"><label for="odAd_country">Country</label><select class="odinp" id="odAd_country" data-odad="country">'+countryOpts+'</select>'+
          '<small class="odm-ferr" data-oderr="address.country"></small></div></div>'+
        (type==='billing'
          ? '<div class="odm-2"><div class="odfld"><label for="odAd_email">Email address</label><input class="odinp" id="odAd_email" type="email" maxlength="191" value="'+sesc(o.email||'')+'"><small class="odm-ferr" data-oderr="email"></small></div>'+
            inp('phone','Phone',' type="tel" maxlength="40"')+'</div>'
          : inp('phone','Phone',' type="tel" maxlength="40"'))+
        '<p class="odm-err" id="odAdErr" role="alert"></p>'+
        '<div class="odm-acts"><button type="button" class="btn ghost" data-odx>Cancel</button>'+
          '<button type="button" class="btn primary" id="odAdGo">Save</button></div>'+
      '</div>');

    setTimeout(function(){ var f = document.getElementById('odAd_first_name'); if(f) f.focus(); }, 30);
    var go = document.getElementById('odAdGo');
    go.onclick = async function(){
      var address = {};
      document.querySelectorAll('#modal [data-odad]').forEach(function(f){ address[f.dataset.odad] = f.value; });
      var body = {type:type, address:address};
      var em = document.getElementById('odAd_email');
      if(em) body.email = em.value.trim();
      document.querySelectorAll('#modal [data-oderr]').forEach(function(s){ s.textContent = ''; });
      document.getElementById('odAdErr').textContent = '';
      go.disabled = true;
      try{
        var j = await api('/admin-api/orders/'+o.id+'/address', {method:'PUT', body:JSON.stringify(body)});
        m.close();
        toast(j.message || 'Saved');
        renderOrderDetail(o.id);
      }catch(e){
        go.disabled = false;
        var errs = (e && e.body && e.body.errors) || {};
        var placed = false;
        Object.keys(errs).forEach(function(k){
          var slot = document.querySelector('#modal [data-oderr="'+k.replace(/"/g,'')+'"]');
          if(slot){ slot.textContent = errs[k][0]; placed = true; }
        });
        if(!placed) document.getElementById('odAdErr').textContent = odErrText(e, 'Could not save those details.');
      }
    };
  }

  /* ------------------------------------------------- Customer → Change */
  function odCustomerModal(o){
    var c = o.customer || {};
    var m = odModal(
      '<div class="modal-h"><b>Change customer</b><button class="x" data-odx aria-label="Cancel">✕</button></div>'+
      '<div class="modal-b odmodal">'+
        '<p class="odm-lead">Now: <b>'+(c.id ? sesc(c.name||c.email)+' · '+sesc(c.email||'') : 'Guest checkout')+'</b>. '+
          'The order moves to the account you pick; its email and addresses stay as they are.</p>'+
        '<div class="odfld"><label for="odCuQ">Find a customer</label><input class="odinp" id="odCuQ" autocomplete="off" placeholder="Name or email"></div>'+
        '<div id="odCuList" class="odm-list" role="listbox" aria-label="Customers"></div>'+
        '<p class="odm-err" id="odCuErr" role="alert"></p>'+
        '<div class="odm-acts">'+(c.id?'<button type="button" class="btn ghost" id="odCuGuest" style="margin-right:auto">Make it a guest order</button>':'')+
          '<button type="button" class="btn ghost" data-odx>Cancel</button></div>'+
      '</div>');

    var q = document.getElementById('odCuQ'), list = document.getElementById('odCuList'), err = document.getElementById('odCuErr');
    var timer = null, seq = 0;
    var save = async function(customerId){
      err.textContent = '';
      try{
        var j = await api('/admin-api/orders/'+o.id+'/customer', {method:'PUT', body:JSON.stringify({customer_id:customerId})});
        m.close(); toast(j.message || 'Customer updated'); renderOrderDetail(o.id);
      }catch(e){ err.textContent = odErrText(e, 'Could not change the customer.'); }
    };
    var guest = document.getElementById('odCuGuest');
    if(guest) guest.onclick = function(){ save(null); };
    setTimeout(function(){ q.focus(); }, 30);
    q.oninput = function(){
      clearTimeout(timer);
      var term = q.value.trim();
      if(term.length < 2){ list.innerHTML = ''; return; }
      timer = setTimeout(async function(){
        var mine = ++seq;
        try{
          var j = await api('/admin-api/order-customer-search?search='+encodeURIComponent(term));
          if(mine !== seq) return;
          var rows = j.customers || [];
          list.innerHTML = rows.length ? rows.map(function(r){
            return '<button type="button" class="odm-item" role="option" data-odcu="'+(+r.id)+'"'+(r.id===c.id?' disabled':'')+'>'+
              '<b>'+sesc(r.name||'—')+'</b><span>'+sesc(r.email)+'</span></button>';
          }).join('') : '<p class="odm-empty">No customer matches “'+sesc(term)+'”.</p>';
          list.querySelectorAll('[data-odcu]').forEach(function(b){ b.onclick = function(){ save(+b.dataset.odcu); }; });
        }catch(e){ if(mine === seq) err.textContent = odErrText(e, 'Could not search customers.'); }
      }, 250);
    };
  }

  /* --------------------------------------------- Customer → Order history */
  function odHistoryModal(o, page){
    var shell = function(inner){
      return '<div class="modal-h"><b>Order history</b><button class="x" data-odx aria-label="Close">✕</button></div>'+
        '<div class="modal-b odmodal odhist">'+inner+'</div>';
    };
    var m = odModal(shell('<p class="odm-empty">Loading orders…</p>'));
    var load = async function(p){
      var j;
      try{ j = await api('/admin-api/orders/'+o.id+'/customer-orders?page='+p); }
      catch(e){
        document.querySelector('#modal .odhist').innerHTML = '<p class="odm-err">'+sesc(odErrText(e, 'Could not load this customer’s orders.'))+'</p>';
        return;
      }
      var s = j.summary || {}, who = j.customer || {};
      var vat = Number(s.tax_collected_aed||0);
      var rows = (j.orders||[]).map(function(r){
        var tone = {completed:'green',processing:'amber',onhold:'amber',shipped:'blue',cancelled:'red',refunded:'red',failed:'red'}[r.status] || 'grey';
        return '<button type="button" class="odh-row'+(r.current?' is-current':'')+'" data-odhist="'+(+r.id)+'">'+
          '<span class="odh-no">#'+sesc(r.order_number)+(r.current?' <em>this order</em>':'')+(r.trashed?' <em>in trash</em>':'')+'</span>'+
          '<span class="odh-date">'+sesc(r.date_label||'')+'</span>'+
          '<span class="odh-st"><span class="pill '+tone+'"><span class="d"></span>'+sesc(odStatusLabel(r.status))+'</span></span>'+
          '<span class="odh-items">'+(+r.items)+' item'+(+r.items===1?'':'s')+'</span>'+
          '<span class="odh-total">AED '+sesc(String(r.total_aed))+'</span>'+
        '</button>';
      }).join('');
      var pager = j.last_page > 1
        ? '<div class="odh-pager"><button type="button" class="btn ghost sm" data-odhp="'+(j.page-1)+'"'+(j.page<=1?' disabled':'')+'>← Newer</button>'+
          '<span>Page '+(+j.page)+' of '+(+j.last_page)+'</span>'+
          '<button type="button" class="btn ghost sm" data-odhp="'+(j.page+1)+'"'+(j.page>=j.last_page?' disabled':'')+'>Older →</button></div>'
        : '';
      document.querySelector('#modal .odhist').innerHTML =
        '<p class="odm-lead"><b>'+sesc(who.name || who.email || '')+'</b>'+(who.name && who.email ? ' · '+sesc(who.email) : '')+(who.guest?' · guest checkout, matched by email':'')+'</p>'+
        '<div class="odh-kpis">'+
          '<div><small>Orders</small><b>'+(+s.total_orders||0)+'</b></div>'+
          '<div><small>Lifetime spend'+(vat?' (incl. VAT)':'')+'</small><b>AED '+sesc(String(s.total_revenue_aed||0))+'</b></div>'+
          '<div><small>Average order</small><b>AED '+sesc(String(s.average_order_value_aed||0))+'</b></div>'+
        '</div>'+
        (rows ? '<div class="odh-head" aria-hidden="true"><span>Order</span><span>Date</span><span>Status</span><span>Items</span><span>Total</span></div><div class="odh-list">'+rows+'</div>' : '<p class="odm-empty">No orders yet.</p>')+
        pager;
      document.querySelectorAll('#modal [data-odhist]').forEach(function(b){
        b.onclick = function(){ m.close(); renderOrderDetail(+b.dataset.odhist); };
      });
      document.querySelectorAll('#modal [data-odhp]').forEach(function(b){
        b.onclick = function(){ load(+b.dataset.odhp); };
      });
    };
    load(page || 1);
  }
  /* ===== Lane PU · end ===== */

  async function openOrder(id){
    var o; try{ o=await api('/admin-api/orders/'+id); }catch(e){ toast('Could not load order','bad'); return; }
    var opts=ORDER_STATUSES.map(function(s){return '<option value="'+s+'"'+(s===o.status?' selected':'')+'>'+s+'</option>';}).join('');
    var c=o.customer||{};
    var addr=[sesc(c.name),sesc(c.email),sesc(c.phone),sesc((c.emirate||'')+(c.address?(' \u00b7 '+c.address):''))].filter(Boolean).join('<br>');
    openModal(
      '<div class="modal-h"><b>Order #'+o.id+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b">'+
      '<div class="row" style="gap:8px;align-items:center;margin-bottom:12px"><span style="font-size:12.5px;color:var(--ink-soft)">Status</span>'+
      '<select class="inp" id="ordStatusSel" style="width:170px">'+opts+'</select>'+
      '<button class="btn sm" id="ordStatusSave">Update</button></div>'+
      '<div class="card pad" style="margin-bottom:12px"><b style="font-size:12.5px">Customer</b><div style="font-size:12.5px;color:var(--ink-2);margin-top:6px;line-height:1.7">'+(addr||'\u2014')+'</div></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead><tbody>'+
      o.items.map(function(it){ return '<tr><td><b style="font-size:12.5px">'+sesc(it.name)+'</b><div class="pbrand">'+sesc((it.brand||''))+'</div>'+odSetContents(it)+'</td><td>'+it.qty+'</td><td>AED '+it.unit_aed+'</td><td><b>AED '+it.line_aed+'</b></td></tr>'; }).join('')+
      '</tbody></table></div>'+
      '<div style="margin-top:12px;font-size:13px;display:flex;flex-direction:column;gap:5px">'+
      '<div class="between"><span style="color:var(--ink-soft)">Subtotal</span><span>AED '+o.subtotal_aed+'</span></div>'+
      '<div class="between"><span style="color:var(--ink-soft)">Delivery</span><span>'+(o.delivery_aed?('AED '+o.delivery_aed):'Free')+'</span></div>'+
      (o.cod_fee_aed?('<div class="between"><span style="color:var(--ink-soft)">COD fee</span><span>AED '+o.cod_fee_aed+'</span></div>'):'')+
      '<div class="between" style="font-weight:700;font-size:14px"><span>Total</span><span>AED '+o.total_aed+'</span></div>'+
      '</div></div>');
    var sel=document.getElementById('ordStatusSel');
    document.getElementById('ordStatusSave').onclick=async function(){
      try{ await api('/admin-api/orders/'+id+'/status',{method:'PUT',body:JSON.stringify({status:sel.value})});
        toast('Order status updated'); closeModal(); renderOrders(); if(cur==='dash') hydrateDash();
      }catch(e){
        /* The same refusal from the same endpoint — see the order detail
           screen for why the sentence has to survive. The modal is left OPEN
           here, unlike the detail screen: the operator is standing in front of
           a status dropdown and the refusal tells him which other status he
           could pick instead. */
        if(e && e.status === 422 && e.body && e.body.error === 'revive_refused'){
          alert(e.body.message);
          return;
        }
        toast('Update failed', 'bad');
      }
    };
  }

  /* ---------- Inventory: real bulk stock save ---------- */
  if(typeof invSave==='function'){
    window.invSave = async function(){
      var changes=[];
      Object.keys(invDraft).forEach(function(k){
        var row=CAT_PRODUCTS[k];
        if(row && invDraft[k]!==row[6]) changes.push({id:row[7], stock:invDraft[k]});
      });
      if(!changes.length){ toast('No changes to save'); return; }
      try{
        await api('/admin-api/inventory',{method:'POST',body:JSON.stringify({changes:changes})});
        Object.keys(invDraft).forEach(function(k){ CAT_PRODUCTS[k][6]=invDraft[k]; });
        var n=changes.length; invDraft={}; catInventory();
        toast('Saved '+n+' product'+(n>1?'s':''));
      }catch(e){ toast('Save failed \u2014 check connection','bad'); }
    };
  }

  /* ===== LANE T · Store · Customers — BEGIN =====

     WHAT THIS REPLACED. Six columns off a single unpaginated fetch of
     /admin-api/customers, one of which — "Emirate" — read c.emirate, and
     `emirate` is not a column on the customers table and never has been. It
     was blank on every install and no one had cause to notice, because the
     screen otherwise looked finished. Pointed at the 5,312 customers the
     WooCommerce import will bring across, that fetch is one response
     containing the entire customer database and no way to find anybody in it.

     WHAT IT IS NOW. A paginated, sortable, filterable list off
     /admin-api/customers/list — inside the guarded admin-api group, which is
     the only reason it is allowed to carry email, phone and home city at all
     — plus a per-customer page, a private note, a reversible trash and a CSV
     of whatever the screen is currently showing.

     EVERY FIGURE IS AGGREGATED IN SQL. Orders, lifetime spend, average order
     value, last order and last activity all arrive with the row. Nothing on
     this screen loops over customers fetching anything, which is the mistake
     that made /shop run 390 queries for four products.

     EVERYTHING WRITTEN INTO THE PAGE GOES THROUGH sesc(). Customer names,
     emails, cities and phone numbers are typed by the public at checkout and
     land in innerHTML; an unescaped one is stored XSS on the owner's own
     back-office. Server messages go through it too — a message can carry a
     customer name.

     BLANKS ARE EXPECTED, NOT EXCEPTIONAL. An imported customer can have no
     name, no phone, no address and no registration date; the owner's own
     reference screenshot shows exactly that. Every cell falls back to an em
     dash instead of printing "undefined" or throwing.
  */

  var CU = {
    page: 1,
    perPage: +(localStorage.getItem('kbb_cust_pp') || 50),
    search: '', filter: 'all', sort: 'newest',
    spendMin: '', spendMax: '', from: '', to: '', country: '', city: '',
    adv: false, cols: null, data: null, err: null, sel: {}, busy: false,
    /* "Select all N matching this view" (Lane PQ, Send account invite): the
       selection is then the FILTER, resolved on the server, not the ticks on
       this page. Remembered with the query it was made for, so changing a
       filter quietly drops it rather than sending to a different set. */
    allMatching: false, allMatchingQs: ''
  };

  var CU_COLDEF = [
    ['contact', 'Phone'], ['type', 'Type'], ['orders', 'Orders'], ['spend', 'Total spend'],
    ['aov', 'AOV'], ['last_order', 'Last order'], ['last_active', 'Last active'],
    ['location', 'Country / City'], ['registered', 'Registered'], ['wp', 'Woo ID']
  ];
  /* Last active and the Woo ID are off by default and one click away in
     Columns: last-active repeats last-order for anybody who has bought, and
     the ten columns that are on already fill a 1032px content area. Woo's own
     screen shows both; this one lets the owner choose without paying for them
     on every page load. */
  var CU_COLS_DEFAULT = {
    contact: true, type: true, orders: true, spend: true, aov: true,
    last_order: true, last_active: false, location: true, registered: true, wp: false
  };
  /* Which column each sortable header maps to, so the header caret and the
     Sort menu can never disagree about what the list is ordered by. */
  var CU_COLSORT = {
    orders: 'orders_desc', spend: 'spend_desc', aov: 'aov_desc',
    last_order: 'last_order_desc', last_active: 'last_active_desc'
  };
  var CU_SORTS = [
    ['newest', 'Newest first'], ['oldest', 'Oldest first'], ['name', 'Name A–Z'],
    ['spend_desc', 'Total spend, high to low'], ['spend_asc', 'Total spend, low to high'],
    ['orders_desc', 'Most orders'], ['aov_desc', 'Highest average order'],
    ['last_order_desc', 'Ordered most recently'], ['last_active_desc', 'Active most recently']
  ];
  var CU_CHIPS = [
    ['all', 'All'], ['ordered', 'Has ordered'], ['never', 'Never ordered'],
    ['repeat', 'Repeat buyers'], ['account', 'Has an account'], ['guest', 'Guest checkout'],
    ['invited', 'Invited, not activated'],
    ['verified', 'Email verified'], ['unverified', 'Not verified'], ['trashed', 'Trash']
  ];
  /* Bands in whole dirhams; the server converts with Money::fromMajor, so the
     comparison happens in fils and nothing here ever holds a money float. */
  var CU_BANDS = [
    ['', '', 'Any spend'], ['0', '0', 'Nothing yet'], ['1', '499', 'Under AED 500'],
    ['500', '1999', 'AED 500 – 1,999'], ['2000', '', 'AED 2,000 and over']
  ];

  function cuCols(){
    if(CU.cols) return CU.cols;
    var saved = null;
    try{ saved = JSON.parse(localStorage.getItem('kbb_cust_cols') || 'null'); }catch(e){ saved = null; }
    CU.cols = Object.assign({}, CU_COLS_DEFAULT, saved || {});
    return CU.cols;
  }
  function cuSaveCols(){ try{ localStorage.setItem('kbb_cust_cols', JSON.stringify(CU.cols)); }catch(e){} }

  function cuParams(forExport){
    var p = new URLSearchParams();
    if(!forExport){ p.set('page', CU.page); p.set('per_page', CU.perPage); }
    if(CU.search) p.set('search', CU.search);
    if(CU.filter && CU.filter !== 'all') p.set('filter', CU.filter);
    if(CU.sort && CU.sort !== 'newest') p.set('sort', CU.sort);
    if(CU.spendMin !== '') p.set('spend_min', CU.spendMin);
    if(CU.spendMax !== '') p.set('spend_max', CU.spendMax);
    if(CU.from) p.set('from', CU.from);
    if(CU.to) p.set('to', CU.to);
    if(CU.country) p.set('country', CU.country);
    if(CU.city) p.set('city', CU.city);
    return p.toString();
  }

  function cuDate(iso){
    if(!iso) return '<span style="color:var(--ink-faint)">—</span>';
    var d = new Date(iso);
    if(isNaN(d)) return '<span style="color:var(--ink-faint)">—</span>';
    return sesc(d.toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'}));
  }
  /* "3 days ago" under the date. A shop owner reads recency faster than a
     date, and a customer who last did anything in 2019 should look like it. */
  function cuAgo(iso){
    if(!iso) return '';
    var d = new Date(iso); if(isNaN(d)) return '';
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if(days < 0) return '';
    if(days === 0) return 'today';
    if(days === 1) return 'yesterday';
    if(days < 31) return days + ' days ago';
    if(days < 365){ var m = Math.max(1, Math.round(days / 30)); return m + (m === 1 ? ' month ago' : ' months ago'); }
    var years = Math.floor(days / 365);
    return (years < 2 ? 'over a year ago' : years + ' years ago');
  }
  function cuLabel(c){ return c.name || c.email || ('Customer #' + c.id); }
  function cuDash(v){ return (v === null || v === undefined || v === '') ? '<span style="color:var(--ink-faint)">—</span>' : sesc(v); }
  /* Customers screen. It reports successes AND failures through one helper,
     so it has to carry the kind through — otherwise 'Could not restore this
     customer' arrives wearing the same green tick as 'Customer restored'. */
  function cuToast(msg, kind){ toast(sesc(msg), kind); }

  function cuTypePill(c){
    var badge = c.account_type === 'account'
      ? '<span class="pill blue">Account</span>'
      : '<span class="pill grey">Guest</span>';
    if(c.email_verified) badge += ' <span class="pill green" title="Email verified">✓</span>';
    return badge + cuInvitePill(c);
  }
  /* Send account invite (Lane PQ). "Activated" once they used an invite to set
     a password; "Invited · 2 Oct" while one is out and unused. */
  function cuInvitePill(c){
    if(c.invite_accepted_at) return ' <span class="pill green" title="Set a password from an account invite on ' + cuDateText(c.invite_accepted_at) + '">Activated</span>';
    if(c.invited_at) return ' <span class="pill amber" title="' + (c.invite_count > 1 ? c.invite_count + ' invites sent' : '1 invite sent') + '">Invited · ' + cuDateText(c.invited_at, true) + '</span>';
    return '';
  }
  function cuDateText(iso, short){
    var d = new Date(iso);
    if(isNaN(d)) return '';
    return sesc(d.toLocaleDateString('en-GB', short ? {day:'numeric', month:'short'} : {day:'numeric', month:'short', year:'numeric'}));
  }

  async function cuLoad(){
    if(CU.busy) return;
    CU.busy = true;
    try{
      CU.data = await api('/admin-api/customers/list?' + cuParams(false));
      CU.perPage = CU.data.per_page;
      CU.err = null;
    }catch(e){
      CU.data = null;
      /* Say WHAT failed, not what might have. The first version of this screen
         guessed "the route may not be wired up", which was wrong on a server
         where it was wired and something else broke — and a wrong guess sends
         whoever is reading it looking in the wrong place. Fetch the same URL
         again plainly so the status and the server's own message can be shown. */
      CU.err = {status:0, body:''};
      try{
        var probe = await fetch(fixAdminApiUrl('/admin-api/customers/list?' + cuParams(false)),
          {credentials:'same-origin', headers:{'Accept':'application/json'}});
        CU.err.status = probe.status;
        CU.err.body = (await probe.text() || '').slice(0, 400);
      }catch(e2){
        CU.err.body = String(e2 && e2.message || e);
      }
    }
    CU.busy = false;
    cuPaint();
  }

  window.renderCustomers = async function(){
    CU.page = 1; CU.sel = {}; CU.allMatching = false;
    if(window.kbbCustomerInvite) window.kbbCustomerInvite.refreshBanner();
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Customers</h2>' +
      '<p>Everyone with a record on the store — shoppers who checked out as guests as well as people with an account.</p></div>' +
      '<p style="padding:24px;color:var(--ink-soft)">Loading customers…</p></div>';
    await cuLoad();
  };

  /* Turn the HTTP status into the thing to actually go and check. */
  function cuWhy(){
    var st = CU.err ? CU.err.status : 0;
    if(st === 404) return 'The server returned 404 — this build\u2019s routes are not live yet. The compiled route cache needs clearing (Store \u2192 Core Updates does this on every apply).';
    if(st === 401 || st === 403) return 'The server returned ' + st + ' — the admin session was refused. Sign out and back in.';
    if(st === 419) return 'The server returned 419 — the admin session expired. Reload the page.';
    if(st === 500) return 'The server returned 500 — the request reached the code and the code threw. The exception is in storage/logs/laravel.log; the text below is what the server sent back. If its \u201cbuild\u201d is older than the package you just applied, the server is running cached code rather than the file that shipped.';
    if(st === 0)   return 'The request never completed — the browser could not reach the server at all.';
    return 'The server returned ' + st + '. The text below is what it sent back.';
  }

  function cuPaint(){
    var el = document.querySelector('#content');
    var d = CU.data;

    if(!d){
      el.innerHTML = '<div class="wrap"><div class="page-head"><h2>Customers</h2></div>' +
        '<div class="card pad"><p style="font-size:13px;color:var(--red)">Customers could not be loaded.</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:6px">' + cuWhy() + '</p>' +
        (CU.err && CU.err.body ? '<pre style="margin-top:10px;padding:10px;background:var(--bg-soft,#f6f6f7);border-radius:8px;font-size:11.5px;white-space:pre-wrap;word-break:break-word;max-height:220px;overflow:auto">' + sesc(CU.err.body) + '</pre>' : '') +
        '<div style="margin-top:12px"><button class="btn ghost sm" id="cuRetry">Try again</button></div></div></div>';
      var retry = document.getElementById('cuRetry');
      if(retry) retry.onclick = function(){ cuLoad(); };
      return;
    }

    var cols = CU_COLDEF.filter(function(c){ return cuCols()[c[0]]; });
    var selected = Object.keys(CU.sel).filter(function(k){ return CU.sel[k]; });
    if(CU.allMatching && (CU.allMatchingQs !== cuParams(true) || !selected.length)) CU.allMatching = false;
    var s = d.summary || {customers:0, orders:0, spend_display:'', aov_display:''};

    el.innerHTML =
      '<div class="wrap">' +
      '<div class="between" style="margin-bottom:8px;flex-wrap:wrap;gap:12px">' +
        '<div class="page-head" style="margin:0"><h2>Customers</h2>' +
        '<p>Everyone with a record on the store — shoppers who checked out as guests as well as people with an account. Orders and spend count paid, processing, shipped and completed orders.</p></div>' +
        '<div class="row" style="gap:8px">' +
          '<button class="btn ghost" id="cuColsBtn">' + ic('<path d="M4 6h16M7 12h10M10 18h4"/>') + ' Columns</button>' +
          '<button class="btn" id="cuExport">' + ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>') + ' Export CSV</button>' +
        '</div>' +
      '</div>' +

      '<div id="cuInviteBanner"></div>' +

      (d.unlinked_orders ?
        '<div class="card pad" style="margin-bottom:14px;border-color:#f0dcae;background:var(--amber-soft)">' +
        '<b style="font-size:12.5px">' + d.unlinked_orders + ' order' + (d.unlinked_orders === 1 ? ' is' : 's are') + ' not linked to any customer.</b>' +
        '<p style="font-size:12px;color:var(--ink-2);margin-top:4px">Their revenue is real but it cannot appear against anybody in this list. This is what an import of WooCommerce guest orders without matching customer records looks like.</p>' +
        '</div>' : '') +

      '<div class="kpis" style="margin-bottom:16px">' +
        cuKpi('Customers in this view', (s.customers || 0).toLocaleString(), d.total === s.customers ? 'matching the filters' : '') +
        cuKpi('Orders', (s.orders || 0).toLocaleString(), 'paid and fulfilled') +
        cuKpi('Lifetime revenue', sesc(s.spend_display || ''), 'from these customers') +
        cuKpi('Average order', sesc(s.aov_display || ''), 'across those orders') +
      '</div>' +

      (CU.colsOpen ? cuColsPanel() : '') +

      '<div class="toolbar" style="flex-wrap:wrap;gap:10px">' +
        '<div class="search" style="min-width:220px">' + ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>') +
        '<input id="cuSearch" placeholder="Search name, email, phone or Woo user ID…" value="' + sesc(CU.search) + '"></div>' +
        '<select class="inp" id="cuSort" style="max-width:230px">' +
          CU_SORTS.map(function(o){ return '<option value="' + o[0] + '"' + (CU.sort === o[0] ? ' selected' : '') + '>Sort: ' + o[1] + '</option>'; }).join('') +
        '</select>' +
        '<button class="btn ghost" id="cuAdv">' + ic('<path d="M4 6h16M7 12h10M10 18h4"/>') + ' Filters' + (cuAdvCount() ? ' · ' + cuAdvCount() : '') + (CU.adv ? ' ▴' : ' ▾') + '</button>' +
      '</div>' +

      (CU.adv ? cuAdvPanel(d) : '') +

      '<div class="chips" style="margin-bottom:12px">' +
        CU_CHIPS.map(function(c){
          var n = (d.counts && d.counts[c[0]] !== undefined) ? d.counts[c[0]] : 0;
          return '<button class="chip' + (CU.filter === c[0] ? ' on' : '') + '" data-cuf="' + c[0] + '">' +
            sesc(c[1]) + ' <span style="opacity:.6">' + n + '</span></button>';
        }).join('') +
      '</div>' +

      (selected.length ?
        '<div class="card pad" style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">' +
        (CU.allMatching
          ? '<b style="font-size:12.5px">All ' + d.total.toLocaleString() + ' customers matching this view are selected</b>'
          : '<b style="font-size:12.5px">' + selected.length + ' selected</b>' +
            (d.total > selected.length ? '<button class="btn ghost sm" id="cuSelAll">Select all ' + d.total.toLocaleString() + ' matching this view</button>' : '')) +
        '<button class="btn ghost sm" id="cuClearSel">Clear</button>' +
        '<div style="flex:1"></div>' +
        '<button class="btn sm" id="cuInvite">Send account invite…</button>' +
        /* Trash stays a per-page action: it is capped at 500 and refuses
           customers with orders one by one, which does not scale to a filter. */
        (CU.allMatching ? '' : '<button class="btn sm" style="background:var(--red)" id="cuBulkDelete">Move to trash…</button>') +
        '</div>' : '') +

      '<div class="card" style="overflow:auto">' + cuTable(d, cols) + '</div>' +

      '<div class="pager" style="margin-top:14px;flex-wrap:wrap;gap:10px">' +
        '<span>' + (d.customers.length ? ((d.page - 1) * d.per_page + 1) : 0) + '–' +
        ((d.page - 1) * d.per_page + d.customers.length) + ' of ' + d.total + '</span>' +
        '<div class="row" style="gap:8px">' +
          '<select class="inp" id="cuPerPage" style="width:126px">' +
            [25, 50, 100, 200].map(function(n){ return '<option value="' + n + '"' + (n === CU.perPage ? ' selected' : '') + '>' + n + ' per page</option>'; }).join('') +
          '</select>' +
          '<button class="btn ghost sm" ' + (d.page <= 1 ? 'disabled' : '') + ' id="cuPrev">‹ Prev</button>' +
          '<span style="font-size:12px">Page ' + d.page + ' of ' + d.last_page + '</span>' +
          '<button class="btn ghost sm" ' + (d.page >= d.last_page ? 'disabled' : '') + ' id="cuNext">Next ›</button>' +
        '</div>' +
      '</div></div>';

    cuBindList();
    if(window.kbbCustomerInvite) window.kbbCustomerInvite.paintBanner();
  }

  function cuKpi(label, value, sub){
    return '<div class="card pad"><div style="font-size:11px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em">' +
      sesc(label) + '</div><div style="font-size:21px;font-weight:700;margin-top:6px">' + value +
      '</div><div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px">' + sesc(sub || '') + '</div></div>';
  }

  function cuAdvCount(){
    var n = 0;
    if(CU.spendMin !== '' || CU.spendMax !== '') n++;
    if(CU.from || CU.to) n++;
    if(CU.country) n++;
    if(CU.city) n++;
    return n;
  }

  function cuColsPanel(){
    return '<div class="card pad" style="margin-bottom:14px">' +
      '<b style="font-size:12.5px">Columns</b>' +
      '<div style="display:flex;flex-wrap:wrap;gap:12px 20px;margin-top:11px">' +
      CU_COLDEF.map(function(c){
        return '<label class="row" style="gap:8px;font-size:12.5px;cursor:pointer">' +
          '<span class="cbx' + (cuCols()[c[0]] ? ' on' : '') + '" data-cucol="' + c[0] + '">' + ic(I.check) + '</span> ' + sesc(c[1]) + '</label>';
      }).join('') +
      '</div><div style="margin-top:14px"><button class="btn ghost sm" id="cuColsReset">Reset to default</button></div></div>';
  }

  function cuAdvPanel(d){
    var band = CU_BANDS.filter(function(b){ return b[0] === CU.spendMin && b[1] === CU.spendMax; })[0];
    return '<div class="card pad" style="margin-bottom:12px">' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">' +
        '<div class="fld" style="margin:0"><label>Lifetime spend</label><select id="cuBand">' +
          CU_BANDS.map(function(b){ return '<option value="' + b[0] + '|' + b[1] + '"' + (band && band[2] === b[2] ? ' selected' : '') + '>' + sesc(b[2]) + '</option>'; }).join('') +
          (band ? '' : '<option value="custom" selected>Custom range</option>') +
        '</select></div>' +
        '<div class="fld" style="margin:0"><label>Spend from (AED)</label><input id="cuSpendMin" type="number" min="0" step="1" value="' + sesc(CU.spendMin) + '" placeholder="any"></div>' +
        '<div class="fld" style="margin:0"><label>Spend to (AED)</label><input id="cuSpendMax" type="number" min="0" step="1" value="' + sesc(CU.spendMax) + '" placeholder="any"></div>' +
        '<div class="fld" style="margin:0"><label>Registered from</label><input id="cuFrom" type="date" value="' + sesc(CU.from) + '"></div>' +
        '<div class="fld" style="margin:0"><label>Registered to</label><input id="cuTo" type="date" value="' + sesc(CU.to) + '"></div>' +
        '<div class="fld" style="margin:0"><label>Country</label><select id="cuCountry"><option value="">Any country</option>' +
          (d.countries || []).map(function(c){ return '<option value="' + sesc(c) + '"' + (CU.country === c ? ' selected' : '') + '>' + sesc(c) + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="fld" style="margin:0"><label>City</label><input id="cuCity" value="' + sesc(CU.city) + '" placeholder="any city"></div>' +
      '</div>' +
      '<div class="row" style="margin-top:14px;gap:8px"><button class="btn sm" id="cuApply">Apply filters</button>' +
      '<button class="btn ghost sm" id="cuClearFilters">Clear all</button>' +
      '<span style="font-size:11.5px;color:var(--ink-soft)">Registration dates leave out customers imported without one.</span></div></div>';
  }

  function cuTable(d, cols){
    if(!d.customers.length){
      return '<p style="padding:34px;text-align:center;color:var(--ink-soft);font-size:13px">No customers match this view.' +
        (cuAdvCount() || CU.search || CU.filter !== 'all' ? ' <button class="btn ghost sm" id="cuEmptyClear" style="margin-left:8px">Clear filters</button>' : '') + '</p>';
    }

    var allOnPage = d.customers.every(function(c){ return CU.sel[c.id]; });

    var head = '<thead><tr>' +
      '<th style="width:36px"><span class="cbx' + (allOnPage ? ' on' : '') + '" id="cuAll">' + ic(I.check) + '</span></th>' +
      '<th>' + cuHeadSort('name', 'Customer') + '</th>' +
      cols.map(function(c){
        var right = ['orders', 'spend', 'aov'].indexOf(c[0]) >= 0;
        var inner = CU_COLSORT[c[0]] ? cuHeadSort(CU_COLSORT[c[0]], c[1]) : (c[0] === 'registered' ? cuHeadSort('newest', c[1]) : sesc(c[1]));
        return '<th style="white-space:nowrap' + (right ? ';text-align:right' : '') + '">' + inner + '</th>';
      }).join('') +
      '<th></th></tr></thead>';

    var body = '<tbody>' + d.customers.map(function(c){
      return '<tr' + (c.trashed ? ' style="opacity:.62"' : '') + '>' +
        '<td><span class="cbx' + (CU.sel[c.id] ? ' on' : '') + '" data-cusel="' + c.id + '">' + ic(I.check) + '</span></td>' +
        '<td><div class="row" style="min-width:0">' +
          '<span class="pthumb" style="background:' + sesc(tcol(cuLabel(c))) + ';width:32px;height:32px;font-size:10px">' + sesc(initials(cuLabel(c))) + '</span>' +
          '<div style="min-width:0"><div class="pname" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:230px">' +
            (c.name ? sesc(c.name) : '<span style="color:var(--ink-faint)">No name on record</span>') +
            (c.trashed ? ' <span class="pill grey">Trashed</span>' : '') + '</div>' +
          '<div class="pbrand" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:230px">' + sesc(c.email) +
            /* Demo customers stay on this list — showing them is what the Demo
               Content feature is for — but they are left out of every money
               figure, and their spend column is computed from real orders only,
               so the row would otherwise read AED 0 with nothing saying why.
               Same badge, same wording and same place as the Orders table. */
            (c.is_demo ? ' · <span style="color:var(--ink-faint)">demo</span>' : '') +
          '</div></div></div></td>' +
        cols.map(function(col){ return cuCell(col[0], c); }).join('') +
        '<td style="white-space:nowrap">' + (c.trashed
          ? '<button class="btn ghost sm" data-curestore="' + c.id + '">Restore</button>'
          : '<button class="btn ghost sm" data-cuview="' + c.id + '">View</button>') + '</td>' +
      '</tr>';
    }).join('') + '</tbody>';

    return '<table style="min-width:760px">' + head + body + '</table>';
  }

  function cuHeadSort(sort, label){
    var on = CU.sort === sort;
    return '<button data-cusort="' + sesc(sort) + '" style="font:inherit;color:inherit;text-transform:inherit;letter-spacing:inherit;' +
      (on ? 'color:var(--accent-ink)' : '') + '">' + sesc(label) + (on ? ' ▾' : '') + '</button>';
  }

  function cuCell(key, c){
    switch(key){
      case 'contact': return '<td style="white-space:nowrap">' + cuDash(c.phone) + '</td>';
      case 'type': return '<td style="white-space:nowrap">' + cuTypePill(c) + '</td>';
      case 'orders': return '<td style="text-align:right">' + c.orders +
        (c.orders_all > c.orders ? '<div class="pbrand">' + c.orders_all + ' incl. cancelled</div>' : '') + '</td>';
      case 'spend': return '<td class="price" style="text-align:right;white-space:nowrap"><b>' + sesc(c.spend_display) + '</b></td>';
      case 'aov': return '<td style="text-align:right;white-space:nowrap;color:var(--ink-2)">' + (c.orders ? sesc(c.aov_display) : '<span style="color:var(--ink-faint)">—</span>') + '</td>';
      case 'last_order': return '<td style="white-space:nowrap;font-size:12px">' + cuDate(c.last_order_at) +
        (c.last_order_at ? '<div class="pbrand">' + sesc(cuAgo(c.last_order_at)) + '</div>' : '') + '</td>';
      case 'last_active': return '<td style="white-space:nowrap;font-size:12px">' + cuDate(c.last_active_at) +
        (c.last_active_at ? '<div class="pbrand">' + sesc(cuAgo(c.last_active_at)) + '</div>' : '') + '</td>';
      case 'location': return '<td style="white-space:nowrap;font-size:12px">' + cuDash(c.country) +
        (c.city ? '<div class="pbrand">' + sesc(c.city) + '</div>' : '') + '</td>';
      case 'registered': return '<td style="white-space:nowrap;font-size:12px">' + cuDate(c.registered_at) + '</td>';
      case 'wp': return '<td style="white-space:nowrap;font-family:var(--mono);font-size:11px;color:var(--ink-soft)">' + cuDash(c.wp_user_id) + '</td>';
      default: return '<td></td>';
    }
  }

  function cuBindList(){
    var $$$ = function(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); };
    var byId = function(id){ return document.getElementById(id); };

    var searchT;
    var searchEl = byId('cuSearch');
    if(searchEl) searchEl.oninput = function(e){
      clearTimeout(searchT);
      var v = e.target.value;
      searchT = setTimeout(function(){ CU.search = v; CU.page = 1; cuLoad(); }, 300);
    };

    var sortEl = byId('cuSort');
    if(sortEl) sortEl.onchange = function(e){ CU.sort = e.target.value; CU.page = 1; cuLoad(); };

    $$$('#content [data-cusort]').forEach(function(b){
      b.onclick = function(){ CU.sort = b.dataset.cusort; CU.page = 1; cuLoad(); };
    });

    $$$('#content .chip[data-cuf]').forEach(function(b){
      b.onclick = function(){ CU.filter = b.dataset.cuf; CU.page = 1; CU.sel = {}; CU.allMatching = false; cuLoad(); };
    });

    var adv = byId('cuAdv');
    if(adv) adv.onclick = function(){ CU.adv = !CU.adv; cuPaint(); };

    var colsBtn = byId('cuColsBtn');
    if(colsBtn) colsBtn.onclick = function(){ CU.colsOpen = !CU.colsOpen; cuPaint(); };

    $$$('#content .cbx[data-cucol]').forEach(function(b){
      b.onclick = function(){ var k = b.dataset.cucol; CU.cols[k] = !CU.cols[k]; cuSaveCols(); cuPaint(); };
    });
    var colsReset = byId('cuColsReset');
    if(colsReset) colsReset.onclick = function(){ CU.cols = Object.assign({}, CU_COLS_DEFAULT); cuSaveCols(); cuPaint(); };

    var band = byId('cuBand');
    if(band) band.onchange = function(e){
      if(e.target.value === 'custom') return;
      var parts = e.target.value.split('|');
      CU.spendMin = parts[0]; CU.spendMax = parts[1]; CU.page = 1; cuLoad();
    };

    var apply = byId('cuApply');
    if(apply) apply.onclick = function(){
      CU.spendMin = (byId('cuSpendMin') || {}).value || '';
      CU.spendMax = (byId('cuSpendMax') || {}).value || '';
      CU.from = (byId('cuFrom') || {}).value || '';
      CU.to = (byId('cuTo') || {}).value || '';
      CU.country = (byId('cuCountry') || {}).value || '';
      CU.city = (byId('cuCity') || {}).value || '';
      CU.page = 1; cuLoad();
    };

    var clearAll = function(){
      CU.spendMin = ''; CU.spendMax = ''; CU.from = ''; CU.to = '';
      CU.country = ''; CU.city = ''; CU.search = ''; CU.filter = 'all';
      CU.page = 1; cuLoad();
    };
    var clearBtn = byId('cuClearFilters'); if(clearBtn) clearBtn.onclick = clearAll;
    var emptyClear = byId('cuEmptyClear'); if(emptyClear) emptyClear.onclick = clearAll;

    var perPage = byId('cuPerPage');
    if(perPage) perPage.onchange = function(e){
      CU.perPage = +e.target.value;
      try{ localStorage.setItem('kbb_cust_pp', CU.perPage); }catch(err){}
      CU.page = 1; cuLoad();
    };

    var prev = byId('cuPrev'); if(prev) prev.onclick = function(){ if(CU.data.page > 1){ CU.page = CU.data.page - 1; cuLoad(); } };
    var next = byId('cuNext'); if(next) next.onclick = function(){ if(CU.data.page < CU.data.last_page){ CU.page = CU.data.page + 1; cuLoad(); } };

    $$$('#content [data-cusel]').forEach(function(b){
      b.onclick = function(){ var id = b.dataset.cusel; CU.sel[id] = !CU.sel[id]; CU.allMatching = false; cuPaint(); };
    });
    var all = byId('cuAll');
    if(all) all.onclick = function(){
      var on = !CU.data.customers.every(function(c){ return CU.sel[c.id]; });
      CU.data.customers.forEach(function(c){ CU.sel[c.id] = on; });
      cuPaint();
    };
    var clearSel = byId('cuClearSel'); if(clearSel) clearSel.onclick = function(){ CU.sel = {}; CU.allMatching = false; cuPaint(); };

    /* Send account invite (Lane PQ). The dialog itself is
       partials/customer-invites.blade.php; this hands it the selection. */
    var selAll = byId('cuSelAll');
    if(selAll) selAll.onclick = function(){
      CU.data.customers.forEach(function(c){ CU.sel[c.id] = true; });
      CU.allMatching = true; CU.allMatchingQs = cuParams(true); cuPaint();
    };
    var invite = byId('cuInvite');
    if(invite) invite.onclick = function(){
      if(!window.kbbCustomerInvite) return;
      var filters = {};
      new URLSearchParams(cuParams(true)).forEach(function(v, k){ filters[k] = v; });
      window.kbbCustomerInvite.open({
        ids: Object.keys(CU.sel).filter(function(k){ return CU.sel[k]; }).map(Number),
        allMatching: CU.allMatching, filters: filters, total: CU.data.total,
        onDone: function(){ CU.sel = {}; CU.allMatching = false; window.kbbCustomerInvite.refreshBanner(); cuLoad(); }
      });
    };

    var bulk = byId('cuBulkDelete');
    if(bulk) bulk.onclick = function(){
      var ids = Object.keys(CU.sel).filter(function(k){ return CU.sel[k]; }).map(Number);
      cuConfirmDelete(ids, null);
    };

    $$$('#content [data-cuview]').forEach(function(b){
      b.onclick = function(){ cuDetail(+b.dataset.cuview); };
    });
    $$$('#content [data-curestore]').forEach(function(b){
      b.onclick = async function(){
        try{
          await api('/admin-api/customers/' + (+b.dataset.curestore) + '/restore', {method:'POST'});
          cuToast('Customer restored'); cuLoad();
        }catch(e){ cuToast('Could not restore this customer', 'bad'); }
      };
    });

    var exportBtn = byId('cuExport');
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = cuParams(true);
      var url = fixAdminApiUrl('/admin-api/customers/export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
  }

  /* -------- destructive actions: always a dialog, sometimes two -------- */

  /**
   * Nothing is deleted on a click. The first dialog says what will happen; the
   * server then refuses anyone with order history and reports how much history
   * there is, and only a second, explicit confirmation carrying force=1 goes
   * through. Trashing is a soft delete either way — the customer and every
   * order they placed are still in the database and Restore brings them back.
   */
  function cuConfirmDelete(ids, label){
    if(!ids.length) return;
    var what = ids.length === 1 ? (label ? sesc(label) : 'this customer') : (ids.length + ' customers');

    openModal('<div class="modal-h"><b>Move to trash</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Move ' + what + ' to the trash?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Nothing is destroyed. The record is hidden from this list, every order they placed stays exactly where it is, and you can restore them from the Trash filter at any time.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" style="background:var(--red)" id="cuDelYes">Move to trash</button></div></div>');

    var yes = document.getElementById('cuDelYes');
    if(yes) yes.onclick = function(){ cuRunDelete(ids, false); };
  }

  async function cuRunDelete(ids, force){
    closeModal();
    try{
      if(ids.length === 1){
        var res = await fetch(fixAdminApiUrl('/admin-api/customers/' + ids[0] + (force ? '?force=1' : '')), {
          method: 'DELETE', credentials: 'same-origin',
          headers: {'X-XSRF-TOKEN': cookie('XSRF-TOKEN'), Accept: 'application/json'}
        });
        var body = await res.json();
        if(res.status === 409 && body.needs_confirmation){ cuConfirmHistory(ids, body); return; }
        if(!res.ok) throw new Error('failed');
        cuToast('Moved to trash');
      }else{
        var out = await api('/admin-api/customers/bulk-delete', {
          method: 'POST', body: JSON.stringify({ids: ids, force: !!force})
        });
        if(out.skipped && out.skipped.length){ cuConfirmSkipped(ids, out); return; }
        cuToast(out.deleted + ' customer' + (out.deleted === 1 ? '' : 's') + ' moved to trash');
      }
      CU.sel = {}; cuLoad();
    }catch(e){
      cuToast('Could not complete that — nothing was changed', 'bad');
    }
  }

  function cuConfirmHistory(ids, body){
    openModal('<div class="modal-h"><b>This customer has order history</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">They have <b>' + body.orders + '</b> order' + (body.orders === 1 ? '' : 's') +
      ' worth <b>' + sesc(body.spend_display || '') + '</b>. Nothing has been deleted.</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Trashing them hides the customer from this list. The orders themselves are untouched and your revenue figures do not change.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Keep this customer</button>' +
      '<button class="btn" style="background:var(--red)" id="cuDelForce">Trash anyway</button></div></div>');
    var force = document.getElementById('cuDelForce');
    if(force) force.onclick = function(){ cuRunDelete(ids, true); };
  }

  function cuConfirmSkipped(ids, out){
    openModal('<div class="modal-h"><b>Some customers have order history</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)"><b>' + out.deleted + '</b> moved to trash. <b>' + out.skipped.length +
      '</b> left alone because ' + (out.skipped.length === 1 ? 'they have' : 'they have') + ' orders:</p>' +
      '<ul style="font-size:12.5px;color:var(--ink-2);margin:8px 0 0 18px">' +
      out.skipped.slice(0, 12).map(function(s){ return '<li>' + sesc(s.label) + ' — ' + s.orders + ' order' + (s.orders === 1 ? '' : 's') + '</li>'; }).join('') +
      (out.skipped.length > 12 ? '<li>and ' + (out.skipped.length - 12) + ' more</li>' : '') + '</ul>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Leave them</button>' +
      '<button class="btn" style="background:var(--red)" id="cuBulkForce">Trash those too</button></div></div>');
    var force = document.getElementById('cuBulkForce');
    if(force) force.onclick = function(){
      cuRunDelete(out.skipped.map(function(s){ return s.id; }), true);
    };
  }

  /* ------------------------------ one customer ------------------------------ */

  async function cuDetail(id){
    var el = document.querySelector('#content');
    el.innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading customer…</p></div>';

    var d;
    try{ d = await api('/admin-api/customers/' + id); }
    catch(e){
      el.innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--red)">Could not load this customer.</p>' +
        '<button class="btn ghost sm" id="cuBack">‹ Back to customers</button></div>';
      var b0 = document.getElementById('cuBack'); if(b0) b0.onclick = function(){ window.renderCustomers(); };
      return;
    }

    var c = d.customer;

    el.innerHTML = '<div class="wrap">' +
      '<div class="between" style="margin-bottom:14px;flex-wrap:wrap;gap:10px">' +
        '<button class="btn ghost sm" id="cuBack">‹ All customers</button>' +
        '<div class="row" style="gap:8px;flex-wrap:wrap">' +
        (!c.trashed && c.account_type !== 'account' ? '<button class="btn sm" id="cuInviteOne">Send account invite…</button>' : '') +
        (c.trashed ? '<button class="btn ghost sm" id="cuRestore">Restore this customer</button>'
                   : '<button class="btn ghost sm" style="color:var(--red)" id="cuTrash">Move to trash</button>') +
        '</div>' +
      '</div>' +

      '<div class="card pad" style="margin-bottom:14px">' +
        '<div class="row" style="gap:14px;flex-wrap:wrap">' +
          '<span class="pthumb" style="background:' + sesc(tcol(cuLabel(c))) + ';width:52px;height:52px;font-size:15px">' + sesc(initials(cuLabel(c))) + '</span>' +
          '<div style="min-width:0;flex:1">' +
            '<div style="font-size:18px;font-weight:700">' + (c.name ? sesc(c.name) : 'No name on record') + '</div>' +
            '<div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px">' + sesc(c.email) + (c.phone ? ' · ' + sesc(c.phone) : '') + '</div>' +
            '<div class="row" style="gap:6px;margin-top:8px;flex-wrap:wrap">' + cuTypePill(c) +
              (c.whatsapp_optin ? ' <span class="pill green">WhatsApp opt-in</span>' : '') +
              (c.wp_user_id ? ' <span class="pill grey">Woo user #' + c.wp_user_id + '</span>' : '') +
              (c.trashed ? ' <span class="pill red">In the trash</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>' +

      '<div class="kpis" style="margin-bottom:14px">' +
        cuKpi('Lifetime value', sesc(c.spend_display), 'paid and fulfilled orders') +
        cuKpi('Orders', String(c.orders), c.orders_all > c.orders ? (c.orders_all + ' including cancelled') : 'all counted') +
        cuKpi('Average order', c.orders ? sesc(c.aov_display) : '—', 'across those orders') +
        cuKpi('Last order', c.last_order_at ? cuAgo(c.last_order_at) : 'never', c.last_active_at ? ('last seen ' + cuAgo(c.last_active_at)) : '') +
      '</div>' +

      '<div class="card pad" style="margin-bottom:14px">' +
        '<b style="font-size:13px">Account</b>' +
        '<div class="g2" style="margin-top:12px">' +
          cuField('Registered', c.registered_at ? cuDate(c.registered_at) : '<span style="color:var(--ink-faint)">Not recorded — typical of an imported customer</span>') +
          cuField('Last activity', c.last_active_at ? (cuDate(c.last_active_at) + ' · ' + sesc(cuAgo(c.last_active_at))) : '<span style="color:var(--ink-faint)">—</span>') +
          cuField('Sign-in', c.account_type === 'account' ? 'Has a password and can sign in' : 'Guest — checked out without an account') +
          cuField('Email verified', c.email_verified ? 'Yes' : 'No') +
          cuField('Account invite', c.invite_accepted_at
            ? 'Activated ' + cuDate(c.invite_accepted_at) + ' — set a password from an invite' + cuInvitePill(c)
            : c.invited_at
              ? 'Sent ' + cuDate(c.invited_at) + (c.invite_count > 1 ? ' (' + c.invite_count + ' invites in all)' : '') + ' — not used yet' + cuInvitePill(c)
              : (c.account_type === 'account' ? '<span style="color:var(--ink-faint)">Not needed — already has a password</span>' : '<span style="color:var(--ink-faint)">Never sent</span>')) +
          cuField('WooCommerce user ID', c.wp_user_id ? String(c.wp_user_id) : '<span style="color:var(--ink-faint)">Not imported — created on this store</span>') +
          cuField('Customer ID', String(c.id)) +
        '</div>' +
      '</div>' +

      '<div class="card pad" style="margin-bottom:14px">' +
        '<b style="font-size:13px">Private note</b>' +
        '<p style="font-size:12px;color:var(--ink-soft);margin:4px 0 10px">Only you see this. The customer never does.</p>' +
        '<div class="fld" style="margin:0"><textarea id="cuNote" placeholder="Anything worth remembering about this customer…">' + sesc(c.notes || '') + '</textarea></div>' +
        '<div style="margin-top:10px"><button class="btn sm" id="cuNoteSave">Save note</button></div>' +
      '</div>' +

      '<div class="card" style="margin-bottom:14px;overflow:auto">' +
        // Not class="pad": that rule is .card.pad, so it does nothing on a
        // child element and the heading sat flush against the card edge.
        '<div style="padding:20px 20px 0"><b style="font-size:13px">Orders</b></div>' +
        (d.orders.length ?
          '<table style="min-width:620px;margin-top:10px"><thead><tr><th>Order</th><th>Status</th><th style="text-align:right">Total</th><th>Payment</th><th>Placed</th></tr></thead><tbody>' +
          d.orders.map(function(o){
            return '<tr><td><b>' + sesc(o.order_number) + '</b>' + (o.wc_order_id ? '<div class="pbrand">Woo #' + o.wc_order_id + '</div>' : '') + '</td>' +
              '<td>' + statusPill(o.status) + '</td>' +
              '<td class="price" style="text-align:right;white-space:nowrap"><b>' + sesc(o.total_display) + '</b>' +
              (o.counts_as_spend ? '' : '<div class="pbrand">not counted</div>') + '</td>' +
              '<td style="font-size:12px">' + sesc(o.payment) + '</td>' +
              '<td style="font-size:12px;white-space:nowrap">' + cuDate(o.placed_at) + '</td></tr>';
          }).join('') + '</tbody></table>'
          : '<p style="padding:24px;color:var(--ink-soft);font-size:13px">No orders yet.</p>') +
      '</div>' +

      '<div class="card pad">' +
        '<b style="font-size:13px">Addresses</b>' +
        (d.addresses.length ?
          '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-top:12px">' +
          d.addresses.map(function(a){
            return '<div style="border:1px solid var(--border);border-radius:12px;padding:12px">' +
              '<div class="row" style="gap:6px"><span class="pill grey">' + sesc(a.type) + '</span>' +
              (a.is_default ? '<span class="pill blue">Default</span>' : '') + '</div>' +
              '<div style="font-size:12.5px;color:var(--ink-2);margin-top:8px;line-height:1.7">' +
              [a.name, a.company, a.line1, a.line2, [a.city, a.state].filter(Boolean).join(', '), a.postcode, a.country, a.phone]
                .filter(function(x){ return x; }).map(function(x){ return sesc(x); }).join('<br>') +
              '</div></div>';
          }).join('') + '</div>'
          : '<p style="font-size:13px;color:var(--ink-soft);margin-top:10px">No saved addresses. A guest checkout does not always leave one.</p>') +
      '</div></div>';

    var back = document.getElementById('cuBack');
    if(back) back.onclick = function(){ window.renderCustomers(); };

    var trash = document.getElementById('cuTrash');
    if(trash) trash.onclick = function(){ cuConfirmDelete([c.id], cuLabel(c)); };

    var inviteOne = document.getElementById('cuInviteOne');
    if(inviteOne) inviteOne.onclick = function(){
      if(window.kbbCustomerInvite) window.kbbCustomerInvite.open({ids: [c.id], total: 1, onDone: function(){ cuDetail(c.id); }});
    };

    var restore = document.getElementById('cuRestore');
    if(restore) restore.onclick = async function(){
      try{ await api('/admin-api/customers/' + c.id + '/restore', {method:'POST'}); cuToast('Customer restored'); cuDetail(c.id); }
      catch(e){ cuToast('Could not restore this customer', 'bad'); }
    };

    var saveNote = document.getElementById('cuNoteSave');
    if(saveNote) saveNote.onclick = async function(){
      var box = document.getElementById('cuNote');
      try{
        await api('/admin-api/customers/' + c.id + '/note', {method:'POST', body: JSON.stringify({notes: box ? box.value : ''})});
        cuToast('Note saved');
      }catch(e){ cuToast('Could not save the note', 'bad'); }
    };
  }

  function cuField(label, value){
    return '<div class="fld" style="margin:0"><label>' + sesc(label) + '</label>' +
      '<div style="font-size:12.5px;color:var(--ink-2);padding-top:2px">' + value + '</div></div>';
  }

  /* window.go, further down, calls this local name; the window-level function
     above is what it delegates to, so the screen can be replaced or tested
     without reaching inside this closure. */
  function renderCustomers(){ return window.renderCustomers(); }

  /* A bookmark straight to this screen — /admin?go=customers, or #customers.
     That navigation is performed by the boot block at the end of the FIRST
     script in this document, which runs before this one exists, so go() lands
     on its fallback and draws the dashboard. Nothing has painted yet at this
     point in parsing, so re-rendering here is not a flicker: it is the first
     thing the browser draws. */
  if(typeof cur !== 'undefined' && cur === 'customers'){ window.renderCustomers(); }
  /* ===== LANE T · Store · Customers — END ===== */

  /* ---------- Quiz Leads screen ---------------------------------------------
     A lead list is a worklist: the owner opens it because somebody has just
     phoned, or because it is Tuesday and the expert requests need ringing back.
     It could do none of that, and it had a hole in it.

     THE HOLE. `concerns` and `recommended` were concatenated into this table's
     HTML with no sesc(), while every other cell on the same row was escaped.
     POST /api/quiz is public, unauthenticated, and validates `concerns` as
     'nullable' \u2014 no type, no length, no content rule \u2014 so the value is whatever
     an anonymous caller posts. That is stored cross-site scripting executing in
     the owner's authenticated admin session, on the one screen whose entire
     purpose is to be opened and read. Both cells go through sesc() now.

     WHAT IT COULD NOT DO. No search, so finding the caller meant reading every
     row. No status, although quiz_submissions.status exists, defaults to 'new'
     and was already coming back from the endpoint unused \u2014 there was nowhere to
     record that a lead had been rung. No conversion figure, so the quiz's worth
     was unknowable from the screen that lists its output. And `expert_message`,
     the words the customer actually typed when asking for a consultation, was
     never sent to the browser at all: the screen showed that somebody wanted
     help and hid what they wanted.

     Search and the status filter are applied by the ENDPOINT, not in the
     browser, so the chip counts and the rows cannot disagree; the box keeps
     focus across the re-render, which is the defect that removed the search
     from another screen in this console. ---------------------------------- */
  var LEADS=[], LEAD_SUMMARY={}, LEAD_STATUSES=['new','contacted','converted','closed'];
  var leadQuery='', leadStatus='', leadExpertOnly=false, leadBusy=false;
  var QL_CSS_ID='ql-quiz-leads-css';
  function qlStyle(){
    if(document.getElementById(QL_CSS_ID)) return '';
    return '<style id="'+QL_CSS_ID+'">'+
      '.ql-wrap{display:grid;gap:18px;min-width:0}.ql-wrap>*{min-width:0}'+
      '.ql-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}'+
      '.ql-sec-h{display:grid;gap:3px;min-width:0}'+
      '.ql-sec-t{font-size:13.5px;font-weight:650}'+
      '.ql-sec-d{font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280);max-width:78ch}'+
      '.ql-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr));gap:12px;min-width:0}'+
      '.ql-stats>*{min-width:0}'+
      '.ql-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:13px 15px;min-width:0}'+
      '.ql-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.05em}'+
      '.ql-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px}'+
      '.ql-stat i{display:block;font-style:normal;font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:3px;line-height:1.4}'+
      /* The header, then a hairline, then the controls that filter what is
         under it \u2014 so the search box belongs to the table rather than floating
         between the title and the rows owned by neither. */
      '.ql-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;min-width:0;'+
        'margin:14px 0 4px;padding-top:14px;border-top:1px solid var(--border,#e6e6e6)}'+
      '.ql-toolbar>*{min-width:0}'+
      '.ql-toolbar input[type=search]{flex:1 1 200px;min-width:0}'+
      '.ql-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;max-width:100%;margin-top:12px}'+
      '.ql-table{width:100%;border-collapse:collapse;font-size:13px}'+
      '.ql-table th,.ql-table td{text-align:left;padding:10px;border-bottom:1px solid var(--border,#e6e6e6);vertical-align:top;white-space:nowrap}'+
      '.ql-table th{font-weight:600;color:var(--ink-soft,#6b7280);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em}'+
      '.ql-table tbody tr:hover{background:rgba(127,127,127,.06)}'+
      /* One thing to read per row: the name leads, and the way to contact them
         rides underneath it as a link you can actually press. */
      '.ql-name{font-weight:600}'+
      '.ql-contact{display:block;font-size:11.5px;font-weight:400;margin-top:2px}'+
      '.ql-contact a{color:var(--ink-soft,#6b7280);text-decoration:underline}'+
      '.ql-tags{white-space:normal;max-width:30ch}'+
      '.ql-tag{display:inline-block;padding:1px 7px;margin:1px 2px 1px 0;border-radius:999px;font-size:10.5px;'+
        'border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}'+
      '.ql-ask{white-space:normal;font-size:11.5px;line-height:1.45;color:var(--ink-soft,#6b7280)}'+
      /* max-width on a <td> is ignored by table layout — the cell sizes to its
         content and the message ran off the right edge into the scroller. A
         block INSIDE the cell is not a table box, so its max-width is honoured
         and the text wraps where it is told to. */
      '.ql-msg{display:block;max-width:30ch;margin-top:5px;white-space:normal}'+
      /* The routine under the concerns that produced it, quieter than them. */
      '.ql-rec{display:block;margin-top:3px;font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.4}'+
      '.ql-sel{font:inherit;font-size:12px;padding:4px 8px;border:1px solid var(--border,#e6e6e6);'+
        'border-radius:8px;background:transparent;color:inherit;min-width:0}'+
      '.ql-empty{padding:34px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}'+
      '.ql-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;max-width:72ch;margin-top:12px}'+
    '</style>';
  }
  function qlParams(){
    var p=[];
    if(leadQuery) p.push('q='+encodeURIComponent(leadQuery));
    if(leadStatus) p.push('status='+encodeURIComponent(leadStatus));
    if(leadExpertOnly) p.push('expert=1');
    return p.length? '?'+p.join('&') : '';
  }
  async function qlLoad(){
    try{
      var d=await api('/admin-api/quiz-leads'+qlParams());
      LEADS=d.leads||[]; LEAD_SUMMARY=d.summary||{}; LEAD_STATUSES=d.statuses||LEAD_STATUSES;
    }catch(e){ LEADS=[]; LEAD_SUMMARY={}; }
  }
  async function renderQuizLeads(){
    await qlLoad();
    qlPaint();
  }
  function qlPaint(){
    var s=LEAD_SUMMARY||{};
    function stat(label,val,sub){
      return '<div class="ql-stat"><span>'+sesc(label)+'</span><b>'+sesc(String(val))+'</b>'+
        (sub? '<i>'+sesc(sub)+'</i>':'')+'</div>';
    }

    var rows=LEADS.length? LEADS.map(function(l){
      var contact = l.email
        ? '<a href="mailto:'+sesc(l.email)+'">'+sesc(l.email)+'</a>'
        : (l.phone? '<a href="tel:'+sesc(String(l.phone).replace(/[^\d+]/g,''))+'">'+sesc(l.phone)+'</a>' : '');
      /* sesc() on BOTH of these. See the note at the top of this screen: the
         values come straight from a public unauthenticated POST. */
      var tags = (l.concerns&&l.concerns.length)
        ? l.concerns.map(function(x){ return '<span class="ql-tag">'+sesc(x)+'</span>'; }).join('')
        : '<span style="color:var(--ink-soft)">\u2014</span>';
      var rec = (l.recommended&&l.recommended.length)
        ? l.recommended.map(function(x){ return sesc(x); }).join(', ')
        : '\u2014';
      var sel='<select class="ql-sel" data-qlstatus="'+l.id+'">'+
        LEAD_STATUSES.map(function(st){
          return '<option value="'+sesc(st)+'"'+((l.status||'new')===st?' selected':'')+'>'+
            sesc(st.charAt(0).toUpperCase()+st.slice(1))+'</option>';
        }).join('')+'</select>';
      /* Six columns, not seven. "Recommended" rides under the concerns it was
         derived from rather than taking a column of its own: one thing to read
         per row, and the table keeps the DATE on screen at 1280 instead of
         pushing it off the right-hand edge into the scroller. */
      return '<tr>'+
        '<td><div class="ql-name">'+sesc(l.name||'Anonymous')+'</div>'+
          (contact? '<span class="ql-contact">'+contact+'</span>':'')+
          (l.email&&l.phone? '<span class="ql-contact">'+sesc(l.phone)+'</span>':'')+'</td>'+
        '<td>'+sel+'</td>'+
        '<td>'+sesc(l.skin_type||'\u2014')+'</td>'+
        '<td class="ql-tags">'+tags+
          '<span class="ql-rec">'+rec+'</span></td>'+
        /* The request AND what was asked. Showing the flag without the message
           made the flag nearly useless. */
        '<td class="ql-ask">'+(l.expert
          ? '<span class="pill amber"><span class="d"></span>Requested</span>'+
            (l.expert_message? '<span class="ql-msg">'+sesc(l.expert_message)+'</span>':'')
          : '<span class="pill grey">\u2014</span>')+'</td>'+
        '<td style="font-size:11.5px;color:var(--ink-soft)">'+sesc((l.created_at||'').slice(0,10))+'</td>'+
      '</tr>';
    }).join('') : '';

    var filtered = !!(leadQuery||leadStatus||leadExpertOnly);

    document.querySelector('#content').innerHTML = qlStyle() +
      '<div class="wrap"><div class="ql-wrap">'+

      '<div class="page-head" style="margin:0"><h2>Quiz Leads</h2>'+
      '<p>Everyone who finished the skin quiz, and what to do about them.</p></div>'+

      '<div class="ql-stats">'+
        stat('Leads', (s.total||0).toLocaleString(), 'quizzes finished')+
        stat('Not yet contacted', (s.new||0).toLocaleString(), 'still marked new')+
        stat('Asked for an expert', (s.expert||0).toLocaleString(), 'waiting on a call back')+
        stat('Went on to order', (s.converted||0).toLocaleString(), (s.converted_pct||0)+'% of leads')+
      '</div>'+

      '<div class="ql-card">'+
        '<div class="ql-sec-h"><div class="ql-sec-t">Leads</div>'+
        '<div class="ql-sec-d">Newest first. Set each one\u2019s status as you work through them.</div></div>'+
        '<div class="ql-toolbar">'+
          '<input type="search" class="inp" id="qlSearch" placeholder="Search name, email or phone\u2026" value="'+sesc(leadQuery)+'">'+
          '<select class="ql-sel" id="qlStatus"><option value="">Any status</option>'+
            LEAD_STATUSES.map(function(st){
              return '<option value="'+sesc(st)+'"'+(leadStatus===st?' selected':'')+'>'+
                sesc(st.charAt(0).toUpperCase()+st.slice(1))+'</option>';
            }).join('')+'</select>'+
          '<button class="btn ghost sm'+(leadExpertOnly?' on':'')+'" id="qlExpert" aria-pressed="'+(leadExpertOnly?'true':'false')+'">'+
            (leadExpertOnly? '\u2713 ':'')+'Expert requests only</button>'+
          (filtered? '<button class="btn ghost sm" id="qlClear">Clear</button>':'')+
        '</div>'+
        (rows
          ? '<div class="ql-scroll"><table class="ql-table"><thead><tr><th>Lead</th><th>Status</th>'+
            '<th>Skin type</th><th>Concerns &amp; routine</th><th>Expert request</th><th>Date</th>'+
            '</tr></thead><tbody>'+rows+'</tbody></table></div>'+
            '<p class="ql-note">Showing '+LEADS.length.toLocaleString()+
            (filtered? ' of '+((s.total||0).toLocaleString())+' leads.' : ' leads.')+'</p>'
          : '<p class="ql-empty">'+(filtered? 'No leads match this search.' : 'No quiz submissions yet.')+'</p>')+
      '</div>'+

      '<p class="ql-note">\u201cWent on to order\u201d counts a lead whose email later placed an order that '+
      'counts as a sale. A lead who ordered under a different address is not counted, so the real number '+
      'is at least this.</p>'+

      '</div></div>';

    qlBind();
  }
  function qlBind(){
    var box=document.querySelector('#qlSearch');
    if(box){
      var t=null;
      box.oninput=function(){
        leadQuery=box.value;
        clearTimeout(t);
        t=setTimeout(async function(){
          var pos=box.selectionStart;
          await qlLoad();
          qlPaint();
          /* Focus and caret restored across the re-render. A search box that
             loses focus after the first keystroke is a search box that vanished
             from under the person using it \u2014 the exact defect found elsewhere
             in this console. */
          var again=document.querySelector('#qlSearch');
          if(again){ again.focus(); try{ again.setSelectionRange(pos,pos); }catch(e){} }
        }, 250);
      };
    }
    var st=document.querySelector('#qlStatus');
    if(st) st.onchange=function(){ leadStatus=st.value; renderQuizLeads(); };
    var ex=document.querySelector('#qlExpert');
    if(ex) ex.onclick=function(){ leadExpertOnly=!leadExpertOnly; renderQuizLeads(); };
    var cl=document.querySelector('#qlClear');
    if(cl) cl.onclick=function(){ leadQuery=''; leadStatus=''; leadExpertOnly=false; renderQuizLeads(); };

    document.querySelectorAll('#content [data-qlstatus]').forEach(function(sel){
      sel.onchange=async function(){
        if(leadBusy) return;
        leadBusy=true;
        var id=+sel.dataset.qlstatus, want=sel.value;
        var was=(LEADS.filter(function(l){ return l.id===id; })[0]||{}).status;
        try{
          await api('/admin-api/quiz-leads/'+id, {method:'PUT', body: JSON.stringify({status: want})});
          LEADS.forEach(function(l){ if(l.id===id) l.status=want; });
          if(typeof toast==='function') toast('Lead marked '+want);
        }catch(e){
          /* Put the control back to what the server still believes, rather than
             leaving the screen showing a change that did not happen. */
          sel.value = was || 'new';
          if(typeof toast==='function') toast('Could not update that lead', 'bad');
        }
        leadBusy=false;
      };
    });
  }
  window.renderQuizLeads = renderQuizLeads;

  /* ---------- Per-product Yoast SEO: live snippet + analysis + persist ---------- */
  var peSeo = {};
  function ySite(){ return (SETTINGS && SETTINGS.seo_site_name) || (SETTINGS && SETTINGS.store_name) || 'K-Beauty Bliss'; }
  function yField(root, prefix){
    var flds=root.querySelectorAll('.fld');
    for(var i=0;i<flds.length;i++){ var l=flds[i].querySelector('label');
      if(l && l.textContent.trim().toLowerCase().indexOf(prefix)===0) return flds[i].querySelector('input,textarea'); }
    return null;
  }
  function yResolve(v){
    return String(v||'').replace(/%%title%%/gi, peCtx.n||'Product').replace(/%%sitename%%/gi, ySite())
      .replace(/%%sep%%/gi, (SETTINGS&&SETTINGS.seo_separator)||'|').replace(/%%page%%/gi, '').replace(/\s+/g,' ').trim();
  }
  function yInsertAtCursor(el, txt){
    var s=el.selectionStart, e=el.selectionEnd;
    if(s==null){ el.value+=(el.value?' ':'')+txt; } else { el.value=el.value.slice(0,s)+txt+el.value.slice(e); el.selectionStart=el.selectionEnd=s+txt.length; }
    el.focus();
  }
  function ySnippet(root){
    var snip=root.querySelector('.seo-snip'); if(!snip) return;
    var t=snip.querySelector('.t'), d=snip.querySelector('.d'), u=snip.querySelector('.u');
    var slug=peSeo.slug||peCtx.slug||'';
    if(t) t.textContent = yResolve(peSeo.seo_title) || ((peCtx.n||'Product')+' | '+ySite());
    /* The preview shows what the STOREFRONT will publish, not a sentence
       invented here. The old fallback was a shop-wide guess the site has never
       emitted -- and it promised "fast UAE delivery" on a shop that ships
       across the Gulf on different terms per country. `seo_fallback_description`
       comes from App\Support\ProductSeo via the product endpoint and is the
       exact tag content the page will carry with this box empty, including the
       empty case: a product whose short description was cleared publishes no
       description at all, and the preview now shows that rather than hiding it
       behind a sentence nobody will ever see. */
    if(d) d.textContent = (peSeo.meta_description||'').trim() || (peCtx.fallbackDesc||'');
    if(u) u.textContent = 'kbeautybliss.com \u203a product \u203a '+slug;
  }
  function yCheck(status, label, msg){
    var ci = status==='good' ? '<div class="ci green">'+ic(I.check)+'</div>'
           : status==='bad'  ? '<div class="ci" style="background:#fde2e2;color:#c0392b">'+ic(ICO.ring)+'</div>'
           : '<div class="ci" style="background:#fdecd2;color:#b7791f">'+ic(ICO.ring)+'</div>';
    return '<div class="check">'+ci+'<b>'+label+'</b><small>'+msg+'</small></div>';
  }
  function yAnalysis(root){
    var box=root.querySelector('.checks'); if(!box) return;
    var kp=(peSeo.focus_keyphrase||'').trim().toLowerCase();
    var title=yResolve(peSeo.seo_title).toLowerCase();
    var meta=(peSeo.meta_description||'').trim();
    var slug=(peSeo.slug||peCtx.slug||'').toLowerCase();
    var out=[];
    if(!kp){ out.push(yCheck('warn','Focus keyphrase','Add a focus keyphrase to run the analysis')); }
    else {
      out.push(yCheck(title.indexOf(kp)>-1?'good':'bad','Keyphrase in title', title.indexOf(kp)>-1?'The focus keyphrase appears in the SEO title':'Add the keyphrase to the SEO title'));
      out.push(yCheck(meta.toLowerCase().indexOf(kp)>-1?'good':'bad','Keyphrase in meta', meta.toLowerCase().indexOf(kp)>-1?'Keyphrase found in the meta description':'Add the keyphrase to the meta description'));
      out.push(yCheck(slug.indexOf(kp.replace(/\s+/g,'-'))>-1?'good':'warn','Keyphrase in slug', slug.indexOf(kp.replace(/\s+/g,'-'))>-1?'Keyphrase is in the URL slug':'Consider adding the keyphrase to the slug'));
    }
    var ml=meta.length;
    out.push(yCheck((ml>=120&&ml<=156)?'good':(ml===0?'warn':(ml>156?'bad':'warn')),'Meta description length', ml+'/156 chars'+(ml>156?' — too long':ml>=120?' — good':ml>0?' — a little short':'')));
    var tl=yResolve(peSeo.seo_title).length;
    out.push(yCheck((tl>0&&tl<=60)?'good':(tl===0?'warn':'bad'),'SEO title length', tl+'/60 chars'+(tl>60?' — too long':tl>0?' — good':'')));
    box.innerHTML=out.join('');
  }
  function enhanceYoast(){
    var root=document.getElementById('yoastBody'); if(!root) return;
    if(yoastTab==='seo'){
      var fk=yField(root,'focus keyphrase'), st=yField(root,'seo title'), sl=yField(root,'slug'), md=yField(root,'meta description');
      // hydrate from saved peSeo, else seed peSeo from the field defaults
      [[fk,'focus_keyphrase'],[st,'seo_title'],[sl,'slug'],[md,'meta_description']].forEach(function(p){
        var el=p[0], k=p[1]; if(!el) return;
        if(peSeo[k]!=null) el.value=peSeo[k]; else peSeo[k]=el.value;
        el.addEventListener('input', function(){ peSeo[k]=el.value; ySnippet(root); yAnalysis(root); });
      });
      // variable chips → insert tokens into the SEO title
      var tokens={'Title':'%%title%%','Page':'%%page%%','Separator':'%%sep%%','Site title':'%%sitename%%'};
      root.querySelectorAll('.tagchips .tagchip').forEach(function(chip){
        chip.addEventListener('mousedown', function(e){ e.preventDefault(); });
        chip.onclick=function(){ if(!st) return; yInsertAtCursor(st, tokens[chip.textContent.trim()]||''); peSeo.seo_title=st.value; ySnippet(root); yAnalysis(root); };
      });
      ySnippet(root); yAnalysis(root);
    } else if(yoastTab==='schema'){
      var sels=root.querySelectorAll('select');
      if(sels[0]){ if(peSeo.schema_page_type) sels[0].value=peSeo.schema_page_type; sels[0].addEventListener('change', function(){ peSeo.schema_page_type=sels[0].value; }); }
      if(sels[1]){ if(peSeo.schema_product) sels[1].value=peSeo.schema_product; sels[1].addEventListener('change', function(){ peSeo.schema_product=sels[1].value; }); }
    } else if(yoastTab!=='read'){ // social
      var ot=yField(root,'facebook'), od=yField(root,'description');
      if(ot){ if(peSeo.og_title!=null) ot.value=peSeo.og_title; else peSeo.og_title=ot.value; ot.addEventListener('input', function(){ peSeo.og_title=ot.value; }); }
      if(od){ if(peSeo.og_description!=null) od.value=peSeo.og_description; od.addEventListener('input', function(){ peSeo.og_description=od.value; }); }
    }
  }
  if(typeof renderYoastBody==='function'){
    var _renderYoastBody = renderYoastBody;
    window.renderYoastBody = function(){ _renderYoastBody(); try{ enhanceYoast(); }catch(e){} };
  }

  /* ---------- Rich-text editor: real Visual/Code + toolbar ---------- */
  function rteClean(s){
    if(s==null) return '';
    return String(s)
      .replace(/\\r\\n/g,'\n').replace(/\\n/g,'\n').replace(/\\t/g,'  ')
      .replace(/\\r/g,'').replace(/\\"/g,'"').replace(/\\\//g,'/');
  }
  function rteWordCount(html){ var d=document.createElement('div'); d.innerHTML=html||''; var t=(d.textContent||'').trim(); return t? t.split(/\s+/).length : 0; }
  function enhanceRTE(){
    var rte=document.querySelector('#content .rte'); if(!rte || rte.dataset.enh) return;
    var ta=rte.querySelector('.rte-area'); if(!ta) return;
    rte.dataset.enh='1';
    ta.value = rteClean(ta.value);
    var vis=document.createElement('div');
    vis.className='rte-visual'; vis.setAttribute('contenteditable','true');
    vis.style.cssText='border:1px solid var(--border);border-top:0;border-radius:0 0 10px 10px;min-height:170px;max-height:440px;overflow:auto;padding:12px 14px;font-size:13px;line-height:1.6;background:#fff;outline:none';
    vis.innerHTML = ta.value || '';
    ta.style.display='none';                 // default to Visual
    ta.parentNode.insertBefore(vis, ta);
    var wc=rte.querySelector('.wcount span');
    function inCode(){ return ta.style.display!=='none'; }
    function updateWC(){ if(wc) wc.textContent='Word count: '+rteWordCount(inCode()?ta.value:vis.innerHTML); }
    // Visual/Code toggle
    var vcBtns=rte.querySelectorAll('.vc button');
    function setMode(code){
      if(code){ ta.value=vis.innerHTML; ta.style.display=''; vis.style.display='none'; }
      else    { vis.innerHTML=ta.value;  vis.style.display=''; ta.style.display='none'; }
      if(vcBtns[0]) vcBtns[0].classList.toggle('on', !code);
      if(vcBtns[1]) vcBtns[1].classList.toggle('on', code);
      updateWC();
    }
    if(vcBtns[0]) vcBtns[0].onclick=function(){ setMode(false); };
    if(vcBtns[1]) vcBtns[1].onclick=function(){ setMode(true); };
    // Toolbar
    rte.querySelectorAll('.rte-bar button').forEach(function(btn){
      var label=(btn.textContent||'').trim();
      btn.addEventListener('mousedown', function(e){ e.preventDefault(); });   // keep the selection
      btn.onclick=function(e){
        e.preventDefault();
        if(inCode()) return;                 // formatting only in Visual mode
        vis.focus();
        if(label==='B') document.execCommand('bold');
        else if(label==='I') document.execCommand('italic');
        else if(label==='U') document.execCommand('underline');
        else if(label.indexOf('\u2022')>-1) document.execCommand('insertUnorderedList');
        else if(label.indexOf('1.')===0) document.execCommand('insertOrderedList');
        else if(label==='\u275d') document.execCommand('formatBlock', false, 'blockquote');
        else if(label==='\ud83d\udd17'){ var u=prompt('Link URL:','https://'); if(u) document.execCommand('createLink', false, u); }
        else if(label.toLowerCase()==='align') document.execCommand('justifyCenter');
        else if(label.toLowerCase().indexOf('paragraph')===0) document.execCommand('formatBlock', false, 'p');
        updateWC();
      };
    });
    vis.addEventListener('input', updateWC);
    ta.addEventListener('input', updateWC);
    updateWC();
    rte._sync=function(){ if(!inCode()) ta.value=vis.innerHTML; };   // used by saveProduct
  }

  /* ---------- Product editor: real save of the core + detail fields ---------- */
  if(typeof openProduct==='function'){
    var _openProduct = openProduct;
    window.openProduct = function(idx){
      peSeo={};
      _openProduct(idx);
      if(idx==null || idx<0) return;                 // new-product create flow: left for a later group
      var row = CAT_PRODUCTS[idx]; if(!row) return;
      var pid = row[7];
      function fldByLabel(prefix){
        var f = Array.prototype.slice.call(document.querySelectorAll('#content #pdBody .fld'));
        for(var i=0;i<f.length;i++){
          var l=f[i].querySelector('label'), inp=f[i].querySelector('input');
          if(l&&inp&&l.textContent.trim().toLowerCase().indexOf(prefix)===0) return inp;
        }
        return null;
      }
      function boxSelect(headerPrefix){
        var boxes=document.querySelectorAll('#content .pe-box');
        for(var i=0;i<boxes.length;i++){
          var h=boxes[i].querySelector('.pe-bh');
          if(h && h.textContent.trim().toLowerCase().indexOf(headerPrefix)===0) return boxes[i].querySelector('.pe-bb select');
        }
        return null;
      }
      function selectedCategory(){
        var cbx=document.querySelector('#content .catlist .catopt .cbx.on');
        if(!cbx) return null;
        var opt=cbx.closest('.catopt'); if(!opt) return null;
        var spans=opt.querySelectorAll('span');
        for(var i=0;i<spans.length;i++){ if(/flex:1/.test(spans[i].getAttribute('style')||'')) return spans[i].textContent.trim(); }
        return null;
      }
      async function saveProduct(){
        var payload={};
        var rte=document.querySelector('#content .rte'); if(rte && rte._sync) rte._sync();   // flush Visual → textarea
        var t=document.querySelector('#content .pe-title'); if(t) payload.name=t.value.trim();
        /* Money goes over as the DECIMAL STRING the operator typed.
           This was parseInt(rp.value, 10), which reads "99.5" as 99: the editor
           loads the price from /admin-api/products, where price_aed is exact
           major units (Money::toMajor(9950) is 99.5), so opening a product at
           AED 99.50 and pressing Update saved it as AED 99 and took 50 fils off
           the price. Silent, and it compounded every time somebody opened the
           row. The read side of this same round trip was fixed once already --
           AdminController::products() says so in as many words -- and this was
           the other half of it, still truncating.
           Not parseFloat either: money is integer fils in this schema and a
           float is how 1.15 becomes 114. The server takes a decimal string,
           validates it with AdminController::MONEY_RULE and converts it by
           integer arithmetic in filsFromMajor(), so the exact digits are what
           it needs. trim() only, no reformatting: anything else is this file
           inventing a number the operator did not type. */
        var rp=fldByLabel('regular price'); if(rp && rp.value.trim()!=='') payload.price_aed=rp.value.trim();
        var sp=fldByLabel('sale price');    if(sp) payload.sale_aed = (sp.value.trim()==='') ? null : sp.value.trim();
        var skuI=fldByLabel('sku');         if(skuI) payload.sku=skuI.value.trim();          // only if the Inventory tab was opened
        var brandSel=boxSelect('brands');   if(brandSel) payload.brand=brandSel.value;
        var cat=selectedCategory();         if(cat) payload.category=cat;
        var areas=document.querySelectorAll('#content .rte-area');
        if(areas[0]) payload.description=areas[0].value;
        if(areas[1]) payload.short_description=areas[1].value;
        if(peSeo && Object.keys(peSeo).length) payload.seo=peSeo;
        try{
          await api('/admin-api/products/'+pid, {method:'PUT', body:JSON.stringify(payload)});
          if(payload.name!=null) row[0]=payload.name;
          if(payload.brand!=null) row[1]=payload.brand;
          if(payload.sku!=null) row[2]=payload.sku;
          if(payload.category!=null) row[3]=payload.category;
          if(payload.price_aed!=null) row[4]=payload.price_aed;
          if('sale_aed' in payload) row[5]=payload.sale_aed;
          toast('Product saved'); go('catalog');
        }catch(e){ toast('Save failed \u2014 check connection','bad'); }
      }
      Array.prototype.slice.call(document.querySelectorAll('#content button')).forEach(function(b){
        var txt=(b.textContent||'').trim();
        if(txt==='Update' || txt==='Publish') b.onclick=saveProduct;
      });
      // Load the REAL stored descriptions (the editor otherwise shows generated sample text,
      // which would clobber real data on save).
      api('/admin-api/products/'+pid).then(function(full){
        var areas=document.querySelectorAll('#content .rte-area');
        if(areas[0]) areas[0].value = full.description || '';
        if(areas[1]) areas[1].value = full.short_description || '';
        enhanceRTE();
        peCtx.fallbackDesc = full.seo_fallback_description || '';
        if(full.seo && typeof full.seo==='object'){ peSeo=full.seo; }
        /* Repaint UNCONDITIONALLY, which is part of the fix rather than tidying:
           this call used to sit inside the `full.seo` branch, so a product with
           no per-product SEO blob -- most of them, and every product whose
           preview is falling back in the first place -- never repainted after
           the fetch resolved, and the panel kept whatever it had drawn before
           the data arrived. */
        if(typeof yoastTab!=='undefined' && yoastTab==='seo') window.renderYoastBody();
      }).catch(function(){ enhanceRTE(); });
    };
  }

  /* ===== LANE AM · Store · Reviews · moderation — BEGIN =====================

     WHAT THIS REPLACED. A single unpaginated fetch of /admin-api/reviews that
     rendered the WHOLE table into one <table>, with:

       - no search, no rating filter and no product filter;
       - the review body cut at 140 characters with no way to read the rest, so
         a long review could not actually be moderated;
       - chip counts taken from the entire table while the list beside them was
         filtered, so the numbers answered a different question from the rows;
       - a `Rejected` chip for a status this schema does not define, and no
         `Spam` chip for the one it does — an imported spam review appeared
         under no chip at all and answered 422 when moderated;
       - and `r.reply` written into innerHTML UNESCAPED. Everything else on
         that screen went through sesc(); the reply did not. The reply is typed
         into this console by the owner, but it is also the one field an
         importer will carry across from WooCommerce, so it is public text in
         every sense that matters. That was stored XSS in the owner's own
         back-office.

     WHAT IT IS NOW. A paginated, filtered, searchable list off
     /admin-api/reviews/list — inside the guarded admin-api group, which is the
     only reason it is allowed to show the reviewer's email address at all —
     plus a per-review expansion that fetches the full text and the reviewer's
     IP from /admin-api/reviews/{id}, one-at-a-time and bulk moderation, and a
     CSV of whatever the screen is currently showing.

     THE VOCABULARY IS THE SCHEMA'S: pending | approved | spam. "Reject" writes
     `spam`, which is this schema's name for "refused, not published". See
     app/Support/ReviewStatus.php for why, and for the one-directional
     `rejected` alias that keeps an older client working.

     NO TABLE. Every row is a card that reflows, because this screen has to be
     usable at 390px and a 6-column table is not. It also reads better: the
     thing being moderated is a paragraph of text, not a spreadsheet row.

     EVERYTHING WRITTEN INTO THE PAGE GOES THROUGH sesc(). Author names, titles,
     bodies and replies are typed by the public; an unescaped one is stored XSS
     on the owner's own console. Server messages go through it too — a message
     can carry a reviewer's name. */

  var RV = {
    page: 1, perPage: 25, filter: 'all', search: '', rating: '', product: '',
    sort: 'newest', sel: {}, open: {}, full: {}, data: null, err: null, busy: false
  };

  var RV_CHIPS = [
    ['all', 'All'], ['pending', 'Waiting'], ['approved', 'Approved'], ['spam', 'Spam']
  ];

  var RV_SORTS = [
    ['newest', 'Newest first'], ['oldest', 'Oldest first'],
    ['rating_desc', 'Highest rated'], ['rating_asc', 'Lowest rated'],
    ['helpful_desc', 'Most helpful'], ['updated_desc', 'Recently moderated']
  ];

  /* The three moderation outcomes, named once so the row buttons and the bulk
     bar cannot offer different sets of actions. */
  var RV_ACTIONS = [
    ['approved', 'Approve', 'Publish this review on the storefront'],
    ['spam', 'Reject', 'Refuse it — not published, kept for the record'],
    /* "Move to queue", not "Unapprove": this button is offered on a SPAM review
       as well as an approved one, and unapproving something that was never
       approved is not a sentence. The label has to read correctly from every
       state the button appears in. */
    ['pending', 'Move to queue', 'Put it back in the queue for a decision']
  ];

  /* Styles for this screen only, rv- prefixed, injected once. Kept here rather
     than in the sheet at the top of this document because that sheet is shared
     and this region is one lane's. */
  function rvStyles(){
    if(document.getElementById('rvStyle')) return;
    var s = document.createElement('style');
    s.id = 'rvStyle';
    s.textContent =
      /* The header, then a hairline, then the controls that filter what is
         under it — the arrangement the Coupons screen settled on. */
      '.rv-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0;' +
        'margin:14px 0 12px;padding-top:14px;border-top:1px solid var(--border)}' +
      /* A select is as wide as its WIDEST OPTION and no media query can reach
         that floor, so "Any product" — whose options are whole product names —
         took half the row while the search box it sits beside was the narrowest
         thing on the screen. The three filters now share one basis and elide. */
      '.rv-tools .inp{flex:0 1 178px;min-width:0;max-width:100%;text-overflow:ellipsis}' +
      /* .rv-tools .rv-search, not .rv-search: the search box carries BOTH
         classes, and the two-class selector above would otherwise out-specify a
         single-class one and pin the search to the filters' width. Measured at
         1280: the box came out 178px and its placeholder was elided. */
      '.rv-tools .rv-search{flex:1 1 260px;min-width:0}' +
      '.rv-tools .btn{margin-left:auto}' +
      '.rv-card{border:1px solid var(--border);border-radius:11px;background:var(--surface);padding:13px 14px;margin-bottom:10px}' +
      '.rv-card.sel{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}' +
      '.rv-top{display:flex;flex-wrap:wrap;gap:9px;align-items:flex-start}' +
      '.rv-who{min-width:0;flex:1 1 200px}' +
      '.rv-nm{font-size:13.5px;font-weight:650;word-break:break-word}' +
      '.rv-meta{font-size:11.5px;color:var(--ink-soft);margin-top:2px;word-break:break-word}' +
      '.rv-badges{display:flex;flex-wrap:wrap;gap:6px;align-items:center}' +
      '.rv-body{margin-top:9px;font-size:13px;line-height:1.55;color:var(--ink-2);word-break:break-word;overflow-wrap:anywhere;white-space:pre-wrap}' +
      '.rv-ttl{font-weight:650;color:var(--ink);font-size:13px;margin-bottom:2px;word-break:break-word}' +
      '.rv-reply{margin-top:8px;padding:8px 10px;border-radius:9px;background:var(--surface-2);font-size:12.5px;color:var(--ink-2);word-break:break-word;overflow-wrap:anywhere;white-space:pre-wrap}' +
      '.rv-more{background:none;border:0;padding:0;margin-top:5px;font-size:12px;font-weight:600;color:var(--accent-ink);cursor:pointer;text-decoration:underline}' +
      '.rv-acts{display:flex;flex-wrap:wrap;gap:6px;margin-top:11px}' +
      '.rv-pii{margin-top:8px;font-size:11.5px;color:var(--ink-soft);font-family:var(--mono);word-break:break-all}' +
      '.rv-empty{padding:34px 16px;text-align:center;color:var(--ink-soft);font-size:13px}' +
      '.rv-pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:12px 2px 2px;font-size:12.5px;color:var(--ink-soft)}' +
      '@media(max-width:560px){.rv-tools .inp{flex:1 1 140px}.rv-acts .btn{flex:1 1 auto}}';
    document.head.appendChild(s);
  }

  function rvStars(n){
    var s = '';
    for(var i = 1; i <= 5; i++) s += (i <= n ? '★' : '☆');
    return '<span style="color:#e0a11e;font-size:12px;letter-spacing:1px" title="' + n + ' out of 5">' + s + '</span>';
  }

  function rvPill(status){
    var m = {approved: ['green', 'Approved'], pending: ['amber', 'Waiting'], spam: ['red', 'Spam']}[status]
      || ['grey', status];
    return '<span class="pill ' + m[0] + '"><span class="d"></span>' + sesc(m[1]) + '</span>';
  }

  function rvDate(iso){
    if(!iso) return '';
    var d = new Date(iso);
    if(isNaN(d)) return '';
    return sesc(d.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}));
  }

  function rvParams(forExport){
    var p = new URLSearchParams();
    if(!forExport){ p.set('page', RV.page); p.set('per_page', RV.perPage); }
    if(RV.filter && RV.filter !== 'all') p.set('filter', RV.filter);
    if(RV.search) p.set('search', RV.search);
    if(RV.rating) p.set('rating', RV.rating);
    if(RV.product) p.set('product_id', RV.product);
    if(RV.sort && RV.sort !== 'newest') p.set('sort', RV.sort);
    return p.toString();
  }

  function rvSelected(){
    return Object.keys(RV.sel).filter(function(k){ return RV.sel[k]; }).map(Number);
  }

  async function rvLoad(){
    if(RV.busy) return;
    RV.busy = true;
    try{
      RV.data = await api('/admin-api/reviews/list?' + rvParams(false));
      RV.err = null;
    }catch(e){
      RV.data = null;
      /* Say WHAT failed, not what might have. Fetch the same URL plainly so the
         status and the server's own message can be shown — a guess at the cause
         sends whoever reads it looking in the wrong place. */
      RV.err = {status: 0, body: ''};
      try{
        var probe = await fetch(fixAdminApiUrl('/admin-api/reviews/list?' + rvParams(false)),
          {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        RV.err.status = probe.status;
        RV.err.body = (await probe.text() || '').slice(0, 400);
      }catch(e2){}
    }finally{
      RV.busy = false;
    }
  }

  async function renderReviews(){
    rvStyles();
    await rvLoad();

    var d = RV.data;
    var counts = (d && d.counts) || {};
    var rows = (d && d.reviews) || [];
    var products = (d && d.products) || [];

    /* "All Reviews", not "Reviews". The sidebar row and the bar above #content
       both say All Reviews; a heading that says something else is the defect
       AdminNavAndIdsTest was written for, one level further in. */
    var head =
      '<div class="page-head"><h2>All Reviews</h2>' +
      '<p>Only approved reviews reach shoppers, and only approved reviews count towards a product’s score.</p></div>';

    if(RV.err){
      document.querySelector('#content').innerHTML =
        '<div class="wrap">' + head +
        '<div class="card pad"><b style="font-size:13.5px">The reviews list could not be loaded.</b>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:6px">' +
        'GET /admin-api/reviews/list answered ' + sesc(String(RV.err.status || 'no response')) + '.' +
        (RV.err.status === 404 ? ' That route is not mounted yet — routes/reviews-admin.php still has to be required from routes/web.php.' : '') +
        '</p>' +
        (RV.err.body ? '<pre style="margin-top:9px;font-size:11.5px;white-space:pre-wrap;word-break:break-word;color:var(--ink-soft)">' + sesc(RV.err.body) + '</pre>' : '') +
        '</div></div>';
      return;
    }

    var sel = rvSelected();

    var toolbar =
      '<div class="rv-tools">' +
        '<input class="inp rv-search" id="rvSearch" type="search" placeholder="Search name, email, title, text or product…" value="' + sesc(RV.search) + '">' +
        '<select class="inp" id="rvRating"><option value="">Any rating</option>' +
          [5, 4, 3, 2, 1].map(function(r){
            return '<option value="' + r + '"' + (String(RV.rating) === String(r) ? ' selected' : '') + '>' + r + ' star' + (r === 1 ? '' : 's') + '</option>';
          }).join('') +
        '</select>' +
        '<select class="inp" id="rvProduct"><option value="">Any product</option>' +
          '<option value="business"' + (RV.product === 'business' ? ' selected' : '') + '>About the shop</option>' +
          products.map(function(p){
            return '<option value="' + p.id + '"' + (String(RV.product) === String(p.id) ? ' selected' : '') + '>' + sesc(p.name) + ' (' + p.reviews + ')</option>';
          }).join('') +
        '</select>' +
        '<select class="inp" id="rvSort">' +
          RV_SORTS.map(function(s){
            return '<option value="' + s[0] + '"' + (RV.sort === s[0] ? ' selected' : '') + '>' + sesc(s[1]) + '</option>';
          }).join('') +
        '</select>' +
        '<button class="btn ghost sm" id="rvExport">Export CSV</button>' +
      '</div>';

    var chips =
      '<div class="chips" style="margin:0 0 13px">' +
      RV_CHIPS.map(function(c){
        var n = counts[c[0]];
        return '<button class="chip' + (RV.filter === c[0] ? ' on' : '') + '" data-rvf="' + c[0] + '">' +
          sesc(c[1]) + (n == null ? '' : ' · ' + n) + '</button>';
      }).join('') + '</div>';

    var bulk = '';
    if(sel.length){
      bulk = '<div class="bulkbar" style="flex-wrap:wrap">' +
        '<span class="cbx on" id="rvClear" title="Clear selection">' + ic(I.check) + '</span>' +
        '<span>' + sel.length + ' selected</span><div style="flex:1 1 40px"></div>' +
        RV_ACTIONS.map(function(a){
          return '<button class="btn ghost sm" data-rvbulk="' + a[0] + '" title="' + sesc(a[2]) + '">' + sesc(a[1]) + '</button>';
        }).join('') +
        '<button class="btn danger sm" data-rvbulk="delete" title="Remove these reviews permanently">Delete</button>' +
        '</div>';
    }

    var list = rows.length
      ? rows.map(rvCard).join('')
      : '<div class="card"><div class="rv-empty">Nothing here.' +
        (RV.filter !== 'all' || RV.search || RV.rating || RV.product
          ? ' No review matches the filters above.'
          : ' No reviews have been left yet.') + '</div></div>';

    var total = (d && d.total) || 0;
    var pages = (d && d.pages) || 1;
    var from = total ? ((RV.page - 1) * RV.perPage) + 1 : 0;
    var to = Math.min(total, RV.page * RV.perPage);

    var pager =
      '<div class="rv-pager">' +
        '<span>' + (total ? ('Showing ' + from + '–' + to + ' of ' + total) : 'Nothing to show') + '</span>' +
        '<span class="row" style="gap:6px">' +
          '<button class="btn ghost sm" id="rvPrev"' + (RV.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
          '<span>Page ' + RV.page + ' of ' + pages + '</span>' +
          '<button class="btn ghost sm" id="rvNext"' + (RV.page >= pages ? ' disabled' : '') + '>Next</button>' +
        '</span>' +
      '</div>';

    document.querySelector('#content').innerHTML =
      '<div class="wrap">' + head + toolbar + chips +
      '<div id="rvBulk">' + bulk + '</div>' + list + pager + '</div>';

    rvBind();
  }

  /* One review. Everything interpolated here is public text; every one of them
     goes through sesc(), the reply included — that was the hole. */
  function rvCard(r){
    var open = !!RV.open[r.id];
    var full = RV.full[r.id];
    var text = open && full != null ? full : r.excerpt;

    var product = r.product
      ? sesc(r.product)
      : (r.product_id == null ? 'About the shop' : 'Product #' + r.product_id);

    var pii = open && full != null && RV.full['ip_' + r.id] != null
      ? '<div class="rv-pii">IP ' + sesc(RV.full['ip_' + r.id]) + '</div>'
      : '';

    return '<div class="rv-card' + (RV.sel[r.id] ? ' sel' : '') + '">' +
      '<div class="rv-top">' +
        '<span class="cbx' + (RV.sel[r.id] ? ' on' : '') + '" data-rvsel="' + r.id + '">' + ic(I.check) + '</span>' +
        '<div class="rv-who">' +
          '<div class="rv-nm">' + sesc(r.author || 'Anonymous') + '</div>' +
          '<div class="rv-meta">' + sesc(r.author_email || '') +
            (r.author_email ? ' · ' : '') + product +
            (r.created_at ? ' · ' + rvDate(r.created_at) : '') +
            /* LANE DO: demo rows stay visible here and are marked, the same way
               Orders and Customers mark theirs. The storefront excludes them
               from every figure; this list is where the owner finds them to
               delete. The API sends is_demo per row; without this the marking
               exists and nobody can see it. */
            (r.is_demo ? ' · <span style="color:var(--ink-faint)">demo</span>' : '') + '</div>' +
        '</div>' +
        '<div class="rv-badges">' + rvStars(r.rating) + rvPill(r.status) +
          (r.verified ? '<span class="pill green" title="Bought this product">✓ Verified</span>' : '') +
          (r.helpful ? '<span class="pill grey">' + r.helpful + ' helpful</span>' : '') +
        '</div>' +
      '</div>' +
      '<div class="rv-body">' +
        (r.title ? '<div class="rv-ttl">' + sesc(r.title) + '</div>' : '') +
        sesc(text) + (r.truncated && !open ? '…' : '') +
      '</div>' +
      ((r.truncated || r.author_email)
        ? '<button class="rv-more" data-rvopen="' + r.id + '">' +
            (open ? 'Show less' : (r.truncated ? 'Read the whole review (' + r.length + ' characters)' : 'Show details')) +
          '</button>'
        : '') +
      pii +
      (r.reply ? '<div class="rv-reply"><b>Your reply:</b> ' + sesc(r.reply) + '</div>' : '') +
      '<div class="rv-acts">' +
        RV_ACTIONS.filter(function(a){ return a[0] !== r.status; }).map(function(a){
          return '<button class="btn ghost sm" data-rvact="' + a[0] + '" data-rvid="' + r.id + '" title="' + sesc(a[2]) + '">' + sesc(a[1]) + '</button>';
        }).join('') +
        '<button class="btn ghost sm" data-rvreply="' + r.id + '">' + (r.reply ? 'Edit reply' : 'Reply') + '</button>' +
      '</div>' +
    '</div>';
  }

  function rvBind(){
    var q = function(s){ return document.querySelectorAll('#content ' + s); };

    q('.chip[data-rvf]').forEach(function(c){
      c.onclick = function(){ RV.filter = c.dataset.rvf; RV.page = 1; RV.sel = {}; renderReviews(); };
    });

    q('[data-rvsel]').forEach(function(c){
      c.onclick = function(){
        var id = c.dataset.rvsel;
        RV.sel[id] = !RV.sel[id];
        renderReviews();
      };
    });

    q('[data-rvact]').forEach(function(b){
      b.onclick = function(){ rvModerate(+b.dataset.rvid, b.dataset.rvact); };
    });

    q('[data-rvopen]').forEach(function(b){
      b.onclick = function(){ rvToggle(+b.dataset.rvopen); };
    });

    q('[data-rvreply]').forEach(function(b){
      b.onclick = function(){ rvReply(+b.dataset.rvreply); };
    });

    q('[data-rvbulk]').forEach(function(b){
      b.onclick = function(){ rvBulk(b.dataset.rvbulk); };
    });

    var clear = document.getElementById('rvClear');
    if(clear) clear.onclick = function(){ RV.sel = {}; renderReviews(); };

    var prev = document.getElementById('rvPrev');
    if(prev) prev.onclick = function(){ if(RV.page > 1){ RV.page--; renderReviews(); } };

    var next = document.getElementById('rvNext');
    if(next) next.onclick = function(){
      if(RV.data && RV.page < RV.data.pages){ RV.page++; renderReviews(); }
    };

    var rating = document.getElementById('rvRating');
    if(rating) rating.onchange = function(){ RV.rating = rating.value; RV.page = 1; renderReviews(); };

    var product = document.getElementById('rvProduct');
    if(product) product.onchange = function(){ RV.product = product.value; RV.page = 1; renderReviews(); };

    var sort = document.getElementById('rvSort');
    if(sort) sort.onchange = function(){ RV.sort = sort.value; RV.page = 1; renderReviews(); };

    var search = document.getElementById('rvSearch');
    if(search){
      /* Debounced, and the caret is restored after the re-render: typing into a
         box that re-renders on every keystroke otherwise jumps to the start of
         the field after the first character. */
      var timer = null;
      search.oninput = function(){
        clearTimeout(timer);
        var at = search.selectionStart;
        timer = setTimeout(function(){
          RV.search = search.value;
          RV.page = 1;
          renderReviews().then(function(){
            var again = document.getElementById('rvSearch');
            if(again){ again.focus(); try{ again.setSelectionRange(at, at); }catch(e){} }
          });
        }, 300);
      };
    }

    var exp = document.getElementById('rvExport');
    if(exp) exp.onclick = async function(){
      /* A navigation of the WHOLE CONSOLE, gated first. (Lane SEC) The other
         four exports carry a comment saying why this is a navigation and not a
         fetch; this one never did, so it is said here: the browser carries the
         admin session cookie, the response is streamed, and the file lands in
         Downloads instead of in the tab's memory. */
      var url = fixAdminApiUrl('/admin-api/reviews/export?' + rvParams(true));
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
  }

  /* Read the whole review. The list deliberately carries only an excerpt, so
     this is a real fetch — and it is also where the reviewer's IP comes from,
     which the list does not carry at all. */
  async function rvToggle(id){
    if(RV.open[id]){ RV.open[id] = false; return renderReviews(); }
    RV.open[id] = true;
    if(RV.full[id] == null){
      try{
        var d = await api('/admin-api/reviews/' + id);
        RV.full[id] = (d.review && d.review.content) || '';
        RV.full['ip_' + id] = (d.review && d.review.ip) || '';
      }catch(e){
        RV.full[id] = '';
        toast('Could not load the full review', 'bad');
      }
    }
    renderReviews();
  }

  async function rvModerate(id, status){
    try{
      var d = await api('/admin-api/reviews/' + id + '/moderate',
        {method: 'PUT', body: JSON.stringify({status: status})});
      /* The server says what it STORED, which is not always what was asked for
         — `rejected` is accepted and normalised to `spam`. Reporting the
         server's answer rather than the request means the toast can never claim
         a status the table does not hold. */
      toast('Review ' + (d.status === 'spam' ? 'rejected' : d.status === 'approved' ? 'approved' : 'moved back to the queue'));
      renderReviews();
    }catch(e){ toast('That change could not be saved','bad'); }
  }

  async function rvBulk(action){
    var ids = rvSelected();
    if(!ids.length) return;

    if(action === 'delete'){
      return rvConfirmDelete(ids);
    }

    try{
      var d = await api('/admin-api/reviews/bulk-moderate',
        {method: 'POST', body: JSON.stringify({action: action, ids: ids})});
      /* "3 of 5" rather than "5", because the endpoint leaves rows that already
         hold the target status alone and saying otherwise would claim a change
         that did not happen. */
      toast(d.affected === d.requested
        ? (d.affected + ' review' + (d.affected === 1 ? '' : 's') + ' updated')
        : (d.affected + ' of ' + d.requested + ' updated — the rest were already there'));
      RV.sel = {};
      renderReviews();
    }catch(e){ toast('That bulk action could not be saved','bad'); }
  }

  function rvConfirmDelete(ids){
    openModal('<div class="modal-h"><b>Delete ' + ids.length + ' review' + (ids.length === 1 ? '' : 's') + '</b>' +
      '<button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">This removes the ' +
      (ids.length === 1 ? 'review' : 'reviews') + ' permanently and cannot be undone. ' +
      'To take a review off the storefront without destroying it, use <b>Reject</b> instead — it stays here for the record.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px;flex-wrap:wrap">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn danger" id="rvDelYes">Delete permanently</button></div></div>');

    var yes = document.getElementById('rvDelYes');
    if(yes) yes.onclick = async function(){
      closeModal();
      try{
        var d = await api('/admin-api/reviews/bulk-moderate',
          {method: 'POST', body: JSON.stringify({action: 'delete', ids: ids})});
        toast(d.affected + ' review' + (d.affected === 1 ? '' : 's') + ' deleted');
        RV.sel = {};
        renderReviews();
      }catch(e){ toast('Those reviews could not be deleted','bad'); }
    };
  }

  function rvReply(id){
    var r = ((RV.data && RV.data.reviews) || []).filter(function(x){ return x.id === id; })[0];
    if(!r) return;

    openModal('<div class="modal-h"><b>Reply to ' + sesc(r.author || 'this reviewer') + '</b>' +
      '<button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b">' +
      '<div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:8px">' + rvStars(r.rating) + ' · ' + sesc(r.title || '') + '</div>' +
      '<textarea class="inp" id="rvReplyTxt" style="width:100%;min-height:96px" placeholder="Shown publicly under the review on the product page…">' + sesc(r.reply || '') + '</textarea>' +
      /* The placeholder promises publication, and now the shop delivers it.
         For a long time it did not: the admin stored a reply, showed it here
         and exported it, while Store\ProductController never SELECTed the
         column and partials/reviews.blade.php never printed it — so no reply
         had ever been seen by a shopper. A lane proved that against a real
         render and replaced this line with an apology; the storefront half
         landed in the same package, so the apology goes and the promise comes
         back. ReviewScreensRepaintTest fails if the two drift apart again. */
      '<p style="font-size:11.5px;color:var(--ink-soft);margin:7px 0 0;line-height:1.45">' +
      'Shown under the review on the product page, saved with it, and included ' +
      'in both CSV exports.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px;flex-wrap:wrap">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" id="rvReplySave">Save reply</button></div></div>');

    var save = document.getElementById('rvReplySave');
    if(save) save.onclick = async function(){
      var box = document.getElementById('rvReplyTxt');
      try{
        await api('/admin-api/reviews/' + id + '/moderate',
          {method: 'PUT', body: JSON.stringify({reply: box ? box.value : ''})});
        toast('Reply saved');
        closeModal();
        renderReviews();
      }catch(e){ toast('That reply could not be saved','bad'); }
    };
  }

  /* A bookmark straight to this screen — /admin?go=rev-all, or #rev-all. That
     navigation is performed by the boot block at the end of the FIRST script in
     this document, which runs before this one exists, so go() lands on
     renderReviewFrame() and draws an iframe pointing at a file this repo does
     not ship. Nothing has painted yet at this point in parsing, so re-rendering
     here is not a flicker: it is the first thing the browser draws. */
  if(typeof cur !== 'undefined' && cur === 'rev-all'){ renderReviews(); }
  /* ===== LANE AM · Store · Reviews · moderation — END ====================== */


  /* ---------- Analytics (derived from real orders) -------------------------
     Rebuilt to the standard the Coupons screen sets: titled sections with a
     one-line description, one thing to read per row, and no figure on the page
     whose definition is not stated somewhere the owner can see it.

     WHAT WAS NUMERICALLY WRONG, and is fixed in AdminController::analytics():

       Revenue counted partially refunded orders at their FULL total.
       PaymentRefunder moves an order to 'refunded' only on a full refund, so a
       partial one left the order 'completed' and the money it gave back stayed
       in Revenue, in the average order value and in the chart for ever. Net and
       gross are both shown now, with the refunded figure between them.

       Top products' revenue was SUM(unit_price * quantity) \u2014 the LIST price \u2014
       so a product only ever sold at a discount reported money the store never
       took, and this table could not be reconciled with the KPI above it. It
       reads order_items.total now.

       The chart had no scale of any kind: no axis, no peak, no number anywhere
       on the card. Fourteen days of AED 90 drew exactly the same picture as
       fourteen days of AED 90,000. The peak and the window total are printed.

       The description claimed revenue "counts processing, on-hold and completed
       orders" \u2014 three of the four statuses in Order::REAL_STATUSES. It was
       silently missing `shipped`, which on this shop is where orders sit for
       most of their life. The endpoint now sends the list and the screen prints
       it, so the sentence cannot drift out of step with the query again.

     The grid was `repeat(4,1fr)`, which cannot go below four tracks: at 390px
     the tiles overflowed #content sideways. auto-fit with a min() floor stacks
     instead \u2014 the same rule the Coupons screen documents at length. --------- */
  var AN_CSS_ID='an-analytics-css';

  /* ---------------------------------------------------------------------------
     THE STYLESHEET GOES IN <head>, NOT INTO #content.

     It used to be returned as a '<style>...' string and concatenated onto the
     screen's markup, guarded by "if the tag already exists, return nothing".
     Those two halves fight each other the moment a screen re-renders itself,
     which is exactly what the date filter made this screen do:

       render 1  tag absent  -> string returned, tag lands INSIDE #content
       render 2  tag present -> '' returned, and innerHTML= wipes #content,
                                taking the tag with it
       render 3  tag absent  -> back it comes

     So every other press of a filter button drew the screen with NO Analytics
     CSS at all: no card padding, and `.an-scroll` losing overflow-x:auto, which
     put the status table 4px past the edge of #content at 390px. It alternated,
     which is the worst way for a defect to present — it looks like a rendering
     glitch rather than a bug.

     Appending to document.head instead survives any number of re-renders, and
     anStyle() still returns '' so the call sites read the same.
     ------------------------------------------------------------------------ */
  function anStyle(){
    if(document.getElementById(AN_CSS_ID)) return '';
    var tag=document.createElement('style');
    tag.id=AN_CSS_ID;
    tag.textContent=anCss();
    document.head.appendChild(tag);
    return '';
  }
  function anCss(){
    return ''+
      '.an-wrap{display:grid;gap:18px;min-width:0}.an-wrap>*{min-width:0}'+
      '.an-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}'+
      '.an-sec-h{display:grid;gap:3px;min-width:0;margin-bottom:14px}'+
      '.an-sec-t{font-size:13.5px;font-weight:650}'+
      '.an-sec-d{font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280);max-width:78ch}'+
      /* ---------------------------------------------------------------- filter
         The date control. A segmented row of buttons rather than a <select>,
         because the owner switches between these six constantly and a select
         costs two taps and hides the other five options while it is open.
         flex-wrap, not a grid: six labels of very different widths ("Today" vs
         "All time") in fixed tracks leaves ragged holes at 1280 and overflows
         at 390. --------------------------------------------------------- */
      '.an-filter{display:grid;gap:10px;min-width:0}'+
      '.an-seg{display:flex;flex-wrap:wrap;gap:6px;min-width:0}'+
      '.an-seg button{appearance:none;cursor:pointer;font:inherit;font-size:12.5px;font-weight:600;'+
        'padding:7px 12px;border-radius:999px;border:1px solid var(--border,#e6e6e6);'+
        'background:var(--surface,#fff);color:var(--ink,#111);line-height:1.2;white-space:nowrap}'+
      '.an-seg button:hover{background:rgba(127,127,127,.08)}'+
      '.an-seg button.is-on{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}'+
      '.an-seg button:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}'+
      /* The custom picker. Its own row so opening it never reflows the buttons
         above, and auto-fit so the two dates and the button stack on a phone
         instead of pushing #content sideways. */
      '.an-custom{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:10px;'+
        'align-items:end;min-width:0;padding-top:2px}'+
      '.an-custom>*{min-width:0}'+
      '.an-custom label{display:block;font-size:11px;font-weight:650;text-transform:uppercase;'+
        'letter-spacing:.05em;color:var(--ink-soft,#6b7280);margin-bottom:4px}'+
      '.an-custom input{width:100%;box-sizing:border-box;font:inherit;font-size:13px;padding:8px 10px;'+
        'border-radius:var(--r,12px);border:1px solid var(--border,#e6e6e6);'+
        'background:var(--surface,#fff);color:var(--ink,#111)}'+
      '.an-custom button{appearance:none;cursor:pointer;font:inherit;font-size:13px;font-weight:600;'+
        'padding:9px 14px;border-radius:var(--r,12px);border:1px solid var(--accent,#15a85a);'+
        'background:var(--accent,#15a85a);color:#fff}'+
      '.an-hint{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;margin:0}'+
      '.an-bad{font-size:11.5px;color:#b4443c;line-height:1.45}'+
      /* The period, repeated on every card. See the comment above anCardHead():
         a screenshot of this page has to say what it covers without anyone
         remembering which button was pressed. */
      '.an-chip{display:inline-block;align-self:start;max-width:100%;font-size:11px;font-weight:650;'+
        'letter-spacing:.03em;padding:3px 9px;border-radius:999px;margin-bottom:2px;'+
        'background:rgba(127,127,127,.12);color:var(--ink-soft,#6b7280);overflow-wrap:anywhere}'+
      '.an-busy{opacity:.45;pointer-events:none}'+
      /* auto-fit + min() floor: a fixed minmax(150px,1fr) still demands 150px a
         track, so four tiles plus gaps overflow a 390px phone. */
      '.an-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(160px,100%),1fr));gap:12px;min-width:0}'+
      '.an-stats>*{min-width:0}'+
      '.an-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:13px 15px;min-width:0}'+
      /* Caption above the figure: the eye reads the small label first and then
         has something to hang the number on. */
      '.an-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.05em}'+
      '.an-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px;overflow-wrap:anywhere}'+
      '.an-stat i{display:block;font-style:normal;font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:3px;line-height:1.4}'+
      '.an-stat.is-out b{color:#b4443c}'+
      /* The chart. A flex row of columns rather than one stretched SVG: the old
         one used preserveAspectRatio="none" on a 100x100 box, so every bar's
         corner radius was smeared to a different shape by the aspect ratio. */
      '.an-chart{display:flex;align-items:flex-end;gap:3px;height:150px;min-width:0;padding-top:4px;'+
        'border-bottom:1px solid var(--border,#e6e6e6)}'+
      '.an-chart.is-dense{gap:1px}'+
      '.an-bar{flex:1 1 0;min-width:0;display:flex;align-items:flex-end;height:100%;border-radius:4px 4px 0 0}'+
      '.an-bar i{display:block;width:100%;background:var(--accent,#15a85a);border-radius:4px 4px 0 0;min-height:2px}'+
      /* A day with no sales gets a visible trough, not an invisible one: an
         empty column and a missing column must not look alike. */
      '.an-bar.is-zero i{background:rgba(127,127,127,.20)}'+
      /* A bucket that has not happened yet, drawn FULL HEIGHT and hatched.
         The back half of an in-progress month drawn as a flat run of zeroes
         reads as trade collapsing, which is the "chart implying a trend that is
         not there" this card already had to fix once — and drawn as nothing at
         all it reads as a chart that stops early for no stated reason. A faint
         full-height hatch says "this part of the period is still to come",
         which is the only honest thing the space can say. */
      '.an-bar.is-future i{height:100%!important;border-radius:4px 4px 0 0;'+
        'background:repeating-linear-gradient(135deg,rgba(127,127,127,.16) 0 3px,transparent 3px 7px)}'+
      '.an-xaxis{display:flex;gap:3px;margin-top:6px;min-width:0}'+
      '.an-xaxis.is-dense{gap:1px}'+
      /* overflow:VISIBLE, not hidden.
         One tick per bar, but only every nth carries a date, so a labelled tick
         is as narrow as its bar — about 6px on a phone with a year of weekly
         buckets — and "29 Dec" needs thirty-odd. Clipped to the tick it read
         "29 ", "2 M", "4 M": a row of truncated dates, which is worse than no
         axis at all. The unlabelled ticks either side are empty, so letting a
         label spill into them costs nothing and collides with nothing; the step
         in renderAnalytics() keeps labels several ticks apart. The two ends
         align inwards so neither hangs off the card. */
      '.an-xaxis span{flex:1 1 0;min-width:0;text-align:center;font-size:10px;color:var(--ink-soft,#6b7280);'+
        'font-variant-numeric:tabular-nums;overflow:visible;white-space:nowrap}'+
      '.an-xaxis span:first-child{text-align:left}'+
      '.an-xaxis span:last-child{text-align:right}'+
      '.an-scale{display:flex;flex-wrap:wrap;gap:4px 14px;justify-content:space-between;margin-bottom:8px;'+
        'font-size:11.5px;color:var(--ink-soft,#6b7280)}'+
      '.an-scale b{font-variant-numeric:tabular-nums;color:inherit}'+
      '.an-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;max-width:100%}'+
      '.an-table{width:100%;border-collapse:collapse;font-size:13px}'+
      '.an-table th,.an-table td{text-align:left;padding:10px;border-bottom:1px solid var(--border,#e6e6e6);vertical-align:middle;white-space:nowrap}'+
      '.an-table th{font-weight:600;color:var(--ink-soft,#6b7280);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em}'+
      '.an-table td.an-num,.an-table th.an-num{text-align:right;font-variant-numeric:tabular-nums}'+
      '.an-table tbody tr:hover{background:rgba(127,127,127,.06)}'+
      /* Brand rides under the product name: one thing to read per row, and the
         table keeps its columns on a laptop. */
      '.an-pname{font-weight:600;white-space:normal;max-width:34ch}'+
      '.an-pbrand{display:block;font-size:11.5px;font-weight:400;color:var(--ink-soft,#6b7280);margin-top:2px}'+
      '.an-meter{display:block;position:relative;height:5px;min-width:60px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden}'+
      '.an-meter i{position:absolute;inset:0 auto 0 0;border-radius:999px;background:var(--accent,#15a85a)}'+
      '.an-empty{padding:30px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}'+
      '.an-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;max-width:72ch;margin-top:12px}';
  }
  function anMoney(v){ return 'AED ' + (Number(v)||0).toLocaleString(); }
  /* A human list: "processing, on-hold, shipped and completed". Built from what
     the endpoint says it summed, so this sentence cannot go stale. */
  function anList(items){
    var xs=(items||[]).map(function(s){ return String(s).replace('onhold','on-hold'); });
    if(!xs.length) return '';
    if(xs.length===1) return xs[0];
    return xs.slice(0,-1).join(', ') + ' and ' + xs[xs.length-1];
  }

  /* ------------------------------------------------------------ the filter ---
     The six periods, spelled here as well as on the server.

     AnalyticsRange::PERIODS is the authority — it decides what the endpoint
     will accept and refuses anything else with a 422 — but the control has to
     draw itself before any answer has come back, and has to keep working when
     one does not come back at all. So the labels live here and the KEYS are the
     ones the endpoint validates against; a key added on one side and not the
     other fails loudly rather than rendering a button that 422s.

     WHAT THE WEEK MEANS. Monday to Sunday. The UAE moved its weekend to
     Saturday-Sunday on 1 January 2022, so the working week runs Monday to
     Friday and the calendar week runs Monday to Sunday, the same as ISO-8601.
     The screen PRINTS the two dates beside the button, because the one way this
     goes wrong is silently. ------------------------------------------------ */
  var AN_PERIODS=[
    ['all','All time','Every order the shop has ever taken'],
    ['today','Today','Midnight to midnight'],
    ['week','This week','Monday to Sunday'],
    ['month','This month','The calendar month, not the last 30 days'],
    ['year','This year','1 January to 31 December'],
    ['custom','Custom range','Two dates you choose, both days whole']
  ];

  /* The chosen filter, kept across re-renders of the screen. */
  var AN={ period:'all', from:'', to:'', error:'' };

  function anQuery(){
    var q='?period='+encodeURIComponent(AN.period);
    if(AN.period==='custom') q+='&from='+encodeURIComponent(AN.from||'')+'&to='+encodeURIComponent(AN.to||'');
    return q;
  }

  /* A card header that always says which period it is describing.

     Every box on this page repeats the range. That is deliberate repetition: a
     screenshot of Best sellers pasted into WhatsApp has to be readable on its
     own, and "the eight products that brought in the most" means nothing
     without the dates it covers. */
  function anCardHead(title, desc, period){
    var chip = period ? '<span class="an-chip">'+sesc(period.label)+' · '+sesc(period.range_label)+'</span>' : '';
    return '<div class="an-sec-h">'+chip+'<div class="an-sec-t">'+sesc(title)+'</div>'+
      '<div class="an-sec-d">'+desc+'</div></div>';
  }

  function anFilterBar(){
    var seg=AN_PERIODS.map(function(p){
      /* title, so "This week" can say WHICH week without a sentence of copy
         beside every button. The UAE moved its weekend to Saturday-Sunday in
         2022, so the week here runs Monday to Sunday — a decision that is only
         dangerous if it is silent, and the chosen range's actual dates are
         printed on every card below as well. */
      return '<button type="button" data-an-period="'+sesc(p[0])+'" title="'+sesc(p[2])+'"'+
        (AN.period===p[0]?' class="is-on" aria-pressed="true"':' aria-pressed="false"')+'>'+sesc(p[1])+'</button>';
    }).join('');

    /* The hint for whatever is selected, spelled out under the buttons rather
       than left on a hover nobody on a phone can perform. */
    var hint=(AN_PERIODS.filter(function(p){ return p[0]===AN.period; })[0]||[,,''])[2];

    var custom = AN.period==='custom'
      ? '<div class="an-custom">'+
          '<div><label for="anFrom">From</label><input id="anFrom" type="date" value="'+sesc(AN.from)+'"></div>'+
          '<div><label for="anTo">To</label><input id="anTo" type="date" value="'+sesc(AN.to)+'"></div>'+
          '<div><button type="button" id="anApply">Show this range</button></div>'+
        '</div>'
      : '';

    return '<div class="an-card"><div class="an-filter">'+
      '<div class="an-seg" role="group" aria-label="Period">'+seg+'</div>'+
      (hint? '<p class="an-hint">'+sesc(hint)+'</p>' : '')+
      custom+
      (AN.error? '<p class="an-bad">'+sesc(AN.error)+'</p>' : '')+
    '</div></div>';
  }

  /* Wire the control up after each render. Delegation would survive re-renders
     for free, but #content is replaced wholesale by every other screen in this
     console and a listener left on it would fire on theirs. */
  function anBind(){
    var root=document.querySelector('#content');
    if(!root) return;

    root.querySelectorAll('[data-an-period]').forEach(function(b){
      b.addEventListener('click', function(){
        var k=b.getAttribute('data-an-period');
        AN.error='';
        if(k==='custom'){
          /* Opening the picker must not fire a request with two empty dates —
             that is the 422. Default it to the last 30 days so the first thing
             the owner sees is a real range they can adjust. */
          AN.period='custom';
          if(!AN.from || !AN.to){
            var to=new Date(), from=new Date(); from.setDate(from.getDate()-29);
            AN.to=anIsoDay(to); AN.from=anIsoDay(from);
          }
        } else {
          AN.period=k;
        }
        renderAnalytics();
      });
    });

    var apply=root.querySelector('#anApply');
    if(apply) apply.addEventListener('click', function(){
      var f=root.querySelector('#anFrom'), t=root.querySelector('#anTo');
      AN.from=(f&&f.value)||''; AN.to=(t&&t.value)||'';
      if(!AN.from || !AN.to){ AN.error='Pick both a start and an end date.'; renderAnalytics(); return; }
      AN.error='';
      renderAnalytics();
    });
  }

  function anIsoDay(d){
    /* Local Y-m-d. toISOString() would convert to UTC first and hand back
       yesterday for anyone east of Greenwich, which is every user of this
       shop. */
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  }

  async function renderAnalytics(){
    var content=document.querySelector('#content');
    var a;
    try{
      a=await api('/admin-api/analytics'+anQuery());
    }catch(e){
      a=null;
      /* A 422 means the dates were refused, not that analytics is broken, and
         saying "could not load" for a typo sends the owner looking for a fault
         that is not there. */
      if(String(e&&e.message||'').indexOf('422')!==-1){
        AN.error='That date range was not accepted. Check both dates and try again.';
      }
    }

    if(!a){
      content.innerHTML = anStyle() +
        '<div class="wrap"><div class="an-wrap">'+
        '<div class="page-head" style="margin:0"><h2>Sales Report</h2>'+
        '<p>How the shop is trading, worked out from your real orders.</p></div>'+
        anFilterBar()+
        '<div class="an-card"><p class="an-empty">'+
        (AN.error? 'Nothing is wrong with your data — the range above could not be read.'
                 : 'Could not load analytics. Nothing is wrong with your data — the figures could not be read just now.')+
        '<br><button class="btn" style="margin-top:14px" id="anRetry">Try again</button></p></div>'+
        '</div></div>';
      anBind();
      var retry=content.querySelector('#anRetry');
      if(retry) retry.addEventListener('click', function(){ AN.error=''; renderAnalytics(); });
      return;
    }

    AN.error='';
    var period=a.period||{key:'all',label:'All time',range_label:'every order the shop has ever taken'};

    /* Keep the control in step with what the server actually answered: it
       swaps a backwards custom range, so the inputs should show the dates the
       figures were computed from, not the ones that were typed. */
    AN.period=period.key||AN.period;
    if(period.key==='custom'){ AN.from=period.from||AN.from; AN.to=period.to||AN.to; }

    function stat(label,val,sub,cls,note){
      /* LANE DU: `note` is help text on hover, taken from the endpoint's own
         revenue_basis rather than written here, so a card cannot describe a
         figure differently from the thing that sums it. */
      return '<div class="an-stat'+(cls?' '+cls:'')+'"'+(note?' title="'+sesc(note)+'"':'')+'><span>'+sesc(label)+'</span><b>'+sesc(String(val))+'</b>'+
        (sub?'<i>'+sesc(sub)+'</i>':'')+'</div>';
    }

    var counted = anList(a.revenue_statuses);
    var inPeriod = period.key==='all' ? 'in the whole of the shop’s history' : 'in '+period.range_label;

    /* ---- the chart, drawn against a peak the screen prints ---- */
    var series=a.series||[];
    var peak=Math.max(0, Number(a.peak_aed)||0);
    var periodTotal=Number(a.period_total_aed)||0;
    var dense=series.length>60;
    var futures=series.filter(function(d){ return !!d.future; }).length;

    var bars=series.map(function(d){
      var v=Number(d.revenue_aed)||0;
      var future=!!d.future;
      var h=(!future && peak>0) ? Math.max(v>0?3:1.5, (v/peak)*100) : 1.5;
      var cls=future ? ' is-future' : (v>0 ? '' : ' is-zero');
      var tip=future ? (d.full||d.label)+' — not yet' : (d.full||d.label)+': '+anMoney(v);
      return '<span class="an-bar'+cls+'" title="'+sesc(tip)+'">'+
        '<i style="height:'+h.toFixed(2)+'%"></i></span>';
    }).join('');

    /* Only a handful of ticks are labelled. Fifty-three dates across a phone
       overlap into an unreadable smear; six are legible and place the rest. The
       step is computed from the bucket count, which now varies from 7 to a few
       hundred, so it can no longer be the hard-coded "first, middle, last". */
    var step=Math.max(1, Math.ceil(series.length/6));
    var xs=series.map(function(d,i){
      var show = (i % step === 0) || (i === series.length-1);
      return '<span>'+(show? sesc(d.label||'') : '')+'</span>';
    }).join('');

    /* ---- order status, as rows with a share bar rather than a pill soup ---- */
    var sb=a.status_breakdown||{};
    var sKeys=Object.keys(sb).sort(function(x,y){ return sb[y]-sb[x]; });
    var sTotal=sKeys.reduce(function(t,k){ return t+(Number(sb[k])||0); },0);
    var real=(a.revenue_statuses||[]);
    var statusRows=sKeys.length? sKeys.map(function(k){
      var n=Number(sb[k])||0;
      var pct=sTotal? Math.round(n*100/sTotal) : 0;
      var counts=real.indexOf(k)!==-1;
      return '<tr><td>'+statusPill(k)+'</td>'+
        '<td class="an-num">'+n.toLocaleString()+'</td>'+
        '<td style="width:34%"><span class="an-meter"><i style="width:'+pct+'%'+
          (counts?'':';background:rgba(127,127,127,.45)')+'"></i></span></td>'+
        '<td class="an-num">'+pct+'%</td>'+
        '<td style="font-size:11.5px;color:var(--ink-soft)">'+(counts?'counts as revenue':'—')+'</td></tr>';
    }).join('') : '';

    /* ---- top products, with each line's share of the top-eight revenue ---- */
    var tp=a.top_products||[];
    var tpMax=tp.reduce(function(m,p){ return Math.max(m, Number(p.revenue_aed)||0); },0);
    var rows=tp.length? tp.map(function(p){
      var rev=Number(p.revenue_aed)||0;
      var w=tpMax? Math.max(2, Math.round(rev*100/tpMax)) : 0;
      return '<tr><td><div class="an-pname">'+sesc(p.name||'—')+
          (p.brand? '<span class="an-pbrand">'+sesc(p.brand)+'</span>':'')+'</div></td>'+
        '<td class="an-num">'+(Number(p.units)||0).toLocaleString()+'</td>'+
        '<td class="an-num"><b>'+anMoney(rev)+'</b></td>'+
        '<td style="width:26%"><span class="an-meter"><i style="width:'+w+'%"></i></span></td></tr>';
    }).join('') : '';

    content.innerHTML = anStyle() +
      '<div class="wrap"><div class="an-wrap">'+

      '<div class="page-head" style="margin:0"><h2>Sales Report</h2>'+
      '<p>How the shop is trading, worked out from your real orders. Every figure below — '+
      'the money, the chart, where the orders are and the best sellers — covers '+
      '<b>'+sesc(period.range_label)+'</b> and nothing else. Revenue is net of refunds.</p></div>'+

      anFilterBar()+

      /* --- section 1: the money --- */
      '<div class="an-card">'+
        anCardHead('Sales',
          'Orders placed '+sesc(inPeriod)+' in a status that counts as a sale'+
          (counted? ' — '+sesc(counted) : '')+'. Refunds are taken off.', period)+
        '<div class="an-stats">'+
          /* LANE DU: "Net revenue" is net of REFUNDS, not net of tax, and the two
             readings of "net" are easy to confuse on a money screen. The card
             now carries the endpoint's own description of what it sums, and
             the VAT collected on those orders sits beside it as its own figure
             rather than staying invisible inside the headline. */
          stat('Net revenue', anMoney(a.revenue_total_aed),
               'after refunds, '+period.range_label, '', (a.revenue_basis||{}).note)+
          stat('Refunded', anMoney(a.refunds_total_aed),
               (a.refunds_total_aed? 'off '+anMoney(a.gross_revenue_aed)+' taken' : 'nothing given back'),
               a.refunds_total_aed? 'is-out' : '')+
          stat('VAT collected', anMoney(a.tax_collected_aed||0),
               (Number(a.tax_collected_aed||0)
                 ? 'inside the revenue figure, owed onward'
                 : 'no VAT charged on these orders'),
               '', (a.revenue_basis||{}).note)+
          stat('Average order', anMoney(a.aov_aed),
               a.paid_orders? 'over '+a.paid_orders.toLocaleString()+' paid orders' : 'no paid orders in this period')+
          stat('Units sold', (a.units_sold||0).toLocaleString(), 'items across those orders')+
        '</div>'+
        '<p class="an-note">A part-refunded order keeps its original status, so its refund is subtracted here '+
        'rather than removing the order. Gross before refunds was '+sesc(anMoney(a.gross_revenue_aed))+'. '+
        '<b>A refund is counted in the period of the order it came off</b>, not the day the money went back — '+
        'so this reads "of what these orders brought in, this much went back", and it is the same rule the '+
        'chart and the average order value follow.</p>'+
        /* The demo disclosure for this screen, worded from the counts and not
           from a.demo.excluded — see the dashboard banner in renderDash() for
           why. Only demo ORDERS move anything here: every figure and every bar
           on this screen is built from `orders`, and DemoSeed::exclude() takes
           the demo ones off (top_products too — it excludes by orders.id).
           Demo customers and demo products change nothing on Analytics, so
           naming them here would be noise. Demo reviews are in no figure on
           this screen at all, which is exactly why they are said out loud
           rather than left implied. */
        (function(){
          var dd = (a.demo||{}), n = Number(dd.orders||0), r = Number(dd.reviews||0), out = '';
          if(n) out += '<b>'+n.toLocaleString()+'</b> demo order'+(n===1?' is':'s are')+
                       ' excluded from every figure on this screen. ';
          if(r) out += '<b>'+r.toLocaleString()+'</b> demo review'+(r===1?' is':'s are')+
                       ' hidden from the storefront; no figure here counts reviews. ';
          return out? '<p class="an-note">'+out.trim()+'</p>' : '';
        })()+
      '</div>'+

      /* --- section 2: the chart --- */
      '<div class="an-card">'+
        /* The caption names the clock as well as the grain. A bar is a day on
           the SHOP's calendar (App\Support\StoreTime, Store -> Business Details),
           not the server's, and the two are four hours apart — so a screenshot
           of this chart has to say which one it was drawn on or the reader
           cannot tell whether an order placed at 01:30 belongs to this bar or
           the one before it. Demo orders are excluded from every figure here,
           which the caption also has to admit: otherwise switching Demo Content
           on moves the chart and nothing says why. */
        anCardHead('Revenue over time',
          sesc(a.bucket_label||'Each bar is one day')+' in '+sesc(a.timezone||'Asia/Dubai')+' time, '+
          'by the date the order was placed, net of anything refunded on it.'+
          /* Keyed on the ORDER count, not on `excluded`: every bar on this chart
             is built from `orders`, so demo customers or demo products flipping
             `excluded` true made this caption claim an exclusion that had not
             happened to it. */
          (Number((a.demo||{}).orders||0)? ' Demo orders are excluded.' : ''), period)+
        '<div class="an-scale"><span>Tallest bar <b>'+sesc(anMoney(peak))+'</b></span>'+
        '<span>'+sesc(period.label)+' <b>'+sesc(anMoney(periodTotal))+'</b></span></div>'+
        (series.length? '<div class="an-chart'+(dense?' is-dense':'')+'">'+bars+'</div>'+
                        '<div class="an-xaxis'+(dense?' is-dense':'')+'">'+xs+'</div>'
                      : '<p class="an-empty">Nothing to chart for this period.</p>')+
        (peak===0? '<p class="an-note">Nothing sold in this period, so every bar is flat.</p>':'')+
        /* Only said when there is something to say: a note about hatched bars
           on a chart with none is a sentence the owner has to check the chart
           against before they can ignore it. */
        (futures? '<p class="an-note">The '+futures.toLocaleString()+' hatched '+sesc(a.bucket||'day')+
          (futures===1?'':'s')+' at the end have not happened yet. They are not a drop in trade.</p>':'')+
      '</div>'+

      /* --- section 3: order status --- */
      '<div class="an-card">'+
        anCardHead('Where the orders are',
          'Every order placed '+sesc(inPeriod)+' by status, and which of them the revenue figure above '+
          'includes.', period)+
        (statusRows? '<div class="an-scroll"><table class="an-table"><thead><tr><th>Status</th>'+
          '<th class="an-num">Orders</th><th>Share</th><th class="an-num">%</th><th>Revenue</th></tr></thead>'+
          '<tbody>'+statusRows+'</tbody></table></div>'
        : '<p class="an-empty">No orders in this period.</p>')+
      '</div>'+

      /* --- section 4: top products --- */
      '<div class="an-card">'+
        anCardHead('Best sellers',
          'The eight products that brought in the most '+sesc(inPeriod)+', at what each line was actually '+
          'charged — not list price.', period)+
        /* LANE DU: this column was also called "Revenue", and it is a different
           quantity from the Revenue tile above it — the value of the LINES
           sold, with no delivery, no fees and no refunds in it, and exclusive
           VAT never reaching a line at all. Two columns called Revenue on one
           screen, summing two different tables, is the defect being closed.
           The label comes from the endpoint so it cannot drift from the sum. */
        (rows? '<div class="an-scroll"><table class="an-table"><thead><tr><th>Product</th>'+
          '<th class="an-num">Units</th><th class="an-num"'+
            (((a.top_products_basis||{}).note)? ' title="'+sesc(a.top_products_basis.note)+'"' : '')+'>'+
            sesc((a.top_products_basis||{}).label || 'Product sales')+'</th><th>Share</th></tr></thead>'+
          '<tbody>'+rows+'</tbody></table></div>'
        : '<p class="an-empty">Nothing sold in this period.</p>')+
      '</div>'+

      '</div></div>';

    anBind();
  }
  window.renderAnalytics = renderAnalytics;

  /* ---------- Users & Roles (real admin accounts) ----------
     ===== LANE DJ ============================================================

     This screen was already real - it reads GET /admin-api/users and renders
     the actual rows of `admin_users`. The fabricated table that used to be
     declared for this name in the first <script> block is gone; see the Lane DJ
     region beside renderUsers() up there for why it was dangerous while dead.

     WHAT WAS WRONG DOWN HERE was one line: `catch(e){ ADMINS=[]; }`. Every
     failure became an empty list, and an empty list renders "No users."

     /admin-api/users is users.manage, which AdminCapabilities grants to `owner`
     alone. So a manager opening this screen got a 403 from
     EnforceAdminCapability, the catch turned it into [], and the screen told
     him his shop has no staff accounts at all. That is the same defect as the
     invented table, arrived at from the other side: the first one made up
     colleagues, this one made them all disappear. Either way the one screen
     whose subject is "who can sign in to my shop" answered with fiction, and
     this half of it was live.

     A refusal is now printed rather than swallowed. EnforceAdminCapability
     already answers /admin-api/* with a JSON body whose `message` names the
     capability in a sentence, and api() already attaches the status and that
     parsed body to the Error it throws. The refusal panel prints that sentence,
     the same way the health card does, and it shows no table and no Add user
     button - because a role that cannot read the list certainly cannot add to
     it, and offering the button would only produce a second 403.

     An empty list with no error is treated as a fault, not as an answer: you
     are signed in as one of these accounts, so zero of them is not a state the
     table should present as normal.

     THE ROLE DESCRIPTIONS were also rewritten to match what the map actually
     grants. They are documentation printed next to a live list, and they were
     narrower than the truth - "Pages, blog, media" for an editor that also
     holds the whole catalogue. The wording now follows the four-role summary in
     AdminCapabilities' own header comment, so the screen and the map say the
     same thing. Nothing here grants anything; the map is the only thing that
     does. */
  var ADMINS=[];
  var USERS_ERR=null;
  var ROLE_OPTS=[['owner','Owner'],['manager','Manager'],['support','Support'],['editor','Content Editor']];
  var ROLE_NOTES=[
    'Owner|Everything, always. Only an owner can reach staff accounts, payment keys, site settings and core updates.',
    'Manager|Runs the shop: orders, refunds, customers, the catalogue, content, marketing and shipping. Not site settings, payment keys, staff accounts or updates.',
    'Support|Answers customers: reads orders and customers, adds notes, moves an order along and moderates reviews. Touches no money, deletes nothing, exports nothing.',
    'Content Editor|Works on the storefront: the catalogue, pages, blog, media and the review furniture. Sees no customer, no order and no money.'
  ];
  function roleBadge(r){ var m={owner:'green',manager:'amber',support:'grey',editor:'grey'}[r]||'grey'; var lbl=(ROLE_OPTS.filter(function(o){return o[0]===r;})[0]||[r,r])[1]; return '<span class="pill '+m+'"><span class="d"></span>'+lbl+'</span>'; }
  function roleSelect(id, sel){ return '<select class="inp" id="'+id+'" style="width:100%">'+ROLE_OPTS.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===sel?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>'; }
  function rolesGridHTML(){
    return '<div class="sec-title">Roles</div><div class="mod-grid">'+
      ROLE_NOTES.map(function(r){var p=r.split('|');return '<div class="mod"><div class="mic">'+ic(I.shield)+'</div><div><div class="mname">'+sesc(p[0])+'</div><div class="mdesc">'+sesc(p[1])+'</div></div></div>';}).join('')+
      '</div>';
  }

  window.renderUsers = async function(){
    USERS_ERR=null;
    try{ var d=await api('/admin-api/users'); ADMINS=d.users||[]; }
    catch(e){ ADMINS=[]; USERS_ERR={ status:(e&&e.status)||0, message:(e&&e.body&&e.body.message)||'' }; }

    var head='<div class="wrap"><div class="between" style="margin-bottom:16px"><div class="page-head" style="margin:0"><h2>Users &amp; Roles</h2><p>Staff accounts and what each can do. Customer accounts live in the Customers module.</p></div>';

    /* Refused: print the sentence the server sent, offer nothing that would
       only be refused again. */
    if(USERS_ERR && USERS_ERR.status===403){
      document.querySelector('#content').innerHTML = head+'</div>'+
        '<div class="card pad"><b style="font-size:14px">You cannot see the staff list</b>'+
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px;line-height:1.6">'+
        sesc(USERS_ERR.message||'Your role does not have the "users.manage" permission.')+
        '</p><p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px;line-height:1.6">Staff accounts are owner-only. Nothing is wrong with your store and nothing is hidden from the owner &mdash; this one list is not yours to read, and adding, editing or removing an account is refused for the same reason. Ask an owner if you need access changed.</p></div>'+
        rolesGridHTML()+'</div>';
      return;
    }

    /* Any other failure is a failure, and says so rather than rendering an
       empty shop. */
    if(USERS_ERR){
      document.querySelector('#content').innerHTML = head+'</div>'+
        '<div class="card pad"><b style="font-size:14px">The staff list could not be loaded</b>'+
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px;line-height:1.6">The server answered '+sesc(String(USERS_ERR.status||'nothing'))+' when this screen asked for your staff accounts, so what you would be looking at is not a list of them. Reload the page; if it keeps happening the accounts themselves are unaffected &mdash; it is this screen that cannot read them.</p></div>'+
        rolesGridHTML()+'</div>';
      return;
    }

    document.querySelector('#content').innerHTML = head+
      '<button class="btn" id="usr_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add user</button></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Added</th><th></th></tr></thead><tbody>'+
      (ADMINS.length? ADMINS.map(function(u){
        return '<tr><td><div class="row"><span class="pthumb" style="background:'+sesc(tcol(u.name||u.email))+';width:30px;height:30px;font-size:10px">'+sesc(initials(u.name||u.email))+'</span><b style="font-size:12.5px">'+sesc((u.name||'—'))+(u.is_self?' <span class="pbrand" style="display:inline">(you)</span>':'')+'</b></div></td>'+
          '<td style="font-size:12px">'+sesc(u.email)+'</td>'+
          '<td>'+roleBadge(u.role)+'</td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(u.created_at||'').slice(0,10)+'</td>'+
          '<td><div class="row" style="gap:5px"><button class="btn ghost sm" data-uedit="'+u.id+'">Edit</button>'+
          '<button class="btn ghost sm" data-upass="'+u.id+'">Reset password</button>'+
          (u.is_self?'':'<button class="btn ghost sm" data-udel="'+u.id+'">Delete</button>')+'</div></td></tr>';
      }).join('') : '<tr><td colspan="5" style="text-align:center;color:var(--ink-soft);padding:30px">No accounts came back. You are signed in as one of them, so this is a fault rather than an empty list &mdash; reload the page.</td></tr>')+
      '</tbody></table></div>'+
      rolesGridHTML()+'</div>';
    document.getElementById('usr_add').onclick=addUser;
    document.querySelectorAll('#content [data-uedit]').forEach(function(b){ b.onclick=function(){ editUser(+b.dataset.uedit); }; });
    document.querySelectorAll('#content [data-upass]').forEach(function(b){ b.onclick=function(){ resetUserPassword(+b.dataset.upass); }; });
    document.querySelectorAll('#content [data-udel]').forEach(function(b){ b.onclick=function(){ deleteUser(+b.dataset.udel); }; });
  };

  function addUser(){
    openModal('<div class="modal-h"><b>Add user</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>Name</label><input id="nu_name"></div>'+
      '<div class="fld"><label>Email</label><input id="nu_email" type="email"></div>'+
      '<div class="fld"><label>Temporary password</label><input id="nu_pass" type="text" placeholder="min 8 characters"></div>'+
      '<div class="fld"><label>Role</label>'+roleSelect('nu_role','manager')+'</div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="nu_save">Create user</button></div></div>');
    document.getElementById('nu_save').onclick=async function(){
      var body={ name:sval('nu_name'), email:sval('nu_email'), password:sval('nu_pass'), role:sval('nu_role') };
      if(!body.name||!body.email||body.password.length<8){ toast('Name, email and an 8+ char password are required'); return; }
      try{ await api('/admin-api/users',{method:'POST',body:JSON.stringify(body)}); toast('User created'); closeModal(); renderUsers(); }
      catch(e){ toast('Could not create user (email may already exist)','bad'); }
    };
  }
  function editUser(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Edit '+sesc(u.name||u.email)+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>Name</label><input id="eu_name" value="'+sesc(u.name)+'"></div>'+
      '<div class="fld"><label>Role</label>'+roleSelect('eu_role',u.role)+'</div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="eu_save">Save</button></div></div>');
    document.getElementById('eu_save').onclick=async function(){
      try{ await api('/admin-api/users/'+id,{method:'PUT',body:JSON.stringify({name:sval('eu_name'),role:sval('eu_role')})}); toast('User updated'); closeModal(); renderUsers(); }
      catch(e){ toast('Update failed (cannot demote the only owner)','bad'); }
    };
  }
  function resetUserPassword(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Reset password \u2014 '+sesc(u.name||u.email)+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>New password</label><input id="rp_pass" type="text" placeholder="min 8 characters"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="rp_save">Set password</button></div></div>');
    document.getElementById('rp_save').onclick=async function(){
      var pw=sval('rp_pass'); if(pw.length<8){ toast('Password must be at least 8 characters'); return; }
      try{ await api('/admin-api/users/'+id,{method:'PUT',body:JSON.stringify({password:pw})}); toast('Password updated'); closeModal(); }
      catch(e){ toast('Could not update password','bad'); }
    };
  }
  async function deleteUser(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Delete user</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Remove <b>'+sesc((u.name||u.email))+'</b>? This cannot be undone.</p>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" style="background:var(--danger,#d6455a)" id="du_yes">Delete</button></div></div>');
    document.getElementById('du_yes').onclick=async function(){
      try{ await api('/admin-api/users/'+id,{method:'DELETE'}); toast('User deleted'); closeModal(); renderUsers(); }
      catch(e){ toast('Could not delete (cannot remove yourself or the only owner)','bad'); }
    };
  }

  /* ---------- Store settings (Business Details) + SEO & Meta ---------- */
  var SETTINGS={};
  function sesc(v){ return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
  function sval(id){ var el=document.getElementById(id); return el?el.value:''; }
  function money2aed(k){ var v=SETTINGS[k]; return (v==null||v==='')?'':(parseInt(v,10)/100); }
  /* The endpoint sends `title_template_basis` BESIDE `settings` — it is not a
     setting, it is the screen's own explanation of one, resolved from
     AdminController::titleTemplateBasis() which sits next to the behaviour it
     describes. Same arrangement as the `revenue_basis` the dashboard tiles
     already take their captions from, and for the same reason: a sentence
     written here would be wrong the first time either side changed, and this
     particular control has already been reported twice as "does nothing" by
     people reading its old caption literally. Defaults to {} so a shell served
     against an older endpoint simply renders no note rather than throwing. */
  var TITLE_BASIS={};
  async function loadSettings(){ try{ var d=await api('/admin-api/settings'); SETTINGS=d.settings||{}; TITLE_BASIS=d.title_template_basis||{}; }catch(e){ SETTINGS={}; TITLE_BASIS={}; } return SETTINGS; }

  /* KBB_CURRENCIES, KBB_VAT_COUNTRIES and KBB_VAT_GCC are declared in the
     small script just before this block (Lane AP), so this block is static. */

  /* SUGGESTIONS, NOT VALUES — and DERIVED, not restated.

     This was a list of six rates written out here. It is now read off the
     shared preset group, App\Support\CountryPresets::TAX, which is where the
     delivery lines get theirs and where any later per-country table will get
     its own. Two lists of tax rates in one console is two lists to keep right,
     and the one that goes stale is the one nobody is looking at.

     The name survives because it is what the console calls the per-country VAT
     suggestions and a test pins that the screen still offers them. What it no
     longer is, is a second source of truth: change a rate in CountryPresets and
     it changes here, in the chips, and in the "fill in all" button together.

     Nothing here is saved. A chip fills the box; the box is what the owner
     reads; Save changes is what publishes. */
  var KBB_VAT_SUGGESTIONS = ((KBB_PRESETS && KBB_PRESETS.tax ? KBB_PRESETS.tax.rows : []) || [])
    .map(function(r){ return { code:r.code, rate:String(r.value||'') }; });

  function curFind(code){ code=String(code||'').toUpperCase(); for(var i=0;i<KBB_CURRENCIES.length;i++){ if(KBB_CURRENCIES[i].code===code) return KBB_CURRENCIES[i]; } return null; }
  function curSymbol(){ var s=SETTINGS.currency_symbol; if(s!=null&&String(s).trim()!=='') return String(s); var c=curFind(SETTINGS.currency||'AED'); return c?c.symbol:''; }
  function curSelect(){
    var cur=String(SETTINGS.currency||'AED').toUpperCase(), seen=false;
    var out=KBB_CURRENCIES.map(function(c){ if(c.code===cur) seen=true; return '<option value="'+sesc(c.code)+'"'+(c.code===cur?' selected':'')+'>'+sesc(c.code+' — '+c.name)+'</option>'; }).join('');
    if(!seen&&cur) out='<option value="'+sesc(cur)+'" selected>'+sesc(cur)+'</option>'+out;
    return '<select class="inp" id="set_currency" style="width:100%">'+out+'</select>';
  }

  /* ---------- Business Details (Lane CD: hierarchy and layout only) ----------
     Three titled bands instead of three cards with a bare bold word on top,
     each saying in one line when the owner would come here. Every field is the
     one that was here before, with the same id, and the save handler below is
     untouched: the payload it posts is the same eleven keys in the same order.

     WHAT CHANGED AND WHY. The old markup used .g2, which is
     `grid-template-columns:1fr 1fr` with no breakpoint anywhere in the console
     — so on a 390px phone this screen drew two 145px columns and the currency
     select read "AED — UAE Dirha" with the rest clipped. .bd-grid is auto-fit
     with a min() floor and stacks instead. The four-line note about the dirham
     glyph sat under one input of a pair, which dragged that side of the row
     four lines deeper than the other; it is now one note at the foot of its
     band, where it reads as background rather than as an instruction attached
     to a box. VAT no longer floats alone at 220px with dead space beside it —
     it takes a track in its own band's grid like everything else. */
  function bdField(id,label,control,help){
    return '<div class="bd-field"><label class="bd-label" for="'+id+'">'+label+'</label>'+control+
      (help?'<div class="bd-help">'+help+'</div>':'')+'</div>';
  }
  function bdSec(title,description,body){
    return '<section class="bd-sec"><div class="bd-sec-h"><div class="bd-sec-t">'+title+'</div>'+
      '<div class="bd-sec-d">'+description+'</div></div>'+body+'</section>';
  }

  /* The accent colour as the owner typed it, which is what the text box shows
     and what the Save button posts. Blank stays blank: it is how the storefront
     is told to use the theme's own pink, and turning it into '#E0567B' here
     would write that value into the database the next time anything is saved on
     this screen -- pinning the colour to today's default instead of following
     it. */
  function bdAccentValue(){ var v=SETTINGS.brand_accent; return (v==null)?'':String(v).trim(); }

  /* A claim's current value for its box. The DEFAULTS ARE ON THE SERVER, in
     App\Support\TrustClaims::CLAIMS, and AdminController::settings() sends the
     resolved value — so the box shows what the page shows, and this console
     never carries a second copy of the wording that could drift from it.
     An absent key gives '' rather than "undefined", and a cleared row gives ''
     too, which is correct: both put an empty box on the screen, and only one
     of them removes the claim from the site. */
  function bdClaim(key){ var v=SETTINGS[key]; return (v==null)?'':String(v); }

  /* And the same colour as <input type="color"> needs it, which is not the same
     thing. That control accepts EXACTLY '#rrggbb': handed '' it shows black,
     and handed the three-digit form the storefront accepts ('#e57') it also
     shows black. Either would mean the swatch beside the box silently
     disagreed with the box -- and the first click on it would then save black.
     So it is expanded here, and a value it cannot represent falls back to the
     shop's own pink, which is the colour the storefront is actually painting
     while the field is blank. */
  function bdAccentSwatch(){
    var v=bdAccentValue().replace(/^#/,'');
    if(/^[0-9a-f]{3}$/i.test(v)) v=v[0]+v[0]+v[1]+v[1]+v[2]+v[2];
    return /^[0-9a-f]{6}$/i.test(v) ? '#'+v.toLowerCase() : '#e0567b';
  }

  /* ---------- Tax by country (Lane CP, extended by Lane CU) ----------------
     The owner asked for both halves of one idea, twice over. First:

       "give us option to choose the percentage of VAT across each country or
        for All countries at once."

     and then, on 2026-09-16:

       "also i will need control to inclusive VAT or exclusive."
       "across each country, for example for uae the vat i can set inclusive,
        for Saudi i can set exclusive and so on as per my requirements."

     ALL COUNTRIES AT ONCE IS NOT A NEW CONTROL. It is the Default rate and
     Default basis above, unchanged — they apply wherever a country has no row
     of its own. This table is only the exceptions, and it ships EMPTY, so a
     shop that never opens it behaves exactly as it always did.

     WHY EVERY ROW HAS A BASIS NOW. Exclusive VAT has exactly one meaning: the
     tax is added on top, and the customer pays more. There is no version of an
     inclusive/exclusive switch that leaves the line display-only, so decision
     D-64 has been overturned — by the owner, deliberately. What protects him
     from that landing by surprise is the switch above this table: until Tax
     mode is set to "Applied to orders", every row here is printed and nothing
     is charged, exactly as before.

     WHY THE FIGURES ARE ONLY SUGGESTIONS. The owner wrote "Saudi there's 15% i
     think". We are not a tax authority, rates change, and a wrong rate printed
     on a receipt is worse than no rate — so nothing is pre-saved. The presets
     fill rows in one click and still have to be saved deliberately, and the
     note says whose job confirming them is.

     WHY A PRESET NEVER SETS "EXCLUSIVE". A preset knows a rate; it cannot know
     how this business prices. Every preset row lands on the shop's own default
     basis, so clicking every preset on this screen and saving can never start
     charging a customer more. Turning a row exclusive is a deliberate act on a
     control of its own. */

  /* code -> rate string, and code -> basis string. Setting::map() hands the
     console raw column values, so both rows arrive as the JSON objects their
     validators stored. */
  var VAT_RATES = {};
  var VAT_BASES = {};

  /* The basis column's vocabulary, and the only place it is written down in
     the console. It mirrors App\Support\TaxRule::BASES. */
  var KBB_VAT_BASIS_LABELS = {
    inclusive: 'Inclusive — price already includes it',
    exclusive: 'Exclusive — added on top',
    flat: 'Printed only — charges nothing'
  };

  function vatParseMap(raw){
    if(raw==null||raw==='') return {};
    var parsed = null;
    try{ parsed = (typeof raw==='string') ? JSON.parse(raw) : raw; }catch(e){ parsed = null; }
    if(!parsed||typeof parsed!=='object'||Array.isArray(parsed)) return {};
    return parsed;
  }

  function vatRatesLoad(){
    VAT_RATES = {};
    VAT_BASES = {};

    var rates = vatParseMap(SETTINGS.vat_country_rates);
    Object.keys(rates).forEach(function(code){
      code = String(code).toUpperCase();
      if(KBB_VAT_COUNTRIES[code]) VAT_RATES[code] = String(rates[code]);
    });

    /* The RATE MAP IS THE TABLE and this is a column on it, which is the same
       rule VatDisplay::countryBases() applies server-side: a basis for a
       country with no rate is a row the owner cannot see, so it is dropped
       rather than quietly applied. */
    var bases = vatParseMap(SETTINGS.vat_country_bases);
    Object.keys(bases).forEach(function(code){
      code = String(code).toUpperCase();
      var basis = String(bases[code]||'').toLowerCase();
      if((code in VAT_RATES) && KBB_VAT_BASIS_LABELS[basis]) VAT_BASES[code] = basis;
    });
  }

  /* The shop's default basis — what a row uses until it is given one, and what
     every preset lands on. Unknown values read as inclusive, the same
     fail-closed reading TaxRule::make() applies. */
  function vatDefaultBasis(){
    var b = String(SETTINGS.vat_basis||'').toLowerCase();
    return KBB_VAT_BASIS_LABELS[b] ? b : 'inclusive';
  }

  function vatBasisOf(code){ return VAT_BASES[code] || vatDefaultBasis(); }

  /* The same trimming VatDisplay::label() does, so the preview is the string
     the storefront will actually print: 5.00 -> 5, 7.50 -> 7.5, 15.00 -> 15. */
  function vatPrintableRate(rate){
    var n = parseFloat(rate);
    if(!isFinite(n)) return '0';
    return n.toFixed(2).replace(/0+$/,'').replace(/\.$/,'');
  }

  /* What the receipt line will read, from the operator's own vat_label. */
  function vatPreviewLine(rate){
    var tpl = SETTINGS.vat_label;
    if(tpl==null||tpl==='') tpl = "You're paying VAT ({rate}%)";
    return String(tpl).split('{rate}').join(vatPrintableRate(rate));
  }

  /* The portion of an AED 100 order the line will show. Mirrors
     App\Support\TaxRule::taxOn() — inclusive takes the tax OUT of the 100,
     exclusive and flat both compute it ON the 100; exclusive is the only one
     that then raises what is paid. */
  function vatPreviewAmount(rate, basis){
    var r = parseFloat(rate);
    if(!isFinite(r)||r<=0) return null;
    var fils = (basis==='inclusive') ? (10000*r/(100+r)) : (10000*r/100);
    return (Math.round(fils)/100).toFixed(2);
  }

  /* One sentence saying what an AED 100 order does under this row — the figure
     that makes the consequence of the basis visible before it is saved. */
  function vatPreviewCell(rate, basis){
    var r = parseFloat(rate);
    if(!isFinite(r)||r<=0) return '<span class="vr-prev">No VAT line is printed at 0%.</span>';
    var amount = vatPreviewAmount(rate, basis);
    var live = String(SETTINGS.tax_mode||'display')==='live';
    var text;
    if(basis==='exclusive'){
      text = live
        ? vatPreviewLine(rate)+' · AED 100 becomes AED '+(100+parseFloat(amount)).toFixed(2)
        : vatPreviewLine(rate)+' · would add AED '+amount+' once tax is switched on';
    }else if(basis==='flat'){
      text = vatPreviewLine(rate)+' · prints AED '+amount+', charges nothing';
    }else{
      text = vatPreviewLine(rate)+' · AED 100 shows AED '+amount+' of it as VAT';
    }
    return '<span class="vr-prev" title="'+sesc(text)+'">'+sesc(text)+'</span>';
  }

  function vatBasisSelect(code){
    var cur = vatBasisOf(code);
    return '<select data-vat-basis="'+sesc(code)+'" aria-label="How VAT applies in '+sesc(KBB_VAT_COUNTRIES[code])+'">'+
      Object.keys(KBB_VAT_BASIS_LABELS).map(function(b){
        return '<option value="'+sesc(b)+'"'+(b===cur?' selected':'')+'>'+sesc(KBB_VAT_BASIS_LABELS[b])+'</option>';
      }).join('')+'</select>';
  }

  /* Countries not yet in the table, by NAME, alphabetically — the owner picks
     "Saudi Arabia", never "SA". */
  function vatAddSelect(){
    var codes = Object.keys(KBB_VAT_COUNTRIES).filter(function(c){ return !(c in VAT_RATES); });
    codes.sort(function(a,b){ return KBB_VAT_COUNTRIES[a].localeCompare(KBB_VAT_COUNTRIES[b]); });
    return '<select id="vat_add_country"><option value="">Choose a country…</option>'+
      codes.map(function(c){ return '<option value="'+sesc(c)+'">'+sesc(KBB_VAT_COUNTRIES[c])+'</option>'; }).join('')+
      '</select>';
  }

  function vatRatesTable(){
    var codes = Object.keys(VAT_RATES);
    codes.sort(function(a,b){ return KBB_VAT_COUNTRIES[a].localeCompare(KBB_VAT_COUNTRIES[b]); });

    if(!codes.length){
      return '<div class="vr-tbl"><div class="vr-empty">No country has a rate of its own yet, so every country uses the default rate and basis above.</div></div>';
    }

    return '<div class="vr-tbl">'+
      '<div class="vr-row vr-head"><span>Country</span><span>Rate&nbsp;%</span><span>How it applies</span><span>What this does</span><span></span></div>'+
      codes.map(function(c){
        var basis = vatBasisOf(c);
        var charges = (basis==='exclusive') && parseFloat(VAT_RATES[c])>0;
        return '<div class="vr-row'+(charges?' vr-charges':'')+'" data-vat-row="'+sesc(c)+'">'+
          '<span class="vr-name">'+sesc(KBB_VAT_COUNTRIES[c])+'</span>'+
          '<input type="number" min="0" max="100" step="0.01" value="'+sesc(VAT_RATES[c])+'" data-vat-rate="'+sesc(c)+'" aria-label="VAT rate for '+sesc(KBB_VAT_COUNTRIES[c])+'">'+
          vatBasisSelect(c)+
          vatPreviewCell(VAT_RATES[c], basis)+
          '<button type="button" class="vr-del" data-vat-del="'+sesc(c)+'" title="Remove '+sesc(KBB_VAT_COUNTRIES[c])+'" aria-label="Remove '+sesc(KBB_VAT_COUNTRIES[c])+'">&times;</button>'+
        '</div>';
      }).join('')+
    '</div>';
  }

  /* THE ONE-CLICK WAY IN, and it is the SHARED one.

     "i want you to make the deliver lines automatic same in tax. like user
      just just click to select."

     So clicking is the primary way this table is filled, and the bar that does
     it is pstBarHtml/pstBind — the same one the Delivery lines screen uses,
     driven by App\Support\CountryPresets. A second implementation of "offer
     the owner one-click preset rows for a country table" is two things to keep
     in step, and this project has already had to merge two screens that had
     drifted apart.

     WHAT IS DELIBERATELY NOT SHARED IS THE TONE, and it lives in the group's
     own `note` rather than here: a delivery sentence is the owner's to state
     and cannot be wrong, while a tax rate is a fact about the world he is
     answerable for and a wrong one prints on a receipt.

     A PRESET NEVER SETS A BASIS. apply() below writes the rate and lands the
     row on the shop's own default basis, so clicking every chip on this screen
     and saving cannot start charging a customer more. Turning a row Exclusive
     is a deliberate act on a control of its own. */
  function vatPresetBar(){
    return pstBarHtml('tax', {
      id: 'taxPresets',
      // What the chips compare against: the row's current rate, or null when
      // the country has no row at all.
      filled: function(code){ return (code in VAT_RATES) ? String(VAT_RATES[code]) : null; },
    });
  }

  function vatRatesBand(){
    return bdSec('Tax by country',
      'The default rate and basis above apply to every country at once. Add a country here only when it should be different — Saudi Arabia charged on top, say, while everywhere else keeps the default.',
      '<div class="vr-wrap" id="vatRatesBand">'+
        vatPresetBar()+
        '<div id="vat_rates_table">'+vatRatesTable()+'</div>'+
        '<div class="bd-label" style="margin-top:2px">Or add one by hand</div>'+
        '<div class="vr-add" id="vat_rates_add">'+vatAddSelect()+
          '<input type="number" id="vat_add_rate" min="0" max="100" step="0.01" placeholder="Rate %" aria-label="VAT rate for the country being added">'+
          '<button type="button" class="btn" id="vat_add_btn">Add</button>'+
        '</div>'+
        '<div class="bd-note"><b>These are the rates printed on your receipts, and they are yours to get right.</b> ' +
          'Rates change, a wrong percentage on a receipt is worse than none, and the note above the chips says whose job checking them is.<br><br>' +
          '<b>Inclusive</b> means the price on the product page already contains the tax: the customer pays AED 100 and AED 4.76 of it is VAT at 5%. ' +
          '<b>Exclusive</b> means the tax is added on top: at 15% the same AED 100 basket is charged AED 115. ' +
          '<b>Printed only</b> shows a figure and charges nothing, which is what this shop did everywhere until now.<br><br>' +
          'Nothing on this tab charges anybody until <b>Tax mode</b> above is set to <b>Applied to orders</b>.</div>'+
      '</div>');
  }

  function vatRatesBind(){
    var band = document.getElementById('vatRatesBand');
    if(!band) return;

    /* Read every rate box back into VAT_RATES before anything repaints.
       pstBind() calls this FIRST on every interaction of the preset bar, and it
       has to: the owner may have typed into a box since the last paint, and a
       repaint that has not read those boxes back throws his typing away. The
       basis selects are read the same way, for the same reason. */
    var harvest = function(){
      band.querySelectorAll('[data-vat-rate]').forEach(function(box){
        VAT_RATES[box.getAttribute('data-vat-rate')] = String(box.value||'').trim();
      });
      band.querySelectorAll('[data-vat-basis]').forEach(function(sel){
        VAT_BASES[sel.getAttribute('data-vat-basis')] = String(sel.value||'').toLowerCase();
      });
    };

    var repaint = function(){
      document.getElementById('vat_rates_table').innerHTML = vatRatesTable();
      var sel = document.getElementById('vat_add_country');
      if(sel) sel.outerHTML = vatAddSelect();
      /* The chips say "already in the box below" for a country whose row now
         matches, and the "fill in all" button counts what is still pending, so
         the bar is rebuilt with the table rather than left claiming six when
         two are already there. */
      var bar = document.getElementById('taxPresets');
      if(bar) bar.outerHTML = vatPresetBar();
      pstBind('taxPresets', taxPresetOpts);
    };

    /* One object, so the binding made here and the one remade on every repaint
       cannot drift. pstBind neither fetches nor saves; apply() puts a value in
       the in-memory table and Save changes is what publishes it. */
    var taxPresetOpts = {
      filled: function(code){ return (code in VAT_RATES) ? String(VAT_RATES[code]) : null; },
      harvest: harvest,
      apply: function(code, value){
        VAT_RATES[code] = String(value);
        /* THE DEFAULT BASIS, NEVER EXCLUSIVE. A preset knows a rate; it cannot
           know how this business prices. An existing row keeps whatever basis
           it already had, because filling in a rate is not a reason to undo a
           decision the owner made about that country. */
        if(!(code in VAT_BASES)) VAT_BASES[code] = vatDefaultBasis();
      },
      repaint: repaint,
      done: function(n){
        toast(n + (n===1 ? ' country filled in' : ' countries filled in') + ' — check the rates, then Save changes');
      },
    };

    pstBind('taxPresets', taxPresetOpts);

    band.addEventListener('click', function(ev){
      var del = ev.target.closest('[data-vat-del]');
      if(del){ var d=del.getAttribute('data-vat-del'); delete VAT_RATES[d]; delete VAT_BASES[d]; repaint(); return; }

      if(ev.target.closest('#vat_add_btn')){
        var pick = document.getElementById('vat_add_country');
        var rate = document.getElementById('vat_add_rate');
        if(!pick||!pick.value){ toast('Choose a country first'); return; }
        var typed = String(rate.value||'').trim();
        if(typed===''){ toast('Enter a rate for '+KBB_VAT_COUNTRIES[pick.value]); return; }
        VAT_RATES[pick.value] = typed;
        VAT_BASES[pick.value] = vatDefaultBasis();
        rate.value='';
        repaint();
      }
    });

    /* 'input' rather than 'change': the preview beside the box is the whole
       point of the column, and waiting for blur to show it defeats it. */
    band.addEventListener('input', function(ev){
      var box = ev.target.closest('[data-vat-rate]');
      if(!box) return;
      var code = box.getAttribute('data-vat-rate');
      VAT_RATES[code] = String(box.value||'').trim();
      var cell = box.parentNode.querySelector('.vr-prev');
      if(cell) cell.outerHTML = vatPreviewCell(VAT_RATES[code], vatBasisOf(code));
      box.parentNode.classList.toggle('vr-charges', vatBasisOf(code)==='exclusive' && parseFloat(VAT_RATES[code])>0);
    });

    /* The basis is a <select>, which fires 'change' and not 'input' in every
       browser worth naming. The whole row is repainted rather than just the
       preview, because switching to Exclusive also turns the row's warning
       state on. */
    band.addEventListener('change', function(ev){
      var sel = ev.target.closest('[data-vat-basis]');
      if(!sel) return;
      VAT_BASES[sel.getAttribute('data-vat-basis')] = String(sel.value||'').toLowerCase();
      document.getElementById('vat_rates_table').innerHTML = vatRatesTable();
    });
  }

  /* The wire values: two JSON objects of code -> value, which is what the
     'ratemap' and 'basismap' rules in AdminController::SETTING_RULES
     validate. Rows left blank are dropped rather than sent as empty strings,
     so clearing a box and saving is how a country goes back to the default. */
  function vatRatesPayload(){
    var out = {};
    Object.keys(VAT_RATES).forEach(function(code){
      var v = String(VAT_RATES[code]==null?'':VAT_RATES[code]).trim();
      if(v!=='') out[code] = v;
    });
    return JSON.stringify(out);
  }

  /* Only for countries that survive into the rate payload, so the two rows can
     never describe different tables. */
  function vatBasesPayload(){
    var rates = JSON.parse(vatRatesPayload());
    var out = {};
    Object.keys(rates).forEach(function(code){
      out[code] = vatBasisOf(code);
    });
    return JSON.stringify(out);
  }

  /* ---------- Business Details, in two tabs -------------------------------
     "ALSO i can not see the TAX seperate tab on the Business Setting page.
      please check and fix."

     He asked for "a seperate tab for 'Tax'" and came to Business Details to
     look for it, because that is where the VAT rate he already knew about sat.
     So the tab is here, on this screen, and the sidebar carries a Tax entry
     that opens this screen on it.

     BOTH PANELS ARE RENDERED AND ONE IS HIDDEN, never destroyed. There is a
     single Save button for the whole screen and it reads every field by id; a
     field that is not in the DOM reads as '' through sval() and would be saved
     over the value it is not showing. Hiding is the cheap, safe version of
     that; unmounting is the version that silently blanks the store name
     because the owner happened to be on the other tab. */
  var BD_TAB = 'business';

  function bdTabs(){
    return '<div class="bd-tabs" id="bdTabs">'+
      '<button type="button" class="bd-tab'+(BD_TAB==='business'?' on':'')+'" data-bdtab="business">Business</button>'+
      '<button type="button" class="bd-tab'+(BD_TAB==='tax'?' on':'')+'" data-bdtab="tax">Tax</button>'+
      /* THE THIRD TAB — Lane DG. Eight settings that decide who the invoice
         says it is from had a reader and no writer: not one of them was in
         AdminController::SETTING_RULES and not one was drawn anywhere in this
         console, so the owner's legal business name and address could not be
         put on his own invoices at all, and `invoice_trn` — which is half of
         what lets the document call itself a Tax Invoice — could not be
         entered. A tab and not a sidebar row: this is what the business is
         called and where it trades, which is the sentence at the top of this
         very screen. */
      '<button type="button" class="bd-tab'+(BD_TAB==='invoice'?' on':'')+'" data-bdtab="invoice">Invoice</button>'+
      /* THE FOURTH TAB — the integrator, finishing Lane DR.
         The shop stated several things about itself — "100% original",
         "24/7 support", "100% authentic" — as literals inside Blade files,
         which on a host with no shell means the owner could not change or
         withdraw a single one of them without a signed package. Lane DR made
         every one of them a setting, defaulting to the wording that shipped, so
         the page reads today exactly as it read yesterday. These are the boxes
         that make them his. A tab and not a sidebar row, for the same reason
         Invoice is: this is what the business says it is, which is the sentence
         at the top of this screen. */
      '<button type="button" class="bd-tab'+(BD_TAB==='claims'?' on':'')+'" data-bdtab="claims">Claims</button>'+
      '</div>';
  }

  async function renderStoreSettings(tab){
    await loadSettings();
    var taxLive = String(SETTINGS.tax_mode||'display')==='live';
    if(tab==='tax'||tab==='business'||tab==='invoice'||tab==='claims') BD_TAB = tab;
    // Before the markup is built: vatRatesBand() renders from VAT_RATES.
    vatRatesLoad();
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Business Details</h2>'+
      '<p>The handful of values the whole shop is built on — what the business is called, what money it takes, what delivery costs and what tax it charges. Everything here reaches the storefront and the checkout total the moment it is saved.</p></div>'+
      bdTabs()+
      '<div class="bd-wrap"><div class="bd-card">'+

      '<div class="bd-panel" data-bdpanel="business"'+(BD_TAB==='business'?'':' hidden')+'>'+
      bdSec('Store identity',
        'The name, the money and the clock every invoice, email and checkout total is built from. Come here when the business name changes, the shop starts taking a different currency, or it trades from a different city.',
        '<div class="bd-grid">'+
          bdField('set_store_name','Store name',
            '<input id="set_store_name" value="'+sesc(SETTINGS.store_name)+'">',
            'Shown in the browser tab, in emails and on invoices.')+
          /* WHAT THE SITE CALLS ITSELF, as opposed to what the business is
             called. Read by store/home.blade.php — it is the page's <h1>
             whenever the hero slider is off or has no slides — and by the
             wordmark on the shareable review wall at /reviews. Until
             AdminController::SETTING_RULES gained the key, NOTHING in the
             application wrote it: both readers fell back to a literal, so the
             line looked configurable and was not.

             Its own box and not a second use of Store name: that one signs the
             emails, heads the invoices and carries the footer copyright, and
             the two ship as different strings on purpose. The placeholder is
             the shipped line, because clearing the box puts it back — the
             storefront reads with `?:`, so an empty value means "the line we
             ship" and never an empty heading. */
          bdField('set_site_title','Site title',
            '<input id="set_site_title" value="'+sesc(SETTINGS.site_title)+'" placeholder="K-Beauty Bliss \u2014 authentic Korean skincare in the UAE">',
            'The heading the homepage shows when the hero slider is switched off, and the name at the top of the review wall. Leave it empty for the shipped line.')+
          bdField('set_currency','Currency',curSelect(),
            'Picking one fills in its symbol and decimals below.')+
          /* WHERE THE VAT RATE WENT. It was a field right here, and the owner
             knows it was. Removing it without saying so would be a dead end on
             the one screen he was told to look at, so its place is kept and it
             points at the tab that now owns it. */
          bdField('set_vat_jump','VAT rate (%)',
            '<button type="button" class="bd-jump" data-bdtab="tax">Now on the Tax tab →</button>',
            'The rate, whether it is included or added on top, and a rate per country all live together on the Tax tab.')+
          /* THE CLOCK EVERY DATE IN THE PANEL IS READ ON.

             Storage stays UTC and this never changes it — see
             App\Support\StoreTime. What this decides is which calendar day a
             stored instant is shown under: the dashboard's "today", each bar of
             the fourteen-day chart, the date printed on an invoice and on the
             customer's confirmation email. Left unset the shop reads Dubai,
             which is where it trades.

             A short list, not the whole tz database: every zone the shop could
             plausibly keep is here, and a free-text box is a way to mistype
             "Asia/Dubai" and have the panel quietly fall back without saying
             so. The server still validates whatever arrives. */
          bdField('set_store_timezone','Time zone',
            seoSel('set_store_timezone',SETTINGS.store_timezone,[
              ['Asia/Dubai','Dubai — UAE (UTC+4)'],
              ['Asia/Muscat','Muscat — Oman (UTC+4)'],
              ['Asia/Riyadh','Riyadh — Saudi Arabia (UTC+3)'],
              ['Asia/Kuwait','Kuwait (UTC+3)'],
              ['Asia/Qatar','Doha — Qatar (UTC+3)'],
              ['Asia/Bahrain','Manama — Bahrain (UTC+3)'],
              ['UTC','UTC — no offset']
            ],'Asia/Dubai'),
            'Which day an order counts towards, on every screen and document. Nothing already recorded is altered.')+
        '</div>')+

      /* ── HOW CUSTOMERS REACH YOU — Lane DI ──────────────────────────────
         Three settings that were read in five places and written in none. Two
         of them are SEEDED with this shop's real address and real number, so
         every install has been printing somebody's actual contact details on
         every page with no box anywhere to change them.

         ON THE BUSINESS TAB and not the Invoice tab, because only one of the
         three is about an invoice and even that one is a FALLBACK for the
         Invoice tab's own box — a fallback belongs one level up from the thing
         that falls back to it, not beside it. The WhatsApp number is on every
         page of the storefront and in every customer email; filing it under
         "Invoice" would say it was an invoice field.

         The placeholders show what the shop prints today when the box is
         blank, so an owner can see what he is replacing before he replaces it.
         Clearing a box goes back to that, rather than to an empty line. */
      bdSec('How customers reach you',
        'The phone number and email address the shop shows to customers. The WhatsApp number runs the chat buttons in the header, the footer and the mobile menu; the email is what an invoice falls back to when the Invoice tab’s own box is blank.',
        '<div class="bd-grid">'+
          bdField('set_support_phone','Phone number',
            '<input id="set_support_phone" value="'+sesc(SETTINGS.support_phone)+'" placeholder="+971 58 505 2611">',
            'Printed in the site header and at the foot of every page. Written exactly as you type it.')+
          bdField('set_brand_whatsapp','WhatsApp number',
            '<input id="set_brand_whatsapp" value="'+sesc(SETTINGS.brand_whatsapp)+'" placeholder="+971585052611">',
            'What the “Chat on WhatsApp” buttons open. Spaces and brackets are fine — the link uses the digits.')+
          bdField('set_support_email','Support email',
            '<input id="set_support_email" type="email" value="'+sesc(SETTINGS.support_email)+'" placeholder="info@kbeautybliss.com">',
            'Printed on invoices when the Invoice tab has no address of its own.')+
        '</div>')+

      /* ── WHERE THE SHOP IS — Lane S ─────────────────────────────────────
         The SEO screen has offered an Organization type of "Store" and
         "LocalBusiness" since it was built, and choosing either published
         nothing that makes a business local: no address, no coordinates, no
         hours. These are the boxes behind that choice. App\Support\
         BusinessAddress decides what may be said and refuses to publish half
         an address; App\Support\OpeningHours is the one parser that both this
         screen's validator and the JSON-LD emitter call.

         ON THIS TAB, beside the store name, the currency and the time zone,
         because where a business trades is a fact about the business — the
         same judgement Lane DI made putting the support phone here rather than
         on Mail. It is not on SEO & Meta: that screen is where you say how
         facts are presented to a search engine, not where the shop's street
         lives.

         THE PHONE IS NOT REPEATED HERE. The number published to Google is the
         one in "How customers reach you" directly above, which is already the
         number the site header and footer print. A second box would be a
         second place for one fact.

         Every box ships blank, so a shop that never opens this section emits
         exactly the JSON-LD it emitted before the section existed. */
      bdSec('Where the shop is',
        'Your trading address, and — if you have a shop customers can walk into — its map pin and opening hours. This is what tells Google the business is in this city; it is published on every page as structured data, so leave it blank if you trade online only. Nothing here changes your invoices: the address printed on those is on the Invoice tab.',
        '<div class="bd-grid">'+
          bdField('set_store_street','Street address',
            '<input id="set_store_street" value="'+sesc(SETTINGS.store_street)+'" placeholder="Shop 4, Al Wasl Road">',
            'The building, unit and street, written as you would on a delivery note.')+
          bdField('set_store_locality','City',
            '<input id="set_store_locality" value="'+sesc(SETTINGS.store_locality)+'" placeholder="Dubai">',
            'Required, along with the street and the country, before anything is published.')+
          bdField('set_store_region','Emirate or region',
            '<input id="set_store_region" value="'+sesc(SETTINGS.store_region)+'" placeholder="Dubai">',
            'Optional.')+
          bdField('set_store_postcode','Postal code',
            '<input id="set_store_postcode" value="'+sesc(SETTINGS.store_postcode)+'" placeholder="">',
            'Optional, and normally empty in the UAE — street addresses here do not carry one.')+
          bdField('set_store_country','Country',
            '<input id="set_store_country" value="'+sesc(SETTINGS.store_country)+'" placeholder="AE" maxlength="2" style="text-transform:uppercase">',
            'Two letters, like AE for the United Arab Emirates.')+
        '</div>'+
        '<div class="bd-grid">'+
          bdField('set_store_latitude','Latitude',
            '<input id="set_store_latitude" value="'+sesc(SETTINGS.store_latitude)+'" placeholder="25.2048">',
            'From the map pin. Both this and the longitude are needed, or neither is published.')+
          bdField('set_store_longitude','Longitude',
            '<input id="set_store_longitude" value="'+sesc(SETTINGS.store_longitude)+'" placeholder="55.2708">',
            'Only published when the Organization type on SEO &amp; Meta is Store or LocalBusiness — those are the only two that can sit on a map.')+
        '</div>'+
        bdField('set_store_hours','Opening hours',
          '<textarea id="set_store_hours" rows="4" placeholder="Mon-Sat 10:00-22:00&#10;Sun 12:00-20:00">'+sesc(SETTINGS.store_hours)+'</textarea>',
          'One line per set of hours, like <b>Mon-Sat 10:00-22:00</b>. Use Mon, Tue, Wed, Thu, Fri, Sat, Sun — alone, in a range, or separated by commas. A day you do not list is treated as closed. Saving tells you which line it could not read.'))+

      /* YOUR BRAND COLOUR (Lane DN).

         `brand_accent` had a reader and no writer. App\View\Composers\Store-
         Composer has read it since the baseline and SettingsSeeder seeds it
         with #E0567B, and nothing in this console has ever written it — so the
         one colour every page on the storefront is built from was decided by a
         seeder and could not be reached by its owner.

         THE CONTROL AND ITS WRITE PATH LAND TOGETHER, which on this screen
         means three things and not one: the field below, the line in
         AdminController::SETTING_RULES, and set_brand_accent in the payload
         the Save button posts. A field without the rule saves nothing while
         the endpoint answers ok — the standing warning at the top of that
         list. A field without the payload line is not sent at all. An earlier
         lane on this screen shipped a control that saved nothing, which is why
         AdminScreenSectionsTest checks that every id the handler posts is
         drawn; the reverse — an id drawn and never posted — is checked by this
         lane's own test.

         TWO CONTROLS FOR ONE VALUE, on purpose, because a colour is the one
         setting an owner wants to SEE. The swatch is the real editor and the
         text box beside it is what makes the value copy-and-pasteable and
         typeable; each writes the other, and only the text box carries the id
         the payload reads, so there is still exactly one field being saved.
         The endpoint normalises what it stores, so the two cannot disagree
         about case or a missing #.

         ON THE BUSINESS TAB, beside the shop's name, its contact details and
         its currency, because it is the same kind of fact: something about
         this shop rather than something about a screen. The Theme screen is an
         index of where design is set and now points here. */
      bdSec('Your brand colour',
        'The accent colour of your storefront — buttons, prices, links, the sale badges and the highlight on anything selected. One colour: the darker shade used beside it is worked out from this one, so the two can never drift apart.',
        '<div class="bd-grid">'+
          bdField('set_brand_accent','Accent colour',
            '<div class="bd-colour">'+
              '<input id="set_brand_accent_pick" type="color" aria-label="Pick the accent colour" value="'+sesc(bdAccentSwatch())+'">'+
              '<input id="set_brand_accent" value="'+sesc(bdAccentValue())+'" placeholder="#E0567B" spellcheck="false">'+
            '</div>',
            'Leave it blank to go back to the shop’s original pink. Written as a hex colour, like #E0567B.')+
        '</div>'+
        '<div class="bd-note">Changing this repaints the storefront, not this console — the admin’s own colours are a console preference under <b>Console</b>. Every other colour in the theme (the soft pinks behind sections, the gold, the ink) is part of the stylesheet and is not a setting.</div>')+

      bdSec('How prices are printed',
        'How every price on the storefront is written — the symbol, where it sits and how many decimals. Choosing a currency above fills these in, and you can still override any of them.',
        '<div class="bd-grid">'+
          bdField('set_currency_symbol','Symbol',
            '<input id="set_currency_symbol" value="'+sesc(SETTINGS.currency_symbol)+'" placeholder="'+sesc(curSymbol())+'">',
            'Leave blank to use the selected currency’s own symbol.')+
          bdField('set_currency_symbol_render','Symbol rendering',
            seoSel('set_currency_symbol_render',SETTINGS.currency_symbol_render,[['unicode','Unicode character — correct, may show an empty box'],['svg','Drawn glyph (SVG) — always renders']],'unicode'),
            'See the note below before changing this.')+
        '</div>'+
        /* Two to a row rather than four in one auto-fit grid. Four 230px
           tracks plus their gaps come to 962px and this card's inner width is
           957px, which is close enough that the row is one browser's rounding
           away from becoming three-and-one. An explicit pair cannot do that. */
        '<div class="bd-grid">'+
          bdField('set_currency_position','Symbol position',
            seoSel('set_currency_position',SETTINGS.currency_position,[['before','Before the number — '+sesc(curSymbol())+'199'],['before_space','Before, with a space — '+sesc(curSymbol())+' 199'],['after','After the number — 199'+sesc(curSymbol())],['after_space','After, with a space — 199 '+sesc(curSymbol())]],'before'),
            'Each option shows what 199 would look like.')+
          bdField('set_currency_decimals','Decimal places',
            '<input id="set_currency_decimals" type="number" min="0" max="4" step="1" value="'+sesc(SETTINGS.currency_decimals)+'" placeholder="blank — whole numbers">',
            'Also how stored amounts are read back. Blank keeps whole dirhams.')+
        '</div>'+
        '<div class="bd-note">The dirham sign “⃣” was accepted by Unicode in July 2025 and ships in Unicode 18.0 (September 2026), so most devices have no font glyph for it yet and draw an empty box instead. <b>Unicode</b> is the default because it puts the real character in the page — right for copy-paste, screen readers and search engines. If the empty box bothers you, switch to <b>Drawn glyph</b>: the storefront then draws the symbol itself and it always renders. <b>2 decimal places</b> means hundredths, which is how every amount already in the database is stored.</div>')+

      bdSec('Delivery and cash on delivery',
        'What the shop charges to get an order to the door, and the basket size at which it stops charging. All three amounts are in AED.',
        '<div class="bd-grid">'+
          bdField('set_free_ship','Free-shipping threshold',
            '<input id="set_free_ship" type="number" step="1" value="'+money2aed('free_ship')+'">',
            'Delivery is free once the cart subtotal reaches this. Measured on the product total before any tax, so it means the same thing in every country.')+
          bdField('set_delivery','Flat delivery fee',
            '<input id="set_delivery" type="number" step="1" value="'+money2aed('delivery_flat')+'">',
            'Charged on every order below the threshold.')+
          bdField('set_cod','Cash-on-delivery fee',
            '<input id="set_cod" type="number" step="1" value="'+money2aed('cod_fee')+'">',
            'Added when the shopper chooses to pay on delivery.')+
        '</div>')+
      '</div>'+

      '<div class="bd-panel" data-bdpanel="tax"'+(BD_TAB==='tax'?'':' hidden')+'>'+
      /* THE TAX MODE SWITCH, and the single most consequential control on
         this screen. Until it says "Applied to orders" the whole tab is a
         printing preference and nothing below it can charge anybody — which
         is how this feature ships, so that applying the update changes
         nothing at all until the owner comes here and decides otherwise.

         WRITTEN OUT HERE rather than in a helper of its own, because the
         single Save button below reads every field by id through sval() and
         AdminScreenSectionsTest checks that each id it posts is DRAWN in
         this function. A field drawn somewhere this function cannot be seen
         to draw is a field that guard stops watching — and the failure it
         watches for is a save that quietly overwrites a setting with ''. */
      bdSec('How tax works on this shop',
        'One switch decides whether the rates below are printed on the receipt or actually applied to what the customer pays. It arrives set to printing only, exactly as this shop has always behaved.',
        '<div class="bd-grid">'+
          bdField('set_tax_mode','Tax mode',
            seoSel('set_tax_mode',SETTINGS.tax_mode,[
              ['display','Printed only — show VAT on the receipt, charge nothing'],
              ['live','Applied to orders — use the rates below for real']
            ],'display'),
            'Switch this to “Applied to orders” only when the rates and bases below are the ones you want to trade on.')+
          bdField('set_vat_enabled','Show the VAT line',
            seoSel('set_vat_enabled',(SETTINGS.vat_enabled==null||SETTINGS.vat_enabled===''||SETTINGS.vat_enabled==='1'||SETTINGS.vat_enabled===1||SETTINGS.vat_enabled===true)?'1':'0',[
              ['1','Yes — show it in the cart and at checkout'],
              ['0','No — say nothing about VAT']
            ],'1'),
            'Turning this off also stops any tax being charged. A tax the customer cannot see is not one this shop will add.')+
          bdField('set_vat','Default rate (%)',
            '<input id="set_vat" type="number" step="0.01" value="'+sesc(SETTINGS.vat_rate)+'">',
            'Used for every country at once, unless one is given its own rate below.')+
          bdField('set_vat_basis','Default basis',
            seoSel('set_vat_basis',SETTINGS.vat_basis,[
              ['inclusive','Inclusive — the price already includes it'],
              ['exclusive','Exclusive — added on top of the price'],
              ['flat','Printed only — charges nothing']
            ],'inclusive'),
            'What the default rate does. Only “Exclusive” raises what the customer pays.')+
          bdField('set_vat_label','Wording on the receipt',
            '<input id="set_vat_label" value="'+sesc(SETTINGS.vat_label==null?"You\'re paying VAT ({rate}%)":SETTINGS.vat_label)+'">',
            'Use {rate} where the percentage should appear.')+
          /* A TRN IS A TAX NUMBER AND THIS IS THE TAX TAB, so this is where the
             owner comes looking for it — the same reasoning that put the Tax
             tab on this screen in the first place. It is entered on the Invoice
             tab, beside the name and address it is printed under, and this
             points at it rather than drawing a second box for one setting.
             `data-bdtab` is what the single tab listener below reads, so this
             needs no handler of its own. */
          bdField('set_trn_jump','Tax registration number (TRN)',
            '<button type="button" class="bd-jump" data-bdtab="invoice">Now on the Invoice tab \u2192</button>',
            'Your TRN is printed on the invoice under your business name, so it is entered there with the rest of your business details.')+
        '</div>'+
        (taxLive
          ? '<div class="bd-note"><b>Tax is being applied to real orders.</b> Every country on an Exclusive basis below is charging its rate on top of the basket, and every order records the rate and basis it was charged at, so old receipts keep saying what they said on the day.</div>'
          : '<div class="bd-note"><b>Nothing here is being charged.</b> The VAT line is printed beside the total and the total is unaffected, which is how this shop has always worked. Set Tax mode to “Applied to orders” when you are ready for the rates below to be real — and check them first, because from that moment an Exclusive country charges more.</div>'))+
      vatRatesBand()+
      '</div>'+

      /* ---------- The Invoice tab (Lane DG) --------------------------------
         "his legal business name and address are not on his invoices, and he
          has no way to put them there."

         App\Services\Invoices\InvoiceDocument::seller() has read eight settings
         since the day it was written. None of them was in
         AdminController::SETTING_RULES, so PUT /admin-api/settings rejected
         every one as an unknown key, and no field for any of them was drawn
         anywhere in this console. A reader with no writer: the invoice printed
         the fallbacks for ever and there was no box to change them in.

         WHY A TAB HERE RATHER THAN A ROW IN THE SIDEBAR. This screen's own
         heading says it holds "the handful of values the whole shop is built on
         — what the business is called". The legal name, the trading address and
         the tax registration number ARE that, and the Store name box on the
         Business tab already tells the owner it is "shown … on invoices". A
         sidebar row would be a twenty-first Store entry for a screen he fills in
         once, and it would split one idea — who this business is — across two
         places. The Tax tab is the precedent: the owner went looking for tax on
         Business Details, so tax lives on Business Details.

         AND IT IS REACHABLE FROM WHERE HE WOULD LOOK. A TRN is a tax number and
         the Tax tab is where he would look for it, so that tab carries a
         pointer to this one, the same way the Business tab keeps the VAT rate's
         old place and points at Tax. Both pointers are `data-bdtab`, which the
         one tab listener below already understands.

         EVERY BOX SHIPS BLANK AND NOTHING IS INVENTED HERE. seller() falls back
         for each one, so an owner who never opens this tab gets exactly the
         invoice he gets today, to the byte. */
      '<div class="bd-panel" data-bdpanel="invoice"'+(BD_TAB==='invoice'?'':' hidden')+'>'+
      bdSec('Who the invoice comes from',
        'The name, address and contact details printed at the top of every invoice and packing slip. Leave a box empty and that line simply does not appear — nothing here is guessed at or filled in for you.',
        '<div class="bd-grid">'+
          bdField('set_invoice_business_name','Business name',
            '<input id="set_invoice_business_name" value="'+sesc(SETTINGS.invoice_business_name)+'" placeholder="'+sesc(SETTINGS.store_name||'')+'">',
            'Your legal trading name, if it differs from the store name. Blank uses the store name.')+
          bdField('set_invoice_phone','Phone',
            '<input id="set_invoice_phone" value="'+sesc(SETTINGS.invoice_phone)+'" placeholder="+971 …">',
            'Printed under your name. Any format you would write on a letterhead.')+
        '</div>'+
        '<div class="bd-grid">'+
          bdField('set_invoice_email','Email',
            '<input id="set_invoice_email" type="email" value="'+sesc(SETTINGS.invoice_email)+'" placeholder="info@example.com">',
            'Where a customer holding this invoice should write to you.')+
          bdField('set_invoice_website','Website',
            '<input id="set_invoice_website" value="'+sesc(SETTINGS.invoice_website)+'" placeholder="kbeautybliss.com">',
            'With or without https:// — whichever you would print.')+
        '</div>'+
        '<div class="bd-grid">'+
          bdField('set_invoice_address','Address',
            '<textarea id="set_invoice_address" rows="4" placeholder="Office 1902, Burlington Tower&#10;Business Bay, Dubai&#10;United Arab Emirates">'+sesc(SETTINGS.invoice_address)+'</textarea>',
            'One line per line, exactly as you want it printed.')+
        '</div>')+

      bdSec('Tax registration, and what the document calls itself',
        'Two settings that decide the two words at the top of the page. Both are yours and your accountant’s to answer — this shop will not put a tax claim on a document on your behalf.',
        '<div class="bd-grid">'+
          bdField('set_invoice_trn','Tax registration number (TRN)',
            '<input id="set_invoice_trn" value="'+sesc(SETTINGS.invoice_trn)+'" placeholder="blank — nothing is printed">',
            'Printed under your name as “TRN …”, and beside the VAT line. Blank prints neither.')+
          bdField('set_invoice_doctype','What the invoice calls itself',
            '<input id="set_invoice_doctype" value="'+sesc(SETTINGS.invoice_doctype)+'" placeholder="blank — decided from the order">',
            'Printed at the top of the page, word for word. Blank lets the rule below decide.')+
        '</div>'+
        /* THE RULE THIS BOX OVERRIDES, spelled out, because overriding it is
           the whole point of the box and an owner cannot consent to something
           he has not been told. */
        '<div class="bd-note">Left blank, the heading is worked out from the order itself: it reads <b>Tax Invoice</b> only when tax was really charged or is really contained in the total <b>and</b> a TRN is entered above — otherwise it reads <b>Invoice</b>, which is true of every invoice. Typing something in the second box overrides that in every case and prints exactly what you type. “Tax Invoice”, “Simplified Tax Invoice” and the rest are terms with legal meanings in the UAE and the GCC; what yours must say is a question for your accountant, not for this shop.</div>')+

      bdSec('The small print at the foot',
        'Anything you want at the bottom of every invoice — payment terms, your returns policy, bank details. It is printed as typed, and blank prints nothing at all.',
        '<div class="bd-grid">'+
          bdField('set_invoice_footer','Invoice footer',
            '<textarea id="set_invoice_footer" rows="3" placeholder="blank — nothing is printed">'+sesc(SETTINGS.invoice_footer)+'</textarea>',
            'Shown on the invoice only. The packing slip never carries it.')+
        '</div>')+
      '</div>'+

      /* ===================================================================
         CLAIMS — what the shop says about itself, in the owner's own words.

         Every box below is pre-filled with the wording that is on the site
         today, which is why opening this tab and saving without touching
         anything changes nothing. The boxes are not required and must never
         become required: CLEARING ONE REMOVES THE CLAIM FROM THE SITE, element
         and all, and that is the half an owner actually needs — "I cannot
         stand behind this" has to be expressible, and in a text box the only
         way to express it is to empty it. App\Support\TrustClaims::text()
         returns null for a cleared or whitespace-only value, which is what the
         templates skip on; see its header for why null and not ''.

         Counts are deliberately absent. The brand count, the product count and
         the review count beside these claims are counted from the catalogue
         and are not editable here, because a number an owner can type is a
         number that can drift away from the shop it describes.
         =================================================================== */
      '<div class="bd-panel" data-bdpanel="claims"'+(BD_TAB==='claims'?'':' hidden')+'>'+
      bdSec('On the home page',
        'The three short promises in the row of badges under the hero, and the line beside your brands. Each is exactly as it reads on the site now. Change the words to what you actually stand behind — or empty a box and that badge disappears, with the row closing up neatly around it.',
        '<div class="bd-grid">'+
          bdField('set_trust_authentic_title','Authenticity badge — title',
            '<input id="set_trust_authentic_title" value="'+sesc(bdClaim('trust_authentic_title'))+'" placeholder="empty — the badge is removed">',
            'Currently reads “100% original”. Empty removes the whole badge, not just the words.')+
          bdField('set_trust_authentic_text','Authenticity badge — the line under it',
            '<input id="set_trust_authentic_text" value="'+sesc(bdClaim('trust_authentic_text'))+'" placeholder="empty — no line under the title">',
            'Currently reads “Direct from brands and trusted suppliers”.')+
          bdField('set_trust_support_title','Support badge — title',
            '<input id="set_trust_support_title" value="'+sesc(bdClaim('trust_support_title'))+'" placeholder="empty — the badge is removed">',
            'Currently reads “24/7 support”. Your WhatsApp number is printed underneath automatically, from Store identity above.')+
          bdField('set_home_brands_note','Brands section — the line under the heading',
            '<input id="set_home_brands_note" value="'+sesc(bdClaim('home_brands_note'))+'" placeholder="empty — no line under the heading">',
            'Currently reads “Korean brands, all sourced direct.” The number of brands beside it is counted from your catalogue and cannot be typed.')+
        '</div>')+

      bdSec('At the checkout',
        'The two reassurance lines a shopper reads with their card in their hand — the moment a claim matters most, and the moment it is hardest to take back.',
        '<div class="bd-grid">'+
          bdField('set_checkout_authentic_text','Beside the Place order button',
            '<input id="set_checkout_authentic_text" value="'+sesc(bdClaim('checkout_authentic_text'))+'" placeholder="empty — the chip is removed">',
            'Currently reads “100% authentic”, next to “SSL secure”. Empty removes the chip and its tick. “SSL secure” stays either way — that one is a fact about the connection, not a claim about the shop.')+
          bdField('set_reassure_auth_text','Above the order summary',
            '<input id="set_reassure_auth_text" value="'+sesc(bdClaim('reassure_auth_text'))+'" placeholder="empty — the line is removed">',
            'Currently reads “100% authentic K-beauty”. The star rating beside it comes from your approved reviews and cannot be typed.')+
        '</div>')+

      /* Its own key, not the checkout one, and the reason is the feature's
         whole point: on a shared key "take that off the product page" would
         also strip the chip beside Place order — a second decision he never
         made, on the page where being wrong costs money. TrustClaims already
         has byte-identical defaults under separate keys for the same reason,
         and the tab is organised by placement so the duplication is visible. */
      bdSec('On the product page',
        'The reassurance chips under the Add to basket button, on every product in the shop. Delivery and returns beside this one are set elsewhere.',
        '<div class="bd-grid">'+
          bdField('set_product_authentic_text','Beside delivery and returns',
            '<input id="set_product_authentic_text" value="'+sesc(bdClaim('product_authentic_text'))+'" placeholder="empty — the chip is removed">',
            'Currently reads “100% authentic”. Empty removes the chip and its shield icon, and the other chips close up around it. This is a separate box from the checkout one above on purpose: clearing one must not silently clear the other.')+
        '</div>')+

      bdSec('On the announcement strip',
        'The thin bar that runs above the header. Kept here so every claim the shop makes is in one place — note that this strip is not switched on at the moment, so nothing you type here is visible yet.',
        '<div class="bd-grid">'+
          bdField('set_anno_authentic_text','Announcement strip — authenticity claim',
            '<input id="set_anno_authentic_text" value="'+sesc(bdClaim('anno_authentic_text'))+'" placeholder="empty — the claim is removed">',
            'Currently reads “100% authentic K-beauty”.')+
        '</div>')+
      '<div class="bd-note">Nothing here is required, and an empty box is a real instruction rather than a mistake: the claim is removed from the page entirely, leaving no gap and no empty badge. Whether any of these statements is true is a question about your business, not about this shop — which is exactly why the words belong to you and not to a file only a release can change.</div>'+
      '</div>'+

      '</div>'+
      '<div class="bd-actions"><button class="btn" id="set_save_biz">Save changes</button></div>'+
      '</div></div>';
    vatRatesBind();

    /* One listener for the tab strip and for the pointer button beside where
       the VAT rate used to be — both carry data-bdtab, so "take me to Tax"
       means one thing on this screen however it is asked for. */
    document.querySelector('#content').addEventListener('click', function(ev){
      var t = ev.target.closest('[data-bdtab]');
      if(!t) return;
      BD_TAB = t.getAttribute('data-bdtab');
      document.querySelectorAll('[data-bdpanel]').forEach(function(p){
        p.hidden = p.getAttribute('data-bdpanel') !== BD_TAB;
      });
      document.querySelectorAll('.bd-tab').forEach(function(b){
        b.classList.toggle('on', b.getAttribute('data-bdtab') === BD_TAB);
      });
      window.scrollTo(0,0);
    });

    /* Picking a currency fills in its symbol and decimals; both stay editable. */
    var curSel=document.getElementById('set_currency');
    if(curSel) curSel.onchange=function(){
      var c=curFind(curSel.value); if(!c) return;
      var symEl=document.getElementById('set_currency_symbol'), decEl=document.getElementById('set_currency_decimals');
      if(symEl){ symEl.value=c.symbol; symEl.placeholder=c.symbol; }
      if(decEl) decEl.value=String(c.decimals);
    };

    /* The default basis is what every preset row lands on and what the
       per-country previews compare against, so changing it has to repaint the
       table below rather than leaving it describing the old default. */
    var basisSel=document.getElementById('set_vat_basis');
    if(basisSel) basisSel.onchange=function(){
      SETTINGS.vat_basis = basisSel.value;
      var tbl=document.getElementById('vat_rates_table');
      if(tbl) tbl.innerHTML = vatRatesTable();
      /* The preset bar compares chips against the rows, not against the
         default basis, so it does not need rebuilding here — only the table,
         whose previews and warning stripes read the default. */
    };

    var modeSel=document.getElementById('set_tax_mode');
    if(modeSel) modeSel.onchange=function(){
      SETTINGS.tax_mode = modeSel.value;
      var tbl=document.getElementById('vat_rates_table');
      if(tbl) tbl.innerHTML = vatRatesTable();
    };

    /* The swatch and the box are one field shown two ways, so each writes the
       other. Only the BOX carries the id the payload reads, which is what keeps
       "one control, one setting" true however many ways it can be edited.

       The swatch is not allowed to clear the box. <input type="color"> has no
       empty state -- it always reports a colour -- so a picker that wrote on
       every event would turn "blank, use the theme's pink" into an explicit
       #e0567b the first time anything on this tab was touched, and the owner
       would lose the ability to go back to the default without knowing he had.
       It writes only when the owner actually opens it and chooses. */
    var accentBox=document.getElementById('set_brand_accent');
    var accentPick=document.getElementById('set_brand_accent_pick');
    if(accentBox&&accentPick){
      accentPick.oninput=function(){ accentBox.value=accentPick.value; };
      accentBox.oninput=function(){
        SETTINGS.brand_accent=accentBox.value;
        accentPick.value=bdAccentSwatch();
      };
    }

    document.getElementById('set_save_biz').onclick=async function(){
      var payload={
        store_name: sval('set_store_name'), currency: sval('set_currency'), vat_rate: sval('set_vat'),
        // Needs its line in AdminController::SETTING_RULES, which it has — the
        // standing warning at the top of that list is that a key without one
        // is dropped while the endpoint still answers ok.
        site_title: sval('set_site_title'),
        store_timezone: sval('set_store_timezone'),
        // The Tax tab. Every one of these needs a line in
        // AdminController::SETTING_RULES or the endpoint answers ok and writes
        // nothing — the standing warning at the top of that list.
        tax_mode: sval('set_tax_mode'),
        vat_enabled: sval('set_vat_enabled'),
        vat_basis: sval('set_vat_basis'),
        vat_label: sval('set_vat_label'),
        // JSON object strings, not arrays: checkSetting() refuses arrays
        // outright, and 'ratemap' / 'basismap' unpack and validate them key by
        // key. The two describe one table and are built from one source, so a
        // country cannot have a basis without a rate.
        vat_country_rates: vatRatesPayload(),
        vat_country_bases: vatBasesPayload(),
        currency_symbol: sval('set_currency_symbol'),
        currency_symbol_render: sval('set_currency_symbol_render'),
        currency_position: sval('set_currency_position'),
        currency_decimals: sval('set_currency_decimals'),
        free_ship: String(Math.round((parseFloat(sval('set_free_ship'))||0)*100)),
        delivery_flat: String(Math.round((parseFloat(sval('set_delivery'))||0)*100)),
        cod_fee: String(Math.round((parseFloat(sval('set_cod'))||0)*100)),
        /* The Invoice tab (Lane DG). Every one of these had a reader in
           App\Services\Invoices\InvoiceDocument and no writer anywhere, and
           they are posted here for the same reason the Tax tab's keys are:
           a key with no line in AdminController::SETTING_RULES is dropped
           while this endpoint still answers ok, which is the standing warning
           at the top of that list and how "Saved" comes to mean nothing.
           All eight are sent every time, blank included, so clearing a box is
           a way of taking a line off the invoice. */
        invoice_business_name: sval('set_invoice_business_name'),
        invoice_address: sval('set_invoice_address'),
        invoice_trn: sval('set_invoice_trn'),
        invoice_email: sval('set_invoice_email'),
        invoice_phone: sval('set_invoice_phone'),
        invoice_website: sval('set_invoice_website'),
        invoice_footer: sval('set_invoice_footer'),
        invoice_doctype: sval('set_invoice_doctype'),
        /* How customers reach you (Lane DI). Same reason as the two blocks
           above: without a line in AdminController::SETTING_RULES these are
           dropped while the endpoint still answers ok. All three are sent every
           time, blank included, so clearing a box is a way of going back to
           what the shop shipped with. */
        support_phone: sval('set_support_phone'),
        brand_whatsapp: sval('set_brand_whatsapp'),
        support_email: sval('set_support_email'),
        /* Where the shop is (Lane S). Same reason as every block above: a key
           with no line in AdminController::SETTING_RULES is dropped while this
           endpoint still answers ok. All eight are sent every time, blank
           included, so clearing a box is how an address is withdrawn — and
           blank is the shipped state, in which nothing is published at all. */
        store_street: sval('set_store_street'),
        store_locality: sval('set_store_locality'),
        store_region: sval('set_store_region'),
        store_postcode: sval('set_store_postcode'),
        store_country: sval('set_store_country'),
        store_latitude: sval('set_store_latitude'),
        store_longitude: sval('set_store_longitude'),
        store_hours: sval('set_store_hours'),
        /* Your brand colour (Lane DN). The reader — StoreComposer, through
           layouts/store.blade.php — has existed since the baseline; this line
           and the `brand_accent` rule in AdminController::SETTING_RULES are the
           writer, and neither half does anything without the other. Sent every
           time, blank included, because blank is the instruction to go back to
           the theme's own pink rather than an absent field. */
        brand_accent: sval('set_brand_accent'),
        /* Claims (Lane DR's readers, this console's writers). Sent every time,
           blank included — blank is the instruction to remove the claim, and a
           field that is only sent when non-empty can never carry it. All seven
           are in AdminController::SETTING_RULES as `text`, which accepts
           blank on purpose; without that line the endpoint answers ok and
           writes nothing. */
        trust_authentic_title: sval('set_trust_authentic_title'),
        trust_authentic_text: sval('set_trust_authentic_text'),
        trust_support_title: sval('set_trust_support_title'),
        home_brands_note: sval('set_home_brands_note'),
        checkout_authentic_text: sval('set_checkout_authentic_text'),
        product_authentic_text: sval('set_product_authentic_text'),
        reassure_auth_text: sval('set_reassure_auth_text'),
        anno_authentic_text: sval('set_anno_authentic_text')

      };
      try{ await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})}); Object.assign(SETTINGS,payload); toast('Business details saved'); }
      catch(e){
        /* The endpoint validates all of it and writes none of it when one
           value is wrong, and says which. Showing that beats "check
           connection" — nothing was saved and the owner needs to know what to
           change, not to go and look at his router. */
        toast((e && e.body && e.body.message) ? e.body.message : 'Save failed — check connection', 'bad');
      }
    };
  }

  function seoSel(id,cur,opts,dflt){ cur=(cur==null||cur==='')?dflt:cur; return '<select class="inp" id="'+id+'" style="width:100%">'+opts.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===cur?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>'; }

  /**
   * THE ONE IMAGE FIELD. Five screens are drawn by this function — the SEO
   * share image, the organisation logo, a brand logo, a category image and an
   * attribute swatch — so the shape of the control is decided here once for
   * all of them. Converting the helper rather than its five callers is also
   * what keeps a sixth caller from being born raw.
   *
   * CLICKING THE ZONE OPENS THE MEDIA LIBRARY. It used to open the browser's
   * own file dialog, through a transparent <input type="file"> stretched over
   * the whole zone, and that is the bug the owner reported twice in the same
   * words: a file dialog can only send a file from this computer, so an image
   * already in the library had to be hunted down and uploaded a second time.
   * The picker lists what is already there and carries its own "Upload new",
   * which files a new image in the library and then selects it — so uploading
   * is still one dialog away, it simply cannot produce a duplicate any more.
   *
   * NOTHING THAT WORKED BEFORE STOPS WORKING. Dragging a file onto the zone
   * still uploads it straight away (a drop carries its own files and needs no
   * input element), and it goes to the same /admin-api/media/upload, so a
   * dropped file lands in the library too. "or paste a URL directly" is
   * untouched. The value written is what it always was: a URL string in the
   * hidden input #<id>, which every caller reads with sval(id).
   */
  function imgUploadField(id,curUrl,label,folder){
    var hasImg = curUrl && curUrl.trim()!=='';
    return '<div class="fld"><label>'+label+'</label>'+
      '<div class="imgup" id="'+id+'_zone" role="button" tabindex="0" style="border:1.5px dashed var(--border);border-radius:10px;padding:14px;text-align:center;cursor:pointer;position:relative">'+
      '<div id="'+id+'_preview" style="'+(hasImg?'':'display:none')+';margin-bottom:8px"><img src="'+sesc(curUrl)+'" style="max-height:70px;max-width:100%;border-radius:6px;display:'+(hasImg?'block':'none')+';margin:0 auto"></div>'+
      '<div id="'+id+'_prompt" style="font-size:12px;color:var(--ink-soft)">'+(hasImg?'Click to replace from the Media Library':'Click to choose from the Media Library, or drag an image here')+'</div>'+
      '<div id="'+id+'_status" style="font-size:11.5px;color:var(--ink-soft);margin-top:4px"></div>'+
      '</div>'+
      /* The same action as clicking the zone, spelled out. The zone reads as a
         drop target to some operators and as a button to others; the labelled
         button removes the guess. */
      '<div style="margin-top:7px"><button type="button" class="btn ghost" id="'+id+'_lib" style="font-size:12px;padding:6px 10px">Choose from Media Library</button></div>'+
      '<input type="hidden" id="'+id+'" value="'+sesc(curUrl)+'">'+
      '<p class="description" style="margin:6px 0 0"><a href="#" id="'+id+'_manual" style="font-size:11.5px">or paste a URL directly</a></p>'+
      '<input id="'+id+'_url" class="inp" style="display:none;margin-top:6px" value="'+sesc(curUrl)+'" placeholder="https://…"></div>';
  }

  /* label is passed so the picker's heading can name the field the operator
     just clicked. It used to be read as a free variable in here, where no such
     binding exists — a ReferenceError that killed the click before the dialog
     could open. Optional, so the five existing two-argument calls keep working. */
  function wireImgUpload(id,folder,label){
    var zone=document.getElementById(id+'_zone'),
        hidden=document.getElementById(id), preview=document.getElementById(id+'_preview'),
        img=preview?preview.querySelector('img'):null, prompt=document.getElementById(id+'_prompt'),
        status=document.getElementById(id+'_status'), manualLink=document.getElementById(id+'_manual'),
        urlInput=document.getElementById(id+'_url');
    if(!zone) return;

    /* Read back off the rendered <label> when the caller did not pass one, so
       none of the five existing call sites has to change — several of them sit
       in screens other lanes are editing right now, and a field's own label is
       the same string imgUploadField was given anyway. */
    if(!label){
      var lab=zone.parentNode?zone.parentNode.querySelector('label'):null;
      label=lab?(lab.textContent||'').trim():'';
    }

    async function doUpload(file){
      if(!file) return;
      status.textContent='Uploading…';
      try{
        var fd=new FormData(); fd.append('file',file); fd.append('folder',folder||'seo');
        var res=await api('/admin-api/media/upload',{method:'POST',body:fd});
        applyUrl(res.url); status.textContent='Uploaded';
        setTimeout(function(){status.textContent='';},1800);
      }catch(e){ status.textContent='Upload failed — check connection'; }
    }

    /* One place that puts a url into the field, whether it came from an upload
       or from the library, so the two can never drift into setting different
       halves of it. */
    function applyUrl(url){
      if(!url) return;
      hidden.value=url; urlInput.value=url;
      img.src=url; img.style.display='block'; preview.style.display='block';
      prompt.textContent='Click to replace from the Media Library';
    }

    /* One opener, shared by the labelled button and by the zone itself, so the
       two can never drift into doing different things. */
    function openLibrary(e){
      if(e) e.preventDefault();
      if(typeof window.kbbPickMedia!=='function'){ status.textContent='Media Library is unavailable'; return; }
      window.kbbPickMedia({
        title:label||'Choose an image',
        note:'Pick one already in the library, or upload a new one \u2014 it joins the library first.',
        folder:folder||'seo',
        onPick:function(urls){
          if(!urls||!urls.length) return;
          applyUrl(urls[0]); status.textContent='Chosen'; setTimeout(function(){status.textContent='';},1500);
        }
      });
    }

    var libBtn=document.getElementById(id+'_lib');
    if(libBtn) libBtn.onclick=openLibrary;

    /* The zone is where the operator's eye and cursor already are. It opens the
       library, which is what the transparent file input over it used to
       pre-empt. Keyboard too — it is a button now, so it has to behave like one. */
    zone.onclick=openLibrary;
    zone.onkeydown=function(e){ if(e.key==='Enter'||e.key===' '){ openLibrary(e); } };

    zone.ondragover=function(e){ e.preventDefault(); zone.style.borderColor='var(--accent)'; };
    zone.ondragleave=function(){ zone.style.borderColor='var(--border)'; };
    zone.ondrop=function(e){ e.preventDefault(); zone.style.borderColor='var(--border)'; if(e.dataTransfer.files[0]) doUpload(e.dataTransfer.files[0]); };
    manualLink.onclick=function(e){
      e.preventDefault();
      urlInput.style.display = urlInput.style.display==='none' ? 'block' : 'none';
    };
    urlInput.oninput=function(){ hidden.value=urlInput.value; if(urlInput.value){ img.src=urlInput.value; img.style.display='block'; preview.style.display='block'; } };
  }

  let seoTab='settings';

  async function renderSeo(){
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>SEO &amp; Meta</h2><p>Site-wide search-engine settings. These render into every storefront page\u2019s &lt;head&gt; and power the sitemap, robots.txt and structured data.</p></div>'+
      /* LANE S7 — the Overview tab, FIRST in the strip and not the DEFAULT one.
         Both halves are deliberate.

         First, because it is the tab that answers "where do I even start" and a
         tab strip is read left to right (start to end, in Arabic).

         Not the default, because `seoTab` still opens on 'settings' and rule 1
         of the project notes is absolute: the screen an owner lands on when he
         clicks SEO & Meta is the screen he landed on yesterday. Making Overview
         the landing tab is a one-word change (`let seoTab='overview'`) and it is
         the owner's to ask for, not a lane's to slip in. */
      '<div class="subtabs"><button class="subtab'+(seoTab==='overview'?' on':'')+'" data-st="overview">Overview</button><button class="subtab'+(seoTab==='settings'?' on':'')+'" data-st="settings">Settings</button><button class="subtab'+(seoTab==='redirects'?' on':'')+'" data-st="redirects">Redirects &amp; 404s</button><button class="subtab'+(seoTab==='schema'?' on':'')+'" data-st="schema">Schema Inspector</button><button class="subtab'+(seoTab==='audit'?' on':'')+'" data-st="audit">Catalogue Audit</button><button class="subtab'+(seoTab==='seoaudit'?' on':'')+'" data-st="seoaudit">SEO Audit</button></div>'+
      /* What the five tabs are and why they are one screen. The owner asked
         this of Catalog and it is the same question here: a tab strip that only
         names itself leaves you clicking each one to find out.

         CATALOGUE AUDIT AND SEO AUDIT ARE NOT THE SAME TAB and the hint has to
         say so, or the second one reads as a duplicate of the first and never
         gets opened. Catalogue Audit asks three questions of PRODUCTS. SEO
         Audit asks a wider set of the whole indexable surface — categories,
         brands, articles and pages as well — and asks the two that no per-row
         check can answer: which titles collide with each other, and which rows
         carry a canonical pointing off this site. */
      '<p class="ectabs-hint">Six views of the same thing — how this shop looks to Google. <b>Overview</b> is the one to open first: what is wrong ranked by what it costs you, and what is still waiting on you. <b>Settings</b> is what you write; <b>Redirects &amp; 404s</b> catches old WooCommerce addresses so a link from Google still lands somewhere; <b>Schema Inspector</b>, <b>Catalogue Audit</b> and <b>SEO Audit</b> only read. Catalogue Audit checks products for a missing description, image or thin copy; SEO Audit covers categories, brands, articles and pages too, and finds the things only a whole-shop scan can see — two pages claiming the same title, or a canonical pointing at somebody else’s site.</p>'+
      '<div id="seoTabBody"></div></div>';
    $$('#content .subtab').forEach(function(b){ b.onclick=function(){ seoTab=b.dataset.st; renderSeo(); }; });
    /* LANE S7. resources/views/admin/partials/seo-back-office.blade.php draws
       it, and it is a plain function call rather than a window.go wrapper
       because this is a SUBTAB of a screen that already exists — there is no
       new screen id, no sidebar row and nothing for a deep link to address that
       #seo did not already address. */
    if(seoTab==='overview') return window.kbbSeoOverview();
    if(seoTab==='redirects') return renderSeoRedirects();
    if(seoTab==='schema') return renderSchemaInspector();
    if(seoTab==='audit') return renderCatalogueAudit();
    if(seoTab==='seoaudit') return renderSeoAudit();
    return renderSeoSettings();
  }

  /* ---------- SEO & Meta · Settings (Lane CD: hierarchy and layout only) ----
     Seven cards, each headed by a bare bold word and none of them saying when
     you would touch it, become two cards that name the question they answer
     and eight bands underneath that name their own. Every field is the one
     that was here before, with the same id; the save handler below is
     untouched and posts the same thirty-eight keys.

     WHAT CHANGED AND WHY. The old markup used .g2, which is
     `grid-template-columns:1fr 1fr` with no breakpoint anywhere in the
     console, so this screen drew two 145px columns on a 390px phone.
     "Title separator" was pinned at 120px inside a 1fr track, leaving a third
     of the row empty beside it; the ship-to country box at 100px did the same.
     .sm-grid is auto-fit with a min() floor, and nothing carries a width of
     its own any more, so a pair shares a row and a width or it stacks.
     The three tick boxes each had a bold label with a paragraph under it,
     which read as three warnings wedged between fields; they are quiet opt
     rows now. The long explanations that were under an input have moved up
     into the band description or down into one note. */
  function smField(id,label,control,help){
    return '<div class="sm-field"><label class="sm-label" for="'+id+'">'+label+'</label>'+control+
      (help?'<div class="sm-help">'+help+'</div>':'')+'</div>';
  }
  function smSec(title,description,body){
    return '<section class="sm-sec"><div class="sm-sec-h"><div class="sm-sec-t">'+title+'</div>'+
      '<div class="sm-sec-d">'+description+'</div></div>'+body+'</section>';
  }
  /* A tick and its one line, as a quiet row rather than a bold label with a
     paragraph under it. The box keeps its id and stays the only thing that
     toggles, exactly as before. */
  function smOpt(cbxId,on,title,help){
    return '<div class="sm-opt"><span class="cbx'+(on?' on':'')+'" id="'+cbxId+'">'+ic(I.check)+'</span>'+
      '<div><span class="sm-opt-t">'+title+'</span>'+(help?'<div class="sm-help">'+help+'</div>':'')+'</div></div>';
  }

  async function renderSeoSettings(){
    await loadSettings(); var S=SETTINGS;
    var base=(location.origin||'');
    document.getElementById('seoTabBody').innerHTML =
      '<div class="sm-wrap">'+

      '<div class="sm-card">'+

      smSec('Search appearance',
        'The words Google prints in a result, and the address it prints them under. This is the band to edit when the shop is renamed or the homepage pitch changes.',
        '<div class="sm-grid">'+
          smField('seo_site_url','Site URL (canonical base)',
            '<input id="seo_site_url" value="'+sesc(S.site_url)+'" placeholder="https://kbeautybliss.com">',
            'The one address every page says it really lives at.')+
          smField('seo_sitename','Site name',
            '<input id="seo_sitename" value="'+sesc(S.seo_site_name||S.store_name)+'" placeholder="K-Beauty Bliss">',
            'What {sitename} becomes in the template below.')+
        '</div>'+
        /* Two to a row, deliberately, rather than four in one auto-fit grid:
           at this console's width a fourth 230px track misses fitting by two
           pixels, so the row silently became three-and-one and the rhythm
           broke. An explicit pair is a pair at every width. */
        '<div class="sm-grid">'+
          smField('seo_sep','Title separator',
            '<input id="seo_sep" value="'+sesc(S.seo_separator||'|')+'">',
            'What {sep} becomes. Usually | or —.')+
          smField('seo_tpl',sesc(TITLE_BASIS.label||'Title template'),
            '<input id="seo_tpl" value="'+sesc(S.seo_title_template||'{title} {sep} {sitename}')+'" placeholder="{title} {sep} {sitename}">',
            /* WAS "Used on every page that has no title of its own." That was
               the wrong way round and it is why the box kept being reported as
               broken: the template reaches product, shop and category pages
               too. What it cannot do there is MOVE the site name. */
            sesc(TITLE_BASIS.note||''))+
        '</div>'+
        /* The {sitename} caveat gets its own row rather than being crammed into
           the field's help line: it is the answer to the question this box
           actually raises, and a reader who has just typed {sitename} into it
           needs to see it without hunting. */
        (TITLE_BASIS.tokens_note
          ? '<div class="sm-help" style="margin:-2px 0 14px">'+sesc(TITLE_BASIS.tokens_note)+'</div>'
          : '')+
        /* LANE S7 — the homepage's Google result, live, above the two boxes that
           decide it.

           These two boxes had no consequence on screen anywhere in this console.
           The homepage title is the single most-read string this shop publishes
           and an owner typing into it was working blind: an empty box does NOT
           publish an empty title (it publishes the site name), a `{sitename}`
           typed into it is substituted server-side, and 155 characters of
           description is a number nobody can count by eye.

           A MOUNT POINT AND NOTHING ELSE. It draws no control, stores nothing
           and posts nothing — the Save button below still posts the same
           thirty-eight keys it posted before, from the same ids. The preview is
           filled in by window.kbbSeoPreview() at the foot of this function,
           which asks the server for the real emitted tag rather than guessing
           at it here. */
        '<div id="seo_home_prev"></div>'+
        '<div class="sm-grid">'+
          smField('seo_home_t','Homepage title',
            '<input id="seo_home_t" value="'+sesc(S.seo_home_title)+'" placeholder="K-Beauty Bliss — Korean skincare for the UAE">',
            'The homepage ignores the template and uses this.')+
        '</div>'+
        '<div class="sm-grid">'+
          smField('seo_home_d','Homepage meta description',
            '<textarea id="seo_home_d" class="inp"></textarea>',
            'The sentence under the homepage result. Around 155 characters.')+
          smField('seo_def_d','Default meta description',
            '<textarea id="seo_def_d" class="inp"></textarea>',
            'Used wherever a page has written none of its own.')+
        '</div>'+
        '<div class="sm-grid">'+
          smField('seo_robots_i','Search engines',
            seoSel('seo_robots_i',S.robots_index,[['index','Index (allow ranking)'],['noindex','Noindex (hide from search)']],'index'),
            'Noindex takes the whole shop out of search results.')+
          smField('seo_robots_f','Follow links',
            seoSel('seo_robots_f',S.robots_follow,[['follow','Follow'],['nofollow','Nofollow']],'follow'),
            'Whether link credit passes to the pages you link to.')+
        '</div>')+

      smSec('Sharing a link',
        'What appears when somebody pastes a link to this shop into a chat or a post. Facebook, LinkedIn, WhatsApp, Pinterest and iMessage all read the same Open Graph tags; Twitter/X alone uses its own card, which is why it has a field of its own.',
        '<div class="sm-grid">'+
          imgUploadField('seo_og_img',S.og_default_image,'Default share image (1200×630)','seo')+
          smField('seo_tw','Twitter / X handle',
            '<input id="seo_tw" value="'+sesc(S.twitter_handle)+'" placeholder="@kbeautybliss">',
            'With the @. Credits the shop on shared cards.')+
        '</div>')+

      smSec('Social profiles',
        'Your official accounts, linked into the Organization schema below so Google can confirm they are genuinely yours — it is what feeds the Knowledge Panel and brand searches. Leave blank any you do not have. Also editable at Appearance → Footer.',
        '<div class="sm-grid">'+
          smField('seo_soc_fb','Facebook','<input id="seo_soc_fb" value="'+sesc(S.social_facebook)+'" placeholder="https://facebook.com/kbeautybliss">')+
          smField('seo_soc_ig','Instagram','<input id="seo_soc_ig" value="'+sesc(S.social_instagram)+'" placeholder="https://instagram.com/kbeautybliss">')+
          smField('seo_soc_tt','TikTok','<input id="seo_soc_tt" value="'+sesc(S.social_tiktok)+'" placeholder="https://tiktok.com/@kbeautybliss">')+
          smField('seo_soc_pin','Pinterest','<input id="seo_soc_pin" value="'+sesc(S.social_pinterest)+'" placeholder="https://pinterest.com/kbeautybliss">')+
          smField('seo_soc_li','LinkedIn','<input id="seo_soc_li" value="'+sesc(S.social_linkedin)+'" placeholder="https://linkedin.com/company/kbeautybliss">')+
          smField('seo_soc_yt','YouTube','<input id="seo_soc_yt" value="'+sesc(S.social_youtube)+'" placeholder="https://youtube.com/@kbeautybliss">')+
        '</div>')+

      smSec('Business identity',
        'Who search engines are told this shop belongs to, in schema.org terms. Set it once; it rarely changes after that.',
        '<div class="sm-grid">'+
          smField('seo_org_name','Organization name',
            '<input id="seo_org_name" value="'+sesc(S.org_name||S.store_name)+'">',
            'The legal or trading name, not the tagline.')+
          /* Lane S8. The owner's words were "we don't have any physical shop,
             we operate only online", so the shipped default is OnlineStore and
             this select agrees with SeoSettings::DEFAULTS -- a select whose
             fallback differs from the emitter's default shows one type and
             publishes another. The labels are the wording SeoAudit's own
             findings use ("set the type to Online store"), so the owner can
             find in this list the thing the audit told him to pick. */
          smField('seo_org_type','Type',
            seoSel('seo_org_type',S.org_type,[['OnlineStore','Online store — no shopfront'],['Store','Store — customers can walk in'],['LocalBusiness','Local business — customers can walk in'],['Organization','Organization — a company, unspecified']],'OnlineStore'),
            'Online store is what this shop is, and it is the shipped setting. Pick Store or Local business only if customers really can walk in: those are the two that publish a map pin and opening hours, and neither is a way to rank locally without premises.')+
          imgUploadField('seo_org_logo',S.org_logo,'Logo','seo')+
        '</div>')+

      '</div>'+

      '<div class="sm-card">'+

      smSec('Rich product results',
        'Adds brand, condition, shipping and return terms to every product’s schema — what unlocks prices and stars showing directly in Google, and eligibility for AI Shopping. Off until you have confirmed the numbers below, because wrong shipping or return terms going out to search engines is worse than none at all. <b>You do not have to answer all of it at once.</b> Every box below is published only when you have filled it in: leave the shipping cost blank and nothing is said about delivery, answer the returns question and that answer goes out on its own.',
        '<div class="sm-opts">'+
          smOpt('seo_merchant_cbx',String(S.enable_merchant)==='1','Enable merchant listing on every product',
            'Publishes the four values below on every product page.')+
        '</div>'+
        '<div class="sm-grid">'+
          smField('seo_merch_cond','Condition',
            seoSel('seo_merch_cond',S.merchant_condition,[['NewCondition','New'],['UsedCondition','Used'],['RefurbishedCondition','Refurbished']],'NewCondition'),
            'What every product is sold as.')+
          smField('seo_merch_country','Ship-to country',
            '<input id="seo_merch_country" value="'+sesc(S.merchant_ship_country||'AE')+'" maxlength="2" style="text-transform:uppercase">',
            'Two-letter code, e.g. AE.')+
        '</div>'+
        '<div class="sm-grid">'+
          smField('seo_merch_cost','Shipping cost (AED)',
            /* WHOLE DIRHAMS. This is published to Google as the offer's
               shipping rate and the crawler compares it against the till: a
               feed advertising AED 12.50 delivery beside a shop that charges
               whole dirhams is a price this shop does not honour.

               BLANK, NOT PREFILLED 0 -- Lane S8, and this is a bug fix rather
               than a nicety. This box used to arrive holding `0`, which reads
               as "delivery is free" and was published as exactly that: every
               product page went out with "shippingRate":{"value":"0.00"} the
               moment the switch above was turned on, on a shop that has never
               quoted a delivery rate. Blank now means "not stated" all the way
               through -- the box, the save, and the markup. See App\Support\
               Seo::shippingDetails(). */
            '<input id="seo_merch_cost" type="number" step="1" value="'+sesc(S.merchant_ship_cost)+'" placeholder="Leave blank until you know">',
            '<b>Leave blank if you have not settled your delivery rate.</b> Blank publishes nothing about shipping. A number here is a promise Google prints beside your price, so enter 0 only if delivery really is free.')+
          smField('seo_merch_freeover','Free shipping over (AED)',
            /* Prefill left at 0 deliberately: blank and 0 mean the same thing
               here ("never free"), so 0 is not a claim the way a 0 shipping
               cost is. It only ever reduces a rate already stated above. */
            '<input id="seo_merch_freeover" type="number" step="1" value="'+sesc(S.merchant_ship_free_over||'0')+'">',
            '0 means delivery is never free. Only read when a shipping cost is filled in above.')+
        '</div>'+
        /* Lane S5. The owner's answer to the returns question was "at the
           moment we don't offer returns", and until this row existed there was
           no value in the application that could SAY it: the days box at 0
           published no return policy at all, which is silence, not a refusal.
           Blank is a real option here and is the shipped value, so applying the
           package moves no markup. Above the days box because it decides
           whether the days box means anything. */
        '<div class="sm-grid is-solo">'+
          smField('seo_merch_returns','Returns policy',
            seoSel('seo_merch_returns',S.merchant_returns,[
              ['','Not stated'],
              ['MerchantReturnNotPermitted','We do not accept returns'],
              ['MerchantReturnFiniteReturnWindow','We accept returns within the window below'],
            ],''),
            'Not stated publishes nothing. "We do not accept returns" publishes a refusal with no window, method or fee beside it — which is the answer for this shop today, and it publishes on its own whether or not the shipping boxes above are filled in.')+
        '</div>'+
        '<div class="sm-grid is-solo">'+
          smField('seo_merch_returndays','Return window (days)',
            '<input id="seo_merch_returndays" type="number" value="'+sesc(S.merchant_return_days||'0')+'">',
            'Only read when the policy above is "We accept returns within the window below".')+
        '</div>')+

      smSec('Sitemap & robots',
        'The two files every crawler asks for first. Open them in a tab to see exactly what is being served right now.',
        '<div class="sm-grid">'+
          smField('seo_sitemap','XML sitemap',
            seoSel('seo_sitemap',S.sitemap_enabled,[['1','Enabled'],['0','Disabled']],'1'),
            'How Google finds new products and posts.')+
          /* Lane S. Off by default, and the help line says what turning it on
             actually does rather than praising it: the operator is about to
             change a file Search Console has already fetched. */
          smField('seo_sitemap_images','Product images in sitemap',
            seoSel('seo_sitemap_images',S.sitemap_images,[['1','Included'],['0','Not included']],'0'),
            'Lists every gallery photo under its product, so Google Images can tie the pictures to the page. Adds bytes to sitemap.xml.')+
          /* Lane S6. Off by default, same reason as the switch above it: turning
             it on adds a schema.org node to seven pages Search Console has
             already fetched. The help line says what it does and does not buy,
             rather than praising it -- Google retired the FAQ drop-down in
             search results, so the value now is that an answer engine can read
             the pairs. */
          smField('seo_faq_schema','FAQ markup on content pages',
            seoSel('seo_faq_schema',S.faq_schema,[['1','Published'],['0','Not published']],'0'),
            'Publishes the questions and answers on a page written as questions, so an answer engine can read them as pairs. A heading counts as a question only when it ends in a question mark. Google no longer shows an FAQ drop-down in search results.')+
          /* The setting and the way to check it, on one row: the pair is the
             point, and it keeps a two-option select off a 957px line. */
          '<div class="sm-field"><span class="sm-label">Check what is being served</span>'+
            '<div class="sm-links">'+
              '<a class="btn ghost sm" href="'+base+'/sitemap.xml" target="_blank">sitemap.xml \u2197</a>'+
              '<a class="btn ghost sm" href="'+base+'/robots.txt" target="_blank">robots.txt \u2197</a>'+
            '</div>'+
            '<div class="sm-help">Opens the live file in a new tab.</div></div>'+
        '</div>'+
        '<div class="sm-grid">'+
          smField('seo_robots_txt','robots.txt',
            '<textarea id="seo_robots_txt" class="inp is-code" placeholder="User-agent: *\nAllow: /"></textarea>',
            'Leave blank for the smart default. Only edit if you know the syntax.')+
        '</div>')+

      smSec('Crawling and AI',
        'Three switches that change what crawlers are told to fetch, and how quickly. Sensible as they are — come here only if something specific needs turning off.',
        '<div class="sm-opts">'+
          smOpt('seo_indexnow_cbx',String(S.indexnow_on)==='1','Instant indexing (IndexNow)',
            'Submits new and updated URLs to Bing, Yandex, Naver, Seznam and Yep the moment they publish. Google is not part of IndexNow — it uses the sitemap.'+
            (String(S.indexnow_on)==='1'?(' Key file: <a href="'+base+'/'+sesc(S.indexnow_key||'')+'.txt" target="_blank">'+sesc(S.indexnow_key||'(generated on first use)')+'.txt ↗</a>'):''))+
          smOpt('seo_llms_cbx',String(S.llms_enabled)!=='0','Publish <a href="'+base+'/llms.txt" target="_blank">/llms.txt</a> for AI crawlers',
            'A plain-text summary of the shop, for assistants that look for one.')+
          smOpt('seo_crawlclean_cbx',String(S.crawl_clean)!=='0','Crawl-budget cleanup',
            'Filtered and sorted shop views point their canonical back at the clean category URL, so ranking signals gather there instead of scattering across every filter combination. Page 2 onward still index normally.')+
        '</div>')+

      /* ===== LANE DD · one section, two different kinds of field ==============
         The description here used to tell the owner that pasting anything into
         this section left the storefront unchanged. That was true of the four
         verification tokens above and untrue of the two tracking fields below
         them. A Google Analytics ID is not a token that proves ownership: the
         moment one is saved, App\Support\Seo puts Google's gtag script on every
         storefront page and starts sending Google a record of every visit. The
         owner was being told the opposite of that while switching it on.

         LANE DP: the two boxes are now ONE VALUE. `ga` and `meta_pixel` are
         aliases — AdminController::settings() reads them back through
         App\Services\Analytics and updateSettings() writes them through it,
         into the Marketing Pixels module's own keys. Whichever box the owner
         types in, the other shows the same thing, and the storefront loads
         one loader per network per page (App\Services\Analytics::headTags(),
         once per request). There is no longer a dead box to warn about.
         ===================================================================== */
      smSec('Verification & tracking',
        'The first four are verification tokens — a service gives you one, it goes into a meta tag on every page, and nothing else about the shop changes. The two below them are not tokens: they load third-party tracking scripts for your visitors.',
        '<div class="sm-grid">'+
          smField('seo_gsv','Google Search Console','<input id="seo_gsv" value="'+sesc(S.google_site_verification)+'" placeholder="verification token">')+
          smField('seo_bing','Bing Webmaster','<input id="seo_bing" value="'+sesc(S.bing_site_verification)+'" placeholder="verification token">')+
          smField('seo_pin','Pinterest','<input id="seo_pin" value="'+sesc(S.pinterest_site_verification)+'" placeholder="verification token">')+
          smField('seo_baidu','Baidu','<input id="seo_baidu" value="'+sesc(S.baidu_site_verification)+'" placeholder="verification token">')+
          smField('seo_ga','Google Analytics ID','<input id="seo_ga" value="'+sesc(S.ga)+'" placeholder="G-XXXXXXXXXX">',
            'Saving an ID here loads Google’s tag on every storefront page. Clear the box to stop it.')+
          smField('seo_pixel','Meta (Facebook) Pixel','<input id="seo_pixel" value="'+sesc(S.meta_pixel)+'" placeholder="123456789012345">',
            'The same pixel as Growth &amp; Marketing → Marketing Pixels — one ID, two places to type it. Saving here loads Meta’s pixel on every storefront page. Clear the box to stop it.')+
        '</div>')+

      '</div>'+

      '<div class="sm-actions"><button class="btn" id="set_save_seo">Save SEO settings</button></div>'+
      '</div>';

    /* The three textareas are filled by property rather than interpolated
       into the markup. sesc() already escapes '<', so the old inline form was
       safe -- but it was safe only because of a helper three hundred lines
       away, and a textarea is the one control where getting that wrong spills
       the rest of the screen into the page as visible text. Setting .value
       cannot be got wrong by anyone editing this later. */
    document.getElementById('seo_home_d').value = S.seo_home_description || '';
    document.getElementById('seo_def_d').value = S.seo_default_description || '';
    document.getElementById('seo_robots_txt').value = S.robots_txt || '';

    /* LANE S7 — wire the homepage preview, AFTER the two textareas have had
       their values set by property above. Wired before them it would draw the
       empty boxes and then not repaint, because the preview listens for `input`
       and setting `.value` from script fires nothing. */
    if (typeof window.kbbSeoPreview === 'function') {
      window.kbbSeoPreview({
        mount: '#seo_home_prev',
        kind: 'home',
        title: '#seo_home_t',
        description: '#seo_home_d'
      });
    }

    document.getElementById('seo_indexnow_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_llms_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_crawlclean_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_merchant_cbx').onclick=function(){ this.classList.toggle('on'); };
    wireImgUpload('seo_og_img','seo');
    wireImgUpload('seo_org_logo','seo');

    document.getElementById('set_save_seo').onclick=async function(){
      var payload={
        site_url:sval('seo_site_url'), seo_site_name:sval('seo_sitename'), seo_separator:sval('seo_sep'), seo_title_template:sval('seo_tpl'),
        seo_home_title:sval('seo_home_t'), seo_home_description:sval('seo_home_d'), seo_default_description:sval('seo_def_d'),
        robots_index:sval('seo_robots_i'), robots_follow:sval('seo_robots_f'),
        og_default_image:sval('seo_og_img'), twitter_handle:sval('seo_tw'),
        social_facebook:sval('seo_soc_fb'), social_instagram:sval('seo_soc_ig'), social_tiktok:sval('seo_soc_tt'),
        social_pinterest:sval('seo_soc_pin'), social_linkedin:sval('seo_soc_li'), social_youtube:sval('seo_soc_yt'),
        org_name:sval('seo_org_name'), org_type:sval('seo_org_type'), org_logo:sval('seo_org_logo'),
        google_site_verification:sval('seo_gsv'), bing_site_verification:sval('seo_bing'),
        pinterest_site_verification:sval('seo_pin'), baidu_site_verification:sval('seo_baidu'),
        ga:sval('seo_ga'), meta_pixel:sval('seo_pixel'),
        sitemap_enabled:sval('seo_sitemap'), sitemap_images:sval('seo_sitemap_images'), robots_txt:sval('seo_robots_txt'),
        indexnow_on:document.getElementById('seo_indexnow_cbx').classList.contains('on')?'1':'0',
        llms_enabled:document.getElementById('seo_llms_cbx').classList.contains('on')?'1':'0',
        crawl_clean:document.getElementById('seo_crawlclean_cbx').classList.contains('on')?'1':'0',
        enable_merchant:document.getElementById('seo_merchant_cbx').classList.contains('on')?'1':'0',
        merchant_condition:sval('seo_merch_cond'), merchant_ship_country:sval('seo_merch_country'),
        merchant_ship_cost:sval('seo_merch_cost'), merchant_ship_free_over:sval('seo_merch_freeover'),
        merchant_return_days:sval('seo_merch_returndays'),
        merchant_returns:sval('seo_merch_returns'),
        faq_schema:sval('seo_faq_schema')
      };
      try{ await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})}); Object.assign(SETTINGS,payload); toast('SEO settings saved'); }
      catch(e){ toast('Save failed \u2014 check connection','bad'); }
    };
  }

  /**
   * Redirects created automatically (on a published product/post's slug
   * changing) sit in the same list as ones an admin added by hand — same
   * table, same effect on a visitor's request either way — marked with an
   * "auto" badge only so it is clear where each one came from, not
   * separated into two different screens for what is the same feature.
   */
  async function renderSeoRedirects(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading\u2026</p>';
    var data;
    try{
      var res=await fetch(redirectsApiBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      data=await res.json();
    }catch(e){ body.innerHTML='<p style="padding:24px;color:var(--sale)">Could not load \u2014 '+sesc(e.message)+'</p>'; return; }

    var reds=data.redirects||[], nf=data.not_found||[];

    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Add a redirect</b>'+
      '<p class="description" style="margin:4px 0 0">Best for pages that are actually gone \u2014 a removed product, an old WordPress URL that no longer exists. Redirecting a page that still works and still loads normally is not supported yet.</p>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>From (path on this site)</label><input id="rd_source" placeholder="/old-page/"></div>'+
      '<div class="fld"><label>To (path or full URL)</label><input id="rd_target" placeholder="/new-page/ or https://\u2026"></div></div>'+
      '<div class="row" style="gap:10px;align-items:flex-end"><div class="fld" style="max-width:160px;margin:0"><label>Type</label>'+seoSel('rd_code','301',[['301','301 (permanent)'],['302','302 (temporary)']],'301')+'</div>'+
      '<button class="btn" id="rd_add" style="margin-top:9px">Add redirect</button></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Redirects</b> <span style="font-size:11.5px;color:var(--ink-soft)">'+reds.length+'</span>'+
      '<div style="margin-top:12px" id="rd_list">'+(reds.length?reds.map(redirectRow).join(''):'<p style="color:var(--ink-soft);font-size:12.5px">No redirects yet.</p>')+'</div></div>'+

      '<div class="card pad"><b style="font-size:13px">Recent 404s</b> <span style="font-size:11.5px;color:var(--ink-soft)">'+nf.length+' \u2014 broken links people have actually hit, most-hit first</span>'+
      '<div style="margin-top:12px" id="nf_list">'+(nf.length?nf.map(notFoundRow).join(''):'<p style="color:var(--ink-soft);font-size:12.5px">No broken links logged.</p>')+'</div></div>';

    document.getElementById('rd_add').onclick=async function(){
      var source=sval('rd_source').trim(), target=sval('rd_target').trim(), code=sval('rd_code');
      if(!source||!target){ toast('Enter both a from and to path'); return; }
      try{
        var res=await fetch(redirectsApiBase(),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({source:source,target:target,code:+code})});
        var j=await res.json();
        if(j.ok){ toast('Redirect added'); renderSeoRedirects(); }
        else{ toast(j.message||'Could not add that redirect.', 'bad'); }
      }catch(e){ toast('Could not save \u2014 check your connection.','bad'); }
    };
    wireRedirectRows();
    wireNotFoundRows();
  }

  function redirectRow(r){
    return '<div class="row" style="gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)">'+
      '<div style="flex:1;min-width:0"><div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(r.source)+'</div>'+
      '<div style="font-size:11px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">\u2192 '+sesc(r.target)+'</div></div>'+
      '<span class="pill '+sesc((r.code===301?'green':'amber'))+'" style="flex:0 0 auto">'+sesc(r.code)+'</span>'+
      (r.auto_created?'<span class="pill" style="flex:0 0 auto;background:var(--surface-2)">auto</span>':'')+
      '<span style="flex:0 0 60px;font-size:11px;color:var(--ink-soft);text-align:right">'+r.hits+' hit'+(r.hits===1?'':'s')+'</span>'+
      '<span class="cbx'+(r.enabled?' on':'')+'" data-rdtoggle="'+r.id+'" style="flex:0 0 auto" title="Enabled">'+ic(I.check)+'</span>'+
      '<button class="btn ghost sm" data-rddel="'+r.id+'" style="flex:0 0 auto">Delete</button></div>';
  }

  function notFoundRow(n){
    return '<div class="row" style="gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)" id="nf_row_'+n.id+'">'+
      '<div style="flex:1;min-width:0"><div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(n.path)+'</div>'+
      (n.referer?'<div style="font-size:10.5px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">from '+sesc(n.referer)+'</div>':'')+'</div>'+
      '<span style="flex:0 0 60px;font-size:11px;color:var(--ink-soft);text-align:right">'+n.hits+' hit'+(n.hits===1?'':'s')+'</span>'+
      '<input id="nf_target_'+n.id+'" class="inp" style="flex:0 0 160px;display:none;font-size:12px" placeholder="/redirect-to/">'+
      '<button class="btn ghost sm" data-nfresolve="'+n.id+'" style="flex:0 0 auto">Resolve</button>'+
      '<button class="btn ghost sm" data-nfdismiss="'+n.id+'" style="flex:0 0 auto">Dismiss</button></div>';
  }

  function wireRedirectRows(){
    $$('#rd_list [data-rdtoggle]').forEach(function(el){ el.onclick=async function(){
      try{
        var res=await fetch(redirectsApiBase()+'/'+el.dataset.rdtoggle+'/toggle',{method:'POST',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        var j=await res.json();
        if(j.ok){ el.classList.toggle('on', j.enabled); }
        // A refusal has to SAY so. Without this the owner clicks the switch,
        // nothing moves and nothing explains why -- which is the same "a row
        // nobody can account for" complaint the refusal exists to end. The
        // Resolve handler below already reads j.message; this one did not.
        else{ toast(j.message||'Could not update that redirect.', 'bad'); }
      }catch(e){ toast('Could not update \u2014 check your connection.','bad'); }
    };});
    $$('#rd_list [data-rddel]').forEach(function(b){ b.onclick=async function(){
      if(!confirm('Delete this redirect?')) return;
      try{
        await fetch(redirectsApiBase()+'/'+b.dataset.rddel,{method:'DELETE',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        toast('Redirect deleted'); renderSeoRedirects();
      }catch(e){ toast('Could not delete \u2014 check your connection.','bad'); }
    };});
  }

  function wireNotFoundRows(){
    $$('#nf_list [data-nfresolve]').forEach(function(b){ b.onclick=async function(){
      var id=b.dataset.nfresolve, input=document.getElementById('nf_target_'+id);
      if(input.style.display==='none'){ input.style.display='inline-block'; input.focus(); return; }
      var target=input.value.trim();
      if(!target){ toast('Enter where this should redirect to'); return; }
      try{
        var res=await fetch(redirectsApiBase()+'/not-found/'+id+'/resolve',{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},body:JSON.stringify({target:target,code:301})});
        var j=await res.json();
        if(j.ok){ toast('Redirect created'); renderSeoRedirects(); }
        else{ toast(j.message||'Could not resolve that.', 'bad'); }
      }catch(e){ toast('Could not save \u2014 check your connection.','bad'); }
    };});
    $$('#nf_list [data-nfdismiss]').forEach(function(b){ b.onclick=async function(){
      try{
        await fetch(redirectsApiBase()+'/not-found/'+b.dataset.nfdismiss,{method:'DELETE',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        renderSeoRedirects();
      }catch(e){ toast('Could not dismiss \u2014 check your connection.','bad'); }
    };});
  }

  /**
   * Shows an admin the real JSON-LD a page would actually output — builds
   * the exact same context the real storefront controllers do (see
   * SchemaInspectorApiController), so nothing shown here can drift from
   * what a real page actually ships.
   */
  function renderSchemaInspector(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Inspect a page\u2019s structured data</b>'+
      '<p class="description" style="margin:4px 0 12px">Shows the exact JSON-LD a page would send to search engines right now \u2014 the same renderer every real page uses, not a preview.</p>'+
      '<div class="row" style="gap:10px;align-items:flex-end"><div class="fld" style="max-width:160px;margin:0"><label>Page type</label>'+seoSel('si_type','product',[['product','Product'],['category','Category'],['shop','Shop (all)'],['home','Homepage']],'product')+'</div>'+
      '<div class="fld" id="si_slug_wrap" style="margin:0;flex:1"><label>Slug</label><input id="si_slug" placeholder="e.g. relief-sun-rice-probiotics-spf50"></div>'+
      '<button class="btn" id="si_go" style="margin-bottom:0">Inspect</button></div></div>'+
      '<div id="si_result"></div>';

    var typeSel=document.getElementById('si_type');
    var slugWrap=document.getElementById('si_slug_wrap');
    function syncSlugVisibility(){ slugWrap.style.display=(typeSel.value==='shop'||typeSel.value==='home')?'none':''; }
    typeSel.onchange=syncSlugVisibility;
    syncSlugVisibility();

    document.getElementById('si_go').onclick=async function(){
      var type=typeSel.value, slug=sval('si_slug').trim();
      var result=document.getElementById('si_result');
      result.innerHTML='<p style="padding:16px;color:var(--ink-soft)">Checking\u2026</p>';
      try{
        var q=new URLSearchParams({type:type, slug:slug});
        var res=await fetch(schemaInspectApiBase()+'?'+q,{credentials:'same-origin',headers:{Accept:'application/json'}});
        var j=await res.json();
        if(!j.ok){ result.innerHTML='<div class="card pad"><p style="color:var(--sale);margin:0">'+sesc(j.message||'Could not inspect that page.')+'</p></div>'; return; }
        var warningsHtml=j.warnings&&j.warnings.length
          ? '<div class="card pad" style="margin-bottom:16px;border-color:var(--amber)"><b style="font-size:13px">Worth a look</b><ul style="margin:8px 0 0;padding-left:20px">'+j.warnings.map(function(w){return '<li style="font-size:12.5px;margin-bottom:4px">'+sesc(w)+'</li>';}).join('')+'</ul></div>'
          : '<div class="card pad" style="margin-bottom:16px"><p style="margin:0;font-size:12.5px;color:var(--ink-soft)">No issues found.</p></div>';
        var nodesHtml=j.nodes.map(function(n){
          return '<div class="card pad" style="margin-bottom:12px"><b style="font-size:12.5px">'+sesc(n['@type']||'?')+'</b>'+
            '<pre style="margin:8px 0 0;font-size:11px;background:var(--surface-2);padding:10px;border-radius:8px;overflow:auto;white-space:pre-wrap">'+sesc(JSON.stringify(n,null,2))+'</pre></div>';
        }).join('');
        result.innerHTML=warningsHtml+nodesHtml;
      }catch(e){ result.innerHTML='<div class="card pad"><p style="color:var(--sale);margin:0">Could not check \u2014 check your connection.</p></div>'; }
    };
  }

  /**
   * Reports, doesn't fix — scans every visible product for the handful of
   * gaps that actually matter (missing meta description, missing image, a
   * short description too thin to build a real fallback from) and shows
   * counts plus the worst offenders. Reads the same `seo` column
   * ProductController now actually renders from, so a product this
   * reports as fixed genuinely is.
   */
  async function renderCatalogueAudit(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Scanning catalogue\u2026</p>';
    var data;
    try{
      var res=await fetch(catalogueAuditApiBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      data=await res.json();
    }catch(e){ body.innerHTML='<p style="padding:24px;color:var(--sale)">Could not scan \u2014 '+sesc(e.message)+'</p>'; return; }

    function issueCard(title, desc, key){
      var list=data.issues[key]||[], count=data.counts[key]||0;
      var rows=list.map(function(p){
        return '<div class="row" style="gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12.5px">'+
          '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(p.name)+'</span>'+
          (p.words!==undefined?'<span style="color:var(--ink-soft)">'+p.words+' words</span>':'')+'</div>';
      }).join('');
      var more=count>list.length?'<p class="description" style="margin:8px 0 0">+ '+(count-list.length)+' more, not shown.</p>':'';
      return '<div class="card pad" style="margin-bottom:16px"><div class="between"><b style="font-size:13px">'+title+'</b><span class="pill '+(count===0?'green':'amber')+'">'+count+'</span></div>'+
        '<p class="description" style="margin:4px 0 12px">'+desc+'</p>'+
        (list.length?rows:'<p style="font-size:12.5px;color:var(--ink-soft)">None \u2014 every visible product has one.</p>')+more+'</div>';
    }

    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">'+data.total+' visible products scanned</b></div>'+
      issueCard('Missing meta description', 'No per-product SEO description set, and the short description is too thin (under 15 words) to build a real fallback from.', 'no_description')+
      issueCard('Missing image', 'No image at all \u2014 affects search results, social shares, and Product schema.', 'no_image')+
      issueCard('Short description too thin', 'Under 15 words \u2014 not necessarily wrong, but too little for a real fallback SEO description or a useful product page.', 'thin_short_description');
  }

  /* ---------- Store \u2192 SEO & Meta \u2192 SEO Audit (Lane S) ----------------------

     The whole indexable surface, not just products, and the two findings a
     per-row check cannot produce: a title shared by two URLs, and a canonical
     override pointing somewhere this shop does not control.

     WHY THE FINDINGS ARE RENDERED FROM THE RESPONSE RATHER THAN FROM A LIST
     HERE. The server owns which checks exist, what each is called and what
     each means \u2014 App\Support\SeoAudit::emptyFindings(). If this file carried
     its own copy of that list, a check added on the server would scan, count,
     and then not be drawn; and a check removed would leave a card here reading
     a key that no longer arrives. The screen iterates what it was sent, so the
     two cannot drift.

     EVERY STRING FROM THE SERVER GOES THROUGH sesc(). The names in here are
     product, category and brand names out of the database \u2014 operator-supplied
     text, which is exactly the class of value that must never reach innerHTML
     raw. Same rule the rest of this console follows. */
  function seoAuditApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/seo-audit'; }

  async function renderSeoAudit(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Scanning the indexable surface\u2026</p>';
    var data;
    try{
      var res=await fetch(seoAuditApiBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      data=await res.json();
      /* A 403 from EnforceAdminCapability arrives as JSON with a message. It
         is shown in the words the server chose rather than turned into a
         generic failure: "you do not have this capability" and "the scan
         broke" are different problems and an operator must be able to tell
         them apart. */
      if(!res.ok) throw new Error(data && data.message ? data.message : ('Request failed ('+res.status+')'));
    }catch(e){ body.innerHTML='<p style="padding:24px;color:var(--sale)">Could not scan \u2014 '+sesc(e.message)+'</p>'; return; }

    var scanned=data.scanned||{};
    var chips=Object.keys(scanned).map(function(k){
      return '<span class="pill" style="margin-right:6px">'+sesc(k)+' '+(scanned[k]|0)+'</span>';
    }).join('');

    var findings=data.findings||{};
    var cards=Object.keys(findings).map(function(key){
      var f=findings[key], count=f.count|0, samples=f.samples||[];
      var rows=samples.map(function(s){
        /* WRAPPING, AND A FLOOR UNDER THE NAME — round 2.
           Six of the ten findings carry a `detail` ("3 of 3 shots", "62 chars"),
           and on a 390px phone four inline-flex children on one unwrapped line
           left the NAME column at nothing: the row read as a pill, a detail and
           a URL running off the card, with the one thing identifying the row
           squeezed to an ellipsis. The name is what an operator scans for.
           Wrapping lets the address drop to its own line at narrow widths and
           changes nothing at 1280, where all four still fit. */
        return '<div class="row" style="gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12.5px;flex-wrap:wrap">'+
          '<span class="pill" style="flex:0 0 auto">'+sesc(s.kind||'')+'</span>'+
          '<span style="flex:1 1 140px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(s.name||s.url||'')+'</span>'+
          (s.detail?'<span style="color:var(--ink-soft);flex:0 0 auto">'+sesc(s.detail)+'</span>':'')+
          '<span style="color:var(--ink-soft);flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px">'+sesc(s.url||'')+'</span>'+
        '</div>';
      }).join('');
      var more=count>samples.length?'<p class="description" style="margin:8px 0 0">+ '+(count-samples.length)+' more, not shown.</p>':'';
      return '<div class="card pad" style="margin-bottom:16px"><div class="between"><b style="font-size:13px">'+sesc(f.label||key)+'</b>'+
        '<span class="pill '+(count===0?'green':'amber')+'">'+count+'</span></div>'+
        '<p class="description" style="margin:4px 0 12px">'+sesc(f.why||'')+'</p>'+
        (samples.length?rows:'<p style="font-size:12.5px;color:var(--ink-soft)">None \u2014 nothing on the shop has this problem.</p>')+more+'</div>';
    }).join('');

    body.innerHTML=
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">'+sesc(data.verdict||'')+'</b>'+
        '<p class="description" style="margin:8px 0 0">'+chips+'</p>'+
        '<p class="description" style="margin:8px 0 0">Rows marked noindex are left out on purpose \u2014 a page you have told Google to skip is not a page with a problem.</p>'+
      '</div>'+cards;
  }

  /* ---------- Catalog → Brands (real CRUD, replacing the preview grid) ----------
     The tab above renders a hard-coded CAT_BRANDS array with "(preview)"
     buttons. This replaces the whole tab: the brand list comes from
     /admin-api/brands, Add/Edit open a real form, and the logo field posts
     through the same /admin-api/media/upload every other image field uses.
     The display-mode select at the top writes `brands_display` through
     /admin-api/settings, which is what the storefront directory reads. */
  var BRANDS=[];

  /* T4b · the empty shape of the Arabic boxes, for this tab's "Add brand"
     dialog. /admin-api/brands hands it down beside the rows, so this screen
     never carries its own copy of Brand::$translatable.

     THIS IS THE SECOND BRAND EDITOR IN THE CONSOLE. The other one is the
     Brands screen in admin/partials/brands-editor-screen.blade.php, and it
     already has its boxes. Both write the same endpoint, so a brand edited
     here and a brand edited there have to offer the same fields — an Arabic
     box present on one and absent on the other is how a translation gets
     silently dropped by whichever screen the operator happened to open. */
  var BRANDS_ARABIC=null;
  var BRAND_DISPLAY_OPTS=[
    ['auto','Logo when the brand has one, name otherwise (default)'],
    ['logos','Logos only'],
    ['names','Names only']
  ];

  async function brandWrite(path, method, body){
    var r = await fetch(fixAdminApiUrl('/admin-api/brands'+(path||'')), {
      method: method,
      credentials: 'same-origin',
      headers: {'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
      body: body ? JSON.stringify(body) : undefined
    });
    var j={}; try{ j=await r.json(); }catch(e){}
    if(!r.ok){
      // 422 carries either Laravel's `errors` bag (duplicate slug, bad logo
      // URL) or this controller's own `message` (brand still in use). Both
      // are meant for the operator, so both are shown rather than swallowed
      // into a generic "save failed".
      var msg = j.message || '';
      if(j.errors){ msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' '); }
      var err = new Error(msg || ('Request failed ('+r.status+')'));
      err.payload = j; err.status = r.status;
      throw err;
    }
    return j;
  }

  window.catBrands = async function(){
    var body=document.getElementById('catBody');
    if(!body) return;
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading brands…</p>';
    try{
      var d=await brandWrite('','GET',null); BRANDS=d.brands||[];
      BRANDS_ARABIC=d.translatable||BRANDS_ARABIC;
    }catch(e){ BRANDS=[]; }
    await loadSettings();
    brandPaint();
  };

  function brandPaint(){
    var body=document.getElementById('catBody');
    if(!body) return;
    var mode=SETTINGS.brands_display||'auto';
    body.innerHTML=
      '<div class="card pad" style="margin-bottom:14px"><b style="font-size:13px">Directory display</b>'+
      '<p style="font-size:11.5px;color:var(--ink-soft);margin:4px 0 12px">How each tile is drawn on the storefront brands page (/brands/). Brands with no logo always fall back to their name, so “Logos only” can never leave an empty tile.</p>'+
      '<div class="fld" style="max-width:420px;margin:0"><label>Show</label>'+
      '<select class="inp" id="brd_display" style="width:100%">'+BRAND_DISPLAY_OPTS.map(function(o){
        return '<option value="'+o[0]+'"'+(o[0]===mode?' selected':'')+'>'+o[1]+'</option>';
      }).join('')+'</select></div>'+
      '<div class="row" style="justify-content:flex-end;margin-top:12px"><button class="btn" id="brd_display_save">Save display</button></div></div>'+
      '<div class="between" style="margin-bottom:12px"><span class="pill grey">'+BRANDS.length+' brand'+(BRANDS.length===1?'':'s')+'</span>'+
      '<button class="btn sm" id="brd_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add brand</button></div>'+
      (BRANDS.length?
        '<div class="mod-grid">'+BRANDS.map(function(b){
          var thumb = b.logo
            ? '<span class="pthumb" style="width:40px;height:40px;background:var(--bg);overflow:hidden"><img src="'+sesc(b.logo)+'" alt="'+sesc(b.name)+'" style="width:100%;height:100%;object-fit:contain"></span>'
            : '<span class="pthumb" style="background:'+sesc(tcol(b.name))+';width:40px;height:40px">'+sesc(initials(b.name))+'</span>';
          return '<div class="mod">'+thumb+
            '<div><div class="mname">'+sesc(b.name)+'</div>'+
            '<div class="mdesc">'+b.products_count+' product'+(b.products_count===1?'':'s')+' · /'+sesc(b.slug)+(b.logo?'':' · no logo')+'</div></div>'+
            '<div class="mod-r"><button class="btn ghost sm" data-bedit="'+b.id+'">Edit</button>'+
            '<button class="btn ghost sm" data-bdel="'+b.id+'">Delete</button></div></div>';
        }).join('')+'</div>'
        : '<p style="padding:24px;color:var(--ink-soft)">No brands yet — add the first one.</p>');

    document.getElementById('brd_display_save').onclick=async function(){
      var payload={brands_display: sval('brd_display')};
      try{
        await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})});
        Object.assign(SETTINGS,payload); toast('Brand display saved');
      }catch(e){ toast('Save failed — check connection','bad'); }
    };
    document.getElementById('brd_add').onclick=function(){ brandEditor(null); };
    document.querySelectorAll('#catBody [data-bedit]').forEach(function(b){
      b.onclick=function(){ brandEditor(BRANDS.filter(function(x){return x.id===+b.dataset.bedit;})[0]); };
    });
    document.querySelectorAll('#catBody [data-bdel]').forEach(function(b){
      b.onclick=function(){ brandDelete(BRANDS.filter(function(x){return x.id===+b.dataset.bdel;})[0]); };
    });
  }

  function brandEditor(brand){
    var isNew=!brand; brand=brand||{name:'',slug:'',logo:'',description:'',position:0};
    openModal('<div class="modal-h"><b>'+(isNew?'Add brand':'Edit brand')+'</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+
      '<div class="fld"><label>Name</label><input id="brd_name" value="'+sesc(brand.name)+'">'+
        (window.KBBArabic ? KBBArabic.boxIf((brand&&brand.translations)||BRANDS_ARABIC, {
          field:'name', label:'Name', prefill:(brand&&brand.translations)||null,
          maxlength:255, from:'#brd_name'
        }) : '')+'</div>'+
      '<div class="fld"><label>Slug</label><input id="brd_slug" value="'+sesc(brand.slug)+'" placeholder="left blank, made from the name">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">Used in /brands/{slug}/ and the shop filter. Lower case, hyphens.</p></div>'+
      imgUploadField('brd_logo', brand.logo||'', 'Logo', 'brands')+
      '<div class="fld"><label>Description</label><textarea id="brd_desc" class="inp" rows="3">'+sesc(brand.description)+'</textarea>'+
        (window.KBBArabic ? KBBArabic.boxIf((brand&&brand.translations)||BRANDS_ARABIC, {
          field:'description', label:'Description', prefill:(brand&&brand.translations)||null,
          type:'textarea', rows:3, maxlength:5000, from:'#brd_desc'
        }) : '')+'</div>'+
      '<div class="fld" style="max-width:160px"><label>Position</label><input id="brd_pos" type="number" min="0" value="'+sesc(brand.position||0)+'"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" id="brd_save">'+(isNew?'Create brand':'Save brand')+'</button></div></div>');
    wireImgUpload('brd_logo','brands');

    /* Attaches the Translate buttons and reveals them ONLY if an API key is
       configured. With no key they are never shown and typing by hand works. */
    if(window.KBBArabic) KBBArabic.wire(document);

    document.getElementById('brd_save').onclick=async function(){
      var payload={
        name: sval('brd_name'), slug: sval('brd_slug'), logo: sval('brd_logo'),
        description: sval('brd_desc'), position: parseInt(sval('brd_pos'),10)||0,
        /* The Arabic goes up in the SAME request as the English. A blank box
           is SENT rather than omitted — blank means "not translated yet" and
           has to reach the server to delete the row. */
        translations: window.KBBArabic ? KBBArabic.collect(document) : {}
      };
      if(!payload.name){ toast('A brand needs a name'); return; }
      try{
        await (isNew ? brandWrite('','POST',payload) : brandWrite('/'+brand.id,'PUT',payload));
        toast(isNew?'Brand created':'Brand saved'); closeModal(); window.catBrands();
      }catch(e){ toast(e.message,'bad'); }
    };
  }

  async function brandDelete(brand){
    if(!brand) return;
    var warn = brand.products_count>0
      ? '<p style="font-size:13px;color:var(--ink-2)"><b>'+brand.products_count+'</b> product'+(brand.products_count===1?'':'s')+' still belong'+(brand.products_count===1?'s':'')+' to <b>'+sesc(brand.name)+'</b>. Deleting the brand leaves them with no brand — the products themselves are kept.</p>'
      : '<p style="font-size:13px;color:var(--ink-2)">Delete <b>'+sesc(brand.name)+'</b>? This cannot be undone.</p>';
    openModal('<div class="modal-h"><b>Delete brand</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+warn+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" style="background:var(--danger,#d6455a)" id="brd_del_yes">Delete</button></div></div>');
    document.getElementById('brd_del_yes').onclick=async function(){
      try{
        // force is what the operator just confirmed: without it the API
        // refuses to unbrand products behind their back.
        await brandWrite('/'+brand.id+'?force=1','DELETE',null);
        toast('Brand deleted'); closeModal(); window.catBrands();
      }catch(e){ toast(e.message,'bad'); }
    };
  }

  /* ===== LANE N · Catalog · Categories & Attributes — BEGIN ==================
     The two tabs that were still hard-coded HTML previews. Above, catCategories
     drew eleven invented rows out of CAT_CATEGORIES with slugs made up in
     JavaScript, and catAttributes drew four invented attributes out of
     CAT_ATTRS — "Skin Type", "Concern", "Finish", none of which exist in this
     database — behind buttons that raised a "(preview)" toast. Both are
     replaced here, whole, the same way the Brands tab above was: real data from
     /admin-api/categories and /admin-api/attributes, real forms, real deletes.

     Every operator-supplied string — a category name, a term name, a slug, an
     error message that quotes one back — goes through sesc() before it reaches
     innerHTML. This region is inside @verbatim, so Blade's {{ }} does not apply
     to it; sesc() is this file's equivalent and the reason it exists.

     Written to match the Brands block above rather than to any fresh design:
     same fetch wrapper shape, same 422 handling, same refuse-then-confirm
     delete flow, same modal furniture. Two tabs that sit next to each other
     behaving differently would be the surprise. ======================= */

  /* Shared fetch wrapper. Same contract as brandWrite: throws on !ok with the
     operator-facing message already unpacked, so each call site is a try/catch
     around one line rather than a status-code ladder. 422 carries either
     Laravel's `errors` bag (duplicate slug, bad image URL, a parent that would
     make a loop) or the controller's own `message` (still in use). Both are
     written for the operator, so both are shown. */
  async function catalogWrite(path, method, body){
    var r = await fetch(fixAdminApiUrl('/admin-api'+path), {
      method: method,
      credentials: 'same-origin',
      headers: {'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
      body: body ? JSON.stringify(body) : undefined
    });
    var j={}; try{ j=await r.json(); }catch(e){}
    if(!r.ok){
      var msg = j.message || '';
      if(j.errors){ msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' '); }
      var err = new Error(msg || ('Request failed ('+r.status+')'));
      err.payload = j; err.status = r.status;
      throw err;
    }
    return j;
  }

  /* toast() assigns its argument into innerHTML, so anything that can contain a
     category or term name — every message this region raises — is escaped on
     the way in. */
  function catToast(msg){ toast(sesc(msg)); }

  /* ---------- Catalog → Categories (real CRUD, replacing the preview table) */
  var CATEGORIES=[];

  var CATEGORIES_ARABIC=null;

  window.catCategories = async function(){
    var body=document.getElementById('catBody');
    if(!body) return;
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading categories…</p>';
    try{
      var d=await catalogWrite('/categories','GET',null); CATEGORIES=d.categories||[];
      /* T4b · the empty shape of the Arabic boxes, for this tab's "Add
         category" dialog. THIS IS THE SECOND CATEGORY EDITOR in the console —
         the other is admin/partials/category-tree-screen.blade.php, which
         already has its boxes. Both write the same endpoint, so both have to
         offer the same fields. */
      CATEGORIES_ARABIC=d.translatable||CATEGORIES_ARABIC;
    }catch(e){ CATEGORIES=[]; }
    catCatPaint();
  };

  /** Children of one parent, in the order the server sorted them. */
  function catKids(parentId){
    return CATEGORIES.filter(function(c){
      var p = c.parent_id==null ? null : +c.parent_id;
      return p===parentId;
    });
  }

  /** Flat render list, depth-first, so a nested tree draws as indented rows. */
  function catTreeRows(parentId, depth, out){
    catKids(parentId).forEach(function(c){
      out.push({cat:c, depth:depth});
      catTreeRows(+c.id, depth+1, out);
    });
    return out;
  }

  /** Ids of a category and everything under it — what a parent select must not offer. */
  function catSubtreeIds(id){
    var ids=[id];
    catKids(id).forEach(function(k){ ids = ids.concat(catSubtreeIds(+k.id)); });
    return ids;
  }

  function catCatPaint(){
    var body=document.getElementById('catBody');
    if(!body) return;

    /* Rows the tree walk never reached: a row whose parent_id points at a
       category that is not in the list. It cannot happen through this screen —
       parent_id is a real FK — but an import can leave one, and silently not
       drawing a category the owner can see in the database is exactly the kind
       of "looks like it works" this screen exists to stop. */
    var rows=catTreeRows(null,0,[]);
    var drawn={}; rows.forEach(function(r){ drawn[r.cat.id]=1; });
    CATEGORIES.forEach(function(c){ if(!drawn[c.id]) rows.push({cat:c, depth:0, orphan:true}); });

    body.innerHTML=
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:13px">Categories are the archive pages at /collections/…/ and the shop filter. Nesting is real — a sub-category’s URL is its whole chain of slugs, rebuilt whenever you rename or move one.</p>'+
      '<div class="between" style="margin-bottom:12px"><span class="pill grey">'+CATEGORIES.length+' categor'+(CATEGORIES.length===1?'y':'ies')+'</span>'+
      '<button class="btn sm" id="cat_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add category</button></div>'+
      (rows.length?
        '<div class="card" style="overflow:auto"><table><thead><tr><th>Category</th><th>Products</th><th>URL path</th><th style="width:150px"></th></tr></thead><tbody>'+
        rows.map(function(r){
          var c=r.cat;
          var pad = r.depth*18;
          var total = (+c.products_count||0);
          var primary = (+c.primary_count||0);
          var kids = (+c.children_count||0);
          var counts = total+' filed'+(primary?' · '+primary+' primary':'')+(kids?' · '+kids+' sub':'');
          return '<tr>'+
            '<td><div class="row" style="gap:8px;min-width:0">'+
              (pad?'<span style="display:inline-block;width:'+pad+'px"></span><span style="color:var(--ink-faint)">└</span>':'')+
              '<b>'+sesc(c.name)+'</b>'+
              (r.orphan?'<span class="pill amber" style="margin-left:6px">parent missing</span>':'')+
            '</div></td>'+
            '<td style="font-size:12px;color:var(--ink-soft)">'+sesc(counts)+'</td>'+
            '<td style="font-family:var(--mono);font-size:11.5px;color:var(--ink-soft)">/'+sesc(c.path||c.slug)+'/</td>'+
            '<td><div class="row" style="gap:4px;justify-content:flex-end">'+
              '<button class="btn ghost sm" data-cup="'+(+c.id)+'" title="Move up">▲</button>'+
              '<button class="btn ghost sm" data-cdn="'+(+c.id)+'" title="Move down">▼</button>'+
              '<button class="btn ghost sm" data-cedit="'+(+c.id)+'">Edit</button>'+
              '<button class="btn ghost sm" data-cdel="'+(+c.id)+'">Delete</button>'+
            '</div></td></tr>';
        }).join('')+
        '</tbody></table></div>'
        : '<p style="padding:24px;color:var(--ink-soft)">No categories yet — add the first one.</p>');

    document.getElementById('cat_add').onclick=function(){ catCatEditor(null); };
    document.querySelectorAll('#catBody [data-cedit]').forEach(function(b){
      b.onclick=function(){ catCatEditor(catById(+b.dataset.cedit)); };
    });
    document.querySelectorAll('#catBody [data-cdel]').forEach(function(b){
      b.onclick=function(){ catCatDelete(catById(+b.dataset.cdel)); };
    });
    document.querySelectorAll('#catBody [data-cup]').forEach(function(b){
      b.onclick=function(){ catCatMove(+b.dataset.cup, -1); };
    });
    document.querySelectorAll('#catBody [data-cdn]').forEach(function(b){
      b.onclick=function(){ catCatMove(+b.dataset.cdn, 1); };
    });
  }

  function catById(id){ return CATEGORIES.filter(function(c){ return +c.id===id; })[0]; }

  /* Reorder is per sibling group: only the row's own siblings are sent, so a
     move never renumbers a branch the operator is not looking at. */
  async function catCatMove(id, delta){
    var cat=catById(id); if(!cat) return;
    var sibs=catKids(cat.parent_id==null?null:+cat.parent_id);
    var at=-1; sibs.forEach(function(s,i){ if(+s.id===id) at=i; });
    var to=at+delta;
    if(at<0 || to<0 || to>=sibs.length) return;
    var order=sibs.map(function(s){ return +s.id; });
    order.splice(to,0,order.splice(at,1)[0]);
    try{
      await catalogWrite('/categories/reorder','POST',{order:order});
      window.catCategories();
    }catch(e){ catToast(e.message); }
  }

  function catCatEditor(cat){
    var isNew=!cat;
    cat=cat||{name:'',slug:'',parent_id:null,description:'',image:'',position:0};
    var banned = isNew ? [] : catSubtreeIds(+cat.id);
    var opts='<option value="">— top level —</option>'+
      catTreeRows(null,0,[]).filter(function(r){ return banned.indexOf(+r.cat.id)===-1; })
        .map(function(r){
          var sel = (cat.parent_id!=null && +cat.parent_id===+r.cat.id) ? ' selected' : '';
          return '<option value="'+(+r.cat.id)+'"'+sel+'>'+sesc(new Array(r.depth+1).join('   ')+r.cat.name)+'</option>';
        }).join('');

    openModal('<div class="modal-h"><b>'+(isNew?'Add category':'Edit category')+'</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+
      '<div class="fld"><label>Name</label><input id="cat_name" value="'+sesc(cat.name)+'">'+
        (window.KBBArabic ? KBBArabic.boxIf((cat&&cat.translations)||CATEGORIES_ARABIC, {
          field:'name', label:'Name', prefill:(cat&&cat.translations)||null,
          maxlength:255, from:'#cat_name'
        }) : '')+'</div>'+
      '<div class="fld"><label>Slug</label><input id="cat_slug" value="'+sesc(cat.slug)+'" placeholder="left blank, made from the name">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">One segment of /collections/…/. Lower case, hyphens. The full path is built from the parents.</p></div>'+
      '<div class="fld"><label>Parent</label><select class="inp" id="cat_parent" style="width:100%">'+opts+'</select>'+
      (isNew?'':'<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">This category and anything under it are not offered — a category cannot sit inside itself.</p>')+'</div>'+
      imgUploadField('cat_image', cat.image||'', 'Image', 'categories')+
      '<div class="fld"><label>Description</label><textarea id="cat_desc" class="inp" rows="3">'+sesc(cat.description)+'</textarea>'+
        (window.KBBArabic ? KBBArabic.boxIf((cat&&cat.translations)||CATEGORIES_ARABIC, {
          field:'description', label:'Description', prefill:(cat&&cat.translations)||null,
          type:'textarea', rows:3, maxlength:5000, from:'#cat_desc'
        }) : '')+'</div>'+
      '<div class="fld" style="max-width:160px"><label>Position</label><input id="cat_pos" type="number" min="0" value="'+sesc(cat.position||0)+'"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" id="cat_save">'+(isNew?'Create category':'Save category')+'</button></div></div>');
    wireImgUpload('cat_image','categories');

    /* Attaches the Translate buttons and reveals them ONLY if an API key is
       configured. With no key they are never shown and typing by hand works. */
    if(window.KBBArabic) KBBArabic.wire(document);

    document.getElementById('cat_save').onclick=async function(){
      var parent=sval('cat_parent');
      var payload={
        name: sval('cat_name'), slug: sval('cat_slug'),
        parent_id: parent===''?null:parseInt(parent,10),
        description: sval('cat_desc'), image: sval('cat_image'),
        position: parseInt(sval('cat_pos'),10)||0,
        /* The Arabic goes up in the SAME request as the English. */
        translations: window.KBBArabic ? KBBArabic.collect(document) : {}
      };
      if(!payload.name){ catToast('A category needs a name'); return; }
      try{
        await (isNew ? catalogWrite('/categories','POST',payload)
                     : catalogWrite('/categories/'+(+cat.id),'PUT',payload));
        catToast(isNew?'Category created':'Category saved'); closeModal(); window.catCategories();
      }catch(e){ catToast(e.message); }
    };
  }

  async function catCatDelete(cat){
    if(!cat) return;
    var filed=(+cat.products_count||0), primary=(+cat.primary_count||0), kids=(+cat.children_count||0);
    var warn;
    if(filed||primary||kids){
      var bits=[];
      if(filed) bits.push('<b>'+filed+'</b> product'+(filed===1?' is':'s are')+' filed under it');
      if(primary) bits.push('<b>'+primary+'</b> product'+(primary===1?' has':'s have')+' it as their primary category');
      if(kids) bits.push('<b>'+kids+'</b> sub-categor'+(kids===1?'y sits':'ies sit')+' under it');
      warn='<p style="font-size:13px;color:var(--ink-2)">'+bits.join(', ')+'. Deleting <b>'+sesc(cat.name)+
        '</b> empties its archive page and moves any sub-category up a level. <b>No product is deleted</b> — only the link to this category.</p>';
    } else {
      warn='<p style="font-size:13px;color:var(--ink-2)">Delete <b>'+sesc(cat.name)+'</b>? Nothing is attached to it. This cannot be undone.</p>';
    }
    openModal('<div class="modal-h"><b>Delete category</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+warn+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" style="background:var(--danger,#d6455a)" id="cat_del_yes">Delete</button></div></div>');
    document.getElementById('cat_del_yes').onclick=async function(){
      try{
        // force is what the operator just confirmed: without it the API refuses
        // to detach products and re-parent children behind their back.
        await catalogWrite('/categories/'+(+cat.id)+'?force=1','DELETE',null);
        catToast('Category deleted'); closeModal(); window.catCategories();
      }catch(e){ catToast(e.message); }
    };
  }

  /* ---------- Catalog → Attributes (real CRUD, replacing the preview cards)
     An attribute is a global list of terms; nothing joins a product to the
     attribute itself. Products join to its VALUES through
     product_attribute_value (which terms a product offers — what the shop
     filter matches), and variants join to them through
     product_variant_attribute_value (which terms define one purchasable
     variant). So the counts on screen are per-term counts rolled up, and the
     delete warnings talk about variants for a reason: a variant that loses the
     term defining it stays on sale with nothing left to say what it is. */
  var ATTRIBUTES=[];

  window.catAttributes = async function(){
    var body=document.getElementById('catBody');
    if(!body) return;
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading attributes…</p>';
    try{
      var d=await catalogWrite('/attributes','GET',null); ATTRIBUTES=d.attributes||[];
    }catch(e){ ATTRIBUTES=[]; }
    catAttrPaint();
  };

  function attrById(id){ return ATTRIBUTES.filter(function(a){ return +a.id===id; })[0]; }

  function attrValueById(attr, id){
    return (attr.values||[]).filter(function(v){ return +v.id===id; })[0];
  }

  function catAttrPaint(){
    var body=document.getElementById('catBody');
    if(!body) return;

    body.innerHTML=
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:13px">A global attribute is a named list of terms. Products carry the terms they offer; a variable product’s variants are each pinned to one term per axis. Turning an attribute into a variation axis is what lets variants be built from it; making it filterable is what puts it in the storefront filter panel.</p>'+
      '<div class="between" style="margin-bottom:12px"><span class="pill grey">'+ATTRIBUTES.length+' attribute'+(ATTRIBUTES.length===1?'':'s')+'</span>'+
      '<button class="btn sm" id="attr_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add attribute</button></div>'+
      (ATTRIBUTES.length?
        ATTRIBUTES.map(function(a){
          var vals=a.values||[];
          return '<div class="card pad" style="margin-bottom:12px">'+
            '<div class="between"><div><b style="font-size:13.5px">'+sesc(a.name)+'</b>'+
              '<span style="font-family:var(--mono);font-size:11.5px;color:var(--ink-soft);margin-left:8px">'+sesc(a.slug)+'</span>'+
              (a.is_variation_axis?'<span class="pill green" style="margin-left:8px">variation axis</span>':'')+
              (a.is_filterable?'<span class="pill grey" style="margin-left:6px">filterable as '+sesc(a.query_var||('filter_'+a.slug))+'</span>':'')+
            '</div>'+
            '<div class="row" style="gap:4px">'+
              '<button class="btn ghost sm" data-avadd="'+(+a.id)+'">Add term</button>'+
              '<button class="btn ghost sm" data-aedit="'+(+a.id)+'">Edit</button>'+
              '<button class="btn ghost sm" data-adel="'+(+a.id)+'">Delete</button>'+
            '</div></div>'+
            '<p style="font-size:11.5px;color:var(--ink-soft);margin:6px 0 0">'+
              vals.length+' term'+(vals.length===1?'':'s')+' · '+
              (+a.products_count||0)+' product'+((+a.products_count||0)===1?'':'s')+' · '+
              (+a.variants_count||0)+' variant'+((+a.variants_count||0)===1?'':'s')+'</p>'+
            (vals.length?
              '<div class="tagchips" style="margin-top:11px">'+vals.map(function(v){
                var swatch = v.swatch_color
                  ? '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:'+sesc(v.swatch_color)+';margin-right:5px;vertical-align:middle"></span>'
                  : '';
                return '<span class="tagchip">'+swatch+sesc(v.name)+
                  '<span style="color:var(--ink-faint);margin-left:5px">'+(+v.products_count||0)+'/'+(+v.variants_count||0)+'</span>'+
                  '<button class="btn ghost sm" style="margin-left:6px;padding:0 5px" data-avedit="'+(+a.id)+':'+(+v.id)+'" title="Edit term">✎</button>'+
                  '<button class="btn ghost sm" style="padding:0 5px" data-avdel="'+(+a.id)+':'+(+v.id)+'" title="Delete term">✕</button>'+
                '</span>';
              }).join('')+'</div>'+
              '<p style="font-size:11px;color:var(--ink-faint);margin:8px 0 0">The two numbers on each term are products / variants using it.</p>'
              : '<p style="font-size:12px;color:var(--ink-soft);margin:11px 0 0">No terms yet — add the first one.</p>')+
          '</div>';
        }).join('')
        : '<p style="padding:24px;color:var(--ink-soft)">No attributes yet — add the first one.</p>');

    document.getElementById('attr_add').onclick=function(){ catAttrEditor(null); };
    document.querySelectorAll('#catBody [data-aedit]').forEach(function(b){
      b.onclick=function(){ catAttrEditor(attrById(+b.dataset.aedit)); };
    });
    document.querySelectorAll('#catBody [data-adel]').forEach(function(b){
      b.onclick=function(){ catAttrDelete(attrById(+b.dataset.adel)); };
    });
    document.querySelectorAll('#catBody [data-avadd]').forEach(function(b){
      b.onclick=function(){ catValEditor(attrById(+b.dataset.avadd), null); };
    });
    document.querySelectorAll('#catBody [data-avedit]').forEach(function(b){
      b.onclick=function(){
        var p=b.dataset.avedit.split(':'), a=attrById(+p[0]);
        catValEditor(a, attrValueById(a, +p[1]));
      };
    });
    document.querySelectorAll('#catBody [data-avdel]').forEach(function(b){
      b.onclick=function(){
        var p=b.dataset.avdel.split(':'), a=attrById(+p[0]);
        catValDelete(a, attrValueById(a, +p[1]));
      };
    });
  }

  function catAttrEditor(attr){
    var isNew=!attr;
    attr=attr||{name:'',slug:'',query_var:'',is_variation_axis:false,is_filterable:true,position:0};
    openModal('<div class="modal-h"><b>'+(isNew?'Add attribute':'Edit attribute')+'</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+
      '<div class="fld"><label>Name</label><input id="atr_name" value="'+sesc(attr.name)+'"></div>'+
      '<div class="fld"><label>Slug</label><input id="atr_slug" value="'+sesc(attr.slug)+'" placeholder="left blank, made from the name"></div>'+
      '<div class="fld"><label>Filter parameter</label><input id="atr_qv" value="'+sesc(attr.query_var||'')+'" placeholder="filter_color">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">The query string this attribute filters on. Existing links use the imported value — changing it breaks them. Leave blank and filter_{slug} is used.</p></div>'+
      '<div class="fld"><label><input type="checkbox" id="atr_axis"'+(attr.is_variation_axis?' checked':'')+'> Use as a variation axis</label>'+
      '<p class="description" style="margin:4px 0 0;font-size:11.5px;color:var(--ink-soft)">Variants of a variable product can be built from this attribute’s terms.</p></div>'+
      '<div class="fld"><label><input type="checkbox" id="atr_filt"'+(attr.is_filterable?' checked':'')+'> Show in the storefront filter panel</label></div>'+
      '<div class="fld" style="max-width:160px"><label>Position</label><input id="atr_pos" type="number" min="0" value="'+sesc(attr.position||0)+'"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" id="atr_save">'+(isNew?'Create attribute':'Save attribute')+'</button></div></div>');

    document.getElementById('atr_save').onclick=async function(){
      var payload={
        name: sval('atr_name'), slug: sval('atr_slug'), query_var: sval('atr_qv'),
        is_variation_axis: document.getElementById('atr_axis').checked,
        is_filterable: document.getElementById('atr_filt').checked,
        position: parseInt(sval('atr_pos'),10)||0
      };
      if(!payload.name){ catToast('An attribute needs a name'); return; }
      try{
        await (isNew ? catalogWrite('/attributes','POST',payload)
                     : catalogWrite('/attributes/'+(+attr.id),'PUT',payload));
        catToast(isNew?'Attribute created':'Attribute saved'); closeModal(); window.catAttributes();
      }catch(e){ catToast(e.message); }
    };
  }

  async function catAttrDelete(attr){
    if(!attr) return;
    var p=(+attr.products_count||0), v=(+attr.variants_count||0), n=(attr.values||[]).length;
    var warn = (p||v)
      ? '<p style="font-size:13px;color:var(--ink-2)">The '+n+' term'+(n===1?'':'s')+' of <b>'+sesc(attr.name)+'</b> '+
        (n===1?'is':'are')+' still used by <b>'+p+'</b> product'+(p===1?'':'s')+' and <b>'+v+'</b> variant'+(v===1?'':'s')+
        '. Deleting the attribute strips those terms from them. <b>No product and no variant is deleted</b>'+
        (v?' — but a variant that loses the term defining it stays on sale with nothing left to say what it is.':'.')+'</p>'
      : '<p style="font-size:13px;color:var(--ink-2)">Delete <b>'+sesc(attr.name)+'</b> and its '+n+' term'+(n===1?'':'s')+'? Nothing is using them. This cannot be undone.</p>';
    openModal('<div class="modal-h"><b>Delete attribute</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+warn+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" style="background:var(--danger,#d6455a)" id="atr_del_yes">Delete</button></div></div>');
    document.getElementById('atr_del_yes').onclick=async function(){
      try{
        await catalogWrite('/attributes/'+(+attr.id)+'?force=1','DELETE',null);
        catToast('Attribute deleted'); closeModal(); window.catAttributes();
      }catch(e){ catToast(e.message); }
    };
  }

  function catValEditor(attr, val){
    if(!attr) return;
    var isNew=!val;
    val=val||{name:'',slug:'',swatch_color:'',swatch_image:'',position:0};
    openModal('<div class="modal-h"><b>'+(isNew?'Add term':'Edit term')+' · '+sesc(attr.name)+'</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+
      '<div class="fld"><label>Name</label><input id="atv_name" value="'+sesc(val.name)+'"></div>'+
      '<div class="fld"><label>Slug</label><input id="atv_slug" value="'+sesc(val.slug)+'" placeholder="left blank, made from the name">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">Unique within this attribute only — “large” may exist under Size and under Shades.</p></div>'+
      '<div class="fld" style="max-width:200px"><label>Swatch colour</label><input id="atv_color" value="'+sesc(val.swatch_color||'')+'" placeholder="#E0567B"></div>'+
      imgUploadField('atv_image', val.swatch_image||'', 'Swatch image', 'attributes')+
      '<div class="fld" style="max-width:160px"><label>Position</label><input id="atv_pos" type="number" min="0" value="'+sesc(val.position||0)+'"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" id="atv_save">'+(isNew?'Create term':'Save term')+'</button></div></div>');
    wireImgUpload('atv_image','attributes');

    document.getElementById('atv_save').onclick=async function(){
      var payload={
        name: sval('atv_name'), slug: sval('atv_slug'),
        swatch_color: sval('atv_color'), swatch_image: sval('atv_image'),
        position: parseInt(sval('atv_pos'),10)||0
      };
      if(!payload.name){ catToast('A term needs a name'); return; }
      try{
        await (isNew ? catalogWrite('/attributes/'+(+attr.id)+'/values','POST',payload)
                     : catalogWrite('/attributes/'+(+attr.id)+'/values/'+(+val.id),'PUT',payload));
        catToast(isNew?'Term created':'Term saved'); closeModal(); window.catAttributes();
      }catch(e){ catToast(e.message); }
    };
  }

  async function catValDelete(attr, val){
    if(!attr || !val) return;
    var p=(+val.products_count||0), v=(+val.variants_count||0);
    var warn = (p||v)
      ? '<p style="font-size:13px;color:var(--ink-2)"><b>'+sesc(val.name)+'</b> is still used by <b>'+p+'</b> product'+(p===1?'':'s')+
        ' and <b>'+v+'</b> variant'+(v===1?'':'s')+'. Removing it strips the term from them. <b>No product and no variant is deleted</b>'+
        (v?' — but a variant that loses the term defining it stays on sale with nothing left to say what it is.':'.')+'</p>'
      : '<p style="font-size:13px;color:var(--ink-2)">Delete the term <b>'+sesc(val.name)+'</b>? Nothing is using it. This cannot be undone.</p>';
    openModal('<div class="modal-h"><b>Delete term</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+warn+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" style="background:var(--danger,#d6455a)" id="atv_del_yes">Delete</button></div></div>');
    document.getElementById('atv_del_yes').onclick=async function(){
      try{
        await catalogWrite('/attributes/'+(+attr.id)+'/values/'+(+val.id)+'?force=1','DELETE',null);
        catToast('Term deleted'); closeModal(); window.catAttributes();
      }catch(e){ catToast(e.message); }
    };
  }
  /* ===== LANE N · Catalog · Categories & Attributes — END ================== */

  /* ===== LANE AF · Catalog · Products — BEGIN ================================

     WHAT WAS HERE BEFORE. catProducts() in the first script block: a list, a
     five-chip row, four sorts, and an Edit button whose entire implementation
     was a toast saying that product editing had not been built. The list was
     real — it paginated and searched on the server — but this is the screen a
     shop owner spends their day on, and the two things they do on it all day,
     change a price and change a stock number, were the two things it could not
     do. There was no export, no bulk action, no way to see what had no image or
     no category, and the 'private' status the schema declares had no chip at
     all. That block is gone; what is left of it upstairs is an honest fallback
     message.

     Everything is now asked of the server: one page of rows, every chip count,
     the summary tiles and the sort all come back from
     /admin-api/catalog-products-list for the filters currently on screen. No
     figure on this page is computed in the browser.

     MONEY NEVER BECOMES A NUMBER IN HERE. Prices arrive as `price_display`
     (already formatted by Money::plain) and `price_input` (a plain decimal
     string for a text box), and they go back as the string the operator typed.
     Nothing in this region multiplies, divides or rounds a price — the server
     parses the digits and stores integer fils. The bulk percentage is sent as
     text too, and turned into integer basis points on the other side. A float
     spelling of "30% off" is 0.69999999999999995559 and lands a fil light,
     which is a defect this repo has already paid for once.

     THE 390px RULE. The table lives in .cplscroll, which is overflow-x:auto and
     max-width:100% — it scrolls inside the card and contributes nothing to the
     width of the page. Every grid uses minmax(0,1fr) rather than 1fr, because a
     grid track defaults to min-width:auto and one long money figure otherwise
     widens its track past its share and pushes the whole page sideways.
     Measured in real Chromium at 390 and 1280; #content reports
     scrollWidth === clientWidth at both.

     BLANKS ARE THE NORMAL CASE. An imported product can have no image, no
     category, no brand, no SKU and no price. Every cell falls back to an em
     dash rather than printing "undefined", and the chips count each of those
     conditions so they can be found rather than stumbled over.

     Product names and SKUs come out of a WooCommerce export and land in
     innerHTML. Everything written into the page goes through sesc().
  */

  (function cplStyles(){
    if(document.getElementById('cplcss')) return;

    /* Injected rather than added to the stylesheet at the top of this file:
       that block is shared by every screen and several lanes are editing this
       view at once. A style element this region owns outright cannot collide
       with somebody else's rule. */
    var s = document.createElement('style');
    s.id = 'cplcss';
    s.textContent =
      '.cplkpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}' +
      '@media(max-width:900px){.cplkpis{grid-template-columns:repeat(2,minmax(0,1fr))}}' +
      /* Phones keep two cards a row (the owner, 2 October 2026: "in mobile I
         want them in two rows maximum"); four stacked cards pushed the list a
         full screen down. */
      '@media(max-width:430px){.cplkpis{gap:10px}.cplkpi{padding:13px}.cplkpi .v{font-size:18px}}' +
      '.cplkpi{min-width:0;overflow-wrap:anywhere}' +
      '.cplkpi .v{font-size:21px;font-weight:700;margin-top:6px;line-height:1.15}' +
      '.cplkpi .k{font-size:11px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em}' +
      '.cplkpi .s{font-size:11.5px;color:var(--ink-soft);margin-top:2px}' +
      /* The whole point of the 390px fix: a wide table scrolls in here, never
         on the page. max-width:100% stops a min-width table stretching the
         card it is inside. */
      '.cplscroll{max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}' +
      '.cplscroll table{min-width:940px}' +
      /* THE ACTIONS FELL OFF THE RIGHT EDGE ON DESKTOP (2 October 2026, the
         owner's screenshot of Catalog with 788 imported products). The Product
         cell had no width limit, so one long imported name ("medicube - PDRN
         Pink Collagen Jelly Eye Mask - 6 pairs") widened the whole table past
         the screen and View / Visit / Edit were only reachable by scrolling
         sideways. On desktop the Product column now takes only the room the
         other columns leave and shortens the name with an ellipsis (the full
         name is its title), and on every width the actions column is pinned
         to the right edge, so turning more columns on scrolls the middle of
         the table and never hides the buttons. */
      '@media(min-width:901px){.cplscroll table{min-width:0;width:100%}' +
        '.cplscroll th,.cplscroll td{padding-left:9px;padding-right:9px}}' +
      '.cplscroll td.cplprod{min-width:230px;max-width:360px}' +
      '@media(min-width:901px){.cplscroll th.cplprod,.cplscroll td.cplprod{width:100%;max-width:0}}' +
      '.cplscroll td.cplprod>.row>div{min-width:0;overflow:hidden}' +
      '.cplscroll td.cplprod .pname,.cplscroll td.cplprod .pbrand{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.cplscroll th.cplact,.cplscroll td.cplact{position:sticky;right:0;z-index:1;background:var(--card,#fff);' +
        'box-shadow:-10px 0 10px -10px rgba(16,23,41,.25)}' +
      '.cplhint{display:none;font-size:11.5px;color:var(--ink-soft);padding:10px 14px 0}' +
      '@media(max-width:900px){.cplhint{display:block}}' +
      '.cpltools{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}' +
      '.cpltools .search{flex:1 1 220px;min-width:0}' +
      /* On desktop the row wrapper is invisible to layout (display:contents),
         so the toolbar is exactly what it was. On phones the search keeps its
         own full-width row and Sort / Filters / Customize columns / Export
         sit in ONE row that swipes sideways, instead of stacking. */
      '.cpltoolrow{display:contents}' +
      '@media(max-width:900px){.cpltools .search{flex:1 1 100%}' +
        '.cpltoolrow{display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;width:100%;' +
        '-webkit-overflow-scrolling:touch;scrollbar-width:none;padding-bottom:2px}' +
        '.cpltoolrow::-webkit-scrollbar{display:none}' +
        '.cpltoolrow>*{flex:0 0 auto;white-space:nowrap}}' +
      /* "In stock" and every other pill stay on one line in the table. */
      '.cplscroll .pill{white-space:nowrap}' +
      '.cpltools .inp{max-width:100%}' +
      '.cplgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:12px}' +
      '.cplbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}' +
      '.cplpager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;' +
        'padding:13px 4px 2px;font-size:12.5px;color:var(--ink-soft)}' +
      '.cplnum{font-variant-numeric:tabular-nums;white-space:nowrap}' +
      '.cplcell{cursor:text;border-radius:5px;padding:2px 5px;margin:-2px -5px;display:inline-block;min-width:44px}' +
      '.cplcell:hover{background:var(--border-2,rgba(0,0,0,.05))}' +
      '.cplin{font:inherit;width:84px;max-width:100%;padding:3px 6px;border:1px solid var(--accent,#3f6fe0);' +
        'border-radius:5px;background:var(--card,#fff);color:inherit;text-align:right}' +
      '.cplsel{font:inherit;padding:2px 4px;border:1px solid var(--border);border-radius:5px;' +
        'background:var(--card,#fff);color:inherit;max-width:100%}' +
      '.cplthumb{width:34px;height:34px;border-radius:6px;object-fit:cover;flex-shrink:0;background:var(--border-2)}' +
      '.cplnoimg{width:34px;height:34px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;' +
        'justify-content:center;border:1px dashed var(--border);color:var(--ink-faint);font-size:9px}' +
      '.cplpanel{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:16px}' +
      '@media(max-width:860px){.cplpanel{grid-template-columns:minmax(0,1fr)}}' +
      '.cplfield{margin-bottom:12px}' +
      '.cplfield label{display:block;font-size:11.5px;color:var(--ink-soft);margin-bottom:4px}' +
      '.cplfield .inp,.cplfield textarea{width:100%;max-width:100%;box-sizing:border-box}' +
      '.cplpair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}' +
      '.cplcats{display:flex;flex-wrap:wrap;gap:6px}' +
      '.cplcat{font-size:11.5px;border:1px solid var(--border);border-radius:999px;padding:3px 10px;cursor:pointer}' +
      '.cplcat.on{background:var(--ink,#1c2430);color:var(--card,#fff);border-color:var(--ink,#1c2430)}';

    document.head.appendChild(s);
  })();

  var CP = {
    page: 1,
    perPage: +(localStorage.getItem('kbb_cp_pp') || 50),
    search: '', filter: 'all', sort: 'newest',
    brandId: '', categoryId: '', priceMin: '', priceMax: '',
    adv: false, colsOpen: false, cols: null,
    data: null, facets: null, err: null, sel: {}, busy: false, detail: null
  };

  var CP_COLDEF = [
    ['sku', 'SKU'], ['brand', 'Brand'], ['status', 'Status'], ['stock', 'Stock'],
    ['price', 'Price'], ['sale', 'Sale price'], ['categories', 'Categories'],
    ['featured', 'Featured'], ['orders', 'Orders'], ['date', 'Added'], ['wc', 'Woo ID']
  ];

  /* Woo ID, Sale price, SKU and Added are off by default and one click away
     in Customize columns. (SKU and Added went off on 2 October 2026 so the
     default set fits a 1280px screen beside a readable Product column --
     measured: 1,122px of columns in a 994px table; most imported products
     carry no SKU at all.)
     The eight that are on already fill a 1032px content area, and a column
     nobody reads is a column that costs horizontal room on every page load. */
  var CP_COLS_DEFAULT = {
    sku: false, brand: true, status: true, stock: true, price: true,
    sale: false, categories: true, featured: true, orders: true, date: false, wc: false
  };

  /* Which sort each sortable header maps to, so the header caret and the Sort
     menu can never disagree about what the list is ordered by. */
  var CP_COLSORT = {
    price: 'price_desc', stock: 'stock_asc', orders: 'orders_desc',
    date: 'newest', status: 'status', brand: 'brand', sku: 'sku'
  };

  var CP_SORTS = [
    ['newest', 'Newest first'], ['oldest', 'Oldest first'], ['updated', 'Recently edited'],
    ['name', 'Name A–Z'], ['name_desc', 'Name Z–A'],
    ['price_desc', 'Price, high to low'], ['price_asc', 'Price, low to high'],
    ['stock_asc', 'Stock, low to high'], ['stock_desc', 'Stock, high to low'],
    ['sku', 'SKU'], ['brand', 'Brand A–Z'], ['status', 'Status'],
    ['orders_desc', 'Most orders'], ['sales_desc', 'Most units sold'],
    ['position', 'Catalogue order']
  ];

  /* How this schema's own statuses read on a chip. A status NOT in here came
     out of an import — the dead importer this repo replaced wrote 'active' —
     and it is shown verbatim, because that is the string the operator will
     search WooCommerce for and prettifying it throws that away for nothing. */
  var CP_STATUS_LABEL = { publish: 'Published', draft: 'Draft', private: 'Private' };
  var CP_STOCK_LABEL = { instock: 'In stock', outofstock: 'Out of stock', onbackorder: 'On backorder' };
  var CP_STATUS_PILL = { publish: 'green', draft: 'grey', private: 'amber' };

  /* The chips that are not a status value, in the order they are drawn. Each
     one is a way an imported product can be incomplete, plus the trash. */
  var CP_DERIVED_CHIPS = [
    ['on_sale', 'On sale'], ['low', 'Low stock'], ['no_image', 'No image'],
    ['no_category', 'No category'], ['no_price', 'No price'], ['hidden', 'Hidden'],
    ['featured', 'Featured'], ['set', 'Sets'], ['trashed', 'Trash']
  ];

  var CP_PER_PAGE = [25, 50, 100, 200, 500];

  function cpCols(){
    if(CP.cols) return CP.cols;
    var saved = null;
    try{ saved = JSON.parse(localStorage.getItem('kbb_cp_cols') || 'null'); }catch(e){ saved = null; }
    CP.cols = Object.assign({}, CP_COLS_DEFAULT, saved || {});
    return CP.cols;
  }
  function cpSaveCols(){ try{ localStorage.setItem('kbb_cp_cols', JSON.stringify(CP.cols)); }catch(e){} }

  function cpParams(forExport){
    var p = new URLSearchParams();
    if(!forExport){ p.set('page', CP.page); p.set('per_page', CP.perPage); }
    if(CP.search) p.set('search', CP.search);
    if(CP.filter && CP.filter !== 'all') p.set('filter', CP.filter);
    if(CP.sort && CP.sort !== 'newest') p.set('sort', CP.sort);
    if(CP.brandId) p.set('brand_id', CP.brandId);
    if(CP.categoryId) p.set('category_id', CP.categoryId);
    if(CP.priceMin !== '') p.set('price_min', CP.priceMin);
    if(CP.priceMax !== '') p.set('price_max', CP.priceMax);
    return p.toString();
  }

  function cpDash(v){ return (v === null || v === undefined || v === '') ? '<span style="color:var(--ink-faint)">—</span>' : sesc(v); }
  function cpTitle(s){ return String(s || '').charAt(0).toUpperCase() + String(s || '').slice(1); }
  function cpStatusLabel(s){ return CP_STATUS_LABEL[s] || s; }
  function cpStockLabel(s){ return CP_STOCK_LABEL[s] || s; }

  /* A write that unpacks the operator-facing message the server sent. 422
     carries either Laravel's `errors` bag or the controller's own `message`,
     and both are written for a person, so both are shown. Same contract as
     catalogWrite() above, deliberately: two screens next to each other
     behaving differently would be the surprise. */
  async function cpWrite(path, body, method){
    /* The FULL '/admin-api/...' path, not a suffix bolted onto a prefix in
       here. Every endpoint this screen writes to is then greppable in this
       file by its real name, which is what lets AdminCatalogProductsTest
       assert that each one is actually called. */
    var r = await fetch(fixAdminApiUrl(path), {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: {'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
      body: body ? JSON.stringify(body) : undefined
    });
    var j = {}; try{ j = await r.json(); }catch(e){}
    if(!r.ok){
      var msg = j.message || '';
      if(j.errors){ msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' '); }
      var err = new Error(msg || ('Request failed (' + r.status + ')'));
      err.payload = j; err.status = r.status;
      throw err;
    }
    return j;
  }

  /* toast() assigns its argument into innerHTML, so anything that can contain a
     product name — every message this region raises — is escaped on the way. */
  function cpToast(msg){ toast(sesc(msg)); }

  /* ---------------------------------------------------------------- loading */

  window.catProducts = async function(){
    var body = document.getElementById('catBody');
    if(!body) return;
    body.innerHTML = '<p style="padding:24px;color:var(--ink-soft)">Loading products…</p>';
    await cpLoad();
  };

  async function cpLoad(){
    var body = document.getElementById('catBody');
    if(!body) return;

    var listArea = document.getElementById('cplListArea');
    if(listArea) listArea.innerHTML = '<p style="padding:24px;color:var(--ink-soft)">Loading…</p>';

    CP.err = null;

    try{
      CP.data = await api('/admin-api/catalog-products-list?' + cpParams(false));
    }catch(e){
      /* SAY WHICH FAILURE IT WAS. (Lane SEC) This screen answered every
         refusal with "the routes may not be wired into routes/web.php yet",
         which for an EXPIRED SESSION sends the owner to Store -> Cache to
         clear a route cache that is perfectly fine, while the one thing that
         would fix it -- signing in again -- is never mentioned. The route
         sentence is kept for the fault it was written for, and is now shown
         only for that fault. Worded the way
         admin/partials/product-editor-screen.blade.php already words it. */
      CP.err = e && e.message ? e.message : 'unknown error';

      if(e && (e.status === 401 || e.status === 419)){
        body.innerHTML = '<div class="card pad"><p style="color:var(--sale,#c0392b);font-size:13px">' +
          'Your session has ended, so this list could not be loaded.</p>' +
          '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">' +
          'Sign in again and it loads as it did.</p></div>';
        return;
      }

      body.innerHTML = '<div class="card pad"><p style="color:var(--sale,#c0392b);font-size:13px">' +
        'The product list could not be loaded — ' + sesc(CP.err) + '</p>' +
        (e && e.status === 404
          ? '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">If this is a fresh deployment, the ' +
            'Catalog → Products routes may not be wired into routes/web.php yet.</p>'
          : '') + '</div>';
      return;
    }

    /* The brand and category vocabularies, once per visit rather than once per
       page of rows. They only change when somebody edits a category. */
    if(!CP.facets){
      try{ CP.facets = await api('/admin-api/catalog-products-facets'); }
      catch(e){ CP.facets = {brands: [], categories: []}; }
    }

    CP.perPage = CP.data.per_page;
    cpPaint();
  }

  /* ---------------------------------------------------------------- drawing */

  function cpPaint(){
    var body = document.getElementById('catBody');
    if(!body || !CP.data) return;

    var d = CP.data;
    var cols = cpCols();
    var counts = d.counts || {};
    var sum = d.summary || {};
    var selected = cpSelectedIds();

    var chips = [['all', 'All']];
    (d.statuses || []).forEach(function(s){ chips.push([s, cpStatusLabel(s)]); });
    (d.stock_statuses || []).forEach(function(s){ chips.push([s, cpStockLabel(s)]); });
    CP_DERIVED_CHIPS.forEach(function(c){ chips.push(c); });

    body.innerHTML =
      cpKpis(sum, d) +
      '<div class="cpltools">' +
        '<div class="search" style="min-width:0">' +
          ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>') +
          '<input id="cplSearch" placeholder="Search ' + (d.total || 0) + ' products by name, SKU, brand or Woo ID…" value="' + sesc(CP.search) + '">' +
        '</div>' +
        '<div class="cpltoolrow">' +
        '<select class="inp" id="cplSort" style="max-width:220px">' +
          CP_SORTS.map(function(s){
            return '<option value="' + s[0] + '"' + (CP.sort === s[0] ? ' selected' : '') + '>' + sesc(s[1]) + '</option>';
          }).join('') +
        '</select>' +
        '<button class="btn ghost" id="cplAdvBtn">' + ic('<path d="M4 6h16M7 12h10M10 18h4"/>') + ' Filters ' + (CP.adv ? '▴' : '▾') + '</button>' +
        '<button class="btn ghost" id="cplColsBtn">Customize columns ' + (CP.colsOpen ? '▴' : '▾') + '</button>' +
        '<button class="btn ghost" id="cplExport">' + ic('<path d="M12 3v12M8 11l4 4 4-4"/><path d="M4 19h16"/>') + ' Export CSV</button>' +
        '</div>' +
      '</div>' +
      (CP.adv ? cpAdvanced() : '') +
      (CP.colsOpen ? cpColumnsPanel() : '') +
      '<div class="chips" style="margin-bottom:10px">' +
        chips.map(function(c){
          var n = counts[c[0]];
          if(n === undefined) n = 0;
          return '<button class="chip' + (CP.filter === c[0] ? ' on' : '') + '" data-cpf="' + sesc(c[0]) + '">' +
            sesc(c[1]) + ' <span style="opacity:.6">' + n + '</span></button>';
        }).join('') +
      '</div>' +
      cpBulkBar(selected) +
      '<div class="card" style="padding:0">' +
        '<div class="cplhint">Swipe the table sideways to see every column.</div>' +
        '<div class="cplscroll" id="cplListArea">' + cpTable(d.products || [], cols, d) + '</div>' +
      '</div>' +
      '<div class="cplpager">' +
        '<span>Showing ' + ((d.products || []).length ? ((d.page - 1) * d.per_page + 1) : 0) + '–' +
          ((d.page - 1) * d.per_page + (d.products || []).length) + ' of ' + d.total + '</span>' +
        '<div class="row" style="gap:8px;align-items:center">' +
          '<select class="inp" id="cplPerPage" style="width:auto">' +
            CP_PER_PAGE.map(function(n){
              return '<option value="' + n + '"' + (n === CP.perPage ? ' selected' : '') + '>' + n + ' per page</option>';
            }).join('') +
          '</select>' +
          '<button class="btn ghost sm" style="white-space:nowrap"' + (d.page <= 1 ? ' disabled' : '') + ' id="cplPrev">‹ Prev</button>' +
          '<span class="cplnum">' + d.page + ' / ' + d.last_page + '</span>' +
          '<button class="btn ghost sm" style="white-space:nowrap"' + (d.page >= d.last_page ? ' disabled' : '') + ' id="cplNext">Next ›</button>' +
        '</div>' +
      '</div>';

    cpBind();
  }

  function cpKpis(sum, d){
    return '<div class="cplkpis">' +
      cpKpi('Products', (sum.products || 0).toLocaleString(), (sum.live || 0) + ' live on the shop') +
      cpKpi('Inventory value', sum.inventory_display || '—', (sum.stock_units || 0).toLocaleString() + ' units tracked') +
      cpKpi('Average price', sum.average_price_display || '—', (sum.priced || 0) + ' with a price set') +
      cpKpi('Needs attention', ((d.counts && d.counts.no_price) || 0) + ((d.counts && d.counts.no_image) || 0) + ((d.counts && d.counts.no_category) || 0),
        (sum.out_of_stock || 0) + ' out of stock · ' + (sum.on_sale || 0) + ' on sale') +
      '</div>';
  }

  function cpKpi(k, v, s){
    return '<div class="card pad cplkpi"><div class="k">' + sesc(k) + '</div>' +
      '<div class="v cplnum">' + sesc(v) + '</div><div class="s">' + sesc(s) + '</div></div>';
  }

  function cpAdvanced(){
    var f = CP.facets || {brands: [], categories: []};

    return '<div class="card pad" style="margin-bottom:12px"><div class="cplgrid">' +
      '<div><label style="font-size:11.5px;color:var(--ink-soft)">Brand</label>' +
        '<select class="inp" id="cplBrand" style="width:100%"><option value="">Any brand</option>' +
        (f.brands || []).map(function(b){
          return '<option value="' + b.id + '"' + (String(CP.brandId) === String(b.id) ? ' selected' : '') + '>' + sesc(b.name) + '</option>';
        }).join('') + '</select></div>' +
      '<div><label style="font-size:11.5px;color:var(--ink-soft)">Category</label>' +
        '<select class="inp" id="cplCategory" style="width:100%"><option value="">Any category</option>' +
        (f.categories || []).map(function(c){
          var pad = '';
          for(var i = 0; i < (+c.depth || 0); i++) pad += '— ';
          return '<option value="' + c.id + '"' + (String(CP.categoryId) === String(c.id) ? ' selected' : '') + '>' + sesc(pad + c.name) + '</option>';
        }).join('') + '</select></div>' +
      '<div><label style="font-size:11.5px;color:var(--ink-soft)">Price from</label>' +
        '<input class="inp" id="cplMin" style="width:100%" inputmode="decimal" placeholder="0" value="' + sesc(CP.priceMin) + '"></div>' +
      '<div><label style="font-size:11.5px;color:var(--ink-soft)">Price to</label>' +
        '<input class="inp" id="cplMax" style="width:100%" inputmode="decimal" placeholder="any" value="' + sesc(CP.priceMax) + '"></div>' +
      '</div><div class="row" style="gap:8px;margin-top:12px">' +
      '<button class="btn" id="cplApply">Apply</button>' +
      '<button class="btn ghost" id="cplClearFilters">Clear all</button></div></div>';
  }

  function cpColumnsPanel(){
    return '<div class="card pad" style="margin-bottom:12px">' +
      '<div class="so-cols">' + CP_COLDEF.map(function(c){
        return '<label class="so-col"><span class="cbx' + (CP.cols[c[0]] ? ' on' : '') + '" data-cpcol="' + c[0] + '">' +
          ic(I.check) + '</span> ' + sesc(c[1]) + '</label>';
      }).join('') + '</div>' +
      '<div style="margin-top:14px"><button class="btn ghost sm" id="cplColsReset">Reset to default</button></div></div>';
  }

  function cpBulkBar(selected){
    if(!selected.length) return '';

    var settable = (CP.data && CP.data.settable_statuses) || ['publish', 'draft', 'private'];
    var cats = (CP.facets && CP.facets.categories) || [];

    return '<div class="card pad cplbar" style="margin-bottom:12px">' +
      '<b style="font-size:13px">' + selected.length + ' selected</b>' +
      '<select class="inp sm" id="cplBulkStatus" style="width:auto"><option value="">Set status…</option>' +
        settable.map(function(s){ return '<option value="' + sesc(s) + '">' + sesc(cpStatusLabel(s)) + '</option>'; }).join('') +
      '</select>' +
      '<select class="inp sm" id="cplBulkCatMode" style="width:auto">' +
        '<option value="add">Add to category</option>' +
        '<option value="remove">Remove from category</option>' +
        '<option value="replace">Replace categories with</option>' +
      '</select>' +
      '<select class="inp sm" id="cplBulkCat" style="width:auto;max-width:220px"><option value="">Choose a category…</option>' +
        cats.map(function(c){
          var pad = '';
          for(var i = 0; i < (+c.depth || 0); i++) pad += '— ';
          return '<option value="' + c.id + '">' + sesc(pad + c.name) + '</option>';
        }).join('') +
      '</select>' +
      '<button class="btn ghost sm" id="cplBulkCatGo">Apply</button>' +
      '<button class="btn ghost sm" id="cplBulkPrice">Adjust prices…</button>' +
      '<div style="flex:1"></div>' +
      '<button class="btn ghost sm" id="cplClearSel">Clear selection</button>' +
      '</div>';
  }

  function cpTable(rows, cols, d){
    if(!rows.length){
      return '<div style="padding:34px;text-align:center;color:var(--ink-soft)">' +
        '<p style="font-size:13px">No products match this view.</p>' +
        '<button class="btn ghost sm" id="cplEmptyClear" style="margin-top:12px">Clear the filters</button></div>';
    }

    var allOn = rows.every(function(p){ return CP.sel[p.id]; });

    var head = '<th style="width:34px"><span class="cbx' + (allOn ? ' on' : '') + '" id="cplAll">' + ic(I.check) + '</span></th>' +
      '<th class="cplprod">Product</th>' +
      CP_COLDEF.filter(function(c){ return cols[c[0]]; }).map(function(c){
        var sort = CP_COLSORT[c[0]];
        var align = (c[0] === 'price' || c[0] === 'sale' || c[0] === 'stock' || c[0] === 'orders') ? 'text-align:right' : '';
        var caret = (sort && CP.sort === sort) ? ' ▾' : '';
        return '<th style="' + align + (sort ? ';cursor:pointer' : '') + '"' + (sort ? ' data-cpsort="' + sort + '"' : '') + '>' +
          sesc(c[1]) + caret + '</th>';
      }).join('') +
      '<th class="cplact" style="width:170px"></th>';

    var bodyRows = rows.map(function(p){
      return '<tr' + (CP.sel[p.id] ? ' style="background:var(--border-2,rgba(0,0,0,.03))"' : '') + '>' +
        '<td><span class="cbx' + (CP.sel[p.id] ? ' on' : '') + '" data-cpsel="' + p.id + '">' + ic(I.check) + '</span></td>' +
        '<td class="cplprod" title="' + sesc(p.name) + '"><div class="row" style="min-width:0;gap:9px">' +
          (p.has_image
            ? '<img class="cplthumb" src="' + sesc(p.image) + '" alt="" loading="lazy">'
            : '<span class="cplnoimg" title="No image">no img</span>') +
          '<div style="min-width:0">' +
            '<div class="pname" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + sesc(p.name) + '</div>' +
            '<div class="pbrand" style="font-size:11px;color:var(--ink-soft)">' +
              (p.is_set ? '<span class="pill blue" style="font-size:9px;padding:1px 6px">Set</span> ' : '') +
              (p.is_visible ? '' : '<span class="pill grey" style="font-size:9px;padding:1px 6px">Hidden</span> ') +
              (p.on_sale ? '<span class="pill red" style="font-size:9px;padding:1px 6px">-' + p.discount_percent + '%</span> ' : '') +
              sesc(p.slug) +
            '</div>' +
          '</div>' +
        '</div></td>' +
        CP_COLDEF.filter(function(c){ return cols[c[0]]; }).map(function(c){ return cpCell(c[0], p, d); }).join('') +
        /* (Lane PK) Visit beside Edit: the product on the shop, in a new tab.
           The address is the server's (p.url is Product::url(), base path
           included) and only drawn as a link when p.live says the shop would
           answer it; otherwise a disabled "Not live yet", never a 404. The
           scheme is checked because an href is an href. */
        '<td class="cplact"><div style="display:flex;gap:6px;justify-content:flex-end;white-space:nowrap">' +
          ((p.live && /^(\/|https?:\/\/)/i.test(String(p.url || '')))
            ? '<a class="btn ghost sm" data-cpvisit="' + p.id + '" href="' + sesc(p.url) + '" target="_blank" rel="noopener" title="Open on the shop, in a new tab" style="text-decoration:none">Visit</a>'
            : '<button type="button" class="btn ghost sm" data-cpvisit="' + p.id + '" disabled title="The shop does not show this product yet" style="opacity:.55;cursor:default">Not live yet</button>') +
          '<button class="btn ghost sm" data-cpedit="' + p.id + '">Edit</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');

    return '<table style="width:100%"><thead><tr>' + head + '</tr></thead><tbody>' + bodyRows + '</tbody></table>';
  }

  /* One cell.
     price, sale price, stock and status are EDITABLE IN PLACE — those are the
     four an owner changes all day. Each one carries the field name and the
     current value as a plain decimal string (price_input), never a number, so
     what goes back to the server is the text the operator sees. */
  function cpCell(k, p, d){
    switch(k){
      case 'sku':
        return '<td style="font-family:var(--mono);font-size:11px;color:var(--ink-soft);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + cpDash(p.sku) + '</td>';

      case 'brand':
        return '<td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + cpDash(p.brand) + '</td>';

      case 'status':
        return '<td><select class="cplsel" data-cpfield="status" data-cpid="' + p.id + '">' +
          ((d.settable_statuses || []).concat(
            (d.settable_statuses || []).indexOf(p.status) === -1 ? [p.status] : []
          )).map(function(s){
            return '<option value="' + sesc(s) + '"' + (s === p.status ? ' selected' : '') + '>' + sesc(cpStatusLabel(s)) + '</option>';
          }).join('') + '</select></td>';

      case 'stock':
        if(!p.manage_stock){
          return '<td style="text-align:right"><span class="pill ' + (p.stock_status === 'outofstock' ? 'red' : 'green') + '">' +
            sesc(cpStockLabel(p.stock_status)) + '</span></td>';
        }
        return '<td style="text-align:right"><span class="cplcell cplnum" data-cpfield="stock" data-cpid="' + p.id + '" ' +
          'data-cpvalue="' + p.stock + '" title="Click to edit">' +
          (p.stock === 0 ? '<span class="pill red"><span class="d"></span>0</span>'
            : (p.low_stock ? '<span class="pill amber"><span class="d"></span>' + p.stock + '</span>' : p.stock)) +
          '</span></td>';

      case 'price':
        return '<td style="text-align:right"><span class="cplcell cplnum" data-cpfield="price" data-cpid="' + p.id + '" ' +
          'data-cpvalue="' + sesc(p.price_input) + '" title="Click to edit">' +
          (p.price_fils === null ? '<span style="color:var(--ink-faint)">—</span>' : sesc(p.price_display)) +
          '</span></td>';

      case 'sale':
        return '<td style="text-align:right"><span class="cplcell cplnum" data-cpfield="sale_price" data-cpid="' + p.id + '" ' +
          'data-cpvalue="' + sesc(p.sale_price_input) + '" title="Click to edit">' +
          (p.sale_price_fils === null ? '<span style="color:var(--ink-faint)">—</span>' : sesc(p.sale_price_display)) +
          '</span></td>';

      case 'categories':
        return '<td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
          (p.categories && p.categories.length ? sesc(p.categories.join(', ')) : '<span style="color:var(--ink-faint)">—</span>') + '</td>';

      case 'featured':
        return '<td style="font-size:15px;text-align:center;color:' + (p.featured ? '#e0a11e' : 'var(--border)') +
          ';cursor:pointer" data-cpfeat="' + p.id + '" title="Featured">' + (p.featured ? '★' : '☆') + '</td>';

      case 'orders':
        return '<td style="text-align:right;font-size:11.5px;color:var(--ink-soft)" title="' + sesc(p.units_sold + ' units · ' + p.revenue_display) + '">' +
          p.orders_count + '</td>';

      case 'date':
        return '<td style="font-size:11.5px;color:var(--ink-soft);white-space:nowrap">' + cpDash(p.date) + '</td>';

      case 'wc':
        return '<td style="font-size:11.5px;color:var(--ink-soft)">' + cpDash(p.wc_id) + '</td>';

      default:
        return '<td></td>';
    }
  }

  /* ---------------------------------------------------------------- binding */

  function cpBind(){
    var $$$ = function(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); };
    var byId = function(id){ return document.getElementById(id); };

    var search = byId('cplSearch');
    if(search){
      var searchT;
      search.oninput = function(e){
        clearTimeout(searchT);
        var v = e.target.value;
        searchT = setTimeout(function(){ CP.search = v; CP.page = 1; cpLoad(); }, 300);
      };
    }

    var sort = byId('cplSort');
    if(sort) sort.onchange = function(e){ CP.sort = e.target.value; CP.page = 1; cpLoad(); };

    $$$('#catBody [data-cpsort]').forEach(function(th){
      th.onclick = function(){ CP.sort = th.dataset.cpsort; CP.page = 1; cpLoad(); };
    });

    var advBtn = byId('cplAdvBtn');
    if(advBtn) advBtn.onclick = function(){ CP.adv = !CP.adv; cpPaint(); };

    var colsBtn = byId('cplColsBtn');
    if(colsBtn) colsBtn.onclick = function(){ CP.colsOpen = !CP.colsOpen; cpPaint(); };

    $$$('#catBody .cbx[data-cpcol]').forEach(function(b){
      b.onclick = function(){ var k = b.dataset.cpcol; CP.cols[k] = !CP.cols[k]; cpSaveCols(); cpPaint(); };
    });
    var colsReset = byId('cplColsReset');
    if(colsReset) colsReset.onclick = function(){ CP.cols = Object.assign({}, CP_COLS_DEFAULT); cpSaveCols(); cpPaint(); };

    var apply = byId('cplApply');
    if(apply) apply.onclick = function(){
      CP.brandId = (byId('cplBrand') || {}).value || '';
      CP.categoryId = (byId('cplCategory') || {}).value || '';
      CP.priceMin = (byId('cplMin') || {}).value || '';
      CP.priceMax = (byId('cplMax') || {}).value || '';
      CP.page = 1; cpLoad();
    };

    var clearAll = function(){
      CP.brandId = ''; CP.categoryId = ''; CP.priceMin = ''; CP.priceMax = '';
      CP.search = ''; CP.filter = 'all'; CP.page = 1; cpLoad();
    };
    var clearBtn = byId('cplClearFilters'); if(clearBtn) clearBtn.onclick = clearAll;
    var emptyClear = byId('cplEmptyClear'); if(emptyClear) emptyClear.onclick = clearAll;

    $$$('#catBody .chip[data-cpf]').forEach(function(c){
      c.onclick = function(){ CP.filter = c.dataset.cpf; CP.page = 1; cpLoad(); };
    });

    var perPage = byId('cplPerPage');
    if(perPage) perPage.onchange = function(e){
      CP.perPage = +e.target.value;
      try{ localStorage.setItem('kbb_cp_pp', CP.perPage); }catch(err){}
      CP.page = 1; cpLoad();
    };

    var prev = byId('cplPrev'); if(prev) prev.onclick = function(){ if(CP.data.page > 1){ CP.page = CP.data.page - 1; cpLoad(); } };
    var next = byId('cplNext'); if(next) next.onclick = function(){ if(CP.data.page < CP.data.last_page){ CP.page = CP.data.page + 1; cpLoad(); } };

    $$$('#catBody [data-cpsel]').forEach(function(b){
      b.onclick = function(){ var id = b.dataset.cpsel; CP.sel[id] = !CP.sel[id]; cpPaint(); };
    });

    var all = byId('cplAll');
    if(all) all.onclick = function(){
      var on = !CP.data.products.every(function(p){ return CP.sel[p.id]; });
      CP.data.products.forEach(function(p){ CP.sel[p.id] = on; });
      cpPaint();
    };

    var clearSel = byId('cplClearSel'); if(clearSel) clearSel.onclick = function(){ CP.sel = {}; cpPaint(); };

    /* ---- inline editing: the four cells an owner changes all day ---- */

    $$$('#catBody .cplcell[data-cpfield]').forEach(function(cell){
      cell.onclick = function(){ cpOpenCell(cell); };
    });

    $$$('#catBody select[data-cpfield]').forEach(function(sel){
      sel.onchange = function(){
        cpSave(+sel.dataset.cpid, sel.dataset.cpfield, sel.value);
      };
    });

    $$$('#catBody [data-cpfeat]').forEach(function(el){
      el.onclick = async function(){
        var id = +el.dataset.cpfeat;
        try{
          var out = await cpWrite('/admin-api/catalog-products-save/' + id, {featured: !cpRowById(id).featured});
          cpReplaceRow(out.product);
        }catch(e){ cpToast(e.message); }
      };
    });

    /* LANE AT. Edit opens the full product editor (window.peoEdit, defined in
       resources/views/admin/partials/product-editor-screen.blade.php), which is
       now the ONLY product editor in the console. What used to be here was
       Lane AF's own two-column detail panel: it wrote thirteen of the editor's
       fields through /catalog-products-save/{id} and none of the gallery, rich
       copy, SEO or scheduling. It has been removed; keeping two editors is how
       the Edit button came to point at the older one while the newer screen
       looked as though it had never shipped.

       The inline cells on this list are NOT that duplication and stay exactly
       as they are: editing a price or a stock number without leaving the list
       is a real workflow, and it goes through the same endpoint it always did. */
    $$$('#catBody [data-cpedit]').forEach(function(b){
      /* Straight into the full product editor, and there is no longer a second
         panel to fall back to: Lane AT retired cpOpenDetail, so this button and
         the editor's own picker are the only ways into a product.

         No retry here. peoEdit() parks the id and lets the screen's start()
         load it after the bootstrap has arrived, which is what makes a cold
         open land on the product instead of the picker. */
      b.onclick = function(){
        var id = +b.dataset.cpedit;

        if(typeof window.peoEdit === 'function') { window.peoEdit(id); return; }

        cpToast('The product editor could not be loaded — reload the page.');
      };
    });

    var bulkStatus = byId('cplBulkStatus');
    if(bulkStatus) bulkStatus.onchange = function(e){
      var status = e.target.value;
      e.target.value = '';
      if(status) cpConfirmStatus(cpSelectedIds(), status);
    };

    var bulkCatGo = byId('cplBulkCatGo');
    if(bulkCatGo) bulkCatGo.onclick = function(){
      var mode = (byId('cplBulkCatMode') || {}).value || 'add';
      var categoryId = (byId('cplBulkCat') || {}).value || '';
      if(!categoryId){ cpToast('Choose a category first.'); return; }
      cpConfirmCategory(cpSelectedIds(), mode, +categoryId);
    };

    var bulkPrice = byId('cplBulkPrice');
    if(bulkPrice) bulkPrice.onclick = function(){ cpPriceDialog(cpSelectedIds()); };

    var exportBtn = byId('cplExport');
    if(exportBtn) exportBtn.onclick = async function(){
      /* A normal navigation, not a fetch: the browser carries the same admin
         session cookie, the server refuses anyone without it, and the file
         lands in Downloads instead of in memory.

         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a
         navigation of the WHOLE CONSOLE, so a dead session took the screen
         away and lost the filters and ticks on it. kbbDownloadOk() asks the
         export itself first and says so on the console instead. */
      var qs = cpParams(true);
      var url = fixAdminApiUrl('/admin-api/catalog-products-export') + (qs ? '?' + qs : '');
      if(!(await kbbDownloadOk(url))) return;
      window.location.href = url;
    };
  }

  function cpSelectedIds(){
    return Object.keys(CP.sel).filter(function(k){ return CP.sel[k]; }).map(Number);
  }

  function cpRowById(id){
    return ((CP.data && CP.data.products) || []).filter(function(p){ return p.id === id; })[0] || {};
  }

  /* Swap one row's data in place and repaint. Cheaper than a reload after an
     inline edit, and it keeps the operator's scroll position — but the chip
     counts and the summary would then be stale, so anything that can move them
     (a status change) reloads instead. */
  function cpReplaceRow(row){
    if(!row || !CP.data) return;
    CP.data.products = CP.data.products.map(function(p){ return p.id === row.id ? row : p; });
    cpPaint();
  }

  /* Turn a cell into a text box. Enter or blur commits, Escape abandons. */
  function cpOpenCell(cell){
    if(cell.querySelector('input')) return;

    var field = cell.dataset.cpfield;
    var id = +cell.dataset.cpid;
    var value = cell.dataset.cpvalue || '';
    var previous = cell.innerHTML;

    cell.innerHTML = '<input class="cplin" type="text" inputmode="decimal" value="' + sesc(value) + '">';

    var input = cell.querySelector('input');
    input.focus();
    input.select();

    var done = false;

    var commit = function(){
      if(done) return;
      done = true;
      var next = input.value.trim();
      if(next === value){ cell.innerHTML = previous; return; }
      cpSave(id, field, next);
    };

    input.onkeydown = function(e){
      if(e.key === 'Enter'){ commit(); }
      if(e.key === 'Escape'){ done = true; cell.innerHTML = previous; }
    };
    input.onblur = commit;
  }

  /**
   * One field, one product, one request.
   *
   * The value goes up as the STRING the operator typed. Nothing here parses it
   * into a number: the server reads the digits and stores integer fils, and a
   * price that went through parseFloat on the way would already have lost the
   * exactness the fils representation exists to keep.
   */
  async function cpSave(id, field, value){
    var payload = {};

    if(field === 'stock'){
      payload.stock = value === '' ? null : parseInt(value, 10);
      /* Typing a stock number on a product that does not track stock is a
         request to start tracking it — otherwise the number is written and the
         shelf ignores it. */
      payload.manage_stock = true;
    }else if(field === 'price' || field === 'sale_price'){
      payload[field] = value === '' ? null : value;
    }else{
      payload[field] = value;
    }

    try{
      var out = await cpWrite('/admin-api/catalog-products-save/' + id, payload);
      cpToast('Saved');

      /* A status change moves the chip counts and the summary tiles, so the
         page is reloaded rather than patched — a screen whose chips disagree
         with its rows is worse than one that takes a moment. */
      if(field === 'status'){ cpLoad(); return; }

      cpReplaceRow(out.product);
    }catch(e){
      cpToast(e.message);
      cpPaint();
    }
  }

  /* -------- destructive actions: always a dialog, sometimes two -------- */

  /**
   * Nothing changes on a click. The first dialog says what will happen; the
   * server then refuses any product that is live on the storefront and reports
   * which ones, and only a second, explicit confirmation carrying force goes
   * through.
   */
  function cpConfirmStatus(ids, status){
    if(!ids.length) return;

    openModal('<div class="modal-h"><b>Change status</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Set <b>' + ids.length + '</b> product' + (ids.length === 1 ? '' : 's') +
      ' to <b>' + sesc(cpStatusLabel(status)) + '</b>?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">Anything that is currently live on the storefront is left alone unless you confirm it separately — taking a product out of Published removes it from the shop, from every category page and from the sitemap.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" id="cplStatusYes">Set status</button></div></div>');

    var yes = document.getElementById('cplStatusYes');
    if(yes) yes.onclick = function(){ cpRunStatus(ids, status, false); };
  }

  async function cpRunStatus(ids, status, force){
    closeModal();
    try{
      var out = await cpWrite('/admin-api/catalog-products-bulk-status', {ids: ids, status: status, force: !!force});

      if(out.skipped && out.skipped.length){ cpConfirmSkipped(out, status); return; }

      cpToast(out.changed + ' product' + (out.changed === 1 ? '' : 's') + ' updated');
      CP.sel = {}; cpLoad();
    }catch(e){ cpToast(e.message); }
  }

  /**
   * The second dialog. The server has already done the safe half and is telling
   * the operator exactly which products it refused and why, by name.
   */
  function cpConfirmSkipped(out, status){
    openModal('<div class="modal-h"><b>Some of these are live</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)"><b>' + out.changed + '</b> updated. <b>' +
      out.skipped.length + '</b> left alone because ' + (out.skipped.length === 1 ? 'it is' : 'they are') + ' published:</p>' +
      '<ul style="font-size:12.5px;color:var(--ink-2);margin:8px 0 0 18px">' +
      out.skipped.slice(0, 12).map(function(s){
        return '<li>' + sesc(s.label) + (s.sku ? ' — ' + sesc(s.sku) : '') + '</li>';
      }).join('') +
      (out.skipped.length > 12 ? '<li>and ' + (out.skipped.length - 12) + ' more</li>' : '') + '</ul>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:10px">Going ahead takes them off the storefront immediately.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Leave them</button>' +
      '<button class="btn" style="background:var(--red)" id="cplForce">Change those too</button></div></div>');

    var force = document.getElementById('cplForce');
    if(force) force.onclick = function(){
      cpRunStatus(out.skipped.map(function(s){ return s.id; }), status, true);
    };
  }

  /**
   * Add and Remove are additive and reversible, so they go straight through.
   * Replace drops every category a product is already in — on an imported
   * catalogue that is its whole WooCommerce taxonomy — so it asks first, and
   * the server refuses it without the confirmation regardless of what this
   * screen sends.
   */
  function cpConfirmCategory(ids, mode, categoryId){
    if(!ids.length) return;

    var cats = (CP.facets && CP.facets.categories) || [];
    var name = (cats.filter(function(c){ return c.id === categoryId; })[0] || {}).name || 'that category';

    if(mode !== 'replace'){ cpRunCategory(ids, mode, categoryId, false); return; }

    openModal('<div class="modal-h"><b>Replace categories</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Put <b>' + ids.length + '</b> product' + (ids.length === 1 ? '' : 's') +
      ' in <b>' + sesc(name) + '</b> and <b>remove every other category</b> they are in?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">On imported products that is the whole WooCommerce taxonomy for each one, and there is no undo. Add to category does the safe version of this.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" style="background:var(--red)" id="cplCatYes">Replace categories</button></div></div>');

    var yes = document.getElementById('cplCatYes');
    if(yes) yes.onclick = function(){ cpRunCategory(ids, mode, categoryId, true); };
  }

  async function cpRunCategory(ids, mode, categoryId, confirm){
    closeModal();
    try{
      var out = await cpWrite('/admin-api/catalog-products-bulk-category', {
        ids: ids, mode: mode, category_ids: [categoryId], confirm: !!confirm
      });

      cpToast(out.changed + ' product' + (out.changed === 1 ? '' : 's') + ' updated');
      CP.sel = {}; cpLoad();
    }catch(e){ cpToast(e.message); }
  }

  /**
   * The price dialog. Two steps, always: this form, then a confirmation, and
   * the server refuses the request outright without `confirm` however it is
   * called. A bulk price change cannot be undone.
   *
   * The percentage and the amount are sent as TEXT. The server carries the
   * percentage as integer basis points and does the arithmetic on integers —
   * `1 - 30 / 100` is 0.69999999999999995559 and lands a fil light on every
   * product, which is exactly the defect found in bundle pricing.
   */
  function cpPriceDialog(ids){
    if(!ids.length) return;

    openModal('<div class="modal-h"><b>Adjust prices</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b">' +
      '<p style="font-size:13px;color:var(--ink-2);margin-bottom:12px">' + ids.length + ' product' + (ids.length === 1 ? '' : 's') + ' selected.</p>' +
      '<div class="cplfield"><label>Which price</label><select class="inp" id="cplPriceTarget">' +
        '<option value="price">Regular price</option><option value="sale_price">Sale price</option></select></div>' +
      '<div class="cplfield"><label>Change</label><select class="inp" id="cplPriceMode">' +
        '<option value="percent">By a percentage</option>' +
        '<option value="amount">By an amount</option>' +
        '<option value="set">Set to exactly</option>' +
        '<option value="clear">Clear it</option></select></div>' +
      '<div class="cplfield" id="cplPriceValueWrap"><label id="cplPriceValueLabel">Percentage (negative to discount)</label>' +
        '<input class="inp" id="cplPriceValue" inputmode="decimal" placeholder="-10"></div>' +
      '<p style="font-size:12px;color:var(--ink-soft)">Products with no price to adjust, and anything that would end below zero or leave a sale price at or above its regular price, are skipped and listed back to you.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" id="cplPriceGo">Review change</button></div></div>');

    var mode = document.getElementById('cplPriceMode');
    var label = document.getElementById('cplPriceValueLabel');
    var wrap = document.getElementById('cplPriceValueWrap');

    if(mode) mode.onchange = function(){
      if(mode.value === 'clear'){ wrap.style.display = 'none'; return; }
      wrap.style.display = '';
      label.textContent = mode.value === 'percent'
        ? 'Percentage (negative to discount)'
        : (mode.value === 'amount' ? 'Amount to add (negative to subtract)' : 'New price');
    };

    var go = document.getElementById('cplPriceGo');
    if(go) go.onclick = function(){
      var target = (document.getElementById('cplPriceTarget') || {}).value || 'price';
      var m = (document.getElementById('cplPriceMode') || {}).value || 'percent';
      var value = ((document.getElementById('cplPriceValue') || {}).value || '').trim();

      if(m !== 'clear' && value === ''){ cpToast('Enter a value first.'); return; }

      cpConfirmPrice(ids, target, m, value);
    };
  }

  function cpConfirmPrice(ids, target, mode, value){
    var what = target === 'price' ? 'regular price' : 'sale price';
    var how = mode === 'percent' ? ('by ' + value + '%')
      : (mode === 'amount' ? ('by ' + value) : (mode === 'set' ? ('to ' + value) : 'removed'));

    openModal('<div class="modal-h"><b>Confirm price change</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Change the <b>' + sesc(what) + '</b> of <b>' +
      ids.length + '</b> product' + (ids.length === 1 ? '' : 's') + ' <b>' + sesc(how) + '</b>?</p>' +
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">This cannot be undone. Export the current view first if you want a record of what the prices were.</p>' +
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
      '<button class="btn ghost" onclick="closeModal()">Cancel</button>' +
      '<button class="btn" style="background:var(--red)" id="cplPriceYes">Change prices</button></div></div>');

    var yes = document.getElementById('cplPriceYes');
    if(yes) yes.onclick = async function(){
      closeModal();

      var payload = {ids: ids, target: target, mode: mode, confirm: true};
      if(mode === 'percent') payload.percent = value;
      if(mode === 'amount') payload.amount = value;
      if(mode === 'set') payload.value = value;

      try{
        var out = await cpWrite('/admin-api/catalog-products-bulk-price', payload);

        cpToast(out.changed + ' price' + (out.changed === 1 ? '' : 's') + ' changed' +
          (out.skipped && out.skipped.length ? ', ' + out.skipped.length + ' skipped' : ''));

        if(out.skipped && out.skipped.length) cpShowSkipped(out.skipped);

        CP.sel = {}; cpLoad();
      }catch(e){ cpToast(e.message); }
    };
  }

  function cpShowSkipped(skipped){
    openModal('<div class="modal-h"><b>Skipped</b><button class="x" onclick="closeModal()">✕</button></div>' +
      '<div class="modal-b"><ul style="font-size:12.5px;color:var(--ink-2);margin:0 0 0 18px">' +
      skipped.slice(0, 20).map(function(s){
        return '<li><b>' + sesc(s.label) + '</b> — ' + sesc(s.reason) + '</li>';
      }).join('') +
      (skipped.length > 20 ? '<li>and ' + (skipped.length - 20) + ' more</li>' : '') +
      '</ul><div class="row" style="justify-content:flex-end;margin-top:14px">' +
      '<button class="btn" onclick="closeModal()">Close</button></div></div>');
  }

  /* ===== LANE AF · Catalog · Products — END ================================= */

  /* ---------- Store → Payments (gateway credentials) ----------
     The real screen for /admin-api/payments (Admin\PaymentsApiController),
     replacing the kbb-admin-payments.html mock frame. Four gateways shipped
     with working webhooks and no way to enter a credential; this is that way.

     Everything is built from what the endpoint actually returns — id, title,
     enabled, mode, configured, fields[] (key/type/label/help/value/has_value)
     and webhook_url — so a gateway added to GatewayRegistry appears here with
     no edit to this file.

     No stored secret is ever rendered. The endpoint returns every `secret`
     field as an empty string with a `has_value` flag, so the box is drawn
     blank with "stored" beside it, and a blank box posts back as "leave the
     stored one alone". The one stored secret that reaches the page is the
     random tail of webhook_url — the owner cannot obtain it any other way and
     has to paste it into the provider, which is the whole point of showing
     it. It appears on this screen and nowhere else. */
  var PAYG=[];

  /* ---------- one tab per gateway ----------
     The list is PAYG, straight off the endpoint, which is GatewayRegistry
     ::all(). Nothing here names a gateway: add one to the registry and it gets
     a tab, a pane and a status light with no edit to this file.

     WHY THE PANES ARE HIDDEN AND NOT RE-RENDERED. Save on this screen is PER
     GATEWAY -- paySave() reads the live DOM for one id and POSTs it alone --
     so a tab switch that rebuilt the markup would silently discard whatever
     the operator had typed into the tab they were leaving. Every gateway's
     card is therefore rendered once and switching only flips `hidden`. The
     nodes are never destroyed, so an unsaved edit survives a tab switch by
     construction rather than by being copied somewhere and copied back. */
  var PAYTAB='';          /* active gateway id */
  var PAYBASE={};         /* per-gateway snapshot of the values as painted */
  var PAY_TAB_KEY='kbb.payments.tab';

  /* Deep link. The console addresses a screen as `#<id>` (and `?go=<id>`), and
     go(id,sub) already carries a sub-tab for Catalog, so a gateway tab is
     `#payments/<id>` -- the same address one level deeper.

     It is read rather than passed because the sub argument cannot survive the
     trip: manual-order, coupon-usage, category-tree and product-editor each
     wrap window.go as `function(id)`, so any second argument is dropped before
     it reaches this screen's dispatch. The hash is the one channel all four
     wrappers leave alone. */
  function payHashTab(){
    var m=/^payments\/([A-Za-z0-9_.-]{1,40})$/.exec(String(location.hash||'').replace(/^#/,''));
    return m?m[1]:'';
  }

  /* The console addresses a screen two ways, `#<id>` and `?go=<id>`, so the
     gateway gets both: `?go=payments&tab=stripe` is the form that survives
     being pasted somewhere that eats fragments. The shared boot already routes
     `?go=payments`, so this half needs no hook of its own. */
  function payQueryTab(){
    try{
      var q=new URLSearchParams(location.search);
      return q.get('go')==='payments' ? (q.get('tab')||'') : '';
    }catch(e){ return ''; }
  }

  function payLinkTab(){ return payHashTab()||payQueryTab(); }

  /* Does the address name this screen at all, with or without a gateway?
     `#payments`, `#payments/stripe` and `?go=payments` all count. */
  function payAddressed(){
    var h=String(location.hash||'').replace(/^#/,'');
    if(h==='payments'||/^payments\//.test(h)) return true;
    try{ return new URLSearchParams(location.search).get('go')==='payments'; }catch(e){ return false; }
  }

  function payStoredTab(){
    try{ return localStorage.getItem(PAY_TAB_KEY)||''; }catch(e){ return ''; }
  }

  function payKnown(id){
    for(var i=0;i<PAYG.length;i++){ if(PAYG[i].id===id) return true; }
    return false;
  }

  /* Never trusted as a string, only matched against ids the endpoint returned,
     so a hand-typed hash can select a tab and nothing else. */
  function payResolveTab(){
    var want=payLinkTab();
    if(payKnown(want)) return want;
    want=payStoredTab();
    if(payKnown(want)) return want;
    return PAYG.length?PAYG[0].id:'';
  }

  function payRememberTab(id){
    try{ localStorage.setItem(PAY_TAB_KEY,id); }catch(e){}
    /* replaceState, not location.hash: assigning the hash pushes a history
       entry per tab click and would make Back walk the tab bar instead of
       leaving the screen. It fires no hashchange either, so nothing re-routes
       underneath us. */
    try{ history.replaceState(null,'',location.pathname+location.search+'#payments/'+id); }catch(e){}
  }

  /* The URL secret is generated on first save, never typed. Drawing it as an
     empty password box would only invite someone to overwrite it; it is shown
     as part of the webhook URL instead, with a Regenerate button. */
  var PAY_HIDDEN_FIELDS={webhook_secret:1};

  /* Only Tamara switches API host on this flag (GatewayCredentials::live()).
     Saying so beats a switch that looks like it does more than it does. */
  var PAY_MODE_HELP={
    tamara:'Sandbox talks to Tamara’s sandbox API, live to the production one. This switch is the only thing that decides which.',
    stripe:'Picks which key set the shop uses: Sandbox / test uses the Test keys, Live uses the Live keys. Switching back and forth keeps both sets.',
    tabby:'A label for your own records. Tabby itself decides test or live from the keys you paste.'
  };

  function payStatus(g){
    if(!g.configured) return ['amber','Not configured'];
    return g.enabled ? ['green','Offered at checkout'] : ['grey','Switched off'];
  }

  function payStatusLine(g){
    if(!g.configured) return 'Not set up. Its credentials are missing, so it reports itself unavailable and shoppers never see it — checkout does not fail, the option simply is not there. Fill in the fields below and save.';
    if(!g.enabled) return 'Set up, but switched off. Turn it on to offer it at checkout.';
    return 'Set up and switched on. Shoppers see this option at checkout.';
  }

  /* A gateway with no credential fields has no mode select either (COD), so it
     has no live/sandbox state to report and must not be labelled as if it did. */
  function payIsLive(g){ return g.mode==='live' && g.fields.length>0; }

  /* ---------- unsaved-change detection ----------
     Compared against the values as painted rather than tracked with a flag: a
     flag set on the first keystroke stays set after the operator types a value
     and then types it back, and this indicator is the only thing telling them
     a collapsed tab is holding an edit. */
  function paySnapshot(id){
    var tog=document.querySelector('[data-payen="'+id+'"]');
    var t=document.getElementById('pay_title_'+id);
    var m=document.getElementById('pay_mode_'+id);
    var snap={
      enabled:tog?tog.classList.contains('on'):false,
      title:t?t.value:'',
      mode:m?m.value:'',
      fields:{}
    };
    document.querySelectorAll('[data-payg="'+id+'"]').forEach(function(el){
      snap.fields[el.dataset.payf]=el.value;
    });
    return snap;
  }

  function paySameSnap(a,b){
    if(!a||!b) return true;
    if(a.enabled!==b.enabled||a.title!==b.title||a.mode!==b.mode) return false;
    var ka=Object.keys(a.fields);
    if(ka.length!==Object.keys(b.fields).length) return false;
    for(var i=0;i<ka.length;i++){ if(a.fields[ka[i]]!==b.fields[ka[i]]) return false; }
    return true;
  }

  function payIsDirty(id){ return !paySameSnap(PAYBASE[id], paySnapshot(id)); }

  /* Every gateway currently holding an edit, so a repaint can put them back. */
  function payCapturePending(){
    var out={};
    PAYG.forEach(function(g){ if(payIsDirty(g.id)) out[g.id]=paySnapshot(g.id); });
    return out;
  }

  function payRestorePending(pending){
    Object.keys(pending||{}).forEach(function(id){
      var s=pending[id];
      var tog=document.querySelector('[data-payen="'+id+'"]');
      if(tog){
        tog.classList.toggle('on',!!s.enabled);
        tog.setAttribute('aria-checked',s.enabled?'true':'false');
      }
      var t=document.getElementById('pay_title_'+id); if(t) t.value=s.title;
      var m=document.getElementById('pay_mode_'+id); if(m&&s.mode) m.value=s.mode;
      document.querySelectorAll('[data-payg="'+id+'"]').forEach(function(el){
        if(Object.prototype.hasOwnProperty.call(s.fields,el.dataset.payf)){
          el.value=s.fields[el.dataset.payf];
        }
      });
      payMsg(id,'Unsaved change');
    });
    payRefreshTabs();
  }

  /* ---------- the tab bar ----------
     Each tab carries the state the card would have shown, because a tab that
     hides a gateway whose state you need is worse than the long page it
     replaced: the status dot (the same payStatus() the card header uses), a
     LIVE chip when this gateway is pointed at a production API, and an amber
     bullet while it holds an unsaved edit. */
  function payTabLabel(g){
    var bits=[g.title, payStatus(g)[1]];
    if(payIsLive(g)) bits.push('live mode');
    return bits.join(' — ');
  }

  function payTabBar(){
    return '<div class="ectabs paytabs" role="tablist" aria-label="Payment gateways">'+
      PAYG.map(function(g){
        var on=g.id===PAYTAB;
        return '<button type="button" class="ectab paytab'+(on?' on':'')+'"'+
          ' id="pay_tab_'+sesc(g.id)+'" data-paytab="'+sesc(g.id)+'"'+
          ' role="tab" aria-controls="pay_pane_'+sesc(g.id)+'"'+
          ' aria-selected="'+(on?'true':'false')+'" tabindex="'+(on?'0':'-1')+'"'+
          ' aria-label="'+sesc(payTabLabel(g))+'" title="'+sesc(payTabLabel(g))+'">'+
          '<span class="paydot '+sesc(payStatus(g)[0])+'" aria-hidden="true"></span>'+
          '<span class="paytab-t">'+sesc(g.title)+'</span>'+
          (payIsLive(g)?'<span class="paylive">LIVE</span>':'')+
          '<span class="paydirty" id="pay_tabdirty_'+sesc(g.id)+'" aria-hidden="true" hidden>&bull;</span>'+
          '</button>';
      }).join('')+'</div>';
  }

  /* Repaints only the tab bar's own indicators — never the panes, which hold
     the operator's typing. */
  function payRefreshTabs(){
    PAYG.forEach(function(g){
      var d=document.getElementById('pay_tabdirty_'+g.id);
      if(!d) return;
      var dirty=payIsDirty(g.id);
      if(dirty) d.removeAttribute('hidden'); else d.setAttribute('hidden','');
      var tab=document.getElementById('pay_tab_'+g.id);
      if(tab){
        var label=payTabLabel(g)+(dirty?' — unsaved changes':'');
        tab.setAttribute('aria-label',label);
        tab.setAttribute('title',label);
      }
    });
  }

  function payShowTab(id,remember){
    if(!payKnown(id)) return;
    PAYTAB=id;
    PAYG.forEach(function(g){
      var pane=document.getElementById('pay_pane_'+g.id);
      if(pane){
        if(g.id===id) pane.removeAttribute('hidden'); else pane.setAttribute('hidden','');
      }
      var tab=document.getElementById('pay_tab_'+g.id);
      if(tab){
        tab.classList.toggle('on',g.id===id);
        tab.setAttribute('aria-selected',g.id===id?'true':'false');
        tab.setAttribute('tabindex',g.id===id?'0':'-1');
      }
    });
    if(remember!==false) payRememberTab(id);
    payRefreshTabs();
  }

  /* A GREEN TICK ON A FIELD THAT IS FILLED IN, reported by the owner:
     "each keys fields etc should have green tick icon when key submitted and
     accepted. for now there's nothing and very confusing."

     He is right about the confusion. A secret field carried a `stored` pill and
     a plain field carried nothing at all, so a screen of boxes gave no answer
     to the only question being asked while filling it in — which of these have
     I done. The tick answers it at a glance, per field, and the pill stays for
     the state the tick cannot express ("not set").

     "Accepted" is the honest word and the tick means exactly it: the server
     took the value and stored it. It is not a claim that Stripe likes the key —
     that is what Connected, and the account name beside it, report. */
  function payTick(){
    return '<span class="paytick" title="Saved" aria-label="Saved">'+
      '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" '+
      'stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'+
      '</span>';
  }

  function payField(gid,f){
    var id='pay_'+gid+'_'+f.key;
    /* A secret is `has_value` because the server never sends one back; a plain
       field is judged on the value it was given. Same question, two sources.

       A `bool` gets NO TICK, and that is not an oversight. The tick means "the
       server took this value and stored it", and a switch left Off is stored
       exactly as surely as one turned On -- so a tick that appeared only in the
       On position would be read as "this is on", which is the one thing the
       select next to it already says. A tick that means two different things on
       two field types is worse than no tick. */
    var filled = f.type==='bool' ? false
      : (f.type==='secret' ? !!f.has_value : String(f.value||'').trim()!=='');

    var head='<div class="ecl"><label for="'+sesc(id)+'">'+sesc(f.label)+'</label>'+
      (filled ? payTick() : '')+
      (f.type==='secret'
        ? (f.has_value?'<span class="pill green">stored</span>':'<span class="pill amber">not set</span>')
        : '')+'</div>'+
      (f.help?'<div class="echelp">'+sesc(f.help)+'</div>':'');

    if(f.type==='secret'){
      return '<div class="ecopt wide"><div class="ecom">'+head+
        '<div class="echelp">'+(f.has_value
          ? 'Already stored. Leave this blank to keep it — type a new value only to replace it. The stored value is never sent back to this page.'
          : 'Nothing stored yet.')+'</div></div>'+
        '<div class="ecctl"><input type="password" class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'"'+
        ' autocomplete="new-password" spellcheck="false" value="" placeholder="'+
        (f.has_value?'••••••••  unchanged':'paste the key here')+'"></div></div>';
    }

    /* A SETTING, NOT A CREDENTIAL. `bool` fields are stored in the same config
       blob as the keys and travel the same way, but there is nothing to paste
       into them: the value is '1' or empty. Drawn as a select rather than a
       checkbox deliberately -- everything on this screen is read and written
       through el.value (paySnapshot, payRestorePending, paySave), and a
       checkbox's .value does not change with its checked state, so a checkbox
       would need three other functions taught about it and would save the wrong
       thing until they were. */
    if(f.type==='bool'){
      return '<div class="ecopt wide"><div class="ecom">'+head+'</div>'+
        '<div class="ecctl"><select class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'">'+
        '<option value=""'+(f.value==='1'?'':' selected')+'>Off</option>'+
        '<option value="1"'+(f.value==='1'?' selected':'')+'>On</option>'+
        '</select></div></div>';
    }

    return '<div class="ecopt wide"><div class="ecom">'+head+'</div>'+
      '<div class="ecctl"><input type="text" class="inp" id="'+sesc(id)+'" data-payg="'+sesc(gid)+'" data-payf="'+sesc(f.key)+'"'+
      ' spellcheck="false" value="'+sesc(f.value)+'"></div></div>';
  }

  /* The URL the owner has to paste into the provider dashboard. It cannot be
     guessed or assembled by hand, so it is shown in full with a copy button. */
  function payWebhook(g){
    var hasHook=false;
    for(var i=0;i<g.fields.length;i++){ if(g.fields[i].key==='webhook_secret') hasHook=true; }
    if(!hasHook) return '';

    if(!g.webhook_url){
      return '<div class="ecopt wide"><div class="ecom"><div class="ecl"><label>Webhook URL</label>'+
        '<span class="pill amber">not generated yet</span></div>'+
        '<div class="echelp">Save this gateway once and its webhook URL appears here. Until it is generated and pasted into the provider dashboard, '+sesc(g.title)+' cannot tell this store that a payment succeeded.</div>'+
        '</div></div>';
    }

    return '<div class="ecopt wide"><div class="ecom"><div class="ecl"><label>Webhook URL</label>'+
      '<span class="pill green">ready to paste</span></div>'+
      '<div class="echelp">Paste this into the provider dashboard as the endpoint for payment events. The random tail is this gateway’s own URL secret, which is what makes the endpoint unguessable — treat the whole URL as confidential and keep it off any public page.</div></div>'+
      '<div class="ecctl" style="display:block;width:100%">'+
      '<input type="text" class="inp" readonly id="pay_hook_'+sesc(g.id)+'" data-payhook="'+sesc(g.id)+'" value="'+sesc(g.webhook_url)+'" style="max-width:none">'+
      '<div class="row" style="gap:8px;margin-top:8px">'+
      '<button type="button" class="btn ghost sm" data-paycopy="'+sesc(g.id)+'">Copy URL</button>'+
      '<button type="button" class="btn ghost sm" data-payregen="'+sesc(g.id)+'">Regenerate</button>'+
      '<span class="echelp" id="pay_copied_'+sesc(g.id)+'" style="margin:0"></span></div></div></div>';
  }

  /* ---------------------------------------------------------------------------
     THE TWO COLUMNS, AND WHY THE SCREEN DOES NOT DECIDE WHICH IS WHICH
     ---------------------------------------------------------------------------
     The owner: "on tabby and tamara setting page, i want two columns, on left
     all keys fields and on right setting things. also make the sections
     prominent and don't give me onwards any classic throw away looks."

     He is describing a real fault and not a preference. Every row was an
     `.ecopt.wide`, which is `display:block` with a 520px control under a
     full-width label — so on a 1670px card the right two thirds of every single
     row was empty, and Tamara's twelve fields ran down one column for about
     1900px with the keys interleaved with the basket limits. The screenshot he
     sent has an arrow drawn up that empty column.

     WHICH SIDE A FIELD GOES TO IS THE GATEWAY'S ANSWER, NOT THIS SCREEN'S.
     `f.group` is the fourth element of the gateway's own configSchema, carried
     through PaymentsApiController. This file may not branch on a gateway's id
     at all: PaymentsGatewayTabsTest forbids naming one here, because a console
     that hardcodes the list stops following the registry the moment a gateway
     is added or renamed.

     That guard caught this very comment. The sentence above used to quote an id
     as an example of what not to write, the test scans the rendered source
     INCLUDING comments, and it went red on the explanation rather than on the
     code — the same shape CLAUDE.md records for a commented-out nav entry still
     counting. An example that trips the rule it is explaining is not an
     example.

     MODE SITS WITH THE KEYS, deliberately. It is the question "which set of
     keys are these", which is why every gateway's own help text for it talks
     about the keys ("Tabby itself decides test or live from the keys you
     paste"). Putting it on the settings side would separate it from the only
     thing it describes.
  --------------------------------------------------------------------------- */

  /* A section head that is a section head: a numbered chip, a title, and one
     line saying what the column is for. Not an uppercase word over a rule. */
  function paySection(n,title,sub,body,extra){
    return '<section class="paysec'+(extra?' '+extra:'')+'">'+
      '<header class="paysech"><span class="paysecn">'+sesc(String(n))+'</span>'+
      '<div class="paysect"><b>'+sesc(title)+'</b><span>'+sesc(sub)+'</span></div></header>'+
      '<div class="paysecb">'+body+'</div></section>';
  }

  function payModeRow(g){
    return '<div class="ecopt wide"><div class="ecom"><div class="ecl"><label for="pay_mode_'+sesc(g.id)+'">Mode</label></div>'+
      '<div class="echelp">'+sesc(PAY_MODE_HELP[g.id]||'Sandbox or live.')+'</div></div>'+
      '<div class="ecctl"><select class="inp" id="pay_mode_'+sesc(g.id)+'" data-paymode="'+sesc(g.id)+'">'+
      '<option value="test"'+(g.mode==='live'?'':' selected')+'>Sandbox / test</option>'+
      '<option value="live"'+(g.mode==='live'?' selected':'')+'>Live</option></select></div></div>';
  }

  function payEnabledRow(g){
    return '<div class="ecopt istog"><div class="ecom"><div class="ecl"><label>Offer this at checkout</label></div>'+
      '<div class="echelp">A gateway that is on but not configured stays hidden rather than failing at the till.</div></div>'+
      '<div class="ecctl"><span class="ectog'+(g.enabled?' on':'')+'" data-payen="'+sesc(g.id)+'" role="switch" aria-checked="'+(g.enabled?'true':'false')+'" tabindex="0"></span></div></div>';
  }

  function payTitleRow(g){
    return '<div class="ecopt wide"><div class="ecom"><div class="ecl"><label for="pay_title_'+sesc(g.id)+'">Label shown to shoppers</label></div>'+
      '<div class="echelp">The wording on the checkout radio list.</div></div>'+
      '<div class="ecctl"><input type="text" class="inp" id="pay_title_'+sesc(g.id)+'" data-paytitle="'+sesc(g.id)+'" maxlength="120" value="'+sesc(g.title)+'"></div></div>';
  }

  /* How many of a gateway's key fields are filled in. Printed on the section
     head so the answer to "how far through this am I" is visible without
     reading every box — which is the question the whole screen is for. */
  function payFilled(fields){
    var n=0;
    for(var i=0;i<fields.length;i++){
      var f=fields[i];
      if(f.type==='bool') continue;
      if(f.type==='secret' ? !!f.has_value : String(f.value||'').trim()!=='') n++;
    }
    return n;
  }

  function payCountable(fields){
    return fields.filter(function(f){ return f.type!=='bool'; }).length;
  }

  function payCard(g){
    var st=payStatus(g);
    var visible=g.fields.filter(function(f){ return !PAY_HIDDEN_FIELDS[f.key]; });
    var keys=visible.filter(function(f){ return f.group==='keys'; });
    var settings=visible.filter(function(f){ return f.group!=='keys'; });
    var hasCreds=g.fields.length>0;

    /* The count is over the KEY fields only. A gateway is "configured" when its
       credentials are stored; a capture window left at its default has nothing
       to do with it, so counting settings here would report progress the owner
       has not made. */
    var total=payCountable(keys);
    var done=payFilled(keys);

    var left=(hasCreds?payModeRow(g):'')+
      (keys.length
        ? keys.map(function(f){ return payField(g.id,f); }).join('')
        : '')+
      payWebhook(g)+
      (g.supports_connect ? '<div id="pay_conn_stripe" class="ecopt wide"><div class="ecom">'+
        '<div class="ecl"><label>Connect to Stripe</label></div>'+
        '<div class="echelp">Loading…</div></div></div>' : '');

    var right=payEnabledRow(g)+payTitleRow(g)+
      settings.map(function(f){ return payField(g.id,f); }).join('');

    /* A gateway with no credentials at all (cash on delivery) gets ONE column
       and says so, rather than an empty box headed "Keys" beside a full one.
       An empty panel reads as a screen that failed to load. */
    var body = hasCreds
      ? '<div class="paygrid">'+
          paySection(1,'Keys from '+g.title,
            total ? done+' of '+total+' filled in · paste these from the provider' : 'paste these from the provider',
            left,'is-keys')+
          paySection(2,'How this shop uses it','what shoppers see, and the rules this shop applies',right,'is-set')+
        '</div>'
      : paySection(1,'How this shop uses it','no account with anyone is needed, so there is nothing to paste in',
          right+'<div class="ecopt wide"><div class="ecom"><div class="ecl"><label>Credentials</label></div>'+
          '<div class="echelp">None — cash on delivery needs no account with anyone, so there is nothing to enter and it is ready as soon as it is switched on.</div></div></div>','is-solo');

    return '<div class="card mmcard" data-paycard="'+sesc(g.id)+'">'+
      '<div class="mmhd" style="display:flex;align-items:center;gap:10px">'+
      '<b style="flex:1">'+sesc(g.title)+'</b>'+
      '<span class="pill '+st[0]+'"><span class="d"></span>'+sesc(st[1])+'</span></div>'+
      '<div class="mmbody">'+
      '<p class="echelp paylede">'+sesc(payStatusLine(g))+'</p>'+
      body+
      '<div class="row payfoot">'+
      '<span class="echelp" id="pay_msg_'+sesc(g.id)+'" style="margin:0;margin-right:auto"></span>'+
      (g.supports_connect ? '<button type="button" class="btn ghost" data-paydisc="'+sesc(g.id)+'">Disconnect Stripe</button>' : '')+
      '<button type="button" class="btn ghost" data-paycheck="'+sesc(g.id)+'">Check this setup</button>'+
      '<button type="button" class="btn" data-paysave="'+sesc(g.id)+'">Save '+sesc(g.title)+'</button></div>'+
      '<div id="pay_pre_'+sesc(g.id)+'"></div>'+
      '</div></div>';
  }

  /* ---------------------------------------------------------------------------
     "Check this setup" — read-only, reaches no provider, writes nothing.

     It answers the question an owner actually has while pasting keys: is this
     going to work, and if not, which box have I not filled in. The endpoint
     redacts every credential BY VALUE wherever it appears, including inside an
     Authorization header, and it redacts VISIBLY ("«secret_key as stored»")
     rather than dropping the header — a header that vanished would read as
     "this gateway sends no credentials", which is the wrong lesson.
  --------------------------------------------------------------------------- */
  async function payPreflight(id){
    var host=document.querySelector('#pay_pre_'+id);
    if(!host) return;
    host.innerHTML='<div class="echelp" style="margin-top:10px">Checking…</div>';
    try{
      var r=await api('/admin-api/payments/preflight/'+encodeURIComponent(id));
      var miss=(r.missing_fields||[]).map(function(f){ return sesc(f.label||f.key); });
      var blocked=(r.blocked_by||[]).map(function(b){ return sesc(b); });
      var out='<div class="ecnote" style="margin-top:12px">';
      out+= blocked.length
        ? '<b>Not offered at checkout yet.</b><ul style="margin:6px 0 0 18px">'+blocked.map(function(b){ return '<li>'+b+'</li>'; }).join('')+'</ul>'
        : '<b>Ready — this method is being offered at checkout right now.</b>';
      if(miss.length) out+='<div style="margin-top:8px">Still to paste in: <b>'+miss.join('</b>, <b>')+'</b>.</div>';
      out+='<div style="margin-top:8px">Mode: <b>'+sesc(String(r.mode||'—'))+'</b>.</div>';
      out+= r.webhook_url
        ? '<div style="margin-top:8px">Paste this address into the provider\'s dashboard:<br><code style="word-break:break-all">'+sesc(String(r.webhook_url))+'</code></div>'
        : '<div style="margin-top:8px" class="echelp">The webhook address is generated when this tab is first saved.</div>';
      var dr=r.dry_run||{};
      out+= dr.ran
        ? '<div style="margin-top:10px">It would send <b>'+sesc(String(dr.method||''))+'</b> to<br><code style="word-break:break-all">'+sesc(String(dr.url||''))+'</code><div class="echelp" style="margin-top:4px">Nothing was actually sent, and no order, payment or event was created.</div></div>'
        : '<div style="margin-top:10px" class="echelp">'+sesc(String(dr.why||'Nothing to send yet.'))+'</div>';
      out+='</div>';
      host.innerHTML=out;
    }catch(e){
      host.innerHTML='<div class="echelp" style="margin-top:10px">The check could not be run'+
        ((e && e.body && e.body.message) ? ' — '+sesc(String(e.body.message)) : '')+'.</div>';
    }
  }

  function paintPayments(){
    var unconfigured=PAYG.filter(function(g){ return g.enabled && !g.configured; });
    var ready=PAYG.filter(function(g){ return g.enabled && g.configured; });

    /* Kept across a repaint so saving does not throw the operator back to the
       first gateway; only re-resolved when it names nothing that exists. */
    if(!payKnown(PAYTAB)) PAYTAB=payResolveTab();

    document.querySelector('#content').innerHTML=
      '<div class="wrap ecwrap mmwrap" data-payscreen="1">'+
      '<div class="page-head"><h2>Payments</h2>'+
      '<p>Credentials for each payment method, one tab per gateway. A gateway is offered at checkout only when it is switched on <i>and</i> its credentials are stored — an unconfigured one reports itself unavailable rather than failing on the shopper.</p></div>'+

      /* ABOVE the tab bar, deliberately. These two warnings are about gateways
         the operator is not currently looking at, so putting them inside a
         pane would hide the one thing tabs must not hide. */
      (ready.length===0
        ? '<div class="nlwarn">No payment method is both configured and switched on, so checkout currently has nothing to offer.</div>'
        : '')+
      (unconfigured.length
        ? '<div class="nlwarn">'+unconfigured.length+' gateway'+(unconfigured.length===1?' is':'s are')+
          ' switched on but missing credentials — '+sesc(unconfigured.map(function(g){ return g.title; }).join(', '))+
          '. '+(unconfigured.length===1?'It is':'They are')+' hidden at checkout until the fields are filled in. Their tabs are marked amber.</div>'
        : '')+

      payTabBar()+

      /* Every card, every time. Only `hidden` differs — see the note on PAYTAB
         above for why a tab switch must not re-render these. */
      PAYG.map(function(g){
        return '<div class="paypane" id="pay_pane_'+sesc(g.id)+'" role="tabpanel"'+
          ' aria-labelledby="pay_tab_'+sesc(g.id)+'"'+(g.id===PAYTAB?'':' hidden')+'>'+
          payCard(g)+'</div>';
      }).join('')+

      /* BELOW the gateway tabs and OUTSIDE them. Reconciliation is about every
         gateway at once, so it must not sit inside a pane that hides it
         whenever the operator happens to be looking at a different tab. */
      reconPanel()+
      '</div>';

    /* The baseline every dirty check is measured against: the values exactly as
       just painted, read back out of the DOM so the comparison is like for
       like (a secret box paints blank and its baseline is blank). */
    PAYBASE={};
    PAYG.forEach(function(g){ PAYBASE[g.id]=paySnapshot(g.id); });

    bindPayments();
    reconBind();
    payRefreshTabs();
  }

  /* ---------------------------------------------------------------------------
     Reconcile — the provider's books against ours.

     READ ONLY, and the button says so in words. It reports; it does not repair.
     An automatic repair that marked orders paid off a provider's list would be
     the same shape as the defect 2.60.199 closed, only acting a page at a time
     and off an unsigned list, against orders whose stock has gone back on the
     shelf. What the operator gets is an order number and a reference, and the
     capture and refund buttons that already exist on the order screen, pressed
     one order at a time.

     Many short requests, not one long one. This host kills a long request and
     has no queue worker, so the browser drives the run exactly as Store ->
     Import / Export already does. Each step commits its own checkpoint, so
     closing this tab halfway loses nothing and pressing the button again
     carries on from where it stopped.
  --------------------------------------------------------------------------- */
  var RECON={run:null,busy:false};

  function reconMoney(fils){ return fils==null ? '' : 'AED '+(Math.round(fils)/100).toFixed(2); }

  function reconDates(){
    var iso=function(d){ return d.toISOString().slice(0,10); };
    return [iso(new Date(Date.now()-13*86400000)), iso(new Date())];
  }

  function reconPanel(){
    var d=reconDates();

    return '<div class="ecopt wide"><div class="ecom">'+
      '<div class="ecl"><label>Check the books</label></div>'+
      '<div class="echelp">Compares what Stripe, Tabby and Tamara say they took against what this shop has '+
      'recorded: money taken that never reached us, money we think we hold that they cannot confirm, amounts '+
      'that disagree, and refunds on one side only. It only looks &mdash; nothing is marked paid, refunded or changed '+
      'by running it, so it is safe to press on a live shop at any time. Cash on delivery is not part of it '+
      '(there is no second set of books for cash) and its figures are shown separately underneath.</div>'+
      '<div class="row" style="gap:10px;align-items:flex-end;margin-top:12px;flex-wrap:wrap">'+
        '<label class="echelp" style="margin:0">From<br><input type="date" id="recon_from" value="'+d[0]+'"></label>'+
        '<label class="echelp" style="margin:0">To<br><input type="date" id="recon_to" value="'+d[1]+'"></label>'+
        '<button type="button" class="btn" id="recon_go">Check the books</button>'+
        '<button type="button" class="btn ghost" id="recon_fresh">Start over</button>'+
        '<span class="echelp" id="recon_msg" style="margin:0"></span>'+
      '</div>'+
      '<div id="recon_out" style="margin-top:14px"></div>'+
      '<div id="recon_cod" style="margin-top:14px"></div>'+
      '</div></div>';
  }

  function reconBind(){
    var go=document.getElementById('recon_go');
    var fresh=document.getElementById('recon_fresh');
    if(go) go.onclick=function(){ reconRun(false); };
    /* "Start over" throws this window's previous findings away and looks again.
       It is what the owner wants after he has FIXED something and needs a clean
       answer rather than yesterday's plus today's. */
    if(fresh) fresh.onclick=function(){ reconRun(true); };
  }

  function reconMsg(t){ var e=document.getElementById('recon_msg'); if(e) e.textContent=t||''; }

  async function reconRun(restart){
    if(RECON.busy) return;

    var from=(document.getElementById('recon_from')||{}).value;
    var to=(document.getElementById('recon_to')||{}).value;

    if(!from||!to){ reconMsg('Pick both dates first.'); return; }

    RECON.busy=true;
    reconMsg('Starting…');
    document.getElementById('recon_out').innerHTML='';
    document.getElementById('recon_cod').innerHTML='';

    try{
      var s=await api('/admin-api/payments/reconcile/start',{method:'POST',
        body:JSON.stringify({from:from,to:to,restart:!!restart})});
      RECON.run=s.run_id;
    }catch(e){
      RECON.busy=false;
      /* The endpoint refuses an unusable window with a sentence written for the
         owner ("that window is longer than 92 days..."). Printing it beats
         replacing it with "could not start", which sends him to look at his
         wifi over a date. */
      reconMsg((e&&e.body&&e.body.error) ? e.body.error : 'That run could not be started.');
      return;
    }

    /* The cap is a guard, not an expectation: the phases are finite and each
       one either advances or finishes. An unbounded loop against somebody
       else's rate-limited API is not a trade worth having either way. */
    for(var i=0;i<400;i++){
      var st;

      try{
        st=await api('/admin-api/payments/reconcile/step',{method:'POST',
          body:JSON.stringify({run_id:RECON.run})});
      }catch(e){
        reconMsg('The check stopped part way. Press "Check the books" again and it will carry on from where it got to.');
        break;
      }

      reconMsg('Checking… '+(st.phases_done||0)+' of '+(st.phases_total||0)+' steps done.');

      if(st.done){ reconMsg(''); break; }
    }

    RECON.busy=false;
    await reconReport();
    await reconCod(from,to);
  }

  async function reconReport(){
    var host=document.getElementById('recon_out');
    if(!host||!RECON.run) return;

    var r;
    try{ r=await api('/admin-api/payments/reconcile/'+RECON.run+'/findings'); }
    catch(e){ host.innerHTML='<div class="echelp">The report could not be loaded.</div>'; return; }

    if(!r.total){
      host.innerHTML='<div class="ecnote"><b>Both sides agree.</b> Nothing outstanding for these dates.</div>';
      return;
    }

    host.innerHTML='<div class="nlwarn"><b>'+r.total+' thing'+(r.total===1?'':'s')+' to look at.</b> '+
      'Nothing has been changed &mdash; each of these is for you to decide about.</div>'+
      r.findings.map(reconRow).join('');

    host.querySelectorAll('[data-reconack]').forEach(function(b){
      b.onclick=async function(){
        try{
          await api('/admin-api/payments/reconcile/'+RECON.run+'/findings/'+b.dataset.reconack+'/ack',
            {method:'POST',body:JSON.stringify({})});
          toast('Marked as dealt with');
          reconReport();
        }catch(e){
          /* The server refuses the ack on the two "could not be read" notices
             and says why. Acknowledging a discrepancy means "I looked, it is
             fine"; acknowledging "I could not look" does not make the looking
             happen, and it used to empty the outstanding count on a run that
             compared nothing with anything. The sentence names the way out —
             press Run over these dates again — so it must be shown rather than
             replaced with "Could not record that". */
          if(e && e.status === 422 && e.body && e.body.error === 'cannot_acknowledge'){
            alert(e.body.message);
            return;
          }
          toast('Could not record that', 'bad');
        }
      };
    });

    host.querySelectorAll('[data-reconorder]').forEach(function(a){
      a.onclick=function(ev){ ev.preventDefault(); renderOrderDetail(+a.dataset.reconorder); };
    });
  }

  function reconRow(f){
    var amounts=[];
    if(f.amount_local!=null) amounts.push(reconMoney(f.amount_local)+' here');
    if(f.amount_remote!=null) amounts.push(reconMoney(f.amount_remote)+' at '+sesc(f.provider));

    return '<div class="ecnote" style="margin-top:8px;border-left:4px solid var('+
      (f.severity==='alarm'?'--sale':'--ink-faint')+')">'+
      '<div>'+sesc(f.summary)+'</div>'+
      '<div class="echelp" style="margin-top:6px">'+
        (f.order_number && f.order_id
          ? 'Order <a href="#" data-reconorder="'+f.order_id+'"><b>'+sesc(f.order_number)+'</b></a>. '
          : (f.order_number ? 'Order <b>'+sesc(f.order_number)+'</b>. ' : ''))+
        (amounts.length ? amounts.join(' · ')+'. ' : '')+
        (f.remote_ref ? 'Their reference <code>'+sesc(f.remote_ref)+'</code>. ' : '')+
        (f.local_ref ? 'Ours <code>'+sesc(f.local_ref)+'</code>.' : '')+
      '</div>'+
      /* NO "I have dealt with this" ON A NOTICE THAT SAYS THE PROVIDER COULD
         NOT BE READ. There is nothing to have dealt with, because the run did
         not look. The server refuses the ack either way; not drawing the button
         is what stops the owner being offered a control whose only possible
         outcome is a refusal. In its place, the route that actually answers the
         window. */
      (f.kind === 'payments_source_unavailable' || f.kind === 'refunds_source_unavailable'
        ? '<div class="echelp" style="margin-top:6px">Press <b>Check the books</b> over these dates again once '+
          sesc(f.provider)+' is reachable — the checks this outage skipped are re-run for real and this '+
          'notice clears itself.</div>'
        : '<div class="row" style="justify-content:flex-end;margin-top:6px">'+
          '<button type="button" class="btn ghost" data-reconack="'+f.id+'">I have dealt with this</button>'+
          '</div>')+'</div>';
  }

  /* Its own block, under its own heading, and never merged into the list above.
     Cash on delivery has no second set of books, so these figures are one sided
     and the `note` says so in words the screen prints verbatim rather than
     summarises. */
  async function reconCod(from,to){
    var host=document.getElementById('recon_cod');
    if(!host) return;

    var c;
    try{
      c=await api('/admin-api/payments/reconcile/cod?from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to));
    }catch(e){ host.innerHTML=''; return; }

    host.innerHTML='<div class="ecnote"><b>Cash on delivery &mdash; a different report.</b>'+
      '<div style="margin-top:6px">'+
      'Marked collected: <b>'+reconMoney(c.collected.fils)+'</b> over '+c.collected.orders+' order(s).<br>'+
      'Still owed to us: <b>'+reconMoney(c.outstanding.fils)+'</b> over '+c.outstanding.orders+' order(s).<br>'+
      'Closed without collection: <b>'+reconMoney(c.closed_uncollected.fils)+'</b> over '+
        c.closed_uncollected.orders+' order(s).</div>'+
      '<div class="echelp" style="margin-top:8px">'+sesc(c.note)+'</div></div>';
  }

  function payMsg(id,text){
    var el=document.getElementById('pay_msg_'+id);
    if(el) el.textContent=text||'';
  }

  async function renderPayments(){
    var body=document.querySelector('#content');
    if(!body) return;

    /* Entering the screen with `#payments/<id>` in the address means somebody
       was sent to that gateway, so the link wins over the remembered tab.
       Validated in paintPayments() against the ids the endpoint actually
       returned — an unknown one falls back rather than painting an empty pane.
       An address of plain `#payments` leaves the remembered tab alone. */
    PAYTAB=payLinkTab()||PAYTAB;

    body.innerHTML='<div class="wrap"><div class="page-head"><h2>Payments</h2><p>Loading gateways…</p></div></div>';
    try{
      var d=await api('/admin-api/payments');
      PAYG=d.gateways||[];
    }catch(e){
      // 404 here means the admin route is not registered — on this host that
      // is the cache-clearing migration for the release not having run.
      body.innerHTML='<div class="wrap"><div class="card pad"><b>Could not load the payment gateways.</b>'+
        '<p style="margin:6px 0 12px;color:var(--ink-soft);font-size:12.5px">'+sesc(e.message)+'</p>'+
        '<button type="button" class="btn sm" id="pay_retry">Retry</button></div></div>';
      var rb=document.getElementById('pay_retry');
      if(rb) rb.onclick=function(){ renderPayments(); };
      return;
    }
    paintPayments();
  }

  async function paySave(id,settingsOverride,note){
    var g=PAYG.filter(function(x){ return x.id===id; })[0];
    if(!g) return;

    var settings=settingsOverride;
    if(!settings){
      settings={};
      document.querySelectorAll('[data-payg="'+id+'"]').forEach(function(el){
        // A blank secret box posts back blank, which the controller reads as
        // "leave the stored one alone" — so an edit to the label alone can
        // never wipe the keys.
        settings[el.dataset.payf]=el.value;
      });
    }

    var tog=document.querySelector('[data-payen="'+id+'"]');
    var titleEl=document.getElementById('pay_title_'+id);
    var modeEl=document.getElementById('pay_mode_'+id);

    // A null in the override means "clear this secret on purpose" (the New
    // webhook URL button). Sent as its own list: a blank or null value in
    // `settings` always keeps the stored key.
    var clear=[];
    Object.keys(settings).forEach(function(k){ if(settings[k]===null){ clear.push(k); delete settings[k]; } });

    var payload={
      id:id,
      clear:clear,
      enabled:tog?tog.classList.contains('on'):!!g.enabled,
      title:titleEl?titleEl.value:g.title,
      mode:modeEl?modeEl.value:g.mode,
      settings:settings
    };

    var btn=document.querySelector('[data-paysave="'+id+'"]');
    if(btn) btn.disabled=true;
    payMsg(id,'Saving…');
    try{
      // Not api(): a 422 here carries the controller's own sentence — "Unknown
      // setting: x", "Unknown payment gateway." — and api() throws away the
      // body, leaving the operator with a status code and no idea why.
      var r=await fetch(fixAdminApiUrl('/admin-api/payments'),{
        method:'POST',credentials:'same-origin',
        headers:{'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
        body:JSON.stringify(payload)
      });
      var d={}; try{ d=await r.json(); }catch(pe){}
      if(!r.ok||d.ok===false){
        var why=d.error||'';
        /* A gateway's validateConfig() sends ONE sentence per field, not
           Laravel's list: [0] of a string is its first letter, and every such
           refusal read "Could not save — T". (Lane WL.) */
        if(d.errors){ why=Object.keys(d.errors).map(function(k){ var v=d.errors[k]; return Array.isArray(v)?v[0]:v; }).join(' '); }
        throw new Error(why||('Request failed ('+r.status+')'));
      }
      toast(note||(g.title+' saved'));

      /* A successful save reloads the whole screen from the endpoint, which is
         what keeps `configured`, the webhook URL and the status lights honest.
         It also rebuilds every pane — so any edit the operator has waiting in
         ANOTHER gateway's tab is taken with it. On the old one-long-page screen
         that was at least visible; behind tabs it would be silent, which is the
         one failure that would make this feature worse than what it replaces.
         Captured before the repaint, put back after it. */
      var pending=payCapturePending();
      delete pending[id];

      await renderPayments();
      payRestorePending(pending);
    }catch(e){
      payMsg(id,'');
      toast('Could not save — '+e.message, 'bad');
      if(btn) btn.disabled=false;
    }
  }

  /* One gateway's controls were touched: say so on the card and light the tab,
     so the change is visible whether or not that pane is the open one. */
  function payTouched(id){
    payMsg(id, payIsDirty(id) ? 'Unsaved change' : '');
    payRefreshTabs();
  }

  /* ---------- Store -> Payments -> Stripe -> Connect ----------
     Everything dangerous is server-side in StripeConnect; this paints its
     answers. It renders no credential because it is given none: the status
     endpoint returns the account report and has_value-style flags only. */
  var STRIPE_CONN=null;

  /* THE MESSAGE HAS TO OUTLIVE THE REPAINT THAT ERASES IT.
     "the save connection button also not showing any successful message upon
     press" — it set the message span to '' on success and then called
     payStripeStatus(), which replaces the pane's innerHTML and destroys the
     span outright. Even a message written first would have been wiped a
     moment later, which is why this is a flash carried across the repaint and
     printed by the renderer rather than a textContent assignment. */
  var PAY_APP_FLASH='';

  /* FG: `painted` is the whole of the loop fix; see block 6.
     payStripeStatus() repaints the pane and then calls bindPayments(), and
     bindPayments() called payStripeStatus() back — an unconditional cycle that
     re-read the status endpoint for as long as the screen was open. It was
     invisible while the pane was three lines of text: it cost a request every
     round trip and painted the same three lines again.

     It stopped being invisible the moment the pane grew a form. Each turn of
     the cycle replaces the pane's innerHTML, so a half-typed client id was
     wiped under the cursor, and the guide — which is fetched separately and
     resolves a beat later — landed in a container that had already been
     thrown away. The panel could not be used at all.

     The flag is set on the HOST, which renderPayments() recreates from
     scratch, so leaving the screen and coming back still re-reads the truth.
     It is only the SECOND call within one painted pane that is refused. */
  async function payStripeStatus(){
    var host=document.getElementById('pay_conn_stripe');
    if(!host) return;
    try{ STRIPE_CONN=await api('/admin-api/payments/stripe/connect/status'); }
    catch(e){ host.innerHTML='<div class="ecom"><div class="echelp">Could not read the Stripe connection.</div></div>'; return; }
    /* HALF-TYPED CLIENT IDS SURVIVE THE REPAINT. Re-check re-reads the server
       and redraws this pane, which threw away whatever was in the two client-id
       boxes — a smaller version of the same complaint that got Re-check fixed.
       The two SECRET boxes are deliberately not carried: a password field
       repopulated from the DOM is a secret key sitting in the DOM, which is the
       rule the save path already follows by clearing them on every outcome.
       A client id is not a secret — it travels in the popup's address bar. */
    var keepIds={};
    ['pay_capp_id_test','pay_capp_id_live'].forEach(function(id){
      var el=document.getElementById(id);
      if(el && el.value) keepIds[id]=el.value;
    });

    host.innerHTML=payStripePanel(STRIPE_CONN);

    Object.keys(keepIds).forEach(function(id){
      var el=document.getElementById(id);
      /* Only where the server had nothing to say: a value it just stored wins
         over one the owner was mid-way through typing over it. */
      if(el && !el.value) el.value=keepIds[id];
    });

    /* Spent by being shown. A flash that outlived its repaint would report
       "Saved." over the next thing the owner did. */
    PAY_APP_FLASH='';
    host.dataset.painted='1';
    bindPayments();
  }

  /* FG: the connected / not-connected half, unchanged. payStripePanel() below
     is now the whole pane and adds the Connect application half after it. */
  function payStripeState(s){
    var a=s.account||{};
    if(s.connected){
      var bits=[];
      if(a.name) bits.push(sesc(a.name));
      if(a.country) bits.push(sesc(a.country));
      if(a.currency) bits.push('settles in '+sesc(a.currency));
      bits.push(a.charges_enabled?'charges enabled':'charges NOT enabled yet');
      bits.push(a.livemode?'LIVE account':'test account');
      return '<div class="ecom"><div class="ecl"><label>Connected to Stripe</label>'+
        '<span class="pill '+(a.charges_enabled?'green':'amber')+'"><span class="d"></span>'+
        (a.charges_enabled?'ready':'not ready')+'</span></div>'+
        '<div class="echelp">'+bits.join(' · ')+'</div>'+
        (a.currency && s.shop_currency && a.currency!==s.shop_currency
          ? '<div class="nlwarn">This shop prices in '+sesc(s.shop_currency)+' but Stripe settles in '+
            sesc(a.currency)+'. Stripe converts every payment, and the amounts on your Stripe dashboard '+
            'will not match the order totals here.</div>' : '')+
        '<div class="echelp">Payment notifications are set up for you. There is nothing to paste into Stripe.</div>'+
        '</div>';
    }
    return '<div class="ecom"><div class="ecl"><label>Connect to Stripe</label>'+
      '<span class="pill amber">not connected</span></div>'+
      '<div class="echelp">Press <b>Set up Stripe</b>. It asks which mode you want, opens the Stripe page your '+
      'key is on, and takes it from there — the account details, the publishable key, the payment '+
      'notifications and their signing secret are all set up for you.</div></div>'+
      '<div class="ecctl" style="display:block;width:100%">'+
      '<div class="row" style="gap:8px;flex-wrap:wrap">'+
      '<button type="button" class="btn" data-paywizard="1">Set up Stripe</button>'+
      (s.oauth_ready
        ? '<button type="button" class="btn ghost" data-payoauth="1">Connect with Stripe (one click)</button>'
        : '')+
      '<span class="echelp" id="pay_conn_msg" style="margin:0"></span></div>'+
      /* Where a blocked popup writes its way out. Empty until it has to be. */
      '<div id="pay_conn_fallback"></div>'+
      /* The old paste box, kept and demoted rather than removed. Somebody who
         already has the key in the clipboard should not have to walk a wizard,
         and every automated check that drove this panel drove this box. */
      '<details style="margin-top:12px"><summary style="cursor:pointer;font-size:12.5px;color:var(--ink-soft)">'+
      'Already have your secret key? Paste it instead</summary>'+
      '<div class="echelp" style="margin:8px 0 6px">Sign in at stripe.com, open Developers → API keys, copy '+
      'the <b>Secret key</b> (it starts sk_test_ or sk_live_) and paste it here. Everything else is done for you.</div>'+
      '<input type="password" class="inp" id="pay_conn_key" autocomplete="new-password" spellcheck="false" '+
      'placeholder="sk_test_… or sk_live_…" style="max-width:none">'+
      '<div class="row" style="gap:8px;margin-top:8px">'+
      '<button type="button" class="btn ghost" data-payconnect="1">Connect Stripe</button></div>'+
      '</details></div>';
  }

  /* The whole Stripe pane: what is connected, then how to switch on one click. */
  function payStripePanel(s){
    return payStripeState(s)+payStripePlatform(s);
  }

  /* ---------- the Connect application ----------
     WHY THIS PANEL EXISTS AT ALL. The one-click button is drawn from
     s.oauth_ready, and oauth_ready was false on every install because nothing
     in this console could store a client id. The button had been written,
     shipped and never once rendered. This is the box that fills it in.

     It renders no secret because it is given none: the status endpoint returns
     has_client_secret_* booleans and never a value, not even a masked one — a
     mask still discloses the length. The two password boxes are therefore
     always empty, and an empty box means "leave the stored one alone". */
  function payStripePlatform(s){
    var p=s.platform||{};
    var live=(p.mode!=='test');
    var idLive=p.client_id_live||'', idTest=p.client_id_test||'';
    var hasLive=!!p.has_client_secret_live, hasTest=!!p.has_client_secret_test;

    var pill = s.oauth_ready ? '<span class="pill green"><span class="d"></span>one click ready</span>'
             : (s.oauth_available ? '<span class="pill amber">half set up</span>'
                                  : '<span class="pill">not set up</span>');

    /* The sentence that distinguishes "you have not done this" from "you did
       it and half of it is missing". Only the second one is a mistake, and the
       panel used to say the same thing for both. */
    /* Already connected: there is no Connect button above to point at, and
       telling him to press one that is not on the screen is the small lie that
       makes an owner distrust the rest of the panel. */
    var say = s.connected
      ? (s.oauth_ready
          ? 'Set up. This shop is connected'+(s.link==='oauth'?' through one click':' with a pasted key')+
            ', and the one-click button is what you will see after a disconnect \u2014 nothing here has to be '+
            'entered again.'
          : 'This shop is connected'+(s.link==='oauth'?' through one click':' with a pasted key')+
            '. The Connect application is not fully set up, so after a disconnect the one-click button would not '+
            'be available until it is.')
      : s.oauth_ready
      ? (p.falls_back_to_merchant_key
          ? 'Ready, using this shop’s own stored Stripe key for the final step. That works while the shop stays '+
            'connected; saving the platform secret key below makes it work from a clean start too, which is the '+
            'state it is in after a disconnect.'
          : 'Ready. Press Connect with Stripe above and approve it in the window that opens.')
      : (s.oauth_available
          ? 'A Connect application id is saved but the '+(live?'Live':'Test')+' platform secret key is not, and '+
            'Stripe authenticates the last step with it. The button is held back on purpose: without that key the '+
            'window would open, you would grant this shop access to your Stripe account, and nothing would be saved.'
          : 'One-click Connect needs a Stripe Connect application registered in your own Stripe Dashboard. '+
            'WooCommerce hides this step because WooCommerce.com registers one for every shop that uses it, and '+
            'nobody has done that for this shop. Leave this empty and nothing is lost — pasting your secret '+
            'key above connects this shop just as fully.');

    return '<div class="ecopt wide" style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">'+
      '<div class="ecom"><div class="ecl"><label>Connect application (one-click setup)</label>'+pill+'</div>'+
      '<div class="echelp">'+say+'</div></div>'+
      '<div class="ecctl" style="display:block;width:100%">'+

      '<div class="row" style="gap:10px;align-items:flex-start;flex-wrap:wrap">'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Test client id'+
      (idTest?payTick():'')+'</div>'+
      '<input type="text" class="inp" id="pay_capp_id_test" spellcheck="false" placeholder="ca_…" '+
      'style="max-width:none" value="'+sesc(idTest)+'"></div>'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Live client id'+
      (idLive?payTick():'')+'</div>'+
      '<input type="text" class="inp" id="pay_capp_id_live" spellcheck="false" placeholder="ca_…" '+
      'style="max-width:none" value="'+sesc(idLive)+'"></div></div>'+

      '<div class="row" style="gap:10px;align-items:flex-start;flex-wrap:wrap;margin-top:8px">'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Test platform secret key'+
      (hasTest?payTick()+' <b>stored</b>':'')+'</div>'+
      '<input type="password" class="inp" id="pay_capp_sec_test" autocomplete="new-password" spellcheck="false" '+
      'placeholder="'+(hasTest?'••• leave blank to keep':'sk_test_…')+'" style="max-width:none"></div>'+
      '<div style="flex:1 1 240px"><div class="echelp" style="margin:0 0 4px">Live platform secret key'+
      (hasLive?payTick()+' <b>stored</b>':'')+'</div>'+
      '<input type="password" class="inp" id="pay_capp_sec_live" autocomplete="new-password" spellcheck="false" '+
      'placeholder="'+(hasLive?'••• leave blank to keep':'sk_live_…')+'" style="max-width:none"></div></div>'+

      /* The return address, printed in full and never shortened. The web root
         on this host is a different directory from the application root and
         every route carries KBB_BASE_PATH, so this is NOT the bare domain plus
         the path in the route file. Owners shorten it; Stripe then refuses the
         authorize request with an error about redirect_uri and says nothing
         about why. */
      '<div class="echelp" style="margin-top:10px">Register this exact address in the Connect application’s '+
      'redirect URI list. Stripe matches it character for character.</div>'+
      '<div class="row" style="gap:8px;margin-top:4px">'+
      '<input type="text" class="inp" id="pay_capp_redirect" readonly data-payhook="1" style="max-width:none" '+
      'value="'+sesc(String(s.redirect_uri||''))+'">'+
      '<button type="button" class="btn ghost" data-payappcopy="1">Copy</button>'+
      '<span class="echelp" id="pay_capp_copied" style="margin:0"></span></div>'+

      '<div class="row" style="gap:8px;margin-top:10px">'+
      '<button type="button" class="btn" data-payappsave="1">Save Connect application</button>'+
      ((hasTest||hasLive)?'<button type="button" class="btn ghost" data-payappclear="1">Forget stored keys</button>':'')+
      '<button type="button" class="btn ghost" data-payrecheck="1">Re-check connection</button>'+
      '<span class="echelp payflash'+(PAY_APP_FLASH?' on':'')+'" id="pay_capp_msg" style="margin:0">'+
      sesc(PAY_APP_FLASH)+'</span></div>'+

      '<div id="pay_capp_guide" style="margin-top:10px"></div>'+
      '</div></div>';
  }

  /* ---------- the setup guide ----------
     Fetched rather than inlined: the steps are App\Support\StripeConnectConsole
     and belong beside the code that knows what the flow needs, not in a string
     in this file that drifts from it. Every step names a MENU PATH and none
     carries a link, because no Stripe URL could be loaded and verified from the
     machine this was written on — and a wrong link in a credential-setup guide
     is the shape of a phishing page. */
  async function payStripeGuide(){
    var host=document.getElementById('pay_capp_guide');
    if(!host) return;
    var g;
    try{ g=(await api('/admin-api/payments/stripe/connect/platform')).guide; }
    catch(e){ host.innerHTML='<div class="echelp">The setup guide could not be loaded.</div>'; return; }
    if(!g){ host.innerHTML=''; return; }
    var steps=(g.steps||[]).map(function(st,i){
      return '<li style="margin:0 0 10px"><b>'+sesc(st.title)+'</b><div class="echelp" style="margin:2px 0 0">'+
        sesc(st.body)+'</div></li>';
    }).join('');
    host.innerHTML='<details class="tr-guide" style="border:1px solid var(--line);border-radius:10px;padding:10px 12px">'+
      '<summary style="cursor:pointer;font-weight:600">'+sesc(g.heading)+'</summary>'+
      '<div class="echelp" style="margin:8px 0 10px">'+sesc(g.intro||'')+'</div>'+
      '<ol style="margin:0 0 0 18px;padding:0">'+steps+'</ol>'+
      '<div class="echelp" style="margin-top:10px">'+sesc(g.closing||'')+'</div>'+
      '</details>';
  }

  async function payStripePlatformSave(clear){
    var msg=document.getElementById('pay_capp_msg');
    var idT=document.getElementById('pay_capp_id_test');
    var idL=document.getElementById('pay_capp_id_live');
    var secT=document.getElementById('pay_capp_sec_test');
    var secL=document.getElementById('pay_capp_sec_live');
    if(!idL) return;
    if(msg) msg.textContent='Saving…';
    var body={
      client_id: idL.value, client_id_test: idT?idT.value:'',
      client_secret: secL?secL.value:'', client_secret_test: secT?secT.value:''
    };
    if(clear){ body.clear_client_secret=true; body.clear_client_secret_test=true;
               body.client_secret=''; body.client_secret_test=''; }
    try{
      var r=await fetch(fixAdminApiUrl('/admin-api/payments/stripe/connect/application'),{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json',
                 'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
        body:JSON.stringify(body)
      });
      var d=await r.json();
      /* Cleared whatever the answer was. A refused save that left a live secret
         key sitting in a form field is a secret key sitting in the DOM. */
      if(secT) secT.value=''; if(secL) secL.value='';
      if(!d.ok){ if(msg) msg.textContent=d.error||'That could not be saved.'; return; }
      PAY_APP_FLASH = clear ? 'Stored keys forgotten.' : 'Saved.';
      await payStripeStatus();
    }catch(e){ if(msg) msg.textContent='Could not reach this site to save.'; }
  }

  /* ---------- the setup wizard ----------
     WHAT THE OWNER ASKED FOR, TWICE, AND IN HIS OWN WORDS: press one button,
     get a window with the Stripe steps and the choices in it, come back
     configured. The OAuth path below is the literal one-click version of that
     and it only exists once a Connect application has been registered in his
     own Stripe Dashboard — fifteen minutes of work in somebody else's console
     that no code here can do for him. This is the rest of the answer, and it
     needs nothing registered: pick a mode, fetch one value from a page this
     screen opens for you, and the server does every other part of the setup.

     WHAT "CONFIGURED AUTOMATICALLY" ACTUALLY COVERS, because it is easy to say
     and this is the list: the account is read back from Stripe and its name,
     country and currency stored; the publishable key is fetched rather than
     asked for; a webhook endpoint is created in his Stripe account pointed at
     this shop's own unguessable URL; and the signing secret Stripe returns
     exactly once, at that moment and never again, is captured and stored. The
     only thing he supplies is the secret key.

     IT IS A MODAL, NOT A SECOND BROWSER WINDOW, and that is a decision rather
     than a shortcut. A window this page opens onto ITSELF is the one kind a
     popup blocker stops for no benefit at all — and it would leave him copying
     a key out of one window and into another. The windows that DO open point
     at Stripe: the API keys page, from a real anchor, which no blocker stops;
     and the OAuth authorize page, through the existing popup path that already
     has a blocked-popup fallback.

     THE TWO LINKS BELOW ARE THE ONLY STRIPE URLS IN THIS CONSOLE. A wrong link
     in a credential-setup guide is the shape of a phishing page, so neither is
     guessed: both are dashboard.stripe.com's own API-keys pages, one per mode,
     and StripeConnectConsole's written guide still carries menu paths and not
     one URL. A test pins these two so a later edit cannot quietly add a third. */
  var STRIPE_KEY_URL={test:'https://dashboard.stripe.com/test/apikeys',
                      live:'https://dashboard.stripe.com/apikeys'};

  var STRIPE_WIZ={step:1,mode:'test',done:null,busy:false,forceKey:false};

  function payStripeWizardOpen(){
    var m=document.getElementById('pay_mode_stripe');
    STRIPE_WIZ={step:1,mode:(m&&m.value==='live')?'live':'test',done:null,busy:false,forceKey:false};
    openModal(payStripeWizardHtml());
    payStripeWizardBind();
  }

  /* Can the one-click button be drawn FOR THE MODE THE WIZARD IS ON?
     s.oauth_ready is the server's answer for the mode the gateway row is
     currently labelled with, and the wizard's first screen can move off that
     before anything is saved. Asking the platform block directly is the only
     answer that is about the mode in front of him — and drawing the button on
     a half-set-up mode would fail AFTER he had granted this shop access to his
     Stripe account, which is the failure FG built the whole hold-back for. */
  function payStripeWizardOneClick(){
    var s=STRIPE_CONN||{}, p=s.platform||{}, live=STRIPE_WIZ.mode==='live';
    var pair=live ? (!!p.client_id_live && !!p.has_client_secret_live)
                  : (!!p.client_id_test && !!p.has_client_secret_test);
    return pair || (!!s.oauth_ready && ((p.mode==='live')===live));
  }

  function payStripeWizardHtml(){
    var w=STRIPE_WIZ, live=w.mode==='live';
    var head='<div class="modal-h"><b>Set up Stripe</b>'+
      '<button type="button" class="x" data-wizclose="1" aria-label="Close">✕</button></div>';
    var crumb='<div class="echelp" style="margin:0 0 14px;letter-spacing:.06em;text-transform:uppercase;font-size:10.5px">'+
      'Step '+w.step+' of 3</div>';

    /* ---- 1. the choice. It is first because it changes every screen after
       it: which Stripe page opens, which key is accepted, and whether the
       one-click button can be drawn at all. */
    if(w.step===1){
      var card=function(mode,title,body){
        var on=(w.mode===mode);
        return '<button type="button" data-wizmode="'+mode+'" style="display:block;width:100%;text-align:left;'+
          'border:1px solid '+(on?'var(--brand,#c2185b)':'var(--border)')+';background:'+
          (on?'var(--surface-2)':'transparent')+';border-radius:10px;padding:12px 14px;margin:0 0 10px;cursor:pointer">'+
          '<b style="font-size:13.5px">'+title+'</b>'+
          '<div class="echelp" style="margin:3px 0 0">'+body+'</div></button>';
      };
      return head+'<div class="modal-b">'+crumb+
        '<div class="echelp" style="margin:0 0 12px">Which one are we setting up? You can do the other one later '+
        '— they are separate keys and separate settings at Stripe, and this shop stores them one at a time.</div>'+
        card('test','Test mode','Practise with Stripe’s fake cards. No real money moves and no real card '+
             'works. This is the safe one to do first.')+
        card('live','Live mode','Real cards, real money, into the Stripe account you are signed in to.')+
        '<div class="row" style="gap:8px;justify-content:flex-end;margin-top:6px">'+
        '<button type="button" class="btn ghost" data-wizclose="1">Cancel</button>'+
        '<button type="button" class="btn" data-wizstep="2">Continue</button></div></div>';
    }

    /* ---- 2. the work. Two genuinely different screens, because when the
       Connect application is registered there is nothing to fetch at all. */
    if(w.step===2){
      var back='<button type="button" class="btn ghost" data-wizstep="1">Back</button>';
      if(payStripeWizardOneClick() && !w.forceKey){
        return head+'<div class="modal-b">'+crumb+
          '<div class="ecnote" style="margin:0 0 14px"><b>One click is available for '+(live?'Live':'Test')+
          ' mode.</b> This shop has a Connect application registered, so there is no key to fetch and nothing to '+
          'copy. A Stripe window opens, you approve it there, and it closes itself.</div>'+
          '<div class="row" style="gap:8px;justify-content:flex-end">'+back+
          '<button type="button" class="btn" data-wizoauth="1">Connect with Stripe</button></div>'+
          '<div class="echelp" style="margin-top:12px;text-align:right">'+
          '<a href="#" data-wizstep="3sub" style="color:var(--ink-soft)">or paste a key instead</a></div></div>';
      }
      var url=STRIPE_KEY_URL[live?'live':'test'];
      var li=function(n,html){
        return '<li style="margin:0 0 12px"><b style="font-size:13px">'+n+'</b>'+
          '<div class="echelp" style="margin:3px 0 0">'+html+'</div></li>';
      };
      return head+'<div class="modal-b">'+crumb+
        '<div class="echelp" style="margin:0 0 12px">Setting up <b>'+(live?'Live':'Test')+' mode</b>. '+
        'There is one value to fetch and the button below opens the exact page it is on.</div>'+
        '<ol style="margin:0 0 4px 18px;padding:0">'+
        li('Open your Stripe API keys',
           'It opens in a new tab at <span style="word-break:break-all">'+sesc(url)+'</span> — Stripe’s own '+
           'dashboard. Sign in there if it asks.'+
           '<div style="margin-top:8px"><a class="btn ghost" href="'+sesc(url)+'" target="_blank" '+
           'rel="noopener noreferrer">Open my Stripe '+(live?'live':'test')+' API keys ↗</a></div>')+
        li('Copy the Secret key',
           'Not the Publishable key above it. The secret one starts <b>'+(live?'sk_live_':'sk_test_')+'</b> and is '+
           'hidden behind a <i>Reveal</i> link. Stripe shows it once — if it will not reveal, create a new one '+
           'and use that.')+
        li('Paste it here and press Finish',
           'It goes straight to the server, is stored encrypted, and is never sent back to this screen.')+
        '</ol>'+
        '<input type="password" class="inp" id="wiz_key" autocomplete="new-password" spellcheck="false" '+
        'placeholder="'+(live?'sk_live_…':'sk_test_…')+'" style="max-width:none;width:100%;margin-top:4px">'+
        '<div class="echelp" id="wiz_msg" style="margin:8px 0 0;min-height:16px"></div>'+
        '<div class="row" style="gap:8px;justify-content:flex-end;margin-top:8px">'+back+
        '<button type="button" class="btn" data-wizfinish="1">Finish setup</button></div></div>';
    }

    /* ---- 3. what actually happened. Named item by item, because "connected"
       on its own is the claim and this is the evidence for it. */
    var d=w.done||{}, a=d.account||{};
    var rows=[];
    if(a.name) rows.push(['Stripe account',sesc(a.name)]);
    if(a.country) rows.push(['Country',sesc(a.country)]);
    if(a.currency) rows.push(['Settles in',sesc(a.currency)]);
    rows.push(['Card payments',a.charges_enabled?'enabled by Stripe':'NOT enabled by Stripe yet']);
    rows.push(['Mode stored',(d.mode==='live'?'Live':'Test')+(a.livemode?' · real money':' · test money')]);
    rows.push(['Publishable key','fetched from Stripe for you']);
    rows.push(['Payment notifications',
      (d.webhook_action==='reused'||d.webhook_action==='reused_existing')
        ? 'endpoint already existed, reused'
        : (d.webhook_action==='replaced' ? 'endpoint replaced and re-secured' : 'endpoint created in your Stripe account')]);
    rows.push(['Signing secret','captured and stored']);
    var table=rows.map(function(r){
      return '<div class="row" style="gap:10px;padding:6px 0;border-bottom:1px solid var(--border)">'+
        '<span class="echelp" style="margin:0;flex:0 0 42%">'+r[0]+'</span>'+
        '<span style="font-size:12.5px;font-weight:600">'+r[1]+'</span></div>';
    }).join('');
    var warn=(d.warnings||[]).map(function(x){
      return '<div class="nlwarn" style="margin-top:10px">'+sesc(x)+'</div>';
    }).join('');
    return head+'<div class="modal-b">'+crumb+
      '<div class="ecnote" style="margin:0 0 12px"><b>Connected.</b> Everything below was done for you. '+
      'Card payments are not being offered at the till yet — that is the <i>Offer this at checkout</i> switch '+
      'on the screen behind this one, and it stays your decision.</div>'+
      table+warn+
      '<div class="row" style="gap:8px;justify-content:flex-end;margin-top:14px">'+
      '<button type="button" class="btn" data-wizdone="1">Done</button></div></div>';
  }

  function payStripeWizardPaint(){
    var host=document.getElementById('modal');
    if(!host) return;
    host.innerHTML=payStripeWizardHtml();
    payStripeWizardBind();
  }

  function payStripeWizardBind(){
    var root=document.getElementById('modal');
    if(!root) return;
    root.querySelectorAll('[data-wizclose]').forEach(function(b){
      b.onclick=function(){ closeModal(); };
    });
    root.querySelectorAll('[data-wizmode]').forEach(function(b){
      b.onclick=function(){ STRIPE_WIZ.mode=b.getAttribute('data-wizmode'); payStripeWizardPaint(); };
    });
    root.querySelectorAll('[data-wizstep]').forEach(function(b){
      b.onclick=function(e){
        e.preventDefault();
        /* '3sub' is the escape hatch off the one-click screen: it is step 2
           with the one-click answer forced off, not a third screen. */
        var v=b.getAttribute('data-wizstep');
        if(v==='3sub'){ STRIPE_WIZ.step=2; STRIPE_WIZ.forceKey=true; }
        else { STRIPE_WIZ.step=Number(v)||1; STRIPE_WIZ.forceKey=false; }
        payStripeWizardPaint();
      };
    });
    root.querySelectorAll('[data-wizoauth]').forEach(function(b){
      /* window.open has to happen inside this gesture or the blocker takes it,
         so the modal is closed synchronously and the existing popup path runs
         in the same turn. Its blocked-popup fallback writes onto the panel
         behind, which is why the modal goes first rather than after. */
      b.onclick=function(){ var m=STRIPE_WIZ.mode; closeModal(); payStripeOauth(m); };
    });
    root.querySelectorAll('[data-wizfinish]').forEach(function(b){
      b.onclick=function(){ payStripeWizardFinish(); };
    });
    root.querySelectorAll('[data-wizdone]').forEach(function(b){
      b.onclick=function(){ closeModal(); renderPayments(); };
    });
    var k=document.getElementById('wiz_key');
    if(k){
      k.onkeydown=function(e){ if(e.key==='Enter'){ e.preventDefault(); payStripeWizardFinish(); } };
      try{ k.focus(); }catch(e){}
    }
  }

  /* The one network call the wizard makes, and it is the SAME endpoint the
     paste box has always used. Nothing new is trusted with a secret key.

     A failure repaints NOTHING: the message lands in #wiz_msg and the rest of
     the screen is left alone, so a rejected key does not throw away the step
     list he is reading. The box is emptied on every outcome either way — a
     refused key left sitting in an input is a secret key sitting in the DOM. */
  async function payStripeWizardFinish(){
    var box=document.getElementById('wiz_key');
    var msg=document.getElementById('wiz_msg');
    var btn=document.querySelector('#modal [data-wizfinish]');
    if(!box || STRIPE_WIZ.busy) return;
    if(!box.value.trim()){ if(msg) msg.textContent='Paste the secret key from Stripe first.'; return; }
    STRIPE_WIZ.busy=true;
    if(msg) msg.textContent='';
    if(btn){ btn.disabled=true; btn.textContent='Checking with Stripe…'; }
    try{
      var r=await fetch(fixAdminApiUrl('/admin-api/payments/stripe/connect'),{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json',
                 'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
        body:JSON.stringify({secret_key:box.value,mode:STRIPE_WIZ.mode})
      });
      var d=await r.json();
      box.value='';
      STRIPE_WIZ.busy=false;
      if(btn){ btn.disabled=false; btn.textContent='Finish setup'; }
      if(!d.ok){
        /* THE ONE MESSAGE THAT HAS TO BE REWRITTEN HERE. The server's
           mode-mismatch sentence ends "Switch Mode to Live and press Connect
           again" — correct on the panel, where there is a Mode select and a
           Connect button, and wrong inside this wizard, where there is
           neither. Sending an owner to look for a control that is not on the
           screen is the small lie that makes him distrust the rest of it. The
           branch is taken on d.step, which the server sets, not on the text. */
        if(msg){
          msg.textContent = (d.step==='mode')
            ? (STRIPE_WIZ.mode==='live'
                ? 'That is a TEST key and you chose Live mode. Press Back and choose Test mode, or fetch the '
                  +'sk_live_ key from the page the button above opens.'
                : 'That is a LIVE key and you chose Test mode — real cards would be charged. Press Back and '
                  +'choose Live mode if that is what you meant, or fetch the sk_test_ key instead.')
            : (d.error||'Stripe refused the connection.');
        }
        return;
      }
      STRIPE_WIZ.done=d; STRIPE_WIZ.step=3;
      payStripeWizardPaint();
      /* The panel behind is now wrong. Repainting it while the result is on
         screen means pressing Done reveals a screen that already agrees. */
      payStripeStatus();
    }catch(e){
      box.value='';
      STRIPE_WIZ.busy=false;
      if(btn){ btn.disabled=false; btn.textContent='Finish setup'; }
      if(msg) msg.textContent='Could not reach this site to connect.';
    }
  }

  /* ---------- the popup ----------
     Opened by the click itself. A round trip before window.open is what the
     browser's popup blocker is looking for, so the URL is ours and 302s to
     Stripe rather than being fetched first and navigated to second. */
  var STRIPE_POPUP=null, STRIPE_POPUP_TIMER=null, STRIPE_POPUP_DONE=false;

  function payStripeOauth(mode){
    /* The wizard knows which mode the owner chose on its own first screen, and
       that screen is in front of the Mode select rather than the other way
       round. Called with nothing, as the panel's own button calls it, this
       reads the select exactly as before. */
    var m=document.getElementById('pay_mode_stripe');
    var use=(mode==='live'||mode==='test')?mode:(m?m.value:'test');
    var url=fixAdminApiUrl('/admin-api/payments/stripe/connect/start?mode='+
      encodeURIComponent(use));
    var msg=document.getElementById('pay_conn_msg');
    var fb=document.getElementById('pay_conn_fallback');
    if(fb) fb.innerHTML='';
    STRIPE_POPUP_DONE=false;

    var w=null;
    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }

    /* A TENTH NAVIGATION, and the one that can be cleaned up. (Lane SEC)
       This address is admin-guarded, so an expired session shows a blank 404
       in the popup with nothing said on the console behind it. Unlike the
       order documents this one KEEPS THE HANDLE, so the dead window is
       closed rather than left for the owner to find. Asked after the open
       for the reason the comment above gives: a round trip in front of
       window.open is what the popup blocker is looking for. */
    if(w) kbbTellIfDownloadRefused(url, w);

    /* BLOCKED. window.open returns null, or an object that is already closed,
       or — in a couple of older browsers — something with no `closed` at all.
       All three are the same answer and none of them may leave a dead button. */
    if(!w || w.closed || typeof w.closed==='undefined'){
      payStripePopupBlocked(url);
      return;
    }

    STRIPE_POPUP=w;
    try{ w.focus(); }catch(e){}
    if(msg) msg.textContent='Finish in the Stripe window…';

    /* HE CLOSED IT. Nothing reaches this page when a popup is dismissed, so
       without this the screen would sit on "Finish in the Stripe window…" for
       ever. The answer is not guessed either: the server is asked what it now
       holds, because he may well have completed the flow and closed the window
       before it could report back. */
    if(STRIPE_POPUP_TIMER) clearInterval(STRIPE_POPUP_TIMER);
    STRIPE_POPUP_TIMER=setInterval(function(){
      var p=STRIPE_POPUP;
      if(!p) { clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; return; }
      var shut=false;
      try{ shut=p.closed; }catch(e){ shut=true; }
      if(!shut) return;
      clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; STRIPE_POPUP=null;
      if(STRIPE_POPUP_DONE) return;
      if(msg) msg.textContent='';
      renderPayments();
    },600);
  }

  /* The fallback, and it has to be something that WORKS rather than an
     apology. A link the owner clicks himself is a user gesture on an anchor,
     which no popup blocker stops. rel="opener" is load-bearing: target=_blank
     implies rel=noopener in every current browser, the callback page would
     find window.opener null, and the console would never be told the outcome —
     a tab that says "connected" over a screen that still says "not connected".
     Re-check covers even that. */
  function payStripePopupBlocked(url){
    var fb=document.getElementById('pay_conn_fallback');
    var msg=document.getElementById('pay_conn_msg');
    if(msg) msg.textContent='';
    if(!fb) return;
    fb.innerHTML='<div class="ecnote" style="margin-top:10px">'+
      '<b>Your browser blocked the Stripe window.</b>'+
      '<div class="echelp" style="margin-top:6px">Nothing has gone wrong and nothing has changed. '+
      'Open it with the link below, or allow pop-ups for this site and press Connect with Stripe again.</div>'+
      '<div class="row" style="gap:8px;margin-top:8px">'+
      '<a class="btn" rel="opener" target="_blank" href="'+sesc(url)+'">Open the Stripe window</a>'+
      '<button type="button" class="btn ghost" data-payrecheck="1">Re-check connection</button></div></div>';
    bindPayments();
  }

  async function payStripeConnect(){
    var box=document.getElementById('pay_conn_key');
    var msg=document.getElementById('pay_conn_msg');
    var mode=document.getElementById('pay_mode_stripe');
    if(!box) return;
    if(msg) msg.textContent='Checking with Stripe…';
    try{
      var r=await fetch(fixAdminApiUrl('/admin-api/payments/stripe/connect'),{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json',
                 'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
        body:JSON.stringify({secret_key:box.value,mode:mode?mode.value:null})
      });
      var d=await r.json();
      box.value='';                       /* the key never lingers in the DOM */
      if(!d.ok){ if(msg) msg.textContent=d.error||'Stripe refused the connection.'; return; }
      if(msg) msg.textContent='';
      await renderPayments();
      if((d.warnings||[]).length) alert(d.warnings.join('\n\n'));
    }catch(e){ if(msg) msg.textContent='Could not reach this site to connect.'; }
  }

  async function payStripeDisconnect(){
    var s=STRIPE_CONN||{};
    var n=(s.in_flight||{}).count||0;
    /* TWO DIFFERENT RISKS, and this dialog could only see one of them.
       in_flight counts orders with NO paid_at — a shopper who may be on
       Stripe's payment page right now. A FULLY CAPTURED order has paid_at set,
       so it was never counted here at all, and the refund on it comes back
       not_configured once the keys are gone: that money cannot be returned
       through this panel by any route. The owner was being told "nothing is in
       flight" over a quarter of a million fils he was about to strand. */
    var rf=s.refundable||{count:0};
    var warn='Disconnect this shop from Stripe?\n\n'+
      'Card payments stop being offered and the stored keys are erased.\n';
    if(n) warn+='\n'+n+' payment'+(n===1?' is':'s are')+' still in progress. Anyone already on '+
      'Stripe\'s payment page can still pay, and that money will reach your Stripe account — but this '+
      'shop will not hear about it, so the order stays unpaid here until you mark it paid or reconnect.\n';
    if(rf.count) warn+='\n'+rf.amount_display+' is still refundable on '+rf.count+' order'+
      (rf.count===1?'':'s')+'. With the keys cleared this shop cannot send a refund to Stripe at all, so '+
      'that money can only go back from the Stripe dashboard by hand until you reconnect — and a refund '+
      'made there will not be recorded against the order here.\n';
    warn+='\nYou can connect again at any time.';
    if(!confirm(warn)) return;
    try{
      var r=await fetch(fixAdminApiUrl('/admin-api/payments/stripe/disconnect'),{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json',
                 'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
        body:JSON.stringify({confirm:'disconnect'})
      });
      var d=await r.json();
      await renderPayments();
      if((d.warnings||[]).length) alert(d.warnings.join('\n\n'));
    }catch(e){ alert('Could not reach this site to disconnect.'); }
  }

  /* The popup reports back on this origin and closes itself.

     THE ORIGIN CHECK IS THE POINT. This listener is on window, so any frame or
     opened window can post to it; without the check, a page on another origin
     could hand this console a fabricated result and make the payments screen
     claim a connection that does not exist. It is compared against an ORIGIN
     and never a URL — postMessage compares scheme, host and port, and a path on
     the end makes every comparison fail silently. */
  window.addEventListener('message',function(e){
    if(e.origin!==window.location.origin) return;
    if(!e.data||e.data.source!=='kbb.stripe.connect') return;
    var r=e.data.result||{};
    /* FG: the popup HAS reported. The watcher in payStripeOauth() polls for it
       being closed and would otherwise repaint a second time a moment later,
       over the top of this one — and, on a failure, would clear the message
       this alert is about to explain. */
    STRIPE_POPUP_DONE=true;
    if(STRIPE_POPUP_TIMER){ clearInterval(STRIPE_POPUP_TIMER); STRIPE_POPUP_TIMER=null; }
    STRIPE_POPUP=null;
    renderPayments().then(function(){
      if(!r.ok && r.error) alert(r.error);
      else if((r.warnings||[]).length) alert(r.warnings.join('\n\n'));
    });
  });

  function bindPayments(){
    /* Bound on the screen's own wrapper, never on document. Two separate bugs
       on this console came from document-level listeners matching another
       screen's markup (a bare .ectog, a bare data-open); the wrapper is
       replaced wholesale on every repaint, so these cannot outlive the screen
       or reach anything outside it. */
    var wrap=document.querySelector('[data-payscreen]');

    if(wrap){
      wrap.addEventListener('click',function(e){
        var tb=e.target.closest ? e.target.closest('[data-paytab]') : null;
        if(tb){ e.preventDefault(); payShowTab(tb.dataset.paytab); }
      });

      /* Left/Right walk the bar, Home/End jump to its ends — the roving
         tabindex set in payShowTab() is what makes Tab leave the bar instead
         of stepping through every gateway. */
      wrap.addEventListener('keydown',function(e){
        var tb=e.target.closest ? e.target.closest('[data-paytab]') : null;
        if(!tb) return;
        var ids=PAYG.map(function(g){ return g.id; });
        var i=ids.indexOf(tb.dataset.paytab);
        if(i<0) return;
        var next=null;
        if(e.key==='ArrowRight') next=ids[(i+1)%ids.length];
        else if(e.key==='ArrowLeft') next=ids[(i-1+ids.length)%ids.length];
        else if(e.key==='Home') next=ids[0];
        else if(e.key==='End') next=ids[ids.length-1];
        if(!next) return;
        e.preventDefault();
        payShowTab(next);
        var el=document.getElementById('pay_tab_'+next);
        if(el) el.focus();
      });

      /* Typing anywhere in a pane re-evaluates that gateway's tab marker. The
         target tells us which gateway without a listener per input. */
      var touch=function(e){
        var el=e.target;
        if(!el||!el.dataset) return;
        var id=el.dataset.payg||el.dataset.paytitle||el.dataset.paymode;
        if(id) payTouched(id);
      };
      wrap.addEventListener('input',touch);
      wrap.addEventListener('change',touch);
    }

    document.querySelectorAll('[data-payen]').forEach(function(el){
      var flip=function(){
        var on=!el.classList.contains('on');
        el.classList.toggle('on',on);
        el.setAttribute('aria-checked',on?'true':'false');
        payTouched(el.dataset.payen);
      };
      el.onclick=flip;
      el.onkeydown=function(e){ if(e.key===' '||e.key==='Enter'){ e.preventDefault(); flip(); } };
    });

    document.querySelectorAll('[data-paywizard]').forEach(function(b){
      b.onclick=function(){ payStripeWizardOpen(); };
    });
    document.querySelectorAll('[data-payconnect]').forEach(function(b){
      b.onclick=function(){ payStripeConnect(); };
    });
    document.querySelectorAll('[data-payoauth]').forEach(function(b){
      b.onclick=function(){ payStripeOauth(); };
    });
    document.querySelectorAll('[data-payappsave]').forEach(function(b){
      b.onclick=function(){ payStripePlatformSave(false); };
    });
    document.querySelectorAll('[data-payappclear]').forEach(function(b){
      b.onclick=function(){
        if(!confirm('Forget the stored platform secret keys?\n\nThe one-click button switches off until one is '+
          'saved again. Nothing that is already connected is disconnected, and no money is affected.')) return;
        payStripePlatformSave(true);
      };
    });
    /* The last resort, and it is bound on the panel AND inside the blocked-popup
       notice: the one case where nothing else can tell this screen what
       happened is the one where the browser refused to open the window that
       would have. Asking the server is always available and never wrong. */
    document.querySelectorAll('[data-payrecheck]').forEach(function(b){
      /* RE-CHECK CHECKS. It called renderPayments(), which rebuilds the WHOLE
         Payments screen from the server: every tab back to its default, every
         unsaved box on every gateway thrown away, the gateway tab reset. The
         owner's words were "just resetting everything instead of checking",
         and that is exactly what it did.
         payStripeStatus() re-reads /connect/status and repaints the connection
         pane alone, which is the thing the button names. */
      b.onclick=function(){
        var msg=document.getElementById('pay_capp_msg');
        if(msg) msg.textContent='Checking…';
        PAY_APP_FLASH='Checked just now.';
        payStripeStatus();
      };
    });
    document.querySelectorAll('[data-payappcopy]').forEach(function(b){
      b.onclick=async function(){
        var input=document.getElementById('pay_capp_redirect');
        if(!input) return;
        try{ await navigator.clipboard.writeText(input.value); }
        catch(err){ input.select(); try{ document.execCommand('copy'); }catch(e2){} }
        var ok=document.getElementById('pay_capp_copied');
        if(ok){ ok.textContent='Copied'; setTimeout(function(){ ok.textContent=''; },1800); }
      };
    });
    if(document.getElementById('pay_capp_guide')) payStripeGuide();
    document.querySelectorAll('[data-paydisc]').forEach(function(b){
      b.onclick=function(){ payStripeDisconnect(); };
    });
    /* FG: once per painted pane, not once per bind. bindPayments() is called
       BY payStripeStatus() at the end of every repaint, so this line called it
       straight back — see the note on payStripeStatus() for what that cost. */
    var connHost=document.getElementById('pay_conn_stripe');
    if(connHost && !connHost.dataset.painted) payStripeStatus();

    document.querySelectorAll('[data-paycheck]').forEach(function(b){
      b.addEventListener('click', function(){ payPreflight(b.getAttribute('data-paycheck')); });
    });
    document.querySelectorAll('[data-paysave]').forEach(function(b){
      b.onclick=function(){ paySave(b.dataset.paysave,null,null); };
    });

    document.querySelectorAll('[data-payhook]').forEach(function(el){
      el.onclick=function(){ el.select(); };
    });

    document.querySelectorAll('[data-paycopy]').forEach(function(b){
      b.onclick=async function(){
        var id=b.dataset.paycopy;
        var input=document.getElementById('pay_hook_'+id);
        if(!input) return;
        try{ await navigator.clipboard.writeText(input.value); }
        catch(err){ input.select(); try{ document.execCommand('copy'); }catch(e2){} }
        var ok=document.getElementById('pay_copied_'+id);
        if(ok){ ok.textContent='Copied'; setTimeout(function(){ ok.textContent=''; },1800); }
      };
    });

    document.querySelectorAll('[data-payregen]').forEach(function(b){
      b.onclick=function(){
        var id=b.dataset.payregen;
        var g=PAYG.filter(function(x){ return x.id===id; })[0];
        if(!g) return;
        openModal('<div class="modal-h"><b>Regenerate webhook URL</b><button class="x" onclick="closeModal()">✕</button></div>'+
          '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">A new URL is generated for <b>'+sesc(g.title)+'</b> and the current one stops working at once. Any payment confirmation sent to the old URL is rejected until the new one is pasted into the provider dashboard.</p>'+
          '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
          '<button class="btn" id="pay_regen_yes">Regenerate</button></div></div>');
        var yes=document.getElementById('pay_regen_yes');
        if(yes) yes.onclick=function(){
          closeModal();
          // null clears the stored key; the controller then finds it empty and
          // mints a fresh one on the same save.
          paySave(id,{webhook_secret:null},'New webhook URL generated — paste it into '+g.title);
        };
      };
    });
  }

  /* ---------- Payments deep link on first load ----------
     Two separate reasons this screen has to claim its own address.

     `#payments/stripe` is not in TITLES, so the console's boot matcher — which
     understands `#<id>` and `?go=<id>` and looks the value up there — falls
     through to the dashboard. That much is expected: the gateway is a level
     deeper than anything that matcher was written for.

     The second reason is a standing bug this lane did not cause and is fixing
     only for its own screen. That boot block runs at the END OF THE FIRST
     SCRIPT, where go() is still the original — and the original's dispatch
     table has no entry for `payments`, because Payments (like Orders,
     Analytics, SEO, Blog, Posts, Business Details and Quiz Leads — the whole
     LIVE_RENDERED set) is wired up by the wrapper installed in the SECOND
     script, further down this file. So even a plain `?go=payments` lands on
     the dashboard today. Widening the shared matcher would touch every one of
     those screens at once, which is not this lane's to do; claiming the
     address here fixes Payments and leaves the other seven exactly as they
     are, reported rather than quietly changed.

     Deferred by a tick so it runs after the four partials further down have
     each wrapped window.go — calling it synchronously here would route through
     a half-built chain. */
  (function(){
    if(payAddressed()){
      setTimeout(function(){
        try{ if(typeof window.go==='function') window.go('payments'); }catch(e){}
      },0);
    }

    /* And the same address arriving at an ALREADY-OPEN console.
       Changing only the fragment is a same-document navigation: no reload, no
       scripts re-run, so the boot above never sees it. Without this, pasting
       `#payments/stripe` into the address bar of an open console does nothing
       at all — which is precisely the case a link is for.

       Only payments-shaped fragments are acted on, so this cannot disturb any
       other screen's address. payRememberTab() uses replaceState, which fires
       no hashchange, so a tab click cannot re-enter here. */
    window.addEventListener('hashchange',function(){
      var want=payHashTab();
      if(!want) return;
      if(document.querySelector('[data-payscreen]') && payKnown(want)){
        payShowTab(want,false);   // already here: just move, do not re-address
        return;
      }
      try{ if(typeof window.go==='function') window.go('payments'); }catch(e){}
    });
  })();

  /* ---------- Route interception: hydrate dash, render new screens ---------- */
  var _go = window.go;
  window.go = function(id, sub){
    /* `#orders/<id>` (Lane ORD) is an order's own address: a row opened in a
       new tab lands on that order, not on the list. */
    if(id==='orders'){ _go(id); return /^[0-9]{1,10}$/.test(String(sub || '')) ? renderOrderDetail(+sub) : renderOrders(); }
    if(id==='customers'){ _go(id); return renderCustomers(); }
    if(id==='quiz-leads'){ _go(id); return renderQuizLeads(); }
    if(id==='rev-all'){ _go(id); return renderReviews(); }
    if(id==='store-settings'){ _go(id); return renderStoreSettings('business'); }
    /* The sidebar's Tax row opens Business Details on its Tax tab —
       one screen, two ways in, so the word he is looking for is both
       in the list and on the page it takes him to. */
    if(id==='tax'){ _go(id); return renderStoreSettings('tax'); }
    if(id==='payments'){ _go(id); return renderPayments(); }
    if(id==='seo'){ _go(id); return renderSeo(); }
    if(id==='analytics'){ _go(id); return renderAnalytics(); }
    if(id==='blog'||id==='posts'){ _go(id); return renderPosts(); }
    /* Every argument, not just the id: go(id, sub) opens a screen ON a tab
       (`#catalog/reorder`, go('catalog','reorder')), and passing `id` alone
       dropped the tab, so those links landed on the screen's first tab.
       Found by Lane PM; item 23. */
    _go.apply(this, arguments);
    if(id==='dash') hydrateDash();
  };

  /* ---------- Sign out (top bar) ---------- */
  function addLogout(){
    var top=document.querySelector('.top');
    if(top && !document.getElementById('kbbSignout')){
      var b=document.createElement('button');
      b.id='kbbSignout'; b.className='iconbtn'; b.title='Sign out';
      b.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>';
      b.onclick=async function(){
        /* THE ADMIN PATH IS NOT ALWAYS "admin". (Lane SEC) Both addresses
           below were literals, and routes/web.php answers `/admin/{any?}`
           with abort(404) the moment the owner moves his admin path -- which
           is the point of the setting, and a control he has on Store -> Core
           Updates. So this button posted to a 404, SWALLOWED IT, and then
           navigated to a second 404.

           MEASURED, on a preview whose admin lives at /sec-console:
           POST /admin/logout -> 404, GET /admin/login -> 404, and
           /admin-api/stats answered 200 immediately afterwards. He pressed
           Sign out, was shown a blank 404, AND WAS STILL SIGNED IN -- on a
           shared machine that is the whole of the damage.

           Derived off window.location.pathname, the way fixAdminApiUrl()
           already derives the api base: this console is served AT the admin
           path, so the browser is standing on it and nothing is disclosed by
           reading it back. Never from a setting. */
        var base=window.location.pathname.replace(/\/+$/,'');
        try{ await fetch(base+'/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}
        location.href=base+'/login';
      };
      var chip=top.querySelector('.userchip');
      if(chip) top.insertBefore(b, chip); else top.appendChild(b);
    }
  }

  /* ---------- CSP fallback ----------
     Some sandboxed previews (e.g. Claude's) block inline on* handlers via CSP,
     which stops the catalog's inline onclick="openProduct(n)" from firing. We
     feature-detect that and, only when inline handlers are blocked, delegate the
     key inline actions. In a normal deployment inline handlers work, so this
     never installs and nothing runs twice. */
  (function(){
    var inlineWorks=false;
    try{
      var t=document.createElement('button'); t.setAttribute('onclick','window.__kbbInline=1');
      (document.body||document.documentElement).appendChild(t); t.click();
      inlineWorks=(window.__kbbInline===1); if(t.parentNode) t.parentNode.removeChild(t); try{delete window.__kbbInline;}catch(e){}
    }catch(e){}
    if(inlineWorks) return;
    document.addEventListener('click', function(e){
      var el = e.target && e.target.closest ? e.target.closest('[onclick]') : null; if(!el) return;
      var code = el.getAttribute('onclick')||''; var m;
      if((m=code.match(/openProduct\((-?\d+)\)/)))      { e.preventDefault(); try{ window.openProduct(parseInt(m[1],10)); }catch(x){} return; }
      if((m=code.match(/go\('([^']+)'\)/)))             { e.preventDefault(); try{ window.go(m[1]); }catch(x){} return; }
      if(/closeModal\(\)/.test(code))                   { e.preventDefault(); try{ window.closeModal(); }catch(x){} return; }
      if(/this\.parentElement\.classList\.toggle/.test(code)) { try{ el.parentElement.classList.toggle('col'); }catch(x){} return; }
      if((m=code.match(/toast\('([^']*)'\)/)))          { try{ window.toast(m[1]); }catch(x){} return; }
      try{ (new Function('event', code)).call(el, e); }catch(x){}   // best-effort; may be blocked without 'unsafe-eval'
    }, false);
  })();

  /* ---------- boot ---------- */
  loadCatalog();
  if(cur==='dash') hydrateDash();
  addLogout();
})();
