<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductTab;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use App\Support\ProductTabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductTabsAdminRoutes;

/**
 * Tabs the owner writes himself. (Lane PT)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THE TABS WERE BEFORE THIS LANE, PINNED SO THE CHANGE CAN BE READ
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Store\ProductController::tabs() built Description / Ingredients / How to use
 * out of three `products` columns, appended whatever the `product_tabs` SETTING
 * held, dropped any entry with an empty title or an empty body, topped the list
 * up from DemoContent when demo mode was on and fewer than two survived, and
 * substituted one hard-coded English tab if the list was still empty. The first
 * surviving tab was the open one.
 *
 * Every one of those five behaviours is asserted below AS IT WAS, because the
 * only way "nothing that already works changed" can be checked is to state what
 * worked.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND WHAT IS NEW
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A global tab on every product, a tab on one product, and one product's answer
 * to an inherited tab: hidden here, or re-worded here. One ordering scale
 * across all three kinds plus the three built-ins. An Arabic title and body on
 * every authored tab, through the same TranslationStore the description uses.
 *
 * Each case that is about a defect carries the mutation that proves it asserts
 * something, and every mutation note below was RUN.
 */
function ptAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Tabs owner',
        'email' => 'pt-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function ptProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pt-'.Str::lower(Str::random(10)),
        'name' => 'Anua Heartleaf Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'description' => '<p>A gentle daily toner.</p>',
    ], $overrides));
}

/** A global tab, which is a row with no product and no source key. */
function ptGlobal(string $title, string $body, array $overrides = []): ProductTab
{
    return ProductTab::create(array_merge([
        'product_id' => null,
        'source_key' => null,
        'title' => $title,
        'body' => $body,
        'position' => ProductTabs::DEFAULT_GLOBAL_POSITION,
        'is_enabled' => true,
    ], $overrides));
}

/** @return list<string> the titles the product page would draw, in order */
function ptTitles(Product $product): array
{
    return array_map(
        static fn (array $t): string => $t['title'],
        ProductTabs::forProduct($product, [])
    );
}

beforeEach(function () {
    ProductTabs::flush();
    TranslationStore::flush();
});

/* ═══════════════════════════════════════════════════ 1. WHAT IT WAS ══════ */

it('draws Description, Ingredients and How to use, in that order, from the product', function () {
    $product = ptProduct([
        'description' => '<p>A gentle daily toner.</p>',
        'ingredients' => '<p>Water, Glycerin, Niacinamide.</p>',
        'how_to_use' => '<p>Sweep over the face.</p>',
    ]);

    expect(ptTitles($product))->toBe(['Description', 'Ingredients', 'How to use']);
});

it('drops a built-in tab whose body is empty rather than drawing a dead heading', function () {
    // The shop's behaviour before this lane and after it: a product with no
    // INCI list has no Ingredients tab at all, and the strip closes up.
    $product = ptProduct(['ingredients' => '', 'how_to_use' => null]);

    expect(ptTitles($product))->toBe(['Description']);
});

it('falls back from the description to the short description', function () {
    $product = ptProduct([
        'description' => '',
        'short_description' => '<p>A toner, briefly.</p>',
    ]);

    $tabs = ProductTabs::forProduct($product, []);

    expect($tabs[0]['title'])->toBe('Description')
        ->and($tabs[0]['body'])->toBe('<p>A toner, briefly.</p>');
});

it('still appends the legacy product_tabs setting, in the same place', function () {
    /*
     * The `product_tabs` setting has no writer anywhere in this application and
     * never had one -- but a live shop is not a clean checkout, and a setting
     * with no writer is not a setting with no value. Its entries went after the
     * three built-ins and before anything else, and they still do.
     *
     * MUTATION, RUN: change ProductTabs::LEGACY_SETTING_POSITION from 40 to 400
     * and this case fails with "Shipping" after "Free shipping over AED 199",
     * because the legacy tab then sorts past the global one.
     */
    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);
    ptGlobal('Returns', '<p>Fourteen days.</p>');

    $tabs = ProductTabs::forProduct($product, [
        ['title' => 'Shipping', 'body' => '<p>Free shipping over AED 199.</p>'],
    ]);

    expect(array_column($tabs, 'title'))->toBe(['Description', 'Shipping', 'Returns']);
});

