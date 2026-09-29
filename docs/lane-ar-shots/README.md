# Lane AR — what `/ar` actually renders, in all three of its states

`tools/ar-preview.sh <port> <en|ar|rtl>` boots the tree three times, one per
language state; `tools/ar-shots.cjs <dir> [prefix]` photographs seven surfaces
at two widths in each. Everything here is one tree — this branch — differing
only in two settings rows and, for the `approved-` half, one press of the
console's **Approve all** button.

## The three states, and why the middle one is the point

| state | `language_ar_enabled` | `language_rtl_enabled` | `/ar/` answers | `<html>` |
|---|---|---|---|---|
| `en`  | off | off | **404** | `lang="en"` (the error page) |
| `ar`  | **on** | off | 200 | `lang="ar" dir="ltr"` |
| `rtl` | **on** | **on** | 200 | `lang="ar" dir="rtl"` |

`en` is the shop **as it ships**: neither row is seeded, so the Arabic
storefront does not exist at all. `ar` is what one switch gets you — Arabic
words in a left-to-right document — and it is the state
`Tests\Support\ArabicShop::on()` puts the suite in. `rtl` is the mirrored
document, and it needs the second switch.

None of this is a bug. `App\Support\Locale::direction()` keeps the two apart on
purpose and says why in its own docblock; `docs/rtl-audit.md` §11–§14 is the
work that made the mirrored layout real.

## The two passes

| prefix | what the shop is doing |
|---|---|
| *(none)* | the 1,018 Arabic strings are shipped and **still drafts**. This is what applying the package gives you. |
| `approved-` | after one press of **Content → Translations → Approve all**. |

The pair is the deliverable. The first half proves applying this changes
nothing; the second proves the words are real.

## Measured

`measurements.json` and `approved-measurements.json`, one row per shot:
`dir`, `lang`, `scrollWidth` against `clientWidth`, the header logo's x and
which side of centre it falls on, and the two words that say which language
rendered.

- **No horizontal overflow anywhere.** `scrollWidth === clientWidth` on all 84
  shots: 390 at 390, 1280 at 1280.
- **`ar` is not mirrored, and the logo is the proof.** Logo x = 68 at 390 and
  22 at 1280 — the same numbers as English, on the same side. A `dir`
  attribute is a claim; where the logo is is a fact.
- **`rtl` is mirrored.** Logo x = 154 at 390 and 1094.3–1097.2 at 1280, right
  of centre, on every one of the seven surfaces.
- **The wordmark is no longer reordered by the mirror.** `fix-wordmark-BEFORE-
  rtl-390.jpg` is the header at 390 with the mirror on, reading **BlissK-Beauty**;
  `fix-wordmark-AFTER-rtl-390.jpg` is the same header reading K-BeautyBliss. The
  measured form is the painted left-to-right order of the two runs, read from
  each run's own client rect: `K-Beauty|Bliss` in all three states. See
  `tests/Feature/WordmarkSurvivesRtlTest.php` for what caused it.
- **Before approval every Arabic page prints `Add to cart`; after it, every one
  prints `أضف إلى السلة`.** Same tree, same settings, one button.

## The English control, and the two things that move in it

`en-*.jpg` and `approved-en-*.jpg` are the same fourteen English pages taken
either side of approving a thousand Arabic strings. **Eleven of the fourteen
are byte-identical.**

The three that are not are `en-product-1280`, `en-set-1280` and `en-tabs-1280`,
and amplifying the difference shows exactly two things in them:

1. **The dispatch countdown**, which is live. Cropped and read, it says *"Order
   within **5h 49m** for delivery by …"* against *"Order within **5h 47m**"* —
   the two passes ran two minutes apart. On `product` and `set` that accounts
   for the difference entirely: **73 pixels each**, all of them clock digits,
   and nothing else in the page differs by a single level.
2. On `tabs`, additionally, **one anti-aliased rounded border** on the product
   image card. 246,175 pixels differ by *something*; only **1,324** differ by
   more than 1% of full scale, the mean difference over the whole box is
   **0.0011**, and the amplified difference map is a single hairline. This is
   the same class as the *"two at 3 and 4 pixels with a maximum channel delta
   of 2 out of 255"* that `docs/rtl-shots/manual/README.md` already records.

Nothing on the English shop moved.

## Why JPEG

`docs/rtl-shots/` made this call first and the reason still holds: 84 full-size
PNGs come to 14 MB, which is more than this repo should carry for one round of
evidence. JPEG q70 is 3.6 MB and the encoding is deterministic, so identical
source pixels still give identical FILES — the eleven byte-identical English
pages above were checked as PNGs and are still byte-identical as JPEGs.

## Reproducing

Chromium 1194 at `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`,
`browser.newContext({viewport})` rather than `page.setViewportSize` (which has
produced wrong-sized shots on this box), `deviceScaleFactor: 1`,
`reducedMotion: 'reduce'`, animations and transitions frozen by an injected
stylesheet, `html{overflow-y:scroll}` so the scrollbar gutter cannot flip
`.wrap`'s `margin:0 auto` between runs, and everything off `127.0.0.1` refused
so a webfont resolving at its own pace cannot move the layout.

```sh
sh tools/ar-preview.sh 8991 ar
sh tools/ar-preview.sh 8992 rtl
sh tools/ar-preview.sh 8993 en
node tools/ar-shots.cjs docs/lane-ar-shots

sh tools/ar-approve.sh ar && sh tools/ar-approve.sh rtl
node tools/ar-shots.cjs docs/lane-ar-shots approved-
```

**Ports 8991–8993, and check them first.** Lane SPL runs a preview on 8981 in
this same container, and an earlier run of this harness spent several minutes
talking to it: `/ar/` came back `dir="rtl"` with none of this lane's products
in it, which reads exactly like a broken seed and is a port collision.

**`CACHE_STORE=array`, not `file`.** The file cache writes to the application's
own `storage/framework/cache`, which is one directory for all three previews
however separate their databases are. Measured: with `file`, the `ar` preview
served `dir="rtl"` because `SettingsService` had cached the `rtl` preview's
`language_rtl_enabled`. On the one question this lane exists to answer, that is
the most misleading failure the harness could have.
