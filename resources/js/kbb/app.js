/**
 * Store front-end entry point.
 *
 * Ported from the theme's kbb.js (568 lines) and split into modules. Deliberately
 * plain ES modules with no framework: the behaviour already exists and works, so
 * translating it line for line preserves it exactly. Rewriting it as components
 * would risk the display parity the whole port is built around.
 */

import '../../css/kbb/kbb.css';

import { initOverlay } from './overlay.js';
import { initToast } from './toast.js';
import { initSearch, initSearchStarter } from './search.js';
import { initMobileNav } from './mobile-nav.js';
import { initCart } from './cart.js';
import { initPdp, initGallery } from './pdp.js';
import { initCheckout } from './checkout.js';
import { initHome, initAccountPanel, initAccountPage, initReveal, initPasswordMeter } from './home.js';
import { initProductTabs } from './tabs.js';
import { initShop } from './shop.js';
import { initFbt } from './fbt.js';
import { initNewsletter } from './newsletter.js';
import { initWishlist } from './wishlist.js';
import { initReviews } from './reviews.js';
import { initNavFit } from './nav-fit.js';

const boot = () => {
    initOverlay();
    initToast();
    initSearch();
    initSearchStarter();
    initMobileNav();
    initCart();
    initPdp();
    initCheckout();
    initHome();
    initAccountPanel();
    initAccountPage();
    initReveal();
    initPasswordMeter();
    initProductTabs();
    initGallery();
    initShop();
    initFbt();
    initNewsletter();
    initWishlist();
    initReviews();
    initNavFit();
};

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', boot)
    : boot();

/* Extended delivery reads a time zone to guess the shopper's country when the
   host does not pass one. Set once, from a browser API that needs no permission
   and makes no request; the checkout maps it server-side. Harmless when
   Extended is off — nothing reads it. */
try {
    if (!document.cookie.includes('kbb_tz=')) {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (zone) {
            document.cookie = 'kbb_tz=' + encodeURIComponent(zone)
                + ';path=/;max-age=31536000;SameSite=Lax';
        }
    }
} catch {
    /* Old browser, or time zone unavailable. The store's own country is used. */
}
