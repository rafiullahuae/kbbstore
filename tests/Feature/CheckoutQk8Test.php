<?php

declare(strict_types=1);

/*
 * Lane QK8 — the owner's round of checkout requests, one test per request.
 *
 *  1. "the payment methods boxes, please turn off the background color, and
 *     the border color should be grey, and when user select any payment
 *     method, the border color should turn to colorful ... upon selection
 *     also no background should come."
 *  2. "the whatsapp floating button should be turn off on cart and checkout
 *     completely."
 *  3. "i want the summary section the checkout page heading. and the icon i
 *     want beside left side of Checkout heading" (a round back arrow to the
 *     cart), and "give control of spacing ... above the checkout title row".
 *  4. "the fields placeholders are not 100% in middle. please fix in all."
 *  5. "remove the Returns information from the checkout footer, as we don't
 *     offer returns, keep the Privacy Policy there."
 *  6. "Remember my details line, please replace to > Remember my shipping
 *     details on this device".
 *
 * (The coupon line turned off and "Free express delivery" are pinned in
 * CheckoutCouponLineTest and CheckoutDeliveryLabelsTest, beside the rest of
 * those features.)
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Services\SlimFooter;
use App\Services\WhatsAppButton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

beforeEach(function () {
    PaymentProvider::query()->delete();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 1500, 'enabled' => true, 'position' => 0]);

    $fake = 'test-not-a-real-key';
    foreach ([
        ['stripe', 'Credit / Debit Card', 0, ['secret_key' => 'sk_test_'.$fake, 'publishable_key' => 'pk_test_'.$fake]],
        ['tabby', 'Tabby Installments', 1, ['public_key' => 'pk_test_'.$fake, 'secret_key' => 'sk_test_'.$fake]],
        ['tamara', 'Pay later with Tamara', 2, ['api_token' => $fake, 'notification_token' => $fake]],
        ['cod', 'Cash on delivery', 3, []],
    ] as [$id, $title, $pos, $config]) {
        PaymentProvider::create(['id' => $id, 'title' => $title, 'enabled' => true, 'mode' => 'test',
            'position' => $pos, 'config' => $config]);
    }
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    qk8Forget();
});

function qk8Forget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function qk8Save(array $values): void
{
    app(CheckoutPage::class)->save($values);
    qk8Forget();
}

function qk8Product(): Product
{
    return Product::create(['slug' => 'qk8-'.Str::random(8), 'name' => 'Glass Skin Set', 'status' => 'publish',
        'is_visible' => true, 'price' => 37500, 'stock_status' => 'instock']);
}

function qk8Get(string $path): string
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => qk8Product()->id, 'quantity' => 1, 'unit_price' => 37500]);

    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    return (string) test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)->assertOk()->getContent();
}

function qk8Section(string $html): string
{
    preg_match('#<section class="kbb-checkout[^"]*"[^>]*>#', $html, $m);

    return $m[0] ?? '';
}

function qk8Css(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
}

/** The kbb-checkout stylesheet the shop actually loads, from the Vite manifest. */
function qk8BuiltCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-checkout.css']['file']));
}

/* ═══════════════════════ 1. the payment method boxes ═══════════════════════ */

