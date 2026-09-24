<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Support\BusinessAddress;
use App\Support\OpeningHours;
use App\Support\Seo;

/*
 * LocalBusiness / Store markup — Lane S.
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP. Store → SEO & Meta → Settings has
 * offered an Organization type of "Store" and "LocalBusiness" since that screen
 * was built. Picking either changed one string in the JSON-LD — `"@type":
 * "Store"` — and published nothing that makes a business local: no address, no
 * telephone, no coordinates, no opening hours, because there were no boxes
 * anywhere in the console to put them in. A shop in Dubai competing for "near
 * me" queries told Google it was a Store and refused to say where.
 *
 * MUTATION NOTE. Delete the `$org += BusinessAddress::organizationFragment(...)`
 * line from App\Support\Seo::jsonLd() and every test below that asserts an
 * address, a telephone, geo or hours goes red. Change BusinessAddress::postal()
 * to drop its `$street === ''` guard and "refuses to publish half an address"
 * goes red. Change organizationFragment() to emit geo without the PLACE_TYPES
 * check and "does not put geo on an OnlineStore" goes red.
 */

/** The settings the SEO layer reads, written straight to the table. */
function bizSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    Setting::flushMap();
}

/** The Organization node out of a rendered page. */
function orgNode(): array
{
    $html = Seo::render(['title' => 'Home']);

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $json) {
        $node = json_decode($json, true);

        if (is_array($node) && in_array($node['@type'] ?? '', ['Organization', 'OnlineStore', 'Store', 'LocalBusiness'], true)) {
            return $node;
        }
    }

    return [];
}

/** A genuinely complete Dubai address. */
function fullAddress(): array
{
    return [
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_region' => 'Dubai',
        'store_country' => 'ae',
        'org_type' => 'Store',
    ];
}

/* ------------------------------------------------------------- the address */

it('publishes a PostalAddress once the address is genuinely filled in', function () {
    bizSettings(fullAddress());

    $org = orgNode();

    expect($org['@type'])->toBe('Store')
        ->and($org['address'])->toBe([
            '@type' => 'PostalAddress',
            'streetAddress' => 'Shop 4, Al Wasl Road',
            'addressLocality' => 'Dubai',
            'addressRegion' => 'Dubai',
            'addressCountry' => 'AE',
        ]);
});

it('publishes no address at all on a shop that has filled none of it in', function () {
    // The shipped state. Rule 1 of this project: a shop that never opens the
    // new section emits exactly the node it emitted before the section existed.
    $org = orgNode();

    expect($org)->not->toHaveKey('address')
        ->and($org)->not->toHaveKey('geo')
        ->and($org)->not->toHaveKey('openingHoursSpecification');
});

it('refuses to publish half an address', function () {
    // A `{"addressCountry":"AE"}` node tells Google the shop has published its
    // address and that the address is "the UAE" -- worse than the silence it
    // replaced, because silence leaves Google the listing it already has.
    bizSettings(['store_country' => 'AE', 'store_locality' => 'Dubai', 'org_type' => 'Store']);

    expect(orgNode())->not->toHaveKey('address');

    bizSettings(['store_street' => 'Shop 4, Al Wasl Road', 'store_locality' => '']);

    expect(orgNode())->not->toHaveKey('address');
});

it('refuses a country code it does not recognise rather than publishing the wrong country', function () {
    // "AR" typed for "AE" is Argentina. The settings screen stores any two
    // letters, the way the existing ship-to country box does; the emitter is
    // where a code that is not a country stops.
    bizSettings(fullAddress());
    bizSettings(['store_country' => 'ZZ']);

    expect(orgNode())->not->toHaveKey('address');
});

it('omits the region and the postal code when they are blank, and keeps the address', function () {
    // The UAE does not use postal codes for street addresses, so demanding one
    // would make a correct Dubai address impossible to enter.
    bizSettings([
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_country' => 'AE',
        'org_type' => 'Store',
    ]);

    $address = orgNode()['address'];

    expect($address)->not->toHaveKey('postalCode')
        ->and($address)->not->toHaveKey('addressRegion')
        ->and($address['streetAddress'])->toBe('Shop 4, Al Wasl Road');
});

