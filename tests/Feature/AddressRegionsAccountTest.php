<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Tests\Support\EnglishRenderWalk;

/**
 * My account -> Addresses carries the same Emirate / state list as the
 * checkout (Lane AD): a list that follows the country, directly above Country,
 * stored as the list's English name. Governed by the same switch, Appearance ->
 * Checkout page -> Fields & attention -> "Emirate / state as a list" (on).
 * The OFF render is pinned byte-identical in AddressRegionsCheckoutTest.
 */
function adAcct(): Customer
{
    return Customer::create(['email' => 'acct' . uniqid() . '@example.com', 'name' => 'Ada Shopper', 'password' => 'secret-secret']);
}

function adAs(Customer $customer)
{
    return test()->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

function adBook(array $over = []): array
{
    return array_merge([
        'type' => 'shipping', 'first_name' => 'Ada', 'line1' => 'Marina Heights, Apt 1203',
        'line2' => 'Dubai Marina', 'state' => 'Dubai', 'country' => 'AE',
    ], $over);
}

it('reads Building / Apartment or Villa, Area / Street, then the Emirate directly above Country', function () {
    // MUTATION: render the OFF branch for ON in addresses.blade.php. RED.
    $html = adAs(adAcct())->get('/my-account/edit-address')->assertOk()->getContent();

    expect($html)->toMatch('#<select name="state" id="ab-state" autocomplete="address-level1" required>#')
        ->and($html)->toContain('<span>Building / Apartment or Villa *</span>')
        ->and($html)->toContain('<span>Area / Street</span>')
        ->and($html)->toContain('<span><span id="ab-state-label">Emirate</span> *</span>')
        // No City box: the Emirate is the city.
        ->and($html)->not->toContain('name="city"')
        ->and(strpos($html, 'name="line1"'))->toBeLessThan(strpos($html, 'name="line2"'))
        ->and(strpos($html, 'name="line2"'))->toBeLessThan(strpos($html, 'name="state"'))
        ->and(strpos($html, 'name="state"'))->toBeLessThan(strpos($html, 'name="country"'))
        ->and($html)->toContain("\u{2068}دبي\u{2069} — Dubai")
        ->and(substr_count($html, 'id="kbb-state-lists"'))->toBe(1);
});

it('refuses an emirate that is not in the list, and stores the list\'s name as city and state', function () {
    /*
     * MUTATION: drop the canonical() check in AddressController::validated().
     * RED -- "Atlantis" is saved. Drop the city copy. RED -- no city.
     */
    $customer = adAcct();

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'Atlantis']))->assertSessionHasErrors(['state']);
    adAs($customer)->post('/my-account/addresses', adBook(['state' => null]))->assertSessionHasErrors(['state']);
    expect($customer->addresses()->count())->toBe(0);

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'rak']))->assertSessionHasNoErrors();
    expect($customer->addresses()->first()->only(['line1', 'line2', 'city', 'state']))->toBe([
        'line1' => 'Marina Heights, Apt 1203', 'line2' => 'Dubai Marina', 'city' => 'Ras Al Khaimah', 'state' => 'Ras Al Khaimah',
    ]);
});

it('shows an old free-text city in Area / Street, so saving the address does not lose it', function () {
    /*
     * A typed address with "JLT" as its city and no state: the form shows JLT
     * in Area / Street and the Emirate on "Select"; choosing Dubai and saving
     * stores line 2 "JLT", city and state "Dubai". A WooCommerce address with
     * the area as state and the emirate as city reads the same way round.
     * MUTATION: drop the city from AddressRegions::split(). RED.
     */
    $customer = adAcct();
    $jlt = $customer->addresses()->create(['type' => 'shipping', 'first_name' => 'Ada', 'line1' => 'Cluster D', 'city' => 'JLT', 'country' => 'AE']);
    $woo = $customer->addresses()->create(['type' => 'billing', 'first_name' => 'Ada', 'line1' => 'Villa 3', 'city' => 'Dubai', 'state' => 'Al Barsha', 'country' => 'AE']);

    $html = adAs($customer)->get('/my-account/edit-address/' . $jlt->id)->assertOk()->getContent();
    expect($html)->toContain('<input type="text" name="line2" value="JLT">')
        ->and($html)->toContain('<option value="" disabled selected>Select</option>');

    adAs($customer)->post('/my-account/addresses/' . $jlt->id, adBook(['line1' => 'Cluster D', 'line2' => 'JLT', 'state' => 'Dubai']))
        ->assertSessionHasNoErrors();
    expect($jlt->fresh()->only(['line2', 'city', 'state']))->toBe(['line2' => 'JLT', 'city' => 'Dubai', 'state' => 'Dubai']);

    $html = adAs($customer)->get('/my-account/edit-address/' . $woo->id)->getContent();
    expect($html)->toContain('<input type="text" name="line2" value="Al Barsha">')
        ->and($html)->toMatch('#<option value="Dubai"\s+selected#');
});

it('keeps a typed Town / city box for a country without a list', function () {
    $customer = adAcct();
    $gb = $customer->addresses()->create(['type' => 'shipping', 'first_name' => 'Ada', 'line1' => '1 High St', 'city' => 'London', 'state' => 'Greater London', 'country' => 'GB']);

    $html = adAs($customer)->get('/my-account/edit-address/' . $gb->id)->getContent();
    expect($html)->toContain('<input type="text" name="state" id="ab-state" autocomplete="address-level2" required value="London">')
        ->and($html)->toContain('<span id="ab-state-label">Town / city</span>');

    adAs($customer)->post('/my-account/addresses/' . $gb->id, adBook(['state' => 'Dover', 'country' => 'GB', 'line2' => 'Harbour Rd']))
        ->assertSessionHasNoErrors();
    expect($gb->fresh()->only(['line2', 'city', 'state']))->toBe(['line2' => 'Harbour Rd', 'city' => 'Dover', 'state' => 'Dover']);
});

it('takes the typed city and free state again with the switch off', function () {
    app(CheckoutPage::class)->save(['state_list' => false]);
    SettingsService::forgetMemo();
    $customer = adAcct();

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'Atlantis', 'city' => 'Al Quoz']))->assertSessionHasNoErrors();
    expect($customer->addresses()->first()->only(['city', 'state']))->toBe(['city' => 'Al Quoz', 'state' => 'Atlantis']);
});
