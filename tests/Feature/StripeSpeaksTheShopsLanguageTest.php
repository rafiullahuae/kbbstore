<?php

declare(strict_types=1);

/**
 * STRIPE IS TOLD WHAT LANGUAGE THIS SHOP IS IN. (Lane CX, task 1 — handed over
 * by Lane AR2, whose ArabicWalletRowTest closes by naming this gap.)
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * Neither stripe.elements() call on /checkout/ passed a `locale`, and neither
 * Stripe() constructor did either. Stripe's documented default for both is
 * `auto`, which means THE BROWSER'S language — not the shop's.
 *
 * So an Arabic shopper on /ar/checkout/ read this, on a phone set to English:
 *
 *     لم تتم عملية الدفع ولم يُخصم أي مبلغ. سلتك محفوظة …
 *     Your card was declined.
 *     أو ادفع عبر
 *     [ Buy with Apple Pay ]
 *
 * Four carefully translated Arabic sentences of ours wrapped around Stripe's
 * English one, and a wallet button labelled in a third language — at the exact
 * moment a payment has failed, which is the worst moment for the page to look
 * broken. It is not a fringe case: this shop trades in the UAE, where a phone
 * set to English and a customer reading Arabic is the ordinary arrangement.
 *
 * ── MUTATION NOTE (run, not asserted) ───────────────────────────────────────
 *
 * Take `{ locale: LOCALE }` off both Stripe() and stripe.elements() in
 * partials/checkout/stripe-elements.blade.php and the first two cases are red:
 * "Failed asserting that 2 is identical to 4", with the message naming the
 * fallback to `auto`. Take it off express-wallets instead and they are red the
 * same way. Make StripeLocale::current() return the raw Locale::current() and
 * the map case is red on `xx-YY` — which is the shape that reaches Stripe.js as
 * an error at boot, on the card fields, so it is an outage and not a cosmetic
 * fault.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use App\Support\StripeLocale;
use Illuminate\Support\Facades\Cache;

/**
 * Every place on the rendered checkout where Stripe is handed a language.
 *
 * FOUR, and they are two pairs rather than four of the same thing. Stripe
 * documents the constructor option as governing the ERROR STRINGS Stripe.js
 * METHODS return — `result.error.message` out of confirmCardPayment() and
 * confirmPayment(), which is where "Your card was declined" comes from — and
 * the elements() option as governing what is drawn INSIDE the iframes, which is
 * where the Apple Pay button label and the field placeholders come from. Both
 * partials need both, so the count is 2 objects x 2 partials.
 */
const CX_STRIPE_LOCALE_SITES = 4;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/** The card gateway keyed and on, with both wallet switches set. */
function cxStripeOn(): void
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);

    $row->fill(['title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $row->config = [
        'publishable_key' => 'pk_test_kbb_locale',
        'secret_key' => 'sk_test_kbb_locale',
        'webhook_signing_secret' => 'whsec_locale_signing',
        'webhook_secret' => 'whsec-url-locale-0123456789',
        'wallet_apple_pay' => '1',
        'wallet_google_pay' => '1',
    ];

    $row->save();

    /*
     * THE PROJECTION, WRITTEN EXPLICITLY, and the memos dropped after it.
     *
     * The wallet switches do not reach the checkout off the gateway row: they
     * reach it through the `wallets_offered` settings row that Wallets::project()
     * writes, which PaymentProvider::booted() fires on save and which did NOT
     * land here (measured: the row read null straight after ->save(), while
     * calling project() by hand wrote 'apple_pay,google_pay'). So it is called
     * the way the payments screen calls it. Then the memos: Setting::map() and
     * SettingsService both hold process-level copies — CLAUDE.md's named trap —
     * and without dropping them the page renders with no express row at all and
     * the wallet half of this file would pass by testing nothing.
     */
    app(Wallets::class)->project();

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
}

