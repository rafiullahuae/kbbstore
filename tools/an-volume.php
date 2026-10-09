<?php
/*
 * Lane AN volume: `seed` puts 100,000 raw hits over the last 48 hours (with a
 * live last half hour), 90 days of summaries and 150 orders with sources into
 * a PREVIEW database; `clear` removes every row it made. Never run on a shop.
 *
 *   php artisan tinker --execute="\$argv=['x','seed']; require 'tools/an-volume.php';"
 */
use Illuminate\Support\Facades\DB;

$mode = $GLOBALS['anMode'] ?? 'seed';
if (app()->environment('production')) {
    throw new RuntimeException('Preview data only.');
}

if ($mode === 'clear') {
    DB::table('an_hits')->delete();
    DB::table('an_days')->delete();
    DB::table('an_dims')->delete();
    DB::table('orders')->where('order_number', 'like', 'ANV-%')->delete();
    echo "cleared\n";

    return;
}

// `live`: a fresh half hour on top of what is there, for the screenshots.
$liveOnly = $mode === 'live';
mt_srand($liveOnly ? time() : 7);
$now = intdiv(time(), 60);
$paths = [['/', 'K-Beauty Bliss — Korean skincare in the UAE'], ['/shop/', 'Shop all'], ['/collections/toners/', 'Toners'], ['/brands/anua/', 'Anua'], ['/checkout/', 'Checkout'], ['/blog/', 'Journal']];
foreach (DB::table('products')->where('slug', 'like', 'an-%')->get(['slug', 'name']) as $p) {
    $paths[] = ['/product/'.$p->slug.'/', $p->name];
}
for ($i = 0; $i < 260; $i++) {
    $paths[] = ['/product/long-tail-'.$i.'/', 'Long tail product '.$i];
}
$ch = [['instagram_ads', 'instagram', 'paid', 'eid_glow', '', 18], ['instagram', 'ig', 'social', '', 'l.instagram.com', 14], ['google', '', '', '', 'google.com', 16],
    ['google_ads', 'google', 'cpc', 'brand_search', '', 9], ['tiktok_ads', 'tiktok', 'paid', 'snail_launch', '', 8], ['tiktok', '', '', '', 'tiktok.com', 4],
    ['whatsapp', '', '', '', 'wa.me', 7], ['facebook', '', '', '', 'm.facebook.com', 3], ['snapchat', '', '', '', 'snapchat.com', 2], ['direct', '', '', '', '', 15],
    ['referral', '', '', '', 'beautyblog.example', 2], ['email', 'newsletter', 'email', 'october', '', 2]];
$pick = function (array $list) { $t = array_sum(array_column($list, 5)); $r = mt_rand(1, $t); foreach ($list as $c) { $r -= $c[5]; if ($r <= 0) { return $c; } } return $list[0]; };
$cc = ['AE', 'AE', 'AE', 'AE', 'AE', 'AE', 'SA', 'SA', 'QA', 'OM', 'KW', 'BH', 'IN', 'GB'];
$devs = [['mobile', 'Safari', 'iOS'], ['mobile', 'Chrome', 'Android'], ['mobile', 'Instagram', 'iOS'], ['mobile', 'Samsung', 'Android'], ['desktop', 'Chrome', 'Windows'], ['desktop', 'Safari', 'macOS'], ['tablet', 'Safari', 'iOS']];

$rows = [];
$n = 0;
$flush = function () use (&$rows) { if ($rows !== []) { DB::table('an_hits')->insert($rows); $rows = []; } };
$target = $liveOnly ? 1500 : 100000;
while ($n < $target) {
    // Sessions: busier recently, and a live last half hour.
    $age = $liveOnly ? (int) floor(sqrt(mt_rand(0, 900))) : (mt_rand(1, 100) <= 8 ? mt_rand(0, 29) : mt_rand(0, 2880));
    $v = substr(md5('v'.mt_rand(1, 30000)), 0, 16);
    $s = substr(md5($v.$age), 0, 16);
    $c = $pick($ch);
    $d = $devs[mt_rand(0, 6)];
    $country = $cc[mt_rand(0, count($cc) - 1)];
    $lang = mt_rand(1, 100) <= 30 ? 'ar' : 'en';
    $pages = mt_rand(1, 100) <= 42 ? 1 : mt_rand(2, 7);
    for ($j = 0; $j < $pages && $n < $target; $j++, $n++) {
        $p = $j === 0 ? $paths[mt_rand(0, 15)] : $paths[mt_rand(0, count($paths) - 1)];
        $isCart = $j > 0 && mt_rand(1, 100) <= 9;
        $rows[] = ['m' => $now - max(0, $age - $j), 'v' => $v, 's' => $s, 'k' => $isCart ? 1 : ($p[0] === '/checkout/' ? 2 : 0), 'e' => $j === 0 ? 1 : 0,
            'path' => $isCart ? '' : $p[0], 'title' => $isCart ? '' : $p[1], 'ref' => $j === 0 ? $c[4] : '', 'ch' => $j === 0 ? $c[0] : 'direct',
            'src' => $j === 0 ? $c[1] : '', 'med' => $j === 0 ? $c[2] : '', 'cmp' => $j === 0 ? $c[3] : '',
            'dev' => $d[0], 'br' => $d[1], 'os' => $d[2], 'cc' => $country, 'lang' => $lang];
        if (count($rows) >= 1000) { $flush(); }
    }
}
$flush();
if ($liveOnly) {
    \App\Services\Analytics\Rollup::rollDay(\App\Support\StoreTime::now()->format('Y-m-d'));
    echo "live burst added\n";

    return;
}

