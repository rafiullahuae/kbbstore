<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Import\Money as ImportMoney;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogProductCreateRoutes;

/**
 * Catalog → Products → Add product, and the product image.
 *
 * Package 2.60.131 shipped a Products screen that could list, search, filter,
 * inline-edit, bulk-edit and export — and could not add a product, or change a
 * product's picture. "Add product" reached openProduct(-1), a mock whose every
 * control raised toast('… (preview)'). Those are the first two things a shop
 * owner does.
 *
 * WHAT IS PINNED HARDEST HERE, AND WHY EACH ONE IS A WAY TO BE WRONG QUIETLY.
 *
 *   THE GUARD. These endpoints add rows to the catalogue and replace the
 *   photographs on products the shop is already selling. /api/* in this app is
 *   unauthenticated by design, so being on the wrong side of that line is an
 *   anonymous write primitive over the store's own shelves. Every route is
 *   asserted against an anonymous caller, a signed-in storefront customer and a
 *   plain `web` user, and the middleware is read back off the REGISTERED routes
 *   rather than trusted from the harness — RouteRegistrar::middleware()
 *   REPLACES rather than appends, so a harness that chains it twice guards
 *   nothing while reading as though it did.
 *
 *   "IT SAVED" IS NOT "IT APPEARED". This is the whole point of the lane. Every
 *   create assertion below ends at the STOREFRONT, not at the row: is it
 *   returned by Product::visible(), is it on its category page, is it in
 *   /sitemap.xml, does /product/{slug}/ answer 200. A test that checked the
 *   insert would pass for a product nobody can buy — which is exactly the bug a
 *   sibling lane just finished paying for, where saving a product as 'active'
 *   answered 200 and silently removed it from all four.
 *
 *   THE CATEGORY PIVOT. ShopController::index() filters the category archive
 *   with whereHas('categories', …) — the category_product many-to-many, NOT
 *   products.category_id. A create that writes only the column produces a
 *   product that is live everywhere except the one page the owner picked for
 *   it, which is the page they will look at to check their work.
 *
 *   THE MONEY. Asserted to the exact fil on every path that writes one, from
 *   the decimal string an operator typed. 99.50 is 9950, 1.15 is 115, 0.29 is
 *   29. `(int) (1.15 * 100)` is 114 and `0.29 * 100` is 28.999999999999996; a
 *   store that is a fil light on every hundredth product is a store whose books
 *   do not add up. The ceiling is asserted too, because products.price is a
 *   signed 32-bit column and past it MySQL raises 1264 while SQLite stores the
 *   value happily.
 *
 *   THE SLUG. A live URL contract (U-01, /product/{slug}/). Generated from the
 *   name, shown before saving, editable at create time, unique against the
 *   whole table including the trash — and NOT editable afterwards, which is
 *   asserted as a property of the registered write paths rather than as a
 *   comment.
 *
 *   THE UPLOAD, BY CONTENT AND NOT BY NAME. There is one upload endpoint in
 *   this application and both new screens post to it. Its accept check reads
 *   the file's bytes; so, now, does the extension it stores the file under and
 *   the decision to run the SVG safety scan. Those three used to disagree, and
 *   a hostile SVG named `photo.png` walked through the gap between them.
 */

/* ------------------------------------------------------------------ fixtures */

function pcAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'PC Owner',
        'email' => 'pc-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function pcBrand(string $name = 'PC Brand'): Brand
{
    static $n = 0;
    $n++;

    return Brand::create(['name' => $name, 'slug' => 'pc-brand-'.$n.'-'.uniqid()]);
}

function pcCategory(string $name = 'PC Category'): Category
{
    static $n = 0;
    $n++;

    return Category::create(['name' => $name, 'slug' => 'pc-cat-'.$n.'-'.uniqid()]);
}

/**
 * Empty the catalogue before a test that counts things.
 *
 * 2026_08_27_100000_seed_demo_catalogue.php puts 24 real products in the
 * database and RefreshDatabase runs the whole migration set, so any count
 * asserted against a fixture of two is really being asserted against
 * twenty-six. Deleted rather than worked around: the deletes run inside
 * RefreshDatabase's transaction, so the seeded catalogue is back for the next
 * test.
 */
function pcResetCatalogue(): void
{
    DB::table('category_product')->delete();
    DB::table('order_items')->delete();
    DB::table('orders')->delete();
    DB::table('products')->delete();
}

/** Wire the route file and sign in as an admin — the normal case. */
function asPcAdmin(): void
{
    CatalogProductCreateRoutes::wire(app());
    pcResetCatalogue();
    test()->actingAs(pcAdmin(), 'admin');
}

/**
 * Everything a valid create needs, so a test can change ONE key and have the
 * failure be about that key.
 *
 * @return array<string, mixed>
 */
function pcPayload(array $overrides = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'name' => 'Snail Mucin Essence '.$n,
        'slug' => '',
        'sku' => 'PC-SKU-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'brand_id' => null,
        'category_id' => null,          // every caller sets this
        'price' => '99.50',
        'sale_price' => null,
        'status' => 'publish',
        'stock_status' => 'instock',
        'is_visible' => true,
        'manage_stock' => false,
        'stock' => null,
        'short_description' => 'A gentle essence.',
        'description' => '<p>A gentle essence.</p>',
        'image' => null,
    ], $overrides);
}