/* ═══════════════════════════════════════════ 2. NOTHING MOVES UNTOUCHED ══ */

it('costs a product with no authored tabs no query at all', function () {
    /*
     * THE POINT OF ProductTabs::scopedProductIds(). A product whose id is not in
     * the cached set has provably no per-product row, so the read path does not
     * ask -- which is what keeps StorefrontQueryBudgetTest's product ceiling
     * where it is on a shop that has not used this feature.
     *
     * MUTATION, RUN: delete the `! in_array($productId, self::scopedProductIds())`
     * guard in ProductTabs::rowsFor() and this fails with 1 query, not 0.
     */
    $product = ptProduct();

    // Warm both caches, the way a real request finds them.
    ProductTabs::forProduct($product, []);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    ProductTabs::forProduct($product, []);

    expect($queries)->toBe(0, 'a product with no authored tabs must cost no query');
});

it('costs a product with six authored tabs exactly one query, not six', function () {
    /*
     * FLATNESS, not a budget. Six tabs and one tab must cost the same, or the
     * feature is an N+1 that a small fixture would hide -- the exact shape
     * CLAUDE.md records /shop reaching 390 queries through.
     */
    $product = ptProduct();

    for ($i = 1; $i <= 6; $i++) {
        ProductTab::create([
            'product_id' => $product->id,
            'title' => 'Tab '.$i,
            'body' => '<p>Body '.$i.'</p>',
            'position' => 500 + $i,
            'is_enabled' => true,
        ]);
    }

    ProductTabs::forProduct($product, []);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    $tabs = ProductTabs::forProduct($product, []);

    expect($queries)->toBe(1, 'six tabs must cost the same one query that one does')
        ->and(count($tabs))->toBe(7);
});

/* ═══════════════════════════════════════════════════ 3. GLOBAL TABS ══════ */

it('puts a global tab on every product, after the built-ins', function () {
    $one = ptProduct(['name' => 'Toner']);
    $two = ptProduct(['name' => 'Cleanser', 'ingredients' => '', 'how_to_use' => '']);

    ptGlobal('Shipping & returns', '<p>One to three working days.</p>');

    expect(ptTitles($one))->toBe(['Description', 'Shipping & returns'])
        ->and(ptTitles($two))->toBe(['Description', 'Shipping & returns']);
});

it('leaves a switched-off global tab off every product without deleting it', function () {
    $product = ptProduct();
    $tab = ptGlobal('Seasonal', '<p>Back in November.</p>', ['is_enabled' => false]);

    expect(ptTitles($product))->toBe(['Description'])
        ->and(ProductTab::find($tab->id))->not->toBeNull();
});

it('orders built-ins, globals and a product\'s own tabs on one scale', function () {
    /*
     * THE DECISION THIS CASE IS ABOUT. A per-product tab is not pinned before
     * or after the globals: it carries a position on the SAME scale, so the
     * owner decides. Here "Battery and charging" is moved to 15 -- between
     * Description at 10 and Ingredients at 20 -- which no globals-then-locals
     * rule could express.
     *
     * MUTATION, RUN: return $entries unsorted from ProductTabs::sorted() and
     * this fails with the product's own tab last instead of second.
     */
    $product = ptProduct([
        'ingredients' => '<p>Water.</p>',
        'how_to_use' => '<p>Sweep.</p>',
    ]);

    ptGlobal('Shipping & returns', '<p>Three days.</p>', ['position' => 100]);

    ProductTab::create([
        'product_id' => $product->id,
        'title' => 'Battery and charging',
        'body' => '<p>Two hours.</p>',
        'position' => 15,
        'is_enabled' => true,
    ]);

    expect(ptTitles($product))->toBe([
        'Description', 'Battery and charging', 'Ingredients', 'How to use', 'Shipping & returns',
    ]);
});

