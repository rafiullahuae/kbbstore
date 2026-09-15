<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductEditorRoutes;

/**
 * The product editor: gallery, categories, rich copy, SEO and scheduling.
 *
 * WHAT THESE TESTS ASSERT, AND WHAT THEY DELIBERATELY DO NOT.
 *
 * Almost every assertion here is about a CONSEQUENCE on the storefront rather
 * than about the shape of a row or the text of a validation rule. A test that
 * checks `in:publish,draft,private` is in the validator keeps passing if
 * somebody changes the rule and scopeVisible() together, which is the exact
 * regression worth catching — and this repo has the scar: a lane shipped
 * `active`, the endpoint answered 200, and the product left the shop, its
 * category page and the sitemap at the same moment.
 *
 * So: does the product appear on its category page, is it in the sitemap, does
 * the public page render the description harmlessly, are the gallery images in
 * the order the owner dragged them into. Those questions have answers that
 * cannot be satisfied by accident.
 */

/* ------------------------------------------------------------------ fixtures */

beforeEach(function () {
    ProductEditorRoutes::wire(app());
});

function peAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'PE Owner',
        'email' => 'pe-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asPeAdmin(): void
{
    test()->actingAs(peAdmin(), 'admin');
}

function peCategory(string $name = 'PE Category'): Category
{
    return Category::create(['name' => $name, 'slug' => 'pe-cat-'.uniqid()]);
}

function peBrand(): Brand
{
    return Brand::create(['name' => 'PE Brand', 'slug' => 'pe-brand-'.uniqid()]);
}

function peProduct(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'name' => 'PE Product '.$n,
        'slug' => 'pe-product-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $attributes));
}

/** Is this product on its category page right now? */
function peOnCategoryPage(Product $product, Category $category): bool
{
    return str_contains(
        (string) test()->get('/product-category/'.$category->slug.'/')->getContent(),
        $product->slug
    );
}

/** Is this product in the sitemap right now? */
function peInSitemap(Product $product): bool
{
    return str_contains(
        (string) test()->get('/sitemap.xml')->getContent(),
        '/product/'.$product->slug.'/'
    );
}

/** Is this product listed on the shop archive right now? */
function peOnShop(Product $product): bool
{
    return str_contains(
        (string) test()->get('/shop')->getContent(),
        $product->slug
    );
}

/* -------------------------------------------------------------- the guard */

it('answers nobody who is not a signed-in admin, on every route this lane adds', function () {
    $product = peProduct();

    $calls = [
        ['get', '/admin-api/product-editor-bootstrap'],
        ['get', '/admin-api/product-editor-list'],
        ['get', '/admin-api/product-editor-load/'.$product->id],
        ['post', '/admin-api/product-editor-slug'],
        ['post', '/admin-api/product-editor-create'],
        ['post', '/admin-api/product-editor-save/'.$product->id],
    ];

    // 1. Anonymous.
    foreach ($calls as [$verb, $path]) {
        $method = $verb === 'get' ? 'getJson' : 'postJson';

        expect(test()->{$method}($path, [])->status())
            ->toBe(401, $verb.' '.$path.' answered an anonymous caller');
    }

    // 2. A signed-in STOREFRONT CUSTOMER is not an admin.
    $customer = Customer::create([
        'email' => 'pe-shopper-'.uniqid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'Pe',
        'last_name' => 'Shopper',
    ]);

    test()->actingAs($customer, 'customer');

    foreach ($calls as [$verb, $path]) {
        $method = $verb === 'get' ? 'getJson' : 'postJson';

        expect(test()->{$method}($path, [])->status())
            ->toBe(401, $verb.' '.$path.' answered a storefront customer');
    }

    // 3. A plain `web` user is not an admin either.
    if (class_exists(User::class)) {
        $user = User::create([
            'name' => 'Pe Web',
            'email' => 'pe-web-'.uniqid().'@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        test()->actingAs($user, 'web');

        foreach ($calls as [$verb, $path]) {
            $method = $verb === 'get' ? 'getJson' : 'postJson';

            expect(test()->{$method}($path, [])->status())
                ->toBe(401, $verb.' '.$path.' answered a plain web user');
        }
    }
});

it('carries the whole admin-api middleware stack on every registered route', function () {
    /*
     * Read back off the REGISTERED routes, not off the harness's intent.
     * RouteRegistrar::middleware() REPLACES rather than appends, so a harness
     * that chains it twice registers routes with only the last stack while
     * reading as though it applied both.
     */
    $routes = ProductEditorRoutes::registered();

    expect($routes)->toHaveCount(6);

    foreach ($routes as $route) {
        // toContain() is VARIADIC in Pest — every argument is another needle,
        // not a failure message. Passing a message here would assert that the
        // middleware list contains the message itself, which is a test that can
        // only ever fail, and one that fails for a reason unrelated to the
        // guard it claims to check.
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class);
    }
});

/* ---------------------------------------------------------------- money */

it('parses operator money to exact fils and never through a float', function () {
    asPeAdmin();

    $product = peProduct();

    foreach ([
        ['99.50', 9950],
        ['1.15', 115],
        ['0.29', 29],
        ['100', 10000],
        ['0', 0],
        ['15000.00', 1500000],
    ] as [$typed, $fils]) {
        test()->postJson('/admin-api/product-editor-save/'.$product->id, [
            'price_aed' => $typed,
        ])->assertOk();

        expect((int) $product->fresh()->price)->toBe($fils, "typed {$typed}");
    }
});

it('refuses a price with more precision than the currency has', function () {
    asPeAdmin();

    $product = peProduct();

    // 0.145 is not expressible in fils. Refused rather than truncated to 14 —
    // a rounding rule applied silently to a price is a number the owner cannot
    // reconcile against what they typed.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'price_aed' => '0.145',
    ])->assertStatus(422)->assertJsonValidationErrors(['price_aed']);

    expect((int) $product->fresh()->price)->toBe(10000);
});

