<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Payments\Gateways\CashOnDelivery;
use App\Services\SettingsService;
use App\Support\Money;

/**
 * The admin settings WRITE path, and what a bad value does to the live store.
 *
 * PUT /admin-api/settings checked that a key was in an allowlist and then wrote
 * whatever string arrived, for every key including the money ones. Nothing on
 * the way in looked at the value, and nothing on the way out could: every
 * consumer reads these with a bare `(int)` cast, and `(int) 'abc'` is 0. So a
 * typo in the free-shipping threshold did not fail, did not warn and did not
 * show up on the settings screen afterwards — it silently set the threshold to
 * zero, which is the one value that makes EVERY order ship free.
 *
 * These tests assert CONSEQUENCES, not rule strings. A test that only checked
 * "the validator rejects a string" would still pass if someone later loosened
 * the rule to `nullable|string`, and the reader would have no way to tell. So
 * each one asks a real consumer what the shopper is now charged.
 *
 * WHY NOT THROUGH /api/checkout/session. That endpoint reads Setting::map(),
 * which memoises in a PROCESS-LEVEL static as well as the cache — the trap
 * CLAUDE.md records. flushMap() clears the cache and not the static, so inside
 * one Pest process the first call wins forever and a settings row written by a
 * test is never visible there. A test built on it fails whatever the endpoint
 * does, which is a false red, not a proof. The consumers used below all read
 * through SettingsService, whose memo can actually be dropped.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
});

function settingsAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Settings Owner',
        'email' => 'settings-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Write a settings row the way a previously-saved, valid screen would have. */
function seedSetting(string $key, string $value): void
{
    app(SettingsService::class)->set($key, $value);
}

/** Read a setting back the way the storefront will, after dropping every memo. */
function storefrontSetting(string $key, mixed $default = null): mixed
{
    Setting::flushMap();
    SettingsService::forgetMemo();

    return app(SettingsService::class)->get($key, $default);
}

// ---------------------------------------------------------------------------
// 1. free_ship — the threshold that decides whether delivery is charged at all
// ---------------------------------------------------------------------------

/**
 * Api\CheckoutController does exactly this with the stored value:
 *
 *     $freeShip = (int) ($settings['free_ship'] ?? 20000);
 *     $delivery = $subtotal >= $freeShip ? 0 : $flatDelivery;
 *
 * so the threshold reaching zero is not a cosmetic problem: `$subtotal >= 0` is
 * true for every basket that has ever existed, and the store ships everything
 * free from that moment until somebody notices.
 */
it('does not let a typo in the free-shipping threshold make every order ship free', function () {
    seedSetting('free_ship', '20000');      // free over AED 200

    settingsAdmin();

    // The operator fat-fingers the threshold field.
    test()->putJson('/admin-api/settings', ['settings' => ['free_ship' => 'abc']]);

    // The threshold the storefront will compare against. Unfixed this is 0.
    $threshold = (int) storefrontSetting('free_ship', '20000');

    expect($threshold)->toBe(20000);

    // And therefore a AED 150 basket is still under it, so delivery is charged.
    expect(15000 >= $threshold)->toBeFalse();
});

it('keeps the previously saved free-shipping threshold when the new value is refused', function () {
    seedSetting('free_ship', '20000');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['free_ship' => 'abc']])
        ->assertStatus(422);

    expect(Setting::query()->where('key', 'free_ship')->value('value'))->toBe('20000');
});

// ---------------------------------------------------------------------------
// 2. cod_fee — a surcharge that silently stops being charged
// ---------------------------------------------------------------------------

it('does not let a typo in the COD fee drop the surcharge from every order', function () {
    seedSetting('cod_fee', '500');          // AED 5 handling fee

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => 'abc']]);

    Setting::flushMap();
    SettingsService::forgetMemo();

    // The gateway that actually charges it, asked the way checkout asks.
    $cod = app(CashOnDelivery::class);

    expect($cod->feeFils(15000))->toBe(500)
        // And the shopper is still told about it on the payment row.
        ->and($cod->description(15000))->toContain('handling fee');
});

/**
 * The decimal trap, which is worse than the letters one because it looks like
 * it worked. These fields are in FILS — the screen multiplies by 100 before it
 * posts — so a hand-typed "12.50" is not AED 12.50, it is 12 fils after the
 * `(int)` cast: AED 0.12, a hundredfold under-charge that reads back on the
 * settings screen as a plausible number.
 */
it('refuses a decimal in a fils field rather than truncating it to a hundredth of the value', function () {
    seedSetting('cod_fee', '1250');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => '12.50']])
        ->assertStatus(422);

    expect(app(CashOnDelivery::class)->feeFils(15000))->toBe(1250);
});

