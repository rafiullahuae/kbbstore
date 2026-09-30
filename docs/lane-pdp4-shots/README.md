# Lane PDP2 round 4 — Product page → Layout, and the demo tabs

Chromium, `/opt/pw-browsers/chromium`, `deviceScaleFactor: 1`, viewports
390×844 and 1280×900, against `tools/pdp-preview.sh` on port 9311.
`tools/pdp4-shots.cjs` produced every picture and every number here.

> **Every rectangle is read by the harness, from outside.** CLAUDE.md rule 4
> forbids JavaScript that measures layout in the product; measuring the product
> from a browser the harness drives is how the claim gets checked. Nothing in
> `tools/pdp4-shots.cjs` reaches a package.

---

## 1 · The product page did not move — measured, not asserted

`base-measure.json` was taken with `resources/css/kbb/kbb-product.css` and
`resources/views/store/product.blade.php` **checked out at the commit this
branch started from**, rebuilt with `npx vite build`, the built assets re-copied
into the preview webroot and every compiled view deleted. `now-measure.json` is
the same page on this branch. `default-measure.json` is the same again after
saving twelve values on the new screen and then deleting them.

Each file carries, at **both widths**: `document.documentElement.scrollWidth`,
eleven `getBoundingClientRect()` boxes and **seventeen groups of computed
styles** on the elements the thirty new custom properties reach.

```
base-measure.json  vs  now-measure.json        DIFFERENCES: 0
base-measure.json  vs  default-measure.json    DIFFERENCES: 0
```

Not "looks the same" — the same numbers, every one of them, at 390 and at 1280.
`styleBlock: false` in both: the page emits no `<style id="kbb-pdp-layout">` at
all while nothing has been moved, which is why
`StorefrontEnglishUnchangedTest` is green with no approved rule.

> **The two 1280 PNGs are byte-identical files**; the two 390 PNGs differ in
> **276 bytes out of 4,654,725**, all of them inside rows 51–72 of a 3,975-row
> page — the site header band, where the logo's anti-aliasing lands a subpixel
> apart between two captures. Both crops were opened and compared: the same
> header, the same width, the same page height. Nothing on the product page
> itself differs in either PNG, which is what the JSON diff above says with
> numbers rather than with pixels.

A sample of what stayed put, at 390:

| | |
|---|---|
| `scrollWidth` / viewport | **390 / 390** (1280 / 1280 at the other width) |
| product name | 19px / 500 / 25.84px line, box 252.41 × 25.83 |
| price | 22px / 800, box 77.59 × 25.30 |
| short description | 13.5px / 21.87px line / `max-block-size` **85.61px** / 20px above / 20px under the rule |
| trust lines | 12.5px, row gap 10px, 22px above, 20px under the rule |
| `.sec` padding | 34px / 34px |
| section heading | 22px / 600 |
| tab row | column gap 22px, 18px under it |
| tab body | 13.5px / 1.7 |

## 2 · …and it moves when a control is moved

`moved-measure.json` / `moved-page-{390,1280}.png` — twelve values off their
defaults. `styleBlock: true`, `scrollWidth` still 390 and 1280.

| control | at 390 | at 1280 |
|---|---|---|
| Product name · phone / laptop, + weight | 19px/500 → **24px/600** | 30px/500 → **38px/600** |
| Price | 22px → **28px** | 22px → **28px** |
| Short description + its line spacing | 13.5px/21.87px → **15.5px/29.45px** | same |
| …and its three-line cap, **derived** | `max-block-size` 85.61px → **118.35px** | same |
| Space above / below each dividing line | 20/20 → **34/30** | same |
| Space between the trust lines | 10px → **18px** | same |
| Space between page sections | 34px → **54px** | same |
| Section headings | 22px → **28px** | same |
| Gap between the detail tabs | 22px → **34px** | same |

The cap is the row worth reading twice: it is a `calc()` against the same two
variables, so the blurb still shows three lines at whatever size and line
spacing the owner picks, rather than clipping mid-line.

## 3 · The detail tabs

`before-*` was shot with the three columns blanked and the preview fixture's two
GLOBAL tabs deleted — i.e. the state of the owner's own shop, where those global
rows do not exist. `after-*` is the same product with the backfill migration run.

| | tab row | open panel |
|---|---|---|
| **before** | `['Description']` — one button, one panel | the short description, *"Demo product for layout testing…"* |
| **after** | `['Description', 'Ingredients', 'How to use']` | the real Description |
| **after, third tab clicked** | open index **2**, exactly **1** panel visible | *"Use as the last step at night, or before sunscreen in the morning…"* |

`scrollWidth` 390 and 1280 against those viewports in every pass.

Pictures: `before-tabs-{390,1280}.png`, `after-tabs-{390,1280}.png`,
`after-tabs-open3-{390,1280}.png`, plus the whole page in each state.

> The preview fixture (`tools/pdp-seed.php`) adds two GLOBAL product tabs,
> "Shipping & returns" and "Authenticity", which the owner's shop does not have
> — they are a fixture, not a migration. Left in place, his "before" would have
> read three tabs instead of one and the picture would have overstated what he
> already had. They were deleted from the preview database for the before pass
> and stayed deleted for the after pass, so the two are comparable.

## 4 · The screen

`admin-sections-{390,1280}.png` and `admin-{sp_page,sp_buy,ty_buy,ty_sec}-{390,1280}.png`.

The strip, read out of the rendered console at both widths:

```
Sections 17 · Spacing · Page 5 · Spacing · Buy column 8 ·
Type · Buy column 12 · Type · Sections & tabs 5
```

`document.documentElement.scrollWidth` is 390 at 390 and 1280 at 1280 on every
one of the five tabs. `admin.json` carries every row label and every value the
screen drew — 34px, 22px, 8px, 19px, 30px, 12.5px, 1.62 and the rest, which are
the numbers the stylesheet already had.
