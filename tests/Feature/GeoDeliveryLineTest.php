<?php

declare(strict_types=1);

/**
 * Lane CO — one delivery promise per country, and one answer to "where is this
 * shopper?".
 *
 * WHAT WAS WRONG. `delivery_default_text` is "1–3 days fast delivery all over
 * UAE". CheckoutController had already been repaired so that the line under
 * Place order is offered to the UAE alone — but store/home.blade.php printed
 * the same sentence unconditionally, to every visitor on earth, with no country
 * check of any kind. A shopper in Riyadh was promised UAE delivery on the first
 * page of the shop and correctly told nothing on the checkout, so the two
 * screens of one store contradicted each other and the louder one was wrong.
 *
 * And the escape hatch the checkout's comment promised — "an explicit
 * `delivery_texts` row still wins for any country" — had one reader in the
 * entire codebase and NO WRITER: no admin screen, no seeder, no validation
 * rule. `AdminController::SETTING_RULES` carries its own warning that a key
 * missing from it makes Save report success and write nothing, and that is
 * exactly what this key would have done.
 *
 * NOTHING HERE INVENTS A DELIVERY WINDOW. No Saudi or Kuwaiti transit time has
 * been measured, so the mechanism ships empty and every country behaves today
 * exactly as it did before. The fixtures below type their own text, the way the
 * owner will.
 *
 * THE CLASS-NAME TRAP, as ShopperPathTruthTest records: searching rendered HTML
 * for a bare class name also matches the page's own inlined CSS. Assertions
 * here match ELEMENTS via preg_match/preg_match_all, or exact copy.
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
use App\Support\DeliveryLine;
use App\Support\Money;
use App\Support\ShopperCountry;
use Illuminate\Support\Str;

beforeEach(function () {
    /*
     * Every cache these settings live behind, dropped before each case.
     * SettingsService holds a forever-cache AND a per-process memo, and
     * Setting::map() holds a second static of its own — the trap CLAUDE.md
     * records. Without this a value written by one test is read back stale by
     * the next, which reads as a failure belonging to neither.
     */
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    // The UAE and the Gulf, in miniature — the Gulf half is what gives a
    // "wrong country" case something real to be wrong about.
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

/** Settings only ever through the service — it holds a forever-cache AND a per-process memo. */
function coSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
}

/** An owner, for the two admin-endpoint cases. */
function coAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Delivery Owner',
        'email' => 'co-delivery-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

/** A cart token, so /api/checkout/rates has something to quote for. */
function coCart(): string
{
    $product = Product::create([
        'slug' => 'co-' . Str::random(8),
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

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 13000,
    ]);

    return $cart->token;
}

/** A browser carrying that cart's cookie, unencrypted so the test can set it. */
function coShopper(string $token)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $token);
}

/** The text inside the home page's delivery band, or null when the band says nothing. */
function coHomeLine(string $html): ?string
{
    $m = [];

    return preg_match('/<div class="delivery[^"]*"[^>]*>.*?<b>(.*?)<\/b>/s', $html, $m) === 1
        ? trim($m[1])
        : null;
}

/*
|------------------------------------------------------------------------------
| 1. The home page stops promising UAE delivery to the whole world
|------------------------------------------------------------------------------
*/

it('does not promise UAE delivery on the home page to a shopper outside the UAE', function () {
    /*
     * The defect, exactly: home.blade.php printed `delivery_default_text` with
     * no country check. This shopper's request carries a geo header saying
     * Saudi Arabia and there is no row written for Saudi Arabia, so the only
     * true thing the shop can say about delivery there is nothing.
     */
    $html = $this->withHeader('CF-IPCountry', 'SA')->get('/')->assertOk()->getContent();

    $line = coHomeLine($html);

    expect($line === null || ! str_contains($line, 'UAE'))
        ->toBeTrue('The home page promises UAE delivery to a shopper detected in Saudi Arabia: ' . (string) $line);
});

it('still shows the UAE delivery line on the home page to a UAE shopper', function () {
    // The other half. Dropping the promise everywhere would be its own defect —
    // it is true of the country it names.
    $html = $this->withHeader('CF-IPCountry', 'AE')->get('/')->assertOk()->getContent();

    expect(str_contains((string) coHomeLine($html), 'UAE'))
        ->toBeTrue('The UAE delivery line vanished from the home page for the country it actually describes.');
});