function pcCreate(array $overrides = [])
{
    return test()->postJson('/admin-api/catalog-product-create', pcPayload($overrides));
}

/* --------------------------------------------- the storefront, not the row */

/** Is this product on its category page right now? */
function pcOnCategoryPage(Product $product, Category $category): bool
{
    $html = test()->get('/product-category/'.$category->slug.'/')->getContent();

    return str_contains((string) $html, $product->slug);
}

/** Is this product in the sitemap right now? */
function pcInSitemap(Product $product): bool
{
    $xml = test()->get('/sitemap.xml')->getContent();

    return str_contains((string) $xml, '/product/'.$product->slug.'/');
}

/**
 * Everything the storefront uses to decide whether a product exists.
 *
 * Deliberately the same four questions AdminProductWritePathTest asks after a
 * SAVE. A product that arrives through the create form and a product that
 * survives an edit have to be live in exactly the same sense, or one of the two
 * screens is lying.
 *
 * @return array<string, bool>
 */
function pcIsLive(Product $product, Category $category): array
{
    return [
        'visible_scope' => Product::visible()->whereKey($product->id)->exists(),
        'category_page' => pcOnCategoryPage($product, $category),
        'sitemap' => pcInSitemap($product),
        'product_page' => test()->get('/product/'.$product->slug.'/')->getStatusCode() === 200,
    ];
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on every route the create screen adds', function () {
    CatalogProductCreateRoutes::wire(app());
    pcResetCatalogue();

    $category = pcCategory();
    $existing = Product::create([
        'name' => 'PC SECRET PRODUCT',
        'slug' => 'pc-secret-'.uniqid(),
        'sku' => 'PC-SECRET-SKU',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 19900,
        'stock_status' => 'instock',
        'image' => 'https://cdn.test/original.jpg',
    ]);

    $before = Product::withTrashed()->count();

    $refusals = [
        ['/admin-api/catalog-product-slug', ['name' => 'Anything']],
        ['/admin-api/catalog-product-create', pcPayload(['category_id' => $category->id])],
        ['/admin-api/catalog-product-image/'.$existing->id, ['image' => 'https://evil.test/defaced.png']],
    ];

    foreach ($refusals as [$uri, $body]) {
        $response = test()->postJson($uri, $body);

        expect($response->getStatusCode())->toBe(401, 'POST '.$uri.' was not refused');

        // Nothing leaked on the way to the refusal either.
        expect($response->getContent())->not->toContain('PC SECRET PRODUCT')
            ->and($response->getContent())->not->toContain('PC-SECRET-SKU');
    }

    // And nothing was written by the anonymous caller.
    expect(Product::withTrashed()->count())->toBe($before)
        ->and($existing->fresh()->image)->toBe('https://cdn.test/original.jpg');
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    CatalogProductCreateRoutes::wire(app());
    pcResetCatalogue();

    $category = pcCategory();
    $existing = Product::create([
        'name' => 'PC Guarded',
        'slug' => 'pc-guarded-'.uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 25000,
        'stock_status' => 'instock',
        'image' => 'https://cdn.test/original.jpg',
    ]);

    $before = Product::withTrashed()->count();

    // A shopper signed into the storefront. The `customer` guard is
    // deliberately separate from `admin` (config/auth.php says so in as many
    // words); this is the assertion that the separation is real and not merely
    // documented.
    $shopper = Customer::create([
        'name' => 'PC Shopper',
        'email' => 'pc-shopper-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');

    expect(test()->postJson('/admin-api/catalog-product-slug', ['name' => 'X'])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-product-create', pcPayload(['category_id' => $category->id]))->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-product-image/'.$existing->id, ['image' => null])->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'PC Web User',
        'email' => 'pc-webuser-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->postJson('/admin-api/catalog-product-create', pcPayload(['category_id' => $category->id]))->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/catalog-product-image/'.$existing->id, ['image' => null])->getStatusCode())->toBe(401);

    // Not one of those calls added a product or touched the image.
    expect(Product::withTrashed()->count())->toBe($before)
        ->and($existing->fresh()->image)->toBe('https://cdn.test/original.jpg');
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    CatalogProductCreateRoutes::wire(app());

    $routes = CatalogProductCreateRoutes::registered();

    // Three routes, and the count is asserted so a fourth cannot be added
    // without this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        // The trap this exists for: RouteRegistrar::middleware() REPLACES the
        // pending middleware, so a harness that chains it twice registers
        // routes with no `auth:admin` at all while reading as though it did —
        // and every 401 assertion above would then be passing against nothing.
        expect($route->middleware())->toContain('auth:admin')
            ->and($route->middleware())->toContain('web');
    }
});

