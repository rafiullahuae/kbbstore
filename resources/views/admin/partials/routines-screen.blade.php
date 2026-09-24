{{--
    Catalog - Build my routine. (Lane FM)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows. The exact anchor and replacement are in
    docs/FM-ADMIN-APP-BLOCKS.md; this lane may not edit that file.

    Its own file rather than more lines inside a 19,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this. The shape is deliberately the same as
    admin/partials/coupon-usage-screen.blade.php; that is the precedent.

    ── EIGHT TABS, AND WHY (Lane Q, round 2) ─────────────────────────────────

    The owner's words: "The routine page is super long, i want tabs with every
    step, so i can just fillup or select and the steps can be lock according."

    This file used to render one continuous page -- the tiles, the role counts,
    the whole product table, the concern countdown, eight routines' worth of
    wording and the settings -- and he had to scroll past all of it to reach the
    one job that matters, which is tagging. It is now ONE FLAT STRIP of eight:

        Cleanser · Toner · Treatment · Moisturiser · SPF · Concern pages ·
        Wording · Settings

    FLAT, not nested, and the pattern is copied on purpose from
    admin/partials/checkout-page-screen.blade.php -- he asked for it by name
    because he uses that screen daily. Same markup, same aria-selected, same
    open-tab-survives-a-save.

    "LOCK" MEANS DONE, NOT DISABLED. He was asked and answered: no tab is ever
    greyed out, nothing is gated on anything else, and there is no manual
    done-tick. Each step tab carries its own count and goes green when no
    routine he is showing still draws that step empty. He works in any order and
    jumps straight at the zeros.

    NOTHING WAS DROPPED. Every control that was on the long page is on a tab and
    does exactly what it did. The one card that is gone is "What each step can
    draw from", whose five role counts ARE the five step tabs now -- the same
    numbers, one card earlier, in the place he is about to click.

    WHAT THIS COSTS IN REQUESTS: opening the screen is still two, the same two
    it always was -- GET /admin-api/routines and one GET /admin-api/routine-
    products. Switching between Concern pages, Wording and Settings costs none
    of either, because all three are drawn from the /routines response the
    screen already holds. Switching to a step tab costs one product request,
    which is the same request the search box has always made.

    WHAT THIS SCREEN IS FOR, AND WHY THE UNTAGGED COUNT IS THE FIRST THING ON
    IT. Every row in this catalogue starts with routine_role NULL. A routine
    engine that quietly skips everything it has no role for looks exactly like a
    routine engine that is working -- four steps drawn as four steps -- so the
    number of products nobody has placed is the headline figure here, the list
    below filters to exactly those rows in one click, and every routine that
    cannot fill a step says which step and why.

    NOTHING HERE IS DRAWN THAT CANNOT BE SAVED. ModuleSchema's header lists the
    controls this console has shipped that saved nothing; every control below
    posts to App\Http\Controllers\Admin\RoutinesApiController and the response
    is read back, and tests/Feature/BuildMyRoutineTest.php posts to each of
    those endpoints and reads the row.

    EVERY CLASS IS PREFIXED rtn- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name -- [data-open], [data-tg], [data-pp] -- and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    THE LAYOUT RULE. Nothing here may be wider than its column at 390px: the
    owner reviews on a phone. The admin sets body{overflow:hidden} and scrolls
    inside #content, so document.scrollWidth can never report an over-wide form
    -- every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.rtn-wrap{display:grid;gap:16px;min-width:0}
.rtn-wrap > *{min-width:0}
.rtn-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rtn-head{display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;justify-content:space-between}
.rtn-title{font-weight:650;font-size:15px}
.rtn-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;margin-top:3px}
.rtn-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));gap:12px;min-width:0}
.rtn-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:13px 15px}
.rtn-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;
               text-transform:uppercase;letter-spacing:.05em}
