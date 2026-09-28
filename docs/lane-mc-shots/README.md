# Lane MC — the four enrolled settings screens, before and after

Taken in Chromium (Playwright, `/opt/pw-browsers/chromium-1194`) at **390px**
and **1280px** by `tests/browser/lane-mc-settings-screens.mjs`, against a local
checkout of this branch with the ordinary sidebar, on a store with no settings
saved — so every value shown is the shipped default.

`before-*` was taken with the five changed PHP files reverted to `HEAD`;
`after-*` with them in place. Nothing else differed between the two runs.

## What the pictures say

| Screen | Admin path | before | after |
|---|---|---|---|
| `ecommerce-product` | Store → Ecommerce → Product page | **"Rating display" drawn TWICE** — once in "Review badges", once in a "Ratings" section of its own. 11 controls. | Once. The "Ratings" section is gone. **10 controls.** Everything else is pixel-identical. |
| `review-settings` | Store → Reviews → Review Settings | 11 controls, 3 cards | unchanged |
| `cache` | Platform → Cache | 3 controls, 5 cards | unchanged |
| `rating-badge-themes` | Reviews → Rating Badge → Badge themes | 3 controls | unchanged |
| `rating-badge-capsule` | Reviews → Rating Badge → Rating capsule | 5 controls | unchanged |
| `mail` | Store → Mail | 28 controls, 5 bands | unchanged |

`document.documentElement.scrollWidth` equals `window.innerWidth` on every
screen at both widths, before and after: **390 = 390** and **1280 = 1280**, so
nothing overflows and nothing started overflowing.

## Byte comparison

Every unchanged screen's `before` and `after` PNG is byte-identical, with one
exception that is not a change: `review-settings-390` is not stable run to run
even with identical code (two consecutive `after` runs differ by 2 bytes — the
live preview of the empty-state line renders with a pixel of antialiasing
noise). `review-settings-1280` and all ten other unchanged pairs match exactly.

## How they were taken

```bash
# a scratch database and an owner, then the app on its own port
php artisan migrate --force                       # DB_DATABASE=<scratch>.sqlite
php -S 127.0.0.1:8932 -t public-web-root <router> # KBB_PUBLIC_PATH=<repo>/public
                                                  # SESSION_DRIVER=file CACHE_STORE=file

PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers \
MC_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
MC_BASE=http://127.0.0.1:8932 MC_EMAIL=... MC_PASSWORD=... MC_TAG=after \
node tests/browser/lane-mc-settings-screens.mjs
```

The console's `#content` is what scrolls, not the document, so the capture opens
the viewport HEIGHT to 2600px after the numbers are measured at the real one.
The WIDTH is never touched — 390 and 1280 are what was asked for and what was
rendered.
