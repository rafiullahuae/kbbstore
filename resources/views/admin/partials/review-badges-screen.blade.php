{{--
    Reviews - Badge Themes (Lane BE).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens this screen borrows.

    WHAT THIS SCREEN IS FOR. 'rev-badge' was an entry in REV_SRC pointing at
    kbb-admin-badgethemes.html, a standalone file this repo has never shipped,
    so the screen rendered the "isn't installed yet" card.

    A "THEME" HERE IS A NAMED SET OF VALUES FOR KEYS THAT ALREADY EXIST. There
    is no review_badge_theme row anywhere and there must not be: a preset that
    remembered itself would be a second source of truth about a colour the
    storefront reads from somewhere else. The active theme is worked out by
    comparing what is stored against the presets. See
    App\Support\ReviewBadgeSettings::THEMES.

    NO NAV ENTRY IS ADDED HERE: 'rev-badge' is already in app.blade.php's NAV
    const and in TITLES, and a second button would give the owner two. The id is
    also added to LIVE_RENDERED in app.blade.php, which is the other half of the
    takeover and is not optional -- without it every visit fires a HEAD request
    for a file that is not there.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Badge Themes. Every rule is prefixed rbt- and every id is rbt-, so this file
   can never restyle or collide with another screen in the console.

   The preview rules are a deliberate copy of the storefront's rather than a
   reuse of it: the console does not load the storefront stylesheet, so
   .sr-capbar here would be an unstyled row of spans.

   min-width:0 on the grid AND on its children is load-bearing: a grid item's
   default min-width is auto, so a card refuses to shrink below its widest child
   and drags the whole column past the viewport with no way to scroll back.
--------------------------------------------------------------------------- */
.rbt-wrap{display:grid;gap:16px;min-width:0;max-width:1400px}
.rbt-wrap > *{min-width:0}

.rbt-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rbt-legend{font-weight:650;font-size:14px;margin:0 0 3px}
.rbt-legend-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0 0 14px;max-width:80ch;line-height:1.5}
.rbt-title{font-weight:650;font-size:15px;margin:0}
.rbt-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:3px 0 0;max-width:80ch;line-height:1.5}

