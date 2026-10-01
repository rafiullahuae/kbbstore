{{--
    Appearance → Cart panel. (Lane: cart-panel)

    ── WHY THIS IS A FILE OF ITS OWN ────────────────────────────────────────

    renderCartPanel() and a `cpp-` style block were lines inside
    resources/views/admin/app.blade.php. They are here for the reason
    cart-page-screen.blade.php and checkout-page-screen.blade.php state: three
    lanes edit that file at once, and a 22,000-line Blade is where their
    conflicts happen. The cost is the same one those two pay — this file cannot
    reach app.blade.php's module-scoped constants — so it appends its own sidebar
    row through window.kbbAddNavEntry and WRAPS window.go rather than being named
    in the console's dispatcher.

    Pulled in at the very end of app.blade.php, after that file closes its raw
    block, so window.go, window.kbbAddNavEntry and toast() already exist by the
    time this runs. THE INCLUDE IS THE INTEGRATOR'S LINE, not this lane's. Until
    he adds it the console keeps drawing its own dead copy; from the moment he
    does, the wrapper below handles 'cartpanel' and returns without calling the
    handler it replaced, so that copy is unreachable and is his to delete.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "on mobile cart panel, i need to squeez the rows, spacing, font sizes,
    quantity button size, cross icon size, padding, and cart checkout buttons
    style etc. i need all those controls on backend on Appearance > Cart Panel >
    Desktop / Mobile, the same way you did for checkout page, along with live
    previews. don't touch the checkout page at all."

    So the tabs are DEVICES, not categories, and every value on the Mobile tab is
    stored separately from its desktop twin. That was the complaint: row padding,
    name size, stepper size and list padding were ONE number shared by a 380px
    desktop panel and a 77vw phone, so squeezing the phone squeezed the desktop.

    Content, Behaviour, Wording and Colour are device-independent, were not what
    was asked for, and neither this screen nor this round changes a value on them.

    ── AN INLINE style ATTRIBUTE BEATS EVERY MEDIA QUERY ────────────────────

    The one fact this screen cannot be read without. partials/drawers.blade.php
    renders the panel as

        <aside class="drawer ..." style="... CartPanel::cssVariables() ...">

    so every custom property arrives in a STYLE ATTRIBUTE, which outranks every
    rule in every media query. A Mobile tab that wrote --cp-rowpad and expected
    the phone block to override it would SAVE AND MOVE NOTHING, and would read as
    a broken save rather than as the specificity problem it is.

    cssVariables() therefore emits --cp-rowpad AND --cp-rowpad-m side by side and
    kbb.css chooses between them inside its own media query — which is what
    panel_width / panel_width_m have always done. CartPanelDeviceSetsTest proves
    each pair, with the mutation that makes it red.

    ── TWO BREAKPOINTS, STATED ON THE SCREEN ────────────────────────────────

    The shop reads the phone values at two widths and always has: 680px for the
    panel's width, padding, rows, type and footer buttons, and 900px for the four
    TAP TARGETS. The Mobile tab says so under its heading and both numbers come
    from the endpoint rather than being written here twice.

    ── THE FOUR 44px VALUES ─────────────────────────────────────────────────

    The per-line ✕, the panel's close button, the footer buttons and the tab
    strip are TOUCH TARGETS, not spacing. 44px is the smallest box a finger hits
    reliably and kbb.css's 900px blocks exist solely to raise them to it.

    The owner asked to squeeze exactly these. So: the default stays 44, because
    that is what the shop renders today; the slider GOES BELOW IT, because he
    asked and it is his shop; and it does not clamp — a slider that stops where
    nobody asked it to stop reads as a bug and gets reported as one. What happens
    instead is one warm line under the slider saying what the cost is, at the
    point of the decision.

    ── WHERE EACH PREVIEW SITS ──────────────────────────────────────────────

    Mobile tab: the preview to the RIGHT of the controls, sticky, folding to one
    column below 1180px — "in all mobile tabs ... i want the preview on the right
    side, only in the mobile tabs." Desktop tab: BELOW the controls, full width,
    headed Preview. A 380px panel drawn in a 372px rail is the same drawing at
    the wrong scale beside controls too narrow to read.

    Both are DRAWINGS, and both redraw on INPUT rather than on save. The real
    panel needs a basket to render and would cost an authenticated fetch per
    keystroke. Every measurement in them is a custom property named for the shop
    property it stands for, so the drawing and the panel cannot disagree about
    what a number means, and the numbers themselves are printed on the ruler
    under each mock — the complaint that started this screen was about sizes, and
    an impression cannot answer one.

    NOTHING HERE MEASURES LAYOUT. No ResizeObserver, no getBoundingClientRect, no
    window.innerWidth: the panel sizes with calc() and custom properties and two
    tests forbid those APIs by name.

    ── NO RAW-BLOCK DIRECTIVE MAY BE NAMED BELOW ────────────────────────────

    Not in the code and not in prose either. Blade pairs the first such opening
    directive it finds anywhere in the file — inside a comment included — with
    the next closing one, so writing the word swallows everything between them
    and serves this docblock to the browser as visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED cpp- OR cpv- AND APPEARS NOWHERE ELSE IN THE
    CONSOLE, and so is every data- attribute anything clicks: app.blade.php binds
    delegated listeners to `document` itself, each claiming a bare attribute
    name, and a click on any element carrying one is handled by that listener
    whichever screen it belongs to.
--}}
@verbatim
<style>
/* Controls, then the preview under them. */
.cpp-wrap{display:grid;gap:14px;min-width:0}
.cpp-wrap > *{min-width:0}

