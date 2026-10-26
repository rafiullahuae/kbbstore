<?php

declare(strict_types=1);

/**
 * THE "GIFTS GO FAST" LINE UNDER THE FREE GIFTS BOX (Lane GU).
 *
 * The owner, 26 October, picking design 5 "Fire sweep": "this design is
 * final, please implement carefully without disturbance anything or break. on
 * cart panel, cart page, checkout summary section, and in the abandoned cart
 * email. need this only at 4 places. also in arabic too."
 *
 * So: exactly four surfaces -- the live box (cart panel, cart page, checkout,
 * all FreeGifts::box(), which sets kbbFgSig) and the basket reminder email --
 * and never an order's own box (thank-you page, My account) or any order
 * email. Each case says what the defect would look like and carries a
 * mutation note.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Support\Locale;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const GU_LINE_EN = '<p class="fgb-u">🔥 <span class="fgb-us">Gifts go fast! Grab yours now</span></p>';
const GU_LINE_AR = '<p class="fgb-u">🔥 <span class="fgb-us">الهدايا تنفد بسرعة! احصلي على هديتك الآن</span></p>';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
});

function guSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
    app()->forgetInstance(FreeGifts::class);
}

function guProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name ?: 'p') . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

/** Gifts live, with $extra merged into the saved free_gifts row. */
function guOn(array $extra = []): void
{
    guSet(FreeGifts::KEY, array_merge(['mode' => 'live', 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts'], $extra));
}

/** A range from AED 100 giving a mask; returns a basket inside it. */
function guBasket(): Cart
{
    $mask = guProduct('Tea Tree Sheet Mask');
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $mask->id, 'quantity' => 1, 'sort' => 0]);
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $serum = guProduct('Serum', ['price' => 15000]);
    $cart->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 15000]);

    return $cart->fresh();
}