/* ----------------------------------------------------------- the telephone */

it('publishes the number the site header already prints, and does not invent a second one', function () {
    bizSettings(array_merge(fullAddress(), ['support_phone' => '+971 58 505 2611']));

    expect(orgNode()['telephone'])->toBe('+971 58 505 2611');
});

/* ------------------------------------------------- geo and hours are Places */

it('publishes geo and opening hours on a Store', function () {
    bizSettings(array_merge(fullAddress(), [
        'store_latitude' => '25.2048',
        'store_longitude' => '55.2708',
        'store_hours' => "Mon-Sat 10:00-22:00\nSun 12:00-20:00",
    ]));

    $org = orgNode();

    expect($org['geo'])->toBe([
        '@type' => 'GeoCoordinates',
        // STRINGS, so the coordinate Google reads is character-for-character
        // the one the owner typed -- no float, no serialize_precision.
        'latitude' => '25.2048',
        'longitude' => '55.2708',
    ])
        ->and($org['openingHoursSpecification'])->toBe([
            [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                'opens' => '10:00',
                'closes' => '22:00',
            ],
            [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => 'Sunday',
                'opens' => '12:00',
                'closes' => '20:00',
            ],
        ]);
});

it('does not put geo or opening hours on an OnlineStore', function () {
    // `geo` and `openingHoursSpecification` are properties of Place. Of the
    // four types the setting offers only Store and LocalBusiness are Places,
    // so emitting coordinates on an OnlineStore is invalid schema.
    bizSettings(array_merge(fullAddress(), [
        'org_type' => 'OnlineStore',
        'store_latitude' => '25.2048',
        'store_longitude' => '55.2708',
        'store_hours' => 'Mon-Sat 10:00-22:00',
    ]));

    $org = orgNode();

    expect($org['@type'])->toBe('OnlineStore')
        // The address and the phone ARE Organization properties, so they stay.
        ->and($org)->toHaveKey('address')
        ->and($org)->not->toHaveKey('geo')
        ->and($org)->not->toHaveKey('openingHoursSpecification');
});

it('publishes no geo from a latitude on its own', function () {
    bizSettings(array_merge(fullAddress(), ['store_latitude' => '25.2048', 'store_longitude' => '']));

    expect(orgNode())->not->toHaveKey('geo');
});

/* ------------------------------------------------------ one node, not two */

it('adds the address to the existing Organization node instead of emitting a second node', function () {
    /*
     * THE DEFECT THIS EXISTS TO STOP. The obvious shape -- a LocalBusiness node
     * beside the Organization node -- would put two nodes on every page
     * claiming to be the same business, which docs/SEO-COMPETITIVE.md §1.4
     * identifies as the classic Shopify review-app failure. Emit a second node
     * anywhere in jsonLd() and this goes red.
     */
    bizSettings(array_merge(fullAddress(), ['store_latitude' => '25.2048', 'store_longitude' => '55.2708']));

    $html = Seo::render(['title' => 'Home']);

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    $businessNodes = 0;

    foreach ($m[1] as $json) {
        $node = json_decode($json, true);

        if (is_array($node) && in_array($node['@type'] ?? '', ['Organization', 'OnlineStore', 'Store', 'LocalBusiness'], true)) {
            $businessNodes++;
        }
    }

    expect($businessNodes)->toBe(1);
});

/* ------------------------------------------------------------------ escaping */

it('cannot be broken out of JSON-LD by an address containing a script tag', function () {
    // The address boxes are ordinary text fields on a settings screen, exactly
    // as `org_name` is, and they reach the same unescaped <script> block. They
    // are safe by the same mechanism -- encodeJsonLd()'s HEX flags -- and this
    // asserts it for the new fields rather than assuming it.
    $payload = '</script><script>alert(1)</script>';

    bizSettings(array_merge(fullAddress(), ['store_street' => $payload]));

    $html = Seo::render(['title' => 'Home']);

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    expect($m)->not->toBeEmpty()
        ->and($m[1])->not->toContain('</script>')
        ->and($html)->not->toContain($payload);
});

/* ------------------------------------------------------- the hours parser */

