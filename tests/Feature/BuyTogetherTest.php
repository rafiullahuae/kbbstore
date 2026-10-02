<?php

declare(strict_types=1);

/*
 * "Buy these together" — the section, the choosing, the admin, the one-request
 * add.                                                              (Lane RB)
 *
 * The owner, 2 October:
 *
 *   "I want one new section called Buy these together, i need the same design
 *    section which we have on the cart page "Recommended for you" same
 *    carousel, same size. the only difference will be: on cart page there is
 *    + icon to add to cart. here on product page, there will be empty circle
 *    at the corner of product, and by default that circle will be checked
 *    (filled green) with check (yes icon) white. and user can un-check. and
 *    whatever products are checked and user click on Buy 4 items together,
 *    number 4 will count as per the user selection. if user selects 3, then 3
 *    will come on the button. and all those will add to the cart. The criteria
 *    of the products selection will be, first product will be the same as on
 *    the product page. second, if the frst product is from sunscreen, then
 *    other 3 will be from moisturizer, toners, cleansing oils, face masks. one
 *    from each random, or best seller or best visits. give option to choose
 *    the criteria at the backend."
 *
 * The test database carries the demo catalogue: Cleansers, Toners, Serums,
 * Moisturisers, Sunscreens and Masks, all at depth 0, and 24 products. Every
 * product made here has outsold all of them, so the choices below are this
 * file's own products, not the demo ones.
 *
 * MUTATIONS, each made against this file, RUN, and reverted:
 *
 *   M1  BuyTogether::pick() — `$takeFrom($ids)` per slot replaced with a loop
 *       over the FALLBACK pool only → "one from each complementary category"
 *       red: the section shows the shop's best sellers instead of a
 *       moisturiser, a toner, a cleansing oil and a mask.
 *   M2  BuyTogether::part() — remove `->where('products.stock_status',
 *       'instock')` → "never offers a hidden, draft, sold-out or variable
 *       product" red: the sold-out best seller comes back.
 *   M3  BuyTogether::part() — remove the `type != variable` where() → same
 *       case red: the variable moisturiser is offered.
 *   M4  BuyTogetherPairs::KINDS — delete the 'cleansing_oil' row, so the
 *       'cleanser' row is the first to match → "names his own shelves" red:
 *       "Cleansing Oils" reads as a cleanser.
 *   M5  BuyTogether::hydrate() — remove `->visible()` → "drops a product
 *       hidden after the pools were cached" red.
 *   M6  CartController::addTogether() — remove the requiresVariant() check →
 *       "re-checks every line" red: the variable product is added at AED 0
 *       (CartService throws VariantRequired, so it lands as "could not be
 *       added" with the wrong sentence — the assertion on the sentence is red).
 *   M7  CartController::addTogether() — `visible()` removed from the product
 *       read → the hidden product is added; red.
 *   M8  routes/buy-together.php — remove `->middleware('throttle:20,1')` →
 *       "throttles" red: the twenty-first request is 200.
 *   M9  ProductPageApiController::save() — write the laptop switch before
 *       answering a refused pair list with its 422 → "refuses an unknown
 *       category ... and writes nothing" red: the Sections row moved.
 *   M10 fbt.js btLabel() — `n === 1` branch removed → the JS case red: one
 *       tick reads "Buy 1 items together".
 *   M11 ProductMobileSections::layout() — `togetherDefault()` replaced with
 *       the schema's `false` → "follows the section's own switch on phones"
 *       red: the phone row stays off with the section on.
 *   M12 2027_07_16_000600_buy_together_on — drop the "already stored" check
 *       (and replace the row) → the migration case red: a stored "0" becomes
 *       "1".
 *
 * Every one was run by storage/rb-logs/mutate.py on this branch (not
 * committed — it is a scratch harness) and every one went red; each file was
 * restored byte for byte after its run.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BuyTogether;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherSettings;
use App\Services\CartService;
use App\Services\ProductMobileSections;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ProductViews;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\BuyTogetherRoutes;

function btAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Together Owner',
        'email' => 'bt-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function btFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
}

/** @param array<string, mixed> $settings */
function btOn(array $settings = []): void
{
    app(BuyTogetherSettings::class)->save(array_merge(['on' => true], $settings));
    btFlush();
}

function btCat(string $name, array $extra = []): Category
{
    $existing = Category::query()->where('name', $name)->first();

    return $existing ?? Category::create(array_merge(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name], $extra));
}

function btProduct(string $name, array $categories, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'total_sales' => $sales,
    ], $extra));

    if ($categories !== []) {
        $p->categories()->sync(array_map(fn (Category $c) => $c->id, $categories));
    }

    return $p;
}

/**
 * A sunscreen, and a best seller and a runner-up on each shelf his sentence
 * names: Moisturisers, Toners, Cleansing Oils (made here — the demo has none)
 * and Masks. Plus a few the section must never offer.
 *
 * @return array<string, mixed>
 */
