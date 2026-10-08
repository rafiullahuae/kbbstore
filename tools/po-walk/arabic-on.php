<?php
/* Lane PO: /ar on, right to left, and the shipped Arabic drafts approved --
   the owner's press, for the Arabic shots only. Preview fixture; never ships. */
foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $k) {
    \App\Models\Setting::query()->updateOrCreate(['key' => $k], ['value' => '1', 'autoload' => true]);
}
\App\Models\Translation::query()->where('locale', 'ar')->where('status', \App\Models\Translation::STATUS_DRAFT)
    ->update(['status' => \App\Models\Translation::STATUS_PUBLISHED, 'reviewed_at' => now()]);
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();
echo "arabic on\n";
