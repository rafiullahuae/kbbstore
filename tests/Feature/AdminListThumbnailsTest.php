<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CatalogProductsApiController;
use App\Http\Controllers\Admin\ImageSizesApiController;
use App\Http\Controllers\Admin\MediaLibraryApiController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Media;
use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Http\Request;

/**
 * Lane IM2 — the admin's own grids stop pulling full-resolution photographs.
 *
 * ── WHAT WAS WRONG ─────────────────────────────────────────────────────────
 *
 * Every list screen in the console built `<img src=…>` in JavaScript out of the
 * URL of the FILE: the media library grid, the media picker, the product
 * picker, the product editor's list and its gallery strip. On this catalogue's
 * sizes that is ~290KB per photograph, into boxes measuring 36px, 38px, 56px
 * and 150px. It is behind a login and there is one user, so these are not a
 * shopper's bytes — they are the owner's, every time he opens the screen he is
 * in most.
 *
 * MEASURED IN CHROMIUM on the preview (docs/lane-im2-shots), Content → Media
 * Library at 36 items: before, 36 image requests for 10.2MB; after, 36 requests
 * for 0.9MB. The grid paints the same picture.
 *
 * ── THE MUTATION NOTES, ALL RUN IN THIS WORKTREE ON 29 SEPTEMBER 2026 ──────
 *
 * Take `item.thumb ||` back out of media-library-screen.blade.php and "it draws
 * the media library grid from a copy, not the original" goes red with "the
 * media library tile is still pointing at the original".
 *
 * Delete the `'thumb' =>` line from MediaLibraryApiController::tile() and the
 * same test goes red with "the media tile payload carries no thumb at all".
 *
 * Delete the `'thumb' =>` line from CatalogProductsApiController's row and "it
 * hands a product list row a copy sized for its 36px square" goes red the same
 * way.
 */

/** A real JPEG of a given pixel size, in this process's own public root. */
function ad2Photo(string $relative, int $width, int $height): string
{
    $path = public_path($relative);
    @mkdir(\dirname($path), 0755, true);

    $im = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        imagefilledrectangle($im, 0, $y, $width, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }

    imagejpeg($im, $path, 90);
    imagedestroy($im);

    return $path;
}

function ad2Sweep(string $relative): void
{
    $root = public_path($relative);

    if (! is_dir($root) || is_link($root)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($root);
}

function ad2RunBatch(): void
{
    $runner = new ImageSizesApiController();
    $cursor = '';

    for ($turn = 1; $turn <= 200; $turn++) {
        $payload = json_decode(
            (string) $runner->run(Request::create('/', 'GET', ['after' => $cursor]))->getContent(),
            true
        );

        expect($payload['ok'] ?? false)->toBeTrue('the batch refused to run');
        $cursor = (string) $payload['cursor'];

        if ($payload['done'] ?? false) {
            return;
        }
    }

    throw new RuntimeException('the batch never reported done');
}

beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    ad2Sweep(ImageVariants::DIR);
    ad2Sweep('uploads/ad2');
});

afterEach(function () {
    ad2Sweep(ImageVariants::DIR);
    ad2Sweep('uploads/ad2');
});


beforeEach(function () {
    if (! ImageVariants::available()) {
        test()->markTestSkipped('this PHP has no GD, which is the thing under test');
    }

    ad2Sweep(ImageVariants::DIR);
    ad2Sweep('uploads/ad2');
});

afterEach(function () {
    ad2Sweep(ImageVariants::DIR);
    ad2Sweep('uploads/ad2');
});

/* ────────────────────────────────── the admin stops pulling originals ──── */