it('shows the owner-written line on the home page once he has written one', function () {
    // The mechanism, proved with the owner's own words rather than an invented
    // transit time. Nothing in the shipped code says this.
    coSet(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    $html = $this->withHeader('CF-IPCountry', 'SA')->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'Delivered across Saudi Arabia'))
        ->toBeTrue('A delivery line the owner wrote for Saudi Arabia never reached the home page.');
});

it('keeps the free-delivery half of the home band when the shopper has a threshold of their own', function () {
    /*
     * AMENDED BY LANE CZ, and the amendment is the point.
     *
     * This case used to assert that the figure survives for ANY visitor, on
     * the grounds that "the free-delivery threshold is true everywhere". That
     * reasoning does not survive this shop's own configuration: production
     * runs 199 for the UAE and 1,600 for the Gulf, and Extended Delivery
     * carries a `free_from` per country. One figure is not true of both, and
     * the number this band printed was the UAE's, to everyone.
     *
     * What the case was really protecting is intact and still asserted here:
     * "say nothing" means the delivery SENTENCE, not the whole band. Where the
     * shopper's own country HAS a threshold, it is shown — and it is THEIRS.
     */
    ShippingMethod::create([
        'shipping_zone_id' => ShippingZone::where('name', 'Gulf Countries')->value('id'),
        'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1,
    ]);

    $html = $this->withHeader('CF-IPCountry', 'SA')->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'Free delivery over'))
        ->toBeTrue('Suppressing the UAE promise also took the free-delivery threshold off the home page.');

    expect(str_contains($html, '1,600'))
        ->toBeTrue('The Gulf shopper was shown a free-delivery figure that is not the one their own zone records.');
});

it('makes no free-delivery claim to a country the shop offers none in', function () {
    /*
     * The other half, and the defect the amendment above uncovered. Neither
     * zone in this fixture carries a free-shipping method, so there is no such
     * offer for anybody — and the band still printed one, because the template
     * fell back to a figure of its own when the shop had none to give.
     */
    $html = $this->withHeader('CF-IPCountry', 'SA')->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'Free delivery over'))
        ->toBeFalse('The home page advertised free delivery in a country where the shop records no such offer.');
});

/*
|------------------------------------------------------------------------------
| 2. One resolver, and it says which source answered
|------------------------------------------------------------------------------
*/

it('falls back to the store country, and says it is falling back, when nothing knows', function () {
    // NO GEO SOURCE IS A SUPPORTED STATE. Whether this host ever receives a
    // country header has not been established, so the no-signal path is the one
    // that has to be right.
    coSet('store_country', 'AE');

    $resolved = ShopperCountry::for(request()->create('/'));

    expect($resolved->code)->toBe('AE');
    expect($resolved->source)->toBe(ShopperCountry::DEFAULT);
    expect($resolved->guessed())->toBeTrue();
});

it('reads a geo header when there is one, and reports it as a guess', function () {
    $request = request()->create('/', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => 'SA']);

    $resolved = ShopperCountry::for($request);

    expect($resolved->code)->toBe('SA');
    expect($resolved->source)->toBe(ShopperCountry::HEADER);
    expect($resolved->guessed())->toBeTrue('A header guess was reported as something the shopper told us.');
    expect($resolved->name())->toBe('Saudi Arabia');
});

it('lets what the shopper chose outrank any geo guess', function () {
    // A person who has said where they are is never overruled by a header.
    $request = request()->create('/', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => 'SA']);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(ShopperCountry::SESSION_KEY, 'KW');

    $resolved = ShopperCountry::for($request);

    expect($resolved->code)->toBe('KW');
    expect($resolved->source)->toBe(ShopperCountry::SESSION);
    expect($resolved->guessed())->toBeFalse();
});

it('never launders a geo guess into the session as though the shopper had said it', function () {
    /*
     * The session tier outranks the header tier, so if a guess were written
     * there it would read back on the next request as a statement of fact and
     * "we think you are in Saudi Arabia" would silently become "you told us you
     * are". remember() is the only writer and only the country selector calls
     * it.
     */
    $request = request()->create('/', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => 'SA']);
    $request->setLaravelSession(app('session')->driver());

    ShopperCountry::for($request);

    expect($request->session()->has(ShopperCountry::SESSION_KEY))
        ->toBeFalse('A detected country was written to the session, where it would be read back as an explicit choice.');
});

it('ignores a country code that is not one', function () {
    // Everything feeding this is user-controlled — a header, a cookie, a
    // session written by an older build. Cloudflare's own "XX" for an
    // anonymised address is the common case.
    foreach (['XX', 'ZZZ', '1', '', 'united arab emirates'] as $junk) {
        $request = request()->create('/', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => $junk]);

        expect(ShopperCountry::for($request)->source)
            ->toBe(ShopperCountry::DEFAULT, 'A bogus country header was taken at its word: ' . $junk);
    }
});