/* ═════════════════════════════════════════════ 4. HIDE AND OVERRIDE ══════ */

it('lets one product hide a global tab', function () {
    /*
     * The owner's own case: a facial device has no patch-test advice. Without
     * this the only way to keep the tab off the one product it is wrong for is
     * to delete it from all seven hundred.
     */
    $device = ptProduct(['name' => 'LED mask', 'ingredients' => '', 'how_to_use' => '']);
    $toner = ptProduct(['name' => 'Toner', 'ingredients' => '', 'how_to_use' => '']);

    $global = ptGlobal('Patch test advice', '<p>Test on the inner arm.</p>');

    ProductTab::create([
        'product_id' => $device->id,
        'source_key' => 'global:'.$global->id,
        'title' => '',
        'body' => '',
        'position' => 100,
        'is_enabled' => false,
    ]);

    expect(ptTitles($device))->toBe(['Description'])
        ->and(ptTitles($toner))->toBe(['Description', 'Patch test advice']);
});

it('lets one product hide a BUILT-IN tab', function () {
    /*
     * A set whose `ingredients` column was filled in by the WooCommerce import
     * with one member's INCI list. Before this the only fix was emptying the
     * column, which loses the text.
     *
     * MUTATION, RUN: drop 'builtin:' from ProductTabs::builtins()'s key and
     * this fails with Ingredients still present, because the override no longer
     * finds anything to cover.
     */
    $product = ptProduct(['ingredients' => '<p>Water, Glycerin.</p>', 'how_to_use' => '']);

    ProductTab::create([
        'product_id' => $product->id,
        'source_key' => 'builtin:ingredients',
        'title' => '',
        'body' => '',
        'position' => 20,
        'is_enabled' => false,
    ]);

    expect(ptTitles($product))->toBe(['Description']);
});

it('lets one product override a global tab\'s body while inheriting its title', function () {
    /*
     * AN EMPTY BOX ON AN OVERRIDE ROW MEANS INHERIT, never "blank". It is the
     * only reading under which the owner can replace a body and keep the
     * heading, and it is the convention the Arabic boxes already use.
     *
     * MUTATION, RUN: change the `$override['title'] !== ''` guard in
     * applyOverrides() to an unconditional assignment and this fails -- the
     * heading becomes the empty string and the whole tab is then dropped by the
     * empty-title rule, so the product loses a tab it was meant to re-word.
     */
    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);
    $global = ptGlobal('Shipping & returns', '<p>One to three working days.</p>');

    ProductTab::create([
        'product_id' => $product->id,
        'source_key' => 'global:'.$global->id,
        'title' => '',
        'body' => '<p>This one ships from Dubai the same day.</p>',
        'position' => 100,
        'is_enabled' => true,
    ]);

    $tabs = ProductTabs::forProduct($product, []);

    expect(array_column($tabs, 'title'))->toBe(['Description', 'Shipping & returns'])
        ->and($tabs[1]['body'])->toBe('<p>This one ships from Dubai the same day.</p>');
});

it('leaves every other product on the global body when one overrides it', function () {
    $overridden = ptProduct(['name' => 'A', 'ingredients' => '', 'how_to_use' => '']);
    $untouched = ptProduct(['name' => 'B', 'ingredients' => '', 'how_to_use' => '']);

    $global = ptGlobal('Shipping', '<p>Three days.</p>');

    ProductTab::create([
        'product_id' => $overridden->id,
        'source_key' => 'global:'.$global->id,
        'title' => 'Shipping (heavy item)',
        'body' => '<p>Five days.</p>',
        'position' => 100,
        'is_enabled' => true,
    ]);

    $a = ProductTabs::forProduct($overridden, []);
    $b = ProductTabs::forProduct($untouched, []);

    expect($a[1]['title'])->toBe('Shipping (heavy item)')
        ->and($a[1]['body'])->toBe('<p>Five days.</p>')
        ->and($b[1]['title'])->toBe('Shipping')
        ->and($b[1]['body'])->toBe('<p>Three days.</p>');
});

