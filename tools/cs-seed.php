<?php

declare(strict_types=1);

/*
 * Lane CS preview fixture: the shop as it is on the day the owner points
 * kbeautybliss.com at the server -- extrabeauty.ae is the main address,
 * Arabic is on, and two admin accounts exist. PREVIEW ONLY; run by
 * tools/cs-preview.sh against its own SQLite file, never a real database.
 *
 *   owner@example.com / preview-password     Full Admin
 *   manager@example.com / preview-password   Store Manager (must be refused)
 */

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
    SiteHost::KEY_CANONICAL => 'extrabeauty.ae', SiteHost::KEY_ALIASES => '', SiteHost::KEY_REDIRECT => '0',
    SiteHost::KEY_VISIBILITY => 'public',
] as $key => $value) {
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
$sv->flush();
Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
echo "cs seed done\n";
