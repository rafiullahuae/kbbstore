<?php

declare(strict_types=1);

/**
 * Free gifts: ONE VARIATION of a product with options as a gift (Lane FGV).
 *
 * The owner, 27 October: "while adding products in the gifts in backend, we
 * can't added variable product. if we choose variable product, then the
 * system should give us option to choose the variation, for example we have
 * variable product in 3 colors, red, pink, black, the pink one we want to add
 * in gifts".
 *
 * Why it could not be added: the picker greyed every product with options out
 * ("Has shades or sizes: pick a simple product"), FreeGiftsController::
 * saveRange() refused one with 422 if it was sent anyway, `distinct` on
 * product_id made two variations of one product impossible, and
 * FreeGifts::linesFor() gave a variable product 0 units. Each case says what
 * the defect looked like and carries a mutation note.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
});

function fgvSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function fgvFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
}

function fgvOn(): void
{
    fgvSet(FreeGifts::KEY, ['mode' => 'live', 'on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
}

function fgvProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish',
        'is_visible' => true, 'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

/**
 * A "Lip Tint" in Red, Pink and Black, each its own photo and shelf.
 *
 * @return array{0: Product, 1: array<string, ProductVariant>}
 */
function fgvTint(array $stock = ['Red' => 5, 'Pink' => 5, 'Black' => 5], string $name = 'Lip Tint'): array
{
    static $axis = null;
    $axis = DB::table('attributes')->where('slug', 'colour')->value('id')
        ?? DB::table('attributes')->insertGetId(['slug' => 'colour', 'name' => 'Colour', 'is_variation_axis' => true, 'position' => 0]);

    $p = fgvProduct($name, ['type' => 'variable', 'manage_stock' => true, 'stock' => 9]);
    $out = [];
    $pos = 0;
    foreach ($stock as $colour => $n) {
        $value = DB::table('attribute_values')->where('attribute_id', $axis)->where('slug', Str::slug($colour))->value('id')
            ?? DB::table('attribute_values')->insertGetId(['attribute_id' => $axis, 'slug' => Str::slug($colour), 'name' => $colour, 'position' => $pos]);
        $v = ProductVariant::create(['product_id' => $p->id, 'sku' => 'LT-' . strtoupper($colour) . '-' . Str::random(4), 'price' => 3000,
            'manage_stock' => true, 'stock' => $n, 'stock_status' => 'instock', 'image' => 'https://img.example.com/tint-' . strtolower($colour) . '.jpg', 'position' => $pos++]);
        DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $v->id, 'attribute_value_id' => $value]);
        $out[$colour] = $v;
    }

    return [$p, $out];
}

/** A range; $gifts = [[Product, qty, ?variant, ?kind, ?role], ...]. */
function fgvRange(int $min, ?int $max, array $gifts, array $o = []): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(array_merge([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100,
        'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));

    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert(array_filter([
            'range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'sort' => $i,
            'variant_id' => isset($g[2]) ? $g[2]->id : null, 'kind' => $g[3] ?? null, 'role' => $g[4] ?? 'primary',
        ], static fn ($v) => $v !== null));
    }

    app(FreeGifts::class)->compile();
    fgvFresh();

    return $id;
}

function fgvCart(array $lines): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    foreach ($lines as [$product, $qty]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (int) $product->price]);
    }

    return $cart->fresh();
}

function fgvTotals(Cart $cart): array
{
    return app(CartService::class)->totals($cart->fresh(), 'AE');
}

/** Queries logged, less the settings map this test's own fgvFresh() made the app read again. */
function fgvQueries(): int
{
    return count(array_filter(DB::getQueryLog(), static fn (array $q) => ! str_contains($q['query'], 'from "settings"')));
}

