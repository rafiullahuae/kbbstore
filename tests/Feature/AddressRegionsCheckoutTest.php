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
        'billing_address_1' => 'Villa 7',
        'billing_address_2' => 'Al Barsha 1, Street 12',
        // Posted for the typed mode's sake (the OFF half of the fee test);
        // the list mode has no City box and ignores it.
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

it('reads Building / Apartment or Villa, Area / Street | Emirate, Country', function () {
    /*
     * The page drew Address, Emirate, City / area, Country. The owner: "bring
     * the EMIRATES field above country", then "The address field should call
     * it, Building / Apartment or Villa and the City/ Area will be Area /
     * Street and the Emirates will work as City." So: Building (full width),
     * Area / Street beside the Emirate list, Country -- the Emirate directly
     * above Country, the street before the city, and no City box at all.
     *
     * MUTATION: swap Area / Street and Emirate in address-fields-list, or put
     * the City box back. RED.
     */
    $section = adSection(adBrowser(adCart())->get('/checkout/')->assertOk()->getContent());

    $at = fn (string $needle) => strpos($section, $needle);

    expect($at('name="billing_address_1"'))->toBeLessThan($at('name="billing_address_2"'))
        ->and($at('name="billing_address_2"'))->toBeLessThan($at('name="billing_state"'))
        ->and($at('name="billing_state"'))->toBeLessThan($at('name="billing_country"'))
        ->and($section)->not->toContain('name="billing_city"')
        // Area / Street and the Emirate share the row; Building and Country do not.
        ->and($section)->toMatch('#<div class="row2">\s*<p[^>]*id="billing_address_2_field".*?id="billing_state_field".*?</div>\s*<p[^>]*id="billing_country_field"#s')
        // The labels he named, in English. (Arabic: drafts, see the Arabic test.)
        ->and($section)->toMatch('#<label for="billing_address_1"[^>]*>Building / Apartment or Villa&nbsp;#')
        ->and($section)->toMatch('#<label for="billing_address_2"[^>]*>Area / Street&nbsp;#')
        ->and($section)->toMatch('#<label for="billing_state"[^>]*>Emirate&nbsp;#')
        // A list, required in the browser as well as by place(); line 2 required too.
        ->and($section)->toMatch('/<select[^>]*name="billing_state"[^>]*required/')
        ->and($section)->toMatch('/<input[^>]*name="billing_address_2"[^>]*required[^>]*autocomplete="section-billing billing address-line2"/')
        ->and($section)->not->toMatch('/<input[^>]*name="billing_state"/');
});