it('refuses the money shapes that a numeric rule would have accepted', function () {
    asPeAdmin();

    $product = peProduct();

    foreach (['1e3', '1,500.00', '-5.00', '+5.00', 'AED 5', '5.', '1.2.3', '0x10'] as $bad) {
        test()->postJson('/admin-api/product-editor-save/'.$product->id, [
            'price_aed' => $bad,
        ])->assertStatus(422);
    }

    expect((int) $product->fresh()->price)->toBe(10000);
});

it('accepts a padded number because the web stack trims it first, and parses it exactly', function () {
    /*
     * " 1.50 " is NOT in the refusal list above, and that is a finding rather
     * than an oversight. MajorUnits::shape() would refuse it — the regex is
     * anchored and space is not a digit — but these routes carry the `web`
     * middleware stack, and TrimStrings runs before validation, so what the
     * rule actually sees is "1.50".
     *
     * That is the right outcome: a value pasted from a spreadsheet with a
     * trailing space becomes the price the operator meant, and it still goes
     * through the same digit-by-digit parse. Asserted here so the behaviour is
     * a decision on the record rather than something a reader has to infer from
     * a gap in the list above.
     */
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'price_aed' => '  1.50  ',
    ])->assertOk();

    expect((int) $product->fresh()->price)->toBe(150);
});

it('round-trips a large price through the editor without a comma refusing it', function () {
    /*
     * The trap this catches: Money::amount() renders 1,500,000 fils as
     * "15,000.00", and MajorUnits::shape() refuses a comma on purpose. Loading
     * a product into the form and pressing Save without touching anything must
     * not fail on a value the editor itself produced.
     */
    asPeAdmin();

    $product = peProduct(['price' => 1500000]);

    $loaded = test()->getJson('/admin-api/product-editor-load/'.$product->id)
        ->assertOk()->json('product');

    expect($loaded['price_aed'])->toBe('15000.00');

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'price_aed' => $loaded['price_aed'],
    ])->assertOk();

    expect((int) $product->fresh()->price)->toBe(1500000);
});

it('refuses an amount past the column ceiling with the limit in words', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'price_aed' => '99999999',
    ])->assertStatus(422);

    expect((int) $product->fresh()->price)->toBe(10000);
});

it('refuses a sale price above the regular price', function () {
    asPeAdmin();

    $product = peProduct(['price' => 10000]);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'sale_aed' => '150.00',
    ])->assertStatus(422);

    expect($product->fresh()->sale_price)->toBeNull();
});

/* -------------------------------------------------------------- statuses */

it('keeps products.status to publish, draft and private only', function () {
    asPeAdmin();

    $product = peProduct();

    foreach (['active', 'archived', 'published', 'live', 'pending'] as $bogus) {
        test()->postJson('/admin-api/product-editor-save/'.$product->id, [
            'status' => $bogus,
        ])->assertStatus(422);
    }

    expect($product->fresh()->status)->toBe('publish');
});

it('takes a product off the storefront everywhere when it is drafted', function () {
    asPeAdmin();

    $category = peCategory();
    $product = peProduct();
    $product->categories()->sync([$category->id]);
    $product->category_id = $category->id;
    $product->save();

    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue()
        ->and(peOnCategoryPage($product, $category))->toBeTrue()
        ->and(peInSitemap($product))->toBeTrue();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'status' => 'draft',
    ])->assertOk();

    expect(Product::visible()->whereKey($product->id)->exists())->toBeFalse()
        ->and(peOnCategoryPage($product, $category))->toBeFalse()
        ->and(peInSitemap($product))->toBeFalse();
});

