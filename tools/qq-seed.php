<?php
/*
 * Seed the Lane QQB preview: an owner, Store -> Quiqup in Internal testing
 * with a FAKE staging key (the preview binds QqPreviewFake, nothing leaves the
 * machine), a pickup address, and a page of orders -- most of them the
 * owner's test orders (phone +971 50 123 4567), one with a bad phone, one
 * card order unpaid, one from a shopper who is not a test customer, and one
 * whose number ends in 7 so the fake refuses it with Quiqup's 422 text.
 */

use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Services\Quiqup\QuiqupSettings;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$opts = QuiqupSettings::defaults();
$opts['mode'] = 'testing';
$opts['mode_log'] = [['at' => now()->subDay()->toIso8601String(), 'by' => 'Preview Owner', 'from' => 'off', 'to' => 'testing']];
$opts['pickup'] = ['contact_name' => 'KBB Warehouse', 'contact_phone' => '+971501112222', 'address1' => 'Warehouse 4, Street 12', 'address2' => 'Al Quoz Industrial 3', 'town' => 'Dubai', 'emirate' => 'Dubai', 'notes' => 'Ring the bell at gate B'];
$opts['services'] = ['enabled' => ['partner_next_day', 'partner_same_day', 'partner_4hr'], 'default' => 'partner_next_day', 'by_method' => ['Express delivery' => 'partner_same_day']];
$opts['cutoffs'] = ['partner_same_day' => '12:00', 'partner_next_day' => '18:00', 'partner_4hr' => ''];
$opts['test'] = ['emails' => ['owner@kbeautybliss.com'], 'phones' => ['+971501234567'], 'orders' => []];

QuiqupConfig::updateOrCreate(['id' => 'sandbox'], [
    'active' => true,
    'secrets' => ['api_key' => 'preview_fake_staging_key_9f3a', 'webhook_secret' => 'preview_whsec_77c1', 'url_secret' => 'previewUrlSecret0123456789abcdefABCDEFgh'],
    'options' => $opts,
    'verified_at' => now()->subHours(3),
    'last_webhook_at' => now()->subMinutes(12),
    'last_webhook_state' => 'out_for_delivery',
]);
QuiqupConfig::updateOrCreate(['id' => 'live'], ['active' => false, 'secrets' => ['url_secret' => 'previewLiveUrlSecret0123456789abcdefABCD']]);

$addr = fn (string $first, string $last, string $line1, string $line2, string $city, string $phone) => [
    'first_name' => $first, 'last_name' => $last, 'line1' => $line1, 'line2' => $line2, 'city' => $city, 'state' => $city, 'country' => 'AE', 'phone' => $phone,
];

$rows = [
    ['KBB-56201', 'Mariam', 'Al Hashimi', 'Villa 12, Street 5', 'Al Barsha 2', 'Dubai', '050 123 4567', 'cod', null, 18900, 'Dubai', 'Please call before arriving'],
    ['KBB-56202', 'Mariam', 'Al Hashimi', 'Tower B, Flat 1204', 'Jumeirah Lake Towers', 'Dubai', '+971501234567', 'stripe', now(), 32450, 'Dubai', ''],
    ['KBB-56203', 'Mariam', 'Al Hashimi', 'Building 7, Al Khan St', 'Al Khan', 'Sharjah', '0501234567', 'cod', null, 9900, 'Sharjah', 'Leave with the concierge'],
    ['KBB-56204', 'Mariam', 'Al Hashimi', 'Villa 3', 'Al Mairid', 'Ras Al Khaimah', '04 123 4567', 'cod', null, 14500, 'Ras Al Khaimah', ''],
    ['KBB-56205', 'Mariam', 'Al Hashimi', 'Office 501, Prime Tower', 'Business Bay', 'Dubai', '050 123 4567', 'tabby', null, 27800, 'Dubai', ''],
    ['KBB-56206', 'Layla', 'Haddad', 'Apartment 8, Corniche Rd', 'Al Khalidiyah', 'Abu Dhabi', '055 765 4321', 'cod', null, 21000, 'Abu Dhabi', ''],
    ['KBB-56207', 'Mariam', 'Al Hashimi', 'Villa 44, 18th St', 'Al Safa 1', 'Dubai', '050 123 4567', 'cod', null, 11900, 'Dubai', 'Gift wrap please'],
];

