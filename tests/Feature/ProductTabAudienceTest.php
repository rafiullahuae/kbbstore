<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTab;
use App\Support\ProductTabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductTabsAdminRoutes;

/**
 * WHERE a global tab shows. (Lane PT, round 2)
 *
 * The owner, with a red arrow on Catalog -> Product tabs -> Global tabs:
 *
 *   "on add product tag, i want to choose specific product, category, brand or
 *    sets products or GLOBAL, so it will show according as per the selection
 *    criteria."
 *
 * ── WHAT WOULD BE WRONG ON THE SHOP WITHOUT EACH OF THESE ─────────────────
 *
 * Every case below says in its own comment what the defect looks like to a
 * shopper, because "the matcher returns false" is not a symptom anybody can
 * recognise. The two shapes worth naming up front:
 *
 *   A TAB ON A PRODUCT IT IS WRONG FOR. "Patch test advice" on a rechargeable
 *   LED mask, or an ingredients policy on a gift card. Visible, embarrassing,
 *   and the thing the owner asked for this feature to stop.
 *
 *   A TAB ON NOTHING AT ALL. The owner writes a shipping paragraph, targets it
 *   at "Skincare", saves, opens a toner and it is not there. Nothing says why.
 *   That is the failure the category-inheritance case below is about, and it is
 *   the one that makes somebody conclude the feature does not work.
 */
function paAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Tabs owner',
        'email' => 'pa-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function paCategory(string $name, ?Category $parent = null): Category
{
    $slug = Str::slug($name).'-'.Str::lower(Str::random(5));

    $category = Category::create([
        'name' => $name,
        'slug' => $slug,
        'parent_id' => $parent?->id,
        'depth' => $parent ? ((int) $parent->depth + 1) : 0,
    ]);

    $category->path = $parent ? ($parent->path.'/'.$slug) : $slug;
    $category->save();

    return $category;
}

function paProduct(array $overrides = []): Product
{
    $product = Product::create(array_merge([
        'slug' => 'pa-'.Str::lower(Str::random(10)),
        'name' => 'A product',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        // Every fixture carries a description, so the ONLY thing that can move
        // the tab list in these cases is the targeting rule under test.
        'description' => '<p>Copy.</p>',
    ], $overrides));

    if (isset($overrides['category_id'])) {
        $product->categories()->syncWithoutDetaching([$overrides['category_id']]);
    }

    return $product->refresh();
}

/** A global tab with a targeting rule. */
function paTab(string $title, string $audience = 'global', array $ids = [], array $overrides = []): ProductTab
{
    return ProductTab::create(array_merge([
        'product_id' => null,
        'source_key' => null,
        'title' => $title,
        'body' => '<p>'.$title.' body.</p>',
        'position' => ProductTabs::DEFAULT_GLOBAL_POSITION,
        'is_enabled' => true,
        'audience' => $audience,
        'audience_ids' => $ids === [] ? null : $ids,
    ], $overrides));
}

/** @return list<string> the titles this product page would draw, in order */
function paTitles(Product $product): array
{
    return array_map(
        static fn (array $t): string => $t['title'],
        ProductTabs::forProduct($product->fresh(['categories']), [])
    );
}

beforeEach(function () {
    ProductTabs::flush();
});

/* ══════════════════════════════════════════ 1. global — the default ══════ */

it('shows a global tab on every product, which is what a tab written before today does', function () {
    /*
     * ON THE SHOP: this is the behaviour that already existed, and the whole
     * point of shipping `audience` DEFAULT 'global'. If the migration had made
     * the column nullable-and-unmatched, every tab the owner had already
     * written would have vanished from the shop the moment the package applied
     * -- the worst possible first impression of a feature that is meant to add
     * control, not take copy away.
     *
     * MUTATION, RUN: change the column default in
     * 2027_04_28_000000_add_product_tab_audience to 'products' and this fails
     * with both products showing Description alone.
     */
    $one = paProduct(['name' => 'One']);
    $two = paProduct(['name' => 'Two', 'type' => 'set']);

    paTab('Shipping & returns');

    expect(paTitles($one))->toBe(['Description', 'Shipping & returns'])
        ->and(paTitles($two))->toBe(['Description', 'Shipping & returns']);
});

