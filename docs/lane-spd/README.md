# Lane SPD — phone-sized banners, small review thumbnails, and the "still loading" after the page is done

Measured 18 October 2026. Before = `2bee674f` (2.60.542), after = this branch.
Both previews run the same fixture (`tools/spd-lh-seed.php`: Lane SP's live-scale
catalogue + a brand banner 2400×700, a category banner + phone picture, a page
header picture, eight reviews with 1080×1440 phone photos, the three pixels on),
SQLite, `php -S`, side by side on one machine. Fixture photos are synthetic
noise, so absolute KB are a worst case; the ratios are what matter.

## 1. The "something still loading for a second" — cause, with evidence

**Cause: the marketing pixels (Meta `fbevents.js`, Google `gtag/js`, TikTok
`events.js`, ~430 KB on a real line) were fetched the instant the product page
loaded, on a page nobody had touched.** Since 2.60.464 they are supposed to wait
for the shopper's first touch / key / scroll, or 5 s. The waiter
(`Analytics::LATE`) listens on `window` in the capture phase, so it also hears
the `scroll` of any scrolling *element* — and the product page's thumbnail
strip `#gthumbs` scrolls itself while the page loads.

* Live product page, no input at all (`tools/spd-pixel-wake.cjs`): one event,
  `scroll` on `div#gthumbs.gthumbs` at 668 ms, before load → all three pixel
  files requested at load (2616 ms).
* Live, Lighthouse with Slow-4G devtools throttling (`late-load-live-before.jpg`):
  page visibly complete at ~3.97 s, load 4.57 s, pixel files requested at
  4.56 s — the download the phone keeps doing after the page looks finished.
* Same loader text, isolated page with a self-scrolling strip
  (`tools/spd-late-probe.cjs`):

  | | untouched | with a tap |
  |---|---|---|
  | before | fired at load (605 ms) | 605 ms |
  | after  | **5606 ms** (load + 5 s, as designed) | 617 ms (a person still wakes it at once) |

**Fix (one function in one constant):** `function on(e){if(e.type==='scroll'&&e.target!==document)return;…}`
— a scroll of the page still counts, an element scrolling itself does not.
A shopper swiping the strip touches it first (`touchstart`), so nobody real is
missed. No new script, nothing measured.

Other candidates checked and ruled out with the same film (`tools/spd-film.py`):
fonts (one preloaded woff2, swap, no re-flow seen), render-blocking CSS (all
done before first paint), the large-text guard (no long task at body start),
carousels/fades (no full-area change after first paint). The only on-screen
change after the page is complete is the WhatsApp button's own animation and
its welcome bubble (designed to appear 1 s later) — the owner's chosen design,
left as is. PSI's red Speed Index is Lighthouse's formula `1.4 × observed SI`
on PSI's own slow observed load (first frames blank = time to first byte from
their test location; our live TTFB through this proxy was 1.24–1.59 s), not a
late visual change on the page.

## 2. Banners: bytes downloaded on a first view (no scrolling)

| page | 390 before | 390 after | 1280 before | 1280 after |
|---|---|---|---|---|
| brand — banner file | 738 KB original | **113 KB** (1280w) | 738 KB original | **113 KB** |
| brand — all images | 881 KB | **256 KB** | 996 KB | **370 KB** |
| category — phone picture | 427 KB original | **146 KB** (800w) | 122 KB | 122 KB |
| category — all images | 570 KB | **461 KB** | 408 KB | 408 KB |
| content page (header picture) | 731 KB original | **111 KB** | 111 KB | 111 KB |
| Journal index | 222 KB | **96 KB** | 1,579 KB | **145 KB** |
| Journal article | 222 KB | **96 KB** | 222 KB | **21 KB** |
| product | 93 KB | 93 KB | 208 KB | 182 KB |
| home (unchanged code) | 501 KB | 513 KB | 259 KB | 267 KB |

Live: `/brands/anua/` serves its 2496×664 banner as a bare `<img>`, **925 KB**, to
every phone; its 400w/800w copies were already on disk.

