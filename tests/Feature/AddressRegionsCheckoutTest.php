<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\AddressRegions;
use Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnglishRenderWalk;

/**
 * =============================================================================
 * THE EMIRATE AS A LIST THAT FOLLOWS THE COUNTRY (Lane AD, 7 October)
 * =============================================================================
 *
 * The owner: "also bring the EMIRATES field above country. and also i need a
 * selection of EMIRATES. for each country. if country is UAE, all 7 emirates
 * list should be there, if oman and so on, i need the Emirates / States
 * properly in list to make selection by user." And then: "for UAE and gulf
 * countries, i need the list dual languages together as attached" -- the old
 * site's list, "أبو ظبي — Abu Dhabi" down to "عجمان — Ajman".
 *
 * Before this the checkout's Shipping address drew Address, Emirate (a box to
 * type in), City / area, Country: a shopper could type "dubai", "DXB" or
 * "Dubia", and a state-level zone written `AE:Dubai` matched only the first
 * spelling a person happened to use with the right capitals.
 *
 * Appearance -> Checkout page -> Fields & attention -> "Emirate / state as a
 * list", ON as asked. OFF is the typed box exactly as it was.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);

    // The zones the shop ships with: All UAE (AED 20, free over 199) and the
    // five other Gulf countries (AED 150, free over 1,600).
    (new ShippingSeeder())->run();
    ShippingService::flushZones();
});

/** The commit Lane AD branched from: the three OFF templates as they were. */
const LANE_AD_BASE = 'e4ab7334';

