<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogProductsAdminRoutes;

/**
 * Catalog → Products.
 *
 * The screen this replaces could list products and nothing else. Its Edit
 * button's entire implementation was a toast saying editing was not built yet,
 * and the two things a shop owner does on this screen all day — change a price,
 * change a stock number — were the two things it could not do.
 *
 * Five things are pinned hardest here, because each is a way to be wrong
 * quietly:
 *
 *   THE GUARD. These endpoints rewrite the shop's own prices, unpublish its
 *   catalogue and hand every SKU over as a file. /api/* in this app is
 *   unauthenticated by design, so being on the wrong side of that line is an
 *   anonymous write primitive over the store's money. Every route is asserted
 *   against an anonymous caller AND a signed-in non-admin, and the middleware
 *   is read back off the REGISTERED routes rather than trusted from the
 *   harness — RouteRegistrar::middleware() REPLACES rather than appends, so a
 *   harness that chains it twice guards nothing while reading as though it did.
 *
 *   THE MONEY. Seeded to exact fils and asserted to exact fils, through every
 *   path that writes one: an inline edit typing "1.15", a percentage applied in
 *   bulk, a flat amount, and the inventory total across the filtered set. The
 *   float spelling of a 30% cut is 0.69999999999999995559 and lands a fil
 *   light; nothing here is allowed to.
 *
 *   THE QUERY COUNT. The list is a fixed number of statements whatever the
 *   catalogue size, asserted as an EQUALITY at two page sizes rather than as a
 *   threshold. A threshold passes for an N+1 that is merely small.
 *
 *   THE VOCABULARY. `products.status` is publish | draft | private, read out of
 *   the schema and out of ProductImporter::STATUS_MAP rather than guessed. The
 *   chips are still built from a DISTINCT over the column on top of that,
 *   because the dead importer this repo replaced wrote 'active' — a value the
 *   schema has no concept of — and a row carrying one must not be invisible.
 *
 *   THE IMPORT. Products with a wc_id, no image, no category, no brand, no SKU,
 *   a null price and an unplanned status. That is what the table really looks
 *   like after the Woo import, and none of it may make a figure wrong or a page
 *   throw.
 */

/* ------------------------------------------------------------------ fixtures */

function cpBrand(string $name = 'CP Brand'): Brand
{
    static $n = 0;
    $n++;

    return Brand::create(['name' => $name, 'slug' => 'cp-brand-'.$n.'-'.uniqid()]);
}

function cpCategory(string $name = 'CP Category'): Category
{
    static $n = 0;
    $n++;

    return Category::create(['name' => $name, 'slug' => 'cp-cat-'.$n.'-'.uniqid()]);
}

/** A product at an exact number of fils. Nothing here rounds. */
function cpProduct(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    $categories = $attributes['categories'] ?? null;
    unset($attributes['categories']);

    $product = Product::create(array_merge([
        'name' => 'CP Product '.$n,
        /*
         * DIGIT-FREE, deliberately.
         *
         * This was 'cp-product-'.$n.'-'.uniqid(). uniqid() is a hex timestamp,
         * so every product built in the same test shares a long prefix -- and
         * the search endpoint LIKEs products.slug. The moment that prefix
         * happened to contain the digits of a row id or a wc_id, a search for
         * one product matched all of them and the search test failed. It is
         * clock-dependent, so it passed twelve runs in a row here and still
         * failed elsewhere; forcing a real-shaped uniqid of 6aa925d2a18422f
         * reproduces it every time, because it contains 18422.
         *
         * The controller was never wrong. $n is a process-wide counter and
         * RefreshDatabase gives each test a clean table, so $n alone is unique
         * -- but spelled in digits it can still collide with an id being
         * searched for. Mapping the digits onto letters keeps it bijective,
         * so it stays unique, and puts no digit in the slug at all.
         */
        'slug' => 'cp-product-'.strtr((string) $n, '0123456789', 'abcdefghij'),
        'sku' => 'CP-SKU-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'image' => 'https://cdn.test/p'.$n.'.jpg',
    ], $attributes));

    if (is_array($categories)) {
        $product->categories()->sync(array_map(fn ($c) => $c instanceof Category ? $c->id : (int) $c, $categories));
    }

    return $product;
}

function cpAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'CP Owner',
        'email' => 'cp-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * Empty the catalogue before a test that counts things.
 *
 * 2026_08_27_100000_seed_demo_catalogue.php puts 24 real products in the
 * database, and RefreshDatabase runs the whole migration set — so a chip count
 * asserted against a fixture of three products is really being asserted against
 * twenty-seven. Every count in this file would then be a number nobody chose,
 * and the day somebody edits the demo seed every one of them goes red for a
 * reason that has nothing to do with this screen.
 *
 * Deleted rather than worked around: the deletes run inside RefreshDatabase's
 * transaction, so the seeded catalogue is back for the next test.
 */
function cpResetCatalogue(): void
{
    DB::table('category_product')->delete();
    DB::table('order_items')->delete();
    DB::table('orders')->delete();
    DB::table('products')->delete();
}

/** Wire the route file and sign in as an admin — the normal case. */
function asCatalogAdmin(): void
{
    CatalogProductsAdminRoutes::wire(app());
    cpResetCatalogue();
    test()->actingAs(cpAdmin(), 'admin');
}

/** An order that counts as revenue, with one line against a product. */
function cpSale(Product $product, int $quantity = 1, int $total = 10000, string $status = 'completed'): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'CP-ORD-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => 'cp-buyer-'.$n.'@example.test',
        'status' => $status,
        'currency' => 'AED',
        'subtotal' => $total,
        'total' => $total,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $quantity > 0 ? intdiv($total, $quantity) : $total,
        'subtotal' => $total,
        'total' => $total,
    ]);

    return $order;
}

/** @return array<int, array<string, mixed>> keyed by product id */
function cpRows(string $query = ''): array
{
    $body = test()->getJson('/admin-api/catalog-products-list'.($query === '' ? '' : '?'.$query))
        ->assertOk()
        ->json();

    $out = [];

    foreach ($body['products'] as $row) {
        $out[$row['id']] = $row;
    }

    return $out;
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on every route the products screen adds', function () {
    CatalogProductsAdminRoutes::wire(app());

    $product = cpProduct(['name' => 'CP SECRET PRODUCT', 'sku' => 'CP-SECRET-SKU', 'price' => 19900]);
    $category = cpCategory();

    $refusals = [
        ['get', '/admin-api/catalog-products-list'],
        ['get', '/admin-api/catalog-products-facets'],
        ['get', '/admin-api/catalog-products-export'],
        ['get', '/admin-api/catalog-products-detail/'.$product->id],
        ['post', '/admin-api/catalog-products-save/'.$product->id],
        ['post', '/admin-api/catalog-products-bulk-status'],
        ['post', '/admin-api/catalog-products-bulk-category'],
        ['post', '/admin-api/catalog-products-bulk-price'],
    ];

    foreach ($refusals as [$method, $uri]) {
        $response = $method === 'get'
            ? test()->getJson($uri)
            : test()->postJson($uri, [
                'ids' => [$product->id],
                'status' => 'draft',
                'category_ids' => [$category->id],
                'mode' => 'percent',
                'percent' => '-50',
                'target' => 'price',
                'price' => '1.00',
                'confirm' => true,
                'force' => true,
            ]);

        expect($response->getStatusCode())->toBe(401, $method.' '.$uri.' was not refused');

        // Nothing leaked on the way to the refusal.
        expect($response->getContent())->not->toContain('CP SECRET PRODUCT')
            ->and($response->getContent())->not->toContain('CP-SECRET-SKU');
    }

    // And nothing was written by the anonymous caller either.
    $product->refresh();

    expect($product->status)->toBe('publish')
        ->and((int) $product->price)->toBe(19900);
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    CatalogProductsAdminRoutes::wire(app());

    $product = cpProduct(['price' => 25000]);

    // A shopper signed into the storefront. The `customer` guard is deliberately
    // separate from `admin` (config/auth.php says so in as many words); this is
    // the assertion that the separation is real and not merely documented.
    $shopper = Customer::create([
        'name' => 'CP Shopper',
        'email' => 'cp-shopper-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');

    expect(test()->getJson('/admin-api/catalog-products-list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/catalog-products-export')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '1.00'])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-products-bulk-status', ['ids' => [$product->id], 'status' => 'draft'])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-products-bulk-price', [
            'ids' => [$product->id], 'target' => 'price', 'mode' => 'percent', 'percent' => '-90', 'confirm' => true,
        ])->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'CP Web User',
        'email' => 'cp-webuser-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->getJson('/admin-api/catalog-products-list')->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '1.00'])->getStatusCode())->toBe(401);

    // Not one of those calls changed the price.
    expect((int) $product->fresh()->price)->toBe(25000);
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    CatalogProductsAdminRoutes::wire(app());

    $routes = CatalogProductsAdminRoutes::registered();

    // Eight routes, and the count is asserted so a ninth cannot be added
    // without this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(8);

    foreach ($routes as $route) {
        // The trap this exists for: RouteRegistrar::middleware() REPLACES the
        // pending middleware, so a harness that chains it twice registers
        // routes with no `auth:admin` at all while reading as though it did —
        // and every 401 assertion above would then be passing against nothing.
        expect($route->middleware())->toContain('auth:admin')
            ->and($route->middleware())->toContain('web');
    }
});