it('reads a row written before these columns existed as global', function () {
    /*
     * ON THE SHOP: a row whose `audience` is NULL, '' or a word this build does
     * not know -- a hand-edited row, or one restored from a backup taken before
     * the package -- must keep showing where it showed. audienceOf() collapses
     * all three to 'global' on the way out of the cache.
     *
     * MUTATION, RUN: make audienceOf() return its argument and this fails, the
     * tab disappearing from a shop that never asked for anything to change.
     */
    $product = paProduct();
    $tab = paTab('Shipping');

    DB::table('product_tabs')->where('id', $tab->id)->update(['audience' => '']);
    ProductTabs::flush();

    expect(paTitles($product))->toBe(['Description', 'Shipping']);
});

/* ══════════════════════════════════════════════ 2. specific products ═════ */

it('shows a products-targeted tab on the products picked and on no others', function () {
    /*
     * ON THE SHOP: without the match, a paragraph the owner wrote for two
     * products -- "this one ships in its own crate" -- appears on all seven
     * hundred. With a match that is inverted, it appears on the six hundred and
     * ninety-eight it is wrong for and not on the two it is right for.
     *
     * MUTATION, RUN: change `in_array` to `! in_array` in showsOn()'s
     * `products` branch and this fails in exactly that inverted shape.
     */
    $picked = paProduct(['name' => 'Picked']);
    $other = paProduct(['name' => 'Other']);

    paTab('Ships in its own crate', 'products', [$picked->id]);

    expect(paTitles($picked))->toBe(['Description', 'Ships in its own crate'])
        ->and(paTitles($other))->toBe(['Description']);
});

/* ═════════════════════════════════════════════════════ 3. categories ═════ */

it('shows a category-targeted tab on a product in that category', function () {
    $skincare = paCategory('Skincare');
    $devices = paCategory('Devices');

    $toner = paProduct(['name' => 'Toner', 'category_id' => $skincare->id]);
    $mask = paProduct(['name' => 'Mask', 'category_id' => $devices->id]);

    paTab('Ingredients policy', 'categories', [$skincare->id]);

    expect(paTitles($toner))->toBe(['Description', 'Ingredients policy'])
        ->and(paTitles($mask))->toBe(['Description']);
});

it('shows a tab targeted at a PARENT category on a product in a child of it', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE DECISION: A CHILD INHERITS ITS PARENT'S TAB. This case is the pin.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ON THE SHOP, WITHOUT IT: the shop nests product_cat FOUR LEVELS DEEP
     * (Skincare -> Face cleansers -> Makeup removers) and a product lives at
     * the bottom of that chain. The owner ticks "Skincare", saves, opens a
     * toner, and the tab is not there. Nothing on either screen says why. He
     * would reasonably conclude the feature does not work -- which is exactly
     * what happened with the coupon editor, and CLAUDE.md records that a screen
     * whose behaviour cannot be told apart from a missing screen is the worse
     * outcome.
     *
     * The alternative failure -- a tab one level too wide -- is VISIBLE on the
     * product page and already has a fix the owner has: "Hide on this product".
     * The missing one has neither.
     *
     * MUTATION, RUN: make categoryFamily() return only the ids it was given
     * (delete the queue walk) and this fails with the toner showing Description
     * alone, while the case above it still passes -- which is the whole reason
     * both exist.
     */
    $skincare = paCategory('Skincare');
    $cleansers = paCategory('Face cleansers', $skincare);
    $removers = paCategory('Makeup removers', $cleansers);

    $deep = paProduct(['name' => 'Cleansing balm', 'category_id' => $removers->id]);
    $outside = paProduct(['name' => 'Hair oil', 'category_id' => paCategory('Hair')->id]);

    paTab('Ingredients policy', 'categories', [$skincare->id]);

    expect(paTitles($deep))->toBe(['Description', 'Ingredients policy'],
        'a product three levels under the category ticked must inherit the tab')
        ->and(paTitles($outside))->toBe(['Description'],
            'and a product outside that branch must not');
});

