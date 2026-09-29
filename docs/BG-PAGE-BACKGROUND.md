# Appearance → Page background — the colour wash, and the preview first

**The ask, verbatim.**

> "ALSO i need the whole site background like this multi colors and color
> changing time to time, but keep it light same as in screenshot, i mean the
> whole background. preview me first how it will look on our site, i need the
> live preview please."

**Nothing is switched on.** The wash ships `off`, `PageWash::css()` returns the
empty string while it is, and every storefront page is byte-identical to the one
it renders today. The deliverable here is the **preview**.

**Where it sits:** `Appearance → Page background`, in the sidebar between
*Section dividers* and *Cart panel*. It opens on its **Preview** tab, which
writes nothing. The controls are on `Appearance → Page background → Colour`,
`→ Motion` and `→ Where it applies`.

---

## 1 · What the shop's background actually is today

Measured before anything was designed, because two of the three things everyone
assumes about it are wrong.

**It is not white.** There are FOUR `body` rules in `resources/css/kbb/kbb.css`
— lines 181, 874, 1198 and 1599 — and the last one wins:

```css
body{background-color:#FDEFF3;
     background-image:var(--bg-botanical),
       linear-gradient(180deg,#FCE7EE 0,#FDF2F5 26%,#FFF7F4 55%,#FBEAF0 100%);
     background-attachment:fixed,fixed}
```

so line 874's `background:#fff` has never reached a shopper.

**And it is not one background, it is two.** `kbb-shop.css:17` and
`kbb-product.css:16` each declare `body{…background:var(--bg)…}`, they are
pushed onto `@stack('styles')` *after* `kbb.css`, and `var(--bg)` is `#fff`. So:

| page | background today |
|---|---|
| home, cart, checkout | pink botanical wash over `#FDEFF3` |
| **/shop/, a product page** | **plain white** |

Read off the rendered page, not the stylesheet:
`docs/bg-shots/measurements.json`, rows `now-home-1280` (`rgb(253, 239, 243)`,
with the botanical `background-image`) and `now-shop-1280` (`rgb(255, 255, 255)`,
`background-image: none`).

That inconsistency is probably part of what prompted the request, and switching
the wash on removes it: the wash is emitted after `@stack('styles')`, so it
outranks both.

**`background-attachment:fixed` in that rule is why this lane adds no fifth
background to `body`.** A fixed attachment repaints the whole viewport on every
scroll frame on a phone. The wash is drawn by three `position:fixed`
pseudo-element layers instead, which the compositor moves for nothing.

---

## 2 · Five pages could never have carried it

`store/blog`, `store/post`, `store/review-wall`, `store/skin-quiz` and
`store/app` each carry their **own** `<html>`, `<head>` and inline stylesheet.
None of them extends `layouts/store.blade.php` and none of them loads `kbb.css`.

So nothing that rides that layout has ever reached them — **not the wash, and
not the brand accent or the site width either**. Found by measurement rather
than by reading: with the wash included only in the layout, the journal index
rendered **byte-identically under all four treatments**, and
`tools/bg-sheet.cjs` refused to build the contact sheet, naming the row.

The wash is therefore one partial — `resources/views/partials/page-wash-css.blade.php`
— included in **six** documents. `PageWashScreenTest > it reaches every
storefront document, each exactly once` pins all six.

> **Found and not fixed:** the brand accent and the site-width settings still do
> not reach those five pages. That is a separate change to five large templates
> and belongs to whoever owns them.

---

## 3 · How it works

Three static gradient layers; the only animated property is `opacity`.

```
html::before   moment 3, always opaque, z-index -3   (the floor)
body::before   moment 1, opacity 1 → 0 → 0 → 1,  z-index -2
body::after    moment 2, opacity 0 → 1 → 0 → 0,  z-index -1
body           a flat opaque tint under all three
```

At 0% you see moment 1; at 33% moment 2; at 66% both upper layers are
transparent and the floor shows through. **Three colour arrangements out of two
animated layers, no extra DOM and no JavaScript at all.**

`html::before`, `html::after`, `body::before` and `body::after` were each
checked against every stylesheet and every Blade in the repository before three
of them were claimed. None is declared anywhere else.

**The two sliders are arithmetic, done in PHP.** `drift` pulls each moment back
towards the palette's mean by `(100 − drift)%`; `intensity` then mixes every
resulting colour towards white by `(100 − intensity)%`. Both happen before any
string is built, so the colour that lands in a gradient stop is known exactly —
which is what makes §5 a proof rather than a sample.

