
/* =========================================================================
   Reviews -> Review Settings (Lane BB)

   Eleven controls, every one of which reads and writes a key a live storefront
   code path consults. The inventory, and where each is obeyed:

     sr_show_stars    partials/reviews.blade.php  the score summary + bars
     sr_compact       partials/reviews.blade.php  `sr-compact`: that box at ~half height (Lane PX)
     sr_show_tabs     partials/reviews.blade.php  the All/5/4/photos chips
     sr_show_date     partials/reviews.blade.php  the date on each card
     sr_grid_cols     partials/reviews.blade.php  the --sr-cols custom property
     sr_empty_text    partials/reviews.blade.php  the .sr-empty line
     sr_allow_submit  reviews.blade.php AND Store\ReviewController::submit()
     sr_allow_photos  reviews.blade.php AND Store\ReviewController::submit()
     sr_max_photos    reviews.blade.php AND Store\ReviewController::submit()
     sr_sort          Store\ProductController::show()  the ORDER BY
     sr_max_reviews   Store\ProductController::show()  the LIMIT
     sr_rate_limit    Store\ReviewController::submit() the per-IP hourly cap

   There is deliberately no control for "reviews need approval before they
   appear", "guests may review" or "a purchase is required". See the lane
   report: the first would change what Google is told, the other two have no
   reader on the storefront at all. An inert switch is worse than a missing one.
   ========================================================================= */
