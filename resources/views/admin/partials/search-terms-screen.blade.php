{{--
    Growth & Marketing -> Search Terms.                               Integrator

    "i need an additional backend page under Growth > Search Terms where i can
    see daily, weekly, monthly etc. the searched terms and counts for each
    word/term so i will have idea that what people is more looking for. list it
    proper ranked wise. must be smooth working and real data, but super light."

    Reads GET admin-api/search-terms (App\Services\SearchTermsReport), one
    request per change of period, filter or page, the word box debounced. The
    report is cached for five minutes on the server, so flicking between
    periods costs one grouped query each.

    EVERY TERM HERE WAS TYPED BY A SHOPPER. Each one reaches the page through
    esc(), and the "see what they saw" link is built with encodeURIComponent.
    An admin screen that prints customer text is exactly where stored script
    would run.

    Pulled into app.blade.php at the end, after its raw block closes, like the
    screens beside it: registers its own sidebar entry and wraps window.go.
--}}
@verbatim
<style>
.stx{display:grid;gap:14px}
.stx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px}
.stx-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.stx-seg{display:flex;flex-wrap:wrap;gap:6px}
.stx-seg button{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:999px;padding:6px 12px;font:inherit;font-size:12.5px;cursor:pointer;color:inherit}
.stx-seg button.on{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff;font-weight:650}
.stx-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.stx-tile{border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:12px 14px}
.stx-tile .k{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft,#6b7280);font-weight:650}
.stx-tile .v{display:block;font-size:22px;font-weight:700;margin-top:4px;font-variant-numeric:tabular-nums}
.stx-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px}
.stx-tools input[type=search]{flex:1 1 220px;min-width:0;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:8px 11px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit}
.stx-tools label{font-size:12.5px;display:flex;gap:6px;align-items:center;color:var(--ink-soft,#6b7280)}
.stx-wrap{overflow-x:auto;margin-top:12px}
.stx-t{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
.stx-t th,.stx-t td{padding:8px 10px;border-bottom:1px solid var(--border,#eee);text-align:end;white-space:nowrap}
.stx-t th:nth-child(2),.stx-t td:nth-child(2){text-align:start;white-space:normal;overflow-wrap:anywhere;min-width:140px}
.stx-t th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-soft,#6b7280);font-weight:650}
.stx-t td.rk{color:var(--ink-soft,#6b7280);width:44px}
.stx-t a{color:inherit;text-decoration:none;font-weight:600}
.stx-t a:hover{text-decoration:underline}
.stx-up{color:#15803d}.stx-down{color:#b91c1c}.stx-new{color:#1d4ed8;font-weight:650}
.stx-pill{display:inline-block;font-size:11px;font-weight:650;border-radius:999px;padding:2px 8px;background:#fee2e2;color:#991b1b}
.stx-note{font-size:12px;color:var(--ink-soft,#6b7280);margin:10px 0 0;line-height:1.5}
.stx-pager{display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:12px;font-size:12.5px}
.stx-pager button{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);border-radius:9px;padding:6px 12px;font:inherit;cursor:pointer;color:inherit}
.stx-pager button[disabled]{opacity:.45;cursor:default}
.stx-empty{padding:26px 8px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
@media (max-width:640px){.stx-tiles{grid-template-columns:1fr}.stx-card{padding:13px}.stx-tile .v{font-size:19px}}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'searchterms';
  var state = { period: '7d', show: 'all', find: '', page: 1, partials: false };
  var data = null, busy = false, error = '', seq = 0, typing = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function num(n) { return Number(n || 0).toLocaleString('en-US'); }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(query) {
    var r = await fetch(base() + '/admin-api/search-terms?' + query, {
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin'
    });
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) { var err = new Error('search-terms ' + r.status); err.status = r.status; throw err; }
    return body;
  }

  async function load() {
    var mine = ++seq;
    busy = true; error = '';
    paint();

    var q = new URLSearchParams({
      period: state.period, show: state.show, find: state.find,
      page: String(state.page), partials: state.partials ? '1' : '0'
    });

    try {
      var body = await api(q.toString());
      if (mine !== seq) return;
      data = body;
    } catch (e) {
      if (mine !== seq) return;
      error = e && e.status === 403
        ? 'Your role cannot see search terms. An owner or manager can.'
        : (e && e.status === 404
          ? 'This page is not in the server\'s route table yet. Clear the route cache and reload.'
          : 'Could not load search terms. Try again in a moment.');
    }

    busy = false;
    paint();
  }

  function change(row) {
    if (row.change === null || row.change === undefined) return '<span>—</span>';
    if (row.change === 'new') return '<span class="stx-new">New</span>';
    if (row.change > 0) return '<span class="stx-up">▲ ' + row.change + '%</span>';
    if (row.change < 0) return '<span class="stx-down">▼ ' + Math.abs(row.change) + '%</span>';
    return '<span>0%</span>';
  }

  function paint() {
    var host = document.getElementById('content');
    if (!host || !host.querySelector('[data-stx]')) {
      if (!host) return;
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>Search Terms</h2>'
        + '<p>What shoppers type into the search box, ranked by how often. A search is counted once it settles — '
        + 'when they stop typing, press Enter or pick a result — so “medicube” counts and “med” on the way to it does not.</p></div>'
        + '<div class="stx" data-stx></div></div>';
    }

    var root = host.querySelector('[data-stx]');
    var periods = (data && data.periods) || { today: 'Today', yesterday: 'Yesterday', '7d': 'Last 7 days', '30d': 'Last 30 days', '90d': 'Last 90 days', '365d': 'Last 12 months', all: 'All time' };
    var t = (data && data.totals) || { searches: 0, terms: 0, nothing: 0, fragments_hidden: 0 };

    var html = '<div class="stx-card"><div class="stx-seg" role="group" aria-label="Period">'
      + Object.keys(periods).map(function (k) {
          return '<button type="button" data-stx-period="' + esc(k) + '" class="' + (state.period === k ? 'on' : '') + '">' + esc(periods[k]) + '</button>';
        }).join('')
      + '</div>'
      + '<div class="stx-tiles" style="margin-top:12px">'
      + '<div class="stx-tile"><span class="k">Searches</span><span class="v">' + num(t.searches) + '</span></div>'
      + '<div class="stx-tile"><span class="k">Different terms</span><span class="v">' + num(t.terms) + '</span></div>'
      + '<div class="stx-tile"><span class="k">Found nothing</span><span class="v">' + num(t.nothing) + '</span></div>'
      + '</div>'
      + '<div class="stx-tools">'
      + '<div class="stx-seg" role="group" aria-label="Show">'
      + [['all', 'All terms'], ['nothing', 'Found nothing'], ['found', 'Found something']].map(function (o) {
          return '<button type="button" data-stx-show="' + o[0] + '" class="' + (state.show === o[0] ? 'on' : '') + '">' + o[1] + '</button>';
        }).join('')
      + '</div>'
      + '<input type="search" id="stxFind" placeholder="Find a word…" value="' + esc(state.find) + '" aria-label="Find a word">'
      + '<label><input type="checkbox" id="stxPartials"' + (state.partials ? ' checked' : '') + '> Show partial words</label>'
      + '</div>';

    if (error) {
      html += '<div class="stx-empty">' + esc(error) + '</div>';
    } else if (!data) {
      html += '<div class="stx-empty">Loading…</div>';
    } else if (!data.rows.length) {
      html += '<div class="stx-empty">' + (busy ? 'Loading…' : 'No searches in this period yet.') + '</div>';
    } else {
      var shop = base() + '/shop/?s=';
      html += '<div class="stx-wrap"><table class="stx-t"><thead><tr>'
        + '<th>#</th><th>Search term</th><th>Searches</th><th>Change</th><th>Days seen</th><th>Results</th><th>Last searched</th>'
        + '</tr></thead><tbody>'
        + data.rows.map(function (r) {
            return '<tr><td class="rk">' + num(r.rank) + '</td>'
              + '<td><a href="' + esc(shop + encodeURIComponent(r.term)) + '" target="_blank" rel="noopener" title="See what shoppers see">' + esc(r.term) + '</a></td>'
              + '<td>' + num(r.searches) + '</td>'
              + '<td>' + change(r) + '</td>'
              + '<td>' + num(r.days) + '</td>'
              + '<td>' + (r.found === 0 ? '<span class="stx-pill">Found nothing</span>' : num(r.found)) + '</td>'
              + '<td>' + esc(r.last) + '</td></tr>';
          }).join('')
        + '</tbody></table></div>'
        + '<div class="stx-pager"><span>Page ' + num(data.page) + ' of ' + num(data.pages) + ' · ' + num(data.matching) + ' terms</span>'
        + '<button type="button" data-stx-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>'
        + '<button type="button" data-stx-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '>Next</button></div>';
    }

    html += '<p class="stx-note">“Change” compares with the same length of time just before. “Found nothing” is what shoppers wanted and did not see — '
      + 'worth stocking, or worth a synonym. Click a term to see its results as shoppers do.'
      + (t.fragments_hidden ? ' ' + num(t.fragments_hidden) + ' partial words (like “med” on the way to “medicube”) are hidden.' : '')
      + '</p></div>';

    var hadFocus = document.activeElement && document.activeElement.id === 'stxFind';
    var caret = hadFocus ? document.activeElement.selectionStart : null;
    root.innerHTML = html;

    if (hadFocus) {
      var f = document.getElementById('stxFind');
      if (f) { f.focus(); try { f.setSelectionRange(caret, caret); } catch (e) {} }
    }
  }

  document.addEventListener('click', function (e) {
    if (!document.querySelector('[data-stx]')) return;
    var b = e.target.closest && e.target.closest('[data-stx-period],[data-stx-show],[data-stx-page]');
    if (!b || b.disabled) return;
    if (b.hasAttribute('data-stx-period')) { state.period = b.getAttribute('data-stx-period'); state.page = 1; }
    if (b.hasAttribute('data-stx-show')) { state.show = b.getAttribute('data-stx-show'); state.page = 1; }
    if (b.hasAttribute('data-stx-page')) { state.page = Math.max(1, parseInt(b.getAttribute('data-stx-page'), 10) || 1); }
    load();
  });

  document.addEventListener('input', function (e) {
    if (!e.target || e.target.id !== 'stxFind') return;
    clearTimeout(typing);
    typing = setTimeout(function () { state.find = e.target.value.trim(); state.page = 1; load(); }, 300);
  });

  document.addEventListener('change', function (e) {
    if (!e.target || e.target.id !== 'stxPartials') return;
    state.partials = !!e.target.checked; state.page = 1; load();
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Search Terms',
      icon: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>',
      group: 'Growth & Marketing',
      after: ['pixels', 'meta', 'labels', 'newsletter']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Growth & Marketing"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Growth & Marketing';
    if (title) title.textContent = 'Search Terms';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
