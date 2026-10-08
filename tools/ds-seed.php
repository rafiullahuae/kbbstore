<?php

declare(strict_types=1);

/*
 * Lane DS: on top of tools/dw-seed.php, the shop as the screenshots need it --
 * switched to kbeautybliss.com (step 6 done), extrabeauty.ae still listed as
 * the old address, content still linking to extrabeauty.ae, and the gateways
 * in a mixed state for Payments ready?. Preview database only.
 */

use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Support\SiteHost;
use Illuminate\Support\Facades\DB;

foreach ([
    SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '0',
    'site_url' => 'https://kbeautybliss.com',
    'footer_copy' => 'Shop online at <a href="https://extrabeauty.ae/">extrabeauty.ae</a> · <a href="https://www.extrabeauty.ae/contact/">Contact</a>',
    'cod_fee' => '1000',
    'wallets_offered' => 'apple_pay,google_pay',
] as $key => $value) {
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

DB::table('products')->insert([
    ['name' => 'Rice Water Cleanser', 'slug' => 'ds-rice-cleanser', 'price' => 5900, 'status' => 'published', 'image' => null,
        'description' => '<p>Pair it with our <a href="https://extrabeauty.ae/collections/toners/">toners</a> and read '
            .'<a href="https://extrabeauty.ae/blog/double-cleansing/">the double-cleansing guide</a>.</p>'
            .'<img src="https://extrabeauty.ae/storage/products/rice-cleanser-texture.webp" alt="texture">',
        'created_at' => now(), 'updated_at' => now()],
]);

DB::table('pages')->insert(['slug' => 'about-us', 'title' => 'About us', 'status' => 'published',
    'content' => '<p>Questions? See <a href="https://extrabeauty.ae/faq/">our FAQ</a>.</p>', 'created_at' => now(), 'updated_at' => now()]);

$stripe = PaymentProvider::query()->find('stripe');
$stripe->mode = 'live';
$stripe->config = ['publishable_key' => 'pk_live_' . 'dspreview00000000000000Ab12', 'secret_key' => 'sk_live_' . 'dspreview00000000000000Cd34',
    'webhook_secret' => 'whsec-stripe-dwpreview0123456789abcdef', 'webhook_endpoint_id' => 'we_preview', 'webhook_signing_secret' => 'whsec_dspreviewsigning0000Ef56', 'wallet_apple_pay' => '1', 'wallet_google_pay' => '1'];
$stripe->save();

$tamara = PaymentProvider::query()->find('tamara');
$tamara->config = (array) $tamara->config + ['webhook_id' => 'tw-preview-1'];
$tamara->save();

PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'live', 'position' => 0]);

\Illuminate\Support\Facades\Cache::flush();
echo "ds seed: switched to kbeautybliss.com; 3 places link to extrabeauty.ae; gateways mixed\n";
