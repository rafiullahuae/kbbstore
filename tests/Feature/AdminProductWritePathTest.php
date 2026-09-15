<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * The product editor's write path: PUT /admin-api/products/{id}.
 *
 * Four defects lived in one method, and three of them answered HTTP 200.
 *
 *   THE STATUS VOCABULARY. `products.status` is publish | draft | private —
 *   the Phase 0 schema declares it, and Product::scopeVisible() filters on
 *   `status = 'publish'`. The validator accepted `active`, `draft`, `archived`.
 *   `active` was the ONLY non-draft value an operator could send, so the one
 *   action that means "put this product on the shop" wrote a value nothing in
 *   the application recognises and the row silently left the storefront, every
 *   category page and the sitemap, with {"ok":true} in the response.
 *
 *   These tests assert that CONSEQUENCE, not the rule string. A test that
 *   checks `in:publish,draft,private` is in the validator would keep passing if
 *   somebody changed the rule and scopeVisible() together, which is the exact
 *   regression worth catching. So the assertions are: after a save, is the row
 *   still returned by Product::visible(), is it still on its category page, is
 *   it still in /sitemap.xml. The draft case is asserted too — a "still
 *   visible" test that cannot go red is not a test.
 *
 *   RELATIONS WRITTEN AS COLUMNS. `$product->brand = 'Anua'` does not touch the
 *   belongsTo; it puts a string in the attribute bag and save() issues
 *   `UPDATE products SET brand = ?`. No such column: SQLSTATE 42S22, HTTP 500.
 *   The live editor in resources/views/admin/app.blade.php sends `brand` on
 *   every save, so this fired whenever the Brands box was on screen and the
 *   operator saw "Save failed — check connection".
 *
 *   MONEY. `price_aed` was validated `integer`, so AED 99.50 was a 422 going in
 *   and `(int) round($price / 100)` rounded it to 100 coming out — which the
 *   editor then saved back as 10000, making the product 50 fils dearer every
 *   time somebody opened it. Money is integer fils; a value typed by an
 *   operator is parsed digit by digit and asserted to the exact fil here.
 *
 *   IDENTITY AND COMPUTED COLUMNS. slug and wc_id are live URL contracts and
 *   total_sales / rating / review_count are derived. None may be typed over.
 */

/* ------------------------------------------------------------------ fixtures */

function apwAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'APW Owner',
        'email' => 'apw-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asApwAdmin(): void
{
    test()->actingAs(apwAdmin(), 'admin');
}

function apwBrand(string $name = 'APW Brand'): Brand
{
    return Brand::create(['name' => $name, 'slug' => 'apw-brand-'.uniqid()]);
}

function apwCategory(string $name = 'APW Category'): Category
{
    return Category::create(['name' => $name, 'slug' => 'apw-cat-'.uniqid()]);
}

/**
 * A live, visible product in a category — the shape the storefront assertions
 * need. Seeded at an exact number of fils; nothing here rounds.
 */
