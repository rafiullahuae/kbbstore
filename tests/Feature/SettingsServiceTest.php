<?php

/**
 * Pins the two settings bugs that made storefront pages expensive and made the
 * SEO screen lie about saving.
 */

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();
});

it('queries a non-autoloaded key once, not once per call', function () {
    Setting::create(['key' => 'rarely_read', 'value' => 'x', 'autoload' => false]);
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $svc = app(SettingsService::class);
    $svc->get('rarely_read');                       // warms all() + the memo

    DB::enableQueryLog();
    for ($i = 0; $i < 20; $i++) {
        $svc->get('rarely_read');
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('remembers a miss, so an absent key does not cost a query per call', function () {
    $svc = app(SettingsService::class);
    $svc->get('never_set_anywhere');

    DB::enableQueryLog();
    for ($i = 0; $i < 20; $i++) {
        expect($svc->get('never_set_anywhere', 'fallback'))->toBe('fallback');
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('makes a write visible to the next read in the same process', function () {
    $svc = app(SettingsService::class);
    expect($svc->get('late_key', 'fallback'))->toBe('fallback');   // memoises the miss

    $svc->set('late_key', 'now-present', autoload: false);

    expect($svc->get('late_key', 'fallback'))->toBe('now-present');
});

it('accepts every key the SEO screen posts', function () {
    $posted = [
        'social_facebook', 'social_instagram', 'social_tiktok', 'social_pinterest',
        'social_linkedin', 'social_youtube', 'pinterest_site_verification',
        'baidu_site_verification', 'indexnow_on', 'llms_enabled', 'crawl_clean',
        'enable_merchant', 'merchant_condition', 'merchant_ship_country',
        'merchant_ship_cost', 'merchant_ship_free_over', 'merchant_return_days',
    ];

    // Read as DATA rather than scraped out of the controller source. The regex
    // version of this looked for `$allowed = [` and pulled every quoted string
    // after it, so it broke when the allowlist became a constant — and it would
    // equally have passed on a key that appeared in a comment.
    $allowed = array_keys(\App\Http\Controllers\Admin\AdminController::SETTING_RULES);

    foreach ($posted as $key) {
        expect($allowed)->toContain($key);
    }
});
