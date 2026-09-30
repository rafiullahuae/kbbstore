<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Product;
use App\Support\DemoProductDetails;
use App\Support\DemoProductShots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * THE GALLERY STRIP WAS NOT THERE, AND THE OWNER WENT LOOKING FOR IT
 * =============================================================================
 *                                                                    (Lane GAL)
 *
 *   "also i can not see the product gallery thumnails, add some demo thumnails
 *    so i can see in action."
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * Open any product on his shop and under the big square frame there was
 * nothing — no row of small squares, nothing to click, no way to tell the page
 * even has a gallery. partials/product-gallery.blade.php draws the `.gthumbs`
 * strip only `@if ($shotCount > 1)`, and Store\ProductController::gallery()
 * builds that count from `products`.image merged with `products`.images.
 * DemoCatalogueSeeder set NEITHER column on any of its 24 rows, so the count
 * was 1 on every demo product and the `@if` never opened. gallery() does pad
 * the list to six labelled shots — but only `when DemoContent::enabled()`, and
 * that setting is `demo_content` defaulting to false, which is how it ships.
 *
 * ── WHERE THE FIVE SHOTS LIVE, WHICH IS A REVERSAL WORTH KNOWING ────────────
 *
 * Not in `products`.images. They were, for one round: a backfill migration in
 * the shape of 2027_06_15_000000's detail-tab backfill, five URLs per demo row.
 * It worked, and it took the full suite from 3 failures — all three already red
 * on the merge tip — to 26, across seven files that have nothing to do with
 * galleries: the importer's re-pointer, MediaAudit, MediaUsageWriter::rebuild()
 * and the image-variants backlog all walk that column. The whole argument is in
 * App\Support\DemoProductShots' header. The pictures are FILES now, drawn once
 * by a migration, and gallery() tops up a demo product that has none of its
 * own.
 *
 * ── THE FALSE GREENS THIS FILE REFUSES ──────────────────────────────────────
 *
 * Two of them, and they are the obvious assertions to write:
 *
 *   "the strip is there"   is satisfied by a strip of five EMPTY squares. The
 *                          blade renders a `.gthumb` div for a shot with no
 *                          image and puts the label text in it, so counting
 *                          divs proves nothing about pictures. Every case below
 *                          therefore reads `data-image`, counts `<img
 *                          class="gthumb-img">` and opens the files on disk.
 *
 *   "clicking works"       is satisfied by markup that exists and does nothing.
 *                          pdp.js swaps the main frame by assigning the thumb's
 *                          `data-image` to the `<img>`'s src, so the assertion
 *                          that means something is that each thumb's
 *                          `data-image` is a DIFFERENT real URL from the one
 *                          the main frame is rendered with. A strip of five
 *                          identical sources would click perfectly and change
 *                          nothing visible. The browser half of this — a real
 *                          click, a real src change — is measured in
 *                          tools/gal-shots.cjs and recorded in
 *                          docs/lane-gal-shots/.
 */

/** A row wearing all three of the seeder's demo marks. */
function galDemoRow(string $name, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::random(6),
        'wc_id' => null,
        'name' => $name,
        'sku' => DemoProductDetails::SKU_PREFIX.Str::upper(Str::random(4)),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => DemoProductDetails::SEEDED_SHORT_DESCRIPTION,
    ], $overrides));
}

/** Run the drawing migration's up() against the current database. */
function galDraw(): void
{
    $migration = require database_path('migrations/2027_06_20_000000_draw_demo_gallery_shots.php');
    $migration->up();
}

/* ══════════════════════════════════════════════════════════════════════════
   THE ONE THAT MATTERS WHEN THE WORDPRESS IMPORT LANDS
   ══════════════════════════════════════════════════════════════════════════ */