function apwProduct(array $attributes = [], ?Category $category = null): Product
{
    static $n = 0;
    $n++;

    $product = Product::create(array_merge([
        'name' => 'APW Product '.$n,
        'slug' => 'apw-product-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $attributes));

    if ($category !== null) {
        // The category archive filters through the many-to-many, not
        // category_id — see ShopController::index().
        $product->categories()->sync([$category->id]);
        $product->category_id = $category->id;
        $product->save();
    }

    return $product;
}

/** Is this product on its category page right now? */
function apwOnCategoryPage(Product $product, Category $category): bool
{
    $html = test()->get('/product-category/'.$category->slug.'/')->getContent();

    return str_contains((string) $html, $product->slug);
}

/** Is this product in the sitemap right now? */
function apwInSitemap(Product $product): bool
{
    $xml = test()->get('/sitemap.xml')->getContent();

    return str_contains((string) $xml, '/product/'.$product->slug.'/');
}

/** Everything the storefront uses to decide whether a product exists. */
function apwIsLive(Product $product, Category $category): array
{
    return [
        'visible_scope' => Product::visible()->whereKey($product->id)->exists(),
        'category_page' => apwOnCategoryPage($product, $category),
        'sitemap' => apwInSitemap($product),
    ];
}

/* --------------------------------------------------- the status vocabulary */

it('keeps a product on the storefront when it is saved through the editor', function () {
    asApwAdmin();

    $category = apwCategory('Serums APW');
    $product = apwProduct([], $category);

    // The product is live before the save, or this test proves nothing.
    expect(apwIsLive($product, $category))->toBe([
        'visible_scope' => true,
        'category_page' => true,
        'sitemap' => true,
    ]);

    // A save through the editor, setting the status the operator means by
    // "this product is on the shop".
    test()->putJson('/admin-api/products/'.$product->id, [
        'name' => 'APW Renamed',
        'status' => 'publish',
    ])->assertOk();

    expect(Product::find($product->id)->status)->toBe('publish');

    /*
     * THE ASSERTION THIS FILE EXISTS FOR.
     *
     * Not "the validator contains publish" — the end-to-end consequence, read
     * from the three places a shopper or a crawler would look. Against the
     * unfixed method these are all three false, because `active` was the only
     * non-draft value it accepted and scopeVisible() wants `publish`.
     */
    expect(apwIsLive($product, $category))->toBe([
        'visible_scope' => true,
        'category_page' => true,
        'sitemap' => true,
    ]);
});

it('never lets a publish attempt end with the product off the shop', function () {
    asApwAdmin();

    $category = apwCategory();
    $product = apwProduct([], $category);

    expect(apwIsLive($product, $category))->toBe([
        'visible_scope' => true,
        'category_page' => true,
        'sitemap' => true,
    ]);

    /*
     * THE LIVE DEFECT, REPRODUCED EXACTLY.
     *
     * `active` was the ONLY non-draft value the old validator accepted, so it
     * is what an operator pressing Publish would have sent. Against the unfixed
     * method this answered {"ok":true} with HTTP 200 and the product silently
     * left Product::visible(), its category page and the sitemap — the store
     * lost a product and nothing anywhere said so.
     *
     * Either outcome is acceptable now and both are asserted together: the
     * request is REFUSED, or it succeeds and the product is still on the shop.
     * What is never acceptable is 200-and-gone, which is what this pins. Written
     * this way on purpose — it holds whichever way a future lane decides to
     * treat the legacy value, and it cannot be satisfied by a validator change
     * alone.
     */
    $response = test()->putJson('/admin-api/products/'.$product->id, ['status' => 'active']);

    if ($response->getStatusCode() < 300) {
        expect(apwIsLive($product, $category))->toBe([
            'visible_scope' => true,
            'category_page' => true,
            'sitemap' => true,
        ], 'a save that answered ' . $response->getStatusCode() . ' took the product off the storefront');
    } else {
        expect($response->getStatusCode())->toBe(422);

        // And a refusal leaves the row exactly as it was.
        expect(apwIsLive($product, $category))->toBe([
            'visible_scope' => true,
            'category_page' => true,
            'sitemap' => true,
        ]);
    }

    // Belt and braces: whatever happened, the stored status is one the schema
    // and Product::scopeVisible() both recognise.
    expect(Product::find($product->id)->status)->toBeIn(['publish', 'draft', 'private']);
});

it('refuses the status vocabulary the schema has no concept of', function () {
    asApwAdmin();

    $category = apwCategory();
    $product = apwProduct([], $category);

    foreach (['active', 'archived', 'published', 'live'] as $bogus) {
        test()->putJson('/admin-api/products/'.$product->id, ['status' => $bogus])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // And the row is untouched, so a refused save cannot half-apply.
        expect(Product::find($product->id)->status)->toBe('publish', $bogus.' was written anyway');
    }

    // Still on the shop after four refused saves.
    expect(apwIsLive($product, $category))->toBe([
        'visible_scope' => true,
        'category_page' => true,
        'sitemap' => true,
    ]);
});

it('takes a product off the storefront when it is actually set to draft', function () {
    asApwAdmin();

    $category = apwCategory();
    $product = apwProduct([], $category);

    test()->putJson('/admin-api/products/'.$product->id, ['status' => 'draft'])->assertOk();

    // The other half of the pin: if this could not go false, the test above
    // would pass against a method that hard-coded 'publish'.
    expect(apwIsLive($product, $category))->toBe([
        'visible_scope' => false,
        'category_page' => false,
        'sitemap' => false,
    ]);
});

it('accepts every status the column actually has', function () {
    asApwAdmin();

    $product = apwProduct();

    foreach (['publish', 'draft', 'private'] as $status) {
        test()->putJson('/admin-api/products/'.$product->id, ['status' => $status])->assertOk();
        expect(Product::find($product->id)->status)->toBe($status);
    }
});

/* ------------------------------------------------ brand and category relations */

it('saves a brand and a category sent as names, the way the editor sends them', function () {
    asApwAdmin();

    $brand = apwBrand('Anua APW');
    $category = apwCategory('Cleansers APW');
    $product = apwProduct();

    /*
     * This is the exact payload resources/views/admin/app.blade.php builds:
     * `payload.brand = brandSel.value` and `payload.category = cat`, both
     * display NAMES out of the pickers. Against the unfixed method this was
     * `UPDATE products SET brand = 'Anua APW'`, SQLSTATE 42S22, HTTP 500 — and
     * the editor's catch turned it into "Save failed — check connection".
     */
    test()->putJson('/admin-api/products/'.$product->id, [
        'brand' => 'Anua APW',
        'category' => 'Cleansers APW',
    ])->assertOk();

    $fresh = Product::find($product->id);

    // Written to the foreign keys, which is where the relations live.
    expect($fresh->brand_id)->toBe($brand->id)
        ->and($fresh->category_id)->toBe($category->id)
        ->and($fresh->brand->name)->toBe('Anua APW')
        ->and($fresh->category->name)->toBe('Cleansers APW');

    // And no stray attribute got written to a column of that name.
    $columns = array_keys((array) DB::table('products')->where('id', $product->id)->first());
    expect($columns)->not->toContain('brand')->not->toContain('category');
});

it('saves a brand and a category sent as ids, which is the form the rest of the admin uses', function () {
    asApwAdmin();

    $brand = apwBrand();
    $category = apwCategory();
    $product = apwProduct();

    test()->putJson('/admin-api/products/'.$product->id, [
        'brand_id' => $brand->id,
        'category_id' => $category->id,
    ])->assertOk();

    $fresh = Product::find($product->id);

    expect($fresh->brand_id)->toBe($brand->id)
        ->and($fresh->category_id)->toBe($category->id);
});

it('clears a brand and a category rather than writing an empty string', function () {
    asApwAdmin();

    $product = apwProduct(['brand_id' => apwBrand()->id, 'category_id' => apwCategory()->id]);

    test()->putJson('/admin-api/products/'.$product->id, [
        'brand' => '',
        'category_id' => null,
    ])->assertOk();

    $fresh = Product::find($product->id);

    expect($fresh->brand_id)->toBeNull()->and($fresh->category_id)->toBeNull();
});

it('refuses a brand or category that is not in the catalogue instead of inventing one', function () {
    asApwAdmin();

    $product = apwProduct();
    $brandsBefore = Brand::count();
    $categoriesBefore = Category::count();

    test()->putJson('/admin-api/products/'.$product->id, ['brand' => 'Anuaa'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_brand');

    test()->putJson('/admin-api/products/'.$product->id, ['category' => 'Sreums'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_category');

    // A typo in a product save must never grow the taxonomy.
    expect(Brand::count())->toBe($brandsBefore)
        ->and(Category::count())->toBe($categoriesBefore);

    $fresh = Product::find($product->id);
    expect($fresh->brand_id)->toBeNull()->and($fresh->category_id)->toBeNull();
});

it('does not hand the editor a whole brand row where it asked for a name', function () {
    asApwAdmin();

    $brand = apwBrand('Beauty of Joseon APW');
    $product = apwProduct(['brand_id' => $brand->id]);

    $body = test()->getJson('/admin-api/products/'.$product->id)->assertOk()->json();

    /*
     * `brand` used to be `$p->brand`, the belongsTo — so this endpoint
     * serialised the entire brands row, importer bookkeeping and all, into a
     * field the screen puts in a <select>.
     */
    expect($body['brand'])->toBe('Beauty of Joseon APW')
        ->and($body['brand_id'])->toBe($brand->id)
        ->and($body['brand'])->toBeString();
});

/* --------------------------------------------------------------------- money */

it('saves a price typed as a decimal string to the exact fil', function () {
    asApwAdmin();

    $product = apwProduct();

    /*
     * Exact fils, asserted as integers. `(int) (1.15 * 100)` is 114 and
     * `(int) (0.29 * 100)` is 28: a store that is a fil light on every
     * hundredth product is a store whose books do not add up. Nothing in the
     * path below constructs a float.
     */
    $cases = [
        '99.50' => 9950,
        '1.15' => 115,
        '0.29' => 29,
        '19.99' => 1999,
        '0.05' => 5,
        '250' => 25000,
        '99.5' => 9950,
        '7.10' => 710,
    ];

    foreach ($cases as $typed => $expectedFils) {
        test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => (string) $typed])->assertOk();

        expect(Product::find($product->id)->price)
            ->toBe($expectedFils, 'AED '.$typed.' should be '.$expectedFils.' fils');
    }
});

it('lets an operator enter a price that is not a whole dirham at all', function () {
    asApwAdmin();

    $product = apwProduct();

    // The old rule was `integer`, so this exact request was a 422 and AED 99.50
    // could not be typed into this store's admin.
    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => '99.50'])->assertOk();

    expect(Product::find($product->id)->price)->toBe(9950);
});

it('does not make a product dearer every time the editor opens it', function () {
    asApwAdmin();

    // A real price that is not a whole dirham.
    $product = apwProduct(['price' => 9950]);

    /*
     * The round trip that was corrupting data: the list and detail endpoints
     * rounded 9950 fils to 100 AED, the editor put 100 in its price box, and
     * saving the form back without touching the price wrote 10000.
     */
    $detail = test()->getJson('/admin-api/products/'.$product->id)->assertOk()->json();

    /*
     * The corruption itself, asserted through the KEYS THAT ALREADY EXISTED,
     * so this goes red on the round trip rather than on a missing field: read
     * the price the editor is handed, send that exact value straight back, and
     * the stored price must not have moved. Against the unfixed pair of methods
     * the read answers 100 and the write stores 10000 — a 50-fil rise for
     * opening a record and pressing Update.
     */
    test()->putJson('/admin-api/products/'.$product->id, [
        'price_aed' => (string) $detail['price_aed'],
    ])->assertOk();

    expect(Product::find($product->id)->price)
        ->toBe(9950, 'the price moved on a save that changed nothing: the editor was handed '
            .var_export($detail['price_aed'], true).' for a product stored at 9950 fils');

    // And the exact integer is published beside the major-unit value, which is
    // the pair /admin-api/catalog/products already answers with.
    expect($detail['price_fils'])->toBe(9950)
        ->and((float) $detail['price_aed'])->toBe(99.5);

    $listRow = collect(test()->getJson('/admin-api/products')->assertOk()->json('products'))
        ->firstWhere('id', $product->id);

    expect($listRow['price_fils'])->toBe(9950)
        ->and((float) $listRow['price_aed'])->toBe(99.5);
});

it('clears a sale price rather than writing zero', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000, 'sale_price' => 8000]);

    test()->putJson('/admin-api/products/'.$product->id, ['sale_aed' => null])->assertOk();

    expect(Product::find($product->id)->sale_price)->toBeNull();
});

