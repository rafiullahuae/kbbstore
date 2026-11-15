<?php

declare(strict_types=1);

/**
 * Catalog -> Labels (Lane LBL): the owner's own badges, approved from
 * docs/plans/product-labels/ on 15 Nov ("make sure no compromise on the
 * speed"). Each case names the defect it would catch as the shop or the
 * console would show it, and a MUTATION note that turns it red.
 */

use App\Http\Controllers\Admin\CustomLabelsApiController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CustomLabels;
use App\Services\ProductStyles;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\CardFragments;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductLabelsAdminRoutes;

function lblProduct(string $name, array $o = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'lbl-t-'.$n.'-'.substr(md5($name), 0, 6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ], $o));
}

/** A label straight into the tables, then the same rebuild a save runs. */
function lblLabel(array $l, array $picks = [], array $cats = [], array $excl = [], bool $deep = true): int
{
    $id = (int) DB::table('product_labels')->insertGetId(array_merge([
        'name' => 'Best Seller', 'text' => 'Best Seller', 'text_ar' => 'الأكثر مبيعاً', 'bg' => '#C13E63', 'fg' => '#FFFFFF',
        'shape' => 'A', 'placement' => 'both', 'mode' => 'both', 'priority' => 1, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $l));

    foreach ($picks as $p) {
        DB::table('product_label_targets')->insert(['label_id' => $id, 'kind' => 'product', 'target_id' => $p, 'with_children' => false]);
    }
    foreach ($cats as $c) {
        DB::table('product_label_targets')->insert(['label_id' => $id, 'kind' => 'category', 'target_id' => $c, 'with_children' => $deep]);
    }
    foreach ($excl as $p) {
        DB::table('product_label_targets')->insert(['label_id' => $id, 'kind' => 'exclude', 'target_id' => $p, 'with_children' => false]);
    }

    CustomLabels::saved();

    return $id;
}

function lblAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'LBL '.$role, 'email' => 'lbl-'.$role.'-'.uniqid().'@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function lblBody(array $o = []): array
{
    return array_merge([
        'name' => 'Best Seller', 'name_ar' => 'الأكثر مبيعاً', 'text' => 'Best Seller', 'text_ar' => 'الأكثر مبيعاً',
        'bg' => '#C13E63', 'fg' => '#FFFFFF', 'shape' => 'A', 'placement' => 'both', 'mode' => 'products',
        'starts_on' => null, 'ends_on' => null, 'priority' => 1, 'active' => true,
        'picks' => [], 'categories' => [], 'with_children' => true, 'excluded' => [],
    ], $o);
}

/** The label ids the shop map gives a product for cards, as the card reads them. */
function lblIds(Product $p, string $where = 'cards'): array
{
    return array_column(CustomLabels::forProduct((int) $p->id, $where), 'id');
}

/*
|------------------------------------------------------------------------------
| RULES RESOLVE
|------------------------------------------------------------------------------
*/

it('resolves picks, categories with and without sub-categories, both together and exclusions', function () {
    /*
     * THE DEFECT: "Sun care, including sub-categories" put the label on Sun
     * care's own products and not on the sunscreen filed under its child, or
     * an excluded product kept its badge.
     * MUTATION: drop `$expand(..., $deep)`'s walk (return [$root] always), or
     * the `unset($set[$pid])` exclusion loop in CustomLabels::resolve(). RED.
     */
    $sun = Category::create(['name' => 'Sun care', 'slug' => 'lbl-sun']);
    $kid = Category::create(['name' => 'Sticks', 'slug' => 'lbl-sticks', 'parent_id' => $sun->id]);
    $a = lblProduct('Parent shelf cream');
    $b = lblProduct('Child shelf stick');
    $c = lblProduct('Hand picked toner');
    $x = lblProduct('Excluded sunscreen');
    DB::table('category_product')->insert([
        ['product_id' => $a->id, 'category_id' => $sun->id], ['product_id' => $b->id, 'category_id' => $kid->id], ['product_id' => $x->id, 'category_id' => $sun->id],
    ]);

    $deep = lblLabel(['name' => 'Deep'], [$c->id], [$sun->id], [$x->id]);
    $flat = lblLabel(['name' => 'Flat', 'priority' => 2], [], [$sun->id], [], false);

    expect(lblIds($a))->toBe([$deep, $flat])
        ->and(lblIds($b))->toBe([$deep])            // child shelf: only the deep one
        ->and(lblIds($c))->toBe([$deep])            // picked
        ->and(lblIds($x))->toBe([$flat]);           // excluded from Deep only
});

it('honours the mode: products ignores the ticked categories, categories ignores the picks', function () {
    /*
     * THE DEFECT: the owner switches a label to "Pick products" and it keeps
     * covering a whole category he can no longer see in the editor.
     * MUTATION: delete either `$mode !== ...` guard in CustomLabels::resolve(). RED.
     */
    $cat = Category::create(['name' => 'Toners', 'slug' => 'lbl-toners']);
    $inCat = lblProduct('In the category');
    $picked = lblProduct('Picked by hand');
    DB::table('category_product')->insert(['product_id' => $inCat->id, 'category_id' => $cat->id]);

    $p = lblLabel(['name' => 'P', 'mode' => 'products'], [$picked->id], [$cat->id]);
    $c = lblLabel(['name' => 'C', 'mode' => 'categories', 'priority' => 2], [$picked->id], [$cat->id]);

    expect(lblIds($picked))->toBe([$p])->and(lblIds($inCat))->toBe([$c]);
});

it('shows a label only inside its store-time date window and only while it is on', function () {
    /*
     * THE DEFECT: "Ramadan Pick" from 8 Feb showed in November, or kept
     * showing after its last day, or a label switched off stayed on the cards.
     * MUTATION: delete the `$live` window test in CustomLabels::current(), or
     * the `where('active', true)` in resolve(). RED.
     */
    $p = lblProduct('Dated product');
    $today = \App\Support\StoreTime::now()->format('Y-m-d');
    $tomorrow = \App\Support\StoreTime::now()->addDay()->format('Y-m-d');
    $yesterday = \App\Support\StoreTime::now()->subDay()->format('Y-m-d');

    $future = lblLabel(['name' => 'Future', 'starts_on' => $tomorrow], [$p->id]);
    $past = lblLabel(['name' => 'Past', 'ends_on' => $yesterday], [$p->id]);
    $todayOnly = lblLabel(['name' => 'Today', 'starts_on' => $today, 'ends_on' => $today, 'priority' => 3], [$p->id]);
    $off = lblLabel(['name' => 'Off', 'active' => false], [$p->id]);

    expect(lblIds($p))->toBe([$todayOnly]);

    $this->travelTo(now()->addDays(2));
    CustomLabels::saved();
    expect(lblIds($p))->toBe([$future]);
});

it('orders by priority, newer first on a tie, and stops at two per card', function () {
    /*
     * THE DEFECT: five labels on one product stacked five badges over the
     * photo, or the owner's "1" did not come first.
     * MUTATION: MAX_PER_CARD = 3, or orderBy('priority') removed. RED.
     */
    $p = lblProduct('Popular product');
    $low = lblLabel(['name' => 'Low', 'priority' => 9], [$p->id]);
    $older = lblLabel(['name' => 'Older', 'priority' => 2], [$p->id]);
    $newer = lblLabel(['name' => 'Newer', 'priority' => 2], [$p->id]);
    $first = lblLabel(['name' => 'First', 'priority' => 1], [$p->id]);

    expect(lblIds($p))->toBe([$first, $newer]);
});

it('keeps each label to its placement: cards, the product page, or both', function () {
    /*
     * MUTATION: drop the `$l['pl'] !== $where` test in forProduct(). RED.
     */
    $p = lblProduct('Placed product');
    $cards = lblLabel(['name' => 'Cards only', 'placement' => 'cards'], [$p->id]);
    $page = lblLabel(['name' => 'Page only', 'placement' => 'page', 'priority' => 2], [$p->id]);

    expect(lblIds($p, 'cards'))->toBe([$cards])->and(lblIds($p, 'page'))->toBe([$page]);
});

/*
|------------------------------------------------------------------------------
| THE SHOP: cards and the product page, English and Arabic, option E
|------------------------------------------------------------------------------
*/

it('draws the labels on the shop card and on the product page, with the stylesheet once, in English and Arabic', function () {
    /*
     * THE DEFECT: a label saved in the console that the shop never shows, or
     * shows unstyled (no stylesheet on the page), or in English on /ar.
     * MUTATION: delete the CustomLabels block in components/product-card, or
     * the composer in CustomLabelsServiceProvider, or the `ta` fallback. RED.
     */
    $p = lblProduct('Glow Serum 30ml');
    lblLabel(['name' => 'Best Seller', 'text' => 'Best Seller', 'text_ar' => 'الأكثر مبيعاً'], [$p->id]);
    lblLabel(['name' => 'Award', 'text' => 'Award Winner', 'text_ar' => '', 'placement' => 'page', 'bg' => '#2A2228', 'priority' => 2], [$p->id]);

    $shop = $this->get('/shop/')->assertOk()->getContent();
    expect($shop)->toContain('<span class="plb-s"><span class="plb plb-A" style="background:#C13E63;color:#FFFFFF">Best Seller</span></span>')
        ->and(substr_count($shop, '<style id="kbb-plb">'))->toBe(1)
        ->and($shop)->not->toContain('Award Winner');   // page-only stays off the card

    $page = $this->get('/product/'.$p->slug.'/')->assertOk()->getContent();
    expect($page)->toContain('<div class="plb-t"><span class="plb plb-A" style="background:#C13E63;color:#FFFFFF">Best Seller</span><span class="plb plb-A" style="background:#2A2228;color:#FFFFFF">Award Winner</span></div>')
        ->and(substr_count($page, '<style id="kbb-plb">'))->toBe(1);

    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    $ar = $this->get('/ar/shop/')->assertOk()->getContent();
    expect($ar)->toContain('<span class="plb plb-A" style="background:#C13E63;color:#FFFFFF">الأكثر مبيعاً</span>')
        ->and($ar)->not->toContain('>Best Seller</span>');
    // An empty Arabic text falls back to the English one, never to nothing.
    app()->setLocale('ar');
    expect(collect(CustomLabels::forProduct((int) $p->id, 'page'))->pluck('text')->all())->toBe(['الأكثر مبيعاً', 'Award Winner']);
});

it('escapes the badge text wherever it is printed', function () {
    /*
     * MUTATION: print `$l['text']` without e() in CustomLabels::badge(). RED.
     */
    $p = lblProduct('Escaped product');
    lblLabel(['text' => '<b>x</b>"\'&'], [$p->id]);

    $html = $this->get('/shop/')->assertOk()->getContent();
    expect($html)->toContain('&lt;b&gt;x&lt;/b&gt;&quot;&#039;&amp;')->and($html)->not->toContain('<b>x</b>');
});

it('takes the top-start corner from New/Bestseller, leaves the sale pill its corner, and never hides Sold out (option E)', function () {
    /*
     * THE DEFECT: a featured product showed "Bestseller" (automatic) beside
     * "Best Seller" (his), or the -20% / Sold out pill vanished under a label.
     * MUTATION: stop setting $kbbStart = null in the card's label block, or
     * gate $kbbEnd / $kbbSoldPill on the labels. RED.
     */
    app(ProductStyles::class)->save(['show_new' => true, 'show_discount' => true]);
    app(ProductStyles::class)->forgetResolved();
    $p = lblProduct('Featured sale product', ['featured' => true, 'price' => 10000, 'sale_price' => 8000]);
    $q = lblProduct('Sold out labelled', ['stock_status' => 'outofstock']);
    $plain = lblProduct('New plain product');

    $before = CardFragments::render($plain);
    lblLabel([], [$p->id, $q->id]);

    $card = CardFragments::render($p->fresh());
    expect($card)->toContain('kbb-badge-sale')              // sale keeps the top-end corner
        ->and($card)->toContain('plb-s plb-e')              // and the labels keep clear of it
        ->and($card)->not->toContain('kbb-badge-new')
        ->and($card)->not->toContain('kbb-badge-best');

    expect(CardFragments::render($q->fresh()))->toContain('kbb-soldout')->and(CardFragments::render($q->fresh()))->toContain('plb-s');
    // A product with no label is the card it always was, byte for byte.
    expect(CardFragments::render($plain->fresh()))->toBe($before)->and($before)->toContain('kbb-badge-new');

    // With Growth & Marketing -> Product Labels ON: its sale badge stays, in the end corner.
    app(SettingsService::class)->setModule('product_labels', true);
    $auto = CardFragments::render($p->fresh());
    expect($auto)->toContain('lbl plb-x')->and($auto)->toContain('plb-s plb-e');
});

/*
|------------------------------------------------------------------------------
| SPEED: no query per card, the same count with 3 or 40 labelled products
|------------------------------------------------------------------------------
*/

it('costs a brand page the same queries with 3 or 40 labelled products, and the same as with no label at all', function () {
    /*
     * THE DEFECT: a label lookup per card -- 40 cards, 40 queries.
     * MUTATION: in CustomLabels::current(), skip the request memo (always
     * build) or read the map with a query per product. RED.
     */
    $brand = Brand::create(['name' => 'Labelbrand', 'slug' => 'labelbrand']);
    $mk = fn (int $from, int $n) => collect(range($from, $from + $n - 1))->map(fn ($i) => lblProduct('LB '.$i, ['brand_id' => $brand->id]))->all();
    $three = $mk(1, 3);

    $this->get('/brands/labelbrand/')->assertOk();
    DB::enableQueryLog();
    $this->get('/brands/labelbrand/')->assertOk();
    $none = count(DB::getQueryLog());

    $id = lblLabel([], array_map(fn ($p) => $p->id, $three));
    $this->get('/brands/labelbrand/')->assertOk();
    DB::flushQueryLog();
    $html = $this->get('/brands/labelbrand/')->assertOk()->getContent();
    $small = count(DB::getQueryLog());
    expect(substr_count($html, 'class="plb plb-A"'))->toBe(3);

    $forty = $mk(10, 40);
    DB::table('product_label_targets')->insert(array_map(fn ($p) => ['label_id' => $id, 'kind' => 'product', 'target_id' => $p->id, 'with_children' => false], $forty));
    CustomLabels::saved();
    $this->get('/brands/labelbrand/')->assertOk();
    DB::flushQueryLog();
    $html = $this->get('/brands/labelbrand/')->assertOk()->getContent();
    $big = count(DB::getQueryLog());

    expect(substr_count($html, 'class="plb plb-A"'))->toBe(43)
        ->and($big)->toBe($small)
        ->and($small)->toBe($none);
});

it('reads nothing at all while no label is on: no cache read, no style, no markup', function () {
    /*
     * THE DEFECT: the feature charging every shop page for a map that is
     * empty -- the owner's speed freeze.
     * MUTATION: drop the `$ver > 0` gate in CustomLabels::current(). RED.
     */
    $p = lblProduct('Unlabelled');
    $reads = 0;
    \Illuminate\Support\Facades\Event::listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], function ($e) use (&$reads) {
        if ($e->key === CustomLabels::CACHE_KEY) {
            $reads++;
        }
    });

    $html = $this->get('/shop/')->assertOk()->getContent();
    expect($reads)->toBe(0)->and($html)->not->toContain('kbb-plb')->and($html)->not->toContain('plb-s');

    lblLabel([], [$p->id]);
    $this->get('/shop/')->assertOk();
    expect($reads)->toBe(1);   // once per request, however many cards
});

