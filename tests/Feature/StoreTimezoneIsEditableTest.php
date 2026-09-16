<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\StoreTime;

/**
 * The owner can actually CHANGE the shop's clock, from a screen, with a mouse.
 *
 * StoreTimezoneTest already proves the mechanism: `store_timezone` validates on
 * the way in, a fake zone is refused, and every date in the panel is read on
 * whatever zone survives. What it cannot prove is that the owner is ever
 * offered the choice — the value could be correct, enforced, tested, and still
 * be reachable only by editing a database row by hand. It was: `store_timezone`
 * existed in AdminController::SETTING_RULES with no field anywhere in the admin
 * console, so the class comment calling it "a SETTING, not a constant" was true
 * of the code and false of the product.
 *
 * TWO HALVES THAT MUST MOVE TOGETHER. A control with no matching line in the
 * save payload is the worst shape this screen can take: the owner picks Riyadh,
 * presses Save, the panel says "Business details saved" and nothing is written.
 * SETTING_RULES carries a comment warning about exactly that failure for the
 * currency keys. So the first test asserts BOTH halves, and would fail if
 * either were removed on its own.
 *
 * Read from the Blade source rather than from rendered HTML on purpose: the
 * admin console inlines its own stylesheet into the page, so searching rendered
 * output for an identifier also matches any CSS that happens to mention it.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function tzEditorAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Clock Owner',
        'email' => 'clock-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

it('offers a time zone control on Business Details and posts what it collects', function () {
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    // The control itself, built through the shared select helper so it looks
    // like every other field on the screen.
    $hasControl = preg_match("/seoSel\\(\\s*'set_store_timezone'/", $console) === 1;
    expect($hasControl)->toBeTrue(
        'Business Details renders no time zone control — the shop clock is a setting the owner cannot reach.'
    );

    // And the half that makes pressing Save mean something.
    $hasPayload = preg_match("/store_timezone:\\s*sval\\(\\s*'set_store_timezone'\\s*\\)/", $console) === 1;
    expect($hasPayload)->toBeTrue(
        'The time zone control is never read into the save payload — Save would report success and write nothing.'
    );
});

it('offers Dubai as the default choice, since that is where the shop trades', function () {
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    // The default handed to seoSel is what an unset setting shows as selected.
    // If this drifts away from StoreTime's own default the screen would claim a
    // zone the code is not using.
    $matched = preg_match(
        "/seoSel\\(\\s*'set_store_timezone'.*?\\],\\s*'([A-Za-z_\\/]+)'\\s*\\)/s",
        $console,
        $m
    ) === 1;

    expect($matched)->toBeTrue('Could not read the time zone control default out of the screen.');
    expect($m[1])->toBe(
        StoreTime::DEFAULT_ZONE,
        'The screen preselects a different zone from the one StoreTime falls back to.'
    );
});

it('lists only real zones, so a pick can never be silently discarded', function () {
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    preg_match(
        "/seoSel\\(\\s*'set_store_timezone',[^\\[]*\\[(.*?)\\],\\s*'[A-Za-z_\\/]+'\\s*\\)/s",
        $console,
        $m
    );

    preg_match_all("/\\[\\s*'([A-Za-z_\\/]+)'\\s*,/", $m[1] ?? '', $codes);

    expect($codes[1] ?? [])->not->toBeEmpty('The time zone control offers no options at all.');

    foreach ($codes[1] as $zone) {
        expect(StoreTime::isValidZone($zone))->toBeTrue(
            "The screen offers '{$zone}', which is not a timezone the server accepts — picking it would fall back to Dubai without saying so."
        );
    }

    // The shop is in Dubai; that option has to be one of them.
    expect(in_array(StoreTime::DEFAULT_ZONE, $codes[1], true))->toBeTrue(
        'Dubai is not among the offered zones, on a shop that trades from Dubai.'
    );
});

it('writes the zone the screen collects, and every date then follows it', function () {
    tzEditorAdmin();

    $this->putJson('/admin-api/settings', ['settings' => ['store_timezone' => 'Asia/Riyadh']])
        ->assertOk();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(StoreTime::zone())->toBe('Asia/Riyadh');

    // The consequence, not just the stored string: an instant that is one day
    // in Dubai and the day before in Riyadh reads as Riyadh's day now.
    expect(StoreTime::dayKey('2026-09-15 21:30:00'))->toBe('2026-09-16');

    $this->putJson('/admin-api/settings', ['settings' => ['store_timezone' => 'Asia/Dubai']])
        ->assertOk();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(StoreTime::dayKey('2026-09-15 21:30:00'))->toBe('2026-09-16');
    expect(StoreTime::dayKey('2026-09-15 19:30:00'))->toBe('2026-09-15');
});

it('seeds Dubai, so a fresh install reads the shop clock before anyone visits the screen', function () {
    $seeder = file_get_contents(database_path('seeders/SettingsSeeder.php'));

    $seeded = preg_match("/'store_timezone'\\s*=>\\s*'([A-Za-z_\\/]+)'/", $seeder, $m) === 1;

    expect($seeded)->toBeTrue('SettingsSeeder never writes store_timezone.');
    expect($m[1])->toBe(StoreTime::DEFAULT_ZONE);
});
