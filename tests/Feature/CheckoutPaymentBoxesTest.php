<?php

declare(strict_types=1);

/*
 * Lane PY -- the checkout's payment boxes: official Tabby / Tamara logos and
 * each box in its gateway's colours ("A · Soft tint", which the owner picked
 * from four previews -- docs/PY-PAYMENT-PREVIEWS.md), with
 * Appearance → Checkout page → Payment boxes as the way to tune it or to put
 * Today's plain boxes back.
 *
 * What the shop looked like before: four white boxes, the chosen one pale
 * green, with the right-hand side of every header row empty -- the space the
 * owner circled on his screenshot for the logo.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Support\PaymentMarkArt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 1500, 'enabled' => true, 'position' => 0]);

    $fake = 'test-not-a-real-key';
    foreach ([
        ['tabby', 'Tabby Installments', 0, ['public_key' => 'pk_test_'.$fake, 'secret_key' => 'sk_test_'.$fake]],
        ['tamara', 'Pay later with Tamara', 1, ['api_token' => $fake, 'notification_token' => $fake]],
        ['stripe', 'Credit or debit card', 2, ['secret_key' => 'sk_test_'.$fake, 'publishable_key' => 'pk_test_'.$fake]],
        ['cod', 'Cash on delivery', 3, []],
    ] as [$id, $title, $pos, $config]) {
        PaymentProvider::create(['id' => $id, 'title' => $title, 'enabled' => true, 'mode' => 'test',
            'position' => $pos, 'config' => $config]);
    }
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function payBoxesSave(array $values): void
{
    app(CheckoutPage::class)->save($values);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
}

function payBoxesCheckout(string $prefix = ''): string
{
    $product = Product::create(['slug' => 'pb-'.uniqid(), 'name' => 'Glass Skin Set', 'status' => 'publish',
        'is_visible' => true, 'price' => 375, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 37500]);

    return test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($prefix.'/checkout')->assertOk()->getContent();
}

/** The <li> of one gateway, open tag to close. */
function payBoxesItem(string $html, string $id): string
{
    preg_match('#<li class="wc_payment_method payment_method_'.$id.'[^"]*">.*?</li>#s', $html, $m);

    return $m[0] ?? '';
}

function payBoxesSection(string $html): string
{
    preg_match('#<section class="kbb-checkout[^"]*"[^>]*>#', $html, $m);

    return $m[0] ?? '';
}

/* ------------------------------------------------------------------------
 | 1. What ships: A, on, with every gateway's logo
 |------------------------------------------------------------------------*/

it('ships the soft-tint boxes ON, each gateway with its own logo at the end of its row', function () {
    $html = payBoxesCheckout();

    expect(payBoxesSection($html))->toContain(' cop-pay')
        ->and(payBoxesSection($html))->not->toContain('style="'); // 26px is the stylesheet's own fallback

    foreach (['tabby', 'tamara', 'stripe', 'cod'] as $id) {
        $li = payBoxesItem($html, $id);

        expect($li)->toContain('payment_method_'.$id.' pay-brand"')
            ->and($li)->toContain('<span class="pay-logo pay-logo-'.$id.'" aria-hidden="true">'.PaymentMarkArt::checkoutLogo($id).'</span>')
            // the logo is inside the label, after the title: the end of the header row
            ->and(strpos($li, 'pay-logo'))->toBeLessThan(strpos($li, '</label>'));
    }

    // The official artwork, not the trust row's drawings: Tabby's badge
    // gradient and Tamara's pastel stops, as their SDK files carry them.
    expect(payBoxesItem($html, 'tabby'))->toContain('#3BFF9D')->and(payBoxesItem($html, 'tabby'))->toContain('#3BFFC8')
        ->and(payBoxesItem($html, 'tamara'))->toContain('#AAE1FF')->and(payBoxesItem($html, 'tamara'))->toContain('#F0826B');
});
// MUTATION: change SCHEMA['pay_style'][2] back to 'plain'. RED: no cop-pay, no logos.

