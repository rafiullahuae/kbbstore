<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\HomepageContent;
use App\Services\PageBanners;
use App\Services\SettingsService;
use App\Services\CartService;
use App\Support\TrustStrip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The trust strip (Lane TS). The owner picked H1 and F1 off
 * docs/trust-strip-options and said where each goes:
 *
 *   homepage      the white card under the banner           ktr-card
 *   /super-sale/  the same row, no box, under its header     ktr-bare
 *                 plus the thin delivery line at the top     .kts
 *   every other   above the footer's pink help strip         ktr-foot
 *   page
 *   NEVER         the footer strip on home, /super-sale/, the cart and the
 *                 checkout ("will not show on the mentioned pages at all")
 *
 * and "remove the previous strip from the super sale page which is below
 * header" — the Page banners strip, switched off there by migration
 * 2027_10_17_100000.
 */

function tsSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

/** A product with a brand and a category, and one published article. */
function tsCatalogue(): array
{
    $brand = Brand::firstOrCreate(['slug' => 'ts-anua'], ['name' => 'TS Anua']);
    $cat = Category::firstOrCreate(['slug' => 'ts-toners'], ['name' => 'TS Toners', 'path' => 'ts-toners', 'depth' => 0]);
    $product = Product::create([
        'slug' => 'ts-heartleaf-toner', 'name' => 'Heartleaf 77% Toner', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
        'category_id' => $cat->id,
    ]);
    $product->categories()->syncWithoutDetaching([$cat->id]);
    Post::create(['slug' => 'ts-article', 'title' => 'Double cleansing', 'body' => '<p>Body.</p>', 'excerpt' => 'Ex.', 'status' => 'published', 'published_at' => now()->subDay()]);

    return ['product' => $product, 'category' => $cat, 'brand' => $brand];
}

/** Count the strips of each shape on a page. */
function tsShapes(string $html): array
{
    preg_match_all('#<section class="ktr ktr-(card|bare|foot)[^"]*" aria-label="Why shop with us">#', $html, $m);

    return array_count_values($m[1]);
}

function tsBasketGet(string $path, Cart $cart): string
{
    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)
        ->assertOk()
        ->getContent();
}

function tsCart(Product $p): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 9900]);

    return $cart;
}

beforeEach(function () {
    Setting::flushMap();
    SettingsService::forgetMemo();
});

it('puts the card on the homepage, the bare row on Super Sale and the footer row on every other page', function () {
    /*
     * DEFECT THIS CATCHES: a strip on the wrong page or twice on one — the
     * footer row on the homepage under the card he asked for, the card on a
     * product page, a page that includes the partial twice. MUTATION: drop
     * @section('no-trust-strip') from store/home.blade.php -> home has a foot
     * row and is red; remove the layout's @include -> every "foot => 1" is.
     */
    $c = tsCatalogue();

    expect(tsShapes($this->get('/')->assertOk()->getContent()))->toBe(['card' => 1])
        ->and(tsShapes($this->get('/super-sale')->assertOk()->getContent()))->toBe(['bare' => 1]);

    foreach (['/product/'.$c['product']->slug, '/collections/'.$c['category']->slug, '/brands/'.$c['brand']->slug, '/blog/', '/shop', '/new-in'] as $path) {
        $res = $this->get($path);
        expect($res->status())->toBe(200, $path);
        $html = $res->getContent();

        expect(tsShapes($html))->toBe(['foot' => 1], $path)
            // Directly above the footer, nothing between them but indentation.
            ->and(preg_match('#</ul></section>\n\s*<footer[\s>]#', $html))->toBe(1, $path);
    }
});

it('never draws the footer row on the cart, even with the footer on, or the checkout', function () {
    /*
     * DEFECT THIS CATCHES: the exclusion done by CSS, or by the cart's
     * "no footer" switch alone. Appearance → Cart page → "Show the site footer
     * on the cart page" ON brings the footer back, and the strip must still be
     * absent from the HTML. MUTATION: drop @section('no-trust-strip') from
     * store/cart.blade.php -> the second cart assertion is red.
     */
    $c = tsCatalogue();
    $cart = tsCart($c['product']);

    expect(tsBasketGet('/cart', $cart))->not->toContain('aria-label="Why shop with us"');

    tsSet('cartpage_footer_on', '1');
    $withFooter = tsBasketGet('/cart', $cart);

    expect(preg_match('#<footer[\s>]#', $withFooter))->toBe(1)
        ->and($withFooter)->not->toContain('aria-label="Why shop with us"')
        ->and(tsBasketGet('/checkout', $cart))->not->toContain('aria-label="Why shop with us"');
});

