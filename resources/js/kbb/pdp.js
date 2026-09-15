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
            const price = variant.dataset.pricehtml || variant.dataset.price || '';
            setPrice(document.getElementById('bbPrice'), price);
            setPrice(document.getElementById('stickyPrice'), price);
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
                window.kbbToast?.(data?.error || 'Could not add that.');
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
 * The block is `<span class="now">…</span> <s>was</s> <span class="off">-30%</span>`.
 * Only the current price changes when a bundle is selected, so only that span
 * is touched; the struck-through original and the badge stay where they are.
 */
function setPrice(el, html) {
    if (!el || !html) return;

    const now = el.querySelector('.now');
    if (now) { now.innerHTML = html; return; }

    // No .now span (a product that is not on sale) — keep the structure by
    // creating one rather than flattening the block to bare text.
    el.innerHTML = '<span class="now">' + html + '</span>';
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