it('constrains the image route id to digits so nothing reaches a typed controller argument', function () {
    CatalogProductCreateRoutes::wire(app());

    foreach (CatalogProductCreateRoutes::registered() as $route) {
        if (! str_contains($route->uri(), '{id}')) {
            continue;
        }

        expect($route->wheres['id'] ?? '')->not->toBe('', $route->uri().' has an unconstrained {id}');
    }

    test()->actingAs(pcAdmin(), 'admin');

    /*
     * Refused by the ROUTER, not by the controller — the point of the
     * constraint is that a stray path segment never reaches a method whose
     * signature is `int $id`, where it would be a TypeError and a 500.
     *
     * Asserted against a control rather than against a literal, because the
     * number is not the obvious one: routes/web.php registers
     * Route::fallback(), which is a GET route matching every path, so
     * Laravel's checkForAlternateVerbs() finds a GET for this URI and answers
     * 405 rather than 404 for ANY unrouted POST in this application. That is
     * app-wide behaviour and not this lane's to change; what matters here is
     * that a non-numeric id is treated exactly like a path with no route at
     * all.
     */
    $unrouted = test()->postJson('/admin-api/no-such-endpoint-at-all', [])->getStatusCode();

    expect(test()->postJson('/admin-api/catalog-product-image/new', ['image' => null])->getStatusCode())
        ->toBe($unrouted)
        ->and($unrouted)->toBeIn([404, 405]);
});

it('adds no delete route, because order_items point at these rows', function () {
    CatalogProductCreateRoutes::wire(app());

    foreach (CatalogProductCreateRoutes::registered() as $route) {
        expect($route->methods())->not->toContain('DELETE', $route->uri().' is a delete route');
        expect($route->uri())->not->toContain('delete');
    }
});

it('documents for the integrator exactly where the file must be mounted', function () {
    $header = (string) file_get_contents(base_path('routes/catalog-product-create-admin.php'));

    // The require line the integrator has to add, and the group it goes in.
    expect($header)->toContain("require __DIR__.'/catalog-product-create-admin.php';")
        ->and($header)->toContain('admin-api')
        ->and($header)->toContain('NoStoreAdminApi')
        // And the reason it may not go anywhere else.
        ->and($header)->toContain('unauthenticated');

    // The routes/web.php group the header points at really is the shape it
    // describes, rather than a shape that was true when the header was written.
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect($web)->toContain("Route::prefix('admin-api')->middleware(\\App\\Http\\Middleware\\NoStoreAdminApi::class)->group(");
});

/* ------------------------------------------- the create actually reaches the shop */

it('creates a product that the storefront really shows', function () {
    asPcAdmin();

    $category = pcCategory('Essences PC');
    $brand = pcBrand('Anua PC');

    $response = pcCreate([
        'name' => 'Heartleaf Quercetinol Essence',
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'price' => '99.50',
    ])->assertStatus(201);

    $product = Product::findOrFail($response->json('id'));

    // THE ASSERTION THAT MATTERS. Not "a row exists" — a row nobody can buy
    // would satisfy that. All four storefront surfaces, the same four
    // AdminProductWritePathTest asks about after an edit.
    expect(pcIsLive($product, $category))->toBe([
        'visible_scope' => true,
        'category_page' => true,
        'sitemap' => true,
        'product_page' => true,
    ]);

    // And the row is what was asked for, to the fil and to the relation.
    expect((int) $product->price)->toBe(9950)
        ->and($product->status)->toBe('publish')
        ->and((bool) $product->is_visible)->toBeTrue()
        ->and((int) $product->brand_id)->toBe($brand->id)
        ->and((int) $product->category_id)->toBe($category->id)
        ->and($product->wc_id)->toBeNull();

    expect($response->json('live'))->toBeTrue()
        ->and($response->json('url'))->toBe('/product/'.$product->slug.'/');
});

/*
 * THE PIVOT, ON ITS OWN.
 *
 * The test above would still pass if the pivot were written and category_id
 * were not, or the other way round on a build where the archive read the
 * column. This one names the mechanism: the row in category_product is what
 * ShopController::index()'s whereHas() matches, and the column is what the
 * admin list and the breadcrumb read. Both, from one id.
 */
it('files the product under its category in the pivot as well as the column', function () {
    asPcAdmin();

    $category = pcCategory('Toners PC');

    $id = pcCreate(['category_id' => $category->id])->assertStatus(201)->json('id');

    $pivot = DB::table('category_product')
        ->where('product_id', $id)
        ->where('category_id', $category->id)
        ->count();

    expect($pivot)->toBe(1, 'no category_product row — the category archive filters on this, not on category_id')
        ->and((int) Product::findOrFail($id)->category_id)->toBe($category->id);
});

it('refuses a product with no category, because no category page would list it', function () {
    asPcAdmin();

    $before = Product::count();

    $response = test()->postJson('/admin-api/catalog-product-create', array_diff_key(
        pcPayload(),
        ['category_id' => null]
    ))->assertStatus(422);

    expect($response->json('errors.category_id.0'))->toContain('category page')
        ->and(Product::count())->toBe($before);
});

it('refuses a category or brand that does not exist rather than 500ing on the insert', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    pcCreate(['category_id' => 999999])->assertStatus(422)
        ->assertJsonPath('errors.category_id.0', 'That category no longer exists.');

    pcCreate(['category_id' => $category->id, 'brand_id' => 999999])->assertStatus(422)
        ->assertJsonPath('errors.brand_id.0', 'That brand no longer exists.');

    expect(Product::count())->toBe($before);
});

