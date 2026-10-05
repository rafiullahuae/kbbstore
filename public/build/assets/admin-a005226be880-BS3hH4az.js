
/* =========================================================================
   Reviews -> Rating Badge (Lane BE; the two screens merged by Lane CL)

   ONE SCREEN, TWO TABS, SEVEN SETTINGS, ONE ENDPOINT. Every control writes a
   key the storefront already consults — the inventory is in
   App\Support\ReviewBadgeSettings:

     Badge themes tab — what it looks like
       review_badge_colour   product.blade.php   the star colour, capsule AND
                                                 the inline line
       review_badge_sold     product.blade.php   the "12k+ sold" note, which
                                                 only appears above 999 sales

     Rating capsule tab — whether it is shown, and what is in it
       review_capsule_style  product.blade.php   $showCap / $showRate
       review_badge_heart    product.blade.php   the heart on the capsule
       review_badge_avg      product.blade.php   the numeric average
       review_badge_count    product.blade.php   the count chip
       review_badge_label    product.blade.php   the count wording, {n} filled

   WHY THE MERGE SIMPLIFIED THE SAVE. As two screens each owned half the keys
   and sent only its own half, so that neither could reset the other's — real
   care, spent on a problem created by the split. There is one draft now and
   Save sends all seven, which is both simpler and impossible to get wrong.

   A PRESET IS NOT STORED. Applying one writes the six appearance keys and
   nothing else; which preset is active is worked out by comparing values, here
   against the DRAFT so the "In use" mark is honest while the owner is still
   typing rather than only after a save. There is no eighth key with no reader.

   review_capsule_style is deliberately not part of any theme: whether the badge
   is shown at all is a different question from what it looks like, which is
   also the line the two tabs are drawn along.

   THE PREVIEW IS BUILT FROM A REAL PRODUCT. The server sends the most-reviewed
   product's approved average, count and sales figures (see
   ReviewBadgeApiController::sample), so the owner can tell whether their own
   best seller clears the 1,000-sale bar the "sold" note needs. A preview made
   of invented numbers can look right while the page looks wrong.
   ========================================================================= */
