/**
 * Desktop nav bar: shrinks font size and padding just enough that every
 * top-level item fits on one line, instead of wrapping to a second row or
 * overflowing off the edge of the screen. Only matters once a menu has
 * enough items that it might not fit — the kbeautybliss.com menu (11
 * top-level items) is exactly the case this exists for.
 *
 * Applied as a CSS custom property (--nav-scale) rather than setting
 * font-size directly in JS, so every existing size/padding rule for
 * .navlink keeps working — this only multiplies them.
 */

/**
 * The width one row of items actually needs, and the width there is for it.
 *
 * `wrap.scrollWidth` answered both questions until the bar was allowed to wrap,
 * and it was wrong in two ways that cancelled out while nothing could wrap:
 *
 *   1. It is CLAMPED to clientWidth. Measured on the twelve-item menu this shop
 *      ships, at 1280 with wrapping suspended: scrollWidth 1280 against a
 *      clientWidth of 1280 -- "it fits" -- while the items and their gaps came
 *      to 1242.3 against a content box of 1236. Six pixels over, and invisible,
 *      because the row simply bled into the 22px padding gutter.
 *   2. It includes the bar's own padding, while flex wrapping happens against
 *      the CONTENT box, which is that padding narrower on both sides.
 *
 * Neither mattered while `flex-wrap` was `nowrap`: an overrun that fits inside
 * the gutter is not visible and cannot wrap. Now that the bar wraps rather than
 * dragging the page sideways, a six-pixel overrun costs a whole second row, so
 * the measurement has to be the one the browser is actually making.
 *
 * So: the items plus their gaps, against the content box. Like for like, with
 * no padding on either side of the comparison.
 */
function rowSpace(wrap){
  const style = getComputedStyle(wrap);

  return wrap.clientWidth
    - (parseFloat(style.paddingLeft) || 0)
    - (parseFloat(style.paddingRight) || 0);
}

function rowNeeded(wrap){
  const kids = [...wrap.children];
  if(!kids.length) return 0;

  const gap = parseFloat(getComputedStyle(wrap).columnGap) || 0;

  /*
   * Wrapping is suspended for the measurement, because a bar that has already
   * wrapped reports the width of its widest ROW rather than of its one row.
   * Set and removed inside one synchronous function, so nothing can paint
   * between the two.
   */
  wrap.style.flexWrap = 'nowrap';
  const width = kids.reduce((sum, k) => sum + k.getBoundingClientRect().width, 0)
    + gap * (kids.length - 1);
  wrap.style.removeProperty('flex-wrap');

  return width;
}

function fitNavBar(){
  const wrap = document.querySelector('.mbar .wrap');
  if(!wrap) return;

  // Not visible (mobile breakpoint hides .mbar entirely) — nothing to fit,
  // and measuring a hidden element's width is meaningless anyway.
  if(wrap.offsetWidth === 0) return;

  const mbar = wrap.closest('.mbar');

  // Reset to natural size first — otherwise a previous shrink from an
  // earlier, narrower viewport would corrupt this measurement.
  mbar.style.removeProperty('--nav-scale');

  const available = rowSpace(wrap);
  let needed = rowNeeded(wrap);

  if(needed <= available) return;

  // A shrink this large means the menu genuinely doesn't belong on a
  // single row at this width — better to admit that than render it
  // illegibly small. Below this floor the header should really be moving
  // items into an overflow menu instead, which is a bigger change than a
  // font-scale script should make on its own.
  const MIN_SCALE = 0.72;

  let scale = Math.max(MIN_SCALE, available / needed);
  // A hair under 100% of the fit, not exactly 100% — sub-pixel rounding
  // differs slightly across browsers, and this is the difference between
  // "always fits" and "fits in the one browser this happened to be tested
  // in." Confirmed directly: without this margin, the tightest realistic
  // widths measured 1-2px of remaining overflow after the calculation,
  // even though nothing was visibly wrapping yet.
  scale *= 0.985;
  mbar.style.setProperty('--nav-scale', scale.toFixed(3));

  // One refinement pass: shrinking text changes wrapping and kerning
  // enough that the first estimate is sometimes a little short. A second
  // measurement at the new size catches that without looping indefinitely.
  needed = rowNeeded(wrap);
  if(needed > available){
    scale = Math.max(MIN_SCALE, scale * (available / needed) * 0.985);
    mbar.style.setProperty('--nav-scale', scale.toFixed(3));
  }
}

let navFitTimer;
function scheduleNavFit(){
  clearTimeout(navFitTimer);
  navFitTimer = setTimeout(fitNavBar, 80);
}

export function initNavFit(){
  if(!document.querySelector('.mbar')) return;
  fitNavBar();
  window.addEventListener('resize', scheduleNavFit);
  // Fonts loading late (a webfont swap) can change scrollWidth after the
  // first measurement already ran.
  if(document.fonts && document.fonts.ready){
    document.fonts.ready.then(fitNavBar);
  }
}