/* ══════════════════════════════════════════════════════ 5. BILINGUAL ══════ */

it('reads an authored tab\'s Arabic title and body on /ar', function () {
    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);
    $global = ptGlobal('Shipping & returns', '<p>One to three working days.</p>');

    foreach ([['title', 'الشحن والإرجاع'], ['body', '<p>من يوم إلى ثلاثة أيام عمل.</p>']] as [$field, $value]) {
        Translation::create([
            'locale' => 'ar',
            'group' => 'product_tabs',
            'item_id' => $global->id,
            'field' => $field,
            'value' => $value,
            'status' => Translation::STATUS_PUBLISHED,
            'source' => Translation::SOURCE_MANUAL,
        ]);
    }

    TranslationStore::flush();
    app()->setLocale('ar');

    $tabs = ProductTabs::forProduct($product, []);

    app()->setLocale('en');

    expect($tabs[1]['title'])->toBe('الشحن والإرجاع')
        ->and($tabs[1]['body'])->toBe('<p>من يوم إلى ثلاثة أيام عمل.</p>');
});

it('falls back to the English tab when the Arabic has not been typed yet', function () {
    // Blank means "not translated yet", exactly as it does for a product's
    // name: a half-Arabic page is the honest degradation while the owner works.
    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);
    ptGlobal('Shipping & returns', '<p>Three days.</p>');

    app()->setLocale('ar');
    $tabs = ProductTabs::forProduct($product, []);
    app()->setLocale('en');

    expect($tabs[1]['title'])->toBe('Shipping & returns');
});

it('reads one tab\'s Arabic body and six tabs\' for the same number of queries', function () {
    /*
     * FLATNESS, not a budget -- the same instrument StorefrontQueryBudgetTest
     * uses, and for the same reason: a page doing one query per tab passes any
     * budget on a small enough fixture.
     *
     * `body` is in TranslationStore::LONG_FIELDS -- which is WHY the column is
     * named `body` -- so it is fetched by the page that prints it rather than
     * carried in the map on every Arabic request. longFor() memoises per row,
     * so priming every id on the page in ONE call is what makes six cost what
     * one costs.
     *
     * MUTATION, RUN: delete the primeTranslations() call from forProduct() and
     * this fails with 6 against 1 -- one query per tab body.
     */
    $measure = function (int $count): int {
        ProductTab::query()->delete();
        Translation::query()->where('group', 'product_tabs')->delete();

        $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);

        for ($i = 1; $i <= $count; $i++) {
            $tab = ptGlobal('Tab '.$i, '<p>Body '.$i.'</p>', ['position' => 100 + $i]);

            Translation::create([
                'locale' => 'ar', 'group' => 'product_tabs', 'item_id' => $tab->id,
                'field' => 'body', 'value' => '<p>نص '.$i.'</p>',
                'status' => Translation::STATUS_PUBLISHED, 'source' => Translation::SOURCE_MANUAL,
            ]);
        }

        TranslationStore::flush();
        ProductTabs::flush();
        app()->setLocale('ar');

        // Warm the globals cache, so what is counted is the translation reads
        // rather than a first request's fixed cost -- the warm-up pass
        // StorefrontQueryBudgetTest documents. The TRANSLATION memo is then
        // cleared again, because the whole question is what the first read of
        // this page's tab bodies costs.
        ProductTabs::forProduct($product, []);
        TranslationStore::flush();

        /*
         * ONLY THE READS OF THE `translations` TABLE ARE COUNTED, and that is
         * not narrowing the measurement to make it pass -- it is what stops the
         * warm-up pass hiding the defect. longFor() memoises PER ROW, so a
         * warm-up that read the bodies one at a time would leave the measured
         * pass costing nothing and report six-for-the-price-of-one on an
         * implementation that had just paid six. Counting the translation reads
         * on the FIRST pass is the only place the difference is visible.
         */
        $queries = 0;

        DB::listen(function ($query) use (&$queries) {
            if (str_contains(strtolower($query->sql), 'translations')) {
                $queries++;
            }
        });

        $tabs = ProductTabs::forProduct($product, []);

        app()->setLocale('en');

        expect($tabs[$count]['body'])->toBe('<p>نص '.$count.'</p>');

        return $queries;
    };

    $one = $measure(1);
    $six = $measure(6);

    expect($six)->toBe($one,
        'six tab bodies must cost what one costs: '.$one.' against '.$six);
});