it('draws the media library grid from a copy, not the original', function () {
    /*
     * MUTATION: delete the `'thumb' =>` line from tile() and this is red with
     * "the media tile payload carries no thumb at all". Take `item.thumb ||`
     * back out of the screen and it is red with "the media library tile is
     * still pointing at the original".
     */
    ad2Photo('uploads/ad2/lib.jpg', 1000, 1000);

    Media::create([
        'filename' => 'lib.jpg', 'original_name' => 'lib.jpg',
        'path' => 'uploads/ad2/lib.jpg', 'mime' => 'image/jpeg',
        'size' => filesize(public_path('uploads/ad2/lib.jpg')), 'width' => 1000, 'height' => 1000,
    ]);

    /*
     * AND THE SAME FILE ON A PRODUCT, which is what puts it in the batch's work
     * list at all.
     *
     * KNOWN GAP, STATED HERE RATHER THAN LEFT TO BE DISCOVERED: the work list
     * walks products, product variants and reviews — the places a photograph is
     * REFERENCED — and not `media.path`. A library row for a file nothing uses
     * yet therefore has no copy, and its tile goes on drawing the original.
     * That is the documented fallback rather than a break, and it is deliberate
     * for one reason: media rows spell a file as an absolute URL through
     * Media::urlFor() while products spell it `/uploads/…`, so putting both in
     * one list would count the same photograph twice in the screen's own
     * backlog. Canonicalising those two spellings is a change to Url::media()
     * and the `site_url` setting, which is its own piece of work.
     */
    $brand = Brand::firstOrCreate(['slug' => 'ad2-anua'], ['name' => 'Anua']);
    Product::create([
        'slug' => 'ad2-lib', 'name' => 'Ampoule', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 4400, 'stock_status' => 'instock',
        'image' => '/uploads/ad2/lib.jpg', 'images' => [],
    ]);

    // With no copies on disk the thumb IS the original, which is the promise
    // that lets this ship to a shop that has never run the batch.
    $cold = json_decode(
        (string) (new MediaLibraryApiController())->index(Request::create('/', 'GET'))->getContent(),
        true
    );

    expect(array_key_exists('thumb', $cold['items'][0]))
        ->toBeTrue('the media tile payload carries no thumb at all')
        ->and($cold['items'][0]['thumb'])->toBe($cold['items'][0]['url'],
            'with no copy on disk the thumb is not the original, so an unsized library would draw nothing');

    ad2RunBatch();

    $warm = json_decode(
        (string) (new MediaLibraryApiController())->index(Request::create('/', 'GET'))->getContent(),
        true
    );

    expect(str_contains((string) $warm['items'][0]['thumb'], '/'.ImageVariants::DIR.'/400/uploads/ad2/lib.jpg'))
        ->toBeTrue('the media tile payload is still pointing at the original: '.$warm['items'][0]['thumb'])
        ->and($warm['items'][0]['url'])->toBe($cold['items'][0]['url'],
            'the url field moved — the detail panel and the copy-address control both mean the FILE');

    // And the screen reads it. Zero here is the "built, never wired up" shape.
    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/media-library-screen.blade.php'));

    expect(substr_count($screen, 'item.thumb || item.url'))
        ->toBe(1, 'the media library tile is still pointing at the original');

    $picker = (string) file_get_contents(base_path('resources/views/admin/partials/media-picker.blade.php'));

    expect(substr_count($picker, 'it.thumb || it.url'))
        ->toBe(1, 'the media picker tile is still pointing at the original');
});

it('hands a product list row a copy sized for its 36px square', function () {
    ad2Photo('uploads/ad2/prod.jpg', 1000, 1000);

    $brand = Brand::firstOrCreate(['slug' => 'ad2-anua'], ['name' => 'Anua']);
    Product::create([
        'slug' => 'ad2-row', 'name' => 'Toner', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 5500, 'stock_status' => 'instock',
        'image' => '/uploads/ad2/prod.jpg', 'images' => [],
    ]);

    $this->actingAs(AdminUser::create([
        'name' => 'Owner', 'email' => 'ad2@preview.test',
        'password' => 'ad2-secret-11', 'role' => 'owner',
    ]), 'admin');

    ad2RunBatch();

    $rows = json_decode(
        (string) (new CatalogProductsApiController())->index(Request::create('/', 'GET'))->getContent(),
        true
    );

    $row = collect($rows['products'] ?? [])->firstWhere('image', '/uploads/ad2/prod.jpg');

    expect($row)->not->toBeNull('the product is not in the list at all');
    expect(array_key_exists('thumb', $row))->toBeTrue('the product list row carries no thumb at all');

    // 200 and not 400: .kpp-th is 36px and .peo-item img is 38px, and this
    // endpoint hands back up to 500 rows a page.
    expect(str_contains((string) $row['thumb'], '/'.ImageVariants::DIR.'/200/uploads/ad2/prod.jpg'))
        ->toBeTrue('the list row is still pointing at the original: '.$row['thumb'])
        ->and($row['image'])->toBe('/uploads/ad2/prod.jpg',
            'the image field moved — everything that needs the real file reads it');

    $picker = (string) file_get_contents(base_path('resources/views/admin/partials/product-picker.blade.php'));

    expect(substr_count($picker, 'p.thumb ? String(p.thumb) : image'))
        ->toBe(1, 'the product picker tile is still pointing at the original');
});

