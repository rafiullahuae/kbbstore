{{--
    App → Owner App (Lane OA4). The owner: "keep controls under App (Main menu)
    > Site App / Owner App".

    Its own screen (id 'ownerapp') in the sidebar's App group, holding EVERYTHING
    that was under Platform → Users & Roles → Owner app — access, PINs, phones,
    the sign-in log, the address, security, settings — and Customise app.
    Above them, the App icon card (Lane IC, admin/partials/app-icon-card,
    included once by the Site App screen).
    MOUNTED, NOT COPIED: it calls window.kbbOwnerAppAdmin.mount() exactly as the
    Users & Roles tab did, so owner-app-access.blade.php and
    owner-app-customise.blade.php remain the only code for any of it.

    Users & Roles → Owner app now shows a one-line pointer here instead.
    Capability unchanged: every endpoint the screen calls is
    admin-api/owner-app/**, `ownerapp.manage`, Full Admin only, failing closed.

    Wired by tools/oa4-wire.php from docs/oa4-wiring.json (the LATE_NAV row, the
    title, the deep-link replay and this include) — app.blade.php is not edited
    by the lane.
--}}
@verbatim
<script>
(function () {
  'use strict';
  var SCREEN = 'ownerapp';
  var base = window.kbbOwnerAppAdmin;
  if (!base || base.oaScreen) return;
  base.oaScreen = true;

  /* The Users & Roles tab: a pointer, not a second copy of the screen. */
  var mount = base.mount;
  base.mount = function (el) {
    if (el && el.closest && el.closest('[data-rl-screen]')) {
      el.innerHTML = '<div class="rl-card rl-note">Owner app settings moved to <b>App → Owner App</b>. <button type="button" class="btn sm" data-go-ownerapp>Open Owner App</button></div>';
      return;
    }
    mount.call(base, el);
  };
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-go-ownerapp]')) window.go(SCREEN);
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Owner App',
      icon: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
      group: 'App',
      after: ['siteapp']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="App"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'App';
    if (title) title.textContent = 'Owner App';
    if (side) side.classList.remove('open');
    var host = document.getElementById('content');
    if (!host) return undefined;
    host.innerHTML = '<div class="wrap rl" data-oa-screen><div class="rl-head"><div><h2>Owner App</h2><p>Who may use the phone app and with which PIN, its address and security, and how it looks and what it may do.</p></div></div><div data-oa-icon></div><div data-oa-admin></div></div>';
    mount.call(base, host.querySelector('[data-oa-admin]'));
    /* App icon and favicon (Lane IC): the card from app-icon-card.blade.php,
       on ownerapp.manage endpoints. It GETs its own state once. */
    if (window.kbbAppIconCard) window.kbbAppIconCard(host.querySelector('[data-oa-icon]'), { path: '/admin-api/owner-app/icon', relativePreviews: true, intro: 'The picture on your phone\'s Home Screen for KBB Owner, and its browser tab icon.', shipFavicon: 'The shipped app icon.', favIntro: 'The owner app is never in Google, so this is only the tab.' });
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
