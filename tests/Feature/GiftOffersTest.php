<?php

declare(strict_types=1);

/**
 * Marketing -> Gift offers (Lane GO). The owner, 1-3 November 2026:
 * docs/plans/GIFT-OFFERS.md. One extra full-size gift at AED 0, by cart-value
 * range, in the abandoned-cart email and both pending-order reminders (ON),
 * and the exit popup (built, OFF). Each case says what the defect would look
 * like on the shop and carries a mutation note: change the named line back
 * and the case goes red.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\GiftOffers\GiftClock;
use App\Services\GiftOffers\GiftOffers;
use App\Services\GiftOffers\GiftOfferStats;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
    gfoSet([]);
});

function gfoFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
    app(CartService::class)->forget();
}

function gfoSet(array $config): void
{
    // Live unless a test says otherwise: 2.60.640 ships the module in Testing
    // (the owner's Off / Testing / Live), and these cases are about what Live does.
    app(SettingsService::class)->set(GiftOffers::KEY, $config + ['mode' => 'live']);
    gfoFresh();
}

function gfoProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 8900, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 20, 'image' => '/media/' . Str::slug($name) . '.webp',
    ], $o));
}

/** A range AED $min..$max with [[Product, role, allocation], ...]; returns [range id, item ids]. */
function gfoRange(int $min, ?int $max, array $items): array
{
    $id = DB::table('gift_offer_ranges')->insertGetId(['min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $ids = [];
    foreach ($items as $i => [$p, $role, $alloc]) {
        $ids[] = DB::table('gift_offer_items')->insertGetId(['range_id' => $id, 'product_id' => $p->id, 'role' => $role, 'sort' => $i, 'allocation' => $alloc, 'used' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(GiftOffers::class)->compile();
    gfoFresh();

    return [$id, $ids];
}

function gfoCart(array $lines): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    foreach ($lines as [$product, $qty]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (int) $product->price]);
    }

    return $cart->fresh();
}

function gfoShopper(?Cart $cart = null)
{
    app(CartService::class)->forget();
    $t = test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class);

    return $cart ? $t->withUnencryptedCookie(CartService::COOKIE, $cart->token) : $t;
}

function gfoAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GO ' . $role, 'email' => 'go-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function gfoBasketMail(int $unitPrice, ?array $offer): string
{
    return (new \App\Mail\CartRecoveryReminder('Your basket', 'Hi Layla', [['name' => 'Glow Serum', 'slug' => 'glow-serum', 'quantity' => 1, 'unit_price' => $unitPrice]], 'https://example.test/cart/', 'https://example.test/u', $offer))->render();
}

function gfoPendingOrder(int $subtotal = 40000): Order
{
    $o = Order::create(['order_number' => 'GO-' . Str::random(6), 'email' => 'go@example.test', 'status' => 'pending', 'total' => $subtotal, 'subtotal' => $subtotal,
        'currency' => 'AED', 'payment_method' => 'stripe', 'payment_method_title' => 'Card', 'shipping_address' => ['first_name' => 'Aisha', 'country' => 'AE', 'city' => 'Dubai']]);
    OrderItem::create(['order_id' => $o->id, 'name' => 'Glow Serum', 'quantity' => 1, 'unit_price' => $subtotal, 'subtotal' => $subtotal, 'total' => $subtotal]);

    return $o->fresh();
}

function gfoTokenOf(array $offer): string
{
    return (string) preg_replace('~^.*/gift-offer/c/~', '', $offer['href']);
}

/*
|------------------------------------------------------------------------------
| WHICH PRODUCT
|------------------------------------------------------------------------------
*/

it('offers the primary, then the next backup when the primary is out of stock or its allocation is spent, and nothing when all are out', function () {
    /*
     * The defect: a sold-out or used-up primary still promised in an email, or
     * a range with nothing left still drawing a block.
     * MUTATION: in GiftOffers::resolve() drop the `$i[3] > 0` filter -- the
     * spent primary is offered again. RED.
     */
    $toner = gfoProduct('Dokdo Toner');
    $sun = gfoProduct('Relief Sun');
    $ess = gfoProduct('Snail Essence');
    [, [$tonerItem]] = gfoRange(300, 499, [[$toner, 'primary', 50], [$sun, 'backup', 50], [$ess, 'backup', 50]]);

    $name = fn () => app(GiftOffers::class)->offerFor(40000)['product']->name ?? null;
    expect($name())->toBe('Dokdo Toner');

    DB::table('gift_offer_items')->where('id', $tonerItem)->update(['used' => 50]);
    app(GiftOffers::class)->compile();
    gfoFresh();
    expect($name())->toBe('Relief Sun');

    $sun->update(['stock' => 0, 'stock_status' => 'outofstock']);
    gfoFresh();
    expect($name())->toBe('Snail Essence');

    $ess->update(['status' => 'draft']);
    gfoFresh();
    expect(app(GiftOffers::class)->offerFor(40000))->toBeNull()
        // Below the range: nothing either.
        ->and(app(GiftOffers::class)->offerFor(20000))->toBeNull();
});

/*
|------------------------------------------------------------------------------
| THE EMAILS
|------------------------------------------------------------------------------
*/

it('puts the Golden ticket at the top of the abandoned-cart email only when a gift resolves', function () {
    /*
     * The defect: a gift block in an email with no gift behind it (module off,
     * place off, no range, nothing in stock) -- "No gift chosen (or none
     * available) = no box in the email, in any case."
     * MUTATION: in GiftOffers::issue() drop the placeOn() check. RED (place off).
     */
    $toner = gfoProduct('Dokdo Toner');
    gfoRange(100, null, [[$toner, 'primary', 50]]);

    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null);
    expect($offer)->not->toBeNull();
    $html = gfoBasketMail(40000, $offer);

    expect($html)->toContain('ADDITIONAL GIFT FOR YOU')->toContain('Wait… this is for you')
        ->toContain("Your bag is still here, and we&#039;ve added a full-size gift to go with it. Free, just for you.")
        ->toContain('Claim my free gift')->toContain('Worth AED 89 · one per order')->toContain('Dokdo Toner')
        ->and(strpos($html, 'ADDITIONAL GIFT FOR YOU'))->toBeLessThan(strpos($html, 'Glow Serum'))
        // Its dark-mode rules travel with it, and only with it.
        ->and($html)->toContain('.gt-card{background:#2B2026!important')
        ->and(gfoBasketMail(40000, null))->not->toContain('ADDITIONAL GIFT FOR YOU')->not->toContain('.gt-card');

    // Every way of having no gift: no row, no block.
    $before = DB::table('gift_offer_claims')->count();
    gfoSet(['places' => ['cart' => false]]);
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null))->toBeNull();
    gfoSet(['mode' => 'off']);
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null))->toBeNull();
    gfoSet([]);
    $toner->update(['stock' => 0, 'stock_status' => 'outofstock']);
    gfoFresh();
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null))->toBeNull()
        ->and(DB::table('gift_offer_claims')->count())->toBe($before);
});

