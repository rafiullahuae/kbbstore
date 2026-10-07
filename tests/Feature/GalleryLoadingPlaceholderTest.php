<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductStyles;
use App\Services\SettingsService;

/*
 * Lane PG2 -- "when i open any product page, specially in incognito or after
 * cache clear. it gives the empty box and title displays there in place of
 * image ... and switching between the product gallery images, gives clear
 * delays", then "i would love to display the loading grey bars", then "same
 * thing for product image too".
 *
 * WHAT THE SHOP DID. A photo still on its way was an empty white box (the
 * gallery frame) or a flat cream one (a product card). A photo whose request
 * FAILED was its alt text -- the product title printed top-left over the
 * discount badge, on the main photo and on all four thumbnails, which is the
 * owner's screenshot; Chromium paints alt text for a broken <img> and nothing
 * for a loading one (measured, docs/PG2-STALLS.md). And a thumbnail tap
 * changed the src of the <img> on screen, which keeps the OLD photo painted
 * until the new file has fully arrived: 1.4 s on a throttled phone with
 * nothing moving.
 *
 * WHAT IS PINNED:
 *   1. tools/pg2g-gallery-unit.cjs, run in Chromium against the real pdp.js,
 *      kbb.css and kbb-product.css: grey box while loading, no alt text, the
 *      tapped thumbnail's own picture at once, the grey box (never the old
 *      photo) when the thumbnail has nothing yet, the next shot warmed after
 *      load and on hover, the grey handed back once decoded.
 *   2. The gallery markup: the main photo stays the LCP (eager, high), the
 *      thumbnails are eager at LOW priority (they are on the first screen of a
 *      phone; lazy ones were not even requested until layout).
 *   3. The stylesheets: the grey sits behind the photo on every gallery and
 *      card <img>, it stops (finite sweeps, reduced motion), and it moves no
 *      geometry -- nothing can shift.
 *   4. Appearance → Product styles → Layout → "Photo loading placeholder":
 *      ships at Grey shimmer and prints nothing for it; Plain grey and None
 *      print their one fixed rule on the product page AND a category page.
 *
 * MUTATIONS, each run: put pdp.js back to `img.src = image` on the element on
 * screen and the unit says "3. a tap left the OLD photo ... [220,40,40]";
 * delete the (Lane PG2) block from kbb-product.css and it says "1. the main
 * frame is not the grey loading box" and "6. a failed photo painted its alt
 * text"; change `8}` to `infinite}` on either rule and test 3 here is red.
 */

function pgStripped(string $file): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/'.$file)));
}

/** @return list<string> every <img ...> tag with this class, from the document only */
function pgTags(string $html, string $class): array
{
    preg_match_all('/<img\b[^>]*class="'.preg_quote($class, '/').'"[^>]*>/s', $html, $m);

    return $m[0];
}

function pgProductPage(): string
{
    $brand = Brand::firstOrCreate(['slug' => 'pg-axis'], ['name' => 'AXIS-Y']);
    $product = Product::create([
        'slug' => 'pg-'.uniqid(), 'name' => 'Dark Spot Correcting Glow Serum', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'image' => '/uploads/pg/a.jpg', 'images' => ['/uploads/pg/a.jpg', '/uploads/pg/b.jpg', '/uploads/pg/c.jpg'],
    ]);

    return (string) test()->get('/product/'.$product->slug)->assertOk()->getContent();
}

function pgCategoryPage(): string
{
    $cat = Category::firstOrCreate(['slug' => 'pg-serums'], ['name' => 'Serums', 'path' => 'pg-serums', 'depth' => 0]);
    $p = Product::create([
        'slug' => 'pg-c-'.uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'image' => '/uploads/pg/a.jpg',
    ]);
    $p->categories()->attach($cat->id);

    return (string) test()->get('/collections/pg-serums/')->assertOk()->getContent();
}

