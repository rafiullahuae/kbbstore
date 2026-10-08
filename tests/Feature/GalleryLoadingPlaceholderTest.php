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
 * LANE GX -- "the 66px stand-in stretched to the gallery size looks blurry for
 * a moment", then "display a grey loading instead of presenting blur", then
 * "in the background silently load all gallery sharp pictures". Measured on
 * a throttled phone (390px DPR 3, 4x CPU, 1.6 Mbps) the old stand-in was a
 * 200px file over 1050 real pixels, and not even instant: old -> grey (150 ms)
 * -> blurred (350 ms) -> sharp (1.4 s). The unit (checks 3, 7-11) now pins:
 * not ready -> the grey box, NEVER the stretched thumbnail; the 400w copy a
 * finger asked for stands in when it is in (or takes over the grey when it
 * lands); the photograph fades in (0.14 s, none under reduced motion) and the
 * stand-in goes; on 4G the whole gallery is fetched one file at a time by a
 * cancellable fetch() after load AND the main photo, so a later tap paints
 * the photograph in its first frame and downloads nothing; a finger on a link
 * cancels the file in flight; Save-Data fetches nothing unasked.
 *
 * MUTATIONS, each run: put pdp.js back to `img.src = image` on the element on
 * screen and the unit says "3. a tap left the OLD photo ... [220,40,40]";
 * delete the (Lane PG2) block from kbb-product.css and it says "1. the main
 * frame is not the grey loading box" and "6. a failed photo painted its alt
 * text"; change `8}` to `infinite}` on either rule and test 3 here is red.
 * (Lane GX) Put the stretched thumbnail back as the stand-in and the unit says
 * "3. a tap whose photograph is not in showed something other than the grey
 * box ... [230,180,20]"; drop the finger's mid request and "7. a finger on a
 * thumbnail did not ask for its 400w copy"; warm with Image() instead of
 * fetch() and "8 ... by fetch(): [0,0,0]" and "9. a finger on a link did not
 * cancel"; drop the link listener and "9. a finger on a link did not cancel";
 * let Save-Data through and "10. Save-Data: a shot was fetched without being
 * tapped"; start the next file before the last one ends and "8 ... two shots
 * at once"; drop the transition and "3. the photograph does not fade in".
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

/*
 * ▲ Lane LZ -- THE GREY BOX IS FOR THE PRODUCT PAGE'S 2ND PHOTO ONWARDS ONLY.
 * The owner, 8 October: "we have done the fade grey stuff yesterday i think.
 * but i wanted only and only for images 2nd and onwards images on the products
 * page. no any other! bcz the product page gallery images switching was coming
 * blurred. so you did this, but you have applied on the whole site, which i
 * didn't want it." PG2 painted it behind every card on every page, the main
 * photo and the thumbnails. Now: the cards are the cream tile, the main photo
 * and the thumbnails the frame's white, exactly as before PG2; the grey is on
 * the tap path only (.gx-s, the stand-in, and .gx-in, the tapped photo), which
 * pdp.js builds after a tap and the server never prints.
 *
 * MUTATIONS: put the grey back on `.kbb-card .kbb-card-img` or on
 * `.gmain-img,.gthumb-img` (PG2's selectors) and the first case is red.
 */
it('draws the grey loading box only behind a tapped 2nd-onwards photo, never a card, the first photo or a thumbnail', function () {
    $sheets = [];
    foreach (['kbb.css', 'kbb-product.css', 'kbb-shop.css', 'kbb-grid-skins.css'] as $f) {
        $sheets[$f] = pgStripped($f);
    }

    $greyed = [];
    foreach ($sheets as $f => $css) {
        preg_match_all('/([^{}]+)\{([^{}]*(?:kbbshim|#f2f4f7)[^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as [, $sel]) {
            foreach (explode(',', trim($sel)) as $one) {
                if (preg_match('/kbb-card-img|gmain-img|gthumb-img|\bimg\b/', $one)) {
                    $greyed[] = trim($one);
                }
            }
        }
    }

    // Exactly the tap path, and every selector carries a tap-only class.
    expect($greyed)->toBe(['.gmain-img.gx-s', '.gmain-img.gx-in']);
    // PG2's site-wide rules are gone; its alt-text lock stays.
    expect($sheets['kbb.css'])->toContain('.kbb-card .kbb-card-img{color:transparent}')
        ->not->toMatch('/\.kbb-card \.kbb-card-img\{[^}]*(background|animation)/');
    expect($sheets['kbb-product.css'])->toContain('.gmain-img,.gthumb-img{color:transparent}')
        ->not->toMatch('/(^|\})\.gmain-img,\.gthumb-img\{[^}]*(background|animation)/');
    // The cream tile the cards had before PG2 is what they show again.
    expect($sheets['kbb.css'])->toContain('.kbb-card img{width:100%;aspect-ratio:1;object-fit:cover;background:#FFF8F5}');
});

it('stops the box, honours reduced motion, hands it back once decoded, and moves nothing', function () {
    $product = pgStripped('kbb-product.css');

    preg_match('/\.gmain-img\.gx-s,\.gmain-img\.gx-in\{([^}]*)\}/', $product, $g);
    expect($g[1] ?? '')->toContain('#f2f4f7')
        ->toMatch('/animation:kbbshim [\d.]+s ease \d+$/')   // a COUNT, so it stops
        ->not->toMatch('/infinite|width|height|margin|padding|position|inset|aspect/');
    expect($product)->toContain('.gmain-img.gx-in.ld{background:none;animation:none}')
        ->toContain('@media (prefers-reduced-motion:reduce){.gmain-img.gx-s,.gmain-img.gx-in{animation:none}}');

    // Neither class is ever in the server's HTML: the page opens without the box.
    $html = pgProductPage();
    expect($html)->not->toContain('gx-s')->not->toContain('gx-in');
});

it('ships at Grey shimmer and prints nothing for it; Plain grey and None reach the product page', function () {
    $settings = app(SettingsService::class);
    expect(ProductStyles::SCHEMA['photo_placeholder'][2])->toBe('shimmer')
        ->and(ProductStyles::TABS['layout'][2])->toContain('photo_placeholder');

    // (Lane LZ) The tap path only, like the box itself.
    $plain = '.gmain-img.gx-s.gx-s,.gmain-img.gx-in.gx-in{animation:none}';
    $none = '.gmain-img.gx-s.gx-s,.gmain-img.gx-in.gx-in{background:none;animation:none}';
    expect(ProductStyles::SCHEMA['photo_placeholder'][3])->toContain('Product page only')->toContain('not affected');

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

it('fades a tapped photograph in over its stand-in, briefly, moving nothing, and not under reduced motion', function () {
    /* (Lane GX) The photograph used to pop over a stretched thumbnail. It
       now fades in over the grey box or the mid copy: opacity only, 0.14 s,
       none when the shopper asked for reduced motion. pdp.js adds .gx-in only
       on a tap whose photograph is not already in, so a warmed shot paints
       straight away with no fade at all (unit check 8). */
    $product = pgStripped('kbb-product.css');

    expect($product)->toContain('.gmain-img.gx-in{opacity:0;transition:opacity .14s ease-out}')
        ->toContain('.gmain-img.gx-in.ld{opacity:1}')
        ->toContain('@media (prefers-reduced-motion:reduce){.gmain-img.gx-in{transition:none}}');

    preg_match_all('/\.gx-in[^{]*\{([^}]*)\}/', $product, $m);
    foreach ($m[1] as $rule) {
        expect($rule)->not->toMatch('/width|height|margin|padding|position|inset|transform|top|left/');
    }
});