foreach ($rows as $i => [$num, $f, $l, $a1, $a2, $city, $phone, $pay, $paid, $total, $em, $note]) {
    Order::updateOrCreate(['order_number' => $num], [
        'email' => $f === 'Layla' ? 'layla@example.com' : 'owner@kbeautybliss.com',
        'phone' => $phone, 'status' => 'processing', 'currency' => 'AED',
        'total' => $total, 'subtotal' => $total, 'payment_method' => $pay,
        'payment_method_title' => ['cod' => 'Cash on delivery', 'stripe' => 'Card', 'tabby' => 'Tabby'][$pay],
        'paid_at' => $paid, 'shipping_method' => $i === 1 ? 'Express delivery' : 'Standard delivery',
        'shipping_address' => $addr($f, $l, $a1, $a2, $city, $phone), 'billing_address' => $addr($f, $l, $a1, $a2, $city, $phone),
        'ship_emirate' => $em, 'customer_note' => $note, 'gift_note' => $i === 6 ? 'Happy birthday, from S' : null,
        'created_at' => now()->subHours(10 - $i), 'updated_at' => now()->subHours(10 - $i),
    ]);
}

// One test order already delivered end to end in Internal testing, so the
// Go-Live checklist can be shown with every tick (after the live key is
// pasted and tested in the shot run).
$done = Order::updateOrCreate(['order_number' => 'KBB-56190'], [
    'email' => 'owner@kbeautybliss.com', 'phone' => '0501234567', 'status' => 'completed', 'currency' => 'AED',
    'total' => 15600, 'subtotal' => 15600, 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    'shipping_address' => $addr('Mariam', 'Al Hashimi', 'Villa 12, Street 5', 'Al Barsha 2', 'Dubai', '0501234567'),
    'ship_emirate' => 'Dubai', 'ship_state' => 'delivery_complete', 'ship_state_at' => now()->subDay(),
    'created_at' => now()->subDays(2), 'updated_at' => now()->subDay(),
]);
$sh = \App\Models\Shipment::updateOrCreate(['order_id' => $done->id], [
    'env' => 'sandbox', 'generation' => 0, 'test' => true, 'request_uuid' => \App\Services\Quiqup\OrderDraft::requestUuid('sandbox', $done->id, 0),
    'active_key' => $done->id, 'qq_id' => 10004242, 'qq_uuid' => '00000000-0000-4000-8000-000000004242',
    'tracking_url' => 'https://track-parcel.quiqup.com/00000000-0000-4000-8000-000000004242', 'service_kind' => 'partner_next_day',
    'payment_mode' => 'paid_on_delivery', 'cod_fils' => 15600, 'parcels' => 1, 'job' => 'created', 'state' => 'delivery_complete',
    'state_at' => now()->subDay(), 'created_by' => 'Preview Owner',
]);
foreach ([['pending', 50], ['ready_for_collection', 49], ['collected', 40], ['at_depot', 34], ['out_for_delivery', 27], ['delivery_complete', 24]] as [$st, $h]) {
    \Illuminate\Support\Facades\DB::table('shipment_events')->insertOrIgnore(['shipment_id' => $sh->id, 'state' => $st, 'state_at' => now()->subHours($h)->format('Y-m-d H:i:s.u'),
        'source' => $st === 'pending' ? 'api' : 'webhook', 'received_at' => now()->subHours($h)->format('Y-m-d H:i:s.u'), 'applied_at' => now()->subHours($h)->format('Y-m-d H:i:s.u')]);
}

echo "qq seed done\n";