it('matches on the many-to-many as well as on the primary category column', function () {
    /*
     * ON THE SHOP: ProductEditorApiController names this as the landmine in
     * that file -- `products.category_id` is the primary category, the archive
     * filters through the `categories` pivot, and "the two must move together".
     * A rule reading only one of them puts a tab on a product the owner can see
     * in that category, or leaves it off one he can, depending on which of the
     * two a past importer happened to write.
     *
     * MUTATION, RUN: delete the `$primary` block from productCategoryIds() and
     * this fails on the primary-only product.
     */
    $sun = paCategory('Sun care');

    $primaryOnly = paProduct(['name' => 'Primary only']);
    $primaryOnly->category_id = $sun->id;
    $primaryOnly->save();

    $pivotOnly = paProduct(['name' => 'Pivot only']);
    $pivotOnly->categories()->syncWithoutDetaching([$sun->id]);

    paTab('Sun care advice', 'categories', [$sun->id]);

    expect(paTitles($primaryOnly))->toContain('Sun care advice')
        ->and(paTitles($pivotOnly))->toContain('Sun care advice');
});

it('corrects itself when a category is moved under another one', function () {
    /*
     * ON THE SHOP: the owner drags "Toners" under "Skincare" on the Categories
     * screen. Every toner should pick up the Skincare tab on the next page
     * view. Without the eviction it picks it up whenever the cache happens to
     * be rebuilt -- which on a file cache is "never", so the shop is wrong
     * until somebody edits a tab.
     *
     * MUTATION, RUN: remove booted() from App\Models\Category and this fails.
     */
    $skincare = paCategory('Skincare');
    $toners = paCategory('Toners');

    $toner = paProduct(['name' => 'Toner', 'category_id' => $toners->id]);

    paTab('Ingredients policy', 'categories', [$skincare->id]);

    expect(paTitles($toner))->toBe(['Description']);

    $toners->parent_id = $skincare->id;
    $toners->save();

    expect(paTitles($toner))->toBe(['Description', 'Ingredients policy']);
});

it('survives a category loop instead of hanging the product page', function () {
    /*
     * `categories.parent_id` is a self-referencing foreign key and nothing in
     * this application refuses a loop. Without the visited set, walking one is
     * an infinite loop inside a storefront request -- a product page that never
     * answers, which is worse than any wrong tab.
     *
     * MUTATION, RUN: delete the `isset($family[$id])` guard from
     * categoryFamily() and this case hangs until the suite's time limit.
     */
    $a = paCategory('A');
    $b = paCategory('B', $a);

    // Close the loop behind Eloquent's back, because the model would not.
    DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);
    ProductTabs::flush();

    $family = ProductTabs::categoryFamily([$a->id]);

    expect(array_keys($family))->toEqualCanonicalizing([$a->id, $b->id]);
});

/* ═════════════════════════════════════════════════════════ 4. brands ═════ */

it('shows a brand-targeted tab on that brand and on no other', function () {
    /*
     * ON THE SHOP: "How we authenticate — bought direct from Anua" printed
     * under a COSRX product is a claim about a supplier relationship that does
     * not exist. That is not an untidy page, it is a false statement to a
     * shopper.
     *
     * MUTATION, RUN: drop the `in_array` in showsOn()'s `brands` branch and
     * return true, and this fails with the tab on both products.
     */
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::lower(Str::random(5))]);
    $cosrx = Brand::create(['name' => 'COSRX', 'slug' => 'cosrx-'.Str::lower(Str::random(5))]);

    $theirs = paProduct(['name' => 'Toner', 'brand_id' => $anua->id]);
    $others = paProduct(['name' => 'Essence', 'brand_id' => $cosrx->id]);
    $noBrand = paProduct(['name' => 'Unbranded']);

    paTab('How we authenticate', 'brands', [$anua->id]);

    expect(paTitles($theirs))->toBe(['Description', 'How we authenticate'])
        ->and(paTitles($others))->toBe(['Description'])
        ->and(paTitles($noBrand))->toBe(['Description'],
            'a product with no brand at all must not match a brand rule');
});

/* ═══════════════════════════════════════════════════════════ 5. sets ═════ */

it('shows a sets-targeted tab on every set and on no ordinary product', function () {
    /*
     * ON THE SHOP: "What is in the box, and what if one item is out of stock"
     * belongs on a gift set and is nonsense on a single toner.
     *
     * A TYPE MATCH AND NOT A PICKER, which is the half worth pinning: a set
     * created next week is covered without anybody going back to tick it. A
     * picker would have gone stale the first time the owner built a new box,
     * silently.
     *
     * MUTATION, RUN: change the `sets` branch in showsOn() to compare against
     * 'simple' and this fails with the tab on the toner and off the set.
     */
    $set = paProduct(['name' => 'Glow Set', 'type' => 'set']);
    $simple = paProduct(['name' => 'Toner', 'type' => 'simple']);

    paTab('What is in the box', 'sets');

    expect(paTitles($set))->toBe(['Description', 'What is in the box'])
        ->and(paTitles($simple))->toBe(['Description']);

    // The half a picker could not do: a set made AFTER the tab was written.
    $later = paProduct(['name' => 'Barrier Set', 'type' => 'set']);

    expect(paTitles($later))->toBe(['Description', 'What is in the box']);
});

