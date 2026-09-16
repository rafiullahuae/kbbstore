{{--
    Reviews - Assign / Duplicate (Lane BE).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens this screen borrows.

    WHAT THIS SCREEN IS FOR. 'rev-assign' was an entry in REV_SRC pointing at
    kbb-admin-assign.html, a standalone file this repo has never shipped, so the
    screen rendered the "isn't installed yet" card.

    MOVE is the everyday job: a review landed on the wrong product, usually
    because the WooCommerce import matched the wrong post or a shopper reviewed
    the bundle instead of the item.

    COPY IS THE ONE WITH A CAVEAT ON IT, and the caveat is printed where the
    button is rather than in a help page. Copying a review onto a second product
    makes it look as though a customer reviewed a product they did not review --
    the same objection this repo already recorded, in app.blade.php's
    NOT_BUILT_NOTE, for why Bulk Add and Bulk Likes were deliberately not built.
    Copy exists because there is a legitimate version of it (one product listed
    twice, a 30ml and a 50ml, a product replaced by its successor) and it is the
    owner's store; what the screen refuses to do is let that decision be made by
    accident. A copy lands unapproved unless the owner deliberately says
    otherwise.

    NO NAV ENTRY IS ADDED HERE: 'rev-assign' is already in app.blade.php's NAV
    const and in TITLES. The id is also added to LIVE_RENDERED in app.blade.php,
    which is the other half of the takeover and is not optional.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Assign / Duplicate. Every rule is prefixed ras- and every id is ras-, so this
   file can never restyle or collide with another screen in the console.

   min-width:0 on the grid AND on its children is load-bearing: a grid item's
   default min-width is auto, so a card refuses to shrink below its widest child
   and drags the whole column past the viewport with no way to scroll back. The
   review list is exactly such a child -- its excerpts are whole sentences --
   which is why it gets its own scroller rather than widening the page.
--------------------------------------------------------------------------- */
.ras-wrap{display:grid;gap:16px;min-width:0;max-width:1400px}
.ras-wrap > *{min-width:0}

.ras-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.ras-legend{font-weight:650;font-size:14px;margin:0 0 3px}
.ras-legend-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0 0 14px;max-width:80ch;line-height:1.5}
.ras-title{font-weight:650;font-size:15px;margin:0}
.ras-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:3px 0 0;max-width:80ch;line-height:1.5}