/* ------------------------------------------------------- scheduled publish */

it('hides a scheduled product everywhere before its time and shows it after, with no cron', function () {
    asPeAdmin();

    $category = peCategory();
    $product = peProduct();
    $product->categories()->sync([$category->id]);
    $product->category_id = $category->id;
    $product->save();

    // Scheduled for a week out.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'status' => 'scheduled',
        'published_at' => now()->addWeek()->format('Y-m-d H:i:s'),
    ])->assertOk();

    // Stored as a PUBLISHED row with a future date — never a fourth status.
    $row = DB::table('products')->where('id', $product->id)->first();
    expect($row->status)->toBe('publish')
        ->and($row->published_at)->not->toBeNull();

    // Invisible everywhere today.
    expect(Product::visible()->whereKey($product->id)->exists())->toBeFalse()
        ->and(peOnShop($product))->toBeFalse()
        ->and(peOnCategoryPage($product, $category))->toBeFalse();

    test()->get('/product/'.$product->slug.'/')->assertNotFound();

    /*
     * Now move the clock, and nothing else. No job is dispatched, no command is
     * run, no column is rewritten — the whole point of evaluating the schedule
     * at read time on a host that has no scheduler to run one.
     */
    test()->travelTo(now()->addWeeks(2));

    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue()
        ->and(peOnShop($product))->toBeTrue()
        ->and(peOnCategoryPage($product, $category))->toBeTrue();

    test()->get('/product/'.$product->slug.'/')->assertOk();

    test()->travelBack();
});

it('keeps a scheduled product out of the sitemap through the shared predicate', function () {
    /*
     * The sitemap is built by Store\SeoFilesController from DB::table(...) with
     * a hand-written filter, so a model scope cannot reach it — and that file
     * belongs to the storefront-SEO lane, not this one. This test therefore
     * asserts the PREDICATE this lane provides for it, against a raw query
     * builder shaped exactly like the sitemap's, so the one-line adoption in
     * the handover is proven before it is made.
     *
     * A sitemap that lists a product whose page 404s is a soft 404 in Search
     * Console — the opposite of what scheduling a launch is for.
     */
    $live = peProduct();
    $scheduled = peProduct(['published_at' => now()->addWeek()]);
    $draft = peProduct(['status' => 'draft']);
    $hidden = peProduct(['is_visible' => false]);

    $slugs = \App\Support\ProductVisibility::raw(DB::table('products'))
        ->pluck('slug')
        ->all();

    expect($slugs)->toContain($live->slug)
        ->and($slugs)->not->toContain($scheduled->slug)
        ->and($slugs)->not->toContain($draft->slug)
        ->and($slugs)->not->toContain($hidden->slug);
});

it('keeps a draft invisible even when it carries a date that has already passed', function () {
    /*
     * ADDED BECAUSE A MUTATION SURVIVED. Rewriting ProductVisibility::schedule()
     * from the grouped form to the flat one —
     *
     *     $query->whereNull($c)->orWhere($c, '<=', $now)
     *
     * instead of wrapping both in a closure — left every test green. It is not
     * harmless. AND binds tighter than OR in SQL, so the flat version reads as
     *
     *     (status='publish' AND is_visible=1 AND published_at IS NULL)
     *      OR (published_at <= now)
     *
     * and that second branch has no status test on it at all: ANY row with a
     * past date matches, whatever its status. A draft carrying an old date —
     * which the importer can write, and which any row un-scheduled by hand
     * would have — is published to the world.
     *
     * The existing scheduling tests could not see it because they only ever use
     * FUTURE dates, where the leaking branch is false anyway. This one uses a
     * past date on a row that must stay hidden, which is the only shape that
     * tells the two implementations apart.
     */
    $draft = peProduct(['status' => 'draft', 'published_at' => now()->subMonth()]);
    $private = peProduct(['status' => 'private', 'published_at' => now()->subMonth()]);
    $hidden = peProduct(['is_visible' => false, 'published_at' => now()->subMonth()]);

    foreach ([$draft, $private, $hidden] as $product) {
        expect(Product::visible()->whereKey($product->id)->exists())
            ->toBeFalse($product->status.' with a past date leaked onto the storefront')
            ->and(peOnShop($product))->toBeFalse()
            ->and(peInSitemap($product))->toBeFalse();
    }

    // And the raw predicate, which the sitemap will adopt, agrees.
    $slugs = \App\Support\ProductVisibility::raw(DB::table('products'))->pluck('slug')->all();

    expect($slugs)
        ->not->toContain($draft->slug)
        ->not->toContain($private->slug)
        ->not->toContain($hidden->slug);
});

