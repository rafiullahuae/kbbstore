<?php

declare(strict_types=1);

/**
 * Lane CP - VAT rates that vary by country, still display-only (D-64).
 *
 * WHAT THIS FEATURE IS, AND WHAT IT DELIBERATELY IS NOT.
 *
 * App\Support\VatDisplay computes a line that is PRINTED and never CHARGED.
 * Decision D-64 says so, the checkout partial repeats it, and nothing here
 * changes it: a per-country rate moves the figure on the receipt and moves
 * nothing else. On an inclusive basis a HIGHER rate therefore means a SMALLER
 * net take on the same order, because the gross the shopper pays is fixed. The
 * owner was told this in writing; these tests exist so the property cannot be
 * quietly lost later by someone "fixing" VAT to be chargeable.
 *
 * WHY THE SETTING IS A JSON STRING AND NOT AN ARRAY.
 *
 * AdminController::checkSetting() refuses arrays and objects outright - the
 * settings column is text and a nested value is not a setting. So the map
 * travels the wire as a JSON object string and is validated key by key by a
 * rule of its own. That rule matters more than it looks: SETTING_RULES carries
 * a standing warning that a key with NO rule is dropped from the payload while
 * the endpoint still answers "ok", so the screen reports success and writes
 * nothing. The round-trip test below is what pins that this key is not in that
 * state.
 *
 * SETTINGS ARE ONLY EVER WRITTEN THROUGH THE SERVICE. SettingsService holds a
 * forever-cache AND a process-level memo, and Setting::map() holds a third,
 * which is the trap CLAUDE.md records. vatSet() goes through the service and
 * drops every one of them, so a write made by a test is visible to the next
 * read inside the same Pest process.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\VatDisplay;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    \App\Models\PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    // The UAE at AED 20 and the Gulf at AED 150 - the same miniature of
    // production's two zones ShopperPathTruthTest builds, so a country change
    // in these tests moves a real delivery charge as well as the VAT line.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Every settings write goes through the service - see the header. */
function vatSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
}

function vatDisplay(): VatDisplay
{
    return app(VatDisplay::class);
}

function vatCart(int $unitPriceFils = 13000, int $qty = 1): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => Product::create([
            'slug' => 'vat-' . Str::random(8),
            'name' => 'Rice Probiotics Toner',
            'status' => 'publish',
            'is_visible' => true,
            'price' => $unitPriceFils,
            'stock_status' => 'instock',
        ])->id,
        'quantity' => $qty,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

/** A browser carrying this cart's cookie. */
function vatShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function vatAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'VAT Owner',
        'email' => 'vat-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Does the markup carry an ELEMENT with this class? Never a bare string search. */
function vatHasElement(string $html, string $class): bool
{
    return (bool) preg_match('/<[a-z]+[^>]*class="[^"]*\b' . preg_quote($class, '/') . '\b/i', $html);
}

/*
|------------------------------------------------------------------------------
| 1. The empty table is today's behaviour, exactly
|------------------------------------------------------------------------------
*/

it('ships with no per-country rates at all', function () {
    /*
     * The owner wrote "Saudi there's 15% i think". A guess is not a tax rate,
     * and a wrong rate printed on a receipt is worse than no rate, so nothing
     * is seeded: the table is empty until he fills it in, and until then every
     * country reads the single global figure exactly as it did before.
     */
    expect(vatDisplay()->countryRates())->toBe([]);

    expect(Setting::query()->where('key', 'vat_country_rates')->exists())
        ->toBeFalse('a per-country VAT rate was seeded; rates must come from the owner, not from us');
});

it('falls back to the global rate for every country while the table is empty', function () {
    vatSet('vat_rate', '5');

    $vat = vatDisplay();

    expect($vat->rate())->toBe(5.0);

    foreach (['AE', 'SA', 'KW', 'QA', 'BH', 'OM', 'GB'] as $code) {
        expect($vat->rate($code))->toBe(5.0, "empty table changed the rate for {$code}");
    }
});

