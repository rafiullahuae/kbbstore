<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone-sized copies of the photographs this site serves, and the srcset that
 * offers them.
 *
 * THE PROBLEM. A product tile is a box between 153 and 399 CSS pixels wide —
 * measured across viewports from 360 to 1920 on /shop, a category archive and
 * the related-products grid — and the file behind it is a 1000x1000
 * photograph. On a 390px phone the nine tiles a shopper actually fetches are
 * about 2.0MB of image to paint nine 187x180 frames. The bytes are real and
 * the pixels are thrown away.
 *
 * WHY THE COPIES ARE MADE ON THE WAY IN AND NOT ON THE WAY OUT. This store is
 * on modest managed hosting with no queue worker and no CDN, and the owner
 * has no shell. Resizing when a photograph is *requested* would put an image
 * decode in front of every tile on a cold page: twenty-five tiles is
 * twenty-five PHP processes on a host that has a handful, and the shopper
 * waits for all of them. Measured here, one 1000x1000 JPEG costs ~137ms and
 * ~7.6MB of resident memory to turn into both sizes; a 4000x4000 upload costs
 * ~544ms and ~65MB. That is an acceptable price once, when an image is
 * uploaded. It is not a price to pay per page view, per tile, forever.
 *
 * So a variant exists because something made it — an upload, or a batch the
 * owner started from the Media Library — and never because a page asked for
 * it.
 *
 * WHY THE SRCSET IS BUILT FROM THE FILESYSTEM AND NOT FROM A DATABASE COLUMN.
 * A srcset that names a file which is not there is worse than no srcset at
 * all: the browser fetches it, gets a 404, and the tile is blank — and unlike
 * a stale `src` there is no second candidate to fall back to. A column can
 * disagree with the disk (a restored backup, a half-finished batch, a package
 * that removed files — this repository has had all three). The disk cannot
 * disagree with itself. Two is_file() calls per tile, fifty on a full grid,
 * are answered from the kernel's dentry cache and out of PHP's own stat cache;
 * they cost far less than one 190KB download they save.
 *
 * Deliberately NOT memoised in a static. CLAUDE.md records what a process-level
 * memo does to a long-lived process, and this one would be wrong in exactly the
 * process that matters: the admin request that has just generated a batch of
 * variants and then renders a page describing them.
 *
 * WHERE THE FILES LIVE, AND WHY NOT IN THE REPOSITORY. Under
 * public_path('img-cache/<width>/<the original's path>'). public_path() is the
 * web root, which on this host is a different directory from the application
 * root (see bootstrap/app.php), and it is where uploads already go — the
 * directory is brought into existence by PHP's own mkdir() here, the same way
 * MediaUploadController already creates public/uploads/<folder>, because the
 * owner has no shell to create it with.
 *
 * Generated files are not source. They are gitignored, so nothing the packager
 * builds from git history can contain them, and 'public/img-cache/' is on
 * BuildPackage::NEVER_SHIP as well. Both matter: a package that carries
 * generated images is a package that can delete them on the next install, and
 * deleting files the product pages depend on is exactly how 2.60.102-.106 took
 * this shop down.
 *
 * A mirrored path rather than a `-400w` suffix beside the original, so that a
 * file which happens to be named `photo-400w.jpg` can never be mistaken for a
 * variant of `photo.jpg`, and so the whole cache is one directory the owner
 * can delete without touching a single original.
 *
 * ── THE CACHE POLICY, IN FULL ───────────────────────────────────────────────
 *
 * Written down because a host the owner has no shell on and no queue worker
 * cannot be given a policy that says "a background job tidies up".
 *
 * WHAT IS GENERATED. Two widths, 400 and 800, and never a width wider than the
 * original — a 300px logo is COMPLETE with neither, and isComplete() says so
 * rather than leaving it in a backlog forever. Only jpg/jpeg/png/webp; SVG has
 * no pixels and GIF is usually an animation. Same format in as out, so a
 * cut-out PNG keeps its transparency.
 *
 * WHEN. Exactly twice, both of them synchronous and both of them started by a
 * person:
 *   1. on upload — MediaUploadController::sizeCopies(), inside the upload
 *      request, which is the one moment the cost is already being paid;
 *   2. from Media Library → Image Sizes, a batch the owner clicks, which walks
 *      the catalogue a slice at a time (ImageSizesApiController).
 * NEVER on page view. A variant exists because something made it, and a page
 * that finds none emits no srcset and loads the original exactly as before.
 *
 * WHAT IT COSTS, MEASURED ON REAL FILES rather than estimated (PHP 8.4, GD
 * 2.3.3, synthetic photographic sources — noisy, so a worst case for JPEG;
 * real product shots on white backgrounds compress better):
 *
 *     source                    original   400w     800w     variants  vs src
 *     1000x1000 JPEG q85        250.1KB    20.5KB   100.6KB  121.1KB    48%
 *     1200x1200 JPEG q85        358.8KB    20.7KB    94.7KB  115.4KB    32%
 *       800x800  JPEG q85       159.8KB    23.0KB   (none)    23.0KB    14%
 *     1000x1000 PNG            2129.4KB   307.7KB  1362.9KB 1670.7KB    78%
 *
 *     generate(), cold: 59ms for the 1000x1000 JPEG, 363ms for the PNG.
 *     generate(), warm: 0.02-0.09ms — two is_file() calls and a return.
 *
 * SO, FOR THIS CATALOGUE. 671 products at one photograph each is about 79MB of
 * variants; at four photographs each, about 317MB. Those are JPEG numbers. A
 * PNG-heavy catalogue costs roughly fourteen times as much per image, and that
 * — not the count of products — is the number to watch on a shared plan.
 *
 * HOW IT IS INVALIDATED. By forget(), called when an original is deleted
 * (Admin\MediaLibraryApiController::destroy). There is no time-based expiry and
 * there should not be: the cache is a pure function of the original's bytes, so
 * an entry is stale only when those bytes change or go away. Uploads cannot
 * overwrite — MediaUploadController names every file `Ymd-His-<random>.ext` —
 * so replacement-in-place can only arrive by FTP or a restored backup, and the
 * answer to that is the same forget(), or deleting public/img-cache entirely
 * and re-running the batch. The whole directory is safe to delete at any time:
 * nothing reads it that does not check is_file() first.
 *
 * HOW IT DEGRADES, all three ways, none of which breaks a page:
 *   - NO GD. available() is false, generate() returns `reason: 'no image
 *     library'`, the batch screen says so instead of reporting a backlog, and
 *     srcsetFor() finds nothing on disk so pages emit no srcset at all.
 *   - CACHE DIRECTORY UNWRITABLE. write() returns false — mkdir failed, or the
 *     encode failed, or the rename failed — `made` stays 0 and the batch
 *     reports progress it did not make rather than throwing. Pages are
 *     unaffected for the same reason: the srcset is built from what is on disk.
 *   - HALF-WRITTEN FILES ARE IMPOSSIBLE. Each variant is encoded to a
 *     `.<random>.part` file beside its destination and rename()d into place,
 *     which is atomic within one filesystem. A reader sees the complete file or
 *     no file.
 */
final class ImageVariants
{
    /**
     * The widths generated, smallest first.
     *
     * 400 is the phone: a 187px frame at device-pixel-ratio 2 needs 374 real
     * pixels. 800 is the same frame at ratio 3 and a 2x desktop. Nothing
     * larger is generated because no tile frame on this site is wider than
     * 399 CSS pixels, so 800 already covers every case a browser can ask for.
     *
     * ── AND 200, WHICH IS THE THUMBNAIL TIER (Lane IM) ─────────────────────
     *
     * THE OWNER'S WORDS: "the products gallery thumbnails must load the
     * thumbnail sizes, not the full image ... our system must be capable to get
     * only the 100x100 thumbnail size."
     *
     * He is right, and the list above is one reason why. The gallery strip is
     * `.gthumb`, a fixed 66px square on a desktop and 56px on a phone
     * (kbb-product.css:120, kbb.css:2129) — measured in Chromium, 62 and 52 CSS
     * pixels of picture once the 2px border is taken off. The SMALLEST thing
     * this class could offer such a box was a 400px-wide file. At
     * device-pixel-ratio 2 that box wants 124 real pixels and was handed 400;
     * the file is about 20KB where 7KB carries every pixel the square can show.
     * Four times the area, on every thumbnail, forever.
     *
     * 200 is chosen against the worst case a browser can ask for, which is
     * ratio 3: 66 x 3 = 198. So a 200w copy is the exact size of a gallery
     * thumbnail on the densest handset sold, and no screen can ask for more
     * than the strip declares in `sizes` (66px). It is also right for the
     * "frequently bought together" row (a fixed 90px square: 90 x 2 = 180) and
     * for the shop tile at ratio 1 on a phone (50vw of 390 = 195).
     *
     * WHAT IT COSTS. A 200w copy of a 1000x1000 JPEG measured here is 7.4KB —
     * about 6% of what the 400w and 800w pair already cost together, so the
     * cache grows by roughly a sixteenth. That is the cheapest width on the
     * list by a wide margin and the one the most elements on the site resolve
     * to.
     *
     * ADDING A WIDTH MAKES EVERY ALREADY-SIZED PHOTOGRAPH INCOMPLETE AGAIN, on
     * purpose: isComplete() asks for every width, so Media Library → Image
     * Sizes will report a fresh backlog after this ships and the owner runs the
     * batch once more. Nothing breaks in the meantime — srcsetFor() lists only
     * what is on disk, so a catalogue with 400w and 800w copies and no 200w
     * ones goes on serving exactly what it serves today.
     */
    public const WIDTHS = [200, 400, 800];

    /** The web-root directory the copies live under. */
    public const DIR = 'img-cache';

    /** JPEG and WebP quality. 82 is the knee: 25KB at 400px, no visible loss at tile size. */
    private const QUALITY = 82;

    /** Extensions that can be resized. SVG has no pixels; GIF is usually an animation. */
    private const RESIZABLE = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Whether this PHP can resize an image at all.
     *
     * GD only. Imagick is not installed on any machine this lane could test on,
     * and an untested second code path that silently produces nothing is worse
     * than one path that says plainly it is unavailable — the caller can then
     * tell the owner, instead of a batch that reports success and writes no
     * files. When this is false nothing is generated, no srcset is emitted, and
     * every tile keeps the original it has today.
     */
    public static function available(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagecreatetruecolor');
    }

    /**
     * The srcset value for a photograph, or '' when there is nothing to offer.
     *
     * Only variants that are on disk right now appear. If none is, the caller
     * emits no srcset and no sizes, and the browser loads `src` exactly as it
     * does today — which is the whole reason this returns a string to be tested
     * rather than a list to be trusted.
     *
     * The original is NOT listed as a candidate. Naming it would mean stating
     * its intrinsic width, and the only honest source for that is
     * getimagesize() — a file read per tile, to advertise a candidate no
     * browser would ever choose: the widest frame on the site is 399 CSS
     * pixels, so even at device-pixel-ratio 3 the 800w copy is the largest
     * anything can ask for.
     */
    public static function srcsetFor(string $image): string
    {
        $parts = self::split($image);

        if ($parts === null) {
            return '';
        }

        [$prefix, $rel, $fsRel] = $parts;

        /*
         * (Lane IM2) A COMMA IN THE PATH, refused here as detailSrcsetFor()
         * already refuses it and for the reason written there: a srcset is a
         * COMMA-separated list, so a comma anywhere in a URL splits one
         * candidate into two malformed ones and the browser is entitled to
         * discard the lot -- leaving the box with whatever `src` resolves to,
         * or nothing at all if the malformed list is preferred over it.
         *
         * It was guarded on the product page's main frame and not here, which
         * is the wrong way round: that method has one caller and this one has
         * nine. It went unnoticed because every path in this catalogue is
         * written by MediaUploadController as `Ymd-His-<random>.ext`, which
         * cannot contain one.
         *
         * WHAT MADE IT REACHABLE. Review photographs now enter this method, and
         * their addresses come from the WordPress import -- a database this
         * shop did not author, whose filenames are whatever an operator typed
         * into WordPress years ago. `src` alone is always correct, so saying
         * nothing is strictly safer than saying something a browser may throw
         * away.
         */
        if (str_contains($rel, ',')) {
            return '';
        }

        $candidates = [];

        foreach (self::WIDTHS as $width) {
            if (is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $candidates[] = $prefix.'/'.self::DIR.'/'.$width.'/'.$rel.' '.$width.'w';
            }
        }

        return implode(', ', $candidates);
    }

    /**
     * The srcset for a photograph shown BIG — the product page's main frame —
     * or '' when there is nothing safe to offer.
     *
     * WHY THIS IS NOT srcsetFor(). That method is right for a tile and would be
     * a downgrade here, for a reason stated in its own comment: it deliberately
     * does not name the original, because "the widest frame on the site is 399
     * CSS pixels, so even at device-pixel-ratio 3 the 800w copy is the largest
     * anything can ask for". That sentence is about tiles. The product page's
     * main frame is ~562 CSS pixels on a 1180px layout, and at ratio 2 a
     * browser wants 1124 of them. Offered only 400w and 800w with no third
     * candidate, it would take the 800 — and the shopper would get a SOFTER
     * hero photograph than the one this page serves today. A srcset that makes
     * the largest image on the page worse is not an optimisation.
     *
     * So the original is a candidate here, and to name it honestly its real
     * width has to be stated. The only source for that is the file's own
     * header, and reading it is precisely the cost srcsetFor() refuses to pay:
     * one getimagesize() per photograph. The trade is different at this size.
     * A grid pays it fifty times to save fifty small downloads; a product page
     * pays it once for the main shot and once per thumbnail — a handful of
     * header reads against the largest single asset on the page.
     *
     * AND IT IS ONLY PAID WHEN IT CAN BUY SOMETHING. The variants are looked
     * for first, and a photograph with no copies on disk returns '' before any
     * file is opened. A catalogue that has never been through the batch
     * therefore costs nothing at all and renders exactly the markup it renders
     * today.
     *
     * '' RATHER THAN A PARTIAL ANSWER when the original's header cannot be
     * read. Emitting the copies alone would silently swap the hero for a
     * smaller file on every high-density screen. With `w` descriptors the
     * browser never looks at `src` once it has chosen, so there is no falling
     * back from that — the same reason srcsetFor() would rather say nothing.
     */
    public static function detailSrcsetFor(string $image): string
    {
        $parts = self::split($image);

        if ($parts === null) {
            return '';
        }

        [$prefix, $rel, $fsRel] = $parts;

        // A srcset is a COMMA-separated list, so a comma anywhere in a URL
        // splits one candidate into two malformed ones and the browser is
        // entitled to discard the lot. Rare, but `src` alone is always correct
        // and this is the page's largest image.
        if (str_contains($rel, ',')) {
            return '';
        }

        $candidates = [];
        $widest = 0;

        foreach (self::WIDTHS as $width) {
            if (is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $candidates[] = $prefix.'/'.self::DIR.'/'.$width.'/'.$rel.' '.$width.'w';
                $widest = $width;
            }
        }

        if ($candidates === []) {
            return '';
        }

        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return '';
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) $info[0] < 1) {
            return '';
        }

        $originalWidth = (int) $info[0];

        // A candidate no wider than one already listed is a bigger file
        // promising the same detail, and a browser choosing by width has no way
        // to prefer the smaller one. generate() never upscales, so this is the
        // ordinary case for an original that is exactly 800 wide.
        if ($originalWidth <= $widest) {
            return implode(', ', $candidates);
        }

        $candidates[] = $prefix.'/'.$rel.' '.$originalWidth.'w';

        return implode(', ', $candidates);
    }

    /**
     * What the product page's MAIN frame tells the browser about how wide the
     * photograph will be drawn.
     *
     * Measured off the stylesheet rather than guessed, the same way
     * tileSizesAttribute() is. `.wrap` is max-width:1180px with 20px of padding a
     * side, and `.pdp` is `grid-template-columns:1.05fr 1fr` with a 42px gap
     * until it collapses to one column at 880px (kbb-product.css:55-56).
     *
     *   >= 1180   (1180 - 40 - 42) x 1.05/2.05  =  562px
     *   881-1179  (100vw - 40 - 42) x 1.05/2.05 =  51.2vw - 42px
     *   <= 880    one column                    =  100vw - 40px
     *
     * The middle band is declared as a flat 52vw and the top as 563px, both a
     * shade ABOVE the measurement. That direction is deliberate and it is not
     * symmetric: overstating costs a slightly larger candidate, understating
     * makes the browser choose a file too small for the frame and the hero
     * photograph is visibly soft. At 1180 the declaration says 613px where the
     * box is 562, and at 881 it says 458 where the box is 409.
     */
    public static function detailSizesAttribute(): string
    {
        return '(max-width: 880px) calc(100vw - 40px), (max-width: 1180px) 52vw, 563px';
    }

    /**
     * The HOMEPAGE's product strip (`.ugc .im`), measured off the stylesheet
     * the same way the others are.
     *
     * These four tiles were the largest images on the most-visited page of the
     * shop and the only product photographs on the storefront emitting no
     * srcset at all — /shop and the product page both had one, the homepage was
     * missed. A 1000x1000 original was being painted into a box that is never
     * wider than about 424 CSS pixels.
     *
     * `.ugc` is a grid inside `.wrap` (max-width 1200, 20px padding a side, so
     * 1160 of content) and it restates its column count four times as the
     * viewport narrows (kbb-BOOpsTt7.css):
     *
     *   >= 1200   6 cols, 12px gap  (1160 - 5x12) / 6      = 183px
     *   1101-1199 6 cols            (100vw - 40 - 60) / 6  = 16.7vw - 17px
     *   901-1100  3 cols            (100vw - 40 - 24) / 3  = 33.3vw - 21px
     *   <= 900    2 cols, 9px gap   (100vw - 40 - 9) / 2   = 50vw - 24px
     *
     * Two of those bands are declared ABOVE the measurement, deliberately and
     * in the same direction detailSizesAttribute() rounds: overstating costs a
     * slightly larger candidate, understating makes the browser choose a file
     * too small for the frame and the photograph is visibly soft. The 901-1100
     * band is declared 34vw where the box is 33.3vw - 21px, and the top band
     * 190px where it is 183px.
     *
     * The widest this can ask for is 50vw of 900 = 424 CSS px, which at
     * device-pixel-ratio 2 wants 848 real pixels — above the 800w ceiling, so
     * the 800w copy is chosen and that is the largest copy that exists. No
     * third width is needed for this strip.
     */
    public static function homeTileSizesAttribute(): string
    {
        return '(max-width: 900px) 50vw, (max-width: 1100px) 34vw, (max-width: 1200px) 25vw, 190px';
    }

    /**
     * THE PRODUCT TILE. One shape, because there is one card.          Lane PG
     *
     * There were two of these — `sizesAttribute()` for `.pc` on /shop and this
     * one for `.kbb-pgrid` — because the shop drew two different cards at two
     * different widths. components/product-card.blade.php is the only tile now
     * and every grid derives its column count from the same rule in kbb.css, so
     * there is one frame to describe and the second method is gone.
     *
     * MEASURED AGAINST THE ARITHMETIC THAT DECIDES THE COUNT, not against a
     * breakpoint. The tile minimum is 220px and the gap 16px (18 on /shop), the
     * page container is `min(100vw, 1680px)` less a 22px gutter a side, and the
     * count is the largest N with `row >= N * (tile + gap) - gap`:
     *
     *   screen     cols   row        tile                        declared
     *   <= 735px    2     100vw-44   (100vw - 60) / 2  ~= 46vw      50vw
     *   736-971     3     100vw-44   (100vw - 76) / 3  ~= 31vw      35vw
     *   972-1207    4     100vw-44   (100vw - 92) / 4  ~= 25vw      26vw
     *   1208-1443   5     100vw-44   (100vw - 108) / 5 ~= 19vw
     *   >= 1444     6     capped     220px .. 255px               260px
     *
     * The last two bands are one declaration: above 1208 the tile is between
     * 220 and 255 CSS pixels at every width, including 2560 where the container
     * has stopped growing, so a single 260px figure is above all of them.
     *
     * DECLARED A SHADE ABOVE EVERY MEASUREMENT, which is the direction that
     * cannot hurt: overstating makes a browser choose the LARGER candidate, and
     * understating makes it choose one too small and leaves the photograph
     * visibly soft. The candidates stop at 800w (see srcsetFor), so the whole
     * cost of the error is at most one step up a two-item list.
     *
     * A per-caller `:columns` pin, or Appearance -> Site layout -> Or pin an
     * exact count, can draw WIDER tiles than this says. That is the overstating
     * direction again.
     */
    public static function tileSizesAttribute(): string
    {
        return '(max-width: 735px) 50vw, (max-width: 971px) 35vw, (max-width: 1207px) 26vw, 260px';
    }

    /**
     * A STORED image column, as a path split() will accept.          Lane PERF
     *
     * ── THE DEFECT THIS EXISTS FOR ──────────────────────────────────────────
     *
     * `split()` refuses a bare relative path, and says why: "a bare relative
     * path resolves against whichever page is being rendered, so it already
     * means different files on /shop/ and on /product/x/". That is right about
     * a path found in HTML and wrong about a path found in a COLUMN, and the
     * two are not the same thing — `MediaRegistrar::normalise()` ends with
     * `ltrim($path, '/')`, so every value this application stores is bare by
     * construction.
     *
     * Measured, not assumed: `banner_cards.image` holds
     * `uploads/posters/perf-poster-1.jpg`, and
     * `ImageVariants::generate()` on that exact string answers
     * `['made' => 0, 'skipped' => 0, 'reason' => 'not a local image']`. With a
     * leading slash in front of it, `['made' => 3]`. Same file, same disk.
     *
     * So a caller holding a stored column and calling srcsetFor() on it
     * directly gets '' every time, silently, and concludes the variants are
     * missing. Anything that reaches these methods through a rendered URL —
     * which is most of the storefront — never meets it.
     *
     * A SCHEME IS REFUSED RATHER THAN PREFIXED. `'/'.'https://other/x.jpg'` is
     * a path on this host that does not exist, and turning a remote address
     * into a local-looking one is how a 404 gets into a srcset. split()'s own
     * URL branch handles a genuine absolute URL; this one is only ever given
     * what a column holds.
     */
    public static function rootRelative(string $stored): string
    {
        $value = trim($stored);

        if ($value === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            return $value;
        }

        return '/'.ltrim($value, '/');
    }

    /**
     * THE CARDS BANNER'S PICTURE (`.kbbn-im img`).                  Lane PERF
     *
     * WHY THIS ONE TAKES ARGUMENTS WHEN THE OTHERS DO NOT. Every other frame
     * on this shop has a column count the stylesheet decides, so its `sizes`
     * is a constant. This one does not: `--kbbn-per-lg` and `--kbbn-peek-lg`
     * are written into the element's own `style` attribute by
     * Banners::cssVariables() from the operator's choice, and per_view ranges
     * 1..8 (BannerSet::LIMITS). A shop showing two big cards draws a 650 CSS
     * pixel box where the shipped four draws 356 — and `sizes` is the one
     * attribute where being wrong in the small direction is visible, because
     * the browser never looks at `src` again once it has chosen.
     *
     * ── THE ARITHMETIC, AND IT WAS MEASURED IN A BROWSER RATHER THAN READ ───
     *
     * It was read off the stylesheet first and it was WRONG, in the direction
     * that matters: 209 CSS pixels declared where the card draws 233 at 390px,
     * which on a device-pixel-ratio 1.75 phone is the difference between the
     * 400w copy and the 800w one, and the 400w copy in a 434-device-pixel box
     * is a visibly soft photograph. What the reading missed is
     * `@media(max-width:680px)` at kbb.css:3096, where the homepage section's
     * card frame comes off entirely — `width:100%; max-width:none; border:0;
     * padding-inline:12px` — so below 680 the container is 24px narrower than
     * the viewport and above it 38px narrower plus the gutter.
     *
     * So the numbers below come from
     * `storage/perf-logs/measure-kbbn.cjs`, which reads `.kbbn-vp`'s own
     * content box and `.kbbn-c`'s rectangle at seventeen viewport widths:
     *
     *   vw     100cqi    card (per/peek)      this method
     *   360     336      213.33  (1/.5)       213.33   exact
     *   390     366      233.33  (1/.5)       233.33   exact
     *   412     388      248.00  (1/.5)       248.00   exact
     *   520     496      189.38  (2/.45)      189.39   exact
     *   600     576      222.03  (2/.45)      222.04   exact
     *   767     705      274.69  (2/.45)      274.69   exact
     *   768     706      193.52  (3/.4)       193.53   exact
     *  1023     956      267.08  (3/.4)       268.53   +0.5%
     *  1024     957      203.89  (4/.38)      205.02   +0.6%
     *  1280    1203      260.00  (4/.38)      263.47   +1.3%
     *  1920    1622      355.70  (4/.38)      360.27   +1.3%
     *
     * Every row is exact or a shade ABOVE, which is the direction
     * tileSizesAttribute() already names as the one that cannot hurt:
     * overstating makes a browser choose the larger candidate, understating
     * leaves the photograph soft. The +1.3% is the gutter, declared at its
     * 18px floor instead of `clamp(18px, 2vw, 28px)` so the expression carries
     * no clamp() — a `sizes` entry a browser cannot parse is DROPPED, and the
     * default for a dropped entry is 100vw, which overstates hugely.
     *
     * ── AND IT PAIRS WITH detailSrcsetFor(), NOT srcsetFor() ────────────────
     *
     * srcsetFor() stops at 800w on the stated ground that "the widest frame on
     * the site is 399 CSS pixels" — a sentence about tiles, and false here: at
     * per_view 2 on a 1920 screen this box is 650 CSS pixels and a
     * device-pixel-ratio 2 browser wants 1300 of them. Offered nothing wider
     * than 800w it would take the 800 and the owner's banner would come out
     * SOFTER than it is today, which is the one outcome a delivery change must
     * never produce.
     *
     * ▲ AND ONE THING THIS CANNOT FIX, SAID HERE BECAUSE IT LOOKS LIKE A BUG.
     * On a 412px phone at ratio 1.75 the card needs 434 device pixels, and
     * `WIDTHS` offers 400 and then 800 with nothing between, so the browser
     * takes the 800w copy — which for an 810px original is very nearly the
     * original. The desktop saving is real (ratio 1, 260 CSS px, the 400w copy)
     * and the phone saving is not. A 600w entry in `WIDTHS` would close it and
     * would also make every variant set in the shop report `isComplete()`
     * false until the owner re-ran the batch, which is a decision for him and
     * not for a delivery change. docs/PERF-PAGESPEED.md §6 carries it.
     *
     * @param  int  $per     cards per row at >= 1024px (BannerSet::LIMITS['per_view'], 1..8)
     * @param  int  $peekPct how much of the next card shows, in percent (0..90)
     * @param  int  $gap     the gap between cards in CSS pixels (0..48)
     */
    public static function bannerCardSizesAttribute(int $per, int $peekPct, int $gap): string
    {
        $per = max(1, $per);
        $divisor = number_format($per + ($peekPct / 100), 2, '.', '');

        /* Above 680px the section still draws its card frame: 24px of inset,
           2px of border and 18px of padding a side. */
        $frame = 'min(100vw - 24px, 1680px)';

        return '(max-width: 519px) calc((100vw - '.(24 + $gap).'px) / 1.5)'
            .', (max-width: 680px) calc((100vw - '.(24 + 2 * $gap).'px) / 2.45)'
            .', (max-width: 767px) calc((100vw - '.(62 + 2 * $gap).'px) / 2.45)'
            .', (max-width: 1023px) calc(('.$frame.' - '.(38 + 3 * $gap).'px) / 3.4)'
            .', calc(('.$frame.' - '.(38 + $gap * $per).'px) / '.$divisor.')';
    }

    /**
     * The SLIDER banner's frame (`.kbbs-vp`), which shows one picture at a time
     * at the full width of the content column.
     *
     * The slider is included from `store/home.blade.php:438` inside `.wrap`, so
     * the box is `min(100vw, --site-max) - 2 * --site-gutter` at every width —
     * there is no column count and no peek to divide by, which is why this is
     * one flat expression and bannerCardSizesAttribute() is five.
     *
     * ── WHY IT IS WRITTEN AT THE WIDEST THE SETTINGS CAN MAKE IT ────────────
     *
     * Both terms are OWNER SETTINGS, not constants: SiteLayout's `max` is a
     * range 1040..2400 (default 1680) and `gutter` is 8..48 (default 22). The
     * `sizes` attribute is parsed by the HTML parser and NOT by the cascade, so
     * `var(--site-max)` in it is not a length — the entry is dropped, and the
     * default for a dropped entry is 100vw. A stale hard-coded 1680/22 pair
     * would be worse than that: the day the owner widens the site the
     * expression UNDERSTATES, and understating is the failure that shows on the
     * screen — the browser picks a file too small for the frame and the
     * owner's banner comes out soft.
     *
     * So the two bounds are taken at the ends that can only overstate: the
     * widest site the slider can be asked for, and no gutter at all. It is the
     * same direction detailSizesAttribute() and homeTileSizesAttribute() round
     * in, and here it costs nothing measurable: at the shipped settings the
     * declaration says 390 where the box is 346 on a phone and 1280 where it
     * is 1236 on a laptop, and with `WIDTHS` offering 200/400/800 neither gap
     * changes the candidate the browser picks (692 and 780 device pixels both
     * take the 800w copy; a 1236px desktop frame takes it too, being the
     * widest on offer).
     *
     * ── AND IT PAIRS WITH detailSrcsetFor() ─────────────────────────────────
     *
     * srcsetFor() stops at 800w for tiles. This frame is 1236 CSS pixels at the
     * shipped width and more on a wide screen, so the cards banner's reasoning
     * applies here unchanged — and more sharply, because the slider's first
     * picture is the homepage's LCP element when the section is above the fold.
     */
    /*
     * ▲ CHECKED AGAINST A PORTRAIT PHONE FRAME, AND THEN CORRECTED FOR IT.
     *                                                               (Lane SEC)
     *
     * The homepage banner ships at the owner's two shapes — 1920 x 550 on
     * desktop and 500 x 600 on phones — and the phone one is PORTRAIT, which
     * every number above was written without. The expression itself was right
     * about the FRAME: `sizes` declares the frame's WIDTH, the frame is `.wrap`
     * wide at every viewport, and the ratio moves only its height. Measured,
     * Chromium, 390px: frame 366 x 439.2, no horizontal scroll.
     *
     * WHAT IT WAS WRONG ABOUT. `object-fit: cover` on a frame taller than it is
     * wide makes the HEIGHT binding for a landscape source, and the width the
     * browser then needs is not the frame's:
     *
     *     covered width = frameWidth x max(1, sourceAspect / frameAspect)
     *
     * With the shipped shapes and a 1920 x 550 picture that is 100vw x 4.189 —
     * 1533 CSS pixels against the 390 the flat expression declared, out by
     * 3.9x. Chromium chose the 400w copy and painted it across 1533 pixels.
     *
     * THE FACTOR IS 1 FOR EVERY CASE THIS SHOP HAD BEFORE, which is what makes
     * this safe to apply everywhere: a source no wider than its frame is
     * width-bound, `max(1, ...)` collapses, and the string is the one that was
     * returned before, byte for byte. PerfDeliveryTest's 810 x 1440 fixture is
     * such a case in BOTH frames and did not move.
     */
    public static function bannerSliderSizesAttribute(): string
    {
        return 'min(100vw, 2400px)';
    }

    /**
     * The same, for one picture in one frame of a known shape.
     *
     * ── WHY THIS IS PER PICTURE AND THE ONE ABOVE IS NOT ────────────────────
     *
     * Because the answer depends on the picture. `sizes` cannot ask the browser
     * "how wide will this file have to be to cover the box" — it is parsed
     * before any image is fetched and it has no way to name the source's shape.
     * But the shop KNOWS the source's shape: `banner_cards.image_w`/`image_h`
     * are read off the file once when the picture is saved. So the arithmetic
     * is done here, per card, and what goes into the attribute is a length.
     *
     * ── WHEN IT IS USED, WHICH IS THE INTERESTING PART ──────────────────────
     *
     * Mostly it is not. A slide with its own phone picture draws that picture
     * in the phone frame, the shapes agree, the factor is 1, and this returns
     * exactly what the flat expression returns. It earns its keep in the
     * FALLBACK — a slide with no phone picture, whose desktop picture has to
     * cover a portrait frame — and the decision there is deliberately to ask
     * for the heavy file rather than serve a soft one: a shop that has not
     * uploaded a phone picture is being shown a crop it did not choose either
     * way, and soft is the worse of the two. Measured in
     * BannerPhonePictureTest, and the numbers are in the lane's report.
     *
     * OVERSTATING IS STILL THE SAFE DIRECTION, so `$frameWidthCss` is the
     * widest the frame can be asked for and the cap is the same 2400px: a
     * pathological ratio cannot make this ask for a file that does not exist,
     * because the browser picks the widest candidate on offer anyway.
     *
     * @param  int|null  $sourceW  the picture's own width, or null if unknown
     * @param  int|null  $sourceH  the picture's own height
     * @param  float  $frameAspect  the frame's width divided by its height
     */
    public static function bannerSliderCoverSizes(?int $sourceW, ?int $sourceH, float $frameAspect): string
    {
        $flat = self::bannerSliderSizesAttribute();

        /*
         * UNKNOWN DIMENSIONS FALL BACK TO THE FLAT EXPRESSION rather than to a
         * guess. NULL is what `image_w`/`image_h` hold for a picture whose
         * header could not be read, and multiplying by a guessed aspect would
         * put a number in the attribute that nothing measured.
         */
        if ($sourceW === null || $sourceH === null || $sourceW < 1 || $sourceH < 1 || $frameAspect <= 0.0) {
            return $flat;
        }

        $factor = ($sourceW / $sourceH) / $frameAspect;

        /*
         * ONE DECIMAL PLACE, and the comparison is against the ROUNDED value.
         * A source a hair wider than its frame gives 1.0004; emitting
         * `min(100vw * 1, 2400px)` for it would be a different string for the
         * same page, which is a byte-for-byte diff with no meaning in it — and
         * StorefrontEnglishUnchangedTest cannot tell that from a real one.
         */
        $factor = round($factor, 1);

        if ($factor <= 1.0) {
            return $flat;
        }

        return 'min(100vw * '.rtrim(rtrim(number_format($factor, 1, '.', ''), '0'), '.').', 2400px)';
    }

    /**
     * The "frequently bought together" row: `.kbb-fbt-item img` is a fixed 90px
     * square at every viewport, declared inline on the element itself, so there
     * is no viewport term to write. The 200w copy covers it to
     * device-pixel-ratio 2 and 400w to ratio 4.
     */
    public static function fbtSizesAttribute(): string
    {
        return '90px';
    }

    /**
     * The quick-view modal's photograph (`.qv-media img`, width:100%).
     *
     * `.qv-modal` is max-width:760px with 22px of padding a side (716px of
     * content) and `.qv-wrap` is two equal columns with a 22px gap until it
     * collapses to one at 640px; the backdrop adds 18px of padding a side.
     *
     *   > 640   (716 - 22) / 2      = 347px
     *   <= 640  100vw - 36 - 44     = 100vw - 80px
     *
     * 350px declared for the top band, a shade above the 347 measured.
     */
    public static function quickViewSizesAttribute(): string
    {
        return '(max-width: 640px) calc(100vw - 80px), 350px';
    }

    /**
     * A SET MEMBER'S photograph on the set's own product page. (Lane SP)
     *
     * `.ksp-grid` is `repeat(auto-fill, minmax(min(100%, 150px), 1fr))` inside
     * `.sec`, and `.ksp-ph` is `aspect-ratio:1` at `width:100%`, so the drawn
     * width is exactly one grid track:
     *
     *   390px  content width 358 (16px gutter a side), 12px gap
     *          -> 2 tracks of 173px          = 45vw, near enough and above
     *   1280px .wrap tops out well under 1200 and 14px gaps
     *          -> 7 tracks of about 150px    = 170px declared, a shade above
     *
     * 45vw for the phone band and a flat 170px above it. Declared a touch wide
     * on both, which costs a few kilobytes on one breakpoint and never serves a
     * picture too small for the box it is in.
     */
    public static function setMemberSizesAttribute(): string
    {
        return '(max-width: 700px) 45vw, 170px';
    }

    /**
     * And what a gallery THUMBNAIL will be drawn at: `.gthumb` is a fixed 66px
     * square at every viewport (kbb-product.css:120), so there is no viewport
     * term to write.
     *
     * 66px is DELIBERATELY A SHADE ABOVE the box. The border is 2px a side and
     * `box-sizing` is border-box, so Chromium draws 62 CSS pixels of picture on
     * a desktop and 52 on a phone, where `.pdp .gthumb` narrows to 56px. The
     * declaration rounds up for the same reason every other one in this class
     * does: overstating costs at most one step up a candidate list, and
     * understating serves a file too small for the box.
     *
     * With the 200w tier this resolves to the 200w copy at every device-pixel
     * ratio up to 3 (66 x 3 = 198), which is every screen a shopper can bring.
     *
     * Thumbnails use srcsetFor(), not detailSrcsetFor(): a 66px box has no use
     * for a 1000px original, so there is nothing to read a header for.
     *
     * ── AND WHY IT TAKES AN ASPECT NOW (Lane IM2) ───────────────────────────
     *
     * The owner: "i want the product gallery images thumbnail to be cropped or
     * load square size mini 100x100 only to reduce the page load." The strip is
     * now `object-fit: cover` rather than `contain`, so each tile is a square
     * CROP of its photograph instead of the whole photograph letterboxed inside
     * a square. That is the picture he asked for, and it costs nothing — but it
     * quietly invalidates the arithmetic above, and that IS worth a header
     * read.
     *
     * A `w` descriptor states a candidate's WIDTH and `sizes` states the box's
     * width, and under `contain` those two are the right pair: the picture is
     * scaled until it fits, so the binding dimension is whichever one runs out
     * first and the width descriptor is never an overstatement. Under `cover`
     * the picture is scaled until it FILLS, so the binding dimension is the
     * image's SHORT side — and for a photograph wider than it is tall the short
     * side is the height, which no descriptor in a srcset mentions.
     *
     * Measured on this preview: a 1778x1000 photograph's 200w copy is 200x112.
     * Covering a 66px square at device-pixel-ratio 3 wants 198 device pixels
     * each way, so 112 rows of pixels are stretched to 198 — a 1.77x upscale,
     * on a tile that was sharp the day before. A square or portrait original is
     * untouched by any of this: its short side IS its width, and 200 >= 198.
     *
     * So: `$aspect` is the ORIGINAL's width divided by its height, and the
     * declaration is the box multiplied by max(1, aspect) — the width a file
     * must have for its short side to cover the box. For every square and every
     * portrait that is exactly 1, so this returns the same '66px' it always
     * returned and the rendered markup does not move a byte. Only a landscape
     * photograph widens, and only by what it actually needs.
     *
     * The default is the unchanged answer, so every other caller and every
     * photograph whose header could not be read gets today's behaviour rather
     * than a guess.
     *
     * @param  float|null  $aspect  the original's width / height, or null for "not known"
     */
    public static function thumbSizesAttribute(?float $aspect = null): string
    {
        if ($aspect === null || ! is_finite($aspect) || $aspect <= 1.0) {
            return '66px';
        }

        // Ceil, not round: this declaration's whole job is to not understate,
        // and a fractional CSS pixel is not a thing a sizes attribute can say.
        return (int) ceil(66 * min($aspect, 8.0)).'px';
    }

    /**
     * A photograph's width divided by its height, or null when that cannot be
     * answered from this web root.
     *
     * ONLY FOR A CALLER THAT HAS ALREADY DECIDED THE ANSWER CAN BUY SOMETHING.
     * This opens the original's header, which is the one cost srcsetFor()
     * refuses to pay per tile; detailSrcsetFor() pays it and explains when that
     * trade is right. The gallery strip is the one caller, it pays it once per
     * shot on a page that already reads the same headers for the main frame's
     * srcset, and it asks only when a variant exists to choose between.
     *
     * getimagesize() and not a database column for the reason the whole class
     * is filesystem-backed: a column can disagree with the disk, and a `sizes`
     * computed from a stale one serves the wrong file with no way to tell.
     */
    public static function aspectOf(string $image): ?float
    {
        $parts = self::split($image);

        if ($parts === null) {
            return null;
        }

        $source = self::insidePublicRoot($parts[2]);

        if ($source === null) {
            return null;
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            return null;
        }

        return (int) $info[0] / (int) $info[1];
    }

    /**
     * The pixel size of a photograph on this site's own disk, or null.  (Lane PW)
     *
     * aspectOf()'s twin, for the one caller that needs both numbers rather
     * than their ratio: App\Support\Seo publishes og:image:width and
     * og:image:height for a product's share picture, which lets Facebook and
     * WhatsApp draw the preview card at its real shape on the FIRST share
     * instead of after a second scrape. Null — and no tags — for anything that
     * is not a file under the web root, exactly as aspectOf() answers.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function sizeOf(string $image): ?array
    {
        $parts = self::split($image);

        if ($parts === null) {
            return null;
        }

        $source = self::insidePublicRoot($parts[2]);

        if ($source === null) {
            return null;
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * What a REVIEW photograph is drawn at on the review wall
     * (partials/reviews.blade.php, `.sr-pp`), which is the one surface on this
     * shop where a shopper's phone camera original is painted into a card.
     *
     * ── WHY THIS TAKES THE COLUMN COUNT RATHER THAN GUESSING IT ─────────────
     *
     * `.sr-grid` is `column-count: var(--sr-cols, 4)` and `--sr-cols` is set
     * from Review Settings, so the card's width is the OWNER'S, not this
     * file's. Every other sizes declaration here can hardcode its breakpoints
     * because the box behind it is fixed; this one cannot, and a hardcoded
     * "300px" would be right at the default four columns and half the truth at
     * two — which is a soft review photograph on the setting the owner is most
     * likely to reach for. The partial already has `$cols` in hand
     * (ReviewSettings::all()), so the exact number is free.
     *
     * THE ARITHMETIC, from sorina-reviews.css and the site's own gutters:
     *   content width  = viewport - 2 x 22px page gutter, capped at 1680px
     *   card           = (content - (cols - 1) x 14px column gap) / cols
     *   card interior  = card - 2 x 16px card padding
     *   .sr-pp.one     = the whole interior;  .sr-pp.multi = half of it, less
     *                    the 4px grid gap
     * Below 760px the stylesheet overrides the column count to 2 and the gap to
     * 10px, so that branch is written out rather than derived.
     *
     * A BOX WITH A FIXED HEIGHT AND A COVER FIT, so the same caveat as the
     * gallery thumbnail applies in principle — but not in size. `.sr-pp.one` is
     * 160px tall against ~266px of width and `.sr-pp.multi` 76px against
     * ~131px, so both boxes are WIDER than they are tall and a photograph has
     * to be more than 1.66:1 before its height binds. At that point the
     * understatement is under 7%, which cannot change which candidate a browser
     * picks out of a list that steps 200 / 400 / 800. The gallery thumbnail is
     * a square box, where the same question is a 1.77x upscale — that is why
     * one of these reads a header and this one does not.
     *
     * @param  int   $columns  the value of --sr-cols
     * @param  bool  $multi    true for the two-up grid a card with 2+ photographs draws
     */
    public static function reviewPhotoSizesAttribute(int $columns, bool $multi): string
    {
        // The setting is an integer the owner types; a zero or a negative would
        // divide by nothing and a huge one would declare a sub-pixel box.
        $columns = max(1, min(8, $columns));

        $phone = $multi ? '21vw' : '43vw';

        /*
         * FLATTENED RATHER THAN NESTED. calc() nests legally, but `sizes` is
         * parsed as a <source-size-value> and a browser that will not take the
         * value drops the whole attribute and assumes 100vw — which on this
         * card is a four-times overstatement and would pull the 800w copy for
         * a 131px box. The two-up case is therefore written as one expression,
         * ((content - gaps) / columns - 36px) / 2, which is the card interior
         * less the 4px grid gap, halved.
         */
        $gaps = ($columns - 1) * 14;

        $wide = static function (string $content) use ($columns, $multi, $gaps): string {
            return $multi
                ? "calc((($content - {$gaps}px) / $columns - 36px) / 2)"
                : "calc(($content - {$gaps}px) / $columns - 32px)";
        };

        // Three branches and not two: above 1724px the page stops growing (the
        // 1680px site cap plus its 22px gutters), so a vw term there would go
        // on declaring a box that is no longer getting any wider.
        return '(max-width: 760px) '.$phone
            .', (max-width: 1724px) '.$wide('100vw - 44px')
            .', '.$wide('1636px');
    }

    /**
     * ONE copy of a photograph, at a stated width, for a box that cannot carry
     * a srcset — or the original unchanged when that copy is not on disk.
     *
     * WHY A SINGLE URL AND NOT A SRCSET. Everything else in this class hands
     * the browser a list and lets it choose, which is free and always better.
     * It is only available to an <img>. The basket drawer, the basket page, the
     * checkout's browsed-and-received lines and the order-received summary draw
     * their line thumbnails as a CSS `background-image` on a 42px square — a
     * deliberate choice made long before any of this existed, because those
     * boxes crop with `center/cover` and an <img> would need object-fit and a
     * positioned wrapper per line. A CSS background takes exactly one URL.
     * `image-set()` exists, but it selects on device-pixel-ratio rather than on
     * width, so it cannot express "this box is 42px" — the thing that actually
     * decides which file is right here.
     *
     * So: pick the width once, on the server, from the same files the srcset
     * would have offered. A basket with five lines was pulling five
     * full-resolution photographs — ~290KB each measured on this catalogue's
     * sizes — to paint five 42px squares.
     *
     * WHY 400 AND NOT 200 AT THOSE CALL SITES. `.kc-th` is 42px, and 200w is
     * already five times what it can show; 400w would look like the wrong
     * choice. It is not, for one reason that is specific to those boxes: their
     * size is a SETTING. `--cp-thumb` and `--cp-thumb-m` are set from
     * Appearance → Cart panel, so the owner can make that square 120px without
     * this file knowing. A single URL has no second candidate to fall back to
     * when that happens, and a soft basket thumbnail is a defect the shop keeps
     * forever. 400w covers a 133px square at ratio 3 and still costs about 7%
     * of the original. The caller states the width; this only refuses to invent
     * one.
     *
     * NEVER RETURNS SOMETHING THAT IS NOT THERE. The original comes back
     * unchanged unless a real file is found for the width asked for, which is
     * the same promise srcsetFor() makes and for a stronger reason: with a
     * background there is no `src` underneath to fall back to, so a URL that
     * 404s is an empty square.
     *
     * AND IT CANNOT BE POINTED AT ANYTHING. $width must be one of WIDTHS —
     * nothing else names a directory this class writes, and a caller that could
     * pass an arbitrary integer would be building a path segment out of one.
     * The image reference goes through the same split() as everything else
     * here, which rejects "..", a segment that is empty or ".", a NUL, a query
     * or fragment, another origin, a non-resizable extension, and the cache's
     * own directory. This adds no new way in.
     */
    public static function variantUrl(string $image, int $width): string
    {
        if (! in_array($width, self::WIDTHS, true)) {
            return $image;
        }

        $parts = self::split($image);

        if ($parts === null) {
            return $image;
        }

        [$prefix, $rel, $fsRel] = $parts;

        return is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))
            ? $prefix.'/'.self::DIR.'/'.$width.'/'.$rel
            : $image;
    }

    /**
     * Make the missing copies of one photograph.
     *
     * Idempotent: a variant that is already there is left alone, so a batch
     * that was interrupted can simply be run again.
     *
     * @return array{made: int, skipped: int, reason: ?string}
     */
    public static function generate(string $image): array
    {
        if (! self::available()) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'no image library'];
        }

        $parts = self::split($image);

        if ($parts === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a local image'];
        }

        [, , $fsRel] = $parts;
        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'file not found'];
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) $info[0] < 1) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'unreadable image'];
        }

        [$srcWidth, $srcHeight] = [(int) $info[0], (int) $info[1]];
        $type = (int) ($info[2] ?? 0);

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a resizable type'];
        }

        $made = $skipped = 0;
        $wanted = [];

        foreach (self::WIDTHS as $width) {
            // Never upscale. A 300px original has no 400px version that is not
            // a lie about how much detail is in it, and a srcset candidate
            // whose width descriptor overstates the pixels behind it is how a
            // browser ends up choosing the blurriest file on offer.
            if ($srcWidth <= $width) {
                continue;
            }

            if (is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $skipped++;

                continue;
            }

            $wanted[] = $width;
        }

        if ($wanted === []) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => null];
        }

        // One decode for every width. Reading the original twice is the single
        // most expensive thing this function could do.
        $src = self::read($source, $type);

        if ($src === null) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => 'could not decode'];
        }

        try {
            foreach ($wanted as $width) {
                $height = max(1, (int) round($srcHeight * ($width / $srcWidth)));

                if (self::write($src, $srcWidth, $srcHeight, $width, $height, $type, self::DIR.'/'.$width.'/'.$fsRel)) {
                    $made++;
                }
            }
        } finally {
            imagedestroy($src);
        }

        return ['made' => $made, 'skipped' => $skipped, 'reason' => null];
    }

    /**
     * The cache directory a crop at one frame shape lives under, or null.
     *                                                               (Lane SEC)
     *
     * `c500x600`. One segment, and it is built from a TOKEN the caller took out
     * of BannerSet::SLIDER_RATIOS — a constant — and never from anything an
     * operator typed. The regex below is the second lock rather than the first:
     * a path segment assembled from a setting is how a cache directory becomes
     * a traversal, and rule 5 says a select stores one of its own options.
     *
     * Anything that is not `<digits>x<digits>` within sane bounds is refused
     * outright, which also keeps the directory name readable on a server the
     * owner cannot list.
     */
    public static function cropDir(string $token): ?string
    {
        return preg_match('/^[1-9][0-9]{0,4}x[1-9][0-9]{0,4}$/', $token) === 1
            ? self::DIR.'/c'.$token
            : null;
    }

    /**
     * The widths a crop is worth writing at, largest first.
     *
     * WIDTHS plus the crop's OWN width, which is the part that matters. A
     * 1920 x 550 banner cropped to a 5 : 6 phone frame is 458 x 550 — every
     * pixel `object-fit: cover` was ever going to show — and stopping at 400w
     * would throw away detail the source actually has. Never upscaling is the
     * same promise generate() makes and for the same reason: a candidate whose
     * width descriptor overstates the pixels behind it is how a browser picks
     * the blurriest file on offer.
     *
     * @return list<int>
     */
    private static function cropWidths(int $cropWidth): array
    {
        $widths = [];

        foreach (self::WIDTHS as $width) {
            if ($cropWidth > $width) {
                $widths[] = $width;
            }
        }

        if (! in_array($cropWidth, $widths, true)) {
            $widths[] = $cropWidth;
        }

        return $widths;
    }

    /**
     * The rectangle `object-fit: cover` shows of a picture in a frame.
     *
     * ── IT IS THE SAME RECTANGLE THE BROWSER ALREADY PICKS ──────────────────
     *
     * That is the whole claim of the crop, and it is why this is not a
     * composition decision. `cover` scales to the larger of the two ratios and
     * centres the overflow, so a 1920 x 550 banner in a 5 : 6 frame shows the
     * middle 458 columns and all 550 rows — today, with no crop file anywhere.
     * Extracting that band on the server changes WHAT IS DOWNLOADED and cannot
     * change what is seen.
     *
     * WHICH ALSO SAYS WHAT IT CANNOT FIX. A shop banner usually has its subject
     * off-centre, and a centre crop of a wide picture is as likely to be
     * background as product — but that is true of the page as it stands, not
     * something this introduces. Moving it would need a focal point the owner
     * sets, which is a control and a round of its own.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height
     */
    public static function coverRect(int $sourceW, int $sourceH, float $frameAspect): array
    {
        if ($sourceW < 1 || $sourceH < 1 || $frameAspect <= 0.0) {
            return [0, 0, max(1, $sourceW), max(1, $sourceH)];
        }

        if (($sourceW / $sourceH) > $frameAspect) {
            // Wider than the frame: full height, middle columns.
            $width = max(1, (int) round($sourceH * $frameAspect));
            $width = min($width, $sourceW);

            return [(int) round(($sourceW - $width) / 2), 0, $width, $sourceH];
        }

        // Taller than the frame: full width, middle rows.
        $height = max(1, (int) round($sourceW / $frameAspect));
        $height = min($height, $sourceH);

        return [0, (int) round(($sourceH - $height) / 2), $sourceW, $height];
    }

    /**
     * Make the phone-shaped crops of one photograph.               (Lane SEC)
     *
     * ── WHY THIS EXISTS, IN ONE MEASUREMENT ─────────────────────────────────
     *
     * A banner slide with no phone picture shows its desktop picture in the
     * portrait phone frame, cropped by `cover` to about a quarter of its width.
     * Serving that correctly without a crop file means asking for the whole
     * 1920px original — measured on a photographic 1920 x 550 JPEG, 152.6 KB —
     * and throwing three quarters of every pixel away in the browser. The same
     * band written out on the server is 38.6 KB at its native 458 x 550, with
     * IDENTICAL pixels. Four times smaller for the same picture.
     *
     * ── AND WHAT IT COSTS TO MAKE, BECAUSE THAT IS THE OTHER HALF ───────────
     *
     * Measured, GD, this machine:
     *
     *   1920 x 550   (153 KB)   43 ms   ->  200w, 400w, 458w
     *   3000 x 900   (367 KB)   63 ms   ->  200w, 400w, 750w
     *   4000 x 1200  (624 KB)  159 ms   ->  200w, 400w, 800w, 1000w
     *
     * One decode for every width, as generate() does and for the same reason.
     * It is never called on a storefront request: a banner card's pictures are
     * cropped when the card is SAVED, and the ones that existed before this
     * shipped are cropped by the migration that adds the column. A shop has
     * three to five banner pictures, not a catalogue, so the backfill is under
     * a second inside an update the owner is already waiting on.
     *
     * IDEMPOTENT, like generate(): a crop already on disk is left alone, so a
     * backfill that was interrupted can simply be run again.
     *
     * @return array{made: int, skipped: int, reason: ?string}
     */
    public static function generateCrop(string $image, string $token, float $frameAspect): array
    {
        $dir = self::cropDir($token);

        if ($dir === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a frame shape this cache names'];
        }

        if (! self::available()) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'no image library'];
        }

        $parts = self::split($image);

        if ($parts === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a local image'];
        }

        [, , $fsRel] = $parts;
        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'file not found'];
        }

        $info = @getimagesize($source);

        if (! is_array($info) || (int) $info[0] < 1) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'unreadable image'];
        }

        [$sourceW, $sourceH] = [(int) $info[0], (int) $info[1]];
        $type = (int) ($info[2] ?? 0);

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'not a resizable type'];
        }

        [$cropX, $cropY, $cropW, $cropH] = self::coverRect($sourceW, $sourceH, $frameAspect);

        /*
         * A PICTURE ALREADY THE FRAME'S SHAPE HAS NOTHING TO CROP, and writing
         * a second copy of it under another name would be pure waste — the
         * plain WIDTHS variants already say everything about it. The storefront
         * reader falls back to those, so answering "nothing to do" here is not
         * a gap.
         */
        if ($cropX === 0 && $cropY === 0 && $cropW === $sourceW && $cropH === $sourceH) {
            return ['made' => 0, 'skipped' => 0, 'reason' => 'already the frame shape'];
        }

        $made = $skipped = 0;
        $wanted = [];

        foreach (self::cropWidths($cropW) as $width) {
            if (is_file(public_path($dir.'/'.$width.'/'.$fsRel))) {
                $skipped++;

                continue;
            }

            $wanted[] = $width;
        }

        if ($wanted === []) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => null];
        }

        $src = self::read($source, $type);

        if ($src === null) {
            return ['made' => 0, 'skipped' => $skipped, 'reason' => 'could not decode'];
        }

        try {
            foreach ($wanted as $width) {
                $height = max(1, (int) round($cropH * ($width / $cropW)));

                if (self::write($src, $cropW, $cropH, $width, $height, $type, $dir.'/'.$width.'/'.$fsRel, $cropX, $cropY)) {
                    $made++;
                }
            }
        } finally {
            imagedestroy($src);
        }

        return ['made' => $made, 'skipped' => $skipped, 'reason' => null];
    }

    /**
     * The srcset of one picture's crops at one frame shape, or ''.
     *
     * '' MEANS "THERE ARE NO CROPS", and every caller has to treat it as the
     * instruction to fall back rather than as an error. A crop file can be
     * missing for reasons that are nobody's fault — the shop has no GD, the
     * picture is an SVG, the source is already the frame's shape, the backfill
     * has not run on a restored database — and in every one of them the page
     * must still draw the picture. The fallback is what the shop did before
     * crops existed: the whole photograph, asked for at the width covering the
     * frame needs.
     *
     * NO ORIGINAL IS APPENDED, which is the one way this differs from
     * detailSrcsetFor(). There is no "original" of a crop; the largest file
     * written IS the crop at its native width, so the candidate list is
     * complete by construction and a browser asking for more has nothing more
     * to be given.
     */
    public static function cropSrcsetFor(string $image, string $token): string
    {
        $dir = self::cropDir($token);

        if ($dir === null) {
            return '';
        }

        $parts = self::split($image);

        if ($parts === null) {
            return '';
        }

        [$prefix, $rel, $fsRel] = $parts;
        $root = public_path($dir);

        if (! is_dir($root)) {
            return '';
        }

        $candidates = [];

        /*
         * The widths are read off the DISK rather than recomputed, because the
         * native one depends on the source's own dimensions and this method has
         * deliberately not opened the file. glob() over one directory level is
         * one readdir; the alternative is a getimagesize() on the original on
         * every render of the home page.
         */
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $widthDir) {
            $width = (int) basename($widthDir);

            if ($width < 1 || ! is_file($widthDir.'/'.$fsRel)) {
                continue;
            }

            $candidates[$width] = $prefix.'/'.$dir.'/'.$width.'/'.$rel.' '.$width.'w';
        }

        if ($candidates === []) {
            return '';
        }

        ksort($candidates);

        return implode(', ', $candidates);
    }

    /**
     * Throw away every cached copy of one photograph, and say how many went.
     *
     * ── WHY THIS EXISTS: THE CACHE HAD NO INVALIDATION AT ALL ───────────────
     *
     * generate() skips a width that is already on disk — which is what makes an
     * interrupted batch safe to re-run, and is right. The consequence is that
     * NOTHING in this application could ever remove a variant. The originals
     * have two ways out, and the copies had none:
     *
     *   DELETE. Admin\MediaLibraryApiController::destroy() unlinks the original
     *   under public/uploads/ and forgets the row. Both copies stayed, in a
     *   directory the owner has no shell to reach and no screen that lists it.
     *   On the measured numbers — 121KB of variants per 1000x1000 JPEG — a
     *   catalogue's worth of deleted photographs is tens of megabytes of files
     *   nobody can see, name or remove.
     *
     *   REPLACE. Less likely but worse. MediaUploadController names every
     *   upload `Ymd-His-<random>.ext`, so a re-upload gets a NEW path and
     *   cannot collide; but anything that does put different bytes at an
     *   existing path — FTP, a restored backup, a future editor that overwrites
     *   in place — leaves the old copies in the srcset. The result is the kind
     *   of bug that takes a day to believe: the product page shows the new
     *   photograph on a desktop and the old one on a phone, because `src` is
     *   the new file and every srcset candidate is stale.
     *
     * So: one method, called when an original goes away or is replaced, and
     * pinned by a test that asserts the disk is actually clean afterwards.
     *
     * IT PRUNES ITS OWN EMPTY DIRECTORIES, up to but never including
     * `img-cache/<width>/`. The cache mirrors the original's path, so deleting
     * a catalogue leaves the whole `uploads/products/` tree behind as empty
     * directories — invisible, but real inodes on a modest host with a file
     * quota. @rmdir only succeeds on an empty directory, so a sibling variant
     * still in use always stops the walk; there is no case where this can
     * remove a directory that still holds a file.
     *
     * NEVER THROWS, and never touches anything outside `img-cache/`. The
     * callers are a delete endpoint and an upload endpoint, and neither should
     * fail because a cached copy could not be unlinked — the worst outcome of a
     * failure here is a stale file, which is exactly what the situation was
     * before this existed.
     */
    public static function forget(string $image): int
    {
        $parts = self::split($image);

        if ($parts === null) {
            return 0;
        }

        [, , $fsRel] = $parts;
        $removed = 0;

        /*
         * ▲ THE CROP DIRECTORIES ARE WALKED TOO, AND LEAVING THEM OUT WOULD
         *   HAVE REINTRODUCED THE EXACT BUG THIS METHOD EXISTS FOR.
         *                                                          (Lane SEC)
         *
         * The header above records that nothing in this application could ever
         * remove a variant, and what that cost: tens of megabytes of files
         * nobody can see or name, and — worse — a REPLACED original serving the
         * new photograph on a desktop and the old one on a phone, because `src`
         * is new and every srcset candidate is stale.
         *
         * A crop is a srcset candidate on exactly the element that fault would
         * show on. So the widths walked are the fixed ones PLUS every
         * `img-cache/c<w>x<h>/<width>` a crop has been written under, read off
         * the disk rather than from a list — a shop can carry crops at any
         * frame shape its owner has picked, and a hard-coded list here would go
         * stale the first time he picks another.
         */
        $roots = [];

        foreach (self::WIDTHS as $width) {
            $roots[] = self::DIR.'/'.$width;
        }

        foreach (glob(public_path(self::DIR).'/c*', GLOB_ONLYDIR) ?: [] as $cropRoot) {
            foreach (glob($cropRoot.'/*', GLOB_ONLYDIR) ?: [] as $widthDir) {
                $roots[] = self::DIR.'/'.basename($cropRoot).'/'.basename($widthDir);
            }
        }

        foreach ($roots as $rootRel) {
            $root = public_path($rootRel);
            $file = $root.'/'.$fsRel;

            if (is_file($file) && @unlink($file)) {
                $removed++;
            }

            // Walk back up the mirrored path, stopping at the width directory
            // itself so the cache root survives an empty catalogue.
            $directory = \dirname($file);

            while (
                $directory !== $root
                && str_starts_with($directory, $root.'/')
                && @rmdir($directory)
            ) {
                $directory = \dirname($directory);
            }
        }

        return $removed;
    }

    /**
     * Whether this photograph is one this server could resize: a file of a
     * resizable type, sitting in this web root, reachable at the path the tile
     * asks for it by.
     *
     * The negative answer is the useful one. It is what lets a screen say "440
     * of these are on another domain" instead of reporting a backlog that can
     * never go down.
     */
    public static function isLocal(string $image): bool
    {
        $parts = self::split($image);

        return $parts !== null && self::insidePublicRoot($parts[2]) !== null;
    }

    /**
     * Whether every copy this photograph should have already exists.
     *
     * "Should have" is the point: an original narrower than a target width is
     * complete without it, so a small logo does not sit in the backlog forever
     * being counted as unfinished work.
     *
     * The missing files are looked for first and the original is opened only if
     * one of them is absent. Once a catalogue has been through the batch that
     * is the common case by a wide margin, and it turns a screen that reads
     * every original's header into one that does not touch them at all.
     */
    public static function isComplete(string $image): bool
    {
        $parts = self::split($image);

        if ($parts === null) {
            return true;
        }

        [, , $fsRel] = $parts;
        $missing = [];

        foreach (self::WIDTHS as $width) {
            if (! is_file(public_path(self::DIR.'/'.$width.'/'.$fsRel))) {
                $missing[] = $width;
            }
        }

        if ($missing === []) {
            return true;
        }

        $source = self::insidePublicRoot($fsRel);

        if ($source === null) {
            return true;
        }

        $info = @getimagesize($source);

        if (! is_array($info)) {
            return true;
        }

        foreach ($missing as $width) {
            if ((int) $info[0] > $width) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Split a rendered image reference into the prefix it is served under and
     * the path below the web root, or null when it is not this site's file.
     *
     * THE PREFIX IS KEPT RATHER THAN REBUILT. Whatever the tile's `src` is
     * served under — nothing, "/kbb-upgrade", or an absolute origin — the
     * variant is offered under exactly the same thing, with only the path
     * swapped. So a variant URL can never be more broken, or less, than the
     * `src` beside it; if the catalogue holds a path missing its base prefix,
     * both are wrong together and the tile still shows a photograph from `src`
     * in every browser that does not support srcset. Rebuilding the prefix from
     * config instead is how this repository produced /kbb-upgrade/kbb-upgrade/
     * twice already.
     *
     * @return array{0: string, 1: string, 2: string}|null [prefix, url path, filesystem path]
     */
    private static function split(string $image): ?array
    {
        $image = trim($image);

        if ($image === '' || str_contains($image, "\0")) {
            return null;
        }

        $prefix = '';

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $image) === 1) {
            // An absolute URL is a promise about some origin's bytes. Only this
            // request's own origin can be checked against this web root, and a
            // photograph on the old WooCommerce domain is not ours to resize.
            $parsed = parse_url($image);

            if (! is_array($parsed) || ! isset($parsed['host'], $parsed['path'])) {
                return null;
            }

            // A cache-buster or a fragment would be dropped from the variant
            // URL and kept on the src, which is two different requests for what
            // is meant to be the same photograph. Left alone instead.
            if (isset($parsed['query']) || isset($parsed['fragment'])) {
                return null;
            }

            if (! app()->bound('request') || app()->runningInConsole()) {
                return null;
            }

            $host = strtolower((string) $parsed['host']);

            if ($host !== strtolower(request()->getHost())) {
                return null;
            }

            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
            $prefix = (isset($parsed['scheme']) ? $parsed['scheme'].':' : '').'//'.$parsed['host'].$port;
            $path = (string) $parsed['path'];
        } elseif (str_starts_with($image, '/')) {
            $path = $image;
        } else {
            // A bare relative path resolves against whichever page is being
            // rendered, so it already means different files on /shop/ and on
            // /product/x/. Nothing sensible can be done with it.
            return null;
        }

        if (str_contains($path, '?') || str_contains($path, '#')) {
            return null;
        }

        // The base path the site is served under is part of the prefix, not part
        // of the path below the web root: public_path() IS the directory that
        // /kbb-upgrade addresses.
        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $prefix .= $base;
            $path = substr($path, strlen($base));
        }

        $rel = ltrim($path, '/');

        if ($rel === '') {
            return null;
        }

        // The stored URL may be percent-encoded; the file on disk is not. The
        // two forms are kept apart on purpose — the decoded one addresses the
        // filesystem, the encoded one goes back into the HTML unchanged.
        $fsRel = rawurldecode($rel);

        if (str_contains($fsRel, "\0")) {
            return null;
        }

        foreach (explode('/', $fsRel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        // Its own cache is not an input to itself.
        if (str_starts_with($fsRel, self::DIR.'/')) {
            return null;
        }

        $extension = strtolower(pathinfo($fsRel, PATHINFO_EXTENSION));

        if (! in_array($extension, self::RESIZABLE, true)) {
            return null;
        }

        return [$prefix, $rel, $fsRel];
    }

    /**
     * The absolute path of a web-root-relative file, or null when it is not
     * there or has escaped the web root.
     *
     * The segment check in split() already rejects "..", and this is the second
     * belt: a symlink inside the web root can point anywhere, and realpath is
     * what follows it before anything is read.
     */
    private static function insidePublicRoot(string $fsRel): ?string
    {
        $root = realpath(public_path());
        $file = realpath(public_path($fsRel));

        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($root, '/').'/') ? $file : null;
    }

    /** @return \GdImage|null */
    private static function read(string $file, int $type)
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * Resample into one width and put it where the srcset will look for it.
     *
     * WRITTEN BESIDE AND THEN RENAMED. A file being written is a file the web
     * server will happily serve half of, and a half-written JPEG in a srcset is
     * a broken tile that stays broken until something overwrites it. rename()
     * within one filesystem is atomic, so the path either does not exist or
     * holds a complete image.
     *
     * ── $srcX/$srcY AND WHAT $srcWidth/$srcHeight NOW MEAN ───────── (Lane SEC)
     *
     * They are the SOURCE RECTANGLE, not the source image. Defaulting to 0,0
     * with the caller's own width and height they are exactly the whole-image
     * copy this method has always made — every existing call is byte-identical
     * and none of them changed — and a caller that wants a crop passes the
     * rectangle `object-fit: cover` would have shown.
     *
     * TWO PARAMETERS AND NOT A SECOND METHOD, because the alternative is a
     * near-copy of the transparency handling, the atomic rename and the four
     * encoders, and the copy that drifts would be the one used by the newer
     * caller. imagecopyresampled() already takes both rectangles; this only
     * stops hard-coding one of them.
     */
    private static function write(
        \GdImage $src,
        int $srcWidth,
        int $srcHeight,
        int $width,
        int $height,
        int $type,
        string $target,
        int $srcX = 0,
        int $srcY = 0
    ): bool {
        $destination = public_path($target);
        $directory = \dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        $out = imagecreatetruecolor($width, $height);

        if ($out === false) {
            return false;
        }

        try {
            // PNG and WebP can carry transparency, and a truecolor canvas starts
            // opaque black. Without this a cut-out product shot gains a black
            // background at 400px and keeps a white one at 1000px.
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($out, false);
                imagesavealpha($out, true);
                imagefilledrectangle($out, 0, 0, $width, $height, (int) imagecolorallocatealpha($out, 0, 0, 0, 127));
            }

            imagecopyresampled($out, $src, 0, 0, $srcX, $srcY, $width, $height, $srcWidth, $srcHeight);

            $temporary = $destination.'.'.bin2hex(random_bytes(4)).'.part';

            $written = match ($type) {
                IMAGETYPE_JPEG => @imagejpeg($out, $temporary, self::QUALITY),
                IMAGETYPE_PNG => @imagepng($out, $temporary, 6),
                IMAGETYPE_WEBP => @imagewebp($out, $temporary, self::QUALITY),
                default => false,
            };

            if ($written !== true || ! is_file($temporary)) {
                @unlink($temporary);

                return false;
            }

            if (! @rename($temporary, $destination)) {
                @unlink($temporary);

                return false;
            }

            @chmod($destination, 0644);

            return true;
        } finally {
            imagedestroy($out);
        }
    }
}
