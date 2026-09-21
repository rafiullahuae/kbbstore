<?php

declare(strict_types=1);

/*
 * The squeezed cart page: the layout switch, the sizing, the address sheet and
 * the ownership rules behind it.
 *
 * Each block names the MUTATION that turns it red, because a test whose
 * failure mode nobody has checked is a test nobody can trust. Where a mutation
 * was tried and came back GREEN that is written down too, with the one that is
 * actually red beside it.
 */

use App\Models\Address;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use App\Support\CartAddressState;
use App\Support\Countries;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Register routes/cart-address.php for this test.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the file ships
 * for the integrator to require. Skipping the endpoint tests until he does
 * would mean the ownership rules -- the half of this package that can leak
 * somebody's home address -- were never executed by anything. So the suite
 * mounts the file itself, in the same `web` group and at the same place the
 * route file's own header names. If the integrator's one line ever disagrees
 * with this, these tests are testing the wrong mounting, which is why the
 * route file states the group it belongs in rather than leaving it to be
 * guessed.
 */
function squeezeRoutes(): void
{
    if (app('router')->getRoutes()->hasNamedRoute('cart.address')) {
        return;
    }

    Route::middleware('web')->group(base_path('routes/cart-address.php'));
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * A free-delivery threshold, set the way this shop actually sets one.
 *
 * THROUGH A ZONE AND A free_shipping METHOD, not through a setting key. There
 * is no `free_shipping_threshold` setting in this application — nothing reads
 * one — and this lane deliberately did not add a second place to configure it:
 * ShippingService::freeShippingThreshold() resolves it from the shipping zone
 * that serves the shopper's country, and that is the figure the rest of the
 * storefront already advertises.
 */
function squeezeFreeOver(int $fils): void
{
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'min_amount' => $fils,
        'enabled' => true,
        'position' => 0,
    ]);
}

/** Turn the squeezed layout on, and anything else this test wants. */
function squeezeOn(array $extra = []): void
{
    app(CartPage::class)->save(['layout' => 'squeeze'] + $extra);
}

