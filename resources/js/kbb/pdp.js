/**
 * Product page behaviour.
 *
 * Selectors follow the ported markup exactly — .variant (a div, not a button),
 * data-q for the stepper, #qtyVal / #qtyInput, and the form submit rather than a
 * click on the button. Writing to the theme's own hooks is what keeps the page
 * working with the theme's CSS unchanged.
 *
 * Rule 27: one delegated listener rather than one per variant or thumbnail, and
 * IntersectionObserver instead of a scroll handler for the sticky bar.
 */

import { addToCart } from './cart.js';
import { t } from './i18n.js';
import { escapeHtml } from './safe.js';

export function initPdp() {
    const form = document.querySelector('.kbb-cart-form');
    if (!form) return;

    const qtyVal = document.getElementById('qtyVal');
    const qtyInput = document.getElementById('qtyInput');
    const varId = document.getElementById('kbbVarId');

    document.addEventListener('click', (event) => {
        /* The gallery is NOT handled here. This listener used to carry a
           thumbnail branch of its own that read `thumb.dataset.img` -- an
           attribute the markup has never emitted; it is `data-image`. So the
           branch resolved to the string "undefined", set the main frame to
           `url('undefined')`, and because it is bound to `document` it ran
           *after* initGallery's own handler had already set the frame
           correctly, overwriting it. Clicking any thumbnail blanked the
           photograph to white. Removed rather than repaired: initGallery below
           owns the gallery, and two handlers for one click is how this
           happened. */

        /* A sold-out option answers, rather than absorbing the tap.
           Declining silently is indistinguishable from a page that has stopped
           responding: the row does not highlight, the price does not move, and
           nothing says why. The row already carries a "Sold out" tag, but a
           shopper who has just pressed it is looking for a reaction, not a
           label they have evidently already missed. */
        const dead = event.target.closest('.variant.oos');
        if (dead) {
            const name = dead.querySelector('.vn')?.textContent?.trim();
            window.kbbToast?.(name
                ? t('store.js.variant_sold_out_named', ':name is sold out — please choose another option.', { name })
                : t('store.js.variant_sold_out', 'That option is sold out.'));
            return;
        }

        // Option selection — a variant, or a quantity bundle.
        const variant = event.target.closest('.variant');
        if (variant && !variant.classList.contains('oos')) {
            document.querySelectorAll('.variant').forEach((v) => v.classList.toggle('on', v === variant));

            if (varId) varId.value = variant.dataset.vid || '';

            /* A bundle row carries the quantity it represents. Selecting the
               3-pack sets the quantity to 3, so the stepper and the button
               agree with the price shown. */
            const qty = Number(variant.dataset.qty || 0);
            if (qty > 0 && qtyInput && qtyVal) {
                qtyInput.value = qty;
                qtyVal.textContent = qty;
            }

            /* Write into the .now span rather than replacing the whole block.
               Replacing it dropped the span, and with it the larger price
               styling — which is why the size jumped when a bundle was picked. */
            /* THE DEFECT: `dataset.price` is Money::plain() -- TEXT, not
               markup -- and it carries the `currency_symbol` SETTING, which
               the admin stores as free text ('text' in AdminController's
               field map). Blade prints it through `{{ }}`, and THAT ESCAPE IS
               UNDONE BEFORE THIS LINE EVER SEES IT: the HTML parser decodes
               the entities while it builds the attribute, so `dataset.price`
               hands back the live characters and setPrice() writes them
               straight into innerHTML. Measured in node: a symbol containing
               `<img src=x onerror=...>` comes back out of the attribute byte
               for byte. CLAUDE.md rule 5 -- anything printed unescaped is a
               constant, never a setting.

               `pricehtml` stays raw BY NAME: it is the branch that means "the
               server composed this as markup", which is what Money::format()
               returns. Nothing emits that attribute today, so escaping it
               would be wrong for the day something does. */
            const price = variant.dataset.pricehtml || escapeHtml(variant.dataset.price || '');
            /* THE STRUCK FIGURE AND THE BADGE TRAVEL WITH IT.
               Escaped exactly as `price` is and for the same reason: both cross
               out of a data attribute, whose contents the HTML parser has
               already decoded, so neither may be trusted as markup. */
            const was = escapeHtml(variant.dataset.was || '');
            const off = escapeHtml(variant.dataset.off || '');
            /* `true` FOR THE BLOCK AND `false` FOR THE BAR, and the caller is
               where that decision belongs -- this file's own rule, stated above
               setPrice(). The block is the one that has to agree with the row
               beneath it; the bar carries a `.now` and nothing else by design
               and must not grow a strike it never had. */
            setPrice(document.getElementById('bbPrice'), price, was, off, true);
            setPrice(document.getElementById('stickyPrice'), price, was, off, false);
            return;
        }

        // Quantity.
        const step = event.target.closest('[data-q]');
        if (step && qtyVal && qtyInput) {
            const next = Math.max(1, Math.min(99, Number(qtyInput.value || 1) + Number(step.dataset.q)));
            qtyInput.value = next;
            qtyVal.textContent = next;

            // Keep the highlighted bundle in step with the stepper, so the two
            // controls never disagree about what is being bought.
            document.querySelectorAll('.variant[data-qty]').forEach((v) => {
                v.classList.toggle('on', Number(v.dataset.qty) === next);
            });
        }
    });

    // Add to cart / Buy it now both submit the form; the difference is where we
    // go afterwards. Intercepting submit keeps the page working without JS.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const buyNow = event.submitter?.dataset.buynow === '1';
        const button = event.submitter || form.querySelector('.addcart');
        if (button) button.disabled = true;

        try {
            // Through the cart module rather than a second copy of the request.
            // The old version posted, announced a custom event nothing listened
            // for, and opened the panel without applying the HTML that came
            // back — so a product added here never appeared in the panel.
            // Variants and quantity bundles both ride on this: the option the
            // shopper picked is already in the hidden variant field and the
            // stepper, and both are read here.
            const data = await addToCart({
                product_id: Number(form.dataset.product_id),
                variant_id: varId ? Number(varId.value) || null : null,
                quantity: Number(qtyInput?.value || 1),
            });

            if (!data || data.ok === false) {
                window.kbbToast?.(data?.error || t('store.js.add_failed_short', 'Could not add that.'));
                return;
            }

            if (buyNow) {
                window.location.href = window.KBB.routes.checkout;
                return;
            }
        } catch {
            // Network failure — fall back to a real submit so the customer is
            // never stuck on a button that silently does nothing.
            form.removeEventListener('submit', () => {});
            form.submit();
        } finally {
            if (button) button.disabled = false;
        }
    });

    // Sticky bar. The class here has to be `show`: kbb-product.css styles
    // .stickybar.show and nothing else, so toggling `on` — as this did until
    // 2.39.0 — left the bar permanently translated off screen even once the
    // markup was restored.
    const bar = document.getElementById('stickybar');
    const add = form.querySelector('.addcart');

    if (bar && bar.dataset.trigger === 'offset') {
        const after = Number(bar.dataset.offset || 200);
        const onScroll = () => bar.classList.toggle('show', window.scrollY > after);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    } else if (bar && add && 'IntersectionObserver' in window) {
        new IntersectionObserver(
            ([entry]) => bar.classList.toggle('show', !entry.isIntersecting),
            { rootMargin: '-80px 0px 0px 0px' }
        ).observe(add);
    }
}