it('refuses a sale price that is not a discount', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000, 'sale_price' => null]);

    // Equal is not a discount.
    test()->putJson('/admin-api/products/'.$product->id, ['sale_aed' => '100'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'sale_not_a_discount');

    // Above is certainly not.
    test()->putJson('/admin-api/products/'.$product->id, ['sale_aed' => '150.75'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'sale_not_a_discount');

    expect(Product::find($product->id)->sale_price)->toBeNull();

    // A fil below the regular price is.
    test()->putJson('/admin-api/products/'.$product->id, ['sale_aed' => '99.99'])->assertOk();

    expect(Product::find($product->id)->sale_price)->toBe(9999);
});

it('checks the sale price against the price arriving in the same request', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000]);

    // Both in one payload: the comparison has to use the incoming regular
    // price, not the stored one.
    test()->putJson('/admin-api/products/'.$product->id, [
        'price_aed' => '50',
        'sale_aed' => '80',
    ])->assertStatus(422)->assertJsonPath('error', 'sale_not_a_discount');

    expect(Product::find($product->id)->price)->toBe(10000, 'the refused request wrote the price anyway');
});

it('refuses a sale price on a product that has no regular price', function () {
    asApwAdmin();

    $product = apwProduct(['price' => null]);

    test()->putJson('/admin-api/products/'.$product->id, ['sale_aed' => '10'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'sale_without_price');
});

it('refuses a price that is not a decimal number', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000]);

    foreach (['abc', '10,50', '-5', '1e3', '99.50.25'] as $bogus) {
        test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => $bogus])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_aed');
    }

    expect(Product::find($product->id)->price)->toBe(10000);
});