/*
|------------------------------------------------------------------------------
| CACHE: a save, a product move and the card cache all refresh at once
|------------------------------------------------------------------------------
*/

it('writes the map on save, so the next shop view shows the new text with no stale card', function () {
    /*
     * THE DEFECT: the owner renames a label and the cards (CardFragments on the
     * product page's recommendations) keep the old one for a day.
     * MUTATION: drop the settings write (the build number) or the map write
     * from CustomLabels::saved(). RED.
     */
    ProductLabelsAdminRoutes::wire($this->app);
    $owner = lblAdmin();
    $p = lblProduct('Cached card');

    $before = CardFragments::many([$p->fresh()]);
    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/labels', lblBody(['picks' => [$p->id], 'text' => 'Old text']))->assertCreated();
    $id = $r->json('id');
    $mid = CardFragments::many([$p->fresh()]);
    expect($mid[$p->id])->toContain('Old text')->and($before[$p->id])->not->toContain('plb');
    expect(Cache::get(CustomLabels::CACHE_KEY)['m'][$p->id] ?? null)->toBe([$id]);

    $this->actingAs($owner, 'admin')->putJson('/admin-api/labels/'.$id, lblBody(['picks' => [$p->id], 'text' => 'New text']))->assertOk();
    $after = CardFragments::many([$p->fresh()]);
    expect($after[$p->id])->toContain('New text')->and($after[$p->id])->not->toContain('Old text');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/labels/'.$id.'/toggle', ['active' => false])->assertOk();
    expect(CardFragments::many([$p->fresh()])[$p->id])->not->toContain('plb');
    expect(CustomLabels::flag())->toBe(0);
});

