<?php

declare(strict_types=1);

use App\Services\OwnerApp\VapidKeys;
use App\Services\Push\PushGeo;
use App\Services\Push\PushStats;
use App\Services\SiteAppPush;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushAdminRoutes;

/*
 * Lane PN: where a phone is, from its IP alone (the optional DB-IP tier), and
 * the Subscribers & analytics tab.
 *
 * The owner: "the area/city is imp to catch after installation via ip or
 * google geo location ... no disturbance to the customer". The real DB-IP
 * download is not reachable from the sandbox this was built in (the egress
 * proxy refuses download.db-ip.com), so the importer is proved on a fixture in
 * DB-IP's own CSV shape: tests/Fixtures/dbip-city-lite-sample.csv.
 */

require_once __DIR__.'/../Support/PushTestHelpers.php';

beforeEach(function () {
    PushAdminRoutes::wire($this->app);
    VapidKeys::forget();
    VapidKeys::pair();
});

it('imports only the UAE rows of a DB-IP file, plain or gzipped, and finds an IPv4 or IPv6 address in one lookup', function () {
    /* DEFECT: a lookup that answers for an address outside every range (the
       nearest lower range is not a match). MUTATION: drop the ip_to check in
       PushGeo::locate() -> 46.252.0.10 (Riyadh, not imported) and 2a02:6041::1
       read as Umm al-Quwain. */
    $csv = base_path('tests/Fixtures/dbip-city-lite-sample.csv');
    expect(PushGeo::import($csv))->toBe(6);

    $gz = storage_path('framework/testing/pn-dbip-'.getmypid().'.csv.gz');
    @mkdir(dirname($gz), 0777, true);
    file_put_contents($gz, gzencode((string) file_get_contents($csv)));
    expect(PushGeo::import($gz))->toBe(6)
        ->and(DB::table('push_geo_ranges')->count())->toBe(6);       // replaced, not appended
    @unlink($gz);

    expect(PushGeo::locate('2.48.10.20'))->toBe(['country' => 'AE', 'region' => 'Dubai', 'city' => 'Dubai', 'location_source' => 'ip-db'])
        ->and(PushGeo::locate('5.30.200.1')['region'])->toBe('Ras Al Khaimah')
        ->and(PushGeo::locate('37.245.1.1'))->toMatchArray(['region' => 'Abu Dhabi', 'city' => 'Al Ain'])
        ->and(PushGeo::locate('2001:8f8:1:2::9')['region'])->toBe('Dubai')
        ->and(PushGeo::locate('2a02:6040::1')['region'])->toBe('Umm Al Quwain')
        ->and(PushGeo::locate('2a02:6041::1'))->toBeNull()
        ->and(PushGeo::locate('46.252.0.10'))->toBeNull()
        ->and(PushGeo::locate('1.0.0.1'))->toBeNull()
        ->and(PushGeo::locate('10.0.0.1'))->toBeNull()              // private: never looked up
        ->and(PushGeo::locate('not an ip'))->toBeNull();

    // A file with no UAE rows leaves the table as it was.
    $empty = storage_path('framework/testing/pn-dbip-empty-'.getmypid().'.csv');
    file_put_contents($empty, "1.0.0.0,1.0.0.255,OC,AU,Queensland,Brisbane,0,0\n");
    expect(fn () => PushGeo::import($empty))->toThrow(RuntimeException::class);
    expect(DB::table('push_geo_ranges')->count())->toBe(6);
    @unlink($empty);
});