/**
 * Update a price block without losing its markup.
 *
 * Ledger's block is `<s>was</s> <span class="now">…</span>
 * <span class="off">-30%</span>`, and ALL THREE follow the row that was
 * pressed: the tier's own struck figure and its own percentage, or the
 * product's where the tier has none of its own. Leaving the outer two alone --
 * which is what this did until round 3 -- printed the product's 25% beside a
 * tier discounted 6%.
 *
 * `mayCreate` says whether this element is allowed to GROW the two spans when
 * the server did not render them. The caller decides; see the note inside.
 */
function setPrice(el, html, was, off, mayCreate) {
    /* `html` IS markup here and is meant to be -- the caller decides. The one
       caller that passes a value out of a data attribute escapes it first; see
       the comment above `const price` in initPdp(). The same is true of `was`
       and `off`. */
    if (!el || !html) return;

    const now = el.querySelector('.now');
    if (now) { now.innerHTML = html; } else {
        // No .now span (a product that is not on sale) — keep the structure by
        // creating one rather than flattening the block to bare text.
        el.innerHTML = '<span class="now">' + html + '</span>';
    }

    /* ── THE STRUCK FIGURE AND THE BADGE FOLLOW THE ROW TOO.
                                                           (Lane PDP2 round 3)

       THE DEFECT THIS CLOSES, measured in a browser: pressing the 2-pack left
       the block reading `AED 99 struck / AED 140 / -25%` while the row said
       `AED 149 / AED 140 / Save 6%`. The strike was untidy; the BADGE was a
       false claim about money, on the page where the shopper decides.

       ▲ `mayCreate` IS THE CALLER'S, AND IT IS WHAT KEEPS THE STICKY BAR
         RIGHT. This is called on #bbPrice AND on #stickyPrice, and the bar
         carries a `.now` and nothing else -- by design, since Lane PP built it.
         Measured before this change: its `.now` already followed the tier
         (AED 74 -> AED 140) and it has neither strike nor badge, so it had
         nothing to go stale. Creating the two spans for it would have GIVEN it
         the problem while fixing it elsewhere, so it is passed `false`.

       ▲ AND THE BLOCK IS PASSED `true` BECAUSE OF THE VARIABLE PRODUCT.
         A variable parent carries no price of its own, so `isOnSale()` is false
         on it and the server renders `.now` alone -- while its VARIATIONS are
         marked down. Measured: pick the 100ml and the block read `AED 127` flat
         while the row directly beneath it read `AED 169 / AED 127`. Not a false
         claim, but the block and the row disagreeing on one page, which is the
         whole thing this change is about.

       ▲ AND THEY ARE INSERTED IN LEDGER'S ORDER -- struck figure BEFORE `.now`,
         badge after it -- because that is the order the server writes and the
         stylesheet lays out (`<s>` is display:block above the live figure). An
         element created in the wrong place would be styled correctly and read
         backwards.

       ▲ style.display AND NOT `hidden`. kbb-product.css gives both spans a
         `display` through a class selector, which outranks the User-Agent rule
         behind the `hidden` attribute -- so `hidden` would set the attribute and
         change nothing on the screen. */
    const live = el.querySelector('.now');

    let struck = el.querySelector('s');
    if (!struck && was && mayCreate && live) {
        live.insertAdjacentHTML('beforebegin', '<s></s>');
        struck = el.querySelector('s');
    }
    if (struck) {
        struck.innerHTML = was || '';
        struck.style.display = was ? '' : 'none';
    }

    let badge = el.querySelector('.off');
    if (!badge && off && mayCreate && live) {
        live.insertAdjacentHTML('afterend', '<span class="off"></span>');
        badge = el.querySelector('.off');
    }
    if (badge) {
        badge.innerHTML = off || '';
        badge.style.display = off ? '' : 'none';
    }
}


