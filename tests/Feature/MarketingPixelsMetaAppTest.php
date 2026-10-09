<?php

declare(strict_types=1);

/*
 * Marketing Pixels' own Meta app (Lane MP). The Instagram module is retired
 * (Lane IGR) and its credentials deleted, so "Connect with Facebook" must stand
 * on its own settings, copied across once by
 * 2027_10_15_130200_copy_meta_app_to_marketing_pixels.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\Analytics;
use App\Services\Pixels\PixelConfig;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelConnectRoutes;

const MAPP_SECRET = '0123456789abcdef0123456789abcdef';

beforeEach(function () {
    PixelConnectRoutes::wire(app());
    mappFlush();
});

function mappFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
}

function mappMigrate(): void
{
    ob_start();
    (require database_path('migrations/2027_10_15_130200_copy_meta_app_to_marketing_pixels.php'))->up();
    $said = (string) ob_get_clean();
    // Never a value in its output, only what happened.
    expect($said)->not->toContain(MAPP_SECRET)->not->toContain('1234567890123');
    mappFlush();
}

function mappInstagramApp(): void
{
    Setting::updateOrCreate(['key' => 'instagram_fb_app_id'], ['value' => '1234567890123']);
    Setting::updateOrCreate(['key' => 'instagram_fb_app_secret'], ['value' => Crypt::encryptString(MAPP_SECRET)]);
}

it('copies the Instagram module\'s Meta app into Marketing Pixels, secret re-encrypted', function () {
    mappInstagramApp();
    mappMigrate();

    $stored = (string) DB::table('module_settings')->where('module', 'marketing_pixels')->where('key', 'meta_app_secret')->value('value');
    expect(app(PixelConfig::class)->get('meta_app_id'))->toBe('1234567890123')
        ->and($stored)->not->toContain(MAPP_SECRET)
        ->and(app(PixelConfig::class)->secret('meta_app_secret'))->toBe(MAPP_SECRET)
        ->and(app(PixelConfig::class)->masked('meta_app_secret'))->toBe('••••cdef');
});

it('never overwrites an app Marketing Pixels already has, and never copies half a pair', function () {
    // MUTATION: drop the "already has its own" return and the id becomes 1234567890123.
    mappInstagramApp();
    app(PixelConfig::class)->save(['meta_app_id' => '99999999']);
    mappFlush();
    mappMigrate();
    expect(app(PixelConfig::class)->get('meta_app_id'))->toBe('99999999')
        ->and(app(PixelConfig::class)->secret('meta_app_secret'))->toBeNull();

    // An id with no usable secret is not copied either.
    DB::table('module_settings')->where('module', 'marketing_pixels')->delete();
    Setting::updateOrCreate(['key' => 'instagram_fb_app_secret'], ['value' => 'not-ciphertext']);
    mappFlush();
    mappMigrate();
    expect(app(PixelConfig::class)->get('meta_app_id'))->toBe('');
});

it('connects with Facebook using only its own keys', function () {
    // No Instagram rows at all: the old fallback cannot be what makes this work.
    expect(Setting::query()->where('key', 'like', 'instagram%')->count())->toBe(0);
    $owner = AdminUser::create(['name' => 'MAPP', 'email' => 'mapp-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/meta/start')->assertStatus(422);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => ['meta_app_id' => '12ab34']])
        ->assertStatus(422)->assertJsonFragment(['fields' => ['meta_app_id']]);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/connect', ['values' => ['meta_app_id' => '555666777888', 'meta_app_secret' => MAPP_SECRET]])->assertOk();
    mappFlush();

    $start = test()->actingAs($owner, 'admin')->postJson('/admin-api/marketing-pixels/meta/start')->assertOk()->json('url');
    expect($start)->toStartWith('https://www.facebook.com/')->toContain('client_id=555666777888')->not->toContain(MAPP_SECRET);
    parse_str((string) parse_url($start, PHP_URL_QUERY), $q);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'USERTOKEN1234567890', 'token_type' => 'bearer']),
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['name' => 'Shop ads', 'adspixels' => ['data' => [['id' => '777788889999000', 'name' => 'Shop pixel']]]]]]),
    ]);

    test()->actingAs($owner, 'admin')->get('/admin-api/marketing-pixels/meta/callback?state='.$q['state'].'&code=abc123')
        ->assertRedirect()->assertRedirectContains('mp_meta=connected');
    mappFlush();

    expect(app(Analytics::class)->id('meta'))->toBe('777788889999000');
    $exchange = collect(Http::recorded())->first()[0];
    expect($exchange['client_id'])->toBe('555666777888')->and($exchange['client_secret'])->toBe(MAPP_SECRET);
});

it('references no Instagram class anywhere in Marketing Pixels', function () {
    // MUTATION: put `use App\Services\Instagram\InstagramCredentials;` back in MetaConnect.
    $files = array_merge(
        glob(app_path('Services/Pixels/*.php')),
        [app_path('Http/Controllers/Admin/PixelConnectApiController.php'), app_path('Http/Controllers/Admin/CustomCodeApiController.php'),
            app_path('Http/Controllers/Store/CatalogFeedController.php'), app_path('Providers/MarketingPixelsServiceProvider.php'),
            base_path('routes/marketing-pixels-connect-admin.php'), base_path('routes/marketing-catalog-feeds.php'),
            resource_path('views/admin/partials/marketing-pixels-connect.blade.php'),
            database_path('migrations/2027_10_15_130200_copy_meta_app_to_marketing_pixels.php')],
    );

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        expect(preg_match('/Services\\\\Instagram|Instagram(Credentials|Auth|Client|Sync)|FacebookConnect|FacebookGraphClient/', $src))
            ->toBe(0, basename($file).' still reaches into the retired Instagram module');
    }
});
