<?php

declare(strict_types=1);

/**
 * Marketing -> Gift offers -> "Gives per order ×N" (Lane GQ). The owner, 10
 * November 2026: "i need x2 in gift offers ... we need to give 2 pcs for this
 * product, and we don't have this option."
 *
 * One claim of a ×N item gives N units: ONE AED 0 line of quantity N, N units
 * of the allocation and N off the shelf. A ×N item that cannot give all N (by
 * allocation or by stock) is skipped for the next backup that can -- never cut
 * down. ×1, the default, is byte-for-byte what the shop drew before.
 *
 * Each case says what the defect would look like on the shop and carries a
 * mutation note: change the named line back and the case goes red.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\GiftOffers\GiftOffers;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
    gqSet([]);
});

function gqFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
    app(CartService::class)->forget();
}

function gqSet(array $config): void
{
    app(SettingsService::class)->set(GiftOffers::KEY, $config + ['mode' => 'live']);
    gqFresh();
}

function gqProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 4500, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 20, 'image' => '/media/' . Str::slug($name) . '.webp',
    ], $o));
}

/** A range from AED $min with [[Product, role, allocation, qty], ...]; returns [range id, item ids]. */
function gqRange(int $min, array $items): array
{
    $id = DB::table('gift_offer_ranges')->insertGetId(['min_fils' => $min * 100, 'max_fils' => null, 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $ids = [];
    foreach ($items as $i => [$p, $role, $alloc, $qty]) {
        $ids[] = DB::table('gift_offer_items')->insertGetId(['range_id' => $id, 'product_id' => $p->id, 'role' => $role, 'sort' => $i, 'allocation' => $alloc, 'qty' => $qty, 'used' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(GiftOffers::class)->compile();
    gqFresh();

    return [$id, $ids];
}

function gqCart(array $lines): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    foreach ($lines as [$product, $qty]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (int) $product->price]);
    }

    return $cart->fresh();
}

function gqShopper(?Cart $cart = null)
{
    app(CartService::class)->forget();
    $t = test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class);

    return $cart ? $t->withUnencryptedCookie(CartService::COOKIE, $cart->token) : $t;
}

function gqCod(): void
{
    \App\Models\PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}

function gqPlace(Cart $cart)
{
    return gqShopper($cart)->post('/checkout/place', [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ]);
}

/** Issue an abandoned-cart offer for a AED 400 basket and open its link in this cart's browser; returns the offer. */
function gqClaimInto(Cart $cart): ?array
{
    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null);
    if ($offer !== null) {
        gqShopper($cart)->get('/gift-offer/c/' . preg_replace('~^.*/gift-offer/c/~', '', $offer['href']));
    }
    gqFresh();

    return $offer;
}

