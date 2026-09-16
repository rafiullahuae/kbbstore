<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The two per-country delivery sources, and why they are two — Lane DC
|------------------------------------------------------------------------------
|
| Three lanes reported "delivery_texts and delivery_countries.eta both hold
| per-country delivery wording, on two screens, visible on one page — merge
| them". This file is the answer, and the answer is NO, with the presentation
| fixed instead.
|
| THEY ARE NOT THE SAME KIND OF VALUE.
|
|   delivery_texts[].text   A WHOLE SENTENCE, standing on its own under Place
|                           order and on the home page and the product page and
|                           the order confirmation. It may carry {country},
|                           which CountryTemplate::fill() substitutes. It has no
|                           length limit worth the name (the console offers
|                           1000 characters). It is read for EVERY country,
|                           whatever Extended delivery is doing, and is the one
|                           escape hatch DeliveryLine's header promises.
|
|   delivery_countries.eta  A FRAGMENT, capped at 40 characters by the Extended
|                           tab's own validator, printed after the fixed words
|                           "Arrives in " in the delivery-options list and
|                           nowhere else. It exists only while Extended delivery
|                           is switched ON, and only for countries no zone
|                           covers — ExtendedDeliveryApiController refuses a
|                           country that a zone already serves.
|
| So the Gulf, which is the whole reason `delivery_texts` exists, CANNOT HAVE AN
| ETA AT ALL: those countries are zone countries and the Extended tab refuses
| them. And "Delivered across Saudi Arabia" is a sentence, not a number of days;
| reading it as one is precisely the mistake ProductController's own header
| refuses to make. Merging the two either produces "Arrives in Delivered across
| Saudi Arabia" or throws away the sentence to keep a duration. Both are worse
| than two fields.
|
| WHAT WAS ACTUALLY WRONG WAS THE PRESENTATION, in two places, and both are
| fixed here:
|
|   1. The arrival estimate did not follow the country selector. /api/checkout/rates
|      re-renders partials/checkout/delivery-options.blade.php without
|      $deliveryEta, so a shopper who changed country watched "Arrives in 5–7
|      days" vanish and never come back — the identical defect the delivery LINE
|      was repaired for one lane earlier, through the one door that does not go
|      past it.
|
|   2. Neither admin tab mentioned the other, although they are TABS OF ONE
|      SCREEN (Store → Delivery & Shipping: Zones, Extended, Gift wrapping,
|      Delivery lines) rather than the two screens the reports described. There
|      is no screen id to retire, so nothing goes in AdminNavAndIdsTest's
|      ALIASED_SCREENS map; what was missing was one place an owner can read
|      both sentences a shopper gets.
|
| NOTHING AN OWNER TYPED MOVES. No value is migrated, no column is dropped and
| no setting is rewritten, because nothing is merged. The last two tests here
| pin the halves of that which a merge would have broken: saving either tab
| leaves the other's value alone.
|
| Pest note: `toContain` reads a second argument as another needle rather than as
| a message, so explanations are written `expect(str_contains(...))->toBeTrue()`.
| And a bare class-name search of rendered HTML also matches the page's inlined
| CSS, so element assertions use preg_match.
*/

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\DeliveryCountry;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\ExtendedDelivery;
use App\Services\SettingsService;
use App\Support\DeliveryLine;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'position' => 0]);

    // One zone, the UAE — so PK below is genuinely outside every zone and is
    // therefore a country the Extended tab will accept.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function dpSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
}