it('keeps the product editor thumbnails keyed by the photograph they belong to', function () {
    /*
     * The editor's gallery is DRAGGED to reorder and tiles are removed one at a
     * time, so a parallel array would have to be re-indexed by every handler
     * and the first one that was not would slide one photograph's thumbnail
     * onto another. A map keyed by URL cannot go out of order.
     */
    ad2Photo('uploads/ad2/main.jpg', 1000, 1000);
    ad2Photo('uploads/ad2/shot.jpg', 1000, 1400);

    $brand = Brand::firstOrCreate(['slug' => 'ad2-anua'], ['name' => 'Anua']);
    $product = Product::create([
        'slug' => 'ad2-editor', 'name' => 'Serum', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 7700, 'stock_status' => 'instock',
        'image' => '/uploads/ad2/main.jpg', 'images' => ['/uploads/ad2/shot.jpg'],
    ]);

    $this->actingAs(AdminUser::create([
        'name' => 'Owner', 'email' => 'ad2b@preview.test',
        'password' => 'ad2-secret-11', 'role' => 'owner',
    ]), 'admin');

    // Before the batch the map is EMPTY rather than a map of each URL to
    // itself — bytes on the wire that tell the screen nothing it had not got.
    $cold = $this->getJson('/admin-api/product-editor-load/'.$product->id)->json();

    expect($cold['product']['thumbs'] ?? null)->toBe([], 'an unsized catalogue sends a map of URLs to themselves');

    ad2RunBatch();

    $warm = $this->getJson('/admin-api/product-editor-load/'.$product->id)->json();
    $thumbs = $warm['product']['thumbs'] ?? [];

    expect(array_key_exists('/uploads/ad2/main.jpg', $thumbs))->toBeTrue('the main image has no thumbnail')
        ->and(array_key_exists('/uploads/ad2/shot.jpg', $thumbs))->toBeTrue('the gallery shot has no thumbnail');

    // The main-image card is ~300px wide and a gallery tile is 56px, so the
    // two widths are different on purpose.
    expect(str_contains((string) $thumbs['/uploads/ad2/main.jpg'], '/'.ImageVariants::DIR.'/400/'))
        ->toBeTrue('the main-image card is not drawing the 400px copy: '.$thumbs['/uploads/ad2/main.jpg'])
        ->and(str_contains((string) $thumbs['/uploads/ad2/shot.jpg'], '/'.ImageVariants::DIR.'/200/'))
        ->toBeTrue('the 56px gallery tile is not drawing the 200px copy: '.$thumbs['/uploads/ad2/shot.jpg']);

    // Writing is unaffected: the save payload still carries the originals.
    expect($warm['product']['image'])->toBe('/uploads/ad2/main.jpg')
        ->and($warm['product']['images'])->toBe(['/uploads/ad2/shot.jpg']);

    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/product-editor-screen.blade.php'));

    expect(substr_count($screen, 'url(shownAt(u))'))
        ->toBe(1, 'the editor gallery tile is still pointing at the original')
        ->and(substr_count($screen, 'url(shownAt(model.image))'))
        ->toBe(1, 'the editor main-image card is still pointing at the original');
});

