/**
 * The colour/size pickers (Lane VPA): "Card A" on every grid and "Page A" on
 * the product page — the owner's choices from docs/variant-options/index.html.
 *
 * Everything either picker needs is already in the page, built once by the
 * server (App\Support\VariantCard, partials/product/variant-picker): the
 * options, their prices pre-formatted by Money::format() and their pictures
 * with img-cache srcsets. A tap is an array lookup — no request, no timer,
 * and nothing here reads a size or a position from the layout.
 *
 * Prices arrive as markup the SERVER composed (the `pricehtml` contract
 * pdp.js documents above setPrice()); every name is written with textContent.
 *
 * One delegated listener per picker kind, so cards added later by
 * listing-load.js are picked from with no extra wiring.
 */

import { setPrice } from './pdp.js';

const KEY = 'kbb.vp.';

/* The option a shopper tapped on a card travels to the product page in this
   tab only, so "View product" opens on the shade they chose. sessionStorage,
   not the URL: a `?shade=` would be a second address for every product (one
   more URL for Google, and instant navigation never prefetches a query). */
function remember(pid, vid) {
    try { sessionStorage.setItem(KEY + pid, String(vid)); } catch { /* private mode */ }
}

function recalled(pid) {
    try { return Number(sessionStorage.getItem(KEY + pid)) || 0; } catch { return 0; }
}

/* The variant whose values are exactly `choice`, or the best one carrying the
   value just tapped: in stock first, then whichever matches most of the rest. */
function resolve(rows, choice, axis) {
    let best = null;
    let score = -1;

    for (const row of rows) {
        const vals = row[1];
        if (vals.every((v, i) => v === choice[i])) return row;
        if (vals[axis] !== choice[axis]) continue;
        const s = vals.filter((v, i) => v === choice[i]).length + (row.inStock ? 100 : 0);
        if (s > score) { score = s; best = row; }
    }

    return best;
}

/* A value is sold out when no in-stock variant has it together with the
   current choice on every other axis — VariantOptions::valueOut(), mirrored. */
function valueOut(rows, axis, value, choice) {
    return !rows.some((row) => row.inStock && row[1][axis] === value
        && row[1].every((v, i) => i === axis || v === choice[i]));
}

/* ── CARD A ──────────────────────────────────────────────────────────────── */

export function initVariantCards() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest && event.target.closest('.vpc [data-v]');
        if (!button) return;

        // The dot sits over the tile's stretched link; it chooses, it never
        // navigates.
        event.preventDefault();

        const box = button.closest('.vpc');
        const card = box.closest('.kbb-tile') || box.parentElement;
        let data = box.vpcData;
        if (!data) {
            try { data = JSON.parse(box.dataset.vpc || ''); } catch { return; }
            data.v.forEach((row) => { row.inStock = row[4] === 1; });
            box.vpcData = data;
        }

        const rowsEls = [...box.querySelectorAll('.vpc-row')];
        const choice = rowsEls.map((r) => Number(r.querySelector('[aria-pressed="true"]')?.dataset.v || 0));
        const axis = Number(button.parentElement.dataset.ax || 0);
        choice[axis] = Number(button.dataset.v);

        const pick = resolve(data.v, choice, axis);
        if (!pick) return;

        rowsEls.forEach((r, i) => {
            r.querySelectorAll('[data-v]').forEach((b) => {
                const v = Number(b.dataset.v);
                b.setAttribute('aria-pressed', String(v === pick[1][i]));
                b.classList.toggle('out', valueOut(data.v, i, v, pick[1]));
            });
        });

        const cp = card.querySelector('.cp');
        if (cp) {
            cp.innerHTML = (pick[3] >= 0 ? '<span class="kbb-card-reg">' + data.p[pick[3]] + '</span> ' : '')
                + '<span class="kbb-card-price">' + data.p[pick[2]] + '</span>';
        }

        const img = card.querySelector('.kbb-card-img');
        if (img) {
            if (img.dataset.vpcSrc === undefined) {
                img.dataset.vpcSrc = img.getAttribute('src') || '';
                img.dataset.vpcSet = img.getAttribute('srcset') || '';
            }
            const [src, set] = pick[5] >= 0 ? data.i[pick[5]] : [img.dataset.vpcSrc, img.dataset.vpcSet];
            if (set) img.setAttribute('srcset', set); else img.removeAttribute('srcset');
            img.setAttribute('src', src);
        }

        if (box.dataset.pid) remember(box.dataset.pid, pick[0]);
    });
}

/* ── PAGE A ──────────────────────────────────────────────────────────────── */