it('puts the ticket at the top of "Your items" in both pending reminders, with the pay button wording', function () {
    $toner = gfoProduct('Dokdo Toner');
    gfoRange(100, null, [[$toner, 'primary', 50]]);
    $order = gfoPendingOrder();

    foreach ([1, 2] as $stage) {
        $offer = GiftOffers::forOrder($order, $stage);
        $html = (new \App\Mail\OrderPaymentReminder($order, $stage, $offer))->render();
        expect($offer['place'])->toBe('reminder_' . $stage)
            ->and($html)->toContain('ADDITIONAL GIFT FOR YOU')->toContain('Pay now &amp; claim my gift')
            ->toContain("Complete your order and we&#039;ll add a full-size gift to it. Free, just for you.")
            ->and(strpos($html, 'ADDITIONAL GIFT FOR YOU'))->toBeLessThan(strpos($html, 'Glow Serum'));
    }

    expect((new \App\Mail\OrderPaymentReminder($order, 1, null))->render())->not->toContain('ADDITIONAL GIFT FOR YOU');
});

it('keeps the green free gifts box in both emails, gift offer or not', function () {
    /*
     * The owner caught a preview without it (2 November): the free gifts box
     * must never go missing from either email.
     * MUTATION: delete the emails.kit.gift-box include from cart-recovery or
     * from kit/order. RED.
     */
    $mask = gfoProduct('Gel Mask', ['gift_kind' => 'mask']);
    $rid = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => '1 Mask', 'label_ar' => 'قناع', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $rid, 'product_id' => $mask->id, 'quantity' => 1, 'sort' => 0]);
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live']);
    gfoFresh();
    app(FreeGifts::class)->compile();
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);

    foreach ([true, false] as $withOffer) {
        $basket = gfoBasketMail(40000, $withOffer ? GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null) : null);
        expect($basket)->toContain('Free gifts for you')->toContain('Gel Mask');

        $order = gfoPendingOrder();
        OrderItem::create(['order_id' => $order->id, 'name' => 'Gel Mask', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
        $order->forceFill(['free_gift_count' => 1])->save();
        $pending = (new \App\Mail\OrderPaymentReminder($order->fresh(), 1, $withOffer ? GiftOffers::forOrder($order, 1) : null))->render();
        expect($pending)->toContain('border:1.5px solid #2e8f62')->toContain('Gel Mask');
    }
});