/* ═══════════════════════════════════════════════════ 6. the union ════════ */

it('shows the union when a product matches several rules, each tab exactly once', function () {
    /*
     * ON THE SHOP: a toner that is in Skincare, is an Anua product and is one
     * of the two named in a products rule should show all four tabs, in the one
     * sort order the screen already has -- and each of them ONCE. A duplicate
     * is not cosmetic: the strip and the accordion are both drawn from this
     * list, `0 === $i` decides which panel is open, and two panels with the
     * same heading is a tab that appears to do nothing when you click it.
     *
     * MUTATION, RUN: append the entry inside showsOn() rather than returning
     * from it -- i.e. let a tab be added once per matching rule -- and this
     * fails on the count.
     */
    $skincare = paCategory('Skincare');
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::lower(Str::random(5))]);

    $toner = paProduct([
        'name' => 'Heartleaf Toner',
        'category_id' => $skincare->id,
        'brand_id' => $anua->id,
    ]);

    paTab('Everywhere', 'global', [], ['position' => 100]);
    paTab('This product', 'products', [$toner->id], ['position' => 110]);
    paTab('This category', 'categories', [$skincare->id], ['position' => 120]);
    paTab('This brand', 'brands', [$anua->id], ['position' => 130]);

    $titles = paTitles($toner);

    expect($titles)->toBe([
        'Description', 'Everywhere', 'This product', 'This category', 'This brand',
    ]);

    expect(count($titles))->toBe(count(array_unique($titles)),
        'a product matching several rules must not show any tab twice');
});

/* ══════════════════════════════════ 7. a rule that matches nothing ═══════ */

it('leaves no empty tab, no gap in the strip and no stray separator', function () {
    /*
     * ON THE SHOP: a tab that matches nothing must be ABSENT, not rendered
     * empty. The strip and the accordion are both built by iterating this list,
     * and an entry kept-but-blank would draw a heading with nothing under it,
     * an accordion row that opens on nothing, and -- because `.macc-i` carries
     * the bottom border -- a separator line under a row that is not there.
     *
     * Asserted on the RENDERED PAGE and not on the array, because "absent from
     * the list" and "absent from the markup" are different claims and only the
     * second one is what a shopper sees.
     *
     * MUTATION, RUN: in forProduct(), replace the `continue` in the showsOn()
     * guard with an assignment of an empty title, and this fails on the
     * accordion count.
     */
    /*
     * All three built-in tabs filled in, deliberately. With fewer than two
     * surviving tabs DemoContent tops the list up -- behaviour this shop has
     * always had -- and a fixture that triggered it would be measuring the demo
     * content rather than the targeting rules.
     */
    $product = paProduct([
        'name' => 'Plain toner',
        'ingredients' => '<p>Water.</p>',
        'how_to_use' => '<p>Sweep.</p>',
    ]);

    // Titles chosen to be unique strings on the page: "Nothing" is already in
    // the storefront's own copy, so a case asserting its absence would have
    // failed on a sentence that has nothing to do with tabs.
    paTab('Zzmatchesnobody', 'products', [$product->id + 9999]);
    paTab('Zzmatchesnocategory', 'categories', [424242]);

    $html = $this->get('/product/'.$product->slug.'/')->assertOk()->getContent();

    /*
     * `<button class="dtab` and not `class="dtab`, which also matches the
     * strip's own `.dtabbar` and each `.dtabpanel` -- three tabs would have
     * counted as seven, and the case would have been pinning a number nobody
     * could read.
     */
    expect(substr_count($html, '<button class="dtab'))->toBe(3,
        'three tab buttons, for the three built-ins, and not one more')
        ->and(substr_count($html, 'class="macc-i'))->toBe(3,
            'and three accordion rows, with no separator under a row that is not there')
        ->and(str_contains($html, 'Zzmatchesnobody'))->toBeFalse()
        ->and(str_contains($html, 'Zzmatchesnocategory'))->toBeFalse();
});

