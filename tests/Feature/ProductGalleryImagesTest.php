<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ProductTitle;
use Illuminate\Support\Facades\DB;

/**
 * The product gallery emits real <img> elements, and the page title names the
 * brand once.
 *
 * EVERY ASSERTION HERE PARSES THE SERVED DOCUMENT. That is the whole point of
 * the file. The gallery used to paint the main shot and every thumbnail as a
 * CSS `background:` on a <div>, and a test that checked the $gallery array, or
 * a Blade variable, or even that the URL appears somewhere in the response,
 * would have passed happily against a page that contained not one <img> — which
 * is exactly the state this replaces. What matters is the element a crawler and
 * a preload scanner actually see, so DOMDocument reads the response body and
 * the assertions are made against nodes and attributes.
 *
 * Three things were wrong and each has a guard below:
 *
 *   1. No <img> at all, so image search could index no product photograph and
 *      the LCP element was invisible to the preload scanner until CSS parsed.
 *   2. The thumbnail click handler in pdp.js read `dataset.img` while the
 *      markup emitted `data-image`, so every swap set the frame to
 *      `url('undefined')` and blanked the photo. See the markup guard below.
 *   3. `product.blade.php` built the title as brand . ' ' . name, and imported
 *      names frequently already carry the brand.
 */

/** A product with real photographs, and one with none, in a known catalogue. */
function galleryFixture(): array
{
    $brand = Brand::firstOrCreate(['slug' => 'gallery-brand'], ['name' => 'Gallery Brand', 'position' => 0]);
    $category = Category::firstOrCreate(
        ['slug' => 'gallery-cat'],
        ['name' => 'Gallery Cat', 'position' => 0, 'depth' => 0, 'path' => 'gallery-cat']
    );

    $shot = Product::firstOrCreate(
        ['slug' => 'gallery-photographed'],
        [
            'name' => 'Photographed Toner', 'sku' => 'GAL-1', 'brand_id' => $brand->id,
            'category_id' => $category->id, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
            'image' => '/media/gal-front.png',
            'images' => ['/media/gal-texture.png', '/media/gal-box.png'],
        ]
    );

    $bare = Product::firstOrCreate(
        ['slug' => 'gallery-bare'],
        [
            'name' => 'Unphotographed Toner', 'sku' => 'GAL-2', 'brand_id' => $brand->id,
            'category_id' => $category->id, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
            'image' => null, 'images' => null,
        ]
    );

    return ['brand' => $brand, 'shot' => $shot, 'bare' => $bare];
}