/*
|------------------------------------------------------------------------------
| 3. The checkout and the storefront give the same answer
|------------------------------------------------------------------------------
*/

it('agrees between the home page and the checkout for the same visitor', function () {
    /*
     * The point of having one resolver. Both screens are asked about the same
     * Saudi visitor; if they can disagree, one of them is lying to him.
     */
    coSet(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    // One browser, one cart, both screens — which is the only way the two can
    // be shown to agree rather than merely to be right separately.
    $cart = coCart();

    $rates = coShopper($cart)->withHeader('CF-IPCountry', 'SA')
        ->postJson('/api/checkout/rates', ['country' => 'SA', 'state' => 'Riyadh'])->assertOk();

    $home = coShopper($cart)->withHeader('CF-IPCountry', 'SA')->get('/')->assertOk()->getContent();

    expect($rates->json('deliveryText'))->toBe('Delivered across Saudi Arabia');
    expect(str_contains($home, 'Delivered across Saudi Arabia'))
        ->toBeTrue('The home page and the checkout disagree about where the same visitor is.');
});

it('does not regress the case the deliveryText docblock exists to prevent', function () {
    /*
     * The rule that must survive every refactor: the default is a UAE promise
     * and is offered to the UAE alone. Asserted on the class that now owns it,
     * so it is pinned wherever the line is printed rather than only at the
     * checkout.
     */
    coSet('store_country', 'AE');
    coSet('delivery_default_text', '1–3 days fast delivery all over UAE');

    $line = app(DeliveryLine::class);

    expect($line->for('AE'))->toBe('1–3 days fast delivery all over UAE');

    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $gulf) {
        expect($line->for($gulf))
            ->toBe('', "The UAE promise leaked back out to {$gulf}.");
    }
});

it('lets an explicit row win even for the store country', function () {
    // Including the UAE — the docblock says "for any country, including the
    // UAE", and that is the owner's only way to change or silence the default.
    coSet('store_country', 'AE');
    coSet(DeliveryLine::SETTING, [['country' => 'AE', 'text' => 'Same-day in Dubai']]);

    expect(app(DeliveryLine::class)->for('AE'))->toBe('Same-day in Dubai');
});

it('survives a delivery_texts value that is not the shape it expects', function () {
    // A row written by an older build, or a hand-edited settings row, must not
    // take the storefront down.
    foreach ([['not-a-row'], [['text' => 'no country']], [['country' => 'TOOLONG', 'text' => 'x']], 'garbage'] as $junk) {
        coSet(DeliveryLine::SETTING, $junk);

        expect(app(DeliveryLine::class)->for('SA'))->toBe('');
    }
});

/*
|------------------------------------------------------------------------------
| 4. The owner can actually write a line — the screen that did not exist
|------------------------------------------------------------------------------
*/

it('saves per-country delivery lines instead of reporting success and writing nothing', function () {
    /*
     * THE FAILURE MODE THIS TEST EXISTS FOR, named in SETTING_RULES' own
     * comment: a key that is not on that list is skipped, the endpoint answers
     * `ok`, and the screen says Saved while nothing whatever reaches the
     * database. `delivery_texts` was not on the list, so the admin screen this
     * lane adds would have done precisely that.
     */
    $response = $this->actingAs(coAdmin(), 'admin')->putJson('/admin-api/settings', [
        'settings' => [
            'delivery_texts' => [
                ['country' => 'SA', 'text' => 'Delivered across Saudi Arabia'],
                ['country' => 'KW', 'text' => 'Delivered across Kuwait'],
            ],
        ],
    ])->assertOk();

    expect($response->json('saved'))->toBe(1, 'The endpoint reported success without saving anything.');
    expect($response->json('rejected'))->toBeNull('delivery_texts was rejected as an unknown key.');

    // Read back through the service the storefront reads through, not the row.
    expect(app(DeliveryLine::class)->for('SA'))->toBe('Delivered across Saudi Arabia');
    expect(app(DeliveryLine::class)->for('KW'))->toBe('Delivered across Kuwait');
});