function gqAdmin(): AdminUser
{
    return AdminUser::create(['name' => 'GQ owner', 'email' => 'gq-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
}

it('lands a ×2 gift on the order as ONE line of quantity 2 at AED 0, using 2 of the allocation and 2 off the shelf', function () {
    /*
     * The defect: the owner sets numbuzin's eye patches to give 2 pcs and the
     * order still carries one -- the packer packs one, the customer was
     * promised two -- or the allocation counts orders, not pieces, so 50
     * patches "last" 50 orders and the shop gives away 100.
     * MUTATION: in GiftOffers::attach() put `'quantity' => 1` back on the gift
     * line (or `increment('used')` without $qty) -- RED.
     */
    gqCod();
    $patches = gqProduct('numbuzin No.9 NAD+ Collagen Under Eye Patches', ['price' => 4500]);
    [, [$item]] = gqRange(300, [[$patches, 'primary', 50, 2]]);

    $offer = gqClaimInto($cart = gqCart([[gqProduct('Serum', ['price' => 40000]), 1]]));
    expect($offer['chips'][2])->toBe('×2')
        // "Worth" is one unit's price x the units given.
        ->and($offer['worth'])->toContain('90');

    gqPlace($cart)->assertRedirect();
    $order = Order::query()->latest('id')->first();
    $line = $order->gifts()->first();

    expect($line)->not->toBeNull()
        ->and((int) $line->quantity)->toBe(2)
        ->and((int) $line->unit_price)->toBe(0)->and((int) $line->total)->toBe(0)
        ->and($line->gift_range_label)->toBe(GiftOffers::LINE_LABEL)
        ->and($order->gifts()->count())->toBe(1)
        ->and((int) $order->free_gift_count)->toBe(2)
        ->and((int) DB::table('gift_offer_items')->where('id', $item)->value('used'))->toBe(2)
        ->and((int) $patches->fresh()->stock)->toBe(18)
        // What the shopper pays is exactly the bought line.
        ->and((int) $order->items()->sum('total'))->toBe(40000);
});

it('skips a ×2 item with only 1 unit of allocation or 1 on the shelf for the next backup, and never cuts a claim down to 1', function () {
    /*
     * The defect: a ×2 primary with one patch left gives ONE patch (a short
     * gift he never promised), or offers nothing while a backup could give.
     * MUTATION: in GiftOffers::resolve() drop `&& $i[3] >= self::qtyOf(...)`
     * from the filter -- the primary with 1 left is offered. RED. And in
     * stillGiveable() compare `< 1` again -- the ×2 claim made before the
     * allocation fell to 1 is attached and the UPDATE refuses, so the
     * conditional `used + ? <= allocation` is the second wall. RED.
     */
    gqCod();
    $patches = gqProduct('Eye Patches', ['price' => 4500]);
    $toner = gqProduct('Dokdo Toner', ['price' => 8900]);
    [$rangeId, [$pItem, $tItem]] = gqRange(300, [[$patches, 'primary', 50, 2], [$toner, 'backup', 50, 1]]);
    $giving = fn () => app(GiftOffers::class)->offerFor(40000);

    expect($giving()['product']->name)->toBe('Eye Patches')->and($giving()['qty'])->toBe(2);

    // Allocation: 1 unit left of a ×2 item -> the backup.
    DB::table('gift_offer_items')->where('id', $pItem)->update(['used' => 49]);
    app(GiftOffers::class)->compile();
    gqFresh();
    expect($giving()['product']->name)->toBe('Dokdo Toner')->and($giving()['qty'])->toBe(1);

    // Stock: allocation back, but one patch on the shelf -> the backup.
    DB::table('gift_offer_items')->where('id', $pItem)->update(['used' => 0]);
    app(GiftOffers::class)->compile();
    $patches->forceFill(['stock' => 1])->save();
    gqFresh();
    expect($giving()['product']->name)->toBe('Dokdo Toner');

    // A ×2 claim made while two were left, then the allocation falls to 1:
    // the gift is left out whole, the order goes through, nothing is spent.
    $patches->forceFill(['stock' => 20])->save();
    gqFresh();
    $cart = gqCart([[gqProduct('Serum', ['price' => 40000]), 1]]);
    expect(gqClaimInto($cart)['name'])->toBe('Eye Patches');
    DB::table('gift_offer_items')->where('id', $pItem)->update(['used' => 49]);
    gqPlace($cart)->assertRedirect();
    $order = Order::query()->latest('id')->first();
    expect($order->gifts()->count())->toBe(0)
        ->and((int) DB::table('gift_offer_items')->where('id', $pItem)->value('used'))->toBe(49)
        ->and((int) $patches->fresh()->stock)->toBe(20);

    // And attach() itself refuses a ×2 claim with one unit left (the UPDATE's own wall).
    $claim = (object) ['id' => 0, 'item_id' => $pItem, 'product_id' => $patches->id, 'variant_id' => null, 'is_test' => false, 'qty' => 2, 'locale' => 'en', 'place' => 'cart', 'range_id' => $rangeId];
    $units = DB::transaction(fn () => app(GiftOffers::class)->attach($order, $claim));
    expect($units)->toBe(0)->and((int) DB::table('gift_offer_items')->where('id', $pItem)->value('used'))->toBe(49);
});

it('draws ×1 exactly as before: the cart row, the email ticket and every popup style are byte-identical with or without the new key', function () {
    /*
     * The defect: an owner who never touches "Gives per order" sees his cart
     * row, emails or popup change by a byte (StorefrontEnglishUnchangedTest
     * covers the pages; this pins the gift partials themselves, which that
     * test never draws because no basket there holds a claim).
     * MUTATION: print `×{{ $goRow['qty'] }} pcs` in row.blade.php, or add the
     * lone chip in popup-card.blade.php without `$goQty > 1` -- RED.
     */
    $row = ['name' => 'Dokdo Toner', 'img' => '', 'left' => 600, 'total' => 900, 'below' => '', 'label' => 'Complete your order in', 'free' => 'Free', 'full' => 'Full size', 'over' => 'Over'];
    $without = view('partials.gift-offers.row', ['goRow' => $row])->render();
    $with1 = view('partials.gift-offers.row', ['goRow' => $row + ['qty' => 1]])->render();
    $with2 = view('partials.gift-offers.row', ['goRow' => $row + ['qty' => 2]])->render();
    expect($with1)->toBe($without)->toContain('<i>Full size</i><i>×1</i>')
        ->and($with2)->toBe(str_replace('<i>×1</i>', '<i>×2</i>', $without));

    $toner = gqProduct('Dokdo Toner', ['price' => 8900]);
    $offer = ['range' => ['id' => 1, 'min' => 0, 'max' => null, 'items' => []], 'item_id' => 1, 'product' => $toner, 'variant' => null, 'product_id' => $toner->id, 'variant_id' => null, 'value' => 8900];
    $go = app(GiftOffers::class);
    $exp = \Carbon\CarbonImmutable::now()->addHours(8);
    expect($go->emailView($offer, GiftOffers::previewToken(), 'cart', $exp)['chips'])->toBe(['FREE', 'FULL SIZE', '×1'])
        ->and($go->emailView($offer + ['qty' => 1], GiftOffers::previewToken(), 'cart', $exp)['chips'][2])->toBe('×1');

    foreach (['A', 'B', 'C', 'D'] as $design) {
        foreach (GiftOffers::STYLES as $style) {
            gqSet(['popup' => ['design' => $design, 'style' => $style]]);
            $p = $go->popupView($offer);
            unset($p['qty']);
            $old = view('partials.gift-offers.popup-card', ['p' => $p])->render();
            $new1 = view('partials.gift-offers.popup-card', ['p' => $p + ['qty' => 1]])->render();
            $new2 = view('partials.gift-offers.popup-card', ['p' => $p + ['qty' => 2]])->render();
            expect($new1)->toBe($old)->and($new2)->toContain('<span class="kgo-c3">×2</span>')->not->toContain('×1<');
        }
    }
});

it('normalises "Gives per order" on save: one of ×1..×5 or the default ×1, never refused, never out of range', function () {
    /*
     * The defect: a doctored request stores ×50 (fifty patches per order) or
     * ×0 (a gift line of nothing), or a bad value refuses the whole range save.
     * MUTATION: store `(int) $item['qty']` in GiftOffersController::saveRange()
     * instead of GiftOffers::qtyOf() -- RED.
     */
    $owner = gqAdmin();
    $products = array_map(fn ($n) => gqProduct('P' . $n), range(1, 7));
    $sent = [9, 'abc', 0, '3', -1, 5, ['x' => 1]];
    $items = [];
    foreach ($products as $i => $p) {
        $items[] = ['product_id' => $p->id, 'role' => $i === 0 ? 'primary' : 'backup', 'allocation' => 10, 'qty' => $sent[$i]];
    }
    $range = test()->actingAs($owner, 'admin')->postJson('/admin-api/gift-offers/ranges', ['min' => 100, 'max' => null, 'enabled' => true, 'items' => $items])
        ->assertOk()->json('ranges.0');

    expect(array_column($range['items'], 'qty'))->toBe([1, 1, 1, 3, 1, 5, 1])
        ->and(DB::table('gift_offer_items')->orderBy('sort')->pluck('qty')->map(fn ($q) => (int) $q)->all())->toBe([1, 1, 1, 3, 1, 5, 1]);

    // An item saved without the key (the screen before this lane) is ×1.
    test()->actingAs($owner, 'admin')->putJson('/admin-api/gift-offers/ranges/' . $range['id'], ['min' => 100, 'max' => null, 'items' => [
        ['product_id' => $products[3]->id, 'role' => 'primary', 'allocation' => 10],
    ]])->assertOk();
    expect((int) DB::table('gift_offer_items')->where('product_id', $products[3]->id)->value('qty'))->toBe(1);

    // The admin screen offers exactly those choices, next to "For this offer".
    $screen = (string) file_get_contents(resource_path('views/admin/partials/gift-offers-screen.blade.php'));
    expect($screen)->toContain('Gives per order <select')->toContain("qty: qtyOf(it.qty)");
});

it('prints ×2 on the cart row, the abandoned-cart ticket, the order email, the invoice and the packing slip', function () {
    /*
     * The defect: the order is right but a page or a document still reads ×1
     * (or 1) -- the customer thinks she gets one, the packer packs one.
     * MUTATION: drop 'qty' from GiftOffers::rowView() -- the cart page reads
     * ×1. RED. Drop '×' . qtyOf(...) from emailView() -- the ticket reads ×1. RED.
     */
    gqCod();
    $patches = gqProduct('numbuzin Eye Patches', ['price' => 4500]);
    gqRange(300, [[$patches, 'primary', 50, 2]]);
    $cart = gqCart([[gqProduct('Serum', ['price' => 40000]), 1]]);
    $offer = gqClaimInto($cart);

    $mail = (new \App\Mail\CartRecoveryReminder('Your basket', 'Hi Layla', [['name' => 'Serum', 'slug' => 'serum', 'quantity' => 1, 'unit_price' => 40000]], 'https://example.test/cart/', 'https://example.test/u', $offer))->render();
    expect($mail)->toContain('>×2</span>')->not->toContain('>×1</span>');

    $page = gqShopper($cart)->get('/cart/')->getContent();
    expect($page)->toContain('<i>×2</i>')->not->toContain('<i>×1</i>');
    expect(gqShopper($cart)->getJson('/api/cart/drawer')->json('html'))->toContain('<i>×2</i>');

    gqPlace($cart)->assertRedirect();
    $order = Order::query()->latest('id')->first();

    $email = (new \App\Mail\OrderConfirmation($order))->render();
    // The gift box in the confirmation: "2× numbuzin Eye Patches".
    expect($email)->toContain('<b>2&times;&nbsp;</b>numbuzin Eye Patches');

    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);
    $gifts = array_values(array_filter($doc['items'], fn ($i) => ! empty($i['gift'])));
    expect($gifts)->toHaveCount(1)->and($gifts[0]['quantity'])->toBe(2)->and($gifts[0]['lineFils'])->toBe(0);
    // The invoice prints the quantity after the name; the packing slip, a
    // picking list, puts it first ("2 · numbuzin Eye Patches").
    $invoice = view('invoices.partials.sheet-invoice', ['doc' => $doc, 'order' => $order])->render();
    expect(preg_match('~numbuzin Eye Patches</div>\s*<div[^>]*>Free gift</div>\s*</td>\s*<td class="num">2</td>~', $invoice))->toBe(1);
    $slip = view('invoices.partials.sheet-packing-slip', ['doc' => $doc, 'order' => $order])->render();
    expect(preg_match('~<td class="num"><strong>2</strong></td>(?:(?!</tr>).)*numbuzin Eye Patches~s', $slip))->toBe(1);

    // The owner app and the admin order screen read the line's own quantity.
    $admin = test()->actingAs(gqAdmin(), 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json('gifts');
    expect($admin[0]['offer'])->toBeTrue()->and($admin[0]['quantity'])->toBe(2);
})->skip(fn () => ! class_exists(\App\Mail\OrderConfirmation::class), 'no confirmation mailable');

it('shows ×2 on the exit popup and its worth as two units', function () {
    /*
     * The defect: the popup promises "a full-size patch" with no ×2 and the
     * one-unit price, so the shopper does not know she gets two.
     * MUTATION: drop 'qty' from GiftOffers::popupView() -- RED.
     */
    $patches = gqProduct('Eye Patches', ['price' => 4500]);
    gqRange(100, [[$patches, 'primary', 50, 2]]);
    gqSet(['places' => ['popup' => true], 'popup' => ['design' => 'A', 'style' => 'S3']]);
    $p = app(GiftOffers::class)->popupView(app(GiftOffers::class)->offerFor(40000));

    expect($p['qty'])->toBe(2)->and($p['worth'])->toContain('90');
    expect(view('partials.gift-offers.popup-card', ['p' => $p])->render())->toContain('<span class="kgo-c3">×2</span>');
});