it('stays under Gmail\'s 102 KB clip with the gift, with images sized and described, and the unsubscribe header intact', function () {
    /*
     * The defect: Gmail cuts a message over 102 KB ("[Message clipped]") and the
     * claim button falls below the cut.
     * MUTATION: inline the GIF as a data: URI in emailView(). RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null);
    $mail = new \App\Mail\CartRecoveryReminder('Your basket', 'Hi', [['name' => 'Glow Serum', 'slug' => 'glow', 'quantity' => 1, 'unit_price' => 40000]], 'https://example.test/cart/', 'https://example.test/u', $offer);
    $html = $mail->render();
    $without = gfoBasketMail(40000, null);

    expect(strlen($html))->toBeLessThan(102 * 1024)
        ->and(strlen($html) - strlen($without))->toBeLessThan(9000)
        ->and($html)->toMatch('#<img src="[^"]+/gift-offer/t/[A-Za-z0-9_-]{43}\.gif" width="216" height="56" alt="Held until [^"]+"#')
        ->and($mail->headers()->text['List-Unsubscribe'])->toBe('<https://example.test/u>');

    $order = gfoPendingOrder();
    expect(strlen((new \App\Mail\OrderPaymentReminder($order, 1, GiftOffers::forOrder($order, 1)))->render()))->toBeLessThan(102 * 1024);

    // Fixed mode: the end time as text, no picture.
    gfoSet(['timer' => 'fixed']);
    $fixed = gfoBasketMail(40000, GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    expect($fixed)->not->toContain('/gift-offer/t/')->toMatch('#Held until \d{1,2}:\d{2} (AM|PM) (today|tomorrow)#');
});

it('speaks Arabic in the ticket for an Arabic shopper', function () {
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    gfoFresh();
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $html = \App\Support\CustomerLocale::render('ar', fn () => gfoBasketMail(40000, GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null)));

    expect($html)->toContain('هدية إضافية لكِ')->toContain('احصلي على هديتي المجانية')->not->toContain('ADDITIONAL GIFT FOR YOU');
});

it('fixes the dark-mode bag item names in every email, and leaves light mode alone', function () {
    /*
     * The owner's dark-mode screenshot: the bag's product names (links with an
     * inline dark colour) were unreadable. The kit's dark rules recoloured the
     * line, not the link inside it.
     * MUTATION: remove `.ink a{...}` from emails/kit/doc.blade.php. RED.
     */
    $html = gfoBasketMail(40000, null);
    preg_match('#@media \(prefers-color-scheme:dark\)\{(.*?)\n  \}#s', $html, $dark);

    expect($dark[1] ?? '')->toContain('.ink a{color:#F7EEF2!important}')
        ->and($html)->toContain('[data-ogsc] .ink a{color:#F7EEF2!important}')
        // The link itself is unchanged in light mode.
        ->and($html)->toContain('<a href="' . \App\Support\Url::external('/product/glow-serum/') . '" style="color:#2A2228;text-decoration:none;">');
});

/*
|------------------------------------------------------------------------------
| THE CLAIM
|------------------------------------------------------------------------------
*/

it('stores only the token\'s hash, and the link claims the gift without signing anyone in or restoring a basket', function () {
    /*
     * The defect: a bearer link that hands over a shopping session (see
     * CartRecoveryReminder's header) or a database that leaks claim tokens.
     * MUTATION: store `$token` instead of self::hash($token) in issue(). RED.
     */
    $customer = \App\Models\Customer::create(['email' => 'aisha@example.test', 'name' => 'Aisha', 'password' => 'password-long-enough']);
    $theirs = gfoCart([[gfoProduct('Their Serum', ['price' => 40000]), 1]]);
    $theirs->forceFill(['customer_id' => $customer->id])->save();
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], $theirs->id);
    $token = gfoTokenOf($offer);
    $row = DB::table('gift_offer_claims')->latest('id')->first();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(DB::table('gift_offer_claims')->get()))->not->toContain($token);

    // Opened on another device: a fresh empty basket gets the gift; nobody is signed in; their basket is untouched.
    $res = gfoShopper()->get('/gift-offer/c/' . $token);
    $res->assertRedirect();
    expect($res->headers->get('Location'))->toEndWith('/cart')
        ->and(auth('customer')->check())->toBeFalse()
        ->and(DB::table('carts')->where('id', $theirs->id)->value('gift_offer_claim_id'))->toBeNull();
    $mine = Cart::query()->where('gift_offer_claim_id', $row->id)->first();
    expect($mine)->not->toBeNull()->and($mine->id)->not->toBe($theirs->id)->and($mine->customer_id)->toBeNull()
        ->and($mine->items()->count())->toBe(0)
        ->and(DB::table('gift_offer_claims')->where('id', $row->id)->value('status'))->toBe('claimed');
});

