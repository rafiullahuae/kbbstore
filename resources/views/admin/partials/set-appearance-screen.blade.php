{{--
    Appearance → Set → Desktop / Mobile.                       (Lane SA, SA3)

    The owner, first time round: *"I need the full controls of everything like
    spacing, fonts, elements turn on off etc etc. every single details. for
    mobile and desktop both separate tabs. Under Appearance → Set → desktop /
    mobile."*

    And then, having got them: *"on this set backend controls page, i want the
    preview on the right side, so i can avoid the long page. also make the tabs
    or sections properly, not just throw the long page. make it super nice. and
    preview must work in real time upon changing controls."*

    Both are answered here. What he was looking at was 198 controls in 13 cards
    stacked down one column, with the preview at the bottom of it — so the two
    things a person actually does on this screen, move a slider and look at what
    it did, were 4,000 pixels apart.

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so it runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 22,000-line Blade:
    several lanes edit that file at once, and a screen that lives on its own can
    be reviewed, reverted and merged on its own. The cost is that it cannot
    reach app.blade.php's module-scoped constants — NAV, TITLES and ADMIN_BASE
    are const, not window properties — so it appends its own sidebar entry to
    the rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this, and the shape is deliberately the same as
    admin/partials/banners-screen.blade.php.

    ── TWO COLUMNS, AND THE RIGHT-HAND ONE STAYS PUT ─────────────────────────

    Controls on the left, the preview on the right, pinned with `position:
    sticky` and nothing else. No scroll listener, no ResizeObserver and no
    getBoundingClientRect: rule 4 forbids measuring layout in JavaScript on this
    project, and a sticky column is one declaration that the compositor honours
    at 60fps whatever this script is doing.

    Below 1120px there is no right-hand side, so the preview goes FIRST instead
    — `order:-1` on the grid item, one line, no second markup path. The owner
    reviews on a phone and the thing he is judging should not be below the
    thing he is dragging.

    The frame's height is `clamp()` against the viewport rather than a fixed
    620px, so a tall screen gets a tall preview and a phone does not get a
    preview taller than the phone.

    ── EIGHT GROUPS, ONE AT A TIME ──────────────────────────────────────────

    The thirteen cards the API returns are regrouped by WHAT THEY DRAW rather
    than by where they happened to be declared, and exactly one group is on
    screen at a time:

        What is drawn · The set row on the cart page · The fanned stack and its
        popup · The panel and the hang · Rows and photographs · Words · The
        fold and the footing · Where the phone sizes start

    The split is not cosmetic. `d_list_size` and `d_list_type` between them held
    49 controls covering four different things — the photographs, the words, the
    "Show all" fold and the footing — and a card that holds four subjects is a
    card nobody can find anything in. The keys are laid out below in GROUPS, and
    anything the API sends that GROUPS does not place is drawn anyway, in a card
    that says so: a schema key must never be able to disappear from this screen
    because a layout table forgot it. SetAppearanceScreenGroupsTest pins that
    the placement is total and has no duplicates.

    Desktop/Mobile stays exactly where it was, above all of this — it is the axis
    the owner asked for by name, and the group strip sits under it.

    ── THE PREVIEW MOVES AS HE DRAGS, AND ALMOST NEVER ASKS THE SERVER ───────

    160 of the 198 controls are ONE CUSTOM PROPERTY each. The two storefront
    partials read every tunable number as `var(--kset-x, <its old literal>)`, so
    the preview can be moved by re-declaring those same properties into a second
    <style> element inside the frame — instant, no request, and truthful because
    it is the very mechanism the shop is driven by.

    Which property each control writes is NOT written down here. It comes from
    the API, out of App\Services\SetAppearanceLiveMap, which DERIVES it by
    rendering SetAppearance::css() once per field and diffing. A table here
    would be a second copy of boxVars() and listVars(), and this project has
    paid twice already for a second copy that stopped agreeing with the first.

    The other 38 change the sheet's STRUCTURE — a `display:none`, the fan's
    `nth-child()` cap, a media query's own width, the fold's position in the
    MARKUP, and the five cart-row numbers that are real properties in a compiled
    stylesheet rather than variables. Those re-render through
    POST /admin-api/set-appearance/preview, debounced, exactly as everything
    used to. A control the map cannot explain is absent from the map and takes
    that path, so a wrong guess can only make the screen slower, never wrong.

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ──────────────────

    The set box responds with a MEDIA QUERY, and a media query asks the VIEWPORT
    how wide it is, not the box it is drawn in. A preview injected straight into
    this page would resolve the desktop branch inside a 700px panel and show a
    row the shop never draws — on a screen whose entire subject is "desktop and
    mobile separately", that is not a nicety. Inside a frame the query resolves
    against the frame's own width, so the Phone and Desktop buttons show what
    those widths really produce.

    WHICH IS WHY THE FRAME IS NO LONGER `max-width:100%`. It was, and inside a
    flex stage that silently clamped every width button to the width of the
    panel: pressing "Desktop · 1280" in a 700px column resolved the media query
    at 700px and drew the phone branch under a label that said Desktop. The
    stage scrolls now and the frame is `flex:none`, so 1280 means 1280.

    Its contents come from POST /admin-api/set-appearance/preview, which renders
    THE SAME PARTIAL the cart renders, from the values in the buffer. Nothing is
    written. A second copy of that markup here would disagree with the shop the
    first time either was touched — the fault HomepageLayouts::summaries()
    shipped.

    THE POPUP IN THE PREVIEW REALLY OPENS, because the partial ships its own
    script and the frame is a fresh window. The owner presses "What's inside"
    and sees the box he just sized. Nothing here simulates an open state — and
    that is the second reason the live path matters: a re-render replaces the
    document, which closes the popup, so a preview that re-rendered on every
    pixel could never be used to size a popup while it was open.

    ── AND THE CONTROLS REDRAW WITHOUT THE PREVIEW ──────────────────────────

    Which is only half of it, and the other half is drawing. Every redraw used
    to be one innerHTML over #content, so the iframe was thrown away and rebuilt
    from the last document the server sent — on a slider's RELEASE, on a tab
    switch, on opening another section. That was invisible while everything
    re-rendered anyway. It is not invisible now: measured, the popup shut on
    every mouseup.

    So there are three builders. topHTML() is the bar, the breakpoint strip and
    the section strip; leftHTML() is the open section; sideHTML() is the preview
    card. renderControls() replaces the first two and does not touch the third,
    and it is what every handler calls except the two that really change the
    frame — its width, which is the viewport the media queries answer, and its
    language, which only the server can render.

    ── ENGLISH AND العربية ──────────────────────────────────────────────────

    The preview used to be `lang="en" dir="ltr"`, written into the document, and
    the controller had no locale at all — so the rendering most likely to be
    wrong, the mirrored one, was the one that could not be looked at. The panel
    hangs its photographs off its LEADING edge, and a leading edge is a
    different edge in Arabic. There is a language pair beside the width buttons
    now and the whole preview re-renders in that locale, wording included.

    ── THE SAVE BUTTON, BECAUSE HE ASKED FOR ONE ON BANNERS ──────────────────

    Nothing is written until Save. The bar says HOW MANY changes are waiting,
    Discard puts them back, leaving the screen asks first, and closing the tab
    asks too. Same machinery as the Banners editor and for the same reason: he
    makes several edits before he saves, and a screen that writes on every input
    turns a slider drag into forty writes.

    Per-control "shipped" stays, and there is now a "Put this group back" beside
    each group's name — the same idea one level up, for an owner who has been
    experimenting inside one section and wants only that section back.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED sap- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
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
.sap-wrap{display:grid;gap:14px;min-width:0}
.sap-wrap > *{min-width:0}
/* `display:contents` AND NOT A BOX. The bar, the Desktop/Mobile strip and the
   section strip are redrawn together and the preview is not, so they need one
   element to replace the innerHTML of — but a real box here would become the
   sticky bar's containing block, and a sticky element only sticks within its
   parent's own bounds. The bar would unstick about 40px down. With
   `display:contents` the three stay grid items of .sap-wrap exactly as they
   were, and there is an element to write into. */
.sap-top{display:contents}
.sap-top > *{min-width:0}
.sap-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.sap-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.sap-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.sap-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55;margin-bottom:12px}
.sap-note{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:10px;
          padding:10px 12px;font-size:12px;line-height:1.55}
.sap-tabs{display:flex;gap:6px;flex-wrap:wrap;min-width:0}
.sap-tab{font:inherit;font-size:13px;font-weight:650;padding:8px 16px;border-radius:999px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.sap-tab[aria-selected="true"]{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.sap-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));min-width:0}
.sap-f{display:grid;gap:5px;min-width:0;align-content:start}
.sap-fh{display:flex;gap:8px;align-items:baseline;justify-content:space-between;min-width:0}
.sap-fh label{font-size:12.5px;font-weight:600;min-width:0;overflow-wrap:anywhere}
.sap-val{font-size:11.5px;font-weight:700;color:inherit;white-space:nowrap;flex:none;
         background:var(--code-bg,rgba(0,0,0,.05));border-radius:6px;padding:1px 6px;
         font-variant-numeric:tabular-nums}