/*
 * BRAND AND CATEGORY ARE RELATIONS, NOT COLUMNS.
 *
 * `$product->brand = 'Anua'` puts a string in the attribute bag and save()
 * issues `UPDATE products SET brand = ?`. No such column: SQLSTATE 42S22,
 * HTTP 500. The old editor sent `brand` on every save and this fired whenever
 * the Brands box was on screen.
 */
it('never writes a brand or a category as a column, or invents one as a side effect', function () {
    asPcAdmin();

    $category = pcCategory();
    $brands = Brand::count();
    $categories = Category::count();

    // A payload carrying the NAMES, the way the old editor sent them.
    $id = test()->postJson('/admin-api/catalog-product-create', pcPayload([
        'category_id' => $category->id,
        'brand' => 'A Brand That Does Not Exist',
        'category' => 'A Category That Does Not Exist',
    ]))->assertStatus(201)->json('id');

    $product = Product::findOrFail($id);

    // The unknown keys were ignored, not written and not turned into rows.
    expect($product->brand_id)->toBeNull()
        ->and(Brand::count())->toBe($brands, 'a brand was created as a side effect of saving a product')
        ->and(Category::count())->toBe($categories, 'a category was created as a side effect of saving a product');

    // And the relations still resolve, i.e. nothing put a string in them.
    expect($product->category?->id)->toBe($category->id);
});

/* ------------------------------------------------------- the status vocabulary */

/*
 * products.status is publish | draft | private. Read out of the Phase 0 schema
 * and out of ProductImporter::STATUS_MAP, not guessed. A sibling lane has just
 * finished paying for the other spelling: the old editor validated
 * `in:active,draft,archived`, so 'active' — the ONE value meaning "put this on
 * the shop" — wrote something nothing in the application recognises, and the
 * row left the storefront, every category page and the sitemap with
 * {"ok":true} in the response.
 */
it('refuses the status vocabulary that silently removes a product from the shop', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    foreach (['active', 'archived', 'published', 'Publish', ''] as $bad) {
        $response = pcCreate(['category_id' => $category->id, 'status' => $bad]);

        expect($response->getStatusCode())->toBe(422, "status '{$bad}' was accepted");
    }

    expect(Product::count())->toBe($before, 'a product was created with a status this schema has no concept of');
});

it('accepts each status the schema declares and puts the product where that status means', function () {
    asPcAdmin();

    $category = pcCategory('Status PC');

    $live = Product::findOrFail(
        pcCreate(['category_id' => $category->id, 'status' => 'publish'])->assertStatus(201)->json('id')
    );

    $draft = Product::findOrFail(
        pcCreate(['category_id' => $category->id, 'status' => 'draft'])->assertStatus(201)->json('id')
    );

    $private = Product::findOrFail(
        pcCreate(['category_id' => $category->id, 'status' => 'private'])->assertStatus(201)->json('id')
    );

    expect(pcIsLive($live, $category))->toBe([
        'visible_scope' => true, 'category_page' => true, 'sitemap' => true, 'product_page' => true,
    ]);

    // A "still visible" assertion that cannot go red is not an assertion, so
    // the two non-public statuses are checked to be absent from all four.
    expect(pcIsLive($draft, $category))->toBe([
        'visible_scope' => false, 'category_page' => false, 'sitemap' => false, 'product_page' => false,
    ]);

    expect(pcIsLive($private, $category))->toBe([
        'visible_scope' => false, 'category_page' => false, 'sitemap' => false, 'product_page' => false,
    ]);
});

/*
 * scopeVisible() is `status = 'publish' AND is_visible = 1`. Publishing with
 * catalogue visibility off produces a row that is published and invisible —
 * not on /shop, not on its category page, not in /sitemap.xml — while the
 * Products list shows it under the "Published" chip the whole time.
 */
it('refuses to create a published product that catalogue visibility would hide', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    $response = pcCreate([
        'category_id' => $category->id,
        'status' => 'publish',
        'is_visible' => false,
    ])->assertStatus(422);

    expect($response->json('errors.is_visible.0'))->toContain('not on the shop')
        ->and($response->json('errors.is_visible.0'))->toContain('sitemap')
        ->and(Product::count())->toBe($before);

    // The same product, saved the way the message suggests, is a legitimate
    // draft — the refusal is about the contradiction, not about the field.
    pcCreate([
        'category_id' => $category->id,
        'status' => 'draft',
        'is_visible' => false,
    ])->assertStatus(201);
});

/* ------------------------------------------------------------------- money */

it('parses an operator-typed price to the exact fil', function () {
    asPcAdmin();

    $category = pcCategory();

    // The three that break a float. (int) (1.15 * 100) is 114; 0.29 * 100 is
    // 28.999999999999996; 99.50 is the value the dead importer landed as 99.
    foreach ([
        ['99.50', 9950],
        ['1.15', 115],
        ['0.29', 29],
        ['19.99', 1999],
        ['100', 10000],
        ['0', 0],
    ] as [$typed, $fils]) {
        $id = pcCreate(['category_id' => $category->id, 'price' => $typed])->assertStatus(201)->json('id');

        expect((int) Product::findOrFail($id)->price)
            ->toBe($fils, "'{$typed}' did not land as {$fils} fils");
    }
});

