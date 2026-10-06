<?php
/*
 * Lane PD preview seed, run after tools/pn-seed.php by tools/pdv-preview.sh: gives the 64 phones the
 * spread a real Devices list has -- last seen from 2 minutes to weeks ago, a
 * few signed-in shoppers with names, one phone the owner already nicknamed,
 * and one Firefox phone whose push service now answers 410 (the preview's
 * front controller fakes the push services; nothing reaches the network).
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

$names = ['Layla Al Mansoori', 'Omar Haddad', 'Sara Khan', 'Noura Al Suwaidi', 'Priya Nair'];
$custIds = [];
foreach ($names as $i => $n) {
    $custIds[] = Customer::query()->updateOrCreate(['email' => 'pd-'.$i.'@preview.test'], ['name' => $n, 'password' => 'preview-secret-1'])->id;
}
$ids = DB::table('site_app_push_subscriptions')->where('status', 'active')->orderBy('id')->pluck('id')->all();
foreach ($ids as $k => $id) {
    DB::table('site_app_push_subscriptions')->where('id', $id)->update([
        'last_seen_at' => now()->subMinutes(7 + $k * 97),
        'customer_id' => $k % 3 === 0 ? $custIds[intdiv($k, 3) % count($custIds)] : null,
    ]);
}
// The phone the owner just opened the app on: seen 2 minutes ago, nobody signed in.
DB::table('site_app_push_subscriptions')->where('id', $ids[5])->update(['last_seen_at' => now()->subMinutes(2), 'customer_id' => null, 'platform' => 'ios', 'locale' => 'en']);
DB::table('site_app_push_subscriptions')->where('id', $ids[9])->update(['nickname' => 'Shop iPad (counter)']);
$ff = 'https://updates.push.services.mozilla.com/wpush/v2/preview-gone';
DB::table('site_app_push_subscriptions')->where('id', $ids[2])->update(['endpoint' => $ff, 'endpoint_hash' => hash('sha256', $ff), 'platform' => 'desktop', 'last_seen_at' => now()->subMinutes(5)]);
echo 'Devices: '.count($ids)." active phones, last seen spread, 1 nicknamed.\n";
