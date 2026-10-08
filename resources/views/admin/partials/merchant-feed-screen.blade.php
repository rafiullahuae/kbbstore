{{--
    Growth & Marketing → Google Shopping feed (Lane SEO).

    The shop's product feed for Google Merchant Center (and Meta Commerce
    Manager, which reads the same file): the on/off switch, the address to
    paste with a Copy button, and what the feed holds right now — counts and
    the first three items exactly as Merchant Center will read them.

    Endpoints: GET and POST /admin-api/merchant-feed (routes/merchant-feed-
    admin.php), capability `marketing.feed`, failing closed. One GET when the
    screen opens, one POST per switch press. No polling, no timer, no layout
    measurement; every value printed passes through esc().

    Wired by tools/seo-wire.php from docs/seo-wiring.json (title, deep-link
    set and this include); the sidebar row is App\Support\AdminNav's.
--}}
@verbatim
<style>
.mf{max-width:860px;margin:0 auto;min-width:0}
.mf-head h2{margin:0 0 6px;font-size:20px}
.mf-head p{margin:0 0 14px;color:var(--ink-soft,#6b7280);font-size:14px;line-height:1.5}
.mf-card{border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;background:var(--card,#fff);margin-bottom:14px;min-width:0}
.mf-card h3{margin:0 0 6px;font-size:15.5px}
.mf-card p{margin:6px 0;color:var(--ink-soft,#6b7280);font-size:13.5px;line-height:1.5}
.mf-switch{display:inline-flex;border:1px solid var(--border,#d9d9d9);border-radius:999px;overflow:hidden}
.mf-switch button{border:0;background:transparent;padding:8px 18px;font:inherit;font-weight:600;cursor:pointer;min-height:40px}
.mf-switch button.on{background:#16a34a;color:#fff}
.mf-switch button.on.off{background:#6b7280}
.mf-copy{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}
.mf-copy code{flex:1 1 260px;min-width:0;overflow-wrap:anywhere;background:rgba(0,0,0,.05);border-radius:8px;padding:9px 10px;font-size:13px}
.mf-copy button,.mf-btn{border:1px solid var(--border,#d9d9d9);background:var(--card,#fff);border-radius:8px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer;min-height:40px}
.mf-steps{margin:6px 0 0;padding-left:20px;font-size:13.5px;line-height:1.6}
.mf-stats{display:flex;flex-wrap:wrap;gap:8px 20px;font-size:14px;margin:4px 0 10px}
.mf-stats b{font-size:18px}
.mf-items{display:grid;gap:10px}
.mf-item{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px;font-size:13px;min-width:0}
.mf-item dl{display:grid;grid-template-columns:150px minmax(0,1fr);gap:3px 10px;margin:0}
.mf-item dt{color:var(--ink-soft,#6b7280)}
.mf-item dd{margin:0;overflow-wrap:anywhere}
.mf-msg{margin:8px 0;font-size:13.5px;border-radius:8px;padding:8px 10px;background:rgba(0,0,0,.04)}
.mf-msg.is-bad{background:rgba(220,38,38,.08);color:#991b1b}
@media (max-width:600px){.mf-item dl{grid-template-columns:minmax(0,1fr)}.mf-item dt{margin-top:4px}}
</style>
<script>
(function () {
  'use strict';
  var SCREEN = 'merchantfeed';
  var BASE = window.location.pathname.replace(/\/+$/, '');
  var st = null;
  var note = null;
  var busy = false;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(body) {
    var opts = { headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api/merchant-feed', opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    return { status: r.status, body: payload || {} };
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fail(status, body) {
    if (status === 404) return 'This screen\'s endpoints are not in the server\'s route table yet. Go to Platform → Cache, press Clear everything, and reload.';
    if (status === 403) return 'Your role cannot open the Google Shopping feed.';
    if (status === 419) return 'Your session expired. Reload the page and sign in again.';
    return (body && body.message) || 'Something went wrong (' + status + '). Nothing was changed.';
  }

  var LABELS = { id: 'id', item_group_id: 'item_group_id', title: 'title', price: 'price', sale_price: 'sale_price', availability: 'availability', brand: 'brand', gtin: 'gtin', identifier_exists: 'identifier_exists', product_type: 'product_type', link: 'link', image_link: 'image_link' };

  function item(row) {
    var out = '<div class="mf-item"><dl>';
    Object.keys(LABELS).forEach(function (k) {
      if (row[k] === undefined) return;
      out += '<dt>' + esc(LABELS[k]) + '</dt><dd>' + esc(row[k]) + '</dd>';
    });
    return out + '</dl></div>';
  }

  function render() {
    var host = document.querySelector('[data-mf-screen]');
    if (!host) return;
    var html = '<div class="mf-head"><h2>Google Shopping feed</h2><p>Your products as a feed for Google Merchant Center, so they can appear in Google Shopping and in free product listings. Meta (Facebook and Instagram shops) can read the same address. It updates by itself whenever a product changes.</p></div>';
    if (note) html += '<div class="mf-msg' + (note.ok ? '' : ' is-bad') + '" role="status">' + esc(note.text) + '</div>';
    if (!st) { host.innerHTML = html + '<div class="mf-card"><p>Loading…</p></div>'; return; }

    html += '<div class="mf-card"><h3>Feed</h3><div class="mf-switch" role="group" aria-label="Google Shopping feed">'
      + '<button type="button" data-mf-set="1" class="' + (st.enabled ? 'on' : '') + '"' + (busy ? ' disabled' : '') + '>On</button>'
      + '<button type="button" data-mf-set="0" class="' + (st.enabled ? '' : 'on off') + '"' + (busy ? ' disabled' : '') + '>Off</button></div>'
      + '<p>' + (st.enabled ? 'On: the address below answers with your products.' : 'Off: the address answers “not found”, and Merchant Center will report the fetch failed.') + '</p>'
      + (st.private ? '<p><b>This install is private</b> (kept out of Google), so the feed stays switched off here until the shop is on its public address.</p>' : '')
      + '</div>';

    html += '<div class="mf-card"><h3>Feed address</h3><div class="mf-copy"><code>' + esc(st.url) + '</code><button type="button" data-copy="' + esc(st.url) + '">Copy</button></div>'
      + '<p><b>Google:</b></p><ol class="mf-steps"><li>Open merchants.google.com → <b>Products</b> → <b>Add products</b> (or <b>Data sources</b> → <b>Add product source</b>).</li><li>Choose <b>Add products from a file</b> → <b>Enter a link to your file</b>, and paste the address above.</li><li>Set the fetch to <b>daily</b>. Country: United Arab Emirates, language English, currency AED.</li></ol>'
      + '<p><b>Meta:</b> Commerce Manager → Catalogue → <b>Data sources</b> → <b>Data feed</b> → <b>Scheduled feed</b>, and paste the same address.</p>'
      + '</div>';

    html += '<div class="mf-card"><h3>What the feed holds</h3><div class="mf-stats"><span><b>' + esc(st.items) + '</b> items</span><span><b>' + esc(st.products) + '</b> products</span><span>built ' + esc(st.built_at) + '</span></div>'
      + '<p>Published, visible products with a price and a picture. A product with sizes or shades is one item per option, grouped together. Products without a barcode (GTIN) are sent as “no barcode on file”; adding the barcode in the product editor helps them show.</p>'
      + (st.sample && st.sample.length ? '<div class="mf-items">' + st.sample.map(item).join('') + '</div>' : '<p>No products qualify yet.</p>')
      + '</div>';

    host.innerHTML = html;
  }

  async function load() {
    var r = await api();
    if (r.status !== 200 || !r.body.ok) { note = { ok: false, text: fail(r.status, r.body) }; st = null; render(); return; }
    st = r.body; render();
  }

  async function set(on) {
    if (busy || !st) return;
    busy = true; render();
    var r = await api({ enabled: on });
    busy = false;
    if (r.status === 200 && r.body.ok) { st = r.body; note = { ok: true, text: on ? 'Saved — the feed is on.' : 'Saved — the feed is off.' }; }
    else note = { ok: false, text: fail(r.status, r.body) };
    render();
  }

  function copyText(value, button) {
    var done = function () { button.textContent = 'Copied ✓'; };
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      if (ok) done(); else button.textContent = 'Select & copy';
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, fallback);
    else fallback();
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-mf-screen]')) return;
    var b = t.closest('[data-copy]');
    if (b) { copyText(b.getAttribute('data-copy'), b); return; }
    b = t.closest('[data-mf-set]');
    if (b && !b.disabled) set(b.getAttribute('data-mf-set') === '1');
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Google Shopping feed',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M6 6 5 3H2"/><path d="M3 11h2M2 14h3"/>',
      group: 'Growth & Marketing',
      after: ['meta']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Growth & Marketing"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Growth & Marketing';
    if (title) title.textContent = 'Google Shopping feed';
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (!host) return undefined;
    host.innerHTML = '<div class="wrap mf" data-mf-screen></div>';
    st = null; note = null; busy = false;
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