it('parses a sale price to the exact fil and keeps it below the regular price', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'price' => '99.50',
        'sale_price' => '79.95',
    ])->assertStatus(201)->json('id');

    $product = Product::findOrFail($id);

    expect((int) $product->price)->toBe(9950)
        ->and((int) $product->sale_price)->toBe(7995)
        ->and($product->effectivePrice())->toBe(7995)
        ->and($product->isOnSale())->toBeTrue();

    // A "sale" at or above the regular price is not one, and Product::isOnSale()
    // would answer false while the screen showed a struck-through price.
    $before = Product::count();

    pcCreate(['category_id' => $category->id, 'price' => '50.00', 'sale_price' => '50.00'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The sale price has to be below the regular price.');

    pcCreate(['category_id' => $category->id, 'price' => '50.00', 'sale_price' => '60.00'])
        ->assertStatus(422);

    expect(Product::count())->toBe($before);
});

/*
 * `numeric` accepts "1e3", " 1.5 " and "0.145", and none of those survive a
 * digit-by-digit parse: "1e3" reads as 1 dirham and "0.145" is a price fils
 * cannot express being silently truncated. The rule is an anchored, digits-only
 * regex for exactly that reason.
 */
it('refuses money spellings that a digit-by-digit parse would silently mangle', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    foreach (['1e3', '1E3', '0.145', '-5.00', '99,50', 'AED 99.50', '+12', '1.2.3', '9.9.9', '١٢٣', ''] as $bad) {
        $response = pcCreate(['category_id' => $category->id, 'price' => $bad]);

        expect($response->getStatusCode())->toBe(422, "price '{$bad}' was accepted");
    }

    expect(Product::count())->toBe($before);

    /*
     * ' 1.5 ' is deliberately NOT on that list, and the reason is worth
     * stating: the `web` middleware group runs TrimStrings, so a padded value
     * is already '1.5' by the time the validator sees it. The anchored regex
     * is still what makes that safe — it is why the trimmed value is the only
     * spelling that can reach the parser — but asserting a 422 for it would be
     * asserting against middleware this lane does not own, and would go red
     * the day somebody legitimately changed it.
     */
    $trimmed = pcCreate(['category_id' => $category->id, 'price' => ' 1.5 '])->assertStatus(201)->json('id');

    expect((int) Product::findOrFail($trimmed)->price)->toBe(150);
});

/*
 * products.price is `$t->integer(...)` — signed 32-bit. MySQL in strict mode
 * raises 1264 on the insert; SQLite stores it happily and the two engines part
 * company in production. Caught here so the refusal is a 422 naming the ceiling
 * rather than a 500 naming a driver.
 */
it('refuses a price larger than the 32-bit column can hold', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    $response = pcCreate([
        'category_id' => $category->id,
        'price' => '21474836.48',
    ])->assertStatus(422);

    expect($response->json('errors.price.0'))->toContain('most a product can cost');

    // And the largest value that DOES fit is accepted, so the bound is a
    // ceiling rather than a blanket refusal of large numbers.
    $id = pcCreate([
        'category_id' => $category->id,
        'price' => '21474836.47',
    ])->assertStatus(201)->json('id');

    // 21474836.47 is MAX_FILS exactly, which is the assertion that the bound
    // is a ceiling rather than a blanket refusal of large numbers.
    expect((int) Product::findOrFail($id)->price)->toBe(ImportMoney::MAX_FILS)
        ->and(Product::count())->toBe($before + 1);
});

it('refuses a product with no price at all', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    $response = test()->postJson('/admin-api/catalog-product-create', array_merge(
        pcPayload(['category_id' => $category->id]),
        ['price' => null]
    ))->assertStatus(422);

    expect($response->json('errors.price.0'))->toContain('needs a price')
        ->and(Product::count())->toBe($before);
});

/* -------------------------------------------------------------------- slug */

it('shows the operator the real slug before they save, and says when it is taken', function () {
    asPcAdmin();

    $preview = test()->postJson('/admin-api/catalog-product-slug', [
        'name' => "Anua Heartleaf 77% Soothing Toner",
    ])->assertOk();

    // Str::slug on the server, not a JavaScript guess at it — the two disagree
    // on accents, on '&' and on every non-Latin character, and the preview
    // matching what is stored is the entire point of showing it.
    expect($preview->json('slug'))->toBe('anua-heartleaf-77-soothing-toner')
        ->and($preview->json('available'))->toBeTrue()
        ->and($preview->json('url'))->toBe('/product/anua-heartleaf-77-soothing-toner/');

    $category = pcCategory();

    pcCreate([
        'category_id' => $category->id,
        'name' => 'Anua Heartleaf 77% Soothing Toner',
    ])->assertStatus(201);

    $taken = test()->postJson('/admin-api/catalog-product-slug', [
        'name' => 'Anua Heartleaf 77% Soothing Toner',
    ])->assertOk();

    expect($taken->json('available'))->toBeFalse()
        ->and($taken->json('reason'))->toContain('already uses')
        // Offered, never applied behind the operator's back.
        ->and($taken->json('suggestion'))->toBe('anua-heartleaf-77-soothing-toner-2');
});

