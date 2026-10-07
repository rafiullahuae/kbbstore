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
        'type' => 'shipping', 'first_name' => 'Ada', 'line1' => '12 Marina Walk',
        'city' => 'Dubai Marina', 'state' => 'Dubai', 'country' => 'AE',
    ], $over);
}

it('draws the emirate as a list directly above Country', function () {
    // MUTATION: render the OFF branch for ON in addresses.blade.php. RED.
    $html = adAs(adAcct())->get('/my-account/edit-address')->assertOk()->getContent();

    expect($html)->toMatch('#<select name="state" id="ab-state"#')
        ->and($html)->toContain('<span id="ab-state-label">Emirate</span>')
        ->and(strpos($html, 'name="postcode"'))->toBeLessThan(strpos($html, 'name="state"'))
        ->and(strpos($html, 'name="state"'))->toBeLessThan(strpos($html, 'name="country"'))
        ->and($html)->toContain("\u{2068}دبي\u{2069} — Dubai")
        ->and(substr_count($html, 'id="kbb-state-lists"'))->toBe(1);
});

it('refuses a state that is not in the list, and stores the list\'s name for a spelling it knows', function () {
    /*
     * MUTATION: drop the canonical() check in AddressController::validated().
     * RED -- "Atlantis" is saved as an emirate.
     */
    $customer = adAcct();

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'Atlantis']))->assertSessionHasErrors(['state']);
    expect($customer->addresses()->count())->toBe(0);

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'rak']))->assertSessionHasNoErrors();
    expect($customer->addresses()->first()->state)->toBe('Ras Al Khaimah');

    // Still optional, as it always was here.
    adAs($customer)->post('/my-account/addresses', adBook(['state' => null]))->assertSessionHasNoErrors();
    expect($customer->addresses()->count())->toBe(2);
});

it('keeps an old free-text state the list does not know, rather than erasing it on save', function () {
    /*
     * A WooCommerce address with "Al Barsha" as its state and "Dubai Marina"
     * as its city: the form offers the saved value back as itself, selected,
     * and saving the address unchanged keeps it. A NEW unknown value is still
     * refused. MUTATION: drop $abKeep from the view, or the $existing
     * exception from the controller. RED.
     */
    $customer = adAcct();
    $address = $customer->addresses()->create(adBook(['state' => 'Al Barsha']));

    $html = adAs($customer)->get('/my-account/edit-address/' . $address->id)->assertOk()->getContent();

    expect($html)->toContain('<option value="Al Barsha" selected>Al Barsha</option>');

    adAs($customer)->post('/my-account/addresses/' . $address->id, adBook(['state' => 'Al Barsha', 'phone' => '+971500000001']))
        ->assertSessionHasNoErrors();
    expect($address->fresh()->state)->toBe('Al Barsha');

    adAs($customer)->post('/my-account/addresses/' . $address->id, adBook(['state' => 'Al Quoz']))
        ->assertSessionHasErrors(['state']);
    expect($address->fresh()->state)->toBe('Al Barsha');
});

it('selects the saved emirate in any spelling, and keeps the typed box for a country without a list', function () {
    $customer = adAcct();
    $known = $customer->addresses()->create(adBook(['state' => 'sharjah']));
    $gb = $customer->addresses()->create(adBook(['state' => 'Greater London', 'country' => 'GB', 'city' => 'London']));

    $html = adAs($customer)->get('/my-account/edit-address/' . $known->id)->getContent();
    expect($html)->toMatch('#<option value="Sharjah"\s+selected#');

    $html = adAs($customer)->get('/my-account/edit-address/' . $gb->id)->getContent();
    expect($html)->toContain('<input type="text" name="state" id="ab-state" autocomplete="address-level1" value="Greater London">')
        ->and($html)->toContain('<span id="ab-state-label">State / region</span>');

    adAs($customer)->post('/my-account/addresses/' . $gb->id, adBook(['state' => 'Kent', 'country' => 'GB', 'city' => 'Dover']))
        ->assertSessionHasNoErrors();
    expect($gb->fresh()->state)->toBe('Kent');
});

it('takes free text again with the switch off', function () {
    app(CheckoutPage::class)->save(['state_list' => false]);
    SettingsService::forgetMemo();
    $customer = adAcct();

    adAs($customer)->post('/my-account/addresses', adBook(['state' => 'Atlantis']))->assertSessionHasNoErrors();
    expect($customer->addresses()->first()->state)->toBe('Atlantis');
});
