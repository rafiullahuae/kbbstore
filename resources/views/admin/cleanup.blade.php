{{--
    Clean up before the migration — Lane IE.

    The owner: "Also delete un-wanted data and patches etc from the site."

    A STANDALONE DOCUMENT, like admin/import-history.blade.php and
    admin/media-progress.blade.php. Two reasons:

      * NO BUILD STEP. package.json defines no `build` script (CLAUDE.md), so
        everything here is inline and nothing needs compiling.
      * app.blade.php is 22,900 lines and two other lanes are editing it this
        round. A screen that does not need to touch it should not.

    NOTHING IS DELETED BY OPENING THIS PAGE. The list is drawn by a GET that
    writes nothing; the delete is a POST that carries the figures from that GET
    back, and the server refuses it if they no longer describe the shop.

    EVERY VALUE FROM THE SERVER IS TEXT, NEVER HTML. The samples are product
    names and review authors, which after the migration are the owner's own
    WooCommerce content and not something this shop controls. See esc().
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Clean up before the migration · K-Beauty Bliss</title>
<style>
:root{
  --bg:#f6f7f9; --card:#fff; --ink:#151a21; --soft:#6a7482; --line:#e4e8ee;
  --good:#15a85a; --good-bg:#eefaf3; --warn:#b5730b; --warn-bg:#fdf6e7;
  --bad:#c23b3b; --bad-bg:#fdeeee; --keep:#2f6fe0; --keep-bg:#eef3fd;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:21px;margin:0 0 4px}
.sub{color:var(--soft);margin:0 0 18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 2px;display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.why{color:var(--soft);font-size:12.5px;margin:0 0 10px}
.count{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums}
.count.zero{color:var(--soft);font-weight:600}
.bytes{color:var(--soft);font-size:12.5px}
ul.samples{margin:8px 0 0;padding-left:18px;color:var(--soft);font-size:12.5px}
ul.samples li{margin:2px 0;word-break:break-word}
.keep{background:var(--keep-bg);border:1px solid #cfe0fa;border-radius:9px;padding:9px 11px;margin-top:10px;font-size:12.5px}
.keep b{color:var(--keep)}
.keep div{margin:2px 0}
label.pick{display:flex;gap:9px;align-items:flex-start;cursor:pointer}
label.pick input{margin-top:3px;flex:none;width:17px;height:17px}
.danger{background:var(--bad-bg);border:1px solid #f3cccc;border-radius:12px;padding:16px}
.danger h2{color:var(--bad)}
input[type=text]{font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:9px;width:100%;max-width:260px}
button{font:inherit;font-weight:600;padding:10px 16px;border-radius:9px;border:1px solid var(--line);
  background:#fff;cursor:pointer}
button.go{background:var(--bad);border-color:var(--bad);color:#fff}
button[disabled]{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
.msg{margin-top:12px;padding:11px 13px;border-radius:9px;font-size:13px}
.msg.ok{background:var(--good-bg);border:1px solid #bfe8d2;color:#0d6b3a}
.msg.err{background:var(--bad-bg);border:1px solid #f3cccc;color:var(--bad)}
.msg.warn{background:var(--warn-bg);border:1px solid #f0dcaf;color:var(--warn)}
@media (max-width:640px){ .wrap{padding:16px 16px 48px} h1{font-size:19px} }
</style>
</head>
<body>
<div class="wrap">
  <h1>Clean up before the migration</h1>
  <p class="sub">Store &rarr; Import &rarr; Clean up before the migration</p>

  <div class="card">
    <p class="why" style="margin:0">
      <b>Nothing here is deleted by looking.</b> This page lists what it would remove, with the
      counts and the sizes. It never touches a product carrying a WooCommerce id, a demo product
      that has been sold, an order, or a customer. Read the list, tick what you want gone,
      type DELETE, and press the button.
    </p>
  </div>

  <div id="list"><div class="card"><p class="why" style="margin:0">Drawing the list&hellip;</p></div></div>

  <div class="card danger" id="dangerBox" hidden>
    <h2>Delete the ticked items</h2>
    <p class="why">This cannot be undone. If the shop has changed since this list was drawn,
      the server will refuse and delete nothing.</p>
    <div class="row">
      <input type="text" id="confirm" placeholder="Type DELETE" autocomplete="off" spellcheck="false">
      <button class="go" id="go" disabled>Delete what is ticked</button>
      <button id="again">Draw the list again</button>
    </div>
    <div id="msg"></div>
  </div>
</div>

<script>
(function () {
  /*
   * Derived from the address this page was served at, exactly as
   * admin/import-history.blade.php does it. KBB_BASE_PATH prefixes every route
   * on some deployments and is EMPTY on extrabeauty.ae (docs/CUTOVER-
   * EXTRABEAUTY.md); reading it back off the URL is right on both without this
   * page having to know which it is on.
   */
  var BASE = window.location.pathname.replace(/\/page\/?$/, '');
  var TOKEN = document.querySelector('meta[name=csrf-token]').content;
  var state = { counts: {}, buckets: {} };

  // Every server value is inserted as TEXT. After the migration these strings
  // are the owner's own WooCommerce product names and review authors.
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function human(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function draw(data) {
    state.counts = data.counts || {};
    state.buckets = data.buckets || {};

    var order = ['demo_reviews', 'demo_products', 'patch_archives', 'logs'];
    var html = '';
    var anything = false;

    order.forEach(function (key) {
      var b = state.buckets[key];
      if (!b) return;

      var n = Number(b.count) || 0;
      if (n > 0) anything = true;

      html += '<div class="card">';
      html += '<h2><label class="pick"><input type="checkbox" data-bucket="' + esc(key) + '"'
           + (n > 0 ? '' : ' disabled') + '>'
           + '<span>' + esc(b.label || key) + '</span></label></h2>';
      html += '<p class="why"><span class="count' + (n ? '' : ' zero') + '">' + n + '</span> '
           + (n === 1 ? 'item' : 'items')
           + (b.bytes ? ' <span class="bytes">&middot; ' + human(b.bytes) + '</span>' : '')
           + '</p>';

      if (b.samples && b.samples.length) {
        html += '<ul class="samples">';
        b.samples.forEach(function (s) { html += '<li>' + esc(s) + '</li>'; });
        if (n > b.samples.length) {
          html += '<li>&hellip; and ' + (n - b.samples.length) + ' more</li>';
        }
        html += '</ul>';
      }

      var prot = b.protected || {};
      var keys = Object.keys(prot);
      if (keys.length) {
        html += '<div class="keep"><b>Kept, and not deletable from here:</b>';
        keys.forEach(function (k) {
          html += '<div>' + esc(prot[k]) + ' &mdash; ' + esc(k) + '</div>';
        });
        html += '</div>';
      }

      html += '</div>';
    });

    var log = data.demo_seed_log || {};
    html += '<div class="card"><h2>Demo Content&rsquo;s own rows</h2>'
         + '<p class="why"><span class="count' + (log.count ? '' : ' zero') + '">'
         + (Number(log.count) || 0) + '</span> rows &middot; deleted from <b>'
         + esc(log.where || 'Safety → Demo Content') + '</b>, not from here.</p>'
         + '<p class="why" style="margin:0">' + esc(log.note || '') + '</p></div>';

    document.getElementById('list').innerHTML = html;
    document.getElementById('dangerBox').hidden = !anything;

    if (!anything) {
      say('warn', 'There is nothing here to delete.');
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('input[data-bucket]'),
      function (el) { el.addEventListener('change', refreshGo); }
    );

    refreshGo();
  }

  function ticked() {
    return Array.prototype.filter
      .call(document.querySelectorAll('input[data-bucket]'), function (el) { return el.checked; })
      .map(function (el) { return el.getAttribute('data-bucket'); });
  }

  function refreshGo() {
    var word = document.getElementById('confirm').value.trim().toUpperCase();
    document.getElementById('go').disabled = !(word === 'DELETE' && ticked().length > 0);
  }

  function say(kind, text) {
    document.getElementById('msg').innerHTML =
      '<div class="msg ' + kind + '">' + esc(text) + '</div>';
  }

  /*
   * REDRAWING THE LIST MUST NOT ERASE THE ANSWER.
   *
   * This cleared #msg on the way in, and the delete's own handler calls it to
   * refresh the counts -- so "Deleted: 3 x demo products" was written and wiped
   * about 200ms later, every time. The screen then looked as though the button
   * had done nothing, on the one page whose whole job is to say exactly what it
   * removed. Caught by tools/ie-cleanup-shots.cjs waiting for a `.msg` that
   * never stayed on screen, not by reading this.
   *
   * So: load() leaves the message alone and hands back its promise; only the
   * "Draw the list again" button clears it, because there the user asked for a
   * fresh start.
   */
  function load() {
    return fetch(BASE + '/preview', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(draw)
      .catch(function () {
        document.getElementById('list').innerHTML =
          '<div class="card"><p class="why" style="margin:0">The list could not be drawn.</p></div>';
      });
  }

  document.getElementById('confirm').addEventListener('input', refreshGo);
  document.getElementById('again').addEventListener('click', function () {
    document.getElementById('msg').innerHTML = '';
    load();
  });

  document.getElementById('go').addEventListener('click', function () {
    var picked = ticked();
    if (!picked.length) return;

    document.getElementById('go').disabled = true;

    fetch(BASE + '/purge', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': TOKEN
      },
      body: JSON.stringify({
        confirm: document.getElementById('confirm').value.trim(),
        buckets: picked,
        expect: state.counts
      })
    })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (res) {
        document.getElementById('confirm').value = '';

        // The counts are refreshed FIRST and the answer written after, so the
        // redraw cannot land on top of it.
        return load().then(function () {
          if (res.j && res.j.ok) {
            var parts = [];
            Object.keys(res.j.removed || {}).forEach(function (k) {
              parts.push(res.j.removed[k] + ' × ' + k.replace(/_/g, ' '));
            });
            say('ok', 'Deleted: ' + (parts.join(', ') || 'nothing'));
          } else {
            say('err', (res.j && res.j.message) || 'Nothing was deleted.');
          }
        });
      })
      .catch(function () { say('err', 'The request failed. Nothing was deleted.'); });
  });

  load();
})();
</script>
</body>
</html>