it('has the Arabic labels he gave, as drafts for the owner to approve', function () {
    // MUTATION: change a draft. RED.
    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();

    expect($drafts['store.checkout.field_building'])->toBe('المبنى / الشقة أو الفيلا')
        ->and($drafts['store.checkout.field_area_street'])->toBe('المنطقة / الشارع')
        ->and($drafts['store.checkout.field_state'])->toBe('الإمارة')
        ->and($drafts['store.checkout.field_state_select'])->toBe('اختر');
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
        ->and(__(AddressRegions::labelKey('GB')))->toBe('Town / city');

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
        ->and($cfg['o'])->toBe('Town / city')
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

it('reads an old saved address into Building, Area / Street and the Emirate', function (?string $line2, string $city, ?string $state, ?string $emirate, string $area) {
    /*
     * Saved addresses carry whatever was typed, or a WooCommerce code: "dubai",
     * "DXB", "AE-DU". An address saved through the cart's popup carries the
     * emirate in its city with state empty; a Woo one may carry the area as
     * state and the emirate as city; a typed one may carry "JLT" as the city.
     *
     * THE RULE (AddressRegions::split): the Emirate is the saved state if it
     * names one, else the saved city; Area / Street is line 2, then the saved
     * city and state WHEN THEY NAME NO EMIRATE, each once. Building is line 1
     * alone. The saved row is never rewritten by showing the page.
     *
     * MUTATION: compare without normalise() in AddressRegions::canonical(). RED
     * on "dubai", "DXB", "AE-DU". Drop the city from split(). RED on "JLT".
     */
    $customer = Customer::create(['email' => 'p' . uniqid() . '@example.com', 'name' => 'Old Address', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => 'Flat 4', 'line2' => $line2, 'city' => $city, 'state' => $state, 'country' => 'AE']);

    $html = adBrowser(adCart(), $customer)->get('/checkout/')->getContent();

    expect(adSelected(adStateSelect($html)))->toBe($emirate ?? '')
        ->and($html)->toMatch('#<input[^>]*name="billing_address_2"[^>]*value="' . preg_quote(e($area), '#') . '"#')
        ->and($html)->toMatch('#<input[^>]*name="billing_address_1"[^>]*value="Flat 4"#');

    expect($customer->addresses()->first()->only(['line2', 'city', 'state']))
        ->toBe(['line2' => $line2, 'city' => $city, 'state' => $state]);
})->with([
    'lower case' => [null, 'Al Barsha', 'dubai', 'Dubai', 'Al Barsha'],
    'airport code' => [null, 'Al Barsha', 'DXB', 'Dubai', 'Al Barsha'],
    'ISO code' => [null, 'Al Barsha', 'AE-DU', 'Dubai', 'Al Barsha'],
    'Woo code' => [null, 'Al Nakheel', 'RK', 'Ras Al Khaimah', 'Al Nakheel'],
    'hyphenated' => [null, 'Al Salamah', 'Umm Al-Quwain', 'Umm Al Quwain', 'Al Salamah'],
    'Arabic' => [null, 'Al Nahda', 'الشارقة', 'Sharjah', 'Al Nahda'],
    'popup: area in line 2, emirate in city' => ['Al Quoz Industrial 2', 'Dubai', null, 'Dubai', 'Al Quoz Industrial 2'],
    'popup: emirate in city only' => [null, 'Abu Dhabi', null, 'Abu Dhabi', ''],
    'Woo: area as state, emirate as city' => [null, 'Dubai', 'Al Barsha', 'Dubai', 'Al Barsha'],
    'typed: JLT as the city, no state' => [null, 'JLT', null, null, 'JLT'],
    'line 2 and an area city' => ['Cluster D', 'JLT', 'Dubai', 'Dubai', 'Cluster D, JLT'],
]);

it('keeps a rejected submission\'s emirate chosen', function () {
    $html = adBrowser(adCart())
        ->withSession(['_old_input' => ['billing_state' => 'Ajman', 'billing_country' => 'AE', 'billing_address_2' => 'Al Nuaimiya']])
        ->get('/checkout/')->getContent();

    expect(adSelected(adStateSelect($html)))->toBe('Ajman')
        ->and($html)->toMatch('#<input[^>]*name="billing_address_2"[^>]*value="Al Nuaimiya"#');
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
        'billing_address_2' => 'Al Barsha', 'billing_state' => 'Muscat', 'billing_country' => 'AE',
        'payment_method' => 'cod',
    ])->assertStatus(422)->assertJsonValidationErrors(['billing_state']);

    expect(Order::count())->toBe($before);
});

it('stores the list\'s own English name, whatever spelling was posted', function () {
    // MUTATION: store $data['billing_state'] as posted. RED.
    adPlace(adCart(), ['billing_state' => 'dxb'])->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->shipping_address['state'])->toBe('Dubai');
});

it('keeps a typed Town / city box for a country with no list', function () {
    // A zone for the United Kingdom puts it in the Country select; it has no
    // list, so the box is typed -- "Town / city" -- and stored as the city
    // and the state alike, the way the Emirate is.
    $gb = ShippingZone::create(['name' => 'UK', 'position' => 5]);
    ShippingZoneLocation::create(['shipping_zone_id' => $gb->id, 'type' => 'country', 'code' => 'GB']);
    ShippingMethod::create(['shipping_zone_id' => $gb->id, 'type' => 'flat_rate', 'title' => 'UK post', 'cost' => 9000, 'enabled' => true, 'position' => 0]);
    ShippingService::flushZones();

    adPlace(adCart(), ['billing_state' => 'London', 'billing_address_2' => 'Baker Street', 'billing_country' => 'GB'])
        ->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->shipping_address)->toMatchArray(['line2' => 'Baker Street', 'city' => 'London', 'state' => 'London']);

    $customer = Customer::create(['email' => 'gb@example.com', 'name' => 'Ann', 'password' => 'secret-secret']);
    $customer->addresses()->create(['type' => 'shipping', 'is_default' => true,
        'line1' => '1 High St', 'line2' => 'Marylebone', 'city' => 'London', 'state' => 'Greater London', 'country' => 'GB']);

    $section = adSection(adBrowser(adCart(), $customer)->get('/checkout/')->getContent());

    expect($section)->toMatch('/<input[^>]*name="billing_state"[^>]*autocomplete="section-billing billing address-level2" value="London"/')
        ->and($section)->toMatch('/<input[^>]*name="billing_address_2"[^>]*value="Marylebone"/')
        ->and($section)->toMatch('#<label for="billing_state"[^>]*>Town / city#');
});