export function initVariantPage() {
    const box = document.getElementById('vpa');
    if (!box) return;

    let data;
    try { data = JSON.parse(box.dataset.vpa || ''); } catch { return; }
    data.v.forEach((row) => { row.inStock = row[5] === 1; });

    const axes = [...box.querySelectorAll('.vpa-ax')];
    const varId = document.getElementById('kbbVarId');
    const stock = document.querySelector('.stockline');
    const sku = document.getElementById('vpaSku');
    const notify = document.getElementById('vpaNotify');
    const notifyId = document.getElementById('notifyVariantId');
    const buttons = [...document.querySelectorAll('.kbb-cart-form .addcart, .kbb-cart-form .buynow')];
    const gallery = document.getElementById('gthumbs');

    const apply = (pick, fromTap) => {
        axes.forEach((ax, i) => {
            let name = '';
            ax.querySelectorAll('[data-v]').forEach((b) => {
                const v = Number(b.dataset.v);
                const on = v === pick[1][i];
                b.setAttribute('aria-checked', String(on));
                b.classList.toggle('out', valueOut(data.v, i, v, pick[1]));
                if (on) name = b.dataset.n || '';
            });
            const label = ax.querySelector('.vpa-lbl b');
            if (label) label.textContent = name;
        });

        if (varId) varId.value = String(pick[0]);

        const was = pick[3] >= 0 ? data.p[pick[3]] : '';
        const off = (pick[4] || '').replace(/[&<>"']/g, (c) => '&#' + c.charCodeAt(0) + ';');
        setPrice(document.getElementById('bbPrice'), data.p[pick[2]], was, off, true);
        setPrice(document.getElementById('stickyPrice'), data.p[pick[2]], was, off, false);

        if (stock) {
            stock.classList.toggle('out', !pick.inStock);
            // The dot is the line's first child; only the words after it move.
            const dot = stock.querySelector('.dot');
            stock.textContent = '';
            if (dot) stock.appendChild(dot);
            stock.appendChild(document.createTextNode(' ' + (pick.inStock ? data.t.in : data.t.out)));
        }

        buttons.forEach((b) => {
            b.disabled = !pick.inStock;
            if (b.classList.contains('addcart')) {
                const svg = b.querySelector('svg');
                b.textContent = '';
                if (svg) b.appendChild(svg);
                b.appendChild(document.createTextNode(' ' + (pick.inStock ? data.t.add : data.t.sold)));
            }
        });

        if (sku) sku.textContent = pick[7] ? data.t.sku.replace(':sku', pick[7]) : '';

        if (notify) notify.hidden = pick.inStock;
        if (notifyId) notifyId.value = pick.inStock ? '0' : String(pick[0]);

        if (pick[6] >= 0) {
            const [src, set] = data.i[pick[6]];
            const thumb = gallery && [...gallery.querySelectorAll('.gthumb')].find((t) => t.dataset.image === src);
            if (thumb) {
                // The gallery's own tap: its warm-up, stand-in and caption
                // logic all run exactly as for a shopper's tap.
                if (!thumb.classList.contains('on')) thumb.click();
            } else {
                const main = document.getElementById('gmainImg');
                if (main) {
                    if (set) main.setAttribute('srcset', set); else main.removeAttribute('srcset');
                    main.setAttribute('src', src);
                }
            }
        }

        if (fromTap) remember(data.pid, pick[0]);
    };

    box.addEventListener('click', (event) => {
        const button = event.target.closest && event.target.closest('[data-v]');
        if (!button || !box.contains(button)) return;
        const ax = button.closest('.vpa-ax');
        const axis = axes.indexOf(ax);
        if (axis < 0) return;
        const current = data.v.find((row) => String(row[0]) === (varId ? varId.value : '')) || data.v[0];
        const choice = current[1].slice();
        choice[axis] = Number(button.dataset.v);
        const pick = resolve(data.v, choice, axis);
        if (pick) apply(pick, true);
    });

    // Arrow keys move along a radio group, as a radio group should.
    box.addEventListener('keydown', (event) => {
        if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
        const button = event.target.closest && event.target.closest('[data-v]');
        if (!button) return;
        const all = [...button.parentElement.querySelectorAll('[data-v]')];
        // Right is "next" on an English page and "previous" on /ar, where the
        // row runs right to left; up and down mean the same in both.
        let step = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : -1;
        if (document.documentElement.dir === 'rtl' && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) step = -step;
        const next = all[(all.indexOf(button) + step + all.length) % all.length];
        event.preventDefault();
        next.focus();
        next.click();
    });

    // The shade tapped on a card in this tab, if it is one of this page's.
    const wanted = recalled(data.pid);
    const row = wanted && data.v.find((r) => r[0] === wanted);
    if (row && String(row[0]) !== (varId ? varId.value : '')) apply(row, false);
}