function adCart(int $fils = 5000): Cart
{
    $product = Product::create([
        'slug' => 'ad-' . uniqid(), 'name' => 'List Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => $fils, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $fils]);

    return $cart->fresh('items');
}

function adBrowser(Cart $cart, ?Customer $customer = null)
{
    $browser = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    return $customer === null
        ? $browser
        : $browser->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

function adSwitch(bool $on): void
{
    app(CheckoutPage::class)->save(['state_list' => $on]);
    SettingsService::forgetMemo();
}

function adSection(string $html): string
{
    $from = strpos($html, '<!-- 2 · Shipping address -->');
    $to = strpos($html, '<!-- 3 · Delivery -->');

    expect($from)->not->toBeFalse()->and($to)->not->toBeFalse();

    return substr($html, $from, $to - $from);
}

/** The <select name="billing_state"> element, whole. */
function adStateSelect(string $html): string
{
    expect(preg_match('#<select[^>]*name="billing_state"[^>]*>.*?</select>#s', $html, $m))->toBe(1, 'no Emirate list on the page');

    return $m[0];
}

/** value => visible text of every option in a select, in order. */
function adOptions(string $select): array
{
    preg_match_all('#<option value="([^"]*)"[^>]*>([^<]*)</option>#', $select, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as [, $value, $text]) {
        $out[html_entity_decode($value)] = html_entity_decode($text);
    }

    return $out;
}

function adSelected(string $select): ?string
{
    return preg_match('#<option value="([^"]*)"[^>]*selected[^>]*>#', $select, $m) ? html_entity_decode($m[1]) : null;
}

function adPlace(Cart $cart, array $over = [], ?Customer $customer = null)
{
    return adBrowser($cart, $customer)->post('/checkout/place', array_merge([
        'billing_email' => 'list@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Mariam Saeed',
        'billing_address_1' => 'Villa 7, Street 12',
        'billing_city' => 'Al Barsha',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $over));
}

/* ------------------------------------------------------------------------
 | 1. The switch, and where the owner finds it
 |------------------------------------------------------------------------*/

it('ships the list on, on Appearance -> Checkout page -> Fields & attention', function () {
    // MUTATION: default `state_list` to false. RED here and in every ON test below.
    expect(CheckoutPage::SCHEMA['state_list'][0])->toBe('bool')
        ->and(CheckoutPage::SCHEMA['state_list'][1])->toBe('Emirate / state as a list')
        ->and(CheckoutPage::SCHEMA['state_list'][2])->toBeTrue()
        ->and(app(CheckoutPage::class)->stateList())->toBeTrue()
        ->and(CheckoutPage::TABS['cues'][0])->toBe('Fields & attention')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('state_list');
});

/* ------------------------------------------------------------------------
 | 2. The order of the fields
 |------------------------------------------------------------------------*/

it('puts the Emirate directly above Country: Address, City / area, Emirate, Country', function () {
    /*
     * The page drew Address, Emirate, City / area, Country. He asked for the
     * Emirate above Country, so it moves past City and is the last box before
     * Country, as a list rather than a box to type in.
     *
     * MUTATION: swap City and Emirate back in address-fields-list. RED.
     */
    $section = adSection(adBrowser(adCart())->get('/checkout/')->assertOk()->getContent());

    $at = fn (string $needle) => strpos($section, $needle);

    expect($at('name="billing_address_1"'))->toBeLessThan($at('name="billing_city"'))
        ->and($at('name="billing_city"'))->toBeLessThan($at('name="billing_state"'))
        ->and($at('name="billing_state"'))->toBeLessThan($at('name="billing_country"'))
        // A list, required in the browser as well as by place().
        ->and($section)->toMatch('/<select[^>]*name="billing_state"[^>]*required/')
        ->and($section)->not->toMatch('/<input[^>]*name="billing_state"/')
        // Nothing between Emirate and Country but the row's close.
        ->and(substr($section, $at('id="billing_state_field"'), $at('id="billing_country_field"') - $at('id="billing_state_field"')))
            ->not->toContain('billing_city');
});

/* ------------------------------------------------------------------------
 | 3. The lists
 |------------------------------------------------------------------------*/

it('lists the seven emirates exactly as the owner\'s screenshot reads them', function () {
    /*
     * Arabic first, an em dash, then English, in the old site's order, with
     * his spellings ("ام القيوين", "Ras al Khaimah"). The VALUE stays the
     * English name the typed box stored, so orders and zones read the same.
     * The Arabic half is wrapped in FSI ... PDI so it cannot reorder the dash.
     *
     * MUTATION: reorder AddressRegions::LISTS['AE'], or drop the 4th element of
     * the Ras Al Khaimah row. RED.
     */
    $options = adOptions(adStateSelect(adBrowser(adCart())->get('/checkout/')->getContent()));
    $strip = fn (string $t) => str_replace(["\u{2068}", "\u{2069}"], '', $t);

    expect(array_keys($options))->toBe(['', 'Abu Dhabi', 'Sharjah', 'Fujairah', 'Umm Al Quwain', 'Dubai', 'Ras Al Khaimah', 'Ajman'])
        ->and(array_map($strip, array_values($options)))->toBe([
            'Select',
            'أبو ظبي — Abu Dhabi',
            'الشارقة — Sharjah',
            'الفجيرة — Fujairah',
            'ام القيوين — Umm Al Quwain',
            'دبي — Dubai',
            'رأس الخيمة — Ras al Khaimah',
            'عجمان — Ajman',
        ])
        ->and($options['Dubai'])->toBe("\u{2068}دبي\u{2069} — Dubai");
});

it('has a complete list for every Gulf country the shop delivers to, and its label', function () {
    /*
     * ISO 3166-2 first-level subdivisions: UAE 7 emirates, Oman 11
     * governorates, Saudi Arabia 13 regions, Qatar 8 municipalities, Bahrain 4
     * governorates, Kuwait 6 governorates. MUTATION: delete a row. RED.
     */
    $counts = array_map(fn (array $c) => count($c[1]), AddressRegions::LISTS);

    expect($counts)->toBe(['AE' => 7, 'OM' => 11, 'SA' => 13, 'QA' => 8, 'BH' => 4, 'KW' => 6])
        ->and(__(AddressRegions::labelKey('AE')))->toBe('Emirate')
        ->and(__(AddressRegions::labelKey('OM')))->toBe('Governorate')
        ->and(__(AddressRegions::labelKey('BH')))->toBe('Governorate')
        ->and(__(AddressRegions::labelKey('KW')))->toBe('Governorate')
        ->and(__(AddressRegions::labelKey('SA')))->toBe('Region')
        ->and(__(AddressRegions::labelKey('QA')))->toBe('Municipality')
        ->and(__(AddressRegions::labelKey('GB')))->toBe('State / region');

    // Every row is bilingual and every spelling answers to one row only.
    foreach (AddressRegions::LISTS as $country => [, $rows]) {
        $seen = [];
        foreach ($rows as $row) {
            expect($row[1])->toMatch('/\p{Arabic}/u');
            foreach (array_merge([$row[0], $row[1]], $row[2]) as $spelling) {
                $key = AddressRegions::normalise($spelling);
                expect($seen[$key] ?? $row[0])->toBe($row[0], "{$country}: '{$spelling}' answers to two rows");
                $seen[$key] = $row[0];
            }
        }
    }
});

it('prints every offered country\'s list once, for the page to swap without a request', function () {
    /*
     * The Oman shopper picks Oman and the list becomes its 11 governorates,
     * labelled "Governorate" -- from this JSON, built into the options in the
     * browser, with no fetch. MUTATION: drop clientMap() from the partial. RED.
     */
    $html = adBrowser(adCart())->get('/checkout/')->getContent();

    expect(substr_count($html, 'id="kbb-state-lists"'))->toBe(1)
        ->and(preg_match('#<script type="application/json" id="kbb-state-lists" data-for="AE">(.*?)</script>#s', $html, $m))->toBe(1);

    $cfg = json_decode($m[1], true);

    expect(array_keys($cfg['c']))->toEqualCanonicalizing(['AE', 'SA', 'KW', 'QA', 'BH', 'OM'])
        ->and($cfg['c']['OM'][0])->toBe('Governorate')
        ->and(count($cfg['c']['OM'][1]))->toBe(11)
        // [value, Arabic], plus the English shown only where it differs; the
        // browser joins them exactly as AddressRegions::options() does.
        ->and($cfg['c']['OM'][1][9])->toBe(['Muscat', 'مسقط'])
        ->and($cfg['c']['AE'][1][5])->toBe(['Ras Al Khaimah', 'رأس الخيمة', 'Ras al Khaimah'])
        ->and($cfg['s'])->toBe('Select')
        ->and($cfg['o'])->toBe('State / region')
        // The script measures nothing and asks the server nothing.
        ->and($html)->not->toMatch('/kbb-state-lists[\s\S]{0,4000}?(fetch\(|getBoundingClientRect|offsetHeight)/');
});

it('re-prices on a choice from the list, once, through the country\'s handler', function () {
    /*
     * A choice is a `change` on #billing_state; it is handed to the country,
     * whose handler in checkout.js fetches the rates with the emirate in the
     * request. Listened for on the document, because the country switch may
     * swap the element for a typed box and back. MUTATION: delete the listener
     * in address-fields-list. RED.
     */
    $html = adBrowser(adCart())->get('/checkout/')->getContent();

    expect(substr_count($html, "document.addEventListener('change', function (e) {\n  if (!e.target || e.target.id !== 'billing_state') { return; }"))->toBe(1)
        ->and($html)->toContain("country.dispatchEvent(new Event('change', { bubbles: true }));")
        // No second pricing path and nothing per keystroke.
        ->and($html)->not->toContain("state.addEventListener('input'");
});

it('opens on the list of the country the page opens on', function () {
    // A returning customer in Muscat sees Oman's governorates, labelled so.
    $customer = Customer::create(['email' => 'om@example.com', 'name' => 'Salim', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Way 3011', 'city' => 'Ruwi', 'state' => 'muscat', 'country' => 'OM']);

    $html = adBrowser(adCart(), $customer)->get('/checkout/')->assertOk()->getContent();
    $select = adStateSelect($html);

    expect(count(adOptions($select)))->toBe(12)
        ->and(adSelected($select))->toBe('Muscat')
        ->and($html)->toMatch('#<label for="billing_state"[^>]*>Governorate#');
});

/* ------------------------------------------------------------------------
 | 4. Old values prefill sensibly
 |------------------------------------------------------------------------*/

it('selects the matching emirate for a saved value in any spelling', function (?string $state, string $city, ?string $expected) {
    /*
     * Saved addresses carry whatever was typed, or a WooCommerce code: "dubai",
     * "DXB", "AE-DU". An address saved through the cart's popup carries the
     * emirate in its city with state empty; a Woo one may carry the area as
     * state and the emirate as city. A value that names no emirate leaves the
     * list on "Select" -- the saved address itself is never rewritten.
     *
     * MUTATION: compare without normalise() in AddressRegions::canonical(). RED
     * on "dubai", "DXB", "AE-DU". Drop the city candidate in show(). RED on
     * the popup and Woo rows.
     */
    $customer = Customer::create(['email' => 'p' . uniqid() . '@example.com', 'name' => 'Old Address', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Flat 4', 'city' => $city, 'state' => $state, 'country' => 'AE']);

    $select = adStateSelect(adBrowser(adCart(), $customer)->get('/checkout/')->getContent());

    expect(adSelected($select))->toBe($expected ?? '');

    // And the saved row is exactly as it was.
    expect($customer->addresses()->first()->state)->toBe($state);
})->with([
    'lower case' => ['dubai', 'Al Barsha', 'Dubai'],
    'airport code' => ['DXB', 'Al Barsha', 'Dubai'],
    'ISO code' => ['AE-DU', 'Al Barsha', 'Dubai'],
    'Woo code' => ['RK', 'Al Nakheel', 'Ras Al Khaimah'],
    'hyphenated' => ['Umm Al-Quwain', 'Al Salamah', 'Umm Al Quwain'],
    'Arabic' => ['الشارقة', 'Al Nahda', 'Sharjah'],
    'popup: emirate in city' => [null, 'Abu Dhabi', 'Abu Dhabi'],
    'Woo: area as state, emirate as city' => ['Al Barsha', 'Dubai', 'Dubai'],
    'names no emirate' => ['Al Quoz', 'Al Quoz', null],
]);

it('keeps a rejected submission\'s emirate chosen', function () {
    $html = adBrowser(adCart())
        ->withSession(['_old_input' => ['billing_state' => 'Ajman', 'billing_country' => 'AE', 'billing_city' => 'Al Nuaimiya']])
        ->get('/checkout/')->getContent();

    expect(adSelected(adStateSelect($html)))->toBe('Ajman');
});

/* ------------------------------------------------------------------------
 | 5. The server takes only a name from the list
 |------------------------------------------------------------------------*/

it('refuses an emirate that is not in the list', function () {
    /*
     * A select is a suggestion to a browser, not to a script.
     * MUTATION: delete the AddressRegions block in place(). RED -- an order
     * for "Atlantis" is placed.
     */
    $before = Order::count();

    adPlace(adCart(), ['billing_state' => 'Atlantis'])->assertSessionHasErrors(['billing_state']);

    expect(Order::count())->toBe($before);

    // The card door answers in JSON, the same refusal.
    adBrowser(adCart())->postJson('/checkout/place', [
        'billing_email' => 'list@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Mariam Saeed', 'billing_address_1' => 'Villa 7',
        'billing_city' => 'Al Barsha', 'billing_state' => 'Muscat', 'billing_country' => 'AE',
        'payment_method' => 'cod',
    ])->assertStatus(422)->assertJsonValidationErrors(['billing_state']);

    expect(Order::count())->toBe($before);
});

it('stores the list\'s own English name, whatever spelling was posted', function () {
    // MUTATION: store $data['billing_state'] as posted. RED.
    adPlace(adCart(), ['billing_state' => 'dxb'])->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->shipping_address['state'])->toBe('Dubai');
});

it('keeps the typed box for a country with no list', function () {
    // A zone for the United Kingdom puts it in the Country select; it has no
    // list, so the box is typed and any state is taken, as before.
    $gb = ShippingZone::create(['name' => 'UK', 'position' => 5]);
    ShippingZoneLocation::create(['shipping_zone_id' => $gb->id, 'type' => 'country', 'code' => 'GB']);
    ShippingMethod::create(['shipping_zone_id' => $gb->id, 'type' => 'flat_rate', 'title' => 'UK post', 'cost' => 9000, 'enabled' => true, 'position' => 0]);
    ShippingService::flushZones();

    adPlace(adCart(), ['billing_state' => 'Greater London', 'billing_city' => 'London', 'billing_country' => 'GB'])
        ->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->shipping_address['state'])->toBe('Greater London');

    $customer = Customer::create(['email' => 'gb@example.com', 'name' => 'Ann', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => '1 High St', 'city' => 'London', 'state' => 'Greater London', 'country' => 'GB']);

    $section = adSection(adBrowser(adCart(), $customer)->get('/checkout/')->getContent());

    expect($section)->toMatch('/<input[^>]*name="billing_state"[^>]*value="Greater London"/')
        ->and($section)->toMatch('#<label for="billing_state"[^>]*>State / region#');
});

/* ------------------------------------------------------------------------
 | 6. The money: the delivery fee does not move
 |------------------------------------------------------------------------*/

it('charges the same delivery for every emirate and every Gulf region as the typed box did', function () {
    /*
     * With the zones the shop ships with, nothing is priced on the emirate:
     * All UAE is AED 20 and the Gulf AED 150 whichever is chosen. Each order is
     * placed twice, switch OFF (the typed box) and ON (the list), and the
     * delivery charged must be the same. MUTATION: price show() or place() on
     * anything but the posted country and canonical state. RED.
     */
    foreach (AddressRegions::LISTS as $country => [, $rows]) {
        foreach ($rows as [$value]) {
            $charged = [];

            foreach ([false, true] as $on) {
                adSwitch($on);
                adPlace(adCart(), ['billing_state' => $value, 'billing_country' => $country])->assertSessionHasNoErrors();
                $charged[] = (int) Order::latest('id')->first()->shipping_total;
            }

            expect($charged[0])->toBe($charged[1], "{$country} {$value}")
                ->and($charged[1])->toBe($country === 'AE' ? 2000 : 15000);
        }
    }
});

it('prices a state-level zone on the list\'s name, so a lower-case saved emirate now matches it', function () {
    /*
     * A zone written `AE:Dubai` at AED 10 ahead of All UAE. A customer whose
     * address says "dubai" was priced AED 20 by the typed box (no zone matched
     * the lower case) and charged what they typed. The list shows Dubai, and
     * the page and the order are both priced on Dubai.
     */
    $dubai = ShippingZone::create(['name' => 'Dubai', 'position' => -1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $dubai->id, 'type' => 'state', 'code' => 'AE:Dubai']);
    ShippingMethod::create(['shipping_zone_id' => $dubai->id, 'type' => 'flat_rate', 'title' => 'Dubai delivery', 'cost' => 1000, 'enabled' => true, 'position' => 0]);
    ShippingService::flushZones();

    $customer = Customer::create(['email' => 'dx@example.com', 'name' => 'Dee', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Flat 9', 'city' => 'Al Barsha', 'state' => 'dubai', 'country' => 'AE']);

    $html = adBrowser(adCart(), $customer)->get('/checkout/')->getContent();

    expect($html)->toContain('Dubai delivery');

    adPlace(adCart(), ['billing_state' => 'dubai'], $customer)->assertSessionHasNoErrors();

    expect((int) Order::latest('id')->first()->shipping_total)->toBe(1000);
});

/* ------------------------------------------------------------------------
 | 7. OFF is the typed box exactly as it was; ON costs no query
 |------------------------------------------------------------------------*/

it('renders the checkout byte-identical to the typed box with the switch off', function () {
    /*
     * Three templates carry an OFF branch: the address fields, the Google
     * address suggestions and the account address book. Rendered OFF, the page
     * must equal the page drawn by those three files as they stood before Lane
     * AD (git, LANE_AD_BASE), with every other template the working tree's.
     *
     * When a later lane edits one of those three files on purpose, this goes
     * red with the diff; move LANE_AD_BASE to that lane's commit.
     */
    adSwitch(false);
    // The Google suggestions on, so their OFF branch is rendered too.
    app(SettingsService::class)->setModule('address_autocomplete', true);
    app(SettingsService::class)->set(\App\Support\AddressAutocomplete::KEY_CONSENT, 'yes');
    app(SettingsService::class)->set(\App\Support\AddressAutocomplete::KEY_API, str_repeat('k', 39));
    SettingsService::forgetMemo();

    $customer = Customer::create(['email' => 'off@example.com', 'name' => 'Off Switch', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Flat 2', 'city' => 'Al Barsha', 'state' => 'dubai', 'country' => 'AE']);
    $cart = adCart();

    // A clean session and auth state before every request, as
    // StorefrontEnglishUnchangedTest does, so the passes differ only in views.
    $fresh = function (): void {
        test()->flushSession();
        app('auth')->forgetGuards();
        app(CartService::class)->forget();
    };
    $render = function () use ($cart, $customer, $fresh) {
        $fresh();
        $checkout = adBrowser($cart, $customer)->get('/checkout/')->assertOk()->getContent();
        $fresh();
        $book = adBrowser($cart, $customer)->get('/my-account/edit-address')->assertOk()->getContent();

        return [EnglishRenderWalk::mask($checkout), EnglishRenderWalk::mask($book)];
    };

    // A warm-up render first: the layout's first signed-in request of a test
    // draws the mobile menu's account links from a cold state, and the
    // comparison is between templates, not between a cold and a warm request.
    $render();
    $after = $render();

    $dir = sys_get_temp_dir() . '/lane-ad-base-' . getmypid() . '-' . uniqid();
    $files = [
        'partials/checkout/address-fields.blade.php',
        'partials/checkout/address-autocomplete.blade.php',
        'store/account/addresses.blade.php',
    ];

    foreach ($files as $file) {
        $old = shell_exec(sprintf('git -C %s show %s:resources/views/%s 2>/dev/null', escapeshellarg(base_path()), LANE_AD_BASE, $file));
        expect($old)->not->toBeEmpty("git show {$file} at the Lane AD base");
        @mkdir(dirname($dir . '/' . $file), 0777, true);
        file_put_contents($dir . '/' . $file, $old);
    }

    $finder = app('view')->getFinder();
    $paths = $finder->getPaths();

    try {
        $finder->setPaths(array_merge([$dir], $paths));
        $finder->flush();
        $before = $render();
    } finally {
        $finder->setPaths($paths);
        $finder->flush();
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }

    expect($before[0])->toContain('name="billing_state"')
        ->and($before[0])->toContain('maps.googleapis.com')
        ->and($before[1])->toContain('name="state"')
        ->and($after[0])->toBe($before[0])
        ->and($after[1])->toBe($before[1]);
});

it('costs the checkout no query and no settings read', function () {
    /*
     * The lists are a PHP constant, the switch is one lookup in the settings
     * map the request already holds. MUTATION: read the lists from a table or
     * Setting::query() the switch. RED.
     */
    $customer = Customer::create(['email' => 'q@example.com', 'name' => 'Count Me', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Flat 1', 'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE']);
    $cart = adCart();

    $count = function (bool $on) use ($cart, $customer): array {
        adSwitch($on);
        adBrowser($cart, $customer)->get('/checkout/')->assertOk(); // warm
        $settingsReads = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        adBrowser($cart, $customer)->get('/checkout/')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($log as $q) {
            if (str_contains($q['query'], '"settings"') || str_contains($q['query'], '`settings`')) {
                $settingsReads++;
            }
        }

        return [count($log), $settingsReads];
    };

    expect($count(true))->toBe($count(false));
});