it('ships the boxes with no tint and a grey edge, and only the chosen box takes its colour', function () {
    /*
     * DEFECT this guards: every box sat on a wash of its gateway's colour --
     * lavender card, mint Tabby, peach Tamara, green cash on delivery -- chosen
     * or not, which the owner asked to be gone. Now the plain white card and
     * the address fields' grey border (var(--line)); the brand border only on
     * the chosen box.
     *
     * MUTATION: ship `pay_bg` true, or delete the `:not(.cop-pay-tint)` rule
     * that paints --pt white, and this is red; change the chosen box's
     * `var(--pg) border-box` and the "colourful when chosen" half is.
     */
    expect(CheckoutPage::SCHEMA['pay_bg'][2])->toBeFalse()
        ->and(CheckoutPage::SCHEMA['pay_unsel'][2])->toBe('grey');

    $section = qk8Section(qk8Get('/checkout'));
    expect($section)->toContain(' cop-pay')
        ->and($section)->not->toContain('cop-pay-tint')
        ->and($section)->not->toContain('cop-pay-ucol');

    foreach ([qk8Css(), qk8BuiltCss()] as $css) {
        // The built file is minified (`:has(>input` for `:has(> input`).
        $flat = (string) preg_replace(['/\s+/', '/:has\(>\s*/'], [' ', ':has(> '], $css);
        expect($flat)
            // no tint for any box, chosen or not: the tint variable IS white
            ->toContain('.kbb-checkout.cop-pay:not(.cop-pay-tint) .wc_payment_methods li.wc_payment_method{--pt:linear-gradient(#fff,#fff)}')
            // a gateway without brand colours: white too, and no green wash under its description
            ->toContain('.kbb-checkout.cop-pay:not(.cop-pay-tint) .wc_payment_methods li.wc_payment_method:not(.pay-brand):hover{background:#fff}')
            ->toContain('.kbb-checkout.cop-pay:not(.cop-pay-tint) #payment .wc_payment_methods li.wc_payment_method div.payment_box{background:transparent!important}')
            // unselected: the fields' grey
            ->toContain('.kbb-checkout.cop-pay .wc_payment_methods li.wc_payment_method.pay-brand:hover{background:var(--pt);border-color:var(--line)}')
            // selected: the brand border, exactly as before
            ->toContain('.kbb-checkout.cop-pay .wc_payment_methods li.wc_payment_method.pay-brand:has(input:checked){border-color:transparent;background:var(--pt) padding-box,var(--pg) border-box}')
            ->toContain('--pg:linear-gradient(90deg,#3BFF9D,#3BFFC8)')
            ->toContain('--pg:linear-gradient(#1434CB,#1434CB)')
            ->toContain('--pg:linear-gradient(#1F7D52,#1F7D52)')
            // keyboard focus on a box whose radio is a 1px invisible input
            ->toContain('.kbb-checkout.cop-pay .wc_payment_methods li.wc_payment_method:has(> input:focus-visible){outline:2px solid var(--ink);outline-offset:2px}');
    }
});

it('puts the tint and the coloured edge back from Appearance → Checkout page → Payment boxes', function () {
    // MUTATION: drop the `pay_bg` arm or the pay_unsel map entry in bodyClass() -> red.
    qk8Save(['pay_bg' => true, 'pay_unsel' => 'colour']);
    $section = qk8Section(qk8Get('/checkout'));

    expect($section)->toContain('cop-pay-tint')->and($section)->toContain('cop-pay-ucol')
        ->and(preg_replace('/\s+/', ' ', qk8Css()))
        ->toContain('.kbb-checkout.cop-pay-ucol .wc_payment_methods li.wc_payment_method.pay-brand:not(:has(input:checked)){border-color:var(--ps)}');

    // A stored value that is not one of the select's own options is the default.
    qk8Save(['pay_unsel' => 'url(javascript:1)']);
    expect(app(CheckoutPage::class)->get('pay_unsel'))->toBe('grey');

    // "Today" is still the whole way back: none of the new classes.
    qk8Save(['pay_style' => 'plain']);
    expect(qk8Section(qk8Get('/checkout')))->not->toContain('cop-pay');

    $tab = CheckoutPage::TABS['payments'][2];
    expect(array_slice($tab, 3, 2))->toBe(['pay_bg', 'pay_unsel']);
});

/* ══════════════════ 2. no WhatsApp on the cart and checkout ══════════════════ */

it('prints nothing of the WhatsApp button on the cart and the checkout, English and Arabic, and keeps it everywhere else', function () {
    /*
     * DEFECT this guards: the round button (and on a phone its side tab) over
     * the cart and the checkout. Off server-side: not one byte -- no <style
     * id="kbb-wa">, no #kbbWa, no tab, no script.
     *
     * MUTATION: drop @section('kbb-wa-page', 'checkout') from
     * store/checkout.blade.php (or 'cart' from store/cart.blade.php), or ship
     * show_cart / show_checkout true, and the matching half is red.
     */
    expect(WhatsAppButton::SCHEMA['show_cart'][2])->toBeFalse()
        ->and(WhatsAppButton::SCHEMA['show_checkout'][2])->toBeFalse();

    ArabicShop::on();
    foreach (['/cart', '/checkout', '/ar/cart', '/ar/checkout'] as $path) {
        $html = qk8Get($path);
        expect($html)->not->toContain('<style id="kbb-wa">')->and($html)->not->toContain('kbbWa')
            ->and($html)->not->toContain('class="kbt')->and($html)->not->toContain('kbbWaB');
    }

    $product = qk8Product();
    foreach (['/', '/product/'.$product->slug.'/', '/ar/'] as $path) {
        $html = (string) test()->get($path)->assertOk()->getContent();
        expect(substr_count($html, 'id="kbbWa"'))->toBe(1, $path);
    }

    // Switched back on, each page gets exactly what it had.
    app(WhatsAppButton::class)->save(['show_cart' => true]);
    qk8Forget();
    expect(qk8Get('/cart'))->toContain('id="kbbWa"')->and(qk8Get('/checkout'))->not->toContain('id="kbbWa"');
    app(WhatsAppButton::class)->save(['show_checkout' => true]);
    qk8Forget();
    expect(qk8Get('/checkout'))->toContain('id="kbbWa"');

    expect(WhatsAppButton::TABS['design'][2])->toContain('show_cart')->toContain('show_checkout');
});