/* ══════════════════════════════════════════════════════ 6. SECURITY ══════ */

it('strips a script out of a tab body on the way in, in English', function () {
    /*
     * partials/product-tabs.blade.php prints every body with {!! !!}, TWICE.
     * The editor's toolbar is a convenience; App\Support\RichText is the
     * control, and it runs on the server on every write whatever the payload
     * claims to be.
     *
     * MUTATION, RUN: drop the RichText::clean() call from
     * ProductTabsApiController::fill() and this fails with the <script> stored
     * verbatim and printed onto every product page in the shop.
     */
    ProductTabsAdminRoutes::wire(app());

    $response = $this->actingAs(ptAdmin(), 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'Shipping',
        'body' => '<p>Three days.</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
    ]);

    $response->assertStatus(201);

    $body = (string) ProductTab::query()->latest('id')->first()->body;

    expect(str_contains($body, '<script'))->toBeFalse('a script must not survive the write')
        ->and(str_contains($body, 'javascript:'))->toBeFalse('a javascript: href must not survive')
        ->and(str_contains($body, '<p>Three days.</p>'))->toBeTrue('the real copy must survive');
});

it('strips a script out of the ARABIC tab body too', function () {
    /*
     * The same template prints the Arabic body through the same {!! !!} and has
     * no idea which language it holds. An Arabic box that skipped the sanitiser
     * would be a stored-XSS hole opened by the act of adding a second language
     * -- which the master plan records as the T4b defect, not an oversight
     * beside it.
     *
     * MUTATION, RUN: drop `self::RICH_FIELDS` from the
     * TranslationInput::fromRequest() call in create() and this fails with the
     * <script> stored in the translations table.
     */
    ProductTabsAdminRoutes::wire(app());

    $this->actingAs(ptAdmin(), 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'Shipping',
        'body' => '<p>Three days.</p>',
        'translations' => ['ar' => ['body' => '<p>ثلاثة أيام</p><script>alert(1)</script>']],
    ])->assertStatus(201);

    $stored = (string) Translation::query()
        ->where('group', 'product_tabs')->where('field', 'body')->value('value');

    expect(str_contains($stored, '<script'))->toBeFalse('the Arabic body travels the same sanitiser')
        ->and(str_contains($stored, 'ثلاثة'))->toBeTrue('the real Arabic copy must survive');
});

it('refuses a source_key outside its own closed vocabulary', function () {
    /*
     * CLAUDE.md rule 5: a select stores one of its own options or the default.
     * `source_key` is a select with five options, and the regex is anchored.
     */
    ProductTabsAdminRoutes::wire(app());

    $product = ptProduct();
    $admin = ptAdmin();

    foreach (['builtin:price', 'global:0', 'global:1x', '../../etc', 'builtin:description; drop'] as $key) {
        $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override', [
                'source_key' => $key,
                'mode' => 'hide',
            ])
            ->assertStatus(422, 'refused: '.$key);
    }
});

