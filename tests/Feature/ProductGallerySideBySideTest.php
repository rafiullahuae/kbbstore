<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The product gallery puts its thumbnails BESIDE the main frame on a desktop
 * width and BELOW it on a phone.
 *
 * Two halves are pinned here and they fail for different reasons.
 *
 * The first half parses the SERVED DOCUMENT. Moving the strip is a layout
 * change, so the temptation is to test only CSS -- but the reason the strip can
 * move at all is that the markup already emits a real <img> per shot with the
 * attributes that make the main shot the LCP element, and a layout change is
 * exactly the kind of edit that quietly drops a fetchpriority or reorders the
 * shots. Asserting a Blade variable, or that a URL appears somewhere in the
 * body, would pass against a page that emitted none of it. DOMDocument reads
 * the response and the assertions are made against nodes and attributes.
 *
 * The second half reads CSS out of GIT rather than off disk, the same way
 * BuildAssetsTest and ProductMobileLayoutTest do, and for the same two reasons:
 * a migration in this suite deletes public/build as a side effect, so the
 * working tree is actively unreliable here, and a package is built from
 * `git show` -- what ships is what is committed. Both the source stylesheet and
 * the built bundle the server actually serves are checked, because this repo's
 * signature failure is a fix that is real in one half and absent in the other.
 *
 * The layout is CSS-only by design: no marker class, no wrapper element, no
 * second code path in the Blade. That is asserted too, because the cheap way to
 * build a side-by-side gallery is to add a class in the partial, and then the
 * markup has two shapes and every other guard in the suite only covers one.
 */

/** A catalogue with a many-shot product, a two-shot one and a single-shot one. */
function sideBySideFixture(): array
{
    $brand = Brand::firstOrCreate(['slug' => 'rail-brand'], ['name' => 'Rail Brand', 'position' => 0]);
    $category = Category::firstOrCreate(
        ['slug' => 'rail-cat'],
        ['name' => 'Rail Cat', 'position' => 0, 'depth' => 0, 'path' => 'rail-cat']
    );

    $base = [
        'brand_id' => $brand->id, 'category_id' => $category->id, 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ];

    $many = Product::firstOrCreate(['slug' => 'rail-many'], $base + [
        'name' => 'Many Shot Toner', 'sku' => 'RAIL-1',
        'image' => '/media/rail-1.png',
        'images' => ['/media/rail-2.png', '/media/rail-3.png', '/media/rail-4.png',
            '/media/rail-5.png', '/media/rail-6.png', '/media/rail-7.png', '/media/rail-8.png'],
    ]);

    $two = Product::firstOrCreate(['slug' => 'rail-two'], $base + [
        'name' => 'Two Shot Toner', 'sku' => 'RAIL-2',
        'image' => '/media/rail-1.png', 'images' => ['/media/rail-2.png'],
    ]);

    $one = Product::firstOrCreate(['slug' => 'rail-one'], $base + [
        'name' => 'One Shot Toner', 'sku' => 'RAIL-3',
        'image' => '/media/rail-1.png', 'images' => [],
    ]);

    return ['brand' => $brand, 'many' => $many, 'two' => $two, 'one' => $one];
}