it('keeps every existing call site working with no country argument', function () {
    /*
     * rate(), amount(), label() and line() all gained an optional country. The
     * default has to be null so the callers that do not know a country - the
     * product page, the invoice, the order email - keep compiling and keep
     * answering exactly what they answered before.
     */
    vatSet('vat_rate', '5');
    vatSet('vat_basis', 'inclusive');

    $vat = vatDisplay();

    // AED 100 inclusive of 5% is 100 x 5 / 105 = 4.7619 -> 476 fils.
    expect($vat->amount(10000))->toBe(476);
    expect($vat->label())->toBe("You're paying VAT (5%)");
    expect($vat->line(10000)['amount'])->toBe(476);
});

/*
|------------------------------------------------------------------------------
| 2. A country override, and only for that country
|------------------------------------------------------------------------------
*/

it('uses a country override where one is set and the global rate everywhere else', function () {
    vatSet('vat_rate', '5');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    $vat = vatDisplay();

    expect($vat->rate('SA'))->toBe(15.0);
    expect($vat->rate('AE'))->toBe(5.0, 'a Saudi override leaked into the UAE');
    expect($vat->rate())->toBe(5.0, 'a Saudi override changed the global rate');
});

it('reads a country code in any case the caller happens to have it in', function () {
    vatSet('vat_rate', '5');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    expect(vatDisplay()->rate('sa'))->toBe(15.0);
});

it('moves the printed amount and the printed label together', function () {
    /*
     * THE LABEL CARRIES THE RATE. vat_label is "You're paying VAT ({rate}%)"
     * and VatDisplay::label() substitutes {rate}. A per-country rate that moved
     * the AMOUNT and left the LABEL alone would print "You're paying VAT (5%)"
     * beside a 15% figure - a receipt that contradicts itself, which is worse
     * than not varying at all.
     */
    vatSet('vat_rate', '5');
    vatSet('vat_basis', 'inclusive');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    $vat = vatDisplay();

    // AED 100 inclusive of 15% is 100 x 15 / 115 = 13.04 -> 1304 fils.
    expect($vat->amount(10000, 'SA'))->toBe(1304);
    expect($vat->label('SA'))->toBe("You're paying VAT (15%)");

    expect($vat->amount(10000, 'AE'))->toBe(476);
    expect($vat->label('AE'))->toBe("You're paying VAT (5%)");

    $line = $vat->line(10000, 'SA');
    expect($line['amount'])->toBe(1304);
    expect($line['label'])->toBe("You're paying VAT (15%)");
});

it('honours the flat basis per country too', function () {
    vatSet('vat_rate', '5');
    vatSet('vat_basis', 'flat');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    expect(vatDisplay()->amount(10000, 'SA'))->toBe(1500);
    expect(vatDisplay()->amount(10000, 'AE'))->toBe(500);
});

it('ignores junk in the stored map rather than printing it', function () {
    /*
     * Anything could be in this row - an older build, a hand-edited database,
     * a half-applied package. A reader that trusted it would print a nonsense
     * percentage on a receipt. Unknown countries and unparseable rates are
     * dropped and the global rate stands.
     */
    vatSet('vat_rate', '5');
    vatSet('vat_country_rates', json_encode([
        'SA' => '15',
        'ZZ' => '99',        // not a country this shop knows
        'KW' => 'abc',       // not a number
        'QA' => '150',       // not a percentage
    ]));

    $rates = vatDisplay()->countryRates();

    expect($rates)->toBe(['SA' => 15.0]);
    expect(vatDisplay()->rate('KW'))->toBe(5.0);
    expect(vatDisplay()->rate('QA'))->toBe(5.0);
});

/*
|------------------------------------------------------------------------------
| 3. Display only. This is the property the owner is deciding about.
|------------------------------------------------------------------------------
*/

it('changes nothing but the printed line when a country rate is raised', function () {
    /*
     * D-64, pinned. The Saudi shopper with a 15% rate pays the same total as
     * the Saudi shopper with a 5% rate; only the figure on the receipt moves.
     * If this ever fails, someone has made VAT chargeable - which is a business
     * decision belonging to the owner and recorded as D-64, not a bug to fix
     * here.
     */
    vatSet('vat_rate', '5');
    vatSet('vat_basis', 'inclusive');

    $cart = vatCart();
    $carts = app(CartService::class);

    $before = $carts->totals($cart, 'SA', 'Riyadh', 15000);

    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    $after = $carts->totals($cart, 'SA', 'Riyadh', 15000);

    expect($after['total'])->toBe($before['total'], 'a per-country VAT rate changed what the shopper is charged (D-64)');
    expect($after['subtotal'])->toBe($before['subtotal']);
    expect($after['shipping'])->toBe($before['shipping']);

    // The line itself, and only the line, moved.
    expect($after['vat']['amount'])->toBeGreaterThan($before['vat']['amount']);
});

