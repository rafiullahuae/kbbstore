# Lane BT — phones set to large text see the normal shop

Admin: **Appearance → Site layout → Large phone text → "Phones set to large text"**
(ships at *Show the normal shop*, as the owner asked; *Let the phone enlarge the
text* prints nothing and is the shop byte for byte as before).

## The mechanism (read in Chromium 141.0.7390.37 source, then reproduced)

| step | source |
|---|---|
| WebView text size = 100 × Android `Configuration.fontScale` by default ("By default, scale the text size by the system font scale factor") | `android_webview/java/.../AwSettings.java` |
| layout algorithm not TEXT_AUTOSIZING (the default) → `SetTextZoomFactor(text_size_percent / 100)` | `android_webview/browser/aw_settings.cc` |
| computed font-size = specified × zoom × `frame->TextZoomFactor()`, unconditionally; `text-size-adjust` only read when autosizing is on | `third_party/blink/.../font_builder.cc` |
| a px / calc line-height × `TextZoomFactor` too | `style_builder_converter.cc` (`ConvertLineHeight`) |

So `-webkit-text-size-adjust:100%` (already in the shop) cannot stop it and no
CSS property or meta tag can. That is why the integrator's two emulations
(text autosizing ± TextSizeAdjustImprovements) rendered the shop normal.

**Emulation, fidelity:** `tools/bt/renderer-gdb.sh` runs every Chromium 141
renderer under gdb and calls the real `blink::LocalFrame::SetTextZoomFactor(Z)`
on each document as it attaches — the same Blink function WebView's
`WebFrameWidgetImpl::SetTextZoomFactor` calls. Same Blink code path, not a
lookalike; what is not emulated is WebView's browser-side plumbing (Instagram's
own app code). Measured at 1.6: a 16px line → 25.6px, a 20px line-height →
32px, width:100px stays 100px, a 50px image stays 50px, `padding:1em` stays
10px, em media queries answer the same. Matches the customer's screenshot
(`large-text-*-before`).

## What was built

* `resources/views/partials/big-text-guard.blade.php` — inline, first in
  `<body>`, ~300 bytes. Reads its **own** computed font-size (it declares
  16px and renders nothing). Normal visitor: that read, nothing else.
  Enlarged (> 4%): `document.write`s the corrections file, parser-blocking, so
  the page never paints huge first.
* `resources/js/kbb/big-text.js` (built `big-text-*.js`, 2.1 KB gzip) — only
  ever requested by an enlarged phone. Puts the shop's own sizes back: absolute
  font-sizes and px line-heights ÷ zoom (solved in float32 so the product is
  exact), em/rem/ic lengths on other properties × zoom, var() line-heights by
  their definitions, SVG text untouched, UA em margins restored at
  `:where()` specificity, `kbb-bt` on `<html>`. A MutationObserver (no timer)
  takes later stylesheets/markup. Pinch-to-zoom untouched.

## Proof — normal visitor (plain Chromium, before = guard cut out)

* **Computed style, every property of every element** (`tools/bt-styles.cjs`):
  **0 differing** on all 36 = 9 pages (home, category, shop, brand, product,
  blog, article, cart, checkout) × EN/AR × 390/1280.
* **Pixels** (`tools/bt-proof.py`, 3 before runs × 2 after runs, full page):
  34/36 have a pixel-identical before/after pair (0 px). The two left —
  blog-en-1280 (49 px, anti-aliasing on the filter pills) and category-en-390
  (9 px, a 2-px card edge at x=17) — are spots that also differ between two
  identical runs, with 0 computed-style differences.
* **Header sweep** (`tools/bt-sweep.cjs`, 320→1024 every 20px + 1280, 40
  frames, modern / old-browser / stylesheet-blocked, EN+AR): logo, lotus and
  search row 37/37 identical, stable 37/37, scrollWidth = width 37/37
  (blocked: 20/37 before and after alike).

## Proof — enlarged visitor (real text zoom)

Full page at 390, against the same page at zoom 1 (`zoom-proof-final.json`):
every page EN+AR at 1.3 / 1.6 / 2 within **0–12 px** of a normal phone (named
noise: WhatsApp avatar ring at 66,709; 2-px card edges at x=17/23/364; 3 px at
13,875). 360/412 (product, all pages): same picture, ≤ 47 px.
110 elementFromPoint checks per zoom (burger, search, account, cart, logo,
menu opens + first link, Add to cart, checkout Complete Order): all ok.
scrollWidth = 390 everywhere (before at 2.0 the product page was 438). No
console errors. CLS max 0.0247 (blog chips built by script — normal visitors
get 0.02 there on the base tree); checkout 0.0226 vs 0.078 before.
Header sweep at 1.3 / 1.6 / 2: logo, lotus, search 37/37 equal to a normal
phone, EN+AR.

## Speed

| | before → after |
|---|---|
| server ms, queries, settings reads (product/category/brand, interleaved ×3) | within run noise; queries and settings-map reads identical |
| HTML (raw / gzip) | ≈ +260 B / ≈ +140 B per page |
| render-blocking CSS | unchanged (no CSS added) |
| normal visitor, guard script | 0.5–5 ms (one style read) ; FCP not later (4× CPU, median of 9) |
| enlarged visitor, corrections | ~40–60 ms script on a desktop CPU, once per page, before first paint |

Pictures: `large-text-<zoom>-<page>-390.png` (normal | before | after),
`large-text-1.6-product-360/412.png`, `large-text-1.6-product-ar-390.png`,
`normal-visitor-*`, `admin-large-phone-text-*.png`.