function guShopper(Cart $cart)
{
    app(CartService::class)->forget();

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** The three live surfaces' HTML for a basket. @return array<string, string> */
function guSurfaces(Cart $cart, string $prefix = ''): array
{
    return [
        'cart panel' => (string) guShopper($cart)->getJson($prefix . '/api/cart/drawer')->assertOk()->json('html'),
        'cart page' => guShopper($cart)->get($prefix . '/cart/')->assertOk()->getContent(),
        'checkout' => guShopper($cart)->get($prefix . '/checkout/')->assertOk()->getContent(),
    ];
}

function guOrder(): Order
{
    $o = Order::create(['order_number' => 'GU-' . Str::random(6), 'email' => 'gu@example.test', 'status' => 'processing', 'total' => 55200, 'subtotal' => 55200,
        'currency' => 'AED', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'free_gift_count' => 3,
        'shipping_address' => ['first_name' => 'Aisha', 'country' => 'AE', 'city' => 'Dubai']]);
    OrderItem::create(['order_id' => $o->id, 'name' => 'Glow Serum', 'quantity' => 1, 'unit_price' => 55200, 'subtotal' => 55200, 'total' => 55200]);
    OrderItem::create(['order_id' => $o->id, 'name' => 'Tea Tree Sheet Mask', 'brand' => 'Mediheal', 'quantity' => 3, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);

    return $o->fresh();
}

function guReminder(int $price = 25000): string
{
    return (new \App\Mail\CartRecoveryReminder('Your basket', 'Hi', [['name' => 'Glow Serum', 'slug' => 'glow', 'quantity' => 1, 'unit_price' => $price]], 'https://example.test/cart/', 'https://example.test/u'))->render();
}

it('draws the line under the box on the cart panel, the cart page and the checkout, outside the card and nowhere else on the page', function () {
    /*
     * The defect: the line missing on one of the three (a box drawn without
     * box()'s kbbFgSig), or inside the card's content instead of after it.
     * It must be the fieldset's LAST child -- gift-live.js swaps the fieldset
     * whole, so a line drawn after </fieldset> would stay behind (twice after
     * one swap) and one drawn before the list would sit inside the card.
     * MUTATION: in box.blade.php gate $kbbFgU on anything but isset($kbbFgSig),
     * or move <p class="fgb-u"> before </div> of .fgb-ls. RED.
     */
    guOn();
    $cart = guBasket();

    foreach (guSurfaces($cart) as $where => $html) {
        $boxes = substr_count($html, '<fieldset class="fgb');
        expect($boxes)->toBeGreaterThan(0, $where)
            ->and(substr_count($html, GU_LINE_EN))->toBe($boxes, $where)
            ->and(substr_count($html, GU_LINE_EN . '</fieldset>'))->toBe($boxes, $where)
            ->and($html)->toContain(' fgb-hu" style="--fg-ts:12px')
            // The sweep: about a fifth of the line (6% of a 300% background), left to right, 2.6 s.
            ->and($html)->toContain('linear-gradient(90deg,#9a9298 0,#9a9298 47%,#ff7a18 48.5%,#e23b7b 50%,#ff3d5a 51.5%,#9a9298 53%,#9a9298 100%);background-size:300% 100%')
            ->and($html)->toContain('@keyframes fgb-sw{from{background-position:100% 0}to{background-position:0 0}}')
            ->and($html)->toContain('animation:fgb-sw 2.6s linear infinite')
            // Static grey for reduced motion; transparent text only where clip:text exists.
            ->and($html)->toContain('@media (prefers-reduced-motion:reduce){.fgb-us{animation:none;background-image:none;color:#9a9298;-webkit-text-fill-color:#9a9298}}')
            ->and($html)->toContain('@supports ((-webkit-background-clip:text) or (background-clip:text)){.fgb-us{')
            // Right to left under an Arabic page.
            ->and($html)->toContain('[dir=rtl] .fgb-us{animation-direction:reverse}');
    }

    // The live check's answer carries the line inside the one element it swaps.
    $live = guShopper($cart)->getJson('/api/cart/gifts?s=x')->assertOk()->json('html');
    expect($live)->toStartWith('<fieldset class="fgb')->toEndWith(GU_LINE_EN . '</fieldset>');
});

it('speaks Arabic on an Arabic page', function () {
    /*
     * The defect: the English line on /ar/, or an Arabic one with its emoji
     * inside the sweep (where the clipped background would paint over it).
     * MUTATION: drop 'urgency' from FreeGifts::TEXTS' Arabic, or the lead
     * split in urgency(). RED.
     */
    guSet('language_ar_enabled', '1');
    guSet(Locale::SETTING_RTL, '1');
    guOn();
    $cart = guBasket();

    $page = guShopper($cart)->get('/ar/cart/')->assertOk()->getContent();
    expect($page)->toContain('dir="rtl"')->toContain(GU_LINE_AR)->not->toContain('Gifts go fast');

    expect(FreeGifts::urgencySlice('الهدايا تنفد بسرعة! احصلي على هديتك الآن'))->toBe(['الهدايا تنفد بسرعة! ', 'احصلي على', ' هديتك الآن']);
});

it('never draws the line on an order\'s box: the thank-you page and My account', function () {
    /*
     * "need this only at 4 places." The order-received page and My account ->
     * Orders draw the same partial from the order's saved gifts, without
     * kbbFgSig. The defect: the urgency line under gifts already on their way.
     * MUTATION: in box.blade.php compute $kbbFgU without the isset($kbbFgSig)
     * gate. RED.
     */
    guOn();
    $order = guOrder();

    foreach (['partials/checkout/received-summary.blade.php', 'store/account/order-detail.blade.php'] as $file) {
        $src = (string) file_get_contents(resource_path('views/' . $file));
        expect($src)->toContain("@include('partials.free-gifts.box', ['kbbFgRows' => \\App\\Services\\FreeGifts::orderRows(\$order)")
            ->not->toContain('kbbFgSig');
    }

    foreach ([['kbbFgOpen' => true], []] as $extra) {
        $html = view('partials.free-gifts.box', ['kbbFgRows' => FreeGifts::orderRows($order), 'kbbFgThumbs' => FreeGifts::orderThumbs($order), 'kbbFgShowValue' => false] + $extra)->render();
        expect($html)->toContain('Tea Tree Sheet Mask')
            ->not->toContain('fgb-u')->not->toContain('fgb-hu')->not->toContain('fgb-sw')->not->toContain('Gifts go fast');
    }
});

it('puts a still frame of the line in the basket reminder email, and in no other email', function () {
    /*
     * Email clients run no CSS animation, so the reminder carries the sweep's
     * still frame: grey, the middle fifth of the words in the fire colour,
     * inline styles in a table row under the box's border. The defect: the
     * line missing, animated CSS in an email, or the line in an order email.
     * MUTATION: drop 'urgency' => true from emails/cart-recovery.blade.php, or
     * pass it from emails/kit/order.blade.php. RED.
     */
    $mask = guProduct('Gel Mask', ['gift_kind' => 'mask']);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 19400, 'max_fils' => null, 'label_en' => '1 Mask', 'label_ar' => 'قناع', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $mask->id, 'quantity' => 1, 'sort' => 0]);
    guOn();
    app(FreeGifts::class)->compile();

    $mail = guReminder();
    expect($mail)->toContain('Gel Mask')
        ->toMatch('#Grab yours before they are gone\.</td></tr>\s*</table></td></tr>\s*<tr><td colspan="3" align="center" style="padding:12px 0 0;text-align:center;[^"]*font-size:12\.5px;line-height:18px;font-weight:800;color:\#9a9298;">🔥&nbsp;Gifts go <span style="color:\#f0532d;">fast!</span> Grab yours now</td></tr>#u')
        ->not->toContain('@keyframes')->not->toContain('fgb-sw');

    // Below every range: no box, so no line.
    expect(guReminder(5000))->not->toContain('Gifts go');

    // The order emails draw the same gift box: never the line.
    expect((new \App\Mail\OrderConfirmation(guOrder()))->render())->toContain('Tea Tree Sheet Mask')->not->toContain('Gifts go');
    expect((new \App\Mail\OrderPaymentReminder(guOrder()->fill(['status' => 'pending']), 1))->render())->not->toContain('Gifts go');
});

