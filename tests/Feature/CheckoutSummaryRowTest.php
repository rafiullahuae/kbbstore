<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * THE CHECKOUT SUMMARY IS ONE THIN ROW, AND BROWSED IS OFF (Lane CK, part 2)
 * =============================================================================
 *
 * The owner: "on checkout page i want to replace the whole summary section to
 * this single thin row, with cart icon, Order Summary text, then order total,
 * and then down pink (our color) arrow with slight continue animation, also
 * turn off the browsed tab on the checkout summary section. do not remove any
 * existing functinoality, just turn off."
 *
 * Appearance → Checkout page → Fields & attention:
 *   "Order summary: collapsed to one row"   ON  (new default, asked for)
 *   "Recently browsed in the summary"       OFF (new default, asked for)
 * Each switched back restores the old markup exactly.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 700);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function csrCart(): Cart
{
    $product = Product::create([
        'slug' => 'csr-' . uniqid(), 'name' => 'Row Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 15550, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15550]);

    return $cart->fresh('items');
}

function csrPage(Cart $cart, array $cookies = []): string
{
    $t = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    foreach ($cookies as $k => $v) {
        $t = $t->withUnencryptedCookie($k, $v);
    }

    return $t->get('/checkout/')->assertOk()->getContent();
}

function csrAside(string $html): string
{
    $from = strpos($html, '<aside class="summary');

    return $from === false ? '' : substr($html, $from, strpos($html, '</aside>', $from) - $from);
}

function csrSet(array $values): void
{
    app(CheckoutPage::class)->save($values);
    SettingsService::forgetMemo();
}

/** The inner HTML of the first `<span class="$class">` inside $html. */
function csrSpan(string $html, string $class): ?string
{
    return preg_match('#<span class="' . preg_quote($class, '#') . '">(.*?)</span></span>#s', $html, $m)
        || preg_match('#<span class="' . preg_quote($class, '#') . '">(.*?)</span>#s', $html, $m)
        ? $m[1] : null;
}

it('folds the summary into one row by default, carrying the order total', function () {
    // MUTATION: default `sum_row` to false. RED.
    $html = csrPage(csrCart());

    expect($html)->toContain('<aside class="summary cosr-on" id="kbbSummary">')
        ->and($html)->toContain('<button type="button" class="cosr" id="kbbSumRow" aria-expanded="false" aria-controls="kbbPanels">')
        ->and($html)->toContain('Order summary')
        // The shop's own cart icon, not a new one.
        ->and($html)->toContain(\App\Support\HeaderIcons::cart())
        // Folded, not removed: every line is still in the page under it.
        ->and($html)->not->toContain('class="summary cosr-on cosr-open"')
        ->and($html)->toContain('id="kbbPanels"')
        ->and($html)->toContain('Row Toner');

    // The row's totals are the order block's totals, to the character -- both
    // the ordinary one and the cash-on-delivery one.
    preg_match('#<button type="button" class="cosr".*?</button>#s', $html, $row);
    preg_match('#<div class="sumrow tot js-total-row"><span>[^<]*</span><span class="js-total">(.*?)</span></div>#s', $html, $tot);
    preg_match('#<div class="sumrow tot js-total-row-fee"><span>[^<]*</span><span class="js-total-fee">(.*?)</span></div>#s', $html, $fee);

    expect($row[0] ?? '')->toContain('<span class="js-total">' . $tot[1] . '</span>')
        ->and($row[0])->toContain('<span class="js-total-fee">' . $fee[1] . '</span>')
        // 2 x AED 155.50 + AED 20 delivery = AED 331; COD adds AED 7.
        ->and(strip_tags($tot[1]))->toContain('331')
        ->and(strip_tags($fee[1]))->toContain('338');
});

it('brings back the old summary exactly with the switch off', function () {
    csrSet(['sum_row' => false]);

    $html = csrPage(csrCart());

    expect($html)->toContain('<aside class="summary" id="kbbSummary">')
        ->and($html)->not->toContain('id="kbbSumRow"')
        ->and($html)->not->toContain('class="cosr')
        ->and($html)->not->toContain('cosrNudge')
        ->and($html)->toContain('id="kbbViewItems"');
});

it('keeps every totals update reaching the row', function () {
    /*
     * The country / emirate refresh and the gift toggle write every .js-total
     * and .js-total-fee on the page; the row carries both classes, so they
     * reach it. The quantity steppers replace .kbb-order-slot wholesale and
     * write only the floating bar's total -- the row follows that block with a
     * MutationObserver. MUTATION: drop the observer. RED.
     */
    $html = csrPage(csrCart());

    expect($html)->toContain("var slot = box.querySelector('.kbb-order-slot');")
        ->and($html)->toContain("}).observe(slot, { childList: true });")
        ->and($html)->toContain('.kbb-checkout:has(#payment_method_cod:checked) .cosr .js-total{display:none}')
        ->and($html)->toContain('.kbb-checkout:has(#payment_method_cod:checked) .cosr .js-total-fee{display:inline}');

    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("document.querySelectorAll('.js-total').forEach((el) => { el.innerHTML = data.total; });")
        ->and($js)->toContain("document.querySelectorAll('.js-total-fee').forEach((el) => { el.innerHTML = data.totalWithFee; });");
});

it('counts a gift into the row total exactly as the order block does', function () {
    $cart = csrCart();
    $html = test()->withSession(['kbb_gift' => true])
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/')->assertOk()->getContent();

    preg_match('#<button type="button" class="cosr".*?</button>#s', $html, $row);
    preg_match('#<div class="sumrow tot js-total-row"><span>[^<]*</span><span class="js-total">(.*?)</span></div>#s', $html, $tot);

    // AED 331 + the AED 15 default gift fee.
    expect(strip_tags($tot[1]))->toContain('346')
        ->and($row[0])->toContain('<span class="js-total">' . $tot[1] . '</span>');
});

it('animates the arrow in transform only, and stops it for reduced motion', function () {
    $html = csrPage(csrCart());

    expect($html)->toContain('@keyframes cosrNudge{0%,100%{transform:translateY(0)}50%{transform:translateY(3px)}}')
        ->and($html)->toContain('color:var(--pink)')
        ->and($html)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\s*\.kbb-checkout \.cosr-chev\{animation:none\}/');
});

it('prints no Browsed tab and looks nothing up for one by default', function () {
    /*
     * MUTATION: default `browsed_on` to true. RED -- the tab, its list and the
     * recently-viewed product are printed, and the page runs the query.
     */
    $viewed = Product::create([
        'slug' => 'csr-viewed', 'name' => 'Viewed Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
    ]);
    $cart = csrCart();

    $off = csrPage($cart, ['kbb_viewed' => (string) $viewed->id]);

    expect($off)->not->toContain('data-stab="browsed"')
        ->and($off)->not->toContain('id="kbbBrowsedList"')
        // Inside the summary. (The cart drawer in the page chrome keeps a
        // recently-viewed list of its own, which this switch is not about.)
        ->and(csrAside($off))->not->toContain('Viewed Essence');

    // The lookup itself is skipped while off, not merely its output: the
    // method the page and every fragment refresh call runs no query at all.
    $browsed = new ReflectionMethod(\App\Http\Controllers\Store\CheckoutController::class, 'browsed');
    $request = Illuminate\Http\Request::create('/checkout/', 'GET', [], ['kbb_viewed' => (string) $viewed->id]);
    $controller = app(\App\Http\Controllers\Store\CheckoutController::class);

    DB::enableQueryLog();
    $none = $browsed->invoke($controller, $request, $cart);
    $offQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($none->count())->toBe(0)->and($offQueries)->toBe(0);

    csrSet(['browsed_on' => true]);

    $on = csrPage($cart, ['kbb_viewed' => (string) $viewed->id]);

    expect($on)->toContain('data-stab="browsed"')
        ->and($on)->toContain('id="kbbBrowsedList"')
        ->and(csrAside($on))->toContain('Viewed Essence')
        ->and($browsed->invoke($controller, $request, $cart)->pluck('id')->all())->toBe([$viewed->id]);
});

it('ships both new switches on the checkout screen, where the owner will look', function () {
    expect(CheckoutPage::SCHEMA['sum_row'][1])->toBe('Order summary: collapsed to one row')
        ->and(CheckoutPage::SCHEMA['sum_row'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['browsed_on'][1])->toBe('Recently browsed in the summary')
        ->and(CheckoutPage::SCHEMA['browsed_on'][2])->toBeFalse()
        ->and(CheckoutPage::TABS['cues'][0])->toBe('Fields & attention')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('sum_row')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('browsed_on');
});