it('leaves a real product alone however much it looks like a demo one', function () {
    /*
     * Three marks are required AT ONCE — wc_id IS NULL, sku LIKE 'DEMO-%' and
     * the seeder's own short_description — and a row that breaks any one of
     * them is untouched even when it breaks only that one. An imported product
     * carries a real `wc_id`, which is the column the Migrator upserts on, so
     * it fails the first mark before the other two are asked.
     *
     * MUTATION NOTE. Drop `->whereNull('wc_id')` from the migration AND the
     * first clause of DemoProductShots::isDemo() and the imported row below
     * gains five thumbnails and this goes red; do the same to the `sku` clause
     * for the hand-made row, and to the short_description clause for the
     * re-described one. RUN: all three, separately.
     */
    $cases = [
        // Imported: no gallery, demo-shaped SKU, demo-shaped blurb — and a
        // wc_id, which is the mark that settles it.
        'imported' => galDemoRow('Gal Imported Toner', ['wc_id' => 907011]),
        // Typed into the admin: no wc_id, no DEMO- SKU.
        'hand-made' => galDemoRow('Gal Hand Made Toner', ['sku' => 'KBB-GAL-1']),
        // Seeded long ago and since given a real blurb.
        'real blurb' => galDemoRow('Gal Re-described Toner', [
            'short_description' => 'A real blurb, written by the owner.',
        ]),
    ];

    $before = [];

    foreach ($cases as $label => $row) {
        $before[$label] = DB::table('products')->where('id', $row->id)
            ->first(['image', 'images', 'updated_at']);
    }

    galDraw();

    foreach ($cases as $label => $row) {
        expect(DemoProductShots::isDemo($row->fresh()))->toBeFalse("the {$label} product reads as a demo one");

        // NOT ONE BYTE WAS DRAWN FOR THEM, and no library row either. The marks
        // are checked in the migration's query, before DemoProductShots is
        // reached at all.
        foreach (array_keys(DemoProductShots::LABELS) as $i) {
            $path = DemoProductShots::pathFor($row->slug, $i);
            expect(is_file(public_path($path)))->toBeFalse("a file was drawn for the {$label} product");
            expect(Media::query()->where('path', $path)->exists())->toBeFalse();
        }

        // AND NO COLUMN MOVED — which this migration promises of every row,
        // demo or not, and the case below proves for a demo one.
        $after = DB::table('products')->where('id', $row->id)
            ->first(['image', 'images', 'updated_at']);

        expect((array) $after)->toBe((array) $before[$label], "the {$label} product was written to");

        // Not a false green: these rows really do still have an empty gallery.
        expect($row->fresh()->images ?? [])->toBe([]);

        // And the page they render carries no strip, which is the state the
        // owner reported and the state a real product must stay in.
        $html = test()->get('/product/'.$row->slug)->assertOk()->getContent();
        expect($html)->not->toContain('id="gthumbs"');
    }
});

it('writes not one catalogue column, which is why it is a file and not a backfill', function () {
    /*
     * THE REVERSAL, PINNED. `products`.images is walked by MediaAudit, by
     * MediaUsageWriter::rebuild(), by the image-variants backlog and by the
     * importer's re-pointer; filling it with 120 placeholder URLs took 23 test
     * cases red across seven files and would have put those pictures into the
     * owner's media-library counts, his Image sizes backlog and his image
     * sitemap. This case is what stops that being quietly reintroduced.
     *
     * MUTATION NOTE. Have the migration also write
     * `['images' => json_encode($urls)]` and this goes red on the first
     * expectation — and so do those seven files. RUN.
     */
    $product = galDemoRow('Gal Column Untouched Toner');

    $before = DB::table('products')->where('id', $product->id)->first();

    galDraw();

    expect((array) DB::table('products')->where('id', $product->id)->first())->toBe((array) $before);

    // Not vacuous: the shots really were drawn, and the page really does show
    // them — so "no column moved" is a statement about the migration rather
    // than about a migration that did nothing.
    expect(DemoProductShots::urlsFor($product->slug))->toHaveCount(5);
    expect($product->fresh()->images ?? [])->toBe([]);
    expect($product->fresh()->image)->toBeNull();

    // And the index over that column is empty too, because the column is.
    expect(DB::table('media_usages')->where('owner_type', 'product')
        ->where('owner_id', $product->id)->count())->toBe(0);
});

/* ══════════════════════════════════════════════════════════════════════════
   WHAT THE PAGE DRAWS
   ══════════════════════════════════════════════════════════════════════════ */