it('takes the tab off a product that is removed from a targeted category', function () {
    // The mirror of the re-parenting case: the rule did not change, the
    // product did. On the shop, an ingredients policy left behind on a product
    // that is no longer in that category.
    $skincare = paCategory('Skincare');
    $product = paProduct(['name' => 'Toner', 'category_id' => $skincare->id]);

    paTab('Ingredients policy', 'categories', [$skincare->id]);

    expect(paTitles($product))->toContain('Ingredients policy');

    $product->categories()->detach();
    $product->category_id = null;
    $product->save();

    expect(paTitles($product))->toBe(['Description']);
});

/* ═════════════════════════════════════════════════ 8. what it costs ══════ */

it('does not cost a query per tab, or per rule type', function () {
    /*
     * THE BUDGET, AND WHY THIS CASE IS SHAPED AS FLATNESS. An N+1 here is on
     * EVERY PRODUCT PAGE IN THE SHOP, and a budget alone cannot catch one: a
     * page doing a lookup per tab passes any ceiling on a fixture with two
     * tabs. So the same page is measured with ONE global tab and again with
     * SIXTEEN spread across all five rule types, and the two counts must be
     * identical.
     *
     * Everything the matcher reads is already in hand: the tabs come out of one
     * cached entry, `type` and `brand_id` are columns on the product, the
     * category tree is a second cached entry, and the product's own categories
     * are resolved at most once per page by a memoised closure.
     *
     * THE PRODUCT IS LOADED WITHOUT ITS CATEGORIES ON PURPOSE. On the product
     * page Store\ProductController eager-loads them, so a page that read the
     * relation per tab would cost nothing there and this case would pass
     * against the N+1 it exists to catch. forProduct() is a public support
     * function and the next caller -- a grid, an API projection, a mail
     * template -- will not have eager-loaded anything. Measured on the harder
     * of the two, which is the only one that can fail.
     *
     * MUTATION, RUN: give showsOn() a second `whereIn` of its own -- for
     * instance resolve the family with a Category query rather than through the
     * cached tree -- and this fails with 3 against 6. The case below it is the
     * one that pins the LAZINESS, which is a different property and has its own
     * mutation.
     */
    $measure = function (int $extra): int {
        ProductTab::query()->delete();
        ProductTabs::flush();

        $skincare = paCategory('Skincare');
        $brand = Brand::create(['name' => 'B', 'slug' => 'b-'.Str::lower(Str::random(6))]);

        $product = paProduct([
            'name' => 'Measured',
            'category_id' => $skincare->id,
            'brand_id' => $brand->id,
        ]);

        paTab('Always', 'global', [], ['position' => 100]);

        for ($i = 0; $i < $extra; $i++) {
            paTab('P'.$i, 'products', [$product->id], ['position' => 200 + $i]);
            paTab('C'.$i, 'categories', [$skincare->id], ['position' => 300 + $i]);
            paTab('B'.$i, 'brands', [$brand->id], ['position' => 400 + $i]);
            paTab('S'.$i, 'sets', [], ['position' => 500 + $i]);
        }

        ProductTabs::flush();

        // Warm the caches the way a second request finds them, exactly as
        // StorefrontQueryBudgetTest's warm-up pass does. A SEPARATE instance
        // each time, and neither with `categories` loaded -- see above.
        ProductTabs::forProduct(Product::query()->find($product->id), []);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        ProductTabs::forProduct(Product::query()->find($product->id), []);

        return $queries;
    };

    /*
     * ONE OF EACH RULE TYPE AGAINST FOUR OF EACH, rather than none against
     * four. The question is whether the cost grows with the NUMBER OF TABS --
     * that is the N+1 -- not whether a shop that uses a rule type pays for it
     * once. Starting from zero would have compared a page that never touches
     * the categories relation with one that does, and reported a difference of
     * one that says nothing about scaling.
     */
    $four = $measure(1);
    $sixteen = $measure(4);

    expect($sixteen)->toBe($four,
        'sixteen targeted tabs must cost what four cost: '.$four.' against '.$sixteen);
});