/* ═════════════════ 3. the heading on top, with its back arrow ═════════════════ */

it('opens the checkout with the "← Checkout" heading, the arrow a real link to the cart', function () {
    /*
     * DEFECT this guards: the heading sat in the left column under the Order
     * summary on a phone, with no way back to the cart but the browser.
     *
     * MUTATION: move .co-titlebar back inside the left column, or drop the
     * `order:-3` / `grid-row:1 / span 2` rules, and this is red; drop the
     * aria-label and the link is unnamed.
     */
    expect(CheckoutPage::SCHEMA['head_back'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['head_back'][1])->toBe('Back arrow beside the Checkout heading');

    $html = qk8Get('/checkout');
    $back = '<a class="co-back" href="/cart/" aria-label="Back to cart" title="Back to cart"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></a>';

    expect(substr_count($html, 'class="co-back"'))->toBe(1)
        ->and($html)->toContain($back)
        // first thing in the grid (the coupon line ships off), arrow then h1 then lead
        ->and($html)->toMatch('#<div class="co-grid">\n\n\s*<div class="co-titlebar">\n\s*<div class="co-titlebar-main">\n\s*<a class="co-back"[^>]*>.*?</a>\n\s*<h1 class="co-h">Checkout</h1>\n\s*<p class="co-lead">#s')
        // before the left column and the summary
        ->and(strpos($html, 'class="co-titlebar"'))->toBeLessThan(strpos($html, '<!-- LEFT -->'))
        ->and(strpos($html, 'class="co-titlebar"'))->toBeLessThan(strpos($html, 'id="kbbSummary"'))
        // and not in the slim header any more
        ->and($html)->not->toMatch('#<header class="co-head">(?:(?!</header>).)*co-back#s');

    $css = preg_replace('/\s+/', ' ', qk8Css());
    expect($css)->toContain('.kbb-checkout .co-grid > .co-titlebar,.kbb-checkout .co-grid:not(:has(> .co-cline)) > .co-titlebar{order:-3;')
        ->toContain('.kbb-checkout .co-grid > .summary{grid-column:2;grid-row:1 / span 2}')
        ->toContain('.kbb-checkout .co-titlebar-main > .co-back{grid-column:1;display:flex;align-items:center;justify-content:center;inline-size:40px;block-size:40px;margin-block:-8px;box-sizing:border-box;border-radius:50%;background:#fff;border:1px solid #E3E3E6;color:#2A2A2E;')
        ->toContain('.kbb-checkout .co-titlebar-main > .co-back:hover,.kbb-checkout .co-titlebar-main > .co-back:active{background:#F2F2F4}')
        ->toContain('.kbb-checkout .co-titlebar-main > .co-back:focus-visible{outline:2px solid var(--ink);outline-offset:2px}')
        ->toContain('[dir="rtl"] .kbb-checkout .co-titlebar-main > .co-back svg{transform:scaleX(-1)}');
});

it('links the arrow to the Arabic cart on /ar/, in Arabic once approved, and draws nothing when switched off', function () {
    ArabicShop::on();
    ArabicShop::string('store.checkout.head_back', 'رجوع إلى السلة');

    expect(qk8Get('/ar/checkout'))->toContain('<a class="co-back" href="/ar/cart/" aria-label="رجوع إلى السلة" title="رجوع إلى السلة">');

    // MUTATION: drop the showHeadBack() @if -> red.
    qk8Save(['head_back' => false]);
    $html = qk8Get('/checkout');
    expect($html)->not->toContain('co-back')
        ->and($html)->toMatch('#<div class="co-titlebar-main">\n\s*<h1 class="co-h">Checkout</h1>#');
});

it('prints the "Space above the Checkout title" only when it is moved, clamped to 0–80', function () {
    // MUTATION: drop d_title_pt / m_title_pt from VARS -> the 8px is not printed.
    expect(qk8Section(qk8Get('/checkout')))->not->toContain('titlept');

    qk8Save(['d_title_pt' => 8, 'm_title_pt' => 200]);
    $section = qk8Section(qk8Get('/checkout'));
    expect($section)->toContain('--cop-d-titlept:8px')->and($section)->toContain('--cop-m-titlept:80px');

    $css = preg_replace('/\s+/', ' ', qk8Css());
    expect($css)->toContain('--cop-titlept:var(--cop-d-titlept,var(--cop-pady));')
        ->toContain('--cop-titlept:var(--cop-m-titlept,var(--cop-pady));')
        ->toContain('.kbb-checkout .co-grid:not(:has(> .co-cline)) > .co-titlebar{margin-top:calc(var(--cop-titlept) - var(--cop-pady))}')
        // the round arrow never meets the sticky header, whatever the slider says
        ->toContain('> .co-titlebar:has(> .co-titlebar-main > .co-back){margin-top:calc(max(var(--cop-titlept), 14px) - var(--cop-pady))}');
});

/* ═══════════════ 4. resting floating labels, centred in every form ═══════════════ */

it('centres the resting label by geometry in every floating-label form, and lifts it from where it always lifted', function () {
    /*
     * DEFECT this guards: "Full name *" sat high in its box -- a fixed top:14px
     * label in a 51.8px box is 2.8px above centre (1.5px at 1280; the contact
     * form's select 3.4px; the sign-in card 0.7-1.7px LOW). Measured in
     * Chromium after: 0.00px on every field.
     *
     * MUTATION: put `top:14px` back on any resting label rule -> red.
     */
    $checkout = preg_replace('/\s+/', ' ', qk8Css());
    expect($checkout)
        ->toContain('.kbb-checkout .fld.kbb-fl > label{position:absolute;inset-inline-start:15px;top:50%;transform:translateY(-50%);')
        ->toContain('.kbb-checkout .fld.kbb-fl:has(> textarea) > label{top:14px;transform:none}')
        ->toContain('.kbb-checkout .fld.kbb-fl select ~ label{ top:14px;transform:translateY(calc(-8px - var(--fld-gap,3px)));')
        ->toContain('select:has(option[value=""]:checked):not(:focus) ~ label{ top:50%;transform:translateY(-50%);');

    $contact = (string) file_get_contents(resource_path('views/store/partials/contact-hub-head.blade.php'));
    expect($contact)->toContain('.ctc-form .fld.kbb-fl > label{position:absolute;inset-inline-start:43px;top:50%;transform:translateY(-50%);')
        ->toContain('.ctc-form .fld.kbb-fl select ~ label{top:14px;transform:translateY(calc(-8px - var(--fld-gap,3px)));');

    $cart = (string) file_get_contents(resource_path('views/store/cart-inner.blade.php'));
    expect($cart)->toContain('.kbb-cartpage .coupon .fld.kbb-fl > label{position:absolute;inset-inline-start:41px;top:50%;transform:translateY(-50%);')
        ->toContain("~ label{\n  top:13px;transform:translateY(calc(-7px - var(--fld-gap,3px)));");

    $kbb = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($kbb)->toContain('.authcard .fld > label{top:50%;transform:translateY(-50%);')
        ->toContain('.authcard .fld input:is(:focus,:not(:placeholder-shown),:-webkit-autofill) ~ label{top:14px}')
        ->toContain('.fs-generous .authcard .fld input:is(:focus,:not(:placeholder-shown),:-webkit-autofill) ~ label{top:16px}');
});

/* ═════════════════ 5. the slim footer: Privacy policy, not Returns ═════════════════ */

it('links the checkout and cart footer to the Privacy policy instead of Returns, in English and Arabic, and leaves the main footer alone', function () {
    /*
     * MUTATION: put 'Returns Information' back as l2_text's default -> red;
     * drop the label() pass in partials/slim-footer -> the Arabic half is.
     */
    expect(SlimFooter::SCHEMA['l2_text'][2])->toBe('Privacy policy')
        ->and(SlimFooter::SCHEMA['l2_url'][2])->toBe('/privacy-policy/');

    $html = qk8Get('/checkout');
    expect($html)->toContain('<a href="/privacy-policy/">Privacy policy</a>')
        ->and($html)->not->toContain('Returns Information');

    app(SlimFooter::class)->save(['cart_on' => true]);
    qk8Forget();
    $cart = qk8Get('/cart');
    preg_match('#<footer class="kbb-slimfoot.*?</footer>#s', $cart, $m);
    expect($m[0] ?? '')->toContain('<a href="/privacy-policy/">Privacy policy</a>')->not->toContain('Returns');

    ArabicShop::on();
    ArabicShop::string('store.footer.link_privacy', 'سياسة الخصوصية');
    expect(qk8Get('/ar/checkout'))->toMatch('#<a href="/ar/privacy-policy/">سياسة الخصوصية</a>#');

    // The main site footer is not this setting and keeps its own links.
    $home = (string) test()->get('/')->assertOk()->getContent();
    expect($home)->toContain('<a href="/privacy-policy/">Privacy policy</a>');
});

it('moves a saved Returns link to Privacy policy only while it still holds the shipped value', function () {
    $migration = base_path('database/migrations/2027_10_16_120200_slim_footer_privacy_policy_for_returns.php');
    $settings = app(SettingsService::class);

    $settings->set(SlimFooter::PREFIX.'l2_text', 'Returns Information');
    $settings->set(SlimFooter::PREFIX.'l2_url', '/refund_returns/');
    qk8Forget();
    (require $migration)->up();
    qk8Forget();
    expect(app(SlimFooter::class)->get('l2_text'))->toBe('Privacy policy')
        ->and(app(SlimFooter::class)->get('l2_url'))->toBe('/privacy-policy/');

    // His own wording stays his.
    $settings->set(SlimFooter::PREFIX.'l2_text', 'Our terms');
    $settings->set(SlimFooter::PREFIX.'l2_url', '/terms-and-conditions/');
    qk8Forget();
    (require $migration)->up();
    qk8Forget();
    expect(app(SlimFooter::class)->get('l2_text'))->toBe('Our terms')
        ->and(app(SlimFooter::class)->get('l2_url'))->toBe('/terms-and-conditions/');
});

/* ═════════════════ 6. "Remember my shipping details" + the package ═════════════════ */

it('says "Remember my shipping details on this device" and moves the shipped Arabic only', function () {
    // MUTATION: put the old English back in InterfaceStrings -> red.
    expect(qk8Get('/checkout'))->toContain('Remember my shipping details on this device');

    // The live shop: the shipped Arabic, published by the owner.
    DB::table('translations')->updateOrInsert(
        ['locale' => 'ar', 'group' => \App\Models\Translation::GROUP_UI, 'item_id' => 0,
            'field' => \App\Services\Translation\TranslationStore::normaliseKey('store.checkout.remember_me')],
        ['value' => 'تذكّر بياناتي على هذا الجهاز', 'status' => \App\Models\Translation::STATUS_PUBLISHED,
            'source' => \App\Models\Translation::SOURCE_MACHINE, 'created_at' => now(), 'updated_at' => now()],
    );
    DB::table('translations')->where('locale', 'ar')
        ->where('field', \App\Services\Translation\TranslationStore::normaliseKey('store.checkout.head_back'))->delete();
    (require base_path('database/migrations/2027_10_16_120100_checkout_back_arrow_and_remember_arabic.php'))->up();

    $row = DB::table('translations')->where('locale', 'ar')
        ->where('field', \App\Services\Translation\TranslationStore::normaliseKey('store.checkout.remember_me'))->first();
    expect($row->value)->toBe('تذكّر تفاصيل الشحن على هذا الجهاز')
        ->and($row->status)->toBe(\App\Models\Translation::STATUS_PUBLISHED)
        ->and(DB::table('translations')->where('locale', 'ar')
            ->where('field', \App\Services\Translation\TranslationStore::normaliseKey('store.checkout.head_back'))->value('status'))
        ->toBe(\App\Models\Translation::STATUS_DRAFT);
});

it('writes his choices to the live shop once, and leaves anything he has stored since', function () {
    // MUTATION: drop a key from the migration's lists -> its expectation is red.
    $migration = base_path('database/migrations/2027_10_16_120000_checkout_plain_payment_boxes_back_arrow_no_whatsapp.php');
    (require $migration)->up();
    qk8Forget();

    $c = app(CheckoutPage::class);
    $w = app(WhatsAppButton::class)->all();
    expect($c->get('pay_bg'))->toBeFalse()->and($c->get('pay_unsel'))->toBe('grey')
        ->and($c->get('head_back'))->toBeTrue()->and($c->get('cline_on'))->toBeFalse()
        ->and($w['show_cart'])->toBeFalse()->and($w['show_checkout'])->toBeFalse()
        ->and(DB::table('settings')->where('key', 'checkoutpage_pay_bg')->exists())->toBeTrue()
        ->and(DB::table('settings')->where('key', 'waf_show_cart')->exists())->toBeTrue();

    qk8Save(['pay_bg' => true, 'head_back' => false]);
    app(WhatsAppButton::class)->save(['show_cart' => true]);
    qk8Forget();
    (require $migration)->up();
    qk8Forget();
    expect(app(CheckoutPage::class)->get('pay_bg'))->toBeTrue()
        ->and(app(CheckoutPage::class)->get('head_back'))->toBeFalse()
        ->and(app(WhatsAppButton::class)->all()['show_cart'])->toBeTrue();
});