it('draws a five-thumbnail strip with a real picture behind every square', function () {
    /*
     * MUTATION NOTE. Delete the `if ($images === [] && …isDemo($product))`
     * block from Store\ProductController::gallery() and this goes red on the
     * very first assertion: the `.gthumbs` element is absent, which is exactly
     * what the owner was looking at. RUN.
     */
    $product = galDemoRow('Heartleaf Gallery Toner');

    galDraw();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    // THE STRIP ITSELF, once. Two would be two sets of click targets.
    expect(substr_count($html, 'id="gthumbs"'))->toBe(1);

    // FIVE SQUARES, and five PICTURES in them — a `.gthumb` with no image
    // renders the label text instead and would pass the first count alone.
    // `gthumb(?: on)?"` and not `gthumb[^"]*"`, which also matches the strip's
    // own `class="gthumbs"` and answers 6.
    expect(preg_match_all('/<div class="gthumb(?: on)?"/', $html))->toBe(5);
    expect(preg_match_all('/<img class="gthumb-img" src="([^"]+)"/', $html, $thumbs))->toBe(5);

    foreach ($thumbs[1] as $src) {
        expect($src)->toStartWith('/'.DemoProductShots::DIRECTORY.'/');
        expect(is_file(public_path(ltrim($src, '/'))))->toBeTrue("{$src} has no file behind it");
    }

    // FIVE DIFFERENT PICTURES. Five copies of one shot would click perfectly
    // and change nothing a person can see.
    expect(array_unique($thumbs[1]))->toHaveCount(5);

    // EXACTLY ONE ACTIVE, and it is the first.
    expect(substr_count($html, 'class="gthumb on"'))->toBe(1);
    expect(preg_match('/<div class="gthumb on"\s+data-i="0"/', $html))->toBe(1);

    // AND THE MAIN FRAME IS SHOWING THE FIRST SHOT, so the strip and the
    // photograph agree before anything is clicked.
    expect(preg_match('/<img class="gmain-img" id="gmainImg"\s+src="([^"]+)"/', $html, $main))->toBe(1);
    expect($main[1])->toBe($thumbs[1][0]);
});

it('gives every thumbnail a data-image the script can actually swap in', function () {
    /*
     * pdp.js initGallery() reads `data-image` off the clicked `.gthumb` and
     * assigns it to the main `<img>`'s src. A strip whose thumbs carry an empty
     * data-image renders, highlights on click and never changes the picture —
     * "clicking works" satisfied by markup that does nothing.
     *
     * MUTATION NOTE. Make DemoProductShots::urlsFor() return five copies of its
     * first URL and the distinctness assertion goes red. RUN.
     */
    $product = galDemoRow('Ceramide Gallery Moisturiser');

    galDraw();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect(preg_match_all('/<div class="gthumb(?: on)?"\s+data-i="(\d+)"\s+data-image="([^"]*)"/', $html, $m))->toBe(5);

    foreach ($m[2] as $i => $image) {
        expect(trim($image))->not->toBe('', "thumb {$i} carries no data-image");
    }

    expect(array_unique($m[2]))->toHaveCount(5);

    // Four of the five are a DIFFERENT picture from the one already on screen,
    // so four of the five clicks visibly move something.
    $showing = $m[2][0];
    expect(array_values(array_filter($m[2], static fn (string $u): bool => $u !== $showing)))->toHaveCount(4);
});

it('captions the five shots Front to Box and never Video', function () {
    /*
     * gallery() labels BY POSITION out of ['Front','Texture','Ingredients','On
     * skin','Box','Video'], and it reserves the sixth for the frame its own
     * demo padding puts a play badge on. Five stills, five labels, and the
     * badge class `vid` on none of them.
     *
     * MUTATION NOTE. Add a sixth entry to DemoProductShots::LABELS and this
     * goes red: the sixth thumb is captioned "Video". RUN.
     */
    $product = galDemoRow('Gal Labelled Serum');

    galDraw();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    preg_match_all('/data-label="([^"]*)"/', $html, $labels);

    expect($labels[1])->toBe(DemoProductShots::LABELS);
    expect($html)->not->toContain('gthumb vid');
    expect($html)->not->toContain('data-label="Video"');
});

