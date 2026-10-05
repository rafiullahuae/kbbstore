
(function(){
  'use strict';

  var SCREEN = 'cache';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;        // GET /admin-api/cache
  var assets = null;      // POST /admin-api/cache/probe, only after a click
  var banner = null;
  var busy = false;
  var seq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body){
    var opts = {headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A 404 from any of these endpoints almost always means the package shipped
     without its clear_caches migration having run, so the compiled route table
     does not know these paths. Said plainly rather than drawing an empty
     screen, which on THIS screen would read as "nothing is cached" -- the
     exact wrong conclusion. */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Cache endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* Seconds, as a person reads them. 31536000 is a number nobody checks; "1
     year" is one they can disagree with. */
  function human(sec){
    sec = Number(sec) || 0;
    if (sec <= 0) return 'not at all';
    if (sec % 31536000 === 0) return (sec / 31536000) + (sec === 31536000 ? ' year' : ' years');
    if (sec % 86400 === 0) return (sec / 86400) + (sec === 86400 ? ' day' : ' days');
    if (sec % 3600 === 0) return (sec / 3600) + (sec === 3600 ? ' hour' : ' hours');
    if (sec % 60 === 0) return (sec / 60) + (sec === 60 ? ' minute' : ' minutes');
    return sec + (sec === 1 ? ' second' : ' seconds');
  }

  function pill(kind, text){
    return '<span class="cch-pill is-' + kind + '">' + esc(text) + '</span>';
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Cache',
      icon:   '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
      group:  'Platform',
      after:  ['siteaddr', 'settings']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Platform"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Platform';
    if (title) title.textContent = 'Cache';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

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
      var body = await api('/cache');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read this shop\'s caching state.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ---------------------------------------------------------------- views */

  /* CARD 1. The reading. Everything here came off a real response or off the
     server's own disk; nothing in it is derived from the settings below. */
  function nowView(){
    var live = data.live || {};
    var mw = data.middleware || {};
    var priv = data.private_pages || {};

    var verdict;
    if (live.error) {
      verdict = pill('bad', 'could not be read');
    } else if (!mw.registered) {
      verdict = pill('bad', 'middleware missing');
    } else if (!mw.enabled) {
      verdict = pill('off', 'shop is not setting this');
    } else if (live.matches_policy) {
      verdict = pill('on', 'matches your settings');
    } else {
      verdict = pill('bad', 'does not match your settings');
    }

    var because;
    if (live.error) {
      because = esc(live.error);
    } else if (!mw.registered) {
      because = 'The header above is whatever the framework computed, because this shop\'s cache middleware is not in the request pipeline at all. '
              + 'That happens when a package has landed but its clear-caches step has not run: clear the compiled caches below and reload this screen.';
    } else if (!mw.enabled) {
      because = 'The header above is the framework\'s own default for a page that states nothing — it keeps a copy and asks this server before reusing it. '
              + 'It is a safe answer and nobody chose it. Switch browser caching on below to have the shop state its own policy instead.';
    } else if (live.matches_policy) {
      because = 'Your shop is stating this deliberately.';
    } else {
      because = 'Something closer to the page is setting its own Cache-Control and this shop\'s policy is being left alone there. '
              + 'That is legitimate — the admin console and /admin-api do it on purpose — but on the shop front it is worth knowing about.';
    }

    return '<div class="cch-card">'
      + '<div class="cch-title">What your shop is doing right now</div>'
      + '<div class="cch-sub">Read a moment ago by fetching a page from this shop through its own code, not worked out from the settings below. '
        + 'Reload this screen to take the reading again.</div>'
      + '<div class="cch-rows">'

      + '<div class="cch-row"><div class="cch-k">Shop page ' + esc(live.path || '/')
        + (live.status ? ' &middot; HTTP ' + esc(live.status) : '') + '</div>'
        + '<div class="cch-v cch-mono">' + esc(live.cache_control || '(no Cache-Control header)') + '</div>'
        + '<div class="cch-v">' + verdict + '</div>'
        + '<div class="cch-note">' + because + '</div></div>'

      + '<div class="cch-row"><div class="cch-k">Account, cart and checkout pages</div>'
        + '<div class="cch-v cch-mono">' + esc(priv.cache_control || '') + '</div>'
        + '<div class="cch-v">' + (priv.enforced ? pill('on', 'enforced') : pill('off', 'waiting on the switch below')) + '</div>'
        + '<div class="cch-note">These pages print one customer their own basket and their own orders. '
          + 'There is no switch for this and there will not be one — a shop that lets a shared cache keep one of these pages '
          + 'shows one customer\'s basket to the next.</div></div>'

      + '<div class="cch-row"><div class="cch-k">Where the shop keeps its own working data</div>'
        + '<div class="cch-v cch-mono">' + esc((data.store || {}).store || '') + ' (' + esc((data.store || {}).driver || '') + ')</div>'
        + '<div class="cch-note">This is the shop\'s internal cache — settings, shipping zones, homepage fragments. '
          + 'Nothing a visitor\'s browser ever sees.</div></div>'

      + '<div class="cch-row"><div class="cch-k">Cache header middleware</div>'
        + '<div class="cch-v">' + (mw.registered ? pill('on', 'in the request pipeline') : pill('bad', 'not in the pipeline'))
        + ' ' + (mw.enabled ? pill('on', 'switched on') : pill('off', 'switched off')) + '</div>'
        + '<div class="cch-note">Two separate things. The first is whether this server is running code new enough to have it; '
          + 'the second is whether you have asked it to act.</div></div>'

      + '</div></div>';
  }

  /* CARD 2. Compiled caches, and the buttons. */
  function compiledView(){
    var c = data.compiled || {};

    function tile(label, text){
      return '<div class="cch-stat"><span>' + esc(label) + '</span><b>' + esc(text) + '</b></div>';
    }

    return '<div class="cch-card">'
      + '<div class="cch-title">Compiled caches on this server</div>'
      + '<div class="cch-sub">Your host has no command line, so these buttons are the only way to drop these. '
        + 'Clearing them is safe at any time: the shop rebuilds each one on the next request that needs it, '
        + 'and an update already does exactly this every time you apply a package.</div>'
      + '<div class="cch-grid" style="margin-top:13px">'
        + tile('Config', c.config ? 'compiled' : 'not compiled')
        + tile('Routes', c.routes ? 'compiled' : 'not compiled')
        + tile('Services', c.services ? 'compiled' : 'not compiled')
        + tile('Packages', c.packages ? 'compiled' : 'not compiled')
        + tile('Views', (c.views || 0) + (c.views === 1 ? ' file' : ' files'))
      + '</div>'
      + '<div class="cch-actions">'
        + '<button class="cch-btn" data-cch-clear="compiled">Clear compiled config, routes and views</button>'
        + '<button class="cch-btn" data-cch-clear="application">Clear the shop\'s working cache</button>'
        + '<button class="cch-btn" data-cch-clear="all">Clear everything</button>'
      + '</div>'
      + '<div class="cch-note">Press the first one after applying a package if a new screen or a new button answers "not found". '
        + 'Press the second if a setting you have saved is not showing on the shop.</div>'
      + '</div>';
  }

  /* CARD 3. The browser half -- the only controls on this screen that change
     a header this application sends. */
  function browserView(){
    var s = data.settings || {};
    var limits = data.limits || {};
    var on = !!s.headers_enabled;

    return '<div class="cch-card">'
      + '<div class="cch-title">What visitors\' browsers may keep</div>'
      + '<div class="cch-sub">Off as your shop ships, and deliberately: turning it on changes the Cache-Control header on '
        + 'every page of a shop that is taking orders, so it is your decision and not a side effect of an update.</div>'

      + '<div class="cch-fields">'
      + '<label class="cch-check"><input type="checkbox" id="cch-enabled"' + (on ? ' checked' : '') + '>'
        + '<span>Let the shop state its own caching rules<div class="cch-note">'
        + 'Turning this on does two things. Shop pages go from the framework\'s default to '
        + '<span class="cch-mono">' + esc(data.storefront_policy || '') + '</span>, which says the same thing in the shop\'s own words. '
        + 'And account, wishlist, cart, checkout and order-tracking pages go from "keep a copy but ask first" to '
        + '<span class="cch-mono">no-store</span> — not kept at all, so a back button after signing out on a shared computer '
        + 'cannot redraw them.</div></span></label>'

      + '<div class="cch-field"><label for="cch-html">Shop pages may be reused for</label>'
        + '<input type="number" id="cch-html" min="0" max="' + esc(limits.html_max_age_ceiling || 3600) + '" step="1" value="'
        + esc(s.html_max_age || 0) + '">'
        + '<div class="cch-note">Seconds, 0 to ' + esc(limits.html_max_age_ceiling || 3600) + '. '
        + '<b>0 is the safe answer and the one to keep</b> — it means a browser may hold the page but must ask this shop before '
        + 'showing it again. Above 0, a visitor can be shown a page without asking, which means a stale basket count and a stale '
        + 'signed-in name for that long. The page is marked <span class="cch-mono">private</span> at every setting, so it is only '
        + 'ever that one browser and never a shared cache in between.</div></div>'
      + '</div>'

      + '<div class="cch-fixed" style="margin-top:13px">'
        + '<div class="cch-title" style="font-size:13px">Never kept, at any setting</div>'
        + '<div class="cch-note">These have no control because the answer is not a matter of taste.</div>'
        + '<div class="cch-paths">'
        + (data.private_pages.prefixes || []).map(function(p){ return '<span class="cch-path">/' + esc(p) + '</span>'; }).join('')
        + '</div></div>'

      + '<div class="cch-actions"><button class="cch-btn is-primary" id="cch-save">Save</button></div>'
      + '</div>';
  }

  /* CARD 4. The half no PHP on this host can enforce. */
  function assetsView(){
    var a = data.assets || {};
    var s = data.settings || {};
    var limits = data.limits || {};

    var probe = '';
    if (assets && assets.ok === false) {
      probe = '<div class="cch-note" style="color:#b4443c">' + esc(assets.reason || '') + '</div>';
    } else if (assets && assets.ok) {
      probe = '<div class="cch-rows"><div class="cch-row">'
        + '<div class="cch-k">Asked the web server for ' + esc(assets.url || '') + '</div>'
        + '<div class="cch-v cch-mono">HTTP ' + esc(assets.status) + ' &middot; '
          + esc(assets.cache_control || '(no Cache-Control header)') + '</div>'
        + '<div class="cch-v">' + (assets.applied
            ? pill('on', 'already in place')
            : pill('off', 'the file below is not doing anything yet')) + '</div>'
        + '</div></div>';
    }

    return '<div class="cch-card">'
      + '<div class="cch-title">Pictures, styles and scripts</div>'
      + '<div class="cch-sub">These files are handed out by the web server directly and never reach your shop\'s code, so no '
        + 'setting in here can put a header on them. The number below only changes the text in the box — the box has to be '
        + 'carried to the server by hand, once.</div>'

      + '<div class="cch-fields">'
      + '<div class="cch-field"><label for="cch-asset">Browsers may keep them for</label>'
        + '<input type="number" id="cch-asset" min="0" max="' + esc(limits.asset_max_age_ceiling || 31536000) + '" step="1" value="'
        + esc(s.asset_max_age || 0) + '">'
        + '<div class="cch-note">Seconds — currently <b>' + esc(human(s.asset_max_age)) + '</b>. '
        + 'A year is safe here and nowhere else on this site, because every one of these files has its content baked into its '
        + 'name: change the picture or the stylesheet and the address changes with it, so a browser holding the old one is '
        + 'holding something that is still correct.</div></div>'
      + '</div>'

      + '<div class="cch-note" style="margin-top:13px">Put this in a file called <b>.htaccess</b> inside '
        + (a.directories || []).map(function(d){ return '<span class="cch-mono">' + esc(d) + '</span>'; }).join(' and ')
        + ' in your web root — two copies, one in each folder, through your host\'s file manager. '
        + '<b>Not in the web root itself</b>: the .htaccess there is the one that routes your whole site, and if one is already '
        + 'in either folder, add to it rather than replacing it. If anything on the shop stops loading afterwards, delete the '
        + 'file and the shop is back immediately — nothing depends on it.</div>'

      + '<div class="cch-code"><pre>' + esc(a.htaccess || '') + '</pre></div>'

      + '<div class="cch-actions">'
        + '<button class="cch-btn" id="cch-copy">Copy</button>'
        + '<button class="cch-btn" id="cch-probe">Check whether it is already in place</button>'
      + '</div>'
      + probe
      + '</div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when this screen is the one on show. The console navigates
    // before an async load finishes, and a late response must not redraw
    // somebody else's page.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="cch-wrap">';

    if (banner) html += '<div class="cch-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="cch-card"><div class="cch-empty">' + (busy ? 'Reading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += nowView() + compiledView() + browserView() + assetsView();
    }

    host.innerHTML = html + '</div>';
    bind();
  }

  /* ---------------------------------------------------------------- saves */
  function bind(){
    var save = document.querySelector('#cch-save');
    if (save) save.onclick = async function(){
      var enabled = document.querySelector('#cch-enabled');
      var html = document.querySelector('#cch-html');
      var asset = document.querySelector('#cch-asset');

      var payload = {
        'headers_enabled': !!(enabled && enabled.checked),
        'html_max_age': Number((html && html.value) || 0),
        'asset_max_age': Number((asset && asset.value) || 0)
      };

      save.disabled = true;

      try {
        // The response IS the new reading -- the server re-probes the shop
        // after writing, so the first card answers with what the change
        // actually did rather than with what it was asked to do.
        data = await api('/cache', payload);
        assets = null;
        say('Saved.');
        render();
      } catch (e) {
        save.disabled = false;
        say(explain(e, 'Could not save that.'));
      }
    };

    document.querySelectorAll('[data-cch-clear]').forEach(function(btn){
      btn.onclick = async function(){
        var target = btn.dataset.cchClear;

        if (target !== 'application' && !window.confirm(
          'This drops the shop\'s compiled config, routes and views. The next few page loads will be slower while it rebuilds them. Continue?'
        )) return;

        btn.disabled = true;

        try {
          var body = await api('/cache/clear', {target: target});
          if (data) data.compiled = body.compiled;
          say(target === 'application' ? 'Working cache cleared.' : 'Compiled caches cleared.');
          // Re-read rather than patch: clearing the compiled caches can change
          // whether the middleware is in the pipeline at all, which is the
          // first card's whole subject.
          load();
        } catch (e) {
          btn.disabled = false;
          say(explain(e, 'Could not clear that.'));
        }
      };
    });

    var copy = document.querySelector('#cch-copy');
    if (copy) copy.onclick = function(){
      var text = ((data || {}).assets || {}).htaccess || '';

      // navigator.clipboard is unavailable on a page served over plain HTTP,
      // which a staging copy of this shop may well be. Falling back rather
      // than doing nothing and looking broken.
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function(){ say('Copied.'); },
                                                  function(){ say('Could not copy — select the text and copy it by hand.'); });
        return;
      }

      var box = document.querySelector('.cch-code pre');
      if (box && window.getSelection) {
        var range = document.createRange();
        range.selectNodeContents(box);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        say('Selected — press Ctrl+C (or Cmd+C) to copy.');
      }
    };

    var probeBtn = document.querySelector('#cch-probe');
    if (probeBtn) probeBtn.onclick = async function(){
      probeBtn.disabled = true;
      probeBtn.textContent = 'Asking…';

      try {
        assets = await api('/cache/probe', {});
        render();
      } catch (e) {
        probeBtn.disabled = false;
        probeBtn.textContent = 'Check whether it is already in place';
        say(explain(e, 'Could not ask the web server.'));
      }
    };
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