it('constrains both id routes to digits so nothing reaches a typed controller argument', function () {
    CatalogProductsAdminRoutes::wire(app());

    foreach (CatalogProductsAdminRoutes::registered() as $route) {
        if (! str_contains($route->uri(), '{id}')) {
            continue;
        }

        expect($route->wheres['id'] ?? '')->not->toBe('', $route->uri().' has an unconstrained {id}');
    }

    test()->actingAs(cpAdmin(), 'admin');

    // A 404 from the router, not a TypeError from the controller.
    expect(test()->getJson('/admin-api/catalog-products-detail/list')->getStatusCode())->toBe(404);
});

it('documents for the integrator exactly where the file must be mounted', function () {
    $header = (string) file_get_contents(base_path('routes/catalog-products-admin.php'));

    expect($header)->toContain('admin-api')
        ->and($header)->toContain('auth:admin')
        ->and($header)->toContain("require __DIR__.'/catalog-products-admin.php';")
        ->and($header)->toContain('/admin-api/catalog-products-list')
        ->and($header)->toContain('clear_caches_catalog_products')
        // And why the paths are flat rather than nested under /catalog/products.
        ->and($header)->toContain('/admin-api/products/{id}');
});

it('ships the clear-caches migration every route-adding package needs', function () {
    $path = database_path('migrations/2026_09_23_000000_clear_caches_catalog_products.php');

    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect($source)->toContain('bootstrap/cache/routes-*.php')
        ->toContain('framework/views/*.php')
        // Nine migrations in this repo were silent no-ops on MySQL because of
        // ->after() on a column that did not exist yet.
        ->and($source)->not->toContain('->after(');
});

it('leaves the routes another lane owns exactly where they are', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    // routes/web.php is not this lane's file. The screen the order-detail
    // product picker calls, and the toggle-featured endpoint, still exist.
    expect($web)->toContain("Route::get('/catalog/products'")
        ->and($web)->toContain('toggle-featured')
        ->and($web)->toContain("Route::put('/products/{id}'");

    // This lane added no route to web.php by hand. The integrator wires the
    // whole file in with one require, asserted separately below.
    expect($web)->not->toContain('catalog-products-list');
});

it('is wired into web.php, so the screen is not a set of 404s', function () {
    // The lane that built this screen was forbidden from editing routes/web.php,
    // so the require line is the integrator's to add and therefore the thing
    // most likely to be forgotten. Without it every button on the screen 404s
    // while the screen itself still loads, which looks like a data problem.
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect($web)->toContain("require __DIR__.'/catalog-products-admin.php';");

    // And it landed inside the guarded group, not after it. Read off the
    // registered routes rather than the file: RouteRegistrar::middleware()
    // replaces rather than appends, so only the router knows the truth.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/catalog-products'));

    expect($routes)->toHaveCount(8);

    $routes->each(function ($route) {
        expect($route->gatherMiddleware())->toContain('auth:admin')
            ->and($route->gatherMiddleware())->toContain(\App\Http\Middleware\NoStoreAdminApi::class);
    });
});

/* ------------------------------------------------------------- the list body */

it('renders the list with prices, stock and sales to the exact fil', function () {
    asCatalogAdmin();

    $brand = cpBrand('Beauty of Joseon');
    $category = cpCategory('Cleansers');

    $product = cpProduct([
        'name' => 'Relief Sun',
        'sku' => 'BOJ-RS-50',
        'brand_id' => $brand->id,
        'price' => 6900,
        'sale_price' => 5175,
        'manage_stock' => true,
        'stock' => 7,
        'categories' => [$category],
    ]);

    cpSale($product, 2, 10350);
    cpSale($product, 1, 5175);

    $row = cpRows()[$product->id];

    expect($row['price_fils'])->toBe(6900)
        ->and($row['sale_price_fils'])->toBe(5175)
        ->and($row['effective_price_fils'])->toBe(5175)
        ->and($row['on_sale'])->toBeTrue()
        // (6900 - 5175) * 100 / 6900 = exactly 25, by integer division.
        ->and($row['discount_percent'])->toBe(25)
        ->and($row['stock'])->toBe(7)
        ->and($row['low_stock'])->toBeTrue()
        ->and($row['brand'])->toBe('Beauty of Joseon')
        ->and($row['sku'])->toBe('BOJ-RS-50')
        ->and($row['categories'])->toBe(['Cleansers'])
        ->and($row['category_count'])->toBe(1)
        ->and($row['orders_count'])->toBe(2)
        ->and($row['units_sold'])->toBe(3)
        ->and($row['revenue_fils'])->toBe(15525)
        ->and($row['has_image'])->toBeTrue();
});

it('counts as a sale only the order statuses the rest of the app counts', function () {
    asCatalogAdmin();

    $product = cpProduct();

    foreach (Order::REAL_STATUSES as $status) {
        cpSale($product, 1, 10000, $status);
    }

    foreach (['draft', 'pending', 'cancelled', 'refunded', 'failed'] as $status) {
        cpSale($product, 1, 99000, $status);
    }

    $row = cpRows()[$product->id];

    // Four revenue orders at 10000 fils each. The five others are orders, and
    // they are not sales.
    expect($row['orders_count'])->toBe(4)
        ->and($row['units_sold'])->toBe(4)
        ->and($row['revenue_fils'])->toBe(40000);
});

it('does not count a line from a trashed order', function () {
    asCatalogAdmin();

    $product = cpProduct();

    cpSale($product, 1, 10000);
    cpSale($product, 5, 50000)->delete();

    $row = cpRows()[$product->id];

    // `orders` soft-deletes, and a raw DB::table() join does not know that
    // unless it is told. A trashed order is not revenue anywhere else either.
    expect($row['orders_count'])->toBe(1)
        ->and($row['units_sold'])->toBe(1)
        ->and($row['revenue_fils'])->toBe(10000);
});

it('honours the sale window exactly as the storefront does', function () {
    asCatalogAdmin();

    $future = cpProduct(['price' => 10000, 'sale_price' => 5000, 'sale_starts_at' => now()->addDays(3)]);
    $past = cpProduct(['price' => 10000, 'sale_price' => 5000, 'sale_ends_at' => now()->subDay()]);
    $live = cpProduct(['price' => 10000, 'sale_price' => 5000, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()]);
    $openEnded = cpProduct(['price' => 10000, 'sale_price' => 7000]);

    $rows = cpRows();

    expect($rows[$future->id]['effective_price_fils'])->toBe(10000)
        ->and($rows[$future->id]['on_sale'])->toBeFalse()
        ->and($rows[$past->id]['effective_price_fils'])->toBe(10000)
        ->and($rows[$past->id]['on_sale'])->toBeFalse()
        ->and($rows[$live->id]['effective_price_fils'])->toBe(5000)
        ->and($rows[$live->id]['on_sale'])->toBeTrue()
        ->and($rows[$openEnded->id]['effective_price_fils'])->toBe(7000);

    // And the chip agrees with the row: the two out-of-window sales are not on
    // sale, so the count is the two that are.
    $counts = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('counts');

    expect($counts['on_sale'])->toBe(2);

    $onSale = cpRows('filter=on_sale');

    expect(array_keys($onSale))->toEqualCanonicalizing([$live->id, $openEnded->id]);
});