it('clears a pending schedule when the status is set back to published', function () {
    asPeAdmin();

    $product = peProduct(['published_at' => now()->addWeek()]);

    expect(Product::visible()->whereKey($product->id)->exists())->toBeFalse();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'status' => 'publish',
    ])->assertOk();

    // Leaving the date behind would mean a product marked Published that is
    // still invisible, with the reason no longer shown anywhere.
    expect($product->fresh()->published_at)->toBeNull()
        ->and(Product::visible()->whereKey($product->id)->exists())->toBeTrue();
});

it('requires a date when the status is scheduled', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'status' => 'scheduled',
    ])->assertStatus(422)->assertJsonValidationErrors(['published_at']);
});

it('leaves every product that existed before the column alone', function () {
    // published_at NULL means "not scheduled", which is how every pre-existing
    // row reads. Adding the column must change nothing about the catalogue.
    $product = peProduct();

    expect($product->published_at)->toBeNull()
        ->and(Product::visible()->whereKey($product->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------- categories */

it('puts a product on every category page it was given, and marks one primary', function () {
    asPeAdmin();

    $a = peCategory('PE Alpha');
    $b = peCategory('PE Beta');
    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'category_ids' => [$a->id, $b->id],
        'primary_category_id' => $b->id,
    ])->assertOk();

    // Both halves moved together: the pivot ShopController::index() filters on,
    // AND products.category_id, which is what the breadcrumb reads.
    expect($product->fresh()->categories->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and((int) $product->fresh()->category_id)->toBe($b->id);

    // The consequence, which is the part that matters.
    expect(peOnCategoryPage($product, $a))->toBeTrue()
        ->and(peOnCategoryPage($product, $b))->toBeTrue();
});

it('never leaves the primary category pointing outside the selected set', function () {
    asPeAdmin();

    $a = peCategory('PE Alpha');
    $b = peCategory('PE Beta');
    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'category_ids' => [$a->id, $b->id],
        'primary_category_id' => $a->id,
    ])->assertOk();

    // Now untick the category that was primary.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'category_ids' => [$b->id],
    ])->assertOk();

    /*
     * The old value must not survive in products.category_id. If it did, the
     * breadcrumb and the product page would keep naming a category the archive
     * no longer lists the product in — the two halves out of step in the
     * quietest possible way.
     */
    expect((int) $product->fresh()->category_id)->toBe($b->id)
        ->and(peOnCategoryPage($product, $a))->toBeFalse()
        ->and(peOnCategoryPage($product, $b))->toBeTrue();
});

/* ------------------------------------------------------------- the gallery */

it('keeps gallery order and shows it on the public page in that order', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'image' => '/uploads/products/main.jpg',
        'images' => [
            '/uploads/products/one.jpg',
            '/uploads/products/two.jpg',
            '/uploads/products/three.jpg',
        ],
    ])->assertOk();

    expect($product->fresh()->images)->toBe([
        '/uploads/products/one.jpg',
        '/uploads/products/two.jpg',
        '/uploads/products/three.jpg',
    ]);

    // And the storefront strip renders them in that sequence, after the main
    // shot — Store\ProductController::gallery() merges [image] with images.
    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    $positions = array_map(
        fn (string $needle) => strpos($html, $needle),
        ['main.jpg', 'one.jpg', 'two.jpg', 'three.jpg']
    );

    foreach ($positions as $at) {
        expect($at)->not->toBeFalse();
    }

    expect($positions)->toBe(array_values(collect($positions)->sort()->values()->all()));
});

it('does not store the main image a second time inside the gallery', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'image' => '/uploads/products/main.jpg',
        'images' => ['/uploads/products/main.jpg', '/uploads/products/one.jpg'],
    ])->assertOk();

    expect($product->fresh()->images)->toBe(['/uploads/products/one.jpg']);
});

/* ------------------------------------------------- the description and XSS */