function btShop(): array
{
    $sun = btCat('Sunscreens');
    $moist = btCat('Moisturisers');
    $toner = btCat('Toners');
    $oil = btCat('Cleansing Oils', ['position' => 9]);
    $mask = btCat('Masks');
    $serum = btCat('Serums');

    return [
        'self' => btProduct('Relief Sun SPF50', [$sun], 10000),
        'm1' => btProduct('Moist Best', [$moist], 900000),
        'm2' => btProduct('Moist Next', [$moist], 800000),
        't1' => btProduct('Toner Best', [$toner], 700000),
        't2' => btProduct('Toner Next', [$toner], 600000),
        'o1' => btProduct('Oil Best', [$oil], 500000),
        'o2' => btProduct('Oil Next', [$oil], 400000),
        'k1' => btProduct('Mask Best', [$mask], 300000),
        'k2' => btProduct('Mask Next', [$mask], 200000),
        's1' => btProduct('Serum Best', [$serum], 100000),
        // Never offered: each one outsells everything above on its shelf, so
        // a missing filter puts it FIRST rather than somewhere unseen.
        'oos' => btProduct('Moist Sold Out', [$moist], 99000000, ['stock_status' => 'outofstock']),
        'hidden' => btProduct('Toner Hidden', [$toner], 99000000, ['is_visible' => false]),
        'draft' => btProduct('Oil Draft', [$oil], 99000000, ['status' => 'draft']),
        'variable' => btProduct('Moist Variable', [$moist], 98000000, ['type' => 'variable', 'price' => null]),
        'cats' => compact('sun', 'moist', 'toner', 'oil', 'mask', 'serum'),
    ];
}

/** The names the section draws on a product's page, in order. */
function btNames(Product $product, array $settings = []): array
{
    btOn($settings);
    $fresh = Product::with(['categories:id,name,slug,path', 'variants', 'brand'])->find($product->id);

    return app(BuyTogether::class)->forProduct($fresh)['products']->pluck('name')->all();
}