**Rule 5.** Every selector, property, unit and piece of punctuation is a literal
in `App\Services\PageWash`. A palette name is looked up in `PALETTES`; an
unknown one selects the shipped palette. A custom colour goes through
`Color::isValidHex()` *and* a six-digit check, and an invalid one is replaced by
the shipped default for that slot **before** any string is built — never
partially emitted, because a gradient with a stop missing is a broken
declaration rather than a safe one.

---

## 4 · The four treatments

Each varies on a different axis from the one before it, because the same
palette at four strengths is four pictures of one idea.

| | palette | cycle | travel | where |
|---|---|---|---|---|
| **A · Cream drift** | ivory → blush → lilac | 7 min | 40% | whole page |
| **B · Blossom** | peach → rose → pearl | 2 min | 100% | whole page |
| **C · Mint morning** | mint → cream → blush | 4 min | 75% | whole page |
| **D · Cool header** | lilac → sky → pearl | 3 min | 60% | **behind the header, faded out by mid-screen** |

The contact sheets are `docs/bg-shots/contact-sheet-390.png` and
`docs/bg-shots/contact-sheet-1280.png`. Each carries today's shop first, then
the four treatments across five real pages; a band showing each treatment at
0%, 33% and 66% of its own cycle; a strip of the wash **alone** at those same
three moments; and the reduced-motion, signed-out and Arabic frames.

`tools/bg-sheet.cjs` **refuses to build a sheet in which two columns are the
same picture.** That check is the reason §2 exists.

---

## 5 · Contrast — the whole cycle, not a sample

`PageWashContrastTest` enumerates the **entire reachable set**: 4 palettes × 21
drift positions × 21 intensity positions × 9 gradient stops = **15,876
colours**, and asserts every one of them.

**The bar is the shop's own background, not AA.** `#FCE7EE` is the darkest flat
background any storefront page renders today, and **no position of any slider on
this screen may go below it**. That is a stricter and more useful promise than
"meets AA", because two of the five text colours *do not meet AA against the
shop as it stands*:

| text | today, on `#FCE7EE` | verdict |
|---|---|---|
| `--ink` `#2A2228` body text | **13.11** | AA, AAA |
| `--ink-2` `#5E545A` secondary | **6.16** | AA |
| `--muted` `#8C828A` muted | **3.14** | **below 4.5 — large text only** |
| `--pink` `#E0567B` accent | **3.08** | **below 4.5 — large text only** |
| `--pink-deep` `#C13E63` | **4.30** | below 4.5 by a hair |

**That is not something this lane introduced and not something it can fix** — it
is the brand colour against a pale page. It is reported here, and the screen
shows it beside the sliders, because somebody choosing three colours of their
own should see it.

Worst case for each treatment, measured against the **darkest** of its own nine
stops:

| | body `--ink` | secondary | muted | pink | deep pink | darkest stop |
|---|---|---|---|---|---|---|
| today | 13.11 | 6.16 | 3.14 | 3.08 | 4.30 | `#FCE7EE` |
| **A** | **13.52** | 6.35 | 3.24 | 3.17 | 4.44 | `#F6EDF6` |
| **B** | **13.30** | 6.24 | 3.18 | 3.12 | 4.36 | `#FDE9ED` |
| **C** | **13.51** | 6.34 | 3.23 | 3.17 | 4.44 | `#FBECEF` |
| **D** | **13.47** | 6.33 | 3.22 | 3.16 | 4.42 | `#EFEEFB` |

Every figure is **at or above today's**. No treatment costs a point of contrast
anywhere in its cycle.

> **One candidate was changed rather than shipped**, which is what the brief
> asks for. The first draft's `cream_blush_lilac` ended in `#E9DFF6` and at full
> intensity reached body 12.04, muted 2.88, pink 2.83 — *worse than the shop
> today*. All four palettes were lightened until the invariant held. The
> mutation note in `PageWashContrastTest` names the exact revert.

---

## 6 · What it costs

Chromium, 390 × 844, page left completely idle, counters read through the
DevTools `Performance` domain. **Five passes per condition, interleaved, medians
reported** — a first single-pass run measured 121 ms/s for today's shop and 313
for the same page with the wash, and a second measured 119 and 148; the
difference between the passes was other lanes' test suites on the same machine.