function fgvAdmin(): AdminUser
{
    return AdminUser::create(['name' => 'FGV', 'email' => 'fgv-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
}

it('lets the owner find a product with options, see its variations and save the pink one (and the black) as gifts', function () {
    /*
     * The defect: the picker greyed the Lip Tint out and the server answered
     * 422 "A product with shades or sizes cannot be a gift; pick a simple
     * product." -- a variable product could not be added at all.
     * MUTATION: put the `$p->type === 'variable'` refusal back in saveRange(). RED.
     * MUTATION: put `'distinct'` back on gifts.*.product_id -- Pink + Black is refused. RED.
     * MUTATION: drop the `variants` key from products() -- no chooser. RED.
     */
    [$tint, $v] = fgvTint();
    $admin = fgvAdmin();

    $found = test()->actingAs($admin, 'admin')->getJson('/admin-api/free-gifts/products?q=Lip')->assertOk()->json('products');
    $row = collect($found)->firstWhere('id', $tint->id);
    expect($row['type'])->toBe('variable')
        ->and(array_column($row['variants'], 'variant_label'))->toBe(['Colour: Red', 'Colour: Pink', 'Colour: Black'])
        ->and($row['variants'][1]['name'])->toBe('Lip Tint – Pink')
        ->and($row['variants'][1]['variant_id'])->toBe($v['Pink']->id)
        ->and($row['variants'][1]['in_stock'])->toBeTrue()
        ->and($row['variants'][1]['stock'])->toBe(5)
        ->and($row['variants'][1]['image'])->toBe('https://img.example.com/tint-pink.jpg');

    $res = test()->actingAs($admin, 'admin')->postJson('/admin-api/free-gifts/ranges', [
        'min' => 100, 'max' => 299, 'label_en' => 'Tint', 'label_ar' => 'تنت', 'enabled' => true,
        'gifts' => [
            ['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1],
            ['product_id' => $tint->id, 'variant_id' => $v['Black']->id, 'quantity' => 2],
        ],
    ])->assertOk();

    expect(DB::table('free_gift_range_items')->orderBy('sort')->pluck('variant_id')->map(fn ($i) => (int) $i)->all())->toBe([$v['Pink']->id, $v['Black']->id]);
    $gifts = $res->json('ranges.0.gifts');
    expect($gifts[0]['variant_id'])->toBe($v['Pink']->id)
        ->and($gifts[0]['product']['name'])->toBe('Lip Tint – Pink')
        ->and($gifts[0]['product']['type'])->toBe('variation')
        ->and($gifts[1]['product']['name'])->toBe('Lip Tint – Black');

    $compiled = app(SettingsService::class)->get(FreeGifts::COMPILED);
    expect($compiled['ranges'][0]['gifts'])->toBe([
        [$tint->id, 1, null, 'primary', $v['Pink']->id],
        [$tint->id, 2, null, 'primary', $v['Black']->id],
    ]);
});

it('refuses a variation that is not that product\'s, a product with options and no variation, and a variation on a simple product -- 422, nothing written', function () {
    /*
     * The defect this guards: a gift row pointing at another product's
     * variation would give, and take stock from, the wrong item.
     * MUTATION: drop the `$variantOwner[$vid] !== $p->id` check. RED.
     */
    [$tint, $v] = fgvTint();
    [$other, $ov] = fgvTint(['Rose' => 3], 'Blush');
    $mask = fgvProduct('Mask');
    $admin = fgvAdmin();
    $keep = fgvRange(100, 299, [[$mask, 1]]);
    $before = [DB::table('free_gift_range_items')->get()->toArray(), app(SettingsService::class)->get(FreeGifts::COMPILED)];

    $put = fn (array $gift) => test()->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $keep, [
        'min' => 100, 'max' => 299, 'label_en' => 'X', 'label_ar' => 'س', 'enabled' => true, 'gifts' => [$gift],
    ]);

    $put(['product_id' => $tint->id, 'variant_id' => $ov['Rose']->id, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('gifts.0.variant_id');
    $put(['product_id' => $tint->id, 'variant_id' => 999999, 'quantity' => 1])->assertStatus(422);
    $put(['product_id' => $tint->id, 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('gifts.0.variant_id');
    $put(['product_id' => $mask->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1])->assertStatus(422);
    test()->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $keep, [
        'min' => 100, 'max' => 299, 'label_en' => 'X', 'label_ar' => 'س', 'enabled' => true,
        'gifts' => [['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1], ['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1]],
    ])->assertStatus(422);

    SettingsService::forgetMemo();
    Setting::flushMap();
    expect([DB::table('free_gift_range_items')->get()->toArray(), app(SettingsService::class)->get(FreeGifts::COMPILED)])->toEqual($before)
        ->and(DB::table('free_gift_ranges')->where('id', $keep)->value('label_en'))->toBe('Gifts');

    // Fails closed for a role without the capability.
    $viewer = AdminUser::create(['name' => 'V', 'email' => 'fgv-v-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'support']);
    test()->actingAs($viewer, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $keep, [
        'min' => 100, 'max' => 299, 'label_en' => 'X', 'label_ar' => 'س', 'enabled' => true,
        'gifts' => [['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1]],
    ])->assertForbidden();
});

it('compiles, gives and draws a gift saved before variations exactly as it always did', function () {
    /*
     * The defect this guards: a fifth `null` in every compiled gift, or new
     * keys on the line or the row, would move the live box's fingerprint
     * (data-fgs) and every saved setting for gifts nobody touched.
     * MUTATION: always append `$i->variant_id` in compile(). RED.
     * MUTATION: add 'variant' => null to every line in linesFor(). RED.
     */
    fgvOn();
    $mask = fgvProduct('Sheet Mask', ['image' => 'mask.jpg']);
    fgvRange(100, null, [[$mask, 2]]);

    expect(app(SettingsService::class)->get(FreeGifts::COMPILED)['ranges'][0]['gifts'])->toBe([[$mask->id, 2, null, 'primary']]);

    $totals = fgvTotals(fgvCart([[fgvProduct('Serum', ['price' => 15000]), 1]]));
    $lines = app(FreeGifts::class)->forTotals($totals);
    expect(array_keys($lines[0]))->toBe(['product', 'product_id', 'quantity', 'value', 'range_id', 'range_label', 'kind', 'backup'])
        ->and(FreeGifts::rows($lines))->toBe([['name' => 'Sheet Mask', 'brand' => '', 'image' => 'mask.jpg', 'quantity' => 2, 'value' => 5000]]);
});

it('gives the chosen variation from its own stock, and falls to the backup when that variation is sold out', function () {
    /*
     * The defect: the gift reading the PARENT's stock (9) -- a sold-out Pink
     * still promised -- or another colour given in its place.
     * MUTATION: use $available($p) for a variant gift in linesFor(). RED.
     */
    fgvOn();
    [$tint, $v] = fgvTint(['Red' => 5, 'Pink' => 0, 'Black' => 5]);
    $mask = fgvProduct('Backup Mask', ['image' => 'mask.jpg']);
    fgvRange(100, null, [[$tint, 1, $v['Pink'], 'mask', 'primary'], [$mask, 1, null, 'mask', 'backup']], ['need_mask' => 1, 'need_mini' => 0, 'need_full' => 0]);
    $totals = fgvTotals(fgvCart([[fgvProduct('Serum', ['price' => 15000]), 1]]));

    $lines = app(FreeGifts::class)->forTotals($totals);
    expect($lines)->toHaveCount(1)->and($lines[0]['product_id'])->toBe($mask->id)->and($lines[0]['backup'])->toBeTrue();

    // Pink restocked: Pink is given, with its own name and photo.
    $v['Pink']->forceFill(['stock' => 4])->save();
    fgvFresh();
    $lines = app(FreeGifts::class)->forTotals($totals);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['variant_id'])->toBe($v['Pink']->id)
        ->and($lines[0]['backup'])->toBeFalse()
        ->and(FreeGifts::rows($lines)[0])->toMatchArray(['name' => 'Lip Tint – Pink', 'image' => 'https://img.example.com/tint-pink.jpg', 'quantity' => 1]);

    // The box the cart panel, cart page and checkout draw.
    $box = app(FreeGifts::class)->box($totals);
    expect($box['kbbFgRows'][0]['name'])->toBe('Lip Tint – Pink')->and($box['kbbFgThumbs'][0]['image'])->toBe('https://img.example.com/tint-pink.jpg');
    $html = view('partials.free-gifts.box', $box)->render();
    expect($html)->toContain('Lip Tint – Pink')->toContain('tint-pink');
});

it('writes the gift line with the variation\'s id and name and takes the units off THAT variation only', function () {
    /*
     * The defect: a gift line with no product_variant_id (the packer cannot
     * tell which colour), or the stock taken from the parent or the red one.
     * MUTATION: send 'variant_id' => null to StockClaim for gifts in place(). RED.
     * MUTATION: drop product_variant_id from the gift create(). RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    fgvOn();
    [$tint, $v] = fgvTint();
    fgvRange(100, 299, [[$tint, 2, $v['Pink']]]);
    $cart = fgvCart([[fgvProduct('Serum', ['price' => 15000]), 1]]);

    test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ])->assertRedirect();

    $order = Order::latest('id')->first();
    $g = $order->gifts->first();
    expect($order->gifts)->toHaveCount(1)
        ->and((int) $g->product_variant_id)->toBe($v['Pink']->id)
        ->and($g->name)->toBe('Lip Tint – Pink')
        ->and($g->sku)->toBe($v['Pink']->sku)
        ->and((int) $g->total)->toBe(0)
        ->and((int) $v['Pink']->fresh()->stock)->toBe(3)
        ->and((int) $v['Red']->fresh()->stock)->toBe(5)
        ->and((int) $v['Black']->fresh()->stock)->toBe(5)
        ->and((int) $tint->fresh()->stock)->toBe(9);

    // Thank-you page, account and admin order screen: its own photo.
    expect(FreeGifts::orderRows($order->fresh())[0])->toMatchArray(['name' => 'Lip Tint – Pink', 'image' => 'https://img.example.com/tint-pink.jpg']);
    $lines = \App\Services\Mail\Kit\KitOrder::lines(app(\App\Services\Mail\OrderEmailPresenter::class)->present($order->fresh()));
    $gift = collect($lines)->first(fn ($l) => array_key_exists('gift', $l));
    expect($gift['name'])->toBe('Lip Tint – Pink')->and((string) $gift['img'])->toContain('tint-pink');
});

it('costs the same queries for 3 variation gifts as for 15, and none extra for gifts without one', function () {
    /*
     * The defect: one variant read (and one value read) per gift on every
     * page with a basket, the homepage and the banner.
     * MUTATION: load each gift's variant with ProductVariant::find() in linesFor(). RED.
     */
    fgvOn();
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $gifts = [];
        for ($i = 0; $i < $n; $i++) {
            [$p, $v] = fgvTint(['Pink' => 5], 'Tint ' . $i);
            $gifts[] = [$p, 1, $v['Pink']];
        }
        fgvRange(100, null, $gifts);
        $totals = fgvTotals(fgvCart([[fgvProduct('Serum', ['price' => 15000]), 1]]));
        fgvFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = FreeGifts::rows(app(FreeGifts::class)->forTotals($totals));
        app(FreeGifts::class)->home(request());
        expect($rows)->toHaveCount($n)->and($rows[0]['name'])->toEndWith(' – Pink');

        return fgvQueries();
    };

    // Products once, variations once -- the homepage reuses both.
    expect($count(3))->toBe($count(15))->toBe(2);

    // A band with no variation costs exactly what it did: one query.
    DB::table('free_gift_ranges')->delete();
    fgvRange(100, null, [[fgvProduct('Plain'), 1]]);
    $totals = fgvTotals(fgvCart([[fgvProduct('Serum', ['price' => 15000]), 1]]));
    fgvFresh();
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(FreeGifts::class)->forTotals($totals);
    expect(fgvQueries())->toBe(1);

    // The admin's picker: one query for every variation of every result.
    $admin = fgvAdmin();
    $picker = function (int $n) use ($admin): int {
        for ($i = 0; $i < $n; $i++) {
            fgvTint(['Red' => 1, 'Pink' => 1], 'Zz Picker ' . Str::random(5));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($admin, 'admin')->getJson('/admin-api/free-gifts/products?q=Zz')->assertOk();

        return count(DB::getQueryLog());
    };
    expect($picker(3))->toBe($picker(12));
});