/* ---------------------------------------------------------------- the chips */

it('builds the status chips from the real vocabulary, plus whatever is in the column', function () {
    asCatalogAdmin();

    cpProduct(['status' => 'publish']);
    cpProduct(['status' => 'draft']);
    cpProduct(['status' => 'private']);

    // What the DEAD importer wrote before ProductImporter replaced it: a value
    // this schema has no concept of. A hard-coded chip list would hide it.
    DB::table('products')->insert([
        'name' => 'CP Legacy Row', 'slug' => 'cp-legacy-'.uniqid(),
        'status' => 'active', 'is_visible' => 1, 'type' => 'simple',
        'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $body = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json();

    // The three the schema declares are always offered, in that order, and the
    // stray one is appended rather than dropped.
    expect(array_slice($body['statuses'], 0, 3))->toBe(['publish', 'draft', 'private'])
        ->and($body['statuses'])->toContain('active')
        ->and($body['counts']['publish'])->toBe(1)
        ->and($body['counts']['draft'])->toBe(1)
        ->and($body['counts']['private'])->toBe(1)
        ->and($body['counts']['active'])->toBe(1)
        ->and($body['counts']['all'])->toBe(4);

    // And the chip actually filters to it.
    expect(cpRows('filter=active'))->toHaveCount(1);

    // A status nobody has is a chip at zero, not a missing chip.
    Product::query()->where('status', 'private')->delete();

    $counts = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('counts');

    expect($counts)->toHaveKey('private')->and($counts['private'])->toBe(0);
});

it('offers a chip for every way an imported product can be incomplete', function () {
    asCatalogAdmin();

    $category = cpCategory();

    $complete = cpProduct(['categories' => [$category], 'manage_stock' => true, 'stock' => 400]);
    $noImage = cpProduct(['image' => null, 'categories' => [$category]]);
    $noCategory = cpProduct();
    $noPrice = cpProduct(['price' => null, 'categories' => [$category]]);
    $hidden = cpProduct(['is_visible' => false, 'categories' => [$category]]);
    $low = cpProduct(['manage_stock' => true, 'stock' => 3, 'categories' => [$category]]);
    $out = cpProduct(['stock_status' => 'outofstock', 'categories' => [$category]]);
    $featured = cpProduct(['featured' => true, 'categories' => [$category]]);

    $counts = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('counts');

    expect($counts['no_image'])->toBe(1)
        ->and($counts['no_category'])->toBe(1)
        ->and($counts['no_price'])->toBe(1)
        ->and($counts['hidden'])->toBe(1)
        ->and($counts['low'])->toBe(1)
        ->and($counts['featured'])->toBe(1)
        ->and($counts['outofstock'])->toBe(1)
        ->and($counts['instock'])->toBe(7);

    expect(array_keys(cpRows('filter=no_image')))->toBe([$noImage->id])
        ->and(array_keys(cpRows('filter=no_category')))->toBe([$noCategory->id])
        ->and(array_keys(cpRows('filter=no_price')))->toBe([$noPrice->id])
        ->and(array_keys(cpRows('filter=hidden')))->toBe([$hidden->id])
        ->and(array_keys(cpRows('filter=low')))->toBe([$low->id])
        ->and(array_keys(cpRows('filter=outofstock')))->toBe([$out->id])
        ->and(array_keys(cpRows('filter=featured')))->toBe([$featured->id]);

    // 400 units is not low, and a product that does not manage stock is not
    // low either however many chips are on the screen.
    expect(cpRows()[$complete->id]['low_stock'])->toBeFalse();
});

it('counts the trash without letting it into any other chip', function () {
    asCatalogAdmin();

    $live = cpProduct();
    $trashed = cpProduct();
    $trashed->delete();

    $body = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json();

    expect($body['counts']['all'])->toBe(1)
        ->and($body['counts']['trashed'])->toBe(1)
        ->and(array_keys(cpRows()))->toBe([$live->id])
        ->and(array_keys(cpRows('filter=trashed')))->toBe([$trashed->id])
        ->and(cpRows('filter=trashed')[$trashed->id]['trashed'])->toBeTrue();
});

it('narrows the chip counts to whatever the other filters already selected', function () {
    asCatalogAdmin();

    $brand = cpBrand();

    cpProduct(['brand_id' => $brand->id, 'status' => 'publish']);
    cpProduct(['brand_id' => $brand->id, 'status' => 'draft']);
    cpProduct(['status' => 'draft']);
    cpProduct(['status' => 'draft']);

    $all = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('counts');
    $branded = test()->getJson('/admin-api/catalog-products-list?brand_id='.$brand->id)->assertOk()->json('counts');

    // The chips describe the list the operator is looking at, not the table.
    expect($all['draft'])->toBe(3)
        ->and($branded['draft'])->toBe(1)
        ->and($branded['all'])->toBe(2);
});

it('reports the same counts, total and summary on every page', function () {
    asCatalogAdmin();

    foreach (range(1, 24) as $i) {
        cpProduct(['price' => 1000 * $i, 'status' => $i % 4 === 0 ? 'draft' : 'publish']);
    }

    $first = test()->getJson('/admin-api/catalog-products-list?per_page=10&page=1')->assertOk();
    $second = test()->getJson('/admin-api/catalog-products-list?per_page=10&page=2')->assertOk();

    /*
     * This is the assertion nobody had written for the Customers screen, which
     * is why `offset 25` on an aggregate went unnoticed: an aggregate returns
     * one row, so skipping any rows returns none, every tile read zero from
     * page two on, and the endpoint still answered 200 on every driver.
     */
    expect($second->json('counts'))->toBe($first->json('counts'))
        ->and($second->json('total'))->toBe($first->json('total'))
        ->and($second->json('summary'))->toBe($first->json('summary'))
        ->and($second->json('total'))->toBeGreaterThan(10)
        ->and($second->json('products'))->toHaveCount(10)
        ->and($second->json('page'))->toBe(2);

    // And a page past the end clamps rather than returning an empty screen with
    // no way back.
    $past = test()->getJson('/admin-api/catalog-products-list?per_page=10&page=99')->assertOk();

    expect($past->json('page'))->toBe($past->json('last_page'))
        ->and($past->json('products'))->not->toBeEmpty();
});

/* -------------------------------------------------------------- the summary */

it('totals the inventory at the price the shop would actually charge', function () {
    asCatalogAdmin();

    // 3 x 6900 regular
    cpProduct(['price' => 6900, 'manage_stock' => true, 'stock' => 3]);
    // 2 x 5175 sale, in window
    cpProduct(['price' => 6900, 'sale_price' => 5175, 'manage_stock' => true, 'stock' => 2]);
    // 4 x 6900 regular, because the sale has not started
    cpProduct(['price' => 6900, 'sale_price' => 1000, 'sale_starts_at' => now()->addWeek(), 'manage_stock' => true, 'stock' => 4]);
    // Not stock-managed: no units, no value.
    cpProduct(['price' => 99900, 'manage_stock' => false, 'stock' => 99]);

    $summary = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('summary');

    expect($summary['stock_units'])->toBe(9)
        ->and($summary['inventory_fils'])->toBe(3 * 6900 + 2 * 5175 + 4 * 6900)
        ->and($summary['inventory_fils'])->toBe(58650)
        ->and($summary['inventory_fils'])->toBeInt();
});

it('averages the price over the products that have one, by integer division', function () {
    asCatalogAdmin();

    cpProduct(['price' => 10000]);
    cpProduct(['price' => 5000]);
    cpProduct(['price' => 33]);
    // An imported draft with no price at all must not drag the average down by
    // being counted as free.
    cpProduct(['price' => null, 'status' => 'draft']);

    $summary = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('summary');

    // 15033 / 3 = 5011 exactly. Integer division; no float on the path.
    expect($summary['products'])->toBe(4)
        ->and($summary['priced'])->toBe(3)
        ->and($summary['average_price_fils'])->toBe(5011)
        ->and($summary['average_price_fils'])->toBeInt();
});

/* ------------------------------------------------------- search, sort, pages */

it('searches name, SKU, brand and both product ids', function () {
    asCatalogAdmin();

    $brand = cpBrand('COSRX');

    $byName = cpProduct(['name' => 'Snail Mucin Essence', 'sku' => 'AAA-1']);
    $bySku = cpProduct(['name' => 'Something Else', 'sku' => 'ZZZ-SNAIL-9']);
    $byBrand = cpProduct(['name' => 'Third Thing', 'sku' => 'BBB-2', 'brand_id' => $brand->id]);
    $byWcId = cpProduct(['name' => 'Imported Thing', 'sku' => 'CCC-3', 'wc_id' => 18422]);

    expect(array_keys(cpRows('search=Snail')))->toEqualCanonicalizing([$byName->id, $bySku->id])
        ->and(array_keys(cpRows('search=COSRX')))->toBe([$byBrand->id])
        ->and(array_keys(cpRows('search=ZZZ-SNAIL-9')))->toBe([$bySku->id])
        // The only way to answer "did Woo product 18422 come across?".
        ->and(array_keys(cpRows('search=18422')))->toBe([$byWcId->id])
        ->and(array_keys(cpRows('search='.$byName->id)))->toBe([$byName->id]);
});

it('treats a percent sign in the search box as a character, not a wildcard', function () {
    asCatalogAdmin();

    $literal = cpProduct(['name' => 'KBB 50% OFF Bundle', 'sku' => 'PCT-1']);
    cpProduct(['name' => 'Ordinary Product', 'sku' => 'PCT-2']);

    /*
     * The usual str_replace-a-backslash escaping is a dialect trap: MySQL
     * treats a backslash as the default LIKE escape and SQLite has none at all,
     * so the same pattern finds this on production and nothing under the suite.
     * The explicit ESCAPE '!' form removes the difference — and this assertion
     * is what proves the escape is actually applied rather than the term
     * happening to match.
     */
    expect(array_keys(cpRows('search='.urlencode('50%'))))->toBe([$literal->id])
        ->and(cpRows('search='.urlencode('50%O')))->toBeEmpty()
        // The escape character itself is doubled, so a term containing it does
        // not escape the character after it.
        ->and(cpRows('search='.urlencode('!%')))->toBeEmpty();
});

it('sorts by every column the screen offers', function () {
    asCatalogAdmin();

    $b = cpBrand('Zed Brand');
    $a = cpBrand('Alpha Brand');

    $cheap = cpProduct(['name' => 'Aaa Cheap', 'sku' => 'S-1', 'price' => 1000, 'brand_id' => $a->id, 'manage_stock' => true, 'stock' => 50]);
    $mid = cpProduct(['name' => 'Mmm Middle', 'sku' => 'S-2', 'price' => 5000, 'brand_id' => $b->id, 'manage_stock' => true, 'stock' => 5]);
    $dear = cpProduct(['name' => 'Zzz Dear', 'sku' => 'S-3', 'price' => 9000, 'manage_stock' => true, 'stock' => 20]);

    cpSale($dear, 3, 27000);
    cpSale($mid, 1, 5000);

    $ids = fn (string $sort) => array_keys(cpRows('sort='.$sort));

    expect($ids('name'))->toBe([$cheap->id, $mid->id, $dear->id])
        ->and($ids('name_desc'))->toBe([$dear->id, $mid->id, $cheap->id])
        ->and($ids('price_asc'))->toBe([$cheap->id, $mid->id, $dear->id])
        ->and($ids('price_desc'))->toBe([$dear->id, $mid->id, $cheap->id])
        ->and($ids('stock_asc'))->toBe([$mid->id, $dear->id, $cheap->id])
        ->and($ids('stock_desc'))->toBe([$cheap->id, $dear->id, $mid->id])
        ->and($ids('sku'))->toBe([$cheap->id, $mid->id, $dear->id])
        ->and($ids('sales_desc'))->toBe([$dear->id, $mid->id, $cheap->id])
        ->and($ids('orders_desc')[0])->toBeIn([$dear->id, $mid->id])
        // A product with no brand sorts under the empty string rather than
        // being scattered by a NULL, which orders differently on the two
        // engines.
        ->and($ids('brand')[0])->toBe($dear->id)
        ->and($ids('newest'))->toBe([$dear->id, $mid->id, $cheap->id])
        ->and($ids('oldest'))->toBe([$cheap->id, $mid->id, $dear->id]);

    // An unknown sort falls back to newest rather than erroring.
    expect($ids('nonsense-sort'))->toBe([$dear->id, $mid->id, $cheap->id]);
});

it('sorts a priceless product to the bottom, not to the top as if it were free', function () {
    asCatalogAdmin();

    $priced = cpProduct(['price' => 100]);
    $priceless = cpProduct(['price' => null]);

    expect(array_keys(cpRows('sort=price_desc')))->toBe([$priced->id, $priceless->id]);
});

it('clamps per_page instead of letting a caller ask for the whole table', function () {
    asCatalogAdmin();

    foreach (range(1, 12) as $i) {
        cpProduct();
    }

    expect(test()->getJson('/admin-api/catalog-products-list?per_page=1')->json('per_page'))->toBe(10)
        ->and(test()->getJson('/admin-api/catalog-products-list?per_page=99999')->json('per_page'))->toBe(500)
        ->and(test()->getJson('/admin-api/catalog-products-list?per_page=0')->json('per_page'))->toBe(50);
});

/* ---------------------------------------------------------- imported rows */

it('survives a product with no brand, no category, no SKU, no image and no price', function () {
    asCatalogAdmin();

    $bare = cpProduct([
        'sku' => null,
        'brand_id' => null,
        'price' => null,
        'sale_price' => null,
        'image' => null,
        'wc_id' => 99001,
        'status' => 'draft',
    ]);

    $row = cpRows()[$bare->id];

    expect($row['sku'])->toBeNull()
        ->and($row['brand'])->toBeNull()
        ->and($row['image'])->toBeNull()
        ->and($row['has_image'])->toBeFalse()
        ->and($row['price_fils'])->toBeNull()
        ->and($row['price_display'])->toBeNull()
        ->and($row['price_input'])->toBe('')
        ->and($row['effective_price_fils'])->toBe(0)
        ->and($row['on_sale'])->toBeFalse()
        ->and($row['discount_percent'])->toBe(0)
        ->and($row['categories'])->toBe([])
        ->and($row['category_count'])->toBe(0)
        ->and($row['stock'])->toBeNull()
        ->and($row['wc_id'])->toBe(99001);

    // And the summary over a catalogue of one priceless product is not a
    // division by zero.
    $summary = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('summary');

    expect($summary['average_price_fils'])->toBe(0)
        ->and($summary['inventory_fils'])->toBe(0);
});

it('never returns the admin blobs or the description in a list row', function () {
    asCatalogAdmin();

    $product = cpProduct([
        'description' => 'CP LONG DESCRIPTION BODY',
        'seo' => ['title' => 'CP SEO TITLE'],
        'meta_feed' => ['fb_brand' => 'CP META FEED'],
        'custom_tabs' => [['title' => 'CP CUSTOM TAB']],
    ]);

    $raw = test()->getJson('/admin-api/catalog-products-list')->assertOk()->getContent();

    // An explicit allowlist, not the model. These are admin blobs and a page of
    // HTML per row; nothing on the list reads any of them.
    expect($raw)->not->toContain('CP LONG DESCRIPTION BODY')
        ->and($raw)->not->toContain('CP SEO TITLE')
        ->and($raw)->not->toContain('CP META FEED')
        ->and($raw)->not->toContain('CP CUSTOM TAB');

    $row = cpRows()[$product->id];

    expect($row)->not->toHaveKey('description')
        ->and($row)->not->toHaveKey('seo')
        ->and($row)->not->toHaveKey('meta_feed')
        ->and($row)->not->toHaveKey('custom_tabs');

    // The detail endpoint, which is a deliberate act to open, does carry the
    // description, because that is the screen that edits it.
    $detail = test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->assertOk()->json('product');

    expect($detail['description'])->toBe('CP LONG DESCRIPTION BODY');
});

/* ------------------------------------------------------------- inline edit */

it('saves a price typed as a decimal string to the exact fil', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 10000]);

    /*
     * 1.15 cannot be held exactly in a binary float, and (int) (1.15 * 100) is
     * 114. A store that is a fil light on every hundredth product is a store
     * whose books do not add up, so the parse is done on the digits.
     */
    foreach ([
        ['1.15', 115],
        ['0.07', 7],
        ['199', 19900],
        ['199.99', 19999],
        ['0.01', 1],
        ['8.29', 829],
    ] as [$typed, $fils]) {
        $out = test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => $typed])
            ->assertOk()
            ->json();

        expect((int) $product->fresh()->price)->toBe($fils, $typed.' did not become '.$fils.' fils')
            ->and($out['product']['price_fils'])->toBe($fils)
            ->and($out['product']['price_fils'])->toBeInt();
    }
});

