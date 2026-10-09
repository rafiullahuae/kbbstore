/**
 * ONE BEACON PER OPENED PAGE.                                     (Lane AN)
 *
 * The owner: "track live visitors, live visits pages list, keywords and much
 * more, MUST BE SUPER LIGHTENING without load to the server."
 *
 * One navigator.sendBeacon to /api/viewed after the page has loaded (or as
 * the page is left, if that comes first), and nothing else: no cookie, no
 * timer, no listener per element. A prefetched page runs no script, and a
 * prerendered one waits for `prerenderingchange`, so only pages a shopper
 * really opened are counted (App\Support\InstantNav says why prefetch).
 *
 * It also carries the two product counts that used to be requests of their
 * own: "Recently viewed" for a page fetched ahead (the `kbb-vl` meta the
 * server prints on such a page) and "Most viewed" (fbt.js hands its id over
 * with addToHit()). So a product page that sent up to three requests sends one.
 *
 * What it sends: the path, the title (120 characters), the referrer's DOMAIN
 * only, utm_source / medium / campaign, WHICH click id the address had (never
 * its value), the language, a new-session flag and the minute the session
 * began. The server allowlists and caps every one (App\Services\Analytics\
 * Tracker). In localStorage, never a cookie: when this browser's session
 * began (`kbb_as`), and the first and last non-direct arrival (`kbb_at`:
 * domain, utm fields, click type, landing path, day) for the order's source.
 * Nothing that identifies anybody. A signed-in administrator sends no page
 * view at all (the `kbb_ah` hint cookie), and the server checks again.
 */
import { hasAdminHint } from './admin-hint.js';

const SESSION_MS = 30 * 60 * 1000;
const extra = {};
let sent = false;

/** Ride on this page's beacon. False once it has gone: send your own. */
export function addToHit(key, value) {
    if (sent) return false;
    extra[key] = String(value);
    return true;
}

function read(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return undefined; }
}

function write(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch { return false; }
}

function clickType(q) {
    if (q.has('gclid') || q.has('gbraid') || q.has('wbraid')) return 'g';
    if (q.has('ttclid')) return 't';
    if (q.has('msclkid')) return 'm';
    if (q.has('fbclid')) return 'f';
    return '';
}

/** The fields of one page view. Exported for the test that reads them. */
export function hitFields(loc, doc, now) {
    const q = new URLSearchParams(loc.search);
    let ref = '';
    try { ref = doc.referrer ? new URL(doc.referrer).hostname.toLowerCase() : ''; } catch { ref = ''; }
    if (ref === loc.hostname.toLowerCase()) ref = '';

    const min = Math.floor(now / 60000);
    const day = Math.floor((now - new Date(now).getTimezoneOffset() * 60000) / 86400000);
    const utm = (k) => (q.get('utm_' + k) || '').slice(0, 100);
    const f = {
        p: loc.pathname.slice(0, 300),
        t: (doc.title || '').slice(0, 120),
        r: ref,
        us: utm('source'), um: utm('medium'), uc: utm('campaign'),
        ck: clickType(q),
        l: /^ar/i.test(doc.documentElement.lang || '') ? 'ar' : 'en',
    };

    // A browsing session: 30 minutes since this browser's last page.
    const as = read('kbb_as');
    const fresh = !as || typeof as.s !== 'number' || now - (as.t || 0) > SESSION_MS;
    if (as === undefined) {
        // No storage at all: an outside arrival starts one; the server keeps
        // one session per visitor per day for the rest.
        f.n = ref || f.us || f.ck ? '1' : '0';
    } else {
        const s = fresh ? min : as.s;
        write('kbb_as', { s, t: now });
        f.n = fresh ? '1' : '0';
        f.ss = String(s);
    }

    if (f.n === '1') {
        const cur = { r: ref, s: f.us, m: f.um, c: f.uc, k: f.ck, p: f.p, d: day };
        const at = read('kbb_at') || {};
        if (!at.f) at.f = cur;
        if (ref || f.us || f.ck) at.l = cur;
        write('kbb_at', at);
        f.af = JSON.stringify(at.f);
        if (at.l) f.al = JSON.stringify(at.l);
    }

    return f;
}

export function initHit() {
    const K = window.KBB;
    if (!K || !K.csrf || !navigator.sendBeacon) return;

    const go = () => {
        if (sent) return;
        sent = true;

        const body = new FormData();
        const vl = document.querySelector('meta[name="kbb-vl"]');
        if (vl && vl.content) extra.id = vl.content;

        const admin = hasAdminHint(document.cookie);
        if (admin && !extra.id && !extra.pv) return;

        body.append('_token', K.csrf);
        Object.keys(extra).forEach((k) => body.append(k, extra[k]));
        if (!admin) {
            const f = hitFields(location, document, Date.now());
            Object.keys(f).forEach((k) => body.append(k, f[k]));
        }
        navigator.sendBeacon((K.base || '') + '/api/viewed', body);
    };

    const arm = () => {
        if (document.readyState === 'complete') setTimeout(go, 0);
        else window.addEventListener('load', go, { once: true });
        // A shopper who leaves before `load` is still one page view.
        window.addEventListener('pagehide', go, { once: true });
    };

    if (document.prerendering) document.addEventListener('prerenderingchange', arm, { once: true });
    else arm();
}
