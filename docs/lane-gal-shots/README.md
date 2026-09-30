# Lane GAL — the product gallery's thumbnail strip

> *"also i can not see the product gallery thumnails, add some demo thumnails so
> i can see in action."*

He could not see them because there were none. `DemoCatalogueSeeder` set neither
`products`.image nor `products`.images on any of its 24 rows,
`Store\ProductController::gallery()` therefore returned ONE shot, and
`partials/product-gallery.blade.php` draws the `.gthumbs` strip only
`@if ($shotCount > 1)`. `gallery()` does pad the list to six labelled shots —
but only `when DemoContent::enabled()`, and `demo_content` defaults to false.

## How it works, and the reversal behind it

Five PNGs per demo product are **drawn on the server** by
`App\Support\DemoProductShots` — GD, the shop's own `:root` palette, the way
`tools/release-330-banner-seed.php` draws the banner pictures — written under
`uploads/demo-shots/` and registered through `MediaRegistrar` like any upload.
`2027_06_20_000000_draw_demo_gallery_shots` draws them on the owner's running
shop; `DemoCatalogueSeeder` draws them on a fresh install.
`Store\ProductController::gallery()` tops up a demo product that has **no shots
of its own** from the files.

**The five URLs are NOT written into `products`.images, and that is a reversal.**
It was built that way first — a backfill migration in the shape of
`2027_06_15_000000`'s detail-tab backfill — and measured:

| | full suite |
| --- | --- |
| merge tip, before this lane | 3 failed (all three pre-existing) |
| with the `products`.images backfill | **26 failed** |
| with the files + `gallery()` top-up | 3 failed (the same three) |

The 23 extra failures were in seven files with nothing to do with galleries:

| file | cases | what it reported |
| --- | --- | --- |
| `ImportRepointsWhatItFetchesTest` | 7 | `MediaAudit` → `missing: 120` |
| `ImageSizesBatchTest` | 3 | variants backlog `total: 122`, not 2 |
| `GalleryThumbnailVariantsTest` | 3 | variants backlog `total: 124`, not 4 |
| `MediaUsagesTest` | 3 | `MediaUsageWriter::rebuild()` added 124, not 4 |
| `GbUrlsAndMediaTest` | 2 | the importer's re-pointer walked them |
| `GdMediaSideloaderTest` | 2 | the sideloader's audit counted them |
| `ReviewPhotoVariantsTest` | 2 | backlog `total: 123`, not 3 |

None of those tests was wrong. `products`.images is **catalogue** data, and five
subsystems walk it. On the owner's own shop that package would have put 120
placeholder pictures into Content → Media Library's attachment counts, 120 items
into his Image sizes backlog — 360 more generated files the moment he pressed
the button — and 120 placeholder URLs into his image sitemap and his products'
`schema.org`. He asked to see a thumbnail strip.

**Regenerate everything here with:**

```sh
sh tools/gal-preview.sh                 # prints a URL and a PID
GAL_BASE=http://127.0.0.1:<port> node tools/gal-shots.cjs
kill <pid>                              # NEVER pkill -f: three lanes share this machine
```

The preview seeds nothing by hand. The demo catalogue and its gallery shots are
both loaded by MIGRATIONS, so `php artisan migrate` on an empty database
produces exactly what applying the package produces on the owner's shop.

## The pictures

| file | what it shows |
| --- | --- |
| `gallery-390.png` | the product page on a 390px phone, strip present, first shot active |
| `gallery-1280.png` | the same at 1280 |
| `gallery-clicked-390.png` | after a real click on the FOURTH thumbnail — the main frame is now "On skin" |
| `gallery-clicked-1280.png` | the same at 1280 |
| `strip-390.png` / `strip-1280.png` | the `.gallery` element alone, so the five shots can be told apart |
| `measurements.json` | every number below, as the browser reported it |

## The numbers

