<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Post;
use App\Models\Product;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Services\SiteLayout;

/**
 * Lane LZ -- the pictures a shopper sees first were loading="lazy".
 *
 * THE OWNER: "the page loads instantly, but the images keep showing time to
 * time. and giving bad experience." Measured in Chromium on a live-scale
 * preview, cold cache, Fast 4G at 150 ms: on a category at 390 the first card
 * (eager) was asked for at 207 ms and the card beside it (lazy) at 855 ms; at
 * 1280 the other four of the first row at 566 ms against 166. A lazy picture
 * waits for the stylesheet and for layout before it is even requested, so the
 * row filled in one picture at a time -- on every listing, and on the homepage
 * under the banner, where 3 of the 4 pictures on a phone's first screen were
 * lazy. On the live homepage, 9 of the 10 in the first viewport were.
 *
 * WHAT IS PINNED: the first ROW of each page's first grid is not lazy (its
 * first card alone keeps fetchpriority="high"); everything after it is still
 * lazy; the count follows Appearance → Layout's grid numbers; and no stylesheet
 * hides a picture that has arrived behind an opacity or reveal animation.
 *
 * MUTATION NOTES, each run:
 *   · `@elseif ($above) loading="eager"` removed from product-card → the three
 *     listing cases and the homepage case are red.
 *   · aboveFoldCards() returning 1 → the count case and the pinned page are red.
 *   · an empty skip list in HomepageSections::firstOnScreen() → both homepage
 *     cases are red (the banner would be "the first section").
 *   · `$loop->index < 3` back to `$loop->first` in store/blog → the blog case is red.
 *   · `.kbb-card-img{opacity:0;transition:opacity .3s}` added to kbb.css → the
 *     animation case is red.
 */
function lzCards(string $uri): array
{
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    app()->forgetScopedInstances();
    $html = (string) test()->get($uri)->assertOk()->getContent();
    preg_match_all('/<img class="kbb-card-img"[^>]*>/', $html, $m);

    return $m[0];
}

/** The first $n not lazy (the first one alone at high priority), the rest lazy. */
function lzExpectFirstRow(array $imgs, int $n, string $where): void
{
    expect(count($imgs))->toBeGreaterThan($n, $where.': too few cards to tell the first row from the rest');
    foreach ($imgs as $i => $img) {
        $card = $where.' card '.($i + 1).': ';
        if ($i < $n) {
            expect(str_contains($img, 'loading="eager"'))->toBeTrue($card.'in the first row and lazy');
            expect(str_contains($img, 'fetchpriority="high"'))->toBe($i === 0, $card.'only the first card claims priority');
        } else {
            expect(str_contains($img, 'loading="lazy"'))->toBeTrue($card.'below the first row and not lazy');
        }
    }
}

beforeEach(function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $brand = Brand::firstOrCreate(['slug' => 'lz-house'], ['name' => 'Lz House']);
    foreach (range(1, 14) as $i) {
        Product::create([
            'slug' => 'lz-'.$i, 'name' => 'Lz Serum '.$i, 'status' => 'publish', 'is_visible' => true,
            'brand_id' => $brand->id, 'price' => 6000, 'sale_price' => 3000, 'stock_status' => 'instock',
            'type' => 'simple', 'rating' => 0.0, 'review_count' => 0, 'image' => '/uploads/lz/'.$i.'.webp',
        ]);
    }
    // Every product photographed, so the n-th <img> is the n-th card.
    Product::query()->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))->update(['image' => '/uploads/lz/demo.webp']);
});

it('works the count out from the grid settings, the same arithmetic as the CSS track', function () {
    $layout = app(SiteLayout::class);
    // Shipped: 1680 site, 22px gutters, 220px tile, 16px gap -> (1636 + 16) / 236 = 7.
    expect($layout->aboveFoldCards())->toBe(7);

    $set = function (array $v) use ($layout) {
        $layout->save($v);
        \App\Models\Setting::flushMap();
        SettingsService::forgetMemo();
    };
    $set(['tile' => 300, 'tile_shop' => 300]);      // (1636 + 16) / 316 = 5
    expect($layout->aboveFoldCards())->toBe(5);
    $set(['max' => 1040]);                           // (996 + 16) / 316 = 3, but two phone rows = 4
    expect($layout->aboveFoldCards())->toBe(4);
    $set(['cols_floor' => 3]);                       // three to a phone row: 6
    expect($layout->aboveFoldCards())->toBe(6);
    $set(['cols_floor' => 2, 'pin' => '5']);         // an exact count wins
    expect($layout->aboveFoldCards())->toBe(5);
    $set(['pin' => 'auto', 'max' => 2400, 'tile' => 120, 'tile_shop' => 120, 'cols_cap' => 6]);
    expect($layout->aboveFoldCards())->toBe(6);      // never past the cap
});

it('keeps the first row of /shop, a curated collection and a brand page out of lazy loading', function (string $uri) {
    lzExpectFirstRow(lzCards($uri), app(SiteLayout::class)->aboveFoldCards(), $uri);
})->with(['/shop/', '/super-sale/', '/brands/lz-house/']);

