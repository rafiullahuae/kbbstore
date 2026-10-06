<?php

declare(strict_types=1);

/*
 * Lane ZM: tools/mac-seed.php's shop, plus a customer who can sign in and has
 * an address, so the signed-in account, address-book and checkout fields can be
 * measured. Run through tools/zm-preview.sh; never against a real database.
 *
 *   sabina.dev@example.com / preview-password
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/mac-seed.php';

$c = DB::table('customers')->where('email', 'sabina.dev@example.com')->first();
DB::table('customers')->where('id', $c->id)->update(['password' => Hash::make('preview-password'), 'email_verified_at' => now()]);
DB::table('addresses')->insert(['customer_id' => $c->id, 'type' => 'shipping', 'is_default' => true, 'first_name' => 'Sabina', 'last_name' => 'Dev',
    'line1' => 'Villa 12, Street 4', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567', 'created_at' => now(), 'updated_at' => now()]);
echo "customer sabina.dev@example.com / preview-password\n";

// The Arabic shop and Build My Routine are off by default; both have fields.
app(\App\Services\SettingsService::class)->set('language_ar_enabled', '1');
DB::table('module_toggles')->updateOrInsert(['module' => 'build_my_routine'], ['enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
\Illuminate\Support\Facades\Cache::flush();
