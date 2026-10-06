/*
 * "Install KBB Owner" — the card at the top of My store (Lane IN, 6 Oct).
 *
 * The owner: "for owner app, when login first time, and if app is not
 * installed, it should give immidiately install option to install the app".
 * So the moment the dashboard draws after a sign-in in a BROWSER TAB, a
 * full-width card sits above everything else on it. It replaces the pop-up
 * sheet that used to open by itself once (oa.a2); More -> Add to home screen
 * still opens the step-by-step sheet on request.
 *
 * WHAT A PAGE CAN AND CANNOT DO (what decides the card's three shapes):
 *   - Chrome / Edge on Android hand the page an install offer
 *     (beforeinstallprompt) once the app is installable AND Chrome's
 *     engagement rule is met (a tap on the site at some point and about 30 s
 *     on it), and never while the app is already installed on that phone.
 *     With the offer, "Install app" calls prompt() FROM THE TAP — prompt()
 *     only works inside a user gesture — and the browser's own install box
 *     opens: one tap, one confirm, nothing else.
 *   - Android before the offer arrives: the card stays, says "⋮ -> Install
 *     app", and "Show me" points an arrow at Chrome's menu. The moment the
 *     offer arrives the card redraws with the direct "Install app" button.
 *   - iPhone / iPad: there is NO install API. Safari's Share -> Add to Home
 *     Screen is the only way, and no page can open it. The card says so in
 *     one line and "Show me" points an arrow at Share (bottom bar on iPhone,
 *     ••• on iOS 26, top right on iPad).
 *
 * WHEN: not running installed (standalone), not installed on this phone as
 * far as Chrome can tell (getInstalledRelatedApps, Android Chrome 84+ —
 * the owner-app manifest names itself under related_applications), and
 * "Not now" not tapped on this phone within NOT_NOW_DAYS (oa.ic). A laptop
 * gets it only when Chrome has an offer to show.
 *
 * NEVER WITH THE NOTIFICATIONS SHEET (Lane NT): that sheet asks only inside
 * the installed app, and this card never shows there; S.icOn says the card is
 * up, and ask.js's shouldAsk() stays quiet while it is. Install first, then
 * the installed app asks about notifications when it opens.
 *
 * Nothing here measures layout; the arrow is position:fixed CSS and one
 * 6-second timer, cleared by any tap.
 */
import { S, ic, logo, store, standalone, isIOS } from './core.js';

/** How long "Not now" keeps the card away on this phone. */
export const NOT_NOW_DAYS = 7;

let offer = null, installed = false, arrow = null, arrowT = 0;
const ua = navigator.userAgent || '';
const android = /Android/i.test(ua);

/* The browser's install offer, held for the card and for More's button. */
export const bip = () => offer;
export function takeBip() { const o = offer; offer = null; return o; }

window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); offer = e; redraw(); });
window.addEventListener('appinstalled', () => { offer = null; installed = true; redraw(); });
if (!standalone() && typeof navigator.getInstalledRelatedApps === 'function') {
  navigator.getInstalledRelatedApps().then((l) => { if (l && l.length) { installed = true; redraw(); } }).catch(() => {});
}

/** Should the card show now? Pure apart from what it reads. */
export function wanted(now) {
  if (standalone() || installed) return false;
  if (!offer && !isIOS && !android) return false;
  const t = +(store.get('oa.ic') || 0);
  return !(t > 0 && (now === undefined ? Date.now() : now) - t < NOT_NOW_DAYS * 864e5);
}

/* [the one line, where the arrow points] — the browser button's PHYSICAL place. */
function where() {
  if (isIOS) {
    if (/CriOS|FxiOS|EdgiOS/.test(ua) || !/iPhone|iPod/.test(ua)) return ['Tap ' + ic('share') + ' <b>Share</b> at the top, then <b>Add to Home Screen</b>', 'tr'];
    const v = /Version\/(\d+)/.exec(ua);
    return v && +v[1] >= 26
      ? ['Tap <b>•••</b> below, then ' + ic('share') + ' <b>Share</b> → <b>Add to Home Screen</b>', 'br']
      : ['Tap ' + ic('share') + ' <b>Share</b> below, then <b>Add to Home Screen</b>', 'bc'];
  }
  if (/SamsungBrowser/.test(ua)) return ['Tap <b>≡</b> below, then <b>Add page to → Home screen</b>', 'br'];
  return ['Tap <b>⋮</b> at the top, then <b>Install app</b>', 'tr'];
}

/** The card's markup, or '' — and S.icOn says which. Every word is a constant. */
export function card() {
  S.icOn = wanted();
  if (!S.icOn) return '';
  const head = '<div class="ic-top">' + logo('sm') + '<div><b>Install KBB Owner</b><small>Full screen in one tap · order alerts on this phone · locked with your PIN</small></div></div>';
  const go = offer
    ? '<button class="btn ic-go" type="button" data-ic-go>' + ic('homeadd') + 'Install app</button>'
    : '<button class="btn ic-go" type="button" data-ic-show>' + ic('up-r') + 'Show me</button>';
  return '<section class="card ic" data-ic aria-label="Install KBB Owner">' + head
    + (offer ? '' : '<p class="ic-hint">' + where()[0] + '</p>')
    + '<div class="ic-btns"><button class="btn ic-later" type="button" data-ic-later>Not now</button>' + go + '</div></section>';
}

/* The offer arrived, or the app got installed: every card on screen redraws in place. */
function redraw() {
  document.querySelectorAll('[data-ic]').forEach((el) => {
    const html = card();
    if (html) el.outerHTML = html; else el.remove();
  });
  if (installed || offer) unarrow();
}

function unarrow() {
  if (arrowT) clearTimeout(arrowT);
  arrowT = 0;
  document.removeEventListener('pointerdown', unarrow, true);
  if (arrow) arrow.remove();
  arrow = null;
}

/** The arrow at the browser's button, for 6 s or until any tap. */
function point() {
  unarrow();
  arrow = document.createElement('i');
  arrow.className = 'ic-ar ic-ar-' + where()[1];
  arrow.setAttribute('aria-hidden', 'true');
  arrow.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 20V5M5.5 11.5 12 5l6.5 6.5"/></svg>';
  document.body.appendChild(arrow);
  arrowT = setTimeout(unarrow, 6000);
  // Registered from inside this tap, so its own pointerdown has gone by.
  document.addEventListener('pointerdown', unarrow, true);
}

/** The card's taps (delegated from the dashboard). Returns whether it took the tap. */
export function cardClick(e) {
  const t = e.target.closest && e.target.closest('[data-ic-go],[data-ic-show],[data-ic-later]');
  if (!t) return false;
  if (t.hasAttribute('data-ic-later')) {
    store.set('oa.ic', Date.now());
    S.icOn = false;
    unarrow();
    const c = t.closest('[data-ic]');
    if (c) c.remove();
    return true;
  }
  if (t.hasAttribute('data-ic-go')) {
    const o = takeBip();
    // The browser's own install box, from this tap: prompt() needs the gesture.
    if (o) { o.prompt(); if (o.userChoice) o.userChoice.then((c) => { if (c && c.outcome === 'accepted') { installed = true; redraw(); } }).catch(() => {}); }
    else redraw();
    return true;
  }
  point();
  return true;
}