(function(){
  'use strict';

  /* The id that keeps the sidebar row, and the id that keeps nothing but its
     routability. Both open this screen; see the docblock at the top. */
  var SCREEN = 'rev-badge';
  var ALIAS = 'rev-capsule';

  /* Which tab each id lands on, so a bookmark to either of the two screens
     this replaced still arrives where it used to. */
  var TAB_FOR = {'rev-badge': 'themes', 'rev-capsule': 'capsule'};
  var TABS = [['themes', 'Badge themes'], ['capsule', 'Rating capsule']];

  var BASE = window.location.pathname.replace(/\/+$/, '');

  var data = null;      // {settings, styles, themes, active_theme, label_max, sample}
  var draft = null;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;
  var tab = 'themes';

  /* UNFINISHED CHANGES (Lane PM). Leaving with edits in `draft` used to throw
     them away without a word; they are kept in Unfinished in the top bar
     (partials/unfinished-drafts.blade.php) and come back on the next visit.
     Either id lights this screen, so either counts as "on screen". */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Reviews → Rating Badge',
    present: function(){
      var on = document.querySelector('.side .nav-item.on');
      var g = on ? on.getAttribute('data-go') : '';
      return g === SCREEN || g === ALIAS;
    },
    values: function(){ return (data && draft) ? draft : null; },
    set: function(k, v){ if (draft && Object.prototype.hasOwnProperty.call(draft, k)) draft[k] = v; },
    render: function(){ render(); },
    save: function(){ save(); }
  });

  /* All seven, in one draft and one save. */
  var KEYS = ['review_capsule_style', 'review_badge_heart', 'review_badge_avg',
              'review_badge_count', 'review_badge_label', 'review_badge_sold',
              'review_badge_colour'];

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

  /* BOTH IDS MARK THE SAME ROW. go() in app.blade.php marks the row whose
     data-go matches the id it was handed; 'rev-capsule' has no row any more, so
     asking for it would leave nothing marked — and render() below refuses to
     paint over a screen that is not the current one, which would show the owner
     an empty page. Marking SCREEN's row for either id is what makes the retired
     id open this screen with the sidebar telling the truth about where they
     are. */
  function highlight(){
    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === SCREEN;
      });
      g.classList.toggle('open', has);
    });
  }

  /* Arriving at the screen under either id. */
  function enter(id){
    tab = TAB_FOR[id] || tab;

    highlight();

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Reviews';
    if (title) title.textContent = 'Rating Badge';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    render();
    load();
  }

  window.go = function(id){
    if (id !== SCREEN && id !== ALIAS) return previousGo.apply(this, arguments);
    enter(id);
    return undefined;
  };

  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/review-badges');
      if (mine !== seq) return;
      seed(body, null);
    } catch (e) {
      if (mine !== seq) return;
      data = null;
      draft = null;
      banner = {kind:'err', text: e.status === 404
        ? 'The rating badge endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load the rating badge settings (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (data && draft && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  /* Re-seeded from the SERVER's normalised copy, never from the draft. The
     server trims and bounds the label and falls back to the default when it is
     emptied, and a screen that kept showing what was typed instead of what was
     stored is precisely the defect this repo has shipped before: a control that
     reads back its own value and looks fine while the storefront has something
     else. */
  function seed(body, message){
    data = body;
    draft = Object.assign({}, body.settings);
    banner = message ? {kind:'ok', text: message} : null;
  }

  async function save(){
    if (!draft || saving) return;

    var colour = hex(draft.review_badge_colour);

    if (!colour) {
      banner = {kind:'err', text:'That is not a colour. Use a hex value such as #E8A33D.'};
      tab = 'themes';   // where the box they need to fix actually is
      render();
      return;
    }

    saving = true;
    render();

    var payload = {};
    KEYS.forEach(function(k){ payload[k] = draft[k]; });
    payload.review_badge_colour = colour;
    payload.review_badge_heart = !!draft.review_badge_heart;
    payload.review_badge_avg = !!draft.review_badge_avg;
    payload.review_badge_count = !!draft.review_badge_count;
    payload.review_badge_sold = !!draft.review_badge_sold;

    try {
      var body = await api('/review-badges', {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
      });
      seed(body, 'Saved. Every product page uses this straight away.');
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say('Rating badge saved');
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
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
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

  /* --------------------------------------------------------- which preset */
  /* Against the DRAFT, not against what the server last told us. The old
     screen's own copy promised that changing a colour by hand "will simply say
     Custom", and it only did so after a save; this makes the promise true while
     the owner is still typing. Compared the way the server compares — booleans
     as booleans, the colour folded to #RRGGBB — so a half-typed value reads as
     Custom rather than as a match. */
  function themeMatches(name){
    var values = data.themes[name].values;

    for (var key in values) {
      if (!Object.prototype.hasOwnProperty.call(values, key)) continue;

      var want = values[key];
      var mine = draft[key];

      if (typeof want === 'boolean') {
        if (!!mine !== want) return false;
      } else if (key === 'review_badge_colour') {
        if (hex(mine) !== hex(want)) return false;
      } else if (String(mine) !== String(want)) {
        return false;
      }
    }

    return true;
  }

  function activeTheme(){
    for (var name in data.themes) {
      if (Object.prototype.hasOwnProperty.call(data.themes, name) && themeMatches(name)) return name;
    }
    return 'custom';
  }

  /* ---------------------------------------------------------------- markup */
  function sec(title, description, body){
    return '<div class="rbt-sec">' +
      '<div class="rbt-sec-h"><div class="rbt-sec-t">' + esc(title) + '</div>' +
      '<div class="rbt-sec-d">' + description + '</div></div>' +
      body + '</div>';
  }

  function field(label, control, help){
    return '<div class="rbt-field">' +
      '<label class="rbt-label">' + esc(label) + '</label>' + control +
      (help ? '<div class="rbt-help">' + help + '</div>' : '') +
      '</div>';
  }

  /* The whole row is the label, so the text is part of the tap target. The
     switch is a <span> rather than a second <label> because a label nested in a
     label has no defined target and browsers disagree about which one wins. */
  function opt(key, title, help){
    return '<label class="rbt-opt">' +
      '<span class="rbt-sw"><input type="checkbox" data-rbt-bool="' + key + '"' +
      (draft[key] ? ' checked' : '') + '><i></i></span>' +
      '<span class="rbt-opt-x"><span class="rbt-opt-t">' + esc(title) + '</span>' +
      '<span class="rbt-help">' + help + '</span></span>' +
      '</label>';
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

  function themeCards(){
    var live = activeTheme();

    return Object.keys(data.themes).map(function(name){
      var t = data.themes[name];
      var on = live === name;
      return '<button type="button" class="rbt-theme' + (on ? ' rbt-on' : '') + '" data-rbt-theme="' +
        esc(name) + '"' + (saving ? ' disabled' : '') + '>' +
        '<h4>' + esc(t.label) + (on ? '<span class="rbt-badge">In use</span>' : '') + '</h4>' +
        mini(t.values) +
        '<p>' + esc(t.note) + '</p>' +
        '</button>';
    }).join('');
  }

  function soldHint(){
    var s = data.sample;
    if (!s.real) return 'Only appears once a product has passed 1,000 sales.';
    if (Number(s.total_sales) > 999) {
      return 'Only above 1,000 sales. ' + esc(s.product) + ' has ' +
             Number(s.total_sales).toLocaleString() + ', so it shows there.';
    }
    return 'Only above 1,000 sales. Your best seller has ' +
           Number(s.total_sales).toLocaleString() + ', so nothing changes there yet.';
  }

  function themesTab(){
    return sec('Presets',
      'A preset sets the colour, the heart, the wording and the &ldquo;sold&rdquo; note in one go. ' +
      'Picking one <b>saves straight away</b> and replaces anything unsaved on either tab; change ' +
      'anything afterwards and the screen simply says Custom.',
      '<div class="rbt-themes">' + themeCards() + '</div>') +

      sec('Fine tuning',
      'The two settings a preset decides that you are most likely to want your own answer to.',
      '<div class="rbt-grid">' +
        field('Star colour',
          '<div class="rbt-colourbox">' +
          '<input class="rbt-colour" type="color" id="rbt-colour" value="' +
          esc(hex(draft.review_badge_colour) || '#E8A33D') + '">' +
          '<input class="rbt-input rbt-hex" type="text" id="rbt-hex" maxlength="7" value="' +
          esc(draft.review_badge_colour) + '"></div>',
          'Used on the capsule and on the inline line.') +
        opt('review_badge_sold', 'Show units sold', soldHint()) +
      '</div>');
  }

  function styleSelect(){
    var opts = Object.keys(data.styles).map(function(k){
      return '<option value="' + esc(k) + '"' + (draft.review_capsule_style === k ? ' selected' : '') + '>' +
             esc(data.styles[k]) + '</option>';
    }).join('');
    return '<select class="rbt-select" id="rbt-style" data-rbt-enum="review_capsule_style">' + opts + '</select>';
  }

  function capsuleTab(){
    return sec('Where it appears',
      'The capsule is the rounded badge; the inline line is the plain row of stars. Showing both ' +
      'puts two ratings above one price, which usually reads as a mistake.',
      '<div class="rbt-grid">' +
        field('Rating display', styleSelect(), 'Applies to every product page.') +
        field('Count wording',
          '<input class="rbt-input" type="text" id="rbt-label" data-rbt-text="review_badge_label" ' +
          'maxlength="' + esc(data.label_max) + '" value="' + esc(draft.review_badge_label) + '">',
          'Put <b>{n}</b> where the number goes. Up to ' + esc(data.label_max) + ' characters.') +
      '</div>') +

      sec('What goes in it',
      'These apply to the capsule and to the inline line together — they are the same rating shown ' +
      'two ways.',
      '<div class="rbt-grid">' +
        opt('review_badge_heart', 'Heart icon', 'Before the stars. Capsule only.') +
        opt('review_badge_avg', 'Average score', 'The number itself, such as 4.8.') +
        opt('review_badge_count', 'Review count', 'The &ldquo;126 reviews&rdquo; chip. Its wording is above.') +
      '</div>');
  }

  /* The preview. Built from the SAME draft both tabs write to, so it moves as
     the owner types whichever tab they are on — and from the server's sample
     product rather than from numbers this file made up. */
  function stage(){
    var s = data.sample;
    var colour = hex(draft.review_badge_colour) || data.settings.review_badge_colour;
    var showCap = draft.review_capsule_style === 'capsule' || draft.review_capsule_style === 'both';
    var showInline = draft.review_capsule_style === 'inline' || draft.review_capsule_style === 'both';
    var label = String(draft.review_badge_label || '').split('{n}').join(Number(s.count).toLocaleString());
    var filled = Math.round(Number(s.rating));

    var html = '<div class="rbt-stage"><div class="rbt-prodname">' + esc(s.product) + '</div>';

    if (showCap) {
      html += '<span class="rbt-cap">' +
        (draft.review_badge_heart ? '<span class="rbt-heart">&#10084;</span>' : '') +
        '<span class="rbt-stars" style="color:' + esc(colour) + '">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' +
        (draft.review_badge_avg ? '<span class="rbt-avg">' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (draft.review_badge_count ? '<span class="rbt-count">' + esc(label) + '</span>' : '') +
        '</span>';
    }

    if (showInline) {
      var stars = '';
      for (var i = 1; i <= 5; i++) stars += (i <= filled ? '&#9733;' : '&#9734;');
      html += '<div class="rbt-inline">' +
        '<span class="rbt-stars" style="color:' + esc(colour) + '">' + stars + '</span>' +
        (draft.review_badge_avg ? '<span>' + esc(Number(s.rating).toFixed(1)) + '</span>' : '') +
        (draft.review_badge_count ? '<span>&middot; ' + esc(label) + '</span>' : '') +
        (draft.review_badge_sold && Number(s.total_sales) > 999
          ? '<span>&middot; <span class="rbt-sold">' + esc(Math.round(Number(s.total_sales) / 1000)) +
            'k+ sold</span></span>'
          : '') +
        '</div>';
    }

    if (!showCap && !showInline) {
      html += '<div class="rbt-empty">No rating is shown above the price at all. ' +
              'The switch for that is <b>Rating display</b>, on the Rating capsule tab.</div>';
    }

    return html + '<div class="rbt-price">AED 129.00</div></div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    /* Only paint when this screen is the one showing, so a render triggered by
       a late response cannot overwrite whatever the owner navigated to. */
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rbt-wrap">';

    if (banner) {
      html += '<div class="rbt-banner' + (banner.kind === 'ok' ? ' rbt-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    if (busy && !draft) {
      html += '<div class="rbt-card rbt-note">Loading…</div>';
    } else if (draft) {
      /* The preview sits above the tabs because it is the one thing both tabs
         are about: whichever of the seven settings you are changing, this is
         what the change does. */
      html += '<div class="rbt-card">' +
        sec('Preview',
          data.sample.real
            ? 'Your most-reviewed product, with its real score, review count and sales.'
            : 'A specimen — no product has an approved review yet, so there are no real numbers to show.',
          stage()) +
        '</div>';

      html += '<div class="rbt-card">' +
        '<div class="rbt-tabs">' +
        TABS.map(function(t){
          return '<button type="button" class="rbt-tab' + (tab === t[0] ? ' on' : '') +
                 '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>';
        }).join('') +
        '</div>' +

        /* The owner's question, answered on the page: what are these tabs, and
           why is this one screen. `ectabs-hint` is the console's existing
           caption class for exactly this — the Ecommerce, Delivery, SEO and
           Coupons screens all use it. */
        '<p class="ectabs-hint">Two tabs, one badge: <b>Badge themes</b> is what the star rating ' +
        'above the price looks like and <b>Rating capsule</b> is whether it is shown and what goes ' +
        'in it, and they are the same seven settings saved together — which is why they are one ' +
        'screen rather than two, and why nothing is saved on either tab until you press Save.</p>' +

        (tab === 'capsule' ? capsuleTab() : themesTab()) +

        '<div class="rbt-actions">' +
        '<button class="rbt-btn" id="rbt-save"' + (saving ? ' disabled' : '') + '>' +
        (saving ? 'Saving…' : 'Save changes') + '</button>' +
        '<button class="rbt-btn rbt-ghost" id="rbt-revert"' + (saving ? ' disabled' : '') + '>Revert</button>' +
        '<span class="rbt-note">Saves both tabs. Presets save on their own.</span>' +
        '</div>' +
        '</div>';

      html += '<p class="rbt-note rbt-warn">These are the same settings as <b>Store &rarr; Ecommerce ' +
        '&rarr; Product page &rarr; Review badges</b>, not a copy of them. Changing one changes the ' +
        'other.</p>';
    }

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    /* The tabs bind whether or not there is a draft — but they are only drawn
       when there is one, so this is scoped to the strip rather than to the
       document: `[data-tab]` is a shape other screens in this console use too. */
    document.querySelectorAll('.rbt-tabs [data-tab]').forEach(function(el){
      el.onclick = function(){
        tab = el.dataset.tab;
        /* render(), not enter(): a tab is not a navigation. Re-loading here
           would refetch and throw away whatever is unsaved on the other tab,
           which is the opposite of what the hint above the tabs promises. */
        render();
      };
    });

    if (!draft) return;

    document.querySelectorAll('[data-rbt-theme]').forEach(function(el){
      el.onclick = function(){ applyTheme(el.dataset.rbtTheme); };
    });

    document.querySelectorAll('[data-rbt-bool]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rbtBool] = el.checked; repaint(); };
    });

    document.querySelectorAll('[data-rbt-enum]').forEach(function(el){
      el.onchange = function(){ draft[el.dataset.rbtEnum] = el.value; repaint(); };
    });

    document.querySelectorAll('[data-rbt-text]').forEach(function(el){
      el.oninput = function(){ draft[el.dataset.rbtText] = el.value; repaint(); };
    });

    var picker = document.querySelector('#rbt-colour');
    var text = document.querySelector('#rbt-hex');

    if (picker) picker.oninput = function(){
      draft.review_badge_colour = picker.value.toUpperCase();
      if (text) text.value = draft.review_badge_colour;
      repaint();
    };

    if (text) text.oninput = function(){
      draft.review_badge_colour = text.value;
      var ok = hex(text.value);
      // The native picker refuses anything that is not #rrggbb, so it is only
      // moved once the typed value is actually a colour. Pushing a half-typed
      // "#1" at it resets it to black under the owner's hands.
      if (ok && picker) picker.value = ok;
      repaint();
    };

    var s = document.querySelector('#rbt-save');
    if (s) s.onclick = save;

    var r = document.querySelector('#rbt-revert');
    if (r) r.onclick = revert;
  }

  /* ONLY THE TWO THINGS THAT READ THE DRAFT ARE REDRAWN, never the form.
     A full render() here would replace the hex box or the wording box under the
     caret and lose the cursor position, and would rebuild a switch between the
     press and the release so the tap never registers. Both are defects this
     console has shipped on other screens; neither is reachable from here,
     because nothing this function touches is a control. */
  function repaint(){
    var oldStage = document.querySelector('.rbt-stage');
    if (oldStage && oldStage.parentNode) {
      var holder = document.createElement('div');
      holder.innerHTML = stage();
      oldStage.parentNode.replaceChild(holder.firstChild, oldStage);
    }

    /* The "In use" mark moves as the values move, so the cards have to follow.
       They are buttons, not inputs: rebuilding them cannot interrupt a keypress
       or a tap on anything else, and nothing repaints while one of them is
       being pressed. */
    var themes = document.querySelector('.rbt-themes');
    if (themes) {
      themes.innerHTML = themeCards();
      themes.querySelectorAll('[data-rbt-theme]').forEach(function(el){
        el.onclick = function(){ applyTheme(el.dataset.rbtTheme); };
      });
    }
  }

  /* THE ADDRESS, READ HERE RATHER THAN TRUSTED FROM THE SIDEBAR.
     app.blade.php's deep-link block runs go(?go= / #) immediately after
     buildNav(), near the top of the document — long before this partial is
     parsed and long before the override above exists. For 'rev-badge' that is
     survivable, because go() marks its row and the check below finds it. For
     'rev-capsule' there is no row to mark, so nothing would be marked, nothing
     would match, and the owner would be left looking at the "could not be
     loaded" card the frame machinery painted on its way past. Reading the
     address is what closes that gap. */
  function addressed(){
    var q = '';
    try { q = new URLSearchParams(window.location.search).get('go') || ''; } catch (e) { q = ''; }
    var h = String(window.location.hash || '').replace(/^#/, '');
    return q || h;
  }

  function bootIfCurrent(){
    var asked = addressed();

    if (asked === SCREEN || asked === ALIAS) { enter(asked); return; }

    var active = document.querySelector('.side .nav-item.on');
    if (active && active.dataset.go === SCREEN) { enter(SCREEN); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
