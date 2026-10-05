/*
 * K-Beauty Bliss: register the shop's service worker (Lane PW).
 * Loaded with `defer` by partials/site-app-head only while App -> Site App is
 * on. It waits for the page's load event, so it never competes with the
 * first paint, and registers the worker whose address the tag carries.
 * Nothing else: no install button, no prompt (how the install is offered is
 * decided later), no timer, no layout read.
 */
(function () {
  'use strict';
  var s = document.currentScript;
  if (!s || !('serviceWorker' in navigator)) return;
  var sw = s.getAttribute('data-sw'), scope = s.getAttribute('data-scope');
  if (!sw || !scope) return;
  function go() {
    navigator.serviceWorker.register(sw, { scope: scope, updateViaCache: 'none' }).catch(function () {});
  }
  if (document.readyState === 'complete') go();
  else window.addEventListener('load', go, { once: true });
})();