it('refuses a delivery-lines payload that is not rows of country and text', function () {
    // A refusal is better than a coerced value: the alternative is storing
    // something the storefront will read back as a country code.
    $response = $this->actingAs(coAdmin(), 'admin')->putJson('/admin-api/settings', [
        'settings' => ['delivery_texts' => [['country' => 'NOT-A-CODE', 'text' => 'x']]],
    ])->assertStatus(422);

    expect($response->json('ok'))->toBeFalse();
    expect(app(SettingsService::class)->get(DeliveryLine::SETTING, []))->toBe([]);
});

it('refuses a row whose country or line is not text, without a PHP warning', function () {
    // `(string) []` is a warning and the literal "Array", which would be
    // refused for the wrong reason and log a line that says nothing about the
    // payload that caused it.
    foreach ([[['country' => ['SA'], 'text' => 'x']], [['country' => 'SA', 'text' => ['x']]]] as $payload) {
        $this->actingAs(coAdmin(), 'admin')->putJson('/admin-api/settings', [
            'settings' => ['delivery_texts' => $payload],
        ])->assertStatus(422);
    }

    expect(app(SettingsService::class)->get(DeliveryLine::SETTING, []))->toBe([]);
});

it('lets the owner clear every line back to nothing', function () {
    // Removing the last row must actually remove it. An empty array that is
    // treated as "no value supplied" would leave the old rows in place and the
    // screen would show them again after a reload.
    coSet(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    $this->actingAs(coAdmin(), 'admin')->putJson('/admin-api/settings', [
        'settings' => ['delivery_texts' => []],
    ])->assertOk();

    expect(app(DeliveryLine::class)->for('SA'))->toBe('');
});

/*
|------------------------------------------------------------------------------
| 5. Changing the country at checkout changes the line, live
|------------------------------------------------------------------------------
*/

it('renders the delivery line element even when there is nothing to say yet', function () {
    /*
     * THE ELEMENT THAT IS NOT THERE CANNOT BE UNHIDDEN.
     *
     * checkout.js updates the line after a country change with
     * `el.hidden = data.deliveryText === ''` over `.kbb-delivery-line`. The
     * partial only rendered that element when the page happened to load with a
     * non-empty line — so a shopper who arrived as Saudi Arabia (no line, no
     * element) and switched to the UAE was handed the UAE promise by the
     * endpoint and had nowhere to put it. The line simply never appeared.
     *
     * This is the same defect, and the same repair, as the gift-wrapping row
     * two partials away: rendered and hidden, not omitted.
     */
    $html = coShopper(coCart())
        ->withSession(['_old_input' => ['billing_country' => 'SA', 'billing_state' => 'Riyadh']])
        ->get('/checkout/')->assertOk()->getContent();

    $elements = [];
    $found = preg_match_all('/<div[^>]*class="[^"]*\bkbb-delivery-line\b[^"]*"[^>]*>/i', $html, $elements);

    expect($found > 0)
        ->toBeTrue('The delivery line element is absent for a country with no line, so a country change can never reveal it.');

    // And it must be hidden, not showing an empty promise with a truck icon.
    foreach ($elements[0] as $tag) {
        expect(str_contains($tag, 'hidden'))
            ->toBeTrue('The delivery line element is rendered visible with nothing in it: ' . $tag);
    }
});

it('hands the browser the right line for the country just chosen', function () {
    /*
     * The live half of the owner's request: the country selector does not
     * reload the page, it posts to /api/checkout/rates and swaps fragments in.
     * That endpoint must answer with the destination's own line, and an empty
     * string is a real answer meaning "say nothing".
     */
    coSet(DeliveryLine::SETTING, [['country' => 'KW', 'text' => 'Delivered across Kuwait']]);

    $cart = coCart();

    $ae = coShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Dubai'])->assertOk();
    $kw = coShopper($cart)->postJson('/api/checkout/rates', ['country' => 'KW', 'state' => ''])->assertOk();
    $sa = coShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA', 'state' => 'Riyadh'])->assertOk();

    expect(str_contains((string) $ae->json('deliveryText'), 'UAE'))->toBeTrue();
    expect($kw->json('deliveryText'))->toBe('Delivered across Kuwait');
    expect($sa->json('deliveryText'))->toBe('', 'A country with no line written for it was given one.');
});

it('remembers the country the shopper chose at checkout so the rest of the shop agrees', function () {
    /*
     * "The system should double check that if user change the country from the
     * list" — the owner's words. Changing the country at checkout is the one
     * unambiguous statement of where he is, so the home page must stop
     * disagreeing with it on the next page load.
     */
    coSet(DeliveryLine::SETTING, [['country' => 'KW', 'text' => 'Delivered across Kuwait']]);

    $cart = coCart();

    coShopper($cart)->postJson('/api/checkout/rates', ['country' => 'KW', 'state' => ''])->assertOk();

    // Same session, and a geo header still insisting on the UAE — the shopper's
    // own choice must win over it.
    $html = $this->withHeader('CF-IPCountry', 'AE')->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'Delivered across Kuwait'))
        ->toBeTrue('The home page ignored the country the shopper had just chosen at checkout.');
});

