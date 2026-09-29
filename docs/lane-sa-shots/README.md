# Lane SA — Appearance → Set, the evidence

Chromium 1194 (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`), at 390 and
1280 unless a file name says otherwise, with the measured numbers for every shot
printed by the scripts that took them.

## How to regenerate

```sh
sh tools/sa-preview.sh 8971          # seeds 12 members, a 3-member set, a
                                     # 12-member set, an EMPTY set, one plain
                                     # product, a basket holding all of them,
                                     # and Arabic + the RTL mirror
node tools/sa-measure.cjs            # the BEFORE numbers: row heights, the
                                     # divider-to-name gap on a set row and on
                                     # the plain rows either side
node tools/sa-shots.cjs              # the storefront, at the shipped defaults
SA_TAG=moved- node tools/sa-shots.cjs   # …and again with controls moved
node tools/sa-admin-shots.cjs        # the screen itself, both tabs
```

Stop the preview by the PID in
`storage/framework/testing/lane-sa-preview/server.pid`. **Never `pkill -f`** —
three lanes share this machine and it has already cost another lane a full
re-run.

## Why these are .jpg and half size

The scripts write full-size PNG at `deviceScaleFactor: 2`, which came to 36 MB
for 61 files — five times what any other lane's shot folder holds, on a disk
this project has already been burned by (CLAUDE.md's "a full disk looks exactly
like a transaction bug"). They are downscaled 50% and re-encoded here, which is
6.6 MB and the same size as `docs/lane-set-shots`. Everything a reader has to
judge — where a control landed, whether the cross is on the right corner, what
wrapped — is legible at that size, and every NUMBER anybody would zoom in to
read is in the scripts' own JSON output and in this lane's report rather than in
a picture. Re-run the commands above for the originals.

## What is in here

| file | what it shows |
| --- | --- |
| `cart-page-*` | the cart page at the shipped defaults, popup closed and open |
| `cart-page-narrow-320/360` | the widths the page used to scroll sideways at |
| `checkout-*` | the checkout summary; the popup opens upward here |
| `product-set-3 / -12 / -empty` | the buy column's list: an ordinary set, one that folds, and a set with no members at all (draws nothing) |
| `ar-*` | `/ar`, `dir="rtl"` — the fan, the popup and the close button all mirror |
| `touch-*` | a coarse-pointer device: the red cross appears, hover-to-open does not |
| `touch-close-ltr / -rtl` | the close button on the popup's own corner in both directions |
| `hover-opens-1280` | the popup open from HOVER alone, no click, no `is-open` class |
| `hover-pointer-on-popup-1280` | the pointer parked on the popup itself — the bridge holding it open |
| `moved-*` | the same surfaces with a dozen controls moved, so the sliders are shown driving something |
| `admin-set-desktop-tab` / `-mobile-tab` | the screen |
| `admin-set-unsaved-changes` | five edits held in the buffer, the count, and the live preview redrawn from them |