it('threads the destination country into the cart totals it already resolves', function () {
    vatSet('vat_rate', '5');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    $cart = vatCart();
    $carts = app(CartService::class);

    $ae = $carts->totals($cart, 'AE', 'Dubai', 2000);
    $sa = $carts->totals($cart, 'SA', 'Riyadh', 15000);

    expect($ae['vat']['label'])->toBe("You're paying VAT (5%)");
    expect($sa['vat']['label'])->toBe("You're paying VAT (15%)");
});

/*
|------------------------------------------------------------------------------
| 4. The country-change refresh on checkout
|------------------------------------------------------------------------------
*/

it('sends the destination country rate back from the country-change endpoint', function () {
    /*
     * The shopper who lands as the UAE, reads "You're paying VAT (5%)" and then
     * picks Saudi Arabia must not still be reading the UAE figure. The country
     * selector does not reload the page - it posts to /api/checkout/rates and
     * swaps fragments in - so that endpoint is where the new country has to
     * reach VatDisplay.
     */
    vatSet('vat_rate', '5');
    vatSet('vat_country_rates', json_encode(['SA' => '15']));

    $cart = vatCart();

    $ae = vatShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Dubai'])
        ->assertOk()->json();
    $sa = vatShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA', 'state' => 'Riyadh'])
        ->assertOk()->json();

    expect($ae['vat']['label'])->toBe("You're paying VAT (5%)");
    expect($sa['vat']['label'])->toBe("You're paying VAT (15%)",
        'the country-change endpoint returned the old country\'s VAT label');

    expect($ae['vat']['formatted'])->not->toBe($sa['vat']['formatted'],
        'the country-change endpoint returned the same VAT amount for two different rates');
});

it('gives the checkout VAT label a hook of its own for the refresh to write into', function () {
    /*
     * checkout.js updated `.js-vat` - the AMOUNT - and nothing else, while the
     * endpoint had been sending `vat.label` all along. With one global rate the
     * label never changed, so the omission was invisible. With per-country
     * rates it prints "You're paying VAT (5%)" next to a 15% figure. The label
     * needs an element the refresh can address.
     */
    vatSet('vat_rate', '5');

    $rendered = vatShopper(vatCart())->get('/checkout/')->assertOk()->getContent();

    expect(vatHasElement($rendered, 'js-vat-label'))
        ->toBeTrue('the checkout VAT label has no hook, so a country change cannot update the percentage it prints');
});

it('has the country-change refresh write the VAT label as well as the amount', function () {
    /*
     * A source assertion, because this half runs in the browser. The regex
     * guard reads comments and quoted strings as code, so this looks for the
     * selector being queried, not for prose about it.
     */
    $js = file_get_contents(base_path('resources/js/kbb/checkout.js'));

    expect(str_contains($js, "querySelectorAll('.js-vat-label')"))
        ->toBeTrue('checkout.js updates the VAT amount on a country change but never the label beside it');
});

/*
|------------------------------------------------------------------------------
| 5. The settings round trip - the "reports success and writes nothing" trap
|------------------------------------------------------------------------------
*/

it('actually writes the per-country rates instead of reporting success and dropping them', function () {
    /*
     * SETTING_RULES carries a standing warning: a key that is not on it is
     * dropped from the payload and the endpoint still answers ok, so the screen
     * says "Saved" and nothing reaches the database. This is the test that pins
     * vat_country_rates out of that state - it asserts the CONSEQUENCE, that a
     * reader sees the new rate, not merely that a 200 came back.
     */
    vatAdmin();
    vatSet('vat_rate', '5');

    $response = test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => json_encode(['SA' => '15', 'AE' => '5'])],
    ])->assertOk();

    expect($response->json('rejected'))->toBeNull('vat_country_rates was rejected by the settings allowlist');
    expect($response->json('saved'))->toBe(1);

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(vatDisplay()->rate('SA'))->toBe(15.0, 'the endpoint answered ok and wrote nothing');
    expect(vatDisplay()->rate('AE'))->toBe(5.0);
});

