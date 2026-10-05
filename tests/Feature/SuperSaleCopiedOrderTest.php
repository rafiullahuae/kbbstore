<?php

declare(strict_types=1);

/**
 * /super-sale/ IN THE OLD SITE'S EXACT ORDER, COPIED BY THE SERVER. (2.60.388)
 *
 * THE OWNER, 5 October: "want the same squence and sorting of products as in
 * original site https://kbeautybliss.com/super-sale/ ... i want this to be done
 * by u, bcz manual sorting will take time ... don't disturb anything else."
 *
 * WHAT THE SHOP DID. /super-sale/ sorted on products.position (Catalog →
 * Reorder), which did not reproduce the old page, and that number is shared by
 * every category, so fixing it there would reorder the rest of the shop.
 *
 * NOW: Pages → Page banners → Super Sale products → "Copy the order from
 * kbeautybliss.com/super-sale/" (or `php artisan kbb:super-sale-order`). The
 * server reads the old page and its /page/N/ pages, matches the product links
 * to this shop's products, and stores the order as its own list. Only
 * /super-sale/ reads it.
 *
 * MUTATIONS, each red here:
 *   · the CASE in SuperSale::apply removed            → "draws the copied order"
 *   · CollectionController stops passing ids()         → "draws the copied order"
 *   · slugsFromHtml reads the whole page, not the grid → "reads only the grid"
 *   · withoutRedirecting() removed                    → "never follows a redirect"
 *   · store() writes products.position                → "touches no other order"
 */

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\SuperSaleOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function scoProduct(string $slug, int $position, Category ...$in): Product
{
    $p = Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)),
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 10000, 'sale_price' => 8000, 'position' => $position, 'stock_status' => 'instock',
    ]);
    $p->categories()->syncWithoutDetaching(array_map(fn (Category $c) => $c->id, $in));

    return $p;
}

function scoOrder(string $html, array $only): array
{
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

/** A WooCommerce-shaped listing page: header link, the grid, a footer link. */
function scoPage(array $slugs): string
{
    $li = implode('', array_map(fn ($s) => '<li class="product"><a href="https://kbeautybliss.com/product/'.$s.'/"><img></a>'
        .'<h2><a href="https://kbeautybliss.com/product/'.$s.'/">'.$s.'</a></h2><a href="?add-to-cart=1">Add</a></li>', $slugs));

    return '<html><header><a href="https://kbeautybliss.com/product/sco-header-pick/">Hot</a></header>'
        .'<ul class="products columns-4">'.$li.'</ul>'
        .'<footer><a href="https://kbeautybliss.com/product/sco-footer-pick/">x</a></footer></html>';
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('reads only the product grid, in order, once each', function () {
    expect(SuperSaleOrder::slugsFromHtml(scoPage(['sco-b', 'sco-a', 'sco-b'])))->toBe(['sco-b', 'sco-a']);

    // Another host's /product/ link is never taken.
    expect(SuperSaleOrder::slugsFromHtml('<ul class="products"><li><a href="https://evil.example/product/x/">x</a></li></ul>'))->toBe([]);
});

it('walks /page/N/ until a page adds nothing, and fetches only the fixed address', function () {
    Http::fake([
        'kbeautybliss.com/super-sale/page/2/' => Http::response(scoPage(['sco-c'])),
        'kbeautybliss.com/super-sale/page/3/' => Http::response('Not found', 404),
        'kbeautybliss.com/super-sale/' => Http::response(scoPage(['sco-a', 'sco-b'])),
    ]);

    $got = SuperSaleOrder::fetchSlugs();

    expect($got['slugs'])->toBe(['sco-a', 'sco-b', 'sco-c'])->and($got['pages'])->toBe(2);
    Http::assertSentCount(3);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), SuperSaleOrder::SOURCE));
});

it('never follows a redirect off the old page', function () {
    Http::fake(['*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/'])]);

    $got = SuperSaleOrder::fetchSlugs();

    expect($got['slugs'])->toBe([])->and($got['error'])->toContain('301');
    Http::assertSentCount(1);
});

it('draws the copied order on /super-sale/ and the rest after it in the Reorder order', function () {
    $sale = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
    foreach (['sco-a' => 0, 'sco-b' => 1, 'sco-c' => 2, 'sco-d' => 3] as $slug => $pos) {
        scoProduct($slug, $pos, $sale);
    }
    $all = ['sco-a', 'sco-b', 'sco-c', 'sco-d'];

    expect(scoOrder((string) $this->get('/super-sale/')->getContent(), $all))->toBe($all);

    $stored = SuperSaleOrder::store(app(SettingsService::class), ['sco-c', 'sco-a', 'sco-gone']);
    expect($stored)->toBe(['count' => 2, 'missing' => ['sco-gone']]);

    SettingsService::forgetMemo();
    expect(scoOrder((string) $this->get('/super-sale/')->getContent(), $all))->toBe(['sco-c', 'sco-a', 'sco-b', 'sco-d']);

    // Forgotten: the Reorder order again.
    SuperSaleOrder::clear(app(SettingsService::class));
    SettingsService::forgetMemo();
    expect(scoOrder((string) $this->get('/super-sale/')->getContent(), $all))->toBe($all);
});

it('touches no other order: positions and every other category stay as they were', function () {
    $sale = Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
    $toners = Category::create(['name' => 'Toners', 'slug' => 'sco-toners', 'path' => 'sco-toners']);
    scoProduct('sco-a', 0, $sale, $toners);
    scoProduct('sco-b', 1, $sale, $toners);

    $before = DB::table('products')->orderBy('id')->pluck('position', 'slug')->all();
    SuperSaleOrder::store(app(SettingsService::class), ['sco-b', 'sco-a']);

    expect(DB::table('products')->orderBy('id')->pluck('position', 'slug')->all())->toBe($before)
        ->and(scoOrder((string) $this->get('/collections/sco-toners/')->getContent(), ['sco-a', 'sco-b']))->toBe(['sco-a', 'sco-b']);
});

it('copies from the admin in one click, for the Page banners capability only', function () {
    Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
    Http::fake(['kbeautybliss.com/super-sale/page/*' => Http::response('', 404), 'kbeautybliss.com/super-sale/' => Http::response(scoPage(['sco-x']))]);
    scoProduct('sco-x', 0);

    $this->actingAs(AdminUser::create(['name' => 'S', 'email' => 's-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'support']), 'admin');
    $this->postJson('/admin-api/page-banners/super-sale-order', ['action' => 'copy'])->assertStatus(403);
    expect(DB::table('settings')->where('key', SuperSaleOrder::KEY)->count())->toBe(0);

    $this->actingAs(AdminUser::create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');
    $this->postJson('/admin-api/page-banners/super-sale-order', ['action' => 'drop tables'])->assertStatus(422);
    $body = $this->postJson('/admin-api/page-banners/super-sale-order', ['action' => 'copy'])->assertOk()->json();

    expect($body['copied'])->toBe(1)->and($body['super_sale']['copied']['count'])->toBe(1);
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/page-banners/super-sale-order'))->toBe('pagebanners.manage');
});
