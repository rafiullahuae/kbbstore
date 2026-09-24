# The desktop nav bar dragged the whole shop into horizontal scroll

Lane G, round 3. `tests/Feature/NavBarContainsItsOwnOverflowTest.php` is the
guard and carries the full reasoning; this directory is the evidence.

## The defect

`.mbar .wrap` was a `flex-wrap: nowrap` flex row with `overflow-x: visible`.
Flex items shrink only to their **min-content** width, and `.navlink` is
`white-space: nowrap`, so past that floor the bar stopped shrinking and pushed
the **page** wider — nothing clipped it, scrolled it or wrapped it. `.mbar` is
`display:none` under 1000px, so every desktop width was exposed.

Chromium at 900px tall, sixteen top-level entries, identical on `/`, `/shop/`,
`/new-in/`, `/best-sellers/` and `/super-sale/`:

| viewport | `documentElement.scrollWidth` | `clientWidth` | |
|---|---|---|---|
| 390 | 390 | 390 | contained — `.mbar` is hidden, the mobile nav has it |
| 1024 | **1459** | 1024 | the shop scrolls sideways by **435px** |
| 1080 | **1459** | 1080 | by **379px** |
| 1120 | **1459** | 1120 | by **339px** |
| 1280 | **1459** | 1280 | by **179px** |

`before-16-1280.jpg` is the picture: the last entry sliced mid-word at the right
edge, the product grid running off the page with it. Lane S measured the same
mechanism on the owner's real menu at `scrollWidth` 1347 against 1280.

## The two candidates, both built and measured

| | page contained | mega panels | shipped 12-entry menu |
|---|---|---|---|
| `overflow-x: auto` | yes, at every width | **0.3% visible — 1px of 319.2**, and clipped at every width | one row, 45.5px, nav-fit untouched |
| `flex-wrap: wrap` alone | yes, at every width | untouched | **3 rows, 93px against 45.5px** |
| `flex-wrap: wrap` + honest nav-fit | yes, at every width | untouched | one row, 45.5px, unchanged |

Measured on the shipped twelve-entry menu, `overflow-x: auto` leaves the bar
alone — one row, 45.5px, scale 0.720/0.763/0.793/0.921 at 1024/1080/1120/1280 —
and clips the mega panel at every one of those widths. It is the panels, not the
bar, that rule it out.

**Why not `overflow-x: auto`.** A scroll container cannot keep
`overflow-y: visible` — the other axis computes to `auto` with it. The mega
panels are `position: absolute` children **inside** `.mbar .wrap`, 319.2px tall
under a 45.5px bar, so they are clipped to nothing.
`rejected-overflow-auto-dropdown-gone.jpg` is that measurement: hovering
"Brands" draws the underline and **no panel at all**. The navigation was
destroyed to save the scrollbar. This is a fact about *this* bar rather than
about bars in general — `.catbar .wrap`, one rule above, uses `overflow-x: auto`
correctly, because its children are plain links with nothing hanging off them.

**Why `flex-wrap: wrap` needed one more thing.** `nav-fit.js` shrinks the bar so
the items fit one row, and it asked `wrap.scrollWidth > wrap.clientWidth`. Both
halves were wrong in ways that cancelled out while nothing could wrap:
`scrollWidth` is **clamped** to `clientWidth`, and `clientWidth` includes the
bar's own padding while flex wraps against the content box. Measured on the
shipped twelve-entry menu at 1280 with wrapping suspended: `scrollWidth` 1280
against `clientWidth` 1280 — "it fits" — while the items and gaps came to
**1242.3** against a content box of **1236**. Six pixels over, invisible,
because the row bled into the 22px padding gutter. Under `wrap` that six pixels
costs a whole second row, which is why the declaration alone put the shipped
menu on three rows.

## After

`after-16-1280.jpg` — sixteen entries on **two rows**, every entry legible and
reachable, page contained. `after-16-1024.jpg` is the same at 1024.

What it costs the shipped twelve-entry menu (`before-12-1280.jpg` against
`after-12-1280.jpg`): **nothing that moves**. Bar height 45.5px at every width,
before and after, so there is no vertical shift and no layout-shift change. One
row before, one row after. Only `--nav-scale`:

| viewport | before | after | nav font |
|---|---|---|---|
| 1024 | 0.736 | 0.729 | |
| 1120 | 0.819 | 0.800 | |
| 1280 and wider | 0.936 | 0.919 | 12.17px → **11.95px** |

A fifth of a pixel, and it is the correct size — the old one overran its content
box. The scale is flat from 1280 upward because `.wrap` is `max-width:1200px`,
so the bar is always being fitted to the same box whatever the viewport.

`before-16-390.jpg` and `after-16-390.jpg` are byte-identical: `.mbar` is
`display:none` there and the mobile nav is untouched.

## How they were produced

A seeded SQLite database served by `php -S` from a web root of its own, with a
router script that returns false for files that exist — without it every
request, including `/build/assets/*.css`, goes to the front controller and the
browser refuses the stylesheet for its MIME type. The **built** bundles are what
is served, so `npx vite build` and a re-copy into the web root precede every
measurement, and the server is restarted with them because Laravel memoises the
manifest per process.

Chromium 1194 via Playwright, `browser.newContext({viewport})`,
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, everything off `127.0.0.1`
blocked, animations and transitions frozen, and 400ms allowed for `nav-fit.js`,
which runs on a timer after resize and again after `document.fonts.ready`.

**The menu was widened in the PREVIEW DATABASE ONLY**, never in the repo seed:
the sixteen-entry case is the twelve shipped entries plus four with the kind of
long labels the owner already uses, added so the min-content floor lands above
1280 the way Lane S measured it. How many entries the menu should carry, and how
long the labels should be, is the owner's question and is edited at
**Appearance → Header**. This lane changed neither.