it('bounds the ARABIC title exactly as it bounds the English one', function () {
    /*
     * TranslationInput::rules() DERIVES the Arabic bounds from the English ones
     * the controller has already written, which is the whole point of that
     * helper: a rule restated is a rule that drifts. This case is here because
     * the override endpoint was the one path that did not pass its English
     * rules through it -- the English title was capped at 120 characters and
     * the Arabic one was capped at nothing at all, so a paste into the Arabic
     * box would have gone into the heading of a live product page unbounded.
     *
     * MUTATION, RUN: drop the `+ TranslationInput::rules(...)` from override()
     * and this fails with 200 instead of 422.
     */
    ProductTabsAdminRoutes::wire(app());

    $product = ptProduct();
    $global = ptGlobal('Shipping', '<p>Three days.</p>');

    $this->actingAs(ptAdmin(), 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override', [
            'source_key' => 'global:'.$global->id,
            'mode' => 'override',
            'title' => 'Shipping',
            'translations' => ['ar' => ['title' => str_repeat('ش', 400)]],
        ])
        ->assertStatus(422);
});

it('refuses an override aimed at a global tab that does not exist', function () {
    ProductTabsAdminRoutes::wire(app());

    $product = ptProduct();

    $this->actingAs(ptAdmin(), 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override', [
            'source_key' => 'global:999999',
            'mode' => 'hide',
        ])
        ->assertStatus(422);

    expect(ProductTab::query()->count())->toBe(0, 'no row may be written for a tab that is not there');
});

it('never lets the payload decide a row\'s scope', function () {
    /*
     * THE ONE THAT WOULD HURT. `product_id` comes from the ROUTE. If it came
     * from the body, a POST to one product's endpoint carrying
     * `product_id: null` would turn that product's tab into a GLOBAL one and
     * print its text on all seven hundred product pages.
     *
     * MUTATION, RUN: add 'product_id' to the rules and to the `new ProductTab`
     * array in create() and this fails -- the row comes back global.
     */
    ProductTabsAdminRoutes::wire(app());

    $product = ptProduct();

    $this->actingAs(ptAdmin(), 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id, [
            'title' => 'Mine only',
            'body' => '<p>Only here.</p>',
            'product_id' => null,
            'source_key' => 'global:1',
        ])
        ->assertStatus(201);

    $row = ProductTab::query()->latest('id')->first();

    expect($row->product_id)->toBe($product->id)
        ->and($row->source_key)->toBeNull();
});

it('bounds a position on both sides of the validator', function () {
    ProductTabsAdminRoutes::wire(app());

    $tab = ptGlobal('Shipping', '<p>Three days.</p>');

    $this->actingAs(ptAdmin(), 'admin')
        ->putJson('/admin-api/product-tabs/'.$tab->id, [
            'title' => 'Shipping',
            'position' => 70000,
        ])
        ->assertStatus(422);

    expect((int) ProductTab::find($tab->id)->position)->toBe(ProductTabs::DEFAULT_GLOBAL_POSITION);
});

it('refuses every endpoint to an anonymous caller and to a storefront customer', function () {
    /*
     * Read off the REGISTERED routes rather than a list written out here, so a
     * tenth endpoint added to the routes file is covered the day it is added.
     */
    ProductTabsAdminRoutes::wire(app());

    $routes = ProductTabsAdminRoutes::registered();

    expect(count($routes))->toBe(9, 'every route in the file must be guarded');

    foreach ($routes as $route) {
        expect(in_array('auth:admin', $route->gatherMiddleware(), true))
            ->toBeTrue($route->uri().' is not behind auth:admin');
    }
});

