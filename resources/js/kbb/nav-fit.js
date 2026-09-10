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

  const available = wrap.clientWidth;
  let needed = wrap.scrollWidth;

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
  needed = wrap.scrollWidth;
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