it('ships on, switches off everywhere, and prints the owner\'s words escaped', function () {
    /*
     * The owner asked for it, so it ships ON (CLAUDE.md "what he asked for
     * ships on"); his switch removes it from all four places and their CSS
     * with it. The defect: a switch the shop ignores, or a text printed raw.
     * MUTATION: drop 'urgency' from the bool loop in FreeGifts::lookOf(), or
     * print {!! $kbbFgU['words'] !!}. RED.
     */
    expect(FreeGifts::LOOK['urgency'])->toBeTrue()
        ->and(FreeGifts::TEXTS['urgency'])->toBe(['🔥 Gifts go fast! Grab yours now', '🔥 الهدايا تنفد بسرعة! احصلي على هديتك الآن']);

    $cart = guBasket();
    guOn(['look' => ['urgency' => false]]);
    foreach (guSurfaces($cart) as $html) {
        expect($html)->toContain('<fieldset class="fgb')
            ->not->toContain('fgb-u')->not->toContain('fgb-hu')->not->toContain('fgb-sw')->not->toContain('Gifts go fast');
    }
    expect(guReminder())->not->toContain('Gifts go');

    guOn(['texts' => ['urgency_en' => '⚡ <b>Hurry</b> & "go"']]);
    $drawer = (string) guShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->toContain('<p class="fgb-u">⚡ <span class="fgb-us">&lt;b&gt;Hurry&lt;/b&gt; &amp; &quot;go&quot;</span></p>')
        ->not->toContain('<b>Hurry</b>');

    // Text with no leading symbol: all of it sweeps.
    guOn(['texts' => ['urgency_en' => 'Only a few left']]);
    expect((string) guShopper($cart)->getJson('/api/cart/drawer')->json('html'))->toContain('<p class="fgb-u"><span class="fgb-us">Only a few left</span></p>');
});