it('renews a cached card the day its label starts, and when a product joins a labelled category, with no label saved', function () {
    /*
     * THE DEFECT: "Ramadan Pick" from tomorrow, or a sunscreen moved into
     * "Sun care", and the product page's cached recommendation cards keep the
     * old face for a day -- nothing in the settings moved, so only the card's
     * own label ids can tell CardFragments.
     * MUTATION: drop CustomLabels::cardKey() from CardFragments::signature(). RED.
     */
    $cat = Category::create(['name' => 'Ramadan', 'slug' => 'lbl-ramadan']);
    $p = lblProduct('Tomorrow product');
    $q = lblProduct('Moved product');
    lblLabel(['text' => 'Ramadan Pick', 'starts_on' => \App\Support\StoreTime::now()->addDay()->format('Y-m-d')], [$p->id]);
    lblLabel(['text' => 'Sun week', 'mode' => 'categories', 'priority' => 2], [], [$cat->id]);

    $today = CardFragments::many([$p->fresh(), $q->fresh()]);
    expect($today[$p->id])->not->toContain('Ramadan Pick')->and($today[$q->id])->not->toContain('Sun week');

    DB::table('category_product')->insert(['product_id' => $q->id, 'category_id' => $cat->id]);
    CustomLabels::rebuildMap();   // what the after-response rebuild does
    // The first minute of tomorrow in store time: inside the cards' one-day TTL.
    $this->travelTo(\App\Support\StoreTime::now()->addDay()->startOfDay()->addMinute());

    $tomorrow = CardFragments::many([$p->fresh(), $q->fresh()]);
    expect($tomorrow[$p->id])->toContain('Ramadan Pick')->and($tomorrow[$q->id])->toContain('Sun week');
});

