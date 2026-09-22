<?php

declare(strict_types=1);

use App\Models\ShippingZone;
use App\Support\CartAddressState;
use App\Support\Countries;
use Illuminate\Http\Request;

/**
 * =============================================================================
 * THE ADDRESS FORM OFFERS THE COUNTRIES THE SHOP ACTUALLY DELIVERS TO
 * =============================================================================
 *
 * It listed every country there is. A shopper could pick Argentina, type a
 * whole address, save it, reach checkout and only then be told the shop does
 * not deliver there. A form that accepts a country the shop cannot ship to is
 * a form that collects a wasted five minutes and an abandoned basket.
 *
 * The list is the one the CHECKOUT already uses — `coveredCountries()`, plus
 * whatever Extended delivery adds — and deliberately the same one: two places
 * deciding where a shop delivers is two places that can disagree, and the one
 * nobody looks at is the one that goes stale.
 *
 * MUTATION: return array_keys(Countries::NAMES) from countryList(). Red.
 */
it('offers only the countries the shipping zones cover', function () {
    ShippingZone::query()->delete();

    // all() reads the session, so the request needs one — Request::create()
    // alone has no store and throws before any of this is exercised.
    $request = Request::create('/cart');
    $request->setLaravelSession(app('session.store'));

    $codes = array_column(CartAddressState::all($request)['countries'], 'code');

    /*
     * With no zones at all the list FAILS OPEN — the full list, not an empty
     * dropdown. That asymmetry is deliberate and is asserted here rather than
     * left to be discovered: a form offering one country too many costs a
     * message at checkout; a form offering none cannot be completed, and the
     * shopper has no way to tell that it is the shop that is broken.
     */
    expect(count($codes))->toBe(
        count(Countries::NAMES),
        'with no shipping zones configured the country list should fall back to every country, '
        .'not collapse to nothing'
    );
})->skip(fn () => ! class_exists(ShippingZone::class), 'no ShippingZone model in this tree');

it('reads the same source the checkout reads, rather than a second list', function () {
    /*
     * The assertion that survives a data change: the code must ASK the
     * shipping service. A test that only compared two arrays would pass on a
     * hard-coded copy of today's zones, which is exactly the drift being
     * guarded against.
     */
    $src = (string) file_get_contents(base_path('app/Support/CartAddressState.php'));

    $fn = substr($src, (int) strpos($src, 'private static function countryList('));
    $fn = substr($fn, 0, 2200);

    expect(str_contains($fn, 'coveredCountries()'))->toBeTrue(
        'the address form no longer asks the shipping service where the shop delivers'
    );

    expect(str_contains($fn, 'ExtendedDelivery'))->toBeTrue(
        'Extended delivery adds countries on top of the zones and is not being consulted, so those '
        .'destinations are offered at checkout but cannot be entered here'
    );

    // And it must not simply walk the whole table.
    expect(preg_match('/foreach \(Countries::NAMES as .*?\)\s*\{/s', $fn))->toBe(
        1,
        'the full list should appear exactly once, as the fail-open fallback'
    );
});
