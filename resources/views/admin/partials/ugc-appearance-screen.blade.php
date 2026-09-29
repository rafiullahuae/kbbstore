{{--
    Content → Shoppable video → Appearance. (Lane V3 — Phase 20, the rail
    itself; moved under one row by the integrator, see ugcTabsHTML.)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It wraps window.go, exactly as the ten screens beside it do, so
    that one include is the whole of the change to that file.

    ── WHAT THIS SCREEN IS ──────────────────────────────────────────────────

    Every control the rail has, drawn from ONE schema by ONE renderer.
    App\Services\UgcSettings declares SCHEMA / TABS / POLICY and the endpoint
    answers ModuleSchema::tabs(), so a new option is a line in an array and not a
    new screen. docs/M-PHASE3-SETTINGS-SCHEMA.md is why: sixteen colour fields
    across four modules each stored a hex with no `#` because there were four
    copies of the same three lines and nothing tied them together.

    ── EVERY DEFAULT IS R3 ──────────────────────────────────────────────────

    The owner chose R3 and then said "i need exactly to match including every
    single thing", so every value this screen ships at is the measured geometry of
    R3 in docs/UGC-VIDEO-PREVIEWS.html — 158px tiles, 206 from 900px, a 12px gap,
    the 16px radius, no count badge, no like button. Moving any of them is a
    departure from that design, and the note at the top of the screen says so
    rather than leaving the owner to discover it.

    ── AND IT SAYS WHEN IT IS INERT ─────────────────────────────────────────

    The module ships OFF. Every control here does nothing at all until Store →
    Modules → Shoppable video is switched on, and a rail appears nowhere until a
    [kbb_videos] shortcode is written somewhere. Both are said on the screen,
    because "I moved the sliders and nothing happened" is the support call those
    two sentences prevent.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included —
    with the next closing one, so writing the word in prose swallows everything
    between them and serves the whole docblock to the browser as visible text.

    EVERY CLASS IS PREFIXED ugy- AND EVERY data- ATTRIBUTE data-ugy-, and both
    appear nowhere else in the console: app.blade.php binds delegated listeners to
    `document` itself, each claiming a bare attribute name, so a click on any
    element carrying one is handled by that listener whichever screen it belongs to.
--}}
@verbatim
<style>
.ugy-wrap{display:grid;gap:14px;min-width:0}
.ugy-wrap > *{min-width:0}
.ugy-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.ugy-title{font-weight:650;font-size:15px}
.ugy-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.ugy-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;margin-top:12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.ugy-note b{color:var(--ink,#16181d)}
.ugy-note.is-warm{border-style:solid;border-color:#e9d5a1;background:#fdf9ef}
.ugy-note.is-bad{border-style:solid;border-color:#d9534f;color:#b4443c}
.ugy-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.ugy-tab{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:999px;background:transparent;
         color:inherit;font:inherit;font-size:12.5px;cursor:pointer}
.ugy-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.ugy-fields{display:grid;gap:15px;margin-top:14px;min-width:0}
.ugy-f{display:grid;gap:5px;min-width:0}
.ugy-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.ugy-f label{font-size:12.5px;font-weight:650;overflow-wrap:anywhere}
.ugy-val{font-size:12px;color:var(--ink-soft,#6b7280);font-variant-numeric:tabular-nums;white-space:nowrap}
.ugy-f select,.ugy-f input[type=text]{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.ugy-f input[type=range]{width:100%;min-width:0}
.ugy-check{display:flex;gap:9px;align-items:flex-start;min-width:0}
.ugy-check input{margin-top:3px;flex:0 0 auto}
.ugy-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.ugy-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px;min-width:0}
.ugy-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.ugy-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.ugy-btn[disabled]{opacity:.45;cursor:default}
.ugy-pl{margin:9px 0 0;padding-inline-start:18px;display:grid;gap:3px}
.ugy-pl li{font-size:12.5px;line-height:1.5}
.ugy-ad{margin:9px 0 0;font-size:12.5px;line-height:1.55}
.ugy-pill{display:inline-block;border:1px solid var(--border,#e6e6e6);border-radius:999px;
          padding:0 7px;font-size:11px;line-height:17px;vertical-align:1px}
.ugy-empty{color:var(--ink-soft,#6b7280);font-size:13px;padding:6px 0}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'ugcstyle';
  var tabs = null, values = {}, open = null, banner = null, busy = false, moduleOn = false, seq = 0;
  var sections = [];
  /* What the storefront will do with the rows and settings this shop has right
     now — App\Services\Ugc\RailPlayback. Null until the first load, and the
     Motion tab simply draws nothing while it is. */
  var playback = null;

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

  /*
   * ── THE TOKEN THIS CONSOLE ACTUALLY USES ────────────────────────────────
   *
   * `X-XSRF-TOKEN`, read from the `XSRF-TOKEN` COOKIE that Laravel sets on
   * every response. That is what app.blade.php's own api() has always sent and
   * what ugc-library-screen.blade.php sends.
   *
   * THIS USED TO READ A <meta name="csrf-token"> TAG, AND THE ADMIN HAS NO SUCH
   * TAG. So the token was '' on every call, every write from this screen was
   * refused with 419 "CSRF token mismatch", and the screen reported it as
   * "That section could not be saved." -- a sentence that names the symptom and
   * hides the cause. Nobody could create a section: this path had never worked
   * on any shop, and the owner found it before any test did.
   */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined) {
      options.method = 'POST';
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      options.body = JSON.stringify(body);
    }
    var response = await fetch(base() + path, options);
    var payload = null;
    try { payload = await response.json(); } catch (e) {}
    if (!response.ok) { throw { status: response.status, body: payload }; }
    return payload || {};
  }

  function explain(e, fallback) {
    /* A 404 here means one thing and it is worth saying: the routes are in the
       source and not in the compiled route table. A package that adds a route
       ships a clear_caches_ migration beside it for exactly this reason, and a
       shop whose cache survived it gets a screen that draws and answers 404. */
    if (e && e.status === 404) {
      return 'The Shoppable video endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) { return 'Your account does not hold the Shoppable video permission.'; }
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  /*
   * ── NO SIDEBAR ENTRY, DELIBERATELY ──────────────────────────────────────
   *
   * This screen used to register "Appearance -> Video rail". It is now the
   * "Appearance" TAB of Content -> Shoppable video. The long argument that used
   * to stand here -- why the label had to be "Video rail" and not "Shoppable
   * video" -- was about a collision between two SIDEBAR LABELS. There is one
   * label now, so the collision it guarded against cannot arise.
   *
   * The block is deleted rather than left uncalled because AdminNavAndIdsTest
   * parses this source for the label rather than watching the call.
   *
   * Still routable by id: #ugcstyle and ?go=ugcstyle open it.
   */

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
    if (title) title.textContent = 'Video rail';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api('/ugc-appearance');
      if (mine !== seq) return;
      tabs = body.tabs || [];
      moduleOn = body.module_on === true;
      /* Lane IG — the list the Homepage tab's dropdown is drawn from. The field
         is a `text` on the schema (UgcSettings says why: a `select` would put a
         query on the storefront's hot path), so the endpoint hands the real
         sections over separately and fieldHTML draws a <select> from them. */
      sections = Array.isArray(body.sections) ? body.sections : [];
      playback = (body.playback && typeof body.playback === 'object') ? body.playback : null;
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'These settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();
    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });
    try {
      var out = await api('/ugc-appearance', { settings: payload });
      /* A REFUSED VALUE IS REPORTED. ModuleSchema::cast() answers null for
         something it will not store, and this module's policy turns most of those
         into the shipped default instead — but the endpoint still lists whatever it
         genuinely refused, and a save that swallowed one is the silence that whole
         class exists to remove. */
      var rejected = Object.keys(out.rejected || {});
      say(rejected.length
        ? 'Saved, except: ' + rejected.map(function (k) { return out.rejected[k]; }).join(', ')
        : 'Saved.');
      await load();
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
      busy = false; render();
    }
  }

  function unitOf(f) { return ((f.options || {}).unit) || ''; }

  function fieldHTML(f) {
    var id = 'ugy-' + f.key;
    var help = f.help ? '<p class="ugy-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="ugy-f"><div class="ugy-check">'
        + '<input type="checkbox" id="' + esc(id) + '" data-ugy-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + esc(id) + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    /*
     * ── home_section: A REAL DROPDOWN OVER A `text` FIELD (Lane IG) ─────────
     *
     * The owner asked to "choose the section to show from the list", and this is
     * that list. It is drawn here rather than by the generic `select` arm above
     * because the options are rows in `ugc_sections` and therefore arrive on the
     * payload instead of on the schema — see App\Services\UgcSettings.
     *
     * A DRAFT SECTION IS OFFERED AND LABELLED, not hidden. Hiding it would leave
     * the owner looking for the section he just made with nothing on the screen
     * to say why it is missing; saying "draft — nothing will show yet" tells him
     * the one thing he needs and keeps the pick he wants to make.
     *
     * The value the owner has saved is kept as an option even when it is not in
     * the list any more — a section somebody deleted. Dropping it would silently
     * re-point the <select> at "Nothing yet" and the next Save would write that,
     * which is a homepage emptied by a repaint.
     */
    if (f.key === 'home_section') {
      var chosen = String(values[f.key] == null ? '' : values[f.key]);
      var known = sections.some(function (s) { return s.handle === chosen; });
      var rows = sections.map(function (s) {
        var note = s.published ? '' : ' — draft, nothing will show yet';
        if (s.locale) { note += ' — ' + (s.locale === 'ar' ? 'Arabic shop only' : 'English shop only'); }
        var name = s.title ? s.title + ' (' + s.handle + ')' : s.handle;
        return '<option value="' + esc(s.handle) + '"' + (chosen === s.handle ? ' selected' : '')
          + '>' + esc(name + note) + '</option>';
      });
      if (chosen !== '' && !known) {
        rows.unshift('<option value="' + esc(chosen) + '" selected>'
          + esc(chosen + ' — this section no longer exists') + '</option>');
      }
      rows.unshift('<option value=""' + (chosen === '' ? ' selected' : '') + '>Nothing yet — no rail on the homepage</option>');
      return '<div class="ugy-f"><div class="ugy-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + esc(id) + '" data-ugy-key="' + esc(f.key) + '">' + rows.join('') + '</select>'
        + (sections.length ? '' : '<p class="ugy-help"><b>You have no video sections yet.</b> '
            + 'Make one in <b>Content → Video sections</b> first, then come back and pick it here.</p>')
        + (chosen ? '<p class="ugy-help">The same section can still be placed anywhere else at the same time with '
            + '<b>[kbb_videos section="' + esc(chosen) + '"]</b>.</p>' : '')
        + help + '</div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="ugy-f"><div class="ugy-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + esc(id) + '" data-ugy-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="ugy-f"><div class="ugy-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label>'
        + '<span class="ugy-val" data-ugy-val="' + esc(f.key) + '">' + esc(String(values[f.key]) + unitOf(f)) + '</span></div>'
        + '<input type="range" id="' + esc(id) + '" data-ugy-key="' + esc(f.key) + '"'
        + ' min="' + esc(o.min) + '" max="' + esc(o.max) + '" step="' + esc(o.step) + '"'
        + ' value="' + esc(values[f.key]) + '">' + help + '</div>';
    }

    return '<div class="ugy-f"><div class="ugy-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + esc(id) + '" data-ugy-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  /*
   * ── THE PANEL THIS WHOLE ROUND IS FOR ───────────────────────────────────
   *
   * "on front-end it still not auto play", three rounds running, against a rail
   * that measured perfectly on seeded data every time. The thing nobody could
   * see was that his four `(Demo)` clips stood at the head of the section and
   * `max_playing` is 4, so his own two clips were never reached — and the play
   * disc, the one visible signal, was being hidden by a class the rail set at
   * MOUNT rather than at playback, so the picture could not be read either.
   *
   * So the screen now says it. Counts, then a line per tile, then what to
   * change. Every number comes from the server reading HIS rows and HIS
   * settings — see App\Services\Ugc\RailPlayback, which also explains why the
   * verdict is stated for a wide screen and why the two shopper-side switches
   * are named rather than guessed at.
   */
  function playbackHTML() {
    if (!playback) return '';

    var why = {
      plays: 'will move',
      cap: 'waiting for a slot',
      no_media: 'no video file on the clip',
      no_file: 'its file is missing from this server',
      teaser_off: 'the loop is switched off'
    };

    if (!playback.section) {
      return '<div class="ugy-note"><b>Nothing to describe yet.</b> This shop has no published video '
        + 'section, so there is no rail for the settings below to act on. Build one in '
        + '<b>Content → Shoppable video → Sections</b>.</div>';
    }

    var head = '<b>What your shop will do right now</b>, in the section '
      + '<b>' + esc(playback.section) + '</b>. ';

    if (!playback.teaser_on) {
      return '<div class="ugy-note is-bad">' + head
        + '<b>No tile will move.</b> “Loop the first second” below is switched off, so every '
        + 'one of the ' + esc(playback.total) + ' tiles shows its cover picture and its play button '
        + 'and waits to be tapped. '
        + (playback.teaser_chosen
            ? 'That is a choice somebody made on this screen — it is not how this ships. '
            : '')
        + 'Switch it back on below and press Save.</div>';
    }

    var rows = (playback.tiles || []).map(function (t) {
      /* NO MANUAL NUMBER. The <ol> draws the position, and printing it again
         gave every row "1. 1. Glass skin in 6 steps". The list is rendered in
         the section's own order, so the marker IS the tile's place in the rail
         — which is the number the owner needs to find it by. */
      return '<li>' + esc(t.title)
        + (t.demo ? ' <span class="ugy-pill">Demo</span>' : '')
        + ' — ' + esc(why[t.why] || t.why) + '</li>';
    }).join('');

    var waiting = (playback.tiles || []).filter(function (t) { return t.why === 'cap'; }).length;
    var missing = (playback.tiles || []).filter(function (t) { return t.why === 'no_file' || t.why === 'no_media'; }).length;

    var advice = [];

    if (waiting > 0) {
      advice.push('<b>' + esc(waiting) + '</b> ' + (waiting === 1 ? 'tile is' : 'tiles are')
        + ' only waiting for a slot. Raise <b>Most clips moving at once</b> — it is <b>'
        + esc(playback.max) + '</b> — and more of them move at the same time. Each one is another '
        + 'video the phone has to decode, which is why it has a ceiling.');
    }

    if (playback.demo_moving > 0) {
      advice.push('<b>' + esc(playback.demo_moving) + '</b> of the moving tiles '
        + (playback.demo_moving === 1 ? 'is' : 'are') + ' <b>demo footage</b> this application wrote, '
        + 'not your own clips. Your own clips are always given a slot first, so demo tiles only take '
        + 'what is left over — but they are still filling your rail. '
        + '<b>Content → Demo content → Remove</b> takes them out.');
    }

    if (missing > 0) {
      advice.push('<b>' + esc(missing) + '</b> ' + (missing === 1 ? 'tile' : 'tiles')
        + ' can never move: the video file is not on this server. Those keep their play button and '
        + 'take no slot from the others. Re-upload them in <b>Content → Shoppable video → All clips</b>.');
    }

    advice.push('Two things on the <b>shopper’s own device</b> still stop every tile, whatever is set '
      + 'here: the phone’s <b>Reduce motion</b> accessibility setting, and <b>Data Saver</b>. Both are '
      + 'deliberate and neither is something this screen can override.');

    advice.push('This counts a screen wide enough to show the whole rail at once. On a phone only about '
      + 'two tiles are on screen at a time, so a phone moves fewer than this — that is the design.');

    return '<div class="ugy-note' + (playback.moving === 0 ? ' is-bad' : (waiting + missing > 0 ? ' is-warm' : '')) + '">'
      + head
      + '<b>' + esc(playback.moving) + ' of ' + esc(playback.total) + '</b> tiles will loop on their own; '
      + 'the rest show their cover picture and a play button until somebody taps.'
      + '<ol class="ugy-pl">' + rows + '</ol>'
      + advice.map(function (a) { return '<p class="ugy-ad">' + a + '</p>'; }).join('')
      + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Video rail') return;
    if (document.querySelector('#crumb') && document.querySelector('#crumb').textContent !== 'Appearance') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="ugy-wrap"><div class="ugy-card"><div class="ugy-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="ugy-wrap"><div class="ugy-card">'
        + '<div class="ugy-title">Video rail</div>'
        + '<p class="ugy-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="ugy-actions"><button class="ugy-btn" data-ugy-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="ugy-tab" data-ugy-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    host.innerHTML = '<div class="ugy-wrap">'
      + (window.kbbUgcTabs ? window.kbbUgcTabs(SCREEN) : '')
      + (banner ? '<div class="ugy-note is-bad">' + esc(banner) + '</div>' : '')
      + (moduleOn ? '' : '<div class="ugy-note is-warm"><b>Shoppable video is switched off.</b> '
          + 'Nothing on this screen changes the shop until you turn it on in '
          + '<b>Store → Modules → Shoppable video</b>. Even then a rail appears only where you have '
          + 'written a <b>[kbb_videos]</b> shortcode — see Content → Video sections for the line to copy.</div>')
      + (open === 'home' ? '<div class="ugy-note"><b>Where this shows up.</b> The homepage draws the '
          + 'section you pick here in its own <b>Video rail</b> row — switch that row on, off or move it '
          + 'up and down in <b>Appearance → Homepage</b>. Leave the dropdown on “Nothing yet” and the '
          + 'homepage shows no rail at all, which is how this ships.</div>'
        /*
         * ── THE ONE THING THE OWNER HAS TO DECIDE, SAID RATHER THAN DECIDED ──
         *
         * docs/UGC-RAIL-R3.md §9 observed that the homepage already has a
         * `#KBeautyBliss spotted` band promising shoppable creator content and
         * delivering four still photographs, and proposed that the rail become
         * that section's content instead of sitting near it. That objection is
         * real: with both rows on, the homepage makes the same promise twice,
         * twenty lines apart.
         *
         * This lane did not take the swap — docs/IG-PROFILE.md §11 has the four
         * reasons, the first being that a row labelled "#KBeautyBliss spotted ·
         * Shoppable community photos" drawing a video rail is a row that lies
         * about what it draws, which is the same class of fault as a switch that
         * moves nothing. But a lane that declines a good suggestion owes the
         * owner the choice, not silence: this is the sentence that hands it to
         * him, on the screen where he picks the section, where it is actionable.
         */
        + '<div class="ugy-note"><b>You now have two bands making a similar promise.</b> '
          + '<b>#KBeautyBliss spotted</b> on the homepage says “shoppable” and shows four still '
          + 'photographs; this rail shows the real clips. They are separate rows, so you can keep both, '
          + 'or switch <b>#KBeautyBliss spotted</b> off in <b>Appearance → Homepage</b> and let the '
          + 'video rail be the one that makes it. Nothing here changes that for you.</div>' : '')
      + '<div class="ugy-note">Every setting here ships at the value the <b>R3</b> design you approved '
      + 'already draws: 158px tiles on a phone, 206px from 900px, a 12px gap, the 16px radius, no count '
      + 'badge and no like button. Moving one is a deliberate change to that design.</div>'
      + '<div class="ugy-card">'
      + '<div class="ugy-tabs">' + strip + '</div>'
      + '<p class="ugy-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      /* ON MOTION, because every control this panel talks about is on Motion —
         the loop switch and the cap. Putting it on the tab that owns them is
         what makes it actionable rather than a notice. */
      + (current.key === 'motion' ? playbackHTML() : '')
      + '<div class="ugy-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="ugy-actions">'
      + '<button class="ugy-btn is-primary" data-ugy-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="ugy-btn" data-ugy-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '</div>'
      + '</div></div>';
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    var tab = t.closest('[data-ugy-tab]');
    if (tab) { e.preventDefault(); open = tab.getAttribute('data-ugy-tab'); render(); return; }

    if (t.closest('[data-ugy-save]')) { e.preventDefault(); save(); return; }
    if (t.closest('[data-ugy-reload]')) { e.preventDefault(); load(); return; }
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-ugy-key')) return;
    var key = el.getAttribute('data-ugy-key');

    if (el.type === 'checkbox') { values[key] = el.checked; return; }
    if (el.type === 'range') {
      values[key] = Number(el.value);
      /* The readout is updated IN PLACE rather than by re-rendering: render()
         replaces #content wholesale, so a full repaint on every input event would
         take the slider out from under the finger dragging it. */
      var out = document.querySelector('[data-ugy-val="' + key + '"]');
      if (out) {
        var field = null;
        (tabs || []).forEach(function (t) { t.fields.forEach(function (f) { if (f.key === key) field = f; }); });
        out.textContent = String(el.value) + (field ? unitOf(field) : '');
      }
      return;
    }
    values[key] = el.value;
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-ugy-key')) return;
    if (el.tagName === 'SELECT') { values[el.getAttribute('data-ugy-key')] = el.value; }
  });

  /*
   * NO SIDEBAR ROW ANY MORE. This is the "Appearance" tab of Content →
   * Shoppable video. The long note above about 'Video rail' vs 'Shoppable
   * video' described a collision between two SIDEBAR LABELS; there is only one
   * label now, so the collision cannot arise. The id stays routable.
   */
  /* No addNavEntry() here — see the note above where the block used to be. */
})();
</script>
@endverbatim
