{{--
    Appearance → Grid sections. (Lane GS — Phase 23)

    The owner:

      "DO ONE thing. prepare a proper grid section with all controls and it can
       be use anywhere, and can be edit that specific grid section. so this case
       we can re-use this grid section anywhere multiple times with different
       products etc selection."

    ── WHERE EACH PART OF THE ASK IS ON THIS SCREEN ──────────────────────────

      "re-use this grid section anywhere multiple times"  → card 1, Add a grid
      "can be edit that specific grid section"            → card 2, one editor
      "choose the brand, category, manual products"       → editor, Which products
      "4 by default on desktop"                           → editor, Layout
      "on mobile careousel"                               → editor, Layout
      "on desktop also give control to make it carousel"  → editor, Layout
      "bottom view all button (manual link)"              → editor, View all button
      the 4-up bundles row and the 5-up BEST SELLERS row  → card 1, the two presets
      a live preview, because every other Appearance
        screen in this console has one and he uses
        them daily                                        → editor, the frame

    ── THE CONTROLS ARE NOT WRITTEN OUT HERE ────────────────────────────────

    Every field in the editor is drawn from the payload's `tabs`, which the
    server builds with `ModuleSchema::tabs()` from `GridSections::SCHEMA`,
    `::TABS` and `::POLICY` — the same three constants the controller casts
    every write through. A hand-written list of inputs in this file would be a
    SECOND description of the same controls, which is the arrangement
    docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of: the picker
    offering an option the cast refuses, or the cast falling back to a default
    the picker does not show. Add a field to that constant and it appears here,
    is validated on the way in, and is persisted — from one statement.

    The three option sets that live in ANOTHER registry — the brands, the
    categories and the 28 card templates — arrive as their own top-level keys
    beside the tabs, because `ModuleSchema::fields()` deliberately emits a
    field's DECLARED options rather than its overrides. Its own comment names
    the two other screens that do the same and why.

    ── THE SCREEN BUFFERS ───────────────────────────────────────────────────

    Two objects, and the difference between them is the feature:

      saved   what the server last handed over. Never written to by a control.
              Discard goes back to it and "unsaved" is measured against it.
      draft   what the owner has typed. Every control writes here and nowhere
              else, and it reaches the database only when Save is pressed.

    A buffered editor that re-renders on every keystroke is the same defect
    with a Save button bolted on — the caret jumps and a <select> closes as it
    is opened — so a control's handler updates the draft, the unsaved bar and
    the preview, and touches no other element. The structural actions (add,
    duplicate, delete, reorder) are IMMEDIATE, because they create and destroy
    the rows the editor is editing and a buffered delete is a row that is gone
    on screen and present in the database.

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ─────────────────

    The section's responsiveness is two media queries over `--gs-d` and
    `--gs-m`. A media query asks the VIEWPORT how wide it is, not the box the
    preview is drawn in — so a preview injected straight into this page would
    resolve the desktop's four-across inside a 700px panel and show the owner a
    row the shop never draws. Inside an iframe the media queries resolve against
    the frame's own width, so the Phone / Tablet / Desktop buttons show what
    those widths really produce.

    Its contents come from the grid-sections preview endpoints, which render THE
    SAME PARTIAL the homepage renders, and the frame links the shop's own BUILT
    stylesheets so the card in it is the real card. A second copy of either here
    would disagree with the shop the first time either was touched — the fault
    `HomepageLayouts::summaries()` shipped, and the fault the cards-banner
    preview's own header records paying for with `--pink`.

    ── ORDERING IS SOMEWHERE ELSE, AND THE SCREEN SAYS SO ───────────────────

    Where a grid sits among the shop's OTHER sections is Appearance → Homepage,
    which is the one ordering mechanism this feature has and the one it
    inherits — an instance is a row in that screen's list like any of the
    seventeen. The ↑↓ here move the grids among THEMSELVES, which is a different
    question. Two answers to one question is how a screen ends up lying, so this
    one names both in as many words.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED gss- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name — [data-open], [data-tg], [data-pp] — and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It cannot reach that
    file's module-scoped constants — NAV, TITLES and ADMIN_BASE are const, not
    window properties — so it appends its own sidebar entry to the rendered nav
    and wraps window.go, exactly as the screens above it do.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.gss-wrap{display:grid;gap:16px;min-width:0}
.gss-wrap > *{min-width:0}
.gss-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.gss-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.gss-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.gss-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55}
.gss-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.55;margin:10px 0 0}
.gss-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0}
.gss-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));min-width:0}
.gss-fld{display:grid;gap:4px;min-width:0}
.gss-lab{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}
.gss-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.gss-in,.gss-sel{width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
     padding:8px 10px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
     background:var(--surface,#fff);color:inherit}
.gss-in:focus,.gss-sel:focus{outline:2px solid var(--accent,#E8919F);outline-offset:1px}
.gss-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.gss-btn:hover{border-color:var(--accent,#E8919F)}
.gss-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.gss-btn.is-danger{color:#B91C1C;border-color:#FCA5A5}
.gss-btn[disabled]{opacity:.5;cursor:default}
.gss-tiny{font:inherit;font-size:11px;padding:3px 8px;border-radius:7px;cursor:pointer;
          border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit}
.gss-sw{display:inline-flex;align-items:center;gap:9px;font-size:13px;cursor:pointer;min-width:0}
.gss-sw input{width:18px;height:18px;flex:0 0 auto}
.gss-list{display:grid;gap:9px;min-width:0}
.gss-item{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px}
.gss-item.is-open{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.06)}
.gss-item .gss-nm{font-weight:650;font-size:13px;min-width:0;flex:1 1 auto;overflow-wrap:anywhere}
.gss-item .gss-meta{font-size:11px;color:var(--ink-soft,#6b7280);width:100%;line-height:1.5}
.gss-pill{font-size:10.5px;font-weight:700;border-radius:999px;padding:2px 8px;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.gss-pill.is-on{background:#DCFCE7;border-color:#86EFAC;color:#166534}
.gss-empty{font-size:12.5px;color:var(--ink-soft,#6b7280);line-height:1.6;text-align:center;padding:16px 8px}
.gss-picked{display:grid;gap:7px;min-width:0;margin-top:10px}
.gss-prow{display:flex;gap:9px;align-items:center;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:6px 9px}
.gss-prow .gss-pn{flex:1 1 auto;min-width:0;font-size:12.5px;overflow-wrap:anywhere}
.gss-th{width:34px;height:34px;flex:0 0 auto;border-radius:7px;overflow:hidden;
        background:var(--code-bg,rgba(0,0,0,.05))}
.gss-th img{width:100%;height:100%;object-fit:cover;display:block}
.gss-results{display:grid;gap:6px;max-height:260px;overflow:auto;margin-top:9px;min-width:0}
.gss-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
           background:var(--code-bg,rgba(0,0,0,.04));padding:10px;display:grid;justify-items:center}
.gss-frame{width:100%;border:0;display:block;background:#fff}
.gss-widths{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}
.gss-widths .gss-tiny.is-on{border-color:var(--accent,#E8919F);font-weight:700}
.gss-foot{position:sticky;bottom:0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
          margin:16px -16px -16px;padding:12px 16px;border-top:1px solid var(--border,#e6e6e6);
          background:var(--surface,#fff);border-radius:0 0 var(--r,12px) var(--r,12px)}
.gss-tab{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;min-width:0}
.gss-tabs select[multiple]{padding:4px}
.gss-tr{white-space:nowrap;flex:0 0 auto}
.gss-foot.is-dirty{background:#f2f4fb;border-top-color:#cfd9ee}
.gss-state{flex:1 1 200px;min-width:0;font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280)}
.gss-state.is-bad{color:#9b1c1c;font-weight:600}
/* Lane HTE — the editor as numbered steps, each a calm block of one or two
   columns rather than an auto-fit grid that scatters six boxes across a row. */
.gss-step{border-top:1px solid var(--border,#e6e6e6);margin-top:18px;padding-top:16px;min-width:0}
.gss-sh{display:flex;gap:11px;align-items:flex-start;margin-bottom:13px;min-width:0}
.gss-num{flex:0 0 auto;width:24px;height:24px;border-radius:50%;background:var(--accent,#E8919F);color:#fff;
         font-size:12px;font-weight:700;line-height:24px;text-align:center}
.gss-shx{min-width:0}
.gss-shx .gss-title{font-size:15px}
.gss-shx .gss-sub{margin:0}
.gss-sb{display:grid;gap:14px;min-width:0;padding-left:35px}
.gss-sb > *{min-width:0}
.gss-two{display:grid;gap:12px 16px;grid-template-columns:repeat(2,minmax(0,1fr));min-width:0}
.gss-three{display:grid;gap:12px 16px;grid-template-columns:repeat(3,minmax(0,1fr));min-width:0}
.gss-col{display:grid;gap:12px;align-content:start;min-width:0;border:1px solid var(--border,#e6e6e6);
         border-radius:10px;padding:12px}
.gss-cap{font-size:12.5px;font-weight:650;margin:0}
.gss-info{margin:0;background:var(--code-bg,rgba(0,0,0,.04));border-radius:9px;padding:9px 11px}
.gss-big{align-items:flex-start}
.gss-big > span{display:grid;gap:2px}
.gss-cards{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));min-width:0}
.gss-choice,.gss-sg{position:relative;cursor:pointer;min-width:0}
.gss-choice input,.gss-sg input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.gss-choice{display:grid;gap:9px;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px}
.gss-choice.is-on{border-color:var(--accent,#E8919F);box-shadow:0 0 0 1px var(--accent,#E8919F);background:rgba(232,145,159,.06)}
.gss-choice:focus-within,.gss-sg:focus-within{outline:2px solid var(--accent,#E8919F);outline-offset:1px}
.gss-cn{font-size:12.5px;font-weight:600}
.gss-smp{display:flex;gap:10px;align-items:center;flex-wrap:nowrap;overflow:hidden;font-size:11px;color:var(--ink-soft,#6b7280)}
.gss-sb .gss-fld{align-content:start}
.gss-smp i{font-style:normal;white-space:nowrap}
.gss-smp-a i{padding-bottom:3px;border-bottom:2px solid transparent}
.gss-smp-a i.on{color:#1f2937;font-weight:700;border-bottom-color:#1f2937}
.gss-smp-b{gap:6px}
.gss-smp-b i{padding:3px 9px;border:1px solid var(--border,#e6e6e6);border-radius:999px}
.gss-smp-b i.on{background:#1f2937;border-color:#1f2937;color:#fff}
.gss-seg{display:inline-flex;max-width:100%;border:1px solid var(--border,#e6e6e6);border-radius:9px;overflow:hidden;justify-self:start}
.gss-sg + .gss-sg{border-left:1px solid var(--border,#e6e6e6)}
.gss-sg span{display:block;padding:7px 18px;font-size:12.5px;font-weight:600}
.gss-sg.is-on span{background:var(--accent,#E8919F);color:#fff}
.gss-pairs{display:grid;gap:10px;min-width:0}
.gss-pr{display:grid;gap:8px 14px;grid-template-columns:140px minmax(0,1fr) minmax(0,1fr);align-items:start;min-width:0}
.gss-ph{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-soft,#6b7280)}
.gss-pl{font-size:12.5px;font-weight:600;padding-top:8px}
.gss-pc{display:grid;gap:4px;min-width:0}
.gss-fld > .gss-help,.gss-pc > .gss-help{margin:0}
.gss-help:empty{display:none}
.gss-arx{display:flex;gap:6px;align-items:center;min-width:0}
.gss-arx .gss-in{flex:1 1 auto}
.gss-thd{display:flex;gap:8px;align-items:center;flex-wrap:wrap;min-width:0}
.gss-tnm{flex:1 1 160px;min-width:0;display:grid;gap:1px}
.gss-tnm b{font-size:13px;overflow-wrap:anywhere}
.gss-tmeta{font-size:11px;color:var(--ink-soft,#6b7280)}
.gss-fold{flex:0 0 auto;width:28px;height:28px;display:grid;place-items:center;padding:0;cursor:pointer;
          border:1px solid var(--border,#e6e6e6);border-radius:8px;background:var(--surface,#fff);color:inherit}
.gss-fold svg{width:14px;height:14px;transition:transform .15s}
.gss-tab.is-open .gss-fold svg{transform:rotate(90deg)}
.gss-tb{display:grid;gap:12px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border,#e6e6e6);min-width:0}
.gss-tab:not(.is-open) .gss-tb{display:none}
.gss-tab.is-open{border-color:#d5d9e2}
@media (max-width:640px){
  .gss-card{padding:13px}
  .gss-foot{margin:13px -13px -13px;padding:10px 13px}
  .gss-sb{padding-left:0}
  .gss-two,.gss-three,.gss-cards{grid-template-columns:minmax(0,1fr)}
  .gss-pr{grid-template-columns:minmax(0,1fr);gap:6px}
  .gss-pr + .gss-pr{border-top:1px solid var(--border,#e6e6e6);padding-top:10px}
  .gss-ph{display:none}
  .gss-pl{padding-top:0}
  .gss-pc::before{content:attr(data-lang);font-size:10.5px;font-weight:700;letter-spacing:.05em;
                  text-transform:uppercase;color:var(--ink-soft,#6b7280)}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'gridsections';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;        /* GET /admin-api/grid-sections */
  var openId = null;      /* the instance whose editor is showing */
  var saved = null;       /* the server's answer: {section, tabs, manual, …} */
  var draft = null;       /* {values:{}, manual_ids:[]} — what has been typed */
  var results = null;     /* the manual picker's last search */
  var tabResults = {};    /* Lane HTB: each product tab's picker search, by tab index */
  var previewLang = 'en'; /* Lane HTB: the preview in English or Arabic */
  var previewWidth = 1280;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;
  var pvSeq = 0;
  var pvTimer = null;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN read from the XSRF-TOKEN COOKIE, which is what app.blade.php's
     own api() has always sent. A <meta name="csrf-token"> tag is what the
     shoppable-video screens reached for and this console does not render one. */
  async function api(path, method, body){
    var opts = {method: method || 'GET', headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
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

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A 404 from any of these endpoints almost always means the package shipped
     without its clear_caches migration having run, so the compiled route table
     does not know these paths. Said plainly rather than drawing an empty
     screen, which here would read as "you have no grids" — the exact wrong
     conclusion, and one an owner would answer by building them all again. */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Grid sections endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Grid sections',
      /* NOT the four-square icon: `layout` — Appearance → Product grid, the
         shop-wide column and tile settings — already uses exactly that glyph,
         and two identical icons two rows apart in the same group is a sidebar
         the owner has to read rather than scan. Two stacked bands, each split,
         which is what a page of repeated grid sections looks like. */
      icon:   '<rect x="3" y="4" width="18" height="7" rx="1.5"/><path d="M12 4v7"/><rect x="3" y="14" width="18" height="6" rx="1.5"/>',
      group:  'Appearance',
      after:  ['banners', 'hpcontent', 'homepage']
    });
  }

  /* -------------------------------------------------------------- the draft */
  /* The FIELD KEYS COME FROM THE PAYLOAD, not from a list in this file. A
     hand-kept list here is the "control with no writer behind it" shape
     AdminConsoleWriteTokenTest was written after: add a field to
     GridSections::SCHEMA, forget to add it here, and the screen shows a box
     that saves nothing. */
  function fieldKeys(){
    var out = [];
    (saved && saved.tabs ? saved.tabs : []).forEach(function(t){
      (t.fields || []).forEach(function(f){ out.push(f.key); });
    });
    return out;
  }

  function fieldOf(key){
    var found = null;
    (saved && saved.tabs ? saved.tabs : []).forEach(function(t){
      (t.fields || []).forEach(function(f){ if (f.key === key) found = f; });
    });
    return found;
  }

  function startDraft(){
    draft = null;
    if (!saved) return;

    draft = {values: {}, manual_ids: (saved.section.manual_ids || []).slice(),
      /* Lane HTB: the product tabs, a deep copy so typing never reaches `saved`. */
      ptabs: saved.product_tabs ? JSON.parse(JSON.stringify(saved.product_tabs)) : null,
      /* Lane GAR: the heading's Arabic twins while tabs are OFF (with tabs on
         they live in ptabs, as before). Same five keys, same column. */
      words: wordsOf(saved.product_tabs || saved.section_words),
      tab_style: saved.tab_style || 'a'};
    fieldKeys().forEach(function(k){
      var f = fieldOf(k);
      draft.values[k] = f ? f.value : null;
    });
  }

  function savedValue(key){
    var f = fieldOf(key);
    return f ? f.value : null;
  }

  function changed(){
    if (!draft || !saved) return [];

    var out = [];

    fieldKeys().forEach(function(k){
      if (String(draft.values[k]) !== String(savedValue(k))) out.push(k);
    });

    if (draft.manual_ids.join(',') !== (saved.section.manual_ids || []).join(',')) {
      out.push('manual_ids');
    }

    if (tabsChanged()) out.push('product_tabs');

    return out;
  }

  function dirty(){ return changed().length > 0; }

  /* Lane HTB: the tabs and their style, compared as a whole. Lane GAR: with
     tabs off, the heading's Arabic words are what the column holds. */
  function tabsChanged(){
    if (!draft || !saved) return false;
    return JSON.stringify(outTabs()) !== JSON.stringify(saved.product_tabs || wordsOnly(wordsOf(saved.section_words)))
      || (draft.ptabs && String(draft.tab_style) !== String(saved.tab_style || 'a'));
  }

  var WORD_KEYS = ['eyebrow', 'eyebrow_ar', 'heading_ar', 'sub_ar', 'view_all_ar'];

  /* The five section words of a stored tabs object (or blanks), in the order the server sends them. */
  function wordsOf(src){
    var w = {};
    WORD_KEYS.forEach(function(k){ w[k] = src && src[k] != null ? String(src[k]) : ''; });
    return w;
  }

  /* What the column holds for an ordinary grid: its words with no tab, or null when there are none. */
  function wordsOnly(w){
    var any = WORD_KEYS.some(function(k){ return String(w[k] || '').trim() !== ''; });
    if (!any) return null;
    var out = wordsOf(w);
    out.align = 'center';
    out.list = [];
    return out;
  }

  /* What Save (and the preview) sends as product_tabs. */
  function outTabs(){ return draft.ptabs || wordsOnly(draft.words); }

  /* LEAVING KEEPS THE DRAFT, AND ASKS NOTHING. (Lane PM)
     This used to be window.confirm("You have N unsaved change(s). Leave them?")
     on every door out of the editor — the owner's "weired popup". The typing
     is handed to Unfinished in the top bar instead
     (partials/unfinished-drafts.blade.php), and reopening this grid puts it
     back with a bar that says so. */
  function mayLeave(){
    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('gridsections');
    return true;
  }

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: 'gridsections', screen: SCREEN,
    label: function(){
      var name = saved && saved.section ? String(saved.section.name || '') : '';
      return 'Appearance → Grid sections · ' + (name || ('grid ' + openId));
    },
    entity: function(){ return saved ? openId : null; },
    values: function(){
      if (!draft || !saved) return null;
      var out = {};
      fieldKeys().forEach(function(k){ out['v.' + k] = draft.values[k]; });
      out.manual_ids = draft.manual_ids.join(',');
      out.ptabs = JSON.stringify(draft.ptabs || null);
      out.words = JSON.stringify(draft.words);
      out.tab_style = draft.tab_style;
      return out;
    },
    set: function(k, v){
      if (k === 'manual_ids') {
        draft.manual_ids = String(v || '').split(',').filter(Boolean).map(Number);
        return;
      }
      if (k === 'ptabs') { try { draft.ptabs = JSON.parse(v); } catch (e) {} return; }
      if (k === 'words') { try { draft.words = wordsOf(JSON.parse(v)); } catch (e) {} return; }
      if (k === 'tab_style') { draft.tab_style = v === 'b' ? 'b' : 'a'; return; }
      if (k.indexOf('v.') === 0 && fieldKeys().indexOf(k.slice(2)) !== -1) draft.values[k.slice(2)] = v;
    },
    count: function(){ return changed().length; },
    render: function(){ render(); refreshPreview(); },
    save: function(){ var b = document.querySelector('#gss-save'); if (b) b.click(); },
    /* The row is chosen BEFORE go(), whose entry branch reopens whatever
       openId names -- two openEditor() calls in flight would race. */
    open: function(id){ openId = Number(id); saved = null; draft = null; window.go(SCREEN); }
  });

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      /* Asked BEFORE anything is repainted, so Cancel really does leave the
         owner where he was rather than on a half-torn-down screen. */
      /* The box he was typing in fires `change` when the screen is torn down
         under it, and its handler writes into the draft -- so it is blurred
         HERE, while the draft still exists. Measured in Chromium: leaving with
         the cursor in the set's name threw "Cannot read properties of null
         (reading 'set')" from the name box's own handler. (Lane PM) */
      var host = document.querySelector('#content');
      var focused = document.activeElement;
      if (host && focused && focused.blur && host.contains(focused)) focused.blur();
      mayLeave();
      draft = null;
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Grid sections';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    /* render() BEFORE load(), synchronously — the condition app.blade.php's
       LATE_RENDERED set carries. The replay's marker inside #content has to be
       destroyed by the time the async load's task runs, or the screen is drawn
       twice. */
    render();
    load();
    /* Coming back to a grid that was open when he left (Lane PM): reopen it,
       which also brings any unfinished changes to it back. Without this the
       editor was drawn from a draft dropped on the way out. */
    if (openId !== null) openEditor(openId);
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/grid-sections');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read your product grids.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openEditor(id){
    openId = id;
    saved = null;
    draft = null;
    results = null;
    openTabs = {0: true};
    render();

    try {
      saved = await api('/grid-sections/' + encodeURIComponent(id));
      startDraft();
    } catch (e) {
      say(explain(e, 'Could not open that grid.'));
      openId = null;
    }

    render();
    refreshPreview();
    /* Unfinished changes to this grid, if any, come back now. */
    if (saved && window.kbbDrafts) window.kbbDrafts.ready('gridsections');
  }

  /* The preview, redrawn from the BUFFER and debounced. Debounced because a
     text box fires `input` on every keystroke and a request per keystroke is a
     request per keystroke; 260ms is below the point a redraw reads as a
     response to something else. */
  function refreshPreview(){
    if (pvTimer) { clearTimeout(pvTimer); pvTimer = null; }
    pvTimer = setTimeout(drawPreview, 260);
  }

  async function drawPreview(){
    var stage = document.querySelector('#gss-stage');
    if (!stage || openId === null || !draft) return;

    var mine = ++pvSeq;

    try {
      var body = await api('/grid-sections/' + encodeURIComponent(openId) + '/preview', 'POST', {
        values: draft.values,
        manual_ids: draft.manual_ids,
        product_tabs: outTabs(),
        tab_style: draft.tab_style,
        lang: previewLang
      });
      if (mine !== pvSeq) return;
      paintPreview(stage, body);
    } catch (e) {
      if (mine !== pvSeq) return;
      stage.innerHTML = '<div class="gss-empty">' + esc(explain(e, 'Could not draw the preview.')) + '</div>';
    }
  }

  /* srcdoc rather than a URL: there is nothing to fetch — the server already
     handed us the section's markup — and a second document on a real address
     would need a route of its own, a capability of its own and a reason. The
     height is fixed per width rather than measured, because measuring it would
     mean reaching into the frame for a box, which is the one thing this whole
     feature is built not to do. */
  function paintPreview(stage, body){
    if (!body || body.empty) {
      stage.innerHTML = '<div class="gss-empty">Nothing to draw yet — this grid\'s selection returned no products. '
        + 'A grid with nothing in it draws nothing on the shop rather than an empty heading.</div>';
      return;
    }

    var links = (body.stylesheets || []).map(function(href){
      return '<link rel="stylesheet" href="' + esc(href) + '">';
    }).join('');

    var doc = '<!doctype html><html' + (body.dir === 'rtl' ? ' dir="rtl" lang="ar"' : '') + '><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + links
      + '<style>html,body{margin:0;padding:0;background:#fff}body{padding:14px 0}'
      + '.sec{padding:0}'
      /* THE HOVER-ONLY CHROME IS SUPPRESSED, AND SAYING WHY MATTERS.
         `.qv-btn` and `.heart` are positioned and faded in by an inline <style>
         in layouts/store.blade.php, which this document does not have and must
         not copy — a second copy of another file's card CSS is exactly the
         fault the cards-banner preview's own header records paying for with
         `--pink`. Drawn without those rules the quick-view chip renders as a
         line of plain text in the corner of every tile, which is a picture of
         something the shop never shows. The preview's job is the LAYOUT — how
         many across, how wide, where it wraps — and a frame with no pointer
         cannot show hover state honestly either way. */
      + '.qv-btn,.heart{display:none}</style>'
      + (body.css || '')
      + '</head><body>' + body.html + '</body></html>';

    var height = previewWidth <= 430 ? 620 : (previewWidth <= 800 ? 560 : 520);

    stage.innerHTML = '<div style="width:' + previewWidth + 'px;max-width:100%">'
      + '<iframe class="gss-frame" style="height:' + height + 'px" '
      + 'sandbox="allow-same-origin" title="Product grid preview" srcdoc="' + esc(doc) + '"></iframe></div>';
  }

  /* ---------------------------------------------------------------- views */
  function listView(){
    var rows = (data.sections || []);

    var html = '<div class="gss-card"><div class="gss-title">Your product grids</div>'
      + '<p class="gss-sub">One grid section, used as many times as you like. Each one has its own heading, '
      + 'its own products and its own layout. Build it here; decide where it sits on the page in '
      + 'Appearance → Homepage, where every grid you build appears as a row alongside the shop\'s own sections.</p>';

    if (!rows.length) {
      html += '<div class="gss-empty">No grids yet. Start from one of the two below, or from a blank one.</div>';
    } else {
      html += '<div class="gss-list">';

      rows.forEach(function(s, i){
        html += '<div class="gss-item' + (s.id === openId ? ' is-open' : '') + '">'
          + '<span class="gss-nm">' + esc(s.name) + '</span>'
          + '<span class="gss-pill' + (s.status === 'publish' ? ' is-on' : '') + '">'
          + (s.status === 'publish' ? 'Published' : 'Draft') + '</span>'
          + '<button type="button" class="gss-tiny" data-gss-up="' + s.id + '"'
          + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button>'
          + '<button type="button" class="gss-tiny" data-gss-down="' + s.id + '"'
          + (i === rows.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>'
          + '<button type="button" class="gss-btn" data-gss-edit="' + s.id + '">Edit</button>'
          + '<button type="button" class="gss-tiny" data-gss-dup="' + s.id + '">Duplicate</button>'
          + '<button type="button" class="gss-tiny gss-btn is-danger" data-gss-del="' + s.id + '">Delete</button>'
          + '<div class="gss-meta">' + esc(describe(s)) + ' · On Appearance → Homepage it is the row called “'
          + esc(s.name) + '”.</div>'
          + '</div>';
      });

      html += '</div>';
    }

    html += '<div class="gss-row" style="margin-top:13px">'
      + '<button type="button" class="gss-btn is-primary" data-gss-add="">Add a blank grid</button>';

    (data.presets || []).forEach(function(p){
      html += '<button type="button" class="gss-btn" data-gss-add="' + esc(p.key) + '">'
        + esc(p.label) + '</button>';
    });

    html += '</div>'
      + '<p class="gss-note">A new grid is always created as a DRAFT, whatever you start it from, so nothing '
      + 'appears on the front page before you have looked at it. The ↑↓ above order the grids among '
      + 'themselves; where they sit among the shop\'s other sections is Appearance → Homepage.</p>'
      + '</div>';

    return html;
  }

  /* One line of plain English about what a grid is set to, so the list is
     readable without opening every one. */
  function describe(s){
    var v = s.values || {};
    var source = {
      bestsellers: 'Best sellers', newest: 'Newest', onsale: 'On sale',
      featured: 'Featured', brand: 'One brand', category: 'One category',
      manual: 'A list you picked'
    }[v.source] || v.source;

    var desk = v.desktop_layout === 'carousel'
      ? ('carousel, ' + v.desktop_cols + ' in view')
      : (v.desktop_cols + ' columns');

    var mob = v.mobile_layout === 'carousel'
      ? ('carousel, ' + v.mobile_cols + ' in view')
      : (v.mobile_cols + ' columns');

    if (s.tab_count) source = 'Product tabs (' + s.tab_count + ' tab' + (s.tab_count === 1 ? '' : 's') + ')';

    return source + ' · ' + v.count + ' on desktop (' + desk + ') · '
      + v.mobile_count + ' on mobile (' + mob + ')';
  }

  function fieldHTML(f){
    var id = 'gss-f-' + f.key;
    var help = f.help ? '<p class="gss-help">' + esc(f.help) + '</p>' : '';
    var value = draft.values[f.key];

    if (f.type === 'bool') {
      return '<div class="gss-fld"><label class="gss-sw">'
        + '<input type="checkbox" id="' + id + '" data-gss-key="' + esc(f.key) + '"'
        + (value ? ' checked' : '') + '>'
        + '<span>' + esc(f.label) + '</span></label>' + help + '</div>';
    }

    if (f.type === 'select' || f.type === 'skin') {
      /* The option set is the field's own where it declares one, and the
         payload's top-level set where it does not — the brands, the categories
         and the 28 card templates live in another registry and travel beside
         the tabs for the reason ModuleSchema::fields() gives. Either way it is
         the SAME set the server's cast checks against. */
      var opts = f.options || {};

      if (f.key === 'source_brand_id') opts = saved.brands || opts;
      if (f.key === 'source_category_id') opts = saved.categories || opts;
      if (f.key === 'skin') opts = saved.skins || opts;

      var body = '';

      Object.keys(opts).forEach(function(k){
        body += '<option value="' + esc(k) + '"'
          + (String(k) === String(value) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>';
      });

      return '<div class="gss-fld"><label class="gss-lab" for="' + id + '">' + esc(f.label) + '</label>'
        + '<select class="gss-sel" id="' + id + '" data-gss-key="' + esc(f.key) + '">' + body + '</select>'
        + help + '</div>';
    }

    var type = f.type === 'int' ? 'number' : 'text';

    return '<div class="gss-fld"><label class="gss-lab" for="' + id + '">' + esc(f.label) + '</label>'
      + '<input class="gss-in" type="' + type + '" id="' + id + '" data-gss-key="' + esc(f.key) + '"'
      + ' value="' + esc(value) + '">' + help + '</div>';
  }

  function editorView(){
    if (openId === null) return '';

    if (!saved) {
      return '<div class="gss-card"><div class="gss-empty">Opening…</div></div>';
    }

    var html = '<div class="gss-card"><div class="gss-title">Editing “' + esc(saved.section.name) + '”</div>'
      + '<p class="gss-sub">Every control below belongs to THIS grid. Build another one and it has its own.</p>'
      + '<div id="gss-draftbar"></div>'
      + stepsView();

    html += '<div class="gss-step"><div class="gss-title">How it will look</div>'
      + '<p class="gss-sub">The shop\'s own template, drawn from what you have typed — not from what is saved.</p>'
      + '<div class="gss-widths">'
      + [[390, 'Phone'], [768, 'Tablet'], [1280, 'Desktop']].map(function(w){
          return '<button type="button" class="gss-tiny' + (previewWidth === w[0] ? ' is-on' : '') + '"'
            + ' data-gss-w="' + w[0] + '">' + w[1] + ' · ' + w[0] + 'px</button>';
        }).join('')
      + [['en', 'English'], ['ar', 'العربية · Arabic']].map(function(l){
          return '<button type="button" class="gss-tiny' + (previewLang === l[0] ? ' is-on' : '') + '"'
            + ' data-gss-lang="' + l[0] + '">' + l[1] + '</button>';
        }).join('')
      + '</div><div class="gss-stage" id="gss-stage"><div class="gss-empty">Drawing…</div></div></div>';

    html += '<div class="gss-foot" id="gss-foot">'
      + '<span class="gss-state" id="gss-state"></span>'
      + '<button type="button" class="gss-btn" id="gss-discard">Discard</button>'
      + '<button type="button" class="gss-btn is-primary" id="gss-save">Saved</button>'
      + '<button type="button" class="gss-btn" id="gss-close">Close</button>'
      + '</div></div>';

    return html;
  }

  /* ═══════════════════════ Lane HTE — the editor as steps ════════════════
     The owner, on this editor with tabs on: "the backend layout is very
     scattered, can i get the nice and easy to understand and managed layout."

     ONLY THE ARRANGEMENT CHANGED. Every control below is the same control it
     was — same data-gss-key / data-gss-t / data-gss-tr, writing the same
     draft — so the saved payload, the API and the shop are untouched. Fields
     still come from the payload (fieldOf); this function only decides WHERE
     each one sits. A schema field it does not place is drawn at the end of
     "Look" (`placed` below), so adding one to GridSections::SCHEMA still makes
     it appear here without touching this file.

     Controls that do nothing in the current mode — the grid's own "Which
     products" while tabs are on, the brand box while the source is not a
     brand — are not drawn. Not drawn is not cleared: their values stay in
     the draft exactly as loaded and are saved exactly as they were. */
  var openTabs = {0: true};   /* which tab cards are expanded; the first, by default */

  function stepHTML(n, id, title, sub, body, extra){
    return '<section class="gss-step' + (extra ? ' ' + extra : '') + '" data-gss-step="' + id + '">'
      + '<div class="gss-sh"><span class="gss-num">' + n + '</span><div class="gss-shx">'
      + '<div class="gss-title">' + esc(title) + '</div>'
      + (sub ? '<p class="gss-sub">' + esc(sub) + '</p>' : '') + '</div></div>'
      + '<div class="gss-sb">' + body + '</div></section>';
  }

  function stepsView(){
    var placed = {};
    var on = !!draft.ptabs;
    var put = function(k){ placed[k] = 1; var f = fieldOf(k); return f ? fieldHTML(f) : ''; };
    var src = draft.values.source;
    var html = '';

    /* 1 · Basics */
    html += stepHTML(1, 'basics', 'Basics', 'What you call this section, and whether it is live.',
      '<div class="gss-two">' + put('name') + put('status') + '</div>');

    /* 3 and 4 are built before 2 so that 2's catch-all knows what they placed. */
    var heading = headingStep(on, placed);

    var products;
    if (on) {
      ['source', 'source_brand_id', 'source_category_id', 'include_children'].forEach(function(k){ placed[k] = 1; });
      products = '<p class="gss-note gss-info">Each tab chooses its own products in step 5, so “Which products” is not used while this section shows as tabs.</p>'
        + '<div class="gss-two">' + put('count') + put('mobile_count') + '</div>'
        + '<p class="gss-help" style="margin:-6px 0 0">These two numbers apply to every tab.</p>';
    } else {
      var which = put('source');
      var pick = '';
      ['source_brand_id', 'source_category_id', 'include_children'].forEach(function(k){ placed[k] = 1; });
      if (src === 'brand') pick = put('source_brand_id');
      if (src === 'category') pick = put('source_category_id');
      products = '<div class="gss-two">' + which + pick + '</div>'
        + (src === 'category' ? put('include_children') : '')
        + '<div class="gss-two">' + put('count') + put('mobile_count') + '</div>'
        + manualView();
    }

    /* 2 · Look */
    var look = '<label class="gss-sw gss-big"><input type="checkbox" data-gss-tabson' + (on ? ' checked' : '') + '>'
      + '<span><b>Show this section as tabs</b><span class="gss-help">Best Sellers, Trending, New Arrivals… one section, '
      + 'each tab with its own products and its own order.</span></span></label>';

    if (on) look += '<div class="gss-two">' + styleChoiceHTML() + alignHTML() + '</div>';

    look += '<div class="gss-two">'
      + '<div class="gss-col"><div class="gss-cap">On a laptop</div>' + put('desktop_layout') + put('desktop_cols') + put('per_d') + put('arrows_d') + '</div>'
      + '<div class="gss-col"><div class="gss-cap">On a phone</div>' + put('mobile_layout') + put('mobile_cols') + put('per_m') + put('arrows_m') + '</div>'
      + '</div>'
      + '<div class="gss-two">' + put('skin') + put('card_label') + '</div>'
      + put('show_rank');

    var rest = fieldKeys().filter(function(k){ return !placed[k]; });
    if (rest.length) look += '<div class="gss-two">' + rest.map(put).join('') + '</div>';

    if (on) look += '<p class="gss-help" style="margin:0">Layout, columns and cards apply to every tab.</p>';

    html += stepHTML(2, 'look', 'Look', on
      ? 'Tabs are on. Choose how the tab row looks, and how the products sit on a laptop and on a phone.'
      : 'One grid of products, or tabs. And how the products sit on a laptop and on a phone.', look);

    html += stepHTML(3, 'heading', 'Heading', on
      ? 'The words above the section, English beside Arabic. An empty Arabic box shows the English on the Arabic shop.'
      : 'The words above the grid, English beside Arabic, and the “View all” button under it. An empty Arabic box shows the English on the Arabic shop.', heading);

    html += stepHTML(4, 'products', 'Products', on
      ? 'How many products each tab shows.'
      : 'Where this grid’s products come from, and how many.', products);

    if (on) html += stepHTML(5, 'tabs', 'Tabs', 'Each tab has its own title, its own products and its own order. Open a tab with its arrow.',
      tabsView(), 'gss-tabs');

    return html;
  }

  /* The English box of a pair: the schema field itself, without its label
     (the row carries the label), same id and same data-gss-key as before. */
  function enField(key, placed, withHelp){
    placed[key] = 1;
    var f = fieldOf(key);
    if (!f) return '';
    return '<input class="gss-in" type="text" id="gss-f-' + esc(key) + '" data-gss-key="' + esc(key) + '"'
      + ' value="' + esc(draft.values[key]) + '">'
      + (withHelp && f.help ? '<p class="gss-help">' + esc(f.help) + '</p>' : '');
  }

  function pairRow(label, forId, en, ar){
    return '<div class="gss-pr"><div class="gss-pl">' + (forId ? '<label for="' + forId + '">' + esc(label) + '</label>' : esc(label)) + '</div>'
      + '<div class="gss-pc" data-lang="English">' + en + '</div>'
      + (ar === null ? '' : '<div class="gss-pc" data-lang="Arabic">' + ar + '</div>') + '</div>';
  }

  function headingStep(on, placed){
    var html = '';
    var f = fieldOf('show_heading');
    placed.show_heading = 1;
    if (f) html += fieldHTML(f);

    /* Lane GAR: English | Arabic in BOTH modes ("this heading on the homepage
       is not translated, please do"). Tabs on, the words are in ptabs; off,
       in draft.words — the same keys, saved into the same column. */
    var t = draft.ptabs || draft.words;
    html += '<div class="gss-pairs">'
      + '<div class="gss-pr gss-ph"><span></span><span>English</span><span>العربية · Arabic</span></div>';

    html += pairRow('Small line above', '',
        '<input class="gss-in" data-gss-t="eyebrow" maxlength="190" value="' + esc(t.eyebrow || '') + '">',
        arabicBox('eyebrow_ar', t.eyebrow_ar, 190))
      + pairRow('Heading', 'gss-f-heading', enField('heading', placed, !on), arabicBox('heading_ar', t.heading_ar, 190))
      + pairRow('Sub-heading', 'gss-f-subheading', enField('subheading', placed, false), arabicBox('sub_ar', t.sub_ar, 255))
      + pairRow('“View all” text', 'gss-f-view_all_label', enField('view_all_label', placed, true), arabicBox('view_all_ar', t.view_all_ar, 120))
      + '</div>';

    placed.show_view_all = 1;
    placed.view_all_url = 1;

    if (on) {
      html += '<p class="gss-help" style="margin:0">With tabs, each tab has its own “View all” link (step 5). A tab with no link shows no button.</p>';
    } else {
      var sw = fieldOf('show_view_all');
      var url = fieldOf('view_all_url');
      html += '<div class="gss-two">' + (sw ? fieldHTML(sw) : '') + (url ? fieldHTML(url) : '') + '</div>';
    }

    return html;
  }

  /* Tab style as two choice cards, each with a tiny drawn sample. The radios
     carry data-gss-t="@style", the attribute the old <select> carried, so the
     same handler writes draft.tab_style. Keys and fallback names come from
     the payload's tab_options. */
  function styleChoiceHTML(){
    var o = tabsOpt();
    var names = {a: 'A · Underlined words', b: 'B · Rounded buttons'};
    var html = '<div class="gss-fld"><span class="gss-lab">Tab style</span><div class="gss-cards" role="radiogroup" aria-label="Tab style">';
    Object.keys(o.styles || {}).forEach(function(k){
      var on = String(draft.tab_style) === String(k);
      var sample = k === 'b' ? 'gss-smp gss-smp-b' : 'gss-smp gss-smp-a';
      html += '<label class="gss-choice' + (on ? ' is-on' : '') + '">'
        + '<input type="radio" name="gss-tstyle" value="' + esc(k) + '" data-gss-t="@style"' + (on ? ' checked' : '') + '>'
        + '<span class="' + sample + '" aria-hidden="true"><i class="on">Best Sellers</i><i>Trending</i><i>New</i></span>'
        + '<span class="gss-cn">' + esc(names[k] || o.styles[k]) + '</span></label>';
    });
    return html + '</div></div>';
  }

  function alignHTML(){
    var o = tabsOpt();
    var opts = o.align || {};
    var names = {left: 'Left', center: 'Centre'};
    var keys = Object.keys(opts).sort(function(a, b){ return (a === 'left' ? 0 : 1) - (b === 'left' ? 0 : 1); });
    var html = '<div class="gss-fld"><span class="gss-lab">Alignment — heading and tabs</span>'
      + '<div class="gss-seg" role="radiogroup" aria-label="Alignment">';
    keys.forEach(function(k){
      var on = String(draft.ptabs.align) === String(k);
      html += '<label class="gss-sg' + (on ? ' is-on' : '') + '">'
        + '<input type="radio" name="gss-talign" value="' + esc(k) + '" data-gss-t="align"' + (on ? ' checked' : '') + '>'
        + '<span>' + esc(names[k] || opts[k]) + '</span></label>';
    });
    return html + '</div><p class="gss-help">“Left” starts at the right-hand side on the Arabic shop, as Arabic reads.</p></div>';
  }

  function manualView(){
    if (draft.values.source !== 'manual') {
      return '<p class="gss-note">Set “Which products” to “A list I pick myself” and a picker appears here.</p>';
    }

    var byId = {};
    (saved.manual || []).forEach(function(p){ byId[p.id] = p; });
    (results || []).forEach(function(p){ byId[p.id] = p; });

    var html = '<div style="margin-top:12px"><div class="gss-lab">Your list, in your own order</div>'
      + '<div class="gss-picked">';

    if (!draft.manual_ids.length) {
      html += '<div class="gss-empty">Nothing picked yet. Search below.</div>';
    } else {
      draft.manual_ids.forEach(function(id, i){
        var p = byId[id];
        html += '<div class="gss-prow">'
          + '<span class="gss-th">' + (p && p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
          + '<span class="gss-pn">' + esc(p ? p.name : ('#' + id)) + '</span>'
          + '<button type="button" class="gss-tiny" data-gss-mup="' + id + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
          + '<button type="button" class="gss-tiny" data-gss-mdown="' + id + '"'
          + (i === draft.manual_ids.length - 1 ? ' disabled' : '') + '>↓</button>'
          + '<button type="button" class="gss-tiny" data-gss-mdel="' + id + '">Remove</button>'
          + '</div>';
      });
    }

    html += '</div><div class="gss-row" style="margin-top:10px">'
      + '<input class="gss-in" id="gss-search" placeholder="Search the catalogue by name" style="flex:1 1 200px">'
      + '<button type="button" class="gss-btn" id="gss-searchgo">Search</button></div>';

    if (results !== null) {
      html += '<div class="gss-results">';

      if (!results.length) {
        html += '<div class="gss-empty">Nothing matched.</div>';
      } else {
        results.forEach(function(p){
          var already = draft.manual_ids.indexOf(p.id) !== -1;
          html += '<div class="gss-prow">'
            + '<span class="gss-th">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
            + '<span class="gss-pn">' + esc(p.name) + '</span>'
            + '<button type="button" class="gss-tiny" data-gss-madd="' + p.id + '"'
            + (already ? ' disabled' : '') + '>' + (already ? 'Added' : 'Add') + '</button>'
            + '</div>';
        });
      }

      html += '</div>';
    }

    return html + '</div>';
  }

  /* ═══════════════════════ Lane HTB — Product tabs ═══════════════════════
     The owner: "i need Tabs section on homepage like different tabs in one
     section, like Best Sellers, Trending K-Beauty, New Arrivals etc. ... add
     new tabs with title and products selection query via categories, brands
     etc and also manual section, and also the sorting inside that section." —
     "Options A and B are final. give us also the align on left or center." —
     "need also for arabic too with proper translation option available while
     creating".

     Every option set below comes from the payload's `tab_options`, which the
     server builds from App\Support\GridTabs — the same constants its clean()
     checks every save against, so the screen cannot offer what the server
     refuses. The Arabic box sits beside its English one; Translate fills it
     through the shop's existing per-field endpoint
     (POST /admin-api/translations/machine/field — its own capability, the
     provider configured under Translation → Language settings), only when
     pressed, and stores nothing: Save does. */
  function blankTab(){
    return {title: '', title_ar: '', source: 'bestsellers', brand_id: 0, category_id: 0, children: false,
      query: {brands: [], cats: [], stock: false}, manual_ids: [], sort: '', url: ''};
  }

  function tabsOpt(){ return saved.tab_options || {max: 6, title_max: 40, styles: {}, align: {}, sources: {}, sorts: {}}; }

  /* The value at a dotted path inside draft.ptabs ("list.2.title_ar"). */
  function tget(path){
    var o = draft.ptabs || draft.words;
    path.split('.').forEach(function(k){ o = (o == null) ? undefined : o[k]; });
    return o;
  }

  function tset(path, v){
    var keys = path.split('.');
    var o = draft.ptabs || draft.words;
    for (var i = 0; i < keys.length - 1; i++) o = o[keys[i]];
    o[keys[keys.length - 1]] = v;
  }

  /* The English an Arabic box translates. */
  function englishFor(path){
    if (path === 'heading_ar') return draft.values.heading || '';
    if (path === 'sub_ar') return draft.values.subheading || '';
    if (path === 'view_all_ar') return draft.values.view_all_label || '';
    if (path === 'eyebrow_ar') return (draft.ptabs || draft.words).eyebrow || '';
    return tget(path.replace(/_ar$/, '')) || '';
  }

  function selectHTML(path, opts, value){
    var body = '';
    Object.keys(opts).forEach(function(k){
      body += '<option value="' + esc(k) + '"' + (String(k) === String(value) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>';
    });
    return '<select class="gss-sel" data-gss-t="' + esc(path) + '">' + body + '</select>';
  }

  function textHTML(path, label, value, max, help){
    return '<div class="gss-fld"><label class="gss-lab">' + esc(label) + '</label>'
      + '<input class="gss-in" data-gss-t="' + esc(path) + '" maxlength="' + max + '" value="' + esc(value || '') + '">'
      + (help ? '<p class="gss-help">' + esc(help) + '</p>' : '') + '</div>';
  }

  /* An Arabic box with its Translate button. */
  function arabicHTML(path, label, value, max){
    return '<div class="gss-fld"><label class="gss-lab">' + esc(label) + '</label>' + arabicBox(path, value, max) + '</div>';
  }

  /* The Arabic box itself, its Translate button inline at the box's end and
     the note under it (Lane HTE: shared by the tab titles and the heading
     table, so every Arabic box behaves and looks the same). */
  function arabicBox(path, value, max){
    var on = !!saved.machine_translate;
    return '<div class="gss-arx">'
      + '<input class="gss-in" dir="rtl" lang="ar" data-gss-t="' + esc(path) + '" maxlength="' + max + '" value="' + esc(value || '') + '"'
      + ' placeholder="Empty: the English is shown">'
      + '<button type="button" class="gss-tiny gss-tr" data-gss-tr="' + esc(path) + '"'
      + (on ? '' : ' title="No translation service is connected — Translation → Language settings"') + '>'
      + (on ? 'Translate' : 'Translate · not set up') + '</button></div>'
      + '<p class="gss-help" data-gss-trnote="' + esc(path) + '"></p>';
  }

  function multiHTML(path, opts, picked){
    var body = '';
    Object.keys(opts).forEach(function(k){
      if (k === '') return;
      body += '<option value="' + esc(k) + '"' + (picked.indexOf(Number(k)) !== -1 ? ' selected' : '') + '>' + esc(opts[k]) + '</option>';
    });
    return '<select class="gss-sel" multiple size="6" data-gss-tm="' + esc(path) + '">' + body + '</select>';
  }

  function tabHTML(t, i, n){
    var o = tabsOpt();
    var p = 'list.' + i;
    var sorts = (o.sorts || {})[t.source] || {};
    var open = !!openTabs[i];
    var srcName = String((o.sources || {})[t.source] || t.source).split(' — ')[0];

    /* Header: the arrow opens and closes the card (no re-render, no request),
       the name follows what is typed in the English title. */
    var html = '<div class="gss-tab' + (open ? ' is-open' : '') + '" data-gss-tcard="' + i + '">'
      + '<div class="gss-thd">'
      + '<button type="button" class="gss-fold" data-gss-tfold="' + i + '" aria-expanded="' + (open ? 'true' : 'false') + '"'
      + ' aria-label="Open or close tab ' + (i + 1) + '"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3l5 5-5 5"/></svg></button>'
      + '<span class="gss-tnm"><b>Tab ' + (i + 1) + '<span data-gss-tname="' + i + '">' + (t.title ? ' · ' + esc(t.title) : '') + '</span></b>'
      + '<span class="gss-tmeta">' + esc(srcName) + (t.title_ar ? ' · Arabic title set' : ' · no Arabic title yet') + '</span></span>'
      + '<button type="button" class="gss-tiny" data-gss-tup="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move tab up">↑</button>'
      + '<button type="button" class="gss-tiny" data-gss-tdown="' + i + '"' + (i === n - 1 ? ' disabled' : '') + ' aria-label="Move tab down">↓</button>'
      + '<button type="button" class="gss-tiny gss-btn is-danger" data-gss-tdel="' + i + '"' + (n === 1 ? ' disabled' : '') + '>Remove</button></div>'
      + '<div class="gss-tb">';

    /* Row 1: the title, English beside Arabic. */
    html += '<div class="gss-two">'
      + textHTML(p + '.title', 'Title · English', t.title, o.title_max, 'A tab with no English title is not saved.')
      + arabicHTML(p + '.title_ar', 'Title · Arabic', t.title_ar, o.title_max)
      + '</div>';

    /* Row 2: where the products come from, what narrows it, and the order. */
    var cells = ['<div class="gss-fld"><label class="gss-lab">Products from</label>' + selectHTML(p + '.source', o.sources || {}, t.source) + '</div>'];

    if (t.source === 'brand') {
      cells.push('<div class="gss-fld"><label class="gss-lab">Brand</label>' + selectHTML(p + '.brand_id', saved.brands || {}, t.brand_id || '') + '</div>');
    }
    if (t.source === 'category') {
      cells.push('<div class="gss-fld"><label class="gss-lab">Category</label>' + selectHTML(p + '.category_id', saved.categories || {}, t.category_id || '')
        + '<label class="gss-sw"><input type="checkbox" data-gss-tb="' + p + '.children"' + (t.children ? ' checked' : '') + '><span>Include sub-categories</span></label></div>');
    }
    if (Object.keys(sorts).length) {
      cells.push('<div class="gss-fld"><label class="gss-lab">Sort inside this tab</label>' + selectHTML(p + '.sort', sorts, t.sort || Object.keys(sorts)[0]) + '</div>');
    } else {
      cells.push('<div class="gss-fld"><label class="gss-lab">Sort inside this tab</label><p class="gss-help">“' + esc(srcName) + '” is its own order.</p></div>');
    }
    html += '<div class="' + (cells.length === 3 ? 'gss-three' : 'gss-two') + '">' + cells.join('') + '</div>';

    if (t.source === 'query') {
      html += '<div class="gss-two">'
        + '<div class="gss-fld"><label class="gss-lab">Brands (any of)</label>' + multiHTML(p + '.query.brands', saved.brands || {}, t.query.brands || [])
        + '<p class="gss-help">Ctrl/⌘-click to pick several, up to 20.</p></div>'
        + '<div class="gss-fld"><label class="gss-lab">Categories (any of)</label>' + multiHTML(p + '.query.cats', saved.categories || {}, t.query.cats || [])
        + '<p class="gss-help">Both chosen: a product must be in one of the brands AND one of the categories.</p></div>'
        + '</div><div class="gss-row">'
        + '<label class="gss-sw"><input type="checkbox" data-gss-tb="' + p + '.children"' + (t.children ? ' checked' : '') + '><span>Include sub-categories</span></label>'
        + '<label class="gss-sw"><input type="checkbox" data-gss-tb="' + p + '.query.stock"' + (t.query.stock ? ' checked' : '') + '><span>In stock only</span></label></div>';
    }

    if (t.source === 'manual') html += tabPickerHTML(t, i);

    /* Row 3: the button under this tab. */
    html += textHTML(p + '.url', '“View all” link for this tab', t.url, 500, 'Empty: no button. /best-sellers or a full https:// address; the Arabic shop gets /ar automatically.');

    return html + '</div></div>';
  }

  function tabPickerHTML(t, i){
    var names = saved.tab_products || {};
    var res = tabResults[i] || null;
    var byId = {};
    Object.keys(names).forEach(function(k){ byId[k] = names[k]; });
    (res || []).forEach(function(p){ byId[p.id] = p; });

    var html = '<div style="margin-top:10px"><div class="gss-lab">This tab’s products (up to 24)</div><div class="gss-picked">';
    if (!t.manual_ids.length) html += '<div class="gss-empty">Nothing picked yet. Search below.</div>';
    t.manual_ids.forEach(function(id, j){
      var p = byId[id];
      html += '<div class="gss-prow"><span class="gss-th">' + (p && p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
        + '<span class="gss-pn">' + esc(p ? p.name : ('#' + id)) + '</span>'
        + '<button type="button" class="gss-tiny" data-gss-tpm="' + i + ':' + j + ':-1"' + (j === 0 ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" class="gss-tiny" data-gss-tpm="' + i + ':' + j + ':1"' + (j === t.manual_ids.length - 1 ? ' disabled' : '') + '>↓</button>'
        + '<button type="button" class="gss-tiny" data-gss-tpm="' + i + ':' + j + ':x">Remove</button></div>';
    });
    html += '</div><div class="gss-row" style="margin-top:9px">'
      + '<input class="gss-in" id="gss-tsearch-' + i + '" placeholder="Search the catalogue by name" style="flex:1 1 200px">'
      + '<button type="button" class="gss-btn" data-gss-tsearch="' + i + '">Search</button></div>';
    if (res !== null) {
      html += '<div class="gss-results">';
      if (!res.length) html += '<div class="gss-empty">Nothing matched.</div>';
      res.forEach(function(p){
        var already = t.manual_ids.indexOf(p.id) !== -1;
        html += '<div class="gss-prow"><span class="gss-th">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
          + '<span class="gss-pn">' + esc(p.name) + '</span>'
          + '<button type="button" class="gss-tiny" data-gss-tpa="' + i + ':' + p.id + '"' + (already || t.manual_ids.length >= 24 ? ' disabled' : '') + '>'
          + (already ? 'Added' : 'Add') + '</button></div>';
      });
      html += '</div>';
    }
    return html + '</div>';
  }

  function tabsView(){
    var o = tabsOpt();
    var t = draft.ptabs;
    var html = '<div class="gss-list">';

    t.list.forEach(function(tab, i){ html += tabHTML(tab, i, t.list.length); });

    var full = t.list.length >= o.max;
    html += '</div><div class="gss-row">'
      + '<button type="button" class="gss-btn" data-gss-tadd' + (full ? ' disabled' : '') + '>+ Add tab</button>'
      + '<span class="gss-help">' + (full
          ? o.max + ' of ' + o.max + ' tabs — the most one section can hold. Remove one to add another.'
          : t.list.length + ' of ' + o.max + ' tabs.')
      + ' Empty Arabic shows the English on the Arabic shop — never a blank tab.</span></div>';

    return html;
  }

  function bindTabs(){
    var on = document.querySelector('[data-gss-tabson]');
    if (on) on.onchange = function(){
      if (on.checked) {
        draft.ptabs = draft.ptabs || (saved.product_tabs ? JSON.parse(JSON.stringify(saved.product_tabs)) : null)
          || {eyebrow: '', eyebrow_ar: '', heading_ar: '', sub_ar: '', view_all_ar: '', align: 'center', list: [blankTab()]};
        /* Lane GAR: the words typed with tabs off come along. */
        WORD_KEYS.forEach(function(k){ draft.ptabs[k] = draft.words[k]; });
        openTabs = {0: true};
      } else {
        /* ...and go back with them: switching tabs off keeps the Arabic. */
        draft.words = wordsOf(draft.ptabs);
        draft.ptabs = null;
      }
      render(); refreshPreview();
    };

    /* Typing writes the draft and nothing else (no re-render under the caret);
       a select that changes what is on screen re-renders. */
    document.querySelectorAll('[data-gss-t]').forEach(function(el){
      var path = el.dataset.gssT;
      var apply = function(){
        if (path === '@style') { draft.tab_style = el.value; }
        else {
          var v = el.value;
          if (/\.(brand_id|category_id)$/.test(path)) v = Number(v) || 0;
          tset(path, v);
        }
        paintDraftBar();
        refreshPreview();
        /* Lane HTE: a choice card or segment lights up without a re-render, and
           a tab's header follows its English title as it is typed. */
        if (el.type === 'radio') {
          document.querySelectorAll('input[name="' + el.name + '"]').forEach(function(r){
            if (r.parentNode && r.parentNode.classList) r.parentNode.classList.toggle('is-on', r.checked);
          });
        }
        var named = /^list\.(\d+)\.title$/.exec(path);
        if (named) {
          var tn = document.querySelector('[data-gss-tname="' + named[1] + '"]');
          if (tn) tn.textContent = el.value ? ' · ' + el.value : '';
        }
        if (/\.source$/.test(path)) {
          var tab = tget(path.replace(/\.source$/, ''));
          var sorts = (tabsOpt().sorts || {})[tab.source] || {};
          tab.sort = Object.keys(sorts)[0] || '';
          render();
        }
      };
      el.oninput = apply;
      el.onchange = apply;
    });

    document.querySelectorAll('[data-gss-tb]').forEach(function(el){
      el.onchange = function(){ tset(el.dataset.gssTb, el.checked); paintDraftBar(); refreshPreview(); };
    });

    document.querySelectorAll('[data-gss-tm]').forEach(function(el){
      el.onchange = function(){
        var ids = Array.prototype.filter.call(el.options, function(op){ return op.selected; })
          .map(function(op){ return Number(op.value); }).slice(0, 20);
        tset(el.dataset.gssTm, ids); paintDraftBar(); refreshPreview();
      };
    });

    /* Open / close a tab card: a class on the card, nothing else. */
    document.querySelectorAll('[data-gss-tfold]').forEach(function(b){
      b.onclick = function(){
        var i = Number(b.dataset.gssTfold);
        var card = document.querySelector('[data-gss-tcard="' + i + '"]');
        openTabs[i] = !openTabs[i];
        if (card) card.classList.toggle('is-open', openTabs[i]);
        b.setAttribute('aria-expanded', openTabs[i] ? 'true' : 'false');
      };
    });

    var add = document.querySelector('[data-gss-tadd]');
    if (add) add.onclick = function(){
      if (draft.ptabs.list.length >= tabsOpt().max) return;
      draft.ptabs.list.push(blankTab()); openTabs[draft.ptabs.list.length - 1] = true;
      tabResults = {}; render(); refreshPreview();
    };

    document.querySelectorAll('[data-gss-tdel]').forEach(function(b){
      b.onclick = function(){
        if (draft.ptabs.list.length < 2) return;
        var gone = Number(b.dataset.gssTdel);
        draft.ptabs.list.splice(gone, 1);
        var kept = {};
        Object.keys(openTabs).forEach(function(k){ k = Number(k); if (k !== gone && openTabs[k]) kept[k > gone ? k - 1 : k] = true; });
        openTabs = kept;
        tabResults = {}; render(); refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-tup],[data-gss-tdown]').forEach(function(b){
      b.onclick = function(){
        var i = Number(b.dataset.gssTup || b.dataset.gssTdown);
        var j = b.dataset.gssTup ? i - 1 : i + 1;
        var l = draft.ptabs.list;
        if (j < 0 || j >= l.length) return;
        var x = l[i]; l[i] = l[j]; l[j] = x;
        var oi = !!openTabs[i]; openTabs[i] = !!openTabs[j]; openTabs[j] = oi;
        tabResults = {}; render(); refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-tsearch]').forEach(function(b){
      b.onclick = async function(){
        var i = Number(b.dataset.gssTsearch);
        var box = document.querySelector('#gss-tsearch-' + i);
        try {
          var body = await api('/grid-sections/products?q=' + encodeURIComponent(box ? box.value : ''));
          tabResults[i] = body.products || [];
          render();
        } catch (e) { say(explain(e, 'Could not search the catalogue.')); }
      };
    });

    document.querySelectorAll('[data-gss-tpa]').forEach(function(b){
      b.onclick = function(){
        var parts = b.dataset.gssTpa.split(':');
        var tab = draft.ptabs.list[Number(parts[0])];
        var id = Number(parts[1]);
        var hit = (tabResults[Number(parts[0])] || []).filter(function(p){ return p.id === id; })[0];
        if (hit) { saved.tab_products = saved.tab_products || {}; saved.tab_products[id] = hit; }
        if (tab.manual_ids.indexOf(id) === -1 && tab.manual_ids.length < 24) tab.manual_ids.push(id);
        render(); refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-tpm]').forEach(function(b){
      b.onclick = function(){
        var parts = b.dataset.gssTpm.split(':');
        var ids = draft.ptabs.list[Number(parts[0])].manual_ids;
        var j = Number(parts[1]);
        if (parts[2] === 'x') ids.splice(j, 1);
        else {
          var k = j + Number(parts[2]);
          if (k < 0 || k >= ids.length) return;
          var x = ids[j]; ids[j] = ids[k]; ids[k] = x;
        }
        render(); refreshPreview();
      };
    });

    /* Translate: ONLY on a press, one short string, filled into the box and
       not stored — Save stores it. The server's own words come back when no
       provider is connected (409), so the button says why. */
    document.querySelectorAll('[data-gss-tr]').forEach(function(b){
      b.onclick = async function(){
        var path = b.dataset.gssTr;
        var box = document.querySelector('[data-gss-t="' + path + '"]');
        var note = document.querySelector('[data-gss-trnote="' + path + '"]');
        var english = String(englishFor(path)).trim();
        var tell = function(m){ if (note) note.textContent = m; };
        if (!english) { tell('Write the English first.'); return; }
        var was = b.textContent;
        b.disabled = true; b.textContent = 'Translating…';
        try {
          var d = await api('/translations/machine/field', 'POST', {text: english, locale: 'ar'});
          if (!d || !d.translation) { tell('Nothing came back for that.'); return; }
          var v = String(d.translation).slice(0, (box && box.maxLength > 0) ? box.maxLength : 255);
          tset(path, v);
          if (box) box.value = v;
          tell('Machine translation — read it before you save.');
          paintDraftBar(); refreshPreview();
        } catch (e) {
          tell(e && e.body && (e.body.message || e.body.error) ? (e.body.message || e.body.error)
            : (e && e.status === 403 ? 'Your account cannot use the translation service.' : 'Could not translate that.'));
        } finally {
          b.disabled = false; b.textContent = was;
        }
      };
    });
  }

  function paintDraftBar(){
    var n = changed().length;
    var bar = document.querySelector('#gss-draftbar');
    var foot = document.querySelector('#gss-foot');
    var save = document.querySelector('#gss-save');
    var disc = document.querySelector('#gss-discard');

    if (bar) {
      bar.innerHTML = n === 0 ? '' : '<div class="gss-banner"><b>' + n + ' unsaved change'
        + (n === 1 ? '' : 's') + '.</b> Nothing has reached the shop yet. '
        + 'The picture below shows them; press Save to keep them.</div>';
    }

    if (foot) foot.classList.toggle('is-dirty', n > 0);
    if (save) {
      save.disabled = saving || n === 0;
      save.textContent = saving ? 'Saving…' : (n === 0 ? 'Saved' : 'Save ' + n + ' change' + (n === 1 ? '' : 's'));
    }
    if (disc) disc.disabled = saving || n === 0;
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    /* Only paint when this screen is the one on show. The console navigates
       before an async load finishes, and a late response must not redraw
       somebody else's page. */
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="gss-wrap">';

    if (banner) html += '<div class="gss-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="gss-card"><div class="gss-empty">' + (busy ? 'Reading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += listView() + editorView();
    }

    host.innerHTML = html + '</div>';

    bind();
    paintDraftBar();
  }

  /* -------------------------------------------------------------- actions */
  function bind(){
    document.querySelectorAll('[data-gss-edit]').forEach(function(b){
      b.onclick = function(){
        if (openId !== null && !mayLeave()) return;
        openEditor(Number(b.dataset.gssEdit));
      };
    });

    document.querySelectorAll('[data-gss-add]').forEach(function(b){
      b.onclick = async function(){
        if (openId !== null && !mayLeave()) return;
        try {
          var body = await api('/grid-sections', 'POST', {preset: b.dataset.gssAdd || null});
          say('Added as a draft. Publish it when you are happy with it.');
          await load();
          openEditor(body.section.id);
        } catch (e) { say(explain(e, 'Could not add a grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-dup]').forEach(function(b){
      b.onclick = async function(){
        if (openId !== null && !mayLeave()) return;
        try {
          await api('/grid-sections/' + b.dataset.gssDup + '/duplicate', 'POST', {});
          say('Copied, as a draft.');
          openId = null; saved = null; draft = null;
          load();
        } catch (e) { say(explain(e, 'Could not duplicate that grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-del]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this grid? It disappears from the homepage and from Appearance → Homepage. This cannot be undone.')) return;
        try {
          await api('/grid-sections/' + b.dataset.gssDel, 'DELETE');
          if (window.kbbDrafts) window.kbbDrafts.drop('gridsections', b.dataset.gssDel);
          say('Deleted.');
          if (String(openId) === String(b.dataset.gssDel)) { openId = null; saved = null; draft = null; }
          load();
        } catch (e) { say(explain(e, 'Could not delete that grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-up],[data-gss-down]').forEach(function(b){
      b.onclick = async function(){
        var id = Number(b.dataset.gssUp || b.dataset.gssDown);
        var order = (data.sections || []).map(function(s){ return s.id; });
        var i = order.indexOf(id);
        var j = b.dataset.gssUp ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= order.length) return;
        order[i] = order[j]; order[j] = id;
        try {
          await api('/grid-sections/reorder', 'POST', {order: order});
          load();
        } catch (e) { say(explain(e, 'Could not reorder.')); }
      };
    });

    bindTabs();

    document.querySelectorAll('[data-gss-lang]').forEach(function(b){
      b.onclick = function(){
        previewLang = b.dataset.gssLang === 'ar' ? 'ar' : 'en';
        document.querySelectorAll('[data-gss-lang]').forEach(function(x){ x.classList.toggle('is-on', x === b); });
        drawPreview();
      };
    });

    document.querySelectorAll('[data-gss-w]').forEach(function(b){
      b.onclick = function(){
        previewWidth = Number(b.dataset.gssW);
        document.querySelectorAll('[data-gss-w]').forEach(function(x){
          x.classList.toggle('is-on', x === b);
        });
        drawPreview();
      };
    });

    /* A CONTROL UPDATES THE DRAFT, THE BAR AND THE PREVIEW — AND NOTHING ELSE.
       render() here would rebuild the editor under the owner's caret on every
       keystroke, which is the defect the buffer exists to remove. The one
       exception is `source`, which decides whether the manual picker is on the
       screen at all, so it is structural rather than a value. */
    document.querySelectorAll('[data-gss-key]').forEach(function(el){
      var key = el.dataset.gssKey;

      var apply = function(){
        draft.values[key] = el.type === 'checkbox' ? el.checked : el.value;
        paintDraftBar();
        refreshPreview();
        if (key === 'source') render();
      };

      el.oninput = apply;
      el.onchange = apply;
    });

    var searchGo = document.querySelector('#gss-searchgo');
    if (searchGo) searchGo.onclick = async function(){
      var box = document.querySelector('#gss-search');
      try {
        var body = await api('/grid-sections/products?q=' + encodeURIComponent(box ? box.value : ''));
        results = body.products || [];
        render();
      } catch (e) { say(explain(e, 'Could not search the catalogue.')); }
    };

    document.querySelectorAll('[data-gss-madd]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.gssMadd);
        if (draft.manual_ids.indexOf(id) === -1) draft.manual_ids.push(id);
        render();
        refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-mdel]').forEach(function(b){
      b.onclick = function(){
        draft.manual_ids = draft.manual_ids.filter(function(x){ return x !== Number(b.dataset.gssMdel); });
        render();
        refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-mup],[data-gss-mdown]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.gssMup || b.dataset.gssMdown);
        var i = draft.manual_ids.indexOf(id);
        var j = b.dataset.gssMup ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= draft.manual_ids.length) return;
        draft.manual_ids[i] = draft.manual_ids[j];
        draft.manual_ids[j] = id;
        render();
        refreshPreview();
      };
    });

    var save = document.querySelector('#gss-save');
    if (save) save.onclick = async function(){
      if (!dirty() || saving) return;
      saving = true;
      paintDraftBar();
      state('Saving…');

      try {
        var payload = {values: draft.values, manual_ids: draft.manual_ids};
        /* Only when they changed: an ordinary grid's save never names the
           tabs columns, so it cannot be refused on a server whose migration
           has not run yet. */
        if (tabsChanged()) {
          payload.product_tabs = outTabs();
          /* Lane GAR: an ordinary grid's words never move its tab style. */
          if (draft.ptabs) payload.tab_style = draft.tab_style;
        }
        var body = await api('/grid-sections/' + encodeURIComponent(openId), 'PUT', payload);
        saved = Object.assign({}, saved, body);
        startDraft();
        if (window.kbbDrafts) window.kbbDrafts.saved('gridsections');
        saving = false;
        await load();
        state('Saved. The shop is showing it now.');
        say('Saved.');
        refreshPreview();
      } catch (e) {
        saving = false;
        paintDraftBar();
        state(explain(e, 'Could not save that grid.'), true);
      }
    };

    var discard = document.querySelector('#gss-discard');
    if (discard) discard.onclick = function(){
      if (!dirty()) return;
      if (!window.confirm('Throw away your ' + changed().length + ' unsaved change(s) and go back to what is saved?')) return;
      startDraft();
      render();
      refreshPreview();
    };

    var close = document.querySelector('#gss-close');
    if (close) close.onclick = function(){
      if (!mayLeave()) return;
      openId = null; saved = null; draft = null; results = null;
      render();
    };
  }

  function state(text, bad){
    var el = document.querySelector('#gss-state');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('is-bad', !!bad);
  }

  /* The fourth door out, closing the tab or refreshing, asks nothing either:
     the draft is already in Unfinished, written as it was typed. (Lane PM) */

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
