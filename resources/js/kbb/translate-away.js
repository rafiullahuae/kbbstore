/*
 * "Browser translated the page -> open the real English page" (Lane AS).
 *
 * The owner: an Arabic page machine-translated by Chrome or Edge reads worse
 * than the English page this shop already has, so a shopper who asks the
 * browser for another language is handed the English page instead.
 *
 * Admin: Translation -> Language settings -> "Open English when the browser
 * translates the page" (ON unless switched off). The server sets
 * window.KBB.trEn on ARABIC pages only when it is on, so on an English page
 * the observer below never starts.
 *
 * WHAT IT DOES
 *  1. Arabic page: watch <html> (attributes only, one MutationObserver, no
 *     timers). Chrome's translator adds class translated-ltr / translated-rtl
 *     and rewrites lang; Edge rewrites lang. The moment lang is no longer
 *     Arabic, remember the choice (cookie kbb_lang=en, 180 days, SameSite=Lax)
 *     and location.replace() to the page's own <link rel="alternate"
 *     hreflang="en">.
 *  2. Arabic page, cookie set, shopper ARRIVED from another site (Google,
 *     WhatsApp, a typed address): the same hand-off without waiting for the
 *     translator -- the remembered choice.
 *  3. Any page, cookie set: a click on a link to an Arabic page is an explicit
 *     choice of Arabic and clears the cookie first, so it wins.
 *
 * WHAT IT NEVER DOES
 *  - Never on an English page (no trEn, lang is not "ar"): no loop is
 *    possible, because the page it lands on never runs step 1 or 2.
 *  - Never for a crawler: no server redirect at all (the HTML every bot reads
 *    is unchanged), a bot user agent is skipped, and a crawler neither
 *    translates nor keeps cookies. A prefetch runs no script.
 *  - Never measures layout; nothing but one attribute observer.
 */
const COOKIE = 'kbb_lang';
const MAX_AGE = 15552000; // 180 days
const BOT = /bot|crawl|spider|slurp|mediapartners|facebookexternalhit|whatsapp|telegram|preview|lighthouse|headless/i;

const hasEnglish = () => /(?:^|;\s*)kbb_lang=en(?:;|$)/.test(document.cookie);

const remember = (value, age) => {
    document.cookie = COOKIE + '=' + value + ';path=/;max-age=' + age + ';SameSite=Lax'
        + (location.protocol === 'https:' ? ';Secure' : '');
};

const arabicPrefix = () => String((window.KBB && window.KBB.base) || '').replace(/\/+$/, '') + '/ar';

const isArabicLink = (a) => {
    if ((a.getAttribute('hreflang') || a.getAttribute('lang') || '').toLowerCase().indexOf('ar') === 0) {
        return true;
    }

    try {
        const url = new URL(a.href, location.href);
        const prefix = arabicPrefix();

        return url.origin === location.origin && (url.pathname === prefix || url.pathname.indexOf(prefix + '/') === 0);
    } catch {
        return false;
    }
};

const isArabic = (lang) => String(lang || '').toLowerCase().indexOf('ar') === 0;

export function initTranslateAway() {
    if (hasEnglish()) {
        document.addEventListener('click', (e) => {
            const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;

            if (a && isArabicLink(a)) {
                remember('', 0);
            }
        }, true);
    }

    const root = document.documentElement;

    if (!(window.KBB && window.KBB.trEn) || !isArabic(root.getAttribute('lang'))) {
        return;
    }

    if (BOT.test(navigator.userAgent || '')) {
        return;
    }

    // The alternate's PATH on this origin: the hreflang URL is absolute on the
    // shop's configured address, and a hop to another host (www / no www)
    // would leave the remembered choice behind on this one.
    const link = document.querySelector('link[rel="alternate"][hreflang="en"]');
    let target = '';

    try {
        const url = link ? new URL(link.href, location.href) : null;
        target = url ? location.origin + url.pathname + url.search : '';
    } catch {
        target = '';
    }

    if (!target || target.split('#')[0] === location.href.split('#')[0]) {
        return;
    }

    let gone = false;
    const go = () => {
        if (gone) {
            return;
        }

        gone = true;
        remember('en', MAX_AGE);
        location.replace(target);
    };

    // 2. The remembered choice, for a shopper landing here from elsewhere.
    let fromHere = false;

    try {
        fromHere = document.referrer !== '' && new URL(document.referrer).origin === location.origin;
    } catch {
        fromHere = false;
    }

    if (hasEnglish() && !fromHere) {
        go();

        return;
    }

    // 1. The translator. Checked once now (it may already have run), then on
    //    every change to <html>'s class or lang until it fires.
    //    The page is Arabic, so ANY translation of it is into another
    //    language: Chrome's class alone is enough, and so is lang leaving "ar".
    const translated = () => !isArabic(root.getAttribute('lang'))
        || /\btranslated-(?:ltr|rtl)\b/.test(root.className);

    if (translated()) {
        go();

        return;
    }

    const watch = new MutationObserver(() => {
        if (translated()) {
            watch.disconnect();
            go();
        }
    });

    watch.observe(root, { attributes: true, attributeFilter: ['class', 'lang'] });
}