it('generates the slug from the name and lets the operator override it at create time', function () {
    asPcAdmin();

    $category = pcCategory();

    $generated = Product::findOrFail(
        pcCreate(['category_id' => $category->id, 'name' => 'Beet Panthenol Moisture Cream'])
            ->assertStatus(201)->json('id')
    );

    expect($generated->slug)->toBe('beet-panthenol-moisture-cream');

    // Typed by hand, and slugified rather than trusted.
    $chosen = Product::findOrFail(
        pcCreate([
            'category_id' => $category->id,
            'name' => 'Beet Panthenol Moisture Cream Refill',
            'slug' => 'Beet Cream Refill!!',
        ])->assertStatus(201)->json('id')
    );

    expect($chosen->slug)->toBe('beet-cream-refill')
        ->and(test()->get('/product/beet-cream-refill/')->getStatusCode())->toBe(200);
});

it('refuses a duplicate slug rather than 500ing on the unique index', function () {
    asPcAdmin();

    $category = pcCategory();

    pcCreate(['category_id' => $category->id, 'name' => 'Rice Toner'])->assertStatus(201);

    $before = Product::count();

    // The same name again. Without validating the DERIVED slug this is a
    // QueryException, i.e. a 500 on a duplicate name.
    pcCreate(['category_id' => $category->id, 'name' => 'Rice Toner'])
        ->assertStatus(422)
        ->assertJsonPath('errors.slug.0', 'Another product already uses that web address.');

    expect(Product::count())->toBe($before);
});

it('counts a trashed product as still owning its address', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate(['category_id' => $category->id, 'name' => 'Ginseng Serum'])->assertStatus(201)->json('id');

    Product::findOrFail($id)->delete();      // soft delete: the row, and the unique index entry, remain

    expect(Product::withTrashed()->whereKey($id)->exists())->toBeTrue();

    // The unique index underneath does not know about deleted_at, so a rule
    // that ignored trashed rows would hand the operator a 500 instead of a
    // message.
    pcCreate(['category_id' => $category->id, 'name' => 'Ginseng Serum'])->assertStatus(422);

    test()->postJson('/admin-api/catalog-product-slug', ['name' => 'Ginseng Serum'])
        ->assertOk()
        ->assertJsonPath('available', false);
});

it('refuses a name that cannot make a web address at all', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    // Str::slug() of punctuation is the empty string, and /product//  is not an
    // address.
    $response = pcCreate(['category_id' => $category->id, 'name' => '!!! ???'])->assertStatus(422);

    expect($response->json('errors.slug.0'))->toContain('web address')
        ->and(Product::count())->toBe($before);

    test()->postJson('/admin-api/catalog-product-slug', ['name' => '!!!'])
        ->assertOk()
        ->assertJsonPath('available', false);
});

/*
 * A slug already handed to Google and printed in customers' order histories is
 * not a text box. This asserts the PROPERTY — that no registered write path
 * accepts one — rather than the absence of a line of code, because the latter
 * would keep passing if a slug key were added somewhere else.
 */
it('never lets an existing product change its slug', function () {
    asPcAdmin();
    Tests\Support\CatalogProductsAdminRoutes::wire(app());

    $category = pcCategory();

    $id = pcCreate(['category_id' => $category->id, 'name' => 'Cica Balm'])->assertStatus(201)->json('id');
    $original = Product::findOrFail($id)->slug;

    // Lane AF's save endpoint — the only write path an existing product has.
    test()->postJson('/admin-api/catalog-products-save/'.$id, [
        'slug' => 'cica-balm-renamed',
        'name' => 'Cica Balm Renamed',
    ])->assertOk();

    expect(Product::findOrFail($id)->slug)->toBe($original, 'the slug was editable after creation');

    // And the image endpoint this lane adds does not quietly accept one either.
    test()->postJson('/admin-api/catalog-product-image/'.$id, [
        'image' => 'https://cdn.test/x.png',
        'slug' => 'cica-balm-renamed-again',
    ])->assertOk();

    expect(Product::findOrFail($id)->slug)->toBe($original);

    // The old address still answers, which is what all of this is protecting.
    expect(test()->get('/product/'.$original.'/')->getStatusCode())->toBe(200);
});

/* ------------------------------------------------------------------ images */

it('stores an image on a new product and shows it on the product page', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'image' => 'https://cdn.test/uploads/products/essence.jpg',
    ])->assertStatus(201)->json('id');

    $product = Product::findOrFail($id);

    expect($product->image)->toBe('https://cdn.test/uploads/products/essence.jpg');

    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)->toContain('https://cdn.test/uploads/products/essence.jpg');
});

it('replaces and removes the image on a product that already exists', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'image' => 'https://cdn.test/first.jpg',
    ])->assertStatus(201)->json('id');

    // Replace.
    test()->postJson('/admin-api/catalog-product-image/'.$id, [
        'image' => '/uploads/products/20260915-abc.png',
    ])->assertOk()->assertJsonPath('image', '/uploads/products/20260915-abc.png');

    expect(Product::findOrFail($id)->image)->toBe('/uploads/products/20260915-abc.png');

    // Remove. `present` rather than `required` in the validator is what makes
    // this expressible at all — on a `sometimes` rule, "not sent" and "set to
    // null" are the same request and there is no way to say "take the picture
    // off".
    test()->postJson('/admin-api/catalog-product-image/'.$id, ['image' => null])
        ->assertOk()
        ->assertJsonPath('image', null);

    expect(Product::findOrFail($id)->image)->toBeNull();

    // Not sending the key at all is a mistake, not a removal.
    test()->postJson('/admin-api/catalog-product-image/'.$id, [])->assertStatus(422);

    test()->postJson('/admin-api/catalog-product-image/999999', ['image' => null])->assertStatus(404);
});