it('saves the switch and the texts from Display, one line at most', function () {
    /*
     * The defect: an endless text that wraps under the box (it is one nowrap
     * line in a fixed room), or a switch the admin cannot store.
     * MUTATION: drop the texts.urgency_* max rule from
     * FreeGiftsController::settings(). RED.
     */
    $owner = AdminUser::create(['name' => 'GU', 'email' => 'gu-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $c = app(FreeGifts::class)->freshConfig();
    $body = ['show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts', 'texts' => $c['texts'], 'home' => $c['home']];

    expect($c['texts']['urgency_en'])->toBe('🔥 Gifts go fast! Grab yours now');

    $long = $body;
    $long['texts']['urgency_ar'] = str_repeat('ب', FreeGifts::URGENCY_MAX + 1);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $long)->assertStatus(422)->assertJsonValidationErrors('texts.urgency_ar');

    $ok = $body;
    $ok['texts']['urgency_en'] = '🔥 Last few gifts!';
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $ok + ['look' => ['urgency' => false]])->assertOk();

    $saved = app(SettingsService::class)->get(FreeGifts::KEY);
    expect($saved['texts']['urgency_en'])->toBe('🔥 Last few gifts!')->and($saved['look']['urgency'])->toBeFalse();

    // The console carries the switch, the texts' row and the 80-character input.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain("sw(lk.urgency, 'Urgency line under the box'")
        ->toContain("['urgency', 'Urgency line: cart panel, cart page, checkout, basket reminder email'")
        ->toContain("maxlength: key === 'urgency' ? 80 : 160");
});

it('costs the cart page and the checkout no query', function () {
    /*
     * Its words live in the free_gifts settings row the box already read.
     * MUTATION: read the text with a Setting::query() in urgency(). RED.
     */
    $cart = guBasket();
    $count = function (bool $on, string $path) use ($cart): int {
        guOn(['look' => ['urgency' => $on]]);
        guShopper($cart)->get($path)->assertOk(); // warm
        guOn(['look' => ['urgency' => $on]]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = guShopper($cart)->get($path)->assertOk()->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(str_contains($html, 'class="fgb-u"'))->toBe($on);

        return $n;
    };

    foreach (['/cart/', '/checkout/'] as $path) {
        expect($count(true, $path))->toBe($count(false, $path), $path);
    }
});

/*
|------------------------------------------------------------------------------
| LANE GU2 -- THE HOMEPAGE LINE AND THE LINE'S LOOK
|------------------------------------------------------------------------------
| The owner, 26 October: "the gift grab yours line should also come on homepage
| and replace the line new gifts added daily. give option to switch on off. by
| default just keep on the Grab yours line, the same line which we have on cart
| panel gift box. and allow us to control the line font size etc, same effect i
| need continues."
*/

/** The homepage section on, a range a shopper can reach; $home merged into the saved home settings. */
function guHome(array $home = [], array $extra = []): void
{
    $mask = guProduct('Home Gel Mask', ['manage_stock' => true, 'stock' => 50, 'gift_kind' => 'mask']);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 19400, 'max_fils' => null, 'label_en' => 'Own words', 'label_ar' => 'كلمات', 'sort' => 0, 'enabled' => true, 'need_mask' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $mask->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0]);
    guOn(['home' => array_merge(['on' => true], $home)] + $extra);
    app(FreeGifts::class)->compile();
}