it('costs a shop with no category-targeted tab nothing for the feature', function () {
    /*
     * THE LAZINESS, and it is a different property from flatness above.
     *
     * A product's category list is the one input the matcher cannot read off a
     * column or a cached entry -- it is a relation. Resolving it up front,
     * before looking at what the tabs actually target, would put a query on
     * EVERY PRODUCT PAGE IN THE SHOP in exchange for a rule type most shops
     * never use. That is the shape CLAUDE.md records SettingsService::get()
     * having on 105 keys: not a bug anybody can see, just every page slower
     * than it needs to be, for ever.
     *
     * So the closure is called only from showsOn()'s `categories` branch, and a
     * shop whose tabs are all `global`, `products`, `brands` or `sets` costs
     * exactly what a shop with no tabs at all costs.
     *
     * MUTATION, RUN: call $categoryIds() once at the top of showsOn(), before
     * the audience is looked at, and this fails with 1 against 2.
     */
    $brand = Brand::create(['name' => 'B', 'slug' => 'b-'.Str::lower(Str::random(6))]);
    $product = paProduct(['name' => 'Measured', 'brand_id' => $brand->id]);

    $measure = function () use ($product): int {
        ProductTabs::flush();

        ProductTabs::forProduct(Product::query()->find($product->id), []);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        ProductTabs::forProduct(Product::query()->find($product->id), []);

        return $queries;
    };

    $bare = $measure();

    paTab('Everywhere', 'global');
    paTab('This one', 'products', [$product->id]);
    paTab('This brand', 'brands', [$brand->id]);
    paTab('The sets', 'sets');

    expect($measure())->toBe($bare,
        'four tabs that never mention a category must not make the page read one');
});

/* ═══════════════════════════════════════════════════════ 9. security ═════ */

it('stores one of its own options, never the word that arrived', function () {
    /*
     * CLAUDE.md rule 5. `audience` decides which product pages a paragraph is
     * printed on, so it is checked twice against the same list -- `Rule::in`
     * in the validator and audienceOf() before the write -- the way
     * SetStockApiController checks its two-value switch.
     *
     * MUTATION, RUN: assign `$data['audience']` straight to `$tab->audience` in
     * fill() and the second half of this case fails, the column holding
     * 'everywhere' and the matcher then showing the tab nowhere at all.
     */
    ProductTabsAdminRoutes::wire(app());

    $admin = paAdmin();

    foreach (['everywhere', 'GLOBAL; drop', '../products', '1'] as $bad) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/product-tabs', [
            'title' => 'Shipping',
            'body' => '<p>x</p>',
            'audience' => $bad,
        ])->assertStatus(422, 'refused: '.$bad);
    }

    expect(ProductTab::query()->count())->toBe(0);

    // And the same check one layer down: a value that somehow bypassed the
    // validator still cannot be stored.
    expect(ProductTabs::audienceOf('everywhere'))->toBe('global')
        ->and(ProductTabs::audienceOf(['products']))->toBe('global')
        ->and(ProductTabs::audienceOf('CATEGORIES'))->toBe('categories');
});

it('refuses a rule whose targets name rows that do not exist', function () {
    /*
     * ON THE SHOP: a tab pointed at a category that has since been deleted
     * shows on no product and gives the owner no clue why. The row looks right
     * and the screen looks right; the tab is simply missing. Refusing the write
     * is the one moment that is explainable.
     *
     * MUTATION, RUN: return null from missingTargets() unconditionally and this
     * fails with a 201 and a tab that can never appear.
     */
    ProductTabsAdminRoutes::wire(app());

    $this->actingAs(paAdmin(), 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'Shipping',
        'body' => '<p>x</p>',
        'audience' => 'categories',
        'audience_ids' => [999999],
    ])->assertStatus(422);

    expect(ProductTab::query()->count())->toBe(0);
});

it('drops anything in the id list that is not a positive integer', function () {
    // "7abc" is not product 7, and (int) would say it was. Dropped rather than
    // coerced, on the way in and on the way out.
    expect(ProductTabs::idsOf(['7abc', '0', -3, 4.5, null, '12', 9, true]))->toBe([12, 9])
        ->and(ProductTabs::idsOf('[3,"4","x"]'))->toBe([3, 4])
        ->and(ProductTabs::idsOf('not json'))->toBe([])
        ->and(count(ProductTabs::idsOf(range(1, 500))))->toBe(ProductTabs::MAX_AUDIENCE_IDS);
});