/**
 * Gallery thumbnails.
 *
 * Selecting a thumbnail swaps the main frame. Placeholder shots carry no image,
 * so their gradient and label are used instead — which is what makes the strip
 * legible before real photography exists.
 */

/* (Lane PG2) The grey loading box behind a gallery photo (kbb-product.css) is
   handed back to the frame's white once the photo is DECODED -- decode(), not
   `load`, because a picture that has loaded but not yet been decoded paints
   nothing, and taking the grey away then would flash the bare frame. Needed
   for the main photo only because it is object-fit:contain (a portrait bottle
   leaves bars that would otherwise stay grey); on a thumbnail it just stops
   the sweep early. A failed picture settles too: never an endless shimmer. */
function settle(img, then) {
    if (!img) return Promise.resolve();
    const done = () => {
        img.classList.add('ld');
        img.removeAttribute('style');
        if (then) then();
    };
    return img.decode ? img.decode().then(done, done) : Promise.resolve(done());
}

/* (Lane GX) THE IDLE WARM-UP STOPS HERE: one megabyte of photographs per
   product view, whatever the gallery holds. */
const WARM_CAP = 1048576;

/* (Lane GX) A 1x1 GIF, the stand-in file pick() offers the browser. */
const DOT = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/* (Lane GX) WHICH FILE WILL THE FRAME ASK FOR, without asking for it.

   The idle warm-up has to download with fetch(), because fetch() is the one
   request a page can really cancel: an Image() whose src is cleared stops on
   the page but NOT on the wire once the shop's service worker is in control
   (measured through a throttled proxy: 188 of 188 KB still delivered after the
   cancel, against 48 KB for an aborted fetch). But fetch() needs the URL, and
   which srcset candidate a browser takes depends on the viewport, the device
   pixel ratio, `sizes` and its own rounding rules -- guessing it here would
   download a file the frame then ignores.

   So the browser is asked: the SAME width descriptors and `sizes`, over tiny
   local blob: files instead of the real addresses. It chooses exactly as the
   frame will (checked against the frame at 360-1280px, DPR 1-3) and fetches
   only the blob. Not data: URLs -- Chromium treats those as already cached
   and always takes the widest. Resolves to '' when there is nothing to pick. */