it('gives the manual-order product search the same copy the picker already reads', function () {
    /*
     * THE HALF-FIX THIS CATCHES. resources/views/admin/partials/product-picker
     * .blade.php is ONE component used by two endpoints — /admin-api/catalog
     * /products and /admin-api/manual-orders/products. Teaching the tile to
     * read `thumb` and giving only one of the two endpoints the field would
     * leave the same picker drawing a 4.8KB copy on one screen and a 290KB
     * original on another, which reads like a caching bug and is not one.
     *
     * MUTATION: delete the 'thumb' line from AdminOrderController::products()
     * and this is red with "the manual-order picker is still pointing at the
     * original".
     */
    ad2Photo('uploads/ad2/mo.jpg', 1000, 1000);

    $brand = Brand::firstOrCreate(['slug' => 'ad2-anua'], ['name' => 'Anua']);
    Product::create([
        'slug' => 'ad2-manual', 'name' => 'Sleeping Mask', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 6600, 'stock_status' => 'instock',
        'image' => '/uploads/ad2/mo.jpg', 'images' => [],
    ]);

    $this->actingAs(AdminUser::create([
        'name' => 'Owner', 'email' => 'ad2c@preview.test',
        'password' => 'ad2-secret-11', 'role' => 'owner',
    ]), 'admin');

    ad2RunBatch();

    $rows = $this->getJson('/admin-api/manual-orders/products?q=Sleeping')->json();
    $row = collect($rows['products'] ?? [])->firstWhere('image', '/uploads/ad2/mo.jpg');

    expect($row)->not->toBeNull('the product is not in the manual-order search at all');
    expect(str_contains((string) ($row['thumb'] ?? ''), '/'.ImageVariants::DIR.'/200/uploads/ad2/mo.jpg'))
        ->toBeTrue('the manual-order picker is still pointing at the original: '.($row['thumb'] ?? '(absent)'))
        ->and($row['image'])->toBe('/uploads/ad2/mo.jpg', 'the image field moved');
});

it('gives the brands list a copy of each logo', function () {
    /*
     * This endpoint returns EVERY brand in the shop — ninety-three on the live
     * catalogue — and `.bz-logo` is a 34px square.
     *
     * MUTATION: delete the setAttribute('logo_thumb', …) from
     * BrandsApiController::index() and this is red with "the brands row carries
     * no logo_thumb"; take `b.logo_thumb ||` out of the screen and the
     * substr_count assertion goes red.
     */
    ad2Photo('uploads/ad2/logo.jpg', 1000, 1000);

    $brand = Brand::firstOrCreate(['slug' => 'ad2-logoed'], ['name' => 'Logoed']);
    $brand->update(['logo' => '/uploads/ad2/logo.jpg']);

    Product::create([
        'slug' => 'ad2-logo-holder', 'name' => 'Holder', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 1100, 'stock_status' => 'instock',
        'image' => '/uploads/ad2/logo.jpg', 'images' => [],
    ]);

    $this->actingAs(AdminUser::create([
        'name' => 'Owner', 'email' => 'ad2d@preview.test',
        'password' => 'ad2-secret-11', 'role' => 'owner',
    ]), 'admin');

    // Before the batch the copy IS the original — the promise every one of
    // these fields makes, and the one that lets them ship.
    $cold = collect($this->getJson('/admin-api/brands')->json('brands'))->firstWhere('slug', 'ad2-logoed');

    expect($cold)->not->toBeNull('the brand is not in the list at all');
    expect(array_key_exists('logo_thumb', $cold))->toBeTrue('the brands row carries no logo_thumb')
        ->and($cold['logo_thumb'])->toBe('/uploads/ad2/logo.jpg', 'an unsized shop is not getting its original back');

    ad2RunBatch();

    $warm = collect($this->getJson('/admin-api/brands')->json('brands'))->firstWhere('slug', 'ad2-logoed');

    expect($warm['logo_thumb'])->toBe('/'.ImageVariants::DIR.'/200/uploads/ad2/logo.jpg',
        'the brands row is still pointing at the original: '.$warm['logo_thumb'])
        ->and($warm['logo'])->toBe('/uploads/ad2/logo.jpg', 'the logo field moved');

    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/brands-editor-screen.blade.php'));

    expect(substr_count($screen, 'b.logo_thumb || b.logo'))
        ->toBe(1, 'the brands row is still drawing the original');
});