it('answers an expired, a forged and an unknown link the same way, and the cart page says the gift has ended', function () {
    /*
     * MUTATION: drop the expiry comparison in GiftOffers::claim(). RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    DB::table('gift_offer_claims')->update(['expires_at' => now()->subMinute()]);

    $expired = gfoShopper()->get('/gift-offer/c/' . $token)->headers->get('Location');
    $unknown = gfoShopper()->get('/gift-offer/c/' . str_repeat('A', 43))->headers->get('Location');

    expect($expired)->toEndWith('/cart?gift=ended')->and($unknown)->toBe($expired)
        ->and(Cart::query()->whereNotNull('gift_offer_claim_id')->count())->toBe(0);

    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);
    expect(gfoShopper($cart)->get('/cart/?gift=ended')->getContent())->toContain('This gift has ended.');
});

/*
|------------------------------------------------------------------------------
| THE CART ROW AND PLACE ORDER
|------------------------------------------------------------------------------
*/

it('draws the R2 row only for a basket holding a live claim, and nothing at all otherwise', function () {
    /*
     * The defect: a byte of gift markup (or a script) on a page whose basket
     * has no gift, or a query to find out.
     * MUTATION: in GiftOffers::liveClaim() drop the `! $cart->gift_offer_claim_id`
     * early return -- the basket without a claim pays a query. RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);

    $page = gfoShopper($cart)->get('/cart/')->getContent();
    expect($page)->not->toContain('kgo-')->not->toContain('data-kgo');

    $totals = app(CartService::class)->totals($cart->fresh(), 'AE');
    app(CartService::class)->current(request(), false);
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(app(GiftOffers::class)->row($totals))->toBe([])->and(DB::getQueryLog())->toBe([]);

    $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    gfoShopper($cart)->get('/gift-offer/c/' . $token);
    gfoFresh();

    $page = gfoShopper($cart)->get('/cart/')->getContent();
    $drawer = gfoShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($page)->toContain('class="kgo-r" data-kgo-row')->toContain('Dokdo Toner')->toContain('Complete your order in')
        ->and(substr_count($page, 'w.kbbGoT=1') >= 1)->toBeTrue()
        ->and($drawer)->toContain('data-kgo-row');
});

it('writes the gift onto the order at AED 0, spends the allocation once, and leaves it out silently when the bag fell below the range', function () {
    /*
     * The defect: a gift given twice from one claim, an allocation that goes
     * below zero, or a gift kept by a bag that no longer qualifies.
     * MUTATION: drop `whereColumn('used', '<', 'allocation')` in attach(). RED
     * (the second order with allocation 1).
     */
    \App\Models\PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    $toner = gfoProduct('Dokdo Toner');
    [, [$item]] = gfoRange(300, null, [[$toner, 'primary', 1]]);

    $place = function (Cart $cart) {
        return gfoShopper($cart)->post('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
        ]);
    };
    $claimInto = function (Cart $cart) {
        $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
        gfoShopper($cart)->get('/gift-offer/c/' . $token);
        gfoFresh();
    };

    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);
    $claimInto($cart);
    $place($cart);
    $order = Order::query()->latest('id')->first();
    $line = $order->gifts()->first();

    expect($line)->not->toBeNull()
        ->and($line->name)->toBe('Dokdo Toner')->and((int) $line->total)->toBe(0)->and($line->gift_range_label)->toBe(GiftOffers::LINE_LABEL)
        ->and($order->items()->pluck('name')->all())->toBe(['Serum'])
        ->and((int) $order->free_gift_count)->toBe(1)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(1)
        ->and((int) $toner->fresh()->stock)->toBe(19);

    // A second basket: the allocation (1) is spent -- no gift, the order still goes through.
    gfoFresh();
    $cart2 = gfoCart([[gfoProduct('Serum 2', ['price' => 40000]), 1]]);
    DB::table('gift_offer_items')->where('id', $item)->update(['used' => 0, 'allocation' => 1]);
    app(GiftOffers::class)->compile();
    $claimInto($cart2);
    DB::table('gift_offer_items')->where('id', $item)->update(['used' => 1]);
    $place($cart2);
    $order2 = Order::query()->latest('id')->first();
    expect($order2->id)->not->toBe($order->id)->and($order2->gifts()->count())->toBe(0)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(1);

    // Below the range's minimum at Place order: left out.
    gfoFresh();
    DB::table('gift_offer_items')->where('id', $item)->update(['used' => 0, 'allocation' => 5]);
    app(GiftOffers::class)->compile();
    $cart3 = gfoCart([[gfoProduct('Serum 3', ['price' => 40000]), 1]]);
    $claimInto($cart3);
    $cart3->items()->update(['unit_price' => 10000]);
    $place($cart3);
    expect(Order::query()->latest('id')->first()->gifts()->count())->toBe(0)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(0);
});

it('adds the pending order\'s gift only when it is paid inside the window of a claimed reminder', function () {
    /*
     * The defect: a gift added to an order paid after the 8 hours, to one whose
     * reminder was never clicked, or by gateway code (payments are frozen).
     * MUTATION: in GiftOffers::orderPaid() drop the `$at->lessThan(...expires_at)`
     * test. RED (the late payment).
     */
    $toner = gfoProduct('Dokdo Toner');
    [, [$item]] = gfoRange(100, null, [[$toner, 'primary', 10]]);
    $pay = fn (Order $o) => app(\App\Services\Orders\OrderStatus::class)->moveTo($o, 'processing', by: 'system', also: ['paid_at' => now()], only: ['paid_at' => null]);

    // Claimed, paid in time: the gift line, allocation spent once.
    $order = gfoPendingOrder();
    $token = gfoTokenOf(GiftOffers::forOrder($order, 1));
    $to = gfoShopper()->get('/gift-offer/c/' . $token)->headers->get('Location');
    expect($to)->toContain('/checkout/order-pay')->toContain('gift=1')
        ->and(auth('customer')->check())->toBeFalse();
    // The pay page shows the gift's row while it is held, the ended note after.
    $pagePath = (string) parse_url($to, PHP_URL_PATH) . '?' . (string) parse_url($to, PHP_URL_QUERY);
    expect(gfoShopper()->get($pagePath)->assertOk()->getContent())->toContain('data-kgo-row')->toContain('Pay within the time and your free gift is added to this order.');
    $pay($order);
    expect($order->fresh()->gifts()->pluck('name')->all())->toBe(['Dokdo Toner'])
        ->and((int) $order->fresh()->free_gift_count)->toBe(1)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(1);

    // Never clicked: nothing.
    $quiet = gfoPendingOrder();
    GiftOffers::forOrder($quiet, 1);
    $pay($quiet);
    expect($quiet->fresh()->gifts()->count())->toBe(0);

    // Clicked, paid after the window: nothing.
    $late = gfoPendingOrder();
    $t = gfoTokenOf(GiftOffers::forOrder($late, 2));
    gfoShopper()->get('/gift-offer/c/' . $t);
    DB::table('gift_offer_claims')->where('order_id', $late->id)->update(['expires_at' => now()->subMinute()]);
    $pay($late);
    expect($late->fresh()->gifts()->count())->toBe(0)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(1);
});