Read out of Chromium by `tools/gal-shots.cjs`, never by a script the shop
serves — CLAUDE.md rule 4 forbids JavaScript that measures layout IN THE
PRODUCT; measuring the product from outside is how the claim gets checked.

| | 390 | 1280 |
| --- | --- | --- |
| `document.documentElement.scrollWidth` | **390** (= innerWidth, no sideways scroll) | **1280** |
| `.gmain` | x 0, w 390, h 390 | x 22, w 611.55, h 611.55 |
| `#gthumbs` | x 0, w 390, h 58 | x 22, w 611.55, h 66 |
| thumbnails | **5**, 56×56, at x 22 / 86 / 150 / 214 / 278 | **5**, 66×66, at x 22 / 98 / 174 / 250 / 326 |
| `#gthumbs`.x − `.gmain`.x | **0** | **0** |
| active thumb before the click | 0 | 0 |
| active thumb after the click | **3** | **3** |
| `#gmainImg` src before | `…-1-front.png` | `…-1-front.png` |
| `#gmainImg` src after | `…-4-on-skin.png` | `…-4-on-skin.png` |
| `naturalWidth` of what was really downloaded | 900 | 900 |

### The 2.60.332 alignment is intact

The strip and the photograph share ONE LEFT EDGE at 1280 — both 22 — and on a
phone both bleed: `.gmain` and `#gthumbs` are each x 0, w 390. The first
thumbnail sits at x 22 because `.pdp .gthumbs` carries
`padding-inline: var(--site-gutter, 22px)` under 720px, which is that release's
own design — the BOX bleeds, the squares inside it keep the page gutter — and at
1280 the same rule is `padding-inline: 0` and the two edges coincide.

### The click really changes the picture

`changed: true` at both widths, and it is asserted on the main `<img>`'s `src`
rather than on a class: a strip of five identical sources would highlight
perfectly and change nothing a person can see. The fourth thumbnail is the one
clicked on purpose — neither the one already showing nor the one beside it.

`naturalWidth` is read as well, so a URL with no file behind it cannot be
photographed as though it were a picture.

## What the shots weigh

| shot | ground | bytes |
| --- | --- | --- |
| 1 Front | cream, a bottle in the product's accent | 2,586 |
| 2 Texture | the accent, full bleed, with a worked cream over it | 6,810 |
| 3 Ingredients | white, botanicals | 4,727 |
| 4 On skin | a warm skin field with a swatch | 4,421 |
| 5 Box | blush, an isometric carton in the accent | 4,925 |
| **per product, averaged over all 24** | | **23,454** |
| **24 demo products, 120 files** | | **562,886** |

They are drawn once, in about six seconds for the whole catalogue, and never
redrawn — a second application of the package finds the files already there. Not
one byte of this travels in the package: CLAUDE.md records what shipping
generated images did to this shop under 2.60.102–.106, and `public/uploads/` is
untracked besides.

## Where it sits in the admin

This lane adds no control. What it adds is files, and the owner should be able
to find them:

* **Content → Media Library.** All 120 shots are catalogued there, exactly as an
  upload would be, named `<product-slug>-<n>-<label>.png`. **Deleting one is the
  undo** — nothing in `media_usages` points at them, because no catalogue column
  holds them, so the library's "still in use" guard does not stand in the way,
  and `gallery()` offers only the files that are really on disk. Deleting the
  second shot leaves the first rather than shuffling every caption up by one.
* **Catalog → Product editor → Images.** Give a demo product a main image or a
  gallery of its own and the top-up stands aside completely: the page shows his
  picture and nothing else. It can never push a real photograph down the strip.
* **Store → Demo Content** is the switch this lane deliberately did NOT touch
  (`demo_content`, read by `App\Services\DemoContent`). It stays off: turning it
  on would also pad related products, reviews and more, on pages the owner did
  not ask about.

The shots stop on their own when the WordPress import lands: an imported row
carries a real `wc_id` and fails the first of the three demo marks.
