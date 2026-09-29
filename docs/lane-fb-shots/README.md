# Lane FB — the flag bar, photographed and measured

The thin strip above the header: UAE flag, one short line, Korean flag.
**Appearance → Header → Flag bar.**

Every number below was measured in Chromium (Playwright, the pinned build) against
a preview of this branch. Reproduce with:

```sh
sh tools/fb-preview.sh 8978                 # boots a throwaway copy on :8978
FB_CHROME=/path/to/chrome node tools/fb-shots.cjs 390  phone-on   /
FB_CHROME=/path/to/chrome node tools/fb-shots.cjs 1280 desktop-default /
FB_CHROME=/path/to/chrome node tools/fb-shots.cjs 390  ar-phone   /ar/
FB_CHROME=/path/to/chrome node tools/fb-admin-shots.cjs 1280
sh tools/fb-set.sh fb_desktop 1             # and 0 again afterwards
kill $(cat storage/framework/testing/lane-fb-preview/server.pid)
```

## The pictures

| File | What it shows |
| --- | --- |
| `phone-on-390.png` | the shop at 390px as the package leaves it — the strip, then the header |
| `narrow-320.png` / `narrow-320-strip.png` | 320px, the narrowest phone still in use — the width where the line used to truncate |
| `phone360-360.png` / `phone360-360-strip.png` | 360px, the commonest Android width |
| `phone-on-390-strip.png` | the strip at its own pixels, English |
| `phone-off-390.png` | the same page with both switches off: the header at y=0, unchanged |
| `desktop-default-1280.png` | the desktop shop, as the package leaves it — **no strip** |
| `desktop-switched-on-1280-strip.png` | what turning "Show it on desktop" on looks like |
| `ar-phone-390.png` | `/ar/` at 390px — the pair mirrored |
| `ar-phone-390-strip.png` | the Arabic strip at its own pixels |
| `admin-flagbar-1280.png` | Appearance → Header → Flag bar, the eleven controls |

## The numbers

| | 390 · English | 390 · `/ar` | 1280 · default | 1280 · desktop on |
| --- | --- | --- | --- | --- |
| `<html dir>` | ltr | **rtl** | ltr | ltr |
| strip in the DOM | yes | yes | yes | yes |
| computed `display` | block | block | **none** | block |
| strip box | 0,0 **390 × 30** | 0,0 **390 × 30** | 0 × 0 | 0,0 **1280 × 30** |
| `<header>` starts at y | 30 | 30 | **0** | 30 |
| UAE flag | x **22** | x **347** | — | x **22** |
| Korea flag | x **347** | x **22** | — | x **1237** |
| flag box | 21 × 14 | 21 × 14 | — | 21 × 14 |
| wording | UAE's Authentic K-Beauty Store | متجر الإمارات للجمال الكوري الأصلي | — | — |
| text size | 12px | 12px | — | 12px |
| text colour | rgb(224, 86, 123) | same | — | same |
| `documentElement.scrollWidth` | **390** | **390** | **1280** | **1280** |
| `clientWidth` | 390 | 390 | 1280 | 1280 |

`scrollWidth === clientWidth` at every width in both languages: the strip gives
the page no horizontal scroll.

### The narrow phones, where the first measurement was a defect

| | 320 | 360 | 390 |
| --- | --- | --- | --- |
| strip box | 320 × 30 | 360 × 30 | 390 × 30 |
| pill width | **235.2** | 241.2 | 241.2 |
| UAE flag x | **12** | 22 | 22 |
| Korea flag x | 287 | 317 | 347 |
| `scrollWidth` | 320 | 360 | 390 |
| CLS | 0 | 0 | 0 |
| the line | whole | whole | whole |

At 320 the shipped line (215px at 12px Poppins), two 21px flags, two 10px gaps
and a 22px gutter a side came to more than the screen, and the pill ellipsised:
the strip read **"UAE's Authentic K-Beauty…"**, a truncated claim about
authenticity. `@media (max-width:359px)` closes the gutter to 12px, the gaps to
6px and the pill's padding to 9px — **below 360 and nowhere else**, which is why
360 and 390 above are the same geometry as they were before the rule existed
(pill 241.2, flags at x=22). 320 now has 242px of room for a 235px pill.

No size the owner controls changes with the viewport. A `vw`-scaled font would
have fitted it in one line and made the Text size slider mean something
different on every phone.

**RTL is the mirror and nothing else.** The UAE flag is at x=22 in English and
x=347 in Arabic — 390 − 22 − 21 = 347, the same distance from the reading edge.
Korea mirrors with it. There is no `[dir]` rule anywhere in the strip's CSS;
this comes out of the source order and a flex row.

## Cumulative layout shift, with the strip and without it

Collected from a `PerformanceObserver` on `layout-shift`, armed with
`addInitScript` **before the first byte of the document** so that shifts during
load are counted — a strip whose height arrives late is exactly the shift that
would be missed by a script that starts after `load`.

| | with the strip | with both switches off |
| --- | --- | --- |
| 390 · home | **0** | **0** |
| 1280 · home | **0.85251** | **0.85251** |

Identical to five decimal places at both widths. The strip costs nothing,
because `min-height:var(--kfb-h,30px)` is declared in `kbb.css` — loaded
render-blocking from `<head>` — so the box is its full height at first paint
and the header below it never moves.

**The 0.85251 at 1280 is not this strip and is not new.** It is one shift, and
it is the same single shift with the strip removed from the document entirely.
Its reported sources are `A.navlink`, `MAIN` and `SECTION..ec` — the desktop
category bar and everything under it — so the desktop home page has carried a
CLS of 0.85 all along, which is well past Google's 0.1 "good" threshold. **Found
and not fixed: it belongs to the nav bar, not to this lane, and touching it is
the sort of change that has to be asked for rather than smuggled in beside a
strip.**

## Where the strip does not appear, and why

31 of the 39 storefront pages the render walk covers. The eight without it are
the quick-view fragment, the checkout and the order-received page (both declare
`bare`), Laravel's own 404 document, and the four standalone documents — the
Journal, an article, the quiz and the review wall — which include neither
`partials.header` nor `partials.mobile-chrome` and so have no site header for a
strip to sit above. **Found and not decided: whether those four should grow one
is the owner's call.**

## One thing the admin screen does not draw

The live preview panel on the right of Appearance → Header shows the header
mock without the strip above it. That mock is hand-written inside
`resources/views/admin/app.blade.php`, which this lane may not edit — the
controls, their values and their help text are all drawn from
`HeaderSettings::TABS` and are correct. Adding the strip to the mock is a
four-line change to that file whenever somebody who owns it is in there.
