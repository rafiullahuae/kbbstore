<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three recommendation blocks' Arabic drafts. (Lane RP)
 *
 * The tab labels ("More from :brand", "More :category"), the tab list's
 * accessible name, and the headings and small lines of blocks 2 and 3. Same
 * reason as every round's migration before it — a migration that has run does
 * not run again, so keys added to ArabicInterfaceDrafts alone would sit in the
 * class and in no database anywhere.
 *
 * WHAT IT CHANGES ON THE SHOP: nothing on a page. All are drafts, which
 * TranslationStore::uiMap() does not serve; Translation → Progress gains nine
 * Drafts awaiting approval. Guarded on the row not existing, so the owner's
 * own typing is never overwritten and a second apply writes nothing.
 *
 * Same body as 2027_07_11_000250_seed_also_like_arabic_drafts.php.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.product.recs_tab_brand',
        'store.product.recs_tab_category',
        'store.product.recs_tabs_label',
        'store.product.recs_more_eyebrow',
        'store.product.recs_routine_heading',
        'store.product.recs_routine_eyebrow',
        'store.product.recs_recent_heading',
        'store.product.recs_recent_eyebrow',
        'store.product.recs_best_eyebrow',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();

        $existing = array_flip(array_map(
            static fn ($f): string => TranslationStore::normaliseKey((string) $f),
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->whereIn('field', self::KEYS)
                ->pluck('field')
                ->all()
        ));

        $now = now();
        $insert = [];

        foreach (self::KEYS as $key) {
            $field = TranslationStore::normaliseKey($key);

            if (isset($existing[$field]) || ! isset($drafts[$key])) {
                continue;
            }

            $english = InterfaceStrings::english($field);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $field,
                'value' => $drafts[$key],
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $english === null ? null : sha1($english),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // Drafts are not served, so a stale map here cannot even be wrong —
            // and an update that dies half-applied over a cache is the shape
            // CLAUDE.md records against UpdateRunner::recordManifest().
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        /*
         * Only rows this migration could have written: a draft from a machine.
         * A string the owner has typed or approved is his, and rolling a
         * package back is not a reason to delete it.
         */
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', self::KEYS)
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};
