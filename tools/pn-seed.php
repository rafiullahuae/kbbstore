<?php
/*
 * Seed the Lane PN preview (Growth & Marketing -> Push Notifications): an
 * owner account, 64 subscribed phones across the emirates, cities, languages
 * and platforms (places learnt from orders, headers and the IP table), a sent
 * campaign with deliveries and taps, a scheduled one and a draft, 30 days of
 * growth, and the DB-IP fixture as the IP table.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\OwnerApp\VapidKeys;
use App\Services\OwnerApp\WebPush;
use App\Services\Push\PushGeo;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
VapidKeys::pair();

$brand = Brand::query()->firstOrCreate(['slug' => 'cosrx'], ['name' => 'Cosrx']);
foreach (['Advanced Snail 96 Mucin Power Essence', 'Low pH Good Morning Gel Cleanser', 'Snail Mucin Sunscreen SPF50'] as $i => $n) {
    Product::query()->updateOrCreate(['slug' => 'cosrx-'.($i + 1)], ['name' => 'COSRX '.$n, 'brand_id' => $brand->id, 'price' => 6900 + $i * 1000,
        'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock']);
}

PushGeo::import(base_path('tests/Fixtures/dbip-city-lite-sample.csv'));
PushGeo::meta(['rows' => 6, 'month' => '2026-10', 'at' => now()->toIso8601String(), 'error' => null]);

$places = [
    ['Dubai', 'Dubai', 'order'], ['Dubai', 'Jumeirah', 'order'], ['Dubai', 'Al Barsha', 'ip-header'], ['Dubai', 'Dubai', 'ip-db'],
    ['Sharjah', 'Sharjah', 'order'], ['Sharjah', 'Al Nahda', 'order'], ['Sharjah', 'Sharjah', 'ip-db'],
    ['Abu Dhabi', 'Abu Dhabi', 'order'], ['Abu Dhabi', 'Al Ain', 'order'], ['Ajman', 'Ajman', 'order'],
    ['Ras Al Khaimah', 'Ras Al Khaimah', 'ip-db'], ['Fujairah', 'Fujairah', 'order'], [null, null, null],
];
$platforms = ['ios', 'android', 'android', 'ios', 'desktop'];
DB::table('site_app_push_subscriptions')->delete();
$ids = [];
for ($i = 0; $i < 64; $i++) {
    [$region, $city, $src] = $places[$i % count($places)];
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/preview-'.$i;
    $at = now()->subDays(29 - intdiv($i, 3))->subHours($i % 7);
    $ids[] = DB::table('site_app_push_subscriptions')->insertGetId([
        'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'p256dh' => WebPush::b64u(WebPush::publicPoint($key)),
        'auth' => WebPush::b64u(random_bytes(16)), 'cookie_hash' => hash('sha256', 'preview-'.$i), 'customer_id' => $i % 3 === 0 ? 5000 + $i : null,
        'locale' => $i % 4 === 1 ? 'ar' : 'en', 'country' => $region ? 'AE' : null, 'region' => $region, 'city' => $city, 'location_source' => $src,
        'platform' => $platforms[$i % 5], 'status' => $i % 21 === 20 ? 'gone' : 'active', 'fail_count' => 0, 'last_seen_at' => now(),
        'created_at' => $at, 'updated_at' => $at,
    ]);
}
foreach ([[now()->subDays(3)->format('Y-m-d'), 'optout', 2], [now()->subDays(9)->format('Y-m-d'), 'optout', 1], [now()->subDays(5)->format('Y-m-d'), 'gone', 3]] as [$d, $k, $n]) {
    DB::table('push_daily')->insertOrIgnore(['day' => $d, 'kind' => $k, 'n' => $n]);
}

DB::table('push_sends')->delete();
DB::table('push_campaigns')->delete();
$mk = function (array $c) {
    return (int) DB::table('push_campaigns')->insertGetId($c + ['created_at' => now(), 'updated_at' => now()]);
};
$sent = $mk(['title' => 'Weekend glow sale: 20% off', 'body' => 'Serums and essences, this weekend only.', 'url' => '/sale/', 'link_label' => null,
    'audience' => json_encode(['emirates' => ['dubai', 'sharjah']]), 'status' => 'sent', 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);
$em = ['Dubai' => 'dubai', 'Sharjah' => 'sharjah'];
$rows = DB::table('site_app_push_subscriptions')->whereIn('region', array_keys($em))->get(['id', 'region']);
foreach ($rows as $n => $s) {
    DB::table('push_sends')->insert(['campaign_id' => $sent, 'kind' => 'campaign', 'ref' => $sent, 'subscription_id' => $s->id, 'emirate' => $em[$s->region],
        'title' => 'Weekend glow sale: 20% off', 'body' => 'x', 'url' => '/sale/', 'status' => $n % 13 === 12 ? 'gone' : ($n % 9 === 8 ? 'held' : 'delivered'),
        'dedupe' => 'c:'.$sent.':'.$s->id, 'due_at' => now()->subDays(2), 'sent_at' => now()->subDays(2),
        'clicked_at' => $n % 4 === 0 ? now()->subDays(2) : null, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
}
app(PushSender::class)->recount($sent);
$mk(['title' => 'New in: Beauty of Joseon', 'body' => 'The sunscreen everyone asks for is back.', 'url' => '/brand/cosrx/',
    'audience' => json_encode(['locales' => ['ar']]), 'status' => 'scheduled', 'scheduled_at' => now()->addDay()->setTime(14, 0)]);
$mk(['title' => 'Sharjah: free delivery today', 'body' => 'Order before 6pm.', 'url' => '/',
    'audience' => json_encode(['emirates' => ['sharjah']]), 'status' => 'draft']);
echo "Seeded 64 phones and 3 campaigns.\n";