.sap-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.sap-f input[type=range]{width:100%;min-width:0}
.sap-check{display:flex;gap:9px;align-items:flex-start;min-width:0}
.sap-check input{width:18px;height:18px;flex:0 0 auto;margin-top:2px}
.sap-check label{font-size:13px;font-weight:600;cursor:pointer}
.sap-col{display:flex;gap:8px;align-items:center;min-width:0}
.sap-col input[type=color]{width:38px;height:30px;padding:0;border:1px solid var(--border,#e6e6e6);
                           border-radius:7px;background:var(--surface,#fff);flex:none}
.sap-col input[type=text]{flex:1 1 auto;min-width:0;font:inherit;font-size:12.5px;padding:6px 9px;
                          border:1px solid var(--border,#e6e6e6);border-radius:8px;
                          background:var(--surface,#fff);color:inherit}
.sap-sel{width:100%;min-width:0;font:inherit;font-size:12.5px;padding:7px 9px;
         border:1px solid var(--border,#e6e6e6);border-radius:8px;
         background:var(--surface,#fff);color:inherit}
.sap-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.sap-btn:hover{border-color:var(--accent,#E8919F)}
.sap-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.sap-btn[disabled]{opacity:.5;cursor:default}
.sap-tiny{font:inherit;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:999px;
          border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:var(--ink-soft,#6b7280);
          cursor:pointer;flex:none}
.sap-tiny:hover{border-color:var(--accent,#E8919F);color:inherit}
.sap-bar{position:sticky;top:0;z-index:6;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
         background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);
         padding:11px 14px;min-width:0}
.sap-bar .sap-count{font-size:12.5px;font-weight:650;min-width:0;flex:1 1 auto}
.sap-bar.is-dirty{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.07)}

/* ── the two columns ─────────────────────────────────────────────────────
   One grid, two children, and everything about the pinning is in the three
   media queries below. The preview is FIRST in the flow until there is a
   right-hand side to put it on — `order`, not a second copy of the markup. */
.sap-body{display:grid;gap:16px;min-width:0;align-items:start}
.sap-left{display:grid;gap:14px;min-width:0;align-content:start}
.sap-side{min-width:0;order:-1}
@media (min-width:1120px){
  .sap-body{grid-template-columns:minmax(0,1fr) minmax(330px,430px)}
  .sap-side{order:0;position:sticky;top:66px}
}
@media (min-width:1420px){.sap-body{grid-template-columns:minmax(0,1fr) minmax(430px,560px)}}
@media (min-width:1640px){.sap-body{grid-template-columns:minmax(0,1fr) minmax(520px,648px)}}

/* ── the group strip ─────────────────────────────────────────────────────
   Chips, because there are eight of them and they have to wrap at 390px.
   The count rides on the chip so the owner can see which sections he has
   already been inside without opening them. */
.sap-nav{display:flex;gap:6px;flex-wrap:wrap;min-width:0}
.sap-g{font:inherit;font-size:12px;font-weight:650;padding:7px 12px;border-radius:9px;
       border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;
       cursor:pointer;display:inline-flex;gap:6px;align-items:center;min-width:0}
.sap-g:hover{border-color:var(--accent,#E8919F)}
.sap-g[aria-current="true"]{background:rgba(232,145,159,.12);border-color:var(--accent,#E8919F);
                            box-shadow:inset 0 0 0 1px var(--accent,#E8919F)}
.sap-gn{font-size:9.5px;font-weight:800;border-radius:999px;padding:0 5px;line-height:15px;
        background:var(--accent,#E8919F);color:#fff;flex:none}
.sap-head{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;min-width:0;margin-bottom:4px}
.sap-h1{font-size:17px;font-weight:700;line-height:1.3;margin:0;min-width:0;flex:1 1 220px}
.sap-steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}
.sap-sec{font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280);margin:0 0 3px}

/* ── the preview ─────────────────────────────────────────────────────────
   overflow:auto, and `flex:none` on the frame, because a frame that is
   allowed to shrink is a frame whose media query answers the panel's width
   instead of the width on the button. */
.sap-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:auto;background:#fff;
           margin-top:10px;display:flex;min-width:0;justify-content:center;
           /* `safe` so a frame WIDER than the panel still starts at its own left
              edge: plain centring in a scroll container pushes the overflow out of
              reach on the leading side, which would hide the phone preview's first
              44 pixels at 390. */
           justify-content:safe center}
.sap-frame{border:0;display:block;background:#fff;flex:none;
           height:min(56vh,430px)}
@media (min-width:1120px){.sap-frame{height:clamp(360px,calc(100vh - 250px),760px)}}
.sap-widths{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center}
.sap-sp{flex:1 1 8px;min-width:0}
.sap-path{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.55}
.sap-path b{color:inherit}
.sap-live{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5;margin-top:8px}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'setap';
  var BASE = (window.KBB_ADMIN_BASE || '') + '/admin-api';

  var tabs = null;        // the payload's field groups
  var defaults = null;    // what "shipped" means, from the server
  var saved = null;       // key => value as the server last told us
  var draft = null;       // key => value as the owner has typed it
  var live = null;        // SetAppearanceLiveMap's derivation, from the server
  var locales = null;     // the languages the preview can be drawn in
  var banner = null;
  var busy = false;
  var seq = 0;
  /* The PREVIEW's own counter, and it must not be `seq`. Sharing one would let
     a preview render land between load()'s request and its reply and make
     load() think it had been superseded — the screen would then sit on
     "Loading…" for ever with a perfectly good payload in hand. */
  var pvSeq = 0;
  var open = 'desk';      // 'desk' | 'mob'
  var group = 'parts';
  /* Essentials or everything. FALSE is the default, which is the whole of the
     change: the screen opens showing the handful of controls a section is
     really about. It is a view state and not a setting — nothing is written,
     and it is deliberately not remembered between visits, because a screen
     that opens differently for reasons the owner cannot see is the thing this
     is trying to stop. (Lane CR) */
  var detail = false;
  var frameW = 390;
  var pvLocale = 'en';
  var pvTimer = null;
  /* The last preview document, kept in a MODULE variable rather than on the
     iframe's dataset: render() replaces #content wholesale, so the element the
     dataset lived on is thrown away — switching tabs left the frame blank until
     the next debounce fired, which reads as a preview that has stopped
     working. */
  var pvHtml = null;
  /* The frame's own window, remembered when IT tells us it is ready. The screen
     cannot look inside a sandboxed frame — no allow-same-origin, so
     contentDocument is null — and must not try; this is the only handle it
     has, and postMessage is the only thing it does with it. */
  var pvWin = null;

  /*
   * ── HOW THE 198 CONTROLS ARE LAID OUT ───────────────────────────────────
   *
   * Eight groups. Each names its sections per breakpoint, and a section is
   * either `from` — a whole card exactly as the API sent it, label, prose and
   * all — or an explicit list of keys with a title written here.
   *
   * The `from` ones are the five cards that already were one subject. The
   * explicit ones exist because `d_list_size` and `d_list_type` between them
   * held 49 controls spanning four subjects, and splitting them is the whole
   * point of the exercise.
   *
   * NOTHING IS DROPPED IF THIS TABLE IS WRONG. render() collects every key the
   * API sent for the open breakpoint, subtracts what this table placed, and
   * draws the remainder in a card of its own that says it is ungrouped. A
   * layout table that silently swallowed a new schema key would be the worst
   * possible failure here — a control the owner cannot reach and nobody can
   * see is missing.
   */
  var GROUPS = [
    {
      id: 'parts',
      few: 'on fan_on btn_on save_on p_on p_panel_on',
      label: 'What is drawn',
      blurb: 'Every on/off switch on this screen, in one place — the fanned stack in the basket and the '
        + 'list on the product page. These are SHARED between Desktop and Mobile: an element switched off '
        + 'here is off at both widths, because one control with two halves that can disagree is worse '
        + 'than one honest control.',
      desk: [{ from: 'd_box_parts' }, { from: 'd_list_parts' }],
      mob: []
    },
    {
      id: 'cart',
      label: 'The set row on the cart page',
      blurb: 'The basket row a SET sits in on /cart, and no other row — an ordinary product’s row is '
        + 'Appearance → Cart page → Product rows. The preview shows a set line and an ordinary line '
        + 'together, so you can watch these land on one and leave the other alone.',
      /* FOUR CARDS AND NOT ONE. This section was five controls and is now
         twenty-five, and twenty-five sliders under one heading is the shape the
         owner called hard to use. Split by the part of the row they move, in
         the order you meet them going across it: the room around it, the
         picture, the words, the controls. (Lane CR) */
      few: 'ci_min_h ci_pad_t ci_pad_b ci_name_gap ci_min_h_m ci_pad_t_m ci_pad_b_m ci_name_gap_m',
      desk: [
        {
          title: 'Room around the row',
          desc: 'How tall the row is allowed to get and how much space is inside it. The height is a '
            + 'MINIMUM — it can only ever make a short row taller, never cut a tall one, which is the '
            + 'defect this row had.',
          keys: ['ci_min_h', 'ci_pad_t', 'ci_pad_b', 'ci_pad_x', 'ci_gap']
        },
        {
          title: 'The picture',
          desc: 'The square at the start of the row. NOT the fanned circles — those are under “The '
            + 'fanned stack and its popup”.',
          keys: ['ci_thumb', 'ci_thumb_r']
        },
        {
          title: 'The words',
          desc: 'The brand line and the set’s name, and what separates the name from what is under it.',
          keys: ['ci_brand_f', 'ci_name_f', 'ci_name_gap']
        },
        {
          title: 'The stepper, and where the circles sit',
          desc: 'The quantity control at the foot of the row, and how the circles, the button and the '
            + 'saving line up against the name above them.',
          keys: ['ci_qty_top', 'ci_qty_bot', 'ci_box_align']
        }
      ],
      mob: [
        {
          title: 'Room around the row',
          desc: 'The phone’s own. Still a minimum height.',
          keys: ['ci_min_h_m', 'ci_pad_t_m', 'ci_pad_b_m', 'ci_pad_x_m', 'ci_gap_m']
        },
        { title: 'The picture', desc: 'The phone’s own.', keys: ['ci_thumb_m', 'ci_thumb_r_m'] },
        {
          title: 'The words',
          desc: 'The phone’s own.',
          keys: ['ci_brand_f_m', 'ci_name_f_m', 'ci_name_gap_m']
        },
        {
          title: 'The stepper',
          desc: 'The phone’s own. Where the circles sit is shared and is on the Desktop tab.',
          keys: ['ci_qty_top_m', 'ci_qty_bot_m']
        }
      ]
    },
    {
      id: 'box',
      few: 'top bot gap circle overlap btn_f save_f top_m bot_m gap_m circle_m overlap_m btn_f_m save_f_m',
      label: 'The fanned stack and its popup',
      blurb: 'The circles and the popup under a set’s name, everywhere a basket is drawn. Sizes are per '
        + 'breakpoint; the weights and colours are shared and live on Desktop.',
      desk: [{ from: 'd_box_size' }, { from: 'd_box_type' }],
      mob: [{ from: 'm_box' }]
    },
    {
      id: 'panel',
      few: 'p_panel_r p_panel_pt p_panel_pb p_over p_panel_r_m p_panel_pt_m p_panel_pb_m p_over_m',
      label: 'The panel and the hang',
      blurb: 'The blush box behind “What is in this set”, and the photographs hanging off its leading '
        + 'edge — on a set’s own product page.',
      desk: [
        { from: 'd_list_panel' },
        {
          title: 'The panel’s fill and the photograph’s ring',
          desc: 'Both breakpoints. Leave a colour empty and that element keeps the theme’s own, so a '
            + 'later theme change still moves it.',
          keys: ['p_panel_bg', 'p_ring_c', 'p_ph_bg', 'p_sh_a']
        }
      ],
      mob: [{ from: 'm_list_panel' }]
    },
    {
      id: 'rows',
      few: 'p_rowpad p_photo p_gap p_rowpad_m p_photo_m p_gap_m',
      label: 'Rows and photographs',
      blurb: 'One member’s line in the list: its photograph, the space around it and the rule under it. '
        + 'The row’s height follows the photograph, so the photograph size and the row padding are the '
        + 'two numbers that decide how tall the whole list is.',
      desk: [
        {
          title: 'Sizes and spacing',
          desc: 'Laptop values.',
          keys: ['p_block', 'p_rowpad', 'p_gap', 'p_wgap', 'p_photo', 'p_radius']
        },
        {
          title: 'The hairline between rows',
          desc: 'Both breakpoints. The hairline itself is switched on under “What is drawn”.',
          keys: ['p_line_c']
        }
      ],
      mob: [
        {
          title: 'Sizes and spacing',
          desc: 'The phone’s own. It never inherits the laptop’s — the shipped sheet already differs.',
          keys: ['p_block_m', 'p_rowpad_m', 'p_gap_m', 'p_wgap_m', 'p_photo_m', 'p_radius_m']
        }
      ]
    },
    {
      id: 'words',
      few: 'p_head_f p_brand p_name p_qty p_head_f_m p_brand_m p_name_m p_qty_m',
      label: 'Words',
      blurb: 'The heading, and the three lines of every member — brand, name, option — plus the '
        + 'quantity beside them. There is no font FAMILY here on purpose: this shop has one typographic '
        + 'system, and a family belongs to a site-wide typography setting rather than to the set.',
      desk: [
        {
          title: 'Sizes and line heights',
          desc: 'Laptop values. Three of these are stored in TENTHS of a pixel, because 13.5px cannot be '
            + 'a whole number; the figure beside the slider shows the real one.',
          keys: ['p_head_f', 'p_head_gap', 'p_brand', 'p_brand_lh', 'p_name', 'p_name_lh', 'p_var', 'p_qty']
        },
        {
          title: 'Weight and colour',
          desc: 'Both breakpoints. Leave a colour empty and that element keeps the theme’s own ink.',
          keys: ['p_head_w', 'p_head_c', 'p_lh', 'p_brand_w', 'p_brand_ls', 'p_brand_op', 'p_brand_c',
            'p_name_w', 'p_name_c', 'p_var_c', 'p_qty_w', 'p_qty_c']
        }
      ],
      mob: [
        {
          title: 'Sizes and line heights',
          desc: 'The phone’s own. The weights and the colours are shared and are set on Desktop.',
          keys: ['p_head_f_m', 'p_head_gap_m', 'p_brand_m', 'p_brand_lh_m', 'p_name_m', 'p_name_lh_m',
            'p_var_m', 'p_qty_m']
        }
      ]
    },
    {
      id: 'foot',
      few: 'p_more p_foot p_footsp p_more_m p_foot_m p_footsp_m',
      label: 'The fold and the footing',
      blurb: 'What a long list does past its fold — “Show all” is a disclosure in the MARKUP, so it '
        + 'works with no script and a crawler still reads every member — and the three money lines under '
        + 'it. Whether each of them is drawn at all is under “What is drawn”.',
      desk: [
        {
          title: '“Show all”',
          desc: 'How many rows stand before the fold is under “What is drawn”; these are its size, its '
            + 'spacing and its colour.',
          keys: ['p_more', 'p_morept', 'p_morepb', 'p_more_w', 'p_more_c']
        },
        {
          title: 'The footing',
          desc: 'Bought separately, Set price, You save — and the rule above them.',
          keys: ['p_foot', 'p_was_f', 'p_price_f', 'p_save_f', 'p_footsp', 'p_footgy', 'p_footgx',
            'p_foot_w', 'p_was_w', 'p_save_w', 'p_foot_c', 'p_foot_b_c', 'p_save_c', 'p_footrule_c']
        }
      ],
      mob: [
        {
          title: '“Show all”',
          desc: 'The phone’s own sizes.',
          keys: ['p_more_m', 'p_morept_m', 'p_morepb_m']
        },
        {
          title: 'The footing',
          desc: 'The phone’s own sizes. The weights and colours are shared and are set on Desktop.',
          keys: ['p_foot_m', 'p_was_f_m', 'p_price_f_m', 'p_save_f_m', 'p_footsp_m', 'p_footgy_m', 'p_footgx_m']
        }
      ]
    },
    {
      id: 'where',
      label: 'Where the phone sizes start',
      blurb: 'Three numbers, and they are meant to differ.',
      desk: [],
      mob: [{ from: 'm_where' }]
    }
  ];

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN READ FROM THE XSRF-TOKEN COOKIE, which is what app.blade.php's
     own api() has always sent, and what the Banners and Checkout page screens
     send. A <meta name="csrf-token"> tag is what the shoppable-video screens
     reached for and this console does not render one.
     WITHOUT IT EVERY POST ANSWERS 419 — measured: the live preview never drew
     at all and the console log carried nothing but "419 (unknown status)",
     which reads like a broken endpoint and is a missing header. */
  function headers() {
    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
    };
  }

  async function api(path, opts) {
    var r = await fetch(BASE + path, Object.assign({
      credentials: 'same-origin',
      headers: headers()
    }, opts || {}));

    if (!r.ok) {
      var body = null;
      try { body = await r.json(); } catch (e) {}
      var err = new Error('http ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }

    return r.json();
  }

  /* A 404 from these endpoints almost always means the package shipped without
     its clear_caches migration having run, so the compiled route table does not
     know these paths. Said plainly rather than drawing an empty screen, which
     here would read as "this shop has no set controls" — the exact wrong
     conclusion. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Set appearance endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Set',
      icon: '<circle cx="8.5" cy="12" r="4.2"/><circle cx="14" cy="12" r="4.2"/><circle cx="19" cy="12" r="1.6"/>',
      group: 'Appearance',
      after: ['cartpanel', 'productpage']
    });
  }

  /* ------------------------------------------------------------- the draft */
  function startDraft() {
    draft = {};
    Object.keys(saved || {}).forEach(function (k) { draft[k] = saved[k]; });
  }

  /*
   * Field by field, so the bar can say HOW MANY rather than merely "yes". A
   * count is what tells the owner whether the thing he just changed registered.
   * Loose comparison through String() on purpose: a checkbox gives true/false
   * and the server gives 1/0 for the same column, and a bar that called that a
   * change would say "unsaved" the moment the screen opened.
   */
  function changed() {
    var out = [];
    if (!draft || !saved) return out;

    Object.keys(saved).forEach(function (k) {
      if (String(draft[k] == null ? '' : draft[k]) !== String(saved[k] == null ? '' : saved[k])) out.push(k);
    });

    return out;
  }

  function dirty() { return changed().length > 0; }

  /* How many settings differ from what the package SHIPPED. Rule 1 made
     visible: a fresh shop reads "nothing moved", and the owner can see at a
     glance whether he is looking at his own choices or at the defaults. */
  function movedFromShipped() {
    var out = [];
    if (!draft || !defaults) return out;

    Object.keys(defaults).forEach(function (k) {
      if (String(draft[k] == null ? '' : draft[k]) !== String(defaults[k] == null ? '' : defaults[k])) out.push(k);
    });

    return out;
  }

  /* THE GUARD ON LEAVING. Two doors out of this screen come through here —
     another screen in the sidebar, and a reload. The third, closing the tab, is
     beforeunload at the bottom of this file, which a browser will only honour
     as a generic prompt. */
  /* LEAVING KEEPS THE DRAFT, AND ASKS NOTHING. (Lane PM)
     This was window.confirm("You have N unsaved change(s) to the set … Leave
     them?") — the owner's "weired popup". The typing goes to Unfinished in the
     top bar instead (partials/unfinished-drafts.blade.php); coming back to
     this screen puts it back with a bar that says so. */
  function mayLeave() {
    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('setap');
    return true;
  }

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: 'setap', screen: SCREEN, label: 'Appearance → Set',
    values: function () { return (draft && saved) ? draft : null; },
    set: function (k, v) { if (draft && Object.prototype.hasOwnProperty.call(saved || {}, k)) draft[k] = v; },
    count: function () { return changed().length; },
    render: function () { render(); refreshPreview(); },
    save: function () { save(); }
  });

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) {
      mayLeave();
      draft = null;
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });

    var grp = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (grp) grp.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Set';

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
  async function load() {
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/set-appearance');
      if (mine !== seq) return;
      tabs = body.tabs;
      defaults = body.defaults;
      /* Both are optional on purpose: a server that predates them still draws
         a working screen, it just re-renders the preview for everything. */
      live = body.live || null;
      locales = body.locales || null;
      saved = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { saved[f.key] = f.value; });
      });
      startDraft();
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read the set appearance settings.');
      tabs = null;
    } finally {
      if (mine === seq) {
        busy = false; render(); refreshPreview();
        /* Unfinished changes to the set, if any, come back now. */
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready('setap');
      }
    }
  }

  async function save() {
    if (!draft) return;

    var keys = changed();
    if (!keys.length) { say('Nothing to save.'); return; }

    var payload = {};
    keys.forEach(function (k) { payload[k] = draft[k]; });

    try {
      await api('/set-appearance', { method: 'POST', body: JSON.stringify({ settings: payload }) });
      keys.forEach(function (k) { saved[k] = draft[k]; });
      if (window.kbbDrafts) window.kbbDrafts.saved('setap');
      say('Saved ' + keys.length + ' change(s).');
      renderControls();
    } catch (e) {
      say(explain(e, 'Could not save.'));
    }
  }

  /* -------------------------------------------------------------- drawing */
  function fieldOf(key) {
    var found = null;
    (tabs || []).forEach(function (t) {
      t.fields.forEach(function (f) { if (f.key === key) found = f; });
    });
    return found;
  }

  function tabOf(key) {
    var found = null;
    (tabs || []).forEach(function (t) { if (t.key === key) found = t; });
    return found;
  }

  /* What the number beside a slider reads. The three list sizes are stored in
     TENTHS of a pixel — 13.5px cannot be a whole number — so the unit says
     "/10 px" and this divides. One place does it, and App\Services\
     SetAppearance prints the same division into the stylesheet, so the slider
     and the page cannot disagree by a factor of ten. */
  function shown(f) {
    var v = draft[f.key];
    var o = f.options || {};
    var u = o.unit == null ? '' : o.unit;

    if (u === '/10 px') return (Number(v) / 10) + 'px';
    if (u === '/100 em') return (Number(v) / 100) + 'em';
    if (u === '/100') return String(Number(v) / 100);

    return String(v) + u;
  }

  function isShipped(key) {
    return String(draft[key] == null ? '' : draft[key])
      === String(defaults[key] == null ? '' : defaults[key]);
  }

  function fieldHTML(f) {
    var id = 'sap-' + f.key;
    var help = f.help ? '<p class="sap-help">' + esc(f.help) + '</p>' : '';
    /* Only drawn when the value is NOT the shipped one, so a fresh screen
       carries no furniture at all and the button's presence is itself the
       answer to "have I moved this". */
    var reset = isShipped(f.key) ? ''
      : '<button type="button" class="sap-tiny" data-sap-reset="' + esc(f.key) + '">shipped</button>';

    if (f.type === 'bool') {
      return '<div class="sap-f"><div class="sap-check">'
        + '<input type="checkbox" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
        + (draft[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + reset + '</div></div>';
    }

    if (f.type === 'colour') {
      /* TWO CONTROLS OVER ONE VALUE, because the value may be EMPTY and a
         native colour input cannot hold "nothing" — it answers #000000 for an
         empty value and would turn "keep the theme's own colour" into black the
         first time the owner opened the screen. So the text box is the value
         and the swatch is a picker that writes into it. */
      var hex = String(draft[f.key] == null ? '' : draft[f.key]);
      var swatch = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#ffffff';

      return '<div class="sap-f"><div class="sap-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + reset + '</div><div class="sap-col">'
        + '<input type="color" value="' + esc(swatch) + '" data-sap-swatch="' + esc(f.key) + '"'
        + ' aria-label="' + esc(f.label) + ' colour picker">'
        + '<input type="text" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
        + ' value="' + esc(hex) + '" placeholder="theme’s own" spellcheck="false">'
        + '</div>' + help + '</div>';
    }

    /* ── A SELECT, AND IT HAD NO BRANCH ─────────────────────────── (Lane CR)
     *
     * Everything on this screen was a switch, a colour or a slider until "where
     * the circles sit" arrived, and a field with no branch FELL THROUGH TO THE
     * RANGE ONE: `<input type="range" min="undefined" max="undefined"
     * value="start">`, which draws a dead slider and posts NaN. That is not
     * hypothetical — the handler below carries a note about three selects on
     * the Checkout page screen that shipped exactly that way and saved as NaN.
     * Found by reading this function after adding the control, not by the
     * suite, which is why SetScreenIsSimplerTest now has a case for it.
     *
     * The handler needs nothing: a <select> element's `.type` is 'select-one',
     * which is neither 'checkbox' nor 'range', so it takes the string branch —
     * which is the right one, because the value is one of the schema's own
     * option keys and never a number. */
    if (f.type === 'select' || f.type === 'enum') {
      var opts = f.options || {};
      var current = String(draft[f.key] == null ? '' : draft[f.key]);

      return '<div class="sap-f"><div class="sap-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + reset + '</div>'
        + '<select id="' + id + '" data-sap-key="' + esc(f.key) + '" class="sap-sel">'
        + Object.keys(opts).map(function (v) {
          return '<option value="' + esc(v) + '"' + (v === current ? ' selected' : '') + '>'
            + esc(opts[v]) + '</option>';
        }).join('')
        + '</select>' + help + '</div>';
    }

    var o = f.options || {};
    return '<div class="sap-f"><div class="sap-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
      + '<span class="sap-val" data-sap-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span>'
      + reset + '</div>'
      + '<input type="range" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
      + ' min="' + esc(o.min) + '" max="' + esc(o.max) + '" step="' + esc(o.step) + '"'
      + ' value="' + esc(draft[f.key]) + '">'
      + help + '</div>';
  }

  function cardHTML(title, description, fields) {
    if (!fields.length) return '';

    return '<div class="sap-card"><div class="sap-title">' + esc(title) + '</div>'
      + (description ? '<p class="sap-sub">' + esc(description) + '</p>' : '')
      + '<div class="sap-grid">' + fields.map(fieldHTML).join('') + '</div></div>';
  }

  /* ------------------------------------------------------------ the groups */
  /* Every key the API sent for this breakpoint, in the API's own order. The
     prefix is the one thing this screen still reads off a tab key, and it is
     the same split App\Services\SetAppearance's TABS declares. */
  function keysOfBreakpoint() {
    var out = [];
    var want = open === 'desk' ? 'd_' : 'm_';

    (tabs || []).forEach(function (t) {
      if (t.key.indexOf(want) !== 0) return;
      t.fields.forEach(function (f) { out.push(f.key); });
    });

    return out;
  }

  function sectionsOf(g) {
    return (open === 'desk' ? g.desk : g.mob) || [];
  }

  function keysOfSection(s) {
    if (s.keys) return s.keys;
    var t = tabOf(s.from);
    return t ? t.fields.map(function (f) { return f.key; }) : [];
  }

  function keysOfGroup(g) {
    var out = [];
    sectionsOf(g).forEach(function (s) {
      keysOfSection(s).forEach(function (k) { out.push(k); });
    });
    return out;
  }

  /** The groups that have anything to show at this breakpoint, plus the count. */
  function visibleGroups() {
    var moved = {};
    movedFromShipped().forEach(function (k) { moved[k] = true; });

    return GROUPS.filter(function (g) { return keysOfGroup(g).length > 0; })
      .map(function (g) {
        var n = 0;
        keysOfGroup(g).forEach(function (k) { if (moved[k]) n++; });
        return { g: g, moved: n };
      });
  }

  /* Anything GROUPS did not place. Normally empty, and pinned empty by
     SetAppearanceScreenGroupsTest — but drawn rather than dropped, because a
     control the owner cannot reach and nobody can see is missing is the worst
     way for this table to be wrong. */
  function ungrouped() {
    var placed = {};
    GROUPS.forEach(function (g) {
      keysOfGroup(g).forEach(function (k) { placed[k] = true; });
    });

    return keysOfBreakpoint().filter(function (k) { return !placed[k]; });
  }

  /* ── FEWER CONTROLS ON THE SCREEN AT ONCE ─────────────────────── (Lane CR)
   *
   * The owner, having been given 198 controls in eight sections with a live
   * preview: *"also make it super easier the set control page."* The sections
   * were the right move and they are not enough — the two biggest still open
   * with twenty-five sliders, and this round adds fifteen more.
   *
   * So every section names the handful somebody actually reaches for, and that
   * is what opens. The rest are one press away and the press says how many
   * there are, so nothing is hidden, only folded. `few` is a SPACE-SEPARATED
   * STRING rather than an array of quoted keys on purpose: the layout table is
   * read out of this file by SetAppearanceScreenGroupsTest, which counts
   * `keys: [...]` entries to prove the placement is total and has no
   * duplicates, and a second array holding the same names would read as the
   * same control placed twice.
   *
   * A group with no `few` opens whole, which is what "Where the phone sizes
   * start" — three controls — wants.
   */
  function fewOf(g) {
    if (!g.few) return null;
    var set = {};
    g.few.split(' ').forEach(function (k) { if (k) set[k] = true; });
    return set;
  }

  function groupHTML(entry) {
    var g = entry.g;
    var few = fewOf(g);
    var all = keysOfGroup(g);
    var short = few ? all.filter(function (k) { return few[k]; }) : all;
    var folding = few && short.length && short.length < all.length;
    var showAll = detail || !folding;

    var out = '<div class="sap-card"><div class="sap-head">'
      + '<h2 class="sap-h1">' + esc(g.label) + '</h2>'
      + (folding
        ? '<button type="button" class="sap-btn' + (showAll ? '' : ' is-primary') + '" data-sap-detail="'
          + (showAll ? 'few' : 'all') + '">'
          + (showAll ? 'Just the ' + short.length + ' main ones' : 'Show all ' + all.length + ' controls')
          + '</button>'
        : '')
      + (entry.moved
        ? '<button type="button" class="sap-btn" data-sap-groupreset="' + esc(g.id) + '">Put this group back to shipped ('
          + entry.moved + ')</button>'
        : '')
      + '</div><p class="sap-sub">' + esc(g.blurb) + '</p>';

    if (open === 'mob' && g.id !== 'where') {
      out += '<div class="sap-note">These are the phone’s own measurements. <b>What is drawn, every '
        + 'weight and every colour are shared with Desktop</b> and are set on that tab. The phone never '
        + 'inherits a size from the laptop: the shop already draws several of these differently at the '
        + 'two widths, so a value that quietly followed the other one would be wrong the day it shipped.'
        + '</div>';
    }

    out += '</div>';

    sectionsOf(g).forEach(function (s) {
      var t = s.from ? tabOf(s.from) : null;
      var title = s.title || (t ? t.label : '');
      var desc = s.desc || (t ? t.description : '');
      var keys = keysOfSection(s).filter(function (k) { return showAll || few[k]; });
      var fields = keys.map(fieldOf).filter(Boolean);
      /* cardHTML() draws nothing for an empty list, so a card whose controls
         are all in the folded half simply is not there — the owner sees four
         fields rather than four fields and three empty headings. */
      out += cardHTML(title, desc, fields);
    });

    /* SAID ONCE, AT THE FOOT, and only while something is folded. A person who
       has just read four sliders and is looking for a fifth is at the bottom of
       the column, not back at the heading. */
    if (!showAll) {
      out += '<div class="sap-card"><p class="sap-path">'
        + (all.length - short.length) + ' more control'
        + (all.length - short.length === 1 ? '' : 's')
        + ' in this section are folded away — padding on every edge, corner radii, weights and '
        + 'colours. <b>Nothing is hidden from the shop</b>: a folded control still has whatever value it '
        + 'has.</p><div class="sap-steps"><button type="button" class="sap-btn" data-sap-detail="all">'
        + 'Show all ' + all.length + ' controls</button></div></div>';
    }

    return out;
  }

  /* ── THREE BUILDERS, AND THE PREVIEW IS NOT IN THE FIRST TWO ────────────
   *
   * Everything used to be one innerHTML over #content, which threw the iframe
   * away and rebuilt it from the last server document — so releasing a slider,
   * switching tab or opening another section RELOADED the preview. That was
   * invisible while every change re-rendered anyway. It is not invisible now:
   * the whole point of the live path is that the frame keeps its state, and a
   * popup the owner has opened in order to size it would have closed on the
   * first mouseup.
   *
   * So the controls redraw on their own and the preview column is left alone
   * unless something about the FRAME really changed — its width, its language,
   * or a structural re-render from the server.
   */
  function topHTML() {
    var n = changed().length;
    var moved = movedFromShipped().length;

    var bar = '<div class="sap-bar' + (n ? ' is-dirty' : '') + '">'
      + '<span class="sap-count">' + (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes')
      + ' · ' + (moved ? moved + ' setting' + (moved === 1 ? '' : 's') + ' moved from shipped'
                            : 'everything is at the value the page shipped with') + '</span>'
      + '<button type="button" class="sap-btn" data-sap-discard' + (n ? '' : ' disabled') + '>Discard</button>'
      + '<button type="button" class="sap-btn is-primary" data-sap-save' + (n ? '' : ' disabled') + '>Save</button>'
      + '</div>';

    var strip = '<div class="sap-tabs">'
      + '<button type="button" class="sap-tab" data-sap-tab="desk" aria-selected="'
      + (open === 'desk' ? 'true' : 'false') + '">Desktop</button>'
      + '<button type="button" class="sap-tab" data-sap-tab="mob" aria-selected="'
      + (open === 'mob' ? 'true' : 'false') + '">Mobile</button>'
      + '</div>';

    /* NOT role="tablist". A tablist's children must be role="tab" with
       aria-selected, and these are buttons that swap the column's contents
       rather than tab panels with ids to point at — a half-applied tab pattern
       reads worse to a screen reader than none. `aria-current` is the honest
       one: this is the item in the set you are on. */
    var nav = '<div class="sap-nav" aria-label="Sections">'
      + visibleGroups().map(function (e) {
        return '<button type="button" class="sap-g" data-sap-group="' + esc(e.g.id) + '"'
          + ' aria-current="' + (e.g.id === group ? 'true' : 'false') + '">' + esc(e.g.label)
          + (e.moved ? '<span class="sap-gn">' + e.moved + '</span>' : '') + '</button>';
      }).join('') + '</div>';

    return bar + strip + nav;
  }

  function leftHTML() {
    /* The open group has to exist at this breakpoint: "What is drawn" is
       Desktop-only and "Where the phone sizes start" is Mobile-only, so
       switching the tab can strand the selection. Fall to the first rather
       than drawing an empty column. */
    var vis = visibleGroups();
    var here = vis.filter(function (e) { return e.g.id === group; });
    if (!here.length && vis.length) { group = vis[0].g.id; here = [vis[0]]; }

    var body = here.length ? groupHTML(here[0]) : '';

    var loose = ungrouped().map(fieldOf).filter(Boolean);
    if (loose.length) {
      body += cardHTML('Not yet grouped',
        'These controls arrived from the server and this screen\u2019s layout table does not place them. '
        + 'They are drawn here so nothing can go missing; tell a developer.', loose);
    }

    /* Prev/next at the foot of the column, so a long section does not have to
       be scrolled back up out of to reach the next one. */
    var at = -1;
    vis.forEach(function (e, i) { if (e.g.id === group) at = i; });

    var steps = '<div class="sap-card"><div class="sap-steps">'
      + (at > 0 ? '<button type="button" class="sap-btn" data-sap-group="' + esc(vis[at - 1].g.id)
        + '">\u2190 ' + esc(vis[at - 1].g.label) + '</button>' : '')
      + (at >= 0 && at < vis.length - 1
        ? '<button type="button" class="sap-btn" data-sap-group="' + esc(vis[at + 1].g.id)
          + '">' + esc(vis[at + 1].g.label) + ' \u2192</button>' : '')
      + '</div></div>';

    var where = '<div class="sap-card"><div class="sap-title">Where these controls land on the shop</div>'
      + '<p class="sap-path"><b>Set row on the cart page</b> \u2014 the row a SET sits in on /cart, and no '
      + 'other row in the basket.<br>'
      + '<b>Set box</b> \u2014 the circles and the \u201cWhat\u2019s inside\u201d popup under a set\u2019s name in the '
      + 'cart drawer, on the cart page, in the checkout summary, in the browsed rail and on an order\u2019s '
      + 'detail page.<br>'
      + '<b>Set list</b> \u2014 \u201cWhat is in this set\u201d in the buy column of a set\u2019s own product page.</p></div>';

    return body + steps + where;
  }

  function sideHTML() {
    var localeDir = 'ltr';
    (locales || []).forEach(function (l) { if (l.code === pvLocale) localeDir = l.dir; });

    var langs = (locales || []).map(function (l) {
      return '<button type="button" class="sap-btn' + (pvLocale === l.code ? ' is-primary' : '') + '"'
        + ' data-sap-loc="' + esc(l.code) + '" lang="' + esc(l.code) + '">' + esc(l.native) + '</button>';
    }).join('');

    var fast = live ? Object.keys(live.fields).length : 0;

    return '<div class="sap-card"><div class="sap-title">Live preview</div>'
      + '<p class="sap-sub">What you have typed, never what is saved \u2014 nothing here writes anything. '
      + 'The real partials, in a frame, so the phone and desktop sizes resolve against the frame\u2019s '
      + 'width the way they do on a real screen.</p>'
      + '<div class="sap-widths">'
      + '<button type="button" class="sap-btn' + (frameW === 390 ? ' is-primary' : '') + '" data-sap-w="390">Phone \u00b7 390</button>'
      + '<button type="button" class="sap-btn' + (frameW === 760 ? ' is-primary' : '') + '" data-sap-w="760">Tablet \u00b7 760</button>'
      + '<button type="button" class="sap-btn' + (frameW === 1280 ? ' is-primary' : '') + '" data-sap-w="1280">Desktop \u00b7 1280</button>'
      + (langs ? '<span class="sap-sp"></span>' + langs : '')
      + '</div>'
      /* THE STAGE TAKES THE PREVIEW'S DIRECTION, and it is not cosmetic. When
         the frame is wider than the panel the stage scrolls, and where that
         scroll STARTS is decided by the stage's own direction — so a mirrored
         preview inside a left-to-right stage opens showing its empty left
         margin with every word off the right-hand edge. Measured: the Arabic
         panel at a 760px frame in a 648px column. One attribute, no script. */
      + '<div class="sap-stage"' + (localeDir === 'rtl' ? ' dir="rtl"' : '')
      + '><iframe class="sap-frame" id="sap-frame" title="Set preview" '
      + 'style="width:' + frameW + 'px" sandbox="allow-scripts"></iframe></div>'
      + '<p class="sap-live">The top block is a set line beside an ordinary one, so the Set-row controls '
      + 'can be seen landing on one and not the other. Press \u201cWhat\u2019s inside\u201d: the popup really opens. '
      + 'Scroll the frame sideways if the width you picked is wider than this panel.<br><br>'
      /* `fast` is 0 only on a server that predates the live map — the screen
         still works there, it just redraws everything through the endpoint, and
         saying "0 of these controls" would read as a broken feature rather than
         an older back end. */
      + (fast
        ? fast + ' of these controls redraw the frame as you drag them, with no request at all \u2014 they '
          + 'are single custom properties and the shop is driven by those very properties. The rest change '
          + 'the STRUCTURE of the stylesheet (something switched off, the fan\u2019s cap, a breakpoint, the '
          + 'fold, the set row\u2019s own padding) and re-render here a moment after you let go.'
        : 'This server does not send the live map yet, so every control re-renders the frame through the '
          + 'endpoint a moment after you let go.') + '</p>'
      + '</div>';
  }

  /** The controls only. The preview column, and the frame in it, are untouched. */
  function renderControls() {
    var top = document.querySelector('.sap-top');
    var left = document.querySelector('.sap-left');

    if (!top || !left) { render(); return; }

    top.innerHTML = topHTML();
    left.innerHTML = leftHTML();
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Set') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sap-wrap"><div class="sap-card">Loading\u2026</div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="sap-wrap"><div class="sap-card">'
        + '<div class="sap-title">Set</div>'
        + '<div class="sap-banner">' + esc(banner || 'Nothing to show yet.') + '</div>'
        + '<button type="button" class="sap-btn" data-sap-reload>Retry</button>'
        + '</div></div>';
      return;
    }

    host.innerHTML = '<div class="sap-wrap">'
      + '<div class="sap-top">' + topHTML() + '</div>'
      + '<div class="sap-body">'
      + '<div class="sap-left">' + leftHTML() + '</div>'
      + '<div class="sap-side">' + sideHTML() + '</div>'
      + '</div></div>';

    var frame = document.querySelector('#sap-frame');
    if (frame && pvHtml) {
      /* A new element every full render, so the window we were posting into is
         gone and the document will announce itself again. */
      pvWin = null;
      frame.srcdoc = pvHtml;
    }
  }

  /* ═══════════════════════════════ the live overlay ════════════════════════
   *
   * The whole set of custom properties, rebuilt from the buffer and posted into
   * the frame. Rebuilt WHOLE rather than patched, and emitted in the order
   * SetAppearanceLiveMap gives, because the sheet it lands on top of declares
   * several of these twice — once at the laptop and once inside a media query —
   * and whichever the overlay writes LAST is the one that wins inside that
   * query. Emitting the phone block before the laptop one would pin every phone
   * value to its laptop one the moment a slider moved, which is precisely the
   * distinction this screen exists to keep.
   *
   * It costs a couple of hundred string joins per input event and no request.
   */
  function declValue(f, v) {
    if (f.c) {
      var hex = String(v == null ? '' : v).trim();
      if (/^#?[0-9a-fA-F]{6}$/.test(hex)) return hex.charAt(0) === '#' ? hex : '#' + hex;

      /* EMPTY, OR HALF-TYPED. `initial` makes a custom property
         guaranteed-invalid, which is the one thing that hands the partial's own
         `var(--x, <fallback>)` back. Leaving the declaration out would not do
         it: the sheet underneath may still declare the property, and a
         declared-but-empty custom property is a VALID value that beats the
         fallback — the exact trap SetAppearance::colourVar() documents. */
      return 'initial';
    }

    var n = Math.round(Number(v));
    if (!isFinite(n)) return '';

    return (f.n ? '-' : '') + (f.d === 1 ? String(n) : String(n / f.d)) + f.s;
  }

  /* ── THE PREVIEW SHOWS THE THING THE OPEN SECTION IS ABOUT ────── (Lane CR)
   *
   * The frame draws three surfaces stacked — the cart row, the box as the
   * drawer and checkout draw it, and the product page's list. Seven of the
   * eight sections are about ONE of them, so the other two are 300 pixels of
   * scrolling between the owner and the thing he just moved.
   *
   * SENT AS CSS DOWN THE CHANNEL THAT ALREADY EXISTS, rather than as a new
   * message or a new request. The frame's receiver filters what arrives to the
   * characters a declaration block can be spelled with and assigns it to
   * .textContent, so a class selector and `display:none` survive it exactly as
   * a custom property does and nothing else has to be trusted. No branch in
   * the controller, no second render, and a section change costs one
   * postMessage.
   *
   * 'all' means show everything, which is what "What is drawn" and the
   * breakpoint section really are about.
   */
  var FOCUS = {
    cart: 'cart', box: 'box', panel: 'list', rows: 'list', words: 'list',
    foot: 'list', parts: 'all', where: 'all'
  };

  function focusCss() {
    var want = FOCUS[group] || 'all';
    if (want === 'all') return '';

    return ['cart', 'box', 'list'].filter(function (s) { return s !== want; })
      .map(function (s) { return '.sap-s-' + s + '{display:none}'; }).join('');
  }

  function overlayCss() {
    if (!live || !draft) return focusCss();

    var buckets = live.blocks.map(function () { return []; });

    Object.keys(live.fields).forEach(function (k) {
      var f = live.fields[k];
      if (!(k in draft)) return;
      var v = declValue(f, draft[k]);
      if (v === '') return;
      buckets[f.b].push(f.p + ':' + v);
    });

    var css = '';

    live.blocks.forEach(function (b, i) {
      if (!buckets[i].length) return;

      var block = b.sel + '{' + buckets[i].join(';') + '}';

      if (b.mq) {
        var w = Math.round(Number(draft[b.mq]));
        if (!isFinite(w)) return;
        block = '@media (max-width:' + w + 'px){' + block + '}';
      }

      css += block;
    });

    return css + focusCss();
  }

  function pushOverlay() {
    if (!pvWin) return;
    try { pvWin.postMessage({ kbbSetLive: 'css', css: overlayCss() }, '*'); } catch (e) {}
  }

  /* Does this key need the server, or can the frame draw it itself? */
  function isFast(key) {
    return !!(live && live.fields && Object.prototype.hasOwnProperty.call(live.fields, key));
  }

  /* The preview, redrawn from the BUFFER and debounced. Only the 38 structural
     controls come through here now; 260ms is below the point a redraw reads as
     a response to something else. */
  function schedulePreview() {
    if (pvTimer) clearTimeout(pvTimer);
    pvTimer = setTimeout(refreshPreview, 260);
  }

  async function refreshPreview() {
    if (!draft) return;

    var frame = document.querySelector('#sap-frame');
    if (!frame) return;

    var mine = ++pvSeq;

    try {
      var r = await fetch(BASE + '/set-appearance/preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: Object.assign(headers(), { Accept: 'text/html' }),
        body: JSON.stringify({ settings: draft, locale: pvLocale })
      });

      if (!r.ok) return;

      var html = await r.text();
      if (mine !== pvSeq) return;

      pvHtml = html;
      pvWin = null;
      frame.srcdoc = pvHtml;
    } catch (e) { /* a preview that cannot be drawn is not an error worth a toast */ }
  }

  /* The frame says when it is ready, and this is the only thing that ever
     answers it. `e.source` identity and not `e.origin`: a sandboxed srcdoc
     frame has an OPAQUE origin, which serialises to the string "null", and
     "null" is also what a file:// page and any other sandboxed frame reports —
     so the origin proves nothing and the window handle proves everything. */
  window.addEventListener('message', function (e) {
    var frame = document.querySelector('#sap-frame');
    if (!frame || e.source !== frame.contentWindow) return;
    if (!e.data || typeof e.data !== 'object' || e.data.kbbSetLive !== 'ready') return;

    pvWin = frame.contentWindow;
    pushOverlay();
  });

  /* ------------------------------------------------------------- handlers */
  function onScreen(el) {
    return !!(el && el.closest && el.closest('.sap-wrap'));
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!onScreen(t)) return;

    var tab = t.closest('[data-sap-tab]');
    if (tab) { open = tab.dataset.sapTab; renderControls(); return; }

    /* The fold, BEFORE the section buttons: the "Show all" control at the foot
       of a section is not inside one, but the prev/next buttons beside it carry
       data-sap-group and a mis-ordered check would move the owner to another
       section instead of unfolding the one he is in. (Lane CR) */
    var d = t.closest('[data-sap-detail]');
    if (d) { detail = d.dataset.sapDetail === 'all'; renderControls(); return; }

    var g = t.closest('[data-sap-group]');
    if (g) {
      group = g.dataset.sapGroup;
      /* A new section opens folded, whatever the last one was left at: the
         point of the fold is what a section OPENS with. */
      detail = false;
      renderControls();
      /* The preview shows the surface this section is about, and that is a
         postMessage rather than a re-render — see focusCss(). Without this the
         frame keeps showing the last section's surface until the next drag. */
      pushOverlay();
      return;
    }

    /* The width is the ONE thing on this card that really needs a new frame:
       it is the viewport the media queries are answering. */
    var w = t.closest('[data-sap-w]');
    if (w) { frameW = Number(w.dataset.sapW); render(); return; }

    var loc = t.closest('[data-sap-loc]');
    if (loc) {
      /* The wording is a SERVER decision — every string in both partials goes
         through __() — so this one really does re-render. */
      pvLocale = loc.dataset.sapLoc;
      render();
      refreshPreview();
      return;
    }

    var reset = t.closest('[data-sap-reset]');
    if (reset) {
      draft[reset.dataset.sapReset] = defaults[reset.dataset.sapReset];
      renderControls();
      if (isFast(reset.dataset.sapReset)) { pushOverlay(); } else { schedulePreview(); }
      return;
    }

    var groupReset = t.closest('[data-sap-groupreset]');
    if (groupReset) {
      var target = null;
      GROUPS.forEach(function (x) { if (x.id === groupReset.dataset.sapGroupreset) target = x; });
      if (!target) return;

      var keys = keysOfGroup(target).filter(function (k) { return !isShipped(k); });
      if (!keys.length) return;

      if (!window.confirm('Put ' + keys.length + ' setting(s) in “' + target.label
        + '” back to the value the page shipped with?\n\nNothing is saved until you press Save.')) return;

      keys.forEach(function (k) { draft[k] = defaults[k]; });
      renderControls();
      pushOverlay();
      if (keys.some(function (k) { return !isFast(k); })) schedulePreview();
      return;
    }

    if (t.closest('[data-sap-save]')) { save(); return; }

    if (t.closest('[data-sap-discard]')) {
      if (!window.confirm('Put back ' + changed().length + ' change(s)?')) return;
      startDraft();
      renderControls();
      pushOverlay();
      schedulePreview();
      return;
    }

    if (t.closest('[data-sap-reload]')) { load(); }
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!onScreen(el)) return;

    var swatch = el.closest && el.closest('[data-sap-swatch]');
    if (swatch) {
      draft[swatch.dataset.sapSwatch] = swatch.value;
      var box = document.querySelector('[data-sap-key="' + swatch.dataset.sapSwatch + '"]');
      if (box) box.value = swatch.value;
      refreshBar();
      if (isFast(swatch.dataset.sapSwatch)) { pushOverlay(); } else { schedulePreview(); }
      return;
    }

    var f = el.closest && el.closest('[data-sap-key]');
    if (!f) return;

    var key = f.dataset.sapKey;

    /*
     * BRANCH ON THE FIELD'S OWN TYPE AND NOT ON THE ELEMENT'S. The Checkout page
     * screen shipped three selects that fell through to a range branch and saved
     * as NaN, because the handler read Number(el.value) for everything. Here a
     * checkbox reads .checked, a colour reads .value as a STRING (it may legally
     * be empty, and Number('') is 0, which would store black), and only a range
     * is a number.
     */
    if (f.type === 'checkbox') {
      draft[key] = f.checked;
    } else if (f.type === 'range') {
      draft[key] = Number(f.value);
      var out = document.querySelector('[data-sap-val="' + key + '"]');
      if (out) {
        var field = fieldOf(key);
        if (field) out.textContent = shown(field);
      }
    } else {
      draft[key] = f.value;
    }

    /*
     * ── THE COUNT IS UPDATED IN PLACE, AND THAT IS A FIX RATHER THAN A
     *    REFINEMENT ─────────────────────────────────────────────────────────
     *
     * This used to re-render only when the DIRTINESS FLIPPED, on the argument
     * that a redraw per pixel of a drag would take the focus off the slider the
     * owner is holding. The first half of that is right and the second half was
     * a bug: after the FIRST change the screen is already dirty, so nothing
     * flipped again and the bar kept saying "1 unsaved change" however many
     * more controls were moved. Measured in Chromium — five controls moved, bar
     * reads 1 — which is exactly the silence the count exists to remove, since
     * a count is what tells him whether the thing he just changed registered.
     *
     * So: the numbers are written straight into the bar on every input, and the
     * full redraw waits for `change`, which a slider fires when it is RELEASED.
     * Dragging gives live numbers and keeps the thumb under the pointer; letting
     * go brings the per-field "shipped" buttons up to date.
     */
    refreshBar();

    /*
     * AND THE PREVIEW IS MOVED HERE, ON EVERY PIXEL, FOR 160 OF THE 198.
     * pushOverlay() is a string join and a postMessage — no request, no
     * document swap, and the popup the owner may have open in the frame stays
     * open. Only the structural controls fall through to the debounce.
     */
    if (isFast(key)) { pushOverlay(); } else { schedulePreview(); }
  });

  /* A slider fires `change` on release and a checkbox and a colour box fire it
     immediately, which is when a full redraw is both affordable and wanted. */
  document.addEventListener('change', function (e) {
    if (!onScreen(e.target)) return;
    if (!(e.target.closest && e.target.closest('[data-sap-key]'))) return;

    /* renderControls() AND NOT render(): `change` fires on a slider's RELEASE,
       and a full render replaces the iframe with the last document the server
       sent — which closes a popup the owner opened in order to size it, and
       throws away the live overlay he has just been dragging. Measured: the
       popup shut on every mouseup. The preview column is already correct; only
       the per-field “shipped” buttons and the counts need redrawing. */
    renderControls();
    pushOverlay();
  });

  function refreshBar() {
    var bar = document.querySelector('.sap-bar');
    var count = document.querySelector('.sap-count');
    if (!bar || !count) return;

    var n = changed().length;
    var moved = movedFromShipped().length;

    bar.classList.toggle('is-dirty', n > 0);
    count.textContent = (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes')
      + ' · ' + (moved ? moved + ' setting' + (moved === 1 ? '' : 's') + ' moved from shipped'
                            : 'everything is at the value the page shipped with');

    var save = document.querySelector('[data-sap-save]');
    var discard = document.querySelector('[data-sap-discard]');
    if (save) save.disabled = n === 0;
    if (discard) discard.disabled = n === 0;
  }

  /* Closing the tab or refreshing asks nothing: the draft is already in
     Unfinished, written as it was typed. (Lane PM) */

  addNavEntry();
})();
</script>
@endverbatim