it('stands aside the moment a demo product has a photograph of its own', function () {
    /*
     * The top-up fires only when the row has NO shots at all, so it can never
     * push a real photograph down the strip or displace one. A demo product the
     * owner has given a main image shows that image and nothing else — which is
     * also the honest answer, because at that point the page is showing him his
     * own picture.
     *
     * MUTATION NOTE. Drop the `$images === []` half of the condition in
     * gallery() and this goes red with six thumbs, the last one captioned
     * "Video". RUN.
     */
    $withCard = galDemoRow('Gal Pictured Cleanser', ['image' => '/uploads/products/gal-main.jpg']);
    $withGallery = galDemoRow('Gal Gallery Cleanser', [
        'images' => ['/wp-content/uploads/2021/03/real-one.jpg', '/wp-content/uploads/2021/03/real-two.jpg'],
    ]);

    galDraw();

    $one = $this->get('/product/'.$withCard->slug)->assertOk()->getContent();
    expect($one)->not->toContain('id="gthumbs"');
    expect($one)->toContain('/uploads/products/gal-main.jpg');
    expect($one)->not->toContain(DemoProductShots::DIRECTORY);

    $two = $this->get('/product/'.$withGallery->slug)->assertOk()->getContent();
    preg_match_all('/data-image="([^"]*)"/', $two, $m);
    expect($m[1])->toBe([
        '/wp-content/uploads/2021/03/real-one.jpg',
        '/wp-content/uploads/2021/03/real-two.jpg',
    ]);
    expect($two)->not->toContain(DemoProductShots::DIRECTORY);
});

it('gives every demo product a gallery through the SEEDER too', function () {
    /*
     * THE OTHER READER, ISOLATED — and isolating it is the whole of this case.
     *
     * A first attempt asserted that the 24 rows the test database was built
     * with have their five files. It passed with `$this->shots($name)` DELETED
     * from DemoCatalogueSeeder, and it was right to: on a fresh install the
     * seed migration and the drawing migration both run inside the same
     * `migrate`, so the migration draws whatever the seeder did not and the
     * seeder half cannot be seen. An assertion that cannot fail for the thing
     * it names is worse than no assertion.
     *
     * So the files are removed, the seeder is run BY ITSELF, and the migration
     * is never called. What is left is the seeder's work and nothing else —
     * which is also the exact shape of `php artisan db:seed --class=
     * Database\Seeders\DemoCatalogueSeeder` on a fully-migrated shop, the case
     * the seeder half exists for.
     *
     * MUTATION NOTE. Remove `$this->shots($name);` from DemoCatalogueSeeder and
     * this goes red with 24 empty galleries. RUN.
     */
    $seeded = Product::query()
        ->whereNull('wc_id')
        ->where('sku', 'like', DemoProductDetails::SKU_PREFIX.'%')
        ->where('short_description', DemoProductDetails::SEEDED_SHORT_DESCRIPTION)
        ->get(['id', 'name', 'slug']);

    expect($seeded)->toHaveCount(24);

    // Wipe the evidence the migration left, so what follows is the seeder's.
    foreach ($seeded as $row) {
        foreach (array_keys(DemoProductShots::LABELS) as $i) {
            @unlink(public_path(DemoProductShots::pathFor($row->slug, $i)));
        }
    }

    expect(DemoProductShots::urlsFor($seeded->first()->slug))->toBe([]);

    (new \Database\Seeders\DemoCatalogueSeeder)->run();

    $thin = [];

    foreach ($seeded as $row) {
        $urls = DemoProductShots::urlsFor($row->slug);

        if (count($urls) !== 5) {
            $thin[] = $row->slug.' has '.count($urls);
        }
    }

    expect($thin)->toBe([], implode("\n  ", $thin));
});

/* ══════════════════════════════════════════════════════════════════════════
   THE MIGRATION'S OWN PROMISES
   ══════════════════════════════════════════════════════════════════════════ */