it('refuses a price larger than the money column can hold', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000]);

    /*
     * products.price is a signed 32-bit integer of fils, so the ceiling is
     * AED 21,474,836.47. MySQL in strict mode raises on the insert and SQLite
     * stores it happily — the kind of gap that keeps a suite green while
     * production 500s — so it is refused here, on both engines, before the
     * write.
     */
    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => '999999999'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'money_out_of_range');

    expect(Product::find($product->id)->price)->toBe(10000);

    // And the largest value that DOES fit is accepted, to the exact fil.
    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => '21474836.47'])->assertOk();

    expect(Product::find($product->id)->price)->toBe(2147483647);
});

/* ------------------------------------------- identity and computed columns */

it('will not let identity or computed columns be typed over', function () {
    asApwAdmin();

    $product = apwProduct(['price' => 10000]);
    $product->forceFill(['wc_id' => 987654, 'total_sales' => 42, 'rating' => 4.5, 'review_count' => 7])->save();

    $before = Product::find($product->id);

    foreach ([
        'slug' => 'a-slug-somebody-typed',
        'wc_id' => 111,
        'total_sales' => 9999,
        'rating' => 5,
        'review_count' => 500,
    ] as $field => $value) {
        test()->putJson('/admin-api/products/'.$product->id, [$field => $value, 'name' => 'Also renamed'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'read_only');
    }

    $after = Product::find($product->id);

    /*
     * Refused, not silently dropped. validate() would have discarded these
     * keys and answered {"ok":true}, which is indistinguishable from a write
     * that worked — and is how a caller keeps sending one for months.
     */
    expect($after->slug)->toBe($before->slug)
        ->and((int) $after->wc_id)->toBe(987654)
        ->and((int) $after->total_sales)->toBe(42)
        ->and((float) $after->rating)->toBe(4.5)
        ->and((int) $after->review_count)->toBe(7)
        // The rest of the payload does not half-apply either.
        ->and($after->name)->toBe($before->name);
});

/* ----------------------------------------------------------------- SEO blob */

it('writes the product SEO fields to the column that actually holds them', function () {
    asApwAdmin();

    $product = apwProduct();

    test()->putJson('/admin-api/products/'.$product->id, [
        'seo' => ['seo_title' => 'APW SEO title', 'meta_description' => 'APW SEO description'],
    ])->assertOk();

    /*
     * `seo`, the json column — and this assertion is the inverse of what it
     * used to be.
     *
     * `products` carries BOTH a `seo` json column (Phase 0, the Yoast import
     * target) and a `seo_json` text column (2026_07_11_000001). This test
     * previously asserted the write landed in `seo_json` and that `seo` stayed
     * null, on the stated grounds that `seo_json` was "the one this editor has
     * always read and written". That was true and it was the bug: the editor
     * was the ONLY thing at either end of `seo_json`. Every reader in the
     * application is on `seo` —
     *
     *     Store\ProductController::show()      builds the real <head>
     *     Admin\SchemaInspectorApiController   the JSON-LD preview
     *     Admin\CatalogueAuditApiController    the SEO health report
     *
     * — so the operator's meta title round-tripped through this endpoint,
     * displayed correctly in the admin, and never reached Google. A green test
     * pinned it there.
     *
     * The keys are asserted as the STOREFRONT's vocabulary too, because the two
     * differed as well: the panel sends Yoast-shaped names and the storefront
     * reads `title` and `desc`. App\Support\ProductSeo owns that mapping.
     */
    $row = DB::table('products')->where('id', $product->id)->first();

    expect(json_decode((string) $row->seo, true))
        ->toBe(['title' => 'APW SEO title', 'desc' => 'APW SEO description']);

    // And it round-trips back out.
    expect(test()->getJson('/admin-api/products/'.$product->id)->assertOk()->json('seo'))
        ->toBe(['title' => 'APW SEO title', 'desc' => 'APW SEO description']);
});

it('publishes a saved SEO title and description into the storefront head', function () {
    /*
     * The end-to-end half, and the one the previous test could not have caught.
     * Asserting which column a value lands in is a structural claim; this asks
     * the only question that matters — after the owner types a meta title into
     * the admin, does the product page say it?
     */
    asApwAdmin();

    $product = apwProduct();

    test()->putJson('/admin-api/products/'.$product->id, [
        'seo' => [
            'seo_title' => 'Glow Serum | Best price in Dubai',
            'meta_description' => 'Authentic Korean glow serum, delivered across the UAE.',
        ],
    ])->assertOk();

    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)
        ->toContain('Glow Serum | Best price in Dubai')
        ->toContain('Authentic Korean glow serum, delivered across the UAE.');
});