it('rebuilds the map after a product joins a labelled category', function () {
    /*
     * THE DEFECT: a sunscreen added to "Sun care" next week never gets the
     * category's label until somebody re-saves the label.
     * MUTATION: delete Product::saved in CustomLabelsServiceProvider. RED.
     */
    $cat = Category::create(['name' => 'Sun', 'slug' => 'lbl-sun-later']);
    $id = lblLabel(['mode' => 'categories'], [], [$cat->id]);
    $p = lblProduct('Next week sunscreen');
    DB::table('category_product')->insert(['product_id' => $p->id, 'category_id' => $cat->id]);
    $p->name = 'Next week sunscreen SPF50';
    $p->save();

    app()->terminate();   // the response has gone; the rebuild runs now
    expect(lblIds($p))->toBe([$id]);
});

it('runs each after-response rebuild once, however many later requests the process serves', function () {
    /*
     * THE DEFECT: Application::terminate() keeps its callbacks, so a queue
     * worker or a test re-ran every scheduled rebuild on every later request.
     * MUTATION: remove the `$job->done` latch in rebuildAfterResponse(). RED.
     */
    $cat = Category::create(['name' => 'Once', 'slug' => 'lbl-once']);
    lblLabel(['mode' => 'categories'], [], [$cat->id]);
    lblProduct('Triggers a rebuild');
    app()->terminate();

    $builds = 0;
    DB::listen(function ($q) use (&$builds) {
        if (str_contains($q->sql, 'product_label_targets')) {
            $builds++;
        }
    });
    app()->terminate();
    app()->terminate();
    expect($builds)->toBe(0);
});

