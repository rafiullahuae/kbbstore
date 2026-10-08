<?php

declare(strict_types=1);

/**
 * Place order never answers with silence -- Lane PO hotfix.
 *
 * The owner, on Firefox for Android with 2.60.431: "the place order button
 * still not working, nothing happening upon click" -- "not even the placing
 * your order popup showing at all."
 *
 * Every Place order handler (placing-overlay's, stripe-elements' pay(),
 * checkout.js's) had early returns that said nothing where the shopper was
 * looking: a wrong box was handed to the browser's validation bubble, which
 * Firefox for Android does not reliably draw; "the card form is still
 * loading" was printed in the card box a screen above the button; a page
 * restored from the back/forward cache mid-payment kept the card leg `busy`
 * and returned on every press. And a box that is not rendered could block
 * the order with no way to fix it.
 *
 * Now: one shared answer (placing-overlay: window.KBB.placeCheck/placeNote)
 * puts words beside the button pressed and under the box; a box that is not
 * rendered never blocks; a card with no card script is said, not posted;
 * the card leg unlocks on a back/forward restore. And /checkout/?kbbdiag=1
 * draws a diagnostic panel so the owner's phone can show us what it sees.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = ['publishable_key' => 'pk_test_pns', 'secret_key' => 'sk_test_pns', 'webhook_signing_secret' => 'whsec_pns', 'webhook_secret' => 'whsec-url-pns-0123456789abcd'];
    $row->save();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function pnsCheckout(string $query = ''): string
{
    $product = Product::create(['slug' => 'pns-' . uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 120, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 12000]);

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout/' . $query)->assertOk()->getContent();
}

function pnsCode(string $partial): string
{
    $src = (string) file_get_contents(resource_path('views/partials/checkout/' . $partial . '.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/* ═════════════════════════ the handlers, read ══════════════════════════════ */

it('hands every wrong box to the one shared answer, in all three handlers', function () {
    /*
     * MUTATION, run: put `FORM.reportValidity(); return;` back in place of
     * check() in placing-overlay's click listener -> red here, and in Chromium
     * the empty-phone case shows nothing beside the button.
     */
    $overlay = pnsCode('placing-overlay');
    $card = pnsCode('stripe-elements');
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));

    expect($overlay)->toContain("if (!check(event.target, 'overlay')) { return; }")
        ->and($overlay)->toContain('window.KBB.placeCheck = check;')
        ->and($overlay)->not->toContain('FORM.reportValidity()')
        ->and($card)->toContain("if (!placeCheck(pressed, 'card')) return;")
        ->and($card)->toContain('pay(event.target);')
        ->and($js)->toContain("if (!window.KBB.placeCheck(event.target, 'checkout.js')) return;");
});

it('says "the card form is still loading" beside the button, not only in the card box', function () {
    // MUTATION, run: drop the placeNote(pressed, 'cardLoading') line -> red.
    $card = pnsCode('stripe-elements');
    $notReady = substr($card, (int) strpos($card, 'if (!ready) {'), 400);

    expect($notReady)->toContain('showError(TEXT.notReady);')
        ->and($notReady)->toContain("window.KBB.placeNote(pressed, 'cardLoading');");
});

it('never posts a card order the page cannot pay for', function () {
    /*
     * Stripe.js blocked: stripe-elements returns before it listens, the press
     * reached the overlay, and the overlay posted -- an order nobody could pay.
     * MUTATION, run: delete the cardLeg check -> red, and in Chromium the
     * blocked case posts /checkout/place.
     */
    expect(pnsCode('placing-overlay'))->toContain("if (method && method.value === 'stripe' && !(window.KBB && window.KBB.cardLeg)) {")
        ->and(pnsCode('stripe-elements'))->toContain('window.KBB.cardLeg = true;');
});

it('unlocks the card leg and the buttons when the page comes back from the back/forward cache', function () {
    /*
     * A page left mid-payment and restored by Back kept `busy` true: every
     * press returned at `if (busy)`, silently. MUTATION, run: delete the
     * pageshow listener at the foot of stripe-elements -> red.
     */
    expect(pnsCode('stripe-elements'))->toContain("if (event.persisted && busy) { busy = false; lock(false);")
        ->and(pnsCode('placing-overlay'))->toContain('if (event.persisted && busy) { down(); liven(); }');
});

it('never lets a box that is not rendered block the order', function () {
    // MUTATION, run: make blocking() return a hidden box -> red in Chromium.
    $overlay = pnsCode('placing-overlay');

    expect($overlay)->toContain("if (el.type === 'hidden' || el.closest('[hidden]')) { return false; }")
        ->and($overlay)->toContain('el.required = false;')
        // and no layout is measured to decide it
        ->and($overlay)->not->toMatch('/getBoundingClientRect|getClientRects|offset(Width|Height|Parent)|getComputedStyle|checkVisibility/');
});

it('switches the hidden account password OFF, so Firefox cannot autofill it', function () {
    /*
     * The owner found it on his phone (8 October): Firefox had filled a saved,
     * short password into the hidden "create an account" box; its minlength
     * made the form invalid and Place order did nothing. A DISABLED field is
     * never autofilled, never validated and never sent. MUTATION: drop
     * `pw.disabled = !box.checked;` from checkout.blade.php -> red.
     */
    $view = file_get_contents(resource_path('views/store/checkout.blade.php'));

    expect($view)->toContain('pw.disabled = !box.checked;')
        ->and($view)->toContain('pw.required = box.checked;');
});

/* ═════════════════════════ the words ═══════════════════════════════════════ */