/* ------------------------------------------------------------ the guard itself */

it('answers nobody who is not a signed-in admin', function () {
    $product = apwProduct();

    test()->putJson('/admin-api/products/'.$product->id, ['status' => 'draft'])->assertStatus(401);
    test()->getJson('/admin-api/products/'.$product->id)->assertStatus(401);

    // A signed-in SHOPPER is not an admin either.
    test()->actingAs(Customer::create([
        'name' => 'APW Shopper',
        'email' => 'apw-shopper-'.uniqid().'@example.test',
    ]), 'customer');

    test()->putJson('/admin-api/products/'.$product->id, ['status' => 'draft'])->assertStatus(401);

    expect(Product::find($product->id)->status)->toBe('publish');
});

it('answers 404 for a product that is not there', function () {
    asApwAdmin();

    test()->getJson('/admin-api/products/99999999')->assertStatus(404);
    test()->putJson('/admin-api/products/99999999', ['status' => 'draft'])->assertStatus(404);
});

/* ------------------------------------- the rest of the audit, same three classes */

it('lets a review carrying the schema status be moderated, and counts it', function () {
    asApwAdmin();

    $product = apwProduct();

    /*
     * `reviews.status` is declared pending | approved | spam. This endpoint has
     * only ever written `rejected`, a fourth value, and refused `spam` — so a
     * review that arrived from the importer carrying the schema's own value
     * could not be moderated at all and showed on no chip.
     */
    $spam = Review::create([
        'product_id' => $product->id,
        'author_name' => 'APW Bot',
        'rating' => 1,
        'title' => 'buy followers',
        'content' => 'spam body',
        'status' => 'spam',
    ]);

    $counts = test()->getJson('/admin-api/reviews')->assertOk()->json('counts');

    expect($counts['spam'])->toBeGreaterThanOrEqual(1)
        // Folded into the existing chip, so "not approved, not waiting" still
        // means what the screen says it means.
        ->and($counts['rejected'])->toBeGreaterThanOrEqual(1);

    // And it can be moved out of spam, which used to be a 422.
    test()->putJson('/admin-api/reviews/'.$spam->id, ['status' => 'approved'])->assertOk();

    expect(Review::find($spam->id)->status)->toBe('approved');
});