.rbt-row{display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;
         padding:11px 0;border-top:1px solid var(--border,#e6e6e6);min-width:0}
.rbt-row:first-of-type{border-top:0}
.rbt-row > *{min-width:0}
.rbt-lab{font-size:13.5px;font-weight:600;max-width:72ch}
.rbt-hint{color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px;line-height:1.45;max-width:72ch}
.rbt-ctl{display:flex;align-items:center;gap:8px;justify-self:end;flex-wrap:wrap}

@media (max-width:560px){
  .rbt-row{grid-template-columns:1fr}
  .rbt-ctl{justify-self:start}
}

.rbt-sw{position:relative;display:inline-block;width:42px;height:24px;flex:none}
.rbt-sw input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer}
.rbt-sw i{position:absolute;inset:0;border-radius:999px;background:rgba(127,127,127,.32);
          transition:background .16s ease;pointer-events:none;display:block}
.rbt-sw i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
                 background:#fff;transition:transform .16s ease;box-shadow:0 1px 3px rgba(0,0,0,.28)}
.rbt-sw input:checked + i{background:var(--accent,#15a85a)}
.rbt-sw input:checked + i::after{transform:translateX(18px)}
.rbt-sw input:focus-visible + i{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

.rbt-btn{appearance:none;border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;
         font:inherit;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer}
.rbt-btn[disabled]{opacity:.55;cursor:default}
.rbt-ghost{background:transparent;color:inherit;border-color:var(--border,#e6e6e6)}
.rbt-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.rbt-banner{border:1px solid #d9534f;border-radius:10px;padding:11px 13px;font-size:13px;line-height:1.5;
            color:#b3312c;background:rgba(217,83,79,.07)}
.rbt-banner.rbt-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);background:rgba(21,168,90,.07)}

.rbt-note{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;max-width:80ch}
.rbt-warn{border-left:3px solid #d9a13d;padding-left:11px;margin-top:10px}

/* The theme cards. auto-fit rather than a fixed column count, so they reflow to
   one column on a phone instead of overflowing. */
.rbt-themes{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;min-width:0}
.rbt-theme{border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:13px;min-width:0;
           display:grid;gap:8px;text-align:left;background:transparent;color:inherit;font:inherit;cursor:pointer}
.rbt-theme:hover{border-color:var(--accent,#15a85a)}
.rbt-theme.rbt-on{border-color:var(--accent,#15a85a);box-shadow:inset 0 0 0 1px var(--accent,#15a85a)}
.rbt-theme h4{margin:0;font-size:13.5px;font-weight:650;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.rbt-theme p{margin:0;color:var(--ink-soft,#6b7280);font-size:12px;line-height:1.45}
.rbt-badge{font-size:11px;font-weight:650;color:var(--accent,#15a85a);border:1px solid var(--accent,#15a85a);
           border-radius:999px;padding:1px 7px}

/* ---- the preview, in the shape the buy box draws it ---- */
.rbt-stage{border:1px dashed var(--border,#e6e6e6);border-radius:12px;padding:18px 16px;min-width:0;
           display:grid;gap:9px;justify-items:start;overflow-x:auto}
.rbt-prodname{font-weight:650;font-size:16px}
/* line-height is explicit so the preview is the shop's real height, not the
   admin console's. Without it this box inherited `normal` and drew 3px
   shorter than the badge the shopper actually sees — a preview that is close
   is a preview you cannot trust. ReviewBadgeParityTest compares it. */
.rbt-cap{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border,#e6e6e6);
         border-radius:999px;padding:5px 12px;font-size:13px;white-space:nowrap;line-height:1.4}
.rbt-heart{color:#e8607f}
.rbt-stars{letter-spacing:1px}
.rbt-avg{font-weight:650}
.rbt-count{color:var(--ink-soft,#6b7280)}
.rbt-inline{font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.rbt-sold{color:var(--accent,#15a85a);font-weight:600}
.rbt-price{font-size:22px;font-weight:700;margin-top:2px}
.rbt-empty{color:var(--ink-soft,#6b7280);font-size:12.5px;font-style:italic}

/* Wraps rather than clips. With white-space:nowrap and overflow:hidden the
   longest preset caption ("Loved by 126 shoppers") was cut off mid-word inside
   its card at 1280 — a theme picker whose swatch you cannot read is not a
   picker. Wrapping lets the card grow by a line instead. */
.rbt-mini{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--border,#e6e6e6);
          border-radius:14px;padding:3px 9px;font-size:11.5px;max-width:100%;flex-wrap:wrap;
          row-gap:2px;overflow-wrap:anywhere}

.rbt-colour{width:44px;height:32px;padding:0;border:1px solid var(--border,#e6e6e6);border-radius:9px;
            background:transparent;cursor:pointer}
.rbt-hex{width:104px;max-width:100%;padding:7px 9px;font:inherit;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
         border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;
         text-transform:uppercase}
</style>

<script>
/* =========================================================================
   Reviews -> Badge Themes (Lane BE)

   Two controls and five presets, and every one of them writes a key the
   storefront already consults — the inventory is in
   App\Support\ReviewBadgeSettings:

     review_badge_colour   product.blade.php   the star colour, capsule AND
                                               the inline line
     review_badge_sold     product.blade.php   the "12k+ sold" note, which only
                                               appears above 999 sales

   A PRESET IS NOT STORED. Applying one writes the six appearance keys and
   nothing else; which preset is active is worked out by comparing values. So
   changing a colour by hand correctly moves the screen to "Custom" rather than
   leaving it claiming a theme it no longer matches, and there is no eighth key
   with no reader.

   review_capsule_style is deliberately not part of any theme: whether the badge
   is shown at all is a different question from what it looks like, and it lives
   on the Rating Capsule screen.
   ========================================================================= */
(function(){
  'use strict';

  var SCREEN = 'rev-badge';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var data = null;
  var draft = null;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;

  /* The keys THIS screen owns. A save sends these and no others, so it cannot
     reset the four that belong to Rating Capsule. */
  var KEYS = ['review_badge_colour', 'review_badge_sold'];

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

  /* A hex colour or nothing. The server refuses anything else outright — this
     value ends up inside a style attribute on a public page — and the point of
     checking here too is that a half-typed "#1" must not be pushed into the
     preview's style attribute on every keystroke. */
  function hex(value){
    var v = String(value || '').trim().toUpperCase();
    if (/^#[0-9A-F]{6}$/.test(v)) return v;
    if (/^#[0-9A-F]{3}$/.test(v)) return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    return null;
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
    if (title) title.textContent = 'Badge Themes';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    render();
    load();
    return undefined;
  };

  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/review-badges');
      if (mine !== seq) return;
      data = body;
      draft = Object.assign({}, body.settings);
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      data = null;
      draft = null;
      banner = {kind:'err', text: e.status === 404
        ? 'The badge theme endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load the badge settings (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  function seed(body, message){
    data.settings = body.settings;
    data.active_theme = body.active_theme;
    draft = Object.assign({}, body.settings);
    banner = {kind:'ok', text: message};
  }

  async function save(){
    if (!draft || saving) return;

    var colour = hex(draft.review_badge_colour);

    if (!colour) {
      banner = {kind:'err', text:'That is not a colour. Use a hex value such as #E8A33D.'};
      render();
      return;
    }

    saving = true;
    render();

    try {
      var body = await api('/review-badges', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({review_badge_colour: colour, review_badge_sold: !!draft.review_badge_sold})
      });
      seed(body, 'Saved. Every product page uses this straight away.');
      say('Badge theme saved');
    } catch (e) {
      var detail = '';
      if (e.body && e.body.errors) {
        var first = Object.keys(e.body.errors)[0];
        if (first) detail = ' ' + e.body.errors[first][0];
      }
      banner = {kind:'err', text:'Could not save (' + (e.status || 'network') + ').' + detail};
    } finally {
      saving = false;
      render();
    }
  }

  async function applyTheme(name){
    if (saving) return;
    saving = true;
    render();

    try {
      var body = await api('/review-badges/theme', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({theme: name})
      });
      seed(body, 'Theme applied. Every product page uses it straight away.');
      say('Theme applied');
    } catch (e) {
      banner = {kind:'err', text:'Could not apply that theme (' + (e.status || 'network') + ').'};
    } finally {
      saving = false;
      render();
    }
  }

  function revert(){
    if (!data) return;
    draft = Object.assign({}, data.settings);
    banner = null;
    render();
  }

  /* ---------------------------------------------------------------- markup */
  function row(label, hint, control){
    return '<div class="rbt-row"><div><div class="rbt-lab">' + esc(label) + '</div>' +
           '<div class="rbt-hint">' + hint + '</div></div>' +
           '<div class="rbt-ctl">' + control + '</div></div>';
  }

  /* A miniature of the badge each preset produces, drawn from the preset's OWN
     values rather than from the current ones — otherwise every card would look
     identical and the picker would be decoration. */
  function mini(values){
    var label = String(values.review_badge_label || '').split('{n}').join('126');
    return '<span class="rbt-mini">' +
      (values.review_badge_heart ? '<span class="rbt-heart">&#10084;</span>' : '') +
      '<span class="rbt-stars" style="color:' + esc(values.review_badge_colour) + '">' +
      '&#9733;&#9733;&#9733;&#9733;&#9733;</span>' +
      (values.review_badge_avg ? '<span class="rbt-avg">4.8</span>' : '') +
      (values.review_badge_count ? '<span class="rbt-count">' + esc(label) + '</span>' : '') +
      '</span>';
  }

  function themes(){
    var cards = Object.keys(data.themes).map(function(name){
      var t = data.themes[name];
      var on = data.active_theme === name;
      return '<button type="button" class="rbt-theme' + (on ? ' rbt-on' : '') + '" data-rbt-theme="' +
        esc(name) + '"' + (saving ? ' disabled' : '') + '>' +
        '<h4>' + esc(t.label) + (on ? '<span class="rbt-badge">In use</span>' : '') + '</h4>' +
        mini(t.values) +
        '<p>' + esc(t.note) + '</p>' +
        '</button>';
    }).join('');

    return '<div class="rbt-card">' +
      '<p class="rbt-legend">Themes</p>' +
      '<p class="rbt-legend-sub">A theme sets the colour, the heart, the wording and the “sold” note in ' +
      'one go. Nothing is locked in — change anything below afterwards and the screen will simply say ' +
      '<b>Custom</b>. Whether the rating is shown at all is on <b>Rating Capsule</b>, and a theme never ' +
      'touches it.</p>' +
      '<div class="rbt-themes">' + cards + '</div>' +
      (data.active_theme === 'custom'
        ? '<p class="rbt-note" style="margin-top:12px">Your badge does not match any of these. That is ' +
          'fine — pick one to start again from.</p>'
        : '') +
      '</div>';
  }

  function stage(){
    var s = data.sample;
    var cur = data.settings;
    var colour = hex(draft.review_badge_colour) || cur.review_badge_colour;
    var showCap = cur.review_capsule_style === 'capsule' || cur.review_capsule_style === 'both';
    var showInline = cur.review_capsule_style === 'inline' || cur.review_capsule_style === 'both';
    var label = String(cur.review_badge_label || '').split('{n}').join(Number(s.count).toLocaleString());
    var filled = Math.round(Number(s.rating));

    var html = '<div class="rbt-stage"><div class="rbt-prodname">' + esc(s.product) + '</div>';

    if (showCap) {
      html += '<span class="rbt-cap">' +
        (cur.review_badge_heart ? '<span class="rbt-heart">&#10084;</span>' : '') +
        '<span class="rbt-stars" style="color:' + esc(colour) + '">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' +
        (cur.review_badge_avg ? '<span class="rbt-avg">' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (cur.review_badge_count ? '<span class="rbt-count">' + esc(label) + '</span>' : '') +
        '</span>';
    }

    if (showInline) {
      var stars = '';
      for (var i = 1; i <= 5; i++) stars += (i <= filled ? '&#9733;' : '&#9734;');
      html += '<div class="rbt-inline">' +
        '<span class="rbt-stars" style="color:' + esc(colour) + '">' + stars + '</span>' +
        (cur.review_badge_avg ? '<span>' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (cur.review_badge_count ? '<span>&middot; ' + esc(label) + '</span>' : '') +
        (draft.review_badge_sold && Number(s.total_sales) > 999
          ? '<span>&middot; <span class="rbt-sold">' + esc(Math.round(Number(s.total_sales) / 1000)) +
            'k+ sold</span></span>'
          : '') +
        '</div>';
    }

    if (!showCap && !showInline) {
      html += '<div class="rbt-empty">The rating is switched off on Rating Capsule, so none of this is ' +
              'shown on the shop at the moment.</div>';
    }

    return html + '<div class="rbt-price">AED 129.00</div></div>';
  }

  function soldHint(){
    var s = data.sample;
    if (!s.real) return 'Only appears once a product has passed 1,000 sales.';
    if (Number(s.total_sales) > 999) {
      return 'Only appears above 1,000 sales. ' + esc(s.product) + ' has ' +
             Number(s.total_sales).toLocaleString() + ', so it shows there.';
    }
    return 'Only appears above 1,000 sales. Your most-reviewed product has ' +
           Number(s.total_sales).toLocaleString() + ', so nothing will change there yet.';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rbt-wrap">';

    html += '<div class="rbt-card"><div>' +
            '<h2 class="rbt-title">Badge Themes</h2>' +
            '<p class="rbt-sub">How the star rating above the price looks — its colour, its wording, and ' +
            'whether it mentions how many you have sold.</p></div></div>';

    if (banner) {
      html += '<div class="rbt-banner' + (banner.kind === 'ok' ? ' rbt-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    if (busy && !draft) {
      html += '<div class="rbt-card rbt-note">Loading…</div>';
    } else if (draft) {
      html += '<div class="rbt-card">' +
        '<p class="rbt-legend">Preview</p>' +
        '<p class="rbt-legend-sub">' +
        (data.sample.real
          ? 'Your most-reviewed product, with its real score, review count and sales.'
          : 'A specimen — no product has an approved review yet, so there are no real numbers to show.') +
        '</p>' + stage() + '</div>';

      html += themes();

      html += '<div class="rbt-card">' +
        '<p class="rbt-legend">Fine tuning</p>' +
        '<p class="rbt-legend-sub">These two are what a theme sets and you can override. The rest of what a ' +
        'theme changes — the heart, the score, the count and its wording — is on <b>Rating Capsule</b>.</p>' +
        row('Star colour',
            'Used on the capsule and on the inline rating line. A hex value such as #E8A33D.',
            '<input class="rbt-colour" type="color" id="rbt-colour" value="' +
            esc(hex(draft.review_badge_colour) || '#E8A33D') + '">' +
            '<input class="rbt-hex" type="text" id="rbt-hex" maxlength="7" value="' +
            esc(draft.review_badge_colour) + '">') +
        row('Show units sold', soldHint(),
            '<label class="rbt-sw"><input type="checkbox" id="rbt-sold"' +
            (draft.review_badge_sold ? ' checked' : '') + '><i></i></label>') +
        '</div>';

      html += '<div class="rbt-card rbt-actions">' +
        '<button class="rbt-btn" id="rbt-save"' + (saving ? ' disabled' : '') + '>' +
        (saving ? 'Saving…' : 'Save changes') + '</button>' +
        '<button class="rbt-btn rbt-ghost" id="rbt-revert"' + (saving ? ' disabled' : '') + '>Revert</button>' +
        '<span class="rbt-note">Picking a theme above saves on its own — this button is for the two ' +
        'settings here.</span>' +
        '</div>';

      html += '<div class="rbt-card"><p class="rbt-note rbt-warn">These are the same settings as ' +
        '<b>Store &rarr; Ecommerce &rarr; Product page &rarr; Review badges</b>, not a copy of them. ' +
        'Changing one changes the other.</p></div>';
    }

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    if (!draft) return;

    document.querySelectorAll('[data-rbt-theme]').forEach(function(el){
      el.onclick = function(){ applyTheme(el.dataset.rbtTheme); };
    });

    var picker = document.querySelector('#rbt-colour');
    var text = document.querySelector('#rbt-hex');

    if (picker) picker.oninput = function(){
      draft.review_badge_colour = picker.value.toUpperCase();
      if (text) text.value = draft.review_badge_colour;
      repaintStage();
    };

    if (text) text.oninput = function(){
      draft.review_badge_colour = text.value;
      var ok = hex(text.value);
      // The native picker refuses anything that is not #rrggbb, so it is only
      // moved once the typed value is actually a colour. Pushing a half-typed
      // "#1" at it resets it to black under the owner's hands.
      if (ok && picker) picker.value = ok;
      repaintStage();
    };

    var sold = document.querySelector('#rbt-sold');
    if (sold) sold.onchange = function(){ draft.review_badge_sold = sold.checked; repaintStage(); };

    var s = document.querySelector('#rbt-save');
    if (s) s.onclick = save;

    var r = document.querySelector('#rbt-revert');
    if (r) r.onclick = revert;
  }

  /* Only the preview is redrawn as the owner types. A full render() would
     replace the hex field under the cursor and lose the caret position. */
  function repaintStage(){
    var old = document.querySelector('.rbt-stage');
    if (!old || !old.parentNode) return;

    var holder = document.createElement('div');
    holder.innerHTML = stage();
    old.parentNode.replaceChild(holder.firstChild, old);
  }

  function bootIfCurrent(){
    var active = document.querySelector('.side .nav-item.on');
    if (active && active.dataset.go === SCREEN) { render(); load(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
</script>
@endverbatim