function btPage(Product $product): string
{
    return (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
}

/* ═══════════════════════════ the choosing ═══════════════════════════════ */

it('puts the product on the page first, then one from each complementary category in his order', function () {
    /*
     * His sentence, as the section draws it: a sunscreen, then a moisturiser,
     * a toner, a cleansing oil and a face mask — the best seller of each.
     * Four products by default ("Buy 4 items together", "the other 3"), five
     * with the slider one notch up. What a defect looks like on the shop: the
     * old block showed the product's own category mates — three more
     * sunscreens under a sunscreen.
     */
    $s = btShop();

    expect(btNames($s['self']))->toBe(['Relief Sun SPF50', 'Moist Best', 'Toner Best', 'Oil Best']);
    expect(btNames($s['self'], ['count' => 5]))->toBe(['Relief Sun SPF50', 'Moist Best', 'Toner Best', 'Oil Best', 'Mask Best']);
    // A second round over the same shelves before anything else.
    expect(btNames($s['self'], ['count' => 6]))->toBe(['Relief Sun SPF50', 'Moist Best', 'Toner Best', 'Oil Best', 'Mask Best', 'Moist Next']);
});

it('holds the count to 3–6, whatever is posted', function () {
    $s = btShop();

    expect(btNames($s['self'], ['count' => 1]))->toHaveCount(3);
    expect(btNames($s['self'], ['count' => 99]))->toHaveCount(6);
    expect(app(BuyTogetherSettings::class)->all()['count'])->toBe(6);
});

it('never offers a hidden, draft, sold-out or variable product, nor the product itself twice', function () {
    $s = btShop();
    $names = btNames($s['self'], ['count' => 6]);

    expect($names)->not->toContain('Moist Sold Out')
        ->not->toContain('Toner Hidden')
        ->not->toContain('Oil Draft')
        ->not->toContain('Moist Variable');
    expect(array_count_values($names))->each->toBe(1);

    // "Hide sold-out products" off: the sold-out best seller joins — drawn
    // greyed with no tick, which the rendering case below checks.
    expect(btNames($s['self'], ['hide_oos' => false]))->toContain('Moist Sold Out')
        ->not->toContain('Toner Hidden');
});

it('chooses by the rule he picks: newest, top rated, most viewed, random', function () {
    $s = btShop();
    $moist = $s['cats']['moist'];

    $new = btProduct('Moist Newest', [$moist], 1);
    $new->forceFill(['created_at' => now()->addDay()])->save();
    $rated = btProduct('Moist Rated', [$moist], 2, ['rating' => 4.9, 'review_count' => 40]);
    $viewed = btProduct('Moist Viewed', [$moist], 3);

    foreach (range(1, 7) as $_) {
        ProductViews::record((int) $viewed->id);
    }

    expect(btNames($s['self'], ['rule' => 'newest'])[1])->toBe('Moist Newest');
    expect(btNames($s['self'], ['rule' => 'rated'])[1])->toBe('Moist Rated');
    expect(btNames($s['self'], ['rule' => 'viewed'])[1])->toBe('Moist Viewed');
    expect(btNames($s['self'], ['rule' => 'best'])[1])->toBe('Moist Best');

    // Random: always a moisturiser in the moisturiser slot, and not always the
    // same one. The pools are cached; the PICK is made on every call.
    btOn(['rule' => 'random']);
    $fresh = Product::with('categories:id,name,slug,path')->find($s['self']->id);
    $seen = [];

    for ($i = 0; $i < 40; $i++) {
        $seen[] = app(BuyTogether::class)->forProduct($fresh)['products'][1]->name;
    }

    $moistIds = Product::query()->where('category_id', $moist->id)
        ->orWhereHas('categories', fn ($q) => $q->where('categories.id', $moist->id))->pluck('name')->all();
    expect(array_diff(array_unique($seen), $moistIds))->toBe([])
        ->and(count(array_unique($seen)))->toBeGreaterThan(1);

    // A select stores one of its own options or the default.
    app(BuyTogetherSettings::class)->save(['rule' => 'cheapest']);
    btFlush();
    expect(app(BuyTogetherSettings::class)->all()['rule'])->toBe('best');
});

it('prefers the same brand inside each shelf when asked', function () {
    $s = btShop();
    $brand = Brand::create(['slug' => 'bt-brand-'.Str::random(4), 'name' => 'Same Brand']);
    $s['self']->forceFill(['brand_id' => $brand->id])->save();
    btProduct('Moist Same Brand', [$s['cats']['moist']], 5, ['brand_id' => $brand->id]);

    expect(btNames($s['self'])[1])->toBe('Moist Best');
    expect(btNames($s['self'], ['same_brand' => true])[1])->toBe('Moist Same Brand');
});

it('fills an empty shelf, and a product with no pairing, from the shop\'s best sellers — never twice', function () {
    $s = btShop();
    // Empty the cleansing-oil shelf.
    Product::query()->whereIn('id', [$s['o1']->id, $s['o2']->id])->update(['is_visible' => false]);

    $names = btNames($s['self'], ['count' => 5]);
    expect($names)->toBe(['Relief Sun SPF50', 'Moist Best', 'Toner Best', 'Mask Best', 'Moist Next']);

    // A product on a shelf that pairs with nothing: the best sellers, in order.
    $gift = btProduct('Gift Card', [btCat('Gift Cards')], 1);
    expect(btNames($gift, ['count' => 4]))->toBe(['Gift Card', 'Moist Best', 'Moist Next', 'Toner Best']);
});

it('draws no section for a product that cannot be bought, and none while it is off', function () {
    $s = btShop();
    $sold = btProduct('Sun Sold Out', [$s['cats']['sun']], 1, ['stock_status' => 'outofstock']);

    expect(btNames($sold))->toBe([]);

    // Off (the schema's default): nothing, and nothing asked of the database
    // beyond the settings snapshot the page has already read.
    app(BuyTogetherSettings::class)->save(['on' => false]);
    btFlush();
    $fresh = Product::with('categories:id,name,slug,path')->find($s['self']->id);
    app(BuyTogetherSettings::class)->all();
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    expect(app(BuyTogether::class)->forProduct($fresh)['products'])->toBeEmpty();
    expect($n)->toBe(0);
});

it('drops a product hidden after the pools were cached, on the very next view', function () {
    $s = btShop();
    expect(btNames($s['self'])[1])->toBe('Moist Best');

    // Behind the model's back, so Product::booted() cannot forget the cache.
    DB::table('products')->where('id', $s['m1']->id)->update(['is_visible' => false]);

    expect(btNames($s['self'])[1])->toBe('Moist Next');
});

/* ═══════════════════════════ the category pairs ═════════════════════════ */

it('names his own shelves by kind, in the order that keeps "Cleansing Oils" out of the cleansers', function () {
    $his = [
        'Skincare' => 'skincare', 'Sunscreens' => 'sunscreen', 'Moisturizers' => 'moisturizer',
        'Toners' => 'toner', 'Lip Care' => 'lip', 'Hair Care' => 'hair', 'Skincare Sets' => 'set',
        'Beauty Devices' => 'device', 'Face Masks' => 'mask', 'Cleansing Oils' => 'cleansing_oil',
        'Face Washes' => 'cleanser', 'Exfoliators' => 'exfoliator', 'Face Serums' => 'serum',
        'Eye Care' => 'eye', 'Sun Cream' => 'sunscreen', 'Eye Cream' => 'eye', 'Cleansing Balm' => 'cleansing_oil',
        'Gift Cards' => null,
    ];

    foreach ($his as $name => $kind) {
        expect(BuyTogetherPairs::kindOf($name, Str::slug($name)))->toBe($kind, $name);
    }
});

it('resolves the default pairs against the shop\'s real categories, and a product to its most specific shelf', function () {
    // His shelves, and nothing else: the demo six are removed for this case.
    DB::table('category_product')->delete();
    DB::table('products')->update(['category_id' => null]);
    DB::table('categories')->delete();

    $skin = btCat('Skincare');
    $ids = [];

    foreach (['Sunscreens', 'Moisturizers', 'Toners', 'Lip Care', 'Hair Care', 'Skincare Sets', 'Beauty Devices',
        'Face Masks', 'Cleansing Oils', 'Face Washes', 'Exfoliators', 'Face Serums', 'Eye Care'] as $i => $name) {
        $ids[$name] = btCat($name, ['parent_id' => $skin->id, 'depth' => 1, 'position' => $i])->id;
    }

    btFlush();
    $pairs = app(BuyTogetherPairs::class);

    expect($pairs->defaultFor($ids['Sunscreens']))
        ->toBe([$ids['Moisturizers'], $ids['Toners'], $ids['Cleansing Oils'], $ids['Face Masks']]);
    expect($pairs->defaultFor($ids['Face Washes']))
        ->toBe([$ids['Toners'], $ids['Face Serums'], $ids['Moisturizers'], $ids['Sunscreens']]);
    expect($pairs->defaultFor($ids['Hair Care'])[0])->toBe($ids['Hair Care']);

    // A sunscreen filed under Skincare AND Sunscreens pairs as a sunscreen.
    $p = btProduct('Filed Twice', [$skin, Category::find($ids['Sunscreens'])], 1);
    expect($pairs->anchorFor($p->load('categories')))->toBe($ids['Sunscreens']);
});

it('follows his override for a category, and goes back to the default when he clears it', function () {
    $s = btShop();
    $sun = $s['cats']['sun']->id;

    // His order, not the default's: serums first, then toners.
    app(BuyTogetherPairs::class)->save([$sun => [$s['cats']['serum']->id, $s['cats']['toner']->id]]);
    expect(btNames($s['self'], ['count' => 3]))->toBe(['Relief Sun SPF50', 'Serum Best', 'Toner Best']);

    // An empty list is a real answer: no pairing, the best sellers.
    app(BuyTogetherPairs::class)->save([$sun => []]);
    expect(btNames($s['self'], ['count' => 4]))->toBe(['Relief Sun SPF50', 'Moist Best', 'Moist Next', 'Toner Best']);

    app(BuyTogetherPairs::class)->save([]);
    expect(btNames($s['self'])[1])->toBe('Moist Best');
});

/* ═══════════════════════════ the page ═══════════════════════════════════ */

it('draws the cart rail\'s cards with a green tick on each, a plus between them, and "Buy 4 items together"', function () {
    $s = btShop();
    btOn();
    $html = btPage($s['self']);

    expect(substr_count($html, '<section class="kbb-fbt bt'))->toBe(1);
    expect(substr_count($html, 'class="bt-card'))->toBe(4);
    // Every tick starts on — a real, checked checkbox per card.
    expect(substr_count($html, 'class="bt-cb"'))->toBe(4)
        ->and(preg_match_all('/class="bt-cb" value="\d+" checked/', $html))->toBe(4);
    // The "+" between cards: one fewer than the cards.
    expect(substr_count($html, 'class="bt-plus"'))->toBe(3);
    // The first card is the product on the page.
    expect(preg_match('/class="bt-card is-main"[^>]*>\s*<label class="im"[^>]*>.*?value="(\d+)"/s', $html, $m))->toBe(1)
        ->and((int) $m[1])->toBe((int) $s['self']->id);
    // Server-rendered count, and the JS's three templates beside it.
    expect($html)->toContain('<span class="bt-label" aria-live="polite">Buy 4 items together</span>')
        ->toContain('data-many="Buy :count items together"')
        ->toContain('data-one="Add 1 item to cart"')
        ->toContain('data-none="Tick at least one product"')
        ->toContain('>Buy these together</h2>');
    // The cart rail's sizing, from the cart page's own settings.
    expect($html)->toContain('--cpg-per:4.50')->toContain('--bt-d-card:calc(');
});

it('makes the picture the same control as the circle, labelled by the product name, and the title the link', function () {
    /*
     * The owner, later the same day: "for buy together section, the product
     * image will also work same as the check circle. upon click it will
     * un-check and upon click again on image, it will be checked. and product
     * title will go to the product page. this only for this section."
     *
     * ONE CONTROL, NOT TWO. Each picture is a <label> wrapping that product's
     * one checkbox, and the checkbox is laid over the whole picture, so a tap
     * anywhere on the photo — or on the circle, which is inside the same
     * label — is a tap on the SAME input. The script has no click handler of
     * its own on the picture and keeps no second state: it reads `checked`
     * on `change`, so the circle, the count and what is posted cannot
     * disagree. A screen reader meets one checkbox per product, named by the
     * product; the title is an ordinary link, so Enter on it follows it.
     *
     * What a defect looks like on the shop: the picture opening the product
     * page (the cart rail's card has its picture outside the link, but a
     * careless "same card" would wrap it), or a second toggle that unticks the
     * circle while the button still counts it.
     *
     * MUTATIONS, RUN: move the <input> out of the <label class="im"> → red
     * ("one checkbox inside each picture"); wrap the picture in the title's
     * <a> → red ("the picture is not a link"); add `cb.checked = !cb.checked`
     * on a picture click in fbt.js → red ("no second state").
     */
    $s = btShop();
    btOn();
    $html = btPage($s['self']);

    preg_match_all('#<div class="bt-card[^"]*"[^>]*>(.*?)</a>\s*</div>#s', $html, $cards);
    expect($cards[1])->toHaveCount(4);

    foreach ([$s['self'], $s['m1'], $s['t1'], $s['o1']] as $i => $p) {
        $card = $cards[1][$i];

        // One checkbox, INSIDE the picture's label, named by the product.
        expect(preg_match('#<label class="im"[^>]*>(.*?)</label>#s', $card, $label))->toBe(1);
        expect(substr_count($card, 'type="checkbox"'))->toBe(1)
            ->and(substr_count($label[1], '<input type="checkbox" class="bt-cb" value="'.$p->id.'"'))->toBe(1, 'one checkbox inside each picture')
            ->and($label[1])->toContain('aria-label="'.e($p->name).'"');

        // The picture is not a link, and nothing in it is.
        expect($label[1])->not->toContain('<a ')
            ->and($card)->not->toMatch('#<a[^>]*>\s*<label class="im"#');

        // The title is the link, to this product's page.
        expect($card)->toMatch('#<a class="lk" href="'.preg_quote(e($p->url()), '#').'">\s*<span class="nm">'.preg_quote(e($p->name), '#').'</span>#');
    }

    // The checkbox covers the picture; the circle and the "+" let the tap through.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-product.css')));
    expect($css)->toMatch('/\.bt-cb\{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer;z-index:2\}/')
        ->and($css)->toMatch('/\.bt-tick\{[^}]*pointer-events:none/')
        ->and($css)->toMatch('/\.bt-plus\{[^}]*pointer-events:none/');

    // No second state: the script never sets a checkbox, it reads them on change.
    $js = (string) file_get_contents(resource_path('js/kbb/fbt.js'));
    expect($js)->not->toMatch('/\.checked\s*=[^=]/')
        ->and($js)->toContain("if (event.target.classList?.contains('bt-cb')) refresh();");
});

it('leaves the cart page\'s Recommended rail and the shared product card exactly as they were', function () {
    /*
     * "this only for this section. don't touch anything else." The section
     * COPIES the cart rail's card; it does not share a template or a class
     * with it, so neither can move when this one does.
     *
     * Two halves. StorefrontEnglishUnchangedTest renders every storefront
     * page, the basket's /cart included, and holds it byte-identical. This
     * adds the direct check: the rail on a real basket still draws its "+"
     * add button on every card and carries nothing of this section's, and the
     * four files that draw the rail and the shared card are unchanged on this
     * branch against the integration branch it was cut from.
     *
     * MUTATION, RUN: add `bt-card` to the rail's `<div class="cpg-card">` in
     * store/cart-inner.blade.php → red, on the rendered rail and on the file.
     */
    $s = btShop();
    btOn();

    $settings = app(SettingsService::class);
    $settings->set('cartpage_layout', 'squeeze');
    $settings->set('cartpage_rec_ids', implode(',', [$s['m1']->id, $s['t1']->id, $s['o1']->id]));
    btFlush();

    $cart = \App\Models\Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $s['k1']->id, 'quantity' => 1, 'unit_price' => 5000]);

    $html = (string) test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')->assertOk()->getContent();

    expect(preg_match('#<section class="cpg-rec">.*?</section>#s', $html, $rail))->toBe(1);
    expect(substr_count($rail[0], '<div class="cpg-card">'))->toBe(3)
        ->and(substr_count($rail[0], 'class="kc-badd" type="button" data-add="'))->toBe(3)
        ->and($rail[0])->not->toMatch('/bt-(card|cb|tick|plus)/');

    $base = trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' merge-base HEAD claude/kind-mayer-rpqesv 2>/dev/null'));

    if ($base === '') {
        test()->markTestSkipped('no integration branch in this checkout; the rendered rail above was still checked.');
    }

    foreach (['resources/views/store/cart-inner.blade.php', 'resources/views/store/cart-squeeze.blade.php',
        'resources/views/components/product-card.blade.php', 'resources/css/kbb/kbb-cart.css'] as $file) {
        $then = (string) shell_exec('git -C '.escapeshellarg(base_path()).' show '.escapeshellarg($base.':'.$file).' 2>/dev/null');
        expect($then)->not->toBe('', "{$file} is not in git at {$base}")
            ->and((string) file_get_contents(base_path($file)))->toBe($then, "{$file} changed on this branch");
    }
});

