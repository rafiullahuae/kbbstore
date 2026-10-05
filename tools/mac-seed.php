<?php

declare(strict_types=1);

/*
 * Lane MAC: the owner-app preview's shop, from the approved Petal preview's
 * own sample data (docs/owner-app-preview/index.html) so the screenshots can be
 * held against it. Run through tools/mac-preview.sh; never against a real
 * database.
 *
 *   owner@example.com / PIN 4826   Full Admin (Rafi)
 *   ayesha@example.com / PIN 7391  Customer Support (Ayesha)
 */

use App\Services\OwnerApp\OwnerAppPath;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$now = StoreTime::now();
$utc = static fn ($local) => $local->utc()->format('Y-m-d H:i:s');

// Products are drawn, as the preview draws them: a gradient and a bottle.
$art = static function (array $c, string $shape): string {
    [$a, $b, $k] = $c;
    $body = match ($shape) {
        'tube' => '<rect x="84" y="62" width="32" height="96" rx="6" fill="#fff" opacity=".92"/><rect x="88" y="44" width="24" height="20" rx="3" fill="'.$k.'"/>',
        'jar' => '<rect x="56" y="104" width="88" height="54" rx="10" fill="#fff" opacity=".92"/><rect x="52" y="86" width="96" height="22" rx="6" fill="'.$k.'"/>',
        'device' => '<rect x="90" y="70" width="20" height="96" rx="10" fill="'.$k.'"/><circle cx="100" cy="56" r="26" fill="#fff" opacity=".85"/>',
        default => '<rect x="74" y="70" width="52" height="96" rx="12" fill="#fff" opacity=".92"/><rect x="88" y="46" width="24" height="26" rx="3" fill="'.$k.'"/>',
    };
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/></linearGradient></defs><rect width="200" height="200" fill="url(#g)"/>'.$body.'</svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$brands = [];
foreach (['Anua', 'COSRX', 'Beauty of Joseon', 'Medicube', "d'Alba", 'Arencia'] as $i => $b) {
    $brands[$b] = DB::table('brands')->insertGetId(['name' => $b, 'slug' => 'mac-'.$i, 'created_at' => now(), 'updated_at' => now()]);
}
$cats = [];
foreach (['Toner', 'Sensitive skin', 'Bestsellers', 'Cleanser', 'Essence', 'Sunscreen', 'Pads', 'Serum', 'Devices', 'Gift sets'] as $i => $c) {
    $cats[$c] = DB::table('categories')->insertGetId(['name' => $c, 'slug' => 'mac-cat-'.$i, 'created_at' => now(), 'updated_at' => now()]);
}

$P = [
    'anua' => ['Anua Heartleaf 77% Soothing Toner', 76, 68, 6, 'ANU-HL77-250', ['#E6F3DC', '#B6D9A2', '#6E9A5A'], 'bottle', 'Anua', ['Toner', 'Sensitive skin', 'Bestsellers']],
    'cosrx' => ['COSRX Advanced Snail 96 Mucin Power Essence', 69, null, 120, 'CRX-SN96-100', ['#FCEBD2', '#EFC58A', '#B98545'], 'bottle', 'COSRX', ['Essence', 'Bestsellers']],
    'boj' => ['Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+', 65, null, 3, 'BOJ-RS-50', ['#F8F1DF', '#E3D2A4', '#5F7E44'], 'tube', 'Beauty of Joseon', ['Sunscreen']],
    'medi' => ['Medicube Zero Pore Pad 2.0', 89, null, 42, 'MDC-ZPP2-70', ['#FFE1EA', '#FFAFC5', '#D9476F'], 'jar', 'Medicube', ['Pads']],
    'dalba' => ["d'Alba White Truffle First Spray Serum", 118, null, 0, 'DLB-WT-SS100', ['#FBF5EC', '#E8D7BA', '#A9823F'], 'bottle', "d'Alba", ['Serum']],
    'aren' => ['Arencia Fresh Green Rice Mochi Cleanser', 92, null, 27, 'ARN-GRM-120', ['#E2F1E3', '#A3D0A9', '#3B7A4B'], 'jar', 'Arencia', ['Cleanser']],
    'ager' => ['Medicube AGE-R Booster Pro', 1150, null, 8, 'MDC-AGER-BP', ['#FFE7EF', '#F5A3BC', '#3A3340'], 'device', 'Medicube', ['Devices']],
    'oil' => ['Anua Heartleaf Pore Control Cleansing Oil', 84, null, 15, 'ANU-HL-CO200', ['#F2F8E6', '#D0E5AE', '#86A657'], 'bottle', 'Anua', ['Cleanser']],
];
$pid = [];
$i = 0;
foreach ($P as $k => [$name, $price, $sale, $stock, $sku, $c, $shape, $brand, $pcats]) {
    $img = $art($c, $shape);
    $pid[$k] = DB::table('products')->insertGetId([
        'slug' => 'mac-'.$k, 'name' => $name, 'sku' => $sku, 'brand_id' => $brands[$brand], 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $price * 100, 'sale_price' => $sale === null ? null : $sale * 100, 'manage_stock' => true, 'stock' => $stock,
        'stock_status' => $stock > 0 ? 'instock' : 'outofstock', 'image' => $img, 'images' => json_encode([$art($c, $shape === 'bottle' ? 'jar' : 'bottle')]),
        'short_description' => 'Calms redness in one step.', 'description' => '<p>A mild, low-pH formula that calms redness and refreshes skin straight after cleansing. Suits sensitive and acne-prone skin.</p>',
        'created_at' => $utc($now->subDays(60 - $i)), 'updated_at' => now(),
    ]);
    foreach ($pcats as $cn) {
        DB::table('category_product')->insert(['product_id' => $pid[$k], 'category_id' => $cats[$cn]]);
    }
    $i++;
}

$CU = ['Sabina Dev' => 'sabina.dev@example.com', 'Mariam Al Suwaidi' => 'mariam.s@example.com', 'Fatima Khan' => 'fatima.k@example.com',
    'Aisha Rahman' => 'aisha.r@example.com', 'Noor Haddad' => 'noor.h@example.com', 'Layla Ahmed' => 'layla.a@example.com',
    'Hessa Al Mansoori' => 'hessa.m@example.com', 'Priya Nair' => 'priya.n@example.com', 'Reem Saeed' => 'reem.s@example.com', 'Zainab Ali' => 'zainab.a@example.com'];
$cid = [];
$n = 0;
foreach ($CU as $name => $email) {
    $cid[$name] = DB::table('customers')->insertGetId(['name' => $name, 'first_name' => explode(' ', $name)[0], 'email' => $email,
        'phone' => '+97150'.str_pad((string) (1000000 + $n * 7919), 7, '0', STR_PAD_LEFT), 'created_at' => $utc($now->subMonths(7)->addDays($n * 9)), 'updated_at' => now()]);
    $n++;
}
DB::table('addresses')->insert(['customer_id' => $cid['Sabina Dev'], 'first_name' => 'Sabina', 'last_name' => 'Dev', 'line1' => 'Marina Gate 2, Apt 1804',
    'city' => 'Dubai Marina', 'state' => 'Dubai', 'country' => 'AE', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

$PAY = ['tabby' => ['tabby', 'Tabby · Pay in 4'], 'tamara' => ['tamara', 'Tamara · Split in 3'], 'card' => ['stripe', 'Visa •••• 4242'], 'apple' => ['stripe', 'Apple Pay']];
$ST = ['proc' => 'processing', 'pend' => 'pending', 'hold' => 'onhold', 'done' => 'completed', 'fail' => 'failed', 'ref' => 'refunded', 'canc' => 'cancelled'];
$O = [
    [33447, 'Sabina Dev', 0, '10:19', 'proc', 'tabby', [['anua', 1], ['cosrx', 1]], 4, 'GLOW', 'Referral · l.instagram.com', ['Marina Gate 2, Apt 1804', 'Dubai Marina', 'Dubai']],
    [33446, 'Mariam Al Suwaidi', 0, '09:52', 'pend', 'tamara', [['medi', 2], ['boj', 1]], 0, null, 'Organic · google.com', ['Villa 14, Street 23', 'Al Barsha 2', 'Dubai']],
    [33445, 'Fatima Khan', 0, '08:31', 'proc', 'card', [['dalba', 1]], 0, null, 'Direct', ['Al Nahda Tower B, 1207', 'Al Nahda', 'Sharjah']],
    [33444, 'Aisha Rahman', 0, '01:12', 'hold', 'card', [['aren', 1], ['oil', 1], ['boj', 2]], 0, null, 'Referral · tiktok.com', ['Villa 7, Khalifa City A', 'Khalifa City', 'Abu Dhabi']],
    [33443, 'Noor Haddad', 1, '21:40', 'done', 'apple', [['anua', 1]], 0, null, 'Email · Newsletter', ['Cluster X, Lake Terrace 2203', 'JLT', 'Dubai']],
    [33442, 'Layla Ahmed', 1, '16:05', 'done', 'tabby', [['cosrx', 1], ['medi', 1], ['boj', 1]], 0, null, 'Referral · l.instagram.com', ['Sky Tower, 3104', 'Al Reem Island', 'Abu Dhabi']],
    [33441, 'Hessa Al Mansoori', 1, '11:27', 'fail', 'tamara', [['ager', 1]], 0, null, 'Organic · google.com', ['Al Jimi District', 'Al Ain', 'Abu Dhabi']],
    [33438, 'Priya Nair', 3, '18:48', 'ref', 'card', [['boj', 1]], 0, null, 'Direct', ['Al Rashidiya 1, Bldg 9', 'Al Rashidiya', 'Ajman']],
    [33436, 'Reem Saeed', 4, '14:15', 'done', 'tabby', [['medi', 1], ['oil', 1], ['anua', 1]], 0, null, 'Referral · l.instagram.com', ['Al Hamra Village, Villa 31', 'Al Hamra', 'Ras Al Khaimah']],
    [33433, 'Zainab Ali', 5, '10:02', 'canc', 'card', [['cosrx', 1]], 0, null, 'Direct', ['Mirdif Hills, Bldg 4', 'Mirdif', 'Dubai']],
];

$makeOrder = function (int $num, string $who, $at, string $status, string $pay, array $items, int $disc, ?string $coupon, ?string $origin, array $adr) use ($P, $pid, $cid, $CU, $PAY, $utc): int {
    $sub = 0;
    foreach ($items as [$k, $q]) {
        $sub += $P[$k][1] * $q * 100;
    }
    $ship = $sub >= 20000 ? 0 : 2000;
    $total = $sub - $disc * 100 + $ship;
    [$first, $last] = array_pad(explode(' ', $who, 2), 2, '');
    $address = ['first_name' => $first, 'last_name' => $last, 'line1' => $adr[0], 'city' => $adr[1], 'state' => $adr[2], 'country' => 'AE', 'phone' => '+971 50 555 '.substr((string) $num, -4)];
    $id = DB::table('orders')->insertGetId([
        'order_number' => (string) $num, 'customer_id' => $cid[$who], 'email' => $CU[$who], 'phone' => $address['phone'], 'status' => $status,
        'billing_address' => json_encode($address), 'shipping_address' => json_encode($address), 'subtotal' => $sub, 'discount_total' => $disc * 100,
        'shipping_total' => $ship, 'tax_total' => (int) round($total * 5 / 105), 'total' => $total, 'shipping_method' => $ship ? 'Delivery · 1–2 days' : 'Free delivery over AED 200',
        'payment_method' => $PAY[$pay][0], 'payment_method_title' => $PAY[$pay][1], 'coupon_code' => $coupon, 'origin' => $origin,
        'paid_at' => in_array($status, ['processing', 'completed', 'onhold', 'shipped'], true) ? $utc($at->addMinute()) : null,
        'created_at' => $utc($at), 'updated_at' => $utc($at),
    ]);
    foreach ($items as [$k, $q]) {
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $pid[$k], 'name' => $P[$k][0], 'sku' => $P[$k][4], 'quantity' => $q,
            'unit_price' => $P[$k][1] * 100, 'subtotal' => $P[$k][1] * 100 * $q, 'total' => $P[$k][1] * 100 * $q, 'created_at' => $utc($at), 'updated_at' => $utc($at)]);
    }

    return $id;
};

// History first (lower ids): the last week's bars, Sabina's eight months, last month's top sellers.
$num = 31000;
$keys = array_keys($P);
for ($d = 6; $d >= 2; $d--) {
    for ($j = 0; $j < 3 + ($d % 3); $j++) {
        $makeOrder($num++, array_keys($CU)[($d + $j) % 10], $now->subDays($d)->setTime(9 + $j * 3, 15), 'completed', ['tabby', 'card', 'tamara'][$j % 3],
            [[$keys[($d + $j) % 8] === 'ager' ? 'boj' : $keys[($d + $j) % 8], 1 + $j % 2], ['cosrx', 1]], 0, null, 'Direct', ['Downtown', 'Downtown', 'Dubai']);
    }
}
foreach ([[7, 188], [5, 96], [4, 145], [3, 312], [2, 154], [1, 228]] as [$m, $v]) {
    $makeOrder($num++, 'Sabina Dev', $now->subMonthsNoOverflow($m)->setTime(12, 0), 'completed', 'tabby', [['anua', 1], ['boj', 1]], 0, null, 'Referral · l.instagram.com', ['Marina Gate 2, Apt 1804', 'Dubai Marina', 'Dubai']);
}

$ids = [];
foreach (array_reverse($O) as [$num2, $who, $daysAgo, $hm, $st, $pay, $items, $disc, $coupon, $origin, $adr]) {
    [$h, $m] = array_map('intval', explode(':', $hm));
    $at = $now->subDays($daysAgo)->setTime($h, $m);
    if ($at->gt($now)) {
        $at = $now->subMinutes(30 + count($ids) * 25);
    }
    $ids[$num2] = $makeOrder($num2, $who, $at, $ST[$st], $pay, $items, $disc, $coupon, $origin, $adr);
}

$t = $now->setTime(10, 19);
foreach ([
    ['Order placed from checkout · Instagram referral', 0], ['Stock held for 60 minutes', 0], ['Tabby payment authorised', 1],
    ['Tabby payment captured · AED 161.00', 1], ['Stock reduced: Heartleaf toner 7 → 6, Snail essence 121 → 120', 1],
    ['Order email to customer failed (mail timeout); resent and delivered', 2], ['Status changed from Pending payment to Processing', 5],
] as [$text, $min]) {
    DB::table('order_notes')->insert(['order_id' => $ids[33447], 'author' => 'system', 'is_customer_note' => false, 'content' => $text,
        'created_at' => $utc($t->addMinutes($min)), 'updated_at' => now()]);
}

// Owner-app people.
$owner = DB::table('admin_users')->insertGetId(['name' => 'Rafi Ullah', 'email' => 'owner@example.com', 'password' => Hash::make('preview-password'), 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
$staff = DB::table('admin_users')->insertGetId(['name' => 'Ayesha Malik', 'email' => 'ayesha@example.com', 'password' => Hash::make('preview-password'), 'role' => 'support', 'created_at' => now(), 'updated_at' => now()]);
foreach ([[$owner, '4826'], [$staff, '7391']] as [$u, $pin]) {
    DB::table('owner_app_members')->insert(['admin_user_id' => $u, 'enabled' => true, 'pin_hash' => Hash::make($pin), 'pin_length' => strlen($pin),
        'pin_set_at' => now(), 'notify' => json_encode(['orders', 'status', 'payments', 'stock']), 'created_at' => now(), 'updated_at' => now()]);
}

foreach ([
    ['order.refunded', $ids[33438], 'Refunded · #33438', 'AED 85 · back to Visa •••• 4242', 26 * 60],
    ['order.status', $ids[33442], '#33442 is now Completed', 'Processing → Completed · AED 241', 22 * 60],
    ['stock.out', $pid['dalba'], "Out of stock: d'Alba White Truffle First Spray Serum", 'Last unit sold in #33445', 300],
    ['order.status', $ids[33445], '#33445 is now Processing', 'Pending payment → Processing', 240],
    ['order.failed', $ids[33441], 'Payment failed · #33441', 'AED 1,150 · Tamara', 60],
    ['stock.low', $pid['boj'], 'Low stock: Beauty of Joseon Relief Sun', '3 left', 18],
    ['order.new', $ids[33447], 'New order #33447', 'AED 161 · Sabina · Tabby', 2],
] as [$type, $ref, $title, $body, $minsAgo]) {
    DB::table('owner_app_events')->insert(['type' => $type, 'ref_id' => $ref, 'title' => $title, 'body' => $body, 'created_at' => now()->subMinutes($minsAgo)]);
}

DB::table('product_view_days')->insert(['product_id' => $pid['anua'], 'day' => now()->toDateString(), 'views' => 214]);
for ($c = 0; $c < 120; $c++) {
    DB::table('carts')->insert(['token' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now()->subMinutes($c * 3), 'updated_at' => now()]);
}

echo 'Owner app at /'.OwnerAppPath::current()."/  (owner@example.com / 4826)\n";