.rtn-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px}
.rtn-stat.is-gap b{color:#b4443c}
.rtn-roles{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.rtn-rolecount{border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:4px 11px;font-size:12px;
               color:var(--ink-soft,#6b7280)}
.rtn-rolecount b{color:inherit;font-variant-numeric:tabular-nums}
.rtn-rolecount.is-gap{border-color:#b4443c;color:#b4443c}
/* The concern-page countdown (Lane Q). Sized entirely with grid and calc(): no
   script measures anything here, which two tests forbid by name. The row is a
   two-column grid at any width -- name and progress -- with the name column
   allowed to shrink (min-width:0) so a long label wraps instead of pushing the
   bar off a 390px screen. */
/* ── THE TAB STRIP (Lane Q, round 2) ────────────────────────────────────────
   Deliberately the same shape as .chp-tabs / .chp-tab on
   admin/partials/checkout-page-screen.blade.php, because the owner chose this
   pattern by name: "the same as Appearance -> Checkout page, which I use
   daily". Same 8px/12px padding, same 9px radius, same accent border and
   weight on the selected one, and the same `aria-selected` attribute doing the
   selecting rather than a second class.

   FLEX-WRAP IS THE WHOLE MOBILE ANSWER. Eight tabs do not fit on a 390px
   phone and must not become a horizontally scrolling strip -- a strip that
   scrolls hides tabs behind an edge with nothing to say they are there, which
   on this screen means hiding the empty step he is looking for. They wrap onto
   as many rows as they need. Nothing measures anything: wrapping is the
   browser's, and `min-width:0` on the strip and `max-width:100%` on a tab stop
   a long label from forcing the card wider than its column. */
/* The live search status. A row that is always the same height whether it is
   counting or searching, so the list below does not jump by a line on every
   keystroke -- a list that nudges while you type reads as instability. */
.rtn-note-warn{border-color:#b4443c;color:#b4443c}
.rtn-next-demo{border-left-color:#B4881F}
.rtn-status{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline;min-width:0;
            margin:10px 0 2px;font-size:12px;color:var(--ink-soft,#6b7280);min-height:18px}
.rtn-status.is-busy{color:var(--accent,#15a85a)}
.rtn-warnline{color:#b4443c}
.rtn-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.rtn-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;
         max-width:100%;display:inline-flex;align-items:baseline;gap:6px}
.rtn-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
/* The count and the verdict. `b` is the number, always; the tick is added only
   when the step is genuinely finished, so the glyph is never decoration. */
.rtn-tab b{font-weight:650;font-variant-numeric:tabular-nums}
.rtn-tab.is-gap b{color:#b4443c}
.rtn-tab.is-done b{color:#2f7d5d}
.rtn-tab .rtn-tick{color:#2f7d5d;font-weight:700}
.rtn-tabnote{font-size:12.5px;color:var(--ink-soft,#6b7280);line-height:1.55;margin-top:12px;max-width:68ch}
/* The one-click "put this product in the open step" button on a row. */
.rtn-use{padding:6px 11px;border:1px solid var(--accent,#15a85a);border-radius:8px;
         background:transparent;color:var(--accent,#15a85a);font:inherit;font-size:12.5px;
         font-weight:650;cursor:pointer;max-width:100%}
.rtn-use[disabled]{border-color:var(--border,#e6e6e6);color:var(--ink-soft,#6b7280);cursor:default;font-weight:400}
.rtn-rowacts{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
/* The "what to do next" line above the strip, on a shop with nothing tagged. */
.rtn-next{border:1px solid var(--border,#e6e6e6);border-left:3px solid #b4443c;border-radius:10px;
          padding:11px 13px;font-size:12.5px;line-height:1.55;min-width:0}
.rtn-cp{display:grid;gap:10px;margin-top:12px;min-width:0}
.rtn-cprow{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 12px;align-items:baseline;min-width:0}
.rtn-cpname{font-size:13px;font-weight:600;min-width:0}
.rtn-cpnum{font-size:12px;font-variant-numeric:tabular-nums;color:var(--ink-soft,#6b7280);white-space:nowrap}
.rtn-cpbar{grid-column:1/-1;height:6px;border-radius:99px;background:var(--border,#e6e6e6);overflow:hidden}
.rtn-cpfill{display:block;height:100%;border-radius:99px;background:#b4443c}
.rtn-cprow.is-live .rtn-cpfill{background:#2f7d5d}
.rtn-cprow.is-live .rtn-cpnum{color:#2f7d5d}
.rtn-cpnote{grid-column:1/-1;font-size:11.5px;line-height:1.5;color:var(--ink-soft,#6b7280)}
.rtn-cpnote code{font-size:11px;word-break:break-all}
.rtn-filters{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
.rtn-filters input{flex:1 1 170px;min-width:0;padding:8px 10px;font:inherit;
                   border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.rtn-filters select,.rtn-row select{padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
                                    border-radius:9px;background:transparent;color:inherit;max-width:100%}
.rtn-list{display:grid;gap:10px;min-width:0}
.rtn-row{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;display:grid;gap:8px;min-width:0}
.rtn-row.is-untagged{border-left:3px solid #b4443c}
.rtn-row > *{min-width:0}
.rtn-name{font-size:13.5px;font-weight:600;overflow-wrap:anywhere}
.rtn-meta{color:var(--ink-soft,#6b7280);font-size:11.5px;overflow-wrap:anywhere}
.rtn-off{color:#b4443c}
.rtn-chips{display:flex;flex-wrap:wrap;gap:6px}
.rtn-chip{border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:4px 10px;font-size:11.5px;
          background:transparent;color:var(--ink-soft,#6b7280);cursor:pointer;font-family:inherit}
.rtn-chip.on{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.rtn-chip[disabled]{opacity:.45;cursor:default}
.rtn-empty{padding:24px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.rtn-pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;font-size:12.5px;margin-top:10px}
.rtn-pager button,.rtn-btn{padding:6px 12px;border:1px solid var(--border,#e6e6e6);border-radius:8px;
                           background:transparent;color:inherit;font:inherit;cursor:pointer}
.rtn-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.rtn-pager button[disabled]{opacity:.4;cursor:default}
.rtn-fields{display:grid;gap:10px;min-width:0}
.rtn-field{display:grid;gap:4px;min-width:0}
.rtn-field label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#6b7280);
                 text-transform:uppercase;letter-spacing:.04em}
.rtn-field input[type=text],.rtn-field input[type=number],.rtn-field select{padding:8px 10px;font:inherit;
        border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;
        max-width:100%;min-width:0}
.rtn-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5}
.rtn-warn{color:#b4443c;font-size:11.5px;line-height:1.5}
.rtn-banner{border:1px solid #b4443c;color:#b4443c;border-radius:var(--r,12px);padding:12px 14px;font-size:13px}
.rtn-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;font-size:12.5px;
          color:var(--ink-soft,#6b7280);line-height:1.55}
@media (max-width:640px){ .rtn-card{padding:13px} }
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'routines';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;          // GET /admin-api/routines
  var products = null;      // GET /admin-api/routine-products
  var query = '';
  /* Which slice of the catalogue the open step tab is listing.
       ''      the products already in this step   (role=<the open tab>)
       'none'  the ones nobody has placed          (role=none)
       'all'   everything                          (no role filter)
     This is the same control the screen has always had -- it was a select
     offering "Every product / Untagged only / <each role>". The per-role
     options ARE the tabs now, so the select keeps the two that are not. */
  var scope = '';
  /* The open tab. One of the five role keys, or one of the three below. It
     survives a save and a reload for the reason the checkout screen's `open`
     does: a save that dumped him back on tab one would make saving cost him
     his place, and he is working through eight of them. */
  var open = 'cleanse';
  var page = 1;
  /* Is a product request in flight? Drawn, because the owner's report was that
     the search "was not working and was not showing any results" -- and a box
     that says nothing while it works is indistinguishable from one that is
     broken. */
  var searching = false;
  /* The debounce handle. See bind(). */
  var typeTimer = null;
  var banner = null;
  var busy = false;
  var seq = 0;
  var pseq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body){
    var opts = {headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A 404 from any of these endpoints almost always means the package shipped
     without its clear_caches migration having run, so the compiled route table
     does not know these paths. Said plainly rather than rendering an empty
     screen, which reads as "nothing is tagged". */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Build-my-routine endpoints are not registered on this server yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function roleLabel(key){
    var found = (data && data.roles || []).filter(function(r){ return r.key === key; })[0];
    return found ? found.label : key;
  }

  /* -------------------------------------------------------- sidebar entry
     INTEGRATOR: this was a hand-rolled copy and two live guards in
     AdminNavAndIdsTest caught it the moment the partial was included — which is
     the moment the lane's own branch could not reach, because nothing included
     it there.

     Both findings were real. `if (!anchor) return;` answers a missing anchor by
     REMOVING THE SCREEN FROM THE SIDEBAR, silently: rename or reorder the
     Catalog group and Build my routine stops existing for the owner while every
     endpoint behind it keeps answering. And a hand-built <button> is a second
     copy of the row's class name and icon markup, free to drift from the nine
     screens beside it.

     kbbAddNavEntry() is the shared version: the intended position, then the end
     of the named group, then the sidebar itself, and a console error naming this
     screen if it gets that far. Nothing is lost — the row lands in the same
     place, after the product editor, inside Catalog. */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Build my routine',
      icon:   '<path d="M4 6h10"/><path d="M4 12h16"/><path d="M4 18h7"/><circle cx="18" cy="6" r="2"/><circle cx="15" cy="18" r="2"/>',
      group:  'Catalog',
      after:  ['product-editor', 'catalog']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Build my routine';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    loadAll();
    loadProducts();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function loadAll(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/routines');
      if (mine !== seq) return;
      data = body;
      banner = null;

      /* THE OPEN TAB SURVIVES EVERY RELOAD, and is only moved when it has
         stopped existing — the guard the checkout screen makes about its own
         `open`. Saving a routine or the settings reloads through here, and a
         save that dumped him back on tab one would cost him his place eight
         times over. */
      if (!isStep(open) && ['concern-pages', 'wording', 'settings'].indexOf(open) === -1) {
        open = (data.roles && data.roles.length) ? data.roles[0].key : 'concern-pages';
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not load the routines screen.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function loadProducts(){
    var mine = ++pseq;

    /* Painted before the request goes out, so the box acknowledges the
       keystroke on the same frame rather than after the round trip. */
    searching = true;
    render();

    try {
      /* THE ROLE PARAMETER COMES FROM THE OPEN TAB, and this is the whole of
         the wiring between the strip and the list. The endpoint is unchanged
         and still takes the same three things; what moved is where the role
         comes from. On a non-step tab this is never called at all -- those
         three tabs are drawn entirely from the /routines response the screen
         already had, so switching to them costs no request. */
      /* A SEARCH ON THE DEFAULT SCOPE LOOKS AT THE WHOLE CATALOGUE, and that
         is the difference between this screen working and not.

         "In this step" narrows to products that already carry this role. Typing
         a term while it is narrowed asks "which of the products already in this
         step match centella" — and on an EMPTY step, which is every step on the
         shop he is looking at, that is guaranteed to be nothing. He types the
         first word off the worksheet, gets no rows, and concludes the search is
         broken. It is the same defect round 1 fixed at the column level, one
         layer up.

         So the default scope means "what is in this step" when he is browsing
         and "find me one to put in it" when he is searching. The two explicit
         scopes are never overridden: if he chose "Untagged only" he gets
         untagged only, term or no term. */
      var role = scope === 'none' ? 'none'
               : (scope === 'all' ? '' : (query ? '' : open));

      var qs = '?page=' + page
             + (query ? '&q=' + encodeURIComponent(query) : '')
             + (role ? '&role=' + encodeURIComponent(role) : '');
      var body = await api('/routine-products' + qs);

      /* ── THE STALE-RESPONSE GUARD, AND IT IS NOT DECORATION ──────────────
         Type "cent" quickly and four requests are in flight at once. They can
         come back in any order, and the reply to "cen" landing after the reply
         to "cent" repaints the older, wider result set over the newer one. It
         looks exactly like a broken search, and because it depends on the
         network it is intermittent, which is worse than always wrong.

         `pseq` is bumped by every call, so a reply whose ticket is no longer
         the current one is DROPPED -- not merged, not rendered, and it does not
         clear the in-flight flag either, because a newer request is still out.
         Copied from the pattern in admin/partials/slim-footer-screen.blade.php. */
      if (mine !== pseq) return;
      products = body;
      banner = null;
    } catch (e) {
      if (mine !== pseq) return;
      banner = explain(e, 'Could not load the product list.');
      products = null;
    } finally {
      if (mine === pseq) { searching = false; render(); }
    }
  }

  /* ---------------------------------------------------------------- views */
  /* ── THE THREE TILES, WHICH STAY ABOVE THE TABS ───────────────────────────
     Not a tab of their own, deliberately. This file's own header states the
     rule they exist for -- "the number of products nobody has placed is the
     headline figure here" -- and a headline that lives behind a tab is not a
     headline. They are the only thing on the screen that is about the whole
     catalogue rather than about one step, so they sit above the strip and are
     true whichever tab is open.

     WHAT IS NOT HERE ANY MORE, AND WHERE IT WENT. The "What each step can draw
     from" card used to sit here and draw one chip per role with that role's
     live count on it. Those five numbers are now ON THE FIVE STEP TABS, which
     is the same information in the place he is about to click. Nothing was
     dropped: the chips were the strip, one card earlier. */
  function statsView(){
    var c = data.coverage;

    return '<div class="rtn-stats">'
      + '<div class="rtn-stat"><span>On the storefront</span><b>' + c.total + '</b></div>'
      + '<div class="rtn-stat"><span>Given a step</span><b>' + c.tagged + '</b></div>'
      + '<div class="rtn-stat' + (c.untagged > 0 ? ' is-gap' : '') + '"><span>Still untagged</span><b>'
        + c.untagged + '</b></div>'
      + '</div>'
      + demoStandingView()
      + nextActionView();
  }

  /* ── THE DEMO ROWS ARE NAMED ON EVERY TAB, NOT JUST WHERE THE BUTTON IS ───
     A demo routine that reaches a shopper unannounced is worse than no demo at
     all, and the way that happens is not malice: he imports it to look, turns
     the module on to look properly, gets distracted, and it is still there next
     week. So for exactly as long as demo rows exist, every tab of this screen
     says so — and says it louder when the module is on, because that is when a
     shopper can actually see them. It disappears the moment they are removed,
     so it can never become furniture. */
  function demoStandingView(){
    var n = (data.demo || {}).routines || 0;
    if (!n) return '';

    return '<div class="rtn-next rtn-next-demo" style="margin-top:12px">'
      + '<b>' + n + ' demo rows are in your catalogue.</b> '
      + (data.module_on
          ? 'The routine section is <b>on</b>, so shoppers can see them right now. '
          : 'The routine section is off, so no shopper can see them yet. ')
      + 'They are named “Demo —”. Remove them on the <b>Settings</b> tab, or at Store → Demo Content.'
      + '</div>';
  }

  /* ── WHAT TO DO NEXT, FROM COLD ───────────────────────────────────────────
     The state this shop is actually in is "nothing tagged", and on that shop
     every tab reads zero and the strip alone does not say where to start. This
     names the first step that needs work and nothing else. It disappears the
     moment there is no first step to name, so it never becomes furniture the
     owner learns to read past.

     It is a SENTENCE, not a control: it changes nothing and saves nothing. */
  function nextActionView(){
    var c = data.coverage || {};
    var short = c.by_role_short || {};
    var counts = c.by_role || {};

    var firstEmpty = null;
    var firstShort = null;

    (data.roles || []).forEach(function(r){
      if (!firstEmpty && (counts[r.key] || 0) === 0) firstEmpty = r;
      if (!firstShort && (short[r.key] || 0) > 0) firstShort = r;
    });

    if (!firstEmpty && !firstShort) return '';

    var step = firstEmpty || firstShort;

    var why = firstEmpty
      ? 'Nothing on the storefront fills it, so every routine draws it as “not stocked yet”.'
      : 'It is filled for some concerns and not others — the tab says which.';

    return '<div class="rtn-next" style="margin-top:12px">'
      + '<b>Next: ' + esc(step.label) + '.</b> ' + why
      + ' Open the <b>' + esc(step.label) + '</b> tab, search for a product — the search reads ingredient '
      + 'lists as well as names — and press <b>Use for ' + esc(step.label) + '</b> on it.'
      + '</div>';
  }

  /* ── THE STRIP ────────────────────────────────────────────────────────────
     Eight tabs, flat, in one row-wrapping strip: the five steps in the order
     skincare is applied, then the three things that are not steps.

     THE LABEL IS THE PROGRESS INDICATOR, which is the point of the whole
     rebuild -- he can read the entire job off the strip without opening
     anything. Two figures, answering two different questions:

       the NUMBER is coverage.by_role -- products carrying that step that a
       shopper could actually be shown: published, visible, not scheduled, in
       stock. Not "tagged". A step with four tagged products that are all out
       of stock reads 0 here, because 0 is what the storefront can draw on.

       the TICK is coverage.by_role_short === 0 -- no routine the owner is
       showing still draws that step empty. That is what "done" means: not a
       threshold somebody picked, and not a box he has to tick himself.

     "LOCKED" IS DONE, NOT DISABLED. No tab is ever disabled, nothing is greyed
     out, and no tab is gated on another -- he opens them in any order and jumps
     straight at the zeros. A finished step goes green and stops asking. */
  function tabsView(){
    var c = data.coverage || {};
    var counts = c.by_role || {};
    var short = c.by_role_short || {};

    var stepTabs = (data.roles || []).map(function(r){
      var n = counts[r.key] || 0;
      var done = n > 0 && (short[r.key] || 0) === 0;
      var state = n === 0 ? ' is-gap' : (done ? ' is-done' : '');

      return tabHTML(r.key, esc(r.label), '<b>' + n + '</b>' + (done ? '<span class="rtn-tick">✓</span>' : ''), state);
    }).join('');

    var pages = (c.concern_pages || []);
    var livePages = pages.filter(function(p){ return p.live; }).length;

    return '<div class="rtn-tabs" role="tablist" aria-label="Build my routine sections">'
      + stepTabs
      + tabHTML('concern-pages', 'Concern pages',
          '<b>' + livePages + '/' + pages.length + '</b>'
            + (pages.length && livePages === pages.length ? '<span class="rtn-tick">✓</span>' : ''),
          livePages === 0 ? ' is-gap' : (pages.length && livePages === pages.length ? ' is-done' : ''))
      + tabHTML('wording', 'Wording', '', '')
      + tabHTML('settings', 'Settings', '', '')
      + '</div>';
  }

  function tabHTML(key, label, badge, state){
    return '<button type="button" role="tab" class="rtn-tab' + state + '" data-rtn-tab="' + esc(key) + '"'
      + ' aria-selected="' + (key === open ? 'true' : 'false') + '">'
      + label + (badge ? ' ' + badge : '') + '</button>';
  }

  /** Is this tab one of the five steps? */
  function isStep(key){
    return (data && data.roles || []).some(function(r){ return r.key === key; });
  }

  function roleFor(key){
    return (data && data.roles || []).filter(function(r){ return r.key === key; })[0] || null;
  }

  /* ------------------------------------------- the concern-page countdown
     WHY THIS IS ON THIS SCREEN AND NOT ANOTHER ONE. This is where the tagging
     happens, and until now the tagging had no visible finish line: a concern
     page 404s until it has copy AND enough live tagged products, and neither
     half could be seen from the screen the products are tagged on. So "tag
     30-45 products" was a leap of faith. It is a countdown now.

     IT COUNTS WHAT THE PAGE COUNTS, which is not what the routine counts. A
     product with no concerns suits EVERY routine, and contributes to NO concern
     page -- the page selects explicit tags only. The server does that
     arithmetic (BuildMyRoutine::concernPageProgress); this only draws it. */
  function concernPagesView(){
    var rows = (data.coverage && data.coverage.concern_pages) || [];
    if (!rows.length) return '';

    var live = rows.filter(function(r){ return r.live; }).length;

    var list = rows.map(function(r){
      /* A bar that fills to the floor and stops there. Over-tagging past the
         minimum is good but it is not more progress towards a page existing,
         and a bar past 100% would say the opposite. calc() in a style
         attribute, not a measured pixel width. */
      var pct = r.min > 0 ? Math.min(100, Math.round((r.tagged / r.min) * 100)) : 0;

      var note;
      if (r.live) {
        note = 'Live now at <code>' + esc(r.path) + '</code>.';
      } else if (!r.has_copy && r.needed > 0) {
        note = 'Needs ' + r.needed + ' more tagged product' + (r.needed === 1 ? '' : 's')
             + ', and page copy — which is written in the code, not on this screen. Ask for it by name.';
      } else if (!r.has_copy) {
        note = 'Enough products. Waiting on page copy, which is written in the code, not on this screen. Ask for it by name.';
      } else {
        note = 'Needs ' + r.needed + ' more tagged product' + (r.needed === 1 ? '' : 's')
             + ' to go live at <code>' + esc(r.path) + '</code>.';
      }

      return '<div class="rtn-cprow' + (r.live ? ' is-live' : '') + '">'
        + '<div class="rtn-cpname">' + esc(r.label) + '</div>'
        + '<div class="rtn-cpnum">' + r.tagged + ' / ' + r.min + '</div>'
        + '<div class="rtn-cpbar"><span class="rtn-cpfill" style="width:' + pct + '%"></span></div>'
        + '<div class="rtn-cpnote">' + note + '</div>'
        + '</div>';
    }).join('');

    return '<div class="rtn-card" style="margin-top:12px">'
      + '<div class="rtn-head"><div>'
      + '<div class="rtn-title">Concern landing pages — ' + live + ' of ' + rows.length + ' live</div>'
      + '<div class="rtn-sub">Each of these is a page of its own for shoppers searching by problem rather than by product type. '
        + 'A concern page does not exist until it has page copy AND at least ' + esc(String((rows[0] || {}).min || '')) + ' products that are '
        + 'tagged for it, published, visible and in stock — counted below. Until then the address is a 404 on purpose: '
        + 'a near-empty collection page ranks worse than no page at all. '
        + 'Only products you have ticked the chip on count here; a product with no concerns suits every routine and no page.</div>'
      + '</div></div>'
      + '<div class="rtn-cp">' + list + '</div>'
      + '</div>';
  }

  /* ── ONE STEP TAB: THE PLACE HE ACTUALLY WORKS ────────────────────────────
     "so i can just fillup or select" — so the tab he is standing in shows what
     is in this step, lets him search the whole catalogue without leaving it,
     and puts the product into THIS step in one press.

     ONE REQUEST, NOT TWO. The obvious build is two lists — "filling this step"
     and "candidates" — and that is two round trips per tab. It is also worse to
     use: the same product appears in both. Instead the ONE list answers
     whichever question is being asked. With no search term it is what fills
     this step; type a term and it is the catalogue, each row saying which step
     it is in now and offering this one. Same endpoint, same single request the
     screen has always made.

     EVERY ROW CONTROL IS THE ONE THAT WAS THERE BEFORE. The step select still
     sets any of the five, the concern chips still toggle, and both still save
     on change and read the row back from the response. The "Use for <step>"
     button is an ADDITION, not a replacement — it is the select's first option
     as one press. */
  function stepView(){
    var role = roleFor(open);
    if (!role) return '';

    var c = data.coverage || {};
    var n = (c.by_role || {})[open] || 0;
    var short = (c.by_role_short || {})[open] || 0;

    /* WHICH ROUTINES STILL DRAW THIS STEP EMPTY, by name. "3 routines short" is
       a number he cannot act on; "Hydration, Dullness & glow and Sun protection"
       tells him which concern chips to tick on whatever he tags next. */
    var shortNames = (data.routines || []).filter(function(r){
      return r.is_enabled && (r.empty || []).indexOf(open) > -1;
    }).map(function(r){ return r.label; });

    var verdict;

    if (n === 0) {
      verdict = '<b>Nothing on the storefront fills this step.</b> Every routine draws it as '
              + '“not stocked yet” — which is deliberate, and is not the same as a routine with four steps.';
    } else if (short === 0) {
      verdict = '<b>Done.</b> Every routine you are showing can fill this step from what is in stock.';
    } else {
      verdict = '<b>' + n + ' in stock, and ' + short + ' routine' + (short === 1 ? '' : 's')
              + ' still cannot fill it:</b> ' + esc(shortNames.join(', '))
              + '. Tag another product for this step and tick those concerns on it — or leave its concerns '
              + 'all off, which makes it suit every routine.';
    }

    /* THE SCOPE SELECT IS THE OLD ROLE FILTER, less the five options that are
       now tabs. "Untagged only" is kept because this file's header calls it out
       by name as the one-click filter the screen exists for. */
    var opts = '<option value=""' + (scope === '' ? ' selected' : '') + '>In this step</option>'
             + '<option value="none"' + (scope === 'none' ? ' selected' : '') + '>Untagged only</option>'
             + '<option value="all"' + (scope === 'all' ? ' selected' : '') + '>Every product</option>';

    /* Said out loud, because the list just changed what it is showing without
       the select above it moving. */
    var scopeNote = (query && scope === '')
      ? '<div class="rtn-tabnote">Showing every product matching “' + esc(query) + '”, wherever it sits today. '
        + 'Press <b>Use for ' + esc(role.label) + '</b> on one to put it in this step.</div>'
      : '';

    /* ── SAY WHAT IS HAPPENING WHILE IT HAPPENS ───────────────────────────
       The owner's report was that the search "was not working and was not
       showing any results". Half of that was two real defects, fixed; the other
       half is that this box never acknowledged a keystroke, never said how many
       rows came back, and drew the same word — "Loading…" — for "the first
       request is out" and "your search is running". A control that says nothing
       while it works cannot be told apart from one that is broken.

       So: a live count, an in-flight line that does NOT blank the rows under it
       (a list that flashes empty between keystrokes is the thing he reported),
       and, when the server had to search fewer columns than it wanted to, a
       line saying so. */
    var status;

    if (searching) {
      status = 'Searching…';
    } else if (!products) {
      status = '';
    } else if (query) {
      // "1 product matches", "2 products match" — the verb agrees too, which
      // the first draft got wrong and a screenshot caught.
      status = products.total + (products.total === 1 ? ' product matches “' : ' products match “')
             + esc(query) + '”';
    } else {
      status = products.total + ' product' + (products.total === 1 ? '' : 's');
    }

    /* THE SERVER SAYS WHICH COLUMNS IT ACTUALLY SEARCHED. On a server whose
       product-editor migration has not run there is no `ingredients` column,
       and before round 3 that made every search a 500. It now degrades to name
       and SKU — and says so here, because a silent narrowing is how somebody
       concludes the ingredient terms on the worksheet are wrong. */
    var narrowed = products && products.searched
      && products.searched.indexOf('ingredients') === -1;

    var body = '';

    if (status || narrowed) {
      body += '<div class="rtn-status' + (searching ? ' is-busy' : '') + '">'
        + (status ? '<span>' + status + '</span>' : '')
        + (narrowed
            ? '<span class="rtn-warnline">Ingredient search is off on this server — '
              + 'the product-editor update has not been applied, so only names and SKUs are searched.</span>'
            : '')
        + '</div>';
    }

    if (!products && !searching) {
      body += '<div class="rtn-empty">Nothing loaded.</div>';
    } else if (!products) {
      body += '<div class="rtn-empty">Searching…</div>';
    } else if (!products.products.length) {
      body += '<div class="rtn-empty">'
           + (query
               ? 'No product matches “' + esc(query) + '”.'
                 + (narrowed ? '' : ' The search reads ingredient lists too, so try an ingredient.')
               : (scope === ''
                   /* FROM COLD THIS IS THE STATE HE IS IN — every step empty —
                      so the empty message carries the next press rather than
                      describing it. It sets the scope select that is already
                      above it; it is a shortcut to a control, not a second one. */
                   ? 'No product fills this step yet.<div style="margin-top:10px">'
                     + '<button type="button" class="rtn-use" id="rtn-show-untagged">'
                     + 'Show the ' + ((data.coverage || {}).untagged || 0) + ' products with no step yet</button></div>'
                   : 'Nothing matches that filter.'))
           + '</div>';
    } else {
      body += '<div class="rtn-list">' + products.products.map(productRow).join('') + '</div>' + pagerView();
    }

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">' + esc(role.label) + '</div>'
      + '<div class="rtn-sub">' + verdict + '</div></div></div>'
      + '<div class="rtn-tabnote">A product with no step is never offered in a routine. Concerns are optional: '
        + 'leave them all off and the product suits every routine; switch some on and it is only offered in those. '
        + 'The search reads ingredient lists as well as names, so “centella”, “niacinamide” or “fragrance-free” '
        + 'find the products whose labels say so even when their names do not.</div>'
      + '<div class="rtn-filters">'
      + '<input id="rtn-q" type="search" placeholder="Search name, SKU or ingredients" value="' + esc(query) + '" autocomplete="off">'
      + '<select id="rtn-scope">' + opts + '</select>'
      + '</div>'
      + scopeNote
      + body
      + '</div>';
  }

  function productRow(p){
    var roleOpts = '<option value="">— no step —</option>'
      + (data.roles || []).map(function(r){
          return '<option value="' + esc(r.key) + '"' + (p.role === r.key ? ' selected' : '') + '>'
               + esc(r.label) + '</option>';
        }).join('');

    var chips = (data.concerns || []).map(function(c){
      var on = (p.concerns || []).indexOf(c.key) > -1;
      return '<button type="button" class="rtn-chip' + (on ? ' on' : '') + '"'
           + ' data-rtn-concern="' + esc(c.key) + '" data-rtn-for="' + p.id + '"'
           + (p.role ? '' : ' disabled title="Give this product a step first"') + '>'
           + esc(c.label) + '</button>';
    }).join('');

    return '<div class="rtn-row' + (p.role ? '' : ' is-untagged') + '" data-rtn-row="' + p.id + '">'
      + '<div class="rtn-name">' + esc(p.name) + '</div>'
      + '<div class="rtn-meta">' + esc(p.brand || '—')
        + (p.sku ? ' · ' + esc(p.sku) : '')
        + (p.live ? '' : ' · <span class="rtn-off">not on the storefront</span>') + '</div>'
      /* Why this row matched, when neither the name nor the SKU shows it. The
         server sends this only in that case, so it never repeats what is
         already on the line above. */
      + (p.ingredient_hit ? '<div class="rtn-meta">Ingredients: ' + esc(p.ingredient_hit) + '</div>' : '')
      + '<div class="rtn-rowacts">'
        + useButtonHTML(p)
        + '<select data-rtn-rolefor="' + p.id + '">' + roleOpts + '</select>'
      + '</div>'
      + '<div class="rtn-chips">' + chips + '</div>'
      + '</div>';
  }

  /* ── "USE FOR <STEP>", AND THE STEP IS THE OPEN TAB ───────────────────────
     THE ONE-PRESS VERSION OF THE SELECT BESIDE IT. It writes exactly what
     choosing that option in the select writes, through the same tag() call and
     the same endpoint, and the row is read back from the response either way.

     IT ACTS ON THE OPEN TAB AND NOTHING ELSE. That is the lesson the checkout
     screen already paid for — the owner reported "when i click squeezed, it
     applies on all tabs", and the fix there was to scope the action to the tab
     being looked at. The step key is read from `open` at the moment of the
     press, so a button on a row can only ever place that product in the step
     whose tab is showing.

     Drawn as a finished tick when the product is already in this step, rather
     than hidden: a row that offers nothing looks like a row the screen forgot,
     and the disabled state is what says "this one is already done". */
  function useButtonHTML(p){
    var role = roleFor(open);
    if (!role) return '';

    if (p.role === open) {
      return '<button type="button" class="rtn-use" disabled>In ' + esc(role.label) + ' ✓</button>';
    }

    return '<button type="button" class="rtn-use" data-rtn-use="' + p.id + '">'
      + 'Use for ' + esc(role.label) + '</button>';
  }

  function pagerView(){
    var pages = Math.max(1, Math.ceil(products.total / products.per_page));

    return '<div class="rtn-pager">'
      + '<span>' + products.total + ' product' + (products.total === 1 ? '' : 's') + '</span>'
      + '<button id="rtn-prev"' + (products.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>' + products.page + ' / ' + pages + '</span>'
      + '<button id="rtn-next"' + (products.page >= pages ? ' disabled' : '') + '>Next</button>'
      + '</div>';
  }

  function settingsView(){
    var fields = [];

    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ fields.push(f); });
    });

    var html = fields.map(function(f){
      var control;

      if (f.key === 'offer_coupon') {
        // A dropdown of codes that really exist rather than a text box. A typed
        // code that does not exist shows NOTHING on the storefront and nothing
        // anywhere says why, which is the silent-configuration-error shape this
        // project has paid for more than once.
        var chosen = String(f.value == null ? '' : f.value);
        var known = (data.coupons || []).some(function(c){ return c.code === chosen; });

        control = '<select data-rtn-set="' + esc(f.key) + '">'
          + '<option value="">— no offer strip —</option>'
          + (chosen && !known ? '<option value="' + esc(chosen) + '" selected>' + esc(chosen) + ' (no such code)</option>' : '')
          + (data.coupons || []).map(function(c){
              return '<option value="' + esc(c.code) + '"' + (c.code === chosen ? ' selected' : '') + '>'
                   + esc(c.code) + '</option>';
            }).join('')
          + '</select>';
      } else if (f.type === 'select') {
        control = '<select data-rtn-set="' + esc(f.key) + '">'
          + Object.keys(f.options || {}).map(function(k){
              return '<option value="' + esc(k) + '"' + (String(f.value) === k ? ' selected' : '') + '>'
                   + esc(f.options[k]) + '</option>';
            }).join('')
          + '</select>';
      } else {
        control = '<input type="text" data-rtn-set="' + esc(f.key) + '" value="' + esc(f.value) + '">';
      }

      return '<div class="rtn-field"><label>' + esc(f.label) + '</label>' + control
           + (f.help ? '<div class="rtn-help">' + esc(f.help) + '</div>' : '') + '</div>';
    }).join('');

    return moduleSwitchView()
      + demoDataView()
      + '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">Settings</div>'
      + '<div class="rtn-sub">The two questions the plan left open. Both are answered here rather than in code, '
        + 'so either answer is a dropdown and not a rebuild.</div></div></div>'
      + '<div class="rtn-fields" style="margin-top:12px">' + html + '</div>'
      + '<div style="margin-top:12px"><button class="rtn-btn is-primary" id="rtn-save-settings">Save settings</button></div>'
      + '</div>';
  }

  /* ── THE ON/OFF SWITCH, ON THE SCREEN THAT SHOWS THE WORK ─────────────────
     The owner asked to "turn on off routine section completely". The switch
     already existed at Store → Modules → Build my routine; what did not exist
     was any sign of it here, so he either did not know it was there or did not
     trust that it turned everything off.

     IT IS THE SAME SETTING, NOT A SECOND ONE. This posts to
     /admin-api/routines-module, which writes `module_toggles` through the same
     SettingsService::setModule() the Modules screen uses, so the two screens
     cannot disagree — there is nothing for them to disagree about.

     AND IT SAYS WHAT IT DOES NOT TURN OFF. /concern/{slug}/ is deliberately not
     behind this switch: those pages exist on their own terms, and a shopper who
     lands on one from Google should not find it gone because a routine module
     was toggled. Saying so here is the difference between a documented boundary
     and a nasty surprise at the worst moment. */
  function moduleSwitchView(){
    var on = !!data.module_on;

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div>'
      + '<div class="rtn-title">The routine section is ' + (on ? 'ON' : 'OFF') + '</div>'
      + '<div class="rtn-sub">' + (on
          ? 'Shoppers can reach <code>/routines</code> and a page for each concern. The routines are in the sitemap.'
          : 'Nothing about the shop changes. <code>/routines</code> and every <code>/routines/&lt;concern&gt;</code> answer 404, '
            + 'nothing links to them, and they are not in the sitemap.')
      + '</div></div>'
      + '<button type="button" class="rtn-btn' + (on ? '' : ' is-primary') + '" id="rtn-module-toggle">'
      + (on ? 'Turn the routine section off' : 'Turn the routine section on')
      + '</button>'
      + '</div>'
      + '<div class="rtn-note" style="margin-top:12px">'
      + '<b>What this switch does not cover.</b> The concern landing pages at '
      + '<code>/concern/&lt;concern&gt;/</code> are not part of it. They appear on their own once a concern has '
      + 'copy and enough tagged products — see the <b>Concern pages</b> tab — and they keep answering whether '
      + 'this is on or off. That is deliberate: they are ordinary shop pages that people find in Google, and a '
      + 'switch here should not take them down.'
      + '</div>'
      + '<div class="rtn-note" style="margin-top:8px">This is the same switch as '
      + '<b>Store → Modules → Build my routine</b>. Changing it in either place changes the other.</div>'
      + '</div>';
  }

  /* ── DEMO DATA ────────────────────────────────────────────────────────────
     "also give option to import demo data". Five products, one per step, so he
     can see a filled routine before committing two or three hours to tagging
     his own catalogue — which is the decision the demo exists to inform.

     It runs the SHOP'S OWN demo machinery, Store → Demo Content, rather than a
     second importer: same endpoints, same demo_seed_log, removable from either
     screen. The button here is a shortcut to that, not a copy of it. */
  function demoDataView(){
    var n = (data.demo || {}).routines || 0;

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div>'
      + '<div class="rtn-title">Demo data</div>'
      + '<div class="rtn-sub">Five sample products, one for each step, so every routine fills and you can see '
        + 'what the page looks like before tagging anything of your own. '
        + 'They carry <b>no concerns</b>, so they cannot publish a concern landing page and they do not move the '
        + 'countdown on the Concern pages tab.</div></div>'
      + (n > 0
          ? '<button type="button" class="rtn-btn" id="rtn-demo-remove">Remove demo data</button>'
          : '<button type="button" class="rtn-btn" id="rtn-demo-import">Import demo data</button>')
      + '</div>'
      + (n > 0
          ? '<div class="rtn-note rtn-note-warn" style="margin-top:12px">'
            + '<b>' + n + ' demo rows are in your catalogue right now.</b> They are named “Demo —” and they are '
            + 'real products: with the routine section on, a shopper sees them. Remove them before you go live, '
            + 'here or at <b>Store → Demo Content</b>.</div>'
          : '')
      + '</div>';
  }

  function routinesView(){
    var custom = data.settings && data.settings.steps_mode === 'custom';

    var rows = (data.routines || []).map(function(r){
      var stepChips = (data.roles || []).map(function(role){
        var on = (r.own_steps || r.steps || []).indexOf(role.key) > -1;
        return '<button type="button" class="rtn-chip' + (on ? ' on' : '') + '"'
             + ' data-rtn-step="' + esc(role.key) + '" data-rtn-of="' + esc(r.concern) + '"'
             + (custom ? '' : ' disabled') + '>' + esc(role.label) + '</button>';
      }).join('');

      var gaps = (r.empty || []).map(roleLabel);

      return '<div class="rtn-row" data-rtn-routine="' + esc(r.concern) + '">'
        + '<div class="rtn-name">' + esc(r.label) + '</div>'
        + '<div class="rtn-fields">'
        + '<div class="rtn-field"><label>Heading on the page</label>'
          + '<input type="text" data-rtn-title="' + esc(r.concern) + '" value="' + esc(r.title || '') + '" placeholder="' + esc(r.label) + ' routine"></div>'
        + '<div class="rtn-field"><label>Sentence under it</label>'
          + '<input type="text" data-rtn-blurb="' + esc(r.concern) + '" value="' + esc(r.blurb || '') + '" placeholder="Leave empty for the default"></div>'
        + '<div class="rtn-field"><label>Steps' + (custom ? '' : ' (fixed — change the setting above to edit)') + '</label>'
          + '<div class="rtn-chips">' + stepChips + '</div></div>'
        + '<div class="rtn-field"><label>Offer strip coupon for this routine</label>'
          + '<select data-rtn-coupon="' + esc(r.concern) + '">'
          + '<option value="">— none —</option>'
          + (data.coupons || []).map(function(c){
              return '<option value="' + esc(c.code) + '"' + (c.code === r.coupon_code ? ' selected' : '') + '>'
                   + esc(c.code) + '</option>';
            }).join('')
          + '</select></div>'
        + '<div class="rtn-field"><label>Order on the list page</label>'
          + '<input type="number" data-rtn-pos="' + esc(r.concern) + '" value="' + (r.position || 0) + '" step="1"></div>'
        + '<div class="rtn-field"><label>Shown to shoppers</label>'
          + '<select data-rtn-on="' + esc(r.concern) + '">'
          + '<option value="1"' + (r.is_enabled ? ' selected' : '') + '>Yes</option>'
          + '<option value="0"' + (r.is_enabled ? '' : ' selected') + '>No — hide this routine</option>'
          + '</select></div>'
        + '</div>'
        + (gaps.length
            ? '<div class="rtn-warn">Nothing stocked for: ' + esc(gaps.join(', '))
              + '. Those steps are shown empty on the storefront.</div>'
            : '')
        + '<div><button class="rtn-btn" data-rtn-save="' + esc(r.concern) + '">Save this routine</button></div>'
        + '</div>';
    }).join('');

    return '<div class="rtn-card">'
      + '<div class="rtn-head"><div><div class="rtn-title">The routines</div>'
      + '<div class="rtn-sub">One per concern, and the concerns are the same eight the skin quiz asks about. '
        + 'Everything here is optional — a routine you have never touched uses the built-in heading and the five fixed steps.</div></div></div>'
      + '<div class="rtn-list" style="margin-top:12px">' + rows + '</div>'
      + '</div>';
  }

  function render(){
    var host = document.querySelector('#view') || document.querySelector('#main') || document.querySelector('.content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the operator navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rtn-wrap">';

    if (banner) html += '<div class="rtn-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="rtn-card"><div class="rtn-empty">' + (busy ? 'Loading…' : 'Nothing to show.') + '</div></div>';
    } else {
      /* THE STRIP IS ALWAYS DRAWN; EXACTLY ONE BODY IS DRAWN UNDER IT.
         Every surface the screen had is still here and still does what it did;
         what changed is that seven of the eight are not painted at once. */
      html += statsView()
            + '<div class="rtn-card" style="margin-top:12px">' + tabsView() + '</div>';

      if (isStep(open)) {
        html += stepView();
      } else if (open === 'concern-pages') {
        html += concernPagesView();
      } else if (open === 'wording') {
        html += routinesView();
      } else {
        html += settingsView();
      }
    }

    /* ── KEEP THE CARET WHERE HE LEFT IT ──────────────────────────────────
       render() replaces the whole panel, which destroys and rebuilds the search
       box. That was harmless while the search only ran on Enter or blur -- focus
       was already leaving the field. Now that it runs AS HE TYPES, a render
       between keystrokes would take the focus away mid-word and the next letter
       would go nowhere: a search box you can type one character into is a worse
       bug than the one this round is fixing.

       So the id of the focused field and its caret are carried across the
       swap. Only for the fields this screen owns, and only when one of them
       really had focus -- this never steals focus, it only puts back what the
       repaint took. selectionStart is a caret position, not a measurement; no
       geometry is read. */
    var active = document.activeElement;
    var keepId = active && active.id && /^rtn-(q|scope)$/.test(active.id) ? active.id : null;
    var keepAt = null;

    if (keepId === 'rtn-q') {
        try { keepAt = active.selectionStart; } catch (e) { keepAt = null; }
    }

    host.innerHTML = html + '</div>';
    bind();

    if (keepId) {
      var restored = document.querySelector('#' + keepId);

      if (restored) {
        restored.focus();

        if (keepAt !== null) {
          try { restored.setSelectionRange(keepAt, keepAt); } catch (e) {}
        }
      }
    }
  }

  /* ---------------------------------------------------------------- saves */
  async function tag(id, payload, message){
    try {
      var body = await api('/routine-products/' + id, payload);
      if (data && body.coverage) data.coverage = body.coverage;

      // The row is updated in place from the RESPONSE, not from what was
      // clicked: the server cleans the concern list and normalises the role,
      // and a screen that painted its own guess would drift from the column.
      (products && products.products || []).forEach(function(p){
        if (p.id === body.product.id) { p.role = body.product.role; p.concerns = body.product.concerns; }
      });

      say(message);
      render();
    } catch (e) {
      say(explain(e, 'Could not save that.'));
    }
  }

  /* ── SWITCHING TAB ────────────────────────────────────────────────────────
     A step tab reloads the list; the other three are already in `data` and
     reload nothing at all, so moving between Concern pages, Wording and
     Settings costs no request.

     THE SEARCH AND THE SCOPE ARE RESET ON THE WAY IN, and that is a decision
     rather than an oversight. Carrying "centella" from Cleanser to SPF would
     open SPF on "Nothing matches", which reads as an empty step when it is an
     empty search — and the empty steps are exactly what he is hunting. Each
     step therefore opens on what fills it. */
  function goTab(key){
    if (key === open) return;

    open = key;
    query = '';
    scope = '';
    page = 1;

    if (isStep(open)) {
      products = null;
      render();
      loadProducts();
    } else {
      render();
    }
  }

  function bind(){
    /* BOUND HERE RATHER THAN DELEGATED ON `document`, which is what the
       checkout screen does. This file's own header gives the reason:
       app.blade.php already binds around a dozen delegated listeners to
       `document`, each claiming a bare attribute name, and a click anywhere is
       handled by whichever of them matches. Binding per render keeps every
       listener on elements this screen drew. */
    var tabs = [].slice.call(document.querySelectorAll('[data-rtn-tab]'));

    tabs.forEach(function(btn, i){
      btn.onclick = function(){ goTab(btn.dataset.rtnTab); };

      /* Left/Right walk the strip, Home/End jump to its ends. Native <button>
         focus and Enter/Space already worked; this is what a strip of eight on
         one keyboard-driven screen is worth. Nothing here measures layout. */
      btn.onkeydown = function(ev){
        var to = null;

        if (ev.key === 'ArrowRight') to = tabs[(i + 1) % tabs.length];
        else if (ev.key === 'ArrowLeft') to = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (ev.key === 'Home') to = tabs[0];
        else if (ev.key === 'End') to = tabs[tabs.length - 1];
        else return;

        ev.preventDefault();
        if (to) { to.focus(); goTab(to.dataset.rtnTab); }
      };
    });

    /* ── THE SEARCH RUNS AS HE TYPES — Lane Q, round 3 ────────────────────
       THE BUG THE OWNER REPORTED, and it is this line: "the product search on
       build routine page was not working and was not showing any results upon
       search". It was bound to `onchange`, which on a text input fires on BLUR
       or ENTER and on nothing else. Typing a word did LITERALLY NOTHING — no
       request, no spinner, no change to the list — and the rows already on
       screen just sat there, which reads exactly like a search that ignores
       you. Measured in Chromium against the shipped 2.60.268 screen: typing
       "centella" fired ZERO requests and left all 24 rows showing.

       It is bound to `input` now, so every keystroke counts, DEBOUNCED at
       250ms. The number is chosen, not picked: a fast typist's inter-keystroke
       gap is roughly 120-180ms, so 250 collapses a word typed at speed into one
       request instead of eight, while staying under the ~300ms at which a
       control stops feeling like it is responding to you. Measured on this
       catalogue the round trip is ~25ms, so the debounce — not the server — is
       what he will feel.

       ENTER STILL SEARCHES, IMMEDIATELY, and cancels the pending timer so it
       cannot fire a second identical request behind it. `onchange` is gone
       entirely: with `input` bound it could only ever duplicate a request that
       had already gone. Measured on the shipped screen, pressing Enter fired
       the request TWICE, because onchange and onkeydown both answered it. */
    var q = document.querySelector('#rtn-q');

    if (q) {
      q.oninput = function(){
        var typed = q.value.trim();

        if (typed === query) return;      // a keystroke that changed nothing

        clearTimeout(typeTimer);
        typeTimer = setTimeout(function(){
          query = typed;
          page = 1;
          loadProducts();
        }, 250);
      };

      q.onkeydown = function(ev){
        if (ev.key !== 'Enter') return;

        ev.preventDefault();
        clearTimeout(typeTimer);

        var typed = q.value.trim();
        if (typed === query && products) return;   // already showing this answer

        query = typed;
        page = 1;
        loadProducts();
      };

      /* The × on a type=search input clears the field and fires `input` in
         Chrome, but `search` is the event every browser agrees on. Bound as
         well as, not instead of. */
      q.onsearch = function(){
        clearTimeout(typeTimer);
        if (q.value.trim() === query) return;
        query = q.value.trim();
        page = 1;
        loadProducts();
      };
    }

    var sc = document.querySelector('#rtn-scope');
    if (sc) sc.onchange = function(){ scope = sc.value; page = 1; loadProducts(); };

    var showUntagged = document.querySelector('#rtn-show-untagged');
    if (showUntagged) showUntagged.onclick = function(){ scope = 'none'; page = 1; loadProducts(); };

    /* ONE PRESS, INTO THE OPEN STEP AND NO OTHER. `open` is read here, at the
       moment of the click, so this cannot place a product in a step whose tab
       is not showing. */
    document.querySelectorAll('[data-rtn-use]').forEach(function(btn){
      btn.onclick = function(){
        var role = roleFor(open);
        if (!role) return;

        tag(btn.dataset.rtnUse, {role: open}, 'Step set to ' + role.label + '.');
      };
    });

    var prev = document.querySelector('#rtn-prev');
    if (prev) prev.onclick = function(){ if (page > 1) { page--; loadProducts(); } };

    var next = document.querySelector('#rtn-next');
    if (next) next.onclick = function(){ page++; loadProducts(); };

    document.querySelectorAll('[data-rtn-rolefor]').forEach(function(sel){
      sel.onchange = function(){
        tag(sel.dataset.rtnRolefor, {role: sel.value || null},
            sel.value ? 'Step set to ' + roleLabel(sel.value) + '.' : 'Step cleared.');
      };
    });

    document.querySelectorAll('[data-rtn-concern]').forEach(function(btn){
      btn.onclick = function(){
        var id = Number(btn.dataset.rtnFor);
        var row = (products && products.products || []).filter(function(p){ return p.id === id; })[0];
        if (!row) return;

        var next = (row.concerns || []).slice();
        var at = next.indexOf(btn.dataset.rtnConcern);
        if (at > -1) next.splice(at, 1); else next.push(btn.dataset.rtnConcern);

        tag(id, {concerns: next}, next.length ? 'Concerns saved.' : 'Concerns cleared — suits every routine.');
      };
    });

    document.querySelectorAll('[data-rtn-step]').forEach(function(btn){
      btn.onclick = function(){ btn.classList.toggle('on'); };
    });

    document.querySelectorAll('[data-rtn-save]').forEach(function(btn){
      btn.onclick = async function(){
        var concern = btn.dataset.rtnSave;
        var steps = [];

        document.querySelectorAll('[data-rtn-of="' + concern + '"]').forEach(function(chip){
          if (chip.classList.contains('on')) steps.push(chip.dataset.rtnStep);
        });

        var payload = {
          title: (document.querySelector('[data-rtn-title="' + concern + '"]') || {}).value || null,
          blurb: (document.querySelector('[data-rtn-blurb="' + concern + '"]') || {}).value || null,
          coupon_code: (document.querySelector('[data-rtn-coupon="' + concern + '"]') || {}).value || null,
          position: Number((document.querySelector('[data-rtn-pos="' + concern + '"]') || {}).value || 0),
          is_enabled: (document.querySelector('[data-rtn-on="' + concern + '"]') || {}).value === '1',
          steps: steps
        };

        try {
          await api('/routines/' + concern, payload);
          say('Routine saved.');
          loadAll();
        } catch (e) {
          say(explain(e, 'Could not save that routine.'));
        }
      };
    });

    /* THE MODULE SWITCH. Confirmed on the way ON only: switching it off takes
       pages away from shoppers, which is recoverable in one press, while
       switching it on PUBLISHES two pages — and the demo rows, if he still has
       them. The asymmetry is deliberate. */
    var mod = document.querySelector('#rtn-module-toggle');

    if (mod) mod.onclick = async function(){
      var turningOn = !data.module_on;

      if (turningOn && !window.confirm(
        'Turn the routine section on?\n\nShoppers will be able to reach /routines and a page for each concern'
        + (((data.demo || {}).routines || 0) > 0
            ? ', and the demo products now in your catalogue will be on them.'
            : '.'))) {
        return;
      }

      mod.disabled = true;

      try {
        await api('/routines-module', {on: turningOn});
        say(turningOn ? 'The routine section is on.' : 'The routine section is off.');
        loadAll();
      } catch (e) {
        mod.disabled = false;
        say(explain(e, 'Could not change the routine section.'));
      }
    };

    /* DEMO DATA. Runs the shop's own demo endpoints, so it is the same import
       and the same removal as Store → Demo Content -- there is one ledger and
       one button behind two screens. */
    var demoIn = document.querySelector('#rtn-demo-import');

    if (demoIn) demoIn.onclick = async function(){
      demoIn.disabled = true;

      try {
        await api('/demo-content/routines/import', {});
        say('Demo routine products imported.');
        loadAll();
        if (isStep(open)) loadProducts();
      } catch (e) {
        demoIn.disabled = false;
        say(explain(e, 'Could not import the demo data.'));
      }
    };

    var demoOut = document.querySelector('#rtn-demo-remove');

    if (demoOut) demoOut.onclick = async function(){
      if (!window.confirm('Remove the demo routine products?\n\nOnly the rows the demo import created are deleted. '
        + 'Anything you tagged yourself is untouched.')) {
        return;
      }

      demoOut.disabled = true;

      try {
        await api('/demo-content/routines/remove', {});
        say('Demo routine products removed.');
        loadAll();
        if (isStep(open)) loadProducts();
      } catch (e) {
        demoOut.disabled = false;
        say(explain(e, 'Could not remove the demo data.'));
      }
    };

    var save = document.querySelector('#rtn-save-settings');
    if (save) save.onclick = async function(){
      var values = {};

      document.querySelectorAll('[data-rtn-set]').forEach(function(el){
        values[el.dataset.rtnSet] = el.value;
      });

      try {
        var body = await api('/routines-settings', {settings: values});
        if (data) data.settings = body.settings;
        say('Settings saved.');
        loadAll();
      } catch (e) {
        say(explain(e, 'Could not save the settings.'));
      }
    };
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