What changed: the brand banner now draws the category's `<picture>` with the
img-cache srcset + cover-aware `sizes`; the Pages → Page banners `<picture>`
gets srcset on both its phone `<source>` and laptop `<img>`; the Journal hero,
cards and "More" get srcset; and every phone `sizes` asks a 3× phone for a 2×
picture (the product photo's 2.60.465 rule) — without it a 3× phone needs
1,698 px for a 566-px-wide cover banner and took the original every time. LCP
banners keep `fetchpriority="high"` and are never lazy. No copies on disk →
the exact markup of before.

## 3. Review thumbnails

Scrolled into view on the product page (15 photos):

| | 390 before | 390 after | 1280 before | 1280 after |
|---|---|---|---|---|
| review photos | 1,206 KB (800w/400w full-height copies) | **158 KB** | 206 KB | **160 KB** |

Thumbnails are centred square crops at 200/400/600 px (`img-cache/c1x1/`),
made after the response that first finds them missing (never in front of a
shopper), lazy, and the click opens the existing popup, which loads the
original only then (670 KB in the fixture — `review-viewer-*.jpg`). On the
narrowest phone column (a 134×160 card) the square trims a few percent off each
side compared with the full-height photo.

## 4. Lighthouse (PSI mobile profile, simulated, median of 3; category 5)

| page | perf | LCP ms | Speed Index ms | TBT ms | CLS |
|---|---|---|---|---|---|
| home | 95 → 95 | 2793 → 2860 | 1434 → 1407 | 0 → 0 | 0 → 0 |
| product | 97 → 97 | 2484 → 2480 | 1659 → 1655 | 27 → 8 | 0 → 0 |
| category | 92 → 92 | 3233 → 3306 | 1508 → 1524 | 1 → 0 | 0 → 0 |
| **brand** | **77 → 92** | **6237 → 3309** | 1511 → 1509 | 0 → 1 | 0 → 0 |
| content page | 96 → 96 | 2601 → 2603 | 1683 → 1553 | 50 → 0 | 0 → 0 |

(The preview's product page uses the default gallery, whose strip does not
scroll, so the pixel fix shows on the live page, section 1, not here.)

## 5. Server (median of 9 warm requests, two alternating passes)

| page | ms before | ms after | queries | settings-map reads |
|---|---|---|---|---|
| home | 49.2 / 57.9 | 58.5 / 55.5 | 2 → 2 | 5 → 5 |
| product | 45.2 / 44.1 | 46.5 / 42.8 | 11 → 11 | 7 → 7 |
| category | 44.1 / 43.5 | 42.4 / 44.3 | 5 → 5 | 6 → 6 |
| brand | 45.0 / 45.4 | 50.6 / 44.3 | 6 → 6 | 7 → 7 |

HTML (raw / gzip on the wire): home 209,092/35,885 → 208,820/35,855; product
220,373/38,421 → 220,748/38,461; category 107,336/21,413 → 107,648/21,443;
brand 122,425/22,630 → 123,172/22,753. No new script, stylesheet or request;
0 console errors and `scrollWidth` = width on every page at 390 and 1280.

## Pictures

`before-*/after-*` `{brand,category,page,post}-{390,1280}.jpg`,
`reviews-{390,1280}.jpg`, `review-viewer-{390,1280}.jpg` (elementFromPoint hits
the card, a real click opens the popup), `late-load-live-before.jpg`. Numbers:
`before-shots.json`, `after-shots.json`.

Harness: `tools/spd-lh-preview.sh`, `spd-lh-seed.php`, `spd-lh.mjs`,
`spd-lh-batch.sh`, `spd-lh-all.sh`, `spd-measure.cjs`, `spd-pics.cjs`,
`spd-server.cjs`, `spd-film.py`, `spd-filmstrip.py`, `spd-pixel-wake.cjs`,
`spd-late-probe.cjs`, `spd-lh-net.cjs`.