it('counts only what can be bought: a sold-out match is drawn without a tick', function () {
    $s = btShop();
    btOn(['hide_oos' => false]);
    $html = btPage($s['self']);

    expect($html)->toContain('<span class="bt-label" aria-live="polite">Buy 3 items together</span>');
    expect(preg_match_all('/class="bt-cb" value="\d+" disabled/', $html))->toBe(1);
    expect($html)->toContain('class="bt-card is-oos is-off"');
});

it('escapes the heading he types', function () {
    $s = btShop();
    btOn(['title' => '<img src=x onerror=alert(1)>Pairs']);
    $html = btPage($s['self']);

    expect($html)->not->toContain('<img src=x onerror')->toContain('Pairs</h2>');
});

it('counts the button the way he described, in the shipped script', function () {
    /*
     * "if user selects 3, then 3 will come on the button." The words are
     * chosen in the browser, so the function that chooses them is run here,
     * out of the built source, in node — the same text the shop ships.
     */
    $src = (string) file_get_contents(resource_path('js/kbb/fbt.js'));

    expect(preg_match('/export function btLabel\(n, tpl\) \{.*?\n\}/s', $src, $label))->toBe(1);
    expect(preg_match('/export function formatMinor\(minor, exp, dec\) \{.*?\n\}/s', $src, $money))->toBe(1);
    // The button can never be pressed with nothing ticked, or while working.
    expect($src)->toContain('button.disabled = busy || n === 0;');
    // And nothing in it measures the layout.
    expect($src)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|clientWidth|scrollWidth|ResizeObserver/');

    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        test()->markTestSkipped('node is not installed here; the source pins above still ran.');
    }

    $js = str_replace('export function', 'function', $label[0]."\n".$money[0])."\n"
        .'const t = {many: "Buy :count items together", one: "Add 1 item to cart", none: "Tick at least one product"};'
        .'console.log(JSON.stringify([btLabel(4, t), btLabel(3, t), btLabel(1, t), btLabel(0, t), formatMinor(21500, 2, 2), formatMinor(123456789, 2, 0), formatMinor(1999, 2, 2)]));';
    $file = storage_path('framework/testing/bt-label-'.getmypid().'.js');
    file_put_contents($file, $js);
    $out = json_decode((string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file)), true);
    @unlink($file);

    expect($out)->toBe([
        'Buy 4 items together', 'Buy 3 items together', 'Add 1 item to cart', 'Tick at least one product',
        '215.00', '1,234,568', '19.99',
    ]);
    // The server's own figure for the same three, so the two cannot drift.
    expect([\App\Support\Money::amount(21500, 2), \App\Support\Money::amount(123456789, 0), \App\Support\Money::amount(1999, 2)])
        ->toBe(['215.00', '1,234,568', '19.99']);
});