it('strips script, handlers and javascript URLs out of a saved description', function () {
    asPeAdmin();

    $product = peProduct();

    $hostile = '<p>Real copy.</p>'
        .'<script>alert(document.cookie)</script>'
        .'<img src=x onerror="alert(1)">'
        .'<a href="javascript:alert(1)">tap</a>'
        .'<a href="jav&#x09;ascript:alert(1)">tab</a>'
        .'<style>body{display:none}</style>'
        .'<iframe src="https://evil.test"></iframe>'
        .'<div onclick="alert(1)" style="x">kept words</div>'
        .'<svg><use href="data:image/svg+xml;base64,PHN2Zz4="/></svg>';

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'description' => $hostile,
    ])->assertOk();

    $stored = (string) $product->fresh()->description;

    expect($stored)
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('<style')
        ->not->toContain('<iframe')
        ->not->toContain('<svg')
        ->not->toContain('alert(document.cookie)')
        // The words the operator typed survive; only the markup goes.
        ->toContain('Real copy.')
        ->toContain('kept words');

    /*
     * And the PUBLIC page, which is the only place this actually matters:
     * partials/product-tabs renders the description with {!! !!}, twice.
     */
    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)
        ->not->toContain('alert(document.cookie)')
        ->not->toContain('onerror=')
        ->not->toContain('javascript:alert')
        ->toContain('Real copy.');
});

it('keeps the formatting the owner actually wants', function () {
    asPeAdmin();

    $product = peProduct();

    $rich = '<h2>How it works</h2>'
        .'<p>A <strong>bold</strong> claim and an <em>italic</em> one.</p>'
        .'<ul><li>First</li><li>Second</li></ul>'
        .'<ol><li>Step one</li></ol>'
        .'<a href="https://kbeautybliss.com/guide">Read the guide</a>';

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'description' => $rich,
    ])->assertOk();

    $stored = (string) $product->fresh()->description;

    foreach (['<h2>', '<strong>', '<em>', '<ul>', '<li>', '<ol>', '<a href="https://kbeautybliss.com/guide"'] as $kept) {
        expect($stored)->toContain($kept);
    }

    // An outbound link gets rel, set by the server rather than trusted.
    expect($stored)->toContain('noopener');
});

it('sanitises every rich field, not only the description', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'short_description' => '<p>Short</p><script>alert(1)</script>',
        'description' => '<p>Long</p><script>alert(2)</script>',
        'ingredients' => '<p>Water</p><script>alert(3)</script>',
        'how_to_use' => '<p>Apply</p><script>alert(4)</script>',
    ])->assertOk();

    $fresh = $product->fresh();

    foreach (['short_description', 'description', 'ingredients', 'how_to_use'] as $field) {
        expect((string) $fresh->{$field})
            ->not->toContain('<script')
            ->not->toContain('alert(');
    }
});

/* ------------------------------ the tabs that could never render before */

it('renders the ingredients and how-to-use tabs, which no product could before', function () {
    /*
     * Store\ProductController::tabs() has always read $product->ingredients and
     * $product->how_to_use. Neither was a column on `products`, so Eloquent
     * returned null, tabs() dropped the tab as empty, and those two tabs could
     * not render for any product in the catalogue — silently, with no error.
     * The columns exist now and the editor writes them.
     */
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'ingredients' => '<p>Aqua, Niacinamide, Panthenol</p>',
        'how_to_use' => '<p>Apply morning and evening to clean skin.</p>',
    ])->assertOk();

    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)
        ->toContain('Ingredients')
        ->toContain('Niacinamide')
        ->toContain('How to use')
        ->toContain('Apply morning and evening');
});

/* ------------------------------------------------------------------- SEO */

it('writes SEO to the column the storefront publishes from', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'seo' => [
            'title' => 'Hydrating Toner | KBB',
            'desc' => 'A gentle Korean toner for dry skin, delivered across the UAE.',
            'canonical' => 'https://kbeautybliss.com/product/hydrating-toner/',
            'noindex' => false,
        ],
    ])->assertOk();

    // `seo`, the json column every reader in the application uses — not
    // `seo_json`, which was a closed loop inside the admin.
    $row = DB::table('products')->where('id', $product->id)->first();
    $stored = json_decode((string) $row->seo, true);

    expect($stored['title'])->toBe('Hydrating Toner | KBB')
        ->and($stored['desc'])->toBe('A gentle Korean toner for dry skin, delivered across the UAE.')
        // noindex false is the default and is not stored as a value.
        ->and($stored)->not->toHaveKey('noindex');

    // The consequence: the product page says it.
    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)
        ->toContain('Hydrating Toner | KBB')
        ->toContain('A gentle Korean toner for dry skin');
});

it('applies noindex to the page when the owner asks for it', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'seo' => ['noindex' => true],
    ])->assertOk();

    expect($product->fresh()->seo)->toBe(['noindex' => true]);

    $html = (string) test()->get('/product/'.$product->slug.'/')->getContent();

    expect($html)->toContain('noindex');
});

it('refuses a canonical that is not a real URL', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'seo' => ['canonical' => 'not a url'],
    ])->assertStatus(422);
});