it('puts the thin delivery line at the very top of Super Sale and the bare row under its header', function () {
    /*
     * DEFECT THIS CATCHES: the thin line missing, below the header instead of
     * above it, or the row above the header. MUTATION: swap the two includes
     * in store/collection.blade.php -> the order assertions are red.
     */
    tsCatalogue();
    $html = $this->get('/super-sale')->assertOk()->getContent();
    $line = strpos($html, '<div class="kts"');
    $row = strpos($html, '<section class="ktr ktr-bare');

    expect($line)->not->toBeFalse()
        ->and(substr_count($html, 'class="kts'))->toBe(1)
        ->and($html)->toContain('<span>1-3 Days Delivery all over UAE')
        ->and($line < strpos($html, '<div class="kbb-home">') ? 'before content' : 'in content')->toBe('in content')
        ->and($line < $row)->toBeTrue();

    // With a configured header (Pages → Page header), the row sits after it.
    if (str_contains($html, 'class="kbb-pt"')) {
        expect(strpos($html, 'class="kbb-pt"') < $row)->toBeTrue();
    }

    // His own wording, tags stripped and escaped; the Arabic box is separate.
    tsSet('ts_sale_line_en', '<b>Free</b> delivery & more');
    $own = $this->get('/super-sale')->assertOk()->getContent();
    expect($own)->toContain('<span>Free delivery &amp; more</span>')
        ->and($own)->not->toContain('<b>Free</b>');

    // Off is off: not hidden, absent.
    tsSet('ts_sale_line', 'off');
    tsSet('ts_sale', 'off');
    $off = $this->get('/super-sale')->assertOk()->getContent();
    expect($off)->not->toContain('class="kts')->and(tsShapes($off))->toBe([]);

    // No other listing gains either: /new-in keeps the footer row only.
    $newIn = $this->get('/new-in')->assertOk()->getContent();
    expect($newIn)->not->toContain('class="kts')->and(tsShapes($newIn))->toBe(['foot' => 1]);
});

it('switches the old Page banners strip off on Super Sale and keeps the banner and every other page', function () {
    /*
     * DEFECT THIS CATCHES: "remove the previous strip from the super sale page
     * which is below header" left undone on a shop that had switched it on, or
     * done by deleting the banner (the feature) or another page's
     * assignment. MUTATION: make saleStripOff() return early -> the strip is
     * still on /super-sale/ and the first assertion after up() is red.
     */
    tsCatalogue();
    $banners = PageBanners::defaults()['banners'];
    tsSet(PageBanners::KEY, ['banners' => $banners, 'assign' => ['collection:super-sale' => 'super-sale', 'collection:new-in' => 'super-sale']]);
    expect($this->get('/super-sale')->assertOk()->getContent())->toContain('class="kbb-pb-strip"');

    $migration = require base_path('database/migrations/2027_10_17_100000_clear_caches_trust_strips.php');
    ob_start();
    $migration->up();
    ob_end_clean();
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetInstance(PageBanners::class);

    $stored = app(SettingsService::class)->get(PageBanners::KEY);
    expect($this->get('/super-sale')->assertOk()->getContent())->not->toContain('class="kbb-pb-strip"')
        ->and($stored['assign'])->toBe(['collection:new-in' => 'super-sale'])
        ->and(array_column($stored['banners'], 'id'))->toBe(['super-sale']);
});

