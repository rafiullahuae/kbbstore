<?php

declare(strict_types=1);

/**
 * Lane DP — the data half of "one analytics identity per network".
 *
 * 2026_11_06_000000_clear_caches_analytics_loaded_once moves whatever the two
 * superseded SEO boxes held into the Marketing Pixels module and deletes the
 * rows. The migration has already run by the time a test starts, so it is
 * re-run here against rows planted on purpose: RefreshDatabase's transaction
 * rolls the planted rows back afterwards, which is what makes re-running a
 * migration safe in a test at all.
 *
 * The three cases that matter are the three an owner can actually be in:
 * only the old box filled, only the new one, or both with different ids.
 */

use App\Models\Setting;
use App\Services\Analytics;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** Run the merge migration's up() against the current tables. */
function dpRunMerge(): void
{
    $migration = require database_path('migrations/2026_11_06_000000_clear_caches_analytics_loaded_once.php');
    $migration->up();

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function dpModuleValue(string $key): ?string
{
    $value = DB::table('module_settings')
        ->where('module', 'marketing_pixels')
        ->where('key', $key)
        ->value('value');

    return $value === null ? null : (string) $value;
}

beforeEach(function () {
    DB::table('settings')->whereIn('key', ['ga', 'meta_pixel'])->delete();
    DB::table('module_settings')->where('module', 'marketing_pixels')->delete();
    DB::table('module_toggles')->where('module', 'marketing_pixels')->delete();

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
});

it('moves an id that only the old SEO box held, and keeps it firing', function () {
    Setting::updateOrCreate(['key' => 'ga'], ['value' => 'G-OLDBOX1234']);
    Setting::updateOrCreate(['key' => 'meta_pixel'], ['value' => '999988887777666']);
    Setting::flushMap();

    dpRunMerge();

    expect(dpModuleValue('ga4_id'))->toBe('G-OLDBOX1234');
    expect(dpModuleValue('meta_id'))->toBe('999988887777666');

    // The row is gone, so there is no second copy to disagree with this one.
    expect(Setting::query()->where('key', 'ga')->exists())->toBeFalse();
    expect(Setting::query()->where('key', 'meta_pixel')->exists())->toBeFalse();

    /*
     * And the module is ON. `settings.ga` was read by App\Support\Seo, which
     * the Marketing Pixels toggle does not gate — an owner who only ever used
     * the SEO screen had a live Google tag with that module off. Moving the id
     * under the module without switching the module on would have switched his
     * analytics off in the middle of a version bump.
     */
    expect(app(Analytics::class)->enabled())->toBeTrue();
    expect(app(Analytics::class)->validId('ga4'))->toBe('G-OLDBOX1234');
});

it('leaves the Marketing Pixels id alone when both boxes were filled differently', function () {
    Setting::updateOrCreate(['key' => 'ga'], ['value' => 'G-OLDBOX1234']);
    Setting::flushMap();

    app(SettingsService::class)->setModuleSetting('marketing_pixels', 'ga4_id', 'G-MODULE5678');
    app(SettingsService::class)->setModule('marketing_pixels', true);

    dpRunMerge();

    // The module id is the one the events already hang off, so it wins.
    expect(dpModuleValue('ga4_id'))->toBe('G-MODULE5678');
    expect(Setting::query()->where('key', 'ga')->exists())->toBeFalse();
    expect(app(Analytics::class)->id('ga4'))->toBe('G-MODULE5678');
});

it('does not switch the module on for a shop that had no analytics at all', function () {
    Setting::updateOrCreate(['key' => 'ga'], ['value' => '']);
    Setting::flushMap();

    dpRunMerge();

    expect(app(Analytics::class)->enabled())
        ->toBeFalse('an empty box is not a reason to start loading third-party scripts');
    expect(Setting::query()->where('key', 'ga')->exists())
        ->toBeFalse('a blank duplicate box is still a duplicate box');
});

it('is safe to run twice', function () {
    Setting::updateOrCreate(['key' => 'ga'], ['value' => 'G-TWICE12345']);
    Setting::flushMap();

    dpRunMerge();
    dpRunMerge();

    expect(dpModuleValue('ga4_id'))->toBe('G-TWICE12345');
});


/** An owner who may use the admin settings endpoints. */
function dpAdmin(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => 'DP Owner',
        'email' => 'dp-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/* ----------------------------------------------------- the admin round trip */

it('writes the SEO screen\'s Google box through to the one canonical id', function () {
    $admin = dpAdmin();

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => ['ga' => 'G-VIASCREEN1']])
        ->assertOk();

    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(dpModuleValue('ga4_id'))->toBe('G-VIASCREEN1');
    expect(Setting::query()->where('key', 'ga')->exists())
        ->toBeFalse('the SEO screen must not create a second row to disagree with the module');

    // And the screen reads back the value it just saved, from the module.
    $read = $this->actingAs($admin, 'admin')->getJson('/admin-api/settings')->json('settings');

    expect($read['ga'])->toBe('G-VIASCREEN1');
});

/**
 * THE SEO SCREEN'S META BOX NOW FIRES, which it never used to.
 *
 * `meta_pixel` was written by Store → SEO & Meta and read by nothing: the
 * pixel that fired was App\Services\MarketingPixels' `meta_id`, a different
 * box on a different screen. An owner could fill in the SEO one, see "Saved",
 * and have no pixel at all. Both boxes are now the same value.
 *
 * THE ADMIN SHELL STILL SAYS OTHERWISE. resources/views/admin/app.blade.php
 * is Lane DN's this round, so its copy for that field — "Stored, but no
 * storefront page fires it." — has not been changed here and is now false.
 * The exact replacement is in this lane's report;
 * AdminConsoleTellsTheTruthTest pins the old sentence and its assertion flips
 * with that edit. This test is the behaviour, which does not depend on either.
 */
it('fires the pixel an owner saved in the SEO screen\'s Meta box', function () {
    $admin = dpAdmin();

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => ['meta_pixel' => '123456789012345']])
        ->assertOk();

    Setting::flushMap();
    SettingsService::forgetMemo();

    \App\Models\Product::create([
        'slug' => 'dp-meta-box', 'name' => 'DP Meta Box', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(preg_match_all("#fbq\(\s*'init'\s*,\s*\"123456789012345\"#", $html))
        ->toBe(1, 'the SEO screen\'s Meta box must reach the storefront exactly once');
});

it('shows the Marketing Pixels id in the SEO screen\'s box, and the reverse', function () {
    $admin = dpAdmin();

    app(\App\Services\MarketingPixels::class)->save(['ga4_id' => 'G-FROMPIXELS']);

    $read = $this->actingAs($admin, 'admin')->getJson('/admin-api/settings')->json('settings');

    expect($read['ga'])->toBe('G-FROMPIXELS', 'two boxes, one id — neither may show something the other does not');
});