it('shows the emirate and address on an order from the order s own snapshot', function () {
    asApwAdmin();

    $customer = Customer::create([
        'name' => 'APW Buyer',
        'email' => 'apw-buyer-'.uniqid().'@example.test',
        'phone' => '+971500000000',
    ]);

    $order = Order::create([
        'order_number' => 'APW-'.uniqid(),
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 10000,
        'total' => 10000,
        'shipping_address' => [
            'line1' => 'Villa 12, Street 4',
            'city' => 'Dubai',
            'state' => 'Dubai',
        ],
    ]);

    $body = test()->getJson('/admin-api/orders/'.$order->id)->assertOk()->json();

    /*
     * These read `$c->emirate` and `$c->default_address`, and `customers` has
     * neither column — the emirate is `addresses.state`. Eloquent answers a
     * missing attribute with null instead of raising, so the order modal showed
     * a blank emirate and a blank address on every order, silently.
     */
    expect($body['customer']['emirate'])->toBe('Dubai')
        ->and($body['customer']['address'])->toContain('Villa 12, Street 4')
        ->and($body['customer']['address'])->toContain('Dubai');
});

it('shows the emirate on the customers list from the address book', function () {
    asApwAdmin();

    $customer = Customer::create([
        'name' => 'APW Resident',
        'email' => 'apw-resident-'.uniqid().'@example.test',
    ]);

    // Through the relation: Address guards `customer_id` on purpose, so
    // Address::create(['customer_id' => ...]) silently drops it.
    $customer->addresses()->create([
        'type' => 'shipping',
        'is_default' => true,
        'line1' => 'Tower 3',
        'city' => 'Sharjah',
        'state' => 'Sharjah',
    ]);

    $row = collect(test()->getJson('/admin-api/customers')->assertOk()->json('customers'))
        ->firstWhere('id', $customer->id);

    expect($row['emirate'])->toBe('Sharjah');
});

it('keeps the customers list to a flat statement count', function () {
    asApwAdmin();

    // The emirate lookup above must not become one query per customer.
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/admin-api/customers')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    for ($i = 0; $i < 3; $i++) {
        $c = Customer::create(['name' => 'APW C'.$i, 'email' => 'apw-c'.$i.'-'.uniqid().'@example.test']);
        $c->addresses()->create(['type' => 'shipping', 'is_default' => true, 'state' => 'Dubai']);
    }

    $small = $count();

    for ($i = 3; $i < 15; $i++) {
        $c = Customer::create(['name' => 'APW C'.$i, 'email' => 'apw-c'.$i.'-'.uniqid().'@example.test']);
        $c->addresses()->create(['type' => 'shipping', 'is_default' => true, 'state' => 'Dubai']);
    }

    expect($count())->toBeLessThanOrEqual($small, 'the customers list grew a query per row');
});
