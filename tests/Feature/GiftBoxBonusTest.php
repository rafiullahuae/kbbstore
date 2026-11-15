<?php

declare(strict_types=1);

/*
 * THE BONUS GIFT INSIDE THE FREE GIFTS BOX (Lane GBX2). The owner:
 *
 *   10 Nov  "on the cart panel, cart and checkout, the additional free gift
 *            product should inside the gifts box ... i don't want additional
 *            separate box for it as we have currently"
 *   11 Nov  "i wanted the additional gift product at the last"
 *   15 Nov  "in place of word bonus, i need the timer, and remove the top bar
 *            timer row also" / "if time end, it will removed completely from
 *            the box silently without anything showing behind like Ended etc."
 *
 * What it looked like before: a claimed gift offer drew a SECOND box (the R2
 * row, partials/gift-offers/row.blade.php) under the Free gifts box, with its
 * own "Complete your order in" clock, and at zero it turned into "Your time is
 * up, so the gift has left your bag." Now it is the last gift INSIDE the box,
 * its clock where the BONUS tag was (design A-E, Marketing -> Gift offers ->
 * Settings -> "Bonus gift in the Free gifts box"), and at zero it simply leaves.
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
use App\Support\GiftBoxBonus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
    gbbSet([]);
});

function gbbFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
    app(CartService::class)->forget();
}

function gbbSet(array $config): void
{
    app(SettingsService::class)->set(GiftOffers::KEY, $config + ['mode' => 'live']);
    gbbFresh();
}

function gbbProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 8900, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 20, 'image' => '/media/' . Str::slug($name) . '.webp',
    ], $o));
}

/** A gift offer range from AED 100 giving $p ×$qty. */
function gbbOffer(Product $p, int $qty = 2): void
{
    $id = DB::table('gift_offer_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('gift_offer_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'role' => 'primary', 'sort' => 0, 'allocation' => 50, 'used' => 0, 'qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    app(GiftOffers::class)->compile();
    gbbFresh();
}

/** Regular free gifts from AED 100: each product ×1, switched on. */
function gbbGifts(array $products): void
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($products as $i => $p) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $i]);
    }
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    gbbFresh();
    app(FreeGifts::class)->compile();
    gbbFresh();
}

function gbbCart(): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $p = gbbProduct('Serum', ['price' => 40000]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 40000]);

    return $cart->fresh();
}

function gbbShopper(?Cart $cart = null)
{
    app(CartService::class)->forget();
    $t = test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class);

    return $cart ? $t->withUnencryptedCookie(CartService::COOKIE, $cart->token) : $t;
}

function gbbClaim(Cart $cart): void
{
    $offer = GiftOffers::forBasket([['unit_price' => 40000, 'quantity' => 1]], null);
    expect($offer)->not->toBeNull();
    gbbShopper($cart)->get('/gift-offer/c/' . preg_replace('~^.*/gift-offer/c/~', '', $offer['href']));
    gbbFresh();
}

function gbbCod(): void
{
    \App\Models\PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}

/** Every Free gifts box in a page, in order. */
function gbbBoxes(string $html): array
{
    preg_match_all('~<fieldset class="fgb.*?</fieldset>~s', $html, $m);

    return $m[0];
}

/** [cart page, cart panel, checkout] for this basket. */
function gbbSurfaces(Cart $cart): array
{
    return [
        'cart page' => gbbShopper($cart)->get('/cart/')->assertOk()->getContent(),
        'cart panel' => (string) gbbShopper($cart)->getJson('/api/cart/drawer')->json('html'),
        'checkout' => gbbShopper($cart)->get('/checkout/')->assertOk()->getContent(),
    ];
}