it('adds no query to any page it is drawn on', function () {
    /*
     * DEFECT THIS CATCHES: a strip that reads its switches through get() (a
     * miss on an unsaved key takes the non-autoload snapshot), loads a model,
     * or counts something. Rule 4 and "Speed is frozen": the same page with
     * every placement off and every placement on runs the same queries.
     * MUTATION: read a switch with app(SettingsService::class)->get('ts_x')
     * on a page where no other code has taken the snapshot -> red.
     */
    $c = tsCatalogue();
    $pages = ['/', '/super-sale', '/product/'.$c['product']->slug, '/collections/'.$c['category']->slug, '/brands/'.$c['brand']->slug];
    $count = function (string $path): int {
        Setting::flushMap();
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->get($path)->assertOk();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };

    foreach (['ts_home', 'ts_foot', 'ts_sale', 'ts_sale_line'] as $k) {
        tsSet($k, 'off');
    }
    foreach ($pages as $p) {
        $count($p);
    }
    $off = array_map($count, $pages);

    foreach (['ts_home', 'ts_foot', 'ts_sale', 'ts_sale_line'] as $k) {
        tsSet($k, 'both');
    }
    foreach ($pages as $p) {
        $count($p);
    }
    $on = array_map($count, $pages);

    expect($on)->toBe($off);
});

it('answers only its own options, and puts its controls on one Homepage content tab', function () {
    /*
     * DEFECT THIS CATCHES: a stored value the select never offered reaching
     * the page (rule 5), or a control with no screen. MUTATION: return $v
     * unchecked from place() -> 'javascript:' answers null/odd and is red.
     */
    expect(TrustStrip::place([], 'ts_foot'))->toBe('')
        ->and(TrustStrip::place(['ts_foot' => 'phone'], 'ts_foot'))->toBe('d-off')
        ->and(TrustStrip::place(['ts_foot' => 'laptop'], 'ts_foot'))->toBe('m-off')
        ->and(TrustStrip::place(['ts_foot' => 'off'], 'ts_foot'))->toBeNull()
        ->and(TrustStrip::place(['ts_foot' => 'javascript:alert(1)'], 'ts_foot'))->toBe('')
        ->and(TrustStrip::place(['ts_foot' => ['both']], 'ts_foot'))->toBe('');

    foreach (array_keys(TrustStrip::SCHEMA) as $key) {
        expect(HomepageContent::SCHEMA)->toHaveKey($key)
            ->and(HomepageContent::TABS['truststrip'][2])->toContain($key);
    }

    // Every default is ON, because he asked for every placement.
    foreach (['ts_home', 'ts_foot', 'ts_sale', 'ts_sale_line'] as $key) {
        expect(TrustStrip::SCHEMA[$key]['default'])->toBe('both');
    }
});

it('draws the homepage card on one device when asked, and nowhere when off', function () {
    /*
     * DEFECT THIS CATCHES: the per-device choice ignored, or "off" printing a
     * hidden copy. MUTATION: drop the class from the home include -> the
     * d-off assertion is red.
     */
    tsCatalogue();
    tsSet('ts_home', 'phone');
    // Default section order: no ordering class at all (HomepageSectionOrderTest's
    // "off means off"); a moved homepage adds the banner's own kbb-ord-N.
    expect($this->get('/')->assertOk()->getContent())->toContain('<section class="ktr ktr-card d-off" aria-label="Why shop with us">');

    tsSet('ts_home', 'off');
    expect(tsShapes($this->get('/')->assertOk()->getContent()))->toBe([]);
});

/*
 * 2.60.458. The homepage card shrank to the width of its words on the live
 * shop (318px of a 390px phone, the four items crushed together) because the
 * owner's homepage uses a custom section order, and HomepageSections::
 * orderStyle() turns .kbb-home into a flex column: in a flex column the
 * strip's `margin:0 auto` shrinks it to its content. The test shop kept the
 * default order, a plain block, so every shot looked right. Measured in
 * Chromium with the order style on: 318 -> 390 px at 390, 657 -> 1280 at 1280.
 * Take `width:100%` out of the rule and this is red.
 */
it('keeps the strip full width when the homepage sections are reordered', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect(preg_match('/^\.ktr\{([^}]*)\}/m', $css, $m))->toBe(1)
        ->and($m[1])->toContain('width:100%')
        ->and($m[1])->toContain('box-sizing:border-box')
        ->and($m[1])->toContain('margin:0 auto');
});
