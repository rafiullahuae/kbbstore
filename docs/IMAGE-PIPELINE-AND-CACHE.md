# Image pipeline and cache strategy

Phase 12 asks for "one stated policy for image sizes and caching". This is it.

Everything below was **measured on this checkout**, not estimated. The
measurement is reproducible: it is the same thing `ImageVariants::generate()`
and a rendered page do, run against synthetic photographic sources (noisy, so a
worst case for JPEG — real product shots on white backgrounds compress better).
PHP 8.4.19, GD, file-backed SQLite.

The authoritative statement of the mechanism lives in the docblock of
`app/Support/ImageVariants.php`. This file is the policy an owner or an
integrator reads; that file is the policy a programmer reads. They are not
allowed to disagree, and the numbers here came out of the code as it stands.

---

## 1. What is generated

Two widths, **400** and **800**, and never a width wider than the original.

- 400 is the phone: a 187px tile frame at device-pixel-ratio 2 needs 374 real
  pixels.
- 800 is the same frame at ratio 3, and a 2x desktop.
- Nothing larger, because no tile frame on this site is wider than ~424 CSS
  pixels, so 800 already covers every case a browser can ask for. The product
  page's main frame is wider (~562px) and is handled differently — see §4.

Only `jpg`, `jpeg`, `png`, `webp`. SVG has no pixels; GIF is usually an
animation. Same format in as out, so a cut-out PNG keeps its transparency.
JPEG/WebP quality 82.

A 300px logo is **complete** with neither copy, and `isComplete()` says so
rather than leaving it in a backlog forever.

## 2. When it is generated — and when it is not

Exactly twice, both synchronous, both started by a person:

1. **On upload**, inside the upload request (`MediaUploadController::sizeCopies()`).
   That is the one moment the cost is already being paid.
2. **From Media Library → Image Sizes**, a batch the owner clicks, which walks
   the catalogue a slice at a time (`ImageSizesApiController`).

**Never on page view.** This matters more here than on most hosts: shared
hosting, no shell, no queue worker, no CDN. Resizing on request would put an
image decode in front of every tile on a cold page — twenty-five tiles is
twenty-five PHP processes on a host that has a handful, and the shopper waits
for all of them.

A variant exists because something made it. A page that finds none emits no
srcset and loads the original exactly as it does today.

## 3. What it costs

Measured, this checkout:

| source | original | 400w | 800w | cold | warm |
|---|---|---|---|---|---|
| 1000×1000 JPEG q85 | 346.7 KB | 65.7 KB | 212.8 KB | 67.6 ms | 0.163 ms |
| 1200×1200 JPEG q85 | 495.9 KB | 69.2 KB | 201.7 KB | 71.0 ms | 0.088 ms |
| 800×800 JPEG q85 | 221.0 KB | 67.1 KB | — (not upscaled) | 16.1 ms | 0.074 ms |
| 1000×1000 PNG | 10.9 KB | 11.6 KB | 47.6 KB | 108.6 ms | 0.107 ms |

"Warm" is two `is_file()` calls and a return.

These numbers are **higher than the ones in `ImageVariants`' own docblock**
(which records 20.5 KB / 100.6 KB for the 1000×1000 JPEG against 65.7 / 212.8
here), and the difference is the source file, not a change in the code. Both
runs used synthetic images; this one's is noisier, which is close to a worst
case for JPEG. Neither is a photograph of a real product on a white background,
which is what this catalogue actually holds and which compresses better than
either. **Treat the pair as a range, not as a contradiction** — the honest
statement is that a 1000×1000 catalogue JPEG costs somewhere between about
120 KB and about 280 KB in variants, and that the ratio to the original (roughly
a third to a half) is the stable part.

**PNG is the number to watch, not the product count.** A PNG source costs
roughly an order of magnitude more disk per image than a JPEG one, and on the
1000×1000 PNG row above the 400w copy is *larger than its own original* — a
flat-colour PNG re-encodes badly. The catalogue is 671 products; at one JPEG
photograph each that is between roughly 80 MB and 190 MB of variants depending
where in the range above the real photographs fall, and four photographs each
multiplies it.
A PNG-heavy catalogue is the case that fills a shared plan.

## 4. What each surface asks for

`sizes` is measured off the stylesheet, never guessed, and every band is
declared a shade **above** the measurement. That direction is deliberate and it
is not symmetric: overstating costs a slightly larger candidate; understating
makes the browser pick a file too small for the frame and the photograph is
visibly soft.

| surface | method | declaration |
|---|---|---|
| `.pc` product card | `sizesAttribute()` | `(max-width: 820px) 50vw, 300px` |
| `.kbb-pgrid` skin grid | `skinGridSizesAttribute()` | `(max-width: 900px) 50vw, (max-width: 1180px) 34vw, 290px` |
| homepage `.ugc` strip | `homeTileSizesAttribute()` | `(max-width: 900px) 50vw, (max-width: 1100px) 34vw, (max-width: 1200px) 25vw, 190px` |
| product page main frame | `detailSizesAttribute()` | `(max-width: 880px) calc(100vw - 40px), (max-width: 1180px) 52vw, 563px` |
| gallery thumbnail | `thumbSizesAttribute()` | `66px` |
| quick-view modal | `quickViewSizesAttribute()` | `(max-width: 640px) calc(100vw - 80px), 350px` |
| frequently-bought-together | `fbtSizesAttribute()` | `90px` |

