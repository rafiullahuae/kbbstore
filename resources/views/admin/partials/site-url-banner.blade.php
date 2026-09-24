{{--
    Platform → Site address · "This shop is being served from a different
    address". (Lane: domain-portability)

    ── WHERE IT DRAWS ───────────────────────────────────────────────────────

    At the top of the existing Site address screen, above the "Which address is
    this shop's real one" card. It is the same screen that already owns the
    canonical host and the forward list, so a move puts everything about the
    shop's address in one place — and the owner arriving there because a link
    went wrong finds the explanation before the controls.

    Exact path: Platform → Site address. The button is labelled
    "Use this address from now on".

    ── WHY A PARTIAL THAT WRAPS go(), AND NOT LINES IN app.blade.php ────────

    Because app.blade.php is 21,000 lines and the most contended file in the
    repository — three lanes are in it as this is written. The convention is
    already here: admin/partials/cache-screen.blade.php and
    admin/partials/routines-screen.blade.php both wrap window.go rather than
    edit the switch inside it, for the same reason and with the same trade
    (no reach into the module-scoped NAV/TITLES consts, which this does not
    need). One @include line is the entire diff in the contended file.

    renderSiteAddress() replaces #content wholesale on every redraw — a save, a
    check, a keystroke that triggers go('siteaddr') — so this cannot draw once
    and stay. It re-inserts after each redraw instead, and is idempotent by id.

    ── WHY IT DRAWS NOTHING ON A SHOP THAT HAS NOT MOVED ────────────────────

    The endpoint answers mismatch:false when the address being served equals
    APP_URL, and this returns without touching the DOM. extrabeauty.ae is
    served from extrabeauty.ae, so applying this package puts nothing on any
    screen there. Rule 1.

    ── THE VALUE IS NEVER BUILT HERE ────────────────────────────────────────

    `confirm` is the string the SERVER derived for the request it answered, and
    it is sent back untouched. This file never assembles a host out of
    location.hostname, and it must not learn to: the moment the browser gets a
    vote on what is written, the whole in-band/out-of-band argument in
    App\Support\SiteUrl collapses. The screen's job is to show the owner what
    the server proposes and carry their yes back.
--}}
<script>
(function(){
  'use strict';

  var SCREEN = 'siteaddr';
  var state = null, busy = false, loading = false, msg = '';

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function api(path, opts){
    return (typeof impApi === 'function')
      ? impApi(path, opts)
      : Promise.resolve({status:0, data:null});
  }

  function load(){
    if (loading) return;
    loading = true;
    api('/site-url').then(function(r){
      loading = false;
      state = (r && r.data && r.data.ok) ? r.data : {ok:false, mismatch:false};
      draw();
    });
  }

  /*
   * The card. Both hosts are named in full — "a different address" with only
   * one of them on screen is the message that makes an owner guess, and the
   * guess is the thing that gets typed into a form.
   */
  function html(s){
    var rows = (s.also_moves || []).map(function(m){
      var tail = m.kind === 'third-party'
        ? '<b>this shop cannot change it</b> — ' + esc(m.where)
        : esc(m.where);
      return '<li style="margin-top:5px">' + esc(m.what) + ' · ' + tail + '</li>';
    }).join('');

    return '<div class="card pad" id="suBanner" style="margin-bottom:16px;border-color:#f0d9a2;background:#fffaf0">'
      + '<b style="font-size:14px;color:#7a4b07">This shop is being served from a different address</b>'
      + '<p style="font-size:12.5px;color:#7a4b07;margin:6px 0 0;max-width:720px;line-height:1.6">'
      + 'You are looking at it on <code style="font-family:var(--mono)">' + esc(s.current) + '</code>, '
      + 'but it is configured as <code style="font-family:var(--mono)">' + esc(s.configured) + '</code>.<br>'
      + 'The shop keeps working either way. What is wrong is everything it sends OUT: '
      + 'order and password-reset emails, the canonical tag search engines read, the sitemap and the '
      + 'payment callbacks all still say the old address.</p>'
      + (rows ? '<p style="font-size:12px;color:#7a4b07;margin:10px 0 0"><b>Moving the address does not fix these:</b></p>'
              + '<ul style="font-size:12px;color:#7a4b07;margin:2px 0 0 18px;padding:0">' + rows + '</ul>' : '')
      + (msg ? '<div class="impbanner" style="margin-top:10px">' + esc(msg) + '</div>' : '')
      + '<div class="row" style="margin-top:14px;gap:8px">'
      + '<button class="btn" id="suAdopt"' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Writing…' : 'Use this address from now on') + '</button>'
      + '</div>'
      + '<p style="font-size:11px;color:#7a4b07;margin:8px 0 0;max-width:720px">'
      + 'This writes <code style="font-family:var(--mono)">APP_URL</code> into '
      + '<code style="font-family:var(--mono)">.env</code> and clears the compiled caches so it is actually read. '
      + 'Nothing else is changed, and the shop does not restart.</p>'
      + '</div>';
  }

  /*
   * The card that replaces the warning once the address has been written.
   *
   * ▲ WITHOUT THIS THE BANNER SIMPLY VANISHED ON SUCCESS, and the owner was
   * left looking at the screen they started on with no statement that anything
   * had happened -- measured in the browser at both widths before it was
   * added. A confirmation that disappears with the thing it is confirming is
   * indistinguishable from a button that did nothing.
   */
  function doneHtml(s){
    return '<div class="card pad" id="suBanner" style="margin-bottom:16px;border-color:#cfe9db;background:#f0f9f4">'
      + '<b style="font-size:14px;color:#1f7d52">' + esc(msg) + '</b>'
      + '<p style="font-size:12px;color:#1f7d52;margin:6px 0 0;max-width:720px;line-height:1.6">'
      + 'The compiled configuration has been cleared, so this is live now &mdash; no restart. '
      + 'The list above still applies: the webhook addresses at your payment providers are set in '
      + 'their dashboards, not here.</p></div>';
  }

  function draw(){
    var wrap = document.querySelector('#content .wrap');
    if (!wrap) return;

    var existing = document.getElementById('suBanner');
    var markup = (state && state.mismatch) ? html(state) : (msg ? doneHtml(state) : null);

    if (markup === null) { if (existing) existing.remove(); return; }

    if (existing) existing.outerHTML = markup;
    else wrap.insertAdjacentHTML('afterbegin', markup);

    var btn = document.getElementById('suAdopt');
    if (btn) btn.onclick = adopt;
  }

  function adopt(){
    /*
     * The confirmation is whatever the server last told us, sent back
     * unaltered. If the shop has since been reached on a third address the
     * endpoint answers 409 and nothing is written — which is the point of it.
     */
    var confirm = state && state.confirm;
    if (!confirm) return;

    busy = true; msg = ''; draw();

    api('/site-url/adopt', {method:'POST', body: JSON.stringify({confirm: confirm})}).then(function(r){
      busy = false;
      if (r && r.data && r.data.ok) {
        state = r.data;
        msg = r.data.changed
          ? 'Saved. This shop now calls itself ' + (r.data.configured || '') + '.'
          : 'That was already the address on file.';
      } else {
        msg = (r && r.data && r.data.reason) ? r.data.reason : 'That did not save.';
        if (r && r.data && r.data.proposed) { load(); return; }
      }
      draw();
    });
  }

  var previousGo = window.go;

  window.go = function(id){
    var out = previousGo.apply(this, arguments);

    if (id === SCREEN) {
      if (state === null) load();
      else draw();
    }

    return out;
  };

  // The console can land straight on this screen from a bookmark or a reload,
  // in which case go() has already run before this file was parsed.
  if (document.querySelector('#suBannerHost') || (location.hash || '').indexOf(SCREEN) > -1) load();
})();
</script>
