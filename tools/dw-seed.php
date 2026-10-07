<?php

declare(strict_types=1);

/*
 * Lane DW: the shop the Domain switch preview starts from -- extrabeauty.ae
 * today, nothing configured for the move. Run through tools/dw-preview.sh;
 * never against a real database.
 *
 *   owner@example.com / preview-password     Full Admin
 *   manager@example.com / preview-password   Store Manager (must be refused)
 *
 * Two things the readiness check must find:
 *   - a product photograph only WordPress has (kbeautybliss.com/wp-content/
 *     uploads/...), which step 8 fetches;
 *   - a product description linking to extrabeauty.ae, which keeps RISK above
 *     0 until the e2e "fixes" it, so step 11's refusal can be seen.
 * And the three payment gateways, configured in test mode, so their buttons
 * reach the (faked) providers.
 */

use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Support\SiteHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

foreach ([['Rafi Ullah', 'owner@example.com', 'owner'], ['Store Manager', 'manager@example.com', 'manager']] as [$name, $email, $role]) {
    DB::table('admin_users')->updateOrInsert(['email' => $email], [
        'name' => $name, 'password' => Hash::make('preview-password'), 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

foreach ([
    SiteHost::KEY_CANONICAL => '', SiteHost::KEY_ALIASES => '', SiteHost::KEY_REDIRECT => '0',
    SiteHost::KEY_VISIBILITY => 'public', 'site_url' => 'https://extrabeauty.ae',
] as $key => $value) {
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

DB::table('products')->insert([
    ['name' => 'Rice Toner', 'slug' => 'dw-rice-toner', 'price' => 6500, 'status' => 'published',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2024/03/rice-toner.jpg', 'description' => 'A gentle toner.',
        'created_at' => now(), 'updated_at' => now()],
    ['name' => 'Snail Cream', 'slug' => 'dw-snail-cream', 'price' => 8900, 'status' => 'published',
        'image' => null, 'description' => '<p>See also <a href="https://extrabeauty.ae/shop/old-cream/">our old cream</a>.</p>',
        'created_at' => now(), 'updated_at' => now()],
]);

$providers = [
    'stripe' => ['Card', ['publishable_key_test' => 'pk_test_dwpreview', 'secret_key_test' => 'sk_test_dwpreview', 'webhook_secret' => 'whsec-stripe-dwpreview0123456789abcdef']],
    'tabby' => ['Tabby', ['public_key' => 'pk_test_dwpreview', 'secret_key' => 'sk_test_dwpreview', 'webhook_secret' => 'whsec-tabby-dwpreview0123456789abcdef']],
    'tamara' => ['Tamara', ['api_token' => 'tamara-token-dwpreview', 'notification_token' => 'tamara-notify-dwpreview', 'webhook_secret' => 'whsec-tamara-dwpreview0123456789abcdef']],
];

foreach ($providers as $id => [$title, $config]) {
    $row = PaymentProvider::query()->firstOrNew(['id' => $id]);
    $row->fill(['title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = $config;
    $row->save();
}

\Illuminate\Support\Facades\Cache::flush();
echo "dw seed: owner@example.com / preview-password; 2 products; stripe, tabby, tamara in test mode\n";