The product page's main frame is the one surface that uses
`detailSrcsetFor()` rather than `srcsetFor()`: it names the **original** as a
third candidate, because the frame is ~562 CSS px and at ratio 2 a browser wants
1124 of them. Offered only 400w and 800w it would take the 800 and the hero
photograph would come out *softer* than it is today. Naming the original
honestly costs one `getimagesize()` per photograph, which a product page pays
once and a fifty-tile grid must not.

## 5. Where the files live, and why not in the repo

`public_path('img-cache/<width>/<the original's path>')`.

`public_path()` is the **web root**, which on this host is a different directory
from the application root (`bootstrap/app.php`). The directory is created by
PHP's own `mkdir()`, because the host has no shell to create it with.

Generated files are not source:

- gitignored, so nothing the packager builds from git history can contain them;
- `public/img-cache/` is on `BuildPackage::NEVER_SHIP`.

Both matter. A package that carries generated images is a package that can
delete them on the next install, and deleting files the product pages depend on
is exactly how 2.60.102–.106 took this shop down.

The cache mirrors the original's path rather than using a `-400w` suffix beside
it, so a file genuinely named `photo-400w.jpg` can never be mistaken for a
variant of `photo.jpg`, and the whole cache is one directory the owner can
delete without touching a single original.

## 6. Invalidation

**There is no time-based expiry, and there should not be.** A variant is a pure
function of the original's bytes, so an entry is stale only when those bytes
change or go away.

- An original deleted in the Media Library calls `ImageVariants::forget()`.
- Uploads cannot overwrite: every file is named `Ymd-His-<random>.ext`. So
  replacement-in-place can only arrive by FTP or a restored backup, and the
  answer to that is the same `forget()`, or deleting `public/img-cache`
  entirely and re-running the batch.
- **The whole directory is safe to delete at any time.** Nothing reads it that
  does not check `is_file()` first.

The srcset is built from the **filesystem**, never from a database column. A
srcset naming a file that is not there is worse than no srcset at all: the
browser fetches it, gets a 404, and the tile is blank — and unlike a stale
`src` there is no second candidate to fall back to. A column can disagree with
the disk (a restored backup, a half-finished batch, a package that removed
files — this repository has had all three). The disk cannot disagree with
itself.

## 7. How it degrades

None of these break a page:

- **No GD.** `available()` is false, the batch screen says so instead of
  reporting a backlog, and pages emit no srcset.
- **Cache directory unwritable.** `write()` returns false, `made` stays 0, the
  batch reports the progress it did not make rather than throwing.
- **Half-written files are impossible.** Each variant is encoded to a
  `.<random>.part` file beside its destination and `rename()`d into place, which
  is atomic within one filesystem. A reader sees the complete file or no file.

## 8. What the measurement indicted, and what was done (Lane FH)

Rendering `/`, `/shop/` and a product page against a catalogue whose variants
had been generated, then counting `<img>` tags with and without a `srcset`:

```
BEFORE
PAGE /shop/                 imgs=1   with srcset=1   without=0
PAGE /                      imgs=2   with srcset=0   without=2
PAGE /product/<slug>/       imgs=1   with srcset=1   without=0
```

The responsive-image work had reached `<x-product-card>` (every grid) and
`partials/product-gallery.blade.php` (the product page) and stopped there. Five
surfaces still handed the full original to a small box, including **the
homepage**, which is the most-visited page of the shop:

- `store/home.blade.php` — the `.ugc` "spotted" strip, four product photographs
- `partials/home/grid.blade.php` — the skin grid
- `components/product-grid.blade.php` — the same grid markup elsewhere
- `partials/quick-view.blade.php` — the quick-view modal
- `partials/fbt.blade.php` — frequently bought together

Each now emits `srcset` + a measured `sizes` when copies exist, and nothing at
all when they do not.

```
AFTER
PAGE /                      imgs=14  with=14  without=0
PAGE /shop/                 imgs=12  with=12  without=0
PAGE /product/<slug>/       imgs=2   with=2   without=0
```

On the homepage strip that is 346.7 KB of original replaced by a 65.7 KB copy
per tile on a phone, four tiles, on the page most first-time shoppers land on.

`tests/Feature/HomeTileResponsiveImageTest.php` pins both halves: the copies are
offered once they exist, and **nothing** is emitted when they do not.

### Not done, and deliberately

- `partials/reviews.blade.php` photo thumbnails are customer uploads rather than
  catalogue photographs; they go through a different upload path and were left
  to the review lane.
- `store/home.blade.php`'s post cover (`640×400`) is editorial, not a product
  photograph, and is one image rather than a grid.
- **No third width was added.** Nothing on this storefront draws a tile wider
  than ~424 CSS px, so 800w remains the ceiling every candidate list stops at.
- **No dependency was added.** `vendor/` cannot ship to this host.