it('draws each logo with ids no other logo on the page shares', function () {
    /* Two inline SVGs that both say id="g1" paint the second with the FIRST
       one's gradient: before the prefixes, Tamara's badge came out mint. */
    $html = payBoxesCheckout();
    preg_match('#<ul class="wc_payment_methods.*?</ul>#s', $html, $list);
    preg_match_all('/\bid="([^"]+)"/', $list[0], $ids);

    expect(count($ids[1]))->toBeGreaterThan(8)
        ->and(count($ids[1]))->toBe(count(array_unique($ids[1])));

    // ...and every url(#x) points at an id that IS in the list.
    preg_match_all('/url\(#([^)]+)\)/', $list[0], $refs);
    expect(array_diff($refs[1], $ids[1]))->toBe([]);

    // Every variant, Latin and Arabic, badge and wordmark: no two share an id.
    $all = '';
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$ar, $word]) {
        $all .= PaymentMarkArt::checkoutLogo('tamara', $ar, $word);
    }
    preg_match_all('/\bid="([^"]+)"/', $all.PaymentMarkArt::checkoutLogo('tabby'), $every);
    expect(count($every[1]))->toBe(count(array_unique($every[1])));
});
// MUTATION: drop the `kpltm` prefix from TAMARA_BADGE's ids. RED (g1 twice).

it('keeps the logos self-contained constants: no URL, no image, no script', function () {
    foreach (['tabby', 'tamara', 'stripe', 'cod'] as $id) {
        foreach ([false, true] as $ar) {
            foreach ([false, true] as $word) {
                $svg = PaymentMarkArt::checkoutLogo($id, $ar, $word);

                expect($svg)->toStartWith('<svg aria-hidden="true" focusable="false" ')
                    ->and($svg)->toEndWith('</svg>')
                    ->and(strtolower($svg))->not->toContain('http')
                    ->and(strtolower($svg))->not->toContain('<image')
                    ->and(strtolower($svg))->not->toContain('href')
                    ->and(strtolower($svg))->not->toContain('<script')
                    ->and(strtolower($svg))->not->toContain(' on');
            }
        }
    }

    expect(PaymentMarkArt::checkoutLogo('paypal'))->toBe('')
        ->and(PaymentMarkArt::checkoutLogo('"><script>'))->toBe('');
});

/* ------------------------------------------------------------------------
 | 2. "Today" is the way back, byte for byte
 |------------------------------------------------------------------------*/

it('renders the payment list under "Today" byte for byte as the partial did before', function () {
    /*
     * The "before" is this partial with this lane's two insertions cut out
     * again, so the comparison stays honest when somebody later edits an
     * unrelated line of it -- a SHA would not survive the next rebase.
     */
    $source = (string) file_get_contents(resource_path('views/partials/checkout/payment-methods.blade.php'));
    $brand = "{{ empty(\$payBoxes['brand'][\$g['id']]) ? '' : ' pay-brand' }}";
    $logo = "{!! empty(\$payBoxes['logos'][\$g['id']]) ? '' : '<span class=\"pay-logo pay-logo-'.e(\$g['id']).'\" aria-hidden=\"true\">'.\$payBoxes['logos'][\$g['id']].'</span>' !!}";

    expect(substr_count($source, $brand))->toBe(1)
        ->and(substr_count($source, $logo))->toBe(1);

    $dir = sys_get_temp_dir().'/kbb-pay-before-'.getmypid();
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/paybefore.blade.php', str_replace([$brand, $logo], '', $source));
    view()->addLocation($dir);

    $data = ['gateways' => [
        ['id' => 'tabby', 'title' => 'Tabby Installments', 'description' => 'Split into 4.', 'fee_html' => null, 'fee_fils' => 0],
        ['id' => 'tamara', 'title' => 'Pay later with Tamara', 'description' => 'Split in up to 4.', 'fee_html' => null, 'fee_fils' => 0],
        ['id' => 'cod', 'title' => 'Cash on delivery', 'description' => null, 'fee_html' => '+AED 10.00', 'fee_fils' => 1000],
    ], 'codHidden' => null, 'selectedMethod' => 'tamara'];

    payBoxesSave(['pay_style' => 'plain', 'pay_logo_h' => 32, 'pay_tint' => 'strong']);

    $today = view('partials.checkout.payment-methods', $data)->render();
    $before = view('paybefore', $data)->render();

    expect($today)->toBe($before)
        ->and($today)->not->toContain('pay-');

    // And with A on, the same data DOES differ -- the comparison can see.
    payBoxesSave(['pay_style' => 'soft']);
    expect(view('partials.checkout.payment-methods', $data)->render())->not->toBe($before);
});
// MUTATION: make paymentBoxes() ignore pay_style. RED on toBe($before).