/* ═══════════════════════════ the one-request add ════════════════════════ */

it('adds every ticked product in one request, priced by the server', function () {
    BuyTogetherRoutes::wire(app());
    $s = btShop();

    $res = test()->postJson('/api/cart/add-together', ['items' => [
        ['product_id' => $s['self']->id], ['product_id' => $s['m1']->id], ['product_id' => $s['t1']->id],
        // A product twice is one line.
        ['product_id' => $s['t1']->id],
    ]])->assertOk();

    expect($res->json('ok'))->toBeTrue()
        ->and($res->json('added_count'))->toBe(3)
        ->and($res->json('count'))->toBe(3)
        ->and($res->json('failed'))->toBe([])
        ->and($res->json('toast'))->toBe('3 items added to your bag')
        ->and($res->json('drawer'))->toContain('Moist Best');
});

it('re-checks every line: hidden, sold out, an option missing or borrowed — named, and the rest still go in', function () {
    BuyTogetherRoutes::wire(app());
    $s = btShop();
    $var = btProduct('Var Toner', [$s['cats']['toner']], 1, ['type' => 'variable', 'price' => null]);
    $v = ProductVariant::create(['product_id' => $var->id, 'price' => 3000, 'stock_status' => 'instock', 'position' => 0]);
    $other = btProduct('Other Var', [], 1, ['type' => 'variable', 'price' => null]);
    $foreign = ProductVariant::create(['product_id' => $other->id, 'price' => 1, 'stock_status' => 'instock', 'position' => 0]);

    $res = test()->postJson('/api/cart/add-together', ['items' => [
        ['product_id' => $s['m1']->id],
        ['product_id' => $s['hidden']->id],
        ['product_id' => $s['oos']->id],
        ['product_id' => $s['variable']->id],
        ['product_id' => $var->id, 'variant_id' => $foreign->id],
    ]])->assertOk();

    expect($res->json('added_count'))->toBe(1)
        ->and($res->json('failed'))->toBe([(int) $s['hidden']->id, (int) $s['oos']->id, (int) $s['variable']->id, (int) $var->id])
        ->and($res->json('toast'))->toContain('Added to bag')
        ->toContain('One of these is no longer available.')
        ->toContain('Moist Sold Out is sold out.')
        ->toContain('Choose an option for Moist Variable first.')
        ->toContain('That option of Var Toner is no longer available.');

    // Its own option, though, is bought at that option's price.
    test()->postJson('/api/cart/add-together', ['items' => [['product_id' => $var->id, 'variant_id' => $v->id]]])
        ->assertOk()->assertJson(['added_count' => 1]);
    $line = DB::table('cart_items')->where('product_id', $var->id)->first();
    expect((int) $line->product_variant_id)->toBe((int) $v->id)->and((int) $line->unit_price)->toBe(3000);

    // Nothing buyable at all: a 422 with the sentence, nothing added.
    test()->postJson('/api/cart/add-together', ['items' => [['product_id' => $s['draft']->id]]])
        ->assertStatus(422)->assertJson(['ok' => false, 'added_count' => 0]);
    // And a shape the button cannot send is refused before anything is read.
    test()->postJson('/api/cart/add-together', ['items' => array_fill(0, 7, ['product_id' => $s['m1']->id])])->assertStatus(422);
    test()->postJson('/api/cart/add-together', ['items' => [['product_id' => 'x']]])->assertStatus(422);
});