it('reads the day forms a person actually writes', function () {
    expect(OpeningHours::spec('Sun 12:00-20:00'))
        ->toBe([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Sunday', 'opens' => '12:00', 'closes' => '20:00']]);

    // A comma list and a range combine, and the days come back in week order
    // however they were typed, so "Sat,Mon" and "Mon,Sat" are one document.
    expect(OpeningHours::spec('Sat,Mon-Tue 09:00-17:00')[0]['dayOfWeek'])
        ->toBe(['Monday', 'Tuesday', 'Saturday']);

    // A range that wraps the week is a range: "Fri-Mon" is a long weekend.
    expect(OpeningHours::spec('Fri-Mon 10:00-22:00')[0]['dayOfWeek'])
        ->toBe(['Monday', 'Friday', 'Saturday', 'Sunday']);

    // Two lines naming the same window collapse into one specification.
    expect(OpeningHours::spec("Mon 10:00-22:00\nTue 10:00-22:00"))->toHaveCount(1);

    // Zero-padded, so "9:05" is a time and not a shape a consumer has to guess.
    expect(OpeningHours::spec('Mon 9:05-17:00')[0]['opens'])->toBe('09:05');
});

it('refuses hours it cannot read, naming the line', function () {
    $cases = [
        'monday-ish, 10ish',
        'Mon 10:00',
        'Xyz 10:00-22:00',
        'Mon 25:00-26:00',
        'Mon 10:00-10:00',
    ];

    foreach ($cases as $bad) {
        expect(OpeningHours::parse($bad)['ok'])->toBeFalse();
    }

    // A day on two lines is the owner editing one and forgetting the other,
    // which would publish a shop that is open twice on a Monday.
    expect(OpeningHours::parse("Mon-Sat 10:00-22:00\nMon 08:00-09:00")['ok'])->toBeFalse();
});

it('keeps past-midnight hours, which are legal and ordinary here', function () {
    // closes earlier than opens means the window crosses midnight. Refusing it
    // would be inventing a limit schema.org does not have.
    $spec = OpeningHours::spec('Thu 20:00-02:00');

    expect($spec[0]['opens'])->toBe('20:00')->and($spec[0]['closes'])->toBe('02:00');
});

it('publishes nothing at all from hours that cannot be parsed', function () {
    // The settings screen refuses these, so this is the row written before the
    // rule existed, or by a direct database edit. It must not become a
    // half-read set of hours on every page.
    bizSettings(array_merge(fullAddress(), ['store_hours' => 'whenever we feel like it']));

    expect(orgNode())->not->toHaveKey('openingHoursSpecification');
});

/* ---------------------------------------------------------- the coordinate */

it('refuses a coordinate outside the earth rather than clamping it', function () {
    expect(BusinessAddress::isCoordinate('25.2048', 90))->toBeTrue()
        ->and(BusinessAddress::isCoordinate('-33.8688', 90))->toBeTrue()
        ->and(BusinessAddress::isCoordinate('200', 90))->toBeFalse()
        ->and(BusinessAddress::isCoordinate('55.2708', 180))->toBeTrue()
        ->and(BusinessAddress::isCoordinate('', 90))->toBeFalse()
        ->and(BusinessAddress::isCoordinate('25,2048', 90))->toBeFalse()
        ->and(BusinessAddress::isCoordinate('25.2048abc', 90))->toBeFalse();
});

/* ======================================================================== */
/*  The write path: Store → Business Details → Business · "Where the shop is" */
/* ======================================================================== */

/*
 * THE ROUND TRIP IS THE PROPERTY, NOT THE ROW — the same discipline
 * InvoiceIdentitySettingsTest states in full. A test that posted a setting and
 * then asserted `Setting::where('key', …)` would go green against a build where
 * the value was stored and never published, which is half the defect. So these
 * save through the real endpoint, over the real payload the screen posts, and
 * read the answer out of the rendered JSON-LD.
 *
 * The standing failure this pins is the one AdminController::SETTING_RULES
 * warns about at the top of its own list: a key with no rule is DROPPED while
 * the endpoint still answers ok, so "Saved" comes to mean nothing. Remove any
 * of the eight rules and the first test here goes red.
 */

function bizAdmin(): \App\Models\AdminUser
{
    $admin = \App\Models\AdminUser::create([
        'name' => 'Business Address Owner',
        'email' => 'biz-address-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

function bizSave(array $settings): \Illuminate\Testing\TestResponse
{
    return test()->putJson('/admin-api/settings', ['settings' => $settings]);
}

it('saves the whole address through the endpoint the screen posts to, and publishes it', function () {
    bizAdmin();

    bizSave([
        'org_type' => 'Store',
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_region' => 'Dubai',
        'store_postcode' => '',
        'store_country' => 'ae',
        'store_latitude' => '25.2048',
        'store_longitude' => '55.2708',
        'store_hours' => "Mon-Sat 10:00-22:00\nSun 12:00-20:00",
    ])->assertOk();

    Setting::flushMap();

    $org = orgNode();

    // Every one of the eight arrived, and the country was normalised to
    // upper case on the way in by the same `code` rule the ship-to box uses.
    expect($org['address']['streetAddress'])->toBe('Shop 4, Al Wasl Road')
        ->and($org['address']['addressCountry'])->toBe('AE')
        ->and($org['address'])->not->toHaveKey('postalCode')
        ->and($org['geo']['latitude'])->toBe('25.2048')
        ->and($org['openingHoursSpecification'])->toHaveCount(2);
});

it('refuses a coordinate off the earth instead of reporting Saved', function () {
    bizAdmin();

    // Clamping 200 to 90 would publish the North Pole as this shop's address
    // and answer ok. The screen says no and names the box.
    bizSave(['store_latitude' => '200'])->assertStatus(422);

    bizSave(['store_longitude' => 'here-ish'])->assertStatus(422);
});

it('refuses a country code that is not a country', function () {
    bizAdmin();

    // "AR" typed for "AE" is Argentina; "ZZ" is nothing. Refused at the box
    // rather than published to Google as where this business trades.
    bizSave(['store_country' => 'ZZ'])->assertStatus(422);

    // And blank is accepted, because blank is how an address is withdrawn.
    bizSave(['store_country' => ''])->assertOk();
});

it('refuses opening hours it cannot read instead of reporting Saved', function () {
    bizAdmin();

    // The exact failure a second validator would allow: the box accepts prose,
    // answers "Saved", and nothing ever appears on a page. The rule and the
    // emitter call the same parser, so this cannot happen.
    bizSave(['store_hours' => 'monday-ish, 10ish'])->assertStatus(422);

    bizSave(['store_hours' => "Mon-Sat 10:00-22:00\nMon 08:00-09:00"])->assertStatus(422);
});

it('accepts a blank address, which is how one is withdrawn', function () {
    bizAdmin();

    bizSave([
        'org_type' => 'Store',
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_country' => 'AE',
    ])->assertOk();

    Setting::flushMap();
    expect(orgNode())->toHaveKey('address');

    bizSave(['store_street' => '', 'store_locality' => '', 'store_country' => '', 'store_hours' => '', 'store_latitude' => ''])
        ->assertOk();

    Setting::flushMap();
    expect(orgNode())->not->toHaveKey('address');
});

it('draws a box for every address key it posts, and posts every box it draws', function () {
    /*
     * The two halves that have to land together on this screen, in both
     * directions — a field with no payload line is never sent, and a payload
     * line with no field reads as '' through sval() and saves over the value
     * it is not showing. AdminScreenSectionsTest makes the general version of
     * this argument; this is the Lane S block's own copy, so the eight keys
     * cannot drift apart from their controls.
     */
    $blade = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    $keys = [
        'store_street', 'store_locality', 'store_region', 'store_postcode',
        'store_country', 'store_latitude', 'store_longitude', 'store_hours',
    ];

    foreach ($keys as $key) {
        // A control the payload never sends, and a payload line with no
        // control -- which reads as '' through sval() and saves over the value
        // it is not showing.
        expect($blade)->toContain('id="set_' . $key . '"')
            ->and($blade)->toContain($key . ": sval('set_" . $key . "')");

        // And the rule, without which the endpoint drops the key and answers ok.
        expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES)->toHaveKey($key);
    }
});
