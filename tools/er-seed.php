<?php
/*
 * Seed the Lane ER preview (Marketing Emails → Reports, the full report): an
 * owner, three sent campaigns, and for "Autumn glow" 240 recipients with a
 * realistic spread of Apple Mail auto-opens, Gmail proxy opens, real opens,
 * scanner loads, clicks over two days on product cards, bounces, an
 * unsubscribe, orders after a click, and the site visits its UTM links brought
 * (an_dims, as Rollup writes them). The PREVIEW's database only.
 */
use App\Services\Marketing\CampaignLinks;
use Illuminate\Support\Facades\DB;

mt_srand(467);
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'],
    ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$seg = DB::table('mkt_segments')->insertGetId(['name' => 'All customers', 'audience' => 'customers', 'match' => 'all', 'rules' => '[]', 'created_at' => now(), 'updated_at' => now()]);
$names = ['Autumn glow — 20% off' => 240, "This week's special" => 120, 'Best sellers' => 60];
$shop = rtrim(\App\Support\Url::external('/'), '/');
$products = ['Zero Pore Pad 2.0', 'Advanced Snail 96 Essence', 'Relief Sun SPF50', 'Heartleaf 77% Toner', 'Glow Deep Serum', 'Dynasty Cream'];
foreach ($products as $p) {
    DB::table('products')->insertOrIgnore(['name' => $p, 'slug' => \Illuminate\Support\Str::slug($p), 'price' => 7900, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now()]);
}
$k = 0;
foreach ($names as $name => $n) {
    $k++;
    $start = now()->subDays(12 - $k * 4)->setTime(9, 0);
    $id = DB::table('mkt_campaigns')->insertGetId(['name' => $name, 'subject' => $name, 'status' => 'sent', 'segment_id' => $seg, 'blocks' => '[]',
        'rules_snapshot' => json_encode(['segment' => 'All customers', 'audience' => 'customers']),
        'started_at' => $start, 'finished_at' => $start->copy()->addMinutes(40), 'recipients' => $n, 'sent' => $n, 'created_at' => $start, 'updated_at' => $start]);
    $links = [];
    foreach (array_merge($products, ['Shop the edit', 'Instagram']) as $i => $label) {
        $url = $label === 'Instagram' ? 'https://www.instagram.com/kbeautybliss/' : ($label === 'Shop the edit' ? $shop . '/shop/' : $shop . '/product/' . \Illuminate\Support\Str::slug($label) . '/');
        $links[] = DB::table('mkt_links')->insertGetId(['campaign_id' => $id, 'n' => $i + 1, 'url' => $url, 'label' => $label]);
    }
    $clickers = 0;
    for ($i = 1; $i <= $n; $i++) {
        $r = mt_rand(1, 100);
        $status = $i % 41 === 0 ? 'failed' : 'sent';
        $row = ['campaign_id' => $id, 'email' => sprintf('customer%03d.%s@example.com', $i, ['gmail', 'icloud', 'outlook', 'yahoo'][$i % 4]), 'first_name' => 'C' . $i,
            'token' => bin2hex(random_bytes(20)), 'status' => $status, 'sent_at' => $start->copy()->addSeconds($i * 9),
            'error' => $status === 'failed' ? ($i % 2 ? 'HARD 550 5.1.1 The email account that you tried to reach does not exist.' : 'SOFT 452 4.2.2 Mailbox full') : null];
        if ($status === 'sent') {
            $hour = (int) min(47 * 60, abs(mt_rand(0, 900) + mt_rand(0, 900) - 300));
            if ($i % 4 === 1 && $r <= 85) {        // iCloud: Apple Mail auto-open, seconds after delivery
                $row += ['open_class' => 'apple', 'open_ua' => 'apple', 'open_ip' => 'apple', 'open_count' => mt_rand(1, 3), 'first_open_at' => $row['sent_at']->copy()->addSeconds(mt_rand(2, 8))];
            } elseif ($r <= 38) {
                $row += ['open_class' => $i % 4 === 0 ? 'proxy' : 'human', 'open_ua' => $i % 4 === 0 ? 'proxy' : 'mail', 'open_ip' => $i % 4 === 0 ? 'google' : 'other', 'open_count' => mt_rand(1, 4), 'first_open_at' => $start->copy()->addMinutes($hour + mt_rand(0, 50))];
            } elseif ($r <= 41) {
                $row += ['open_class' => 'scanner', 'open_ua' => 'bot', 'open_ip' => 'other', 'open_count' => 1, 'first_open_at' => $row['sent_at']->copy()->addSeconds(1)];
            }
            if (isset($row['first_open_at']) && $r <= 14) {
                $row['first_click_at'] = $row['first_open_at']->copy()->addMinutes(mt_rand(1, 20));
            }
        }
        if ($i === 17) {
            $row['unsubscribed_at'] = $start->copy()->addHours(5);
        }
        $sid = DB::table('mkt_sends')->insertGetId($row);
        if (! empty($row['first_click_at'])) {
            $clickers++;
            foreach (range(1, mt_rand(1, 3)) as $c) {
                DB::table('mkt_clicks')->insert(['send_id' => $sid, 'link_id' => $links[mt_rand(0, 6) === 6 ? 6 : mt_rand(0, 5)], 'clicked_at' => $row['first_click_at']->copy()->addMinutes($c * 2),
                    'dev' => ['mobile', 'mobile', 'mobile', 'desktop', 'tablet'][mt_rand(0, 4)]]);
            }
            if ($i % 3 === 0) {
                DB::table('customers')->insertOrIgnore(['name' => 'C' . $i, 'email' => $row['email'], 'created_at' => now(), 'updated_at' => now()]);
                $cid = DB::table('customers')->where('email', $row['email'])->value('id');
                DB::table('orders')->insert(['customer_id' => $cid, 'order_number' => 'ER-' . $id . '-' . $i, 'email' => $row['email'], 'status' => 'processing',
                    'subtotal' => 18900, 'total' => 18900 + $i * 100, 'paid_at' => now(), 'created_at' => $row['first_click_at']->copy()->addHours(2), 'updated_at' => now(),
                    'src_channel' => 'email', 'src_campaign' => CampaignLinks::utmCampaign($id, $name)]);
            }
        }
        if ($i === 33 && $status === 'sent') {
            DB::table('email_bounces')->insert(['email' => $row['email'], 'kind' => 'hard', 'code' => '5.1.1', 'detail' => 'User unknown', 'campaign_id' => $id, 'send_id' => $sid, 'source' => 'dsn', 'created_at' => now()]);
        }
    }
    // The site visits those links brought, as Rollup leaves them in an_dims.
    $utm = CampaignLinks::utmCampaign($id, $name);
    foreach (range(0, 2) as $d) {
        $visits = (int) round($clickers * [0.7, 0.25, 0.05][$d]) + 1;
        $day = $start->copy()->addDays($d)->format('Y-m-d');
        DB::table('an_dims')->insert([
            ['day' => $day, 'dim' => 'campaign', 'val' => $utm, 'label' => '', 'views' => $visits * 3, 'visitors' => $visits, 'sessions' => $visits, 'bounces' => (int) ($visits / 4)],
            ['day' => $day, 'dim' => 'cmp_cart', 'val' => $utm, 'label' => '', 'views' => 0, 'visitors' => (int) ceil($visits / 3), 'sessions' => (int) ceil($visits / 3), 'bounces' => 0],
            ['day' => $day, 'dim' => 'cmp_chk', 'val' => $utm, 'label' => '', 'views' => 0, 'visitors' => (int) ceil($visits / 5), 'sessions' => (int) ceil($visits / 5), 'bounces' => 0],
        ]);
    }
}
echo "seeded\n";