// ---------------------------------------------------------------------------
// 3. gift_fee
// ---------------------------------------------------------------------------

it('does not let a typo in the gift-wrap fee zero it', function () {
    seedSetting('gift_enabled', '1');
    seedSetting('gift_fee', '1500');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['gift_fee' => 'abc']]);

    // Store\CheckoutController::giftFee() reads exactly this.
    expect((int) storefrontSetting('gift_fee', '1500'))->toBe(1500);
});

// ---------------------------------------------------------------------------
// 4. A money value too large for the 32-bit column it ends up in
// ---------------------------------------------------------------------------

/**
 * Every money column in this schema is a signed 32-bit integer, so the largest
 * storable amount is Money::MAX_FILS. `settings.value` is longText and will
 * hold anything; the break happens later, when the number is added into an
 * order total and MySQL refuses the INSERT in strict mode. Caught on the way
 * in, while the operator is still looking at the screen.
 */
it('refuses a money setting larger than the money column it will be added into', function () {
    seedSetting('cod_fee', '500');

    settingsAdmin();

    $tooBig = (string) (\App\Services\Import\Money::MAX_FILS + 1);

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => $tooBig]])
        ->assertStatus(422);

    expect(app(CashOnDelivery::class)->feeFils(15000))->toBe(500);
});

it('refuses a negative fee', function () {
    seedSetting('cod_fee', '500');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => '-100']])
        ->assertStatus(422);

    expect(app(CashOnDelivery::class)->feeFils(15000))->toBe(500);
});

it('accepts the largest amount the money column can actually hold', function () {
    settingsAdmin();

    $max = (string) \App\Services\Import\Money::MAX_FILS;

    /*
     * THE COLUMN'S OWN MAXIMUM CARRIES 47 FILS, so the whole-dirham policy
     * refuses it — Lane FA. The largest fee this shop can be GIVEN is the
     * largest whole dirham that fits, one dirham below the column, and it is
     * refused for its decimals rather than for its size. The original point of
     * this test survives underneath: the ceiling is the column's own and not
     * an invented smaller number.
     */
    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => $max]])
        ->assertStatus(422);

    $maxWhole = (string) (intdiv(\App\Services\Import\Money::MAX_FILS, 100) * 100);

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => $maxWhole]])
        ->assertOk();

    expect(Setting::query()->where('key', 'cod_fee')->value('value'))->toBe($maxWhole)
        ->and($maxWhole)->toBe('2147483600');
});

// ---------------------------------------------------------------------------
// 5. merchant_ship_cost — the one money key that is NOT fils
// ---------------------------------------------------------------------------

/**
 * This key is major units, not fils: the SEO screen posts it raw and
 * App\Support\Seo compares it against price_aed and publishes it to Google as
 * the offer's shipping rate. So the rule here reads the value as DIRHAMS where
 * the fils keys above read the same digits as hundredths — which is exactly
 * why one blanket "money" rule would have been wrong, and is still the subject
 * of this test.
 *
 * The consequence of getting it wrong is not an internal total: `(float) 'abc'`
 * is 0.0, so the store publishes "we ship free" in structured data while still
 * charging for delivery at checkout.
 *
 * WHOLE DIRHAMS APPLY HERE TOO — Lane FA. A feed advertising "AED 25.50
 * delivery" beside a till that charges whole dirhams has published a rate the
 * shop does not honour, and the crawler compares the two. So "25.50" is now
 * refused and "25" is stored; the major-units reading is unchanged and is
 * still what this test pins.
 */
it('reads the merchant shipping cost as dirhams and refuses a non-number', function () {
    seedSetting('merchant_ship_cost', '19');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['merchant_ship_cost' => '25.50']])
        ->assertStatus(422);

    test()->putJson('/admin-api/settings', ['settings' => ['merchant_ship_cost' => '25']])
        ->assertOk();

    expect(Setting::query()->where('key', 'merchant_ship_cost')->value('value'))->toBe('25');

    test()->putJson('/admin-api/settings', ['settings' => ['merchant_ship_cost' => 'abc']])
        ->assertStatus(422);

    // Still the real rate, so the feed still says what the store really charges.
    $published = (float) storefrontSetting('merchant_ship_cost', '0');

    expect($published)->toBe(25.0);
});

// ---------------------------------------------------------------------------
// 6. Enums the consumer does not implement
// ---------------------------------------------------------------------------

it('refuses a currency position the formatter does not implement', function () {
    seedSetting('currency_position', 'after_space');
    seedSetting('currency_symbol', 'AED');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['currency_position' => 'diagonal']])
        ->assertStatus(422);

    Money::forgetConfig();
    Setting::flushMap();

    // The operator's real choice still renders; it did not silently fall back.
    expect(Money::position())->toBe('after_space');
});