function dpCart(): Cart
{
    $product = Product::create([
        'slug' => 'dp-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 13000]);

    return $cart;
}

function dpShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** Extended delivery on, with Pakistan served and an arrival estimate against it. */
function dpExtendedPakistan(string $eta = '5-7 days'): void
{
    dpSet(ExtendedDelivery::SETTING_ON, true);

    DeliveryCountry::updateOrCreate(['code' => 'PK'], [
        'enabled' => true, 'charge' => 5000, 'free_from' => null, 'eta' => $eta, 'position' => 0,
    ]);
}

/* ============================================================================
 | 1. They are two different things, and both reach one page
 |==========================================================================*/

it('shows a sentence and a duration in two different places on one checkout', function () {
    dpExtendedPakistan('5-7 days');
    dpSet(DeliveryLine::SETTING, [['country' => 'PK', 'text' => 'Delivered across {country} by courier']]);

    $html = dpShopper(dpCart())
        ->withSession(['_old_input' => ['billing_country' => 'PK']])
        ->get('/checkout/')->assertOk()->getContent();

    // The fragment, completing a sentence the template owns.
    expect((bool) preg_match('/<p class="xd-eta">Arrives in 5-7 days<\/p>/', $html))
        ->toBeTrue('The arrival estimate from the Extended tab is not on the checkout.');

    // The whole sentence, standing on its own, with {country} already filled.
    expect(str_contains($html, 'Delivered across Pakistan by courier'))
        ->toBeTrue('The delivery line from the Delivery lines tab is not on the checkout.');
});

it('cannot give a zone country an arrival estimate at all', function () {
    /*
     * The half of the picture that settles the merge question. The Gulf is why
     * `delivery_texts` exists, and the Extended tab refuses every country a zone
     * already serves — so there is no single country for which "one field" could
     * have replaced both without taking something away from somebody.
     */
    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'dp-owner@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/extended-delivery', [
            'on' => true, 'detect' => true, 'show_all' => false,
            'rows' => [['code' => 'AE', 'enabled' => true, 'charge' => 0, 'free_from' => null, 'eta' => '1-3 days']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(DeliveryCountry::query()->where('code', 'AE')->exists())->toBeFalse();
});

it('keeps the delivery line answering for every country, Extended on or off', function () {
    // The line is not gated on Extended and never was; the estimate is. Two
    // different reaches, which is the other reason one field cannot serve both.
    dpSet(DeliveryLine::SETTING, [['country' => 'PK', 'text' => 'Delivered across Pakistan by courier']]);

    expect(app(DeliveryLine::class)->for('PK'))->toBe('Delivered across Pakistan by courier');
    expect(app(ExtendedDelivery::class)->etaFor('PK'))
        ->toBeNull('An arrival estimate appeared with Extended delivery switched off.');
});

/* ============================================================================
 | 2. The estimate follows the country selector
 |==========================================================================*/

it('sends the arrival estimate with the rates when the country changes', function () {
    dpExtendedPakistan('5-7 days');

    $cart = dpCart();

    $pk = dpShopper($cart)->postJson('/api/checkout/rates', ['country' => 'PK'])->assertOk()->json();

    // Asserted on the extracted text rather than on a boolean, so a failure
    // prints what the endpoint really sent instead of "false is not true".
    $eta = [];
    preg_match('/<p class="xd-eta">(.*?)<\/p>/', (string) ($pk['deliveryHtml'] ?? ''), $eta);

    expect($eta[1] ?? '')->toBe('Arrives in 5-7 days');
});

it('says nothing about arrival for a country that has no estimate', function () {
    dpExtendedPakistan('5-7 days');

    $cart = dpCart();

    $ae = dpShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk()->json();

    $eta = [];
    preg_match('/<p class="xd-eta">(.*?)<\/p>/', (string) ($ae['deliveryHtml'] ?? ''), $eta);

    // The UAE is a zone country and can never carry an estimate. Anything here
    // would be another country's, left behind by the refresh.
    expect($eta[1] ?? '')->toBe('');
});

it('costs no query for the estimate while Extended delivery is off', function () {
    // ExtendedDelivery::countries() returns early when the switch is off, so
    // etaFor() touches no table. StorefrontQueryBudgetTest's checkout ceiling
    // was measured with it off and must not have to move for this.
    dpSet(ExtendedDelivery::SETTING_ON, false);

    $cart = dpCart();
    $queries = [];

    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    dpShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk();

    $touched = array_filter($queries, fn (string $sql) => str_contains($sql, 'delivery_countries'));

    expect($touched)->toBe([], 'The rates endpoint queried delivery_countries with Extended delivery switched off.');
});

/* ============================================================================
 | 3. Nothing an owner typed is lost
 |==========================================================================*/

it('leaves the delivery lines alone when the Extended tab is saved', function () {
    dpSet(DeliveryLine::SETTING, [['country' => 'PK', 'text' => 'Delivered across Pakistan by courier']]);

    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'dp-owner-2@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/extended-delivery', [
            'on' => true, 'detect' => true, 'show_all' => false,
            'rows' => [['code' => 'PK', 'enabled' => true, 'charge' => 5000, 'free_from' => null, 'eta' => '5-7 days']],
        ])
        ->assertOk();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();

    expect(app(DeliveryLine::class)->for('PK'))->toBe('Delivered across Pakistan by courier');
});

it('leaves the arrival estimates alone when the Delivery lines tab is saved', function () {
    dpExtendedPakistan('5-7 days');

    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'dp-owner-3@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    // The Delivery lines tab writes through the general settings endpoint.
    test()->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', [
            'settings' => [DeliveryLine::SETTING => [['country' => 'PK', 'text' => 'Delivered across Pakistan']]],
        ])
        ->assertOk();

    expect(DeliveryCountry::query()->where('code', 'PK')->value('eta'))->toBe('5-7 days');
});

/* ============================================================================
 | 4. The console says both exist
 |==========================================================================*/

/**
 * One function of the console's script, with its comments removed.
 *
 * A REGEX GUARD READS COMMENTS AS CODE, which five lanes here have been bitten
 * by: this file's own explanation of dlExtendedNote() names the function, so a
 * bare search of the source says the row calls it whether or not any row does.
 * The body is sliced by brace-matching from the declaration and then stripped of
 * `/* *\/` and `//` comments, so what is searched is what runs.
 */
function dpConsoleFunction(string $html, string $declaration): string
{
    $start = strpos($html, $declaration);

    expect($start)->not->toBeFalse("The console no longer declares {$declaration}");

    $open = strpos($html, '{', $start);
    $depth = 0;
    $end = $open;

    for ($i = $open, $len = strlen($html); $i < $len; $i++) {
        if ($html[$i] === '{') {
            $depth++;
        } elseif ($html[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    $body = substr($html, $open, $end - $open + 1);

    return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $body);
}

it('shows the arrival estimate beside the delivery line for the same country', function () {
    /*
     * THE PRESENTATION FIX, in the console. The two fields stay two fields; what
     * changes is that one screen now shows both, so an owner writing the
     * sentence can read the duration a shopper in that country also gets. That
     * is the answer to "two places hold per-country delivery wording" that does
     * not require throwing one of them away.
     */
    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'dp-owner-4@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    $html = test()->actingAs($admin, 'admin')
        ->get('/' . \App\Services\AdminPathService::current())
        ->assertOk()->getContent();

    // The row really renders it, rather than a helper merely existing.
    expect(str_contains(dpConsoleFunction($html, 'function dlRow('), 'dlExtendedNote('))
        ->toBeTrue('A delivery-line row does not show the arrival estimate the same country may carry.');

    // And the helper reads the Extended rows rather than inventing a duration.
    $helper = dpConsoleFunction($html, 'function dlExtendedNote(');

    expect(str_contains($helper, 'dlExtendedEta(') && str_contains($helper, 'Arrives in'))
        ->toBeTrue('The note beside a delivery line does not come from the Extended tab: ' . $helper);
});

it('names the other tab in both delivery hints', function () {
    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'dp-owner-5@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    $html = test()->actingAs($admin, 'admin')
        ->get('/' . \App\Services\AdminPathService::current())
        ->assertOk()->getContent();

    $hints = dpConsoleFunction($html, 'function shTabs(');

    // Three separate reviews read the two fields as one duplicate. The hint
    // strip is the first thing an owner reads on either tab, so it is where the
    // difference has to be stated.
    expect(str_contains($hints, 'Delivery lines tab'))
        ->toBeTrue('The Extended tab hint never mentions where the full delivery sentence lives.');

    expect(str_contains($hints, 'on that tab'))
        ->toBeTrue('The Delivery lines hint never mentions the Extended “Arrives in” wording.');
});