it('refuses more decimals than the currency has instead of truncating them', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 10000]);

    /*
     * This screen used to accept '1.199' and store 119 fils -- truncated, not
     * rounded, so the operator got AED 1.19 for a price they did not type and
     * nothing said so. The rule was regex:/^\d{1,9}(\.\d{1,4})?$/: four
     * decimal places, on a currency that has two.
     *
     * Truncation is the right behaviour once a value has been ACCEPTED --
     * rounding a price up is a price nobody asked for either -- but the moment
     * to refuse extra precision is before that, where it can be said out loud.
     * App\Services\Import\Money takes exactly this line, and the create form
     * added in the same package takes it too; leaving this path truncating
     * would have made two product-write screens disagree about the same
     * keystrokes.
     */
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '1.199'])
        ->assertStatus(422);

    // And it did not half-apply: the old price is still there.
    expect((int) $product->fresh()->price)->toBe(10000);

    // Two decimals remain fine, which is the whole point -- this refuses
    // precision the currency cannot hold, not decimals as such.
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '1.19'])
        ->assertOk();

    expect((int) $product->fresh()->price)->toBe(119);
});

it('refuses a price larger than the column can hold rather than overflowing it', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 10000]);

    /*
     * 999999999 major units is 99,999,999,900 fils against a signed 32-bit
     * column -- 46 times over. MySQL strict mode raises 1264 and answers 500;
     * SQLite stores it and the two engines stop agreeing about what the
     * catalogue holds. The order-line editor had the same hole and was closed
     * one package earlier; this is the same fix on the other screen.
     */
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '999999999'])
        ->assertStatus(422);

    expect((int) $product->fresh()->price)->toBe(10000);

    /*
     * The boundary itself is accepted, so the ceiling is the column's own and
     * not an arbitrary smaller number. Spelled from MAX_FILS rather than
     * hardcoded, and NOT taken from MajorUnits::maxMajor() -- that returns a
     * formatted display string ("AED 21,474,836"), which is the right thing to
     * put in an error message and the wrong thing to post as a value.
     */
    $maxFils = \App\Support\MajorUnits::MAX_FILS;
    $exactMax = intdiv($maxFils, 100).'.'.str_pad((string) ($maxFils % 100), 2, '0', STR_PAD_LEFT);

    expect($exactMax)->toBe('21474836.47');

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => $exactMax])
        ->assertOk();

    expect((int) $product->fresh()->price)->toBe($maxFils);

    // And one fil past it is refused, so the boundary is exact rather than
    // approximate.
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['price' => '21474836.48'])
        ->assertStatus(422);

    expect((int) $product->fresh()->price)->toBe($maxFils);
});