/* ------------------------------------------------------------------ GTIN */

it('accepts a real barcode and stores it without its grouping', function () {
    asPeAdmin();

    $product = peProduct();

    foreach ([
        ['4006381333931', '4006381333931'],   // EAN-13
        ['96385074', '96385074'],             // EAN-8
        ['036000291452', '036000291452'],     // UPC-A
        ['00012345678905', '00012345678905'], // GTIN-14, leading zeros kept
        ['400-6381-33393-1', '4006381333931'],// grouped as printed
    ] as [$typed, $stored]) {
        test()->postJson('/admin-api/product-editor-save/'.$product->id, [
            'gtin' => $typed,
        ])->assertOk();

        expect($product->fresh()->gtin)->toBe($stored, "typed {$typed}");
    }
});

it('refuses a barcode whose check digit does not agree with it', function () {
    /*
     * The whole reason the field is worth having. A rule that counted digits
     * would accept every one of these, and the shop would then publish a
     * structured, machine-readable claim that this product is a DIFFERENT
     * product — which is worse for a merchant listing than publishing nothing.
     */
    asPeAdmin();

    $product = peProduct(['gtin' => '4006381333931']);

    foreach ([
        '4006381333932',   // one digit mistyped
        '4006381333913',   // a transposed pair
        '400638133393',    // an EAN-13 body with the check digit dropped
        '1234567890',      // 10 digits is not a GTIN length
        '12345678901',     // nor is 11 (UPC-E)
        '4006381A33931',   // not digits
    ] as $bad) {
        test()->postJson('/admin-api/product-editor-save/'.$product->id, [
            'gtin' => $bad,
        ])->assertStatus(422);
    }

    // And the good value that was already there is untouched.
    expect($product->fresh()->gtin)->toBe('4006381333931');
});

it('lets a barcode be cleared, because most of this catalogue has none', function () {
    asPeAdmin();

    $product = peProduct(['gtin' => '4006381333931']);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, ['gtin' => ''])->assertOk();

    expect($product->fresh()->gtin)->toBeNull();
});

/* -------------------------------------------------------------- alt text */

it('stores alt text per image and keys it by URL, not by position', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'image' => '/uploads/products/main.jpg',
        'images' => ['/uploads/products/one.jpg', '/uploads/products/two.jpg'],
        'image_alts' => [
            '/uploads/products/main.jpg' => 'Bottle, front',
            '/uploads/products/one.jpg' => 'Texture on the back of a hand',
            '/uploads/products/two.jpg' => 'Ingredient list on the box',
        ],
    ])->assertOk();

    $fresh = $product->fresh();

    expect($fresh->altFor('/uploads/products/one.jpg'))->toBe('Texture on the back of a hand');

    // Reorder the gallery WITHOUT resending the alts. Because the map is keyed
    // by URL, the caption stays on its own photograph rather than sliding onto
    // whichever image now occupies that position.
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'images' => ['/uploads/products/two.jpg', '/uploads/products/one.jpg'],
    ])->assertOk();

    $fresh = $product->fresh();

    expect($fresh->altFor('/uploads/products/one.jpg'))->toBe('Texture on the back of a hand')
        ->and($fresh->altFor('/uploads/products/two.jpg'))->toBe('Ingredient list on the box');
});

it('drops alt text for an image that is no longer on the product', function () {
    asPeAdmin();

    $product = peProduct();

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'image' => '/uploads/products/main.jpg',
        'images' => ['/uploads/products/one.jpg'],
        'image_alts' => [
            '/uploads/products/main.jpg' => 'Bottle, front',
            '/uploads/products/one.jpg' => 'Texture',
            '/uploads/products/gone.jpg' => 'An image that is not here',
        ],
    ])->assertOk();

    expect($product->fresh()->image_alts)->toBe([
        '/uploads/products/main.jpg' => 'Bottle, front',
        '/uploads/products/one.jpg' => 'Texture',
    ]);
});

it('falls back to a derived description when no alt was written', function () {
    $brand = peBrand();
    $product = peProduct(['name' => 'Heartleaf Toner', 'brand_id' => $brand->id]);
    $product->load('brand');

    // A named shot gets its label; the generic "View 4" is a position rather
    // than a description and is left out rather than read aloud.
    expect($product->altFor('/uploads/x.jpg', 'Texture'))->toBe('PE Brand — Heartleaf Toner — Texture')
        ->and($product->altFor('/uploads/x.jpg', 'View 4'))->toBe('PE Brand — Heartleaf Toner')
        ->and($product->altFor('/uploads/x.jpg'))->toBe('PE Brand — Heartleaf Toner');

    // A stored alt always wins over the derived one.
    $product->image_alts = ['/uploads/x.jpg' => 'The real thing'];
    $product->save();

    expect($product->fresh()->altFor('/uploads/x.jpg', 'Texture'))->toBe('The real thing');
});

