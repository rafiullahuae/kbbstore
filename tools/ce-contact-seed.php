<?php
/*
 * Seed for the Lane CE preview: Store -> Customers -> (a customer) -> Edit
 * email / phone. The owner's own case, as on his screenshot: a guest, "Jovana
 * Belenzada, jovana8b@yahoo.co · 00971504697062", with three orders on the
 * mistyped address, plus a registered customer with a gmial.com sign-in.
 * Mail goes to the `log` transport, so the re-send really runs end to end.
 */
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Rafi (owner)', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
AdminUser::updateOrCreate(['email' => 'support@preview.test'], [
    'name' => 'Preview Support', 'password' => 'preview-secret-1', 'role' => 'support',
]);

app(SettingsService::class)->set('store_name', 'K Beauty Bliss');
app(SettingsService::class)->set('mail_transport', 'log');

$jovana = Customer::create([
    'name' => 'Jovana Belenzada', 'first_name' => 'Jovana', 'last_name' => 'Belenzada',
    'email' => 'jovana8b@yahoo.co', 'phone' => '00971504697062',
]);
$sara = Customer::create([
    'name' => 'Sara Khan', 'first_name' => 'Sara', 'last_name' => 'Khan',
    'email' => 'sara.khan@gmial.com', 'phone' => '+971 55 123 4567', 'password' => 'has-a-password-1',
]);
$sara->forceFill(['email_verified_at' => now()->subMonths(2)])->save();

$addr = ['first_name' => 'Jovana', 'last_name' => 'Belenzada', 'line1' => 'Al Barsha 1, Villa 12', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '00971504697062'];
$n = 56300;
foreach ([['completed', 40, 18900], ['processing', 3, 24500], ['pending', 1, 9900]] as [$status, $days, $total]) {
    $n++;
    DB::table('orders')->insert([
        'order_number' => (string) $n, 'customer_id' => $jovana->id,
        'email' => 'jovana8b@yahoo.co', 'phone' => '00971504697062', 'status' => $status, 'currency' => 'AED',
        'billing_address' => json_encode($addr), 'shipping_address' => json_encode($addr),
        'subtotal' => $total - 2000, 'shipping_total' => 2000, 'total' => $total,
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'paid_at' => $status === 'pending' ? null : now()->subDays($days),
        'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
    ]);
}
$saddr = ['first_name' => 'Sara', 'last_name' => 'Khan', 'line1' => 'Marina Walk', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '+971 55 123 4567'];
DB::table('orders')->insert([
    'order_number' => '56310', 'customer_id' => $sara->id,
    'email' => 'sara.khan@gmial.com', 'phone' => '+971 55 123 4567', 'status' => 'processing', 'currency' => 'AED',
    'billing_address' => json_encode($saddr), 'shipping_address' => json_encode($saddr),
    'subtotal' => 30000, 'shipping_total' => 0, 'total' => 30000,
    'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
]);

echo "seeded: jovana #{$jovana->id}, sara #{$sara->id}\n";
file_put_contents(base_path('storage/framework/testing/lane-ce-contact-preview/webroot/ce-ids.json'), json_encode(['jovana' => $jovana->id, 'sara' => $sara->id]));