/*
|------------------------------------------------------------------------------
| 6. The screen itself is on the page
|------------------------------------------------------------------------------
*/

it('puts a Delivery lines tab on Store, Delivery and Shipping', function () {
    /*
     * The half of this lane that is a screen. A save path with no way to reach
     * it is the same gap `delivery_texts` already had — a documented escape
     * hatch nobody could open.
     *
     * ASSERTED ON ELEMENTS AND ATTRIBUTES, NOT ON A CLASS NAME. The console
     * inlines its own stylesheet into the document, so a bare search for
     * `dl-row` matches a CSS rule whether or not any element or any line of
     * code carries it — the trap ShopperPathTruthTest records.
     */
    $html = $this->actingAs(coAdmin(), 'admin')
        ->get('/' . \App\Services\AdminPathService::current())
        ->assertOk()->getContent();

    $tabs = [];
    preg_match_all('/data-shtab="lines"/', $html, $tabs);

    expect(count($tabs[0]))->toBeGreaterThan(0, 'The Delivery lines tab is not on the Delivery & Shipping screen.');

    // The picker has to offer real country NAMES, not codes — the owner is
    // choosing "Saudi Arabia", not "SA".
    expect(str_contains($html, 'KBB_COUNTRY_NAMES'))
        ->toBeTrue('The country list the picker is built from never reached the page.');
    expect(preg_match('/"SA"\s*:\s*"Saudi Arabia"/', $html) === 1)
        ->toBeTrue('The country list reached the page without names in it.');

    // And it must post to the key that the settings endpoint now knows how to
    // store, rather than to a name nothing reads.
    expect(str_contains($html, 'delivery_texts'))
        ->toBeTrue('The screen never names the setting it is supposed to write.');
});

/*
|------------------------------------------------------------------------------
| 7. The geo signal that does not need a header
|------------------------------------------------------------------------------
*/

it('reads the time-zone cookie the browser writes, which encryption used to eat', function () {
    /*
     * THE ONLY GEO SIGNAL THIS SHOP HAS THAT DOES NOT DEPEND ON THE HOST.
     *
     * resources/js/kbb/app.js writes `kbb_tz` from the browser's own time zone,
     * and ExtendedDelivery::detect() maps it to a country when no country
     * header is present. On this host that matters more than the header does:
     * the site is on Hostinger shared hosting with no Cloudflare proxy and no
     * trusted-proxy configuration anywhere, so `CF-IPCountry` may never arrive
     * at all.
     *
     * IT WAS BEING READ AS NULL, ALWAYS. EncryptCookies drops any incoming
     * cookie that does not carry Laravel's encryption envelope, and a cookie
     * written in JavaScript never does — so the time-zone tier had never once
     * fired in a real browser since it was added. Nothing errored: a signal
     * that never arrives and a signal that says "I don't know" are the same
     * shape from the reading end, which is exactly why this needs a test rather
     * than a comment.
     *
     * SENT THE WAY A BROWSER SENDS IT — through call()'s plain cookie jar, not
     * through withUnencryptedCookie(), which marks the cookie "do not decrypt"
     * and would pass whether or not the exemption exists. Using that helper
     * here would be a test that cannot fail.
     */
    coSet(DeliveryLine::SETTING, [['country' => 'KW', 'text' => 'Delivered across Kuwait']]);

    $html = $this->call('GET', '/', [], ['kbb_tz' => 'Asia/Kuwait'])->assertOk()->getContent();

    expect(str_contains($html, 'Delivered across Kuwait'))
        ->toBeTrue('The browser time-zone cookie never reached the country resolver — cookie encryption is eating it again.');
});

it('ignores a time zone it has no country for, rather than guessing', function () {
    // ExtendedDelivery::TIMEZONE_COUNTRY is a curated table, not the full IANA
    // list, and anything off it must fall through to the store default rather
    // than become a country.
    $html = $this->call('GET', '/', [], ['kbb_tz' => 'Antarctica/Troll'])->assertOk()->getContent();

    expect(str_contains((string) coHomeLine($html), 'UAE'))
        ->toBeTrue('An unmapped time zone stopped the store country answering.');
});