/* ---------------------------------------------------- identity + computed */

it('refuses to change a slug or a wc_id after the product exists', function () {
    asPeAdmin();

    $product = peProduct(['wc_id' => 4242]);
    $originalSlug = $product->slug;

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => 'Renamed',
        'slug' => 'a-brand-new-slug',
        'wc_id' => 9999,
    ])->assertOk();

    $fresh = $product->fresh();

    // The name changed; the two URL contracts did not.
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->slug)->toBe($originalSlug)
        ->and((int) $fresh->wc_id)->toBe(4242);
});

it('never lets computed columns be typed over', function () {
    asPeAdmin();

    $product = peProduct();
    DB::table('products')->where('id', $product->id)->update([
        'total_sales' => 17, 'rating' => 4.5, 'review_count' => 9,
    ]);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'total_sales' => 9999,
        'rating' => 1.0,
        'review_count' => 0,
    ])->assertOk();

    $fresh = $product->fresh();

    expect((int) $fresh->total_sales)->toBe(17)
        ->and((float) $fresh->rating)->toBe(4.5)
        ->and((int) $fresh->review_count)->toBe(9);
});

/* ---------------------------------------------------------------- create */

it('creates a product, and only puts it on the shop when asked to', function () {
    asPeAdmin();

    $category = peCategory();
    $brand = peBrand();

    $response = test()->postJson('/admin-api/product-editor-create', [
        'name' => 'PE Created Serum',
        'slug' => 'pe-created-serum',
        'brand_id' => $brand->id,
        'category_ids' => [$category->id],
        'primary_category_id' => $category->id,
        'price_aed' => '129.50',
        'status' => 'draft',
        'description' => '<p>Lovely.</p><script>alert(1)</script>',
    ])->assertCreated();

    $id = $response->json('product.id');
    $product = Product::find($id);

    expect((int) $product->price)->toBe(12950)
        ->and($product->status)->toBe('draft')
        ->and((string) $product->description)->not->toContain('<script')
        ->and($product->categories->pluck('id')->all())->toBe([$category->id])
        ->and((int) $product->category_id)->toBe($category->id);

    // A draft is not on the storefront.
    expect(Product::visible()->whereKey($id)->exists())->toBeFalse()
        ->and(peOnCategoryPage($product, $category))->toBeFalse();
});

it('does not reuse the web address of a product that already has one', function () {
    asPeAdmin();

    peProduct(['slug' => 'pe-taken-slug']);

    $response = test()->postJson('/admin-api/product-editor-create', [
        'name' => 'PE Another',
        'slug' => 'pe-taken-slug',
        'price_aed' => '10.00',
    ])->assertCreated();

    expect($response->json('product.slug'))->not->toBe('pe-taken-slug');
});

it('reports whether a web address is free', function () {
    asPeAdmin();

    peProduct(['slug' => 'pe-existing-slug']);

    test()->postJson('/admin-api/product-editor-slug', ['name' => 'PE Existing Slug'])
        ->assertOk()
        ->assertJson(['slug' => 'pe-existing-slug', 'available' => false]);

    test()->postJson('/admin-api/product-editor-slug', ['name' => 'PE Totally New Thing'])
        ->assertOk()
        ->assertJson(['slug' => 'pe-totally-new-thing', 'available' => true]);
});

/* -------------------------------------------------------------- the list */

it('escapes LIKE wildcards in the product search', function () {
    asPeAdmin();

    $match = peProduct(['name' => 'PE 50% Off Bundle']);
    $other = peProduct(['name' => 'PE Ordinary Item']);

    // Without ESCAPE '!', '%' inside the term makes the pattern match anything.
    $names = collect(
        test()->getJson('/admin-api/product-editor-list?q='.urlencode('50%'))
            ->assertOk()->json('products')
    )->pluck('name');

    expect($names)->toContain($match->name)
        ->and($names)->not->toContain($other->name);

    // An underscore is a single-character wildcard in both dialects.
    $underscore = peProduct(['name' => 'PE A_B Special']);
    peProduct(['name' => 'PE AXB Special']);

    $names = collect(
        test()->getJson('/admin-api/product-editor-list?q='.urlencode('A_B'))
            ->assertOk()->json('products')
    )->pluck('name');

    expect($names)->toContain($underscore->name)
        ->and($names)->not->toContain('PE AXB Special');
});

/* ------------------------------------------------- the public API is public */

