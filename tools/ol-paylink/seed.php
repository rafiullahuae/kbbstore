<?php
/* Lane OL preview: the co3 checkout seed (UAE delivery, Stripe faked, COD) plus
   Tabby and Tamara configured, an owner for the admin and the owner app, and a
   FAILED order shaped like #56187 (Tamara, never started, AED 590) whose stock
   was really taken at placement and really given back by its failure.
   Development tooling only: tools/ never ships. */
require __DIR__.'/../co3-polish/seed.php';

use App\Models\{AdminUser, Order, PaymentProvider, Product};
use Illuminate\Support\Facades\{DB, Hash};

$tabby = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 2]);
$tabby->config = ['public_key' => 'pk_test_11111111-2222-3333-4444-555555555555', 'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555', 'merchant_code' => 'AE', 'webhook_secret' => 'whsec-tabby-ol-abcdefghijklmnopqrstuvwxyz0123'];
$tabby->save();
$tamara = PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 3]);
$tamara->config = ['api_token' => 'tamara-api-token', 'notification_token' => 'tamara-ol-notification-key-0123456789', 'webhook_secret' => 'whsec-tamara-ol-abcdefghijklmnopqrstuvwxyz01'];
$tamara->save();
app(\App\Services\Payments\GatewayCredentials::class)->forget();

app(\App\Services\SettingsService::class)->set('store_name', 'K-Beauty Bliss');
$owner = AdminUser::updateOrCreate(['email' => 'owner@ol.test'], ['name' => 'Rafi', 'password' => Hash::make('ol-preview-secret'), 'role' => 'owner']);
DB::table('owner_app_members')->where('admin_user_id', $owner->id)->delete();
DB::table('owner_app_members')->insert(['admin_user_id' => $owner->id, 'enabled' => true, 'pin_hash' => Hash::make('482615'), 'pin_length' => 6,
    'pin_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

$serum = Product::where('slug', 'co-glow-serum')->first();
$serum->forceFill(['price' => 28500])->save();

function olSeedOrder(string $number, string $status, Product $p, int $qty, string $locale = 'en'): Order
{
    $line = 28500 * $qty;
    $addr = ['first_name' => 'Alia', 'last_name' => 'Saeed', 'line1' => 'Villa 9, Al Wasl Road', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '050 888 3841'];
    return DB::transaction(function () use ($number, $status, $p, $qty, $locale, $line, $addr) {
    $o = Order::create([
        'order_number' => $number, 'email' => 'alia@example.com', 'phone' => '050 888 3841', 'status' => 'pending', 'currency' => 'AED', 'locale' => $locale,
        'billing_address' => $addr, 'shipping_address' => $addr, 'subtotal' => $line, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => $line + 2000, 'shipping_method' => 'Standard delivery',
        'payment_method' => 'tamara', 'payment_method_title' => 'Tamara', 'origin' => 'Instagram ad',
    ]);
    $o->items()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty, 'unit_price' => 28500, 'subtotal' => $line, 'total' => $line]);
    app(\App\Services\StockClaim::class)->claim([['product_id' => $p->id, 'variant_id' => null, 'quantity' => $qty, 'label' => $p->name]], $o->id);
    if ($status !== 'pending') {
        app(\App\Services\Orders\OrderStatus::class)->moveTo($o, $status, by: 'system', reason: 'The payment could not be started. Tamara: HTTP 400 — Invalid date format; risk_assessment_wrong_data_format');
    }

    return $o->fresh();
    });
}

$failed = olSeedOrder('56187', 'failed', $serum, 2);
$card = olSeedOrder('56188', 'failed', $serum, 1);
$paid = olSeedOrder('56190', 'pending', $serum, 1);
$paid->forceFill(['status' => 'processing', 'paid_at' => now(), 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery'])->save();
echo "ol: failed #{$failed->order_number} id {$failed->id}, card #{$card->order_number} id {$card->id}, paid #{$paid->order_number} id {$paid->id}; stock ".(int) $serum->fresh()->stock."\n";