/* ── THE MOBILE TAB PUTS THE PREVIEW BESIDE THE CONTROLS ──────────────────
   Only the mobile one, and that is not a preference. The phone mock is a
   narrow frame and sits happily in a side column; the desktop panel stands for
   380px of real width and drawn inside a 372px rail it is the same drawing at
   the wrong scale, next to controls too narrow to read. Below 1180px it folds
   back to one column, for the reason the checkout screen folds at the same
   width: a 300px control column beside a phone is two things nobody can use. */
.cpp-wrap.cpp-side{grid-template-columns:minmax(0,1fr) 372px;align-items:start}
.cpp-wrap.cpp-side > .cpp-col{display:grid;gap:14px;min-width:0}
.cpp-wrap.cpp-side [data-cpp-preview]{position:sticky;top:16px}
@media (max-width:1180px){
  .cpp-wrap.cpp-side{grid-template-columns:minmax(0,1fr)}
  .cpp-wrap.cpp-side [data-cpp-preview]{position:static}
}

.cpp-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.cpp-title{font-weight:650;font-size:15px}
.cpp-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.cpp-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.cpp-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cpp-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cpp-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.cpp-f{display:grid;gap:5px;min-width:0}
.cpp-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.cpp-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.cpp-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
         font-variant-numeric:tabular-nums}