/*
|------------------------------------------------------------------------------
| THE CONSOLE: capability, validation, the index
|------------------------------------------------------------------------------
*/

it('puts every labels endpoint on labels.manage and fails closed for a role without it', function () {
    /*
     * MUTATION: map ['*', 'admin-api/labels/**'] to catalog.view, or drop the
     * pair from AdminCapabilities::RULES. RED.
     */
    ProductLabelsAdminRoutes::wire($this->app);
    foreach (['GET' => 'admin-api/labels', 'POST' => 'admin-api/labels', 'PUT' => 'admin-api/labels/3', 'DELETE' => 'admin-api/labels/3'] as $verb => $path) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('labels.manage');
    }
    expect(AdminCapabilities::forPath('GET', 'admin-api/labels/index'))->toBe('labels.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/labels/3/toggle'))->toBe('labels.manage');

    $this->getJson('/admin-api/labels')->assertStatus(401);
    foreach (['support', 'editor'] as $role) {
        $u = lblAdmin($role);
        $this->actingAs($u, 'admin')->getJson('/admin-api/labels')->assertForbidden();
        $this->actingAs($u, 'admin')->getJson('/admin-api/labels/index')->assertForbidden();
        $this->actingAs($u, 'admin')->postJson('/admin-api/labels', lblBody())->assertForbidden();
    }
    expect(DB::table('product_labels')->count())->toBe(0);
    $this->actingAs(lblAdmin('manager'), 'admin')->getJson('/admin-api/labels')->assertOk()->assertJsonStructure(['labels', 'today']);
});

