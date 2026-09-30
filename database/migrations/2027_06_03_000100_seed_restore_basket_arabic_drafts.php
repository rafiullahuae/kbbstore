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
 * The restore-basket button's three Arabic drafts. (Lane PLC, round 2)
 *
 * ── WHY ANOTHER MIGRATION AND NOT AN EDIT TO AN EARLIER ONE ───────────────
 *
 * Five migrations have already seeded this table on this shop, the most recent
 * being this lane's own 2027_05_11_000100. A migration that has run does not run
 * again, so three keys added to ArabicInterfaceDrafts in a second round would
 * sit in the class and in no database anywhere: /ar would go on rendering the
 * English with nothing to show for it, the console's Drafts figure would not
 * move, and nobody would have anything to click. That is the silent half of the
 * shape, and it is why a round gets a migration rather than an edit.
 *
 * ── WHAT IT CHANGES ON THE SHOP: NOTHING ──────────────────────────────────
 *
 * All three rows are `status = draft`, and TranslationStore::uiMap() filters
 * on `published` before the map is built, so a draft cannot reach a page. /ar
 * renders the same English it renders today; the only thing that moves is
 * Translation → Progress, where Drafts awaiting approval goes up by three.
 *
 * Guarded on the row not already existing, keyed on the same
 * (locale, group, item_id, field) identity the table is unique on. So the
 * owner's own typing is never overwritten, and an update package applied twice
 * writes nothing the second time — the table's unique index makes the guard
 * load-bearing rather than tidy.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.checkout.restore_basket',
        'store.checkout.restore_done',
        'store.checkout.restore_gone',
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
