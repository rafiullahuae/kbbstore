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

---

## 9. The cache strategy — and which half of it PHP can actually enforce

Phase 12 pairs "image pipeline" with "cache strategy" in one line. §1–§8 above
are the image half. This is the other half, and the first thing it has to say
is **where each rule is enforced**, because on this host that is not one place.

### 9.1 The policy

| what | rule | enforced by |
|---|---|---|
| `/build/assets/*` — hashed CSS, JS, source maps | `public, max-age=31536000, immutable` | the web server, `.htaccess` |
| `/img-cache/**` — generated variants | `public, max-age=31536000, immutable` | the web server, `.htaccess` |
| storefront HTML | `private, no-cache, max-age=0, must-revalidate` | PHP, `App\Http\Middleware\CacheHeaders` |
| `/my-account`, `/my-wishlist`, `/wishlist`, `/cart`, `/checkout`, `/track-my-order` | `no-store, no-cache, max-age=0, must-revalidate` | PHP, same middleware |
| the admin console | `no-store` | PHP, `Admin\PageController`, unchanged |
| `/admin-api/*` | `no-store` | PHP, `NoStoreAdminApi`, unchanged |

The year is written once, as `CacheHeaders::IMMUTABLE`, and
`docs/cache-headers.htaccess` repeats the same string.
`tests/Feature/CacheHeaderPolicyTest.php` holds the two to each other, so the
file and the application cannot state different numbers.

> **The middleware rows above are what the shop sends WITH THE SWITCH ON.**
> `CacheHeaders` was registered nowhere for the whole life of this section: the
> registration was written out as a hand-edit to `bootstrap/app.php`
> (`docs/FQ-CACHE-HEADERS.md`) and never applied.
> `AppServiceProvider::boot()` now appends it to the `web` group from a file a
> package can ship — but turning a header policy on across a shop that is taking
> orders is the owner's decision, not a side effect of applying an update. So
> `cache.headers_enabled` ships **false**, the middleware returns every response
> untouched while it is, and **Platform → Cache** is where it is switched on. It
> is also the screen that shows what the storefront is answering with right now,
> fetched rather than described. `App\Support\CacheSettings` carries the
> argument; `tests/Feature/CacheControlScreenTest.php` pins both sides of it.
>
> The two `no-store` rows are **not** switchable. There is no setting for them
> and the screen draws them as a statement.
>
> One knob was added to the storefront HTML row: `cache.html_max_age`, 0 to
> 3600 seconds, 0 as shipped and identical to the policy above. It can never
> produce `public` — `storefrontHeader()` keeps `private` at every value, and a
> case asserts that across the whole range.

### 9.2 Why a year is safe on those two paths and nowhere else

Both are content-addressed, and neither property is assumed — the test asserts
both:

- **`/build/`** — Vite writes `kbb-NawQuIF5.css`, and a rebuild is a new name.
  Every entry in `public/build/manifest.json` is checked against the hash shape;
  a build that stopped hashing would otherwise mean every browser that has seen
  the site keeps last year's stylesheet for a year, and **no package can reach
  it** — there is nothing to reinstall, because the URL never changes.
- **`/img-cache/`** — variant paths mirror originals named `Ymd-His-<random>.ext`,
  and `MediaUploadController` cannot overwrite an original (§6). The test strips
  comments with `token_get_all()` and looks for the naming rule in the code
  rather than in the prose, because prose is what is left saying it after
  somebody has changed the code.

### 9.3 HTML: `no-cache` was already right, and that is the problem

Every storefront response already leaves as `no-cache, private`. **Nothing chose
it.** Symfony's `ResponseHeaderBag` computes that value for any response that
carries no `Cache-Control` of its own, so the correct policy on the most
sensitive pages of the shop was an accident of a framework default.

The cost of it changing is specific and already written down in this
repository: `NoStoreAdminApi`'s docblock records that **shared hosting commonly
caches GET responses by default**. A storefront page carries a cart badge, a
signed-in name and a CSRF token. `public` on this host is one shopper's basket
shown to the next.

So the middleware states it, and the test asserts it **on a fetched page**.

