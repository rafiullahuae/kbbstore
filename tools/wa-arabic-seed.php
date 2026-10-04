<?php
use App\Models\Setting; use App\Support\Locale; use App\Models\Translation;
Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
// The owner's one press on Translation -> Progress, for this lane's six only.
$n = \Illuminate\Support\Facades\DB::table('translations')->where('locale','ar')->where('field','like','store.whatsapp.%')->update(['status' => Translation::STATUS_PUBLISHED]);
Setting::query()->updateOrCreate(['key' => 'sticky_show'], ['value' => '1', 'autoload' => true]);
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();
\Illuminate\Support\Facades\Cache::flush();
echo "published $n whatsapp drafts; arabic+rtl on; sticky bar on\n";
// Keep the six rows in step with ArabicInterfaceDrafts after a wording change.
$d = \App\Services\Translation\ArabicInterfaceDrafts::all();
foreach (\App\Services\WhatsAppButton::KEYS as $k) {
  \Illuminate\Support\Facades\DB::table('translations')->where('locale','ar')->where('field',$k)->update(['value' => $d[$k]]);
}
\App\Services\Translation\TranslationStore::flush(); \Illuminate\Support\Facades\Cache::flush();
echo "synced\n";
