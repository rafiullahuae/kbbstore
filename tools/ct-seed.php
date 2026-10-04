<?php
/*
 * Seed the Lane CT preview: Growth & Marketing → Cart Tracking with realistic
 * data. Written into the PREVIEW's database only; nothing here ships.
 *
 *   ~640 carts over the last 60 days, most in the last week; mixed countries
 *   (mostly UAE and the Gulf); real phone and desktop browsers; bots from
 *   datacenters (python-requests, curl, HeadlessChrome, AhrefsBot), one burst
 *   of 14 carts from a single address; about a quarter bought, COD and card,
 *   in every order status; removals; a handful of blocks.
 *
 *   CT_SEED_CARTS=100000 makes the volume run (no orders, bulk inserts).
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartTracking\BotSignals;
use App\Services\CartTracking\HostingNetworks;
use App\Services\Security\IpBlockList;
use App\Support\IpRange;
use Illuminate\Support\Facades\DB;

mt_srand(20261004);

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$names = ['Beauty of Joseon Glow Serum', 'COSRX Advanced Snail 96 Mucin Essence', 'Anua Heartleaf 77% Soothing Toner', 'Round Lab Birch Juice Sunscreen',
    'Skin1004 Centella Ampoule', 'Torriden Dive-In Serum', 'Laneige Lip Sleeping Mask', 'Medicube Zero Pore Pad', 'Isntree Hyaluronic Acid Toner',
    'Some By Mi AHA BHA PHA Toner', 'Innisfree Green Tea Seed Serum', 'Banila Co Clean It Zero Balm', 'Klairs Supple Preparation Toner',
    'Purito Centella Unscented Serum', 'Dr. Jart+ Cicapair Cream', 'Mixsoon Bean Essence', 'Numbuzin No.3 Skin Softening Serum',
    'Abib Heartleaf Sun Essence', 'Ma:nyo Pure Cleansing Oil', 'Biodance Bio-Collagen Real Deep Mask', 'Haruharu Wonder Black Rice Toner',
    'Etude SoonJung 2x Barrier Cream', 'Axis-Y Dark Spot Correcting Glow Serum', 'Pyunkang Yul Essence Toner', 'I\'m From Rice Toner',
    'Benton Snail Bee High Content Essence', 'Neogen Real Ferment Micro Essence', 'Heimish All Clean Balm', 'Skinfood Carrot Carotene Pad',
    'Missha Time Revolution First Essence'];

$products = [];
foreach ($names as $i => $name) {
    $products[] = Product::updateOrCreate(['slug' => 'ct-'.\Illuminate\Support\Str::slug($name)], [
        'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => [4500, 5900, 6900, 7900, 8900, 9900, 11900, 12900, 14900][$i % 9], 'stock_status' => 'instock',
    ]);
}

$volume = (int) (getenv('CT_SEED_CARTS') ?: 0);

$browsers = [
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 13; 2201117TY) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
];
$bots = [
    ['python-requests/2.31.0', null],
    ['curl/8.4.0', null],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0.0.0 Safari/537.36', 180],
    ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', null],
    ['Go-http-client/1.1', null],
    ['Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.112 Safari/537.36', null],
];
$homes = [
    'AE' => ['94.200.', '86.98.', '5.195.', '2.50.', '83.110.', '91.73.'],
    'SA' => ['37.106.', '188.50.', '51.36.'],
    'KW' => ['37.36.', '62.215.'],
    'QA' => ['37.208.', '89.211.'],
    'OM' => ['5.36.', '188.135.'],
    'BH' => ['37.131.', '89.148.'],
    'GB' => ['81.2.', '86.132.'],
    'US' => ['73.15.', '98.207.'],
    'IN' => ['49.36.', '106.208.'],
    'PK' => ['39.40.', '119.155.'],
    'EG' => ['41.33.', '156.204.'],
];
$weights = ['AE' => 52, 'SA' => 14, 'KW' => 5, 'QA' => 5, 'OM' => 4, 'BH' => 3, 'GB' => 3, 'US' => 3, 'IN' => 4, 'PK' => 3, 'EG' => 2, '' => 2];
$dc = ['3.5.140.', '159.89.', '95.216.', '45.77.', '172.105.', '34.120.'];

$pickCountry = function () use ($weights) {
    $r = mt_rand(1, array_sum($weights));
    foreach ($weights as $cc => $w) {
        if (($r -= $w) <= 0) {
            return $cc;
        }
    }
    return 'AE';
};

$statuses = ['processing', 'processing', 'completed', 'completed', 'completed', 'pending', 'on-hold', 'cancelled', 'failed'];
$first = ['Aisha', 'Fatima', 'Mariam', 'Noura', 'Sara', 'Hessa', 'Layla', 'Reem', 'Huda', 'Amna', 'Priya', 'Zainab', 'Emma', 'Olivia'];
$last = ['Khan', 'Al Mansoori', 'Al Hashimi', 'Hassan', 'Ahmed', 'Rahman', 'Nair', 'Al Suwaidi', 'Saeed', 'Malik', 'Brown'];

$now = now();
$count = $volume > 0 ? $volume : 640;
$nextOrder = (int) (DB::table('orders')->max('id') ?? 0) + 1;
$eventsBuf = [];
$itemsBuf = [];
$burstIp = '159.89.120.41';

$flush = function () use (&$eventsBuf, &$itemsBuf) {
    foreach (array_chunk($eventsBuf, 500) as $c) {
        DB::table('cart_events')->insert($c);
    }
    foreach (array_chunk($itemsBuf, 500) as $c) {
        DB::table('cart_items')->insert($c);
    }
    $eventsBuf = [];
    $itemsBuf = [];
};

DB::beginTransaction();

for ($n = 0; $n < $count; $n++) {
    // Time: most carts recent, a long tail back 60 days (or 3 years for the volume run).
    $span = $volume > 0 ? 1095 * 86400 : 60 * 86400;
    $ago = (int) ($span * pow(mt_rand(0, 1000) / 1000, 2.4));
    $start = $now->copy()->subSeconds($ago);

    $isBurst = $volume === 0 && $n >= 300 && $n < 314;
    $isBot = $isBurst || mt_rand(1, 100) <= 11;

    if ($isBot) {
        [$ua, $ms] = $isBurst ? ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 300] : $bots[array_rand($bots)];
        $ip = $isBurst ? $burstIp : $dc[array_rand($dc)].mt_rand(1, 254).'.'.mt_rand(1, 254);
        $cc = $isBurst ? 'US' : ['US', 'DE', 'NL', 'SG', 'FR', ''][mt_rand(0, 5)];
        if ($isBurst) {
            $start = $now->copy()->subHours(5)->addMinutes($n - 300);
        }
    } else {
        $ua = $browsers[array_rand($browsers)];
        $ms = mt_rand(1, 100) <= 6 ? mt_rand(300, 1200) : mt_rand(4000, 90000);
        $cc = $pickCountry();
        $pre = $homes[$cc !== '' ? $cc : 'AE'][array_rand($homes[$cc !== '' ? $cc : 'AE'])];
        $ip = $pre.mt_rand(1, 254).'.'.mt_rand(1, 254);
        if (mt_rand(1, 100) <= 6) {
            $ip = sprintf('2001:8f8:%x:%x:%x:%x:%x:%x', mt_rand(1, 0xffff), mt_rand(1, 0xffff), mt_rand(1, 0xffff), mt_rand(1, 0xffff), mt_rand(1, 0xffff), mt_rand(1, 0xffff));
        }
    }

    [$uaFlags] = BotSignals::agent($ua);
    [$jsFlags] = BotSignals::script($ms === null ? null : (string) $ms, 1500);
    $flags = $uaFlags | $jsFlags;
    if ($volume === 0 && HostingNetworks::contains($ip)) {
        $flags |= BotSignals::HOSTING;
    }
    if ($isBurst && $n >= 304) {
        $flags |= BotSignals::BURST_IP;
    }

    // Lines.
    $lines = [];
    $events = [];
    $t = $start->copy();
    $adds = $isBot ? mt_rand(1, 6) : mt_rand(1, 4);
    $value = 0;
    $removed = 0;
    for ($a = 0; $a < $adds; $a++) {
        $p = $products[mt_rand(0, count($products) - 1)];
        $qty = mt_rand(1, 100) <= 80 ? 1 : mt_rand(2, 3);
        $t = $t->copy()->addSeconds(mt_rand(20, 600));
        $pid = (int) $p->id;
        $lines[$pid] = ['qty' => ($lines[$pid]['qty'] ?? 0) + $qty, 'price' => (int) $p->price];
        $events[] = ['type' => 1, 'product_id' => $pid, 'qty' => $qty, 'qty_after' => $lines[$pid]['qty'], 'unit_price' => (int) $p->price, 'created_at' => $t->copy()];
    }
    if (count($lines) > 1 && mt_rand(1, 100) <= 30) {
        $pid = array_rand($lines);
        $t = $t->copy()->addSeconds(mt_rand(30, 900));
        $events[] = ['type' => 2, 'product_id' => $pid, 'qty' => -$lines[$pid]['qty'], 'qty_after' => 0, 'unit_price' => $lines[$pid]['price'], 'created_at' => $t->copy()];
        unset($lines[$pid]);
        $removed++;
    }
    if ($lines && mt_rand(1, 100) <= 15) {
        $pid = array_rand($lines);
        $t = $t->copy()->addSeconds(mt_rand(30, 600));
        $events[] = ['type' => 3, 'product_id' => $pid, 'qty' => 1, 'qty_after' => $lines[$pid]['qty'] + 1, 'unit_price' => $lines[$pid]['price'], 'created_at' => $t->copy()];
        $lines[$pid]['qty']++;
    }
    foreach ($lines as $l) {
        $value += $l['qty'] * $l['price'];
    }
    // Never in the future: the last event is at most "now".
    if ($t->greaterThan($now)) {
        $shift = $t->diffInSeconds($now, true);
        $t = $now->copy();
        foreach ($events as &$ev) {
            $ev['created_at'] = $ev['created_at']->copy()->subSeconds($shift);
        }
        unset($ev);
    }

    $bought = ! $isBot && $lines && mt_rand(1, 100) <= 27 && $volume === 0;
    $orderId = null;
    $status = 'active';
    $customerEmail = null;

    if ($bought) {
        $fn = $first[array_rand($first)];
        $ln = $last[array_rand($last)];
        $customerEmail = strtolower($fn.'.'.str_replace(' ', '', $ln)).mt_rand(1, 99).'@example.com';
        $orderAt = $t->copy();
        $pay = mt_rand(1, 100) <= 55 ? 'cod' : 'stripe';
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => (string) (10000 + $nextOrder++),
            'email' => $customerEmail, 'phone' => '+9715'.mt_rand(10000000, 99999999),
            'status' => $statuses[array_rand($statuses)], 'currency' => 'AED',
            'billing_address' => json_encode(['first_name' => $fn, 'last_name' => $ln, 'country' => $cc ?: 'AE', 'city' => 'Dubai']),
            'shipping_address' => json_encode(['first_name' => $fn, 'last_name' => $ln, 'country' => $cc ?: 'AE', 'city' => 'Dubai']),
            'subtotal' => $value, 'discount_total' => 0, 'shipping_total' => 2000, 'fee_total' => 0, 'tax_total' => 0,
            'total' => $value + 2000, 'payment_method' => $pay, 'payment_method_title' => $pay === 'cod' ? 'Cash on delivery' : 'Card',
            'ip_address' => $ip, 'created_at' => $orderAt, 'updated_at' => $orderAt,
        ]);
        $status = 'converted';
    } elseif ($volume === 0 && ! $isBot && mt_rand(1, 100) <= 12) {
        // A guest who asked to be reminded: their email is known.
        $customerEmail = strtolower($first[array_rand($first)]).mt_rand(100, 999).'@example.com';
    }

    $cartId = DB::table('carts')->insertGetId([
        'token' => (string) \Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => $status,
        'last_activity_at' => $t, 'converted_at' => $bought ? $t : null,
        'created_at' => $start, 'updated_at' => $t,
        'ct_ip' => IpRange::normalise($ip), 'ct_net' => IpRange::rangeOf($ip), 'ct_country' => $cc !== '' ? $cc : null,
        'ct_ua' => mb_substr($ua, 0, 255), 'ct_bot_flags' => $flags, 'ct_bot_score' => BotSignals::score($flags),
        'ct_speed_ms' => $ms, 'ct_value' => $value, 'ct_added' => $adds, 'ct_removed' => $removed,
        'ct_first_at' => $events[0]['created_at'], 'ct_last_at' => $t, 'ct_order_id' => $orderId,
    ]);

    if ($customerEmail !== null && ! $bought && $volume === 0) {
        DB::table('cart_recoveries')->insert(['cart_id' => $cartId, 'email' => $customerEmail, 'source' => 'cart', 'stage' => 0,
            'consented_at' => $t, 'created_at' => $t, 'updated_at' => $t]);
    }

    foreach ($events as $e) {
        $eventsBuf[] = ['cart_id' => $cartId] + $e + ['variant_id' => null, 'ip' => null, 'country' => null];
    }
    foreach ($lines as $pid => $l) {
        $itemsBuf[] = ['cart_id' => $cartId, 'product_id' => $pid, 'quantity' => $l['qty'], 'unit_price' => $l['price'], 'created_at' => $t, 'updated_at' => $t];
    }

    if (count($eventsBuf) > 4000) {
        $flush();
        DB::commit();
        DB::beginTransaction();
    }
}
$flush();
DB::commit();

if ($volume === 0) {
    $who = ['admin_ip' => '127.0.0.9', 'admin_id' => 1, 'admin_name' => 'Preview Owner'];
    $list = app(IpBlockList::class);
    $list->block($burstIp, $who + ['reason' => '14 carts in 14 minutes', 'source' => 'cart']);
    $list->block('45.77.0.0/16', $who + ['reason' => 'Scraper network', 'days' => 30]);
    $list->block('37.106.44.0/24', $who + ['reason' => 'Fake COD orders — 3 refused deliveries', 'source' => 'manual']);
    $list->block('2001:8f8:1a2b:3c4d::/64', $who + ['reason' => 'Fake COD order', 'source' => 'manual']);
    DB::table('ip_blocks')->where('cidr', '37.106.44.0/24')->update(['hits' => 37, 'last_hit_at' => $now->copy()->subHours(2)]);
    DB::table('ip_blocks')->where('cidr', '159.89.120.41/32')->update(['hits' => 212, 'last_hit_at' => $now->copy()->subMinutes(9)]);
    IpBlockList::rebuild();
}

echo sprintf("ct seed: %d carts, %d events, %d orders, %d blocks\n",
    DB::table('carts')->count(), DB::table('cart_events')->count(), DB::table('orders')->count(), DB::table('ip_blocks')->count());