it('saves stock, status, visibility and the stock status from an inline cell', function () {
    asCatalogAdmin();

    $product = cpProduct(['manage_stock' => true, 'stock' => 4]);

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['stock' => 250])->assertOk();
    expect((int) $product->fresh()->stock)->toBe(250);

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['status' => 'draft'])->assertOk();
    expect($product->fresh()->status)->toBe('draft');

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['is_visible' => false])->assertOk();
    expect((bool) $product->fresh()->is_visible)->toBeFalse();

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['stock_status' => 'onbackorder'])->assertOk();
    expect($product->fresh()->stock_status)->toBe('onbackorder');

    // One key in, one column changed. An inline edit of the stock cell must not
    // quietly reset the status beside it.
    expect($product->fresh()->status)->toBe('draft');
});

it('validates every write on the server, whatever the screen allowed', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 10000, 'status' => 'publish', 'manage_stock' => true, 'stock' => 5]);

    $refusals = [
        // 'active' and 'archived' are what the OLD product editor still
        // accepts, and neither is a value this schema has any concept of.
        ['status' => 'active'],
        ['status' => 'archived'],
        ['status' => ''],
        ['stock' => -1],
        ['stock' => 'plenty'],
        ['stock_status' => 'maybe'],
        ['price' => '-5.00'],
        ['price' => 'free'],
        ['price' => '1e3'],
        ['price' => '12.345678'],
        ['name' => ''],
        ['brand_id' => 9999999],
        ['category_id' => 9999999],
    ];

    foreach ($refusals as $payload) {
        test()->postJson('/admin-api/catalog-products-save/'.$product->id, $payload)
            ->assertStatus(422);
    }

    $product->refresh();

    expect((int) $product->price)->toBe(10000)
        ->and($product->status)->toBe('publish')
        ->and((int) $product->stock)->toBe(5);
});

it('refuses a sale price that is not a discount', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 6900]);

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['sale_price' => '69.00'])
        ->assertStatus(422);

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['sale_price' => '99.00'])
        ->assertStatus(422);

    expect($product->fresh()->sale_price)->toBeNull();

    // A real discount goes through, to the fil.
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['sale_price' => '51.75'])->assertOk();

    expect((int) $product->fresh()->sale_price)->toBe(5175);

    // And clearing it is a null, not an empty string that becomes zero.
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, ['sale_price' => null])->assertOk();

    expect($product->fresh()->sale_price)->toBeNull();
});

it('will not let identity or computed columns be typed over', function () {
    asCatalogAdmin();

    $product = cpProduct(['wc_id' => 4242, 'total_sales' => 17]);
    $originalSlug = $product->slug;

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, [
        'wc_id' => 1,
        'slug' => 'stolen-slug',
        'total_sales' => 9999,
        'rating' => 5,
        'review_count' => 9999,
        // One legitimate change alongside, so the request is not simply a no-op.
        'name' => 'Renamed',
    ])->assertOk();

    $product->refresh();

    /*
     * wc_id and slug are URL contracts: ?add-to-cart={wc_id} links and
     * /product/{slug}/ addresses are live in the wild. total_sales, rating and
     * review_count are computed from orders and reviews — a field that can be
     * typed over is a field whose value means nothing.
     */
    expect((int) $product->wc_id)->toBe(4242)
        ->and($product->slug)->toBe($originalSlug)
        ->and((int) $product->total_sales)->toBe(17)
        ->and((int) $product->review_count)->toBe(0)
        ->and($product->name)->toBe('Renamed');
});

it('saves the category set from the edit panel', function () {
    asCatalogAdmin();

    $one = cpCategory('One');
    $two = cpCategory('Two');
    $three = cpCategory('Three');

    $product = cpProduct(['categories' => [$one]]);

    test()->postJson('/admin-api/catalog-products-save/'.$product->id, [
        'category_ids' => [$two->id, $three->id],
    ])->assertOk();

    expect($product->fresh()->categories->pluck('name')->sort()->values()->all())
        ->toBe(['Three', 'Two']);
});

it('answers 404 for a product that is not there, on both id routes', function () {
    asCatalogAdmin();

    expect(test()->getJson('/admin-api/catalog-products-detail/987654')->getStatusCode())->toBe(404)
        ->and(test()->postJson('/admin-api/catalog-products-save/987654', ['stock' => 1])->getStatusCode())->toBe(404);
});

/* ------------------------------------------------------------ detail screen */