The account, wishlist, cart and checkout paths get `no-store` instead, which is
a real change and not a restatement: `no-cache` still writes the page to the
browser's disk, and on a shared or borrowed computer the back button after a
sign-out renders it without ever asking this server. The private list is
consulted **before** the status check, because `/checkout` redirects an empty
basket and `/my-account` redirects a signed-out visitor, and a 302 carrying that
Location is still something a shared machine should not keep.

### 9.4 The `.htaccess`, and why it is not shipped

**It is an `.htaccess`, not PHP headers, and that is not a preference.** On this
host `public_path()` is a different directory from the application root
(`bootstrap/app.php`), the web server serves files out of it directly, and a
request for `/build/assets/kbb-NawQuIF5.css` never reaches PHP at all. There is
no PHP hook to hang a header on. `ImageSizesApiController`'s docblock reached
the same conclusion from the other direction when it rejected on-request
resizing: "that rewrite lives in an .htaccess in the web root, which no package
can ship and no shell can fix if it is wrong."

The file is `docs/cache-headers.htaccess`. `docs/` is on
`BuildPackage::NEVER_SHIP`, so it cannot reach the server by accident.

**Whether this host honours it is NOT VERIFIED, and must not be guessed at.**
Three things are unknown from here and all three are checkable in a minute by
somebody with the hosting panel open:

1. **Is the server Apache or LiteSpeed?** Both read `.htaccess`. nginx does not,
   and on nginx the file is inert — harmless, and doing nothing.
2. **What is already in the web root?** `kbb-doctor.php` lists that directory
   ("This directory — the public web root itself"). If an `.htaccess` is in the
   listing it is almost certainly carrying the rewrite that puts every URL in
   front of `index.php`, and it must be **appended to, never replaced**.
   Replacing it is the 2.60.102–.106 failure mode with a different file.
3. **Is `AllowOverride` wide enough?** `Header` needs `FileInfo`. If the site's
   routing already comes from an `.htaccess` `RewriteRule`, `FileInfo` is
   granted and so is `Header`. If routing comes from the vhost instead, a
   directive Apache is not allowed to process is a **500 on every file in that
   directory** — which for `/build/` means a shop with no stylesheet.

Because of (3) the file scopes its own blast radius: it contains **no
`RewriteRule`, no `Options` and nothing outside an `<IfModule mod_headers.c>`
guard**, and the test asserts that (with the comment lines stripped first — the
file's own header explains that it contains no rewrite, and an unstripped search
finds the explanation and reports it as the defect).

**Installing it.** Put it at `<web root>/build/.htaccess` and, separately, at
`<web root>/img-cache/.htaccess`, by hand, through the hosting file manager —
**not** at the web root itself, where it would sit beside the file that routes
the site. Load a product page, check the response headers on a `.css` under
`/build/`, and if anything 500s, delete the file: nothing else depends on it and
the site is back the moment it is gone.

**Shipping it in a package later.** `UpdateGuard::ALLOWED_PREFIXES` already
permits `public/build/`, and `htaccess` is on `ALLOWED_EXTENSIONS`, so
`public/build/.htaccess` is a path a package *can* carry once (3) has been
answered once. `public/.htaccess` is on `ALLOWED_FILES` too and should stay
unused: it is the file the whole site's routing may depend on.

### 9.5 What is deliberately not done

- **No cache headers on `sitemap.xml`, `robots.txt` or `llms.txt`.** They are
  public documents and marking them `public, max-age=` would be an obvious win,
  except that they are served through the `web` group and therefore leave with a
  `Set-Cookie` for a freshly minted session. A `public` response carrying
  `Set-Cookie` is one that a shared cache may keep and hand to the next visitor
  — cookie and all. Moving the routes out of the group is a `routes/web.php`
  change, which this lane does not own, and the gain (a handful of crawler
  fetches an hour) does not justify guessing at it. Written up rather than
  shipped.
- **No ETag on HTML.** A 304 saves the body and not the render, and the render
  is the scarce thing on a host with a handful of PHP workers.
- **No `Expires` header and no `mod_expires` block.** `Cache-Control: max-age`
  supersedes it everywhere, and `ExpiresByType` sits in a different
  `AllowOverride` class (`Indexes`) from `Header` (`FileInfo`) — two chances to
  hit (3) instead of one.