it('is a storefront POST: the web stack (session, cart cookie, CSRF), and throttled', function () {
    BuyTogetherRoutes::wire(app());
    $s = btShop();

    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/cart/add-together');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('web')->toContain('throttle:20,1');

    // CSRF for real: the framework skips the check under the test runner, so
    // the check is put back for this one request.
    app()->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    test()->postJson('/api/cart/add-together', ['items' => [['product_id' => $s['m1']->id]]])->assertStatus(419);
    app()->forgetInstance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    // Twenty a minute, then a 429.
    $codes = [];

    for ($i = 0; $i < 21; $i++) {
        $codes[] = test()->postJson('/api/cart/add-together', ['items' => [['product_id' => $s['m1']->id]]])->getStatusCode();
    }

    expect(array_slice($codes, 0, 20))->each->toBe(200)
        ->and($codes[20])->toBe(429);
});

it('counts a view from the beacon, refuses an invisible product, and costs the page nothing', function () {
    BuyTogetherRoutes::wire(app());
    $s = btShop();

    test()->post('/api/product-view', ['id' => $s['m1']->id])->assertNoContent();
    test()->post('/api/product-view', ['id' => $s['m1']->id])->assertNoContent();
    test()->post('/api/product-view', ['id' => $s['hidden']->id])->assertNotFound();

    expect((int) DB::table('product_view_days')->where('product_id', $s['m1']->id)->value('views'))->toBe(2)
        ->and(DB::table('product_view_days')->where('product_id', $s['hidden']->id)->exists())->toBeFalse();

    // The page itself writes nothing to it.
    btOn();
    btPage($s['self']);
    expect(DB::table('product_view_days')->where('product_id', $s['self']->id)->exists())->toBeFalse();
});

