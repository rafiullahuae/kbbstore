<?php

declare(strict_types=1);

/*
 * "Show the total and buy-together discount" — the master switch over the
 * Buy these together total row and its discount.                    (Lane RH)
 *
 * The owner, 3 October:
 *
 *   "also buy together pricing and discount row, i want to hide on desktop and
 *    mobile both by default, if hide, then no any discount will be picked from
 *    the system from the buy together discount. lock that discount section is
 *    the section is hided with toggle button."
 *
 * Appearance → Product page → Buy these together → "Discount for buying
 * together" card, at its top. OFF by default because he asked for off.
 *
 * THE BASKET (BuyTogetherBundleTest's): Relief Sun 69, Moist Best 108 (on sale
 * from 153), Toner Best 80, Oil Best 95 — AED 352. Tiers 5 / 10 / 15 %; four
 * together at 10 % is AED 36 off when the switch is on, AED 0 when it is off.
 *
 * MUTATIONS, each made against the file named, RUN (storage/rh-logs/mutate.py,
 * a scratch harness, not committed), red as stated, and reverted
 * byte for byte:
 *
 *   H1  BuyTogetherPricing::percentFor() — drop `|| empty($c['discount_on'])`
 *       → "default OFF" red (bundle_discount 3600, "Bought together · 10% off"
 *       in the drawer, the order's bundle_discount 3600), "coupon … ordinary
 *       lines" red, "re-prices a grouped basket" red, and "ignores the tiers"
 *       red — 4 of 21 in this file and BuyTogetherBundleTest.
 *   H2  BuyTogetherSettings SCHEMA — `discount_on` default `true` → "default
 *       OFF", "coupon", "ignores the tiers" and "serves the switch OFF to the
 *       admin" red — 4 of 21.
 *   H3  fbt.blade.php — drop `&& ! empty($btCfg['discount_on'])` from the
 *       row's @if → "default OFF" red on `bt-sumrow` (the row drawn, with a
 *       zero total and no saving).
 *   H4  BuyTogetherSettings::save() — drop the LOCKED `continue` → "ignores
 *       the tiers" red: a POST while off writes tier_4 = 40.
 *   H5  fbt.blade.php — drop the ` bt-hide-m` class → "per-device" red.
 *   H6  product-buy-together-screen.blade.php — drop the aria-disabled guard
 *       in the click handler → "locked controls" red.
 *   H7  BuyTogetherPricing::couponsInclude() — drop `empty($c['discount_on'])
 *       ||` → nothing red (couponLines() only reads it with a bundle to
 *       apply, and there is none while off): belt and braces, not load-bearing.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherPricing;
use App\Services\BuyTogetherSettings;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function btswFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
    app()->forgetInstance(BuyTogetherSettings::class);
    app()->forgetInstance(BuyTogetherPricing::class);
}

/**
 * The live shop on the day this lands: the section on and his tiers stored,
 * and NO `bt_discount_on` row — written straight to settings, the way the
 * rows already sit on the server, not through save().
 */
function btswLiveShop(): void
{
    $s = app(SettingsService::class);
    foreach (['bt_on' => 1, 'bt_count' => 4, 'bt_tier_3' => 5, 'bt_tier_4' => 10, 'bt_tier_5' => 15] as $k => $v) {
        $s->set($k, $v);
    }
    btswFlush();
}

/** @param array<string, mixed> $values */
function btswSave(array $values): void
{
    app(BuyTogetherSettings::class)->save($values);
    btswFlush();
}

function btswCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function btswProduct(string $name, array $cats, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'total_sales' => $sales,
    ], $extra));

    if ($cats !== []) {
        $p->categories()->sync(array_map(fn (Category $c) => $c->id, $cats));
    }

    return $p;
}

function btswShop(): array
{
    return [
        'sun' => btswProduct('Relief Sun', [btswCat('Sunscreens')], 10000, ['price' => 6900]),
        'moist' => btswProduct('Moist Best', [btswCat('Moisturisers')], 900000, ['price' => 15300, 'sale_price' => 10800]),
        'toner' => btswProduct('Toner Best', [btswCat('Toners')], 700000, ['price' => 8000]),
        'oil' => btswProduct('Oil Best', [btswCat('Cleansing Oils')], 500000, ['price' => 9500]),
        'mask' => btswProduct('Mask Best', [btswCat('Masks')], 300000, ['price' => 6000]),
    ];
}

function btswCart(): Cart
{
    return Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
}

