<?php

declare(strict_types=1);

/*
 * /checkout/?kbbdiag=1 — the on-phone Apple Pay / Google Pay diagnostic. (Lane WL2.)
 *
 * THE SITUATION IT EXISTS FOR. 9 October 2026, after 2.60.453: wallets
 * enabled in Stripe, domains green, Apple's file served, cards in both phone
 * wallets, and still no button on iPhone Safari or Android Chrome. The
 * express row removes itself silently when Stripe offers no wallet, and Stripe
 * says why only in a console a phone does not show. The owner opens this
 * address and screenshots the box instead.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use Illuminate\Support\Str;

const WD_PK = 'pk_live_51OyUO7WDdiagSECRETTAILzzzz9';

function wdCheckout(string $query = ''): string
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);
    $row->fill(['title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'live', 'position' => 0]);
    $row->config = ['publishable_key' => WD_PK, 'secret_key' => 'sk_live_wd_secret_key_value',
        'wallet_apple_pay' => '1', 'wallet_google_pay' => '1'];
    $row->save();
    app(GatewayCredentials::class)->forget();
    // The projection lives in settings, which memoise per process (CLAUDE.md).
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    app(\App\Services\SettingsService::class)->flush();
    /*
     * Written by PaymentProvider's saved hook in production. In the FIRST test
     * of a process that hook is not on this application's dispatcher (the
     * model booted against an earlier one), so the projection was never
     * written and the row was not drawn -- measured: setting absent in test
     * one, present in tests two and three. Projected here explicitly.
     */
    app(Wallets::class)->project();
    app(Wallets::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $product = Product::create(['slug' => 'wd-'.uniqid(), 'name' => 'WD Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 200, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout'.$query)->assertOk()->getContent();
}

beforeEach(function () {
    PaymentProvider::query()->delete();
});

it('is off without the parameter: no box, no console wrapping', function (string $q) {
    $html = wdCheckout($q);

    expect($html)->toContain('data-kbb-express ')
        ->and($html)->not->toContain('id="kbbWalletDiag"')
        ->and($html)->not->toContain('window.kbbWalletDiag = {')
        ->and($html)->not->toContain('console.warn = saved.warn');
})->with(['kbbdiag=0' => ['?kbbdiag=0'], 'kbbdiag=yes' => ['?kbbdiag=yes'], 'no query' => ['']]);
// MUTATION, run: render the wallet-diag partial unconditionally. RED — and
// every shopper's checkout would wrap console.error and show the box.

it('is on with ?kbbdiag=1: the box, the server line, and every hook the script reports through', function () {
    $html = wdCheckout('?kbbdiag=1');

    expect(substr_count($html, 'id="kbbWalletDiag"'))->toBe(1)
        // Before the express script, so window.kbbWalletDiag exists when it reads it.
        ->and(strpos($html, 'window.kbbWalletDiag = {'))->toBeLessThan((int) strpos($html, 'var DIAG = window.kbbWalletDiag || null;'))
        ->and($html)->toContain('"row_drawn":true')
        ->and($html)->toContain('"apple_pay_offered":true')
        ->and($html)->toContain('"google_pay_offered":true')
        ->and($html)->toContain('"amount_fils":')
        ->and($html)->toContain('availablePaymentMethods = ')
        ->and($html)->toContain("log('loaderror: type ' + e.type + ' | message ' + e.message)")
        ->and($html)->toContain('ApplePaySession.canMakePayments()')
        ->and($html)->toContain("typeof window.PaymentRequest")
        ->and($html)->toContain('navigator.userAgent')
        ->and($html)->toContain('No wallet offered by Stripe on this browser')
        // The script reports into it at each step.
        ->and($html)->toContain('if (DIAG) { DIAG.boot(PK, groupOptions, eceOptions); }')
        ->and($html)->toContain('if (DIAG) { DIAG.ready(event); }')
        ->and($html)->toContain('if (DIAG) { DIAG.loadError(event); }')
        ->and($html)->toContain("if (DIAG) { DIAG.gone(ROW, DIVIDER, why || 'unknown'); return; }")
        // Console wrapped only here, and put back.
        ->and($html)->toContain('console.warn = saved.warn;')
        ->and($html)->toContain('console.error = saved.error;');
});
// MUTATION, run: drop `if (DIAG) { DIAG.gone(...); return; }` from gone().
// RED — and under diag the row would vanish with the evidence.

it('prints no key beyond its first 12 characters, and never the secret', function () {
    $html = wdCheckout('?kbbdiag=1');

    $start = strpos($html, 'id="kbbWalletDiag"');
    $end = strpos($html, '</script>', (int) $start);
    $diag = substr($html, (int) $start, (int) $end - (int) $start);

    expect($diag)->not->toContain(substr(WD_PK, 12))
        ->and($html)->not->toContain('sk_live_wd_secret_key_value')
        // The boot line slices the key, and captured console text is cut too.
        ->and($diag)->toContain("String(pk || '').slice(0, 12)")
        ->and($diag)->toContain('m.slice(0, 12)');
});
// MUTATION, run: log `pk` whole in boot(). RED on the slice expectation.