it('locates a new subscriber by IP only when nothing better is known, marked approximate', function () {
    /* MUTATION: call PushGeo::locate() before the header tier in
       SiteAppPush::locate() -> the Cloudflare city is replaced by the guess. */
    PushGeo::import(base_path('tests/Fixtures/dbip-city-lite-sample.csv'));
    $sub = static function (): array {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/pn-geo-'.bin2hex(random_bytes(4)),
            'keys' => ['p256dh' => \App\Services\OwnerApp\WebPush::b64u(\App\Services\OwnerApp\WebPush::publicPoint($k)), 'auth' => \App\Services\OwnerApp\WebPush::b64u(random_bytes(16))]];
    };

    $this->withServerVariables(['REMOTE_ADDR' => '5.30.1.1'])->postJson('/api/site-app/push', $sub())->assertOk();
    $row = DB::table('site_app_push_subscriptions')->orderByDesc('id')->first();
    expect([$row->country, $row->region, $row->location_source])->toBe(['AE', 'Sharjah', 'ip-db']);

    $this->withServerVariables(['REMOTE_ADDR' => '5.30.1.1'])->withHeaders(['CF-IPCountry' => 'AE', 'CF-Region' => 'Dubai', 'CF-IPCity' => 'Dubai'])
        ->postJson('/api/site-app/push', $sub())->assertOk();
    $row = DB::table('site_app_push_subscriptions')->orderByDesc('id')->first();
    expect([$row->region, $row->location_source])->toBe(['Dubai', 'ip-header']);

    // Switched off: nothing guessed.
    pnRules(['geo_ip' => false]);
    $this->flushHeaders();
    $this->withServerVariables(['REMOTE_ADDR' => '5.30.1.1'])->postJson('/api/site-app/push', $sub())->assertOk();
    $row = DB::table('site_app_push_subscriptions')->orderByDesc('id')->first();
    expect($row->location_source)->toBeNull();
});

it('imports from the command, from a file on disk', function () {
    $this->artisan('kbb:push-geo-import', ['--file' => base_path('tests/Fixtures/dbip-city-lite-sample.csv')])
        ->expectsOutput('Imported 6 UAE ranges.')->assertExitCode(0);
    expect(PushGeo::status())->toMatchArray(['ranges' => 6, 'month' => 'file']);
    // --auto with a fresh table does nothing (no network).
    $this->artisan('kbb:push-geo-import', ['--auto' => true])->assertExitCode(0);
});

it('draws the analytics in the same number of queries for 3 phones and for 40', function () {
    /* DEFECT: a screen that loops over subscribers and slows as the list
       grows. MUTATION: compute the emirate per subscription row in PHP with a
       query each -> the 40-phone count is larger. */
    $seed = function (int $n) {
        $places = [['Dubai', 'Dubai', 'en', 'ios'], ['Sharjah', 'Al Nahda', 'ar', 'android'], ['Abu Dhabi', 'Al Ain', 'en', 'desktop'], [null, null, 'en', 'other']];
        for ($i = 0; $i < $n; $i++) {
            [$r, $c, $l, $p] = $places[$i % 4];
            pnPhone(['region' => $r, 'city' => $c, 'locale' => $l, 'platform' => $p, 'location_source' => $r ? 'order' : null, 'customer_id' => $i % 3 === 0 ? 1000 + $i : null]);
        }
    };
    $owner = pnAdmin();

    $seed(3);
    $this->actingAs($owner, 'admin')->getJson('/admin-api/push/analytics')->assertOk();   // warm
    DB::enableQueryLog();
    DB::flushQueryLog();
    $small = $this->actingAs($owner, 'admin')->getJson('/admin-api/push/analytics')->assertOk()->json();
    $q3 = count(DB::getQueryLog());

    $seed(37);
    DB::flushQueryLog();
    $big = $this->actingAs($owner, 'admin')->getJson('/admin-api/push/analytics')->assertOk()->json();
    $q40 = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($q40)->toBe($q3)
        ->and($small['active'])->toBe(3)->and($big['active'])->toBe(40)
        ->and(collect($big['emirates'])->firstWhere('key', 'dubai')['n'])->toBe(11)
        ->and(collect($big['emirates'])->firstWhere('key', 'abu_dhabi')['n'])->toBe(10)
        ->and(collect($big['emirates'])->firstWhere('key', 'unknown')['n'])->toBe(9)
        ->and(collect($big['languages'])->firstWhere('k', 'ar')['n'])->toBe(10)
        ->and($big['customers'] + $big['guests'])->toBe(40)
        ->and(collect($big['growth'])->last()['new'])->toBe(40)
        ->and($big['attribution'])->toBe('IP Geolocation by DB-IP');
});

it('counts opt-outs by day, though the subscription row itself is deleted', function () {
    $sub = pnPhone(['endpoint' => 'https://fcm.googleapis.com/fcm/send/pn-optout']);
    SiteAppPush::forget('https://fcm.googleapis.com/fcm/send/pn-optout');
    SiteAppPush::forget('https://fcm.googleapis.com/fcm/send/never-was');
    expect(DB::table('site_app_push_subscriptions')->where('id', $sub)->exists())->toBeFalse()
        ->and(app(PushStats::class)->summary()['optouts'])->toBe(1);
});