/* ═══════════════════════════ cost ═══════════════════════════════════════ */

it('costs two queries warm and three cold, flat in the size of the catalogue', function () {
    /*
     * The product page budget (StorefrontQueryBudgetTest, 13) is measured with
     * the section OFF, which is how the suite ships; this measures the section
     * itself. Cold: the category list, the one union and the brands. Warm:
     * the pooled cards and their brands. Then sixty more products on the same
     * shelves, which must change neither number.
     */
    $s = btShop();
    btOn();
    $fresh = fn () => Product::with('categories:id,name,slug,path')->find($s['self']->id);
    $count = function () use ($fresh): int {
        $p = $fresh();
        // What the product page has already read by the time it reaches the
        // section: the settings snapshot and the interface strings.
        app(BuyTogetherSettings::class)->all();
        __('store.buy_together.heading');
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        app(BuyTogether::class)->forProduct($p);

        return $n;
    };

    Cache::flush();
    $cold = $count();
    $warm = $count();

    foreach (range(1, 15) as $i) {
        foreach (['moist', 'toner', 'oil', 'mask'] as $shelf) {
            btProduct("Filler {$shelf} {$i}", [$s['cats'][$shelf]], $i);
        }
    }

    Cache::flush();
    $coldBig = $count();
    $warmBig = $count();

    expect([$cold, $warm])->toBe([3, 2])
        ->and([$coldBig, $warmBig])->toBe([$cold, $warm]);
});

/* ═══════════════════════════ the admin ══════════════════════════════════ */

it('serves the tab from the product page endpoint, with both device switches read from where they live', function () {
    test()->actingAs(btAdmin(), 'admin');
    btShop();

    $body = test()->getJson('/admin-api/product-page')->assertOk()->json('together');

    expect(array_column($body['options'][0]['fields'], 'key'))
        ->toBe(['on', 'count', 'rule', 'hide_oos', 'same_brand', 'show_total', 'title', 'title_ar']);
    expect($body['phone'])->toBeFalse()->and($body['laptop'])->toBeTrue()->and($body['max_pairs'])->toBe(5);

    $sun = collect($body['pairs'])->firstWhere('name', 'Sunscreens');
    expect($sun['kind'])->toBe('sunscreen')->and($sun['custom'])->toBeNull()->and($sun['default'])->toHaveCount(4);
});