function pick(srcset, sizes, fallback) {
    const list = srcset ? srcset.split(', ') : [];
    if (!list.length) return Promise.resolve(fallback || '');
    const dot = new Blob([Uint8Array.from(atob(DOT), (c) => c.charCodeAt(0))], { type: 'image/gif' });
    const blobs = list.map(() => URL.createObjectURL(dot));
    const probe = new Image();
    return new Promise((resolve) => {
        probe.onload = probe.onerror = () => {
            const i = blobs.indexOf(probe.currentSrc);
            blobs.forEach((u) => URL.revokeObjectURL(u));
            resolve(i < 0 ? '' : list[i].slice(0, list[i].lastIndexOf(' ')));
        };
        probe.sizes = sizes || '';
        probe.srcset = list.map((c, i) => blobs[i] + c.slice(c.lastIndexOf(' '))).join(', ');
    });
}

export function initGallery() {
    const main = document.getElementById('gmain');
    if (!main) return;

    const lcp = settle(main.querySelector('.gmain-img'));

    const strip = document.getElementById('gthumbs');
    if (!strip) return;

    strip.querySelectorAll('.gthumb-img').forEach((i) => settle(i));

    const caption = document.getElementById('gcap');
    const conn = navigator.connection;
    // Save-Data, 2G or 3G: no fetch nobody asked for. Unknown (Safari,
    // Firefox) is not lean -- today's one next-shot warm-up runs there.
    const lean = Boolean(conn && (conn.saveData || /(^|-)(2g|3g)$/.test(conn.effectiveType || '')));
    // A connection that SAYS it is 4G, and only that, gets the whole gallery.
    const good = Boolean(conn && !conn.saveData && conn.effectiveType === '4g');

    /* (Lane PG2) WARM A SHOT BEFORE IT IS TAPPED. The owner: "switching
       between the product gallery images, gives clear delays to show up the
       specific picture upon click." Measured: the frame-sized file was only
       requested on the tap, and Chrome keeps the OLD photo on screen until the
       new one has fully arrived -- 1.4s on a throttled phone with nothing
       moving. So the next shot is fetched once the page has finished loading
       (low priority, never on Save-Data or a 2G/3G connection), and any
       other shot the moment a finger or a pointer reaches its thumbnail. An
       Image() off the page with the SAME srcset/sizes picks the same file the
       frame will ask for, so the tap finds it in the cache. One file each,
       once; no timer, nothing measured.

       (Lane GX) AND IT IS DECODED once it lands (decode() runs off the main
       thread), so the tap that finds it paints it in one frame with no
       decode hitch. `ready` holds the shots whose frame-sized file is in. */
    const warmed = new Map();
    const ready = new Set();
    const warm = (thumb, low) => {
        const image = thumb && thumb.dataset.image;
        if (!image || warmed.has(image)) return null;
        const w = new Image();
        w.decoding = 'async';
        if (low) w.fetchPriority = 'low';
        w.sizes = thumb.dataset.sizes || '';
        w.srcset = thumb.dataset.srcset || '';
        w.src = image;
        warmed.set(image, w);
        return w.decode().then(() => { ready.add(image); }, () => {});
    };
    strip.addEventListener('pointerover', (event) => warm(event.target.closest('.gthumb')), { passive: true });

    /* (Lane GX) THE SHARPER STAND-IN, FETCHED ON INTENT. The owner: the 66px
       picture stretched to the frame "looks blurry for a moment" -- "display a
       grey loading instead of presenting blur". So a tap no longer shows the
       thumbnail's own file. When a finger lands on (or focus reaches) a
       thumbnail, its MID copy -- the 400px one, which the thumbnail's own
       srcset already lists -- is asked for at low priority. (The 800px copy
       was measured too: on a slow phone it arrived after 1.7 s and held the
       photograph back to 3.4 s.)
       If it is decoded by the time of the tap it is the stand-in; if it lands
       while the frame-sized file is still on its way it takes over the grey
       box then; otherwise the grey box stays until the photograph itself. Never
       on Save-Data/2G/3G, never once the frame-sized file is already in, and
       nothing before a finger asks: no request on page load. */
    const mids = new Map();
    const midFor = (thumb) => {
        const image = thumb && thumb.dataset.image;
        const small = thumb && thumb.querySelector('.gthumb-img');
        const list = small && small.getAttribute('srcset');
        if (lean || !image || !list || ready.has(image) || mids.has(image)) return;
        const mid = list.split(', ').find((c) => / 400w$/.test(c));
        if (!mid) return;
        const m = new Image();
        m.decoding = 'async';
        m.fetchPriority = 'low';
        m.src = mid.slice(0, -5);
        const entry = { url: '', wait: null };
        entry.wait = m.decode().then(() => {
            // SAFE: the browser's own serialisation of a URL this page's Blade
            // printed; one carrying a quote, a backslash or a line break is
            // refused rather than written into a style.
            if (!/["\\\n\r]/.test(m.currentSrc)) entry.url = m.currentSrc;
            return entry.url;
        }, () => '');
        mids.set(image, entry);
    };
    const intent = (event) => midFor(event.target.closest && event.target.closest('.gthumb'));
    strip.addEventListener('pointerdown', intent, { passive: true });
    strip.addEventListener('focusin', intent);

    /* (Lane GX) THE WHOLE GALLERY, QUIETLY, WHILE THE SHOPPER READS. The
       owner: "when user open the product page, then in the background silently
       load all gallery sharp pictures." Only on a connection that reports 4G
       and no Save-Data; elsewhere the single next-shot warm-up below is
       unchanged. It waits for the page's `load` AND for the main photograph
       (the LCP) to be decoded, then takes ONE shot at a time, starting with
       the next, each at low priority in an idle callback, so nothing it does
       competes with the page or with itself. It stops at WARM_CAP, and for
       good the moment the shopper heads elsewhere -- a finger or a button on
       any link (that is when instant navigation asks for the next page), the
       tab hidden, the page left -- cancelling the file in flight so it never
       shares the line with the next page. */
    let yieldTo = () => {};
    if (good) {
        let stopped = false;
        let paused = 0;
        let current = null;
        let spent = 0;
        const shots = [...strip.querySelectorAll('.gthumb')];
        const at = shots.findIndex((t) => t.classList.contains('on'));
        const queue = shots.slice(at + 1).concat(shots.slice(0, Math.max(0, at)));
        const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 1));
        // The download in flight is cancelled on the wire (see pick()), and
        // the shot may be asked for again.
        const cancel = () => {
            const c = current;
            current = null;
            if (!c) return null;
            c.ac.abort();
            return c.thumb;
        };
        const stop = () => {
            stopped = true;
            cancel();
        };
        const next = () => {
            if (stopped || paused || current) return;
            if (spent >= WARM_CAP) return stop();
            let thumb = null;
            while (queue.length && !thumb) {
                thumb = queue.shift();
                if (!thumb.dataset.image || warmed.has(thumb.dataset.image)) thumb = null;
            }
            if (!thumb) return;
            const mine = (current = { thumb, ac: new AbortController() });
            const { signal } = mine.ac;
            pick(thumb.dataset.srcset, thumb.dataset.sizes, thumb.dataset.image)
                .then((url) => url && !signal.aborted && fetch(url, { signal, priority: 'low' }))
                .then((res) => res && res.ok ? res.blob() : null)
                // Now in the HTTP cache: an Image() with the frame's own
                // srcset takes it from there, decodes it, and marks it ready.
                .then((blob) => {
                    spent += blob ? blob.size : 0;
                    return blob && !signal.aborted ? warm(thumb, true) : null;
                })
                .catch(() => {})
                .then(() => {
                    if (current !== mine) return;
                    current = null;
                    idle(next);
                });
        };
        /* A TAP COMES FIRST. The moment a finger lands on a thumbnail whose
           photograph is not in, the file warming for another shot is dropped
           (and queued again), so the tapped one has the line to itself; the
           tap then holds the warm-up until its photograph is on screen. A
           finger that lifts without tapping (a scroll) lets it carry on. */
        const makeWay = (event) => {
            const thumb = event.target.closest && event.target.closest('.gthumb');
            const image = thumb && thumb.dataset.image;
            if (stopped || !image || ready.has(image) || !current || current.thumb === thumb) return;
            const back = cancel();
            if (back) queue.unshift(back);
        };
        strip.addEventListener('pointerdown', makeWay, { passive: true });
        strip.addEventListener('pointerup', () => idle(next), { passive: true });
        strip.addEventListener('pointercancel', () => idle(next), { passive: true });
        yieldTo = (image, shown) => {
            if (stopped) return;
            if (current && current.thumb.dataset.image !== image) {
                const back = cancel();
                if (back) queue.unshift(back);
            }
            paused++;
            shown.then(() => {
                paused--;
                idle(next);
            });
        };
        const leaving = (event) => { if (event.target.closest && event.target.closest('a[href]')) stop(); };
        document.addEventListener('pointerdown', leaving, { capture: true, passive: true });
        /* A pointer RESTING on a link is when instant navigation fetches the
           next page (Speculation Rules, moderate), so the warm-up steps aside
           for as long as it rests there -- the file in flight dropped and
           queued again -- and carries on when the pointer moves off. */
        let resting = null;
        document.addEventListener('pointerover', (event) => {
            const link = event.target.closest && event.target.closest('a[href]');
            if (!link || link === resting || stopped) return;
            if (!resting) {
                paused++;
                const back = cancel();
                if (back) queue.unshift(back);
            }
            resting = link;
        }, { capture: true, passive: true });
        document.addEventListener('pointerout', (event) => {
            if (!resting || (event.relatedTarget && resting.contains(event.relatedTarget))) return;
            resting = null;
            paused--;
            idle(next);
        }, { capture: true, passive: true });
        document.addEventListener('touchstart', leaving, { capture: true, passive: true });
        document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
        window.addEventListener('pagehide', stop);
        const begin = () => lcp.then(() => idle(next));
        if (document.readyState === 'complete') begin();
        else window.addEventListener('load', begin, { once: true });
    } else if (!lean) {
        const next = () => warm(strip.querySelector('.gthumb.on + .gthumb'), true);
        if (document.readyState === 'complete') next();
        else window.addEventListener('load', next, { once: true });
    }

    strip.addEventListener('click', (event) => {
        const thumb = event.target.closest('.gthumb');
        if (!thumb) return;

        strip.querySelectorAll('.gthumb').forEach((t) => t.classList.toggle('on', t === thumb));

        const image = thumb.dataset.image;

        // A stand-in left by a tap whose photograph never arrived.
        main.querySelectorAll('.gx-s').forEach((s) => s.remove());

        if (image) {
            /* (Lane PG2) A FRESH <img> FOR EVERY SWAP. Changing the src of the
               <img> that is on screen keeps the OLD photograph painted until
               the new file has completely arrived (Chromium, measured) -- a
               tap that visibly does nothing for as long as the download takes.
               A new element has no old picture. Never the old photo.

               (Lane GX) AND WHAT STANDS IN FOR IT IS NO LONGER THE 66px
               THUMBNAIL. Measured on a throttled phone (390px, DPR 3), that
               stand-in was a 200px file stretched over 1050 real pixels -- and
               it was not even instant: as a CSS background it is fetched
               again from the disk cache, so the frame went old -> grey (150
               ms) -> blurred (350 ms) -> sharp (1.4 s). Now:
                 - the frame-sized file already in (warmed): it goes straight
                   in, decoded, in one frame -- no stand-in, no fade;
                 - otherwise a stand-in BEHIND it: the shot's mid copy if that
                   is decoded, else the gallery's own loading box (Appearance
                   -> Product styles -> Layout -> Photo loading placeholder:
                   shimmer, plain or none), which the mid copy takes over if it
                   lands first; the photograph then fades in over it (0.14 s,
                   CSS; none under reduced motion) and the stand-in goes.

               Same box (position:absolute, inset 0), same class, same id, so
               nothing moves. */
            const old = main.querySelector('img.gmain-img');
            const img = document.createElement('img');
            img.className = 'gmain-img';
            img.id = 'gmainImg';
            img.decoding = 'async';
            img.fetchPriority = 'high';
            img.width = 1000;
            img.height = 1000;

            /* SRCSET FIRST, AND IT IS SET EVEN WHEN IT IS EMPTY.

               `srcset` outranks `src`: a browser that has both picks from the
               list and never reads src again. So assigning src alone -- which
               is all this did while the main <img> carried no srcset -- would
               now leave the PREVIOUS photograph on screen, or, for a shot with
               no copies following one that has them, leave the old shot's
               candidates describing the new shot's file.

               Hence the `|| ''`: clearing is as necessary as setting. An empty
               string removes the list and hands the decision back to src,
               which is exactly what a photograph with no copies on disk wants.
               The server put one answer per shot on the thumbnail
               (partials/product-gallery.blade.php) rather than leaving this to
               guess at a URL, because whether a copy exists is a question only
               the filesystem can answer. */
            /* SAFE. These are PROPERTY assignments, not an HTML context:
               nothing is parsed, so no quote can close an attribute. The
               addresses come from this page's own Blade
               (partials/product-gallery.blade.php) rather than from a fetch,
               and a `javascript:` URL in an <img> src is inert in every
               browser -- src is fetched, never executed. Checked by the sweep
               and left alone. */
            img.srcset = thumb.dataset.srcset || '';
            img.sizes = thumb.dataset.sizes || '';
            img.src = image;
            img.alt = thumb.dataset.alt || '';
            // The frame now holds this shot: no warm-up asks for it again,
            // and once it is decoded a tap back to it is instant.
            warmed.set(image, img);
            const mark = () => { if (img.naturalWidth > 0) ready.add(image); };

            if (ready.has(image) && img.complete) {
                if (old) old.replaceWith(img);
                else main.prepend(img);
                settle(img, mark);
            } else {
                const stand = document.createElement('span');
                stand.className = 'gmain-img gx-s';
                stand.setAttribute('aria-hidden', 'true');
                img.classList.add('gx-in');
                if (old) old.replaceWith(stand, img);
                else main.prepend(stand, img);
                const mid = mids.get(image);
                const show = (url) => {
                    if (url && stand.isConnected) stand.style.cssText = 'background:#fff url("' + url + '") center/contain no-repeat;animation:none';
                };
                if (mid && mid.url) show(mid.url);
                else if (mid) mid.wait.then(show);
                yieldTo(image, settle(img, () => {
                    mark();
                    // No fade to wait for (reduced motion, or no stylesheet):
                    // the stand-in goes now, never left under the photograph.
                    if (getComputedStyle(img).transitionDuration === '0s') stand.remove();
                    else img.addEventListener('transitionend', () => stand.remove(), { once: true });
                }));
            }
            main.style.background = '#fff';
        } else {
            const img = main.querySelector('img.gmain-img');
            if (img) img.hidden = true;
            main.style.background = getComputedStyle(thumb).background;
        }

        if (caption) {
            caption.hidden = Boolean(image);

            if (!image && thumb.dataset.label) {
                /* Text nodes, not innerHTML. A brand name is operator-supplied
                   data and this file has no business turning it back into
                   markup -- the same mistake the XSS sweep took out of
                   eighteen other places. */
                const brand = caption.dataset.brand || '';
                caption.textContent = '';

                if (brand) {
                    caption.append(brand, document.createElement('br'));
                }

                caption.append(thumb.dataset.label);
            }
        }
    });
}
