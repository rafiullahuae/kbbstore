/*
 * Full screen on phones (the owner: "the app itself expand to full screen in
 * browser by default", with YouTube's small enlarge / shrink arrows to leave
 * and re-enter it).
 *
 * WHO. A phone-sized viewport with a coarse pointer, in a browser that offers
 * element full screen (Android Chrome, Samsung Internet, Firefox). Tablets and
 * desktops keep the normal browser view. iPhone Safari has no element full
 * screen, so the icon is hidden there; from the home screen the app is
 * already full screen (standalone) and needs none.
 *
 * WHEN. ONLY when he taps the icon (2.60.401). The first build asked on the
 * first tap anywhere; the owner: "upon clicking anywhere in the owner app, it
 * keeps enabling auto the full screen mode, which is very annoying ... click
 * anywhere should not perform any full screen". The icon sits beside "Sync
 * now" in every header (fsIcon()), and on the PIN screens in their own bar.
 *
 * SMOOTH. The click handler's FIRST statement is requestFullscreen() or
 * exitFullscreen(): no await, no work, no re-render before it. Both glyphs are
 * always in the DOM and one class on <html> picks which shows, toggled on
 * `fullscreenchange`; the layout is CSS (100dvh, safe-area insets), so there
 * is no resize listener and nothing measures. A refused request is caught and
 * the class simply stays as it was. Full screen never triggers a refresh.
 */
import { standalone } from './core.js';

const root = document.documentElement;
const phone = window.matchMedia('(pointer: coarse) and (max-width: 600px)');
const can = !!(root.requestFullscreen && document.fullscreenEnabled);

const eligible = () => can && phone.matches && !standalone();
const opts = { navigationUI: 'hide' };

export const fsButton = () =>
  '<div class="fsbar"><button type="button" class="fs" data-fs aria-label="Full screen" title="Full screen">'
  + '<svg class="i g-out" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M4 4l6 6M20 15v5h-5M20 20l-6-6"/></svg>'
  + '<svg class="i g-in" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5v5H5M10 10L4 4M14 19v-5h5M14 14l6 6"/></svg>'
  + '</button></div>';

function sync() {
  const on = !!document.fullscreenElement;
  root.classList.toggle('is-fs', on);
  root.classList.toggle('can-fs', eligible());
  const label = on ? 'Exit full screen' : 'Full screen';
  document.querySelectorAll('[data-fs]').forEach((b) => { b.setAttribute('aria-label', label); b.setAttribute('title', label); });
}

export function initFullscreen() {
  sync();
  if (!can) return;

  // Capture phase, so it runs before any screen's own handler.
  document.addEventListener('click', (e) => {
    const icon = e.target.closest && e.target.closest('[data-fs]');
    if (icon) {
      if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
      else root.requestFullscreen(opts).catch(() => {});
    }
  }, true);

  document.addEventListener('fullscreenchange', sync);
  phone.addEventListener('change', sync);
}

export { sync as syncFullscreen };