it('draws nothing the second time it runs', function () {
    $product = galDemoRow('Gal Idempotent Essence');

    galDraw();

    $stamps = [];

    foreach (DemoProductShots::urlsFor($product->slug) as $url) {
        $absolute = public_path(ltrim($url, '/'));
        $stamps[$url] = [filemtime($absolute), md5_file($absolute)];
    }

    expect($stamps)->toHaveCount(5);

    galDraw();

    foreach ($stamps as $url => $was) {
        $absolute = public_path(ltrim($url, '/'));
        expect([filemtime($absolute), md5_file($absolute)])->toBe($was, "{$url} was redrawn");
    }

    // And the library gained one row per file, not two. MediaRegistrar is
    // idempotent by path and this is the case that proves it on this path.
    foreach (array_keys($stamps) as $url) {
        expect(Media::query()->where('path', ltrim($url, '/'))->count())->toBe(1);
    }
});

/* ══════════════════════════════════════════════════════════════════════════
   THE PICTURES THEMSELVES
   ══════════════════════════════════════════════════════════════════════════ */

it('draws five different PNGs and catalogues each one in the library', function () {
    $product = galDemoRow('Gal Bytes Ampoule');

    galDraw();

    $urls = DemoProductShots::urlsFor($product->slug);
    expect($urls)->toHaveCount(5);

    $bytes = [];
    $total = 0;

    foreach ($urls as $url) {
        $path = ltrim($url, '/');
        $absolute = public_path($path);

        expect(is_file($absolute))->toBeTrue("{$url} has no file behind it");

        $size = getimagesize($absolute);
        expect($size)->toBeArray();
        expect($size[2])->toBe(IMAGETYPE_PNG);
        expect($size[0])->toBe($size[1]);

        // A real Media row, exactly as an upload leaves — this is the owner's
        // "whenever we upload any media, it should go to Media also", and it is
        // also the UNDO: deleting the row and its file there makes the shot
        // stop appearing, which the case below proves.
        $media = Media::query()->where('path', $path)->first();
        expect($media)->not->toBeNull("{$url} was not catalogued");
        expect($media->mime)->toBe('image/png');
        expect($media->width)->toBe($size[0]);

        $bytes[] = md5_file($absolute);
        $total += (int) filesize($absolute);
    }

    // FIVE DIFFERENT PICTURES, not one drawn five times. This is the assertion
    // that a strip of identical squares cannot pass.
    expect(array_unique($bytes))->toHaveCount(5);

    // And they stay small enough to be worth keeping on his disk. Measured at
    // 23,454 bytes per product when this was written; the ceiling is generous
    // so that a tweak to the artwork does not go red for being a kilobyte
    // wider, and tight enough that a photograph-sized file could never pass.
    expect($total)->toBeLessThan(120 * 1024);
});

it('lets the owner take a shot back out again', function () {
    /*
     * THE UNDO, AND IT HAS TO EXIST. CLAUDE.md: a change with no way to undo it
     * is worse than no change. These five files are Media Library rows like any
     * upload, nothing in `media_usages` points at them — because no catalogue
     * column holds them — so the library's "still in use" guard does not stand
     * in the way, and deleting the file there makes the shot stop appearing.
     *
     * Deleting the SECOND one leaves the first, rather than shuffling every
     * caption up by one: urlsFor() stops at the first file that is not there,
     * for the reason its own comment gives.
     *
     * MUTATION NOTE. Change that `break` in urlsFor() to `continue` and this
     * goes red — four thumbs come back and the ingredients flat-lay is
     * captioned "Texture". RUN.
     */
    $product = galDemoRow('Gal Undo Toner');

    galDraw();

    expect(DemoProductShots::urlsFor($product->slug))->toHaveCount(5);

    // As the Media Library's delete does: the file goes, and the row with it.
    $gone = DemoProductShots::pathFor($product->slug, 1);
    unlink(public_path($gone));

    expect(DemoProductShots::urlsFor($product->slug))->toHaveCount(1);

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    // One shot is not a strip — which is the page he had, and the page he gets
    // back if he clears these out.
    expect($html)->not->toContain('id="gthumbs"');
    preg_match_all('/data-label="([^"]*)"/', $html, $labels);
    expect($labels[1])->toBe([]);
});