it('returns one product with everything the edit panel needs', function () {
    asCatalogAdmin();

    $brand = cpBrand('Round Lab');
    $category = cpCategory('Toners');

    $product = cpProduct([
        'name' => 'Dokdo Toner',
        'brand_id' => $brand->id,
        'price' => 8500,
        'sale_price' => 6800,
        'manage_stock' => true,
        'stock' => 12,
        'wc_id' => 771,
        'categories' => [$category],
    ]);

    cpSale($product, 4, 27200);

    $detail = test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->assertOk()->json('product');

    expect($detail['id'])->toBe($product->id)
        ->and($detail['wc_id'])->toBe(771)
        ->and($detail['brand'])->toBe('Round Lab')
        ->and($detail['price_fils'])->toBe(8500)
        ->and($detail['price_input'])->toBe('85.00')
        ->and($detail['sale_price_fils'])->toBe(6800)
        ->and($detail['sale_price_input'])->toBe('68.00')
        ->and($detail['effective_price_fils'])->toBe(6800)
        ->and($detail['discount_percent'])->toBe(20)
        ->and($detail['stock'])->toBe(12)
        ->and($detail['categories'])->toBe([['id' => $category->id, 'name' => 'Toners']])
        ->and($detail['orders_count'])->toBe(1)
        ->and($detail['units_sold'])->toBe(4)
        ->and($detail['revenue_fils'])->toBe(27200)
        ->and($detail['url'])->toContain($product->slug);
});

it('opens a trashed product rather than pretending it is gone', function () {
    asCatalogAdmin();

    $product = cpProduct();
    $product->delete();

    $detail = test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->assertOk()->json('product');

    expect($detail['trashed'])->toBeTrue();
});

it('lists the brands and categories the pickers need', function () {
    asCatalogAdmin();

    $brand = cpBrand('Picker Brand');
    $category = cpCategory('Picker Category');

    $facets = test()->getJson('/admin-api/catalog-products-facets')->assertOk()->json();

    expect(collect($facets['brands'])->pluck('name'))->toContain('Picker Brand')
        ->and(collect($facets['categories'])->pluck('name'))->toContain('Picker Category')
        ->and(collect($facets['categories'])->firstWhere('name', 'Picker Category')['id'])->toBe($category->id);
});

/* -------------------------------------------------------------------- bulk */

it('sets a status on a selection', function () {
    asCatalogAdmin();

    $a = cpProduct(['status' => 'draft']);
    $b = cpProduct(['status' => 'draft']);
    $untouched = cpProduct(['status' => 'draft']);

    test()->postJson('/admin-api/catalog-products-bulk-status', ['ids' => [$a->id, $b->id], 'status' => 'publish'])
        ->assertOk()
        ->assertJson(['ok' => true, 'changed' => 2]);

    expect($a->fresh()->status)->toBe('publish')
        ->and($b->fresh()->status)->toBe('publish')
        ->and($untouched->fresh()->status)->toBe('draft');
});

it('refuses to take a product off the storefront in bulk until it is confirmed', function () {
    asCatalogAdmin();

    $live = cpProduct(['status' => 'publish', 'name' => 'Live Product']);
    $draft = cpProduct(['status' => 'draft']);

    $out = test()->postJson('/admin-api/catalog-products-bulk-status', [
        'ids' => [$live->id, $draft->id],
        'status' => 'private',
    ])->assertOk()->json();

    // The safe half of the selection is still what the operator meant.
    expect($out['changed'])->toBe(1)
        ->and($out['skipped'])->toHaveCount(1)
        ->and($out['skipped'][0]['id'])->toBe($live->id)
        ->and($out['skipped'][0]['label'])->toBe('Live Product')
        ->and($out['skipped'][0]['reason'])->not->toBeEmpty();

    expect($live->fresh()->status)->toBe('publish')
        ->and($draft->fresh()->status)->toBe('private');

    // And only with force does the live one move.
    test()->postJson('/admin-api/catalog-products-bulk-status', [
        'ids' => [$live->id], 'status' => 'private', 'force' => true,
    ])->assertOk()->assertJson(['changed' => 1]);

    expect($live->fresh()->status)->toBe('private');
});

it('will not let a bulk action invent a status the schema has no concept of', function () {
    asCatalogAdmin();

    $product = cpProduct(['status' => 'publish']);

    foreach (['active', 'archived', 'trash', 'wc-anything', ''] as $status) {
        test()->postJson('/admin-api/catalog-products-bulk-status', [
            'ids' => [$product->id], 'status' => $status, 'force' => true,
        ])->assertStatus(422);
    }

    expect($product->fresh()->status)->toBe('publish');
});

it('adds and removes categories across a selection', function () {
    asCatalogAdmin();

    $keep = cpCategory('Keep');
    $add = cpCategory('Add');

    $a = cpProduct(['categories' => [$keep]]);
    $b = cpProduct();

    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$a->id, $b->id], 'category_ids' => [$add->id], 'mode' => 'add',
    ])->assertOk();

    expect($a->fresh()->categories->pluck('name')->sort()->values()->all())->toBe(['Add', 'Keep'])
        ->and($b->fresh()->categories->pluck('name')->all())->toBe(['Add']);

    // Adding the same category twice is not a duplicate-key error: the pivot
    // has a composite primary key and the difference is computed first.
    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$a->id, $b->id], 'category_ids' => [$add->id], 'mode' => 'add',
    ])->assertOk();

    expect($a->fresh()->categories)->toHaveCount(2);

    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$a->id, $b->id], 'category_ids' => [$add->id], 'mode' => 'remove',
    ])->assertOk();

    expect($a->fresh()->categories->pluck('name')->all())->toBe(['Keep'])
        ->and($b->fresh()->categories)->toHaveCount(0);
});

it('fills in the primary category when a product had none', function () {
    asCatalogAdmin();

    $category = cpCategory('Primary');
    $product = cpProduct();

    expect($product->category_id)->toBeNull();

    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$product->id], 'category_ids' => [$category->id], 'mode' => 'add',
    ])->assertOk();

    // The storefront reads category_id for breadcrumbs. A product with plenty
    // of assignments and no primary one renders a breadcrumb to nowhere.
    expect((int) $product->fresh()->category_id)->toBe($category->id);
});

it('refuses to replace a product s whole category set without confirmation', function () {
    asCatalogAdmin();

    $old = cpCategory('Old');
    $new = cpCategory('New');
    $product = cpProduct(['categories' => [$old]]);

    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$product->id], 'category_ids' => [$new->id], 'mode' => 'replace',
    ])->assertStatus(422)->assertJson(['needs_confirmation' => true]);

    // On an imported catalogue this is the whole WooCommerce taxonomy for that
    // product, so nothing happened.
    expect($product->fresh()->categories->pluck('name')->all())->toBe(['Old']);

    test()->postJson('/admin-api/catalog-products-bulk-category', [
        'ids' => [$product->id], 'category_ids' => [$new->id], 'mode' => 'replace', 'confirm' => true,
    ])->assertOk();

    expect($product->fresh()->categories->pluck('name')->all())->toBe(['New']);
});

it('adjusts prices by a percentage with integer arithmetic, to the exact fil', function () {
    asCatalogAdmin();

    /*
     * THE FLOAT BUG THIS EXISTS FOR. `1 - 30 / 100` is 0.69999999999999995559,
     * so 10000 * that is 6999.9999999999995 and lands as 6999 — a fil light on
     * every product. The percentage is carried as integer basis points and the
     * division is intdiv, so 10000 at -30% is exactly 7000 and nothing else.
     */
    $a = cpProduct(['price' => 10000]);
    $b = cpProduct(['price' => 6900]);
    $c = cpProduct(['price' => 1]);

    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$a->id, $b->id, $c->id],
        'target' => 'price', 'mode' => 'percent', 'percent' => '-30', 'confirm' => true,
    ])->assertOk()
        // Two changed, not three: 1 fil at -30% rounds back to 1 fil, and a
        // write that would not change the value is not counted as one.
        ->assertJson(['changed' => 2]);

    expect((int) $a->fresh()->price)->toBe(7000)
        ->and((int) $b->fresh()->price)->toBe(4830)
        // 1 fil at -30% is 0.7 of a fil, which rounds to 1. Not 0, and not
        // 0.7 — a price is an integer number of the smallest unit there is.
        ->and((int) $c->fresh()->price)->toBe(1);

    // A fractional percentage, still on integers: 7000 at +12.5% is 7875.
    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$a->id], 'target' => 'price', 'mode' => 'percent', 'percent' => '12.5', 'confirm' => true,
    ])->assertOk();

    expect((int) $a->fresh()->price)->toBe(7875);
});

