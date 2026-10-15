<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicDraftCorrections;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane AR round 2: the grammar and wording corrections in
 * App\Services\Translation\ArabicDraftCorrections, carried onto a shop whose
 * drafts were seeded before them.
 *
 * ONLY A ROW THAT IS STILL THE SHIPPED DRAFT, WORD FOR WORD, AND STILL A DRAFT
 * is rewritten. A row the owner approved or edited is his wording and is never
 * touched -- the WHERE clause on `value` and `status` is the whole guarantee.
 * Nothing on /ar changes until he approves, exactly as with every other draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (ArabicDraftCorrections::ROUND2 as $key => [$old, $new]) {
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->where('field', TranslationStore::normaliseKey($key))
                ->where('status', Translation::STATUS_DRAFT)
                ->where('value', $old)
                ->update(['value' => $new, 'updated_at' => now()]);
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // A cache that cannot be reached is not a failed migration.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (ArabicDraftCorrections::ROUND2 as $key => [$old, $new]) {
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->where('field', TranslationStore::normaliseKey($key))
                ->where('status', Translation::STATUS_DRAFT)
                ->where('value', $new)
                ->update(['value' => $old]);
        }
    }
};
