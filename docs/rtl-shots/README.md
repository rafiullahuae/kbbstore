# T6 render check — before/after pairs

The acceptance bar for the logical-property rewrite was *byte-identical
rendering in a left-to-right document*, so this is the measurement rather than
an eyeball.

See `docs/rtl-audit.md` §8 for the result in context. This directory holds the
artefacts.

## What is here

`<viewport>-<page>-{before,after}.jpg` — the five pages the brief named (home,
category grid, product, cart, checkout) at two viewports, captured **interleaved**:
before and after for each page back to back, in the same second.

    cmp desktop-checkout-before.jpg desktop-checkout-after.jpg && echo identical

Every pair in this directory is byte-identical. They are JPEG at quality 70
rather than the full-size PNGs because 44 full-page PNGs come to 16 MB and this
repo is the durable record of a site that ships as zip packages. JPEG encoding
is deterministic, so identical source pixels still give identical files — the
`cmp` above is a real check, not a decorative one.

`render-check-results.txt` — the raw per-pair verdict for all six full-page PNG
runs (three cross, three control), across all 11 pages rather than just the five
stored here, plus the SHA-256 of every PNG in one of them so a specific pair can
be verified rather than taken on trust.

## How they were produced

Two `php -S` instances side by side against the same seeded SQLite database:

| | port | tree |
|---|---|---|
| before | 8942 | a detached worktree at `7315120`, this branch's base |
| after | 8941 | this branch |

Each has its own web root, a router script that falls through to real files, and
a **Vite manifest pointing at the source stylesheets** rather than the hashes
under `public/build`. That last part is the one that makes the comparison mean
anything: the committed build assets predate this change, so serving them would
have compared the same bytes to themselves and produced a guaranteed — and
worthless — pass.

    PHP_CLI_SERVER_WORKERS=6 SESSION_DRIVER=file KBB_PUBLIC_PATH=<webroot> \
      php -S 127.0.0.1:8941 -t <webroot> <webroot>/router.php

Capture: Chromium 1194 (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`)
via Playwright, `browser.newContext({viewport: {...}})` — not
`page.setViewportSize`, which has produced wrong-sized shots on this box —
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, animations and transitions
frozen by an injected stylesheet, `fullPage: true`. Two units are added to the
bag before the cart and checkout shots so those pages render real content rather
than an empty-bag redirect.

## Reading the result honestly

A first, *sequential* pass reported 2 of 22 pairs differing. Both were capture
noise, and the way that was established is worth repeating if anyone re-runs
this:

**Run the control.** Capture before-vs-before — the same tree against itself,
same harness, same interleaving — and compare the flake rate:

| | identical (of 22) | pairs that differed |
|---|---|---|
| cross 1 (before vs after) | 19 | `desktop-brands` 313 px Δ5, `mobile-home` 12 px Δ3, `mobile-product` 12 px Δ3 |
| cross 2 | 19 | `mobile-account` 18 px Δ15, `mobile-brands` 17 px Δ3, `mobile-shop` 12 px Δ3 |
| cross 3 | 19 | `desktop-brands` 313 px Δ5, `mobile-product` 12 px Δ3, `mobile-shop` 15 px Δ3 |
| **control 1 (before vs before)** | **19** | `mobile-brands` 12 px Δ3, `mobile-home` 17 px Δ3, `mobile-shop` 15 px Δ3 |
| **control 2** | **19** | `desktop-brands` 313 px Δ5, `mobile-home` 12 px Δ3, `mobile-wishlist` 12 px Δ3 |
| **control 3** | **19** | `desktop-brands` 313 px Δ5, `mobile-account` 18 px Δ15, `mobile-shop` 12 px Δ3 |

Same rate, same magnitudes, a different set of pages each run — and both of the
largest signatures occur in the **control**, where the two stylesheets are
byte-identical. The flake is the harness, not the change. (Δ is the maximum
single-channel difference out of 255.) The captures stored here, and one other
interleaved run, happened to come out 22/22.

Two specific causes were identified rather than waved at:

- **`desktop-product`** differs by ~1,700 px between two runs of the same tree.
  The PDP renders a live "order within 6h 10m" delivery countdown, which ticks.
  Interleaving the captures removes it.
- **`desktop-brands`** differed by 317 px at a maximum channel delta of 5 — and
  then differed by the same amount between two runs of the *after* tree against
  itself. Swapping only `kbb.css` between the two trees and re-shooting gave
  pixel-identical output, which rules this change out as the cause.

**Stronger than the screenshots**, and the evidence to trust if a future run
flakes: computed geometry was dumped for every element and every
`::before`/`::after` on all 22 page/viewport combinations — position and size to
three decimal places, all four margins, paddings, border widths and colours,
both insets, `text-align`, `float` and all four corner radii, ~10,000 nodes —
and is **identical everywhere**, with two normalisations that are documented in
`docs/rtl-audit.md` §8 and are not differences:
`getComputedStyle().textAlign` echoes the specified keyword (`start` where it
used to say `left`, same used value), and the `margin: 0 auto` readback on
`.wrap` flaps between `0px` and the used value between two runs of the same tree.

---

## The JPEGs are gone; the evidence is not

This directory shipped with twenty before/after screenshots, ten pairs, every
pair byte-identical. They were the eyeballing half of the render check and they
were 3.3 MB, which is 3.3 MB in this repository's history forever in exchange
for looking at two pictures that `cmp` had already proved identical.

`render-check-results.txt` is the half that stands alone: per-pair verdicts for
all six runs across all eleven pages, with SHA-256s, including the control runs
that establish the flake floor. It is the reason to believe the rewrite changed
nothing, and it is 204 lines.

Regenerating the pictures is a re-run of the capture script, not a loss.
