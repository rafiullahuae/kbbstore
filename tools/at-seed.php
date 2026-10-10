<?php
/*
 * Lane AT preview seed: "Time on site & engagement" on the Analytics board.
 * PREVIEW DATABASE ONLY (tools/at-preview.sh). On top of tools/an-seed.php:
 *
 *   - raw hits for yesterday and today with realistic dwell: ~45% one-page
 *     visits, the rest 2-7 pages with gaps of 0-12 minutes and the odd long
 *     break; Instagram Ads visitors skim, Google and email visitors read;
 *     ~12% of readers add to cart and ~40% of those reach checkout;
 *   - 28 older days of summaries rolled BEFORE time was recorded (no timed
 *     columns), so the 7- and 30-day ranges show "Time is recorded from";
 *   - orders by source over 30 days, so "Orders & revenue by source" has rows;
 *   - $GLOBALS['atLayout'] = 'owner' saves the owner's arrangement from the
 *     brief (revenue by source left, Sources and Top pages to its right,
 *     Countries below) for the preview owner.
 */
use App\Services\Analytics\BoardLayout;
use App\Services\Analytics\Rollup;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

if (app()->environment('production')) {
    throw new RuntimeException('Preview data only.');
}

require __DIR__.'/an-seed.php';

mt_srand(11);
DB::table('an_hits')->delete();
DB::table('an_days')->delete();
DB::table('an_dims')->delete();
DB::table('orders')->where('order_number', 'like', 'ATV-%')->delete();

$paths = [['/', 'K-Beauty Bliss — Korean skincare in the UAE'], ['/shop/', 'Shop all'], ['/collections/toners/', 'Toners'], ['/brands/anua/', 'Anua'], ['/blog/', 'Journal'], ['/collections/serums/', 'Serums']];
foreach (DB::table('products')->where('slug', 'like', 'an-%')->get(['slug', 'name']) as $p) {
    $paths[] = ['/product/'.$p->slug.'/', $p->name];
}
// channel, src, med, cmp, ref, weight, reading factor (1 = average)
$ch = [['instagram_ads', 'instagram', 'paid', 'eid_glow', '', 20, 0.55], ['instagram', 'ig', 'social', '', 'l.instagram.com', 13, 0.8],
    ['google', '', '', '', 'google.com', 17, 1.5], ['google_ads', 'google', 'cpc', 'brand_search', '', 9, 1.2],
    ['tiktok_ads', 'tiktok', 'paid', 'snail_launch', '', 8, 0.45], ['whatsapp', '', '', '', 'wa.me', 7, 1.3],
    ['direct', '', '', '', '', 18, 1.1], ['email', 'newsletter', 'email', 'october', '', 3, 1.8], ['facebook', '', '', '', 'm.facebook.com', 3, 0.7]];
$pick = function (array $list) { $t = array_sum(array_column($list, 5)); $r = mt_rand(1, $t); foreach ($list as $c) { $r -= $c[5]; if ($r <= 0) { return $c; } } return $list[0]; };
$devs = [['mobile', 'Safari', 'iOS', 0.85], ['mobile', 'Chrome', 'Android', 0.85], ['mobile', 'Instagram', 'iOS', 0.6], ['desktop', 'Chrome', 'Windows', 1.5], ['desktop', 'Safari', 'macOS', 1.6], ['tablet', 'Safari', 'iOS', 1.2]];
$gap = function (float $f): int {
    $r = mt_rand(1, 100);
    $g = $r <= 40 ? 0 : ($r <= 75 ? mt_rand(1, 2) : ($r <= 93 ? mt_rand(3, 5) : ($r <= 99 ? mt_rand(6, 12) : mt_rand(35, 90))));

    return $g >= 35 ? $g : (int) round($g * $f);
};