it('gives two products different pictures and one product a coherent set', function () {
    /*
     * The strip has to READ as one product's five shots and as a different
     * product's five shots, or it is decoration. Each set shares an accent
     * seeded off the name, the way Gradient::for() seeds the placeholder these
     * products showed before.
     *
     * MUTATION NOTE. Make DemoProductShots::accent() return a constant and this
     * goes red. RUN.
     */
    $one = galDemoRow('Gal Palette Alpha Serum');
    $two = galDemoRow('Gal Palette Beta Serum');

    galDraw();

    $first = array_map(static fn (string $u): string => md5_file(public_path(ltrim($u, '/'))),
        DemoProductShots::urlsFor($one->slug));
    $second = array_map(static fn (string $u): string => md5_file(public_path(ltrim($u, '/'))),
        DemoProductShots::urlsFor($two->slug));

    expect($first)->toHaveCount(5);
    expect(array_intersect($first, $second))->toBe([]);
});

it('stops at the first shot it cannot draw rather than leaving a hole', function () {
    /*
     * gallery() labels BY POSITION. A list with a hole in it does not lose one
     * caption, it MOVES every caption after the hole: drop Texture and the
     * ingredients flat-lay is captioned "Texture", the skin swatch
     * "Ingredients" and the carton "On skin". Four correct captions beats four
     * wrong ones, so ensureFor() answers a contiguous run and stops.
     *
     * STAGED BY PUTTING A DIRECTORY WHERE THE THIRD FILE GOES — imagepng()
     * cannot write over a directory, for root as much as for anyone, so this
     * case is demonstrable on this container where a chmod one is not.
     *
     * MUTATION NOTE. Change either `break` in ensureFor()'s loop to `continue`
     * and this goes red: four URLs come back instead of two, and the page
     * captions the ingredients shot "Texture". RUN.
     */
    $product = galDemoRow('Gal Holed Toner', ['slug' => 'gal-holed-toner']);

    $blocked = public_path(DemoProductShots::pathFor('gal-holed-toner', 2));
    @mkdir(dirname($blocked), 0o775, true);
    mkdir($blocked, 0o775, true);

    try {
        galDraw();

        expect(DemoProductShots::ensureFor('gal-holed-toner', 'Gal Holed Toner'))->toHaveCount(2);

        $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

        preg_match_all('/data-label="([^"]*)"/', $html, $labels);
        expect($labels[1])->toBe(['Front', 'Texture']);
    } finally {
        @rmdir($blocked);
    }
});

it('says nothing at all when there is nowhere to draw', function () {
    /*
     * A page offering URLs with no files behind them is five broken thumbnails
     * where there are currently none, so ensureFor() returns only the shots
     * whose bytes really landed and urlsFor() offers only the files that are
     * really there.
     *
     * THE FAILURE IS STAGED BY PUTTING A FILE WHERE THE DIRECTORY GOES, and not
     * by chmod. This suite runs as root on this container, and root writes
     * straight through a 0555 directory — the permissions version of this case
     * SKIPPED here and would have proved nothing. mkdir() over an existing
     * regular file fails for everybody.
     *
     * MUTATION NOTE. Make ensureFor() return the paths regardless of whether
     * the file landed and this goes red with five URLs. RUN.
     */
    $product = galDemoRow('Gal Unwritable Cream');

    $root = sys_get_temp_dir().'/kbb-gal-'.bin2hex(random_bytes(6));
    mkdir($root.'/'.dirname(DemoProductShots::DIRECTORY), 0o775, true);
    // The blocker: a FILE at the path the shots directory needs.
    file_put_contents($root.'/'.DemoProductShots::DIRECTORY, 'not a directory');

    $was = $this->app->publicPath();

    try {
        $this->app->usePublicPath($root);

        expect(DemoProductShots::ensureFor('gal-nowhere', 'Gal Nowhere'))->toBe([]);

        galDraw();
    } finally {
        $this->app->usePublicPath($was);
        @unlink($root.'/'.DemoProductShots::DIRECTORY);
        @rmdir($root.'/'.dirname(DemoProductShots::DIRECTORY));
        @rmdir($root);
    }

    // Asserted with the real web root back — the page is not rendered inside
    // the temp one, which has no Vite manifest and would 500 for a reason that
    // has nothing to do with this lane.
    expect(DemoProductShots::urlsFor($product->slug))->toBe([]);

    // And the page is the page he had: no strip, and no broken squares.
    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();
    expect($html)->not->toContain('id="gthumbs"');
});