.ras-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;min-width:0;margin-bottom:12px}
.ras-in,.ras-sel{padding:8px 10px;font:inherit;border:1px solid var(--border,#e6e6e6);border-radius:9px;
                 background:transparent;color:inherit;max-width:100%}
.ras-in{flex:1 1 220px;min-width:0}

.ras-btn{appearance:none;border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;
         font:inherit;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer}
.ras-btn[disabled]{opacity:.55;cursor:default}
.ras-ghost{background:transparent;color:inherit;border-color:var(--border,#e6e6e6)}
.ras-danger{border-color:#d9a13d;background:transparent;color:inherit}
.ras-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.ras-banner{border:1px solid #d9534f;border-radius:10px;padding:11px 13px;font-size:13px;line-height:1.5;
            color:#b3312c;background:rgba(217,83,79,.07)}
.ras-banner.ras-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);background:rgba(21,168,90,.07)}

.ras-scroll{overflow:auto;-webkit-overflow-scrolling:touch;max-height:420px;
            border:1px solid var(--border,#e6e6e6);border-radius:10px;min-width:0}
.ras-tab{width:100%;border-collapse:collapse;font-size:12.5px;min-width:520px}
.ras-tab th,.ras-tab td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--border,#e6e6e6);
                        vertical-align:top}
.ras-tab th{font-weight:650;position:sticky;top:0;background:var(--surface,#fff);z-index:1}
.ras-tab tr:last-child td{border-bottom:0}
.ras-tab td.ras-pick{width:1%;white-space:nowrap}
.ras-tab td.ras-n{font-variant-numeric:tabular-nums;white-space:nowrap;width:1%;color:var(--ink-soft,#6b7280)}
.ras-tab tr.ras-sel-row{background:rgba(21,168,90,.06)}
.ras-ex{color:var(--ink-soft,#6b7280);display:block;margin-top:3px;line-height:1.45}

.ras-pill{display:inline-block;border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:1px 8px;
          font-size:11px;white-space:nowrap}
.ras-pill.ras-live{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a)}
.ras-pill.ras-wait{border-color:#d9a13d;color:#b8860b}

.ras-target{display:grid;gap:8px;min-width:0}
.ras-hit{display:flex;gap:10px;align-items:baseline;justify-content:space-between;flex-wrap:wrap;
         border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;min-width:0;
         background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer;width:100%}
.ras-hit:hover{border-color:var(--accent,#15a85a)}
.ras-hit.ras-on{border-color:var(--accent,#15a85a);box-shadow:inset 0 0 0 1px var(--accent,#15a85a)}
.ras-hit b{font-weight:650;min-width:0;overflow-wrap:anywhere}
.ras-hit span{color:var(--ink-soft,#6b7280);font-size:12px;white-space:nowrap}

.ras-note{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;max-width:80ch}
.ras-warn{border-left:3px solid #d9a13d;padding-left:11px}
.ras-empty{color:var(--ink-soft,#6b7280);font-size:12.5px;padding:14px 4px}

.ras-sw{position:relative;display:inline-block;width:42px;height:24px;flex:none}
.ras-sw input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer}
.ras-sw i{position:absolute;inset:0;border-radius:999px;background:rgba(127,127,127,.32);
          transition:background .16s ease;pointer-events:none;display:block}
.ras-sw i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
                 background:#fff;transition:transform .16s ease;box-shadow:0 1px 3px rgba(0,0,0,.28)}
.ras-sw input:checked + i{background:var(--accent,#15a85a)}
.ras-sw input:checked + i::after{transform:translateX(18px)}

.ras-row{display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;
         padding:11px 0;border-top:1px solid var(--border,#e6e6e6);min-width:0}
.ras-row:first-of-type{border-top:0}
.ras-row > *{min-width:0}
.ras-lab{font-size:13.5px;font-weight:600;max-width:72ch}
.ras-hint{color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px;line-height:1.45;max-width:72ch}
.ras-ctl{display:flex;align-items:center;gap:8px;justify-self:end;flex-wrap:wrap}

@media (max-width:560px){
  .ras-row{grid-template-columns:1fr}
  .ras-ctl{justify-self:start}
}
</style>

<script>
/* =========================================================================
   Reviews -> Assign / Duplicate (Lane BE)

   Four endpoints, all behind auth:admin:

     GET  /admin-api/review-assign/reviews   find reviews to act on
     GET  /admin-api/review-assign/products  find a destination
     POST /admin-api/review-assign/move      move them
     POST /admin-api/review-assign/copy      copy them

   THE RATING MATHS IS THE PART THAT MATTERS and it is the server's, not this
   file's: both writes call App\Support\ProductRating::refresh() for the product
   the review left AND the product it joined, which is the same helper the
   moderation screen calls. The response carries the destination's new score,
   and this screen prints it — a screen that only says "done" is a screen whose
   rating bug nobody notices.
   ========================================================================= */
(function(){
  'use strict';

  var SCREEN = 'rev-assign';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var reviews = [];      // the search results
  var products = [];     // the destination candidates
  var picked = {};       // review id -> true
  var target = null;     // the chosen destination product
  var toBusiness = false;
  var keepStatus = false;
  var banner = null;
  var result = null;     // the last move/copy response
  var busy = false;
  var working = false;
  var seq = 0;
  var pseq = 0;

  var query = {q: '', status: 'all'};
  var productQuery = '';

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, opts){
    var o = opts || {};
    o.headers = o.headers || {};
    o.headers['Accept'] = 'application/json';
    o.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    o.credentials = 'same-origin';

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, o);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  function chosen(){
    return Object.keys(picked).filter(function(k){ return picked[k]; }).map(Number);
  }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === SCREEN;
      });
      g.classList.toggle('open', has);
    });

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Reviews';
    if (title) title.textContent = 'Assign / Duplicate';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    render();
    loadReviews();
    loadProducts();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function loadReviews(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/review-assign/reviews?q=' + encodeURIComponent(query.q) +
                           '&status=' + encodeURIComponent(query.status));
      if (mine !== seq) return;
      reviews = body.reviews || [];
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      reviews = [];
      banner = {kind:'err', text: e.status === 404
        ? 'The assign endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load reviews (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function loadProducts(){
    var mine = ++pseq;

    try {
      var body = await api('/review-assign/products?q=' + encodeURIComponent(productQuery));
      if (mine !== pseq) return;
      products = body.products || [];
      render();
    } catch (e) {
      if (mine !== pseq) return;
      products = [];
      render();
    }
  }

  async function run(action){
    var ids = chosen();

    if (!ids.length) {
      banner = {kind:'err', text:'Pick at least one review first.'};
      render();
      return;
    }

    if (!target && !toBusiness) {
      banner = {kind:'err', text:'Choose the product to ' + action + ' them to.'};
      render();
      return;
    }

    working = true;
    banner = null;
    render();

    var payload = {ids: ids};
    if (toBusiness) payload.to_business = '1';
    else payload.product_id = target.id;
    if (action === 'copy') payload.status = keepStatus ? 'keep' : 'pending';

    try {
      result = await api('/review-assign/' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });
      picked = {};
      banner = {kind:'ok', text: action === 'move'
        ? 'Moved ' + result.affected + (result.affected === 1 ? ' review.' : ' reviews.')
        : 'Copied ' + result.affected + (result.affected === 1 ? ' review.' : ' reviews.')};
      say(action === 'move' ? 'Reviews moved' : 'Reviews copied');
      loadReviews();
      loadProducts();
    } catch (e) {
      var detail = '';
      if (e.body && e.body.message) detail = ' ' + e.body.message;
      else if (e.body && e.body.errors) {
        var first = Object.keys(e.body.errors)[0];
        if (first) detail = ' ' + e.body.errors[first][0];
      }
      banner = {kind:'err', text:'Could not ' + action + ' those reviews (' + (e.status || 'network') + ').' + detail};
    } finally {
      working = false;
      render();
    }
  }

  /* ---------------------------------------------------------------- markup */
  function statusPill(status){
    if (status === 'approved') return '<span class="ras-pill ras-live">On the shop</span>';
    if (status === 'pending') return '<span class="ras-pill ras-wait">Waiting</span>';
    return '<span class="ras-pill">Refused</span>';
  }

  function reviewRows(){
    if (!reviews.length) {
      return '<div class="ras-empty">' +
        (busy ? 'Looking…' : 'No reviews match that. Try a reviewer\'s name, a phrase from the review, ' +
        'a product name, or a review id.') + '</div>';
    }

    return '<div class="ras-scroll"><table class="ras-tab"><thead><tr>' +
      '<th></th><th>Review</th><th>On</th><th>Stars</th><th>State</th>' +
      '</tr></thead><tbody>' +
      reviews.map(function(r){
        var on = !!picked[r.id];
        return '<tr class="' + (on ? 'ras-sel-row' : '') + '">' +
          '<td class="ras-pick"><input type="checkbox" data-ras-pick="' + esc(r.id) + '"' +
          (on ? ' checked' : '') + '></td>' +
          '<td><b>' + esc(r.author) + '</b>' +
          (r.title ? ' — ' + esc(r.title) : '') +
          '<span class="ras-ex">' + esc(r.excerpt) + (r.truncated ? '…' : '') + '</span></td>' +
          '<td>' + (r.product ? esc(r.product) : '<i>the shop itself</i>') + '</td>' +
          '<td class="ras-n">' + esc(r.rating) + '</td>' +
          '<td>' + statusPill(r.status) + '</td>' +
          '</tr>';
      }).join('') +
      '</tbody></table></div>';
  }

  function targetCard(){
    var hits = products.map(function(p){
      var on = !toBusiness && target && target.id === p.id;
      return '<button type="button" class="ras-hit' + (on ? ' ras-on' : '') + '" data-ras-target="' +
        esc(p.id) + '"><b>' + esc(p.name) + (p.sku ? ' <span>' + esc(p.sku) + '</span>' : '') + '</b>' +
        '<span>' + esc(p.review_count) + (p.review_count === 1 ? ' review' : ' reviews') +
        (p.review_count ? ' · ' + esc(Number(p.rating).toFixed(1)) + '★' : '') + '</span></button>';
    }).join('');

    return '<div class="ras-card">' +
      '<p class="ras-legend">2 · Where to</p>' +
      '<p class="ras-legend-sub">The product the chosen reviews should belong to. Search by name, SKU or ' +
      'product id — the most-reviewed products are listed first.</p>' +
      '<div class="ras-tools">' +
      '<input class="ras-in" type="search" id="ras-psearch" placeholder="Search products" value="' +
      esc(productQuery) + '">' +
      '</div>' +
      '<div class="ras-target">' + (hits || '<div class="ras-empty">No products match that.</div>') + '</div>' +
      '<div class="ras-row" style="margin-top:6px"><div><div class="ras-lab">Or take them off every product</div>' +
      '<div class="ras-hint">A review of the shop itself rather than of something you sell. It stops ' +
      'counting towards any product\'s star rating.</div></div>' +
      '<div class="ras-ctl"><label class="ras-sw"><input type="checkbox" id="ras-business"' +
      (toBusiness ? ' checked' : '') + '><i></i></label></div></div>' +
      '</div>';
  }

  function actionCard(){
    var n = chosen().length;
    var where = toBusiness ? 'the shop itself' : (target ? target.name : 'nowhere yet');

    return '<div class="ras-card">' +
      '<p class="ras-legend">3 · Do it</p>' +
      '<p class="ras-legend-sub"><span id="ras-count">' + esc(n) + (n === 1 ? ' review' : ' reviews') +
      '</span> chosen, going to <b id="ras-where">' + esc(where) + '</b>. Both products\' star ratings are ' +
      'recalculated straight away — the one losing the review and the one gaining it.</p>' +
      '<div class="ras-actions">' +
      '<button class="ras-btn" id="ras-move"' + (working ? ' disabled' : '') + '>' +
      (working ? 'Working…' : 'Move') + '</button>' +
      '<button class="ras-btn ras-danger" id="ras-copy"' + (working ? ' disabled' : '') + '>Copy</button>' +
      '</div>' +
      '<div class="ras-row" style="margin-top:12px"><div><div class="ras-lab">A copy goes live immediately</div>' +
      '<div class="ras-hint">Off — the safe default — a copy waits for you under All Reviews, exactly like ' +
      'a new review from a shopper. On, it appears on the product page at once and changes that product\'s ' +
      'star rating.</div></div>' +
      '<div class="ras-ctl"><label class="ras-sw"><input type="checkbox" id="ras-keep"' +
      (keepStatus ? ' checked' : '') + '><i></i></label></div></div>' +
      '<p class="ras-note ras-warn" style="margin-top:12px"><b>Copy, honestly.</b> Moving a review is ' +
      'housekeeping — it puts a real review where it belongs. Copying one puts a customer\'s words under a ' +
      'product they did not actually review, and that review feeds the star rating Google shows for your ' +
      'shop. It is genuinely useful when the same product is listed twice, or in two sizes, or has been ' +
      'replaced by its own successor. It is worth being sure that is the case here.</p>' +
      '</div>';
  }

  function resultCard(){
    var r = result;
    var p = r.product;

    return '<div class="ras-card">' +
      '<p class="ras-legend">' + (r.action === 'move' ? 'Moved' : 'Copied') + '</p>' +
      '<p class="ras-legend-sub">' + esc(r.affected) +
      (r.affected === 1 ? ' review' : ' reviews') + ' ' +
      (r.action === 'move' ? 'moved' : 'copied') + '.' +
      (r.action === 'copy' && r.status === 'pending'
        ? ' Waiting for approval under All Reviews — nothing has changed on the shop yet.'
        : '') +
      (r.missing && r.missing.length
        ? ' ' + esc(r.missing.length) + ' of them no longer existed and were skipped.'
        : '') +
      '</p>' +
      (p
        ? '<div class="ras-target"><div class="ras-hit"><b>' + esc(p.name) + '</b><span>now ' +
          esc(p.review_count) + (p.review_count === 1 ? ' review' : ' reviews') +
          (p.review_count ? ' · ' + esc(Number(p.rating).toFixed(1)) + '★' : '') +
          '</span></div></div>'
        : '<p class="ras-note">They are no longer counted towards any product.</p>') +
      '</div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="ras-wrap">';

    html += '<div class="ras-card"><div>' +
      '<h2 class="ras-title">Assign / Duplicate</h2>' +
      '<p class="ras-sub">Move a review to the product it actually belongs to, or copy one onto a second ' +
      'listing of the same product.</p></div></div>';

    if (banner) {
      html += '<div class="ras-banner' + (banner.kind === 'ok' ? ' ras-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    html += '<div class="ras-card">' +
      '<p class="ras-legend">1 · Which reviews</p>' +
      '<p class="ras-legend-sub">Search by reviewer, by a phrase from the review, by product, or by review ' +
      'id. Tick the ones to act on.</p>' +
      '<div class="ras-tools">' +
      '<input class="ras-in" type="search" id="ras-search" placeholder="Search reviews" value="' +
      esc(query.q) + '">' +
      '<select class="ras-sel" id="ras-status">' +
      [['all','Any state'],['approved','On the shop'],['pending','Waiting'],['spam','Refused']].map(function(o){
        return '<option value="' + esc(o[0]) + '"' + (query.status === o[0] ? ' selected' : '') + '>' +
               esc(o[1]) + '</option>';
      }).join('') +
      '</select>' +
      '</div>' + reviewRows() + '</div>';

    html += targetCard();
    html += actionCard();
    if (result) html += resultCard();

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    document.querySelectorAll('[data-ras-pick]').forEach(function(el){
      el.onchange = function(){
        picked[el.dataset.rasPick] = el.checked;

        /* NOT render(). Re-rendering the whole screen on a tick throws away the
           list's scroll position and the caret in the search box, and on a
           phone it takes the checkbox out from under the finger mid-tap. The
           row highlight is a class toggle and the count is one text node. */
        var tr = el.closest('tr');
        if (tr) tr.classList.toggle('ras-sel-row', el.checked);

        refreshCount();
      };
    });

    var search = document.querySelector('#ras-search');
    if (search) search.oninput = function(){ query.q = search.value; debounce(loadReviews); };

    var status = document.querySelector('#ras-status');
    if (status) status.onchange = function(){ query.status = status.value; loadReviews(); };

    var psearch = document.querySelector('#ras-psearch');
    if (psearch) psearch.oninput = function(){ productQuery = psearch.value; debounce(loadProducts); };

    document.querySelectorAll('[data-ras-target]').forEach(function(el){
      el.onclick = function(){
        var id = Number(el.dataset.rasTarget);
        target = products.filter(function(p){ return p.id === id; })[0] || null;
        toBusiness = false;
        render();
      };
    });

    var business = document.querySelector('#ras-business');
    if (business) business.onchange = function(){
      toBusiness = business.checked;
      if (toBusiness) target = null;
      render();
    };

    var keep = document.querySelector('#ras-keep');
    if (keep) keep.onchange = function(){ keepStatus = keep.checked; };

    var move = document.querySelector('#ras-move');
    if (move) move.onclick = function(){ run('move'); };

    var copy = document.querySelector('#ras-copy');
    if (copy) copy.onclick = function(){ run('copy'); };
  }

  /* The two live figures in the "Do it" card, updated in place.
     textContent, not innerHTML: the product name is the owner's own catalogue
     text and has no business being parsed as markup here. */
  function refreshCount(){
    var n = chosen().length;

    var count = document.querySelector('#ras-count');
    if (count) count.textContent = n + (n === 1 ? ' review' : ' reviews');

    var where = document.querySelector('#ras-where');
    if (where) where.textContent = toBusiness ? 'the shop itself' : (target ? target.name : 'nowhere yet');
  }

  /* One timer for the two search boxes. Without it every keystroke is a request
     and the answers arrive out of order; the sequence guards in loadReviews()
     and loadProducts() make that harmless but not free. */
  var timer = null;
  function debounce(fn){
    if (timer) clearTimeout(timer);
    timer = setTimeout(fn, 260);
  }

  function bootIfCurrent(){
    var active = document.querySelector('.side .nav-item.on');
    if (active && active.dataset.go === SCREEN) { render(); loadReviews(); loadProducts(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
</script>
@endverbatim