it('adjusts prices by a flat amount and sets them outright', function () {
    asCatalogAdmin();

    $a = cpProduct(['price' => 10000]);
    $b = cpProduct(['price' => 505]);

    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$a->id, $b->id], 'target' => 'price', 'mode' => 'amount', 'amount' => '-5.05', 'confirm' => true,
    ])->assertOk();

    expect((int) $a->fresh()->price)->toBe(9495)
        ->and((int) $b->fresh()->price)->toBe(0);

    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$a->id, $b->id], 'target' => 'price', 'mode' => 'set', 'value' => '19.99', 'confirm' => true,
    ])->assertOk();

    expect((int) $a->fresh()->price)->toBe(1999)
        ->and((int) $b->fresh()->price)->toBe(1999);
});

it('never writes a bulk price below zero or a sale price above its regular price', function () {
    asCatalogAdmin();

    $cheap = cpProduct(['price' => 100, 'name' => 'Cheap One']);
    $priceless = cpProduct(['price' => null, 'name' => 'No Price']);
    $onSale = cpProduct(['price' => 10000, 'sale_price' => 5000]);

    $out = test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$cheap->id, $priceless->id],
        'target' => 'price', 'mode' => 'amount', 'amount' => '-50.00', 'confirm' => true,
    ])->assertOk()->json();

    expect($out['changed'])->toBe(0)
        ->and($out['skipped'])->toHaveCount(2)
        ->and(collect($out['skipped'])->pluck('label')->sort()->values()->all())->toBe(['Cheap One', 'No Price']);

    expect((int) $cheap->fresh()->price)->toBe(100)
        ->and($priceless->fresh()->price)->toBeNull();

    // A sale price raised above the regular price is not a sale; it is a
    // struck-through price that is lower than the one beside it.
    $out = test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$onSale->id], 'target' => 'sale_price', 'mode' => 'percent', 'percent' => '500', 'confirm' => true,
    ])->assertOk()->json();

    expect($out['changed'])->toBe(0)
        ->and($out['skipped'])->toHaveCount(1)
        ->and((int) $onSale->fresh()->sale_price)->toBe(5000);
});

it('clears a sale price in bulk but refuses to clear a regular one', function () {
    asCatalogAdmin();

    $onSale = cpProduct(['price' => 10000, 'sale_price' => 7000]);
    $notOnSale = cpProduct(['price' => 10000]);

    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$onSale->id, $notOnSale->id], 'target' => 'sale_price', 'mode' => 'clear', 'confirm' => true,
    ])->assertOk()->assertJson(['changed' => 1]);

    expect($onSale->fresh()->sale_price)->toBeNull()
        ->and((int) $onSale->fresh()->price)->toBe(10000);

    $out = test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$notOnSale->id], 'target' => 'price', 'mode' => 'clear', 'confirm' => true,
    ])->assertOk()->json();

    expect($out['changed'])->toBe(0)
        ->and($out['skipped'])->toHaveCount(1)
        ->and((int) $notOnSale->fresh()->price)->toBe(10000);
});

it('refuses a bulk price change that was never confirmed', function () {
    asCatalogAdmin();

    $product = cpProduct(['price' => 10000]);

    test()->postJson('/admin-api/catalog-products-bulk-price', [
        'ids' => [$product->id], 'target' => 'price', 'mode' => 'percent', 'percent' => '-90',
    ])->assertStatus(422)->assertJson(['needs_confirmation' => true]);

    // There is no undo for this one, so nothing happens on a first click.
    expect((int) $product->fresh()->price)->toBe(10000);
});

it('will not accept an unbounded bulk request', function () {
    asCatalogAdmin();

    $category = cpCategory();

    test()->postJson('/admin-api/catalog-products-bulk-status', ['ids' => range(1, 5000), 'status' => 'draft'])->assertStatus(422);
    test()->postJson('/admin-api/catalog-products-bulk-status', ['ids' => [], 'status' => 'draft'])->assertStatus(422);
    test()->postJson('/admin-api/catalog-products-bulk-status', ['ids' => [1]])->assertStatus(422);
    test()->postJson('/admin-api/catalog-products-bulk-category', ['ids' => range(1, 5000), 'category_ids' => [$category->id], 'mode' => 'add'])->assertStatus(422);
    test()->postJson('/admin-api/catalog-products-bulk-price', ['ids' => range(1, 5000), 'target' => 'price', 'mode' => 'set', 'value' => '1.00', 'confirm' => true])->assertStatus(422);
});

/* ------------------------------------------------------------------- export */

it('exports the current filtered view, not the whole catalogue', function () {
    asCatalogAdmin();

    $category = cpCategory('Exported Category');

    $kept = cpProduct([
        'name' => 'CP EXPORTED',
        'sku' => 'CP-EXP-1',
        'status' => 'draft',
        'price' => 12345,
        'manage_stock' => true,
        'stock' => 3,
        'categories' => [$category],
    ]);

    cpProduct(['name' => 'CP NOT EXPORTED', 'status' => 'publish']);

    $response = test()->get('/admin-api/catalog-products-export?filter=draft');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('products-');

    $csv = $response->streamedContent();

    expect($csv)->toContain('CP EXPORTED')
        ->and($csv)->not->toContain('CP NOT EXPORTED')
        // Both the exact integer and a spreadsheet-friendly decimal, so later
        // tooling has the fils and the owner has a number.
        ->and($csv)->toContain('12345')
        ->and($csv)->toContain('123.45')
        ->and($csv)->toContain('price_fils')
        ->and($csv)->toContain('Exported Category')
        ->and($csv)->toContain('CP-EXP-1');
});

it('keeps the admin blobs out of the download too', function () {
    asCatalogAdmin();

    cpProduct([
        'description' => 'CP CSV LONG DESCRIPTION',
        'seo' => ['title' => 'CP CSV SEO'],
        'meta_feed' => ['fb_brand' => 'CP CSV FEED'],
    ]);

    $csv = test()->get('/admin-api/catalog-products-export')->streamedContent();

    expect($csv)->not->toContain('CP CSV LONG DESCRIPTION')
        ->and($csv)->not->toContain('CP CSV SEO')
        ->and($csv)->not->toContain('CP CSV FEED');
});

it('does not let an imported product name become a formula in the owner s spreadsheet', function () {
    asCatalogAdmin();

    // A WooCommerce export is a file somebody could have edited, and every one
    // of these is a legal product name.
    cpProduct(['name' => '=HYPERLINK("http://evil.test","click")']);
    cpProduct(['name' => '+1234']);
    cpProduct(['name' => '-1234']);
    cpProduct(['name' => '@SUM(A1:A9)']);
    // A leading tab on the NAME, which is not trimmed. (The SKU is: a stored
    // SKU with whitespace round it is a data-entry mistake, and trimming it is
    // the right answer — it also happens to defuse this, so the assertion is
    // put on a field where the tab really does survive to the cell.)
    cpProduct(['name' => "\t=cmd|'/c calc'!A1", 'sku' => 'TAB-1']);

    $csv = test()->get('/admin-api/catalog-products-export')->streamedContent();

    // Quoted into a text cell, so none of them is evaluated when opened.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'+1234")
        ->and($csv)->toContain("'-1234")
        ->and($csv)->toContain("'@SUM")
        // A leading tab sneaks past a naive check of the first character.
        ->and($csv)->toContain("'\t=cmd")
        // And the un-neutralised form is nowhere in the file.
        ->and($csv)->not->toContain(',=HYPERLINK')
        ->and($csv)->not->toContain(',@SUM');
});

/* ------------------------------------------------------------- query counts */