.cpp-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.cpp-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.cpp-f select.cpp-sel,.cpp-f input.cpp-text{
  width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
  padding:7px 9px;border:1px solid var(--line,#e5e7eb);border-radius:7px;
  background:var(--card,#fff);color:inherit
}
.cpp-f select.cpp-sel:focus-visible,.cpp-f input.cpp-text:focus-visible{
  outline:2px solid var(--accent,#15a85a);outline-offset:1px;border-color:transparent
}
.cpp-f input.cpp-text::placeholder{color:var(--ink-soft,#6b7280);opacity:.7}
.cpp-swatch{display:flex;align-items:center;gap:9px;min-width:0}
.cpp-swatch input[type=color]{flex:none;width:44px;height:30px;padding:0;border:1px solid var(--line,#e5e7eb);
  border-radius:7px;background:var(--card,#fff);cursor:pointer}
.cpp-swatch code{font-size:11.5px;color:var(--ink-soft,#6b7280);font-variant-numeric:tabular-nums}
.cpp-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.cpp-check input{margin-top:3px;flex:none;width:16px;height:16px}
.cpp-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.cpp-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cpp-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cpp-btn[disabled]{opacity:.45;cursor:default}
.cpp-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.cpp-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
@media (max-width:640px){ .cpp-card{padding:13px} }

/* ── THE TOUCH-TARGET WARNING ─────────────────────────────────────────────
   Four values on the Mobile tab are not spacing, they are TAP TARGETS: the
   per-line ✕, the panel's close button, the two footer buttons and the tab
   strip. 44px is the smallest box a finger hits reliably and the shop's
   @media(max-width:900px) block exists solely to raise them to it.

   The owner asked to squeeze exactly these, so the slider goes below 44 — it is
   his shop. It does NOT clamp: a slider that stops where nobody asked it to
   stop reads as a bug and gets reported as one. What happens instead is this
   line, which turns warm and says what the cost is, at the point of the
   decision rather than in a dialog. */
.cpp-warn{font-size:11.5px;line-height:1.5;margin:0;max-width:68ch;
          color:#8a5a12;background:#fff8e6;border:1px solid #f0dfb0;border-radius:8px;padding:7px 9px}

/* ── the preview ────────────────────────────────────────────────────────── */
.cpv-h{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:4px 0 10px}
.cpv-h b{font-size:15px;font-weight:700}
.cpv-h span{font-size:11px;color:var(--ink-soft,#6b7280)}

/* THE FRAME IS THE PAGE THE PANEL SLIDES OVER, so the panel's share of the
   screen — which is the whole of what panel_width_m sets — is something the
   owner can see rather than a number they have to imagine. */
.cpv-frame{position:relative;border:1px solid var(--border,#e6e6e6);border-radius:12px;overflow:hidden;
  background:#fbf5f4;box-shadow:0 8px 26px -18px rgba(0,0,0,.4)}
.cpv-frame.is-phone{width:320px;max-width:100%;margin:0 auto;border-radius:20px}
.cpv-bar{display:flex;align-items:center;gap:6px;padding:7px 11px;background:#f6f7f9;
  border-bottom:1px solid var(--border,#e6e6e6);font-size:10px;color:#6b7280}
.cpv-bar i{width:6px;height:6px;border-radius:50%;background:#d8dbe0;display:block;flex:none}
.cpv-bar span{margin-inline-start:auto;white-space:nowrap}
.cpv-stage{position:relative;display:flex;justify-content:flex-end;min-height:var(--cpv-stage,300px);
  background:repeating-linear-gradient(135deg,#fff,#fff 9px,#fdf7f9 9px,#fdf7f9 18px)}

/* The panel itself. Every measurement below is a custom property this screen
   writes, named for the storefront property it stands for, so the drawing and
   the shop cannot disagree about what a number means. */
.cpv-panel{width:var(--cpv-w,62%);background:#fff;border-inline-start:1px solid #e6e9ef;
  display:flex;flex-direction:column;min-width:0;box-shadow:-10px 0 28px -22px rgba(60,20,40,.5)}
.cpv-tabs{display:flex;align-items:center;border-bottom:1px solid #eef1f6;padding:0 var(--cpv-tabpad,10px);flex:none}
.cpv-tabs b,.cpv-tabs i{flex:1;text-align:center;font-size:var(--cpv-tabf,11px);font-weight:700;
  padding:0 4px;min-height:var(--cpv-tabh,30px);display:flex;align-items:center;justify-content:center;gap:5px;
  border-bottom:2px solid transparent;margin-bottom:-1px;font-style:normal}
.cpv-tabs b{font-weight:800}
.cpv-tabs i{color:#9aa3b0}
.cpv-tabs em{font-style:normal;background:#f7dbe5;border-radius:10px;padding:0 6px;font-size:9px}
/* The panel's own close button — .kc-x on the shop. A BOX and a GLYPH, set
   apart, because the shop sets them apart: 28x28 with a 13px ✕ on the desktop
   and 44x44 on a phone. */
.cpv-x{flex:none;display:grid;place-items:center;background:#f4eef1;border-radius:8px;color:#5c6675;
  width:var(--cpv-x,28px);height:var(--cpv-x,28px);font-size:var(--cpv-xg,13px);
  margin-inline-start:6px;align-self:center}
.cpv-ship{padding:8px var(--cpv-pad,16px);border-bottom:1px solid #eef1f6;font-size:10.5px;color:#5c6675}
.cpv-bar2{height:5px;border-radius:5px;background:#f0e2e8;overflow:hidden;margin-top:5px}
.cpv-bar2 div{height:100%;width:100%}
/* SCROLLS, because the panel it is drawing scrolls: .dbody is
   `flex:1;overflow:auto;min-height:0` on the shop and the list is taller than
   any box this preview can be given. Clipping it instead drew half a product
   line under the promotion strip, which reads as a fault in the panel rather
   than as a list with more in it. */
.cpv-body{padding:6px var(--cpv-pad,16px);flex:1;overflow:auto;min-height:0}
.cpv-item{display:flex;gap:8px;align-items:center;padding:var(--cpv-rowpad,9px) 0;border-bottom:1px solid #f2f4f8}
.cpv-item:last-child{border-bottom:0}
.cpv-th{width:var(--cpv-thumb,42px);height:var(--cpv-thumb,42px);border-radius:8px;flex:none;
  display:grid;place-items:center;color:#fff;font-weight:700;font-size:9px}
.cpv-mid{flex:1;min-width:0}
.cpv-nm{font-size:var(--cpv-nm,13px);font-weight:600;line-height:1.25;margin-bottom:4px;
  display:-webkit-box;-webkit-line-clamp:var(--cpv-lines,2);-webkit-box-orient:vertical;overflow:hidden}
/* The stepper takes its measurement on the BOX AND THE NUMBER BETWEEN THEM,
   which is what kbb.css does: --cp-step sizes .kc-qty button and the min-width
   of the span, so one slider moves three boxes. Scaling one of the three would
   draw a control the shop never renders. */
.cpv-qty{display:inline-flex;align-items:center;border:1px solid #e6e9ef;border-radius:7px;overflow:hidden}
.cpv-qty span,.cpv-qty b{width:var(--cpv-step,22px);height:var(--cpv-step,22px);display:grid;place-items:center;
  font-size:10.5px;font-weight:600}
.cpv-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex:none}
/* The per-line ✕ — .kc-rm. Its GLYPH is a font size on both surfaces; its BOX
   exists only on a phone, where the shop gives it 44x44. */
.cpv-rm{color:#c3b3bb;font-size:var(--cpv-rm,13px);line-height:1;display:grid;place-items:center;
  width:var(--cpv-rmbox,auto);height:var(--cpv-rmbox,auto)}
.cpv-pr{font-size:var(--cpv-prf,12.5px);font-weight:800;color:var(--cpv-acc,#C13E63)}
.cpv-promo{padding:7px var(--cpv-pad,16px);background:#fff0f4;font-size:10px;color:#5e545a}
.cpv-foot{border-top:1px solid #eef1f6;padding:10px var(--cpv-pad,16px);flex:none}
.cpv-sum{display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:8px}
.cpv-btns{display:grid;grid-template-columns:1fr 1fr;gap:var(--cpv-btngap,8px)}
.cpv-btns.is-stack{grid-template-columns:1fr}
.cpv-btns a{display:flex;align-items:center;justify-content:center;text-align:center;
  padding:var(--cpv-btnpad,12px) 8px;min-height:var(--cpv-btnh,0px);
  border-radius:var(--cpv-btnr,99px);font-size:var(--cpv-btnf,13.5px);font-weight:700;
  border:1px solid #e6e9ef;color:#1d2430;white-space:nowrap}
/* The measured numbers, stated on the frame. A number on the drawing is the
   quickest way for the owner to check that what they are looking at is what
   they set — the complaint that started this screen was about sizes, and an
   impression cannot answer it. */
.cpv-ruler{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 8px;
  padding:7px 11px;margin-top:8px;background:#f6f7f9;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font-size:11px;color:#6b7280;text-align:center}
.cpv-ruler b{font-weight:700;color:var(--ink,#16181d);font-variant-numeric:tabular-nums}
.cpv-ruler s{text-decoration:none;color:#b4443c;font-weight:700}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'cartpanel';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Cart panel',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  /* ---------------------------------------------------------------- state */
  var tabs = null;        // GET /admin-api/cart-panel -> tabs
  var values = {};        // key -> current value, edited in place
  var touch = [];         // the keys that are tap targets
  var touchMin = 44;
  var phoneMax = 680;     // where the phone's spacing takes effect
  var tapMax = 900;       // where the phone's tap targets take effect
  var open = null;        // which tab is showing
  var banner = null;
  var busy = false;
  var seq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
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

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  /* A 404 here almost always means the compiled route table does not know the
     path yet, which is a cleared cache away rather than a broken screen. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Cart panel endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  /* The console carries a built-in `cartpanel` row, so this call finds it and
     returns it — kbbAddNavEntry is keyed on the screen id and a second call
     adds nothing. It is here so that the row survives the integrator deleting
     the dead copy this file replaced, NAV entry and all. */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart panel',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>',
      group: 'Appearance',
      after: ['dividers', 'mobilehdr', 'prodstyles']
    });
  }

  /* ------------------------------------------------------------ the route */
  /* WRAPPED, NOT REPLACED. Nine partials do this, and one that forgot to call
     the handler it replaced would black out every screen registered before it —
     which is every screen app.blade.php draws itself. */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Cart panel';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load() {
    var mine = ++seq;
    busy = true;
    banner = null;
    render();

    try {
      var body = await api('/cart-panel');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      touch = body.touch || [];
      touchMin = body.touchMin || 44;
      phoneMax = body.phoneMax || 680;
      tapMax = body.tapMax || 900;
      values = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { values[f.key] = f.value; });
      });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Cart panel settings could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save() {
    if (busy) return;
    busy = true;
    render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      await api('/cart-panel', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say('Cart panel saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  /* ------------------------------------------------------------- controls */
  function shown(f) {
    var o = f.options || {};
    return String(values[f.key]) + (o.unit || '');
  }

  /** The schema row for a key, across every tab. */
  function fieldFor(key) {
    var out = null;

    (tabs || []).forEach(function (t) {
      t.fields.forEach(function (f) { if (f.key === key) out = f; });
    });

    return out;
  }

  /* `name_lines` cuts a name off, so its help line is only true while a name is
     drawn at all — and nothing else on either device tab depends on a switch.
     Kept as a function rather than inlined so a later dependency has one place
     to go, the way the checkout screen's does. */
  function hidden(f) {
    return false;
  }

  /*
   * THE TAP-TARGET WARNING, AND WHY IT IS NOT A CLAMP.
   *
   * Four Mobile values are touch targets rather than spacing. The owner asked to
   * squeeze exactly those, so the slider reaches below 44 and this is what
   * happens there instead: one warm line, under the slider, at the moment the
   * number goes below it.
   *
   * Not a blocking dialog, not a refusal, and above all NOT a silent clamp — a
   * slider that stops where the owner did not ask it to stop reads as a bug and
   * he reports it as one.
   */
  function warnHTML(f) {
    if (touch.indexOf(f.key) === -1) return '';
    if (Number(values[f.key]) >= touchMin) return '';

    return '<p class="cpp-warn">Below ' + touchMin + 'px a finger misses this more often. '
      + touchMin + 'px is the smallest box that is reliable on a phone — this is '
      + esc(String(values[f.key])) + 'px. Saved as it is; nothing here stops you.</p>';
  }

  function fieldHTML(f) {
    if (hidden(f)) return '';

    var id = 'cpp-' + f.key;
    var help = f.help ? '<p class="cpp-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="cpp-f"><div class="cpp-check">'
        + '<input type="checkbox" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    /*
     * A SELECT IS A SELECT AND NOT A SLIDER. Three selects shipped on the
     * checkout screen rendering as `<input type="range" min="undefined">` showing
     * a value a range cannot represent, and saving NaN, because the branch read
     * the DOM element's type instead of the field's. This branches on the
     * field's own type and so does the input handler.
     */
    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '') + '>'
          + esc(f.options[k]) + '</option>';
      }).join('');

      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select class="cpp-sel" id="' + id + '" data-cpp-key="' + esc(f.key) + '">' + opts + '</select>'
        + help + '</div>';
    }

    if (f.type === 'text') {
      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<input class="cpp-text" type="text" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + ' value="' + esc(values[f.key] == null ? '' : values[f.key]) + '"'
        + ' placeholder="' + esc(f['default'] == null ? '' : f['default']) + '">'
        + help + '</div>';
    }

    /* A COLOUR IS A COLOUR PICKER, and the hex beside it is printed as TEXT
       rather than interpolated into a style attribute. The value is repaired to
       a `#rrggbb` on the way in by CartPanel's `hex => repair` policy, but this
       screen never has to know that to be safe. */
    if (f.type === 'colour') {
      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<span class="cpp-swatch">'
        + '<input type="color" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + ' value="' + esc(values[f.key]) + '">'
        + '<code data-cpp-val="' + esc(f.key) + '">' + esc(values[f.key]) + '</code>'
        + '</span>' + help + '</div>';
    }

    var o = f.options || {};
    return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
      + '<span class="cpp-val" data-cpp-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
      + '<input type="range" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
      + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
      + help
      + '<span data-cpp-warn="' + esc(f.key) + '">' + warnHTML(f) + '</span>'
      + '</div>';
  }

  /* ---------------------------------------------------------------- notes */
  function noteHTML() {
    if (open === 'desktop') {
      return '<div class="cpp-note">Wider than <b>' + phoneMax + 'px</b>. Every value here has a twin on '
        + 'the <b>Mobile</b> tab, so squeezing the phone no longer squeezes this — which it used to, '
        + 'because row padding, name size, quantity buttons and list padding were one number shared by '
        + 'both.</div>';
    }

    if (open === 'mobile') {
      return '<div class="cpp-note">Width, padding, rows and type take effect at <b>' + phoneMax
        + 'px</b> and below. The four tap targets — the line ✕, the close button, the tab strip '
        + 'and the footer buttons — take effect at <b>' + tapMax + 'px</b> and below, which is where '
        + 'the panel raises them today. Both widths are the shop’s own; nothing here moved them.</div>';
    }

    return '<div class="cpp-note">The same on a laptop and on a phone. Nothing on this tab is '
      + 'device-specific, so it is left off <b>Desktop</b> and <b>Mobile</b> rather than asked twice.</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Cart panel') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="cpp-wrap"><div class="cpp-card"><div class="cpp-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="cpp-wrap"><div class="cpp-card">'
        + '<div class="cpp-title">Cart panel</div>'
        + '<p class="cpp-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="cpp-actions"><button class="cpp-btn" data-cpp-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="cpp-tab" data-cpp-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    /* Two columns on the MOBILE tab only. Matched on the prefix rather than
       listed, so a phone tab added later gets the side-by-side layout its own
       name claims instead of silently falling through to the full-width one. */
    var side = /^mobile/.test(String(open));

    host.innerHTML = '<div class="cpp-wrap' + (side ? ' cpp-side' : '') + '">'
      + (side ? '<div class="cpp-col">' : '')
      + (banner ? '<div class="cpp-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + noteHTML()
      + '<div class="cpp-card">'
      + '<div class="cpp-tabs">' + strip + '</div>'
      + '<p class="cpp-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="cpp-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="cpp-actions">'
      + '<button class="cpp-btn is-primary" data-cpp-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="cpp-btn" data-cpp-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      /* THE PRESETS WRITE THE SLIDERS AND NOTHING ELSE, and only the ones on the
         tab being looked at. The owner's report on the checkout screen's version
         of this button was "when i click squeezed, it applies on all tabs ...
         which is not correct": a preset that reaches past the screen changes
         numbers nobody can see, so the only way to learn what it did is to visit
         every tab. Nothing is stored until Save, and Reload undoes both. */
      + '<button class="cpp-btn" data-cpp-squeeze' + (busy ? ' disabled' : '') + '>Squeeze this tab</button>'
      + '<button class="cpp-btn" data-cpp-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="cpp-help" style="margin-top:9px">Both presets move only the sliders on '
      + '<b>this tab</b> — the other tabs are left exactly as you set them. Nothing is stored until '
      + 'you press Save, and Reload puts them back.</p>'
      + '</div>'
      + (side ? '</div>' : '')
      + previewHTML()
      + '</div>';
  }

  /* --------------------------------------------------------------- presets */
  /*
   * `min` drives every range on the open tab to the bottom of its own scale;
   * `default` puts every field on it back to the value the schema ships. The two
   * are deliberately not symmetrical: squeezing is a look, applied to the
   * controls that make a panel denser, while "back to defaults" has to be able
   * to undo anything at all or it is not a way out.
   *
   * Squeeze reaches the four tap targets too, and that is on purpose: their
   * minimum is below 44 because the owner asked for it, and a preset that
   * silently skipped them would be the clamp this screen refuses to have, moved
   * somewhere harder to see. The warning lines appear under all four when it
   * does, which is exactly the point at which he can decide.
   */
  function preset(which) {
    if (!tabs) return;

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    current.fields.forEach(function (f) {
      if (which === 'default') { values[f.key] = f['default']; return; }
      if (f.type !== 'range') return;
      values[f.key] = Number((f.options || {}).min);
    });

    render();
    say(which === 'min'
      ? 'Squeezed — ' + current.label + ' only. Nothing is saved until you press Save.'
      : current.label + ' is back to its shipped values. Nothing is saved until you press Save.');
  }

  /* -------------------------------------------------------------- preview */
  function pvNum(key, fallback) {
    var v = Number(values[key]);
    return isFinite(v) ? v : fallback;
  }

  /* The Content tab's switches, read the way the storefront reads them: absent
     means on, because that is what a fresh shop renders. */
  function pvOn(key) { return values[key] !== false; }

  /*
   * THE PHONE MOCK IS A 320px FRAME STANDING FOR A 390px SCREEN, so every stored
   * pixel is drawn at this share of itself and a 9px row reads here at the size
   * it reads on the owner's own phone. One decimal: the point of a preview is
   * proportion, and 0.05px is not a difference anybody can see.
   *
   * The desktop mock is drawn 1:1 instead — a laptop panel is 380px and the card
   * it sits in is wider than that on anything but a phone, so there is nothing to
   * scale and scaling would only make the numbers on the ruler untrue. On a 390px
   * admin the panel's own max-width:100% takes over, which is why the ruler says
   * what the real width is rather than leaving it to be measured off the screen.
   */
  var PHONE = 320, PHONE_REAL = 390;
  function pvPx(key, fallback) {
    return (pvNum(key, fallback) * (PHONE / PHONE_REAL)).toFixed(1) + 'px';
  }
  function pvOwn(key, fallback) { return pvNum(key, fallback) + 'px'; }

  /* A percentage as the factor the stylesheet multiplies by — the same
     arithmetic CartPanel::cssVariables() does, so the drawing and the panel
     cannot disagree about what 115% means. */
  function pvFactor(key) { return (pvNum(key, 100) / 100).toFixed(3); }

  /* Four lines, the last of them long enough to reach the line clamp, which is
     the state the owner has to be able to see. */
  var PV_ITEMS = [
    {i: 'I', n: 'Age-R Booster Pro Device', p: '80', g: 'linear-gradient(140deg,#F6C6A0,#E89B6C)'},
    {i: 'RL', n: 'Hyaluronic Acid Watery Sun Gel that runs to a second line', p: '133', g: 'linear-gradient(140deg,#F4A6B8,#E0567B)'},
    {i: 'M', n: 'Cellmazing Fit Serum', p: '299', g: 'linear-gradient(140deg,#A8D0F0,#5F9BD4)'},
    {i: 'S', n: 'Ceramide Daily Moisturiser', p: '329', g: 'linear-gradient(140deg,#C4B5F0,#8B6FD4)'}
  ];

  function pvLines() {
    return PV_ITEMS.map(function (it) {
      return '<div class="cpv-item">'
        + (pvOn('show_thumb') ? '<div class="cpv-th" style="background:' + esc(it.g) + '">' + esc(it.i) + '</div>' : '')
        + '<div class="cpv-mid"><div class="cpv-nm">' + esc(it.n) + '</div>'
        + (pvOn('show_qty') ? '<div class="cpv-qty"><span>&minus;</span><b>2</b><span>+</span></div>' : '')
        + '</div>'
        + '<div class="cpv-right">'
        + (pvOn('show_remove') ? '<span class="cpv-rm">✕</span>' : '')
        + (pvOn('show_price') ? '<div class="cpv-pr">' + esc(it.p) + ' د.إ</div>' : '')
        + '</div></div>';
    }).join('');
  }

  /* The panel itself, drawn once and used by both mocks. `px` is the scaler the
     surface uses — 1:1 on the desktop mock, the phone frame's 320/390 on the
     other — and `k` decorates a key name with the surface's suffix, so ONE
     function draws both and neither can drift from the other. */
  function pvPanel(k, px, vars, isPhone) {
    return '<div class="cpv-panel" style="' + vars
      + ';--cpv-pad:' + px(k('list_pad'), 16)
      + ';--cpv-rowpad:' + px(k('row_pad'), 9)
      + ';--cpv-thumb:' + px(k('thumb_size'), 42)
      + ';--cpv-nm:' + px(k('name_size'), 13)
      + ';--cpv-lines:' + pvNum(k('name_lines'), 2)
      + ';--cpv-step:' + px(k('stepper_size'), 22)
      + ';--cpv-rm:' + px(k('rm_size'), 13)
      + ';--cpv-btngap:' + px(k('btn_gap'), 8)
      + ';--cpv-btnpad:' + px(k('btn_pad'), 12)
      + ';--cpv-btnr:' + px(k('btn_radius'), 99)
      + ';--cpv-acc:' + esc(String(values.accent || '#C13E63'))
      + '">'
      + '<div class="cpv-tabs">'
      + '<b style="color:' + esc(String(values.accent || '#C13E63')) + ';border-bottom-color:'
      + esc(String(values.accent || '#C13E63')) + '">' + esc(String(values.txt_tab_cart || 'Cart'))
      + '<em>4</em></b>'
      + (pvOn('show_browsed') ? '<i>' + esc(String(values.txt_tab_browsed || 'Browsed')) + '</i>' : '')
      + '<span class="cpv-x">✕</span>'
      + '</div>'
      + (pvOn('show_ship_bar')
          ? '<div class="cpv-ship">' + esc(String(values.txt_ship_done || ''))
            + '<div class="cpv-bar2"><div style="background:'
            + esc(String(values.accent || '#C13E63')) + '"></div></div></div>'
          : '')
      + '<div class="cpv-body">' + pvLines() + '</div>'
      + (pvOn('show_promo') ? '<div class="cpv-promo">🎁 Spend 199 for free delivery</div>' : '')
      + '<div class="cpv-foot">'
      + '<div class="cpv-sum"><span>' + esc(String(values.txt_subtotal || 'Subtotal')) + '</span><span>841 د.إ</span></div>'
      /* The stacked arrangement is a MOBILE control, so only the phone mock
         draws it — `.cp-btnstack .kc-btns` is inside the shop's own phone block
         and the desktop panel is unaffected by it. */
      + '<div class="cpv-btns' + (isPhone && String(values.btn_layout_m) === 'stack' ? ' is-stack' : '') + '">'
      + '<a>' + esc(String(values.txt_btn_cart || 'Cart')) + '</a>'
      + '<a style="background:' + esc(String(values.checkout_bg || '#C13E63'))
      + ';color:' + esc(String(values.checkout_fg || '#FFFFFF'))
      + ';border-color:' + esc(String(values.checkout_bg || '#C13E63')) + '">'
      + esc(String(values.txt_btn_checkout || 'Checkout')) + '</a>'
      + '</div></div></div>';
  }

  /* The ruler under a mock: the numbers the owner is actually setting, printed
     rather than left to be guessed at. A tap target under 44 is marked here as
     well as under its slider, because this is the picture he is looking at. */
  function pvTap(key, label) {
    var v = pvNum(key, 44);
    return label + ' <b>' + v + 'px</b>' + (v < touchMin ? ' <s>under ' + touchMin + '</s>' : '');
  }

  function previewDesktop() {
    var k = function (n) { return n; };
    var vars = '--cpv-w:' + pvNum('panel_width', 380) + 'px'
      + ';--cpv-x:' + pvOwn('x_size', 28)
      + ';--cpv-xg:' + pvOwn('x_glyph', 13)
      + ';--cpv-tabh:30px;--cpv-tabf:11px;--cpv-tabpad:10px'
      /* No box on the desktop ✕ — .kc-rm has none there either, it is a glyph in
         the flow. `auto` is what the rule falls back to. */
      + ';--cpv-rmbox:auto'
      + ';--cpv-prf:calc(12.5px * ' + pvFactor('price_size') + ')'
      + ';--cpv-btnf:calc(13.5px * ' + pvFactor('btn_size') + ')'
      + ';--cpv-btnh:0px';

    return '<div class="cpv-frame">'
      + '<div class="cpv-bar"><i></i><i></i><i></i><span>Wider than ' + phoneMax + 'px · drawn 1:1</span></div>'
      + '<div class="cpv-stage" style="--cpv-stage:360px">' + pvPanel(k, pvOwn, vars, false) + '</div>'
      + '</div>'
      + '<div class="cpv-ruler">Panel <b>' + pvNum('panel_width', 380) + 'px</b>'
      + '<span>·</span>list padding <b>' + pvNum('list_pad', 16) + 'px</b>'
      + '<span>·</span>row <b>' + pvNum('row_pad', 9) + 'px</b> above and below'
      + '<span>·</span>name <b>' + pvNum('name_size', 13) + 'px</b>'
      + '<span>·</span>price <b>' + (12.5 * pvNum('price_size', 100) / 100).toFixed(2) + 'px</b>'
      + '<span>·</span>stepper <b>' + pvNum('stepper_size', 22) + 'px</b>'
      + '<span>·</span>line ✕ <b>' + pvNum('rm_size', 13) + 'px</b>'
      + '<span>·</span>close <b>' + pvNum('x_size', 28) + 'px</b>'
      + '<span>·</span>button label <b>' + (13.5 * pvNum('btn_size', 100) / 100).toFixed(2) + 'px</b>'
      + '</div>';
  }

  function previewMobile() {
    var k = function (n) { return n + '_m'; };
    /* THE FRAME IS THE PHONE AND THE PANEL IS A SHARE OF IT, which is what
       panel_width_m sets — a percentage, not a pixel count, so it needs no
       scaling and the hatched page behind it is the part the shopper still sees. */
    var vars = '--cpv-w:' + pvNum('panel_width_m', 77) + '%'
      + ';--cpv-x:' + pvPx('x_size_m', 44)
      + ';--cpv-xg:' + pvPx('x_glyph_m', 13)
      + ';--cpv-tabh:' + pvPx('tab_h_m', 44)
      + ';--cpv-tabf:10px;--cpv-tabpad:5px'
      + ';--cpv-rmbox:' + pvPx('rm_tap_m', 44)
      + ';--cpv-prf:calc(12.5px * ' + pvFactor('price_size_m') + ' * ' + (PHONE / PHONE_REAL).toFixed(4) + ')'
      + ';--cpv-btnf:calc(12px * ' + pvFactor('btn_size_m') + ' * ' + (PHONE / PHONE_REAL).toFixed(4) + ')'
      + ';--cpv-btnh:' + pvPx('btn_h_m', 44);

    return '<div class="cpv-frame is-phone">'
      + '<div class="cpv-bar"><i></i><i></i><i></i><span>' + PHONE_REAL + 'px phone</span></div>'
      + '<div class="cpv-stage" style="--cpv-stage:340px">' + pvPanel(k, pvPx, vars, true) + '</div>'
      + '</div>'
      /* Outside the frame. Inside a 320px one this wrapped to four ragged rows
         and read as a layout fault in the thing it is measuring. */
      + '<div class="cpv-ruler">Drawn at <b>' + PHONE + 'px</b> for a <b>' + PHONE_REAL + 'px</b> phone'
      + '<span>·</span>panel <b>' + pvNum('panel_width_m', 77) + '%</b> = <b>'
      + Math.round(PHONE_REAL * pvNum('panel_width_m', 77) / 100) + 'px</b>'
      + '<span>·</span>list padding <b>' + pvNum('list_pad_m', 11) + 'px</b>'
      + '<span>·</span>row <b>' + pvNum('row_pad_m', 9) + 'px</b>'
      + '<span>·</span>name <b>' + pvNum('name_size_m', 13) + 'px</b>'
      + '<span>·</span>price <b>' + (12.5 * pvNum('price_size_m', 100) / 100).toFixed(2) + 'px</b>'
      + '<span>·</span>stepper <b>' + pvNum('stepper_size_m', 22) + 'px</b>'
      + '<span>·</span>line ✕ <b>' + pvNum('rm_size_m', 15) + 'px</b> in '
      + pvTap('rm_tap_m', 'a box of')
      + '<span>·</span>' + pvTap('x_size_m', 'close')
      + '<span>·</span>' + pvTap('tab_h_m', 'tab strip')
      + '<span>·</span>' + pvTap('btn_h_m', 'buttons')
      + '</div>';
  }

  function previewHTML() {
    var body = /^mobile/.test(String(open)) ? previewMobile() : previewDesktop();

    return '<div class="cpp-card" data-cpp-preview>'
      + '<div class="cpv-h"><b>Preview</b><span>Redraws as you drag. A drawing, not the live panel.</span></div>'
      + body + '</div>';
  }

  /* Repaint without rebuilding the controls — rebuilding them mid-drag drops the
     pointer capture and the slider stops following the finger. */
  function paintPreview() {
    var node = document.querySelector('[data-cpp-preview]');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-cpp-key]');
    if (!el) return;

    var key = el.getAttribute('data-cpp-key');
    var fld = fieldFor(key);
    var kind = fld ? fld.type : (el.type === 'checkbox' ? 'bool' : 'range');

    /* BY THE FIELD'S TYPE, NOT THE ELEMENT'S. `Number(el.value)` on a select
       stored NaN for every select on the checkout screen — see fieldHTML. */
    if (kind === 'bool') values[key] = el.checked;
    else if (kind === 'select' || kind === 'text' || kind === 'colour') values[key] = String(el.value);
    else values[key] = Number(el.value);

    /* A checkbox or a select can decide whether another control belongs on the
       screen, so both redraw rather than only repainting. A text field and a
       colour must NOT: a redraw on every keystroke would take the caret to the
       end of the line on the second character, and on every drag of a colour
       picker it would close the picker. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); return; }

    var out = document.querySelector('[data-cpp-val="' + key + '"]');
    if (out && fld) out.textContent = kind === 'colour' ? String(values[key]) : shown(fld);

    /* The tap-target line appears and disappears as the number crosses 44. Its
       own span, replaced in place, so the slider under the pointer is untouched. */
    var warn = document.querySelector('[data-cpp-warn="' + key + '"]');
    if (warn && fld) warn.innerHTML = warnHTML(fld);

    paintPreview();
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-cpp-tab]');
    if (tab) { open = tab.getAttribute('data-cpp-tab'); render(); return; }

    if (e.target.closest('[data-cpp-squeeze]')) { preset('min'); return; }
    if (e.target.closest('[data-cpp-defaults]')) { preset('default'); return; }
    if (e.target.closest('[data-cpp-save]')) { save(); return; }
    if (e.target.closest('[data-cpp-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