it('still saves every valid currency key', function () {
    settingsAdmin();

    $posted = [
        'currency' => 'KWD',
        'currency_symbol' => 'د.ك',
        'currency_position' => 'after_space',
        'currency_decimals' => '3',
        'currency_symbol_render' => 'unicode',
    ];

    test()->putJson('/admin-api/settings', ['settings' => $posted])
        ->assertOk()
        ->assertJsonPath('saved', count($posted));

    foreach ($posted as $key => $value) {
        expect(Setting::query()->where('key', $key)->value('value'))->toBe($value);
    }
});

it('still saves the free-text SEO keys it has always accepted', function () {
    settingsAdmin();

    $posted = [
        'store_name' => 'K Beauty Bliss',
        'seo_home_title' => 'Korean skincare, delivered across the UAE',
        // No trailing newline: Laravel's TrimStrings middleware removes it
        // before the controller ever sees the value.
        'robots_txt' => "User-agent: *\nDisallow: /admin",
        'social_instagram' => 'https://instagram.com/kbeautybliss',
    ];

    test()->putJson('/admin-api/settings', ['settings' => $posted])
        ->assertOk()
        ->assertJsonPath('saved', count($posted));

    foreach ($posted as $key => $value) {
        expect(Setting::query()->where('key', $key)->value('value'))->toBe($value);
    }
});

/**
 * A rejected key must not take the rest of the payload down with it, and must
 * not half-write either. The screen posts the whole tab at once.
 */
it('writes nothing at all when one key in the payload is bad', function () {
    seedSetting('store_name', 'Original Name');
    seedSetting('cod_fee', '500');

    settingsAdmin();

    test()->putJson('/admin-api/settings', ['settings' => [
        'store_name' => 'New Name',
        'cod_fee' => 'abc',
    ]])->assertStatus(422);

    expect(Setting::query()->where('key', 'store_name')->value('value'))->toBe('Original Name')
        ->and(Setting::query()->where('key', 'cod_fee')->value('value'))->toBe('500');
});

// ---------------------------------------------------------------------------
// 7. The Customers screen's lifetime total
// ---------------------------------------------------------------------------

/**
 * Two screens, one customer, two different lifetime totals.
 *
 * AdminController::customers summed EVERY order status; the replacement
 * Customers screen sums Order::REAL_STATUSES. A refunded order is not spend —
 * that is what REAL_STATUSES exists to say, and the dashboard's revenue figure
 * and Catalog → Reorder both already read it. So the legacy endpoint was the
 * one that was wrong, and this pins the two together.
 */
it('counts only real orders towards a customer lifetime total, like every other screen', function () {
    settingsAdmin();

    $customer = Customer::create([
        'name' => 'Mona Said',
        'email' => 'mona-' . uniqid() . '@example.test',
    ]);

    $mk = function (string $status, int $totalFils) use ($customer) {
        Order::create([
            'order_number' => 'SWP-' . uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => $status,
            'currency' => 'AED',
            'subtotal' => $totalFils,
            'discount_total' => 0,
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 0,
            'total' => $totalFils,
            'payment_method' => 'cod',
        ]);
    };

    $mk('completed', 30000);   // AED 300 — real
    $mk('processing', 10000);  // AED 100 — real
    $mk('cancelled', 50000);   // AED 500 — not spend
    $mk('refunded', 70000);    // AED 700 — not spend

    $row = collect(test()->getJson('/admin-api/customers')->assertOk()->json('customers'))
        ->firstWhere('id', $customer->id);

    // AED 400, not AED 1,600.
    expect($row['spent_aed'])->toBe(400)
        // The order COUNT is deliberately still every order: the operator asked
        // how many times this person has ordered, not how many stuck.
        ->and($row['orders'])->toBe(4);
});

it('agrees with the Customers screen on the same customer', function () {
    settingsAdmin();

    $customer = Customer::create([
        'name' => 'Layla Noor',
        'email' => 'layla-' . uniqid() . '@example.test',
    ]);

    foreach ([['completed', 12500], ['cancelled', 99900]] as [$status, $total]) {
        Order::create([
            'order_number' => 'SWP-' . uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => $status,
            'currency' => 'AED',
            'subtotal' => $total,
            'discount_total' => 0,
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 0,
            'total' => $total,
            'payment_method' => 'cod',
        ]);
    }

    $legacy = collect(test()->getJson('/admin-api/customers')->assertOk()->json('customers'))
        ->firstWhere('id', $customer->id);

    $replacement = collect(test()->getJson('/admin-api/customers/list')->assertOk()->json('customers'))
        ->firstWhere('id', $customer->id);

    // Same fact, same number, whichever screen the operator happens to open.
    expect($legacy['spent_aed'] * 100)->toBe((int) $replacement['spend_fils']);
});