| page (median of 5 × 12 s) | main-thread CPU | spread | style recalcs | layouts | script |
|---|---|---|---|---|---|
| **today — no wash** | 113 ms/s | 102 – 143 | 719 | **0** | 0 |
| A (420 s cycle) | 213 ms/s | 122 – 322 | 716 | **0** | 0 |
| B (120 s cycle) | 183 ms/s | 169 – 301 | 712 | **0** | 0 |
| D (top, 180 s) | 241 ms/s | 162 – 345 | 686 | **0** | 0 |
| B, cycle forced to 6 s | 170 ms/s | 141 – 273 | 715 | **0** | 0 |
| **B, `prefers-reduced-motion`** | **0.1 ms/s** | 0.08 – 0.2 | **0** | **0** | 0 |

**What the numbers say.**

- **No layout, ever.** Zero layouts in every condition.
- **No extra style recalculation.** ~715 in twelve seconds with the wash and
  ~719 without — the animation adds none. A main-thread opacity animation would
  add roughly one per frame, 720 over this window, and double that figure. It
  does not: the animation is not touching style.
- **No script.** There is no JavaScript in this feature at all.
- **Under reduced motion the page stops completely** — 0.1 ms/s and zero style
  recalculations, against 113 for the shop today. Which is the other finding:
  **the shop already burns ~113 ms/s of a phone's CPU continuously**, from
  animations that have nothing to do with this lane (the header marquee and the
  hero slider), and every one of them stops under `reduce`.
- The wash adds roughly **+60 ms/s** of main-thread time. Isolated further, with
  four passes each: layers hidden 131, layers present but **paused** 151, layers
  animating 185, against a 123 baseline — so about half of it is the *presence*
  of three extra full-viewport layers and about half the crossfade itself.
  Replacing the four gradients per layer with one flat linear gradient did
  **not** reduce it (219 ms/s, inside the noise), so it is layer compositing
  rather than gradient complexity.

**The honest caveat:** this is headless Chromium in a shared container with
**software rasterisation and no GPU**, which is the worst case by a wide margin
— compositing three full-viewport layers is exactly the work a GPU does for
free. Treat +60 ms/s as an upper bound on a device with no graphics
acceleration, not as a phone figure.

**If that is still too much, the lever is in the design and not in a slider:**
dropping `html::before` and letting the flat floor colour be the third moment
removes one composited layer, at the cost of the third moment being flat instead
of a gradient. It is one deleted line in `PageWash::css()`.

**A cycle measured in minutes.** The shortest the slider allows is 60 s and the
shipped value is 420 s. "Time to time" means a drift nobody catches happening,
and the contact sheet's cycle band is what shows whether each treatment
actually does that.

---

## 7 · `prefers-reduced-motion`

The two `animation` declarations are the **only** thing inside
`@media (prefers-reduced-motion: no-preference)`. Stated that way round on
purpose: under `reduce` — and under any engine that does not understand the
query — the layers keep their static opacities and the page is one still
gradient. Motion is *added* where it is welcome rather than removed where it is
not, so the failure mode of a typo is "it never moves", not "it moves for
somebody who asked it not to".

The stills are `docs/bg-shots/reduced-{a,b,c,d}-{390,1280}.png`, and the
measurement above reads `document.getAnimations()` off the animation timeline
rather than trusting the stylesheet: it is empty.

---

## 8 · It does not eat the shop

Read off computed style on every one of the 94 frames, in
`docs/bg-shots/measurements.json`. Nine surfaces, compared today-against-washed
on fourteen page/width pairs:

```
page/width pairs compared: 14   surfaces changed: 0
scrollWidth != clientWidth: 0 of 94
reduced-motion animations:  []
signed-out wash block:      false
```

| | today | with the wash |
|---|---|---|
| `header` background | `rgb(255,255,255)` | `rgb(255,255,255)` |
| `header` opacity | `1` | `1` |
| homepage section card | `rgba(255,255,255,0.94)` | `rgba(255,255,255,0.94)` |
| product card | `rgba(0,0,0,0)` | `rgba(0,0,0,0)` |
| cart drawer | `rgb(255,255,255)` | `rgb(255,255,255)` |
| journal sticky header | `rgba(255,255,255,0.92)` | `rgba(255,255,255,0.92)` |
| `document.documentElement.scrollWidth` @390 | **390** | **390** |
| `document.documentElement.scrollWidth` @1280 | **1280** | **1280** |
| body font-size | `14px` | `14px` |

