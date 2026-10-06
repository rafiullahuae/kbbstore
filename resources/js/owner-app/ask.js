/*
 * "Allow notifications" when the installed app opens (Lane NT).
 *
 * The owner, 5 October: "the site app + owner app, apps should ask by default
 * about to allow notifications, after install when the open the app".
 *
 * What the platforms allow decides the shape. iOS (16.4+) lets a web page ask
 * for notifications only from a home-screen app and only inside a tap; Chrome
 * quietens or blocks a question nobody tapped for. So the app never asks the
 * browser by itself: after the PIN, in the INSTALLED app, while the browser
 * has neither allowed nor blocked this site, it shows its own small sheet, and
 * the browser's question is asked from the "Allow notifications" tap and from
 * nowhere else.
 *
 *   granted  nothing to ask; syncPush() keeps the subscription on each unlock.
 *   denied   never asked again: the browser forbids it, and nagging is worse.
 *   default  the sheet, unless "Not now" was tapped on this phone within
 *            ASK_AGAIN_DAYS, or Platform -> Owner app -> Settings has "Ask for
 *            notifications when the app opens" off (S.askPush, from the unlock
 *            answer the app already loads: no extra request).
 *
 * Closing the sheet any way at all (Not now, the scrim, Escape) counts as
 * "Not now": the time is written when the sheet opens. Allow then makes the
 * snooze moot, because the permission is no longer 'default'.
 *
 * Which alerts arrive is the member's own notify groups, exactly as with the
 * switch on More: the server falls back to OwnerAppEvents::DEFAULT_GROUPS for
 * a member who never chose (new orders, payments, stock).
 */
import { S, $, esc, ic, logo, sheet, toast, store, standalone } from './core.js';

/** How long "Not now" holds on this phone before the sheet may show again. */
export const ASK_AGAIN_DAYS = 7;

const DAY_MS = 864e5;
const ICON = { orders: 'receipt', status: 'edit', stock: 'stack', payments: 'alert' };
const pushable = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/** Should the sheet show now? Pure apart from what it reads. */
export function shouldAsk(now) {
  // Never over the install card (Lane IN): that card is browser-tab only and
  // this sheet installed-only, so they cannot meet; S.icOn makes it explicit.
  if (S.icOn || !S.askPush || !S.vapid || !standalone() || !pushable()) return false;
  if (window.Notification.permission !== 'default') return false;
  const t = +(store.get('oa.np') || 0);
  return !(t > 0 && (now === undefined ? Date.now() : now) - t < ASK_AGAIN_DAYS * DAY_MS);
}

/**
 * Offer the sheet if it should show. `subscribe` is store.js's syncPush,
 * passed in so this module needs nothing but core.js. Returns whether it showed.
 */
export function offerPush(subscribe) {
  if (!shouldAsk()) return false;
  store.set('oa.np', Date.now());
  const groups = (S.me && S.me.notify) || [];
  const rows = groups.filter((g) => ICON[g] && S.groups && S.groups[g])
    .map((g) => '<li><span class="tone t-acc">' + ic(ICON[g]) + '</span>' + esc(S.groups[g]) + '</li>').join('');
  sheet('', '<div class="a2-head">' + logo() + '<div><b>Allow notifications on this phone?</b><small>Hear about the shop the moment it happens, even with the app closed</small></div></div>'
    + (rows ? '<ul class="a2-list">' + rows + '</ul>' : '')
    + '<div class="btns"><button class="btn sec" type="button" data-close>Not now</button><button class="btn pri" type="button" data-allow>Allow notifications</button></div>',
  (panel, close) => {
    panel.setAttribute('aria-label', 'Allow notifications');
    $('[data-allow]', panel).addEventListener('click', () => {
      close();
      // The browser's own question: from this tap, synchronously, and nowhere else.
      window.Notification.requestPermission().then((perm) => {
        if (perm !== 'granted') return null;
        return Promise.resolve(subscribe()).then((ok) => toast(ok === false ? 'Notifications are allowed, but this phone could not be registered. Try the switch on More.' : 'Notifications on', ok === false));
      }).catch(() => {});
    });
  }, 'a2');
  return true;
}
