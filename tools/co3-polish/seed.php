<?php
/* Lane CO (checkout polish) preview: the card-walk seed (two products, UAE
   delivery, Stripe test keys faked, cash on delivery), plus Apple Pay and
   Google Pay switched on, the footer's payment marks on (as on the live shop),
   and a customer with a saved UAE address to sign in as. */
require __DIR__.'/../co-card-walk/seed.php';

use App\Models\Customer;

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Services\Payments\Wallets::SETTING_KEY, 'apple_pay,google_pay');
app(\App\Services\SlimFooter::class)->save(['pay_on' => true]);

$c = Customer::updateOrCreate(['email' => 'aisha@example.com'], [
    'name' => 'Aisha Rahman', 'password' => \Illuminate\Support\Facades\Hash::make('glow-secret-1'), 'phone' => '+971501234567',
]);
$c->addresses()->delete();
$c->addresses()->create([
    'type' => 'shipping', 'is_default' => true, 'first_name' => 'Aisha', 'last_name' => 'Rahman',
    'line1' => 'Villa 12, Al Wasl Road', 'line2' => 'Jumeirah 1', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567',
]);
echo "co3: customer #{$c->id}\n";
