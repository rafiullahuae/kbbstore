# Lane PS — "You may also like" as a carousel, before and after

Chromium (Playwright, `/opt/pw-browsers/chromium-1194`) at **390px** and
**1280px**, by `tests/browser/lane-ps-also-like.mjs`, on a scratch SQLite shop
seeded with `php artisan db:seed` (24 demo products, 8 brands, 6 categories).
The product is `heartleaf-77-soothing-toner` (Round Lab, Sunscreens).
`before-*` is the tree at 5cb6865, served from a separate worktree against the
same database; `after-*` is this branch. Every number below is read by the
script from outside the page — the page's own JavaScript reads no geometry.

| | before | after |
|---|---|---|
| cards | 3 (category only, a grid that wraps) | 12 (Round Lab, Sunscreens, Round Lab, Sunscreens, … then best sellers) |
| card width @1280 | 232.8px | **232.8px** (5 in view) |
| card width @390 | 168px | **168px** (2 in view, the third peeks in) |
| card height @1280 / @390 | 416.8 / 352px | 416.8 / 352px |
| track scrollWidth @1280 / @390 | 1236 / 346 | 2992 / 2170 (scrolls inside itself) |
| `documentElement.scrollWidth` | 1280 / 390 | **1280 / 390** — no sideways page scroll |
| Next arrow @1280 | — | scrollLeft 0 → 1003 (four cards, snaps on a card edge) |
| swipe to the end @390 | — | scrollLeft 1780, Next greys, Previous lights |
| Arabic (RTL) @1280, Next | — | scrollLeft 0 → −1003 (right-to-left, correct way) |
| keyboard | — | track focusable (`tabindex=0`, `role=region`); ←/→ scroll it natively |

Files: `before-product-{390,1280}.png`, `after-product-{390,1280}.png`,
`after-product-{390,1280}-scrolled.png`, `after-product-ar-*.png`,
`after-admin-product-page-ymal-{390,1280}.png` (Appearance → Product page →
You may also like), `after-admin-editor-picker-{390,1280}.png` (Catalog →
Products → edit → You may also like, two picks added), and both
`*-report.json`.

The demo shop has no category parents, so the "category tree" top-up is not
visible here; AlsoLikeCarouselTest covers it with a real parent.