/** A one-line basket, and the browser that carries its cookie. */
function cxShopper()
{
    $product = Product::create([
        'slug' => 'cx-serum-'.uniqid(),
        'name' => 'Locale Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    // withCredentials() and the unencrypted cookie for the reason
    // CheckoutCardFormTest states at length: without them the checkout reads an
    // empty basket and the page under test is never rendered at all.
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** The Arabic storefront switched on, the way the admin screen switches it. */
function cxArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** How many Stripe objects on this page were handed the locale. */
function cxToldSites(string $html): int
{
    return substr_count($html, 'locale: LOCALE');
}

/** The one value both partials computed, as it was printed into the page. */
function cxLocaleValues(string $html): array
{
    preg_match_all('/var LOCALE\s*=\s*("[^"]*");/', $html, $m);

    return $m[1];
}

it('tells every Stripe object on the English checkout that this shop speaks English', function () {
    cxStripeOn();

    $html = cxShopper()->get('/checkout')->assertOk()->getContent();

    expect(cxToldSites($html))->toBe(
        CX_STRIPE_LOCALE_SITES,
        'A Stripe object on the checkout was not told the shop\'s language, so it falls back to `auto` — the browser\'s.'
    );

    // BOTH partials, and the SAME value in each: a checkout whose card fields
    // speak one language and whose Apple Pay button speaks another is the
    // defect half-fixed. Two `var LOCALE` declarations, one per script.
    expect(cxLocaleValues($html))->toBe(['"en"', '"en"']);
});

it('tells every Stripe object on the Arabic checkout that this shop speaks Arabic', function () {
    cxArabicOn();
    cxStripeOn();

    $html = cxShopper()->get('/ar/checkout')->assertOk()->getContent();

    // The page really is the Arabic one, so this case cannot pass by quietly
    // rendering the English checkout and finding "en" twice.
    expect(app()->getLocale())->toBe('ar');

    expect(cxToldSites($html))->toBe(CX_STRIPE_LOCALE_SITES);

    // THE ASSERTION THE DEFECT FAILED. Before this lane there was no `locale`
    // anywhere on this page, so Stripe drew "Buy with Apple Pay" and wrote
    // "Your card was declined" in whatever language the phone was set to.
    expect(cxLocaleValues($html))->toBe(['"ar"', '"ar"']);
});

it('leaves the English checkout byte-identical apart from the language it now states', function () {
    /*
     * RULE 1, from the other end. The only thing this lane may have changed on
     * the English checkout is the addition of the locale — the publishable key,
     * the three mount boxes and Stripe's own script tag are all untouched.
     */
    cxStripeOn();

    $html = cxShopper()->get('/checkout')->assertOk()->getContent();

    expect($html)->toContain('id="kbb-card-number"');
    expect($html)->toContain('id="kbb-card-expiry"');
    expect($html)->toContain('id="kbb-card-cvc"');
    expect($html)->toContain('https://js.stripe.com/v3');
    expect($html)->toContain('pk_test_kbb_locale');
    // One <script src> for Stripe.js and not two: express-wallets deliberately
    // waits for the card partial's copy rather than loading a second one.
    expect(substr_count($html, 'https://js.stripe.com/v3'))->toBe(1);
});

it('maps a locale Stripe knows, falls back deliberately for one it does not, and never says `auto` while the shop default is speakable', function () {
    /*
     * ── THE MAP, AND WHY IT IS NOT THE SHOP'S RAW STRING ────────────────────
     *
     * Stripe accepts `auto` plus a fixed list of tags and NOTHING ELSE; a tag it
     * does not know is a Stripe.js error at boot, on the card fields. Both of
     * this shop's locales happen to be tags Stripe lists, so passing the raw
     * string would work today and break the day somebody adds a row to
     * Locale::LOCALES — which Locale's own docblock invites ("adding French
     * later is a row here plus its translations").
     */
    expect(StripeLocale::current('en'))->toBe('en');
    expect(StripeLocale::current('ar'))->toBe('ar');

    // A regional tag Stripe does not list resolves to its base language, which
    // Stripe does. Never the other way round: `pt` must not become `pt-BR`,
    // because picking a region for a shop that named none is a decision this
    // class has no business making.
    expect(StripeLocale::current('pt-PT'))->toBe('pt');
    expect(StripeLocale::current('pt'))->toBe('pt');
    expect(StripeLocale::current('ZH_hk'))->toBe('zh-HK');

    /*
     * ── WHICH WAY THE FALLBACK GOES, SAID OUT LOUD ──────────────────────────
     *
     * DELIBERATELY NOT `auto`. `auto` is the defect itself — it is what the
     * checkout did before this lane and it is how a shopper ends up reading a
     * third language. A language Stripe cannot speak falls back to the shop's
     * DEFAULT language, which is what every unresolved string on this shop
     * already falls back to, so the decline reason matches the furniture around
     * it instead of the handset.
     */
    expect(StripeLocale::current('ur'))->toBe(StripeLocale::current(Locale::DEFAULT));
    expect(StripeLocale::current('ur'))->toBe('en');
    expect(StripeLocale::current('xx-YY'))->toBe('en');
    expect(StripeLocale::current('ur'))->not->toBe('auto');

    // And `auto` itself is not a language a shop can claim to be written in, so
    // it cannot be smuggled in through the locale code either.
    expect(StripeLocale::current('auto'))->toBe('en');

    // Whatever comes in, what goes out is a value Stripe lists — this is the
    // property the two views rely on to print it without a guard of their own.
    foreach (['en', 'ar', 'pt-PT', 'ur', 'xx-YY', '', 'auto', 'e', 'en-GB'] as $code) {
        expect(StripeLocale::SUPPORTED)->toContain(StripeLocale::current($code));
    }

    // speaks() is the honest half: it says when a shopper is getting the
    // fallback rather than their own language.
    expect(StripeLocale::speaks('ar'))->toBeTrue();
    expect(StripeLocale::speaks('ur'))->toBeFalse();
});

it('reads the request locale rather than a hard-coded one', function () {
    /*
     * The trap this would otherwise fall into: current() defaulting to 'en'
     * because that is what the test process happens to be set to. Drive it from
     * App::setLocale(), which is the one lever Locale::current() reads.
     */
    cxArabicOn();

    app()->setLocale('ar');
    expect(StripeLocale::current())->toBe('ar');

    app()->setLocale(Locale::DEFAULT);
    expect(StripeLocale::current())->toBe('en');
});