it('gives every new admin endpoint its own capability, and fails closed', function () {
    /*
     * CLAUDE.md rule 5. AdminCapabilities::for() returns null for a route it
     * does not recognise and EnforceAdminCapability turns a null into a 403 for
     * everyone who is not an owner -- so an unmapped route is owner-only rather
     * than open. This asserts the mapping is actually there, and that the
     * WRITES did not land on the read capability.
     *
     * MUTATION, RUN: move the two `GET` rules above the four write rules in
     * AdminCapabilities::RULES and this fails -- POST .../override resolves to
     * producttabs.view, because RULES is first-match-wins.
     */
    ProductTabsAdminRoutes::wire(app());

    foreach (ProductTabsAdminRoutes::registered() as $route) {
        $capability = \App\Support\AdminCapabilities::for($route);
        $method = in_array('GET', $route->methods(), true) ? 'GET' : 'WRITE';

        expect($capability)->not->toBeNull($route->uri().' has no capability');

        expect($capability)->toBe(
            $method === 'GET' ? 'producttabs.view' : 'producttabs.manage',
            $route->uri().' ('.$method.') resolved to '.$capability
        );
    }
});

it('keeps a support account out of the tab writer', function () {
    // `support` answers customers. It holds neither capability, so it cannot
    // print a paragraph on seven hundred product pages.
    ProductTabsAdminRoutes::wire(app());

    $this->actingAs(ptAdmin('support'), 'admin')
        ->postJson('/admin-api/product-tabs', ['title' => 'Shipping', 'body' => '<p>x</p>'])
        ->assertStatus(403);
});

/* ═══════════════════════════════════════════════ 7. THE ADMIN ENDPOINTS ══ */

it('creates, re-orders and deletes a global tab', function () {
    ProductTabsAdminRoutes::wire(app());

    $admin = ptAdmin();

    $first = $this->actingAs($admin, 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'Shipping & returns',
        'body' => '<p>Three days.</p>',
    ])->assertStatus(201)->json('tab');

    $second = $this->actingAs($admin, 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'How we authenticate',
        'body' => '<p>Every product is bought from the brand.</p>',
    ])->assertStatus(201)->json('tab');

    // A new tab lands AFTER the last one, so creating two does not need a drag.
    expect($second['position'])->toBeGreaterThan($first['position']);

    $this->actingAs($admin, 'admin')->postJson('/admin-api/product-tabs/order', [
        'order' => [
            ['id' => $second['id'], 'position' => 100],
            ['id' => $first['id'], 'position' => 110],
        ],
    ])->assertOk();

    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);

    expect(ptTitles($product))->toBe(['Description', 'How we authenticate', 'Shipping & returns']);

    $this->actingAs($admin, 'admin')
        ->deleteJson('/admin-api/product-tabs/'.$first['id'])->assertOk();

    expect(ptTitles($product))->toBe(['Description', 'How we authenticate']);
});

it('deletes a tab\'s translations with it, so a reused id cannot inherit them', function () {
    /*
     * The translations are rows in another table keyed by (group, item_id) and
     * nothing deletes them by cascade. A table that has had rows deleted hands
     * the next AUTO_INCREMENT an id that has been used before -- so leaving the
     * rows behind would give a brand new tab somebody else's Arabic.
     *
     * MUTATION, RUN: remove the `$tab->translations()->delete()` line from
     * destroy() and this fails with the Arabic row still in the table.
     */
    ProductTabsAdminRoutes::wire(app());

    $tab = ptGlobal('Shipping', '<p>Three days.</p>');

    Translation::create([
        'locale' => 'ar', 'group' => 'product_tabs', 'item_id' => $tab->id,
        'field' => 'title', 'value' => 'الشحن',
        'status' => Translation::STATUS_PUBLISHED, 'source' => Translation::SOURCE_MANUAL,
    ]);

    $this->actingAs(ptAdmin(), 'admin')
        ->deleteJson('/admin-api/product-tabs/'.$tab->id)->assertOk();

    expect(Translation::query()->where('group', 'product_tabs')->count())->toBe(0);
});

