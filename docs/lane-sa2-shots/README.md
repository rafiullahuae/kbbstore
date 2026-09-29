# Lane SA2 — the "hanging photos" panel becomes a set of controls

Chromium 1194 (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`), at 390 and
1280 unless a file name says otherwise. Every number a reader would zoom in for
is in the JSON the scripts print, kept in the lane report rather than in a
picture.

Lane SA's own harness is reused whole — the shots below come out of
`tools/sa-shots.cjs`, `tools/sa-measure.cjs` and `tools/sa-admin-shots.cjs`, and
the only new piece is `tools/sa2-move.php`, which moves the panel's new controls
so the "after" pictures show sliders driving something.

## How to regenerate

```sh
sh tools/sa-preview.sh 8991          # seeds 12 members, a 3-member set, a
                                     # 12-member set, an EMPTY set, one plain
                                     # product, a basket, and Arabic + RTL
SA_BASE=http://127.0.0.1:8991 node tools/sa-measure.cjs
SA_BASE=http://127.0.0.1:8991 SA_OUT=$PWD/docs/lane-sa2-shots node tools/sa-shots.cjs
SA_BASE=http://127.0.0.1:8991 SA_OUT=$PWD/docs/lane-sa2-shots node tools/sa-admin-shots.cjs

# …then move the panel's controls and shoot the same surfaces again
php artisan tinker tools/sa2-move.php    # with the preview's env exported
SA_BASE=… SA_TAG=moved- node tools/sa-shots.cjs
```

Stop the preview by the PID in
`storage/framework/testing/lane-sa-preview/server.pid`. **Never `pkill -f`** —
six lanes share this machine.

The files are downscaled 50% and re-encoded as JPEG for the reason Lane SA's own
README gives: 75 PNGs at full size came to 35 MB on a disk this project has
already been burned by (`df -h /` read 6.2 GB free while these were taken).
6.3 MB now.

## The defect these were taken for

`before-ar-product-set-1280.jpg` is the whole of it. The panel's padding was a
PHYSICAL `padding: 12px 14px 11px 20px` shorthand while the chips and the
footing were pulled off `*-inline-start`, so on /ar the three disagreed:

| | 1280 | 390 |
| --- | --- | --- |
| chips hang past the panel (10px is the design) | **16px** | **17px** |
| footing stands past the panel (0 is the design) | **6px** | **7px** |

The footing's rule and all three money figures sat outside the blush panel on
every Arabic set page. `after-…` is the same page with four logical longhands.
English geometry is unchanged to the pixel — compare `before-product-set-*` with
`after-moved-…` at defaults in the lane report's table.

## What is in here

| file | what it shows |
| --- | --- |
| `before-product-set-*` / `before-ar-product-set-*` | the panel BEFORE, English correct and Arabic broken |
| `after-moved-product-set-*` / `after-moved-ar-product-set-*` | the panel with a dozen of the new controls moved — and both languages mirroring |
| `after-moved-product-set-12-390` | the same with a twelve-member set, which folds |
| `product-set-3 / -12 / -empty` | the buy column at the shipped defaults: an ordinary set, one that folds, one with no members (draws nothing) |
| `moved-*` | every other surface with the controls moved |
| `cart-page-*` | the cart page, popup closed and open |
| `cart-page-narrow-320/360` | the widths the fan used to push the page sideways at |
| `checkout-*` | the checkout summary; the popup opens upward here |
| `ar-*` | `/ar`, `dir="rtl"` |
| `touch-*` | a coarse-pointer device: the red cross appears, hover-to-open does not |
| `hover-opens-1280` | the popup open from HOVER alone — no click, no `is-open` class |
| `hover-pointer-on-popup-1280` | the pointer parked on the popup itself |
| `admin-set-desktop-tab` / `-mobile-tab` | the screen, both tabs |
| `admin-set-panel-card-*` | the NEW card: Desktop · Set list — the panel and the hang |
| `admin-set-mobile-panel-card-*` | its phone twin |
| `admin-set-preview-*` | the live preview, which now draws the product page's list as well as the two cart rows |
| `admin-set-unsaved-changes-*` | edits held in the buffer, the count, and the preview redrawn from them |