$now = intdiv(time(), 60);
$rows = [];
$flush = function () use (&$rows) { foreach (array_chunk($rows, 500) as $c) { DB::table('an_hits')->insert($c); } $rows = []; };
foreach ([1, 0] as $ago) {
    $day = StoreTime::now()->subDays($ago)->format('Y-m-d');
    [$from, $to] = Rollup::minutes($day);
    $to = min($to, $now);
    $visitors = $ago === 1 ? 980 : 860;
    for ($i = 0; $i < $visitors; $i++) {
        $v = substr(md5($day.'v'.$i), 0, 16);
        $c = $pick($ch);
        $d = $devs[mt_rand(0, count($devs) - 1)];
        $f = $c[6] * $d[3];
        $m = mt_rand($from, max($from, $to - 30));
        $s = substr(md5($v.'s'.$m), 0, 16);
        $pages = mt_rand(1, 100) <= (int) round(45 / max(0.6, min(1.4, $f))) ? 1 : mt_rand(2, 7);
        $cartAt = $pages > 1 && mt_rand(1, 100) <= (int) round(12 * $f) ? mt_rand(1, $pages - 1) : -1;
        $checkout = $cartAt >= 0 && mt_rand(1, 100) <= 40;
        $base = ['v' => $v, 'dev' => $d[0], 'br' => $d[1], 'os' => $d[2], 'cc' => ['AE', 'AE', 'AE', 'AE', 'SA', 'QA', 'OM', 'KW'][mt_rand(0, 7)], 'lang' => mt_rand(1, 100) <= 30 ? 'ar' : 'en'];
        for ($j = 0; $j < $pages; $j++) {
            if ($j > 0) {
                $m += $gap($f);
            }
            if ($m >= $to) {
                break;
            }
            $p = $j === 0 ? $paths[mt_rand(0, 8)] : $paths[mt_rand(0, count($paths) - 1)];
            if ($checkout && $j === $pages - 1) {
                $p = ['/checkout/', 'Checkout'];
            }
            $rows[] = $base + ['m' => $m, 's' => $s, 'k' => $p[0] === '/checkout/' ? 2 : 0, 'e' => $j === 0 ? 1 : 0, 'path' => $p[0], 'title' => $p[1],
                'ref' => $j === 0 ? $c[4] : '', 'ch' => $j === 0 ? $c[0] : 'direct', 'src' => $j === 0 ? $c[1] : '', 'med' => $j === 0 ? $c[2] : '', 'cmp' => $j === 0 ? $c[3] : ''];
            if ($j === $cartAt) {
                $rows[] = $base + ['m' => min($to - 1, $m + mt_rand(0, 1)), 's' => substr(md5($v.'d'), 0, 16), 'k' => 1, 'e' => 0, 'path' => '', 'title' => '',
                    'ref' => '', 'ch' => 'direct', 'src' => '', 'med' => '', 'cmp' => ''];
            }
        }
        if (count($rows) >= 2000) {
            $flush();
        }
    }
    $flush();
}
Rollup::rollDay(StoreTime::now()->subDay()->format('Y-m-d'));
Rollup::rollDay(StoreTime::now()->format('Y-m-d'));

// Older days, summarised before time on site existed: no timed columns.
for ($i = 2; $i <= 29; $i++) {
    $day = StoreTime::now()->subDays($i)->format('Y-m-d');
    $vis = 800 + mt_rand(0, 300);
    DB::table('an_days')->insert(['day' => $day, 'views' => $vis * 3, 'visitors' => $vis, 'sessions' => (int) ($vis * 1.1), 'bounces' => (int) ($vis * 0.48),
        'carts' => (int) ($vis * 0.08), 'checkouts' => (int) ($vis * 0.03), 'rolled_at' => now()]);
    $dims = [];
    foreach (array_slice($paths, 0, 14) as $p) {
        $dims[] = ['day' => $day, 'dim' => 'page', 'val' => $p[0], 'label' => $p[1], 'views' => mt_rand(20, 300), 'visitors' => mt_rand(10, 150), 'sessions' => 0, 'bounces' => 0];
    }
    foreach ($ch as $c) {
        $dims[] = ['day' => $day, 'dim' => 'channel', 'val' => $c[0], 'label' => '', 'views' => mt_rand(50, 600), 'visitors' => mt_rand(20, 200), 'sessions' => $c[5] * mt_rand(4, 7), 'bounces' => mt_rand(5, 60)];
    }
    DB::table('an_dims')->insert($dims);
}

// Orders with sources, last 30 days.
for ($i = 0; $i < 60; $i++) {
    $c = $pick(array_slice($ch, 0, 7));
    $at = now('UTC')->subMinutes(mt_rand(0, 30 * 1440));
    if ($i < 6) {
        $at = now('UTC')->subMinutes(mt_rand(5, 600));
    }
    DB::table('orders')->insert(['order_number' => 'ATV-'.$i, 'email' => 'at'.$i.'@preview.test', 'status' => ['processing', 'completed', 'shipped'][mt_rand(0, 2)],
        'currency' => 'AED', 'subtotal' => $t = mt_rand(90, 600) * 100, 'total' => $t, 'created_at' => $at, 'updated_at' => $at,
        'src_channel' => $c[0], 'src_campaign' => $c[3] !== '' ? $c[3] : null]);
}

$owner = \App\Models\AdminUser::where('email', 'owner@preview.test')->first();
if (($GLOBALS['atLayout'] ?? '') === 'owner' && $owner) {
    // The FULL order: a partial one is completed by BoardLayout::sanitize, which
    // re-inserts each missing block after its default predecessor.
    BoardLayout::put((int) $owner->id, ['live', 'feed', 'strip', 'revsrc', 'sources', 'pages', 'ccnow', 'pagesnow', 'srcnow',
        'daily', 'campaigns', 'utm', 'funnel', 'entry', 'search', 'google', 'referrers', 'devices', 'langs'], []);
} elseif ($owner) {
    BoardLayout::reset((int) $owner->id);
}
echo 'seeded '.DB::table('an_hits')->count()." hits\n";