/** The served document, parsed. */
function railDom(string $slug): DOMXPath
{
    $html = test()->get('/product/'.$slug.'/')->assertSuccessful()->getContent();

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/** Committed content for a path, as git has it. What ships is what is committed. */
function railTracked(string $path): ?string
{
    $out = shell_exec('git -C '.escapeshellarg(base_path()).' show HEAD:'.escapeshellarg($path).' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}

/** The product stylesheet's source, as committed. */
function railSourceCss(): string
{
    $css = railTracked('resources/css/kbb/kbb-product.css');
    expect($css)->not->toBeNull('resources/css/kbb/kbb-product.css is not committed');

    return (string) $css;
}

/** The built product stylesheet the page loads, resolved through the manifest. */
function railBuiltCss(): string
{
    $manifest = json_decode((string) railTracked('public/build/manifest.json'), true);
    $entry = 'resources/css/kbb/kbb-product.css';

    expect($manifest)->toBeArray()->toHaveKey($entry);

    $file = $manifest[$entry]['file'] ?? null;
    expect($file)->not->toBeNull();

    $css = railTracked('public/build/'.$file);
    expect($css)->not->toBeNull(
        "public/build/{$file} is referenced by the manifest but is not committed — ".
        'vite deletes sibling hashed assets, so `git checkout -- public/build/assets` has to follow a build.'
    );

    return (string) $css;
}

/* ── The served document ─────────────────────────────────────────────────── */

it('still emits the main shot as the LCP img, with the strip beside it', function () {
    $f = sideBySideFixture();
    $x = railDom($f['many']->slug);

    $main = $x->query("//div[@id='gmain']/img");
    expect($main->length)->toBe(1, 'the main frame must contain exactly one <img>');

    $img = $main->item(0);
    expect($img->getAttribute('src'))->toBe('/media/rail-1.png');

    // Unchanged by the move: the main shot is still the LCP element.
    expect($img->getAttribute('loading'))->toBe('eager');
    expect($img->getAttribute('fetchpriority'))->toBe('high');
    expect($img->getAttribute('decoding'))->toBe('async');
    expect($img->getAttribute('width'))->toBe($img->getAttribute('height'));
    expect($img->getAttribute('alt'))->toBe('Rail Brand Many Shot Toner');
});

it('renders every shot in gallery order, lazily, whichever side the strip is on', function () {
    $f = sideBySideFixture();
    $x = railDom($f['many']->slug);

    $thumbs = $x->query("//div[@id='gthumbs']//img");
    expect($thumbs->length)->toBe(8, 'one <img> per gallery shot');

    $srcs = [];
    foreach ($thumbs as $t) {
        $srcs[] = $t->getAttribute('src');
        expect($t->getAttribute('loading'))->toBe('lazy');
        expect($t->getAttribute('decoding'))->toBe('async');
        expect($t->getAttribute('fetchpriority'))->toBe('');
        expect($t->getAttribute('alt'))->not->toBe('');
    }

    /* Order is the assertion. A vertical rail reads top-to-bottom where the
       strip read left-to-right, and a reordering would be invisible in any test
       that only counted them. */
    expect($srcs)->toBe([
        '/media/rail-1.png', '/media/rail-2.png', '/media/rail-3.png', '/media/rail-4.png',
        '/media/rail-5.png', '/media/rail-6.png', '/media/rail-7.png', '/media/rail-8.png',
    ]);
});

it('keeps the main frame ahead of the strip in source order', function () {
    $f = sideBySideFixture();
    $x = railDom($f['many']->slug);

    /* The rail paints to the LEFT of the frame but must not be moved above it
       in the markup to get there. Source order is what the preload scanner
       walks and what a screen reader reads, and the hero belongs first in both.
       CSS does the moving; this is what stops someone "simplifying" it by
       swapping the two blocks in the partial. */
    $children = $x->query("//div[@id='gmain'] | //div[@id='gthumbs']");
    expect($children->length)->toBe(2);
    expect($children->item(0)->getAttribute('id'))->toBe('gmain');
    expect($children->item(1)->getAttribute('id'))->toBe('gthumbs');
});

it('builds the side-by-side layout in CSS alone, with no second markup shape', function () {
    $f = sideBySideFixture();

    foreach (['many', 'two', 'one'] as $key) {
        $html = test()->get('/product/'.$f[$key]->slug.'/')->assertSuccessful()->getContent();

        /* One gallery element, one class list, for every shot count. If a
           marker class ever gets added here the partial has two shapes and
           every other assertion in the suite covers only one of them. */
        expect(substr_count($html, 'class="gallery"'))->toBe(
            1,
            "product {$key}: the gallery must carry exactly the class `gallery` — ".
            'the rail is selected with :has(.gthumbs), not with a marker class.'
        );
    }
});

it('renders no strip at all for a single-shot product', function () {
    $f = sideBySideFixture();
    $x = railDom($f['one']->slug);

    /* Which is why the CSS is scoped with :has(.gthumbs): a product with one
       photograph has no rail, and must not be left with an empty 78px gutter
       down the side of its frame. */
    expect($x->query("//div[@id='gthumbs']")->length)->toBe(0);
    expect($x->query("//div[@id='gmain']/img")->length)->toBe(1);
});

/* ── The stylesheet, source and built ────────────────────────────────────── */

dataset('rail stylesheets', [
    'source' => [fn () => railSourceCss()],
    'built bundle' => [fn () => railBuiltCss()],
]);

it('puts the strip beside the frame only from 881px up', function (Closure $css) {
    $css = $css();

    // The same breakpoint .pdp and .gallery already switch on, so the gallery
    // never sits between two layouts.
    expect($css)->toMatch('/@media\s*\(min-width:\s*881px\)\s*\{/');

    // Everything the rail needs lives inside that query. Nothing about the rail
    // may appear unconditionally, or a 390px screen gets a squeezed desktop.
    $railRules = [];
    if (preg_match_all('/@media\s*\(min-width:\s*881px\)\s*\{(.*?\}\s*)\}/s', $css, $m)) {
        $railRules = $m[1];
    }
    $inside = implode("\n", $railRules);

    expect($inside)->toContain('padding-left:78px');
    expect($inside)->toContain('position:absolute');

    /* The rail must not leak outside the media query. Counting occurrences in
       the whole sheet against occurrences inside the query is what catches a
       rule that was moved out, which no amount of "does it contain" would. */
    expect(substr_count($css, 'padding-left:78px'))
        ->toBe(substr_count($inside, 'padding-left:78px'), 'a rail rule escaped the 881px media query');
})->with('rail stylesheets');

it('scopes the rail to a gallery that actually has one', function (Closure $css) {
    $css = $css();

    // :has(.gthumbs), so a single-shot product keeps a full-width frame.
    expect($css)->toMatch('/\.gallery:has\(\s*\.gthumbs\s*\)\s*\{[^}]*padding-left:\s*78px/');
})->with('rail stylesheets');

it('takes the rail out of flow so the frame alone sizes the column', function (Closure $css) {
    $css = $css();

    /* The load-bearing detail. As an in-flow track the rail's own content
       height feeds back into the row, and a product with more shots than fit
       stretches the column taller than the photograph -- a rail hanging below
       the frame with nothing beside it. top:0/bottom:0 inverts that: the frame
       sizes .gallery and the rail is told the answer. */
    expect($css)->toMatch(
        '/\.gallery:has\(\s*\.gthumbs\s*\)\s+\.gthumbs\s*\{[^}]*position:\s*absolute/',
        'the rail must be absolutely positioned'
    );

    foreach (['left:0', 'top:0', 'bottom:0', 'width:66px'] as $decl) {
        expect($css)->toMatch(
            '/\.gallery:has\(\s*\.gthumbs\s*\)\s+\.gthumbs\s*\{[^}]*'.preg_quote($decl, '/').'/',
            "the rail must declare {$decl}"
        );
    }
})->with('rail stylesheets');

it('stacks the rail downward and lets it scroll when there are more shots than fit', function (Closure $css) {
    $css = $css();

    $rail = '/\.gallery:has\(\s*\.gthumbs\s*\)\s+\.gthumbs\s*\{[^}]*';

    expect($css)->toMatch($rail.'flex-direction:\s*column/', 'the rail runs top to bottom');
    expect($css)->toMatch($rail.'flex-wrap:\s*nowrap/', 'a wrapping rail would start a second column');

    /* Twelve shots measure 902px of thumbnails in a 646px rail. Without
       overflow-y they are simply cut off and the last four are unreachable. */
    expect($css)->toMatch($rail.'overflow-y:\s*auto/', 'the rail must scroll');
    expect($css)->toMatch($rail.'min-height:\s*0/', 'min-height:0 is what lets it scroll instead of growing');
})->with('rail stylesheets');

it('refuses to squash thumbnails to make them fit', function (Closure $css) {
    $css = $css();

    /* A column flex item shrinks by default. Twelve 66px squares in a 646px
       rail are quietly squashed to ~53px instead of overflowing, which also
       means the scrollbar -- the only thing telling a shopper there are more --
       never appears. flex:0 0 auto is what makes them overflow. */
    expect($css)->toMatch(
        '/\.gallery:has\(\s*\.gthumbs\s*\)\s+\.gthumb\s*\{[^}]*flex:\s*0\s+0\s+auto/',
        'thumbnails in the rail must not shrink'
    );
})->with('rail stylesheets');

it('gives the frame back the height the strip used to add', function (Closure $css) {
    $css = $css();

    /* Measured at 1280: the strip below the frame was holding the column up, so
       a square frame beside a rail leaves the gallery 484px tall against a
       715px buy box -- a 231px hole where there used to be 75px. 3:4 brings it
       to 646px and a 69px gap. The frame's WIDTH is unchanged by the ratio and
       object-fit:contain scales to whichever edge binds first, so no shot
       squarer than 3:4 renders one pixel smaller than it did. */
    expect($css)->toMatch(
        '/\.gallery:has\(\s*\.gthumbs\s*\)\s+\.gmain\s*\{[^}]*aspect-ratio:\s*3\s*\/\s*4/',
        'the side-by-side frame is 3:4'
    );
})->with('rail stylesheets');

it('leaves the phone layout exactly as it was', function (Closure $css) {
    $css = $css();

    /* The horizontal strip is the unconditional rule and the rail overrides it
       above 881px, rather than the other way round. That ordering is why a
       phone cannot inherit a squeezed desktop layout. */
    expect($css)->toMatch('/\.gthumbs\s*\{[^}]*display:\s*flex[^}]*\}/');
    expect($css)->toMatch('/\.gthumbs\s*\{[^}]*flex-wrap:\s*wrap[^}]*\}/', 'the strip below still wraps');
    expect($css)->toMatch('/\.gthumbs\s*\{[^}]*margin-top:\s*12px[^}]*\}/', 'the strip below keeps its 12px gap');

    // The square frame is still the default; 3:4 applies only beside a rail.
    expect($css)->toMatch('/\.gmain\s*\{[^}]*aspect-ratio:\s*1[^}]*\}/');

    // And the gallery still goes static under 880px.
    expect($css)->toMatch('/@media\s*\(max-width:\s*880px\)\s*\{\s*\.gallery\s*\{\s*position:\s*static/');
})->with('rail stylesheets');

it('does not disturb the phone gutters another lane pinned', function (Closure $css) {
    $css = $css();

    /* ProductMobileLayoutTest owns these. They are re-asserted from this lane
       because the rail work sits in the same stylesheet and a careless edit to
       the gallery block is exactly how a neighbouring rule gets lost.

       toBeTrue, not toContain: Pest's toContain is variadic, so a second string
       argument there is read as another needle rather than as a message -- the
       trap ProductMobileLayoutTest's own header warns about, and which this
       assertion walked straight into on its first run. */
    expect((bool) preg_match('/@media\s*\(max-width:\s*600px\)\s*\{\s*\.rel\s*\{\s*gap:\s*10px/', $css))
        ->toBeTrue('the related grid keeps its 10px gap under 600px');

    expect((bool) preg_match('/@media\s*\(max-width:\s*940px\)\s*\{\s*\.wrap\s*>\s*\.sr\s*\{\s*padding-left:\s*0/', $css))
        ->toBeTrue('the reviews block keeps its single 20px gutter at phone width');
})->with('rail stylesheets');

/* ── Cost ────────────────────────────────────────────────────────────────── */

it('moves the strip without costing the product page a single query', function () {
    $f = sideBySideFixture();
    $path = '/product/'.$f['many']->slug.'/';

    // Warm the process-lifetime caches first -- Setting::map() memoises in a
    // function static, so a cold first request measures those too.
    test()->get($path)->assertSuccessful();

    $n = 0;
    DB::listen(function () use (&$n) { $n++; });

    test()->get($path)->assertSuccessful();

    /* The layout is CSS. It reads no rows, and an eight-shot product reads no
       more than a three-shot one -- if this moves, something started querying
       per shot. */
    expect($n)->toBe(9, "product page ran {$n} queries; the measured count is 9");
});
