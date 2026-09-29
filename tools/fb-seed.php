<?php
/* Seed the Lane FB preview.

   An owner to sign in as, so Appearance -> Header -> Flag bar can be
   photographed, and Arabic switched on with the strip's three drafts PUBLISHED
   -- which is the only way to photograph the /ar pair, because the migration
   ships them as drafts and a draft never reaches a page.

   NOTHING ELSE IS TOUCHED. The flag bar's own settings are left at their
   shipped values on purpose: the phone shot has to be the shop as the package
   leaves it, not a shop somebody has already configured. */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

/* Arabic on, and the mirrored layout on WITH it -- Locale::direction() keeps
   the two switches apart and returns ltr for Arabic while the second is off,
   which is the shop's real default and exactly the wrong state to photograph
   RTL in. tests/Support/ArabicShop's header carries that trap at length. */
foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $key) {
    \App\Models\Setting::query()->updateOrCreate(['key' => $key], ['value' => '1', 'autoload' => true]);
}

foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $value) {
    if (! str_starts_with($key, 'store.flagbar.')) {
        continue;
    }

    \App\Models\Translation::query()->updateOrCreate(
        [
            'locale' => 'ar',
            'group' => \App\Models\Translation::GROUP_UI,
            'item_id' => 0,
            'field' => \App\Services\Translation\TranslationStore::normaliseKey($key),
        ],
        [
            'value' => $value,
            'status' => \App\Models\Translation::STATUS_PUBLISHED,
            'source' => \App\Models\Translation::SOURCE_MACHINE,
        ]
    );
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();

echo "seeded\n";