it('stores the emirate as the order\'s city and state, Area / Street as line 2, and prints them', function () {
    /*
     * "the Emirates will work as City." The order's address: line1 Building,
     * line2 Area / Street, city AND state the Emirate -- the shape the picker
     * row has always written. The emails and the invoice print line 2 and
     * print the emirate once (OrderEmailPresenter::cityLine, InvoiceDocument).
     * Tabby and Tamara are sent the emirate as the city.
     *
     * MUTATION: drop the billing_city copy in place(). RED (city is null). Drop
     * the line2 insertion. RED. Drop Tabby's `line1` fallback. RED.
     */
    adPlace(adCart(), ['billing_address_1' => 'Marina Heights, Apt 1203', 'billing_address_2' => 'Dubai Marina, Al Marsa St', 'billing_state' => 'dubai'])
        ->assertSessionHasNoErrors();

    $order = Order::latest('id')->first();

    expect($order->shipping_address)->toMatchArray([
        'line1' => 'Marina Heights, Apt 1203',
        'line2' => 'Dubai Marina, Al Marsa St',
        'city' => 'Dubai',
        'state' => 'Dubai',
        'country' => 'AE',
    ])->and(array_keys($order->shipping_address))->toBe(['first_name', 'last_name', 'line1', 'line2', 'city', 'state', 'country', 'phone']);

    // The order email's address block: line 2 printed, Dubai once.
    $email = (new ReflectionMethod(\App\Services\Mail\OrderEmailPresenter::class, 'address'));
    $lines = $email->invoke(app(\App\Services\Mail\OrderEmailPresenter::class), $order->shipping_address);
    expect($lines)->toContain('Marina Heights, Apt 1203')
        ->and($lines)->toContain('Dubai Marina, Al Marsa St')
        ->and($lines)->toContain('Dubai')
        ->and($lines)->not->toContain('Dubai, Dubai');

    // The invoice / packing slip the same way.
    $invoice = new ReflectionMethod(\App\Services\Invoices\InvoiceDocument::class, 'address');
    $doc = (new ReflectionClass(\App\Services\Invoices\InvoiceDocument::class))->newInstanceWithoutConstructor();
    $printed = $invoice->invoke($doc, $order->shipping_address, false);
    expect($printed)->toContain('Dubai Marina, Al Marsa St')->and($printed)->toContain('Dubai')
        ->and(implode("\n", $printed))->not->toContain('Dubai Dubai');

    // Tabby: address line from line1 + line2, the emirate as the city.
    $tabby = new ReflectionMethod(\App\Services\Payments\Gateways\TabbyGateway::class, 'shippingAddress');
    expect($tabby->invoke(app(\App\Services\Payments\Gateways\TabbyGateway::class), $order))->toBe([
        'address' => 'Marina Heights, Apt 1203, Dubai Marina, Al Marsa St',
        'city' => 'Dubai',
    ]);

    // Tamara: line1, line2, the emirate as city and region.
    $tamara = new ReflectionMethod(\App\Services\Payments\Gateways\TamaraGateway::class, 'address');
    expect($tamara->invoke(app(\App\Services\Payments\Gateways\TamaraGateway::class), $order->shipping_address))->toMatchArray([
        'line1' => 'Marina Heights, Apt 1203', 'line2' => 'Dubai Marina, Al Marsa St', 'city' => 'Dubai', 'region' => 'Dubai',
    ]);
});

it('requires Area / Street in the list mode, and takes a pre-switch page\'s City / area as it', function () {
    /*
     * A tab opened before the package, or a cached copy, still posts the old
     * "City / area" box and no Area / Street. That box always held the area,
     * so it becomes line 2 and the emirate the city, rather than a refused
     * order. With neither, Area / Street is required.
     * MUTATION: make billing_address_2 plainly required. RED on the second half.
     */
    adPlace(adCart(), ['billing_address_2' => '', 'billing_city' => ''])->assertSessionHasErrors(['billing_address_2']);

    adPlace(adCart(), ['billing_address_2' => null, 'billing_city' => 'Jumeirah 1', 'billing_state' => 'Dubai'])->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->shipping_address)->toMatchArray(['line2' => 'Jumeirah 1', 'city' => 'Dubai', 'state' => 'Dubai']);
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
    // Lane QK6: this tells the Dubai rate by its stored title, which the
    // owner's "Delivery labels" (ON) shows as "Express Delivery"; OFF prints it.
    app(\App\Services\CheckoutPage::class)->save(['dl_on' => false]);
    \App\Services\SettingsService::forgetMemo();

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
