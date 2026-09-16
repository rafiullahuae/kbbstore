{{--
    Reviews - Bulk Add and Reviews - Bulk Likes (Lane BD).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens these screens borrow. The shape is deliberately the same as
    admin/partials/review-settings-screen.blade.php; that is the precedent.

    TWO SCREENS, ONE FILE, ON PURPOSE. The repo's convention is one screen per
    partial and the reason for it is that several lanes edit app.blade.php at
    once. That reason is served here by the file being separate at all. These
    two ids share a controller, a product picker, a notice block, an API helper
    and every line of CSS; split across two files that is one copy each of all
    of it, and two copies drift. They are one feature with two entry points.

    WHAT THESE SCREENS DO. Bulk Add writes rows into `reviews` that no customer
    submitted. Bulk Likes raises `reviews.helpful` — the "was this helpful?"
    counter — on rows nobody voted for. The owner asked for both after being
    told what they are. Each screen carries ONE plain statement of what the
    content is, at the top, because whoever operates this after the owner needs
    to know what they are looking at. One statement, not a confirmation
    gauntlet: it does not block, repeat, or ask again.

    NO NAV ENTRY IS ADDED HERE. Both ids are ALREADY in app.blade.php's NAV
    const (Reviews - Bulk Add, Reviews - Bulk Likes) and in TITLES. Appending a
    button would give the owner each row twice. All this file does is take over
    the two routes.

    'rev-add' AND 'rev-likes' ARE ALSO ADDED TO LIVE_RENDERED in app.blade.php,
    which is the other half and is not optional. Without it, go('rev-add')
    reaches renderReviewFrame() -> mountFrame(), which fires a HEAD request for
    kbb-admin-bulkadd.html -- a standalone file this repo has never shipped --
    on every single visit, and paints the not-built card a moment before this
    screen overwrites it. That is what Lane AM did for 'rev-all', Lane BB for
    'rev-settings' and Lane BC for 'htmlblocks', and what the LANE AV comment
    block above LIVE_RENDERED describes as path 1.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the braces inside JavaScript
    template literals are not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Bulk Add / Bulk Likes. Every rule is prefixed rbk- and every id is rbk-, so
   this file can never restyle or collide with another screen in the console.
   The console is one document: an unprefixed .card or #save here would reach
   into whatever else is mounted.

   Same layout rule as the Review Settings and Coupons screens: nothing may be
   wider than its column at 390px, because the owner reviews on a phone.
   Measured on #content, not on documentElement -- the document metric is
   structurally blind here because the console's shell is what scrolls, not the
   page, and body{overflow-x:hidden} hides the difference anyway.
--------------------------------------------------------------------------- */
/* min-width:0 on the grid AND on its children is load-bearing, not tidiness.
   A grid item's default min-width is auto -- "at least as wide as my content"
   -- so a card holding a wide table refuses to shrink below it and drags the
   whole column past the viewport with no way to scroll back. That shipped on
   the Coupons screen; see tests/Feature/AdminScreenGridOverflowTest.php.

   1400px to match .rvs-wrap and .ecwrap on the sibling settings screens rather
   than a narrower band: the owner has already reported the console leaving
   empty space down the right-hand side. Prose is capped separately below so
   widening the column does not set a hint to a 1250px line. */
.rbk-wrap{display:grid;gap:16px;min-width:0;max-width:1400px}
.rbk-wrap > *{min-width:0}

.rbk-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rbk-legend{font-weight:650;font-size:14px;margin:0 0 3px}
.rbk-legend-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0 0 14px;max-width:80ch}
.rbk-title{font-weight:650;font-size:15px;margin:0}
.rbk-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:3px 0 0;max-width:80ch}

/* The one notice. A quiet panel, not an alert: it states what the content is
   and then gets out of the way. It does not block anything and it is not
   repeated anywhere else on the screen. */