it('has the words in English, and their Arabic as drafts with a seed', function () {
    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();
    $seed = (string) file_get_contents(database_path('migrations/2027_10_09_130000_seed_place_order_notice_arabic_drafts.php'));

    foreach (['place_check_field', 'place_card_unavailable', 'field_missing', 'field_bad_email', 'field_choose', 'field_bad_value'] as $key) {
        expect(__('store.checkout.' . $key))->not->toBe('store.checkout.' . $key)
            ->and($drafts)->toHaveKey('store.checkout.' . $key)
            ->and($seed)->toContain("'store.checkout.{$key}',");
    }

    expect(__('store.checkout.place_check_field', ['field' => 'Phone']))->toBe('Please check: Phone ↑');
});

/* ═════════════════════════ the diagnostic panel ════════════════════════════ */

it('draws the diagnostic panel only for ?kbbdiag=1', function () {
    /*
     * MUTATION, run: drop the query test around the include -> red: every
     * checkout carries the panel.
     */
    expect(pnsCheckout())->not->toContain('id="kbbDiag"')
        ->and(pnsCheckout('?kbbdiag=0'))->not->toContain('id="kbbDiag"');

    $diag = pnsCheckout('?kbbdiag=1');

    expect(substr_count($diag, 'id="kbbDiag"'))->toBe(1)
        // first in the page, so its error listener is up before the checkout's scripts
        ->and(strpos($diag, 'id="kbbDiag"'))->toBeLessThan((int) strpos($diag, 'id="kbbCheckoutForm"'));
});

it('sends nothing anywhere and reads no value from the diagnostic panel', function () {
    $src = (string) file_get_contents(resource_path('views/partials/checkout/diag.blade.php'));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

    expect($code)->not->toMatch('/fetch\(|XMLHttpRequest|sendBeacon|localStorage|sessionStorage|\.value\b(?!\s*:)/')
        ->and($code)->toContain("window.addEventListener('unhandledrejection'");
});

/* ═════════════════════════ in Chromium ═════════════════════════════════════ */

it('says why beside the button with no bubble, and still places a good order -- in Chromium', function () {
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $missing = [];
    if (! env('KBB_BROWSER_TESTS')) { $missing[] = 'KBB_BROWSER_TESTS is not set'; }
    if (! is_file($chrome)) { $missing[] = "no Chromium at {$chrome}"; }
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') { $missing[] = 'node is not on PATH'; }
    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    $rel = fn (string $html) => preg_replace('#(href|src)="https?://[^"]*/build/#', '$1="/build/', $html) ?? $html;
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0o777, true);
    $file = $dir . '/pns-' . getmypid() . '.html';
    $diag = $dir . '/pns-diag-' . getmypid() . '.html';
    file_put_contents($file, $rel(pnsCheckout()));
    file_put_contents($diag, $rel(pnsCheckout('?kbbdiag=1')));

    $env = 'KBB_PO_HTML=' . escapeshellarg($file) . ' KBB_PO_DIAG=' . escapeshellarg($diag)
        . ' KBB_PO_BUILD=' . escapeshellarg(base_path('public/build'))
        . ' KBB_BROWSER_CHROME=' . escapeshellarg($chrome)
        . ' NODE_PATH=' . escapeshellarg((string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'));
    $raw = (string) shell_exec($env . ' node ' . escapeshellarg(base_path('tests/browser/po-place-notice.mjs')) . ' 2>&1');
    @unlink($file);
    @unlink($diag);

    $r = json_decode($raw, true);
    expect($r)->toBeArray('no report: ' . substr($raw, 0, 600))->and($r['ok'] ?? false)->toBeTrue($r['error'] ?? '');
    $c = $r['cases'];

    foreach (['stripe', 'cod'] as $m) {
        $s = $c['emptyPhone_' . $m]['stopped'];
        expect($s['note'])->toBe('Please check: Phone ↑')
            ->and($s['noteRole'])->toBe('alert')->and($s['noteAfterButton'])->toBeTrue()
            ->and($s['fieldError'])->toBe('Please fill in Phone.')
            ->and($s['describedBy'])->toContain('billing_phone-kbberr')
            ->and($s['ariaInvalid'])->toBe('true')
            ->and($s['focused'])->toBe('billing_phone')
            ->and($s['overlay'])->toBeFalse()
            ->and($c['emptyPhone_' . $m]['posts'])->not->toContain('/checkout/place')
            // typing the number clears both lines
            ->and($c['emptyPhone_' . $m]['fixed']['note'])->toBeNull()
            ->and($c['emptyPhone_' . $m]['fixed']['fieldError'])->toBeNull()
            ->and($c['emptyPhone_' . $m]['errors'])->toBe([]);
    }

    expect($c['emirate']['stopped']['note'])->toBe('Please check: Emirate ↑')
        ->and($c['emirate']['stopped']['fieldError'])->toBe('Please choose your Emirate.');

    expect($c['hiddenInvalid']['invalidBefore'])->toBeTrue()
        ->and($c['hiddenInvalid']['url'])->toBe('/checkout/success');

    expect($c['cardMissing']['state']['note'])->toBe('Card payment could not load in this browser. Please choose another payment method.')
        ->and($c['cardMissing']['posts'])->not->toContain('/checkout/place');

    expect($c['valid_cod']['url'])->toBe('/checkout/success')->and($c['valid_cod']['errors'])->toBe([])
        ->and($c['valid_stripe']['url'])->toBe('/checkout/success')->and($c['valid_stripe']['errors'])->toBe([]);

    expect($c['diag']['log'])->toContain('browser: Mozilla/5.0 (Android 14; Mobile; rv:131.0)')
        ->and($c['diag']['log'])->toContain('card: listening')
        ->and($c['diag']['log'])->toContain('click: target button.place[data-place]')
        ->and($c['diag']['log'])->toContain('card: stopped at billing_phone')
        ->and($c['diag']['errors'])->toBe([]);
});
