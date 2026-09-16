{{--
    Reviews - Rating Capsule (Lane BE).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens this screen borrows.

    WHAT THIS SCREEN IS FOR. 'rev-capsule' was an entry in REV_SRC pointing at
    kbb-capsule-editor.html, a standalone file this repo has never shipped, so
    the screen rendered the "isn't installed yet" card.

    IT INVENTS NO SETTING. All five keys it edits are already read by
    resources/views/store/product.blade.php on every product page. See
    App\Support\ReviewBadgeSettings for the inventory and for the note that
    Store -> Ecommerce -> Product page is ALSO a writer of the same rows -- this
    screen says so on the screen itself rather than leaving the owner to
    discover that two places edit one thing.

    NO NAV ENTRY IS ADDED HERE: 'rev-capsule' is already in app.blade.php's NAV
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
   Rating Capsule. Every rule is prefixed rcp- and every id is rcp-, so this
   file can never restyle or collide with another screen in the console.

   The PREVIEW rules are a deliberate copy of the storefront's, not a reuse of
   it: the admin console does not load the storefront stylesheet, so .sr-capbar
   here would be an unstyled row of spans. They are named rcp-* so that nothing
   in this file can be mistaken for the real thing, and the numbers are the ones
   the product page actually uses.

   min-width:0 on the grid AND on its children is load-bearing: a grid item's
   default min-width is auto, so a card refuses to shrink below its widest child
   and drags the whole column past the viewport with no way to scroll back.
--------------------------------------------------------------------------- */
.rcp-wrap{display:grid;gap:16px;min-width:0;max-width:1400px}
.rcp-wrap > *{min-width:0}

.rcp-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rcp-legend{font-weight:650;font-size:14px;margin:0 0 3px}
.rcp-legend-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0 0 14px;max-width:80ch;line-height:1.5}
.rcp-headrow{display:grid;gap:3px;min-width:0;padding:0 2px}
.rcp-title{font-weight:650;font-size:15px;margin:0}
.rcp-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0;max-width:78ch;line-height:1.5}