> **That table was wrong twice before it was right, and both times the
> MEASUREMENT had moved rather than the page.** First, the card was found with
> four alternative selectors in one `querySelector`, which returns whichever
> matches first in DOM order — so it reported the home page's card turning
> transparent. Then the reduced-motion and signed-out sets moved from the home
> page to `/shop/` and their `page` label did not move with them, so two
> different pages shared one key and the later row overwrote the earlier. Both
> times the tell was the same: the **signed-out control**, which emits no wash
> at all, "changed" too. A control that moves is a measurement that moved.

- Every white card, panel and modal keeps its own background and its own edges.
  The wash declares nothing on any of them: three layers at **negative
  z-index**, behind everything, with `pointer-events:none` so nothing can become
  unclickable.
- **No horizontal scroll at either width.** The whole-page form is
  `position:fixed`, which cannot widen a document; the fade-out form is
  `position:absolute` with `inset-inline:0`, so its containing block is exactly
  the viewport's width.
- `inset-inline` rather than `left`/`right`: identical at zero, and it keeps
  this out of the 57 physical direction declarations Lane G is still working
  through. The `/ar/` pair is on both contact sheets.
- **Printed documents carry none of it.** The invoice, packing slip, delivery
  note and shipping label extend `invoices/document.blade.php`, which loads no
  site stylesheet and includes no partial of this lane's — asserted, not
  assumed. A storefront page sent to a printer drops the wash too, through the
  block's own `@media print` rule. **No existing `@media print` rule is
  touched.**

---

## 9 · The preview, and why it is the real shop

`?kbbwash=a|b|c|d` on an ordinary storefront URL. Three conditions, cheapest
first:

1. the parameter is present at all — a string comparison on a query value, which
   is what keeps this free on requests that do not carry it;
2. its value is a key of `PageWash::TREATMENTS`, so only this file's own
   constants can select a preset;
3. an **admin session**, on the `admin` guard.

A signed-out visitor to `extrabeauty.ae/?kbbwash=b` gets the shop exactly as it
is today: no style block, and a page byte-identical to the one without the
parameter. `PageWashTest` asserts that over HTTP, and measures that the
parameter costs such a visitor **zero extra queries**.

Not a signed URL, which was the first draft: a signed URL is a bearer token in
an address bar, and it would keep working after the owner had been handed it to
somebody and signed out.

**The preview is the real page and not a rebuild.** The two things a wash can
actually break — a white card going translucent, text losing its background —
live on the real pages, in other lanes' stylesheets, behind the real catalogue.
The screen frames five real addresses, with a real product slug read out of the
catalogue rather than invented.

---

## 10 · Running the harness

```bash
sh tools/bg-preview.sh                    # picks a free port, seeds, reports only if it answers
BG_BASE=http://127.0.0.1:<port> node tools/bg-shots.cjs
node tools/bg-sheet.cjs                   # refuses if two columns are the same picture
php tools/bg-css.php > docs/bg-shots/gradients.json
BG_BASE=http://127.0.0.1:<port> node tools/bg-perf.cjs
```

For the console screenshots and to prove the red pins go green:

```bash
python3 tools/bg-apply-blocks.py          # edits two files this lane may not ship
KBB_WP_DB=kbb_wp_bg vendor/bin/pest --compact --filter='PageWash|AdminNavAndIds\
|AdminSidebarIsCompleteAtBuild|AdminDeepLink|EverythingIsMountedOnce\
|AdminConsoleControlsAreLive|TranslationConsole|GridSectionConsoleReach'
BG_BASE=http://127.0.0.1:<port> node tools/bg-admin-shots.cjs
git checkout -- resources/views/admin/app.blade.php routes/web.php
```

`docs/BG-ADMIN-APP-BLOCKS.md` is the record: **five** edits, not three —
`LATE_NAV` and the route require joined the set when this branch was rebased
onto a console that had grown a new guard. It also names the two other lanes'
handover documents this lengthens (already updated here) and the one assertion
in another lane's test that had made `LATE_RENDERED` unextendable.

> `tools/bg-preview.sh` asks the operating system for a port rather than
> guessing one, and refuses to print a URL for a server that did not answer.
> Both are this lane's own twenty minutes: `php -S` said *"Address already in
> use"*, the script reported a URL anyway, and the next twenty minutes were
> spent reading **another lane's leaked preview** as though it were this one —
> a shop with the wrong catalogue in it and a 404 on a product that was
> certainly in the database. `tests/Support/PreviewPort.php` is the same fix for
> the suite and carries the same story.
