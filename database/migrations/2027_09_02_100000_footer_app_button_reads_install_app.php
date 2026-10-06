<?php

declare(strict_types=1);

/*
 * The footer app row's button: "Install" -> "Install App" (owner, 6 Oct:
 * "rename the button from Install > Install App"). The English default lives
 * in InterfaceStrings and moves with the code; the Arabic was seeded into the
 * translations table by 2027_09_01_100100 as «تثبيت», so it is moved here --
 * only while it still reads exactly that, so a wording he typed himself in
 * Translation -> Strings is never overwritten. Its status (draft or approved)
 * is kept. Never throws: a failed rename must not fail a Core Update.
 */

use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('translations')) {
                return;
            }

            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->where('field', TranslationStore::normaliseKey('store.footer.app_button'))
                ->where('value', 'تثبيت')
                ->update(['value' => 'تثبيت التطبيق', 'source_hash' => sha1('Install App'), 'updated_at' => now()]);

            // The Arabic headline, shortened in the same breath: beside the
            // wider «تثبيت التطبيق» a 390px phone cut «حمّلي تطبيق K-Beauty Bliss»
            // to "…auty Bliss" (measured). Only while it still reads the seed.
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->where('field', TranslationStore::normaliseKey('store.footer.app_title'))
                ->where('value', 'حمّلي تطبيق K-Beauty Bliss')
                ->update(['value' => 'حمّلي تطبيقنا', 'updated_at' => now()]);

            TranslationStore::flush();
        } catch (\Throwable) {
            // The old word still reads correctly; the owner can retype it.
        }
    }

    public function down(): void
    {
        // Wording only; nothing to undo.
    }
};
