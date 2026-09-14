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

    $allowed = (function () {
        $src = file_get_contents(app_path('Http/Controllers/Admin/AdminController.php'));
        $start = strpos($src, '$allowed = [');
        preg_match_all("/'([a-z0-9_]+)'/", substr($src, $start, strpos($src, '];', $start) - $start), $m);

        return $m[1];
    })();

    foreach ($posted as $key) {
        expect($allowed)->toContain($key);
    }
});