it('shows the grey box, never alt text and never the old photo, in a real browser', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    $chrome = (string) env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium');
    if ($node === '' || ! is_file($chrome) || ! is_dir(base_path('node_modules/playwright'))) {
        $this->markTestSkipped('node, playwright or Chromium is not installed here; tools/pg2g-gallery-unit.cjs needs them.');
    }

    exec(escapeshellarg($node).' '.escapeshellarg(base_path('tools/pg2g-gallery-unit.cjs')).' '.escapeshellarg($chrome).' 2>&1', $out, $code);

    expect($code)->toBe(0, implode("\n", $out))
        ->and(implode("\n", $out))->toMatch('/^ok \d+$/m');
});

it('keeps the main photo the LCP and fetches the thumbnails at once, at low priority', function () {
    $html = pgProductPage();

    $main = pgTags($html, 'gmain-img');
    expect($main)->toHaveCount(1)
        ->and($main[0])->toContain('loading="eager"')->toContain('fetchpriority="high"')
        ->toContain('alt="')->not->toContain('loading="lazy"');

    $thumbs = pgTags($html, 'gthumb-img');
    expect($thumbs)->toHaveCount(3);
    foreach ($thumbs as $tag) {
        // Lazy thumbnails sat on a phone's first screen unrequested until layout:
        // painted 2603 ms lazy, 1451 ms eager+low, main photo not delayed.
        expect($tag)->toContain('loading="eager"')->toContain('fetchpriority="low"')
            ->not->toContain('loading="lazy"')->toMatch('/\salt="[^"]+"/');
    }
});

it('paints the grey behind every gallery and card photo, stops it, and moves nothing', function () {
    $product = pgStripped('kbb-product.css');
    $base = pgStripped('kbb.css');

    preg_match('/\.gmain-img,\.gthumb-img\{([^}]*)\}/', $product, $g);
    preg_match('/\.kbb-card \.kbb-card-img\{([^}]*)\}/', $base, $c);

    foreach (['gallery' => $g[1] ?? '', 'card' => $c[1] ?? ''] as $where => $rule) {
        expect($rule)->not->toBe('', "the {$where} placeholder rule is gone")
            ->toContain('color:transparent')            // never the alt text
            ->toContain('#f2f4f7')                      // the shop's skeleton grey
            ->toMatch('/animation:kbbshim [\d.]+s ease \d+$/')   // a COUNT, so it stops
            ->not->toMatch('/infinite|width|height|margin|padding|position|inset|aspect/');
    }

    expect($product)->toContain('.gmain-img.ld,.gthumb-img.ld{background:none;animation:none}')
        ->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\.gmain-img,\.gthumb-img\{animation:none\}\}/')
        ->and($base)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\.kbb-card \.kbb-card-img\{animation:none\}\}/');
});

it('ships at Grey shimmer and prints nothing for it; Plain grey and None reach the product page and the grid', function () {
    $settings = app(SettingsService::class);
    expect(ProductStyles::SCHEMA['photo_placeholder'][2])->toBe('shimmer')
        ->and(ProductStyles::TABS['layout'][2])->toContain('photo_placeholder');

    $plain = '.kbb-card .kbb-card-img.kbb-card-img,.gmain-img.gmain-img,.gthumb-img.gthumb-img{animation:none}';
    $none = '.kbb-card .kbb-card-img.kbb-card-img{background:#FFF8F5;animation:none}.gmain-img.gmain-img,.gthumb-img.gthumb-img{background:none;animation:none}';

    expect(pgProductPage())->not->toContain($plain)->not->toContain($none);

    $settings->set('photo_placeholder', 'plain');
    app(ProductStyles::class)->forgetResolved();
    expect(pgProductPage())->toContain($plain)
        ->and(pgCategoryPage())->toContain($plain);

    $settings->set('photo_placeholder', 'none');
    app(ProductStyles::class)->forgetResolved();
    expect(pgProductPage())->toContain($none)->not->toContain($plain);

    // A row written by hand is one of the three or the default: never CSS.
    $settings->set('photo_placeholder', '}body{display:none');
    app(ProductStyles::class)->forgetResolved();
    expect(app(ProductStyles::class)->cardCss())->not->toContain('display:none')->not->toContain($plain)->not->toContain($none);
});