it('still never leaks internal columns through the public API', function () {
    asPeAdmin();

    $product = peProduct(['wc_id' => 7777, 'sku' => 'PE-SKU-1']);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'ingredients' => '<p>Aqua</p>',
        'seo' => ['title' => 'Nope'],
    ])->assertOk();

    // /api/* is unauthenticated by design. Nothing this lane added may appear
    // there, and the columns that were already private must stay private.
    $json = test()->getJson('/api/products/'.$product->slug)->assertOk()->json();

    $flat = json_encode($json);

    expect($flat)
        ->not->toContain('7777')
        ->not->toContain('PE-SKU-1')
        ->not->toContain('total_sales')
        ->not->toContain('seo')
        ->not->toContain('ingredients');
});

/* --------------------------------------------------------- the sanitiser */

it('drops a hostile element WHOLE, including children the parser keeps as text', function () {
    /*
     * WHY THIS TEST EXISTS, which is worth spelling out because it was added
     * after a mutation survived.
     *
     * Removing 'script' from RichText::DROP_WHOLE — the change that should turn
     * `<script>alert(1)</script>` into the visible text `alert(1)` — left the
     * whole suite GREEN. The reason is a second layer nobody designed as one:
     * libxml hands the contents of `<script>` and `<style>` back as CDATA
     * sections, not text nodes, and walk() removes every node that is neither
     * an element nor a text node. So the payload died even with the tag merely
     * unwrapped, and no consequence test could tell the two configurations
     * apart.
     *
     * That redundancy is real protection, but it is also a coupling: the day
     * someone makes walk() preserve CDATA (to stop eating something else), the
     * DROP_WHOLE entry becomes the only thing standing there, and nothing would
     * have told them. So both halves are pinned.
     *
     * The elements below are the ones where DROP_WHOLE is genuinely
     * load-bearing: their children are ORDINARY TEXT, which walk() keeps, so
     * unwrapping them really would promote the payload onto the page.
     */
    foreach ([
        ['<form action="https://evil.test">Enter your card number</form>', 'Enter your card number'],
        ['<iframe src="https://evil.test">Your browser cannot show this</iframe>', 'Your browser cannot show this'],
        ['<object data="x">fallback text</object>', 'fallback text'],
        ['<textarea>raw payload</textarea>', 'raw payload'],
        ['<noscript>enable javascript</noscript>', 'enable javascript'],
        ['<button onclick="x">Press me</button>', 'Press me'],
        ['<select><option>choice</option></select>', 'choice'],
    ] as [$payload, $childText]) {
        expect(RichText::clean($payload))->not->toContain($childText);
    }
});

it('keeps script and style contents out, by both of the layers that stop them', function () {
    // Layer 1: the tag is on the drop-whole list.
    $reflection = new ReflectionClass(RichText::class);
    $dropWhole = $reflection->getConstant('DROP_WHOLE');

    expect($dropWhole)->toContain('script', 'style', 'iframe', 'object', 'embed', 'form', 'svg');

    // Layer 2: whatever the parser calls the contents, only elements and text
    // survive the walk — and libxml calls a script body a CDATA section.
    expect(RichText::clean('<script>alert(1)</script>'))->toBe('')
        ->and(RichText::clean('<style>body{display:none}</style>'))->toBe('');
});

it('sanitises the strings an attacker would actually send', function () {
    foreach ([
        '<script>alert(1)</script>',
        '<SCRIPT>alert(1)</SCRIPT>',
        '<img src=x onerror=alert(1)>',
        '<a href="javascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="jav&#x09;ascript:alert(1)">x</a>',
        '<a href="&#106;avascript:alert(1)">x</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
        '<a href="//evil.test">x</a>',
        '<iframe src="https://evil.test"></iframe>',
        '<style>@import url(https://evil.test)</style>',
        '<svg onload=alert(1)></svg>',
        '<body onload=alert(1)>',
        '<form action="https://evil.test"><input name=card></form>',
        '<noscript><script>alert(1)</script></noscript>',
        '<math><mtext><script>alert(1)</script></mtext></math>',
        '<object data="https://evil.test"></object>',
        '<embed src="https://evil.test">',
        '<!-- <script>alert(1)</script> -->',
    ] as $payload) {
        $clean = RichText::clean($payload);

        expect($clean)
            ->not->toContain('<script')
            ->not->toContain('alert(1)')
            ->not->toContain('javascript:')
            ->not->toContain('<iframe')
            ->not->toContain('<style')
            ->not->toContain('<svg')
            ->not->toContain('<form')
            ->not->toContain('onerror')
            ->not->toContain('onload')
            ->not->toContain('evil.test');
    }
});