it('saves the options, the two device switches into their own rows, and his pairs', function () {
    test()->actingAs(btAdmin(), 'admin');
    $s = btShop();
    $sun = $s['cats']['sun']->id;

    test()->postJson('/admin-api/product-page', ['together' => [
        'options' => ['on' => true, 'count' => 5, 'rule' => 'viewed', 'title' => 'Complete the look'],
        'phone' => true,
        'laptop' => false,
        'pairs' => [(string) $sun => [$s['cats']['serum']->id]],
    ]])->assertOk()->assertJson(['ok' => true]);
    btFlush();

    $c = app(BuyTogetherSettings::class)->all();
    expect([$c['on'], $c['count'], $c['rule'], $c['title']])->toBe([true, 5, 'viewed', 'Complete the look']);
    // The phone switch IS the Mobile sections row; the laptop switch IS the
    // Sections row's Desktop value — and nothing else in that map moved.
    expect(app(ProductMobileSections::class)->layout()['on']['buytogether'])->toBeTrue();
    expect(app(ProductSections::class)->all()['fbt']['desktop'])->toBeFalse();
    expect(app(ProductSections::class)->all()['reviews']['desktop'])->toBeTrue();
    expect(app(BuyTogetherPairs::class)->overrides())->toBe([$sun => [$s['cats']['serum']->id]]);

    // "Use the default" is null.
    test()->postJson('/admin-api/product-page', ['together' => ['pairs' => [(string) $sun => null]]])->assertOk();
    btFlush();
    expect(app(BuyTogetherPairs::class)->overrides())->toBe([]);
});

it('refuses an unknown category, a sixth pair, a repeat, an unknown option or part — and writes nothing', function () {
    test()->actingAs(btAdmin(), 'admin');
    $s = btShop();
    $sun = (string) $s['cats']['sun']->id;
    $c = $s['cats'];

    $bad = [
        ['pairs' => ['999999' => [$c['moist']->id]]],
        ['pairs' => [$sun => [999999]]],
        ['pairs' => [$sun => [$c['moist']->id, $c['moist']->id]]],
        ['pairs' => [$sun => [$c['moist']->id, $c['toner']->id, $c['oil']->id, $c['mask']->id, $c['serum']->id, $c['sun']->id]]],
        ['options' => ['on' => true, 'colour' => 'red']],
        ['phone' => 'sometimes'],
        ['everything' => true],
    ];

    foreach ($bad as $body) {
        // Each carries a valid switch beside it, which must not be written either.
        test()->postJson('/admin-api/product-page', ['together' => $body + ['laptop' => false]])->assertStatus(422);
    }

    btFlush();
    expect(app(BuyTogetherPairs::class)->overrides())->toBe([])
        ->and(app(BuyTogetherSettings::class)->all()['on'])->toBeFalse()
        ->and(app(ProductSections::class)->all()['fbt']['desktop'])->toBeTrue();
});

it('wires the tab into the console exactly once, and names the old module row\'s new home', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.product-buy-together-screen')"))->toBe(1);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-buy-together-screen.blade.php'));
    // It wraps the Product page screen once, and says so on the wrapper.
    expect(substr_count($screen, 'window.paintProductPage = wrapped;'))->toBe(1)
        ->and($screen)->toContain('wrapped.__btp = true;');

    // Store → Modules no longer draws a second, dead switch for the old block:
    // the row points at the tab where the section is switched.
    $row = \App\Services\ModuleRegistry::REGISTRY['frequently_bought'] ?? null;
    expect($row)->not->toBeNull()
        ->and($row[1])->toBe('Buy these together')
        ->and($row[4])->toBe('Appearance → Product page')
        ->and($row[9])->toBe('elsewhere');
});

it('follows the section\'s own switch on phones until he saves Mobile sections', function () {
    $msec = fn () => new ProductMobileSections(app(SettingsService::class));

    expect($msec()->layout()['on']['buytogether'])->toBeFalse();

    btOn();
    expect($msec()->layout()['on']['buytogether'])->toBeTrue();
    expect($msec()->payload()['defaults']['on']['buytogether'])->toBeTrue();

    // A layout he saved keeps what he saved.
    $m = $msec();
    $m->saveLayout($m->validate(['on' => ['buytogether' => false]]));
    btFlush();
    expect($msec()->layout()['on']['buytogether'])->toBeFalse();
});

/* ═══════════════════════════ the migration ══════════════════════════════ */

it('switches the section on for a real shop only, and never over a stored value', function () {
    $migration = require database_path('migrations/2027_07_16_000600_buy_together_on.php');

    // Under the test runner: nothing.
    $migration->up();
    expect(DB::table('settings')->where('key', 'bt_on')->exists())->toBeFalse();

    $env = app()['env'];

    try {
        app()['env'] = 'production';

        // A shop with categories and no stored switch: ON.
        $migration->up();
        expect(DB::table('settings')->where('key', 'bt_on')->value('value'))->toBe('1');

        // A shop that set it off keeps its off.
        DB::table('settings')->where('key', 'bt_on')->update(['value' => '0']);
        $migration->up();
        expect(DB::table('settings')->where('key', 'bt_on')->value('value'))->toBe('0');
    } finally {
        app()['env'] = $env;
    }
});