/** The served document, parsed. */
function galleryDom(string $path): DOMXPath
{
    $html = test()->get($path)->assertSuccessful()->getContent();

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

it('renders the main product photograph as a real img, not a CSS background', function () {
    $f = galleryFixture();
    $x = galleryDom('/product/' . $f['shot']->slug . '/');

    $main = $x->query("//div[@id='gmain']/img");
    expect($main->length)->toBe(1, 'the main frame must contain exactly one <img>');

    $img = $main->item(0);
    expect($img->getAttribute('src'))->toBe('/media/gal-front.png');

    // The LCP element: discoverable by the preload scanner and prioritised.
    expect($img->getAttribute('loading'))->toBe('eager');
    expect($img->getAttribute('fetchpriority'))->toBe('high');
    expect($img->getAttribute('decoding'))->toBe('async');

    // Intrinsic ratio present so nothing has to guess the box's shape.
    expect($img->getAttribute('width'))->not->toBe('');
    expect($img->getAttribute('height'))->not->toBe('');
    expect($img->getAttribute('width'))->toBe($img->getAttribute('height'));

    expect($img->getAttribute('alt'))->toBe('Gallery Brand Photographed Toner');
});

it('renders every thumbnail as a real img, lazily', function () {
    $f = galleryFixture();
    $x = galleryDom('/product/' . $f['shot']->slug . '/');

    $thumbs = $x->query("//div[@id='gthumbs']//img");
    expect($thumbs->length)->toBe(3, 'one <img> per gallery shot');

    $srcs = [];
    foreach ($thumbs as $i => $t) {
        $srcs[] = $t->getAttribute('src');

        // Below the fold on a phone and never the LCP.
        expect($t->getAttribute('loading'))->toBe('lazy');
        expect($t->getAttribute('decoding'))->toBe('async');
        expect($t->getAttribute('fetchpriority'))->toBe('');
        expect($t->getAttribute('width'))->not->toBe('');
        expect($t->getAttribute('height'))->not->toBe('');
        expect($t->getAttribute('alt'))->not->toBe('');
    }

    expect($srcs)->toBe(['/media/gal-front.png', '/media/gal-texture.png', '/media/gal-box.png']);
});

it('describes later shots by position, never by the label the controller guessed', function () {
    $f = galleryFixture();
    $x = galleryDom('/product/' . $f['shot']->slug . '/');

    $alts = [];
    foreach ($x->query("//div[@id='gthumbs']//img") as $t) {
        $alts[] = $t->getAttribute('alt');
    }

    expect($alts)->toBe([
        'Gallery Brand Photographed Toner',
        'Gallery Brand Photographed Toner, view 2 of 3',
        'Gallery Brand Photographed Toner, view 3 of 3',
    ]);

    /* ProductController::gallery() names shots from a fixed list by index --
       'Front', 'Texture', 'Ingredients'. Beyond the first that is a statement
       about position, not about the photograph, so it must not reach alt text:
       whatever was uploaded second is not thereby a texture shot. */
    foreach ($alts as $alt) {
        expect($alt)->not->toContain('Texture');
        expect($alt)->not->toContain('Ingredients');
    }
});

it('no longer paints any gallery photograph as a CSS background', function () {
    $f = galleryFixture();
    $html = test()->get('/product/' . $f['shot']->slug . '/')->assertSuccessful()->getContent();

    $start = strpos($html, 'class="gallery"');
    expect($start)->not->toBeFalse();
    $gallery = substr($html, $start, 4000);

    /* The exact shape that used to hide every photograph from crawlers:
       `background:#fff url('...') center/contain no-repeat`. A gradient
       placeholder is still a background and is still allowed -- it is not a
       photograph. What must never come back is a url() carrying an image. */
    expect($gallery)->not->toMatch('/background:[^"]*url\(/i');
});

it('keeps the placeholder deliberate for a product with no photograph', function () {
    $f = galleryFixture();
    $x = galleryDom('/product/' . $f['bare']->slug . '/');

    // No <img> invented for a product that has no image.
    expect($x->query("//div[@id='gmain']/img")->length)->toBe(0);

    // The gradient and the brand/label caption are what make it look intended.
    $main = $x->query("//div[@id='gmain']")->item(0);
    expect($main->getAttribute('style'))->toContain('linear-gradient');

    $cap = $x->query("//span[@id='gcap']")->item(0);
    expect($cap)->not->toBeNull();
    expect($cap->hasAttribute('hidden'))->toBeFalse('the caption must be visible when no photo is showing');
    expect($cap->textContent)->toContain('Gallery Brand');
});

it('keeps the wishlist button and the swap hooks the script depends on', function () {
    $f = galleryFixture();
    $x = galleryDom('/product/' . $f['shot']->slug . '/');

    expect($x->query("//button[@id='gwish']")->length)->toBe(1);

    /* pdp.js reads data-image and data-alt off the thumbnail. It used to read
       `dataset.img`, which the markup has never emitted, so every swap produced
       url('undefined') and blanked the photograph. Pinning the attribute NAMES
       is what stops that pairing drifting apart again. */
    foreach ($x->query("//div[@id='gthumbs']/div") as $thumb) {
        expect($thumb->hasAttribute('data-image'))->toBeTrue();
        expect($thumb->hasAttribute('data-alt'))->toBeTrue();
        expect($thumb->getAttribute('data-image'))->not->toBe('');
    }

    // The caption is present but hidden while a photograph is showing, so the
    // script can reveal it when a placeholder shot is selected.
    $cap = $x->query("//span[@id='gcap']")->item(0);
    expect($cap)->not->toBeNull();
    expect($cap->hasAttribute('hidden'))->toBeTrue();
});

it('names the brand exactly once in the page title', function () {
    $f = galleryFixture();
    $path = '/product/' . $f['shot']->slug . '/';

    /* Read the real <title>, and assert the brand's OCCURRENCE COUNT rather
       than the whole string. Seo::render() wraps whatever the view yields in
       the configured title template -- the served title here ends up as
       "... · K-Beauty Bliss | KBB" -- so pinning the full text would be
       pinning the SEO settings, not this fix. The defect was the brand
       appearing twice; that is what gets asserted. */
    $titleOf = function (string $path): string {
        $x = galleryDom($path);
        $node = $x->query('//title')->item(0);

        expect($node)->not->toBeNull();

        return $node->textContent;
    };

    // A name that does not carry its brand gets the brand prepended: once.
    $title = $titleOf($path);
    expect(substr_count($title, 'Gallery Brand'))->toBe(1, "title was: {$title}");
    expect($title)->toContain('Gallery Brand Photographed Toner');

    // A name that already carries the brand does not get it a second time.
    $f['shot']->update(['name' => 'Gallery Brand Heartleaf 77% Soothing Toner']);

    $title = $titleOf($path);
    expect(substr_count($title, 'Gallery Brand'))->toBe(1, "title was: {$title}");
    expect($title)->toContain('Gallery Brand Heartleaf 77% Soothing Toner');
    expect($title)->not->toContain('Gallery Brand Gallery Brand');

    // And the <h1> still carries the stored name, untouched -- the join is a
    // display concern and must not rewrite the product.
    $x = galleryDom($path);
    expect($x->query("//h1[@id='bbTitle']")->item(0)->textContent)
        ->toBe('Gallery Brand Heartleaf 77% Soothing Toner');
});

it('holds the product page to its measured query count', function () {
    $f = galleryFixture();
    $path = '/product/' . $f['shot']->slug . '/';

    // Warm the process-lifetime caches first -- Setting::map() memoises in a
    // function static, so a cold first request measures those too and would
    // report a number that has nothing to do with this page.
    test()->get($path)->assertSuccessful();

    $n = 0;
    DB::listen(function () use (&$n) { $n++; });

    test()->get($path)->assertSuccessful();

    /* Rendering three <img> elements instead of three background declarations
       reads no extra rows: the gallery array was already built and the brand
       was already eager-loaded. If this moves, something started querying per
       shot. */
    expect($n)->toBeLessThanOrEqual(9, "product page ran {$n} queries; the measured count is 9");
});

/* ── The joining rule itself ─────────────────────────────────────────────── */

it('joins brand and name so the brand appears exactly once', function (string $brand, string $name, string $expected) {
    expect(ProductTitle::full($brand, $name))->toBe($expected);
})->with([
    // The reported bug.
    ['Anua', 'Anua Heartleaf 77% Soothing Toner', 'Anua Heartleaf 77% Soothing Toner'],
    // A name that genuinely does not carry its brand keeps everything it has:
    // stripping a leading brand instead would have eaten the "1025" here.
    ['Round Lab', '1025 Dokdo Toner', 'Round Lab 1025 Dokdo Toner'],
    // Case differs in the real catalogue -- the store writes "celimax" lower.
    ['Celimax', 'celimax The Vita-A Retinal Shot', 'celimax The Vita-A Retinal Shot'],
    // Punctuation and spacing differ: "Dr.Althea" vs "Dr. Althea".
    ['Dr. Althea', 'Dr.Althea 345 Relief Cream', 'Dr.Althea 345 Relief Cream'],
    // Letter/digit runs: the brand row says SKIN1004, the storefront's own
    // trending-words list says SKIN 1004.
    ['SKIN1004', 'SKIN 1004 Madagascar Centella Ampoule', 'SKIN 1004 Madagascar Centella Ampoule'],
    ['SKIN 1004', 'SKIN1004 Madagascar Centella Ampoule', 'SKIN1004 Madagascar Centella Ampoule'],
    // A prefix is not a word: "Anua" must not match a name beginning "Anuaa".
    ['Anua', 'Anuaa Serum', 'Anua Anuaa Serum'],
    // The brand appearing later in the name is not the brand leading it.
    ['Anua', 'Heartleaf Toner by Anua', 'Anua Heartleaf Toner by Anua'],
    // Multi-word brands match as a unit, not word by word.
    ['Beauty of Joseon', 'Beauty of Joseon Relief Sun', 'Beauty of Joseon Relief Sun'],
    ['Beauty of Joseon', 'Beauty Water Toner', 'Beauty of Joseon Beauty Water Toner'],
    // Degenerate inputs.
    ['', 'Just A Name', 'Just A Name'],
    ['Only Brand', '', 'Only Brand'],
    ['COSRX', 'COSRX', 'COSRX'],
    ['  Anua  ', '  Anua Serum  ', 'Anua Serum'],
]);

it('labels gallery shots by position only after the first', function () {
    expect(ProductTitle::alt('Anua', 'Heartleaf Toner', 0, 3))->toBe('Anua Heartleaf Toner');
    expect(ProductTitle::alt('Anua', 'Heartleaf Toner', 1, 3))->toBe('Anua Heartleaf Toner, view 2 of 3');
    // A lone shot is not "view 1 of 1".
    expect(ProductTitle::alt('Anua', 'Heartleaf Toner', 0, 1))->toBe('Anua Heartleaf Toner');
});