it('refuses junk: colours that are not #hex, shapes and places outside their options, long text, ids that do not exist, dates out of order', function () {
    /*
     * THE DEFECT: `bg = "red;background:url(//x)"` written into a style
     * attribute on every card, or a label pointing at a deleted product.
     * MUTATION: drop the regex on bg/fg, the `in:` on shape, or the existence
     * check in validated(). RED.
     */
    ProductLabelsAdminRoutes::wire($this->app);
    $owner = lblAdmin();
    $p = lblProduct('Exists');
    $post = fn (array $o) => $this->actingAs($owner, 'admin')->postJson('/admin-api/labels', lblBody($o));

    $post(['bg' => 'red;background:url(//x)'])->assertUnprocessable();
    $post(['fg' => '#FFF'])->assertUnprocessable();
    $post(['shape' => 'E'])->assertUnprocessable();
    $post(['placement' => 'header'])->assertUnprocessable();
    $post(['mode' => 'all'])->assertUnprocessable();
    $post(['text' => str_repeat('x', 23)])->assertUnprocessable();
    $post(['text' => ''])->assertUnprocessable();
    $post(['priority' => 0])->assertUnprocessable();
    $post(['picks' => [$p->id + 999]])->assertUnprocessable();
    $post(['excluded' => ['x']])->assertUnprocessable();
    $post(['categories' => [99999]])->assertUnprocessable();
    $post(['starts_on' => '2026-12-10', 'ends_on' => '2026-12-01'])->assertUnprocessable();
    $post(['starts_on' => '10/12/2026'])->assertUnprocessable();
    expect(DB::table('product_labels')->count())->toBe(0);

    $post(['picks' => [$p->id], 'bg' => '#c13e63'])->assertCreated();
    expect(DB::table('product_labels')->value('bg'))->toBe('#C13E63');
});