it('runs a bounded number of queries however many products there are', function () {
    asCatalogAdmin();

    $brands = [cpBrand(), cpBrand(), cpBrand()];
    $categories = [cpCategory(), cpCategory(), cpCategory()];

    // A realistic population, not two rows: an N+1 is invisible at two rows and
    // this screen is going to be pointed at 2,266 imported products.
    foreach (range(1, 60) as $i) {
        $product = cpProduct([
            'brand_id' => $i % 4 === 0 ? null : $brands[$i % 3]->id,
            'status' => ['publish', 'publish', 'draft', 'private'][$i % 4],
            'price' => 1000 * $i,
            'sale_price' => $i % 5 === 0 ? 500 * $i : null,
            'manage_stock' => $i % 2 === 0,
            'stock' => $i,
            'image' => $i % 7 === 0 ? null : 'https://cdn.test/load-'.$i.'.jpg',
            'categories' => $i % 6 === 0 ? [] : [$categories[$i % 3]],
        ]);

        if ($i % 3 === 0) {
            cpSale($product, 2, 2000 * $i);
        }
    }

    // One unmeasured request first. The currency settings row is read through a
    // cache on the first call in a process and memoised after it, so without
    // this the first measurement carries a warm-up query the second does not.
    test()->getJson('/admin-api/catalog-products-list?per_page=50')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/admin-api/catalog-products-list?per_page=50')->assertOk();

    $withFifty = count(DB::getQueryLog());

    DB::flushQueryLog();

    // A tenth of the page size. A per-row query would shrink with it; a join
    // and a single eager load do not.
    test()->getJson('/admin-api/catalog-products-list?per_page=5')->assertOk();

    $withFive = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($withFifty)->toBeLessThanOrEqual(10, 'the product list is running '.$withFifty.' queries for one page')
        // The same count for a tenth of the rows is what "not N+1" means. A
        // threshold alone passes for an N+1 that is merely small.
        ->and($withFive)->toBe($withFifty);

    // And the aggregates are still right at this size, so the bound was not
    // bought by not computing anything.
    $row = test()->getJson('/admin-api/catalog-products-list?sort=oldest&per_page=1')->json('products.0');

    expect($row['price_fils'])->toBe(1000)
        ->and($row['categories'])->toHaveCount(1);
});

it('runs a bounded number of queries for the export too', function () {
    asCatalogAdmin();

    $category = cpCategory();

    foreach (range(1, 60) as $i) {
        cpProduct(['price' => 1000 + $i, 'categories' => [$category]]);
    }

    test()->get('/admin-api/catalog-products-export')->streamedContent();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $csv = test()->get('/admin-api/catalog-products-export')->streamedContent();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Chunked, so it is one statement per 500 rows (plus its eager load) rather
    // than one per product.
    expect($count)->toBeLessThanOrEqual(6, 'the export is running '.$count.' queries for 60 products')
        ->and(substr_count($csv, "\n"))->toBeGreaterThan(60);
});

it('runs a bounded number of queries for the detail screen', function () {
    asCatalogAdmin();

    $category = cpCategory();
    $product = cpProduct(['categories' => [$category]]);

    foreach (range(1, 20) as $i) {
        cpSale($product, 1, 1000);
    }

    test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/admin-api/catalog-products-detail/'.$product->id)->assertOk();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    // The product, its categories, its brand and one aggregate over its order
    // lines. Twenty orders do not make twenty queries.
    expect($count)->toBeLessThanOrEqual(6, 'the product detail is running '.$count.' queries');
});

/* ---------------------------------------------------------------- the screen */

it('replaces the client-side Products screen in the admin console', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $start = strpos($source, 'LANE AF · Catalog · Products — BEGIN');
    $end = strpos($source, 'LANE AF · Catalog · Products — END');

    expect($start)->not->toBeFalse('the Lane AF region is missing from the admin view')
        ->and($end)->not->toBeFalse('the Lane AF region is not closed');

    $region = substr($source, (int) $start, (int) $end - (int) $start);

    expect($region)->toContain('window.catProducts')
        ->toContain("'/admin-api/catalog-products-list?'")
        ->toContain("fixAdminApiUrl('/admin-api/catalog-products-export')")
        ->toContain('/admin-api/catalog-products-save/')
        ->toContain('/admin-api/catalog-products-bulk-status')
        ->toContain('/admin-api/catalog-products-bulk-category')
        ->toContain('/admin-api/catalog-products-bulk-price')
        // Product names and SKUs come out of a WooCommerce export and land in
        // innerHTML. Everything written into the page goes through sesc().
        ->toContain('sesc(')
        // A destructive action never happens on a click alone.
        ->toContain('cpConfirm')
        ->toContain('confirm')
        // The price cell edits fils, never a float.
        ->toContain('price_input');

    // The old screen's dead Edit button, which raised a toast saying editing
    // was not built, is gone.
    expect($source)->not->toContain("Product editing isn't built yet");

    // And the table is in its own scroll container, so a wide table scrolls
    // inside the card instead of making the whole page scroll sideways.
    expect($region)->toContain('cplscroll');
});

it('renders the whole admin document with the products screen in it', function () {
    // One 600KB Blade file with several lanes editing it at once: a stray brace
    // here takes down the entire admin console, not just this screen.
    $html = view('admin.app')->render();

    expect($html)->toContain('window.catProducts')
        ->toContain('/admin-api/catalog-products-list');
});

it('does not scroll the page sideways on a narrow phone', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $start = (int) strpos($source, 'LANE AF · Catalog · Products — BEGIN');
    $end = (int) strpos($source, 'LANE AF · Catalog · Products — END');
    $region = substr($source, $start, $end - $start);

    // The measured proof is the Chromium run at 390 and 1280 recorded in the
    // PR; this pins the rules that make it true, so none can be dropped by a
    // later edit without a test going red.
    expect($region)->toContain('overflow-x:auto')
        ->and($region)->toContain('max-width:100%')
        // A grid track defaults to min-width:auto, so a long money figure
        // widens its track past its share and pushes the page out.
        ->and($region)->toContain('minmax(0,1fr)');
});

it('leaves the other lanes regions of the admin view alone', function () {
    $source = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // Orders, Customers, Mail and the Categories/Attributes tabs all live in
    // this same file and belong to other lanes.
    expect($source)->toContain('LANE V · Store · Orders — BEGIN')
        ->toContain('LANE T · Store · Customers — BEGIN')
        ->toContain('LANE J · Store · Mail — BEGIN')
        ->toContain('LANE N · Catalog · Categories & Attributes — BEGIN')
        // And the order-detail product picker still calls the endpoint it has
        // always called, with the fields it reads still present.
        ->toContain("api('/admin-api/catalog/products?search='");
});

it('keeps answering the old catalog products endpoint the way its callers expect', function () {
    asCatalogAdmin();

    $brand = cpBrand('Legacy Brand');
    $product = cpProduct(['name' => 'Legacy Product', 'brand_id' => $brand->id, 'price' => 19900, 'sale_price' => 9950]);

    /*
     * routes/web.php is not this lane's file, and GET /admin-api/catalog/products
     * is still mounted there against this same controller. The order-detail
     * product picker reads `id`, `name`, `brand` and `sale_price || price` as
     * MAJOR units, and tests/Feature/SqlDialectGuardTest.php drives it by name
     * with the old chip names. All of that still works.
     */
    $body = test()->getJson('/admin-api/catalog/products?search=Legacy&per_page=8')->assertOk()->json();

    $row = collect($body['products'])->firstWhere('id', $product->id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Legacy Product')
        ->and($row['brand'])->toBe('Legacy Brand')
        // Major units, as the picker has always read them. JSON renders 199.0
        // as 199, so these are compared as numbers rather than by type.
        ->and((float) $row['price'])->toBe(199.0)
        ->and((float) $row['sale_price'])->toBe(99.5)
        // And the exact integers beside them, which is what this screen reads.
        ->and($row['price_fils'])->toBe(19900)
        ->and($row['sale_price_fils'])->toBe(9950);

    // The old chip names still answer, and still mean what they meant.
    $counts = test()->getJson('/admin-api/catalog/products?filter=published')->assertOk()->json('counts');

    expect($counts)->toHaveKeys(['all', 'published', 'draft', 'low', 'out'])
        ->and($counts['published'])->toBe($counts['publish']);

    expect(test()->getJson('/admin-api/catalog/products?filter=out')->assertOk()->json('total'))->toBe(0);
});