// 90 days of summaries, synthetic, at the cap a real day would reach.
$tz = \App\Support\StoreTime::now();
for ($i = 2; $i <= 90; $i++) {
    $day = $tz->subDays($i)->format('Y-m-d');
    $vis = 1500 + mt_rand(0, 900);
    DB::table('an_days')->insert(['day' => $day, 'views' => $vis * 3, 'visitors' => $vis, 'sessions' => (int) ($vis * 1.2), 'bounces' => (int) ($vis * 0.5), 'carts' => (int) ($vis * 0.12), 'checkouts' => (int) ($vis * 0.05), 'rolled_at' => now()]);
    $dims = [];
    foreach (array_slice($paths, 0, 300) as $k => $p) {
        $dims[] = ['day' => $day, 'dim' => 'page', 'val' => $p[0], 'label' => $p[1], 'views' => mt_rand(1, 400), 'visitors' => mt_rand(1, 200), 'sessions' => 0, 'bounces' => 0];
    }
    foreach (array_slice($paths, 0, 120) as $p) {
        $dims[] = ['day' => $day, 'dim' => 'entry', 'val' => $p[0], 'label' => '', 'views' => mt_rand(1, 300), 'visitors' => mt_rand(1, 100), 'sessions' => mt_rand(1, 120), 'bounces' => mt_rand(0, 60)];
    }
    foreach ($ch as $c) {
        $dims[] = ['day' => $day, 'dim' => 'channel', 'val' => $c[0], 'label' => '', 'views' => mt_rand(50, 900), 'visitors' => mt_rand(20, 300), 'sessions' => $c[5] * mt_rand(8, 14), 'bounces' => mt_rand(5, 90)];
        if ($c[3] !== '') {
            $dims[] = ['day' => $day, 'dim' => 'campaign', 'val' => $c[3], 'label' => '', 'views' => mt_rand(50, 500), 'visitors' => mt_rand(20, 200), 'sessions' => mt_rand(20, 220), 'bounces' => mt_rand(5, 90)];
        }
    }
    foreach (['device' => ['mobile', 'desktop', 'tablet'], 'browser' => ['Safari', 'Chrome', 'Instagram', 'Samsung'], 'os' => ['iOS', 'Android', 'Windows', 'macOS'], 'country' => ['AE', 'SA', 'QA', 'OM', 'KW'], 'lang' => ['en', 'ar'], 'referrer' => ['l.instagram.com', 'google.com', 'wa.me', 'tiktok.com']] as $dim => $vals) {
        foreach ($vals as $val) {
            $dims[] = ['day' => $day, 'dim' => $dim, 'val' => $val, 'label' => '', 'views' => mt_rand(50, 900), 'visitors' => mt_rand(20, 300), 'sessions' => mt_rand(30, 900), 'bounces' => mt_rand(5, 300)];
        }
    }
    foreach (array_chunk($dims, 400) as $chunk) {
        DB::table('an_dims')->insert($chunk);
    }
}

// Orders with sources over 30 days.
for ($i = 0; $i < 150; $i++) {
    $c = $pick($ch);
    $at = now('UTC')->subMinutes(mt_rand(0, 30 * 1440));
    if ($i < 3) { $at = now('UTC')->subMinutes(mt_rand(1, 25)); }
    DB::table('orders')->insert(['order_number' => 'ANV-'.$i, 'email' => 'v'.$i.'@preview.test', 'status' => ['processing', 'completed', 'shipped'][mt_rand(0, 2)],
        'currency' => 'AED', 'subtotal' => $t = mt_rand(90, 600) * 100, 'total' => $t, 'created_at' => $at, 'updated_at' => $at,
        'src_channel' => mt_rand(1, 100) <= 10 ? null : $c[0], 'src_campaign' => $c[3] !== '' ? $c[3] : null]);
}

\App\Services\Analytics\Rollup::rollDay($tz->format('Y-m-d'));
\App\Services\Analytics\Rollup::rollDay($tz->subDay()->format('Y-m-d'));
echo 'seeded '.DB::table('an_hits')->count()." hits\n";