it('follows the grid setting on the page: pin four columns and four are eager', function () {
    app(SiteLayout::class)->save(['pin' => '4']);
    lzExpectFirstRow(lzCards('/brands/lz-house/'), 4, 'pinned at 4');
});

it('keeps the first section under the banner out of lazy loading on the homepage, and every other section lazy', function () {
    $first = app(HomepageSections::class)->firstOnScreen();
    expect($first)->toBe(['bundles']);

    $html = (string) $this->get('/')->assertOk()->getContent();
    preg_match('/<div class="kbb-pgrid bndl-track".*?<\/section>/s', $html, $b);
    expect($b)->not->toBeEmpty('the bundles row did not render');
    preg_match_all('/<img class="kbb-card-img"[^>]*>/', $b[0], $m);
    $n = \App\Support\HomeBundles::config()['above'];   // 4 a view on a laptop, 2.3 on a phone
    expect($n)->toBe(4);
    foreach ($m[0] as $i => $img) {
        expect($img)->toContain($i < $n ? 'loading="eager"' : 'loading="lazy"')->and($img)->not->toContain('fetchpriority');
    }

    // every card outside that row is lazy
    $rest = str_replace($b[0], '', $html);
    preg_match_all('/<img class="kbb-card-img"[^>]*>/', $rest, $r);
    foreach ($r[0] as $img) {
        expect($img)->toContain('loading="lazy"');
    }
});

it('moves with the owner\'s order: the first section on screen is the eager one', function () {
    $all = app(HomepageSections::class)->all();
    $payload = [];
    $i = 0;
    foreach (array_keys($all) as $key) {
        $payload[$key] = ['desktop' => $all[$key]['desktop'], 'mobile' => $all[$key]['mobile'], 'skin' => $all[$key]['skin'], 'order' => $i++];
    }
    $payload['bestselling']['order'] = -1;     // Best Sellers to the top
    $payload['bundles']['mobile'] = false;     // and bundles off on a phone
    app(SettingsService::class)->set('homepage_sections', $payload);
    SettingsService::forgetMemo();

    expect(app(HomepageSections::class)->firstOnScreen())->toBe(['bestselling']);

    $payload['bestselling']['order'] = 99;
    $payload['bestselling']['mobile'] = true;
    app(SettingsService::class)->set('homepage_sections', $payload);
    SettingsService::forgetMemo();
    // bundles first on a laptop; on a phone (bundles off) the next section on.
    $first = app(HomepageSections::class)->firstOnScreen();
    expect($first[0])->toBe('bundles')->and(count($first))->toBe(2)->and($first[1])->not->toBe('bundles');
});

it('loads the Journal\'s first row of three eagerly and the rest lazily', function () {
    foreach (range(1, 5) as $i) {
        Post::create(['slug' => 'lz-post-'.$i, 'title' => 'Lz post '.$i, 'body' => 'Words.', 'status' => 'published',
            'cover' => '/uploads/lz/post-'.$i.'.webp', 'published_at' => now()->subMinutes($i)]);
    }
    $html = (string) $this->get('/blog/')->assertOk()->getContent();
    preg_match_all('/<div class="cover"[^>]*>\s*(?:\{\{--.*?--\}\}\s*)?<img[^>]*>/s', $html, $m);
    expect(count($m[0]))->toBeGreaterThan(3);
    foreach ($m[0] as $i => $img) {
        expect($img)->toContain($i < 3 ? 'loading="eager"' : 'loading="lazy"');
    }
});

it('hides no shop picture behind an opacity or reveal animation once it has arrived', function () {
    /*
     * Looked for, and not found on 2.60.443: the card and gallery shimmer is a
     * BACKGROUND behind the picture's own pixels (kbb.css .kbb-card-img,
     * kbb-product.css .gmain-img), so a photograph covers it the frame it
     * decodes. The only image fade, `.gmain-img.gx-in`, is on the thumbnail
     * TAP path (pdp.js adds it after the first paint). This keeps it that way:
     * no rule may start a shop picture at opacity 0 or animate it in.
     */
    $sheets = ['kbb.css', 'kbb-shop.css', 'kbb-product.css', 'kbb-grid-skins.css', 'kbb-brand-header.css', 'kbb-banner.css', 'kbb-title-header.css'];
    $bad = [];
    foreach ($sheets as $f) {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/'.$f)));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $sel, $body]) {
            $sel = trim($sel);
            // a rule about a picture itself, not one reached only on hover/focus
            if (! preg_match('/(\bimg\b|-img\b|\.cover\b)[^,]*$/', $sel) || preg_match('/:hover|:focus|gx-in/', $sel)) {
                continue;
            }
            if (preg_match('/(^|;)\s*opacity\s*:\s*0(\.0*)?\s*(;|$)/', $body) || preg_match('/(^|;)\s*animation[^:]*:[^;]*(fade|reveal|appear)/i', $body)) {
                $bad[] = $f.': '.$sel.'{'.$body.'}';
            }
        }
    }
    expect($bad)->toBe([]);
});
