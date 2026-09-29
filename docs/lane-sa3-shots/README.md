# Lane SA3 — Appearance → Set becomes two columns, eight sections and a live preview

The owner, verbatim:

> on this set backend controls page, i want the preview on the right side, so i
> can avoid the long page. also make the tabs or sections properly, not just
> throw the long page. make it super nice. and preview must work in real time
> upon changing controls.

Chromium 1194 (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`), at **1280
and 1680** because it is an admin screen, and at **390** because he reviews on a
phone. Downscaled 50% and re-encoded as JPEG for the reason Lane SA's README
gives: 40 PNGs at full size came to 15 MB on a disk this project has already
been burned by (`df -h /` read 3.6 GB free while these were taken). 2.9 MB now.

## How to regenerate

```sh
sh tools/sa-preview.sh 8993          # seeds 12 members, a 3-member set, a
                                     # 12-member set, an EMPTY set, one plain
                                     # product, a basket, and Arabic + RTL
SA_BASE=http://127.0.0.1:8993 SA_OUT=$PWD/docs/lane-sa3-shots node tools/sa3-admin-shots.cjs
SA_BASE=http://127.0.0.1:8993 SA_OUT=$PWD/docs/lane-sa3-shots node tools/sa3-guards.cjs
SA_BASE=http://127.0.0.1:8993 node tools/sa3-width-check.cjs

# the "before" column of the table below
git show <base>:resources/views/admin/partials/set-appearance-screen.blade.php \
  > /tmp/old.blade.php
cp /tmp/old.blade.php resources/views/admin/partials/set-appearance-screen.blade.php
rm -f storage/framework/views/*.php
SA_BASE=… SA_MEASURE_ONLY=1 SA_TAG=before- node tools/sa3-admin-shots.cjs
# …and put the file back.
```

Stop the preview by the PID in
`storage/framework/testing/lane-sa-preview/server.pid`. **Never `pkill -f`** —
six lanes share this machine.

`tools/sa3-admin-shots.cjs` does **not** inject the partial the way Lane SA's
and Lane SA2's scripts did. `app.blade.php` carries the include now and
`routes/web.php` requires the route file, so injecting ran the screen twice —
two `window.go` wrappers and two delegated `input` listeners, the first copy's
`draft` still `null`, throwing `Cannot set properties of null` into the console
on every slider while appearing to work. Measured here before it was removed.

## "Avoid the long page", measured

`#content`'s `scrollHeight` with the screen freshly opened and nothing scrolled
(`before-whole-*.jpg` / `after-whole-*.jpg`):

| viewport | before | after | |
| --- | ---: | ---: | --- |
| 1280 | **6 747 px** | **2 405 px** | −64 % |
| 1680 | **5 764 px** | **2 131 px** | −63 % |
| 390 | **13 534 px** | **4 144 px** | −69 % |

Controls on screen at once went from **132** (every Desktop control, in 10
cards) to **25** (the open section). The preview used to be the ninth card down,
roughly 5 000 px below the first slider; it is now pinned at the top of the
right-hand column and stays there — `position:sticky; top:66px`, no scroll
listener and nothing measured in JavaScript.

`document.documentElement.scrollWidth` equals the viewport at **390, 1280 and
1680**: no horizontal overflow at any of them.

| | 1280 | 1680 | 390 |
| --- | ---: | ---: | ---: |
| controls column | 550 px | 721 px | 362 px |
| preview column | 430 px | 648 px | 362 px, and **first** in the flow |
| preview sticky | yes | yes | no — one column, so it leads instead |
| frame height | 650 px | 650 px | 430 px |

The preview column is wide enough to be honest: the buy column it is judging is
582 px at 1280 and 346 px at 390, and the frame is drawn at its true pixel width
(the stage scrolls) rather than being shrunk to fit.

### …which is a fix, not a preference

The frame was `max-width:100%` inside a flex stage, so it was silently clamped
to the panel. `tools/sa3-width-check.cjs`, in a **396 px** stage:

| button | frame width | the frame's own `window.innerWidth` | set box branch | list branch |
| --- | ---: | ---: | --- | --- |
| Phone · 390 | 390 px | 390 | phone | phone |
| Tablet · 760 | 760 px | 760 | phone | laptop |
| Desktop · 1280 | 1280 px | 1280 | **laptop** | laptop |

Before the fix all three resolved at ~396 px, so "Desktop · 1280" drew the phone
branch under a label that said Desktop.

## The eight sections

`desk-<id>-1280.jpg` and `desk-<id>-1680.jpg`, one per section, plus
`desk-panel-390.jpg` for the phone.

| section | Desktop controls | Mobile controls |
| --- | ---: | ---: |
| What is drawn | 25 | — (shared, set on Desktop) |
| The set row on the cart page | 5 | 5 |
| The fanned stack and its popup | 43 | 25 |
| The panel and the hang | 13 | 9 |
| Rows and photographs | 7 | 6 |
| Words | 20 | 8 |
| The fold and the footing | 19 | 10 |
| Where the phone sizes start | — | 3 |

132 + 66 = **198**, and `SetAppearanceScreenGroupsTest` pins that the placement
is total and has no duplicates. Every shot reports `ungrouped: 0`.

Desktop/Mobile stays above the section strip. `mob-*.jpg` is every one of the
Mobile tab's seven sections at 1680, plus `mob-where-390.jpg`.

## "Real time", measured

`live-before-1680.jpg` and `live-after-1680.jpg` are **24 `input` events apart**
— two sliders dragged through their full travel — and the number of requests to
`/admin-api/set-appearance/preview` between them is **0**.

| | before | after |
| --- | --- | --- |
| `--ksl-pr` (panel corner radius) | `18px` | `40px` |
| `--ksl-over` (the hang) | `10px` | `32px` |

`live-structural-1680.jpg` is the other path: switching *Show each member's
photograph* off is a `display:none` rule, not a custom property, so it
re-renders — **1** request, debounced, after the click.

`guard-popup-open-1680.jpg` is the clearest single proof the two paths are
different. The popup in the frame is opened, then a slider is dragged nine
times, **released** (`change`), a different section is opened, and the
Desktop/Mobile tab is switched twice. `.kset-pop.is-open` is **1 before and 1
after** all of it.

That took a second change to get right. Every redraw used to be one `innerHTML`
over `#content`, which threw the iframe away and rebuilt it from the last
document the server sent — invisible while everything re-rendered anyway, and
fatal to the point of the live path: the popup shut on every mouseup. The
controls and the preview column are now drawn by separate functions, and the
preview column is left alone unless the frame's **width** or **language**
changes or the server really re-renders.

## The set row, and the row beside it

`cart-rows-before-1680.jpg` / `cart-rows-after-1680.jpg`. *Set row padding, top*
and *…bottom* dragged from 11 to 34:

| | before | after |
| --- | --- | --- |
| `.ci.ci-set` padding | `11px 14px` | **`34px 14px`** |
| `.ci` (ordinary) padding | `11px 14px` | **`11px 14px`** |

## العربية

`arabic-panel-1680.jpg` and `arabic-panel-390.jpg`. The preview document used to
carry `lang="en" dir="ltr"` written in, and its controller had no locale at all.
It renders `<html lang="ar" dir="rtl">` now — the whole view inside the locale,
so the wording is the wording an Arabic shopper reads, not English text pushed
to the other side.

`dir` follows `Locale::direction()`, which is gated on `language_rtl_enabled`.
With Arabic on and that switch off the preview draws `dir="ltr"` and says so in
a strip at the top of the document, because that is what the shop serves.

The stage itself takes the preview's direction. Without that, a 760 px frame in
a 648 px panel opens scrolled to its empty left margin with every Arabic word
off the right-hand edge.

## The guards the re-layout had to keep

`guard-bar-counts-1680.jpg`, `guard-after-discard-1680.jpg`,
`guard-after-save-1680.jpg`, and the JSON `tools/sa3-guards.cjs` prints:

| | |
| --- | --- |
| five controls moved | bar reads **“5 unsaved changes · 5 settings moved from shipped”** |
| leaving by the sidebar | `confirm` — *“You have 5 unsaved change(s)… Press Cancel to go back”*, and Cancel really stays on Set |
| “Put this group back to shipped” | `confirm` naming the section and the count; 6 waiting → 5 |
| Discard | `confirm` *“Put back 5 change(s)?”*, then **“No unsaved changes”** |
| Save | writes, bar goes quiet, **“1 setting moved from shipped”** stands |
