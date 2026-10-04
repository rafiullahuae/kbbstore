<?php

/*
 * Lane MK — the preview's data for docs/mk-shots/ (run by tools/mk-preview.sh
 * through `artisan tinker`). A small catalogue with the approved previews'
 * product pictures, customers with paid orders across the emirates and
 * brands, subscribers in every state, coupons, journal posts, and campaigns in
 * every status — one of them really sent through the LOG mailer, so its
 * report has real rows and its email can be shown exactly as it went out.
 *
 * Addresses in the footer are the approved mocks' own placeholders — the
 * owner has not given the real ones yet (plan D9).
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Marketing\CampaignSender;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Rafi', 'password' => 'preview-secret-1', 'role' => 'owner']);
AdminUser::updateOrCreate(['email' => 'support@preview.test'], ['name' => 'Support', 'password' => 'preview-secret-1', 'role' => 'support']);

$set = app(SettingsService::class);
$set->set('mail_transport', 'log');
$set->set('mail_address_dubai', '[Dubai address]');
$set->set('mail_address_korea', '[Korea address — owner to paste]');

$brand = fn (string $n) => Brand::firstOrCreate(['name' => $n], ['slug' => strtolower(preg_replace('/\W+/', '-', $n))]);
$P = [];
$add = function (string $key, string $brandName, string $name, int $aed, ?int $sale, int $sales, int $daysOld) use ($brand, &$P) {
    $p = Product::create([
        'name' => $name, 'slug' => 'mk-' . $key, 'status' => 'publish', 'is_visible' => true,
        'price' => $aed * 100, 'sale_price' => $sale !== null ? $sale * 100 : null,
        'stock_status' => 'instock', 'brand_id' => $brand($brandName)->id, 'total_sales' => $sales,
        'image' => '/wp-content/uploads/mk/p-' . $key . '-400.jpg',
    ]);
    DB::table('products')->where('id', $p->id)->update(['created_at' => now()->subDays($daysOld)]);
    $P[$key] = $p;
};
$add('medicube-pad', 'Medicube', 'Zero Pore Pad 2.0 (70 pads)', 89, null, 9400, 3);
$add('medicube-serum', 'Medicube', 'PDRN Pink Peptide Serum 30ml', 129, null, 9300, 5);
$add('medicube-mask', 'Medicube', 'Collagen Night Wrapping Mask 75ml', 99, 79, 9200, 9);
$add('medicube-cream', 'Medicube', 'Deep Vita C Capsule Cream 55g', 115, null, 9100, 12);
$add('roundlab', 'Round Lab', 'Birch Juice Moisturizing Sunscreen 50ml', 95, 79, 9900, 1);
$add('skin1004', 'SKIN1004', 'Madagascar Centella Ampoule 55ml', 72, null, 9800, 2);
$add('laneige', 'Laneige', 'Lip Sleeping Mask Berry 20g', 85, null, 9700, 30);
$add('boj', 'Beauty of Joseon', 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', 115, null, 9600, 40);
$add('anua', 'Anua', 'Heartleaf 77% Soothing Toner 250ml', 89, 69, 9500, 20);
$add('cosrx', 'COSRX', 'Advanced Snail 96 Mucin Power Essence 100ml', 49, 39, 9050, 60);

// The demo catalogue the migrations seed has no pictures in this preview's
// web root; keep it off the shelf so the emails show the products above.
DB::table('products')->where('slug', 'not like', 'mk-%')->update(['status' => 'draft']);

foreach ([['GLOW15', 1500], ['MISSYOU10', 1000], ['MEDI10', 1000]] as [$code, $amt]) {
    DB::table('coupons')->insert(['code' => $code, 'type' => 'percent', 'amount' => $amt, 'expires_at' => now()->addDays(10), 'created_at' => now(), 'updated_at' => now()]);
}

foreach ([
    ['morning-routine-for-dewy-skin', 'A 5-minute morning routine for dewy skin', 'Toner, essence, sunscreen: the three steps that do most of the work.'],
    ['what-pdrn-does', 'PDRN, explained: why everyone is talking about it', 'What the ingredient is, what it is not, and who it suits.'],
] as $i => [$slug, $title, $excerpt]) {
    DB::table('posts')->insert(['slug' => $slug, 'title' => $title, 'excerpt' => $excerpt, 'body' => $excerpt, 'status' => 'published', 'published_at' => now()->subDays($i + 1), 'created_at' => now(), 'updated_at' => now()]);
}

$names = ['Aisha', 'Fatima', 'Mariam', 'Noura', 'Sara', 'Layla', 'Huda', 'Reem', 'Amal', 'Dana', 'Hessa', 'Maha', 'Lina', 'Rana', 'Zainab', 'Yasmin', 'Salma', 'Nadia', 'Hind', 'Shamsa'];
$emirates = ['Dubai', 'Dubai', 'DU', 'Abu Dhabi', 'AZ', 'Sharjah', 'Ajman', 'Ras Al Khaimah', 'Fujairah', 'Umm Al Quwain'];
$brandsBought = [['Medicube', 32000], ['Medicube', 18000], ['COSRX', 9000], ['Round Lab', 14000], ['Anua', 12000], ['Laneige', 8500]];
$n = 0;

foreach ($names as $i => $first) {
    for ($k = 0; $k < 2; $k++) {
        $n++;
        $c = Customer::create(['name' => $first . ' ' . chr(65 + $k), 'first_name' => $first, 'email' => strtolower($first) . ($k ? '.' . $k : '') . '@example.com']);
        $orders = ($n % 4) + ($k ? 0 : 1);

        for ($o = 0; $o < $orders; $o++) {
            [$b, $fils] = $brandsBought[($n + $o) % count($brandsBought)];
            $order = Order::create([
                'customer_id' => $c->id, 'order_number' => 'MKP-' . $n . '-' . $o, 'email' => $c->email,
                'status' => ['completed', 'processing', 'shipped'][$o % 3], 'subtotal' => $fils, 'total' => $fils, 'paid_at' => now(),
                'shipping_address' => ['first_name' => $first, 'city' => 'X', 'state' => $emirates[$n % count($emirates)], 'country' => $n % 13 === 0 ? 'SA' : 'AE'],
            ]);
            DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDays(($n * 11 + $o * 37) % 300 + 1)]);
            DB::table('order_items')->insert(['order_id' => $order->id, 'name' => $b . ' item', 'brand' => $b, 'quantity' => 1, 'unit_price' => $fils, 'subtotal' => $fils, 'total' => $fils, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}

foreach (range(1, 14) as $i) {
    DB::table('subscribers')->insert(['email' => 'reader' . $i . '@example.com', 'source' => $i % 3 ? 'homepage' : 'footer', 'status' => $i === 4 ? 'unsubscribed' : 'subscribed', 'confirmed_at' => $i === 7 || $i === 9 ? null : now(), 'created_at' => now()->subDays($i * 9), 'updated_at' => now()]);
}
DB::table('subscribers')->insert(['email' => 'sara@example.com', 'source' => 'homepage', 'status' => 'pending', 'confirmed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
DB::table('email_suppressions')->insert(['email' => 'reem@example.com', 'reason' => 'unsubscribe', 'source' => 'manual', 'created_at' => now()]);
DB::table('email_suppressions')->insert(['email' => 'dana.1@example.com', 'reason' => 'bounce', 'source' => 'campaign:0', 'created_at' => now()]);

$seg = fn (string $name, array $rules, string $aud = 'customers') => DB::table('mkt_segments')->insertGetId(['name' => $name, 'audience' => $aud, 'match' => 'all', 'rules' => json_encode($rules), 'preset' => false, 'created_at' => now(), 'updated_at' => now()]);
$medi = $seg('Mostly bought Medicube', [['field' => 'brand', 'op' => 'mostly', 'value' => 'Medicube']]);
$seg('Abu Dhabi · spent AED 300+', [['field' => 'emirate', 'op' => 'any_of', 'value' => ['abu_dhabi']], ['field' => 'spent', 'op' => 'gte', 'value' => 300]]);
$repeat = DB::table('mkt_segments')->where('key', 'repeat-buyers')->value('id');
$lapsed = DB::table('mkt_segments')->where('key', 'lapsed-90')->value('id');
$subs = DB::table('mkt_segments')->where('key', 'newsletter-subscribers')->value('id');
$never = DB::table('mkt_segments')->where('key', 'never-ordered')->value('id');

$tpl = fn (string $key) => DB::table('mkt_templates')->where('key', $key)->first();
$camp = function (string $key, string $name, ?int $segment, array $over = []) use ($tpl) {
    $t = $tpl($key);
    $blocks = json_decode($t->blocks, true);

    foreach ($blocks as &$b) {
        if ($b['type'] === 'coupon' && isset($over['coupon'])) {
            $b['props']['coupon_id'] = DB::table('coupons')->where('code', $over['coupon'])->value('id');
        }
    }

    unset($over['coupon']);

    return DB::table('mkt_campaigns')->insertGetId(array_merge([
        'name' => $name, 'template_id' => $t->id, 'blocks' => json_encode($blocks), 'subject' => $t->subject, 'preheader' => $t->preheader,
        'segment_id' => $segment, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ], $over));
};

// 1. Autumn Glow Edit — really sent, through the log mailer.
$glow = $camp('autumn-glow', 'Autumn Glow Edit', $repeat, ['coupon' => 'GLOW15', 'subject' => 'Skin that glows back — 15% inside']);
$sender = app(CampaignSender::class);
$sender->start($glow);
for ($i = 0; $i < 20 && ! $sender->step($glow)['done']; $i++) {
}
$sends = DB::table('mkt_sends')->where('campaign_id', $glow)->orderBy('id')->get();
$links = DB::table('mkt_links')->where('campaign_id', $glow)->orderBy('n')->get();
foreach ($sends->take(4) as $j => $s) {
    $at = now()->subDays(2)->addMinutes($j);
    DB::table('mkt_sends')->where('id', $s->id)->update(['first_click_at' => $at]);
    foreach ($links->take(2 + ($j % 2)) as $l) {
        DB::table('mkt_clicks')->insert(['send_id' => $s->id, 'link_id' => $l->id, 'clicked_at' => $at]);
    }
    if ($j < 2) {
        $c = Customer::where('email', $s->email)->first();
        $o = Order::create(['customer_id' => $c?->id, 'order_number' => 'MKA-' . $j, 'email' => $s->email, 'status' => 'processing', 'subtotal' => 19400 + $j * 5000, 'total' => 19400 + $j * 5000, 'paid_at' => now()]);
        DB::table('orders')->where('id', $o->id)->update(['created_at' => now()->subDay()]);
    }
}
DB::table('mkt_campaigns')->where('id', $glow)->update(['finished_at' => now()->subDays(2), 'started_at' => now()->subDays(2)->subMinutes(4)]);
DB::table('mkt_sends')->where('campaign_id', $glow)->update(['sent_at' => now()->subDays(2)->subMinutes(2)]);
app(\App\Services\Marketing\CampaignReport::class)->campaign($glow);

// 2. We miss you — part sent, still sending.
$miss = $camp('we-miss-you', 'We miss you — 10% off', $lapsed, ['coupon' => 'MISSYOU10', 'subject' => 'It has been a while, {first_name|friend}']);
$set->set('mkt_rate_per_minute', 4, false);
$sender->start($miss);
$sender->step($miss);
$sender->step($miss);
$set->set('mkt_rate_per_minute', 60, false);

// 3. Scheduled, 4. drafts.
$camp('new-arrivals', 'New in: Sun care', $subs, ['status' => 'scheduled', 'scheduled_at' => now()->addDays(4)->setTime(15, 0)]);
$camp('welcome', 'Welcome first order', $never);
$camp('brand-fans', 'More Medicube for you', $medi, ['coupon' => 'MEDI10', 'subject' => 'More Medicube, just for you', 'preheader' => 'New Medicube picks, chosen because you love the brand.']);

echo "mk seed: " . DB::table('mkt_campaigns')->count() . " campaigns, " . DB::table('mkt_sends')->count() . " sends\n";