(function(){
  'use strict';

  var SCREEN = 'rev-settings';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;      // {settings, sorts, photo_ceiling, text_max}
  var draft = null;     // the edited copy; null until loaded
  var banner = null;    // {kind:'err'|'ok', text}
  var busy = false;
  var saving = false;
  var seq = 0;          // guards against an older response landing last

  /* UNFINISHED CHANGES (Lane PM). Leaving with edits in `draft` used to throw
     them away without a word; they are kept in Unfinished in the top bar
     (partials/unfinished-drafts.blade.php) and come back on the next visit. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Reviews → Review Settings',
    values: function(){ return (data && draft) ? draft : null; },
    set: function(k, v){ if (draft && Object.prototype.hasOwnProperty.call(draft, k)) draft[k] = v; },
    render: function(){ render(); },
    save: function(){ save(); }
  });

  /* ------------------------------------------------------------- plumbing */
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

    /* The console's own go() is NOT called for this id. It would reach
       renderReviewFrame(), and although 'rev-settings' is in LIVE_RENDERED --
       so mountFrame() paints the startup message instead of probing for a file
       that is not there -- there is no reason to paint it at all when this
       screen is about to draw. The nav, crumb and title below are the only
       things go() would have done for us. */
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
    if (title) title.textContent = 'Review Settings';

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
      var body = await api('/review-settings');
      if (mine !== seq) return;
      data = body;
      draft = Object.assign({}, body.settings);
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      data = null;
      draft = null;
      /* A 404 here almost always means the route file shipped without its
         clear_caches migration having run, so the compiled route table does not
         know these paths yet. Said plainly rather than rendering an empty
         screen, which reads as "there are no settings". */
      banner = {kind:'err', text: e.status === 404
        ? 'The review settings endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load review settings (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (data && draft && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save(){
    if (!draft || saving) return;
    saving = true;
    render();

    try {
      var body = await api('/review-settings', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(draft)
      });
      /* Re-seeded from the SERVER's normalised copy, not from the draft. The
         server clamps (grid columns to 1..6, photos to the hard ceiling, the
         empty-state line to its maximum length), and a screen that kept showing
         what was typed instead of what was stored is precisely the defect this
         lane was told to avoid: a control that reads back its own value and
         looks fine while the storefront has something else. */
      data.settings = body.settings;
      draft = Object.assign({}, body.settings);
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      banner = {kind:'ok', text:'Saved. The product page uses these straight away.'};
      say('Review settings saved');
    } catch (e) {
      var detail = '';
      if (e.body && e.body.errors) {
        var first = Object.keys(e.body.errors)[0];
        if (first) detail = ' ' + e.body.errors[first][0];
      }
      banner = {kind:'err', text:'Could not save review settings (' + (e.status || 'network') + ').' + detail};
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
    return '<label class="rvs-sw"><input type="checkbox" data-rvs-bool="' + key + '"' +
           (draft[key] ? ' checked' : '') + '><i></i></label>';
  }

  function num(key, min, max){
    return '<input class="rvs-num" type="number" inputmode="numeric" data-rvs-int="' + key + '" ' +
           'min="' + min + '" max="' + max + '" value="' + esc(draft[key]) + '">';
  }

  function row(key, label, hint, control){
    return '<div class="rvs-row"><div><div class="rvs-lab">' + esc(label) + '</div>' +
           '<div class="rvs-hint">' + hint + '</div></div>' +
           '<div class="rvs-ctl">' + control + '</div></div>';
  }

  function sortSelect(){
    var opts = Object.keys(data.sorts).map(function(k){
      return '<option value="' + esc(k) + '"' + (draft.sr_sort === k ? ' selected' : '') + '>' +
             esc(data.sorts[k]) + '</option>';
    }).join('');
    return '<select class="rvs-sel" data-rvs-enum="sr_sort">' + opts + '</select>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the owner navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rvs-wrap">';

    /* The screen's own heading as a header ROW, not a bordered card. The bar
       above #content already carries the name; a full-width card repeating it
       pushed the first real control below the fold on a phone. */
    html += '<div class="rvs-headrow">' +
            '<div class="rvs-title">Review Settings</div>' +
            '<div class="rvs-sub">How the review section behaves on every product page. ' +
            'Changes reach the storefront as soon as you save.</div>' +
            '</div>';

    if (banner) {
      html += '<div class="rvs-banner' + (banner.kind === 'ok' ? ' rvs-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    if (busy && !draft) {
      html += '<div class="rvs-card rvs-note">Loading review settings…</div>';
    } else if (draft) {
      var ceiling = data.photo_ceiling;

      /* ---- what shoppers see ---- */
      html += '<div class="rvs-card">' +
        '<p class="rvs-legend">On the product page</p>' +
        '<p class="rvs-legend-sub">The review section itself — what it shows and how it is laid out.</p>' +
        row('sr_show_stars', 'Score summary', 'The big average, the star row and the 5-to-1 distribution bars above the reviews. Off leaves just the review count.', sw('sr_show_stars')) +
        row('sr_compact', 'Compact summary', 'The score box at about half its height — the same score, stars, count and bars in the same places, with less space around them. Off draws the taller box.', sw('sr_compact')) +
        row('sr_show_tabs', 'Filter chips', 'The All / 5★ / 4★ / With Photos buttons above the grid.', sw('sr_show_tabs')) +
        row('sr_show_date', 'Dates on reviews', 'The date under each review. Off hides it on every card.', sw('sr_show_date')) +
        row('sr_grid_cols', 'Columns', 'How many review cards sit side by side on a wide screen. Phones always show one.', num('sr_grid_cols', 1, 6)) +
        row('sr_sort', 'Order', 'Which reviews lead the section.', sortSelect()) +
        row('sr_max_reviews', 'Most reviews to load', 'The cap on how many are sent with the page. “Load more” reveals what is already there, so nothing above this number can ever be reached.', num('sr_max_reviews', 4, 500)) +
        '</div>';

      /* ---- empty state ---- */
      html += '<div class="rvs-card">' +
        '<p class="rvs-legend">When a product has no reviews</p>' +
        '<p class="rvs-legend-sub">Printed in place of the grid. Plain text — any markup is stripped when it is saved.</p>' +
        '<div class="rvs-row rvs-wide"><div><div class="rvs-lab">Empty-state line</div>' +
        '<div class="rvs-hint">Up to ' + data.text_max + ' characters.</div></div>' +
        '<div class="rvs-ctl"><input class="rvs-txt" type="text" data-rvs-text="sr_empty_text" ' +
        'maxlength="' + data.text_max + '" value="' + esc(draft.sr_empty_text) + '">' +
        '<div class="rvs-prev" id="rvs-prev">' + esc(draft.sr_empty_text) + '</div></div></div>' +
        '</div>';

      /* ---- submissions ---- */
      html += '<div class="rvs-card">' +
        '<p class="rvs-legend">Writing a review</p>' +
        '<p class="rvs-legend-sub">These govern the form <em>and</em> the endpoint behind it — turning something off here refuses it on the server, not just in the page.</p>' +
        row('sr_allow_submit', 'Accept new reviews', 'Off hides the “Write a Review” button and refuses anything posted to the submit endpoint.', sw('sr_allow_submit')) +
        row('sr_allow_photos', 'Allow photos', 'Off removes the upload field and rejects a submission that carries photos.', sw('sr_allow_photos')) +
        row('sr_max_photos', 'Photos per review', 'Shown on the form and enforced when it is submitted. The server never accepts more than ' + ceiling + ' whatever this says.', num('sr_max_photos', 1, ceiling)) +
        row('sr_rate_limit', 'Submissions per hour', 'Per visitor IP address. The honeypot and the arithmetic check run on every submission and are not optional.', num('sr_rate_limit', 1, 50)) +
        '</div>';

      /* ---- actions ---- */
      html += '<div class="rvs-card rvs-actions">' +
        '<button class="rvs-btn" id="rvs-save"' + (saving ? ' disabled' : '') + '>' +
        (saving ? 'Saving…' : 'Save changes') + '</button>' +
        '<button class="rvs-btn rvs-ghost" id="rvs-revert"' + (saving ? ' disabled' : '') + '>Revert</button>' +
        '<span class="rvs-note">Moderation is unchanged: every new review still waits for approval under All Reviews.</span>' +
        '</div>';
    }

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    if (!draft) return;

    document.querySelectorAll('[data-rvs-bool]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rvsBool] = el.checked; };
    });

    document.querySelectorAll('[data-rvs-int]').forEach(function(el){
      el.oninput = function(){
        var n = parseInt(el.value, 10);
        /* NaN while the box is empty mid-edit is left as-is rather than forced
           to a number: rewriting the field under the cursor makes it impossible
           to clear and retype. The server clamps on save, and the response
           re-seeds the form with what was actually stored. */
        if (!isNaN(n)) draft[el.dataset.rvsInt] = n;
      };
    });

    document.querySelectorAll('[data-rvs-enum]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rvsEnum] = el.value; };
    });

    document.querySelectorAll('[data-rvs-text]').forEach(function(el){
      el.oninput = function(){
        draft[el.dataset.rvsText] = el.value;
        var prev = document.querySelector('#rvs-prev');
        /* textContent, not innerHTML: this is a live preview of visitor-facing
           copy and must not execute anything typed into it. */
        if (prev) prev.textContent = el.value;
      };
    });

    var s = document.querySelector('#rvs-save');
    if (s) s.onclick = save;

    var r = document.querySelector('#rvs-revert');
    if (r) r.onclick = revert;
  }

  /* A bookmark straight to this screen — /admin?go=rev-settings, or the hash.
     That navigation is performed by the boot block at the end of the FIRST
     script in this document, which runs before this one exists, so go() lands
     on renderReviewFrame() and paints the startup message. Nothing has been
     drawn at this point in parsing, so rendering here is not a flicker: it is
     the first thing the browser paints. */
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