function btswAs(Cart $cart)
{
    app(CartService::class)->forget();

    return test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function btswTogether(Cart $cart, array $s)
{
    return btswAs($cart)->postJson('/api/cart/add-together', [
        'items' => array_map(fn (Product $p) => ['product_id' => $p->id], [$s['sun'], $s['moist'], $s['toner'], $s['oil']]),
        'main_id' => $s['sun']->id,
    ]);
}

function btswTotals(Cart $cart): array
{
    return app(CartService::class)->totals(Cart::query()->find($cart->id), 'AE');
}

function btswPage(Product $p): string
{
    Cache::flush();

    return (string) test()->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

function btswCheckoutSetup(): void
{
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}

function btswPlace(Cart $cart): Order
{
    btswAs($cart)->post('/checkout/place', [
        'billing_email' => 'rh@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan', 'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertRedirect();

    return Order::query()->latest('id')->firstOrFail();
}

function btswAdmin(): void
{
    test()->actingAs(AdminUser::create(['name' => 'O', 'email' => 'rh-'.Str::random(6).'@example.test', 'password' => Hash::make('secret-secret'), 'role' => 'owner']), 'admin');
}

/* ═══════════════════════════ default OFF ═══════════════════════════════ */

it('ships the switch OFF: no total row on the page and no discount in the cart, drawer, checkout or order, even with tiers set', function () {
    /*
     * The defect this guards, on the shop: the package lands, his stored
     * tiers are still live, and "You're saving AED 36" and a buy-together
     * discount keep appearing on a shop where he asked for them hidden — or
     * the row is hidden but the checkout still takes AED 36 off.
     */
    expect(BuyTogetherSettings::defaults())->toMatchArray(['discount_on' => false, 'row_phone' => true, 'row_laptop' => true]);

    $s = btswShop();
    btswLiveShop();
    btswCheckoutSetup();

    // No row written by anything: the code default is what is read.
    expect(DB::table('settings')->where('key', 'bt_discount_on')->exists())->toBeFalse()
        ->and(app(BuyTogetherSettings::class)->discountOn())->toBeFalse()
        ->and(app(BuyTogetherPricing::class)->tiers())->toBe([3 => 0, 4 => 0, 5 => 0, 6 => 0])
        // The tiers he stored are kept, not wiped.
        ->and(app(BuyTogetherSettings::class)->all()['tier_4'])->toBe(10);

    // The page: the section and its button, no row.
    $html = btswPage($s['sun']);
    expect($html)->toContain('class="kbb-fbt bt')
        ->toContain('data-bt-buy')
        ->toContain('Buy 4 items together')
        ->toContain('data-tiers="{&quot;3&quot;:0,&quot;4&quot;:0,&quot;5&quot;:0,&quot;6&quot;:0}"')
        ->not->toContain('bt-sumrow')
        ->not->toContain('class="bt-save"')
        ->not->toContain('class="bt-total"');

    // The button adds all four at their own prices.
    $cart = btswCart();
    $res = btswTogether($cart, $s)->assertOk();
    $t = btswTotals($cart);
    expect($t['subtotal'])->toBe(35200)
        ->and($t['bundle_discount'])->toBe(0)
        ->and($t['bundle']['lines'])->toBe([])
        ->and($t['total'])->toBe(35200);
    expect((string) $res->json('drawer'))->not->toContain('Bought together')->not->toContain('Buy-together discount')->not->toContain('class="kc-bt"');

    // The group is still recorded — it just costs nothing while off.
    expect($cart->items()->whereNotNull('bt_group')->count())->toBe(4);

    $page = (string) btswAs($cart)->get('/cart')->assertOk()->getContent();
    expect($page)->not->toContain('Buy-together discount')->not->toContain('Bought together')->not->toContain('class="cbt"');

    $co = (string) btswAs($cart)->get('/checkout')->assertOk()->getContent();
    expect($co)->not->toContain('Buy-together discount')->not->toContain('class="co-bt"');

    // The order: nothing taken off, nothing recorded as bundled.
    $order = btswPlace($cart);
    expect([(int) $order->subtotal, (int) $order->bundle_discount, (int) $order->discount_total])->toBe([35200, 0, 0]);
    $items = $order->items()->get();
    expect($items->pluck('bundle_discount')->unique()->all())->toBe([0])
        ->and($items->pluck('bundle_percent')->filter()->all())->toBe([]);

    // And the payment providers are sent exactly the order total.
    $tabby = app(\App\Services\Payments\Gateways\TabbyGateway::class);
    $obj = (new ReflectionMethod($tabby, 'paymentObject'))->invoke($tabby, $order->fresh('items'), 'AED');
    $sum = array_sum(array_map(fn ($i) => (float) $i['unit_price'] * $i['quantity'], $obj['order']['items']));
    expect(round($sum + (float) $obj['order']['shipping_amount'] + (float) $obj['order']['tax_amount'] - (float) $obj['order']['discount_amount'], 2))
        ->toBe(round((float) $obj['amount'], 2))
        ->and(round((float) $obj['amount'] * 100))->toBe((float) $order->total)
        ->and((float) $obj['order']['discount_amount'])->toBe(0.0);

    $tamara = app(\App\Services\Payments\Gateways\TamaraGateway::class);
    $a = (new ReflectionMethod($tamara, 'amounts'))->invoke($tamara, $order->fresh('items'), (int) $order->total, 'AED');
    expect($a['items_fils'] + $a['shipping'] + $a['tax'] - $a['discount'])->toBe((int) $order->total)
        ->and($a['discount'])->toBe(0);

    // The receipt has no buy-together row.
    $receipt = view('partials.checkout.received-summary', ['order' => $order->fresh('items'), 'settings' => app(SettingsService::class)])->render();
    expect($receipt)->not->toContain('Buy-together discount');
});

it('lets a coupon treat the lines as ordinary lines while off, even under Exclude', function () {
    /*
     * "Coupons behave as if there were no groups": with Exclude stored and the
     * switch off, a coupon must discount the bundled products like any others
     * — not skip them for a bundle discount nobody is getting.
     */
    $s = btswShop();
    btswLiveShop();
    app(SettingsService::class)->set('bt_coupons', 0);
    btswFlush();
    Coupon::create(['code' => 'GLOW10', 'type' => 'percent', 'amount' => 1000]);

    $grouped = btswCart();
    btswTogether($grouped, $s)->assertOk();
    btswAs($grouped)->postJson('/api/cart/coupon', ['code' => 'GLOW10'])->assertOk();

    $plain = btswCart();
    foreach (['sun', 'moist', 'toner', 'oil'] as $k) {
        btswAs($plain)->postJson('/api/cart/add', ['product_id' => $s[$k]->id])->assertOk();
    }
    btswAs($plain)->postJson('/api/cart/coupon', ['code' => 'GLOW10'])->assertOk();

    $g = btswTotals($grouped);
    $p = btswTotals($plain);
    expect($g['bundle_discount'])->toBe(0)
        ->and($g['discount'])->toBeGreaterThan(0)
        ->and([$g['discount'], $g['total']])->toBe([$p['discount'], $p['total']]);
});

/* ═══════════════════════════ ON ═════════════════════════════════════════ */

it('works exactly as before when switched on: the row on both devices, the bundle priced', function () {
    $s = btswShop();
    btswLiveShop();
    btswSave(['discount_on' => true]);

    // The row as it was drawn before this switch existed: no device class.
    $html = btswPage($s['sun']);
    expect($html)->toMatch('#<div class="bt-sumrow">\s*<p class="bt-save"[^>]*>.*?</p>\s*<p class="bt-total">.*?</p>\s*</div>#s')
        ->toContain('<span class="bt-save-num">36</span>')
        ->toContain('data-tiers="{&quot;3&quot;:5,&quot;4&quot;:10,&quot;5&quot;:15,&quot;6&quot;:15}"');

    $cart = btswCart();
    $res = btswTogether($cart, $s)->assertOk();
    expect(btswTotals($cart)['bundle_discount'])->toBe(3600)
        ->and((string) $res->json('drawer'))->toContain('Bought together · 10% off')->toContain('Buy-together discount');
});

it('re-prices a grouped basket to normal the moment it is switched off, and back when it is switched on', function () {
    $s = btswShop();
    btswLiveShop();
    btswSave(['discount_on' => true]);

    $cart = btswCart();
    btswTogether($cart, $s)->assertOk();
    expect(btswTotals($cart)['bundle_discount'])->toBe(3600);

    btswSave(['discount_on' => false]);
    $t = btswTotals($cart);
    expect($t['bundle_discount'])->toBe(0)->and($t['total'])->toBe(35200);
    $page = (string) btswAs($cart)->get('/cart')->assertOk()->getContent();
    expect($page)->not->toContain('Bought together');

    // The tiers were kept, so switching back restores the same bundle.
    btswSave(['discount_on' => true]);
    expect(btswTotals($cart)['bundle_discount'])->toBe(3600);
});

/* ═══════════════════════════ the server holds the lock ═════════════════ */

it('ignores the tiers while off: nothing prices a bundle, and a request cannot change a locked value', function () {
    btswLiveShop();
    $pricing = app(BuyTogetherPricing::class);
    expect($pricing->percentFor(3))->toBe(0)
        ->and($pricing->percentFor(4))->toBe(0)
        ->and($pricing->percentFor(6))->toBe(0)
        ->and($pricing->anyTier())->toBeFalse();

    // Around the locked sliders, straight at the endpoint: refused quietly,
    // the stored values kept — and the unlocked options still save.
    btswAdmin();
    test()->postJson('/admin-api/product-page', ['together' => ['options' => ['tier_4' => 40, 'coupons' => false, 'row_phone' => false]]])->assertOk();
    btswFlush();
    $c = app(BuyTogetherSettings::class)->all();
    expect([$c['tier_4'], $c['coupons'], $c['row_phone'], $c['discount_on']])->toBe([10, true, false, false])
        ->and(app(BuyTogetherPricing::class)->percentFor(4))->toBe(0);

    // Switched on in the same request: the tiers move with it.
    test()->postJson('/admin-api/product-page', ['together' => ['options' => ['discount_on' => true, 'tier_4' => 40]]])->assertOk();
    btswFlush();
    expect(app(BuyTogetherPricing::class)->percentFor(4))->toBe(40);

    // A bool is stored as a bool, whatever is posted.
    btswSave(['discount_on' => 'off']);
    expect(app(BuyTogetherSettings::class)->all()['discount_on'])->toBeFalse();
});

/* ═══════════════════════════ the admin ═════════════════════════════════ */

it('serves the switch OFF to the admin and draws the discount card locked while it is off', function () {
    btswLiveShop();
    btswAdmin();
    $fields = collect(test()->getJson('/admin-api/product-page')->assertOk()->json('together.options'))->flatMap(fn ($t) => $t['fields'])->keyBy('key');
    expect($fields['discount_on']['value'])->toBeFalse()
        ->and($fields['discount_on']['label'])->toBe('Show the total and buy-together discount')
        ->and($fields['row_phone']['label'])->toBe('Show on phones')
        ->and($fields['row_laptop']['label'])->toBe('Show on laptops')
        ->and($fields['row_laptop']['help'])->toBe('The discount still applies at checkout on a device where the row is hidden; switch the whole thing off above to stop the discount.')
        // The stored tiers are served, so the locked sliders show them.
        ->and($fields['tier_4']['value'])->toBe(10);

    $src = (string) file_get_contents(resource_path('views/admin/partials/product-buy-together-screen.blade.php'));
    expect($src)
        // the master switch first, its own row, never locked
        ->toContain("'<div class=\"mmbody\">' + optRow('discount_on') + '</div>'")
        // the note, only while off
        ->toContain("(on ? '' : '<p class=\"btp-lock-note\" role=\"note\">Turn on ‘Show the total and buy-together discount’ to use these.</p>')")
        // every control under it locked while off, the preview greyed too
        ->toContain("var lockA = on ? '' : ' btp-locked\" aria-disabled=\"true';")
        ->toContain("['row_phone', 'row_laptop', 'tier_3', 'tier_4', 'tier_5', 'coupons'].map(function (k) { return optRowL(k, !on); })")
        ->toContain("'<div class=\"btp-prev' + lockA + '\">")
        // a locked slider is disabled; a locked switch is out of the tab order
        ->toContain("(locked ? ' disabled aria-disabled=\"true\"' : '')")
        ->toContain("t.replace(' tabindex=\"0\"', ' tabindex=\"-1\" aria-disabled=\"true\"')")
        // and a click on a locked switch does nothing
        ->toContain("if (ot.getAttribute('aria-disabled') === 'true') return;")
        ->toContain('.btp-locked{opacity:.45;filter:grayscale(1);pointer-events:none;user-select:none}')
        // `.map(optRow)` never reads the index as `locked`
        ->toContain('function optRow(k) { return optRowL(k, false); }');
});

/* ═══════════════════════════ per device ════════════════════════════════ */

it('hides the row on one device by a class at the block\'s 1024px breakpoint, and the discount still applies', function () {
    $s = btswShop();
    btswLiveShop();

    btswSave(['discount_on' => true, 'row_phone' => false]);
    expect(btswPage($s['sun']))->toContain('<div class="bt-sumrow bt-hide-m">');

    btswSave(['row_phone' => true, 'row_laptop' => false]);
    expect(btswPage($s['sun']))->toContain('<div class="bt-sumrow bt-hide-d">');

    // Both hidden: not drawn at all.
    btswSave(['row_phone' => false, 'row_laptop' => false]);
    expect(btswPage($s['sun']))->not->toContain('bt-sumrow')->toContain('data-bt-buy');

    // The basket cannot know the device: hidden or not, the bundle is priced.
    $cart = btswCart();
    btswTogether($cart, $s)->assertOk();
    expect(btswTotals($cart)['bundle_discount'])->toBe(3600);

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    expect($css)->toContain('@media (max-width:1023.98px){.bt-sumrow.bt-hide-m{display:none}}')
        ->toContain('@media (min-width:1024px){.bt-sumrow.bt-hide-d{display:none}}');
});
