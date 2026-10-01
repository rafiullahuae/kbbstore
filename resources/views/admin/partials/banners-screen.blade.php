{{--
    Appearance → Banners → Cards banner. (Lane BN — Phase 22)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry,
    window.kbbPickMedia and toast(). Its own file rather than more lines inside
    a 22,000-line Blade: several lanes edit that file at once, and a screen that
    lives on its own can be reviewed, reverted and merged on its own. The cost
    is that it cannot reach app.blade.php's module-scoped constants — NAV,
    TITLES and ADMIN_BASE are const, not window properties — so it appends its
    own sidebar entry to the rendered nav and wraps window.go instead. Both are
    surfaces the console already exposes for exactly this, and the shape is
    deliberately the same as admin/partials/cache-screen.blade.php.

    ── WHAT THE OWNER ASKED FOR, AND WHERE EACH PART OF IT IS ────────────────

      "we can turn on off card banners"                   → card 1, the switch
      "choose which banner will show on homepage"         → card 1, the select
      "create multiple cards" inside a set                → card 3, the cards
      "how many cards, scroll speed, animation etc"       → card 3, the controls
      "same sizes of the cards"                           → card 3, Card shape
      "full control to turn on off bottom text etc"       → card 3, two switches
      a live preview, because every other Appearance
        screen in this console has one and he uses
        them daily                                        → card 3, the frame

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ──────────────────

    The row's responsiveness is three media queries over `--kbbn-per` and
    `--kbbn-peek`. A media query asks the VIEWPORT how wide it is, not the box
    the preview is drawn in — so a preview injected straight into this page
    would resolve the desktop's four-across inside a 700px panel and show the
    owner a row the shop never draws. Inside an iframe the media queries resolve
    against the frame's own width, so the Phone / Tablet / Desktop buttons show
    what those widths really produce. It is the same problem the Instagram
    screen solved with @container rules, answered the other way because this
    section is sized by media queries on purpose.

    The frame's contents come from GET /admin-api/banners/sets/{id}/preview,
    which renders THE SAME PARTIAL the homepage renders. A second copy of the
    markup in this file would disagree with the shop the first time either was
    touched — the fault HomepageLayouts::summaries() shipped.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED bns- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name — [data-open], [data-tg], [data-pp] — and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.bns-wrap{display:grid;gap:16px;min-width:0}
.bns-wrap > *{min-width:0}
.bns-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.bns-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.bns-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.bns-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55}
.bns-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0}
.bns-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));min-width:0}
.bns-fld{display:grid;gap:4px;min-width:0}
.bns-lab{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}
.bns-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.bns-in,.bns-sel{width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
     padding:8px 10px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
     background:var(--surface,#fff);color:inherit}
.bns-in:focus,.bns-sel:focus{outline:2px solid var(--accent,#E8919F);outline-offset:1px}
.bns-rng{width:100%;min-width:0}
.bns-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.bns-btn:hover{border-color:var(--accent,#E8919F)}
.bns-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.bns-btn.is-danger{color:#B91C1C;border-color:#FCA5A5}
.bns-btn[disabled]{opacity:.5;cursor:default}
.bns-sw{display:inline-flex;align-items:center;gap:9px;font-size:13px;cursor:pointer;min-width:0}
.bns-sw input{width:18px;height:18px;flex:0 0 auto}
.bns-sets{display:grid;gap:9px;min-width:0}
.bns-set{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;
         border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px}
.bns-set.is-open{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.06)}
.bns-set .bns-nm{font-weight:650;font-size:13px;min-width:0;flex:1 1 auto;overflow-wrap:anywhere}
.bns-pill{font-size:10.5px;font-weight:700;border-radius:999px;padding:2px 8px;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.bns-pill.is-on{background:#DCFCE7;border-color:#86EFAC;color:#166534}
.bns-pill.is-live{background:#E0E7FF;border-color:#A5B4FC;color:#3730A3}
.bns-cards{display:grid;gap:12px;min-width:0;margin-top:12px}
.bns-cd{border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:12px;min-width:0;
        display:grid;gap:10px;grid-template-columns:96px minmax(0,1fr)}
.bns-th{width:96px;height:120px;border-radius:9px;overflow:hidden;background:var(--code-bg,rgba(0,0,0,.05));
        display:grid;place-items:center;font-size:10.5px;color:var(--ink-soft,#6b7280);text-align:center}
.bns-th img{width:100%;height:100%;object-fit:cover;display:block}
/* ── TWO THUMBNAILS ON A SLIDER'S ROW ── (Lane SEC)
   The row is a grid and a third child in a two-column template wraps under the
   first, which reads as a second card rather than as a second picture of the
   same one. The modifier is on the row and only a slider gets it, so a cards
   banner's row keeps the exact template it had.
   `bns-th-m` IS DRAWN AT 5:6, the shape the phone frame is, so the empty slot
   says what belongs in it before anything has been uploaded. */
.bns-cd.is-two{grid-template-columns:96px 96px minmax(0,1fr)}
.bns-th-m{height:115px;outline:1px dashed var(--border,#e6e6e6);outline-offset:-1px}
.bns-cdb{display:grid;gap:8px;min-width:0}
.bns-frame{width:100%;border:0;display:block;background:#fff}
.bns-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
           background:#fff;margin-top:10px;display:flex;justify-content:center;min-width:0}
.bns-stage > div{min-width:0;max-width:100%;overflow:hidden}
.bns-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.bns-warn{font-size:11.5px;color:#B45309}

/* ── Lane BP: the buffered editor's own furniture ───────────────────────────
   Every class here is still prefixed bns- and appears nowhere else in this
   console, for the reason the docblock above gives: app.blade.php binds a
   dozen delegated listeners to `document` on bare attribute names. */

/* A named band, so the nine controls that were one flat grid are five short
   groups. "You didn't gave controls for speed" was a findability failure, not
   a missing control, and this is half of the fix. */
.bns-sec{font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280);margin:18px 0 9px;padding-bottom:5px;
         border-bottom:1px solid var(--border,#e6e6e6)}
.bns-ends{display:flex;justify-content:space-between;font-size:10.5px;
          color:var(--ink-soft,#6b7280);margin-top:-2px}
.bns-dim{color:var(--ink-soft,#6b7280);opacity:.75}
.bns-narrow{width:auto;min-width:0;flex:0 0 auto}

.bns-crow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0}
.bns-col{width:44px;height:30px;padding:0;border:1px solid var(--border,#e6e6e6);
         border-radius:8px;background:var(--surface,#fff);cursor:pointer;flex:0 0 auto}
.bns-col[disabled]{opacity:.4;cursor:default}
.bns-bgth{width:64px;height:40px;border-radius:8px;overflow:hidden;flex:0 0 auto;
          background:var(--code-bg,rgba(0,0,0,.05));display:grid;place-items:center;
          font-size:10px;color:var(--ink-soft,#6b7280)}
.bns-bgth img{width:100%;height:100%;object-fit:cover;display:block}

/* The unsaved marker. Deliberately NOT a warning colour: unsaved work is not an
   error, and colouring it like one teaches the owner to dismiss it. The same
   call admin/partials/ugc-library-screen.blade.php's draft bar makes. */
.bns-draft{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;min-width:0;
           margin:11px 0;padding:10px 12px;border-radius:10px;
           border:1px solid #cfd9ee;background:var(--surface-2,#f2f4fb);
           font-size:12.5px;line-height:1.55}
.bns-drafttext{flex:1 1 240px;min-width:0}
.bns-draftacts{display:flex;flex-wrap:wrap;gap:7px}

/* THE SAVE FOOTER IS STICKY. The editor is longer than a phone screen and
   longer than most desktop ones, and a Save button that has to be scrolled to
   is a Save button that gets forgotten — which, on a screen that no longer
   saves by itself, loses work. `bottom:0` inside the card so it never covers
   the sidebar, and a border-top so it reads as a bar rather than as a control
   floating over the content. */
.bns-foot{position:sticky;bottom:0;z-index:2;display:flex;gap:10px;align-items:center;
          flex-wrap:wrap;min-width:0;margin:16px -16px -16px;padding:11px 16px;
          border-top:1px solid var(--border,#e6e6e6);
          background:var(--surface,#fff);border-radius:0 0 var(--r,12px) var(--r,12px)}
.bns-foot.is-dirty{background:#f2f4fb;border-top-color:#cfd9ee}
.bns-savest{flex:1 1 200px;min-width:0;font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280)}
.bns-savest.is-bad{color:#9b1c1c;font-weight:600}
@media (max-width:640px){
  .bns-card{padding:13px}
  .bns-cd{grid-template-columns:64px minmax(0,1fr)}
  .bns-th{width:64px;height:80px}
  .bns-cd.is-two{grid-template-columns:64px 64px minmax(0,1fr)}
  .bns-th-m{height:77px}
  .bns-foot{margin:13px -13px -13px;padding:10px 13px}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'banners';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  /*
   * ── THE SCREEN BUFFERS, AND THAT IS THE WHOLE SHAPE OF IT — Lane BP ───────
   *
   * The owner, after using the first version: "i don't want auto save, there
   * should b save button, bcz i need multiple edits before save. make it
   * properly."
   *
   * He was describing something real. Every set control and every card field
   * carried an `onchange` that fired a PUT immediately — ONE REQUEST PER FIELD
   * — and then called load() or openEditor(), which re-rendered the editor
   * underneath him. Three edits were three writes, and the redraw after each
   * one is what made the screen feel like it was fighting him.
   *
   * So there are now two objects and the difference between them is the
   * feature:
   *
   *   openSet   WHAT THE SERVER LAST HANDED OVER. Never written to by a
   *             control. It is what Discard goes back to and what "unsaved"
   *             is measured against.
   *   draft     WHAT THE OWNER HAS TYPED. Every control writes here and
   *             nowhere else, and it reaches the database only when Save is
   *             pressed — one request, one transaction, the set and every card.
   *
   * ── AND THE EDITOR IS NOT RE-RENDERED WHILE HE IS IN IT ──────────────────
   *
   * A buffered editor that redraws on every keystroke is the same defect with a
   * Save button bolted on: the caret jumps, a half-typed word is replaced by
   * itself, and a <select> closes as it is opened. So a control's handler
   * updates THREE things by hand — the draft, its own readout, and the unsaved
   * bar — and touches no other element. render() runs on open, on save, on
   * discard, and on the structural actions; never on a keystroke.
   *
   * ── WHAT IS STILL IMMEDIATE, AND WHY THAT IS NOT A CONTRADICTION ─────────
   *
   * New set, Duplicate, Delete set, Add a card and Delete card act at once, as
   * they always did. They are not edits to a value — they create and destroy
   * the rows the editor is editing, and a buffered delete is a row that is
   * gone on screen and present in the database. The screen says which is which
   * in as many words rather than leaving him to find out. Each one asks first
   * when there is unsaved typing, because performing one reloads the editor.
   */
  var data = null;        // GET /admin-api/banners
  var openId = null;      // the set whose editor is showing
  var openSet = null;     // the server's answer: {set, cards}
  var draft = null;       // what has been typed but not saved: {set, cards:{id:…}}
  var previewWidth = 1280;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;
  var pvSeq = 0;
  var pvTimer = null;

  /* The set columns and the card columns the editor owns. THE SAVE AND THE
     PREVIEW BOTH SEND EXACTLY THESE, so a control added to the screen without
     its key added here is a control that saves nothing — the defect
     ModuleFrameworkGuardTest exists to catch on the module framework's side of
     this screen, written out by hand here because these are table columns and
     not module settings. tests/Feature/CardsBannerControlsTest.php pins the two
     lists against the controller's own validated rules. */
  var SET_KEYS = ['name', 'status', 'position', 'ratio', 'animation', 'shadow',
    'per_view', 'peek', 'speed_ms', 'gap', 'card_radius',
    'autoplay', 'pause_on_hover', 'show_text', 'show_button', 'show_dots', 'show_arrows',
    'bg_mode', 'bg_color', 'bg_image', 'btn_bg', 'btn_text', 'btn_hover', 'title_pos',
    /* Lane BN2. `kind` is the banner TYPE and the other three belong to the
       slider. They are in this one list with everything else because the Save
       and the preview both send exactly these keys — a control added to the
       screen without its key here is a control that saves nothing. */
    'kind', 'slider_style', 'slider_ratio', 'slider_ratio_m'];

  /* `image_m` IS IN THIS LIST AND IT HAS TO BE. (Lane SEC) The buffer sends
     exactly these keys on Save, so a column left out of it is a control the
     owner can operate, see redraw in the preview, and lose the moment he
     presses the button -- which is worse than not having the control. */
  var CARD_KEYS = ['image', 'image_m', 'alt', 'heading', 'body', 'button_label', 'button_url', 'position', 'status'];

  /* The colour a control falls back to when the set says "use the shop's own".
     These are the SHOP'S values out of resources/css/kbb/kbb.css, not this
     console's, because the swatch is telling the owner what the storefront
     draws. '' is what is stored; this is only ever shown. */
  var THEME = {btn_bg: '#e0567b', btn_text: '#ffffff', btn_hover: '#c13e63', bg_color: '#fff8f5'};

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN read from the XSRF-TOKEN COOKIE, which is what
     app.blade.php's own api() has always sent. A <meta name="csrf-token"> tag
     is what the shoppable-video screens reached for and this console does not
     render one. */
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
     screen, which here would read as "you have no banners" — the exact wrong
     conclusion, and one an owner would answer by building them all again. */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Banners endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Banners',
      icon:   '<rect x="3" y="4" width="7" height="16" rx="2"/><rect x="14" y="4" width="7" height="16" rx="2"/>',
      group:  'Appearance',
      after:  ['hpcontent', 'homepage']
    });
  }

  /* -------------------------------------------------------------- the draft */
  function startDraft(){
    draft = null;
    if (!openSet) return;

    draft = {set: {}, cards: {}};
    SET_KEYS.forEach(function(k){ draft.set[k] = openSet.set[k]; });
    (openSet.cards || []).forEach(function(c){
      var row = {};
      CARD_KEYS.forEach(function(k){ row[k] = c[k]; });
      /* The picture is buffered like everything else, so the thumbnail has to
         be able to show a file that is not in the database yet. The picker
         hands over a URL; the server turns it into a stored path on Save. */
      row.image_url = c.image_url;
      row.image_m_url = c.image_m_url;   /* the phone picture's thumbnail (SEC) */
      draft.cards[c.id] = row;
    });
  }

  /* Field-by-field, so the bar can say HOW MANY rather than merely "yes". A
     count is what tells the owner whether the thing he just changed registered.
     Loose equality on purpose: a checkbox gives true/false and the server gives
     1/0 for the same column, and a bar that called that a change would say
     "unsaved" the moment a set was opened. */
  function changed(){
    var out = [];
    if (!draft || !openSet) return out;

    SET_KEYS.forEach(function(k){
      if (String(draft.set[k] == null ? '' : draft.set[k]) !== String(openSet.set[k] == null ? '' : openSet.set[k])) out.push(k);
    });

    (openSet.cards || []).forEach(function(c){
      var d = draft.cards[c.id] || {};
      CARD_KEYS.forEach(function(k){
        if (String(d[k] == null ? '' : d[k]) !== String(c[k] == null ? '' : c[k])) out.push('card ' + c.id + ' ' + k);
      });
    });

    return out;
  }

  function dirty(){ return changed().length > 0; }

  /* What Save and the buffered preview both send. ONE BUILDER, so the picture
     the owner is looking at is composed from the same bytes the save will
     write — a second copy of this shape is the copy that disagrees. */
  function payload(){
    var p = {set: {}, cards: []};
    SET_KEYS.forEach(function(k){ p.set[k] = draft.set[k]; });
    (openSet.cards || []).forEach(function(c){
      var d = draft.cards[c.id] || {};
      var row = {id: c.id};
      CARD_KEYS.forEach(function(k){ row[k] = d[k] == null ? '' : d[k]; });
      p.cards.push(row);
    });
    return p;
  }

  /* LEAVING KEEPS THE DRAFT, AND ASKS NOTHING. (Lane PM)
     Three doors out of this editor come through here: another screen in the
     sidebar, another set in the list, and a structural action that reloads
     the editor. Each used to stop the owner with window.confirm("You have N
     unsaved change(s) to this set … Leave them?") — the "weired popup" he
     asked to be rid of. Now the typing is handed to Unfinished in the top bar
     (partials/unfinished-drafts.blade.php) and the door simply opens. Coming
     back to this set — from the list, from Unfinished → Open, or because Add a
     card reloaded it — puts the typing back, with a bar that says so. */
  function mayLeave(){
    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('banners');
    return true;
  }

  /* The draft, flattened for the Unfinished list: set.<column>,
     card.<id>.<column>, and the two picture thumbnails the picker hands back,
     so a restored picture shows the file that was picked and not the saved
     one. */
  var CARD_DRAFT_KEYS = CARD_KEYS.concat(['image_url', 'image_m_url']);

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: 'banners', screen: SCREEN,
    label: function(){
      var name = openSet && openSet.set ? String(openSet.set.name || '') : '';
      return 'Appearance → Banners · ' + (name || ('set ' + openId));
    },
    entity: function(){ return openSet ? openId : null; },
    values: function(){
      if (!draft || !openSet) return null;
      var out = {};
      SET_KEYS.forEach(function(k){ out['set.' + k] = draft.set[k]; });
      out['set.bgUrl'] = draft.bgUrl;
      Object.keys(draft.cards).forEach(function(id){
        CARD_DRAFT_KEYS.forEach(function(k){ out['card.' + id + '.' + k] = draft.cards[id][k]; });
      });
      return out;
    },
    set: function(k, v){
      var m = /^card\.(\d+)\.(\w+)$/.exec(k);
      if (m) { if (draft.cards[m[1]] && CARD_DRAFT_KEYS.indexOf(m[2]) !== -1) draft.cards[m[1]][m[2]] = v; return; }
      if (k === 'set.bgUrl') { draft.bgUrl = v; return; }
      if (k.indexOf('set.') === 0 && SET_KEYS.indexOf(k.slice(4)) !== -1) draft.set[k.slice(4)] = v;
    },
    count: function(){ return changed().length; },
    render: function(){ render(); refreshPreview(); },
    save: function(){ var b = document.querySelector('#bns-saveset'); if (b) b.click(); },
    open: function(id){ window.go(SCREEN); openEditor(Number(id)); }
  });

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      /* Leaving Banners for another screen. Asked BEFORE anything is repainted,
         so Cancel really does leave the owner where he was rather than on a
         half-torn-down screen. */
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
    if (title) title.textContent = 'Banners';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    /* render() BEFORE load(), synchronously — the condition app.blade.php's
       LATE_RENDERED set carries. The replay's marker inside #content has to be
       destroyed by the time the async load's task runs, or the screen is drawn
       twice. */
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
      var body = await api('/banners');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read your banner sets.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openEditor(id){
    openId = id;
    openSet = null;
    draft = null;
    render();

    try {
      openSet = await api('/banners/sets/' + encodeURIComponent(id));
      startDraft();
    } catch (e) {
      say(explain(e, 'Could not open that set.'));
      openId = null;
    }

    render();
    refreshPreview();
    /* Unfinished changes to this set, if any, come back now. */
    if (openSet && window.kbbDrafts) window.kbbDrafts.ready('banners');
  }

  /* The preview, redrawn from the BUFFER and debounced.
     Debounced because a slider fires `input` on every pixel of the drag and a
     request per pixel is a request per pixel; 260ms is below the point a
     redraw reads as a response to something else. */
  function schedulePreview(){
    if (pvTimer) clearTimeout(pvTimer);
    pvTimer = setTimeout(refreshPreview, 260);
  }

  async function refreshPreview(){
    if (openId === null || !draft) return;

    var stage = document.querySelector('#bns-stage');
    if (!stage) return;

    var mine = ++pvSeq;

    try {
      /* POST, and the draft goes with it. The endpoint lays this over the
         stored row IN MEMORY with the same code the save uses and renders the
         shop's own partial from it; it writes nothing. */
      var body = await api('/banners/sets/' + encodeURIComponent(openId) + '/preview', 'POST', payload());
      if (mine !== pvSeq) return;
      paintPreview(stage, body);
    } catch (e) {
      if (mine !== pvSeq) return;
      stage.innerHTML = '<div class="bns-empty">' + esc(explain(e, 'Could not draw the preview.')) + '</div>';
    }
  }

  /* The frame. srcdoc rather than a URL: there is nothing to fetch — the server
     already handed us the row's markup, and a second document on a real address
     would need a route of its own, a capability of its own and a reason. The
     height is fixed per width rather than measured, because measuring it would
     mean reaching into the frame for a box, which is the one thing this whole
     feature is built not to do. */
  function paintPreview(stage, body){
    if (!body || body.empty || !body.html) {
      stage.innerHTML = '<div class="bns-empty">Nothing to draw yet — add a card with a picture, and publish the set to show it on the shop.</div>';
      return;
    }

    /*
     * ── THE PALETTE IS THE SHOP'S, AND IT WAS NOT ──────────────────────────
     *
     * This frame declared `--pink:#E8919F`. The storefront's `--pink` is
     * `#E0567B` (resources/css/kbb/kbb.css:2), and the card partial draws its
     * button `background:var(--pink,#E8919F)` — so the preview was painting
     * every button in the FALLBACK colour and the screen underneath it said
     * "what is here is what the homepage draws". `--muted` and `--line` were
     * wrong in the same way, which is the dots. Copied off kbb.css's :root
     * here; `--line2` is not defined there at all, so the partial's own
     * fallback is what the shop uses and it is repeated verbatim.
     */
    var doc = '<!doctype html><html><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>html,body{margin:0;padding:0;background:#fff;'
      + 'font-family:Outfit,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;'
      + '--site-gutter:18px;--site-max:1280px;--pink:#E0567B;--ink:#2A2228;--muted:#8C828A;'
      + '--line:rgba(42,34,40,.10);--line2:#F4EEF1}'
      + '.wrap{max-width:var(--site-max);margin-inline:auto;padding-inline:var(--site-gutter)}'
      + 'body{padding:14px 0}</style></head><body><div class="wrap">'
      + body.html + '</div></body></html>';

    /*
     * ── THE FRAME HAS TO BE TALL ENOUGH FOR WHAT IS IN IT — Lane BN2 ───────
     *
     * The three fixed heights are the CARDS row's, and they are right for it:
     * its own height comes from a card ratio the phone and the desktop both
     * narrow to about the same band. A slider is a single picture at the shape
     * the owner chose, so a 21:9 banner at 1280 is 560px tall and a 470px frame
     * cut the bars — the controls he is choosing between — off the bottom.
     *
     * Computed from the RATIO TOKEN, which is two integers out of
     * BannerSet::SLIDER_RATIOS and therefore arithmetic rather than a
     * measurement; the phone's token below 768px, because that is the
     * breakpoint the partial itself switches at. The slack is the body padding,
     * the wrap's gutters and the bar strip.
     */
    var height = previewWidth <= 430 ? 470 : (previewWidth <= 800 ? 430 : 470);
    var box = 'height:' + height + 'px';

    if (draft && draft.set && draft.set.kind === 'slider') {
      var token = String((previewWidth < 768 ? draft.set.slider_ratio_m : draft.set.slider_ratio) || '');
      var parts = token.split('/');
      var w = Number(parts[0]);
      var h = Number(parts[1]);
      var ratio = (w > 0 && h > 0) ? w / h : 16 / 9;

      /*
       * AN ASPECT RATIO ON THE FRAME, NOT A HEIGHT, and the difference shows on
       * a narrow console. The wrapper is `width:<previewWidth>px;max-width:100%`
       * — so on a 1280 console the 1280 preview is really about 900 wide, and a
       * height computed for 1280 leaves a third of the frame empty. Given the
       * ratio instead, the frame's height follows whatever width it ends up
       * with, which is also what the banner inside it does. No measurement: it
       * is two integers out of BannerSet::SLIDER_RATIOS and a constant.
       */
      box = 'aspect-ratio:' + (previewWidth / (Math.round((previewWidth - 36) / ratio) + 92)).toFixed(4) + ';height:auto';
    }

    /*
     * ── `allow-scripts`, AND `allow-same-origin` IS GONE ──────────────────
     *
     * The slider is the first storefront section with a script in it, and a
     * preview that cannot run it would draw the no-script fallback — a plain
     * rail with no arrows and no bars, which is precisely what the owner is
     * being asked to choose between. So the frame has to run scripts.
     *
     * DROPPING `allow-same-origin` IS WHAT MAKES THAT SAFE, and it is a
     * TIGHTENING rather than a trade. The two together are the documented
     * escape hatch — a frame with both can reach the parent document and its
     * cookies, so the sandbox buys nothing. With `allow-scripts` alone the
     * frame is a unique opaque origin: the script runs, and it can touch
     * nothing outside its own document. The section needs no more than that —
     * it reads its own elements and its own data attributes — and the cards
     * banner, which has no script at all, cannot tell the difference.
     */
    stage.innerHTML = '<div style="width:' + previewWidth + 'px;max-width:100%">'
      + '<iframe class="bns-frame" style="' + box + '" '
      + 'sandbox="allow-scripts" title="Banner preview" srcdoc="' + esc(doc) + '"></iframe></div>';
  }

  /* ---------------------------------------------------------------- views */

  function moduleView(){
    var setField = null;
    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ if (f.key === 'set') setField = f; });
    });

    /* data.setOptions, NOT setField.options. ModuleSchema::fields() emits the
       module's OWN schema options and never the overrides — see the controller,
       which carries the whole argument and the defect it fixes. */
    var options = data.setOptions || {'': 'None'};
    var current = setField ? String(setField.value == null ? '' : setField.value) : '';

    var html = '<div class="bns-card">'
      + '<div class="bns-title">The cards banner on the homepage</div>'
      + '<div class="bns-sub">Off by default, and nothing shows until you switch it on <em>and</em> pick a published set below. '
        + 'Its position on the page, and whether it shows on phones or on desktop, are on Appearance → Homepage under “Cards banner”.</div>'
      + '<label class="bns-sw"><input type="checkbox" id="bns-module"' + (data.moduleOn ? ' checked' : '') + '>'
      + '<span>Show the cards banner on the homepage</span></label>'
      + '<div class="bns-fld" style="margin-top:13px"><span class="bns-lab">Which set shows</span>'
      + '<select class="bns-sel" id="bns-chosen">';

    Object.keys(options).forEach(function(k){
      html += '<option value="' + esc(k) + '"' + (k === current ? ' selected' : '') + '>' + esc(options[k]) + '</option>';
    });

    html += '</select><span class="bns-help">Only a published set draws anything. A draft is listed here and marked, so you can see which one you picked.</span></div>'
      + '<div class="bns-row" style="margin-top:12px"><button class="bns-btn is-primary" id="bns-save">Save</button></div>'
      + '</div>';

    return html;
  }

  function setsView(){
    var html = '<div class="bns-card">'
      + '<div class="bns-title">Your banner sets</div>'
      + '<div class="bns-sub">Two types live in this one list. A <b>cards banner</b> is a row of picture cards that scrolls itself; a <b>picture slider</b> is one picture at a time with arrows and thin bars along the bottom. Each set carries its own speed, background and shape. Build as many as you like and pick which one the homepage shows, above.</div>'
      + '<div class="bns-row" style="margin-bottom:11px">'
      /* NO `id` ON EITHER, and that is a rule rather than a tidy-up:
         AdminConsoleControlsAreLiveTest walks this console for an `id` that no
         handler binds, and both of these are bound by `[data-bns-kind]` — one
         loop, so neither can end up wired and the other not. An id here would
         be a control the guard has to take on trust. */
      + '<button class="bns-btn is-primary" data-bns-kind="cards">New cards banner</button>'
      + '<button class="bns-btn is-primary" data-bns-kind="slider">New picture slider</button>'
      + '</div>';

    if (!(data.sets || []).length) {
      html += '<div class="bns-empty">No sets yet. Press one of the two buttons above to build your first one.</div></div>';
      return html;
    }

    html += '<div class="bns-sets">';

    var chosen = '';
    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ if (f.key === 'set') chosen = String(f.value == null ? '' : f.value); });
    });

    data.sets.forEach(function(s){
      html += '<div class="bns-set' + (openId === s.id ? ' is-open' : '') + '">'
        + '<span class="bns-nm">' + esc(s.name) + '</span>'
        + '<span class="bns-pill' + (s.status === 'publish' ? ' is-on' : '') + '">' + esc(s.status === 'publish' ? 'Published' : 'Draft') + '</span>'
        + (String(s.id) === chosen ? '<span class="bns-pill is-live">On the homepage</span>' : '')
        /* THE TYPE, ON EVERY ROW. Two kinds in one list is unreadable without
           it — the sets are named by the owner and nothing else on the row says
           which editor opening it will give him. */
        + '<span class="bns-pill">' + esc(s.kind === 'slider' ? 'Picture slider' : 'Cards banner') + '</span>'
        + '<span class="bns-pill">' + esc(s.cards_count)
          + (s.kind === 'slider' ? ' picture' : ' card') + (s.cards_count === 1 ? '' : 's') + '</span>'
        + '<button class="bns-btn" data-bns-open="' + esc(s.id) + '">' + (openId === s.id ? 'Close' : 'Edit') + '</button>'
        + '<button class="bns-btn" data-bns-dup="' + esc(s.id) + '">Duplicate</button>'
        + '<button class="bns-btn is-danger" data-bns-del="' + esc(s.id) + '">Delete</button>'
        + '</div>';
    });

    return html + '</div></div>';
  }

  /* ------------------------------------------------------------- controls */

  function fld(label, help, inner){
    return '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>' + inner
      + (help ? '<span class="bns-help">' + help + '</span>' : '') + '</div>';
  }

  function num(key, label, help, value, min, max, step){
    return '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>'
      + '<input class="bns-rng" type="range" data-bns-set="' + esc(key) + '" value="' + esc(value) + '" '
      + 'min="' + esc(min) + '" max="' + esc(max) + '" step="' + esc(step || 1) + '">'
      + '<span class="bns-help"><b data-bns-out="' + esc(key) + '">' + esc(value) + '</b> — ' + esc(help) + '</span></div>';
  }

  function pick(key, label, help, value, options){
    var html = '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>'
      + '<select class="bns-sel" data-bns-set="' + esc(key) + '">';
    Object.keys(options).forEach(function(k){
      html += '<option value="' + esc(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + esc(options[k]) + '</option>';
    });
    return html + '</select><span class="bns-help">' + esc(help) + '</span></div>';
  }

  function sw(key, label, value){
    return '<label class="bns-sw"><input type="checkbox" data-bns-set="' + esc(key) + '"'
      + (value ? ' checked' : '') + '><span>' + esc(label) + '</span></label>';
  }

  /*
   * A COLOUR, WITH "THE SHOP'S OWN" AS A REAL CHOICE AND THE DEFAULT.
   *
   * The stored value is '' or a hex, and '' is what every one of these columns
   * ships at — the template then writes no custom property at all and the
   * button draws the accent it always drew. A bare <input type="color"> cannot
   * express that: it always has a colour, so a screen built from one alone
   * would have no way back to the theme once the owner had touched it, and
   * opening the screen would look like a colour had been chosen when none had.
   *
   * So: a switch that means "use the shop's own", and a swatch that is live
   * only when it is off. The swatch shows the shop's colour while it is off,
   * which is also the honest answer to "what colour is it now".
   */
  function colour(key, label, help, value){
    var own = String(value || '') === '';
    return '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>'
      + '<div class="bns-crow">'
      + '<input class="bns-col" type="color" data-bns-colour="' + esc(key) + '" value="' + esc(own ? THEME[key] : value) + '"'
      + (own ? ' disabled' : '') + '>'
      + '<label class="bns-sw"><input type="checkbox" data-bns-own="' + esc(key) + '"' + (own ? ' checked' : '')
      + '><span>Use the shop’s own colour</span></label>'
      + '</div>'
      + '<span class="bns-help">' + esc(help) + '</span></div>';
  }

  /*
   * ── SPEED, WHICH EXISTED AND COULD NOT BE FOUND ─────────────────────────
   *
   * The owner: "you didn't gave controls for speed". It was there — a slider
   * labelled "Speed", helped "milliseconds per card", ninth in a grid of nine
   * numeric boxes. Three things were wrong with it and none of them was that it
   * was missing:
   *
   *   1. MILLISECONDS PER CARD IS NOT A UNIT ANYBODY THINKS IN. The readout now
   *      names a band — Very slow … Very fast — and gives the seconds for one
   *      full loop of THIS set, which is the number he can check against the
   *      thing moving in front of him.
   *   2. DRAGGING RIGHT MADE IT SLOWER, because the column is a duration and a
   *      bigger duration is slower. The slider is mirrored: its own value is
   *      (min + max − speed_ms), so right is faster, and the handler mirrors it
   *      back. The COLUMN is untouched — this is presentation, and the stored
   *      milliseconds-per-card is still what Banners::cssVariables() reads.
   *   3. IT WAS IN THE WRONG PLACE. It is the first control under "Motion" now,
   *      beside the two switches that decide whether there is any motion at
   *      all, instead of ninth among the sizes.
   */
  function speedBands(v){
    return v >= 9000 ? 'Very slow' : v >= 6000 ? 'Slow' : v >= 3000 ? 'Medium' : v >= 1600 ? 'Fast' : 'Very fast';
  }

  /* ── TWO UNITS, ONE COLUMN — Lane BN2 ──────────────────────────────────
     `speed_ms` is the time attached to ONE item in both banner types, which is
     what lets them share the column. What that time IS differs: on the cards
     row it is how long a card takes to travel its own width, and on the slider
     it is how long a picture rests before the next one comes. The owner reads a
     sentence, not a column name, so the sentence has to say which. */
  function speedText(ms, cards, slider){
    if (slider) {
      return '<b>' + esc(speedBands(ms)) + '</b> — each picture rests '
        + esc((ms / 1000).toFixed(1)) + 's, so ' + esc(((ms * Math.max(1, cards)) / 1000).toFixed(0))
        + 's to show all ' + esc(Math.max(1, cards))
        + '. <span class="bns-dim">(' + esc(ms) + ' ms per picture)</span>';
    }

    var loop = (ms * Math.max(1, cards)) / 1000;
    return '<b>' + esc(speedBands(ms)) + '</b> — ' + esc((ms / 1000).toFixed(1)) + 's per card, so '
      + esc(loop.toFixed(0)) + 's for one full loop of ' + esc(Math.max(1, cards))
      + ' card' + (cards === 1 ? '' : 's') + '. <span class="bns-dim">(' + esc(ms) + ' ms per card)</span>';
  }

  function speedField(value, lim, cards, slider){
    var mirrored = lim[0] + lim[1] - value;
    return '<div class="bns-fld"><span class="bns-lab">Speed</span>'
      + '<input class="bns-rng" type="range" data-bns-speed="1" value="' + esc(mirrored) + '" '
      + 'min="' + esc(lim[0]) + '" max="' + esc(lim[1]) + '" step="100">'
      + '<div class="bns-ends"><span>slower</span><span>faster</span></div>'
      + '<span class="bns-help" data-bns-out="speed_ms">' + speedText(value, cards, slider) + '</span></div>';
  }

  /*
   * ── THE SECTION BACKGROUND, WRITTEN ONCE FOR BOTH BANNER TYPES ──────────
   *
   * `bg_mode`, `bg_color` and `bg_image` are three columns BOTH kinds read, and
   * the block that draws them carries three element ids. Copied into each
   * branch those ids appeared TWICE IN THE SOURCE — only one is ever rendered,
   * but AdminNavAndIdsTest reads the source and is right to: a duplicated id is
   * how a screen comes to have two controls writing one value, and the copy is
   * how the two come to drift. It caught this the day it was written.
   *
   * Only the two sentences differ between the types, so only those are
   * arguments.
   */
  function backgroundSection(s, heading, colourHelp){
    return '<div class="bns-sec">' + esc(heading) + '</div><div class="bns-grid">'
      + pick('bg_mode', 'Background', 'What sits behind the whole thing, edge to edge. \u201CNone\u201D is how every set has looked so far.', s.bg_mode, data.enums.bg_modes)
      + '<div data-bns-when="color"' + (s.bg_mode === 'color' ? '' : ' hidden') + '>'
      + colour('bg_color', 'Background colour', colourHelp, s.bg_color)
      + '</div>'
      + '<div data-bns-when="image"' + (s.bg_mode === 'image' ? '' : ' hidden') + '>'
      + fld('Background picture', 'Cropped to fill, centred. It goes into your Media Library like any other upload.',
          '<div class="bns-crow"><div class="bns-bgth" id="bns-bgth">'
          + (s.bg_image ? '<img src="' + esc(draft.bgUrl || openSet.set.bg_image_url) + '" alt="">' : 'none')
          + '</div><button class="bns-btn" id="bns-bgpic">' + (s.bg_image ? 'Change' : 'Choose') + '</button>'
          + '<button class="bns-btn is-danger" id="bns-bgclr">Remove</button></div>')
      + '</div>'
      + '</div>';
  }

  function editorView(){
    if (openId === null) return '';

    if (!openSet || !draft) return '<div class="bns-card"><div class="bns-empty">Opening…</div></div>';

    var s = draft.set;
    var e = data.enums;
    var L = e.limits;
    var cards = openSet.cards || [];

    var html = '<div class="bns-card" id="bns-editor">'
      + '<div class="bns-title">' + esc(openSet.set.name) + '</div>'
      + '<div class="bns-sub">Everything on this card belongs to this set alone. '
      + '<b>Nothing here is saved until you press Save at the bottom</b> — change as much as you like first, and the picture below redraws as you go. '
      + 'Adding, deleting and duplicating happen straight away, because they create and destroy the rows themselves.</div>'
      + '<div id="bns-bar"></div>';

    /* ── THE BANNER TYPE, FIRST AND ON ITS OWN — Lane BN2 ──────────────────
       It is the control everything under it depends on, so it is the control
       at the top. Changing it RE-RENDERS the editor rather than hiding rows,
       because the two types do not share a control set: a slider has no card
       count, no gap and no text band, and leaving those on screen greyed out
       would be four controls that do nothing. The draft survives the redraw —
       it is the same buffer — so nothing typed is lost by looking at the other
       type and changing back. */
    var slider = s.kind === 'slider';

    html += '<div class="bns-sec">Banner type</div><div class="bns-grid">'
      + pick('kind', 'What this set is', 'Both types live in the same list and the homepage shows whichever set you pick above. Changing this changes nothing until you press Save.', s.kind, e.kinds)
      + '</div>';

    if (slider) {
      /* ── the look, which is the four previews ── */
      html += '<div class="bns-sec">Look</div><div class="bns-grid">'
        + pick('slider_style', 'Where the arrows and bars sit', 'All four have the same arrows and the same bars — what changes is where they sit and how they read.', s.slider_style, e.slider_styles)
        + '</div>'
        + '<div class="bns-help" style="margin-top:9px" data-bns-note="slider_style">'
        + esc(e.slider_style_notes[s.slider_style] || '') + '</div>';

      /* ── shape ── */
      html += '<div class="bns-sec">Shape &amp; frame</div><div class="bns-grid">'
        + pick('slider_ratio', 'Shape on a computer', 'From a tablet up. The picture is cropped to fill this shape, centred.', s.slider_ratio, e.slider_ratios)
        + pick('slider_ratio_m', 'Shape on a phone', 'Below a tablet. A 21:9 banner is a 44px band on a phone, which is why this is its own choice.', s.slider_ratio_m, e.slider_ratios)
        + num('card_radius', 'Corner radius', 'px', s.card_radius, L.card_radius[0], L.card_radius[1])
        + pick('shadow', 'Shadow', 'No border at all — the corner radius and the shadow are what lift the picture off the page.', s.shadow, e.shadows)
        + '</div>';

      /* ── motion ── */
      html += '<div class="bns-sec">Motion</div><div class="bns-grid">'
        + speedField(s.speed_ms, L.speed_ms, cards.length, true)
        + '</div>'
        + '<div class="bns-row" style="margin-top:11px">'
        + sw('autoplay', 'Move by itself', s.autoplay)
        + '</div>'
        + '<div class="bns-help" style="margin-top:9px">'
        + 'It <b>always</b> stops while the mouse is over it, while anything inside it has keyboard focus, and for a shopper whose device asks for less motion — those are not switches, because a slideshow that cannot be stopped is one a shopper with a tremor or a screen reader cannot use. There is also a real pause button in the corner of the picture whenever it is moving.'
        + '</div>';

      /* ── the section's background, the same control the cards row has ── */
      html += backgroundSection(s, 'Behind the banner', 'Shown behind the picture, right across the page.');

      /* ── steering ── */
      html += '<div class="bns-sec">How a shopper moves it</div>'
        + '<div class="bns-row">'
        + sw('show_arrows', 'Show the arrows', s.show_arrows)
        + sw('show_dots', 'Show the bars along the bottom', s.show_dots)
        + '</div>'
        + '<div class="bns-help" style="margin-top:9px">'
        + '<b>Both work in every browser</b>, unlike the cards row\u2019s arrows — these are real buttons rather than the browser\u2019s own scroll controls. '
        + 'The bars are one per picture: the one for the picture on show is lit, and tapping any of them goes straight to it. '
        + 'A shopper can also swipe, and use the left and right arrow keys once he has tabbed into it. '
        + 'With one picture in the set neither is drawn, because there is nowhere to go.'
        + '</div>';
    } else {
        /* ── motion ── */
        html += '<div class="bns-sec">Motion</div><div class="bns-grid">'
          + speedField(s.speed_ms, L.speed_ms, cards.length)
          + pick('animation', 'Animation', 'The row loops seamlessly with no JavaScript at all, so it costs the page nothing to run.', s.animation, e.animations)
          + '</div>'
          + '<div class="bns-row" style="margin-top:11px">'
          + sw('autoplay', 'Scroll by itself', s.autoplay)
          + sw('pause_on_hover', 'Pause when the mouse is over it', s.pause_on_hover)
          + '</div>';

        /* ── shape ── */
        html += '<div class="bns-sec">Cards &amp; shape</div><div class="bns-grid">'
          + pick('ratio', 'Card shape', 'Every card in the row is exactly this shape, whatever is written in it and whatever shape the picture is.', s.ratio, e.ratios)
          + pick('shadow', 'Shadow', 'No border at all — the corner radius and the shadow are what lift the card off the page.', s.shadow, e.shadows)
          + num('per_view', 'Cards across on desktop', 'full cards, with the next one peeking past the edge. Phones and tablets narrow this by themselves — one across on a phone.', s.per_view, L.per_view[0], L.per_view[1])
          + num('peek', 'How much of the next card shows', '% of a card past the edge. This is what makes the row read as swipeable at a glance.', s.peek, L.peek[0], L.peek[1])
          + num('gap', 'Space between cards', 'px', s.gap, L.gap[0], L.gap[1])
          + num('card_radius', 'Corner radius', 'px', s.card_radius, L.card_radius[0], L.card_radius[1])
          + '</div>';

        /* ── the section's background ── */
      html += backgroundSection(s, 'Behind the row', 'Shown behind the cards, right across the page.');

        /* ── the button ── */
        html += '<div class="bns-sec">The button</div>'
          + '<div class="bns-sub" style="margin-bottom:10px">One set of colours for the whole row — a row whose buttons are four different colours is not a row. Leave all three on “the shop’s own” and they are the pink the rest of the shop uses.</div>'
          + '<div class="bns-grid">'
          + colour('btn_bg', 'Button colour', 'The pill behind the label.', s.btn_bg)
          + colour('btn_text', 'Button text', 'The label itself. Check it against the colour above — pale on pale is the one mistake this control makes easy.', s.btn_text)
          + colour('btn_hover', 'Button when the mouse is over it', 'Left on the shop’s own, the button simply darkens a little, which works for any colour.', s.btn_hover)
          + '</div>';

        /* ── text ── */
        html += '<div class="bns-sec">The words on a card</div><div class="bns-grid">'
          + pick('title_pos', 'Where the words sit', 'On the picture, they sit over the bottom of it with a soft dark scrim behind them so they stay readable over a pale photograph as well as a dark one.', s.title_pos, e.title_positions)
          + '</div>'
          + '<div class="bns-row" style="margin-top:11px">'
          + sw('show_text', 'Show the heading and the line under it', s.show_text)
          + sw('show_button', 'Show the button', s.show_button)
          + '</div>'
          + '<div class="bns-help" style="margin-top:9px">'
          + 'With the words off the card is the picture alone, at the same shape, and the button goes with them — the whole picture becomes the link instead.'
          + '</div>';

        /* ── steering ── */
        html += '<div class="bns-sec">How a shopper moves the row</div>'
          + '<div class="bns-row">'
          + sw('show_dots', 'Show dots under the row', s.show_dots)
          + sw('show_arrows', 'Show arrows', s.show_arrows)
          + '</div>'
          + '<div class="bns-help" style="margin-top:9px">'
          + 'Both appear only while the row is <em>not</em> scrolling by itself, and for a shopper whose device asks for less motion — while it is scrolling by itself there is nothing for them to steer. '
          + '<b>The dots work in every browser</b>, and the one for the card you jumped to is drawn as a filled bar so a shopper can see where he is. '
          + '<b>Arrows are drawn by Chrome and Edge only.</b> They are the browser’s own scroll buttons, which is the only way to move a row from CSS with no JavaScript; Safari and Firefox draw nothing at all, so roughly a third of shoppers will not see them however this switch is set. The row is still scrollable by hand and by the dots in all four. '
          + 'If you want one control that everybody gets, use the dots.'
          + '</div>';

    }

    /* ── name and order ── */
    html += '<div class="bns-sec">This set</div><div class="bns-grid">'
      + fld('Name', 'Yours to recognise it by. Shoppers never see it.',
          '<input class="bns-in" type="text" data-bns-set="name" value="' + esc(s.name) + '" maxlength="180">')
      + pick('status', 'Published', 'A draft never shows on the shop, whatever is picked above.', s.status, e.statuses)
      + fld('Order in the list above', 'Only changes where this set sits in your own list. Lower comes first.',
          '<input class="bns-in" type="number" min="' + esc(L.position[0]) + '" max="' + esc(L.position[1]) + '" data-bns-set="position" value="' + esc(s.position) + '">')
      + '</div>';

    /* ---------------------------------------------------------- the cards */
    html += '<div class="bns-sec">' + (slider ? 'Pictures' : 'Cards') + '</div>'
      + '<div class="bns-sub">' + (slider
          ? 'One picture each, in this order. A picture with a link becomes a link — the whole picture, since there is no button on this banner type. The description is what a screen reader says; leave it empty for a picture that is decoration and has nothing to add.'
          : 'A picture, one or two lines under it, and a small button. Anything you leave blank is simply not drawn — it leaves no gap.')
      + '</div>'
      + '<div class="bns-row"><button class="bns-btn is-primary" id="bns-newcard">' + (slider ? 'Add a picture' : 'Add a card') + '</button></div>';

    if (!cards.length) {
      html += '<div class="bns-empty">' + (slider ? 'No pictures yet.' : 'No cards yet.') + '</div>' + footer() + '</div>';
      return html;
    }

    html += '<div class="bns-cards">';

    cards.forEach(function(c){
      var d = draft.cards[c.id] || {};
      html += '<div class="bns-cd' + (slider ? ' is-two' : '') + '">'
        + '<div class="bns-th" data-bns-thumb="' + esc(c.id) + '">' + (d.image_url
            ? '<img src="' + esc(d.image_url) + '" alt="">'
            : 'no picture') + '</div>'
        /* ── THE PHONE PICTURE'S OWN THUMBNAIL, RIGHT NEXT TO THE OTHER ONE ──
           (Lane SEC)

           The owner asked for two sizes -- "for desktop the size should be 1920
           x 550 and in mobile 500 x 600" -- which is two pictures, and the
           thing this screen has to make impossible is uploading ONE and
           wondering why the phone looks wrong. So the empty slot is DRAWN
           rather than hidden: a second box, the phone's shape, sitting beside
           the first and saying "no phone picture" until there is one. An empty
           box he can see is a question he asks now; a button he has to notice
           is a question he asks in a week, about his own live site.

           SLIDER ONLY. A cards banner has one frame shape (`ratio`) and draws
           the same picture at every width, so a second upload there would be a
           control that does nothing -- the fault rule 5 and this file's own
           notes keep coming back to. */
        + (slider
            ? '<div class="bns-th bns-th-m" data-bns-thumbm="' + esc(c.id) + '" title="Phone picture, 500 x 600">' + (d.image_m_url
                ? '<img src="' + esc(d.image_m_url) + '" alt="">'
                : 'no phone<br>picture') + '</div>'
            : '')
        + '<div class="bns-cdb">'
        + '<div class="bns-row">'
          + '<button class="bns-btn" data-bns-pic="' + esc(c.id) + '">' + (d.image ? 'Change picture' : 'Choose a picture') + (slider ? ' · 1920 × 550' : '') + '</button>'
          + (slider
              ? '<button class="bns-btn" data-bns-picm="' + esc(c.id) + '">' + (d.image_m ? 'Change phone picture' : 'Choose the phone picture') + ' · 500 × 600</button>'
                + (d.image_m
                    ? '<button class="bns-btn" data-bns-clearm="' + esc(c.id) + '">Remove the phone one</button>'
                    : '')
              : '')
          + '<select class="bns-sel bns-narrow" data-bns-card="' + esc(c.id) + '" data-bns-k="status">'
            + '<option value="publish"' + (d.status === 'publish' ? ' selected' : '') + '>Showing</option>'
            + '<option value="draft"' + (d.status === 'draft' ? ' selected' : '') + '>Hidden</option>'
          + '</select>'
          + '<button class="bns-btn is-danger" data-bns-delcard="' + esc(c.id) + '">Delete</button>'
          + '<span class="bns-warn" data-bns-badurl="' + esc(c.id) + '"' + (c.button_url && !c.button_url_safe ? '' : ' hidden') + '>That link is not a kind of address this shop will publish, so no button is drawn.</span>'
          /* ── SAY IT, RATHER THAN LEAVING HIM TO FIND IT ON HIS OWN PHONE ──
             (Lane SEC)

             A slider slide with no phone picture still draws: it shows the
             desktop picture in the 500 x 600 frame, cropped by `cover` to about
             a quarter of its width. That is a real banner, so nothing looks
             broken in the console and nothing looks broken on a laptop — the
             only place it shows is a handset.

             ▲ AND THE WORDING IS ABOUT THE CROP, NOT THE SHARPNESS, WHICH IS
               THE CHANGE THIS ROUND MADE TO IT.

             The fallback used to be soft as well as cropped, and a soft banner
             is its own argument for fixing it. It is not soft any more: the
             server now makes a phone-shaped crop of the wide picture, so what a
             handset gets is sharp, small — measured, three slides at 390px on
             photographic sources: 80 KB against 458 KB for the whole
             picture — and still only the MIDDLE QUARTER of what he composed.

             A fallback that looks fine is a fallback he never fixes, so the
             line has to name the thing that is still wrong. It says "cropped to
             its middle" and does not mention quality, because quality is no
             longer the complaint and a warning that cries about a fixed problem
             is a warning he learns to scroll past.

             Slider only — a cards banner has one frame shape and wants one
             picture. */
          + (slider && d.image && !d.image_m
              ? '<span class="bns-warn">No phone picture yet, so phones see only the middle of this one — sharp, but cropped to about a quarter of its width. Choose one at 500 × 600 to decide what they see.</span>'
              : '')
        + '</div>'
        /* THE THREE TEXT BOXES ARE THE CARDS ROW'S AND ARE NOT DRAWN FOR A
           SLIDER, because this banner type draws no text at all — the owner's
           words were "only images slider". They are HIDDEN, not deleted: the
           columns keep whatever is in them, so a set switched to a slider and
           back has its headings again. The link and the description ARE drawn,
           because a slider picture can still be a link and still needs a name
           for a screen reader. */
        + (slider ? ''
            : '<input class="bns-in" type="text" placeholder="Heading" maxlength="190" data-bns-card="' + esc(c.id) + '" data-bns-k="heading" value="' + esc(d.heading) + '">'
              + '<input class="bns-in" type="text" placeholder="One short line under it" maxlength="255" data-bns-card="' + esc(c.id) + '" data-bns-k="body" value="' + esc(d.body) + '">')
        + '<div class="bns-grid">'
          + (slider ? ''
              : '<input class="bns-in" type="text" placeholder="Button label" maxlength="80" data-bns-card="' + esc(c.id) + '" data-bns-k="button_label" value="' + esc(d.button_label) + '">')
          + '<input class="bns-in" type="text" placeholder="Where it goes, e.g. /shop/" maxlength="400" data-bns-card="' + esc(c.id) + '" data-bns-k="button_url" value="' + esc(d.button_url) + '">'
          + '<input class="bns-in" type="text" placeholder="Picture description, for screen readers" maxlength="255" data-bns-card="' + esc(c.id) + '" data-bns-k="alt" value="' + esc(d.alt) + '">'
          + '<input class="bns-in" type="number" min="0" max="9999" placeholder="Order" data-bns-card="' + esc(c.id) + '" data-bns-k="position" value="' + esc(d.position) + '">'
        + '</div></div></div>';
    });

    html += '</div>';

    /* -------------------------------------------------------- the preview */
    html += '<div class="bns-sec">How it looks</div>'
      + '<div class="bns-sub">Drawn by the shop’s own template, at a real screen width, from <b>what you have typed</b> — including the changes you have not saved yet.</div>'
      + '<div class="bns-row">'
      + [[390, 'Phone'], [768, 'Tablet'], [1280, 'Desktop']].map(function(w){
          return '<button class="bns-btn' + (previewWidth === w[0] ? ' is-primary' : '') + '" data-bns-w="' + w[0] + '">' + w[1] + ' · ' + w[0] + 'px</button>';
        }).join('')
      + '</div><div class="bns-stage" id="bns-stage"><div class="bns-empty">Drawing…</div></div>';

    return html + footer() + '</div>';
  }

  /* The save footer. It is the bottom of the editor AND it is sticky, because
     the editor is longer than a screen and a Save button the owner has to
     scroll to find is a Save button he will forget to press. */
  function footer(){
    return '<div class="bns-foot" id="bns-foot">'
      + '<span class="bns-savest" id="bns-state"></span>'
      + '<div class="bns-draftacts">'
      + '<button class="bns-btn" id="bns-discard">Discard changes</button>'
      + '<button class="bns-btn is-primary" id="bns-saveset">Save</button>'
      + '</div></div>';
  }

  /* THE UNSAVED MARKER. Written straight into two elements rather than by
     re-rendering, for the reason at the top of this file: a redraw on a
     keystroke is the defect this whole screen was rebuilt to remove. */
  function markDirty(){
    var n = changed().length;
    var bar = document.querySelector('#bns-bar');
    var foot = document.querySelector('#bns-foot');
    var save = document.querySelector('#bns-saveset');
    var disc = document.querySelector('#bns-discard');

    if (bar) {
      bar.innerHTML = n === 0 ? '' : '<div class="bns-draft"><div class="bns-drafttext">'
        + '<b>' + n + ' unsaved change' + (n === 1 ? '' : 's') + '.</b> '
        + 'Nothing has reached the shop yet. The picture below shows them; press Save to keep them.'
        + '</div></div>';
    }

    if (foot) foot.classList.toggle('is-dirty', n > 0);
    if (save) { save.disabled = saving || n === 0; save.textContent = saving ? 'Saving…' : (n === 0 ? 'Saved' : 'Save ' + n + ' change' + (n === 1 ? '' : 's')); }
    if (disc) disc.disabled = saving || n === 0;
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when this screen is the one on show. The console navigates
    // before an async load finishes, and a late response must not redraw
    // somebody else's page.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="bns-wrap">';

    if (banner) html += '<div class="bns-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="bns-card"><div class="bns-empty">' + (busy ? 'Reading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += moduleView() + setsView() + editorView();
    }

    host.innerHTML = html + '</div>';
    wire();
    markDirty();
  }

  /* ----------------------------------------------------------------- wire */
  function wire(){
    var save = document.querySelector('#bns-save');
    if (save) save.onclick = async function(){
      save.disabled = true;
      try {
        await api('/banners/module', 'POST', {enabled: document.querySelector('#bns-module').checked});
        await api('/banners', 'POST', {settings: {set: document.querySelector('#bns-chosen').value}});
        say('Saved.');
        await load();
      } catch (e) {
        save.disabled = false;
        say(explain(e, 'Could not save that.'));
      }
    };

    /* THE TWO NEW-SET BUTTONS. The kind is sent rather than set afterwards, so
       a brand-new slider is a slider on its first draw — created without it the
       owner would meet the cards editor, change the type and have an unsaved
       change on a set he had not touched. The controller validates the token
       against BannerSet::KINDS and falls back to `cards`. */
    document.querySelectorAll('[data-bns-kind]').forEach(function(add){
      add.onclick = async function(){
        var kind = add.dataset.bnsKind;
        if (!mayLeave('Start a new set and lose them?')) return;
        add.disabled = true;
        try {
          var body = await api('/banners/sets', 'POST', {kind: kind});
          await load();
          openEditor(body.set.id);
        } catch (e) {
          add.disabled = false;
          say(explain(e, 'Could not create a set.'));
        }
      };
    });

    document.querySelectorAll('[data-bns-open]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.bnsOpen);
        if (openId !== null && !mayLeave(openId === id ? 'Close this set and lose them?' : 'Open another set and lose them?')) return;
        if (openId === id) { openId = null; openSet = null; draft = null; render(); return; }
        openEditor(id);
      };
    });

    document.querySelectorAll('[data-bns-dup]').forEach(function(b){
      b.onclick = async function(){
        if (!mayLeave('Duplicate and lose them?')) return;
        b.disabled = true;
        try {
          var body = await api('/banners/sets/' + b.dataset.bnsDup + '/duplicate', 'POST', {});
          say('Copied, as a draft.');
          await load();
          openEditor(body.set.id);
        } catch (e) { b.disabled = false; say(explain(e, 'Could not duplicate that.')); }
      };
    });

    document.querySelectorAll('[data-bns-del]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this set and all of its cards? This cannot be undone.')) return;
        b.disabled = true;
        try {
          await api('/banners/sets/' + b.dataset.bnsDel, 'DELETE');
          if (window.kbbDrafts) window.kbbDrafts.drop('banners', b.dataset.bnsDel);
          if (openId === Number(b.dataset.bnsDel)) { openId = null; openSet = null; draft = null; }
          say('Deleted.');
          await load();
        } catch (e) { b.disabled = false; say(explain(e, 'Could not delete that.')); }
      };
    });

    /*
     * ── A SET CONTROL WRITES TO THE DRAFT AND NOWHERE ELSE ────────────────
     *
     * `input` and not `change`, so a slider moves the readout and the preview
     * while it is being dragged. Nothing is sent per event: the preview is
     * debounced and the database is not touched at all until Save.
     */
    document.querySelectorAll('[data-bns-set]').forEach(function(el){
      var key = el.dataset.bnsSet;
      var out = document.querySelector('[data-bns-out="' + key + '"]');

      var apply = function(){
        draft.set[key] = el.type === 'checkbox' ? el.checked : (el.type === 'number' || el.type === 'range' ? Number(el.value) : el.value);
        if (out) out.textContent = el.value;
        if (key === 'bg_mode') {
          document.querySelectorAll('[data-bns-when]').forEach(function(w){
            w.hidden = w.dataset.bnsWhen !== el.value;
          });
        }

        /* THE ONE CONTROL THAT REDRAWS THE EDITOR, and the reason is in
           editorView(): the two banner types do not share a control set. The
           draft is untouched by the redraw, so nothing typed is lost. */
        if (key === 'kind') {
          markDirty();
          render();
          refreshPreview();
          return;
        }

        /* The sentence under the Look picker is the chosen treatment's own, out
           of the enum the server sent. Written straight into the element rather
           than by re-rendering — the same decision markDirty() makes, and for
           the same reason. */
        if (key === 'slider_style') {
          var note = document.querySelector('[data-bns-note="slider_style"]');
          if (note) note.textContent = (data.enums.slider_style_notes || {})[el.value] || '';
        }

        markDirty();
        schedulePreview();
      };

      el.oninput = apply;
      el.onchange = apply;
    });

    /* Speed, mirrored. The slider's own value runs the other way so that right
       is faster; the column keeps its milliseconds-per-card. */
    var speed = document.querySelector('[data-bns-speed]');
    if (speed) {
      var lim = data.enums.limits.speed_ms;
      var out = document.querySelector('[data-bns-out="speed_ms"]');
      var applySpeed = function(){
        var ms = lim[0] + lim[1] - Number(speed.value);
        draft.set.speed_ms = ms;
        if (out) out.innerHTML = speedText(ms, (openSet.cards || []).length, draft.set.kind === 'slider');
        markDirty();
        schedulePreview();
      };
      speed.oninput = applySpeed;
      speed.onchange = applySpeed;
    }

    /* A colour and its "use the shop's own" switch, which write the same key. */
    document.querySelectorAll('[data-bns-own]').forEach(function(box){
      box.onchange = function(){
        var key = box.dataset.bnsOwn;
        var swatch = document.querySelector('[data-bns-colour="' + key + '"]');
        if (swatch) swatch.disabled = box.checked;
        draft.set[key] = box.checked ? '' : (swatch ? swatch.value : '');
        markDirty();
        schedulePreview();
      };
    });

    document.querySelectorAll('[data-bns-colour]').forEach(function(sw2){
      var apply = function(){
        draft.set[sw2.dataset.bnsColour] = sw2.value;
        markDirty();
        schedulePreview();
      };
      sw2.oninput = apply;
      sw2.onchange = apply;
    });

    /* The background picture. The picker is a dialog, so this is a click, not a
       typed edit — but what it produces is still buffered, and the thumbnail is
       repainted by hand rather than by re-rendering the editor. */
    var bgPic = document.querySelector('#bns-bgpic');
    if (bgPic) bgPic.onclick = function(){
      if (typeof window.kbbPickMedia !== 'function') { say('The media picker is not available on this page.'); return; }
      window.kbbPickMedia({
        title: 'Choose the background picture', folder: 'banners',
        onPick: function(urls){
          if (!urls || !urls.length) return;
          draft.set.bg_image = String(urls[0]);
          draft.bgUrl = String(urls[0]);
          var th = document.querySelector('#bns-bgth');
          if (th) th.innerHTML = '<img src="' + esc(draft.bgUrl) + '" alt="">';
          bgPic.textContent = 'Change';
          markDirty();
          schedulePreview();
        }
      });
    };

    var bgClr = document.querySelector('#bns-bgclr');
    if (bgClr) bgClr.onclick = function(){
      draft.set.bg_image = '';
      draft.bgUrl = '';
      var th = document.querySelector('#bns-bgth');
      if (th) th.textContent = 'none';
      if (bgPic) bgPic.textContent = 'Choose';
      markDirty();
      schedulePreview();
    };

    var newCard = document.querySelector('#bns-newcard');
    if (newCard) newCard.onclick = async function(){
      if (!mayLeave('Add a card and lose them?')) return;
      newCard.disabled = true;
      try {
        await api('/banners/sets/' + openId + '/cards', 'POST', {});
        await openEditor(openId);
      } catch (e) { newCard.disabled = false; say(explain(e, 'Could not add a card.')); }
    };

    document.querySelectorAll('[data-bns-card]').forEach(function(el){
      var id = el.dataset.bnsCard;
      var key = el.dataset.bnsK;
      var apply = function(){
        draft.cards[id][key] = el.type === 'number' ? Number(el.value) : el.value;
        markDirty();
        schedulePreview();
      };
      el.oninput = apply;
      el.onchange = apply;
    });

    document.querySelectorAll('[data-bns-delcard]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this card? This happens straight away and cannot be undone.')) return;
        try {
          await api('/banners/cards/' + b.dataset.bnsDelcard, 'DELETE');
          /* The deleted card's buffered edits go with it; everything else the
             owner has typed is kept, which is why the draft is merged forward
             rather than thrown away. */
          var keep = draft;
          await openEditor(openId);
          if (keep && draft) {
            SET_KEYS.forEach(function(k){ draft.set[k] = keep.set[k]; });
            if (keep.bgUrl !== undefined) draft.bgUrl = keep.bgUrl;
            Object.keys(draft.cards).forEach(function(cid){
              if (keep.cards[cid]) draft.cards[cid] = keep.cards[cid];
            });
            render();
            refreshPreview();
          }
        } catch (e) { say(explain(e, 'Could not delete that card.')); }
      };
    });

    /* THE PICTURE COMES THROUGH THE CONSOLE'S SHARED PICKER, which is how it
       reaches the Media Library: window.kbbPickMedia opens the one dialog every
       image field in this console opens, and an upload made from it goes
       through POST /admin-api/media/upload, which registers the file. The
       controller calls MediaRegistrar::record() again on write anyway, for the
       path that did not come this way. */
    document.querySelectorAll('[data-bns-pic]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') {
          say('The media picker is not available on this page.');
          return;
        }

        window.kbbPickMedia({
          title: 'Choose the card’s picture',
          folder: 'banners',
          /* onPick receives an ARRAY OF URLS, always — the picker's own
             docblock says so, and a single-select call gets an array of one
             rather than a bare string precisely so a caller cannot be written
             against the wrong shape and work by accident. The URL is cut down
             to a stored path by the controller, once, rather than here: a
             second copy of that rule in JavaScript is the copy that goes
             stale. */
          onPick: function(urls){
            if (!urls || !urls.length) return;
            var id = b.dataset.bnsPic;
            draft.cards[id].image = String(urls[0]);
            draft.cards[id].image_url = String(urls[0]);
            var th = document.querySelector('[data-bns-thumb="' + id + '"]');
            if (th) th.innerHTML = '<img src="' + esc(String(urls[0])) + '" alt="">';
            b.textContent = 'Change picture';
            markDirty();
            schedulePreview();
          }
        });
      };
    });

    /* ── THE PHONE PICTURE, THROUGH THE SAME PICKER ── (Lane SEC)

       Deliberately a near-copy of the handler above rather than a shared one
       parameterised over two keys: the two differ in the dialog's title, the
       thumbnail they repaint and the button's words, which is three of the five
       lines, and a helper taking three callbacks to save two would be longer
       than both. What they MUST share is the picker itself, and they do —
       window.kbbPickMedia is the one dialog every image field in this console
       opens, so the phone picture is registered in the Media Library exactly as
       the desktop one is. */
    document.querySelectorAll('[data-bns-picm]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') {
          say('The media picker is not available on this page.');
          return;
        }

        window.kbbPickMedia({
          title: 'Choose the phone picture — 500 × 600',
          folder: 'banners',
          onPick: function(urls){
            if (!urls || !urls.length) return;
            var id = b.dataset.bnsPicm;
            draft.cards[id].image_m = String(urls[0]);
            draft.cards[id].image_m_url = String(urls[0]);
            var th = document.querySelector('[data-bns-thumbm="' + id + '"]');
            if (th) th.innerHTML = '<img src="' + esc(String(urls[0])) + '" alt="">';
            b.textContent = 'Change phone picture · 500 × 600';
            markDirty();
            schedulePreview();
            render();
          }
        });
      };
    });

    /* AND A WAY BACK OFF IT. Without this the only route from "I picked the
       wrong file" to "no phone picture" is deleting the whole slide, and the
       empty string is what the storefront reads as "fall back to the desktop
       picture" — so removing it has to be as reachable as choosing it. */
    document.querySelectorAll('[data-bns-clearm]').forEach(function(b){
      b.onclick = function(){
        var id = b.dataset.bnsClearm;
        draft.cards[id].image_m = '';
        draft.cards[id].image_m_url = '';
        markDirty();
        schedulePreview();
        render();
      };
    });

    document.querySelectorAll('[data-bns-w]').forEach(function(b){
      b.onclick = function(){ previewWidth = Number(b.dataset.bnsW); render(); refreshPreview(); };
    });

    /* ── SAVE, AND DISCARD ── */
    var saveSet = document.querySelector('#bns-saveset');
    if (saveSet) saveSet.onclick = async function(){
      if (!dirty() || saving) return;
      saving = true;
      markDirty();
      state('Saving…', false);

      try {
        var body = await api('/banners/sets/' + openId + '/all', 'PUT', payload());
        openSet = {set: body.set, cards: body.cards};
        saving = false;
        startDraft();
        if (window.kbbDrafts) window.kbbDrafts.saved('banners');
        say('Saved.');
        /* The list above carries the name, the status and the card count, so it
           is refreshed too — but AFTER the editor's own state is settled, so a
           failure there cannot leave the editor thinking it is unsaved. */
        render();
        refreshPreview();
        await load();
        /* AFTER load(), not before: load() ends in a render(), which rebuilds
           the footer and takes the sentence with it. The first version said it
           here and the owner saw nothing — the button went grey and that was
           the whole confirmation. */
        state('Saved.', false);
      } catch (e) {
        /*
         * A FAILED SAVE KEEPS THE DRAFT, ALWAYS. This is the one moment the
         * buffer is the only copy of what was typed, and a screen that cleared
         * it here would be the swallowed-write defect CLAUDE.md catalogues
         * wearing different clothes.
         */
        saving = false;
        markDirty();
        state(explain(e, 'Could not save. Nothing was written — your changes are still here.'), true);
        say(explain(e, 'Could not save that.'));
      }
    };

    var discard = document.querySelector('#bns-discard');
    if (discard) discard.onclick = function(){
      if (!dirty()) return;
      if (!window.confirm('Throw away your ' + changed().length + ' unsaved change(s) and go back to what is saved?')) return;
      startDraft();
      render();
      refreshPreview();
    };
  }

  function state(text, bad){
    var el = document.querySelector('#bns-state');
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