.rcp-row{display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;
         padding:11px 0;border-top:1px solid var(--border,#e6e6e6);min-width:0}
.rcp-row:first-of-type{border-top:0}
.rcp-row > *{min-width:0}
.rcp-lab{font-size:13.5px;font-weight:600;max-width:72ch}
.rcp-hint{color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px;line-height:1.45;max-width:72ch}
.rcp-ctl{display:flex;align-items:center;gap:8px;justify-self:end;flex-wrap:wrap}
.rcp-row.rcp-wide{grid-template-columns:1fr}
.rcp-row.rcp-wide .rcp-ctl{justify-self:stretch;display:block}

@media (max-width:560px){
  .rcp-row{grid-template-columns:1fr}
  .rcp-ctl{justify-self:start}
}

.rcp-sel,.rcp-txt{max-width:100%;padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
                  border-radius:9px;background:transparent;color:inherit}
.rcp-txt{width:100%}

.rcp-sw{position:relative;display:inline-block;width:42px;height:24px;flex:none}
.rcp-sw input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer}
.rcp-sw i{position:absolute;inset:0;border-radius:999px;background:rgba(127,127,127,.32);
          transition:background .16s ease;pointer-events:none;display:block}
.rcp-sw i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
                 background:#fff;transition:transform .16s ease;box-shadow:0 1px 3px rgba(0,0,0,.28)}
.rcp-sw input:checked + i{background:var(--accent,#15a85a)}
.rcp-sw input:checked + i::after{transform:translateX(18px)}
.rcp-sw input:focus-visible + i{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

.rcp-btn{appearance:none;border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;
         font:inherit;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer}
.rcp-btn[disabled]{opacity:.55;cursor:default}
.rcp-ghost{background:transparent;color:inherit;border-color:var(--border,#e6e6e6)}
.rcp-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.rcp-banner{border:1px solid #d9534f;border-radius:10px;padding:11px 13px;font-size:13px;line-height:1.5;
            color:#b3312c;background:rgba(217,83,79,.07)}
.rcp-banner.rcp-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);background:rgba(21,168,90,.07)}

.rcp-note{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;max-width:80ch}
.rcp-warn{border-left:3px solid #d9a13d;padding-left:11px;margin-top:10px}

/* ---- the preview, in the shape the buy box draws it ---- */
.rcp-stage{border:1px dashed var(--border,#e6e6e6);border-radius:12px;padding:18px 16px;min-width:0;
           display:grid;gap:9px;justify-items:start;overflow-x:auto}
.rcp-prodname{font-weight:650;font-size:16px}
.rcp-cap{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--border,#e6e6e6);
         border-radius:999px;padding:5px 12px;font-size:13px;text-decoration:none;color:inherit;
         white-space:nowrap}
.rcp-heart{color:#e8607f}
.rcp-stars{letter-spacing:1px}
.rcp-avg{font-weight:650}
.rcp-count{color:var(--ink-soft,#6b7280)}
.rcp-inline{font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.rcp-sold{color:var(--accent,#15a85a);font-weight:600}
.rcp-price{font-size:22px;font-weight:700;margin-top:2px}
.rcp-empty{color:var(--ink-soft,#6b7280);font-size:12.5px;font-style:italic}
</style>

<script>
/* =========================================================================
   Reviews -> Rating Capsule (Lane BE)

   Five controls, every one of which reads and writes a key the storefront
   already consults, and the inventory is in App\Support\ReviewBadgeSettings:

     review_capsule_style   product.blade.php   $showCap / $showRate
     review_badge_heart     product.blade.php   the heart on the capsule
     review_badge_avg       product.blade.php   the numeric average
     review_badge_count     product.blade.php   the count chip
     review_badge_label     product.blade.php   the count wording, {n} filled in

   The star colour and the sold note live on Badge Themes, which is the same
   set of settings asked a different question: this screen is WHERE the rating
   appears and WHAT IS IN IT, that one is WHAT IT LOOKS LIKE.

   THE PREVIEW IS BUILT FROM A REAL PRODUCT. The server sends the most-reviewed
   product's approved average, count and sales figures (see
   ReviewBadgeApiController::sample), so the owner can tell whether their own
   best seller clears the 1,000-sale bar the "sold" note needs. A preview made
   of invented numbers can look right while the page looks wrong.
   ========================================================================= */
(function(){
  'use strict';

  var SCREEN = 'rev-capsule';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  var data = null;      // {settings, styles, themes, active_theme, sample}
  var draft = null;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;

  /* The keys THIS screen owns. A save sends these and no others, so it cannot
     reset the two that belong to Badge Themes. */
  var KEYS = ['review_capsule_style', 'review_badge_heart', 'review_badge_avg',
              'review_badge_count', 'review_badge_label'];

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
    if (title) title.textContent = 'Rating Capsule';

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
        ? 'The rating badge endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load the rating settings (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save(){
    if (!draft || saving) return;
    saving = true;
    render();

    var payload = {};
    KEYS.forEach(function(k){ payload[k] = draft[k]; });

    try {
      var body = await api('/review-badges', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });
      /* Re-seeded from the SERVER's normalised copy, not from the draft. The
         server trims and bounds the label and falls back to the default when it
         is emptied, and a screen that kept showing what was typed instead of
         what was stored is precisely the defect this repo has shipped before: a
         control that reads back its own value and looks fine while the
         storefront has something else. */
      data.settings = body.settings;
      data.active_theme = body.active_theme;
      draft = Object.assign({}, body.settings);
      banner = {kind:'ok', text:'Saved. Every product page uses this straight away.'};
      say('Rating capsule saved');
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

  function revert(){
    if (!data) return;
    draft = Object.assign({}, data.settings);
    banner = null;
    render();
  }

  /* ---------------------------------------------------------------- markup */
  function sw(key){
    return '<label class="rcp-sw"><input type="checkbox" data-rcp-bool="' + key + '"' +
           (draft[key] ? ' checked' : '') + '><i></i></label>';
  }

  function row(label, hint, control){
    return '<div class="rcp-row"><div><div class="rcp-lab">' + esc(label) + '</div>' +
           '<div class="rcp-hint">' + hint + '</div></div>' +
           '<div class="rcp-ctl">' + control + '</div></div>';
  }

  function styleSelect(){
    var opts = Object.keys(data.styles).map(function(k){
      return '<option value="' + esc(k) + '"' + (draft.review_capsule_style === k ? ' selected' : '') + '>' +
             esc(data.styles[k]) + '</option>';
    }).join('');
    return '<select class="rcp-sel" data-rcp-enum="review_capsule_style">' + opts + '</select>';
  }

  /* The preview. Built from the SAME values the form holds, so it moves as the
     owner types rather than after a save — and from the server's sample product
     rather than from numbers this file made up. */
  function stage(){
    var s = data.sample;
    var showCap = draft.review_capsule_style === 'capsule' || draft.review_capsule_style === 'both';
    var showInline = draft.review_capsule_style === 'inline' || draft.review_capsule_style === 'both';
    var colour = data.settings.review_badge_colour;
    var label = String(draft.review_badge_label || '').split('{n}').join(Number(s.count).toLocaleString());
    var filled = Math.round(Number(s.rating));

    var html = '<div class="rcp-stage">' +
      '<div class="rcp-prodname">' + esc(s.product) + '</div>';

    if (showCap) {
      html += '<span class="rcp-cap">' +
        (draft.review_badge_heart ? '<span class="rcp-heart">&#10084;</span>' : '') +
        '<span class="rcp-stars" style="color:' + esc(colour) + '">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' +
        (draft.review_badge_avg ? '<span class="rcp-avg">' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (draft.review_badge_count ? '<span class="rcp-count">' + esc(label) + '</span>' : '') +
        '</span>';
    }

    if (showInline) {
      var stars = '';
      for (var i = 1; i <= 5; i++) stars += (i <= filled ? '&#9733;' : '&#9734;');
      html += '<div class="rcp-inline">' +
        '<span class="rcp-stars" style="color:' + esc(colour) + '">' + stars + '</span>' +
        (draft.review_badge_avg ? '<span>' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (draft.review_badge_count ? '<span>&middot; ' + esc(label) + '</span>' : '') +
        (data.settings.review_badge_sold && Number(s.total_sales) > 999
          ? '<span>&middot; <span class="rcp-sold">' + esc(Math.round(Number(s.total_sales) / 1000)) +
            'k+ sold</span></span>'
          : '') +
        '</div>';
    }

    if (!showCap && !showInline) {
      html += '<div class="rcp-empty">No rating is shown above the price at all.</div>';
    }

    html += '<div class="rcp-price">AED 129.00</div></div>';

    return html;
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rcp-wrap">';

    /* The screen's own heading as a header ROW, not a bordered card. The bar
       above #content already carries the name; a full-width card repeating it
       pushed the preview below the fold on a phone. */
    html += '<div class="rcp-headrow">' +
            '<div class="rcp-title">Rating Capsule</div>' +
            '<div class="rcp-sub">The star rating above the price on every product page — whether ' +
            'it is shown, where, and what goes in it.</div></div>';

    if (banner) {
      html += '<div class="rcp-banner' + (banner.kind === 'ok' ? ' rcp-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    if (busy && !draft) {
      html += '<div class="rcp-card rcp-note">Loading…</div>';
    } else if (draft) {
      html += '<div class="rcp-card">' +
        '<p class="rcp-legend">Preview</p>' +
        '<p class="rcp-legend-sub">' +
        (data.sample.real
          ? 'Your most-reviewed product, with its real score and review count.'
          : 'A specimen — no product has an approved review yet, so there are no real numbers to show.') +
        '</p>' + stage() + '</div>';

      html += '<div class="rcp-card">' +
        '<p class="rcp-legend">Where the rating appears</p>' +
        '<p class="rcp-legend-sub">The capsule is the rounded badge; the inline line is the plain row of ' +
        'stars. Showing both puts two ratings above one price, which usually reads as a mistake.</p>' +
        row('Rating display', 'Applies to every product page.', styleSelect()) +
        '</div>';

      html += '<div class="rcp-card">' +
        '<p class="rcp-legend">What goes in it</p>' +
        '<p class="rcp-legend-sub">These apply to the capsule and to the inline line together — they are ' +
        'the same rating shown two ways.</p>' +
        row('Heart icon', 'The small heart before the stars. Capsule only.', sw('review_badge_heart')) +
        row('Average score', 'The number itself, such as 4.8.', sw('review_badge_avg')) +
        row('Review count', 'The “126 reviews” chip. Its wording is below.', sw('review_badge_count')) +
        '<div class="rcp-row rcp-wide"><div><div class="rcp-lab">Count wording</div>' +
        '<div class="rcp-hint">Put <b>{n}</b> where the number should go. Up to ' + esc(data.label_max) +
        ' characters; any markup is stripped when it is saved.</div></div>' +
        '<div class="rcp-ctl"><input class="rcp-txt" type="text" data-rcp-text="review_badge_label" ' +
        'maxlength="' + esc(data.label_max) + '" value="' + esc(draft.review_badge_label) + '"></div></div>' +
        '</div>';

      html += '<div class="rcp-card rcp-actions">' +
        '<button class="rcp-btn" id="rcp-save"' + (saving ? ' disabled' : '') + '>' +
        (saving ? 'Saving…' : 'Save changes') + '</button>' +
        '<button class="rcp-btn rcp-ghost" id="rcp-revert"' + (saving ? ' disabled' : '') + '>Revert</button>' +
        '</div>';

      html += '<div class="rcp-card"><p class="rcp-note rcp-warn">These are the same settings as ' +
        '<b>Store &rarr; Ecommerce &rarr; Product page &rarr; Review badges</b>, not a copy of them. ' +
        'Changing one changes the other. The star colour and the “sold” note are on <b>Badge Themes</b>.' +
        '</p></div>';
    }

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    if (!draft) return;

    document.querySelectorAll('[data-rcp-bool]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rcpBool] = el.checked; repaintStage(); };
    });

    document.querySelectorAll('[data-rcp-enum]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rcpEnum] = el.value; repaintStage(); };
    });

    document.querySelectorAll('[data-rcp-text]').forEach(function(el){
      el.oninput = function(){ draft[el.dataset.rcpText] = el.value; repaintStage(); };
    });

    var s = document.querySelector('#rcp-save');
    if (s) s.onclick = save;

    var r = document.querySelector('#rcp-revert');
    if (r) r.onclick = revert;
  }

  /* Only the preview is redrawn as the owner types. A full render() would
     replace the text input under the cursor and lose the caret position, which
     makes the field impossible to edit in the middle. */
  function repaintStage(){
    var old = document.querySelector('.rcp-stage');
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
