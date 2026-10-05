/*
 * K-Beauty Bliss: the app is switched OFF (App -> Site App).
 *
 * A phone that installed the app still has the previous worker registered,
 * and browsers check /sw.js for a new version on navigation. This is what
 * they find: it takes over, deletes every cache the shop's worker made,
 * unregisters itself and handles no request at all on the way out. The next
 * page load runs with no worker, exactly as before the app existed.
 */
'use strict';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const k of await caches.keys()) {
      if (k.startsWith('kbb-')) await caches.delete(k);
    }
    await self.registration.unregister();
  })());
});