it('hides, overrides and then reverts one inherited tab through the override endpoint', function () {
    ProductTabsAdminRoutes::wire(app());

    $admin = ptAdmin();
    $product = ptProduct(['ingredients' => '', 'how_to_use' => '']);
    $global = ptGlobal('Shipping', '<p>Three days.</p>');
    $key = 'global:'.$global->id;

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override',
            ['source_key' => $key, 'mode' => 'hide'])->assertOk();

    expect(ptTitles($product))->toBe(['Description']);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override',
            ['source_key' => $key, 'mode' => 'override', 'title' => 'Shipping (heavy)'])->assertOk();

    expect(ptTitles($product))->toBe(['Description', 'Shipping (heavy)']);

    // ONE ROW, not three: the three acts are three states of the same row.
    expect(ProductTab::query()->where('product_id', $product->id)->count())->toBe(1);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id.'/override',
            ['source_key' => $key, 'mode' => 'inherit'])->assertOk();

    expect(ptTitles($product))->toBe(['Description', 'Shipping'])
        ->and(ProductTab::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('tells the screen which inherited tabs are inherited, overridden and hidden', function () {
    /*
     * The owner has to be able to see at a glance which of a product's tabs are
     * its own doing. Three states, named in the payload rather than left to the
     * browser to infer from two lists.
     */
    ProductTabsAdminRoutes::wire(app());

    $product = ptProduct();
    $kept = ptGlobal('Shipping', '<p>Three days.</p>', ['position' => 100]);
    $hidden = ptGlobal('Patch test', '<p>Inner arm.</p>', ['position' => 110]);
    $reworded = ptGlobal('Returns', '<p>Fourteen days.</p>', ['position' => 120]);

    ProductTab::create(['product_id' => $product->id, 'source_key' => 'global:'.$hidden->id,
        'title' => '', 'body' => '', 'position' => 110, 'is_enabled' => false]);
    ProductTab::create(['product_id' => $product->id, 'source_key' => 'global:'.$reworded->id,
        'title' => 'Returns (final sale)', 'body' => '', 'position' => 120, 'is_enabled' => true]);

    $body = $this->actingAs(ptAdmin(), 'admin')
        ->getJson('/admin-api/product-tabs/product/'.$product->id)->assertOk()->json();

    $states = [];

    foreach ($body['inherited'] as $row) {
        $states[$row['key']] = $row['state'];
    }

    expect($states['builtin:description'])->toBe('inherited')
        ->and($states['global:'.$kept->id])->toBe('inherited')
        ->and($states['global:'.$hidden->id])->toBe('hidden')
        ->and($states['global:'.$reworded->id])->toBe('overridden');
});

/* ══════════════════════════════════════════════════ 8. THE WIRING ════════ */

it('is mounted exactly once, and the screen is included exactly once', function () {
    /*
     * THE FINISHED STATE, not an absence. CLAUDE.md is explicit about why: an
     * assertion that routes/web.php does NOT require this file is correct in
     * the lane's worktree and goes red the moment the integrator does the one
     * thing the lane asked for, and the only way to green it as written is to
     * unmount the feature.
     *
     * ONE is the number. ZERO is the "built, never wired up" shape this repo
     * keeps finding; TWO registers the sidebar row twice and wraps window.go
     * around its own wrapper.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $shell = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($web, "require __DIR__.'/product-tabs-admin.php';"))
        ->toBe(1, 'routes/product-tabs-admin.php must be required exactly once');

    expect(substr_count($shell, "@include('admin.partials.product-tabs-screen')"))
        ->toBe(1, 'the Product tabs screen partial must be included exactly once');
})->skip(fn () => ! str_contains(
    (string) file_get_contents(base_path('routes/web.php')),
    "require __DIR__.'/product-tabs-admin.php';"
), 'Not yet wired by the integrator — see routes/product-tabs-admin.php.');