function squeezeCart(int $qty = 2): Cart
{
    $product = Product::create([
        'slug' => 'squeeze-'.Str::random(8),
        'name' => 'Glow Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 12000]);

    return $cart;
}

function squeezeGet(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart');
}

/* ------------------------------------------------------------------------
 | 1. The default changes nothing
 |------------------------------------------------------------------------*/

it('ships on the classic layout and adds not one byte to the page', function () {
    // The guarantee the whole package rests on. This is a live shop.
    expect(app(CartPage::class)->get('layout'))->toBe('classic')
        ->and(app(CartPage::class)->squeezed())->toBeFalse()
        ->and(app(CartPage::class)->cssVariables())->toBe('')
        ->and(app(CartPage::class)->bodyClass())->toBe('')
        ->and(app(CartPage::class)->styleAttr())->toBe('');

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    // The wrapper is the one that ships today, with no class and no style.
    expect($html)->toContain('<div class="kbb-cartpage" id="cartPage">')
        // and none of the squeezed furniture exists at all.
        ->and($html)->not->toContain('cpg-squeeze')
        ->and($html)->not->toContain('cpg-sheet')
        ->and($html)->not->toContain('cpg-docked')
        ->and($html)->not->toContain('cpg-rec');
});
// MUTATION: change CartPage::SCHEMA's 'layout' default from 'classic' to
// 'squeeze'. RED — the wrapper gains ` cpg-squeeze` and a style attribute, and
// every not->toContain above fires. Run and confirmed.

/* ------------------------------------------------------------------------
 | 2. Sizing is CSS, derived from the row height
 |------------------------------------------------------------------------*/

it('emits every size as a custom property and none of them from JavaScript', function () {
    squeezeOn(['row_h' => 58, 'row_font' => 115, 'rec_per' => 55, 'addr_h' => 34, 'co_h' => 54]);

    $vars = app(CartPage::class)->cssVariables();

    expect($vars)->toContain('--cpg-row-h:58px')
        ->and($vars)->toContain('--cpg-fscale:1.15')
        // rec_per is stored in TENTHS; 55 is five and a half cards.
        ->and($vars)->toContain('--cpg-per:5.50')
        ->and($vars)->toContain('--cpg-addr-h:34px')
        ->and($vars)->toContain('--cpg-co-h:54px');

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // The derived sizes are calc() off the knob, in the stylesheet.
    expect($css)->toContain('--cpg-thumb:calc(var(--cpg-row-h) - (var(--cpg-pad) * 2))')
        ->and($css)->toContain('--cpg-f-name:calc((11.5px + var(--cpg-row-h) * .042) * var(--cpg-fscale))');

    // And nothing measures anything. A resize observer here would mean the
    // first paint is the wrong size on every phone.
    expect($css)->not->toContain('ResizeObserver')
        ->and($css)->not->toContain('getBoundingClientRect')
        ->and($css)->not->toContain('window.innerWidth');
});
// MUTATION: make cssVariables() emit '--cpg-row-h:96px' unconditionally. RED on
// the first assertion. Run and confirmed.
//
// MUTATION TRIED AND GREEN: deleting the `--cpg-f-name` line from the
// stylesheet alone — the `not->toContain` assertions still pass and so does
// everything in block 1, because nothing else reads that property. The
// toContain on it is what catches it, and it is red. Kept for that reason.

it('keeps 4.5 cards a fraction of the screen rather than a pixel width', function () {
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // The card width divides the SCREEN by the count, so it holds on any phone.
    expect($css)->toContain('/ var(--cpg-per))')
        // and the name size is a clamp against vw, which is what "auto adjust
        // to the screen" means.
        ->and($css)->toContain('.cpg-card .nm{font-size:clamp(8px,2.45vw,10.5px)');
});
// MUTATION: replace the flex-basis with a fixed `flex:0 0 78px`. RED.

/* ------------------------------------------------------------------------
 | 3. The page, squeezed
 |------------------------------------------------------------------------*/

it('renders the docked rows, the summary and the trust row once switched on', function () {
    squeezeOn(['sum_express_on' => true, 'sum_delivery_on' => true,
        'sum_service_on' => true, 'sum_express' => 1500, 'sum_service' => 300]);

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    expect($html)->toContain('cpg-squeeze')
        ->and($html)->toContain('--cpg-row-h:96px')
        // Both docked rows, the shorter one first.
        ->and($html)->toContain('cpg-addrbar')
        ->and($html)->toContain('cpg-cobar')
        ->and($html)->toContain('Please choose your delivery address')
        ->and($html)->toContain('+ Address')
        ->and($html)->toContain('>Proceed to Checkout</a>')
        // The summary, as the reference reads.
        ->and($html)->toContain('Order Value')
        ->and($html)->toContain('Express Delivery Charge')
        ->and($html)->toContain('Standard Delivery Charge')
        ->and($html)->toContain('Service Fee')
        ->and($html)->toContain('Order Total')
        // The trust row: a tick, a rule, and the marks.
        ->and($html)->toContain('cpg-trust')
        ->and($html)->toContain('Secure checkout')
        ->and($html)->toContain('tabby')
        ->and($html)->toContain('tamara');
});
// MUTATION: flip the `@if ($kbbSq)` around the docked block in
// cart-inner.blade.php to `@if (! $kbbSq)`. RED on cpg-addrbar. Run.

it('quotes express delivery instead of adding it to the order total', function () {
    // The reference shows Express priced beside a free Standard and a total
    // that matches Standard. A total that silently included it would surprise
    // the shopper at the payment step — which is the defect the page's own
    // "delivery calculated at checkout" note exists to prevent.
    squeezeOn(['sum_express_on' => true, 'sum_express' => 5000]);

    $cart = squeezeCart(2);            // 2 x AED 120 = AED 240
    $html = squeezeGet($cart)->assertOk()->getContent();

    // Compared against the shop's own formatter rather than a literal: the
    // symbol is wrapped in its own bidi-isolated span, so "AED 240" is never a
    // contiguous run of bytes in the page.
    expect($html)->toContain(\App\Support\Money::format(24000))
        // Neither the express charge nor the service fee has moved the total.
        ->and($html)->not->toContain(\App\Support\Money::format(29000))
        ->and($html)->not->toContain(\App\Support\Money::format(24300));
});
// MUTATION FIRST TRIED AND GREEN: adding the express charge into $kbbGrand,
// against `not->toContain(AED 293)`. Green, and the assertion was simply
// wrong arithmetic — 240 + 50 is 290, not 293. The figure is now AED 290 and
// the same mutation is RED. Written down rather than quietly corrected,
// because a test whose red nobody checked is a test nobody can trust.

it('charges the service fee only while its row is on, and by exactly what it shows', function () {
    // A switched-off row contributes nothing to the total either. A charge a
    // shopper cannot see on the line above is a charge they meet for the first
    // time at the payment step.
    squeezeOn(['sum_service_on' => false, 'sum_service' => 300]);

    $off = squeezeGet(squeezeCart(2))->assertOk()->getContent();
    expect($off)->toContain(\App\Support\Money::format(24000))
        ->and($off)->not->toContain(\App\Support\Money::format(24300))
        ->and($off)->not->toContain('Service Fee');

    squeezeOn(['sum_service_on' => true]);

    $on = squeezeGet(squeezeCart(2))->assertOk()->getContent();
    expect($on)->toContain('Service Fee')
        ->and($on)->toContain(\App\Support\Money::format(24300));
});
// MUTATION: make serviceFee() ignore sum_service_on and always return the
// amount. RED on the `not->toContain(AED 243)` in the off half.

it('takes a percentage fee off the order value AFTER the coupon', function () {
    squeezeOn(['sum_service_on' => true, 'sum_service_mode' => 'percent', 'sum_service_pct' => 5]);

    // 5% of AED 240 is AED 12, so the total is AED 252.
    expect(app(CartPage::class)->serviceFee(24000))->toBe(1200);

    // And it follows the discount down rather than being clawed back off the
    // undiscounted figure.
    expect(app(CartPage::class)->serviceFee(20000))->toBe(1000);

    expect(squeezeGet(squeezeCart(2))->getContent())
        ->toContain(\App\Support\Money::format(25200));
});
// MUTATION: compute the percentage on the subtotal instead of the
// after-coupon total. GREEN against the two serviceFee() calls, which pass the
// figure in — the argument is the contract. RED is the caller: change the
// Blade to pass $totals['subtotal'] and the third assertion still passes too,
// because this basket has no coupon on it. So the REAL red is the unit pair
// above, which is why the fee is a method taking the after-coupon figure and
// not a lump of arithmetic in the view.

it('puts the shop\'s own free-delivery bar where the delivery row was', function () {
    // The delivery row ships off, so this is the default state of the page.
    squeezeOn();

    $cart = squeezeCart(2);                       // AED 240

    // A threshold this basket has NOT reached: the useful half — it answers the
    // delivery question and gives a reason to add another item.
    squeezeFreeOver(50000);

    $away = squeezeGet($cart)->assertOk()->getContent();

    // The shop's own element, its own classes, its own translated strings. No
    // new markup and no new keys for words already translated.
    expect($away)->toContain('<div class="ship">')
        ->and($away)->toContain('<div class="bar"><div class="fill"')
        // It is in the SUMMARY now, not at the top of the page.
        ->and(strpos($away, '<div class="ship">'))->toBeGreaterThan(strpos($away, 'Order Value'))
        // and there is exactly one of it: the copy at the top of the file is
        // switched off on this layout.
        ->and(substr_count($away, '<div class="ship">'))->toBe(1);

    /*
     * READ OUT OF THE BAR ITSELF, not out of the page.
     *
     * The first version of this asserted toContain('away from') against the
     * whole document and stayed GREEN when the not-yet branch was removed
     * altogether — the mini-cart drawer is rendered on every page of this shop
     * and carries the same sentence. An assertion that a second, unrelated
     * element can satisfy is an assertion that tests nothing.
     */
    $bar = substr($away, (int) strpos($away, '<div class="ship">'), 600);

    expect($bar)->toContain('away from')
        ->and($bar)->not->toContain('🎉');

});

it('shows the green congratulations once the order qualifies', function () {
    // A test of its own rather than a second render in the one above:
    // ShippingService memoises its zones for the life of the process, so
    // moving the threshold mid-test moves the database and not the answer —
    // and a test that passes for that reason is a test that proves nothing.
    squeezeOn();
    squeezeFreeOver(10000);

    $won = squeezeGet(squeezeCart(2))->assertOk()->getContent();

    expect($won)->toContain('<div class="ship">');

    $bar = substr($won, (int) strpos($won, '<div class="ship">'), 600);

    expect($bar)->toContain('🎉')
        ->and($bar)->not->toContain('away from');
});
// MUTATION: render only the @else (unlocked) branch of the bar. GREEN here and
// RED in the test above, whose basket has not reached the threshold — which is
// why both halves are tested and not just the happy one.
// MUTATION: drop the `&& ! $kbbSq` from the @if around the bar at the top of
// cart-inner.blade.php. RED — substr_count becomes 2, which is the defect
// worth catching: two progress bars on one page, both correct, one of them
// nowhere near the figures it is about. Run and confirmed.
//
// MUTATION TRIED AND GREEN: replacing the summary bar's `@if ($left > 0)` with
// `@if (false)`, so only the congratulations branch can ever render. Green,
// because the original assertion looked for "away from" anywhere in the
// document and the mini-cart drawer — present on every page — says the same
// thing. The assertions now read the bar's own markup, and the same mutation
// is RED.

it('says nothing about VAT on the cart page at all', function () {
    squeezeOn(['sum_delivery_on' => true]);

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    // Inclusive, and the checkout already says so. A second place saying it is
    // a second place that has to stay true.
    expect($html)->not->toContain('include VAT')
        ->and($html)->not->toContain('All prices include');

    // And the switch that used to control it is gone rather than left behind
    // controlling nothing.
    expect(CartPage::SCHEMA)->not->toHaveKey('sum_vat_note');

    $onTabs = collect(CartPage::TABS)->flatMap(fn ($t) => $t[2])->all();
    expect($onTabs)->not->toContain('sum_vat_note');
});
// MUTATION: put 'sum_vat_note' back in SCHEMA and on the summary tab. RED on
// the last two assertions — which is the half that catches a deletion that
// half-happened.

it('keeps the long checkout label from pushing the total off a narrow bar', function () {
    expect(app(CartPage::class)->get('co_label'))->toBe('Proceed to Checkout');

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // The button gives way first. min-width:0 is the load-bearing half: a flex
    // item's default min-width is auto, which is what makes "it should shrink"
    // quietly not work.
    expect($css)->toContain('flex:0 1 auto;min-width:0;max-width:100%;white-space:nowrap;overflow:hidden;')
        ->and($css)->toContain('text-overflow:ellipsis;text-align:center;');
});
// MUTATION: drop `min-width:0` from the button rule. RED.

it('ships every charge row switched off', function () {
    $c = app(CartPage::class)->all();

    expect($c['sum_express_on'])->toBeFalse()
        ->and($c['sum_delivery_on'])->toBeFalse()
        ->and($c['sum_service_on'])->toBeFalse()
        ->and($c['sum_service_mode'])->toBe('fixed');

    // And with all three off the total is the subtotal less any discount,
    // exactly — the same figure the checkout will charge.
    squeezeOn();
    expect(app(CartPage::class)->serviceFee(24000))->toBe(0);
});

/* ------------------------------------------------------------------------
 | 4. The recommended rail
 |------------------------------------------------------------------------*/

it('hides the whole rail while no products have been chosen', function () {
    squeezeOn();

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    // An empty horizontal scroller under a heading is worse than no section.
    // The class names appear in the stylesheet whatever happens, so this looks
    // for the MARKUP: the rail element itself and the heading above it.
    expect($html)->not->toContain('<div class="cpg-rail">')
        ->and($html)->not->toContain('>Recommended for you<');
});

it('fills the rail with the chosen products, in the chosen order', function () {
    $first = Product::create(['slug' => 'rec-a-'.Str::random(6), 'name' => 'Rice Toner',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock']);
    $second = Product::create(['slug' => 'rec-b-'.Str::random(6), 'name' => 'Snail Essence',
        'status' => 'publish', 'is_visible' => true, 'price' => 7000, 'stock_status' => 'instock']);

    squeezeOn(['rec_ids' => $second->id.','.$first->id]);

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    expect($html)->toContain('>Recommended for you<')
        ->and(strpos($html, 'Snail Essence'))->toBeLessThan(strpos($html, 'Rice Toner'));
});
// MUTATION: drop the reorder in CartPage::recommended() and return the whereIn
// result. RED — the database returns them by id, so Rice Toner comes first.

it('drops a product from the rail once it stops being published', function () {
    $live = Product::create(['slug' => 'rec-live-'.Str::random(6), 'name' => 'Live One',
        'status' => 'publish', 'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);
    $gone = Product::create(['slug' => 'rec-gone-'.Str::random(6), 'name' => 'Draft One',
        'status' => 'draft', 'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);

    squeezeOn(['rec_ids' => $live->id.','.$gone->id]);

    expect(app(CartPage::class)->recommended()->pluck('id')->all())->toBe([$live->id]);
});
// MUTATION: remove the ->where('status', 'publish') filter. RED — a rail of
// links to a 404 with a picture on it.

it('refuses more products than the rail holds, and drops duplicates', function () {
    $ids = implode(',', array_merge(range(1, CartPage::MAX_REC + 8), [3, 3, 3]));

    app(CartPage::class)->save(['rec_ids' => $ids]);

    expect(count(app(CartPage::class)->recommendedIds()))->toBe(CartPage::MAX_REC)
        ->and(array_unique(app(CartPage::class)->recommendedIds()))
        ->toHaveCount(CartPage::MAX_REC);
});
// MUTATION: drop the array_slice in castIds(). RED — a paste of the catalogue
// becomes four hundred cards in a horizontal scroller.

/* ------------------------------------------------------------------------
 | 5. The coupon box moves; it is not duplicated
 |------------------------------------------------------------------------*/

it('renders exactly one coupon field, under the rail, on the squeezed layout', function () {
    app(SettingsService::class)->setModule('cart_coupon_field', true);
    squeezeOn();

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    // One id, once. cart.js reads the value by id, so two would be one of them
    // silently doing nothing.
    expect(substr_count($html, 'id="kbbCartCoupon"'))->toBe(1);

    // And it sits ABOVE the summary rather than inside it.
    expect(strpos($html, 'id="kbbCartCoupon"'))->toBeLessThan(strpos($html, 'Order Value'));
});
// MUTATION: drop the `&& ! $kbbSq` from the summary's coupon @if. RED — the
// count becomes 2.

/* ------------------------------------------------------------------------
 | 6. The sheet is not inside the scrolling page
 |------------------------------------------------------------------------*/

it('puts the address sheet outside the cart page wrapper, and fixes it to the screen', function () {
    squeezeOn();

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    $sheet = strpos($html, 'id="cpgSheet"');
    $inner = strpos($html, 'id="cartInner"');

    expect($sheet)->not->toBeFalse()->and($inner)->not->toBeFalse();

    /*
     * THE BUG THIS PINS, and it is invisible until somebody opens the sheet at
     * the bottom of a long cart. A bottom sheet positioned against `bottom:0`
     * inside a SCROLLING ancestor resolves against that ancestor's content box
     * and not the part of it you can see, so it parks itself at the bottom of
     * all the content and the docked bars paint over it — the trust row comes
     * up through the middle of the form. No z-index fixes that; it is a
     * containing-block problem wearing a stacking-order costume.
     *
     * So: the sheet must come after #cartPage closes, not inside it. Counting
     * the closing tag is crude and it is exactly what is wrong when it is
     * wrong — the marker moves back inside the wrapper.
     */
    /*
     * PARSED, not counted. An earlier version of this counted opening and
     * closing tags between the two markers and went red the day a legitimate
     * wrapper was added around the sheet — a false alarm on a correct page is
     * how a guard stops being believed. The DOM answers the actual question:
     * is the sheet a DESCENDANT of the cart page?
     */
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $node = $dom->getElementById('cpgSheet');
    expect($node)->not->toBeNull();

    for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
        expect($p instanceof DOMElement ? $p->getAttribute('id') : '')->not->toBe(
            'cartPage',
            'The address sheet is nested inside the cart page wrapper. A bottom sheet inside a '
            .'scrolling box resolves bottom:0 against the CONTENT box, not the part of it you can '
            .'see, so it parks at the bottom of all the content and the docked bars paint over it '
            .'— which is what put the trust row through the middle of the open sheet.'
        );
    }

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    expect($css)->toContain('.cpg-sheet{position:fixed')
        ->and($css)->toContain('.cpg-scrim{position:fixed')
        ->and($css)->toContain('.cpg-x{position:fixed');
});
// MUTATION: move the sheet block back inside the .kbb-cartpage div in
// cart.blade.php. RED — the open/close tag count between the wrapper and the
// sheet no longer balances.
//
// MUTATION TRIED AND GREEN: changing .cpg-sheet to `position:absolute` alone —
// the tag-balance half still passes. The three position:fixed assertions are
// what catch it, and they are red.

it('closes the sheet off the screen entirely rather than sliding it out of sight', function () {
    squeezeOn();

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    // It ships hidden, so nothing of it is in the tab order before it is asked
    // for, and it is emptied on close so a saved address is not left in the
    // document.
    expect($html)->toMatch('/id="cpgSheet"[^>]*\shidden/');

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    expect($css)->toContain('.cpg-sheet[hidden]{display:none}')
        ->and($css)->toContain('sheet.hidden = true;')
        ->and($css)->toContain("sheet.innerHTML = '';")
        // One frame between unhiding and the open class, or there is no start
        // value to animate from and the sheet appears instead of sliding.
        ->and($css)->toContain("requestAnimationFrame(function () { sheet.classList.add('on'); });")
        // The hide timer is cancelled on reopen, or a fast close-then-open
        // hides the sheet that was just opened.
        ->and($css)->toContain('clearTimeout(hideTimer);');
});
// MUTATION: drop the `hidden` attribute from the markup. RED on the toMatch.

it('opens the country list upward, and does not use a native select', function () {
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // Upward, because a list dropping down from a field this near the bottom
    // lands under the docked bars.
    expect($css)->toContain('.cpg-cmenu{position:absolute;inset-inline:0;bottom:calc(100% + 5px)')
        // A native <select> gives the page no say in which way it opens, so
        // this is a button plus a listbox — and therefore owes the keyboard.
        ->and($css)->toContain('aria-haspopup="listbox"')
        ->and($css)->toContain("role=\"listbox\"")
        ->and($css)->toContain("e.key === 'ArrowDown'")
        ->and($css)->toContain("e.key === 'Enter'");
});
// MUTATION: change `bottom:calc(100% + 5px)` to `top:calc(100% + 5px)`. RED.

/* ------------------------------------------------------------------------
 | 7. The address endpoints
 |------------------------------------------------------------------------*/

it('answers 404 on every address endpoint while the classic layout is on', function () {
    squeezeRoutes();

    // The controller gates in its constructor, so the writes are covered too —
    // a gate on the listing alone is the shape of bug this app keeps finding.
    test()->getJson('/cart/address')->assertNotFound();
    test()->postJson('/cart/address', ['area' => 'Al Quoz'])->assertNotFound();
});

it('keeps a signed-out shopper out of the database', function () {
    squeezeOn();
    squeezeRoutes();

    test()->postJson('/cart/address', [
        'area' => 'Jumeirah Village Circle',
        'apartment' => 'Flat 802',
        'city' => 'Dubai',
        'country' => 'AE',
        'tag' => 'home',
    ])->assertOk()->assertJsonPath('chosen.tag', 'home');

    // No customer was manufactured and no address row was written.
    expect(Customer::count())->toBe(0)
        ->and(Address::count())->toBe(0);

    // The choice is in the session, so the docked row renders it.
    expect(session(CartAddressState::SESSION_KEY))->toBeArray();
});

it('404s, never 403s, on an address belonging to somebody else', function () {
    squeezeOn();
    squeezeRoutes();

    $mine = Customer::create(['email' => 'mine-'.Str::random(6).'@example.com', 'password' => bcrypt('x')]);
    $theirs = Customer::create(['email' => 'theirs-'.Str::random(6).'@example.com', 'password' => bcrypt('x')]);

    $stranger = $theirs->addresses()->create([
        'type' => 'shipping', 'line1' => 'Somewhere', 'city' => 'Dubai', 'country' => 'AE',
    ]);

    /*
     * 404 AND NOT 403, and the difference is the whole point. A 403 is a
     * confirmation that the row exists, so a stranger walking the ids learns
     * how many addresses this shop holds and which ones are live. A 404 tells
     * them nothing, and it is the same answer an id that was never issued
     * gets. Same reasoning as the note on QuizController::expertRequest.
     */
    test()->actingAs($mine, 'customer')
        ->postJson('/cart/address/'.$stranger->id.'/choose')
        ->assertNotFound();

    // An id that never existed answers identically.
    test()->actingAs($mine, 'customer')
        ->postJson('/cart/address/999999/choose')
        ->assertNotFound();
});
// MUTATION: resolve with Address::findOrFail($id) and abort(403) on a mismatch.
// RED on both — and the second assertion is the one that matters, because it is
// what makes the two answers indistinguishable.

it('never lists one customer an address belonging to another', function () {
    squeezeOn();
    squeezeRoutes();

    $mine = Customer::create(['email' => 'a-'.Str::random(6).'@example.com', 'password' => bcrypt('x')]);
    $theirs = Customer::create(['email' => 'b-'.Str::random(6).'@example.com', 'password' => bcrypt('x')]);

    $mine->addresses()->create(['type' => 'shipping', 'line1' => 'Mine', 'city' => 'Dubai', 'country' => 'AE']);
    $theirs->addresses()->create(['type' => 'shipping', 'line1' => 'Theirs', 'city' => 'Dubai', 'country' => 'AE']);

    $body = test()->actingAs($mine, 'customer')->getJson('/cart/address')->assertOk()->json();

    expect($body['addresses'])->toHaveCount(1)
        ->and($body['addresses'][0]['line'])->toContain('Mine')
        ->and(json_encode($body))->not->toContain('Theirs');
});
// MUTATION: list with Address::all() in CartAddressState::all(). RED.

it('offers the shop\'s own country list and defaults it from the shopper', function () {
    squeezeOn();
    squeezeRoutes();

    $body = test()->getJson('/cart/address')->assertOk()->json();

    // The SAME list the address book and the checkout validate against — a
    // second list here could offer a country the checkout then refuses.
    expect($body['countries'])->not->toBeEmpty()
        ->and(collect($body['countries'])->pluck('code')->all())
        ->toContain(...array_slice(array_keys(Countries::NAMES), 0, 3));

    expect($body['geo']['country'])->toHaveLength(2);
});

/* ------------------------------------------------------------------------
 | 8. The popup's own variables, and the two late controls
 |------------------------------------------------------------------------*/

it('puts the popup\'s sizes on the popup and not on the page it is no longer inside', function () {
    // The sheet was hoisted out of .kbb-cartpage to fix the stacking defect
    // above, so it inherits nothing from that element. Variables left there
    // would resolve to their fallbacks and every slider on the popup would
    // save, say it saved, and move nothing.
    squeezeOn(['sheet_max' => 65, 'sheet_blur' => 6, 'sheet_font' => 110]);

    $html = squeezeGet(squeezeCart())->assertOk()->getContent();

    $portal = strpos($html, 'class="cpg-portal');
    expect($portal)->not->toBeFalse();

    $attrs = substr($html, (int) $portal, 320);

    expect($attrs)->toContain('--cpg-sheet-max:65%')
        ->and($attrs)->toContain('--cpg-sheet-blur:6px')
        ->and($attrs)->toContain('--cpg-sheet-f:1.10');

    // And they are NOT on the cart page wrapper, where nothing would read them.
    expect(app(CartPage::class)->cssVariables())->not->toContain('--cpg-sheet-max');
});
// MUTATION: move the five sheet variables back into cssVariables() and drop
// sheetAttrs(). RED on all four — and this is the mutation worth having,
// because the page still renders and every slider still saves.

it('pairs City and Country only when the shop asks, and only upright', function () {
    squeezeOn();
    expect(squeezeGet(squeezeCart())->getContent())->not->toContain('cpg-portal cpg-twoup');

    squeezeOn(['sheet_two_up' => true]);
    expect(squeezeGet(squeezeCart())->getContent())->toContain('cpg-portal cpg-twoup');

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // Scoped to portrait. Landscape already pairs every field, and a second
    // rule there would put Area beside Apartment.
    expect($css)->toContain('@media (orientation:portrait){')
        ->and($css)->toContain('.cpg-portal.cpg-twoup .cpg-fields{grid-template-columns:1fr 1fr')
        ->and($css)->toContain('.cpg-portal.cpg-twoup .cpg-fields > .full{grid-column:1 / -1}');
});
// MUTATION: drop the @media (orientation:portrait) wrapper. RED.

it('fades a long chosen address off the right rather than cutting it with an ellipsis', function () {
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    // Both lines of the docked row, one line each, masked.
    expect($css)->toContain('.kbb-cartpage.cpg-squeeze .cpg-addrbar .who b,')
        ->and($css)->toContain('-webkit-mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent);')
        ->and($css)->toContain('mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent)')
        // The prefixed one first, so a browser that has both takes the standard.
        ->and(strpos($css, '-webkit-mask-image:linear-gradient'))
        ->toBeLessThan(strpos($css, "\n  mask-image:linear-gradient"))
        // No ellipsis left on that row: a "…" invites a tap to see the rest,
        // and there is nothing here to show.
        ->and($css)->not->toContain('.cpg-addrbar .who b{display:block;font-weight:500;color:var(--ink-2);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}');
});
// MUTATION: swap the mask rules back for text-overflow:ellipsis. RED on the
// first mask assertion.

it('animates the free-delivery bar and lets its bloom out of the track', function () {
    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));

    /*
     * THE ONE THAT SILENTLY KILLS IT. kbb-cart.css gives `.bar` overflow:hidden,
     * which is right for a flat fill and flattens this entirely — the bloom's
     * whole job is to spill past the track. Pinned from BOTH ends: the rule
     * that ships in the stylesheet, and the override here, so that if either
     * moves the other is known to be stale rather than quietly wrong.
     */
    $base = (string) preg_replace('#/\*.*?\*/#s', '',
        (string) file_get_contents(base_path('resources/css/kbb/kbb-cart.css')));

    expect($base)->toContain('.kbb-cartpage .bar{height:7px;border-radius:6px;background:var(--pink-soft);overflow:hidden}');

    expect($css)->toContain('.sum .ship .bar{overflow:visible}')
        // and the rounding moves onto the fill, which is what it was for.
        ->and($css)->toContain('.sum .ship .fill{position:relative;border-radius:6px;')
        // Three animations: the flow, the breathing bloom, the turning petals.
        ->and($css)->toContain('animation:cpgflow 2.6s linear infinite')
        ->and($css)->toContain('animation:cpgbloom 1.9s ease-in-out infinite')
        ->and($css)->toContain('animation:cpgpetal 4.2s linear infinite')
        // Both ride the leading edge, and every keyframe keeps the translate —
        // a transform that dropped it would snap the bloom back to the corner
        // the moment its animation took over.
        ->and($css)->toContain('position:absolute;top:50%;right:0;pointer-events:none')
        ->and($css)->toContain('0%,100%{transform:translate(50%,-50%) scale(.85)}')
        ->and($css)->toContain('from{transform:translate(50%,-50%) rotate(0deg)}')
        // No image and no extra request: four petals out of one clip-path.
        ->and($css)->toContain('clip-path:polygon(50% 0%,62% 38%,100% 50%,62% 62%,50% 100%,38% 62%,0% 50%,38% 38%)')
        /*
         * Stopped, not slowed, and all three of them.
         *
         * MATCHED AS ONE STRING — the media query together with the selector
         * list it opens. An earlier version asserted the two separately and
         * stayed GREEN when the query was widened to `@media all`: this file
         * carries several reduced-motion blocks and the bare query went on
         * matching one of the others. Written out in full, the widening is red.
         */
        ->and($css)->toContain(
            "@media (prefers-reduced-motion:reduce){\n"
            .'  .kbb-cartpage.cpg-squeeze .sum .ship .fill,'
        )
        ->and($css)->toContain('.sum .ship .fill::after{animation:none}')
        ->and($css)->toContain('.sum .ship .fill{background-position:0 0}}');
});
// MUTATION: change the override to `.sum .ship .bar{overflow:hidden}`. RED —
// and it is the mutation worth having, because the page still renders, the bar
// still fills, and the effect is simply gone.

it('does not bloom a bar that has not started', function () {
    squeezeOn();
    squeezeFreeOver(50000);

    // Nothing to bloom from at zero, and the shape would land outside the
    // track looking like a stray mark.
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $tiny = Product::create(['slug' => 'tiny-'.Str::random(6), 'name' => 'Sample',
        'status' => 'publish', 'is_visible' => true, 'price' => 0, 'stock_status' => 'instock']);
    $cart->items()->create(['product_id' => $tiny->id, 'quantity' => 1, 'unit_price' => 0]);

    $html = squeezeGet($cart)->assertOk()->getContent();

    expect($html)->toContain('class="fill cpg-flat" style="width:0%"');

    $css = (string) file_get_contents(resource_path('views/store/cart-squeeze.blade.php'));
    expect($css)->toContain('.fill.cpg-flat::after{display:none}');
});
// MUTATION: emit `class="fill"` unconditionally. RED on the first assertion.