it('adds nothing to the page shell under "Today", whatever the other controls say', function () {
    payBoxesSave(['pay_style' => 'plain', 'pay_logo_h' => 30, 'pay_tint' => 'strong', 'pay_border' => 'pink']);
    $section = payBoxesSection(payBoxesCheckout());

    expect($section)->not->toContain('cop-pay')
        ->and($section)->not->toContain('--cop-paylogo');
});
// MUTATION: remove `$this->payBoxesOn($c) &&` from cssVariables(). RED on --cop-paylogo.

/* ------------------------------------------------------------------------
 | 3. Every control, and every control fails closed
 |------------------------------------------------------------------------*/

it('turns each control into a class, a px value or a choice of constant -- nothing else', function () {
    payBoxesSave(['pay_logo_h' => 30, 'pay_tint' => 'strong', 'pay_border' => 'pink',
        'pay_tamara' => false, 'pay_tamara_logo' => 'wordmark']);
    $html = payBoxesCheckout();
    $section = payBoxesSection($html);

    expect($section)->toContain('cop-pay cop-pay-strong cop-pay-bpink')
        ->and($section)->toContain('--cop-paylogo:30px')
        // Tamara kept plain: no brand class, logo still there, and the wordmark
        ->and(payBoxesItem($html, 'tamara'))->not->toContain('pay-brand')
        ->and(payBoxesItem($html, 'tamara'))->toContain(PaymentMarkArt::checkoutLogo('tamara', false, true))
        ->and(payBoxesItem($html, 'tabby'))->toContain('pay-brand');

    payBoxesSave(['pay_tint' => 'light', 'pay_border' => 'solid']);
    expect(payBoxesSection(payBoxesCheckout()))->toContain('cop-pay cop-pay-light cop-pay-bsolid');
});

it('stores only its own options and clamps the size, so nothing a request sends reaches the page', function () {
    payBoxesSave([
        'pay_style' => 'evil" onload="x',
        'pay_tint' => '<script>',
        'pay_border' => 'url(javascript:1)',
        'pay_tamara_logo' => '../../.env',
        'pay_logo_h' => 999,
    ]);
    $c = app(CheckoutPage::class)->all();

    expect($c['pay_style'])->toBe('soft')
        ->and($c['pay_tint'])->toBe('medium')
        ->and($c['pay_border'])->toBe('gradient')
        ->and($c['pay_tamara_logo'])->toBe('badge')
        ->and($c['pay_logo_h'])->toBe(32);

    payBoxesSave(['pay_logo_h' => -5]);
    expect(app(CheckoutPage::class)->get('pay_logo_h'))->toBe(20)
        ->and(payBoxesSection(payBoxesCheckout()))->toContain('--cop-paylogo:20px');
});

it('drops every logo, and the size with it, when Show logos is off', function () {
    payBoxesSave(['pay_logos' => false, 'pay_logo_h' => 30]);
    $html = payBoxesCheckout();

    expect($html)->not->toContain('class="pay-logo')
        ->and(payBoxesSection($html))->not->toContain('--cop-paylogo')
        // the colours stay: logos and tint are separate controls
        ->and(payBoxesItem($html, 'tabby'))->toContain('pay-brand');
});

it('gives the Arabic shop Tamara\'s Arabic artwork', function () {
    $s = app(\App\Services\SettingsService::class);
    $s->set(\App\Support\Locale::SETTING_ENABLED, true);
    $s->set(\App\Support\Locale::SETTING_RTL, true);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $html = payBoxesCheckout('/ar');

    expect(payBoxesItem($html, 'tamara'))->toContain(PaymentMarkArt::checkoutLogo('tamara', true))
        ->and(PaymentMarkArt::checkoutLogo('tamara', true))->not->toBe(PaymentMarkArt::checkoutLogo('tamara'));
});
// MUTATION: pass `false` for $arabic in paymentBoxes(). RED.