it('serves the picker one compact index with an ETag, revalidated with 304, rebuilt when a product changes', function () {
    /*
     * THE DEFECT: the console downloading the whole catalogue on every open,
     * or showing a renamed product under its old name.
     * MUTATION: drop the If-None-Match test, or `max(updated_at) from
     * products` from the fingerprint. RED.
     */
    ProductLabelsAdminRoutes::wire($this->app);
    $owner = lblAdmin();
    $cat = Category::create(['name' => 'Serums', 'slug' => 'lbl-serums']);
    $p = lblProduct('Snail Mucin Essence', ['sku' => 'SNL-1', 'total_sales' => 77]);
    DB::table('category_product')->insert(['product_id' => $p->id, 'category_id' => $cat->id]);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'name', 'value' => 'إيسنس الحلزون', 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->actingAs($owner, 'admin')->get('/admin-api/labels/index')->assertOk();
    $etag = $r->headers->get('ETag');
    $j = json_decode($r->getContent(), true);
    $row = collect($j['p'])->firstWhere(0, $p->id);
    expect($row[1])->toBe('Snail Mucin Essence')->and($row[3])->toBe('SNL-1')->and($row[4])->toBe('إيسنس الحلزون')->and($row[5])->toBe([$cat->id])
        ->and($j['g'])->not->toBeEmpty()->and($j['t'])->toHaveKey('سيروم')
        ->and($r->getContent())->not->toContain('total_sales')->not->toContain('wc_id');

    $this->actingAs($owner, 'admin')->get('/admin-api/labels/index', ['If-None-Match' => $etag])->assertStatus(304);

    $this->travel(2)->seconds();
    $p->update(['name' => 'Snail Mucin Essence 100ml']);
    $r2 = $this->actingAs($owner, 'admin')->get('/admin-api/labels/index', ['If-None-Match' => $etag])->assertOk();
    expect($r2->headers->get('ETag'))->not->toBe($etag)->and($r2->getContent())->toContain('Snail Mucin Essence 100ml');
});

it('never puts a label rule on /api, and keeps the build flag off the public settings', function () {
    /*
     * MUTATION: add custom_labels_on to SettingController::PUBLIC_KEYS, or a
     * label relation to Product::toApi(). RED.
     */
    $p = lblProduct('Public product');
    lblLabel(['name' => 'ZZ-SECRET-NAME', 'text' => 'ZZ-SECRET-TEXT'], [$p->id]);

    foreach (['/api/products', '/api/products/'.$p->slug, '/api/settings'] as $url) {
        $body = $this->getJson($url)->getContent();
        expect($body)->not->toContain('ZZ-SECRET')->not->toContain('product_label')->not->toContain(CustomLabels::FLAG);
    }
});

it('keeps the console preview on the shop stylesheet', function () {
    /*
     * THE DEFECT: the editor's live preview drifting from what the shop draws.
     * MUTATION: change one rule in CustomLabels::CSS only. RED.
     */
    $src = file_get_contents(resource_path('views/admin/partials/product-labels-screen.blade.php'));
    expect($src)->toContain("var SHOPCSS = '".CustomLabels::CSS."';")
        ->and(CustomLabels::CSS)->not->toContain('var(')->not->toContain('color-mix')->not->toContain('round(');
});

it('is mounted at most once and the screen is included at most once', function () {
    /*
     * Pins the finished state, not the unwired one (CLAUDE.md): zero while the
     * lane waits for the integrator, one after; two registers the sidebar row
     * twice and wraps window.go around its own wrapper.
     */
    $web = file_get_contents(base_path('routes/web.php'));
    $app = file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($web, "require __DIR__.'/product-labels-admin.php';"))->toBeLessThanOrEqual(1)
        ->and(substr_count($app, "@include('admin.partials.product-labels-screen')"))->toBeLessThanOrEqual(1);
});
