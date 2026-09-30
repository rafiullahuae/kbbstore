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
export function initGallery() {
    const strip = document.getElementById('gthumbs');
    const main = document.getElementById('gmain');
    if (!strip || !main) return;

    const caption = document.getElementById('gcap');

    strip.addEventListener('click', (event) => {
        const thumb = event.target.closest('.gthumb');
        if (!thumb) return;

        strip.querySelectorAll('.gthumb').forEach((t) => t.classList.toggle('on', t === thumb));

        const image = thumb.dataset.image;

        if (image) {
            /* The frame carries a real <img> now, so swapping means changing
               its src, not restyling the div. A product whose first shot is a
               placeholder has no <img> to change, so one is created the first
               time a photographed shot is chosen. */
            let img = main.querySelector('.gmain-img');

            if (!img) {
                img = document.createElement('img');
                img.className = 'gmain-img';
                img.id = 'gmainImg';
                img.decoding = 'async';
                img.width = 1000;
                img.height = 1000;
                main.prepend(img);
            }

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
            img.hidden = false;
            main.style.background = '#fff';
        } else {
            const img = main.querySelector('.gmain-img');
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