/*
|------------------------------------------------------------------------------
| THE POPUP
|------------------------------------------------------------------------------
*/

it('prints nothing and loads no script while the popup is off, and costs no query to know it', function () {
    /*
     * The defect: popup markup or its script on every shop page while the
     * owner has it off ("keep turned off by default").
     * MUTATION: default PLACE_DEFAULTS['popup'] to true. RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);
    $drawer = gfoShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->not->toContain('data-kgo-pop')->not->toContain('gift-offer-popup');

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(app(GiftOffers::class)->popup(['item_count' => 1, 'subtotal' => 40000]))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);

    // On: the template and the watcher; the script itself only by URL, loaded on exit intent.
    gfoSet(['places' => ['popup' => true]]);
    $drawer = gfoShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->toContain('<template data-kgo-pop')->toContain('Psst… a secret gift, just for you')->toContain('WAIT…')
        ->toContain('kgo-dA')->toContain('kgo-S3')
        ->not->toContain('<script src=');
});

it('claims from the popup onto this basket with the popup\'s minutes, never twice', function () {
    gfoSet(['places' => ['popup' => true]]);
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);

    $res = gfoShopper($cart)->postJson('/gift-offer/popup');
    $res->assertOk()->assertJsonPath('ok', true);
    $claim = DB::table('gift_offer_claims')->where('place', 'popup')->first();
    expect((int) $cart->fresh()->gift_offer_claim_id)->toBe((int) $claim->id)
        ->and(abs(abs(\Carbon\CarbonImmutable::parse($claim->expires_at)->diffInMinutes(now())) - 15))->toBeLessThan(1.0);

    gfoShopper($cart)->postJson('/gift-offer/popup')->assertStatus(409);

    gfoSet(['places' => ['popup' => false]]);
    $other = gfoCart([[gfoProduct('Serum 2', ['price' => 40000]), 1]]);
    gfoShopper($other)->postJson('/gift-offer/popup')->assertStatus(409);

    // Never for someone signed in to the console.
    gfoSet(['places' => ['popup' => true]]);
    app(CartService::class)->forget();
    request()->setLaravelSession(app('session.store'));
    expect(app(GiftOffers::class)->popup(['item_count' => 1, 'subtotal' => 40000]))->not->toBeNull();
    $guard = \Illuminate\Support\Facades\Auth::guard('admin')->getName();
    request()->setLaravelSession(tap(app('session.store'))->put($guard, 1));
    expect(app(GiftOffers::class)->popup(['item_count' => 1, 'subtotal' => 40000]))->toBeNull();
});

/*
|------------------------------------------------------------------------------
| THE CLOCK, THE REPORT, THE ADMIN
|------------------------------------------------------------------------------
*/

it('draws the email clock as a small animated GIF, once per minute however many opens', function () {
    /*
     * MUTATION: drop the Cache::remember in GiftOfferController::clock(). The
     * renders count goes to 2. RED.
     */
    $gif = GiftClock::render(8 * 3600);
    expect(substr($gif, 0, 6))->toBe('GIF89a')->and(strlen($gif))->toBeLessThan(40 * 1024)
        ->and(substr_count($gif, "\x21\xF9\x04"))->toBe(60);

    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    \Illuminate\Support\Facades\Cache::flush();
    $a = test()->get('/gift-offer/t/' . $token . '.gif');
    $a->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($a->headers->get('Cache-Control'))->toContain('no-store');
    $keys = 0;
    $end = \Carbon\CarbonImmutable::parse(DB::table('gift_offer_claims')->value('expires_at'))->getTimestamp();
    expect(\Illuminate\Support\Facades\Cache::has('gift_offer_gif:' . $end . ':' . intdiv(time(), 60)))->toBeTrue();
    expect(test()->get('/gift-offer/t/' . $token . '.gif')->getContent())->toBe($a->getContent());

    expect(DB::table('gift_offer_stats')->where('event', 'open')->value('n'))->toEqual(2);
});

it('gives the screen its own capability and fails closed', function () {
    /*
     * MUTATION: map ['*', 'admin-api/gift-offers/**'] to giftoffers.view. RED.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/gift-offers'))->toBe('giftoffers.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/gift-offers/report'))->toBe('giftoffers.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/gift-offers/preview'))->toBe('giftoffers.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/gift-offers/settings'))->toBe('giftoffers.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/gift-offers/test'))->toBe('giftoffers.manage')
        ->and(AdminCapabilities::forPath('PATCH', 'admin-api/gift-offers/anything'))->toBe('giftoffers.manage')
        ->and(AdminCapabilities::roleCan('support', 'giftoffers.view'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('editor', 'giftoffers.manage'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('manager', 'giftoffers.manage'))->toBeTrue();

    test()->getJson('/admin-api/gift-offers')->assertStatus(401);
    test()->actingAs(gfoAdmin('support'), 'admin')->postJson('/admin-api/gift-offers/settings', ['config' => ['on' => true]])->assertForbidden();
    test()->actingAs(gfoAdmin('owner'), 'admin')->getJson('/admin-api/gift-offers')->assertOk()
        ->assertJsonPath('config.places.popup', false)->assertJsonPath('config.places.cart', true)
        ->assertJsonPath('config.hold_hours', 8)->assertJsonPath('config.popup_minutes', 15)->assertJsonPath('config.timer', 'live')
        ->assertJsonPath('config.popup.design', 'A')->assertJsonPath('config.popup.words', '3');
});

it('saves a range with a primary and backups, keeps what was given, and refuses two primaries', function () {
    $owner = gfoAdmin();
    $a = gfoProduct('Dokdo Toner');
    $b = gfoProduct('Relief Sun');
    $res = test()->actingAs($owner, 'admin')->postJson('/admin-api/gift-offers/ranges', ['min' => 150, 'max' => 299, 'enabled' => true, 'items' => [
        ['product_id' => $b->id, 'role' => 'backup', 'allocation' => 20], ['product_id' => $a->id, 'role' => 'primary', 'allocation' => 50],
    ]])->assertOk();
    $range = $res->json('ranges.0');
    expect(array_column($range['items'], 'role'))->toBe(['primary', 'backup'])
        ->and($range['items'][0]['product'])->toHaveKeys(['name', 'brand', 'thumb', 'stock', 'in_stock'])
        ->and($range['items'][0]['product'])->not->toHaveKeys(['wc_id', 'sku', 'total_sales']);

    DB::table('gift_offer_items')->where('product_id', $a->id)->update(['used' => 7]);
    test()->actingAs($owner, 'admin')->putJson('/admin-api/gift-offers/ranges/' . $range['id'], ['min' => 150, 'max' => null, 'items' => [
        ['product_id' => $a->id, 'role' => 'primary', 'allocation' => 60],
    ]])->assertOk();
    expect((int) DB::table('gift_offer_items')->where('product_id', $a->id)->value('used'))->toBe(7)
        ->and(DB::table('gift_offer_items')->where('product_id', $b->id)->exists())->toBeFalse();

    test()->actingAs($owner, 'admin')->putJson('/admin-api/gift-offers/ranges/' . $range['id'], ['min' => 150, 'max' => null, 'items' => [
        ['product_id' => $a->id, 'role' => 'primary', 'allocation' => 1], ['product_id' => $b->id, 'role' => 'primary', 'allocation' => 1],
    ]])->assertStatus(422);
});

it('previews the real email from unsaved values without storing anything, and a test send never counts', function () {
    Mail::fake();
    $owner = gfoAdmin();
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $claims = DB::table('gift_offer_claims')->count();

    $html = test()->actingAs($owner, 'admin')->postJson('/admin-api/gift-offers/preview', ['place' => 'cart', 'locale' => 'en',
        'config' => ['texts' => ['email_title_en' => 'Unsaved heading']]])->assertOk()->json('html');
    expect($html)->toContain('Unsaved heading')->toContain('data:image/gif;base64,')
        ->and(DB::table('gift_offer_claims')->count())->toBe($claims)
        ->and(app(GiftOffers::class)->freshConfig()['texts']['email_title_en'])->toBe('Wait… this is for you');

    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    gfoFresh();
    $pop = test()->actingAs($owner, 'admin')->postJson('/admin-api/gift-offers/preview', ['place' => 'popup', 'locale' => 'ar', 'config' => ['popup' => ['design' => 'D', 'style' => 'S4']]])->json('html');
    expect($pop)->toContain('kgo-dD')->toContain('data-kgo-mm')->toContain('dir="rtl"');

    test()->actingAs($owner, 'admin')->postJson('/admin-api/gift-offers/test', ['to' => 'owner@example.test', 'place' => 'cart', 'locale' => 'en']);
    expect(DB::table('gift_offer_claims')->where('is_test', true)->count())->toBe(1)
        ->and(DB::table('gift_offer_stats')->count())->toBe(0);
});

it('reports per day, place and range at a flat cost as the rows grow', function () {
    /*
     * MUTATION: query gift_offer_stats once per bucket instead of once. RED.
     */
    $count = function (int $days): int {
        DB::table('gift_offer_stats')->delete();
        for ($i = 0; $i < $days; $i++) {
            DB::table('gift_offer_stats')->insert(['day' => now()->subDays($i)->format('Y-m-d'), 'place' => 'cart', 'range_id' => 1, 'event' => 'sent', 'n' => 3]);
        }
        GiftOfferStats::report(now()->format('Y-m-d'), now()->format('Y-m-d'), 'day');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = GiftOfferStats::report(now()->subDays(60)->format('Y-m-d'), now()->format('Y-m-d'), 'week');
        expect($r['totals']['sent'])->toBe(3 * $days);

        return count(DB::getQueryLog());
    };

    expect($count(3))->toBe($count(40))->toBe(1);
});

/*
|------------------------------------------------------------------------------
| OFF / TESTING / LIVE (2.60.640, the owner, 4 November: "i need the same
| TEST / LIVE / OFF function, make sure it's working perfectly")
|------------------------------------------------------------------------------
*/

it('reads an old saved row as Off or Testing, never Live by itself, and keeps only real addresses on the list', function () {
    /*
     * The defect this guards: a package that turns the gift on for every
     * customer because a row from 2.60.639 had no `mode`.
     * MUTATION: make modeOf() default to 'live' -- RED.
     */
    expect(GiftOffers::normalise([])['mode'])->toBe('testing')
        ->and(GiftOffers::normalise(['on' => true])['mode'])->toBe('testing')
        ->and(GiftOffers::normalise(['on' => false])['mode'])->toBe('off')
        ->and(GiftOffers::normalise(['mode' => 'live'])['mode'])->toBe('live')
        ->and(GiftOffers::normalise(['mode' => 'everyone'])['mode'])->toBe('testing')
        ->and(GiftOffers::normalise(['testers' => [['ip' => '203.0.113.9', 'label' => 'Office'], ['ip' => 'nope'], ['ip' => '203.0.113.9'], 'x']])['testers'])
        ->toBe([['ip' => '203.0.113.9', 'label' => 'Office']]);
});

it('gives nothing anywhere when Off: no email box, no claim, no cart row', function () {
    /*
     * MUTATION: drop `$c['mode'] !== 'off'` from placeOn() -- the email gets the box. RED.
     * MUTATION: drop the visibleTo() check in liveClaim() -- the row stays. RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $cart = gfoCart([[gfoProduct('Serum', ['price' => 40000]), 1]]);
    $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    gfoShopper($cart)->get('/gift-offer/c/' . $token);
    gfoFresh();
    expect(gfoShopper($cart)->get('/cart/')->getContent())->toContain('data-kgo-row');

    gfoSet(['mode' => 'off']);
    $before = DB::table('gift_offer_claims')->count();
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], $cart->id))->toBeNull()
        ->and(GiftOffers::forOrder(gfoPendingOrder(), 1))->toBeNull()
        ->and(DB::table('gift_offer_claims')->count())->toBe($before)
        ->and(gfoShopper($cart)->get('/cart/')->getContent())->not->toContain('data-kgo-row');

    // A link sent while it was on says the gift has ended.
    gfoSet(['mode' => 'live']);
    $later = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    gfoSet(['mode' => 'off']);
    expect(gfoShopper()->get('/gift-offer/c/' . $later)->headers->get('Location'))->toEndWith('/cart?gift=ended');

    // A test send is a test send in every mode.
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null, true))->not->toBeNull();
});

it('in Testing gives the gift only to listed addresses: emails by the cart\'s or order\'s address, the shop by the browser\'s', function () {
    /*
     * The defect: Testing that leaks the gift to real customers, or one the
     * owner cannot see himself.
     * MUTATION: drop the Testing check in issue() -- a customer's email gets
     * the box. RED. Drop testerIp() from visibleTo() -- the row shows for
     * anybody. RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    gfoSet(['mode' => 'testing', 'testers' => [['ip' => '203.0.113.9', 'label' => 'Office']]]);
    $offers = app(GiftOffers::class);

    $customer = gfoCart([[gfoProduct('Serum A', ['price' => 40000]), 1]]);
    $customer->forceFill(['ct_ip' => '198.51.100.20'])->save();
    $tester = gfoCart([[gfoProduct('Serum B', ['price' => 40000]), 1]]);
    $tester->forceFill(['ct_ip' => '203.0.113.9'])->save();
    expect(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], $customer->id))->toBeNull()
        ->and(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], $tester->id))->not->toBeNull()
        ->and(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null))->toBeNull();

    $theirs = gfoPendingOrder();
    $theirs->forceFill(['ip_address' => '198.51.100.20'])->save();
    $ours = gfoPendingOrder();
    $ours->forceFill(['ip_address' => '203.0.113.9'])->save();
    expect(GiftOffers::forOrder($theirs, 1))->toBeNull()->and(GiftOffers::forOrder($ours, 1))->not->toBeNull();

    // The shop: by the request's own address (tests run as 127.0.0.1).
    $req = \Illuminate\Http\Request::create('/cart/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    expect($offers->visibleTo($req))->toBeTrue()
        ->and($offers->visibleTo(\Illuminate\Http\Request::create('/cart/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20'])))->toBeFalse()
        ->and($offers->visibleTo(null))->toBeFalse();

    // A claim opened from an address not on the list ends; from a listed one it works.
    gfoSet(['mode' => 'live']);
    $token = gfoTokenOf(GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null));
    gfoSet(['mode' => 'testing', 'testers' => [['ip' => '203.0.113.9', 'label' => '']]]);
    expect(gfoShopper()->get('/gift-offer/c/' . $token)->headers->get('Location'))->toEndWith('/cart?gift=ended');
    gfoSet(['mode' => 'testing', 'testers' => [['ip' => '127.0.0.1', 'label' => '']]]);
    expect(gfoShopper()->get('/gift-offer/c/' . $token)->headers->get('Location'))->toEndWith('/cart');
});

it('switches mode, the Testing list and each place at once, and the form\'s Save never moves them', function () {
    /*
     * The owner, 4 November: "the GIFT OFFER page on/off is not working". The
     * switch only changed a draft that waited for a Save at the foot of a long
     * form. Now each saves itself.
     * MUTATION: let settings() take `mode`/`places` from the posted draft --
     * the stale draft turns the place back on. RED.
     */
    $admin = gfoAdmin();
    $api = fn () => test()->actingAs($admin, 'admin');

    $res = $api()->postJson('/admin-api/gift-offers/mode', ['mode' => 'testing'])->assertOk();
    expect($res->json('config.mode'))->toBe('testing')->and($res->json('added_ip'))->toBe('127.0.0.1')
        ->and($res->json('config.testers'))->toBe([['ip' => '127.0.0.1', 'label' => 'My device']])->and($res->json('my_ip'))->toBe('127.0.0.1');

    $api()->postJson('/admin-api/gift-offers/mode', ['mode' => 'everyone'])->assertStatus(422);
    $api()->postJson('/admin-api/gift-offers/testers', ['testers' => [['ip' => 'not-an-ip']]])->assertStatus(422)->assertJsonFragment(['not-an-ip is not an IP address.']);
    $api()->postJson('/admin-api/gift-offers/testers', ['testers' => [['ip' => '203.0.113.9', 'label' => 'Office']]])->assertOk();
    $api()->postJson('/admin-api/gift-offers/place', ['place' => 'reminder_2', 'on' => false])->assertOk();
    $api()->postJson('/admin-api/gift-offers/mode', ['mode' => 'live'])->assertOk();

    // A draft opened before all that, saved after.
    $stale = GiftOffers::normalise(['mode' => 'off', 'testers' => [], 'hold_hours' => 9]);
    $cfg = $api()->postJson('/admin-api/gift-offers/settings', ['config' => $stale])->assertOk()->json('config');
    expect($cfg['mode'])->toBe('live')->and($cfg['testers'])->toBe([['ip' => '203.0.113.9', 'label' => 'Office']])
        ->and($cfg['places']['reminder_2'])->toBeFalse()->and($cfg['hold_hours'])->toBe(9);

    // Every one of them is a manage write: a support login is refused.
    foreach (['mode' => ['mode' => 'off'], 'testers' => ['testers' => []], 'place' => ['place' => 'cart', 'on' => false]] as $path => $body) {
        test()->actingAs(gfoAdmin('support'), 'admin')->postJson('/admin-api/gift-offers/' . $path, $body)->assertForbidden();
    }
});

it('puts "Free gift" at the front of the subject only when the email carries the gift', function () {
    /*
     * The owner, 4 November: "mention Free Gift in the title some how very
     * nicely and short".
     * MUTATION: return $subject unchanged from GiftOffers::subjectWith() -- RED.
     */
    gfoRange(100, null, [[gfoProduct('Dokdo Toner'), 'primary', 50]]);
    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null, true);
    $with = new \App\Mail\CartRecoveryReminder('Your basket is waiting', 'Hi', [['name' => 'Glow Serum', 'slug' => 'glow-serum', 'quantity' => 1, 'unit_price' => 40000]], 'https://example.test/cart/', 'https://example.test/u', $offer);
    $without = new \App\Mail\CartRecoveryReminder('Your basket is waiting', 'Hi', [['name' => 'Glow Serum', 'slug' => 'glow-serum', 'quantity' => 1, 'unit_price' => 40000]], 'https://example.test/cart/', 'https://example.test/u', null);

    expect($with->envelope()->subject)->toBe('🎁 A free gift inside · Your basket is waiting')
        ->and($without->envelope()->subject)->toBe('Your basket is waiting');

    $order = gfoPendingOrder();
    $plain = (new \App\Mail\OrderPaymentReminder($order, 1))->envelope()->subject;
    expect((new \App\Mail\OrderPaymentReminder($order, 1, GiftOffers::forOrder($order, 1, true)))->envelope()->subject)->toBe('🎁 A free gift inside · ' . $plain);

    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    gfoFresh();
    $ar = \App\Support\CustomerLocale::render('ar', fn () => GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null, true));
    expect(GiftOffers::subjectWith('x', $ar))->toBe('🎁 هدية مجانية بانتظاركِ · x');
});

