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

---

# Round two — the three §13.5 left open

`docs/rtl-audit.md` §14 is the write-up. `r2-` is this round.

## The change an owner can see

A still cannot show motion, so these are caught **mid-transition** — the next
arrow clicked, then photographed a quarter of the way through the 0.55s ease,
with the slide half in frame and the edge it is entering from plainly visible.

| file | what it shows |
|---|---|
| `r2-travel-BEFORE-rtl-390.jpg` | Arabic, the hero advancing with round one's CSS fix: slide 2 at 339.4..705.4 of a frame at 12..378 — **entering from the RIGHT**, the English direction, in a document read from the right |
| `r2-travel-rtl-390.jpg` | the same moment after the sign flip: slide 2 at -297..69 — **entering from the LEFT**, the side an Arabic reader is reading towards |
| `r2-travel-BEFORE-rtl-1280.jpg`, `r2-travel-rtl-1280.jpg` | the same pair at 1280: 1160.1..2362.9 becomes -1082.9..119.9 |
| `r2-travel-ltr-390.jpg`, `r2-travel-ltr-1280.jpg` | English, unchanged — slide 2 at 339.4..705.4, entering from the right, exactly as before |

## The shot this lane owes every round

`r2-rtl-390-drawers-closed-<page>.jpg` — every off-canvas panel **closed**, in
Arabic with the mirrored layout on, at 390×844, on this round's tree. Nothing is
clicked and no class is added; that is the point. The numbers are in §14.5 and
they are the trunk's exactly: `.drawer` -300.3..0 on all four pages, `.mnav`
403.3..668.4 or 390..690, `.filtercol` 390..690 on the shop, and
`documentElement.scrollWidth` 390 against a `clientWidth` of 390 everywhere.
Round one's `ltr-390-drawers-closed-*.jpg` are the English pages beside them and
are unchanged, so they are not duplicated here.

## How they were produced

As round one, with three differences that mattered more than they sound:

- **The trunk, this branch and a SECOND copy of the trunk** are served on three
  ports, so a difference between two processes can be told from a difference
  between two trees. Without the third server the home page's rails looked like
  a regression and were a `Cache::remember` warmed from each tree's own seed.
- **One seeded database, copied to all three.** The demo catalogue randomises
  prices, so independently seeded databases disagree about `-30%` and `-31%`.
- **Everything off `127.0.0.1` is refused** and `html{overflow-y:scroll}` is
  injected before measuring. The webfonts failing at their own pace moved when
  `networkidle` fired, and the scrollbar gutter flipped `.wrap`'s `margin:0
  auto` between 0px and 50px — six differing rows between two runs of the same
  tree, before either was taken away.

`php -S` gets a router script that returns false for files that exist;
without it every request, including `/build/assets/*.css`, goes to the front
controller and the browser refuses the stylesheet for its MIME type — which
looks like a broken layout and is a broken harness.