.rbk-notice{border:1px solid var(--border,#e6e6e6);border-left:3px solid var(--ink-soft,#6b7280);
            border-radius:var(--r,12px);padding:14px 16px;background:var(--surface,#fff);min-width:0}
.rbk-notice h4{margin:0 0 6px;font-size:13.5px;font-weight:650}
.rbk-notice p{margin:0 0 8px;font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);max-width:88ch}
.rbk-notice p:last-child{margin-bottom:0}
.rbk-notice code{font-size:11.5px;padding:1px 5px;border-radius:5px;background:rgba(127,127,127,.14)}

/* One row per control. Label column flexible, control column fixed-ish; at
   560px they stack instead of squeezing the control to nothing. */
.rbk-row{display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;
         padding:11px 0;border-top:1px solid var(--border,#e6e6e6);min-width:0}
.rbk-row:first-of-type{border-top:0}
.rbk-row > *{min-width:0}
.rbk-lab{font-size:13.5px;font-weight:600;max-width:72ch}
.rbk-hint{color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px;line-height:1.45;max-width:72ch}
.rbk-ctl{display:flex;align-items:center;gap:8px;justify-self:end;flex-wrap:wrap}
.rbk-row.rbk-wide{grid-template-columns:1fr}
.rbk-row.rbk-wide .rbk-ctl{justify-self:stretch;display:block}

@media (max-width:560px){
  .rbk-row{grid-template-columns:1fr}
  .rbk-ctl{justify-self:start}
}

/* Controls. Widths are capped at 100% so none of them can be the element that
   pushes the column out. */
.rbk-num{width:104px;max-width:100%;padding:7px 9px;font:inherit;font-variant-numeric:tabular-nums;
         border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.rbk-sel{max-width:100%;padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
         border-radius:9px;background:transparent;color:inherit}
.rbk-txt{width:100%;max-width:100%;box-sizing:border-box;padding:8px 10px;font:inherit;
         border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.rbk-area{width:100%;max-width:100%;box-sizing:border-box;min-height:132px;resize:vertical;
          padding:9px 11px;font:inherit;font-size:13px;line-height:1.5;
          border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.rbk-date{max-width:100%;padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
          border-radius:9px;background:transparent;color:inherit}

/* The switch. A real checkbox underneath so it is keyboard-reachable and has a
   focus ring; the visual is the label. Same construction as .rvs-sw. */
.rbk-sw{position:relative;display:inline-block;width:42px;height:24px;flex:none}
.rbk-sw input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer}
.rbk-sw i{position:absolute;inset:0;border-radius:999px;background:rgba(127,127,127,.32);
          transition:background .16s ease;pointer-events:none;display:block}
.rbk-sw i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
                 background:#fff;transition:transform .16s ease;box-shadow:0 1px 3px rgba(0,0,0,.28)}
.rbk-sw input:checked + i{background:var(--accent,#15a85a)}
.rbk-sw input:checked + i::after{transform:translateX(18px)}
.rbk-sw input:focus-visible + i{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

/* The product picker. A bounded, scrolling list -- a catalogue of several
   thousand is not a dropdown, and an unbounded list is the element that makes
   the page scroll for ever. */
.rbk-pick{border:1px solid var(--border,#e6e6e6);border-radius:10px;min-width:0;overflow:hidden}
.rbk-pick-search{display:flex;gap:8px;padding:9px;border-bottom:1px solid var(--border,#e6e6e6);min-width:0}
.rbk-pick-list{max-height:260px;overflow-y:auto;overflow-x:hidden;min-width:0}
.rbk-pick-row{display:flex;gap:10px;align-items:center;padding:8px 11px;font-size:13px;
              border-top:1px solid var(--border,#e6e6e6);min-width:0;cursor:pointer}
.rbk-pick-row:first-child{border-top:0}
.rbk-pick-row input{flex:none;margin:0}
.rbk-pick-name{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rbk-pick-meta{flex:none;color:var(--ink-soft,#6b7280);font-size:11.5px;font-variant-numeric:tabular-nums}
.rbk-pick-empty{padding:14px;color:var(--ink-soft,#6b7280);font-size:12.5px}

/* Selected products, shown outside the scroller so a selection made and then
   scrolled past is still visible. */
.rbk-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;min-width:0}
.rbk-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:4px 6px 4px 10px;
          border:1px solid var(--border,#e6e6e6);border-radius:999px;font-size:12px;min-width:0}
.rbk-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.rbk-chip button{flex:none;border:0;background:transparent;color:inherit;cursor:pointer;
                 font:inherit;line-height:1;padding:2px 4px;border-radius:50%;opacity:.7}
.rbk-chip button:hover{opacity:1}

/* The star mix. Five weights, each with the share it works out to, so the owner
   sees the distribution rather than five numbers whose total they have to do in
   their head. minmax(min(...),1fr) so a track can give way on a phone. */
.rbk-mix{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(132px,100%),1fr));gap:10px;min-width:0}
.rbk-mix > *{min-width:0}
.rbk-mix label{display:block;font-size:12.5px;font-weight:600;margin-bottom:4px}
.rbk-mix .rbk-num{width:100%}
.rbk-mix-pct{color:var(--ink-soft,#6b7280);font-size:11.5px;margin-top:3px;font-variant-numeric:tabular-nums}

.rbk-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.rbk-btn{padding:9px 16px;font:inherit;font-weight:600;border-radius:10px;cursor:pointer;
         border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff}
.rbk-btn[disabled]{opacity:.55;cursor:default}
.rbk-btn.rbk-ghost{background:transparent;color:inherit;border-color:var(--border,#e6e6e6)}
.rbk-note{color:var(--ink-soft,#6b7280);font-size:12.5px}
.rbk-count{font-variant-numeric:tabular-nums;font-weight:650}
.rbk-over{color:#b4443c}

.rbk-banner{border:1px solid #b4443c;color:#b4443c;border-radius:var(--r,12px);
            padding:12px 14px;font-size:13px;line-height:1.5;min-width:0}
.rbk-banner.rbk-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a)}

/* The result table. overflow-x:auto alone is not a scroller: without a
   min-width it grows to its content and scrolls nothing. */
.rbk-scroll{overflow-x:auto;min-width:0;max-width:100%}
.rbk-table{border-collapse:collapse;width:100%;font-size:12.5px}
.rbk-table th,.rbk-table td{text-align:left;padding:7px 10px;border-top:1px solid var(--border,#e6e6e6);
                            white-space:nowrap}
.rbk-table th{font-weight:650;color:var(--ink-soft,#6b7280)}
.rbk-table td.rbk-n{text-align:right;font-variant-numeric:tabular-nums}
</style>

<script>
/* =========================================================================
   Reviews -> Bulk Add (rev-add) and Reviews -> Bulk Likes (rev-likes)

   Lane BD. Both screens talk to App\Http\Controllers\Admin\ReviewBulkApiController
   through routes/review-bulk-admin.php, which the integrator mounts inside the
   admin-api group -- so every call below is behind auth:admin.

   EVERY CONTROL ON THESE SCREENS IS READ BY THE SERVER. The inventory, and the
   request field each one sets:

     Bulk Add
       product picker      product_ids[]   the products the rows are attached to
       per product         per_product     rows created for each of them
       star mix (5 boxes)  ratings{1..5}   weights for the rating drawn per row
       dated between       date_from/to    created_at is spread across the window
       status              status          pending | approved
       verified badge      verified        reviews.verified -> the "Verified" tick
       names / titles / bodies
                           authors[] titles[] bodies[]   the text pools

     Bulk Likes
       scope               scope           product | ids
       product picker      product_ids[]   |  review ids box   ids[]
       only                status          approved (default) | pending | spam | any
       at most             limit           ceiling on rows touched
       mode                mode            add (to current) | set (to)
       between / and       min, max        the range a per-row value is drawn from

   There is deliberately no "generate the review text for me" control. The text
   is the owner's; a pool of canned sentences shipped in this file would put
   words in their shop that neither they nor a customer chose. And there is no
   initial-likes control on Bulk Add: that is what Bulk Likes is for, and one
   control in one place beats the same control in two that can disagree.
   ========================================================================= */
(function(){
  'use strict';

  var ADD = 'rev-add';
  var LIKES = 'rev-likes';
  var SCREENS = [ADD, LIKES];

  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var screen = null;      // which of the two is showing
  var opts = null;        // {products, limits, statuses, source}
  var busy = false;
  var working = false;    // a create/apply in flight
  var banner = null;      // {kind:'err'|'ok', text}
  var result = null;      // the last server response worth showing
  var seq = 0;            // guards against an older response landing last
  var search = '';
  var searchTimer = null;

  /* Selected product ids, kept as a map so a selection survives a re-search
     that no longer lists the product. id -> name. */
  var picked = {};

  /* The Bulk Add form. Seeded with values that are legal on their own, so the
     screen is never in a state where pressing the button 422s for something the
     owner never touched. */
  var add = {
    per_product: 10,
    status: 'pending',
    verified: false,
    ratings: {5: 70, 4: 22, 3: 6, 2: 1, 1: 1},
    date_from: '',
    date_to: '',
    authors: '',
    titles: '',
    bodies: ''
  };

  var likes = {
    scope: 'product',
    ids: '',
    status: 'approved',
    limit: 200,
    mode: 'add',
    min: 1,
    max: 8
  };

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, o){
    o = o || {};
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

  /* Lines of a textarea, trimmed, blanks dropped. The server trims and strips
     tags again -- this is so the COUNT the screen prints is the count the
     server will see, not the number of newlines. */
  function lines(text){
    return String(text || '').split('\n')
      .map(function(l){ return l.trim(); })
      .filter(function(l){ return l !== ''; });
  }

  function ids(text){
    return String(text || '').split(/[^0-9]+/)
      .filter(function(v){ return v !== ''; })
      .map(function(v){ return parseInt(v, 10); })
      .filter(function(v){ return !isNaN(v) && v > 0; });
  }

  function pickedIds(){
    return Object.keys(picked).map(function(k){ return parseInt(k, 10); });
  }

  function limits(){
    return (opts && opts.limits) || {
      max_rows: 200, max_targets: 50, max_pool: 500,
      likes_max_reviews: 500, likes_ceiling: 9999
    };
  }

  /* How many rows Bulk Add would create right now. Printed live beside the
     button, because the bound is on the TOTAL and neither field is wrong on
     its own -- discovering it with a 422 after filling in three textareas is
     the friction this avoids. */
  function plannedRows(){
    return pickedIds().length * Math.max(0, parseInt(add.per_product, 10) || 0);
  }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (SCREENS.indexOf(id) === -1) return previousGo.apply(this, arguments);

    /* The console's own go() is NOT called for these ids. It would reach
       renderReviewFrame(), and although both are in LIVE_RENDERED -- so
       mountFrame() paints the startup message instead of probing for a file
       that is not there -- there is no reason to paint it at all when this
       screen is about to draw. The nav, crumb and title below are the only
       things go() would have done for us. */
    screen = id;
    result = null;
    banner = null;

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === id);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === id;
      });
      g.classList.toggle('open', has);
    });

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Reviews';
    if (title) title.textContent = (id === ADD) ? 'Bulk Add' : 'Bulk Likes';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/review-bulk/options' + (search ? ('?q=' + encodeURIComponent(search)) : ''));
      if (mine !== seq) return;
      opts = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      opts = null;
      /* A 404 here almost always means the route file shipped without its
         clear_caches migration having run, so the compiled route table does not
         know these paths yet. Said plainly rather than rendering an empty
         screen, which reads as "there are no products". */
      banner = {kind:'err', text: e.status === 404
        ? 'The bulk review endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load products (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  function failureText(e){
    var detail = '';
    if (e.body && e.body.errors) {
      var first = Object.keys(e.body.errors)[0];
      if (first) detail = ' ' + e.body.errors[first][0];
    } else if (e.body && e.body.message) {
      detail = ' ' + e.body.message;
    }
    return '(' + (e.status || 'network') + ').' + detail;
  }

  async function createReviews(){
    if (working) return;

    var products = pickedIds();
    if (!products.length) { banner = {kind:'err', text:'Pick at least one product.'}; return render(); }

    var authors = lines(add.authors);
    var bodies = lines(add.bodies);
    if (!authors.length) { banner = {kind:'err', text:'Add at least one reviewer name.'}; return render(); }
    if (!bodies.length) { banner = {kind:'err', text:'Add at least one review body.'}; return render(); }

    working = true;
    banner = null;
    render();

    try {
      var payload = {
        product_ids: products,
        per_product: parseInt(add.per_product, 10) || 0,
        status: add.status,
        verified: !!add.verified,
        ratings: add.ratings,
        authors: authors,
        bodies: bodies,
        titles: lines(add.titles)
      };
      if (add.date_from) payload.date_from = add.date_from;
      if (add.date_to) payload.date_to = add.date_to;

      var body = await api('/review-bulk/add', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });

      result = body;
      banner = {kind:'ok', text: body.created + ' review' + (body.created === 1 ? '' : 's') +
        ' created as ' + body.status + '. Tagged source ' + body.source + '.'};
      say('Created ' + body.created + ' reviews');
    } catch (e) {
      result = null;
      banner = {kind:'err', text:'Could not create the reviews ' + failureText(e)};
    } finally {
      working = false;
      render();
    }
  }

  async function applyLikes(){
    if (working) return;

    var payload = {
      scope: likes.scope,
      mode: likes.mode,
      min: parseInt(likes.min, 10) || 0,
      max: parseInt(likes.max, 10) || 0,
      status: likes.status,
      limit: parseInt(likes.limit, 10) || 1
    };

    if (likes.scope === 'product') {
      payload.product_ids = pickedIds();
      if (!payload.product_ids.length) {
        banner = {kind:'err', text:'Pick at least one product.'}; return render();
      }
    } else {
      payload.ids = ids(likes.ids);
      if (!payload.ids.length) {
        banner = {kind:'err', text:'Paste at least one review id.'}; return render();
      }
    }

    if (payload.max < payload.min) {
      banner = {kind:'err', text:'The top of the range must not be below the bottom.'}; return render();
    }

    working = true;
    banner = null;
    render();

    try {
      var body = await api('/review-bulk/likes', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });

      result = body;
      banner = {kind:'ok', text: body.note ? body.note
        : (body.affected + ' of ' + body.matched + ' matching review' +
           (body.matched === 1 ? '' : 's') + ' updated.')};
      say(body.affected + ' reviews updated');
    } catch (e) {
      result = null;
      banner = {kind:'err', text:'Could not apply the votes ' + failureText(e)};
    } finally {
      working = false;
      render();
    }
  }

  /* ---------------------------------------------------------------- markup */
  function row(label, hint, control, wide){
    return '<div class="rbk-row' + (wide ? ' rbk-wide' : '') + '"><div>' +
           '<div class="rbk-lab">' + esc(label) + '</div>' +
           '<div class="rbk-hint">' + hint + '</div></div>' +
           '<div class="rbk-ctl">' + control + '</div></div>';
  }

  function sw(key, on){
    return '<label class="rbk-sw"><input type="checkbox" data-rbk-bool="' + esc(key) + '"' +
           (on ? ' checked' : '') + '><i></i></label>';
  }

  function num(key, value, min, max){
    return '<input class="rbk-num" type="number" inputmode="numeric" data-rbk-int="' + esc(key) + '" ' +
           'min="' + min + '" max="' + max + '" value="' + esc(value) + '">';
  }

  function select(key, value, options){
    var out = options.map(function(o){
      return '<option value="' + esc(o[0]) + '"' + (String(value) === String(o[0]) ? ' selected' : '') +
             '>' + esc(o[1]) + '</option>';
    }).join('');
    return '<select class="rbk-sel" data-rbk-enum="' + esc(key) + '">' + out + '</select>';
  }

  /* The notice. One per screen, at the top, stating what the content is. It is
     not repeated further down and nothing on the screen waits on it. */
  function notice(){
    if (screen === ADD) {
      return '<div class="rbk-notice">' +
        '<h4>What this screen creates</h4>' +
        '<p>These are reviews <strong>you</strong> write, not reviews customers left. ' +
        'Once saved they are ordinary rows in your reviews table: they appear in the review ' +
        'section of the product page, and once approved they count toward the star average and ' +
        'review count on your shop cards and go into the <code>aggregateRating</code> this site ' +
        'publishes to Google.</p>' +
        '<p>Presenting reviews as customers&rsquo; own when no customer wrote them is restricted ' +
        'under UAE consumer-protection rules and under the EU rules that follow this shop&rsquo;s ' +
        'international orders. This screen does not check that for you.</p>' +
        '<p>Everything created here is stored with <code>source = ' +
        esc((opts && opts.source) || 'admin_bulk') + '</code>, so it can be found, exported or ' +
        'removed later from All&nbsp;Reviews &mdash; which is not true of anything typed in by hand.</p>' +
        '</div>';
    }

    return '<div class="rbk-notice">' +
      '<h4>What this screen changes</h4>' +
      '<p>A &ldquo;helpful&rdquo; count is the number of shoppers who pressed 👍 on a review. ' +
      'This screen writes those numbers directly, so what it stores is not a record of anyone ' +
      'having voted.</p>' +
      '<p>The counts show under each review on the product page and can order the review list ' +
      '(&ldquo;Most helpful first&rdquo; in Review&nbsp;Settings). They do <strong>not</strong> ' +
      'move the star rating &mdash; that is the average of the ratings themselves. Making a real ' +
      'review look more trusted than customers made it falls under the same consumer-protection ' +
      'rules as inventing one.</p>' +
      '</div>';
  }

  function picker(){
    if (!opts) {
      return '<div class="rbk-pick"><div class="rbk-pick-empty">' +
             (busy ? 'Loading products…' : 'Products are unavailable.') + '</div></div>';
    }

    var rows = opts.products.map(function(p){
      var on = Object.prototype.hasOwnProperty.call(picked, String(p.id));
      return '<label class="rbk-pick-row">' +
        '<input type="checkbox" data-rbk-pick="' + p.id + '" data-rbk-name="' + esc(p.name) + '"' +
        (on ? ' checked' : '') + '>' +
        '<span class="rbk-pick-name">' + esc(p.name) + '</span>' +
        '<span class="rbk-pick-meta">' + p.review_count + ' rev · ' +
        (p.rating ? p.rating.toFixed(2) : '—') + '</span>' +
        '</label>';
    }).join('');

    if (!rows) {
      rows = '<div class="rbk-pick-empty">No products match “' + esc(search) + '”.</div>';
    }

    var chips = pickedIds().map(function(id){
      return '<span class="rbk-chip"><span>' + esc(picked[id]) + '</span>' +
             '<button type="button" data-rbk-unpick="' + id + '" title="Remove">×</button></span>';
    }).join('');

    return '<div class="rbk-pick">' +
      '<div class="rbk-pick-search">' +
      '<input class="rbk-txt" type="search" id="rbk-search" placeholder="Search products by name, SKU or id" ' +
      'value="' + esc(search) + '"></div>' +
      '<div class="rbk-pick-list">' + rows + '</div>' +
      '</div>' +
      (opts.truncated ? '<div class="rbk-hint">Showing the first ' + opts.products.length +
        ' products — search to reach the rest.</div>' : '') +
      (chips ? '<div class="rbk-chips">' + chips + '</div>' : '');
  }

  function starMix(){
    var total = [1,2,3,4,5].reduce(function(sum, s){
      return sum + (parseInt(add.ratings[s], 10) || 0);
    }, 0);

    var cells = [5,4,3,2,1].map(function(s){
      var w = parseInt(add.ratings[s], 10) || 0;
      var pct = total > 0 ? Math.round(w / total * 100) : 0;
      return '<div><label>' + s + ' ★</label>' +
        '<input class="rbk-num" type="number" inputmode="numeric" data-rbk-star="' + s + '" ' +
        'min="0" max="1000" value="' + w + '">' +
        '<div class="rbk-mix-pct">' + pct + '% of reviews</div></div>';
    }).join('');

    return '<div class="rbk-mix">' + cells + '</div>' +
      (total > 0 ? '' : '<div class="rbk-hint rbk-over">Give at least one rating a share above zero.</div>');
  }

  function addScreen(){
    var L = limits();
    var planned = plannedRows();
    var over = planned > L.max_rows;

    var html = '';

    html += '<div class="rbk-card"><h2 class="rbk-title">Bulk Add</h2>' +
      '<p class="rbk-sub">Create review rows against one or more products, from names and text ' +
      'you supply.</p></div>';

    html += notice();

    if (banner) {
      html += '<div class="rbk-banner' + (banner.kind === 'ok' ? ' rbk-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    /* ---- what they attach to ---- */
    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">Products</p>' +
      '<p class="rbk-legend-sub">Each selected product gets its own set of reviews. The figures ' +
      'beside each name are the review count and star average it carries right now.</p>' +
      picker() +
      '<div class="rbk-row rbk-wide" style="margin-top:12px">' +
      '<div><div class="rbk-lab">Reviews per product</div>' +
      '<div class="rbk-hint">At most ' + L.max_rows + ' rows in one go, across every product ' +
      'selected. A bigger batch is several passes, deliberately: one request that inserts ' +
      'thousands of rows is how a shared host falls over.</div></div>' +
      '<div class="rbk-ctl">' + num('per_product', add.per_product, 1, L.max_rows) +
      ' <span class="rbk-note' + (over ? ' rbk-over' : '') + '">' +
      '<span class="rbk-count">' + planned + '</span> of ' + L.max_rows + ' rows' +
      (over ? ' — too many' : '') + '</span></div></div>' +
      '</div>';

    /* ---- what they look like ---- */
    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">Ratings and dates</p>' +
      '<p class="rbk-legend-sub">The star for each review is drawn from the mix below. Weights are ' +
      'relative — 70/22/6/1/1 and 7/2.2/0.6/0.1/0.1 mean the same thing.</p>' +
      starMix() +
      '<div class="rbk-row rbk-wide" style="margin-top:14px">' +
      '<div><div class="rbk-lab">Dated between</div>' +
      '<div class="rbk-hint">Each review gets its own moment inside this window, so they do not ' +
      'all carry the same timestamp. Neither end may be in the future — a review dated tomorrow ' +
      'sorts above every real one and is the most obvious tell there is. Left empty, the window ' +
      'is the last 90 days.</div></div>' +
      '<div class="rbk-ctl">' +
      '<input class="rbk-date" type="date" data-rbk-text="date_from" value="' + esc(add.date_from) + '">' +
      '<span class="rbk-note">to</span>' +
      '<input class="rbk-date" type="date" data-rbk-text="date_to" value="' + esc(add.date_to) + '">' +
      '</div></div>' +
      '</div>';

    /* ---- the text ---- */
    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">The text</p>' +
      '<p class="rbk-legend-sub">One entry per line, up to ' + L.max_pool + ' lines each. Entries are ' +
      'shuffled and dealt out evenly, so every line is used once before any line is used twice. ' +
      'HTML is stripped when it is saved.</p>' +
      '<div class="rbk-row rbk-wide"><div><div class="rbk-lab">Reviewer names</div>' +
      '<div class="rbk-hint">' + lines(add.authors).length + ' name(s). Required.</div></div>' +
      '<div class="rbk-ctl"><textarea class="rbk-area" data-rbk-text="authors" ' +
      'placeholder="Aisha K.&#10;Mariam&#10;Sara H.">' + esc(add.authors) + '</textarea></div></div>' +
      '<div class="rbk-row rbk-wide"><div><div class="rbk-lab">Review bodies</div>' +
      '<div class="rbk-hint">' + lines(add.bodies).length + ' line(s). Required. Up to 5,000 ' +
      'characters each.</div></div>' +
      '<div class="rbk-ctl"><textarea class="rbk-area" data-rbk-text="bodies">' +
      esc(add.bodies) + '</textarea></div></div>' +
      '<div class="rbk-row rbk-wide"><div><div class="rbk-lab">Titles</div>' +
      '<div class="rbk-hint">' + lines(add.titles).length + ' line(s). Optional — leave empty and ' +
      'the reviews carry no headline, which is what the storefront shows for most real ones.</div></div>' +
      '<div class="rbk-ctl"><textarea class="rbk-area" data-rbk-text="titles" style="min-height:88px">' +
      esc(add.titles) + '</textarea></div></div>' +
      '</div>';

    /* ---- how they land ---- */
    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">How they land</p>' +
      '<p class="rbk-legend-sub">Whether the reviews are published straight away or wait in the ' +
      'moderation queue.</p>' +
      row('Status', 'A review a shopper writes arrives as <strong>Awaiting approval</strong>, and ' +
        'that is the default here so the two match. <strong>Approved</strong> publishes ' +
        'immediately: the reviews show on the product page and the star average and review count ' +
        'on your shop cards are recalculated in the same request. Either way you can change it ' +
        'later under All Reviews.',
        select('status', add.status, [['pending','Awaiting approval'],['approved','Approved — live now']])) +
      row('Verified-buyer tick',
        'Adds the green “✓ Verified” badge beside the name, which on a real review means the ' +
        'reviewer’s order was matched. Off by default.',
        sw('verified', add.verified)) +
      '</div>';

    html += '<div class="rbk-card rbk-actions">' +
      '<button class="rbk-btn" id="rbk-create"' + ((working || over || planned < 1) ? ' disabled' : '') + '>' +
      (working ? 'Creating…' : 'Create ' + planned + ' review' + (planned === 1 ? '' : 's')) + '</button>' +
      '<button class="rbk-btn rbk-ghost" id="rbk-reset"' + (working ? ' disabled' : '') + '>Clear form</button>' +
      '<span class="rbk-note">Written in one transaction — if any row fails, none are kept.</span>' +
      '</div>';

    if (result && result.products) {
      html += '<div class="rbk-card">' +
        '<p class="rbk-legend">After the recalculation</p>' +
        '<p class="rbk-legend-sub">What your shop cards and the “rating” and “popular” sorts now ' +
        'print for each product. Read back from the products table after the request, not ' +
        'predicted.</p>' +
        '<div class="rbk-scroll"><table class="rbk-table"><thead><tr>' +
        '<th>Product</th><th class="rbk-n">Reviews</th><th class="rbk-n">Rating</th>' +
        '</tr></thead><tbody>' +
        result.products.map(function(p){
          return '<tr><td>' + esc(p.name) + '</td>' +
            '<td class="rbk-n">' + p.review_count + '</td>' +
            '<td class="rbk-n">' + (p.rating ? p.rating.toFixed(2) : '—') + '</td></tr>';
        }).join('') +
        '</tbody></table></div>' +
        (result.status === 'pending'
          ? '<p class="rbk-hint" style="margin-top:10px">These reviews are awaiting approval, so ' +
            'they are not counted above yet — only approved reviews are. Approve them under All ' +
            'Reviews and these figures move.</p>'
          : '') +
        '</div>';
    }

    return html;
  }

  function likesScreen(){
    var L = limits();
    var byProduct = likes.scope === 'product';

    var html = '';

    html += '<div class="rbk-card"><h2 class="rbk-title">Bulk Likes</h2>' +
      '<p class="rbk-sub">Set the “helpful” count on existing reviews.</p></div>';

    html += notice();

    if (banner) {
      html += '<div class="rbk-banner' + (banner.kind === 'ok' ? ' rbk-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    /* ---- which reviews ---- */
    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">Which reviews</p>' +
      '<p class="rbk-legend-sub">Either every review on the products you pick, or an explicit list ' +
      'of review ids — the ids All Reviews shows and its CSV export carries.</p>' +
      row('Choose by', 'Products, or ids pasted in.',
        select('scope', likes.scope, [['product','Product'],['ids','Review ids']])) +
      '</div>';

    if (byProduct) {
      html += '<div class="rbk-card">' +
        '<p class="rbk-legend">Products</p>' +
        '<p class="rbk-legend-sub">Every review on these products that matches the filters below.</p>' +
        picker() + '</div>';
    } else {
      html += '<div class="rbk-card">' +
        '<p class="rbk-legend">Review ids</p>' +
        '<p class="rbk-legend-sub">Separated by anything — commas, spaces or new lines. ' +
        ids(likes.ids).length + ' id(s) so far.</p>' +
        '<textarea class="rbk-area" data-rbk-ltext="ids" style="min-height:96px" ' +
        'placeholder="1201, 1202, 1203">' + esc(likes.ids) + '</textarea></div>';
    }

    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">Filters</p>' +
      '<p class="rbk-legend-sub">Which of the matching reviews are actually touched.</p>' +
      row('Only', 'A shopper can only vote on an <strong>approved</strong> review — ' +
        'the storefront answers 404 for anything else — so that is the default. Widen it if you ' +
        'want counts in place before you approve a batch.',
        select('status', likes.status, [
          ['approved','Approved reviews'],
          ['pending','Awaiting approval'],
          ['spam','Spam'],
          ['any','Any status']
        ])) +
      row('At most', 'Ceiling on how many reviews one pass touches, newest first. ' +
        'The server never touches more than ' + L.likes_max_reviews + ' whatever this says.',
        num('limit', likes.limit, 1, L.likes_max_reviews)) +
      '</div>';

    html += '<div class="rbk-card">' +
      '<p class="rbk-legend">The count</p>' +
      '<p class="rbk-legend-sub">Each review gets its own number drawn from the range, so they do ' +
      'not all end up on the same figure.</p>' +
      row('Mode', '<strong>Add</strong> leaves any genuine votes in place and raises the number. ' +
        '<strong>Set</strong> replaces it, which discards whatever real votes the review had.',
        select('mode', likes.mode, [['add','Add to the current count'],['set','Set the count to']])) +
      row('Between', 'Inclusive, both ends. The highest count this will ever write is ' +
        L.likes_ceiling + '.',
        num('min', likes.min, 0, L.likes_ceiling) + '<span class="rbk-note">and</span>' +
        num('max', likes.max, 0, L.likes_ceiling)) +
      '</div>';

    html += '<div class="rbk-card rbk-actions">' +
      '<button class="rbk-btn" id="rbk-apply"' + (working ? ' disabled' : '') + '>' +
      (working ? 'Applying…' : 'Apply') + '</button>' +
      '<span class="rbk-note">Written in one transaction. Star ratings are not affected — ' +
      '“helpful” is not part of the average.</span>' +
      '</div>';

    if (result && typeof result.affected === 'number' && !result.products) {
      html += '<div class="rbk-card"><p class="rbk-legend">Last pass</p>' +
        '<div class="rbk-scroll"><table class="rbk-table"><thead><tr>' +
        '<th>Reviews matched</th><th class="rbk-n">Updated</th></tr></thead><tbody>' +
        '<tr><td>' + (result.matched || 0) + '</td>' +
        '<td class="rbk-n">' + result.affected + '</td></tr>' +
        '</tbody></table></div>' +
        (result.matched && result.affected < result.matched
          ? '<p class="rbk-hint" style="margin-top:10px">The difference is reviews that were ' +
            'already on the number they were dealt — nothing to write.</p>'
          : '') +
        '</div>';
    }

    return html;
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when one of these screens is the one showing, so a render
    // triggered by a late response cannot overwrite whatever the owner
    // navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || SCREENS.indexOf(active.dataset.go) === -1) return;

    screen = active.dataset.go;

    host.innerHTML = '<div class="rbk-wrap">' +
      (screen === ADD ? addScreen() : likesScreen()) + '</div>';

    bind();
  }

  /* ------------------------------------------------------------------ bind */
  function form(){ return screen === ADD ? add : likes; }

  function bind(){
    var f = form();

    document.querySelectorAll('[data-rbk-bool]').forEach(function(el){
      el.onchange = function(){ f[el.dataset.rbkBool] = el.checked; };
    });

    document.querySelectorAll('[data-rbk-int]').forEach(function(el){
      el.oninput = function(){
        var n = parseInt(el.value, 10);
        /* NaN while the box is empty mid-edit is left alone rather than forced
           to a number: rewriting the field under the cursor makes it impossible
           to clear and retype. The server clamps and validates on submit. */
        if (!isNaN(n)) { f[el.dataset.rbkInt] = n; repaintCounts(); }
      };
    });

    document.querySelectorAll('[data-rbk-enum]').forEach(function(el){
      el.onchange = function(){
        f[el.dataset.rbkEnum] = el.value;
        // Scope changes swap the whole panel, so this one repaints.
        if (el.dataset.rbkEnum === 'scope') { result = null; render(); }
      };
    });

    document.querySelectorAll('[data-rbk-text]').forEach(function(el){
      el.oninput = function(){ add[el.dataset.rbkText] = el.value; repaintCounts(); };
    });

    document.querySelectorAll('[data-rbk-ltext]').forEach(function(el){
      el.oninput = function(){ likes[el.dataset.rbkLtext] = el.value; };
    });

    document.querySelectorAll('[data-rbk-star]').forEach(function(el){
      el.oninput = function(){
        var n = parseInt(el.value, 10);
        if (!isNaN(n)) { add.ratings[el.dataset.rbkStar] = Math.max(0, n); repaintCounts(); }
      };
    });

    document.querySelectorAll('[data-rbk-pick]').forEach(function(el){
      el.onchange = function(){
        var id = el.dataset.rbkPick;
        if (el.checked) { picked[id] = el.dataset.rbkName; }
        else { delete picked[id]; }
        render();
      };
    });

    document.querySelectorAll('[data-rbk-unpick]').forEach(function(el){
      el.onclick = function(){ delete picked[el.dataset.rbkUnpick]; render(); };
    });

    var s = document.querySelector('#rbk-search');
    if (s) {
      s.oninput = function(){
        search = s.value;
        clearTimeout(searchTimer);
        // Debounced, so typing a product name is one request and not eleven.
        searchTimer = setTimeout(load, 250);
      };
    }

    var create = document.querySelector('#rbk-create');
    if (create) create.onclick = createReviews;

    var apply = document.querySelector('#rbk-apply');
    if (apply) apply.onclick = applyLikes;

    var reset = document.querySelector('#rbk-reset');
    if (reset) reset.onclick = function(){
      add.authors = ''; add.titles = ''; add.bodies = '';
      picked = {};
      result = null; banner = null;
      render();
    };
  }

  /* The live counters — planned rows, the star percentages, the line counts.
     A full render() on every keystroke would move the caret out of the textarea
     being typed into, so the numbers are patched in place instead. */
  function repaintCounts(){
    if (screen !== ADD) return;

    var L = limits();
    var planned = plannedRows();

    var count = document.querySelector('.rbk-count');
    if (count) {
      count.textContent = String(planned);
      var note = count.parentElement;
      if (note) note.classList.toggle('rbk-over', planned > L.max_rows);
    }

    var create = document.querySelector('#rbk-create');
    if (create) {
      create.disabled = working || planned > L.max_rows || planned < 1;
      if (!working) {
        create.textContent = 'Create ' + planned + ' review' + (planned === 1 ? '' : 's');
      }
    }

    var total = [1,2,3,4,5].reduce(function(sum, st){
      return sum + (parseInt(add.ratings[st], 10) || 0);
    }, 0);

    document.querySelectorAll('[data-rbk-star]').forEach(function(el){
      var w = parseInt(el.value, 10) || 0;
      var pct = el.parentElement && el.parentElement.querySelector('.rbk-mix-pct');
      if (pct) pct.textContent = (total > 0 ? Math.round(w / total * 100) : 0) + '% of reviews';
    });
  }

  /* A bookmark straight to either screen — /admin?go=rev-add, or the hash.
     That navigation is performed by the boot block at the end of the FIRST
     script in this document, which runs before this one exists, so go() lands
     on renderReviewFrame() and paints the startup message. Nothing has been
     drawn at this point in parsing, so rendering here is not a flicker: it is
     the first thing the browser paints. */
  function bootIfCurrent(){
    var active = document.querySelector('.side .nav-item.on');
    if (active && SCREENS.indexOf(active.dataset.go) !== -1) {
      screen = active.dataset.go;
      render();
      load();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
</script>
@endverbatim
