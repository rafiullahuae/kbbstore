{{--
    Appearance → Set contents. (Lane SF)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 20,000-line Blade, for
    the reason cart-page-screen.blade.php states: several lanes edit that file
    at once. The cost is the same — it cannot reach app.blade.php's
    module-scoped constants, so it appends its own sidebar entry and wraps
    window.go.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "there should be list of products which are inside the set, present it
     beautifully. better to preview me the set product front-end preview. so i
     can choose from."

    So the four designs are DRAWN here, not described: each panel below is
    partials/set-contents-panel.blade.php rendering one of the owner's own
    sets, through App\Support\SetContents, in a frame the width of a phone or
    the width of the page. One click makes one of them live.

    ── APPLYING THE PACKAGE MOVES NOTHING ───────────────────────────────────

    `set_panel_design` is not seeded. Absent, SetPanelDesign::current() answers
    `grid`, which is the block set pages already draw, and this screen marks it
    "Live". Nothing on the shop changes until the owner presses a button here.

    ── THE FRAMES ARE IFRAMES, AND THAT IS NOT DECORATION ───────────────────

    The panel ships its own <style> block, and `h2`, `.sec` and `.eyebrow` are
    selectors the console's own stylesheet also claims. Pasted into #content
    the preview would be drawn in the console's type and not the shop's, and
    the panel's rules would leak back out into the console around it. A
    document of its own has neither problem — and it is also how the WIDTH is
    set, which is the whole question: at 390px the designs answer differently
    from how they answer at the width of the page.

    The frames have a fixed height and scroll. Sizing one to its content means
    reading scrollHeight out of it, and this project's habit is not to measure
    layout in script; a preview that scrolls costs the owner a flick and costs
    the codebase nothing.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment
    included — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED sfc- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and
    so is every data- attribute anything clicks: app.blade.php binds delegated
    listeners to `document` itself, each claiming a bare attribute name, and a
    click on any element carrying one is handled by that listener whichever
    screen it belongs to.
--}}
@verbatim
<style>
.sfc-wrap{display:grid;gap:14px;min-width:0}
.sfc-wrap > *{min-width:0}
.sfc-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.sfc-title{font-weight:650;font-size:15px}
.sfc-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:72ch}
.sfc-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.sfc-note.is-bad{border-style:solid;border-color:#b4443c;color:#b4443c}
.sfc-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

/* The control bar: which set, which width, which language. */
.sfc-bar{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:flex-end;min-width:0}
.sfc-f{display:grid;gap:5px;min-width:0;flex:1 1 200px}
.sfc-f label{font-size:12.5px;font-weight:650}
.sfc-f select{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.sfc-seg{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.sfc-seg button{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
  background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sfc-seg button[aria-pressed="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}

/* One block per design: heading, description, button, frame. */
.sfc-d{display:grid;gap:11px;min-width:0}
.sfc-dh{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:baseline;justify-content:space-between;min-width:0}
.sfc-dn{font-size:15px;font-weight:700;min-width:0;overflow-wrap:anywhere}
.sfc-live{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  padding:3px 8px;border-radius:999px;background:var(--accent,#15a85a);color:#fff;white-space:nowrap}
.sfc-dd{font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);margin:0;max-width:72ch}
.sfc-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
  color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sfc-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.sfc-btn[disabled]{opacity:.45;cursor:default}
.sfc-acts{display:flex;flex-wrap:wrap;gap:8px;min-width:0}

/* The frame. A phone is 390 wide and stays 390 wide; the page frame takes the
   column it is given, which on a desktop console is most of a page. */
.sfc-frame{border:1px solid var(--border,#e6e6e6);border-radius:12px;overflow:hidden;
  background:#fff;min-width:0;max-width:100%}
.sfc-frame.is-phone{width:390px}
.sfc-frame iframe{display:block;width:100%;height:520px;border:0;background:#fff}
.sfc-frame.is-phone iframe{height:620px}
@media (max-width:640px){
  .sfc-card{padding:13px}
  .sfc-frame.is-phone{width:100%}
}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'setcontents';

  var data = null;        // { current, default, designs[], sets[] }
  var frames = {};        // design key -> rendered html
  var setId = null, width = 'phone', dir = 'ltr';
  var banner = null, busy = false, seq = 0;

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
      err.status = r.status; err.body = payload;
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

  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Set contents endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Set contents',
      icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M9 7V4h6v3"/>',
      group: 'Appearance',
      after: ['productpage', 'prodstyles', 'bundles']
    });
  }

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
    if (title) title.textContent = 'Set contents';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/set-contents');
      if (mine !== seq) return;

      data = body;
      if (setId === null && body.sets && body.sets.length) setId = body.sets[0].id;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Set contents settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }

    if (mine === seq && data) await drawAll();
  }

  /* Four renders, one per design, all against the same set, the same width and
     the same language. Asked for together so the four pictures the owner is
     comparing are four pictures of the same thing. */
  async function drawAll() {
    if (!data || !data.designs) return;

    var mine = seq;
    frames = {};
    render();

    for (var i = 0; i < data.designs.length; i++) {
      var key = data.designs[i].key;

      try {
        var body = await api('/set-contents/preview', { design: key, set_id: setId, dir: dir });
        if (mine !== seq) return;
        frames[key] = body.html;
      } catch (e) {
        if (mine !== seq) return;
        frames[key] = null;
        banner = explain(e, 'That design could not be previewed.');
      }

      render();
    }
  }

  async function choose(key) {
    if (busy) return;
    busy = true; render();

    try {
      var body = await api('/set-contents', { design: key });
      data.current = body.current;
      data.designs = body.designs;
      say('Set pages now use this design.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  function designHTML(d) {
    var live = d.key === (data && data.current);
    var html = frames[d.key];

    var frame = html === undefined
      ? '<div class="sfc-frame' + (width === 'phone' ? ' is-phone' : '') + '"><div class="sfc-empty">Drawing…</div></div>'
      : (html === null
          ? '<div class="sfc-note is-bad">This design could not be drawn. See the message above.</div>'
          : '<div class="sfc-frame' + (width === 'phone' ? ' is-phone' : '') + '">'
            + '<iframe title="' + esc(d.label) + ' preview" data-sfc-frame="' + esc(d.key) + '"'
            + ' sandbox="allow-same-origin" loading="lazy"></iframe></div>');

    return '<div class="sfc-card sfc-d">'
      + '<div class="sfc-dh"><span class="sfc-dn">' + esc(d.label) + '</span>'
      +   (live ? '<span class="sfc-live">Live</span>' : '') + '</div>'
      + '<p class="sfc-dd">' + esc(d.description) + '</p>'
      + '<div class="sfc-acts">'
      +   '<button class="sfc-btn' + (live ? '' : ' is-primary') + '" data-sfc-pick="' + esc(d.key) + '"'
      +     (live || busy ? ' disabled' : '') + '>'
      +     (live ? 'This is the one in use' : 'Use this design') + '</button>'
      + '</div>'
      + frame
      + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Set contents') return;

    if (busy && !data) {
      host.innerHTML = '<div class="sfc-wrap"><div class="sfc-card"><div class="sfc-empty">Loading…</div></div></div>';
      return;
    }

    if (!data) {
      host.innerHTML = '<div class="sfc-wrap"><div class="sfc-card">'
        + '<div class="sfc-title">Set contents</div>'
        + '<p class="sfc-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="sfc-acts" style="margin-top:12px"><button class="sfc-btn" data-sfc-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var sets = data.sets || [];

    var setField = sets.length
      ? '<div class="sfc-f"><label for="sfc-set">Preview against</label>'
        + '<select id="sfc-set" data-sfc-set>'
        + sets.map(function (s) {
            return '<option value="' + s.id + '"' + (s.id === setId ? ' selected' : '') + '>'
              + esc(s.name) + ' (' + s.members + ' ' + (s.members === 1 ? 'product' : 'products') + ')</option>';
          }).join('')
        + '</select></div>'
      : '';

    var head = '<div class="sfc-card">'
      + '<div class="sfc-title">Set contents</div>'
      + '<p class="sfc-sub">The block on a set\'s own product page that names what is in the box. '
      + 'Four designs, each drawn below from one of your own sets. Pick one and every set page uses it; '
      + 'nothing else on the page moves, and no price changes.</p>'
      + (sets.length
          ? '<div class="sfc-bar" style="margin-top:14px">'
            + setField
            + '<div class="sfc-f" style="flex:0 1 auto"><label>Width</label><div class="sfc-seg">'
            +   '<button type="button" data-sfc-width="phone" aria-pressed="' + (width === 'phone') + '">Phone</button>'
            +   '<button type="button" data-sfc-width="page" aria-pressed="' + (width === 'page') + '">Page</button>'
            + '</div></div>'
            + '<div class="sfc-f" style="flex:0 1 auto"><label>Language</label><div class="sfc-seg">'
            +   '<button type="button" data-sfc-dir="ltr" aria-pressed="' + (dir === 'ltr') + '">English</button>'
            +   '<button type="button" data-sfc-dir="rtl" aria-pressed="' + (dir === 'rtl') + '">Arabic</button>'
            + '</div></div>'
            + '</div>'
          : '')
      + '</div>';

    var body = sets.length
      ? (data.designs || []).map(designHTML).join('')
      : '<div class="sfc-card"><div class="sfc-empty">There is no Set to preview yet. '
        + 'Create one under Catalog &rarr; Sets, put a product or two in it, and come back.</div></div>';

    host.innerHTML = '<div class="sfc-wrap">'
      + (banner ? '<div class="sfc-note is-bad">' + esc(banner) + '</div>' : '')
      + head
      + '<div class="sfc-note">Applying an update does not change your set pages. Until you press '
      + '<b>Use this design</b> the shop keeps drawing <b>Compact grid</b>, which is the block it drew before '
      + 'this screen existed.</div>'
      + body
      + '</div>';

    writeFrames();
  }

  /* srcdoc is assigned as a PROPERTY and never built into the markup above:
     the rendered panel is a whole HTML document with quotes in it, and a
     document pasted into an attribute is one escaping mistake from breaking
     out of it. Assigning the property hands the browser the string. */
  function writeFrames() {
    document.querySelectorAll('[data-sfc-frame]').forEach(function (f) {
      var html = frames[f.dataset.sfcFrame];
      if (typeof html === 'string') f.srcdoc = html;
    });
  }

  document.addEventListener('click', function (e) {
    var pick = e.target.closest ? e.target.closest('[data-sfc-pick]') : null;
    if (pick) { choose(pick.dataset.sfcPick); return; }

    var w = e.target.closest ? e.target.closest('[data-sfc-width]') : null;
    if (w) { width = w.dataset.sfcWidth === 'page' ? 'page' : 'phone'; render(); return; }

    var d = e.target.closest ? e.target.closest('[data-sfc-dir]') : null;
    if (d) { dir = d.dataset.sfcDir === 'rtl' ? 'rtl' : 'ltr'; drawAll(); return; }

    var again = e.target.closest ? e.target.closest('[data-sfc-reload]') : null;
    if (again) { load(); }
  });

  document.addEventListener('change', function (e) {
    var sel = e.target.closest ? e.target.closest('[data-sfc-set]') : null;
    if (!sel) return;
    setId = parseInt(sel.value, 10) || null;
    drawAll();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