/*
 * `javascript:` in an <img src> is inert, but `data:` is not — a data: URL of
 * type image/svg+xml renders as a document and can carry script, which is the
 * stored-XSS shape MediaUploadController already refuses for uploaded SVG. The
 * same rule, and the same sentence, as BrandsApiController::safeLogoUrl().
 */
it('refuses an image address that is not safe as an img src', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'image' => 'https://cdn.test/safe.jpg',
    ])->assertStatus(201)->json('id');

    foreach ([
        'javascript:alert(1)',
        'data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+',
        'vbscript:msgbox(1)',
        '/uploads/../../../../etc/passwd',
        'file:///etc/passwd',
    ] as $bad) {
        $create = pcCreate(['category_id' => $category->id, 'image' => $bad]);
        expect($create->getStatusCode())->toBe(422, "create accepted image '{$bad}'");

        $set = test()->postJson('/admin-api/catalog-product-image/'.$id, ['image' => $bad]);
        expect($set->getStatusCode())->toBe(422, "image endpoint accepted '{$bad}'");
    }

    // And none of that replaced the image that was already there.
    expect(Product::findOrFail($id)->image)->toBe('https://cdn.test/safe.jpg');
});

/* --------------------------------------------- the one upload path, by content */

it('uses the one existing upload endpoint rather than adding a second', function () {
    CatalogProductCreateRoutes::wire(app());

    /*
     * Exactly one IMAGE upload route in the whole application, and it is the
     * one that already existed.
     *
     * The other two are not image paths and are deliberately named here rather
     * than filtered out by a looser pattern: admin-api/import/upload takes the
     * WooCommerce export files and admin/updates/upload takes a signed update
     * package. Listing them means this assertion goes red when a THIRD kind of
     * upload appears, which is the event worth being told about — a regex that
     * merely excluded them would not notice.
     */
    $uploads = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'upload'))
        ->map(fn ($r) => $r->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($uploads)->toBe([
        'admin-api/import/upload',      // WooCommerce export files
        'admin-api/media/upload',       // every image in the admin, including this lane's
        'admin/updates/upload',         // signed update packages
    ]);

    // And none of this lane's own routes is one of them.
    foreach (CatalogProductCreateRoutes::registered() as $route) {
        expect($route->uri())->not->toContain('upload');
    }

    // The screen posts to it by that exact name, so the wiring is greppable.
    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($view)->toContain('/admin-api/media/upload');
});

/**
 * A real UploadedFile over real bytes, NOT UploadedFile::fake().
 *
 * Illuminate\Http\Testing\File::getMimeType() returns
 * `MimeType::from($this->name)` — the type implied by the FILENAME. A test
 * built on the fake therefore cannot tell a content check from a name check,
 * and would pass just as happily against the defect this endpoint had. These
 * files have genuine bytes on disk and genuine, sometimes lying, names.
 */
function pcUpload(string $name, string $bytes): \Illuminate\Http\UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pcup');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

/**
 * Empty public/uploads/products, and answer what is in it.
 *
 * The upload endpoint writes straight into the real public web root — there is
 * no storage:link on this host, see MediaUploadController's class comment — so
 * a test that uploads a file leaves a file behind. Every upload test below
 * starts from empty and asserts against a count taken in the same test, rather
 * than against "the directory is empty", which is only true until some other
 * test in the same run has uploaded something legitimately.
 *
 * @return list<string>
 */
function pcUploads(bool $clear = false): array
{
    $dir = public_path('uploads/products');

    $files = array_values(array_filter(glob($dir.'/*') ?: [], 'is_file'));

    if ($clear) {
        foreach ($files as $file) {
            @unlink($file);
        }

        return [];
    }

    return $files;
}

function pcPngBytes(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('accepts a real image and stores it under the extension its bytes say it is', function () {
    asPcAdmin();

    pcUploads(true);

    // A genuine PNG that the operator happened to name .jpg — a completely
    // ordinary mistake, and the file must still be stored as, and served as,
    // what it really is.
    $response = test()->post('/admin-api/media/upload', [
        'file' => pcUpload('holiday-photo.jpg', pcPngBytes()),
        'folder' => 'products',
    ])->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('filename'))->toEndWith('.png')
        ->and($response->json('url'))->toContain('/uploads/products/');

    pcUploads(true);
});

/*
 * THE DEFECT THIS CLOSES. The accept check read the file's bytes; the stored
 * extension and the decision to run the SVG safety scan both read the file's
 * NAME. A hostile SVG uploaded as `photo.png` passed the accept check as
 * image/svg+xml, skipped the scan entirely because the name did not end .svg,
 * and was written into the public web root.
 */
