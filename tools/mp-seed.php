<?php

/*
 * Lane MP preview seed: Lane SEO's measuring catalogue (brand, two categories,
 * products with drawn photos), the preview owner and a manager, and a few rows
 * in Last events so that tab has something to show. Preview database only.
 */
putenv('SEO_N=12');
require __DIR__.'/seo-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
\App\Models\AdminUser::updateOrCreate(['email' => 'manager@preview.test'], ['name' => 'Preview Manager', 'password' => 'preview-secret-1', 'role' => 'manager']);
app(\App\Services\SettingsService::class)->set('site_url', (string) config('app.url'));

$now = now();
foreach ([
    ['meta', 'Purchase', 'purchase-10234', 'sent', 200, 'Received: 1', 3],
    ['tiktok', 'CompletePayment', 'purchase-10234', 'sent', 200, 'OK', 3],
    ['ga4', 'purchase', 'purchase-10234', 'sent', 204, 'Accepted', 3],
    ['meta', 'InitiateCheckout', 'ic-4f2a9c1d7e3b8a60', 'sent', 200, 'Received: 1', 9],
    ['tiktok', 'AddToCart', 'atc-lmn0p9q8r7', 'failed', 0, 'Could not reach business-api.tiktok.com: Connection timed out after 4000 ms', 14],
    ['ga4', 'purchase', 'purchase-10233', 'skipped', null, 'No Google Analytics client id on this order (cookie blocked or declined), so the browser purchase is the only one — sending a second from the server would double-count it.', 40],
] as [$p, $e, $id, $s, $h, $m, $ago]) {
    \Illuminate\Support\Facades\DB::table('marketing_server_events')->insert(['platform' => $p, 'event' => $e, 'event_id' => $id, 'status' => $s, 'http_status' => $h, 'message' => $m, 'created_at' => $now->copy()->subMinutes($ago)]);
}
echo "mp seed done\n";