it('clears the target list when the rule moves to one that has no targets', function () {
    /*
     * A row saying `global` while still carrying eleven product ids is a row
     * whose meaning depends on which field the next reader looks at -- and the
     * next reader is a matcher written a year from now.
     */
    ProductTabsAdminRoutes::wire(app());

    $admin = paAdmin();
    $product = paProduct();

    $tab = $this->actingAs($admin, 'admin')->postJson('/admin-api/product-tabs', [
        'title' => 'Shipping',
        'body' => '<p>x</p>',
        'audience' => 'products',
        'audience_ids' => [$product->id],
    ])->assertStatus(201)->json('tab');

    expect($tab['audience_ids'])->toBe([$product->id]);

    $moved = $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/product-tabs/'.$tab['id'], [
            'title' => 'Shipping',
            'audience' => 'global',
            'audience_ids' => [$product->id],
        ])->assertOk()->json('tab');

    expect($moved['audience'])->toBe('global')
        ->and($moved['audience_ids'])->toBe([])
        ->and(ProductTab::find($tab['id'])->audience_ids)->toBeNull();
});

it('never lets a per-product tab or an override carry a targeting rule', function () {
    /*
     * A per-product tab already names its product and an override already names
     * the tab it covers, so neither has an audience to choose. The key is left
     * out of THEIR rules, which is what makes the write path unable to set one
     * -- rather than a comment asking the next reader not to.
     *
     * MUTATION, RUN: pass `true` unconditionally as rules()' second argument
     * and this fails, the per-product row coming back targeted at a brand.
     */
    ProductTabsAdminRoutes::wire(app());

    $admin = paAdmin();
    $product = paProduct();
    $brand = Brand::create(['name' => 'B', 'slug' => 'b-'.Str::lower(Str::random(6))]);

    $own = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/product-tabs/product/'.$product->id, [
            'title' => 'Mine',
            'body' => '<p>Mine.</p>',
            'audience' => 'brands',
            'audience_ids' => [$brand->id],
        ])->assertStatus(201)->json('tab');

    expect($own['audience'])->toBe('global', 'a per-product row keeps the default')
        ->and($own['audience_ids'])->toBe([]);
});

it('never prints a targeting rule on the shop', function () {
    /*
     * `/api/*` is unauthenticated and the product page is public. A tab's rule
     * names PRODUCT, CATEGORY AND BRAND IDS -- including, for a products rule,
     * the ids of products that may be draft or private. The storefront list is
     * an allowlist of two keys and always was; this case exists so the third
     * key cannot be added quietly by somebody passing the cached row straight
     * through.
     *
     * MUTATION, RUN: add `'audience' => $entry['audience']` to the `$out[]` in
     * forProduct() and this fails.
     */
    $secret = paProduct(['name' => 'Unreleased', 'status' => 'draft', 'is_visible' => false]);
    $shown = paProduct(['name' => 'Toner']);

    paTab('Shipping', 'products', [$shown->id, $secret->id]);

    foreach (ProductTabs::forProduct($shown->fresh(['categories']), []) as $tab) {
        expect(array_keys($tab))->toBe(['title', 'body'],
            'the storefront gets a title and a body and nothing else');
    }

    $html = $this->get('/product/'.$shown->slug.'/')->assertOk()->getContent();

    /*
     * The NAME and not the id: a bare id is a digit that appears all over an
     * HTML page for reasons that have nothing to do with tabs, so asserting on
     * one would be a case that passes or fails on the fixture's row numbers.
     * The name of an unreleased product is the thing that would actually be a
     * leak, and `audience` is the key that would carry it.
     */
    expect(str_contains($html, 'audience'))->toBeFalse()
        ->and(str_contains($html, 'Unreleased'))->toBeFalse(
            'a draft product named in a tab rule must not reach a public page'
        );
});

it('holds the five options identical to the ones the screen offers', function () {
    /*
     * The screen draws its select from the SERVER's vocabulary, so the values
     * it offers cannot drift from the ones the validator accepts -- which is
     * the difference between a control that quietly saves nothing and one that
     * cannot be wrong.
     */
    ProductTabsAdminRoutes::wire(app());

    $body = $this->actingAs(paAdmin(), 'admin')
        ->getJson('/admin-api/product-tabs')->assertOk()->json();

    expect($body['audiences'])->toBe(['global', 'products', 'categories', 'brands', 'sets'])
        ->and($body['audience_default'])->toBe('global');
});