it('runs the SVG safety scan on the bytes, not on the filename', function () {
    asPcAdmin();

    pcUploads(true);

    $hostile = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    $response = test()->post('/admin-api/media/upload', [
        'file' => pcUpload('innocent-photo.png', $hostile),
        'folder' => 'products',
    ])->assertStatus(422);

    expect($response->json('ok'))->toBeFalse()
        // The message says what was wrong, not merely that something was.
        ->and($response->json('message'))->toContain('script element');

    // Nothing was written into the web root.
    expect(pcUploads())->toBe([], 'a hostile SVG reached the public web root');

    // The same scan still catches it under its own name, which is the case
    // that already worked and must keep working.
    test()->post('/admin-api/media/upload', [
        'file' => pcUpload('hostile.svg', $hostile),
        'folder' => 'products',
    ])->assertStatus(422);

    // And a clean SVG is still accepted — the rule is about scripting, not
    // about the format.
    $clean = test()->post('/admin-api/media/upload', [
        'file' => pcUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8"/></svg>'),
        'folder' => 'products',
    ])->assertOk();

    expect($clean->json('filename'))->toEndWith('.svg')
        ->and(pcUploads())->toHaveCount(1);

    pcUploads(true);
});

it('refuses a file that is not an image and says what it actually was', function () {
    asPcAdmin();
    pcUploads(true);

    $response = test()->post('/admin-api/media/upload', [
        'file' => pcUpload('price-list.png', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n"),
        'folder' => 'products',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('application/pdf')
        ->and($response->json('message'))->toContain('JPG, PNG, WebP, GIF or SVG');

    expect(pcUploads())->toBe([], 'a refused file still reached the public web root');
});

it('caps the upload size and says the cap in the units the operator sees', function () {
    asPcAdmin();
    pcUploads(true);

    // 6MB of a genuine PNG header followed by filler: over the 5MB cap.
    // The Accept header matters: without it a validation failure is a 302 back
    // to a form this API has no concept of, and the operator's screen sees a
    // redirect instead of a reason.
    $response = test()->post('/admin-api/media/upload', [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('huge.png', 6 * 1024, 'image/png'),
        'folder' => 'products',
    ], ['Accept' => 'application/json'])->assertStatus(422);

    expect($response->json('errors.file.0'))->toContain('5MB');

    expect(pcUploads())->toBe([], 'an oversized file still reached the public web root');
});

/* -------------------------------------------------- the rest of the fields */

it('keeps the descriptions, the SKU and the stock exactly as typed', function () {
    asPcAdmin();

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'sku' => 'ANUA-HEART-77',
        'short_description' => 'Soothing toner for sensitive skin.',
        'description' => '<p>77% heartleaf extract.</p>',
        'manage_stock' => true,
        'stock' => 42,
        'stock_status' => 'onbackorder',
    ])->assertStatus(201)->json('id');

    $product = Product::findOrFail($id);

    expect($product->sku)->toBe('ANUA-HEART-77')
        ->and($product->short_description)->toBe('Soothing toner for sensitive skin.')
        ->and($product->description)->toBe('<p>77% heartleaf extract.</p>')
        ->and((bool) $product->manage_stock)->toBeTrue()
        ->and((int) $product->stock)->toBe(42)
        ->and($product->stock_status)->toBe('onbackorder')
        // Not typed over, not invented: a product born here has no Woo id.
        ->and($product->wc_id)->toBeNull()
        ->and((int) $product->total_sales)->toBe(0)
        ->and((int) $product->review_count)->toBe(0);
});

it('refuses a stock status this schema has no concept of', function () {
    asPcAdmin();

    $category = pcCategory();
    $before = Product::count();

    foreach (['in_stock', 'available', 'sold-out', ''] as $bad) {
        expect(pcCreate(['category_id' => $category->id, 'stock_status' => $bad])->getStatusCode())
            ->toBe(422, "stock_status '{$bad}' was accepted");
    }

    expect(Product::count())->toBe($before);
});

it('shows a newly created product on the Products list it was created from', function () {
    asPcAdmin();
    Tests\Support\CatalogProductsAdminRoutes::wire(app());

    $category = pcCategory();

    $id = pcCreate([
        'category_id' => $category->id,
        'name' => 'Propolis Ampoule',
        'price' => '145.00',
    ])->assertStatus(201)->json('id');

    $rows = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json('products');

    $row = collect($rows)->firstWhere('id', $id);

    expect($row)->not->toBeNull('the new product is not on the list screen that created it')
        ->and($row['name'])->toBe('Propolis Ampoule')
        // The list formats money from the same integer fils this lane stored.
        ->and($row['price_input'])->toBe('145.00');
});

/* ----------------------------------------------------- the screen is wired */

it('replaces the preview Add product button with one that opens the real form', function () {
    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The mock this lane replaces: openProduct(-1) reached a form whose every
    // control raised toast('… (preview)').
    expect($view)->toContain('cpOpenCreate')
        ->and($view)->toContain('/admin-api/catalog-product-create')
        ->and($view)->toContain('/admin-api/catalog-product-slug')
        ->and($view)->toContain('/admin-api/catalog-product-image/');

    // The Add product button on the Catalog header no longer reaches the mock.
    expect($view)->not->toContain('onclick="openProduct(-1)"');
});
