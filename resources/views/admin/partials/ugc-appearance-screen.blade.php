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
.ugy-empty{color:var(--ink-soft,#6b7280);font-size:13px;padding:6px 0}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'ugcstyle';
  var tabs = null, values = {}, open = null, banner = null, busy = false, moduleOn = false, seq = 0;

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
      + '<div class="ugy-note">Every setting here ships at the value the <b>R3</b> design you approved '
      + 'already draws: 158px tiles on a phone, 206px from 900px, a 12px gap, the 16px radius, no count '
      + 'badge and no like button. Moving one is a deliberate change to that design.</div>'
      + '<div class="ugy-card">'
      + '<div class="ugy-tabs">' + strip + '</div>'
      + '<p class="ugy-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
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