it('accepts an empty table and clears any rates already saved', function () {
    vatAdmin();

    test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => json_encode(['SA' => '15'])],
    ])->assertOk();

    SettingsService::forgetMemo();
    expect(vatDisplay()->rate('SA'))->toBe(15.0);

    test()->putJson('/admin-api/settings', ['settings' => ['vat_country_rates' => '{}']])->assertOk();

    SettingsService::forgetMemo();
    expect(vatDisplay()->countryRates())->toBe([], 'the owner could not empty the table again');
});

it('refuses a rate that is not a percentage and saves nothing at all', function () {
    vatAdmin();

    $response = test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => json_encode(['SA' => 'fifteen'])],
    ])->assertStatus(422);

    expect($response->json('errors.vat_country_rates'))->toBeArray();

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(vatDisplay()->countryRates())->toBe([], 'a refused payload was written anyway');
});

it('refuses a rate above one hundred per cent', function () {
    vatAdmin();

    test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => json_encode(['SA' => '150'])],
    ])->assertStatus(422);

    SettingsService::forgetMemo();
    expect(vatDisplay()->countryRates())->toBe([]);
});

it('refuses a country the shop does not know', function () {
    vatAdmin();

    $response = test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => json_encode(['ZZ' => '15'])],
    ])->assertStatus(422);

    $message = (string) $response->json('message');

    expect(str_contains($message, 'ZZ'))->toBeTrue("the refusal did not name the country at fault: {$message}");
});

it('refuses something that is not a map at all', function () {
    vatAdmin();

    test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_rates' => 'not json'],
    ])->assertStatus(422);
});

it('keeps the global rate field working exactly as it did', function () {
    /*
     * "All countries at once" is not a new control - it is vat_rate, already on
     * the Business Details screen. Changing it must keep doing what it always
     * did, for every country without an override of its own.
     */
    vatAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['vat_rate' => '7.5']])->assertOk();

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(vatDisplay()->rate())->toBe(7.5);
    expect(vatDisplay()->rate('KW'))->toBe(7.5, 'the global rate stopped reaching countries without an override');
});

/*
|------------------------------------------------------------------------------
| 6. The admin screen
|------------------------------------------------------------------------------
*/

it('gives the owner a per-country VAT editor on the settings screen', function () {
    $html = view('admin.app')->render();

    expect(str_contains($html, 'vatRatesBand'))
        ->toBeTrue('there is no per-country VAT control on the Business Details screen');

    // The payload the Save button posts has to carry the key, or the screen
    // edits something it never sends.
    expect(str_contains($html, 'vat_country_rates'))
        ->toBeTrue('the Business Details save payload does not carry the per-country rates');
});

it('offers the owner country names rather than ISO codes', function () {
    $html = view('admin.app')->render();

    // Built from App\Support\Countries::NAMES so a country is never restated
    // in the console. The owner picks "Saudi Arabia", not "SA".
    expect(str_contains($html, 'KBB_VAT_COUNTRIES'))
        ->toBeTrue('the screen has no country name list to build its picker from');

    expect(str_contains($html, 'Saudi Arabia'))
        ->toBeTrue('the country list reached the console without its names');
});

it('never pre-saves a tax rate it merely suggests', function () {
    /*
     * The screen may OFFER commonly-cited Gulf figures as one-click
     * suggestions, clearly marked as needing the owner's confirmation. It must
     * never write one, and nothing is saved until he saves it. Rendering the
     * console must therefore leave the settings table alone.
     */
    view('admin.app')->render();

    expect(Setting::query()->where('key', 'vat_country_rates')->exists())
        ->toBeFalse('merely opening the console saved a tax rate');

    expect(vatDisplay()->countryRates())->toBe([]);
});

it('tells the owner in plain words that the rates are his to get right', function () {
    /*
     * "Saudi there's 15% i think" is not a rate we may act on. Whatever the
     * screen suggests, it has to say on the screen that these are the figures
     * PRINTED ON THE RECEIPT and that confirming them is his job.
     */
    $html = view('admin.app')->render();

    expect(str_contains($html, 'KBB_VAT_SUGGESTIONS'))
        ->toBeTrue('the suggestion chips are not on the screen');

    expect(preg_match('/[Cc]onfirm (?:them|these|the rate)/', $html))
        ->toBe(1, 'the screen never asks the owner to confirm the suggested rates');
});