it('draws the bonus INSIDE the Free gifts box, as its LAST gift, on the cart page, the cart panel and the checkout', function () {
    /*
     * The defect: the claimed gift in a second box of its own under the Free
     * gifts box (the owner, 10 Nov: "i don't want additional separate box for
     * it"), or inside the box but FIRST (11 Nov: "i wanted the additional gift
     * product at the last").
     * MUTATION: in box.blade.php move {!! $kbbFgBonusTile !!} before the
     * @foreach of photos -- the bonus comes first. RED. Drop the intoBox()
     * call in any of the three views -- that surface keeps the row. RED.
     */
    gbbCod();
    gbbGifts([gbbProduct('Mask One'), gbbProduct('Mask Two')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    foreach (gbbSurfaces($cart) as $where => $html) {
        $boxes = gbbBoxes($html);
        expect($boxes)->not->toBe([], $where);

        foreach ($boxes as $box) {
            $one = strpos($box, 'alt="Mask One"');
            $two = strpos($box, 'alt="Mask Two"');
            $bonus = strpos($box, 'gbm-t gbA-t" data-gbm-bonus>');
            $viewAll = strpos($box, '>View all</button>');
            expect($one)->toBeInt($where)->and($two)->toBeInt($where)->and($bonus)->toBeInt($where)
                ->and($bonus)->toBeGreaterThan(max($one, $two))
                ->and($bonus)->toBeLessThan($viewAll)
                ->and($box)->toContain('<img src="/media/relief-sun.webp" alt="Relief Sun"')
                ->and($box)->toContain('<b>Bonus:</b> 2× Relief Sun · full size')
                ->and($box)->toContain(' gbm gbA"');
        }

        // ...and no separate row anywhere on the surface.
        expect($html)->not->toContain('data-kgo-row')->not->toContain('class="kgo-r"')->not->toContain('kbbGoT');
    }
});

it('puts no timer row above the gifts: the live clock is ON the bonus gift, where the BONUS tag was', function () {
    /*
     * The defect (the owner, 15 Nov: "in place of word bonus, i need the timer,
     * and remove the top bar timer row also"): a bar, pill or "Complete your
     * order in" line above the photos, or a BONUS tag with the clock elsewhere.
     * MUTATION: print $kbbFgB['after'] right after </legend> -- something sits
     * above the row. RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    $box = gbbBoxes(gbbShopper($cart)->get('/cart/')->getContent())[0];
    $top = substr($box, strpos($box, '</legend>'), strpos($box, '<div class="fgb-th">') - strpos($box, '</legend>'));

    expect($top)->not->toContain('gbm')->not->toContain('timer')->not->toContain('Complete your order in')
        ->and($box)->toContain('data-gbm-bonus><i class="gbA-tag" role="timer" aria-label="Bonus gift held for"><span class="gbm-clk" data-gbm-clk>')
        // one clock on the photo row, one on the bonus's row inside View all (hidden until opened)
        ->and(substr_count($box, 'role="timer"'))->toBe(2)
        ->and(substr_count($top, 'role="timer"'))->toBe(0)
        ->and($box)->not->toContain('kgo-tw')->not->toContain('>BONUS<');
});

it('draws nothing of an expired bonus, and the order does not get it', function () {
    /*
     * The defect (the owner, 15 Nov: "if time end, it will removed completely
     * from the box silently without anything showing behind like Ended etc."):
     * an expired bonus still drawn, or a word about it ("ended", "time is up"),
     * or the gift written onto the order anyway.
     * MUTATION: drop the expires_at check in GiftOffers::liveClaim() -- the
     * bonus is drawn at 00:00 and the order gets it. RED.
     */
    gbbCod();
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);
    expect(gbbShopper($cart)->get('/cart/')->getContent())->toContain('data-gbm-left');

    $this->travel(9)->hours();
    gbbFresh();

    foreach (gbbSurfaces($cart) as $where => $html) {
        foreach (['gbm', 'kgo-', 'Relief Sun', 'Ended', 'has ended', 'time is up'] as $gone) {
            expect(str_contains($html, $gone))->toBeFalse("$where still shows \"$gone\"");
        }
        expect(gbbBoxes($html))->not->toBe([], $where);   // the regular box is still there
    }

    gbbShopper($cart)->post('/checkout/place', [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertRedirect();
    $order = Order::query()->latest('id')->first();
    expect($order->gifts()->pluck('name')->all())->not->toContain('Relief Sun');
});

it('fades the bonus out and removes it at zero with no words, transform and opacity only', function () {
    /*
     * The defect: at zero the clock's script writes a message ("Ended", the
     * row's "Your time is up...") or leaves the bonus's space behind, or
     * animates a layout property.
     * MUTATION: in GiftBoxBonus::SCRIPT drop `e.remove()` -- the faded bonus
     * keeps its room. RED.
     */
    $s = GiftBoxBonus::SCRIPT;
    expect($s)->toContain("classList.add('gbm-out')")->toContain('e.remove()')->toContain('450')
        ->toContain('clearInterval(iv)')
        ->not->toContain('textContent=b.getAttribute')->not->toContain('over')->not->toContain('nded')
        ->not->toMatch('/getBoundingClientRect|offsetHeight|offsetWidth|getComputedStyle|clientHeight/');

    $css = GiftBoxBonus::parts(['design' => 'A', 'left' => 60, 'total' => 900, 'name' => 'X'])['css'];
    expect($css)->toContain('.gbm .gbm-out,.gbm.gbm-out{opacity:0;transform:scale(.88)}')
        ->toContain('transition:opacity .4s ease,transform .4s ease')
        ->toContain('@media (prefers-reduced-motion:reduce)');
});

it('keeps the box for a bonus with no regular gifts: only the bonus, no View all, no wording line', function () {
    /*
     * The defect: a claimed gift that vanished because the basket had no
     * regular free gift (the box drew nothing), or a box saying "0 gifts" with
     * a View all that opens an empty list.
     * MUTATION: in box.blade.php drop `|| isset($kbbFgBonus)` -- no box, the
     * gift is invisible. RED.
     */
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    $box = gbbBoxes(gbbShopper($cart)->get('/cart/')->getContent())[0];
    expect($box)->toContain(' gbm gbA gbm-solo"')->toContain('alt="Relief Sun"')
        ->not->toContain('>View all</button>')->not->toContain('class="fgb-w"')->not->toContain('class="fgb-u"');
});

it('turns the bonus red under two minutes', function () {
    /*
     * The defect: the clock stays pink in its last two minutes.
     * MUTATION: drop 'low' from GiftBoxBonus::parts(). RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);
    DB::table('gift_offer_claims')->update(['expires_at' => now()->addSeconds(100)]);
    gbbFresh();

    $box = gbbBoxes(gbbShopper($cart)->get('/cart/')->getContent())[0];
    expect($box)->toContain(' gbm gbA low"')->toMatch('~data-gbm-clk>01:[34][0-9]</span>~');
});

it('draws each of the five designs with its clock on the bonus and only its own CSS', function () {
    /*
     * The defect: a design that loses its clock, prints another design's
     * rules, or puts an unescaped name on the page.
     */
    $b = ['left' => 892, 'total' => 1200, 'name' => 'Relief <Sun>', 'qty' => 2, 'img' => '/media/r.webp', 'free' => 'Free', 'full' => 'Full size',
        'bonus' => 'Bonus:', 'plus' => '+ Bonus', 'held' => 'Bonus gift held for', 'size' => 'full size', 'below' => ''];

    foreach (GiftOffers::BOX_DESIGNS as $d) {
        $p = GiftBoxBonus::parts($b + ['design' => $d]);
        $html = $p['tile'] . $p['after'];
        expect($p['design'])->toBe($d)
            ->and($p['css'])->toContain('.gb' . $d . '-')
            ->and($html)->toContain('Relief &lt;Sun&gt;')->not->toContain('<Sun>')
            ->and($html)->toContain($d === 'E' ? 'data-gbm-flip><i>1</i><i>4</i><u>:</u><i>5</i><i>2</i>' : 'data-gbm-clk>14:52</span>');

        foreach (array_diff(GiftOffers::BOX_DESIGNS, [$d]) as $other) {
            expect($p['css'])->not->toContain('.gb' . $other . '-');
        }
    }

    // A and C: the bonus photo IS the last tile in the row; B and D: a card at the foot.
    expect(GiftBoxBonus::parts($b + ['design' => 'B'])['tile'])->toBe('')
        ->and(GiftBoxBonus::parts($b + ['design' => 'D'])['tile'])->toBe('')
        ->and(GiftBoxBonus::parts($b + ['design' => 'C'])['after'])->toContain('<b>+ Bonus</b> 2× Relief &lt;Sun&gt;');

    // The one-line name (A, C, E) never widens the page: measured on /ar/cart/
    // at 390 it pushed scrollWidth to 421 (its nowrap text set the cart
    // column's min-content). width:0 + min-width:100% takes it out of that.
    // MUTATION: drop width:0;min-width:100% from .gbA-cap. RED.
    foreach (['A', 'C', 'E'] as $d) {
        expect(GiftBoxBonus::parts($b + ['design' => $d])['css'])->toContain('.gb' . $d . '-cap{width:0;min-width:100%;box-sizing:border-box;');
    }
});

it('stores one of its own five designs or A, and a real on/off, whatever the admin is sent', function () {
    /*
     * The defect (CLAUDE.md rule 5): a select that stores whatever it is
     * posted -- here a class name printed on every cart page.
     * MUTATION: in GiftOffers::normalise() store $saved['box']['design']
     * as sent. RED.
     */
    $admin = AdminUser::create(['name' => 'GBX2', 'email' => 'gbx2-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $save = fn (array $box) => test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/gift-offers/settings', ['config' => ['box' => $box]])->assertOk()->json('config.box');

    expect($save(['design' => 'Z" onmouseover="x', 'merge' => 'yes']))->toBe(['merge' => true, 'design' => GiftOffers::BOX_DESIGN])
        ->and($save(['design' => 'c', 'merge' => 0]))->toBe(['merge' => true, 'design' => 'A'])
        ->and($save(['design' => 'E', 'merge' => false]))->toBe(['merge' => false, 'design' => 'E'])
        ->and(GiftOffers::normalise([])['box'])->toBe(['merge' => true, 'design' => 'A'])
        ->and(GiftBoxBonus::design('<x>'))->toBe(GiftOffers::BOX_DESIGN);

    // The admin's control sits on Marketing -> Gift offers -> Settings.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/gift-offers-screen.blade.php'));
    expect($screen)->toContain('Bonus gift in the Free gifts box')->toContain("'Show bonus gift inside the Free gifts box', 'box:merge'")
        ->toContain('data-k="box:design"')->toContain("['E', 'Gold frame'");
});

it('with the switch OFF draws today\'s two boxes byte for byte, at the same query count', function () {
    /*
     * The defect: the merge leaking into a shop that switched it off (a class,
     * a byte, a script), or the merged view costing a query.
     * MUTATION: drop `! $this->config()['box']['merge']` from intoBox() --
     * the bonus moves in anyway. RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    $count = function () use ($cart): int {
        gbbFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        gbbShopper($cart)->get('/cart/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count();   // warm: the first request in a process pays for what later ones memoise
    $merged = $count();

    gbbSet(['box' => ['merge' => false]]);
    $page = gbbShopper($cart)->get('/cart/')->getContent();
    $off = $count();

    // Off: intoBox() hands back exactly what it was given, so the views include
    // the very arrays they included before; the box is followed by the row,
    // adjacent, as before, and no byte of the merge is on the page.
    $box = ['kbbFgRows' => [['name' => 'x']], 'kbbFgSig' => 'abc'];
    $row = ['goRow' => ['left' => 30, 'name' => 'y']];
    expect(app(GiftOffers::class)->intoBox($box, $row))->toBe([$box, $row])
        ->and($page)->not->toContain('gbm')->not->toContain('kbbGbmT')->not->toContain('data-gbm')
        ->and($page)->toContain('</fieldset><style>.kgo-r{')
        ->and(substr_count($page, 'class="kgo-r" data-kgo-row'))->toBe(substr_count($page, '</fieldset><style>.kgo-r{'))
        ->and($merged)->toBe($off);
});

it('keeps the clock digits left to right on /ar, with the Arabic words', function () {
    /*
     * The defect: on /ar the clock reads 25:41 for 14:52 (RTL digit runs), or
     * the name line stays English.
     * MUTATION: drop direction:ltr from .gbm-clk. RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);
    app(SettingsService::class)->set('language_ar_enabled', '1');
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');
    gbbFresh();
    \App\Support\Money::forgetConfig();
    app()->forgetInstance(FreeGifts::class);

    $page = gbbShopper($cart)->get('/ar/cart/')->assertOk()->getContent();
    $box = gbbBoxes($page)[0];
    expect($page)->toContain('dir="rtl"');
    expect($box)->toContain('.gbm-clk{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums;font-weight:800;letter-spacing:.03em;direction:ltr;unicode-bidi:isolate}')
        ->toContain('<b>هدية إضافية:</b>')->toContain('[dir=rtl] .gbA-ln{transform-origin:100% 50%}')
        ->toContain('aria-label="الهدية الإضافية محجوزة لمدة"');
});

it('keeps the bonus in the box when the live check redraws it', function () {
    /*
     * The defect: GET /api/cart/gifts answers a changed box WITHOUT the bonus,
     * so the shopper's claimed gift disappears on her next tap.
     * MUTATION: drop the intoBox() call in CartGiftsController. RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    $html = (string) gbbShopper($cart)->getJson('/api/cart/gifts?s=stale')->assertOk()->json('html');
    expect($html)->toContain('alt="Mask One"')->toContain('alt="Relief Sun"')->toContain('data-gbm-left=');
});

it('shows the bonus as the last row of View all, with its own small clock', function () {
    /*
     * The defect (the owner, 15 Nov: "when press view all, then the bonus product should also show there
     * in single row with small numbers timer"): View all opened a list of the regular gifts only, so the
     * bonus vanished the moment the shopper opened the box.
     * MUTATION: drop the GiftBoxBonus::listRow() call from partials/free-gifts/box.blade.php -- RED.
     */
    gbbGifts([gbbProduct('Mask One')]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    foreach (gbbSurfaces($cart) as $where => $html) {
        $box = gbbBoxes($html)[0] ?? '';
        preg_match('~<div class="fgb-list">(.*?)</div><(?:span class="gbx-ft"|button)~s', $box, $m);
        $list = $m[1] ?? '';
        expect($list)->toContain('Mask One')
            ->and(preg_match('~<div class="fgb-lr gbm-lr" data-gbm-bonus>.*?2× </em>Relief Sun.*?<i class="gbm-lk" role="timer"[^>]*><span data-gbm-clk>\d\d?:\d\d(:\d\d)?</span></i>~s', $list))->toBe(1, $where)
            // the bonus row is the LAST row of the list
            ->and(strrpos($list, 'fgb-lr') === strpos($list, 'fgb-lr gbm-lr'))->toBeTrue($where);
    }
});

it('hides the bonus silently while the basket is below its minimum, and brings it back above it', function () {
    /*
     * The defect (the owner, 15 Nov: "if user reduced the cart, then the bonus product still showing with
     * weird line that add 'aed' to keep this product ... i need it should silently removed ... and if the
     * same user increase the cart again, it should add silently also"): below the range's minimum the box
     * kept the bonus and printed "Add AED X more to keep this gift".
     * MUTATION: drop the `below` condition in GiftOffers::intoBox() -- the first loop is RED.
     */
    gbbGifts([gbbProduct('Mask One', ['price' => 100])]);
    gbbOffer(gbbProduct('Relief Sun'));
    $cart = gbbCart();
    gbbClaim($cart);

    // Basket down to AED 50 (the bonus range starts at AED 100): no bonus, no line, no row.
    $cart->items()->update(['unit_price' => 5000]);
    Product::query()->where('name', 'Serum')->update(['price' => 5000]);
    gbbFresh();
    foreach (gbbSurfaces($cart->fresh()) as $where => $html) {
        foreach (['data-gbm-bonus', 'Relief Sun', 'kgo-', 'gbm-bl', 'more to keep'] as $gone) {
            expect(str_contains($html, $gone))->toBeFalse("$where still shows \"$gone\"");
        }
    }

    // Back up to AED 400: the claim was kept, so the bonus is back in the box, silently.
    $cart->items()->update(['unit_price' => 40000]);
    Product::query()->where('name', 'Serum')->update(['price' => 40000]);
    gbbFresh();
    foreach (gbbSurfaces($cart->fresh()) as $where => $html) {
        expect(str_contains($html, 'data-gbm-bonus') && str_contains($html, 'Relief Sun'))->toBeTrue("$where lost the bonus");
    }
});
