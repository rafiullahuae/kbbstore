# Lane S3 — evidence for "Old shop address with no redirect"

Taken in Chromium (Playwright, `deviceScaleFactor: 2`) against the running shop
on a migrated + seeded demo database (24 products, 6 categories, 8 brands,
7 routed pages, 10 seeded redirect rows — all ten journal articles, not one a
category), signed in as an `owner` admin.

**Where it sits: Store → SEO & Meta → SEO Audit**, last card on the page.

## The three states, measured

| Shot | Width | `clientWidth` | `scrollWidth` | Cards | Last card | Count | Pill |
|---|---|---|---|---|---|---|---|
| `before-390.png` | 390 | 390 | 390 | 12 | Product sits in no category | — | card absent |
| `before-1280.png` | 1280 | 1280 | 1280 | 12 | Product sits in no category | — | card absent |
| `legacy-card-390.png` | 390 | 390 | 390 | 13 | **Old shop address with no redirect** | **15** | amber |
| `legacy-card-1280.png` | 1280 | 1280 | 1280 | 13 | **Old shop address with no redirect** | **15** | amber |
| `covered-390.png` | 390 | 390 | 390 | 13 | Old shop address with no redirect | **0** | green |
| `covered-1280.png` | 1280 | 1280 | 1280 | 13 | Old shop address with no redirect | **0** | green |

`legacy-card-only-390.png` and `legacy-card-only-1280.png` are the card on its
own, cropped to its bounding box.

`document.documentElement.scrollWidth === clientWidth` at both widths in all
three states, so the card introduces no horizontal scrollbar. Measured on the
card itself: 362 × 1275 px at 390, 996 × 697 px at 1280; heading 13px, the same
`font-size` every other card heading on the screen already uses. No row
overflows its own box at either width (`row.scrollWidth > row.clientWidth` is
false for all fifteen) — the existing `flex-wrap: wrap` on the sample row does
the work, and nothing in this change touched the stylesheet.

## Rule 1 — the verdict line is byte-identical, measured

The one number and the one sentence an owner reads first are the same string in
all three states:

```
45 indexable URLs scanned. Biggest issue: No image (38).
```

`before` → `after` → `covered`, unchanged. `scanned` gained no key and `total`
stayed 45, because these fifteen are addresses this shop does **not** serve and
counting them would have inflated "45 indexable URLs scanned" to 60. The finding
is in the `ADVISORY` list for the same reason `product_no_image_alt` is: it is
fifteen on every freshly migrated shop, so left out of that list it would take
the headline away from *"Canonical points somewhere unsafe (1)"* on the day it
ships.

The card is declared **last** in `SeoAudit::emptyFindings()`, so every card that
was already on the screen is at the same place on the page — compare
`before-1280.png` with `legacy-card-1280.png`.

## What it found, on real rows

Fifteen, which is every entry in `App\Support\LegacyCategoryUrls::PATHS`, each
reading `no redirect set`:

```
Old address  /skincare/        no redirect set  /product-category/skincare/
Old address  /face-cleansers/  no redirect set  /product-category/face-cleansers/
… thirteen more
```

Two of those fifteen are confirmed in Google's index today, with their live
titles, which is what makes this a real finding rather than a tidy one:

| Indexed address | Title Google is showing |
|---|---|
| `kbeautybliss.com/skincare/` | "Glow Instantly with Korean Skincare Products Online \| K-Beauty Bliss" |
| `kbeautybliss.com/skincare-sets/` | "K-Beauty Bliss: Best Korean Skin Care Sets for Women in 2024" |

Both were observed in a web search on 24 September 2026. The port answers
neither — `LegacyCategoryUrls`' own docblock records why the flat root path
falls through to the article route and 404s.

`docs/CUTOVER-EXTRABEAUTY.md` §4.1 is what turns that into a loss rather than a
curiosity: forwarding `kbeautybliss.com` to `extrabeauty.ae` preserves the
**path**, so `kbeautybliss.com/skincare/` lands on `extrabeauty.ae/skincare/`,
and without a redirect row that is a 404 at the end of a 301. The host hop
carries the ranking exactly as far as the path resolves.

## The control working

`covered-*.png` is the same screen after fifteen enabled rows were written in
the spelling `App\Services\Import\RedirectMap` writes — the shape the WordPress
import produces. The count goes 15 → 0, the pill goes amber → green, and the
card prints *"None — nothing on the shop has this problem."* Nothing else on the
screen moves.

## How these were produced

A front controller under the scratchpad served the app, because
`bootstrap/app.php` pins `usePublicPath()` to the production web root and this
repo ships no `public/index.php`. Nothing in the repo was changed to take these
shots: the temporary `.env` (a scratchpad SQLite file and `SESSION_DRIVER=file`)
was restored from a copy afterwards, and the server was stopped by the PID this
lane started — never by pattern.
