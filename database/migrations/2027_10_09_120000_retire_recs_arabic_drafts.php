<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Six interface strings the product page no longer prints. (Lane RP2)
 *
 * Lane RP's tabs, "Complete your routine" and "Continue shopping" are gone —
 * the owner changed the three blocks to brand / category / best sellers — and
 * their wording went with them. 2027_10_08_120000_seed_recs_arabic_drafts
 * seeded an Arabic DRAFT for each; left behind, they would sit in
 * Translation → Progress as six drafts to review for words no page shows.
 *
 * Only a machine draft is removed: a string the owner typed or approved is his
 * and stays. Nothing on any page changes — drafts are never served.
 */
return new class extends Migration
{
    private const KEYS = [
        'store.product.recs_tabs_label',
        'store.product.recs_more_eyebrow',
        'store.product.recs_routine_heading',
        'store.product.recs_routine_eyebrow',
        'store.product.recs_recent_heading',
        'store.product.recs_recent_eyebrow',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', array_map(static fn (string $k): string => TranslationStore::normaliseKey($k), self::KEYS))
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // Drafts are not served; a stale map cannot be wrong here.
        }
    }

    public function down(): void
    {
        // Nothing to restore: the strings no longer exist in code, so a draft
        // for one would be a row for words no page can print.
    }
};