/** The free gifts section of a homepage. */
function guSection(string $html): string
{
    preg_match('#<section class="sec fgh.*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

it('puts the "Gifts go fast" line on the homepage in place of "New gifts added daily", sweeping, in English and Arabic', function () {
    /*
     * The defect: the homepage still saying "New gifts added daily", both lines
     * at once, or a line with no sweep / the wrong direction on /ar/.
     * MUTATION: drop 'urgency' from FreeGifts::home()'s payload, or the
     * @if ($fghU !== null) branch in partials/home/free-gifts. RED.
     */
    guHome();
    $sec = guSection(test()->get('/')->assertOk()->getContent());

    expect($sec)->toContain('<p class="fgh-u">🔥 <span class="fgh-us">Gifts go fast! Grab yours now</span></p>')
        ->not->toContain('class="fgh-note"')->not->toContain('New gifts added daily')
        ->toContain('.fgh .fgh-u{margin:0;text-align:center;font-size:11px;font-weight:800;line-height:1.45;color:#9a9298}')
        ->toContain('linear-gradient(90deg,#9a9298 0,#9a9298 47%,#ff7a18 48.5%,#e23b7b 50%,#ff3d5a 51.5%,#9a9298 53%,#9a9298 100%);background-size:300% 100%')
        ->toContain('animation:fgh-sw 2.6s linear infinite}[dir=rtl] .fgh-us{animation-direction:reverse}}')
        ->toContain('@keyframes fgh-sw{from{background-position:100% 0}to{background-position:0 0}}')
        ->toContain('@media (prefers-reduced-motion:reduce){.fgh-us{animation:none;background-image:none;color:#9a9298;-webkit-text-fill-color:#9a9298}}')
        ->toContain('@media (max-width:760px){.fgh .fgh-u{font-size:11px}}');
    // Exactly once: the line sits where the note sat, after the card.
    expect(substr_count($sec, 'class="fgh-u"'))->toBe(1)
        ->and(strpos($sec, 'class="fgh-u"'))->toBeGreaterThan(strpos($sec, '<div class="fgh-card">'));

    guSet('language_ar_enabled', '1');
    guSet(Locale::SETTING_RTL, '1');
    $ar = guSection(test()->get('/ar/')->assertOk()->getContent());
    expect($ar)->toContain('<p class="fgh-u">🔥 <span class="fgh-us">الهدايا تنفد بسرعة! احصلي على هديتك الآن</span></p>')
        ->not->toContain('هدايا جديدة تُضاف يومياً');
});

it('switched off, gives the homepage back "New gifts added daily" byte for byte', function () {
    /*
     * "give option to switch on off". The defect: off leaving the sweep's CSS
     * behind, or drawing the old line in any way it was not drawn before.
     * The reference is the section rendered from a payload of the shape it had
     * before this lane (no 'urgency', no 'look' key at all).
     * MUTATION: in home() ignore $h['urgency']. RED.
     */
    guHome(['urgency' => false]);
    $sec = guSection(test()->get('/')->assertOk()->getContent());
    expect($sec)->toMatch('#<p class="fgh-note"><svg[^>]*>.*?</svg><span>New gifts added daily</span></p>#s')
        ->not->toContain('fgh-u')->not->toContain('fgh-sw');

    app()->instance('request', \Illuminate\Http\Request::create('/'));
    $payload = app(FreeGifts::class)->home(request());
    expect($payload['urgency'])->toBeNull();
    $now = view('partials.home.free-gifts', ['fgh' => $payload])->render();
    unset($payload['urgency'], $payload['look']);
    expect($now)->toBe(view('partials.home.free-gifts', ['fgh' => $payload])->render());
});

it('sizes, weights and speeds the line from Gift box look, on the box and the homepage; the defaults move nothing', function () {
    /*
     * The defect: a size that only reaches one of the two lines, a phone size
     * the phone never sees, or the box line moving although nobody touched a
     * control (its CSS must stay byte-identical at 11 / 11 / 800 / 2.6 s).
     * MUTATION: print a fixed 11px in box.blade.php, or drop the 760px block
     * from either partial. RED.
     */
    $cart = guBasket();
    guOn();
    $drawer = (string) guShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->toContain('fieldset.fgb-hu{position:relative;margin-bottom:30px}fieldset.fgb-page.fgb-hu{margin-bottom:40px}.fgb-u{position:absolute;left:0;right:0;bottom:-27.5px;margin:0;padding:0 4px;text-align:center;font-size:11px;font-weight:800;line-height:16px;white-space:nowrap')
        ->toContain('animation:fgb-sw 2.6s linear infinite')
        ->toContain('-webkit-text-fill-color:#9a9298}}</style>')
        ->not->toContain('@media (max-width:760px){fieldset.fgb-hu');

    $look = ['u_px' => 14, 'u_px_m' => 12, 'u_weight' => 700, 'u_speed' => 40];
    guOn(['look' => $look]);
    $drawer = (string) guShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->toContain('fieldset.fgb-hu{position:relative;margin-bottom:33px}fieldset.fgb-page.fgb-hu{margin-bottom:43px}.fgb-u{position:absolute;left:0;right:0;bottom:-30.5px;margin:0;padding:0 4px;text-align:center;font-size:14px;font-weight:700;line-height:19px;')
        ->toContain('animation:fgb-sw 4s linear infinite')
        ->toContain('@media (max-width:760px){fieldset.fgb-hu{margin-bottom:31px}fieldset.fgb-page.fgb-hu{margin-bottom:41px}.fgb-u{bottom:-28.5px;font-size:12px;line-height:17px}}');

    DB::table('free_gift_ranges')->delete();
    guHome([], ['look' => $look]);
    $sec = guSection(test()->get('/')->assertOk()->getContent());
    expect($sec)->toContain('.fgh .fgh-u{margin:0;text-align:center;font-size:14px;font-weight:700;')
        ->toContain('animation:fgh-sw 4s linear infinite')
        ->toContain('@media (max-width:760px){.fgh .fgh-u{font-size:12px}}');

    // Out of range is clamped; not one of the weights is the default.
    $bad = FreeGifts::lookOf(['u_px' => 99, 'u_px_m' => 2, 'u_weight' => '650', 'u_speed' => '0.5']);
    expect([$bad['u_px'], $bad['u_px_m'], $bad['u_weight'], $bad['u_speed']])->toBe([24, 9, 800, 10])
        ->and(FreeGifts::lookOf(['u_weight' => '900'])['u_weight'])->toBe(900)
        ->and(FreeGifts::lookOf(['u_weight' => ['x']])['u_weight'])->toBe(800);
});

it('saves the homepage switch and the line\'s look from the admin, and keeps both when an older console sends neither', function () {
    /*
     * MUTATION: in FreeGiftsController::settings() drop the 'urgency' branch
     * (the string branch saves trim((string) false) = ''), or store look
     * unnormalised. RED.
     */
    $owner = AdminUser::create(['name' => 'GU2', 'email' => 'gu2-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $c = app(FreeGifts::class)->freshConfig();
    expect($c['home']['urgency'])->toBeTrue();
    $body = ['show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts', 'texts' => $c['texts']];

    $home = $c['home'];
    $home['urgency'] = false;
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $body + ['home' => $home, 'look' => ['u_px' => 30, 'u_px_m' => '13', 'u_weight' => 600, 'u_speed' => 35]])->assertOk();
    $saved = app(SettingsService::class)->get(FreeGifts::KEY);
    expect($saved['home']['urgency'])->toBeFalse()
        ->and([$saved['look']['u_px'], $saved['look']['u_px_m'], $saved['look']['u_weight'], $saved['look']['u_speed']])->toBe([24, 13, 600, 35]);

    // An older console: no home.urgency, no look -- both kept.
    $old = $c['home'];
    unset($old['urgency']);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $body + ['home' => $old])->assertOk();
    $saved = app(SettingsService::class)->get(FreeGifts::KEY);
    expect($saved['home']['urgency'])->toBeFalse()->and($saved['look']['u_weight'])->toBe(600);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/settings', $body + ['home' => ['urgency' => 'yes'] + $c['home']])->assertStatus(422);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain('Line under the gifts: “🔥 Gifts go fast! Grab yours now”')
        ->toContain("num('u_px', 'Urgency line size, computer'")
        ->toContain("num('u_px_m', 'Urgency line size, phone'")
        ->toContain("field('Urgency line weight'")
        ->toContain("'data-fk': 'lk-u_speed'")
        ->toContain('  var LOOK_U_WEIGHTS = ' . json_encode(FreeGifts::LOOK_U_WEIGHTS) . ';');
});

it('costs the homepage no query', function () {
    /*
     * The line's words and look are in the free_gifts row home() already read.
     * MUTATION: read them with a Setting::query() in home(). RED.
     */
    guHome();
    $count = function (bool $on): int {
        $s = app(SettingsService::class)->get(FreeGifts::KEY);
        $s['home']['urgency'] = $on;
        guSet(FreeGifts::KEY, $s);
        test()->get('/')->assertOk();
        guSet(FreeGifts::KEY, $s);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = test()->get('/')->assertOk()->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(str_contains($html, 'class="fgh-u"'))->toBe($on);

        return $n;
    };
    expect($count(true))->toBe($count(false));
});