it('puts the ten controls on their own tab, Appearance → Checkout page → Payment boxes', function () {
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'pb-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'owner']);

    $tabs = test()->actingAs($owner, 'admin')->getJson('/admin-api/checkout-page')->assertOk()->json('tabs');
    $tab = collect($tabs)->firstWhere('key', 'payments');

    expect($tab)->not->toBeNull()
        ->and($tab['label'])->toBe('Payment boxes')
        ->and(collect($tab['fields'])->pluck('key')->all())->toBe([
            'pay_style', 'pay_logos', 'pay_logo_h', 'pay_tint', 'pay_border',
            'pay_tabby', 'pay_tamara', 'pay_card', 'pay_cod', 'pay_tamara_logo',
        ]);

    // the defaults ARE A as previewed
    $d = collect($tab['fields'])->mapWithKeys(fn ($f) => [$f['key'] => $f['value']])->all();
    expect($d)->toMatchArray(['pay_style' => 'soft', 'pay_logos' => true, 'pay_logo_h' => 26, 'pay_tint' => 'medium',
        'pay_border' => 'gradient', 'pay_tabby' => true, 'pay_tamara' => true, 'pay_card' => true, 'pay_cod' => true,
        'pay_tamara_logo' => 'badge']);
});

/* ------------------------------------------------------------------------
 | 4. The stylesheet, and the cost
 |------------------------------------------------------------------------*/

it('carries the A rules, the Arabic-gap fix and the rounded corners, only under cop-pay', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    // THE ARABIC GAP: the label's 44px was physical, so in Arabic it sat at the
    // logo's end of the row and pushed the logo 29px in (measured x82 -> x53).
    expect($css)->toContain('.kbb-checkout.cop-pay .wc_payment_methods li.wc_payment_method > label:has(> .pay-logo){gap:10px;padding-inline-end:15px!important}')
        // SQUARE CORNERS: the chosen box lost its bottom radius, a cut-off look once coloured
        ->and($css)->toContain('.kbb-checkout.cop-pay .wc_payment_methods li.wc_payment_method:has(input:checked){border-end-start-radius:12px;border-end-end-radius:12px}')
        // the slot is a fixed box: no shift when the artwork paints
        ->and($css)->toContain('block-size:var(--cop-paylogo,26px);inline-size:calc(var(--cop-paylogo,26px) * 3.24)');

    // Choosing a box never changes the border's WIDTH (1.6px today), so the
    // list does not move by a pixel on select.
    preg_match_all('/cop-pay[^{]*:has\(input:checked\)\{([^}]*)\}/', $css, $sel);
    foreach ($sel[1] as $body) {
        expect($body)->not->toMatch('/border(-width)?:\s*[\d.]+px/');
    }

    // Every new rule is scoped: nothing reaches the page under "Today".
    $from = strpos($css, 'PAYMENT BOXES -- "A');
    $block = substr($css, $from, strpos($css, 'real WooCommerce place-order') - $from);
    $block = (string) preg_replace('#/\*.*?\*/#s', '', substr($block, strpos($block, '*/') + 2));
    preg_match_all('/(?:^|\})\s*([^{}]+)\{/', $block, $selectors);
    expect(count($selectors[1]))->toBeGreaterThan(15);
    foreach ($selectors[1] as $selector) {
        foreach (explode(',', $selector) as $one) {
            expect(trim($one))->toMatch('/^\.kbb-checkout\.cop-pay/');
        }
    }

    // and the built stylesheet the server ships is this one
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-checkout.css']['file']));
    expect($built)->toContain('cop-pay')->and($built)->toContain('--cop-paylogo');
});
// MUTATION: change the gap fix to `padding-right:15px`. RED (and wrong in Arabic).

it('costs no query: A and Today render the checkout with the same number', function () {
    payBoxesCheckout(); // warm: first render compiles views and fills caches

    payBoxesSave(['pay_style' => 'soft']);
    DB::flushQueryLog();
    DB::enableQueryLog();
    payBoxesCheckout();
    $soft = count(DB::getQueryLog());

    payBoxesSave(['pay_style' => 'plain']);
    DB::flushQueryLog();
    payBoxesCheckout();
    $plain = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($soft)->toBe($plain);
});
