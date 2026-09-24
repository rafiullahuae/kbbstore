# Lane G — the 34 re-read, and the three defects the re-reading found

`docs/rtl-audit.md` §13 is the write-up. This directory is the evidence.

## The shot this lane owed

`rtl-390-drawers-closed-<page>.jpg` — every off-canvas panel **closed**, in
Arabic with the mirrored layout on, at 390×844. That is the specific way this
class of change breaks: convert a panel's inset without its `translateX` and the
drawer sits on screen in its closed state. `ltr-390-drawers-closed-<page>.jpg`
is the same page in English, so "closed" can be compared rather than asserted.
The measured insets and `document.documentElement.scrollWidth` for each are in
§13.6.

## The fixes, before and after

| file | what it shows |
|---|---|
| `fix-badge-BEFORE-rtl-390.jpg` | the product page's discount badge still on the physical left in Arabic, printed on top of the wishlist heart (both at x≈35) |
| `fix-badge-rtl-390.jpg` | the badge at 304.1..355, the mirror of its English position; `fix-badge-ltr-390.jpg` is English, unchanged |
| `fix-slider-BEFORE-rtl-390.jpg` | the Arabic hero after one click of ▸: empty, 0% of every slide inside the frame |
| `fix-slider-rtl-390.jpg`, `fix-slider-rtl-1280.jpg` | slide 2 fully in frame, as in English (`fix-slider-ltr-*.jpg`) |
| `fix-dots-BEFORE-ltr-390.jpg`, `fix-dots-BEFORE-ltr-1280.jpg` | the home slider's dot rail displaced by half its width **in English**, the first dot half off the phone's left edge |
| `fix-dots-ltr-390.jpg`, `fix-dots-ltr-1280.jpg`, `fix-dots-rtl-390.jpg` | the rail centred, in both directions |
| `fix-mnav-x-rtl-1280.jpg` | the mobile nav's ✕ at 996..1028, the mirror of English's 252..284, after the inline `margin-left:auto` is outranked |

## How they were produced

A seeded SQLite database, the application served by `php -S` from a web root of
its own (the app's public path is a different directory from the application
root on this host), with `language_ar_enabled` and `language_rtl_enabled` both
on. **The built bundles are what is served, not the sources** — the sources were
rebuilt with `npx vite build` first, because `package.json` has no `build` script
and an unbuilt stylesheet edit is invisible to the page. The "BEFORE" shots come
from a second server on another port whose web root holds `HEAD`'s bundles, so
the only difference between the two is the stylesheet.

Chromium 1194 at `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` via
Playwright, `browser.newContext({viewport})` — not `page.setViewportSize` —
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, animations and transitions
frozen by an injected stylesheet. Panels are opened by adding the class the
site's own JS adds; the closed shots add nothing at all, which is the point.