it('marks the additional gift on the admin order screen so packing cannot miss it', function () {
    /*
     * MUTATION: drop 'offer' from AdminOrderController's gift rows, or the
     * gold row and the banner from the Items card -- RED.
     */
    $order = gfoPendingOrder();
    OrderItem::create(['order_id' => $order->id, 'name' => 'Dokdo Toner 200ml', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true, 'gift_range_label' => GiftOffers::LINE_LABEL]);
    OrderItem::create(['order_id' => $order->id, 'name' => 'Tea Tree Mask', 'quantity' => 2, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true, 'gift_range_label' => 'AED 100+']);
    $order->forceFill(['free_gift_count' => 3])->save();

    $gifts = test()->actingAs(gfoAdmin(), 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json('gifts');
    expect(collect($gifts)->where('offer', true)->pluck('name')->all())->toBe(['Dokdo Toner 200ml'])
        ->and(collect($gifts)->where('offer', false)->pluck('name')->all())->toBe(['Tea Tree Mask']);

    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($blade)->toContain('ADDITIONAL GIFT · PACK THIS TOO')->toContain('This order has an additional gift:');
});

it('draws the screen again when the owner comes back to it, without a refresh', function () {
    /*
     * The owner, 4 November: "when i go to any other page, and come back to
     * gift offer page. it's showing empty, on refresh it fixed." Re-entry
     * cleared the draft but kept the old data, so settings() read a null
     * config and threw; the page stayed blank. Chromium, four round trips
     * (Free gifts, Orders, Newsletter, Dashboard) now draw it every time.
     * MUTATION: drop `st.data = null;` from the screen's go() -- RED.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/gift-offers-screen.blade.php'));
    expect($screen)->toContain("st.drafts = {}; st.pick = null; st.msg = ''; st.data = null; st.cfg = null;");
});
